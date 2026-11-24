<?php
/**
 * Plugin Name: OKT dev — G5 (bridge chưa có khoá)
 * Description: CHỈ máy dev, chỉ trong lúc chạy tests/g5-khong-khoa.sh (script chép vào mu-plugins rồi gỡ). Che mọi khoá
 *              của bridge (khoá blog, khoá chung, khoá cài đặt, khoá sổ thuế) — GIỮ URL kế toán (ca xấu nhất: có địa chỉ, không khoá);
 *              ghi + CHẶN mọi lời gọi HTTP đi ra (kể cả gọi vòng về chính site) vào /tmp/g5-http.log.
 */
// [2026-11-24 04:10 PM Johnny Chu - Chu Hoàng Anh] SW3-4 — ca vàng G5 (PHASE-001 doc 01): bridge có, chưa khoá ở blog nào → tự quản, 0 lời gọi ra kế toán.

if (!defined('ABSPATH')) { exit; }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { return; }
if (defined('TGS_KETOAN_API_KEY') || defined('TGS_KETOAN_API_KEYS') || defined('TGS_KETOAN_INSTALL_KEY')) { return; } // khoá cứng trong wp-config: không che được bằng option

define('TGS_G5_MU', true);
// Đối chứng (tests/g5-khong-khoa.sh DOI_CHUNG=1): có tệp /tmp/g5-giu-khoa thì GIỮ khoá thật, chỉ chặn + ghi HTTP — chứng minh bộ đếm bắt được lời gọi.
$tgs_g5_giu_khoa = file_exists('/tmp/g5-giu-khoa');
$tgs_g5_strip = function (callable $read) {
    return function ($pre) use ($read) {
        static $in = false;
        if ($in) { return $pre; }
        $in = true;
        $v = $read();
        $in = false;
        if (!is_array($v)) { return $pre; }
        foreach (['key', 'install_key', 'tax_key'] as $k) {
            if (array_key_exists($k, $v)) { $v[$k] = ''; }
        }
        return $v;
    };
};
if (!$tgs_g5_giu_khoa) {
    add_filter('pre_site_option_tgs_acc_bridge_config', $tgs_g5_strip(function () { return get_site_option('tgs_acc_bridge_config', false); }));
    add_filter('pre_option_tgs_acc_bridge_config', $tgs_g5_strip(function () { return get_option('tgs_acc_bridge_config', false); }));
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    $who = (defined('WP_CLI') && WP_CLI) ? 'cli' : ((defined('DOING_CRON') && DOING_CRON) ? 'cron' : (wp_doing_ajax() ? 'ajax' : 'web'));
    @file_put_contents('/tmp/g5-http.log', gmdate('H:i:s') . "\t{$who}\t" . ($args['method'] ?? 'GET') . "\t" . preg_replace('/\?.*$/', '', (string) $url) . "\n", FILE_APPEND);
    return new WP_Error('g5_chan', 'G5: chặn mọi lời gọi HTTP ra ngoài');
}, 1, 3);
