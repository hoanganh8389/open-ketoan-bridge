<?php
/**
 * Kiểm S002-5 (mã chi nhánh khai theo blog, không tự tạo chi nhánh trống) và S002-6 (gửi ĐVT nhỏ nhất ở unit khi qty
 * theo ĐVT nhỏ nhất) không cần WordPress.
 *
 *   php tests/branch-unit-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-21 08:30 PM Johnny Chu - Chu Hoàng Anh] S002-5, S002-6 — test chi nhánh theo blog + ĐVT nhỏ nhất.

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('TGS_ACC_BRIDGE_PATH', dirname(__DIR__) . '/');

$GLOBALS['FILTERS'] = [];
$GLOBALS['BLOGOPT'] = [];
$GLOBALS['NET'] = [];
function apply_filters($tag, $value, ...$args) { return isset($GLOBALS['FILTERS'][$tag]) ? ($GLOBALS['FILTERS'][$tag])($value, ...$args) : $value; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOGOPT'][(int) $b][$k] ?? $d; }
function get_option($k, $d = false) { return $d; }
function get_userdata($id) { return null; }
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }

class Fake_Wpdb
{
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public function get_blog_prefix($b) { return 'wp_' . (int) $b . '_'; }
    public function prepare($sql, ...$a) { return $sql; }
    public function suppress_errors($on = true) { return false; }
    public function get_var($sql) { return null; }
    public function get_col($sql) { return []; }
    public function get_results($sql, $o = null) { return []; }
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

/** Client giả: danh mục chi nhánh của tenant + đếm lần tạo chi nhánh. */
class Fake_Client
{
    public $branches = [['code' => 'CN01', 'name' => 'Chi nhánh chính']];
    public $gets = 0;
    public $created = [];
    public function get($path) { $this->gets++; return ['data' => $this->branches, 'meta' => []]; }
    public function ensure_branch($code, $name, $ref = '') { $this->created[] = $code; return true; }
}

// [2026-11-23 02:20 AM] OKT-TOPO-2 — lớp bao kiểu cài thật; test này giả multisite (get_blog_option giả).
if (!function_exists('is_multisite')) { function is_multisite() { return true; } }
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-site.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-source.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-mapper.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-push-log.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-pusher.php';

$fails = 0;
function eq($label, $got, $want)
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  ✔ ' : '  ✘ ') . $label . ($ok ? '' : '  — được ' . var_export($got, true) . ', cần ' . var_export($want, true)) . "\n";
}

// ── B1 mã chi nhánh theo blog (S002-5) ──────────────────────────────────────
echo "B1 mã chi nhánh khai theo blog\n";
eq('chưa khai: dò như cũ (máy thử không có phân cấp) → rỗng', TGS_Acc_Source::branch_of_blog(2), '');
$GLOBALS['BLOGOPT'][2]['tgs_acc_branch_code'] = ' CN01 ';
eq('blog 2 khai CN01: branch_of_blog = CN01 (bỏ khoảng trắng)', TGS_Acc_Source::branch_of_blog(2), 'CN01');
eq('blog 2: shop_code cũng = CN01 (chiều nhận sự kiện khớp chiều gửi)', TGS_Acc_Source::shop_code(2), 'CN01');
eq('blog 3 chưa khai: vẫn rỗng', TGS_Acc_Source::branch_of_blog(3), '');
$GLOBALS['BLOGOPT'][4]['tgs_acc_branch_code'] = str_repeat('X', 33);
eq('mã dài quá 32 ký tự (khuôn branchCode): bỏ, coi như chưa khai', TGS_Acc_Source::branch_override(4), '');
$GLOBALS['FILTERS']['tgs_acc_branch_code'] = function ($code, $blog) { return $blog === 5 ? 'CN05' : $code; };
eq('filter tgs_acc_branch_code đặt được theo blog', TGS_Acc_Source::branch_of_blog(5), 'CN05');
unset($GLOBALS['FILTERS']['tgs_acc_branch_code']);

// ── B2 chứng từ dùng mã khai + ĐVT nhỏ nhất (S002-5, S002-6) ────────────────
echo "B2 chứng từ: branchCode + unit\n";
$pos_row = [
    'sale_id' => 101, 'so_phieu_xuat' => '02011AA00012', 'ngay_xuat' => '2026-11-23', 'ma_shop' => 'CN01', 'ghi_chu' => '',
    'is_z' => false, 'vat_state' => '', 'user_id' => 0, 'ma_kh' => '', 'ten_kh' => '',
    'items' => [[
        'item_id' => 5002, 'ma_hang' => '101010002', 'ten' => 'Bia lon', 'dvt' => 'Lốc_6', 'dvt_goc' => 'Lon',
        'sl_dvcb' => 12.0, 'sl' => 2.0, 'ratio' => 6.0, 'don_gia_gui_thue' => 9175.93, 'tien_chua_thue' => 110111.0,
        'ck' => 1000.0, 'thue_suat' => 8.0, 'tien_thue' => 8809.0, 'kho' => '', 'is_gift' => false, 'so_lo' => '', 'exp' => '', 'ghi_chu' => '',
    ]],
];
$doc = TGS_Acc_Mapper::sale($pos_row, 2);
eq('sale: branchCode = mã khai của blog', $doc['branchCode'], 'CN01');
eq('nguồn sổ POS: unit = ĐVT nhỏ nhất (Lon), qty = 12 lon', [$doc['lines'][0]['unit'], $doc['lines'][0]['qty']], ['Lon', 12.0]);
eq('ĐVT bán vẫn đi kèm ở unitQty / unitRatio (2 lốc × 6)', [$doc['lines'][0]['unitQty'], $doc['lines'][0]['unitRatio']], [2.0, 6.0]);
$bctk_row = $pos_row;
unset($bctk_row['items'][0]['dvt_goc']);
$doc2 = TGS_Acc_Mapper::sale($bctk_row, 2);
eq('dòng tgs-bc-tk (không có dvt_goc): unit giữ như cũ', $doc2['lines'][0]['unit'], 'Lốc_6');

// ── B3 bảo đảm chi nhánh trước khi gửi (S002-5) ─────────────────────────────
echo "B3 kiểm chi nhánh ở tenant\n";
$c = new Fake_Client();
eq('mã khai CN01 có ở tenant: được', TGS_Acc_Pusher::check_branch($c, 2, 'CN01'), '');
eq('mã khai: KHÔNG tạo chi nhánh', $c->created, []);
eq('đã kiểm thì nhớ theo blog: lần sau không hỏi lại', [TGS_Acc_Pusher::check_branch($c, 2, 'CN01'), $c->gets], ['', 1]);
$GLOBALS['BLOGOPT'][6]['tgs_acc_branch_code'] = 'CN09';
$err = TGS_Acc_Pusher::check_branch($c, 6, 'CN09');
eq('mã khai CN09 không có ở tenant: báo lỗi rõ', strpos($err, 'Chi nhánh CN09') === 0 && strpos($err, 'chưa có ở tenant') !== false, true);
eq('mã khai sai: vẫn không tạo chi nhánh trống', $c->created, []);
eq('blog chưa khai, mã dò kiểu cũ: giữ cách cũ (ensure_branch tạo)', [TGS_Acc_Pusher::check_branch($c, 7, 'SHOP7'), $c->created], ['', ['SHOP7']]);
eq('mã rỗng: báo lỗi chỉ cách khai', strpos(TGS_Acc_Pusher::check_branch($c, 8, ''), 'tgs_acc_branch_code') !== false, true);

echo $fails === 0 ? "\nKẾT QUẢ: ĐẠT\n" : "\nKẾT QUẢ: KHÔNG ĐẠT ($fails phép kiểm)\n";
exit($fails === 0 ? 0 : 1);
