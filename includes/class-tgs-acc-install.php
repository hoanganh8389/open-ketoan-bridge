<?php
/**
 * KHOÁ CÀI ĐẶT open-pos + PING + SƠ ĐỒ KẾT NỐI (OKT-SET-2, OKT-SET-3 — luật tối thượng P-0; open-setup 82 B-3, B-4, B-6, B-7;
 * open-ketoan ADR-0037, docs/api/pos-installation.md).
 *
 * Mô hình `chain` (tự cài nhiều cửa hàng): MỘT máy open-ketoan, MỘT khoá cài đặt (okti_…) cho cả mạng WordPress, MỖI cửa hàng
 * (blog) là MỘT tenant riêng. Mọi lời gọi của blog N mang X-Api-Key: <khoá cài đặt> + X-OKT-Blog: N (+ X-OKT-Install: inst_…);
 * open-ketoan đổi (khoá, blog) ra tenant mà kế toán của sổ đó đã nối bằng mã nối. Bridge KHÔNG tự tạo tenant, KHÔNG chọn tenant.
 *
 *   - Blog có khoá riêng (key_for) vẫn dùng khoá riêng — khoá riêng thắng khoá cài đặt (một blog chuyển dần được).
 *   - Blog chưa nối (chờ mã nối / chưa đăng ký) → GIỮ đơn trong hàng chờ, không tính lần thử (I-3), nối xong cron gửi bù.
 *   - Ping 5 phút: chain → MỘT lượt POST /pos-installation/ping cho cả bản cài; bizcity / một cửa hàng → mỗi blog
 *     POST /connection/ping bằng khoá của nó. Kết quả lưu ở option mạng để vẽ sơ đồ.
 *
 * Không suy tenant / pháp nhân từ khoá hay blog id (P-0 ý 3): mã tenant chỉ ĐỌC từ open-ketoan để hiện.
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-23 12:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 / OKT-SET-3 — khoá cài đặt theo nhánh chain, đăng ký cửa hàng, giữ đơn khi chưa nối, ping, sơ đồ.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Install
{
    const OPTION = 'tgs_acc_install';   // option mạng: ['code', 'blogs' => [blog => dòng], 'synced_at', 'error']
    const PING_OPTION = 'tgs_acc_ping'; // option mạng: ['at', 'blogs' => [blog => …], 'hosts' => [url => …]]
    const PING_HOOK = 'tgs_acc_ping';
    const KEY_PREFIX = 'okti_';
    const PING_TIMEOUT = 5;
    const STALE_SECONDS = 900; // 15 phút, như open-ketoan PING_STALE_MS
    const HOLD_MSG = 'Cửa hàng chưa được kế toán nối vào sổ (chờ mã nối) — giữ trong hàng chờ, nối xong gửi bù';
    const NOT_LINKED = ['BLOG_NOT_LINKED', 'BLOG_UNKNOWN'];

    public static function init()
    {
        add_action(self::PING_HOOK, [__CLASS__, 'cron']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function schedule()
    {
        if (TGS_Acc_Site::current() === TGS_Acc_Site::main() && !wp_next_scheduled(self::PING_HOOK)) {
            wp_schedule_event(time() + 180, 'tgs_acc_5min', self::PING_HOOK);
        }
    }

    /* ───────────────────────────── khoá ───────────────────────────── */

    /** Khoá cài đặt: hằng TGS_KETOAN_INSTALL_KEY → option mạng (Cài đặt). '' = chưa có. */
    public static function key()
    {
        if (defined('TGS_KETOAN_INSTALL_KEY') && TGS_KETOAN_INSTALL_KEY !== '') {
            return trim((string) TGS_KETOAN_INSTALL_KEY);
        }
        return trim((string) TGS_Acc_Config::get('install_key'));
    }

    /** 'wp-config' | 'option' | '' */
    public static function key_source()
    {
        if (defined('TGS_KETOAN_INSTALL_KEY') && TGS_KETOAN_INSTALL_KEY !== '') {
            return 'wp-config';
        }
        return self::key() !== '' ? 'option' : '';
    }

    /** Nhánh khoá cài đặt đang bật: mô hình chain + có khoá cài đặt. */
    public static function active()
    {
        return class_exists('TGS_Acc_Profile') && TGS_Acc_Profile::current() === 'chain' && self::key() !== '';
    }

    /** Mã bản cài (inst_…): hằng TGS_KETOAN_INSTALL_CODE → mã open-ketoan trả lúc đăng ký. '' = chưa biết (không gửi X-OKT-Install). */
    public static function code()
    {
        if (defined('TGS_KETOAN_INSTALL_CODE') && TGS_KETOAN_INSTALL_CODE !== '') {
            return (string) TGS_KETOAN_INSTALL_CODE;
        }
        return (string) (self::state()['code'] ?? '');
    }

    /** Header của MỘT blog theo nhánh khoá cài đặt. */
    public static function headers($blog_id)
    {
        $h = ['X-OKT-Blog' => (string) (int) $blog_id];
        if (self::code() !== '') {
            $h['X-OKT-Install'] = self::code();
        }
        return $h;
    }

    /* ───────────────────────────── trạng thái blog ───────────────────────────── */

    public static function state()
    {
        $s = get_site_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];
        $s['blogs'] = isset($s['blogs']) && is_array($s['blogs']) ? $s['blogs'] : [];
        return $s;
    }

    private static function save_state(array $s)
    {
        update_site_option(self::OPTION, $s);
    }

    /** '' (chưa đăng ký) | pending | active | revoked | unknown (open-ketoan không biết blog — đăng ký lại). */
    public static function status($blog_id)
    {
        return (string) (self::state()['blogs'][(int) $blog_id]['status'] ?? '');
    }

    /** Blog đi khoá cài đặt mà chưa nối vào sổ → giữ đơn (không gửi, không tính lần thử). */
    public static function holds($blog_id)
    {
        return TGS_Acc_Config::key_mode($blog_id) === 'install' && self::status($blog_id) !== 'active';
    }

    /** Ghi trạng thái một blog (client gọi khi open-ketoan trả BLOG_NOT_LINKED / BLOG_UNKNOWN hoặc gọi được). Chỉ ghi khi đổi. */
    public static function mark($blog_id, $status)
    {
        $s = self::state();
        $b = (int) $blog_id;
        if ((string) ($s['blogs'][$b]['status'] ?? '') === (string) $status) {
            return;
        }
        $s['blogs'][$b] = array_merge((array) ($s['blogs'][$b] ?? []), ['status' => (string) $status, 'seen_at' => gmdate('c')]);
        self::save_state($s);
    }

    /** Ghép danh sách blog open-ketoan trả (đăng ký / trạng thái / ping) vào trạng thái lưu. */
    private static function merge(array $data)
    {
        $s = self::state();
        if (!empty($data['installationCode'])) {
            $s['code'] = (string) $data['installationCode'];
        }
        foreach ((array) ($data['blogs'] ?? []) as $r) {
            $r = (array) $r;
            $b = (int) ($r['blogId'] ?? 0);
            if ($b <= 0) {
                continue;
            }
            $cur = (array) ($s['blogs'][$b] ?? []);
            $row = [
                'status'       => (string) ($r['status'] ?? ($cur['status'] ?? '')),
                'tenant'       => (string) ($r['tenantCode'] ?? ''),
                'linked_at'    => (string) ($r['linkedAt'] ?? ''),
                'last_ping_at' => (string) ($r['lastPingAt'] ?? ''),
                'last_ping_ms' => isset($r['lastPingMs']) ? (int) $r['lastPingMs'] : null,
                'stale'        => !empty($r['stale']),
                'seen_at'      => gmdate('c'),
            ];
            // Mã nối chỉ có lúc open-ketoan sinh — giữ để hiện cho admin tới khi nối xong / hết hạn.
            if (!empty($r['linkCode'])) {
                $row['link_code'] = (string) $r['linkCode'];
                $row['link_expires'] = (string) ($r['linkCodeExpiresAt'] ?? '');
            } elseif ($row['status'] === 'pending' && !empty($cur['link_code'])) {
                $row['link_code'] = (string) $cur['link_code'];
                $row['link_expires'] = (string) ($cur['link_expires'] ?? '');
            }
            $s['blogs'][$b] = $row;
        }
        $s['synced_at'] = gmdate('c');
        $s['error'] = '';
        self::save_state($s);
        return $s;
    }

    private static function fail_state($msg)
    {
        $s = self::state();
        $s['error'] = (string) $msg;
        self::save_state($s);
    }

    /** Client của BẢN CÀI (không X-OKT-Blog): chỉ gọi được route /pos-installation/*. */
    public static function client()
    {
        $c = TGS_Acc_Api_Client::raw(TGS_Acc_Config::url_for(0), self::key());
        return self::code() !== '' ? $c->with_headers(['X-OKT-Install' => self::code()]) : $c;
    }

    /** Blog đi khoá cài đặt (chain, không có khoá riêng). */
    public static function install_blogs()
    {
        return array_values(array_filter(TGS_Acc_Site::blogs(), function ($b) {
            return TGS_Acc_Config::key_mode($b) === 'install';
        }));
    }

    /**
     * Đăng ký blog với open-ketoan (gửi lại được — đặt trạng thái tuyệt đối). Blog mới → pending + mã nối 8 ký tự (24 giờ).
     * $renew: blog đang chờ cần mã nối mới. @return array|WP_Error trạng thái lưu
     */
    public static function register(array $blog_ids = [], array $renew = [])
    {
        if (!self::active()) {
            return new WP_Error('tgs_acc_install_off', 'Chưa bật nhánh khoá cài đặt: chọn mô hình "Tôi có nhiều cửa hàng" và khai khoá cài đặt');
        }
        $ids = $blog_ids ? array_map('intval', $blog_ids) : self::install_blogs();
        $renew = array_map('intval', $renew);
        $blogs = [];
        foreach ($ids as $b) {
            if ($b <= 0 || !TGS_Acc_Site::exists($b)) {
                continue;
            }
            $blogs[] = array_filter([
                'blogId'        => $b,
                'name'          => mb_substr(TGS_Acc_Site::name($b) ?: ('Blog ' . $b), 0, 200),
                'url'           => (string) TGS_Acc_Site::opt($b, 'home', ''),
                'renewLinkCode' => in_array($b, $renew, true) ? true : null,
            ], static function ($v) { return $v !== null && $v !== ''; });
        }
        if (!$blogs) {
            return new WP_Error('tgs_acc_install_none', 'Không có cửa hàng nào đi khoá cài đặt để đăng ký');
        }
        $res = self::client()->post_json('/api/v1/pos-installation/blogs', ['blogs' => $blogs]);
        if (is_wp_error($res)) {
            self::fail_state($res->get_error_message());
            return $res;
        }
        return self::merge($res);
    }

    /** Đọc trạng thái mọi blog của bản cài từ open-ketoan. @return array|WP_Error */
    public static function refresh()
    {
        if (!self::active()) {
            return new WP_Error('tgs_acc_install_off', 'Chưa bật nhánh khoá cài đặt');
        }
        $res = self::client()->get('/api/v1/pos-installation/blogs');
        if (is_wp_error($res)) {
            self::fail_state($res->get_error_message());
            return $res;
        }
        return self::merge((array) $res['data']);
    }

    /* ───────────────────────────── ping ───────────────────────────── */

    /** Hàng chờ của blog: số nhóm + số phiếu chưa gửi (đọc tệp retry, không gọi mạng). */
    public static function queue_counts($blog_id)
    {
        $groups = 0;
        $docs = 0;
        if (class_exists('TGS_Acc_Events')) {
            foreach (TGS_Acc_Events::retry_state() as $r) {
                if (!empty($r['closed']) || (int) ($r['blog'] ?? 0) !== (int) $blog_id || (string) ($r['target'] ?? '') !== '') {
                    continue;
                }
                $groups++;
                $docs += count((array) ($r['ledger'] ?? [])) + count((array) ($r['ct'] ?? [])) + count((array) ($r['gt'] ?? []));
            }
        }
        return ['groups' => $groups, 'docs' => $docs];
    }

    /** Lịch 5 phút (site chính). */
    public static function cron()
    {
        self::ping();
    }

    /**
     * Ping mọi blog có đường nối, theo mô hình. Không ghi kế toán ngoài bảng ping của open-ketoan. @return array PING_OPTION mới
     * $deps (test): 'blogs' => int[], 'health' => callable(url) → array|WP_Error, 'now' => ts
     */
    public static function ping(array $deps = [])
    {
        $now = (int) ($deps['now'] ?? time());
        $blogs = $deps['blogs'] ?? array_values(array_filter(TGS_Acc_Site::blogs(), function ($b) {
            return TGS_Acc_Config::key_mode($b) !== 'none' && TGS_Acc_Config::url_for($b) !== '';
        }));
        $out = ['at' => gmdate('c', $now), 'profile' => class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::current() : '', 'blogs' => [], 'hosts' => []];
        TGS_Acc_Api_Client::$request_timeout = self::PING_TIMEOUT;
        try {
            // 1. Mỗi máy open-ketoan một lần health (độ trễ, phiên bản, đồng hồ). Máy không trả lời → bỏ qua ping các blog trỏ máy đó.
            foreach ($blogs as $b) {
                $url = TGS_Acc_Config::url_for($b);
                if (isset($out['hosts'][$url])) {
                    continue;
                }
                $t0 = microtime(true);
                $h = isset($deps['health']) ? call_user_func($deps['health'], $url) : TGS_Acc_Api_Client::raw($url, '')->health();
                $ms = (int) round((microtime(true) - $t0) * 1000);
                if (is_wp_error($h)) {
                    $out['hosts'][$url] = ['ok' => false, 'ms' => $ms, 'msg' => $h->get_error_message()];
                } else {
                    $st = strtotime((string) ($h['time'] ?? ''));
                    $out['hosts'][$url] = ['ok' => true, 'ms' => $ms, 'version' => (string) ($h['version'] ?? ($h['appVersion'] ?? '')), 'skew' => $st ? abs($now - $st) : null];
                }
            }
            // 2. chain: một lượt cho cả bản cài
            $inst = array_values(array_filter($blogs, function ($b) { return TGS_Acc_Config::key_mode($b) === 'install'; }));
            if ($inst) {
                $url = TGS_Acc_Config::url_for(0);
                $host = $out['hosts'][$url] ?? ['ok' => true, 'ms' => 0];
                $payload = [];
                foreach ($inst as $b) {
                    $q = self::queue_counts($b);
                    $payload[] = ['blogId' => (int) $b, 'bridgeVersion' => TGS_ACC_BRIDGE_VERSION, 'queueGroups' => $q['groups'], 'pendingDocs' => $q['docs'], 'latencyMs' => (int) $host['ms']];
                }
                $t0 = microtime(true);
                $res = empty($host['ok']) ? new WP_Error('tgs_acc_host_down', 'Máy open-ketoan không trả lời: ' . ($host['msg'] ?? '')) : self::client()->post_json('/api/v1/pos-installation/ping', ['blogs' => $payload]);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                if (is_wp_error($res)) {
                    foreach ($inst as $b) {
                        $out['blogs'][$b] = ['ok' => false, 'ms' => $ms, 'kind' => 'install', 'msg' => $res->get_error_message()];
                    }
                } else {
                    $s = self::merge($res);
                    foreach ($inst as $b) {
                        $st = (string) ($s['blogs'][$b]['status'] ?? '');
                        $out['blogs'][$b] = ['ok' => $st === 'active', 'ms' => $ms, 'kind' => 'install', 'status' => $st, 'tenant' => (string) ($s['blogs'][$b]['tenant'] ?? ''),
                            'msg' => in_array($b, array_map('intval', (array) ($res['unknown'] ?? [])), true) ? 'open-ketoan chưa biết blog này — bấm Đăng ký' : ($st === 'active' ? '' : 'Chờ kế toán nhập mã nối')];
                    }
                }
            }
            // 3. khoá thường (bizcity · một cửa hàng · blog chain có khoá riêng · khoá chung cũ): mỗi blog một lượt bằng khoá của nó
            foreach ($blogs as $b) {
                if (isset($out['blogs'][$b])) {
                    continue;
                }
                $url = TGS_Acc_Config::url_for($b);
                if (empty($out['hosts'][$url]['ok'])) {
                    $out['blogs'][$b] = ['ok' => false, 'ms' => 0, 'kind' => TGS_Acc_Config::key_mode($b), 'msg' => 'Máy open-ketoan không trả lời'];
                    continue;
                }
                $q = self::queue_counts($b);
                $t0 = microtime(true);
                $res = TGS_Acc_Api_Client::for_blog($b)->post_json('/api/v1/connection/ping', [
                    'blogId' => (int) $b, 'name' => mb_substr(TGS_Acc_Site::name($b) ?: ('Blog ' . $b), 0, 200), 'url' => (string) TGS_Acc_Site::opt($b, 'home', ''),
                    'bridgeVersion' => TGS_ACC_BRIDGE_VERSION, 'queueGroups' => $q['groups'], 'pendingDocs' => $q['docs'], 'latencyMs' => (int) $out['hosts'][$url]['ms'],
                ]);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $out['blogs'][$b] = is_wp_error($res)
                    ? ['ok' => false, 'ms' => $ms, 'kind' => TGS_Acc_Config::key_mode($b), 'msg' => $res->get_error_message()]
                    : ['ok' => true, 'ms' => $ms, 'kind' => TGS_Acc_Config::key_mode($b), 'tenant' => (string) ($res['tenantCode'] ?? '')];
            }
        } finally {
            TGS_Acc_Api_Client::$request_timeout = 0;
        }
        update_site_option(self::PING_OPTION, $out);
        return $out;
    }

    /* ───────────────────────────── sơ đồ ───────────────────────────── */

    /**
     * Dữ liệu cho màn Cài đặt (ma trận ba mô hình) + Sơ đồ kết nối: mô hình, khoá cài đặt (che), từng blog (khoá, máy, tenant,
     * trạng thái nối, mã nối, ping, hàng chờ, tự kiểm), từng máy open-ketoan (ping, phiên bản, lệch đồng hồ, số tenant).
     */
    public static function topology()
    {
        $p = class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::current() : '';
        $st = self::state();
        $ping = get_site_option(self::PING_OPTION, []);
        $ping = is_array($ping) ? $ping : [];
        $blogs = [];
        $hosts = [];
        foreach (TGS_Acc_Site::blogs() as $b) {
            $mode = TGS_Acc_Config::key_mode($b);
            $url = TGS_Acc_Config::url_for($b);
            $row = (array) ($st['blogs'][$b] ?? []);
            $pg = (array) ($ping['blogs'][$b] ?? []);
            $last = class_exists('TGS_Acc_Selfcheck') ? TGS_Acc_Site::opt($b, TGS_Acc_Selfcheck::LAST_OPTION, null) : null;
            $confirmed = class_exists('TGS_Acc_Selfcheck') ? (string) TGS_Acc_Site::opt($b, TGS_Acc_Selfcheck::TENANT_OPTION, '') : '';
            // Khoá cài đặt: tenant CHỈ lấy từ open-ketoan (dòng đã nối) — không lấy từ lần tự kiểm cũ bằng khoá khác (P-0: không suy tenant).
            $tenant = $mode === 'install' ? (string) ($row['tenant'] ?? '')
                : ($mode === 'none' ? '' : ((string) ($pg['tenant'] ?? '') ?: (is_array($last) ? (string) ($last['tenant'] ?? '') : '')));
            $q = self::queue_counts($b);
            $expires = (string) ($row['link_expires'] ?? '');
            $blogs[] = [
                'blog' => (int) $b, 'name' => TGS_Acc_Site::name($b), 'url' => (string) TGS_Acc_Site::opt($b, 'home', ''),
                'key_mode' => $mode, 'host' => $url, 'tenant' => $tenant, 'confirmed' => $confirmed,
                'link' => $mode === 'install' ? ((string) ($row['status'] ?? '') ?: 'unregistered') : '',
                'link_code' => $mode === 'install' && ($row['status'] ?? '') === 'pending' && ($expires === '' || strtotime($expires) > time()) ? (string) ($row['link_code'] ?? '') : '',
                'link_expires' => $expires,
                'ping' => $pg ?: null,
                'queue' => $q['groups'], 'pending_docs' => $q['docs'],
                'paused' => class_exists('TGS_Acc_Events') ? TGS_Acc_Events::paused_since($b) : '',
                'check' => is_array($last) ? (string) ($last['verdict'] ?? '') : '',
            ];
            if ($url !== '' && $mode !== 'none') {
                $hosts[$url] = $hosts[$url] ?? ['url' => $url, 'blogs' => 0, 'tenants' => []];
                $hosts[$url]['blogs']++;
                if ($tenant !== '') {
                    $hosts[$url]['tenants'][$tenant] = true;
                }
            }
        }
        foreach ($hosts as $u => $h) {
            $hosts[$u] = array_merge($h, (array) ($ping['hosts'][$u] ?? []), ['tenants' => count($h['tenants'])]);
        }
        return [
            'profile' => $p, 'label' => class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::label($p) : '',
            'source' => class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::source() : 'none',
            'locked' => class_exists('TGS_Acc_Profile') && TGS_Acc_Profile::locked(),
            'allowed' => class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::allowed() : [],
            'is_ms' => TGS_Acc_Site::is_ms(), 'site_count' => count($blogs),
            'constant' => defined('TGS_ACC_DEPLOYMENT') ? (string) TGS_ACC_DEPLOYMENT : '',
            'install' => [
                'active' => self::active(), 'key_masked' => TGS_Acc_Config::mask(self::key()), 'key_source' => self::key_source(),
                'code' => self::code(), 'synced_at' => (string) ($st['synced_at'] ?? ''), 'error' => (string) ($st['error'] ?? ''),
                'url' => TGS_Acc_Config::url_for(0),
            ],
            'blogs' => $blogs, 'hosts' => array_values($hosts),
            'ping_at' => (string) ($ping['at'] ?? ''), 'stale_seconds' => self::STALE_SECONDS,
        ];
    }
}

if (function_exists('add_action')) {
    TGS_Acc_Install::init();
}
