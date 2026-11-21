# PHASE-001 (bridge) · 95 — Danh sách deploy

<!-- [2026-11-21 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-001 (bridge) W0. -->

> Deploy luôn **phải hỏi và được chủ xác nhận**. Thứ tự trong mỗi mốc: **tsm → tgs_pos → bridge**. Bridge kích hoạt cả mạng (`Network: true`), nên mỗi mốc deploy một lần cho cả mạng; **bật kết nối theo từng blog** bằng cách dán khoá.

| Mốc | Thay đổi | Dữ liệu | Lùi bằng |
|---|---|---|---|
| M1 | FIX1, C1 | không; option blog chưa ai đặt | bản 0.5.0 (C1 không đổi hành vi khi blog chưa có khoá) |
| M2 | C2, C6, C4 | site option `tgs_okt_tenant_map` mới | xoá khoá blog → mọi blog standalone; bản M1 |
| M3 | C5, C7, C9 | **gỡ** 5 site option `tgs_acc_catalog_*` (giữ ảnh ở `evidence/`); bảng global catalog không bị bridge ghi nữa | bản M2 (có catalog sync) + tsm cờ `tgs_catalog_store = global` |
| M4 | C3, C8, C10, C11, C12, C13 | `tgs_acc_push_log` DB_VERSION 4 (thêm cột, dữ liệu cũ `tenant = legacy`); bỏ option `tgs_acc_known_branches*`, `tgs_acc_branch_sync_last` | khoá chung cũ vẫn gửi được (legacy); bản M3 |
| M5 | C14, DOC | — | bản M4 |

**Chuyển tiếp khoá chung cũ:** giữ `TGS_KETOAN_API_KEY` / option `key` ở tầng mạng cho tới khi mọi blog có khoá riêng (S001-K9). Blog nào đã có khoá riêng thì bridge thôi dùng khoá chung cho blog đó.

**Trước khi deploy M3 trên mạng thật:** tsm `TGS_Acc_Catalog_Pull` đã chạy trên site thử với đối chứng lệch 0; chưa thì không gỡ `TGS_Acc_Catalog_Sync`.
