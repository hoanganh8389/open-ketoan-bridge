<?php
/**
 * DANH MỤC API — mọi lượt hệ thống bán hàng gọi sang phần mềm kế toán, một chỗ để xem.
 *
 * Trang "Cổng kế toán" (tab Danh mục API) đọc từ đây. Thêm luồng mới:
 *   1. viết hàm dựng chứng từ trong TGS_Acc_Mapper (+ đọc dữ liệu trong TGS_Acc_Source);
 *   2. khai một mục ở all() bên dưới (status 'live' khi đã chạy thật, 'planned' khi mới dự kiến);
 *   3. nếu có dữ liệu mẫu thật: khai 'sample' => tên loại chứng từ để tab Danh mục API cho "Xem dữ liệu thật".
 * Plugin khác thêm mục qua filter 'tgs_acc_bridge_api_catalog'.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Acc_Catalog
{
    /**
     * Mỗi mục:
     *   id, group, title, method, path, scope (phạm vi khoá API cần có), status (live|planned),
     *   trigger (khi nào gọi), source (lấy dữ liệu ở đâu bên bán hàng), key (khoá chống trùng),
     *   notes (luật quan trọng), fields (trường → nguồn), sample (docType có thể xem dữ liệu thật).
     */
    public static function all()
    {
        // Luật chung mọi chứng từ: chỉ phiếu ĐÃ DUYỆT (local_ledger_approver_status = 1), chưa xoá; ngày phiếu trong khoảng
        // HOẶC sửa trong khoảng. Sang kế toán luôn ở trạng thái "Chờ định khoản" (trạm ACC14), kế toán xem trước rồi mới ghi sổ.
        $common = [
            'Chỉ lấy phiếu đã duyệt (local_ledger_approver_status = 1), chưa xoá.',
            'Vào kế toán ở trạng thái Chờ định khoản (ACC14); kế toán xem trước bút toán rồi mới định khoản.',
            'Gửi lại không ghi trùng: cùng docKey + cùng nội dung → bỏ qua. Nội dung đổi → lên phiên bản, chờ định khoản lại.',
        ];
        $items = [
            [
                'id'      => 'gate',
                'group'   => 'Cổng chờ (kế toán kéo sang)',
                'title'   => 'Kế toán kéo chứng từ đang chờ',
                'method'  => 'GET',
                'path'    => '/wp-json/tgs-acc/v1/pending?from=&to=&shops=&cursor=',
                'scope'   => 'Bearer <khoá cổng chờ>',
                'status'  => 'live',
                'trigger' => 'Trạm ACC14 của kế toán: tự kéo khi mở màn (theo chi nhánh đang chọn) hoặc bấm "Kéo chứng từ".',
                'source'  => 'TGS_Acc_Pusher::build — dựng mọi loại chứng từ bên dưới cho từng shop.',
                'key'     => 'docKey từng chứng từ; nhật ký gửi (tgs_acc_push_log) ghi trạng thái gate',
                'notes'   => [
                    'Mỗi trang một shop (cursor = shop tiếp theo). shops = mã chi nhánh bên kế toán, chỉ quét đúng các shop đó.',
                    'Chỉ trả chứng từ chưa có ở kế toán hoặc đã đổi nội dung; kèm "cancelled" = phiếu đã xoá / bỏ duyệt ở shop.',
                    'Phiếu bị xoá KHÔNG tự huỷ bên kế toán: vào trạng thái Chờ huỷ, kế toán bấm định khoản huỷ.',
                    'consumer=tax (sổ thuế): chỉ trả phiếu bán / hoàn có số hoá đơn; nhật ký gửi riêng (tgs_acc_push_log_tax), báo nhận cũng kèm consumer=tax.',
                ],
                'fields'  => ['documents[]' => 'Chứng từ đang chờ', 'cancelled[]' => '{docKey, reason} phiếu đã xoá / bỏ duyệt', 'nextCursor' => 'Trang tiếp theo, null là hết'],
            ],
            [
                'id'      => 'gate_ack',
                'group'   => 'Cổng chờ (kế toán kéo sang)',
                'title'   => 'Kế toán báo đã nhận',
                'method'  => 'POST',
                'path'    => '/wp-json/tgs-acc/v1/pending',
                'scope'   => 'Bearer <khoá cổng chờ>',
                'status'  => 'live',
                'trigger' => 'Sau mỗi trang kéo về.',
                'source'  => '—',
                'key'     => 'docKey + version',
                'notes'   => ['Nhật ký gửi chuyển gate → posted (hoặc error); lần kéo sau không trả lại.', 'Máy chủ chặn POST thì báo nhận bằng GET ?ack=<JSON>, từng nhóm 20 phiếu.'],
                'fields'  => ['received[]' => '{docKey, version, status}', 'rejected[]' => '{docKey, message}'],
            ],
            [
                'id'      => 'sale',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu xuất bán (cả bill nội bộ Z)',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ (kế toán kéo) hoặc nút "Đẩy sang Kế toán" / cron day-ke-toan.php.',
                'source'  => 'Dòng VAT của tgs-bc-tk (vat_rows_for_export, kind=sale) — cùng luật tiền với màn Quản lý VAT; local_ledger loại 10.',
                'key'     => 'btsoft:{blog}:sale:{sale_id}',
                'notes'   => array_merge($common, [
                    'Bill Z: internalOnly = true, không kèm hoá đơn — chỉ vào sổ nội bộ.',
                    'Thuế bán ra: mã R05 / R08 / R10 / KCT (TK 33311).',
                    'Đổi trả tại POS: offsets[] trỏ phiếu hoàn được đối trừ (không có tiền thật).',
                    'Tiền thu đi riêng thành phiếu thanh toán (mục Phiếu thu / chi trả khách).',
                ]),
                'fields'  => [
                    'docNo / docDate' => 'Số phiếu xuất, ngày xuất (ngày hạch toán)',
                    'branchCode'      => 'Mã shop (tgs_site_code) = mã chi nhánh bên kế toán',
                    'partner'         => 'Khách: mã (SĐT), tên, MST, địa chỉ',
                    'lines[]'         => 'SKU, ĐVT, SL (ĐVT bán + quy đổi), đơn giá sau CK trước thuế, CK, tiền trước thuế, mã thuế, tiền thuế',
                    'invoice'         => 'Hoá đơn điện tử: ký hiệu, số, ngày (không gửi với bill Z)',
                    'offsets[]'       => 'Phiếu hoàn đối trừ: docKey, số tiền',
                ],
                'sample'  => 'sale',
            ],
            [
                'id'      => 'sale_return',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu hoàn hàng (hàng bán trả lại)',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cùng lượt với phiếu xuất bán.',
                'source'  => 'Dòng VAT kind=return (theo NGÀY PHIẾU HOÀN); local_ledger loại 11.',
                'key'     => 'btsoft:{blog}:return:{return_id}',
                'notes'   => array_merge($common, [
                    'originalDocKey trỏ về phiếu bán gốc; có hoá đơn điều chỉnh thì invoice.relation = "adjusts".',
                    'Thuế giảm đầu ra: mã R (TK 33311), không dùng mã V.',
                    'Hoàn đối trừ với đơn mới: không có phiếu chi, công nợ trừ qua offsets của phiếu bán mới.',
                ]),
                'fields'  => ['originalDocKey' => 'Khoá phiếu bán gốc', 'lines[]' => 'Dòng hàng trả lại (tiền DƯƠNG, loại chứng từ quyết định chiều)'],
                'sample'  => 'sale_return',
            ],
            [
                'id'      => 'customer_payment',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu thu / chi trả khách (theo đơn)',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cùng lượt với phiếu bán / hoàn.',
                'source'  => 'local_ledger loại 7 (thu) / 8 (chi trả khách) có phiếu cha là đơn bán / hoàn; phiếu cha cũng phải đã duyệt.',
                'key'     => 'btsoft:{blog}:receipt:{id} · btsoft:{blog}:refund:{id}',
                'notes'   => array_merge($common, [
                    'Mỗi phiếu thu / chi là một chứng từ riêng, theo ngày của chính phiếu (tách phiếu thì theo ngày phiếu cha).',
                    'Hình thức gửi đúng tên chi tiết (TM, CK, thẻ, ví…) — kế toán đổi mã ở cột "Đổi hình thức" của kết nối.',
                    'Sửa / tách phiếu thu ở POS: phiếu cũ vào Chờ huỷ, phiếu mới vào Chờ định khoản.',
                ]),
                'fields'  => ['direction' => 'in = thu, out = chi trả khách', 'methodCode / methodLabel' => 'Hình thức thanh toán', 'invoiceDocKey' => 'Đơn bán / hoàn được thu / chi'],
                'sample'  => 'customer_payment',
            ],
            [
                'id'      => 'purchase',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu nhập kho mua hàng (từ NCC)',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ.',
                'source'  => 'local_ledger loại 1 không có phiếu cha (màn Tạo phiếu nhập kho); NCC ở wp_global_supplier; hoá đơn = mã chứng từ nguồn + advance_meta.invoice.',
                'key'     => 'btsoft:{blog}:purchase:{id}',
                'notes'   => array_merge($common, ['Thuế đầu vào: mã V05 / V08 / V10 (TK 1331) — mã R của phiếu được đổi sang V.', 'Bỏ qua phiếu nhập thử (mã DCTEST…).']),
                'fields'  => ['partner' => 'Nhà cung cấp', 'invoice' => 'Ký hiệu, số, ngày hoá đơn đầu vào', 'lines[]' => 'SL × đơn giá − CK, tiền thuế từng dòng'],
                'sample'  => 'purchase',
            ],
            [
                'id'      => 'purchase_return',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu trả hàng NCC',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ.',
                'source'  => 'local_ledger loại 16 (màn Tạo phiếu trả hàng NCC).',
                'key'     => 'btsoft:{blog}:supplier-return:{id}',
                'notes'   => array_merge($common, ['Thuế đầu vào ghi giảm: mã V (TK 1331).']),
                'fields'  => ['partner' => 'Nhà cung cấp', 'lines[]' => 'Dòng hàng trả'],
                'sample'  => 'purchase_return',
            ],
            [
                'id'      => 'transfer',
                'group'   => 'Chứng từ',
                'title'   => 'Chuyển kho nội bộ (mỗi đầu một chứng từ)',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ + gửi tức thời (hook tgs_global_transfer_changed: tạo / sửa / duyệt / bỏ duyệt / từ chối / xoá).',
                'source'  => 'Phiếu chuyển kho global (wp_global_transfer, một phiếu hai đầu): đầu xuất của shop đi khi đã duyệt xuất và chưa bị từ chối (ngày phiếu), đầu nhận của shop đến khi đã duyệt nhận (ngày duyệt nhận, SL thực nhận).',
                'key'     => 'btsoft:{blog}:gt-out|gt-in:{transfer_id}',
                'notes'   => array_merge($common, [
                    'Chỉ gửi số lượng (tiền 0): kế toán định giá bằng giá vốn bình quân khi tính giá vốn tháng.',
                    'Chi nhánh = chi nhánh ghi trên phiếu ở đầu đó; mã kho từng dòng = phân kho của đầu đó (from_zone / to_zone).',
                    'Phiếu bị xoá, bỏ duyệt hoặc bên nhận từ chối → báo huỷ chứng từ của đầu tương ứng.',
                ]),
                'fields'  => ['leg' => 'out / in', 'toBranchCode / fromBranchCode' => 'Shop đầu kia (nếu biết)', 'lines[].warehouseCode' => 'Phân kho của đầu', 'extra.transferGroup' => 'Mã phiếu chuyển kho (ghép hai đầu)', 'extra.globalTransferId' => 'transfer_id'],
                'sample'  => 'transfer',
            ],
            [
                'id'      => 'stock_adjustment',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu điều chỉnh tồn kho',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ.',
                'source'  => 'local_ledger loại 21 có meta edit_type = inventory; mỗi dòng là chênh lệch số lượng có dấu.',
                'key'     => 'btsoft:{blog}:adjust:{id}',
                'notes'   => array_merge($common, ['direction: increase / decrease / mixed (dòng + thừa, − thiếu).', 'Chỉ gửi số lượng (tiền 0), định giá khi tính giá vốn tháng.']),
                'fields'  => ['direction' => 'increase / decrease / mixed', 'lines[].qty' => 'Chênh lệch (có dấu khi mixed)'],
                'sample'  => 'stock_adjustment',
            ],
            [
                'id'      => 'export_adjust',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu xuất điều chỉnh (xuất dùng, tặng, dùng thử, huỷ… — bill giấy)',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ.',
                'source'  => 'local_ledger loại 2 KHÔNG có phiếu cha: màn "Tạo phiếu xuất" (XSD-…, lý do ở advance_meta.import_reason) và POS "Phiếu xuất điều chỉnh" (lý do ở ghi chú wp_global_po_adjustment).',
                'key'     => 'btsoft:{blog}:export-adjust:{id}',
                'notes'   => array_merge($common, [
                    'Phiếu 0đ → "stock_adjustment" giảm kho kèm purposeCode (mục đích xuất) đổi từ lý do: XHH01 → HUY (6328), XSD1 → DUNG-BH (64128), XMKT1 → TANG-KH (64184)… (bảng TGS_Acc_Mapper::PURPOSE_OF_REASON).',
                    'Mục đích quyết định tài khoản (Quy tắc hạch toán ACC41 bên kế toán); lý do chỉ gửi làm nhãn. Lý do chưa có cặp mục đích → thiếu chờ xử lý (1381), kế toán soát.',
                    'Giá trị theo giá vốn bình quân khi tính giá vốn tháng. Phiếu có giá (thực chất là bán) → phiếu bán nội bộ (internalOnly) như cũ.',
                ]),
                'fields'  => ['purposeCode' => 'Mục đích xuất (đổi từ lý do)', 'reasonCode' => 'Mã lý do xuất (nhãn)', 'direction' => 'decrease', 'lines[]' => 'Dòng hàng: sku, qty, amount = 0'],
                'sample'  => 'stock_adjustment',
            ],
            [
                'id'      => 'cash_transfer',
                'group'   => 'Chứng từ',
                'title'   => 'Phiếu chuyển tiền giữa chi nhánh',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/batch',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Cổng chờ.',
                'source'  => 'tgs_pos bảng global_cash_transfer (màn "Tạo phiếu chuyển tiền"), theo shop lập phiếu (issuer_blog_id); phiếu chưa xoá, ngày chuyển hoặc ngày sửa / xác nhận trong khoảng.',
                'key'     => 'btsoft:{blog lập phiếu}:cash_transfer:{ct_id}',
                'notes'   => [
                    'Như HTsoft: lập phiếu là tiền đã rời nơi chuyển → status sent (Nợ 11319 / Có tiền nơi chuyển). Kế toán (không phải nhân viên cửa hàng) xác nhận đã nhận tiền ở ACC14 → kế toán ghi Nợ tiền nơi nhận / Có 11319; màn POS đọc lại xác nhận để hiện trạng thái và khoá sửa.',
                    'Mã chi nhánh gửi / nhận = mã chi nhánh HTsoft trên phiếu (không phải mã shop). Ngân hàng rút / nộp trống = tiền mặt.',
                    'Lý do chi / thu gửi nguyên; CPCT / TPCT (không ghi sổ) bên kế toán bỏ qua. Lý do thu TBNH (13681) → nơi chuyển Nợ 33681.',
                    'Phiếu đã gửi mà bị xoá → báo huỷ.',
                ],
                'fields'  => ['branchCode / toBranchCode' => 'Chi nhánh gửi / nhận (mã HTsoft)', 'fromBankCode / toBankCode' => 'Mã ngân hàng rút / nộp (nếu có)', 'reasonCode / toReasonCode' => 'Lý do chi / lý do thu', 'status' => 'sent (chờ xác nhận) / received (đã xác nhận)'],
                'sample'  => 'cash_transfer',
            ],
            [
                'id'      => 'cancel',
                'group'   => 'Chứng từ',
                'title'   => 'Báo phiếu đã xoá / bỏ duyệt',
                'method'  => 'POST',
                'path'    => '/api/v1/documents/cancel',
                'scope'   => 'documents:write',
                'status'  => 'live',
                'trigger' => 'Luồng đẩy: phiếu đã gửi mà nay đã xoá / bỏ duyệt ở shop. Cổng chờ: trả trong "cancelled".',
                'source'  => 'So nhật ký gửi (tgs_acc_push_log) với chứng từ dựng lại + phiếu is_deleted / bỏ duyệt trong khoảng.',
                'key'     => 'docKey của chứng từ',
                'notes'   => ['Bên kế toán KHÔNG tự huỷ: chứng từ vào Chờ huỷ ở ACC14, kế toán bấm định khoản huỷ (ghi số âm, không xoá dòng sổ).', 'Phiếu được khôi phục / gửi phiên bản mới thì bỏ cờ chờ huỷ.'],
                'fields'  => ['docKey' => 'Khoá chứng từ', 'reason' => 'Lý do (ghi vào nhật ký bên kế toán)'],
            ],
            [
                'id'      => 'branches',
                'group'   => 'Danh mục',
                'title'   => 'Chi nhánh (đọc + tạo khi thiếu)',
                'method'  => 'GET / POST',
                'path'    => '/api/v1/branches',
                'scope'   => 'catalog:write',
                'status'  => 'live',
                'trigger' => 'Đầu mỗi lượt đẩy của một shop; nút "Kiểm tra kết nối".',
                'source'  => 'tgs_site_code + tên shop (tgs_shop_name / blogname).',
                'key'     => 'code = mã shop; externalRef = blog:{blog_id}',
                'notes'   => ['Chỉ tạo khi chưa có, không sửa tên chi nhánh đã có bên kế toán.'],
                'fields'  => ['code' => 'Mã shop', 'name' => 'Tên shop', 'externalRef' => 'blog:{id}'],
            ],
            [
                'id'      => 'catalog_feed',
                'group'   => 'Danh mục',
                'title'   => 'Danh mục hàng lấy từ kế toán (mặt hàng, ĐVT, bảng giá, giá)',
                'method'  => 'GET',
                'path'    => '/api/v1/catalog/feed/meta?since= · /api/v1/catalog/feed/products?after= · /api/v1/catalog/feed/prices?after=',
                'scope'   => 'catalog:read',
                'status'  => 'live',
                'trigger' => 'Kế toán báo ngay mỗi lần sửa (POST /wp-json/tgs-acc/v1/catalog-changed, khoá cổng chờ) → kéo về; cron 5 phút làm lưới an toàn. Chỉ chạy khi bật (danh-muc-hang.php bat). Kéo tay: danh-muc-hang.php keo.',
                'source'  => 'TGS_Acc_Catalog_Sync — ghi vào wp_global_product_name, _htsoft_unit_base, _htsoft_stock_convert, _htsoft_price_list(_blog), _milk_under24m.',
                'key'     => 'Con trỏ updated_at|id từng luồng (site option tgs_acc_catalog_cursor)',
                'notes'   => [
                    'Chỉ ghi cột khác; xoá bên kế toán → xoá mềm bên này. Ghi xong báo tgs_cache làm mới gói giá POS.',
                    'Bật thì chặn MỌI AJAX ghi bảng danh mục hàng bên này (trừ thuế suất) — sửa ở kế toán DM10 / DM14.',
                    'Nhóm hàng chỉ thêm khi mặt hàng cần, không đổi tên nhóm đang có. Mã mới chưa khai ĐVT không xuống bán hàng.',
                    'Thuế suất chưa đồng bộ (TGS_Acc_Catalog_Sync::SYNC_TAX) — hai bên còn lệch.',
                    'Nạp ban đầu một lần: POST /api/v1/catalog/import (catalog:write) — danh-muc-hang.php nap.',
                ],
                'fields'  => ['products[]' => 'Mặt hàng + units[] (ĐVT, tỉ lệ, bán chính)', 'prices[]' => 'Bảng giá × SKU × ĐVT → giá', 'meta' => 'Bảng giá, chi nhánh áp bảng giá, nhóm hàng, mục đã xoá'],
            ],
            [
                'id'      => 'reconcile',
                'group'   => 'Đối chiếu',
                'title'   => 'Đối chiếu số bán hàng ↔ kế toán theo ngày',
                'method'  => 'GET',
                'path'    => '/api/v1/reports/sales-summary · /api/v1/reports/sales-payments · /api/v1/documents?status=error',
                'scope'   => 'reports:read',
                'status'  => 'live',
                'trigger' => 'Dòng lệnh tools/doi-chieu-ke-toan.php (chỉ đọc hai bên).',
                'source'  => 'Dựng lại chứng từ từ sổ bán hàng ngay lúc chạy, cộng theo ngày.',
                'key'     => '—',
                'notes'   => ['So số phiếu bán / trả, tiền hàng thuần, thuế thuần, tiền thu theo hình thức; liệt kê chứng từ đang Lỗi bên kế toán.'],
                'fields'  => [],
            ],
            [
                'id'      => 'health',
                'group'   => 'Hệ thống',
                'title'   => 'Kiểm tra máy chủ kế toán sống',
                'method'  => 'GET',
                'path'    => '/api/v1/system/health',
                'scope'   => '(công khai)',
                'status'  => 'live',
                'trigger' => 'Nút "Kiểm tra kết nối" trên trang này.',
                'source'  => '—',
                'key'     => '—',
                'notes'   => [],
                'fields'  => [],
            ],
            // ── Dự kiến: chưa có mã gửi. ──
            [
                'id' => 'cash', 'group' => 'Dự kiến', 'title' => 'Phiếu thu / chi khác (không gắn đơn bán)',
                'method' => 'POST', 'path' => '/api/v1/documents/batch (docType: cash_receipt / cash_payment)', 'scope' => 'documents:write', 'status' => 'planned',
                'trigger' => 'Chưa làm.', 'source' => 'Phiếu thu loại 7 / chi loại 8 không có phiếu cha.', 'key' => 'btsoft:{blog}:cash:{id}', 'notes' => [], 'fields' => [],
            ],
            [
                'id' => 'supplier_payment', 'group' => 'Dự kiến', 'title' => 'Trả tiền nhà cung cấp / NCC hoàn tiền',
                'method' => 'POST', 'path' => '/api/v1/documents/batch (docType: supplier_payment)', 'scope' => 'documents:write', 'status' => 'planned',
                'trigger' => 'Chưa làm.', 'source' => 'tgs_money_voucher, wp_global_supplier_payment; phiếu thu loại 7 con của phiếu trả NCC.', 'key' => 'btsoft:{blog}:supplier-payment:{id}', 'notes' => [], 'fields' => [],
            ],
        ];
        return (array) apply_filters('tgs_acc_bridge_api_catalog', $items);
    }

    public static function get($id)
    {
        foreach (self::all() as $it) {
            if (($it['id'] ?? '') === $id) {
                return $it;
            }
        }
        return null;
    }
}
