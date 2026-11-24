<?php
/**
 * SW4b-2 — TGS_Acc_Catalog_Dryrun (kiểm thử danh mục bằng dryRun của kế toán) với client giả: dựng dòng, chia lô, gộp lỗi theo dòng,
 * lưu kết quả, ô ở màn tsm "Chuẩn hàng hoá", nút admin-post. Chạy TRONG WordPress thử; thêm mã / nhóm tạm rồi xoá.
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/catalog-dryrun-wp-test.php --url=shop-b.okt.local
 */
// [2026-11-24 09:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-2 — test.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ máy dev.\n"); exit(1); }
global $wpdb;
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
wp_set_current_user(1);
$B = (int) get_current_blog_id();
$P = tgs_catalog_table('product', $B); $C = tgs_catalog_table('cat', $B); $U = tgs_catalog_table('unit', $B);
$before = TGS_Acc_Site::opt($B, TGS_Acc_Catalog_Dryrun::OPTION, null);
$tag = 'DR' . mt_rand(1000, 9999);
$pids = []; $cids = []; $uids = [];
register_shutdown_function(function () use (&$pids, &$cids, &$uids, $P, $C, $U, $B, $before) {
    global $wpdb;
    foreach ($pids as $id) { $wpdb->delete($P, ['global_product_name_id' => $id]); }
    foreach ($cids as $id) { $wpdb->delete($C, ['global_product_cat_id' => $id]); }
    foreach ($uids as $id) { $wpdb->delete($U, ['global_htsoft_unit_base_id' => $id]); }
    $before === null ? TGS_Acc_Site::del($B, TGS_Acc_Catalog_Dryrun::OPTION) : TGS_Acc_Site::set($B, TGS_Acc_Catalog_Dryrun::OPTION, $before);
});
$wpdb->insert($C, ['global_product_cat_code' => $tag, 'global_product_cat_name' => $tag, 'global_product_cat_path' => $tag, 'global_product_cat_status' => 1, 'is_deleted' => 0, 'user_id' => 1,
    'id_sync_api' => 'G-' . $tag, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
$cids[] = $cat = (int) $wpdb->insert_id;
$add = function ($sku, array $cols = []) use ($wpdb, $P, &$pids, $tag) {
    $wpdb->insert($P, $cols + ['global_product_sku' => $sku, 'global_product_name' => "{$tag} {$sku}", 'global_product_unit' => 'Cái', 'global_product_status' => 1, 'is_deleted' => 0,
        'global_product_tax' => 8, 'global_product_is_kct' => 0, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
    $pids[] = (int) $wpdb->insert_id;
};
$n = mt_rand(10000, 99999);
$s1 = "8{$n}001"; $s2 = "8{$n}002"; $s3 = "8{$n}003"; $s1v = $s1 . 'A';
$add($s1v, ['global_product_parent_sku' => $s1]); // mã con thêm TRƯỚC mã cha trong bảng
$add($s1, ['global_product_cat_id' => $cat, 'global_product_unit' => 'cai']);
$add($s2, ['global_product_is_kct' => 1, 'global_product_tax' => 0, 'global_product_status' => 0]);
$add($s3, ['global_product_tax' => null]);
$wpdb->insert($U, ['global_product_sku' => $s1, 'convert_unit' => 'Thùng', 'convert_to_htsoft' => 24, 'is_default_unit' => 0, 'is_deleted' => 0]); $uids[] = (int) $wpdb->insert_id;
$wpdb->insert($U, ['global_product_sku' => $s1, 'convert_unit' => 'Lon', 'convert_to_htsoft' => 1, 'is_default_unit' => 1, 'is_deleted' => 0]); $uids[] = (int) $wpdb->insert_id;

echo "== dựng dòng\n";
$rows = TGS_Acc_Catalog_Dryrun::rows($B);
$by = []; $pos = [];
foreach ($rows as $i => $r) { $by[$r['sku']] = $r; $pos[$r['sku']] = $i; }
$check('mọi mã còn sống thành một dòng, row đánh số 1…n', count($rows) === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$P}` WHERE is_deleted = 0 OR is_deleted IS NULL") && $rows[0]['row'] === 1 && end($rows)['row'] === count($rows));
$check('mã cha trước mã con (dù mã con có trong bảng trước)', isset($pos[$s1], $pos[$s1v]) && $pos[$s1] < $pos[$s1v]);
$check('groupCode = id_sync_api của nhóm (quy ước SW4b-1 / chiều kéo về)', ($by[$s1]['groupCode'] ?? '') === 'G-' . $tag);
$check('ĐVT = ĐVT tỉ lệ 1 ở bảng ĐVT (Lon), không phải cột ĐVT của mã', ($by[$s1]['unit'] ?? '') === 'Lon');
$check('thuế: 8 → "8"; KCT → "KCT"; chưa khai → không gửi (kế toán báo thiếu)', ($by[$s1]['vatRate'] ?? '') === '8' && ($by[$s2]['vatRate'] ?? '') === 'KCT' && !isset($by[$s3]['vatRate']));
$check('isActive: Có / Không theo trạng thái', ($by[$s1]['isActive'] ?? '') === 'Có' && ($by[$s2]['isActive'] ?? '') === 'Không');

echo "== gửi kế toán (client giả)\n";
class TGS_Dr_Fake
{
    public $calls = [];
    public $fail_at = 0;
    public function post_json($path, array $body)
    {
        $this->calls[] = [$path, $body];
        if ($this->fail_at && count($this->calls) === $this->fail_at) { return new WP_Error('ketoan_http', 'hết giờ'); }
        $rows = []; $c = 0; $f = 0;
        foreach ($body['rows'] as $r) {
            $err = isset($r['vatRate']) ? [] : ['Mặt hàng mới phải có Thuế suất GTGT'];
            $rows[] = ['row' => $r['row'], 'sku' => $r['sku'], 'action' => $err ? 'error' : 'create', 'errors' => $err];
            $err ? $f++ : $c++;
        }
        return ['dryRun' => true, 'created' => $c, 'updated' => 0, 'failed' => $f, 'rows' => $rows];
    }
}
$f = new TGS_Dr_Fake();
$r = TGS_Acc_Catalog_Dryrun::run($B, ['client' => $f, 'batch' => 2]);
$check('chia lô: ' . count($f->calls) . ' lô × ≤ 2 dòng, mỗi lô dryRun = true, đúng đường', count($f->calls) === (int) ceil(count($rows) / 2) && !array_filter($f->calls, function ($c) { return $c[0] !== '/api/v1/catalog/products/import' || $c[1]['dryRun'] !== true || count($c[1]['rows']) > 2; }));
$errsku = array_column($r['rows'], 'sku');
$check('gộp kết quả: tổng = số dòng; dòng lỗi có SKU + lời kế toán', $r['total'] === count($rows) && $r['create'] + $r['failed'] === count($rows) && in_array($s3, $errsku, true) && strpos(implode(' ', $r['rows'][array_search($s3, $errsku, true)]['errors']), 'Thuế suất') !== false);
$check('có lỗi → ok = false; lưu kết quả vào option của blog', !$r['ok'] && TGS_Acc_Catalog_Dryrun::last($B)['failed'] === $r['failed']);
$f = new TGS_Dr_Fake(); $f->fail_at = 1;
$r = TGS_Acc_Catalog_Dryrun::run($B, ['client' => $f, 'batch' => 2]);
$check('một lô lỗi mạng → báo lô đó, các lô sau vẫn kiểm', count($r['errors']) === 1 && strpos($r['errors'][0], 'hết giờ') !== false && count($f->calls) === (int) ceil(count($rows) / 2));

echo "== màn tsm + nút\n";
if (!class_exists('TGS_Catalog_Readiness_Admin')) { require_once TGS_SHOP_PLUGIN_DIR . 'functions/class-tgs-catalog-readiness-admin.php'; }
TGS_Catalog_Readiness::run($B);
ob_start(); TGS_Catalog_Readiness_Admin::render(); $html = (string) ob_get_clean();
$check('màn Chuẩn hàng hoá có ô "Kiểm thử ở kế toán" (hook tgs_catalog_readiness_panels) + kết quả + nút', strpos($html, 'id="tgs-acc-dryrun"') !== false && strpos($html, 'id="tgs-acc-dryrun-sum"') !== false && strpos($html, 'id="tgs-acc-dryrun-btn"') !== false);
$check('bảng dòng lỗi có SKU lỗi', strpos($html, $s3) !== false && strpos($html, 'id="tgs-acc-dryrun-rows"') !== false);
remove_action('tgs_catalog_readiness_panels', ['TGS_Acc_Catalog_Dryrun', 'panel']);
ob_start(); TGS_Catalog_Readiness_Admin::render(); $html2 = (string) ob_get_clean();
add_action('tgs_catalog_readiness_panels', ['TGS_Acc_Catalog_Dryrun', 'panel']);
$check('không có bridge gắn ô → màn tsm vẫn đủ, không có ô (P-0 ý 4)', strpos($html2, 'id="tgs-acc-dryrun"') === false && strpos($html2, 'id="tgs-rd-score"') !== false);
$_POST = $_REQUEST = ['_wpnonce' => 'sai'];
$die = function () { return function ($m) { throw new RuntimeException('DIE'); }; };
add_filter('wp_die_handler', $die, PHP_INT_MAX);
$x = ''; try { TGS_Acc_Catalog_Dryrun::handle(); } catch (RuntimeException $e) { $x = $e->getMessage(); }
remove_filter('wp_die_handler', $die, PHP_INT_MAX);
$_POST = $_REQUEST = [];
$check('nút admin-post sai nonce → dừng, không chạy', $x === 'DIE');
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
