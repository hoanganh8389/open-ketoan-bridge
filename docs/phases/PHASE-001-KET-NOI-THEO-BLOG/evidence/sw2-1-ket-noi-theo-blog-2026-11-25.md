<!-- [2026-11-25 09:10 AM Johnny Chu - Chu Hoàng Anh] SW2-1 — bằng chứng: câu hỏi kết nối theo blog (B001-C2, C6, T2). -->
# SW2-1 — kết nối theo blog: "blog này nối open-ketoan chưa?" — 25/11/2026

SW2 cần một câu trả lời thật cho "shop này đang nối kế toán không" trước lần chuyển nguồn đầu (SW2-2, doc 06 §5.2 bước 0). Trước đây `tgs_okt_connected` chỉ trả "có khoá và có URL" — shop có khoá sai hay kế toán tắt vẫn hiện 🟢.

## Làm gì

**`TGS_Acc_Connection`** (`includes/class-tgs-acc-connection.php`, mới):

| Lời gọi | Việc |
|---|---|
| `connection($blog, $fresh)` | `state`, `ok`, `url`, `tenant`, `key_mode`, `checked_at`, `error`, bốn `conditions` — không có khoá thật |
| `connected($blog)` | cả bốn điều kiện đạt |
| `forget($blog)` | quên đệm (đổi khoá / URL — `save_blog` gọi) |
| `blogs_of_tenant($tenant)` | blog đang trỏ tenant, theo bản đồ chung với tự kiểm SC-3 (`tgs_acc_tenant_map`) |
| `tgs_okt_connection($blog)` | bản hàm của `connection()` (chữ ký chốt ở 00 §4) |
| `wp tgs-acc connection [--shop=\|--all] [--format=json]` | hỏi lại ngay, in bốn điều kiện |

**Bốn điều kiện:**
1. `config`: có URL + một nhánh khoá (riêng / khoá cài đặt / khoá chung).
2. `reachable`: `GET /api/v1/tenant` bằng khoá của chính blog, 2xx trong **3 giây**.
3. `tenant`: kế toán **báo** mã tenant (không suy từ khoá hay blog id — P-0 ý 3).
4. `unique`: không blog nào khác (khoá riêng / khoá cài đặt) trỏ cùng tenant — luật SC-3. Khoá chung của mạng không bị loại vì SC-3 (OKT-TOPO-8), chỉ ghi chú.

**Đệm 60 giây** theo blog (site transient), kể cả kết quả hỏng — kế toán chập chờn không làm trang nào chờ 3 giây mỗi lần. Đổi trạng thái → `do_action('tgs_okt_connection_changed', $blog, 'connected'|'standalone', $info)` (lần hỏi đầu không tính là đổi).

**Nối với tsm:** `TGS_Acc_Catalog_Sync::connected()` (filter `tgs_okt_connected` của tsm) trả theo `TGS_Acc_Connection::connected()`. Bridge **không** khai hàm `tgs_okt_connected` thứ hai (tsm khai, đọc filter) — một hàm duy nhất.

## Luật vai trò (P-0 ý 4)

- Quầy bán **không** đọc câu trả lời này. tsm dùng nó để **hiển thị** (nhãn "mất kết nối", chặn nút chuyển nguồn khi chưa 🟢). Nguồn danh mục `tgs_catalog_source` do admin chọn, không đổi theo chập chờn mạng: kế toán dừng thì shop vẫn bán, vẫn nhập tay được ở nguồn `local`.
- Lớp này không ghi vào kế toán, không đụng bảng POS / catalog (test kiểm trên mã nguồn).

## Chạy thật (okt-thu, 25/11)

`wp tgs-acc connection --all` trên okt-wp (đa site):

| Shop | Khoá | Kết quả |
|---|---|---|
| #1 okt.local | khoá cài đặt | 🔴 `reachable`: *"Cửa hàng chưa được kế toán nối vào sổ (chờ mã nối)"* (`BLOG_NOT_LINKED`) — đúng thực tế, blog 1 chưa được duyệt; ba điều kiện sau ghi "chưa kiểm" |
| #2 shop-a | riêng | 🟢 tenant `shopa` |
| #3 shop-b | khoá cài đặt | 🟢 tenant `shopb` |
| #6 shop-c | khoá cài đặt | 🟢 tenant `shopc` |

okt-wp1 (một site): 🟢 tenant `shopa`.

## Test

`tests/connection-wp-test.php` (client giả, không gọi ra ngoài): **32/32** shop-b, **30/30** okt-wp1.
- Điều kiện 1–4, mỗi điều kiện một ca hỏng + một ca đạt; điều kiện sau điều kiện hỏng ghi "chưa kiểm" và **không** gọi kế toán.
- Thời gian chờ lúc hỏi = 3 giây, trả lại giá trị cũ.
- Đệm: ba lần hỏi liền → một lượt gọi; `fresh`, `forget` hỏi lại; **kết quả hỏng cũng được đệm**.
- Hook: standalone → connected bắn một lần; không đổi thì không bắn; connected → standalone bắn.
- Trùng tenant: blog 2 (khoá riêng) cùng tenant → `unique` đỏ; khác tenant → đạt.
- Filter `tgs_okt_connected` đọc đệm đúng (true / false).
- Kết quả không chứa khoá; tenant là điều kế toán báo; ghi chú lỗi chỉ ở điều kiện hỏng.
- `save_blog()` quên đệm; lớp không chứa lời gọi ghi / bảng POS.

**Hồi quy:** 18 test không cần WP (selfcheck 70/70, install 37/37, MS0 32 tệp 0 vi phạm…) đạt; `catalog-source`, `catalog-dryrun` 14/14, `tenant-prepare` 16/16, `retry-blog-gone` 8/8 đạt; tsm `catalog-source-admin` 23/23 (shop-b) và 18/18 (okt-wp1).

## Chưa làm trong SW2-1 (còn lại)

- **B001-C4 — màn Network "Khoá theo shop"** (bảng blog · nguồn khoá · tenant · 🟢🟡⚪🔴 · lần kéo · Kiểm tất cả / Xuất CSV) và màn Kết nối của site: lõi đã sẵn (`connection()`), còn màn.
- **tsm S001-C1** (`tgs_tenant_of_blog` = `tgs_okt_connection()['tenant']`), **S001-C3 / C3b** (màn site "Đồng bộ kế toán", bảng tổng mạng): mở khoá nhờ B001-C6 này.
- Thay `TGS_Acc_Config::ready()` ở các chỗ khác (admin, events, branch / warehouse / supplier) bằng câu hỏi bốn điều kiện: chưa làm vì các chỗ đó cần "có khoá" tĩnh, không cần hỏi mạng — đổi thì mỗi trang admin thêm tối đa 3 giây/phút; ghi lại để chủ quyết.
