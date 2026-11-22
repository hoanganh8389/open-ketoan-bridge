<?php
/**
 * PHÂN KHO LẤY TỪ PHẦN MỀM KẾ TOÁN (12/11/2026).
 *
 * Danh mục kho chỉ khai ở MỘT chỗ: phần mềm kế toán (Danh mục → Kho, DM12 — mỗi kho thuộc một chi nhánh, có kho mặc định).
 * Trang quản trị không tự khai phân kho nữa:
 *   - bảng phân kho của từng website (wp_N_local_warehouse_zone — ô chọn phân kho ở phiếu) là BẢN SAO các kho đang dùng của
 *     chi nhánh nối với website đó (TGS_Acc_Source::shop_code), giữ ĐÚNG MÃ bên kế toán; kho mặc định xếp đầu;
 *   - kho không còn bên kế toán → is_deleted = 1 (ẩn khỏi ô chọn, phiếu cũ vẫn giữ mã). Chỉ ẩn khi lấy được danh sách.
 *
 * Gửi chứng từ (normalize): mã kho trên dòng phải là kho của đúng chi nhánh của chứng từ bên kế toán; mã lạ (mã site, phân
 * kho cũ của trang quản trị…) thì BỎ để kế toán ghi vào kho mặc định của chi nhánh. Không đoán mã kho.
 *
 *   - Chạy: mỗi giờ (cron), khi mở ô chọn phân kho mà lần trước đã quá 10 phút, nút tải lại (AJAX tgs_acc_sync_warehouses).
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Warehouse_Sync
{
    const MAP = 'tgs_acc_warehouse_map'; // site option: ['at' => ts, 'branches' => [mã chi nhánh => [mã kho => ['name', 'default']]]]
    const CRON = 'tgs_acc_warehouse_sync';
    const STALE = 600; // giây

    /** Chứng từ có hàng ra / vào kho. */
    const STOCK_TYPES = ['sale', 'sale_return', 'purchase', 'purchase_return', 'transfer', 'stock_adjustment'];

    public static function init()
    {
        add_action(self::CRON, [__CLASS__, 'sync']);
        add_action('init', static function () {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 300, 'hourly', self::CRON);
            }
        });
        add_action('wp_ajax_tgs_acc_sync_warehouses', [__CLASS__, 'ajax_sync']);
    }

    /**
     * Có kết nối kế toán → phân kho do kế toán quản lý (trang quản trị chỉ xem / chọn).
     * OKT-H2: theo kết nối của blog đang xem (I-1), không theo cấu hình site chính. Khi có nguồn danh mục theo blog
     * (tgs_catalog_source, I-2 / D001-19) thì blog `local` phải trả false ở đây và không bị sync() ghi đè phân kho.
     */
    public static function ready($blog_id = 0)
    {
        $blog_id = (int) $blog_id ?: (function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0);
        if (!class_exists('TGS_Acc_Api_Client')) {
            return false;
        }
        return $blog_id > 0 && method_exists('TGS_Acc_Config', 'ready_for') ? TGS_Acc_Config::ready_for($blog_id) : (TGS_Acc_Config::url() !== '' && TGS_Acc_Config::key() !== '');
    }

    /**
     * Danh mục kho bên kế toán theo chi nhánh (nhớ ở site option, cũ quá 10 phút thì tải lại).
     * Tải lỗi mà còn bản nhớ cũ → dùng bản cũ. @return array|WP_Error [mã chi nhánh => [mã kho => ['name' => …, 'default' => bool]]]
     */
    public static function branches($fresh = false, $blog_id = 0)
    {
        // [2026-11-22 06:30 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — đệm THEO KẾT NỐI của blog (URL + khoá: một blog = một tenant, I-1), danh sách
        // rỗng (tenant chưa có kho) vẫn là đệm hợp lệ, tải hỏng thì nhớ 60 s. Trước đây: một đệm chung cả mạng tải bằng khoá của
        // ngữ cảnh hiện tại; danh sách rỗng coi như chưa có → MỌI lần gửi gọi /warehouses; kế toán treo → mỗi lần chờ 10 s.
        $blog_id = (int) $blog_id;
        $client = $blog_id > 0 ? TGS_Acc_Api_Client::for_blog($blog_id) : new TGS_Acc_Api_Client();
        $key = $blog_id > 0 ? self::MAP . '_' . $client->identity() : self::MAP;
        $cached = (array) get_site_option($key, []);
        $has = array_key_exists('branches', $cached);
        if (!$fresh && $has && time() - (int) ($cached['at'] ?? 0) <= self::STALE) {
            return (array) $cached['branches'];
        }
        if (!$fresh && time() - (int) ($cached['fail_at'] ?? 0) < 60) {
            return $has ? (array) $cached['branches'] : new WP_Error('tgs_acc_wh_recent_fail', 'Vừa tải danh mục kho không được — thử lại sau 60 s');
        }
        $map = self::fetch($client, $key);
        if (is_wp_error($map)) {
            update_site_option($key, ['fail_at' => time()] + $cached);
            $d = $map->get_error_data();
            if ($blog_id > 0 && class_exists('TGS_Acc_Events') && (!is_array($d) || empty($d['status']))) {
                TGS_Acc_Events::trip($blog_id, 'Danh mục kho: ' . $map->get_error_message()); // đường truyền hỏng → mở cầu dao luôn
            }
            return $has ? (array) $cached['branches'] : $map;
        }
        return $map;
    }

    /** Mã kho mặc định bên kế toán của chi nhánh nối với website ('' nếu chưa có / không tải được). */
    public static function default_code($blog_id)
    {
        $map = self::branches(false, (int) $blog_id);
        if (is_wp_error($map)) {
            return '';
        }
        foreach ($map[TGS_Acc_Source::shop_code((int) $blog_id)] ?? [] as $code => $w) {
            if ($w['default']) {
                return (string) $code;
            }
        }
        return '';
    }

    /** Tải danh mục kho từ kế toán (chỉ kho đang dùng) và nhớ lại. */
    private static function fetch($client = null, $key = self::MAP)
    {
        $client = $client ?: new TGS_Acc_Api_Client();
        if (!$client->ready()) {
            return new WP_Error('tgs_acc_not_ready', 'Chưa cấu hình kết nối phần mềm kế toán.');
        }
        $res = $client->get('/api/v1/warehouses');
        if (is_wp_error($res)) {
            return $res;
        }
        $map = [];
        foreach ((array) ($res['data'] ?? []) as $w) {
            $w = (array) $w;
            $code = trim((string) ($w['code'] ?? ''));
            $branch = trim((string) ($w['branchCode'] ?? ''));
            if ($code === '' || $branch === '' || empty($w['isActive'])) {
                continue;
            }
            $map[$branch][$code] = ['name' => (string) ($w['name'] ?? ''), 'default' => !empty($w['isDefault'])];
        }
        update_site_option($key, ['at' => time(), 'branches' => $map]);
        return $map;
    }

    /**
     * Chuẩn hoá mã kho trên dòng các chứng từ có hàng: giữ mã là kho của đúng chi nhánh chứng từ bên kế toán, bỏ mã lạ
     * (→ kho mặc định của chi nhánh bên kế toán). Không lấy được danh mục kho → bỏ hết mã kho (lần gửi sau có danh mục,
     * mã băm đổi, cầu nối tự gửi phiên bản mới đúng kho).
     */
    public static function normalize(array &$docs)
    {
        $map = self::branches(false, (int) ($docs[0]['extra']['blogId'] ?? 0)); // OKT-CAU-DAO — kho theo kết nối của blog có phiếu
        if (is_wp_error($map)) {
            $map = [];
        }
        foreach ($docs as &$d) {
            if (!in_array($d['docType'] ?? '', self::STOCK_TYPES, true) || empty($d['lines'])) {
                continue;
            }
            $valid = $map[(string) ($d['branchCode'] ?? '')] ?? [];
            foreach ($d['lines'] as &$l) {
                $code = trim((string) ($l['warehouseCode'] ?? ''));
                if ($code !== '' && isset($valid[$code])) {
                    $l['warehouseCode'] = $code;
                } else {
                    unset($l['warehouseCode']);
                }
            }
            unset($l);
        }
        unset($d);
    }

    /**
     * Chép kho của từng chi nhánh vào bảng phân kho của website nối với chi nhánh đó.
     * @param int[] $blog_ids trống = mọi website. @return array|WP_Error ['sites' => n, 'added' => n, 'updated' => n, 'hidden' => n]
     */
    public static function sync(array $blog_ids = [])
    {
        // [2026-11-22 07:30 PM Johnny Chu - Chu Hoàng Anh] OKT-H2 — mỗi blog tải danh mục kho bằng KẾT NỐI CỦA CHÍNH NÓ (I-1). Trước đây:
        // tải một lần bằng khoá của ngữ cảnh đang chạy (cron = site chính) rồi chép cho MỌI website → shop nối tenant khác nhận kho
        // của tenant site chính. Blog chưa có kết nối thì bỏ qua; một blog tải hỏng không chặn blog khác, không ẩn phân kho của nó.
        $one = count($blog_ids) === 1;
        if (!$blog_ids) {
            $blog_ids = is_multisite() ? array_map('intval', get_sites(['fields' => 'ids', 'number' => 0])) : [get_current_blog_id()];
        }
        global $wpdb;
        $sum = ['sites' => 0, 'added' => 0, 'updated' => 0, 'hidden' => 0, 'failed' => 0];
        $now = current_time('mysql');
        foreach ($blog_ids as $blog) {
            $blog = (int) $blog;
            $branch = TGS_Acc_Source::shop_code($blog);
            $T = $wpdb->get_blog_prefix($blog) . 'local_warehouse_zone';
            if ($branch === '' || !TGS_Acc_Config::ready_for($blog) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $T))) {
                continue;
            }
            $map = self::branches(true, $blog);
            if (is_wp_error($map)) {
                if ($one) {
                    return $map;
                }
                $sum['failed']++;
                continue;
            }
            $sum['sites']++;
            $want = $map[$branch] ?? [];
            $have = [];
            foreach ((array) $wpdb->get_results("SELECT id, zone_code, zone_name, sort_order, is_deleted FROM {$T}", ARRAY_A) as $r) {
                $have[(string) $r['zone_code']] = $r;
            }
            foreach ($want as $code => $w) {
                // Kho mặc định xếp đầu: form phiếu mới tự chọn phân kho đầu danh sách.
                $data = ['zone_name' => mb_substr($w['name'], 0, 255), 'sort_order' => $w['default'] ? 100 : 10, 'is_deleted' => 0];
                $cur = $have[$code] ?? null;
                if (!$cur) {
                    $wpdb->insert($T, $data + ['zone_code' => $code, 'created_at' => $now, 'updated_at' => $now]);
                    $sum['added']++;
                } elseif ((string) $cur['zone_name'] !== $data['zone_name'] || (int) $cur['sort_order'] !== $data['sort_order'] || (int) $cur['is_deleted'] !== 0) {
                    $wpdb->update($T, $data + ['updated_at' => $now], ['id' => (int) $cur['id']]);
                    $sum['updated']++;
                }
            }
            foreach ($have as $code => $cur) {
                if (!isset($want[$code]) && (int) $cur['is_deleted'] === 0) {
                    $wpdb->update($T, ['is_deleted' => 1, 'updated_at' => $now], ['id' => (int) $cur['id']]);
                    $sum['hidden']++;
                }
            }
            update_blog_option($blog, self::MAP . '_at', time());
        }
        return $sum;
    }

    /** Đồng bộ website hiện tại nếu lần trước đã cũ (gọi trước khi trả danh sách phân kho cho ô chọn). */
    public static function maybe_sync()
    {
        $blog = get_current_blog_id();
        if (self::ready($blog) && time() - (int) get_blog_option($blog, self::MAP . '_at', 0) > self::STALE) {
            self::sync([$blog]);
        }
    }

    /** Nút "Tải lại từ kế toán": đồng bộ website hiện tại ngay. */
    public static function ajax_sync()
    {
        check_ajax_referer('tgs_acc_sync_warehouses', 'nonce');
        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        $r = self::sync([get_current_blog_id()]);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success($r);
    }
}
