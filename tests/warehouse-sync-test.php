<?php
/**
 * Kiểm OKT-H2 (phân kho chép từ kế toán THEO KẾT NỐI CỦA TỪNG BLOG, I-1) không cần WordPress: $wpdb giả, client giả theo blog.
 *
 *   php tests/warehouse-sync-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 07:30 PM Johnny Chu - Chu Hoàng Anh] OKT-H2 — trước đây sync() tải kho MỘT lần bằng khoá của ngữ cảnh đang chạy rồi chép cho mọi blog.

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['NET'] = [];
$GLOBALS['BLOGOPT'] = [];
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOGOPT'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOGOPT'][(int) $b][$k] = $v; return true; }
function add_action() {}
function current_time() { return '2026-11-22 19:30:00'; }
function is_multisite() { return true; }
function get_sites() { return [1, 2, 3, 4]; }
function get_current_blog_id() { return 2; }
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }

// Blog 1 = site chính (tenant MAIN), 2 = shop-a (tenant shopa), 3 = shop-b chưa có kết nối, 4 = shop-c kế toán không tới được.
$GLOBALS['CONN'] = [1 => 'http://main', 2 => 'http://shopa', 4 => 'http://down'];
$GLOBALS['BRANCH'] = [1 => 'HQ', 2 => 'CN01', 3 => 'CN02', 4 => 'CN03'];
$GLOBALS['TENANT_WH'] = [
    'http://main'  => [['code' => 'KHQ', 'branchCode' => 'HQ', 'name' => 'Kho tổng', 'isActive' => true, 'isDefault' => true],
                       ['code' => 'KMAIN1', 'branchCode' => 'CN01', 'name' => 'Kho của tenant site chính', 'isActive' => true, 'isDefault' => true]],
    'http://shopa' => [['code' => 'KCN01', 'branchCode' => 'CN01', 'name' => 'Kho cửa hàng', 'isActive' => true, 'isDefault' => true],
                       ['code' => 'KOLD', 'branchCode' => 'CN01', 'name' => 'Kho ngừng', 'isActive' => false, 'isDefault' => false]],
];
$GLOBALS['GETS'] = [];
class TGS_Acc_Config
{
    static function ready_for($b) { return isset($GLOBALS['CONN'][(int) $b]); }
    static function url() { return 'http://main'; }
    static function key() { return 'k'; }
}
class TGS_Acc_Source { static function shop_code($b) { return $GLOBALS['BRANCH'][(int) $b] ?? ''; } }
class TGS_Acc_Events { static $trips = []; static function trip($b, $m) { self::$trips[] = (int) $b; } }
class TGS_Acc_Api_Client
{
    public $base;
    function __construct($base = 'http://main') { $this->base = $base; }
    static function for_blog($b) { return new self($GLOBALS['CONN'][(int) $b] ?? ''); }
    function identity() { return substr(md5($this->base), 0, 12); }
    function ready() { return $this->base !== ''; }
    function get($path)
    {
        $GLOBALS['GETS'][] = $this->base . $path;
        if ($this->base === 'http://down') {
            return new WP_Error('http_request_failed', 'timeout');
        }
        return ['data' => $GLOBALS['TENANT_WH'][$this->base] ?? []];
    }
}
class FakeDb
{
    public $t = [];   // bảng => [id => hàng]
    function get_blog_prefix($b) { return 'wp_' . (int) $b . '_'; }
    function prepare($q, $a) { return str_replace('%s', "'{$a}'", $q); }
    function get_var($q) { preg_match("/'([^']+)'/", $q, $m); return isset($this->t[$m[1]]) ? $m[1] : null; }
    function get_results($q) { preg_match('/FROM (\S+)/', $q, $m); return array_values($this->t[$m[1]] ?? []); }
    function insert($T, $d) { $id = count($this->t[$T]) + 1; $this->t[$T][$id] = ['id' => $id] + $d; return 1; }
    function update($T, $d, $w) { $this->t[$T][$w['id']] = array_merge($this->t[$T][$w['id']], $d); return 1; }
    function codes($b) { $o = []; foreach ($this->t['wp_' . $b . '_local_warehouse_zone'] as $r) { if (!(int) $r['is_deleted']) { $o[] = $r['zone_code']; } } sort($o); return implode(',', $o); }
}
$wpdb = new FakeDb();
foreach ([1, 2, 3, 4] as $b) {
    $wpdb->t['wp_' . $b . '_local_warehouse_zone'] = [];
}
// shop-c (kế toán hỏng) đang có một phân kho cũ — tải hỏng không được ẩn nó
$wpdb->t['wp_4_local_warehouse_zone'][1] = ['id' => 1, 'zone_code' => 'KCN03', 'zone_name' => 'Kho C', 'sort_order' => 100, 'is_deleted' => 0];

require dirname(__DIR__) . '/includes/class-tgs-acc-warehouse-sync.php';

$ok = 0; $fail = 0;
function check($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}\n"; } }

// 1. Cron (không tham số): mỗi blog chép kho của CHÍNH tenant nó
$sum = TGS_Acc_Warehouse_Sync::sync();
check('site chính nhận kho tenant site chính', $wpdb->codes(1) === 'KHQ');
check('shop-a nhận KCN01 của tenant shopa, KHÔNG nhận KMAIN1 của tenant site chính', $wpdb->codes(2) === 'KCN01');
check('shop-a bỏ kho ngừng dùng', strpos($wpdb->codes(2), 'KOLD') === false);
check('shop-b chưa kết nối: không chép, không gọi', $wpdb->codes(3) === '' && !in_array('/api/v1/warehouses', $GLOBALS['GETS'], true));
check('shop-c kế toán hỏng: giữ phân kho cũ, không ẩn', $wpdb->codes(4) === 'KCN03');
check('tổng kết: 2 site đã chép, 1 lỗi', $sum['sites'] === 2 && $sum['failed'] === 1 && $sum['added'] === 2 && $sum['hidden'] === 0);
check('mỗi blog gọi đúng URL của nó', in_array('http://shopa/api/v1/warehouses', $GLOBALS['GETS'], true) && in_array('http://main/api/v1/warehouses', $GLOBALS['GETS'], true));
check('kế toán hỏng → mở cầu dao của đúng blog đó', TGS_Acc_Events::$trips === [4]);
check('đệm theo kết nối: shop-a có khoá đệm riêng', isset($GLOBALS['NET'][TGS_Acc_Warehouse_Sync::MAP . '_' . substr(md5('http://shopa'), 0, 12)]));

// 2. Một blog (nút Tải lại / maybe_sync): lỗi trả về cho người bấm
$r = TGS_Acc_Warehouse_Sync::sync([4]);
check('một blog hỏng → WP_Error', is_wp_error($r));
$r = TGS_Acc_Warehouse_Sync::sync([2]);
check('một blog chạy lại: không đổi gì', !is_wp_error($r) && $r['added'] === 0 && $r['updated'] === 0 && $r['hidden'] === 0);

// 3. Tenant shopa bỏ KCN01, thêm KCN01B → ẩn cũ, thêm mới
$GLOBALS['TENANT_WH']['http://shopa'] = [['code' => 'KCN01B', 'branchCode' => 'CN01', 'name' => 'Kho mới', 'isActive' => true, 'isDefault' => true]];
$r = TGS_Acc_Warehouse_Sync::sync([2]);
check('đổi kho bên tenant → ẩn KCN01, thêm KCN01B', $wpdb->codes(2) === 'KCN01B' && $r['hidden'] === 1 && $r['added'] === 1);

// 4. maybe_sync theo kết nối của blog hiện tại (blog 2), không theo cấu hình site chính
$GLOBALS['BLOGOPT'][2] = [];
$GLOBALS['GETS'] = [];
TGS_Acc_Warehouse_Sync::maybe_sync();
check('maybe_sync gọi kết nối của shop-a', $GLOBALS['GETS'] === ['http://shopa/api/v1/warehouses']);

// 5. ready() (tsm: ô phân kho chỉ xem khi kế toán quản lý) theo kết nối của blog, không theo site chính
check('ready: blog 2 có kết nối riêng', TGS_Acc_Warehouse_Sync::ready(2) === true);
check('ready: blog 3 chưa kết nối → admin vẫn tự khai phân kho', TGS_Acc_Warehouse_Sync::ready(3) === false);
check('ready(): không tham số = blog hiện tại (2)', TGS_Acc_Warehouse_Sync::ready() === true);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
