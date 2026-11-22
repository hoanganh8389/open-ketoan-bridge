<?php
/**
 * ĐỐI CHIẾU WP ↔ KẾ TOÁN CỦA MỘT BLOG (OKT-H4, scoreboard H4; tự kiểm SC-12 — 75-LUAT-TU-KIEM).
 *
 * CHỈ ĐỌC: không ghi sổ POS (I-0), không ghi kế toán. Hai phần:
 *
 * 1. Chứng từ theo docKey, trong [from, to] theo ngày chứng từ, chi nhánh của blog:
 *      - "WP lẽ ra phải có" = chứng từ dựng bằng chính đường gửi (TGS_Acc_Pusher::build) từ sổ của blog;
 *      - "kế toán đang có"  = GET /api/v1/documents?from&to&branchCode&sourceSystem=btsoft, lọc docKey btsoft:{blog}:…
 *    → thiếu (WP có, kế toán không) · mồ côi (kế toán có, WP không — vd chứng từ thử gửi tay) · đang xin huỷ · chờ định khoản ·
 *      lỗi · bản định khoản cũ (postedVersion < version).
 *
 * 2. Tồn theo SKU (các SKU vừa có biến động trong khoảng, tối đa $opts['skus']):
 *      WP  = TGS_Global_Product_Source::get_stock_for_skus() — đúng số POS dùng (sổ đã duyệt);
 *      KT  = GET /api/v1/inventory/items?skus&branchCode — chỉ tính chứng từ ĐÃ định khoản;
 *      chờ = ảnh hưởng của chứng từ WP chưa định khoản (thiếu / chờ / lỗi) — bán −, khách trả +, nhập mua +, trả NCC −.
 *    lệch = WP − (KT + chờ). Khác 0 → báo (giai đoạn đầu chỉ cảnh báo — 75 SC-12). Lệch còn lại thường do: chứng từ mồ côi đã
 *    định khoản, chứng từ ngoài khoảng ngày, phiếu WP không lên kế toán (vd phiếu nhập không có lý do nhập).
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-22 09:45 PM Johnny Chu - Chu Hoàng Anh] OKT-H4 — đối chiếu tự động; ma trận 65 MT-6: bắt chứng từ mồ côi, không chỉ lệch số.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Reconcile
{
    const DAYS = 7;
    const SKUS = 50;
    const LIST_PAGE = 500;
    const SAMPLE = 20; // số docKey liệt kê mỗi nhóm

    /** Dấu ảnh hưởng tồn của từng loại chứng từ (chỉ các loại có hướng rõ; loại khác báo riêng). */
    const SIGN = ['sale' => -1, 'sale_return' => 1, 'purchase' => 1, 'purchase_return' => -1];

    /**
     * @param array $opts days | from, to (Y-m-d) | skus
     * @param array $deps (test) client, today, branch, build(blog, from, to) → docs, stock(skus, blog) → [sku => qty]
     * @return array ['blog', 'from', 'to', 'counts', 'missing', 'orphan', 'cancel_pending', 'pending', 'error', 'stale', 'stock', 'checks', 'verdict']
     */
    public static function run($blog_id, array $opts = [], array $deps = [])
    {
        $blog = (int) $blog_id;
        $today = (string) ($deps['today'] ?? (function_exists('wp_date') ? wp_date('Y-m-d') : date('Y-m-d')));
        $days = max(1, (int) ($opts['days'] ?? self::DAYS));
        $to = (string) ($opts['to'] ?? $today);
        $from = (string) ($opts['from'] ?? date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days')));
        $max_skus = max(1, (int) ($opts['skus'] ?? self::SKUS));
        $branch = array_key_exists('branch', $deps) ? (string) $deps['branch'] : (class_exists('TGS_Acc_Source') ? (string) TGS_Acc_Source::shop_code($blog) : '');
        $client = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog);
        $r = ['blog' => $blog, 'from' => $from, 'to' => $to, 'branch' => $branch, 'counts' => [], 'missing' => [], 'orphan' => [], 'cancel_pending' => [],
            'pending' => [], 'error' => [], 'stale' => [], 'stock' => [], 'checks' => [], 'verdict' => 'pass'];

        // 1. WP lẽ ra phải có
        $built = isset($deps['build']) ? (array) call_user_func($deps['build'], $blog, $from, $to) : (array) (TGS_Acc_Pusher::build($blog, $from, $to)['docs'] ?? []);
        $want = [];
        foreach ($built as $d) {
            $day = substr((string) ($d['docDate'] ?? ''), 0, 10);
            if (!empty($d['docKey']) && $day >= $from && $day <= $to) { // build còn lấy phiếu SỬA trong khoảng mà ngày cũ hơn → bỏ, kế toán lọc theo ngày
                $want[(string) $d['docKey']] = $d;
            }
        }

        // 2. Kế toán đang có
        $have = self::remote($client, $blog, $branch, $from, $to);
        if (is_wp_error($have)) {
            self::add($r, 'R-1', 'fail', 'Không đọc được danh sách chứng từ của tenant: ' . $have->get_error_message(), []);
            return self::finish($r);
        }

        foreach ($want as $k => $d) {
            $h = $have[$k] ?? null;
            if (!$h) {
                $r['missing'][] = $k;
                continue;
            }
            $st = (string) ($h['status'] ?? '');
            if ($st === 'pending') {
                $r['pending'][] = $k;
            } elseif ($st === 'error') {
                $r['error'][] = $k . ($h['errorMessage'] ? ': ' . $h['errorMessage'] : '');
            } elseif ($st === 'posted' && (int) ($h['postedVersion'] ?? 0) < (int) ($h['version'] ?? 0)) {
                $r['stale'][] = $k;
            }
        }
        foreach ($have as $k => $h) {
            if (isset($want[$k]) || (string) ($h['status'] ?? '') === 'cancelled') {
                continue;
            }
            if (!empty($h['cancelRequestedAt'])) {
                $r['cancel_pending'][] = $k;
            } else {
                $r['orphan'][] = $k . ' (' . (string) ($h['status'] ?? '?') . ')';
            }
        }
        $r['counts'] = ['wp' => count($want), 'ketoan' => count($have), 'missing' => count($r['missing']), 'orphan' => count($r['orphan']),
            'cancel_pending' => count($r['cancel_pending']), 'pending' => count($r['pending']), 'error' => count($r['error']), 'stale' => count($r['stale'])];

        $c = $r['counts'];
        self::add($r, 'R-1', $c['missing'] ? 'warn' : 'pass', $c['missing'] ? "{$c['missing']} / {$c['wp']} chứng từ của WP chưa có ở kế toán" : "{$c['wp']} chứng từ của WP đều có ở kế toán", ['keys' => array_slice($r['missing'], 0, self::SAMPLE)]);
        self::add($r, 'R-2', $c['orphan'] ? 'warn' : 'pass', $c['orphan'] ? "{$c['orphan']} chứng từ ở kế toán không có phiếu gốc trên WP (mồ côi) — xin huỷ hoặc tìm phiếu gốc" : 'Không có chứng từ mồ côi', ['keys' => array_slice($r['orphan'], 0, self::SAMPLE)]);
        if ($c['cancel_pending']) {
            self::add($r, 'R-2', 'warn', "{$c['cancel_pending']} chứng từ đang chờ kế toán duyệt huỷ", ['keys' => array_slice($r['cancel_pending'], 0, self::SAMPLE)]);
        }
        self::add($r, 'R-3', $c['error'] ? 'fail' : ($c['stale'] ? 'warn' : 'pass'), sprintf('Chờ định khoản %d · lỗi %d · định khoản bản cũ %d', $c['pending'], $c['error'], $c['stale']), ['error' => array_slice($r['error'], 0, self::SAMPLE), 'stale' => array_slice($r['stale'], 0, self::SAMPLE)]);

        // 3. Tồn theo SKU
        $moved = [];
        $effect = [];
        $other = 0;
        foreach ($want as $k => $d) {
            $type = (string) ($d['docType'] ?? '');
            $h = $have[$k] ?? null;
            $not_posted = !$h || (string) ($h['status'] ?? '') !== 'posted';
            foreach ((array) ($d['lines'] ?? []) as $l) {
                $sku = trim((string) ($l['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $moved[$sku] = ($moved[$sku] ?? 0) + 1;
                if ($not_posted) {
                    if (isset(self::SIGN[$type])) {
                        $effect[$sku] = ($effect[$sku] ?? 0) + self::SIGN[$type] * abs((float) ($l['qty'] ?? 0));
                    } elseif (in_array($type, ['transfer', 'stock_adjustment'], true)) {
                        $other++;
                    }
                }
            }
        }
        if (!$moved) {
            self::add($r, 'R-4', 'pass', 'Không có mặt hàng nào biến động trong khoảng để đối chiếu tồn', []);
            return self::finish($r);
        }
        arsort($moved);
        $skus = array_slice(array_keys($moved), 0, $max_skus);
        $wp = isset($deps['stock']) ? (array) call_user_func($deps['stock'], $skus, $blog) : self::wp_stock($skus, $blog);
        $kt = self::remote_stock($client, $skus, $branch);
        if (is_wp_error($kt)) {
            self::add($r, 'R-4', 'warn', 'Không đọc được tồn của tenant: ' . $kt->get_error_message(), []);
            return self::finish($r);
        }
        $off = [];
        foreach ($skus as $sku) {
            $w = round((float) ($wp[$sku] ?? 0), 4);
            $k = array_key_exists($sku, $kt) ? round((float) $kt[$sku], 4) : null;
            $e = round((float) ($effect[$sku] ?? 0), 4);
            $diff = $k === null ? null : round($w - ($k + $e), 4);
            $row = ['sku' => $sku, 'wp' => $w, 'ketoan' => $k, 'chua_dinh_khoan' => $e, 'lech' => $diff];
            $r['stock'][] = $row;
            if ($k === null || abs((float) $diff) > 0.0001) {
                $off[] = $row;
            }
        }
        $n = count($skus);
        if ($off) {
            $list = implode(', ', array_map(function ($o) { return $o['ketoan'] === null ? "{$o['sku']} (kế toán chưa có mã)" : sprintf('%s WP %s ↔ KT %s%s', $o['sku'], self::num($o['wp']), self::num($o['ketoan']), $o['chua_dinh_khoan'] ? ' + chờ ' . self::num($o['chua_dinh_khoan']) : ''); }, array_slice($off, 0, 5)));
            $why = $c['orphan'] || $c['cancel_pending'] ? ' — có chứng từ mồ côi / chờ huỷ, xem R-2' : ' — có thể do chứng từ ngoài khoảng ngày hoặc phiếu WP không lên kế toán';
            self::add($r, 'R-4', 'warn', count($off) . " / {$n} mã lệch tồn: {$list}{$why}", ['off' => array_slice($off, 0, self::SAMPLE)]);
        } else {
            self::add($r, 'R-4', 'pass', "{$n} mã: tồn WP = tồn kế toán + chứng từ chưa định khoản", []);
        }
        if ($other) {
            self::add($r, 'R-4', 'warn', "{$other} dòng chuyển kho / điều chỉnh chưa định khoản — không tính vào phần chờ, có thể gây lệch tạm", []);
        }
        return self::finish($r);
    }

    /** [docKey => hàng] của blog trong khoảng — chia trang. */
    private static function remote($client, $blog, $branch, $from, $to)
    {
        $out = [];
        $prefix = 'btsoft:' . (int) $blog . ':';
        for ($page = 1; $page <= 200; $page++) {
            $q = array_filter(['from' => $from, 'to' => $to, 'branchCode' => $branch, 'sourceSystem' => 'btsoft', 'page' => $page, 'pageSize' => self::LIST_PAGE], 'strlen');
            $res = $client->get('/api/v1/documents', $q);
            if (is_wp_error($res)) {
                return $res;
            }
            $rows = (array) ($res['data'] ?? []);
            foreach ($rows as $row) {
                $row = (array) $row;
                $k = (string) ($row['docKey'] ?? '');
                if (strpos($k, $prefix) === 0) {
                    $out[$k] = $row;
                }
            }
            $total = (int) ($res['meta']['total'] ?? 0);
            if (count($rows) < self::LIST_PAGE || ($total && $page * self::LIST_PAGE >= $total)) {
                break;
            }
        }
        return $out;
    }

    /** [sku => tồn] bên kế toán (chi nhánh của blog). Mã kế toán chưa có thì không có trong mảng. */
    private static function remote_stock($client, array $skus, $branch)
    {
        $out = [];
        foreach (array_chunk($skus, 200) as $chunk) {
            $res = $client->get('/api/v1/inventory/items', array_filter(['skus' => implode(',', $chunk), 'branchCode' => $branch, 'limit' => 200], 'strlen'));
            if (is_wp_error($res)) {
                return $res;
            }
            foreach ((array) ($res['data'] ?? []) as $row) {
                $row = (array) $row;
                $out[(string) $row['sku']] = (float) ($row['stock'] ?? 0);
            }
        }
        return $out;
    }

    /** [sku => tồn thực] bên WP — đúng hàm POS / quản trị dùng (sổ đã duyệt). */
    private static function wp_stock(array $skus, $blog)
    {
        if (!class_exists('TGS_Global_Product_Source') || !method_exists('TGS_Global_Product_Source', 'get_stock_for_skus')) {
            return [];
        }
        $out = [];
        foreach ((array) TGS_Global_Product_Source::get_stock_for_skus($skus, (int) $blog) as $sku => $s) {
            $out[(string) $sku] = (float) ((array) $s)['actual_stock'];
        }
        return $out;
    }

    private static function num($v)
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    }

    private static function add(array &$r, $id, $level, $message, array $data)
    {
        $r['checks'][] = ['id' => $id, 'level' => $level, 'message' => $message, 'data' => $data];
    }

    private static function finish(array $r)
    {
        $levels = array_column($r['checks'], 'level');
        $r['verdict'] = in_array('fail', $levels, true) ? 'fail' : (in_array('warn', $levels, true) ? 'warn' : 'pass');
        return $r;
    }
}
