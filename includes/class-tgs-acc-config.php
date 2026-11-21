<?php
/**
 * Cấu hình kết nối phần mềm kế toán.
 *
 * Hai nguồn, theo thứ tự ưu tiên:
 *   1. Hằng trong wp-config.php: TGS_KETOAN_API_URL, TGS_KETOAN_API_KEY (máy chủ thật nên dùng cách này);
 *   2. Site option của network (lưu từ trang "Cổng kế toán" — tiện khi chạy thử).
 * Khoá API không bao giờ trả ra trình duyệt: chỉ trả dạng che (kt_ab…xy).
 */

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

    private static function stored()
    {
        $v = get_site_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($v) ? $v : []);
    }

    public static function url()
    {
        if (defined('TGS_KETOAN_API_URL') && TGS_KETOAN_API_URL !== '') {
            return rtrim((string) TGS_KETOAN_API_URL, '/');
        }
        return rtrim((string) self::stored()['url'], '/');
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
        $w = rtrim((string) self::stored()['web_url'], '/');
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

    public static function key()
    {
        if (defined('TGS_KETOAN_API_KEY') && TGS_KETOAN_API_KEY !== '') {
            return (string) TGS_KETOAN_API_KEY;
        }
        return (string) self::stored()['key'];
    }

    /** Sổ thuế: địa chỉ + khoá API (hằng TGS_KETOAN_TAX_API_URL / TGS_KETOAN_TAX_API_KEY ưu tiên). Trống = không gửi sổ thuế. */
    public static function tax_url()
    {
        if (defined('TGS_KETOAN_TAX_API_URL') && TGS_KETOAN_TAX_API_URL !== '') {
            return rtrim((string) TGS_KETOAN_TAX_API_URL, '/');
        }
        return rtrim((string) (self::stored()['tax_url'] ?? ''), '/');
    }

    public static function tax_key()
    {
        if (defined('TGS_KETOAN_TAX_API_KEY') && TGS_KETOAN_TAX_API_KEY !== '') {
            return (string) TGS_KETOAN_TAX_API_KEY;
        }
        return (string) (self::stored()['tax_key'] ?? '');
    }

    public static function tax_ready()
    {
        return self::tax_url() !== '' && self::tax_key() !== '';
    }

    public static function get($name)
    {
        $s = self::stored();
        return $s[$name] ?? (self::defaults()[$name] ?? null);
    }

    public static function ready()
    {
        return self::url() !== '' && self::key() !== '';
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

    /** Dữ liệu an toàn để hiện trên trang (không có khoá thật). */
    public static function for_display()
    {
        return [
            'url'        => self::url(),
            'url_source' => self::source('url'),
            'web_url'    => self::web_url(),
            'web_source' => defined('TGS_KETOAN_WEB_URL') && TGS_KETOAN_WEB_URL !== '' ? 'wp-config' : ((string) self::stored()['web_url'] !== '' ? 'option' : ''),
            'key_masked' => self::mask(self::key()),
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
            'ready'      => self::ready(),
        ];
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
