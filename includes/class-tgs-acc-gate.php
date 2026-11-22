<?php
/**
 * CỔNG CHỜ — "đầu chuẩn" để phần mềm kế toán (ERP, trạm Định khoản tự động ACC14) KÉO chứng từ sang.
 * Đặc tả bên ERP: tgs-ketoan/docs/cong-ket-noi.md.
 *
 *   GET  /wp-json/tgs-acc/v1/pending?from=YYYY-MM-DD&to=YYYY-MM-DD[&cursor=…][&blogs=95,96]
 *        Authorization: Bearer <khoá cổng chờ>
 *        → { documents: [...], cancelled: [{docKey, reason}], nextCursor: string|null }
 *        Mỗi trang là một shop (cursor = vị trí shop tiếp theo). Chỉ trả chứng từ ĐANG CHỜ ĐẨY: chưa có ở kế toán,
 *        hoặc nội dung đã đổi (phiên bản mới). Phiên bản quyết định giống luồng đẩy (nhật ký gửi, mã băm nội dung).
 *   POST /wp-json/tgs-acc/v1/pending   { received: [{docKey, version, status}], rejected: [{docKey, message}] }
 *        ERP báo nhận sau mỗi trang → nhật ký gửi chuyển 'gate' → 'posted' (hoặc 'error'), lần kéo sau không trả lại.
 *        Máy chủ chặn POST (403) thì ERP báo nhận bằng GET ?ack=<cùng JSON>, từng nhóm 20 phiếu.
 * Nhật ký gửi (bảng tgs_acc_push_log, dùng chung với luồng đẩy): chứng từ trả ra cổng được ghi trạng thái 'gate' kèm
 * phiên bản + mã băm nội dung; không dùng transient (máy không có Redis thì transient không giữ giữa các request).
 *
 * Hiện có (chỉ phiếu ĐÃ DUYỆT): phiếu bán (kể cả bill Z), phiếu hoàn, phiếu thu / chi trả khách (mỗi phiếu một chứng từ
 * theo ngày của chính phiếu), phiếu nhập mua (loại 1 không cha), trả NCC (16), chuyển kho mỗi đầu một chứng từ (12–15,
 * phiếu cha), điều chỉnh tồn (21) — dựng bằng TGS_Acc_Pusher::build. Phiếu sửa trong khoảng dù thuộc ngày trước cũng
 * được dựng lại. Phiếu đã gửi mà nay đã xoá / bỏ duyệt: trả trong "cancelled" → bên kế toán vào Chờ huỷ (không tự huỷ).
 * Tham số shops = mã chi nhánh bên kế toán: chỉ quét các shop đó. Danh mục đầy đủ: TGS_Acc_Catalog (tab Danh mục API).
 *
 * Khoá cổng chờ: hằng TGS_ACC_GATE_KEY trong wp-config (ưu tiên), nếu không thì tự sinh và lưu ở site option,
 * xem ở Cổng kế toán → Cài đặt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Gate
{
    const NS = 'tgs-acc/v1';
    const STATUS_GATE = 'gate';

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function url()
    {
        return rest_url(self::NS . '/pending');
    }

    public static function key()
    {
        if (defined('TGS_ACC_GATE_KEY') && TGS_ACC_GATE_KEY !== '') {
            return (string) TGS_ACC_GATE_KEY;
        }
        $k = (string) TGS_Acc_Config::get('gate_key');
        if ($k === '') {
            $k = 'gate_' . wp_generate_password(32, false, false);
            $s = get_site_option(TGS_Acc_Config::OPTION, []);
            $s = is_array($s) ? $s : [];
            $s['gate_key'] = $k;
            update_site_option(TGS_Acc_Config::OPTION, $s);
        }
        return $k;
    }

    public static function routes()
    {
        register_rest_route(self::NS, '/pending', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'pending'], 'permission_callback' => [__CLASS__, 'authorized']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'acknowledge'], 'permission_callback' => [__CLASS__, 'authorized']],
        ]);
        register_rest_route(self::NS, '/sites', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'sites'], 'permission_callback' => [__CLASS__, 'authorized']],
        ]);
    }

    /**
     * GET /wp-json/tgs-acc/v1/sites — danh sách website của hệ thống bán hàng để phần mềm kế toán hiện cầu nối ở danh mục
     * Chi nhánh: { sites: [{ blogId, name, url, siteCode, branchCode, isMain, active }] }.
     *   siteCode   = wp_blogs.tgs_site_code (mã kho đại diện của shop, đồng bộ phân kho ghi)
     *   branchCode = chi nhánh website đang nối ở Cấu hình phân cấp multisite (htsoft_srid → bảng chi nhánh, TEN); null = chưa nối
     */
    public static function sites(WP_REST_Request $req)
    {
        global $wpdb;
        $map = [];
        $raw = get_site_option(defined('TGS_HIERARCHY_OPTION_KEY') ? TGS_HIERARCHY_OPTION_KEY : 'tgs_multisite_hierarchy_data', '');
        $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        foreach ((array) ($data['sites'] ?? []) as $bid => $s) {
            $srid = trim((string) ($s['htsoft_srid'] ?? ''));
            if ($srid !== '') {
                $map[(int) $bid] = $srid;
            }
        }
        $names = [];
        $T = $wpdb->base_prefix . 'htsoft_chinhanh';
        if ($map && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $T)) === $T) {
            foreach ((array) $wpdb->get_results("SELECT SRID, TEN FROM `$T`", ARRAY_A) as $r) {
                $names[(string) $r['SRID']] = (string) $r['TEN'];
            }
        }
        $B = $wpdb->base_prefix . 'blogs';
        $has_code = (bool) $wpdb->get_col("SHOW COLUMNS FROM `$B` LIKE 'tgs_site_code'");
        $rows = (array) $wpdb->get_results('SELECT blog_id, domain, path, deleted, archived' . ($has_code ? ', tgs_site_code' : '') . " FROM `$B` ORDER BY blog_id", ARRAY_A);
        $main = (int) get_main_site_id();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['blog_id'];
            $srid = $map[$id] ?? '';
            $out[] = [
                'blogId' => $id,
                'name' => (string) TGS_Acc_Site::opt($id, 'blogname'),
                'url' => (is_ssl() ? 'https://' : 'http://') . $r['domain'] . $r['path'],
                'siteCode' => $has_code ? (string) ($r['tgs_site_code'] ?? '') : '',
                'branchCode' => $srid !== '' ? ($names[$srid] ?? null) : null,
                'isMain' => $id === $main,
                'active' => (int) $r['deleted'] === 0 && (int) $r['archived'] === 0,
            ];
        }
        return rest_ensure_response(['sites' => $out]);
    }

    /** Khoá gửi kèm "Authorization: Bearer …" (hoặc X-Api-Key). */
    public static function authorized(WP_REST_Request $req)
    {
        $h = (string) $req->get_header('authorization');
        if ($h === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $h = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $given = stripos($h, 'Bearer ') === 0 ? trim(substr($h, 7)) : (string) $req->get_header('x-api-key');
        if ($given !== '' && hash_equals(self::key(), $given)) {
            return true;
        }
        return new WP_Error('tgs_acc_gate_key', 'Sai khoá cổng chờ', ['status' => 401]);
    }

    /**
     * Danh sách shop (blog id) được kéo: theo tham số shops (mã chi nhánh bên kế toán — kế toán chỉ kéo các chi nhánh đang
     * chọn, chỉ quét đúng các shop đó), hoặc blogs (id), không có thì mọi site trừ site tổng.
     * Mã khớp mã site; mã kế toán có đuôi chữ của danh mục cũ (08016Plus) khớp mã site 08016; hoặc khớp tên site.
     */
    // [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-13 — shop đang tạm dừng kết nối không trả ra cổng chờ.
    private static function blogs(WP_REST_Request $req)
    {
        return array_values(array_filter(self::blogs_all($req), function ($id) { return !TGS_Acc_Events::paused((int) $id); }));
    }

    private static function blogs_all(WP_REST_Request $req)
    {
        $shops = array_values(array_filter(array_map('trim', explode(',', (string) $req->get_param('shops')))));
        if ($shops && class_exists('TGS_BCTK_Sites')) {
            $want = array_map('strtolower', $shops);
            $ids = [];
            foreach (TGS_BCTK_Sites::list_sites() as $s) {
                $code = strtolower(trim((string) $s['code']));
                $name = strtolower(trim((string) $s['name']));
                // Chi nhánh website đang nối (Cấu hình phân cấp) — khớp chính xác trước, đúng như mã chứng từ gửi đi.
                $branch = strtolower(TGS_Acc_Source::branch_of_blog((int) $s['blog_id']));
                foreach ($want as $w) {
                    if (($branch !== '' && $w === $branch) || ($code !== '' && ($w === $code || (strpos($w, $code) === 0 && preg_match('/^[a-z_]+$/', substr($w, strlen($code)))))) || ($name !== '' && $w === $name)) {
                        $ids[] = (int) $s['blog_id'];
                        break;
                    }
                }
            }
            sort($ids);
            return array_values(array_unique($ids));
        }
        $only = array_filter(array_map('intval', explode(',', (string) $req->get_param('blogs'))));
        if ($only) {
            return array_values($only);
        }
        $ids = TGS_Acc_Site::blogs();
        $main = (int) get_main_site_id();
        sort($ids);
        return array_values(array_filter($ids, function ($id) use ($main) { return $id !== $main; }));
    }

    /**
     * Bên nhận: consumer=tax là sổ thuế — nhật ký gửi riêng, chỉ nhận phiếu bán / hoàn có số hoá đơn điện tử
     * (không phải bill nội bộ, hoá đơn chưa huỷ). Không có tham số = sổ nội bộ, nhận mọi chứng từ.
     */
    private static function consumer(WP_REST_Request $req)
    {
        $c = (string) $req->get_param('consumer') === 'tax' ? 'tax' : '';
        TGS_Acc_Push_Log::use_consumer($c);
        return $c;
    }

    /** Chứng từ của sổ thuế: phiếu bán / hoàn có số hoá đơn, không phải phiếu nội bộ, hoá đơn chưa huỷ. */
    public static function for_tax_book(array $doc)
    {
        return in_array($doc['docType'] ?? '', ['sale', 'sale_return'], true) && empty($doc['internalOnly'])
            && (string) ($doc['invoice']['no'] ?? '') !== '' && ($doc['invoice']['status'] ?? '') !== 'cancelled';
    }

    public static function pending(WP_REST_Request $req)
    {
        $tax = self::consumer($req) === 'tax';
        // Báo nhận bằng GET (?ack=<json>) khi máy chủ chặn POST.
        if ((string) $req->get_param('ack') !== '') {
            $ack = json_decode(wp_unslash((string) $req->get_param('ack')), true);
            return self::apply_ack(is_array($ack) ? $ack : []);
        }
        $from = (string) $req->get_param('from');
        $to = (string) $req->get_param('to');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            return new WP_Error('tgs_acc_gate_range', 'Cần from, to dạng YYYY-MM-DD (from ≤ to)', ['status' => 400]);
        }
        if (!TGS_Acc_Source::ready()) {
            return new WP_Error('tgs_acc_gate_source', 'Chưa có nguồn phiếu bán / hoàn: cài tgs-bc-tk, hoặc bật nguồn sổ POS (option tgs_acc_sale_source = pos)', ['status' => 503]);
        }
        TGS_Acc_Push_Log::maybe_install();
        $blogs = self::blogs($req);
        $i = max(0, (int) $req->get_param('cursor'));
        if ($i >= count($blogs)) {
            return ['documents' => [], 'nextCursor' => null];
        }
        $blog_id = $blogs[$i];
        $built = TGS_Acc_Pusher::build($blog_id, $from, $to);
        if ($tax) {
            $built['docs'] = array_values(array_filter($built['docs'], [__CLASS__, 'for_tax_book']));
        }
        $log = TGS_Acc_Push_Log::find_many(array_column($built['docs'], 'docKey'));
        $out = [];
        foreach ($built['docs'] as $doc) {
            $h = TGS_Acc_Pusher::hash($doc);
            $prev = $log[$doc['docKey']] ?? null;
            // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9 — 'pending' / 'remote_error' cũng là kế toán đã có đúng nội dung này.
            if ($prev && $prev['content_hash'] === $h && TGS_Acc_Push_Log::is_delivered($prev['status'])) {
                continue; // kế toán đã có đúng nội dung này
            }
            $doc['version'] = max(1, $prev ? ((int) $prev['version'] + ($prev['content_hash'] === $h ? 0 : 1)) : 1);
            $doc = TGS_Acc_Pusher::decorate($doc); // sourceRef: chung cho kéo / đẩy / sự kiện
            // Đã đưa ra cổng chờ: ghi nhật ký (phiên bản + mã băm), chờ ERP báo nhận.
            if (!$prev || $prev['status'] !== self::STATUS_GATE || $prev['content_hash'] !== $h) {
                TGS_Acc_Push_Log::save($blog_id, $doc, $doc['version'], $h, self::STATUS_GATE, 'Đã đưa ra cổng chờ, chờ ERP báo nhận');
            }
            $out[] = $doc;
        }
        // Phiếu đã gửi (ERP đã nhận / đang ở cổng) trong khoảng mà nay không còn ở shop (đã xoá) → báo huỷ.
        // Phiếu bị xoá trong khoảng (theo lúc xoá) cũng báo huỷ, dù ngày phiếu ngoài khoảng đang kéo.
        $cancelled = [];
        foreach (TGS_Acc_Push_Log::cancel_keys($blog_id, $from, $to, array_column($built['docs'], 'docKey'), TGS_Acc_Source::deleted_keys($blog_id, $from, $to)) as $key) {
            $cancelled[] = ['docKey' => $key, 'reason' => 'Phiếu đã xoá / bỏ duyệt ở hệ thống bán hàng'];
        }
        return ['documents' => $out, 'cancelled' => $cancelled, 'nextCursor' => $i + 1 < count($blogs) ? (string) ($i + 1) : null, 'blog' => $blog_id, 'failedBuild' => $built['failed']];
    }

    public static function acknowledge(WP_REST_Request $req)
    {
        self::consumer($req);
        return self::apply_ack((array) $req->get_json_params());
    }

    private static function apply_ack(array $body)
    {
        $done = 0;
        global $wpdb;
        $t = TGS_Acc_Push_Log::table();
        foreach ((array) ($body['received'] ?? []) as $r) {
            $n = $wpdb->query($wpdb->prepare(
                // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9 — báo nhận = 'pending' (chờ định khoản), trừ khi kế toán báo đã ghi sổ; kéo trạng thái chuyển tiếp.
                "UPDATE {$t} SET status = %s, message = %s, pushed_at = %s WHERE doc_key = %s AND version = %d AND status IN (%s, 'error')",
                ($r['status'] ?? '') === 'posted' ? 'posted' : 'pending',
                ($r['status'] ?? '') === 'unchanged' ? 'ERP đã có phiên bản này' : (($r['status'] ?? '') === 'posted' ? 'ERP đã nhận và ghi sổ' : 'ERP đã nhận (chờ định khoản)'), current_time('mysql'),
                (string) ($r['docKey'] ?? ''), (int) ($r['version'] ?? 0), self::STATUS_GATE
            ));
            $done += (int) $n;
        }
        foreach ((array) ($body['cancelled'] ?? []) as $r) {
            $key = (string) ($r['docKey'] ?? '');
            if ($key !== '') {
                TGS_Acc_Push_Log::mark($key, 'cancelled', 'ERP đã nhận báo xoá — chờ kế toán định khoản huỷ');
            }
        }
        foreach ((array) ($body['rejected'] ?? []) as $r) {
            $key = (string) ($r['docKey'] ?? '');
            if ($key !== '') {
                TGS_Acc_Push_Log::mark($key, 'error', 'ERP từ chối: ' . (string) ($r['message'] ?? ''));
            }
        }
        return ['ok' => true, 'acknowledged' => $done];
    }
}
