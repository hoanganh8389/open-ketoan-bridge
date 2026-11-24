<?php
/**
 * DỰNG SẴN TENANT trước lần đẩy danh mục đầu (SW4b-1 — open-pos-backend PHASE-001 doc 16 §4, SW2 bước 0).
 *
 * Tenant mới (`db:seed`) có TT99, 6 mã thuế, 40 ĐVT, chi nhánh CN01 — KHÔNG có cây nhóm hàng, bảng giá (kể cả bảng giá công ty).
 * `/catalog/import` không tạo được nhóm. Lớp này dựng theo thứ tự, bằng khoá của CHÍNH blog (TGS_Acc_Api_Client::for_blog — I-1):
 *
 *   1. Cây nhóm: nhóm cha trước con; cấp 1–3 kèm mã SKU nhóm (tsm TGS_Catalog_Sku::ensure_codes — cùng luật sinh mã open-ketoan).
 *      PUT /api/v1/item-classes/group/:code?new=1. Mã nhóm bên kế toán = id_sync_api ?: id (cùng quy ước đường kéo về).
 *      Nhóm đã có: KHÔNG ghi đè (kế toán có thể đã sửa) — mã SKU / nhóm cha lệch thì báo.
 *   2. Bảng giá: bảng giá công ty (tsm is_company_default, không có thì bảng giá đang áp của blog) + bảng giá của blog nếu khác.
 *      CHỈ TẠO bảng còn thiếu: PUT một bảng đã có sẽ ghi đè tên / cờ (không gửi isCompany = TẮT cờ công ty), gửi isCompany = true sẽ
 *      bỏ cờ của bảng công ty khác — nên tenant đã có bảng công ty thì không đặt cờ.
 *   3. Chi nhánh của blog (TGS_Acc_Source::branch_of_blog): chưa có thì tạo (POST /api/v1/branches).
 *   4. Gán chi nhánh cho bảng giá của blog: PUT /price-lists/:code/branches với HỢP danh sách đang gán + chi nhánh của blog
 *      (lệnh này thay cả danh sách).
 *
 * $dry = true: chỉ GET, trả kế hoạch; không ghi gì ở kế toán, không ghi mã SKU nhóm vào tsm. Chạy lại an toàn (thứ đã có thì bỏ qua).
 * Không bán, không đụng POS (P-0 ý 4). CLI: wp tgs-acc prepare --shop=<id> [--run].
 *
 * @package open-ketoan-bridge
 */
// [2026-11-24 08:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-1 — dựng sẵn tenant: cây nhóm có mã SKU, bảng giá công ty, chi nhánh.

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Tenant_Prepare
{
    /**
     * @param array $deps ['client' => TGS_Acc_Api_Client] (test)
     * @return array{ok: bool, dry: bool, plan: array, done: array, skipped: array, errors: array, notes: array}
     */
    public static function run($blog_id, $dry = true, array $deps = [])
    {
        $blog_id = (int) $blog_id;
        $out = ['ok' => false, 'dry' => (bool) $dry, 'plan' => [], 'done' => [], 'skipped' => [], 'errors' => [], 'notes' => []];
        if (!function_exists('tgs_catalog_table') || !class_exists('TGS_Catalog_Sku') || !method_exists('TGS_Catalog_Sku', 'ensure_codes')) {
            $out['errors'][] = 'Thiếu open-pos-backend (tgs_shop_management) bản có TGS_Catalog_Sku::ensure_codes — không đọc được danh mục của shop.';
            return $out;
        }
        if (!TGS_Acc_Config::ready_for($blog_id)) {
            $out['errors'][] = "Blog {$blog_id} chưa nối phần mềm kế toán (chưa có khoá) — không dựng được.";
            return $out;
        }
        $c = $deps['client'] ?? TGS_Acc_Api_Client::for_blog($blog_id);
        $act = function ($what, callable $call) use (&$out, $dry) {
            if ($dry) {
                $out['plan'][] = $what;
                return true;
            }
            $r = $call();
            if (is_wp_error($r)) {
                $out['errors'][] = $what . ' — ' . $r->get_error_message();
                return false;
            }
            $out['done'][] = $what;
            return true;
        };

        // 1. cây nhóm
        $remote = self::index($c->get('/api/v1/item-classes/group'), 'code');
        if (is_wp_error($remote)) {
            $out['errors'][] = 'Không đọc được nhóm hàng của kế toán — ' . $remote->get_error_message();
            return $out;
        }
        $tree = TGS_Catalog_Sku::ensure_codes($blog_id, !$dry && (!function_exists('tgs_catalog_source') || tgs_catalog_source($blog_id) === 'local'));
        foreach ($tree['errors'] as $path => $msg) {
            $out['errors'][] = "Nhóm {$path}: {$msg}";
        }
        $ok_paths = [];
        foreach ($tree['groups'] as $path => $g) {
            if ($g['level'] === 0) {
                $ok_paths[$path] = true; // gốc chung "Nhóm hàng" — không đẩy, con của nó là cấp 1
                continue;
            }
            $parent = $g['parent'] !== null ? ($tree['groups'][$g['parent']] ?? null) : null;
            if ($g['parent'] !== null && (!$parent || !isset($ok_paths[$g['parent']]))) {
                $out['errors'][] = "Nhóm {$path}: nhóm cha chưa dựng được — bỏ qua.";
                continue;
            }
            $code = $g['erp_code'];
            $parent_code = $parent ? $parent['erp_code'] : null;
            $label = "nhóm {$code} \"{$g['name']}\"" . ($g['sku_code'] !== '' ? " (cấp {$g['level']}, mã SKU {$g['sku_code']})" : " (cấp {$g['level']})");
            if (isset($remote[$code])) {
                $r = $remote[$code];
                $lech = [];
                if ($g['sku_code'] !== '' && strtoupper((string) ($r['skuCode'] ?? '')) !== $g['sku_code']) {
                    $lech[] = 'mã SKU kế toán "' . ($r['skuCode'] ?? '') . '" ≠ shop "' . $g['sku_code'] . '"';
                }
                if ((string) ($r['parentCode'] ?? '') !== (string) $parent_code) {
                    $lech[] = 'nhóm cha kế toán "' . ($r['parentCode'] ?? '') . '" ≠ shop "' . $parent_code . '"';
                }
                if ($lech) {
                    $out['notes'][] = ucfirst($label) . ' đã có ở kế toán nhưng lệch: ' . implode('; ', $lech) . ' — không ghi đè (sửa ở kế toán hoặc ở shop).';
                } else {
                    $out['skipped'][] = $label . ' đã có';
                }
                $ok_paths[$path] = true;
                continue;
            }
            $body = array_filter(['name' => mb_substr($g['name'], 0, 255), 'parentCode' => $parent_code, 'isActive' => $g['active'],
                'skuCode' => $g['sku_code'] !== '' ? $g['sku_code'] : null], function ($v) { return $v !== null; });
            if ($act('Tạo ' . $label, function () use ($c, $code, $body) {
                return $c->put_json('/api/v1/item-classes/group/' . rawurlencode($code) . '?new=1', $body);
            })) {
                $ok_paths[$path] = true;
            }
        }

        // 2. bảng giá
        global $wpdb;
        $lt = tgs_catalog_table('price_list', $blog_id);
        $lists = [];
        foreach ((array) $wpdb->get_results("SELECT * FROM `{$lt}` WHERE COALESCE(is_deleted, 0) = 0 ORDER BY is_company_default DESC, global_htsoft_price_list_id", ARRAY_A) as $l) {
            $lists[(int) $l['global_htsoft_price_list_id']] = $l;
        }
        $cur_id = class_exists('TGS_Price_List') ? (int) TGS_Price_List::current_id($blog_id) : 0;
        $cur = $lists[$cur_id] ?? null;
        $company = null;
        foreach ($lists as $l) {
            if ((int) $l['is_company_default'] === 1) { $company = $l; break; }
        }
        $company = $company ?: $cur;
        $rlists = self::index($c->get('/api/v1/catalog/price-lists'), 'code');
        if (is_wp_error($rlists)) {
            $out['errors'][] = 'Không đọc được bảng giá của kế toán — ' . $rlists->get_error_message();
            return self::finish($out);
        }
        if (!$company) {
            $out['errors'][] = 'Shop chưa có bảng giá nào (H-15) — tạo bảng giá ở tsm trước.';
        } else {
            $remote_company = '';
            foreach ($rlists as $code => $r) {
                if (!empty($r['isCompany'])) { $remote_company = (string) $code; break; }
            }
            foreach (array_filter([$company, $cur && $cur !== $company ? $cur : null]) as $l) {
                $code = (string) $l['price_list_code'];
                if (isset($rlists[$code])) {
                    $out['skipped'][] = "bảng giá {$code} đã có";
                    continue;
                }
                $is_company = $l === $company && $remote_company === '';
                if ($l === $company && $remote_company !== '') {
                    $out['notes'][] = "Kế toán đã có bảng giá công ty {$remote_company} — bảng {$code} tạo như bảng thường (không đổi bảng giá công ty của kế toán).";
                }
                $act("Tạo bảng giá {$code} \"" . ($l['price_list_name'] ?: $code) . '"' . ($is_company ? ' (bảng giá công ty)' : ''), function () use ($c, $code, $l, $is_company) {
                    return $c->put_json('/api/v1/catalog/price-lists/' . rawurlencode($code), ['name' => mb_substr((string) ($l['price_list_name'] ?: $code), 0, 255),
                        'isCompany' => $is_company, 'isActive' => (int) $l['price_list_status'] !== 0, 'note' => mb_substr((string) $l['price_list_note'], 0, 500)]);
                });
            }
        }

        // 3. chi nhánh
        $bc = class_exists('TGS_Acc_Source') ? (string) TGS_Acc_Source::branch_of_blog($blog_id) : '';
        $branches = self::index($c->get('/api/v1/branches'), 'code');
        if ($bc === '') {
            $out['errors'][] = "Blog {$blog_id} chưa có mã chi nhánh (tgs_acc_branch_code) — khai ở Cổng kế toán rồi chạy lại.";
        } elseif (is_wp_error($branches)) {
            $out['errors'][] = 'Không đọc được chi nhánh của kế toán — ' . $branches->get_error_message();
        } elseif (isset($branches[$bc])) {
            $out['skipped'][] = "chi nhánh {$bc} đã có";
        } else {
            $name = class_exists('TGS_Acc_Site') ? (string) TGS_Acc_Site::name($blog_id) : '';
            $act("Tạo chi nhánh {$bc} \"" . ($name ?: $bc) . '"', function () use ($c, $bc, $name, $blog_id) {
                return $c->post_json('/api/v1/branches', ['code' => $bc, 'name' => mb_substr($name ?: $bc, 0, 255), 'externalRef' => 'blog:' . $blog_id]);
            });
        }

        // 4. gán chi nhánh cho bảng giá của blog
        $own = $cur ?: $company;
        if ($own && $bc !== '' && !is_wp_error($branches)) {
            $code = (string) $own['price_list_code'];
            $have = isset($rlists[$code]) ? array_map('strval', (array) ($rlists[$code]['branchCodes'] ?? [])) : [];
            $assigned_elsewhere = '';
            foreach ($rlists as $lc => $r) {
                if ((string) $lc !== $code && in_array($bc, array_map('strval', (array) ($r['branchCodes'] ?? [])), true)) { $assigned_elsewhere = (string) $lc; }
            }
            if (in_array($bc, $have, true)) {
                $out['skipped'][] = "chi nhánh {$bc} đã áp bảng giá {$code}";
            } elseif ($assigned_elsewhere !== '') {
                $out['notes'][] = "Chi nhánh {$bc} đang áp bảng giá {$assigned_elsewhere} ở kế toán — không đổi (shop dùng {$code}; đổi ở kế toán nếu cần).";
            } else {
                $all = array_values(array_unique(array_merge($have, [$bc])));
                $act("Áp bảng giá {$code} cho chi nhánh {$bc}", function () use ($c, $code, $all) {
                    return $c->put_json('/api/v1/catalog/price-lists/' . rawurlencode($code) . '/branches', ['branchCodes' => $all]);
                });
            }
        }
        return self::finish($out);
    }

    private static function finish(array $out)
    {
        $out['ok'] = !$out['errors'];
        return $out;
    }

    /** @return array|WP_Error [key => row] từ phản hồi get() (data là mảng, hoặc {items}) */
    private static function index($res, $key)
    {
        if (is_wp_error($res)) {
            return $res;
        }
        $rows = (array) ($res['data'] ?? []);
        if (isset($rows['items']) && is_array($rows['items'])) {
            $rows = $rows['items'];
        }
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && isset($r[$key])) {
                $out[(string) $r[$key]] = $r;
            }
        }
        return $out;
    }
}
