<?php
/**
 * Kiểm vé đăng nhập phần mềm kế toán bằng tài khoản WordPress (TGS_Acc_Embed::ticket) không cần WordPress: giả lập vài hàm WP.
 * Hằng wp-config không định nghĩa lại được trong một tiến trình, nên kịch bản có hằng chạy ở tiến trình riêng.
 *
 *   php tests/sso-ticket-test.php         chạy mọi kịch bản (mỗi kịch bản một tiến trình con)
 *   php tests/sso-ticket-test.php T2      chạy một kịch bản
 *
 * T1 vé mang sub = wp:<ID> và aud mặc định open-ketoan, chữ ký HMAC đúng, hạn ≤ 5 phút
 * T2 hằng TGS_KETOAN_SSO_AUD đổi được aud · T3 hằng trống → quay về mặc định
 * T4 chưa đặt khoá chung / chưa đăng nhập / không có email → vé rỗng (hành vi cũ giữ nguyên)
 * T5 trường cũ còn đủ (email, name, login, perms, tenant, iat, exp) — ERP cũ bỏ qua sub / aud nên không vỡ
 *
 * Mã thoát 0 = đạt hết.
 */
// [2026-11-24 11:10 PM Johnny Chu - Chu Hoàng Anh] SPINE-6 — test vé SSO có sub + aud (bizcity_identity@1).

$SCENARIOS = ['T1', 'T2', 'T3', 'T4', 'T5'];

if ($argc < 2) {
    $fail = 0;
    foreach ($SCENARIOS as $s) {
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $s . ' 2>&1', $out, $code);
        echo implode("\n", $out), "\n";
        if ($code !== 0) {
            $fail++;
        }
    }
    echo $fail === 0 ? "\nKẾT QUẢ: ĐẠT " . count($SCENARIOS) . '/' . count($SCENARIOS) . "\n" : "\nKẾT QUẢ: KHÔNG ĐẠT ($fail kịch bản)\n";
    exit($fail === 0 ? 0 : 1);
}

$scn = $argv[1];

// ── hằng wp-config theo kịch bản (phải đặt trước khi nạp lớp) ──
define('ABSPATH', __DIR__ . '/');
if ($scn !== 'T4') {
    define('TGS_KETOAN_SSO_SECRET', 'khoa-chung-kiem-thu-0123456789abcdef');
}
if ($scn === 'T2') {
    define('TGS_KETOAN_SSO_AUD', 'ketoan-chi-nhanh-2');
}
if ($scn === 'T3') {
    define('TGS_KETOAN_SSO_AUD', '   ');
}

// ── giả lập WordPress ──
$GLOBALS['USER'] = new class {
    public $ID = 1203;
    public $user_email = 'NV.A@Example.TEST';
    public $display_name = 'Nguyễn Văn A';
    public $user_login = 'nva';
    public function exists() { return $this->ID > 0; }
};
function wp_get_current_user() { return $GLOBALS['USER']; }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function apply_filters($tag, $value) { return $value; }
function add_filter() {}
function add_action() {}
function is_super_admin($id) { return true; } // quản trị mạng → có quyền; kiểm vé, không kiểm phân quyền
function get_site_option($k, $d = false) { return $d; }
function is_multisite() { return false; }

require dirname(__DIR__) . '/includes/class-tgs-acc-config.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-embed.php';

function ok($c, $msg)
{
    echo ($c ? '  ✔ ' : '  ✘ ') . $msg . "\n";
    if (!$c) {
        exit(1);
    }
}

/** Giải vé: kiểm chữ ký, trả mảng JSON. */
function decode($ticket)
{
    [$body, $sig] = explode('.', $ticket);
    $want = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, TGS_KETOAN_SSO_SECRET, true)), '+/', '-_'), '=');
    ok(hash_equals($want, $sig), 'chữ ký HMAC-SHA256 đúng');
    return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
}

echo "[$scn]\n";
$t = TGS_Acc_Embed::ticket();

switch ($scn) {
    case 'T1':
        $c = decode($t);
        ok($c['sub'] === 'wp:1203', 'sub = wp:<ID người dùng WordPress>');
        ok(preg_match('/^(wp:[0-9]+|biz:[A-Za-z0-9_-]{1,64})$/', $c['sub']) === 1, 'sub đúng khuôn bizcity_identity@1');
        ok($c['aud'] === 'open-ketoan', 'aud mặc định = open-ketoan');
        ok($c['exp'] - $c['iat'] <= 300 && $c['exp'] > $c['iat'], 'hạn vé ≤ 5 phút');
        break;
    case 'T2':
        ok(decode($t)['aud'] === 'ketoan-chi-nhanh-2', 'hằng TGS_KETOAN_SSO_AUD đổi được aud');
        break;
    case 'T3':
        ok(decode($t)['aud'] === 'open-ketoan', 'hằng chỉ có khoảng trắng → về mặc định, không gửi aud rỗng');
        break;
    case 'T4':
        ok($t === '', 'chưa đặt khoá chung → vé rỗng (mở thẳng địa chỉ ERP như cũ)');
        break;
    case 'T5':
        $c = decode($t);
        ok($c['email'] === 'nv.a@example.test', 'email viết thường');
        ok($c['name'] === 'Nguyễn Văn A' && $c['login'] === 'nva', 'name, login giữ nguyên');
        ok(is_array($c['perms']) && in_array('reports:read', $c['perms'], true), 'perms còn đủ');
        ok(array_key_exists('tenant', $c) && isset($c['iat'], $c['exp']), 'tenant, iat, exp còn đủ');
        break;
}
echo "  ĐẠT\n";
