<?php
/**
 * Màn "Khoá theo shop" (B001-C4): mỗi blog một dòng — nguồn khoá, khoá che, tenant, trạng thái kết nối. KHÔNG có khoá thật.
 *
 *   TGS_Acc_Blog_Keys::rows()          mọi blog của mạng (một site: một dòng), đọc câu trả lời đang đệm, không hỏi mạng
 *   TGS_Acc_Blog_Keys::row($blog)      một dòng
 *   TGS_Acc_Blog_Keys::check($blog)    hỏi lại ngay (TGS_Acc_Connection::connection fresh) rồi trả dòng
 *   TGS_Acc_Blog_Keys::save($blog,$in) lưu URL / khoá của blog (TGS_Acc_Config::save_blog) rồi hỏi lại
 *   TGS_Acc_Blog_Keys::csv($rows)      CSV không có khoá
 *
 * Trạng thái: ok 🟢 đạt cả bốn điều kiện · warn 🟡 đạt nhưng có điều cần để ý (khoá chung dồn nhiều site một tenant) ·
 *             none ⚪ chưa có khoá, hoặc có khoá mà chưa kiểm · bad 🔴 một điều kiện hỏng.
 * Luật vai trò (P-0 ý 4): chỉ để xem và cấu hình; không đụng quầy bán, không ghi kế toán. Tenant là điều kế toán BÁO (P-0 ý 3).
 *
 * @package open-ketoan-bridge
 */
// [2026-11-25 10:05 AM Johnny Chu - Chu Hoàng Anh] SW2-1 / B001-C4 — màn Khoá theo shop.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Blog_Keys
{
    const STATES = ['ok' => '🟢', 'warn' => '🟡', 'none' => '⚪', 'bad' => '🔴'];

    /** @return array<int,array> */
    public static function rows()
    {
        $out = [];
        foreach (TGS_Acc_Site::blogs() as $b) {
            $out[] = self::row((int) $b);
        }
        return $out;
    }

    public static function row($blog_id, $info = null)
    {
        $blog_id = (int) $blog_id;
        $d = TGS_Acc_Config::for_display_blog($blog_id);
        $info = $info ?: TGS_Acc_Connection::cached($blog_id);
        list($state, $text) = self::state_of($d['key_mode'], $info);
        $code = '';
        if (is_multisite()) {
            $site = get_site($blog_id);
            $code = $site && isset($site->tgs_site_code) ? (string) $site->tgs_site_code : '';
        }
        return [
            'blog'       => $blog_id,
            'code'       => $code !== '' ? $code : '#' . $blog_id,
            'name'       => TGS_Acc_Site::name($blog_id),
            'key_mode'   => $d['key_mode'],
            'key_masked' => $d['key_masked'],
            'key_locked' => (bool) $d['key_locked'],
            'url'        => $d['url'],
            'url_source' => $d['url_source'],
            'tenant'     => $info ? (string) $info['tenant'] : '',
            'state'      => $state,
            'icon'       => self::STATES[$state],
            'text'       => $text,
            'checked_at' => $info ? (string) $info['checked_at'] : '',
            'conditions' => $info ? $info['conditions'] : [],
        ];
    }

    public static function check($blog_id)
    {
        $info = TGS_Acc_Connection::connection((int) $blog_id, true);
        return self::row($blog_id, $info);
    }

    /** @return array|WP_Error dòng sau khi lưu và hỏi lại */
    public static function save($blog_id, array $in)
    {
        $r = TGS_Acc_Config::save_blog((int) $blog_id, array_intersect_key($in, array_flip(['url', 'key'])));
        if (is_wp_error($r)) {
            return $r;
        }
        return self::check($blog_id);
    }

    /** @return array{0:string,1:string} [trạng thái, lời giải thích ngắn] */
    public static function state_of($key_mode, $info)
    {
        if ($key_mode === 'none') {
            return ['none', 'Chưa có khoá'];
        }
        if (!$info) {
            return ['none', 'Chưa kiểm'];
        }
        if (!empty($info['ok'])) {
            $note = (string) ($info['conditions']['unique']['note'] ?? '');
            return $note !== '' ? ['warn', $note] : ['ok', 'Đã nối'];
        }
        return ['bad', (string) ($info['error'] ?: 'Chưa nối được')];
    }

    /** CSV (UTF-8 có BOM) — không có khoá, chỉ phần che. */
    public static function csv(array $rows)
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['blog', 'ma', 'ten', 'nguon_khoa', 'khoa_che', 'dia_chi', 'tenant', 'trang_thai', 'ghi_chu', 'kiem_luc']);
        foreach ($rows as $r) {
            fputcsv($f, [$r['blog'], $r['code'], $r['name'], $r['key_mode'], $r['key_masked'], $r['url'], $r['tenant'], $r['state'], $r['text'], $r['checked_at']]);
        }
        rewind($f);
        $s = (string) stream_get_contents($f);
        fclose($f);
        return $s;
    }
}
