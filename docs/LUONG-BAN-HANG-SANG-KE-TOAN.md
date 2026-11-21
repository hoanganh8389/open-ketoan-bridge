# Từ một phiếu bán ở POS đến sổ sách kế toán

Tài liệu cho **người viết code chưa biết nghiệp vụ kế toán**. Đọc xong sẽ trả lời được:

- Vì sao công ty làm phần mềm kế toán riêng.
- Một phiếu bán ở `tgs_pos` đi qua những đâu để thành số trên sổ kế toán.
- Kế toán làm gì với số đó, ai thao tác, bấm ở màn hình nào.
- Khi số lệch thì tìm ở đâu.

Cập nhật: 10/10/2026. Phần mềm kế toán nằm ở `c:\xampp\htdocs\tgs-ketoan` (TypeScript + PostgreSQL), chạy thử tại `http://localhost:8088`.

---

## 1. Vì sao có phần mềm kế toán này

Trước đây công ty dùng hai hệ thống:

| Hệ thống | Làm gì |
|---|---|
| **BTsoft** (WordPress, `tgs_pos`, `tgs_shop_management`…) | Bán hàng ở cửa hàng, tồn kho, phát hành hoá đơn điện tử qua Viettel |
| **Phần mềm kế toán thuê ngoài (HTsoft)** | Sổ sách kế toán, báo cáo thuế, báo cáo tài chính |

Mỗi phiếu bán ở BTsoft phải đẩy sang phần mềm kế toán thuê ngoài thì kế toán mới có số. Cách làm đó có ba vấn đề:

1. **Phụ thuộc bên thứ ba.** Hợp tác sắp kết thúc; dữ liệu và logic nằm ở hệ thống mình không sửa được.
2. **Khó đối chiếu.** Số ở cửa hàng, số trên hoá đơn Viettel và số trên sổ kế toán nằm ở ba nơi, lệch thì dò tay.
3. **Không mở rộng được.** Muốn thêm báo cáo, thêm công cụ (bảng tính AI…) đều phải chờ bên kia.

Phần mềm kế toán mới giải quyết bằng cách: **BTsoft gửi thẳng "sự việc đã xảy ra" (bán gì, bao nhiêu, thuế bao nhiêu, thu bằng gì) qua API; phần mềm kế toán tự định khoản và lên sổ.** Công ty nắm toàn bộ code và dữ liệu, sau này có thể cho đơn vị khác thuê.

---

## 2. Bức tranh trong 30 giây

```
 CỬA HÀNG                    WORDPRESS (BTsoft)                         PHẦN MỀM KẾ TOÁN
┌─────────┐   ┌──────────────────────────────────────────┐   ┌──────────────────────────────────┐
│ Thu ngân│   │ tgs_pos            ghi phiếu bán,         │   │ POST /api/v1/documents/batch     │
│ bán hàng│──▶│                    phiếu thu, phiếu hoàn  │   │   1. kiểm tra khuôn, kiểm tổng   │
└─────────┘   │        │                                  │   │   2. định khoản theo cấu hình    │
              │        ▼                                  │   │   3. ghi sổ cái (2 bộ sổ)        │
              │ Viettel: phát hành hoá đơn điện tử        │   │   4. ghi sổ hoá đơn, số liệu bán │
              │        │                                  │   └───────────────┬──────────────────┘
              │        ▼                                  │                   ▼
              │ tgs-bc-tk          gom "dòng VAT":        │      Sổ chi tiết, sổ cái, cân đối,
              │                    phiếu + hoá đơn + dòng │      báo cáo thuế, bán hàng, công nợ,
              │        │           hàng đúng như hoá đơn  │      kết quả kinh doanh, cân đối kế toán
              │        ▼                                  │                   ▲
              │ tgs_accounting_bridge   đổi sang JSON,    │──────── API ──────┘
              │                    gửi, ghi nhật ký gửi   │                KẾ TOÁN xem, đối chiếu,
              └──────────────────────────────────────────┘                nhập thêm phiếu, kết chuyển
```

Điểm mấu chốt: **WordPress không biết gì về tài khoản kế toán.** Nó chỉ kể lại sự việc. Tài khoản Nợ / Có do phần mềm kế toán quyết định theo cấu hình mà kế toán khai.

---

## 3. Kế toán cần gì từ một phiếu bán (kiến thức tối thiểu)

### 3.1. Năm khái niệm

| Khái niệm | Hiểu theo kiểu lập trình |
|---|---|
| **Tài khoản** | Một "ngăn" để gom tiền theo ý nghĩa. Có số hiệu: `1111` tiền mặt, `1121…` tiền gửi ngân hàng, `1311` khách còn nợ mình, `51111` doanh thu bán lẻ, `33311` thuế GTGT phải nộp. |
| **Nợ / Có** | Hai cột của mỗi tài khoản. Không phải "nợ nần". Tài khoản tài sản (tiền, phải thu) **tăng ghi Nợ**; tài khoản doanh thu, phải trả **tăng ghi Có**. |
| **Bút toán** | Một giao dịch gồm nhiều dòng Nợ / Có. Luật bất biến: **tổng Nợ = tổng Có**. Giống một transaction chuyển tiền giữa các ngăn. |
| **Sổ cái** | Bảng chỉ-thêm chứa mọi dòng bút toán (`ledger_lines`). Mọi báo cáo đều tính từ đây. Sai thì không sửa dòng cũ mà ghi thêm bút toán đảo. |
| **Kỳ kế toán** | Tháng. Hết tháng kế toán kiểm tra, kết chuyển, rồi **khoá sổ**: không ai sửa được số của tháng đó nữa. |

### 3.2. Hai bộ sổ

| Bộ sổ | Chứa gì | Dùng cho |
|---|---|---|
| **Sổ nội bộ** | Mọi chứng từ, kể cả phiếu không xuất hoá đơn (bill Z, phiếu nội bộ) | Quản trị: doanh thu thật, tiền thật |
| **Sổ thuế** | Chỉ chứng từ **có hoá đơn hợp lệ**, số tiền đúng như trên hoá đơn | Kê khai thuế, làm việc với cơ quan thuế |

Một phiếu bán có hoá đơn sinh **hai bút toán** giống nhau, mỗi bộ sổ một cái. Phiếu không hoá đơn chỉ sinh một bút toán ở sổ nội bộ.

### 3.3. Một phiếu bán thành bút toán như thế nào

Phiếu bán 200.000 đ (tiền hàng 185.185 + thuế 8% là 14.815), khách trả tiền mặt:

| Bước | Ý nghĩa | Nợ | Có | Số tiền |
|---|---|---|---|---|
| 1 | Ghi nhận doanh thu, khách "nợ" tiền hàng | 1311 Phải thu khách | 51111 Doanh thu bán lẻ | 185.185 |
| 2 | Ghi nhận thuế phải nộp nhà nước | 1311 Phải thu khách | 33311 Thuế GTGT đầu ra | 14.815 |
| 3 | Khách trả tiền mặt, hết nợ | 1111 Tiền mặt | 1311 Phải thu khách | 200.000 |

Vì sao phải đi vòng qua `1311` thay vì ghi thẳng tiền mặt / doanh thu? Vì bán hàng và thu tiền là **hai sự việc**: khách có thể trả sau, trả một phần, trả bằng nhiều cách. Tài khoản 1311 là chỗ khớp hai việc đó. Sau bước 3, số dư 1311 của phiếu này về 0. Nếu khách chưa trả, bước 3 không có và 1311 còn dư Nợ 200.000: đó chính là **công nợ phải thu**.

---

## 4. Luồng kỹ thuật từng bước

### Bước 1 — Thu ngân bán hàng (`tgs_pos`)

Thu ngân lập đơn, thu tiền, in bill. Hệ thống ghi vào sổ của shop:

- **phiếu bán** kèm các dòng hàng;
- **phiếu thu** (loại 7): thu bằng gì, bao nhiêu — một đơn có thể nhiều phiếu thu (tiền mặt + quét mã);
- nếu khách trả hàng: **phiếu hoàn** (loại 11) và **phiếu chi trả tiền khách** (loại 8).

Người thao tác: thu ngân. Kế toán không đụng vào bước này.

### Bước 2 — Phát hành hoá đơn điện tử (Viettel)

Đơn cần hoá đơn được gửi sang Viettel. Viettel trả về **số hoá đơn, ký hiệu, mẫu số, ngày**. Từ lúc này số tiền trên hoá đơn là **số đã chốt với cơ quan thuế**: tiền hàng trước thuế và tiền thuế từng dòng đã làm tròn theo luật hoá đơn.

Bill Z là phần tách ra không xuất hoá đơn: chỉ theo dõi nội bộ.

### Bước 3 — Gom "dòng VAT" (`tgs-bc-tk`)

Plugin báo cáo thống kê đã có sẵn màn "Phiếu xuất bán VAT". Hai hàm công khai trả về đúng dữ liệu của màn đó:

| Hàm | Trả về |
|---|---|
| `TGS_BCTK_Ajax::vat_rows_for_export($blog_id, $from, $to, 'sale')` | Phiếu bán trong khoảng ngày: thông tin phiếu, người mua, hoá đơn, từng dòng hàng với **tiền chưa thuế và tiền thuế đúng như gửi Viettel** |
| `TGS_BCTK_Report::site_return_rows_all($blog_id, $from, $to)` | Mọi phiếu hoàn theo **ngày hoàn**, kèm hoá đơn điều chỉnh nếu có |

Đây là lý do cầu nối đọc qua `tgs-bc-tk` chứ không tự query: **một nguồn duy nhất cho luật tính tiền hoá đơn**. Số kế toán nhận được chắc chắn bằng số đã gửi Viettel.

### Bước 4 — Đổi sang chứng từ và gửi (`tgs_accounting_bridge`)

| File | Việc |
|---|---|
| `includes/class-tgs-acc-source.php` | Lấy phiếu bán, phiếu hoàn, phiếu thu (loại 7), phiếu chi trả khách (loại 8) |
| `includes/class-tgs-acc-mapper.php` | Đổi một dòng VAT thành JSON theo khuôn của phần mềm kế toán |
| `includes/class-tgs-acc-pusher.php` | So với nhật ký, quyết định gửi / bỏ qua / tăng phiên bản / huỷ |
| `includes/class-tgs-acc-push-log.php` | Bảng nhật ký `{base_prefix}tgs_acc_push_log` |
| `includes/class-tgs-acc-api-client.php` | Gọi API |
| `tools/day-ke-toan.php` | Lệnh chạy tay hoặc chạy theo lịch |

Chứng từ gửi đi cho phiếu ví dụ ở mục 3.3:

```json
{
  "docType": "sale",
  "docKey": "btsoft:95:sale:12345",
  "version": 1,
  "branchCode": "26003",
  "docNo": "26003AA0001234",
  "docDate": "2026-10-05",
  "sourceSystem": "btsoft",
  "internalOnly": false,
  "partner": { "code": "0912345678", "name": "Nguyễn Văn A" },
  "invoice": { "no": "C26TNH31079", "serial": "C26TNH", "form": "1", "date": "2026-10-05", "status": "valid" },
  "lines": [
    { "lineKey": "88001", "sku": "100861007", "name": "Bimbosan 1 400g", "unit": "Lon", "qty": 1,
      "amountBeforeTax": 185185, "taxCode": "R08", "taxAmount": 14815 }
  ],
  "payments": [ { "methodCode": "TM", "amount": 200000, "reference": "PT000777" } ],
  "totals": { "beforeTax": 185185, "tax": 14815, "total": 200000 }
}
```

Các quy ước quan trọng:

| Trường | Quy ước | Vì sao |
|---|---|---|
| `docKey` | `btsoft:<blog_id>:sale:<sale_id>` hoặc `btsoft:<blog_id>:return:<return_id>` | Khoá duy nhất. Gửi lại bao nhiêu lần cũng không ghi trùng. |
| `version` | Tăng 1 mỗi khi **nội dung** phiếu đổi | Bên kế toán đảo bút toán cũ rồi ghi lại theo nội dung mới. |
| `branchCode` | Mã shop | Chi nhánh bên kế toán; chưa có thì cầu nối tự tạo. |
| `internalOnly` | `true` với bill Z | Không bao giờ vào sổ thuế. |
| `invoice` | Chỉ có khi đã có số hoá đơn và không phải bill Z | Có khối này thì chứng từ vào thêm sổ thuế. |
| `taxCode` | `KCT`, `R00`, `R05`, `R08`, `R10` (suy từ thuế suất dòng) | Mã phải có trong danh mục Loại thuế bên kế toán. |
| `methodCode` | `TM`, `CK`, `QR`, `POS`, `VOUCHER`, `CONGNO`, `DOI_TRU` (bảng quy đổi ở `method_map()`, sửa bằng filter `tgs_acc_method_map`) | Mỗi mã ứng với một tài khoản tiền bên kế toán. |
| Số tiền | Luôn **dương**; loại chứng từ (`sale` / `sale_return`) cho biết chiều | Tránh nhầm dấu. |

Cách cầu nối quyết định gửi gì (`TGS_Acc_Pusher::push`):

1. Dựng toàn bộ chứng từ của shop trong khoảng ngày.
2. Tính mã băm nội dung, so với nhật ký:
   - **không đổi và đã ghi sổ** → bỏ qua;
   - **đổi** → tăng `version`, gửi lại;
   - **lần trước lỗi** → gửi lại cùng `version`.
3. Gửi theo lô 100 chứng từ tới `POST /api/v1/documents/batch`.
4. Ghi kết quả từng chứng từ vào nhật ký.
5. Phiếu đã gửi trước đây mà nay **không còn ở shop** (bị xoá) → gọi `POST /api/v1/documents/cancel`.

Lệnh chạy:

```bash
# Xem thử, không gửi
php wp-content/plugins/tgs_accounting_bridge/tools/day-ke-toan.php --blog=95 --from=2026-10-01 --to=2026-10-09 --dry

# Gửi một shop
php wp-content/plugins/tgs_accounting_bridge/tools/day-ke-toan.php --blog=95 --from=2026-10-01 --to=2026-10-09

# Chạy theo lịch: mọi shop đang bật, 2 ngày gần nhất
php wp-content/plugins/tgs_accounting_bridge/tools/day-ke-toan.php --all --days=2
```

Cấu hình trong `wp-config.php`: `TGS_KETOAN_API_URL`, `TGS_KETOAN_API_KEY` (khoá tạo ở màn **Khoá API** bên kế toán).

### Bước 5 — Phần mềm kế toán nhận và ghi sổ

Với mỗi chứng từ (`apps/api/src/modules/documents/document.service.ts`), trong **một transaction**:

1. Kiểm tra khuôn dữ liệu và kiểm tổng tiền khớp các dòng.
2. Tìm chi nhánh; tạo hoặc cập nhật khách hàng (không có thì gắn "Khách lẻ").
3. Khoá theo `docKey`:
   - cùng phiên bản, đã ghi sổ → trả `unchanged`;
   - phiên bản mới → **đảo bút toán cũ bằng ghi số âm** rồi ghi lại;
   - phiên bản cũ hơn → từ chối.
4. **Định khoản**: chọn tài khoản theo cấu hình (mục 5), dựng các cặp Nợ / Có.
5. Ghi sổ cái từng bộ sổ, cập nhật số dư theo ngày.
6. Ghi sổ hoá đơn (để lên bảng kê thuế) và số liệu bán hàng theo dòng (để lên báo cáo bán hàng).

Kết quả từng chứng từ: `posted` (đã ghi sổ), `unchanged` (đã có, không đổi), `error` (chưa ghi được, kèm lý do).

Chứng từ `error` **vẫn được lưu lại** bên kế toán ở trạng thái Lỗi. Kế toán sửa cấu hình rồi bấm "Ghi sổ lại", không cần gửi lại từ WordPress.

---

## 5. Tài khoản lấy từ đâu (cấu hình định khoản)

Kế toán khai một lần ở nhóm menu **Danh mục**. Code không cứng số tài khoản nào.

| Phần của phiếu | Tài khoản | Khai ở màn |
|---|---|---|
| Tài khoản trung gian (khách nợ) | `1311` | Lý do - Tài khoản (ACC18): lý do "Xuất bán hàng" |
| Doanh thu | `51111` | Mặt hàng - Tài khoản (ACC17): nhóm "Hàng hoá bán lẻ" |
| Hàng bán trả lại | `52131` | Mặt hàng - Tài khoản (ACC17) |
| Thuế đầu ra | `33311` | Loại thuế (ACC43) |
| Tiền thu theo hình thức | TM → `1111`, CK → `11210005`, QR → `11210007`, POS → `11210006`, Voucher → `13881` | Hình thức thanh toán (DM05) |
| Ghi nợ, đối trừ | để nguyên trên `1311` | Hình thức thanh toán, đánh dấu "ghi nhận công nợ" |

Thứ tự ưu tiên khi chọn tài khoản: lý do của chứng từ → nhóm hạch toán của mặt hàng → loại thuế → hình thức thanh toán → tài khoản ngầm định (ACC41).

---

## 6. Các tình huống thường gặp

| Tình huống ở cửa hàng | WordPress gửi gì | Kế toán thấy gì |
|---|---|---|
| Bán có hoá đơn, trả tiền mặt | `sale` có `invoice`, `payments: TM` | Bút toán ở cả hai bộ sổ; lên báo cáo thuế ra; tiền mặt tăng |
| Bán chưa / không xuất hoá đơn | `sale` không có `invoice` | Chỉ sổ nội bộ; **không** lên báo cáo thuế |
| Bill Z | `sale` với `internalOnly: true` | Chỉ sổ nội bộ, kể cả khi lỡ có số hoá đơn |
| Đơn xuất hoá đơn sau | Gửi lại cùng `docKey`, `version` tăng, thêm `invoice` | Bút toán cũ bị đảo, ghi lại; nay có thêm ở sổ thuế |
| Khách trả nhiều cách | Nhiều dòng `payments` | Mỗi cách vào một tài khoản tiền |
| Khách ghi nợ | `payments: CONGNO` | 1311 còn dư Nợ → công nợ phải thu |
| Khách trả hàng, nhận lại tiền | `sale_return` + `payments` là tiền trả khách | Doanh thu giảm (ghi 5213), thuế giảm, tiền giảm |
| Trả hàng, đối trừ sang đơn mới | `sale_return` không có `payments`; đơn mới có `payments: DOI_TRU` | Khách tạm dư Có trên 1311, đơn mới trừ vào đó |
| Sửa phiếu đã gửi | Mã băm đổi → `version` tăng | Bút toán cũ ghi số âm, bút toán mới ghi lại. Số phát sinh là số thuần. |
| Xoá phiếu đã gửi | Lệnh `cancel` | Bút toán bị đảo, chứng từ ở trạng thái Đã huỷ |
| Mã thanh toán lạ (ví dụ VNPAY chưa khai) | Gửi bình thường | Chứng từ **Lỗi**: "Hình thức thanh toán VNPAY chưa khai báo". Kế toán khai thêm rồi bấm Ghi sổ lại. |
| Kỳ đã khoá sổ | Gửi bình thường | Chứng từ **Lỗi**: kỳ đã khoá. Phải mở khoá mới ghi được. |

---

## 7. Ai làm gì

| Vai trò | Việc | Ở đâu |
|---|---|---|
| **Thu ngân** | Bán hàng, thu tiền, trả hàng | `tgs_pos` |
| **Người phát hành hoá đơn** | Gửi hoá đơn sang Viettel, xử lý hoá đơn thay thế / điều chỉnh | Màn VAT của `tgs-bc-tk` |
| **Lập trình viên / IT** | Giữ lịch đẩy chạy đều; xem nhật ký gửi; thêm mã thanh toán mới vào `method_map()`; cấp khoá API | `tools/day-ke-toan.php`, bảng `tgs_acc_push_log` |
| **Kế toán viên** | Xem chứng từ lỗi, đối chiếu doanh thu – tiền – thuế, nhập phiếu thu, chi, báo nợ, báo có, phiếu kế toán | Phần mềm kế toán |
| **Kế toán trưởng** | Khai cấu hình định khoản, kết chuyển cuối tháng, xem báo cáo tài chính, khoá sổ | Phần mềm kế toán |
| **Chủ sở hữu** | Tạo người dùng, khoá API | Tiện ích → Người dùng, Khoá API |

Kế toán **không nhập lại** phiếu bán. Việc của kế toán với dữ liệu bán hàng là **kiểm tra và đối chiếu**.

---

## 8. Kế toán thao tác gì, ở màn nào

Mã trong ngoặc gõ được vào ô tìm chức năng (Ctrl + K), ví dụ gõ `65`.

### Hằng ngày

| Việc | Màn | Cách làm |
|---|---|---|
| Xem có chứng từ nào chưa vào sổ | Định khoản tự động (ACC14) | Lọc trạng thái **Lỗi**. Bấm dòng, đọc lý do, sửa cấu hình, bấm **Ghi sổ lại**. |
| Đối chiếu doanh số với cửa hàng | Tổng hợp bán hàng (BH02) | Thẻ "Theo ngày" hoặc "Theo chi nhánh". |
| Đối chiếu tiền nộp về | Thu tiền theo hình thức thanh toán (BH03), Sổ quỹ tiền mặt (ACC55), Tiền gửi ngân hàng (ACC56) | So cột tiền mặt với tiền cửa hàng nộp; cột chuyển khoản / QR / thẻ với sao kê. |
| Ghi khoản thu chi ngoài bán hàng | Phiếu thu (ACC51), Phiếu chi (ACC52), Báo nợ (ACC53), Báo có (ACC54) | Ví dụ cửa hàng nộp tiền mặt vào ngân hàng → phiếu chi, tài khoản đối ứng là tài khoản ngân hàng. |

### Hằng tháng

| Thứ tự | Việc | Màn |
|---|---|---|
| 1 | Rà chứng từ lỗi, hoá đơn trùng số, hoá đơn bất thường | Kiểm tra chứng từ, hoá đơn (ACC93) |
| 2 | Đối chiếu chi tiết với bảng kê của Viettel | Báo cáo bán hàng (BH01), lọc "Có hoá đơn", Xuất Excel; Báo cáo thuế ra (ACC65) |
| 3 | Nhập chi phí, hoá đơn đầu vào | Phiếu chi, báo nợ, phiếu kế toán (nhập thuế và số hoá đơn trên từng dòng) → Báo cáo thuế vào (ACC64) |
| 4 | Xem thuế phải nộp | Bảng tổng hợp thuế GTGT (ACC91) |
| 5 | Kiểm tra công nợ | Sổ tổng hợp công nợ (ACC38), Công nợ theo tuổi nợ (ACC67) |
| 6 | Kết chuyển lãi lỗ | Kết chuyển lãi/lỗ (ACC6): Xem trước → Kết chuyển |
| 7 | Xem báo cáo | Cân đối số phát sinh (ACC10), Kết quả kinh doanh (ACC9), Cân đối kế toán (ACC8) |
| 8 | Khoá sổ | DM Kỳ kế toán và khoá sổ (ACC2) |

### Một lần khi bắt đầu

| Việc | Màn |
|---|---|
| Rà danh mục tài khoản, loại thuế, hình thức thanh toán, cấu hình định khoản | Nhóm Danh mục |
| Nhập số dư đầu kỳ | Số dư tài khoản (ACC15) |
| Khai khoản mục, bộ phận (nếu dùng) | ACC24, ACC26 |

Mỗi màn có nút **Hướng dẫn** ở góc phải, viết cho người dùng cuối.

---

## 9. Số lệch thì tìm ở đâu

| Triệu chứng | Nguyên nhân thường gặp | Kiểm tra |
|---|---|---|
| Kế toán thiếu phiếu so với cửa hàng | Lịch đẩy chưa chạy; phiếu đang Lỗi | Chạy `day-ke-toan.php --dry` cho shop và ngày đó; xem ACC14 lọc Lỗi |
| Doanh thu kế toán thấp hơn báo cáo bán hàng của shop | Phiếu lỗi chưa ghi sổ; khác cách tính ngày (ngày phiếu so với ngày hoàn) | ACC93 mục 1; phiếu hoàn tính theo **ngày hoàn** |
| Báo cáo thuế ra thấp hơn tổng bán | Bình thường: phiếu không hoá đơn và bill Z chỉ ở sổ nội bộ | BH02 lọc Phạm vi "Có hoá đơn" phải bằng ACC65 |
| Thuế kế toán lệch Viettel vài đồng | Có nơi tính lại thuế thay vì lấy số đã gửi | Không được xảy ra: cầu nối lấy `tien_thue` đúng như gửi Viettel. Nếu có, so `sales-detail` với dòng VAT của phiếu đó. |
| Tài khoản 1311 còn dư Có lạ | Trả hàng đối trừ chưa có đơn mới; phiếu chi trả khách chưa về | Sổ chi tiết công nợ (ACC40) theo khách |
| Phiếu sửa ở shop nhưng kế toán vẫn số cũ | Chưa đẩy lại; hoặc lần đẩy bị lỗi | Bảng `tgs_acc_push_log`: cột trạng thái, lỗi, phiên bản |
| Gửi báo "Chi nhánh … chưa khai báo" | Mã shop chưa có bên kế toán và tự tạo thất bại | Khoá API cần phạm vi "Tạo/sửa danh mục" |

Ba phép đối chiếu nội bộ luôn phải đúng (đã có phép thử tự động):

1. Doanh thu thuần ở BH02 = phát sinh Có 511 − phát sinh Nợ 521 trên sổ nội bộ.
2. BH02 lọc "Có hoá đơn" = tổng Báo cáo thuế ra (ACC65).
3. Thuế đầu ra ACC65 = phát sinh tài khoản 3331 trên sổ thuế.

### Kiểm tra sau mỗi lần đẩy (làm ở local trước, rồi làm y hệt trên máy chủ)

**Bước 1 — Xem thử, chưa gửi.** Biết trước sẽ gửi bao nhiêu chứng từ, phiếu nào không dựng được:

```bash
php wp-content/plugins/tgs_accounting_bridge/tools/day-ke-toan.php --all --from=2026-09-01 --to=2026-10-10 --dry
```

**Bước 2 — Gửi.** Bỏ `--dry`. Đọc dòng "Kết quả bên kế toán": `lỗi` phải bằng 0; nếu không, từng phiếu lỗi được in kèm lý do.

**Bước 3 — Gửi lại lần nữa.** Phải ra "không đổi (bỏ qua)" toàn bộ và "ghi sổ 0". Đây là phép thử không ghi trùng.

**Bước 4 — Đối chiếu tự động từng ngày** giữa sổ bán hàng và kế toán:

```bash
php wp-content/plugins/tgs_accounting_bridge/tools/doi-chieu-ke-toan.php --all --from=2026-09-01 --to=2026-10-10
```

Công cụ dựng lại chứng từ từ sổ bán hàng ngay lúc chạy, rồi so với báo cáo bên kế toán theo từng ngày: số phiếu bán, số phiếu trả, tiền hàng thuần, thuế thuần, và tiền thu thuần theo từng hình thức. Dòng cuối là `KẾT LUẬN: KHỚP toàn bộ N ngày` hoặc `LỆCH ở N ngày` kèm ngày và khoản lệch. Công cụ chỉ đọc, chạy bao nhiêu lần cũng được. Mã thoát 0 = khớp, 2 = lệch (dùng được để cảnh báo tự động).

**Bước 5 — Soi tay một phiếu bất kỳ** để tin vào kết quả:

1. Lấy một số phiếu ở POS, ghi lại: tiền hàng, tiền thuế, tổng, cách trả.
2. Bên kế toán mở **Định khoản tự động (ACC14)**, gõ số phiếu vào ô tìm, bấm vào dòng.
3. So phần "Dòng hàng" và "Thanh toán" với bill; xem phần "Bút toán" có đủ ba nhóm: doanh thu (Có 511), thuế (Có 3331), thu tiền (Nợ 111 / 112).
4. Phiếu có hoá đơn phải có **hai** bút toán (sổ nội bộ và sổ thuế); phiếu không hoá đơn chỉ có một.

**Bước 6 — Xem các màn kiểm soát bên kế toán:**

| Màn | Phải thấy |
|---|---|
| Kiểm tra chứng từ, hoá đơn (ACC93) | Mục 1 "Chứng từ lỗi" và mục 2 "Số hoá đơn trùng" đều "Không có" |
| Tổng hợp bán hàng (BH02), thẻ Theo chi nhánh | Doanh thu thuần từng shop bằng dòng TỔNG của công cụ đối chiếu |
| Báo cáo thuế ra (ACC65) | Bằng BH02 khi lọc Phạm vi "Có hoá đơn" |
| Bảng cân đối số phát sinh (ACC10) | Tổng phát sinh Nợ = tổng phát sinh Có |
| Bảng cân đối kế toán (ACC8) | Không có thông báo "Bảng chưa cân" |

**Lưu ý về phiếu "không có dòng hàng".** Công cụ đẩy và công cụ đối chiếu đều in danh sách phiếu không dựng được chứng từ. Các phiếu này **không được gửi**, nên không nằm trong phép so. Cần xem từng phiếu ở hệ thống bán hàng để biết đó là phiếu rỗng thật hay phiếu bị thiếu dữ liệu.

**Lưu ý khi đổi nơi nhận.** Nhật ký gửi (`tgs_acc_push_log`) ghi theo phiếu, không ghi theo máy chủ kế toán. Lần đầu đẩy sang một máy chủ kế toán khác (ví dụ từ local sang production) phải thêm `--force`, nếu không các phiếu đã từng gửi sẽ bị bỏ qua.

---

## 10. Thuật ngữ

| Từ | Nghĩa |
|---|---|
| Chứng từ | Giấy tờ chứng minh một sự việc: phiếu bán, phiếu thu, hoá đơn. Trong code: `accounting_events`. |
| Định khoản | Quyết định sự việc này ghi Nợ tài khoản nào, Có tài khoản nào. |
| Ghi sổ | Lưu bút toán vào sổ cái. |
| Bút toán đảo / ghi số âm | Bút toán có số tiền âm để xoá hiệu lực bút toán cũ, vì sổ cái không cho sửa. |
| Phát sinh | Tổng tiền ghi vào một tài khoản trong kỳ (phát sinh Nợ, phát sinh Có). |
| Số dư | Đầu kỳ + phát sinh Nợ − phát sinh Có (với tài khoản tài sản). |
| Tài khoản đối ứng | Tài khoản ở phía bên kia của cùng cặp định khoản. |
| Công nợ phải thu / phải trả | Khách còn nợ mình (131) / mình còn nợ nhà cung cấp (331). |
| Thuế đầu ra / đầu vào | Thuế GTGT thu hộ nhà nước khi bán (3331) / thuế đã trả khi mua, được khấu trừ (133). |
| Kết chuyển | Cuối kỳ dồn doanh thu, chi phí về tài khoản 911 để ra lãi lỗ, rồi về 4212. |
| Khoá sổ | Chặn mọi thay đổi số liệu của một kỳ. |
| Bảng kê | Danh sách hoá đơn theo thuế suất, nộp kèm tờ khai thuế. |

---

## 11. Giới hạn hiện tại

- Mới có luồng **bán hàng và trả hàng**. Chưa đẩy nhập mua, chuyển kho → chưa có giá vốn, tồn kho, lợi nhuận gộp.
- Lịch đẩy tự động chưa cài trên máy chủ; đang chạy lệnh bằng tay.
- Hoá đơn thay thế / điều chỉnh đã có trong khuôn dữ liệu nhưng chưa kiểm trên dữ liệu thật.
- Chưa có màn nạp file chi tiết của Viettel để đối chiếu tự động; hiện xuất Excel hai bên rồi so.
- Phần mềm kế toán mới chạy trên máy phát triển; chưa có máy chủ, sao lưu cho bản dùng thật.
