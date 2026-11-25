<?php
/**
 * Plugin Name: Open Kế toán Bridge (Cổng kế toán)
 * Description: Đẩy chứng từ bán hàng (phiếu xuất bán VAT, bill nội bộ, phiếu điều chỉnh giảm) sang phần mềm kế toán qua API chuẩn. Tiền theo đúng luật của màn Quản lý VAT (khớp hoá đơn đã phát hành).
 * Version: 0.6.0
 * Author: TGS
 * Network: true
 *
 * ─── CẤU HÌNH (wp-config.php) ───────────────────────────────────────────────
 *
 *   define('TGS_KETOAN_API_URL', 'http://localhost:8088');   // địa chỉ phần mềm kế toán
 *   define('TGS_KETOAN_API_KEY', 'kt_…');                     // khoá API của đơn vị bên kế toán
 *   define('TGS_KETOAN_WEB_URL', 'https://ketoan.…');         // (tuỳ chọn) địa chỉ người dùng mở trên trình duyệt
 *
 *   (hoặc khai ở trang Quản trị → Kế toán → Cổng kế toán → Cài đặt; hằng wp-config được ưu tiên)
 *
 * ─── GỬI ────────────────────────────────────────────────────────────────────
 *
 *   - Nút "⇄ Đẩy sang Kế toán" ở màn Quản lý phiếu xuất bán (VAT) / phiếu điều chỉnh của tgs-bc-tk;
 *   - Trang Cổng kế toán → tab "Gửi chứng từ";
 *   - Dòng lệnh / lịch chạy:
 *
 *   php wp-content/plugins/open-ketoan-bridge/tools/day-ke-toan.php --blog=95 --from=2026-10-01 --to=2026-10-09 [--dry]
 *
 * Gửi lại bao nhiêu lần cũng không ghi trùng: mỗi chứng từ có khoá cố định; nội dung
 * đổi thì tự tăng phiên bản (bên kế toán ghi số âm bút toán cũ, ghi bút toán mới);
 * phiếu đã gửi mà nay không còn (bị xoá) thì tự gửi lệnh huỷ.
 *
 * ─── CẤU TRÚC ───────────────────────────────────────────────────────────────
 *   includes/class-tgs-acc-config.php       cấu hình kết nối (wp-config ưu tiên, sau đó site option)
 *   includes/class-tgs-acc-api-client.php   gọi API (X-Api-Key, khuôn { ok, data|error }), nhật ký 60 lượt gọi
 *   includes/class-tgs-acc-source.php       đọc dữ liệu shop: dòng VAT (tgs-bc-tk) + phiếu thu
 *   includes/class-tgs-acc-source-pos.php   nguồn thứ hai: phiếu bán / hoàn đọc thẳng sổ POS khi không có tgs-bc-tk
 *   includes/class-tgs-acc-selfcheck.php    tự kiểm SC-1…SC-11 theo cặp blog → tenant qua API (wp tgs-acc selfcheck, luật I-8)
 *   includes/class-tgs-acc-code-map.php     dịch mã phương thức / lý do / thuế theo bảng ánh xạ của blog (wp tgs-acc map, S001-MD3)
 *   includes/class-tgs-acc-mapper.php       dòng VAT → khuôn chứng từ của phần mềm kế toán
 *   includes/class-tgs-acc-push-log.php     nhật ký gửi (bảng global), quyết định phiên bản
 *   includes/class-tgs-acc-pusher.php       điều phối: đọc → dựng → so nhật ký → gửi → ghi kết quả (+ ghi lượt gửi)
 *   includes/class-tgs-acc-catalog.php      DANH MỤC API: mọi luồng gửi sang kế toán (thêm luồng mới khai ở đây)
 *   includes/class-tgs-acc-admin.php        trang Cổng kế toán + nút ở màn VAT + các AJAX
 *   includes/class-tgs-acc-gate.php         CỔNG CHỜ: REST /wp-json/tgs-acc/v1/pending để ERP (trạm ACC14) kéo chứng từ
 *   includes/class-tgs-acc-catalog-sync.php DANH MỤC HÀNG: kéo mặt hàng / ĐVT / bảng giá / giá từ kế toán vào các bảng global
 *                                           (bật catalog_from_erp: cron 5 phút + khoá sửa bên này); nạp ban đầu: tools/danh-muc-hang.php
 *   includes/class-tgs-acc-events.php       SỰ KIỆN: hứng mọi lần ghi phiếu → JSONL theo ngày (uploads/tgs-acc-events) → gửi ERP tức thời,
 *                                           lỗi thì cron gửi lại; plugin tự bắn: do_action('tgs_acc/event', 'ledger', $id)
 *   admin/page-cong-ke-toan.php             khung trang; assets/*.js|css giao diện
 *   tools/day-ke-toan.php                   công cụ dòng lệnh (gửi)
 *   tools/doi-chieu-ke-toan.php             công cụ dòng lệnh (đối chiếu hai bên, chỉ đọc)
 *   docs/MO-RONG.md                         hướng dẫn cho team: thêm luồng chứng từ mới
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_ACC_BRIDGE_VERSION', '0.6.0'); // OKT-SET-2/3 — khoá cài đặt (chain), ping, sơ đồ
define('TGS_ACC_BRIDGE_PATH', plugin_dir_path(__FILE__));

require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-site.php'; // [2026-11-23 02:10 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-2 — lớp bao kiểu cài (một site / multisite), nạp trước mọi lớp khác
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-profile.php'; // [2026-11-23 06:40 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-9 — hồ sơ mô hình triển khai (bizcity · chain · single), khai rõ
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-config.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-api-client.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-install.php'; // [2026-11-23 12:10 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-2 / OKT-SET-3 — khoá cài đặt (chain), đăng ký cửa hàng, ping 5 phút, sơ đồ kết nối
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-source.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-source-pos.php'; // [2026-11-21 08:11 PM Johnny Chu - Chu Hoàng Anh] S002-4 — nguồn phiếu bán / hoàn từ sổ POS khi không có tgs-bc-tk (chỉ đọc)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-mapper.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-push-log.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-pusher.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-catalog.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-lists.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-admin.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-gate.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-events.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-code-map.php'; // [2026-11-22 01:10 PM Johnny Chu - Chu Hoàng Anh] S001-MD3 — dịch mã theo bảng ánh xạ của blog (open-pos-backend {p}tgs_okt_map)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-selfcheck.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-connection.php'; // [2026-11-25 08:20 AM Johnny Chu - Chu Hoàng Anh] SW2-1 / B001-C6 — câu hỏi kết nối theo blog (bốn điều kiện, đệm 60 giây)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-blog-keys.php'; // [2026-11-25 10:05 AM Johnny Chu - Chu Hoàng Anh] SW2-1 / B001-C4 — màn Khoá theo shop
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-reconcile.php'; // [2026-11-22 09:45 PM Johnny Chu - Chu Hoàng Anh] OKT-H4 — đối chiếu WP ↔ kế toán (chỉ đọc), SC-12
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-trace.php'; // [2026-11-22 03:10 PM Johnny Chu - Chu Hoàng Anh] OKT-TRACE — trace chiều bán của một phiếu (chỉ đọc) // [2026-11-22 11:30 AM Johnny Chu - Chu Hoàng Anh] OKT-SC-1 — tự kiểm theo cặp blog → tenant qua API (luật I-8)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-tenant-prepare.php'; // [2026-11-24 08:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-1 — dựng sẵn tenant (cây nhóm có mã SKU, bảng giá, chi nhánh)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-catalog-dryrun.php'; // [2026-11-24 09:40 PM Johnny Chu - Chu Hoàng Anh] SW4b-2 — kiểm thử danh mục bằng dryRun của kế toán
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-switch.php'; // [2026-11-25 02:30 PM Johnny Chu - Chu Hoàng Anh] SW2-2 — chuyển nguồn lần đầu local → ketoan
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-cli.php'; // [2026-11-21 09:00 PM Johnny Chu - Chu Hoàng Anh] S002-8, 11, 13 — wp tgs-acc status / preflight / pause / resume
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-embed.php';
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-catalog-sync.php'; // danh mục hàng lấy từ phần mềm kế toán
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-warehouse-sync.php'; // phân kho lấy từ phần mềm kế toán (DM12)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-ledger-mirror.php'; // lõi ghi bản sao chứng từ kế toán → local_ledger (mọi shop, không cần plugin POS)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-trade-mapper.php'; // phiếu bán kế toán lập (TradeDoc) → bản sao
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-ketoan-events.php'; // cửa nhận sự kiện kế toán /wp-json/tgs-acc/v1/ketoan-events
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-branch-sync.php'; // chi nhánh bên kế toán tự có khi gắn site (phân cấp / site mới)
require_once TGS_ACC_BRIDGE_PATH . 'includes/class-tgs-acc-supplier-sync.php'; // NCC lấy từ phần mềm kế toán (đồng bộ vào wp_global_supplier) // menu "Kế toán": nhúng phần mềm kế toán trong trang quản trị

add_action('plugins_loaded', ['TGS_Acc_Admin', 'init']);
add_action('plugins_loaded', ['TGS_Acc_Embed', 'init']);
TGS_Acc_Supplier_Sync::init();
TGS_Acc_Warehouse_Sync::init();
TGS_Acc_Branch_Sync::init();
TGS_Acc_Ketoan_Events::init();
TGS_Acc_Catalog_Sync::init();
TGS_Acc_Gate::init();
// Gửi tức thời theo sự kiện: hứng mọi lần ghi phiếu, ghi JSONL theo ngày, gửi ERP cuối request, cron gửi lại khi lỗi.
TGS_Acc_Events::init();

// Tạo/nâng cấp bảng nhật ký gửi khi cần (rẻ: chỉ so một option).
add_action('admin_init', ['TGS_Acc_Push_Log', 'maybe_install']);

// [2026-11-22 02:30 PM Johnny Chu - Chu Hoàng Anh] OKT-SC-2 — tắt plugin thì gỡ các lịch của nó (gửi lại, kéo trạng thái, tự kiểm hằng ngày).
// [2026-11-23 02:40 AM Johnny Chu - Chu Hoàng Anh] OKT-TOPO-2 — tạo bảng nhật ký gửi lúc kích hoạt (một site / máy mới: trạng thái, tự kiểm đọc bảng này trước lần gửi đầu).
register_activation_hook(__FILE__, function () {
    if (class_exists('TGS_Acc_Push_Log')) {
        TGS_Acc_Push_Log::maybe_install();
    }
});
register_deactivation_hook(__FILE__, function () {
    foreach ([TGS_Acc_Events::CRON_HOOK, TGS_Acc_Events::STATUS_HOOK, TGS_Acc_Selfcheck::DAILY_HOOK, TGS_Acc_Install::PING_HOOK] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
});
