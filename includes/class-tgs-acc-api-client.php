<?php
/**
 * Gọi API phần mềm kế toán.
 *
 * Phản hồi luôn theo khuôn { ok: true, data, meta } | { ok: false, error: { code, message } }.
 * Lỗi mạng / lỗi API trả WP_Error; thành công trả mảng `data` (kèm `meta` nếu có).
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Api_Client
{
    /** @var string */
    private $base;
    /** @var string */
    private $key;
    /** @var int giây chờ riêng cho lượt gọi (0 = theo Cài đặt) */
    private $timeout = 0;
    // [2026-11-23 12:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 — nhánh khoá cài đặt (chain): header X-OKT-Blog / X-OKT-Install; blog của client để ghi trạng thái nối.
    /** @var array<string,string> header thêm cho mọi lượt gọi có khoá */
    private $headers = [];
    /** @var int blog mà client gọi thay (0 = không gắn blog) */
    private $blog = 0;

    // [2026-11-22 06:30 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — thời gian chờ cho cả request hiện tại (gửi tức thời đặt 5 s rồi trả lại 0).
    /** @var int */
    public static $request_timeout = 0;

    /** Định danh kết nối (URL + khoá + blog) — để đệm theo đúng tenant (I-1), không lộ khoá. Khoá cài đặt dùng chung nhiều blog → tách theo X-OKT-Blog. */
    public function identity()
    {
        return substr(md5($this->base . '|' . $this->key . (isset($this->headers['X-OKT-Blog']) ? '|blog:' . $this->headers['X-OKT-Blog'] : '')), 0, 12);
    }

    /** Thêm header cho mọi lượt gọi có khoá (X-OKT-Blog, X-OKT-Install). */
    public function with_headers(array $h)
    {
        $this->headers = array_merge($this->headers, array_map('strval', $h));
        return $this;
    }

    /** 'own' | 'install' | 'legacy' | 'custom' — client đang đi khoá nào (để hiện / test). */
    public function mode()
    {
        return isset($this->headers['X-OKT-Blog']) ? 'install' : ($this->blog > 0 ? 'blog' : 'custom');
    }

    /** Gắn client vào nhánh khoá cài đặt cho blog $blog_id (mô hình chain, blog không có khoá riêng). */
    private function use_install($blog_id)
    {
        $this->blog = (int) $blog_id;
        $this->key = TGS_Acc_Install::key();
        $this->headers = array_merge($this->headers, TGS_Acc_Install::headers($blog_id));
    }

    public function __construct($base = '', $key = '')
    {
        $this->base = rtrim($base !== '' ? $base : TGS_Acc_Config::url(), '/');
        $this->key  = $key !== '' ? $key : TGS_Acc_Config::key();
        // OKT-SET-2 — client mặc định của blog hiện tại: nhánh chain đi khoá cài đặt + X-OKT-Blog (khoá riêng của blog vẫn thắng).
        $cur = TGS_Acc_Config::current_blog();
        if ($key === '' && $cur > 0 && TGS_Acc_Config::key_mode($cur) === 'install') {
            $this->use_install($cur);
        }
    }

    /** Client đúng URL + khoá truyền vào (không lấy mặc định của blog hiện tại; '' giữ nguyên là rỗng). */
    public static function raw($base, $key)
    {
        $c = new self(' ', ' ');
        $c->base = rtrim(trim((string) $base), '/');
        $c->key = trim((string) $key);
        return $c;
    }

    /** Nhật ký gọi gần nhất (vòng 60 lượt, site option) — để trang "Cổng kế toán" xem nhanh. */
    const CALL_LOG = 'tgs_acc_bridge_calls';
    const CALL_LOG_MAX = 60;

    public static function recent_calls($n = 30)
    {
        $log = get_site_option(self::CALL_LOG, []);
        return array_slice(is_array($log) ? $log : [], 0, (int) $n);
    }

    private static function remember_call($method, $path, $http, $ms, $ok, $message, $count = null)
    {
        $log = get_site_option(self::CALL_LOG, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, array_filter([
            'at'     => current_time('mysql'),
            'method' => $method,
            'path'   => strtok($path, '?'),
            'http'   => (int) $http,
            'ms'     => (int) $ms,
            'ok'     => $ok ? 1 : 0,
            'msg'    => mb_substr((string) $message, 0, 300),
            'count'  => $count,
            'user'   => get_current_user_id() ?: null,
            'cli'    => PHP_SAPI === 'cli' ? 1 : null,
        ], static function ($v) { return $v !== null && $v !== ''; }));
        update_site_option(self::CALL_LOG, array_slice($log, 0, self::CALL_LOG_MAX));
    }

    /** Tình trạng máy chủ kế toán (công khai, không cần khoá): phiên bản, cơ sở dữ liệu. */
    public function health()
    {
        $res = $this->request('GET', '/api/v1/system/health', null, false);
        return is_wp_error($res) ? $res : (array) ($res['data'] ?? []);
    }

    public function ready()
    {
        return $this->base !== '' && $this->key !== '';
    }

    /**
     * Gửi tối đa 200 chứng từ.
     * @return array|WP_Error  ['results' => [...], 'summary' => [...]]
     */
    public function send_batch(array $documents)
    {
        $res = $this->request('POST', '/api/v1/documents/batch', ['documents' => array_values($documents)]);
        if (is_wp_error($res)) {
            return $res;
        }
        return ['results' => (array) $res['data'], 'summary' => (array) ($res['meta'] ?? [])];
    }

    /** Huỷ chứng từ theo khoá (bên kế toán ghi số âm để đảo). */
    public function cancel($doc_key, $reason)
    {
        $res = $this->request('POST', '/api/v1/documents/cancel', ['docKey' => $doc_key, 'reason' => $reason]);
        return is_wp_error($res) ? $res : (array) $res['data'];
    }

    /** Kiểm tra kết nối + khoá. */
    public function ping()
    {
        $res = $this->request('GET', '/api/v1/branches');
        return is_wp_error($res) ? $res : true;
    }

    /**
     * Đọc một báo cáo / danh sách bên kế toán (khoá API cần phạm vi reports:read).
     * @return array|WP_Error  ['data' => …, 'meta' => …]
     */
    public function get($path, array $query = [])
    {
        $res = $this->request('GET', $path . ($query ? '?' . http_build_query($query) : ''));
        return is_wp_error($res) ? $res : ['data' => $res['data'], 'meta' => (array) ($res['meta'] ?? [])];
    }

    /**
     * Gửi một lệnh ghi bất kỳ (POST JSON). @return array|WP_Error `data` của phản hồi
     */
    public function post_json($path, array $body)
    {
        $res = $this->request('POST', $path, $body);
        return is_wp_error($res) ? $res : (array) $res['data'];
    }

    // [2026-11-24 08:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-1 — dựng sẵn tenant: PUT nhóm hàng (?new=1), bảng giá, gán chi nhánh.
    /** Gửi một lệnh ghi PUT JSON. @return array|WP_Error `data` của phản hồi */
    public function put_json($path, array $body)
    {
        $res = $this->request('PUT', $path, $body);
        return is_wp_error($res) ? $res : (array) ($res['data'] ?? []);
    }

    /**
     * Như get() nhưng chờ ngắn (8 giây): màn POS gọi khi mở trang (danh mục chi nhánh, ngân hàng, lý do; xác nhận nhận
     * tiền), kế toán không trả lời thì trang vẫn mở được. @return array|WP_Error ['data' => …, 'meta' => …]
     */
    public function quick_get($path, array $query = [])
    {
        $this->timeout = 8;
        $res = $this->get($path, $query);
        $this->timeout = 0;
        return $res;
    }

    /**
     * Xác nhận nhận tiền của kế toán cho các phiếu chuyển tiền (docKey); lỗi thì WP_Error (màn POS coi như chưa xác nhận).
     * @return array|WP_Error [docKey => ['confirmedBy', 'confirmedAt', 'valid', …]]
     */
    public function receipt_confirmations(array $doc_keys)
    {
        $doc_keys = array_values(array_filter(array_map('strval', $doc_keys)));
        if (!$doc_keys) {
            return [];
        }
        $res = $this->quick_get('/api/v1/documents/receipt-confirmations', ['docKeys' => implode(',', $doc_keys)]);
        if (is_wp_error($res)) {
            return $res;
        }
        $out = [];
        foreach ((array) $res['data'] as $c) {
            $out[(string) ($c['docKey'] ?? '')] = $c;
        }
        return $out;
    }

    /**
     * Bảo đảm chi nhánh tồn tại bên kế toán (khoá API cần phạm vi catalog:write).
     * @return true|WP_Error
     */
    public function ensure_branch($code, $name, $external_ref = '')
    {
        $list = $this->request('GET', '/api/v1/branches');
        if (is_wp_error($list)) {
            return $list;
        }
        foreach ((array) $list['data'] as $b) {
            if ((string) ($b['code'] ?? '') === (string) $code) {
                return true;
            }
        }
        $res = $this->request('POST', '/api/v1/branches', array_filter(['code' => (string) $code, 'name' => (string) $name, 'externalRef' => (string) $external_ref]));
        return is_wp_error($res) ? $res : true;
    }

    // [2026-11-21 Võ Hoàng] NT-BH-035 §3.6 — thêm khách trên máy bán không cần đơn: tìm theo SĐT, tạo qua core.partner.upsert (đóng góp)
    /** SĐT chỉ còn chữ số; 84xxxxxxxxx (11 số) → 0xxxxxxxxx như POS cũ. */
    public static function phone_digits($phone)
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($d) === 11 && strpos($d, '84') === 0) {
            $d = '0' . substr($d, 2);
        }
        return $d;
    }

    /**
     * Tìm đối tượng bên kế toán theo SĐT (khoá API cần catalog:read): mã = SĐT (khách POS tạo kèm đơn lấy SĐT làm mã) hoặc
     * `mobile` / `phone` trùng (so chữ số). Nhiều đối tượng khớp thì ưu tiên: đang dùng + là khách hàng, rồi mã = SĐT.
     * @return array|null|WP_Error đối tượng (khuôn GET /api/v1/partners) hoặc null khi chưa có
     */
    public function find_partner_by_phone($phone)
    {
        $phone = self::phone_digits($phone);
        if ($phone === '') {
            return null;
        }
        $res = $this->get('/api/v1/partners', ['search' => $phone, 'page' => 1, 'pageSize' => 50]);
        if (is_wp_error($res)) {
            return $res;
        }
        $best = null;
        $best_score = -1;
        foreach ((array) $res['data'] as $p) {
            $p = (array) $p;
            $code_hit = (string) ($p['code'] ?? '') === $phone;
            $phone_hit = self::phone_digits($p['mobile'] ?? '') === $phone || self::phone_digits($p['phone'] ?? '') === $phone;
            if (!$code_hit && !$phone_hit) {
                continue;
            }
            $score = (self::is_active_customer($p) ? 4 : 0) + ($code_hit ? 2 : 0) + (!empty($p['isActive']) ? 1 : 0);
            if ($score > $best_score) {
                $best = $p;
                $best_score = $score;
            }
        }
        return $best;
    }

    /** Đối tượng đang dùng và là khách hàng (vai trò chính hoặc vai trò thêm) — máy bán chỉ tìm thấy loại này. */
    public static function is_active_customer(array $p)
    {
        $active = !array_key_exists('isActive', $p) || $p['isActive'] !== false;
        return $active && ((string) ($p['kind'] ?? '') === 'customer' || in_array('customer', (array) ($p['alsoKinds'] ?? []), true));
    }

    /**
     * Tạo / cập nhật đối tượng theo mã (POST /api/v1/partners/upsert = core.partner.upsert; khoá API cần catalog:write).
     * Trường rỗng không gửi (bên kế toán giữ giá trị cũ). @return array|WP_Error ['id' => …, 'code' => …]
     */
    public function upsert_partner(array $p)
    {
        $body = ['code' => (string) ($p['code'] ?? ''), 'name' => (string) ($p['name'] ?? ''), 'kind' => (string) ($p['kind'] ?? 'customer')];
        foreach (['phone', 'address', 'taxCode', 'email'] as $k) {
            $v = trim((string) ($p[$k] ?? ''));
            if ($v !== '') {
                $body[$k] = $v;
            }
        }
        return $this->post_json('/api/v1/partners/upsert', $body);
    }

    /**
     * [2026-11-15 Nguyễn Văn Thủy] Tải nội dung một tệp (vd ảnh mặt hàng đã tải lên phần mềm kế toán) bằng khoá API.
     * @return array{body:string,type:string}|WP_Error (đóng góp)
     */
    public function download($path)
    {
        if ($this->base === '') {
            return new WP_Error('ketoan_not_configured', 'Chưa khai địa chỉ phần mềm kế toán');
        }
        $resp = wp_remote_get($this->base . $path, ['timeout' => 60, 'headers' => ['X-Api-Key' => $this->key] + $this->headers]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 400) {
            return new WP_Error('ketoan_download', "Không tải được tệp (HTTP {$code})");
        }
        return ['body' => (string) wp_remote_retrieve_body($resp), 'type' => (string) wp_remote_retrieve_header($resp, 'content-type')];
    }

    private function request($method, $path, $body = null, $with_key = true)
    {
        if ($this->base === '') {
            return new WP_Error('ketoan_not_configured', 'Chưa khai địa chỉ phần mềm kế toán (Quản trị → Cổng kế toán → Cài đặt, hoặc TGS_KETOAN_API_URL).');
        }
        $args = [
            'method'  => $method,
            'timeout' => $this->timeout ?: (self::$request_timeout ?: ((int) TGS_Acc_Config::get('timeout') ?: 60)),
            'headers' => ['X-Api-Key' => $this->key, 'Accept' => 'application/json'] + $this->headers,
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        if (!$with_key) {
            $args['headers'] = ['Accept' => 'application/json'] + (isset($args['headers']['Content-Type']) ? ['Content-Type' => 'application/json'] : []);
        }
        $count = ($body !== null && isset($body['documents'])) ? count((array) $body['documents']) : null;
        $t0 = microtime(true);
        $resp = wp_remote_request($this->base . $path, $args);
        $ms = (microtime(true) - $t0) * 1000;
        if (is_wp_error($resp)) {
            self::remember_call($method, $path, 0, $ms, false, $resp->get_error_message(), $count);
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json)) {
            self::remember_call($method, $path, $code, $ms, false, 'Không phải JSON', $count);
            return new WP_Error('ketoan_bad_response', "Phần mềm kế toán trả về không phải JSON (HTTP {$code})");
        }
        // /system/health trả thẳng đối tượng, không theo khuôn { ok, data }.
        if (!$with_key && !isset($json['ok'])) {
            self::remember_call($method, $path, $code, $ms, $code < 400, '', $count);
            return ['ok' => true, 'data' => $json];
        }
        self::remember_call($method, $path, $code, $ms, !empty($json['ok']), (string) ($json['error']['message'] ?? ''), $count);
        if (empty($json['ok'])) {
            $err = (array) ($json['error'] ?? []);
            $msg = (string) ($err['message'] ?? 'Lỗi không rõ');
            if (!empty($err['details']) && is_array($err['details'])) {
                $msg .= ' — ' . wp_json_encode($err['details'], JSON_UNESCAPED_UNICODE);
            }
            $reason = (string) ($err['details']['reason'] ?? '');
            // OKT-SET-2 — blog chưa nối / open-ketoan chưa biết blog: ghi trạng thái để hàng chờ GIỮ đơn (không tính lần thử, I-3).
            if ($this->blog > 0 && isset($this->headers['X-OKT-Blog']) && in_array($reason, TGS_Acc_Install::NOT_LINKED, true)) {
                TGS_Acc_Install::mark($this->blog, $reason === 'BLOG_UNKNOWN' ? 'unknown' : ((string) ($err['details']['status'] ?? '') ?: 'pending'));
                $msg = TGS_Acc_Install::HOLD_MSG . ' — ' . $msg;
            }
            return new WP_Error((string) ($err['code'] ?? 'ketoan_error'), $msg, ['status' => $code, 'reason' => $reason]);
        }
        if ($this->blog > 0 && isset($this->headers['X-OKT-Blog']) && TGS_Acc_Install::status($this->blog) !== 'active') {
            TGS_Acc_Install::mark($this->blog, 'active'); // gọi được bằng (khoá cài đặt, blog) = blog đã nối
        }
        return $json;
    }

    // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-12 — client theo blog cho cron / gửi lại: chạy ở site chính vẫn dùng đúng khoá + URL của blog có phiếu (ADR-OKT-1 I-1).
    /**
     * Client cho MỘT blog: URL = TGS_Acc_Config::url_for($blog), khoá = khoá riêng của blog (key_for). Blog chưa có khoá
     * riêng thì dùng khoá chung cũ của mạng (key_legacy) — y như trước khi có khoá theo blog, để shop đang chạy không vỡ.
     * $allow_legacy = false: chỉ khoá riêng (không có thì client chưa sẵn sàng — ready() sai).
     */
    public static function for_blog($blog_id, $allow_legacy = true)
    {
        $blog_id = (int) $blog_id;
        // [2026-11-23 12:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 — mô hình chain, blog không có khoá riêng: khoá cài đặt + X-OKT-Blog (open-ketoan đổi ra tenant của blog).
        if (TGS_Acc_Config::key_mode($blog_id) === 'install') {
            $c = self::raw(TGS_Acc_Config::url_for($blog_id), '');
            $c->use_install($blog_id);
            return $c;
        }
        $key = TGS_Acc_Config::key_for($blog_id);
        if ($key === '' && $allow_legacy) {
            $key = TGS_Acc_Config::key_legacy();
        }
        $url = TGS_Acc_Config::url_for($blog_id);
        // Khoá rỗng thì truyền một khoảng trắng: hàm dựng coi '' là "lấy theo cấu hình blog hiện tại" (đúng điều cần tránh ở cron).
        $c = new self($url !== '' ? $url : ' ', $key !== '' ? $key : ' ');
        if ($key === '') {
            $c->key = '';
        }
        if ($url === '') {
            $c->base = '';
        }
        return $c;
    }

}
