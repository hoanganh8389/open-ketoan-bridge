<?php
/**
 * LÕI GHI BẢN SAO CHỨNG TỪ KẾ TOÁN → local_ledger* CỦA SHOP (14/11/2026, phiếu NT-BH-017, chữ chốt open-ketoan docs/nghiep-vu/23).
 *
 * Chuyển từ tgs_pos (TGS_POS_Ledger_Mirror) sang cầu nối vì cầu nối có ở MỌI shop: phiếu bán / trả kế toán lập (module phieu-ban)
 * về được website shop KHÔNG cài / không bật plugin máy bán POS. Khác bản của tgs_pos: không có điều kiện "shop bật POS React",
 * không mở REST riêng (cửa nhận là TGS_Acc_Ketoan_Events), thêm cancel() (huỷ theo bảng trạng thái, không cần gửi lại nội dung).
 * Dùng CHUNG bảng trạng thái {prefix}tgs_pos_mirror với tgs_pos (cùng khoá pg:<id>) để hai bên không ghi đè nhau.
 *
 * Thân một chứng từ:
 *   { blog_id, entity, id (<uuid>), version, deleted, rows: { ledger: [{ role, local_ledger_type, …cột local_ledger, meta?, parent_role?,
 *     parent_ledger_id?, item_ids_from? }], items: [{ ledger_role, …cột local_ledger_item }], person: {…cột local_ledger_person} | { guest: true } } }
 *   - parent_role: phiếu cha trong cùng chứng từ (phiếu thu → phiếu bán); parent_ledger_id: phiếu cha ĐÃ CÓ ở website (phiếu hoàn →
 *     phiếu bán gốc, tra bằng ledger_id_of — 15/11/2026, bản sao phiếu trả).
 *   - ghi đè TẠI CHỖ theo khoá pg:<id> + vai trò (giữ local_ledger_id — hoá đơn Viettel gắn theo id này);
 *   - version ≤ bản đã ghi → bỏ qua (gửi lại / gửi sai thứ tự);
 *   - vai trò lần trước có, lần này không → xoá mềm;
 *   - local_ledger_source_id = 'pg:<id>' → cầu nối không gửi ngược phiếu này sang kế toán (TGS_Acc_Source::not_mirror_sql),
 *     cờ is_mirroring() chặn bộ hứng tức thời trong lúc ghi.
 *
 * @package tgs_accounting_bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Acc_Ledger_Mirror
{
    /** order / return: máy bán; manual_sale / manual_return: phiếu kế toán lập (module phieu-ban). */
    const ENTITIES = ['order', 'return', 'payment', 'cash_entry', 'stock_doc', 'fallback_order', 'manual_sale', 'manual_return'];

    /** Đang ghi bản sao: bộ hứng tức thời của cầu nối bỏ qua. */
    private static $mirroring = false;

    public static function is_mirroring()
    {
        return self::$mirroring;
    }

    /**
     * Ghi một chứng từ. Trả {id, version, skipped, ledger_ids: {role: id}, person_id} hoặc WP_Error.
     */
    public static function apply(array $doc)
    {
        $blog_id = (int) ($doc['blog_id'] ?? 0);
        $pg_id = (string) ($doc['id'] ?? '');
        $entity = (string) ($doc['entity'] ?? '');
        $version = (int) ($doc['version'] ?? 0);
        if ($blog_id <= 0 || !preg_match('/^[0-9a-f-]{8,64}$/i', $pg_id) || !in_array($entity, self::ENTITIES, true) || $version <= 0) {
            return new WP_Error('tgs_mirror_invalid', 'Thiếu blog_id / id / entity / version hợp lệ', ['status' => 422]);
        }
        $ledgers = array_values(array_filter((array) ($doc['rows']['ledger'] ?? []), 'is_array'));
        $roles = array_map(function ($l) { return (string) ($l['role'] ?? ''); }, $ledgers);
        if (!$ledgers || in_array('', $roles, true) || count(array_unique($roles)) !== count($roles)) {
            return new WP_Error('tgs_mirror_invalid', 'rows.ledger phải có ít nhất một phiếu, mỗi phiếu một role khác nhau', ['status' => 422]);
        }
        if (is_multisite() && !get_site($blog_id)) {
            return new WP_Error('tgs_mirror_no_blog', 'Không có site ' . $blog_id, ['status' => 404]);
        }
        return self::in_blog($blog_id, function () use ($pg_id, $entity, $version, $doc, $ledgers) {
            return self::write($pg_id, $entity, $version, !empty($doc['deleted']), $ledgers, (array) ($doc['rows']['items'] ?? []), $doc['rows']['person'] ?? null);
        }, $entity, $pg_id);
    }

    /** Huỷ bản sao (xoá mềm mọi phiếu của chứng từ) theo bảng trạng thái. Chưa có bản sao → skipped. */
    public static function cancel($blog_id, $pg_id, $version)
    {
        return self::in_blog((int) $blog_id, function () use ($pg_id, $version) {
            global $wpdb;
            self::ensure_state_table();
            $state = $wpdb->prefix . 'tgs_pos_mirror';
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT role, ledger_id, version, entity FROM {$state} WHERE pg_id = %s FOR UPDATE", $pg_id));
            if (!$rows) {
                return ['id' => $pg_id, 'version' => (int) $version, 'skipped' => true, 'ledger_ids' => [], 'person_id' => null];
            }
            $now = current_time('mysql');
            $ids = [];
            foreach ($rows as $r) {
                $wpdb->update($wpdb->prefix . 'local_ledger', ['is_deleted' => 1, 'deleted_at' => $now, 'updated_at' => $now], ['local_ledger_id' => (int) $r->ledger_id]);
                $ids[$r->role] = (int) $r->ledger_id;
            }
            // Giữ dòng trạng thái (ghi số bản) để lần duyệt lại ghi đè đúng phiếu cũ.
            $wpdb->query($wpdb->prepare("UPDATE {$state} SET version = GREATEST(version, %d), updated_at = %s WHERE pg_id = %s", (int) $version, $now, $pg_id));
            return ['id' => $pg_id, 'version' => (int) $version, 'skipped' => false, 'ledger_ids' => $ids, 'person_id' => null];
        }, 'cancel', $pg_id);
    }

    /** Có bản sao của chứng từ ở site chưa. */
    public static function has_mirror($blog_id, $pg_id)
    {
        global $wpdb;
        $state = $wpdb->get_blog_prefix((int) $blog_id) . 'tgs_pos_mirror';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $state)) !== $state) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$state} WHERE pg_id = %s LIMIT 1", $pg_id));
    }

    /** local_ledger_id của một vai trò trong bản sao (vd phiếu 'sale' của phiếu bán gốc); 0 nếu chưa có / đã xoá mềm. */
    public static function ledger_id_of($blog_id, $pg_id, $role)
    {
        global $wpdb;
        $p = $wpdb->get_blog_prefix((int) $blog_id);
        $state = $p . 'tgs_pos_mirror';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $state)) !== $state) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT s.ledger_id FROM {$state} s JOIN {$p}local_ledger l ON l.local_ledger_id = s.ledger_id WHERE s.pg_id = %s AND s.role = %s AND l.is_deleted = 0",
            $pg_id, $role
        ));
    }

    /** Chạy $fn trong site $blog_id, trong giao dịch, gỡ bộ hứng của cầu nối; bắn tgs_acc_ledger_mirrored khi ghi được. */
    private static function in_blog($blog_id, callable $fn, $entity, $pg_id)
    {
        TGS_Acc_Site::switch_to($blog_id);
        global $wpdb;
        $hook = self::detach_events();
        self::$mirroring = true;
        $wpdb->query('START TRANSACTION');
        try {
            $result = $fn();
            $wpdb->query(is_wp_error($result) ? 'ROLLBACK' : 'COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $result = new WP_Error('tgs_mirror_failed', $e->getMessage(), ['status' => 500]);
        } finally {
            self::$mirroring = false;
            self::attach_events($hook);
        }
        if (!is_wp_error($result) && empty($result['skipped'])) {
            do_action('tgs_acc_ledger_mirrored', $entity, $result['ledger_ids'], $pg_id, $blog_id);
        }
        TGS_Acc_Site::restore();
        return $result;
    }

    private static function write($pg_id, $entity, $version, $deleted, array $ledgers, array $items, $person)
    {
        global $wpdb;
        self::ensure_state_table();
        $state = $wpdb->prefix . 'tgs_pos_mirror';
        $known = [];
        $stored_version = 0;
        $stored_fallback = false;
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT role, ledger_id, version, entity FROM {$state} WHERE pg_id = %s FOR UPDATE", $pg_id)) as $r) {
            $known[$r->role] = (int) $r->ledger_id;
            $stored_version = max($stored_version, (int) $r->version);
            if ((string) $r->entity === 'fallback_order') $stored_fallback = true;
        }
        if ($stored_fallback && $entity !== 'fallback_order') $stored_version = 0;
        if ($entity === 'fallback_order' && $known && !$stored_fallback) {
            return ['id' => $pg_id, 'version' => $stored_version, 'skipped' => true, 'ledger_ids' => $known, 'person_id' => null];
        }
        if ($entity === 'fallback_order') $stored_version = 0;
        if ($known && $version <= $stored_version) {
            return ['id' => $pg_id, 'version' => $stored_version, 'skipped' => true, 'ledger_ids' => $known, 'person_id' => null];
        }

        $now = current_time('mysql');
        $person_id = is_array($person) ? self::upsert_person($person, $now) : null;
        if (is_wp_error($person_id)) return $person_id;

        $ledger_t = $wpdb->prefix . 'local_ledger';
        $item_t = $wpdb->prefix . 'local_ledger_item';
        $ledger_cols = self::columns($ledger_t, ['local_ledger_id', 'local_ledger_item_id', 'local_ledger_meta_id', 'local_ledger_parent_id', 'local_ledger_source_id']);
        $item_cols = self::columns($item_t, ['local_ledger_item_id', 'local_ledger_id']);

        usort($ledgers, function ($a, $b) {
            return (int) !empty($a['parent_role']) - (int) !empty($b['parent_role']);
        });
        $ids = [];
        foreach ($ledgers as $l) {
            $role = (string) $l['role'];
            $row = array_intersect_key($l, array_flip($ledger_cols));
            $row['local_ledger_source_id'] = 'pg:' . $pg_id;
            if (!isset($row['local_ledger_source'])) $row['local_ledger_source'] = 1;
            if ($person_id && !isset($row['local_ledger_person_id'])) $row['local_ledger_person_id'] = $person_id;
            if (!empty($l['parent_ledger_id'])) {
                $row['local_ledger_parent_id'] = (int) $l['parent_ledger_id'];
            }
            if (!empty($l['parent_role'])) {
                if (!isset($ids[$l['parent_role']])) {
                    return new WP_Error('tgs_mirror_invalid', "Phiếu {$role}: không có phiếu cha {$l['parent_role']} trong chứng từ", ['status' => 422]);
                }
                $row['local_ledger_parent_id'] = $ids[$l['parent_role']];
            }
            $row['is_deleted'] = $deleted ? 1 : 0;
            $row['deleted_at'] = $deleted ? $now : null;
            $row['updated_at'] = $now;
            $existing = $known[$role] ?? 0;
            if (array_key_exists('meta', $l)) {
                $meta_id = $existing ? (int) $wpdb->get_var($wpdb->prepare("SELECT local_ledger_meta_id FROM {$ledger_t} WHERE local_ledger_id = %d", $existing)) : 0;
                $meta_id = self::upsert_meta($meta_id, $l['meta'], $now, $row['user_id'] ?? null);
                if (is_wp_error($meta_id)) return $meta_id;
                $row['local_ledger_meta_id'] = $meta_id;
            }
            $row = self::encode_json($row);
            if ($existing && $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$ledger_t} WHERE local_ledger_id = %d", $existing))) {
                if ($wpdb->update($ledger_t, $row, ['local_ledger_id' => $existing]) === false) {
                    return self::db_error("Sửa phiếu {$role}");
                }
                $ids[$role] = $existing;
            } else {
                $row['created_at'] = $row['created_at'] ?? $now;
                if (!$wpdb->insert($ledger_t, $row)) return self::db_error("Ghi phiếu {$role}");
                $ids[$role] = (int) $wpdb->insert_id;
            }
        }

        $item_ids = [];
        $by_role = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $role = (string) ($it['ledger_role'] ?? '');
            if (!isset($ids[$role])) {
                return new WP_Error('tgs_mirror_invalid', "Dòng hàng gắn vai trò {$role} không có trong rows.ledger", ['status' => 422]);
            }
            $by_role[$role][] = $it;
        }
        // [2026-11-27 05:00 PM Võ Hoàng] Ghi lại chứng từ (kế toán tăng version, vd lúc xuất hoá đơn) GIỮ id dòng hàng: ghép dòng
        // mới với dòng cũ cùng sản phẩm theo thứ tự, sửa tại chỗ; chỉ thêm / xoá phần dư. Xoá hết rồi chèn lại làm đổi id →
        // bản chụp dòng của hoá đơn Viettel (ledger_item_id) và liên kết dòng hoàn → dòng bán (source_ledger_item_id) trỏ vào
        // dòng đã mất, phiếu điều chỉnh hoá đơn bị bỏ qua là "không nằm trên hoá đơn" (đóng góp)
        foreach ($ids as $role => $ledger_id) {
            $pool = [];
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT local_ledger_item_id, local_product_name_id FROM {$item_t} WHERE local_ledger_id = %d ORDER BY local_ledger_item_id",
                $ledger_id
            )) as $old) {
                $pool[(int) $old->local_product_name_id][] = (int) $old->local_ledger_item_id;
            }
            foreach ($by_role[$role] ?? [] as $it) {
                $row = self::encode_json(array_intersect_key($it, array_flip($item_cols)));
                $row['local_ledger_id'] = $ledger_id;
                $row['updated_at'] = $now;
                $product = (int) ($row['local_product_name_id'] ?? 0);
                $reuse = !empty($pool[$product]) ? array_shift($pool[$product]) : 0;
                if ($reuse) {
                    unset($row['created_at']);
                    if ($wpdb->update($item_t, $row, ['local_ledger_item_id' => $reuse]) === false) return self::db_error("Sửa dòng hàng phiếu {$role}");
                    $item_ids[$role][] = $reuse;
                } else {
                    $row['created_at'] = $row['created_at'] ?? $now;
                    if (!$wpdb->insert($item_t, $row)) return self::db_error("Ghi dòng hàng phiếu {$role}");
                    $item_ids[$role][] = (int) $wpdb->insert_id;
                }
            }
            foreach ($pool as $left) {
                foreach ($left as $gone) {
                    $wpdb->delete($item_t, ['local_ledger_item_id' => $gone]);
                }
            }
        }
        foreach ($ledgers as $l) {
            $role = (string) $l['role'];
            $list = $item_ids[$role] ?? (!empty($l['item_ids_from']) ? ($item_ids[$l['item_ids_from']] ?? []) : []);
            $wpdb->update($ledger_t, ['local_ledger_item_id' => wp_json_encode($list)], ['local_ledger_id' => $ids[$role]]);
        }
        if (!$deleted && !empty($ids['return']) && !empty($item_ids['return'])) {
            self::link_return_sources((int) $ids['return'], $item_ids['return']);
        }

        foreach (array_diff_key($known, $ids) as $role => $ledger_id) {
            $wpdb->update($ledger_t, ['is_deleted' => 1, 'deleted_at' => $now, 'updated_at' => $now], ['local_ledger_id' => $ledger_id]);
            $wpdb->delete($item_t, ['local_ledger_id' => $ledger_id]);
            $wpdb->delete($state, ['pg_id' => $pg_id, 'role' => $role]);
        }
        foreach ($ids as $role => $ledger_id) {
            $wpdb->replace($state, ['pg_id' => $pg_id, 'role' => $role, 'ledger_id' => $ledger_id, 'entity' => $entity, 'version' => $version, 'updated_at' => $now]);
        }
        return ['id' => $pg_id, 'version' => $version, 'skipped' => false, 'ledger_ids' => $ids, 'person_id' => $person_id];
    }

    /** Khách theo SĐT trong site: có thì sửa, chưa có thì thêm. `{guest: true}` = khách lẻ chung (CUS-GUEST) như POS cũ. */
    // [2026-11-27 05:00 PM Võ Hoàng] Chuyển từ TGS_POS_Ledger_Mirror::link_return_sources (bản tgs_pos có, bản cầu nối sót) (đóng góp)
    /**
     * Gắn mỗi dòng phiếu hoàn với dòng của đơn bán gốc (`source_ledger_item_id` trong meta), như dịch vụ hoàn của POS cũ vẫn
     * ghi. Plugin Viettel đọc khoá này để biết dòng hoàn nằm ở dòng nào trên hoá đơn gốc (thiếu thì coi như "không nằm trên
     * hoá đơn" và bỏ qua điều chỉnh thuế); POS cũ đọc nó để tính số đã hoàn của từng dòng.
     * Kế toán chỉ biết id dòng của nó nên khớp ở đây: cùng sản phẩm, cùng SKU, cùng cờ quà; nhiều dòng trùng thì ưu tiên dòng
     * còn đủ số lượng và đơn giá gần nhất. Dòng đã có khoá trỏ vào dòng còn sống của đơn gốc thì giữ nguyên.
     * @return int số dòng vừa gắn
     */
    public static function link_return_sources($return_id, array $return_item_ids)
    {
        global $wpdb;
        $ledger_t = $wpdb->prefix . 'local_ledger';
        $item_t = $wpdb->prefix . 'local_ledger_item';
        $parent = (int) $wpdb->get_var($wpdb->prepare("SELECT local_ledger_parent_id FROM {$ledger_t} WHERE local_ledger_id = %d", $return_id));
        $return_item_ids = array_values(array_filter(array_map('intval', $return_item_ids)));
        if ($parent <= 0 || !$return_item_ids) return 0;
        $sale_ids = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT local_ledger_item_id FROM {$ledger_t} WHERE local_ledger_id = %d", $parent)), true);
        $sale_ids = is_array($sale_ids) ? array_values(array_filter(array_map('intval', $sale_ids))) : [];
        if (!$sale_ids) return 0;

        $read = function (array $ids) use ($wpdb, $item_t) {
            $ph = implode(',', array_fill(0, count($ids), '%d'));
            return (array) $wpdb->get_results($wpdb->prepare(
                "SELECT local_ledger_item_id, local_product_name_id, quantity, price, local_ledger_item_meta FROM {$item_t} WHERE local_ledger_item_id IN ({$ph}) ORDER BY local_ledger_item_id",
                ...$ids
            ), ARRAY_A);
        };
        $meta_of = function ($row) {
            $m = json_decode((string) ($row['local_ledger_item_meta'] ?? ''), true);
            return is_array($m) ? $m : [];
        };
        $sales = [];
        foreach ($read($sale_ids) as $s) {
            $m = $meta_of($s);
            $sales[] = ['id' => (int) $s['local_ledger_item_id'], 'product' => (int) $s['local_product_name_id'], 'sku' => (string) ($m['sku'] ?? ''),
                'gift' => !empty($m['is_gift']), 'price' => (float) $s['price'], 'left' => (float) $s['quantity']];
        }

        $linked = 0;
        foreach ($read($return_item_ids) as $r) {
            $m = $meta_of($r);
            if (!empty($m['source_ledger_item_id']) && in_array((int) $m['source_ledger_item_id'], $sale_ids, true)) continue;
            $qty = (float) $r['quantity'];
            $best = null;
            foreach ($sales as $k => $s) {
                if ($s['product'] !== (int) $r['local_product_name_id'] || $s['gift'] !== !empty($m['is_gift'])) continue;
                if ($s['sku'] !== '' && isset($m['sku']) && (string) $m['sku'] !== $s['sku']) continue;
                $score = [$s['left'] + 1e-9 >= $qty ? 0 : 1, abs($s['price'] - (float) $r['price'])];
                if ($best === null || $score < $best[1]) $best = [$k, $score];
            }
            if ($best === null) continue;
            $k = $best[0];
            $sales[$k]['left'] -= $qty;
            $m['source_ledger_item_id'] = $sales[$k]['id'];
            $m['source_export_ledger_id'] = $parent;
            $wpdb->update($item_t, ['local_ledger_item_meta' => wp_json_encode($m, JSON_UNESCAPED_UNICODE)], ['local_ledger_item_id' => (int) $r['local_ledger_item_id']]);
            $linked++;
        }
        return $linked;
    }

    private static function upsert_person(array $person, $now)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'local_ledger_person';
        if (!empty($person['guest'])) {
            $id = (int) $wpdb->get_var("SELECT local_ledger_person_id FROM {$t} WHERE local_ledger_person_code = 'CUS-GUEST' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY local_ledger_person_id LIMIT 1");
            if ($id) return $id;
            $guest = ['local_ledger_person_code' => 'CUS-GUEST', 'local_ledger_person_name' => 'Khách lẻ', 'local_ledger_person_phone' => '',
                'local_ledger_person_type' => 2, 'local_ledger_person_status' => 1, 'user_id' => (int) ($person['user_id'] ?? 0),
                'is_deleted' => 0, 'created_at' => $now, 'updated_at' => $now];
            if (!$wpdb->insert($t, $guest)) return self::db_error('Ghi khách lẻ');
            return (int) $wpdb->insert_id;
        }
        $row = self::encode_json(array_intersect_key($person, array_flip(self::columns($t, ['local_ledger_person_id']))));
        $phone = preg_replace('/\D+/', '', (string) ($row['local_ledger_person_phone'] ?? ''));
        if (strlen($phone) === 11 && strpos($phone, '84') === 0) $phone = '0' . substr($phone, 2);
        if ($phone === '') {
            return new WP_Error('tgs_mirror_invalid', 'rows.person thiếu local_ledger_person_phone', ['status' => 422]);
        }
        $row['local_ledger_person_phone'] = $phone;
        $row['updated_at'] = $now;
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT local_ledger_person_id FROM {$t} WHERE local_ledger_person_phone = %s AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY local_ledger_person_id LIMIT 1", $phone));
        if ($id) {
            if ($wpdb->update($t, $row, ['local_ledger_person_id' => $id]) === false) return self::db_error('Sửa khách');
            return $id;
        }
        $row['created_at'] = $now;
        if (!$wpdb->insert($t, $row)) return self::db_error('Ghi khách');
        return (int) $wpdb->insert_id;
    }

    private static function upsert_meta($meta_id, $value, $now, $user_id = null)
    {
        global $wpdb;
        $t = $wpdb->prefix . 'local_ledger_meta';
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($meta_id > 0 && $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$t} WHERE local_ledger_meta_id = %d", $meta_id))) {
            $wpdb->update($t, ['local_ledger_meta_value' => $json, 'updated_at' => $now], ['local_ledger_meta_id' => $meta_id]);
            return $meta_id;
        }
        $row = ['local_ledger_meta_value' => $json, 'is_deleted' => 0, 'created_at' => $now, 'updated_at' => $now];
        if ($user_id !== null) $row['user_id'] = (int) $user_id;
        if (!$wpdb->insert($t, $row)) return self::db_error('Ghi meta');
        return (int) $wpdb->insert_id;
    }

    private static function encode_json(array $row)
    {
        foreach ($row as $k => $v) {
            if (is_array($v)) $row[$k] = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return $row;
    }

    private static function columns($table, array $except)
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            global $wpdb;
            $cache[$table] = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        }
        return array_values(array_diff($cache[$table], $except));
    }

    /** Bảng nhớ chứng từ kế toán ↔ phiếu website — cùng bảng với tgs_pos (tgs_pos_mirror). */
    public static function ensure_state_table()
    {
        global $wpdb;
        $t = $wpdb->prefix . 'tgs_pos_mirror';
        if (get_option('tgs_pos_mirror_table') === '1' && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
            return;
        }
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t} (
            pg_id varchar(64) NOT NULL,
            role varchar(32) NOT NULL,
            ledger_id bigint unsigned NOT NULL,
            entity varchar(20) NOT NULL,
            version int unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (pg_id, role),
            KEY ledger_id (ledger_id)
        ) {$wpdb->get_charset_collate()}");
        update_option('tgs_pos_mirror_table', '1', true);
    }

    private static function db_error($what)
    {
        global $wpdb;
        return new WP_Error('tgs_mirror_db', $what . ': ' . ($wpdb->last_error ?: 'lỗi DB'), ['status' => 500]);
    }

    /** Gỡ bộ hứng `query` của cầu nối trong lúc ghi bản sao (xử lý nốt INSERT đang chờ trước khi gỡ). */
    private static function detach_events()
    {
        $cb = ['TGS_Acc_Events', 'on_query'];
        if (!class_exists('TGS_Acc_Events') || has_filter('query', $cb) === false) {
            return null;
        }
        global $wpdb;
        $wpdb->query('SELECT 1');
        $priority = has_filter('query', $cb);
        remove_filter('query', $cb, $priority);
        return $priority;
    }

    private static function attach_events($priority)
    {
        if ($priority !== null && $priority !== false) {
            add_filter('query', ['TGS_Acc_Events', 'on_query'], $priority);
        }
    }
}
