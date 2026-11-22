<!-- [2026-11-22 06:45 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — bằng chứng (I-7). Không ghi khoá. -->
# OKT-CAU-DAO — kế toán treo không làm đứng site PHP-FPM — 22/11/2026

Máy chủ thật chạy **PHP-FPM** (chủ xác nhận 22/11). Dựng đúng kiểu đó: nginx 1.27 + `wordpress:php8.1-fpm`, **`pm.max_children = 4`** (static), cùng CSDL / mã với WordPress thử `okt-wp`. Kế toán **treo**: URL blog A trỏ `10.255.255.1` (không trả lời). **8 đơn cùng lúc** qua HTTP thật vào `admin-ajax.php`; sau 1 s mở trang chủ shop để đo site có còn phản hồi.

## Trước (bridge `main` c104e31)
```
== TRƯỚC (main, chưa cầu dao) (kế toán treo, 8 đơn cùng lúc, FPM 4 tiến trình)
[TRƯỚC (main, chưa cầu dao) đơn 1] HTTP 200 · 30586 ms · LƯU ĐƯỢC · đơn CNTESBT10022
[TRƯỚC (main, chưa cầu dao) đơn 2] HTTP 200 · 346 ms · LƯU ĐƯỢC · đơn CNTESBT10019
[TRƯỚC (main, chưa cầu dao) đơn 3] HTTP 200 · 313 ms · LƯU ĐƯỢC · đơn CNTESBT10018
[TRƯỚC (main, chưa cầu dao) đơn 4] HTTP 200 · 30613 ms · LƯU ĐƯỢC · đơn CNTESBT10023
[TRƯỚC (main, chưa cầu dao) đơn 5] HTTP 200 · 283 ms · LƯU ĐƯỢC · đơn CNTESBT10017
[TRƯỚC (main, chưa cầu dao) đơn 6] HTTP 200 · 30558 ms · LƯU ĐƯỢC · đơn CNTESBT10021
[TRƯỚC (main, chưa cầu dao) đơn 7] HTTP 200 · 379 ms · LƯU ĐƯỢC · đơn CNTESBT10020
[TRƯỚC (main, chưa cầu dao) đơn 8] HTTP 200 · 30636 ms · LƯU ĐƯỢC · đơn CNTESBT10024
   thăm dò trang chủ (giữa cơn bão): 60147 ms
   cả cơn bão: 61152 ms
```
FPM trả trang trước (đơn 2, 3, 5, 7 ~300 ms) nhưng **giữ tiến trình** tới khi lời gọi hết giờ: mỗi lần gửi gọi 3 lần × 10 s (`/warehouses` ×2 khi dựng — đệm kho rỗng bị coi như chưa có — rồi `documents/batch`). 4 tiến trình bị giữ 30 s → đơn 1, 4, 6, 8 chờ 30 s; **trang chủ chờ 60 s** — cả site đứng.

## Sau (nhánh `feat/okt-cau-dao`)
```
== SAU (cầu dao + chờ 5 s + đệm kho) (kế toán treo, 8 đơn cùng lúc, FPM 4 tiến trình)
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 1] HTTP 200 · 5735 ms · LƯU ĐƯỢC · đơn CNTESBT10029
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 2] HTTP 200 · 5858 ms · LƯU ĐƯỢC · đơn CNTESBT10030
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 3] HTTP 200 · 6121 ms · LƯU ĐƯỢC · đơn CNTESBT10032
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 4] HTTP 200 · 5981 ms · LƯU ĐƯỢC · đơn CNTESBT10031
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 5] HTTP 200 · 377 ms · LƯU ĐƯỢC · đơn CNTESBT10027
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 6] HTTP 200 · 377 ms · LƯU ĐƯỢC · đơn CNTESBT10025
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 7] HTTP 200 · 377 ms · LƯU ĐƯỢC · đơn CNTESBT10026
[SAU (cầu dao + chờ 5 s + đệm kho) đơn 8] HTTP 200 · 377 ms · LƯU ĐƯỢC · đơn CNTESBT10028
   thăm dò trang chủ (giữa cơn bão): 5290 ms
   cả cơn bão: 6294 ms
   cầu dao: mở tới 12:23:05 (lần 1) · hàng chờ 13 nhóm

== ĐỢT 2 (cầu dao đang mở, kế toán vẫn treo)
[đợt 2 đơn 1] HTTP 200 · 260 ms · LƯU ĐƯỢC · đơn CNTESBT10034
[đợt 2 đơn 2] HTTP 200 · 321 ms · LƯU ĐƯỢC · đơn CNTESBT10036
[đợt 2 đơn 3] HTTP 200 · 293 ms · LƯU ĐƯỢC · đơn CNTESBT10035
[đợt 2 đơn 4] HTTP 200 · 232 ms · LƯU ĐƯỢC · đơn CNTESBT10033
[đợt 2 đơn 5] HTTP 200 · 407 ms · LƯU ĐƯỢC · đơn CNTESBT10037
[đợt 2 đơn 6] HTTP 200 · 468 ms · LƯU ĐƯỢC · đơn CNTESBT10039
[đợt 2 đơn 7] HTTP 200 · 499 ms · LƯU ĐƯỢC · đơn CNTESBT10040
[đợt 2 đơn 8] HTTP 200 · 436 ms · LƯU ĐƯỢC · đơn CNTESBT10038
   thăm dò trang chủ: 178 ms · cả đợt: 1183 ms
  hàng chờ gửi       24

# nối lại kế toán, cầu dao hết hạn, cron gửi lại
chờ cầu dao hết hạn 88 s…
cron gửi lại: 24 nhóm trong 1.3 s
hàng chờ còn 0 · cầu dao: đã đóng
chứng từ: 78 · trùng: 0 · v>1 mới: 0 · theo trạng thái: error=4 pending=72 posted=2
```

| | Trước | Sau, đợt 1 | Sau, đợt 2 (cầu dao mở) |
|---|---|---|---|
| đơn chậm nhất | 30.636 ms | 6.121 ms | 499 ms |
| trang chủ giữa cơn bão | **60.147 ms** | 5.290 ms | **178 ms** |
| cả đợt 8 đơn | 61.152 ms | 6.294 ms | 1.183 ms |

Phục hồi: 24 nhóm gửi trong 1,3 s, hàng chờ 0, cầu dao tự đóng, 78 chứng từ, **0 trùng, 0 lên phiên bản vô cớ** (4 `error` là của lúc thư mục sự kiện thuộc root, xem okt-i3).

Test: `tests/breaker-test.php` 11/11; 11/11 tệp test đạt.
