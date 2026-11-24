<?php
/**
 * SW3-4 — hàng chờ gửi lại: nhóm của blog đã gỡ khỏi mạng được đóng (gave_up blog_gone), không đọc option của blog không còn bảng;
 * nhóm của blog còn sống giữ nguyên. Chạy TRONG WordPress multisite thử; chặn mọi HTTP; tự dọn.
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/retry-blog-gone-wp-test.php
 */
// [2026-11-24 04:40 PM Johnny Chu - Chu Hoàng Anh] SW3-4 — bắt khi chạy ca vàng G5: 506 truy vấn lỗi wp_N_options mỗi lượt cron.

if (!defined('ABSPATH')) { exit(1); }
if (!is_multisite()) { echo "  ·   máy một site: không có blog bị gỡ — bỏ\nKẾT QUẢ: ĐẠT 0/0\n"; return; }
if (!preg_match('/\.local$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ multisite thử (.local).\n"); exit(1); }
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };

$live = array_flip(TGS_Acc_Site::blogs(false));
$gone_blog = 999999;
$live_blog = (int) array_key_first($live);
$check("blog {$gone_blog} không có trong mạng", !isset($live[$gone_blog]));

$append = new ReflectionMethod('TGS_Acc_Events', 'append');
$append->setAccessible(true);
$date = wp_date('Y-m-d');
$rid_gone = 'g5gone-' . wp_generate_password(8, false, false);
$rid_live = 'g5live-' . wp_generate_password(8, false, false);
$append->invoke(null, $date, [
    ['t' => gmdate('c'), 'rid' => $rid_gone, 'target' => '', 'blog' => $gone_blog, 'ledger' => [1], 'ct' => [], 'gt' => [], 'n' => 1, 'next' => time() - 60, 'msg' => 'thử'],
    ['t' => gmdate('c'), 'rid' => $rid_live, 'target' => '', 'blog' => $live_blog, 'ledger' => [1], 'ct' => [], 'gt' => [], 'n' => 1, 'next' => time() + 3600, 'msg' => 'thử'],
], 'retry');

$http = 0;
$block = function () use (&$http) { $http++; return new WP_Error('g5_chan', 'chặn'); };
add_filter('pre_http_request', $block, 1);
$bad = 0;
$q = function ($sql) use (&$bad, $live) {
    if (preg_match_all('/\bwp_(\d+)_options\b/', $sql, $m)) { foreach ($m[1] as $b) { if (!isset($live[(int) $b])) { $bad++; } } }
    return $sql;
};
add_filter('query', $q);
delete_site_option('tgs_acc_events_retry_lock');
$r1 = TGS_Acc_Events::retry();
$bad1 = $bad; $bad = 0;
$r2 = TGS_Acc_Events::retry();
remove_filter('query', $q);
remove_filter('pre_http_request', $block, 1);

$st = TGS_Acc_Events::retry_state();
$g = $st[$rid_gone] ?? [];
$check('nhóm của blog đã gỡ: đóng, lý do blog_gone', !empty($g['closed']) && strpos((string) ($g['msg'] ?? ''), 'blog_gone') === 0, json_encode($g, JSON_UNESCAPED_UNICODE));
$check('nhóm của blog còn sống (chưa đến hạn): giữ nguyên mở', isset($st[$rid_live]) && empty($st[$rid_live]['closed']));
$open_gone = 0;
foreach ($st as $r) { if (empty($r['closed']) && (int) ($r['blog'] ?? 0) > 0 && !isset($live[(int) $r['blog']])) { $open_gone++; } }
$check('hàng chờ không còn nhóm mở của blog đã gỡ', $open_gone === 0, "{$open_gone} nhóm");
$check('lượt sau: 0 truy vấn option của blog không còn bảng', $bad === 0, "lượt 1: {$bad1}, lượt 2: {$bad}");
$check('không lời gọi HTTP nào (không gửi gì của blog đã gỡ)', $http === 0, "{$http} lời gọi");
$check('retry() không bị khoá chồng', empty($r1['locked']) && empty($r2['locked']));

// dọn: đóng nhóm thử của blog còn sống
$append->invoke(null, $date, [['t' => gmdate('c'), 'rid' => $rid_live, 'done' => 1, 'n' => 1, 'sent' => 0, 'cancelled' => 0]], 'retry');
$check('dọn nhóm thử', !empty(TGS_Acc_Events::retry_state()[$rid_live]['closed']));
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
