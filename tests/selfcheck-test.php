<?php
/**
 * Kiểm OKT-SC-1 (tự kiểm cặp blog → tenant qua API, luật I-8) không cần WordPress: client API giả, chứng từ dựng sẵn.
 *
 *   php tests/selfcheck-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 11:30 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — test SC-1…SC-11.

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['NET'] = [];
$GLOBALS['BLOG'] = [];
$GLOBALS['KEYMODE'] = [];
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
class TGS_Acc_Config {
    static function key_mode($b) { return $GLOBALS['KEYMODE'][(int) $b] ?? 'none'; }
    static function url_for($b) { return 'http://localhost:8098'; }
}
// [2026-11-23 02:20 AM] OKT-TOPO-2 — lớp bao kiểu cài thật; test này giả multisite (get_blog_option giả).
if (!function_exists('is_multisite')) { function is_multisite() { return true; } }
require_once dirname(__DIR__) . '/includes/class-tgs-acc-site.php';
require dirname(__DIR__) . '/includes/class-tgs-acc-selfcheck.php';

class FakeClient
{
    public $r = [];
    public $calls = [];
    function __construct(array $r) { $this->r = $r; }
    function get($path, array $q = []) { $this->calls[] = 'GET ' . $path; return $this->r[$path] ?? ['data' => []]; }
    function post_json($path, array $b) { $this->calls[] = 'POST ' . $path; $f = $this->r['preflight'] ?? []; return is_callable($f) ? $f($b) : $f; }
}

$NOW = strtotime('2026-11-22 04:00:00 UTC');
function tenant_ok(array $over = [])
{
    global $NOW;
    return $over + [
        '/api/v1/tenant'                  => ['data' => ['code' => 'shopa']],
        '/api/v1/catalog/feed/meta'       => ['data' => ['serverTime' => gmdate('c', $NOW + 10)]],
        '/api/v1/documents'               => ['data' => []],
        '/api/v1/posting/payment-methods' => ['data' => [['code' => 'TM'], ['code' => 'CK'], ['code' => 'QR']]],
        '/api/v1/tax-codes'               => ['data' => [['code' => 'R08'], ['code' => 'R10'], ['code' => 'KCT']]],
        '/api/v1/branches'                => ['data' => [['code' => 'CN01']]],
        '/api/v1/warehouses'              => ['data' => [['code' => 'K01', 'branchCode' => 'CN01', 'isDefault' => true, 'isActive' => true]]],
        '/api/v1/posting/reasons'         => ['data' => [['code' => 'XBA'], ['code' => 'XTA1.4']]],
        'preflight'                       => ['findings' => []],
    ];
}
function sale($id, array $lines, $reason = '', array $payments = [])
{
    return ['docType' => 'sale', 'docKey' => "btsoft:2:sale:{$id}", 'branchCode' => 'CN01', 'reasonCode' => $reason, 'lines' => $lines, 'payments' => $payments];
}
function pay($id, $method)
{
    return ['docType' => 'customer_payment', 'docKey' => "btsoft:2:receipt:{$id}", 'branchCode' => 'CN01', 'methodCode' => $method, 'lines' => [['amount' => 11000]]];
}
$GOOD_DOCS = [sale(10, [['taxCode' => 'R10', 'warehouseCode' => 'K01']]), pay(11, 'TM')];
function deps($client, array $docs, array $status = [], array $queue = [])
{
    global $NOW;
    return [
        'client' => $client, 'now' => $NOW, 'map_ready' => $GLOBALS['MAP_READY'] ?? true, 'branch' => $GLOBALS['BRANCH'] ?? 'CN01',
        'ids'    => function () use ($docs) { return $docs ? [10, 11] : []; },
        'build'  => function () use ($docs) { return ['docs' => $docs, 'failed' => []]; },
        'status' => function () use ($status) { return $status + ['pending_over_24h' => 0, 'errors' => 0, 'paused' => false]; },
        'queue'  => function () use ($queue) { return $queue + ['groups' => 0, 'oldest' => '']; },
    ];
}
function lvl(array $r, $id)
{
    $o = [];
    foreach ($r['checks'] as $c) {
        if ($c['id'] === $id) {
            $o[] = $c['level'];
        }
    }
    return implode(',', $o);
}
function msg(array $r, $id)
{
    foreach ($r['checks'] as $c) {
        if ($c['id'] === $id && $c['level'] !== 'pass') {
            return $c['message'];
        }
    }
    return '';
}
$ok = 0; $fail = 0;
function check($name, $cond) { global $ok, $fail; if ($cond) { $ok++; } else { $fail++; echo "SAI: {$name}\n"; } }
function reset_state() { $GLOBALS['NET'] = []; $GLOBALS['BLOG'] = []; $GLOBALS['KEYMODE'] = [2 => 'own']; }

// 1. Không khoá → SC-1 đỏ, không gọi API
reset_state(); $GLOBALS['KEYMODE'] = [];
$cl = new FakeClient(tenant_ok());
$r = TGS_Acc_Selfcheck::run(2, [], deps($cl, $GOOD_DOCS));
check('không khoá: SC-1 fail', lvl($r, 'SC-1') === 'fail' && $r['verdict'] === 'fail');
check('không khoá: 0 lời gọi API', $cl->calls === []);
check('lưu kết quả gần nhất', ($GLOBALS['BLOG'][2]['tgs_acc_selfcheck_last']['verdict'] ?? '') === 'fail');

// 2. Đủ, chưa xác nhận tenant → vàng ở SC-2, còn lại xanh
reset_state();
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('chưa xác nhận: SC-2 warn', lvl($r, 'SC-2') === 'warn' && $r['verdict'] === 'warn');
check('tenant đọc từ /tenant', $r['tenant'] === 'shopa');
foreach (['SC-1', 'SC-3', 'SC-4', 'SC-5', 'SC-6', 'SC-7', 'SC-8', 'SC-9', 'SC-10', 'SC-11'] as $id) {
    check("đủ: {$id} pass", lvl($r, $id) !== '' && str_replace(['pass', ','], '', lvl($r, $id)) === '');
}

// 3. --confirm-tenant → lưu, lần sau xanh hết
$r = TGS_Acc_Selfcheck::run(2, ['confirm_tenant' => true], deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('xác nhận: lưu mã tenant', ($GLOBALS['BLOG'][2]['tgs_acc_tenant_code'] ?? '') === 'shopa');
check('xác nhận: verdict pass', $r['verdict'] === 'pass');

// 4. Khoá trỏ tenant khác mã đã xác nhận → SC-2 đỏ
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['/api/v1/tenant' => ['data' => ['code' => 'shopb']]])), $GOOD_DOCS));
check('sai tenant: SC-2 fail', lvl($r, 'SC-2') === 'fail' && strpos(msg($r, 'SC-2'), 'shopb') !== false);

// 5. Hai blog cùng tenant → SC-3 đỏ; blog kia mất khoá thì thôi
reset_state(); $GLOBALS['KEYMODE'][5] = 'own'; $GLOBALS['NET']['tgs_acc_tenant_map'] = [5 => 'shopa'];
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('trùng tenant: SC-3 fail', lvl($r, 'SC-3') === 'fail' && strpos(msg($r, 'SC-3'), '5') !== false);
check('bản đồ ghi blog 2', ($GLOBALS['NET']['tgs_acc_tenant_map'][2] ?? '') === 'shopa');
$GLOBALS['KEYMODE'][5] = 'none';
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('blog kia không còn khoá: SC-3 pass', lvl($r, 'SC-3') === 'pass');

// 6. Mã bridge sẽ gửi mà tenant không có: QR-VTB-CT, R04, XTA
reset_state();
$docs = [sale(10, [['taxCode' => 'R10', 'warehouseCode' => ''], ['taxCode' => 'R04', 'warehouseCode' => '']], 'XTA'), pay(11, 'QR-VTB-CT'), pay(12, 'QR-VTB-CT'), pay(13, 'TM')];
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $docs));
check('phương thức thiếu: SC-5 fail', lvl($r, 'SC-5') === 'fail' && strpos(msg($r, 'SC-5'), 'QR-VTB-CT ×2') !== false);
check('thuế thiếu: SC-6 fail R04', lvl($r, 'SC-6') === 'fail' && strpos(msg($r, 'SC-6'), 'R04') !== false && strpos(msg($r, 'SC-6'), 'R10') === false);
check('lý do thiếu: SC-8 fail XTA', lvl($r, 'SC-8') === 'fail' && strpos(msg($r, 'SC-8'), 'XTA ×1') !== false);
check('chi nhánh có + kho mặc định: SC-7 pass', lvl($r, 'SC-7') === 'pass,pass');

// 7. Tenant 0 kho → SC-7 vàng; dòng có kho lạ → đỏ
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['/api/v1/warehouses' => ['data' => []]])), [sale(10, [['taxCode' => 'R10', 'warehouseCode' => '']])]));
check('0 kho: SC-7 warn', strpos(lvl($r, 'SC-7'), 'warn') !== false && strpos(lvl($r, 'SC-7'), 'fail') === false);
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), [sale(10, [['taxCode' => 'R10', 'warehouseCode' => 'K99']])]));
check('kho lạ: SC-7 fail', strpos(lvl($r, 'SC-7'), 'fail') !== false);

// 8. Preflight: chặn → đỏ, chỉ cảnh báo → vàng, ưu tiên phiếu bán
$seen = [];
$pf = function ($b) use (&$seen) { $seen[] = $b['document']['docType']; return ['findings' => [['severity' => 'block', 'code' => 'IN-SUM-001', 'title' => 'Tổng lệch']]]; };
$r = TGS_Acc_Selfcheck::run(2, ['samples' => 1], deps(new FakeClient(tenant_ok(['preflight' => $pf])), [pay(11, 'TM'), sale(10, [['taxCode' => 'R10', 'warehouseCode' => 'K01']])]));
check('preflight chặn: SC-9 fail', lvl($r, 'SC-9') === 'fail' && strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'IN-SUM-001') !== false);
check('preflight thử phiếu bán trước', $seen === ['sale']);
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['preflight' => ['findings' => [['severity' => 'warn', 'code' => 'IN-CFG-001']]]])), $GOOD_DOCS));
check('preflight cảnh báo: SC-9 warn', lvl($r, 'SC-9') === 'warn');
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), []));
check('không có phiếu: SC-9 warn', lvl($r, 'SC-9') === 'warn');
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['preflight' => new WP_Error('forbidden', 'Thiếu quyền', ['status' => 403])])), $GOOD_DOCS));
check('preflight 403: SC-4 fail', strpos(lvl($r, 'SC-4'), 'fail') !== false);

// 9. Khoá bị từ chối ngay ở /tenant → SC-2 + SC-4 đỏ, dừng
$cl = new FakeClient(tenant_ok(['/api/v1/tenant' => new WP_Error('unauthorized', 'Khoá sai', ['status' => 401])]));
$r = TGS_Acc_Selfcheck::run(2, [], deps($cl, $GOOD_DOCS));
check('401: SC-2 fail, SC-4 fail', lvl($r, 'SC-2') === 'fail' && lvl($r, 'SC-4') === 'fail');
check('401: dừng sau /tenant', count($cl->calls) === 1);

// 10. Hàng chờ: ≥ 6 ngày đỏ, ≥ 3 ngày vàng, pending > 24 h vàng
global $NOW;
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS, [], ['groups' => 4, 'oldest' => gmdate('Y-m-d H:i:s', $NOW - 6.5 * 86400)]));
check('hàng chờ 6,5 ngày: SC-10 fail', lvl($r, 'SC-10') === 'fail');
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS, [], ['groups' => 1, 'oldest' => gmdate('Y-m-d H:i:s', $NOW - 4 * 86400)]));
check('hàng chờ 4 ngày: SC-10 warn', lvl($r, 'SC-10') === 'warn');
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS, ['pending_over_24h' => 2]));
check('pending > 24 h: SC-10 warn', lvl($r, 'SC-10') === 'warn');

// 11. Đồng hồ lệch 400 giây → SC-11 đỏ
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['/api/v1/catalog/feed/meta' => ['data' => ['serverTime' => gmdate('c', $NOW + 400)]]])), $GOOD_DOCS));
check('lệch 400 s: SC-11 fail', lvl($r, 'SC-11') === 'fail');

// 12. used_codes gom cả sale.payments
$u = TGS_Acc_Selfcheck::used_codes([sale(1, [['taxCode' => 'R08', 'warehouseCode' => '']], '', [['methodCode' => 'TM'], ['methodCode' => 'QR']]), pay(2, 'TM')]);
check('used_codes: phương thức TM ×2, QR ×1', $u['methods'] === ['TM' => 2, 'QR' => 1]);
check('used_codes: bỏ mã rỗng', $u['warehouses'] === [] && $u['reasons'] === []);

// 13. Khoá chung của mạng → SC-1 vàng
reset_state(); $GLOBALS['KEYMODE'][2] = 'legacy';
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('khoá chung: SC-1 warn', lvl($r, 'SC-1') === 'warn');

// 14. S001-MD3: chưa có bảng ánh xạ → SC-5 thêm dòng vàng, không đỏ
reset_state(); $GLOBALS['MAP_READY'] = false;
$r = TGS_Acc_Selfcheck::run(2, ['confirm_tenant' => true], deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('không bảng ánh xạ: SC-5 pass + warn', lvl($r, 'SC-5') === 'pass,warn' && $r['verdict'] === 'warn');
$GLOBALS['MAP_READY'] = true;

// 15. OKT-H2: phiếu POS không ghi kho → kế toán ghi vào kho mặc định của chi nhánh; SC-7 nói rõ kho nào nhận
reset_state();
$POS = [sale(10, [['taxCode' => 'R10']]), pay(11, 'TM')];
$r = TGS_Acc_Selfcheck::run(2, ['confirm_tenant' => true], deps(new FakeClient(tenant_ok()), $POS));
$m = implode(' | ', array_column(array_filter($r['checks'], function ($c) { return $c['id'] === 'SC-7'; }), 'message'));
check('H2 có kho mặc định: SC-7 pass, nêu K01', lvl($r, 'SC-7') === 'pass,pass' && strpos($m, '`K01`') !== false);
$wh2 = ['data' => [['code' => 'K01', 'branchCode' => 'CN01', 'isDefault' => false], ['code' => 'K02', 'branchCode' => 'CN02', 'isDefault' => true]]];
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['/api/v1/warehouses' => $wh2])), $POS));
check('H2 mặc định chỉ ở chi nhánh khác: SC-7 warn', strpos(lvl($r, 'SC-7'), 'warn') !== false && strpos(lvl($r, 'SC-7'), 'fail') === false);
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['/api/v1/warehouses' => ['data' => [['code' => 'K01', 'branchCode' => 'CN01', 'isDefault' => true, 'isActive' => false]]]])), $POS));
check('H2 kho mặc định ngừng dùng: SC-7 warn', strpos(lvl($r, 'SC-7'), 'warn') !== false);
$GLOBALS['BRANCH'] = '';
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok()), $POS));
check('H2 blog chưa nối chi nhánh: SC-7 warn', strpos(lvl($r, 'SC-7'), 'warn') !== false);
unset($GLOBALS['BRANCH']);
$r = TGS_Acc_Selfcheck::run(2, [], deps(new FakeClient(tenant_ok(['/api/v1/warehouses' => new WP_Error('x', 'hỏng')])), $POS));
check('H2 không đọc được kho: không báo kho mặc định', strpos(implode(' ', array_column($r['checks'], 'message')), 'kho mặc định') === false);

// 16. OKT-MA-TRAN: SC-9 lấy phiếu bán MỚI NHẤT (build_refs trả id tăng dần), không phải cũ nhất
reset_state();
$asc = [];
foreach ([2, 5, 8, 11, 14, 140, 145, 150] as $id) { $asc[] = sale($id, [['taxCode' => 'R10']]); }
$asc[] = pay(141, 'TM'); $asc[] = pay(151, 'TM');
$sent = [];
$cl = new FakeClient(tenant_ok(['preflight' => function ($b) use (&$sent) { $sent[] = $b['document']['docKey']; return ['findings' => []]; }]));
$r = TGS_Acc_Selfcheck::run(2, [], deps($cl, $asc));
check('SC-9 preflight 5 phiếu bán mới nhất', $sent === ['btsoft:2:sale:150', 'btsoft:2:sale:145', 'btsoft:2:sale:140', 'btsoft:2:sale:14', 'btsoft:2:sale:11']);

// 17. OKT-MT-3: lịch của site chính quá hạn → SC-10 vàng / đỏ; đúng hạn → không thêm dòng
$cr = function (array $over) { global $NOW; return ['cron' => $over + ['tgs_acc_events_retry' => $NOW - 60, 'tgs_acc_status_pull' => $NOW - 60, 'tgs_acc_selfcheck_daily' => $NOW + 3600]]; };
reset_state();
$r = TGS_Acc_Selfcheck::run(2, [], $cr([]) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('cron đúng hạn: SC-10 chỉ một dòng xanh', lvl($r, 'SC-10') === 'pass');
$r = TGS_Acc_Selfcheck::run(2, [], $cr(['tgs_acc_events_retry' => $NOW - 3 * 3600]) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('gửi lại quá hạn 3 giờ: SC-10 vàng, nêu cron hệ thống', lvl($r, 'SC-10') === 'pass,warn' && strpos(msg($r, 'SC-10'), 'cron hệ thống') !== false);
$r = TGS_Acc_Selfcheck::run(2, [], $cr(['tgs_acc_status_pull' => $NOW - 7 * 3600]) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('kéo trạng thái quá hạn 7 giờ: SC-10 đỏ', strpos(lvl($r, 'SC-10'), 'fail') !== false);
$r = TGS_Acc_Selfcheck::run(2, [], $cr(['tgs_acc_selfcheck_daily' => null]) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('tự kiểm hằng ngày chưa lên lịch: SC-10 đỏ', strpos(lvl($r, 'SC-10'), 'fail') !== false && strpos(msg($r, 'SC-10'), 'chưa lên lịch') !== false);
$r = TGS_Acc_Selfcheck::run(2, [], ['cron' => null] + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('không đọc được lịch (ngoài multisite): bỏ qua', lvl($r, 'SC-10') === 'pass');

// 18. OKT-H4: SC-12 = kết quả đối chiếu, tối đa vàng (giai đoạn đầu chỉ báo); --no-reconcile bỏ
$rc = function ($levels) { return ['reconcile' => function () use ($levels) { return ['checks' => array_map(function ($l, $i) { return ['id' => 'R-' . ($i + 1), 'level' => $l, 'message' => "m{$i}", 'data' => []]; }, $levels, array_keys($levels))]; }]; };
$r = TGS_Acc_Selfcheck::run(2, [], $rc(['pass', 'pass']) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('SC-12 xanh khi đối chiếu xanh', lvl($r, 'SC-12') === 'pass,pass' && in_array('R-1 m0', array_column($r['checks'], 'message'), true));
$r = TGS_Acc_Selfcheck::run(2, [], $rc(['warn', 'fail']) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('SC-12 đỏ ở đối chiếu → vàng ở tự kiểm', lvl($r, 'SC-12') === 'warn,warn');
$r = TGS_Acc_Selfcheck::run(2, ['no_reconcile' => true], $rc(['warn']) + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('no_reconcile: không có SC-12', lvl($r, 'SC-12') === '');

// 19. OKT-TOPO-2: SC-0b kiểu cài
reset_state();
$r = TGS_Acc_Selfcheck::run(2, [], ['topology' => 'single'] + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('SC-0b một site: xanh, nêu kiểu', lvl($r, 'SC-0b') === 'pass' && strpos($r['checks'][0]['message'], 'một site') !== false);
$r = TGS_Acc_Selfcheck::run(2, [], ['topology' => 'multisite-network-tenant'] + deps(new FakeClient(tenant_ok()), $GOOD_DOCS));
check('SC-0b chuỗi (một khoá cả mạng): vàng, nêu Q-OKT-3', lvl($r, 'SC-0b') === 'warn' && strpos(msg($r, 'SC-0b'), 'Q-OKT-3') !== false);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
