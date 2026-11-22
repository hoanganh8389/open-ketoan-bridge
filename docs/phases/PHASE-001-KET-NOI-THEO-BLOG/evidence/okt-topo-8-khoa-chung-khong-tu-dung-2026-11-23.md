<!-- [2026-11-23 06:20 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-8 — bằng chứng (I-7). Không ghi khoá. -->
# OKT-TOPO-8 — khoá chung của mạng không còn làm tự kiểm tạm dừng cả mạng — 23/11/2026

Luật tối thượng P-0 (open-setup ADR §0; `LUAT-TOI-THUONG.md`): mỗi cửa hàng một tenant; bizcity chuyển sang khoá riêng từng site,
tự cài nhiều cửa hàng dùng khoá cài đặt. Trong lúc chuyển, mạng dùng **khoá chung** dồn mọi site vào một tenant (open-setup 82 B-8).

## Lỗi (tái hiện trên `okt-wp`, bridge `main` 34dd9fd)
shop-a, shop-b tạm dùng khoá chung của mạng (như bizcity hôm nay), chạy `TGS_Acc_Selfcheck::daily()`:
```
blog 2 SC-3 fail: Tenant `shopa` cũng đang nối với blog 1 — sổ hai shop trộn nhau
blog 2 tạm dừng: selfcheck: SC-3 tenant shopa trùng blog 1
blog 3 SC-3 fail: Tenant `shopa` cũng đang nối với blog 1, 2 — sổ hai shop trộn nhau
blog 3 tạm dừng: selfcheck: SC-3 tenant shopa trùng blog 1, 2
```
→ một lượt tự kiểm hằng ngày **dừng gửi của cả mạng** (quầy vẫn bán, nhưng không chứng từ nào lên kế toán).

## Sửa
SC-3: blog khoá chung (`legacy`) mà **mọi** blog trùng tenant cũng khoá chung → **vàng** (không tự dừng), nêu số site bị dồn và cách chuyển.
Có blog **khoá riêng** trỏ trùng tenant → vẫn **đỏ + tự tạm dừng** (trộn sổ thật). `enforce()` không đổi (chỉ dừng khi đỏ).

## Sau sửa (cùng môi trường)
```
blog 2 SC-3 warn: Khoá chung của mạng đang dồn 2 site vào một tenant `shopa` (blog 1) — mỗi cửa hàng phải là một tenant (P-0): bizcity
                  chuyển sang khoá riêng từng site; tự cài nhiều cửa hàng dùng khoá cài đặt. Không tự tạm dừng
blog 2 tạm dừng: không
blog 3 SC-3 warn: … dồn 3 site vào một tenant `shopa` (blog 1, 2) …
blog 3 tạm dừng: không
--- blog 3 đổi sang khoá RIÊNG trỏ trùng tenant của khoá chung:
blog 3 SC-3 fail · blog 3 tạm dừng: CÓ
```
Trả môi trường về như cũ: shop-a khoá riêng, tự kiểm SC-0b/1/2/3 xanh; shop-b chưa khoá; không blog nào tạm dừng.

## Test
`tests/selfcheck-test.php` 62/62 (+3 ca: cả mạng khoá chung → vàng + không dừng; khoá chung trùng blog khoá riêng → đỏ + dừng; khoá riêng trùng khoá chung → đỏ).
Ba ca đỏ với mã cũ. 15 tệp test đạt.
