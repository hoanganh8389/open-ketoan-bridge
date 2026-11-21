# PHASE-001 (bridge) · 40 — Master checklist

> ☐ chưa làm · 🟡 có mã + test · ✅ có bằng chứng chạy thật · ⛔ chờ chủ / repo khác · ⏸ hoãn · ❌ huỷ.
> Nơi giữ trạng thái **duy nhất** của `B001-*`. Mốc M1–M5 theo bản đồ chương trình (tsm `05-CHUONG-TRINH-3-REPO.md`). Mã việc C1–C14 trùng với `docs/CAU-HINH-KET-NOI.md §8` (C1–C7) và mở rộng.

<!-- [2026-11-21 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-001 (bridge) W0. -->

## B0 — tài liệu
| ID | Việc | Doc | Trạng thái |
|---|---|---|---|
| B001-W0a | `docs/CAU-HINH-KET-NOI.md` — bốn trường hợp, ba tầng, K1–K13, T-CFG-1…12 | — | 🟡 2026-11-21 01:39 PM · PR #1 chờ duyệt |
| B001-W0b | Bộ hồ sơ phase + khảo sát máy 21/11 | — | 🟡 2026-11-21 02:20 PM |
| B001-DOC | `docs/MO-RONG.md:57, 61, 141-155` và `includes/class-tgs-acc-catalog.php:86, 258-262` bỏ "branchCode = mã shop", "tự tạo chi nhánh"; `docs/LUONG-BAN-HANG-SANG-KE-TOAN.md` thêm mục nguồn thứ hai | 10 §9 | ☐ (M4) |

## M1 — sửa ngay + cấu hình theo blog
| ID | Việc | Doc | Trạng thái |
|---|---|---|---|
| B001-FIX1 | `require_once` tên file cũ → `open-ketoan-bridge.php` ở `tools/day-ke-toan.php:33`, `doi-chieu-ke-toan.php:36`, `danh-muc-hang.php:41`, `includes/class-tgs-acc-admin.php:106`; cron hint `admin/page-cong-ke-toan.php:277-279` | 10 §8 | ☐ |
| B001-C1 | Tầng cấu hình H → B → M: `key_for`, `key_legacy`, `url_for`, `key_mode`, `current_blog`, `save_blog`, `for_display_blog`; `key()` giữ hành vi cũ | 00 B001-1 | 🟡 2026-11-21 01:52 PM · [PR #2](https://github.com/hoanganh8389/open-ketoan-bridge/pull/2), 8/8 kịch bản · 51 phép kiểm; **chưa chạy trong WordPress thật** |

## M2 — câu hỏi kết nối
| ID | Việc | Doc | Trạng thái |
|---|---|---|---|
| B001-C2 | `TGS_Acc_Api_Client::for_blog($blog)` (WP_Error nếu không có khoá riêng); constructor mặc định = blog hiện tại; `health()` / `ping()` theo blog; nhật ký 60 lượt gọi thêm `blog_id`, xem theo blog | 00 §4 | ☐ |
| B001-C6 | `includes/class-tgs-acc-connection.php`: `tgs_okt_connected()` (4 điều kiện, transient 60 giây, timeout 3 giây, kiểm trùng tenant), `tgs_okt_connection()`, bản đồ tenant → blog (site option `tgs_okt_tenant_map`), hook `tgs_okt_connection_changed`; thay `TGS_Acc_Config::ready()` ở admin :121 / :250, events :76-87, catalog-sync :182, branch / warehouse / supplier `ready()` | 00 §4 | ☐ |
| B001-C4 | Màn Network "Khoá theo shop" (cột: blog, nguồn khoá, khoá che, tenant, trạng thái 🟢🟡⚪🔴, lần kéo) + nút Kiểm tất cả / dòng / Xuất CSV (không khoá); màn Kết nối của site (URL riêng, khoá, Kiểm tra, Xoá); AJAX `tgs_acc_blog_save` / `_check` / `_list`; `shops()` từ blog đã kết nối | `CAU-HINH §4` | ☐ |
| B001-T2 | `tests/connection-test.php`: 4 điều kiện, transient, trùng tenant, site 0, bridge tắt | 70 | ☐ |

## M3 — sự kiện vào theo tenant, gỡ kéo catalog
| ID | Việc | Doc | Trạng thái |
|---|---|---|---|
| B001-C5 | `/ketoan-events`: xác thực → `tenantId` → blog (bản đồ C6) → `switch_to_blog` → mirror; không tìm ra → 404; `/catalog-changed`: xác thực theo tenant (khoá của blog hoặc chữ ký), phát `tgs_okt_catalog_changed($blog, $payload)`, **không kéo**; `/pending`, `/sites`: blog theo tenant của khoá gọi, bỏ `?shops=` và `branchCode` | 10 §5 | ⛔ chờ tsm S001-C8 (có người lắng hook) |
| B001-C7 | Gỡ `includes/class-tgs-acc-catalog-sync.php` (require ở bootstrap :68 / :82), `tools/danh-muc-hang.php`, option `catalog_from_erp` (config :34 / :236-238 / :166-167, admin :390), 5 site option `tgs_acc_catalog_*`, filter `tgs_catalog_readonly` / `tgs_price_edit_locked`; giữ §4 doc 10 làm tham chiếu cho tsm | 10 §4 | ⛔ chờ tsm S001-C8 chạy trên site thử |
| B001-C9 | `TGS_Acc_Lists` cache khoá `blog\|kind`, client theo blog; `TGS_Acc_Warehouse_Sync` map theo blog, `sync()` theo khoá blog; `TGS_Acc_Supplier_Sync` **tạm tắt** cho blog không có bảng NCC theo blog (mua hàng ngoài phase) | 00 B001-9 | ☐ |

## M4 — chứng từ theo tenant
| ID | Việc | Doc | Trạng thái |
|---|---|---|---|
| B001-C3 | `TGS_Acc_Pusher::send()` :403, `TGS_Acc_Events::deliver()` :453-457, `day-ke-toan.php:115`, `doi-chieu.php:91`: client theo blog; không khoá riêng → `key_legacy()` kèm nhãn `legacy` trên kết quả / màn hình; không có cả hai → bỏ qua, ghi lý do; `--all` lặp blog | `CAU-HINH §6` | ☐ |
| B001-C8 | `tgs_acc_push_log(_tax)`: thêm `tenant VARCHAR(64)`, `server_url`; `UNIQUE(tenant, doc_key)`; `find_many` / `keys_in_range` / `search` / `stats` lọc theo tenant; DB_VERSION 4; bỏ cảnh báo admin :401-405 | 10 §6 | ☐ |
| B001-C10 | `includes/class-tgs-acc-source-pos.php`: `sale_rows` / `return_rows` từ `{B}local_ledger` 10 / 11 (đã duyệt, không `pg:`), dòng từ phiếu xuất con loại 2 / phiếu hoàn (khuôn `supplier_doc_items`), không `invoice`; `TGS_Acc_Source::for_blog($blog)` chọn tgs-bc-tk nếu có, không thì POS; thay guard `ready()` ở gate :200, events :426, admin :247, day-ke-toan :109; danh sách shop = blog đã kết nối | 10 §3 | ☐ (Q001-15 ✅ 21/11) |
| B001-C11 | Bỏ `branchCode` chi nhánh (mapper 9 chỗ → chi nhánh mặc định của tenant từ `tgs_okt_connection()`), bỏ `ensure_branch` (pusher :405-424), gỡ `class-tgs-acc-branch-sync.php` + hook bootstrap :80, option `tgs_acc_known_branches(_tax)`, `tgs_acc_branch_sync_last`; chuyển kho / chuyển tiền chéo blog khác tenant → bỏ qua + log (00 §5) | 10 §1 | ☐ |
| B001-C12 | SSO: `tenant` và URL theo blog đang đứng; `sso_tenant` xuống tầng B; `safe_return()` so với `url_for($blog)` | 10 §7 | ☐ |
| B001-C13 | `TGS_Acc_Trade_Mapper::catalog()` :315-326 đọc `tgs_catalog_table('product', $blog)` | 10 §1 | ⛔ chờ tsm S001-C11 |
| B001-T4 | `tests/source-pos-test.php` (fixture sổ POS → chứng từ, không `invoice`), `tests/push-log-tenant-test.php` | 70 | ☐ |

## M5 — vận hành
| ID | Việc | Doc | Trạng thái |
|---|---|---|---|
| B001-C14 | Cron `tgs_acc_events_retry`, `warehouse_sync`: lặp blog, khoá của blog; `--all` bỏ `TGS_BCTK_Vat_Shops` | `CAU-HINH §3.3` | ☐ |
| B001-G | Ca golden G5, G6, G8, G10–G13 trên multisite thử (script tsm S001-G6) | 70 | ☐ |

## Phía kế toán (theo dõi, làm ở repo open-ketoan theo R-FLOW)
| ID | Việc | Chặn |
|---|---|---|
| S001-K11 | Mỗi tenant một kết nối ngược trỏ REST của blog | N1 (G7 ≤ 1 phút) |
| S001-K10 | Cấp tenant cho shop mới | production |
| S001-K8 | Mã tự tạo từ đơn và mã shop gửi cùng SKU → cùng `item_id` | G9 |
