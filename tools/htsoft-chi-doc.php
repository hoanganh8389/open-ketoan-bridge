<?php
/**
 * ĐỌC (chỉ đọc) database HTsoft để phân tích nghiệp vụ kế toán — KHÔNG ghi gì.
 *
 * Dùng thông tin kết nối có sẵn trong tgs_htsoft_connector/config.php (không in mật khẩu ra).
 * Từ chối mọi câu lệnh không phải SELECT; mở kết nối ở chế độ ReadOnly; giới hạn thời gian và số dòng.
 *
 *   # Một câu truy vấn tuỳ ý (chỉ SELECT):
 *   php wp-content/plugins/open-ketoan-bridge/tools/htsoft-chi-doc.php vat "SELECT TOP 5 name FROM sys.tables"
 *
 *   # Lấy cả bộ phục vụ phân tích kế toán, ghi ra thư mục (mặc định D:\htsoft-src\notes\db):
 *   php wp-content/plugins/open-ketoan-bridge/tools/htsoft-chi-doc.php vat --bo-ke-toan
 *   php wp-content/plugins/open-ketoan-bridge/tools/htsoft-chi-doc.php vat --bo-ke-toan --out=D:\htsoft-src\notes\db
 *
 * Tham số đầu: vat = database thuế (db_vat) · main = database nội bộ.
 *
 * Kết quả chứa cấu trúc và thủ tục của phần mềm bên thứ ba: để ở ổ D, KHÔNG đưa lên GitHub.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$which = ($argv[1] ?? '') === 'vat' ? 'vat' : (($argv[1] ?? '') === 'main' ? 'main' : '');
if ($which === '' || empty($argv[2])) {
    fwrite(STDERR, "Dùng: php htsoft-chi-doc.php <vat|main> \"<SELECT …>\" [số dòng tối đa]\n  hoặc: php htsoft-chi-doc.php <vat|main> --bo-ke-toan [--out=<thư mục>]\n");
    exit(1);
}

$config = require dirname(__DIR__, 2) . '/tgs_htsoft_connector/config.php';
$key = $which === 'vat' ? 'db_vat' : (isset($config['db_main']) ? 'db_main' : 'db');
$c = $config[$key] ?? null;
if (!is_array($c) || empty($c['server'])) {
    fwrite(STDERR, "Không có cấu hình '{$key}' trong config.php (các khoá: " . implode(', ', array_keys($config)) . ").\n");
    exit(1);
}

function tgs_ht_only_select($sql)
{
    $bare = preg_replace('/--[^\n]*|\/\*.*?\*\//s', ' ', $sql);

    return (bool) preg_match('/^\s*(SELECT|WITH)\b/i', $bare)
        && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|MERGE|CREATE|GRANT|REVOKE|EXEC|EXECUTE|INTO|BACKUP|RESTORE|SHUTDOWN)\b/i', $bare)
        && strpos($bare, ';') === false;
}

try {
    $pdo = new PDO(
        "sqlsrv:Server={$c['server']};Database={$c['database']};LoginTimeout=15;TrustServerCertificate=1;ApplicationIntent=ReadOnly",
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 90]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Không kết nối được: ' . $e->getMessage() . "\n");
    exit(1);
}

$run = function ($sql, $max = 500) use ($pdo) {
    if (!tgs_ht_only_select($sql)) {
        throw new RuntimeException('Chỉ cho phép một câu SELECT.');
    }
    $rows = [];
    foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $r) {
        $rows[] = $r;
        if (count($rows) >= $max) {
            break;
        }
    }

    return $rows;
};
$line = static function (array $r) {
    return implode(' | ', array_map(static function ($v) {
        return $v === null ? '' : trim(preg_replace('/[ \t]+/', ' ', (string) $v));
    }, $r));
};

/* ── Chế độ lấy nội dung một số thủ tục: --sp=ten1,ten2,… ─────────────────── */
if (strpos($argv[2], '--sp=') === 0) {
    $dir = 'D:/htsoft-src/notes/db/' . $which . '/sp';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $ok = 0;
    $names = array_filter(array_map('trim', explode(',', substr($argv[2], 5))));
    foreach ($names as $name) {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $name);
        $rows = $run("SELECT m.definition FROM sys.sql_modules m JOIN sys.objects o ON o.object_id = m.object_id WHERE o.name = '{$name}'", 1);
        if ($rows && $rows[0]['definition'] !== null) {
            file_put_contents($dir . '/' . $name . '.sql', (string) $rows[0]['definition']);
            $ok++;
        } else {
            echo "(không có) {$name}\n";
        }
    }
    echo "Đã lưu {$ok}/" . count($names) . " thủ tục vào {$dir}\n";
    exit(0);
}

/* ── Chế độ 1: một câu truy vấn (thêm --luu=<tên file> để ghi ra thư mục data thay vì in) ── */
if ($argv[2] !== '--bo-ke-toan') {
    $save_as = '';
    foreach (array_slice($argv, 3) as $a) {
        if (strpos($a, '--luu=') === 0) {
            $save_as = preg_replace('/[^A-Za-z0-9_.\-]/', '', substr($a, 6));
        }
    }
    if ($save_as !== '') {
        try {
            $rows = $run($argv[2], 20000);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Lỗi: ' . $e->getMessage() . "\n");
            exit(1);
        }
        $dir = 'D:/htsoft-src/notes/db/' . $which . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $text = $rows ? implode(' | ', array_keys($rows[0])) . "\n" . implode("\n", array_map($line, $rows)) . "\n" : "(không có dòng nào)\n";
        file_put_contents($dir . '/' . $save_as, $text);
        echo "Đã lưu " . count($rows) . " dòng vào {$dir}/{$save_as}\n";
        exit(0);
    }
    try {
        $rows = $run($argv[2], max(1, (int) ($argv[3] ?? 200)));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Lỗi: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if ($rows) {
        echo implode(' | ', array_keys($rows[0])), "\n";
    }
    foreach ($rows as $r) {
        echo $line($r), "\n";
    }
    fwrite(STDERR, "[{$c['database']}] " . count($rows) . " dòng\n");
    exit(0);
}

/* ── Chế độ 2: cả bộ phục vụ phân tích kế toán ──────────────────────────── */
$out = 'D:/htsoft-src/notes/db';
foreach (array_slice($argv, 3) as $a) {
    if (strpos($a, '--out=') === 0) {
        $out = rtrim(str_replace('\\', '/', substr($a, 6)), '/');
    }
}
$out .= '/' . $which;
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "Không tạo được thư mục {$out}\n");
    exit(1);
}
if (!is_dir($out . '/sp')) {
    mkdir($out . '/sp', 0777, true);
}
$save = static function ($name, $text) use ($out) {
    file_put_contents($out . '/' . $name, $text);
    echo '  ' . str_pad($name, 46) . number_format(strlen($text)) . " byte\n";
};
$table = static function (array $rows) use ($line) {
    if (!$rows) {
        return "(không có dòng nào)\n";
    }

    return implode(' | ', array_keys($rows[0])) . "\n" . implode("\n", array_map($line, $rows)) . "\n";
};

echo "Đọc database {$c['database']} → {$out}\n";

// 1. Danh sách bảng + số dòng (ước lượng từ thống kê, không quét bảng)
$save('01-bang.txt', $table($run(
    "SELECT t.name AS bang, SUM(p.rows) AS so_dong
       FROM sys.tables t JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
      GROUP BY t.name ORDER BY t.name",
    5000
)));

// 2. Cột của các bảng liên quan kế toán / thuế / danh mục cấu hình
$save('02-cot-bang-ke-toan.txt', $table($run(
    "SELECT c.TABLE_NAME AS bang, c.COLUMN_NAME AS cot, c.DATA_TYPE AS kieu, c.CHARACTER_MAXIMUM_LENGTH AS dai,
            c.NUMERIC_PRECISION AS so, c.NUMERIC_SCALE AS le, c.IS_NULLABLE AS rong
       FROM INFORMATION_SCHEMA.COLUMNS c
      WHERE c.TABLE_NAME LIKE '%LEDGER%' OR c.TABLE_NAME LIKE '%ACCOUNT%' OR c.TABLE_NAME LIKE '%PERIOD%'
         OR c.TABLE_NAME LIKE '%VAT%' OR c.TABLE_NAME LIKE '%TAX%' OR c.TABLE_NAME LIKE '%THUE%'
         OR c.TABLE_NAME LIKE '%TAIKHOAN%' OR c.TABLE_NAME LIKE '%LYDO%' OR c.TABLE_NAME LIKE '%KHOANMUC%'
         OR c.TABLE_NAME LIKE '%CLOSING%' OR c.TABLE_NAME LIKE '%BALANCE%' OR c.TABLE_NAME LIKE '%TOKHAI%'
         OR c.TABLE_NAME LIKE '%TUYCHON%' OR c.TABLE_NAME LIKE 'ACC[_]%' OR c.TABLE_NAME LIKE '%LOCK%'
      ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
    20000
)));

// 3. Danh sách thủ tục kế toán
$save('03-thu-tuc-ke-toan.txt', $table($run(
    "SELECT p.name AS thu_tuc, p.modify_date AS sua_lan_cuoi, LEN(m.definition) AS do_dai
       FROM sys.procedures p JOIN sys.sql_modules m ON m.object_id = p.object_id
      WHERE p.name LIKE 'acc[_]%' OR p.name LIKE 'Acc[_]%' OR p.name LIKE 'sp[_]VAT%' OR p.name LIKE '%EVAT%'
      ORDER BY p.name",
    5000
)));

// 4. Nội dung các thủ tục cần đọc để biết luật định khoản và cách dựng báo cáo thuế
$wanted = [
    'acc_InsertOrUpdateLegerFromBANHOADON', 'acc_InsertOrUpdateLegerFromBANHOADON1',
    'acc_InsertOrUpdateLegerFromBANHOANTRA', 'acc_InsertOrUpdateLegerFromBANHOANTRA1',
    'acc_InsertOrUpdateLegerFromBANPTT', 'acc_InsertOrUpdateLegerFromBANPTT1',
    'acc_InsertOrUpdateLegerFromMUAHOADON', 'acc_InsertOrUpdateLegerFromMUAHOADON1',
    'acc_InsertOrUpdateLegerFromMUAHOANTRA', 'acc_InsertOrUpdateLegerFromMUAHOANTRA1',
    'acc_InsertOrUpdateLegerFromMUAPTT', 'acc_InsertOrUpdateLegerFromMUAPTT1',
    'acc_InsertOrUpdateLegerFromCHIPHI', 'acc_InsertOrUpdateLegerFromCHIPHI1',
    'acc_InsertOrUpdateLegerFromTHU', 'acc_InsertOrUpdateLegerFromTHU1',
    'acc_InsertOrUpdateLegerFromCHUYENTIEN', 'acc_InsertOrUpdateLegerFromCHUYENTIEN1',
    'acc_InsertOrUpdateLegerFromDIEUCHINH', 'acc_InsertOrUpdateLegerFromDIEUCHINH1',
    'acc_InsertOrUpdateLegerFromCHUYENKHO', 'acc_InsertOrUpdateLegerFromCHUYENKHO1',
    'acc_LoadBaocaothue', 'Acc_LoadToKhaiVAT', 'acc_LoadTongHopThue', 'acc_ThongTinToKhaiThue_SelectOne',
    'acc_LoadSoCaiTkS03b', 'acc_LoadgenerajournalS03A', 'acc_LoadCashOnHandBookS03a1',
    'acc_LoadAccountBalance_ByDateTime', 'acc_InsertOrUpdateAccountBalancebyPeriod',
    'acc_InsertOrUpdateLedger', 'acc_InsertOrUpdateLedgerDetailListInfo',
    'acc_LoadLedgerListCheckLech', 'acc_SaveLockPeriod', 'acc_InsertLegerToRpt',
    'adsp_SaveChungTuDaChinhSuaVaoVAT', 'acc_SaveDocVATBANHOADON', 'acc_SaveDocVATBANCTHOADON',
    'sp_VAT_InsertVATRa', 'sp_VAT_InsertVATvao', 'sp_InsertVATRaCT',
];
$found = 0;
foreach ($wanted as $name) {
    $rows = $run("SELECT m.definition FROM sys.sql_modules m JOIN sys.objects o ON o.object_id = m.object_id WHERE o.name = '" . str_replace("'", '', $name) . "'", 1);
    if ($rows && $rows[0]['definition'] !== null) {
        $save('sp/' . $name . '.sql', (string) $rows[0]['definition']);
        $found++;
    } else {
        echo '  (không có) ' . $name . "\n";
    }
}

echo "\nXong: {$found}/" . count($wanted) . " thủ tục. Bước sau: mở 01-bang.txt để biết tên bảng cấu hình rồi đọc dữ liệu của chúng bằng chế độ một câu truy vấn.\n";
