<!-- [2026-11-24 09:50 PM Johnny Chu - Chu Hoàng Anh] SW4b-2 — bằng chứng kiểm thử danh mục bằng dryRun. Không ghi khoá. -->
# SW4b-2 — kiểm thử danh mục ở kế toán (dryRun), lỗi theo dòng — 24/11/2026

open-pos-backend PHASE-001 doc 06 §4 / doc 16 §5. Trước lần đẩy thật (SW2), danh mục của shop được gửi vào đường nhập Excel **chặt nhất** của kế toán, `POST /api/v1/catalog/products/import {dryRun: true}`. Kế toán kiểm từng dòng và **không ghi gì**.

## Làm gì

- **Bridge `TGS_Acc_Catalog_Dryrun`.** Gọi bằng khoá của chính blog.
  - Mỗi mã còn sống thành một dòng:
    - `sku`, `name`;
    - `unit` = ĐVT tỉ lệ 1 trong bảng ĐVT;
    - `groupCode` = `id_sync_api ?: id`, cùng quy ước với SW4b-1 và chiều kéo về;
    - `vatRate` = `TGS_Tax_Code::okt_code`;
    - `barcode`, `isActive`.
  - Mã cha luôn đứng trước mã con. Mỗi lô tối đa 2.000 dòng; một lô lỗi mạng thì các lô sau vẫn kiểm.
  - Kết quả gần nhất lưu vào option `tgs_acc_catalog_dryrun` của blog (không autoload): tổng, số sẽ thêm / sẽ sửa / lỗi, và tối đa 2.000 dòng lỗi.
  - CLI: `wp tgs-acc dryrun --shop=<id>`. Nút bấm chạy qua `admin-post`.
- **Ô "Kiểm thử ở kế toán" trong màn tsm "Chuẩn hàng hoá".** tsm chỉ thêm một dòng `do_action('tgs_catalog_readiness_panels', $blog)`; bridge tự gắn ô vào đó. Không có bridge thì màn tsm vẫn đủ, chỉ không có ô (P-0 ý 4).

## Chạy thật (okt-thu)

| Shop / tenant | Kết quả | Ghi chú |
|---|---|---|
| shop-c (tenant đã dựng sẵn ở SW4b-1) | 3 mã · **1 sẽ thêm** · 2 lỗi | `920011001`: nhóm cấp 3 `SW4BTHU\NHAN\NHOM`, mã khớp đoạn 92·001·1 → kế toán nhận. `900000001` / `900000002`: *Nhóm hàng "Hàng thử lát dọc" mới là nhóm cấp 1 — phải chọn tới nhóm cấp 3*, đúng cảnh báo H-10 của điểm sẵn sàng tsm. |
| okt-wp1 (tenant lát dọc, **chưa** dựng sẵn) | 3 mã · 3 lỗi | *Nhóm hàng "1" chưa có trong danh mục*. Đúng thứ tự: dựng sẵn tenant (SW4b-1) trước, kiểm thử sau. |

- **Không ghi gì:** sau khi kiểm thử, `GET /api/v1/products` của tenant shop-c vẫn trả **0 mặt hàng**.
- **Đường bấm thật** (HTTP, cookie đăng nhập thật): POST `admin-post.php` → 302 về màn Chuẩn hàng hoá `#tgs-acc-dryrun`; màn trả 200, 0 lỗi PHP, hiện tổng và bảng dòng lỗi. Đã thử trên cả hai bản cài.
- Mã thử `920011001` đã xoá khỏi bảng dev sau khi chạy.

## Test

`tests/catalog-dryrun-wp-test.php` (shop-b, client giả; mã, nhóm, ĐVT tạm đều được dọn): **14/14**.
- **Dựng dòng:**
  - mỗi mã còn sống một dòng, đánh số 1…n;
  - mã cha đứng trước mã con, kể cả khi mã con được thêm vào bảng trước;
  - `groupCode` lấy `id_sync_api`;
  - ĐVT là ĐVT tỉ lệ 1 (Lon), không lấy cột ĐVT của mã;
  - thuế 8 → `8`, KCT → `KCT`, chưa khai → không gửi (kế toán báo thiếu);
  - `isActive` đúng.
- **Gửi:**
  - lô 2 dòng ra 4 lô, mỗi lô đều `dryRun = true`;
  - gộp đúng tổng và số dòng lỗi;
  - có lỗi thì `ok = false` và kết quả được lưu;
  - một lô lỗi mạng chỉ báo lô đó.
- **Màn:**
  - có ô "Kiểm thử ở kế toán", phần tổng và nút;
  - bảng dòng lỗi có SKU lỗi;
  - gỡ hook thì màn tsm vẫn đủ;
  - nút với nonce sai thì dừng.

**Hồi quy:**
- bridge: 17 test không cần WP (gồm MS0), `tenant-prepare` 16/16, `catalog-source` 8/8;
- tsm: `catalog-readiness` 28/28, `catalog-sku` 23/23, trên hai bản cài.

## Tiêu chí hoàn tất SW4 (doc 06 §4)

"Blog thử đạt 100 %, dryRun 0 lỗi": **công cụ đã đủ** (điểm sẵn sàng SW4a-3, dựng sẵn SW4b-1, kiểm thử SW4b-2). **Dữ liệu thử chưa đạt**: các mã lát dọc 9000000xx chưa gắn nhóm cấp 3. Muốn đạt thì phải gắn nhóm cấp 3 cho các mã đó, sửa mã theo tiền tố nhóm, rồi dựng sẵn tenant. Việc này đổi SKU của các mã đang dùng cho ca bán chuẩn, nên chờ chủ quyết.
