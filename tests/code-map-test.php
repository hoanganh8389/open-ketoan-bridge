<?php
/**
 * Kiểm S001-MD3 phía bridge (TGS_Acc_Code_Map) không cần WordPress: bảng ánh xạ giả thay TGS_Okt_Map của open-pos-backend.
 *
 *   php tests/code-map-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — test dịch mã khi dựng chứng từ + tự dựng.

define('ABSPATH', __DIR__ . '/');
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
require dirname(__DIR__) . '/includes/class-tgs-acc-code-map.php';

$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "SAI: {$n}\n"; } }

$sale = ['docType' => 'sale', 'docKey' => 'btsoft:2:sale:10', 'reasonCode' => 'XTA', 'payments' => [['methodCode' => 'QR-VTB-CT', 'amount' => 1], ['methodCode' => 'TM', 'amount' => 2]],
    'lines' => [['taxCode' => 'R10'], ['taxCode' => 'R04']], 'extra' => ['blogId' => 2]];
$pay = ['docType' => 'customer_payment', 'docKey' => 'btsoft:2:receipt:11', 'methodCode' => 'QR-VTB-CT', 'description' => 'Thu tiền khách hàng (QR VTB CT)', 'lines' => [['amount' => 11000]], 'extra' => ['blogId' => 2]];

// 1. Chưa có open-pos-backend (không có TGS_Okt_Map) → giữ nguyên
check('không có bảng: chứng từ giữ nguyên', TGS_Acc_Code_Map::apply($sale) === $sale && TGS_Acc_Code_Map::apply_all([$pay]) === [$pay]);

if (true) { // khai báo lúc chạy (không bị PHP kéo lên đầu tệp), để ca 1 thấy "chưa có bảng"
    class TGS_Okt_Map
    {
        static $map = [2 => ['method' => ['QR-VTB-CT' => 'QR'], 'reason' => ['XTA' => 'XTA1.4'], 'tax' => ['R10' => 'R10']]];
        static $calls = [];
        static function remote($b, $k, $l) { return self::$map[$b][$k][$l] ?? null; }
        static function auto_fill($b, array $used, array $remote, $tenant = '', array $labels = []) { self::$calls[] = compact('b', 'used', 'remote', 'tenant', 'labels'); return ['method' => []]; }
        static function flush($b = null) {}
    }
}

// 2. Dịch đủ các chỗ
$s = TGS_Acc_Code_Map::apply($sale);
check('sale: payments QR-VTB-CT → QR, TM giữ', $s['payments'][0]['methodCode'] === 'QR' && $s['payments'][1]['methodCode'] === 'TM');
check('sale: lý do XTA → XTA1.4', $s['reasonCode'] === 'XTA1.4');
check('sale: thuế R10 giữ, R04 chưa ánh xạ giữ nguyên', $s['lines'][0]['taxCode'] === 'R10' && $s['lines'][1]['taxCode'] === 'R04');
check('sale: phần khác không đổi', $s['docKey'] === $sale['docKey'] && $s['payments'][0]['amount'] === 1);
$p = TGS_Acc_Code_Map::apply($pay);
check('phiếu thu: methodCode → QR', $p['methodCode'] === 'QR');

// 3. Chạy hai lần không đổi thêm (pusher build_refs gọi build rồi áp lại)
check('áp hai lần = một lần', TGS_Acc_Code_Map::apply($s) === $s);

// 4. Blog khác: không dùng ánh xạ của blog 2
$o = $sale; $o['extra']['blogId'] = 3;
check('blog khác: giữ nguyên', TGS_Acc_Code_Map::apply($o) === $o);
check('blog truyền tay thắng extra', TGS_Acc_Code_Map::apply($o, 2)['reasonCode'] === 'XTA1.4');
$n = $sale; unset($n['extra']);
check('không biết blog: giữ nguyên', TGS_Acc_Code_Map::apply($n) === $n);

// 5. Chế độ raw (tự dựng cần mã gốc)
TGS_Acc_Code_Map::$raw = true;
check('raw: giữ nguyên', TGS_Acc_Code_Map::apply($sale) === $sale);
TGS_Acc_Code_Map::$raw = false;

// 6. Tự dựng: dựng CHƯA dịch, danh mục tenant qua API, nhãn từ phiếu thu
class FakeClient
{
    public $calls = [];
    function get($path, array $q = [])
    {
        $this->calls[] = $path;
        $d = [
            '/api/v1/tenant' => ['code' => 'shopa'],
            '/api/v1/posting/payment-methods' => [['code' => 'TM'], ['code' => 'QR']],
            '/api/v1/posting/reasons' => [['code' => 'XBA'], ['code' => 'XTA1.4']],
            '/api/v1/tax-codes' => [['code' => 'R08'], ['code' => 'R10']],
        ];
        return ['data' => $d[$path] ?? []];
    }
}
class TGS_Acc_Selfcheck { static function used_codes(array $docs) { $u = ['methods' => [], 'taxes' => [], 'branches' => [], 'warehouses' => [], 'reasons' => []]; foreach ($docs as $d) { if (!empty($d['methodCode'])) $u['methods'][$d['methodCode']] = ($u['methods'][$d['methodCode']] ?? 0) + 1; foreach ((array) ($d['payments'] ?? []) as $x) $u['methods'][$x['methodCode']] = ($u['methods'][$x['methodCode']] ?? 0) + 1; if (!empty($d['reasonCode'])) $u['reasons'][$d['reasonCode']] = 1; foreach ((array) ($d['lines'] ?? []) as $l) if (!empty($l['taxCode'])) $u['taxes'][$l['taxCode']] = ($u['taxes'][$l['taxCode']] ?? 0) + 1; } return $u; } }
$rawSeen = null;
$cl = new FakeClient();
$r = TGS_Acc_Code_Map::auto(2, ['client' => $cl, 'ids' => function () { return [10, 11]; }, 'build' => function ($b, $ids) use (&$rawSeen, $sale, $pay) { $rawSeen = TGS_Acc_Code_Map::$raw; return ['docs' => [$sale, $pay]]; }]);
check('auto: dựng ở chế độ raw', $rawSeen === true && TGS_Acc_Code_Map::$raw === false);
check('auto: tenant + số phiếu', $r['tenant'] === 'shopa' && $r['scanned'] === 2);
$c = TGS_Okt_Map::$calls[0] ?? [];
check('auto: mã bridge sinh (chưa dịch)', ($c['used']['method'] ?? []) === ['QR-VTB-CT' => 2, 'TM' => 1] && ($c['used']['reason'] ?? []) === ['XTA' => 1] && ($c['used']['tax'] ?? []) === ['R10' => 1, 'R04' => 1]);
check('auto: danh mục tenant theo loại', ($c['remote']['method'] ?? []) === ['TM', 'QR'] && ($c['remote']['reason'] ?? []) === ['XBA', 'XTA1.4'] && ($c['remote']['tax'] ?? []) === ['R08', 'R10']);
check('auto: nhãn phiếu thu', ($c['labels']['method']['QR-VTB-CT'] ?? '') === 'QR VTB CT');
check('auto: gọi đúng 4 API', $cl->calls === ['/api/v1/tenant', '/api/v1/posting/payment-methods', '/api/v1/posting/reasons', '/api/v1/tax-codes']);

// 7. API lỗi → trả lỗi, không ghi
TGS_Okt_Map::$calls = [];
class BadClient { function get($p, array $q = []) { return $p === '/api/v1/tenant' ? new WP_Error('unauthorized', 'Khoá sai', ['status' => 401]) : ['data' => []]; } }
$r = TGS_Acc_Code_Map::auto(2, ['client' => new BadClient(), 'ids' => function () { return [1]; }, 'build' => function () { return ['docs' => []]; }]);
check('auto 401: WP_Error, không ghi', is_wp_error($r) && TGS_Okt_Map::$calls === []);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
