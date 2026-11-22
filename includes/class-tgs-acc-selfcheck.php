<?php
/**
 * Tự kiểm theo cặp blog → tenant (ADR-OKT-1 I-8, open-setup docs/chuong-trinh/OKT/75-LUAT-TU-KIEM.md).
 *
 * Chạy QUA ĐÚNG API mà đường nối dùng, bằng ĐÚNG khoá của blog, đọc ĐÚNG sổ của blog. Kiểm chính những mã mà bridge
 * SẼ GỬI: dựng chứng từ của các phiếu gần nhất bằng đường thật (TGS_Acc_Pusher::build_refs → mapper), rồi đối chiếu
 * với danh mục của tenant. Không ghi gì vào kế toán (preflight save:false), không ghi sổ POS (I-0). Chỉ ghi option của
 * bridge: kết quả gần nhất, mã tenant đã xác nhận, bản đồ blog → tenant.
 *
 *   SC-1  cấu hình (khoá riêng, URL)          SC-7  chi nhánh + kho
 *   SC-2  danh tính tenant (/tenant)          SC-8  lý do xuất / nhập
 *   SC-3  một tenant ↔ một blog               SC-9  chứng từ mẫu thật → preflight
 *   SC-4  quyền của khoá                      SC-10 hàng chờ + trạng thái + lịch cron của site chính (OKT-MT-3)
 *   SC-5  phương thức thanh toán              SC-11 đồng hồ
 *   SC-6  mã thuế                             SC-12 đối chiếu chứng từ + tồn (OKT-H4, TGS_Acc_Reconcile; tối đa vàng)
 *
 * @package tgs_accounting_bridge
 */
// [2026-11-22 11:30 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — tự kiểm SC-1…SC-11 theo cặp blog → tenant qua API (luật I-8).

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Selfcheck
{
    const TENANT_OPTION = 'tgs_acc_tenant_code';   // option của blog: mã tenant admin đã xác nhận
    const MAP_OPTION    = 'tgs_acc_tenant_map';    // site option: [blog_id => mã tenant thấy ở lần tự kiểm gần nhất]
    const LAST_OPTION   = 'tgs_acc_selfcheck_last'; // option của blog: kết quả gần nhất
    const SAMPLES       = 5;                        // số chứng từ gửi preflight (SC-9)
    const SCAN          = 200;                      // số phiếu gần nhất dựng để gom mã (SC-5…SC-8)
    const QUEUE_WARN_DAYS = 3;
    const QUEUE_FAIL_DAYS = 6;                      // hàng chờ giữ 7 ngày (TGS_Acc_Events::RETRY_DAYS)
    const CLOCK_WARN = 60;
    const CLOCK_FAIL = 300;
    // [2026-11-22 02:00 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — cron hằng ngày 02:30 (giờ site), tổng kết cấp mạng.
    const DAILY_HOOK = 'tgs_acc_selfcheck_daily';
    /** OKT-MT-3: lịch của site chính → giây quá hạn còn chấp nhận (chu kỳ + đệm). */
    const CRON_HOOKS = ['tgs_acc_events_retry' => 900, 'tgs_acc_status_pull' => 1800, 'tgs_acc_selfcheck_daily' => 2 * 86400];
    const CRON_FAIL = 6 * 3600;
    const RECONCILE_DAYS = 7;
    const RECONCILE_SKUS = 50;
    const DAILY_OPTION = 'tgs_acc_selfcheck_daily';
    const DAILY_AT = '02:30';

    /**
     * @param array $opts samples, scan, confirm_tenant (bool: lưu mã tenant đang thấy làm mã đã xác nhận)
     * @param array $deps (cho test) client, build(callable $blog, $ids), ids(callable $blog, $limit), status(callable $blog), now(int)
     * @return array{blog:int, tenant:string, at:string, verdict:string, checks:array[]}
     */
    public static function run($blog_id, array $opts = [], array $deps = [])
    {
        $blog = (int) $blog_id;
        $samples = max(0, (int) ($opts['samples'] ?? self::SAMPLES));
        $scan = max(1, (int) ($opts['scan'] ?? self::SCAN));
        $now = (int) ($deps['now'] ?? time());
        $c = [];

        // [2026-11-23 06:40 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-9 — SC-0b mô hình triển khai KHAI RÕ (luật tối thượng P-0, open-setup 82 B-1):
        // hằng TGS_ACC_DEPLOYMENT → lựa chọn ở mạng → một site. Không đoán từ khoá. Multisite chưa khai → vàng.
        $profile = (string) ($deps['profile'] ?? (class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::current() : ''));
        $src = (string) ($deps['profile_source'] ?? (class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::source() : 'none'));
        if ($profile === '') {
            self::add($c, 'SC-0b', 'warn', 'Chưa khai mô hình triển khai — máy chủ bizcity đặt hằng TGS_ACC_DEPLOYMENT = bizcity; tự cài nhiều cửa hàng chọn ở Cổng kế toán → Cài đặt (hoặc wp tgs-acc profile --set=chain)', ['profile' => '', 'source' => $src]);
        } else {
            self::add($c, 'SC-0b', 'pass', 'Mô hình: ' . (class_exists('TGS_Acc_Profile') ? TGS_Acc_Profile::label($profile) : $profile) . ' (' . ['constant' => 'hằng máy chủ', 'option' => 'đã chọn ở Cài đặt', 'auto' => 'tự nhận: WordPress một site'][$src] . ')', ['profile' => $profile, 'source' => $src]);
        }

        // SC-1 cấu hình — theo mô hình (đặc tả 75 SC-1; 82 §4 B-5)
        $mode = class_exists('TGS_Acc_Config') ? TGS_Acc_Config::key_mode($blog) : 'none';
        $url = class_exists('TGS_Acc_Config') ? TGS_Acc_Config::url_for($blog) : '';
        if ($mode === 'none' || trim($url) === '') {
            self::add($c, 'SC-1', 'fail', 'Blog chưa có ' . ($mode === 'none' ? 'khoá' : 'URL') . ' kế toán — không có đường nối để kiểm', ['key_mode' => $mode, 'url' => $url, 'profile' => $profile]);
            return self::finish($blog, '', $c, $now);
        }
        // [2026-11-23 12:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 — nhánh khoá cài đặt (chain): SC-1 = khoá cài đặt + cửa hàng đã được kế toán nối vào sổ. Chưa nối → vàng, dừng ở đây
        // (open-ketoan trả 403 BLOG_NOT_LINKED cho mọi lời gọi; đơn giữ trong hàng chờ, không phải lỗi khoá).
        if ($mode === 'install') {
            $ls = (string) ($deps['link_status'] ?? (class_exists('TGS_Acc_Install') ? TGS_Acc_Install::status($blog) : ''));
            if ($ls !== 'active') {
                $what = ['' => 'chưa đăng ký với open-ketoan — bấm Đăng ký ở Cài đặt', 'unknown' => 'open-ketoan chưa biết cửa hàng này — bấm Đăng ký ở Cài đặt', 'pending' => 'chờ kế toán nhập mã nối (Kết nối open-pos → Nhận cửa hàng) ở đúng sổ của cửa hàng', 'revoked' => 'đã bị thu hồi bên open-ketoan'][$ls] ?? $ls;
                // [2026-11-23 04:20 PM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-11 — đặc tả 75 SC-1: blog bị thu hồi bên open-ketoan = ĐỎ (cửa hàng đã nối bị cắt, cần người xử lý); chưa đăng ký / chờ mã nối = vàng.
                self::add($c, 'SC-1', $ls === 'revoked' ? 'fail' : 'warn', 'Khoá cài đặt: cửa hàng ' . $what . '. Đơn giữ trong hàng chờ, nối xong gửi bù', ['key_mode' => $mode, 'url' => $url, 'profile' => $profile, 'link' => $ls]);
                return self::finish($blog, '', $c, $now);
            }
            self::add($c, 'SC-1', 'pass', 'Khoá cài đặt + cửa hàng đã được kế toán nối vào sổ (mỗi cửa hàng một tenant — P-0)', ['key_mode' => $mode, 'url' => $url, 'profile' => $profile, 'link' => $ls]);
        } elseif ($mode === 'own') {
            $msg = ['bizcity' => 'Khoá riêng của site (bizcity — đúng P-0)', 'chain' => 'Khoá riêng của blog (mô hình nhiều cửa hàng cho phép; khoá riêng thắng khoá cài đặt)', 'single' => 'Khoá của site'][$profile] ?? 'Khoá riêng của blog';
            self::add($c, 'SC-1', 'pass', $msg, ['key_mode' => $mode, 'url' => $url, 'profile' => $profile]);
        } else {
            $msg = [
                'bizcity' => 'bizcity đang dùng khoá chung của mạng (giai đoạn chuyển) — P-0: mỗi site phải có khoá riêng của phần mềm kế toán của nó',
                'chain'   => 'Khoá chung trỏ MỘT tenant cho cả mạng — mô hình nhiều cửa hàng: khai khoá cài đặt (okti_…) ở Cài đặt để mỗi cửa hàng là một tenant (P-0)',
            ][$profile] ?? 'Đang dùng khoá chung của mạng — chưa chứng minh được một blog = một tenant';
            self::add($c, 'SC-1', 'warn', $msg, ['key_mode' => $mode, 'url' => $url, 'profile' => $profile]);
        }

        $client = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog);

        // SC-2 danh tính tenant
        $t = $client->get('/api/v1/tenant');
        $tenant = '';
        if (is_wp_error($t)) {
            self::add($c, 'SC-2', 'fail', 'Không đọc được danh tính tenant: ' . $t->get_error_message(), ['status' => self::status_of($t)]);
            self::add($c, 'SC-4', self::denied($t) ? 'fail' : 'warn', self::denied($t) ? 'Khoá bị từ chối (' . self::status_of($t) . ')' : 'Không gọi được kế toán', []);
            return self::finish($blog, '', $c, $now);
        }
        $tenant = (string) ($t['data']['code'] ?? '');
        $stored = (string) TGS_Acc_Site::opt($blog, self::TENANT_OPTION, '');
        if ($stored === '' && !empty($opts['confirm_tenant']) && $tenant !== '') {
            TGS_Acc_Site::set($blog, self::TENANT_OPTION, $tenant);
            $stored = $tenant;
        }
        if ($stored === '') {
            self::add($c, 'SC-2', 'warn', "Khoá trỏ tenant `{$tenant}` — chưa xác nhận. Đúng thì chạy lại với --confirm-tenant", ['tenant' => $tenant]);
        } elseif ($stored !== $tenant) {
            self::add($c, 'SC-2', 'fail', "Khoá trỏ tenant `{$tenant}`, blog đã xác nhận `{$stored}` — chứng từ sẽ vào sổ của đơn vị khác", ['tenant' => $tenant, 'confirmed' => $stored]);
        } else {
            self::add($c, 'SC-2', 'pass', "Tenant `{$tenant}` đúng mã đã xác nhận", ['tenant' => $tenant]);
        }

        // SC-3 một tenant ↔ một blog
        $map = (array) get_site_option(self::MAP_OPTION, []);
        $map[$blog] = $tenant;
        update_site_option(self::MAP_OPTION, $map);
        $twins = [];
        $own_twin = false;
        foreach ($map as $b => $code) {
            $m = TGS_Acc_Config::key_mode((int) $b);
            if ((int) $b !== $blog && $code !== '' && $code === $tenant && $m !== 'none') {
                $twins[] = (int) $b;
                $own_twin = $own_twin || $m !== 'legacy';
            }
        }
        // [2026-11-23 06:10 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-8 (luật tối thượng P-0, open-setup 82 B-8) — mạng dùng KHOÁ CHUNG hôm nay
        // (bizcity đang chuyển sang khoá theo blog; selfhost chưa có khoá cài đặt) dồn mọi site vào MỘT tenant. Trước đây SC-3 đỏ →
        // enforce() TỰ TẠM DỪNG gửi của CẢ MẠNG sau lượt tự kiểm hằng ngày. Blog khoá chung mà mọi blog trùng tenant cũng khoá chung
        // → vàng (không tự dừng), chỉ cách chuyển. Có blog khoá RIÊNG trỏ trùng tenant → vẫn đỏ + tự dừng (trộn sổ thật).
        $shared = $twins && TGS_Acc_Config::key_mode($blog) === 'legacy' && !$own_twin;
        if ($shared) {
            self::add($c, 'SC-3', 'warn', 'Khoá chung của mạng đang dồn ' . (count($twins) + 1) . ' site vào một tenant `' . $tenant . '` (blog ' . implode(', ', $twins) . ') — mỗi cửa hàng phải là một tenant (P-0): bizcity chuyển sang khoá riêng từng site; tự cài nhiều cửa hàng dùng khoá cài đặt. Không tự tạm dừng', ['others' => $twins, 'shared_key' => true]);
        } else {
            self::add($c, 'SC-3', $twins ? 'fail' : 'pass', $twins ? 'Tenant `' . $tenant . '` cũng đang nối với blog ' . implode(', ', $twins) . ' — sổ hai shop trộn nhau' : 'Không blog nào khác nối tenant này (theo các lần tự kiểm đã chạy)', ['others' => $twins]);
        }

        // SC-4 quyền (đọc feed, đọc chứng từ); ghi được kiểm ở SC-9 qua preflight
        $meta = $client->get('/api/v1/catalog/feed/meta', ['since' => gmdate('c', $now)]);
        $docs_r = $client->get('/api/v1/documents', ['search' => 'okt-selfcheck', 'pageSize' => 1]);
        $bad = [];
        foreach (['feed/meta' => $meta, 'documents' => $docs_r] as $k => $r) {
            if (is_wp_error($r)) {
                $bad[$k] = self::status_of($r) . ' ' . $r->get_error_message();
            }
        }
        self::add($c, 'SC-4', $bad ? 'fail' : 'pass', $bad ? 'Khoá không đọc được: ' . implode('; ', array_map(function ($k, $v) { return "{$k} ({$v})"; }, array_keys($bad), $bad)) : 'Đọc được feed danh mục và trạng thái chứng từ', $bad);

        // SC-11 đồng hồ
        $st = is_wp_error($meta) ? '' : (string) ($meta['data']['serverTime'] ?? '');
        if ($st === '') {
            self::add($c, 'SC-11', 'warn', 'Không có serverTime để so đồng hồ', []);
        } else {
            $skew = abs(strtotime($st) - $now);
            self::add($c, 'SC-11', $skew > self::CLOCK_FAIL ? 'fail' : ($skew > self::CLOCK_WARN ? 'warn' : 'pass'), "Lệch đồng hồ {$skew} giây so với kế toán", ['skew' => $skew, 'serverTime' => $st]);
        }

        // Dựng chứng từ của các phiếu gần nhất bằng đường thật → gom mã bridge sẽ gửi
        $ids = isset($deps['ids']) ? call_user_func($deps['ids'], $blog, $scan) : self::recent_ids($blog, $scan);
        $built = $ids ? (isset($deps['build']) ? call_user_func($deps['build'], $blog, $ids) : TGS_Acc_Pusher::build_refs($blog, $ids)) : ['docs' => [], 'failed' => []];
        $docs = array_map(function ($d) { return class_exists('TGS_Acc_Pusher') && method_exists('TGS_Acc_Pusher', 'decorate') ? TGS_Acc_Pusher::decorate($d) : $d; }, (array) ($built['docs'] ?? []));
        $used = self::used_codes($docs);

        // SC-5…SC-8 đối chiếu danh mục tenant — mã ĐÃ DỊCH theo bảng ánh xạ của blog (S001-MD3)
        self::against($c, 'SC-5', 'phương thức thanh toán', $used['methods'], $client->get('/api/v1/posting/payment-methods'));
        // [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — chưa có bảng ánh xạ: mã đang suy từ nhãn (đặc tả 75 SC-5 warn).
        $map_ready = $deps['map_ready'] ?? (class_exists('TGS_Okt_Map') && TGS_Okt_Map::ready($blog));
        if (!$map_ready) {
            self::add($c, 'SC-5', 'warn', 'Chưa có bảng ánh xạ mã ({prefix}tgs_okt_map, open-pos-backend S001-MD3) — mã phương thức / lý do / thuế đang gửi đúng như bridge suy ra', []);
        }
        self::against($c, 'SC-6', 'mã thuế', $used['taxes'], $client->get('/api/v1/tax-codes'));
        $br = $client->get('/api/v1/branches');
        self::against($c, 'SC-7', 'chi nhánh', $used['branches'], $br);
        $wh = $client->get('/api/v1/warehouses');
        // [2026-11-22 07:30 PM Johnny Chu - Chu Hoàng Anh] OKT-H2 — phiếu POS không ghi kho (zone trống); kế toán ghi dòng kho trống vào
        // KHO MẶC ĐỊNH của chi nhánh (kho-giu-ho.ts, đã chạy thật: movement + bút toán mang KCN01). Không có kho mặc định → tồn chỉ theo chi nhánh.
        if (!is_wp_error($wh)) {
            $rows = (array) ($wh['data'] ?? []);
            $branch = array_key_exists('branch', $deps) ? (string) $deps['branch'] : (class_exists('TGS_Acc_Source') ? (string) TGS_Acc_Source::shop_code($blog) : '');
            $def = '';
            foreach ($rows as $w) {
                $w = (array) $w;
                if ((string) ($w['branchCode'] ?? '') === $branch && !empty($w['isDefault']) && ($w['isActive'] ?? true)) {
                    $def = (string) $w['code'];
                    break;
                }
            }
            if (!$rows) {
                self::add($c, 'SC-7', 'warn', 'Tenant chưa có kho nào — phiếu POS không ghi kho, kế toán chỉ theo dõi tồn theo chi nhánh (chưa tách kho, chưa nhận diện kho giữ hộ)', ['branch' => $branch]);
            } elseif ($branch === '') {
                self::add($c, 'SC-7', 'warn', 'Blog chưa nối chi nhánh — không biết kho mặc định nào nhận phiếu POS', []);
            } elseif ($def === '') {
                self::add($c, 'SC-7', 'warn', "Chi nhánh `{$branch}` chưa có kho mặc định đang dùng — phiếu POS không ghi kho sẽ ghi tồn không có kho", ['branch' => $branch]);
            } else {
                self::add($c, 'SC-7', 'pass', "Phiếu POS không ghi kho vào kho mặc định `{$def}` của chi nhánh `{$branch}`", ['branch' => $branch, 'default' => $def]);
            }
        }
        if ($used['warehouses']) {
            self::against($c, 'SC-7', 'kho', $used['warehouses'], $wh);
        }
        self::against($c, 'SC-8', 'lý do', $used['reasons'], $client->get('/api/v1/posting/reasons'));

        // SC-9 chứng từ mẫu thật → preflight (không ghi)
        if (!$docs) {
            self::add($c, 'SC-9', 'warn', 'Chưa có phiếu nào dựng được chứng từ' . (!empty($built['failed']) ? ' (' . implode('; ', array_slice((array) $built['failed'], 0, 3)) . ')' : ' — bán một đơn rồi chạy lại'), ['scanned' => count($ids)]);
        } else {
            // [2026-11-22 09:00 PM Johnny Chu - Chu Hoàng Anh] OKT-MA-TRAN — phiếu bán trước, rồi MỚI NHẤT trước. build_refs trả theo id tăng
            // dần → trước đây preflight luôn chạy trên các đơn CŨ NHẤT của khoảng quét (đo thật: sale:2,5,8,11,14 khi shop đã có 150 phiếu).
            usort($docs, function ($a, $b) {
                $s = ($a['docType'] === 'sale' ? 0 : 1) - ($b['docType'] === 'sale' ? 0 : 1);
                $n = function ($d) { return (int) preg_replace('/^.*:/', '', (string) ($d['docKey'] ?? '')); }; // btsoft:{blog}:{loại}:{id}
                return $s ?: ($n($b) - $n($a));
            });
            $blocks = $warns = 0;
            $seen = [];
            $denied = false;
            foreach (array_slice($docs, 0, $samples) as $doc) {
                $doc['version'] = max(1, (int) ($doc['version'] ?? 1));
                $r = $client->post_json('/api/v1/intake/preflight', ['document' => $doc, 'save' => false, 'post' => true]);
                if (is_wp_error($r)) {
                    $denied = $denied || self::denied($r);
                    $blocks++;
                    $seen[] = $doc['docKey'] . ': ' . $r->get_error_message();
                    continue;
                }
                foreach ((array) ($r['findings'] ?? []) as $f) {
                    $f = (array) $f;
                    $sev = (string) ($f['severity'] ?? '');
                    if ($sev === 'block') {
                        $blocks++;
                        $seen[] = $doc['docKey'] . ': ' . ($f['code'] ?? '') . ' ' . ($f['title'] ?? '');
                    } elseif ($sev === 'warn') {
                        $warns++;
                        $seen[] = $doc['docKey'] . ': ' . ($f['code'] ?? '') . ' ' . ($f['title'] ?? '');
                    }
                }
            }
            if ($denied) {
                self::add($c, 'SC-4', 'fail', 'Khoá không được gửi chứng từ (preflight bị từ chối)', []);
            }
            $n = min($samples, count($docs));
            self::add($c, 'SC-9', $blocks ? 'fail' : ($warns ? 'warn' : 'pass'), "{$n} chứng từ mẫu: {$blocks} lỗi chặn, {$warns} cảnh báo", ['tested' => $n, 'block' => $blocks, 'warn' => $warns, 'findings' => array_slice(array_values(array_unique($seen)), 0, 10)]);
        }

        // SC-10 hàng chờ + trạng thái
        $s = isset($deps['status']) ? call_user_func($deps['status'], $blog) : TGS_Acc_Cli::status_data($blog);
        $q = isset($deps['queue']) ? call_user_func($deps['queue'], $blog) : TGS_Acc_Events::queue_of($blog);
        $age = ($q['oldest'] ?? '') !== '' ? max(0, ($now - strtotime((string) $q['oldest'])) / DAY_IN_SECONDS) : 0;
        // Shop đang tạm dừng: hàng chờ được giữ tới 90 ngày (OKT-SC-2) — chỉ đỏ khi sắp quá giới hạn đó.
        $fail_days = !empty($s['paused']) ? (class_exists('TGS_Acc_Events') ? TGS_Acc_Events::PAUSED_KEEP_DAYS : 90) - 1 : self::QUEUE_FAIL_DAYS;
        $lvl = $age >= $fail_days ? 'fail' : (($age >= self::QUEUE_WARN_DAYS || $s['pending_over_24h'] > 0 || $s['errors'] > 0) ? 'warn' : 'pass');
        // [2026-11-22 06:10 PM Johnny Chu - Chu Hoàng Anh] OKT-CAU-DAO — cầu dao đang mở: báo vàng (hàng chờ đang giữ, cron thử lại).
        $br = isset($deps['breaker']) ? $deps['breaker'] : (class_exists('TGS_Acc_Events') && method_exists('TGS_Acc_Events', 'breaker_open') && TGS_Acc_Events::breaker_open($blog) ? TGS_Acc_Events::breaker($blog) : null);
        if (is_array($br) && $br) {
            self::add($c, 'SC-10', 'warn', 'Cầu dao mở (lần ' . (int) ($br['fails'] ?? 1) . '): lần gửi gần nhất không tới được kế toán — ' . (string) ($br['msg'] ?? '') . '; đơn xếp hàng', ['breaker' => $br]);
        }
        // [2026-11-22 05:10 PM Johnny Chu - Chu Hoàng Anh] OKT-I3 — thư mục sự kiện không ghi được = mất hàng chờ trong im lặng.
        $writable = isset($deps['writable']) ? (bool) $deps['writable'] : (class_exists('TGS_Acc_Events') && method_exists('TGS_Acc_Events', 'writable') ? TGS_Acc_Events::writable() : true);
        $werr = isset($deps['write_error']) ? $deps['write_error'] : (function_exists('get_site_option') ? get_site_option('tgs_acc_events_write_error', null) : null);
        if (!$writable) {
            self::add($c, 'SC-10', 'fail', 'Thư mục sự kiện kế toán không ghi được (quyền thư mục uploads/tgs-acc-events) — sự kiện và hàng chờ bị mất', []);
        } elseif (is_array($werr) && (int) ($werr['at'] ?? 0) > $now - DAY_IN_SECONDS) {
            self::add($c, 'SC-10', 'fail', 'Trong 24 giờ có lần không ghi được tệp sự kiện: ' . (string) ($werr['file'] ?? '') . ' — kiểm quyền thư mục rồi gửi lại ngày đó', ['write_error' => $werr]);
        }
        self::add($c, 'SC-10', $lvl, sprintf('Hàng chờ %d nhóm (cũ nhất %.1f ngày), chờ định khoản > 24 h: %d, lỗi: %d', (int) $q['groups'], $age, (int) $s['pending_over_24h'], (int) $s['errors']), ['queue' => (int) $q['groups'], 'oldest_days' => round($age, 1), 'pending_over_24h' => (int) $s['pending_over_24h'], 'errors' => (int) $s['errors'], 'paused' => !empty($s['paused'])]);

        // [2026-11-22 09:45 PM Johnny Chu - Chu Hoàng Anh] OKT-MT-3 — gửi lại / kéo trạng thái / tự kiểm hằng ngày là lịch WP-Cron của SITE CHÍNH.
        // DISABLE_WP_CRON mà máy chủ không có cron hệ thống → cả ba đứng im, không ai báo (đo thật ở ma trận 65: quá hạn nhiều giờ).
        $cron = array_key_exists('cron', $deps) ? $deps['cron'] : self::cron_next();
        if (is_array($cron)) {
            $late = [];
            foreach (self::CRON_HOOKS as $hook => $grace) {
                $t = $cron[$hook] ?? null;
                if (!$t) {
                    $late[$hook] = 'chưa lên lịch';
                } elseif ($now - (int) $t > $grace) {
                    $late[$hook] = 'quá hạn ' . round(($now - (int) $t) / 60) . ' phút';
                }
            }
            if ($late) {
                $worst = max(array_map(function ($h) use ($cron, $now) { return empty($cron[$h]) ? PHP_INT_MAX : $now - (int) $cron[$h]; }, array_keys($late)));
                self::add($c, 'SC-10', $worst > self::CRON_FAIL ? 'fail' : 'warn', 'Lịch của site chính không chạy: ' . implode('; ', array_map(function ($h, $m) { return "{$h} {$m}"; }, array_keys($late), $late)) . ' — máy chủ cần cron hệ thống gọi WP-Cron của site chính mỗi phút', ['late' => $late]);
            }
        }

        // [2026-11-22 09:45 PM Johnny Chu - Chu Hoàng Anh] OKT-H4 — SC-12 đối chiếu chứng từ (mồ côi, thiếu) + tồn theo SKU (giai đoạn đầu: chỉ báo, tối đa vàng).
        if (empty($opts['no_reconcile']) && (isset($deps['reconcile']) || class_exists('TGS_Acc_Reconcile'))) {
            $rc = isset($deps['reconcile']) ? call_user_func($deps['reconcile'], $blog, $client) : TGS_Acc_Reconcile::run($blog, ['days' => self::RECONCILE_DAYS, 'skus' => self::RECONCILE_SKUS], ['client' => $client]);
            foreach ((array) ($rc['checks'] ?? []) as $k) {
                self::add($c, 'SC-12', $k['level'] === 'pass' ? 'pass' : 'warn', $k['id'] . ' ' . $k['message'], (array) ($k['data'] ?? []));
            }
        }

        return self::finish($blog, $tenant, $c, $now);
    }

    /**
     * Hành động tự động theo đặc tả 75 §3: SAI hoặc TRÙNG tenant (chắc chắn — không phải "không gọi được") → tự tạm dừng gửi của
     * blog; quầy vẫn bán, chứng từ xếp hàng (I-3). Không tự bỏ tạm dừng: admin sửa khoá, chạy lại tự kiểm, bỏ tạm dừng.
     * @param callable|null $pause (cho test) fn($blog, $reason)
     */
    public static function enforce(array $r, $pause = null)
    {
        $why = [];
        foreach ($r['checks'] as $c) {
            if ($c['level'] !== 'fail') {
                continue;
            }
            if ($c['id'] === 'SC-2' && isset($c['data']['confirmed'])) {
                $why[] = 'SC-2 khoá trỏ tenant ' . ($c['data']['tenant'] ?? '?') . ', đã xác nhận ' . $c['data']['confirmed'];
            }
            if ($c['id'] === 'SC-3' && !empty($c['data']['others'])) {
                $why[] = 'SC-3 tenant ' . $r['tenant'] . ' trùng blog ' . implode(', ', (array) $c['data']['others']);
            }
        }
        if (!$why) {
            return $r;
        }
        $reason = 'selfcheck: ' . implode('; ', $why);
        $pause ? call_user_func($pause, $r['blog'], $reason) : TGS_Acc_Events::pause($r['blog'], $reason);
        $r['enforced'] = 'paused';
        $r['checks'][] = ['id' => 'SC-2', 'level' => 'fail', 'message' => 'Đã TỰ TẠM DỪNG gửi của blog (quầy vẫn bán, chứng từ xếp hàng). Sửa khoá / tenant, chạy lại tự kiểm, rồi bỏ tạm dừng', 'data' => ['reason' => $reason]];
        TGS_Acc_Site::set($r['blog'], self::LAST_OPTION, $r);
        return $r;
    }

    public static function init()
    {
        add_action(self::DAILY_HOOK, [__CLASS__, 'daily']);
        add_action('init', [__CLASS__, 'schedule'], 30);
    }

    /** Lịch hằng ngày lúc 02:30 giờ site, chỉ ở site chính (lặp theo blog trong daily()). */
    public static function schedule()
    {
        if (!function_exists('is_main_site') || !is_main_site() || wp_next_scheduled(self::DAILY_HOOK)) {
            return;
        }
        wp_schedule_event(self::next_run(time()), 'daily', self::DAILY_HOOK);
    }

    /** Lần chạy kế tiếp lúc DAILY_AT theo múi giờ site (timestamp UTC). */
    public static function next_run($now, $tz = null)
    {
        $tz = $tz ?: (function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Asia/Ho_Chi_Minh'));
        $d = (new DateTimeImmutable('@' . (int) $now))->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', self::DAILY_AT));
        $at = $d->setTime($h, $m, 0);
        if ($at->getTimestamp() <= (int) $now) {
            $at = $at->modify('+1 day');
        }
        return $at->getTimestamp();
    }

    /** Cron: tự kiểm mọi blog có khoá, áp hành động tự động, ghi tổng kết cấp mạng. */
    public static function daily()
    {
        $lock = (int) get_site_option('tgs_acc_selfcheck_lock', 0);
        if ($lock && time() - $lock < 1800) {
            return [];
        }
        update_site_option('tgs_acc_selfcheck_lock', time());
        $sum = ['at' => gmdate('c'), 'pass' => 0, 'warn' => 0, 'fail' => 0, 'paused' => 0, 'blogs' => []];
        try {
            foreach (self::blogs_with_key() as $b) {
                $r = self::enforce(self::run($b));
                $sum[$r['verdict']]++;
                $sum['paused'] += isset($r['enforced']) ? 1 : 0;
                $sum['blogs'][$b] = ['verdict' => $r['verdict'], 'tenant' => $r['tenant']];
            }
        } finally {
            delete_site_option('tgs_acc_selfcheck_lock');
        }
        update_site_option(self::DAILY_OPTION, $sum);
        return $sum;
    }

    /** Blog có khoá kế toán (riêng hoặc chung) — những cặp phải tự kiểm. */
    public static function blogs_with_key()
    {
        $ids = TGS_Acc_Site::blogs();
        return array_values(array_filter(array_map('intval', $ids), function ($b) { return TGS_Acc_Config::key_mode($b) !== 'none'; }));
    }

    /** Lần chạy kế tiếp của các lịch bridge trên SITE CHÍNH: [hook => ts | null]; null = không đọc được (không phải multisite / ngoài WP). */
    public static function cron_next()
    {
        // OKT-TOPO-2: một site cũng có lịch của site chính (blog 1) — đọc qua TGS_Acc_Site; chỉ bỏ qua khi ngoài WordPress.
        if (!function_exists('get_main_site_id')) {
            return null;
        }
        $cron = TGS_Acc_Site::opt(TGS_Acc_Site::main(), 'cron', []);
        $out = array_fill_keys(array_keys(self::CRON_HOOKS), null);
        foreach ((array) $cron as $ts => $hooks) {
            foreach ((array) $hooks as $hook => $_) {
                if (array_key_exists($hook, $out) && is_numeric($ts) && ($out[$hook] === null || (int) $ts < $out[$hook])) {
                    $out[$hook] = (int) $ts;
                }
            }
        }
        return $out;
    }

    /** Mã bridge sẽ gửi, gom từ chứng từ đã dựng: [methods, taxes, branches, warehouses, reasons] → [mã => số lần]. */
    public static function used_codes(array $docs)
    {
        $u = ['methods' => [], 'taxes' => [], 'branches' => [], 'warehouses' => [], 'reasons' => []];
        $inc = function ($k, $v) use (&$u) {
            $v = trim((string) $v);
            if ($v !== '') {
                $u[$k][$v] = ($u[$k][$v] ?? 0) + 1;
            }
        };
        foreach ($docs as $d) {
            $inc('branches', $d['branchCode'] ?? '');
            $inc('reasons', $d['reasonCode'] ?? '');
            if (($d['docType'] ?? '') === 'customer_payment') {
                $inc('methods', $d['methodCode'] ?? '');
            }
            foreach ((array) ($d['payments'] ?? []) as $p) {
                $inc('methods', $p['methodCode'] ?? '');
            }
            foreach ((array) ($d['lines'] ?? []) as $l) {
                $inc('taxes', $l['taxCode'] ?? '');
                $inc('warehouses', $l['warehouseCode'] ?? '');
            }
        }
        return $u;
    }

    /** Đối chiếu mã đang dùng với danh mục tenant (GET trả data[] có `code`). */
    private static function against(array &$c, $id, $what, array $used, $res)
    {
        if (is_wp_error($res)) {
            self::add($c, $id, 'fail', "Không đọc được danh mục {$what} của tenant: " . $res->get_error_message(), []);
            return;
        }
        $have = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $have[(string) ((array) $row)['code']] = true;
        }
        if (!$used) {
            self::add($c, $id, 'pass', "Chưa có {$what} nào trên phiếu gần nhất để đối chiếu (tenant có " . count($have) . ')', ['tenant' => count($have)]);
            return;
        }
        $missing = array_diff_key($used, $have);
        if ($missing) {
            arsort($missing);
            $list = implode(', ', array_map(function ($k, $n) { return "{$k} ×{$n}"; }, array_keys($missing), $missing));
            self::add($c, $id, 'fail', "{$what}: " . count($missing) . " mã bridge sẽ gửi mà tenant không có — {$list}", ['missing' => $missing, 'used' => $used]);
            return;
        }
        self::add($c, $id, 'pass', "{$what}: " . count($used) . ' mã đang dùng đều có ở tenant', ['used' => $used]);
    }

    /** local_ledger_id của các phiếu gần nhất có chứng từ (bán 10, hoàn 11, thu 7, chi trả khách 8), đã duyệt, không phải bản sao. */
    public static function recent_ids($blog_id, $limit)
    {
        global $wpdb;
        $L = $wpdb->get_blog_prefix((int) $blog_id) . 'local_ledger';
        $nm = TGS_Acc_Source::not_mirror_sql($L);
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT local_ledger_id FROM {$L}
              WHERE local_ledger_type IN (10, 11, 7, 8) AND (is_deleted = 0 OR is_deleted IS NULL) AND local_ledger_approver_status = 1{$nm}
              ORDER BY local_ledger_id DESC LIMIT %d",
            (int) $limit
        )));
    }

    private static function add(array &$c, $id, $level, $message, array $data)
    {
        $c[] = ['id' => $id, 'level' => $level, 'message' => $message, 'data' => $data];
    }

    private static function status_of($err)
    {
        $d = method_exists($err, 'get_error_data') ? $err->get_error_data() : null;
        return is_array($d) && isset($d['status']) ? (int) $d['status'] : 0;
    }

    private static function denied($err)
    {
        return in_array(self::status_of($err), [401, 403], true);
    }

    private static function finish($blog, $tenant, array $checks, $now)
    {
        $levels = array_column($checks, 'level');
        $verdict = in_array('fail', $levels, true) ? 'fail' : (in_array('warn', $levels, true) ? 'warn' : 'pass');
        $out = ['blog' => $blog, 'tenant' => $tenant, 'at' => gmdate('c', $now), 'verdict' => $verdict, 'checks' => $checks];
        TGS_Acc_Site::set($blog, self::LAST_OPTION, $out);
        return $out;
    }
}

if (function_exists('add_action')) {
    TGS_Acc_Selfcheck::init();
}
