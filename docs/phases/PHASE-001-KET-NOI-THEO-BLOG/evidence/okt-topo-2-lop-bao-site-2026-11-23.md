<!-- [2026-11-23 03:10 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-2 — bằng chứng (I-7). Không ghi khoá. -->
# OKT-TOPO-2 (P1) — lớp bao kiểu cài `TGS_Acc_Site`: bridge chạy ở WordPress một site — 23/11/2026

Theo open-setup `docs/chuong-trinh/OKT/80-BA-KIEU-CAI-DAT.md` §4 phương án B (Q-OKT-4, mặc định tạm B).

## Vấn đề (đo trước khi sửa, `okt-wp1` = WordPress một site)
WordPress chỉ nạp `ms-blogs.php` / `ms-functions.php` khi multisite → `get_blog_option`, `update_blog_option`, `switch_to_blog`, `get_sites`,
`get_blog_details` không tồn tại. Bridge `main`: **tự kiểm fatal** (`update_blog_option`, selfcheck:456), **tạm dừng fatal** (events:814),
trang Cổng kế toán / cổng (`get_sites` không kiểm, admin:187, gate:169) fatal; trạng thái báo lỗi CSDL (bảng nhật ký chưa tạo).

## Sửa
- `includes/class-tgs-acc-site.php` (mới): `opt / set / del / exists / blogs / main / current / switch_to / restore / in_blog / name / topology`.
  Một site = mạng một blog: option của blog hiện tại = option của site; blog khác → mặc định / false / `WP_Error` (không đoán). **Không polyfill**.
- 47 lời gọi thẳng trong `includes/` → `TGS_Acc_Site` (12 tệp). Census `tests/ms0-census-test.php`: **0** (đối chứng âm: mã `main` → 47).
- Lỗi tìm thêm khi chạy thật: `TGS_Acc_Source::branch_override` (mã chi nhánh) có điều kiện `function_exists('get_blog_option')` và **không có
  nhánh else** → ở một site mã chi nhánh không bao giờ được đọc. Bỏ điều kiện (lớp bao lo). Tương tự nguồn phiếu bán, `cron_next()`.
- `TGS_Acc_Config::save_blog` ở một site: blog hiện tại → ghi cấu hình site (một API cho cả ba kiểu); blog khác → từ chối.
- Tự kiểm **SC-0b** kiểu cài: `single` · `multisite-blog-tenant` · `multisite-network-tenant` (vàng — chờ Q-OKT-3).
- Bảng nhật ký gửi tạo lúc kích hoạt + trước khi đọc trạng thái.

## Kiểu 3 — `okt-wp1` (WordPress một site) sau sửa
```
save_blog: ok · key_mode=own · chi nhánh=CN01 · nguồn=pos · tạm dừng=có      (tạm dừng: không gửi gì vào sổ tenant thử)
🟡 Shop #1 → tenant shopa
   🟢 SC-0b  Kiểu cài: WordPress một site
   🟢 SC-1 … SC-8, SC-10, SC-11, SC-12 (R-1…R-4)
   🟡 SC-9   Chưa có phiếu nào dựng được chứng từ — bán một đơn rồi chạy lại
🟢 Đối chiếu shop #1 (chi nhánh CN01)
Shop #1  nguồn phiếu: pos  chi nhánh: CN01  khoá: own  ⏸ tạm dừng
lỗi PHP trong lượt: 0
```

## Kiểu 2 — ma trận tuân thủ chạy lại trên `okt-wp` (multisite) với lớp bao
```
M1 sống 676 ms · M2 tạm dừng 623 / 429 ms, 0 lời gọi ra ngoài, hàng chờ +2 · M3 nối lại 2/2
M4 kế toán TẮT 1534 / 923 ms, lưu được · M5 bật lại, gửi bù (1 nhóm chờ hết cửa sổ cầu dao 120 s — như thiết kế)
M6 s1 ↔ s2 🟢 giống hệt · s1 ↔ s4 🟢 giống hệt
M7 gửi lại 134: unchanged 132, lỗi 0 · 135 chứng từ = 135 docKey (0 trùng)
M8 trùng tenant → SC-3 đỏ cả hai, tự tạm dừng · M9 SC-0b "multisite, khoá theo blog"
```
(Lượt đầu M4 không lưu được vì hết tồn thử 60/60 — nhập thêm 30 bằng phiếu nhập có lý do nhập rồi chạy lại cả ma trận.)

## Test
15 tệp đạt: mới `site-single-test` 16/16 (tiến trình không có hàm multisite), `ms0-census-test`; `selfcheck-test` 58/58 (+SC-0b);
`config-test` S5 đổi theo hành vi mới (save_blog ở một site); các test giả multisite nạp lớp bao thật.

## `docKey` của bản cài một site (Q-OKT-6 ✅ 23/11)
Mọi bản cài một site đều là blog 1 → `btsoft:1:…`. Chủ chốt: **không phải rủi ro** — mỗi chủ shop có phần mềm kế toán (tenant) riêng, API key nội bộ
open-ketoan cấp riêng cho open-pos của shop đó (I-1 ở kiểu 3). Không đổi `docKey`.
