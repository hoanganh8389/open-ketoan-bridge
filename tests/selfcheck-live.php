<?php
/**
 * Tự kiểm OKT-SC-1 chạy THẬT vào một open-ketoan thử (không cần WordPress): client curl mỏng, ba ca dựng sẵn
 * (đơn tiền mặt · đơn QR nhãn ngân hàng + thuế 3,5 % + lý do XTA · khoá sai). Kiểm hợp đồng API của SC-1…SC-11.
 * KHÔNG ghi gì vào kế toán (preflight save:false). Chỉ dùng với môi trường thử.
 *
 *   OKT_URL=http://127.0.0.1:8098 OKT_KEY=<khoá tenant thử> php tests/selfcheck-live.php
 */
// [2026-11-22 11:45 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — chạy tự kiểm vào open-ketoan thử (okt-thu).
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
$bridge = dirname(__DIR__);
$url = (string) getenv('OKT_URL');
$key = (string) getenv('OKT_KEY');
if ($url === '' || $key === '') { fwrite(STDERR, "Đặt OKT_URL và OKT_KEY (tenant thử).
"); exit(2); }
$GLOBALS['NET'] = []; $GLOBALS['BLOG'] = [];
function get_site_option($k, $d = false) { return $GLOBALS['NET'][$k] ?? $d; }
function update_site_option($k, $v) { $GLOBALS['NET'][$k] = $v; return true; }
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
class WP_Error { public $m; public $d; function __construct($c = '', $m = '', $d = null) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
class TGS_Acc_Config { static function key_mode($b) { return $b === 2 ? 'own' : 'none'; } static function url_for($b) { return 'live'; } }
require $bridge . '/includes/class-tgs-acc-selfcheck.php';
class CurlClient {
    function __construct($u, $k) { $this->u = rtrim($u, '/'); $this->k = $k; }
    private function req($m, $p, $q = [], $body = null) {
        $ch = curl_init($this->u . $p . ($q ? '?' . http_build_query($q) : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => array_filter(['X-Api-Key: ' . $this->k, 'Accept: application/json', $body !== null ? 'Content-Type: application/json' : null])]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string) $raw, true);
        if (!is_array($j)) return new WP_Error('bad', "HTTP {$code} không phải JSON", ['status' => $code]);
        if (empty($j['ok'])) return new WP_Error($j['error']['code'] ?? 'err', $j['error']['message'] ?? 'lỗi', ['status' => $code]);
        return $j;
    }
    function get($p, array $q = []) { $r = $this->req('GET', $p, $q); return is_wp_error($r) ? $r : ['data' => $r['data'], 'meta' => $r['meta'] ?? []]; }
    function post_json($p, array $b) { $r = $this->req('POST', $p, [], $b); return is_wp_error($r) ? $r : (array) $r['data']; }
}
$sale = ['docType' => 'sale', 'docKey' => 'btsoft:2:sale:1', 'branchCode' => 'CN01', 'docNo' => 'BH-THU-1', 'docDate' => gmdate('Y-m-d'), 'description' => 'Tự kiểm SC-9', 'internalOnly' => false, 'sourceSystem' => 'btsoft',
  'lines' => [['lineKey' => 'i1', 'sku' => '900000001', 'name' => 'Hàng thử lát dọc 900000001', 'unit' => 'Cái', 'qty' => 1, 'unitQty' => 1, 'unitRatio' => 1, 'unitPrice' => 10000, 'amountBeforeTax' => 10000, 'discount' => 0, 'taxCode' => 'R10', 'taxAmount' => 1000, 'warehouseCode' => '', 'isGift' => false, 'lotNo' => '', 'note' => '']],
  'payments' => [], 'totals' => ['beforeTax' => 10000, 'tax' => 1000, 'total' => 11000]];
$pay = fn($id, $m) => ['docType' => 'customer_payment', 'docKey' => "btsoft:2:receipt:{$id}", 'branchCode' => 'CN01', 'docNo' => "PT{$id}", 'docDate' => gmdate('Y-m-d'), 'methodCode' => $m, 'direction' => 'in', 'sourceSystem' => 'btsoft', 'lines' => [['lineKey' => 'l1', 'amount' => 11000, 'invoiceDocKey' => 'btsoft:2:sale:1']], 'totals' => ['total' => 11000]];
$scenarios = [
  'A. đơn tiền mặt (TM), xác nhận tenant' => [[$sale, $pay(2, 'TM')], ['confirm_tenant' => true], $key],
  'B. đơn QR nhãn ngân hàng + thuế 3,5 % + lý do XTA' => [[array_merge($sale, ['reasonCode' => 'XTA', 'lines' => [array_merge($sale['lines'][0], ['taxCode' => 'R04'])]]), $pay(3, 'QR-VTB-CT')], [], $key],
  'C. khoá sai' => [[$sale], [], 'okt_sai_khoa_0000'],
];
foreach ($scenarios as $name => [$docs, $opts, $k]) {
    $deps = ['client' => new CurlClient($url, $k), 'ids' => fn() => [1, 2], 'build' => fn() => ['docs' => $docs, 'failed' => []], 'status' => fn() => ['pending_over_24h' => 0, 'errors' => 0, 'paused' => false], 'queue' => fn() => ['groups' => 0, 'oldest' => '']];
    $r = TGS_Acc_Selfcheck::run(2, $opts + ['samples' => 2], $deps);
    $icon = ['pass' => '🟢', 'warn' => '🟡', 'fail' => '🔴'];
    echo "\n### {$name}\n{$icon[$r['verdict']]} blog 2 → tenant " . ($r['tenant'] ?: '?') . "\n";
    foreach ($r['checks'] as $c) echo "  {$icon[$c['level']]} " . str_pad($c['id'], 6) . " {$c['message']}\n";
}
