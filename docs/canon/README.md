# Canon và xương sống xuyên app — áp cho open-ketoan-bridge

<!-- [2026-11-24 05:08 PM Johnny Chu - Chu Hoàng Anh] SPINE-1 — gieo tài liệu canon vào repo: trỏ về canon chung (open-hub) và chuẩn xương sống bizcity_spine@1 (bizcity-platform); khai trục của app ở bizcity.app.json. Không đổi mã. -->

Luật đứng trên repo này, theo thứ tự:

1. **[LUAT-TOI-THUONG.md](../../LUAT-TOI-THUONG.md)** (P-0; bridge là **kênh**, không bán, không ghi thẳng POS).
2. **[BIZCITY OPEN CANON](https://github.com/hoanganh8389/open-hub/blob/main/docs/canon/README.md)** (open-hub).
3. **[`bizcity_spine@1`](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-SPINE-v1.md)** (bizcity-platform) — bridge thực hiện ba việc của chuẩn: **changefeed → bản sao** (§4.1), **gửi sau** (R-SPINE-7), **đối chiếu** (R-SPINE-8).

## App này trong xương sống (`bizcity.app.json`)

| | |
|---|---|
| Làm chủ | không trục nào |
| Kéo | `item`, `uom`, `branch`, `partner` từ open-ketoan → ghi vào bảng tsm theo blog (bộ kéo C8). Khi open-ketoan mở changefeed `since` + digest (Q-SPINE-3) thì kéo theo cursor thay vì kéo cả bảng |
| Gửi | chứng từ bán của POS (`doc_ref` `pos.*`) qua `bizcity_ledger_intake@1`, `Idempotency-Key = <doc_ref>@<version>`; nhận lại `doc_ref` sự việc kế toán và ghi `document_links (pos.order:… → bizcity.ledger.event:…, role = ledger)` |
| Tham chiếu | dòng hàng gửi `ItemRef {id}` khi đã ràng buộc, không thì `{code, as_of}` và chờ `IN-ITEM-*`; đối tượng gửi `PartnerRef {code}`; chi nhánh gửi `BranchRef {code}`; ĐVT gửi `uom` + `qty`, **không gửi ratio** |
| Đối chiếu | chạy `RC-SPINE-*` theo cặp blog → tenant; kết quả vào scoreboard kết nối (OKT 70) |

## Ba điều không làm

- Không bịa `item_id`, không suy tenant / pháp nhân từ khoá hay blog id (P-0).
- Không ghi đè bản sao bằng dữ liệu cục bộ, không ghi đè chủ bằng bản sao: lệch ⇒ finding + remedy.
- Không gửi chứng từ thiếu `doc_ref` hoặc thiếu `Idempotency-Key`.

## Kiểm

```bash
node ../bizcity-platform/tools/spine-check.mjs --manifest bizcity.app.json   # SC-SPINE-1, phải ĐẠT
```
