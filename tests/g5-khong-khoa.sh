#!/usr/bin/env bash
# [2026-11-24 04:10 PM Johnny Chu - Chu Hoàng Anh] SW3-4 — ca vàng G5 (open-pos-backend PHASE-001 doc 01): bridge BẬT, chưa có khoá ở blog nào
# → mọi blog 🔵 tự quản, 0 lời gọi ra kế toán. Sau đó (khoá thật) S002-12: client theo blog gửi bằng khoá của chính blog đó.
#
# Cách làm: chép tests/g5-mu-khong-khoa.php vào mu-plugins (che mọi khoá, GIỮ URL; ghi + chặn mọi HTTP đi ra vào /tmp/g5-http.log),
# rồi bằng tiến trình THẬT: mở trang quản trị qua HTTP (bridge, tsm, POS), chạy mọi lịch của bridge ở từng blog, bán 1 đơn POS +
# tạo 1 mã tsm; gỡ mu-plugin (trap EXIT) rồi đếm nhật ký. CHỈ máy dev (mu-plugin + tests tự từ chối máy khác).
#   bash tests/g5-khong-khoa.sh                         (okt-wp multisite, bán ở shop-a)
#   W=okt-wp1-wp-1 BAN=127.0.0.1:8096 bash tests/g5-khong-khoa.sh   (máy một site)
#   DOI_CHUNG=1 bash tests/g5-khong-khoa.sh   đối chứng: GIỮ khoá thật, chỉ chạy các lịch kéo chỉ đọc (không bán) → bộ đếm PHẢI thấy lời gọi ra kế toán
export MSYS_NO_PATHCONV=1
W=${W:-okt-wp-wp-1}; BAN=${BAN:-shop-a.okt.local}; HERE="$(cd "$(dirname "$0")" && pwd)"
T=$(mktemp -d)
P() { cygpath -m "$1" 2>/dev/null || echo "$1"; }
MU=/var/www/html/wp-content/mu-plugins/okt-dev-g5-khong-khoa.php
kiem() { docker exec -e G5_PHASE="$1" $W wp --allow-root --url="$2" eval-file /tmp/g5-kiem.php 2>&1 | grep -vE "Warning: Constant|Deprecated" | sed 's/^/  /'; }
go_mu() { docker exec $W rm -f $MU /tmp/g5-giu-khoa; }
trap go_mu EXIT

docker cp "$(P "$HERE/g5-kiem-wp.php")" $W:/tmp/g5-kiem.php >/dev/null
URLS=$(docker exec $W wp --allow-root site list --field=url 2>/dev/null | sed -E 's#^https?://##; s#/$##')
[ -n "$URLS" ] || URLS=$(docker exec $W wp --allow-root option get home 2>/dev/null | sed -E 's#^https?://##; s#/$##')
echo "bản cài $W · blog: $(echo $URLS | tr '\n' ' ')"
KT=$(docker exec $W wp --allow-root eval 'echo (string) parse_url(TGS_Acc_Config::url_for(0), PHP_URL_HOST);' 2>/dev/null | tail -1)
echo "máy kế toán đang khai: ${KT:-(không)}"

echo "══ 1. bật mu-plugin G5 (che khoá, giữ URL, chặn + ghi HTTP)"
docker exec $W sh -c "mkdir -p /var/www/html/wp-content/mu-plugins && : > /tmp/g5-http.log && chmod 666 /tmp/g5-http.log"
docker cp "$(P "$HERE/g5-mu-khong-khoa.php")" $W:$MU >/dev/null
docker exec $W chown www-data:www-data $MU 2>/dev/null

if [ "${DOI_CHUNG:-}" = 1 ]; then
  docker exec $W touch /tmp/g5-giu-khoa
  echo "══ ĐỐI CHỨNG — giữ khoá thật, chạy các lịch kéo chỉ đọc (vẫn chặn HTTP)"
  for u in $URLS; do docker exec -e G5_PHASE=cron -e G5_HOOKS=tgs_acc_status_pull,tgs_acc_catalog_sync,tgs_acc_supplier_sync,tgs_acc_warehouse_sync $W wp --allow-root --url="$u" eval-file /tmp/g5-kiem.php 2>/dev/null | grep -E "^SAI|^  ok" | sed "s/^/  [$u] /"; done
  go_mu
  OUT=$(docker exec $W cat /tmp/g5-http.log | awk -F'	' -v kt="$KT" 'kt != "" && index($4, "//" kt) {n++} END {print n + 0}')
  docker exec $W cat /tmp/g5-http.log | awk -F'	' '{print "     " $3 " " $4}' | sort | uniq -c | sort -rn | head -8
  [ "$OUT" -gt 0 ] && echo "  ok  đối chứng: có khoá thì bộ đếm bắt được $OUT lời gọi ra kế toán (bộ đếm hoạt động)" || echo "SAI: đối chứng — có khoá mà bộ đếm không thấy lời gọi nào (bộ đếm hỏng)"
  exit 0
fi

echo "══ 2. trạng thái mọi blog"
kiem trangthai "$(echo "$URLS" | head -1)"

echo "══ 3. mở trang quản trị bằng HTTP (bridge · tsm · POS)"
TSMV=$(docker exec $W sh -c 'grep -ohE "page=tgs-shop-management&(amp;)?view=[a-z0-9_-]+" -r --include=*.php /var/www/html/wp-content/plugins/tgs_shop_management/admin-views' | sed 's/.*view=//' | sort -u)
for u in $URLS; do
  AUTH=$(docker exec $W wp --allow-root --url=$u eval '$e = time() + 900; $t = WP_Session_Tokens::get_instance(1)->create($e); echo AUTH_COOKIE, "=", rawurlencode(wp_generate_auth_cookie(1, $e, "auth", $t)), "; ", LOGGED_IN_COOKIE, "=", rawurlencode(wp_generate_auth_cookie(1, $e, "logged_in", $t));' 2>/dev/null | tail -1)
  { echo "/wp-admin/"; echo "/wp-admin/admin.php?page=tgs-shop-management&view=acc-bridge"; echo "/wp-admin/admin.php?page=tgs-shop-management&view=ke-toan"
    echo "/wp-admin/admin.php?page=tgs-shop-management"; echo "/wp-admin/admin.php?page=bizgpt-tmd-pos"; echo "/wp-admin/admin.php?page=tgs-okt-map"
    [ "$u" = "$BAN" ] && for v in $TSMV; do echo "/wp-admin/admin.php?page=tgs-shop-management&view=$v"; done; } > "$T/g5-urls.txt" 2>/dev/null || true
  [ -s "$T/g5-urls.txt" ] || { echo "SAI: không ghi được danh sách trang"; exit 1; }
  docker cp "$(P "$T/g5-urls.txt")" $W:/tmp/g5-urls.txt >/dev/null
  n0=$(docker exec $W sh -c 'wc -l < /tmp/g5-http.log')
  docker exec -e AUTH="$AUTH" -e HOSTH="$u" $W sh -c 'while read p; do c=$(curl -s -o /tmp/g5.html -w "%{http_code}" -H "Host: $HOSTH" -H "Cookie: $AUTH" "http://127.0.0.1$p"); b=$(grep -ciE "fatal error|<b>warning</b>|<b>notice</b>|critical error|lỗi nghiêm trọng|Uncaught" /tmp/g5.html); echo "$c $b $p"; done < /tmp/g5-urls.txt' > "$T/g5-pages.txt"
  n1=$(docker exec $W sh -c 'wc -l < /tmp/g5-http.log')
  echo "  [$u] $(wc -l < "$T/g5-pages.txt") trang: $(awk '{print $1}' "$T/g5-pages.txt" | sort | uniq -c | tr '\n' ' ')· lỗi PHP / 5xx: $(awk '$2 > 0 || $1 >= 500' "$T/g5-pages.txt" | wc -l) · lời gọi HTTP khi mở trang: $((n1 - n0))"
  awk '$2 > 0 || $1 >= 500 {print "     ↳ " $0}' "$T/g5-pages.txt" | head -10
done

echo "══ 4. chạy mọi lịch của bridge ở từng blog"
for u in $URLS; do R=$(kiem cron "$u"); echo "$R" | grep -vE "^  KẾT QUẢ|^    ok "; echo "  [$u] $(echo "$R" | tail -1 | sed "s/^ *//")"; done

echo "══ 5. bán 1 đơn POS + tạo 1 mã tsm ở $BAN"
kiem ban "$BAN"

echo "══ 6. gỡ mu-plugin, đếm nhật ký HTTP"
go_mu
docker exec $W cat /tmp/g5-http.log > "$T/g5-http.log"
TOTAL=$(wc -l < "$T/g5-http.log")
OUT=$(awk -F'\t' -v kt="$KT" 'kt != "" && index($4, "//" kt) {n++} END {print n + 0}' "$T/g5-http.log")
echo "  lời gọi HTTP đi ra (đã chặn): $TOTAL · trong đó tới máy kế toán ($KT): $OUT"
awk -F'\t' '{print "     " $2 " " $3 " " $4}' "$T/g5-http.log" | sort | uniq -c | sort -rn | head -12
[ "$OUT" = 0 ] && echo "  ok  G5: 0 lời gọi ra kế toán" || echo "SAI: G5 — $OUT lời gọi ra kế toán khi chưa có khoá"

echo "══ 7. khoá thật (đã gỡ mu-plugin): gửi bằng khoá của từng blog — S002-12"
kiem khoa "$(echo "$URLS" | head -1)"
