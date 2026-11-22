<!-- [2026-11-23 01:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 / OKT-SET-3 — bằng chứng (I-7). -->
# OKT-SET-2 / OKT-SET-3 — khoá cài đặt (nhiều cửa hàng), ping, màn Cài đặt + Sơ đồ kết nối — 23/11/2026

Luật tối thượng P-0 ý 2 (a): **một máy open-ketoan, một khoá cài đặt, mỗi cửa hàng một tenant**. Phía open-ketoan: ADR-0037, migration 0180
(open-ketoan #579, #580). Đặc tả: open-setup `82` B-2, B-3, B-4, B-6, B-7.

| Việc 82 | Ở bridge |
|---|---|
| B-3 client theo nhánh | `TGS_Acc_Config::key_mode()` thêm `install` (chain + khoá cài đặt; khoá riêng thắng, khoá cài đặt thắng khoá chung). `TGS_Acc_Api_Client::for_blog()` / client mặc định của blog hiện tại → `X-Api-Key: okti_…` + `X-OKT-Blog: N` + `X-OKT-Install`. `identity()` tách theo blog (đệm không lẫn tenant, I-1). `Config::key()` / `ready()` thấy khoá cài đặt |
| B-4 đăng ký cửa hàng | `TGS_Acc_Install::register()` → `POST /api/v1/pos-installation/blogs` (không `X-OKT-Blog`); lưu mã bản cài, trạng thái, **mã nối** để admin đưa kế toán. `403 BLOG_NOT_LINKED` / `BLOG_UNKNOWN` → ghi trạng thái; hàng chờ **giữ** nhóm của blog chưa nối (`hold: link`, không tính lần thử, không bỏ) — nối xong cron gửi bù |
| B-5 tự kiểm | SC-1 nhánh `install`: đã nối = xanh; chưa đăng ký / chờ mã nối = vàng và dừng (không gọi API → không SC-2/SC-4 đỏ giả). SC-3 giữ nguyên: hai cửa hàng cùng tenant = đỏ + tự tạm dừng |
| B-6 ping 5 phút | `TGS_Acc_Install::ping()` (lịch `tgs_acc_ping`, site chính): health mỗi máy một lần; chain → **một** lượt `POST /pos-installation/ping`; khoá thường → `POST /connection/ping` từng blog bằng khoá của nó; máy không trả lời → không ping blog của máy đó |
| B-2 màn Cài đặt | thẻ "Mô hình triển khai" (tab Cài đặt): ba lựa chọn, khoá theo môi trường (hằng / multisite / một site), bước 2 theo nhánh (chain: khoá cài đặt + bảng cửa hàng ↔ tenant + mã nối + Đăng ký / Mã nối mới; bizcity / một cửa hàng: khoá từng site), bước 3 kiểm |
| B-7 Sơ đồ kết nối | tab mới: site → bridge → máy open-ketoan → tenant, chấm màu **kèm chữ**, Ping tất cả, bảng từng cửa hàng và từng máy (phiên bản, ping, lệch đồng hồ, số tenant / site) |
| CLI | `wp tgs-acc install [--register] [--renew=<blog>] [--refresh] [--ping]` |

## Chạy thật — okt-wp (multisite) ⇄ okt-thu (open-ketoan main 6d3aac2e, migration 0180 đã áp)
Hai sổ `shopa` (chủ khoá cài đặt) và `shopb` (tạo bằng job seed). Blog 2 giữ khoá riêng → shopa; blog 1, 3 đi khoá cài đặt. Khoá / mã nối không in.
```
1. kế toán shopa tạo khoá cài đặt → mã bản cài inst_ARBK4FSX3EFX42V26BSZBE43F7
2. wp tgs-acc profile --set=chain; khai địa chỉ + khoá cài đặt → blog 1: install · blog 2: own · blog 3: install
3. wp tgs-acc install --register
   1 OKT thử        install  pending  —      (mã nối)
   2 Shop A         own      —        shopa
   3 Shop B         install  pending  —      (mã nối)
4. blog 3 nhập kho (lat-doc-seed, đường nhập thật) + bán 1 đơn khi CHƯA nối:
   hàng chờ blog 3: {"groups":2,"docs":4} · giữ: có
   nhóm 7812e14b2fb3 n=0 hold=link · Cửa hàng chưa được kế toán nối vào sổ (chờ mã nối) — giữ trong hàng chờ…
   chứng từ ở sổ shopb trước khi nối: 0
5. kế toán đăng nhập sổ shopb, nhập mã nối của blog 3 → {"ok":true,"blogId":3}
   nhập lại mã đã dùng ở sổ shopa → {"ok":false,"reason":"POS_LINK_CODE_INVALID"}
6. ping → blog 3 active, tenant shopb, 15 ms → cron gửi lại: {"retried":2,"ok":2}, hàng chờ {"groups":0,"docs":0}
   (lần đầu không gửi được vì blog 3 "tự quản" chưa bật nguồn sổ POS / chưa có mã chi nhánh — lỗi cấu hình máy thử, n tăng như lỗi thường;
    bật tgs_acc_sale_source = pos, tgs_acc_branch_code = CN01 thì gửi được)
   bán thêm sau khi nối → gửi tức thời: {"res":"ok","blog":3,"sent":2}
   sổ shopb: 9 chứng từ của blog 3 (purchase LATDOC-NK-900000001, 4 sale, 4 customer_payment), docKey trùng: 0
   sổ shopa: 0 chứng từ của blog 3 · gửi lại cùng nhóm: unchanged 4 (không trùng)
7. wp tgs-acc selfcheck --shop=3 --confirm-tenant
   🟢 SC-0b  Mô hình: tự cài nhiều cửa hàng — … (đã chọn ở Cài đặt)
   🟢 SC-1   Khoá cài đặt + cửa hàng đã được kế toán nối vào sổ (mỗi cửa hàng một tenant — P-0)
   🟢 SC-2   Tenant `shopb` đúng mã đã xác nhận
   🟢 SC-3   Không blog nào khác nối tenant này
8. open-ketoan GET /api/v1/pos-installations
   sổ shopa: installations [inst_ARBK… active, blogs 1:pending, 3:active:shopb:11ms] · directKeys [2:Shop A:11ms]
   sổ shopb: installations 0 · linkedHere [inst_ARBK…, blog 3, ping 11 ms, pending 4]
```
Ping hai nhánh còn lại:
```
okt-wp, --set=bizcity: khoá cài đặt "không dùng"; blog 2 own 13 ms; blog 1, 3 none (không gửi: ready_for(3) = false, không giữ)
okt-wp1 (một site): Mô hình single · blog 1 own · tenant shopa · 13 ms · máy okt-thu-web-1 14 ms
```

### Lỗi chỉ chạy thật bắt được (đã sửa trong PR)
- `TGS_Acc_Config::key()` chưa biết khoá cài đặt → `ready()` sai → `Events::targets()` rỗng → blog chain **đã nối** không gửi tức thời (sự kiện ghi, không gửi,
  không vào hàng chờ). Sửa + ca `install-test` "blog 3 hiện tại: Config::key() = khoá cài đặt". Hai đơn bán trong lúc lỗi gửi bù bằng `Events::process` (không trùng).
- Sơ đồ hiện tenant từ lần tự kiểm cũ cho blog chưa nối / chưa có khoá → nhánh `install` chỉ lấy tenant từ open-ketoan; `none` không hiện tenant.

### Ghi nhận ngoài phạm vi
- `tools/day-ke-toan.php` dòng 33 nạp `tgs-accounting-bridge.php` (tên tệp cũ) → lỗi nghiêm trọng trên main. Chưa sửa ở PR này.

## Test
`tests/install-test.php` 37/37 (mới: key_mode theo mô hình, header theo blog, đệm tách theo blog, đăng ký + mã nối, BLOG_UNKNOWN / BLOG_NOT_LINKED → giữ,
cron không tăng lần thử, nối xong hết giữ, ping theo nhánh, máy sập, sơ đồ không lộ khoá); `selfcheck-test` 69/69 (ca 21: SC-1 nhánh khoá cài đặt, SC-3 hai
cửa hàng cùng tenant = đỏ + tự dừng). 17 tệp test đạt; census MS0 đạt (29 tệp). Giao diện: chạy `assets/ket-noi.js` với DOM giả + topology thật của okt-wp
cho cả ba mô hình — dựng được, các nút gọi đúng thao tác, không có khoá thật trong HTML.
