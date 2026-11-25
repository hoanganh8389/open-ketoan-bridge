<?php
/**
 * SW2-2 — TGS_Acc_Switch: điều kiện (ready), thủ tục sáu bước với client giả (không gọi ra kế toán), dừng đúng chỗ, đối chứng lệch,
 * gắn item_id, không đổi nguồn, nhật ký, build_import theo blog. Cần shop có ≥ 1 mã local + dòng giá (dev: shop-c).
 *   wp eval-file wp-content/plugins/open-ketoan-bridge/tests/switch-wp-test.php --url=shop-c.okt.local
 */
// [2026-11-25 03:30 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — test.

if (!defined('ABSPATH')) { exit(1); }
if (!preg_match('/(\.local|127\.0\.0\.1|localhost)(:\d+)?$/i', (string) parse_url(network_site_url(), PHP_URL_HOST))) { fwrite(STDERR, "Từ chối: chỉ máy dev.\n"); exit(1); }
$ok = 0; $fail = 0;
$check = function ($name, $cond, $extra = '') use (&$ok, &$fail) { if ($cond) { $ok++; echo "  ok  {$name}\n"; } else { $fail++; echo "SAI: {$name}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; } };
global $wpdb;
wp_set_current_user(1);
$B = (int) get_current_blog_id();
$pt = tgs_catalog_table('product', $B);
$lt = tgs_catalog_table('price_list', $B);
$ct = tgs_catalog_table('price', $B);
$rows = (array) $wpdb->get_results("SELECT global_product_sku s, global_product_tax t, global_product_item_id i, global_product_parent_item_id p, global_product_variant v FROM `{$pt}` WHERE COALESCE(is_deleted,0)=0", ARRAY_A);
if (!$rows) { fwrite(STDERR, "Shop {$B} không có mã local để thử.\n"); exit(1); }
$saved = $rows;
$src_before = TGS_Shop_Site::opt($B, 'tgs_catalog_source', null);
$log_before = TGS_Acc_Site::opt($B, TGS_Acc_Switch::LOG_OPTION, null);
register_shutdown_function(function () use ($saved, $pt, $B, $src_before, $log_before) {
    global $wpdb;
    foreach ($saved as $r) { $wpdb->update($pt, ['global_product_item_id' => $r['i'], 'global_product_parent_item_id' => $r['p'], 'global_product_variant' => $r['v']], ['global_product_sku' => $r['s']]); }
    $src_before === null ? TGS_Shop_Site::del($B, 'tgs_catalog_source') : TGS_Shop_Site::set($B, 'tgs_catalog_source', $src_before);
    $log_before === null ? TGS_Acc_Site::del($B, TGS_Acc_Switch::LOG_OPTION) : TGS_Acc_Site::set($B, TGS_Acc_Switch::LOG_OPTION, $log_before);
    TGS_Acc_Connection::forget($B);
});
TGS_Shop_Site::del($B, 'tgs_catalog_source');
TGS_Acc_Site::del($B, TGS_Acc_Switch::LOG_OPTION);
$lists = [];
foreach ((array) $wpdb->get_results("SELECT global_htsoft_price_list_id i, price_list_code c FROM `{$lt}`", ARRAY_A) as $l) { $lists[(int) $l['i']] = $l['c']; }
$skus = array_column($rows, 's');

/** Client giả: feed dựng từ bảng local (sửa được bằng $st['mut']), ghi lại lượt gọi vào $st['calls']. */
$st = ['calls' => [], 'mut' => [], 'push_failed' => 0, 'import_err' => false, 'feed_err' => false];
$client = new class($rows, $wpdb, $ct, $lists, $skus, $st) {
    private $a; private $st;
    public function __construct($rows, $wpdb, $ct, $lists, $skus, &$st) { $this->a = compact('rows', 'wpdb', 'ct', 'lists', 'skus'); $this->st = &$st; }
    public function post_json($path, array $body) {
        $this->st['calls'][] = [$path, $body];
        if ($path === '/api/v1/catalog/products/import') { return ['created' => 0, 'updated' => count($body['rows']), 'failed' => $this->st['push_failed']]; }
        return $this->st['import_err'] ? new WP_Error('boom', 'Kế toán từ chối') : ['skippedCount' => 0];
    }
    public function get($path, array $q = []) {
        $this->st['calls'][] = [$path, $q];
        if ($this->st['feed_err']) { return new WP_Error('feed', 'Feed hỏng'); }
        $mut = $this->st['mut'];
        $items = [];
        if (substr($path, -8) === 'products') {
            foreach ($this->a['rows'] as $r) {
                if (in_array($r['s'], (array) ($mut['drop'] ?? []), true)) { continue; }
                $items[] = ['sku' => $r['s'], 'itemId' => 'TEST-' . $r['s'], 'parentItemId' => null, 'variantCode' => 'normal', 'taxRate' => isset($mut['tax']) ? (float) $mut['tax'] : ($r['t'] === null ? null : (float) $r['t'])];
            }
        } else {
            foreach ((array) $this->a['wpdb']->get_results("SELECT price_list_id l, global_product_sku s, convert_unit u, unit_price p FROM `{$this->a['ct']}` WHERE COALESCE(is_deleted,0)=0 AND unit_price IS NOT NULL", ARRAY_A) as $r) {
                if (!in_array($r['s'], $this->a['skus'], true)) { continue; }
                $items[] = ['priceListCode' => $this->a['lists'][(int) $r['l']] ?? '?', 'sku' => $r['s'], 'unit' => $r['u'], 'price' => (float) $r['p'] + (float) ($mut['price'] ?? 0)];
            }
        }
        return ['data' => ['items' => $items, 'next' => 'x', 'more' => false], 'meta' => []];
    }
};
$prep_ok = function () { return ['ok' => true, 'done' => ['a'], 'skipped' => [], 'errors' => []]; };
$dry_ok = function () use ($skus) { return ['ok' => true, 'total' => count($skus), 'failed' => 0, 'errors' => [], 'rows' => []]; };
$ready_yes = function () { return true; };
$conn = function ($ok) use ($B) {
    TGS_Acc_Connection::forget($B);
    set_site_transient('tgs_okt_conn_' . $B, ['blog' => $B, 'state' => $ok ? 'connected' : 'standalone', 'ok' => $ok, 'url' => 'http://x.local', 'tenant' => $ok ? 'tn' : '', 'key_mode' => 'own', 'checked_at' => gmdate('c'), 'error' => $ok ? '' : 'lỗi giả',
        'conditions' => ['config' => ['ok' => true, 'note' => ''], 'reachable' => ['ok' => $ok, 'note' => ''], 'tenant' => ['ok' => $ok, 'note' => ''], 'unique' => ['ok' => $ok, 'note' => '']]], 60);
};
$run = function (array $extra = []) use (&$st, $client, $prep_ok, $dry_ok, $ready_yes, $B) {
    $st['calls'] = [];
    return TGS_Acc_Switch::run($B, $extra + ['client' => $client, 'readiness' => $ready_yes, 'prepare' => $prep_ok, 'dryrun' => $dry_ok]);
};
$code = function ($r) { return $r instanceof WP_Error ? $r->get_error_code() : ''; };

echo "== ready(): ba điều kiện, không ghi gì\n";
$conn(true);
$check('đủ điều kiện → true', TGS_Acc_Switch::ready($B, ['readiness' => $ready_yes]) === true);
$check('chuẩn hàng hoá chưa 100 % → not_ready', $code(TGS_Acc_Switch::ready($B, ['readiness' => function () { return false; }])) === 'tgs_switch_not_ready');
$conn(false);
$check('chưa nối 🟢 → not_connected', $code(TGS_Acc_Switch::ready($B, ['readiness' => $ready_yes])) === 'tgs_switch_not_connected');
$conn(true);
tgs_catalog_set_source($B, 'ketoan', ['via' => 'cli']);
$check('đã ở ketoan → not_local', $code(TGS_Acc_Switch::ready($B, ['readiness' => $ready_yes])) === 'tgs_switch_not_local');
TGS_Shop_Site::set($B, 'tgs_catalog_source', 'local');
$check('filter của tsm đã nối với bridge', has_filter('tgs_catalog_switch_to_ketoan_ready', ['TGS_Acc_Switch', 'filter_ready']) !== false && has_filter('tgs_catalog_switch_to_ketoan', ['TGS_Acc_Switch', 'filter_run']) !== false);

echo "== dừng đúng chỗ, chưa đẩy gì\n";
$r = $run(['prepare' => function () { return ['ok' => false, 'done' => [], 'skipped' => [], 'errors' => ['nhóm X lỗi']]; }]);
$check('dựng sẵn tenant hỏng → prepare, 0 lượt gọi', $code($r) === 'tgs_switch_prepare' && !$st['calls']);
$r = $run(['dryrun' => function () { return ['ok' => false, 'total' => 2, 'failed' => 1, 'errors' => [], 'rows' => [['row' => 1, 'sku' => '9', 'errors' => ['Nhóm cấp 1']]]]; }]);
$check('dryRun có lỗi → dryrun, nêu dòng đầu, 0 lượt đẩy', $code($r) === 'tgs_switch_dryrun' && strpos($r->get_error_message(), 'Nhóm cấp 1') !== false && !$st['calls']);
$st['push_failed'] = 1;
$r = $run();
$feed_calls = array_filter($st['calls'], function ($c) { return strpos($c[0], '/feed/') !== false; });
$check('kế toán từ chối dòng → push, không kéo về', $code($r) === 'tgs_switch_push' && !$feed_calls);
$st['push_failed'] = 0; $st['import_err'] = true;
$check('nạp ĐVT / giá lỗi → import', $code($run()) === 'tgs_switch_import');
$st['import_err'] = false; $st['feed_err'] = true;
$check('kéo về lỗi → pull', $code($run()) === 'tgs_switch_pull');
$st['feed_err'] = false;
$check('bước hỏng không đặt nguồn', tgs_catalog_source($B) === 'local');

echo "== đối chứng lệch → không báo xong\n";
$st['mut'] = ['drop' => [$skus[0]]];
$r = $run();
$check('thiếu một mã ở kế toán → diff', $code($r) === 'tgs_switch_diff' && strpos($r->get_error_message(), $skus[0]) !== false);
$st['mut'] = ['tax' => 99];
$r = $run();
$check('thuế lệch → diff', $code($r) === 'tgs_switch_diff' && strpos($r->get_error_message(), 'thuế') !== false);
$st['mut'] = ['price' => 5];
$r = $run();
$check('giá lệch → diff', $code($r) === 'tgs_switch_diff' && strpos($r->get_error_message(), 'giá') !== false);

echo "== chạy đạt\n";
$st['mut'] = [];
$conn(true);
$r = $run();
$check('đạt: ok, diff 0, có hash', is_array($r) && $r['ok'] === true && $r['diff'] === 0 && strlen($r['hash']) === 32, is_wp_error($r) ? $r->get_error_message() : '');
$check('đúng khuôn tsm to_ketoan() chờ (ok + diff = 0)', is_array($r) && !empty($r['ok']) && (int) $r['diff'] === 0);
$push = array_values(array_filter($st['calls'], function ($c) { return $c[0] === '/api/v1/catalog/products/import'; }));
$check('đẩy thật (dryRun = false), lô ≤ 200', $push && $push[0][1]['dryRun'] === false && count($push[0][1]['rows']) <= 200);
$cat = array_values(array_filter($st['calls'], function ($c) { return $c[0] === '/api/v1/catalog/import'; }));
$check('nạp qua /catalog/import: bảng giá + mặt hàng + giá', count($cat) >= 3);
$ids = $wpdb->get_col("SELECT global_product_item_id FROM `{$pt}` WHERE COALESCE(is_deleted,0)=0 ORDER BY global_product_sku");
$check('mọi mã local đã gắn item_id', $ids && !array_filter($ids, function ($i) { return strpos((string) $i, 'TEST-') !== 0; }));
$check('KHÔNG tự đặt nguồn (tsm đặt khi diff = 0)', tgs_catalog_source($B) === 'local');
$h = TGS_Acc_Switch::history($B);
$check('nhật ký ghi mỗi lần chạy, mới nhất trước', count($h) >= 5 && $h[0]['ok'] === true && $h[1]['ok'] === false);
$r2 = $run();
$check('chạy lại an toàn: vẫn diff 0, hash như cũ', is_array($r2) && $r2['diff'] === 0 && $r2['hash'] === $r['hash']);
$check('chạy lại không ghi lại item_id đã đúng', is_array($r2) && $r2['steps']['attach']['attached'] === 0);

echo "== build_import theo blog\n";
$d = TGS_Acc_Catalog_Sync::build_import($skus, $B);
$check('chỉ mã của blog', array_diff(array_column($d['products'], 'sku'), $skus) === [] && count($d['products']) === count($skus), count($d['products']) . ' vs ' . count($skus));
$check('chi nhánh chỉ của blog này', count($d['branches']) <= 1);
$d0 = TGS_Acc_Catalog_Sync::build_import($skus);
$check('gọi không blog: hành vi cũ (bảng mạng), không lỗi', is_array($d0) && isset($d0['products']));

echo "== chia lượt: start / step / status\n";
$st['mut'] = [];
$conn(true);
$deps = ['client' => $client, 'readiness' => $ready_yes, 'prepare' => $prep_ok, 'dryrun' => $dry_ok, 'batch' => 1];
$a = TGS_Acc_Switch::start($B, [], $deps);
$check('start: phase prepare, chưa xong', is_array($a) && $a['phase'] === 'prepare' && $a['done'] === false);
$check('start lần hai khi đang chạy → busy', $code(TGS_Acc_Switch::start($B, [], $deps)) === 'tgs_switch_busy');
$phases = [$a['phase']]; $n = 0; $st['calls'] = [];
do { $a = TGS_Acc_Switch::step($B, $deps); $n++; $phases[] = is_array($a) ? $a['phase'] : 'ERR'; } while (is_array($a) && !$a['done'] && $n < 200);
$check('mỗi lượt làm MỘT việc: nhiều lượt (batch = 1)', $n >= 6 && is_array($a) && $a['done'] && $a['ok'], "lượt={$n} " . (is_wp_error($a) ? $a->get_error_message() : ''));
$check('đi đúng thứ tự pha', array_values(array_unique($phases)) === ['prepare', 'dryrun', 'push', 'import', 'pull_products', 'pull_prices', 'done'], implode('>', array_unique($phases)));
$pushes = array_filter($st['calls'], function ($c) { return $c[0] === '/api/v1/catalog/products/import'; });
$check('đẩy theo lô nhỏ: batch 1 → số lượt đẩy = số mã', count($pushes) === count($skus), count($pushes) . ' vs ' . count($skus));
$check('mỗi lượt đẩy ≤ 1 dòng', !array_filter($pushes, function ($c) { return count($c[1]['rows']) > 1; }));
$stat = TGS_Acc_Switch::status($B);
$check('status sau khi xong: ok + diff 0 + không lộ gì nặng', $stat['ok'] && $stat['result']['diff'] === 0 && isset($stat['steps']['reconcile']['hash']));
$check('step sau khi xong không làm gì thêm', ($before = count($st['calls'])) !== null && TGS_Acc_Switch::step($B, $deps)['done'] === true && count($st['calls']) === $before);
$check('tsm bấm nút: filter dùng kết quả đã có, KHÔNG chạy lại', ($st['calls'] = []) === [] && ($fr = apply_filters('tgs_catalog_switch_to_ketoan', new WP_Error('x', 'y'), $B)) && is_array($fr) && $fr['ok'] === true && !$st['calls']);
$check('step khi chưa start → WP_Error idle', (function () use ($B, $deps, $code) { TGS_Acc_Site::del($B, TGS_Acc_Switch::STATE); return $code(TGS_Acc_Switch::step($B, $deps)) === 'tgs_switch_idle'; })());

echo "== huỷ + cron nền + ajax\n";
$conn(true);
TGS_Acc_Switch::start($B, ['cron' => true], $deps);
$check('start cron → có lịch tgs_acc_switch_tick', wp_next_scheduled(TGS_Acc_Switch::TICK, [$B]) !== false);
$c0 = TGS_Acc_Switch::status($B)['cron'];
$check('trạng thái đánh dấu cron', $c0 === true);
$GLOBALS['__tick_deps'] = $deps;
TGS_Acc_Switch::cancel($B);
$s = TGS_Acc_Switch::status($B);
$check('huỷ: failed + gỡ lịch cron', $s['phase'] === 'failed' && wp_next_scheduled(TGS_Acc_Switch::TICK, [$B]) === false);
$check('cron không chạy lượt khi thủ tục không phải cron / đã dừng', (function () use ($B, &$st) { $st['calls'] = []; TGS_Acc_Switch::tick($B); return !$st['calls']; })());
$check('ajax tgs_acc_switch đã đăng ký', has_action('wp_ajax_tgs_acc_switch', ['TGS_Acc_Switch', 'ajax']) !== false);
$check('tick đã đăng ký', has_action(TGS_Acc_Switch::TICK, ['TGS_Acc_Switch', 'tick']) !== false);
$ax = apply_filters('tgs_catalog_switch_to_ketoan_ajax', null, $B);
$check('tsm hỏi cách chạy từng lượt → action + nonce', is_array($ax) && $ax['action'] === 'tgs_acc_switch' && $ax['nonce'] !== '');
$asrc = (string) file_get_contents(TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-switch.php');
$check('ajax: nonce + quyền trước mọi thao tác', (bool) preg_match('/function ajax\(\)\s*\{\s*check_ajax_referer\(self::NONCE, \'nonce\'\);\s*if \(!current_user_can/', $asrc));
TGS_Acc_Site::del($B, TGS_Acc_Switch::STATE);
echo "== luật vai trò\n";
$src = (string) file_get_contents(TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-switch.php');
$check('lớp không gọi tgs_catalog_set_source (nguồn do tsm / --set đặt)', strpos($src, 'tgs_catalog_set_source') === false);
$check('không ghi bảng POS / sổ', !preg_match('/tgs_pos|local_ledger|->insert\(|->delete\(/', $src));

echo "\n{$ok} đạt, {$fail} sai\n";
exit($fail ? 1 : 0);
