<?php
/**
 * Kiểm OKT-TRACE (TGS_Acc_Trace) không cần WordPress: sổ / dựng / nhật ký giả; tệp sự kiện thật trong thư mục tạm.
 *
 *   php tests/trace-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 03:10 PM Johnny Chu - Chu Hoàng Anh] OKT-TRACE — test trace chiều bán.

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tgs-acc-trace-' . getmypid());
$GLOBALS['NET'] = [];
$GLOBALS['BLOG'] = [];
function apply_filters($t, $v, ...$a) { return $v; }
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
function delete_blog_option($b, $k) { unset($GLOBALS['BLOG'][(int) $b][$k]); return true; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_time($t) { return date('Y-m-d H:i:s'); }
function wp_date($f, $ts = null) { return date($f, $ts ?? time()); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
require dirname(__DIR__) . '/includes/class-tgs-acc-events.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-trace.php';

$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "SAI: {$n}\n"; } }
function step(array $r, $key) { foreach ($r['steps'] as $s) { if ($s['key'] === $key) { return $s; } } return null; }

// 1. Tách mã
check('docKey', TGS_Acc_Trace::parse(0, 'btsoft:2:sale:123') === ['blog' => 2, 'id' => 123, 'docKey' => 'btsoft:2:sale:123']);
check('docKey có gạch', TGS_Acc_Trace::parse(0, 'btsoft:5:supplier-return:9')['id'] === 9);
check('id + blog', TGS_Acc_Trace::parse(2, '#123') === ['blog' => 2, 'id' => 123, 'docKey' => '']);
check('id thiếu blog → null', TGS_Acc_Trace::parse(0, '123') === null);
check('rác → lỗi', is_wp_error(TGS_Acc_Trace::run(2, 'abc')));

// Dữ liệu dựng sẵn: phiếu bán 10 (#100) + xuất 2 (#101) + thu 7 (#102)
$L = [
    ['id' => 100, 'type' => 10, 'parent_id' => 0, 'code' => 'BH001', 'day' => '2026-11-22', 'approver_status' => 1, 'total' => 11000, 'is_deleted' => 0, 'updated_at' => ''],
    ['id' => 101, 'type' => 2, 'parent_id' => 100, 'code' => 'XK001', 'day' => '2026-11-22', 'approver_status' => 1, 'total' => 11000, 'is_deleted' => 0, 'updated_at' => ''],
    ['id' => 102, 'type' => 7, 'parent_id' => 100, 'code' => 'PT001', 'day' => '2026-11-22', 'approver_status' => 1, 'total' => 11000, 'is_deleted' => 0, 'updated_at' => ''],
];
$sale = ['docType' => 'sale', 'docKey' => 'btsoft:2:sale:100', 'docNo' => 'BH001', 'docDate' => '2026-11-22', 'branchCode' => 'CN01', 'reasonCode' => 'XTA', 'lines' => [['taxCode' => 'R10']], 'totals' => ['total' => 11000]];
$pay = ['docType' => 'customer_payment', 'docKey' => 'btsoft:2:receipt:102', 'docNo' => 'PT001', 'docDate' => '2026-11-22', 'branchCode' => 'CN01', 'methodCode' => 'QR-VTB-CT', 'lines' => [['amount' => 11000]], 'totals' => ['total' => 11000]];
$map = function ($d) { if (isset($d['methodCode']) && $d['methodCode'] === 'QR-VTB-CT') { $d['methodCode'] = 'QR'; } if (($d['reasonCode'] ?? '') === 'XTA') { $d['reasonCode'] = 'XTA1.4'; } return $d; };
$build = function ($b, $ids, $raw) use ($sale, $pay, $map) { $docs = [$sale, $pay]; return ['docs' => $raw ? $docs : array_map($map, $docs)]; };
$hash = function ($d) { return md5(json_encode($d)); };
class Remote { public $rows; function __construct($r) { $this->rows = $r; } function get($p, array $q = []) { return ['data' => array_values(array_filter($this->rows, function ($x) use ($q) { return $x['docKey'] === $q['search']; }))]; } }
function deps($over = []) {
    global $L, $build, $sale, $pay, $map, $hash;
    return $over + [
        'ledger' => function () use ($L) { return $L; },
        'events' => function () { return [['t' => '2026-11-22 09:00:00', 'id' => 'e1', 'kind' => 'ledger', 'ref' => 100, 'op' => 'insert', 'src' => 'pos', 'result' => ['res' => 'ok']]]; },
        'queue'  => function () { return []; },
        'build'  => $build,
        'log'    => function ($keys) use ($sale, $pay, $map, $hash) { return ['btsoft:2:sale:100' => ['status' => 'pending', 'version' => 1, 'content_hash' => $hash($map($sale)), 'pushed_at' => '2026-11-22 09:00:05', 'message' => 'ERP đã nhận'], 'btsoft:2:receipt:102' => ['status' => 'posted', 'version' => 1, 'content_hash' => $hash($map($pay)), 'pushed_at' => '2026-11-22 09:00:05', 'message' => '']]; },
        'client' => new Remote([['docKey' => 'btsoft:2:sale:100', 'status' => 'pending', 'postedVersion' => null], ['docKey' => 'btsoft:2:receipt:102', 'status' => 'posted', 'postedVersion' => 1]]),
    ];
}
// TGS_Acc_Pusher::hash chưa nạp trong test → doc_summary dùng md5(json) — cùng hàm $hash ở trên.

// 2. Đường đi đầy đủ
$r = TGS_Acc_Trace::run(2, 'btsoft:2:sale:100', ['remote' => true], deps());
check('6 chặng đúng thứ tự', array_column($r['steps'], 'key') === ['ledger', 'events', 'queue', 'build', 'log', 'remote']);
check('sổ: phiếu bán đã duyệt, 3 phiếu liên quan', step($r, 'ledger')['level'] === 'pass' && strpos(step($r, 'ledger')['message'], '3 phiếu liên quan') !== false && strpos(step($r, 'ledger')['message'], 'phiếu bán BH001') !== false);
check('sự kiện: 1, gửi được', step($r, 'events')['level'] === 'pass' && strpos(step($r, 'events')['message'], 'gửi được') !== false);
check('hàng chờ: không', step($r, 'queue')['level'] === 'pass');
$docs = step($r, 'build')['data']['docs'];
check('dựng: 2 chứng từ, 2 mã được dịch', count($docs) === 2 && strpos(step($r, 'build')['message'], '2 mã được dịch') !== false);
check('dịch: reasonCode XTA → XTA1.4', $docs[0]['mapped'] === [['field' => 'reasonCode', 'from' => 'XTA', 'to' => 'XTA1.4']]);
check('dịch: methodCode QR-VTB-CT → QR', $docs[1]['mapped'] === [['field' => 'methodCode', 'from' => 'QR-VTB-CT', 'to' => 'QR']]);
check('nhật ký: khớp mã băm, pending → cần xem', step($r, 'log')['level'] === 'warn' && strpos(step($r, 'log')['message'], 'không gửi lại') !== false);
check('kế toán: pending + posted, không lỗi', step($r, 'remote')['level'] === 'pass' && strpos(step($r, 'remote')['message'], 'btsoft:2:receipt:102: posted') !== false);
check('docKey trả về', $r['docKey'] === 'btsoft:2:sale:100' && $r['id'] === 100);

// 3. Nội dung đổi → lên phiên bản; remote_error → đỏ
$r = TGS_Acc_Trace::run(2, 'btsoft:2:sale:100', [], deps(['log' => function () { return ['btsoft:2:sale:100' => ['status' => 'remote_error', 'version' => 3, 'content_hash' => 'cu', 'pushed_at' => 'x', 'message' => 'Mã thuế R04 không có']]; }]));
check('remote_error → đỏ', step($r, 'log')['level'] === 'fail');
check('đổi nội dung → lên v4', strpos(step($r, 'log')['message'], 'lên v4') !== false);
check('phiếu thu chưa gửi lần nào', strpos(step($r, 'log')['message'], 'btsoft:2:receipt:102: — (chưa gửi lần nào)') !== false);
check('không hỏi kế toán → chặng 6 none', step($r, 'remote')['level'] === 'none');

// 4. Không thấy phiếu / chưa duyệt / đang trong hàng chờ / kế toán chưa có
$r = TGS_Acc_Trace::run(2, '999', [], deps(['ledger' => function () { return []; }, 'build' => function () { return ['docs' => []]; }, 'log' => function () { return []; }]));
check('không thấy phiếu → đỏ', step($r, 'ledger')['level'] === 'fail');
check('không dựng được → vàng', step($r, 'build')['level'] === 'warn');
$L2 = $L; $L2[0]['approver_status'] = 0;
$r = TGS_Acc_Trace::run(2, '100', [], deps(['ledger' => function () use ($L2) { return $L2; }]));
check('chưa duyệt → vàng', step($r, 'ledger')['level'] === 'warn' && strpos(step($r, 'ledger')['message'], 'CHƯA duyệt') !== false);
$r = TGS_Acc_Trace::run(2, '100', [], deps(['queue' => function () { return [['rid' => 'g1', 'n' => 3, 'msg' => 'Hết giờ', 'paused' => 1]]; }]));
check('trong hàng chờ → vàng, ghi lần thử + tạm dừng', step($r, 'queue')['level'] === 'warn' && strpos(step($r, 'queue')['message'], '(shop tạm dừng)') !== false && strpos(step($r, 'queue')['message'], 'lần thử 3') !== false);
$r = TGS_Acc_Trace::run(2, 'btsoft:2:sale:100', ['remote' => true], deps(['client' => new Remote([])]));
check('kế toán chưa có → vàng', step($r, 'remote')['level'] === 'warn' && strpos(step($r, 'remote')['message'], 'kế toán chưa có') !== false);

// 5. events() đọc tệp thật, nối kết quả theo id; queue() lọc theo blog + phiếu
$f = TGS_Acc_Events::file(date('Y-m-d'));
wp_mkdir_p(dirname($f));
file_put_contents($f, implode("\n", [
    json_encode(['t' => date('Y-m-d') . ' 08:00:00', 'id' => 'a1', 'blog' => 2, 'kind' => 'ledger', 'ref' => 100, 'op' => 'insert', 'src' => 'pos']),
    json_encode(['t' => date('Y-m-d') . ' 08:00:01', 'id' => 'a2', 'blog' => 3, 'kind' => 'ledger', 'ref' => 100, 'op' => 'insert', 'src' => 'pos']),
    json_encode(['t' => date('Y-m-d') . ' 08:00:02', 'id' => 'a3', 'blog' => 2, 'kind' => 'ledger', 'ref' => 555, 'op' => 'update', 'src' => 'admin']),
    json_encode(['t' => date('Y-m-d') . ' 08:00:03', 'res' => 'error', 'target' => 'noi-bo', 'blog' => 2, 'ids' => ['a1'], 'msg' => 'Hết giờ']),
]) . "\n");
$ev = TGS_Acc_Trace::events(2, [100, 101], 1);
check('events: đúng blog + phiếu', count($ev) === 1 && $ev[0]['id'] === 'a1');
check('events: nối kết quả theo id', ($ev[0]['result']['res'] ?? '') === 'error' && $ev[0]['result']['msg'] === 'Hết giờ');
$rf = TGS_Acc_Events::file(date('Y-m-d'), 'retry');
file_put_contents($rf, json_encode(['t' => 'x', 'rid' => 'g1', 'blog' => 2, 'ledger' => [100], 'n' => 1, 'next' => 0]) . "\n" . json_encode(['t' => 'x', 'rid' => 'g2', 'blog' => 3, 'ledger' => [100], 'n' => 1, 'next' => 0]) . "\n");
$q = TGS_Acc_Trace::queue(2, [100]);
check('queue: chỉ nhóm của blog 2 có phiếu 100', count($q) === 1 && $q[0]['rid'] === 'g1');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $x) { $x->isDir() ? @rmdir($x) : @unlink($x); }
@rmdir(WP_CONTENT_DIR);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
