<?php
/**
 * Lối vào PHẦN MỀM KẾ TOÁN (ERP) từ trang quản trị — ERP CHẠY ĐỘC LẬP (23/10/2026 bỏ nhúng iframe): tên miền riêng, menu
 * riêng; hai bên chỉ nói chuyện qua API. Bên này chỉ còn:
 *
 *   - Menu "Kế toán" một mục, mở ERP ở TAB MỚI.
 *   - Link cũ admin.php?page=tgs-shop-management&view=ke-toan&p=/duong-dan (menu, bookmark, nút ở các màn) → chuyển thẳng
 *     sang ERP đúng màn đó (redirect_old_view, chạy ở admin_init).
 *   - ĐĂNG NHẬP ERP BẰNG TÀI KHOẢN WORDPRESS (WordPress multisite là gốc tài khoản + phân quyền):
 *       open_url($path) = admin-post.php?action=tgs_acc_sso_go&return=<ERP + path> → cấp VÉ ngắn hạn rồi quay lại ERP
 *       (#sso=…). Vé = base64url(JSON {sub, aud, email, name, login, perms, tenant, iat, exp}) . base64url(HMAC-SHA256, khoá chung).
 *       ERP (POST /api/v1/auth/wp-sso) kiểm chữ ký, tìm người dùng theo `sub` (= wp:<ID người dùng WordPress>) rồi theo email,
 *       tạo nếu chưa có, cấp phiên đúng `perms`. `aud` = tên app đích (TGS_KETOAN_SSO_AUD, mặc định open-ketoan).
 *       [2026-11-24 11:10 PM Johnny Chu - Chu Hoàng Anh] SPINE-6 — bizcity_identity@1 (chủ duyệt 2026-11-24, bizcity-platform docs/standard/BIZCITY-IDENTITY-v1.md): thêm sub + aud.
 *       ERP cũ (chưa cập nhật) bỏ qua hai trường lạ nên gửi vé mới sang ERP cũ vẫn đăng nhập được.
 *       Mở thẳng ERP mà chưa đăng nhập: ERP chuyển sang chính địa chỉ này (WP_SSO_LOGIN_URL bên ERP).
 *     `perms` lấy từ tgs_permission (các mục "Kế toán - …", PERM_MAP). Quản trị mạng = mọi quyền.
 *     Khoá chung: Cổng kế toán → "Khoá đăng nhập bằng tài khoản WordPress" (hoặc TGS_KETOAN_SSO_SECRET) = WP_SSO_SECRET bên ERP.
 *     Chưa đặt khoá → mở thẳng địa chỉ ERP, đăng nhập bằng mật khẩu của ERP.
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Embed
{
    const VIEW = 'ke-toan';
    const TICKET_TTL = 120; // giây

    /** Mục quyền tgs_permission → quyền bên phần mềm kế toán. Mở được menu Kế toán = quyền xem. */
    const PERM_MAP = [
        // [18/11/2026 Võ Hoàng] Thêm quyền các module bán hàng của kế toán (pos, phieu-ban, voucher, loyalty): trước đây đăng nhập
        // bằng tài khoản WordPress không mở được màn nào của module (BH10, BH11, BH13, BH14, BH17, khuyến mại, voucher…).
        'ke-toan'                     => ['catalog:read', 'documents:read', 'ledger:read', 'reports:read',
                                          'pos:report', 'phieu-ban:read', 'voucher:read', 'loyalty:read'],
        // [18/11/2026 Võ Hoàng] NT-BH-031: lập / sửa danh mục Kênh bán hàng (module pos) — thêm, sửa / dùng lại, ngừng dùng kênh
        'ketoan-chung-tu'             => ['documents:write', 'catalog:write', 'phieu-ban:write', 'voucher:write',
                                          'pos:kenh-ban-lap', 'pos:kenh-ban-sua', 'pos:kenh-ban-xoa'],
        'ketoan-sua-phieu-nguoi-khac' => ['documents:edit-all'],
        'ketoan-duyet-chi'            => ['payments:approve'],
        // Duyệt cấp 2 = kế toán trưởng: duyệt đơn đặt hàng, duyệt chương trình khuyến mại, duyệt trả hàng quá hạn.
        'ketoan-duyet-chi-cap-2'      => ['payments:approve-final', 'phieu-ban:order-approve', 'pos:promo-approve', 'pos:return-approve'],
        'ketoan-khoa-so'              => ['periods:lock', 'documents:bypass-date-lock'],
        'ketoan-cai-dat'              => ['settings:write'],
        'ketoan-nguoi-dung'           => ['users:write'],
    ];

    /** Quyền module chỉ quản trị mạng nhận thêm (thao tác máy bán tại quầy; nhân viên quầy dùng vé riêng của máy bán). */
    const MODULE_EXTRA_PERMS = ['pos:sell', 'pos:return', 'pos:cancel', 'voucher:use'];

    public static function init()
    {
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_route']);
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'add_menu'], 30, 2);
        add_action('admin_init', [__CLASS__, 'redirect_old_view'], 1);
        // Mở thẳng phần mềm kế toán: ERP chuyển sang đây lấy vé (đang đăng nhập → quay lại ngay; chưa → trang đăng nhập WP).
        add_action('admin_post_tgs_acc_sso_go', [__CLASS__, 'sso_go']);
        add_action('admin_post_nopriv_tgs_acc_sso_go', [__CLASS__, 'sso_go']);
    }

    /** Địa chỉ giao diện ERP trên trình duyệt. */
    public static function erp_url()
    {
        $w = TGS_Acc_Config::web_url();
        return $w !== '' ? $w : rtrim((string) TGS_Acc_Config::url(), '/');
    }

    /**
     * Địa chỉ mở ERP đúng màn $path (vd '/danh-muc/mat-hang'), đăng nhập sẵn bằng tài khoản WordPress khi đã đặt khoá
     * chung; chưa đặt thì địa chỉ ERP trực tiếp. '' nếu chưa cấu hình địa chỉ ERP.
     */
    public static function open_url($path = '/')
    {
        $erp = self::erp_url();
        if ($erp === '') {
            return '';
        }
        $target = $erp . '/' . ltrim((string) $path, '/');
        if (TGS_Acc_Config::sso_secret() === '') {
            return $target;
        }
        return add_query_arg(['action' => 'tgs_acc_sso_go', 'return' => rawurlencode($target)], admin_url('admin-post.php'));
    }

    /** Giữ view "ke-toan" để phân quyền menu và link cũ còn nhận ra; trang thật không còn (redirect_old_view chuyển đi). */
    public static function register_route($routes)
    {
        $routes[self::VIEW] = ['Kế toán', TGS_ACC_BRIDGE_PATH . 'admin/page-ke-toan.php'];
        return $routes;
    }

    /** Link cũ view=ke-toan&p=… → chuyển sang ERP (tab hiện tại; menu / nút mới đều mở tab mới sẵn). */
    public static function redirect_old_view()
    {
        if (wp_doing_ajax() || ($_GET['page'] ?? '') !== 'tgs-shop-management' || ($_GET['view'] ?? '') !== self::VIEW) {
            return;
        }
        $url = self::open_url(isset($_GET['p']) ? sanitize_text_field(wp_unslash($_GET['p'])) : '/');
        if ($url !== '') {
            wp_redirect($url);
            exit;
        }
    }

    /** Menu "Kế toán": một mục mở ERP ở tab mới (ERP có menu riêng). Chèn ngay sau "Chuyển kho". */
    public static function add_menu($nav, $current_view = '')
    {
        $top = ['ke-toan' => ['label' => 'Kế toán', 'icon' => 'bx bx-calculator', 'link_view' => self::VIEW, 'target' => '_blank', 'title' => 'Mở phần mềm kế toán (tab mới)']];
        $out = [];
        foreach ($nav as $key => $m) {
            $out[$key] = $m;
            if ($key === 'transfer') {
                $out += $top;
            }
        }
        return $out + $top;
    }

    /** Quyền bên phần mềm kế toán của người dùng (theo tgs_permission). */
    public static function erp_permissions($user_id)
    {
        $user_id = (int) $user_id;
        if (is_super_admin($user_id)) {
            // Quản trị mạng = mọi quyền, kể cả quyền riêng của máy bán (bán / hoàn / huỷ / dùng voucher).
            $all = array_merge(array_merge(...array_values(self::PERM_MAP)), self::MODULE_EXTRA_PERMS);
            return array_values(array_unique((array) apply_filters('tgs_acc_erp_permissions', $all, $user_id)));
        }
        $perm = class_exists('TGS_Permission') ? TGS_Permission::get_instance() : null;
        $out = [];
        foreach (self::PERM_MAP as $key => $codes) {
            $ok = $perm && method_exists($perm, 'user_can_view_menu') ? $perm->user_can_view_menu($user_id, $key) : user_can($user_id, 'manage_options');
            if ($ok) {
                $out = array_merge($out, $codes);
            }
        }
        // Không mở được menu Kế toán thì không có quyền nào bên đó.
        $out = in_array('reports:read', $out, true) ? array_values(array_unique($out)) : [];
        // Bổ sung / bớt quyền theo người dùng mà không sửa plugin (vd thêm quyền module mới): filter tgs_acc_erp_permissions.
        return $out ? array_values(array_unique((array) apply_filters('tgs_acc_erp_permissions', $out, $user_id))) : [];
    }

    private static function b64url($s)
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Vé đăng nhập ERP cho người dùng hiện tại; '' nếu chưa đặt khoá chung / chưa đăng nhập. */
    public static function ticket()
    {
        $secret = TGS_Acc_Config::sso_secret();
        $u = wp_get_current_user();
        if ($secret === '' || !$u || !$u->exists() || (string) $u->user_email === '') {
            return '';
        }
        $now = time();
        $body = self::b64url(wp_json_encode([
            // [2026-11-24 11:10 PM Johnny Chu - Chu Hoàng Anh] SPINE-6 — danh tính WordPress ổn định (email đổi được, ID người dùng không đổi); ERP khớp người dùng theo đây trước.
            'sub'    => 'wp:' . (int) $u->ID,
            'aud'    => TGS_Acc_Config::sso_aud(),
            'email'  => strtolower((string) $u->user_email),
            'name'   => (string) ($u->display_name ?: $u->user_login),
            'login'  => (string) $u->user_login,
            'perms'  => self::erp_permissions($u->ID),
            'tenant' => TGS_Acc_Config::sso_tenant(),
            'iat'    => $now,
            'exp'    => $now + self::TICKET_TTL,
        ]));
        return $body . '.' . self::b64url(hash_hmac('sha256', $body, $secret, true));
    }

    /** Đích quay lại hợp lệ: phải nằm trong địa chỉ ERP đã cấu hình (chống chuyển hướng sang trang lạ). */
    private static function safe_return($url)
    {
        $erp = self::erp_url();
        $url = (string) $url;
        if ($erp === '' || $url === '') {
            return $erp !== '' ? $erp . '/' : '';
        }
        $a = wp_parse_url($erp);
        $b = wp_parse_url($url);
        $same = $a && $b && strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '') && (int) ($a['port'] ?? 0) === (int) ($b['port'] ?? 0);
        return $same ? strtok($url, '#') : $erp . '/';
    }

    /** admin-post.php?action=tgs_acc_sso_go&return=… — cấp vé rồi quay lại phần mềm kế toán. */
    public static function sso_go()
    {
        $return = self::safe_return(wp_unslash($_GET['return'] ?? ''));
        if ($return === '') {
            wp_die('Chưa cấu hình địa chỉ phần mềm kế toán (Cổng kế toán).', 'Kế toán', ['response' => 400]);
        }
        if (!is_user_logged_in()) {
            $self = add_query_arg(['action' => 'tgs_acc_sso_go', 'return' => rawurlencode($return)], admin_url('admin-post.php'));
            wp_redirect(wp_login_url($self));
            exit;
        }
        $t = self::ticket();
        if ($t === '' || !self::erp_permissions(get_current_user_id())) {
            wp_die('Tài khoản chưa được cấp quyền Kế toán (phân quyền: "Kế toán - Mở phần mềm kế toán"), hoặc chưa đặt khoá đăng nhập ở Cổng kế toán.', 'Kế toán', ['response' => 403, 'back_link' => true]);
        }
        nocache_headers();
        wp_redirect($return . (strpos($return, '?') === false ? '?' : '&') . '_t=' . time() . '#sso=' . rawurlencode($t));
        exit;
    }
}
