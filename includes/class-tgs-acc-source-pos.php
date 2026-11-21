<?php
/**
 * NGUỒN THỨ HAI cho phiếu bán / phiếu hoàn: đọc THẲNG sổ POS của một shop ({prefix}local_ledger*), dùng khi không có
 * plugin tgs-bc-tk (TGS_BCTK_Ajax::vat_rows_for_export). Trả ĐÚNG khuôn dòng VAT mà TGS_Acc_Mapper::sale / sale_return
 * đang nhận, nên mapper, pusher, nhật ký gửi dùng lại nguyên.
 *
 * - CHỈ ĐỌC. Không ghi, không sửa sổ POS (ADR-OKT-1 I-0: luồng bán, logic bán hàng và sổ của open-pos giữ nguyên).
 * - Phiếu bán = local_ledger loại 10 (kể cả bill Z: phiếu 10 con của phiếu 10, mã đuôi "Z"). Dòng hàng nằm ở phiếu xuất
 *   con loại 2 (local_ledger_parent_id = phiếu bán).
 * - Phiếu hoàn = local_ledger loại 11, dòng hàng (item_type 3) nằm ngay trên phiếu; local_ledger_parent_id = phiếu bán gốc.
 * - Chỉ phiếu ĐÃ DUYỆT, chưa xoá, không phải bản sao kế toán (local_ledger_source_id 'pg:…'); ngày phiếu trong khoảng
 *   HOẶC phiếu được sửa trong khoảng — cùng luật với các nguồn khác ở TGS_Acc_Source.
 * - Tiền dòng: CHÉP ĐÚNG công thức TGS_POS_BCTK_Sales_Service::sales() của open-pos
 *   (functions/class-tgs-pos-bctk-sales-service.php, khối "BÁO CÁO LÀM TRÒN VỀ ĐỒNG") — thành tiền chốt theo ĐVT bán
 *   gồm VAT, tách chưa thuế bằng TGS_Viettel_Invoice_Flow_Service::api_line_amounts khi có (khớp hoá đơn), thuế =
 *   thành tiền − chưa thuế. Sửa công thức bên POS thì sửa cùng ở đây (ADR-OKT-1 I-5: một luật tiền).
 * - Không có khối hoá đơn (so_hd …): phiếu đi sổ nội bộ của kế toán. Có tgs-bc-tk thì nguồn đó vẫn được ưu tiên
 *   (Q001-15) — xem TGS_Acc_Source::sale_source().
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-21 08:11 PM Johnny Chu - Chu Hoàng Anh] S002-4 (= B001-C10) — nguồn phiếu bán / hoàn từ sổ POS, chỉ đọc (I-0), công thức tiền theo TGS_POS_BCTK_Sales_Service (I-5).

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Source_Pos
{
    const TYPE_SALE = 10;
    const TYPE_RETURN = 11;
    const TYPE_EXPORT = 2;

    /** Có đọc được sổ POS của blog không (bảng phiếu + bảng dòng có mặt). */
    public static function ready($blog_id = 0)
    {
        global $wpdb;
        $prefix = self::prefix($blog_id);
        foreach (['local_ledger', 'local_ledger_item'] as $t) {
            $name = $prefix . $t;
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
                return false;
            }
        }
        return true;
    }

    /**
     * Phiếu bán (loại 10) theo khuôn dòng VAT. $ids: chỉ đúng các phiếu này (gửi tức thời theo sự kiện).
     * @return array[] mỗi phần tử: sale_id, so_phieu_xuat, ngay_xuat, ma_shop, ghi_chu, is_z, vat_state, user_id, nv_ten,
     *                 ma_kh, ten_kh, items[]
     */
    public static function sale_rows($blog_id, $from, $to, array $ids = [])
    {
        $rows = self::headers($blog_id, self::TYPE_SALE, $from, $to, $ids);
        if (!$rows) {
            return [];
        }
        $items = self::items($blog_id, array_column($rows, 'sale_id'), self::TYPE_EXPORT);
        $shop = self::shop_code($blog_id);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['sale_id'];
            $out[] = self::row($r, $shop, $items[$id] ?? [], self::is_z_sale($r)) + ['sale_id' => $id];
        }
        return $out;
    }

    /**
     * Phiếu hoàn (loại 11) theo khuôn dòng VAT của tgs-bc-tk: return_id = phiếu hoàn, sale_id = phiếu bán gốc
     * (0 nếu không có), _ledger_id = phiếu hoàn (cho TGS_Acc_Source::only_approved).
     */
    public static function return_rows($blog_id, $from, $to)
    {
        $rows = self::headers($blog_id, self::TYPE_RETURN, $from, $to, []);
        if (!$rows) {
            return [];
        }
        $items = self::items($blog_id, array_column($rows, 'sale_id'), 0);
        $shop = self::shop_code($blog_id);
        $out = [];
        foreach ($rows as $r) {
            $rid = (int) $r['sale_id'];
            $out[] = self::row($r, $shop, $items[$rid] ?? [], self::is_z_code((string) $r['so_phieu_xuat'])) + [
                'return_id'  => $rid,
                'sale_id'    => (int) $r['parent_id'],
                '_ledger_id' => $rid,
            ];
        }
        return $out;
    }

    /**
     * Tiền một dòng, đúng công thức TGS_POS_BCTK_Sales_Service::sales() (open-pos). Đầu vào là các cột của
     * local_ledger_item: quantity (ĐVT nhỏ nhất), price (đơn giá trước thuế / ĐVT nhỏ nhất), discount_amount (chiết khấu
     * trước thuế cả dòng), tax_percent, tax_amount, unit_ratio.
     * @return array ['before' => chưa thuế (đồng), 'tax' => thuế (đồng), 'total' => thành tiền gồm VAT (đồng),
     *                'unit_price' => đơn giá trước thuế sau chiết khấu / ĐVT nhỏ nhất]
     */
    public static function line_money($qty, $price, $discount, $tax_pct, $tax_amount, $ratio)
    {
        $qty = (float) $qty;
        $price = (float) $price;
        $discount = (float) $discount;
        $tax_pct = (float) $tax_pct;
        $ratio = max(1.0, (float) $ratio);

        $sau_ck = $qty * $price - $discount;
        $don_gia = $qty != 0.0 ? $sau_ck / $qty : 0.0;

        $consumer = round($price * $qty - $discount + (float) $tax_amount);
        $dg_sale = round($price * $ratio * (1 + $tax_pct / 100));
        $sale_qty = $qty / $ratio;
        $ck_sale = max(0.0, round($dg_sale * $sale_qty - $consumer));
        $total = (float) max(0, round($dg_sale * $sale_qty - $ck_sale));

        if ($tax_pct > 0) {
            if (class_exists('TGS_Viettel_Invoice_Flow_Service') && method_exists('TGS_Viettel_Invoice_Flow_Service', 'api_line_amounts')) {
                $al = TGS_Viettel_Invoice_Flow_Service::api_line_amounts($qty, $don_gia, $total, $tax_pct);
                $before = (float) max(0, round((float) ($al['without_tax'] ?? 0)));
            } else {
                $before = (float) max(0, round($sau_ck));
            }
            $tax = max(0.0, $total - $before);
        } else {
            $before = $total;
            $tax = 0.0;
        }
        return ['before' => $before, 'tax' => $tax, 'total' => $total, 'unit_price' => round($don_gia, 2)];
    }

    // ------------------------------------------------------------------------------------------------ đọc sổ

    private static function prefix($blog_id)
    {
        global $wpdb;
        return (int) $blog_id > 0 ? $wpdb->get_blog_prefix((int) $blog_id) : $wpdb->prefix;
    }

    /** Đầu phiếu loại $type: đã duyệt, chưa xoá, không phải bản sao kế toán, ngày phiếu / ngày sửa trong khoảng. */
    private static function headers($blog_id, $type, $from, $to, array $ids)
    {
        global $wpdb;
        $prefix = self::prefix($blog_id);
        $L = $prefix . 'local_ledger';
        $P = $prefix . 'local_ledger_person';
        $nm = TGS_Acc_Source::not_mirror_sql($L, 'd');
        $only = '';
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids) {
            $only = ' AND d.local_ledger_id IN (' . implode(',', $ids) . ')';
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT d.local_ledger_id AS sale_id, d.local_ledger_parent_id AS parent_id, pa.local_ledger_type AS parent_type,
                    d.local_ledger_code AS so_phieu_xuat, COALESCE(d.local_ledger_ticket_date, d.created_at) AS ngay_xuat,
                    COALESCE(d.local_ledger_note, '') AS ghi_chu, d.user_id,
                    COALESCE(per.local_ledger_person_phone, '') AS ma_kh, COALESCE(per.local_ledger_person_name, '') AS ten_kh,
                    COALESCE(u.display_name, '') AS nv_ten
               FROM {$L} d
               LEFT JOIN {$L} pa ON pa.local_ledger_id = d.local_ledger_parent_id
               LEFT JOIN {$P} per ON per.local_ledger_person_id = d.local_ledger_person_id
               LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id
              WHERE d.local_ledger_type = %d AND (d.is_deleted = 0 OR d.is_deleted IS NULL) AND d.local_ledger_approver_status = 1{$nm}
                AND (COALESCE(d.local_ledger_ticket_date, DATE(d.created_at)) BETWEEN %s AND %s OR d.updated_at BETWEEN %s AND %s)",
            (int) $type, $from, $to, $from . ' 00:00:00', $to . ' 23:59:59'
        ) . $only . ' ORDER BY d.local_ledger_id ASC', ARRAY_A);
    }

    /**
     * Dòng hàng của các phiếu $ids. $child_type > 0: dòng nằm ở phiếu con loại đó (phiếu bán → phiếu xuất loại 2);
     * 0: dòng nằm ngay trên phiếu (phiếu hoàn). [ledger_id => [dòng…]].
     */
    private static function items($blog_id, array $ids, $child_type)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $prefix = self::prefix($blog_id);
        $L = $prefix . 'local_ledger';
        $I = $prefix . 'local_ledger_item';
        $G = $wpdb->base_prefix . 'global_product_name';
        $in = implode(',', $ids);
        $cols = "i.local_ledger_item_id AS item_id, i.local_product_sku AS sku,
                 COALESCE(NULLIF(g.global_product_name, ''), i.local_ledger_item_product_name_cache, '') AS name,
                 COALESCE(g.global_product_unit, '') AS base_unit,
                 i.quantity, i.price, i.local_ledger_item_unit_quantity AS unit_qty, i.local_ledger_item_unit_ratio AS unit_ratio,
                 i.local_ledger_item_unit_name AS unit, i.local_ledger_item_discount_amount AS discount,
                 i.local_ledger_item_tax_percent AS tax_percent, i.local_ledger_item_is_kct AS is_kct,
                 i.local_ledger_item_tax_amount AS tax_amount, i.local_ledger_item_gift_type AS gift_type,
                 i.local_ledger_item_warehouse_zone AS zone, i.lot_code, i.exp_date, i.local_ledger_item_note AS note";
        $join = "LEFT JOIN {$G} g ON g.global_product_name_id = i.global_product_name_id";
        if ((int) $child_type > 0) {
            $sql = "SELECT ch.local_ledger_parent_id AS doc_id, {$cols}
                      FROM {$L} ch JOIN {$I} i ON i.local_ledger_id = ch.local_ledger_id {$join}
                     WHERE ch.local_ledger_parent_id IN ({$in}) AND ch.local_ledger_type = " . (int) $child_type . "
                       AND (ch.is_deleted = 0 OR ch.is_deleted IS NULL) AND (i.is_deleted = 0 OR i.is_deleted IS NULL)
                     ORDER BY i.local_ledger_item_id";
        } else {
            $sql = "SELECT i.local_ledger_id AS doc_id, {$cols}
                      FROM {$I} i {$join}
                     WHERE i.local_ledger_id IN ({$in}) AND (i.is_deleted = 0 OR i.is_deleted IS NULL)
                     ORDER BY i.local_ledger_item_id";
        }
        $out = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $it) {
            $out[(int) $it['doc_id']][] = self::item($it);
        }
        return $out;
    }

    /** Một dòng local_ledger_item → dòng VAT (khoá mà TGS_Acc_Mapper::lines đọc). */
    private static function item(array $it)
    {
        $qty = abs((float) $it['quantity']);
        $ratio = max(1.0, (float) ($it['unit_ratio'] ?? 1));
        $kct = !empty($it['is_kct']);
        $pct = $kct ? 0.0 : (float) ($it['tax_percent'] ?? 0);
        $m = self::line_money($qty, abs((float) $it['price']), abs((float) ($it['discount'] ?? 0)), $pct, abs((float) ($it['tax_amount'] ?? 0)), $ratio);
        $unit_qty = (float) ($it['unit_qty'] ?? 0);
        $sale_unit = trim((string) ($it['unit'] ?? ''));
        $base_unit = trim((string) ($it['base_unit'] ?? ''));
        $gift = trim((string) ($it['gift_type'] ?? ''));
        return [
            'item_id'          => (int) $it['item_id'],
            'ma_hang'          => (string) $it['sku'],
            'ten'              => (string) $it['name'],
            'dvt'              => $sale_unit !== '' ? $sale_unit : $base_unit,
            'dvt_goc'          => $base_unit,
            'sl_dvcb'          => $qty,
            'sl'               => $unit_qty > 0 ? $unit_qty : $qty / $ratio,
            'ratio'            => $ratio,
            'don_gia_gui_thue' => $m['unit_price'],
            'tien_chua_thue'   => $m['before'],
            'ck'               => abs((float) ($it['discount'] ?? 0)),
            'thue_suat'        => $kct ? 'KCT' : $pct,
            'tien_thue'        => $m['tax'],
            'thanh_tien'       => $m['total'],
            'kho'              => (string) ($it['zone'] ?? ''),
            'is_gift'          => $gift !== '' && $gift !== '0',
            'so_lo'            => (string) ($it['lot_code'] ?? ''),
            'exp'              => (string) ($it['exp_date'] ?? ''),
            'ghi_chu'          => (string) ($it['note'] ?? ''),
        ];
    }

    /** Đầu phiếu → khoá dòng VAT dùng chung cho phiếu bán và phiếu hoàn. */
    private static function row(array $r, $shop, array $items, $is_z)
    {
        return [
            'so_phieu_xuat' => (string) $r['so_phieu_xuat'],
            'ngay_xuat'     => (string) $r['ngay_xuat'],
            'ma_shop'       => (string) $shop,
            'ghi_chu'       => (string) $r['ghi_chu'],
            'is_z'          => (bool) $is_z,
            'vat_state'     => '',
            'user_id'       => (int) $r['user_id'],
            'nv_ten'        => (string) $r['nv_ten'],
            'ma_kh'         => (string) $r['ma_kh'],
            'ten_kh'        => (string) $r['ten_kh'],
            'items'         => $items,
        ];
    }

    /** Bill Z (phiếu bán tách toàn hàng không gửi thuế): phiếu 10 con của phiếu 10, mã đuôi "Z". */
    private static function is_z_sale(array $r)
    {
        return (int) ($r['parent_type'] ?? 0) === self::TYPE_SALE || self::is_z_code((string) $r['so_phieu_xuat']);
    }

    /** Mã phiếu bán / hoàn của bill Z kết thúc bằng hậu tố tách (mặc định "Z") — hỏi lớp mã phiếu của POS nếu có. */
    private static function is_z_code($code)
    {
        if (class_exists('TGS_POS_Sale_Code') && method_exists('TGS_POS_Sale_Code', 'code_ends_with_split_suffix')) {
            return (bool) TGS_POS_Sale_Code::code_ends_with_split_suffix($code);
        }
        return (bool) preg_match('/Z$/', (string) $code);
    }

    private static function shop_code($blog_id)
    {
        return method_exists('TGS_Acc_Source', 'shop_code') ? (string) TGS_Acc_Source::shop_code((int) $blog_id) : '';
    }
}
