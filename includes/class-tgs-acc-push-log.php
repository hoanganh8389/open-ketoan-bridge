<?php
/**
 * Nhật ký gửi chứng từ sang kế toán — bảng GLOBAL {base_prefix}tgs_acc_push_log.
 *
 * Mỗi chứng từ một dòng (khoá doc_key): phiên bản đã gửi, mã băm nội dung, trạng thái
 * bên kế toán trả về. Dùng để:
 *   - bỏ qua chứng từ không đổi (cùng mã băm, đã ghi sổ);
 *   - tăng phiên bản khi nội dung đổi;
 *   - phát hiện phiếu đã gửi mà nay không còn → gửi lệnh huỷ.
 *
 * Mỗi BÊN NHẬN một nhật ký riêng: sổ nội bộ dùng bảng tgs_acc_push_log; sổ thuế (cổng chờ gọi với consumer=tax) dùng
 * tgs_acc_push_log_tax — báo nhận của sổ thuế không làm sổ nội bộ mất chứng từ và ngược lại. Chọn bằng use_consumer().
 *
 * Bảng thứ hai {base_prefix}tgs_acc_push_run: mỗi LƯỢT gửi một dòng (ai, từ đâu, shop, khoảng
 * ngày, tổng kết) — để trang "Cổng kế toán" cho xem lịch sử chạy của nút / lịch / dòng lệnh.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Push_Log
{
    const DB_VERSION = '3';
    const OPTION = 'tgs_acc_push_log_db';

    /** Bên nhận đang phục vụ: '' = sổ nội bộ, 'tax' = sổ thuế. */
    private static $consumer = '';

    public static function use_consumer($consumer)
    {
        self::$consumer = $consumer === 'tax' ? 'tax' : '';
    }

    public static function consumer()
    {
        return self::$consumer;
    }

    public static function table()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'tgs_acc_push_log' . (self::$consumer === 'tax' ? '_tax' : '');
    }

    public static function run_table()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'tgs_acc_push_run';
    }

    /** Tạo bảng nếu chưa có (so option trước, không chạy dbDelta mỗi lần). */
    public static function maybe_install()
    {
        if (get_site_option(self::OPTION) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (['tgs_acc_push_log', 'tgs_acc_push_log_tax'] as $name) {
        $t = $wpdb->base_prefix . $name;
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id BIGINT UNSIGNED NOT NULL,
            doc_key VARCHAR(160) NOT NULL,
            doc_type VARCHAR(32) NOT NULL,
            doc_no VARCHAR(64) NOT NULL DEFAULT '',
            doc_date DATE NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            content_hash CHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT '',
            message TEXT NULL,
            pushed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY doc_key (doc_key),
            KEY blog_date (blog_id, doc_date)
        ) {$charset};");
        }
        $r = self::run_table();
        dbDelta("CREATE TABLE {$r} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id BIGINT UNSIGNED NOT NULL,
            date_from DATE NULL,
            date_to DATE NULL,
            origin VARCHAR(16) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            built INT UNSIGNED NOT NULL DEFAULT 0,
            sent INT UNSIGNED NOT NULL DEFAULT 0,
            posted INT UNSIGNED NOT NULL DEFAULT 0,
            unchanged INT UNSIGNED NOT NULL DEFAULT 0,
            skipped INT UNSIGNED NOT NULL DEFAULT 0,
            errors INT UNSIGNED NOT NULL DEFAULT 0,
            cancelled INT UNSIGNED NOT NULL DEFAULT 0,
            ms INT UNSIGNED NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY created (created_at),
            KEY blog (blog_id, created_at)
        ) {$charset};");
        update_site_option(self::OPTION, self::DB_VERSION);
    }

    /** @return array<string, array> doc_key => dòng nhật ký */
    public static function find_many(array $keys)
    {
        global $wpdb;
        if (!$keys) {
            return [];
        }
        $t = self::table();
        $ph = implode(',', array_fill(0, count($keys), '%s'));
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE doc_key IN ({$ph})", $keys), ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['doc_key']] = $r;
        }
        return $out;
    }

    /** Chứng từ đã gửi của shop trong khoảng ngày, theo loại (để phát hiện phiếu đã bị xoá). */
    public static function keys_in_range($blog_id, $from, $to, $doc_type)
    {
        global $wpdb;
        $t = self::table();
        return (array) $wpdb->get_col($wpdb->prepare(
            "SELECT doc_key FROM {$t} WHERE blog_id = %d AND doc_type = %s AND doc_date BETWEEN %s AND %s AND status IN ('posted','pending','remote_error','error','gate')",
            (int) $blog_id, $doc_type, $from, $to
        ));
    }

    /**
     * Chứng từ cần báo huỷ: đã gửi (ERP đã nhận / đang ở cổng / lỗi) mà nay không còn ở shop — phiếu ngày trong khoảng
     * không còn trong danh sách dựng, hoặc phiếu bị xoá trong khoảng (TGS_Acc_Source::deleted_keys, mọi ngày phiếu).
     */
    public static function cancel_keys($blog_id, $from, $to, array $present_keys, array $deleted_keys = [])
    {
        $present = array_flip($present_keys);
        $out = [];
        foreach (['sale', 'sale_return', 'customer_payment', 'purchase', 'purchase_return', 'transfer', 'stock_adjustment', 'cash_transfer'] as $type) {
            foreach (self::keys_in_range($blog_id, $from, $to, $type) as $key) {
                if (!isset($present[$key])) {
                    $out[$key] = true;
                }
            }
        }
        foreach (self::find_many(array_values(array_diff($deleted_keys, $present_keys))) as $key => $r) {
            if (in_array($r['status'], ['posted', 'pending', 'remote_error', 'error', 'gate'], true)) {
                $out[$key] = true;
            }
        }
        return array_keys($out);
    }

    // [2026-11-21 08:45 PM Johnny Chu - Chu Hoàng Anh] S002-9, S002-10 — trạng thái 'pending' (kế toán đã nhận, chờ định khoản) và 'remote_error' (kế toán định khoản lỗi) tách khỏi 'posted'.
    /**
     * Trạng thái "kế toán đã có đúng phiên bản này": không gửi lại khi nội dung không đổi.
     *   posted       — đã ghi sổ;
     *   pending      — đã nhận, chờ định khoản (ACC14) — kéo trạng thái sẽ chuyển tiếp;
     *   remote_error — kế toán nhận nhưng định khoản lỗi: gửi lại cùng nội dung chỉ được "unchanged", nên KHÔNG tự gửi lại;
     *                  sửa ở kế toán hoặc sửa phiếu ở shop (nội dung đổi → phiên bản mới).
     */
    const DELIVERED = ['posted', 'pending', 'remote_error'];

    public static function is_delivered($status)
    {
        return in_array((string) $status, self::DELIVERED, true);
    }

    /** Chứng từ đang chờ kế toán định khoản của một blog (cũ nhất trước). */
    public static function pending_rows($blog_id, $limit = 50)
    {
        global $wpdb;
        $t = self::table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT doc_key, doc_type, doc_no, doc_date, version, content_hash FROM {$t} WHERE blog_id = %d AND status = 'pending' ORDER BY pushed_at ASC, id ASC LIMIT %d",
            (int) $blog_id, max(1, (int) $limit)
        ), ARRAY_A);
    }

    /** Các blog còn chứng từ chờ định khoản. */
    public static function blogs_with_pending()
    {
        global $wpdb;
        $t = self::table();
        return array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT blog_id FROM {$t} WHERE status = 'pending'"));
    }

    /** Đổi trạng thái theo kết quả kéo về; chỉ đổi dòng đúng phiên bản đang chờ (không đè phiên bản mới hơn). */
    public static function set_status($doc_key, $version, $status, $message = '')
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = %s, message = %s, pushed_at = %s WHERE doc_key = %s AND version = %d AND status = 'pending'",
            $status, $message, current_time('mysql'), $doc_key, (int) $version
        ));
    }

    public static function save($blog_id, array $doc, $version, $hash, $status, $message = '')
    {
        global $wpdb;
        $t = self::table();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (blog_id, doc_key, doc_type, doc_no, doc_date, version, content_hash, status, message, pushed_at)
             VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE version = VALUES(version), content_hash = VALUES(content_hash),
                 status = VALUES(status), message = VALUES(message), pushed_at = VALUES(pushed_at),
                 doc_no = VALUES(doc_no), doc_date = VALUES(doc_date)",
            (int) $blog_id, $doc['docKey'], $doc['docType'], $doc['docNo'], $doc['docDate'],
            (int) $version, $hash, $status, $message, current_time('mysql')
        ));
    }

    public static function mark($doc_key, $status, $message = '')
    {
        global $wpdb;
        $wpdb->update(self::table(), ['status' => $status, 'message' => $message, 'pushed_at' => current_time('mysql')], ['doc_key' => $doc_key]);
    }

    /** Ghi một lượt gửi (tổng kết của TGS_Acc_Pusher::push). */
    public static function save_run($blog_id, $from, $to, array $sum, $origin, $ms)
    {
        global $wpdb;
        $errs = array_merge((array) ($sum['failed_build'] ?? []), (array) ($sum['errors'] ?? []));
        $wpdb->insert(self::run_table(), [
            'blog_id'   => (int) $blog_id,
            'date_from' => $from,
            'date_to'   => $to,
            'origin'    => substr((string) $origin, 0, 16),
            'user_id'   => (int) get_current_user_id(),
            'built'     => (int) ($sum['built'] ?? 0),
            'sent'      => (int) ($sum['to_send'] ?? 0),
            'posted'    => (int) ($sum['posted'] ?? 0),
            'unchanged' => (int) ($sum['unchanged'] ?? 0),
            'skipped'   => (int) ($sum['skipped'] ?? 0),
            'errors'    => (int) ($sum['error'] ?? 0) + count((array) ($sum['failed_build'] ?? [])),
            'cancelled' => (int) ($sum['cancelled'] ?? 0),
            'ms'        => (int) $ms,
            'note'      => $errs ? mb_substr(implode("
", array_slice($errs, 0, 30)), 0, 8000) : null,
            'created_at'=> current_time('mysql'),
        ]);
        // Giữ 5000 lượt gần nhất là đủ để tra cứu.
        $max = (int) $wpdb->get_var("SELECT MAX(id) FROM " . self::run_table());
        if ($max > 5000 && $max % 200 === 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::run_table() . " WHERE id <= %d", $max - 5000));
        }
    }

    /** Lượt gửi gần nhất, lọc theo shop. */
    public static function runs($blog_id = 0, $limit = 100)
    {
        global $wpdb;
        $t = self::run_table();
        $where = (int) $blog_id > 0 ? $wpdb->prepare('WHERE blog_id = %d', (int) $blog_id) : '';
        return (array) $wpdb->get_results("SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT " . max(1, min(500, (int) $limit)), ARRAY_A);
    }

    /**
     * Tra nhật ký chứng từ. $f: blog, status, type, from, to, q (số phiếu / khoá), page, per.
     * @return array{rows: array[], total: int}
     */
    public static function search(array $f)
    {
        global $wpdb;
        $t = self::table();
        $w = ['1=1'];
        $args = [];
        if (!empty($f['blog'])) { $w[] = 'blog_id = %d'; $args[] = (int) $f['blog']; }
        if (!empty($f['status'])) { $w[] = 'status = %s'; $args[] = (string) $f['status']; }
        if (!empty($f['type'])) { $w[] = 'doc_type = %s'; $args[] = (string) $f['type']; }
        if (!empty($f['from'])) { $w[] = 'doc_date >= %s'; $args[] = (string) $f['from']; }
        if (!empty($f['to'])) { $w[] = 'doc_date <= %s'; $args[] = (string) $f['to']; }
        if (!empty($f['q'])) {
            $like = '%' . $wpdb->esc_like((string) $f['q']) . '%';
            $w[] = '(doc_no LIKE %s OR doc_key LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $where = implode(' AND ', $w);
        $per = max(10, min(500, (int) ($f['per'] ?? 100)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $sql_where = $args ? $wpdb->prepare($where, $args) : $where;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$sql_where}");
        $rows = (array) $wpdb->get_results("SELECT * FROM {$t} WHERE {$sql_where} ORDER BY pushed_at DESC, id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), ARRAY_A);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Đếm theo shop × trạng thái (bảng tổng quan). */
    public static function stats()
    {
        global $wpdb;
        $t = self::table();
        return (array) $wpdb->get_results(
            "SELECT blog_id, status, COUNT(*) AS n, MAX(pushed_at) AS last_at, MAX(doc_date) AS last_doc
               FROM {$t} GROUP BY blog_id, status",
            ARRAY_A
        );
    }
}
