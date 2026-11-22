<?php
/**
 * Trace chiều bán của MỘT phiếu: sổ POS → sự kiện → hàng chờ → chứng từ dựng ra (trước / sau ánh xạ mã) → nhật ký gửi →
 * trạng thái ở kế toán. Mockup open-setup docs/chuong-trinh/OKT/mockups/Trace.dc.html (màn 3), đặc tả 70 §1.
 *
 * Chỉ ĐỌC: sổ POS (I-0), tệp sự kiện, nhật ký gửi; dựng chứng từ bằng đúng đường gửi thật (build_refs) nhưng không gửi;
 * hỏi kế toán (GET documents?search=docKey) chỉ khi được yêu cầu, bằng khoá của blog.
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-22 03:10 PM Johnny Chu - Chu Hoàng Anh] OKT-TRACE — trace dòng chảy chiều bán của một phiếu (chỉ đọc).

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Trace
{
    const TYPE_NAME = [1 => 'nhập mua', 2 => 'xuất kho', 7 => 'phiếu thu', 8 => 'phiếu chi trả khách', 10 => 'phiếu bán', 11 => 'phiếu hoàn', 16 => 'trả NCC'];

    /** Tách `btsoft:{blog}:{loại}:{id}` hoặc một id phiếu. @return array{blog:int, id:int, docKey:string}|null */
    public static function parse($blog_id, $ref)
    {
        $ref = trim((string) $ref);
        if (preg_match('/^btsoft:(\d+):[a-z_-]+:(\d+)$/i', $ref, $m)) {
            return ['blog' => (int) $m[1], 'id' => (int) $m[2], 'docKey' => $ref];
        }
        if (preg_match('/^#?(\d+)$/', $ref, $m) && (int) $blog_id > 0) {
            return ['blog' => (int) $blog_id, 'id' => (int) $m[1], 'docKey' => ''];
        }
        return null;
    }

    /**
     * @param array $opts remote (bool: hỏi trạng thái ở kế toán), days (số ngày tệp sự kiện đọc ngược, mặc định 7)
     * @param array $deps (cho test) ledger(callable $blog, $id), build(callable $blog, $ids, $raw), log(callable $keys),
     *                    events(callable $blog, $ids, $days), queue(callable $blog, $ids), client
     */
    public static function run($blog_id, $ref, array $opts = [], array $deps = [])
    {
        $p = self::parse($blog_id, $ref);
        if (!$p) {
            return new WP_Error('bad_ref', 'Nhập mã chứng từ dạng btsoft:{blog}:{loại}:{id} hoặc id phiếu của shop đã chọn');
        }
        $blog = $p['blog'];
        $steps = [];

        // 1. Sổ POS (chỉ đọc): phiếu + phiếu con / cha liên quan
        $ledgers = isset($deps['ledger']) ? call_user_func($deps['ledger'], $blog, $p['id']) : self::ledger_family($blog, $p['id']);
        $root = null;
        foreach ($ledgers as $l) {
            if ((int) $l['id'] === $p['id']) {
                $root = $l;
            }
        }
        if (!$root) {
            $steps[] = self::step('ledger', 'Sổ POS', 'fail', "Không thấy phiếu #{$p['id']} ở sổ của blog {$blog} (đã xoá cứng hoặc sai blog)", ['rows' => []]);
        } else {
            $deleted = !empty($root['is_deleted']);
            $approved = (int) $root['approver_status'] === 1;
            $steps[] = self::step('ledger', 'Sổ POS', $deleted ? 'warn' : ($approved ? 'pass' : 'warn'),
                (self::TYPE_NAME[(int) $root['type']] ?? ('loại ' . $root['type'])) . ' ' . $root['code'] . ' · ' . $root['day'] . ($approved ? ' · đã duyệt' : ' · CHƯA duyệt (chưa có chứng từ)') . ($deleted ? ' · ĐÃ XOÁ (kế toán nhận lệnh huỷ)' : '') . ' · ' . count($ledgers) . ' phiếu liên quan',
                ['rows' => $ledgers]);
        }
        $ids = array_values(array_unique(array_map(function ($l) { return (int) $l['id']; }, $ledgers ?: [['id' => $p['id']]])));

        // 2. Sự kiện ghi lại (tệp JSONL) + kết quả gửi
        $days = max(1, min(90, (int) ($opts['days'] ?? 7)));
        $ev = isset($deps['events']) ? call_user_func($deps['events'], $blog, $ids, $days) : self::events($blog, $ids, $days);
        $last = $ev ? end($ev) : null;
        $steps[] = self::step('events', 'Sự kiện', !$ev ? 'none' : ((($last['result']['res'] ?? '') === 'error') ? 'warn' : 'pass'),
            $ev ? count($ev) . " sự kiện trong {$days} ngày; lần cuối " . $last['t'] . ' (' . $last['op'] . ', ' . $last['src'] . ')' . (isset($last['result']) ? ' → gửi ' . ($last['result']['res'] === 'ok' ? 'được' : 'lỗi: ' . ($last['result']['msg'] ?? '')) : ' → chưa thấy kết quả gửi') : "Không có sự kiện nào trong {$days} ngày (phiếu cũ, hoặc gửi bằng nút Gửi chứng từ)",
            ['rows' => $ev]);

        // 3. Hàng chờ
        $q = isset($deps['queue']) ? call_user_func($deps['queue'], $blog, $ids) : self::queue($blog, $ids);
        $steps[] = self::step('queue', 'Hàng chờ', $q ? 'warn' : 'pass',
            $q ? count($q) . ' nhóm đang chờ gửi lại' . (!empty($q[0]['paused']) ? ' (shop tạm dừng)' : '') . ' — lần thử ' . (int) ($q[0]['n'] ?? 0) . ': ' . ($q[0]['msg'] ?? '') : 'Không nằm trong hàng chờ',
            ['rows' => $q]);

        // 4. Chứng từ dựng ra (đường thật, không gửi) — mã trước / sau ánh xạ
        $build = $deps['build'] ?? function ($b, $ids, $raw) {
            $prev = class_exists('TGS_Acc_Code_Map') ? TGS_Acc_Code_Map::$raw : false;
            if (class_exists('TGS_Acc_Code_Map')) {
                TGS_Acc_Code_Map::$raw = $raw;
            }
            try {
                return TGS_Acc_Pusher::build_refs($b, $ids);
            } finally {
                if (class_exists('TGS_Acc_Code_Map')) {
                    TGS_Acc_Code_Map::$raw = $prev;
                }
            }
        };
        $mapped = (array) (call_user_func($build, $blog, [$p['id']], false)['docs'] ?? []);
        $raw = [];
        foreach ((array) (call_user_func($build, $blog, [$p['id']], true)['docs'] ?? []) as $d) {
            $raw[$d['docKey']] = $d;
        }
        $docs = [];
        foreach ($mapped as $d) {
            $docs[] = self::doc_summary($d, $raw[$d['docKey']] ?? $d);
        }
        $nmap = array_sum(array_map(function ($d) { return count($d['mapped']); }, $docs));
        $steps[] = self::step('build', 'Chứng từ dựng ra', $docs ? 'pass' : 'warn',
            $docs ? count($docs) . ' chứng từ: ' . implode(', ', array_column($docs, 'docKey')) . ($nmap ? " · {$nmap} mã được dịch theo bảng ánh xạ" : '') : 'Không dựng được chứng từ (phiếu chưa duyệt, đã xoá, hoặc chưa bật nguồn phiếu)',
            ['docs' => $docs]);

        // 5. Nhật ký gửi: so mã băm hiện tại với lần gửi cuối
        $keys = array_values(array_unique(array_filter(array_merge(array_column($docs, 'docKey'), [$p['docKey']]))));
        $log = $keys ? (isset($deps['log']) ? call_user_func($deps['log'], $keys) : TGS_Acc_Push_Log::find_many($keys)) : [];
        $lrows = [];
        $lvl = 'pass';
        foreach ($keys as $k) {
            $row = $log[$k] ?? null;
            $cur = null;
            foreach ($docs as $d) {
                if ($d['docKey'] === $k) {
                    $cur = $d;
                }
            }
            if (!$row) {
                $lrows[] = ['docKey' => $k, 'status' => '', 'note' => 'chưa gửi lần nào'];
                $lvl = $lvl === 'fail' ? 'fail' : 'warn'; // đỏ không bị hạ bởi chứng từ sau
                continue;
            }
            $same = $cur && $cur['hash'] === $row['content_hash'];
            $note = $cur ? ($same ? 'nội dung khớp lần gửi cuối — không gửi lại' : 'nội dung ĐÃ ĐỔI — lần gửi tới lên v' . ((int) $row['version'] + 1)) : 'không còn dựng được — lần đối chiếu tới báo huỷ';
            if (in_array($row['status'], ['error', 'remote_error'], true)) {
                $lvl = 'fail';
            } elseif ($row['status'] === 'pending' || !$same) {
                $lvl = $lvl === 'fail' ? 'fail' : 'warn';
            }
            $lrows[] = ['docKey' => $k, 'status' => (string) $row['status'], 'version' => (int) $row['version'], 'pushed_at' => (string) $row['pushed_at'], 'message' => (string) $row['message'], 'note' => $note];
        }
        $steps[] = self::step('log', 'Nhật ký gửi', $keys ? $lvl : 'none',
            $keys ? implode(' · ', array_map(function ($r) { return $r['docKey'] . ': ' . ($r['status'] !== '' ? $r['status'] . ' v' . $r['version'] : '—') . ' (' . $r['note'] . ')'; }, $lrows)) : 'Không có chứng từ để tra',
            ['rows' => $lrows]);

        // 6. Kế toán (chỉ khi được yêu cầu)
        if (!empty($opts['remote']) && $keys) {
            $client = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog);
            $rem = [];
            $bad = false;
            foreach ($keys as $k) {
                $r = $client->get('/api/v1/documents', ['search' => $k, 'pageSize' => 5]);
                if (is_wp_error($r)) {
                    $rem[] = ['docKey' => $k, 'error' => $r->get_error_message()];
                    $bad = true;
                    continue;
                }
                $hit = null;
                foreach ((array) ($r['data'] ?? []) as $x) {
                    if ((string) ((array) $x)['docKey'] === $k) {
                        $hit = (array) $x;
                    }
                }
                $rem[] = $hit ? ['docKey' => $k, 'status' => (string) ($hit['status'] ?? ''), 'postedVersion' => $hit['postedVersion'] ?? null, 'error' => (string) ($hit['errorMessage'] ?? '')] : ['docKey' => $k, 'status' => '', 'error' => 'kế toán chưa có chứng từ này'];
                $bad = $bad || !$hit || !empty($hit['errorMessage']);
            }
            $steps[] = self::step('remote', 'Kế toán', $bad ? 'warn' : 'pass', implode(' · ', array_map(function ($r) { return $r['docKey'] . ': ' . ($r['status'] ?: '—') . ($r['error'] ? ' (' . $r['error'] . ')' : ''); }, $rem)), ['rows' => $rem]);
        } else {
            $steps[] = self::step('remote', 'Kế toán', 'none', 'Chưa hỏi (bật "Hỏi trạng thái ở kế toán")', ['rows' => []]);
        }
        return ['blog' => $blog, 'id' => $p['id'], 'docKey' => $p['docKey'] ?: (string) ($docs[0]['docKey'] ?? ''), 'steps' => $steps];
    }

    /** Tóm tắt một chứng từ + mã đã dịch so với bản chưa dịch. */
    public static function doc_summary(array $d, array $raw)
    {
        $codes = function ($x) {
            $c = ['branchCode' => (string) ($x['branchCode'] ?? ''), 'methodCode' => (string) ($x['methodCode'] ?? ''), 'reasonCode' => (string) ($x['reasonCode'] ?? '')];
            foreach ((array) ($x['payments'] ?? []) as $i => $p) {
                $c['payments[' . $i . ']'] = (string) ($p['methodCode'] ?? '');
            }
            foreach ((array) ($x['lines'] ?? []) as $i => $l) {
                $c['lines[' . $i . '].taxCode'] = (string) ($l['taxCode'] ?? '');
            }
            return array_filter($c, 'strlen');
        };
        $a = $codes($raw);
        $b = $codes($d);
        $mapped = [];
        foreach ($b as $k => $v) {
            if (isset($a[$k]) && $a[$k] !== $v) {
                $mapped[] = ['field' => $k, 'from' => $a[$k], 'to' => $v];
            }
        }
        return [
            'docKey' => (string) $d['docKey'], 'docType' => (string) ($d['docType'] ?? ''), 'docNo' => (string) ($d['docNo'] ?? ''), 'docDate' => (string) ($d['docDate'] ?? ''),
            'total' => (float) ($d['totals']['total'] ?? 0), 'lines' => count((array) ($d['lines'] ?? [])), 'codes' => $b, 'mapped' => $mapped,
            'hash' => class_exists('TGS_Acc_Pusher') ? TGS_Acc_Pusher::hash($d) : md5(json_encode($d)),
        ];
    }

    /** Phiếu + phiếu con (cha = phiếu) + phiếu cha (nếu phiếu là thu / chi / xuất). Chỉ đọc. */
    public static function ledger_family($blog_id, $id)
    {
        global $wpdb;
        $L = $wpdb->get_blog_prefix((int) $blog_id) . 'local_ledger';
        $cols = 'local_ledger_id AS id, local_ledger_type AS type, local_ledger_parent_id AS parent_id, local_ledger_code AS code,
                 COALESCE(local_ledger_ticket_date, DATE(created_at)) AS day, local_ledger_approver_status AS approver_status,
                 local_ledger_total_amount AS total, is_deleted, updated_at';
        $root = $wpdb->get_row($wpdb->prepare("SELECT {$cols} FROM {$L} WHERE local_ledger_id = %d", (int) $id), ARRAY_A);
        if (!$root) {
            return [];
        }
        $top = (int) $root['parent_id'] > 0 ? (int) $root['parent_id'] : (int) $root['id'];
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT {$cols} FROM {$L} WHERE local_ledger_id = %d OR local_ledger_parent_id = %d ORDER BY local_ledger_id", $top, $top), ARRAY_A);
        $ids = array_column($rows, 'id');
        if (!in_array((string) $root['id'], array_map('strval', $ids), true)) {
            $rows[] = $root;
        }
        return $rows;
    }

    /** Sự kiện của các phiếu trong $days ngày gần nhất, kèm kết quả gửi (nối theo id sự kiện). */
    public static function events($blog_id, array $ledger_ids, $days)
    {
        $want = array_fill_keys(array_map('intval', $ledger_ids), true);
        $out = [];
        $res = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
            foreach (TGS_Acc_Events::read(TGS_Acc_Events::file($date), 3 * 1024 * 1024) as $r) {
                if (isset($r['res'])) {
                    foreach ((array) ($r['ids'] ?? []) as $eid) {
                        $res[$eid] = $r;
                    }
                } elseif (($r['kind'] ?? '') === 'ledger' && (int) ($r['blog'] ?? 0) === (int) $blog_id && isset($want[(int) ($r['ref'] ?? 0)])) {
                    $out[] = $r;
                }
            }
        }
        foreach ($out as &$e) {
            if (isset($e['id'], $res[$e['id']])) {
                $e['result'] = $res[$e['id']];
            }
        }
        unset($e);
        return $out;
    }

    /** Nhóm đang chờ gửi lại có chứa một trong các phiếu. */
    public static function queue($blog_id, array $ledger_ids)
    {
        $want = array_map('intval', $ledger_ids);
        $out = [];
        foreach (TGS_Acc_Events::retry_state() as $r) {
            if (empty($r['closed']) && (int) ($r['blog'] ?? 0) === (int) $blog_id && array_intersect($want, array_map('intval', (array) ($r['ledger'] ?? [])))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function step($key, $name, $level, $message, array $data)
    {
        return ['key' => $key, 'name' => $name, 'level' => $level, 'message' => $message, 'data' => $data];
    }
}
