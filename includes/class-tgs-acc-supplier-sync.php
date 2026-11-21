<?php
/**
 * NHÀ CUNG CẤP LẤY TỪ PHẦN MỀM KẾ TOÁN (21/10/2026).
 *
 * Danh mục Nhà cung cấp chỉ khai ở MỘT chỗ: phần mềm kế toán (Danh mục → Nhà cung cấp, DM13 — đối tượng loại NCC).
 * Trang quản trị không tự khai NCC nữa; bảng wp_global_supplier (nơi mọi màn chọn NCC đang đọc: tạo phiếu nhập kho, trả
 * NCC…) là BẢN SAO đồng bộ từ đó, GIỮ ĐÚNG MÃ bên kế toán → phiếu đẩy sang kế toán khớp đúng đối tượng.
 *
 *   - Lấy mọi đối tượng kind=supplier đang dùng (GET /api/v1/partners?kind=supplier&status=active), thêm / cập nhật theo mã.
 *   - NCC chỉ có ở trang quản trị (kế toán không có / đã ngừng) → supplier_status = 0: ẩn khỏi ô chọn, KHÔNG xoá (phiếu cũ
 *     vẫn mở được). Chỉ ẩn khi lấy đủ danh sách (đủ số dòng kế toán báo).
 *   - Chạy: mỗi giờ (cron), khi mở form có ô chọn NCC mà lần đồng bộ trước đã quá 10 phút, và nút "Tải lại từ kế toán"
 *     trong bảng chọn NCC (AJAX tgs_acc_sync_suppliers — trả danh sách để bảng cập nhật ngay, không tải lại trang).
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Supplier_Sync
{
    const LAST = 'tgs_acc_supplier_sync_at';
    const CRON = 'tgs_acc_supplier_sync';
    const STALE = 600; // giây

    public static function init()
    {
        add_action(self::CRON, [__CLASS__, 'sync']);
        add_action('init', static function () {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 300, 'hourly', self::CRON);
            }
        });
        add_action('wp_ajax_tgs_acc_sync_suppliers', [__CLASS__, 'ajax_sync']);
    }

    private static function table()
    {
        global $wpdb;
        return defined('TGS_TABLE_GLOBAL_SUPPLIER') ? TGS_TABLE_GLOBAL_SUPPLIER : $wpdb->base_prefix . 'global_supplier';
    }

    /** Đồng bộ nếu lần trước đã cũ (gọi trước khi dựng ô chọn NCC). */
    public static function maybe_sync()
    {
        if (time() - (int) get_site_option(self::LAST, 0) > self::STALE) {
            self::sync();
        }
    }

    /**
     * Kéo NCC từ kế toán vào wp_global_supplier.
     * @return array|WP_Error ['added' => n, 'updated' => n, 'hidden' => n, 'total' => n]
     */
    public static function sync()
    {
        if (!class_exists('TGS_Acc_Api_Client') || TGS_Acc_Config::url() === '' || TGS_Acc_Config::key() === '') {
            return new WP_Error('tgs_acc_not_ready', 'Chưa cấu hình kết nối phần mềm kế toán.');
        }
        // Đánh dấu ngay để các request đồng thời không cùng kéo.
        update_site_option(self::LAST, time());
        $res = (new TGS_Acc_Api_Client())->get('/api/v1/partners', ['kind' => 'supplier', 'status' => 'active', 'page' => 1, 'pageSize' => 100000]);
        if (is_wp_error($res)) {
            return $res;
        }
        $rows = (array) ($res['data'] ?? []);
        $total = (int) ($res['meta']['total'] ?? count($rows));

        global $wpdb;
        $T = self::table();
        $existing = [];
        foreach ((array) $wpdb->get_results("SELECT supplier_id, supplier_code, supplier_name, supplier_phone, supplier_email, supplier_address, supplier_tax_code, supplier_contact, supplier_status, is_deleted FROM {$T}", ARRAY_A) as $r) {
            $existing[(string) $r['supplier_code']] = $r;
        }
        $now = current_time('mysql');
        $seen = [];
        $added = 0;
        $updated = 0;
        foreach ($rows as $p) {
            $p = (array) $p;
            $code = trim((string) ($p['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $seen[$code] = true;
            $data = [
                'supplier_name'     => mb_substr((string) (($p['name'] ?? '') ?: ($p['companyName'] ?? '') ?: $code), 0, 255),
                'supplier_phone'    => mb_substr((string) (($p['mobile'] ?? '') ?: ($p['phone'] ?? '')), 0, 50),
                'supplier_email'    => mb_substr((string) ($p['email'] ?? ''), 0, 255),
                'supplier_address'  => (string) (($p['address'] ?? '') ?: ($p['companyAddress'] ?? '')),
                'supplier_tax_code' => mb_substr((string) ($p['taxCode'] ?? ''), 0, 50),
                'supplier_contact'  => mb_substr((string) ($p['contactName'] ?? ''), 0, 255),
                'supplier_status'   => 1,
                'is_deleted'        => 0,
            ];
            $cur = $existing[$code] ?? null;
            if (!$cur) {
                $wpdb->insert($T, $data + ['supplier_code' => $code, 'created_at' => $now, 'updated_at' => $now]);
                $added++;
                continue;
            }
            $changed = false;
            foreach ($data as $k => $v) {
                if ((string) $cur[$k] !== (string) $v) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $wpdb->update($T, $data + ['updated_at' => $now, 'deleted_at' => null], ['supplier_id' => (int) $cur['supplier_id']]);
                $updated++;
            }
        }
        // Ẩn NCC không còn bên kế toán — chỉ khi đã lấy đủ danh sách.
        $hidden = 0;
        if ($rows && count($rows) >= $total) {
            foreach ($existing as $code => $cur) {
                if (!isset($seen[$code]) && (int) $cur['supplier_status'] === 1) {
                    $wpdb->update($T, ['supplier_status' => 0, 'updated_at' => $now], ['supplier_id' => (int) $cur['supplier_id']]);
                    $hidden++;
                }
            }
        }
        update_site_option(self::LAST, time());
        return ['added' => $added, 'updated' => $updated, 'hidden' => $hidden, 'total' => count($rows)];
    }

    /** Danh sách NCC đang dùng, cùng dạng dữ liệu ô chọn NCC của form phiếu. */
    public static function picker_rows()
    {
        global $wpdb;
        $T = self::table();
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT supplier_id, supplier_code, supplier_name, supplier_phone, supplier_email, supplier_address, supplier_tax_code, supplier_contact FROM {$T} WHERE is_deleted = 0 AND supplier_status = 1 ORDER BY supplier_name", ARRAY_A) as $r) {
            $out[] = [
                'id' => (int) $r['supplier_id'], 'code' => $r['supplier_code'], 'name' => $r['supplier_name'], 'product_count' => 0,
                'phone' => (string) $r['supplier_phone'], 'email' => (string) $r['supplier_email'], 'address' => (string) $r['supplier_address'],
                'tax_code' => (string) $r['supplier_tax_code'], 'contact' => (string) $r['supplier_contact'], 'company' => '',
            ];
        }
        return $out;
    }

    public static function ajax_sync()
    {
        check_ajax_referer('tgs_acc_sync_suppliers', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Chưa đăng nhập.'], 403);
        }
        $r = self::sync();
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success(['stat' => $r, 'items' => self::picker_rows()]);
    }
}
