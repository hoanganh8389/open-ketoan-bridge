<?php
/**
 * LỚP BAO KIỂU CÀI (OKT-TOPO-2, P1 — open-setup docs/chuong-trinh/OKT/80-BA-KIEU-CAI-DAT.md §4, phương án B / Q-OKT-4).
 *
 * WordPress MỘT SITE không nạp ms-blogs.php / ms-functions.php → get_blog_option, update_blog_option, delete_blog_option,
 * switch_to_blog, restore_current_blog, get_sites, get_blog_details KHÔNG TỒN TẠI (gọi là fatal — đo thật ở okt-wp1 23/11:
 * tự kiểm, tạm dừng, trang Cổng kế toán). Mọi chỗ của bridge cần "option của blog N", "chạy trong blog N", "mọi blog" đi qua
 * lớp này. Census tests/ms0-census-test.php chặn lời gọi thẳng ngoài tệp này.
 *
 * Một site = mạng một blog: blog duy nhất là blog hiện tại (thường 1). Option của blog = option của site. Hỏi blog khác
 * → trả mặc định / false, không đoán (không có blog đó). KHÔNG định nghĩa lại hàm của WordPress (polyfill) — plugin khác
 * (và chính bridge) dùng function_exists('switch_to_blog') để biết có phải multisite.
 *
 * Mô hình triển khai (bizcity · chain · single) KHÔNG đoán ở đây — đọc TGS_Acc_Profile (OKT-TOPO-9, hằng / lựa chọn, không theo khoá).
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-23 02:10 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-2 — lớp bao để bridge chạy đúng ở ba kiểu cài.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Site
{
    /** @var int[] chồng blog đã chuyển bằng switch_to() */
    private static $stack = [];

    public static function is_ms()
    {
        return function_exists('is_multisite') && is_multisite() && function_exists('get_blog_option');
    }

    public static function current()
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
    }

    public static function main()
    {
        return function_exists('get_main_site_id') ? (int) get_main_site_id() : 1;
    }

    /** Blog có trên bản cài này không (một site: chỉ blog hiện tại). */
    public static function exists($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return false;
        }
        return self::is_ms() ? (bool) get_blog_details($blog_id) : $blog_id === self::current();
    }

    /** Option của blog. Một site: option của site nếu đúng blog hiện tại, không thì $default. */
    public static function opt($blog_id, $key, $default = false)
    {
        $blog_id = (int) $blog_id ?: self::current();
        if (self::is_ms()) {
            return get_blog_option($blog_id, $key, $default);
        }
        return $blog_id === self::current() ? get_option($key, $default) : $default;
    }

    public static function set($blog_id, $key, $value)
    {
        $blog_id = (int) $blog_id ?: self::current();
        if (self::is_ms()) {
            return update_blog_option($blog_id, $key, $value);
        }
        return $blog_id === self::current() ? update_option($key, $value) : false;
    }

    public static function del($blog_id, $key)
    {
        $blog_id = (int) $blog_id ?: self::current();
        if (self::is_ms()) {
            return delete_blog_option($blog_id, $key);
        }
        return $blog_id === self::current() ? delete_option($key) : false;
    }

    /**
     * Id các blog. $active_only: bỏ blog đã xoá / lưu trữ / spam (như get_sites(deleted=0, archived=0, spam=0)).
     * @return int[]
     */
    public static function blogs($active_only = true)
    {
        if (!self::is_ms() || !function_exists('get_sites')) {
            return [self::current()];
        }
        $args = ['fields' => 'ids', 'number' => 0];
        if ($active_only) {
            $args += ['deleted' => 0, 'archived' => 0, 'spam' => 0];
        }
        return array_map('intval', get_sites($args));
    }

    /**
     * Chuyển sang blog (một site: chỉ blog hiện tại → không làm gì). Luôn gọi restore() trong finally.
     * @return bool đã chuyển thật (false = đang ở blog đó / một site)
     */
    public static function switch_to($blog_id)
    {
        $blog_id = (int) $blog_id;
        $switched = self::is_ms() && $blog_id > 0 && self::current() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }
        self::$stack[] = $switched;
        return $switched;
    }

    public static function restore()
    {
        $switched = array_pop(self::$stack);
        if ($switched && function_exists('restore_current_blog')) {
            restore_current_blog();
        }
    }

    /** Chạy $fn trong blog $blog_id rồi quay lại (kể cả khi ném lỗi). Một site + blog khác → WP_Error, không chạy. */
    public static function in_blog($blog_id, callable $fn)
    {
        if (!self::is_ms() && (int) $blog_id !== self::current()) {
            return new WP_Error('tgs_acc_no_blog', 'Bản cài một site không có blog #' . (int) $blog_id);
        }
        self::switch_to($blog_id);
        try {
            return $fn();
        } finally {
            self::restore();
        }
    }

    /** Tên hiển thị của blog: tgs_shop_name → blogname. */
    public static function name($blog_id)
    {
        return trim((string) self::opt($blog_id, 'tgs_shop_name', '')) ?: (string) self::opt($blog_id, 'blogname', '');
    }
}
