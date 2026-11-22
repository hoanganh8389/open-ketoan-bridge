<?php
/**
 * Kiểm OKT-SC-2 không cần WordPress: tự tạm dừng khi sai / trùng tenant (không khi chỉ không gọi được), lịch 02:30 theo múi giờ
 * site, giữ hàng chờ của shop tạm dừng quá 7 ngày, bỏ tạm dừng thì chép nhóm còn mở sang tệp hôm nay.
 *
 *   php tests/sc2-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 02:30 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — test tự kiểm hằng ngày + giữ hàng chờ.

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('TGS_ACC_BRIDGE_PATH', dirname(__DIR__) . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tgs-acc-sc2-' . getmypid());
$GLOBALS['NET'] = [];
$GLOBALS['BLOG'] = [];
function apply_filters($tag, $v, ...$a) { return $v; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function delete_site_option($k) { unset($GLOBALS['NET'][$k]); return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
function delete_blog_option($b, $k) { unset($GLOBALS['BLOG'][(int) $b][$k]); return true; }
function get_option($k, $d = false) { return $d; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_time($t) { return date('Y-m-d H:i:s'); }
function wp_date($f, $ts = null) { return date($f, $ts ?? time()); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
class TGS_Acc_Config { static function key_mode($b) { return 'own'; } static function url_for($b) { return 'x'; } }
// [2026-11-23 02:20 AM] OKT-TOPO-2 — lớp bao kiểu cài thật; test này giả multisite (get_blog_option giả).
if (!function_exists('is_multisite')) { function is_multisite() { return true; } }
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-site.php';
require TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-events.php';
require TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-selfcheck.php';

$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "SAI: {$n}\n"; } }
function res($checks, $tenant = 'shopa') { return ['blog' => 2, 'tenant' => $tenant, 'at' => 'x', 'verdict' => 'fail', 'checks' => $checks]; }
function ck($id, $level, $data = []) { return ['id' => $id, 'level' => $level, 'message' => $id, 'data' => $data]; }

// 1. enforce: chỉ tự tạm dừng khi CHẮC sai / trùng tenant
$paused = [];
$p = function ($b, $why) use (&$paused) { $paused[] = [$b, $why]; };
$r = TGS_Acc_Selfcheck::enforce(res([ck('SC-2', 'fail', ['tenant' => 'shopb', 'confirmed' => 'shopa'])]), $p);
check('sai tenant: tự tạm dừng', count($paused) === 1 && strpos($paused[0][1], 'SC-2 khoá trỏ tenant shopb') !== false && ($r['enforced'] ?? '') === 'paused');
check('sai tenant: thêm dòng báo trong kết quả', end($r['checks'])['message'] !== '' && strpos(end($r['checks'])['message'], 'TỰ TẠM DỪNG') !== false);
$paused = [];
TGS_Acc_Selfcheck::enforce(res([ck('SC-3', 'fail', ['others' => [5]])]), $p);
check('trùng tenant: tự tạm dừng', count($paused) === 1 && strpos($paused[0][1], 'trùng blog 5') !== false);
$paused = [];
$r = TGS_Acc_Selfcheck::enforce(res([ck('SC-2', 'fail', ['status' => 0]), ck('SC-4', 'fail')]), $p);
check('KHÔNG gọi được kế toán: không tạm dừng', $paused === [] && !isset($r['enforced']));
$r = TGS_Acc_Selfcheck::enforce(res([ck('SC-2', 'fail', ['status' => 401]), ck('SC-4', 'fail')]), $p);
check('khoá bị từ chối: không tạm dừng (đã không gửi được)', $paused === []);
$r = TGS_Acc_Selfcheck::enforce(res([ck('SC-5', 'fail'), ck('SC-6', 'fail'), ck('SC-9', 'fail')]), $p);
check('mã thiếu: không tạm dừng, chỉ báo', $paused === []);
$r = TGS_Acc_Selfcheck::enforce(res([ck('SC-2', 'warn', ['tenant' => 'shopa'])]), $p);
check('chưa xác nhận tenant (vàng): không tạm dừng', $paused === []);

// 2. lịch 02:30 theo múi giờ site
$tz = new DateTimeZone('Asia/Ho_Chi_Minh');
$at = function ($s) use ($tz) { return (new DateTimeImmutable($s, $tz))->getTimestamp(); };
check('01:00 → 02:30 cùng ngày', TGS_Acc_Selfcheck::next_run($at('2026-11-22 01:00'), $tz) === $at('2026-11-22 02:30'));
check('02:30 đúng → ngày mai', TGS_Acc_Selfcheck::next_run($at('2026-11-22 02:30'), $tz) === $at('2026-11-23 02:30'));
check('14:00 → 02:30 ngày mai', TGS_Acc_Selfcheck::next_run($at('2026-11-22 14:00'), $tz) === $at('2026-11-23 02:30'));
check('02:30 giờ VN = 19:30 UTC hôm trước', gmdate('Y-m-d H:i', TGS_Acc_Selfcheck::next_run($at('2026-11-22 14:00'), $tz)) === '2026-11-22 19:30');

// 3. giữ hàng chờ của shop tạm dừng quá 7 ngày
$old = date('Y-m-d', time() - 20 * DAY_IN_SECONDS);
$f = TGS_Acc_Events::file($old, 'retry');
wp_mkdir_p(dirname($f));
file_put_contents($f, json_encode(['t' => $old . ' 09:00:00', 'rid' => 'g1', 'target' => '', 'blog' => 2, 'ledger' => [101], 'ct' => [], 'gt' => [], 'n' => 0, 'next' => 0, 'paused' => 1]) . "\n"
    . json_encode(['t' => $old . ' 09:05:00', 'rid' => 'g9', 'target' => '', 'blog' => 9, 'ledger' => [900], 'ct' => [], 'gt' => [], 'n' => 0, 'next' => 0, 'paused' => 1]) . "\n");
check('chưa tạm dừng: cửa sổ 7 ngày, không thấy nhóm 20 ngày', TGS_Acc_Events::window_days() === 7 && !isset(TGS_Acc_Events::retry_state()['g1']));
$GLOBALS['BLOG'][2][TGS_Acc_Events::PAUSE_OPTION] = date('Y-m-d H:i:s', time() - 21 * DAY_IN_SECONDS);
TGS_Acc_Events::pause(2, 'selfcheck: SC-2 thử');
check('pause trên shop đang tạm dừng: giữ mốc cũ', TGS_Acc_Events::paused_since(2) === $GLOBALS['BLOG'][2][TGS_Acc_Events::PAUSE_OPTION] && strtotime(TGS_Acc_Events::paused_since(2)) < time() - 20 * DAY_IN_SECONDS);
check('lý do tạm dừng lưu', TGS_Acc_Events::paused_reason(2) === 'selfcheck: SC-2 thử');
check('đang tạm dừng 21 ngày: cửa sổ ≥ 22 ngày', TGS_Acc_Events::window_days() >= 22);
check('đang tạm dừng: thấy nhóm 20 ngày', isset(TGS_Acc_Events::retry_state()['g1']));
check('queue_of thấy 1 nhóm của blog 2', TGS_Acc_Events::queue_of(2)['groups'] === 1);
$GLOBALS['NET'][TGS_Acc_Events::PAUSED_MAP][2] = date('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
check('cửa sổ tối đa 90 ngày', TGS_Acc_Events::window_days() === TGS_Acc_Events::PAUSED_KEEP_DAYS);
$GLOBALS['NET'][TGS_Acc_Events::PAUSED_MAP][2] = TGS_Acc_Events::paused_since(2);

// 4. bỏ tạm dừng → chép nhóm còn mở của blog sang tệp hôm nay, đến hạn ngay; blog khác không đụng
TGS_Acc_Events::resume(2);
check('bỏ tạm dừng: hết tạm dừng, xoá lý do, rời bản đồ', !TGS_Acc_Events::paused(2) && TGS_Acc_Events::paused_reason(2) === '' && !isset($GLOBALS['NET'][TGS_Acc_Events::PAUSED_MAP][2]));
check('bỏ tạm dừng: cửa sổ về 7 ngày', TGS_Acc_Events::window_days() === 7);
$st = TGS_Acc_Events::retry_state();
check('bỏ tạm dừng: nhóm g1 vẫn còn (đã chép sang hôm nay)', isset($st['g1']) && empty($st['g1']['closed']));
check('nhóm chép: đến hạn ngay, bỏ cờ paused, giữ t gốc', (int) $st['g1']['next'] === 0 && empty($st['g1']['paused']) && $st['g1']['t'] === $old . ' 09:00:00');
check('blog khác không bị chép', !isset($st['g9']));
$today = @file(TGS_Acc_Events::file(date('Y-m-d'), 'retry'), FILE_IGNORE_NEW_LINES) ?: [];
check('tệp hôm nay có đúng 1 dòng chép', count($today) === 1);

// dọn
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $x) { $x->isDir() ? @rmdir($x) : @unlink($x); }
@rmdir(WP_CONTENT_DIR);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
