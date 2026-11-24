# Cấu hình kết nối open-ketoan — bốn trường hợp

<!-- [2026-11-21 01:39 PM Johnny Chu - Chu Hoàng Anh] R-OKT-CONN — tài liệu cấu hình kết nối theo bốn trường hợp: toàn mạng, một site nhánh, tầng mạng (site 0), localhost của chủ. Thiết kế đích; mã 0.5.0 mới có tầng mạng. -->

Tài liệu cho **người cài đặt và vận hành** open-pos (`tgs_pos` + `tgs_shop_management`) và **người sửa mã bridge**.

Luật gốc là **R-OKT-CONN** trong CANON open-ketoan (`docs/rules/RULE-OPEN-POS-CONNECTION.md`). Tài liệu này chỉ nói **cấu hình ở đâu, ai cấu hình, ra kết quả gì**. Khi lệch với luật, luật thắng.

> **Trạng thái: thiết kế đích, chưa có mã.** Bridge 0.5.0 mới có **một tầng cấu hình cho cả mạng** (`get_site_option('tgs_acc_bridge_config')` và hằng wp-config). Tầng **blog** ở §2 và bảng khoá theo blog ở §4 là việc S001-BR7 của tgs_shop_management PHASE-001. Mỗi mục bên dưới ghi rõ cái nào **đã có** và cái nào **sẽ có**.

---

## 0. Một câu hỏi quyết định tất cả

Mọi màn sửa sản phẩm, giá, thuế ở open-pos-backend đều hỏi một hàm:

```php
tgs_okt_connected( $blog_id )   // true = blog này có kết nối API thành công với open-ketoan qua bridge
```

| Trả lời | Blog chạy | Nghĩa là |
|---|---|---|
| **Không** | **standalone** | Shop tự sửa sản phẩm, giá, thuế. Bán hàng đầy đủ, không cần kế toán |
| **Có** | **connected** | Trường gốc chỉ đọc, theo kế toán. Ánh xạ mặc định tự dựng, có đối chứng |

"Có" phải **đủ cả bốn điều kiện**:

1. Plugin open-ketoan-bridge đang bật.
2. Blog phân giải ra được **URL máy chủ kế toán** (theo thứ tự ở §2).
3. Blog có **khoá API của chính tenant mình** (theo thứ tự ở §2). Khoá dùng chung cả mạng **không tính**.
4. Lần kiểm sức khoẻ gần nhất thành công: `GET /api/v1/catalog/feed/meta` bằng khoá đó trả 200, đúng tenant, và **không blog nào khác giữ cùng tenant**. Kết quả giữ 60 giây.

Một blog = một shop = một tenant (R-OKT-CONN-6). Vì thế **câu hỏi luôn được hỏi theo blog**, kể cả khi cả mạng cùng kết nối.

---

## 1. Bốn trường hợp trong một bảng

| | 1. Toàn mạng | 2. Một site nhánh | 3. Tầng mạng (site 0) | 4. Localhost của chủ |
|---|---|---|---|---|
| **Ý nghĩa** | Mọi shop trong multisite dùng open-ketoan | Chỉ một vài shop dùng open-ketoan, các shop khác standalone | Ngữ cảnh **không thuộc blog nào**: Network Admin, WP-CLI không có `--url`, cron cấp mạng | Máy dev của chủ dự án: WordPress local + open-ketoan local |
| **Ai cấu hình** | Super admin, ở Network Admin | Super admin (URL chung) + admin của site đó (khoá) | Super admin | Chủ dự án, trong `wp-config.php` |
| **URL máy chủ kế toán** | Tầng mạng, một URL cho mọi blog | Tầng mạng; site có thể đè bằng URL riêng | Tầng mạng | Hằng `TGS_KETOAN_API_URL` (vd `http://localhost:8088`) |
| **Khoá API** | **Mỗi blog một khoá** của tenant blog đó, nhập ở bảng khoá theo blog (§4) | Chỉ site đó có khoá | **Không có khoá dữ liệu.** Việc cấp mạng lặp qua từng blog và dùng khoá của blog đó | Hằng `TGS_KETOAN_API_KEYS` (blog → khoá); máy một site thì dùng `TGS_KETOAN_API_KEY` |
| **Kết quả `tgs_okt_connected`** | Có cho blog đủ khoá và sức khoẻ tốt; Không cho blog thiếu khoá | Có cho site đó; Không cho mọi site khác | **Luôn Không** (không có tenant) | Có cho blog có khoá trong map |
| **Màn hình** | Bảng tổng: blog · tenant · trạng thái xanh / vàng / đỏ | Site đó xanh, còn lại xám "standalone" | Chỉ xem gom, không sửa catalog | Như 1 hoặc 2, kèm nhãn "khoá từ wp-config" |

---

## 2. Thứ tự đọc cấu hình (áp dụng cho cả bốn trường hợp)

Ba tầng, đọc từ trên xuống, gặp giá trị đầu tiên thì dừng.

| Tầng | Nơi lưu | Ai sửa | Hiện có? |
|---|---|---|---|
| **H** — hằng | `wp-config.php` | Người quản trị máy chủ / chủ dự án | ✅ có (`TGS_KETOAN_API_URL`, `TGS_KETOAN_API_KEY`, …) · `TGS_KETOAN_API_KEYS` **sẽ có** |
| **B** — blog | `get_option('tgs_acc_bridge_config')` trong ngữ cảnh blog | Admin site, hoặc super admin qua bảng §4 | **sẽ có** |
| **M** — mạng | `get_site_option('tgs_acc_bridge_config')` | Super admin | ✅ có (đây là tầng duy nhất của 0.5.0) |

### 2.1 Từng thiết lập đọc ở tầng nào

| Thiết lập | Thứ tự | Ghi chú |
|---|---|---|
| `url` (API kế toán) | H → B → M | Có hằng thì khoá cứng, site không đè được |
| `web_url` (người dùng mở trên trình duyệt) | H → B → M | |
| `key` (khoá dữ liệu của tenant) | `TGS_KETOAN_API_KEYS[blog]` → B → `TGS_KETOAN_API_KEY` **chỉ khi không phải multisite** | **Không bao giờ đọc khoá ở tầng M** để trả lời câu hỏi kết nối |
| `key_legacy` (khoá cũ của tenant chung) | M, hoặc hằng `TGS_KETOAN_API_KEY` trên multisite | Chỉ dùng để **đẩy chứng từ** trong giai đoạn chuyển tiếp (§6). Không làm `tgs_okt_connected` thành "Có" |
| `timeout`, `chunk_days`, `realtime` | H → B → M | |
| `sso_secret`, `sso_tenant` | H → M | Đăng nhập kế toán bằng tài khoản WP: cấp mạng. `sso_tenant` sẽ thành theo blog khi có tenant theo blog |
| `TGS_KETOAN_SSO_AUD` (hằng, tuỳ chọn) | M | Tên app đích trong vé SSO (`aud`); mặc định `open-ketoan`, phải bằng `WP_SSO_AUD` bên phần mềm kế toán. Vé luôn mang thêm `sub` = `wp:<ID người dùng WordPress>`. <!-- [2026-11-24 11:10 PM Johnny Chu - Chu Hoàng Anh] SPINE-6 --> |
| `tax_url`, `tax_key` (sổ thuế) | H → B → M | Sổ thuế cũng là một tenant: theo blog như `key` |
| `gate_key` (cổng chờ cho kế toán kéo chứng từ) | M | Cấp mạng; ở §5 có cách kế toán chỉ thấy blog của tenant mình |
| `catalog_from_erp` | — | **Bỏ.** Thay bằng `tgs_okt_connected()` (R-OKT-CONN-1) |

### 2.2 Hằng wp-config

```php
// Chung cho cả mạng (tầng H, đã có)
define('TGS_KETOAN_API_URL', 'https://ketoan.ten-mien.com');   // địa chỉ API
define('TGS_KETOAN_WEB_URL', 'https://ketoan.ten-mien.com');   // tuỳ chọn

// Khoá theo blog (tầng H, sẽ có) — dùng cho localhost, hoặc máy chủ muốn giữ khoá ngoài CSDL
define('TGS_KETOAN_API_KEYS', [
    95  => 'kt_…',   // blog 95 → khoá tenant của shop 95
    112 => 'kt_…',
]);

// Máy MỘT site (không multisite): khoá của chính site đó (đã có)
define('TGS_KETOAN_API_KEY', 'kt_…');
```

Trên multisite, `TGS_KETOAN_API_KEY` được hiểu là **khoá cũ dùng chung** (`key_legacy`), không phải khoá của blog nào.

---

## 3. Từng trường hợp — các bước

### 3.1 Toàn mạng

1. **Super admin → Network Admin → Cổng kế toán → Cài đặt:** nhập URL máy chủ kế toán (tầng M), hoặc khai hằng `TGS_KETOAN_API_URL`.
2. **Bên kế toán:** mỗi shop một tenant, chủ sở hữu tenant tạo khoá ở *Tiện ích → Khoá API*. Khoá cần đọc danh mục, ghi chứng từ, và tạo mã mới (R-OKT-CONN-8).
3. **Network Admin → Cổng kế toán → Khoá theo shop** (§4, sẽ có): dán khoá cho từng blog, rồi bấm **Kiểm tất cả**.
4. Mỗi blog kiểm xong sẽ tự làm ba việc:
   - dựng ánh xạ mặc định: tenant, chi nhánh, bảng giá, nhóm, ĐVT, mã thuế, hình thức thanh toán;
   - kéo đầy đủ lần đầu;
   - đối chứng.
5. **Kết quả:** blog xanh là `connected`; blog chưa có khoá là `standalone`.

Kiểm: T-CFG-1, 2, 5, 6, 7 ở §7.

### 3.2 Một site nhánh

1. Super admin nhập URL ở tầng M như 3.1. Bước này làm một lần, mọi site dùng chung.
2. **Admin của site đó → Cổng kế toán (màn của site, sẽ có) → Kết nối:** dán khoá của tenant shop mình, bấm **Kiểm tra**.
   - Shop dùng một máy chủ kế toán khác, ví dụ đơn vị tự cài open-ketoan: nhập thêm URL riêng ở tầng B. URL này chỉ áp cho site đó.
3. **Kết quả:** site đó `connected`, mọi site khác vẫn `standalone` và không bị ảnh hưởng gì.
4. Ngắt kết nối: xoá khoá ở màn của site. Site về `standalone`, dữ liệu đã kéo ở lại.

Kiểm: T-CFG-2, 3, 4.

### 3.3 Tầng mạng (site 0)

"Site 0" là mọi ngữ cảnh **không thuộc blog nào**:
- màn Network Admin;
- WP-CLI chạy không có `--url` / `--blog`;
- cron đăng ký ở cấp mạng;
- REST nhận sự kiện từ kế toán **trước khi** biết sự kiện đó thuộc blog nào.

Luật cho tầng mạng:
- `tgs_okt_connected(0)` **luôn là Không**: tầng mạng không có tenant, nên không sửa và không kéo catalog.
- Việc cấp mạng (đẩy chứng từ `--all`, đồng bộ phân kho, đồng bộ chi nhánh) **lặp qua từng blog**, `switch_to_blog($id)` rồi dùng **khoá của blog đó**. Blog nào không có khoá thì bỏ qua và ghi lý do.
- Sự kiện từ kế toán gửi vào (`/wp-json/tgs-acc/v1/ketoan-events`, `/catalog-changed`) phải tìm ra blog **từ tenant của khoá / chữ ký** trước. Hiện mã tìm theo `branchCode` (`blog_for_branch`). Không tìm ra blog thì từ chối, không ghi vào blog nào.
- Network Admin chỉ **xem gom**: trạng thái từng blog, lỗi, lần kéo gần nhất. Không có catalog chung để sửa.
- Site chính (blog 1) **không phải** site 0: nó là một blog bình thường, có tenant và khoá riêng nếu là một shop.

Kiểm: T-CFG-8, 9, 10.

### 3.4 Localhost của chủ dự án

1. Chạy open-ketoan local (`http://localhost:8088`). Đăng nhập bằng tài khoản **chủ sở hữu**, tạo tenant thử cho từng shop thử, tạo khoá ở *Tiện ích → Khoá API*.
2. `wp-config.php` của WordPress local:
   - **Local một site:** `TGS_KETOAN_API_URL` + `TGS_KETOAN_API_KEY`.
   - **Local multisite:** `TGS_KETOAN_API_URL` + `TGS_KETOAN_API_KEYS` (blog → khoá).
3. Màn hình hiện nhãn **"khoá từ wp-config"**, ô khoá bị khoá (R-BLOCK: ghi lý do và cách gỡ).
4. Chạy thử bằng dòng lệnh. `--url` / `--key` đè mọi tầng, **chỉ trong lượt chạy đó**:

```bash
php wp-content/plugins/open-ketoan-bridge/tools/day-ke-toan.php --blog=95 --from=2026-11-01 --to=2026-11-21 --dry
php wp-content/plugins/open-ketoan-bridge/tools/day-ke-toan.php --blog=95 --from=2026-11-01 --dry --url=http://localhost:8088 --key=kt_…
```

5. **Chuyển nơi nhận** (local → máy chủ thật hoặc ngược lại): nhật ký gửi ghi theo phiếu, không ghi theo máy chủ. Lần đầu sang máy chủ khác phải chạy kèm `--force` (xem `LUONG-BAN-HANG-SANG-KE-TOAN.md` §9).
6. **Không** commit `wp-config.php`, **không** dán khoá thật vào tài liệu, issue hay ảnh chụp.

Kiểm: T-CFG-11, 12.

---

## 4. Màn "Khoá theo shop" (Network Admin, sẽ có)

| Cột | Nội dung |
|---|---|
| Blog | id, tên shop, mã chi nhánh hiện tại |
| Nguồn khoá | wp-config · đặt ở site · chưa có · legacy (khoá chung) |
| Khoá | che, chỉ hiện 4 ký tự cuối. Ô nhập mới; `-` để xoá |
| Tenant | mã tenant kế toán trả về khi kiểm |
| Trạng thái | 🟢 connected · 🟡 có khoá nhưng kiểm lỗi (đang standalone, ghi giờ) · ⚪ standalone (chưa có khoá) · 🔴 trùng tenant với blog khác |
| Lần kéo / đối chứng | giờ, số dòng, lệch |

Nút: **Kiểm tất cả** · **Kiểm dòng này** · **Kéo ngay** · **Xuất CSV trạng thái**. CSV không có khoá.

---

## 5. Các tình huống kết nối cấu hình phải xử lý đúng

| # | Tình huống | `tgs_okt_connected` | Đẩy chứng từ | Màn hình |
|---|---|---|---|---|
| K1 | Bridge chưa cài / đang tắt | Không | không | open-pos-backend chạy standalone, không báo lỗi |
| K2 | Có bridge, chưa có URL | Không | không | ⚪ "chưa cấu hình máy chủ kế toán" |
| K3 | Có URL, blog chưa có khoá | Không | chỉ bằng khoá legacy, nếu còn (§6) | ⚪ standalone |
| K4 | Khoá sai / bị thu hồi (401 / 403) | Không | dừng, ghi lỗi | 🟡 "khoá bị từ chối", gửi thông báo admin |
| K5 | Máy chủ kế toán không trả lời / quá thời gian | Không (sau 60 giây) | xếp hàng, gửi lại khi thông | 🟡 "không kết nối được từ HH:MM", shop sửa được, sửa được ghi nhật ký |
| K6 | Khoá đúng nhưng tenant đã có blog khác giữ | Không, cho **cả hai** blog | dừng ở cả hai | 🔴 "trùng tenant với blog N" |
| K7 | Kết nối thông lại sau K5 | Có | gửi phần đã xếp hàng | 🟢, kèm "N thay đổi tại shop đã bị kế toán ghi đè" |
| K8 | Site đè URL sang máy chủ khác | theo khoá ở máy chủ đó | theo khoá ở máy chủ đó | 🟢, ghi "URL riêng của site" |
| K9 | Ngữ cảnh tầng mạng (site 0) | Không | lặp từng blog bằng khoá của blog | Chỉ xem gom |
| K10 | Sự kiện từ kế toán không tìm ra blog | — | — | từ chối 404, ghi nhật ký |
| K11 | WP-CLI có `--url` / `--key` | theo tham số, chỉ trong lượt đó | theo tham số | — |
| K12 | Hằng wp-config có khoá | theo hằng | theo hằng | nhãn "khoá từ wp-config", ô khoá bị khoá |
| K13 | Đổi khoá của blog sang tenant khác | Có sau khi kiểm, nhưng **dừng kéo** cho tới khi admin xác nhận | dừng tới khi xác nhận | 🟡 "đổi tenant: dữ liệu cũ thuộc tenant X" |

---

## 6. Giai đoạn chuyển tiếp: khoá chung cũ

Hiện mọi shop đẩy chứng từ bằng **một khoá** vào **một tenant**, trong đó mỗi shop là một chi nhánh. Cắt ngay thì các shop ngừng đẩy chứng từ. Vì thế:

| Việc | Trước khi blog có khoá riêng | Sau khi blog có khoá riêng |
|---|---|---|
| Câu hỏi kết nối catalog | **Không**: blog chạy standalone | Có (nếu kiểm tốt) |
| Đẩy chứng từ bán hàng | bằng `key_legacy`, nhãn 🟡 "legacy tenant chung" | bằng khoá của blog. **Thôi** dùng `key_legacy` cho blog đó |
| Đồng bộ chi nhánh (`TGS_Acc_Branch_Sync`) | giữ như cũ cho tenant chung | không tạo chi nhánh trong tenant chung nữa |

Bỏ hẳn `key_legacy` khi mọi blog đã có tenant riêng. Việc tách tenant phía kế toán là S001-K9.

---

## 7. Ca kiểm

| # | Làm | Mong đợi |
|---|---|---|
| T-CFG-1 | Toàn mạng: URL ở tầng M, khoá cho 3 blog, blog thứ 4 không khoá | 3 blog 🟢 `connected`, blog 4 ⚪ `standalone` |
| T-CFG-2 | Một blog 🟢 thử sửa giá | Bị chặn, thông báo "Sửa ở kế toán" + link |
| T-CFG-3 | Một site nhánh: chỉ site đó có khoá | Site đó 🟢; site khác vẫn sửa sản phẩm được |
| T-CFG-4 | Site đè URL riêng | Chỉ site đó gọi máy chủ riêng (xem nhật ký lượt gọi) |
| T-CFG-5 | Hai blog dán cùng một khoá | Cả hai 🔴, cả hai standalone, không kéo |
| T-CFG-6 | Tắt máy chủ kế toán 2 phút | Sau ≤ 60 giây: 🟡, sửa được; bật lại: 🟢, có danh sách bị ghi đè |
| T-CFG-7 | Khoá bị thu hồi bên kế toán | 🟡 "khoá bị từ chối", không mở vòng lặp gọi lại |
| T-CFG-8 | `tgs_okt_connected(0)` ở Network Admin | false; không có nút sửa catalog |
| T-CFG-9 | `day-ke-toan.php --all` khi 2 / 5 blog có khoá | 2 blog gửi bằng khoá riêng; 3 blog dùng legacy (nếu còn) hoặc bỏ qua, ghi lý do |
| T-CFG-10 | Gửi `catalog-changed` với khoá của tenant không gắn blog nào | 404, không blog nào bị kéo |
| T-CFG-11 | Localhost một site, khoá ở hằng | 🟢, ô khoá bị khoá, nhãn "wp-config" |
| T-CFG-12 | Localhost multisite, `TGS_KETOAN_API_KEYS` có 1 blog | Chỉ blog đó 🟢 |

---

## 8. Việc sửa mã để đạt tài liệu này (bridge)

| # | Việc | Tệp |
|---|---|---|
| C1 | Thêm tầng B; `url( $blog_id )`, `key( $blog_id )`, `key_legacy()`, `source( $name, $blog_id )` theo bảng §2.1 | `includes/class-tgs-acc-config.php` |
| C2 | `TGS_Acc_Api_Client` mặc định lấy URL / khoá của **blog hiện tại**; nhật ký 60 lượt gọi ghi `blog_id` | `includes/class-tgs-acc-api-client.php` |
| C3 | Đẩy chứng từ: khoá blog, nếu không có thì `key_legacy` (§6) | `includes/class-tgs-acc-pusher.php`, `tools/day-ke-toan.php` |
| C4 | Màn "Khoá theo shop" (§4) + màn Kết nối của site | `includes/class-tgs-acc-admin.php`, `admin/page-cong-ke-toan.php` |
| C5 | Sự kiện vào: tìm blog từ tenant trước, rồi mới theo `branchCode` | `includes/class-tgs-acc-ketoan-events.php`, `includes/class-tgs-acc-catalog-sync.php` (route) |
| C6 | Hàm `tgs_okt_connected()` (bốn điều kiện §0, giữ kết quả 60 giây, kiểm trùng tenant). Bridge cung cấp; open-pos-backend gọi | file mới trong `includes/` |
| C7 | Bỏ `catalog_from_erp`; gỡ phần kéo catalog vào bảng global khi open-pos-backend nhận việc (S001-BR6) | `includes/class-tgs-acc-catalog-sync.php` |

Mỗi dòng một PR nhỏ, theo thứ tự C1 → C2 → C6 → C3 → C4 → C5 → C7.
