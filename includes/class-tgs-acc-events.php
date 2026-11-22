<?php
/**
 * SỰ KIỆN CHỨNG TỪ — gửi tức thời sang phần mềm kế toán (ERP) mỗi khi phiếu được lưu / sửa / duyệt / xoá.
 *
 *   1. HỨNG: mọi câu lệnh GHI vào bảng phiếu của bất kỳ plugin nào (tgs_pos, tgs_shop_management, tgs-transfer-management,
 *      tgs_internal_transfer, tgs_purchase_management…) — {prefix}local_ledger, {prefix}local_ledger_item,
 *      {base}global_cash_transfer — được nhận qua bộ lọc 'query' của WordPress: INSERT lấy mã phiếu vừa tạo (insert_id),
 *      UPDATE / DELETE lấy mã phiếu bị đụng bằng một SELECT cùng điều kiện TRƯỚC khi câu lệnh chạy. Không phải sửa từng
 *      plugin; màn mới, plugin mới ghi vào các bảng đó cũng tự có sự kiện.
 *      Plugin muốn tự bắn (ví dụ chỉ đổi dữ liệu ở bảng khác ảnh hưởng chứng từ):
 *          do_action('tgs_acc/event', 'ledger', $local_ledger_id [, $blog_id]);
 *          do_action('tgs_acc/event', 'cash_transfer', $ct_id);
 *      Phiếu chuyển kho global (wp_global_transfer) không hứng qua 'query' mà qua hook 'tgs_global_transfer_changed' do
 *      TGS_Global_Transfer bắn sau mỗi lần ghi — dựng lại cả đầu xuất (shop đi) và đầu nhận (shop đến).
 *   2. GHI SỰ KIỆN: mọi sự kiện (có đổi nội dung hay không) ghi vào tệp JSONL toàn mạng, chia theo ngày:
 *          wp-content/uploads/tgs-acc-events/2026/10/2026-10-20.jsonl
 *      mỗi dòng một sự kiện {t, id, blog, kind, ref, op, src, user}; kết quả gửi của từng nhóm cũng ghi một dòng {t, res, …}.
 *   3. GỬI NGAY: cuối request (sau khi đã trả trang cho người dùng nếu máy chủ hỗ trợ) dựng lại ĐÚNG các phiếu đó
 *      (TGS_Acc_Pusher::build_refs) → gửi (TGS_Acc_Pusher::send: không đổi thì bỏ qua, đổi thì lên phiên bản mới) → phiếu
 *      không còn (xoá / bỏ duyệt) thì báo huỷ. Bên ERP: loại chứng từ đặt "Tự động định khoản" thì vào sổ luôn, "Vào cổng
 *      chờ" thì chờ kế toán ở trạm ACC14.
 *   4. GỬI LẠI: nhóm gửi lỗi (mạng, ERP bận, thiếu cấu hình…) ghi vào tệp …/YYYY-MM-DD.retry.jsonl; cron mỗi phút CHỈ đọc
 *      các tệp retry 7 ngày gần nhất và gửi lại (giãn cách 1, 2, 4… tối đa 60 phút; quá 30 lần thì bỏ, kéo tay bù).
 *      Không quét shop nào. Phiếu cũ trước khi bật tính năng: nút "Kéo chứng từ" ở trạm ACC14 (cổng chờ) lo.
 *
 *   5. HAI NƠI NHẬN: sổ nội bộ (mọi chứng từ) và SỔ THUẾ (nếu khai địa chỉ + khoá sổ thuế ở Cài đặt: chỉ phiếu bán / hoàn có
 *      số hoá đơn điện tử, không phải bill nội bộ, hoá đơn chưa huỷ — TGS_Acc_Gate::for_tax_book). Dựng một lần, gửi từng nơi
 *      với nhật ký gửi riêng (tgs_acc_push_log / tgs_acc_push_log_tax) và gửi lại riêng (target trong tệp retry). Phiếu mất số
 *      hoá đơn / bị huỷ hoá đơn thì sổ thuế nhận lệnh huỷ.
 *
 * Bật / tắt gửi tức thời: Cổng kế toán → Cài đặt (realtime), hoặc define('TGS_ACC_REALTIME', false) trong wp-config.
 * Tắt thì vẫn ghi sự kiện, chỉ không gửi. Cổng chờ (kéo) vẫn dùng được cho cả hai sổ để bù phiếu cũ / sót.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Events
{
    const DIR = 'tgs-acc-events';
    /** Một request đụng quá số phiếu này (khôi phục dữ liệu, nhập hàng loạt…): chỉ ghi một sự kiện "bulk", không tự gửi. */
    const MAX_REFS = 2000;
    const RETRY_DAYS = 7;
    const RETRY_MAX = 30;
    const CRON_HOOK = 'tgs_acc_events_retry';
    // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-10 — cron kéo trạng thái chứng từ chờ định khoản (5 phút, site chính, lặp theo blog).
    const STATUS_HOOK = 'tgs_acc_status_pull';
    // [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-13 — tạm dừng kết nối theo shop (option của blog, giữ khoá; R-OKT-CONN-12).
    const PAUSE_OPTION = 'tgs_acc_paused';
    // [2026-11-22 02:00 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — giữ hàng chờ của shop tạm dừng quá 7 ngày (K4): bản đồ cấp mạng blog => lúc bắt đầu tạm dừng.
    const PAUSED_MAP = 'tgs_acc_paused_map';
    const PAUSED_KEEP_DAYS = 90; // cửa sổ đọc tối đa khi có shop tạm dừng lâu
    const PAUSED_MSG = 'Tạm dừng kết nối của shop — gửi khi bỏ tạm dừng';

    /** Hàng đợi của request: "blog|kind" => [ref => op]. */
    private static $queue = [];
    private static $count = 0;
    private static $bulk = false;
    /** INSERT vừa chạy vào bảng phiếu: lấy insert_id ở câu lệnh kế tiếp / cuối request. */
    private static $pending_insert = null;
    /** Đang chạy truy vấn của chính lớp này (không hứng lại). */
    private static $busy = false;

    public static function init()
    {
        add_filter('query', [__CLASS__, 'on_query'], 9999);
        add_action('tgs_acc/event', [__CLASS__, 'add'], 10, 4);
        add_action('tgs_global_transfer_changed', [__CLASS__, 'on_global_transfer'], 10, 1);
        add_action('shutdown', [__CLASS__, 'flush'], 9999);
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'retry']);
        add_action(self::STATUS_HOOK, [__CLASS__, 'pull_status']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    /* ------------------------------------------------------------------ cấu hình */

    /** Gửi tức thời có bật không (vẫn ghi sự kiện khi tắt). */
    public static function enabled()
    {
        if (defined('TGS_ACC_REALTIME') && !TGS_ACC_REALTIME) {
            return false;
        }
        return (string) TGS_Acc_Config::get('realtime') !== '0' && (TGS_Acc_Config::ready() || TGS_Acc_Config::tax_ready());
    }

    /** Các nơi nhận đang khai: '' = sổ nội bộ, 'tax' = sổ thuế. */
    public static function targets()
    {
        $t = [];
        if (TGS_Acc_Config::ready()) {
            $t[] = '';
        }
        if (TGS_Acc_Config::tax_ready()) {
            $t[] = 'tax';
        }
        return $t;
    }

    public static function schedules($s)
    {
        $s['tgs_acc_minute'] = ['interval' => 60, 'display' => 'Mỗi phút (gửi lại sự kiện kế toán)'];
        if (!isset($s['tgs_acc_5min'])) {
            $s['tgs_acc_5min'] = ['interval' => 300, 'display' => 'Mỗi 5 phút (kế toán)'];
        }
        return $s;
    }

    /** Cron gửi lại chạy ở site tổng. */
    public static function schedule()
    {
        if (is_main_site() && !wp_next_scheduled(self::STATUS_HOOK)) {
            wp_schedule_event(time() + 120, 'tgs_acc_5min', self::STATUS_HOOK);
        }
        if (is_main_site() && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'tgs_acc_minute', self::CRON_HOOK);
        }
    }

    /* ------------------------------------------------------------------ hứng sự kiện */

    /** Bảng phiếu nào: ['blog' => id (0 = theo phiếu), 'kind' => ledger | item | cash_transfer] hoặc null. */
    private static function table_info($table)
    {
        global $wpdb;
        $bp = $wpdb->base_prefix;
        if ($table === $bp . 'global_cash_transfer') {
            return ['blog' => 0, 'kind' => 'cash_transfer'];
        }
        if (preg_match('/^' . preg_quote($bp, '/') . '(?:(\d+)_)?local_ledger(_item)?$/', $table, $m)) {
            return ['blog' => !empty($m[1]) ? (int) $m[1] : (int) get_main_site_id(), 'kind' => !empty($m[2]) ? 'item' : 'ledger'];
        }
        return null;
    }

    /** Phần WHERE của câu UPDATE / DELETE (bỏ ORDER BY / LIMIT ở cuối); không có WHERE thì null (không đoán). */
    private static function where_of($q)
    {
        if (!preg_match('/\sWHERE\s(.+)$/is', $q, $m)) {
            return null;
        }
        return preg_replace('/\s(ORDER\s+BY|LIMIT)\s.*$/is', '', $m[1]);
    }

    public static function on_query($q)
    {
        if (self::$busy) {
            return $q;
        }
        if (self::$pending_insert) {
            self::resolve_insert();
        }
        // [2026-11-12 10:30 AM Võ Hoàng] POS-MIRROR — đang ghi BẢN SAO kế toán (máy bán POS React, đơn dự phòng): gốc đã ở phần mềm kế toán, không hứng
        // (TGS_Acc_Source::not_mirror_sql chặn thêm ở bước đọc phiếu cho cổng chờ / quét theo ngày / gửi lại).
        // 14/11: lõi bản sao của cầu nối (phiếu kế toán lập về shop không cài POS — TGS_Acc_Ledger_Mirror) cũng không hứng.
        if ((class_exists('TGS_POS_Ledger_Mirror') && TGS_POS_Ledger_Mirror::is_mirroring()) || (class_exists('TGS_Acc_Ledger_Mirror') && TGS_Acc_Ledger_Mirror::is_mirroring())) {
            return $q;
        }
        // Lọc rẻ trước: phần lớn câu lệnh không đụng bảng phiếu.
        if (stripos($q, 'local_ledger') === false && stripos($q, 'global_cash_transfer') === false) {
            return $q;
        }
        if (!preg_match('/^\s*(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $q, $m)) {
            return $q;
        }
        $info = self::table_info($m[2]);
        if (!$info) {
            return $q;
        }
        $verb = strtoupper(strtok(trim($m[1]), " \t\n"));
        if ($verb === 'INSERT' || $verb === 'REPLACE') {
            // Dòng hàng thêm cùng lúc với phiếu (đã có sự kiện của phiếu); phiếu / phiếu chuyển tiền: lấy insert_id sau khi chạy.
            if ($info['kind'] !== 'item') {
                self::$pending_insert = $info;
            }
            return $q;
        }
        $where = self::where_of($q);
        if ($where === null || self::$bulk) {
            return $q;
        }
        // Lấy mã phiếu bị đụng TRƯỚC khi câu lệnh chạy (xoá rồi thì không còn để lấy).
        global $wpdb;
        $col = $info['kind'] === 'cash_transfer' ? 'ct_id' : 'local_ledger_id';
        self::$busy = true;
        // Câu UPDATE kiểu lạ (JOIN, bí danh bảng…) làm câu dò hỏng: ẩn lỗi, không in ra màn hình, bỏ qua sự kiện đó
        // (plugin có thể tự bắn do_action('tgs_acc/event', …); phiếu sót thì nút Kéo chứng từ bù).
        $prev = $wpdb->suppress_errors(true);
        $last_error = $wpdb->last_error;
        $ids = $wpdb->get_col("SELECT DISTINCT {$col} FROM `{$m[2]}` WHERE {$where} LIMIT " . (self::MAX_REFS + 1));
        $wpdb->last_error = $last_error;
        $wpdb->suppress_errors($prev);
        self::$busy = false;
        foreach ((array) $ids as $id) {
            self::queue($info['blog'], $info['kind'] === 'item' ? 'ledger' : $info['kind'], (int) $id, $verb === 'DELETE' && $info['kind'] !== 'item' ? 'delete' : 'update');
        }
        return $q;
    }

    private static function resolve_insert()
    {
        global $wpdb;
        $info = self::$pending_insert;
        self::$pending_insert = null;
        if ($info && (int) $wpdb->insert_id > 0 && $wpdb->last_error === '') {
            self::queue($info['blog'], $info['kind'], (int) $wpdb->insert_id, 'insert');
        }
    }

    private static function queue($blog, $kind, $ref, $op)
    {
        if ($ref <= 0 || self::$bulk) {
            return;
        }
        $k = (int) $blog . '|' . $kind;
        if (!isset(self::$queue[$k][$ref])) {
            if (++self::$count > self::MAX_REFS) {
                self::$bulk = true;
                return;
            }
        }
        // delete thắng mọi thao tác khác của cùng phiếu trong request.
        if (($op === 'delete') || !isset(self::$queue[$k][$ref])) {
            self::$queue[$k][$ref] = $op;
        }
    }

    /** Phiếu chuyển kho global vừa ghi (tạo / sửa / duyệt / bỏ duyệt / từ chối / xoá). */
    public static function on_global_transfer($transfer_id)
    {
        self::queue(0, 'transfer', (int) $transfer_id, 'update');
    }

    /** API cho plugin tự bắn: do_action('tgs_acc/event', 'ledger' | 'cash_transfer' | 'transfer', $ref [, $blog_id [, $op]]). */
    public static function add($kind, $ref, $blog_id = 0, $op = 'manual')
    {
        $kind = in_array($kind, ['cash_transfer', 'transfer'], true) ? $kind : 'ledger';
        $blog = $kind !== 'ledger' ? 0 : ((int) $blog_id ?: get_current_blog_id());
        self::queue($blog, $kind, (int) $ref, (string) $op);
    }

    /* ------------------------------------------------------------------ tệp JSONL */

    public static function base_dir()
    {
        return WP_CONTENT_DIR . '/uploads/' . self::DIR;
    }

    /** Tệp của một ngày: …/YYYY/MM/YYYY-MM-DD.jsonl (suffix 'retry' → .retry.jsonl). */
    public static function file($date, $suffix = '')
    {
        return self::base_dir() . '/' . substr($date, 0, 4) . '/' . substr($date, 5, 2) . '/' . $date . ($suffix ? ".{$suffix}" : '') . '.jsonl';
    }

    private static function append($date, array $rows, $suffix = '')
    {
        if (!$rows) {
            return;
        }
        $f = self::file($date, $suffix);
        $dir = dirname($f);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            $base = self::base_dir();
            if (!file_exists($base . '/.htaccess')) {
                @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
                @file_put_contents($base . '/index.php', "<?php // Silence is golden.\n");
            }
        }
        $lines = '';
        foreach ($rows as $r) {
            $lines .= wp_json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        @file_put_contents($f, $lines, FILE_APPEND | LOCK_EX);
    }

    /** Đọc tệp JSONL (bỏ dòng hỏng). $tail_bytes > 0: chỉ đọc phần cuối tệp. */
    public static function read($file, $tail_bytes = 0)
    {
        if (!is_file($file)) {
            return [];
        }
        $size = filesize($file);
        $h = @fopen($file, 'rb');
        if (!$h) {
            return [];
        }
        if ($tail_bytes > 0 && $size > $tail_bytes) {
            fseek($h, $size - $tail_bytes);
            fgets($h); // bỏ dòng dở
        }
        $out = [];
        while (($line = fgets($h)) !== false) {
            $r = json_decode($line, true);
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        fclose($h);
        return $out;
    }

    private static function now()
    {
        return wp_date('c');
    }

    private static function today()
    {
        return wp_date('Y-m-d');
    }

    /** Nguồn của request (để tra cứu sự kiện): ajax:<action> | rest:<route> | cron | cli | web:<đường dẫn>. */
    private static function origin()
    {
        if (wp_doing_ajax()) {
            return 'ajax:' . sanitize_key((string) ($_REQUEST['action'] ?? ''));
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest:' . substr((string) ($GLOBALS['wp']->query_vars['rest_route'] ?? ''), 0, 80);
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        return 'web:' . substr((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), 0, 80);
    }

    /* ------------------------------------------------------------------ gửi */

    /** Cuối request: ghi sự kiện, rồi gửi các phiếu bị đụng. */
    public static function flush()
    {
        if (self::$pending_insert) {
            self::resolve_insert();
        }
        if (!self::$queue && !self::$bulk) {
            return;
        }
        self::$busy = true;
        $date = self::today();
        $t = self::now();
        $src = self::origin();
        $user = get_current_user_id();
        $rows = [];
        foreach (self::$queue as $k => $refs) {
            [$blog, $kind] = explode('|', $k);
            foreach ($refs as $ref => $op) {
                $id = substr(md5(uniqid('', true) . $k . $ref), 0, 12);
                $rows[] = ['t' => $t, 'id' => $id, 'blog' => (int) $blog, 'kind' => $kind, 'ref' => (int) $ref, 'op' => $op, 'src' => $src, 'user' => $user];
            }
        }
        if (self::$bulk) {
            $rows[] = ['t' => $t, 'bulk' => true, 'src' => $src, 'user' => $user, 'note' => 'Quá ' . self::MAX_REFS . ' phiếu trong một lượt: không tự gửi, dùng nút Kéo chứng từ ở trạm ACC14'];
        }
        self::append($date, $rows);
        self::$queue = [];
        if (self::$bulk || !self::enabled()) {
            return;
        }

        // Trả trang cho người dùng trước, gửi sau (PHP-FPM / LiteSpeed); máy chủ khác thì gửi trước khi kết thúc request.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(120);

        foreach (self::group($rows) as $g) {
            // [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-13 — shop đang tạm dừng kết nối: không gửi, xếp vào hàng chờ (không tính lần thử), gửi bù khi bỏ tạm dừng.
            if (self::paused($g['blog'])) {
                foreach (self::targets() as $target) {
                    self::append($date, [['t' => self::now(), 'rid' => $g['ids'][0] . ($target ? '-' . $target : ''), 'target' => $target, 'blog' => $g['blog'], 'ledger' => $g['ledger'], 'ct' => $g['ct'], 'gt' => $g['gt'], 'n' => 0, 'next' => 0, 'paused' => 1, 'msg' => self::PAUSED_MSG]], 'retry');
                }
                continue;
            }
            // Dựng MỘT lần, gửi từng nơi nhận (sổ nội bộ, sổ thuế).
            $built = self::build($g['blog'], $g['ledger'], $g['ct'], $g['gt']);
            foreach (self::targets() as $target) {
                $r = self::deliver($g['blog'], $built, $target);
                self::append($date, [['t' => self::now(), 'res' => $r['ok'] ? 'ok' : 'error', 'target' => $target ?: 'noi-bo', 'blog' => $g['blog'], 'ids' => $g['ids']] + $r]);
                if (!$r['ok']) {
                    self::append($date, [['t' => self::now(), 'rid' => $g['ids'][0] . ($target ? '-' . $target : ''), 'target' => $target, 'blog' => $g['blog'], 'ledger' => $g['ledger'], 'ct' => $g['ct'], 'gt' => $g['gt'], 'n' => 1, 'next' => time() + 60, 'msg' => $r['msg']]], 'retry');
                }
            }
        }
    }

    /**
     * Gom sự kiện theo shop: phiếu (ledger) của shop + phiếu chuyển tiền (theo shop lập phiếu) + phiếu chuyển kho global
     * (vào CẢ shop đi lẫn shop đến — mỗi bên một đầu chứng từ).
     */
    private static function group(array $rows)
    {
        global $wpdb;
        $groups = [];
        $cts = [];
        $gts = [];
        $blank = function ($b) { return ['blog' => $b, 'ledger' => [], 'ct' => [], 'gt' => [], 'ids' => []]; };
        foreach ($rows as $r) {
            if (!isset($r['kind'])) {
                continue;
            }
            if ($r['kind'] === 'cash_transfer') {
                $cts[(int) $r['ref']] = $r['id'];
                continue;
            }
            if ($r['kind'] === 'transfer') {
                $gts[(int) $r['ref']] = $r['id'];
                continue;
            }
            $b = (int) $r['blog'];
            $groups[$b] = $groups[$b] ?? $blank($b);
            $groups[$b]['ledger'][] = (int) $r['ref'];
            $groups[$b]['ids'][] = $r['id'];
        }
        if ($cts) {
            $T = $wpdb->base_prefix . 'global_cash_transfer';
            foreach ($wpdb->get_results("SELECT ct_id, issuer_blog_id FROM {$T} WHERE ct_id IN (" . implode(',', array_keys($cts)) . ")", ARRAY_A) ?: [] as $c) {
                $b = (int) $c['issuer_blog_id'];
                $groups[$b] = $groups[$b] ?? $blank($b);
                $groups[$b]['ct'][] = (int) $c['ct_id'];
                $groups[$b]['ids'][] = $cts[(int) $c['ct_id']];
            }
        }
        if ($gts && class_exists('TGS_Global_Transfer') && TGS_Global_Transfer::ready()) {
            $T = TGS_Global_Transfer::table();
            foreach ($wpdb->get_results("SELECT transfer_id, from_blog_id, to_blog_id FROM {$T} WHERE transfer_id IN (" . implode(',', array_keys($gts)) . ")", ARRAY_A) ?: [] as $t) {
                foreach (array_unique([(int) $t['from_blog_id'], (int) $t['to_blog_id']]) as $b) {
                    $groups[$b] = $groups[$b] ?? $blank($b);
                    $groups[$b]['gt'][] = (int) $t['transfer_id'];
                    $groups[$b]['ids'][] = $gts[(int) $t['transfer_id']];
                }
            }
        }
        return array_values(array_filter($groups, function ($g) { return $g['blog'] > 0; }));
    }

    /** Dựng các chứng từ của một nhóm phiếu (một lần cho mọi nơi nhận). Lỗi dựng → ['error' => thông báo]. */
    private static function build($blog_id, array $ledger_ids, array $ct_ids, array $gt_ids = [])
    {
        try {
            // [2026-11-21 08:11 PM Johnny Chu - Chu Hoàng Anh] S002-4 — nguồn phiếu theo blog (tgs-bc-tk hoặc sổ POS).
            if (!TGS_Acc_Source::ready($blog_id)) {
                return ['error' => 'Chưa có nguồn phiếu bán / hoàn: cài tgs-bc-tk, hoặc bật nguồn sổ POS (option tgs_acc_sale_source = pos)'];
            }
            TGS_Acc_Push_Log::maybe_install();
            return TGS_Acc_Pusher::build_refs($blog_id, $ledger_ids, $ct_ids, $gt_ids);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Gửi chứng từ đã dựng tới MỘT nơi nhận: '' = sổ nội bộ (mọi chứng từ), 'tax' = sổ thuế (chỉ phiếu bán / hoàn có số hoá
     * đơn). Nhật ký gửi theo nơi nhận; phiếu không còn (hoặc với sổ thuế: không còn đủ điều kiện) → báo huỷ.
     * @return array{ok: bool, sent: int, unchanged: int, cancelled: int, msg: string}
     */
    private static function deliver($blog_id, array $built, $target)
    {
        $none = ['ok' => true, 'sent' => 0, 'unchanged' => 0, 'cancelled' => 0, 'msg' => ''];
        if (isset($built['error'])) {
            return ['ok' => false, 'msg' => $built['error']] + $none;
        }
        try {
            TGS_Acc_Push_Log::use_consumer($target === 'tax' ? 'tax' : '');
            if ($target === 'tax') {
                if (!TGS_Acc_Config::tax_ready()) {
                    return $none;
                }
                $client = new TGS_Acc_Api_Client(TGS_Acc_Config::tax_url(), TGS_Acc_Config::tax_key());
                $docs = array_values(array_filter($built['docs'], ['TGS_Acc_Gate', 'for_tax_book']));
                $gone = TGS_Acc_Pusher::gone_keys($blog_id, $built['want'], $built['want_ct'], $docs, $built['want_gt'] ?? []);
            } else {
                // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-12 — client THEO BLOG có phiếu: cron gửi lại chạy ở site chính không còn dùng khoá của site chính (sai tenant).
                $client = TGS_Acc_Api_Client::for_blog((int) $blog_id);
                $docs = $built['docs'];
                $gone = $built['gone'];
            }
            $s = $docs ? TGS_Acc_Pusher::send($blog_id, $docs, ['client' => $client]) : ['posted' => 0, 'pending' => 0, 'unchanged' => 0, 'skipped' => 0, 'error' => 0, 'errors' => []];
            $c = TGS_Acc_Pusher::cancel($gone, $client);
            $errors = array_merge($s['errors'], $c['errors']);
            return [
                'ok'        => (int) $s['error'] === 0 && !$c['errors'],
                'sent'      => (int) $s['posted'] + (int) $s['pending'],
                'unchanged' => (int) $s['unchanged'] + (int) $s['skipped'],
                'cancelled' => (int) $c['cancelled'],
                'msg'       => implode('; ', array_slice(array_merge($errors, $built['failed']), 0, 5)),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()] + $none;
        } finally {
            TGS_Acc_Push_Log::use_consumer('');
        }
    }

    /** Dựng + gửi một nhóm phiếu tới một nơi nhận (cron gửi lại). */
    public static function process($blog_id, array $ledger_ids, array $ct_ids = [], $target = '', array $gt_ids = [])
    {
        return self::deliver($blog_id, self::build($blog_id, $ledger_ids, $ct_ids, $gt_ids), $target);
    }

    /* ------------------------------------------------------------------ gửi lại (cron) */

    /**
     * Trạng thái mới nhất của từng nhóm gửi lỗi trong các tệp retry gần nhất: rid => dòng cuối. Cửa sổ 7 ngày; có shop đang
     * tạm dừng thì kéo dài tới ngày bắt đầu tạm dừng sớm nhất (tối đa 90 ngày) để hàng chờ của nó không rơi khỏi cửa sổ.
     */
    public static function retry_state()
    {
        $state = [];
        for ($i = self::window_days() - 1; $i >= 0; $i--) {
            $date = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
            foreach (self::read(self::file($date, 'retry')) as $r) {
                if (!empty($r['rid'])) {
                    $state[$r['rid']] = $r + ($state[$r['rid']] ?? []);
                    if (!empty($r['done']) || !empty($r['gave_up'])) {
                        $state[$r['rid']]['closed'] = true;
                    }
                }
            }
        }
        return $state;
    }

    /** Cron mỗi phút: gửi lại các nhóm lỗi đến hạn. $force: bỏ qua giãn cách (nút "Gửi lại ngay"). */
    public static function retry($force = false)
    {
        if (!self::enabled()) {
            return ['retried' => 0, 'ok' => 0];
        }
        // Khoá chống chạy chồng.
        $lock = (int) get_site_option('tgs_acc_events_retry_lock', 0);
        if ($lock > time() - 120) {
            return ['retried' => 0, 'ok' => 0, 'locked' => true];
        }
        update_site_option('tgs_acc_events_retry_lock', time());
        self::$busy = true;
        $t0 = time();
        $done = 0;
        $ok = 0;
        $date = self::today();
        foreach (self::retry_state() as $rid => $r) {
            if (!empty($r['closed']) || (!$force && (int) ($r['next'] ?? 0) > time())) {
                continue;
            }
            // [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-13 — shop tạm dừng: để nguyên trong hàng chờ, không gửi, không tăng số lần thử.
            if (self::paused((int) ($r['blog'] ?? 0))) {
                continue;
            }
            if (time() - $t0 > 50 || $done >= 50) {
                break;
            }
            $res = self::process((int) $r['blog'], (array) ($r['ledger'] ?? []), (array) ($r['ct'] ?? []), (string) ($r['target'] ?? ''), (array) ($r['gt'] ?? []));
            $done++;
            $n = (int) ($r['n'] ?? 1) + 1;
            if ($res['ok']) {
                $ok++;
                self::append($date, [['t' => self::now(), 'rid' => $rid, 'done' => 1, 'n' => $n, 'sent' => $res['sent'], 'cancelled' => $res['cancelled']]], 'retry');
            } elseif ($n > self::RETRY_MAX) {
                self::append($date, [['t' => self::now(), 'rid' => $rid, 'gave_up' => 1, 'n' => $n, 'msg' => $res['msg']]], 'retry');
            } else {
                self::append($date, [['t' => self::now(), 'rid' => $rid, 'target' => (string) ($r['target'] ?? ''), 'blog' => (int) $r['blog'], 'ledger' => $r['ledger'] ?? [], 'ct' => $r['ct'] ?? [], 'gt' => $r['gt'] ?? [], 'n' => $n, 'next' => time() + 60 * min(60, 2 ** min(6, $n - 1)), 'msg' => $res['msg']]], 'retry');
            }
        }
        delete_site_option('tgs_acc_events_retry_lock');
        self::$busy = false;
        return ['retried' => $done, 'ok' => $ok];
    }

    /* ------------------------------------------------------------------ xem (trang Cổng kế toán) */

    /** Sự kiện của một ngày (mới nhất trước) + nhóm đang chờ gửi lại. */
    public static function overview($date, $limit = 300)
    {
        $rows = array_reverse(self::read(self::file($date), 3 * 1024 * 1024));
        $events = [];
        $results = [];
        foreach ($rows as $r) {
            if (isset($r['res'])) {
                $results[] = $r;
            } elseif (count($events) < $limit) {
                $events[] = $r;
            }
        }
        // Gắn kết quả gửi vào từng sự kiện, theo từng nơi nhận (sổ nội bộ, sổ thuế).
        $by = [];
        foreach ($results as $r) {
            $tg = (string) ($r['target'] ?? 'noi-bo');
            foreach ((array) ($r['ids'] ?? []) as $id) {
                $by[$id][$tg] = $by[$id][$tg] ?? $r;
            }
        }
        foreach ($events as &$e) {
            if (isset($e['id'], $by[$e['id']])) {
                $e['result'] = $by[$e['id']]['noi-bo'] ?? null;
                $e['tax'] = $by[$e['id']]['tax'] ?? null;
            }
        }
        unset($e);
        $waiting = array_values(array_filter(self::retry_state(), function ($r) { return empty($r['closed']); }));
        return ['date' => $date, 'events' => $events, 'waiting' => $waiting, 'enabled' => self::enabled(), 'tax' => TGS_Acc_Config::tax_ready(), 'file' => str_replace(ABSPATH, '', self::file($date))];
    }

    // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-10 — cron: mọi blog còn chứng từ 'pending' → hỏi kế toán bằng client của chính blog đó.
    /** Kéo trạng thái chứng từ chờ định khoản cho mọi blog (cron 5 phút ở site chính). @return array blog => tổng kết */
    public static function pull_status()
    {
        $lock = (int) get_site_option('tgs_acc_status_pull_lock', 0);
        if ($lock && time() - $lock < 240) {
            return [];
        }
        update_site_option('tgs_acc_status_pull_lock', time());
        $out = [];
        try {
            TGS_Acc_Push_Log::use_consumer('');
            foreach (TGS_Acc_Push_Log::blogs_with_pending() as $blog_id) {
                if (self::paused($blog_id)) {
                    continue; // S002-13 — tạm dừng: không gọi kế toán
                }
                $client = TGS_Acc_Api_Client::for_blog($blog_id);
                if (!$client->ready()) {
                    continue;
                }
                $out[$blog_id] = TGS_Acc_Pusher::refresh_status($blog_id, $client);
            }
        } finally {
            delete_site_option('tgs_acc_status_pull_lock');
        }
        return $out;
    }


    // [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-13 — tạm dừng / bỏ tạm dừng kết nối theo shop; hàng chờ của shop.
    /** Shop đang tạm dừng kết nối (vẫn ghi sự kiện, không gửi, không thử lại, không hỏi trạng thái). */
    public static function paused($blog_id)
    {
        $blog_id = (int) $blog_id;
        return $blog_id > 0 && function_exists('get_blog_option') && (string) get_blog_option($blog_id, self::PAUSE_OPTION, '') !== '';
    }

    /** Tạm dừng: ghi thời điểm (giữ khoá, giữ cấu hình). Đang tạm dừng thì giữ mốc cũ (OKT-SC-2: tự tạm dừng lặp lại không xoá dấu vết). */
    public static function pause($blog_id, $reason = '')
    {
        $blog_id = (int) $blog_id;
        $since = self::paused_since($blog_id);
        if ($since === '') {
            $since = current_time('mysql');
            update_blog_option($blog_id, self::PAUSE_OPTION, $since);
        }
        if ($reason !== '') {
            update_blog_option($blog_id, 'tgs_acc_paused_reason', (string) $reason);
        }
        $map = (array) get_site_option(self::PAUSED_MAP, []);
        $map[$blog_id] = $since;
        update_site_option(self::PAUSED_MAP, $map);
        return true;
    }

    /** Lý do tạm dừng ('' = admin tạm dừng tay; 'selfcheck: …' = tự kiểm tự tạm dừng). */
    public static function paused_reason($blog_id)
    {
        return (string) get_blog_option((int) $blog_id, 'tgs_acc_paused_reason', '');
    }

    /** Số ngày tệp retry phải đọc: 7, hoặc tới ngày bắt đầu tạm dừng sớm nhất của các shop đang tạm dừng (tối đa 90). */
    public static function window_days()
    {
        $days = self::RETRY_DAYS;
        foreach ((array) get_site_option(self::PAUSED_MAP, []) as $since) {
            $ts = strtotime((string) $since);
            if ($ts) {
                $days = max($days, (int) ceil((time() - $ts) / DAY_IN_SECONDS) + 1);
            }
        }
        return min($days, self::PAUSED_KEEP_DAYS);
    }

    /**
     * Bỏ tạm dừng: các nhóm đang chờ của shop đến hạn ngay — cron gửi lại (mỗi phút) gửi bù theo thứ tự thời gian, không trùng
     * (docKey + phiên bản). Muốn gửi ngay: retry(true).
     */
    public static function resume($blog_id)
    {
        $blog_id = (int) $blog_id;
        // [2026-11-22 02:00 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — chép các nhóm còn mở của shop sang tệp hôm nay (đến hạn ngay):
        // bỏ tạm dừng thì cửa sổ đọc về 7 ngày, nhóm cũ hơn vẫn còn trong cửa sổ, gửi bù theo thứ tự thời gian gốc (`t` giữ nguyên).
        $carry = [];
        foreach (self::retry_state() as $r) {
            if (empty($r['closed']) && (int) ($r['blog'] ?? 0) === $blog_id) {
                unset($r['closed'], $r['paused']);
                $r['next'] = 0;
                $carry[] = $r;
            }
        }
        if ($carry) {
            usort($carry, function ($a, $b) { return strcmp((string) ($a['t'] ?? ''), (string) ($b['t'] ?? '')); });
            self::append(wp_date('Y-m-d'), $carry, 'retry');
        }
        $map = (array) get_site_option(self::PAUSED_MAP, []);
        unset($map[$blog_id]);
        update_site_option(self::PAUSED_MAP, $map);
        delete_blog_option($blog_id, 'tgs_acc_paused_reason');
        return delete_blog_option($blog_id, self::PAUSE_OPTION);
    }

    /** Thời điểm bắt đầu tạm dừng ('' = không tạm dừng). */
    public static function paused_since($blog_id)
    {
        return function_exists('get_blog_option') ? (string) get_blog_option((int) $blog_id, self::PAUSE_OPTION, '') : '';
    }

    /**
     * Hàng chờ gửi của một shop (nhóm chưa đóng trong các tệp retry 7 ngày gần nhất).
     * @return array{groups: int, paused: bool, oldest: string}  paused = shop đang tạm dừng (cả hàng chờ đang giữ lại)
     */
    public static function queue_of($blog_id)
    {
        $out = ['groups' => 0, 'paused' => self::paused($blog_id), 'oldest' => ''];
        foreach (self::retry_state() as $r) {
            if (!empty($r['closed']) || (int) ($r['blog'] ?? 0) !== (int) $blog_id) {
                continue;
            }
            $out['groups']++;
            $t = (string) ($r['t'] ?? '');
            if ($t !== '' && ($out['oldest'] === '' || $t < $out['oldest'])) {
                $out['oldest'] = $t;
            }
        }
        return $out;
    }

}
