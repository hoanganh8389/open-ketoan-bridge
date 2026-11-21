<?php
/**
 * Chuyển một dòng VAT (tgs-bc-tk) thành chứng từ theo khuôn của phần mềm kế toán
 * (packages/contracts/src/documents.ts bên dự án kế toán).
 *
 * Nguyên tắc: chỉ gửi SỰ VIỆC (bán gì, bao nhiêu, thuế bao nhiêu, thu bằng gì).
 * Tài khoản Nợ/Có do phần mềm kế toán quyết định theo cấu hình.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Mapper
{
    /**
     * Hình thức thu (meta phiếu thu) → mã hình thức thanh toán bên kế toán.
     * Sửa bằng filter 'tgs_acc_method_map'. Mã chưa có trong bảng giữ nguyên dạng IN HOA
     * để bên kế toán báo rõ "Hình thức thanh toán … chưa khai báo".
     */
    public static function method_map()
    {
        return apply_filters('tgs_acc_method_map', [
            'cash' => 'TM', 'tien_mat' => 'TM',
            'qr' => 'QR', 'vietqr' => 'QR', 'qrcode' => 'QR', 'qr_code' => 'QR',
            'card' => 'POS', 'pos' => 'POS', 'visa' => 'POS',
            'transfer' => 'CK', 'bank' => 'CK', 'bank_transfer' => 'CK', 'chuyen_khoan' => 'CK',
            'voucher' => 'VOUCHER',
            'debt' => 'CONGNO', 'credit' => 'CONGNO', 'cong_no' => 'CONGNO',
        ]);
    }

    public static function method_code($method, $label = '')
    {
        // Phiếu thu ghi rõ hình thức (nhãn = tên hình thức thanh toán: "QR VTB CT", "POS AGR CT"…) → mã chi tiết,
        // để bên kế toán ghi đúng tài khoản ngân hàng của hình thức đó. Tiền mặt / ghi nợ giữ mã chung.
        $m = strtolower(trim((string) $method));
        $detail = self::label_code($label);
        if ($detail !== '' && $m !== 'cash' && $m !== 'tien_mat') {
            return $detail;
        }
        $map = self::method_map();
        if (isset($map[$m])) {
            return $map[$m];
        }
        foreach ($map as $k => $code) {
            if ($k !== '' && strpos($m, $k) !== false) {
                return $code;
            }
        }
        return strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $m)) ?: 'TM';
    }

    /**
     * Tên hình thức thanh toán → mã bên kế toán: bỏ dấu, IN HOA, khoảng trắng / ký tự lạ thành "-"
     * ("QR VTB CT" → QR-VTB-CT, "VietQR MBB CT-Z" → VIETQR-MBB-CT-Z). Vài tên chung đổi về mã chung.
     * Sửa bằng filter 'tgs_acc_method_label_code'.
     */
    public static function label_code($label)
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '';
        }
        $s = function_exists('remove_accents') ? remove_accents($label) : $label;
        $s = str_replace(['đ', 'Đ'], ['d', 'D'], $s);
        $s = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        $common = ['TIEN-MAT' => 'TM', 'CHUYEN-KHOAN' => 'CK', 'CONG-NO' => 'CONGNO', 'GHI-CONG-NO' => 'CONGNO', 'DOI-TRU-CONG-NO' => 'DOI_TRU'];
        $s = $common[$s] ?? substr($s, 0, 32);
        return (string) apply_filters('tgs_acc_method_label_code', $s, $label);
    }

    /**
     * Thuế suất của dòng → mã loại thuế bên kế toán.
     * KCT → "KCT"; 0/5/8/10 → R00/R05/R08/R10; chưa khai báo → R08 nếu có tiền thuế, KCT nếu không.
     */
    public static function tax_code($thue_suat, $tien_thue)
    {
        if ($thue_suat === 'KCT') {
            return 'KCT';
        }
        if (is_numeric($thue_suat)) {
            return 'R' . str_pad((string) (int) round((float) $thue_suat), 2, '0', STR_PAD_LEFT);
        }
        return abs((float) $tien_thue) > 0 ? 'R08' : 'KCT';
    }

    /** Ngày giờ máy chủ "Y-m-d H:i:s" → "Y-m-d" (ngày hạch toán). */
    private static function day($dt)
    {
        $dt = trim((string) $dt);
        return $dt === '' ? '' : substr($dt, 0, 10);
    }

    /** Dòng hàng VAT → dòng chứng từ (tiền DƯƠNG). */
    private static function lines(array $items)
    {
        $out = [];
        if (class_exists('TGS_Item_Variant')) {
            TGS_Item_Variant::prime(array_column($items, 'ma_hang'));
        }
        foreach ($items as $i => $it) {
            $before = abs((float) ($it['tien_chua_thue'] ?? 0));
            $tax    = abs((float) ($it['tien_thue'] ?? 0));
            $line = [
                'lineKey'         => (string) ((int) ($it['item_id'] ?? 0) ?: ('i' . $i)),
                'sku'             => (string) ($it['ma_hang'] ?? ''),
                // Định danh bất biến: kế toán khớp theo itemId, mã chỉ để đọc (docs/ma-hang-so-va-bien-the.md).
                'itemId'          => class_exists('TGS_Item_Variant') ? TGS_Item_Variant::item_id($it['ma_hang'] ?? '') : null,
                'name'            => (string) ($it['ten'] ?? ''),
                // [2026-11-21 08:30 PM Johnny Chu - Chu Hoàng Anh] S002-6 — qty theo ĐVT nhỏ nhất thì unit cũng là ĐVT nhỏ nhất (dvt_goc của nguồn sổ POS); kế toán tự thêm mặt hàng lấy unit này. Dòng tgs-bc-tk không có dvt_goc: giữ như cũ.
                'unit'            => (string) (($it['dvt_goc'] ?? '') !== '' ? $it['dvt_goc'] : ($it['dvt'] ?? '')),
                // qty = quy về đơn vị nhỏ nhất (tồn kho); unitQty/unitRatio = theo ĐVT bán trên phiếu (lốc, thùng…).
                'qty'             => abs((float) ($it['sl_dvcb'] ?? 0)),
                'unitQty'         => abs((float) ($it['sl'] ?? ($it['sl_dvcb'] ?? 0))),
                'unitRatio'       => max(1.0, (float) ($it['ratio'] ?? 1)),
                'unitPrice'       => (float) ($it['don_gia_gui_thue'] ?? 0),
                'amountBeforeTax' => $before,
                'discount'        => abs((float) ($it['ck'] ?? 0)),
                'taxCode'         => self::tax_code($it['thue_suat'] ?? null, $tax),
                'taxAmount'       => $tax,
                'warehouseCode'   => (string) ($it['kho'] ?? ''),
                'isGift'          => !empty($it['is_gift']),
                'lotNo'           => (string) ($it['so_lo'] ?? ''),
                'note'            => trim((string) ($it['ghi_chu'] ?? '')),
            ];
            // Hạn dùng của lô: chỉ gửi khi đúng dạng ngày.
            $exp = self::day($it['exp'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) && $exp !== '0000-00-00') {
                $line['expDate'] = $exp;
            }
            if (!$line['itemId']) {
                unset($line['itemId']);
            }
            $out[] = $line;
        }
        return $out;
    }

    private static function totals(array $lines)
    {
        $before = 0.0;
        $tax = 0.0;
        foreach ($lines as $l) {
            $before += $l['amountBeforeTax'];
            $tax += $l['taxAmount'];
        }
        return ['beforeTax' => round($before, 2), 'tax' => round($tax, 2), 'total' => round($before + $tax, 2)];
    }

    /** Đối tượng: mã = số điện thoại khách; không có thì để bên kế toán gắn "Khách lẻ". */
    private static function partner(array $r)
    {
        $code = trim((string) ($r['ma_kh'] ?? ''));
        if ($code === '') {
            return null;
        }
        $name = trim((string) ($r['ten_cty_mua'] ?? '')) ?: trim((string) ($r['ten_kh'] ?? ''));
        return array_filter([
            'code'    => $code,
            'name'    => $name,
            'taxCode' => trim((string) ($r['mst_mua'] ?? '')),
            'address' => trim((string) ($r['dchi_mua'] ?? '')),
            'email'   => trim((string) ($r['email_mua'] ?? '')),
            'phone'   => trim((string) ($r['dt_mua'] ?? '')),
        ], static function ($v) { return $v !== ''; });
    }

    /** Nhân viên lập phiếu (người dùng WordPress): mã = tên đăng nhập, để kế toán xem công nợ theo nhân viên. */
    private static function employee(array $r)
    {
        $uid = (int) ($r['user_id'] ?? 0);
        if ($uid <= 0 || !function_exists('get_userdata')) {
            return null;
        }
        $u = get_userdata($uid);
        if (!$u) {
            return null;
        }
        $code = trim((string) $u->user_login);
        // Tên nhân viên: tên POS ghi trên phiếu (nv_ten), không có thì tên hiển thị của tài khoản.
        $name = trim((string) ($r['nv_ten'] ?? '')) ?: trim((string) $u->display_name);
        return $code === '' ? null : ['code' => $code, 'name' => $name ?: $code];
    }

    /** Hoá đơn đã phát hành (có số) → khối invoice; chưa có số thì null (không vào sổ thuế). */
    private static function invoice(array $r, $date_fallback)
    {
        $no = trim((string) ($r['so_hd'] ?? ''));
        if ($no === '') {
            return null;
        }
        $state = strtolower((string) ($r['vat_state'] ?? ''));
        $status = $state === 'replaced' ? 'replaced' : (in_array($state, ['cancelled', 'canceled', 'deleted'], true) ? 'cancelled' : 'valid');
        return array_filter([
            'no'             => $no,
            'serial'         => (string) ($r['seri'] ?? ''),
            'form'           => (string) ($r['mau_hd'] ?? ''),
            'date'           => self::day($r['ngay_hd'] ?? '') ?: $date_fallback,
            'status'         => $status,
            'provider'       => 'viettel',
            'buyerName'      => trim((string) ($r['ten_kh'] ?? '')),
            'buyerLegalName' => trim((string) ($r['ten_cty_mua'] ?? '')),
            'buyerTaxCode'   => trim((string) ($r['mst_mua'] ?? '')),
            'buyerAddress'   => trim((string) ($r['dchi_mua'] ?? '')),
        ], static function ($v) { return $v !== ''; });
    }

    /**
     * Phiếu xuất bán → chứng từ "sale". KHÔNG kèm tiền thu: mỗi phiếu thu của đơn là một chứng từ thanh toán riêng
     * (self::payment), ghi sổ theo ngày thu và trỏ về phiếu bán này. Đơn đổi trả: $offsets = [[return_id, số tiền], …]
     * (TGS_Acc_Source::offsets_by_sale) → mục "offsets" (đối trừ với phiếu hoàn, không phải tiền thật).
     */
    public static function sale(array $r, $blog_id, array $offsets = [])
    {
        $date  = self::day($r['ngay_xuat'] ?? '');
        $lines = self::lines((array) ($r['items'] ?? []));
        $payments = [];
        $doc = [
            'docType'      => 'sale',
            'docKey'       => 'btsoft:' . (int) $blog_id . ':sale:' . (int) $r['sale_id'],
            'branchCode'   => (TGS_Acc_Source::branch_of_blog((int) $blog_id) ?: (string) $r['ma_shop']),
            'docNo'        => (string) $r['so_phieu_xuat'],
            'docDate'      => $date,
            'description'  => trim('Bán hàng ' . $r['so_phieu_xuat'] . ($r['ghi_chu'] !== '' ? ' — ' . $r['ghi_chu'] : '')),
            'internalOnly' => !empty($r['is_z']),
            'sourceSystem' => 'btsoft',
            'lines'        => $lines,
            'payments'     => $payments,
            'totals'       => self::totals($lines),
            'extra'        => ['blogId' => (int) $blog_id, 'saleId' => (int) $r['sale_id'], 'vatState' => (string) $r['vat_state'], 'userId' => (int) $r['user_id']],
        ];
        // Lý do xuất chọn ở POS (XBA, XCN, XTA…) quyết định tài khoản đối ứng / doanh thu / giá vốn bên kế toán.
        // Không ghi, hoặc là XBA (= lý do mặc định của phiếu bán bên kế toán) thì không gửi: phiếu đã gửi trước đây giữ
        // nguyên nội dung, không bị lên phiên bản hàng loạt.
        if (!in_array(strtoupper((string) ($r['export_reason'] ?? '')), ['', 'XBA'], true)) {
            $doc['reasonCode'] = (string) $r['export_reason'];
        }
        $partner = self::partner($r);
        if ($partner) {
            $doc['partner'] = $partner;
        }
        $employee = self::employee($r);
        if ($employee) {
            $doc['employee'] = $employee;
        }
        $inv = self::invoice($r, $date);
        if ($inv && empty($r['is_z'])) {
            $doc['invoice'] = $inv;
        }
        // Đổi trả: tiền hàng trả lại được trừ vào đơn (không phải tiền thật) → kế toán đối trừ công nợ với phiếu hoàn.
        foreach ($offsets as $o) {
            $doc['offsets'][] = ['docKey' => 'btsoft:' . (int) $blog_id . ':return:' . (int) $o[0], 'amount' => (float) $o[1]];
        }
        return $doc;
    }

    /**
     * Phiếu hoàn hàng → chứng từ "sale_return". KHÔNG kèm tiền trả khách: mỗi phiếu chi trả khách là một chứng từ thanh
     * toán riêng (self::payment, chiều chi). Hoàn kiểu đối trừ với đơn mới (không có phiếu chi) → khách dư Có trên công nợ.
     */
    public static function sale_return(array $r, $blog_id)
    {
        $date  = self::day($r['ngay_xuat'] ?? '');
        $lines = self::lines((array) ($r['items'] ?? []));
        $key_id = (int) ($r['return_id'] ?? 0) ?: (int) $r['sale_id'];
        $payments = [];
        $doc = [
            'docType'        => 'sale_return',
            'docKey'         => 'btsoft:' . (int) $blog_id . ':return:' . $key_id,
            'branchCode'     => (TGS_Acc_Source::branch_of_blog((int) $blog_id) ?: (string) $r['ma_shop']),
            'docNo'          => (string) $r['so_phieu_xuat'],
            'docDate'        => $date,
            'description'    => trim('Hàng bán trả lại ' . $r['so_phieu_xuat'] . ($r['ghi_chu'] !== '' ? ' — ' . $r['ghi_chu'] : '')),
            'internalOnly'   => !empty($r['is_z']),
            'sourceSystem'   => 'btsoft',
            'lines'          => $lines,
            'payments'       => $payments,
            'totals'         => self::totals($lines),
            'extra'          => ['blogId' => (int) $blog_id, 'saleId' => (int) $r['sale_id'], 'returnId' => $key_id],
        ];
        // Hàng bán trả lại không có phiếu bán gốc (bản linh hoạt cho kế toán) → không gửi originalDocKey.
        if ((int) ($r['sale_id'] ?? 0) > 0) {
            $doc['originalDocKey'] = 'btsoft:' . (int) $blog_id . ':sale:' . (int) $r['sale_id'];
        }
        $partner = self::partner($r);
        if ($partner) {
            $doc['partner'] = $partner;
        }
        $employee = self::employee($r);
        if ($employee) {
            $doc['employee'] = $employee;
        }
        $inv = self::invoice($r, $date);
        if ($inv && empty($r['is_z'])) {
            $inv['relation'] = 'adjusts';
            $doc['invoice'] = $inv;
        }
        return $doc;
    }

    /**
     * Phiếu thu của đơn bán (loại 7) / phiếu chi trả tiền khách của phiếu hoàn (loại 8) → chứng từ "customer_payment"
     * (thanh toán khách hàng) RIÊNG: ngày = ngày của phiếu, số = mã phiếu, trỏ về phiếu bán / hoàn (invoiceDocKey) để bên
     * kế toán đối trừ công nợ đúng hoá đơn. Phiếu chi trả khách: direction = out. Phiếu 0 đồng: không gửi.
     * @param array  $p          dòng của TGS_Acc_Source::payment_rows
     * @param string $shop_code  mã shop (chi nhánh bên kế toán)
     */
    /**
     * Phiếu nhập kho mua của NCC (loại 1, không phiếu cha) → chứng từ "purchase"; phiếu trả hàng NCC (loại 16) →
     * "purchase_return". Tiền dòng theo mô hình tiền của local_ledger_item: tiền hàng = số lượng (ĐVCB) × đơn giá gốc −
     * chiết khấu dòng (trước thuế), thuế = tiền thuế của dòng; làm tròn đồng từng dòng. Nhà cung cấp = mã NCC (danh mục
     * NCC chung); hoá đơn đầu vào = số chứng từ (code_source) + ký hiệu, ngày hoá đơn ở advance_meta.invoice.
     */
    /**
     * [2026-11-15 Nguyễn Văn Thủy] Lý do xuất ở shop → MỤC ĐÍCH XUẤT của phần mềm kế toán (Quy tắc hạch toán ACC41) (đóng góp)
     * Lý do không còn quyết tài khoản bên kế toán; mục đích xuất mới quyết. Lý do không có trong bảng → không gửi mục đích:
     * phiếu vào "thiếu chờ xử lý" (1381) để kế toán soát, không mất phiếu. Thêm cặp mới ở đây khi kế toán khai mục đích mới.
     */
    const PURPOSE_OF_REASON = [
        'XHH01'     => 'HUY',        // Xuất hủy hàng hỏng → 6328
        'XHH02'     => 'MAT',        // Mất, thất thoát hàng hóa → 8115
        'XMKT1'     => 'TANG-KH',    // Xuất khách dùng thử sản phẩm → 64184
        'XTA1.4'    => 'TANG-KH',    // Xuất sử dụng / tặng / thưởng khách hàng → 64184
        'XSD1'      => 'DUNG-BH',    // Xuất sử dụng vật tư, hàng hoá cho bộ phận bán hàng → 64128
        'XSD2'      => 'DUNG-QL',    // Xuất sử dụng vật tư cho bộ phận quản lý → 64232
        'XDT_641'   => 'DAO-TAO',    // Xuất đào tạo bộ phận bán hàng → 64185
        'XTA3'      => 'THUONG-NV',  // Xuất sử dụng / tặng / thưởng nhân viên → 64118
        'XTA_64118' => 'THUONG-NV',  // Xuất sử dụng / tặng / thưởng bộ phận bán hàng → 64118
        'XTA2'      => 'NGOAI-GIAO', // Xuất tặng / ngoại giao → 64282
    ];

    /** Mục đích xuất ứng với lý do xuất (mã viết hoa); '' = chưa có cặp. */
    public static function purpose_of($reason)
    {
        $reason = strtoupper(trim((string) $reason));
        return self::PURPOSE_OF_REASON[$reason] ?? '';
    }

    /**
     * Phiếu xuất điều chỉnh (TGS_Acc_Source::export_adjust_rows):
     *   - phiếu 0đ (xuất dùng, hủy, tặng…) → chứng từ "stock_adjustment" giảm kho, kèm purposeCode theo lý do (bảng trên).
     *     Chỉ gửi số lượng: giá trị theo giá vốn bình quân khi tính giá vốn tháng (Nợ TK của mục đích / Có kho).
     *   - phiếu có giá (thực chất là bán) → chứng từ "sale" NỘI BỘ (internalOnly, không hoá đơn) như phiếu bán.
     * Lý do gửi kèm (reasonCode) chỉ làm nhãn để lọc báo cáo.
     */
    public static function export_adjust(array $r, $blog_id, $shop_code)
    {
        $blog = (int) $blog_id;
        $lines = [];
        foreach ((array) $r['items'] as $i => $it) {
            $qty = abs((float) $it['quantity']);
            if ((string) $it['sku'] === '' || $qty <= 0) {
                continue;
            }
            $before = round(max(0, $qty * (float) $it['price'] - abs((float) $it['discount'])));
            $tax = round(abs((float) $it['tax_amount']));
            $line = [
                'lineKey'         => (string) ((int) $it['item_id'] ?: ('i' . $i)),
                'sku'             => (string) $it['sku'],
                'name'            => (string) ($it['name'] ?? ''),
                'unit'            => (string) ($it['unit'] ?? ''),
                'qty'             => $qty,
                'unitQty'         => abs((float) ($it['unit_qty'] ?: $qty)),
                'unitRatio'       => max(1.0, (float) ($it['unit_ratio'] ?: 1)),
                'unitPrice'       => (float) $it['price'],
                'amountBeforeTax' => $before,
                'discount'        => round(abs((float) $it['discount'])),
                'taxCode'         => !empty($it['is_kct']) ? 'KCT' : self::tax_code($it['tax_percent'], $tax),
                'taxAmount'       => $tax,
                'isGift'          => (int) $it['gift_type'] > 0,
                'lotNo'           => (string) ($it['lot_code'] ?? ''),
                'note'            => trim((string) ($it['note'] ?? '')),
            ];
            $exp = self::day($it['exp_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) && $exp !== '0000-00-00') {
                $line['expDate'] = $exp;
            }
            $lines[] = $line;
        }
        $reason = trim((string) ($r['reason'] ?? ''));
        $priced = array_sum(array_map(static function ($l) { return $l['amountBeforeTax'] + $l['taxAmount']; }, $lines)) > 0;
        if (!$priced) {
            $doc = [
                'docType'      => 'stock_adjustment',
                'direction'    => 'decrease',
                'docKey'       => 'btsoft:' . $blog . ':export-adjust:' . (int) $r['id'],
                'branchCode'   => (string) $shop_code,
                'docNo'        => (string) $r['code'],
                'docDate'      => self::day($r['day'] ?? ''),
                'description'  => trim('Xuất điều chỉnh ' . $r['code'] . ((string) ($r['note'] ?? '') !== '' ? ' — ' . mb_substr((string) $r['note'], 0, 300) : '')),
                'sourceSystem' => 'btsoft',
                'sourceRef'    => ['type' => 'export_adjust', 'id' => (string) (int) $r['id'], 'label' => 'Phiếu xuất điều chỉnh'],
                'lines'        => array_map(static function ($l) {
                    return array_filter([
                        'lineKey' => $l['lineKey'], 'sku' => $l['sku'], 'name' => $l['name'], 'unit' => $l['unit'], 'qty' => $l['qty'], 'amount' => 0,
                        'lotNo' => $l['lotNo'] !== '' ? $l['lotNo'] : null, 'expDate' => $l['expDate'] ?? null,
                    ], static function ($v) { return $v !== null; });
                }, $lines),
                'totals'       => ['total' => 0],
                'extra'        => ['blogId' => $blog, 'ledgerId' => (int) $r['id'], 'userId' => (int) ($r['user_id'] ?? 0), 'exportAdjust' => true],
            ];
            if ($reason !== '') {
                $doc['reasonCode'] = $reason;
                $purpose = self::purpose_of($reason);
                if ($purpose !== '') {
                    $doc['purposeCode'] = $purpose;
                }
            }
            return $doc;
        }
        $doc = [
            'docType'      => 'sale',
            'docKey'       => 'btsoft:' . $blog . ':export-adjust:' . (int) $r['id'],
            'branchCode'   => (string) $shop_code,
            'docNo'        => (string) $r['code'],
            'docDate'      => self::day($r['day'] ?? ''),
            'description'  => trim('Xuất điều chỉnh ' . $r['code'] . ((string) ($r['note'] ?? '') !== '' ? ' — ' . mb_substr((string) $r['note'], 0, 300) : '')),
            'internalOnly' => true,
            'sourceSystem' => 'btsoft',
            'sourceRef'    => ['type' => 'export_adjust', 'id' => (string) (int) $r['id'], 'label' => 'Phiếu xuất điều chỉnh'],
            'lines'        => $lines,
            'payments'     => [],
            'totals'       => self::totals($lines),
            'extra'        => ['blogId' => $blog, 'ledgerId' => (int) $r['id'], 'userId' => (int) ($r['user_id'] ?? 0), 'exportAdjust' => true],
        ];
        if ((string) ($r['reason'] ?? '') !== '') {
            $doc['reasonCode'] = (string) $r['reason'];
        }
        $phone = trim((string) ($r['phone'] ?? ''));
        if ($phone !== '') {
            $doc['partner'] = array_filter(['code' => $phone, 'name' => (string) ($r['person_name'] ?? ''), 'phone' => $phone], 'strlen');
        }
        return $doc;
    }

    public static function supplier_doc(array $r, $blog_id, $shop_code)
    {
        $return = (int) $r['type'] === 16;
        $blog = (int) $blog_id;
        $adv = (array) ($r['adv'] ?? []);
        $date = self::day($r['day'] ?? '');
        $lines = [];
        foreach ((array) $r['items'] as $i => $it) {
            $qty = abs((float) $it['quantity']);
            $before = round(max(0, $qty * (float) $it['price'] - abs((float) $it['discount'])));
            $tax = round(abs((float) $it['tax_amount']));
            $line = [
                'lineKey'         => (string) ((int) $it['item_id'] ?: ('i' . $i)),
                'sku'             => (string) $it['sku'],
                'name'            => (string) ($it['name'] ?? ''),
                'unit'            => (string) ($it['unit'] ?? ''),
                'qty'             => $qty,
                'unitQty'         => abs((float) ($it['unit_qty'] ?: $qty)),
                'unitRatio'       => max(1.0, (float) ($it['unit_ratio'] ?: 1)),
                'unitPrice'       => (float) $it['price'],
                'amountBeforeTax' => $before,
                'discount'        => round(abs((float) $it['discount'])),
                // Mua vào: mã thuế ĐẦU VÀO (V05/V08/V10 → 1331), không dùng mã đầu ra R…
                'taxCode'         => !empty($it['is_kct']) ? 'KCT' : preg_replace('/^R/', 'V', self::tax_code($it['tax_percent'], $tax)),
                'taxAmount'       => $tax,
                'isGift'          => (int) $it['gift_type'] > 0,
                'lotNo'           => (string) ($it['lot_code'] ?? ''),
                'note'            => trim((string) ($it['note'] ?? '')),
            ];
            $exp = self::day($it['exp_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) && $exp !== '0000-00-00') {
                $line['expDate'] = $exp;
            }
            $lines[] = $line;
        }
        $reason = (string) ($adv['import_reason']['code'] ?? $adv['export_reason']['code'] ?? '');
        $doc = [
            'docType'      => $return ? 'purchase_return' : 'purchase',
            'docKey'       => 'btsoft:' . $blog . ($return ? ':supplier-return:' : ':purchase:') . (int) $r['id'],
            'branchCode'   => (string) $shop_code,
            'docNo'        => (string) $r['code'],
            'docDate'      => $date,
            'description'  => trim(($return ? 'Trả hàng NCC ' : 'Nhập mua hàng ') . $r['code'] . ((string) ($r['note'] ?? '') !== '' ? ' — ' . $r['note'] : '')),
            'sourceSystem' => 'btsoft',
            'lines'        => $lines,
            'payments'     => [],
            'totals'       => self::totals($lines),
            'extra'        => ['blogId' => $blog, 'ledgerId' => (int) $r['id'], 'supplierId' => (int) ($r['supplier_id'] ?? 0)],
        ];
        if ($reason !== '') {
            $doc['reasonCode'] = $reason;
        }
        $s = $r['supplier'] ?? null;
        if ($s && (string) $s['supplier_code'] !== '') {
            $doc['partner'] = array_filter([
                'code'    => (string) $s['supplier_code'],
                'name'    => (string) $s['supplier_name'],
                'taxCode' => (string) ($s['supplier_tax_code'] ?? ''),
                'address' => (string) ($s['supplier_address'] ?? ''),
                'phone'   => (string) ($s['supplier_phone'] ?? ''),
            ], 'strlen');
        }
        $due = self::day($r['due_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) && $due >= $date) {
            $doc['dueDate'] = $due;
        }
        $no = trim((string) ($r['invoice_no'] ?? ''));
        if ($no !== '') {
            $inv = (array) ($adv['invoice'] ?? []);
            $idate = self::day($inv['date'] ?? '');
            $doc['invoice'] = [
                'no'     => substr($no, 0, 32),
                'serial' => (string) ($inv['symbol'] ?? ''),
                'date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $idate) ? $idate : $date,
            ];
            // Tab "Thuế" của phiếu nhập: mẫu số HĐ + đối tượng ghi trên hoá đơn (sổ hoá đơn mua vào đọc buyer* là người bán).
            if ((string) ($inv['template'] ?? '') !== '') {
                $doc['invoice']['form'] = substr((string) $inv['template'], 0, 32);
            }
            $vat = (array) ($adv['vat'] ?? []);
            foreach (['name' => 'buyerName', 'tax_code' => 'buyerTaxCode', 'address' => 'buyerAddress'] as $k => $f) {
                if (trim((string) ($vat[$k] ?? '')) !== '') {
                    $doc['invoice'][$f] = trim((string) $vat[$k]);
                }
            }
        }
        // Phiếu lập từ form mới (có ca làm việc): gửi ca + nhân viên lập phiếu. Phiếu cũ không thêm để khỏi sinh phiên bản mới.
        if ((string) ($adv['shift'] ?? '') !== '') {
            $doc['extra']['shift'] = (string) $adv['shift'];
            if ($emp = self::employee($r)) {
                $doc['employee'] = $emp;
            }
        }
        if (!empty($adv['vat']['branch'])) {
            $doc['extra']['taxBranch'] = (string) $adv['vat']['branch'];
        }
        // Trả NCC từ phiếu nhập kho đã gửi kế toán: trỏ về phiếu nhập gốc.
        if ($return && (int) ($r['parent_type'] ?? 0) === 1) {
            $doc['originalDocKey'] = 'btsoft:' . $blog . ':purchase:' . (int) $r['parent_id'];
        }
        return $doc;
    }

    /**
     * Một đầu của phiếu chuyển kho global → chứng từ "transfer" MỘT ĐẦU (leg 'out' / 'in') của shop $blog_id.
     * Chi nhánh = chi nhánh ghi trên phiếu ở đầu đó (website ↔ chi nhánh lúc lập phiếu), thiếu thì mã shop; kho từng dòng
     * = phân kho của đầu đó. Chỉ gửi số lượng (giá trị 0): kế toán định giá theo giá vốn bình quân khi tính giá vốn tháng,
     * hai đầu cùng đơn giá nên hàng đang chuyển về 0. Đầu nhận dùng SL thực nhận nếu có.
     */
    public static function global_transfer(array $r, $blog_id, $shop_code)
    {
        $blog = (int) $blog_id;
        $out = $r['leg'] === 'out';
        $id = (int) $r['transfer_id'];
        $zone = (string) ($out ? $r['from_zone'] : $r['to_zone']);
        $lines = [];
        foreach ((array) $r['items'] as $it) {
            if ((string) $it['sku'] === '') {
                continue;
            }
            $qty = !$out && $it['received_qty'] !== null ? (float) $it['received_qty'] : (float) $it['quantity'];
            if ($qty <= 0) {
                continue;
            }
            $line = [
                'lineKey' => 'gt' . (int) $it['item_id'],
                'sku'     => (string) $it['sku'],
                'name'    => (string) ($it['product_name'] ?? ''),
                'unit'    => (string) ($it['unit_name'] ?? ''),
                'qty'     => $qty,
                'amount'  => 0,
            ];
            if ($zone !== '') {
                $line['warehouseCode'] = $zone;
            }
            // Lô (10/11/2026): phiếu ghi số lô / hạn dùng thì phần mềm kế toán lấy đúng lô đó ở cả hai đầu; trống thì tự chọn
            // lô hạn dùng sớm trước. Hai đầu gửi cùng lô nên bên nhận nhận đúng lô bên xuất.
            if (trim((string) ($it['lot_no'] ?? '')) !== '') {
                $line['lotNo'] = trim((string) $it['lot_no']);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($it['exp_date'] ?? ''))) {
                $line['expDate'] = (string) $it['exp_date'];
            }
            $lines[] = $line;
        }
        $label = $out ? 'Phiếu chuyển kho (xuất)' : 'Phiếu chuyển kho (nhận)';
        $doc = [
            'docType'      => 'transfer',
            'leg'          => $out ? 'out' : 'in',
            'docKey'       => 'btsoft:' . $blog . ':gt-' . ($out ? 'out' : 'in') . ':' . $id,
            'branchCode'   => (string) (($out ? $r['from_branch_code'] : $r['to_branch_code']) ?: $shop_code),
            'docNo'        => (string) $r['transfer_code'],
            'docDate'      => self::day($r['day'] ?? ''),
            'description'  => trim($label . ' ' . $r['transfer_code'] . ((string) ($r['note'] ?? '') !== '' ? ' — ' . $r['note'] : '')),
            // Đầu xuất: "Đang chuyển" tới khi bên nhận xác nhận (chỉ để hiển thị; định khoản một đầu không theo status).
            'status'       => !$out || (int) ($r['import_status'] ?? 0) === 1 || (int) ($r['to_blog_id'] ?? 0) <= 0 ? 'received' : 'shipped',
            'sourceSystem' => 'btsoft',
            'sourceRef'    => ['type' => 'global_transfer', 'id' => (string) $id, 'label' => 'Phiếu chuyển kho'],
            'lines'        => $lines,
            'totals'       => ['total' => 0],
            'extra'        => [
                'blogId' => $blog, 'globalTransferId' => $id, 'transferGroup' => (string) $r['transfer_code'],
                'fromBlogId' => (int) $r['from_blog_id'], 'fromZone' => (string) $r['from_zone'],
                'toBlogId' => (int) $r['to_blog_id'], 'toZone' => (string) $r['to_zone'],
            ],
        ];
        // Lý do chuyển kho chọn trên phiếu (danh mục lý do của phần mềm kế toán); trống = lý do mặc định của chuyển kho.
        if (trim((string) ($r['reason_code'] ?? '')) !== '') {
            $doc['reasonCode'] = trim((string) $r['reason_code']);
        }
        $other_blog = (int) ($out ? $r['to_blog_id'] : $r['from_blog_id']);
        $other = (string) ($out ? $r['to_branch_code'] : $r['from_branch_code']);
        if (class_exists('TGS_Global_Transfer')) {
            $other = TGS_Global_Transfer::branch_name_of($other); // phiếu chuyển từ dữ liệu cũ có thể lưu SRID
        }
        if ($other === '' && $other_blog > 0) {
            $other = TGS_Acc_Source::shop_code($other_blog);
        }
        if ($other !== '') {
            $doc[$out ? 'toBranchCode' : 'fromBranchCode'] = $other;
        }
        return $doc;
    }

    /**
     * Phiếu điều chỉnh tồn (loại 21) → chứng từ "stock_adjustment". Số lượng mỗi dòng giữ nguyên dấu (+ thừa / − thiếu):
     * cả phiếu cùng dấu thì direction increase / decrease, lẫn lộn thì 'mixed'. Chỉ gửi số lượng (giá trị 0): kế toán định
     * giá theo giá vốn bình quân khi tính giá vốn tháng. Lý do dòng → ghi chú dòng.
     */
    public static function adjustment(array $r, $blog_id, $shop_code)
    {
        $lines = [];
        $pos = 0;
        $neg = 0;
        foreach ((array) $r['items'] as $i => $it) {
            $q = (float) $it['quantity'];
            if ((string) $it['sku'] === '' || abs($q) < 0.0000001) {
                continue;
            }
            $q > 0 ? $pos++ : $neg++;
            $lines[] = [
                'lineKey' => (string) ((int) $it['item_id'] ?: ('i' . $i)),
                'sku'     => (string) $it['sku'],
                'name'    => (string) ($it['name'] ?? ''),
                'unit'    => (string) ($it['unit'] ?? ''),
                'qty'     => $q,
                'amount'  => 0,
            ];
        }
        $direction = $pos && $neg ? 'mixed' : ($neg ? 'decrease' : 'increase');
        if ($direction !== 'mixed') {
            foreach ($lines as &$l) {
                $l['qty'] = abs($l['qty']);
            }
            unset($l);
        }
        $blog = (int) $blog_id;
        return [
            'docType'      => 'stock_adjustment',
            'direction'    => $direction,
            'docKey'       => 'btsoft:' . $blog . ':adjust:' . (int) $r['id'],
            'branchCode'   => (string) $shop_code,
            'docNo'        => (string) $r['code'],
            'docDate'      => self::day($r['day'] ?? ''),
            'description'  => trim('Điều chỉnh tồn kho ' . $r['code'] . ((string) ($r['note'] ?? '') !== '' ? ' — ' . $r['note'] : '')),
            'sourceSystem' => 'btsoft',
            'sourceRef'    => ['type' => 'stock_adjustment', 'id' => (string) (int) $r['id'], 'label' => 'Phiếu điều chỉnh tồn kho'],
            'lines'        => $lines,
            'totals'       => ['total' => 0],
            'extra'        => ['blogId' => $blog, 'ledgerId' => (int) $r['id']],
        ];
    }

    /**
     * Phiếu chuyển tiền giữa chi nhánh (tgs_pos global_cash_transfer) → chứng từ "cash_transfer".
     * Mã chi nhánh gửi / nhận là mã chi nhánh HTsoft (cùng danh mục chi nhánh bên kế toán). Ngân hàng rút / nộp trống
     * = tiền mặt (TM), có thì gửi mã ngân hàng (danh mục Tài khoản ngân hàng bên kế toán) — không đọc cột paid_by_cash
     * vì phiếu lập ở POS luôn ghi 1. Phiếu lập ở POS → status 'sent' (ghi sổ nơi chuyển); kế toán xác nhận đã nhận tiền
     * ngay bên phần mềm kế toán (ACC14). Phiếu HTsoft đã duyệt (đồng bộ ngược) → 'received'. Lý do chi / thu gửi nguyên;
     * lý do không ghi sổ (CPCT / TPCT) bên kế toán tự bỏ qua.
     */
    public static function cash_transfer(array $r, $blog_id)
    {
        $amount = round(abs((float) ($r['amount'] ?? 0)), 2);
        $from = trim((string) ($r['branch_from_code'] ?? ''));
        $to = trim((string) ($r['branch_to_code'] ?? ''));
        if ($amount == 0.0 || $from === '' || $to === '') {
            return null;
        }
        $blog = (int) $blog_id;
        $id = (int) $r['ct_id'];
        $code = (string) ($r['ct_code'] ?? '') ?: ('CT#' . $id);
        $received = (int) ($r['status'] ?? 0) === 1 || (int) ($r['approved'] ?? 0) === 1;
        $doc = [
            'docType'        => 'cash_transfer',
            'docKey'         => 'btsoft:' . $blog . ':cash_transfer:' . $id,
            'branchCode'     => $from,
            'toBranchCode'   => $to,
            'docNo'          => $code,
            'docDate'        => self::day($r['transfer_date'] ?? ''),
            'description'    => trim((string) ($r['description'] ?? '')) ?: ('Chuyển tiền ' . $from . ' → ' . $to),
            'fromMethodCode' => 'TM',
            'toMethodCode'   => 'TM',
            'status'         => $received ? 'received' : 'sent',
            'sourceSystem'   => 'btsoft',
            'sourceRef'      => ['type' => 'cash_transfer', 'id' => (string) $id, 'label' => 'Phiếu chuyển tiền'],
            'totals'         => ['total' => $amount],
            'extra'          => ['blogId' => $blog, 'ctId' => $id, 'approvedBy' => (string) ($r['approved_by'] ?? '')],
        ];
        foreach (['nh_code_from' => 'fromBankCode', 'nh_code_to' => 'toBankCode', 'reason_code' => 'reasonCode', 'recv_reason_code' => 'toReasonCode'] as $col => $field) {
            $v = trim((string) ($r[$col] ?? ''));
            if ($v !== '') {
                $doc[$field] = $v;
            }
        }
        return $doc;
    }

    public static function payment(array $p, $blog_id, $shop_code)
    {
        $amount = abs(round((float) ($p['amount'] ?? 0)));
        if ($amount == 0.0 || $shop_code === '') {
            return null;
        }
        $refund = (int) $p['type'] === 8;
        $blog = (int) $blog_id;
        $parent = $refund ? 'btsoft:' . $blog . ':return:' . (int) $p['parent_id'] : 'btsoft:' . $blog . ':sale:' . (int) $p['parent_id'];
        $code = (string) ($p['code'] ?? '') ?: ('#' . (int) $p['id']);
        $doc = [
            'docType'      => 'customer_payment',
            'docKey'       => 'btsoft:' . $blog . ($refund ? ':refund:' : ':receipt:') . (int) $p['id'],
            'branchCode'   => (string) $shop_code,
            'docNo'        => $code,
            'docDate'      => self::day($p['day'] ?? '') ?: self::day($p['created_at'] ?? ''),
            'description'  => ($refund ? 'Chi trả tiền khách' : 'Thu tiền khách hàng') . ($p['method_label'] !== '' ? ' (' . $p['method_label'] . ')' : ''),
            'methodCode'   => self::method_code($p['method'], $p['method_label'] ?? ''),
            'direction'    => $refund ? 'out' : 'in',
            'sourceSystem' => 'btsoft',
            'sourceRef'    => ['type' => $refund ? 'refund_payment' : 'sale_receipt', 'id' => (string) (int) $p['id'], 'label' => $refund ? 'Phiếu chi trả khách' : 'Phiếu thu'],
            'lines'        => [['lineKey' => 'l1', 'amount' => $amount, 'invoiceDocKey' => $parent]],
            'totals'       => ['total' => $amount],
            'extra'        => ['blogId' => $blog, 'ledgerId' => (int) $p['id'], 'parentId' => (int) $p['parent_id']],
        ];
        $phone = trim((string) ($p['phone'] ?? ''));
        if ($phone !== '') {
            $doc['partner'] = ['code' => $phone]; // tên để trống: bên kế toán giữ tên lấy từ phiếu bán
        }
        $employee = self::employee(['user_id' => (int) ($p['user_id'] ?? 0)]);
        if ($employee) {
            $doc['employee'] = $employee;
        }
        return $doc;
    }
}
