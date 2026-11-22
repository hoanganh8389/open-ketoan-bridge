<?php
/**
 * CỔNG KẾ TOÁN — Quản trị → Kế toán → Cổng kế toán.
 *
 * Một chỗ cho team xem hệ thống bán hàng gửi gì sang phần mềm kế toán:
 *   Tổng quan · Gửi chứng từ · Nhật ký chứng từ · Lượt gửi · Danh mục API · Lượt gọi API · Cài đặt.
 * Phần số liệu do assets/cong-ke-toan.js nạp qua AJAX (TGS_Acc_Admin); danh mục API dựng sẵn ở đây.
 *
 * @package tgs_accounting_bridge
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!TGS_Acc_Admin::can_view()) {
    echo '<div style="padding:24px">Không có quyền dùng Cổng kế toán.</div>';
    return;
}
$tacc_cfg = TGS_Acc_Config::for_display();
$tacc_catalog = TGS_Acc_Catalog::all();
$tacc_today = current_time('Y-m-d');
$tacc_month1 = current_time('Y-m-01');
$tacc_status = ['live' => ['Đang chạy', 'ok'], 'planned' => ['Dự kiến', 'muted']];
?>
<div class="tacc" id="taccApp">
    <div class="tacc-head">
        <div>
            <h2>Cổng kế toán <small>v<?php echo esc_html(TGS_ACC_BRIDGE_VERSION); ?></small></h2>
            <div class="tacc-muted">Chứng từ đã duyệt của các shop (bán, hoàn, thu chi theo đơn, mua, trả NCC, chuyển kho, điều chỉnh tồn) sang phần mềm kế toán, vào trạng thái Chờ định khoản. Gửi lại bao nhiêu lần cũng không ghi trùng; phiếu sửa thì lên phiên bản, phiếu xoá thì vào Chờ huỷ để kế toán xử lý.</div>
        </div>
        <div class="tacc-head__right">
            <span class="tacc-pill <?php echo $tacc_cfg['ready'] ? 'ok' : 'bad'; ?>" id="taccReady"><?php echo $tacc_cfg['ready'] ? 'Đã cấu hình' : 'Chưa cấu hình'; ?></span>
            <?php if ($tacc_cfg['web_url'] !== '') : ?>
                <a class="tacc-btn ghost" href="<?php echo esc_url($tacc_cfg['web_url']); ?>" target="_blank" rel="noopener">Mở phần mềm kế toán ↗</a>
            <?php endif; ?>
        </div>
    </div>

    <nav class="tacc-tabs" role="tablist">
        <button type="button" data-tab="overview" class="on">Tổng quan</button>
        <button type="button" data-tab="selfcheck">Tự kiểm</button>
        <button type="button" data-tab="trace">Trace</button>
        <button type="button" data-tab="events">Sự kiện (tức thời)</button>
        <button type="button" data-tab="push">Gửi chứng từ</button>
        <button type="button" data-tab="log">Nhật ký chứng từ</button>
        <button type="button" data-tab="runs">Lượt gửi</button>
        <button type="button" data-tab="api">Danh mục API</button>
        <button type="button" data-tab="lists">Danh mục kế toán</button>
        <button type="button" data-tab="calls">Lượt gọi API</button>
        <button type="button" data-tab="topology">Sơ đồ kết nối</button><!-- [2026-11-23 12:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-3 -->
        <button type="button" data-tab="settings">Cài đặt</button>
    </nav>

    <!-- TỔNG QUAN -->
    <section class="tacc-panel" data-panel="overview">
        <div class="tacc-cards" id="taccCards"></div>
        <div class="tacc-card">
            <div class="tacc-card__title">Theo shop <span class="tacc-muted">— số chứng từ trong nhật ký gửi</span></div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccShopTable">
                <thead><tr><th>Shop</th><th>Tên</th><th class="r">Đã vào sổ</th><th class="r">Lỗi</th><th class="r">Đã huỷ</th><th>Chứng từ mới nhất</th><th>Gửi lần cuối</th><th>Kết nối</th><th></th></tr></thead>
                <tbody><tr><td colspan="9" class="tacc-muted">Đang tải…</td></tr></tbody>
            </table></div>
        </div>
        <div class="tacc-card">
            <div class="tacc-card__title">Lượt gửi gần đây</div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccRecentRuns"><tbody><tr><td class="tacc-muted">Đang tải…</td></tr></tbody></table></div>
        </div>
    </section>

    <!-- GỬI CHỨNG TỪ -->
    <section class="tacc-panel" data-panel="push" hidden>
        <div class="tacc-card">
            <div class="tacc-card__title">Gửi chứng từ sang kế toán</div>
            <div class="tacc-muted" style="margin-bottom:10px">Chọn shop và khoảng ngày → <b>Xem trước</b> (chỉ dựng, không gửi) → <b>Gửi</b>. Khoảng dài được chia thành từng đoạn <span id="taccChunkLbl"><?php echo (int) $tacc_cfg['chunk_days']; ?></span> ngày, gửi lần lượt từng shop. Chứng từ không đổi được bỏ qua.</div>
            <div class="tacc-form">
                <label>Từ ngày <input type="date" id="taccFrom" value="<?php echo esc_attr($tacc_month1); ?>"></label>
                <label>Đến ngày <input type="date" id="taccTo" value="<?php echo esc_attr($tacc_today); ?>"></label>
                <label class="tacc-check"><input type="checkbox" id="taccForce"> Gửi lại cả chứng từ không đổi</label>
            </div>
            <div class="tacc-shoppick" id="taccShopPick"></div>
            <div class="tacc-actions">
                <button type="button" class="tacc-btn ghost" id="taccDry">Xem trước</button>
                <button type="button" class="tacc-btn" id="taccGo">Gửi sang kế toán</button>
                <button type="button" class="tacc-btn danger" id="taccStop" hidden>Dừng</button>
                <span class="tacc-muted" id="taccPushNote"></span>
            </div>
            <div class="tacc-progress"><div id="taccBar"></div></div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccPushTable">
                <thead><tr><th>Shop</th><th>Đoạn ngày</th><th>Trạng thái</th><th class="r">Dựng</th><th class="r">Cần gửi</th><th class="r">Không đổi</th><th class="r">Vào sổ</th><th class="r">Lỗi</th><th class="r">Huỷ</th><th>Ghi chú</th></tr></thead>
                <tbody></tbody>
                <tfoot></tfoot>
            </table></div>
        </div>
    </section>

    <!-- NHẬT KÝ CHỨNG TỪ -->
    <section class="tacc-panel" data-panel="log" hidden>
        <div class="tacc-card">
            <div class="tacc-form">
                <label>Shop <select id="taccLogBlog"><option value="">Tất cả</option></select></label>
                <label>Trạng thái <select id="taccLogStatus"><option value="">Tất cả</option><option value="posted">Đã vào sổ</option><option value="error">Lỗi</option><option value="cancelled">Đã huỷ</option></select></label>
                <label>Loại <select id="taccLogType"><option value="">Tất cả</option><option value="sale">Phiếu bán</option><option value="sale_return">Phiếu hoàn</option><option value="customer_payment">Phiếu thu / chi trả khách</option><option value="purchase">Nhập mua</option><option value="purchase_return">Trả NCC</option><option value="transfer">Chuyển kho</option><option value="stock_adjustment">Điều chỉnh kho</option><option value="cash_transfer">Chuyển tiền</option></select></label>
                <label>Từ <input type="date" id="taccLogFrom" value="<?php echo esc_attr($tacc_month1); ?>"></label>
                <label>Đến <input type="date" id="taccLogTo" value="<?php echo esc_attr($tacc_today); ?>"></label>
                <label>Số phiếu / khoá <input type="search" id="taccLogQ" placeholder="VD: 1AA20505"></label>
                <button type="button" class="tacc-btn" id="taccLogGo">Xem</button>
            </div>
            <div class="tacc-muted" id="taccLogInfo"></div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccLogTable">
                <thead><tr><th>Shop</th><th>Ngày CT</th><th>Số phiếu</th><th>Loại</th><th>Khoá chứng từ</th><th class="r">Phiên bản</th><th>Trạng thái</th><th>Thông báo</th><th>Gửi lúc</th><th></th></tr></thead>
                <tbody></tbody>
            </table></div>
            <div class="tacc-pager" id="taccLogPager"></div>
        </div>
    </section>

    <!-- LƯỢT GỬI -->
    <section class="tacc-panel" data-panel="runs" hidden>
        <div class="tacc-card">
            <div class="tacc-form">
                <label>Shop <select id="taccRunBlog"><option value="">Tất cả</option></select></label>
                <button type="button" class="tacc-btn" id="taccRunGo">Xem</button>
                <span class="tacc-muted">Mỗi lần bấm nút, mỗi lần lịch chạy, mỗi lần chạy dòng lệnh là một lượt (200 lượt gần nhất).</span>
            </div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccRunTable"><tbody></tbody></table></div>
        </div>
    </section>

    <!-- DANH MỤC API -->
    <section class="tacc-panel" data-panel="api" hidden>
        <div class="tacc-card">
            <div class="tacc-card__title">Hệ thống bán hàng gọi những API nào của phần mềm kế toán</div>
            <div class="tacc-muted">Mọi lượt gọi đều kèm header <code>X-Api-Key</code>; phản hồi theo khuôn <code>{ ok: true, data, meta }</code> hoặc <code>{ ok: false, error: { code, message } }</code>.
                Tài liệu đầy đủ của từng API: <code>&lt;địa chỉ kế toán&gt;/api/docs</code>. Thêm luồng mới: xem <code>includes/class-tgs-acc-catalog.php</code> và <code>docs/MO-RONG.md</code>.</div>
        </div>
        <?php
        $tacc_groups = [];
        foreach ($tacc_catalog as $it) {
            $tacc_groups[$it['group'] ?? 'Khác'][] = $it;
        }
        foreach ($tacc_groups as $tacc_g => $tacc_items) : ?>
            <h3 class="tacc-h3"><?php echo esc_html($tacc_g); ?></h3>
            <?php foreach ($tacc_items as $it) :
                $st = $tacc_status[$it['status'] ?? 'live'] ?? ['?', 'muted']; ?>
                <details class="tacc-api" <?php echo ($it['status'] ?? '') === 'live' && ($it['group'] ?? '') === 'Chứng từ' ? 'open' : ''; ?>>
                    <summary>
                        <span class="tacc-method m-<?php echo esc_attr(strtolower(strtok((string) $it['method'], ' '))); ?>"><?php echo esc_html($it['method']); ?></span>
                        <code class="tacc-path"><?php echo esc_html($it['path']); ?></code>
                        <b><?php echo esc_html($it['title']); ?></b>
                        <span class="tacc-pill <?php echo esc_attr($st[1]); ?>"><?php echo esc_html($st[0]); ?></span>
                        <span class="tacc-scope"><?php echo esc_html($it['scope']); ?></span>
                    </summary>
                    <div class="tacc-api__body">
                        <dl>
                            <dt>Khi nào gọi</dt><dd><?php echo esc_html($it['trigger']); ?></dd>
                            <dt>Dữ liệu lấy từ</dt><dd><?php echo esc_html($it['source']); ?></dd>
                            <dt>Khoá chống trùng</dt><dd><code><?php echo esc_html($it['key']); ?></code></dd>
                        </dl>
                        <?php if (!empty($it['notes'])) : ?>
                            <ul class="tacc-notes"><?php foreach ($it['notes'] as $n) : ?><li><?php echo esc_html($n); ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                        <?php if (!empty($it['fields'])) : ?>
                            <table class="tacc-table tacc-fields"><thead><tr><th>Trường gửi</th><th>Lấy từ</th></tr></thead><tbody>
                            <?php foreach ($it['fields'] as $f => $src) : ?><tr><td><code><?php echo esc_html($f); ?></code></td><td><?php echo esc_html($src); ?></td></tr><?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                        <?php if (!empty($it['sample'])) : ?>
                            <div class="tacc-sample" data-type="<?php echo esc_attr($it['sample']); ?>">
                                <div class="tacc-form">
                                    <label>Shop <select class="tacc-sample__blog"></select></label>
                                    <label>Ngày <input type="date" class="tacc-sample__date" value="<?php echo esc_attr($tacc_today); ?>"></label>
                                    <label>Số phiếu (để trống = phiếu đầu tiên) <input type="text" class="tacc-sample__no"></label>
                                    <button type="button" class="tacc-btn ghost tacc-sample__go">Xem dữ liệu thật sẽ gửi</button>
                                </div>
                                <div class="tacc-muted tacc-sample__info"></div>
                                <pre class="tacc-pre" hidden></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach;
        endforeach; ?>
    </section>

    <!-- DANH MỤC KẾ TOÁN -->
    <section class="tacc-panel" data-panel="lists" hidden>
        <div class="tacc-card">
            <div class="tacc-form">
                <label>Danh mục <select id="taccListKind"></select></label>
                <label>Tìm <input type="search" id="taccListQ" placeholder="mã, tên…"></label>
                <button type="button" class="tacc-btn" id="taccListFresh">Đọc lại từ kế toán</button>
                <button type="button" class="tacc-btn" id="taccListCsv">Tải CSV</button>
                <span class="tacc-muted" id="taccListInfo"></span>
            </div>
            <div class="tacc-muted" id="taccListUse" style="margin:4px 0 8px"></div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccListTable"><tbody></tbody></table></div>
            <div class="tacc-muted" style="margin-top:8px">Chỉ đọc, lấy trực tiếp từ phần mềm kế toán (nhớ 10 phút). Thêm / sửa mã thì làm bên phần mềm kế toán. Trong code: <code>TGS_Acc_Lists::rows('reasons')</code>.</div>
        </div>
    </section>

    <!-- LƯỢT GỌI API -->
    <section class="tacc-panel" data-panel="calls" hidden>
        <div class="tacc-card">
            <div class="tacc-form"><button type="button" class="tacc-btn" id="taccCallsGo">Tải lại</button><span class="tacc-muted">60 lượt gọi gần nhất (mọi nguồn: nút, lịch, dòng lệnh).</span></div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccCallTable"><tbody></tbody></table></div>
        </div>
    </section>

    <!-- TRACE (OKT-TRACE) -->
    <section class="tacc-panel" data-panel="trace" hidden>
        <div class="tacc-card">
            <div class="tacc-card__title">Trace một phiếu <span class="tacc-muted">— sổ POS → sự kiện → hàng chờ → chứng từ (trước / sau ánh xạ mã) → nhật ký gửi → kế toán. Chỉ đọc, không gửi.</span></div>
            <div class="tacc-form">
                <label>Shop <select id="taccTrBlog"></select></label>
                <label>Mã chứng từ hoặc id phiếu <input type="text" id="taccTrRef" placeholder="btsoft:2:sale:123 hoặc 123" style="min-width:260px"></label>
                <label>Sự kiện <select id="taccTrDays"><option value="7">7 ngày</option><option value="30">30 ngày</option><option value="90">90 ngày</option></select></label>
                <label class="tacc-check"><input type="checkbox" id="taccTrRemote"> Hỏi trạng thái ở kế toán</label>
                <button type="button" class="tacc-btn" id="taccTrGo">Trace</button>
                <span class="tacc-muted" id="taccTrInfo"></span>
            </div>
            <div id="taccTrOut"></div>
        </div>
    </section>

    <!-- TỰ KIỂM (OKT-SC-2, luật I-8) -->
    <section class="tacc-panel" data-panel="selfcheck" hidden>
        <div class="tacc-card">
            <div class="tacc-card__title">Tự kiểm blog → tenant <span class="tacc-muted">— mỗi shop tự chứng minh qua đúng API, bằng đúng khoá, đọc đúng sổ của shop (luật I-8). Không ghi gì vào kế toán.</span></div>
            <div class="tacc-form">
                <button type="button" class="tacc-btn" id="taccScAll">Chạy tự kiểm mọi shop</button>
                <button type="button" class="tacc-btn ghost" id="taccScReload">Tải lại</button>
                <span class="tacc-muted" id="taccScInfo"></span>
            </div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccScTable"><tbody><tr><td class="tacc-muted">Đang tải…</td></tr></tbody></table></div>
            <div class="tacc-muted" style="margin-top:6px">✓ đạt · ! cảnh báo, vẫn gửi · ✕ đỏ · — chưa chạy. Sai / trùng tenant → tự tạm dừng gửi (quầy vẫn bán). Bấm tên shop để xem từng phép.</div>
        </div>
        <div class="tacc-card" id="taccScDetail" hidden></div>
    </section>

    <!-- SỰ KIỆN -->
    <section class="tacc-panel" data-panel="events" hidden>
        <div class="tacc-card">
            <div class="tacc-card__title">Sự kiện chứng từ <span class="tacc-muted">— mỗi lần lưu / sửa / duyệt / xoá phiếu ở bất kỳ màn nào được ghi lại và gửi sang kế toán ngay</span></div>
            <div class="tacc-form">
                <label>Ngày <input type="date" id="taccEvDate"></label>
                <button type="button" class="tacc-btn" id="taccEvGo">Tải</button>
                <button type="button" class="tacc-btn ghost" id="taccEvRetry">Gửi lại các nhóm lỗi ngay</button>
                <span class="tacc-muted" id="taccEvInfo"></span>
            </div>
            <div class="tacc-muted" id="taccEvWaiting" style="margin:6px 0"></div>
            <div class="tacc-tablewrap"><table class="tacc-table" id="taccEvTable"><tbody></tbody></table></div>
        </div>
    </section>

    <!-- SƠ ĐỒ KẾT NỐI ([2026-11-23 12:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-3 — open-setup 82 B-7: site → bridge → máy open-ketoan → tenant, ping từng site, từng máy) -->
    <section class="tacc-panel" data-panel="topology" hidden>
        <div class="tacc-card" id="taccTopo"><span class="tacc-muted">Đang tải…</span></div>
    </section>

    <!-- CÀI ĐẶT -->
    <section class="tacc-panel" data-panel="settings" hidden>
        <!-- [2026-11-23 12:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-3 — ma trận ba mô hình (luật tối thượng P-0; open-setup 82 B-2) -->
        <div class="tacc-card" id="taccModel"><span class="tacc-muted">Đang tải…</span></div>
        <div class="tacc-card">
            <div class="tacc-card__title">Kết nối</div>
            <div class="tacc-grid">
                <label>Địa chỉ phần mềm kế toán
                    <input id="taccUrl" value="<?php echo esc_attr($tacc_cfg['url']); ?>" placeholder="http://127.0.0.1:8088" <?php disabled($tacc_cfg['url_source'] === 'wp-config'); ?>>
                    <?php if ($tacc_cfg['url_source'] === 'wp-config') : ?><small>Đặt bằng TGS_KETOAN_API_URL trong wp-config.php — sửa ở đó.</small><?php endif; ?>
                </label>
                <label>Khoá API <?php echo $tacc_cfg['key_masked'] !== '' ? '(đang dùng ' . esc_html($tacc_cfg['key_masked']) . ')' : '(chưa có)'; ?>
                    <input id="taccKey" type="password" autocomplete="new-password" placeholder="Để trống = giữ khoá cũ; gõ - để xoá" <?php disabled($tacc_cfg['key_source'] === 'wp-config'); ?>>
                    <?php if ($tacc_cfg['key_source'] === 'wp-config') : ?><small>Đặt bằng TGS_KETOAN_API_KEY trong wp-config.php — sửa ở đó.</small><?php endif; ?>
                </label>
                <label>Địa chỉ mở trên trình duyệt (cho người dùng)
                    <input id="taccWebUrl" value="<?php echo esc_attr($tacc_cfg['web_source'] !== '' ? $tacc_cfg['web_url'] : ''); ?>" placeholder="https://ketoan.ten-mien.com" <?php disabled($tacc_cfg['web_source'] === 'wp-config'); ?>>
                    <?php if ($tacc_cfg['web_source'] === 'wp-config') : ?><small>Đặt bằng TGS_KETOAN_WEB_URL trong wp-config.php — sửa ở đó.</small><?php else : ?><small style="color:#64748b">Khác địa chỉ trên khi máy chủ gọi nội bộ (127.0.0.1). Dùng cho nút "Mở phần mềm kế toán".</small><?php endif; ?>
                </label>
                <label>Khoá đăng nhập bằng tài khoản WordPress <?php echo $tacc_cfg['sso_secret_masked'] !== '' ? '(đang dùng ' . esc_html($tacc_cfg['sso_secret_masked']) . ')' : '(chưa có — menu Kế toán phải đăng nhập tay)'; ?>
                    <input id="taccSsoSecret" type="password" autocomplete="new-password" placeholder="Để trống = giữ; gõ - để xoá; ≥ 24 ký tự" <?php disabled($tacc_cfg['sso_secret_locked']); ?>>
                    <small style="color:#64748b">Giống hệt biến WP_SSO_SECRET của phần mềm kế toán.<?php echo $tacc_cfg['sso_secret_locked'] ? ' Đặt bằng TGS_KETOAN_SSO_SECRET trong wp-config.php.' : ''; ?></small>
                </label>
                <label>Mã đơn vị kế toán (đăng nhập bằng tài khoản WordPress)
                    <input id="taccSsoTenant" value="<?php echo esc_attr($tacc_cfg['sso_tenant']); ?>" placeholder="Trống = đơn vị mặc định bên kế toán">
                </label>
                <label>Chờ tối đa mỗi lượt gọi (giây)<input id="taccTimeout" type="number" min="10" max="300" value="<?php echo (int) $tacc_cfg['timeout']; ?>"></label>
                <label>Chia khoảng ngày khi gửi (ngày / đoạn)<input id="taccChunk" type="number" min="1" max="31" value="<?php echo (int) $tacc_cfg['chunk_days']; ?>"></label>
                <label>Sổ thuế — địa chỉ phần mềm kế toán (bỏ trống = không gửi sổ thuế)
                    <input id="taccTaxUrl" value="<?php echo esc_attr($tacc_cfg['tax_url']); ?>" placeholder="https://ketoanthue…" <?php disabled($tacc_cfg['tax_url_locked']); ?>>
                    <small>Chỉ gửi phiếu bán / hoàn có số hoá đơn điện tử, tức thời như sổ nội bộ.<?php echo $tacc_cfg['tax_url_locked'] ? ' Đặt bằng TGS_KETOAN_TAX_API_URL trong wp-config.php.' : ''; ?></small></label>
                <label>Sổ thuế — khoá API <?php echo $tacc_cfg['tax_key_masked'] !== '' ? '(đang dùng ' . esc_html($tacc_cfg['tax_key_masked']) . ')' : '(chưa có)'; ?>
                    <input id="taccTaxKey" type="password" autocomplete="new-password" placeholder="Để trống = giữ khoá cũ; gõ - để xoá" <?php disabled($tacc_cfg['tax_key_locked']); ?>></label>
                <label class="tacc-check"><input id="taccRealtime" type="checkbox" <?php checked($tacc_cfg['realtime']); ?> <?php disabled($tacc_cfg['realtime_locked']); ?>> Gửi tức thời theo sự kiện (lưu / sửa / xoá phiếu là gửi kế toán ngay)
                    <?php if ($tacc_cfg['realtime_locked']) : ?><small>Đặt bằng TGS_ACC_REALTIME trong wp-config.php.</small><?php endif; ?></label>
            </div>
            <div class="tacc-actions">
                <button type="button" class="tacc-btn" id="taccSave" <?php disabled(!TGS_Acc_Admin::can_configure()); ?>>Lưu cài đặt</button>
                <button type="button" class="tacc-btn ghost" id="taccPing">Kiểm tra kết nối</button>
                <?php if (TGS_Acc_Admin::can_configure()) : ?><button type="button" class="tacc-btn ghost" id="taccSyncBranches" title="Tạo bên kế toán chi nhánh còn thiếu cho mọi site đã có mã chi nhánh (gắn ở Cấu hình phân cấp multisite). Site mới / đổi phân cấp: tự chạy.">Đồng bộ chi nhánh từ các site</button><?php endif; ?>
                <span class="tacc-muted" id="taccSaveNote"><?php echo TGS_Acc_Admin::can_configure() ? '' : 'Chỉ quản trị cấp cao sửa được cài đặt.'; ?></span>
            </div>
            <div id="taccPingOut"></div>
        </div>
        <?php if (TGS_Acc_Admin::can_configure()) : $tacc_gate_url = TGS_Acc_Gate::url(); ?>
        <div class="tacc-card">
            <div class="tacc-card__title">Cổng chờ cho ERP kéo chứng từ (trạm Định khoản tự động ACC14)</div>
            <div class="tacc-muted">Bên ERP: Định khoản tự động → <b>Kết nối</b> → Thêm kết nối, kiểu <b>Đầu chuẩn</b>, dán đường dẫn và khoá dưới đây. ERP chạy trong Docker trên cùng máy thì thay <code>localhost</code> bằng <code>host.docker.internal</code>.</div>
            <pre class="tacc-pre">Đường dẫn: <?php echo esc_html($tacc_gate_url); ?>

Trong Docker: <?php echo esc_html(preg_replace('#//(localhost|127\.0\.0\.1)([:/])#', '//host.docker.internal$2', $tacc_gate_url)); ?>

Khoá:      <?php echo esc_html(TGS_Acc_Gate::key()); ?></pre>
            <div class="tacc-muted">Đổi khoá: đặt hằng <code>TGS_ACC_GATE_KEY</code> trong wp-config.php. Hiện cổng trả phiếu bán (kể cả bill Z) và phiếu hoàn của khách đang chờ đẩy; báo nhận của ERP ghi vào Nhật ký chứng từ.</div>
        </div>
        <?php endif; ?>
        <div class="tacc-card">
            <div class="tacc-card__title">Lịch chạy tự động (máy chủ)</div>
            <div class="tacc-muted">aaPanel → Cron → Shell script, mỗi 15–30 phút. Bán hàng không phụ thuộc kế toán: kế toán dừng thì lần chạy sau gửi bù.</div>
            <pre class="tacc-pre">cd <?php echo esc_html(untrailingslashit(ABSPATH)); ?> && php wp-content/plugins/<?php echo esc_html(basename(untrailingslashit(TGS_ACC_BRIDGE_PATH))); ?>/tools/day-ke-toan.php --all --days=2</pre>
            <div class="tacc-muted">Đối chiếu số hai bên theo ngày (chỉ đọc):</div>
            <pre class="tacc-pre">php wp-content/plugins/<?php echo esc_html(basename(untrailingslashit(TGS_ACC_BRIDGE_PATH))); ?>/tools/doi-chieu-ke-toan.php --all --from=<?php echo esc_html($tacc_month1); ?> --to=<?php echo esc_html($tacc_today); ?></pre>
        </div>
    </section>
</div>
