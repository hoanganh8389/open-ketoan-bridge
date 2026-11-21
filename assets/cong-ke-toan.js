/**
 * Trang "Cổng kế toán": chuyển tab, tổng quan, gửi chứng từ, nhật ký, lượt gửi, xem dữ liệu thật, cài đặt.
 * Gửi dùng chung TaccRunner (push-runner.js) với nút ở màn VAT.
 */
(function () {
    'use strict';
    var CFG = window.tgsAccBridge;
    var R = window.TaccRunner;
    var app = document.getElementById('taccApp');
    if (!CFG || !R || !app) { return; }
    var $ = function (sel, root) { return (root || app).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); };
    var esc = R.esc, num = R.num, fmtD = R.fmtD;
    var shopOf = {};
    CFG.shops.forEach(function (s) { shopOf[s.blog] = s; });
    var shopLabel = function (b) { var s = shopOf[b]; return s ? s.code : '#' + b; };
    var fmtT = function (s) { return s ? fmtD(s.slice(0, 10)) + ' ' + s.slice(11, 16) : ''; };
    var STATUS = { posted: ['Đã vào sổ', 'ok'], error: ['Lỗi', 'bad'], cancelled: ['Đã huỷ', ''] };
    var TYPE = { sale: 'Phiếu bán', sale_return: 'Phiếu hoàn', customer_payment: 'Thu / chi trả khách', purchase: 'Nhập mua', purchase_return: 'Trả NCC', transfer: 'Chuyển kho', stock_adjustment: 'Điều chỉnh kho', cash_transfer: 'Chuyển tiền' };
    var ORIGIN = { web: 'Nút trên web', cli: 'Dòng lệnh', cron: 'Lịch chạy' };

    /* ── tab ── */
    var loaded = {};
    function show(tab) {
        $$('.tacc-tabs button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === tab); });
        $$('.tacc-panel').forEach(function (p) { p.hidden = p.dataset.panel !== tab; });
        try { sessionStorage.setItem('taccTab', tab); } catch (e) { /* bỏ qua */ }
        if (!loaded[tab]) {
            loaded[tab] = true;
            if (tab === 'overview') { overview(); }
            if (tab === 'log') { loadLog(1); }
            if (tab === 'runs') { loadRuns(); }
            if (tab === 'calls') { loadCalls(); }
            if (tab === 'events') { loadEvents(); }
            if (tab === 'lists') { initLists(); }
        }
    }
    $$('.tacc-tabs button').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });

    /* ── chọn shop (dùng ở nhiều chỗ) ── */
    var opts = CFG.shops.map(function (s) { return '<option value="' + s.blog + '">' + esc(s.code + ' — ' + s.name) + '</option>'; }).join('');
    ['#taccLogBlog', '#taccRunBlog'].forEach(function (id) { $(id).insertAdjacentHTML('beforeend', opts); });
    $$('.tacc-sample__blog').forEach(function (el) { el.innerHTML = opts; });
    $('#taccShopPick').innerHTML = '<label class="all"><input type="checkbox" id="taccAll" checked> Tất cả (' + CFG.shops.length + ' shop áp dụng thuế)</label>'
        + CFG.shops.map(function (s) { return '<label><input type="checkbox" class="taccShop" value="' + s.blog + '" checked> ' + esc(s.code) + '</label>'; }).join('');
    $('#taccAll').addEventListener('change', function () { var on = this.checked; $$('.taccShop').forEach(function (c) { c.checked = on; }); });
    // Ô "Tất cả" chỉ tích khi mọi shop đều đang tích.
    $('#taccShopPick').addEventListener('change', function (e) {
        if (e.target.classList.contains('taccShop')) { $('#taccAll').checked = $$('.taccShop').every(function (c) { return c.checked; }); }
    });

    /* ── tổng quan ── */
    function runRows(rows, withShop) {
        if (!rows.length) { return '<tr><td class="tacc-muted">Chưa có lượt gửi nào.</td></tr>'; }
        return '<thead><tr><th>Lúc</th>' + (withShop ? '<th>Shop</th>' : '') + '<th>Khoảng ngày</th><th>Nguồn</th><th>Người bấm</th><th class="r">Dựng</th><th class="r">Gửi</th><th class="r">Vào sổ</th><th class="r">Không đổi</th><th class="r">Lỗi</th><th class="r">Huỷ</th><th class="r">Thời gian</th><th>Ghi chú</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td>' + fmtT(r.created_at) + '</td>' + (withShop ? '<td><b>' + esc(shopLabel(r.blog_id)) + '</b></td>' : '')
                    + '<td>' + fmtD(r.date_from) + (r.date_to !== r.date_from ? ' → ' + fmtD(r.date_to) : '') + '</td>'
                    + '<td>' + esc(ORIGIN[r.origin] || r.origin) + '</td><td>' + esc(r.user) + '</td>'
                    + '<td class="r">' + num(r.built) + '</td><td class="r">' + num(r.sent) + '</td><td class="r">' + num(r.posted) + '</td>'
                    + '<td class="r">' + num(+r.unchanged + +r.skipped) + '</td><td class="r">' + (+r.errors ? '<span class="tacc-pill bad">' + num(r.errors) + '</span>' : '0') + '</td>'
                    + '<td class="r">' + num(r.cancelled) + '</td><td class="r">' + (r.ms / 1000).toFixed(1) + 's</td><td class="msg">' + esc((r.note || '').split('\n').slice(0, 3).join('\n')) + '</td></tr>';
            }).join('') + '</tbody>';
    }

    function overview() {
        R.post('tgs_acc_overview').then(function (d) {
            var t = { posted: 0, error: 0, cancelled: 0 };
            Object.keys(d.stats).forEach(function (b) { ['posted', 'error', 'cancelled'].forEach(function (k) { t[k] += d.stats[b][k] || 0; }); });
            var c = d.config;
            $('#taccCards').innerHTML = [
                ['Kết nối', c.ready ? 'Đã cấu hình' : 'Chưa cấu hình', c.ready ? '' : 'bad', c.url || '—'],
                ['Shop áp dụng thuế', num(d.shops.length), '', 'Danh sách ở tgs-bc-tk → Shop áp dụng thuế'],
                ['Chứng từ đã vào sổ', num(t.posted), '', 'Theo nhật ký gửi'],
                ['Chứng từ lỗi', num(t.error), t.error ? 'bad' : '', t.error ? 'Xem tab Nhật ký chứng từ, lọc Lỗi' : 'Không có'],
                ['Đã huỷ', num(t.cancelled), '', 'Phiếu bị xoá ở shop sau khi đã gửi']
            ].map(function (k) {
                return '<div class="tacc-kpi ' + k[2] + '"><span class="tacc-muted">' + k[0] + '</span><b>' + esc(k[1]) + '</b><span class="tacc-muted">' + esc(k[3]) + '</span></div>';
            }).join('') + (d.source ? '' : '<div class="tacc-kpi bad"><span class="tacc-muted">Nguồn dữ liệu</span><b>Thiếu tgs-bc-tk</b></div>');
            $('#taccShopTable tbody').innerHTML = d.shops.map(function (s) {
                var st = d.stats[s.blog] || {};
                return '<tr><td><b>' + esc(s.code) + '</b></td><td>' + esc(s.name) + '</td><td class="r">' + num(st.posted) + '</td>'
                    + '<td class="r">' + (st.error ? '<a href="#" class="taccErr" data-blog="' + s.blog + '"><span class="tacc-pill bad">' + num(st.error) + '</span></a>' : '0') + '</td>'
                    + '<td class="r">' + num(st.cancelled) + '</td><td>' + fmtD(st.last_doc) + '</td><td>' + fmtT(st.last_at) + '</td>'
                    + '<td><a href="#" class="taccPushShop" data-blog="' + s.blog + '">Gửi…</a></td></tr>';
            }).join('') || '<tr><td colspan="8" class="tacc-muted">Chưa có shop nào trong danh sách áp dụng thuế.</td></tr>';
            $('#taccRecentRuns').innerHTML = runRows(d.runs, true);
        }).catch(function (e) { $('#taccCards').innerHTML = '<div class="tacc-kpi bad"><b>Lỗi</b>' + esc(e.message) + '</div>'; });
    }
    app.addEventListener('click', function (ev) {
        var a = ev.target.closest('.taccErr, .taccPushShop');
        if (!a) { return; }
        ev.preventDefault();
        var blog = a.dataset.blog;
        if (a.classList.contains('taccErr')) {
            $('#taccLogBlog').value = blog;
            $('#taccLogStatus').value = 'error';
            $('#taccLogFrom').value = '';
            show('log');
            loadLog(1);
        } else {
            $('#taccAll').checked = false;
            $$('.taccShop').forEach(function (c) { c.checked = c.value === blog; });
            show('push');
        }
    });

    /* ── gửi ── */
    function pickedShops() {
        var ids = $$('.taccShop:checked').map(function (c) { return +c.value; });
        return CFG.shops.filter(function (s) { return ids.indexOf(s.blog) >= 0; });
    }
    function startPush(dry) {
        if (R.running()) { return; }
        var shops = pickedShops();
        var from = $('#taccFrom').value, to = $('#taccTo').value;
        if (!shops.length) { alert('Chọn ít nhất một shop.'); return; }
        if (!from || !to || from > to) { alert('Khoảng ngày không hợp lệ.'); return; }
        var pass = '';
        if (!dry) {
            if (!CFG.ready) { alert('Chưa cấu hình kết nối — vào tab Cài đặt.'); return; }
            if (!confirm('Gửi chứng từ của ' + shops.length + ' shop, ' + fmtD(from) + ' → ' + fmtD(to) + ' sang kế toán?')) { return; }
            if (CFG.needPass) {
                pass = prompt('Mật khẩu thao tác:') || '';
                if (!pass) { return; }
            }
        }
        $('#taccDry').disabled = $('#taccGo').disabled = true;
        $('#taccStop').hidden = false;
        R.run({
            shops: shops, from: from, to: to, dry: dry, force: $('#taccForce').checked, password: pass,
            table: $('#taccPushTable'), bar: $('#taccBar'), note: $('#taccPushNote'),
            onDone: function (tot, stopped) {
                $('#taccDry').disabled = $('#taccGo').disabled = false;
                $('#taccStop').hidden = true;
                $('#taccPushNote').textContent = (stopped ? 'Đã dừng. ' : 'Xong. ') + (dry
                    ? 'Dựng ' + num(tot.built) + ' chứng từ, cần gửi ' + num(tot.to_send) + ', không đổi ' + num(tot.skipped) + '.'
                    : 'Vào sổ ' + num(tot.posted) + ', không đổi ' + num(tot.skipped + tot.unchanged) + ', lỗi ' + num(tot.error + tot.failed) + ', huỷ ' + num(tot.cancelled) + '.');
                loaded.overview = loaded.log = loaded.runs = loaded.calls = false;
            }
        });
    }
    $('#taccDry').addEventListener('click', function () { startPush(true); });
    $('#taccGo').addEventListener('click', function () { startPush(false); });
    $('#taccStop').addEventListener('click', function () { R.stop(); this.hidden = true; });

    /* ── nhật ký chứng từ ── */
    var logPage = 1;
    function loadLog(page) {
        logPage = page || 1;
        var per = 100;
        R.post('tgs_acc_log', {
            blog: $('#taccLogBlog').value, status: $('#taccLogStatus').value, type: $('#taccLogType').value,
            from: $('#taccLogFrom').value, to: $('#taccLogTo').value, q: $('#taccLogQ').value.trim(), page: logPage, per: per
        }).then(function (d) {
            var pages = Math.max(1, Math.ceil(d.total / per));
            $('#taccLogInfo').textContent = num(d.total) + ' chứng từ';
            $('#taccLogTable tbody').innerHTML = d.rows.map(function (r) {
                var st = STATUS[r.status] || [r.status, ''];
                return '<tr><td><b>' + esc(shopLabel(r.blog_id)) + '</b></td><td>' + fmtD(r.doc_date) + '</td><td>' + esc(r.doc_no) + '</td>'
                    + '<td>' + esc(TYPE[r.doc_type] || r.doc_type) + '</td><td><code>' + esc(r.doc_key) + '</code></td><td class="r">' + r.version + '</td>'
                    + '<td><span class="tacc-pill ' + st[1] + '">' + esc(st[0]) + '</span></td><td class="msg">' + esc(r.message) + '</td><td>' + fmtT(r.pushed_at) + '</td>'
                    + '<td><a href="#" class="taccResend" data-blog="' + r.blog_id + '" data-date="' + esc(r.doc_date) + '">Gửi lại ngày này</a></td></tr>';
            }).join('') || '<tr><td colspan="10" class="tacc-muted">Không có chứng từ nào khớp bộ lọc.</td></tr>';
            $('#taccLogPager').innerHTML = pages > 1
                ? '<button type="button" class="tacc-btn ghost" data-p="' + (logPage - 1) + '"' + (logPage <= 1 ? ' disabled' : '') + '>‹ Trước</button>'
                  + '<span>Trang ' + logPage + ' / ' + pages + '</span>'
                  + '<button type="button" class="tacc-btn ghost" data-p="' + (logPage + 1) + '"' + (logPage >= pages ? ' disabled' : '') + '>Sau ›</button>'
                : '';
        }).catch(function (e) { $('#taccLogInfo').textContent = e.message; });
    }
    $('#taccLogGo').addEventListener('click', function () { loadLog(1); });
    $('#taccLogQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { loadLog(1); } });
    $('#taccLogPager').addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b && !b.disabled) { loadLog(+b.dataset.p); } });
    app.addEventListener('click', function (ev) {
        var a = ev.target.closest('.taccResend');
        if (!a) { return; }
        ev.preventDefault();
        $('#taccAll').checked = false;
        $$('.taccShop').forEach(function (c) { c.checked = c.value === a.dataset.blog; });
        $('#taccFrom').value = $('#taccTo').value = a.dataset.date;
        show('push');
    });

    /* ── lượt gửi, lượt gọi ── */
    function loadRuns() {
        R.post('tgs_acc_runs', { blog: $('#taccRunBlog').value }).then(function (rows) { $('#taccRunTable').innerHTML = runRows(rows, true); })
            .catch(function (e) { $('#taccRunTable').innerHTML = '<tr><td>' + esc(e.message) + '</td></tr>'; });
    }
    $('#taccRunGo').addEventListener('click', loadRuns);
    function loadCalls() {
        R.post('tgs_acc_calls').then(function (rows) {
            $('#taccCallTable').innerHTML = rows.length ? '<thead><tr><th>Lúc</th><th>Lệnh</th><th>Đường dẫn</th><th class="r">HTTP</th><th class="r">Thời gian</th><th class="r">Số CT</th><th>Kết quả</th><th>Nguồn</th></tr></thead><tbody>'
                + rows.map(function (r) {
                    return '<tr><td>' + fmtT(r.at) + '</td><td>' + esc(r.method) + '</td><td><code>' + esc(r.path) + '</code></td><td class="r">' + (r.http || '—') + '</td>'
                        + '<td class="r">' + num(r.ms) + ' ms</td><td class="r">' + (r.count != null ? num(r.count) : '') + '</td>'
                        + '<td>' + (r.ok ? '<span class="tacc-pill ok">OK</span>' : '<span class="tacc-pill bad">Lỗi</span> ' + esc(r.msg)) + '</td><td>' + (r.cli ? 'Dòng lệnh' : 'Web') + '</td></tr>';
                }).join('') + '</tbody>' : '<tr><td class="tacc-muted">Chưa có lượt gọi nào.</td></tr>';
        }).catch(function (e) { $('#taccCallTable').innerHTML = '<tr><td>' + esc(e.message) + '</td></tr>'; });
    }
    $('#taccCallsGo').addEventListener('click', loadCalls);

    /* ── sự kiện chứng từ (gửi tức thời) ── */
    var OP = { insert: 'Thêm', update: 'Sửa', delete: 'Xoá', manual: 'Plugin báo' };
    $('#taccEvDate').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    function evResult(e, r) {
        if (!r) { return '<span class="tacc-muted">' + (e.bulk ? 'Hàng loạt — kéo tay' : 'Chưa gửi') + '</span>'; }
        var txt = (r.sent ? 'gửi ' + r.sent : '') + (r.unchanged ? (r.sent ? ', ' : '') + 'không đổi ' + r.unchanged : '') + (r.cancelled ? ', báo huỷ ' + r.cancelled : '');
        return r.res === 'ok' ? '<span class="tacc-pill ok">OK</span> ' + esc(txt || 'không có chứng từ kế toán') : '<span class="tacc-pill bad">Lỗi</span> ' + esc(r.msg || '') + ' <span class="tacc-muted">(cron sẽ gửi lại)</span>';
    }
    function loadEvents() {
        $('#taccEvInfo').textContent = 'Đang tải…';
        R.post('tgs_acc_events', { date: $('#taccEvDate').value }).then(function (d) {
            $('#taccEvInfo').textContent = (d.enabled ? 'Gửi tức thời: BẬT' : 'Gửi tức thời: TẮT (chỉ ghi sự kiện)') + ' · tệp ' + d.file;
            $('#taccEvWaiting').innerHTML = d.waiting.length
                ? '<span class="tacc-pill bad">' + d.waiting.length + ' nhóm chờ gửi lại</span> ' + d.waiting.slice(0, 5).map(function (w) { return esc(shopLabel(w.blog) + ' (lần ' + w.n + '): ' + (w.msg || '')); }).join(' · ')
                : 'Không có nhóm nào chờ gửi lại.';
            $('#taccEvTable').innerHTML = d.events.length ? '<thead><tr><th>Lúc</th><th>Shop</th><th>Loại</th><th class="r">Mã phiếu</th><th>Thao tác</th><th>Từ màn</th><th class="r">Người</th><th>Sổ nội bộ</th>' + (d.tax ? '<th>Sổ thuế</th>' : '') + '</tr></thead><tbody>'
                + d.events.map(function (e) {
                    return '<tr><td>' + esc((e.t || '').slice(11, 19)) + '</td><td><b>' + esc(e.blog ? shopLabel(e.blog) : '—') + '</b></td><td>' + esc(e.bulk ? 'Hàng loạt' : (e.kind === 'cash_transfer' ? 'Chuyển tiền' : 'Phiếu')) + '</td>'
                        + '<td class="r">' + esc(e.ref || '') + '</td><td>' + esc(OP[e.op] || e.op || '') + '</td><td><code>' + esc(e.src || '') + '</code></td><td class="r">' + esc(e.user || '') + '</td><td>' + evResult(e, e.result) + '</td>' + (d.tax ? '<td>' + evResult(e, e.tax) + '</td>' : '') + '</tr>';
                }).join('') + '</tbody>' : '<tr><td class="tacc-muted">Ngày này chưa có sự kiện nào.</td></tr>';
        }).catch(function (e) { $('#taccEvInfo').textContent = e.message; });
    }
    $('#taccEvGo').addEventListener('click', loadEvents);
    $('#taccEvRetry').addEventListener('click', function () {
        $('#taccEvInfo').textContent = 'Đang gửi lại…';
        R.post('tgs_acc_events_retry').then(function (r) { $('#taccEvInfo').textContent = 'Đã gửi lại ' + r.retried + ' nhóm, thành công ' + r.ok + '.'; loadEvents(); })
            .catch(function (e) { $('#taccEvInfo').textContent = e.message; });
    });

    /* ── danh mục bên kế toán (chỉ đọc) ── */
    var lists = { kinds: [], rows: [], kind: '' };
    function listDef() { return lists.kinds.filter(function (k) { return k.kind === lists.kind; })[0]; }
    function drawList() {
        var d = listDef();
        if (!d) { return; }
        var q = ($('#taccListQ').value || '').trim().toLowerCase();
        var keys = Object.keys(d.cols);
        var rows = q ? lists.rows.filter(function (r) { return keys.some(function (k) { return String(r[k] || '').toLowerCase().indexOf(q) >= 0; }); }) : lists.rows;
        $('#taccListTable').innerHTML = rows.length
            ? '<thead><tr>' + keys.map(function (k) { return '<th>' + esc(d.cols[k]) + '</th>'; }).join('') + '</tr></thead><tbody>'
                + rows.map(function (r) { return '<tr>' + keys.map(function (k, i) { return '<td>' + (i === 0 ? '<b><code>' + esc(r[k]) + '</code></b>' : esc(r[k])) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody>'
            : '<tr><td class="tacc-muted">Không có dòng nào.</td></tr>';
    }
    function loadList(fresh) {
        lists.kind = $('#taccListKind').value;
        var d = listDef();
        $('#taccListUse').textContent = d ? 'Dùng cho: ' + d.use : '';
        $('#taccListInfo').textContent = 'Đang đọc…';
        $('#taccListTable').innerHTML = '';
        R.post('tgs_acc_lists', { kind: lists.kind, fresh: fresh ? 1 : 0 }).then(function (r) {
            lists.rows = r.rows || [];
            $('#taccListInfo').textContent = lists.rows.length + ' dòng' + (r.fetchedAt ? ' · đọc lúc ' + r.fetchedAt : '');
            drawList();
        }).catch(function (e) { $('#taccListInfo').textContent = ''; $('#taccListTable').innerHTML = '<tr><td>' + esc(e.message) + '</td></tr>'; });
    }
    function initLists() {
        R.post('tgs_acc_lists', {}).then(function (r) {
            lists.kinds = r.kinds || [];
            $('#taccListKind').innerHTML = lists.kinds.map(function (k) { return '<option value="' + esc(k.kind) + '">' + esc(k.label) + '</option>'; }).join('');
            loadList(false);
        }).catch(function (e) { $('#taccListTable').innerHTML = '<tr><td>' + esc(e.message) + '</td></tr>'; });
    }
    $('#taccListKind').addEventListener('change', function () { loadList(false); });
    $('#taccListFresh').addEventListener('click', function () { loadList(true); });
    $('#taccListQ').addEventListener('input', drawList);
    $('#taccListCsv').addEventListener('click', function () {
        var d = listDef();
        if (!d || !lists.rows.length) { return; }
        var keys = Object.keys(d.cols);
        var cell = function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
        var csv = '\ufeff' + [keys.map(function (k) { return cell(d.cols[k]); }).join(',')]
            .concat(lists.rows.map(function (r) { return keys.map(function (k) { return cell(r[k]); }).join(','); })).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = 'danh-muc-ke-toan-' + lists.kind + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
    });

    /* ── xem dữ liệu thật ── */
    $$('.tacc-sample').forEach(function (box) {
        $('.tacc-sample__go', box).addEventListener('click', function () {
            var info = $('.tacc-sample__info', box), pre = $('.tacc-pre', box), d = $('.tacc-sample__date', box).value;
            info.textContent = 'Đang dựng…';
            pre.hidden = true;
            R.post('tgs_acc_sample', { blog: $('.tacc-sample__blog', box).value, from: d, to: d, type: box.dataset.type, doc_no: $('.tacc-sample__no', box).value.trim() })
                .then(function (r) {
                    if (!r.doc) { info.textContent = 'Không có chứng từ loại này trong ngày (dựng được ' + r.count + ').' + (r.failed.length ? ' Không dựng được: ' + r.failed.join('; ') : ''); return; }
                    var st = r.log ? (STATUS[r.log.status] || [r.log.status])[0] + ', phiên bản ' + r.log.version + ', gửi lúc ' + fmtT(r.log.pushed_at) : 'chưa gửi lần nào';
                    info.textContent = 'Ngày này có ' + r.count + ' chứng từ loại này. Đang xem ' + r.doc.docNo + ' — nhật ký gửi: ' + st + '.';
                    pre.textContent = JSON.stringify(r.doc, null, 2);
                    pre.hidden = false;
                }).catch(function (e) { info.textContent = e.message; });
        });
    });

    /* ── cài đặt ── */
    $('#taccSave').addEventListener('click', function () {
        var data = { timeout: $('#taccTimeout').value, chunk_days: $('#taccChunk').value };
        if (!$('#taccRealtime').disabled) { data.realtime = $('#taccRealtime').checked ? '1' : '0'; }
        if (!$('#taccTaxUrl').disabled) { data.tax_url = $('#taccTaxUrl').value.trim(); }
        if (!$('#taccTaxKey').disabled && $('#taccTaxKey').value.trim()) { data.tax_key = $('#taccTaxKey').value.trim(); }
        if (!$('#taccUrl').disabled) { data.url = $('#taccUrl').value.trim(); }
        if (!$('#taccWebUrl').disabled) { data.web_url = $('#taccWebUrl').value.trim(); }
        if (!$('#taccKey').disabled && $('#taccKey').value.trim()) { data.key = $('#taccKey').value.trim(); }
        if ($('#taccSsoSecret') && !$('#taccSsoSecret').disabled && $('#taccSsoSecret').value.trim()) { data.sso_secret = $('#taccSsoSecret').value.trim(); }
        if ($('#taccSsoTenant')) { data.sso_tenant = $('#taccSsoTenant').value.trim(); }
        R.post('tgs_acc_save', data).then(function (r) {
            $('#taccSaveNote').textContent = r.message;
            $('#taccKey').value = '';
            if ($('#taccSsoSecret')) { $('#taccSsoSecret').value = ''; }
            CFG.ready = r.config.ready ? 1 : 0;
            CFG.chunkDays = r.config.chunk_days;
            $('#taccChunkLbl').textContent = r.config.chunk_days;
            var pill = document.getElementById('taccReady');
            pill.className = 'tacc-pill ' + (r.config.ready ? 'ok' : 'bad');
            pill.textContent = r.config.ready ? 'Đã cấu hình' : 'Chưa cấu hình';
        }).catch(function (e) { $('#taccSaveNote').textContent = e.message; });
    });
    $('#taccPing').addEventListener('click', function () {
        var out = $('#taccPingOut');
        out.innerHTML = '<div class="tacc-muted">Đang kiểm tra…</div>';
        R.post('tgs_acc_ping').then(function (p) {
            var line = function (ok, label, text) { return '<div><span class="tacc-pill ' + (ok ? 'ok' : 'bad') + '">' + (ok ? 'OK' : 'Lỗi') + '</span> <b>' + label + '</b> <span class="tacc-muted">' + esc(text) + '</span></div>'; };
            out.innerHTML = '<div class="tacc-ping">'
                + line(p.health.ok, 'Máy chủ kế toán', p.health.ok ? (p.url + ' — đang chạy') : p.health.message)
                + line(p.auth.ok, 'Khoá API', p.auth.ok ? 'hợp lệ' : p.auth.message)
                + (p.branches ? line(true, 'Chi nhánh bên kế toán', p.branches.length + ': ' + p.branches.map(function (b) { return b.code; }).join(', ')) : '')
                + '</div>';
        }).catch(function (e) { out.innerHTML = '<div class="tacc-pill bad">' + esc(e.message) + '</div>'; });
    });

    // Đồng bộ chi nhánh từ các site (TGS_Acc_Branch_Sync): tạo bên kế toán mã còn thiếu, hiện kết quả từng site.
    if ($('#taccSyncBranches')) {
        $('#taccSyncBranches').addEventListener('click', function () {
            var out = $('#taccPingOut');
            var btn = $('#taccSyncBranches');
            btn.disabled = true;
            out.innerHTML = '<div class="tacc-muted">Đang đồng bộ chi nhánh…</div>';
            var LBL = { created: ['ok', 'Vừa tạo'], exists: ['ok', 'Đã có'], skipped: ['', 'Bỏ qua'], error: ['bad', 'Lỗi'] };
            R.post('tgs_acc_sync_branches').then(function (r) {
                var rows = r.rows.map(function (x) {
                    var l = LBL[x.status] || ['', x.status];
                    return '<tr><td>' + x.blog + '</td><td><b>' + esc(x.code || '—') + '</b></td><td>' + esc(x.name) + '</td><td><span class="tacc-pill ' + l[0] + '">' + l[1] + '</span></td><td class="tacc-muted">' + esc(x.message || '') + '</td></tr>';
                }).join('');
                out.innerHTML = '<div class="tacc-ping"><div><b>Chi nhánh bên kế toán:</b> vừa tạo ' + r.created + ' · đã có ' + r.exists + ' · bỏ qua ' + r.skipped + ' · lỗi ' + r.errors + '</div>'
                    + '<table class="tacc-table"><thead><tr><th>Site</th><th>Mã chi nhánh</th><th>Tên</th><th>Kết quả</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>';
            }).catch(function (e) { out.innerHTML = '<div class="tacc-pill bad">' + esc(e.message) + '</div>'; })
              .then(function () { btn.disabled = false; });
        });
    }

    var first = 'overview';
    try { first = sessionStorage.getItem('taccTab') || 'overview'; } catch (e) { /* bỏ qua */ }
    show($('.tacc-tabs button[data-tab="' + first + '"]') ? first : 'overview');
})();
