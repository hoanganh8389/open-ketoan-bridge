<?php
/**
 * Đọc dữ liệu của MỘT shop (chạy khi WordPress đã khởi động ngay trong shop đó).
 *
 * - Dòng VAT: lấy qua TGS_BCTK_Ajax::vat_rows_for_export() của plugin tgs-bc-tk — cùng
 *   luật tiền với màn Quản lý VAT (khớp hoá đơn eVAT đã phát hành), gồm bill Z.
 * - Phiếu thu (7, cha = phiếu bán 10) và phiếu chi trả khách (8, cha = phiếu hoàn 11): mỗi phiếu là một chứng từ thanh
 *   toán RIÊNG bên kế toán, ghi sổ theo ngày của chính phiếu (như phiếu thanh toán khách hàng tách khỏi hoá đơn).
 * - Phiếu bán / hoàn bị SỬA trong khoảng ngày dù thuộc ngày trước (updated_at) → dựng lại để gửi phiên bản mới.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Source
{
    const TYPE_RECEIPT = 7;
    const TYPE_PAYMENT = 8;

    public static function ready()
    {
        return class_exists('TGS_BCTK_Ajax') && method_exists('TGS_BCTK_Ajax', 'vat_rows_for_export');
    }

    /**
     * Phiếu xuất bán (cả bill Z, cả phiếu chưa phát hành hoá đơn). Kèm export_reason = mã Lý do xuất chọn ở POS
     * (local_ledger_advance_meta → export_reason.code; dòng VAT luôn ghi cứng XBA nên phải đọc riêng ở đây).
     */
    public static function sale_rows($blog_id, $from, $to, array $ids = [])
    {
        // $ids: chỉ đúng các phiếu này (gửi tức thời theo sự kiện) — lọc ngay trong truy vấn, không dựng cả ngày.
        $rows = self::only_approved($blog_id, TGS_BCTK_Ajax::vat_rows_for_export($blog_id, $from, $to, 'sale', 'all', $ids), 'sale_id');
        $reasons = self::export_reasons($blog_id, array_column($rows, 'sale_id'));
        foreach ($rows as &$r) {
            $r['export_reason'] = $reasons[(int) $r['sale_id']] ?? '';
        }
        unset($r);
        return $rows;
    }

    /** Mã Lý do xuất của các phiếu bán: [local_ledger_id => mã], phiếu không ghi lý do thì không có trong mảng. */
    public static function export_reasons($blog_id, array $ids)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $out = [];
        foreach ((array) $wpdb->get_results(
            "SELECT local_ledger_id AS id, JSON_UNQUOTE(JSON_EXTRACT(local_ledger_advance_meta, '$.export_reason.code')) AS code
               FROM {$prefix}local_ledger WHERE local_ledger_id IN (" . implode(',', $ids) . ") AND JSON_VALID(local_ledger_advance_meta)",
            ARRAY_A
        ) as $x) {
            $code = trim((string) $x['code']);
            if ($code !== '' && strtolower($code) !== 'null') {
                $out[(int) $x['id']] = $code;
            }
        }
        return $out;
    }

    /**
     * MỌI phiếu hoàn hàng theo ngày phiếu hoàn (kể cả đơn chưa phát hành hoá đơn).
     * Có hoá đơn điều chỉnh thì kèm số hoá đơn. Tiền mang dấu âm.
     */
    public static function return_rows($blog_id, $from, $to)
    {
        $rows = (array) TGS_BCTK_Ajax::vat_rows_for_export($blog_id, $from, $to, 'return');
        foreach ($rows as &$r) {
            $r['_ledger_id'] = (int) ($r['return_id'] ?? 0) ?: (int) ($r['sale_id'] ?? 0);
        }
        unset($r);
        return self::only_approved($blog_id, $rows, '_ledger_id');
    }

    /**
     * Chỉ phiếu ĐÃ DUYỆT (local_ledger_approver_status = 1) mới vào cổng chờ kế toán — áp cho mọi loại phiếu gửi đi
     * (bán, hoàn, thu, chi, nhập mua, trả NCC…). Phiếu chưa duyệt / bị bỏ duyệt không gửi; đã gửi rồi mà bị bỏ duyệt thì
     * báo huỷ như phiếu bị xoá (deleted_keys).
     */
    /**
     * [2026-11-12 10:30 AM Võ Hoàng] POS-MIRROR — phiếu do BẢN SAO kế toán ghi (máy bán POS React qua module pos: TGS_POS_Ledger_Mirror ghi
     * local_ledger_source_id = 'pg:<id>'; đơn bán dự phòng cũng ghi cùng khoá) có gốc ở phần mềm kế toán → KHÔNG gửi lại
     * (gửi lại = doanh thu, thuế, tiền thu gấp đôi: chứng từ pos:… và btsoft:… khác khoá nên kế toán không chặn trùng).
     * Trả điều kiện SQL "AND …" cho bí danh $alias (rỗng = không bí danh); bảng chưa có cột local_ledger_source_id thì ''.
     */
    public static function not_mirror_sql($table, $alias = '')
    {
        static $has = [];
        if (!isset($has[$table])) {
            global $wpdb;
            $prev = $wpdb->suppress_errors(true);
            $has[$table] = (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'local_ledger_source_id'));
            $wpdb->suppress_errors($prev);
        }
        if (!$has[$table]) {
            return '';
        }
        $c = ($alias !== '' ? $alias . '.' : '') . 'local_ledger_source_id';
        // Không dùng LIKE 'pg:%': chuỗi này còn đi qua $wpdb->prepare (dấu % là chỗ giữ tham số).
        return " AND ({$c} IS NULL OR LEFT({$c}, 3) <> 'pg:')";
    }

    public static function only_approved($blog_id, $rows, $id_field)
    {
        global $wpdb;
        $rows = (array) $rows;
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, $id_field)))));
        if (!$ids) {
            return [];
        }
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $ok = array_flip(array_map('intval', (array) $wpdb->get_col(
            "SELECT local_ledger_id FROM {$prefix}local_ledger WHERE local_ledger_approver_status = 1 AND local_ledger_id IN (" . implode(',', $ids) . ")"
                . self::not_mirror_sql($prefix . 'local_ledger')
        )));
        return array_values(array_filter($rows, function ($r) use ($ok, $id_field) {
            return isset($ok[(int) ($r[$id_field] ?? 0)]);
        }));
    }

    /**
     * Mã chi nhánh (danh mục Chi nhánh) website $blog_id đang nối ở Cấu hình phân cấp multisite: sites[blog].htsoft_srid →
     * bảng chi nhánh dùng chung (TEN). '' = chưa nối. Đọc một lần mỗi request.
     */
    public static function branch_of_blog($blog_id)
    {
        static $map = null;
        if ($map === null) {
            global $wpdb;
            $map = [];
            $raw = get_site_option(defined('TGS_HIERARCHY_OPTION_KEY') ? TGS_HIERARCHY_OPTION_KEY : 'tgs_multisite_hierarchy_data', '');
            $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            $srids = [];
            foreach ((array) ($data['sites'] ?? []) as $bid => $s) {
                $srid = trim((string) ($s['htsoft_srid'] ?? ''));
                if ($srid !== '') {
                    $srids[(int) $bid] = $srid;
                }
            }
            $T = $wpdb->base_prefix . 'htsoft_chinhanh';
            if ($srids && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $T)) === $T) {
                $ten = [];
                foreach ((array) $wpdb->get_results("SELECT SRID, TEN FROM `$T`", ARRAY_A) as $r) {
                    $ten[(string) $r['SRID']] = trim((string) $r['TEN']);
                }
                foreach ($srids as $bid => $srid) {
                    if (($ten[$srid] ?? '') !== '') {
                        $map[$bid] = $ten[$srid];
                    }
                }
            }
        }
        return $map[(int) $blog_id] ?? '';
    }

    /** Mã shop (mã site) bên kế toán: lấy theo cấu hình phân cấp của tgs-bc-tk, không có thì tên site. */
    public static function shop_code($blog_id)
    {
        // Chuẩn: chi nhánh website đang nối ở Cấu hình phân cấp multisite (cùng cầu nối phần mềm kế toán hiện ở danh mục
        // Chi nhánh) — chứng từ vào đúng chi nhánh, đúng bộ phận. Chưa nối mới lấy mã site (tgs_site_code — nay là mã kho).
        $mapped = self::branch_of_blog((int) $blog_id);
        if ($mapped !== '') {
            return $mapped;
        }
        if (class_exists('TGS_BCTK_Sites')) {
            foreach (TGS_BCTK_Sites::list_sites() as $s) {
                if ((int) $s['blog_id'] === (int) $blog_id) {
                    return (string) ($s['code'] !== '' ? $s['code'] : $s['name']);
                }
            }
        }
        return '';
    }

    /**
     * Phiếu thu của đơn bán (loại 7, cha loại 10) và phiếu chi trả tiền khách của phiếu hoàn (loại 8, cha loại 11):
     * ngày của phiếu (ticket_date, không có thì ngày tạo) nằm trong khoảng, HOẶC phiếu được sửa trong khoảng.
     * Phiếu đã xoá không lấy (phiếu đã gửi mà nay không còn → báo huỷ bên kế toán).
     */
    public static function payment_rows($blog_id, $from, $to)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $M = $prefix . 'local_ledger_meta';
        $P = $prefix . 'local_ledger_person';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.local_ledger_id AS id, c.local_ledger_type AS type, c.local_ledger_parent_id AS parent_id,
                    c.local_ledger_code AS code, c.local_ledger_total_amount AS amount, c.user_id,
                    COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) AS day, c.created_at,
                    m.local_ledger_meta_value AS meta, per.local_ledger_person_phone AS phone, per.local_ledger_person_name AS person_name
               FROM {$L} c
               JOIN {$L} pa ON pa.local_ledger_id = c.local_ledger_parent_id
                    AND pa.local_ledger_type = (CASE c.local_ledger_type WHEN 7 THEN 10 ELSE 11 END)
                    AND (pa.is_deleted = 0 OR pa.is_deleted IS NULL) AND pa.local_ledger_approver_status = 1
               LEFT JOIN {$M} m ON m.local_ledger_meta_id = c.local_ledger_meta_id
               LEFT JOIN {$P} per ON per.local_ledger_person_id = c.local_ledger_person_id
              WHERE c.local_ledger_type IN (7, 8) AND (c.is_deleted = 0 OR c.is_deleted IS NULL) AND c.local_ledger_approver_status = 1{$nm}
                AND (COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) BETWEEN %s AND %s
                     OR c.updated_at BETWEEN %s AND %s)
              ORDER BY c.local_ledger_id ASC",
            $from, $to, $from . ' 00:00:00', $to . ' 23:59:59'
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $method = strtolower(trim((string) ($meta['receipt_method'] ?? $meta['payment_method'] ?? 'cash')));
            // Phiếu thu KỸ THUẬT của đơn đổi trả (đối trừ với phiếu hoàn, không phải tiền thật): không thành chứng từ thu
            // tiền — phần đối trừ đi theo phiếu bán (offsets, xem offsets_by_sale).
            if ($method === 'doi_tru' || !empty($meta['offset_netting'])) {
                continue;
            }
            $row['method'] = $method !== '' ? $method : 'cash';
            $row['method_label'] = (string) ($meta['payment_method_label'] ?? '');
            $row['split_parent'] = (int) ($meta['split_multi_parent_receipt_id'] ?? 0);
            unset($row['meta']);
            $out[] = $row;
        }
        // Phiếu tách "Đa HTTT" từ một phiếu thu: tiền thu vào ngày của phiếu gốc, không phải ngày bấm tách.
        $parents = array_values(array_unique(array_filter(array_column($out, 'split_parent'))));
        if ($parents) {
            $days = [];
            foreach ((array) $wpdb->get_results(
                "SELECT local_ledger_id AS id, COALESCE(local_ledger_ticket_date, DATE(created_at)) AS day FROM {$L} WHERE local_ledger_id IN (" . implode(',', array_map('intval', $parents)) . ")",
                ARRAY_A
            ) as $p) {
                $days[(int) $p['id']] = $p['day'];
            }
            foreach ($out as &$row) {
                if ($row['split_parent'] && isset($days[$row['split_parent']])) {
                    $row['day'] = $days[$row['split_parent']];
                }
            }
            unset($row);
        }
        return $out;
    }

    /**
     * Phiếu nhập kho mua của nhà cung cấp (loại 1, KHÔNG có phiếu cha — tạo ở màn "Tạo phiếu nhập kho") và phiếu trả hàng
     * NCC (loại 16): đã duyệt, chưa xoá, ngày phiếu trong khoảng HOẶC phiếu được sửa trong khoảng. Loại 1 có phiếu cha
     * (nhập ngầm của phiếu mua, nhập chuyển kho…) và phiếu nhập giả lập tồn kho (DCTEST…) không lấy.
     * Mỗi phiếu: dòng của bảng local_ledger + 'supplier' (nhà cung cấp) + 'adv' (local_ledger_advance_meta) + 'items'.
     */
    public static function supplier_doc_rows($blog_id, $from, $to, $type)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $type = (int) $type === 16 ? 16 : 1;
        // Mẫu LIKE truyền qua tham số: chuỗi '%i…' viết thẳng trong câu bị prepare() hiểu là chỗ điền tên bảng.
        $only = $type === 1
            ? $wpdb->prepare(
                "AND (c.local_ledger_parent_id IS NULL OR c.local_ledger_parent_id = 0)
                 AND (c.local_ledger_title LIKE %s OR c.local_ledger_advance_meta LIKE %s) AND c.local_ledger_code NOT LIKE %s",
                'Thông tin phiếu nhập kho%', '%' . $wpdb->esc_like('import_reason') . '%', 'DCTEST%'
            )
            : '';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.local_ledger_id AS id, c.local_ledger_type AS type, c.local_ledger_parent_id AS parent_id, c.local_ledger_code AS code,
                    c.local_ledger_code_source AS invoice_no, c.local_ledger_payment_due_date AS due_date, c.local_ledger_note AS note,
                    c.local_ledger_advance_meta AS adv, c.supplier_id, c.user_id,
                    COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) AS day, p.local_ledger_type AS parent_type
               FROM {$L} c
               LEFT JOIN {$L} p ON p.local_ledger_id = c.local_ledger_parent_id
              WHERE c.local_ledger_type = %d AND (c.is_deleted = 0 OR c.is_deleted IS NULL) AND c.local_ledger_approver_status = 1{$nm}
                AND (COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) BETWEEN %s AND %s OR c.updated_at BETWEEN %s AND %s)",
            $type, $from, $to, $from . ' 00:00:00', $to . ' 23:59:59'
        ) . ' ' . $only . ' ORDER BY c.local_ledger_id ASC', ARRAY_A);
        if (!$rows) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $items = self::supplier_doc_items($blog_id, $ids);
        $sup_ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'supplier_id')))));
        $suppliers = [];
        if ($sup_ids) {
            foreach ((array) $wpdb->get_results(
                "SELECT supplier_id, supplier_code, supplier_name, supplier_tax_code, supplier_address, supplier_phone
                   FROM {$wpdb->base_prefix}global_supplier WHERE supplier_id IN (" . implode(',', $sup_ids) . ")",
                ARRAY_A
            ) as $s) {
                $suppliers[(int) $s['supplier_id']] = $s;
            }
        }
        foreach ($rows as &$r) {
            $adv = json_decode((string) ($r['adv'] ?? ''), true);
            $r['adv'] = is_array($adv) ? $adv : [];
            $r['supplier'] = $suppliers[(int) $r['supplier_id']] ?? null;
            $r['items'] = $items[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    /**
     * Phiếu XUẤT ĐIỀU CHỈNH (xuất dùng nội bộ, tặng, dùng thử, huỷ hỏng… — bill giấy, không có hoá đơn điện tử):
     * local_ledger loại 2 (phiếu xuất) KHÔNG có phiếu cha, đã duyệt, chưa xoá; ngày phiếu trong khoảng HOẶC sửa trong khoảng.
     * Hai màn tạo ra loại phiếu này:
     *   - Quản lý shop → "Tạo phiếu xuất" (mã XSD-…): lý do ở advance_meta.import_reason.code (cùng khoá với phiếu nhập);
     *   - POS → "Phiếu xuất điều chỉnh": lý do ở ghi chú đề nghị wp_global_po_adjustment
     *     ("[TGS_POS_ADJUSTMENT_EXPORT_REASON:XSD1]…"), po_code = mã phiếu xuất.
     * Phiếu xuất ngầm của đơn bán / chuyển kho luôn có phiếu cha nên không lọt vào đây.
     */
    public static function export_adjust_rows($blog_id, $from, $to)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $P = $prefix . 'local_ledger_person';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.local_ledger_id AS id, c.local_ledger_code AS code, c.local_ledger_note AS note, c.local_ledger_advance_meta AS adv,
                    c.user_id, COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) AS day,
                    per.local_ledger_person_phone AS phone, per.local_ledger_person_name AS person_name
               FROM {$L} c
               LEFT JOIN {$P} per ON per.local_ledger_person_id = c.local_ledger_person_id
              WHERE c.local_ledger_type = 2 AND (c.local_ledger_parent_id IS NULL OR c.local_ledger_parent_id = 0)
                AND (c.is_deleted = 0 OR c.is_deleted IS NULL) AND c.local_ledger_approver_status = 1{$nm}
                AND (COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) BETWEEN %s AND %s OR c.updated_at BETWEEN %s AND %s)
              ORDER BY c.local_ledger_id ASC",
            $from, $to, $from . ' 00:00:00', $to . ' 23:59:59'
        ), ARRAY_A);
        if (!$rows) {
            return [];
        }
        $items = self::supplier_doc_items($blog_id, array_map('intval', array_column($rows, 'id')));
        // Lý do của phiếu POS: ghi chú đề nghị xuất điều chỉnh (po_code = mã phiếu, cùng shop).
        $pos_reason = [];
        $po = $wpdb->base_prefix . 'global_po_adjustment';
        $codes = array_values(array_filter(array_map('strval', array_column($rows, 'code'))));
        if ($codes && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $po)) === $po) {
            $in = implode(',', array_fill(0, count($codes), '%s'));
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT po_code, note FROM {$po} WHERE request_blog_id = %d AND intent = %s AND po_code IN ({$in})",
                array_merge([(int) $blog_id, 'shop_adjustment_export'], $codes)
            ), ARRAY_A) as $x) {
                if (preg_match('/^\[TGS_POS_ADJUSTMENT_EXPORT_REASON:([A-Za-z0-9_.\-]+)\]/', (string) $x['note'], $m)) {
                    $pos_reason[(string) $x['po_code']] = strtoupper($m[1]);
                }
            }
        }
        foreach ($rows as &$r) {
            $adv = json_decode((string) ($r['adv'] ?? ''), true);
            $adv = is_array($adv) ? $adv : [];
            $r['reason'] = (string) ($adv['import_reason']['code'] ?? $adv['export_reason']['code'] ?? $pos_reason[(string) $r['code']] ?? '');
            $r['items'] = $items[(int) $r['id']] ?? [];
            unset($r['adv']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Dòng hàng của phiếu nhập kho / trả NCC: dòng gắn chính phiếu; phiếu không có dòng (dòng nằm ở phiếu kho con —
     * xuất kho trả NCC) thì lấy dòng của phiếu con loại 1 / 2. [ledger_id => [dòng…]], tên hàng theo danh mục chung.
     */
    private static function supplier_doc_items($blog_id, array $ids)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $I = $prefix . 'local_ledger_item';
        $in = implode(',', array_map('intval', $ids));
        $cols = "i.local_ledger_item_id AS item_id, i.local_product_sku AS sku, g.global_product_name AS name, i.quantity, i.price,
                 i.local_ledger_item_unit_quantity AS unit_qty, i.local_ledger_item_unit_ratio AS unit_ratio, i.local_ledger_item_unit_name AS unit,
                 i.local_ledger_item_discount_amount AS discount, i.local_ledger_item_tax_percent AS tax_percent, i.local_ledger_item_is_kct AS is_kct,
                 i.local_ledger_item_tax_amount AS tax_amount, i.local_ledger_item_gift_type AS gift_type, i.lot_code, i.exp_date, i.local_ledger_item_note AS note";
        $join = "LEFT JOIN {$wpdb->base_prefix}global_product_name g ON g.global_product_name_id = i.global_product_name_id";
        $out = [];
        foreach ((array) $wpdb->get_results(
            "SELECT i.local_ledger_id AS doc_id, {$cols} FROM {$I} i {$join}
              WHERE i.local_ledger_id IN ({$in}) AND (i.is_deleted = 0 OR i.is_deleted IS NULL) ORDER BY i.local_ledger_item_id",
            ARRAY_A
        ) as $it) {
            $out[(int) $it['doc_id']][] = $it;
        }
        $missing = array_values(array_diff(array_map('intval', $ids), array_keys($out)));
        if ($missing) {
            foreach ((array) $wpdb->get_results(
                "SELECT ch.local_ledger_parent_id AS doc_id, {$cols} FROM {$L} ch JOIN {$I} i ON i.local_ledger_id = ch.local_ledger_id {$join}
                  WHERE ch.local_ledger_parent_id IN (" . implode(',', $missing) . ") AND ch.local_ledger_type IN (1, 2)
                    AND (ch.is_deleted = 0 OR ch.is_deleted IS NULL) AND (i.is_deleted = 0 OR i.is_deleted IS NULL)
                  ORDER BY i.local_ledger_item_id",
                ARRAY_A
            ) as $it) {
                $out[(int) $it['doc_id']][] = $it;
            }
        }
        return $out;
    }

    /**
     * Chuyển kho của MỘT cửa hàng từ PHIẾU CHUYỂN KHO GLOBAL (wp_global_transfer — một phiếu hai đầu). Mỗi đầu của shop này
     * một dòng (leg):
     *   'out' — shop là bên đi, phiếu đã duyệt xuất, bên nhận chưa từ chối; ngày = ngày phiếu; phân kho = from_zone.
     *   'in'  — shop là bên đến, đã duyệt nhận; ngày = ngày duyệt nhận; SL = SL thực nhận (nếu có); phân kho = to_zone.
     * Ngày trong khoảng HOẶC phiếu sửa trong khoảng. $only_ids: chỉ các phiếu này (gửi theo sự kiện).
     */
    public static function global_transfer_rows($blog_id, $from, $to, array $only_ids = [])
    {
        if (!class_exists('TGS_Global_Transfer') || !TGS_Global_Transfer::ready()) {
            return [];
        }
        global $wpdb;
        $T = TGS_Global_Transfer::table();
        $I = TGS_Global_Transfer::item_table();
        $b = (int) $blog_id;
        $a = $from . ' 00:00:00';
        $z = $to . ' 23:59:59';
        $only = $only_ids ? ' AND t.transfer_id IN (' . implode(',', array_map('intval', $only_ids) ?: [0]) . ')' : '';
        $rows = array_merge(
            (array) $wpdb->get_results($wpdb->prepare(
                "SELECT t.*, 'out' AS leg, t.doc_date AS day FROM {$T} t
                  WHERE t.from_blog_id = %d AND t.is_deleted = 0 AND t.export_status = 1 AND t.import_status <> 2
                    AND (t.doc_date BETWEEN %s AND %s OR t.updated_at BETWEEN %s AND %s){$only}",
                $b, $from, $to, $a, $z
            ), ARRAY_A),
            (array) $wpdb->get_results($wpdb->prepare(
                "SELECT t.*, 'in' AS leg, DATE(t.import_approved_at) AS day FROM {$T} t
                  WHERE t.to_blog_id = %d AND t.is_deleted = 0 AND t.import_status = 1
                    AND (DATE(t.import_approved_at) BETWEEN %s AND %s OR t.updated_at BETWEEN %s AND %s){$only}",
                $b, $from, $to, $a, $z
            ), ARRAY_A)
        );
        if (!$rows) {
            return [];
        }
        $items = [];
        $ids = implode(',', array_unique(array_map('intval', array_column($rows, 'transfer_id'))));
        foreach ((array) $wpdb->get_results("SELECT * FROM {$I} WHERE is_deleted = 0 AND transfer_id IN ({$ids}) ORDER BY line_no, item_id", ARRAY_A) as $it) {
            $items[(int) $it['transfer_id']][] = $it;
        }
        foreach ($rows as &$r) {
            $r['items'] = $items[(int) $r['transfer_id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    /**
     * Khoá chứng từ chuyển kho global của shop không còn hợp lệ (phiếu xoá, bỏ duyệt, bị từ chối) mà sửa trong khoảng.
     * @return string[]
     */
    public static function global_transfer_gone_keys($blog_id, $from, $to)
    {
        if (!class_exists('TGS_Global_Transfer') || !TGS_Global_Transfer::ready()) {
            return [];
        }
        global $wpdb;
        $T = TGS_Global_Transfer::table();
        $b = (int) $blog_id;
        $out = [];
        foreach ((array) $wpdb->get_col($wpdb->prepare(
            "SELECT transfer_id FROM {$T} WHERE from_blog_id = %d AND (is_deleted = 1 OR export_status <> 1 OR import_status = 2) AND updated_at BETWEEN %s AND %s",
            $b, $from . ' 00:00:00', $to . ' 23:59:59'
        )) as $id) {
            $out[] = 'btsoft:' . $b . ':gt-out:' . (int) $id;
        }
        foreach ((array) $wpdb->get_col($wpdb->prepare(
            "SELECT transfer_id FROM {$T} WHERE to_blog_id = %d AND (is_deleted = 1 OR import_status <> 1) AND updated_at BETWEEN %s AND %s",
            $b, $from . ' 00:00:00', $to . ' 23:59:59'
        )) as $id) {
            $out[] = 'btsoft:' . $b . ':gt-in:' . (int) $id;
        }
        return $out;
    }

    /**
     * Phiếu điều chỉnh tồn kho (loại 21, màn "Phiếu điều chỉnh mới", loại điều chỉnh = Điều chỉnh tồn kho — meta
     * edit_type = 'inventory'): đã duyệt, chưa xoá, ngày phiếu trong khoảng HOẶC sửa trong khoảng. Mỗi dòng lưu CHÊNH
     * LỆCH số lượng có dấu (quantity: + thừa / − thiếu). Điều chỉnh giá bán, công nợ, doanh thu… không động kho → không lấy.
     */
    public static function adjustment_rows($blog_id, $from, $to)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $M = $prefix . 'local_ledger_meta';
        $I = $prefix . 'local_ledger_item';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.local_ledger_id AS id, c.local_ledger_code AS code, c.local_ledger_item_id AS item_ids, c.local_ledger_note AS note,
                    COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) AS day, m.local_ledger_meta_value AS meta
               FROM {$L} c
               LEFT JOIN {$M} m ON m.local_ledger_meta_id = c.local_ledger_meta_id
              WHERE c.local_ledger_type = 21 AND (c.is_deleted = 0 OR c.is_deleted IS NULL) AND c.local_ledger_approver_status = 1{$nm}
                AND (COALESCE(c.local_ledger_ticket_date, DATE(c.created_at)) BETWEEN %s AND %s OR c.updated_at BETWEEN %s AND %s)
              ORDER BY c.local_ledger_id ASC",
            $from, $to, $from . ' 00:00:00', $to . ' 23:59:59'
        ), ARRAY_A);
        $out = [];
        $want = [];
        foreach ($rows as $r) {
            $meta = json_decode((string) ($r['meta'] ?? ''), true);
            if (!is_array($meta) || ($meta['edit_type'] ?? '') !== 'inventory') {
                continue;
            }
            $ids = json_decode((string) $r['item_ids'], true);
            $r['item_ids'] = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
            foreach ($r['item_ids'] as $id) {
                $want[$id] = true;
            }
            unset($r['meta']);
            $out[] = $r;
        }
        $items = [];
        if ($want) {
            foreach ((array) $wpdb->get_results(
                "SELECT i.local_ledger_item_id AS item_id, i.local_ledger_id AS doc_id, i.local_product_sku AS sku, g.global_product_name AS name, i.quantity,
                        i.local_ledger_item_unit_name AS unit, i.local_ledger_item_note AS note
                   FROM {$I} i LEFT JOIN {$wpdb->base_prefix}global_product_name g ON g.global_product_name_id = i.global_product_name_id
                  WHERE i.local_ledger_item_id IN (" . implode(',', array_keys($want)) . ") AND (i.is_deleted = 0 OR i.is_deleted IS NULL)",
                ARRAY_A
            ) as $it) {
                $items[(int) $it['item_id']] = $it;
            }
        }
        foreach ($out as &$r) {
            $r['items'] = [];
            foreach ($r['item_ids'] as $id) {
                if (isset($items[$id])) {
                    $r['items'][] = $items[$id];
                }
            }
        }
        unset($r);
        return $out;
    }

    /**
     * Khoá chứng từ của các phiếu BỊ XOÁ (is_deleted) hoặc BỊ BỎ DUYỆT (approver_status khác 1) trong khoảng thời gian
     * (theo lúc sửa = updated_at, không theo ngày phiếu): phiếu bán, hoàn, thu / chi trả khách, nhập mua, trả NCC — kể cả
     * phiếu thu / chi của phiếu bán / hoàn bị xoá, bỏ duyệt. Chỉ khoá đã từng gửi mới thành báo huỷ (Push_Log::cancel_keys).
     * Để ERP huỷ chứng từ dù phiếu thuộc ngày ngoài khoảng đang kéo.
     */
    public static function deleted_keys($blog_id, $from, $to)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $a = $from . ' 00:00:00';
        $b = $to . ' 23:59:59';
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.local_ledger_id AS id, c.local_ledger_type AS type
               FROM {$L} c
               LEFT JOIN {$L} pa ON pa.local_ledger_id = c.local_ledger_parent_id AND c.local_ledger_type IN (7, 8)
              WHERE c.local_ledger_type IN (1, 2, 7, 8, 10, 11, 16, 21){$nm}
                AND (c.local_ledger_type <> 2 OR c.local_ledger_parent_id IS NULL OR c.local_ledger_parent_id = 0)
                AND (((c.is_deleted = 1 OR NOT (c.local_ledger_approver_status <=> 1)) AND c.updated_at BETWEEN %s AND %s)
                     OR (c.local_ledger_type IN (7, 8) AND (pa.is_deleted = 1 OR NOT (pa.local_ledger_approver_status <=> 1)) AND pa.updated_at BETWEEN %s AND %s))",
            $a, $b, $a, $b
        ), ARRAY_A);
        $names = [1 => 'purchase', 2 => 'export-adjust', 7 => 'receipt', 8 => 'refund', 10 => 'sale', 11 => 'return', 16 => 'supplier-return', 21 => 'adjust'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = 'btsoft:' . (int) $blog_id . ':' . $names[(int) $r['type']] . ':' . (int) $r['id'];
        }
        // Chuyển kho global: đầu xuất / đầu nhập của shop không còn hợp lệ.
        $out = array_merge($out, self::global_transfer_gone_keys($blog_id, $from, $to));
        // Phiếu chuyển tiền (bảng global) của shop lập phiếu, bị xoá trong khoảng.
        $T = self::cash_transfer_table();
        if ($T) {
            foreach ((array) $wpdb->get_col($wpdb->prepare(
                "SELECT ct_id FROM {$T} WHERE issuer_blog_id = %d AND is_deleted = 1 AND updated_at BETWEEN %s AND %s",
                (int) $blog_id, $a, $b
            )) as $id) {
                $out[] = 'btsoft:' . (int) $blog_id . ':cash_transfer:' . (int) $id;
            }
        }
        return $out;
    }

    /** Bảng phiếu chuyển tiền (global, tgs_pos); chưa có bảng thì ''. */
    private static function cash_transfer_table()
    {
        global $wpdb;
        $t = $wpdb->base_prefix . 'global_cash_transfer';
        static $exists = null;
        if ($exists === null) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
        }
        return $exists ? $t : '';
    }

    /**
     * Phiếu chuyển tiền giữa chi nhánh (tgs_pos, bảng global_cash_transfer — màn "Tạo phiếu chuyển tiền") do shop này
     * lập (issuer_blog_id): chưa xoá, ngày chuyển trong khoảng HOẶC sửa / xác nhận trong khoảng. Gửi cả phiếu chờ
     * xác nhận (tiền đã rời nơi chuyển như HTsoft sp_InsertCHUYENTIENSHOWROOM) lẫn đã xác nhận (nơi nhận đã nhận).
     */
    public static function cash_transfer_rows($blog_id, $from, $to)
    {
        global $wpdb;
        $T = self::cash_transfer_table();
        if (!$T) {
            return [];
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ct_id, ct_code, branch_from_code, branch_from_name, branch_to_code, branch_to_name, nh_code_from, nh_code_to,
                    reason_code, recv_reason_code, amount, transfer_date, nv_name, description, status, approved, approved_by, approved_date
               FROM {$T}
              WHERE issuer_blog_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)
                AND (transfer_date BETWEEN %s AND %s OR updated_at BETWEEN %s AND %s)
              ORDER BY ct_id ASC",
            (int) $blog_id, $from, $to, $from . ' 00:00:00', $to . ' 23:59:59'
        ), ARRAY_A);
    }

    /**
     * Ngày của các phiếu bán (10) / hoàn (11) bị SỬA trong khoảng nhưng thuộc ngày TRƯỚC khoảng:
     * ['sale' => [ngày => [id,…]], 'return' => [ngày => [id,…]]] — để dựng lại riêng các phiếu đó.
     */
    public static function changed_before($blog_id, $from, $to)
    {
        global $wpdb;
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT local_ledger_id AS id, local_ledger_type AS type, COALESCE(local_ledger_ticket_date, DATE(created_at)) AS day
               FROM {$L}
              WHERE local_ledger_type IN (10, 11) AND (is_deleted = 0 OR is_deleted IS NULL) AND local_ledger_approver_status = 1{$nm0}
                AND updated_at BETWEEN %s AND %s
                AND COALESCE(local_ledger_ticket_date, DATE(created_at)) < %s",
            $from . ' 00:00:00', $to . ' 23:59:59', $from
        ), ARRAY_A);
        $out = ['sale' => [], 'return' => []];
        foreach ($rows as $r) {
            $out[(int) $r['type'] === 10 ? 'sale' : 'return'][(string) $r['day']][] = (int) $r['id'];
        }
        return $out;
    }

    /**
     * Phần ĐỐI TRỪ của đơn đổi trả: đơn bán (10) ghi trong meta payment_receipts các dòng {offset_netting,
     * offset_return_ledger_id, amount} = tiền hàng trả lại được trừ vào đơn. [sale_id => [[return_id, amount], …]].
     */
    public static function offsets_by_sale(array $sale_ids, $blog_id = 0)
    {
        global $wpdb;
        $sale_ids = array_values(array_filter(array_map('intval', $sale_ids)));
        if (!$sale_ids) {
            return [];
        }
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $M = $prefix . 'local_ledger_meta';
        $in = implode(',', $sale_ids);
        $rows = (array) $wpdb->get_results(
            "SELECT d.local_ledger_id AS id, m.local_ledger_meta_value AS meta
               FROM {$L} d JOIN {$M} m ON m.local_ledger_meta_id = d.local_ledger_meta_id
              WHERE d.local_ledger_id IN ({$in}) AND m.local_ledger_meta_value LIKE '%offset_netting%'",
            ARRAY_A
        );
        $out = [];
        foreach ($rows as $r) {
            $m = json_decode((string) $r['meta'], true);
            foreach ((array) ($m['payment_receipts'] ?? []) as $rc) {
                $rid = (int) ($rc['offset_return_ledger_id'] ?? 0);
                $amt = round(abs((float) ($rc['amount'] ?? 0)));
                if (!empty($rc['offset_netting']) && $rid > 0 && $amt > 0) {
                    $out[(int) $r['id']][] = [$rid, $amt];
                }
            }
        }
        return $out;
    }

    /** Phiếu thu (loại 7) của các đơn bán: [sale_id => [ ['code','method','amount','paid_at'], … ]]. */
    public static function receipts_by_sale(array $sale_ids, $blog_id = 0)
    {
        return self::children_by_parent($sale_ids, self::TYPE_RECEIPT, $blog_id);
    }

    /** Phiếu chi trả tiền khách (loại 8) của các phiếu hoàn: [return_id => [ … ]]. */
    public static function refunds_by_return(array $return_ids, $blog_id = 0)
    {
        return self::children_by_parent($return_ids, self::TYPE_PAYMENT, $blog_id);
    }

    /**
     * Phiếu tiền con của một loạt phiếu cha — một truy vấn cho cả lô.
     * Tên bảng theo tiền tố của CHÍNH shop ($blog_id) nên gọi được từ site tổng (nút đẩy ở màn VAT).
     */
    private static function children_by_parent(array $sale_ids, $type, $blog_id = 0)
    {
        global $wpdb;
        $sale_ids = array_values(array_filter(array_map('intval', $sale_ids)));
        if (!$sale_ids) {
            return [];
        }
        $prefix = (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
        $L = $prefix . 'local_ledger';
        $nm = self::not_mirror_sql($L, 'c');
        $nm0 = self::not_mirror_sql($L);
        $M = $prefix . 'local_ledger_meta';
        $in = implode(',', $sale_ids);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT r.local_ledger_parent_id AS sale_id, r.local_ledger_code AS code,
                    r.local_ledger_total_amount AS amount, r.created_at, m.local_ledger_meta_value AS meta
               FROM {$L} r
               LEFT JOIN {$M} m ON m.local_ledger_meta_id = r.local_ledger_meta_id
              WHERE r.local_ledger_parent_id IN ({$in}) AND r.local_ledger_type = %d
                AND (r.is_deleted = 0 OR r.is_deleted IS NULL)
              ORDER BY r.local_ledger_id ASC",
            (int) $type
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $method = strtolower(trim((string) ($meta['receipt_method'] ?? $meta['payment_method'] ?? 'cash')));
            $out[(int) $row['sale_id']][] = [
                'code'    => (string) $row['code'],
                'method'  => $method !== '' ? $method : 'cash',
                'label'   => (string) ($meta['payment_method_label'] ?? ''),
                'amount'  => round((float) $row['amount']),
                'paid_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }
}
