<?php
/**
 * Trang "Cổng kế toán" (Quản trị → Kế toán) + nút "Đẩy sang Kế toán" ở màn Quản lý VAT.
 *
 * Quyền:
 *   - XEM trang, xem trước, tra nhật ký: quyền của màn VAT (TGS_BCTK_CAPABILITY) — kế toán dùng được;
 *   - GỬI THẬT: thêm mật khẩu thao tác (như các nút đẩy khác ở màn VAT);
 *   - SỬA CÀI ĐẶT: chỉ quản trị cấp cao.
 * Đổi quyền bằng filter 'tgs_acc_bridge_capability'.
 *
 * Mọi AJAX đi qua guard(): nonce + quyền. Gửi luôn theo TỪNG shop × TỪNG đoạn ngày ngắn
 * (trình duyệt gọi lần lượt) để một lượt gọi không chạy quá lâu.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Admin
{
    const VIEW  = 'acc-bridge';
    const NONCE = 'tgs_acc_bridge';
    const MAX_RANGE_DAYS = 31; // một lượt gọi tối đa 31 ngày của một shop

    public static function init()
    {
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_route']);
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'add_nav_item'], 45, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 30);
        foreach (['overview', 'push', 'log', 'runs', 'sample', 'ping', 'save', 'calls', 'lists', 'events', 'events_retry', 'pause', 'selfcheck', 'trace', 'topology', 'blog_list', 'blog_check', 'blog_save'] as $a) {
            add_action('wp_ajax_tgs_acc_' . $a, [__CLASS__, 'ajax_' . $a]);
        }
        // [2026-11-21 10:15 PM Johnny Chu - Chu Hoàng Anh] S002-13 — blog đổi nguồn phiếu thì làm mới danh sách blog nguồn sổ POS.
        foreach (['update_option_', 'add_option_', 'delete_option_'] as $h) {
            add_action($h . 'tgs_acc_sale_source', [__CLASS__, 'forget_pos_blogs']);
        }
    }

    /* ───────────────────────────── quyền ───────────────────────────── */

    public static function capability()
    {
        $cap = defined('TGS_BCTK_CAPABILITY') ? TGS_BCTK_CAPABILITY : 'manage_network_options';
        return (string) apply_filters('tgs_acc_bridge_capability', $cap);
    }

    public static function can_view()
    {
        return is_super_admin() || current_user_can(self::capability());
    }

    public static function can_configure()
    {
        return is_super_admin() || current_user_can('manage_network_options');
    }

    private static function guard($need = 'view')
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $ok = $need === 'configure' ? self::can_configure() : self::can_view();
        if (!$ok) {
            wp_send_json_error(['message' => $need === 'configure' ? 'Chỉ quản trị cấp cao sửa được cài đặt.' : 'Không có quyền dùng Cổng kế toán.'], 403);
        }
    }

    /** Gửi thật cần mật khẩu thao tác — cùng mật khẩu các nút đẩy khác ở màn VAT. */
    private static function check_password()
    {
        $pass = (string) wp_unslash($_POST['password'] ?? '');
        $expected = class_exists('TGS_Book_Close') ? (string) TGS_Book_Close::PASSWORD : '';
        if ($expected !== '' && !hash_equals($expected, $pass)) {
            wp_send_json_error(['message' => 'Sai mật khẩu thao tác.'], 403);
        }
    }

    /* ───────────────────────────── menu ───────────────────────────── */

    public static function page_url()
    {
        return admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW);
    }

    public static function register_route($routes)
    {
        $routes[self::VIEW] = ['Cổng kế toán', TGS_ACC_BRIDGE_PATH . 'admin/page-cong-ke-toan.php'];
        return $routes;
    }

    /** Quản trị → mục "Kế toán" (tạo mục nếu chưa có). Plugin khác thêm trang kế toán vào cùng mục bằng key 'accounting'. */
    public static function add_nav_item($nav, $current_view = '')
    {
        if (!self::can_view() || !isset($nav['admin']['sections'])) {
            return $nav;
        }
        $item = ['view' => self::VIEW, 'label' => 'Cổng kế toán (API đẩy chứng từ)', 'icon' => 'bx bx-transfer-alt'];
        foreach ($nav['admin']['sections'] as $i => $section) {
            if (($section['key'] ?? '') === 'accounting') {
                array_unshift($nav['admin']['sections'][$i]['items'], $item);
                return $nav;
            }
        }
        $nav['admin']['sections'][] = ['key' => 'accounting', 'heading' => 'Kế toán', 'icon' => 'bx bx-calculator', 'items' => [$item]];
        return $nav;
    }

    /* ───────────────────────────── tài nguyên ───────────────────────────── */

    private static function asset($file)
    {
        return plugins_url('assets/' . $file, TGS_ACC_BRIDGE_PATH . 'open-ketoan-bridge.php');
    }

    private static function ver($file)
    {
        return TGS_ACC_BRIDGE_VERSION . '.' . (int) @filemtime(TGS_ACC_BRIDGE_PATH . 'assets/' . $file);
    }

    /** Dữ liệu dùng chung cho JS của trang và của nút ở màn VAT. */
    public static function js_config()
    {
        return [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce(self::NONCE),
            'pageUrl'    => self::page_url(),
            'ready'      => TGS_Acc_Config::ready() ? 1 : 0,
            'chunkDays'  => (int) TGS_Acc_Config::get('chunk_days'),
            'maxDays'    => self::MAX_RANGE_DAYS,
            'canConfig'  => self::can_configure() ? 1 : 0,
            'needPass'   => class_exists('TGS_Book_Close') ? 1 : 0,
            'shops'      => self::shops(),
        ];
    }

    public static function enqueue()
    {
        $view = (string) ($_GET['view'] ?? '');
        if (($_GET['page'] ?? '') !== 'tgs-shop-management' || !self::can_view()) {
            return;
        }
        // Trang Cổng kế toán, và nút "Đẩy sang Kế toán" ở màn Quản lý phiếu xuất bán / phiếu điều chỉnh (tgs-bc-tk).
        $script = $view === self::VIEW ? 'cong-ke-toan.js' : (in_array($view, ['bctk-vat-sales', 'bctk-vat-adjust'], true) ? 'vat-push.js' : '');
        if ($script === '') {
            return;
        }
        wp_enqueue_style('tgs-acc-bridge', self::asset('cong-ke-toan.css'), [], self::ver('cong-ke-toan.css'));
        wp_enqueue_script('tgs-acc-runner', self::asset('push-runner.js'), [], self::ver('push-runner.js'), true);
        wp_localize_script('tgs-acc-runner', 'tgsAccBridge', self::js_config());
        wp_enqueue_script('tgs-acc-' . basename($script, '.js'), self::asset($script), ['tgs-acc-runner'], self::ver($script), true);
        if ($view === self::VIEW) {
            wp_enqueue_script('tgs-acc-ket-noi', self::asset('ket-noi.js'), ['tgs-acc-runner'], self::ver('ket-noi.js'), true); // [2026-11-23 12:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-3
        }
    }

    /* ───────────────────────────── dữ liệu ───────────────────────────── */

    /** Shop được đẩy: danh sách "Shop áp dụng thuế" của tgs-bc-tk. [{blog, code, name}] */
    public static function shops()
    {
        global $wpdb;
        $ids = class_exists('TGS_BCTK_Vat_Shops') ? array_map('intval', (array) TGS_BCTK_Vat_Shops::active_blog_ids()) : [];
        // [2026-11-21 10:15 PM Johnny Chu - Chu Hoàng Anh] S002-13 — thêm blog bật nguồn sổ POS (không có trong danh sách thuế của tgs-bc-tk).
        $ids = array_values(array_unique(array_merge($ids, self::pos_source_blogs())));
        $ids = (array) apply_filters('tgs_acc_bridge_blog_ids', $ids);
        $has_code = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->blogs} LIKE 'tgs_site_code'");
        $out = [];
        foreach ($ids as $id) {
            if (!TGS_Acc_Site::exists($id)) {
                continue;
            }
            $code = $has_code ? (string) $wpdb->get_var($wpdb->prepare("SELECT tgs_site_code FROM {$wpdb->blogs} WHERE blog_id = %d", $id)) : '';
            $name = trim((string) TGS_Acc_Site::opt($id, 'tgs_shop_name', '')) ?: (string) TGS_Acc_Site::opt($id, 'blogname', '');
            $out[] = ['blog' => (int) $id, 'code' => $code !== '' ? $code : '#' . $id, 'name' => $name];
        }
        return $out;
    }

    const POS_BLOGS_CACHE = 'tgs_acc_pos_source_blogs';

    /** Blog bật nguồn phiếu = sổ POS (option tgs_acc_sale_source = pos). Đệm 10 phút ở tầng mạng. @return int[] */
    public static function pos_source_blogs()
    {
        if (!is_multisite()) {
            return [];
        }
        $ids = get_site_transient(self::POS_BLOGS_CACHE);
        if (is_array($ids)) {
            return array_map('intval', $ids);
        }
        $ids = [];
        foreach (TGS_Acc_Site::blogs() as $id) {
            if (TGS_Acc_Source::sale_source((int) $id) === 'pos') {
                $ids[] = (int) $id;
            }
        }
        set_site_transient(self::POS_BLOGS_CACHE, $ids, 10 * MINUTE_IN_SECONDS);
        return $ids;
    }

    public static function forget_pos_blogs()
    {
        delete_site_transient(self::POS_BLOGS_CACHE);
    }

    private static function allowed_blog($blog_id)
    {
        foreach (self::shops() as $s) {
            if ($s['blog'] === (int) $blog_id) {
                return true;
            }
        }
        return false;
    }

    private static function read_range()
    {
        $from = sanitize_text_field(wp_unslash($_POST['from'] ?? ''));
        $to = sanitize_text_field(wp_unslash($_POST['to'] ?? $from));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            wp_send_json_error(['message' => 'Khoảng ngày không hợp lệ.']);
        }
        if ((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS + 1 > self::MAX_RANGE_DAYS) {
            wp_send_json_error(['message' => 'Mỗi lượt tối đa ' . self::MAX_RANGE_DAYS . ' ngày — trình duyệt tự chia đoạn, hãy tải lại trang.']);
        }
        return [$from, $to];
    }

    /* ───────────────────────────── AJAX ───────────────────────────── */

    /** Tổng quan: cấu hình (đã che), số chứng từ theo shop × trạng thái, lượt gửi gần nhất. */
    public static function ajax_overview()
    {
        self::guard();
        $stats = [];
        foreach (TGS_Acc_Push_Log::stats() as $r) {
            $b = (int) $r['blog_id'];
            if (!isset($stats[$b])) {
                $stats[$b] = ['posted' => 0, 'pending' => 0, 'remote_error' => 0, 'error' => 0, 'cancelled' => 0, 'last_at' => '', 'last_doc' => '']; // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9 — đếm thêm pending, remote_error
            }
            $stats[$b][$r['status']] = (int) $r['n'];
            $stats[$b]['last_at'] = max($stats[$b]['last_at'], (string) $r['last_at']);
            $stats[$b]['last_doc'] = max($stats[$b]['last_doc'], (string) $r['last_doc']);
        }
        // [2026-11-21 10:15 PM Johnny Chu - Chu Hoàng Anh] S002-13 — trạng thái kết nối từng shop: tạm dừng từ lúc nào, bao nhiêu nhóm đang chờ gửi.
        $shops = self::shops();
        $conn = [];
        foreach ($shops as $s) {
            $conn[$s['blog']] = self::conn_of($s['blog']);
        }
        wp_send_json_success([
            'config'   => TGS_Acc_Config::for_display(),
            'source'   => TGS_Acc_Source::ready(),
            'shops'    => $shops,
            'stats'    => $stats,
            'conn'     => $conn,
            'runs'     => self::decorate_runs(TGS_Acc_Push_Log::runs(0, 15)),
        ]);
    }

    private static function decorate_runs(array $rows)
    {
        $users = [];
        foreach ($rows as &$r) {
            $uid = (int) $r['user_id'];
            if ($uid && !isset($users[$uid])) {
                $u = get_userdata($uid);
                $users[$uid] = $u ? $u->display_name : '#' . $uid;
            }
            $r['user'] = $uid ? $users[$uid] : '';
        }
        unset($r);
        return $rows;
    }

    /**
     * Gửi (hoặc xem trước khi dry=1) MỘT shop trong MỘT khoảng ngày ngắn.
     * Trình duyệt gọi lần lượt từng shop × từng đoạn ngày.
     */
    public static function ajax_push()
    {
        self::guard();
        $dry = !empty($_POST['dry']);
        if (!$dry) {
            self::check_password();
        }
        $blog = (int) ($_POST['blog'] ?? 0);
        if (!self::allowed_blog($blog)) {
            wp_send_json_error(['message' => 'Shop #' . $blog . ' không nằm trong danh sách shop áp dụng thuế.']);
        }
        list($from, $to) = self::read_range();
        // [2026-11-21 08:11 PM Johnny Chu - Chu Hoàng Anh] S002-4 — nguồn phiếu theo blog (tgs-bc-tk hoặc sổ POS).
        if (!TGS_Acc_Source::ready($blog)) {
            wp_send_json_error(['message' => 'Chưa có nguồn phiếu bán / hoàn: cài tgs-bc-tk, hoặc bật nguồn sổ POS (option tgs_acc_sale_source = pos).']);
        }
        if (!$dry && !TGS_Acc_Config::ready()) {
            wp_send_json_error(['message' => 'Chưa khai địa chỉ / khoá phần mềm kế toán (Cổng kế toán → Cài đặt).']);
        }
        @set_time_limit(0);
        try {
            $s = TGS_Acc_Pusher::push($blog, $from, $to, ['dry' => $dry, 'force' => !empty($_POST['force']), 'origin' => 'web']);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => 'Lỗi khi dựng / gửi: ' . $e->getMessage()]);
        }
        unset($s['sample']);
        $s['failed_build'] = array_slice((array) $s['failed_build'], 0, 50);
        $s['errors'] = array_slice((array) ($s['errors'] ?? []), 0, 50);
        wp_send_json_success($s);
    }

    /** Xem dữ liệu THẬT sẽ gửi: dựng chứng từ của một shop / một ngày, trả chứng từ đầu tiên (hoặc theo số phiếu). */
    public static function ajax_sample()
    {
        self::guard();
        $blog = (int) ($_POST['blog'] ?? 0);
        if (!self::allowed_blog($blog)) {
            wp_send_json_error(['message' => 'Chọn shop trong danh sách.']);
        }
        list($from, $to) = self::read_range();
        $type = sanitize_key($_POST['type'] ?? 'sale');
        $no = trim(sanitize_text_field(wp_unslash($_POST['doc_no'] ?? '')));
        try {
            $built = TGS_Acc_Pusher::build($blog, $from, $to);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
        $pick = null;
        $n = 0;
        foreach ($built['docs'] as $d) {
            if ($d['docType'] !== $type) {
                continue;
            }
            $n++;
            if ($pick === null && ($no === '' || $d['docNo'] === $no)) {
                $pick = $d;
            }
        }
        $log = $pick ? (TGS_Acc_Push_Log::find_many([$pick['docKey']])[$pick['docKey']] ?? null) : null;
        wp_send_json_success([
            'count'  => $n,
            'doc'    => $pick,
            'log'    => $log,
            'failed' => array_slice($built['failed'], 0, 20),
        ]);
    }

    public static function ajax_log()
    {
        self::guard();
        $f = [];
        foreach (['blog', 'status', 'type', 'from', 'to', 'q', 'page', 'per'] as $k) {
            $f[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
        }
        wp_send_json_success(TGS_Acc_Push_Log::search($f));
    }

    public static function ajax_runs()
    {
        self::guard();
        wp_send_json_success(self::decorate_runs(TGS_Acc_Push_Log::runs((int) ($_POST['blog'] ?? 0), 200)));
    }

    public static function ajax_calls()
    {
        self::guard();
        wp_send_json_success(TGS_Acc_Api_Client::recent_calls(60));
    }

    /** Kiểm tra kết nối: máy chủ sống? khoá đúng? có quyền ghi chứng từ? */
    /** Danh mục bên kế toán (chỉ đọc): kind = loại danh mục, fresh = 1 thì đọc lại ngay, bỏ bản nhớ. */
    /** Thẻ "Sự kiện": sự kiện chứng từ của một ngày (JSONL) + nhóm đang chờ gửi lại. */
    public static function ajax_events()
    {
        self::guard();
        $date = (string) ($_POST['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = wp_date('Y-m-d');
        }
        wp_send_json_success(TGS_Acc_Events::overview($date));
    }

    /** Nút "Gửi lại các nhóm lỗi ngay" (bỏ qua giãn cách). */
    /** @return array{paused: bool, since: string, queue: int, oldest: string} */
    private static function conn_of($blog_id)
    {
        $q = TGS_Acc_Events::queue_of($blog_id);
        // [2026-11-22 02:20 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — kèm kết luận tự kiểm gần nhất + lý do tạm dừng.
        $last = class_exists('TGS_Acc_Selfcheck') ? TGS_Acc_Site::opt((int) $blog_id, TGS_Acc_Selfcheck::LAST_OPTION, null) : null;
        return [
            'paused' => (bool) $q['paused'], 'since' => TGS_Acc_Events::paused_since($blog_id), 'reason' => TGS_Acc_Events::paused_reason($blog_id),
            'queue' => (int) $q['groups'], 'oldest' => (string) $q['oldest'],
            'check' => is_array($last) ? ['verdict' => (string) $last['verdict'], 'at' => (string) $last['at'], 'tenant' => (string) $last['tenant']] : null,
        ];
    }

    /**
     * Tab Tự kiểm (OKT-SC-2, luật I-8): op=list → kết quả gần nhất của mọi shop + lần chạy hằng ngày; op=run → chạy lại một shop
     * (SC-1…SC-12 qua API bằng khoá của shop; sai / trùng tenant thì tự tạm dừng gửi). Không ghi kế toán, không ghi sổ POS.
     */
    public static function ajax_selfcheck()
    {
        self::guard();
        $op = sanitize_key((string) ($_POST['op'] ?? 'list'));
        if ($op === 'run') {
            $blog = (int) ($_POST['blog'] ?? 0);
            if (!self::allowed_blog($blog)) {
                wp_send_json_error(['message' => 'Shop không thuộc danh sách của Cổng kế toán.']);
            }
            $r = TGS_Acc_Selfcheck::enforce(TGS_Acc_Selfcheck::run($blog, ['confirm_tenant' => !empty($_POST['confirm'])]));
            wp_send_json_success(['row' => self::selfcheck_row($blog, $r)]);
        }
        $rows = [];
        foreach (self::shops() as $s) {
            $rows[] = self::selfcheck_row($s['blog']);
        }
        $next = wp_next_scheduled(TGS_Acc_Selfcheck::DAILY_HOOK);
        wp_send_json_success(['rows' => $rows, 'daily' => get_site_option(TGS_Acc_Selfcheck::DAILY_OPTION, null), 'next' => $next ? wp_date('Y-m-d H:i', $next) : '']);
    }

    // [2026-11-22 03:10 PM Johnny Chu - Chu Hoàng Anh] OKT-TRACE — tab Trace: đường đi của một phiếu / chứng từ (chỉ đọc).
    public static function ajax_trace()
    {
        self::guard();
        $blog = (int) ($_POST['blog'] ?? 0);
        $ref = sanitize_text_field(wp_unslash((string) ($_POST['ref'] ?? '')));
        $p = TGS_Acc_Trace::parse($blog, $ref);
        if (!$p) {
            wp_send_json_error(['message' => 'Nhập mã chứng từ btsoft:{blog}:{loại}:{id} hoặc id phiếu của shop đã chọn.']);
        }
        if (!self::allowed_blog($p['blog'])) {
            wp_send_json_error(['message' => 'Shop không thuộc danh sách của Cổng kế toán.']);
        }
        $r = TGS_Acc_Trace::run($p['blog'], $ref, ['remote' => !empty($_POST['remote']), 'days' => (int) ($_POST['days'] ?? 7)]);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success($r);
    }

    private static function selfcheck_row($blog, $r = null)
    {
        $blog = (int) $blog;
        $r = $r ?: TGS_Acc_Site::opt($blog, TGS_Acc_Selfcheck::LAST_OPTION, null);
        $shop = null;
        foreach (self::shops() as $s) {
            if ($s['blog'] === $blog) {
                $shop = $s;
            }
        }
        return [
            'blog' => $blog, 'code' => $shop['code'] ?? ('#' . $blog), 'name' => $shop['name'] ?? '',
            'key_mode' => TGS_Acc_Config::key_mode($blog), 'confirmed' => (string) TGS_Acc_Site::opt($blog, TGS_Acc_Selfcheck::TENANT_OPTION, ''),
            'paused' => TGS_Acc_Events::paused_since($blog), 'reason' => TGS_Acc_Events::paused_reason($blog),
            'last' => is_array($r) ? $r : null,
        ];
    }

    /**
     * Tạm dừng / bỏ tạm dừng kết nối của MỘT shop (S002-13, R-OKT-CONN-12). Tạm dừng: shop vẫn bán và ghi sự kiện, không gửi,
     * không thử lại, không hỏi trạng thái. Bỏ tạm dừng: cron gửi lại (mỗi phút) gửi bù hàng chờ, không trùng.
     */
    public static function ajax_pause()
    {
        self::guard();
        $blog = (int) ($_POST['blog'] ?? 0);
        if (!self::allowed_blog($blog)) {
            wp_send_json_error(['message' => 'Shop không thuộc danh sách của Cổng kế toán.']);
        }
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        if ($op === 'pause') {
            TGS_Acc_Events::pause($blog);
        } elseif ($op === 'resume') {
            TGS_Acc_Events::resume($blog);
        } else {
            wp_send_json_error(['message' => 'Thao tác không hợp lệ.']);
        }
        wp_send_json_success(['blog' => $blog, 'conn' => self::conn_of($blog)]);
    }

    public static function ajax_events_retry()
    {
        self::guard();
        wp_send_json_success(TGS_Acc_Events::retry(true));
    }

    public static function ajax_lists()
    {
        self::guard();
        $kinds = TGS_Acc_Lists::kinds();
        $kind = sanitize_key($_POST['kind'] ?? '');
        $meta = [];
        foreach ($kinds as $k => $d) {
            $meta[] = ['kind' => $k, 'label' => $d['label'], 'use' => $d['use'], 'cols' => $d['cols']];
        }
        if ($kind === '') {
            wp_send_json_success(['kinds' => $meta]);
        }
        $rows = TGS_Acc_Lists::rows($kind, !empty($_POST['fresh']));
        if (is_wp_error($rows)) {
            wp_send_json_error(['message' => $rows->get_error_message()]);
        }
        $at = TGS_Acc_Lists::fetched_at($kind);
        wp_send_json_success(['kind' => $kind, 'rows' => $rows, 'fetchedAt' => $at ? wp_date('d/m/Y H:i', $at) : '']);
    }

    public static function ajax_ping()
    {
        self::guard();
        $c = new TGS_Acc_Api_Client();
        $out = ['url' => TGS_Acc_Config::url(), 'health' => null, 'auth' => null, 'branches' => null];
        $h = $c->health();
        $out['health'] = is_wp_error($h) ? ['ok' => false, 'message' => $h->get_error_message()] : ['ok' => true, 'data' => $h];
        if (TGS_Acc_Config::key() === '') {
            $out['auth'] = ['ok' => false, 'message' => 'Chưa có khoá API.'];
        } else {
            $b = $c->get('/api/v1/branches');
            if (is_wp_error($b)) {
                $out['auth'] = ['ok' => false, 'message' => $b->get_error_message()];
            } else {
                $out['auth'] = ['ok' => true];
                $out['branches'] = array_map(static function ($x) {
                    return ['code' => $x['code'] ?? '', 'name' => $x['name'] ?? ''];
                }, (array) $b['data']);
            }
        }
        wp_send_json_success($out);
    }

    // [2026-11-23 12:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-3 — màn Cài đặt (ma trận ba mô hình) + Sơ đồ kết nối (open-setup 82 B-2, B-4, B-7; luật tối thượng P-0).
    /**
     * op=get: dữ liệu ma trận + sơ đồ · op=profile: chọn mô hình · op=install_key: lưu khoá cài đặt · op=register: đăng ký cửa hàng
     * (blogs[], renew[]) · op=refresh: đọc trạng thái từ open-ketoan · op=ping: ping tất cả ngay. Ghi = quản trị cấp cao.
     */
    public static function ajax_topology()
    {
        $op = sanitize_key((string) ($_POST['op'] ?? 'get'));
        self::guard($op === 'get' ? 'view' : 'configure');
        $ids = static function ($k) {
            $v = $_POST[$k] ?? [];
            return array_values(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string) $v))));
        };
        $r = true;
        $msg = '';
        if ($op === 'profile') {
            $r = TGS_Acc_Profile::set((string) wp_unslash($_POST['profile'] ?? ''));
            $msg = 'Đã lưu mô hình.';
        } elseif ($op === 'install_key') {
            $r = TGS_Acc_Config::save(['install_key' => (string) wp_unslash($_POST['install_key'] ?? '')]);
            $msg = 'Đã lưu khoá cài đặt.';
            if (!is_wp_error($r) && TGS_Acc_Install::active()) {
                $reg = TGS_Acc_Install::register();
                $msg .= is_wp_error($reg) ? ' Chưa đăng ký được cửa hàng: ' . $reg->get_error_message() : ' Đã đăng ký cửa hàng với open-ketoan.';
            }
        } elseif ($op === 'register') {
            $r = TGS_Acc_Install::register($ids('blogs'), $ids('renew'));
            $msg = 'Đã đăng ký với open-ketoan.';
        } elseif ($op === 'refresh') {
            $r = TGS_Acc_Install::refresh();
        } elseif ($op === 'ping') {
            TGS_Acc_Install::ping();
        } elseif ($op !== 'get') {
            wp_send_json_error(['message' => 'Thao tác không hợp lệ.']);
        }
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message(), 'topology' => TGS_Acc_Install::topology()]);
        }
        wp_send_json_success(['topology' => TGS_Acc_Install::topology(), 'message' => $msg]);
    }

    // [2026-11-25 10:05 AM Johnny Chu - Chu Hoàng Anh] B001-C4 — màn "Khoá theo shop": liệt kê (đọc đệm), kiểm từng blog, lưu URL / khoá của blog. Không trả khoá thật.
    public static function ajax_blog_list()
    {
        self::guard();
        $rows = TGS_Acc_Blog_Keys::rows();
        $out = ['rows' => $rows, 'canConfig' => self::can_configure()];
        if (!empty($_POST['csv'])) {
            $out['csv'] = TGS_Acc_Blog_Keys::csv($rows);
        }
        wp_send_json_success($out);
    }

    public static function ajax_blog_check()
    {
        self::guard();
        $blog = (int) ($_POST['blog'] ?? 0);
        if (!in_array($blog, TGS_Acc_Site::blogs(), true)) {
            wp_send_json_error(['message' => 'Blog #' . $blog . ' không có trong mạng.']);
        }
        wp_send_json_success(['row' => TGS_Acc_Blog_Keys::check($blog)]);
    }

    public static function ajax_blog_save()
    {
        self::guard('configure');
        $blog = (int) ($_POST['blog'] ?? 0);
        if (!in_array($blog, TGS_Acc_Site::blogs(), true)) {
            wp_send_json_error(['message' => 'Blog #' . $blog . ' không có trong mạng.']);
        }
        $in = [];
        foreach (['url', 'key'] as $k) {
            if (isset($_POST[$k])) {
                $in[$k] = (string) wp_unslash($_POST[$k]);
            }
        }
        $r = TGS_Acc_Blog_Keys::save($blog, $in);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success(['row' => $r, 'message' => 'Đã lưu và kiểm lại shop #' . $blog . '.']);
    }

    public static function ajax_save()
    {
        self::guard('configure');
        $in = [];
        foreach (['url', 'web_url', 'key', 'timeout', 'chunk_days', 'realtime', 'tax_url', 'tax_key', 'sso_secret', 'sso_tenant', 'catalog_from_erp'] as $k) {
            if (isset($_POST[$k])) {
                $in[$k] = wp_unslash($_POST[$k]);
            }
        }
        $before = md5(TGS_Acc_Config::url() . '|' . TGS_Acc_Config::key());
        $r = TGS_Acc_Config::save($in);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        $msg = 'Đã lưu cài đặt.';
        // Nhật ký gửi không biết chứng từ nằm ở đơn vị kế toán nào: đổi sang đơn vị / máy chủ khác
        // thì chứng từ "đã gửi" vẫn bị bỏ qua → phải gửi lại có tích "Gửi lại cả chứng từ không đổi".
        if ($before !== md5(TGS_Acc_Config::url() . '|' . TGS_Acc_Config::key()) && TGS_Acc_Push_Log::search(['per' => 10])['total'] > 0) {
            $msg .= ' Lưu ý: vừa đổi địa chỉ / khoá. Nếu đây là ĐƠN VỊ KẾ TOÁN KHÁC, chứng từ đã gửi trước đây chưa có ở đó — gửi lại với tích "Gửi lại cả chứng từ không đổi".';
        }
        wp_send_json_success(['config' => TGS_Acc_Config::for_display(), 'message' => $msg]);
    }
}
