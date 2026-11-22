<?php
/**
 * Điều phối gửi chứng từ của MỘT shop trong một khoảng ngày:
 *
 *   1. đọc dòng VAT (bán + điều chỉnh giảm) và phiếu thu;
 *   2. dựng chứng từ theo khuôn của phần mềm kế toán;
 *   3. so với nhật ký: không đổi → bỏ qua; đổi → tăng phiên bản;
 *   4. gửi theo lô; ghi kết quả từng chứng từ vào nhật ký;
 *   5. phiếu đã gửi trong khoảng mà nay không còn → gửi lệnh huỷ.
 *
 * Gọi được từ bất kỳ site nào: mọi truy vấn đọc theo tiền tố bảng của chính shop
 * (get_blog_prefix) — nút đẩy ở màn VAT chạy ở site tổng, công cụ dòng lệnh chạy trong shop.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Pusher
{
    const BATCH = 100;

    /** Mã băm nội dung (bỏ phiên bản) để biết chứng từ có đổi không. */
    public static function hash(array $doc)
    {
        unset($doc['version']);
        return md5(wp_json_encode($doc, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Dựng toàn bộ chứng từ của shop trong khoảng ngày.
     * @return array{docs: array[], failed: string[]}
     */
    public static function build($blog_id, $from, $to, array $only_ids = [])
    {
        $docs = [];
        $failed = [];
        // Mã chi nhánh của mọi chứng từ shop này (phiếu thu, nhập NCC, chuyển kho, xuất / điều chỉnh): chi nhánh đang nối ở
        // Cấu hình phân cấp — cùng mã phiếu bán dùng (TGS_Acc_Mapper::sale). Chưa nối mới lùi về ma_shop của tgs-bc-tk.
        // (14/11/2026: trước đây lấy ma_shop thô → phiếu thu vào chi nhánh "tên site" khác chi nhánh của phiếu bán.)
        $code = TGS_Acc_Source::branch_of_blog((int) $blog_id);

        // Phiếu bán / hoàn trong khoảng, cộng phiếu bị sửa trong khoảng nhưng thuộc ngày trước (dựng lại riêng các phiếu đó).
        // $only_ids (gửi theo sự kiện): phiếu bán chỉ lấy đúng các mã này ngay trong truy vấn (phần nặng nhất), không cần
        // tìm phiếu sửa ngày trước vì đã biết đúng phiếu.
        $sales = TGS_Acc_Source::sale_rows($blog_id, $from, $to, $only_ids);
        $returns = TGS_Acc_Source::return_rows($blog_id, $from, $to);
        $changed = $only_ids ? ['sale' => [], 'return' => []] : TGS_Acc_Source::changed_before($blog_id, $from, $to);
        foreach ($changed['sale'] as $day => $ids) {
            foreach (TGS_Acc_Source::sale_rows($blog_id, $day, $day) as $r) {
                if (in_array((int) $r['sale_id'], $ids, true)) {
                    $sales[] = $r;
                }
            }
        }
        foreach ($changed['return'] as $day => $ids) {
            foreach (TGS_Acc_Source::return_rows($blog_id, $day, $day) as $r) {
                if (in_array((int) ($r['return_id'] ?? 0), $ids, true)) {
                    $returns[] = $r;
                }
            }
        }
        $offsets = TGS_Acc_Source::offsets_by_sale(array_column($sales, 'sale_id'), $blog_id);
        foreach ($sales as $r) {
            if (empty($r['items'])) {
                $failed[] = ($r['so_phieu_xuat'] ?? '?') . ': phiếu không có dòng hàng';
                continue;
            }
            $docs[] = TGS_Acc_Mapper::sale($r, $blog_id, $offsets[(int) $r['sale_id']] ?? []);
            $code = $code ?: (string) $r['ma_shop'];
        }
        foreach ($returns as $r) {
            if (empty($r['items'])) {
                $failed[] = ($r['so_phieu_xuat'] ?? '?') . ': phiếu hoàn không có dòng hàng';
                continue;
            }
            $docs[] = TGS_Acc_Mapper::sale_return($r, $blog_id);
            $code = $code ?: (string) $r['ma_shop'];
        }

        // Phiếu thu / phiếu chi trả khách: chứng từ thanh toán riêng, theo ngày của chính phiếu.
        $code = $code ?: TGS_Acc_Source::shop_code($blog_id);
        foreach (TGS_Acc_Source::payment_rows($blog_id, $from, $to) as $p) {
            $d = TGS_Acc_Mapper::payment($p, $blog_id, $code);
            if ($d) {
                $docs[] = $d;
            }
        }

        // Phiếu nhập kho mua của NCC và phiếu trả hàng NCC.
        foreach ([1, 16] as $type) {
            foreach (TGS_Acc_Source::supplier_doc_rows($blog_id, $from, $to, $type) as $r) {
                if (empty($r['items'])) {
                    $failed[] = $r['code'] . ': phiếu không có dòng hàng';
                    continue;
                }
                $docs[] = TGS_Acc_Mapper::supplier_doc($r, $blog_id, $code);
            }
        }

        // Chuyển kho (phiếu global hai đầu): mỗi đầu của chính shop này (xuất / nhận) một chứng từ. Gửi theo sự kiện thì
        // phiếu chuyển kho đi đường riêng (build_refs $gt_ids), không dựng ở đây.
        if (!$only_ids) {
            foreach (TGS_Acc_Source::global_transfer_rows($blog_id, $from, $to) as $r) {
                $d = TGS_Acc_Mapper::global_transfer($r, $blog_id, $code);
                if (!$d['lines']) {
                    $failed[] = $r['transfer_code'] . ': phiếu chuyển kho không có dòng hàng';
                    continue;
                }
                $docs[] = $d;
            }
        }

        // Phiếu xuất điều chỉnh (xuất dùng nội bộ / tặng / dùng thử / huỷ… — bill giấy, không hoá đơn điện tử).
        foreach (TGS_Acc_Source::export_adjust_rows($blog_id, $from, $to) as $r) {
            $d = TGS_Acc_Mapper::export_adjust($r, $blog_id, $code);
            if (!$d['lines']) {
                $failed[] = $r['code'] . ': phiếu xuất điều chỉnh không có dòng hàng';
                continue;
            }
            $docs[] = $d;
        }

        // Phiếu điều chỉnh tồn kho (chênh lệch +/− từng mặt hàng).
        foreach (TGS_Acc_Source::adjustment_rows($blog_id, $from, $to) as $r) {
            $d = TGS_Acc_Mapper::adjustment($r, $blog_id, $code);
            if (!$d['lines']) {
                $failed[] = $r['code'] . ': phiếu điều chỉnh không có dòng chênh lệch số lượng';
                continue;
            }
            $docs[] = $d;
        }

        // Phiếu chuyển tiền giữa chi nhánh do shop này lập (mã chi nhánh gửi / nhận theo phiếu, không theo mã shop).
        foreach (TGS_Acc_Source::cash_transfer_rows($blog_id, $from, $to) as $r) {
            $d = TGS_Acc_Mapper::cash_transfer($r, $blog_id);
            if (!$d) {
                $failed[] = ($r['ct_code'] ?: '#' . $r['ct_id']) . ': phiếu chuyển tiền thiếu chi nhánh gửi / nhận hoặc số tiền';
                continue;
            }
            $docs[] = $d;
        }

        self::tag_warehouses($docs, $blog_id);
        TGS_Acc_Warehouse_Sync::normalize($docs);
        // [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — dịch mã (phương thức, lý do, thuế) theo bảng ánh xạ của blog TRƯỚC khi băm.
        if (class_exists('TGS_Acc_Code_Map')) {
            $docs = TGS_Acc_Code_Map::apply_all($docs, $blog_id);
        }

        // Một phiếu một chứng từ (phiếu vừa trong khoảng vừa bị sửa không lặp).
        $uniq = [];
        foreach ($docs as $d) {
            $uniq[$d['docKey']] = $d;
        }
        return ['docs' => array_values($uniq), 'failed' => $failed];
    }

    /**
     * MÃ KHO từng dòng hàng (hai tầng: chi nhánh của chứng từ theo cầu nối website ↔ chi nhánh; KHO theo dòng):
     *   - dòng ghi phân kho (local_ledger_item_warehouse_zone — kho tổng nhiều phân kho, vd 08000: 08-HH, 08-KM…) → phân kho đó;
     *   - dòng không ghi → giữ mã kho đang có trên dòng (thường trống).
     * Sau đó TGS_Acc_Warehouse_Sync::normalize bỏ mã không phải kho của chi nhánh bên kế toán (→ kho mặc định bên kế toán).
     * 12/11/2026: bỏ lấy mã site (tgs_site_code) làm mã kho — kế toán là gốc phân kho, mã site không phải mã kho DM12.
     * lineKey của dòng là id dòng hàng (local_ledger_item_id) của chính website. Chuyển kho: mỗi đầu là chứng từ của shop
     * mình nên kho xuất / kho nhập nằm đúng ở đầu tương ứng. Một truy vấn cho cả lượt.
     */
    private static function tag_warehouses(array &$docs, $blog_id)
    {
        global $wpdb;
        // Chỉ chứng từ có hàng ra / vào kho (thu chi, chuyển tiền không có kho).
        $stock = ['sale', 'sale_return', 'purchase', 'purchase_return', 'transfer', 'stock_adjustment'];
        $ids = [];
        foreach ($docs as $d) {
            if (!in_array($d['docType'] ?? '', $stock, true)) {
                continue;
            }
            foreach ((array) ($d['lines'] ?? []) as $l) {
                if (ctype_digit((string) ($l['lineKey'] ?? ''))) {
                    $ids[(int) $l['lineKey']] = true;
                }
            }
        }
        $zones = [];
        if ($ids) {
            $I = $wpdb->get_blog_prefix((int) $blog_id) . 'local_ledger_item';
            foreach ((array) $wpdb->get_results('SELECT local_ledger_item_id AS id, local_ledger_item_warehouse_zone AS zone FROM ' . $I
                . ' WHERE local_ledger_item_id IN (' . implode(',', array_keys($ids)) . ')', ARRAY_A) as $r) {
                $z = trim((string) $r['zone']);
                if ($z !== '') {
                    $zones[(int) $r['id']] = $z;
                }
            }
        }
        foreach ($docs as &$d) {
            if (empty($d['lines']) || !in_array($d['docType'] ?? '', $stock, true) || !empty($d['extra']['globalTransferId'])) {
                continue;
            }
            foreach ($d['lines'] as &$l) {
                $key = (string) ($l['lineKey'] ?? '');
                $code = ctype_digit($key) ? ($zones[(int) $key] ?? '') : '';
                if ($code !== '') {
                    $l['warehouseCode'] = $code;
                }
            }
            unset($l);
        }
        unset($d);
    }

    /**
     * Gửi. $opts: dry (chỉ dựng), force (gửi lại kể cả không đổi), client (TGS_Acc_Api_Client),
     * origin ('cli' | 'cron' | 'web' — ghi vào nhật ký lượt gửi).
     * @return array tổng kết
     */
    public static function push($blog_id, $from, $to, array $opts = [])
    {
        $t0 = microtime(true);
        $summary = self::push_inner($blog_id, $from, $to, $opts);
        if (empty($opts['dry'])) {
            TGS_Acc_Push_Log::save_run($blog_id, $from, $to, $summary, (string) ($opts['origin'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'web')), (microtime(true) - $t0) * 1000);
        }
        return $summary;
    }

    /** Loại phiếu nguồn gắn vào chứng từ (sourceRef) để kế toán lọc, mở phiếu gốc — chung cho kéo / đẩy / sự kiện. */
    private static $source_types = [
        'sale'            => ['sale_order', 'Phiếu bán hàng'],
        'sale_return'     => ['customer_return', 'Phiếu hoàn hàng từ khách'],
        'purchase'        => ['stock_in', 'Phiếu nhập kho'],
        'purchase_return' => ['supplier_return', 'Phiếu trả hàng NCC'],
        'cash_transfer'   => ['cash_transfer', 'Phiếu chuyển tiền'],
    ];

    /** Bổ sung sourceRef (loại phiếu nguồn) nếu mapper chưa gắn. */
    public static function decorate(array $doc)
    {
        $t = self::$source_types[$doc['docType'] ?? ''] ?? null;
        if ($t && empty($doc['sourceRef'])) {
            $doc['sourceRef'] = ['type' => $t[0], 'id' => (string) preg_replace('/^.*:/', '', $doc['docKey']), 'label' => $t[1]];
        }
        return $doc;
    }

    /**
     * Dựng lại ĐÚNG các chứng từ bị ảnh hưởng bởi một nhóm phiếu vừa đổi (gửi tức thời theo sự kiện):
     *   $ledger_ids: local_ledger_id của shop (phiếu bán, hoàn, thu / chi, nhập mua, trả NCC, chuyển kho, điều chỉnh, xuất
     *   điều chỉnh); phiếu thu / chi kéo theo phiếu cha. $ct_ids: phiếu chuyển tiền (global_cash_transfer.ct_id, shop lập).
     * Dựng theo ngày của từng phiếu (mỗi ngày một lượt build) rồi chỉ giữ chứng từ của các phiếu đó.
     * @return array{docs: array[], gone: string[], failed: string[]}  gone = khoá đã gửi trước đây mà nay không còn (xoá /
     *         bỏ duyệt / không còn đủ điều kiện) → cần báo huỷ.
     */
    public static function build_refs($blog_id, array $ledger_ids, array $ct_ids = [], array $gt_ids = [])
    {
        global $wpdb;
        $blog_id = (int) $blog_id;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ledger_ids))));
        $days = [];
        $want = [];
        if ($ids) {
            $L = $wpdb->get_blog_prefix($blog_id) . 'local_ledger';
            $rows = $wpdb->get_results(
                "SELECT local_ledger_id AS id, local_ledger_type AS type, local_ledger_parent_id AS parent_id,
                        COALESCE(local_ledger_ticket_date, DATE(created_at)) AS day
                   FROM {$L} WHERE local_ledger_id IN (" . implode(',', $ids) . ")",
                ARRAY_A
            ) ?: [];
            $parents = [];
            foreach ($rows as $r) {
                $want[(int) $r['id']] = true;
                if ($r['day']) {
                    $days[$r['day']] = true;
                }
                // Phiếu thu / chi trả khách đổi: chứng từ thanh toán của nó + phiếu cha (bán / hoàn) dựng lại theo ngày phiếu cha.
                if (in_array((int) $r['type'], [7, 8], true) && (int) $r['parent_id'] > 0) {
                    $parents[] = (int) $r['parent_id'];
                }
            }
            if ($parents) {
                foreach ($wpdb->get_results("SELECT local_ledger_id AS id, COALESCE(local_ledger_ticket_date, DATE(created_at)) AS day FROM {$L} WHERE local_ledger_id IN (" . implode(',', array_unique($parents)) . ")", ARRAY_A) ?: [] as $p) {
                    $want[(int) $p['id']] = true;
                    if ($p['day']) {
                        $days[$p['day']] = true;
                    }
                }
            }
            foreach ($ids as $id) {
                $want[$id] = true; // phiếu đã xoá cứng vẫn cần so khoá để báo huỷ
            }
        }
        $cts = array_values(array_unique(array_filter(array_map('intval', $ct_ids))));
        $want_ct = array_fill_keys($cts, true);
        if ($cts) {
            $T = $wpdb->base_prefix . 'global_cash_transfer';
            foreach ($wpdb->get_col("SELECT DISTINCT transfer_date FROM {$T} WHERE ct_id IN (" . implode(',', $cts) . ")") ?: [] as $d) {
                $days[substr((string) $d, 0, 10)] = true;
            }
        }

        $docs = [];
        $failed = [];
        foreach (array_keys($days) as $day) {
            $built = self::build($blog_id, $day, $day, array_keys($want) ?: [0]);
            $failed = array_merge($failed, $built['failed']);
            foreach ($built['docs'] as $d) {
                $parts = explode(':', (string) $d['docKey']);
                $ref = (int) end($parts);
                $is_ct = ($parts[2] ?? '') === 'cash_transfer';
                if (($is_ct && isset($want_ct[$ref])) || (!$is_ct && isset($want[$ref]))) {
                    $docs[$d['docKey']] = $d;
                }
            }
        }

        // Phiếu chuyển kho global: dựng thẳng theo mã phiếu (mọi ngày), mỗi đầu của shop này một chứng từ.
        $gts = array_values(array_unique(array_filter(array_map('intval', $gt_ids))));
        $want_gt = array_fill_keys($gts, true);
        if ($gts) {
            $code = TGS_Acc_Source::shop_code($blog_id);
            foreach (TGS_Acc_Source::global_transfer_rows($blog_id, '1970-01-01', '2999-12-31', $gts) as $r) {
                $d = TGS_Acc_Mapper::global_transfer($r, $blog_id, $code);
                if ($d['lines']) {
                    $docs[$d['docKey']] = $d;
                } else {
                    $failed[] = $r['transfer_code'] . ': phiếu chuyển kho không có dòng hàng';
                }
            }
        }

        $docs = array_values($docs);
        TGS_Acc_Warehouse_Sync::normalize($docs); // phiếu chuyển kho global (phần dựng bằng build đã chuẩn hoá — chạy lại không đổi)
        if (class_exists('TGS_Acc_Code_Map')) {
            $docs = TGS_Acc_Code_Map::apply_all($docs, $blog_id); // S001-MD3 — chạy lại không đổi (mã đã dịch không phải mã nguồn)
        }
        return ['docs' => $docs, 'gone' => self::gone_keys($blog_id, $want, $want_ct, $docs, $want_gt), 'failed' => $failed, 'want' => $want, 'want_ct' => $want_ct, 'want_gt' => $want_gt];
    }

    /**
     * Khoá đã gửi (theo nhật ký của BÊN NHẬN đang dùng — sổ nội bộ hay sổ thuế, TGS_Acc_Push_Log::use_consumer) của các phiếu
     * $want / $want_ct mà danh sách $docs không còn → cần báo huỷ (phiếu xoá, bỏ duyệt, hoặc với sổ thuế: không còn số hoá đơn).
     */
    public static function gone_keys($blog_id, array $want, array $want_ct, array $docs, array $want_gt = [])
    {
        global $wpdb;
        $blog_id = (int) $blog_id;
        $docs = array_column($docs, null, 'docKey');
        $gone = [];
        $t = TGS_Acc_Push_Log::table();
        $patterns = [];
        foreach (array_keys($want) as $id) {
            $patterns[] = $wpdb->prepare('doc_key LIKE %s', 'btsoft:' . $blog_id . ':%:' . $id);
        }
        foreach (array_keys($want_ct) as $id) {
            $patterns[] = $wpdb->prepare('doc_key = %s', 'btsoft:' . $blog_id . ':cash_transfer:' . $id);
        }
        foreach (array_keys($want_gt) as $id) {
            $patterns[] = $wpdb->prepare('doc_key IN (%s, %s)', 'btsoft:' . $blog_id . ':gt-out:' . $id, 'btsoft:' . $blog_id . ':gt-in:' . $id);
        }
        foreach (array_chunk($patterns, 200) as $chunk) {
            foreach ($wpdb->get_col("SELECT doc_key FROM {$t} WHERE status NOT IN ('cancelled') AND (" . implode(' OR ', $chunk) . ")") ?: [] as $key) {
                // LIKE '…:%:12' cũng khớp '…:112'? Không: phần sau dấu ':' cuối phải đúng bằng mã phiếu.
                $parts = explode(':', $key);
                $ref = (int) end($parts);
                $is_ct = ($parts[2] ?? '') === 'cash_transfer';
                $is_gt = strpos((string) ($parts[2] ?? ''), 'gt-') === 0; // chứng từ chuyển kho global: mã sau cùng là transfer_id
                $hit = $is_gt ? isset($want_gt[$ref]) : ($is_ct ? isset($want_ct[$ref]) : isset($want[$ref]));
                if (!isset($docs[$key]) && $hit) {
                    $gone[] = $key;
                }
            }
        }
        return array_values(array_unique($gone));
    }

    /**
     * Gửi các chứng từ đã dựng: so nhật ký (không đổi → bỏ qua; đổi → tăng phiên bản), bảo đảm chi nhánh bên kế toán, gửi
     * theo lô, ghi kết quả từng chứng từ. Dùng chung cho nút / dòng lệnh (push) và gửi tức thời theo sự kiện.
     * $opts: dry, force, client. @return array tổng kết (skipped, posted, unchanged, error, errors, to_send)
     */
    public static function send($blog_id, array $docs, array $opts = [])
    {
        $summary = ['skipped' => 0, 'posted' => 0, 'pending' => 0, 'unchanged' => 0, 'error' => 0, 'errors' => [], 'to_send' => 0, 'transport' => 0]; // [2026-11-22 06:10 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — transport: số chứng từ không tới được kế toán (lỗi đường truyền)
        // So nhật ký → quyết định phiên bản, bỏ qua cái không đổi.
        $log = TGS_Acc_Push_Log::find_many(array_column($docs, 'docKey'));
        $to_send = [];
        $meta = [];
        foreach ($docs as $doc) {
            // Mã băm tính TRƯỚC khi gắn sourceRef — giống cổng chờ, để kéo / đẩy / sự kiện cùng một mã băm, không tăng phiên bản vô cớ.
            $h = self::hash($doc);
            $doc = self::decorate($doc);
            $prev = $log[$doc['docKey']] ?? null;
            // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9 — đã ghi sổ / đang chờ định khoản / định khoản lỗi ở kế toán: không gửi lại cùng nội dung.
            if ($prev && $prev['content_hash'] === $h && TGS_Acc_Push_Log::is_delivered($prev['status']) && empty($opts['force'])) {
                $summary['skipped']++;
                continue;
            }
            $version = $prev ? ((int) $prev['version'] + ($prev['content_hash'] === $h ? 0 : 1)) : 1;
            $doc['version'] = max(1, $version);
            $to_send[] = $doc;
            $meta[$doc['docKey']] = ['hash' => $h, 'version' => $doc['version'], 'doc' => $doc];
        }
        $summary['to_send'] = count($to_send);
        if (!empty($opts['dry'])) {
            $summary['sample'] = $to_send[0] ?? null;
            return $summary;
        }
        if (!$to_send) {
            return $summary;
        }
        /** @var TGS_Acc_Api_Client $client */
        $client = $opts['client'] ?? new TGS_Acc_Api_Client();

        // Chi nhánh bên kế toán = mã shop; chưa có thì tạo (tên lấy theo tên shop). Phiếu chuyển tiền mang mã chi nhánh
        // gửi / nhận của chính phiếu (có thể không phải shop này) nên không dùng để suy mã shop.
        $own = array_values(array_filter($docs, function ($d) { return $d['docType'] !== 'cash_transfer'; }));
        if ($own) {
            $err = self::check_branch($client, (int) $blog_id, (string) $own[0]['branchCode']);
            if ($err !== '') {
                $summary['errors'][] = $err;
            }
        }

        foreach (array_chunk($to_send, self::BATCH) as $chunk) {
            $res = $client->send_batch($chunk);
            if (is_wp_error($res)) {
                $summary['errors'][] = 'Lô lỗi: ' . $res->get_error_message();
                foreach ($chunk as $d) {
                    // [2026-11-22 05:30 PM Johnny Chu - Chu Hoàng Anh] OKT-I3 — hỏng ĐƯỜNG TRUYỀN (chưa tới kế toán): vẫn lưu mã băm, để lần gửi bù cùng
                    // nội dung giữ nguyên phiên bản (I-4); trạng thái 'error' vẫn bảo đảm gửi lại. Đo trên WP thật: trước đây gửi bù lên v2 vô cớ.
                    TGS_Acc_Push_Log::save($blog_id, $d, $meta[$d['docKey']]['version'], $meta[$d['docKey']]['hash'], 'error', $res->get_error_message());
                }
                $summary['error'] += count($chunk);
                $summary['transport'] += count($chunk);
                continue;
            }
            foreach ($res['results'] as $r) {
                $key = (string) ($r['docKey'] ?? '');
                if (!isset($meta[$key])) {
                    continue;
                }
                $status = (string) ($r['status'] ?? 'error');
                $msg = (string) ($r['error']['message'] ?? '');
                // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9 — 'pending' của kế toán GIỮ là 'pending' (trước đây ghi thành 'posted'); 'unchanged' cũng ghi 'pending' để lần kéo trạng thái (refresh_status) xác định đúng.
                // posted: đã tự định khoản · pending: vào cổng chờ định khoản · unchanged: kế toán đã có đúng phiên bản này (chưa biết đã ghi sổ chưa).
                $log_status = $status === 'posted' ? 'posted' : (in_array($status, ['pending', 'unchanged'], true) ? 'pending' : 'error');
                $note = $status === 'pending' ? 'ERP đã nhận (vào cổng chờ định khoản)' : ($status === 'posted' ? 'ERP đã nhận và tự định khoản' : ($status === 'unchanged' ? 'ERP đã có phiên bản này — chờ kéo trạng thái' : $msg));
                // Lỗi thì không lưu mã băm → lần sau gửi lại.
                TGS_Acc_Push_Log::save($blog_id, $meta[$key]['doc'], $meta[$key]['version'], $log_status === 'error' ? '' : $meta[$key]['hash'], $log_status, $note);
                $summary[$log_status === 'error' ? 'error' : $status]++;
                if ($log_status === 'error') {
                    $summary['errors'][] = $meta[$key]['doc']['docNo'] . ': ' . $msg;
                }
            }
        }
        return $summary;
    }

    /**
     * Báo ERP các khoá chứng từ không còn ở nguồn (xoá / bỏ duyệt). ERP: chế độ Tự động thì tự huỷ, Vào cổng chờ thì Chờ huỷ.
     * @return array{cancelled: int, errors: string[]}
     */
    public static function cancel(array $keys, $client = null)
    {
        $out = ['cancelled' => 0, 'errors' => []];
        if (!$keys) {
            return $out;
        }
        /** @var TGS_Acc_Api_Client $client */
        $client = $client ?: new TGS_Acc_Api_Client();
        foreach ($keys as $key) {
            $c = $client->cancel($key, 'Phiếu đã xoá / bỏ duyệt ở hệ thống bán hàng');
            if (is_wp_error($c)) {
                $data = (array) $c->get_error_data();
                if ((int) ($data['status'] ?? 0) === 404) {
                    // ERP chưa từng nhận phiếu này (gửi lỗi từ trước): không có gì để huỷ.
                    TGS_Acc_Push_Log::mark($key, 'cancelled', 'Phiếu không còn ở nguồn; ERP chưa có phiếu này');
                    $out['cancelled']++;
                    continue;
                }
                $out['errors'][] = "Huỷ {$key}: " . $c->get_error_message();
                continue;
            }
            TGS_Acc_Push_Log::mark($key, 'cancelled', !empty($c['cancelled']) ? 'ERP đã tự huỷ (chế độ Tự động)' : 'Đã báo ERP phiếu không còn — chờ kế toán định khoản huỷ');
            $out['cancelled']++;
        }
        return $out;
    }

    private static function push_inner($blog_id, $from, $to, array $opts)
    {
        TGS_Acc_Push_Log::maybe_install();
        $built = self::build($blog_id, $from, $to);
        $docs = $built['docs'];
        $sent = self::send($blog_id, $docs, $opts);
        $summary = array_merge(['built' => count($docs), 'failed_build' => $built['failed'], 'cancelled' => 0], $sent);
        if (!empty($opts['dry'])) {
            return $summary;
        }
        // Phiếu đã gửi trong khoảng mà nay không còn ở shop → báo huỷ bên kế toán.
        $c = self::cancel(TGS_Acc_Push_Log::cancel_keys($blog_id, $from, $to, array_column($docs, 'docKey'), TGS_Acc_Source::deleted_keys($blog_id, $from, $to)), $opts['client'] ?? null);
        $summary['cancelled'] = $c['cancelled'];
        $summary['errors'] = array_merge($summary['errors'], $c['errors']);
        return $summary;
    }

    // [2026-11-21 08:30 PM Johnny Chu - Chu Hoàng Anh] S002-5 — mã chi nhánh khai tay (TGS_Acc_Source::branch_override) chỉ KIỂM có ở tenant, không tự tạo chi nhánh trống; mã dò như cũ thì giữ ensure_branch.
    /**
     * Bảo đảm chi nhánh $code có bên kế toán trước khi gửi. Trả '' khi được, không thì câu báo lỗi.
     *   - Mã do admin khai cho blog (TGS_Acc_Source::branch_override): chỉ kiểm có trong GET /api/v1/branches — không có
     *     thì báo lỗi, KHÔNG tạo (tạo sẽ ra chi nhánh trống, không kho).
     *   - Mã dò kiểu cũ (phân cấp / mã site): như trước — chưa có thì ensure_branch tạo.
     * Đã bảo đảm thì nhớ ở site option (theo blog với mã khai tay, vì mỗi blog một tenant) — không hỏi lại mỗi lần gửi.
     */
    public static function check_branch($client, $blog_id, $code)
    {
        $code = (string) $code;
        if ($code === '') {
            return 'Chưa có mã chi nhánh của shop (khai option tgs_acc_branch_code của blog, ví dụ CN01)';
        }
        $explicit = TGS_Acc_Source::branch_override((int) $blog_id) !== '';
        $cache = 'tgs_acc_known_branches' . (TGS_Acc_Push_Log::consumer() === 'tax' ? '_tax' : '');
        $key = $explicit ? (int) $blog_id . ':' . $code : $code;
        $known = (array) get_site_option($cache, []);
        if (!empty($known[$key])) {
            return '';
        }
        if ($explicit) {
            $list = $client->get('/api/v1/branches');
            if (is_wp_error($list)) {
                return "Không đọc được danh mục chi nhánh bên kế toán: " . $list->get_error_message();
            }
            $found = false;
            foreach ((array) ($list['data'] ?? []) as $b) {
                if (strcasecmp((string) ($b['code'] ?? ''), $code) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return "Chi nhánh {$code} (khai ở tgs_acc_branch_code của blog {$blog_id}) chưa có ở tenant kế toán — tạo ở kế toán hoặc sửa mã";
            }
        } else {
            $name = trim((string) get_blog_option((int) $blog_id, 'tgs_shop_name', '')) ?: (string) get_blog_option((int) $blog_id, 'blogname', '');
            $ok = $client->ensure_branch($code, $name, 'blog:' . (int) $blog_id);
            if (is_wp_error($ok)) {
                return "Không tạo được chi nhánh {$code} bên kế toán: " . $ok->get_error_message();
            }
        }
        $known[$key] = 1;
        update_site_option($cache, $known);
        return '';
    }


    // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-10 — kéo trạng thái chứng từ đang chờ từ kế toán về nhật ký gửi (ADR-OKT-1 I-6: biết được đơn đã vào sổ chưa).
    /**
     * Hỏi kế toán trạng thái các chứng từ 'pending' của blog (GET /api/v1/documents?search=<docKey>, khoá cần documents:read
     * — có sẵn trong documents:write) rồi đổi nhật ký gửi:
     *   posted (postedVersion ≥ phiên bản đã gửi) → 'posted' · error → 'remote_error' (kèm lời kế toán) · cancelled → 'cancelled'
     *   pending → giữ · không tìm thấy → giữ (ghi vào 'missing').
     * Chỉ đổi dòng đúng phiên bản đang chờ, không đè phiên bản mới hơn vừa gửi.
     * @return array{checked:int, posted:int, remote_error:int, cancelled:int, still:int, missing:int, errors:string[]}
     */
    public static function refresh_status($blog_id, $client, $limit = 50)
    {
        $out = ['checked' => 0, 'posted' => 0, 'remote_error' => 0, 'cancelled' => 0, 'still' => 0, 'missing' => 0, 'errors' => []];
        foreach (TGS_Acc_Push_Log::pending_rows((int) $blog_id, $limit) as $row) {
            $key = (string) $row['doc_key'];
            $res = $client->get('/api/v1/documents', ['search' => $key, 'pageSize' => 5]);
            if (is_wp_error($res)) {
                $out['errors'][] = "{$key}: " . $res->get_error_message();
                continue;
            }
            $out['checked']++;
            $doc = null;
            foreach ((array) ($res['data'] ?? []) as $d) {
                if ((string) ($d['docKey'] ?? '') === $key) {
                    $doc = $d;
                    break;
                }
            }
            if (!$doc) {
                $out['missing']++;
                continue;
            }
            $status = (string) ($doc['status'] ?? '');
            $mine = (int) $row['version'];
            if ($status === 'posted' && (int) ($doc['postedVersion'] ?? $doc['version'] ?? 0) >= $mine) {
                TGS_Acc_Push_Log::set_status($key, $mine, 'posted', 'Kế toán đã ghi sổ');
                $out['posted']++;
            } elseif ($status === 'error') {
                $msg = trim((string) ($doc['errorMessage'] ?? '')) ?: 'Kế toán định khoản lỗi';
                TGS_Acc_Push_Log::set_status($key, $mine, 'remote_error', 'Kế toán: ' . $msg);
                $out['remote_error']++;
            } elseif ($status === 'cancelled') {
                TGS_Acc_Push_Log::set_status($key, $mine, 'cancelled', 'Kế toán đã huỷ chứng từ');
                $out['cancelled']++;
            } else {
                $out['still']++;
            }
        }
        return $out;
    }

}
