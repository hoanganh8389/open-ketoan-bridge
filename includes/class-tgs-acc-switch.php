<?php
/**
 * CHUYỂN NGUỒN LẦN ĐẦU local → ketoan (SW2-2 — ADR-OKT-1 §5.2). Thủ tục do bridge làm; tsm chỉ có nút và ghi `tgs_catalog_source = ketoan`
 * khi thủ tục trả `['ok' => true, 'diff' => 0]` (filter `tgs_catalog_switch_to_ketoan` / `_ready`).
 *
 *   ready($blog)      true | WP_Error — nguồn đang local · kết nối 🟢 (SW2-1) · chuẩn hàng hoá 100 % (tsm ready_to_push). KHÔNG ghi gì.
 *   run($blog)        thủ tục, dừng ở bước đầu tiên hỏng (shop vẫn tự quản, quầy vẫn bán):
 *     1. dựng sẵn tenant (SW4b-1): cây nhóm, bảng giá, chi nhánh — chỉ tạo cái còn thiếu
 *     2. dryRun (SW4b-2): kế toán kiểm từng dòng, KHÔNG ghi; có lỗi thì dừng, chưa đẩy gì
 *     3. đẩy mặt hàng, lô 200, mã cha trước mã con: POST /api/v1/catalog/products/import (tên, ĐVT, nhóm, thuế, mã vạch)
 *     4. đẩy ĐVT quy đổi + bảng giá + giá: POST /api/v1/catalog/import (dữ liệu đọc từ bảng của blog)
 *     5. kéo về, gắn `item_id` / `parent_item_id` / Biến thể cho mã local theo SKU (chỉ ba cột định danh; lượt kéo đầy đủ là SW2-3)
 *     6. đối chứng: mọi mã local phải có ở kế toán, đúng thuế, đúng từng dòng giá; ghi hash hai bên. diff = số chỗ lệch
 *   Bằng khoá của CHÍNH blog (I-1). Không đụng POS, không đổi luồng bán (P-0 ý 4). Mỗi lần chạy ghi nhật ký vào option của blog.
 *   Chạy lại an toàn: kế toán ghi đè đúng dòng, mã đã có item_id giữ nguyên.
 *   CLI: wp tgs-acc switch --shop=<id> [--run].
 *
 * @package open-ketoan-bridge
 */
// [2026-11-25 02:30 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — chuyển nguồn lần đầu local → ketoan.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Switch
{
    const BATCH      = 200;
    const FEED_PAGE  = 1000;
    const LOG_OPTION = 'tgs_acc_switch_log'; // option của blog: các lần chạy gần nhất (tối đa 20)

    public static function init()
    {
        add_filter('tgs_catalog_switch_to_ketoan_ready', [__CLASS__, 'filter_ready'], 10, 2);
        add_filter('tgs_catalog_switch_to_ketoan', [__CLASS__, 'filter_run'], 10, 2);
    }

    public static function filter_ready($default, $blog_id = 0)
    {
        return self::ready((int) $blog_id);
    }

    public static function filter_run($default, $blog_id = 0)
    {
        return self::run((int) $blog_id);
    }

    /** @return true|WP_Error */
    public static function ready($blog_id, array $deps = [])
    {
        $blog_id = (int) $blog_id;
        if (function_exists('tgs_catalog_source') && tgs_catalog_source($blog_id) !== 'local') {
            return new WP_Error('tgs_switch_not_local', 'Shop không ở nguồn tự quản — không có gì để chuyển.');
        }
        if (!TGS_Acc_Connection::connected($blog_id, $deps)) {
            $c = TGS_Acc_Connection::connection($blog_id);
            return new WP_Error('tgs_switch_not_connected', 'Shop chưa nối kế toán 🟢: ' . ($c['error'] !== '' ? $c['error'] : 'xem Cổng kế toán → Khoá theo shop.'));
        }
        $ok = isset($deps['readiness']) ? (bool) call_user_func($deps['readiness'], $blog_id)
            : (class_exists('TGS_Catalog_Readiness') && TGS_Catalog_Readiness::ready_to_push($blog_id));
        if (!$ok) {
            return new WP_Error('tgs_switch_not_ready', 'Chuẩn hàng hoá chưa đạt 100 % (hoặc chưa quét xong) — quét lại và sửa ở màn Chuẩn hàng hoá.');
        }
        return true;
    }

    /**
     * @param array $deps test: ['client' => api, 'readiness' => callable, 'connected' => deps của Connection, 'batch' => n]
     * @return array|WP_Error ['ok' => true, 'diff' => 0, 'steps' => [...], …] — WP_Error ở bước hỏng đầu tiên (đã ghi nhật ký)
     */
    public static function run($blog_id, array $deps = [])
    {
        $blog_id = (int) $blog_id;
        $steps = [];
        $fail = function ($code, $msg) use ($blog_id, &$steps) {
            self::log($blog_id, ['ok' => false, 'error' => $msg, 'steps' => $steps]);
            return new WP_Error($code, $msg, ['steps' => $steps]);
        };
        $r = self::ready($blog_id, (array) ($deps['connected'] ?? []) + (isset($deps['readiness']) ? ['readiness' => $deps['readiness']] : []));
        if (is_wp_error($r)) {
            return $fail($r->get_error_code(), $r->get_error_message());
        }
        $c = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog_id);
        if (is_wp_error($c)) {
            return $fail('tgs_switch_no_client', $c->get_error_message());
        }

        // 1. dựng sẵn tenant
        $p = TGS_Acc_Tenant_Prepare::run($blog_id, false, ['client' => $c]);
        $steps['prepare'] = ['done' => count($p['done']), 'skipped' => count($p['skipped']), 'errors' => count($p['errors'])];
        if (!$p['ok']) {
            return $fail('tgs_switch_prepare', 'Dựng sẵn tenant không đạt: ' . implode(' | ', array_slice($p['errors'], 0, 3)));
        }

        // 2. dryRun
        $d = TGS_Acc_Catalog_Dryrun::run($blog_id, ['client' => $c]);
        $steps['dryrun'] = ['total' => $d['total'], 'failed' => $d['failed'], 'errors' => count($d['errors'])];
        if (!$d['ok']) {
            $first = $d['rows'] ? $d['rows'][0]['sku'] . ': ' . implode('; ', $d['rows'][0]['errors']) : implode(' | ', array_slice($d['errors'], 0, 2));
            return $fail('tgs_switch_dryrun', "Kế toán kiểm thử còn {$d['failed']} dòng lỗi — chưa đẩy gì. Dòng đầu: {$first}");
        }

        // 3. đẩy mặt hàng (cha trước con — rows() đã xếp)
        $rows = TGS_Acc_Catalog_Dryrun::rows($blog_id);
        $made = 0;
        $upd = 0;
        foreach (array_chunk($rows, max(1, (int) ($deps['batch'] ?? self::BATCH))) as $chunk) {
            $x = $c->post_json('/api/v1/catalog/products/import', ['dryRun' => false, 'rows' => $chunk]);
            if (is_wp_error($x)) {
                return $fail('tgs_switch_push', 'Đẩy mặt hàng lô ' . $chunk[0]['row'] . '–' . end($chunk)['row'] . ' không được: ' . $x->get_error_message());
            }
            $made += (int) ($x['created'] ?? 0);
            $upd += (int) ($x['updated'] ?? 0);
            if ((int) ($x['failed'] ?? 0) > 0) {
                return $fail('tgs_switch_push', 'Kế toán từ chối ' . (int) $x['failed'] . ' dòng ở lô ' . $chunk[0]['row'] . '–' . end($chunk)['row'] . ' (đã ghi các dòng đúng; chạy lại sau khi sửa).');
            }
        }
        $steps['push'] = ['rows' => count($rows), 'created' => $made, 'updated' => $upd];

        // 4. ĐVT quy đổi + bảng giá + giá (mã đã có ở kế toán → không gửi lại tên / nhóm)
        $import = TGS_Acc_Catalog_Sync::build_import(array_column($rows, 'sku'), $blog_id);
        $skipped = [];
        $send = function (array $body) use ($c, &$skipped) {
            $x = $c->post_json('/api/v1/catalog/import', $body);
            if (!is_wp_error($x) && !empty($x['skippedCount'])) {
                $skipped = array_merge($skipped, array_map('strval', (array) ($x['skipped'] ?? [])));
            }
            return $x;
        };
        $bodies = [['priceLists' => $import['priceLists'], 'branches' => $import['branches']]];
        foreach (array_chunk($import['products'], 2000) as $ch) {
            $bodies[] = ['products' => $ch];
        }
        foreach (array_chunk($import['prices'], 20000) as $ch) {
            $bodies[] = ['prices' => $ch];
        }
        foreach ($bodies as $b) {
            $x = $send($b);
            if (is_wp_error($x)) {
                return $fail('tgs_switch_import', 'Nạp ĐVT / bảng giá / giá không được: ' . $x->get_error_message());
            }
        }
        $steps['import'] = ['priceLists' => count($import['priceLists']), 'products' => count($import['products']), 'prices' => count($import['prices']), 'skipped' => count($skipped)];

        // 5. kéo về + gắn item_id
        $feed = self::read_feed($c);
        if (is_wp_error($feed)) {
            return $fail('tgs_switch_pull', 'Kéo danh mục về không được: ' . $feed->get_error_message());
        }
        $steps['attach'] = ['attached' => self::attach($blog_id, $feed['products'])];

        // 6. đối chứng
        $rec = self::reconcile($blog_id, $feed);
        $steps['reconcile'] = $rec;
        if ($rec['diff'] !== 0) {
            return $fail('tgs_switch_diff', "Đối chứng còn lệch {$rec['diff']} chỗ (mã thiếu {$rec['missing']}, thuế lệch {$rec['tax']}, giá lệch {$rec['price']}) — shop vẫn tự quản. Ví dụ: " . implode('; ', array_slice($rec['samples'], 0, 3)));
        }
        $out = ['ok' => true, 'diff' => 0, 'steps' => $steps, 'hash' => $rec['hash'], 'at' => current_time('mysql')];
        self::log($blog_id, $out);
        return $out;
    }

    /** Kéo feed mặt hàng + giá của tenant (khoá của blog). @return array|WP_Error ['products' => [sku => item], 'prices' => ["list|sku|unit" => price]] */
    private static function read_feed($c)
    {
        $out = ['products' => [], 'prices' => []];
        foreach (['products' => self::FEED_PAGE, 'prices' => 5000] as $feed => $limit) {
            $after = '';
            for ($page = 0; $page < 500; $page++) {
                $res = $c->get('/api/v1/catalog/feed/' . $feed, array_filter(['after' => $after, 'limit' => $limit]));
                if (is_wp_error($res)) {
                    return $res;
                }
                $d = (array) $res['data'];
                foreach ((array) ($d['items'] ?? []) as $it) {
                    if ($feed === 'products') {
                        $out['products'][(string) $it['sku']] = $it;
                    } else {
                        $out['prices'][$it['priceListCode'] . '|' . $it['sku'] . '|' . $it['unit']] = isset($it['price']) && $it['price'] !== null ? (float) $it['price'] : null;
                    }
                }
                $after = (string) ($d['next'] ?? $after);
                if (empty($d['more'])) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Gắn định danh cho mã local theo SKU. Chỉ ghi khi khác; không đụng cột khác. @return int số mã đã gắn / sửa */
    private static function attach($blog_id, array $items)
    {
        global $wpdb;
        $t = tgs_catalog_table('product', $blog_id);
        $n = 0;
        foreach ((array) $wpdb->get_results("SELECT global_product_sku s, global_product_item_id i, global_product_parent_item_id p, global_product_variant v FROM `{$t}` WHERE COALESCE(is_deleted, 0) = 0", ARRAY_A) as $r) {
            $it = $items[(string) $r['s']] ?? null;
            if (!$it || empty($it['itemId'])) {
                continue;
            }
            $want = ['global_product_item_id' => (string) $it['itemId'], 'global_product_parent_item_id' => !empty($it['parentItemId']) ? (string) $it['parentItemId'] : null,
                'global_product_variant' => !empty($it['variantCode']) ? (string) $it['variantCode'] : 'normal'];
            if ((string) $r['i'] === $want['global_product_item_id'] && (string) $r['p'] === (string) $want['global_product_parent_item_id'] && (string) $r['v'] === $want['global_product_variant']) {
                continue;
            }
            $wpdb->update($t, $want, ['global_product_sku' => (string) $r['s']]);
            $n++;
        }
        return $n;
    }

    /** Mọi mã + mọi dòng giá của blog phải có đúng ở kế toán. @return array{diff:int,missing:int,tax:int,price:int,local:int,hash:string,samples:string[]} */
    private static function reconcile($blog_id, array $feed)
    {
        global $wpdb;
        $pt = tgs_catalog_table('product', $blog_id);
        $lt = tgs_catalog_table('price_list', $blog_id);
        $ct = tgs_catalog_table('price', $blog_id);
        $res = ['diff' => 0, 'missing' => 0, 'tax' => 0, 'price' => 0, 'local' => 0, 'hash' => '', 'samples' => []];
        $lines = [];
        foreach ((array) $wpdb->get_results("SELECT global_product_sku s, global_product_tax t, global_product_is_kct k FROM `{$pt}` WHERE COALESCE(is_deleted, 0) = 0 ORDER BY global_product_sku", ARRAY_A) as $r) {
            $res['local']++;
            $it = $feed['products'][(string) $r['s']] ?? null;
            if (!$it) {
                $res['missing']++;
                $res['samples'][] = "{$r['s']}: chưa có ở kế toán";
                continue;
            }
            $kct = (int) $r['k'] === 1;
            $loc = $kct || $r['t'] === null ? null : (float) $r['t'];
            $rem = isset($it['taxRate']) && $it['taxRate'] !== null ? (float) $it['taxRate'] : null;
            if (!$kct && ($loc === null ? $rem !== null && $rem != 0 : $rem === null || abs($loc - $rem) > 0.001)) {
                $res['tax']++;
                $res['samples'][] = "{$r['s']}: thuế " . ($loc === null ? '-' : $loc) . ' ≠ ' . ($rem === null ? '-' : $rem);
            }
            $lines[] = $r['s'] . '|' . ($kct ? 'KCT' : ($loc === null ? '' : $loc));
        }
        $code = [];
        foreach ((array) $wpdb->get_results("SELECT global_htsoft_price_list_id i, price_list_code c FROM `{$lt}`", ARRAY_A) as $l) {
            $code[(int) $l['i']] = (string) $l['c'];
        }
        foreach ((array) $wpdb->get_results("SELECT price_list_id l, global_product_sku s, convert_unit u, unit_price p FROM `{$ct}` WHERE COALESCE(is_deleted, 0) = 0 AND unit_price IS NOT NULL ORDER BY price_list_id, global_product_sku, convert_unit", ARRAY_A) as $r) {
            $k = ($code[(int) $r['l']] ?? '?') . '|' . $r['s'] . '|' . $r['u'];
            $rem = $feed['prices'][$k] ?? null;
            if ($rem === null || abs((float) $r['p'] - $rem) > 0.005) {
                $res['price']++;
                $res['samples'][] = "giá {$k}: " . (float) $r['p'] . ' ≠ ' . ($rem === null ? 'chưa có' : $rem);
            }
            $lines[] = $k . '|' . (float) $r['p'];
        }
        $res['diff'] = $res['missing'] + $res['tax'] + $res['price'];
        $res['hash'] = md5(implode("\n", $lines));
        return $res;
    }

    private static function log($blog_id, array $entry)
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $entry = ['t' => gmdate('c'), 'user' => $user && $user->ID ? $user->user_login : 'CLI'] + $entry;
        $log = (array) TGS_Acc_Site::opt($blog_id, self::LOG_OPTION, []);
        array_unshift($log, $entry);
        TGS_Acc_Site::set($blog_id, self::LOG_OPTION, array_slice($log, 0, 20));
    }

    /** @return array[] các lần chạy gần nhất (mới nhất trước) */
    public static function history($blog_id)
    {
        return array_values((array) TGS_Acc_Site::opt((int) $blog_id, self::LOG_OPTION, []));
    }
}
TGS_Acc_Switch::init();
