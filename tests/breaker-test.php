<?php
/**
 * Kiểm OKT-CAU-DAO (cầu dao theo blog) không cần WordPress.
 *
 *   php tests/breaker-test.php      mã thoát 0 = đạt hết
 */
// [2026-11-22 06:10 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — test mở / gia hạn / đóng cầu dao.

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['BLOG'] = [];
function get_blog_option($b, $k, $d = false) { return $GLOBALS['BLOG'][(int) $b][$k] ?? $d; }
function update_blog_option($b, $k, $v) { $GLOBALS['BLOG'][(int) $b][$k] = $v; return true; }
function delete_blog_option($b, $k) { unset($GLOBALS['BLOG'][(int) $b][$k]); return true; }
function apply_filters($t, $v, ...$a) { return $v; }
require dirname(__DIR__) . '/includes/class-tgs-acc-events.php';

$ok = 0; $fail = 0;
function check($n, $c) { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "SAI: {$n}\n"; } }

check('mặc định đóng', !TGS_Acc_Events::breaker_open(2) && TGS_Acc_Events::breaker(2) === []);
TGS_Acc_Events::trip(2, 'cURL error 28: Connection timed out');
$b = TGS_Acc_Events::breaker(2);
check('lần 1: mở 2 phút', TGS_Acc_Events::breaker_open(2) && $b['fails'] === 1 && abs($b['until'] - time() - 120) <= 1);
check('ghi lý do', strpos($b['msg'], 'timed out') !== false);
check('hết hạn thì coi như đóng (thử lại được)', !TGS_Acc_Events::breaker_open(2, time() + 121));
$since = $b['since'];
TGS_Acc_Events::trip(2, 'x');
check('lần 2: 4 phút, giữ mốc bắt đầu', abs(TGS_Acc_Events::breaker(2)['until'] - time() - 240) <= 1 && TGS_Acc_Events::breaker(2)['since'] === $since);
TGS_Acc_Events::trip(2, 'x');
check('lần 3: 8 phút', abs(TGS_Acc_Events::breaker(2)['until'] - time() - 480) <= 1);
TGS_Acc_Events::trip(2, 'x');
TGS_Acc_Events::trip(2, 'x');
check('tối đa 15 phút', abs(TGS_Acc_Events::breaker(2)['until'] - time() - 900) <= 1 && TGS_Acc_Events::breaker(2)['fails'] === 5);
check('blog khác không bị ảnh hưởng', !TGS_Acc_Events::breaker_open(3));
TGS_Acc_Events::reset_breaker(2);
check('gửi được: đóng, xoá trạng thái', !TGS_Acc_Events::breaker_open(2) && TGS_Acc_Events::breaker(2) === []);
TGS_Acc_Events::trip(2, 'x');
check('sau khi đóng, lỗi mới lại bắt đầu từ 2 phút', TGS_Acc_Events::breaker(2)['fails'] === 1);
check('lý do dài bị cắt 200 ký tự', (TGS_Acc_Events::trip(4, str_repeat('a', 500)) || true) && strlen(TGS_Acc_Events::breaker(4)['msg']) === 200);

echo ($fail ? 'KẾT QUẢ: SAI ' : 'KẾT QUẢ: ĐẠT ') . "{$ok}/" . ($ok + $fail) . "\n";
exit($fail ? 1 : 0);
