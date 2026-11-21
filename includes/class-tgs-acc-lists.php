<?php
/**
 * DANH MỤC BÊN KẾ TOÁN — đọc các danh mục của phần mềm kế toán (lý do, hình thức thanh toán, loại thuế, chi nhánh,
 * bộ phận, khoản mục phí, nhóm hạch toán mặt hàng, hệ thống tài khoản) để bên bán hàng gửi chứng từ đúng mã.
 *
 * Dùng trong code:
 *   TGS_Acc_Lists::rows('reasons')            → [['code' => 'XBA', 'name' => '…', …], …] hoặc WP_Error
 *   TGS_Acc_Lists::rows('payment_methods', true)  → bỏ qua bản nhớ, đọc lại ngay
 *   TGS_Acc_Lists::kinds()                    → danh sách loại danh mục + cột + trường chứng từ dùng mã đó
 * Xem trên web: Cổng kế toán → tab "Danh mục kế toán".
 *
 * Chỉ ĐỌC (GET) bằng khoá API của Cổng kế toán (phạm vi documents:write đã gồm quyền đọc danh mục). Kết quả nhớ ở site
 * option 10 phút cho đỡ gọi lặp; sửa danh mục thì sửa bên phần mềm kế toán, không sửa ở đây.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Lists
{
    const CACHE_OPTION = 'tgs_acc_lists_cache';
    const TTL = 600;

    /**
     * Mỗi loại: label, path (API bên kế toán), use (chứng từ dùng mã này ở trường nào), cols (khoá → tên cột),
     * map (tuỳ chọn: chuyển giá trị để hiển thị).
     */
    public static function kinds()
    {
        $yes = static function ($v) {
            return $v ? 'Có' : '';
        };
        $active = static function ($v) {
            return $v === false ? 'Ngưng dùng' : '';
        };
        return [
            'reasons' => [
                'label' => 'Lý do (xuất / nhập / thu / chi)',
                'path'  => '/api/v1/posting/reasons',
                'use'   => 'reasonCode của chứng từ — chỉ là nhãn để lọc báo cáo, không đổi tài khoản (tài khoản theo Quy tắc hạch toán ACC41).',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'kind' => 'Loại', 'isActive' => 'Trạng thái'],
                'map'   => [
                    'kind'     => static function ($v) {
                        $k = ['out' => 'Xuất', 'in' => 'Nhập', 'receipt' => 'Thu', 'payment' => 'Chi'];
                        return $k[$v] ?? (string) $v;
                    },
                    'isActive' => $active,
                ],
            ],
            'payment_methods' => [
                'label' => 'Hình thức thanh toán',
                'path'  => '/api/v1/posting/payment-methods',
                'use'   => 'methodCode của phiếu thu / chi. Tên lạ thì kế toán đổi mã ở cột "Đổi hình thức" của kết nối.',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'accountCode' => 'Tài khoản', 'isReceivable' => 'Ghi công nợ', 'isActive' => 'Trạng thái'],
                'map'   => ['isReceivable' => $yes, 'isActive' => $active],
            ],
            'tax_codes' => [
                'label' => 'Loại thuế',
                'path'  => '/api/v1/tax-codes',
                'use'   => 'taxCode từng dòng hàng: mã R… (đầu ra) cho bán / hoàn; mã V… (đầu vào) cho mua / trả NCC.',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'rate' => 'Thuế suất %', 'direction' => 'Chiều', 'accountCode' => 'Tài khoản', 'isActive' => 'Trạng thái'],
                'map'   => [
                    'direction' => static function ($v) {
                        return $v === 'output' ? 'Đầu ra' : ($v === 'input' ? 'Đầu vào' : (string) $v);
                    },
                    'isActive'  => $active,
                ],
            ],
            'branches' => [
                'label' => 'Chi nhánh',
                'path'  => '/api/v1/branches',
                'use'   => 'branchCode = mã shop (tgs_site_code). Mã tham chiếu dùng khi mã shop khác mã chi nhánh.',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'accountingUnitCode' => 'Đơn vị hạch toán', 'externalRef' => 'Mã tham chiếu', 'departmentCode' => 'Bộ phận'],
            ],
            'warehouses' => [
                'label' => 'Kho',
                'path'  => '/api/v1/warehouses',
                'use'   => 'lines[].warehouseCode = mã kho của đúng chi nhánh. Ô chọn phân kho của website chép từ đây; mã lạ thì cầu nối bỏ, kế toán ghi vào kho mặc định.',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'branchCode' => 'Chi nhánh', 'isDefault' => 'Mặc định', 'isActive' => 'Trạng thái'],
                'map'   => ['isDefault' => $yes, 'isActive' => $active],
            ],
            'departments' => [
                'label' => 'Bộ phận',
                'path'  => '/api/v1/dimensions/department',
                'use'   => 'Mã bộ phận (chiều phân tích) trên dòng chi phí / doanh thu.',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'parentCode' => 'Mã cha', 'isActive' => 'Trạng thái'],
                'map'   => ['isActive' => $active],
            ],
            'cost_items' => [
                'label' => 'Khoản mục phí',
                'path'  => '/api/v1/dimensions/cost_item',
                'use'   => 'Mã khoản mục trên phiếu chi / chi phí.',
                'cols'  => ['code' => 'Mã', 'name' => 'Tên', 'parentCode' => 'Mã cha', 'isActive' => 'Trạng thái'],
                'map'   => ['isActive' => $active],
            ],
            // [2026-11-15 Nguyễn Văn Thủy] nhóm hạch toán mặt hàng không còn quyết tài khoản → thay bằng Mục đích xuất (đóng góp)
            'purposes' => [
                'label' => 'Mục đích xuất',
                'path'  => '/api/v1/posting/purposes',
                'use'   => 'purposeCode của phiếu xuất kho không bán / nhập kho khác: quyết tài khoản đối ứng. Cầu nối đổi từ lý do xuất của shop (TGS_Acc_Mapper::PURPOSE_OF_REASON).',
                'cols'  => ['code' => 'Mã', 'name' => 'Mục đích', 'direction' => 'Dùng cho', 'accountCode' => 'TK đối ứng', 'isActive' => 'Trạng thái'],
                'map'   => [
                    'direction' => static function ($v) {
                        $k = ['decrease' => 'Giảm kho', 'increase' => 'Tăng kho', 'both' => 'Cả hai'];
                        return $k[$v] ?? $v;
                    },
                    'isActive'  => $active,
                ],
            ],
            'accounts' => [
                'label' => 'Hệ thống tài khoản',
                'path'  => '/api/v1/accounts',
                'use'   => 'Tham khảo: tài khoản nào bắt buộc theo dõi đối tượng / bộ phận / khoản mục.',
                'cols'  => ['code' => 'Số TK', 'name' => 'Tên', 'isPosting' => 'Chi tiết', 'trackPartner' => 'Đối tượng', 'trackDepartment' => 'Bộ phận', 'trackCostItem' => 'Khoản mục'],
                'map'   => ['isPosting' => $yes, 'trackPartner' => $yes, 'trackDepartment' => $yes, 'trackCostItem' => $yes],
            ],
        ];
    }

    /**
     * Dòng danh mục (đã chuyển giá trị để hiển thị), nhớ 10 phút.
     * @return array|WP_Error
     */
    public static function rows($kind, $fresh = false)
    {
        $kinds = self::kinds();
        if (!isset($kinds[$kind])) {
            return new WP_Error('tgs_acc_list', 'Không có danh mục ' . $kind);
        }
        $cache = (array) get_site_option(self::CACHE_OPTION, []);
        if (!$fresh && isset($cache[$kind]['at']) && time() - (int) $cache[$kind]['at'] < self::TTL) {
            return (array) $cache[$kind]['rows'];
        }
        if (TGS_Acc_Config::key() === '') {
            return new WP_Error('tgs_acc_list', 'Chưa có khoá API (Cổng kế toán → Cài đặt).');
        }
        $res = (new TGS_Acc_Api_Client())->get($kinds[$kind]['path']);
        if (is_wp_error($res)) {
            return $res;
        }
        $def = $kinds[$kind];
        $map = $def['map'] ?? [];
        $rows = [];
        foreach ((array) $res['data'] as $x) {
            $r = [];
            foreach (array_keys($def['cols']) as $k) {
                $v = $x[$k] ?? null;
                $r[$k] = isset($map[$k]) ? $map[$k]($v) : (is_scalar($v) || $v === null ? (string) $v : wp_json_encode($v));
            }
            $rows[] = $r;
        }
        $cache[$kind] = ['at' => time(), 'rows' => $rows];
        update_site_option(self::CACHE_OPTION, $cache);
        return $rows;
    }

    /** Thời điểm đọc gần nhất của một danh mục (0 = chưa đọc). */
    public static function fetched_at($kind)
    {
        $cache = (array) get_site_option(self::CACHE_OPTION, []);
        return (int) ($cache[$kind]['at'] ?? 0);
    }
}
