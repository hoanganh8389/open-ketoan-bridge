# PHASE-001 (bridge) · 10 — Hiện trạng mã (tóm từ khảo sát 21/11)

<!-- [2026-11-21 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-001 (bridge) W0 — tóm tiếng Việt có file:dòng; bản đầy đủ ở evidence/census-2026-11-21-bridge.md. -->

`main` `eb778ae`, 27 file PHP. Ký hiệu: **G** = bảng / option cả mạng · **B** = bảng theo blog (`get_blog_prefix`) · **F** = file.

## 1. Từng lớp làm gì và giả định cả-mạng ở đâu

| Lớp (file) | Vai | Cấu hình đọc | Bảng | Giả định cả mạng |
|---|---|---|---|---|
| `TGS_Acc_Config` (config.php) | cấu hình | `get_site_option` :40, hằng :46-103, ghi :242 | — | **một khoá = một tenant** |
| `TGS_Acc_Api_Client` (api-client.php) | gọi API | mặc định `url()/key()` :24-25; log 60 lượt site option `tgs_acc_bridge_calls` :29-55 | G option | client không biết blog |
| `TGS_Acc_Source` (source.php) | đọc sổ shop theo `$blog_id` + `get_blog_prefix` | — | B `local_ledger*`; G `global_supplier` :289, `global_product_name` :381 / :525, `global_po_adjustment` :340, `global_cash_transfer` :593, `htsoft_chinhanh` :144 | `ready()` = có tgs-bc-tk (:21-24); `branch_of_blog/shop_code` :129-177 |
| `TGS_Acc_Mapper` (mapper.php) | dòng VAT / POS → chứng từ | — | — | `branchCode` ở :221, 270, 387, 414, 475, 586, 657, 692, 727; chi nhánh chéo blog :606-616 |
| `TGS_Acc_Push_Log` (push-log.php) | nhật ký gửi | — | G `{base}tgs_acc_push_log(_tax)` :40-44, `tgs_acc_push_run` :46-50; `UNIQUE doc_key` :76 | không cột tenant / server |
| `TGS_Acc_Pusher` (pusher.php) | điều phối gửi | client mặc định :403 | B `local_ledger_item` :164-205; G push log | chi nhánh :41 / :70 / :78 / :82; **tự tạo chi nhánh** :405-424, option `tgs_acc_known_branches` :412-421 |
| `TGS_Acc_Catalog` (catalog.php) | mô tả luồng cho tab admin | — | — | ghi "branchCode = mã shop" :86, :258-259 |
| `TGS_Acc_Lists` (lists.php) | danh mục kế toán chỉ đọc | `key()` :135, client mặc định :138 | G option `tgs_acc_lists_cache` :22 (khoá theo loại) | cache dùng chung mọi blog |
| `TGS_Acc_Admin` (admin.php) | trang Cổng kế toán + AJAX | `for_display` :208, `ready` :121 / :250, `save` :396 | G `wp_blogs.tgs_site_code` :155-161 | danh sách shop từ `TGS_BCTK_Vat_Shops` :153; `asset()` tên file cũ :106 |
| `TGS_Acc_Gate` (gate.php) | REST `/pending`, `/sites` cho kế toán kéo | khoá cổng `gate_key` :46-60 | G hierarchy, `htsoft_chinhanh`, `blogs.tgs_site_code` :79-117 | một khoá cổng; `?shops=` chi nhánh → blog :138-158; mặc định mọi site trừ chính :163-166 |
| `TGS_Acc_Events` (events.php) | gửi tức thời theo sự kiện | `realtime`, `ready()` :71-77; client mặc định :457, tax :453 | F `uploads/tgs-acc-events/*.jsonl`; G lock `tgs_acc_events_retry_lock` | cron chỉ trên site chính :99-104; `build()` đòi `TGS_Acc_Source::ready()` :426 |
| `TGS_Acc_Embed` (embed.php) | menu Kế toán + SSO | `web_url/url` :61-66, `sso_secret/tenant` :150 / :161 | — | một `sso_tenant` |
| `TGS_Acc_Catalog_Sync` (catalog-sync.php, 1037 dòng) | kéo feed vào bảng global | `catalog_from_erp` :172-178, `ready()` :182 | G 7 bảng global :848-866; 5 site option :44-61 | cả lớp (gỡ ở C7) |
| `TGS_Acc_Warehouse_Sync` | phân kho kế toán → `{B}local_warehouse_zone` | `ready()` :45-47 | G option `tgs_acc_warehouse_map` (theo branchCode) :25 | chọn kho theo `shop_code($blog)` :151 |
| `TGS_Acc_Supplier_Sync` | NCC kế toán → `{G}global_supplier` | url && key :59 | G `global_supplier` :42-121 | một tenant NCC |
| `TGS_Acc_Branch_Sync` | tạo chi nhánh cho mọi site | `ready()` :45-48 | G option `tgs_acc_known_branches` | **trái với một blog = một tenant** (gỡ ở C11) |
| `TGS_Acc_Ledger_Mirror` | ghi chứng từ kế toán vào sổ blog | — | B qua `switch_to_blog` :116-139 | không (blog do người gọi chọn) |
| `TGS_Acc_Trade_Mapper` | phiếu bán kế toán → mirror | — | G `global_product_name` :315-326 | catalog global (C13) |
| `TGS_Acc_Ketoan_Events` | nhận sự kiện kế toán | secret hằng :48-51 | G `tgs_acc_ketoan_inbox` :272-296; B qua mirror | **tìm blog theo `branchCode`** :121, :249-258; bỏ `tenantId` |
| `tools/day-ke-toan.php`, `doi-chieu-ke-toan.php` | CLI đẩy / đối chiếu | `--url/--key` đè; client mặc định :115 / :91 | — | `--all` từ `TGS_BCTK_Vat_Shops` :51 / :50; re-exec theo `TGS_HOST/TGS_PATH`; **`require` tên file cũ** :33 / :36 |
| `tools/danh-muc-hang.php` | nạp / kéo / bật catalog | `save(['catalog_from_erp'])` :132 | G cursor | gỡ cùng C7 |

## 2. Trạng thái cả mạng phải tách hoặc bỏ

**Site option:** `tgs_acc_bridge_config` · `tgs_acc_bridge_calls` · `tgs_acc_known_branches(_tax)` · `tgs_acc_branch_sync_last` · `tgs_acc_catalog_cursor` / `_version` / `_sync_at` / `_sync_lock` / `_sync_dirty` · `tgs_acc_events_retry_lock` · `tgs_acc_lists_cache` · `tgs_acc_supplier_sync_at` · `tgs_acc_warehouse_map` · đọc `tgs_multisite_hierarchy_data`.

**Bảng global:** `{base}tgs_acc_push_log`, `_tax`, `tgs_acc_push_run`, `tgs_acc_ketoan_inbox`; đọc `htsoft_chinhanh`, `blogs.tgs_site_code`, `global_supplier`, `global_product_name`, `global_po_adjustment`, `global_cash_transfer`, bảng của `TGS_Global_Transfer`.

## 3. Nguồn chứng từ: mapper cần gì, lấy từ đâu

- **Đầu phiếu bán / hoàn** (mapper :213-299): `sale_id`, `return_id`, `ngay_xuat`, `so_phieu_xuat`, `ghi_chu`, `is_z`, `vat_state`, `user_id`, `nv_ten`, `ma_shop`, `ma_kh`, `ten_kh`, buyer pháp lý, `so_hd / seri / mau_hd / ngay_hd`, `items[]` — **tất cả từ tgs-bc-tk**; `export_reason` bridge tự đọc `local_ledger_advance_meta` (:43-63).
- **Dòng** (mapper :96-137): `item_id`, `ma_hang`, `ten`, `dvt`, `sl_dvcb`, `sl`, `ratio`, `don_gia_gui_thue`, `tien_chua_thue`, `ck`, `thue_suat`, `tien_thue`, `kho`, `is_gift`, `so_lo`, `exp`, `ghi_chu`. Cột POS tương ứng trên `{B}local_ledger_item`: `local_product_sku`, `local_ledger_item_unit_name / _ratio / _quantity`, `quantity`, `price`, `local_ledger_item_discount_amount`, `_tax_percent`, `_is_kct`, `_tax_amount`, `_warehouse_zone`, `_gift_type`, `lot_code`, `exp_date`, `_note` (bảng đủ ở evidence §3.2).
- **Phiếu thu (7) / chi (8)** (`payment_rows` :184-244): **đã từ sổ POS**.
- **Đã từ sổ POS**: mua / trả NCC (:252-303), xuất điều chỉnh (:314-362), kiểm kê (:486-542), chuyển kho (:412-451), chuyển tiền (:606-622), khoá đã xoá (:550-587).
- **tgs-bc-tk cho thêm**: số hoá đơn + trạng thái VAT; tiền "đúng như hoá đơn" (làm tròn Viettel); bill Z (`is_z`); buyer pháp lý; danh sách shop (`TGS_BCTK_Vat_Shops`, `TGS_BCTK_Sites`).
- **Cách xuống cấp khi không có tgs-bc-tk** (§3.6 evidence): dựng từ `local_ledger` 10 / 11 đã duyệt, dòng từ phiếu xuất con loại 2 (khuôn `supplier_doc_items` :368-403), tiền = `round(qty × price − discount)`, thuế từ `_tax_amount`; **bỏ khối `invoice`** → chỉ sổ nội bộ; `for_tax_book` (gate :181-185) tự loại; hash đổi một lần lúc chuyển nguồn.

## 4. Hợp đồng feed (bàn giao cho tsm làm `TGS_Acc_Catalog_Pull`)

`GET /api/v1/catalog/feed/meta?since=` → `{priceLists[], branches[{branchCode, priceListCode}], groups[], tombstones[], serverTime}` · `feed/products?after=&limit=1000` · `feed/prices?after=&limit=5000` → `{items[], next, more}`; tối đa 500 trang; `CURSOR_VERSION 'v3'`; nạp đầu `POST /api/v1/catalog/import`; ảnh `GET /api/v1/document-attachments/<id>/file`. Ánh xạ cột ERP → bảng ở catalog-sync :300-705 (giữ làm tham chiếu cho tsm, rồi xoá lớp).

## 5. Route vào và xác thực

| Route `tgs-acc/v1` | Xác thực | Chọn blog | file:dòng |
|---|---|---|---|
| `GET/POST /pending`, `GET /sites` | Bearer / `X-Api-Key` = khoá cổng chung | `?shops=` chi nhánh → blog; `?blogs=`; mặc định mọi site trừ chính | gate :64-70, :120-131, :138-167 |
| `POST/GET /catalog-changed` | khoá cổng chung | không — kéo cả mạng | catalog-sync :151-170 |
| `POST /ketoan-events` | HMAC `Bizcity-Signature`, secret hằng, lệch ≤ 300 giây | `blog_for_branch(data.branchCode)`; `tenantId` bị bỏ | ketoan-events :41-77, :121, :249-258 |

## 6. Nhật ký gửi, idempotency

`docKey` = `btsoft:<blog>:<kind>:<id>` (kind: sale, return, receipt, refund, purchase, supplier-return, gt-out, gt-in, export-adjust, adjust, cash_transfer). `hash()` md5 không gồm `version` (pusher :24-28), tính **trước** `decorate()`; cùng hash + `posted` + không `force` → bỏ qua; khác hash → `version + 1`. Huỷ: `cancel_keys` + `deleted_keys` → `POST /documents/cancel`; 404 → đánh dấu huỷ. `--force` chỉ bỏ bước "bỏ qua". Log **không có cột tenant / server** → admin cảnh báo phải `--force` khi đổi URL / khoá (admin :401-405). Nếu đổi dạng `docKey` thì kế toán coi mọi chứng từ là mới → **giữ dạng, thêm cột `tenant`** (B001-5).

## 7. SSO

Vé `{email, name, login, perms, tenant, iat, exp}` HMAC `sso_secret`, 120 giây (embed :149-166); `tenant = sso_tenant()` cả mạng (:161); `safe_return()` chỉ so với URL mạng (:169-181).

## 8. CLI

| Tool | Tham số | `--all` | Chuyển blog |
|---|---|---|---|
| `day-ke-toan.php` | `--blog --from --to --days --dry --force --url --key --origin` | `TGS_BCTK_Vat_Shops::active_blog_ids()`, một tiến trình con / blog | re-exec với `TGS_HOST/TGS_PATH`, đọc `get_option('tgs_site_code')` |
| `doi-chieu-ke-toan.php` | `--blog --from --to --url --key --all` | như trên | như trên; lọc báo cáo kế toán theo `branchCode` :124-127 |
| `danh-muc-hang.php` | `nap\|keo\|bat\|tat\|trang-thai --dry --full --mau --url --key` | — | — |

Cả ba `require_once dirname(__DIR__) . '/tgs-accounting-bridge.php'` (:33 / :36 / :41) — **file không còn**.

## 9. Mười lăm điều phải đổi (thứ tự, đủ ở evidence)

1. Tầng B + nghĩa khoá (C1) · 2. client theo blog (C2) · 3. `tgs_okt_connected` thay `ready()` (C6) · 4. pusher / events / gate / CLI dùng khoá blog (C3) · 5. bỏ mô hình chi nhánh, gỡ Branch_Sync (C11) · 6. nhật ký gửi có tenant (C8) · 7. route vào theo tenant (C5) · 8. gỡ catalog sync (C7) · 9. supplier / warehouse / lists theo blog (C9) · 10. SSO theo blog (C12) · 11. trade-mapper đọc bảng blog (C13) · 12. nguồn thứ hai từ sổ POS (C10) · 13. màn admin theo blog (C4) · 14. cron lặp blog (C14) · 15. tài liệu (`MO-RONG.md:57, 61, 141-155`, `class-tgs-acc-catalog.php:86, 258-262`).
