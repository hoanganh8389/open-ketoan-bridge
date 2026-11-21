<?php
/**
 * Trang "Kế toán" cũ (nhúng iframe) — đã bỏ 23/10/2026: phần mềm kế toán chạy độc lập. Bình thường không tới được trang
 * này (TGS_Acc_Embed::redirect_old_view chuyển sang ERP ở admin_init); chỉ hiện khi chưa cấu hình địa chỉ ERP.
 */
if (!defined('ABSPATH')) {
    exit;
}
$tgs_open = TGS_Acc_Embed::open_url(isset($_GET['p']) ? sanitize_text_field(wp_unslash($_GET['p'])) : '/');
?>
<div class="alert alert-<?php echo $tgs_open === '' ? 'warning' : 'info'; ?> m-3">
    <?php if ($tgs_open === '') : ?>
        Chưa cấu hình địa chỉ phần mềm kế toán. Vào <strong>Quản trị → Cổng kế toán → Cài đặt</strong>, điền "Địa chỉ mở trên trình duyệt" rồi lưu.
    <?php else : ?>
        Phần mềm kế toán chạy riêng. <a href="<?php echo esc_url($tgs_open); ?>" target="_blank" rel="noopener">Mở phần mềm kế toán</a>.
    <?php endif; ?>
</div>
