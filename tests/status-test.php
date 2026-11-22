<?php
/**
 * Kiểm S002-9 (giữ 'pending' riêng), S002-10 (kéo trạng thái về) và S002-12 (client theo blog) không cần WordPress.
 *
 *   php tests/status-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9, S002-10, S002-12 — test trạng thái gửi + client theo blog.

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('TGS_ACC_BRIDGE_PATH', dirname(__DIR__) . '/');

$GLOBALS['NET'] = [];
$GLOBALS['BLOG'] = [];
$GLOBALS['CUR'] = 1;
function apply_filters($tag, $v, ...$a) { return $v; }
function is_multisite() { return true; }
function is_network_admin() { return false; }
function get_current_blog_id() { return $GLOBALS['CUR']; }
function get_site($id) { return (object) ['blog_id' => $id]; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function delete_site_option($k) { unset($GLOBALS['NET'][$k]); return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function get_option($k, $d = false) { return $d; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_time($t) { return '2026-11-23 10:00:00'; }
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }

/** $wpdb giả: nhật ký gửi trong bộ nhớ ($log: doc_key => dòng); ghi lại tham số mọi câu prepare. */
class Fake_Wpdb
{
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $log = [];
    public $writes = [];
    private $args = [];
    public function get_blog_prefix($b) { return 'wp_' . (int) $b . '_'; }
    public function suppress_errors($on = true) { return false; }
    public function prepare($sql, ...$a)
    {
        $this->args[] = $a;
        return 'PREP#' . (count($this->args) - 1) . '#' . $sql;
    }
    private function argsOf($q)
    {
        return preg_match('/^PREP#(\d+)#/', $q, $m) ? $this->args[(int) $m[1]] : [];
    }
    public function query($q)
    {
        $a = $this->argsOf($q);
        if (strpos($q, 'INSERT INTO') !== false) {
            // save(): blog, docKey, docType, docNo, docDate, version, hash, status, message, time
            $this->log[$a[1]] = ['doc_key' => $a[1], 'version' => $a[5], 'content_hash' => $a[6], 'status' => $a[7], 'message' => $a[8], 'blog_id' => $a[0]];
            $this->writes[] = ['save', $a[1], $a[7]];
            return 1;
        }
        if (strpos($q, 'UPDATE') !== false && strpos($q, "status = 'pending'") !== false) {
            // set_status(): status, message, time, doc_key, version — chỉ đổi dòng đúng phiên bản đang chờ
            $row = $this->log[$a[3]] ?? null;
            if ($row && (int) $row['version'] === (int) $a[4] && $row['status'] === 'pending') {
                $this->log[$a[3]]['status'] = $a[0];
                $this->log[$a[3]]['message'] = $a[1];
                $this->writes[] = ['set', $a[3], $a[0]];
                return 1;
            }
            return 0;
        }
        return 0;
    }
    public function get_results($q, $o = null)
    {
        $a = $this->argsOf($q);
        if (strpos($q, "status = 'pending' ORDER BY") !== false) {
            return array_values(array_filter($this->log, function ($r) use ($a) { return (int) $r['blog_id'] === (int) $a[0] && $r['status'] === 'pending'; }));
        }
        // find_many: mọi dòng nhật ký (lọc khoá ở phía PHP là đủ cho test)
        return array_values($this->log);
    }
    public function get_col($q) { return []; }
    public function get_var($q) { return null; }
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

/** Client giả: kết quả gửi theo docKey + trạng thái phía kế toán cho lần kéo. */
class Fake_Client
{
    public $send_status = [];
    public $remote = [];
    public $sent = [];
    public function get($path, $q = [])
    {
        if ($path === '/api/v1/branches') {
            return ['data' => [['code' => 'CN01']], 'meta' => []];
        }
        $k = $q['search'] ?? '';
        return ['data' => isset($this->remote[$k]) ? [$this->remote[$k] + ['docKey' => $k]] : [], 'meta' => []];
    }
    public function ensure_branch($c, $n, $r = '') { return true; }
    public function send_batch(array $docs)
    {
        $this->sent = array_merge($this->sent, array_column($docs, 'docKey'));
        return ['results' => array_map(function ($d) { return ['docKey' => $d['docKey'], 'status' => $this->send_status[$d['docKey']] ?? 'error', 'error' => ['message' => 'Lỗi thử']]; }, $docs), 'summary' => []];
    }
}

// [2026-11-23 02:20 AM] OKT-TOPO-2 — lớp bao kiểu cài thật; test này giả multisite (get_blog_option giả).
if (!function_exists('is_multisite')) { function is_multisite() { return true; } }
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-site.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-config.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-api-client.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-source.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-push-log.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-pusher.php';

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
function doc($key, $no = 'A1') { return ['docType' => 'sale', 'docKey' => $key, 'branchCode' => 'CN01', 'docNo' => $no, 'docDate' => '2026-11-23', 'lines' => [], 'totals' => ['total' => 1]]; }
$GLOBALS['BLOG'][2]['tgs_acc_branch_code'] = 'CN01';
$db = $GLOBALS['wpdb'];

// ── S1 ghi kết quả gửi (S002-9) ─────────────────────────────────────────────
echo "S1 kết quả gửi → nhật ký gửi\n";
$c = new Fake_Client();
$c->send_status = ['k:pending' => 'pending', 'k:posted' => 'posted', 'k:unch' => 'unchanged', 'k:err' => 'error'];
$s = TGS_Acc_Pusher::send(2, [doc('k:pending'), doc('k:posted'), doc('k:unch'), doc('k:err')], ['client' => $c]);
eq('kế toán trả pending → nhật ký pending (trước đây ghi nhầm posted)', $db->log['k:pending']['status'], 'pending');
eq('kế toán trả posted → posted', $db->log['k:posted']['status'], 'posted');
eq('kế toán trả unchanged → pending (chờ kéo trạng thái xác định)', $db->log['k:unch']['status'], 'pending');
eq('lỗi → error, không lưu mã băm (lần sau gửi lại)', [$db->log['k:err']['status'], $db->log['k:err']['content_hash']], ['error', '']);
eq('pending có lưu mã băm', strlen($db->log['k:pending']['content_hash']), 32);
eq('tổng kết đếm đúng', [$s['pending'], $s['posted'], $s['unchanged'], $s['error']], [1, 1, 1, 1]);

// ── S2 không gửi lại cùng nội dung ──────────────────────────────────────────
echo "S2 không gửi lại khi kế toán đã có\n";
$c2 = new Fake_Client();
$db->log['k:rerr'] = ['doc_key' => 'k:rerr', 'version' => 1, 'content_hash' => TGS_Acc_Pusher::hash(doc('k:rerr')), 'status' => 'remote_error', 'message' => '', 'blog_id' => 2];
$s2 = TGS_Acc_Pusher::send(2, [doc('k:pending'), doc('k:posted'), doc('k:rerr'), doc('k:err')], ['client' => $c2]);
eq('pending / posted / remote_error cùng nội dung: bỏ qua', $s2['skipped'], 3);
eq('chỉ gửi lại phiếu lỗi', $c2->sent, ['k:err']);
eq('remote_error không bị gửi lại thành "unchanged" rồi ghi nhầm posted', $db->log['k:rerr']['status'], 'remote_error');
eq('is_delivered', [TGS_Acc_Push_Log::is_delivered('pending'), TGS_Acc_Push_Log::is_delivered('error'), TGS_Acc_Push_Log::is_delivered('gate')], [true, false, false]);

// ── S3 kéo trạng thái về (S002-10) ──────────────────────────────────────────
echo "S3 kéo trạng thái từ kế toán\n";
$db->log['k:a'] = ['doc_key' => 'k:a', 'version' => 1, 'content_hash' => 'x', 'status' => 'pending', 'message' => '', 'blog_id' => 2];
$db->log['k:b'] = ['doc_key' => 'k:b', 'version' => 2, 'content_hash' => 'x', 'status' => 'pending', 'message' => '', 'blog_id' => 2];
$db->log['k:c'] = ['doc_key' => 'k:c', 'version' => 1, 'content_hash' => 'x', 'status' => 'pending', 'message' => '', 'blog_id' => 2];
$db->log['k:d'] = ['doc_key' => 'k:d', 'version' => 3, 'content_hash' => 'x', 'status' => 'pending', 'message' => '', 'blog_id' => 2];
$db->log['k:z'] = ['doc_key' => 'k:z', 'version' => 1, 'content_hash' => 'x', 'status' => 'pending', 'message' => '', 'blog_id' => 9];
$c3 = new Fake_Client();
$c3->remote = [
    'k:pending' => ['status' => 'posted', 'version' => 1, 'postedVersion' => 1],
    'k:unch'    => ['status' => 'pending', 'version' => 1],
    'k:a'       => ['status' => 'error', 'version' => 1, 'errorMessage' => 'Thiếu tài khoản doanh thu'],
    'k:b'       => ['status' => 'cancelled', 'version' => 2],
    'k:d'       => ['status' => 'posted', 'version' => 3, 'postedVersion' => 2],
];
$r = TGS_Acc_Pusher::refresh_status(2, $c3);
eq('posted → posted', $db->log['k:pending']['status'], 'posted');
eq('kế toán còn pending → giữ pending', $db->log['k:unch']['status'], 'pending');
eq('kế toán định khoản lỗi → remote_error kèm lời kế toán', [$db->log['k:a']['status'], $db->log['k:a']['message']], ['remote_error', 'Kế toán: Thiếu tài khoản doanh thu']);
eq('kế toán huỷ → cancelled', $db->log['k:b']['status'], 'cancelled');
eq('không tìm thấy ở kế toán → giữ pending', $db->log['k:c']['status'], 'pending');
eq('kế toán mới ghi sổ phiên bản cũ hơn (2 < 3) → giữ pending', $db->log['k:d']['status'], 'pending');
eq('chỉ blog 2 (blog 9 không đụng)', $db->log['k:z']['status'], 'pending');
eq('tổng kết', [$r['posted'], $r['remote_error'], $r['cancelled'], $r['still'], $r['missing']], [1, 1, 1, 2, 1]);

// ── S4 client theo blog (S002-12) ───────────────────────────────────────────
echo "S4 client theo blog\n";
$GLOBALS['NET']['tgs_acc_bridge_config'] = ['url' => 'https://kt.mang', 'key' => 'kt_chung'];
$GLOBALS['BLOG'][5]['tgs_acc_bridge_config'] = ['url' => 'https://kt.shop5', 'key' => 'kt_shop5'];
$GLOBALS['CUR'] = 1; // cron chạy ở site chính
$k = function ($client) { $p = new ReflectionProperty($client, 'key'); $p->setAccessible(true); $b = new ReflectionProperty($client, 'base'); $b->setAccessible(true); return [$b->getValue($client), $p->getValue($client)]; };
eq('blog 5 có khoá riêng: cron ở site chính vẫn dùng URL + khoá của blog 5', $k(TGS_Acc_Api_Client::for_blog(5)), ['https://kt.shop5', 'kt_shop5']);
eq('blog 7 chưa có khoá riêng: khoá chung cũ (như trước)', $k(TGS_Acc_Api_Client::for_blog(7)), ['https://kt.mang', 'kt_chung']);
eq('blog 7, không cho khoá chung: client chưa sẵn sàng', TGS_Acc_Api_Client::for_blog(7, false)->ready(), false);

echo $fails === 0 ? "\nKẾT QUẢ: ĐẠT\n" : "\nKẾT QUẢ: KHÔNG ĐẠT ($fails phép kiểm)\n";
exit($fails === 0 ? 0 : 1);
