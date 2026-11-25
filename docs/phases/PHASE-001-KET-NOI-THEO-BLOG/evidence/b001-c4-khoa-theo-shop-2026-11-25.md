<!-- [2026-11-25 10:50 AM Johnny Chu - Chu Hoàng Anh] B001-C4 — bằng chứng: màn "Khoá theo shop". -->
# B001-C4 — màn "Khoá theo shop" — 25/11/2026

Tab mới **Khoá theo shop** trong Cổng kế toán: mỗi blog một dòng, đọc từ `TGS_Acc_Connection` (SW2-1), không có khoá thật.

| Cột | Nguồn |
|---|---|
| Shop (mã, tên, #blog) | `blogs.tgs_site_code`, `tgs_shop_name` / `blogname` |
| Nguồn khoá · khoá che | `TGS_Acc_Config::for_display_blog` (riêng / khoá cài đặt / khoá chung / chưa có) |
| Địa chỉ | `url_for` |
| Tenant | điều kế toán **báo** (đệm kết nối) — không suy từ khoá, blog id (P-0 ý 3) |
| Trạng thái | 🟢 đạt bốn điều kiện · 🟡 đạt nhưng khoá chung dồn nhiều site một tenant · ⚪ chưa khoá / chưa kiểm · 🔴 một điều kiện hỏng (kèm lý do) |

Nút: **Kiểm** (một dòng, hỏi lại ngay), **Kiểm tất cả** (lần lượt từng blog, mỗi blog tối đa 3 giây), **Sửa** (URL + khoá riêng của blog, quản trị cấp cao; lưu xong tự hỏi lại), **Xuất CSV** (không khoá).
AJAX: `tgs_acc_blog_list` (đọc đệm, **không hỏi mạng**) · `tgs_acc_blog_check` · `tgs_acc_blog_save` (cần quyền `configure`).

## Chạy thật (okt-thu, 25/11)
| Shop | Nguồn khoá | Tenant | Trạng thái |
|---|---|---|---|
| #1 | khoá cài đặt | — | 🔴 `BLOG_NOT_LINKED` (đúng thực tế: chưa được duyệt) |
| #2 shop-a | riêng (`okt_s…b1`) | shopa | 🟢 |
| #3 shop-b | khoá cài đặt | shopb | 🟢 |
| #6 shop-c | khoá cài đặt | shopc | 🟢 |

## Test
`tests/blog-keys-wp-test.php`: **33/33** shop-b, **34/34** okt-wp1. Hồi quy: connection 32/32, selfcheck 70/70, install 37/37, config 8/8, profile 11/11, MS0 33 tệp 0 vi phạm.
Kiểm: trạng thái 5 ca; danh sách không phát HTTP nào; khoá thật không có trong JSON / CSV; CSV có BOM, thoát dấu phẩy / nháy; AJAX đăng ký, `blog_save` cần `configure`; lớp không ghi kế toán / bảng POS.

## Chưa làm / ghi chú
- Cột "lần kéo" hiện là **lần kiểm kết nối** gần nhất: lần kéo danh mục (`tgs_acc_catalog_sync_at`) đang lưu cấp mạng, chưa theo blog.
- Khoá cài đặt chưa có phần che ở dòng (ô "Khoá" để trống với blog dùng khoá cài đặt).
- Màn Kết nối riêng của site (URL riêng, Xoá): đang gộp ở nút **Sửa** (gõ `-` để xoá khoá).
