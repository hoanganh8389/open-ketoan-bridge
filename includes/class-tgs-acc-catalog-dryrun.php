<?php
/**
 * KIỂM THỬ DANH MỤC Ở KẾ TOÁN (SW4b-2 — open-pos-backend PHASE-001 doc 06 / doc 16 §5): gửi danh mục của shop vào đường nhập Excel
 * CHẶT nhất của kế toán `POST /api/v1/catalog/products/import {dryRun: true}` — kế toán kiểm từng dòng, KHÔNG ghi gì — để thấy lỗi
 * theo dòng trước lần đẩy thật (SW2). Bằng khoá của chính blog (I-1).
 *
 *   Dòng = một mã còn sống: sku, name, unit (ĐVT tỉ lệ 1), groupCode (mã nhóm bên kế toán = id_sync_api ?: id — như SW4b-1 / chiều kéo về),
 *          vatRate (TGS_Tax_Code::okt_code: KCT · 0 · 5 · 8 · 10 · KKKN), barcode, isActive. Mã cha trước mã con. Lô ≤ 2.000 dòng.
 *   Kết quả gần nhất lưu option của blog `tgs_acc_catalog_dryrun` (autoload no): tổng, sẽ thêm / sẽ sửa / lỗi, các dòng lỗi (tối đa 2.000).
 *   Hiện ở màn tsm "Chuẩn hàng hoá" qua hook `tgs_catalog_readiness_panels` (tsm không gọi bridge — P-0 ý 4); nút chạy = admin-post.
 *   CLI: wp tgs-acc dryrun --shop=<id>.
 *
 * @package open-ketoan-bridge
 */
// [2026-11-24 09:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-2 — kiểm thử danh mục bằng dryRun của kế toán, lỗi theo dòng.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Catalog_Dryrun
{
    const OPTION = 'tgs_acc_catalog_dryrun';
    const BATCH = 2000;
    const KEEP = 2000;

    public static function init()
    {
        add_action('tgs_catalog_readiness_panels', [__CLASS__, 'panel']);
        add_action('admin_post_tgs_acc_catalog_dryrun', [__CLASS__, 'handle']);
    }

    /** Dòng gửi kế toán từ danh mục của shop (đọc qua tgs_catalog_table — bảng của blog hoặc bảng mạng theo cờ). */
    public static function rows($blog_id)
    {
        global $wpdb;
        $pt = tgs_catalog_table('product', $blog_id);
        $ct = tgs_catalog_table('cat', $blog_id);
        $ut = tgs_catalog_table('unit', $blog_id);
        $code = [];
        foreach ((array) $wpdb->get_results("SELECT global_product_cat_id id, id_sync_api FROM `{$ct}`", ARRAY_A) as $c) {
            $code[(int) $c['id']] = (string) ($c['id_sync_api'] ?: $c['id']);
        }
        $base = [];
        foreach ((array) $wpdb->get_results("SELECT global_product_sku s, convert_unit u FROM `{$ut}` WHERE COALESCE(is_deleted, 0) = 0 AND ABS(convert_to_htsoft - 1) < 0.0005 AND convert_unit <> ''", ARRAY_A) as $u) {
            $base[(string) $u['s']] = $base[(string) $u['s']] ?? (string) $u['u'];
        }
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT global_product_sku, global_product_name, global_product_unit, global_product_cat_id, global_product_tax, global_product_is_kct,
                global_product_barcode_main, global_product_status, global_product_parent_sku FROM `{$pt}` WHERE (is_deleted = 0 OR is_deleted IS NULL) ORDER BY global_product_name_id", ARRAY_A) as $p) {
            $sku = (string) $p['global_product_sku'];
            $tax = $p['global_product_tax'];
            $out[] = array_filter([
                'sku' => $sku, 'name' => (string) $p['global_product_name'], 'unit' => $base[$sku] ?? (string) $p['global_product_unit'],
                'groupCode' => (int) $p['global_product_cat_id'] ? ($code[(int) $p['global_product_cat_id']] ?? '') : '',
                'vatRate' => (string) TGS_Tax_Code::okt_code($tax === null ? null : (float) $tax, (int) $p['global_product_is_kct']),
                'barcode' => mb_substr((string) $p['global_product_barcode_main'], 0, 600), 'isActive' => (int) $p['global_product_status'] === 1 ? 'Có' : 'Không',
                '_parent' => (string) $p['global_product_parent_sku'],
            ], function ($v) { return $v !== ''; }) + ['sku' => $sku];
        }
        // mã cha trước mã con (kế toán cần cha có trước, kể cả trong cùng lô)
        usort($out, function ($a, $b) { return (int) isset($a['_parent']) <=> (int) isset($b['_parent']); });
        foreach ($out as $i => &$r) {
            $r['row'] = $i + 1;
            unset($r['_parent']);
        }
        return $out;
    }

    /**
     * @param array $deps ['client' => …, 'batch' => n] (test)
     * @return array{ok: bool, at: string, total: int, create: int, update: int, failed: int, errors: array, rows: array}
     */
    public static function run($blog_id, array $deps = [])
    {
        $blog_id = (int) $blog_id;
        $res = ['ok' => false, 'at' => current_time('mysql'), 'total' => 0, 'create' => 0, 'update' => 0, 'failed' => 0, 'errors' => [], 'rows' => []];
        if (!function_exists('tgs_catalog_table') || !class_exists('TGS_Tax_Code') || !method_exists('TGS_Tax_Code', 'okt_code')) {
            $res['errors'][] = 'Thiếu open-pos-backend bản có TGS_Tax_Code::okt_code — không dựng được dòng gửi kế toán.';
            return self::save($blog_id, $res);
        }
        if (!TGS_Acc_Config::ready_for($blog_id)) {
            $res['errors'][] = "Blog {$blog_id} chưa nối phần mềm kế toán (chưa có khoá).";
            return self::save($blog_id, $res);
        }
        $c = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog_id);
        $rows = self::rows($blog_id);
        $res['total'] = count($rows);
        foreach (array_chunk($rows, max(1, (int) ($deps['batch'] ?? self::BATCH))) as $chunk) {
            $r = $c->post_json('/api/v1/catalog/products/import', ['dryRun' => true, 'rows' => $chunk]);
            if (is_wp_error($r)) {
                $res['errors'][] = 'Kế toán không kiểm được lô ' . $chunk[0]['row'] . '–' . end($chunk)['row'] . ': ' . $r->get_error_message();
                continue;
            }
            $res['create'] += (int) ($r['created'] ?? 0);
            $res['update'] += (int) ($r['updated'] ?? 0);
            $res['failed'] += (int) ($r['failed'] ?? 0);
            foreach ((array) ($r['rows'] ?? []) as $x) {
                if (($x['action'] ?? '') === 'error' && count($res['rows']) < self::KEEP) {
                    $res['rows'][] = ['row' => (int) $x['row'], 'sku' => (string) $x['sku'], 'errors' => array_values(array_map('strval', (array) ($x['errors'] ?? [])))];
                }
            }
        }
        $res['ok'] = !$res['errors'] && $res['failed'] === 0;
        return self::save($blog_id, $res);
    }

    public static function last($blog_id)
    {
        $v = TGS_Acc_Site::opt((int) $blog_id, self::OPTION, null);
        return is_array($v) ? $v : null;
    }

    private static function save($blog_id, array $res)
    {
        $switched = TGS_Acc_Site::is_ms() && (int) $blog_id !== (int) get_current_blog_id() ? TGS_Acc_Site::switch_to((int) $blog_id) : false;
        update_option(self::OPTION, $res, false);
        if ($switched) {
            TGS_Acc_Site::restore();
        }
        return $res;
    }

    /** admin-post: chạy kiểm thử cho blog hiện tại rồi về màn Chuẩn hàng hoá. */
    public static function handle()
    {
        check_admin_referer('tgs_acc_catalog_dryrun');
        if (!current_user_can('manage_options')) {
            wp_die('Không có quyền.', 403);
        }
        self::run((int) get_current_blog_id());
        wp_safe_redirect(add_query_arg(['page' => 'tgs-catalog-readiness', 'dryrun' => 1], admin_url('admin.php')) . '#tgs-acc-dryrun');
        exit;
    }

    /** Ô ở màn tsm "Chuẩn hàng hoá" (hook tgs_catalog_readiness_panels). */
    public static function panel($blog_id)
    {
        $blog_id = (int) ($blog_id ?: get_current_blog_id());
        $ready = TGS_Acc_Config::ready_for($blog_id);
        $last = self::last($blog_id);
        ?>
        <div class="card" id="tgs-acc-dryrun" style="max-width: 980px; padding: 12px 16px; margin: 12px 0">
            <h2 style="margin-top:0">Kiểm thử ở kế toán</h2>
            <p class="description">Gửi danh mục của shop vào đường nhập Excel của phần mềm kế toán ở chế độ <b>kiểm thử</b> (dryRun): kế toán kiểm từng dòng, <b>không ghi gì</b>. Cần 0 lỗi trước khi đẩy danh mục lên kế toán.</p>
            <?php if (!$ready) : ?>
                <p class="description">Shop chưa nối phần mềm kế toán — dán khoá ở Cổng kế toán trước.</p>
            <?php else : ?>
                <?php if ($last) : ?>
                    <p id="tgs-acc-dryrun-sum">Lần gần nhất <?php echo esc_html((string) $last['at']); ?>: <b><?php echo (int) $last['total']; ?></b> mã ·
                        <?php echo (int) $last['create']; ?> sẽ thêm · <?php echo (int) $last['update']; ?> sẽ sửa ·
                        <b style="color:<?php echo $last['failed'] || $last['errors'] ? '#b3261e' : '#1e7a4a'; ?>"><?php echo (int) $last['failed']; ?> lỗi</b></p>
                    <?php foreach ((array) $last['errors'] as $e) : ?><p style="color:#b3261e"><?php echo esc_html($e); ?></p><?php endforeach; ?>
                    <?php if ($last['rows']) : ?>
                        <table class="widefat striped" id="tgs-acc-dryrun-rows"><thead><tr><th>Dòng</th><th>SKU</th><th>Kế toán báo</th></tr></thead><tbody>
                        <?php foreach (array_slice($last['rows'], 0, 200) as $r) : ?>
                            <tr><td><?php echo (int) $r['row']; ?></td><td><code><?php echo esc_html($r['sku']); ?></code></td><td><?php echo esc_html(implode(' · ', $r['errors'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                        <?php if (count($last['rows']) > 200) : ?><p class="description">… còn <?php echo count($last['rows']) - 200; ?> dòng lỗi (xem bằng <code>wp tgs-acc dryrun --shop=<?php echo $blog_id; ?></code>).</p><?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tgs_acc_catalog_dryrun">
                    <?php wp_nonce_field('tgs_acc_catalog_dryrun'); ?>
                    <?php submit_button($last ? 'Kiểm thử lại' : 'Kiểm thử ở kế toán', 'secondary', 'submit', false, ['id' => 'tgs-acc-dryrun-btn']); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

TGS_Acc_Catalog_Dryrun::init();
