<!-- [2026-11-22 10:10 PM Johnny Chu - Chu Hoàng Anh] OKT-H4 + OKT-MT-3 — bằng chứng (I-7). Không ghi khoá. -->
# OKT-H4 — đối chiếu WP ↔ kế toán tự động; OKT-MT-3 — báo lịch cron của site chính — 22/11/2026

Môi trường thử `okt-wp` (shop-a → tenant `shopa`, CN01) + `okt-thu`. Lệnh: `wp tgs-acc reconcile --shop=2` (chỉ đọc); tự kiểm SC-12.

## Cách đối chiếu
- **Chứng từ** (7 ngày, theo ngày chứng từ, chi nhánh của blog): WP lẽ ra có = `TGS_Acc_Pusher::build` (đúng đường gửi) ↔
  `GET /api/v1/documents?from&to&branchCode&sourceSystem=btsoft` (chia trang 500) lọc `btsoft:{blog}:` → thiếu · mồ côi · chờ duyệt huỷ ·
  chờ định khoản · lỗi · định khoản bản cũ.
- **Tồn** (≤ 50 mã biến động nhiều nhất): WP `TGS_Global_Product_Source::get_stock_for_skus` (đúng số POS dùng) ↔ `GET /inventory/items?skus&branchCode`
  (chỉ chứng từ đã định khoản) + ảnh hưởng của chứng từ WP chưa định khoản (bán −, khách trả +, nhập mua +, trả NCC −). Lệch = WP − (KT + chờ).

## Bốn trạng thái chạy thật

**1. Có chứng từ thử mồ côi đang chờ huỷ** (đúng lệch đã tìm bằng tay ở ma trận 65):
```
🟡 Đối chiếu shop #2 (chi nhánh CN01) 2026-11-16 → 2026-11-22
   🟢 R-1  107 chứng từ của WP đều có ở kế toán
   🟢 R-2  Không có chứng từ mồ côi
   🟡 R-2  1 chứng từ đang chờ kế toán duyệt huỷ
          · btsoft:2:sale:900001
   🟢 R-3  Chờ định khoản 0 · lỗi 0 · định khoản bản cũ 0
   🟡 R-4  1 / 1 mã lệch tồn: 900000001 WP 8 ↔ KT 7 — có chứng từ mồ côi / chờ huỷ, xem R-2
```
**2. Kế toán duyệt huỷ** (người dùng của tenant gọi `POST /documents/cancel` → `reversed: 1`):
```
🟢 R-1 107 đều có · 🟢 R-2 không mồ côi · 🟢 R-3 0 / 0 / 0 · 🟢 R-4 900000001  WP 8  KT 8  chờ 0  lệch 0
```
**3. Tạm dừng kết nối, bán 1 đơn** (`CNTESBT10052`, 881 ms):
```
🟡 R-1  2 / 109 chứng từ của WP chưa có ở kế toán
          · btsoft:2:sale:160
          · btsoft:2:receipt:161
🟢 R-4  1 mã: tồn WP = tồn kế toán + chứng từ chưa định khoản      900000001  WP 7  KT 8  chờ −1  lệch 0
```
**4. Nối lại (gửi bù 1/1), kế toán định khoản (posted 2)**:
```
🟢 R-1 109 đều có · 🟢 R-2 · 🟢 R-3 · 🟢 R-4 900000001  WP 7  KT 7  chờ 0  lệch 0
```

## Tự kiểm
```
🟡 SC-10  Lịch của site chính không chạy: tgs_acc_events_retry quá hạn 22 phút — máy chủ cần cron hệ thống gọi WP-Cron của site chính mỗi phút
🟢 SC-12  R-1 109 chứng từ của WP đều có ở kế toán
🟢 SC-12  R-2 Không có chứng từ mồ côi
🟢 SC-12  R-3 Chờ định khoản 0 · lỗi 0 · định khoản bản cũ 0
🟢 SC-12  R-4 1 mã: tồn WP = tồn kế toán + chứng từ chưa định khoản
```
Chạy `wp cron event run --due-now --url=okt.local` (như cron hệ thống) → SC-10 chỉ còn dòng xanh. Ngưỡng: gửi lại 15 phút, kéo trạng thái 30 phút,
tự kiểm hằng ngày 2 ngày → vàng; quá 6 giờ hoặc chưa lên lịch → đỏ. Cổng kế toán → Tự kiểm có cột SC-12.

## Test
`tests/reconcile-test.php` 20/20 (mới); `tests/selfcheck-test.php` 56/56 (+5 cron, +3 SC-12); 13 tệp test đều đạt.

## Giới hạn đã biết
- Chứng từ ngoài khoảng ngày, chuyển kho / điều chỉnh chưa định khoản không vào phần "chờ" (R-4 nêu riêng).
- Đối chiếu tồn mới là theo chi nhánh (một blog = một chi nhánh); chưa theo kho.
