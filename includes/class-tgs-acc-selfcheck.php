<?php
/**
 * Tự kiểm theo cặp blog → tenant (ADR-OKT-1 I-8, open-setup docs/chuong-trinh/OKT/75-LUAT-TU-KIEM.md).
 *
 * Chạy QUA ĐÚNG API mà đường nối dùng, bằng ĐÚNG khoá của blog, đọc ĐÚNG sổ của blog. Kiểm chính những mã mà bridge
 * SẼ GỬI: dựng chứng từ của các phiếu gần nhất bằng đường thật (TGS_Acc_Pusher::build_refs → mapper), rồi đối chiếu
 * với danh mục của tenant. Không ghi gì vào kế toán (preflight save:false), không ghi sổ POS (I-0). Chỉ ghi option của
 * bridge: kết quả gần nhất, mã tenant đã xác nhận, bản đồ blog → tenant.
 *
 *   SC-1  cấu hình (khoá riêng, URL)          SC-7  chi nhánh + kho
 *   SC-2  danh tính tenant (/tenant)          SC-8  lý do xuất / nhập
 *   SC-3  một tenant ↔ một blog               SC-9  chứng từ mẫu thật → preflight
 *   SC-4  quyền của khoá                      SC-10 hàng chờ + trạng thái
 *   SC-5  phương thức thanh toán              SC-11 đồng hồ
 *   SC-6  mã thuế
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-22 11:30 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — tự kiểm SC-1…SC-11 theo cặp blog → tenant qua API (luật I-8).

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Selfcheck
{
    const TENANT_OPTION = 'tgs_acc_tenant_code';   // option của blog: mã tenant admin đã xác nhận
    const MAP_OPTION    = 'tgs_acc_tenant_map';    // site option: [blog_id => mã tenant thấy ở lần tự kiểm gần nhất]
    const LAST_OPTION   = 'tgs_acc_selfcheck_last'; // option của blog: kết quả gần nhất
    const SAMPLES       = 5;                        // số chứng từ gửi preflight (SC-9)
    const SCAN          = 200;                      // số phiếu gần nhất dựng để gom mã (SC-5…SC-8)
    const QUEUE_WARN_DAYS = 3;
    const QUEUE_FAIL_DAYS = 6;                      // hàng chờ giữ 7 ngày (TGS_Acc_Events::RETRY_DAYS)
    const CLOCK_WARN = 60;
    const CLOCK_FAIL = 300;

    /**
     * @param array $opts samples, scan, confirm_tenant (bool: lưu mã tenant đang thấy làm mã đã xác nhận)
     * @param array $deps (cho test) client, build(callable $blog, $ids), ids(callable $blog, $limit), status(callable $blog), now(int)
     * @return array{blog:int, tenant:string, at:string, verdict:string, checks:array[]}
     */
    public static function run($blog_id, array $opts = [], array $deps = [])
    {
        $blog = (int) $blog_id;
        $samples = max(0, (int) ($opts['samples'] ?? self::SAMPLES));
        $scan = max(1, (int) ($opts['scan'] ?? self::SCAN));
        $now = (int) ($deps['now'] ?? time());
        $c = [];

        // SC-1 cấu hình
        $mode = class_exists('TGS_Acc_Config') ? TGS_Acc_Config::key_mode($blog) : 'none';
        $url = class_exists('TGS_Acc_Config') ? TGS_Acc_Config::url_for($blog) : '';
        if ($mode === 'none' || trim($url) === '') {
            self::add($c, 'SC-1', 'fail', 'Blog chưa có ' . ($mode === 'none' ? 'khoá' : 'URL') . ' kế toán — không có đường nối để kiểm', ['key_mode' => $mode, 'url' => $url]);
            return self::finish($blog, '', $c, $now);
        }
        self::add($c, 'SC-1', $mode === 'own' ? 'pass' : 'warn', $mode === 'own' ? 'Khoá riêng của blog' : 'Đang dùng khoá chung của mạng (giai đoạn chuyển) — chưa chứng minh được một blog = một tenant', ['key_mode' => $mode, 'url' => $url]);

        $client = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog);

        // SC-2 danh tính tenant
        $t = $client->get('/api/v1/tenant');
        $tenant = '';
        if (is_wp_error($t)) {
            self::add($c, 'SC-2', 'fail', 'Không đọc được danh tính tenant: ' . $t->get_error_message(), ['status' => self::status_of($t)]);
            self::add($c, 'SC-4', self::denied($t) ? 'fail' : 'warn', self::denied($t) ? 'Khoá bị từ chối (' . self::status_of($t) . ')' : 'Không gọi được kế toán', []);
            return self::finish($blog, '', $c, $now);
        }
        $tenant = (string) ($t['data']['code'] ?? '');
        $stored = (string) get_blog_option($blog, self::TENANT_OPTION, '');
        if ($stored === '' && !empty($opts['confirm_tenant']) && $tenant !== '') {
            update_blog_option($blog, self::TENANT_OPTION, $tenant);
            $stored = $tenant;
        }
        if ($stored === '') {
            self::add($c, 'SC-2', 'warn', "Khoá trỏ tenant `{$tenant}` — chưa xác nhận. Đúng thì chạy lại với --confirm-tenant", ['tenant' => $tenant]);
        } elseif ($stored !== $tenant) {
            self::add($c, 'SC-2', 'fail', "Khoá trỏ tenant `{$tenant}`, blog đã xác nhận `{$stored}` — chứng từ sẽ vào sổ của đơn vị khác", ['tenant' => $tenant, 'confirmed' => $stored]);
        } else {
            self::add($c, 'SC-2', 'pass', "Tenant `{$tenant}` đúng mã đã xác nhận", ['tenant' => $tenant]);
        }

        // SC-3 một tenant ↔ một blog
        $map = (array) get_site_option(self::MAP_OPTION, []);
        $map[$blog] = $tenant;
        update_site_option(self::MAP_OPTION, $map);
        $twins = [];
        foreach ($map as $b => $code) {
            if ((int) $b !== $blog && $code !== '' && $code === $tenant && TGS_Acc_Config::key_mode((int) $b) !== 'none') {
                $twins[] = (int) $b;
            }
        }
        self::add($c, 'SC-3', $twins ? 'fail' : 'pass', $twins ? 'Tenant `' . $tenant . '` cũng đang nối với blog ' . implode(', ', $twins) . ' — sổ hai shop trộn nhau' : 'Không blog nào khác nối tenant này (theo các lần tự kiểm đã chạy)', ['others' => $twins]);

        // SC-4 quyền (đọc feed, đọc chứng từ); ghi được kiểm ở SC-9 qua preflight
        $meta = $client->get('/api/v1/catalog/feed/meta', ['since' => gmdate('c', $now)]);
        $docs_r = $client->get('/api/v1/documents', ['search' => 'okt-selfcheck', 'pageSize' => 1]);
        $bad = [];
        foreach (['feed/meta' => $meta, 'documents' => $docs_r] as $k => $r) {
            if (is_wp_error($r)) {
                $bad[$k] = self::status_of($r) . ' ' . $r->get_error_message();
            }
        }
        self::add($c, 'SC-4', $bad ? 'fail' : 'pass', $bad ? 'Khoá không đọc được: ' . implode('; ', array_map(function ($k, $v) { return "{$k} ({$v})"; }, array_keys($bad), $bad)) : 'Đọc được feed danh mục và trạng thái chứng từ', $bad);

        // SC-11 đồng hồ
        $st = is_wp_error($meta) ? '' : (string) ($meta['data']['serverTime'] ?? '');
        if ($st === '') {
            self::add($c, 'SC-11', 'warn', 'Không có serverTime để so đồng hồ', []);
        } else {
            $skew = abs(strtotime($st) - $now);
            self::add($c, 'SC-11', $skew > self::CLOCK_FAIL ? 'fail' : ($skew > self::CLOCK_WARN ? 'warn' : 'pass'), "Lệch đồng hồ {$skew} giây so với kế toán", ['skew' => $skew, 'serverTime' => $st]);
        }

        // Dựng chứng từ của các phiếu gần nhất bằng đường thật → gom mã bridge sẽ gửi
        $ids = isset($deps['ids']) ? call_user_func($deps['ids'], $blog, $scan) : self::recent_ids($blog, $scan);
        $built = $ids ? (isset($deps['build']) ? call_user_func($deps['build'], $blog, $ids) : TGS_Acc_Pusher::build_refs($blog, $ids)) : ['docs' => [], 'failed' => []];
        $docs = array_map(function ($d) { return class_exists('TGS_Acc_Pusher') && method_exists('TGS_Acc_Pusher', 'decorate') ? TGS_Acc_Pusher::decorate($d) : $d; }, (array) ($built['docs'] ?? []));
        $used = self::used_codes($docs);

        // SC-5…SC-8 đối chiếu danh mục tenant — mã ĐÃ DỊCH theo bảng ánh xạ của blog (S001-MD3)
        self::against($c, 'SC-5', 'phương thức thanh toán', $used['methods'], $client->get('/api/v1/posting/payment-methods'));
        // [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — chưa có bảng ánh xạ: mã đang suy từ nhãn (đặc tả 75 SC-5 warn).
        $map_ready = $deps['map_ready'] ?? (class_exists('TGS_Okt_Map') && TGS_Okt_Map::ready($blog));
        if (!$map_ready) {
            self::add($c, 'SC-5', 'warn', 'Chưa có bảng ánh xạ mã ({prefix}tgs_okt_map, open-pos-backend S001-MD3) — mã phương thức / lý do / thuế đang gửi đúng như bridge suy ra', []);
        }
        self::against($c, 'SC-6', 'mã thuế', $used['taxes'], $client->get('/api/v1/tax-codes'));
        $br = $client->get('/api/v1/branches');
        self::against($c, 'SC-7', 'chi nhánh', $used['branches'], $br);
        $wh = $client->get('/api/v1/warehouses');
        if (!is_wp_error($wh) && !(array) ($wh['data'] ?? [])) {
            self::add($c, 'SC-7', 'warn', 'Tenant chưa có kho nào — định khoản giá vốn theo kho chưa kiểm', []);
        }
        if ($used['warehouses']) {
            self::against($c, 'SC-7', 'kho', $used['warehouses'], $wh);
        }
        self::against($c, 'SC-8', 'lý do', $used['reasons'], $client->get('/api/v1/posting/reasons'));

        // SC-9 chứng từ mẫu thật → preflight (không ghi)
        if (!$docs) {
            self::add($c, 'SC-9', 'warn', 'Chưa có phiếu nào dựng được chứng từ' . (!empty($built['failed']) ? ' (' . implode('; ', array_slice((array) $built['failed'], 0, 3)) . ')' : ' — bán một đơn rồi chạy lại'), ['scanned' => count($ids)]);
        } else {
            usort($docs, function ($a, $b) { return ($a['docType'] === 'sale' ? 0 : 1) - ($b['docType'] === 'sale' ? 0 : 1); });
            $blocks = $warns = 0;
            $seen = [];
            $denied = false;
            foreach (array_slice($docs, 0, $samples) as $doc) {
                $doc['version'] = max(1, (int) ($doc['version'] ?? 1));
                $r = $client->post_json('/api/v1/intake/preflight', ['document' => $doc, 'save' => false, 'post' => true]);
                if (is_wp_error($r)) {
                    $denied = $denied || self::denied($r);
                    $blocks++;
                    $seen[] = $doc['docKey'] . ': ' . $r->get_error_message();
                    continue;
                }
                foreach ((array) ($r['findings'] ?? []) as $f) {
                    $f = (array) $f;
                    $sev = (string) ($f['severity'] ?? '');
                    if ($sev === 'block') {
                        $blocks++;
                        $seen[] = $doc['docKey'] . ': ' . ($f['code'] ?? '') . ' ' . ($f['title'] ?? '');
                    } elseif ($sev === 'warn') {
                        $warns++;
                        $seen[] = $doc['docKey'] . ': ' . ($f['code'] ?? '') . ' ' . ($f['title'] ?? '');
                    }
                }
            }
            if ($denied) {
                self::add($c, 'SC-4', 'fail', 'Khoá không được gửi chứng từ (preflight bị từ chối)', []);
            }
            $n = min($samples, count($docs));
            self::add($c, 'SC-9', $blocks ? 'fail' : ($warns ? 'warn' : 'pass'), "{$n} chứng từ mẫu: {$blocks} lỗi chặn, {$warns} cảnh báo", ['tested' => $n, 'block' => $blocks, 'warn' => $warns, 'findings' => array_slice(array_values(array_unique($seen)), 0, 10)]);
        }

        // SC-10 hàng chờ + trạng thái
        $s = isset($deps['status']) ? call_user_func($deps['status'], $blog) : TGS_Acc_Cli::status_data($blog);
        $q = isset($deps['queue']) ? call_user_func($deps['queue'], $blog) : TGS_Acc_Events::queue_of($blog);
        $age = ($q['oldest'] ?? '') !== '' ? max(0, ($now - strtotime((string) $q['oldest'])) / DAY_IN_SECONDS) : 0;
        $lvl = $age >= self::QUEUE_FAIL_DAYS ? 'fail' : (($age >= self::QUEUE_WARN_DAYS || $s['pending_over_24h'] > 0 || $s['errors'] > 0) ? 'warn' : 'pass');
        self::add($c, 'SC-10', $lvl, sprintf('Hàng chờ %d nhóm (cũ nhất %.1f ngày), chờ định khoản > 24 h: %d, lỗi: %d', (int) $q['groups'], $age, (int) $s['pending_over_24h'], (int) $s['errors']), ['queue' => (int) $q['groups'], 'oldest_days' => round($age, 1), 'pending_over_24h' => (int) $s['pending_over_24h'], 'errors' => (int) $s['errors'], 'paused' => !empty($s['paused'])]);

        return self::finish($blog, $tenant, $c, $now);
    }

    /** Mã bridge sẽ gửi, gom từ chứng từ đã dựng: [methods, taxes, branches, warehouses, reasons] → [mã => số lần]. */
    public static function used_codes(array $docs)
    {
        $u = ['methods' => [], 'taxes' => [], 'branches' => [], 'warehouses' => [], 'reasons' => []];
        $inc = function ($k, $v) use (&$u) {
            $v = trim((string) $v);
            if ($v !== '') {
                $u[$k][$v] = ($u[$k][$v] ?? 0) + 1;
            }
        };
        foreach ($docs as $d) {
            $inc('branches', $d['branchCode'] ?? '');
            $inc('reasons', $d['reasonCode'] ?? '');
            if (($d['docType'] ?? '') === 'customer_payment') {
                $inc('methods', $d['methodCode'] ?? '');
            }
            foreach ((array) ($d['payments'] ?? []) as $p) {
                $inc('methods', $p['methodCode'] ?? '');
            }
            foreach ((array) ($d['lines'] ?? []) as $l) {
                $inc('taxes', $l['taxCode'] ?? '');
                $inc('warehouses', $l['warehouseCode'] ?? '');
            }
        }
        return $u;
    }

    /** Đối chiếu mã đang dùng với danh mục tenant (GET trả data[] có `code`). */
    private static function against(array &$c, $id, $what, array $used, $res)
    {
        if (is_wp_error($res)) {
            self::add($c, $id, 'fail', "Không đọc được danh mục {$what} của tenant: " . $res->get_error_message(), []);
            return;
        }
        $have = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $have[(string) ((array) $row)['code']] = true;
        }
        if (!$used) {
            self::add($c, $id, 'pass', "Chưa có {$what} nào trên phiếu gần nhất để đối chiếu (tenant có " . count($have) . ')', ['tenant' => count($have)]);
            return;
        }
        $missing = array_diff_key($used, $have);
        if ($missing) {
            arsort($missing);
            $list = implode(', ', array_map(function ($k, $n) { return "{$k} ×{$n}"; }, array_keys($missing), $missing));
            self::add($c, $id, 'fail', "{$what}: " . count($missing) . " mã bridge sẽ gửi mà tenant không có — {$list}", ['missing' => $missing, 'used' => $used]);
            return;
        }
        self::add($c, $id, 'pass', "{$what}: " . count($used) . ' mã đang dùng đều có ở tenant', ['used' => $used]);
    }

    /** local_ledger_id của các phiếu gần nhất có chứng từ (bán 10, hoàn 11, thu 7, chi trả khách 8), đã duyệt, không phải bản sao. */
    public static function recent_ids($blog_id, $limit)
    {
        global $wpdb;
        $L = $wpdb->get_blog_prefix((int) $blog_id) . 'local_ledger';
        $nm = TGS_Acc_Source::not_mirror_sql($L);
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT local_ledger_id FROM {$L}
              WHERE local_ledger_type IN (10, 11, 7, 8) AND (is_deleted = 0 OR is_deleted IS NULL) AND local_ledger_approver_status = 1{$nm}
              ORDER BY local_ledger_id DESC LIMIT %d",
            (int) $limit
        )));
    }

    private static function add(array &$c, $id, $level, $message, array $data)
    {
        $c[] = ['id' => $id, 'level' => $level, 'message' => $message, 'data' => $data];
    }

    private static function status_of($err)
    {
        $d = method_exists($err, 'get_error_data') ? $err->get_error_data() : null;
        return is_array($d) && isset($d['status']) ? (int) $d['status'] : 0;
    }

    private static function denied($err)
    {
        return in_array(self::status_of($err), [401, 403], true);
    }

    private static function finish($blog, $tenant, array $checks, $now)
    {
        $levels = array_column($checks, 'level');
        $verdict = in_array('fail', $levels, true) ? 'fail' : (in_array('warn', $levels, true) ? 'warn' : 'pass');
        $out = ['blog' => $blog, 'tenant' => $tenant, 'at' => gmdate('c', $now), 'verdict' => $verdict, 'checks' => $checks];
        update_blog_option($blog, self::LAST_OPTION, $out);
        return $out;
    }
}
