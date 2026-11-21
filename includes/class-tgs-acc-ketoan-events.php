<?php
/**
 * CỬA NHẬN SỰ KIỆN TỪ PHẦN MỀM KẾ TOÁN (14/11/2026, phiếu NT-BH-017, chữ chốt open-ketoan docs/nghiep-vu/23).
 *
 * `POST /wp-json/tgs-acc/v1/ketoan-events` — app Core `tgs-wp` giao webhook ký HMAC (phong bì BIZCITY-EVENT §7a:
 * { id, type, version, tenantId, occurredAt, subject, actor, data }). Nằm ở CẦU NỐI (có ở mọi shop) để shop KHÔNG cài plugin máy
 * bán POS vẫn nhận phiếu kế toán lập. Đặt địa chỉ webhook của app tgs-wp về cửa này (APP_WEBHOOK_URL_TGS_WP).
 *
 *   - phieu-ban.doc.recorded, kind sale  → TGS_Acc_Trade_Mapper::sale_to_mirror + TGS_Acc_Ledger_Mirror::apply (khoá pg:<docId>,
 *     bản số = data.version: duyệt lại sau khi sửa ghi đè cùng phiếu website);
 *   - phieu-ban.doc.recorded, kind sale_return → TGS_Acc_Trade_Mapper::return_to_mirror (phiếu hoàn loại 11 gắn bản sao phiếu bán gốc,
 *     tra theo originalDocKey; phiếu gốc chưa có bản sao → 409 để Core phát lại) — 15/11/2026, trước đó vào hàng chờ;
 *   - phieu-ban.doc.cancelled → TGS_Acc_Ledger_Mirror::cancel (xoá mềm các phiếu đã ghi); chưa có bản sao thì bỏ qua;
 *   - pos.return.recorded (phiếu trả máy bán React) → cầu nối tự ghi (pos_return: cùng bộ chuyển phiếu trả, gắn bản sao đơn gốc) — 15/11/2026;
 *   - pos.* khác (máy bán React) → chuyển cho tgs_pos (TGS_POS_Ketoan_Events::process) nếu shop cài; không cài thì lỗi 409 (giữ để phát lại).
 * Shop nhận: site có mã chi nhánh (TGS_Acc_Source::shop_code) = data.branchCode, không phân biệt hoa thường.
 *
 * Chữ ký: header Bizcity-Signature `t=<giây>,v1=<hex HMAC-SHA256(TGS_KETOAN_WEBHOOK_SECRET, "<t>.<thân>")>`, lệch > 5 phút từ chối.
 * Mọi sự kiện ghi {base_prefix}tgs_acc_ketoan_inbox (id, loại, site, trạng thái, lỗi): gửi lại cùng id → trả kết quả cũ.
 * Lỗi → 5xx để Core giữ sự kiện (dead) và phát lại được sau khi sửa (mã hàng chưa có ở website, site chưa nối chi nhánh…).
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Acc_Ketoan_Events
{
    const ROUTE_NS = 'tgs-acc/v1';
    const MAX_SKEW = 300;

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes()
    {
        register_rest_route(self::ROUTE_NS, '/ketoan-events', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle'],
            'permission_callback' => [__CLASS__, 'verify'],
        ]);
    }

    public static function secret()
    {
        return defined('TGS_KETOAN_WEBHOOK_SECRET') ? (string) TGS_KETOAN_WEBHOOK_SECRET : '';
    }

    /** @param WP_REST_Request $request */
    public static function verify($request)
    {
        $secret = self::secret();
        if ($secret === '') {
            return new WP_Error('tgs_ketoan_no_secret', 'Chưa cấu hình TGS_KETOAN_WEBHOOK_SECRET', ['status' => 503]);
        }
        $parts = [];
        foreach (explode(',', (string) $request->get_header('bizcity_signature')) as $kv) {
            $p = explode('=', trim($kv), 2);
            if (count($p) === 2) $parts[$p[0]] = $p[1];
        }
        $ts = (string) ($parts['t'] ?? '');
        $v1 = strtolower((string) ($parts['v1'] ?? ''));
        if (!ctype_digit($ts) || $v1 === '') {
            return new WP_Error('tgs_ketoan_unsigned', 'Thiếu chữ ký', ['status' => 401]);
        }
        if (abs(time() - (int) $ts) > self::MAX_SKEW) {
            return new WP_Error('tgs_ketoan_expired', 'Chữ ký quá hạn (lệch giờ > 5 phút)', ['status' => 401]);
        }
        if (!hash_equals(hash_hmac('sha256', $ts . '.' . $request->get_body(), $secret), $v1)) {
            return new WP_Error('tgs_ketoan_bad_signature', 'Sai chữ ký', ['status' => 401]);
        }
        return true;
    }

    /** @param WP_REST_Request $request */
    public static function handle($request)
    {
        $evt = json_decode($request->get_body(), true);
        if (!is_array($evt) || !is_string($evt['id'] ?? null) || !is_string($evt['type'] ?? null) || !is_array($evt['data'] ?? null)) {
            return new WP_Error('tgs_ketoan_bad_body', 'Thân không phải sự kiện', ['status' => 422]);
        }
        $id = substr($evt['id'], 0, 64);
        self::ensure_inbox();
        $done = self::inbox_get($id);
        // 'queued' (phiếu trả trước 15/11 chưa có bộ ánh xạ) không tính là xong: phát lại thì ghi bản sao.
        if ($done && in_array($done->status, ['applied', 'skipped'], true)) {
            return rest_ensure_response(['eventId' => $id, 'status' => $done->status, 'replayed' => true]);
        }
        $result = self::process($evt);
        $status = is_wp_error($result) ? 'failed' : (string) $result['status'];
        self::inbox_put($id, $evt, $status, is_wp_error($result) ? $result->get_error_message() : ($result['reason'] ?? null), is_wp_error($result) ? null : ($result['blog_id'] ?? null));
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $code = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $code >= 500 ? $code : 500, 'cause' => $code]);
        }
        return rest_ensure_response(['eventId' => $id] + $result);
    }

    /** @return array{status: string, blog_id?: int, ledgerIds?: array, reason?: string}|WP_Error */
    public static function process(array $evt)
    {
        $type = (string) $evt['type'];
        $data = (array) $evt['data'];
        if ($type === 'pos.return.recorded') {
            return self::pos_return($data);
        }
        if (strpos($type, 'pos.') === 0) {
            if (class_exists('TGS_POS_Ketoan_Events') && method_exists('TGS_POS_Ketoan_Events', 'process')) {
                return TGS_POS_Ketoan_Events::process($evt);
            }
            return new WP_Error('tgs_ketoan_no_pos', 'Sự kiện máy bán POS cần plugin tgs_pos ở website', ['status' => 409]);
        }
        if (!in_array($type, ['phieu-ban.doc.recorded', 'phieu-ban.doc.cancelled'], true)) {
            return ['status' => 'skipped', 'reason' => 'Loại sự kiện không xử lý: ' . $type];
        }
        $blog_id = self::blog_for_branch((string) ($data['branchCode'] ?? ''));
        if ($blog_id <= 0) {
            return new WP_Error('tgs_ketoan_no_shop', 'Không có website nối chi nhánh ' . ($data['branchCode'] ?? '?') . ' (Cấu hình phân cấp multisite)', ['status' => 409]);
        }
        $doc_id = (string) ($data['docId'] ?? '');
        $version = max(1, (int) ($data['version'] ?? 1));
        if ($type === 'phieu-ban.doc.cancelled') {
            if (!TGS_Acc_Ledger_Mirror::has_mirror($blog_id, $doc_id)) {
                return ['status' => 'skipped', 'blog_id' => $blog_id, 'reason' => 'Phiếu chưa có bản sao — không có gì để huỷ'];
            }
            $r = TGS_Acc_Ledger_Mirror::cancel($blog_id, $doc_id, $version);
            return is_wp_error($r) ? $r : ['status' => 'applied', 'blog_id' => $blog_id, 'ledgerIds' => $r['ledger_ids']];
        }
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, ['sale', 'sale_return'], true)) {
            return ['status' => 'skipped', 'blog_id' => $blog_id, 'reason' => 'Loại phiếu không xử lý: ' . $kind];
        }
        $doc = is_array($data['doc'] ?? null) ? $data['doc'] : null;
        if (!$doc) {
            return new WP_Error('tgs_ketoan_bad_body', 'Sự kiện thiếu nội dung phiếu', ['status' => 422]);
        }
        $ctx = ['blog_id' => $blog_id, 'doc_id' => $doc_id, 'version' => $version, 'user_id' => 0];
        if ($kind === 'sale_return') {
            // Phiếu trả gắn phiếu bán gốc ĐÃ CÓ bản sao ở website (originalDocKey = phieu-ban:<id>); chưa có → lỗi để Core phát lại sau.
            $orig = preg_replace('/^phieu-ban:/', '', (string) ($data['originalDocKey'] ?? ''));
            $ctx['parent_ledger_id'] = $orig !== '' ? TGS_Acc_Ledger_Mirror::ledger_id_of($blog_id, $orig, 'sale') : 0;
            if ($ctx['parent_ledger_id'] <= 0) {
                return new WP_Error('tgs_ketoan_no_original', 'Phiếu bán gốc ' . ($doc['originalDocNo'] ?? '?') . ' chưa có bản sao ở website — phát lại sau khi phiếu gốc về', ['status' => 409]);
            }
            $ctx['parent_code'] = (string) ($doc['originalDocNo'] ?? '');
        }
        $switched = is_multisite() && get_current_blog_id() !== $blog_id;
        if ($switched) switch_to_blog($blog_id);
        try {
            $ctx['user_id'] = self::user_id((string) ($data['cashier']['name'] ?? ''));
            $body = $kind === 'sale' ? TGS_Acc_Trade_Mapper::sale_to_mirror($doc, $ctx) : TGS_Acc_Trade_Mapper::return_to_mirror($doc, $ctx);
        } finally {
            if ($switched) restore_current_blog();
        }
        if (is_wp_error($body)) return $body;
        $r = TGS_Acc_Ledger_Mirror::apply($body);
        if (is_wp_error($r)) return $r;
        return ['status' => $r['skipped'] ? 'skipped' : 'applied', 'blog_id' => $blog_id, 'ledgerIds' => $r['ledger_ids']];
    }

    /**
     * Phiếu trả của máy bán React (pos.return.recorded, data.return = ảnh chụp phiếu trả) → bản sao phiếu hoàn loại 11 gắn bản sao đơn gốc
     * (khoá pg:<orderId>, vai trò sale), cùng bộ chuyển với phiếu trả kế toán lập. Ảnh chụp chỉ có tiền hoàn GỒM thuế từng dòng: giá chưa
     * thuế và thuế suất lấy theo dòng xuất của đơn gốc ở website (export / zexport), tiền chưa thuế = làm tròn(hoàn ÷ (1 + thuế suất)).
     * Đổi hàng (exchangeOrderId): tiền hoàn đối trừ đơn mới → không phiếu chi.
     */
    private static function pos_return(array $data)
    {
        $blog_id = self::blog_for_branch((string) ($data['branchCode'] ?? ''));
        if ($blog_id <= 0) {
            return new WP_Error('tgs_ketoan_no_shop', 'Không có website nối chi nhánh ' . ($data['branchCode'] ?? '?') . ' (Cấu hình phân cấp multisite)', ['status' => 409]);
        }
        $ret = is_array($data['return'] ?? null) ? $data['return'] : null;
        $order_id = (string) ($data['orderId'] ?? '');
        $return_id = (string) ($data['returnId'] ?? ($ret['id'] ?? ''));
        if (!$ret || $order_id === '' || $return_id === '') {
            return new WP_Error('tgs_ketoan_bad_body', 'Sự kiện thiếu ảnh chụp phiếu trả / mã đơn', ['status' => 422]);
        }
        $parent = TGS_Acc_Ledger_Mirror::ledger_id_of($blog_id, $order_id, 'sale');
        if ($parent <= 0) {
            return new WP_Error('tgs_ketoan_no_original', 'Đơn gốc ' . ($data['orderCode'] ?? '?') . ' chưa có bản sao ở website — phát lại sau khi đơn về', ['status' => 409]);
        }
        // Giá chưa thuế (đơn vị nhỏ nhất) + thuế suất theo mã hàng, từ dòng xuất của đơn gốc.
        global $wpdb;
        $p = $wpdb->get_blog_prefix($blog_id);
        $src = [];
        foreach (['export', 'zexport'] as $role) {
            $lid = TGS_Acc_Ledger_Mirror::ledger_id_of($blog_id, $order_id, $role);
            if ($lid <= 0) continue;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT local_product_sku, price, local_ledger_item_tax_percent FROM {$p}local_ledger_item WHERE local_ledger_id = %d AND is_deleted = 0", $lid)) as $r) {
                $src[(string) $r->local_product_sku] = $src[(string) $r->local_product_sku] ?? ['price' => (float) $r->price, 'tax' => (float) $r->local_ledger_item_tax_percent];
            }
        }
        $lines = [];
        foreach ((array) ($ret['lines'] ?? []) as $i => $l) {
            if (!is_array($l) || (float) ($l['qty'] ?? 0) <= 0) continue;
            $sku = (string) ($l['sku'] ?? '');
            $ratio = max(1.0, (float) ($l['unitRatio'] ?? 1));
            $qty = (float) $l['qty'] * $ratio;
            $s = $src[$sku] ?? null;
            if (!$s) {
                return new WP_Error('tgs_ketoan_no_line', "Mã {$sku} không có trong bản sao đơn gốc ở website", ['status' => 409]);
            }
            $gift = !empty($l['isGift']);
            $refund = round((float) ($l['refund'] ?? 0));
            $before = $gift ? 0.0 : round($refund / (1 + $s['tax'] / 100));
            $lines[] = [
                'lineKey' => (string) ($l['lineId'] ?? 'r' . $i), 'sku' => $sku, 'qty' => $qty, 'unit' => (string) ($l['unitName'] ?? ''), 'unitRatio' => $ratio,
                'unitPrice' => $s['price'], 'amountBeforeTax' => $before, 'discount' => $gift ? 0.0 : max(0.0, round($s['price'] * $qty - $before, 2)),
                'taxCode' => $s['tax'] > 0 ? 'R' . str_pad((string) (int) $s['tax'], 2, '0', STR_PAD_LEFT) : 'R00', 'taxAmount' => $gift ? 0.0 : $refund - $before,
                'isGift' => $gift,
            ];
        }
        if (!$lines) {
            return ['status' => 'skipped', 'blog_id' => $blog_id, 'reason' => 'Phiếu trả không có dòng hàng'];
        }
        $exchange = !empty($ret['exchangeOrderId']);
        $total = round((float) ($ret['refundTotal'] ?? 0));
        $customer = (array) ($ret['customer'] ?? []);
        $doc = [
            'docNo' => (string) ($data['code'] ?? ($ret['code'] ?? '')), 'docDate' => (string) ($ret['businessDate'] ?? ''), 'originalDocNo' => (string) ($data['orderCode'] ?? ''),
            'note' => (string) ($ret['reason'] ?? ''), 'lines' => $lines, 'totals' => ['total' => $total],
            'partner' => ['code' => (string) ($customer['phone'] ?? ''), 'name' => (string) ($customer['name'] ?? '')],
            'payments' => !$exchange && $total > 0 ? [['methodCode' => (string) ($ret['refundMethod'] ?? 'TM'), 'amount' => $total]] : [],
        ];
        $switched = is_multisite() && get_current_blog_id() !== $blog_id;
        if ($switched) switch_to_blog($blog_id);
        try {
            $body = TGS_Acc_Trade_Mapper::return_to_mirror($doc, [
                'blog_id' => $blog_id, 'doc_id' => $return_id, 'version' => max(1, (int) ($data['version'] ?? 1)), 'user_id' => self::user_id((string) ($data['cashier']['name'] ?? '')),
                'parent_ledger_id' => $parent, 'parent_code' => (string) ($data['orderCode'] ?? ''), 'entity' => 'return', 'doc_key' => (string) ($data['docKey'] ?? ''),
                'via' => 'pos_react', 'exchange' => $exchange,
            ]);
        } finally {
            if ($switched) restore_current_blog();
        }
        if (is_wp_error($body)) return $body;
        $r = TGS_Acc_Ledger_Mirror::apply($body);
        if (is_wp_error($r)) return $r;
        return ['status' => $r['skipped'] ? 'skipped' : 'applied', 'blog_id' => $blog_id, 'ledgerIds' => $r['ledger_ids']];
    }

    /** Site có mã chi nhánh trùng (không phân biệt hoa thường); 0 nếu không có. */
    public static function blog_for_branch($code)
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') return 0;
        $blogs = is_multisite() ? array_map('intval', get_sites(['fields' => 'ids', 'number' => 0])) : [get_current_blog_id()];
        foreach ($blogs as $b) {
            if (strtoupper((string) TGS_Acc_Source::shop_code($b)) === $code) return $b;
        }
        return 0;
    }

    /** Người dùng của site có tên hiển thị (hoặc tên đăng nhập) đúng tên người duyệt — một người duy nhất, không thì 0. */
    private static function user_id($name)
    {
        $name = trim($name);
        if ($name === '') return 0;
        foreach (['display_name', 'user_login'] as $col) {
            $ids = get_users(['blog_id' => get_current_blog_id(), 'search' => $name, 'search_columns' => [$col], 'fields' => 'ID', 'number' => 2]);
            if (count($ids) === 1) return (int) $ids[0];
        }
        return 0;
    }

    private static function inbox_table()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'tgs_acc_ketoan_inbox';
    }

    private static function ensure_inbox()
    {
        global $wpdb;
        $t = self::inbox_table();
        if (get_site_option('tgs_acc_ketoan_inbox') === '1') return;
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t} (
            event_id varchar(64) NOT NULL,
            type varchar(64) NOT NULL,
            subject varchar(160) NOT NULL DEFAULT '',
            blog_id bigint unsigned NULL,
            status varchar(16) NOT NULL,
            error text NULL,
            body longtext NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (event_id),
            KEY status (status)
        ) {$wpdb->get_charset_collate()}");
        update_site_option('tgs_acc_ketoan_inbox', '1');
    }

    private static function inbox_get($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT status FROM ' . self::inbox_table() . ' WHERE event_id = %s', $id));
    }

    private static function inbox_put($id, array $evt, $status, $error, $blog_id)
    {
        global $wpdb;
        $wpdb->replace(self::inbox_table(), [
            'event_id' => $id, 'type' => substr((string) $evt['type'], 0, 64), 'subject' => substr((string) ($evt['subject'] ?? ''), 0, 160),
            'blog_id' => $blog_id, 'status' => $status, 'error' => $error,
            // Hàng chờ (phiếu trả) giữ thân để ghi sau khi có bộ ánh xạ; trạng thái khác chỉ cần dấu vết.
            'body' => $status === 'queued' || $status === 'failed' ? wp_json_encode($evt, JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => current_time('mysql'),
        ]);
    }
}
