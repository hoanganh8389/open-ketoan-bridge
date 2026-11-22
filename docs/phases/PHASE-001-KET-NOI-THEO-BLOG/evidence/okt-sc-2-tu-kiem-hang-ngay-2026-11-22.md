<!-- [2026-11-22 02:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — bằng chứng (I-7). Không ghi khoá. -->
# OKT-SC-2 — tự kiểm hằng ngày, tự tạm dừng, giữ hàng chờ — 22/11/2026 02:40 PM

Người chạy: Johnny Chu (Claude Code), máy dev; okt-thu (tenant `shopa`). Bridge nhánh `feat/okt-sc-2-tu-kiem-hang-ngay`.

```
$ for t in tests/*-test.php; do php $t | tail -1; done
branch-unit-test.php   KẾT QUẢ: ĐẠT
code-map-test.php      KẾT QUẢ: ĐẠT 18/18
config-test.php        KẾT QUẢ: ĐẠT 8/8
pause-cli-test.php     KẾT QUẢ: ĐẠT
sc2-test.php           KẾT QUẢ: ĐẠT 24/24
selfcheck-test.php     KẾT QUẢ: ĐẠT 42/42
source-pos-test.php    KẾT QUẢ: ĐẠT
status-test.php        KẾT QUẢ: ĐẠT

# Chạy thật vào okt-thu: blog đã xác nhận tenant shopb, khoá lại trỏ shopa
  >> TỰ TẠM DỪNG blog 2: selfcheck: SC-2 khoá trỏ tenant shopa, đã xác nhận shopb

### A. đơn tiền mặt (TM), xác nhận tenant
🔴 blog 2 → tenant shopa
  🟢 SC-1   Khoá riêng của blog
  🔴 SC-2   Khoá trỏ tenant `shopa`, blog đã xác nhận `shopb` — chứng từ sẽ vào sổ của đơn vị khác
  🟢 SC-3   Không blog nào khác nối tenant này (theo các lần tự kiểm đã chạy)
  🟢 SC-4   Đọc được feed danh mục và trạng thái chứng từ
### C. khoá sai
🔴 blog 2 → tenant ?
  🟢 SC-1   Khoá riêng của blog
  🔴 SC-2   Không đọc được danh tính tenant: Chưa đăng nhập hoặc phiên đã hết hạn
  🔴 SC-4   Khoá bị từ chối (401)
```

- Khoá trỏ nhầm tenant (đã xác nhận `shopb`, khoá trỏ `shopa`): SC-2 đỏ → `enforce` **tự tạm dừng** gửi, lý do lưu `tgs_acc_paused_reason`.
- Khoá sai (401): SC-2 / SC-4 đỏ nhưng **không** tự tạm dừng — không gọi được không phải là sai danh tính; tạm dừng lúc kế toán tắt sẽ chặn gửi bù khi kế toán sống lại.
- Màn Cổng kế toán (tab Tự kiểm, cột Kết nối) **chưa xem trong WordPress thật** — cần WP thử.
