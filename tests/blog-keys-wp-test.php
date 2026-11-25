<?php
/**
 * SW2-1 / B001-C4 — TGS_Acc_Blog_Keys: mỗi blog một dòng, trạng thái 🟢🟡⚪🔴, không lộ khoá thật, CSV, danh sách KHÔNG hỏi mạng,
 * AJAX đăng ký đủ. Chạy TRONG WordPress thử (chỉ máy dev).
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/blog-keys-wp-test.php --url=shop-b.okt.local
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/blog-keys-wp-test.php            (okt-wp1, một site)
 */
// [2026-11-25 10:30 AM Johnny Chu - Chu Hoàng Anh] SW2-1 / B001-C4 — test.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ máy dev.\n"); exit(1); }
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
wp_set_current_user(1);
$B = (int) get_current_blog_id();

echo "== trạng thái từ câu trả lời kết nối\n";
$cases = [
    'không khoá' => [['none', null], 'none'],
    'có khoá, chưa kiểm' => [['own', null], 'none'],
    'đạt' => [['own', ['ok' => true, 'error' => '', 'conditions' => ['unique' => ['ok' => true, 'note' => '']]]], 'ok'],
    'đạt nhưng khoá chung dồn tenant' => [['legacy', ['ok' => true, 'error' => '', 'conditions' => ['unique' => ['ok' => true, 'note' => 'Khoá chung dồn 3 site']]]], 'warn'],
    'hỏng' => [['install', ['ok' => false, 'error' => 'BLOG_NOT_LINKED', 'conditions' => []]], 'bad'],
];
foreach ($cases as $name => $c) {
    $r = TGS_Acc_Blog_Keys::state_of($c[0][0], $c[0][1]);
    $check("state_of: {$name} → {$c[1]}", $r[0] === $c[1] && $r[1] !== '', json_encode($r, JSON_UNESCAPED_UNICODE));
}
$check('lý do hỏng được giữ', TGS_Acc_Blog_Keys::state_of('own', ['ok' => false, 'error' => 'X', 'conditions' => []])[1] === 'X');

echo "== dòng của blog\n";
TGS_Acc_Connection::forget($B);
$r = TGS_Acc_Blog_Keys::row($B);
foreach (['blog', 'code', 'name', 'key_mode', 'key_masked', 'url', 'tenant', 'state', 'icon', 'text', 'checked_at'] as $k) {
    $check("dòng có trường {$k}", array_key_exists($k, $r));
}
$check('dòng đúng blog + biểu tượng khớp trạng thái', $r['blog'] === $B && $r['icon'] === TGS_Acc_Blog_Keys::STATES[$r['state']]);

echo "== danh sách KHÔNG hỏi mạng\n";
$hit = 0;
$spy = function ($pre) use (&$hit) { $hit++; return $pre; };
add_filter('pre_http_request', $spy, 1);
$rows = TGS_Acc_Blog_Keys::rows();
remove_filter('pre_http_request', $spy, 1);
$check('rows() không phát yêu cầu HTTP nào', $hit === 0, "hit={$hit}");
$check('rows() có đủ mọi blog hoạt động', count($rows) === count(TGS_Acc_Site::blogs()) && in_array($B, array_column($rows, 'blog'), true));

echo "== đọc từ đệm\n";
$info = ['blog' => $B, 'state' => 'connected', 'ok' => true, 'url' => 'http://x.local', 'tenant' => 'tn-test', 'key_mode' => TGS_Acc_Config::key_mode($B), 'checked_at' => gmdate('c'), 'error' => '',
    'conditions' => ['config' => ['ok' => true, 'note' => ''], 'reachable' => ['ok' => true, 'note' => ''], 'tenant' => ['ok' => true, 'note' => ''], 'unique' => ['ok' => true, 'note' => '']]];
set_site_transient(TGS_Acc_Connection::TRANSIENT . $B, $info, 60);
$check('cached() đọc đệm, không hỏi mạng', (TGS_Acc_Connection::cached($B)['tenant'] ?? '') === 'tn-test');
$r = TGS_Acc_Blog_Keys::row($B);
if ($r['key_mode'] !== 'none') {
    $check('dòng 🟢 + tenant từ đệm', $r['state'] === 'ok' && $r['tenant'] === 'tn-test' && $r['icon'] === '🟢');
} else {
    $check('blog không khoá → ⚪ dù đệm nói gì', $r['state'] === 'none');
}
TGS_Acc_Connection::forget($B);
$check('hết đệm → cached() null', TGS_Acc_Connection::cached($B) === null);

echo "== không lộ khoá thật\n";
$key = TGS_Acc_Config::key_for($B);
$json = json_encode(TGS_Acc_Blog_Keys::rows());
$csv = TGS_Acc_Blog_Keys::csv(TGS_Acc_Blog_Keys::rows());
if (strlen($key) > 8) {
    $check('khoá thật không có trong JSON dòng', strpos($json, $key) === false);
    $check('khoá thật không có trong CSV', strpos($csv, $key) === false);
} else {
    $check('(blog không có khoá dài để dò — bỏ qua dò khoá thật)', true);
}

echo "== CSV\n";
$check('CSV có BOM UTF-8 + dòng tiêu đề', strpos($csv, "\xEF\xBB\xBFblog,ma,ten,") === 0);
$check('CSV một dòng mỗi blog + tiêu đề', count(array_filter(explode("\n", trim($csv)))) === count(TGS_Acc_Blog_Keys::rows()) + 1);
$q = TGS_Acc_Blog_Keys::csv([['blog' => 9, 'code' => 'a,b', 'name' => 'Cửa "hàng"', 'key_mode' => 'own', 'key_masked' => 'ab••••yz', 'url' => 'http://u', 'tenant' => 't', 'state' => 'ok', 'text' => 'Đã nối', 'checked_at' => '']]);
$check('CSV thoát dấu phẩy và nháy kép', strpos($q, '"a,b"') !== false && strpos($q, '"Cửa ""hàng"""') !== false);

echo "== đăng ký AJAX + phân quyền\n";
foreach (['blog_list', 'blog_check', 'blog_save'] as $a) {
    $check("wp_ajax_tgs_acc_{$a} đã đăng ký", has_action('wp_ajax_tgs_acc_' . $a) !== false);
}
$src = (string) file_get_contents(TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-admin.php');
$check('blog_save đòi quyền configure', (bool) preg_match("/function ajax_blog_save\(\)\s*\{\s*self::guard\('configure'\)/", $src));
$check('blog_list / blog_check chỉ đòi quyền xem', (bool) preg_match("/function ajax_blog_list\(\)\s*\{\s*self::guard\(\)/", $src) && (bool) preg_match("/function ajax_blog_check\(\)\s*\{\s*self::guard\(\)/", $src));

echo "== lớp không đụng quầy bán / kế toán\n";
$cls = (string) file_get_contents(TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-blog-keys.php');
$check('không có lời gọi ghi chứng từ / bảng POS', !preg_match('/post_json|TGS_Acc_Pusher|tgs_pos|\$wpdb->(insert|update|delete)/', $cls));

echo "\n{$ok} đạt, {$fail} sai\n";
exit($fail ? 1 : 0);
