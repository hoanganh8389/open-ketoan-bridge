<!-- [2026-11-22 11:45 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — bằng chứng tự kiểm chạy thật vào open-ketoan thử (I-7, I-8). Không ghi khoá. -->
# OKT-SC-1 — tự kiểm blog → tenant chạy thật — 22/11/2026 11:45 AM

Người chạy: Johnny Chu (Claude Code), máy dev. open-ketoan thử `okt-thu` (open-ketoan `71fa48e2`, tenant `shopa`, http://127.0.0.1:8098). Bridge nhánh `feat/okt-sc-1-selfcheck`.

```
$ php tests/selfcheck-test.php
KẾT QUẢ: ĐẠT 41/41

$ OKT_URL=http://127.0.0.1:8098 OKT_KEY=<khoá shopa> php tests/selfcheck-live.php

### A. đơn tiền mặt (TM), xác nhận tenant
🟡 blog 2 → tenant shopa
  🟢 SC-1   Khoá riêng của blog
  🟢 SC-2   Tenant `shopa` đúng mã đã xác nhận
  🟢 SC-3   Không blog nào khác nối tenant này (theo các lần tự kiểm đã chạy)
  🟢 SC-4   Đọc được feed danh mục và trạng thái chứng từ
  🟢 SC-11  Lệch đồng hồ 0 giây so với kế toán
  🟢 SC-5   phương thức thanh toán: 1 mã đang dùng đều có ở tenant
  🟢 SC-6   mã thuế: 1 mã đang dùng đều có ở tenant
  🟢 SC-7   chi nhánh: 1 mã đang dùng đều có ở tenant
  🟡 SC-7   Tenant chưa có kho nào — định khoản giá vốn theo kho chưa kiểm
  🟢 SC-8   Chưa có lý do nào trên phiếu gần nhất để đối chiếu (tenant có 14)
  🟡 SC-9   2 chứng từ mẫu: 0 lỗi chặn, 2 cảnh báo
  🟢 SC-10  Hàng chờ 0 nhóm (cũ nhất 0.0 ngày), chờ định khoản > 24 h: 0, lỗi: 0

### B. đơn QR nhãn ngân hàng + thuế 3,5 % + lý do XTA
🔴 blog 2 → tenant shopa
  🟢 SC-1   Khoá riêng của blog
  🟢 SC-2   Tenant `shopa` đúng mã đã xác nhận
  🟢 SC-3   Không blog nào khác nối tenant này (theo các lần tự kiểm đã chạy)
  🟢 SC-4   Đọc được feed danh mục và trạng thái chứng từ
  🟢 SC-11  Lệch đồng hồ 0 giây so với kế toán
  🔴 SC-5   phương thức thanh toán: 1 mã bridge sẽ gửi mà tenant không có — QR-VTB-CT ×1
  🔴 SC-6   mã thuế: 1 mã bridge sẽ gửi mà tenant không có — R04 ×1
  🟢 SC-7   chi nhánh: 1 mã đang dùng đều có ở tenant
  🟡 SC-7   Tenant chưa có kho nào — định khoản giá vốn theo kho chưa kiểm
  🔴 SC-8   lý do: 1 mã bridge sẽ gửi mà tenant không có — XTA ×1
  🔴 SC-9   2 chứng từ mẫu: 2 lỗi chặn, 2 cảnh báo
  🟢 SC-10  Hàng chờ 0 nhóm (cũ nhất 0.0 ngày), chờ định khoản > 24 h: 0, lỗi: 0

### C. khoá sai
🔴 blog 2 → tenant ?
  🟢 SC-1   Khoá riêng của blog
  🔴 SC-2   Không đọc được danh tính tenant: Chưa đăng nhập hoặc phiên đã hết hạn
  🔴 SC-4   Khoá bị từ chối (401)
```

**Đọc kết quả:**
- Ca A: mọi phép xanh trừ hai ô vàng có thật trên tenant thử: 0 kho; preflight cảnh báo `IN-CFG-001` (chưa khai hồ sơ kế toán).
- Ca B: tự kiểm bắt `QR-VTB-CT` (SC-5), `R04` (SC-6), `XTA` (SC-8) **trước khi gửi**; preflight của kế toán cũng chặn cả 2 chứng từ — hai bên khớp.
- Ca C: khoá sai → 401 ở `/tenant`, SC-2 + SC-4 đỏ, dừng.
- Chưa chạy trong WordPress thật (`wp tgs-acc selfcheck`): cần WP thử (S002-1 phần WP).
