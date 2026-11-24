<?php
/**
 * SW3-4 — kiểm trong WordPress thử cho ca vàng G5 (dùng trong tests/g5-khong-khoa.sh). In "ok / SAI"; dòng cuối "KẾT QUẢ".
 *   G5_PHASE=trangthai  (có mu-plugin G5) mọi blog: không khoá → tự quản, không nối, nguồn local, không khoá danh mục
 *   G5_PHASE=cron       (có mu-plugin G5) chạy MỌI lịch của bridge ở blog hiện tại, đếm lời gọi HTTP mỗi lịch
 *   G5_PHASE=ban        (có mu-plugin G5) bán 1 đơn POS + tạo 1 mã qua form tsm ở blog hiện tại
 *   G5_PHASE=khoa       (KHÔNG mu-plugin) khoá theo blog — S002-12: client theo blog gửi đúng khoá của blog đó (bắt header, không gọi thật)
 */
// [2026-11-24 04:10 PM Johnny Chu - Chu Hoàng Anh] SW3-4 — ca vàng G5 + gửi bằng khoá của blog.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ máy dev.\n"); exit(1); }
global $wpdb;
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
$log_lines = function () { return is_readable('/tmp/g5-http.log') ? count(file('/tmp/g5-http.log')) : 0; };
$blogs = is_multisite() ? array_map('intval', wp_list_pluck(get_sites(['number' => 500, 'deleted' => 0, 'archived' => 0]), 'blog_id')) : [1];
$phase = (string) getenv('G5_PHASE');
$mu = defined('TGS_G5_MU');
$only = array_filter(explode(',', (string) getenv('G5_HOOKS'))); // đối chứng: chỉ chạy các lịch kéo chỉ đọc
wp_set_current_user(1);
$B = (int) get_current_blog_id();

if (in_array($phase, ['trangthai', 'cron', 'ban'], true) && !$mu) { echo "SAI: chưa có mu-plugin G5 (tests/g5-mu-khong-khoa.php) — dừng\n"; echo "KẾT QUẢ: SAI\n"; return; }

if ($phase === 'trangthai') {
    $check('URL kế toán vẫn khai (ca xấu nhất: có địa chỉ, không khoá)', TGS_Acc_Config::url_for(0) !== '' || TGS_Acc_Config::url_for($B) !== '');
    $check('khoá cài đặt: không có → nhánh install tắt', TGS_Acc_Install::key() === '' && !TGS_Acc_Install::active());
    foreach ($blogs as $b) {
        $mode = TGS_Acc_Config::key_mode($b);
        $src = function_exists('tgs_catalog_source') ? tgs_catalog_source($b) : '?';
        $conn = function_exists('tgs_okt_connected') ? tgs_okt_connected($b) : null;
        tgs_g5_switch($b);
        $locked = TGS_Acc_Catalog_Sync::locked_here(false);
        $targets = TGS_Acc_Events::targets();
        tgs_g5_restore();
        $check("blog {$b}: không khoá (key_mode none), không sẵn sàng gửi, tgs_okt_connected = false, nguồn local, danh mục không khoá, sự kiện không đích",
            $mode === 'none' && !TGS_Acc_Config::ready_for($b) && $conn === false && $src === 'local' && !$locked && empty($targets),
            "mode={$mode} ready=" . (int) TGS_Acc_Config::ready_for($b) . ' conn=' . var_export($conn, true) . " nguồn={$src} khoá=" . (int) $locked . ' đích=' . count((array) $targets));
    }
}

if ($phase === 'cron') {
    $hooks = [TGS_Acc_Events::CRON_HOOK => [], TGS_Acc_Events::STATUS_HOOK => [], TGS_Acc_Catalog_Sync::CRON => [], TGS_Acc_Install::PING_HOOK => [],
        TGS_Acc_Selfcheck::DAILY_HOOK => [], TGS_Acc_Supplier_Sync::CRON => [], TGS_Acc_Warehouse_Sync::CRON => [],
        TGS_Acc_Branch_Sync::EVENT_ALL => [], TGS_Acc_Branch_Sync::EVENT_SITE => [$B]];
    foreach ($hooks as $h => $args) {
        if ($only && !in_array($h, $only, true)) { continue; }
        if (!has_action($h)) { echo "  ·   lịch {$h}: không gắn ở blog này\n"; continue; }
        $n0 = $log_lines();
        $err = '';
        ob_start();
        try { do_action_ref_array($h, $args); } catch (Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
        ob_end_clean();
        $n = $log_lines() - $n0;
        $check("blog {$B} lịch {$h}: chạy không lỗi, 0 lời gọi HTTP", $err === '' && $n === 0, $err !== '' ? $err : "{$n} lời gọi");
    }
}

if ($phase === 'ban') {
    $n0 = $log_lines();
    $ajax = function (callable $cb, array $post) {
        $_POST = $_REQUEST = wp_slash($post);
        add_filter('wp_doing_ajax', '__return_true');
        $die = function () { return function () { throw new RuntimeException('__ajax_done__'); }; };
        add_filter('wp_die_ajax_handler', $die);
        ob_start();
        $err = '';
        try { $cb(); } catch (RuntimeException $e) { if ($e->getMessage() !== '__ajax_done__') { $err = $e->getMessage(); } } catch (Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(); }
        $out = (string) ob_get_clean();
        remove_filter('wp_die_ajax_handler', $die);
        remove_filter('wp_doing_ajax', '__return_true');
        $j = json_decode($out, true);
        return is_array($j) ? $j : ['success' => false, 'data' => ['message' => $err ?: substr($out, 0, 200)]];
    };
    $t = tgs_catalog_table('product');
    $pick = null;
    foreach ((array) $wpdb->get_col("SELECT global_product_sku FROM `{$t}` WHERE (is_deleted = 0 OR is_deleted IS NULL) AND global_product_sku LIKE '9000000%' ORDER BY global_product_name_id") as $s) {
        $p = TGS_Global_Product_Source::get_product_object($s, ['by' => 'sku', 'with_stock' => true, 'blog_id' => $B]);
        if ($p && (float) ($p->projected_stock ?? 0) >= 1) { $pick = $p; break; }
    }
    if (!$pick) {
        $check("blog {$B}: có mã thử còn tồn để bán", false, 'chạy lat-doc-seed.php SEED_SKU=900000002');
    } else {
        $sku = (string) $pick->global_product_sku; $pid = (int) $pick->global_product_name_id;
        $j = $ajax(['TGS_POS_Ajax_Order', 'save_order'], ['action' => 'tgs_pos_save_order', 'nonce' => wp_create_nonce('tmd_pos_nonce'),
            'items' => wp_json_encode([['productId' => $pid, 'tgsProductId' => $pid, 'sku' => $sku, 'name' => $pick->global_product_name, 'qty' => 1, 'price' => 11000, 'tax' => 10, 'unit' => 'Cái']]),
            'products' => wp_json_encode([['product_id' => $pid, 'quantity' => 1, 'price' => 10000, 'tax_percent' => 10, 'tax_amount' => 1000, 'subtotal_no_vat' => 10000,
                'price_after_discount' => 10000, 'discount_type' => '', 'discount_value' => 0, 'discount_amount' => 0, 'lot_barcodes' => [], 'note' => 'Bán lẻ theo đơn POS',
                'is_gift' => 0, 'is_under24_promo_danger' => 0, 'gift_scope' => 'order', 'gift_parent_cart_key' => '', 'gift_parent_product_id' => null, 'gift_parent_sku' => '',
                'gift_parent_resolution' => 'order', 'promotion_id' => '', 'promotion_type' => '', 'is_manual_gift_pending' => 0, 'sku' => $sku, 'unit' => 'Cái', 'unit_quantity' => 1,
                'unit_ratio' => 1, 'unit_name' => 'Cái', 'promotion_discount_meta' => null, 'manual_promotion' => null]]),
            'total' => 11000, 'payment_method' => 'cash', 'credit_sale' => '0', 'cash' => 11000, 'change' => 0, 'customer_cash_given' => 11000, 'customer_cash_change' => 0,
            'receipt_no' => '', 'order_note' => 'Ca G5 SW3-4', 'checkout_token' => 'g5-' . wp_generate_password(10, false), 'delivery_method' => 'Tại chỗ']);
        $check("blog {$B}: POS bán 1 đơn {$sku}", !empty($j['success']), substr((string) ($j['data']['message'] ?? ''), 0, 160));
        if (!empty($j['success'])) { echo '      phiếu #' . ($j['data']['order_id'] ?? '?') . "\n"; }
        // đường gửi sau bán chạy ở shutdown / hàng chờ: ép chạy luôn trong tiến trình này để đếm
        if (method_exists('TGS_Acc_Events', 'flush')) { ob_start(); try { TGS_Acc_Events::flush(); } catch (Throwable $e) { echo $e->getMessage(); } ob_end_clean(); }
    }
    if (!class_exists('TGS_Shop_Ajax_Product')) { require_once TGS_SHOP_PLUGIN_DIR . 'functions/tgs-ajax-functions.php'; }
    $sku = 'G5' . mt_rand(100000, 999999);
    $j = $ajax(['TGS_Shop_Ajax_Product', 'create'], ['nonce' => wp_create_nonce(TGS_Shop_Ajax_Product::NONCE_ACTION), 'product_name' => 'Thử G5 ' . $sku, 'product_sku' => $sku,
        'product_unit' => 'Cái', 'product_tax' => '10', 'product_price_after_tax' => '12000', 'product_status' => 1]);
    $check("blog {$B}: tsm tạo mã qua form (danh mục không khoá khi chưa nối)", !empty($j['success']), substr((string) ($j['data']['message'] ?? ''), 0, 120) . ' ' . ($j['data']['code'] ?? ''));
    foreach (['product', 'unit', 'price'] as $k) { $wpdb->delete(tgs_catalog_table($k), ['global_product_sku' => $sku]); }
    $n = $log_lines() - $n0;
    $check("blog {$B}: bán + tạo mã + chạy hàng gửi: 0 lời gọi HTTP", $n === 0, "{$n} lời gọi");
}

if ($phase === 'khoa') {
    $check('không có mu-plugin G5 (khoá thật)', !$mu);
    $seen = [];
    $cap = function ($pre, $args, $url) use (&$seen) {
        $seen[] = ['url' => (string) $url, 'h' => (array) ($args['headers'] ?? [])];
        return ['headers' => [], 'body' => '{"ok":true,"data":{}}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    add_filter('pre_http_request', $cap, 1, 3);
    $fp = function ($k) { return $k === '' ? '∅' : substr(md5((string) $k), 0, 6); }; // vân tay — không in khoá
    foreach ($blogs as $b) {
        $mode = TGS_Acc_Config::key_mode($b);
        if ($mode === 'none') { echo "  ·   blog {$b}: chưa khoá → bỏ\n"; continue; }
        $seen = [];
        TGS_Acc_Api_Client::for_blog($b)->post_json('/api/v1/connection/ping', ['g5' => 1]);
        $h = $seen[0]['h'] ?? [];
        $sent = (string) ($h['X-Api-Key'] ?? '');
        if ($mode === 'own') {
            $want = TGS_Acc_Config::key_for($b);
            $good = $sent !== '' && $sent === $want && !isset($h['X-OKT-Blog']) && strpos($seen[0]['url'] ?? '', TGS_Acc_Config::url_for($b)) === 0;
        } elseif ($mode === 'install') {
            $want = TGS_Acc_Install::key();
            $good = $sent !== '' && $sent === $want && (string) ($h['X-OKT-Blog'] ?? '') === (string) $b;
        } else { // legacy
            $want = TGS_Acc_Config::key_legacy();
            $good = $sent !== '' && $sent === $want;
        }
        $check("blog {$b} ({$mode}): gửi từ ngữ cảnh blog " . get_current_blog_id() . " bằng khoá của blog {$b}", count($seen) === 1 && $good,
            'gửi ' . $fp($sent) . ' / cần ' . $fp($want) . ' · X-OKT-Blog=' . ($h['X-OKT-Blog'] ?? '-'));
    }
    $keys = [];
    foreach ($blogs as $b) { if (TGS_Acc_Config::key_mode($b) === 'own') { $keys[$b] = TGS_Acc_Config::key_for($b); } }
    $check('hai blog khoá riêng không dùng chung một khoá (I-1)', count($keys) === count(array_unique($keys)));
    remove_filter('pre_http_request', $cap, 1);
}

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";

function tgs_g5_switch($b) { if (is_multisite()) { switch_to_blog($b); } }
function tgs_g5_restore() { if (is_multisite()) { restore_current_blog(); } }
