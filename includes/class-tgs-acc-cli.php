<?php
/**
 * Lệnh WP-CLI của cầu nối kế toán (chạy theo từng shop):
 *
 *   wp tgs-acc status    --blog=<id>                    năm số của một shop: hàng chờ · chờ định khoản · chờ > 24 giờ ·
 *                                                       lỗi · lần gửi cuối; kèm nguồn phiếu, mã chi nhánh, tạm dừng
 *   wp tgs-acc preflight --blog=<id> --ledger=<id>     dựng chứng từ của một phiếu rồi KIỂM THỬ ở kế toán
 *                       [--no-post] [--json]            (POST /api/v1/intake/preflight — không ghi sự việc, không vào sổ)
 *   wp tgs-acc pause     --blog=<id>                    tạm dừng kết nối của shop (giữ khoá; vẫn ghi sự kiện, không gửi)
 *   wp tgs-acc resume    --blog=<id> [--now]            bỏ tạm dừng; --now: gửi bù hàng chờ ngay (không chờ cron)
 *   wp tgs-acc selfcheck --blog=<id> | --all            tự kiểm cặp blog → tenant qua API (SC-1…SC-11, luật I-8)
 *                       [--confirm-tenant] [--samples=5] [--format=table|json]   thoát 0 = xanh / vàng, 1 = có đỏ
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
        TGS_Acc_Push_Log::use_consumer('');
        $s = TGS_Acc_Push_Log::blog_stats($blog_id);
        $q = TGS_Acc_Events::queue_of($blog_id);
        $by = $s['by_status'];
        $since = TGS_Acc_Events::paused_since($blog_id);
        $warn = [];
        if ($since !== '' && strtotime($since) < strtotime(current_time('mysql')) - TGS_Acc_Events::RETRY_DAYS * DAY_IN_SECONDS) {
            $warn[] = 'Tạm dừng quá ' . TGS_Acc_Events::RETRY_DAYS . ' ngày: phần hàng chờ cũ hơn không còn trong tệp gửi lại — kéo bù ở trạm ACC14';
        }
        if ($s['pending_over_24h'] > 0) {
            $warn[] = $s['pending_over_24h'] . ' chứng từ chờ định khoản quá 24 giờ — báo kế toán duyệt ở ACC14';
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
     * --blog=<id>
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
     * --blog=<id>
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
     * --blog=<id>
     * : Shop.
     */
    public function pause($args, $assoc)
    {
        $blog = self::blog_arg($assoc);
        TGS_Acc_Events::pause($blog);
        WP_CLI::success("Đã tạm dừng kết nối của shop #{$blog}: vẫn ghi sự kiện, không gửi, không thử lại. Bỏ: wp tgs-acc resume --blog={$blog}");
    }

    /**
     * Bỏ tạm dừng kết nối của shop.
     *
     * ## OPTIONS
     * --blog=<id>
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

    // [2026-11-22 11:30 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — lệnh tự kiểm (luật I-8).
    /**
     * Tự kiểm cặp blog → tenant qua API (SC-1…SC-11). Không ghi kế toán (preflight save:false), không ghi sổ POS.
     *
     * ## OPTIONS
     * [--blog=<id>]
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
            $blogs = array_values(array_filter(array_map('intval', function_exists('get_sites') ? get_sites(['fields' => 'ids', 'number' => 0, 'deleted' => 0, 'archived' => 0, 'spam' => 0]) : [get_current_blog_id()]), function ($b) { return TGS_Acc_Config::key_mode($b) !== 'none'; }));
        } else {
            $blogs = [self::blog_arg($assoc)];
        }
        $opts = ['confirm_tenant' => !empty($assoc['confirm-tenant']), 'samples' => (int) ($assoc['samples'] ?? TGS_Acc_Selfcheck::SAMPLES)];
        $all = [];
        $worst = 0;
        foreach ($blogs as $b) {
            $r = TGS_Acc_Selfcheck::run($b, $opts);
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

    private static function blog_arg($assoc)
    {
        $b = (int) ($assoc['blog'] ?? 0);
        if ($b <= 0) {
            WP_CLI::error('Cần --blog=<blog_id>');
        }
        return $b;
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('tgs-acc', 'TGS_Acc_Cli');
}
