<?php
/**
 * Kiểm TGS_Acc_Source_Pos (nguồn phiếu bán / hoàn đọc thẳng sổ POS) không cần WordPress: giả lập $wpdb trả đúng các dòng
 * sổ của một đơn thật, rồi cho đi qua TGS_Acc_Mapper::sale / sale_return như đường gửi thật.
 *
 *   php tests/source-pos-test.php      mã thoát 0 = đạt hết
 *
 * Bốn nhóm: P1 công thức tiền dòng (khớp TGS_POS_BCTK_Sales_Service::sales của open-pos) · P2 chọn nguồn (tgs-bc-tk /
 * sổ POS / filter) · P3 phiếu bán → chứng từ sale (bill chính + bill Z) · P4 phiếu hoàn → sale_return · P5 chỉ đọc (I-0).
 */
// [2026-11-21 08:11 PM Johnny Chu - Chu Hoàng Anh] S002-4 (= B001-C10) — test nguồn sổ POS (ADR-OKT-1 I-0 chỉ đọc, I-5 một luật tiền).

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('TGS_ACC_BRIDGE_PATH', dirname(__DIR__) . '/');

$GLOBALS['FILTERS'] = [];
function apply_filters($tag, $value, ...$args) { return isset($GLOBALS['FILTERS'][$tag]) ? ($GLOBALS['FILTERS'][$tag])($value, ...$args) : $value; }
function get_site_option($k, $d = false) { return $d; }
$GLOBALS["BLOGOPT"] = [];
function get_blog_option($b, $k, $d = false) { return $GLOBALS["BLOGOPT"][(int) $b][$k] ?? $d; }
function get_option($k, $d = false) { return $d; }
function get_userdata($id) { return (object) ['user_login' => 'thungan' . (int) $id, 'display_name' => 'Thu ngân ' . (int) $id]; }

/** $wpdb giả: trả dòng theo nội dung câu SQL, ghi lại mọi câu để kiểm "chỉ đọc". */
class Fake_Wpdb
{
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $users = 'wp_users';
    public $queries = [];
    public $db = [];
    public $writes = [];

    public function insert(...$a) { $this->writes[] = ["insert", $a]; return 1; }
    public function update(...$a) { $this->writes[] = ["update", $a]; return 1; }
    public function delete(...$a) { $this->writes[] = ["delete", $a]; return 1; }
    public function replace(...$a) { $this->writes[] = ["replace", $a]; return 1; }
    public function query($sql) { $this->writes[] = ["query", $sql]; return 1; }

    public function get_blog_prefix($blog) { return 'wp_' . (int) $blog . '_'; }
    public function suppress_errors($on = true) { return false; }
    public function prepare($sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $sql);
    }
    public function get_var($sql)
    {
        $this->queries[] = $sql;
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $sql, $m)) {
            return in_array($m[1], $this->db['tables'] ?? [], true) ? $m[1] : null;
        }
        if (stripos($sql, 'SHOW COLUMNS') !== false) {
            return 'local_ledger_source_id';
        }
        return null;
    }
    public function get_col($sql)
    {
        $this->queries[] = $sql;
        // only_approved: phiếu đã duyệt trong danh sách id.
        preg_match('/IN \(([0-9,]+)\)/', $sql, $m);
        $ids = array_map('intval', explode(',', $m[1] ?? ''));
        return array_values(array_intersect($ids, $this->db['approved'] ?? []));
    }
    public function get_results($sql, $out = null)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'export_reason.code') !== false) {
            return $this->db['reasons'] ?? [];
        }
        if (strpos($sql, 'd.local_ledger_type = 10') !== false) {
            return $this->db['sales'] ?? [];
        }
        if (strpos($sql, 'd.local_ledger_type = 11') !== false) {
            return $this->db['returns'] ?? [];
        }
        if (strpos($sql, 'ch.local_ledger_parent_id IN') !== false) {
            return $this->db['sale_items'] ?? [];
        }
        if (strpos($sql, 'i.local_ledger_id IN') !== false) {
            return $this->db['return_items'] ?? [];
        }
        return [];
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

// [2026-11-23 02:20 AM] OKT-TOPO-2 — lớp bao kiểu cài thật; test này giả multisite (get_blog_option giả).
if (!function_exists('is_multisite')) { function is_multisite() { return true; } }
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-site.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-source.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-source-pos.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-mapper.php';

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

/** Một dòng local_ledger_item như POS cũ ghi (cột theo TGS_Acc_Source_Pos::items). */
function item($id, $sku, $qty, $price, $pct, $tax, $ratio = 1, $unit = 'Cái', $discount = 0, $extra = [])
{
    return $extra + [
        'doc_id' => 0, 'item_id' => $id, 'sku' => $sku, 'name' => 'Hàng ' . $sku, 'base_unit' => 'Cái',
        'quantity' => $qty, 'price' => $price, 'unit_qty' => $qty / $ratio, 'unit_ratio' => $ratio, 'unit' => $unit,
        'discount' => $discount, 'tax_percent' => $pct, 'is_kct' => 0, 'tax_amount' => $tax, 'gift_type' => '',
        'zone' => '', 'lot_code' => '', 'exp_date' => null, 'note' => '',
    ];
}

// ── P1 công thức tiền ───────────────────────────────────────────────────────
echo "P1 công thức tiền dòng (TGS_POS_BCTK_Sales_Service::sales)\n";
$m = TGS_Acc_Source_Pos::line_money(1, 10000, 0, 10, 1000, 1);
eq('11.000đ gồm VAT 10 %: chưa thuế 10.000, thuế 1.000, thành tiền 11.000', [$m['before'], $m['tax'], $m['total']], [10000.0, 1000.0, 11000.0]);
// Giá kệ 25.000đ gồm VAT 8 %, POS lưu đơn giá trước thuế 23.148,15 (JS bóc VAT, làm tròn 2 số lẻ), bán 3 cái.
$m = TGS_Acc_Source_Pos::line_money(3, 23148.15, 0, 8, 5555.556, 1);
eq('3 × 25.000đ (8 %): thành tiền 75.000 = đúng tiền khách trả', $m['total'], 75000.0);
eq('3 × 25.000đ (8 %): chưa thuế 69.444 + thuế 5.556', [$m['before'], $m['tax']], [69444.0, 5556.0]);
// Bán theo ĐVT Lốc_6 (tỉ lệ 6): 2 lốc = 12 lon, lon 10.000đ gồm 8 % (trước thuế 9.259,26), chiết khấu 1.000đ trước thuế.
$m = TGS_Acc_Source_Pos::line_money(12, 9259.26, 1000, 8, 8808.8896, 6);
eq('2 lốc (12 lon) có chiết khấu: thành tiền 118.920', $m['total'], 118920.0);
eq('2 lốc: chưa thuế 110.111 + thuế 8.809 = thành tiền', [$m['before'], $m['tax'], $m['before'] + $m['tax']], [110111.0, 8809.0, 118920.0]);
$m = TGS_Acc_Source_Pos::line_money(2, 5000, 0, 0, 0, 1);
eq('KCT / 0 %: thuế 0, chưa thuế = thành tiền 10.000', [$m['before'], $m['tax'], $m['total']], [10000.0, 0.0, 10000.0]);

// ── P2 chọn nguồn (mặc định tắt, bật từng blog — I-6) ───────────────────────
echo "P2 chọn nguồn
";
eq('chưa bật, không có tgs-bc-tk: không có nguồn (như cũ)', TGS_Acc_Source::sale_source(2), '');
eq('chưa bật: ready(2) sai — bridge không tự gửi đơn bán', TGS_Acc_Source::ready(2), false);
$GLOBALS['BLOGOPT'][2]['tgs_acc_sale_source'] = 'pos';
eq('blog 2 bật option tgs_acc_sale_source = pos: nguồn = pos', TGS_Acc_Source::sale_source(2), 'pos');
eq('blog 2 bật: ready(2) đúng', TGS_Acc_Source::ready(2), true);
eq('blog 3 chưa bật: vẫn không có nguồn', TGS_Acc_Source::sale_source(3), '');
$GLOBALS['FILTERS']['tgs_acc_sale_source'] = function () { return 'bctk'; };
eq('filter chọn bctk mà plugin không có: không có nguồn', TGS_Acc_Source::sale_source(2), '');
unset($GLOBALS['FILTERS']['tgs_acc_sale_source']);
$GLOBALS['wpdb']->db['tables'] = ['wp_2_local_ledger', 'wp_2_local_ledger_item'];
eq('blog 2 có bảng sổ: Source_Pos::ready(2)', TGS_Acc_Source_Pos::ready(2), true);
eq('blog 9 không có bảng sổ: Source_Pos::ready(9) sai', TGS_Acc_Source_Pos::ready(9), false);

// ── P3 phiếu bán ────────────────────────────────────────────────────────────
echo "P3 phiếu bán → chứng từ sale\n";
$db = &$GLOBALS['wpdb']->db;
$db['approved'] = [101, 102, 201];
$db['sales'] = [
    ['sale_id' => 101, 'parent_id' => null, 'parent_type' => null, 'so_phieu_xuat' => '02011AA00012', 'ngay_xuat' => '2026-11-23', 'ghi_chu' => '', 'user_id' => 7, 'ma_kh' => '0901234567', 'ten_kh' => 'Chị Lan', 'nv_ten' => 'Thu ngân 7'],
    ['sale_id' => 102, 'parent_id' => 101, 'parent_type' => 10, 'so_phieu_xuat' => '02011Z0012Z', 'ngay_xuat' => '2026-11-23', 'ghi_chu' => 'Tách từ 02011AA00012', 'user_id' => 7, 'ma_kh' => '', 'ten_kh' => '', 'nv_ten' => ''],
    ['sale_id' => 103, 'parent_id' => null, 'parent_type' => null, 'so_phieu_xuat' => '02011AA00013', 'ngay_xuat' => '2026-11-23', 'ghi_chu' => '', 'user_id' => 7, 'ma_kh' => '', 'ten_kh' => '', 'nv_ten' => ''],
];
$db['sale_items'] = [
    item(5001, '101010001', 3, 23148.15, 8, 5555.556, 1, 'Cái', 0, ['doc_id' => 101]),
    item(5002, '101010002', 12, 9259.26, 8, 8808.8896, 6, 'Lốc_6', 1000, ['doc_id' => 101, 'base_unit' => 'Lon', 'zone' => 'KHO1']),
    item(5003, '101010009Z', 1, 0, 0, 0, 1, 'Cái', 0, ['doc_id' => 102, 'gift_type' => 'gift']),
];
$sales = TGS_Acc_Source::sale_rows(2, '2026-11-23', '2026-11-23');
eq('chỉ phiếu đã duyệt (101, 102; 103 chưa duyệt bị bỏ)', array_column($sales, 'sale_id'), [101, 102]);
$main = $sales[0];
$doc = TGS_Acc_Mapper::sale($main, 2);
eq('docKey theo blog + id phiếu', $doc['docKey'], 'btsoft:2:sale:101');
eq('docNo = mã phiếu POS', $doc['docNo'], '02011AA00012');
eq('docDate', $doc['docDate'], '2026-11-23');
eq('bill chính không internalOnly', $doc['internalOnly'], false);
eq('hai dòng', count($doc['lines']), 2);
eq('dòng 1: qty 3, chưa thuế 69.444, thuế 5.556, R08', [$doc['lines'][0]['qty'], $doc['lines'][0]['amountBeforeTax'], $doc['lines'][0]['taxAmount'], $doc['lines'][0]['taxCode']], [3.0, 69444.0, 5556.0, 'R08']);
eq('dòng 2: qty theo ĐVT nhỏ nhất 12, unitQty 2, unitRatio 6', [$doc['lines'][1]['qty'], $doc['lines'][1]['unitQty'], $doc['lines'][1]['unitRatio']], [12.0, 2.0, 6.0]);
eq('dòng 2: kho KHO1, chiết khấu 1.000', [$doc['lines'][1]['warehouseCode'], $doc['lines'][1]['discount']], ['KHO1', 1000.0]);
eq('tổng = cộng dòng = 75.000 + 118.920', $doc['totals'], ['beforeTax' => 179555.0, 'tax' => 14365.0, 'total' => 193920.0]);
eq('đối tượng = SĐT khách', $doc['partner'] ?? null, ['code' => '0901234567', 'name' => 'Chị Lan']);
eq('nhân viên = tên đăng nhập', $doc['employee'] ?? null, ['code' => 'thungan7', 'name' => 'Thu ngân 7']);
eq('không có khối hoá đơn (nguồn sổ POS)', isset($doc['invoice']), false);
$z = TGS_Acc_Mapper::sale($sales[1], 2);
eq('bill Z (phiếu 10 con, mã đuôi Z): internalOnly', $z['internalOnly'], true);
eq('bill Z: dòng quà isGift', $z['lines'][0]['isGift'], true);

// ── P4 phiếu hoàn ───────────────────────────────────────────────────────────
echo "P4 phiếu hoàn → chứng từ sale_return\n";
$db['returns'] = [
    ['sale_id' => 201, 'parent_id' => 101, 'parent_type' => 10, 'so_phieu_xuat' => '02011.A00005', 'ngay_xuat' => '2026-11-24', 'ghi_chu' => '', 'user_id' => 7, 'ma_kh' => '0901234567', 'ten_kh' => 'Chị Lan', 'nv_ten' => 'Thu ngân 7'],
];
$db['return_items'] = [item(6001, '101010001', 1, 23148.15, 8, 1851.852, 1, 'Cái', 0, ['doc_id' => 201])];
$rets = TGS_Acc_Source::return_rows(2, '2026-11-24', '2026-11-24');
eq('một phiếu hoàn đã duyệt', count($rets), 1);
$ret = TGS_Acc_Mapper::sale_return($rets[0], 2);
eq('docKey theo id phiếu hoàn', $ret['docKey'], 'btsoft:2:return:201');
eq('trỏ phiếu bán gốc', $ret['originalDocKey'] ?? null, 'btsoft:2:sale:101');
eq('hoàn 1 cái 25.000đ: chưa thuế 23.148 + thuế 1.852', [$ret['lines'][0]['amountBeforeTax'], $ret['lines'][0]['taxAmount']], [23148.0, 1852.0]);

// ── P5 chỉ đọc ──────────────────────────────────────────────────────────────
echo "P5 chỉ đọc sổ POS (I-0)\n";
$writes = array_filter($GLOBALS['wpdb']->queries, function ($q) { return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\b/i', $q); });
eq('không có câu ghi nào', count($writes), 0);
eq("không gọi insert / update / delete / replace / query", count($GLOBALS["wpdb"]->writes), 0);

// ── P6 chưa bật thì không đọc sổ ─────────────────────────────────────────────
echo "P6 blog chưa bật nguồn sổ POS
";
$before = count($GLOBALS['wpdb']->queries);
eq('blog 3: sale_rows rỗng', TGS_Acc_Source::sale_rows(3, '2026-11-23', '2026-11-23'), []);
eq('blog 3: return_rows rỗng', TGS_Acc_Source::return_rows(3, '2026-11-23', '2026-11-23'), []);
eq('blog 3: không có truy vấn nào chạm sổ', count($GLOBALS['wpdb']->queries) - $before, 0);

echo $fails === 0 ? "\nKẾT QUẢ: ĐẠT\n" : "\nKẾT QUẢ: KHÔNG ĐẠT ($fails phép kiểm)\n";
exit($fails === 0 ? 0 : 1);
