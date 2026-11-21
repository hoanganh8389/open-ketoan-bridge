/**
 * Nút "Đẩy sang Kế toán" ở màn Quản lý phiếu xuất bán / phiếu điều chỉnh (VAT) của tgs-bc-tk.
 * Lấy khoảng ngày + các chi nhánh đang tích ở màn đó → Xem trước → Gửi (mật khẩu thao tác).
 * Một lượt đẩy gửi CẢ phiếu bán lẫn phiếu hoàn của khoảng ngày. Chi tiết, nhật ký: trang Cổng kế toán.
 */
(function () {
    'use strict';
    var CFG = window.tgsAccBridge;
    var R = window.TaccRunner;
    if (!CFG || !R) { return; }
    var esc = R.esc, num = R.num, fmtD = R.fmtD;

    function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

    ready(function () {
        var head = document.querySelector('.bctk-result__head');
        if (!head || document.getElementById('taccVatBtn')) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'taccVatBtn';
        btn.textContent = '⇄ Đẩy sang Kế toán';
        btn.title = 'Gửi phiếu bán + phiếu hoàn của khoảng ngày đang chọn sang phần mềm kế toán (không ghi trùng)';
        head.appendChild(btn);

        var box = null;
        function shopsInScreen() {
            // Chi nhánh đang tích ở bộ lọc bên trái; không tích gì = tất cả shop áp dụng thuế.
            var ids = Array.prototype.map.call(document.querySelectorAll('.bctk-site:checked'), function (c) { return parseInt(c.value, 10); });
            var all = CFG.shops;
            var picked = ids.length ? all.filter(function (s) { return ids.indexOf(s.blog) >= 0; }) : all;
            return picked.length ? picked : all;
        }

        function open() {
            var from = (document.getElementById('bctkDateFrom') || {}).value || '';
            var to = (document.getElementById('bctkDateTo') || {}).value || from;
            var shops = shopsInScreen();
            if (!box) {
                box = document.createElement('div');
                box.id = 'taccVat';
                box.innerHTML = '<div class="tacc-ov"></div><div class="tacc-win">'
                    + '<div class="tacc-win__head"><strong style="font-size:15px">Đẩy chứng từ sang Kế toán</strong><span class="tacc-muted" id="taccVatSum"></span>'
                    + '<button type="button" class="tacc-x" title="Đóng">×</button></div>'
                    + '<div class="tacc-win__body">'
                    + (CFG.ready ? '' : '<div class="tacc-pill bad" style="margin-bottom:8px">Chưa cấu hình kết nối kế toán — vào Quản trị → Kế toán → Cổng kế toán → Cài đặt. Vẫn xem trước được.</div>')
                    + '<div class="tacc-form"><label>Từ ngày <input type="date" id="taccVatFrom"></label><label>Đến ngày <input type="date" id="taccVatTo"></label>'
                    + '<label class="tacc-check"><input type="checkbox" id="taccVatForce"> Gửi lại cả chứng từ không đổi</label></div>'
                    + '<div class="tacc-muted" id="taccVatShops" style="margin-bottom:8px"></div>'
                    + '<div class="tacc-progress"><div id="taccVatBar"></div></div>'
                    + '<div class="tacc-tablewrap"><table class="tacc-table" id="taccVatTable"><thead><tr><th>Shop</th><th>Đoạn ngày</th><th>Trạng thái</th><th class="r">Dựng</th><th class="r">Cần gửi</th><th class="r">Không đổi</th><th class="r">Vào sổ</th><th class="r">Lỗi</th><th class="r">Huỷ</th><th>Ghi chú</th></tr></thead><tbody></tbody><tfoot></tfoot></table></div>'
                    + '</div>'
                    + '<div class="tacc-win__foot"><span class="tacc-muted" id="taccVatNote" style="flex:1">Gửi lại bao nhiêu lần cũng không ghi trùng: chứng từ không đổi được bỏ qua, phiếu sửa thì lên phiên bản, phiếu đã xoá thì huỷ bên kế toán.</span>'
                    + '<a class="tacc-btn ghost" href="' + esc(CFG.pageUrl) + '" target="_blank" rel="noopener">Nhật ký ↗</a>'
                    + '<button type="button" class="tacc-btn danger" id="taccVatStop" hidden>Dừng</button>'
                    + '<button type="button" class="tacc-btn ghost" id="taccVatDry">Xem trước</button>'
                    + '<button type="button" class="tacc-btn" id="taccVatGo">Gửi sang kế toán</button></div>'
                    + '</div>';
                document.body.appendChild(box);
                box.querySelector('.tacc-x').addEventListener('click', close);
                box.querySelector('.tacc-ov').addEventListener('click', close);
                box.querySelector('#taccVatDry').addEventListener('click', function () { go(true); });
                box.querySelector('#taccVatGo').addEventListener('click', function () { go(false); });
                box.querySelector('#taccVatStop').addEventListener('click', function () { R.stop(); this.hidden = true; });
            }
            box.querySelector('#taccVatFrom').value = from;
            box.querySelector('#taccVatTo').value = to;
            box.dataset.shops = JSON.stringify(shops.map(function (s) { return s.blog; }));
            box.querySelector('#taccVatShops').innerHTML = '<b>' + shops.length + ' shop</b>' + (shops.length === CFG.shops.length ? ' (tất cả shop áp dụng thuế)' : ' (theo chi nhánh đang tích)') + ': '
                + shops.map(function (s) { return esc(s.code); }).join(', ');
            box.querySelector('#taccVatSum').textContent = '';
            box.hidden = false;
        }

        function close() {
            if (R.running() && !confirm('Đang gửi dở. Đóng sẽ dừng sau lượt đang chạy. Đóng?')) { return; }
            R.stop();
            box.hidden = true;
        }

        function go(dry) {
            if (R.running()) { return; }
            var from = box.querySelector('#taccVatFrom').value, to = box.querySelector('#taccVatTo').value;
            var ids = JSON.parse(box.dataset.shops || '[]');
            var shops = CFG.shops.filter(function (s) { return ids.indexOf(s.blog) >= 0; });
            if (!from || !to || from > to) { alert('Khoảng ngày không hợp lệ.'); return; }
            if (!shops.length) { alert('Không có shop nào trong danh sách áp dụng thuế.'); return; }
            var pass = '';
            if (!dry) {
                if (!CFG.ready) { alert('Chưa cấu hình kết nối kế toán.'); return; }
                if (!confirm('Gửi chứng từ của ' + shops.length + ' shop, ' + fmtD(from) + ' → ' + fmtD(to) + ' sang kế toán?')) { return; }
                if (CFG.needPass) { pass = prompt('Mật khẩu thao tác:') || ''; if (!pass) { return; } }
            }
            var bDry = box.querySelector('#taccVatDry'), bGo = box.querySelector('#taccVatGo'), bStop = box.querySelector('#taccVatStop');
            bDry.disabled = bGo.disabled = true;
            bStop.hidden = false;
            R.run({
                shops: shops, from: from, to: to, dry: dry, force: box.querySelector('#taccVatForce').checked, password: pass,
                table: box.querySelector('#taccVatTable'), bar: box.querySelector('#taccVatBar'), note: box.querySelector('#taccVatSum'),
                onDone: function (t, stopped) {
                    bDry.disabled = bGo.disabled = false;
                    bStop.hidden = true;
                    box.querySelector('#taccVatSum').textContent = (stopped ? 'Đã dừng — ' : 'Xong — ') + (dry
                        ? 'dựng ' + num(t.built) + ', cần gửi ' + num(t.to_send) + ', không đổi ' + num(t.skipped)
                        : 'vào sổ ' + num(t.posted) + ', không đổi ' + num(t.skipped + t.unchanged) + ', lỗi ' + num(t.error + t.failed) + ', huỷ ' + num(t.cancelled));
                }
            });
        }

        btn.addEventListener('click', open);
    });
})();
