<!-- [2026-11-23 07:00 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-9 — bằng chứng (I-7). -->
# OKT-TOPO-9 — hồ sơ mô hình triển khai khai rõ (bizcity · chain · single) — 23/11/2026

Luật tối thượng P-0 + Q-OKT-8 ✅: mô hình **khai rõ**, không đoán từ khoá. `TGS_Acc_Profile::current()`: hằng `TGS_ACC_DEPLOYMENT` (thắng, khoá
lựa chọn) → lựa chọn ở mạng (`tgs_acc_deployment`) → WordPress một site tự là `single`. Bỏ `TGS_Acc_Site::topology()` (đoán theo khoá).
SC-0b in mô hình (multisite chưa khai → vàng); SC-1 theo mô hình (bizcity khoá chung → vàng "mỗi site phải có khoá riêng"; chain khoá chung →
vàng "cần khoá cài đặt, OKT-SET-1"). Lệnh `wp tgs-acc profile [--set=bizcity|chain]`.

## WordPress thật
```
=== multisite okt-wp (chưa khai)
Mô hình: (chưa khai) · Chọn được: bizcity, chain
Warning: Máy chủ bizcity: đặt hằng TGS_ACC_DEPLOYMENT = bizcity trong wp-config.php. Tự cài nhiều cửa hàng: wp tgs-acc profile --set=chain
--set=single → Error: Bản cài này chỉ chọn được: bizcity, chain (multisite)
--set=chain  → Mô hình: chain
   🟢 SC-0b  Mô hình: tự cài nhiều cửa hàng — một máy open-ketoan, một khoá cài đặt, mỗi cửa hàng một tenant (đã chọn ở Cài đặt)
   🟢 SC-1   Khoá riêng của blog (mô hình nhiều cửa hàng cho phép; khoá cài đặt dùng chung khi open-ketoan có — OKT-SET-1)
(trả về chưa khai)
=== một site okt-wp1
Mô hình: single — tự nhận · Chọn được: single · --set=chain → Error
   🟢 SC-0b  Mô hình: tự cài một cửa hàng — WordPress một site (tự nhận: WordPress một site)
   🟢 SC-1   Khoá của site
```

## Test
`tests/profile-test.php` 11/11 (mới: chưa khai không đoán từ khoá chung, chỉ chọn bizcity/chain trên multisite, hằng thắng + khoá lựa chọn,
option hỏng = chưa khai); `site-single-test` 17/17; `selfcheck-test` 65/65 (ca 19 viết lại theo mô hình). 16 tệp test đạt.
