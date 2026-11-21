<?php
/**
 * DANH MỤC HÀNG ↔ PHẦN MỀM KẾ TOÁN (xem includes/class-tgs-acc-catalog-sync.php).
 *
 * Chuyển sang khai danh mục hàng ở kế toán, theo thứ tự:
 *
 *   1. php wp-content/plugins/tgs_accounting_bridge/tools/danh-muc-hang.php nap --dry
 *        đếm dữ liệu sẽ nạp (bảng giá, gán chi nhánh, mặt hàng + ĐVT + cờ, dòng giá), KHÔNG gửi
 *   2. php …/danh-muc-hang.php nap
 *        nạp sang kế toán (POST /api/v1/catalog/import; khoá API cần catalog:write). Chạy lại được: ghi đè đúng dòng.
 *        Tên + nhóm hàng chỉ gửi cho mã kế toán CHƯA có (tên bên kế toán giữ nguyên).
 *   3. php …/danh-muc-hang.php keo --dry --full
 *        đối chiếu: kéo toàn bộ từ kế toán, đếm cột nào sẽ đổi ở bảng bên này, KHÔNG ghi. Phải gần như 0 khác biệt.
 *   4. php …/danh-muc-hang.php bat
 *        bật "danh mục lấy từ kế toán": khoá màn sửa bên này + cron 5 phút kéo về. Tắt: … tat
 *
 *   keo [--full]    kéo thay đổi ngay (bỏ --dry là ghi thật); --mau in 5 dòng mẫu mỗi cột khác biệt
 *   trang-thai      công tắc, con trỏ, lần kéo gần nhất
 *   --url, --key    thay cho TGS_KETOAN_API_URL / TGS_KETOAN_API_KEY
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$cmd = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
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

$api = new TGS_Acc_Api_Client((string) ($opt['url'] ?? ''), (string) ($opt['key'] ?? ''));
$out = function ($s) {
    fwrite(STDOUT, $s . "\n");
};
$fail = function ($s) {
    fwrite(STDERR, $s . "\n");
    exit(1);
};

switch ($cmd) {
    case 'nap':
        // Mã kế toán đã có → không gửi tên / nhóm của các mã đó.
        $known = [];
        if (!$api->ready()) {
            $fail('Chưa cấu hình kết nối phần mềm kế toán.');
        }
        $res = $api->get('/api/v1/products', ['page' => 1, 'pageSize' => 1000000]);
        if (is_wp_error($res)) {
            $fail('Không đọc được danh mục mặt hàng bên kế toán: ' . $res->get_error_message());
        }
        foreach ((array) $res['data'] as $p) {
            $known[] = (string) $p['sku'];
        }
        $d = TGS_Acc_Catalog_Sync::build_import($known);
        $new = count(array_filter($d['products'], static function ($p) { return isset($p['name']); }));
        $noUnits = count(array_filter($d['products'], static function ($p) { return empty($p['units']); }));
        $out(sprintf('Bảng giá %d · gán chi nhánh %d · mặt hàng %d (kế toán chưa có %d, không có ĐVT tỉ lệ 1: %d) · dòng giá %d',
            count($d['priceLists']), count($d['branches']), count($d['products']), $new, $noUnits, count($d['prices'])));
        foreach ($d['warnings'] as $w) {
            $out('  ! ' . $w);
        }
        if (!$d['products']) {
            $fail('Không đọc được mặt hàng nào bên này — xem cảnh báo ở trên, chưa gửi gì.');
        }
        if (!empty($opt['dry'])) {
            $out('Mặt hàng mẫu: ' . wp_json_encode($d['products'][0] ?? null, JSON_UNESCAPED_UNICODE));
            $out('--dry: chưa gửi.');
            break;
        }
        $send = function (array $body, $label) use ($api, $out, $fail) {
            $r = $api->post_json('/api/v1/catalog/import', $body);
            if (is_wp_error($r)) {
                $fail("{$label}: " . $r->get_error_message());
            }
            $out("{$label}: xong" . (!empty($r['skippedCount']) ? " — bỏ qua {$r['skippedCount']}: " . implode(' | ', array_slice((array) $r['skipped'], 0, 5)) : ''));
        };
        $send(['priceLists' => $d['priceLists'], 'branches' => $d['branches']], 'Bảng giá + gán chi nhánh');
        foreach (array_chunk($d['products'], 2000) as $i => $chunk) {
            $send(['products' => $chunk], 'Mặt hàng lượt ' . ($i + 1));
        }
        foreach (array_chunk($d['prices'], 20000) as $i => $chunk) {
            $send(['prices' => $chunk], 'Dòng giá lượt ' . ($i + 1));
        }
        $out('Xong. Bước tiếp: keo --dry --full để đối chiếu.');
        break;

    case 'keo':
        $r = (new TGS_Acc_Catalog_Sync())->sync(['dry' => !empty($opt['dry']), 'full' => !empty($opt['full']), 'api' => $api]);
        if (is_wp_error($r)) {
            $fail($r->get_error_message());
        }
        $out(($r['dry'] ? '[ĐỐI CHIẾU, không ghi] ' : '') . "{$r['skus']} mã hàng, {$r['ms']} ms");
        foreach ($r['tables'] as $t => $s) {
            arsort($s['fields']);
            $f = implode(', ', array_map(static function ($k, $n) { return "{$k} {$n}"; }, array_keys($s['fields']), $s['fields']));
            $out("  {$t}: thêm {$s['insert']}, sửa {$s['update']}, xoá {$s['delete']}" . (!empty($s['skip_new']) ? ", bỏ qua {$s['skip_new']} mã mới chưa khai ĐVT" : '') . ($f !== '' ? " — cột: {$f}" : ''));
        }
        if (!empty($opt['mau'])) {
            foreach ($r['samples'] as $t => $cols) {
                foreach ($cols as $col => $rows) {
                    foreach ($rows as [$id, $old, $new]) {
                        $out("    {$t}.{$col} {$id}: " . wp_json_encode($old, JSON_UNESCAPED_UNICODE) . ' → ' . wp_json_encode($new, JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }
        foreach ($r['notes'] as $n) {
            $out('  · ' . $n);
        }
        if ($r['noteCount'] > count($r['notes'])) {
            $out('  · … còn ' . ($r['noteCount'] - count($r['notes'])) . ' ghi chú');
        }
        break;

    case 'bat':
    case 'tat':
        if (defined('TGS_ACC_CATALOG_FROM_ERP')) {
            $fail('Công tắc đang đặt bằng hằng TGS_ACC_CATALOG_FROM_ERP trong wp-config.php — sửa ở đó.');
        }
        TGS_Acc_Config::save(['catalog_from_erp' => $cmd === 'bat' ? '1' : '0']);
        $out($cmd === 'bat' ? 'Đã BẬT: danh mục hàng lấy từ kế toán, màn sửa bên này khoá; cron 5 phút kéo về.' : 'Đã TẮT: sửa danh mục hàng bên này như cũ, ngừng kéo về.');
        break;

    case 'trang-thai':
        $out('Danh mục lấy từ kế toán: ' . (TGS_Acc_Catalog_Sync::enabled() ? 'BẬT' : 'tắt'));
        $out('Con trỏ: ' . wp_json_encode(get_site_option(TGS_Acc_Catalog_Sync::CURSOR, []), JSON_UNESCAPED_UNICODE));
        $out('Lần kéo gần nhất: ' . wp_json_encode(TGS_Acc_Catalog_Sync::last(), JSON_UNESCAPED_UNICODE));
        break;

    default:
        $out('Dùng: danh-muc-hang.php nap|keo|bat|tat|trang-thai [--dry] [--full] — xem đầu tệp.');
}
