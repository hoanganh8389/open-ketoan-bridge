<?php
/**
 * OKT-TOPO-9: TGS_Acc_Profile ở MULTISITE — thứ tự hằng → lựa chọn ở mạng; không đoán từ khoá; hằng khoá lựa chọn.
 * (Một site: tests/site-single-test.php.)
 *
 *   php tests/profile-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-23 06:50 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-9 — luật tối thượng P-0: mô hình khai rõ.

define('ABSPATH', __DIR__ . '/');
$GLOBALS['NET'] = [];
$GLOBALS['ACT'] = [];
function is_multisite() { return true; }
function get_blog_option($b, $k, $d = false) { return $d; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function do_action($h, ...$a) { $GLOBALS['ACT'][] = [$h, $a]; }
class WP_Error { public $c; function __construct($c = '', $m = '') { $this->c = $c; } function get_error_code() { return $this->c; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
class TGS_Acc_Config { static function key_legacy() { return 'khoa-chung-cua-mang'; } static function key_mode($b) { return 'legacy'; } }
require dirname(__DIR__) . '/includes/class-tgs-acc-site.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-profile.php';

$ok = 0; $fail = 0;
function check($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}\n"; } }

check('multisite chưa khai: current() rỗng — KHÔNG đoán từ khoá chung đang có', TGS_Acc_Profile::current() === '' && TGS_Acc_Profile::source() === 'none');
check('multisite chọn được bizcity / chain, không chọn single', TGS_Acc_Profile::allowed() === ['bizcity', 'chain']);
$e = TGS_Acc_Profile::set('single');
check('chọn single trên multisite → lỗi', $e instanceof WP_Error && $e->get_error_code() === 'tgs_acc_profile_bad' && TGS_Acc_Profile::current() === '');
check('chọn mô hình lạ → lỗi', TGS_Acc_Profile::set('tenant') instanceof WP_Error);
check('chọn chain → lưu option mạng, phát hook', TGS_Acc_Profile::set('chain') === true && $GLOBALS['NET']['tgs_acc_deployment'] === 'chain' && end($GLOBALS['ACT'])[0] === 'tgs_acc_profile_changed');
check('current() = chain, nguồn: lựa chọn', TGS_Acc_Profile::current() === 'chain' && TGS_Acc_Profile::source() === 'option');
$GLOBALS['NET']['tgs_acc_deployment'] = 'gì-đó';
check('option hỏng → coi như chưa khai', TGS_Acc_Profile::current() === '');
$GLOBALS['NET']['tgs_acc_deployment'] = 'chain';

define('TGS_ACC_DEPLOYMENT', 'BizCity');
check('hằng thắng lựa chọn (không phân biệt hoa thường)', TGS_Acc_Profile::current() === 'bizcity' && TGS_Acc_Profile::source() === 'constant' && TGS_Acc_Profile::locked());
check('hằng khoá lựa chọn: chỉ còn bizcity', TGS_Acc_Profile::allowed() === ['bizcity']);
$e = TGS_Acc_Profile::set('chain');
check('đổi khi đã có hằng → lỗi, không ghi', $e instanceof WP_Error && $e->get_error_code() === 'tgs_acc_profile_locked' && TGS_Acc_Profile::current() === 'bizcity');
check('nhãn mô hình', strpos(TGS_Acc_Profile::label('chain'), 'khoá cài đặt') !== false && TGS_Acc_Profile::label('x') === 'chưa khai');

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
