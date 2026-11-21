# PHASE-001 (open-ketoan-bridge) — Kết nối theo blog: một blog = một tenant, bridge giữ kết nối, không kéo catalog

> ⚠️ **21/11 05:18 PM — đọc [open-pos-backend 28](https://github.com/hoanganh8389/open-pos-backend/blob/docs/phase-001-danh-muc-theo-site/docs/phases/PHASE-001-DANH-MUC-THEO-SITE-DONG-BO-KETOAN/28-QUYET-DINH-BANG-LOCAL-O-GIUA.md) trước.** Admin chọn nguồn theo blog (`local` hoặc `ketoan`). Bridge **không bắt buộc** cho blog `local` (gỡ bridge, blog `local` vẫn bán). Với blog có khoá: đường gửi chứng từ (`TGS_Acc_Pusher`, cổng `/pending`) là **outbox** của nguyên tắc "đơn ghi local trước, gửi lên sau commit" (D001-20), phải theo blog / tenant. **Không gỡ** `build_import` / `tools/danh-muc-hang.php nap`: dùng cho lần chuyển `local` → `ketoan`. Hai repo bạn đã đổi tên: **open-pos** (trước `tgs_pos`), **open-pos-backend** (trước `tgs_shop_management`). Banner 04:40 PM "bỏ standalone" đã bị thay.

> **Trạng thái:** 📄 tài liệu (B0), 2026-11-21 02:20 PM. Mã đã có: C1 ([PR #2](https://github.com/hoanganh8389/open-ketoan-bridge/pull/2), chưa merge). Trạng thái việc chỉ ở [40-MASTER-CHECKLIST](40-MASTER-CHECKLIST.md).
> **Chương trình:** OKT — bản đồ chung ở tgs_shop_management `docs/phases/PHASE-001-DANH-MUC-THEO-SITE-DONG-BO-KETOAN/05-CHUONG-TRINH-3-REPO.md`; mục tiêu golden ở `01-GOLDEN-MUC-TIEU.md` cùng thư mục.
> **Luật:** R-OKT-CONN (CANON open-ketoan `docs/rules/RULE-OPEN-POS-CONNECTION.md`, [PR #516](https://github.com/hoanganh8389/open-ketoan/pull/516)). **Cấu hình:** [`docs/CAU-HINH-KET-NOI.md`](../../CAU-HINH-KET-NOI.md) (PR #1) — bốn trường hợp, ba tầng, 13 tình huống K1–K13.
> **Repo đã đọc:** `main` `eb778ae` (bản 0.5.0 nguyên trạng). Khảo sát máy nguyên văn: [evidence/census-2026-11-21-bridge.md](evidence/census-2026-11-21-bridge.md).

<!-- [2026-11-21 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-001 (bridge) W0 — mở phase theo khuôn chương trình OKT. -->

## Phase này làm gì

Bridge 0.5.0 coi **cả mạng là một tenant**: một khoá, mỗi blog là một chi nhánh, tìm blog theo `branchCode`, tự tạo chi nhánh, kéo catalog vào bảng global, chỉ đọc chứng từ bán qua tgs-bc-tk. Sau phase này bridge là **cầu nối thuần**: giữ cấu hình kết nối theo blog, trả lời `tgs_okt_connected($blog)`, cấp client theo blog, đẩy chứng từ của blog bằng khoá của blog (nguồn thứ hai đọc thẳng sổ POS khi không có tgs-bc-tk), nhận sự kiện và tìm blog theo tenant, và **không kéo catalog nữa** — việc đó về open-pos-backend.

| Tài liệu | Một câu |
|---|---|
| [00-MASTER-PLAN](00-MASTER-PLAN.md) | Hiện trạng, quyết định B001-*, việc C1–C14 + FIX1 gắn mốc M1–M5, hợp đồng bridge cung cấp |
| [10-INVENTORY](10-INVENTORY.md) | Từng lớp / file làm gì, giả định cả-mạng ở đâu, nguồn chứng từ, route vào, nhật ký gửi, CLI |
| [40-MASTER-CHECKLIST](40-MASTER-CHECKLIST.md) | Nguồn trạng thái duy nhất của B001-* |
| [70-TEST-PLAN](70-TEST-PLAN.md) | Test php CLI theo lớp, ca T-CFG-1…12 của tài liệu cấu hình, ca golden G5–G13 |
| [95-DEPLOY-LIST](95-DEPLOY-LIST.md) | Theo mốc, cách lùi (khoá chung cũ là công tắc lùi) |
| [evidence/](evidence/) | Khảo sát máy 21/11 (nguyên văn, tiếng Anh, có file:dòng) |

## Bàn giao

**1. Phạm vi / ngoài phạm vi**

| Làm | Không làm |
|---|---|
| Cấu hình, khoá, client, câu hỏi kết nối **theo blog** | Kéo catalog (gỡ `TGS_Acc_Catalog_Sync`; tsm làm) |
| Đẩy chứng từ bằng khoá blog; nguồn thứ hai từ sổ POS | Tạo tenant / cấp khoá phía kế toán (S001-K10) |
| Sự kiện vào tìm blog theo tenant | Hợp đồng chứng từ (`documents/batch`) — không đổi khuôn |
| Nhật ký gửi có cột tenant; SSO theo blog | Tool MSSQL HTsoft (`htsoft-chi-doc`, `xuat-tai-san-cu`) |
| Gỡ mô hình chi nhánh, gỡ `TGS_Acc_Branch_Sync` | Sổ thuế thứ hai (`tax_url`): chỉ theo blog hoá, không đổi luồng |

**2. Đầu vào:** từ tsm — `tgs_catalog_table()` cho trade-mapper (C13); hook `tgs_okt_catalog_changed` do bridge phát, tsm lắng. Từ kế toán — feed theo tenant (đã đúng), K11 kết nối ngược mỗi tenant.

**3. Đầu ra** (hợp đồng §2.1 bản đồ chương trình): `tgs_okt_connected()`, `tgs_okt_connection()`, `TGS_Acc_Api_Client::for_blog()`, `TGS_Acc_Config::key_for/url_for/key_legacy`, hook `tgs_okt_connection_changed`, hook `tgs_okt_catalog_changed`.

**4. Cách kiểm:** `php tests/*.php` (CLI, giả lập WP); ca T-CFG-1…12; golden G5, G6, G8, G10–G13.

**5. Trạng thái:** [40-MASTER-CHECKLIST](40-MASTER-CHECKLIST.md).
