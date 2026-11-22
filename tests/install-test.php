<?php
/**
 * OKT-SET-2 / OKT-SET-3 — khoá cài đặt open-pos ở bridge, không cần WordPress (HTTP giả đóng vai open-ketoan ADR-0037):
 * key_mode theo mô hình, header X-OKT-Blog / X-OKT-Install, đệm tách theo blog, đăng ký cửa hàng + mã nối, BLOG_NOT_LINKED →
 * giữ đơn (không tính lần thử), nối xong hết giữ, ping theo nhánh, sơ đồ.
 *
 *   php tests/install-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-23 12:50 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 — luật tối thượng P-0 ý 2 (a): một khoá cài đặt, mỗi cửa hàng một tenant.

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('TGS_ACC_BRIDGE_VERSION', '0.6.0');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tgs-acc-install-' . getmypid());
$GLOBALS['NET'] = ['tgs_acc_bridge_config' => ['url' => 'http://okt.local', 'key' => 'kt_khoa_chung_cu']];
$GLOBALS['BLOG'] = [
    2 => ['blogname' => 'Shop A', 'home' => 'http://a.shop.local'],
    3 => ['blogname' => 'Shop B', 'home' => 'http://b.shop.local'],
    4 => ['blogname' => 'Shop C', 'home' => 'http://c.shop.local'],
    5 => ['blogname' => 'Shop riêng', 'home' => 'http://r.shop.local', 'tgs_acc_bridge_config' => ['key' => 'kt_rieng_5', 'url' => 'http://okt2.local']],
];
$GLOBALS['CUR'] = 1;
$GLOBALS['NETADMIN'] = false;
$GLOBALS['REQ'] = [];
$GLOBALS['SRV'] = ['linked' => [2 => 'shopa'], 'down' => []];
function is_multisite() { return true; }
function is_network_admin() { return $GLOBALS['NETADMIN']; }
function get_current_blog_id() { return $GLOBALS['CUR']; }
function get_main_site_id() { return 1; }
function get_site($id) { return isset($GLOBALS['BLOG'][(int) $id]) || (int) $id === 1 ? (object) ['blog_id' => $id] : null; }
function get_blog_details($id) { return get_site($id); }
function get_sites($a = []) { return [1, 2, 3, 4, 5]; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function delete_site_option($k) { unset($GLOBALS['NET'][$k]); return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
function delete_blog_option($b, $k) { unset($GLOBALS['BLOG'][(int) $b][$k]); return true; }
function get_option($k, $d = false) { return $d; }
function do_action($h, ...$a) {}
function apply_filters($t, $v, ...$a) { return $v; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_date($f, $ts = null) { return date($f, $ts ?? time()); }
function current_time($t) { return date('Y-m-d H:i:s'); }
function get_current_user_id() { return 0; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_doing_cron() { return true; }
class WP_Error {
    public $c; public $m; public $d;
    function __construct($c = '', $m = '', $d = null) { $this->c = $c; $this->m = $m; $this->d = $d; }
    function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }

/** open-ketoan giả: route của bản cài (không X-OKT-Blog), route thường theo (khoá cài đặt, blog), khoá thường. */
function wp_remote_request($url, $args)
{
    $h = $args['headers'];
    $GLOBALS['REQ'][] = ['method' => $args['method'], 'url' => $url, 'headers' => $h, 'body' => isset($args['body']) ? json_decode($args['body'], true) : null];
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);
    if (in_array($host, $GLOBALS['SRV']['down'], true)) {
        return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
    }
    $ok = function ($data) { return ['code' => 200, 'body' => json_encode(['ok' => true, 'data' => $data])]; };
    $err = function ($code, $c, $reason, $extra = []) { return ['code' => $code, 'body' => json_encode(['ok' => false, 'error' => ['code' => $c, 'message' => 'bị từ chối', 'details' => ['reason' => $reason] + $extra]])]; };
    $blogs = function ($withCodes, array $only = []) {
        $out = [];
        foreach ($only ?: [2, 3, 4] as $b) {
            if (!in_array($b, $GLOBALS['SRV']['registered'] ?? [], true)) {
                continue;
            }
            $t = $GLOBALS['SRV']['linked'][$b] ?? null;
            $out[] = ['blogId' => $b, 'status' => $t ? 'active' : 'pending', 'tenantCode' => $t, 'lastPingMs' => $GLOBALS['SRV']['ping'][$b] ?? null, 'stale' => !isset($GLOBALS['SRV']['ping'][$b])]
                + ($withCodes && !$t ? ['linkCode' => 'K' . $b . 'X7M2QP', 'linkCodeExpiresAt' => gmdate('c', time() + 86400)] : []);
        }
        return ['installationCode' => 'inst_01TEST', 'blogs' => $out];
    };
    $key = $h['X-Api-Key'] ?? '';
    if ($path === '/api/v1/system/health') {
        $r = $ok(['status' => 'up', 'time' => gmdate('c'), 'version' => $host === 'okt.local' ? '1.23.0' : '1.22.0']);
    } elseif (strpos($key, 'okti_') === 0) {
        if (isset($h['X-OKT-Install']) && $h['X-OKT-Install'] !== 'inst_01TEST') {
            $r = $err(401, 'UNAUTHORIZED', '');
        } elseif (!isset($h['X-OKT-Blog'])) {
            if ($path === '/api/v1/pos-installation/blogs' && $args['method'] === 'POST') {
                $ids = array_map(function ($b) { return (int) $b['blogId']; }, $GLOBALS['REQ'][count($GLOBALS['REQ']) - 1]['body']['blogs']);
                $GLOBALS['SRV']['registered'] = array_values(array_unique(array_merge($GLOBALS['SRV']['registered'] ?? [], $ids)));
                $r = $ok($blogs(true, $ids));
            } elseif ($path === '/api/v1/pos-installation/blogs') {
                $r = $ok($blogs(false));
            } elseif ($path === '/api/v1/pos-installation/ping') {
                $acc = []; $unk = [];
                foreach (end($GLOBALS['REQ'])['body']['blogs'] as $p) {
                    in_array((int) $p['blogId'], $GLOBALS['SRV']['registered'] ?? [], true) ? $acc[] = $GLOBALS['SRV']['ping'][(int) $p['blogId']] = (int) $p['latencyMs'] : $unk[] = (int) $p['blogId'];
                }
                $r = $ok(['accepted' => count($acc), 'unknown' => $unk] + $blogs(false));
            } else {
                $r = $err(403, 'FORBIDDEN', 'BLOG_REQUIRED');
            }
        } else {
            $b = (int) $h['X-OKT-Blog'];
            if (!in_array($b, $GLOBALS['SRV']['registered'] ?? [], true)) {
                $r = $err(403, 'FORBIDDEN', 'BLOG_UNKNOWN', ['blogId' => $b]);
            } elseif (!isset($GLOBALS['SRV']['linked'][$b])) {
                $r = $err(403, 'FORBIDDEN', 'BLOG_NOT_LINKED', ['blogId' => $b, 'status' => 'pending']);
            } else {
                $r = $ok(['code' => $GLOBALS['SRV']['linked'][$b]]);
            }
        }
    } elseif ($path === '/api/v1/connection/ping') {
        $r = $ok(['blogId' => end($GLOBALS['REQ'])['body']['blogId'], 'tenantCode' => $key === 'kt_rieng_5' ? 'shopr' : 'chung']);
    } else {
        $r = $ok(['code' => 'chung']);
    }
    return ['response' => ['code' => $r['code']], 'body' => $r['body']];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

require_once dirname(__DIR__) . '/includes/class-tgs-acc-site.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-profile.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-config.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-api-client.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-install.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-events.php';

$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; if ($c) { $ok++; echo "  ok  {$n}\n"; } else { $fail++; echo "SAI: {$n}\n"; } }
function last_req() { return end($GLOBALS['REQ']); }
function reqs_to($path) { return array_values(array_filter($GLOBALS['REQ'], function ($r) use ($path) { return parse_url($r['url'], PHP_URL_PATH) === $path; })); }

// 1. key_mode theo mô hình — khoá cài đặt chỉ dùng ở mô hình chain; khoá riêng thắng
TGS_Acc_Profile::set('bizcity');
$GLOBALS['NET']['tgs_acc_bridge_config']['install_key'] = 'okti_khoa_cai_dat_thu';
check('bizcity + có khoá cài đặt: KHÔNG dùng khoá cài đặt (blog 2 vẫn khoá chung cũ)', TGS_Acc_Config::key_mode(2) === 'legacy' && !TGS_Acc_Install::active());
TGS_Acc_Profile::set('chain');
check('chain + khoá cài đặt: blog không có khoá riêng → install', TGS_Acc_Install::active() && TGS_Acc_Config::key_mode(2) === 'install' && TGS_Acc_Config::key_mode(3) === 'install');
check('chain: blog có khoá riêng → own (khoá riêng thắng)', TGS_Acc_Config::key_mode(5) === 'own');
check('chain: tầng mạng (blog 0) không phải install', TGS_Acc_Config::key_mode(0) !== 'install');
check('ready_for tính cả khoá cài đặt', TGS_Acc_Config::ready_for(3) === true);
$e = TGS_Acc_Config::save(['install_key' => 'kt_khong_phai_khoa_cai_dat']);
check('lưu khoá kt_… vào ô khoá cài đặt → lỗi, không ghi', $e instanceof WP_Error && $GLOBALS['NET']['tgs_acc_bridge_config']['install_key'] === 'okti_khoa_cai_dat_thu');
check('lưu khoá cài đặt okti_… được', TGS_Acc_Config::save(['install_key' => 'okti_khoa_cai_dat_moi']) === true && TGS_Acc_Install::key() === 'okti_khoa_cai_dat_moi');

// 1b. ngữ cảnh blog hiện tại (gửi tức thời cuối request): key() / ready() / targets() thấy khoá cài đặt
$GLOBALS['CUR'] = 3;
check('blog 3 hiện tại: Config::key() = khoá cài đặt, ready() đúng, Events::targets() có sổ nội bộ', TGS_Acc_Config::key() === 'okti_khoa_cai_dat_moi' && TGS_Acc_Config::ready() && in_array('', TGS_Acc_Events::targets(), true));
$GLOBALS['CUR'] = 5;
check('blog 5 hiện tại: Config::key() = khoá riêng', TGS_Acc_Config::key() === 'kt_rieng_5');
$GLOBALS['CUR'] = 1;

// 2. client theo blog
$c2 = TGS_Acc_Api_Client::for_blog(2);
$c3 = TGS_Acc_Api_Client::for_blog(3);
$c5 = TGS_Acc_Api_Client::for_blog(5);
$c2->get('/api/v1/tenant');
$h = last_req()['headers'];
check('for_blog(2): X-Api-Key = khoá cài đặt + X-OKT-Blog: 2', $h['X-Api-Key'] === 'okti_khoa_cai_dat_moi' && $h['X-OKT-Blog'] === '2' && strpos(last_req()['url'], 'http://okt.local/') === 0);
check('đệm tách theo blog: identity(2) ≠ identity(3) dù cùng khoá (I-1)', $c2->identity() !== $c3->identity());
$c5->get('/api/v1/tenant');
check('for_blog(5) khoá riêng: không X-OKT-Blog, URL riêng của blog', !isset(last_req()['headers']['X-OKT-Blog']) && last_req()['headers']['X-Api-Key'] === 'kt_rieng_5' && strpos(last_req()['url'], 'http://okt2.local/') === 0);
$GLOBALS['CUR'] = 3;
(new TGS_Acc_Api_Client())->get('/api/v1/accounts');
check('client mặc định trong blog 3 (màn POS, gửi tức thời): khoá cài đặt + X-OKT-Blog: 3', (last_req()['headers']['X-OKT-Blog'] ?? '') === '3');
$GLOBALS['NETADMIN'] = true; $GLOBALS['CUR'] = 1;
(new TGS_Acc_Api_Client())->get('/api/v1/accounts');
check('Network Admin (tầng mạng): không gắn blog nào', !isset(last_req()['headers']['X-OKT-Blog']));
$GLOBALS['NETADMIN'] = false;
TGS_Acc_Api_Client::raw('http://okt.local', '')->health();
check('health: không gửi khoá, không gửi header blog', !isset(last_req()['headers']['X-Api-Key']) && !isset(last_req()['headers']['X-OKT-Blog']));

// 3. blog chưa đăng ký → BLOG_UNKNOWN → ghi 'unknown', giữ đơn
$r = TGS_Acc_Api_Client::for_blog(3)->get('/api/v1/tenant');
check('blog chưa đăng ký: WP_Error lý do BLOG_UNKNOWN, ghi trạng thái unknown', is_wp_error($r) && $r->get_error_data()['reason'] === 'BLOG_UNKNOWN' && TGS_Acc_Install::status(3) === 'unknown' && TGS_Acc_Install::holds(3));

// 4. đăng ký: route của bản cài, không X-OKT-Blog; blog khoá riêng không đăng ký
$s = TGS_Acc_Install::register();
$reg = last_req();
check('đăng ký: POST /pos-installation/blogs không X-OKT-Blog, gửi blog 1–4 đi khoá cài đặt, KHÔNG gửi blog 5 (khoá riêng)', !is_wp_error($s) && !isset($reg['headers']['X-OKT-Blog']) && array_column($reg['body']['blogs'], 'blogId') === [1, 2, 3, 4]);
check('đăng ký: gửi tên + địa chỉ blog', $reg['body']['blogs'][1]['name'] === 'Shop A' && $reg['body']['blogs'][1]['url'] === 'http://a.shop.local');
check('đăng ký: lưu mã bản cài; blog 2 active (tenant shopa), blog 3 pending + mã nối', TGS_Acc_Install::code() === 'inst_01TEST' && TGS_Acc_Install::status(2) === 'active' && TGS_Acc_Install::status(3) === 'pending' && $s['blogs'][3]['link_code'] === 'K3X7M2QP');
check('holds: blog 3 chờ nối → giữ; blog 2 đã nối → không; blog 5 khoá riêng → không', TGS_Acc_Install::holds(3) && !TGS_Acc_Install::holds(2) && !TGS_Acc_Install::holds(5));
TGS_Acc_Api_Client::for_blog(2)->get('/api/v1/tenant');
check('sau khi biết mã bản cài: gửi X-OKT-Install', (last_req()['headers']['X-OKT-Install'] ?? '') === 'inst_01TEST');
TGS_Acc_Install::refresh();
check('đọc lại trạng thái: mã nối của blog đang chờ vẫn giữ để hiện', (TGS_Acc_Install::state()['blogs'][3]['link_code'] ?? '') === 'K3X7M2QP');

// 5. blog chờ nối: 403 BLOG_NOT_LINKED → thông báo "giữ trong hàng chờ"; hàng chờ không tăng lần thử
$r = TGS_Acc_Api_Client::for_blog(3)->post_json('/api/v1/documents/batch', ['documents' => []]);
check('blog chờ: BLOG_NOT_LINKED, thông báo giữ hàng chờ', is_wp_error($r) && $r->get_error_data()['reason'] === 'BLOG_NOT_LINKED' && strpos($r->get_error_message(), 'hàng chờ') !== false && TGS_Acc_Install::status(3) === 'pending');
check('Events::held(3) = true, held(2) = false', TGS_Acc_Events::held(3) && !TGS_Acc_Events::held(2));
$day = wp_date('Y-m-d');
$ref = new ReflectionMethod('TGS_Acc_Events', 'append');
$ref->setAccessible(true);
$ref->invoke(null, $day, [['t' => date('c'), 'rid' => 'r3', 'target' => '', 'blog' => 3, 'ledger' => [11, 12], 'ct' => [], 'gt' => [], 'n' => 2, 'next' => 0, 'hold' => 'link', 'msg' => TGS_Acc_Install::HOLD_MSG]], 'retry');
$n0 = count($GLOBALS['REQ']);
TGS_Acc_Events::retry(true);
$rs = TGS_Acc_Events::retry_state();
check('cron gửi lại: nhóm của blog chờ nối GIỮ NGUYÊN (không gửi, không tăng lần thử, không bỏ)', isset($rs['r3']) && (int) $rs['r3']['n'] === 2 && empty($rs['r3']['closed']) && count($GLOBALS['REQ']) === $n0);
check('hàng chờ blog 3: 1 nhóm, 2 phiếu', TGS_Acc_Install::queue_counts(3) === ['groups' => 1, 'docs' => 2]);

// 6. ping theo nhánh: chain một lượt cho cả bản cài; khoá riêng một lượt bằng khoá của nó
$GLOBALS['REQ'] = [];
$GLOBALS['SRV']['registered'] = [1, 2, 3]; // open-ketoan quên blog 4 (vd thu hồi rồi xoá)
$p = TGS_Acc_Install::ping();
$ip = reqs_to('/api/v1/pos-installation/ping');
$cp = reqs_to('/api/v1/connection/ping');
check('ping: MỘT lượt /pos-installation/ping cho blog 1–4, không X-OKT-Blog', count($ip) === 1 && !isset($ip[0]['headers']['X-OKT-Blog']) && array_column($ip[0]['body']['blogs'], 'blogId') === [1, 2, 3, 4]);
$b3 = array_values(array_filter($ip[0]['body']['blogs'], function ($b) { return $b['blogId'] === 3; }))[0];
check('ping gửi hàng chờ của blog 3 (1 nhóm, 2 phiếu) + phiên bản bridge', $b3['queueGroups'] === 1 && $b3['pendingDocs'] === 2 && $b3['bridgeVersion'] === '0.6.0');
check('ping: blog 5 khoá riêng → /connection/ping bằng khoá của nó, máy riêng', count($cp) === 1 && $cp[0]['headers']['X-Api-Key'] === 'kt_rieng_5' && strpos($cp[0]['url'], 'http://okt2.local/') === 0 && $p['blogs'][5]['tenant'] === 'shopr');
check('ping: health mỗi máy một lần (2 máy)', count(reqs_to('/api/v1/system/health')) === 2 && $p['hosts']['http://okt.local']['version'] === '1.23.0' && $p['hosts']['http://okt2.local']['ok']);
check('ping: blog 2 xanh, blog 3 chờ (nêu mã nối), blog 4 open-ketoan chưa biết (chỉ Đăng ký)', $p['blogs'][2]['ok'] && !$p['blogs'][3]['ok'] && strpos($p['blogs'][3]['msg'], 'mã nối') !== false && strpos($p['blogs'][4]['msg'], 'Đăng ký') !== false);

// 7. kế toán nhập mã nối ở sổ B → bridge đọc lại → hết giữ, mã nối bỏ
$GLOBALS['SRV']['linked'][3] = 'shopb';
TGS_Acc_Install::refresh();
check('nối xong: blog 3 active, tenant shopb, không giữ nữa, bỏ mã nối', TGS_Acc_Install::status(3) === 'active' && !TGS_Acc_Install::holds(3) && empty(TGS_Acc_Install::state()['blogs'][3]['link_code']) && TGS_Acc_Install::state()['blogs'][3]['tenant'] === 'shopb');
TGS_Acc_Install::mark(2, 'pending');
TGS_Acc_Api_Client::for_blog(2)->get('/api/v1/tenant');
check('gọi được bằng (khoá cài đặt, blog) → tự ghi active', TGS_Acc_Install::status(2) === 'active');

// 8. sơ đồ
$t = TGS_Acc_Install::topology();
$byb = array_column($t['blogs'], null, 'blog');
check('sơ đồ: mô hình chain, 5 site, 2 máy; máy chính 3 tenant (shopa, shopb, + blog 1 chưa) / 4 site', $t['profile'] === 'chain' && $t['site_count'] === 5 && count($t['hosts']) === 2);
check('sơ đồ: blog 3 tenant shopb, khoá cài đặt, đã nối; blog 5 khoá riêng', $byb[3]['tenant'] === 'shopb' && $byb[3]['key_mode'] === 'install' && $byb[3]['link'] === 'active' && $byb[5]['key_mode'] === 'own' && $byb[5]['link'] === '');
check('sơ đồ: khoá cài đặt chỉ hiện dạng che', $t['install']['key_masked'] !== '' && strpos(json_encode($t), 'okti_khoa_cai_dat_moi') === false);

// 9. máy open-ketoan không trả lời → không ping các blog trỏ máy đó
$GLOBALS['SRV']['down'] = ['okt.local'];
$GLOBALS['REQ'] = [];
$p = TGS_Acc_Install::ping();
check('máy chính sập: không gọi /pos-installation/ping, blog 2 đỏ "không trả lời"; máy riêng vẫn ping', count(reqs_to('/api/v1/pos-installation/ping')) === 0 && !$p['blogs'][2]['ok'] && strpos($p['blogs'][2]['msg'], 'không trả lời') !== false && $p['blogs'][5]['ok']);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
