<?php
/**
 * DANH MỤC HÀNG LẤY TỪ PHẦN MỀM KẾ TOÁN (22/10/2026).
 *
 * Mặt hàng, đơn vị tính + tỉ lệ quy đổi, bảng giá, giá theo ĐVT, chi nhánh áp bảng giá, cờ hàng chính / sữa < 24 tháng /
 * không được bán / xuất điều chỉnh (BIT1) / KCT, ảnh, mô tả, barcode — khai ở MỘT chỗ: phần mềm kế toán (DM10, DM14).
 * Các bảng global bên này là BẢN SAO để POS / báo cáo đọc như cũ (không phải sửa nơi đọc):
 *
 *   ERP                          →  bảng bên này
 *   products                     →  wp_global_product_name (tên, barcode, ĐVT nhỏ nhất, ảnh, mô tả, nhóm, thuế, cờ)
 *   products.is_under_24m        →  + wp_global_milk_under24m (POS đọc bảng này để chặn khuyến mại)
 *   product_units                →  wp_global_htsoft_unit_base (Bảng gốc: ĐVT, tỉ lệ, ĐVT bán chính, khối lượng)
 *   price_lists                  →  wp_global_htsoft_price_list (theo mã bảng giá; is_company → is_company_default)
 *   price_list_branches          →  wp_global_htsoft_price_list_blog (mã chi nhánh = wp_blogs.tgs_site_code;
 *                                    chi nhánh chưa gán = bảng giá công ty)
 *   product_prices               →  wp_global_htsoft_stock_convert (price_list_id × SKU × ĐVT; tỉ lệ lấy theo Bảng gốc)
 *   item_classes (nhóm hàng)     →  wp_global_product_cat: CHỈ THÊM nhóm mặt hàng cần mà bên này chưa có (id = mã nhóm,
 *                                    hoặc id_sync_api = mã nhóm khi mã không phải số). Không đổi tên nhóm đang có — tên bên
 *                                    này là tên ghép dài, bên kế toán là tên ngắn từng cấp.
 *   catalog_tombstones           →  xoá MỀM (is_deleted = 1) đúng dòng tương ứng; nhóm hàng không xoá.
 *
 * Luồng:
 *   sync()               kéo thay đổi theo con trỏ (GET /api/v1/catalog/feed/meta|products|prices), chỉ ghi dòng khác,
 *                        cuối cùng báo tgs_cache làm mới gói giá / sản phẩm. dry = chỉ đếm khác biệt, không ghi.
 *   build_import()       dựng dữ liệu nạp ban đầu từ các bảng bên này (POST /api/v1/catalog/import) — chạy một lần lúc
 *                        chuyển sang khai ở kế toán (tools/danh-muc-hang.php nap).
 *   enabled()            danh mục lấy từ kế toán: TỰ BẬT khi mạng đã nối kế toán (C7 — bỏ công tắc catalog_from_erp); hằng
 *                        TGS_ACC_CATALOG_FROM_ERP trong wp-config thắng (true ép bật, false ép tắt).
 *                        Bật thì: cron 5 phút kéo về + khoá mọi màn sửa mặt hàng / danh mục / giá & ĐVT / sữa < 24 tháng
 *                        bên này: chặn MỌI AJAX ghi bảng danh mục hàng (block_writes, theo danh sách action các plugin khai
 *                        với tgs_cache) + bật các chốt sẵn có (filter tgs_catalog_readonly, tgs_price_edit_locked).
 *   routes()             POST /wp-json/tgs-acc/v1/catalog-changed: kế toán báo vừa đổi → kéo về ngay (khoá cổng chờ).
 *
 * SW3 (24/11/2026) — khoá THEO NGUỒN CỦA TỪNG BLOG (tsm `tgs_catalog_source`, ADR-OKT-1 D001-19), không khoá cả mạng nữa:
 *   source_default()     bật kéo danh mục + blog đã nối kế toán + blog đang đọc bảng mạng (cờ tgs_catalog_store = global — đúng bảng
 *                        được kéo về) → nguồn mặc định `ketoan`. Admin đã chọn rõ nguồn (option của blog) thì giữ lựa chọn đó.
 *                        Blog cờ `local` (bảng riêng, vd blog mới) không nhận dữ liệu kéo về → giữ `local`, không bị khoá.
 *   locked_here()        khoá màn sửa / chặn AJAX ghi chỉ khi blog đang xem có nguồn `ketoan`. tsm cũ chưa có tgs_catalog_source
 *                        → khoá như trước (cả mạng).
 *   connected()          trả lời tsm `tgs_okt_connected` theo kết nối của blog (TGS_Acc_Config::ready_for) — chỉ để hiện trạng thái.
 *
 * Thêm trường mới: thêm cột ở ERP (products) → map ở product_row() (kéo về) và import_products() (nạp ban đầu).
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Catalog_Sync
{
    const CURSOR = 'tgs_acc_catalog_cursor';   // site option: ['meta' => ISO, 'products' => cursor, 'prices' => cursor]
    // Tăng số này khi luồng mặt hàng thêm trường mới: lượt kéo kế tiếp đọc lại toàn bộ một lần.
    // v2 (09/11/2026): item_id, mã cha, ô Biến thể — docs/ma-hang-so-va-bien-the.md (tgs_shop_management).
    // v3 (09/11/2026): ô Gửi lên thuế (sendToTax).
    const CURSOR_VERSION = 'v3';
    const CURSOR_VERSION_OPT = 'tgs_acc_catalog_cursor_version';
    const LAST   = 'tgs_acc_catalog_sync_at';  // site option: lần kéo gần nhất + thống kê
    const LOCK   = 'tgs_acc_catalog_sync_lock';
    const CRON   = 'tgs_acc_catalog_sync';
    /** Có blog nào đủ URL + khoá kế toán (enabled() khi mạng không có khoá chung). */
    const ANY_READY = 'tgs_acc_catalog_any_ready';
    const MSG_LOCKED = 'Danh mục hàng (mặt hàng, ĐVT & tỉ lệ, bảng giá, giá bán, sữa < 24 tháng) khai ở phần mềm kế toán: Danh mục → Mặt hàng (DM10) / Bảng giá bán (DM14). Bên này chỉ hiển thị, tự cập nhật vài phút một lần.';

    /**
     * Ghi thuế suất (global_product_tax) theo mã VAT ra bên kế toán. TẮT: 22/10/2026 đối chiếu lệch 449 mã (vd quà tặng Q…
     * bên này 0%, bên kế toán 10%) — thuế quyết định hoá đơn POS, bật khi hai bên đã thống nhất.
     */
    const SYNC_TAX = false;

    const DIRTY = 'tgs_acc_catalog_sync_dirty'; // site option: có báo đổi trong lúc đang kéo → kéo thêm lượt nữa

    /** Bảng danh mục hàng — mọi AJAX ghi vào đây bị chặn khi danh mục lấy từ kế toán. */
    const CATALOG_TABLES = [
        'global_product_name', 'global_product_cat', 'global_htsoft_unit_base', 'global_htsoft_stock_convert',
        'global_htsoft_price_list', 'global_htsoft_price_list_blog', 'global_milk_under24m',
    ];
    /** Thuế suất còn khai bên này (SYNC_TAX tắt) → không chặn màn thuế. */
    const TAX_ACTIONS = ['tgs_shop_tax_save_one', 'tgs_shop_tax_import_batch'];

    /** Action AJAX ghi bảng sữa < 24 tháng (plugin tgs_shop_management chưa khai với tgs_cache). */
    const MILK_WRITE_ACTIONS = [
        'tgs_milk_under24m_delete', 'tgs_milk_under24m_restore', 'tgs_milk_under24m_bulk_delete',
        'tgs_milk_under24m_import_single', 'tgs_milk_under24m_batch_import',
    ];

    /** @var bool */
    private $dry = false;
    /** @var array thống kê: bảng → ['insert' => n, 'update' => n, 'delete' => n, 'fields' => [cột => n]] */
    private $stat = [];
    /** @var array<string,bool> SKU đã chạm (để tính lại tỉ lệ / ĐVT bán chính ở bảng giá) */
    private $touched = [];
    /** @var array<string,bool> bảng đã ghi (báo tgs_cache) */
    private $changed_tables = [];
    /** @var string[] */
    private $notes = [];
    /** @var array mẫu khác biệt (đối chiếu): bảng → cột → [[mã, cũ, mới], …] tối đa 5 */
    private $samples = [];
    /** @var array|null mã nhóm → ['id' => int, 'path' => string] */
    private $cats = null;
    /** @var array mã nhóm → nhóm hàng bên kế toán (feed/meta) */
    private $erp_groups = [];
    /** @var array|null mã bảng giá → id */
    private $lists = null;

    // ------------------------------------------------------------------------------------------------ khởi động

    public static function init()
    {
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_filter('cron_schedules', static function ($s) {
            $s['tgs_acc_5min'] = ['interval' => 300, 'display' => 'Mỗi 5 phút (danh mục hàng từ kế toán)'];
            return $s;
        });
        add_action('wp_ajax_tgs_acc_catalog_sync', [__CLASS__, 'ajax_sync']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        // [2026-11-24 02:40 AM Johnny Chu - Chu Hoàng Anh] SW3 — khoá / nguồn theo TỪNG BLOG (tsm tgs_catalog_source, D001-19; chủ chốt 24/11: blog đã nối → mặc định ketoan).
        add_filter('tgs_okt_connected', [__CLASS__, 'connected'], 10, 2);
        if (!self::enabled()) {
            add_action('init', static function () {
                wp_clear_scheduled_hook(self::CRON);
            });
            return;
        }
        add_action('init', static function () {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 60, 'tgs_acc_5min', self::CRON);
            }
        });
        // Khoá sửa bên này — các plugin đã có sẵn chốt, chỉ cần bật. // [2026-11-24 02:40 AM Johnny Chu - Chu Hoàng Anh] SW3 — khoá / nguồn theo TỪNG BLOG (tsm tgs_catalog_source, D001-19; chủ chốt 24/11: blog đã nối → mặc định ketoan).
        add_filter('tgs_catalog_source_default', [__CLASS__, 'source_default'], 10, 2);
        add_filter('tgs_catalog_readonly', [__CLASS__, 'locked_here']);
        add_filter('tgs_price_edit_locked', [__CLASS__, 'locked_here']);
        $msg = static function () {
            return self::MSG_LOCKED;
        };
        add_filter('tgs_catalog_readonly_message', $msg);
        add_filter('tgs_price_edit_locked_message', $msg);
        // Chặn chung: mọi AJAX ghi vào bảng danh mục hàng (các plugin khai qua filter tgs_cache_ajax_writes: thêm, sửa,
        // xoá, sửa nhanh giá / trạng thái, nhập Excel…) + sữa < 24 tháng. Chạy ở admin_init — trước mọi wp_ajax_*.
        add_action('admin_init', [__CLASS__, 'block_writes'], 1);
    }

    /** Blog có nối kế toán (để tsm hiện 🟢 / 🔴). */
    public static function connected($connected, $blog_id = 0)
    {
        $blog_id = (int) $blog_id ?: (int) get_current_blog_id();
        // [2026-11-25 08:20 AM Johnny Chu - Chu Hoàng Anh] SW2-1 — trả lời bằng bốn điều kiện thật (có khoá · kế toán trả lời · biết tenant · không trùng tenant), không chỉ "có khoá".
        return (bool) $connected || ($blog_id > 0 && TGS_Acc_Connection::connected($blog_id));
    }

    /** Nguồn mặc định của blog khi admin chưa chọn: blog đã nối + đọc bảng mạng được kéo về → ketoan. */
    public static function source_default($default, $blog_id = 0)
    {
        $blog_id = (int) $blog_id ?: (int) get_current_blog_id();
        if (!self::enabled() || $blog_id <= 0 || !TGS_Acc_Config::ready_for($blog_id)) {
            return $default;
        }
        if (class_exists('TGS_Catalog_Store') && TGS_Catalog_Store::store($blog_id) !== 'global') {
            return $default; // bảng riêng của blog — dữ liệu kéo về (bảng mạng) không tới đây
        }
        return 'ketoan';
    }

    /** Khoá sửa danh mục ở blog đang xem? tsm cũ (chưa có nguồn theo blog) → khoá như trước. */
    public static function locked_here($locked = false)
    {
        if (!self::enabled()) {
            return (bool) $locked;
        }
        if (!function_exists('tgs_catalog_source')) {
            return true;
        }
        return (bool) $locked || tgs_catalog_source((int) get_current_blog_id()) === 'ketoan';
    }

    /** Request AJAX đang ghi danh mục hàng → trả lỗi, chỉ đường sang kế toán. */
    public static function block_writes()
    {
        if (!wp_doing_ajax() || !self::locked_here()) {
            return;
        }
        $action = sanitize_key($_REQUEST['action'] ?? '');
        if ($action === '' || (!self::SYNC_TAX && in_array($action, self::TAX_ACTIONS, true))) {
            return;
        }
        $tables = (array) (apply_filters('tgs_cache_ajax_writes', [])[$action] ?? []);
        if (in_array($action, self::MILK_WRITE_ACTIONS, true) || array_intersect($tables, self::CATALOG_TABLES)) {
            wp_send_json_error(['code' => 'tgs_catalog_from_erp', 'message' => self::MSG_LOCKED], 403);
        }
    }

    /**
     * POST /wp-json/tgs-acc/v1/catalog-changed — phần mềm kế toán báo danh mục hàng vừa đổi (khoá cổng chờ). Trả lời ngay,
     * kéo về sau khi đã trả lời (không bắt bên kia chờ). Đang có lượt kéo khác thì đánh dấu để lượt đó kéo thêm.
     */
    public static function routes()
    {
        register_rest_route('tgs-acc/v1', '/catalog-changed', [
            'methods'             => ['POST', 'GET'],
            'permission_callback' => ['TGS_Acc_Gate', 'authorized'],
            'callback'            => static function () {
                if (!self::enabled()) {
                    return ['ok' => true, 'skipped' => 'Danh mục chưa lấy từ kế toán (công tắc tắt)'];
                }
                add_action('shutdown', static function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    ignore_user_abort(true);
                    (new self())->sync();
                }, 100);
                return ['ok' => true, 'queued' => true];
            },
        ]);
    }

    // [2026-11-27 06:00 PM Võ Hoàng] C7 (docs/CAU-HINH-KET-NOI.md) — bỏ công tắc catalog_from_erp: đã nối kế toán thì danh mục hàng
    // lấy từ kế toán ("mọi cài đặt ở open-ketoan"). Khoá sửa vẫn theo TỪNG BLOG (locked_here / source_default: blog đã nối →
    // nguồn ketoan), blog chưa nối vẫn sửa tay như cũ. Hằng wp-config vẫn thắng để ép bật / tắt (đóng góp)
    /** Danh mục hàng lấy từ kế toán? Hằng TGS_ACC_CATALOG_FROM_ERP thắng; không có hằng → mạng (hoặc một blog) đã nối kế toán. */
    public static function enabled()
    {
        if (defined('TGS_ACC_CATALOG_FROM_ERP')) {
            return (bool) TGS_ACC_CATALOG_FROM_ERP;
        }
        if (TGS_Acc_Config::ready()) {
            return true;
        }
        // Mô hình khoá riêng từng blog: mạng không có khoá chung → xem có blog nào đủ URL + khoá (giữ 10 phút, không gọi mạng).
        static $any = null;
        if ($any === null) {
            $cached = get_site_transient(self::ANY_READY);
            if ($cached === false) {
                $cached = '0';
                foreach (TGS_Acc_Site::blogs() as $blog_id) {
                    if (TGS_Acc_Config::ready_for((int) $blog_id)) {
                        $cached = '1';
                        break;
                    }
                }
                set_site_transient(self::ANY_READY, $cached, 600);
            }
            $any = $cached === '1';
        }
        return $any;
    }

    public static function cron()
    {
        if (self::enabled() && TGS_Acc_Config::ready()) {
            (new self())->sync();
        }
    }

    public static function ajax_sync()
    {
        check_ajax_referer(TGS_Acc_Admin::NONCE, 'nonce');
        if (!TGS_Acc_Admin::can_configure()) {
            wp_send_json_error(['message' => 'Chỉ quản trị cấp cao kéo được danh mục hàng.'], 403);
        }
        $r = (new self())->sync(['dry' => !empty($_POST['dry']), 'full' => !empty($_POST['full'])]);
        is_wp_error($r) ? wp_send_json_error(['message' => $r->get_error_message()]) : wp_send_json_success($r);
    }

    /** Lần kéo gần nhất (trang Cổng kế toán hiện). */
    public static function last()
    {
        return (array) get_site_option(self::LAST, []);
    }

    // ------------------------------------------------------------------------------------------------ kéo về

    /**
     * Kéo thay đổi từ phần mềm kế toán.
     * @param array $o dry (chỉ đếm khác biệt), full (bỏ con trỏ, đối chiếu lại toàn bộ), api (TGS_Acc_Api_Client khác cấu hình)
     * @return array|WP_Error thống kê
     */
    public function sync(array $o = [])
    {
        $this->dry = !empty($o['dry']);
        $api = $o['api'] ?? new TGS_Acc_Api_Client();
        if (!$api->ready()) {
            return new WP_Error('tgs_acc_not_ready', 'Chưa cấu hình kết nối phần mềm kế toán.');
        }
        if (!$this->dry) {
            $lock = (int) get_site_option(self::LOCK, 0);
            if ($lock && time() - $lock < 600) {
                update_site_option(self::DIRTY, 1); // lượt đang chạy sẽ kéo thêm khi xong
                return new WP_Error('tgs_acc_busy', 'Đang có lượt kéo danh mục hàng khác chạy.');
            }
            delete_site_option(self::DIRTY);
            update_site_option(self::LOCK, time());
        }
        $t0 = microtime(true);
        $full = !empty($o['full']) || get_site_option(self::CURSOR_VERSION_OPT) !== self::CURSOR_VERSION;
        $cur = $full ? [] : (array) get_site_option(self::CURSOR, []);
        if ($full && !$this->dry) {
            update_site_option(self::CURSOR_VERSION_OPT, self::CURSOR_VERSION);
        }
        $save = function ($k, $v) use (&$cur) {
            $cur[$k] = $v;
            if (!$this->dry) {
                update_site_option(self::CURSOR, $cur);
            }
        };
        try {
            // 1. Bảng giá, gán chi nhánh, nhóm hàng, mục đã xoá.
            $meta = $api->get('/api/v1/catalog/feed/meta', array_filter(['since' => $cur['meta'] ?? '']));
            if (is_wp_error($meta)) {
                return $meta;
            }
            $m = (array) $meta['data'];
            $this->apply_price_lists((array) ($m['priceLists'] ?? []));
            $this->apply_branches((array) ($m['branches'] ?? []), isset($m['knownBranches']) ? (array) $m['knownBranches'] : null);
            $this->apply_groups((array) ($m['groups'] ?? []));
            $this->apply_tombstones((array) ($m['tombstones'] ?? []));
            $meta_at = (string) ($m['serverTime'] ?? '');

            // 2. Mặt hàng (kèm đơn vị tính), từng trang.
            foreach (['products' => 1000, 'prices' => 5000] as $feed => $limit) {
                $after = (string) ($cur[$feed] ?? '');
                for ($page = 0; $page < 500; $page++) {
                    $res = $api->get('/api/v1/catalog/feed/' . $feed, array_filter(['after' => $after, 'limit' => $limit]));
                    if (is_wp_error($res)) {
                        return $res;
                    }
                    $d = (array) $res['data'];
                    $items = (array) ($d['items'] ?? []);
                    $feed === 'products' ? $this->apply_products($items) : $this->apply_prices($items);
                    $after = (string) ($d['next'] ?? $after);
                    $save($feed, $after);
                    if (empty($d['more'])) {
                        break;
                    }
                }
            }
            // 3. Bảng giá theo Bảng gốc: tỉ lệ + ĐVT bán chính của các SKU vừa đổi.
            $this->refresh_price_rows(array_keys($this->touched));
            if ($meta_at !== '') {
                $save('meta', $meta_at);
            }
        } finally {
            if (!$this->dry) {
                delete_site_option(self::LOCK);
            }
        }
        $this->flush_caches();
        $out = [
            'dry'     => $this->dry,
            'ms'      => (int) ((microtime(true) - $t0) * 1000),
            'skus'    => count($this->touched),
            'tables'  => $this->stat,
            'samples' => $this->samples,
            'notes'   => array_slice($this->notes, 0, 50),
            'noteCount' => count($this->notes),
            'at'      => current_time('mysql'),
        ];
        if (!$this->dry) {
            update_site_option(self::LAST, $out);
            // Có báo đổi trong lúc vừa kéo → kéo thêm một lượt (theo con trỏ, nhanh).
            if (get_site_option(self::DIRTY) && empty($o['_again'])) {
                (new self())->sync(['api' => $api, '_again' => true]);
            }
        }
        return $out;
    }

    private function apply_price_lists(array $lists)
    {
        global $wpdb;
        $T = self::t('global_htsoft_price_list');
        $have = [];
        foreach ((array) $wpdb->get_results("SELECT * FROM {$T}", ARRAY_A) as $r) {
            $have[(string) $r['price_list_code']] = $r;
        }
        foreach ($lists as $l) {
            $code = (string) ($l['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $company = !empty($l['isCompany']) ? 1 : 0;
            $this->upsert($T, $have[$code] ?? null, 'global_htsoft_price_list_id', [
                'price_list_code'    => $code,
                'price_list_name'    => (string) ($l['name'] ?? $code),
                'price_list_note'    => (string) ($l['note'] ?? ''),
                'price_list_status'  => !isset($l['isActive']) || $l['isActive'] ? 1 : 0,
                'is_company_default' => $company,
                'is_default'         => $company,
                'is_deleted'         => 0,
            ]);
        }
        $this->lists = null;
    }

    /**
     * Gán bảng giá theo chi nhánh cho các website có mã chi nhánh:
     *   - chi nhánh có gán ở kế toán → bảng giá đó;
     *   - chi nhánh kế toán CÓ mà chưa gán → bảng giá công ty;
     *   - chi nhánh kế toán CHƯA CÓ (chưa khai ở danh mục Chi nhánh) → GIỮ NGUYÊN bảng giá đang dùng, ghi chú để bổ sung.
     * $known null (kế toán bản cũ không báo danh sách chi nhánh) → chỉ áp các chi nhánh có gán.
     */
    private function apply_branches(array $assign, $known = null)
    {
        global $wpdb;
        $lists = $this->list_ids();
        $company = $this->company_list_id();
        if (!$company && !$assign) {
            return;
        }
        $by_branch = [];
        foreach ($assign as $a) {
            $by_branch[(string) ($a['branchCode'] ?? '')] = (string) ($a['priceListCode'] ?? '');
        }
        $T = self::t('global_htsoft_price_list_blog');
        $have = [];
        foreach ((array) $wpdb->get_results("SELECT * FROM {$T}", ARRAY_A) as $r) {
            $have[(int) $r['blog_id']] = $r;
        }
        $known = $known === null ? null : array_fill_keys(array_map('strval', $known), true);
        $unknown = [];
        foreach (self::blog_codes() as $blog_id => $code) {
            if (!isset($by_branch[$code]) && ($known === null || !isset($known[$code]))) {
                if ($known !== null) {
                    $unknown[] = $code;
                }
                continue;
            }
            $want = isset($by_branch[$code]) ? ($lists[$by_branch[$code]] ?? 0) : $company;
            if (!$want) {
                continue;
            }
            $this->upsert($T, $have[$blog_id] ?? null, 'global_htsoft_price_list_blog_id', [
                'blog_id' => $blog_id, 'global_htsoft_price_list_id' => $want, 'is_deleted' => 0,
            ]);
        }
        if ($unknown) {
            $unknown = array_values(array_unique($unknown));
            $this->notes[] = count($unknown) . ' chi nhánh chưa có ở danh mục Chi nhánh của kế toán — giữ nguyên bảng giá đang dùng: ' . implode(', ', array_slice($unknown, 0, 40)) . (count($unknown) > 40 ? '…' : '');
        }
    }

    /** Nhóm hàng bên kế toán — giữ để tạo nhóm bên này khi mặt hàng cần tới (ensure_cat). */
    private function apply_groups(array $groups)
    {
        foreach ($groups as $g) {
            $this->erp_groups[(string) $g['code']] = $g;
        }
    }

    /**
     * Danh mục bên này cho mã nhóm bên kế toán; chưa có thì tạo (kèm các nhóm cha còn thiếu). Chỉ THÊM, không đổi tên nhóm
     * đang có. id = mã nhóm khi mã là số và id còn trống, không thì id tự tăng + id_sync_api = mã nhóm.
     * @return array|null ['id', 'path']
     */
    private function ensure_cat($code, $depth = 0)
    {
        $cats = $this->cats();
        if (isset($cats[$code])) {
            return $cats[$code];
        }
        $g = $this->erp_groups[$code] ?? null;
        if (!$g || $depth > 10) {
            return null;
        }
        global $wpdb;
        $T = self::t('global_product_cat');
        $parent_code = (string) ($g['parentCode'] ?? '');
        if ($parent_code !== '') {
            $parent = $this->ensure_cat($parent_code, $depth + 1);
            if (!$parent) {
                return null;
            }
        } else {
            $root = (int) $wpdb->get_var("SELECT global_product_cat_id FROM {$T} WHERE global_product_cat_parent_id IS NULL AND global_product_cat_name = 'Nhóm hàng' AND is_deleted = 0 ORDER BY global_product_cat_id LIMIT 1");
            $parent = $root ? ['id' => $root, 'path' => 'Nhóm hàng'] : ['id' => null, 'path' => ''];
        }
        $name = (string) ($g['name'] ?? $code);
        $path = $parent['path'] !== '' ? $parent['path'] . '\\' . $name : $name;
        $row = [
            'global_product_cat_code' => $name, 'global_product_cat_name' => $name, 'global_product_cat_path' => $path,
            'global_product_cat_parent_id' => $parent['id'], 'global_product_cat_status' => 1, 'is_deleted' => 0,
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ];
        $numeric = ctype_digit($code) && !$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$T} WHERE global_product_cat_id = %d", (int) $code));
        $row += $numeric ? ['global_product_cat_id' => (int) $code] : ['id_sync_api' => $code];
        $this->count('global_product_cat', 'insert');
        $id = -1;
        if (!$this->dry) {
            $id = $wpdb->insert($T, $row) ? (int) $wpdb->insert_id : 0;
            $this->changed_tables['global_product_cat'] = true;
        }
        $this->notes[] = "Thêm nhóm hàng {$code} {$name}.";
        return $this->cats[$code] = ['id' => $id, 'path' => $path];
    }

    private function apply_tombstones(array $tomb)
    {
        global $wpdb;
        $now = current_time('mysql');
        $soft = ['is_deleted' => 1, 'deleted_at' => $now, 'updated_at' => $now];
        foreach ($tomb as $t) {
            $key = (string) ($t['key'] ?? '');
            switch ((string) ($t['entity'] ?? '')) {
                case 'product':
                    $this->soft_delete('global_product_name', ['global_product_sku' => $key], $soft);
                    $this->soft_delete('global_htsoft_unit_base', ['global_product_sku' => $key], $soft);
                    $this->soft_delete('global_htsoft_stock_convert', ['global_product_sku' => $key], $soft);
                    break;
                case 'unit':
                    [$sku, $unit] = array_pad(explode('|', $key, 2), 2, '');
                    $this->soft_delete('global_htsoft_unit_base', ['global_product_sku' => $sku, 'convert_unit' => $unit], $soft);
                    $this->touched[$sku] = true;
                    break;
                case 'price':
                    [$list, $sku, $unit] = array_pad(explode('|', $key, 3), 3, '');
                    $id = $this->list_ids()[$list] ?? 0;
                    if ($id) {
                        $this->soft_delete('global_htsoft_stock_convert', ['price_list_id' => $id, 'global_product_sku' => $sku, 'convert_unit' => $unit], $soft);
                        $this->touched[$sku] = true;
                    }
                    break;
                case 'price_list':
                    $this->soft_delete('global_htsoft_price_list', ['price_list_code' => $key], $soft);
                    $this->lists = null;
                    break;
                default: // nhóm hàng: không xoá danh mục bên này (mặt hàng cũ còn trỏ tới)
                    break;
            }
        }
    }

    /** Một trang mặt hàng của luồng thay đổi. */
    private function apply_products(array $items)
    {
        if (!$items) {
            return;
        }
        global $wpdb;
        $P = self::t('global_product_name');
        $skus = array_map(static function ($p) { return (string) $p['sku']; }, $items);
        $have = $this->rows_by_sku($P, $skus);
        $milk = $this->rows_by_sku(self::t('global_milk_under24m'), $skus);
        $base = [];
        foreach ($this->rows_in(self::t('global_htsoft_unit_base'), 'global_product_sku', $skus) as $r) {
            $base[$r['global_product_sku']][$r['convert_unit']] = $r;
        }
        foreach ($items as $p) {
            $sku = (string) $p['sku'];
            $cur = $have[$sku] ?? null;
            // Mã mới chỉ xuống bán hàng khi đã khai ĐVT — mã kế toán tự thêm từ chứng từ cũ (chưa khai) không hiện ở POS.
            if (!$cur && empty($p['units'])) {
                $this->count('global_product_name', 'skip_new');
                continue;
            }
            $row = $this->product_row($p, $cur);
            if (!$cur) {
                $row += ['global_product_sku' => $sku, 'global_blog_id' => get_main_site_id(), 'created_at' => current_time('mysql')];
            }
            $this->upsert($P, $cur, 'global_product_name_id', $row);
            $this->apply_units($sku, (array) ($p['units'] ?? []), $base[$sku] ?? []);
            $this->apply_milk($sku, (string) ($p['name'] ?? ''), !empty($p['isUnder24m']), $milk[$sku] ?? null);
            $this->touched[$sku] = true;
        }
    }

    /** Cột item_id / Biến thể đã có chưa (migration 2026_11_09_000100 của tgs_shop_management). */
    private static function has_item_columns()
    {
        static $has = null;
        if ($has === null) {
            global $wpdb;
            $has = !empty($wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM `' . self::t('global_product_name') . '` LIKE %s', 'global_product_variant')));
        }
        return $has;
    }

    /** Cột Gửi lên thuế (migration 2026_11_09_000200 của tgs_shop_management). */
    private static function has_send_tax_column()
    {
        static $has = null;
        if ($has === null) {
            global $wpdb;
            $has = !empty($wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM `' . self::t('global_product_name') . '` LIKE %s', 'global_product_send_tax')));
        }
        return $has;
    }

    /** Dòng wp_global_product_name từ mặt hàng bên kế toán. */
    private function product_row(array $p, $cur)
    {
        $units = (array) ($p['units'] ?? []);
        $base_unit = (string) ($p['unit'] ?? '');
        foreach ($units as $u) {
            if (abs((float) $u['ratio'] - 1) < 0.0005) {
                $base_unit = (string) $u['unit'];
                break;
            }
        }
        $type = (int) ($cur['global_product_htsoft_type'] ?? 0);
        $row = [
            'global_product_name'         => (string) ($p['name'] ?? ''),
            'global_product_barcode_main' => (string) ($p['barcode'] ?? ''),
            'global_product_unit'         => $base_unit,
            'global_product_thumbnail'    => self::image_url((string) ($p['imageUrl'] ?? ''), (string) ($cur['global_product_thumbnail'] ?? '')),
            'global_product_description'  => (string) ($p['description'] ?? ''),
            'global_product_is_main'      => !empty($p['isMain']) ? 1 : 0,
            'global_product_is_kct'       => !empty($p['isKct']) ? 1 : 0,
            'global_product_htsoft_bit1'  => !empty($p['exportAdjust']) ? 1 : 0,
            // 5 = chỉ nhập, không bán; các loại khác (1, 4, 6) giữ nguyên khi vẫn được bán.
            'global_product_htsoft_type'  => !empty($p['noSale']) ? 5 : ($type === 5 ? 0 : $type),
            'global_product_is_under_24m' => !empty($p['isUnder24m']) ? 1 : 0,
            'global_product_status'       => !isset($p['isActive']) || $p['isActive'] ? '1' : '0',
            'is_deleted'                  => 0,
        ];
        // Mã số cha – con: định danh bất biến + ô Biến thể do ERP cấp. Mọi nơi đọc qua TGS_Item_Variant, không đọc đuôi mã.
        // KHÔNG ghi global_product_parent_sku: cột đó là mã con theo HSD (tgs-hsd-checker), POS ẩn các mã đó. Họ mã ERP đi qua parent_item_id.
        if (self::has_item_columns()) {
            $row['global_product_item_id']        = !empty($p['itemId']) ? (string) $p['itemId'] : null;
            $row['global_product_parent_item_id'] = !empty($p['parentItemId']) ? (string) $p['parentItemId'] : null;
            $row['global_product_variant']        = !empty($p['variantCode']) ? (string) $p['variantCode'] : 'normal';
            if (self::has_send_tax_column()) {
                // Căn cứ duy nhất tách khỏi thuế. Thiếu trường (ERP cũ) thì gửi, trừ Nội bộ.
                $send = isset($p['sendToTax']) ? !empty($p['sendToTax']) : true;
                $row['global_product_send_tax'] = ($send && $row['global_product_variant'] !== 'internal') ? 1 : 0;
            }
        }
        if (self::SYNC_TAX && isset($p['taxRate']) && $p['taxRate'] !== null) {
            $row['global_product_tax'] = (float) $p['taxRate'];
        }
        $group = (string) ($p['groupCode'] ?? '');
        $cat = $group !== '' ? $this->ensure_cat($group) : null;
        if ($group === '') {
            // Không nhóm bên kế toán (vd mặt hàng gắn nút gốc "Nhóm hàng" bên này) → giữ nhóm cũ.
        } elseif ($cat) {
            $row['global_product_cat_id'] = $cat['id'];
            // Đường dẫn nhóm (bản phái sinh, báo cáo lọc theo nó): chỉ ghi khi mặt hàng đổi nhóm / chưa có đường dẫn.
            if (!$cur || (int) ($cur['global_product_cat_id'] ?? 0) !== (int) $cat['id'] || (string) ($cur['global_product_category_path'] ?? '') === '') {
                $row['global_product_category_path'] = $cat['path'];
            }
        } else {
            $this->notes[] = "{$p['sku']}: nhóm hàng {$group} chưa có bên này — giữ nhóm cũ.";
        }
        return $row;
    }

    /** Bảng gốc của một SKU: thay cả bộ (đơn vị không còn → xoá mềm). */
    private function apply_units($sku, array $units, array $cur)
    {
        if (!$units) {
            return;
        }
        $T = self::t('global_htsoft_unit_base');
        $seen = [];
        foreach ($units as $u) {
            $unit = (string) $u['unit'];
            $seen[$unit] = true;
            $row = [
                'convert_from_tgs'  => 1,
                'convert_to_htsoft' => (float) $u['ratio'],
                'is_default_unit'   => !empty($u['isDefault']) ? 1 : 0,
                'unit_weight_kg'    => isset($u['weightKg']) && $u['weightKg'] !== null ? (float) $u['weightKg'] : null,
                'convert_note'      => (string) ($u['note'] ?? ''),
                'is_deleted'        => 0,
            ];
            if (!isset($cur[$unit])) {
                $row += ['global_product_sku' => $sku, 'convert_unit' => $unit, 'created_at' => current_time('mysql')];
            }
            $this->upsert($T, $cur[$unit] ?? null, 'global_htsoft_unit_base_id', $row);
        }
        foreach ($cur as $unit => $r) {
            if (!isset($seen[$unit]) && !(int) $r['is_deleted']) {
                $this->upsert($T, $r, 'global_htsoft_unit_base_id', ['is_deleted' => 1, 'deleted_at' => current_time('mysql')]);
            }
        }
    }

    private function apply_milk($sku, $name, $under24m, $cur)
    {
        $T = self::t('global_milk_under24m');
        if ($under24m) {
            $row = ['is_deleted' => 0, 'deleted_at' => null];
            if (!$cur) {
                $row += ['global_product_sku' => $sku, 'global_product_name' => $name, 'created_at' => current_time('mysql')];
            }
            $this->upsert($T, $cur, 'id', $row);
        } elseif ($cur && !(int) $cur['is_deleted']) {
            $this->upsert($T, $cur, 'id', ['is_deleted' => 1, 'deleted_at' => current_time('mysql')]);
        }
    }

    /** Một trang dòng giá. */
    private function apply_prices(array $items)
    {
        if (!$items) {
            return;
        }
        $T = self::t('global_htsoft_stock_convert');
        $lists = $this->list_ids();
        $skus = array_values(array_unique(array_map(static function ($r) { return (string) $r['sku']; }, $items)));
        $have = [];
        foreach ($this->rows_in($T, 'global_product_sku', $skus) as $r) {
            $have[$r['price_list_id'] . '|' . $r['global_product_sku'] . '|' . $r['convert_unit']] = $r;
        }
        $ratio = [];
        foreach ($this->rows_in(self::t('global_htsoft_unit_base'), 'global_product_sku', $skus) as $r) {
            if (!(int) $r['is_deleted']) {
                $ratio[$r['global_product_sku'] . '|' . $r['convert_unit']] = (float) $r['convert_to_htsoft'];
            }
        }
        foreach ($items as $pr) {
            $list = $lists[(string) $pr['priceListCode']] ?? 0;
            $sku = (string) $pr['sku'];
            $unit = (string) $pr['unit'];
            if (!$list) {
                $this->notes[] = "Giá {$pr['priceListCode']}/{$sku}/{$unit}: bảng giá chưa có bên này.";
                continue;
            }
            $k = "{$list}|{$sku}|{$unit}";
            $row = [
                'unit_price'              => isset($pr['price']) && $pr['price'] !== null ? (float) $pr['price'] : null,
                'convert_from_tgs'        => 1,
                'convert_to_htsoft'       => $ratio["{$sku}|{$unit}"] ?? (float) ($have[$k]['convert_to_htsoft'] ?? 1),
                'default_unit_overridden' => !empty($pr['isDefaultUnit']) ? 1 : 0,
                'is_deleted'              => 0,
            ];
            if (!isset($have[$k])) {
                $row += ['price_list_id' => $list, 'global_product_sku' => $sku, 'convert_unit' => $unit, 'created_at' => current_time('mysql')];
            }
            $this->upsert($T, $have[$k] ?? null, 'global_htsoft_stock_convert_id', $row);
            $this->touched[$sku] = true;
        }
    }

    /**
     * Dòng bảng giá theo Bảng gốc cho các SKU vừa đổi: tỉ lệ = tỉ lệ Bảng gốc; ĐVT bán chính = ĐVT bảng giá đó tự chọn
     * (default_unit_overridden) nếu có, không thì ĐVT bán chính của mặt hàng.
     */
    private function refresh_price_rows(array $skus)
    {
        $T = self::t('global_htsoft_stock_convert');
        foreach (array_chunk($skus, 500) as $chunk) {
            $base = [];
            $default = [];
            foreach ($this->rows_in(self::t('global_htsoft_unit_base'), 'global_product_sku', $chunk) as $r) {
                if ((int) $r['is_deleted']) {
                    continue;
                }
                $base[$r['global_product_sku'] . '|' . $r['convert_unit']] = (float) $r['convert_to_htsoft'];
                if ((int) $r['is_default_unit']) {
                    $default[$r['global_product_sku']] = $r['convert_unit'];
                }
            }
            $groups = [];
            foreach ($this->rows_in($T, 'global_product_sku', $chunk) as $r) {
                if (!(int) $r['is_deleted']) {
                    $groups[$r['price_list_id'] . '|' . $r['global_product_sku']][] = $r;
                }
            }
            foreach ($groups as $rows) {
                $sku = $rows[0]['global_product_sku'];
                $own = array_filter($rows, static function ($r) { return (int) $r['default_unit_overridden'] === 1; });
                foreach ($rows as $r) {
                    $want = [
                        'is_default_unit' => $own ? (int) $r['default_unit_overridden'] : (int) (($default[$sku] ?? null) === $r['convert_unit']),
                    ];
                    if (isset($base[$sku . '|' . $r['convert_unit']])) {
                        $want['convert_to_htsoft'] = $base[$sku . '|' . $r['convert_unit']];
                    }
                    $this->upsert($T, $r, 'global_htsoft_stock_convert_id', $want);
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ nạp ban đầu

    /**
     * Dữ liệu nạp ban đầu sang kế toán (POST /api/v1/catalog/import), từ các bảng bên này.
     * @param array $erp_skus SKU kế toán đã có (mặt hàng chưa có thì gửi kèm tên + nhóm)
     * @return array ['priceLists', 'branches', 'products', 'prices']
     */
    public static function build_import(array $erp_skus, $blog_id = null)
    {
        // [2026-11-25 02:00 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — $blog_id: đọc bảng của BLOG qua tgs_catalog_table() (SW2-2, shop local); không có: bảng mạng như cũ (nap).
        $prev = self::$scope;
        self::$scope = $blog_id ? (int) $blog_id : null;
        try {
            return self::build_import_from($erp_skus);
        } finally {
            self::$scope = $prev;
        }
    }

    private static function build_import_from(array $erp_skus)
    {
        global $wpdb;
        $warn = [];
        $known = array_fill_keys(array_map('strval', $erp_skus), true);
        $lists = [];
        $list_code = [];
        foreach ((array) $wpdb->get_results('SELECT * FROM ' . self::t('global_htsoft_price_list') . ' WHERE COALESCE(is_deleted, 0) = 0 ORDER BY is_company_default DESC, global_htsoft_price_list_id', ARRAY_A) as $l) {
            $list_code[(int) $l['global_htsoft_price_list_id']] = (string) $l['price_list_code'];
            $lists[] = [
                'code' => (string) $l['price_list_code'], 'name' => (string) ($l['price_list_name'] ?: $l['price_list_code']),
                'isCompany' => (bool) $l['is_company_default'], 'isActive' => (bool) $l['price_list_status'], 'note' => (string) $l['price_list_note'],
            ];
        }
        $branches = [];
        $codes = self::blog_codes();
        foreach ((array) $wpdb->get_results('SELECT blog_id, global_htsoft_price_list_id FROM ' . self::t('global_htsoft_price_list_blog') . ' WHERE COALESCE(is_deleted, 0) = 0 ORDER BY blog_id', ARRAY_A) as $b) {
            if (self::$scope !== null && (int) $b['blog_id'] !== self::$scope) {
                continue; // SW2-2: chỉ chi nhánh của blog đang chuyển
            }
            $code = self::$scope !== null ? (string) TGS_Acc_Source::branch_of_blog(self::$scope) : ($codes[(int) $b['blog_id']] ?? ''); // chi nhánh khai theo blog (S002-5), như tenant-prepare
            $list = $list_code[(int) $b['global_htsoft_price_list_id']] ?? '';
            if ($code !== '' && $list !== '' && !isset($branches[$code])) {
                $branches[$code] = ['branchCode' => $code, 'priceListCode' => $list];
            }
        }

        // Nhóm hàng bên này → mã nhóm bên kế toán (id_sync_api nếu có, không thì id).
        $group_code = [];
        foreach ((array) $wpdb->get_results('SELECT global_product_cat_id, id_sync_api FROM ' . self::t('global_product_cat'), ARRAY_A) as $c) {
            $group_code[(int) $c['global_product_cat_id']] = (string) ($c['id_sync_api'] ?: $c['global_product_cat_id']);
        }
        $units = [];
        foreach ((array) $wpdb->get_results('SELECT global_product_sku, convert_unit, convert_to_htsoft, is_default_unit, unit_weight_kg, convert_note FROM ' . self::t('global_htsoft_unit_base') . " WHERE COALESCE(is_deleted, 0) = 0 AND convert_unit <> '' ORDER BY convert_to_htsoft, global_htsoft_unit_base_id", ARRAY_A) as $u) {
            $units[$u['global_product_sku']][] = [
                'unit' => (string) $u['convert_unit'], 'ratio' => (float) $u['convert_to_htsoft'], 'isDefault' => (bool) $u['is_default_unit'],
                'weightKg' => $u['unit_weight_kg'] === null ? null : (float) $u['unit_weight_kg'], 'note' => (string) $u['convert_note'],
            ];
        }
        if ($wpdb->last_error) {
            $warn[] = 'Lỗi đọc Bảng gốc (ĐVT): ' . $wpdb->last_error;
        }
        $milk = array_fill_keys((array) $wpdb->get_col('SELECT global_product_sku FROM ' . self::t('global_milk_under24m') . ' WHERE is_deleted = 0'), true);
        if ($wpdb->last_error) {
            $warn[] = 'Lỗi đọc bảng sữa < 24 tháng (bỏ qua): ' . $wpdb->last_error;
        }
        $products = [];
        $unit_of = [];
        // Chỉ đọc cột đang có (máy chủ có thể chưa chạy đủ migration): cột thiếu coi như mặc định, ghi vào $warn.
        $T = self::t('global_product_name');
        $have = array_fill_keys((array) $wpdb->get_col("SHOW COLUMNS FROM {$T}"), true);
        $want = ['global_product_name', 'global_product_unit', 'global_product_barcode_main', 'global_product_thumbnail', 'global_product_description',
            'global_product_cat_id', 'global_product_status', 'global_product_is_main', 'global_product_is_kct', 'global_product_htsoft_type',
            'global_product_htsoft_bit1', 'global_product_is_under_24m'];
        $missing = array_values(array_diff($want, array_keys($have)));
        if ($missing) {
            $warn[] = 'Bảng sản phẩm thiếu cột (coi như trống / 0): ' . implode(', ', $missing);
        }
        $cols = array_merge(['global_product_sku'], array_values(array_intersect($want, array_keys($have))));
        $where = isset($have['is_deleted']) ? ' WHERE COALESCE(is_deleted, 0) = 0' : '';
        $rows = $wpdb->get_results('SELECT ' . implode(', ', $cols) . " FROM {$T}{$where}", ARRAY_A);
        if ($wpdb->last_error) {
            $warn[] = 'Lỗi đọc bảng sản phẩm: ' . $wpdb->last_error;
        }
        foreach ((array) $rows as $p) {
            $p += array_fill_keys($missing, null);
            $sku = (string) $p['global_product_sku'];
            $u = $units[$sku] ?? [];
            $u = self::fix_units($u, (string) $p['global_product_unit']);
            $unit_of[$sku] = array_fill_keys(array_column($u, 'unit'), true);
            $row = [
                'sku'             => $sku,
                'barcode'         => trim((string) $p['global_product_barcode_main'], " /"),
                'imageUrl'        => (string) $p['global_product_thumbnail'],
                'description'     => (string) $p['global_product_description'],
                'isMain'          => (bool) $p['global_product_is_main'],
                'isUnder24m'      => isset($milk[$sku]) || (bool) $p['global_product_is_under_24m'],
                'noSale'          => (int) $p['global_product_htsoft_type'] === 5,
                'exportAdjust'    => (bool) $p['global_product_htsoft_bit1'],
                'isKct'           => (bool) $p['global_product_is_kct'],
            ];
            if ($u) {
                $row['units'] = $u;
            }
            if (!isset($known[$sku])) {
                $row['name'] = (string) $p['global_product_name'];
                $row['groupCode'] = $p['global_product_cat_id'] ? ($group_code[(int) $p['global_product_cat_id']] ?? null) : null;
                $row['isActive'] = (string) $p['global_product_status'] !== '0';
            }
            $products[] = $row;
        }
        $prices = [];
        foreach ((array) $wpdb->get_results('SELECT price_list_id, global_product_sku, convert_unit, unit_price, is_default_unit, default_unit_overridden FROM ' . self::t('global_htsoft_stock_convert') . ' WHERE COALESCE(is_deleted, 0) = 0 AND unit_price IS NOT NULL', ARRAY_A) as $r) {
            $list = $list_code[(int) $r['price_list_id']] ?? '';
            $sku = (string) $r['global_product_sku'];
            if ($list === '' || !isset($unit_of[$sku][$r['convert_unit']])) {
                continue;
            }
            $prices[] = [
                'priceListCode' => $list, 'sku' => $sku, 'unit' => (string) $r['convert_unit'], 'price' => (float) $r['unit_price'],
                'isDefaultUnit' => (int) $r['default_unit_overridden'] === 1 && (int) $r['is_default_unit'] === 1,
            ];
        }
        return ['priceLists' => $lists, 'branches' => array_values($branches), 'products' => $products, 'prices' => $prices, 'warnings' => $warn];
    }

    /**
     * Bảng gốc thường lệch nhẹ: nhiều dòng tỉ lệ 1, không dòng nào tỉ lệ 1, nhiều ĐVT bán chính. Sửa cho đúng luật kế
     * toán (đúng một tỉ lệ 1, tối đa một ĐVT bán chính) theo cách POS đang hiểu (ưu tiên dòng trùng ĐVT của mặt hàng).
     */
    private static function fix_units(array $u, $product_unit)
    {
        if (!$u) {
            return $product_unit !== '' ? [['unit' => $product_unit, 'ratio' => 1.0, 'isDefault' => true, 'weightKg' => null, 'note' => '']] : [];
        }
        $ones = array_keys(array_filter($u, static function ($x) { return abs($x['ratio'] - 1) < 0.0005; }));
        if (count($ones) > 1) {
            $keep = $ones[0];
            foreach ($ones as $i) {
                if ($u[$i]['unit'] === $product_unit) {
                    $keep = $i;
                }
            }
            $u = array_values(array_filter($u, static function ($x, $i) use ($ones, $keep) { return !in_array($i, $ones, true) || $i === $keep; }, ARRAY_FILTER_USE_BOTH));
        } elseif (!$ones) {
            return []; // không đoán đơn vị nhỏ nhất — bỏ, kế toán khai tay
        }
        $seen = false;
        foreach ($u as &$x) {
            $x['isDefault'] = $x['isDefault'] && !$seen;
            $seen = $seen || $x['isDefault'];
        }
        return $u;
    }

    // ------------------------------------------------------------------------------------------------ tiện ích

    /** @var int|null blog đang dựng dữ liệu nạp (SW2-2): bảng danh mục đọc theo blog */
    private static $scope = null;
    const SCOPE_KIND = ['global_product_name' => 'product', 'global_product_cat' => 'cat', 'global_htsoft_price_list' => 'price_list',
        'global_htsoft_price_list_blog' => 'price_list_blog', 'global_htsoft_stock_convert' => 'price', 'global_htsoft_unit_base' => 'unit'];

    private static function t($suffix)
    {
        global $wpdb;
        if (self::$scope !== null && isset(self::SCOPE_KIND[$suffix]) && function_exists('tgs_catalog_table')) {
            return tgs_catalog_table(self::SCOPE_KIND[$suffix], self::$scope);
        }
        return $wpdb->base_prefix . $suffix;
    }

    /** blog_id → mã chi nhánh (wp_blogs.tgs_site_code), chỉ website đang hoạt động. */
    private static function blog_codes()
    {
        global $wpdb;
        if (!$wpdb->get_var("SHOW COLUMNS FROM {$wpdb->blogs} LIKE 'tgs_site_code'")) {
            return [];
        }
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT blog_id, tgs_site_code FROM {$wpdb->blogs} WHERE deleted = 0 AND tgs_site_code IS NOT NULL AND tgs_site_code <> ''", ARRAY_A) as $r) {
            $out[(int) $r['blog_id']] = (string) $r['tgs_site_code'];
        }
        return $out;
    }

    /** mã nhóm (bên kế toán) → ['id', 'path'] danh mục bên này. */
    private function cats()
    {
        if ($this->cats === null) {
            global $wpdb;
            $this->cats = [];
            foreach ((array) $wpdb->get_results('SELECT global_product_cat_id, id_sync_api, global_product_cat_path FROM ' . self::t('global_product_cat') . ' WHERE is_deleted = 0', ARRAY_A) as $c) {
                $code = (string) ($c['id_sync_api'] ?: $c['global_product_cat_id']);
                $this->cats[$code] = ['id' => (int) $c['global_product_cat_id'], 'path' => (string) $c['global_product_cat_path']];
            }
        }
        return $this->cats;
    }

    private function list_ids()
    {
        if ($this->lists === null) {
            global $wpdb;
            $this->lists = [];
            foreach ((array) $wpdb->get_results('SELECT global_htsoft_price_list_id, price_list_code, is_company_default FROM ' . self::t('global_htsoft_price_list') . ' WHERE COALESCE(is_deleted, 0) = 0', ARRAY_A) as $l) {
                $this->lists[(string) $l['price_list_code']] = (int) $l['global_htsoft_price_list_id'];
            }
        }
        return $this->lists;
    }

    private function company_list_id()
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT global_htsoft_price_list_id FROM ' . self::t('global_htsoft_price_list') . ' WHERE is_company_default = 1 AND COALESCE(is_deleted, 0) = 0 ORDER BY global_htsoft_price_list_id LIMIT 1');
    }

    private function rows_by_sku($table, array $skus)
    {
        $out = [];
        foreach ($this->rows_in($table, 'global_product_sku', $skus) as $r) {
            $out[(string) $r['global_product_sku']] = $r;
        }
        return $out;
    }

    private function rows_in($table, $col, array $vals)
    {
        global $wpdb;
        $out = [];
        foreach (array_chunk(array_values(array_unique($vals)), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $out = array_merge($out, (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$col} IN ({$ph})", ...$chunk), ARRAY_A));
        }
        return $out;
    }

    /** @var array bảng → [cột => true] (máy chủ chưa chạy đủ migration thì thiếu cột: bỏ qua cột đó khi ghi) */
    private $columns = [];

    /** Thêm khi chưa có, sửa đúng các cột khác (so theo chuỗi; null = ''), không chạm khi giống hệt. */
    private function upsert($table, $cur, $pk, array $row)
    {
        global $wpdb;
        $name = substr($table, strlen($wpdb->base_prefix));
        if (!isset($this->columns[$table])) {
            $this->columns[$table] = array_fill_keys((array) $wpdb->get_col("SHOW COLUMNS FROM {$table}"), true);
            $gone = array_diff(array_keys($row), array_keys($this->columns[$table]), ['updated_at', 'created_at']);
            if ($gone) {
                $this->notes[] = "Bảng {$name} thiếu cột (bỏ qua): " . implode(', ', $gone);
            }
        }
        $row = array_intersect_key($row, $this->columns[$table]);
        if (!$cur) {
            $this->count($name, 'insert');
            if (!$this->dry) {
                $wpdb->insert($table, $row + ['updated_at' => current_time('mysql')]);
                $this->changed_tables[$name] = true;
            }
            return;
        }
        $diff = [];
        foreach ($row as $k => $v) {
            $a = array_key_exists($k, $cur) ? $cur[$k] : null;
            $same = is_float($v) || is_int($v) ? abs((float) $a - (float) $v) < 0.0005 && ($a === null) === ($v === null) : (string) $a === (string) $v;
            if (!$same) {
                $diff[$k] = $v;
            }
        }
        unset($diff['created_at']);
        if (!$diff) {
            return;
        }
        foreach ($diff as $k => $v) {
            if (count($this->samples[$name][$k] ?? []) < 5) {
                $this->samples[$name][$k][] = [(string) ($cur['global_product_sku'] ?? $cur[$pk]), $cur[$k] ?? null, $v];
            }
        }
        $this->count($name, 'update', array_keys($diff));
        if (!$this->dry) {
            $wpdb->update($table, $diff + ['updated_at' => current_time('mysql')], [$pk => $cur[$pk]]);
            $this->changed_tables[$name] = true;
        }
    }

    private function soft_delete($suffix, array $where, array $soft)
    {
        global $wpdb;
        $T = self::t($suffix);
        $cond = implode(' AND ', array_map(static function ($k) { return "{$k} = %s"; }, array_keys($where)));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$T} WHERE {$cond} AND COALESCE(is_deleted, 0) = 0", ...array_values($where)));
        if (!$n) {
            return;
        }
        $this->count($suffix, 'delete', [], $n);
        if (!$this->dry) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare("UPDATE {$T} SET is_deleted = 1, deleted_at = %s, updated_at = %s WHERE {$cond} AND COALESCE(is_deleted, 0) = 0", $soft['deleted_at'], $soft['updated_at'], ...array_values($where)));
            $this->changed_tables[$suffix] = true;
        }
    }

    private function count($table, $op, array $fields = [], $n = 1)
    {
        $s = $this->stat[$table] ?? ['insert' => 0, 'update' => 0, 'delete' => 0, 'fields' => []];
        $s[$op] = ($s[$op] ?? 0) + $n;
        foreach ($fields as $f) {
            $s['fields'][$f] = ($s['fields'][$f] ?? 0) + 1;
        }
        $this->stat[$table] = $s;
    }

    /** Báo tgs_cache các bảng vừa ghi (gói giá POS, sản phẩm, danh mục); thiếu tgs_cache thì không làm gì. */
    private function flush_caches()
    {
        foreach (array_keys($this->changed_tables) as $table) {
            do_action('tgs_cache_table_changed', $table);
        }
        if ($this->changed_tables && class_exists('TGS_POS_Price_List')) {
            TGS_POS_Price_List::flush_cache();
        }
    }

    /**
     * [2026-11-15 Nguyễn Văn Thủy] Ảnh mặt hàng tải lên phần mềm kế toán là đường dẫn nội bộ (/api/v1/document-attachments/<id>/file,
     * cần khoá API) — website không hiện được. Tải tệp về uploads/tgs-erp-images/<id>.<đuôi> (một lần, có rồi thì dùng lại) và
     * dùng đường dẫn website. Đường dẫn ảnh ngoài (https://…) giữ nguyên. Tải lỗi thì giữ ảnh đang có (đóng góp)
     */
    private static function image_url($url, $current = '')
    {
        if (!preg_match('~^/api/v1/document-attachments/([A-Za-z0-9_-]+)/file$~', $url, $m)) {
            return $url;
        }
        $up = wp_upload_dir(null, false);
        if (!empty($up['error'])) {
            return $current;
        }
        $dir = trailingslashit($up['basedir']) . 'tgs-erp-images';
        $base = trailingslashit($up['baseurl']) . 'tgs-erp-images/';
        foreach (glob($dir . '/' . $m[1] . '.*') ?: [] as $f) {
            return $base . basename($f);
        }
        $res = (new TGS_Acc_Api_Client())->download($url);
        if (is_wp_error($res) || $res['body'] === '' || strpos($res['type'], 'image/') !== 0) {
            return $current;
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][strtolower(trim(explode(';', $res['type'])[0]))] ?? 'img';
        if (!wp_mkdir_p($dir) || file_put_contents($dir . '/' . $m[1] . '.' . $ext, $res['body']) === false) {
            return $current;
        }
        return $base . $m[1] . '.' . $ext;
    }
}
