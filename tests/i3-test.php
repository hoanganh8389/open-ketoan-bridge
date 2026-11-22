<?php
/**
 * Kiểm OKT-I3 không cần WordPress: bật / tắt gửi theo BLOG (cron gửi lại chạy ở site chính), quyết định gửi nền khi máy chủ
 * không trả trang sớm được, SC-10 báo đỏ khi thư mục sự kiện không ghi được.
 *
 *   php tests/i3-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 05:40 PM Johnny Chu - Chu Hoàng Anh] OKT-I3 — test gửi nền + bật theo blog.

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tgs-acc-i3-' . getmypid());
$GLOBALS['NET'] = ['tgs_acc_bridge_config' => []];   // mạng: KHÔNG có URL / khoá (mô hình khoá theo blog)
$GLOBALS['BLOG'] = [];
$GLOBALS['FILTERS'] = [];
$GLOBALS['CRON'] = false;
function apply_filters($tag, $v, ...$a) { return array_key_exists($tag, $GLOBALS['FILTERS']) ? $GLOBALS['FILTERS'][$tag] : $v; }
function is_multisite() { return true; }
function is_network_admin() { return false; }
function get_current_blog_id() { return 1; }               // như cron: chạy ở site chính
function get_site($id) { return (object) ['blog_id' => $id]; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
function get_option($k, $d = false) { return $d; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_date($f, $ts = null) { return date($f, $ts ?? time()); }
function current_time($t) { return date('Y-m-d H:i:s'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_doing_cron() { return $GLOBALS['CRON']; }
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } function get_error_data() { return null; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
// [2026-11-23 02:20 AM] OKT-TOPO-2 — lớp bao kiểu cài thật; test này giả multisite (get_blog_option giả).
if (!function_exists('is_multisite')) { function is_multisite() { return true; } }
require_once dirname(__DIR__) . '/includes/class-tgs-acc-site.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-config.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-events.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-selfcheck.php';

$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "SAI: {$n}\n"; } }

// 1. bật / tắt theo blog khi chạy ở site chính (cron)
$GLOBALS['BLOG'][2]['tgs_acc_bridge_config'] = ['url' => 'http://okt-thu-web-1', 'key' => 'k_shopa', 'realtime' => '1'];
$GLOBALS['BLOG'][3]['tgs_acc_bridge_config'] = [];
$GLOBALS['BLOG'][4]['tgs_acc_bridge_config'] = ['url' => 'http://x', 'key' => 'k4', 'realtime' => '0'];
check('site chính không cấu hình: enabled() = false (như trước)', TGS_Acc_Events::enabled() === false);
check('blog 2 có khoá riêng: enabled(2) = true — trước đây cron coi như tắt', TGS_Acc_Events::enabled(2) === true);
check('blog 3 không khoá: enabled(3) = false', TGS_Acc_Events::enabled(3) === false);
check('blog 4 tắt gửi tức thời: enabled(4) = false', TGS_Acc_Events::enabled(4) === false);
check('ready_for(2) / get_for(2, realtime)', TGS_Acc_Config::ready_for(2) === true && TGS_Acc_Config::get_for(2, 'realtime') === '1');
check('get_for blog không đặt → mặc định', TGS_Acc_Config::get_for(3, 'realtime') === '1');

// 2. gửi nền: CLI / cron gửi ngay; web không trả trang sớm được → gửi nền; filter đè được
check('CLI (không ai chờ): không gửi nền', TGS_Acc_Events::defer_send() === false);
$GLOBALS['FILTERS']['tgs_acc_events_defer'] = true;
check('filter ép gửi nền', TGS_Acc_Events::defer_send() === true);
$GLOBALS['FILTERS']['tgs_acc_events_defer'] = false;
check('filter tắt gửi nền', TGS_Acc_Events::defer_send() === false);
unset($GLOBALS['FILTERS']['tgs_acc_events_defer']);

// 3. khoá đánh thức: sinh một lần, giữ nguyên
$GLOBALS['NET']['tgs_acc_kick_key'] = '';
function wp_generate_password($n, $s = true) { return str_repeat('a', $n); }
$k1 = TGS_Acc_Events::kick_key();
check('khoá đánh thức sinh 32 ký tự và lưu', strlen($k1) === 32 && $GLOBALS['NET']['tgs_acc_kick_key'] === $k1 && TGS_Acc_Events::kick_key() === $k1);

// 4. thư mục ghi được
check('thư mục sự kiện ghi được (tạo khi chưa có)', TGS_Acc_Events::writable() === true);

// 5. SC-10: thư mục không ghi được / lỗi ghi trong 24 h → đỏ
function sc10(array $deps) {
    $base = ['client' => new class { function get($p, array $q = []) { return ['data' => $p === '/api/v1/tenant' ? ['code' => 'shopa'] : ($p === '/api/v1/catalog/feed/meta' ? ['serverTime' => gmdate('c')] : [])]; } function post_json($p, array $b) { return ['findings' => []]; } },
        'map_ready' => true, 'ids' => function () { return []; }, 'status' => function () { return ['pending_over_24h' => 0, 'errors' => 0, 'paused' => false]; }, 'queue' => function () { return ['groups' => 0, 'oldest' => '']; }];
    $r = TGS_Acc_Selfcheck::run(2, ['confirm_tenant' => true], $deps + $base);
    $l = [];
    foreach ($r['checks'] as $c) { if ($c['id'] === 'SC-10') { $l[] = $c['level']; } }
    return implode(',', $l);
}
check('SC-10: ghi được, không lỗi → chỉ pass', sc10(['writable' => true, 'write_error' => null]) === 'pass');
check('SC-10: không ghi được → fail', strpos(sc10(['writable' => false, 'write_error' => null]), 'fail') === 0);
check('SC-10: lỗi ghi 2 giờ trước → fail', strpos(sc10(['writable' => true, 'write_error' => ['at' => time() - 7200, 'file' => '/x']]), 'fail') === 0);
check('SC-10: lỗi ghi 3 ngày trước → không đỏ', sc10(['writable' => true, 'write_error' => ['at' => time() - 3 * DAY_IN_SECONDS, 'file' => '/x']]) === 'pass');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $x) { $x->isDir() ? @rmdir($x) : @unlink($x); }
@rmdir(WP_CONTENT_DIR);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
