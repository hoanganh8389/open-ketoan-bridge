# PHASE-001 (bridge) · 00 — Master plan

> ⛔ **21/11 04:40 PM — open-ketoan bắt buộc, bỏ chế độ standalone (D001-15, [open-pos-backend 26](https://github.com/hoanganh8389/open-pos-backend/blob/docs/phase-001-danh-muc-theo-site/docs/phases/PHASE-001-DANH-MUC-THEO-SITE-DONG-BO-KETOAN/26-QUYET-DINH-OPEN-KETOAN-BAT-BUOC.md)).** Hai repo bạn đã đổi tên: **open-pos** (trước `tgs_pos`), **open-pos-backend** (trước `tgs_shop_management`). Bridge thành plugin **bắt buộc**; `tgs_okt_connected()` từ nay nghĩa là "blog sẵn sàng bán". **Không gỡ** `build_import` / `tools/danh-muc-hang.php nap` ở B001-C7 cho tới khi xong lần chuyển 92 blog lên open-ketoan (26 §4).

<!-- [2026-11-21 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-001 (bridge) W0 — từ khảo sát máy 21/11 (evidence/census-2026-11-21-bridge.md) và docs/CAU-HINH-KET-NOI.md. -->

## 0. Chỉ đạo của chủ (21/11)

> *"… bridge ở site nào cấu hình kết nối api với open-ketoan của site đó thì sẽ tự động sync được với open-ketoan đó."*
> Luật tối thượng R-OKT-CONN: luôn hỏi "có kết nối API thành công với open-ketoan qua bridge không?"; một blog = một shop = một tenant; open-pos-backend là bộ kéo duy nhất.

## 1. Hiện trạng (file:dòng ở [10](10-INVENTORY.md))

| Điều | Thực tế 0.5.0 |
|---|---|
| Khoá / URL | Một `get_site_option('tgs_acc_bridge_config')` + hằng wp-config cho cả mạng (`class-tgs-acc-config.php:40, 84-90, 242`); `TGS_Acc_Api_Client` mặc định lấy khoá đó (`api-client.php:24-25`) |
| Mô hình chi nhánh | Mọi chứng từ mang `branchCode` (mapper 9 chỗ); `TGS_Acc_Source::branch_of_blog/shop_code` đọc phân cấp multisite + `{base}htsoft_chinhanh` (`source.php:129-177`); pusher tự tạo chi nhánh (`pusher.php:405-424`); `TGS_Acc_Branch_Sync` tạo chi nhánh cho **mọi** site khi gắn phân cấp / tạo site |
| Trạng thái cả mạng | 12 site option (cursor, lock, call log, known branches, lists cache, warehouse map…) + bảng global `{base}tgs_acc_push_log(_tax)`, `tgs_acc_push_run`, `tgs_acc_ketoan_inbox` — không cột tenant |
| Nguồn chứng từ bán / hoàn | **Chỉ** `TGS_BCTK_Ajax::vat_rows_for_export` (tgs-bc-tk) (`source.php:21-24, 33, 71`). Phiếu thu / chi, mua, điều chỉnh, chuyển kho, chuyển tiền **đã** đọc thẳng sổ POS theo `get_blog_prefix` |
| Kéo catalog | `TGS_Acc_Catalog_Sync` (1037 dòng) kéo feed vào 7 bảng global; công tắc `catalog_from_erp`; `SYNC_TAX = false`; `/catalog-changed` xác thực bằng khoá cổng chung |
| Sự kiện vào | `/ketoan-events`: HMAC một secret cho cả mạng; **tìm blog theo `branchCode`**, bỏ qua `tenantId` (`ketoan-events.php:121, 249-258`). `/pending`, `/sites`, `/catalog-changed`: một khoá cổng |
| SSO | Một `sso_tenant` cho cả mạng (`embed.php:161`) |
| CLI | `tools/*.php` còn `require_once '…/tgs-accounting-bridge.php'` — **file đã đổi tên**, chạy tay sẽ fatal (`day-ke-toan.php:33`, `doi-chieu.php:36`, `danh-muc-hang.php:41`; `admin.php:106`) |
| Test | Không có trên `main`; C1 thêm `tests/config-test.php` |

**Một câu:** 0.5.0 là cầu nối cho **một công ty nhiều chi nhánh**. Phase này đổi nó thành cầu nối cho **nhiều shop, mỗi shop một tenant**, và trả việc kéo catalog về open-pos-backend.

## 2. Quyết định (B001-*)

| # | Quyết định | Căn cứ |
|---|---|---|
| B001-1 | Ba tầng cấu hình H → B → M; **khoá dữ liệu không bao giờ đọc ở tầng mạng** để trả lời câu hỏi kết nối; `key_legacy()` chỉ để đẩy chứng từ chuyển tiếp, có nhãn | `CAU-HINH-KET-NOI.md §2`, đã có mã C1 |
| B001-2 | `tgs_okt_connected($blog)`: bridge bật ∧ có URL ∧ có khoá riêng ∧ kiểm `feed/meta` gần nhất OK ∧ không blog khác giữ cùng tenant. Giữ 60 giây, timeout 3 giây. Site 0 luôn `false` | `CAU-HINH §0` |
| B001-3 | `TGS_Acc_Api_Client::for_blog($blog)`; không có khoá riêng → `WP_Error`, không tự rơi về khoá chung. Chỗ **duy nhất** được dùng `key_legacy()` là đẩy chứng từ (`pusher`, `events`, `day-ke-toan`) khi blog chưa có khoá riêng | R-OKT-CONN-6 |
| B001-4 | Bỏ mô hình chi nhánh: `branchCode` trên chứng từ = chi nhánh mặc định của tenant (từ `feed/meta.branches` do tsm lưu khi tự ánh xạ) hoặc bỏ; gỡ `ensure_branch`, gỡ `TGS_Acc_Branch_Sync`; chuyển kho chéo blog (`gt-out/gt-in`) chuyển sang hai tenant → tạm **ngoài phase** (ghi ở §5) | Khảo sát §2.4 |
| B001-5 | Nhật ký gửi thêm cột `tenant` (+ `server_url`), `UNIQUE(tenant, doc_key)`; giữ dạng `docKey` `btsoft:<blog>:<kind>:<id>` để kế toán không phải đổi khoá | Khảo sát §6 |
| B001-6 | Sự kiện vào: xác thực rồi **tìm blog theo `tenantId`** (bản đồ tenant → blog lập lúc kiểm kết nối); không tìm ra → 404, không ghi blog nào. `/catalog-changed`: xác thực theo tenant, phát `do_action('tgs_okt_catalog_changed', $blog, $payload)`, **không tự kéo** | `CAU-HINH K10`, bản đồ §2.1 |
| B001-7 | Gỡ `TGS_Acc_Catalog_Sync` và `tools/danh-muc-hang.php`, bỏ `catalog_from_erp`; hợp đồng feed (§4.1 khảo sát) bàn giao cho tsm làm `TGS_Acc_Catalog_Pull` | R-OKT-CONN-7 |
| B001-8 | Nguồn chứng từ thứ hai `TGS_Acc_Source_Pos`: dựng `sale` / `sale_return` từ `{B}local_ledger` (10 / 11, đã duyệt, không mirror `pg:`) + dòng từ phiếu xuất con loại 2 / chính phiếu hoàn, theo khuôn `supplier_doc_items`; **không có khối `invoice`** → chỉ sổ nội bộ; có tgs-bc-tk thì ưu tiên dòng VAT. Chọn nguồn **theo blog** | Q001-15 ✅; khảo sát §3.6 |
| B001-9 | Supplier sync, warehouse sync, lists cache: theo blog (khoá cache `blog\|kind`, client theo blog); supplier sync ghi bảng nào do tsm quyết (mua hàng ngoài phase) → bridge chỉ còn kéo khi tsm có bảng theo blog, tạm tắt | Q001-11 |
| B001-10 | SSO: `tenant` và URL kế toán lấy từ blog người dùng đang đứng; `sso_tenant` xuống tầng B | Khảo sát §7 |
| B001-11 | Cron / `--all`: lặp blog, mỗi blog khoá riêng; không khoá thì dùng legacy có nhãn, hoặc bỏ qua và ghi lý do | `CAU-HINH §3.3` |
| B001-12 | Sửa ngay `require_once` tên file cũ (FIX1), không đợi mốc | Khảo sát §8 |

## 3. Việc, gắn mốc chương trình

| Việc | Mốc | Nội dung | Tệp chính |
|---|---|---|---|
| **FIX1** | M1 | `tgs-accounting-bridge.php` → `open-ketoan-bridge.php` ở 3 tool + `admin.php:106`; cron hint ở `page-cong-ke-toan.php:277-279` | tools/*, admin |
| **C1** | M1 | Tầng cấu hình theo blog (PR #2) | config |
| **C2** | M2 | `for_blog($blog)`; nhật ký 60 lượt gọi có `blog_id`; `health()` theo blog | api-client |
| **C6** | M2 | `tgs_okt_connected()`, `tgs_okt_connection()`, bản đồ tenant → blog, hook `tgs_okt_connection_changed`; thay mọi `TGS_Acc_Config::ready()` | file mới `class-tgs-acc-connection.php` |
| **C4** | M2 | Màn Network "Khoá theo shop" (`CAU-HINH §4`) + màn Kết nối của site; AJAX `save_blog` / `for_display_blog` / kiểm | admin, page-cong-ke-toan, assets |
| **C5** | M3 | Sự kiện vào theo tenant; `/catalog-changed` → hook; `/pending`, `/sites` theo tenant của khoá gọi | ketoan-events, catalog-sync (route), gate |
| **C7** | M3 | Gỡ `TGS_Acc_Catalog_Sync`, `danh-muc-hang.php`, `catalog_from_erp`, 5 site option `tgs_acc_catalog_*` | catalog-sync, config, bootstrap |
| **C9** | M3 | Supplier / warehouse / lists theo blog (hoặc tắt supplier) | supplier-sync, warehouse-sync, lists |
| **C3** | M4 | Đẩy bằng khoá blog; legacy có nhãn; `--all` lặp blog | pusher, events, day-ke-toan, doi-chieu |
| **C8** | M4 | Nhật ký gửi: cột `tenant`, `server_url`; bỏ cảnh báo "đổi đơn vị → --force" | push-log, admin |
| **C10** | M4 | `TGS_Acc_Source_Pos`; chọn nguồn theo blog; thay guard `TGS_Acc_Source::ready()` và danh sách shop từ `TGS_BCTK_*` bằng "blog đã kết nối" | source (mới), gate, events, admin, day-ke-toan |
| **C11** | M4 | Bỏ `branchCode` chi nhánh / `ensure_branch`; gỡ `TGS_Acc_Branch_Sync`; option `tgs_acc_known_branches*` | mapper, pusher, branch-sync, bootstrap |
| **C12** | M4 | SSO theo blog | embed, config |
| **C13** | M4 | Trade-mapper đọc `tgs_catalog_table('product', $blog)` | trade-mapper |
| **C14** | M5 | Cron lặp blog theo khoá; DOC (`MO-RONG.md`, `class-tgs-acc-catalog.php` bỏ "branchCode = mã shop") | events, warehouse-sync, docs |

Thứ tự merge trong một mốc: FIX1 → C1 → C2 → C6 → C4 (M2) · C5 → C7 → C9 (M3) · C3 → C8 → C10 → C11 → C12 → C13 (M4).

## 4. Hợp đồng bridge cung cấp (chữ ký chốt)

```php
function tgs_okt_connected( int $blog_id = 0 ): bool;
function tgs_okt_connection( int $blog_id ): array;   // { url, tenant, key_mode, checked_at, ok, error } — không có khoá thật
TGS_Acc_Api_Client::for_blog( int $blog_id ): TGS_Acc_Api_Client;   // WP_Error nếu blog không có khoá riêng
TGS_Acc_Config::key_for( int $blog_id ): string;   TGS_Acc_Config::url_for( int $blog_id ): string;   TGS_Acc_Config::key_legacy(): string;
do_action( 'tgs_okt_connection_changed', int $blog_id, string $state /* connected|standalone */, array $info );
do_action( 'tgs_okt_catalog_changed', int $blog_id, array $payload );
```

## 5. Phụ thuộc và điều để lại

- **tsm:** C13 cần `tgs_catalog_table()`; C7 chỉ gỡ khi tsm `TGS_Acc_Catalog_Pull` (S001-C8) đã chạy trên site thử.
- **Kế toán:** K11 (kết nối ngược mỗi tenant) để N1 có sự kiện; K10 (cấp tenant) cho production; K8 cho mã tự tạo.
- **Để lại, ghi rõ:** chuyển kho chéo blog (`gt-out` / `gt-in`, `mapper.php:545-618`) và chuyển tiền chéo blog khi hai blog là hai tenant: cần hợp đồng mới phía kế toán (chứng từ liên tenant). Trong phase: chỉ gửi khi hai blog cùng tenant (legacy), còn lại bỏ qua và ghi log.
- **Sổ thuế thứ hai** (`tax_url` / `tax_key`): theo blog hoá cùng C1 / C2, không đổi luồng.

## 6. Không làm

- Không đổi khuôn chứng từ `documents/batch`, `docKey`.
- Không kéo catalog, không ghi bảng catalog nào.
- Không tạo tenant, không cấp khoá.
- Không deploy; theo [95](95-DEPLOY-LIST.md), hỏi chủ trước.
