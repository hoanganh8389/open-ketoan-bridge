# Census — open-ketoan-bridge (`tgs_accounting_bridge` 0.5.0), branch `main` @ eb778ae

Read-only inventory, 2026-11-21. All paths relative to `d:/OneDrive/Code/huongnguyen.vibeyeu.com.vn/wp-content/plugins/open-ketoan-bridge/`.
Target design read from `docs/cau-hinh-ket-noi:docs/CAU-HINH-KET-NOI.md`; C1 config read from `feat/c1-cau-hinh-theo-blog:includes/class-tgs-acc-config.php` (not censused as main code).

Legend for tables: **G** = global/network (base_prefix or site option), **B** = per-blog (`get_blog_prefix($blog)`), **F** = filesystem.

---

## 1. Per class / file

### `open-ketoan-bridge.php` (88 lines) — bootstrap
`Network: true` (header line 7). Defines `TGS_ACC_BRIDGE_VERSION`, `TGS_ACC_BRIDGE_PATH` (53-54); requires the 19 includes (56-74). Hooks: `plugins_loaded` → `TGS_Acc_Admin::init`, `TGS_Acc_Embed::init` (76-77); immediate `::init()` of Supplier_Sync, Warehouse_Sync, Branch_Sync, Ketoan_Events, Catalog_Sync, Gate, Events (78-85); `admin_init` → `TGS_Acc_Push_Log::maybe_install` (88). The header comment (9-15) documents the **network-wide** constants `TGS_KETOAN_API_URL/KEY/WEB_URL`. No config read itself. Note: `tools/*.php` and `TGS_Acc_Admin::asset()` still reference the old filename `tgs-accounting-bridge.php` (see §8).

### `includes/class-tgs-acc-config.php` (245) — `TGS_Acc_Config`
Purpose: connection settings. One option `tgs_acc_bridge_config` read with `get_site_option` (40) and written with `update_site_option` (242) — **single network-wide store**. Constants win: `TGS_KETOAN_API_URL` (46), `TGS_KETOAN_WEB_URL` (59), `TGS_KETOAN_SSO_SECRET` (73), `TGS_KETOAN_API_KEY` (86), `TGS_KETOAN_TAX_API_URL/KEY` (95, 103). Keys: `url`, `web_url`, `key`, `timeout`, `chunk_days`, `gate_key`, `realtime`, `tax_url`, `tax_key`, `sso_secret`, `sso_tenant`, `catalog_from_erp` (22-35). `ready()` = url && key (120-123); `tax_ready()` (109-112); `get($name)` (114-118); `for_display()` (145-170) masks keys; `save()` (176-244). No tables, no switch_to_blog, no blog_id argument anywhere. Tenant assumption: **one key = one tenant for the whole network**. C1 branch adds `key_for($blog)`, `key_legacy()`, `url_for()`, `current_blog()`, `key_mode()`, `save_blog()`, `for_display_blog()`, `BLOG_FIELDS` (C1 diff lines 36-364), keeping `key()`/`url()` backward compatible.

### `includes/class-tgs-acc-api-client.php` (301) — `TGS_Acc_Api_Client`
HTTP client. Constructor defaults to `TGS_Acc_Config::url()/key()` (24-25) — **network key unless caller passes one**. Header `X-Api-Key` (262); timeout `TGS_Acc_Config::get('timeout')` (261). Call log: site option `tgs_acc_bridge_calls` ring of 60 (29-55, `get_site_option` 34/40, `update_site_option` 54) — **G**, no blog_id column. Methods: `health()` GET `/api/v1/system/health` no key (58-62); `ready()` (64); `send_batch()` POST `/api/v1/documents/batch` (73-80); `cancel()` POST `/api/v1/documents/cancel` (83-87); `ping()` GET `/api/v1/branches` (90-94); `get()`, `post_json()`, `quick_get()` 8 s (100-125); `receipt_confirmations()` GET `/api/v1/documents/receipt-confirmations?docKeys=` (131-146); `ensure_branch()` GET+POST `/api/v1/branches` with `externalRef` (152-165); `find_partner_by_phone()` GET `/api/v1/partners?search=` (183-209); `upsert_partner()` POST `/api/v1/partners/upsert` (222-232); `download()` raw GET with key (238-252). No tables other than the option; no switch_to_blog. Tenant assumption: tenant is whatever the key resolves to; `ensure_branch` presumes one tenant holding many branches.

### `includes/class-tgs-acc-source.php` (742) — `TGS_Acc_Source`
Reads one shop's documents by explicit `$blog_id` and `get_blog_prefix` (never switch_to_blog). `ready()` = `class_exists('TGS_BCTK_Ajax') && method_exists(..., 'vat_rows_for_export')` (21-24) — hard dependency on tgs-bc-tk for sale/return. `sale_rows()` (30-40) → `TGS_BCTK_Ajax::vat_rows_for_export($blog,$from,$to,'sale','all',$ids)` + `export_reasons()` from `{B}local_ledger.local_ledger_advance_meta` JSON `$.export_reason.code` (43-63). `return_rows()` (69-77) → `vat_rows_for_export(...,'return')`. `only_approved()` filters `local_ledger_approver_status = 1` + `not_mirror_sql` (`local_ledger_source_id` not `pg:*`) (90-123). **`branch_of_blog()`** (129-158): site option `tgs_multisite_hierarchy_data` (or `TGS_HIERARCHY_OPTION_KEY`) `sites[blog].htsoft_srid` → **G** table `{base}htsoft_chinhanh` (SRID→TEN) — static cache per request. **`shop_code()`** (161-177): branch_of_blog, else `TGS_BCTK_Sites::list_sites()` code/name. `payment_rows()` (184-244): **B** `local_ledger` types 7/8 joined to parent 10/11, `local_ledger_meta`, `local_ledger_person`. `supplier_doc_rows()` (252-303): **B** `local_ledger` type 1 (no parent) / 16, **G** `{base}global_supplier`. `supplier_doc_items()` (368-403): **B** `local_ledger_item` joined **G** `{base}global_product_name` for name; falls back to child type 1/2 ledgers. `export_adjust_rows()` (314-362): type 2 no parent; **G** `{base}global_po_adjustment`. `global_transfer_rows()`/`global_transfer_gone_keys()` (412-479): `TGS_Global_Transfer::table()` (G). `adjustment_rows()` (486-542): type 21 with meta `edit_type=inventory`. `deleted_keys()` (550-587): builds `btsoft:{blog}:{name}:{id}` for deleted/unapproved; `cash_transfer_table()` **G** `{base}global_cash_transfer` (590-599); `cash_transfer_rows()` (606-622). `changed_before()` (628-648); `offsets_by_sale()` (654-685); `receipts_by_sale()/refunds_by_return()/children_by_parent()` (688-741, unused by pusher now). Config: none directly. Tenant/branch: `branch_of_blog`, `shop_code` — branch code derived from network hierarchy.

### `includes/class-tgs-acc-mapper.php` (749) — `TGS_Acc_Mapper`
Pure transform VAT row / POS row → open-ketoan document. `method_map()` filter `tgs_acc_method_map` (21-31), `method_code()` (33-52), `label_code()` (59-71), `tax_code()` (77-86) → `KCT|R00|R05|R08|R10`. `lines()` (96-137) reads tgs-bc-tk item fields (see §3). `partner()` (151-166), `employee()` (169-183, `get_userdata`), `invoice()` (186-206, provider hard-coded `viettel`). Documents: `sale()` (213-255) docKey `btsoft:{blog}:sale:{sale_id}`, **`branchCode` = `TGS_Acc_Source::branch_of_blog($blog) ?: $r['ma_shop']`** (221); `sale_return()` (261-299) key `…:return:{return_id}`, `originalDocKey` (283); `export_adjust()` (346-434) `…:export-adjust:{id}`, `PURPOSE_OF_REASON` (319-330); `supplier_doc()` (436-537) `…:purchase:{id}` / `…:supplier-return:{id}`, input tax `V..` (459); `global_transfer()` (545-618) `…:gt-out|gt-in:{transfer_id}`, branch from transfer row (586), `toBranchCode/fromBranchCode` via `TGS_Acc_Source::shop_code($other_blog)` (606-616) — **cross-blog branch lookup**; `adjustment()` (625-667) `…:adjust:{id}`; `cash_transfer()` (677-712) `…:cash_transfer:{ct_id}`, `branchCode`/`toBranchCode` = HTsoft branch codes on the row (692-693); `payment()` (714-748) `…:receipt:{id}` / `…:refund:{id}`, `invoiceDocKey` = parent key (722). No DB, no config, no switch_to_blog. Tenant assumption: every doc carries `branchCode`; `sourceSystem: 'btsoft'`; `extra.blogId` (230, 279, 400, 424, 483, 597, 665, 703, 737).

### `includes/class-tgs-acc-push-log.php` (257) — `TGS_Acc_Push_Log`
Push log in **G** tables `{base}tgs_acc_push_log` and `{base}tgs_acc_push_log_tax` (40-44, consumer switch 30-38), plus `{base}tgs_acc_push_run` (46-50). Install via `dbDelta` gated by site option `tgs_acc_push_log_db` = `DB_VERSION '3'` (24-25, 53-103). Columns: `blog_id, doc_key UNIQUE(160), doc_type, doc_no, doc_date, version, content_hash CHAR(32), status, message, pushed_at` (63-78). `find_many()` (106-120); `keys_in_range()` by blog/type/date, status in `posted,error,gate` (123-131); `cancel_keys()` (137-154); `save()` upsert (156-169); `mark()` (171-175); `save_run()` keeps 5000 (178-205); `runs()`, `search()`, `stats()` (208-256). No switch_to_blog. Tenant assumption: **one log for all blogs; `doc_key` unique across network; no tenant/server column** (the admin warns on URL/key change, admin.php 401-405).

### `includes/class-tgs-acc-pusher.php` (504) — `TGS_Acc_Pusher`
Orchestrator. `hash()` md5 of doc without `version` (24-28). `build($blog,$from,$to,$only_ids)` (34-153): `$code = TGS_Acc_Source::branch_of_blog($blog)` (41), fallback `ma_shop` from first VAT row (70, 78), then `shop_code()` (82); gathers sale/return/payment/supplier/global transfer/export-adjust/adjustment/cash-transfer; `tag_warehouses()` reads **B** `local_ledger_item.local_ledger_item_warehouse_zone` (164-205); `TGS_Acc_Warehouse_Sync::normalize()` (145). `push()` (212-220) → `push_inner()` (488-503) → `send()` + `cancel()`. `$source_types`/`decorate()` add `sourceRef` (223-239). `build_refs()` (249-329) for event path: reads **B** `local_ledger` days, **G** `global_cash_transfer` (290). `gone_keys()` (335-366) queries push log with `LIKE 'btsoft:{blog}:%:{id}'`. **`send()`** (373-455): version = prev+1 if hash differs (389); skip if same hash & posted & no `force` (385); client = `$opts['client'] ?? new TGS_Acc_Api_Client()` (403) — **network key by default**; **branch auto-create** (405-424): `$code = $own[0]['branchCode']`, cache site option `tgs_acc_known_branches` (`_tax` suffix for tax consumer) (412-413), `ensure_branch($code, tgs_shop_name|blogname, 'blog:'.$blog)` (415-416), `update_site_option` (421); batches of 100 (21, 426). `cancel()` (461-486): 404 → mark cancelled locally. No switch_to_blog (`get_blog_option` 415). Tenant assumption: one tenant, blog = branch, branch created on demand.

### `includes/class-tgs-acc-catalog.php` (335) — `TGS_Acc_Catalog`
Static descriptive catalog of API flows for the admin tab (`all()` 24-324, filter `tgs_acc_bridge_api_catalog` 323; `get()` 326-334). Documents docKey patterns and states `branchCode = mã shop (tgs_site_code)` (86, 258-259) — **documentation embeds the branch model**. No hooks, DB, config.

### `includes/class-tgs-acc-lists.php` (164) — `TGS_Acc_Lists`
Read-only ERP lists (reasons, payment_methods, tax_codes, branches, warehouses, departments, cost_items, purposes, accounts) (29-119). `rows()` (125-156) caches in site option `tgs_acc_lists_cache` 10 min (22-23, 131, 154) — **G, shared across blogs, keyed by kind only**; uses `TGS_Acc_Config::key()` check (135) and default client (138). Tenant assumption: one tenant's catalogs for everyone.

### `includes/class-tgs-acc-admin.php` (408) — `TGS_Acc_Admin`
Admin page + AJAX. Hooks: filters `tgs_shop_dashboard_routes` (27), `tgs_shop_workflow_nav` (28); `admin_enqueue_scripts` (29); AJAX `wp_ajax_tgs_acc_{overview,push,log,runs,sample,ping,save,calls,lists,events,events_retry}` (30-32). Caps: `TGS_BCTK_CAPABILITY` or `manage_network_options` (37-51); real send requires `TGS_Book_Close::PASSWORD` (63-70). `shops()` (151-166): `TGS_BCTK_Vat_Shops::active_blog_ids()` + filter `tgs_acc_bridge_blog_ids`, code from **G** `wp_blogs.tgs_site_code` (155, 161), name via `get_blog_option` (162). `ajax_push()` (235-263) → `TGS_Acc_Pusher::push($blog,…,['origin'=>'web'])`; requires `TGS_Acc_Source::ready()` (247) and `TGS_Acc_Config::ready()` (250). `ajax_sample()` (266-299), `ajax_log/runs/calls/events/events_retry/lists` (301-361), `ajax_ping()` (363-384) network client, `ajax_save()` (386-407) `TGS_Acc_Config::save` + warning on URL/key change (401-405). `asset()` uses old filename `tgs-accounting-bridge.php` (106) — harmless for `plugins_url`. No switch_to_blog. Tenant assumption: one settings page for the network; shop list from tgs-bc-tk.

### `includes/class-tgs-acc-gate.php` (272) — `TGS_Acc_Gate`
REST pull gate. `rest_api_init` → routes (36-39, 62-71): `GET/POST tgs-acc/v1/pending`, `GET tgs-acc/v1/sites`. Auth `authorized()` (120-131): `Authorization: Bearer <gate_key>` or `X-Api-Key` compared with `key()` (46-60) = `TGS_ACC_GATE_KEY` or `TGS_Acc_Config::get('gate_key')`, auto-generated and stored via `update_site_option` (53-57) — **one gate key for the network**. `sites()` (79-117): **G** `tgs_multisite_hierarchy_data`, `{base}htsoft_chinhanh`, `{base}blogs(.tgs_site_code)`. `blogs()` (138-167): `?shops=` branch codes → blog ids via `TGS_BCTK_Sites::list_sites()` + `branch_of_blog` (141-158); `?blogs=`; else all sites except main (163-166). `pending()` (187-237): cursor = index into blog list (205-209), `TGS_Acc_Pusher::build`, writes log status `gate` (226), `cancelled[]` from `cancel_keys`+`deleted_keys` (233). `acknowledge()`/`apply_ack()` (239-271) update **G** push log by `doc_key`+`version`. `consumer()` tax switch (173-178), `for_tax_book()` (181-185). No switch_to_blog. Tenant assumption: **one caller (one ERP tenant) may pull every blog**; routing by branch code.

### `includes/class-tgs-acc-events.php` (578) — `TGS_Acc_Events`
Realtime push. Hooks: filter `query` priority 9999 (59), action `tgs_acc/event` (60), `tgs_global_transfer_changed` (61), `shutdown` (62), `cron_schedules` + cron `tgs_acc_events_retry` every minute scheduled only on main site (63-65, 92-104). `enabled()` = `TGS_Acc_Config::get('realtime')` and `ready()||tax_ready()` (71-77); `targets()` (80-90). `table_info()` regex `{base}(N_)?local_ledger(_item)?` / `{base}global_cash_transfer` → blog from prefix (109-120). Event files **F** `wp-content/uploads/tgs-acc-events/YYYY/MM/YYYY-MM-DD(.retry).jsonl` (41, 230-261). `flush()` (320-370): groups per blog, `build()` → `TGS_Acc_Pusher::build_refs` (423-434) requires `TGS_Acc_Source::ready()` (426), `deliver()` (441-476): tax target uses `new TGS_Acc_Api_Client(tax_url, tax_key)` (453), internal target `new TGS_Acc_Api_Client()` (457) — **network key**. `retry()` (505-543) lock site option `tgs_acc_events_retry_lock` (511-515, 540). `overview()` (548-577). No switch_to_blog. Tenant assumption: one target per consumer for all blogs.

### `includes/class-tgs-acc-embed.php` (203) — `TGS_Acc_Embed`
ERP entry + SSO. Hooks: `tgs_shop_dashboard_routes` (53), `tgs_shop_workflow_nav` (54), `admin_init` redirect (55), `admin_post(_nopriv)_tgs_acc_sso_go` (57-58). `erp_url()` = `web_url()`/`url()` (61-66); `open_url()` (72-83); `erp_permissions()` via `TGS_Permission::user_can_view_menu` + `PERM_MAP` (119-140); `ticket()` (149-166): JSON `{email,name,login,perms,tenant: TGS_Acc_Config::sso_tenant(),iat,exp}` HMAC-SHA256 with `sso_secret`, TTL 120 s (28); `sso_go()` (184-202). Tenant assumption: **single `sso_tenant` for the network** (161); perms are network-wide (super admin = all).

### `includes/class-tgs-acc-catalog-sync.php` (1037) — `TGS_Acc_Catalog_Sync`
Pulls item catalog from ERP into **global** tables. See §4. Hooks: cron `tgs_acc_catalog_sync` every 5 min (100-104, 113-117), `wp_ajax_tgs_acc_catalog_sync` (105), `rest_api_init` route `/catalog-changed` (106, 151-170), when enabled: filters `tgs_catalog_readonly`, `tgs_price_edit_locked` → true (119-120), messages (121-125), `admin_init` → `block_writes` priority 1 (128). Config: `enabled()` = `TGS_ACC_CATALOG_FROM_ERP` or `TGS_Acc_Config::get('catalog_from_erp')` (172-178); `cron()` also needs `TGS_Acc_Config::ready()` (182). Site options: `tgs_acc_catalog_cursor`, `tgs_acc_catalog_cursor_version`, `tgs_acc_catalog_sync_at`, `tgs_acc_catalog_sync_lock`, `tgs_acc_catalog_sync_dirty` (44-61). Tables (all **G** via `t()` = base_prefix, 848-852): `global_product_name`, `global_product_cat`, `global_htsoft_unit_base`, `global_htsoft_stock_convert`, `global_htsoft_price_list`, `global_htsoft_price_list_blog`, `global_milk_under24m`, `wp_blogs.tgs_site_code` (855-866). No switch_to_blog. Tenant assumption: one tenant's catalog = the whole network's catalog; branch→price-list by `tgs_site_code`.

### `includes/class-tgs-acc-warehouse-sync.php` (207) — `TGS_Acc_Warehouse_Sync`
Hooks: cron `tgs_acc_warehouse_sync` hourly (34-39), `wp_ajax_tgs_acc_sync_warehouses` (40). `ready()` = url && key (45-47). `branches()` cache site option `tgs_acc_warehouse_map` 10 min (25, 53-64, 101) from GET `/api/v1/warehouses` (87) — **G, keyed by branchCode**. `default_code($blog)` via `shop_code` (67-79). `normalize(&$docs)` drops `warehouseCode` not in `map[doc.branchCode]` (110-132). `sync($blog_ids)` (138-183): all sites by default (145), per-blog **B** table `{prefix}local_warehouse_zone` insert/update/hide (152-178), `update_blog_option(MAP.'_at')` (180). `maybe_sync()`/`ajax_sync()` current blog (185-206). Uses `get_blog_prefix`, not switch_to_blog. Tenant assumption: one ERP warehouse list for all branches; blog ↔ branch via `shop_code`.

### `includes/class-tgs-acc-supplier-sync.php` (158) — `TGS_Acc_Supplier_Sync`
Hooks: cron `tgs_acc_supplier_sync` hourly (30-35), `wp_ajax_tgs_acc_sync_suppliers` (36). `sync()` (57-128): requires url && key (59); GET `/api/v1/partners?kind=supplier&status=active&pageSize=100000` (64); writes **G** `{base}global_supplier` (or `TGS_TABLE_GLOBAL_SUPPLIER`) (39-43, 100, 112, 121); last-run site option `tgs_acc_supplier_sync_at` (24, 48, 63, 126). `picker_rows()` (131-144). Tenant assumption: one tenant's suppliers into one global table.

### `includes/class-tgs-acc-branch-sync.php` (181) — `TGS_Acc_Branch_Sync`
Hooks: cron singles `tgs_acc_branch_sync_site`/`_all` (36-37), `wp_initialize_site` (38), `update_site_option_/add_site_option_tgs_multisite_hierarchy_data` (39-41), `wp_ajax_tgs_acc_sync_branches` (42). `ready()` url && key (45-48). `code_of($blog)` = `branch_of_blog` else `TGS_BCTK_Sites` code (78-92); `name_of()` `get_blog_option tgs_shop_name|blogname` (94-97). `sync()` (105-166): GET `/api/v1/branches` once, POST missing with `externalRef 'blog:N'` (144), updates site option `tgs_acc_known_branches` (29, 124, 159), `tgs_acc_branch_sync_last` (32, 164), then `TGS_Acc_Warehouse_Sync::sync($touched)` (162). `ajax_sync()` requires `can_configure` (169-180). Tenant assumption: **every blog becomes a branch in the one tenant** — the direct opposite of one-blog-one-tenant.

### `includes/class-tgs-acc-ledger-mirror.php` (364) — `TGS_Acc_Ledger_Mirror`
Writes ERP-originated docs into a shop's ledger. `apply($doc)` (44-64) validates `blog_id`, `id` (uuid), `entity ∈ ENTITIES` (31), version; **`in_blog()` uses `switch_to_blog`** (116-139) inside a transaction, detaching `TGS_Acc_Events::on_query` (344-363), fires `tgs_acc_ledger_mirrored`. `write()` (141-250): **B** `{prefix}tgs_pos_mirror` state table (pg_id, role, ledger_id, entity, version) (317-336, blog option `tgs_pos_mirror_table`), `{prefix}local_ledger`, `local_ledger_item`, `local_ledger_meta`, `local_ledger_person`; sets `local_ledger_source_id = 'pg:<id>'` (179). `cancel()` soft-deletes (67-87); `has_mirror()`, `ledger_id_of()` by `get_blog_prefix` (90-113). No config. Tenant assumption: none (blog chosen by caller).

### `includes/class-tgs-acc-trade-mapper.php` (361) — `TGS_Acc_Trade_Mapper`
TradeDoc (ERP `phieu-ban`) → mirror body. `sale_to_mirror()` (32-145): ledger roles `sale` (10), `receipt_N` (7), `export` (2); meta `ketoan_doc_key 'phieu-ban:<id>'` (72), `invoice{no,serial,date}` into sale meta (75-77); `return_to_mirror()` (154-235): `return` (11) + `payment_N` (8). `catalog()` reads **G** `{base}global_product_name` (315-326) — **assumes global catalog** (must become per-blog when tsm moves catalog). `export_code()` reads current-blog `{prefix}tgs_pos_mirror`/`local_ledger` (340-354) — relies on caller having switched blog. Tenant assumption: none.

### `includes/class-tgs-acc-ketoan-events.php` (315) — `TGS_Acc_Ketoan_Events`
Inbound webhook. Route `POST tgs-acc/v1/ketoan-events` (41-45). Auth `verify()` (54-77): header `Bizcity-Signature: t=<ts>,v1=<hex HMAC-SHA256(TGS_KETOAN_WEBHOOK_SECRET, "<t>.<body>")>`, skew 300 s (32); secret only from constant (48-51) — **one webhook secret for the network**. `handle()` (80-102): idempotency in **G** `{base}tgs_acc_ketoan_inbox` (272-296, site option `tgs_acc_ketoan_inbox`). `process()` (105-164): **blog resolved by `blog_for_branch(data.branchCode)`** (121, 249-258 — iterates all sites comparing `TGS_Acc_Source::shop_code`); event `tenantId` in envelope is **ignored**; `switch_to_blog` for mapping (152-159), `TGS_Acc_Ledger_Mirror::apply/cancel`. `pos_return()` (172-246) same branch lookup (174), switch_to_blog (231-241), reads **B** `local_ledger_item` (195). `user_id()` (261-270) searches users of current blog. Tenant assumption: branch code is the routing key; `tenantId` unused.

### `admin/page-cong-ke-toan.php` (282)
Markup for tabs Tổng quan / Sự kiện / Gửi / Nhật ký / Lượt gửi / Danh mục API / Danh mục kế toán / Lượt gọi / Cài đặt. Reads `TGS_Acc_Config::for_display()` (18), `TGS_Acc_Catalog::all()` (19). Settings form fields: url, key, web_url, sso_secret, sso_tenant, timeout, chunk_days, tax_url, tax_key, realtime (225-252); buttons save/ping/sync-branches (255-257); gate URL + key printed for `can_configure` (262-273, `TGS_Acc_Gate::url()/key()`); cron hints with old path `tgs_accounting_bridge/tools/...` (277-279). Single network form — no per-blog section.

### `admin/page-ke-toan.php` (17)
Legacy "Kế toán" view; only prints `TGS_Acc_Embed::open_url()` link (9-16).

### `assets/cong-ke-toan.js` (357)
Page controller. Uses `tgsAccBridge` (nonce, shops, ready, chunkDays) and `TaccRunner`. AJAX actions: `tgs_acc_overview` (67), `tgs_acc_log` (152), `tgs_acc_runs` (187), `tgs_acc_calls` (192), `tgs_acc_events`/`_retry` (213, 228), `tgs_acc_lists` (252, 259), `tgs_acc_sample` (287), `tgs_acc_save` (309), `tgs_acc_ping` (324), `tgs_acc_sync_branches` (342). Shop picker from `CFG.shops` (41-50). Save payload keys (300-308). No tenant notion.

### `assets/push-runner.js` (143)
`TaccRunner.post/chunks/run/stop`: iterates shop × date-chunk, POST `tgs_acc_push` with `blog, from, to, dry, force, password` (102-104). Stops on messages matching `mật khẩu|cấu hình|quyền` (126).

### `assets/vat-push.js` (111)
Adds "⇄ Đẩy sang Kế toán" button to tgs-bc-tk VAT screens (`.bctk-result__head`, 16-23); shops from `.bctk-site:checked` (26-32); runs `TaccRunner.run` (96-106).

### `assets/cong-ke-toan.css` — styling only.

### `tools/day-ke-toan.php` (146) — see §8.
### `tools/doi-chieu-ke-toan.php` (229) — see §8.
### `tools/danh-muc-hang.php` (144) — see §8.
### `tools/htsoft-chi-doc.php` (233) — read-only MSSQL explorer of legacy HTsoft via `tgs_htsoft_connector/config.php` (30); no WP, no bridge config. Unrelated to tenancy.
### `tools/xuat-tai-san-cu.php` (228) — legacy fixed-asset CSV export from HTsoft MSSQL (45-61); no WP. Unrelated.

---

## 2. Network-wide assumptions (wrong under one-blog-one-tenant)

### 2.1 Single key / single URL
| Where | file:line | Why wrong |
|---|---|---|
| `TGS_Acc_Config::key()/url()` from one site option / constants | config.php:40, 44-50, 84-90, 242 | One tenant for all blogs |
| Default client uses network key | api-client.php:24-25 | Every caller without explicit key talks to the shared tenant |
| Branch/warehouse/supplier `ready()` | branch-sync.php:47, warehouse-sync.php:46, supplier-sync.php:59 | Readiness is network, not per blog |
| `TGS_Acc_Events::enabled()/targets()`, `deliver()` | events.php:76, 83-87, 453, 457 | Push per blog but key is network |
| `TGS_Acc_Pusher::send()` default client | pusher.php:403 | Same |
| `TGS_Acc_Lists::rows()` | lists.php:135, 138 | Shared catalogs |
| `TGS_Acc_Admin::ajax_ping/save/js_config` | admin.php:121, 250, 367-370, 395-403 | Single settings page |
| `TGS_Acc_Catalog_Sync::cron()`/`sync()` | catalog-sync.php:182, 213 | Network pull |
| Tax book `tax_url/tax_key` | config.php:93-112; events.php:450-453 | Second tenant, also network-wide |
| `sso_tenant`, `sso_secret` | config.php:71-82; embed.php:150, 161 | One tenant in SSO ticket |
| Webhook secret `TGS_KETOAN_WEBHOOK_SECRET` | ketoan-events.php:48-51 | One secret regardless of tenant |

### 2.2 `get_site_option` state (all **G**)
| Option | file:line | Purpose | One-tenant problem |
|---|---|---|---|
| `tgs_acc_bridge_config` | config.php:17, 40, 242; gate.php:54-57 | all settings incl. `gate_key` | per-blog fields need tier B (C1) |
| `tgs_acc_bridge_calls` | api-client.php:29, 34, 40, 54 | 60-call ring log | no blog/tenant column |
| `tgs_acc_known_branches` (+`_tax`) | pusher.php:412-421; branch-sync.php:29, 124, 159 | "branch exists in ERP" memo | keyed by branch code only; different tenants may share codes |
| `tgs_acc_branch_sync_last` | branch-sync.php:32, 164 | last run | — |
| `tgs_acc_catalog_cursor`, `_cursor_version`, `_sync_at`, `_sync_lock`, `_sync_dirty` | catalog-sync.php:44-61, 200-235, 276, 291-293 | feed cursor/lock | one cursor for one tenant feed |
| `tgs_acc_events_retry_lock` | events.php:511-540 | cron lock | fine if cron iterates blogs |
| `tgs_acc_ketoan_inbox` | ketoan-events.php:282, 295 | table-created flag | fine |
| `tgs_acc_lists_cache` | lists.php:22, 131, 154, 161 | ERP list cache by kind | must be keyed by blog/tenant |
| `tgs_acc_push_log_db` | push-log.php:25, 55, 102 | dbDelta flag | fine |
| `tgs_acc_supplier_sync_at` | supplier-sync.php:24, 48, 63, 126 | last run | one tenant's suppliers |
| `tgs_acc_warehouse_map` | warehouse-sync.php:25, 55, 101 | warehouses by branchCode | keyed by branch, not tenant |
| `tgs_multisite_hierarchy_data` (read) | source.php:135; gate.php:83; branch-sync.php:39-41 | blog → `htsoft_srid` | branch model itself |

### 2.3 Global tables (base_prefix)
| Table | R/W | file:line |
|---|---|---|
| `{base}tgs_acc_push_log`, `{base}tgs_acc_push_log_tax` | RW | push-log.php:43, 61-78, 114, 128, 161, 174, 242, 252; gate.php:249-256; pusher.php:341-353 |
| `{base}tgs_acc_push_run` | RW | push-log.php:49, 81-101, 182, 201-203, 213 |
| `{base}tgs_acc_ketoan_inbox` | RW | ketoan-events.php:275, 283-294, 301, 307 |
| `{base}htsoft_chinhanh` | R | source.php:144-148; gate.php:92-96 |
| `{base}blogs.tgs_site_code` | R | admin.php:155-161; gate.php:98-100; catalog-sync.php:858-862 |
| `{base}global_supplier` | R: source.php:289 / RW: supplier-sync.php:42, 74, 100, 112, 121 |
| `{base}global_product_name` | R: source.php:381, 525; trade-mapper.php:320 / RW: catalog-sync.php:471-491, 504, 515, 761-771 |
| `{base}global_product_cat`, `global_htsoft_unit_base`, `global_htsoft_stock_convert`, `global_htsoft_price_list`, `global_htsoft_price_list_blog`, `global_milk_under24m` | RW | catalog-sync.php:303, 346, 398, 437-455, 474-476, 584, 611, 629, 672, 721-753, 803, 874, 887, 897 |
| `{base}global_po_adjustment` | R | source.php:340-347 |
| `{base}global_cash_transfer` | R | source.php:593, 613-621; pusher.php:290; events.php:113, 401-402 |
| `TGS_Global_Transfer::table()/item_table()` | R | source.php:418-419, 443, 463; events.php:410-411 |

### 2.4 Branch-based routing / branch auto-create
| Mechanism | file:line |
|---|---|
| `TGS_Acc_Source::branch_of_blog`, `shop_code` | source.php:129-177 |
| `branchCode` on every document | mapper.php:221, 270, 387, 414, 475, 586, 657, 692, 727 |
| Cross-blog branch of transfer counterpart | mapper.php:606-616 |
| `TGS_Acc_Pusher::build` picks branch | pusher.php:41, 70, 78, 82, 315 |
| `ensure_branch` on first send | pusher.php:405-424; api-client.php:152-165 |
| `TGS_Acc_Branch_Sync` creates branches for all sites on hierarchy save / new site | branch-sync.php:38-41, 105-166 |
| Gate `?shops=` branch → blog; `/sites` reports branchCode | gate.php:111, 138-158 |
| Inbound events `blog_for_branch(data.branchCode)` | ketoan-events.php:121, 174, 249-258 |
| Warehouse map by branchCode, `normalize` by `doc.branchCode` | warehouse-sync.php:73, 95-99, 120, 151 |
| Catalog price-list ↔ branch by `tgs_site_code` | catalog-sync.php:334-372, 728-736, 855-866 |
| Reconcile tool filters ERP by `branchCode` | doi-chieu-ke-toan.php:99, 124-127 |
| Events cron scheduled only on main site | events.php:99-104 |
| Gate blogs() default "all sites except main" | gate.php:163-166 |

---

## 3. Document source: what the mapper needs, and where it comes from today

### 3.1 Sale / return header (consumed by `TGS_Acc_Mapper::sale` 213-255, `sale_return` 261-299, `partner` 151-166, `employee` 169-183, `invoice` 186-206)
| Field (VAT row key) | Type | Used for | Today from |
|---|---|---|---|
| `sale_id` | int | docKey, extra.saleId, originalDocKey | tgs-bc-tk (= `local_ledger_id` of type 10) |
| `return_id` | int | docKey return | tgs-bc-tk (= `local_ledger_id` of type 11) |
| `ngay_xuat` | `Y-m-d[ H:i:s]` | docDate | tgs-bc-tk (POS: `local_ledger_ticket_date` / `created_at`) |
| `so_phieu_xuat` | string | docNo, description | tgs-bc-tk (POS: `local_ledger_code`) |
| `ghi_chu` | string | description | tgs-bc-tk (POS: `local_ledger_note`) |
| `is_z` | bool | internalOnly, suppress invoice | tgs-bc-tk (bill Z split — POS: unclear; trade mapper uses role `zexport`) |
| `vat_state` | string `valid|replaced|cancelled|…` | extra.vatState, invoice.status | **tgs-bc-tk only** |
| `user_id` | int | employee (`get_userdata` → user_login) | tgs-bc-tk (POS: `local_ledger.user_id`) |
| `nv_ten` | string | employee.name | tgs-bc-tk |
| `ma_shop` | string | fallback branchCode | tgs-bc-tk |
| `ma_kh` | string (phone) | partner.code | tgs-bc-tk (POS: `local_ledger_person.local_ledger_person_phone` / `local_ledger_person_meta.phone`) |
| `ten_kh`, `ten_cty_mua`, `mst_mua`, `dchi_mua`, `email_mua`, `dt_mua` | string | partner + invoice buyer* | tgs-bc-tk (POS: `local_ledger_person`, `local_ledger_person_meta.extra.tax_code`, `local_ledger_advance_meta.vat.*`) |
| `so_hd`, `seri`, `mau_hd`, `ngay_hd` | string / `Y-m-d` | invoice.no/serial/form/date | **tgs-bc-tk only** (eVAT result; POS mirror stores only `meta.invoice{no,serial,date}` written by trade-mapper 75-77) |
| `items[]` | array | lines | tgs-bc-tk |
| `export_reason` | string | reasonCode if ≠ XBA | bridge itself from POS `local_ledger_advance_meta.$.export_reason.code` (source.php:43-63) |

### 3.2 Sale / return line (`TGS_Acc_Mapper::lines` 96-137)
| VAT item key | Type | Doc field | POS equivalent (`{B}local_ledger_item` on the export child type 2 for sales, on the return type 11 itself; cf. `supplier_doc_items` fallback source.php:368-403) |
|---|---|---|---|
| `item_id` | int | lineKey (used by `tag_warehouses` to read `local_ledger_item_warehouse_zone`, pusher.php:175-199) | `local_ledger_item_id` |
| `ma_hang` | string | sku, itemId via `TGS_Item_Variant` | `local_product_sku` |
| `ten` | string | name | `{G}global_product_name.global_product_name` (join source.php:381) |
| `dvt` | string | unit | `local_ledger_item_unit_name` |
| `sl_dvcb` | float | qty (base unit) | `quantity` |
| `sl` | float | unitQty | `local_ledger_item_unit_quantity` |
| `ratio` | float ≥1 | unitRatio | `local_ledger_item_unit_ratio` |
| `don_gia_gui_thue` | float | unitPrice (price **as sent to Viettel**) | approx `price` or `local_ledger_item_price_after_discount` — **not identical** |
| `tien_chua_thue` | float | amountBeforeTax (rounded per invoice law) | compute `round(quantity*price − discount_amount)` (as supplier_doc does, mapper.php:445) — **may differ by rounding from invoice** |
| `ck` | float | discount | `local_ledger_item_discount_amount` |
| `thue_suat` | `'KCT'|number|null` | taxCode | `local_ledger_item_tax_percent`, `local_ledger_item_is_kct` |
| `tien_thue` | float | taxAmount (**as sent to Viettel**) | `local_ledger_item_tax_amount` — POS-side rounding |
| `kho` | string | warehouseCode (overridden by tag_warehouses) | `local_ledger_item_warehouse_zone` |
| `is_gift` | bool | isGift | `local_ledger_item_gift_type > 0` |
| `so_lo`, `exp` | string / date | lotNo, expDate | `lot_code`, `exp_date` |
| `ghi_chu` | string | note | `local_ledger_item_note` |

### 3.3 Receipt (7) / refund (8) → `customer_payment` (`TGS_Acc_Mapper::payment` 714-748). **Already POS-ledger based** — `TGS_Acc_Source::payment_rows` (184-244):
| Field | Type | From |
|---|---|---|
| `id`, `type` (7/8), `parent_id`, `code`, `amount`, `user_id` | int/str/float | `{B}local_ledger` c (194-197) |
| `day` | date | `COALESCE(local_ledger_ticket_date, DATE(created_at))`; split receipts take parent day (226-242) |
| `phone`, `person_name` | string | `{B}local_ledger_person` |
| `method`, `method_label`, `split_parent` | string/int | `{B}local_ledger_meta.local_ledger_meta_value` JSON `receipt_method|payment_method`, `payment_method_label`, `split_multi_parent_receipt_id`; skipped when `doi_tru` / `offset_netting` (214-218) |
| parent must be type 10/11, approved, not deleted (198-201) | | |
Also offsets: `offsets_by_sale` reads sale meta `payment_receipts[].offset_netting/offset_return_ledger_id/amount` (654-685).

### 3.4 Already POS-only sources (no tgs-bc-tk): purchase/supplier-return (252-303), export-adjust (314-362), stock adjustment (486-542), global transfer (412-451), cash transfer (606-622), deleted keys (550-587), changed_before (628-648). These give the exact query/column patterns a POS-ledger sale source can copy (approved filter, `not_mirror_sql`, `updated_at` window, child-item fallback).

### 3.5 What tgs-bc-tk adds that a POS-ledger source lacks
1. Invoice identity: `so_hd`, `seri`, `mau_hd`, `ngay_hd`, `vat_state` (replaced/cancelled) — from the eVAT (Viettel) result tables owned by tgs-bc-tk.
2. Money "as on the invoice": `tien_chua_thue`, `tien_thue`, `don_gia_gui_thue` rounded per invoice law (docs/LUONG-BAN-HANG-SANG-KE-TOAN.md 111-120; MO-RONG.md 60 "Không tự tính lại tiền").
3. Bill Z split (`is_z`) and buyer legal fields (`ten_cty_mua`, `mst_mua`, `dchi_mua`, `email_mua`).
4. Shop list: `TGS_BCTK_Vat_Shops::active_blog_ids()` (admin.php:153; day-ke-toan.php:51; doi-chieu.php:50) and `TGS_BCTK_Sites::list_sites()` (source.php:170; gate.php:141-155; branch-sync.php:84-90).
5. Gate `?shops=` → blog resolution and `TGS_Acc_Source::ready()` gate (gate.php:200-202; events.php:426; admin.php:247; day-ke-toan.php:109).

### 3.6 Degrade path when tgs-bc-tk is absent (Q001-15)
- Build `sale`/`sale_return` from `{B}local_ledger` (type 10/11, approved, not deleted, not `pg:` mirror) + lines from the type-2 child (sale) / own items (return) using the `supplier_doc_items` fallback pattern; amounts = `round(qty*price − discount)`, tax = `local_ledger_item_tax_amount`, taxCode from `tax_percent`/`is_kct` (same as `supplier_doc`, mapper.php:443-470 minus the `R→V` swap).
- **Omit the `invoice` block** (mapper.php:246-249, 293-297) → ERP books to internal ledger only (doc semantics in LUONG… §4 table "invoice: Có khối này thì chứng từ vào thêm sổ thuế"); `TGS_Acc_Gate::for_tax_book` (181-185) then excludes them from the tax consumer automatically. If the POS sale meta carries `invoice{no,serial,date}` (trade-mapper.php:75-77 shape) it could be forwarded as a partial invoice (no `form`, no `status`), but Viettel-rounded tax is still unavailable, so tax-book use must stay off.
- `internalOnly` must be derived from the POS (e.g. a `zexport` role / meta flag) or default `false`; `is_z` has no documented POS column in this plugin.
- Hash stability: a doc built by the POS source will hash differently from one built by tgs-bc-tk (field set/rounding) → ERP sees a new version once per doc at switch-over; acceptable but must be stated.
- Replace the `TGS_Acc_Source::ready()` guards (gate.php:200, events.php:426, admin.php:247, day-ke-toan.php:109) with "a source is available" and route per blog.

---

## 4. Catalog sync and other pull/mirror classes

### 4.1 `TGS_Acc_Catalog_Sync` feed contract (catalog-sync.php)
- Endpoints: `GET /api/v1/catalog/feed/meta?since=<ISO>` (240) → `{priceLists[], branches[{branchCode,priceListCode}], knownBranches[]?, groups[{code,name,parentCode}], tombstones[{entity,key}], serverTime}` (244-249); `GET /api/v1/catalog/feed/products?after=<cursor>&limit=1000` and `/prices?after=&limit=5000` (252-268) → `{items[], next, more}`; max 500 pages per feed (254). Initial load: `POST /api/v1/catalog/import` with `{priceLists,branches,products,prices}` (`build_import` 714-815; tools/danh-muc-hang.php:83-95). Image download `GET /api/v1/document-attachments/<id>/file` (1013-1036) into `uploads/tgs-erp-images/`.
- Cursor: site option `tgs_acc_catalog_cursor` = `['meta'=>ISO,'products'=>cursor,'prices'=>cursor]` (44, 232-237); `CURSOR_VERSION = 'v3'` in `tgs_acc_catalog_cursor_version` forces full re-read when bumped (45-49, 227-231). Lock 600 s (218-224), dirty re-run (61, 220, 293-295).
- Column mapping (ERP → **G** table):
  - product → `global_product_name`: name, barcode_main, unit (ratio-1 unit), thumbnail, description, is_main, is_kct, htsoft_bit1(exportAdjust), htsoft_type 5(noSale), is_under_24m, status, is_deleted, item_id/parent_item_id/variant, send_tax; cat via `ensure_cat` (521-576, 387-426); new rows get `global_blog_id = get_main_site_id()` (489) — **main-site ownership**.
  - product.units → `global_htsoft_unit_base` (convert_unit, convert_to_htsoft, is_default_unit, unit_weight_kg, convert_note) (579-607).
  - product.isUnder24m → `global_milk_under24m` (609-621).
  - priceLists → `global_htsoft_price_list` (300-325); branches → `global_htsoft_price_list_blog` by `blog_codes()` = `wp_blogs.tgs_site_code` (334-372, 855-866).
  - prices → `global_htsoft_stock_convert` (price_list_id × sku × unit) (624-664), `refresh_price_rows` (670-705).
  - tombstones → soft delete (428-462).
- `SYNC_TAX = false` (59): `taxRate` not written to `global_product_tax` (559-561); tax AJAX actions `tgs_shop_tax_save_one`, `tgs_shop_tax_import_batch` not blocked (69, 138).
- `block_writes()` (131-145): on `admin_init` for AJAX, 403 if action's tables (filter `tgs_cache_ajax_writes`) intersect `CATALOG_TABLES` (64-67) or action in `MILK_WRITE_ACTIONS` (72-75).
- `routes()` (151-170): `POST|GET tgs-acc/v1/catalog-changed`, **auth = `TGS_Acc_Gate::authorized` (gate key Bearer / X-Api-Key)**, no tenant, triggers network sync on shutdown. `enabled()` = `TGS_ACC_CATALOG_FROM_ERP` or option `catalog_from_erp` (172-178). Target doc §2.1 says `catalog_from_erp` is to be dropped (C7).
- `flush_caches()` → `do_action('tgs_cache_table_changed')`, `TGS_POS_Price_List::flush_cache()` (998-1006).

### 4.2 `TGS_Acc_Warehouse_Sync` — ERP `GET /api/v1/warehouses` → site option map by branchCode → each blog's `{B}local_warehouse_zone` (see §1). Keeps per-blog table but chooses rows by `shop_code($blog)` (151).
### 4.3 `TGS_Acc_Supplier_Sync` — ERP partners kind=supplier → **G** `global_supplier` (see §1). Would need a per-blog supplier table or per-tenant partition.
### 4.4 `TGS_Acc_Branch_Sync` — creates branches in the single tenant (see §1, §2.4).
### 4.5 `TGS_Acc_Ledger_Mirror` / `TGS_Acc_Trade_Mapper` — per-blog writes via switch_to_blog; only global dependency is `global_product_name` lookup (trade-mapper.php:315-326).
### 4.6 `TGS_Acc_Ketoan_Events` inbound — auth HMAC with one constant secret; blog from `branchCode`; writes `{G}tgs_acc_ketoan_inbox` + per-blog ledger via mirror (see §1, §5).

---

## 5. Inbound auth per REST route (`tgs-acc/v1/*`)
| Route | Method | Auth | Target blog | file:line |
|---|---|---|---|---|
| `/pending` | GET | `Authorization: Bearer <gate_key>` or `X-Api-Key: <gate_key>` (`TGS_Acc_Gate::authorized`) | `?shops=<branch codes>` → blogs via `TGS_BCTK_Sites` + `branch_of_blog`; or `?blogs=<ids>`; else all non-main sites; `cursor` = index | gate.php:64-66, 120-131, 138-167, 204-209 |
| `/pending` | POST (ack) / GET `?ack=` | same | none — updates log rows by `doc_key` | gate.php:191-194, 239-271 |
| `/sites` | GET | same | n/a — lists all blogs with branchCode | gate.php:68-70, 79-117 |
| `/catalog-changed` | POST/GET | same gate key | none — network catalog pull | catalog-sync.php:151-170 |
| `/ketoan-events` | POST | `Bizcity-Signature t=,v1=` HMAC-SHA256 with `TGS_KETOAN_WEBHOOK_SECRET` over `"<t>.<body>"`, skew ≤300 s | `blog_for_branch(data.branchCode)` (event `tenantId` ignored) | ketoan-events.php:41-45, 54-77, 121, 174, 249-258 |
Note: gate key and webhook secret are both network singletons; nothing maps a caller's key/tenant to a blog. The target (CAU-HINH-KET-NOI §3.3, §5 K10) requires resolving blog from tenant first (C5).

---

## 6. Push log and idempotency
- docKey formats (all `btsoft:<blog_id>:<kind>:<id>`): `sale`, `return` (mapper.php:220, 269, 283), `receipt`/`refund` (726, 722), `purchase`/`supplier-return` (474, 534), `gt-out`/`gt-in` (585; source.php:470-476), `export-adjust` (386, 413), `adjust` (656), `cash_transfer` (691); `deleted_keys` names map (source.php:569). `gone_keys` patterns `LIKE 'btsoft:{blog}:%:{id}'` (pusher.php:344-350) and parse `explode(':')` with `$parts[2]` as kind and `end($parts)` as id (302-305, 355-358).
- Version/hash: `hash()` = md5(JSON without `version`) (pusher.php:24-28), computed **before** `decorate()` (382-383) so gate/push/event agree. `send()`: same hash & `status='posted'` & no `force` → skip (385-388); else `version = prev.version + (hash differs ? 1 : 0)`, min 1 (389-390). Gate uses the same rule (gate.php:217-222). Error results store empty hash so next run resends (446-447). Log statuses: `posted` (ERP posted/pending/unchanged), `error`, `gate`, `cancelled`.
- Cancel path: push → `cancel_keys()` = logged keys in range not rebuilt + `deleted_keys` with status posted/error/gate (push-log.php:137-154) → `POST /documents/cancel`; 404 → mark cancelled locally (pusher.php:461-486). Gate → returns `cancelled[]`, ERP acks `cancelled` → `mark('cancelled')` (gate.php:230-235, 258-263). Events → `gone_keys` (pusher.php:335-366; events.php:455-462).
- `--force` (`$opts['force']`): only disables the skip at pusher.php:385; version still only increments when the hash differs, so an unchanged doc is resent with the same version (ERP answers `unchanged`). Used by CLI (`day-ke-toan.php:126`) and UI checkbox (admin.php:255; cong-ke-toan.js:131; vat-push.js:97). Admin warns that changing URL/key needs `--force` because the log has no server/tenant dimension (admin.php:401-405; LUONG… 361; CAU-HINH §3.4 step 5).
- If docKey should embed tenant: (a) `UNIQUE KEY doc_key` (push-log.php:76) stays fine but every parse that assumes `parts[2]` = kind and `parts[1]` = blog must change (pusher.php:302-305, 344-350, 355-358; decorate 236 uses last segment only — fine); (b) `cancel_keys/keys_in_range` filter by `blog_id` column — fine; (c) ERP-side keys of already-sent docs change → every doc becomes "new" (duplicate posting) unless the ERP migrates keys; safer alternative: keep docKey, add `tenant` column + include tenant in the uniqueness (`UNIQUE(tenant, doc_key)`) and in `find_many` scope, and reset/partition the log per tenant (C3).

---

## 7. Embed / SSO
- Menu "Kế toán" opens `TGS_Acc_Embed::open_url()` → `admin-post.php?action=tgs_acc_sso_go&return=<erp>` (embed.php:72-83, 105-117). `sso_go()` (184-202) requires login, builds `ticket()`: base64url JSON `{email, name, login, perms, tenant, iat, exp(+120 s)}` + base64url HMAC-SHA256(secret) (149-166), redirects `return#sso=<ticket>` (200). ERP endpoint `POST /api/v1/auth/wp-sso` (header comment 12).
- Secret: `TGS_KETOAN_SSO_SECRET` or option `sso_secret` (config.php:71-77, min 24 chars 206). **Tenant: `TGS_Acc_Config::sso_tenant()` — one value for the network** (config.php:79-82; embed.php:161). Perms: `PERM_MAP` by `tgs_permission` menu keys (31-46), super admin gets all + `MODULE_EXTRA_PERMS` (49, 123-127); filter `tgs_acc_erp_permissions`.
- Under one-blog-one-tenant: `tenant` must come from the blog the user is acting in (or from the blog's key), and `erp_url` from `url_for($blog)`; `safe_return()` (169-181) compares against the network ERP URL only.

---

## 8. CLI tools (`tools/*.php`)
All three WP tools bootstrap by parsing `wp-config.php` for `DOMAIN_CURRENT_SITE/PATH_CURRENT_SITE` (or env `TGS_HOST/TGS_PATH`), `require wp-load.php`, then **`require_once dirname(__DIR__) . '/tgs-accounting-bridge.php'`** (day-ke-toan.php:26-33; doi-chieu-ke-toan.php:29-36; danh-muc-hang.php:34-41) — **this file no longer exists (renamed `open-ketoan-bridge.php`)**; on a plain `php tools/x.php` run the require fatals unless WP already loaded the plugin (it does, since wp-load activates plugins; `require_once` of a missing file is still a fatal error). Needs fixing regardless of tenancy.

| Tool | Args | Config used | `--all` | blog switching |
|---|---|---|---|---|
| `day-ke-toan.php` | `--blog`, `--from`, `--to`, `--days`, `--dry`, `--force`, `--url`, `--key`, `--origin` (35-43, 102-126) | `new TGS_Acc_Api_Client($opt['url'],$opt['key'])` → network defaults (115); `TGS_Acc_Source::ready()` (109) | `TGS_BCTK_Vat_Shops::active_blog_ids()`, one child process per blog with `--origin=cron` (50-74) | No switch_to_blog: re-execs itself with `TGS_HOST/TGS_PATH` of the blog (81-100); reads `get_option('tgs_site_code')` of current blog (121) |
| `doi-chieu-ke-toan.php` | `--blog`, `--from`, `--to`, `--url`, `--key`, `--all` (22-27, 85-95) | client as above (91); ERP reports `/api/v1/reports/sales-summary`, `/sales-payments`, `/documents?status=error` filtered by `branchCode` (124-127) | same list, child process per blog (49-66) | re-exec per blog (69-83); `get_option('tgs_site_code')` fallback (99) |
| `danh-muc-hang.php` | `nap|keo|bat|tat|trang-thai`, `--dry`, `--full`, `--mau`, `--url`, `--key` (26-32) | client with overrides (43); `TGS_Acc_Config::save(['catalog_from_erp'])` (132); site option cursor (138) | n/a — network catalog | none |
| `htsoft-chi-doc.php`, `xuat-tai-san-cu.php` | MSSQL legacy tools | `tgs_htsoft_connector/config.php` | n/a | none (no WP) |

Under one-blog-one-tenant: `--all` must iterate blogs with their own key (`key_for`) and skip/log blogs without (CAU-HINH §3.3, T-CFG-9); `--url/--key` already give per-run override (K11).

---

## 9. Tests
None on `main` (no `tests/` directory). `feat/c1-cau-hinh-theo-blog` adds `tests/config-test.php` (193 lines; commit d669633: "8 kịch bản, 51 phép kiểm", php CLI with WP function stubs) covering only `TGS_Acc_Config`. No tests for source/mapper/pusher/gate/events.

---

## What must change for one-blog-one-tenant (ordered)

1. **Config tier B + key semantics** — merge C1 (`key_for`, `key_legacy`, `url_for`, `save_blog`) from `feat/c1-cau-hinh-theo-blog` into `includes/class-tgs-acc-config.php` (main 40-50, 84-90, 242); keep `key()` as transitional.
2. **API client per blog** — `includes/class-tgs-acc-api-client.php:22-26` take `$blog_id` (or a factory `for_blog($blog)`), default to `key_for(current_blog)`; add `blog_id` to call log rows (38-55) and read log per blog (32-36).
3. **Connection question** — new `includes/class-tgs-acc-connection.php` providing `tgs_okt_connected($blog)` (4 conditions, 60 s cache, duplicate-tenant check via `GET /api/v1/catalog/feed/meta`), per CAU-HINH §0 / C6. Replace `TGS_Acc_Config::ready()` uses at admin.php:121, 250; events.php:76-87; catalog-sync.php:182; branch/warehouse/supplier `ready()` (branch-sync.php:47, warehouse-sync.php:46, supplier-sync.php:59).
4. **Pusher/Events/Gate use blog key** — `pusher.php:403` (`send`), `events.php:453-457` (`deliver`), `gate.php:210` (build per blog is fine, but ack/consumer are network) → client = blog's; blogs without own key fall back to `key_legacy` with a visible label (CAU-HINH §6), else skip and log. `tools/day-ke-toan.php:115` and `doi-chieu-ke-toan.php:91` likewise.
5. **Remove branch model from outbound docs** — `TGS_Acc_Source::branch_of_blog/shop_code` (source.php:129-177) and `branchCode` in mapper (221, 270, 387, 414, 475, 586, 606-616, 657, 692, 727) + pusher (41, 70-82, 315): under one tenant per blog `branchCode` becomes the tenant's default branch (or omitted); delete `ensure_branch` block pusher.php:405-424 and `TGS_Acc_Branch_Sync` (whole file; hooks at open-ketoan-bridge.php:80) once legacy is gone; drop site options `tgs_acc_known_branches(_tax)`, `tgs_acc_branch_sync_last`.
6. **Push log tenant-aware** — `push-log.php:61-78`: add `tenant`/`server` column (or partition by blog+tenant), change `UNIQUE doc_key` scope, make `find_many` (106-120), `keys_in_range` (123-131), `search`/`stats` scoped; keep docKey format (avoid ERP re-keying) — see §6. Remove the "đổi đơn vị → --force" warning (admin.php:401-405) once tenant is in the log.
7. **Inbound routing by tenant** — `ketoan-events.php:121, 174, 249-258`: resolve blog from envelope `tenantId` (map tenant → blog from the blog keys / a `tenant` stored at connect time), reject 404 when none (K10); secret per tenant or at least verify tenant matches the blog's; `catalog-sync.php:151-170` `/catalog-changed`: authenticate with the blog's key/tenant, trigger that blog only; `gate.php:120-131, 138-167`: gate key per blog or derive blogs from the caller's tenant instead of `?shops=`; `/sites` (79-117) drop `branchCode`.
8. **Remove catalog-sync-into-global-tables** — `includes/class-tgs-acc-catalog-sync.php` (init 98-129, block_writes 131-145, apply_* 300-705, build_import 709-815, blog_codes 855-866), its require at open-ketoan-bridge.php:68/82, option `catalog_from_erp` (config.php:34, 236-238, 166-167; admin.php:390; danh-muc-hang.php:127-140), site options `tgs_acc_catalog_*`; tsm ("open-pos-backend") pulls the feed into per-blog prefix tables (C7 / S001-BR6). Keep the feed contract (§4.1) as the spec for tsm.
9. **Supplier / warehouse / lists per blog** — `supplier-sync.php` writes **G** `global_supplier` (42-121): move to tsm per-blog or key by blog; `warehouse-sync.php` map option (25, 101) keyed by blog instead of branchCode, `sync()` per blog with blog key (138-183), `normalize` by blog (110-132); `lists.php` cache key `blog|kind` and blog client (131-154).
10. **Embed/SSO per blog** — `embed.php:61-66, 161, 169-181`: `tenant` and ERP URL from the acting blog; `sso_tenant` moves to tier B (config.php:79-82, 212-214); `admin/page-cong-ke-toan.php:241-243` field becomes per-blog.
11. **Trade mapper catalog lookup** — `trade-mapper.php:315-326` reads `{base}global_product_name`; after tsm moves catalog per blog, read the current blog's product table.
12. **Second document source (POS ledger)** — add `TGS_Acc_Source_Pos` (or branch inside `source.php:30-77`) building sale/return from `{B}local_ledger`/`local_ledger_item` per §3.6; select source per blog (tgs-bc-tk present → VAT rows; else POS ledger, no `invoice`, internal only); replace `ready()` guards (gate.php:200, events.php:426, admin.php:247, day-ke-toan.php:109) and shop lists from `TGS_BCTK_Vat_Shops`/`TGS_BCTK_Sites` (admin.php:153, gate.php:141-155, branch-sync.php:84-90, day-ke-toan.php:51, doi-chieu.php:50) with "blogs where `tgs_okt_connected`".
13. **Admin UI** — per-blog "Kết nối" panel + Network "Khoá theo shop" table (CAU-HINH §4) in `admin.php` (new AJAX for `save_blog`/`for_display_blog`/check) and `admin/page-cong-ke-toan.php:221-261`; `shops()` (151-166) from connected blogs; cron hints (277-279) and `require` paths in `tools/*.php:33/36/41` + `admin.php:106` renamed to `open-ketoan-bridge.php`.
14. **Cron/network contexts iterate blogs** — `events.php:99-104` (retry cron on main site is fine as long as `process()` uses the group's blog key), `warehouse-sync.php:34-39`, `supplier-sync.php:30-35`, `tools/day-ke-toan.php:50-74`: loop blogs, `switch_to_blog`/`get_blog_prefix` + that blog's key, skip without key (CAU-HINH §3.3, K9).
15. **Docs** — `docs/MO-RONG.md:57, 61, 141-155` and `includes/class-tgs-acc-catalog.php:86, 258-262` state `branchCode = mã shop` and branch auto-create; update after steps 5-7.
