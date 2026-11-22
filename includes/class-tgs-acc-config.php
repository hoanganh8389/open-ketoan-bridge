<?php
/**
 * Cấu hình kết nối phần mềm kế toán.
 *
 * Ba tầng, đọc từ trên xuống (docs/CAU-HINH-KET-NOI.md §2):
 *   H. Hằng trong wp-config.php: TGS_KETOAN_API_URL, TGS_KETOAN_API_KEY, TGS_KETOAN_API_KEYS (blog → khoá);
 *   B. Option của từng blog (multisite): khoá của tenant blog đó, URL riêng nếu có;
 *   M. Site option của network (lưu từ trang "Cổng kế toán").
 * Khoá API không bao giờ trả ra trình duyệt: chỉ trả dạng che (kt_ab…xy).
 *
 * Khoá có hai loại (R-OKT-CONN-6, một blog = một tenant):
 *   - khoá RIÊNG của blog: key_for($blog_id) — TGS_KETOAN_API_KEYS[blog] → option của blog → (máy một site) TGS_KETOAN_API_KEY / option;
 *   - khoá CHUNG cũ của mạng: key_legacy() — TGS_KETOAN_API_KEY / option mạng trên multisite. Chỉ dùng để đẩy chứng từ
 *     trong giai đoạn chuyển tiếp (§6); không bao giờ làm blog thành "đã kết nối".
 * key() giữ hành vi cũ cho mọi nơi đang gọi: khoá riêng của blog hiện tại nếu có, không thì khoá chung.
 */
// [2026-11-21 01:52 PM Johnny Chu - Chu Hoàng Anh] R-OKT-CONN C1 — tầng cấu hình theo blog: key_for / key_legacy / url_for / save_blog / for_display_blog; key() và url() giữ hành vi cũ khi blog chưa có khoá riêng.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Config
{
    const OPTION = 'tgs_acc_bridge_config';

    /** Giá trị mặc định của các thiết lập không phải bí mật. */
    public static function defaults()
    {
        return [
            'url'        => '',
            'web_url'    => '',   // địa chỉ người dùng mở trên trình duyệt (https://…); trống = dùng 'url' nếu không phải địa chỉ nội bộ
            'key'        => '',
            'timeout'    => 60,   // giây mỗi lượt gọi
            'chunk_days' => 7,    // nút đẩy chia khoảng ngày thành từng đoạn ngắn để mỗi lượt gọi không quá lâu
            'gate_key'   => '',   // khoá cổng chờ cho ERP kéo chứng từ (TGS_Acc_Gate); trống = tự sinh lần đầu
            'realtime'   => '1',  // gửi tức thời theo sự kiện (TGS_Acc_Events); '0' = chỉ ghi sự kiện, không gửi
            'tax_url'    => '',   // SỔ THUẾ (phần mềm kế toán thứ hai, BOOK_MODE=tax): gửi tức thời phiếu bán / hoàn có số hoá đơn
            'tax_key'    => '',
            'sso_secret' => '',   // khoá chung ký vé đăng nhập phần mềm kế toán bằng tài khoản WordPress (= WP_SSO_SECRET bên kế toán)
            'sso_tenant' => '',   // mã đơn vị kế toán nhận người dùng (trống = đơn vị mặc định bên kế toán)
            'catalog_from_erp' => '0', // '1' = danh mục hàng (mặt hàng, ĐVT, bảng giá, giá) lấy từ kế toán, khoá sửa bên này (TGS_Acc_Catalog_Sync)
        ];
    }

    /** Thiết lập được phép đặt ở tầng blog. Còn lại (SSO, cổng chờ, catalog_from_erp) chỉ ở tầng mạng. */
    const BLOG_FIELDS = ['url', 'web_url', 'key', 'tax_url', 'tax_key', 'timeout', 'chunk_days', 'realtime'];

    /** Tầng mạng (M). Trên máy một site đây chính là option của site. */
    private static function stored()
    {
        $v = get_site_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($v) ? $v : []);
    }

    /** Tầng blog (B). Chỉ có trên multisite; máy một site đã có mọi thứ ở stored(). */
    private static function blog_stored($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0 || !is_multisite()) {
            return [];
        }
        $v = TGS_Acc_Site::opt($blog_id, self::OPTION, []);
        return is_array($v) ? array_intersect_key($v, array_flip(self::BLOG_FIELDS)) : [];
    }

    /**
     * Blog của ngữ cảnh hiện tại. 0 = tầng mạng (Network Admin): không có tenant, không dùng khoá riêng của blog nào.
     * Ngữ cảnh blog 1 (site chính) là một blog bình thường.
     */
    public static function current_blog()
    {
        if (is_multisite() && function_exists('is_network_admin') && is_network_admin()) {
            return 0;
        }
        return (int) get_current_blog_id();
    }

    /** Giá trị một thiết lập ở tầng blog, '' nếu blog không đặt. */
    private static function blog_value($blog_id, $name)
    {
        $b = self::blog_stored($blog_id);
        return isset($b[$name]) ? trim((string) $b[$name]) : '';
    }

    /** URL API của một blog: hằng → blog → mạng. $blog_id = 0: tầng mạng. */
    public static function url_for($blog_id)
    {
        if (defined('TGS_KETOAN_API_URL') && TGS_KETOAN_API_URL !== '') {
            return rtrim((string) TGS_KETOAN_API_URL, '/');
        }
        $b = self::blog_value($blog_id, 'url');
        if ($b !== '') {
            return rtrim($b, '/');
        }
        return rtrim((string) self::stored()['url'], '/');
    }

    public static function url()
    {
        return self::url_for(self::current_blog());
    }

    /**
     * Khoá RIÊNG của tenant blog này. '' = blog chưa có khoá riêng (→ standalone theo R-OKT-CONN).
     * Thứ tự: TGS_KETOAN_API_KEYS[blog] → option của blog → (máy một site) TGS_KETOAN_API_KEY / option.
     * Trên multisite KHÔNG BAO GIỜ trả khoá chung của mạng.
     */
    public static function key_for($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return '';
        }
        $map = self::keys_map();
        if (isset($map[$blog_id]) && (string) $map[$blog_id] !== '') {
            return (string) $map[$blog_id];
        }
        $b = self::blog_value($blog_id, 'key');
        if ($b !== '') {
            return $b;
        }
        if (!is_multisite()) {
            if (defined('TGS_KETOAN_API_KEY') && TGS_KETOAN_API_KEY !== '') {
                return (string) TGS_KETOAN_API_KEY;
            }
            return (string) self::stored()['key'];
        }
        return '';
    }

    /** Khoá CHUNG cũ của mạng (chỉ multisite). Dùng để đẩy chứng từ trong giai đoạn chuyển tiếp; '' nếu không có. */
    public static function key_legacy()
    {
        if (!is_multisite()) {
            return '';
        }
        if (defined('TGS_KETOAN_API_KEY') && TGS_KETOAN_API_KEY !== '') {
            return (string) TGS_KETOAN_API_KEY;
        }
        return (string) self::stored()['key'];
    }

    /** Hằng TGS_KETOAN_API_KEYS dạng [blog_id => khoá]; sai dạng thì coi như không có. */
    private static function keys_map()
    {
        if (!defined('TGS_KETOAN_API_KEYS') || !is_array(TGS_KETOAN_API_KEYS)) {
            return [];
        }
        $out = [];
        foreach (TGS_KETOAN_API_KEYS as $bid => $k) {
            if ((int) $bid > 0 && is_string($k) && trim($k) !== '') {
                $out[(int) $bid] = trim($k);
            }
        }
        return $out;
    }

    /** 'own' = blog có khoá riêng · 'legacy' = chỉ có khoá chung của mạng · 'none' = không có khoá nào. */
    public static function key_mode($blog_id)
    {
        if (self::key_for($blog_id) !== '') {
            return 'own';
        }
        return self::key_legacy() !== '' ? 'legacy' : 'none';
    }

    /** Nguồn khoá của blog: 'wp-config-map' | 'site' | 'wp-config' | 'option' (máy một site) | 'legacy' | ''. */
    public static function key_source_for($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id > 0) {
            $map = self::keys_map();
            if (isset($map[$blog_id])) {
                return 'wp-config-map';
            }
            if (self::blog_value($blog_id, 'key') !== '') {
                return 'site';
            }
            if (!is_multisite()) {
                return self::source('key');
            }
        }
        return self::key_legacy() !== '' ? 'legacy' : '';
    }

    /** Nguồn URL của blog: 'wp-config' | 'site' | 'network' | ''. */
    public static function url_source_for($blog_id)
    {
        if (defined('TGS_KETOAN_API_URL') && TGS_KETOAN_API_URL !== '') {
            return 'wp-config';
        }
        if (self::blog_value($blog_id, 'url') !== '') {
            return 'site';
        }
        return (string) self::stored()['url'] !== '' ? 'network' : '';
    }

    /**
     * Địa chỉ mở giao diện kế toán trên trình duyệt. Khác 'url' khi WordPress gọi nội bộ
     * (http://127.0.0.1:8088) còn người dùng vào bằng tên miền HTTPS.
     * Hằng TGS_KETOAN_WEB_URL ưu tiên. Trả '' nếu chỉ có địa chỉ nội bộ.
     */
    public static function web_url()
    {
        if (defined('TGS_KETOAN_WEB_URL') && TGS_KETOAN_WEB_URL !== '') {
            return rtrim((string) TGS_KETOAN_WEB_URL, '/');
        }
        $w = rtrim(self::blog_value(self::current_blog(), 'web_url'), '/');
        if ($w === '') {
            $w = rtrim((string) self::stored()['web_url'], '/');
        }
        if ($w !== '') {
            return $w;
        }
        $u = self::url();
        return preg_match('#^https?://(127\.|localhost|\[?::1)#i', $u) ? '' : $u;
    }

    /** Khoá chung ký vé đăng nhập (hằng TGS_KETOAN_SSO_SECRET ưu tiên). '' = chưa bật đăng nhập bằng tài khoản WordPress. */
    public static function sso_secret()
    {
        if (defined('TGS_KETOAN_SSO_SECRET') && TGS_KETOAN_SSO_SECRET !== '') {
            return (string) TGS_KETOAN_SSO_SECRET;
        }
        return (string) self::stored()['sso_secret'];
    }

    public static function sso_tenant()
    {
        return (string) self::stored()['sso_tenant'];
    }

    /**
     * Khoá dùng cho lượt gọi trong ngữ cảnh hiện tại (giữ hành vi cũ cho mọi nơi đang gọi):
     * khoá riêng của blog hiện tại nếu có, không thì khoá chung của mạng (giai đoạn chuyển tiếp).
     * Câu hỏi "blog đã kết nối chưa" KHÔNG dùng hàm này — dùng key_for().
     */
    public static function key()
    {
        $own = self::key_for(self::current_blog());
        return $own !== '' ? $own : self::key_legacy();
    }

    /** Sổ thuế: địa chỉ + khoá API: hằng TGS_KETOAN_TAX_API_URL / _KEY → blog → mạng. Trống = không gửi sổ thuế. */
    public static function tax_url()
    {
        if (defined('TGS_KETOAN_TAX_API_URL') && TGS_KETOAN_TAX_API_URL !== '') {
            return rtrim((string) TGS_KETOAN_TAX_API_URL, '/');
        }
        $b = self::blog_value(self::current_blog(), 'tax_url');
        return rtrim($b !== '' ? $b : (string) (self::stored()['tax_url'] ?? ''), '/');
    }

    public static function tax_key()
    {
        if (defined('TGS_KETOAN_TAX_API_KEY') && TGS_KETOAN_TAX_API_KEY !== '') {
            return (string) TGS_KETOAN_TAX_API_KEY;
        }
        $b = self::blog_value(self::current_blog(), 'tax_key');
        return $b !== '' ? $b : (string) (self::stored()['tax_key'] ?? '');
    }

    public static function tax_ready()
    {
        return self::tax_url() !== '' && self::tax_key() !== '';
    }

    /** Thiết lập thường (timeout, chunk_days, realtime…): blog → mạng → mặc định. Bí mật dùng hàm riêng. */
    public static function get($name)
    {
        if (in_array($name, ['timeout', 'chunk_days', 'realtime'], true)) {
            $b = self::blog_value(self::current_blog(), $name);
            if ($b !== '') {
                return $b;
            }
        }
        $s = self::stored();
        return $s[$name] ?? (self::defaults()[$name] ?? null);
    }

    public static function ready()
    {
        return self::url() !== '' && self::key() !== '';
    }

    // [2026-11-22 05:10 PM Johnny Chu - Chu Hoàng Anh] OKT-I3 — đọc theo blog có phiếu (cron gửi lại chạy ở site chính, không có cấu hình của blog).
    /** Blog có đủ URL + khoá để gửi sổ nội bộ không (khoá riêng, hoặc khoá chung cũ của mạng). */
    public static function ready_for($blog_id)
    {
        $blog_id = (int) $blog_id;
        return self::url_for($blog_id) !== '' && (self::key_for($blog_id) !== '' || self::key_legacy() !== '');
    }

    /** Như get() nhưng của một blog chỉ định: blog → mạng → mặc định. */
    public static function get_for($blog_id, $name)
    {
        if (in_array($name, ['timeout', 'chunk_days', 'realtime'], true)) {
            $b = self::blog_value((int) $blog_id, $name);
            if ($b !== '') {
                return $b;
            }
        }
        $s = self::stored();
        return $s[$name] ?? (self::defaults()[$name] ?? null);
    }

    /** Nguồn của từng giá trị: 'wp-config' (khoá cứng, không sửa trên trang được) | 'option' | ''. */
    public static function source($name)
    {
        $const = $name === 'url' ? 'TGS_KETOAN_API_URL' : 'TGS_KETOAN_API_KEY';
        if (defined($const) && constant($const) !== '') {
            return 'wp-config';
        }
        return (string) self::stored()[$name] !== '' ? 'option' : '';
    }

    public static function mask($key)
    {
        $key = (string) $key;
        if ($key === '') {
            return '';
        }
        return strlen($key) <= 8 ? str_repeat('•', strlen($key)) : substr($key, 0, 5) . '…' . substr($key, -2);
    }

    /**
     * Dữ liệu an toàn để hiện trên trang Cổng kế toán (TẦNG MẠNG — trang này lưu bằng save()).
     * Khoá hiện là khoá của tầng mạng (khoá chung trên multisite), không phải khoá riêng của blog đang đứng.
     */
    public static function for_display()
    {
        $net_key = is_multisite() ? self::key_legacy() : self::key_for((int) get_current_blog_id());
        return [
            'url'        => self::url_for(0),
            'url_source' => self::source('url'),
            'web_url'    => self::web_url(),
            'web_source' => defined('TGS_KETOAN_WEB_URL') && TGS_KETOAN_WEB_URL !== '' ? 'wp-config' : ((string) self::stored()['web_url'] !== '' ? 'option' : ''),
            'key_masked' => self::mask($net_key),
            'key_source' => self::source('key'),
            'timeout'    => (int) self::get('timeout'),
            'chunk_days' => (int) self::get('chunk_days'),
            'realtime'   => (string) self::get('realtime') !== '0',
            'tax_url'    => self::tax_url(),
            'tax_url_locked' => defined('TGS_KETOAN_TAX_API_URL') && TGS_KETOAN_TAX_API_URL !== '',
            'tax_key_masked' => self::mask(self::tax_key()),
            'tax_key_locked' => defined('TGS_KETOAN_TAX_API_KEY') && TGS_KETOAN_TAX_API_KEY !== '',
            'tax_ready'  => self::tax_ready(),
            'sso_secret_masked' => self::mask(self::sso_secret()),
            'sso_secret_locked' => defined('TGS_KETOAN_SSO_SECRET') && TGS_KETOAN_SSO_SECRET !== '',
            'sso_tenant' => self::sso_tenant(),
            'realtime_locked' => defined('TGS_ACC_REALTIME'),
            'catalog_from_erp' => class_exists('TGS_Acc_Catalog_Sync') && TGS_Acc_Catalog_Sync::enabled(),
            'catalog_last' => class_exists('TGS_Acc_Catalog_Sync') ? TGS_Acc_Catalog_Sync::last() : [],
            'ready'      => self::url_for(0) !== '' && $net_key !== '',
        ];
    }

    /** Dữ liệu an toàn của MỘT blog (màn "Khoá theo shop" / màn Kết nối của site). Không có khoá thật. */
    public static function for_display_blog($blog_id)
    {
        $blog_id = (int) $blog_id;
        $own = self::key_for($blog_id);
        $map = self::keys_map();
        return [
            'blog_id'    => $blog_id,
            'url'        => self::url_for($blog_id),
            'url_source' => self::url_source_for($blog_id),
            'key_masked' => self::mask($own),
            'key_source' => self::key_source_for($blog_id),
            'key_mode'   => self::key_mode($blog_id),
            'key_locked' => isset($map[$blog_id]) || (!is_multisite() && self::source('key') === 'wp-config'),
            'url_locked' => defined('TGS_KETOAN_API_URL') && TGS_KETOAN_API_URL !== '',
        ];
    }

    /**
     * Lưu tầng BLOG (chỉ multisite). Như save(): ô khoá trống = giữ khoá cũ; "-" = xoá; ô URL trống = dùng URL của mạng.
     * Khoá đặt bằng TGS_KETOAN_API_KEYS[blog] thì không ghi đè được. Chỉ nhận các thiết lập trong BLOG_FIELDS.
     */
    public static function save_blog($blog_id, array $in)
    {
        $blog_id = (int) $blog_id;
        if (!is_multisite()) {
            // OKT-TOPO-2: một site = mạng một blog → cấu hình của blog hiện tại LÀ cấu hình của site (một API cho cả ba kiểu cài, 80 §4).
            if ($blog_id !== TGS_Acc_Site::current()) {
                return new WP_Error('bad_blog', 'Máy một site chỉ có blog ' . TGS_Acc_Site::current());
            }
            return self::save(array_intersect_key($in, array_flip(self::BLOG_FIELDS)));
        }
        if ($blog_id <= 0 || !get_site($blog_id)) {
            return new WP_Error('bad_blog', 'Không có blog ' . $blog_id);
        }
        $cur = TGS_Acc_Site::opt($blog_id, self::OPTION, []);
        $s = is_array($cur) ? array_intersect_key($cur, array_flip(self::BLOG_FIELDS)) : [];
        foreach (['url', 'web_url', 'tax_url'] as $f) {
            if (isset($in[$f])) {
                $u = trim((string) $in[$f]);
                if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                    return new WP_Error('bad_url', 'Địa chỉ phải bắt đầu bằng http:// hoặc https://');
                }
                $s[$f] = rtrim($u, '/');
            }
        }
        foreach (['key', 'tax_key'] as $f) {
            if (!isset($in[$f])) {
                continue;
            }
            if ($f === 'key' && isset(self::keys_map()[$blog_id])) {
                continue; // khoá cứng ở wp-config
            }
            $k = trim((string) $in[$f]);
            if ($k === '-') {
                $s[$f] = '';
            } elseif ($k !== '') {
                $s[$f] = $k;
            }
        }
        if (isset($in['timeout'])) {
            $s['timeout'] = $in['timeout'] === '' ? '' : (string) max(10, min(300, (int) $in['timeout']));
        }
        if (isset($in['chunk_days'])) {
            $s['chunk_days'] = $in['chunk_days'] === '' ? '' : (string) max(1, min(31, (int) $in['chunk_days']));
        }
        if (isset($in['realtime'])) {
            $s['realtime'] = $in['realtime'] === '' ? '' : (in_array((string) $in['realtime'], ['0', 'false'], true) ? '0' : '1');
        }
        TGS_Acc_Site::set($blog_id, self::OPTION, array_filter($s, static function ($v) {
            return $v !== '';
        }));
        return true;
    }

    /**
     * Lưu từ trang quản trị. Ô khoá để trống = giữ khoá cũ; gõ "-" = xoá.
     * Giá trị đang đặt bằng hằng wp-config thì không ghi đè được (bỏ qua).
     */
    public static function save(array $in)
    {
        $s = self::stored();
        if (isset($in['url'])) {
            $url = trim((string) $in['url']);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                return new WP_Error('bad_url', 'Địa chỉ phải bắt đầu bằng http:// hoặc https://');
            }
            $s['url'] = rtrim($url, '/');
        }
        if (isset($in['web_url'])) {
            $w = trim((string) $in['web_url']);
            if ($w !== '' && !preg_match('#^https?://#i', $w)) {
                return new WP_Error('bad_url', 'Địa chỉ mở trên trình duyệt phải bắt đầu bằng http:// hoặc https://');
            }
            $s['web_url'] = rtrim($w, '/');
        }
        if (isset($in['key'])) {
            $key = trim((string) $in['key']);
            if ($key === '-') {
                $s['key'] = '';
            } elseif ($key !== '') {
                $s['key'] = $key;
            }
        }
        if (isset($in['sso_secret'])) {
            $ss = trim((string) $in['sso_secret']);
            if ($ss === '-') {
                $s['sso_secret'] = '';
            } elseif ($ss !== '') {
                if (strlen($ss) < 24) {
                    return new WP_Error('bad_sso', 'Khoá đăng nhập phải dài ít nhất 24 ký tự');
                }
                $s['sso_secret'] = $ss;
            }
        }
        if (isset($in['sso_tenant'])) {
            $s['sso_tenant'] = sanitize_text_field((string) $in['sso_tenant']);
        }
        if (isset($in['timeout'])) {
            $s['timeout'] = max(10, min(300, (int) $in['timeout']));
        }
        if (isset($in['tax_url'])) {
            $tu = trim((string) $in['tax_url']);
            if ($tu !== '' && !preg_match('#^https?://#i', $tu)) {
                return new WP_Error('bad_url', 'Địa chỉ sổ thuế phải bắt đầu bằng http:// hoặc https://');
            }
            $s['tax_url'] = rtrim($tu, '/');
        }
        if (isset($in['tax_key'])) {
            $tk = trim((string) $in['tax_key']);
            if ($tk === '-') {
                $s['tax_key'] = '';
            } elseif ($tk !== '') {
                $s['tax_key'] = $tk;
            }
        }
        if (isset($in['realtime'])) {
            $s['realtime'] = in_array((string) $in['realtime'], ['0', 'false', ''], true) ? '0' : '1';
        }
        if (isset($in['catalog_from_erp'])) {
            $s['catalog_from_erp'] = in_array((string) $in['catalog_from_erp'], ['1', 'true'], true) ? '1' : '0';
        }
        if (isset($in['chunk_days'])) {
            $s['chunk_days'] = max(1, min(31, (int) $in['chunk_days']));
        }
        update_site_option(self::OPTION, $s);
        return true;
    }
}
