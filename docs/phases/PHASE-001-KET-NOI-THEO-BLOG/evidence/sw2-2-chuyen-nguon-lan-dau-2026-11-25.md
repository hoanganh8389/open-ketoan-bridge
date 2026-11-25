<!-- [2026-11-25 04:00 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — bằng chứng: chuyển nguồn danh mục lần đầu local → ketoan (ADR-OKT-1 §5.2). -->
# SW2-2 — chuyển nguồn lần đầu local → ketoan — 25/11/2026

Thủ tục do bridge làm (`TGS_Acc_Switch`), nối vào hai filter tsm đã chừa sẵn: `tgs_catalog_switch_to_ketoan_ready` (mở khoá nút) và `tgs_catalog_switch_to_ketoan` (chạy). tsm chỉ đặt `tgs_catalog_source = ketoan` khi nhận `['ok' => true, 'diff' => 0]`. Bridge **không tự đặt nguồn** (CLI có `--set` cho người vận hành).

## Điều kiện (`ready`, không ghi gì)
Nguồn đang `local` · kết nối 🟢 (SW2-1, bốn điều kiện) · chuẩn hàng hoá 100 % (tsm `ready_to_push`).

## Sáu bước (`run`, dừng ở bước hỏng đầu tiên — shop vẫn tự quản, quầy vẫn bán)
| # | Bước | Cách làm |
|---|---|---|
| 1 | Dựng sẵn tenant | `TGS_Acc_Tenant_Prepare` (SW4b-1): cây nhóm, bảng giá, chi nhánh — chỉ tạo cái thiếu |
| 2 | dryRun | `TGS_Acc_Catalog_Dryrun` (SW4b-2): kế toán kiểm từng dòng, không ghi. Lỗi → dừng, **chưa đẩy mã nào** |
| 3 | Đẩy mặt hàng | `POST /catalog/products/import` dryRun=false, lô 200, cha trước con |
| 4 | Đẩy ĐVT + bảng giá + giá | `POST /catalog/import`, dữ liệu đọc từ bảng của **blog** (`build_import($skus, $blog)` đọc qua `tgs_catalog_table`; chi nhánh theo `branch_of_blog`) |
| 5 | Kéo về, gắn định danh | `feed/products` bằng khoá của blog → ghi `item_id`, `parent_item_id`, Biến thể cho mã local theo SKU. Chỉ ba cột này; lượt kéo đầy đủ là SW2-3 |
| 6 | Đối chứng | mọi mã local có ở kế toán · đúng thuế · đúng từng dòng giá (bảng giá·SKU·ĐVT); hash hai bên; `diff` = số chỗ lệch. Giới hạn theo mã của blog (bảng giá mạng còn dòng của blog khác) |

Mỗi lần chạy ghi nhật ký vào option của blog (`tgs_acc_switch_log`, 20 lần). Chạy lại an toàn (kế toán ghi đè đúng dòng; mã đã gắn `item_id` không ghi lại).
CLI: `wp tgs-acc switch --shop=<id>` (chỉ hỏi điều kiện) · `--run` (thật) · `--run --set` (đặt luôn nguồn).

## Chạy thật (shop-c ↔ okt-thu, tenant `shopc`, 25/11)
1. `switch --shop=6` → đủ điều kiện (🟢, 100 %).
2. `--run` lần 1 → **dừng ở dryRun**, chưa đẩy gì: *"Nhóm hàng 'Hàng thử lát dọc' mới là nhóm cấp 1 — phải chọn tới nhóm cấp 3"*. Chuẩn hàng hoá báo 100 % nhưng kế toán từ chối — xem **Nợ** bên dưới. Dữ liệu thử đã chỉnh: hai mã chuyển sang nhóm cấp 3 `920011` và đổi SKU thành `920011001/002` (đúng luật "mã bắt đầu bằng mã nhóm cấp 3").
3. `dryrun` → 2 mã, 0 lỗi. `--run` → đẩy 2 mã, `item_id` ULID được gắn; lần này đối chứng báo lệch 7 do **dòng giá của blog khác** nằm trong bảng giá mạng → sửa: chỉ đối chứng mã của blog.
4. `--run` lần cuối: prepare 8 đã có · dryRun 0 lỗi · đẩy 2 · nạp 2 bảng giá / 2 mã / 2 giá · **đối chứng lệch 0**, hash `d285c8f0361c52fe1ba9992db8deeca4`.
5. Nút tsm: `tgs_catalog_switch_to_ketoan_ready(6)` = true; filter chạy trả `ok=1 diff=0`; đặt nguồn `ketoan` rồi trả về `local` (nhật ký 3 dòng) — dev, không để lại nguồn ketoan.

## Test
`tests/switch-wp-test.php` **28/28** (shop-c, client giả): ba điều kiện của `ready`; dừng đúng ở prepare / dryRun / push / import / pull mà không đẩy gì thêm; đối chứng bắt thiếu mã, lệch thuế, lệch giá; đạt → `ok`, `diff 0`, `item_id` gắn, **không đặt nguồn**; nhật ký; chạy lại cùng hash, không ghi lại; `build_import` theo blog chỉ lấy mã của blog; lớp không ghi bảng POS.
Hồi quy: connection 32/32, blog-keys 33/33, dryrun 14/14, tenant-prepare 16/16, catalog-source 2/2, selfcheck 70/70, install 37/37, config 8/8, profile 11/11, MS0 34 tệp 0 vi phạm.

## Nợ / chưa làm
- **SW2-0c (mới):** chuẩn hàng hoá (tsm) báo 100 % dù mặt hàng nằm ở nhóm **cấp 1** (kế toán đòi cấp 3) — dryRun mới bắt. Thêm kiểm "mặt hàng phải thuộc nhóm cấp 3". Cùng nhóm với **SW2-0a** (mã phải bắt đầu bằng mã nhóm cấp 3).
- Giá chỉ đối chứng theo dòng giá local; đối soát đêm là SW2-3.

## Chạy từng lượt, không một request dài (chủ chốt 25/11)
Thủ tục là **máy trạng thái** (option `tgs_acc_switch_state` của blog); mỗi `step()` làm đúng MỘT việc: dựng tenant · dryRun · một lô 200 mã · một lượt nạp ĐVT/giá · một trang feed mặt hàng (1.000) · một trang feed giá (5.000) · tổng đối chứng. Đối chứng chạy cuốn chiếu theo trang feed (không giữ cả danh mục trong bộ nhớ; mã đã thấy lưu ở transient).
- **admin-ajax `tgs_acc_switch`** (op `start | step | status | cancel`; nonce + quyền như nút ở tsm): màn tsm lặp `step` tới khi xong rồi gửi form để tsm đặt nguồn.
- **Cron nền** `tgs_acc_switch_tick` (`start` với `cron = 1`, ô "Chạy nền" ở màn): mỗi 20 giây một lượt; đóng trang không mất việc; mở lại màn thấy tiến độ.
- Nút tsm: kết quả đạt còn hiệu lực 10 phút (`fresh`) thì **dùng luôn, không chạy lại**; hết hạn / chưa chạy → chạy trọn (cách cũ, shop nhỏ).
- Chạy thật shop-c ↔ okt-thu qua từng lượt: 8 lượt (prepare → dryrun → push → import ×3 → pull_products → pull_prices → done), cùng hash `d285c8f0361c52fe1ba9992db8deeca4`. `switch-wp-test` **46/46** (thêm: thứ tự pha, batch 1 → mỗi lượt đẩy 1 dòng, busy, huỷ, cron hẹn / gỡ lịch, filter dùng lại kết quả, ajax đăng ký).
