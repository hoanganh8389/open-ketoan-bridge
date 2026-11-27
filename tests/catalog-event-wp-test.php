<?php
/**
 * NT-BH-062 — sự kiện "danh mục hàng đổi" qua webhook app tgs-wp: cầu nối nhận → hẹn kéo danh mục NGAY sau khi trả lời (một lần mỗi
 * yêu cầu), không gắn blog; route catalog-changed dùng chung đường hẹn. Chạy TRONG WordPress thử (.local); không kéo thật (gỡ hẹn
 * shutdown trước khi thoát), tự dọn.
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/catalog-event-wp-test.php --url=shop2.thegioisua.local
 */
// [2026-11-28 09:00 PM Võ Hoàng] NGHIEM-THU — NT-BH-062: test nhận sự kiện danh mục đổi (đóng góp)

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/\.local$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ multisite thử (.local).\n"); exit(1); }
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };

$evt = ['id' => wp_generate_uuid4(), 'type' => 'bizcity.masterdata.catalog.changed', 'data' => ['at' => gmdate('c')]];
$before = has_action('shutdown') ? count($GLOBALS['wp_filter']['shutdown']->callbacks[100] ?? []) : 0;
try {
    $on = TGS_Acc_Catalog_Sync::enabled();
    echo '  ·   danh mục lấy từ kế toán: ' . ($on ? 'BẬT' : 'tắt') . "\n";
    $r = TGS_Acc_Ketoan_Events::process($evt);
    $check('xử lý không lỗi', !is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : '');
    $check('không gắn blog (không phải chứng từ)', !is_wp_error($r) && empty($r['blog_id']));
    $after = count($GLOBALS['wp_filter']['shutdown']->callbacks[100] ?? []);
    if ($on) {
        $check('bật → applied, hẹn kéo ngay', !is_wp_error($r) && $r['status'] === 'applied' && $after === $before + 1, json_encode([$r, $before, $after]));
        TGS_Acc_Ketoan_Events::process($evt + ['id' => wp_generate_uuid4()]);
        $again = TGS_Acc_Catalog_Sync::pull_after_response();
        $check('gọi lại trong cùng yêu cầu chỉ hẹn một lần', ($again['queued'] ?? false) === true && count($GLOBALS['wp_filter']['shutdown']->callbacks[100] ?? []) === $after);
    } else {
        $check('tắt → skipped, không hẹn', !is_wp_error($r) && $r['status'] === 'skipped' && $after === $before, json_encode([$r, $before, $after]));
    }
    $other = TGS_Acc_Ketoan_Events::process(['id' => wp_generate_uuid4(), 'type' => 'loai.la', 'data' => []]);
    $check('loại sự kiện lạ vẫn bỏ qua như cũ', !is_wp_error($other) && $other['status'] === 'skipped');
} finally {
    // Không kéo thật khi test kết thúc: gỡ các hẹn shutdown vừa thêm.
    $cbs = $GLOBALS['wp_filter']['shutdown']->callbacks[100] ?? [];
    foreach (array_slice($cbs, $before, null, true) as $k => $cb) {
        remove_action('shutdown', $cb['function'], 100);
    }
}
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
