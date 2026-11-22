<?php
/**
 * Census MS0 (OKT-TOPO-2, open-setup 80 §4): mã chạy của bridge (includes/, tệp chính) KHÔNG gọi thẳng hàm chỉ có ở multisite —
 * phải qua TGS_Acc_Site. Gọi thẳng = fatal ở WordPress một site (đo thật okt-wp1 23/11).
 *
 *   php tests/ms0-census-test.php      mã thoát 0 = xanh
 */
// [2026-11-23 02:30 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-2 — census MS0.

$root = dirname(__DIR__);
$allow = ['includes/class-tgs-acc-site.php'];
$files = array_merge(glob($root . '/includes/*.php'), [$root . '/open-ketoan-bridge.php']);
$fn = 'get_blog_option|update_blog_option|delete_blog_option|add_blog_option|switch_to_blog|restore_current_blog|get_sites|get_blog_details|wpmu_\w+|get_blog_permalink|get_site_url_by_id';
$hits = [];
foreach ($files as $f) {
    $rel = ltrim(str_replace('\\', '/', substr($f, strlen($root))), '/');
    if (in_array($rel, $allow, true)) {
        continue;
    }
    foreach (token_get_all(file_get_contents($f)) as $i => $t) {
        // chỉ bắt LỜI GỌI hàm toàn cục (T_STRING theo sau là "("), không bắt chuỗi, chú thích, phương thức ->x() / ::x()
        if (is_array($t) && $t[0] === T_STRING && preg_match('/^(' . $fn . ')$/', $t[1])) {
            $hits[] = "{$rel}:{$t[2]} {$t[1]}()";
        }
    }
}
// lọc: T_STRING sau -> hoặc :: là phương thức, không phải hàm toàn cục
$hits = array_values(array_filter($hits, function ($h) use ($root) {
    [$loc, $name] = explode(' ', $h);
    [$rel, $line] = explode(':', $loc);
    $src = file($root . '/' . $rel)[(int) $line - 1] ?? '';
    $n = rtrim($name, '()');
    return (bool) preg_match('/(?<![\w>:$\'"])' . $n . '\s*\(/', $src) && !preg_match('/function\s+' . $n . '\s*\(/', $src);
}));
echo $hits ? "SAI MS0: " . count($hits) . " lời gọi thẳng hàm multisite ngoài TGS_Acc_Site\n  " . implode("\n  ", $hits) . "\n" : "KẾT QUẢ: ĐẠT MS0 — 0 lời gọi thẳng hàm multisite ngoài TGS_Acc_Site (" . count($files) . " tệp)\n";
exit($hits ? 1 : 0);
