<?php
/**
 * PHIẾU BÁN KẾ TOÁN LẬP (module phieu-ban, khuôn TradeDoc — open-ketoan docs/api/13-phieu-nhap-tay.md) → thân bản sao local_ledger,
 * đúng hình dạng POS cũ ghi một đơn bán (như TGS_POS_Order_Mapper của tgs_pos):
 *   - sale (loại 10): mã = số phiếu kế toán, tổng, chiết khấu, khách, nguồn phần mềm 'ketoan';
 *   - receipt_N (loại 7): mỗi hình thức thanh toán một phiếu thu; không thu (ghi nợ) → một phiếu thu 0đ "mặc định" như POS cũ;
 *   - export (loại 2): phiếu xuất kèm dòng hàng (giá chưa thuế, chưa giảm; chiết khấu, thuế theo dòng).
 * Mã phiếu thu / phiếu xuất sinh cố định theo id phiếu (gửi lại không đổi mã) — cùng thuật toán tgs_pos.
 * Mã hàng không có trong danh mục website → lỗi (không tự tạo mặt hàng), sự kiện giữ ở trạng thái lỗi để phát lại.
 * Phiếu trả (sale_return, 15/11/2026) → đúng hình dạng POS cũ ghi một phiếu khách trả hàng (TGS_POS_Return_Service::commit):
 *   - return (loại 11): mã = số phiếu kế toán (TL…), cha = phiếu bán gốc ĐÃ CÓ bản sao ở website, dòng hàng loại 3 (tồn tự cộng lại);
 *   - payment_N (loại 8): chỉ khi kế toán ghi trả tiền khách — không trả thì như POS cũ: không phiếu chi, ghi "còn nợ khách".
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Acc_Trade_Mapper
{
    /** Mã hình thức thanh toán kế toán → mã POS website (phiếu thu), nhãn. Mã khác giữ nguyên. */
    const METHODS = ['TM' => 'cash', 'CK' => 'transfer', 'THE' => 'card', 'QR' => 'qr'];
    const METHOD_NAMES = ['cash' => 'Tiền mặt', 'transfer' => 'Chuyển khoản', 'card' => 'Thẻ', 'qr' => 'QR VTB CT'];

    /**
     * @param array $doc TradeDoc đã tính ở kế toán (data.doc của sự kiện phieu-ban.doc.recorded)
     * @param array $ctx blog_id, user_id, doc_id, version
     * @return array|WP_Error thân một chứng từ cho TGS_Acc_Ledger_Mirror::apply
     */
    public static function sale_to_mirror(array $doc, array $ctx)
    {
        $id = (string) ($ctx['doc_id'] ?? '');
        $code = (string) ($doc['docNo'] ?? '');
        $lines = array_values(array_filter((array) ($doc['lines'] ?? []), 'is_array'));
        if ($id === '' || $code === '' || !$lines) {
            return new WP_Error('tgs_map_invalid', 'Phiếu thiếu id / số phiếu / dòng hàng', ['status' => 422]);
        }
        $blog_id = (int) ($ctx['blog_id'] ?? get_current_blog_id());
        $user_id = (int) ($ctx['user_id'] ?? 0);
        $now = current_time('mysql');
        $catalog = self::catalog(array_map(static function ($l) { return (string) ($l['sku'] ?? ''); }, $lines));
        $missing = [];
        foreach ($lines as $l) {
            if (!isset($catalog[(string) ($l['sku'] ?? '')])) $missing[] = (string) ($l['sku'] ?? '');
        }
        if ($missing) {
            return new WP_Error('tgs_map_unknown_sku', 'Mã hàng không có trong danh mục website: ' . implode(', ', array_unique($missing)), ['status' => 422, 'skus' => array_values(array_unique($missing))]);
        }

        $total = round((float) ($doc['totals']['total'] ?? 0));
        $discount = 0.0;
        foreach ($lines as $l) $discount += (float) ($l['discount'] ?? 0);
        $receipts = self::receipts($doc);
        $partner = (array) ($doc['partner'] ?? []);
        $common = [
            'local_ledger_status' => 4,
            'local_ledger_approver_status' => 1,
            'local_ledger_approver_id' => $user_id,
            'user_id' => $user_id,
            'created_at' => self::local_date((string) ($doc['docDate'] ?? ''), $now),
        ];
        $paid_rows = array_values(array_map(static function ($r) { return ['method' => $r['method'], 'amount' => $r['amount'], 'label' => $r['label']]; },
            array_filter($receipts, static function ($r) { return !$r['placeholder']; })));
        $split = count($paid_rows) > 1;
        $sale_meta = [
            'is_closed' => true, 'closed_reason' => 'Đã tạo phiếu thu', 'closed_at' => $now, 'created_via' => 'ketoan', 'delivery_method' => 'Tại chỗ',
            'payment_method' => $split ? 'split' : (string) ($paid_rows[0]['method'] ?? 'cash'),
            'payment_method_label' => $split ? 'Hỗn hợp' : (string) ($paid_rows[0]['label'] ?? 'Tiền mặt'),
            'payment_receipts' => $paid_rows, 'pending_payment_receipts' => [], 'is_split_payment' => $split ? 1 : 0, 'payment_sync_status' => 'completed',
            'checkout_token' => $id, 'customer_cash_given' => 0.0, 'ketoan_doc_key' => 'phieu-ban:' . $id,
        ];
        if (array_sum(array_column($paid_rows, 'amount')) < $total) $sale_meta['credit_sale'] = 1;
        if (!empty($doc['invoice']['no'])) {
            $sale_meta['invoice'] = ['no' => (string) $doc['invoice']['no'], 'serial' => (string) ($doc['invoice']['serial'] ?? ''), 'date' => (string) ($doc['invoice']['date'] ?? '')];
        }
        $advance = [
            'pos_sale_source' => 'Kế toán lập',
            'export_reason' => ['code' => 'XBA', 'label' => class_exists('TGS_POS_Export_Reason') ? TGS_POS_Export_Reason::label_for_code('XBA') : 'Xuất bán tại Cửa hàng'],
        ];

        $ledger = [];
        $ledger[] = $common + [
            'role' => 'sale',
            'local_ledger_type' => 10,
            'local_ledger_code' => $code,
            'local_ledger_title' => 'Phiếu bán hàng ' . $code . ' (kế toán lập)',
            'local_ledger_note' => (string) ($doc['note'] ?? ''),
            'local_ledger_discount' => floor($discount),
            'local_ledger_total_amount' => $total,
            'local_ledger_source' => 1,
            'local_ledger_person_meta' => [
                'source' => 1, 'source_id' => (string) ($partner['code'] ?? ''), 'name' => (string) ($partner['name'] ?? '') ?: 'Khách lẻ',
                'phone' => self::phone_of((string) ($partner['code'] ?? '')), 'address' => '', 'email' => '', 'extra' => array_filter(['tax_code' => (string) ($partner['taxCode'] ?? '')]),
            ],
            'local_ledger_software_source' => ['ketoan'],
            'local_ledger_advance_meta' => $advance,
            'item_ids_from' => 'export',
            'meta' => $sale_meta,
        ];
        foreach ($receipts as $i => $r) {
            $rc = self::receipt_code($id, $i);
            $ledger[] = $common + [
                'role' => 'receipt_' . ($i + 1),
                'local_ledger_type' => 7,
                'local_ledger_code' => $rc,
                'local_ledger_title' => 'Phiếu thu ' . $rc . ' (Thu tiền từ ' . $code . ')',
                'local_ledger_note' => $r['placeholder'] ? 'Phiếu thu mặc định' : '',
                'local_ledger_total_amount' => $r['amount'],
                'parent_role' => 'sale',
                'item_ids_from' => 'export',
                'meta' => array_filter(['receipt_method' => $r['receipt_method'], 'receipt_method_source' => $r['method'], 'sale_code' => $code, 'payment_method_label' => $r['label']],
                    static function ($v) { return $v !== ''; }),
            ];
        }
        $px = self::export_code($id, (string) ($doc['docDate'] ?? ''), $blog_id);
        $ledger[] = $common + [
            'role' => 'export',
            'local_ledger_type' => 2,
            'local_ledger_code' => $px,
            'local_ledger_title' => 'Phiếu xuất hàng ' . $px . ' (từ bán hàng ' . $code . ')',
            'local_ledger_note' => 'Phiếu xuất tự động từ phiếu bán hàng ' . $code . ' (kế toán lập)',
            'local_ledger_discount' => floor($discount),
            'local_ledger_total_amount' => $total,
            'parent_role' => 'sale',
        ];
        $items = [];
        foreach ($lines as $l) $items[] = self::item($l, 'export', $catalog[(string) $l['sku']], $user_id);

        $phone = self::phone_of((string) ($partner['code'] ?? ''));
        $person = $phone !== ''
            ? ['local_ledger_person_phone' => $phone, 'local_ledger_person_code' => (string) $partner['code'], 'local_ledger_person_name' => (string) ($partner['name'] ?? ''),
               'local_ledger_person_type' => 2, 'local_ledger_person_status' => 1, 'user_id' => $user_id, 'is_deleted' => 0]
            : ['guest' => true, 'user_id' => $user_id];

        return [
            'blog_id' => $blog_id,
            'entity' => 'manual_sale',
            'id' => $id,
            'version' => max(1, (int) ($ctx['version'] ?? 1)),
            'deleted' => false,
            'rows' => ['ledger' => $ledger, 'items' => $items, 'person' => $person],
        ];
    }

    /**
     * Phiếu trả kế toán lập → thân bản sao: phiếu hoàn loại 11 gắn phiếu bán gốc (ctx.parent_ledger_id, ctx.parent_code) + phiếu chi
     * loại 8 theo từng khoản trả tiền khách (không trả → không phiếu chi, như POS cũ mặc định).
     * @param array $doc TradeDoc sale_return (data.doc của phieu-ban.doc.recorded)
     * @param array $ctx blog_id, user_id, doc_id, version, parent_ledger_id, parent_code; tuỳ chọn entity (mặc định manual_return),
     *                   doc_key, via (nguồn ghi ở meta), exchange (đổi hàng: tiền hoàn đối trừ đơn mới, không phiếu chi)
     */
    public static function return_to_mirror(array $doc, array $ctx)
    {
        $id = (string) ($ctx['doc_id'] ?? '');
        $code = (string) ($doc['docNo'] ?? '');
        $parent = (int) ($ctx['parent_ledger_id'] ?? 0);
        $sale_code = (string) ($ctx['parent_code'] ?? ($doc['originalDocNo'] ?? ''));
        $lines = array_values(array_filter((array) ($doc['lines'] ?? []), 'is_array'));
        if ($id === '' || $code === '' || !$lines || $parent <= 0) {
            return new WP_Error('tgs_map_invalid', 'Phiếu trả thiếu id / số phiếu / dòng hàng / phiếu bán gốc', ['status' => 422]);
        }
        $user_id = (int) ($ctx['user_id'] ?? 0);
        $now = current_time('mysql');
        $catalog = self::catalog(array_map(static function ($l) { return (string) ($l['sku'] ?? ''); }, $lines));
        $missing = array_values(array_unique(array_filter(array_map(static function ($l) use ($catalog) { $s = (string) ($l['sku'] ?? ''); return isset($catalog[$s]) ? '' : $s; }, $lines))));
        if ($missing) {
            return new WP_Error('tgs_map_unknown_sku', 'Mã hàng không có trong danh mục website: ' . implode(', ', $missing), ['status' => 422, 'skus' => $missing]);
        }
        $total = round((float) ($doc['totals']['total'] ?? 0));
        $discount = 0.0;
        foreach ($lines as $l) $discount += (float) ($l['discount'] ?? 0);
        $reason = (string) ($doc['note'] ?? '') ?: 'Khách trả hàng (kế toán lập)';
        $partner = (array) ($doc['partner'] ?? []);
        $common = [
            'local_ledger_status' => 4,
            'local_ledger_approver_status' => 1,
            'local_ledger_approver_id' => $user_id,
            'user_id' => $user_id,
            'created_at' => self::local_date((string) ($doc['docDate'] ?? ''), $now),
        ];
        $paid = array_values(array_filter(self::receipts($doc), static function ($r) { return !$r['placeholder']; }));
        $ledger = [];
        $ledger[] = $common + [
            'role' => 'return',
            'local_ledger_type' => 11,
            'local_ledger_code' => $code,
            'local_ledger_title' => 'Phiếu hoàn hàng ' . $code . ' (từ đơn bán ' . $sale_code . ', kế toán lập)',
            'local_ledger_note' => $reason,
            'local_ledger_discount' => floor($discount),
            'local_ledger_total_amount' => $total,
            'local_ledger_source' => 1,
            'parent_ledger_id' => $parent,
            'local_ledger_person_meta' => [
                'source' => 1, 'source_id' => (string) ($partner['code'] ?? ''), 'name' => (string) ($partner['name'] ?? '') ?: 'Khách lẻ',
                'phone' => self::phone_of((string) ($partner['code'] ?? '')), 'address' => '', 'email' => '', 'extra' => array_filter(['tax_code' => (string) ($partner['taxCode'] ?? '')]),
            ],
            'local_ledger_software_source' => ['ketoan'],
            'meta' => [
                'source_sale_ledger_id' => $parent, 'source_sale_code' => $sale_code, 'return_reason' => $reason, 'created_via' => (string) ($ctx['via'] ?? 'ketoan'),
                'ketoan_doc_key' => (string) ($ctx['doc_key'] ?? 'phieu-ban:' . $id), 'refund_status' => $paid ? 'paid' : ($ctx['exchange'] ?? false ? 'exchange' : 'owed'),
            ],
        ];
        foreach ($paid as $i => $r) {
            $pc = 'PC' . substr(self::receipt_code($id, 'pc' . $i), 3);
            $ledger[] = $common + [
                'role' => 'payment_' . ($i + 1),
                'local_ledger_type' => 8,
                'local_ledger_code' => $pc,
                'local_ledger_title' => 'Phiếu chi ' . $pc . ' (Hoàn tiền từ ' . $code . ')',
                'local_ledger_note' => 'Hoàn tiền đơn ' . $sale_code . ' — ' . $reason,
                'local_ledger_total_amount' => $r['amount'],
                'parent_role' => 'return',
                'item_ids_from' => 'return',
                'meta' => ['receipt_method' => $r['receipt_method'], 'return_code' => $code, 'source_sale_code' => $sale_code, 'source_sale_ledger_id' => $parent, 'created_via' => (string) ($ctx['via'] ?? 'ketoan')],
            ];
        }
        $items = [];
        foreach ($lines as $l) $items[] = self::item($l, 'return', $catalog[(string) $l['sku']], $user_id, '3');

        $phone = self::phone_of((string) ($partner['code'] ?? ''));
        $person = $phone !== ''
            ? ['local_ledger_person_phone' => $phone, 'local_ledger_person_code' => (string) $partner['code'], 'local_ledger_person_name' => (string) ($partner['name'] ?? ''),
               'local_ledger_person_type' => 2, 'local_ledger_person_status' => 1, 'user_id' => $user_id, 'is_deleted' => 0]
            : ['guest' => true, 'user_id' => $user_id];
        return [
            'blog_id' => (int) ($ctx['blog_id'] ?? get_current_blog_id()),
            'entity' => (string) ($ctx['entity'] ?? 'manual_return'),
            'id' => $id,
            'version' => max(1, (int) ($ctx['version'] ?? 1)),
            'deleted' => false,
            'rows' => ['ledger' => $ledger, 'items' => $items, 'person' => $person],
        ];
    }

    /** Một dòng hàng: giá chưa thuế, chưa giảm (đơn giá kế toán); chiết khấu, thuế theo dòng; quà = giảm 100 %. Loại dòng: 2 xuất, 3 hoàn. */
    private static function item(array $l, $role, $cat, $user_id, $item_type = '2')
    {
        $qty = (float) ($l['qty'] ?? 0);
        // SL theo đơn vị nhỏ nhất; ĐVT bán + tỉ lệ (unitRatio, máy bán React bán theo lốc / thùng) giữ ở cột ĐVT như dòng gốc.
        $ratio = max(1.0, (float) ($l['unitRatio'] ?? 1));
        $gift = !empty($l['isGift']);
        $price = round((float) ($l['unitPrice'] ?? 0), 4);
        $before = (float) ($l['amountBeforeTax'] ?? 0);
        $discount = $gift ? round($price * $qty, 2) : round((float) ($l['discount'] ?? 0), 2);
        $tax = self::tax_percent((string) ($l['taxCode'] ?? ''));
        $kct = strtoupper((string) ($l['taxCode'] ?? '')) === 'KCT' ? 1 : (int) ($cat->global_product_is_kct ?? 0);
        $meta = [
            'sku' => (string) $l['sku'], 'is_gift' => $gift, 'unit' => (string) ($l['unit'] ?? ''), 'unit_price_effective' => $price,
            'subtotal_no_vat' => round($price * $qty, 2), 'discount_type' => $gift ? 'percent' : 'vnd_line', 'discount_value' => $gift ? 100 : $discount,
            'discount_amount' => $discount, 'tax_percent' => $tax, 'is_kct' => $kct, 'tax_amount' => (float) ($l['taxAmount'] ?? 0),
            'unit_quantity' => $qty / $ratio, 'unit_ratio' => $ratio, 'unit_name' => (string) ($l['unit'] ?? ''), 'gift_scope' => $gift ? 'order' : '', 'source' => 'ketoan',
        ];
        return [
            'ledger_role' => $role,
            'local_product_name_id' => (int) $cat->global_product_name_id,
            'global_product_name_id' => (int) $cat->global_product_name_id,
            'local_product_sku' => (string) $l['sku'],
            'quantity' => $qty,
            'price' => $price,
            'local_ledger_item_unit_quantity' => $qty / $ratio,
            'local_ledger_item_unit_ratio' => $ratio,
            'local_ledger_item_unit_name' => (string) ($l['unit'] ?? ''),
            'local_ledger_item_discount_amount' => $discount,
            'local_ledger_item_tax_percent' => $tax,
            'local_ledger_item_is_kct' => $kct,
            'local_ledger_item_tax_amount' => (float) ($l['taxAmount'] ?? 0),
            'local_ledger_item_price_after_discount' => $qty > 0 ? round($before / $qty, 4) : 0.0,
            'local_ledger_item_is_under24_promo_danger' => 0,
            'local_ledger_item_note' => '',
            'local_ledger_item_meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'local_ledger_item_type' => (string) $item_type,
            'local_ledger_item_gift_type' => $gift ? 1 : 0,
            'is_tracking' => (int) ($cat->global_product_is_tracking ?? 1),
            'user_id' => $user_id,
            'is_deleted' => 0,
        ];
    }

    /** Phiếu thu theo hình thức; không thu (ghi nợ) → một phiếu thu 0đ "mặc định" như POS cũ. */
    private static function receipts(array $doc)
    {
        $out = [];
        foreach ((array) ($doc['payments'] ?? []) as $p) {
            $amount = (float) ($p['amount'] ?? 0);
            if ($amount <= 0) continue;
            $m = self::METHODS[(string) ($p['methodCode'] ?? '')] ?? (string) ($p['methodCode'] ?? 'cash');
            $out[] = ['method' => $m, 'amount' => $amount, 'receipt_method' => self::receipt_method($m), 'label' => self::METHOD_NAMES[$m] ?? (string) ($p['methodCode'] ?? ''), 'placeholder' => false];
        }
        return $out ?: [['method' => 'cash', 'amount' => 0.0, 'receipt_method' => 'cash', 'label' => 'Tiền mặt', 'placeholder' => true]];
    }

    private static function receipt_method($m)
    {
        if (in_array($m, ['qr', 'transfer', 'bank_transfer'], true)) return 'bank_transfer';
        if ($m === 'card') return 'card';
        if (strpos($m, 'voucher') === 0) return 'voucher';
        return 'cash';
    }

    /** Thuế suất từ mã thuế đầu ra (R08 → 8, R10 → 10, KCT / R00 → 0). */
    public static function tax_percent($code)
    {
        return preg_match('/(\d+)$/', (string) $code, $m) ? (float) $m[1] : 0.0;
    }

    /** Mã khách là số điện thoại (9–11 số) → SĐT; khác → '' (khách lẻ ở website, tên giữ ở person_meta). */
    public static function phone_of($code)
    {
        $d = preg_replace('/\D+/', '', (string) $code);
        return ($d === (string) $code || $d === ltrim((string) $code, '+')) && strlen($d) >= 9 && strlen($d) <= 11 ? $d : '';
    }

    private static function catalog(array $skus)
    {
        global $wpdb;
        $skus = array_values(array_unique(array_filter($skus)));
        if (!$skus) return [];
        $t = defined('TGS_TABLE_GLOBAL_PRODUCT_NAME') ? TGS_TABLE_GLOBAL_PRODUCT_NAME : $wpdb->base_prefix . 'global_product_name';
        $ph = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT global_product_name_id, global_product_sku, global_product_is_tracking, global_product_is_kct FROM {$t} WHERE global_product_sku IN ({$ph})", $skus));
        $out = [];
        foreach ((array) $rows as $r) $out[(string) $r->global_product_sku] = $r;
        return $out;
    }

    /** Mã phiếu thu cố định theo id phiếu (BTT + 8 số chèn một chữ — 12 ký tự như POS cũ). */
    public static function receipt_code($doc_id, $i)
    {
        $letters = 'ADEFGHJKLMNPQRSUVWXY';
        $h = sprintf('%u', crc32($doc_id . '#' . $i));
        $digits = str_pad(substr($h, -8), 8, '0', STR_PAD_LEFT);
        $letter = $letters[intval($h) % strlen($letters)];
        $pos = intval(substr($h, 0, 1)) % 8;
        return 'BTT' . substr($digits, 0, $pos) . $letter . substr($digits, $pos);
    }

    /** PX{yymmdd}_{site}_{seq4}: gửi lại giữ mã đã cấp (bảng trạng thái bản sao), lần đầu lấy số kế tiếp trong ngày. */
    private static function export_code($doc_id, $doc_date, $blog_id)
    {
        global $wpdb;
        $state = $wpdb->prefix . 'tgs_pos_mirror';
        $ledger_t = $wpdb->prefix . 'local_ledger';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $state)) === $state) {
            $known = $wpdb->get_var($wpdb->prepare("SELECT l.local_ledger_code FROM {$state} s JOIN {$ledger_t} l ON l.local_ledger_id = s.ledger_id WHERE s.pg_id = %s AND s.role = 'export'", $doc_id));
            if ($known) return (string) $known;
        }
        $ymd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $doc_date) ? substr(str_replace('-', '', $doc_date), 2) : current_time('ymd');
        $prefix = 'PX' . $ymd . '_' . $blog_id . '_';
        $last = (string) $wpdb->get_var($wpdb->prepare("SELECT MAX(local_ledger_code) FROM {$ledger_t} WHERE local_ledger_type = 2 AND local_ledger_code LIKE %s", $wpdb->esc_like($prefix) . '%'));
        $seq = $last !== '' ? intval(substr($last, strlen($prefix))) : 0;
        return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Ngày chứng từ kế toán → thời điểm ghi ở website (ngày đó, giờ hiện tại; ngày sai khuôn → bây giờ). */
    private static function local_date($date, $fallback)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . substr($fallback, 10) : $fallback;
    }
}
