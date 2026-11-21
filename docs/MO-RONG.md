# Cổng kế toán — hướng dẫn cho team

Plugin `tgs_accounting_bridge` đưa chứng từ từ hệ thống bán hàng (WordPress multisite, mỗi shop một blog) sang phần mềm kế toán qua API. Tài liệu này dành cho người sửa / mở rộng plugin.

## 1. Bức tranh

```
Shop (blog) ──đọc──▶ TGS_Acc_Source ──▶ TGS_Acc_Mapper ──▶ TGS_Acc_Pusher ──▶ TGS_Acc_Api_Client ──HTTP──▶ Phần mềm kế toán
                     (sổ bán hàng,       (dựng chứng từ     (so nhật ký,      (X-Api-Key, nhật ký
                      dòng VAT bc-tk)     theo khuôn API)     phiên bản, huỷ)   60 lượt gọi)
                                                                 │
                                                  bảng tgs_acc_push_log (từng chứng từ)
                                                  bảng tgs_acc_push_run (từng lượt gửi)
```

Ba cách kích hoạt, cùng đi qua `TGS_Acc_Pusher::push()`:

| Cách | Ở đâu | Nguồn ghi trong "Lượt gửi" |
|---|---|---|
| Nút **⇄ Đẩy sang Kế toán** | Màn Quản lý phiếu xuất bán (VAT) / phiếu điều chỉnh của tgs-bc-tk | Nút trên web |
| Tab **Gửi chứng từ** | Quản trị → Kế toán → Cổng kế toán | Nút trên web |
| Lịch chạy | `tools/day-ke-toan.php --all --days=2` (cron aaPanel) | Lịch chạy |
| Dòng lệnh | `tools/day-ke-toan.php --blog=… --from=… --to=…` | Dòng lệnh |

Trình duyệt luôn gửi **từng shop × từng đoạn ngày ngắn** (mặc định 7 ngày, sửa ở Cài đặt) để một lượt gọi không chạy quá lâu.

### 1b. Gửi tức thời theo sự kiện (đường chính) — `TGS_Acc_Events`

```
Mọi plugin ghi phiếu (POS, quản lý shop, chuyển kho, chuyển kho nội bộ, mua hàng…)
   │  INSERT / UPDATE / DELETE vào {prefix}local_ledger, {prefix}local_ledger_item, {base}global_cash_transfer
   ▼
bộ lọc 'query' của WordPress  ──▶  sự kiện {blog, kind, ref, op}  ──▶  uploads/tgs-acc-events/YYYY/MM/YYYY-MM-DD.jsonl
   │ (cuối request, sau khi trả trang cho người dùng)
   ▼
TGS_Acc_Pusher::build_refs (dựng ĐÚNG các phiếu đó) → send (so nhật ký, phiên bản) → cancel (phiếu không còn)
   │ lỗi
   ▼
…/YYYY-MM-DD.retry.jsonl  ◀── cron tgs_acc_events_retry mỗi phút (site tổng) gửi lại, giãn cách 1→60 phút, tối đa 30 lần
```

- **Không phải sửa plugin khác**: màn mới ghi vào các bảng trên tự có sự kiện. Plugin ghi dữ liệu ở bảng KHÁC mà ảnh hưởng chứng
  từ thì tự bắn: `do_action('tgs_acc/event', 'ledger', $local_ledger_id [, $blog_id]);` hoặc `do_action('tgs_acc/event', 'cash_transfer', $ct_id);`.
- **Không thêm bảng**: sự kiện, kết quả gửi, nhóm gửi lại đều là JSONL theo ngày (thư mục có .htaccess chặn truy cập web).
- **Không quét shop**: cron chỉ đọc các tệp `.retry.jsonl` 7 ngày gần nhất. Phiếu cũ trước khi bật, phiếu sót (khôi phục dữ liệu
  hàng loạt > 2.000 phiếu / lượt chỉ ghi một sự kiện `bulk`) → nút **Kéo chứng từ** ở trạm ACC14.
- **Bật / tắt**: Cổng kế toán → Cài đặt → "Gửi tức thời theo sự kiện", hoặc `define('TGS_ACC_REALTIME', false)`. Tắt thì vẫn ghi
  sự kiện, chỉ không gửi. Xem ở tab **Sự kiện (tức thời)** (kết quả từng sự kiện, nhóm chờ gửi lại, nút "Gửi lại ngay").
- **ERP quyết định ghi sổ**: Chế độ định khoản từng loại chứng từ ở trạm ACC14 — "Tự động định khoản" (vào sổ luôn, sửa thì định
  khoản lại phần đổi, xoá / bỏ duyệt thì tự huỷ) hoặc "Vào cổng chờ" (kế toán duyệt). Thẻ "Tự động định khoản" ở ACC14.
- Ba đường (kéo cổng chờ, đẩy theo khoảng ngày, sự kiện) dùng chung `TGS_Acc_Pusher::build` / `send` / `decorate` và cùng mã
  băm → sửa mapper một chỗ, cả ba đổi theo; không tăng phiên bản vô cớ khi đổi đường.
- **Hai nơi nhận**: sổ nội bộ (mọi chứng từ) và **sổ thuế** (khai địa chỉ + khoá sổ thuế ở Cài đặt, hoặc `TGS_KETOAN_TAX_API_URL` / `TGS_KETOAN_TAX_API_KEY`): chỉ phiếu bán / hoàn có số hoá đơn điện tử (`TGS_Acc_Gate::for_tax_book`). Dựng một lần, gửi từng nơi; nhật ký gửi riêng (`tgs_acc_push_log` / `_tax`), gửi lại riêng (`target` trong tệp retry); phiếu mất / huỷ hoá đơn thì sổ thuế nhận lệnh huỷ. Cổng chờ (kéo, `consumer=tax`) vẫn dùng để bù.

## 2. Luật không được phá

- **Khoá chứng từ cố định** (`docKey`): `btsoft:{blog}:sale:{sale_id}`, `btsoft:{blog}:return:{return_id}`. Đổi cách đặt khoá = bên kế toán coi là chứng từ mới = ghi trùng.
- **Phiên bản**: nội dung đổi (mã băm khác) → `version + 1`; bên kế toán ghi số âm bút toán cũ rồi ghi bút toán mới. Không bao giờ gửi phiên bản thấp hơn.
- **Huỷ**: phiếu đã gửi mà nay không còn ở shop → `POST /documents/cancel`. Không xoá gì bên kế toán.
- **Tiền** lấy theo đúng luật màn Quản lý VAT (`TGS_BCTK_Ajax::vat_rows_for_export`) — khớp hoá đơn đã phát hành. Không tự tính lại tiền trong plugin.
- **Đọc chéo shop**: mọi truy vấn dùng `$wpdb->get_blog_prefix($blog_id)`, không dùng hằng `TGS_TABLE_*` (hằng bám site đang đứng). Nhờ vậy nút ở site tổng gửi được cho mọi shop.
- **Bill Z** chỉ vào sổ nội bộ (`internalOnly = true`), không kèm hoá đơn.
- **Khoá API** không bao giờ trả ra trình duyệt hay in ra nhật ký (chỉ dạng che `kt_ab…xy`).

## 3. Thêm một luồng chứng từ mới (ví dụ: phiếu nhập mua)

1. **Bên kế toán** (`tgs-ketoan`): thêm loại chứng từ vào `packages/contracts/src/documents.ts` (`ingestDocumentSchema`), cấu hình định khoản, test. Chưa có loại bên đó thì API trả lỗi kiểm dữ liệu.
2. **Đọc dữ liệu**: thêm hàm vào `TGS_Acc_Source` (nhận `$blog_id`, đọc theo `get_blog_prefix`).
3. **Dựng chứng từ**: thêm hàm vào `TGS_Acc_Mapper` trả mảng đúng khuôn, `docKey` theo mẫu `btsoft:{blog}:{loại}:{id}`.
4. **Gửi**: trong `TGS_Acc_Pusher::build()` gom thêm chứng từ loại mới; thêm loại vào vòng huỷ (`foreach (['sale', 'sale_return', …])`).
5. **Danh mục API**: trong `TGS_Acc_Catalog::all()` đổi mục "Dự kiến" thành `status => 'live'`, ghi `trigger`, `source`, `key`, `fields`; khai `sample => '<docType>'` để tab Danh mục API có nút "Xem dữ liệu thật sẽ gửi".
6. **Nhật ký**: thêm tên loại vào `TYPE` trong `assets/cong-ke-toan.js` và ô chọn "Loại" ở `admin/page-cong-ke-toan.php`.
7. Thử: tab Gửi chứng từ → **Xem trước** một shop một ngày → **Gửi** → tab Nhật ký chứng từ không có dòng Lỗi → mở phần mềm kế toán kiểm bút toán.

Plugin khác muốn khai thêm mục vào danh mục API mà không sửa plugin này: filter `tgs_acc_bridge_api_catalog`.

## 4. Điểm mở (filter)

| Filter | Dùng để |
|---|---|
| `tgs_acc_method_map` | Ánh xạ hình thức thu của POS → mã hình thức thanh toán bên kế toán |
| `tgs_acc_bridge_api_catalog` | Thêm / sửa mục trong tab Danh mục API |
| `tgs_acc_bridge_capability` | Quyền xem trang + bấm nút (mặc định = quyền màn VAT) |
| `tgs_acc_bridge_blog_ids` | Danh sách shop được gửi (mặc định = "Shop áp dụng thuế" của tgs-bc-tk) |

Menu: trang nằm ở Quản trị → mục **Kế toán** (`key = 'accounting'`). Trang kế toán khác thêm vào cùng mục qua filter `tgs_shop_workflow_nav`, tìm section có `key === 'accounting'`.

## 5. Quyền

- Xem trang, xem trước, tra nhật ký: quyền màn VAT (`TGS_BCTK_CAPABILITY`).
- Gửi thật: thêm **mật khẩu thao tác** (cùng mật khẩu các nút đẩy khác ở màn VAT).
- Sửa cài đặt kết nối: chỉ quản trị cấp cao. Hằng trong `wp-config.php` luôn thắng giá trị lưu trên trang.

## 6. Khi có lỗi

| Hiện tượng | Xem ở đâu | Thường do |
|---|---|---|
| Chứng từ "Lỗi" | Tab Nhật ký chứng từ, lọc Lỗi → cột Thông báo | Thiếu mã hình thức thanh toán / loại thuế bên kế toán; kỳ đã khoá sổ; tổng tiền không khớp dòng |
| Cả lượt lỗi | Tab Lượt gọi API | Máy chủ kế toán dừng, sai khoá, hết thời gian chờ |
| Số hai bên lệch | `tools/doi-chieu-ke-toan.php --blog=… --from=… --to=…` | Chứng từ lỗi chưa gửi lại; phiếu sửa sau khi gửi mà chưa chạy lại |
| Đổi sang đơn vị kế toán khác | — | Nhật ký gửi vẫn ghi "đã vào sổ" → gửi lại với tích **Gửi lại cả chứng từ không đổi** |

Sửa xong nguyên nhân thì chỉ cần gửi lại khoảng ngày đó: chứng từ đã đúng được bỏ qua, chứng từ lỗi được gửi lại.

## Cổng chờ (ERP kéo chứng từ)

`includes/class-tgs-acc-gate.php` — REST `GET/POST /wp-json/tgs-acc/v1/pending` theo đầu chuẩn của ERP (tgs-ketoan/docs/cong-ket-noi.md).
GET trả chứng từ đang chờ đẩy của từng shop (mỗi trang một shop, `cursor` = vị trí shop), dựng bằng `TGS_Acc_Pusher::build()`;
chứng từ trả ra được ghi nhật ký trạng thái `gate`, ERP báo nhận (POST) thì chuyển `posted` / `error`.
Thêm loại phiếu mới (mua, trả NCC, chuyển kho, điều chỉnh, thu chi độc lập): thêm vào `build()` và khai `sourceRef` ở `$source_types`.
Khoá: hằng `TGS_ACC_GATE_KEY` (ưu tiên) hoặc tự sinh, xem ở Cổng kế toán → Cài đặt.

### Phiếu chuyển tiền giữa chi nhánh (17/10/2026)
Nguồn: bảng global `{base}global_cash_transfer` của tgs_pos (màn "Tạo phiếu chuyển tiền", `TGS_POS_Cash_Transfer_Service`),
lấy theo shop LẬP phiếu (`issuer_blog_id`) — `TGS_Acc_Source::cash_transfer_rows`, `TGS_Acc_Mapper::cash_transfer`,
`docKey = btsoft:{blog}:cash_transfer:{ct_id}`. Làm theo HTsoft (`sp_InsertCHUYENTIENSHOWROOM` trừ tiền nơi chuyển ngay khi lập,
`sp_ApproveCHUYENTIENSHOWROOM` = nơi nhận xác nhận rồi mới cộng tiền nơi nhận):
- phiếu lập ở POS → `status: sent` (kế toán ghi nơi chuyển: Nợ 11319 / Có tiền);
- **nhân viên cửa hàng không xác nhận**: KẾ TOÁN đối chiếu quỹ / sao kê rồi bấm **Xác nhận đã nhận tiền** ở ACC14 (phần mềm
  kế toán, bảng `cash_transfer_confirmations`) → kế toán ghi tiếp nơi nhận (Nợ tiền / Có 11319). Màn danh sách phiếu
  chuyển tiền POS đọc lại xác nhận qua `TGS_Acc_Api_Client::receipt_confirmations` (GET
  `/api/v1/documents/receipt-confirmations`): hiện "Kế toán đã xác nhận" và khoá sửa phiếu;
- POS **không còn dùng HTsoft** cho phiếu chuyển tiền: danh mục chi nhánh, tài khoản ngân hàng, lý do chi / thu của form
  lấy từ phần mềm kế toán (`TGS_Acc_Api_Client::quick_get`, đệm 10 phút); chi nhánh của site = `wp_blogs.tgs_site_code`;
  cột `srid_*` / `nhid_*` nay lưu mã chi nhánh / mã ngân hàng của kế toán. Phiếu cũ đã duyệt bên HTsoft (`status = 1`)
  vẫn gửi `status: received`;
- `branchCode` / `toBranchCode` = mã chi nhánh HTsoft trên phiếu (không phải mã shop); ngân hàng rút / nộp → `fromBankCode` /
  `toBankCode` (mã danh mục Tài khoản ngân hàng bên kế toán), trống = tiền mặt; lý do chi / thu → `reasonCode` / `toReasonCode`
  (CPCT / TPCT không ghi sổ, bên kế toán tự bỏ qua; TBNH đối ứng 13681 → nơi chuyển Nợ 33681);
- phiếu bị xoá (is_deleted) → báo huỷ (`deleted_keys`).

### Phiếu thu / chi là chứng từ riêng (17/10/2026)
Phiếu bán / hoàn KHÔNG kèm tiền. Mỗi phiếu thu (7, cha = phiếu bán 10) / phiếu chi trả khách (8, cha = phiếu hoàn 11) là
chứng từ `customer_payment` riêng (`TGS_Acc_Mapper::payment`, `TGS_Acc_Source::payment_rows`): ngày = ngày của phiếu,
số = mã phiếu, `invoiceDocKey` trỏ về phiếu cha (kế toán tự đối trừ công nợ đúng hoá đơn); phiếu chi trả khách
`direction = out`. Quét thêm phiếu bị sửa trong khoảng dù thuộc ngày trước (`updated_at`, `TGS_Acc_Source::changed_before`).
Đổi trả: phiếu thu kỹ thuật `doi_tru` / `offset_netting` (không phải tiền thật) KHÔNG gửi; đơn bán mang `offsets`
[{docKey phiếu hoàn, amount}] (`TGS_Acc_Source::offsets_by_sale`) — kế toán chỉ đối trừ công nợ.


## Chi nhánh bên kế toán tự có khi gắn site (14/11/2026)

`includes/class-tgs-acc-branch-sync.php` (`TGS_Acc_Branch_Sync`). Trước đây chi nhánh bên kế toán chỉ được tạo khi site gửi chứng từ đầu tiên, nên site mới / site chưa từng gửi phiếu không có ở các màn chọn chi nhánh bên kế toán.

| Khi nào | Làm gì |
|---|---|
| Lưu **Cấu hình phân cấp multisite** (tuỳ chọn mạng `tgs_multisite_hierarchy_data` — nơi site nhận mã chi nhánh) | Hẹn một lượt cho mọi site sau 30 giây (lưu nhiều lần liền nhau chỉ chạy một lượt) |
| Tạo site mới (`wp_initialize_site`) | Hẹn đồng bộ riêng site đó sau 60 giây — site mới thường chưa có mã thì bỏ qua, chờ gắn phân cấp |
| Nút **Đồng bộ chi nhánh từ các site** (Cổng kế toán → Cài đặt, quản trị cấp cao) | Chạy ngay, bảng kết quả từng site: *Vừa tạo / Đã có / Bỏ qua (chưa có mã) / Lỗi* |

- Mã chi nhánh: theo phân cấp (`TGS_Acc_Source::branch_of_blog`), không có thì mã site của tgs-bc-tk. Site **chỉ có tên** → bỏ qua (không tạo chi nhánh rác mang tên site).
- Tên: `tgs_shop_name`, không có thì tên site; `externalRef = blog:<số site>`.
- Tải danh sách chi nhánh một lần cho cả lượt, chỉ `POST /api/v1/branches` mã còn thiếu; cập nhật bộ nhớ `tgs_acc_known_branches` dùng chung với lúc gửi chứng từ.
- Tạo xong → đồng bộ phân kho (`TGS_Acc_Warehouse_Sync`) cho các site đó. Chi nhánh mới chưa khai kho ở DM12 thì phiếu ghi kho mặc định bên kế toán.
- Khoá API cần phạm vi **`catalog:write`**; thiếu thì bảng kết quả báo lỗi từng site.

## Phiếu kế toán lập về website shop — không cần plugin POS (14/11/2026)

Phiếu NT-BH-017, chữ chốt open-ketoan `docs/nghiep-vu/23-phieu-ban-ve-website.md`. Kế toán lập / sửa phiếu bán cho từng shop ở phần mềm kế toán (BH10) → website shop có phiếu như shop tự lập.

| Thành phần | Việc |
|---|---|
| `TGS_Acc_Ketoan_Events` | Cửa nhận `POST /wp-json/tgs-acc/v1/ketoan-events` (chữ ký `Bizcity-Signature`, bí mật `TGS_KETOAN_WEBHOOK_SECRET`); hộp nhận `{base}tgs_acc_ketoan_inbox` chống ghi trùng. `phieu-ban.doc.recorded` (bán) → ghi bản sao; `phieu-ban.doc.cancelled` → xoá mềm; phiếu trả (`sale_return`) → phiếu hoàn loại 11 gắn bản sao phiếu bán gốc (+ phiếu chi khi trả tiền); `pos.return.recorded` → cầu nối tự ghi cùng cách (gắn bản sao đơn gốc); `pos.*` khác → chuyển cho tgs_pos nếu có |
| `TGS_Acc_Ledger_Mirror` | Lõi ghi bản sao (chuyển từ tgs_pos, **bỏ điều kiện shop bật POS React**), dùng chung bảng `{prefix}tgs_pos_mirror`; ghi đè tại chỗ theo khoá `pg:<id>` + phiên bản; `local_ledger_source_id = 'pg:…'` nên cầu nối không gửi ngược |
| `TGS_Acc_Trade_Mapper` | Phiếu kế toán (TradeDoc) → phiếu bán (10), phiếu thu theo hình thức (7; ghi nợ → phiếu thu 0đ), phiếu xuất (2) kèm dòng hàng — như POS cũ |

Cấu hình:
- **Đặt địa chỉ webhook của app `tgs-wp`** bên kế toán (`APP_WEBHOOK_URL_TGS_WP`) thành `https://<website>/wp-json/tgs-acc/v1/ketoan-events`. Sự kiện máy bán (`pos.*`) vẫn được chuyển cho tgs_pos.
- Site phải nối chi nhánh (Cấu hình phân cấp multisite).
