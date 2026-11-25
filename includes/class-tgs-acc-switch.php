<?php
/**
 * CHUYỂN NGUỒN LẦN ĐẦU local → ketoan (SW2-2 — ADR-OKT-1 §5.2). Thủ tục do bridge làm; tsm chỉ có nút và ghi `tgs_catalog_source = ketoan`
 * khi thủ tục trả `['ok' => true, 'diff' => 0]` (filter `tgs_catalog_switch_to_ketoan` / `_ready`).
 *
 * Chạy theo TỪNG LƯỢT NHỎ (máy trạng thái, lưu ở option của blog) để shop vài nghìn mã không kẹt một request:
 *   start($blog)   mở thủ tục (kiểm điều kiện, xoá trạng thái cũ)            ready($blog)   true | WP_Error — không ghi gì
 *   step($blog)    làm MỘT lượt: một bước nhỏ hoặc một lô; trả phase / tiến độ / done / kết quả
 *   run($blog)     start + step tới khi xong (CLI, test, nút không JS)        status($blog)  trạng thái hiện tại
 *   Người chạy lượt: (1) trình duyệt — admin-ajax `tgs_acc_switch` (op start|step|status|cancel), lặp tới khi done;
 *                    (2) cron nền — `tgs_acc_switch_tick` tự nối lượt mỗi 20 giây sau start(…, ['cron' => true]), đóng trang không mất việc.
 *
 * Các pha: prepare (dựng tenant, SW4b-1) → dryrun (SW4b-2, lỗi thì dừng, chưa đẩy mã nào) → push (/products/import, lô 200, cha trước con)
 *   → import (/catalog/import: bảng giá + chi nhánh, rồi mặt hàng / giá theo lô) → pull_products (feed → gắn item_id, đối thuế)
 *   → pull_prices (feed → đối từng dòng giá) → done (diff = mã thiếu + thuế lệch + giá lệch; hash các dòng local).
 * Đối chứng chạy cuốn chiếu theo trang feed — không giữ cả danh mục trong bộ nhớ. Bằng khoá của CHÍNH blog (I-1).
 * Không đụng POS, không đổi luồng bán (P-0 ý 4); bridge không tự đặt nguồn. Chạy lại an toàn. Nhật ký: option `tgs_acc_switch_log`.
 * CLI: wp tgs-acc switch --shop=<id> [--run] [--set].
 *
 * @package open-ketoan-bridge
 */
// [2026-11-25 02:30 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — chuyển nguồn lần đầu local → ketoan.
// [2026-11-25 05:00 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — chủ chốt: không chạy đồng bộ một request; chia lượt (admin-ajax từng lượt) + cron nền.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Switch
{
    const BATCH       = 200;
    const FEED_PAGE   = 1000;
    const PRICE_PAGE  = 5000;
    const LOG_OPTION  = 'tgs_acc_switch_log';   // option của blog: các lần chạy gần nhất (tối đa 20)
    const STATE       = 'tgs_acc_switch_state'; // option của blog: trạng thái thủ tục đang chạy / vừa xong
    const SEEN        = 'tgs_acc_switch_seen_'; // site transient: mã đã thấy ở feed (đối chứng cuốn chiếu)
    const TICK        = 'tgs_acc_switch_tick';
    const TICK_EVERY  = 20;
    const NONCE       = 'tgs_acc_switch';
    const KEEP_RESULT = 600;                    // giây: kết quả đạt còn hiệu lực để tsm đặt nguồn

    public static function init()
    {
        add_filter('tgs_catalog_switch_to_ketoan_ready', [__CLASS__, 'filter_ready'], 10, 2);
        add_filter('tgs_catalog_switch_to_ketoan', [__CLASS__, 'filter_run'], 10, 2);
        add_filter('tgs_catalog_switch_to_ketoan_ajax', [__CLASS__, 'filter_ajax'], 10, 2);
        add_action('wp_ajax_tgs_acc_switch', [__CLASS__, 'ajax']);
        add_action(self::TICK, [__CLASS__, 'tick'], 10, 1);
    }

    public static function filter_ready($default, $blog_id = 0)
    {
        return self::ready((int) $blog_id);
    }

    /** tsm bấm nút: có kết quả đạt còn hiệu lực (chạy từng lượt bằng ajax / cron) thì dùng luôn, không thì chạy trọn (cách cũ). */
    public static function filter_run($default, $blog_id = 0)
    {
        $blog_id = (int) $blog_id;
        $s = self::state($blog_id);
        if ($s && ($s['phase'] ?? '') === 'done' && !empty($s['result']['ok']) && time() - (int) ($s['finished'] ?? 0) <= self::KEEP_RESULT) {
            return $s['result'];
        }
        return self::run($blog_id);
    }

    /** Cho màn của tsm biết cách chạy từng lượt. @return array */
    public static function filter_ajax($default, $blog_id = 0)
    {
        return ['action' => 'tgs_acc_switch', 'nonce' => wp_create_nonce(self::NONCE)];
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

    // ── trạng thái ──────────────────────────────────────────────────────────────

    public static function state($blog_id)
    {
        $s = TGS_Acc_Site::opt((int) $blog_id, self::STATE, null);
        return is_array($s) ? $s : null;
    }

    private static function save($blog_id, array $s)
    {
        TGS_Acc_Site::set((int) $blog_id, self::STATE, $s);
    }

    /** @return array tóm tắt cho màn / ajax (không có dữ liệu nặng) */
    public static function status($blog_id)
    {
        $s = self::state($blog_id);
        if (!$s) {
            return ['phase' => 'idle', 'done' => false, 'ok' => false, 'message' => 'Chưa chạy.', 'error' => '', 'steps' => [], 'result' => null, 'cron' => false, 'fresh' => false];
        }
        return ['phase' => $s['phase'], 'done' => in_array($s['phase'], ['done', 'failed'], true), 'ok' => $s['phase'] === 'done',
            'message' => (string) ($s['message'] ?? ''), 'error' => (string) ($s['error'] ?? ''), 'steps' => (array) ($s['steps'] ?? []), 'result' => $s['result'] ?? null, 'cron' => !empty($s['cron']),
            'fresh' => $s['phase'] === 'done' && time() - (int) ($s['finished'] ?? 0) <= self::KEEP_RESULT]; // kết quả đạt còn hiệu lực để tsm đặt nguồn
    }

    /**
     * Mở thủ tục. @param array $o ['cron' => true] để cron nền tự nối lượt. @param array $deps test
     * @return array|WP_Error trạng thái đầu
     */
    public static function start($blog_id, array $o = [], array $deps = [])
    {
        $blog_id = (int) $blog_id;
        $cur = self::state($blog_id);
        if ($cur && !in_array($cur['phase'], ['done', 'failed'], true) && time() - (int) ($cur['touched'] ?? 0) < 300) {
            return new WP_Error('tgs_switch_busy', 'Thủ tục chuyển nguồn đang chạy (lượt gần nhất ' . (time() - (int) $cur['touched']) . ' giây trước).');
        }
        $r = self::ready($blog_id, (array) ($deps['connected'] ?? []) + (isset($deps['readiness']) ? ['readiness' => $deps['readiness']] : []));
        if (is_wp_error($r)) {
            return $r;
        }
        delete_site_transient(self::SEEN . $blog_id);
        $s = ['phase' => 'prepare', 'started' => time(), 'touched' => time(), 'user' => self::who(), 'steps' => [], 'cron' => !empty($o['cron']),
            'message' => 'Dựng sẵn tenant…', 'push_at' => 0, 'import_i' => 0, 'after' => '', 'm_prod' => 0, 'tax' => 0, 'm_price' => 0, 'p_seen' => 0, 'samples' => []];
        self::save($blog_id, $s);
        if ($s['cron']) {
            self::schedule($blog_id);
        }
        return self::status($blog_id);
    }

    public static function cancel($blog_id)
    {
        $s = self::state($blog_id);
        if ($s && !in_array($s['phase'], ['done', 'failed'], true)) {
            self::fail((int) $blog_id, $s, 'tgs_switch_cancel', 'Đã huỷ thủ tục (phần đã đẩy ở kế toán giữ nguyên, chạy lại được).');
        }
        wp_clear_scheduled_hook(self::TICK, [(int) $blog_id]);
        return self::status($blog_id);
    }

    /** Chạy trọn: start + step tới khi xong. @return array|WP_Error kết quả (ok + diff) hoặc lỗi bước hỏng */
    public static function run($blog_id, array $deps = [])
    {
        $blog_id = (int) $blog_id;
        $st = self::start($blog_id, [], $deps);
        if (is_wp_error($st)) {
            self::log($blog_id, ['ok' => false, 'error' => $st->get_error_message(), 'steps' => []]);
            return $st;
        }
        for ($i = 0; $i < 100000; $i++) {
            $st = self::step($blog_id, $deps);
            if (is_wp_error($st)) {
                return $st;
            }
            if ($st['done']) {
                break;
            }
        }
        return $st['ok'] ? $st['result'] : new WP_Error('tgs_switch_unfinished', $st['error'] ?: 'Thủ tục chưa xong.');
    }

    // ── một lượt ────────────────────────────────────────────────────────────────

    /**
     * Làm MỘT lượt. @return array|WP_Error trạng thái (status()) — WP_Error ở bước hỏng (trạng thái chuyển 'failed', đã ghi nhật ký)
     * @param array $deps test: ['client', 'prepare', 'dryrun', 'batch']
     */
    public static function step($blog_id, array $deps = [])
    {
        $blog_id = (int) $blog_id;
        $s = self::state($blog_id);
        if (!$s) {
            return new WP_Error('tgs_switch_idle', 'Chưa mở thủ tục — gọi start trước.');
        }
        if (in_array($s['phase'], ['done', 'failed'], true)) {
            return self::status($blog_id);
        }
        $s['touched'] = time();
        $c = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog_id);
        if (is_wp_error($c)) {
            return self::fail($blog_id, $s, 'tgs_switch_no_client', $c->get_error_message());
        }
        switch ($s['phase']) {
            case 'prepare':
                $p = isset($deps['prepare']) ? call_user_func($deps['prepare'], $blog_id) : TGS_Acc_Tenant_Prepare::run($blog_id, false, ['client' => $c]);
                $s['steps']['prepare'] = ['done' => count($p['done']), 'skipped' => count($p['skipped']), 'errors' => count($p['errors'])];
                if (!$p['ok']) {
                    return self::fail($blog_id, $s, 'tgs_switch_prepare', 'Dựng sẵn tenant không đạt: ' . implode(' | ', array_slice($p['errors'], 0, 3)));
                }
                return self::go($blog_id, $s, 'dryrun', 'Kế toán kiểm thử danh mục…');

            case 'dryrun':
                $d = isset($deps['dryrun']) ? call_user_func($deps['dryrun'], $blog_id) : TGS_Acc_Catalog_Dryrun::run($blog_id, ['client' => $c]);
                $s['steps']['dryrun'] = ['total' => $d['total'], 'failed' => $d['failed'], 'errors' => count($d['errors'])];
                if (!$d['ok']) {
                    $first = $d['rows'] ? $d['rows'][0]['sku'] . ': ' . implode('; ', $d['rows'][0]['errors']) : implode(' | ', array_slice($d['errors'], 0, 2));
                    return self::fail($blog_id, $s, 'tgs_switch_dryrun', "Kế toán kiểm thử còn {$d['failed']} dòng lỗi — chưa đẩy gì. Dòng đầu: {$first}");
                }
                $s['total'] = (int) $d['total'];
                return self::go($blog_id, $s, 'push', 'Đẩy mặt hàng…');

            case 'push':
                return self::step_push($blog_id, $s, $c, $deps);
            case 'import':
                return self::step_import($blog_id, $s, $c);
            case 'pull_products':
                return self::step_pull_products($blog_id, $s, $c);
            case 'pull_prices':
                return self::step_pull_prices($blog_id, $s, $c);
        }
        return self::fail($blog_id, $s, 'tgs_switch_phase', 'Pha không hợp lệ: ' . $s['phase']);
    }

    private static function step_push($blog_id, array $s, $c, array $deps)
    {
        $rows = TGS_Acc_Catalog_Dryrun::rows($blog_id); // đã xếp cha trước con; đọc lại mỗi lượt (rẻ so với một lượt gọi mạng)
        $n = max(1, (int) ($deps['batch'] ?? self::BATCH));
        $chunk = array_slice($rows, (int) $s['push_at'], $n);
        if ($chunk) {
            $x = $c->post_json('/api/v1/catalog/products/import', ['dryRun' => false, 'rows' => $chunk]);
            if (is_wp_error($x)) {
                return self::fail($blog_id, $s, 'tgs_switch_push', 'Đẩy mặt hàng lô ' . $chunk[0]['row'] . '–' . end($chunk)['row'] . ' không được: ' . $x->get_error_message());
            }
            $push = $s['steps']['push'] ?? ['rows' => count($rows), 'created' => 0, 'updated' => 0];
            $push['created'] += (int) ($x['created'] ?? 0);
            $push['updated'] += (int) ($x['updated'] ?? 0);
            $s['steps']['push'] = $push;
            if ((int) ($x['failed'] ?? 0) > 0) {
                return self::fail($blog_id, $s, 'tgs_switch_push', 'Kế toán từ chối ' . (int) $x['failed'] . ' dòng ở lô ' . $chunk[0]['row'] . '–' . end($chunk)['row'] . ' (đã ghi các dòng đúng; chạy lại sau khi sửa).');
            }
            $s['push_at'] += count($chunk);
        }
        if ($s['push_at'] >= count($rows)) {
            $s['steps']['push'] = $s['steps']['push'] ?? ['rows' => 0, 'created' => 0, 'updated' => 0];
            return self::go($blog_id, $s, 'import', 'Nạp đơn vị tính, bảng giá, giá…');
        }
        return self::go($blog_id, $s, 'push', 'Đã đẩy ' . $s['push_at'] . '/' . count($rows) . ' mã…');
    }

    /** ĐVT + bảng giá + giá: lượt 0 = bảng giá + chi nhánh; rồi mặt hàng 2.000 mã / lượt; rồi giá 20.000 dòng / lượt. */
    private static function step_import($blog_id, array $s, $c)
    {
        $skus = array_column(TGS_Acc_Catalog_Dryrun::rows($blog_id), 'sku');
        $d = TGS_Acc_Catalog_Sync::build_import($skus, $blog_id);
        $bodies = [['priceLists' => $d['priceLists'], 'branches' => $d['branches']]];
        foreach (array_chunk($d['products'], 2000) as $ch) {
            $bodies[] = ['products' => $ch];
        }
        foreach (array_chunk($d['prices'], 20000) as $ch) {
            $bodies[] = ['prices' => $ch];
        }
        $i = (int) $s['import_i'];
        if ($i < count($bodies)) {
            $x = $c->post_json('/api/v1/catalog/import', $bodies[$i]);
            if (is_wp_error($x)) {
                return self::fail($blog_id, $s, 'tgs_switch_import', 'Nạp ĐVT / bảng giá / giá không được: ' . $x->get_error_message());
            }
            $imp = $s['steps']['import'] ?? ['priceLists' => count($d['priceLists']), 'products' => count($d['products']), 'prices' => count($d['prices']), 'skipped' => 0];
            $imp['skipped'] += (int) ($x['skippedCount'] ?? 0);
            $s['steps']['import'] = $imp;
            $s['import_i'] = $i + 1;
        }
        if ($s['import_i'] >= count($bodies)) {
            $s['after'] = '';
            return self::go($blog_id, $s, 'pull_products', 'Kéo danh mục về, gắn mã định danh…');
        }
        return self::go($blog_id, $s, 'import', 'Nạp ' . $s['import_i'] . '/' . count($bodies) . ' lượt…');
    }

    private static function step_pull_products($blog_id, array $s, $c)
    {
        global $wpdb;
        $res = $c->get('/api/v1/catalog/feed/products', array_filter(['after' => (string) $s['after'], 'limit' => self::FEED_PAGE]));
        if (is_wp_error($res)) {
            return self::fail($blog_id, $s, 'tgs_switch_pull', 'Kéo danh mục về không được: ' . $res->get_error_message());
        }
        $d = (array) $res['data'];
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $it) {
            $items[(string) $it['sku']] = $it;
        }
        $t = tgs_catalog_table('product', $blog_id);
        $seen = (array) get_site_transient(self::SEEN . $blog_id);
        if ($items) {
            $in = implode(',', array_fill(0, count($items), '%s'));
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT global_product_sku s, global_product_tax t, global_product_is_kct k, global_product_item_id i, global_product_parent_item_id p, global_product_variant v
                FROM `{$t}` WHERE COALESCE(is_deleted, 0) = 0 AND global_product_sku IN ({$in})", array_keys($items)), ARRAY_A);
            $att = (int) ($s['steps']['attach']['attached'] ?? 0);
            foreach ($rows as $r) {
                $it = $items[(string) $r['s']];
                $seen[(string) $r['s']] = 1;
                $s['m_prod']++;
                if (!empty($it['itemId'])) {
                    $want = ['global_product_item_id' => (string) $it['itemId'], 'global_product_parent_item_id' => !empty($it['parentItemId']) ? (string) $it['parentItemId'] : null,
                        'global_product_variant' => !empty($it['variantCode']) ? (string) $it['variantCode'] : 'normal'];
                    if ((string) $r['i'] !== $want['global_product_item_id'] || (string) $r['p'] !== (string) $want['global_product_parent_item_id'] || (string) $r['v'] !== $want['global_product_variant']) {
                        $wpdb->update($t, $want, ['global_product_sku' => (string) $r['s']]);
                        $att++;
                    }
                }
                $kct = (int) $r['k'] === 1;
                $loc = $kct || $r['t'] === null ? null : (float) $r['t'];
                $rem = isset($it['taxRate']) && $it['taxRate'] !== null ? (float) $it['taxRate'] : null;
                if (!$kct && ($loc === null ? $rem !== null && $rem != 0 : $rem === null || abs($loc - $rem) > 0.001)) {
                    $s['tax']++;
                    if (count($s['samples']) < 5) {
                        $s['samples'][] = "{$r['s']}: thuế " . ($loc === null ? '-' : $loc) . ' ≠ ' . ($rem === null ? '-' : $rem);
                    }
                }
            }
            $s['steps']['attach'] = ['attached' => $att];
            set_site_transient(self::SEEN . $blog_id, $seen, DAY_IN_SECONDS);
        }
        $s['after'] = (string) ($d['next'] ?? $s['after']);
        if (empty($d['more'])) {
            $s['after'] = '';
            $s['steps']['attach'] = $s['steps']['attach'] ?? ['attached' => 0];
            return self::go($blog_id, $s, 'pull_prices', 'Đối chiếu giá…');
        }
        return self::go($blog_id, $s, 'pull_products', 'Đã kéo ' . $s['m_prod'] . ' mã…');
    }

    private static function step_pull_prices($blog_id, array $s, $c)
    {
        $res = $c->get('/api/v1/catalog/feed/prices', array_filter(['after' => (string) $s['after'], 'limit' => self::PRICE_PAGE]));
        if (is_wp_error($res)) {
            return self::fail($blog_id, $s, 'tgs_switch_pull', 'Kéo bảng giá về không được: ' . $res->get_error_message());
        }
        $d = (array) $res['data'];
        $page = [];
        foreach ((array) ($d['items'] ?? []) as $it) {
            $page[$it['priceListCode'] . '|' . $it['sku'] . '|' . $it['unit']] = isset($it['price']) && $it['price'] !== null ? (float) $it['price'] : null;
        }
        if ($page) {
            $skus = array_values(array_unique(array_map(function ($k) { return explode('|', $k, 3)[1]; }, array_keys($page))));
            foreach (self::local_prices($blog_id, $skus) as $k => $price) {
                if (!array_key_exists($k, $page)) {
                    continue; // dòng giá local này nằm ở trang khác, hoặc thiếu → tính ở tổng cuối
                }
                $s['p_seen']++;
                if ($page[$k] !== null && abs($price - $page[$k]) <= 0.005) {
                    $s['m_price']++;
                } elseif (count($s['samples']) < 5) {
                    $s['samples'][] = "giá {$k}: {$price} ≠ " . ($page[$k] === null ? 'chưa có' : $page[$k]);
                }
            }
        }
        $s['after'] = (string) ($d['next'] ?? $s['after']);
        if (!empty($d['more'])) {
            return self::go($blog_id, $s, 'pull_prices', 'Đã đối ' . $s['m_price'] . ' dòng giá…');
        }
        return self::finish($blog_id, $s);
    }

    /** Dòng giá local của các mã (chỉ mã còn sống của blog): ["bảng giá|sku|ĐVT" => giá]. */
    private static function local_prices($blog_id, array $skus)
    {
        global $wpdb;
        $out = [];
        if (!$skus) {
            return $out;
        }
        $pt = tgs_catalog_table('product', $blog_id);
        $lt = tgs_catalog_table('price_list', $blog_id);
        $ct = tgs_catalog_table('price', $blog_id);
        $in = implode(',', array_fill(0, count($skus), '%s'));
        $sql = "SELECT l.price_list_code c, p.global_product_sku s, p.convert_unit u, p.unit_price pr FROM `{$ct}` p
            JOIN `{$lt}` l ON l.global_htsoft_price_list_id = p.price_list_id
            JOIN `{$pt}` n ON n.global_product_sku = p.global_product_sku AND COALESCE(n.is_deleted, 0) = 0
            WHERE COALESCE(p.is_deleted, 0) = 0 AND p.unit_price IS NOT NULL AND p.global_product_sku IN ({$in})";
        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $skus), ARRAY_A) as $r) {
            $out[$r['c'] . '|' . $r['s'] . '|' . $r['u']] = (float) $r['pr'];
        }
        return $out;
    }

    /** Tổng đối chứng. */
    private static function finish($blog_id, array $s)
    {
        global $wpdb;
        $pt = tgs_catalog_table('product', $blog_id);
        $lt = tgs_catalog_table('price_list', $blog_id);
        $ct = tgs_catalog_table('price', $blog_id);
        $lines = [];
        $local = [];
        foreach ((array) $wpdb->get_results("SELECT global_product_sku s, global_product_tax t, global_product_is_kct k FROM `{$pt}` WHERE COALESCE(is_deleted, 0) = 0 ORDER BY global_product_sku", ARRAY_A) as $r) {
            $local[] = (string) $r['s'];
            $lines[] = $r['s'] . '|' . ((int) $r['k'] === 1 ? 'KCT' : ($r['t'] === null ? '' : (float) $r['t']));
        }
        $n_price = 0;
        foreach ((array) $wpdb->get_results("SELECT l.price_list_code c, p.global_product_sku s, p.convert_unit u, p.unit_price pr FROM `{$ct}` p
            JOIN `{$lt}` l ON l.global_htsoft_price_list_id = p.price_list_id
            JOIN `{$pt}` n ON n.global_product_sku = p.global_product_sku AND COALESCE(n.is_deleted, 0) = 0
            WHERE COALESCE(p.is_deleted, 0) = 0 AND p.unit_price IS NOT NULL ORDER BY p.price_list_id, p.global_product_sku, p.convert_unit", ARRAY_A) as $r) {
            $n_price++;
            $lines[] = $r['c'] . '|' . $r['s'] . '|' . $r['u'] . '|' . (float) $r['pr'];
        }
        $seen = (array) get_site_transient(self::SEEN . $blog_id);
        $missing_list = array_values(array_filter($local, function ($k) use ($seen) { return !isset($seen[$k]); }));
        $missing = count($missing_list);
        $price = max(0, $n_price - (int) $s['m_price']);
        $rec = ['diff' => $missing + (int) $s['tax'] + $price, 'missing' => $missing, 'tax' => (int) $s['tax'], 'price' => $price, 'local' => count($local), 'prices' => $n_price, 'hash' => md5(implode("\n", $lines))];
        $samples = array_merge(array_map(function ($k) { return "{$k}: chưa có ở kế toán"; }, array_slice($missing_list, 0, 3)), (array) $s['samples']);
        $s['steps']['reconcile'] = $rec + ['samples' => $samples];
        delete_site_transient(self::SEEN . $blog_id);
        if ($rec['diff'] !== 0) {
            return self::fail($blog_id, $s, 'tgs_switch_diff', "Đối chứng còn lệch {$rec['diff']} chỗ (mã thiếu {$missing}, thuế lệch {$rec['tax']}, giá lệch {$price}) — shop vẫn tự quản. Ví dụ: " . implode('; ', array_slice($samples, 0, 3)));
        }
        $result = ['ok' => true, 'diff' => 0, 'steps' => $s['steps'], 'hash' => $rec['hash'], 'at' => current_time('mysql')];
        $s['phase'] = 'done';
        $s['finished'] = time();
        $s['message'] = 'Xong: đối chứng lệch 0.';
        $s['result'] = $result;
        self::save($blog_id, $s);
        wp_clear_scheduled_hook(self::TICK, [$blog_id]);
        self::log($blog_id, $result);
        return self::status($blog_id);
    }

    private static function go($blog_id, array $s, $phase, $message)
    {
        $s['phase'] = $phase;
        $s['message'] = $message;
        self::save($blog_id, $s);
        return self::status($blog_id);
    }

    private static function fail($blog_id, array $s, $code, $msg)
    {
        $s['phase'] = 'failed';
        $s['error'] = $msg;
        $s['message'] = $msg;
        $s['finished'] = time();
        self::save($blog_id, $s);
        wp_clear_scheduled_hook(self::TICK, [(int) $blog_id]);
        self::log($blog_id, ['ok' => false, 'error' => $msg, 'steps' => $s['steps']]);
        return new WP_Error($code, $msg, ['steps' => $s['steps']]);
    }

    // ── cron nền + admin-ajax ───────────────────────────────────────────────────

    public static function schedule($blog_id, $delay = self::TICK_EVERY)
    {
        if (!wp_next_scheduled(self::TICK, [(int) $blog_id])) {
            wp_schedule_single_event(time() + (int) $delay, self::TICK, [(int) $blog_id]);
        }
    }

    /** Cron: một lượt rồi tự hẹn lượt sau nếu chưa xong. */
    public static function tick($blog_id)
    {
        $blog_id = (int) $blog_id;
        $s = self::state($blog_id);
        if (!$s || empty($s['cron']) || in_array($s['phase'], ['done', 'failed'], true)) {
            return;
        }
        $r = self::step($blog_id);
        if (!is_wp_error($r) && empty($r['done'])) {
            self::schedule($blog_id);
        }
    }

    /** admin-ajax tgs_acc_switch: op = start | step | status | cancel; blog = blog đang đứng; quyền như nút ở tsm. */
    public static function ajax()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can((string) apply_filters('tgs_catalog_source_cap', 'manage_options'))) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        $blog = (int) get_current_blog_id();
        $op = sanitize_key((string) ($_POST['op'] ?? 'status'));
        @set_time_limit(120);
        if ($op === 'start') {
            $r = self::start($blog, ['cron' => !empty($_POST['cron'])]);
        } elseif ($op === 'step') {
            $r = self::step($blog);
        } elseif ($op === 'cancel') {
            $r = self::cancel($blog);
        } else {
            $r = self::status($blog);
        }
        if (is_wp_error($r)) {
            $st = self::status($blog);
            unset($st['result']);
            wp_send_json_error(['message' => $r->get_error_message(), 'status' => $st]);
        }
        unset($r['result']); // kết quả đầy đủ chỉ tsm đọc qua filter
        wp_send_json_success($r);
    }

    private static function who()
    {
        $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return $u && $u->ID ? $u->user_login : 'CLI';
    }

    private static function log($blog_id, array $entry)
    {
        $entry = ['t' => gmdate('c'), 'user' => self::who()] + $entry;
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
