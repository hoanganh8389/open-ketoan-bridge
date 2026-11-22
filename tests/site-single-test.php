<?php
/**
 * OKT-TOPO-2: TGS_Acc_Site ở WordPress MỘT SITE — không có get_blog_option / switch_to_blog / get_sites (như WP thật khi không multisite).
 *
 *   php tests/site-single-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-23 02:30 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-2 — chạy ở tiến trình riêng: không định nghĩa hàm multisite nào.

define('ABSPATH', __DIR__ . '/');
$GLOBALS['OPT'] = [];
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }
function get_main_site_id() { return 1; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['OPT']) ? $GLOBALS['OPT'][$k] : $d; }
function update_option($k, $v) { $GLOBALS['OPT'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['OPT'][$k]); return true; }
class WP_Error { public $c; function __construct($c = '', $m = '') { $this->c = $c; } function get_error_code() { return $this->c; } }
require dirname(__DIR__) . '/includes/class-tgs-acc-site.php';
function get_site_option($k, $d = false) { return get_option($k, $d); }
function do_action() {}
require dirname(__DIR__) . '/includes/class-tgs-acc-profile.php';

$ok = 0; $fail = 0;
function check($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}\n"; } }

check('không có hàm multisite (đúng như WP một site)', !function_exists('get_blog_option') && !function_exists('switch_to_blog') && !function_exists('get_sites'));
check('is_ms() = false; mô hình tự nhận single', !TGS_Acc_Site::is_ms() && TGS_Acc_Profile::current() === 'single' && TGS_Acc_Profile::source() === 'auto');
check('một site: chỉ chọn được single; chọn chain → lỗi', TGS_Acc_Profile::allowed() === ['single'] && TGS_Acc_Profile::set('chain') instanceof WP_Error && TGS_Acc_Profile::set('single') === true);
check('blogs() = [1]', TGS_Acc_Site::blogs() === [1] && TGS_Acc_Site::blogs(false) === [1]);
check('main() = 1, exists(1), không exists(2)', TGS_Acc_Site::main() === 1 && TGS_Acc_Site::exists(1) && !TGS_Acc_Site::exists(2));

check('set(1) ghi option của site', TGS_Acc_Site::set(1, 'tgs_acc_x', 'v') === true && $GLOBALS['OPT']['tgs_acc_x'] === 'v');
check('opt(1) đọc option của site', TGS_Acc_Site::opt(1, 'tgs_acc_x', 'd') === 'v');
check('opt(0) = blog hiện tại', TGS_Acc_Site::opt(0, 'tgs_acc_x', 'd') === 'v');
check('opt(2) — blog không có → mặc định, không đọc nhầm option của site', TGS_Acc_Site::opt(2, 'tgs_acc_x', 'd') === 'd');
check('set(2) → false, không ghi đè option của site', TGS_Acc_Site::set(2, 'tgs_acc_x', 'khác') === false && $GLOBALS['OPT']['tgs_acc_x'] === 'v');
check('del(2) → false, không xoá', TGS_Acc_Site::del(2, 'tgs_acc_x') === false && isset($GLOBALS['OPT']['tgs_acc_x']));
check('del(1) xoá', TGS_Acc_Site::del(1, 'tgs_acc_x') === true && !isset($GLOBALS['OPT']['tgs_acc_x']));

check('switch_to(1) không chuyển; restore() không lỗi', TGS_Acc_Site::switch_to(1) === false && (TGS_Acc_Site::restore() || true));
check('in_blog(1) chạy hàm', TGS_Acc_Site::in_blog(1, function () { return 42; }) === 42);
$e = TGS_Acc_Site::in_blog(2, function () { return 'không được chạy'; });
check('in_blog(2) → WP_Error, KHÔNG chạy hàm', $e instanceof WP_Error && $e->get_error_code() === 'tgs_acc_no_blog');
check('in_blog: hàm ném lỗi vẫn trả chồng về', (function () { try { TGS_Acc_Site::in_blog(1, function () { throw new RuntimeException('x'); }); } catch (RuntimeException $x) { return TGS_Acc_Site::switch_to(1) === false; } return false; })());
TGS_Acc_Site::restore();
update_option('tgs_shop_name', ''); update_option('blogname', 'Shop một site');
check('name(1) lùi về blogname', TGS_Acc_Site::name(1) === 'Shop một site');

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
