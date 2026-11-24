<!-- [2026-11-24 09:10 PM Johnny Chu - Chu Hoàng Anh] SW4b-1 — bằng chứng dựng sẵn tenant. Không ghi khoá. -->
# SW4b-1 — dựng sẵn tenant trước lần đẩy danh mục đầu — 24/11/2026

open-pos-backend PHASE-001, doc 16 §4 (SW2 bước 0). Tenant mới tạo bằng `db:seed` **không có** cây nhóm hàng và bảng giá, và `/catalog/import` không tạo được nhóm. Hai tenant thử trên okt-thu (của shop-a và shop-c) đúng là như vậy: 0 nhóm, 0 bảng giá, chỉ có chi nhánh CN01.

## Làm gì

- **Bridge `TGS_Acc_Tenant_Prepare::run($blog, $dry)`** gọi bằng khoá của chính blog (`for_blog`), theo thứ tự:
  1. **Cây nhóm**, cha trước con: `PUT /api/v1/item-classes/group/:code?new=1`, cấp 1–3 kèm `skuCode`.
     - Mã nhóm bên kế toán = `id_sync_api ?: id`, cùng quy ước với chiều kéo về (`ensure_cat`).
     - Nhóm đã có thì không ghi đè; mã SKU hoặc nhóm cha lệch thì chỉ báo.
     - Nhóm cha lỗi thì bỏ qua cả nhóm con.
  2. **Bảng giá công ty** (tsm `is_company_default`; không có thì lấy bảng đang áp của shop), thêm bảng của shop nếu khác. **Chỉ tạo bảng còn thiếu.** Lý do: open-ketoan `savePriceList` ghi đè tên và cờ của bảng đã có; không gửi `isCompany` là **tắt** cờ công ty; gửi `isCompany = true` thì bảng công ty khác mất cờ. Vì vậy tenant đã có bảng công ty khác thì bảng của shop được tạo như bảng thường, kèm lưu ý.
  3. **Chi nhánh** của blog (`branch_of_blog`): chưa có thì `POST /api/v1/branches`.
  4. **Gán chi nhánh** cho bảng giá của shop: gửi **hợp** của danh sách đang gán và chi nhánh của shop, vì lệnh này thay cả danh sách. Chi nhánh đang áp bảng giá khác thì không đổi, chỉ lưu ý.
  - `$dry`: chỉ GET và trả kế hoạch, không ghi lên kế toán, không ghi mã SKU nhóm vào tsm. Chạy lại an toàn.
  - CLI: `wp tgs-acc prepare --shop=<id> [--run]`. `TGS_Acc_Api_Client::put_json`.
- **tsm `TGS_Catalog_Sku::ensure_codes($blog, $apply)`**: duyệt mọi nhóm theo độ sâu, tính cấp (bỏ gốc chung "Nhóm hàng"), cấp mã SKU cho nhóm cấp 1–3 cùng luật bộ sinh mã SW4a-2 (đã khai → suy từ mã hàng → cấp số mới), và báo lỗi khi mã đã khai sai dạng (cấp 1 phải là 2 chữ số, không bắt đầu bằng 0). Mã chỉ ghi lại vào nhóm khi chạy thật **và** shop tự quản.

## Chạy thật trên okt-thu, tenant của shop-c (blog 6, khoá cài đặt + `X-OKT-Blog`)

| Lượt | Kết quả |
|---|---|
| chạy thử | 4 việc sẽ làm: nhóm 2 (mã mới 91), nhóm 1 (**suy** 90 từ các SKU 9000000xx), bảng LATDOC làm bảng giá công ty, áp cho CN01; CN01 đã có |
| `--run` | 4 việc đã làm, 0 lỗi |
| `--run` lần 2 | 0 việc mới, **5 đã có** (chạy lại không đổi gì) |
| thêm cây thử 3 cấp `SW4BTHU\NHAN\NHOM`: chạy thử rồi `--run` | 3 việc đã làm: nhóm 114 (cấp 1, 92) → 115 (cấp 2, 001, cha 114) → 116 (cấp 3, 1, cha 115) |

Đọc lại từ kế toán: 5 nhóm, cha và `skuCode` đúng; bảng giá LATDOC `isCompany = true`, áp cho CN01. Phía tsm đã ghi mã nhóm 90 / 91 / 92 / 001 / 1.

**Hai bên sinh cùng một mã hàng.** `GET /api/v1/catalog/next-sku?groupCode=116` của open-ketoan trả **920011001** (92·001·1), và `TGS_Catalog_Sku::next()` của tsm cũng trả **920011001**. Với nhóm 1 (mới ở cấp 1), cả hai cùng báo phải chọn tới nhóm cấp 3.

okt-wp1 (một site, tenant lát dọc): chỉ chạy thử, ra kế hoạch 4 việc, không ghi gì.

## Test

`tests/tenant-prepare-wp-test.php` (shop-b, client giả, cây nhóm tạm được dọn): **16/16**.
- **Chạy thử:** 0 lệnh ghi, không ghi mã vào tsm, kế hoạch cha trước con.
- **Tenant trống:** 3 PUT nhóm `?new=1` đúng thứ tự, có cha và `skuCode` dài 2 / 3 / 1; mã ghi lại vào tsm; tạo bảng giá công ty; tạo chi nhánh rồi áp bảng giá.
- **Tenant đã có một phần:** nhóm khớp thì bỏ qua; nhóm lệch mã thì báo, không ghi đè; bảng giá đã có thì không PUT lại; gán chi nhánh bằng hợp danh sách (CN02 + CN01); chi nhánh đã có thì không POST.
- **Không cướp cờ:** tenant đã có bảng công ty CTY thì bảng của shop có `isCompany = false` kèm lưu ý; chi nhánh đang áp CTY thì không đổi.
- **Lỗi và nguồn:** nhóm cha lỗi thì không gửi nhóm con; shop theo nguồn kế toán thì vẫn dựng tenant nhưng không ghi mã vào tsm.

Lần chạy đầu sai 2 ca. Nguyên nhân là `$wpdb->get_col` trả `null` cho chuỗi rỗng, không phải mã bị ghi nhầm; đã sửa ở test.

**Hồi quy:**
- bridge: 17 test không cần WP (gồm MS0 census) và `catalog-source-wp-test` 8/8;
- tsm: `catalog-sku` 23/23, `catalog-readiness` 28/28, `catalog-standard` 47/47, trên cả hai bản cài.

## Dữ liệu thật đã ghi
- **okt-thu**, tenant của shop-c: 5 nhóm (1, 2, 114, 115, 116), bảng giá LATDOC (bảng công ty), áp cho CN01. open-ketoan **không xoá được nhóm**; nếu cần thì bỏ tích "Đang dùng" ở kế toán.
- **okt-wp dev**: bảng nhóm của mạng có thêm cây thử `SW4BTHU` (114–116) và mã nhóm 90 / 91 / 92 / 001 / 1.

## Chưa làm
- SW4b-2: `dryRun` của `/catalog/products/import`.
- Nút "Dựng sẵn ở kế toán" trên màn (hiện chỉ có CLI). Màn sẽ gắn cùng SW2-2 (lần đầu bật).
- Nhóm quà khuyến mại cấp 1 `Q`: open-ketoan chặn mã cấp 1 không phải 2 chữ số (GRP-BLOCK-008), trong khi `sku-rule.ts` của chính nó lại cho phép `Q`. Đây là lệch phía open-ketoan, cần báo bên đó.
