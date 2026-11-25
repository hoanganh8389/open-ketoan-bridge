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

## Chuẩn nền của hệ (bizcity-platform)

<!-- [2026-11-25 10:04 AM Johnny Chu - Chu Hoàng Anh] STD-2 — trỏ về bản đồ chuẩn nền bizcity_standard_map@1 (bản nháp chờ duyệt) để repo này đi cùng hướng với cả hệ. Không đổi mã. -->

Repo này theo **[bản đồ chuẩn nền `bizcity_standard_map@1`](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/README.md)**: bốn trục dữ liệu (master data, chứng từ, khách 360, người dùng) và mười hai trụ cột kỹ thuật. Bức tranh toàn hệ: [ARCHITECTURE](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/ARCHITECTURE.md). Thứ tự luật: canon chung (open-hub) → chuẩn nền (bizcity-platform) → luật của repo này; luật của repo **không được nới** hai tầng trên.

| Việc ở repo này | Chuẩn |
|---|---|
| Khuôn repo, manifest, nếp làm việc | [APP-SKELETON](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-APP-SKELETON-v1.md) |
| API, kiểm đầu vào / đầu ra | [API-PROFILE](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-API-PROFILE-v1.md) |
| Người dùng, vai, quyền, đăng nhập một lần | [USERS-ROLES](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-USERS-ROLES-v1.md) · [IDENTITY](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-IDENTITY-v1.md) |
| Bảo mật · quan sát và truy lỗi · đệm | [SECURITY](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-SECURITY-v1.md) · [OBSERVABILITY](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-OBSERVABILITY-v1.md) · [CACHE](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-CACHE-v1.md) |
| Kiểm thử · phiên bản, migration, sao lưu, nâng cấp | [TESTING](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-TESTING-v1.md) · [RELEASE](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-RELEASE-v1.md) |
| Giao diện React (model · view · controller), mockup | [WEB](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-WEB-v1.md) |
| Nhiều site / nhiều CSDL · Hub, khoá, 1API | [MULTISITE](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-MULTISITE-v1.md) · [HUB](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-HUB-v1.md) |

Kiểm khuôn repo (mức S0–S3):

```bash
node ../bizcity-platform/tools/standard-check.mjs --repo .
```

Lần kiểm 2026-11-25 còn:

- SC-STD-2 (cảnh báo): `README.md` — thiếu
- SC-STD-2 (cảnh báo): `CHANGELOG.md` — thiếu
- SC-STD-2 (cảnh báo): `LICENSE` — thiếu
