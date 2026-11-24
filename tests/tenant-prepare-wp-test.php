<?php
/**
 * SW4b-1 — TGS_Acc_Tenant_Prepare (dựng sẵn tenant) với client giả: thứ tự cha trước con, chỉ tạo thứ còn thiếu, không ghi đè bảng giá,
 * không cướp cờ bảng giá công ty, gán chi nhánh bằng hợp danh sách, nhóm cha lỗi thì bỏ con, chạy thử không ghi gì.
 * Chạy TRONG WordPress thử (cần tsm có TGS_Catalog_Sku::ensure_codes); dựng cây nhóm tạm rồi xoá.
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/tenant-prepare-wp-test.php --url=shop-b.okt.local
 */
// [2026-11-24 08:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-1 — test.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ máy dev.\n"); exit(1); }
global $wpdb;
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
$B = (int) get_current_blog_id();
$C = tgs_catalog_table('cat', $B);
$sep = chr(92);
$tag = 'TP' . mt_rand(1000, 9999);
$made = [];
$src_before = TGS_Acc_Site::opt($B, 'tgs_catalog_source', null);
$log_before = TGS_Acc_Site::opt($B, 'tgs_catalog_source_log', null);
register_shutdown_function(function () use (&$made, $C, $B, $src_before, $log_before) {
    global $wpdb;
    foreach ($made as $id) { $wpdb->delete($C, ['global_product_cat_id' => $id]); }
    if (class_exists('TGS_Category_Path')) { TGS_Category_Path::flush_db_dictionary(); }
    foreach (['tgs_catalog_source' => $src_before, 'tgs_catalog_source_log' => $log_before] as $k => $v) {
        if (is_multisite()) { $v === null ? delete_blog_option($B, $k) : update_blog_option($B, $k, $v); } else { $v === null ? delete_option($k) : update_option($k, $v); }
    }
});
$cat = function ($path, $name) use ($wpdb, $C, &$made) {
    $wpdb->insert($C, ['global_product_cat_code' => $name, 'global_product_cat_name' => $name, 'global_product_cat_path' => $path, 'global_product_cat_status' => 1,
        'is_deleted' => 0, 'user_id' => 1, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
    $made[] = (int) $wpdb->insert_id;
    return (int) $wpdb->insert_id;
};
$g1 = $cat($tag, "{$tag} ngành"); $g2 = $cat("{$tag}{$sep}N", "{$tag} nhãn"); $g3 = $cat("{$tag}{$sep}N{$sep}M", "{$tag} nhóm");
TGS_Category_Path::flush_db_dictionary();
// get_col / get_var trả null cho chuỗi rỗng → ép về chuỗi
$codes = function () use ($wpdb, $C, $g1, $g2, $g3) { return array_map('strval', (array) $wpdb->get_col("SELECT global_product_cat_sku_code FROM `{$C}` WHERE global_product_cat_id IN ({$g1},{$g2},{$g3}) ORDER BY global_product_cat_id")); };

/** Client giả: GET trả dữ liệu cấu hình, ghi lại mọi lệnh ghi; $fail_put[path] = WP_Error. */
class TGS_Tp_Fake_Client
{
    public $remote = ['/api/v1/item-classes/group' => [], '/api/v1/catalog/price-lists' => [], '/api/v1/branches' => []];
    public $writes = [];
    public $fail = [];
    public function get($path, array $q = []) { return ['data' => $this->remote[$path] ?? [], 'meta' => []]; }
    public function put_json($path, array $body) { $this->writes[] = ['PUT', $path, $body]; return $this->fail[$path] ?? ['ok' => 1]; }
    public function post_json($path, array $body) { $this->writes[] = ['POST', $path, $body]; return $this->fail[$path] ?? ['ok' => 1]; }
}
$mine = function ($writes) use ($g1, $g2, $g3) {
    return array_values(array_filter($writes, function ($w) use ($g1, $g2, $g3) { return preg_match('#/group/(' . $g1 . '|' . $g2 . '|' . $g3 . ')\?#', $w[1]); }));
};
if (is_multisite()) { update_blog_option($B, 'tgs_catalog_source', 'local'); } else { update_option('tgs_catalog_source', 'local'); }

echo "== chạy thử\n";
$f = new TGS_Tp_Fake_Client();
$r = TGS_Acc_Tenant_Prepare::run($B, true, ['client' => $f]);
$plan = implode("\n", $r['plan']);
$check('chạy thử: 0 lệnh ghi lên kế toán', !$f->writes);
$check('chạy thử: không ghi mã SKU nhóm vào tsm', $codes() === ['', '', '']);
$p1 = strpos($plan, "nhóm {$g1} "); $p2 = strpos($plan, "nhóm {$g2} "); $p3 = strpos($plan, "nhóm {$g3} ");
$check('kế hoạch: tạo 3 nhóm, cha trước con, cấp 1 / 2 / 3', $p1 !== false && $p2 > $p1 && $p3 > $p2 && strpos($plan, '(cấp 3, mã SKU') !== false);

echo "== chạy thật, tenant trống\n";
$f = new TGS_Tp_Fake_Client();
$r = TGS_Acc_Tenant_Prepare::run($B, false, ['client' => $f]);
$w = $mine($f->writes);
$check('3 PUT nhóm ?new=1 theo thứ tự cha trước con', count($w) === 3 && strpos($w[0][1], "/group/{$g1}?new=1") !== false && strpos($w[2][1], "/group/{$g3}?new=1") !== false);
$check('thân PUT: cấp 1 không cha; cấp 2 cha = cấp 1; mỗi cấp có skuCode đúng độ dài 2 / 3 / 1', !isset($w[0][2]['parentCode']) && ($w[1][2]['parentCode'] ?? '') === (string) $g1
    && strlen($w[0][2]['skuCode'] ?? '') === 2 && strlen($w[1][2]['skuCode'] ?? '') === 3 && strlen($w[2][2]['skuCode'] ?? '') === 1, wp_json_encode($w, JSON_UNESCAPED_UNICODE));
$check('chạy thật (shop tự quản): mã SKU nhóm ghi lại vào tsm', $codes() === [$w[0][2]['skuCode'], $w[1][2]['skuCode'], $w[2][2]['skuCode']]);
$pl = array_values(array_filter($f->writes, function ($x) { return preg_match('#^/api/v1/catalog/price-lists/[^/]+$#', $x[1]); }));
$check('tạo bảng giá công ty (isCompany = true) khi tenant chưa có', count($pl) >= 1 && $pl[0][2]['isCompany'] === true);
$asg = array_values(array_filter($f->writes, function ($x) { return substr($x[1], -9) === '/branches' && $x[0] === 'PUT'; }));
$bc = TGS_Acc_Source::branch_of_blog($B);
$check('tạo chi nhánh của blog (' . $bc . ') rồi áp bảng giá', in_array(['POST', '/api/v1/branches'], array_map(function ($x) { return [$x[0], $x[1]]; }, $f->writes), true) && count($asg) === 1 && $asg[0][2]['branchCodes'] === [$bc]);

echo "== tenant đã có thứ của mình\n";
$listcode = (string) $pl[0][1]; $listcode = substr($listcode, strrpos($listcode, '/') + 1);
$f = new TGS_Tp_Fake_Client();
$f->remote['/api/v1/item-classes/group'] = [['code' => (string) $g1, 'parentCode' => null, 'skuCode' => $w[0][2]['skuCode']], ['code' => (string) $g2, 'parentCode' => (string) $g1, 'skuCode' => '999']];
$f->remote['/api/v1/catalog/price-lists'] = [['code' => 'CTY', 'isCompany' => true, 'branchCodes' => ['CN99']], ['code' => rawurldecode($listcode), 'isCompany' => false, 'branchCodes' => ['CN02']]];
$f->remote['/api/v1/branches'] = [['code' => $bc], ['code' => 'CN02']];
$r = TGS_Acc_Tenant_Prepare::run($B, false, ['client' => $f]);
$w = $mine($f->writes);
$check('nhóm đã có, khớp: bỏ qua; nhóm lệch mã SKU: báo, không ghi đè', count($w) === 1 && strpos($w[0][1], "/group/{$g3}?new=1") !== false && strpos(implode("\n", $r['notes']), 'lệch') !== false);
$check('bảng giá đã có: KHÔNG PUT lại (không ghi đè tên / cờ)', !array_filter($f->writes, function ($x) use ($listcode) { return $x[1] === '/api/v1/catalog/price-lists/' . $listcode; }));
$asg = array_values(array_filter($f->writes, function ($x) { return substr($x[1], -9) === '/branches' && $x[0] === 'PUT'; }));
$check('gán chi nhánh = HỢP danh sách đang gán (CN02) + chi nhánh blog', count($asg) === 1 && $asg[0][2]['branchCodes'] === ['CN02', $bc], wp_json_encode($asg));
$check('chi nhánh đã có: không POST', !array_filter($f->writes, function ($x) { return $x[0] === 'POST'; }));

echo "== không cướp bảng giá công ty, chi nhánh đang áp bảng khác\n";
$f = new TGS_Tp_Fake_Client();
$f->remote['/api/v1/catalog/price-lists'] = [['code' => 'CTY', 'isCompany' => true, 'branchCodes' => [$bc]]];
$f->remote['/api/v1/branches'] = [['code' => $bc]];
$r = TGS_Acc_Tenant_Prepare::run($B, false, ['client' => $f]);
$pl = array_values(array_filter($f->writes, function ($x) { return preg_match('#^/api/v1/catalog/price-lists/[^/]+$#', $x[1]); }));
$check('tenant đã có bảng công ty CTY → bảng của shop tạo với isCompany = false + lưu ý', $pl && $pl[0][2]['isCompany'] === false && strpos(implode("\n", $r['notes']), 'bảng giá công ty CTY') !== false);
$check('chi nhánh đang áp CTY → không đổi, chỉ lưu ý', !array_filter($f->writes, function ($x) { return substr($x[1], -9) === '/branches'; }) && strpos(implode("\n", $r['notes']), 'đang áp bảng giá CTY') !== false);

echo "== lỗi giữa chừng\n";
$f = new TGS_Tp_Fake_Client();
$f->fail["/api/v1/item-classes/group/{$g1}?new=1"] = new WP_Error('GRP-BLOCK-006', 'tên trùng nhóm cùng cấp');
$r = TGS_Acc_Tenant_Prepare::run($B, false, ['client' => $f]);
$w = $mine($f->writes);
$err = implode("\n", $r['errors']);
$check('nhóm cha lỗi → báo lỗi, KHÔNG gửi nhóm con', count($w) === 1 && strpos($err, 'tên trùng') !== false && strpos($err, "{$tag}{$sep}N: nhóm cha chưa dựng được") !== false && !$r['ok']);

echo "== nguồn ketoan\n";
$wpdb->query("UPDATE `{$C}` SET global_product_cat_sku_code = '' WHERE global_product_cat_id IN ({$g1},{$g2},{$g3})");
if (is_multisite()) { update_blog_option($B, 'tgs_catalog_source', 'ketoan'); } else { update_option('tgs_catalog_source', 'ketoan'); }
$f = new TGS_Tp_Fake_Client();
TGS_Acc_Tenant_Prepare::run($B, false, ['client' => $f]);
$check('shop nguồn ketoan: vẫn dựng tenant nhưng KHÔNG ghi mã SKU nhóm vào tsm', count($mine($f->writes)) === 3 && $codes() === ['', '', '']);
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
