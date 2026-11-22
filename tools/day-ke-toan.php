<?php
/**
 * ĐẨY CHỨNG TỪ của một shop sang phần mềm kế toán.
 *
 *   php wp-content/plugins/open-ketoan-bridge/tools/day-ke-toan.php --blog=95 --from=2026-10-01 --to=2026-10-09 --dry
 *   php wp-content/plugins/open-ketoan-bridge/tools/day-ke-toan.php --blog=95 --from=2026-10-01 --to=2026-10-09
 *
 *   --dry        chỉ dựng chứng từ, in tổng kết + chứng từ đầu tiên, KHÔNG gửi
 *   --force      gửi lại cả chứng từ không đổi
 *   --url, --key thay cho TGS_KETOAN_API_URL / TGS_KETOAN_API_KEY trong wp-config.php
 *
 * Chỉ ĐỌC sổ bán hàng; ghi duy nhất vào bảng nhật ký gửi {base_prefix}tgs_acc_push_log.
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
// [2026-11-23 02:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-4 — tệp chính đã đổi tên open-ketoan-bridge.php (tên cũ tgs-accounting-bridge.php → lỗi nghiêm trọng).
require_once dirname(__DIR__) . '/open-ketoan-bridge.php';

$shop = (int) ($opt['blog'] ?? 0);

// --days=N: gửi N ngày gần nhất (tính cả hôm nay) thay cho --from/--to. Dùng khi chạy theo lịch.
if (!empty($opt['days']) && empty($opt['from'])) {
    $n = max(1, (int) $opt['days']);
    $opt['to'] = current_time('Y-m-d');
    $opt['from'] = date('Y-m-d', strtotime($opt['to'] . ' -' . ($n - 1) . ' days'));
}

/*
 * --all: chạy lần lượt cho mọi shop áp dụng thuế (danh sách của tgs-bc-tk), mỗi shop một
 * tiến trình riêng (để hằng tên bảng gắn đúng shop). Dùng cho lịch chạy tự động, ví dụ
 * 15 phút một lần:   php …/day-ke-toan.php --all --days=2
 * Bán hàng không phụ thuộc: kế toán dừng thì lần chạy sau gửi bù (nhật ký gửi giữ trạng thái).
 */
if (!empty($opt['all'])) {
    $ids = class_exists('TGS_BCTK_Vat_Shops') ? array_map('intval', (array) TGS_BCTK_Vat_Shops::active_blog_ids()) : [];
    if (!$ids) {
        fwrite(STDERR, "Không có shop nào trong danh sách áp dụng thuế (tgs-bc-tk).\n");
        exit(1);
    }
    $worst = 0;
    foreach ($ids as $id) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg('--blog=' . $id);
        $cmd .= ' --origin=cron';
        foreach (['from', 'to', 'url', 'key'] as $k) {
            if (!empty($opt[$k])) {
                $cmd .= ' ' . escapeshellarg("--{$k}=" . $opt[$k]);
            }
        }
        foreach (['dry', 'force'] as $k) {
            if (!empty($opt[$k])) {
                $cmd .= ' --' . $k;
            }
        }
        passthru($cmd, $code_one);
        $worst = max($worst, (int) $code_one);
    }
    exit($worst);
}

/*
 * PHẢI KHỞI ĐỘNG WORDPRESS NGAY TRONG SHOP CẦN ĐỌC: nhiều lớp bán hàng dùng hằng tên
 * bảng gắn với shop LÚC NẠP (TGS_TABLE_*), không đổi theo switch_to_blog(). Lần chạy
 * đầu chỉ tra tên miền + đường dẫn của shop rồi tự chạy lại trong shop đó.
 */
if (is_multisite() && $shop > 0 && get_current_blog_id() !== $shop) {
    if (getenv('TGS_ACC_REEXEC')) {
        fwrite(STDERR, "Không khởi động được trong shop #{$shop} (đang ở #" . get_current_blog_id() . ").\n");
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
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    foreach (array_slice($argv, 1) as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    passthru($cmd, $exit_code);
    exit($exit_code);
}

$from = (string) ($opt['from'] ?? '');
$to = (string) ($opt['to'] ?? $from);
if ($shop <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Dùng: php day-ke-toan.php --blog=<id shop> --from=YYYY-MM-DD [--to=YYYY-MM-DD] [--dry] [--force] [--url=…] [--key=…]
  hoặc: php day-ke-toan.php --all --days=2\n");
    exit(1);
}
if (!TGS_Acc_Source::ready()) {
    fwrite(STDERR, "Thiếu plugin tgs-bc-tk (bản có TGS_BCTK_Ajax::vat_rows_for_export).\n");
    exit(1);
}

$dry = !empty($opt['dry']);
$client = new TGS_Acc_Api_Client((string) ($opt['url'] ?? ''), (string) ($opt['key'] ?? ''));
if (!$dry && !$client->ready()) {
    fwrite(STDERR, "Chưa có địa chỉ / khoá phần mềm kế toán: thêm --url=… --key=… hoặc khai TGS_KETOAN_API_URL, TGS_KETOAN_API_KEY.\n");
    exit(1);
}

$code = (string) get_option('tgs_site_code', 'BLOG' . $shop);
echo "═══ SHOP #{$shop} ({$code}) — {$from} → {$to} — " . ($dry ? 'CHỈ DỰNG, KHÔNG GỬI' : 'GỬI SANG KẾ TOÁN') . " ═══\n";

try {
    $origin = in_array($opt['origin'] ?? '', ['cli', 'cron'], true) ? $opt['origin'] : 'cli';
    $s = TGS_Acc_Pusher::push($shop, $from, $to, ['dry' => $dry, 'force' => !empty($opt['force']), 'client' => $client, 'origin' => $origin]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Lỗi: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Dựng được: {$s['built']} chứng từ · không dựng được: " . count($s['failed_build']) . " · không đổi (bỏ qua): {$s['skipped']} · cần gửi: {$s['to_send']}\n";
foreach (array_slice($s['failed_build'], 0, 10) as $line) {
    echo "  ✗ {$line}\n";
}
if ($dry) {
    if (!empty($s['sample'])) {
        echo "\n── Chứng từ đầu tiên ──\n" . wp_json_encode($s['sample'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    exit(0);
}
echo "Kết quả bên kế toán: ghi sổ {$s['posted']} · đã có, không đổi {$s['unchanged']} · lỗi {$s['error']} · huỷ {$s['cancelled']}\n";
foreach (array_slice($s['errors'], 0, 15) as $line) {
    echo "  ✗ {$line}\n";
}
exit($s['error'] > 0 ? 2 : 0);
