<?php
/**
 * Lệnh WP-CLI của cầu nối kế toán (chạy theo từng shop):
 *
 *   wp tgs-acc status    --shop=<id>                    năm số của một shop: hàng chờ · chờ định khoản · chờ > 24 giờ ·
 *                                                       lỗi · lần gửi cuối; kèm nguồn phiếu, mã chi nhánh, tạm dừng
 *   wp tgs-acc preflight --shop=<id> --ledger=<id>     dựng chứng từ của một phiếu rồi KIỂM THỬ ở kế toán
 *                       [--no-post] [--json]            (POST /api/v1/intake/preflight — không ghi sự việc, không vào sổ)
 *   wp tgs-acc pause     --shop=<id>                    tạm dừng kết nối của shop (giữ khoá; vẫn ghi sự kiện, không gửi)
 *   wp tgs-acc resume    --shop=<id> [--now]            bỏ tạm dừng; --now: gửi bù hàng chờ ngay (không chờ cron)
 *   wp tgs-acc selfcheck --shop=<id> | --all            tự kiểm cặp blog → tenant qua API (SC-1…SC-11, luật I-8)
 *                       [--confirm-tenant] [--samples=5] [--format=table|json]   thoát 0 = xanh / vàng, 1 = có đỏ
 *   wp tgs-acc map       --shop=<id> [--auto] [--set=<kind>:<mã bridge>=<mã tenant>]…   bảng ánh xạ mã (S001-MD3)
 *
 * Lớp chỉ đọc sổ POS (qua TGS_Acc_Pusher / TGS_Acc_Source). Không ghi sổ POS (ADR-OKT-1 I-0).
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-8, S002-11, S002-13 — lệnh preflight / status / pause / resume theo shop.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Cli
{
    /** Năm số + bối cảnh của một shop (dùng chung cho lệnh status và test). */
    public static function status_data($blog_id)
    {
        $blog_id = (int) $blog_id;
        TGS_Acc_Push_Log::maybe_install(); // OKT-TOPO-2: máy mới / một site chưa gửi lần nào → chưa có bảng nhật ký (đo okt-wp1: lỗi CSDL)
        TGS_Acc_Push_Log::use_consumer('');
        $s = TGS_Acc_Push_Log::blog_stats($blog_id);
        $q = TGS_Acc_Events::queue_of($blog_id);
        $by = $s['by_status'];
        $since = TGS_Acc_Events::paused_since($blog_id);
        $warn = [];
        if ($since !== '' && strtotime($since) < strtotime(current_time('mysql')) - TGS_Acc_Events::RETRY_DAYS * DAY_IN_SECONDS) {
            // [2026-11-22 02:00 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — hàng chờ của shop tạm dừng giữ tới PAUSED_KEEP_DAYS.
            $warn[] = 'Tạm dừng quá ' . TGS_Acc_Events::RETRY_DAYS . ' ngày: hàng chờ vẫn giữ (tối đa ' . TGS_Acc_Events::PAUSED_KEEP_DAYS . ' ngày), bỏ tạm dừng sớm để gửi bù';
        }
        if ($s['pending_over_24h'] > 0) {
            $warn[] = $s['pending_over_24h'] . ' chứng từ chờ định khoản quá 24 giờ — báo kế toán duyệt ở ACC14';
        }
        // [2026-11-22 06:10 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — cầu dao mở: đang không gửi trong request.
        $br = TGS_Acc_Events::breaker($blog_id);
        if (TGS_Acc_Events::breaker_open($blog_id)) {
            $warn[] = 'Cầu dao mở tới ' . wp_date('H:i:s', (int) $br['until']) . ' (lần ' . (int) $br['fails'] . '): kế toán không tới được — ' . $br['msg'] . '; đơn xếp hàng, cron thử lại';
        }
        if (($by['remote_error'] ?? 0) > 0) {
            $warn[] = $by['remote_error'] . ' chứng từ kế toán định khoản lỗi — xem lời kế toán trong nhật ký gửi';
        }
        return [
            'blog'             => $blog_id,
            'queue'            => (int) $q['groups'],
            'pending'          => (int) ($by['pending'] ?? 0),
            'pending_over_24h' => (int) $s['pending_over_24h'],
            'errors'           => (int) ($by['error'] ?? 0) + (int) ($by['remote_error'] ?? 0),
            'last_pushed'      => (string) $s['last_pushed'],
            'posted'           => (int) ($by['posted'] ?? 0),
            'paused'           => $since !== '',
            'paused_since'     => $since,
            'sale_source'      => TGS_Acc_Source::sale_source($blog_id),
            'branch_code'      => TGS_Acc_Source::branch_of_blog($blog_id),
            'key_mode'         => TGS_Acc_Config::key_mode($blog_id),
            'breaker'          => TGS_Acc_Events::breaker_open($blog_id) ? $br : null,
            'warnings'         => $warn,
        ];
    }

    /**
     * Dựng chứng từ của MỘT phiếu (theo id local_ledger) và kiểm thử ở kế toán. $client: TGS_Acc_Api_Client theo blog.
     * @return array{docs: array, results: array[], failed: string[]}  results: [docKey, docType, findings[], score, l5, error?]
     */
    public static function preflight_data($blog_id, $ledger_id, $client, $post = true)
    {
        $built = TGS_Acc_Pusher::build_refs((int) $blog_id, [(int) $ledger_id]);
        $out = ['docs' => $built['docs'], 'results' => [], 'failed' => (array) ($built['failed'] ?? [])];
        foreach ($built['docs'] as $doc) {
            $doc = TGS_Acc_Pusher::decorate($doc);
            $doc['version'] = max(1, (int) ($doc['version'] ?? 1));
            $res = $client->post_json('/api/v1/intake/preflight', ['document' => $doc, 'save' => false, 'post' => (bool) $post]);
            if (is_wp_error($res)) {
                $out['results'][] = ['docKey' => $doc['docKey'], 'docType' => $doc['docType'], 'error' => $res->get_error_message(), 'findings' => []];
                continue;
            }
            $out['results'][] = [
                'docKey'   => $doc['docKey'],
                'docType'  => $doc['docType'],
                'findings' => array_values((array) ($res['findings'] ?? [])),
                'score'    => $res['score'] ?? null,
                'l5'       => (string) ($res['l5'] ?? ''),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ lệnh

    /**
     * Năm số của một shop.
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop (blog id).
     * [--json]
     * : In JSON.
     */
    public function status($args, $assoc)
    {
        $d = self::status_data(self::blog_arg($assoc));
        if (!empty($assoc['json'])) {
            WP_CLI::line(wp_json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        WP_CLI::line("Shop #{$d['blog']}  nguồn phiếu: " . ($d['sale_source'] ?: '(chưa có)') . "  chi nhánh: " . ($d['branch_code'] ?: '(chưa có)') . "  khoá: {$d['key_mode']}" . ($d['paused'] ? "  ⏸ tạm dừng từ {$d['paused_since']}" : ''));
        WP_CLI::line("  hàng chờ gửi       {$d['queue']}");
        WP_CLI::line("  chờ định khoản     {$d['pending']}");
        WP_CLI::line("  chờ > 24 giờ       {$d['pending_over_24h']}");
        WP_CLI::line("  lỗi                {$d['errors']}");
        WP_CLI::line("  lần gửi cuối       " . ($d['last_pushed'] ?: '—'));
        foreach ($d['warnings'] as $w) {
            WP_CLI::warning($w);
        }
    }

    /**
     * Kiểm thử chứng từ của một phiếu ở kế toán (không ghi sổ).
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     * --ledger=<id>
     * : local_ledger_id của phiếu (bán, hoàn, thu…).
     * [--no-post]
     * : Không định khoản thử (chỉ kiểm L1–L4).
     * [--json]
     * : In JSON (kèm chứng từ đã dựng).
     */
    public function preflight($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        $ledger = (int) ($assoc['ledger'] ?? 0);
        if ($ledger <= 0) {
            WP_CLI::error('Cần --ledger=<local_ledger_id>');
        }
        $client = TGS_Acc_Api_Client::for_blog($blog);
        if (!$client->ready()) {
            WP_CLI::error("Shop #{$blog} chưa có URL / khoá kế toán");
        }
        $d = self::preflight_data($blog, $ledger, $client, !isset($assoc['post']) || $assoc['post'] !== false);
        if (!empty($assoc['json'])) {
            WP_CLI::line(wp_json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        if (!$d['docs']) {
            WP_CLI::warning('Không dựng được chứng từ nào cho phiếu này' . ($d['failed'] ? ': ' . implode('; ', $d['failed']) : ' (chưa duyệt, đã xoá, hoặc chưa bật nguồn phiếu — xem `wp tgs-acc status`)'));
            return;
        }
        $bad = 0;
        foreach ($d['results'] as $r) {
            if (isset($r['error'])) {
                $bad++;
                WP_CLI::line("✘ {$r['docKey']} ({$r['docType']}): {$r['error']}");
                continue;
            }
            $n = count($r['findings']);
            WP_CLI::line(($n ? '•' : '✔') . " {$r['docKey']} ({$r['docType']}): {$n} phát hiện · điểm " . var_export($r['score'], true) . " · L5 {$r['l5']}");
            foreach ($r['findings'] as $f) {
                $f = (array) $f;
                WP_CLI::line('    - ' . trim(($f['severity'] ?? $f['level'] ?? '') . ' ' . ($f['code'] ?? '') . ' ' . ($f['message'] ?? $f['text'] ?? wp_json_encode($f, JSON_UNESCAPED_UNICODE))));
            }
            $bad += $n ? 1 : 0;
        }
        $bad ? WP_CLI::warning("{$bad} chứng từ có phát hiện") : WP_CLI::success('Không có phát hiện nào');
    }

    /**
     * Tạm dừng kết nối của shop.
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     */
    public function pause($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        TGS_Acc_Events::pause($blog);
        WP_CLI::success("Đã tạm dừng kết nối của shop #{$blog}: vẫn ghi sự kiện, không gửi, không thử lại. Bỏ: wp tgs-acc resume --shop={$blog}");
    }

    /**
     * [2026-11-24 08:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-1 — dựng sẵn tenant trước lần đẩy danh mục đầu: cây nhóm có mã SKU,
     * bảng giá công ty + bảng giá của shop, chi nhánh, gán chi nhánh. Mặc định chỉ xem kế hoạch (chỉ GET).
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     * [--run]
     * : Ghi thật lên kế toán (không có = chạy thử, chỉ in kế hoạch).
     */
    public function prepare($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        $r = TGS_Acc_Tenant_Prepare::run($blog, empty($assoc['run']));
        foreach (['plan' => 'sẽ làm', 'done' => 'đã làm', 'skipped' => 'bỏ qua', 'notes' => 'lưu ý', 'errors' => 'LỖI'] as $k => $label) {
            foreach ($r[$k] as $line) {
                WP_CLI::log("  [{$label}] {$line}");
            }
        }
        $sum = count($r['plan']) . ' việc sẽ làm · ' . count($r['done']) . ' đã làm · ' . count($r['skipped']) . ' đã có · ' . count($r['errors']) . ' lỗi';
        $r['ok'] ? WP_CLI::success(($r['dry'] ? 'Chạy thử — ' : '') . "Shop #{$blog}: {$sum}" . ($r['dry'] && $r['plan'] ? '. Ghi thật: thêm --run' : '')) : WP_CLI::error("Shop #{$blog}: {$sum}", false);
    }

    /**
     * [2026-11-24 09:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-2 — kiểm thử danh mục của shop ở kế toán (POST /catalog/products/import
     * dryRun = true): kế toán kiểm từng dòng, không ghi gì. In tổng và các dòng lỗi.
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     * [--limit=<n>]
     * : Số dòng lỗi in ra (mặc định 50).
     */
    public function dryrun($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        $r = TGS_Acc_Catalog_Dryrun::run($blog);
        foreach ($r['errors'] as $e) {
            WP_CLI::warning($e);
        }
        foreach (array_slice($r['rows'], 0, (int) ($assoc['limit'] ?? 50)) as $x) {
            WP_CLI::log(sprintf('  dòng %d  %s  — %s', $x['row'], $x['sku'], implode(' · ', $x['errors'])));
        }
        $sum = "{$r['total']} mã · {$r['create']} sẽ thêm · {$r['update']} sẽ sửa · {$r['failed']} lỗi";
        $r['ok'] ? WP_CLI::success("Shop #{$blog}: {$sum} — sẵn sàng đẩy") : WP_CLI::error("Shop #{$blog}: {$sum}", false);
    }

    /**
     * Bỏ tạm dừng kết nối của shop.
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     * [--now]
     * : Gửi bù hàng chờ ngay.
     */
    public function resume($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        TGS_Acc_Events::resume($blog);
        $q = TGS_Acc_Events::queue_of($blog);
        if (!empty($assoc['now'])) {
            $r = TGS_Acc_Events::retry(true);
            WP_CLI::success("Đã bỏ tạm dừng shop #{$blog}; gửi bù {$r['retried']} nhóm, đạt {$r['ok']}.");
            return;
        }
        WP_CLI::success("Đã bỏ tạm dừng shop #{$blog}; {$q['groups']} nhóm trong hàng chờ sẽ gửi ở lượt cron kế tiếp (thêm --now để gửi ngay).");
    }

    // [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — lệnh xem / tự dựng / đặt ánh xạ mã.
    /**
     * Bảng ánh xạ mã bridge sinh → mã ở tenant (phương thức, lý do, thuế) của một shop.
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     * [--auto]
     * : Tự dựng từ các phiếu gần nhất + danh mục tenant (không ghi đè dòng đã xác nhận).
     * [--set=<kind:local=remote>]
     * : Đặt (xác nhận) một dòng, vd --set=method:QR-VTB-CT=QR. Lặp được bằng dấu phẩy.
     * [--format=<f>]
     * : table (mặc định) | json.
     */
    public function map($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        if (!class_exists('TGS_Okt_Map')) {
            WP_CLI::error('Chưa có bảng ánh xạ: cần open-pos-backend có S001-MD3 (DB_VERSION 1.1.63).');
        }
        if (!empty($assoc['set'])) {
            foreach (array_filter(array_map('trim', explode(',', (string) $assoc['set']))) as $one) {
                if (!preg_match('/^(method|reason|tax):([^=]+)=(.*)$/', $one, $m)) {
                    WP_CLI::error("--set sai dạng: {$one} (đúng: method:QR-VTB-CT=QR)");
                }
                TGS_Okt_Map::set($blog, $m[1], trim($m[2]), trim($m[3]), 'admin');
                WP_CLI::line("  ✔ {$m[1]}: {$m[2]} → " . (trim($m[3]) !== '' ? trim($m[3]) : '(bỏ ánh xạ)'));
            }
        }
        if (!empty($assoc['auto'])) {
            $r = TGS_Acc_Code_Map::auto($blog);
            if (is_wp_error($r)) {
                WP_CLI::error('Không tự dựng được: ' . $r->get_error_message());
            }
            WP_CLI::line("Tự dựng từ {$r['scanned']} phiếu gần nhất, tenant {$r['tenant']}");
        }
        $rows = TGS_Okt_Map::rows($blog);
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        $miss = 0;
        foreach ($rows as $kind => $list) {
            WP_CLI::line("[{$kind}]");
            foreach ($list as $local => $r) {
                $empty = (string) $r['remote_code'] === '';
                $miss += $empty ? 1 : 0;
                WP_CLI::line(sprintf('  %s %-22s → %-14s %s ×%d %s', $empty ? '✕' : '✓', $local, $empty ? '(chưa ánh xạ)' : $r['remote_code'], $r['map_source'] === 'admin' ? 'đã xác nhận' : 'gợi ý', (int) $r['used_count'], $r['local_label'] !== '' ? '· ' . $r['local_label'] : ''));
            }
        }
        if (!$rows) {
            WP_CLI::warning('Chưa có dòng nào — chạy với --auto.');
        } elseif ($miss) {
            WP_CLI::warning("{$miss} dòng chưa ánh xạ — đặt bằng --set hoặc ở Quản lý Shop → Ánh xạ kế toán.");
        } else {
            WP_CLI::success('Mọi mã đã ánh xạ.');
        }
    }

    // [2026-11-22 11:30 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — lệnh tự kiểm (luật I-8).
    /**
     * Tự kiểm cặp blog → tenant qua API (SC-1…SC-11). Không ghi kế toán (preflight save:false), không ghi sổ POS.
     *
     * ## OPTIONS
     * [--shop=<id>]
     * : Shop.
     * [--all]
     * : Mọi blog có khoá (riêng hoặc chung).
     * [--confirm-tenant]
     * : Lưu mã tenant đang thấy làm mã đã xác nhận của blog (lần đầu).
     * [--samples=<n>]
     * : Số chứng từ mẫu gửi preflight (mặc định 5).
     * [--format=<f>]
     * : table (mặc định) | json.
     */
    public function selfcheck($args, $assoc)
    {
        if (!empty($assoc['all'])) {
            $blogs = array_values(array_filter(TGS_Acc_Site::blogs(), function ($b) { return TGS_Acc_Config::key_mode($b) !== 'none'; }));
        } else {
            $blogs = [self::blog_arg($assoc)];
        }
        $opts = ['confirm_tenant' => !empty($assoc['confirm-tenant']), 'samples' => (int) ($assoc['samples'] ?? TGS_Acc_Selfcheck::SAMPLES)];
        $all = [];
        $worst = 0;
        foreach ($blogs as $b) {
            $r = TGS_Acc_Selfcheck::enforce(TGS_Acc_Selfcheck::run($b, $opts)); // OKT-SC-2: sai / trùng tenant → tự tạm dừng gửi
            $all[] = $r;
            $worst = max($worst, $r['verdict'] === 'fail' ? 1 : 0);
            if (($assoc['format'] ?? 'table') === 'json') {
                continue;
            }
            $icon = ['pass' => '🟢', 'warn' => '🟡', 'fail' => '🔴'];
            WP_CLI::line("{$icon[$r['verdict']]} Shop #{$b} → tenant " . ($r['tenant'] ?: '?') . "  ({$r['at']})");
            foreach ($r['checks'] as $ck) {
                WP_CLI::line('   ' . $icon[$ck['level']] . ' ' . str_pad($ck['id'], 6) . ' ' . $ck['message']);
            }
        }
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode(count($all) === 1 ? $all[0] : $all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if (!$blogs) {
            WP_CLI::warning('Không có blog nào có khoá kế toán.');
        }
        if ($worst) {
            WP_CLI::halt(1);
        }
    }

    // [2026-11-25 08:20 AM Johnny Chu - Chu Hoàng Anh] SW2-1 — kết nối theo blog.
    /**
     * Bốn điều kiện kết nối của shop (có khoá · kế toán trả lời · biết tenant · không trùng tenant). Hỏi lại ngay, không dùng đệm 60 giây.
     *
     * ## OPTIONS
     * [--shop=<id>]
     * : Shop (blog id).
     * [--all]
     * : Mọi shop có khoá.
     * [--format=<f>]
     * : table (mặc định) | json
     */
    public function connection($args, $assoc)
    {
        $blogs = !empty($assoc['all']) ? TGS_Acc_Selfcheck::blogs_with_key() : [self::blog_arg($assoc)];
        $all = [];
        $bad = 0;
        foreach ($blogs as $b) {
            $c = TGS_Acc_Connection::connection($b, true);
            $all[] = $c;
            $bad += $c['ok'] ? 0 : 1;
            if (($assoc['format'] ?? 'table') === 'json') {
                continue;
            }
            WP_CLI::line(($c['ok'] ? '🟢' : '🔴') . " Shop #{$b} · {$c['state']} · tenant " . ($c['tenant'] ?: '—') . ' · khoá ' . $c['key_mode'] . ($c['error'] !== '' ? ' · ' . $c['error'] : ''));
            foreach ($c['conditions'] as $name => $x) {
                WP_CLI::line('   ' . ($x['ok'] ? '🟢' : '🔴') . ' ' . str_pad($name, 10) . ($x['note'] !== '' ? ' ' . $x['note'] : ''));
            }
        }
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode(count($all) === 1 ? $all[0] : $all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if (!$blogs) {
            WP_CLI::warning('Không có blog nào có khoá kế toán.');
        }
        if ($bad) {
            WP_CLI::halt(1);
        }
    }

    // [2026-11-23 06:50 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-9 — xem / đặt mô hình triển khai (luật tối thượng P-0).
    /**
     * Mô hình triển khai: bizcity · chain (tự cài nhiều cửa hàng) · single (một site). Hằng TGS_ACC_DEPLOYMENT thắng và khoá lựa chọn.
     *
     * ## OPTIONS
     * [--set=<mo-hinh>]
     * : bizcity | chain (multisite) · single (WordPress một site, tự nhận)
     */
    public function profile($args, $assoc)
    {
        if (isset($assoc['set'])) {
            $r = TGS_Acc_Profile::set((string) $assoc['set']);
            if (is_wp_error($r)) {
                WP_CLI::error($r->get_error_message());
            }
        }
        $p = TGS_Acc_Profile::current();
        WP_CLI::line('Mô hình: ' . ($p !== '' ? $p . ' — ' . TGS_Acc_Profile::label($p) : '(chưa khai)'));
        WP_CLI::line('Nguồn:   ' . ['constant' => 'hằng TGS_ACC_DEPLOYMENT (khoá)', 'option' => 'đã chọn (option mạng tgs_acc_deployment)', 'auto' => 'tự nhận — WordPress một site', 'none' => 'chưa khai'][TGS_Acc_Profile::source()]);
        WP_CLI::line('Chọn được: ' . implode(', ', TGS_Acc_Profile::allowed()));
        if ($p === '') {
            WP_CLI::warning('Máy chủ bizcity: đặt hằng TGS_ACC_DEPLOYMENT = bizcity trong wp-config.php. Tự cài nhiều cửa hàng: wp tgs-acc profile --set=chain');
        }
    }

    // [2026-11-23 12:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 — khoá cài đặt (mô hình nhiều cửa hàng): trạng thái, đăng ký cửa hàng, ping.
    /**
     * Khoá cài đặt open-pos (mô hình chain): mỗi cửa hàng một tenant, nối bằng mã nối kế toán nhập ở open-ketoan.
     *
     * ## OPTIONS
     * [--register]
     * : đăng ký mọi blog đi khoá cài đặt (blog mới → chờ + mã nối)
     * [--renew=<blog>]
     * : xin mã nối mới cho blog đang chờ (nhiều blog: 2,3)
     * [--refresh]
     * : đọc trạng thái từ open-ketoan
     * [--ping]
     * : ping ngay (như lịch 5 phút)
     */
    public function install($args, $assoc)
    {
        $renew = isset($assoc['renew']) ? array_filter(array_map('intval', explode(',', (string) $assoc['renew']))) : [];
        $r = null;
        if (!empty($assoc['register']) || $renew) {
            $r = TGS_Acc_Install::register($renew ?: [], $renew);
        } elseif (!empty($assoc['refresh'])) {
            $r = TGS_Acc_Install::refresh();
        }
        if (is_wp_error($r)) {
            WP_CLI::error($r->get_error_message());
        }
        if (!empty($assoc['ping'])) {
            TGS_Acc_Install::ping();
        }
        $t = TGS_Acc_Install::topology();
        WP_CLI::line('Mô hình: ' . ($t['profile'] ?: '(chưa khai)') . ' · khoá cài đặt: ' . ($t['install']['key_masked'] ?: '(chưa có)') . ' · mã bản cài: ' . ($t['install']['code'] ?: '(chưa biết)') . ' · ' . ($t['install']['active'] ? 'ĐANG DÙNG' : 'không dùng'));
        if ($t['install']['error'] !== '') {
            WP_CLI::warning('Lần gọi open-ketoan gần nhất lỗi: ' . $t['install']['error']);
        }
        $rows = array_map(function ($b) {
            $p = (array) ($b['ping'] ?? []);
            return ['blog' => $b['blog'], 'tên' => $b['name'], 'khoá' => $b['key_mode'], 'nối' => $b['link'] ?: '—', 'tenant' => $b['tenant'] ?: '—', 'mã nối' => $b['link_code'] ?: '', 'ping' => $p ? (!empty($p['ok']) ? $p['ms'] . ' ms' : '✕ ' . mb_substr((string) ($p['msg'] ?? ''), 0, 40)) : '—', 'hàng chờ' => $b['queue']];
        }, $t['blogs']);
        WP_CLI\Utils\format_items('table', $rows, ['blog', 'tên', 'khoá', 'nối', 'tenant', 'mã nối', 'ping', 'hàng chờ']);
        foreach ($t['hosts'] as $h) {
            WP_CLI::line('Máy ' . $h['url'] . ': ' . (isset($h['ok']) ? ($h['ok'] ? $h['ms'] . ' ms' : '✕ ' . ($h['msg'] ?? '')) : 'chưa ping') . (isset($h['version']) && $h['version'] !== '' ? ' · bản ' . $h['version'] : '') . ' · ' . $h['tenants'] . ' tenant / ' . $h['blogs'] . ' site');
        }
    }

    // [2026-11-22 09:45 PM Johnny Chu - Chu Hoàng Anh] OKT-H4 — đối chiếu WP ↔ kế toán (chỉ đọc).
    /**
     * Đối chiếu chứng từ (thiếu, mồ côi, chờ, lỗi) và tồn theo SKU giữa WP và kế toán của shop. Chỉ đọc.
     *
     * ## OPTIONS
     * --shop=<id>
     * : Shop.
     * [--days=<n>]
     * : Số ngày gần nhất (mặc định 7).
     * [--from=<Y-m-d>]
     * [--to=<Y-m-d>]
     * [--skus=<n>]
     * : Số mã đối chiếu tồn (mặc định 50, mã biến động nhiều nhất trước).
     * [--format=<f>]
     * : table | json
     */
    public function reconcile($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        $opts = array_filter(['days' => $assoc['days'] ?? null, 'from' => $assoc['from'] ?? null, 'to' => $assoc['to'] ?? null, 'skus' => $assoc['skus'] ?? null], static function ($v) { return $v !== null; });
        $r = TGS_Acc_Reconcile::run($blog, $opts);
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }
        $icon = ['pass' => '🟢', 'warn' => '🟡', 'fail' => '🔴'];
        WP_CLI::line("{$icon[$r['verdict']]} Đối chiếu shop #{$blog} (chi nhánh {$r['branch']}) {$r['from']} → {$r['to']}");
        foreach ($r['checks'] as $ck) {
            WP_CLI::line('   ' . $icon[$ck['level']] . ' ' . str_pad($ck['id'], 4) . ' ' . $ck['message']);
            foreach (array_slice((array) ($ck['data']['keys'] ?? []), 0, 5) as $k) {
                WP_CLI::line('          · ' . $k);
            }
        }
        if ($r['stock']) {
            WP_CLI\Utils\format_items('table', array_slice($r['stock'], 0, 20), ['sku', 'wp', 'ketoan', 'chua_dinh_khoan', 'lech']);
        }
        if ($r['verdict'] === 'fail') {
            WP_CLI::halt(1);
        }
    }

    private static function blog_arg($assoc)
    {
        // [2026-11-22 04:00 PM Johnny Chu - Chu Hoàng Anh] WP-CLI giữ --blog làm tham số chung (tên cũ của --url) nên --blog=N không tới lệnh: dùng --shop=N.
        $b = (int) ($assoc['shop'] ?? ($assoc['blog'] ?? 0));
        if ($b <= 0) {
            WP_CLI::error('Cần --shop=<blog_id>');
        }
        return $b;
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('tgs-acc', 'TGS_Acc_Cli');
}
