<?php
/**
 * Kiểm S002-13 (tạm dừng kết nối theo shop, gửi bù khi bỏ tạm dừng), S002-11 (`wp tgs-acc status`) và S002-8
 * (`wp tgs-acc preflight`) không cần WordPress. Tệp sự kiện ghi vào một thư mục tạm.
 *
 *   php tests/pause-cli-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-8, S002-11, S002-13 — test tạm dừng / hàng chờ / status / preflight.

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('TGS_ACC_BRIDGE_PATH', dirname(__DIR__) . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tgs-acc-test-' . getmypid());

$GLOBALS['NET'] = ['tgs_acc_bridge_config' => ['url' => 'https://kt.thu', 'key' => 'kt_thu']];
$GLOBALS['BLOG'] = [];
$GLOBALS['NOW'] = date('Y-m-d H:i:s'); // đồng hồ thật: retry_state() đọc 7 ngày theo time()
function apply_filters($tag, $v, ...$a) { return $v; }
function is_multisite() { return true; }
function is_network_admin() { return false; }
function get_current_blog_id() { return 1; }
function get_site($id) { return (object) ['blog_id' => $id]; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function delete_site_option($k) { unset($GLOBALS['NET'][$k]); return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
function delete_blog_option($b, $k) { unset($GLOBALS['BLOG'][(int) $b][$k]); return true; }
function get_option($k, $d = false) { return $d; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_time($t) { return $GLOBALS['NOW']; }
function wp_date($f, $ts = null) { return date($f, $ts ?? strtotime($GLOBALS['NOW'])); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function get_current_user_id() { return 7; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function get_userdata($id) { return null; }
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } function get_error_data() { return null; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_REST_Request { private $p; function __construct(array $p) { $this->p = $p; } function get_param($k) { return $this->p[$k] ?? null; } }

/** $wpdb giả: trả dòng theo nội dung câu SQL. */
class Fake_Wpdb
{
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $users = 'wp_users';
    public $db = [];
    public function get_blog_prefix($b) { return 'wp_' . (int) $b . '_'; }
    public function suppress_errors($on = true) { return false; }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$a)
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $sql);
    }
    public function get_var($q)
    {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $q, $m)) { return $m[1]; }
        if (stripos($q, 'SHOW COLUMNS') !== false) { return 'local_ledger_source_id'; }
        if (strpos($q, "status = 'pending' AND pushed_at <") !== false) { return $this->db['old_pending'] ?? 0; }
        if (strpos($q, 'MAX(pushed_at)') !== false) { return $this->db['last'] ?? null; }
        return null;
    }
    public function get_col($q)
    {
        if (strpos($q, 'local_ledger_approver_status = 1') !== false) { return $this->db['approved'] ?? []; }
        return [];
    }
    public function get_results($q, $o = null)
    {
        if (strpos($q, 'GROUP BY status') !== false) { return $this->db['by_status'] ?? []; }
        if (strpos($q, 'local_ledger_type AS type, local_ledger_parent_id AS parent_id') !== false && strpos($q, 'FROM wp_2_local_ledger WHERE local_ledger_id IN') !== false) {
            return $this->db['refs'] ?? [];
        }
        if (strpos($q, 'd.local_ledger_type = 10') !== false) { return $this->db['sales'] ?? []; }
        if (strpos($q, 'ch.local_ledger_parent_id IN') !== false) { return $this->db['sale_items'] ?? []; }
        return [];
    }
    public function query($q) { return 1; }
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

/** Client giả cho preflight: ghi lại thân request. */
class Fake_Client
{
    public $bodies = [];
    public function ready() { return true; }
    public function post_json($path, array $body)
    {
        $this->bodies[] = [$path, $body];
        return ['findings' => [['code' => 'IN-MD-004', 'severity' => 'hint', 'message' => 'Mã hàng chưa có']], 'score' => 95, 'l5' => 'ran'];
    }
}

foreach (['config', 'api-client', 'source', 'source-pos', 'mapper', 'push-log', 'pusher', 'gate', 'events', 'warehouse-sync', 'cli'] as $c) {
    require_once TGS_ACC_BRIDGE_PATH . "includes/class-tgs-acc-{$c}.php";
}

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
function retry_rows() { return TGS_Acc_Events::read(TGS_Acc_Events::file(wp_date('Y-m-d'), 'retry')); }

// ── P1 tạm dừng theo shop ───────────────────────────────────────────────────
echo "P1 tạm dừng / bỏ tạm dừng\n";
eq('mặc định không tạm dừng', TGS_Acc_Events::paused(2), false);
TGS_Acc_Events::pause(2);
eq('tạm dừng blog 2: ghi thời điểm', [TGS_Acc_Events::paused(2), TGS_Acc_Events::paused_since(2)], [true, $GLOBALS['NOW']]);
eq('blog 3 không bị ảnh hưởng', TGS_Acc_Events::paused(3), false);
eq('khoá vẫn giữ nguyên khi tạm dừng', TGS_Acc_Config::key_mode(2), 'legacy');

// ── P2 bán khi tạm dừng: ghi sự kiện, xếp hàng chờ, không gửi ──────────────
echo "P2 phiếu ghi trong lúc tạm dừng\n";
TGS_Acc_Events::add('ledger', 1001, 2, 'insert');
TGS_Acc_Events::add('ledger', 1002, 2, 'insert');
TGS_Acc_Events::flush();
$rows = retry_rows();
eq('một nhóm vào hàng chờ, đánh dấu paused, chưa tính lần thử', [count($rows), $rows[0]['paused'] ?? 0, $rows[0]['n'] ?? -1, $rows[0]['ledger'] ?? []], [1, 1, 0, [1001, 1002]]);
$ev = TGS_Acc_Events::read(TGS_Acc_Events::file(wp_date('Y-m-d')));
eq('sự kiện vẫn ghi đủ (2 phiếu), không có dòng kết quả gửi', [count(array_filter($ev, function ($r) { return isset($r['kind']); })), count(array_filter($ev, function ($r) { return isset($r['res']); }))], [2, 0]);
$q = TGS_Acc_Events::queue_of(2);
eq('hàng chờ của blog 2: 1 nhóm, đang tạm dừng', [$q['groups'], $q['paused']], [1, true]);
$r = TGS_Acc_Events::retry(true);
eq('cron gửi lại bỏ qua shop tạm dừng (kể cả ép gửi)', $r['retried'], 0);

// ── P3 cổng chờ không trả shop đang tạm dừng ────────────────────────────────
echo "P3 cổng chờ /pending\n";
$m = new ReflectionMethod('TGS_Acc_Gate', 'blogs');
$m->setAccessible(true);
eq('?blogs=2,3: chỉ trả blog 3', $m->invoke(null, new WP_REST_Request(['blogs' => '2,3'])), [3]);

// ── P4 bỏ tạm dừng: gửi bù ─────────────────────────────────────────────────
echo "P4 bỏ tạm dừng\n";
TGS_Acc_Events::resume(2);
eq('bỏ tạm dừng', TGS_Acc_Events::paused(2), false);
$r = TGS_Acc_Events::retry(true);
eq('cron gửi lại xử lý nhóm đang chờ của blog 2', $r['retried'], 1);
$rows = retry_rows();
$last = end($rows);
eq('lần thử đầu tính là 1 (không bị trừ do thời gian tạm dừng)', $last['n'] ?? null, 1);

// ── P5 năm số của lệnh status ───────────────────────────────────────────────
echo "P5 wp tgs-acc status\n";
$GLOBALS['wpdb']->db['by_status'] = [['status' => 'posted', 'n' => 12], ['status' => 'pending', 'n' => 3], ['status' => 'error', 'n' => 1], ['status' => 'remote_error', 'n' => 2]];
$GLOBALS['wpdb']->db['old_pending'] = 1;
$GLOBALS['wpdb']->db['last'] = '2026-11-23 09:58:00';
$GLOBALS['BLOG'][2]['tgs_acc_branch_code'] = 'CN01';
$GLOBALS['BLOG'][2]['tgs_acc_sale_source'] = 'pos';
$d = TGS_Acc_Cli::status_data(2);
eq('năm số: hàng chờ · chờ định khoản · chờ > 24 giờ · lỗi · lần gửi cuối', [$d['queue'], $d['pending'], $d['pending_over_24h'], $d['errors'], $d['last_pushed']], [1, 3, 1, 3, '2026-11-23 09:58:00']);
eq('bối cảnh: nguồn pos, chi nhánh CN01, không tạm dừng', [$d['sale_source'], $d['branch_code'], $d['paused']], ['pos', 'CN01', false]);
eq('cảnh báo chờ > 24 giờ và định khoản lỗi', count($d['warnings']), 2);
TGS_Acc_Events::pause(2);
$saved_now = $GLOBALS['NOW'];
$GLOBALS['NOW'] = date('Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS);
$d = TGS_Acc_Cli::status_data(2);
eq('tạm dừng quá 7 ngày: có cảnh báo kéo bù ở ACC14', (bool) array_filter($d['warnings'], function ($w) { return strpos($w, 'quá 7 ngày') !== false; }), true);
TGS_Acc_Events::resume(2);
$GLOBALS['NOW'] = $saved_now;
// Danh mục kho đã đồng bộ trước (TGS_Acc_Warehouse_Sync dùng bản nhớ, không gọi mạng).
$GLOBALS['NET']['tgs_acc_warehouse_map'] = ['at' => time(), 'branches' => ['CN01' => []]];

// ── P6 preflight một phiếu ──────────────────────────────────────────────────
echo "P6 wp tgs-acc preflight\n";
$GLOBALS['wpdb']->db['refs'] = [['id' => 101, 'type' => 10, 'parent_id' => null, 'day' => '2026-11-23']];
$GLOBALS['wpdb']->db['approved'] = [101];
$GLOBALS['wpdb']->db['sales'] = [['sale_id' => 101, 'parent_id' => null, 'parent_type' => null, 'so_phieu_xuat' => '02011AA00012', 'ngay_xuat' => '2026-11-23', 'ghi_chu' => '', 'user_id' => 7, 'ma_kh' => '', 'ten_kh' => '', 'nv_ten' => '']];
$GLOBALS['wpdb']->db['sale_items'] = [['doc_id' => 101, 'item_id' => 5001, 'sku' => '101010001', 'name' => 'Hàng A', 'base_unit' => 'Cái', 'quantity' => 1, 'price' => 10000, 'unit_qty' => 1, 'unit_ratio' => 1, 'unit' => 'Cái', 'discount' => 0, 'tax_percent' => 10, 'is_kct' => 0, 'tax_amount' => 1000, 'gift_type' => '', 'zone' => '', 'lot_code' => '', 'exp_date' => null, 'note' => '']];
$c = new Fake_Client();
$p = TGS_Acc_Cli::preflight_data(2, 101, $c, true);
$sale = array_values(array_filter($p['results'], function ($r) { return $r['docType'] === 'sale'; }));
eq('dựng được chứng từ sale của phiếu 101', $sale[0]['docKey'] ?? null, 'btsoft:2:sale:101');
eq('gọi đúng /api/v1/intake/preflight, không lưu, có định khoản thử', [$c->bodies[0][0], $c->bodies[0][1]['save'], $c->bodies[0][1]['post']], ['/api/v1/intake/preflight', false, true]);
eq('chứng từ gửi đi đúng khuôn: branchCode CN01, version 1, 11.000đ', [$c->bodies[0][1]['document']['branchCode'], $c->bodies[0][1]['document']['version'], $c->bodies[0][1]['document']['totals']['total']], ['CN01', 1, 11000.0]);
eq('trả phát hiện của kế toán', [$sale[0]['findings'][0]['code'] ?? '', $sale[0]['score'], $sale[0]['l5']], ['IN-MD-004', 95, 'ran']);

// dọn thư mục tạm
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir(WP_CONTENT_DIR);

echo $fails === 0 ? "\nKẾT QUẢ: ĐẠT\n" : "\nKẾT QUẢ: KHÔNG ĐẠT ($fails phép kiểm)\n";
exit($fails === 0 ? 0 : 1);
