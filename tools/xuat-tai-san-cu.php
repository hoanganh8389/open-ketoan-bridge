<?php
/**
 * XUẤT danh sách tài sản cố định của phần mềm kế toán cũ (HTsoft, database thuế) ra tệp CSV đúng mẫu
 * "Nhập tài sản từ Excel" của phần mềm kế toán mới (tgs-ketoan, màn Tài sản cố định → Nhập từ Excel).
 *
 * CHỈ ĐỌC: dùng kết nối ReadOnly như htsoft-chi-doc.php, chỉ chạy câu SELECT, không in mật khẩu.
 *
 *   php wp-content/plugins/open-ketoan-bridge/tools/xuat-tai-san-cu.php --den=2026-09-30
 *   php wp-content/plugins/open-ketoan-bridge/tools/xuat-tai-san-cu.php --den=2026-09-30 --out=D:\htsoft-src\export\tai-san.csv
 *
 * --den: ngày chốt chuyển sổ (thường là ngày cuối tháng đã khấu hao xong ở phần mềm cũ). Mỗi tài sản lấy trạng thái
 *        ở dòng sổ tài sản gần nhất tới hết ngày đó: nguyên giá, giá trị tính khấu hao, luỹ kế, số tháng còn lại,
 *        mức tháng, chi nhánh đang dùng (sau điều chuyển). Đã ghi giảm tới ngày đó → trạng thái "Đã ghi giảm".
 *        Phần mềm mới trích tiếp từ tháng sau ngày chốt.
 * Chi nhánh = mã ở cột TEN của bảng SHOWROOM. Phân bổ chi phí lấy từ ACC_FixedAssetDetailAllocation.
 * Công cụ dụng cụ (Acc_InstrumentsAndTools) không xuất: dữ liệu hiện có đều đã phân bổ xong.
 *
 * Tệp chứa dữ liệu kế toán: để ở ổ D, KHÔNG đưa lên GitHub.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$cutoff = '';
$out = '';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--den=') === 0) {
        $cutoff = substr($a, 6);
    } elseif (strpos($a, '--out=') === 0) {
        $out = substr($a, 6);
    }
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
    fwrite(STDERR, "Dùng: php xuat-tai-san-cu.php --den=YYYY-MM-DD [--out=<tệp .csv>]\n");
    exit(1);
}
if ($out === '') {
    $out = 'D:/htsoft-src/export/tai-san-' . $cutoff . '.csv';
}
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}

$config = require dirname(__DIR__, 2) . '/tgs_htsoft_connector/config.php';
$c = $config['db_vat'] ?? null;
if (!is_array($c) || empty($c['server'])) {
    fwrite(STDERR, "Không có cấu hình 'db_vat' trong config.php.\n");
    exit(1);
}
try {
    $pdo = new PDO(
        "sqlsrv:Server={$c['server']};Database={$c['database']};LoginTimeout=15;TrustServerCertificate=1;ApplicationIntent=ReadOnly",
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 300]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Không kết nối được: ' . $e->getMessage() . "\n");
    exit(1);
}
$select = static function ($sql) use ($pdo) {
    if (!preg_match('/^\s*SELECT\b/i', $sql) || preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|MERGE|CREATE|EXEC|INTO)\b/i', $sql) || strpos($sql, ';') !== false) {
        throw new RuntimeException('Chỉ cho phép SELECT.');
    }
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
};

$next = date('Y-m-d', strtotime($cutoff . ' +1 day'));
$assets = $select("
    SELECT f.FixedAssetID AS id, f.FixedAssetCode AS code, f.FixedAssetName AS name, f.Quantity AS qty,
           CONVERT(varchar(10), f.RefDate, 120) AS acquired, CONVERT(varchar(10), f.DepreciationDate, 120) AS start_date,
           f.LifeTimeInMonth AS life, f.OrgPrice AS card_cost, f.DepreciationAmount AS card_dep, f.AccumDepreciationAmount AS card_acc,
           f.MonthlyDepreciationAmount AS card_monthly, f.OrgPriceAccount AS cost_acc, f.DepreciationAccount AS dep_acc,
           f.IsNotDepreciation AS no_dep, f.IsLimitDepreciationAmount AS tax_limit, f.MonthlyDepreciationAmountByIncomeTax AS tax_monthly,
           sb.TEN AS branch_card,
           L.OrgPrice AS l_cost, L.DepreciationAmount AS l_dep, L.AccumDepreciationAmount AS l_acc, L.MonthlyDepreciationAmount AS l_monthly,
           L.LifeTimeInMonth AS l_life, L.LifeTimeRemainingInMonth AS l_left, L.DepreciationAccount AS l_dep_acc, sl.TEN AS branch_now,
           L.RefType AS l_type, CONVERT(varchar(10), L.PostedDate, 120) AS l_posted,
           D.disposed
      FROM ACC_FixedAsset f
      LEFT JOIN SHOWROOM sb ON sb.SRID = f.BranchID
      OUTER APPLY (SELECT TOP 1 l.* FROM ACC_FixedAssetLedger l
                    WHERE l.FixedAssetID = f.FixedAssetID AND l.PostedDate < '{$next}'
                    ORDER BY l.PostedDate DESC, l.RefOrder DESC) L
      LEFT JOIN SHOWROOM sl ON sl.SRID = L.BranchID
      OUTER APPLY (SELECT MIN(CONVERT(varchar(10), x.RefDate, 120)) AS disposed FROM ACC_FixedAssetLedger x
                    WHERE x.FixedAssetID = f.FixedAssetID AND x.RefType = 102 AND x.PostedDate < '{$next}') D
     ORDER BY f.FixedAssetCode");

$allocRows = $select("
    SELECT a.FixedAssetID AS id, a.AllocationRate AS rate, a.CostAccount AS acc, s.TEN AS branch
      FROM ACC_FixedAssetDetailAllocation a LEFT JOIN SHOWROOM s ON s.SRID = a.SRID
     ORDER BY a.FixedAssetID, a.SortOrder");
$allocs = [];
foreach ($allocRows as $r) {
    $allocs[$r['id']][] = $r;
}

$num = static function ($v) {
    if ($v === null || $v === '') {
        return '';
    }
    $s = number_format((float) $v, 4, '.', '');
    return rtrim(rtrim($s, '0'), '.');
};
$category = ['2111' => 'TS-NHA', '2112' => 'TS-MAY', '2113' => 'TS-XE', '2114' => 'TS-QL', '2118' => 'TS-KHAC', '2135' => 'TS-PM', '2138' => 'TS-PM'];
// Sổ cũ có tài sản ghi vào tài khoản tổng hợp; phần mềm mới bắt buộc tài khoản chi tiết.
$generalToDetail = ['211' => '2118', '213' => '2138', '214' => '2141'];

$headers = ['Mã tài sản', 'Tên tài sản', 'Nhóm', 'Loại tài sản', 'Chi nhánh', 'Ngày ghi tăng', 'Ngày bắt đầu khấu hao', 'Nguyên giá', 'Giá trị tính khấu hao',
    'Số tháng sử dụng', 'Luỹ kế đã khấu hao', 'Luỹ kế tính tới ngày', 'Số tháng còn lại', 'Mức khấu hao tháng', 'Mức khấu hao tháng theo thuế',
    'TK nguyên giá', 'TK hao mòn', 'TK chi phí', 'Bộ phận', 'Khoản mục', 'Phân bổ', 'Trạng thái', 'Ngày ghi giảm', 'Đơn vị tính', 'Số lượng', 'Ghi chú'];

$fh = fopen($out, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, $headers);
$t = ['n' => 0, 'in_use' => 0, 'disposed' => 0, 'stopped' => 0, 'cost' => 0.0, 'acc' => 0.0, 'cost_in_use' => 0.0, 'acc_in_use' => 0.0, 'no_branch' => 0, 'split' => 0, 'over' => 0, 'over_amount' => 0.0, 'lag' => 0];
$lag = [];
foreach ($assets as $a) {
    $hasLedger = $a['l_cost'] !== null;
    $cost = $hasLedger ? (float) $a['l_cost'] : (float) $a['card_cost'];
    $dep = $hasLedger ? (float) $a['l_dep'] : (float) $a['card_dep'];
    $acc = $hasLedger ? (float) $a['l_acc'] : (float) $a['card_acc'];
    $life = (int) round((float) ($a['l_life'] ?: $a['life']));
    $notes = [];
    if ($life < 1) {
        // Sổ cũ để 0 tháng (thường là tài sản đã khấu hao hết): phần mềm mới cần ít nhất 1 tháng.
        $notes[] = 'Sổ cũ ghi 0 tháng sử dụng';
        $life = 1;
    }
    $monthly = (float) ($a['l_monthly'] ?: $a['card_monthly']);
    $branch = trim((string) ($a['branch_now'] ?: $a['branch_card']));
    $status = $a['disposed'] ? 'Đã ghi giảm' : ((int) $a['no_dep'] === 1 ? 'Tạm ngừng' : 'Đang dùng');
    // Mốc luỹ kế = ngày của dòng sổ tài sản cuối cùng tới ngày chốt (chi nhánh chạy khấu hao chậm thì mốc sớm hơn
    // ngày chốt; phần mềm mới biết còn thiếu những tháng nào). Mới ghi tăng, chưa trích tháng nào: trích từ tháng
    // bắt đầu; nếu khai sẵn luỹ kế thì mốc là ngày trước ngày bắt đầu.
    $onlyIncrease = $hasLedger && (int) $a['l_type'] === 101;
    if ($hasLedger && !$onlyIncrease) {
        $asOf = $a['l_posted'];
    } else {
        $asOf = $acc > 0 ? date('Y-m-d', strtotime($a['start_date'] . ' -1 day')) : '';
    }
    $left = '';
    if ($asOf !== '' && $a['l_left'] !== null) {
        $leftNum = (int) ceil((float) $a['l_left'] - 1e-9);
        if ($leftNum < 0) {
            $notes[] = "Sổ cũ ghi còn {$leftNum} tháng (trích quá thời gian)";
            $leftNum = 0;
        }
        $left = (string) $leftNum;
    }
    if ($acc > max($dep, $cost) + 0.5) {
        $notes[] = 'Sổ cũ trích lố ' . number_format($acc - max($dep, $cost), 0, ',', '.');
        $t['over']++;
        $t['over_amount'] += $acc - max($dep, $cost);
    }

    $costAcc = trim((string) $a['cost_acc']);
    if (isset($generalToDetail[$costAcc])) {
        $notes[] = "Sổ cũ ghi TK {$costAcc}, đổi sang {$generalToDetail[$costAcc]}";
        $costAcc = $generalToDetail[$costAcc];
    }
    $depAcc = trim((string) ($a['l_dep_acc'] ?: $a['dep_acc']));
    if (isset($generalToDetail[$depAcc])) {
        $notes[] = "Sổ cũ ghi TK hao mòn {$depAcc}, đổi sang {$generalToDetail[$depAcc]}";
        $depAcc = $generalToDetail[$depAcc];
    }
    $lines = array_values(array_filter($allocs[$a['id']] ?? [], static function ($l) {
        return (float) $l['rate'] > 0;
    }));
    $expense = $lines ? trim((string) $lines[0]['acc']) : '';
    $split = '';
    if (count($lines) > 1 || ($lines && (trim((string) $lines[0]['branch']) !== $branch || abs((float) $lines[0]['rate'] - 100) > 0.0001))) {
        $split = implode('; ', array_map(static function ($l) use ($num) {
            return trim((string) $l['branch']) . ':' . trim((string) $l['acc']) . ':' . $num($l['rate']);
        }, $lines));
        $t['split']++;
    }
    if ($branch === '') {
        $t['no_branch']++;
    }

    fputcsv($fh, [
        trim((string) $a['code']), trim((string) $a['name']), 'TSCĐ', $category[$costAcc] ?? '', $branch,
        $a['acquired'] ?: $a['start_date'], $a['start_date'], $num($cost), abs($dep - $cost) > 0.005 ? $num($dep) : '',
        $life, $num($acc), $asOf, $left, $num($monthly), (int) $a['tax_limit'] === 1 ? $num($a['tax_monthly']) : '',
        $costAcc, $depAcc, $expense, '', '', $split,
        $status, $a['disposed'] ?: '', '', $num($a['qty'] ?: 1), implode('; ', array_merge($hasLedger ? [] : ['Khai sau ngày chốt'], $notes)),
    ]);
    if ($status === 'Đang dùng' && $asOf !== '' && substr($asOf, 0, 7) < substr($cutoff, 0, 7) && $cost - $acc > 0.5) {
        $m = substr($asOf, 0, 7);
        $lag[$branch] = isset($lag[$branch]) ? min($lag[$branch], $m) : $m;
        $t['lag']++;
    }
    $t['n']++;
    $t['cost'] += $cost;
    $t['acc'] += $acc;
    if ($status === 'Đang dùng') {
        $t['in_use']++;
        $t['cost_in_use'] += $cost;
        $t['acc_in_use'] += $acc;
    } elseif ($status === 'Đã ghi giảm') {
        $t['disposed']++;
    } else {
        $t['stopped']++;
    }
}
fclose($fh);

$fmt = static function ($v) {
    return number_format($v, 0, ',', '.');
};
echo "Đã xuất {$t['n']} tài sản (chốt tới hết {$cutoff}) vào {$out}\n";
echo "  Đang dùng: {$t['in_use']} · đã ghi giảm: {$t['disposed']} · tạm ngừng: {$t['stopped']} · chia chi phí nhiều nơi: {$t['split']}" . ($t['no_branch'] ? " · THIẾU CHI NHÁNH: {$t['no_branch']}" : '') . "\n";
echo '  Tài sản đang dùng: nguyên giá ' . $fmt($t['cost_in_use']) . ' · luỹ kế ' . $fmt($t['acc_in_use']) . ' · còn lại ' . $fmt($t['cost_in_use'] - $t['acc_in_use']) . "\n";
if ($t['lag']) {
    ksort($lag);
    echo "  CHƯA TRÍCH TỚI THÁNG CHỐT: {$t['lag']} tài sản còn giá trị, theo chi nhánh (tháng trích cuối):\n    "
        . implode(', ', array_map(static function ($b, $m) {
            return ($b === '' ? '(không rõ)' : $b) . ' ' . substr($m, 5, 2) . '/' . substr($m, 0, 4);
        }, array_keys($lag), $lag)) . "\n";
    echo "    → Hoặc chạy nốt khấu hao các tháng đó ở phần mềm cũ rồi xuất lại; hoặc nhập như vậy và tính các tháng còn thiếu ở phần mềm mới (không chạy cả hai nơi).\n";
}
if ($t['over']) {
    echo "  CẦN KẾ TOÁN XEM: {$t['over']} tài sản sổ cũ trích lố quá nguyên giá, tổng " . $fmt($t['over_amount']) . " (xem cột Ghi chú)\n";
}
echo "  Đối chiếu với Sổ tài sản cố định của phần mềm cũ tại ngày chốt trước khi nhập.\n";
