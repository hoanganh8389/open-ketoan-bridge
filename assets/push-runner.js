/**
 * Bộ chạy gửi chứng từ dùng chung (trang Cổng kế toán + nút "Đẩy sang Kế toán" ở màn VAT).
 *
 *   TaccRunner.post(action, data)            gọi AJAX, trả data hoặc ném lỗi có thông báo tiếng Việt
 *   TaccRunner.chunks(from, to, days)        chia khoảng ngày thành từng đoạn
 *   TaccRunner.run({ shops, from, to, dry, force, password, table, bar, note, onDone })
 *       gửi lần lượt shop × đoạn ngày, vẽ từng dòng vào <table> (tbody + tfoot); trả Promise.
 *   TaccRunner.stop()                        dừng sau lượt đang chạy
 */
(function () {
    'use strict';
    var CFG = window.tgsAccBridge;
    if (!CFG) { return; }

    var esc = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var num = function (v) { return Number(v || 0).toLocaleString('vi-VN'); };
    var fmtD = function (s) { return s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : ''; };

    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', CFG.nonce);
        Object.keys(data || {}).forEach(function (k) {
            if (data[k] !== undefined && data[k] !== null) { fd.append(k, data[k]); }
        });
        return fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                return res.text().then(function (text) {
                    try { return JSON.parse(text); } catch (e) { throw new Error('Máy chủ trả lời không đọc được (mã ' + res.status + ').'); }
                });
            })
            .then(function (out) {
                if (!out || !out.success) { throw new Error((out && out.data && out.data.message) || 'Không thực hiện được.'); }
                return out.data;
            });
    }

    function addDays(d, n) {
        var t = new Date(d + 'T00:00:00Z');
        t.setUTCDate(t.getUTCDate() + n);
        return t.toISOString().slice(0, 10);
    }

    function chunks(from, to, days) {
        var out = [];
        days = Math.max(1, Math.min(CFG.maxDays || 31, days || 7));
        for (var a = from; a <= to; a = addDays(a, days)) {
            var b = addDays(a, days - 1);
            out.push([a, b > to ? to : b]);
        }
        return out;
    }

    var state = { stop: false, running: false };

    function run(o) {
        var jobs = [];
        chunks(o.from, o.to, CFG.chunkDays).forEach(function (c) {
            o.shops.forEach(function (s) { jobs.push({ shop: s, from: c[0], to: c[1] }); });
        });
        // Sắp theo shop rồi theo ngày cho dễ đọc.
        jobs.sort(function (x, y) { return x.shop.blog - y.shop.blog || (x.from < y.from ? -1 : 1); });
        var tb = o.table.querySelector('tbody');
        var tf = o.table.querySelector('tfoot');
        tb.innerHTML = jobs.map(function (j, i) {
            return '<tr data-i="' + i + '"><td><b>' + esc(j.shop.code) + '</b> <span class="tacc-muted">' + esc(j.shop.name) + '</span></td>'
                + '<td>' + fmtD(j.from) + (j.to !== j.from ? ' → ' + fmtD(j.to) : '') + '</td>'
                + '<td><span class="tacc-pill">Chờ</span></td><td class="r"></td><td class="r"></td><td class="r"></td><td class="r"></td><td class="r"></td><td class="r"></td><td class="msg"></td></tr>';
        }).join('');
        if (tf) { tf.innerHTML = ''; }
        var tot = { built: 0, to_send: 0, skipped: 0, unchanged: 0, posted: 0, error: 0, cancelled: 0, failed: 0 };
        state.stop = false;
        state.running = true;
        var i = 0;

        function setBar() { if (o.bar) { o.bar.style.width = Math.round(i / Math.max(1, jobs.length) * 100) + '%'; } }
        function foot() {
            if (!tf) { return; }
            tf.innerHTML = '<tr><td colspan="3">Cộng ' + jobs.length + ' lượt</td><td class="r">' + num(tot.built) + '</td><td class="r">' + num(tot.to_send)
                + '</td><td class="r">' + num(tot.skipped + tot.unchanged) + '</td><td class="r">' + (o.dry ? '—' : num(tot.posted)) + '</td><td class="r">'
                + num(tot.error + tot.failed) + '</td><td class="r">' + num(tot.cancelled) + '</td><td></td></tr>';
        }

        function next() {
            if (state.stop || i >= jobs.length) {
                state.running = false;
                setBar();
                foot();
                if (o.onDone) { o.onDone(tot, state.stop); }
                return Promise.resolve(tot);
            }
            var j = jobs[i];
            var tr = tb.querySelector('tr[data-i="' + i + '"]');
            var cells = tr.children;
            cells[2].innerHTML = '<span class="tacc-pill run">' + (o.dry ? 'Đang dựng…' : 'Đang gửi…') + '</span>';
            tr.scrollIntoView({ block: 'nearest' });
            if (o.note) { o.note.textContent = 'Lượt ' + (i + 1) + '/' + jobs.length + ' — ' + j.shop.code; }
            return post('tgs_acc_push', {
                blog: j.shop.blog, from: j.from, to: j.to,
                dry: o.dry ? 1 : '', force: o.force ? 1 : '', password: o.password || ''
            }).then(function (s) {
                var fails = (s.failed_build || []).length;
                var errs = (s.error || 0) + fails;
                tot.built += s.built || 0; tot.to_send += s.to_send || 0; tot.skipped += s.skipped || 0;
                tot.unchanged += s.unchanged || 0; tot.posted += s.posted || 0; tot.error += s.error || 0;
                tot.cancelled += s.cancelled || 0; tot.failed += fails;
                var pill = errs || (s.errors || []).length ? '<span class="tacc-pill bad">Có lỗi</span>'
                    : (o.dry ? '<span class="tacc-pill warn">Xem trước</span>' : '<span class="tacc-pill ok">Xong</span>');
                cells[2].innerHTML = pill;
                cells[3].textContent = num(s.built);
                cells[4].textContent = num(s.to_send);
                cells[5].textContent = num((s.skipped || 0) + (s.unchanged || 0));
                cells[6].textContent = o.dry ? '—' : num(s.posted);
                cells[7].textContent = num(errs);
                cells[8].textContent = num(s.cancelled);
                cells[9].textContent = [].concat(s.failed_build || [], s.errors || []).slice(0, 8).join('\n');
            }).catch(function (e) {
                tot.error += 1;
                cells[2].innerHTML = '<span class="tacc-pill bad">Lỗi</span>';
                cells[9].textContent = e.message;
                // Sai mật khẩu / chưa cấu hình: dừng luôn, khỏi chạy tiếp các lượt sau.
                if (/mật khẩu|cấu hình|quyền/i.test(e.message)) { state.stop = true; }
            }).then(function () {
                i++;
                setBar();
                foot();
                return next();
            });
        }
        setBar();
        return next();
    }

    window.TaccRunner = {
        post: post, chunks: chunks, run: run, esc: esc, num: num, fmtD: fmtD,
        stop: function () { state.stop = true; },
        running: function () { return state.running; }
    };
})();
