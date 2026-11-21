# PHASE-001 (bridge) · 70 — Kế hoạch kiểm thử

<!-- [2026-11-21 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-001 (bridge) W0. -->

## Máy (php CLI, giả lập hàm WP như `tests/config-test.php`)

| Test | Phủ | Mốc |
|---|---|---|
| `tests/config-test.php` (có, PR #2) | ba tầng H → B → M, `key_for` / `key_legacy`, site 0, localhost, `save_blog` | M1 |
| `tests/connection-test.php` | `tgs_okt_connected()`: bridge tắt → false; thiếu URL → false; thiếu khoá riêng (chỉ legacy) → false; kiểm `feed/meta` lỗi → false và transient 60 giây; hai blog cùng tenant → cả hai false; site 0 → false; hook `tgs_okt_connection_changed` phát đúng một lần khi đổi trạng thái | M2 |
| `tests/api-client-test.php` | `for_blog()` lấy khoá blog; không khoá → `WP_Error`; không bao giờ rơi về legacy; log lượt gọi có `blog_id` | M2 |
| `tests/ketoan-events-test.php` | `tenantId` → blog; tenant lạ → 404, không ghi; chữ ký sai → 401; `/catalog-changed` phát hook, không gọi kéo | M3 |
| `tests/source-pos-test.php` | fixture `local_ledger` 10 + phiếu xuất 2 + `local_ledger_item` → chứng từ `sale` đúng khuôn, không `invoice`, tiền = `round(qty×price−ck)`, thuế từ `_tax_amount`; phiếu hoàn 11; chưa duyệt / `pg:` bị loại; có tgs-bc-tk (mock) → ưu tiên VAT | M4 |
| `tests/push-log-tenant-test.php` | cùng `doc_key` hai tenant → hai dòng; `find_many` lọc tenant; nâng DB_VERSION 4 giữ dữ liệu cũ (tenant = `legacy`) | M4 |
| `tests/pusher-key-test.php` | blog có khoá → client blog; chỉ legacy → gửi + nhãn `legacy`; không có → bỏ qua + lý do | M4 |
| `php -l` mọi file; `php tests/run-all.php` | | mọi mốc |

**Chưa có cách chạy WordPress thật ở máy dev** (WP-CLI dừng vì thiếu extension `redis`): mọi PR ghi rõ "chưa chạy WP thật"; chạy trên site thử trước khi merge vào mốc.

## Người

Ca **T-CFG-1…12** ở `docs/CAU-HINH-KET-NOI.md §7` (bốn trường hợp cấu hình).

Ca golden thuộc bridge (mã G theo tsm `01-GOLDEN-MUC-TIEU.md`):

| # | Làm | Mong đợi |
|---|---|---|
| G5 | Không blog nào có khoá | `tgs_okt_connected()` false ở A, B, C, Network; màn Khoá theo shop toàn ⚪ |
| G6 | A dán khoá tenant `tA` (K1), bấm Kiểm | 🟢 ≤ 1 phút; hook `tgs_okt_connection_changed(A, connected)` phát; tsm tự ánh xạ + kéo + đối chứng lệch 0 |
| G8 | A bán 1 đơn | Chứng từ vào `tA` ở K1 bằng khoá của A; nhật ký gửi có `tenant = tA`; không vào tenant khác |
| G10 | C đặt URL riêng K2 + khoá `tC` | C gọi K2; A vẫn K1; B standalone |
| G11 | Dán khoá `tA` vào cả B | A và B 🔴 "trùng tenant", cả hai false, không gửi, không kéo |
| G12 | Tắt K1 2 phút, bật lại | A 🟡 rồi 🟢; chứng từ xếp hàng được gửi sau; hook phát 2 lần |
| G13 | Xoá khoá ở A | ⚪, hook `standalone`; không gửi; dữ liệu đã kéo ở lại (tsm) |
| T-BR-1 | Không có tgs-bc-tk, A bán 1 đơn | Chứng từ `sale` từ sổ POS, không `invoice`, kế toán ghi sổ nội bộ; có tgs-bc-tk thì chứng từ có `invoice` |
| T-BR-2 | `php tools/day-ke-toan.php --all --days=1` khi 1/3 blog có khoá | 1 blog gửi khoá riêng; 2 blog ghi "không có khoá, bỏ qua" (hoặc legacy có nhãn nếu còn) |
| T-BR-3 | Kế toán gửi `ketoan-events` với `tenantId` không gắn blog | 404, inbox không ghi, không blog nào bị sửa |
