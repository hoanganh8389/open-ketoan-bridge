/**
 * [2026-11-23 12:40 PM Johnny Chu - Chu Hoàng Anh] OKT-SET-3 — Cổng kế toán: thẻ "Mô hình triển khai" (tab Cài đặt — ma trận ba lựa chọn,
 * khoá theo môi trường) + tab "Sơ đồ kết nối" (site → bridge → máy open-ketoan → tenant, ping từng site, từng máy).
 * Luật tối thượng P-0: bizcity = khoá riêng từng site · nhiều cửa hàng = một khoá cài đặt, mỗi cửa hàng một tenant · một cửa hàng = blog 1.
 * Dữ liệu: AJAX tgs_acc_topology (TGS_Acc_Admin::ajax_topology → TGS_Acc_Install::topology()).
 */
(function () {
    'use strict';
    var CFG = window.tgsAccBridge;
    var R = window.TaccRunner;
    var app = document.getElementById('taccApp');
    var box = document.getElementById('taccModel');
    var map = document.getElementById('taccTopo');
    if (!CFG || !R || !app || !box || !map) { return; }
    var esc = R.esc;
    var T = null;

    var MODELS = {
        bizcity: ['Cửa hàng trên bizcity.vn', 'Mỗi cửa hàng một phần mềm kế toán riêng, khoá API riêng từng site. Demo mô hình bizcity.'],
        chain: ['Tôi có nhiều cửa hàng', 'Tự cài, multisite. Một máy open-ketoan, một khoá cài đặt; mỗi cửa hàng một sổ (tenant) riêng.'],
        single: ['Tôi có một cửa hàng', 'Tự cài, WordPress một site. Một khoá, một sổ.']
    };
    var KEY = { own: 'khoá riêng', install: 'khoá cài đặt', legacy: 'khoá chung (cũ)', none: 'chưa có khoá' };
    var LINK = { active: ['Đã nối', 'ok'], pending: ['Chờ kế toán nhập mã nối', 'warn'], unregistered: ['Chưa đăng ký', ''], unknown: ['open-ketoan chưa biết — đăng ký lại', 'warn'], revoked: ['Đã thu hồi', 'bad'] };
    var CHECK = { pass: ['✓', 'pass'], warn: ['!', 'warn'], fail: ['✕', 'fail'] };

    function fmtAt(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        return isNaN(d) ? iso : d.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit', day: '2-digit', month: '2-digit' });
    }
    function stale(iso) {
        return !iso || (Date.now() - new Date(iso).getTime()) / 1000 > (T ? T.stale_seconds : 900);
    }
    /** Trạng thái một cửa hàng cho chấm màu: ok | warn | bad | none (+ chữ, không chỉ màu). */
    function shopState(b) {
        if (b.key_mode === 'none') { return ['none', 'chưa có khoá']; }
        if (b.paused) { return ['bad', 'tạm dừng']; }
        if (b.key_mode === 'install' && b.link !== 'active') { return ['warn', (LINK[b.link] || [b.link])[0]]; }
        if (!b.ping) { return ['none', 'chưa ping']; }
        if (!b.ping.ok) { return ['bad', 'lỗi']; }
        if (stale(T.ping_at)) { return ['warn', b.ping.ms + ' ms · trễ']; }
        return ['ok', b.ping.ms + ' ms'];
    }
    function dot(st) { return '<span class="tacc-dot ' + st[0] + '" aria-hidden="true"></span>'; }
    function pill(p) { return '<span class="tacc-pill ' + (p[1] || '') + '">' + esc(p[0]) + '</span>'; }
    function hostName(u) { return String(u || '').replace(/^https?:\/\//, '').replace(/\/$/, ''); }

    function load(op, extra) {
        var data = Object.assign({ op: op || 'get' }, extra || {});
        return R.post('tgs_acc_topology', data).then(function (d) {
            T = d.topology;
            render();
            if (d.message) { note(d.message, false); }
        }).catch(function (e) { note(e.message, true); });
    }
    function note(msg, bad) {
        var n = document.getElementById('taccModelNote');
        if (n) { n.textContent = msg; n.style.color = bad ? '#b91c1c' : ''; }
        var m = document.getElementById('taccTopoNote');
        if (m) { m.textContent = msg; m.style.color = bad ? '#b91c1c' : ''; }
    }

    /* ───────── thẻ Mô hình (tab Cài đặt) ───────── */
    function render() { renderModel(); renderTopo(); }

    function renderModel() {
        var can = !!CFG.canConfig;
        var chips = '<span class="tacc-pill">WordPress: ' + (T.is_ms ? 'multisite' : 'một site') + '</span> <span class="tacc-pill">Số site: ' + T.site_count + '</span> '
            + '<span class="tacc-pill' + (T.locked ? ' run' : '') + '">TGS_ACC_DEPLOYMENT: ' + esc(T.constant || '(chưa khai)') + '</span>';
        var cards = ['bizcity', 'chain', 'single'].map(function (k) {
            var allowed = T.allowed.indexOf(k) >= 0;
            var why = '';
            if (!allowed) {
                why = T.locked ? 'Máy chủ đã khai hằng TGS_ACC_DEPLOYMENT = ' + T.constant + '.'
                    : (k === 'single' ? 'Bản cài này là multisite — chọn "nhiều cửa hàng" hoặc bizcity.' : 'WordPress một site — chỉ chọn được "một cửa hàng".');
            }
            return '<label class="tacc-model' + (T.profile === k ? ' on' : '') + (allowed ? '' : ' off') + '">'
                + '<span><input type="radio" name="taccProfile" value="' + k + '"' + (T.profile === k ? ' checked' : '') + (allowed && can && !T.locked ? '' : ' disabled') + '> <b>' + esc(MODELS[k][0]) + '</b></span>'
                + '<span class="tacc-muted">' + esc(MODELS[k][1]) + '</span>' + (why ? '<small>⚠ ' + esc(why) + '</small>' : '') + '</label>';
        }).join('');
        var step2 = T.profile === 'chain' ? chainStep(can) : (T.profile === '' ? '<div class="tacc-muted">Chọn mô hình ở bước 1 rồi lưu.</div>' : keyStep());
        box.innerHTML = '<div class="tacc-card__title">Mô hình triển khai <span class="tacc-muted">— luật tối thượng P-0: một blog = một tenant = một sổ. Chọn đúng mô hình để bridge dùng đúng loại khoá.</span></div>'
            + '<div class="tacc-h3">Bước 1 · Bạn dùng mô hình nào?</div><div style="margin-bottom:8px">' + chips + '</div>'
            + '<div class="tacc-models">' + cards + '</div>'
            + (can && !T.locked && T.allowed.length > 1 ? '<div class="tacc-actions"><button type="button" class="tacc-btn" id="taccProfileSave">Lưu mô hình</button></div>' : '')
            + '<div class="tacc-h3">Bước 2 · ' + esc(T.profile === 'chain' ? 'Một máy open-ketoan, một khoá cài đặt' : (T.profile === 'bizcity' ? 'Khoá riêng của từng cửa hàng' : 'Khoá của cửa hàng')) + '</div>' + step2
            + '<div class="tacc-h3">Bước 3 · Kiểm</div>'
            + '<div class="tacc-muted">SC-0b mô hình · SC-1 ' + (T.profile === 'chain' ? 'khoá cài đặt + cửa hàng đã nối' : 'khoá của site') + ' · SC-2 tenant khớp mã đã xác nhận · SC-3 hai site cùng tenant = đỏ + tự tạm dừng · ping 5 phút.</div>'
            + '<div class="tacc-actions"><button type="button" class="tacc-btn ghost" data-topo="ping">Ping tất cả</button> <button type="button" class="tacc-btn ghost" data-goto="selfcheck">Mở Tự kiểm</button> <button type="button" class="tacc-btn ghost" data-goto="topology">Mở Sơ đồ kết nối</button> <span class="tacc-muted" id="taccModelNote"></span></div>';
    }

    function chainStep(can) {
        var I = T.install;
        var form = '<div class="tacc-grid">'
            + '<label>Địa chỉ open-ketoan (ô "Địa chỉ phần mềm kế toán" ở thẻ Kết nối)<input value="' + esc(I.url) + '" disabled></label>'
            + '<label>Khoá cài đặt ' + (I.key_masked ? '(đang dùng ' + esc(I.key_masked) + ')' : '(chưa có)')
            + '<input id="taccInstallKey" type="password" autocomplete="new-password" placeholder="okti_… — để trống = giữ; gõ - để xoá"' + (I.key_source === 'wp-config' || !can ? ' disabled' : '') + '>'
            + (I.key_source === 'wp-config' ? '<small>Đặt bằng TGS_KETOAN_INSTALL_KEY trong wp-config.php.</small>' : '<small style="color:#64748b">open-ketoan → Hệ thống → Kết nối open-pos → Tạo khoá cài đặt (khoá chỉ hiện một lần).</small>') + '</label>'
            + '<label>Mã bản cài<input value="' + esc(I.code || '(open-ketoan trả khi đăng ký)') + '" disabled></label></div>'
            + (can && I.key_source !== 'wp-config' ? '<div class="tacc-actions"><button type="button" class="tacc-btn" id="taccInstallSave">Lưu khoá cài đặt</button></div>' : '');
        var info = '<div class="tacc-note">Mỗi cửa hàng vẫn là một tenant riêng. Bridge gửi kèm <code>X-OKT-Blog</code>; open-ketoan đổi ra sổ mà kế toán đã nối bằng <b>mã nối</b>. '
            + 'Admin cửa hàng đưa mã nối cho kế toán; kế toán <b>đăng nhập đúng sổ của cửa hàng</b> → Kết nối open-pos → Nhận cửa hàng → nhập mã. Chưa nối thì đơn giữ trong hàng chờ, nối xong gửi bù.</div>';
        if (!I.active) {
            return form + info + '<div class="tacc-muted">Khai khoá cài đặt để đăng ký cửa hàng.</div>';
        }
        var rows = T.blogs.map(function (b) {
            var st = shopState(b);
            var act = '';
            if (can && b.key_mode === 'install') {
                act = b.link === 'pending' ? '<button type="button" class="tacc-btn ghost" data-topo="renew" data-blog="' + b.blog + '">Mã nối mới</button>'
                    : (b.link === 'active' ? '' : '<button type="button" class="tacc-btn ghost" data-topo="register" data-blog="' + b.blog + '">Đăng ký</button>');
            }
            var code = b.link_code ? '<code class="tacc-code">' + esc(b.link_code.slice(0, 4) + '-' + b.link_code.slice(4)) + '</code><div class="tacc-muted">hết hạn ' + esc(fmtAt(b.link_expires)) + '</div>' : '';
            return '<tr><td>#' + b.blog + '</td><td><b>' + esc(b.name) + '</b><div class="tacc-muted">' + esc(hostName(b.url)) + '</div></td><td>' + esc(KEY[b.key_mode] || b.key_mode) + '</td>'
                + '<td>' + esc(b.tenant || '—') + '</td><td>' + (b.key_mode === 'install' ? pill(LINK[b.link] || [b.link]) : '<span class="tacc-muted">—</span>') + '</td><td>' + code + '</td>'
                + '<td>' + dot(st) + ' ' + esc(st[1]) + '</td><td>' + act + '</td></tr>';
        }).join('');
        return form + info
            + '<div class="tacc-tablewrap"><table class="tacc-table"><thead><tr><th>Blog</th><th>Cửa hàng</th><th>Khoá</th><th>Tenant</th><th>Đăng ký</th><th>Mã nối</th><th>Ping</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            + '<div class="tacc-actions">' + (can ? '<button type="button" class="tacc-btn ghost" data-topo="register">Đăng ký mọi cửa hàng</button> ' : '') + '<button type="button" class="tacc-btn ghost" data-topo="refresh">Đọc trạng thái từ open-ketoan</button>'
            + (I.synced_at ? ' <span class="tacc-muted">đọc lúc ' + esc(fmtAt(I.synced_at)) + '</span>' : '') + (I.error ? ' <span class="tacc-pill bad">' + esc(I.error) + '</span>' : '') + '</div>';
    }

    function keyStep() {
        var rows = T.blogs.map(function (b) {
            var ok = b.key_mode === 'own';
            var why = b.key_mode === 'legacy' ? (T.profile === 'bizcity' ? 'đang dùng khoá chung — P-0: khai khoá riêng của site' : 'khoá chung') : (b.key_mode === 'none' ? 'chưa có khoá' : '');
            return '<tr><td>#' + b.blog + '</td><td><b>' + esc(b.name) + '</b></td><td>' + pill([KEY[b.key_mode] || b.key_mode, ok ? 'ok' : (b.key_mode === 'none' ? 'bad' : 'warn')]) + (why ? ' <span class="tacc-muted">' + esc(why) + '</span>' : '') + '</td>'
                + '<td>' + esc(hostName(b.host) || '—') + '</td><td>' + esc(b.confirmed || '—') + '</td></tr>';
        }).join('');
        return '<div class="tacc-note">' + (T.profile === 'bizcity'
            ? 'Mỗi site một phần mềm kế toán (tenant). Kế toán của sổ tạo <b>khoá API</b> (open-ketoan → Hệ thống → Khoá API) rồi đưa admin site; khai ở Cổng kế toán của site đó, hoặc máy chủ đặt hằng <code>TGS_KETOAN_API_KEYS</code>. Xác nhận tenant ở tab Tự kiểm.'
            : 'Một site, một sổ. Khai địa chỉ + khoá API của sổ ở thẻ Kết nối bên dưới, rồi xác nhận tenant ở tab Tự kiểm.') + '</div>'
            + '<div class="tacc-tablewrap"><table class="tacc-table"><thead><tr><th>Blog</th><th>Cửa hàng</th><th>Khoá</th><th>open-ketoan</th><th>Tenant đã xác nhận</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    /* ───────── tab Sơ đồ kết nối ───────── */
    function renderTopo() {
        var sites = T.blogs.filter(function (b) { return b.key_mode !== 'none'; });
        var left = sites.map(function (b) {
            var st = shopState(b);
            return '<li>' + dot(st) + ' <b>#' + b.blog + '</b> ' + esc(b.name) + ' <span class="tacc-muted">' + esc(st[1]) + '</span></li>';
        }).join('') || '<li class="tacc-muted">Chưa site nào có khoá</li>';
        var none = T.blogs.length - sites.length;
        var kinds = {};
        sites.forEach(function (b) { kinds[KEY[b.key_mode]] = (kinds[KEY[b.key_mode]] || 0) + 1; });
        var mid = Object.keys(kinds).map(function (k) { return esc(k) + ': ' + kinds[k] + ' site'; }).join('<br>') || '—';
        var right = T.hosts.map(function (h) {
            var st = h.ok === undefined ? ['none', 'chưa ping'] : (h.ok ? [stale(T.ping_at) ? 'warn' : 'ok', h.ms + ' ms'] : ['bad', 'không trả lời']);
            var ten = T.blogs.filter(function (b) { return b.host === h.url && b.tenant; }).map(function (b) { return b.tenant; });
            var wait = T.blogs.filter(function (b) { return b.host === h.url && b.key_mode === 'install' && b.link !== 'active'; }).map(function (b) { return b.name || ('#' + b.blog); });
            return '<div class="tacc-node">' + dot(st) + ' <b>' + esc(hostName(h.url)) + '</b> <span class="tacc-muted">' + esc(st[1]) + '</span>'
                + '<div class="tacc-muted">tenant: ' + esc(ten.join(' · ') || '—') + (wait.length ? ' · (chờ: ' + esc(wait.join(', ')) + ')' : '') + '</div></div>';
        }).join('') || '<div class="tacc-node tacc-muted">Chưa khai máy open-ketoan</div>';
        var diagram = '<div class="tacc-diagram">'
            + '<div class="tacc-node"><div class="tacc-node__t">' + (T.is_ms ? 'WordPress multisite' : 'WordPress một site') + ' · ' + T.site_count + ' site</div><ul class="tacc-sites">' + left + '</ul>' + (none ? '<div class="tacc-muted">+ ' + none + ' site chưa có khoá</div>' : '') + '</div>'
            + '<div class="tacc-arrow" aria-hidden="true">→</div>'
            + '<div class="tacc-node"><div class="tacc-node__t">open-ketoan-bridge</div><div class="tacc-muted">' + esc(T.label || 'chưa khai mô hình') + '</div><div style="margin-top:6px">' + mid + '</div>'
            + (T.profile === 'chain' ? '<div class="tacc-muted" style="margin-top:4px">+ X-OKT-Blog' + (T.install.code ? ' · ' + esc(T.install.code.slice(0, 10)) + '…' : '') + '</div>' : '') + '</div>'
            + '<div class="tacc-arrow" aria-hidden="true">→</div>'
            + '<div class="tacc-node-col"><div class="tacc-node__t">open-ketoan · ' + T.hosts.length + ' máy</div>' + right + '</div></div>';
        var shops = T.blogs.map(function (b) {
            var st = shopState(b);
            var ck = CHECK[b.check];
            var linkCell = b.key_mode === 'install' ? pill(LINK[b.link] || [b.link]) : '—';
            var pingText = b.key_mode === 'install' && b.link !== 'active' ? 'chưa gọi được (chờ nối)' : st[1];
            return '<tr><td>#' + b.blog + ' <b>' + esc(b.name) + '</b></td><td>' + esc(hostName(b.host) || '—') + '</td><td>' + esc(KEY[b.key_mode] || b.key_mode) + '</td><td>' + esc(b.tenant || '—') + '</td>'
                + '<td>' + linkCell + '</td><td>' + dot(st) + ' ' + esc(pingText) + (b.ping && !b.ping.ok && b.ping.msg && st[0] === 'bad' ? '<div class="tacc-muted msg">' + esc(b.ping.msg) + '</div>' : '') + '</td>'
                + '<td class="c">' + (ck ? '<span class="tacc-sc ' + ck[1] + '">' + ck[0] + '</span>' : '<span class="tacc-sc">—</span>') + '</td><td class="r">' + b.queue + (b.pending_docs ? ' <span class="tacc-muted">(' + b.pending_docs + ' phiếu)</span>' : '') + '</td></tr>';
        }).join('');
        var hosts = T.hosts.map(function (h) {
            return '<tr><td><b>' + esc(hostName(h.url)) + '</b></td><td>' + esc(h.version || '—') + '</td><td>' + (h.ok === undefined ? 'chưa ping' : (h.ok ? h.ms + ' ms' : '✕ ' + esc(h.msg || ''))) + '</td>'
                + '<td>' + (h.skew === null || h.skew === undefined ? '—' : h.skew + ' giây') + '</td><td class="r">' + h.tenants + ' tenant / ' + h.blogs + ' site</td></tr>';
        }).join('');
        map.innerHTML = '<div class="tacc-actions" style="margin-top:0"><b>Sơ đồ kết nối</b> <span class="tacc-muted">Ping lúc ' + esc(fmtAt(T.ping_at) || '(chưa)') + ' · lịch 5 phút</span>'
            + ' <button type="button" class="tacc-btn ghost" data-topo="ping" style="margin-left:auto">Ping tất cả</button> <span class="tacc-muted" id="taccTopoNote"></span></div>'
            + diagram
            + '<div class="tacc-h3">Từng cửa hàng</div><div class="tacc-tablewrap"><table class="tacc-table"><thead><tr><th>Blog</th><th>open-ketoan</th><th>Khoá</th><th>Tenant</th><th>Nối</th><th>Ping</th><th class="c">Tự kiểm</th><th class="r">Hàng chờ</th></tr></thead><tbody>' + shops + '</tbody></table></div>'
            + '<div class="tacc-h3">Từng máy open-ketoan</div><div class="tacc-tablewrap"><table class="tacc-table"><thead><tr><th>Máy</th><th>Phiên bản</th><th>Ping</th><th>Đồng hồ lệch</th><th class="r">Nối</th></tr></thead><tbody>' + (hosts || '<tr><td colspan="5" class="tacc-muted">Chưa có</td></tr>') + '</tbody></table></div>'
            + '<div class="tacc-muted" style="margin-top:6px">● xanh = gọi được · ● vàng = chờ nối / ping trễ quá 15 phút · ● đỏ = lỗi / tạm dừng · ○ xám = chưa ping / chưa có khoá. Bizcity: N site → N tenant; nhiều cửa hàng: một máy, mỗi cửa hàng một tenant; một cửa hàng: 1 → 1.</div>';
    }

    /* ───────── thao tác ───────── */
    app.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-topo],[data-goto],#taccProfileSave,#taccInstallSave');
        if (!t) { return; }
        if (t.dataset.goto) {
            var tab = app.querySelector('.tacc-tabs button[data-tab="' + t.dataset.goto + '"]');
            if (tab) { tab.click(); }
            return;
        }
        t.disabled = true;
        var done = function () { t.disabled = false; };
        if (t.id === 'taccProfileSave') {
            var r = box.querySelector('input[name=taccProfile]:checked');
            if (!r) { done(); return note('Chọn một mô hình.', true); }
            return load('profile', { profile: r.value }).then(done);
        }
        if (t.id === 'taccInstallSave') {
            return load('install_key', { install_key: document.getElementById('taccInstallKey').value }).then(done);
        }
        var op = t.dataset.topo;
        note(op === 'ping' ? 'Đang ping…' : 'Đang gọi open-ketoan…', false);
        var extra = {};
        if (op === 'renew') { op = 'register'; extra = { blogs: t.dataset.blog, renew: t.dataset.blog }; }
        else if (op === 'register' && t.dataset.blog) { extra = { blogs: t.dataset.blog }; }
        load(op, extra).then(done);
    });

    load('get');
})();
