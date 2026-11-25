<?php
/**
 * Kết nối theo blog (SW2-1 / B001-C6): "blog này đang nối open-ketoan chưa?" — MỘT chỗ trả lời cho cả hệ thống.
 *
 *   TGS_Acc_Connection::connection($blog)   ['blog','state','ok','url','tenant','key_mode','checked_at','error','conditions'] — không có khoá
 *   TGS_Acc_Connection::connected($blog)    true khi cả bốn điều kiện đạt
 *   tgs_okt_connection($blog)               bản hàm của connection() (chữ ký chốt ở 00-MASTER-PLAN §4)
 *   tgs_okt_connected($blog)                hàm của tsm → filter `tgs_okt_connected` → TGS_Acc_Catalog_Sync::connected() → connected()
 *
 * Bốn điều kiện (đều phải đạt):
 *   1. config     blog có URL + một nhánh khoá (riêng / khoá cài đặt / khoá chung) — TGS_Acc_Config::ready_for.
 *   2. reachable  `GET /api/v1/tenant` bằng khoá của chính blog trả 2xx trong 3 giây (khoá đúng, máy chủ trả lời).
 *   3. tenant     kế toán cho biết mã tenant của blog.
 *   4. unique     không blog nào khác (khoá riêng / khoá cài đặt) cùng trỏ tenant này — luật SC-3 (P-0: mỗi blog một tenant). Blog dùng
 *                 khoá CHUNG của mạng không bị loại vì SC-3 (OKT-TOPO-8), chỉ ghi chú.
 *
 * Kết quả đệm 60 giây theo blog (site transient), kể cả khi hỏng — một lần kế toán chập chờn không làm trang nào chờ quá 3 giây mỗi phút.
 * Đổi trạng thái (connected ⇄ standalone) → `do_action('tgs_okt_connection_changed', $blog, $state, $info)`.
 *
 * Luật vai trò (P-0 ý 4): câu trả lời này chỉ để HIỂN THỊ và để cho phép thủ tục chuyển nguồn (SW2-2). Quầy bán KHÔNG đọc nó; nguồn danh mục
 * `tgs_catalog_source` do admin chọn, không đổi theo chập chờn mạng. Kế toán dừng thì shop vẫn bán, vẫn nhập tay được ở nguồn local.
 * Không suy tenant / pháp nhân từ khoá hay blog id — tenant là điều kế toán BÁO (P-0 ý 3).
 *
 * Không ghi vào kế toán; chỉ ghi: transient đệm, option `tgs_acc_conn_state` của blog, bản đồ blog → tenant (cùng option với tự kiểm SC-3).
 *
 * @package open-ketoan-bridge
 */
// [2026-11-25 08:20 AM Johnny Chu - Chu Hoàng Anh] SW2-1 / B001-C6 — câu hỏi kết nối theo blog.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Connection
{
    const TTL          = 60;   // giây đệm
    const TIMEOUT      = 3;    // giây chờ một lượt hỏi
    const TRANSIENT    = 'tgs_okt_conn_';
    const STATE_OPTION = 'tgs_acc_conn_state'; // option của blog: 'connected' | 'standalone' (lần gần nhất đã báo)

    /** Tên ngắn của bốn điều kiện, theo thứ tự hỏi. */
    const CONDITIONS = ['config', 'reachable', 'tenant', 'unique'];

    /** @var array<int, array> đệm trong một request (tránh gọi đọc transient lặp) */
    private static $memo = [];

    /**
     * @param int   $blog_id
     * @param bool  $fresh true: bỏ đệm, hỏi lại ngay
     * @param array $deps  cho test: ['client' => callable(blog): object có get($path), 'ready' => callable(blog): bool]
     * @return array{blog:int, state:string, ok:bool, url:string, tenant:string, key_mode:string, checked_at:string, error:string, conditions:array<string,array{ok:bool,note:string}>}
     */
    public static function connection($blog_id, $fresh = false, array $deps = [])
    {
        $blog_id = (int) $blog_id > 0 ? (int) $blog_id : (int) TGS_Acc_Site::current();
        if (!$fresh) {
            if (isset(self::$memo[$blog_id])) {
                return self::$memo[$blog_id];
            }
            $hit = get_site_transient(self::TRANSIENT . $blog_id);
            if (is_array($hit) && isset($hit['state'])) {
                return self::$memo[$blog_id] = $hit;
            }
        }
        $info = self::probe($blog_id, $deps);
        set_site_transient(self::TRANSIENT . $blog_id, $info, self::TTL);
        self::$memo[$blog_id] = $info;
        self::announce($blog_id, $info);
        return $info;
    }

    /** Câu trả lời đang đệm, KHÔNG hỏi mạng (cho màn liệt kê nhiều blog). null = chưa có / hết hạn. */
    public static function cached($blog_id)
    {
        $blog_id = (int) $blog_id;
        if (isset(self::$memo[$blog_id])) {
            return self::$memo[$blog_id];
        }
        $hit = get_site_transient(self::TRANSIENT . $blog_id);
        return is_array($hit) && isset($hit['state']) ? $hit : null;
    }

    /** Cả bốn điều kiện đạt? */
    public static function connected($blog_id = 0, array $deps = [])
    {
        return self::connection($blog_id, false, $deps)['ok'];
    }

    /** Quên đệm (đổi khoá / URL / tenant thì gọi). */
    public static function forget($blog_id = 0)
    {
        if ((int) $blog_id > 0) {
            unset(self::$memo[(int) $blog_id]);
            delete_site_transient(self::TRANSIENT . (int) $blog_id);
            return;
        }
        self::$memo = [];
    }

    /** Blog đang trỏ tenant $tenant (theo bản đồ tự kiểm / kết nối). @return int[] */
    public static function blogs_of_tenant($tenant)
    {
        $tenant = (string) $tenant;
        $out = [];
        if ($tenant === '') {
            return $out;
        }
        foreach ((array) get_site_option(TGS_Acc_Selfcheck::MAP_OPTION, []) as $b => $code) {
            if ((string) $code === $tenant && TGS_Acc_Config::key_mode((int) $b) !== 'none') {
                $out[] = (int) $b;
            }
        }
        sort($out);
        return $out;
    }

    // ── hỏi thật ────────────────────────────────────────────────────────────────

    private static function probe($blog_id, array $deps)
    {
        $mode = TGS_Acc_Config::key_mode($blog_id);
        $url = TGS_Acc_Config::url_for($blog_id);
        $info = ['blog' => $blog_id, 'state' => 'standalone', 'ok' => false, 'url' => $url, 'tenant' => '', 'key_mode' => $mode,
            'checked_at' => gmdate('c'), 'error' => '', 'conditions' => []];
        // $note là lời GIẢI THÍCH LỖI: chỉ giữ khi điều kiện hỏng (hoặc khi $keep = true: ghi chú cho điều kiện đạt nhưng đáng lưu ý).
        $cond = static function ($name, $ok, $note = '', $keep = false) use (&$info) {
            $info['conditions'][$name] = ['ok' => (bool) $ok, 'note' => ($ok && !$keep) ? '' : (string) $note];
            return (bool) $ok;
        };

        // 1. config
        $ready = isset($deps['ready']) ? (bool) call_user_func($deps['ready'], $blog_id) : TGS_Acc_Config::ready_for($blog_id);
        if (!$cond('config', $ready, $mode === 'none' ? 'Chưa có khoá kế toán cho shop này.' : ($url === '' ? 'Chưa có địa chỉ kế toán.' : 'Cấu hình kết nối chưa đủ.'))) {
            $info['error'] = $info['conditions']['config']['note'];
            return self::skip($info, ['reachable', 'tenant', 'unique']);
        }

        // 2. reachable (khoá đúng + máy chủ trả lời) — chờ tối đa 3 giây, trả lại giá trị cũ sau khi hỏi
        $client = isset($deps['client']) ? call_user_func($deps['client'], $blog_id) : TGS_Acc_Api_Client::for_blog($blog_id);
        if (is_wp_error($client)) {
            $info['error'] = $client->get_error_message();
            $cond('reachable', false, $info['error']);
            return self::skip($info, ['tenant', 'unique']);
        }
        $was = TGS_Acc_Api_Client::$request_timeout;
        TGS_Acc_Api_Client::$request_timeout = self::TIMEOUT;
        try {
            $t = $client->get('/api/v1/tenant');
        } finally {
            TGS_Acc_Api_Client::$request_timeout = $was;
        }
        if (is_wp_error($t)) {
            $info['error'] = $t->get_error_message();
            $cond('reachable', false, $info['error']);
            return self::skip($info, ['tenant', 'unique']);
        }
        $cond('reachable', true);

        // 3. tenant — điều kế toán BÁO (không suy từ khoá / blog id)
        $info['tenant'] = (string) ($t['data']['code'] ?? '');
        if (!$cond('tenant', $info['tenant'] !== '', 'Kế toán không báo mã tenant của khoá này.')) {
            $info['error'] = $info['conditions']['tenant']['note'];
            return self::skip($info, ['unique']);
        }
        self::remember($blog_id, $info['tenant']);

        // 4. unique — luật SC-3
        $twins = array_values(array_diff(self::blogs_of_tenant($info['tenant']), [$blog_id]));
        $own_twin = false;
        foreach ($twins as $b) {
            $own_twin = $own_twin || TGS_Acc_Config::key_mode($b) !== 'legacy';
        }
        $shared = $twins && $mode === 'legacy' && !$own_twin;
        if ($shared) {
            $cond('unique', true, 'Khoá chung của mạng đang dồn ' . (count($twins) + 1) . ' site vào tenant `' . $info['tenant'] . '` — mỗi cửa hàng nên có tenant riêng (P-0).', true);
        } elseif (!$cond('unique', !$twins, 'Tenant `' . $info['tenant'] . '` cũng đang nối với blog ' . implode(', ', $twins) . ' — sổ hai shop trộn nhau (SC-3).')) {
            $info['error'] = $info['conditions']['unique']['note'];
            return $info;
        }

        $info['ok'] = true;
        $info['state'] = 'connected';
        return $info;
    }

    /** Điều kiện sau điều kiện hỏng: ghi "chưa kiểm". */
    private static function skip(array $info, array $names)
    {
        foreach ($names as $n) {
            $info['conditions'][$n] = ['ok' => false, 'note' => 'Chưa kiểm (điều kiện trước chưa đạt).'];
        }
        return $info;
    }

    /** Ghi blog → tenant vào bản đồ chung với tự kiểm SC-3 (không ghi khi không đổi). */
    private static function remember($blog_id, $tenant)
    {
        $map = (array) get_site_option(TGS_Acc_Selfcheck::MAP_OPTION, []);
        if (($map[$blog_id] ?? null) !== $tenant) {
            $map[$blog_id] = $tenant;
            update_site_option(TGS_Acc_Selfcheck::MAP_OPTION, $map);
        }
    }

    /** Đổi trạng thái so với lần báo gần nhất → hook. */
    private static function announce($blog_id, array $info)
    {
        $last = (string) TGS_Acc_Site::opt($blog_id, self::STATE_OPTION, '');
        if ($last === $info['state']) {
            return;
        }
        TGS_Acc_Site::set($blog_id, self::STATE_OPTION, $info['state']);
        if ($last !== '') { // lần đầu hỏi không phải là "đổi"
            do_action('tgs_okt_connection_changed', $blog_id, $info['state'], $info);
        }
    }
}

if (!function_exists('tgs_okt_connection')) {
    /** Thông tin kết nối của blog (không có khoá thật). @return array */
    function tgs_okt_connection($blog_id = 0)
    {
        return TGS_Acc_Connection::connection((int) $blog_id);
    }
}
// `tgs_okt_connected($blog)` là hàm của tsm (đọc filter cùng tên, không bridge → false); bridge trả lời filter đó ở TGS_Acc_Catalog_Sync::connected()
// bằng TGS_Acc_Connection::connected() — để MỘT hàm duy nhất, không khai hai bản.
