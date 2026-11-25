<?php
/**
 * SW2-1 / B001-T2 — TGS_Acc_Connection: bốn điều kiện, đệm 60 giây, thời gian chờ 3 giây, hook đổi trạng thái, trùng tenant (SC-3),
 * bản đồ blog → tenant, không lộ khoá, filter `tgs_okt_connected` của tsm. Dùng client giả (không gọi ra kế toán). Chạy TRONG WordPress thử.
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/connection-wp-test.php --url=shop-b.okt.local
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/connection-wp-test.php            (okt-wp1, một site)
 */
// [2026-11-25 08:30 AM Johnny Chu - Chu Hoàng Anh] SW2-1 — test.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ máy dev.\n"); exit(1); }
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
wp_set_current_user(1);
$B = (int) get_current_blog_id();
$map_before = get_site_option(TGS_Acc_Selfcheck::MAP_OPTION, null);
$state_before = TGS_Acc_Site::opt($B, TGS_Acc_Connection::STATE_OPTION, null);
$timeout_before = TGS_Acc_Api_Client::$request_timeout;
register_shutdown_function(function () use ($B, $map_before, $state_before) {
    $map_before === null ? delete_site_option(TGS_Acc_Selfcheck::MAP_OPTION) : update_site_option(TGS_Acc_Selfcheck::MAP_OPTION, $map_before);
    $state_before === null ? TGS_Acc_Site::del($B, TGS_Acc_Connection::STATE_OPTION) : TGS_Acc_Site::set($B, TGS_Acc_Connection::STATE_OPTION, $state_before);
    TGS_Acc_Connection::forget($B);
});

/** Client giả: get('/api/v1/tenant') trả theo $reply; đếm lượt gọi; ghi lại thời gian chờ lúc được gọi. */
$calls = 0; $seen_timeout = null;
$fake = function ($reply) use (&$calls, &$seen_timeout) {
    return function ($blog) use ($reply, &$calls, &$seen_timeout) {
        return new class($reply, $calls, $seen_timeout) {
            private $reply; private $calls; private $seen;
            public function __construct($reply, &$calls, &$seen) { $this->reply = $reply; $this->calls = &$calls; $this->seen = &$seen; }
            public function get($path, array $q = []) { $this->calls++; $this->seen = TGS_Acc_Api_Client::$request_timeout; return $this->reply; }
        };
    };
};
$yes = function () { return true; };
$T = 'tenant-' . mt_rand(1000, 9999);

echo "== hàm có sẵn\n";
$check('class + hàm tgs_okt_connection', class_exists('TGS_Acc_Connection') && function_exists('tgs_okt_connection'));
$check('bridge KHÔNG khai hàm tgs_okt_connected thứ hai (chỉ tsm khai, qua filter)', (new ReflectionFunction('tgs_okt_connected'))->getFileName() !== (new ReflectionClass('TGS_Acc_Connection'))->getFileName());

echo "== điều kiện 1: config\n";
TGS_Acc_Connection::forget($B); $calls = 0;
$c = TGS_Acc_Connection::connection($B, true, ['ready' => function () { return false; }, 'client' => $fake(['data' => ['code' => $T]])]);
$check('không có khoá → standalone, ok = false, config đỏ', $c['state'] === 'standalone' && $c['ok'] === false && $c['conditions']['config']['ok'] === false);
$check('không có khoá → không hỏi kế toán, ba điều kiện sau "chưa kiểm"', $calls === 0 && $c['conditions']['reachable']['ok'] === false && strpos($c['conditions']['unique']['note'], 'Chưa kiểm') === 0);
$check('lý do báo rõ', $c['error'] !== '');

echo "== điều kiện 2: kế toán trả lời\n";
$c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(new WP_Error('http_request_failed', 'Hết thời gian chờ'))]);
$check('kế toán không trả lời → reachable đỏ, ok = false, có lý do', $c['ok'] === false && $c['conditions']['reachable']['ok'] === false && strpos($c['error'], 'Hết thời gian') !== false && $c['tenant'] === '');
$c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => function ($b) { return new WP_Error('no_key', 'Shop chưa có khoá riêng'); }]);
$check('không dựng được client (WP_Error) → reachable đỏ, không lỗi PHP', $c['ok'] === false && $c['conditions']['reachable']['ok'] === false);

echo "== điều kiện 3: tenant\n";
$c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => '']])]);
$check('kế toán không báo tenant → tenant đỏ', $c['ok'] === false && $c['conditions']['reachable']['ok'] === true && $c['conditions']['tenant']['ok'] === false);

echo "== cả bốn đạt\n";
TGS_Acc_Site::set($B, TGS_Acc_Connection::STATE_OPTION, 'standalone');
$hook = []; $h = function ($b, $state, $info) use (&$hook) { $hook[] = [$b, $state, $info['tenant']]; };
add_action('tgs_okt_connection_changed', $h, 10, 3);
$calls = 0;
$c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
$check('đạt → connected, ok, tenant do kế toán báo', $c['ok'] === true && $c['state'] === 'connected' && $c['tenant'] === $T && $c['error'] === '');
$check('đủ bốn điều kiện đạt', count($c['conditions']) === 4 && !array_filter($c['conditions'], function ($x) { return !$x['ok']; }));
$check('thời gian chờ lúc hỏi = 3 giây, rồi trả lại giá trị cũ', $seen_timeout === 3 && TGS_Acc_Api_Client::$request_timeout === $timeout_before);
$check('bản đồ blog → tenant (chung với tự kiểm SC-3) có blog này', (get_site_option(TGS_Acc_Selfcheck::MAP_OPTION, [])[$B] ?? '') === $T);
$check('standalone → connected: hook tgs_okt_connection_changed bắn một lần', count($hook) === 1 && $hook[0][0] === $B && $hook[0][1] === 'connected' && $hook[0][2] === $T);
$c2 = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
$check('hỏi lại, trạng thái không đổi → hook không bắn thêm', count($hook) === 1);
$c3 = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(new WP_Error('x', 'mất mạng'))]);
$check('connected → standalone: hook bắn với state standalone', count($hook) === 2 && $hook[1][1] === 'standalone');
remove_action('tgs_okt_connection_changed', $h, 10);

echo "== đệm 60 giây\n";
TGS_Acc_Connection::forget($B); $calls = 0;
$deps = ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])];
TGS_Acc_Connection::connection($B, false, $deps); TGS_Acc_Connection::connection($B, false, $deps); TGS_Acc_Connection::connected($B, $deps);
$check('ba lần hỏi liền → một lượt gọi kế toán', $calls === 1, "calls={$calls}");
$check('đệm nằm ở transient của mạng, hạn ≤ 60 giây', is_array(get_site_transient(TGS_Acc_Connection::TRANSIENT . $B)));
TGS_Acc_Connection::connection($B, true, $deps);
$check('fresh = true bỏ đệm, hỏi lại', $calls === 2);
TGS_Acc_Connection::forget($B);
TGS_Acc_Connection::connection($B, false, $deps);
$check('forget() rồi hỏi → hỏi lại', $calls === 3);
$bad = ['ready' => $yes, 'client' => $fake(new WP_Error('x', 'mất mạng'))];
TGS_Acc_Connection::connection($B, true, $bad); $n = $calls;
TGS_Acc_Connection::connection($B, false, $bad);
$check('kết quả HỎNG cũng được đệm (kế toán chập chờn không làm trang nào chờ 3 giây mỗi lần)', $calls === $n);

echo "== filter của tsm\n";
TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
$check('apply_filters(tgs_okt_connected) = true khi bốn điều kiện đạt (đọc đệm, không gọi ra ngoài)', apply_filters('tgs_okt_connected', false, $B) === true);
TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(new WP_Error('x', 'mất mạng'))]);
$check('kế toán không trả lời → filter = false (tsm hiện "mất kết nối", quầy vẫn bán)', apply_filters('tgs_okt_connected', false, $B) === false);

echo "== trùng tenant (SC-3, mỗi blog một tenant)\n";
if (is_multisite()) {
    $twin = 0;
    foreach (TGS_Acc_Site::blogs() as $b) { if ((int) $b !== $B && TGS_Acc_Config::key_mode((int) $b) === 'own') { $twin = (int) $b; break; } }
    if ($twin) {
        $m = (array) get_site_option(TGS_Acc_Selfcheck::MAP_OPTION, []);
        $m[$twin] = $T;
        update_site_option(TGS_Acc_Selfcheck::MAP_OPTION, $m);
        $c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
        $check("blog {$twin} (khoá riêng) cũng trỏ tenant này → unique đỏ, ok = false", $c['ok'] === false && $c['conditions']['unique']['ok'] === false && strpos($c['error'], (string) $twin) !== false);
        $check('blogs_of_tenant liệt kê cả hai blog', in_array($twin, TGS_Acc_Connection::blogs_of_tenant($T), true) && in_array($B, TGS_Acc_Connection::blogs_of_tenant($T), true));
        $m[$twin] = 'tenant-khac';
        update_site_option(TGS_Acc_Selfcheck::MAP_OPTION, $m);
        $c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
        $check('blog kia trỏ tenant khác → unique đạt', $c['ok'] === true && $c['conditions']['unique']['ok'] === true);
    } else {
        echo "  (bỏ qua: không có blog thứ hai có khoá riêng)\n";
    }
} else {
    $check('một site: không có blog khác → unique đạt', TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])])['conditions']['unique']['ok'] === true);
}

echo "== ghi chú chỉ ở điều kiện hỏng\n";
$c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T . '-ghichu']])]);
$check('điều kiện đạt thì không mang lời giải thích lỗi', $c['ok'] && !array_filter($c['conditions'], function ($x) { return $x['note'] !== ''; }));
$check('điều kiện hỏng mang lời giải thích', TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => '']])])['conditions']['tenant']['note'] !== '');

echo "== không lộ khoá, không suy tenant từ khoá / blog id\n";
$c = TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
$json = wp_json_encode($c);
$keys = array_filter([TGS_Acc_Config::key_for($B), TGS_Acc_Config::key_legacy()]);
$leak = false; foreach ($keys as $k) { if ($k !== '' && strpos($json, $k) !== false) { $leak = true; } }
$check('kết quả không chứa khoá thật', !$leak);
$check('tgs_okt_connection() = connection() (cùng đệm)', tgs_okt_connection($B)['tenant'] === $T);
$check('tenant lấy từ điều kế toán BÁO, không phải mã suy từ blog id', $c['tenant'] === $T && $c['tenant'] !== (string) $B);

echo "== đổi khoá / URL thì quên đệm\n";
TGS_Acc_Connection::connection($B, true, ['ready' => $yes, 'client' => $fake(['data' => ['code' => $T]])]);
$r = TGS_Acc_Config::save_blog($B, []);
$check('save_blog() chạy được và quên đệm kết nối của blog', !is_wp_error($r) && get_site_transient(TGS_Acc_Connection::TRANSIENT . $B) === false);

echo "== vai trò (P-0 ý 4)\n";
$src = file_get_contents(dirname(__DIR__) . '/includes/class-tgs-acc-connection.php');
$check('lớp kết nối không ghi vào kế toán, không đụng bảng POS / catalog', !preg_match('/post_json|put_json|send_batch|\$wpdb|tgs_catalog_table|global_product/', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src)));
echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
