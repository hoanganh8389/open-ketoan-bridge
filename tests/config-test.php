<?php
/**
 * Kiểm TGS_Acc_Config ba tầng (H → B → M) không cần WordPress: giả lập vài hàm WP.
 * Hằng wp-config không định nghĩa lại được trong một tiến trình, nên mỗi kịch bản chạy một tiến trình riêng.
 *
 *   php tests/config-test.php            chạy mọi kịch bản (mỗi kịch bản một tiến trình con)
 *   php tests/config-test.php S2         chạy một kịch bản
 *
 * Mã thoát 0 = đạt hết.
 */
// [2026-11-21 01:52 PM Johnny Chu - Chu Hoàng Anh] R-OKT-CONN C1 — test tầng cấu hình theo blog (docs/CAU-HINH-KET-NOI.md §2, §5, §7).

$SCENARIOS = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8'];

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
    echo $fail === 0 ? "\nKẾT QUẢ: ĐẠT " . count($SCENARIOS) . "/" . count($SCENARIOS) . "\n" : "\nKẾT QUẢ: KHÔNG ĐẠT ($fail kịch bản)\n";
    exit($fail === 0 ? 0 : 1);
}

// ── giả lập WordPress ───────────────────────────────────────────────────────
define('ABSPATH', __DIR__ . '/');
$GLOBALS['T'] = ['multisite' => true, 'net' => [], 'blog' => [], 'cur' => 1, 'netadmin' => false, 'sites' => [1, 5, 7]];
function is_multisite() { return $GLOBALS['T']['multisite']; }
function is_network_admin() { return $GLOBALS['T']['netadmin']; }
function get_current_blog_id() { return $GLOBALS['T']['cur']; }
function get_site($id) { return in_array((int) $id, $GLOBALS['T']['sites'], true) ? (object) ['blog_id' => $id] : null; }
function get_site_option($k, $d = false) { return $GLOBALS['T']['net'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['T']['net'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['T']['blog'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['T']['blog'][(int) $b][$k] = $v; return true; }
class WP_Error { public $code; public $msg; function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }

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
function net(array $cfg) { $GLOBALS['T']['net']['tgs_acc_bridge_config'] = $cfg; }
function blog($id, array $cfg) { $GLOBALS['T']['blog'][$id]['tgs_acc_bridge_config'] = $cfg; }
function at($id, $netadmin = false) { $GLOBALS['T']['cur'] = $id; $GLOBALS['T']['netadmin'] = $netadmin; }

$s = $argv[1];

// Hằng phải định nghĩa TRƯỚC khi nạp lớp (giống wp-config).
if ($s === 'S3') {
    define('TGS_KETOAN_API_KEYS', [5 => 'kt_map5', 'x' => 'bo_qua', 7 => '']);
}
if ($s === 'S4') {
    define('TGS_KETOAN_API_KEY', 'kt_const_chung');
}
if ($s === 'S5') {
    $GLOBALS['T']['multisite'] = false;
    $GLOBALS['T']['sites'] = [1];
    define('TGS_KETOAN_API_KEY', 'kt_mot_site');
    define('TGS_KETOAN_API_URL', 'http://localhost:8088/');
}
if ($s === 'S6b') {
    define('TGS_KETOAN_API_URL', 'https://khoa-cung.vd');
}

require dirname(__DIR__) . '/includes/class-tgs-acc-config.php';

echo "── $s\n";
switch ($s) {
    case 'S1': // multisite, chỉ có khoá chung ở tầng mạng (hiện trạng 0.5.0) → hành vi cũ giữ nguyên
        net(['url' => 'https://kt.vd/', 'key' => 'kt_chung']);
        at(5);
        eq('key() blog 5 = khoá chung (như cũ)', TGS_Acc_Config::key(), 'kt_chung');
        eq('url() blog 5 = URL mạng', TGS_Acc_Config::url(), 'https://kt.vd');
        eq('key_for(5) rỗng: chưa có khoá riêng', TGS_Acc_Config::key_for(5), '');
        eq('key_mode(5) = legacy', TGS_Acc_Config::key_mode(5), 'legacy');
        eq('ready() như cũ', TGS_Acc_Config::ready(), true);
        eq('for_display ready (tầng mạng)', TGS_Acc_Config::for_display()['ready'], true);
        break;

    case 'S2': // blog 5 có khoá riêng; blog 7 không; tầng mạng (site 0)
        net(['url' => 'https://kt.vd', 'key' => 'kt_chung']);
        blog(5, ['key' => 'kt_rieng5']);
        at(5);
        eq('key() blog 5 = khoá riêng', TGS_Acc_Config::key(), 'kt_rieng5');
        eq('key_for(5)', TGS_Acc_Config::key_for(5), 'kt_rieng5');
        eq('key_source_for(5) = site', TGS_Acc_Config::key_source_for(5), 'site');
        at(7);
        eq('key() blog 7 = khoá chung (chuyển tiếp)', TGS_Acc_Config::key(), 'kt_chung');
        eq('key_for(7) rỗng', TGS_Acc_Config::key_for(7), '');
        at(1, true);
        eq('Network Admin: current_blog() = 0', TGS_Acc_Config::current_blog(), 0);
        eq('Network Admin: key() = khoá chung, không lấy khoá blog', TGS_Acc_Config::key(), 'kt_chung');
        eq('key_for(0) luôn rỗng (site 0 không có tenant)', TGS_Acc_Config::key_for(0), '');
        eq('for_display() hiện khoá tầng mạng', TGS_Acc_Config::for_display()['key_masked'], TGS_Acc_Config::mask('kt_chung'));
        break;

    case 'S3': // hằng TGS_KETOAN_API_KEYS thắng option blog; khoá cứng không ghi đè được
        net(['url' => 'https://kt.vd']);
        blog(5, ['key' => 'kt_option5']);
        eq('key_for(5) = khoá trong map', TGS_Acc_Config::key_for(5), 'kt_map5');
        eq('key_source_for(5) = wp-config-map', TGS_Acc_Config::key_source_for(5), 'wp-config-map');
        eq('map: id sai / khoá rỗng bị bỏ', TGS_Acc_Config::key_for(7), '');
        eq('save_blog không ghi đè khoá cứng', TGS_Acc_Config::save_blog(5, ['key' => 'kt_moi']), true);
        eq('… option blog vẫn khoá cũ', get_blog_option(5, 'tgs_acc_bridge_config')['key'], 'kt_option5');
        eq('for_display_blog(5) key_locked', TGS_Acc_Config::for_display_blog(5)['key_locked'], true);
        eq('key_mode(7) = none (không có khoá chung)', TGS_Acc_Config::key_mode(7), 'none');
        break;

    case 'S4': // multisite có TGS_KETOAN_API_KEY: đó là khoá CHUNG, không phải khoá của blog nào
        net(['url' => 'https://kt.vd']);
        at(5);
        eq('key_for(5) rỗng dù có hằng', TGS_Acc_Config::key_for(5), '');
        eq('key_legacy() = hằng', TGS_Acc_Config::key_legacy(), 'kt_const_chung');
        eq('key() blog 5 = hằng (như cũ)', TGS_Acc_Config::key(), 'kt_const_chung');
        eq('key_source_for(5) = legacy', TGS_Acc_Config::key_source_for(5), 'legacy');
        break;

    case 'S5': // localhost một site: hằng là khoá RIÊNG của site
        at(1);
        eq('key_for(1) = hằng', TGS_Acc_Config::key_for(1), 'kt_mot_site');
        eq('key_legacy() rỗng trên máy một site', TGS_Acc_Config::key_legacy(), '');
        eq('key_mode(1) = own', TGS_Acc_Config::key_mode(1), 'own');
        eq('url() = hằng, bỏ "/" cuối', TGS_Acc_Config::url(), 'http://localhost:8088');
        eq('key_source_for(1) = wp-config', TGS_Acc_Config::key_source_for(1), 'wp-config');
        eq('for_display_blog key_locked', TGS_Acc_Config::for_display_blog(1)['key_locked'], true);
        eq('save_blog từ chối trên máy một site', is_wp_error(TGS_Acc_Config::save_blog(1, ['key' => 'x'])), true);
        break;

    case 'S6': // blog đè URL riêng (máy chủ kế toán khác)
        net(['url' => 'https://k1.vd', 'key' => 'kt_chung']);
        blog(5, ['url' => 'https://k2.vd/', 'key' => 'kt_tC']);
        eq('url_for(5) = URL riêng', TGS_Acc_Config::url_for(5), 'https://k2.vd');
        eq('url_source_for(5) = site', TGS_Acc_Config::url_source_for(5), 'site');
        eq('url_for(7) = URL mạng', TGS_Acc_Config::url_for(7), 'https://k1.vd');
        eq('url_for(0) = URL mạng', TGS_Acc_Config::url_for(0), 'https://k1.vd');
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' S6b 2>&1', $out, $c);
        echo implode("\n", $out), "\n";
        if ($c !== 0) {
            $fails++;
        }
        break;

    case 'S6b': // hằng URL khoá cứng, thắng URL riêng của blog
        blog(5, ['url' => 'https://k2.vd']);
        eq('hằng TGS_KETOAN_API_URL thắng URL blog', TGS_Acc_Config::url_for(5), 'https://khoa-cung.vd');
        eq('url_source_for(5) = wp-config', TGS_Acc_Config::url_source_for(5), 'wp-config');
        break;

    case 'S7': // save_blog: kiểm đầu vào, "-" xoá, trống giữ
        net(['url' => 'https://kt.vd']);
        eq('URL sai bị từ chối', is_wp_error(TGS_Acc_Config::save_blog(5, ['url' => 'ftp://x'])), true);
        eq('blog không tồn tại bị từ chối', is_wp_error(TGS_Acc_Config::save_blog(99, ['key' => 'x'])), true);
        TGS_Acc_Config::save_blog(5, ['key' => 'kt_a', 'url' => 'https://k2.vd/', 'timeout' => '5']);
        eq('lưu khoá', TGS_Acc_Config::key_for(5), 'kt_a');
        eq('timeout kẹp về 10', get_blog_option(5, 'tgs_acc_bridge_config')['timeout'], '10');
        TGS_Acc_Config::save_blog(5, ['key' => '']);
        eq('ô khoá trống = giữ khoá cũ', TGS_Acc_Config::key_for(5), 'kt_a');
        TGS_Acc_Config::save_blog(5, ['key' => '-', 'url' => '']);
        eq('"-" = xoá khoá', TGS_Acc_Config::key_for(5), '');
        eq('URL trống = dùng URL mạng', TGS_Acc_Config::url_for(5), 'https://kt.vd');
        TGS_Acc_Config::save_blog(5, ['secret_la' => 'x', 'sso_secret' => 'y']);
        eq('không nhận thiết lập ngoài BLOG_FIELDS', array_keys(get_blog_option(5, 'tgs_acc_bridge_config')), ['timeout']);
        break;

    case 'S8': // tầng mạng không đọc thiết lập của blog 1, get() theo blog
        net(['url' => 'https://kt.vd', 'timeout' => 60]);
        blog(1, ['url' => 'https://rieng-blog1.vd', 'timeout' => '30']);
        at(1, true);
        eq('Network Admin: url() = URL mạng, không lấy URL blog 1', TGS_Acc_Config::url(), 'https://kt.vd');
        eq('Network Admin: get(timeout) = mạng', (int) TGS_Acc_Config::get('timeout'), 60);
        at(1, false);
        eq('blog 1 (site chính) là blog thường: url() = URL riêng', TGS_Acc_Config::url(), 'https://rieng-blog1.vd');
        eq('blog 1: get(timeout) = 30', (int) TGS_Acc_Config::get('timeout'), 30);
        break;

    default:
        echo "  không có kịch bản $s\n";
        exit(2);
}
exit($fails === 0 ? 0 : 1);
