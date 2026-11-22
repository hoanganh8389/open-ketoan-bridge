<!-- [2026-11-22 05:45 PM Johnny Chu - Chu Hoàng Anh] OKT-I3 — bằng chứng (I-7). Không ghi khoá. -->
# OKT-I3 — quầy không chờ kế toán; hàng chờ gửi bù được — 22/11/2026

WordPress multisite thật (Docker `okt-wp`, **Apache mod_php** — không có `fastcgi_finish_request` / `litespeed_finish_request`), open-ketoan thử `okt-thu`. Request bán qua **HTTP thật** vào `admin-ajax.php` (`tgs_pos_save_order`), cookie đăng nhập + nonce thật, như trình duyệt thu ngân. POS `main` không sửa (I-0).

## Trước khi sửa (bridge `main` 7c28093)

| Trường hợp | Thời gian request bán | Đơn | Hàng chờ |
|---|---|---|---|
| không gửi tức thời (gốc) | 119–277 ms | lưu | — |
| kế toán sống | 219 ms | lưu | — |
| **kế toán tắt** | **16.067 ms** | lưu | **không vào** |
| **kế toán treo** | **30.351 ms** | lưu | **không vào** |

Sáu lỗi thật tìm ra (không test giả nào bắt được):

1. Không trả trang sớm được → bridge gửi **trong** request bán: thu ngân chờ kế toán (16–30 s).
2. `enabled()` đọc cấu hình **site chính** — mô hình khoá theo blog thì site chính không có khoá → **cron gửi lại không bao giờ chạy**, hàng chờ không bao giờ gửi bù.
3. `append()` dùng `@file_put_contents`: thư mục sự kiện không ghi được (tạo bởi root, web chạy www-data) → mất sự kiện + hàng chờ **lặng lẽ** (4 chứng từ `error` không bao giờ được gửi lại).
4. Hỏng đường truyền → nhật ký gửi lưu **không mã băm** → gửi bù cùng nội dung lên **v2** vô cớ (trái I-4).

## Sau khi sửa (nhánh `fix/okt-i3-gui-nen`)

```
trước:
   hàng chờ 0 · nhật ký gửi: error=4 pending=10 posted=2 · v>1: 2
[A kế toán sống] HTTP 200 · 896 ms · LƯU ĐƯỢC · đơn CNTESBT10010
[A lần 2] HTTP 200 · 447 ms · LƯU ĐƯỢC · đơn CNTESBT10011
   hàng chờ 0 · nhật ký gửi: error=4 pending=14 posted=2 · v>1: 2
[B kế toán TẮT] HTTP 200 · 1491 ms · LƯU ĐƯỢC · đơn CNTESBT10012
[B lần 2] HTTP 200 · 282 ms · LƯU ĐƯỢC · đơn CNTESBT10013
   hàng chờ 2 · nhật ký gửi: error=4 pending=14 posted=2 · v>1: 2
[C kế toán TREO] HTTP 200 · 1377 ms · LƯU ĐƯỢC · đơn CNTESBT10014
[C lần 2] HTTP 200 · 250 ms · LƯU ĐƯỢC · đơn CNTESBT10015
   hàng chờ 3 · nhật ký gửi: error=4 pending=16 posted=2 · v>1: 2
… nối lại, chờ cron gửi lại (chạy tay đúng hàm cron sau 70 s)
Array
(
    [retried] => 1
    [ok] => 1
)
   hàng chờ 2 · nhật ký gửi: error=8 pending=18 posted=2 · v>1: 2
Array
(
    [retried] => 2
    [ok] => 2
)
hàng chờ 0
btsoft:2:sale:29 | 1 | pending
btsoft:2:receipt:30 | 1 | pending
btsoft:2:sale:33 | 1 | pending
btsoft:2:receipt:34 | 1 | pending
btsoft:2:sale:36 | 1 | pending
btsoft:2:receipt:37 | 1 | pending
btsoft:2:sale:39 | 1 | pending
btsoft:2:receipt:40 | 1 | pending
btsoft:2:sale:42 | 1 | pending
btsoft:2:receipt:43 | 1 | pending
btsoft:2:sale:45 | 1 | pending
btsoft:2:receipt:46 | 1 | pending
btsoft:2:sale:48 | 1 | pending
btsoft:2:receipt:49 | 1 | pending
trùng: 0
   🟡 SC-10  Hàng chờ 0 nhóm (cũ nhất 0.0 ngày), chờ định khoản > 24 h: 0, lỗi: 4
```

| Trường hợp | Thời gian request bán | Đơn | Gửi |
|---|---|---|---|
| kế toán sống | 447–896 ms | lưu | request nền gửi ngay → pending |
| kế toán tắt | 282–1.491 ms | lưu | vào hàng chờ; nền thử lại sau |
| kế toán treo | 250–1.377 ms | lưu | vào hàng chờ; nền thử lại sau |
| nối lại | — | — | cron gửi lại → **hàng chờ 0, mọi chứng từ v1, 0 trùng** |

Nhóm kẹt từ trước khi sửa (`d0e2e8ec73d8`, ca B lúc 12:00) được gửi bù ngay khi request nền đầu tiên chạy. Bốn chứng từ `error` của lúc thư mục thuộc root vẫn `error` — tự kiểm SC-10 báo "lỗi: 4" (đúng): gửi lại ngày đó bằng nút Gửi chứng từ.

Test: `tests/i3-test.php` 15/15; 10/10 tệp test của bridge đạt.
