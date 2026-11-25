# PHASE-002 (bridge) · 40 — Master checklist (trạng thái ở bizcity-platform)

<!-- [2026-11-25 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-002 (bridge) W0 — mô tả kỹ thuật B002-*. Trạng thái + bằng chứng của việc xuyên repo (LD-BR-*) chỉ ghi ở bizcity-platform PHASE-001 CHECKLIST (một nguồn). -->

> **Trạng thái và bằng chứng chỉ ghi ở [bizcity-platform PHASE-001 CHECKLIST](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/phases/PHASE-001-LUONG-DON-CRM-POS-KE-TOAN/CHECKLIST.md)** (cột LD).

> ☐ chưa làm · 🟡 có mã + test · ✅ có bằng chứng chạy thật · ⛔ chờ chủ / repo khác.

| ID | LD | Việc | Xong khi | → |
|---|---|---|---|---|
| B002-0 | — | README + checklist, đo `main` 3fea0ab | có trên `main` | ✅ 2026-11-25 |
| B002-1 | LD-BR-1 | header `Idempotency-Key = <docKey>@<version>` | gửi lại lô ⇒ `unchanged` | [→ bizcity-platform](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/phases/PHASE-001-LUONG-DON-CRM-POS-KE-TOAN/CHECKLIST.md) |
| B002-2 | LD-BR-2 | `links` role `source` về `pos.order:<id>` cho phiếu có nguồn CRM | Kế Toán lưu `document_links` (open-ketoan S042-5) | [→ bizcity-platform](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/phases/PHASE-001-LUONG-DON-CRM-POS-KE-TOAN/CHECKLIST.md) |
| B002-3 | LD-BR-3 | lưu kết quả `posted | pending | error` theo `docKey` | open-pos-backend S003-7 đọc được | [→ bizcity-platform](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/phases/PHASE-001-LUONG-DON-CRM-POS-KE-TOAN/CHECKLIST.md) |
