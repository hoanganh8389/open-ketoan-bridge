<?php
/**
 * CHI NHÁNH BÊN KẾ TOÁN TỰ CÓ KHI GẮN SITE (14/11/2026).
 *
 * Trước đây chi nhánh bên phần mềm kế toán chỉ được tạo khi site gửi chứng từ ĐẦU TIÊN (TGS_Acc_Pusher → ensure_branch):
 * site mới / site chưa từng gửi phiếu thì kế toán không biết tới, mọi màn chọn chi nhánh bên kế toán thiếu shop đó.
 * Lớp này tạo chi nhánh NGAY khi site có mã chi nhánh:
 *
 *   - gắn site ở Cấu hình phân cấp multisite (tuỳ chọn mạng tgs_multisite_hierarchy_data — nơi site nhận mã chi nhánh)
 *     → hẹn chạy sau 30 giây cho mọi site (một lần, gộp nhiều lần lưu liền nhau);
 *   - tạo site mới → hẹn chạy sau 60 giây cho site đó (site mới thường CHƯA có mã: bỏ qua, chờ gắn phân cấp);
 *   - nút "Đồng bộ chi nhánh từ các site" ở Cổng kế toán (Cài đặt) → chạy ngay, hiện kết quả từng site.
 *
 * Mã chi nhánh = TGS_Acc_Source::branch_of_blog (phân cấp), không có thì mã site của tgs-bc-tk. Site chỉ có TÊN (chưa có mã)
 * thì BỎ QUA — không tạo chi nhánh mang tên site (rác). Tên chi nhánh: tgs_shop_name, không có thì tên site.
 * Tải danh sách chi nhánh MỘT lần cho cả lượt, chỉ POST mã còn thiếu (đồng bộ hàng trăm site không gọi hàng trăm lần GET).
 * Khoá API cần phạm vi catalog:write. Tạo xong thì đồng bộ phân kho (TGS_Acc_Warehouse_Sync) cho các site đó.
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Branch_Sync
{
    /** Cùng bộ nhớ "mã đã có bên kế toán" của TGS_Acc_Pusher (sổ nội bộ) — gửi chứng từ sau đó không phải hỏi lại. */
    const KNOWN = 'tgs_acc_known_branches';
    const EVENT_SITE = 'tgs_acc_branch_sync_site';
    const EVENT_ALL = 'tgs_acc_branch_sync_all';
    const LAST = 'tgs_acc_branch_sync_last'; // site option: kết quả lượt gần nhất (để xem lại ở Cổng kế toán)

    public static function init()
    {
        add_action(self::EVENT_SITE, [__CLASS__, 'run_site']);
        add_action(self::EVENT_ALL, [__CLASS__, 'run_all']);
        add_action('wp_initialize_site', [__CLASS__, 'on_new_site'], 20, 1);
        $opt = defined('TGS_HIERARCHY_OPTION_KEY') ? TGS_HIERARCHY_OPTION_KEY : 'tgs_multisite_hierarchy_data';
        add_action('update_site_option_' . $opt, [__CLASS__, 'on_hierarchy'], 10, 0);
        add_action('add_site_option_' . $opt, [__CLASS__, 'on_hierarchy'], 10, 0);
        add_action('wp_ajax_tgs_acc_sync_branches', [__CLASS__, 'ajax_sync']);
    }

    public static function ready()
    {
        return class_exists('TGS_Acc_Api_Client') && TGS_Acc_Config::url() !== '' && TGS_Acc_Config::key() !== '';
    }

    /** Site mới: hẹn đồng bộ riêng site đó (để các plugin khác kịp đặt tuỳ chọn của site). */
    public static function on_new_site($site)
    {
        $blog = is_object($site) ? (int) $site->blog_id : (int) $site;
        if ($blog > 0 && self::ready()) {
            wp_schedule_single_event(time() + 60, self::EVENT_SITE, [$blog]);
        }
    }

    /** Lưu cấu hình phân cấp: hẹn một lượt cho mọi site (lưu nhiều lần liền nhau chỉ chạy một lượt). */
    public static function on_hierarchy()
    {
        if (self::ready() && !wp_next_scheduled(self::EVENT_ALL)) {
            wp_schedule_single_event(time() + 30, self::EVENT_ALL);
        }
    }

    public static function run_site($blog_id)
    {
        self::sync([(int) $blog_id]);
    }

    public static function run_all()
    {
        self::sync();
    }

    /** Mã chi nhánh bên kế toán của site; '' khi site chưa có mã (chỉ có tên). */
    public static function code_of($blog_id)
    {
        $mapped = TGS_Acc_Source::branch_of_blog((int) $blog_id);
        if ($mapped !== '') {
            return $mapped;
        }
        if (class_exists('TGS_BCTK_Sites')) {
            foreach (TGS_BCTK_Sites::list_sites() as $s) {
                if ((int) $s['blog_id'] === (int) $blog_id) {
                    return trim((string) ($s['code'] ?? ''));
                }
            }
        }
        return '';
    }

    public static function name_of($blog_id)
    {
        return trim((string) TGS_Acc_Site::opt((int) $blog_id, 'tgs_shop_name', '')) ?: (string) TGS_Acc_Site::opt((int) $blog_id, 'blogname', '');
    }

    /**
     * Bảo đảm mỗi site có chi nhánh bên kế toán.
     * @param int[] $blog_ids trống = mọi site (trừ site gốc).
     * @return array|WP_Error ['rows' => [['blog','code','name','status','message']], 'created' => n, 'exists' => n, 'skipped' => n, 'errors' => n]
     *   status: created | exists | skipped (chưa có mã) | error
     */
    public static function sync(array $blog_ids = [])
    {
        if (!self::ready()) {
            return new WP_Error('tgs_acc_not_ready', 'Chưa cấu hình kết nối phần mềm kế toán (địa chỉ + khoá API).');
        }
        $client = new TGS_Acc_Api_Client();
        $list = $client->get('/api/v1/branches');
        if (is_wp_error($list)) {
            return $list;
        }
        $have = [];
        foreach ((array) $list['data'] as $b) {
            $have[strtoupper((string) (((array) $b)['code'] ?? ''))] = true;
        }
        if (!$blog_ids) {
            $blog_ids = TGS_Acc_Site::blogs(false);
            $main = function_exists('get_main_site_id') ? (int) get_main_site_id() : 1;
            $blog_ids = array_values(array_filter($blog_ids, static function ($b) use ($main) { return $b !== $main; }));
        }
        $known = (array) get_site_option(self::KNOWN, []);
        $out = ['rows' => [], 'created' => 0, 'exists' => 0, 'skipped' => 0, 'errors' => 0];
        $touched = [];
        foreach ($blog_ids as $blog) {
            if (!TGS_Acc_Site::exists($blog)) {
                continue;
            }
            $code = self::code_of($blog);
            $name = self::name_of($blog);
            $row = ['blog' => (int) $blog, 'code' => $code, 'name' => $name, 'status' => '', 'message' => ''];
            if ($code === '') {
                $row['status'] = 'skipped';
                $row['message'] = 'Site chưa có mã chi nhánh — gắn ở Cấu hình phân cấp multisite';
                $out['skipped']++;
            } elseif (isset($have[strtoupper($code)])) {
                $row['status'] = 'exists';
                $known[$code] = 1;
                $out['exists']++;
                $touched[] = (int) $blog;
            } else {
                $res = $client->post_json('/api/v1/branches', array_filter(['code' => $code, 'name' => $name !== '' ? $name : $code, 'externalRef' => 'blog:' . (int) $blog]));
                if (is_wp_error($res)) {
                    $row['status'] = 'error';
                    $row['message'] = $res->get_error_message();
                    $out['errors']++;
                } else {
                    $row['status'] = 'created';
                    $have[strtoupper($code)] = true;
                    $known[$code] = 1;
                    $out['created']++;
                    $touched[] = (int) $blog;
                }
            }
            $out['rows'][] = $row;
        }
        update_site_option(self::KNOWN, $known);
        // Phân kho của site lấy từ kế toán (DM12): chi nhánh mới chưa khai kho thì site chỉ có kho mặc định bên kế toán.
        if ($touched && class_exists('TGS_Acc_Warehouse_Sync')) {
            TGS_Acc_Warehouse_Sync::sync($touched);
        }
        update_site_option(self::LAST, ['at' => time(), 'created' => $out['created'], 'exists' => $out['exists'], 'skipped' => $out['skipped'], 'errors' => $out['errors']]);
        return $out;
    }

    /** Nút "Đồng bộ chi nhánh từ các site" (Cổng kế toán → Cài đặt). Tạo danh mục bên kế toán → chỉ quản trị cấp cao. */
    public static function ajax_sync()
    {
        check_ajax_referer(TGS_Acc_Admin::NONCE, 'nonce');
        if (!TGS_Acc_Admin::can_configure()) {
            wp_send_json_error(['message' => 'Chỉ quản trị cấp cao đồng bộ được chi nhánh.'], 403);
        }
        $r = self::sync();
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success($r);
    }
}
