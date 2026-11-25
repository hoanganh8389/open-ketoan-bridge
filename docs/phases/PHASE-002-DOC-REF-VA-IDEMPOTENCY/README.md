# PHASE-002 (open-ketoan-bridge) — Mang nguồn gốc đơn và khoá chống lặp lên phiếu gửi Kế Toán

> **Quyết định hiện hành: [ADR-OKT-1](https://github.com/hoanganh8389/bizcity-open-setup/blob/main/docs/chuong-trinh/OKT/00-ADR-OKT-1.md) (open-setup).** Bridge là **kênh**, không làm chủ: không nhận đơn từ app khác, không phục vụ đọc tồn cho app khác (I-0 giữ nguyên).

> **Trạng thái:** 📄 tài liệu (W0), 2026-11-25 06:20 PM. Chưa có mã. **Trạng thái việc xuyên repo (`LD-BR-*`) ở [bizcity-platform PHASE-001 CHECKLIST](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/phases/PHASE-001-LUONG-DON-CRM-POS-KE-TOAN/CHECKLIST.md)** — một nguồn.
> **Chương trình:** luồng đơn xuyên app — [bizcity-platform PHASE-001](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/phases/PHASE-001-LUONG-DON-CRM-POS-KE-TOAN/README.md) · [`bizcity_order_flow@1`](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-ORDER-FLOW-v1.md) §1, §6 · [SPINE R-SPINE-7](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-SPINE-v1.md).
> **Repo đã đọc:** `main` `3fea0ab` (2026-11-25).

<!-- [2026-11-25 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-002 (bridge) W0 — mở phase: phần của bridge trong luồng đơn xuyên app (chủ dự án chốt Q-ORD-1..8 ngày 2026-11-25). -->

## Phase này làm gì

Phiếu bán sinh ra khi open-pos **bàn giao vận chuyển** một đơn CRM là phiếu bán bình thường; bridge gửi nó như hôm nay, chỉ thêm: (1) header `Idempotency-Key = <docKey>@<version>` (manifest đã khai, mã chưa gửi); (2) `links` về `pos.order:<id>` (role `source`) để Kế Toán tra ngược được tới yêu cầu đặt hàng của CRM; (3) lưu kết quả `posted | pending | error` theo `docKey` để open-pos-backend chạy đối chiếu RC-ORDER-2.

## Hiện trạng đọc mã

| Có | Ở đâu |
|---|---|
| `POST /api/v1/documents/batch` (100 / lô), `docKey = btsoft:<blog>:<kind>:<id>` + `version` theo băm nội dung | `includes/class-tgs-acc-api-client.php:125`, `class-tgs-acc-mapper.php:221`, `class-tgs-acc-pusher.php:24-28,384-400` |
| chỉ gửi phiếu `approver_status = 1` | `includes/class-tgs-acc-source.php:134` |
| thử lại JSONL, cron mỗi phút, lùi 1→60 phút | `includes/class-tgs-acc-events.php:22-24,45-46` |
| manifest khai `Idempotency-Key = <doc_ref>@<version>` — **mã không gửi** | `bizcity.app.json:20` vs `includes/` |

## Việc (chi tiết ở [40](40-MASTER-CHECKLIST.md))

| B002 | LD | Việc |
|---|---|---|
| B002-1 | LD-BR-1 | gửi header `Idempotency-Key = <docKey>@<version>` cho mọi lô / phiếu |
| B002-2 | LD-BR-2 | phiếu bán có nguồn đơn chờ (CRM) mang `links: [{ to: "pos.order:<id>", role: "source" }]` |
| B002-3 | LD-BR-3 | lưu kết quả trả về theo `docKey` (bảng / option của bridge) cho RC-ORDER-2 |

## Xong khi

Gửi lại một lô ⇒ Kế Toán trả `unchanged`, không phiếu trùng; phiếu từ đơn CRM tra ngược được `pos.order` → `cake.order_request` qua `document_links`; phiếu bán quầy (không nguồn CRM) gửi y như cũ (I-0).
