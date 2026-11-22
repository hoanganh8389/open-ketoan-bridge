# Luật tối thượng — open-ketoan-bridge

Tệp này nằm ở gốc repo để người và agent (CLAUDE.md nạp tệp này) đọc **trước mọi việc**. Bản chuẩn: open-setup `docs/chuong-trinh/OKT/00-ADR-OKT-1.md` §0; CANON open-ketoan `docs/rules/RULE-OPEN-POS-CONNECTION.md` mục P-0.

<!-- [2026-11-23 05:40 AM Johnny Chu - Chu Hoàng Anh] OKT-P-0 — luật tối thượng của chủ (23/11): hai mô hình triển khai + mỗi book là một pháp nhân. -->
## Luật tối thượng P-0

> **Luật tối thượng P-0 (Johnny Chu – Chu Hoàng Anh, 23/11/2026)** — đứng trên mọi luật khác của chương trình open-pos ⇄ open-ketoan (open-ketoan, open-ketoan-bridge, open-pos-backend, open-pos, open-setup). Mâu thuẫn thì P-0 thắng; luật nào trái P-0 phải sửa.
>
> 1. **bizcity.vn (multisite):** "mỗi một blog trên hệ thống multisite của bizcity sẽ yêu cầu một phần mềm kế toán tương ứng. Việc này để demo cho khách mô hình hệ thống chạy như nào." → mỗi blog một tenant, **khoá API riêng từng blog**.
> 2. **Selfhost (ngoài bizcity):** "ưu tiên khóa chung cho cả mạng để người dùng có thể setup chuỗi nhiều cửa hàng dùng POS nhưng vẫn về 1 open-ketoan tương ứng mỗi tenant id = blog_id" — chủ chốt nghĩa (a): **một bản open-ketoan, một khoá chung (khoá cài đặt), mỗi blog là một tenant riêng**, mỗi cửa hàng sổ riêng trên cùng một máy kế toán. Một site = một cửa hàng = một tenant.
> 3. **"Chỉ có luật mỗi book là 1 pháp nhân, không có pháp nhân theo tenant id."** Pháp nhân (tên, mã số thuế, loại pháp nhân, hồ sơ kế toán) là thuộc tính của **sổ (book)**. `tenant_id` chỉ là khoá kỹ thuật (cô lập dữ liệu, khoá API, RLS) — không dùng để xác định, đặt tên hay suy ra pháp nhân.
>
> Mô hình triển khai phải **khai rõ** (`TGS_ACC_DEPLOYMENT` → lựa chọn ở màn Cài đặt của bridge → một site), không đoán từ khoá đang có. Đặc tả: open-setup `docs/chuong-trinh/OKT/00-ADR-OKT-1.md` §0, `81`, `82`.
>
> Ở repo này: mọi mã chạm khoá, tenant, kết nối kế toán đi theo **nhánh mô hình** đã khai (bizcity · nhiều cửa hàng · một cửa hàng); không suy tenant / pháp nhân từ khoá hay từ blog id.

## Áp dụng ở repo này

- Mọi chỗ chạm khoá, URL, tenant đi qua **hồ sơ mô hình** (`TGS_Acc_Profile::current()` = bizcity · chain · single — việc OKT-TOPO-9), không suy từ khoá đang có.
- **bizcity:** khoá riêng từng blog (`key_for`). **Nhiều cửa hàng:** một khoá cài đặt + `X-OKT-Blog` + `X-OKT-Install`; blog chưa được open-ketoan duyệt → xếp hàng (I-3). **Một cửa hàng:** blog 1.
- Tự kiểm: SC-3 "hai blog cùng tenant = đỏ + tự tạm dừng" giữ ở cả ba mô hình; blog dùng khoá chung (`legacy`) **không** bị tự tạm dừng vì SC-3 (OKT-TOPO-8).
- **Không có pháp nhân theo tenant id:** bridge không đặt tên, không suy mã số thuế hay pháp nhân từ `tenant_id` / mã tenant; thông tin pháp nhân (tên, MST trên hoá đơn) lấy từ sổ (book) / hồ sơ của shop.
- Lớp bao kiểu cài `TGS_Acc_Site` (một site / multisite) — không gọi thẳng hàm multisite (census `tests/ms0-census-test.php`).
