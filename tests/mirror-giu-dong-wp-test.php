<?php
/**
 * Bản sao chứng từ kế toán: ghi lại đơn (version mới) GIỮ id dòng hàng; phiếu hoàn chép sang được gắn dòng bán gốc
 * (source_ledger_item_id) — plugin Viettel cần cả hai để lập hoá đơn điều chỉnh. Chạy TRONG WordPress multisite thử; tự dọn.
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/mirror-giu-dong-wp-test.php
 */
// [2026-11-27 05:00 PM Võ Hoàng] Bắt lỗi phiếu hoàn CN02.B00013 không lập được hoá đơn điều chỉnh (đơn gốc chép lại đổi id dòng) (đóng góp)

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/\.local$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ multisite thử (.local).\n"); exit(1); }
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };

global $wpdb;
$blog = get_current_blog_id();
$L = $wpdb->prefix . 'local_ledger';
$I = $wpdb->prefix . 'local_ledger_item';
$S = $wpdb->prefix . 'tgs_pos_mirror';
$products = array_map('intval', (array) $wpdb->get_col("SELECT global_product_name_id FROM {$wpdb->base_prefix}global_product_name ORDER BY global_product_name_id DESC LIMIT 2"));
if (count($products) < 2) { echo "  ·   thiếu mặt hàng thử — bỏ\nKẾT QUẢ: ĐẠT 0/0\n"; return; }
[$pa, $pb] = $products;
$sale_pg = wp_generate_uuid4();
$ret_pg = wp_generate_uuid4();
$code = 'TEST-GIU-' . wp_generate_password(6, false, false);

$item = function ($role, $product, $sku, $qty, $price) {
    return ['ledger_role' => $role, 'local_product_name_id' => $product, 'quantity' => $qty, 'price' => $price,
        'local_ledger_item_meta' => ['sku' => $sku, 'is_gift' => false, 'unit_ratio' => 1, 'source' => 'ketoan']];
};
$sale_doc = function ($version, array $items) use ($blog, $sale_pg, $code) {
    return ['blog_id' => $blog, 'entity' => 'order', 'id' => $sale_pg, 'version' => $version, 'rows' => [
        'ledger' => [['role' => 'sale', 'local_ledger_type' => 10, 'local_ledger_code' => $code, 'local_ledger_total_amount' => 300000]],
        'items' => $items, 'person' => ['guest' => true]]];
};
$ids_of = function ($ledger_id) use ($wpdb, $L) {
    return array_map('intval', (array) json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT local_ledger_item_id FROM {$L} WHERE local_ledger_id = %d", $ledger_id)), true));
};

try {
    $r1 = TGS_Acc_Ledger_Mirror::apply($sale_doc(1, [$item('sale', $pa, 'TA', 3, 100000), $item('sale', $pb, 'TB', 1, 50000)]));
    $check('ghi đơn lần 1', !is_wp_error($r1) && !empty($r1['ledger_ids']['sale']), is_wp_error($r1) ? $r1->get_error_message() : '');
    $sale_id = (int) ($r1['ledger_ids']['sale'] ?? 0);
    $first = $ids_of($sale_id);
    $check('đơn có 2 dòng', count($first) === 2);

    // Kế toán gửi lại (vd lúc xuất hoá đơn): cùng hàng, đổi số tiền → id dòng giữ nguyên, nội dung được sửa.
    $r2 = TGS_Acc_Ledger_Mirror::apply($sale_doc(2, [$item('sale', $pa, 'TA', 3, 90000), $item('sale', $pb, 'TB', 1, 50000)]));
    $second = $ids_of($sale_id);
    $check('ghi lại giữ nguyên id dòng', $second === $first, json_encode([$first, $second]));
    $check('ghi lại có sửa nội dung', (float) $wpdb->get_var($wpdb->prepare("SELECT price FROM {$I} WHERE local_ledger_item_id = %d", $first[0])) === 90000.0);

    // Bỏ một mặt hàng → dòng đó bị xoá, dòng kia giữ id.
    TGS_Acc_Ledger_Mirror::apply($sale_doc(3, [$item('sale', $pa, 'TA', 3, 90000)]));
    $third = $ids_of($sale_id);
    $check('bỏ hàng: còn đúng dòng cũ', $third === [$first[0]], json_encode($third));
    $check('bỏ hàng: dòng dư đã xoá', !$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$I} WHERE local_ledger_item_id = %d", $first[1])));

    // Phiếu hoàn trỏ đơn gốc → dòng hoàn gắn dòng bán.
    $r4 = TGS_Acc_Ledger_Mirror::apply(['blog_id' => $blog, 'entity' => 'return', 'id' => $ret_pg, 'version' => 1, 'rows' => [
        'ledger' => [['role' => 'return', 'local_ledger_type' => 11, 'local_ledger_code' => $code . '-H', 'parent_ledger_id' => $sale_id]],
        'items' => [$item('return', $pa, 'TA', 1, 90000)], 'person' => ['guest' => true]]]);
    $check('ghi phiếu hoàn', !is_wp_error($r4) && !empty($r4['ledger_ids']['return']), is_wp_error($r4) ? $r4->get_error_message() : '');
    $ret_items = $ids_of((int) ($r4['ledger_ids']['return'] ?? 0));
    $meta = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT local_ledger_item_meta FROM {$I} WHERE local_ledger_item_id = %d", $ret_items[0] ?? 0)), true);
    $check('dòng hoàn gắn dòng bán', (int) ($meta['source_ledger_item_id'] ?? 0) === $first[0], json_encode($meta['source_ledger_item_id'] ?? null));
    $check('dòng hoàn ghi phiếu bán gốc', (int) ($meta['source_export_ledger_id'] ?? 0) === $sale_id);
} finally {
    foreach ([$sale_pg, $ret_pg] as $pg) {
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ledger_id FROM {$S} WHERE pg_id = %s", $pg)) as $lid) {
            $wpdb->delete($I, ['local_ledger_id' => (int) $lid]);
            $wpdb->delete($L, ['local_ledger_id' => (int) $lid]);
        }
        $wpdb->delete($S, ['pg_id' => $pg]);
    }
}
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
