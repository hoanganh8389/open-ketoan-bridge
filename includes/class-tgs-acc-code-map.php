<?php
/**
 * Dịch mã bridge sinh → mã có ở tenant, theo bảng ánh xạ của blog (open-pos-backend S001-MD3, `TGS_Okt_Map`, {prefix}tgs_okt_map).
 *
 * Áp NGAY KHI DỰNG chứng từ (cuối TGS_Acc_Pusher::build / build_refs), trước mọi bước băm / gửi / cổng chờ / preflight /
 * tự kiểm — để sửa ánh xạ thì nội dung đổi → phiên bản mới → chứng từ lỗi mã được gửi lại với mã đúng.
 *   method  customer_payment.methodCode, sale.payments[].methodCode
 *   reason  reasonCode
 *   tax     lines[].taxCode
 * Kho có ánh xạ riêng (TGS_Acc_Warehouse_Sync). Chưa có open-pos-backend / chưa có bảng / chưa có dòng → giữ nguyên mã (I-6).
 * Chỉ đổi chứng từ dựng ra; không đụng sổ POS (I-0).
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — bridge áp bảng ánh xạ mã khi dựng chứng từ; tự dựng ánh xạ từ sổ + danh mục tenant.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Code_Map
{
    /** true = dựng chứng từ KHÔNG dịch (để tự dựng lấy đúng mã bridge sinh). */
    public static $raw = false;

    public static function enabled()
    {
        return !self::$raw && class_exists('TGS_Okt_Map');
    }

    /** Dịch mã của một chứng từ. $blog_id: blog của phiếu (mặc định đọc extra.blogId). */
    public static function apply(array $doc, $blog_id = 0)
    {
        if (!self::enabled()) {
            return $doc;
        }
        $blog = (int) ($blog_id ?: ($doc['extra']['blogId'] ?? 0));
        if ($blog <= 0) {
            return $doc;
        }
        $tr = function ($kind, $code) use ($blog) {
            $code = (string) $code;
            if ($code === '') {
                return $code;
            }
            $r = TGS_Okt_Map::remote($blog, $kind, $code);
            return ($r === null || $r === '') ? $code : $r;
        };
        if (isset($doc['methodCode'])) {
            $doc['methodCode'] = $tr('method', $doc['methodCode']);
        }
        if (!empty($doc['payments']) && is_array($doc['payments'])) {
            foreach ($doc['payments'] as $i => $p) {
                if (isset($p['methodCode'])) {
                    $doc['payments'][$i]['methodCode'] = $tr('method', $p['methodCode']);
                }
            }
        }
        if (isset($doc['reasonCode'])) {
            $doc['reasonCode'] = $tr('reason', $doc['reasonCode']);
        }
        if (!empty($doc['lines']) && is_array($doc['lines'])) {
            foreach ($doc['lines'] as $i => $l) {
                if (is_array($l) && isset($l['taxCode'])) {
                    $doc['lines'][$i]['taxCode'] = $tr('tax', $l['taxCode']);
                }
            }
        }
        return $doc;
    }

    public static function apply_all(array $docs, $blog_id = 0)
    {
        if (!self::enabled()) {
            return $docs;
        }
        foreach ($docs as $k => $d) {
            $docs[$k] = self::apply($d, $blog_id);
        }
        return $docs;
    }

    /**
     * Tự dựng ánh xạ của blog: dựng các phiếu gần nhất (CHƯA dịch) → mã bridge sinh; danh mục tenant qua API bằng khoá blog;
     * ghi gợi ý bằng TGS_Okt_Map::auto_fill (không ghi đè dòng admin).
     * @param array $deps (cho test) client, ids(callable $blog, $limit), build(callable $blog, $ids)
     * @return array|WP_Error ['tenant' => string, 'scanned' => int, 'report' => [kind => [local => [remote, source, used, status]]]]
     */
    public static function auto($blog_id, array $deps = [], $scan = 200)
    {
        $blog = (int) $blog_id;
        if (!class_exists('TGS_Okt_Map')) {
            return new WP_Error('no_map', 'Chưa có bảng ánh xạ (cần open-pos-backend có S001-MD3)');
        }
        $client = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog, false);
        if (!isset($deps['client']) && !$client->ready()) {
            return new WP_Error('no_key', "Blog #{$blog} chưa có khoá kế toán riêng");
        }
        $t = $client->get('/api/v1/tenant');
        if (is_wp_error($t)) {
            return $t;
        }
        $tenant = (string) ($t['data']['code'] ?? '');
        $remote = [];
        foreach (['method' => '/api/v1/posting/payment-methods', 'reason' => '/api/v1/posting/reasons', 'tax' => '/api/v1/tax-codes'] as $kind => $path) {
            $r = $client->get($path);
            if (is_wp_error($r)) {
                return $r;
            }
            $remote[$kind] = array_values(array_filter(array_map(function ($row) { return (string) (((array) $row)['code'] ?? ''); }, (array) ($r['data'] ?? []))));
        }
        $ids = isset($deps['ids']) ? call_user_func($deps['ids'], $blog, $scan) : TGS_Acc_Selfcheck::recent_ids($blog, $scan);
        self::$raw = true;
        try {
            $built = $ids ? (isset($deps['build']) ? call_user_func($deps['build'], $blog, $ids) : TGS_Acc_Pusher::build_refs($blog, $ids)) : ['docs' => []];
        } finally {
            self::$raw = false;
        }
        $docs = (array) ($built['docs'] ?? []);
        $u = TGS_Acc_Selfcheck::used_codes($docs);
        $labels = [];
        foreach ($docs as $d) {
            // Nhãn hình thức ở sổ ("Thu tiền khách hàng (QR VTB CT)") → để màn hiện cho người xác nhận.
            if (($d['docType'] ?? '') === 'customer_payment' && preg_match('/\(([^()]+)\)\s*$/u', (string) ($d['description'] ?? ''), $m)) {
                $labels['method'][(string) ($d['methodCode'] ?? '')] = $m[1];
            }
        }
        $used = ['method' => $u['methods'], 'reason' => $u['reasons'], 'tax' => $u['taxes']];
        $report = TGS_Okt_Map::auto_fill($blog, $used, $remote, $tenant, $labels);
        TGS_Okt_Map::flush($blog);
        return ['tenant' => $tenant, 'scanned' => count($ids), 'report' => $report];
    }
}
