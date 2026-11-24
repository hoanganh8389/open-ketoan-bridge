<?php
/**
 * SW3 — bridge khoá / nguồn danh mục THEO TỪNG BLOG (tsm tgs_catalog_source). Chạy TRONG WordPress thử, có tsm:
 *
 *   wp --exec="define('TGS_ACC_CATALOG_FROM_ERP', true);" eval-file wp-content/plugins/open-ketoan-bridge/tests/catalog-source-wp-test.php --url=shop-b.okt.local
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/catalog-source-wp-test.php --url=shop-b.okt.local      (công tắc tắt: không đổi gì)
 *
 * Không gọi kế toán, không kéo gì. Tự dọn, trả option như cũ.
 */
// [2026-11-24 02:40 AM Johnny Chu - Chu Hoàng Anh] SW3 — test bridge khoá theo nguồn của blog.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) {
    fwrite(STDERR, "Từ chối: chỉ chạy trên WordPress thử.\n");
    exit(1);
}
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
if (!function_exists('tgs_catalog_source')) { fwrite(STDERR, "Cần tsm có tgs_catalog_source (open-pos-backend SW3-1).\n"); exit(1); }
$B = (int) get_current_blog_id();
$on = TGS_Acc_Catalog_Sync::enabled();
$ready = TGS_Acc_Config::ready_for($B);
$store = TGS_Catalog_Store::store($B);
$keep = ['src' => TGS_Acc_Site::opt($B, 'tgs_catalog_source', null), 'store' => TGS_Acc_Site::opt($B, 'tgs_catalog_store', null)];
register_shutdown_function(function () use ($B, $keep) {
    foreach (['tgs_catalog_source' => 'src', 'tgs_catalog_store' => 'store'] as $opt => $k) {
        $keep[$k] === null ? TGS_Acc_Site::del($B, $opt) : TGS_Acc_Site::set($B, $opt, $keep[$k]);
    }
});
TGS_Acc_Site::del($B, 'tgs_catalog_source');
echo "  ·  blog {$B}: kéo danh mục " . ($on ? 'BẬT' : 'tắt') . ', nối kế toán ' . ($ready ? 'có' : 'không') . ", cờ đọc bảng {$store}\n";

$check('tgs_okt_connected = kết nối của blog (bridge trả lời)', tgs_okt_connected($B) === $ready);

if (!$on) {
    $check('công tắc tắt: nguồn mặc định local, không khoá (như trước)', tgs_catalog_source($B) === 'local' && !tgs_catalog_is_readonly());
} else {
    TGS_Acc_Site::del($B, 'tgs_catalog_store');
    $want = $ready ? 'ketoan' : 'local';
    $check("bật kéo + cờ global + " . ($ready ? 'đã nối' : 'chưa nối') . " → nguồn mặc định {$want}", tgs_catalog_source($B) === $want);
    $check('khoá sửa (tgs_catalog_readonly / tgs_price_edit_locked) theo nguồn', tgs_catalog_is_readonly() === ($want === 'ketoan') && (bool) apply_filters('tgs_price_edit_locked', false) === ($want === 'ketoan'));
    $g = tgs_catalog_write_gate('erp', $B, false);
    $check('cổng ghi của tsm theo nguồn', ($want === 'ketoan') === is_wp_error($g));

    tgs_catalog_set_source($B, 'local');
    $check('admin chọn rõ "Tự quản" → giữ local dù đã nối, mở khoá', tgs_catalog_source($B) === 'local' && !tgs_catalog_is_readonly() && tgs_catalog_write_gate('erp', $B, false) === true);
    TGS_Acc_Site::del($B, 'tgs_catalog_source');

    TGS_Catalog_Store::ensure($B);
    TGS_Catalog_Store::set_store($B, 'local', true);
    $check('blog cờ local (bảng riêng — dữ liệu kéo về không tới) → nguồn local, không khoá', tgs_catalog_source($B) === 'local' && !tgs_catalog_is_readonly());

    // block_writes: AJAX ghi danh mục bị chặn chỉ khi nguồn ketoan
    $_REQUEST['action'] = 'tgs_milk_under24m_delete';
    add_filter('wp_doing_ajax', '__return_true');
    $die = function () { return function () { throw new RuntimeException('__ajax_done__'); }; };
    add_filter('wp_die_ajax_handler', $die);
    $blocked = function () { ob_start(); try { TGS_Acc_Catalog_Sync::block_writes(); ob_end_clean(); return false; } catch (RuntimeException $e) { ob_end_clean(); return true; } };
    $check('blog local: block_writes cho qua', $blocked() === false);
    TGS_Acc_Site::del($B, 'tgs_catalog_store');
    if ($ready) {
        $check('blog ketoan: block_writes chặn AJAX ghi danh mục', $blocked() === true);
    }
    remove_filter('wp_die_ajax_handler', $die);
    remove_filter('wp_doing_ajax', '__return_true');
}
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
