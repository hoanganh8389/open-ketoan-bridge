<?php
/**
 * HỒ SƠ MÔ HÌNH TRIỂN KHAI (OKT-TOPO-9 — luật tối thượng P-0; open-setup 82 §4 B-1, Q-OKT-8 ✅).
 *
 *   bizcity  máy chủ bizcity.vn: mỗi site một phần mềm kế toán (tenant), khoá riêng từng site (P-0 ý 1)
 *   chain    tự cài nhiều cửa hàng (multisite): một máy open-ketoan, một khoá cài đặt, mỗi cửa hàng một tenant (P-0 ý 2, nghĩa (a))
 *   single   tự cài một cửa hàng (WordPress một site): một khoá, một tenant
 *
 * Thứ tự đọc: hằng TGS_ACC_DEPLOYMENT (máy chủ đặt — thắng mọi thứ, khoá lựa chọn) → lựa chọn lưu ở mạng (site option
 * tgs_acc_deployment, đặt ở màn Cài đặt / `wp tgs-acc profile --set`) → WordPress một site tự là `single`.
 * KHÔNG đoán từ khoá đang có (bizcity hôm nay cũng dùng khoá chung trong giai đoạn chuyển). Multisite chưa khai → '' (tự kiểm SC-0b vàng).
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-23 06:40 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-9 — mọi nhánh mã theo mô hình đọc một chỗ này.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Profile
{
    const OPTION = 'tgs_acc_deployment';
    const ALL = ['bizcity', 'chain', 'single'];

    /** bizcity | chain | single | '' (multisite chưa khai). */
    public static function current()
    {
        $c = self::constant();
        if ($c !== '') {
            return $c;
        }
        if (!self::is_ms()) {
            return 'single';
        }
        $o = function_exists('get_site_option') ? (string) get_site_option(self::OPTION, '') : '';
        return in_array($o, ['bizcity', 'chain'], true) ? $o : '';
    }

    /** constant | option | auto | none */
    public static function source()
    {
        if (self::constant() !== '') {
            return 'constant';
        }
        if (!self::is_ms()) {
            return 'auto';
        }
        return self::current() !== '' ? 'option' : 'none';
    }

    public static function locked()
    {
        return self::constant() !== '';
    }

    /** Mô hình nào được chọn ở bản cài này (màn Cài đặt khoá các ô còn lại). */
    public static function allowed()
    {
        if (self::locked()) {
            return [self::constant()];
        }
        return self::is_ms() ? ['bizcity', 'chain'] : ['single'];
    }

    /** @return true|WP_Error */
    public static function set($profile)
    {
        $profile = (string) $profile;
        if (self::locked()) {
            return new WP_Error('tgs_acc_profile_locked', 'Mô hình do máy chủ khai (hằng TGS_ACC_DEPLOYMENT = ' . self::constant() . '), không đổi ở đây');
        }
        if (!in_array($profile, self::allowed(), true)) {
            return new WP_Error('tgs_acc_profile_bad', 'Bản cài này chỉ chọn được: ' . implode(', ', self::allowed()) . ' (' . (self::is_ms() ? 'multisite' : 'WordPress một site') . ')');
        }
        if (self::is_ms()) {
            update_site_option(self::OPTION, $profile);
        }
        do_action('tgs_acc_profile_changed', $profile);
        return true;
    }

    public static function label($profile)
    {
        return [
            'bizcity' => 'bizcity.vn — mỗi cửa hàng một phần mềm kế toán, khoá riêng từng site',
            'chain'   => 'tự cài nhiều cửa hàng — một máy open-ketoan, một khoá cài đặt, mỗi cửa hàng một tenant',
            'single'  => 'tự cài một cửa hàng — WordPress một site',
        ][$profile] ?? 'chưa khai';
    }

    private static function constant()
    {
        $c = defined('TGS_ACC_DEPLOYMENT') ? strtolower(trim((string) TGS_ACC_DEPLOYMENT)) : '';
        return in_array($c, self::ALL, true) ? $c : '';
    }

    private static function is_ms()
    {
        return class_exists('TGS_Acc_Site') ? TGS_Acc_Site::is_ms() : (function_exists('is_multisite') && is_multisite());
    }
}
