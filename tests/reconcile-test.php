<?php
/**
 * Kiểm OKT-H4 (TGS_Acc_Reconcile: đối chiếu chứng từ + tồn WP ↔ kế toán) không cần WordPress: client giả, chứng từ dựng sẵn.
 *
 *   php tests/reconcile-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 09:50 PM Johnny Chu - Chu Hoàng Anh] OKT-H4 — ca lấy từ lượt chạy thật của ma trận 65 (chứng từ thử mồ côi sale:900001).

define('ABSPATH', __DIR__ . '/');
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
require dirname(__DIR__) . '/includes/class-tgs-acc-reconcile.php';

$ok = 0; $fail = 0;
function check($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}\n"; } }
function lv(array $r, $id) { return implode(',', array_map(function ($c) { return $c['level']; }, array_values(array_filter($r['checks'], function ($c) use ($id) { return $c['id'] === $id; })))); }

class FakeClient
{
    public $docs; public $stock; public $calls = []; public $fail_list = false;
    function __construct(array $docs, array $stock) { $this->docs = $docs; $this->stock = $stock; }
    function get($path, array $q = [])
    {
        $this->calls[] = [$path, $q];
        if ($path === '/api/v1/documents') {
            if ($this->fail_list) { return new WP_Error('x', 'kế toán không trả lời'); }
            $size = (int) $q['pageSize'];
            $rows = array_slice($this->docs, ((int) $q['page'] - 1) * $size, $size);
            return ['data' => $rows, 'meta' => ['total' => count($this->docs)]];
        }
        if ($path === '/api/v1/inventory/items') {
            $out = [];
            foreach (explode(',', $q['skus']) as $s) { if (array_key_exists($s, $this->stock)) { $out[] = ['sku' => $s, 'stock' => $this->stock[$s]]; } }
            return ['data' => $out];
        }
        return ['data' => []];
    }
}
function doc($type, $id, $day, array $lines = []) { return ['docType' => $type, 'docKey' => "btsoft:2:{$type}:{$id}", 'docDate' => $day, 'lines' => $lines]; }
function row($key, $status, array $x = []) { return $x + ['docKey' => $key, 'status' => $status, 'version' => 1, 'postedVersion' => $status === 'posted' ? 1 : null, 'errorMessage' => null, 'cancelRequestedAt' => null]; }
$A = [['sku' => 'A', 'qty' => 1]];
$WANT = [
    doc('sale', 10, '2026-11-22', $A),                         // posted
    doc('sale', 11, '2026-11-22', $A),                         // pending ở kế toán → chờ −1
    doc('purchase', 12, '2026-11-21', [['sku' => 'A', 'qty' => 5]]), // thiếu ở kế toán → chờ +5
    doc('receipt', 13, '2026-11-22'),                          // posted, không có dòng hàng
    doc('sale', 9, '2026-11-01', $A),                          // ngày ngoài khoảng (phiếu sửa trong khoảng) → bỏ
];
$HAVE = [
    row('btsoft:2:sale:10', 'posted'), row('btsoft:2:sale:11', 'pending'), row('btsoft:2:receipt:13', 'posted'),
    row('btsoft:2:sale:900001', 'posted'),                                        // mồ côi
    row('btsoft:2:sale:99', 'posted', ['cancelRequestedAt' => '2026-11-22T06:12:38Z']), // chờ duyệt huỷ
    row('btsoft:2:sale:98', 'cancelled'),                                         // đã huỷ → bỏ qua
    row('btsoft:3:sale:5', 'posted'),                                             // blog khác → bỏ qua
    row('btsoft:22:sale:5', 'posted'),                                            // tiền tố gần giống (blog 22) → bỏ qua
];
function deps($client, $want, $wp_stock) { return ['client' => $client, 'today' => '2026-11-22', 'branch' => 'CN01', 'build' => function () use ($want) { return $want; }, 'stock' => function () use ($wp_stock) { return $wp_stock; }]; }

// 1. Chứng từ: thiếu / mồ côi / chờ huỷ / chờ định khoản
$cl = new FakeClient($HAVE, ['A' => 6]);
$r = TGS_Acc_Reconcile::run(2, [], deps($cl, $WANT, ['A' => 10]));
check('khoảng mặc định 7 ngày', $r['from'] === '2026-11-16' && $r['to'] === '2026-11-22');
check('hỏi kế toán theo ngày + chi nhánh + nguồn btsoft', $cl->calls[0][1]['from'] === '2026-11-16' && $cl->calls[0][1]['branchCode'] === 'CN01' && $cl->calls[0][1]['sourceSystem'] === 'btsoft');
check('WP: 4 chứng từ trong khoảng (bỏ sale:9 ngày cũ)', $r['counts']['wp'] === 4);
check('thiếu: purchase:12', $r['missing'] === ['btsoft:2:purchase:12']);
check('mồ côi: sale:900001 (bỏ blog 3, blog 22, đã huỷ)', $r['orphan'] === ['btsoft:2:sale:900001 (posted)']);
check('đang xin huỷ: sale:99 — không tính mồ côi', $r['cancel_pending'] === ['btsoft:2:sale:99']);
check('chờ định khoản: sale:11', $r['pending'] === ['btsoft:2:sale:11']);
check('R-1 vàng, R-2 vàng ×2, R-3 xanh', lv($r, 'R-1') === 'warn' && lv($r, 'R-2') === 'warn,warn' && lv($r, 'R-3') === 'pass');

// 2. Tồn: WP 10 = KT 6 + chờ (−1 + 5) → khớp
$st = $r['stock'][0];
check('tồn khớp khi tính phần chưa định khoản', $st['wp'] == 10 && $st['ketoan'] == 6 && $st['chua_dinh_khoan'] == 4 && $st['lech'] == 0 && lv($r, 'R-4') === 'pass');
check('hỏi tồn theo mã + chi nhánh', $cl->calls[1][0] === '/api/v1/inventory/items' && $cl->calls[1][1]['skus'] === 'A' && $cl->calls[1][1]['branchCode'] === 'CN01');

// 3. Tồn lệch (mồ côi đã định khoản ăn mất 1) → vàng, nhắc R-2
$r = TGS_Acc_Reconcile::run(2, [], deps(new FakeClient($HAVE, ['A' => 5]), $WANT, ['A' => 10]));
check('lệch 1 → R-4 vàng, nêu mồ côi', lv($r, 'R-4') === 'warn' && $r['stock'][0]['lech'] == 1 && strpos($r['checks'][count($r['checks']) - 1]['message'], 'mồ côi') !== false);
check('verdict vàng', $r['verdict'] === 'warn');

// 4. Mã kế toán chưa có → lệch
$r = TGS_Acc_Reconcile::run(2, [], deps(new FakeClient($HAVE, []), $WANT, ['A' => 10]));
check('mã chưa có ở kế toán → R-4 vàng', lv($r, 'R-4') === 'warn' && $r['stock'][0]['ketoan'] === null && strpos(implode(' ', array_column($r['checks'], 'message')), 'kế toán chưa có mã') !== false);

// 5. Lỗi định khoản → R-3 đỏ; bản cũ → vàng
$h = $HAVE; $h[1] = row('btsoft:2:sale:11', 'error', ['errorMessage' => 'Thiếu TK']);
$r = TGS_Acc_Reconcile::run(2, [], deps(new FakeClient($h, ['A' => 6]), $WANT, ['A' => 10]));
check('lỗi → R-3 đỏ, verdict đỏ, có câu lỗi', lv($r, 'R-3') === 'fail' && $r['verdict'] === 'fail' && $r['error'] === ['btsoft:2:sale:11: Thiếu TK']);
$h = $HAVE; $h[0] = row('btsoft:2:sale:10', 'posted', ['version' => 2, 'postedVersion' => 1]);
$r = TGS_Acc_Reconcile::run(2, [], deps(new FakeClient($h, ['A' => 6]), $WANT, ['A' => 10]));
check('định khoản bản cũ → R-3 vàng', lv($r, 'R-3') === 'warn' && $r['stale'] === ['btsoft:2:sale:10']);

// 6. Tất cả khớp → xanh
$r = TGS_Acc_Reconcile::run(2, [], deps(new FakeClient([row('btsoft:2:sale:10', 'posted'), row('btsoft:2:receipt:13', 'posted')], ['A' => 9]), [doc('sale', 10, '2026-11-22', $A), doc('receipt', 13, '2026-11-22')], ['A' => 9]));
check('khớp hết → xanh', $r['verdict'] === 'pass');

// 7. Kế toán không trả lời → R-1 đỏ, không đối chiếu tồn
$cl = new FakeClient($HAVE, ['A' => 6]); $cl->fail_list = true;
$r = TGS_Acc_Reconcile::run(2, [], deps($cl, $WANT, ['A' => 10]));
check('không đọc được danh sách → R-1 đỏ', lv($r, 'R-1') === 'fail' && !$r['stock']);

// 8. Chia trang: 501 chứng từ ở kế toán → đọc 2 trang
$many = [];
for ($i = 1; $i <= 501; $i++) { $many[] = row("btsoft:2:sale:{$i}", 'posted'); }
$cl = new FakeClient($many, []);
$r = TGS_Acc_Reconcile::run(2, [], deps($cl, [], []));
check('đọc đủ 2 trang (501 chứng từ → 501 mồ côi)', count(array_filter($cl->calls, function ($c) { return $c[0] === '/api/v1/documents'; })) === 2 && $r['counts']['orphan'] === 501);
check('không có mặt hàng biến động → R-4 xanh, không hỏi tồn', lv($r, 'R-4') === 'pass' && count($cl->calls) === 2);

// 9. Giới hạn số mã: mã biến động nhiều nhất trước
$want = [doc('sale', 1, '2026-11-22', [['sku' => 'X', 'qty' => 1]]), doc('sale', 2, '2026-11-22', [['sku' => 'Y', 'qty' => 1]]), doc('sale', 3, '2026-11-22', [['sku' => 'Y', 'qty' => 1]])];
$cl = new FakeClient([], ['X' => 0, 'Y' => 0]);
$r = TGS_Acc_Reconcile::run(2, ['skus' => 1], deps($cl, $want, ['X' => 0, 'Y' => 0]));
check('--skus=1 → chỉ Y (2 lần)', count($r['stock']) === 1 && $r['stock'][0]['sku'] === 'Y');

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
