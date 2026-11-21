<?php
/**
 * ĐỐI CHIẾU số ở hệ thống bán hàng với số đã vào phần mềm kế toán, theo từng ngày của MỘT shop.
 *
 *   php wp-content/plugins/tgs_accounting_bridge/tools/doi-chieu-ke-toan.php --blog=95 --from=2026-10-01 --to=2026-10-09
 *   … thêm --url=… --key=… nếu không dùng TGS_KETOAN_API_URL / TGS_KETOAN_API_KEY trong wp-config.php
 *   … thêm --all để chạy lần lượt mọi shop áp dụng thuế
 *
 * Cách so:
 *   BÊN BÁN HÀNG  = dựng lại chứng từ từ sổ bán hàng NGAY LÚC NÀY (cùng hàm với lúc gửi), cộng theo ngày.
 *   BÊN KẾ TOÁN   = báo cáo Tổng hợp bán hàng theo ngày + Thu tiền theo hình thức thanh toán của chi nhánh đó.
 * So từng ngày: số phiếu bán, số phiếu trả, tiền hàng thuần (trước thuế), thuế thuần, và tiền thu thuần
 * theo từng hình thức. Ngày nào lệch thì in ra, kèm các chứng từ đang LỖI bên kế toán (nguyên nhân hay gặp nhất).
 *
 * CHỈ ĐỌC ở cả hai bên; không gửi, không sửa gì. Mã thoát: 0 = khớp hết, 2 = có lệch, 1 = lỗi chạy.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}

$cfg = (string) @file_get_contents(dirname(__DIR__, 4) . '/wp-config.php');
$def = function ($name, $fallback) use ($cfg) {
    return preg_match("/define\\(\\s*'" . $name . "'\\s*,\\s*'([^']*)'/", $cfg, $m) ? $m[1] : $fallback;
};
$_SERVER['HTTP_HOST'] = getenv('TGS_HOST') ?: $def('DOMAIN_CURRENT_SITE', 'localhost');
$_SERVER['REQUEST_URI'] = getenv('TGS_PATH') ?: $def('PATH_CURRENT_SITE', '/');
require dirname(__DIR__, 4) . '/wp-load.php';
require_once dirname(__DIR__) . '/tgs-accounting-bridge.php';

/** Chạy lại chính lệnh này trong một tiến trình khác với danh sách tham số cho trước. */
$rerun = function (array $args) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    passthru($cmd, $code);
    return (int) $code;
};

// --all: mỗi shop một tiến trình (hằng tên bảng gắn với shop lúc nạp WordPress).
if (!empty($opt['all'])) {
    $ids = class_exists('TGS_BCTK_Vat_Shops') ? array_map('intval', (array) TGS_BCTK_Vat_Shops::active_blog_ids()) : [];
    if (!$ids) {
        fwrite(STDERR, "Không có shop nào trong danh sách áp dụng thuế (tgs-bc-tk).\n");
        exit(1);
    }
    $worst = 0;
    foreach ($ids as $id) {
        $args = ['--blog=' . $id];
        foreach (['from', 'to', 'url', 'key'] as $k) {
            if (!empty($opt[$k])) {
                $args[] = "--{$k}=" . $opt[$k];
            }
        }
        $worst = max($worst, $rerun($args));
    }
    exit($worst);
}

$shop = (int) ($opt['blog'] ?? 0);
if (is_multisite() && $shop > 0 && get_current_blog_id() !== $shop) {
    if (getenv('TGS_ACC_REEXEC')) {
        fwrite(STDERR, "Không khởi động được trong shop #{$shop}.\n");
        exit(1);
    }
    $details = get_blog_details($shop);
    if (!$details) {
        fwrite(STDERR, "Không có shop #{$shop}.\n");
        exit(1);
    }
    putenv('TGS_HOST=' . $details->domain);
    putenv('TGS_PATH=' . $details->path);
    putenv('TGS_ACC_REEXEC=1');
    exit($rerun(array_slice($argv, 1)));
}

$from = (string) ($opt['from'] ?? '');
$to = (string) ($opt['to'] ?? $from);
if ($shop <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Dùng: php doi-chieu-ke-toan.php --blog=<id shop> --from=YYYY-MM-DD [--to=YYYY-MM-DD] [--url=…] [--key=…]\n  hoặc: php doi-chieu-ke-toan.php --all --from=… --to=…\n");
    exit(1);
}
$client = new TGS_Acc_Api_Client((string) ($opt['url'] ?? ''), (string) ($opt['key'] ?? ''));
if (!$client->ready()) {
    fwrite(STDERR, "Chưa có địa chỉ / khoá phần mềm kế toán: thêm --url=… --key=… hoặc khai TGS_KETOAN_API_URL, TGS_KETOAN_API_KEY.\n");
    exit(1);
}

// ---------------------------------------------------------------- 1. Bên bán hàng
$built = TGS_Acc_Pusher::build($shop, $from, $to);
$branch = $built['docs'] ? (string) $built['docs'][0]['branchCode'] : (string) get_option('tgs_site_code', '');
$blank = ['sale' => 0, 'ret' => 0, 'amount' => 0.0, 'tax' => 0.0, 'pay' => []];
$src = [];
foreach ($built['docs'] as $d) {
    $day = (string) $d['docDate'];
    $sign = $d['docType'] === 'sale_return' ? -1 : 1;
    $src[$day] = $src[$day] ?? $blank;
    $src[$day][$sign > 0 ? 'sale' : 'ret']++;
    $src[$day]['amount'] += $sign * (float) $d['totals']['beforeTax'];
    $src[$day]['tax'] += $sign * (float) $d['totals']['tax'];
    foreach ($d['payments'] as $p) {
        $src[$day]['pay'][$p['methodCode']] = ($src[$day]['pay'][$p['methodCode']] ?? 0) + $sign * (float) $p['amount'];
    }
}

echo "═══ ĐỐI CHIẾU SHOP #{$shop} (chi nhánh {$branch}) — {$from} → {$to} ═══\n";
if ($built['failed']) {
    echo 'Phiếu không dựng được chứng từ (' . count($built['failed']) . "): " . implode('; ', array_slice($built['failed'], 0, 5)) . "\n";
}
if ($branch === '') {
    echo "Shop không có phiếu nào trong khoảng này và chưa có mã chi nhánh → không có gì để so.\n";
    exit(0);
}

// ---------------------------------------------------------------- 2. Bên kế toán
$q = ['from' => $from, 'to' => $to, 'branchCodes' => $branch];
$sum = $client->get('/api/v1/reports/sales-summary', $q + ['groupBy' => 'day']);
$pay = $client->get('/api/v1/reports/sales-payments', $q + ['groupBy' => 'day']);
$err = $client->get('/api/v1/documents', ['from' => $from, 'to' => $to, 'branchCode' => $branch, 'status' => 'error', 'pageSize' => 200]);
foreach (['Tổng hợp bán hàng' => $sum, 'Thu tiền' => $pay, 'Chứng từ lỗi' => $err] as $name => $r) {
    if (is_wp_error($r)) {
        fwrite(STDERR, "Không đọc được \"{$name}\" bên kế toán: " . $r->get_error_message() . "\n");
        exit(1);
    }
}
$acc = [];
foreach ((array) $sum['data']['rows'] as $r) {
    $acc[$r['key']] = ['sale' => (int) $r['saleDocs'], 'ret' => (int) $r['returnDocs'], 'amount' => (float) $r['netAmount'], 'tax' => (float) $r['tax'], 'pay' => []];
}
foreach ((array) $pay['data']['rows'] as $r) {
    $acc[$r['key']] = $acc[$r['key']] ?? $blank;
    $acc[$r['key']]['pay'] = array_map('floatval', (array) $r['cells']);
}

// ---------------------------------------------------------------- 3. So từng ngày
$money = function ($v) {
    return number_format((float) $v, 0, ',', '.');
};
$same = function ($a, $b) {
    return abs((float) $a - (float) $b) < 0.5;
};
$days = array_unique(array_merge(array_keys($src), array_keys($acc)));
sort($days);
$tot = ['s' => $blank, 'a' => $blank];
$diffs = [];
printf("%-11s %9s %9s %16s %16s %14s %14s  %s\n", 'Ngày', 'Phiếu BH', 'Phiếu KT', 'Tiền hàng BH', 'Tiền hàng KT', 'Thuế BH', 'Thuế KT', 'Kết quả');
foreach ($days as $day) {
    $s = $src[$day] ?? $blank;
    $a = $acc[$day] ?? $blank;
    $why = [];
    if ($s['sale'] !== $a['sale']) {
        $why[] = "phiếu bán {$s['sale']}≠{$a['sale']}";
    }
    if ($s['ret'] !== $a['ret']) {
        $why[] = "phiếu trả {$s['ret']}≠{$a['ret']}";
    }
    if (!$same($s['amount'], $a['amount'])) {
        $why[] = 'tiền hàng lệch ' . $money($s['amount'] - $a['amount']);
    }
    if (!$same($s['tax'], $a['tax'])) {
        $why[] = 'thuế lệch ' . $money($s['tax'] - $a['tax']);
    }
    foreach (array_unique(array_merge(array_keys($s['pay']), array_keys($a['pay']))) as $mth) {
        if (!$same($s['pay'][$mth] ?? 0, $a['pay'][$mth] ?? 0)) {
            $why[] = "thu {$mth} lệch " . $money(($s['pay'][$mth] ?? 0) - ($a['pay'][$mth] ?? 0));
        }
        $tot['s']['pay'][$mth] = ($tot['s']['pay'][$mth] ?? 0) + ($s['pay'][$mth] ?? 0);
        $tot['a']['pay'][$mth] = ($tot['a']['pay'][$mth] ?? 0) + ($a['pay'][$mth] ?? 0);
    }
    foreach (['sale', 'ret', 'amount', 'tax'] as $k) {
        $tot['s'][$k] += $s[$k];
        $tot['a'][$k] += $a[$k];
    }
    if ($why) {
        $diffs[$day] = $why;
    }
    printf(
        "%-11s %9s %9s %16s %16s %14s %14s  %s\n",
        $day,
        $s['sale'] . '+' . $s['ret'],
        $a['sale'] . '+' . $a['ret'],
        $money($s['amount']),
        $money($a['amount']),
        $money($s['tax']),
        $money($a['tax']),
        $why ? 'LỆCH: ' . implode('; ', $why) : 'khớp'
    );
}
printf(
    "%-11s %9s %9s %16s %16s %14s %14s\n",
    'TỔNG',
    $tot['s']['sale'] . '+' . $tot['s']['ret'],
    $tot['a']['sale'] . '+' . $tot['a']['ret'],
    $money($tot['s']['amount']),
    $money($tot['a']['amount']),
    $money($tot['s']['tax']),
    $money($tot['a']['tax'])
);
echo "  (cột Phiếu: số phiếu bán + số phiếu trả; BH = hệ thống bán hàng, KT = kế toán; tiền hàng và thuế là số thuần sau khi trừ trả lại)\n";

echo "\nTiền thu thuần theo hình thức (thu của khách − trả lại khách):\n";
ksort($tot['s']['pay']);
foreach (array_unique(array_merge(array_keys($tot['s']['pay']), array_keys($tot['a']['pay']))) as $mth) {
    $sv = $tot['s']['pay'][$mth] ?? 0;
    $av = $tot['a']['pay'][$mth] ?? 0;
    printf("  %-10s bán hàng %16s   kế toán %16s   %s\n", $mth, $money($sv), $money($av), $same($sv, $av) ? 'khớp' : 'LỆCH ' . $money($sv - $av));
}

$errors = (array) $err['data'];
if ($errors) {
    echo "\nChứng từ đang LỖI bên kế toán (chưa ghi sổ, là nguyên nhân lệch hay gặp nhất): " . count($errors) . "\n";
    foreach (array_slice($errors, 0, 15) as $e) {
        echo "  ✗ {$e['docDate']} {$e['docNo']}: {$e['errorMessage']}\n";
    }
}

echo "\n" . ($diffs ? 'KẾT LUẬN: LỆCH ở ' . count($diffs) . ' ngày.' : 'KẾT LUẬN: KHỚP toàn bộ ' . count($days) . ' ngày.') . "\n";
if ($diffs) {
    echo "Gợi ý: (1) chạy lại day-ke-toan.php cho khoảng ngày này để gửi bù phiếu mới hoặc phiếu đã sửa; (2) xử lý chứng từ lỗi ở màn Định khoản tự động (ACC14) rồi bấm Ghi sổ lại.\n";
}
exit($diffs ? 2 : 0);
