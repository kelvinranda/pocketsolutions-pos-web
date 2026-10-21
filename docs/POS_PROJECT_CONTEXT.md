# POS Project Context

**Application:** Pocket Solutions PHP/mysqli POS (`pocketsolutions-pos-web`)  
**Database:** configured in `pockets_config.php` (credentials must never be printed)  
**Phase 3 closure (Module 3.10):** 2026-10-07  
**Phase 4A (Supplier & Purchasing Foundation):** complete 2026-10-07  
**Phase 4B (Purchase Orders):** complete 2026-10-07  
**Phase 4C (Goods Receiving / PO-linked Receiving):** complete 2026-10-07  
**Phase 4D (Purchasing Completion):** complete 2026-10-07  
**Phase 4 (Purchasing & Suppliers):** **COMPLETE** 2026-10-07  
**Phase 5.1 (Customer Master & Identity Foundation):** complete 2026-10-07  
**Phase 5.2 (POS Customer Integration & Transaction Identity):** complete 2026-10-08  
**Phase 5.3 (Customer Profile & Transaction History):** complete 2026-10-08  
**Phase 5.4 (Customer Management Finalisation & Regression Closure):** complete 2026-10-08  
**Phase 5 (Customers):** **COMPLETE** 2026-10-08  
**Phase 6.1 (Customer Account & Receivables Ledger Foundation):** complete 2026-10-08  
**Phase 6.2 (Credit Sales / Account-Charged Sales Integration):** complete 2026-10-08  
**Phase 6.3 (Customer Payments & Receivables Allocation):** complete 2026-10-08  
**Phase 6.4 (Customer Account Statements & Receivables Ageing):** complete 2026-10-08  
**Phase 6.5 (Receivables Credit Controls & Collections Foundation):** complete 2026-10-08  
**Phase 6 (Credit / Customer Accounts):** in progress — 6.1 + 6.2 + 6.3 + 6.4 + 6.5 complete (not whole Phase 6)  
**Phase 7.1 (Cash Movement & Till Ledger Foundation):** complete 2026-10-08  
**Phase 7.2 (Till Cash Reconciliation):** complete 2026-10-08  
**Phase 7.3 (Cash-In / Cash-Out Controls):** complete 2026-10-08  
**Phase 7.4 (Till Closure & Cashier Handover):** complete 2026-10-08 — controlled close complete; mid-session handover deferred  
**Phase 7.5 (Cash Management Dashboard & Reporting):** complete 2026-10-08 — read-only dashboard + reports; no financial mutations  
**Phase 7.6 (Cash Management Finalisation & Closure):** complete 2026-10-08 — integration audit; single formula helper; Phase 7 closed  
**Phase 7 (Cash Management):** **COMPLETE** 2026-10-08 (7.1–7.6) — documented limitations remain  
**Phase 8.1 (Refund Foundation Audit & Controlled Refund Model):** complete 2026-10-08  
**Phase 8.2 (Refund Authorisation):** complete 2026-10-08 — REQUESTED→APPROVED→PROCESSED / REQUESTED→REJECTED; no monetary thresholds  
**Phase 8.3 (Line-Level / Partial Refund Integrity):** complete 2026-10-08 — cumulative remaining from PROCESSED only; multi-line; no auto-shrink  
**Phase 8.4 (Voids / Sale Cancellation):** complete 2026-10-09 — cart discard + safe uncompleted cancel; committed → refund workflow only  
**Phase 8.5 (Refund / Cash / Stock Reconciliation Hardening):** complete 2026-10-09 — read-only detection; no auto-correct; review status append-only  
**Phase 8.6 (Refund Reconciliation Reporting):** complete 2026-10-09 — read-only summary/findings/detail/review-history/CSV; reuses 8.5 helpers  
**Phase 8 (Refunds / Voids / Authorisation):** **COMPLETE** 2026-10-09 (8.1–8.6) — closed with documented limitations (final closure audit)  
**Phase 9.1 (Multi-Branch Control Audit):** complete 2026-10-09 — audit-only; no application/schema/data changes; Phase 9 overall not closed  
**Phase 9.2 (Authorised Branch Resolution & Switch Hardening):** complete 2026-10-09 — C1/C2/H1; `includes/branch_access.php`; hardened `set_branch.php` + `admin_dashboard.php`; Phase 9 overall not closed  
**Phase 9.3 (Legacy / Unauthenticated Endpoint Branch Gates):** complete 2026-10-09 — C4/C5/H2/H4; gated `update_branch`/`users`/`income_statement`/`expenses`/`InvestmentReport`/`my_receipt`; `submit_sales.php` write path retired; Phase 9 overall not closed  
**Phase 9.4 (Mobile / API Branch Authorisation):** complete 2026-10-09 — C3/H3; `mobile_branch_auth.php`; hardened `login`/`products`/`sync_sales`/`session_sync`; Phase 9 overall not closed  
**Phase 9.5 (Historical Orphan Branch Data Policy & Soft Integrity):** complete 2026-10-09 — classification policy; soft registry write gates; admin diagnostics report; non-unique `idx_branchies_branch_name`; no historical rewrite / till auto-close / sequence reset  
**Phase 9.6 (Unified helpers, constraints & regression closure):** complete 2026-10-09 — consolidated `psBranchRequireAuthorisedWorking` / till operational gate; legacy stock/receipt/report UIs gated; transfer sending authz; UNIQUE/FK deferred; mobile `sync_sales` fail-closed; consolidated regression  
**Phase 9 (Multi-Branch Control):** **COMPLETE** 2026-10-09 (9.1–9.6) — closed with documented limitations  
**Phase 10 (Reporting & Dashboard):** **COMPLETE** 2026-10-09 — read-only management dashboard + sales/inventory/purchasing exports; reuses cash (7.5) and refund recon (8.6); Phase 9 branch ACL; closed with documented limitations  
**Phase 11 (Accounting Integration):** **COMPLETE** 2026-10-09 — accounting-ready journal export + recon; configurable mappings; idempotent batches; safe reversal; no external GL connector (dependency); closed with documented limitations  
**Phase 12 (VAT / Tax):** **COMPLETE** 2026-10-09 — configurable rates/classifications/inclusive-exclusive; server-side sale & refund tax persistence; tax reports; accounting tax split for COMPLETE data; legacy never invented; legal confirmation & external accounting acceptance remain dependencies  
**Phase 13 (Barcode & Hardware):** **COMPLETE** 2026-10-09 — `goods.barcode`; POS keyboard-wedge scan; browser labels + narrow receipt print; inventory search integrations; physical peripherals not hardware-tested; no ESC/POS/cash-drawer/scale/customer-display claims  
**Phase 14 (Security & Audit):** **COMPLETE** 2026-10-09 — `includes/security.php`; hashed passwords + legacy upgrade; session regenerate/revalidate/logout; CSRF inventory; `security_audit`; user lock; mobile token hardening; Phase 9 ACL preserved; see `docs/POS_SECURITY.md`
**Phase 17 (ZIMRA FDMS Fiscalisation):** implemented 2026-10-15 — per-branch fiscal devices; fiscal invoice per sale, credit note per processed refund; Supervisor + Management roles; see `docs/POS_ZIMRA.md`  
**Phase 15 (Backup / Reliability):** **COMPLETE** 2026-10-09 — plaintext password migration verified (remaining 0); mysqldump backups outside web; retention; isolated restore-tested; health; CSRF residual financial fixes; see `docs/POS_BACKUP.md`

## Architecture overview

This is a classic multi-file PHP application (not a framework rewrite). Core operational surfaces:

| Area | Primary paths |
|------|----------------|
| Web POS (production) | `ajax_pos.php` → `submit_sale2.php` → `includes/sale_payment.php` (+ stock via `includes/stock_movement.php`) |
| Product master UI | `create_items.php`, `items_list.php`, `import_items.php`, `export.php`, `product_audit_history.php`, `product_history.php` |
| Shared product rules | `includes/product_master.php`, `includes/item_import.php` |
| Supplier master (Phase 4A) | `create_supplier.php`, `suppliers_list.php`, `supplier_audit_history.php`, `includes/supplier_master.php` |
| Customer master (Phase 5) | `create_customer.php`, `customers_list.php`, `customer_view.php`, `customer_audit_history.php`, `includes/customer_master.php` |
| POS customer identity (Phase 5) | `ajax_pos.php` + `ajax_pos_customer_search.php` → `submit_sale2.php` / `psResolveSaleCustomer` → `online_sales_ref.customer_master_id` |
| Customer profile & sales history (Phase 5) | `customer_view.php` (Profile / Transaction History / Audit History) + `psCustomerSalesHistory` — link only via `customer_master_id` |
| Customer accounts / AR ledger (Phase 6.1) | `customer_accounts_list.php`, `create_customer_account.php`, `customer_account_view.php`, `customer_account_audit_history.php`, `includes/customer_account.php` — one account per customer; ledger-derived balance |
| Credit sales / account charge (Phase 6.2) | Production POS `CREDIT` payment → `submit_sale2.php` eligibility + same-txn `CREDIT_SALE` ledger; POS account snapshot `ajax_pos_customer_account.php` |
| Customer account payments (Phase 6.3) | `customer_account_payments.php` / create / view / allocate / payment audit + `includes/customer_account_payment.php` — PAY-###### receipts → PAYMENT ledger + optional allocations; **not** `sale_payments`; till integration disabled |
| Account statements & ageing (Phase 6.4) | `customer_account_statement.php`, `customer_receivables_ageing.php` + `includes/customer_account_reporting.php` — derived statement from ledger; sale-level ageing; no second ledger table; viewing posts nothing |
| Credit controls & collections (Phase 6.5) | `customer_credit_control.php`, `customer_collection_activity.php` + `includes/customer_credit_control.php`; `credit_status` NORMAL\|ON_HOLD on `customer_accounts`; hold/release/limit via `includes/customer_account.php` (HOLD / RELEASE_HOLD / LIMIT_UPDATE audit); POS CREDIT rejects ON_HOLD at `psCustomerAccountEvaluateCreditSale` |
| Cash till ledger (Phase 7.1) | `cash_movement.php` + `includes/cash_movement.php`; append-only `cash_movements`; types OPENING_FLOAT / CASH_SALE / CASH_REFUND / CASH_IN / CASH_OUT; balance via `psCashMovementBalance` (no cached column). Reuses existing `sessions` till architecture (`includes/till_session.php`) — no second till model. Phase 6.3 account payments still do **not** post here |
| Cash-in / cash-out controls (Phase 7.3) | Same `cash_movement.php` / `includes/cash_movement.php` path — controlled reason codes for CASH_IN / CASH_OUT (+ optional description; OTHER requires meaningful description); server CIN-/COUT- refs; own OPEN active till only; expected cash RO via `psCashMovementBalance`; does **not** auto-block CASH_OUT for negative expected |
| Till cash reconciliation (Phase 7.2 + 7.4 refresh) | `cash_reconciliation.php` + `includes/cash_reconciliation.php`; snapshot table `cash_reconciliations` (FINAL rows immutable; Phase 7.4 allows additional FINAL snapshots while OPEN so a stale count can be refreshed; close uses latest); expected = `psCashMovementBalance` only; counted ≥ 0; variance = counted − expected → BALANCED / SHORTAGE / SURPLUS; does **not** alter `cash_movements` or auto-close till |
| Till closure (Phase 7.4) | `cash_till_close.php` + `includes/till_closure.php`; requires current FINAL recon (latest expected == live ledger); atomic CLOSE on `sessions` with snapshot fields + `till_closure_audit`; no cash_movements/recon mutation; no reopen; mid-session cashier handover **not** implemented |
| Cash management dashboard & reporting (Phase 7.5) | `cash_management.php` + `cash_movements_report.php` / `cash_reconciliation_report.php` / `cash_closure_report.php` / `cash_session_view.php` / `cash_audit_history.php` + `includes/cash_reporting.php`; read-only; expected via `psCashMovementBalance`; OPEN = live ledger, CLOSED = closure snapshot; shortage/surplus never netted; CSV export branch-secured; Phase 6.3 account payments excluded |
| Management reporting (Phase 10) | `management_dashboard.php` + `sales_reports.php` + `inventory_reports.php` + helpers `includes/reporting.php` / `dashboard_reporting.php` / `sales_reporting.php` / `inventory_reporting.php`; purchasing CSV via `psPurchasingReportExportCsv`; read-only; modern completed = COMPLETED/refunded or `sale_payments`; legacy empty-status shown separately; stock ledger when OPENING_BALANCE; valuation only with cost>0; Phase 9 branch ACL; no duplicate ledgers |
| Accounting export (Phase 11) | `accounting.php` / `accounting_mappings.php` / `accounting_preview.php` / `accounting_batches.php` / `accounting_recon.php` + `includes/accounting.php` — configurable CoA mappings; balanced journal preview/export CSV batches; idempotent `accounting_exported_sources`; admin reversal; recon POS vs exported; does not mutate operational financials; `external_status=NOT_SENT` (no live GL API); Phase 12 COMPLETE tax split via `tax.output` |
| VAT / Tax (Phase 12) | `tax_rates.php` / `tax_reports.php` / `tax_audit_history.php` + `includes/tax.php` / `includes/tax_reporting.php`; product `tax_code` on `items_list.php`; sale path `submit_sale2.php` / `psBuildSaleLines`; refund tax snapshots; receipts show stored tax; no hardcoded statutory rate |
| Barcode & hardware (Phase 13) | `includes/barcode.php`; create/edit/import barcode; POS `ajax_pos.php` + `ajax_pos_barcode.php` (wedge scan → existing cart/`submit_sale2`); `barcode_labels.php` (browser Code 128); `receipt_print.php` (~80mm browser print); catalogue/GRV/adjustment barcode lookup; picqer already in vendor — no new dependency |
| Security & audit (Phase 14) | `includes/security.php`; `login.php` / `logout.php` / `change_password.php` / `session_check.php`; `user_lock.php`; `security_audit_history.php`; CSRF on inventoried POSTs; mobile login/token revalidation; `docs/POS_SECURITY.md` |
| ZIMRA fiscalisation (Phase 17) | `includes/zimra.php` + `includes/zimra_receipts.php`; sale hook in `submit_sale2.php` (signed in the sale txn, sent after commit); credit note in `psProcessApprovedRefund`; `zimra_setup.php` (admin), `zimra_day.php` (Supervisor/Management), `zimra_receipts.php` (Management), `zimra_receipt_print.php`; `cli_zimra_worker.php`; `docs/POS_ZIMRA.md` |
| Backup & reliability (Phase 15) | `includes/backup.php`; `backup_admin.php`; `cli_backup.php` / `cli_restore_test.php` / `cli_migrate_passwords.php`; `health.php`; `backup_jobs`; private root `pos-private/backups`; `docs/POS_BACKUP.md` |
| Cash finalisation (Phase 7.6) | Integration audit of 7.1–7.5 as one architecture; single formula via `psCashMovementComputeBalanceFromParts` / `psCashMovementBalance`; sessions money columns DECIMAL(14,2); consolidated CLI regression; Phase 7 formally closed with documented limitations |
| Customer refunds (Phase 8.1–8.3) | `refund.php` + `includes/sale_refund.php` — request → approve/reject → process; `sale_refund_requests` + `sale_refund_request_lines`; line-level partial/cumulative qty via `psGetRefundableSaleLineQuantity` (PROCESSED only); multi-line one REF; stock `REFUND`; CASH → one `CASH_REFUND` per processed request (`REFUND`+REF-######); non-cash none; `status=refunded` only when fully refunded; no thresholds |
| Voids / cancellation (Phase 8.4) | `includes/sale_cancel.php` + POS Clear cart (`ajax_pos_discard_cart.php`) + cancel panel on `refund.php` — discard uncommitted cart; cancel persisted uncompleted (no ledger effects → `CANCELLED`); committed/paid/refund-active → reject and use 8.1–8.3 refund workflow; append-only `sale_cancel_audit`; no VOID stock/cash types; no history delete |
| Refund / cash / stock recon (Phase 8.5) | `refund_reconciliation.php` + `includes/sale_refund_reconciliation.php` — read-only detection of PROCESSED refund vs sale lines vs stock `REFUND` vs `CASH_REFUND` vs audit vs cancel; optional review status (`sale_refund_recon_reviews` + append-only `sale_refund_recon_review_audit`); never auto-corrects ledgers; branch via session/perms; paginated |
| Refund recon reporting (Phase 8.6) | `refund_recon_report.php` / `refund_recon_finding.php` + `includes/sale_refund_recon_reporting.php` — read-only summary/findings/detail/review-history/CSV; reuses 8.5 (no second algorithm; no findings ledger); open/filter/export create no review rows |
| Purchase orders (Phase 4B) | `purchase_orders_list.php`, `create_purchase_order.php`, `purchase_order_view.php`, `purchase_order_audit_history.php`, `includes/purchase_order.php` |
| Goods receiving (Phase 4C) | `grv.php` → `psSaveGoodsReceipt` (`includes/stock_movement.php` + `includes/goods_receipt.php`); list `goods_receipt_ref.php`; audit `goods_receipt_audit_history.php` |
| Purchase returns (Phase 4D) | `purchase_returns_list.php`, `create_purchase_return.php`, `purchase_return_view.php`, `purchase_return_audit_history.php`, `includes/purchase_return.php` → `stock_movements` (`PURCHASE_RETURN`) |
| Supplier purchasing history (Phase 4D) | `supplier_purchasing_history.php` + `includes/purchasing_reports.php` |
| Purchasing reports (Phase 4D) | `purchasing_reports.php` (PO / GRV / return reports) |
| Inventory ledger | `includes/stock_movement.php`, stock UI pages (`opening_stock.php`, `stock_adjustment.php`, transfers, GRV, purchase returns, etc.) |
| Branch list | table `branchies` |

Display errors are off via `pockets_config.php` (`display_errors=0`); failures go to the PHP error log. User-facing messages must not expose raw SQL.

## Product identity

Authoritative identity is:

```text
product_id + branch
```

Enforced in MySQL as `UNIQUE` index `uniq_goods_product_branch` on `(product_id, branch)`.

- There is **no** `UNIQUE(product_id)` alone.
- There is **no** `UNIQUE(product)` (name) alone.
- Duplicate product **names** on the same branch are allowed (different `product_id`).
- The same `product_id` may exist on multiple branches as separate `goods` rows.
- Surrogate key `goods.id` is used for row edits, always scoped with session branch.

## Branch authority

- Authoritative branch names come from `branchies.branch_name` (currently: Concession bar, Village, Westlea sports bar — Phase 9.1 confirmed).
- **Phase 9.2 trusted model** (`includes/branch_access.php`):
  - **Home branch:** `$_SESSION['branch']` from authenticated login (`users.branch`) — never from POST.
  - **Working branch:** authorised `selected_branch` when set; else home when usable; contradictory/unauthorised selected fails closed (no silent foreign fallback).
  - **Authorised:** ordinary = home only (not merely because `selected_branch ∈ branchies`); admin (`level` case-insensitive) = registered `branchies` names. Switch ≠ elevated unrelated permissions.
- Working branch for product create/edit/list/POS / till / purchasing reports: `psBranchResolveWorking` / `psBranchRequireAuthorisedWorking` / `psRequireSessionProductBranch` / `psResolveProductWriteBranch` / `psPurchasingResolveReportBranch` (post-9.2/9.6). Working ops require **authorised + registered** (ordinary unregistered home fails closed for catalogue/till/sales/reports; historical home still used for receipt ownership via `psBranchSaleBranchAuthorised`).
- Posted branch values that differ from the session working branch are **rejected** on modern product/purchasing write paths.
- Admin may select All Branches / any `branchies` name for import/export and purchasing **reports/history**; create/edit/list/POS/returns remain session-branch scoped for writes.
- Cross-branch edits by manipulated `goods_id` / `product_id` / `branch` are blocked by helpers that require matching branch.
- **Phase 9.2 COMPLETE** (C1/C2/H1): `set_branch.php` auth + POST + authorised/registered validation + open-till gate; `admin_dashboard.php` requires admin; ordinary UI no longer offers switch. App-wide CSRF not claimed.
- **Phase 9.3 COMPLETE** (C4/C5/H2/H4): legacy endpoints require session (and admin where user/branch mgmt); financial reports scoped via `psBranchReportScope`; receipts check sale header branch; `submit_sales.php` write path disabled (production remains `submit_sale2.php`). Expenses remain company-global (no branch column). InvestmentReport still name-joins GRV/Z — scoped only where `goods.branch` exists after join.
- **Phase 9.4 COMPLETE (C3/H3):** mobile APIs use Bearer/`mobile_tokens` identity; working branch = live `users.branch` when registered in `branchies` (home only — admin does **not** unlock all branchies on mobile); client `branch` is consistency check only. Login returns authorised branch info only (not full `branchies`). `products.php` / `session_sync.php` bind to that branch; foreign product/till IDs rejected; inactive excluded.
- **Phase 9.5 COMPLETE:** historical orphans classified (VALID / HISTORICAL / MISSING / PROVABLE / UNRESOLVED); preserved without auto-remap. Soft integrity: NEW writes require registered `branchies` names (trim + PHP exact match — collation case-fold blocked). Admin read-only `branch_integrity_report.php`. Invoice seq left at **140** (gaps 108–139; 132–139 absent sales after 9.4 probe cleanup). NULL OPEN tills left unchanged. No FK/UNIQUE on `branchies.branch_name`.
- **Phase 9.6 COMPLETE:** unified helpers (`psBranchRequireAuthorisedWorking`, `psBranchTillRowOperational`, transfer sending ∈ `psSessionStockBranches`); gated legacy UIs (`stock_movement.php`, `opening_stock.php`, `stock_movements.php`, `all_receipts.php`, `z_report.php`, `inventory_control.php`, `stock_list.php`/`run_stocklist.php`, `product_history.php`, `stock_take_sheet.php`, `movement_list.php`, GRV AJAX); UNIQUE/FK on `branchies.branch_name` **deferred** (orphan/historical blockers); mobile `sync_sales.php` **fail-closed** for NEW posts (idempotent replay only; production sales = `submit_sale2.php`); mismatch review 0 provable / 11 unresolved preserved; 18 out-of-registry users not mass-reassigned (ops fail closed); 2 NULL OPEN tills unchanged (cannot claim/switch into).
- **Phase 9 COMPLETE** with documented limitations: historical remediations need business decisions; mobile live sales not via sync; no app-wide CSRF; browser/mobile-device testing not performed; older UIs may still POST to retired `submit_sales.php` (403).

## Stock authority

- Authoritative stock for controlled products is the **`stock_movements` ledger** (Phase 2), not `goods.quantity`.
- Product-master create / edit / status / import / pricing / category paths must **not** write stock movements and must **not** intentionally write `goods.quantity`.
- Sales deduct stock through the sale transaction (`submit_sale2.php` + stock helpers) when products are ledger-tracked.
- Purchase returns post `PURCHASE_RETURN` (out / negative qty) — distinct from customer `REFUND`.
- Baseline at Phase 3 close: `stock_movements` row count was **0** (ledger empty; inventory migration state as verified — see `POS_REGRESSION_BASELINE.md`). Phase 4D tests restore the same fingerprint after cleanup.

## Legacy fields (do not migrate/delete in Phase 3)

| Field | Role |
|-------|------|
| `goods.quantity` | Legacy on-row quantity. Not authoritative stock. Product-master paths must not write it. DB column default may still populate new rows if the column is omitted from INSERT. |
| `goods.re_order_level` | Legacy reorder column (`varchar`, default historically `10`). Product-master paths must not write it. |
| `goods.reorder_level` | Authoritative reorder-level field (`decimal`, nullable). Used by create/edit/import/export. |

## Authoritative product-master fields

Maintained through create/edit/import (as applicable):

- `product_id`, `product` (name), `price`, `cost_price`
- `category` (classification, free-text)
- `button_category` (POS button grouping)
- `reorder_level`, `pack_size`, `branch`, `status`

Not part of product-master stock writes: `quantity`, `re_order_level`.

## Product status

- Values: `ACTIVE` / `INACTIVE` (`goods.status`).
- POS catalogue and sale identity require `ACTIVE` (`fetch_pos_catalog.php`, `psActiveGoodsForSale`).
- Inactive products remain in DB for history; they cannot be sold on the production POS path.
- Status is branch-scoped (per `goods` row). Status changes do not touch stock.

## Categories

- `goods.category` and `goods.button_category` are **independent**.
- POS button tabs use `button_category` only.
- Historical rows may have swapped or placeholder values (e.g. `button_category='1'`, or category/button reversed on some branches). Phase 3 does **not** mass-correct that data.

## Product audit

- Table `product_audit` is append-only (INSERT only in application code; no UPDATE/DELETE helpers).
- Records field-level changes from UI create/edit/status and import when values actually change.
- No fake historical backfill of pre-audit changes.
- Separate from stock history (`product_history.php` / stock movements).

## Supplier identity (Phase 4A)

Authoritative supplier identity is the global **`supplier_code`** on table `supplier_master` (not supplier name).

- Suppliers are **global** (no branch column on the master). Branch belongs on purchasing / GRV / return transactions.
- Status: `ACTIVE` / `INACTIVE`. Inactive suppliers remain for history and must not be selectable for new purchasing (`psActiveSuppliersForSelect`).
- Suppliers with history are never hard-deleted from the UI; deactivate instead.
- Append-only `supplier_audit` records CREATE / UPDATE / ACTIVATE / DEACTIVATE (no fake historical backfill).
- Legacy tables `suppliers`, `suppliers_link`, and `supplier_invoices` remain untouched; they are not the Phase 4A master and were not mass-migrated.
- Existing GRV still stores free-text `supplier_name` on `goods_receipts` / `goods_receipts_ref`. Phase 4A does **not** rewrite GRV to FK the new master.

### Purchasing path

```text
Supplier (supplier_master) → Purchase Order → PO Lines → Goods Receipt (GRV) → stock_movements (GOODS_RECEIPT)
                                                                              ↘ Purchase Return → stock_movements (PURCHASE_RETURN)
```

- **4A:** `supplier_master` + `supplier_audit` (global by `supplier_code`).
- **4B:** Purchase orders + lines + dedicated PO numbering (`purchase_order_seq`, format `PO-000001`) + `purchase_order_audit`. PO create/approve/cancel does **not** write `stock_movements`, `goods.quantity`, or `goods.cost_price`.
- **4C:** One authoritative receiving path — evolve `grv.php` + `psSaveGoodsReceipt`. Non-PO (free-text supplier) and PO-linked receiving. Dedicated `goods_receipt_seq` (`GRV-000001`). Received qty derived from `goods_receipts` by `purchase_order_line_id` (no `received_qty` on PO lines). PO → `CLOSED` only when all lines fully received (server). Does **not** update `goods.cost_price` or `goods.quantity`.
- **4D:** Purchase returns (`PR-000001` via `purchase_return_seq`), supplier purchasing history / received-purchases summary (not payable balance), purchasing reports (PO / GRV / returns). Returns require reliable numbered GRV line identity; cost from original receiving line; stock via `PURCHASE_RETURN` only; do not reopen CLOSED POs; do not invent payable balance from legacy `supplier_invoices`.
- Legacy `request_orders` (product/email/date_created) exists in DB but is **not** used as the PO module.
- Legacy alternate GRV writers: `submit_grv.php` is **hardened (disabled)**; `online_grv_*.php` / `create_grv.php` are **legacy/dead** (not production path).

## Customer identity (Phase 5 — final architecture)

Authoritative customer identity is the global **`customer_code`** / surrogate **`id`** on table **`customer_master`** (not name, phone, email, or national_id alone).

```text
customer_master → customer_audit
                ↘ online_sales_ref.customer_master_id (optional sale link)
                ↘ customer_accounts (optional, Phase 6.1 — one per customer)
                     → customer_account_ledger
                     → customer_account_audit
                     → customer_account_payments (Phase 6.3)
                          → customer_account_payment_allocations
                          → customer_account_payment_audit
```

- Customers are **global** (no branch column on the master), same scope pattern as `supplier_master`.
- Codes are server-allocated via dedicated `customer_seq` as `CUS-000001` (`SELECT … FOR UPDATE`; never `sale_invoice_seq` / PO / GRV / purchase-return seq / `MAX(id)+1`). Code is immutable after create.
- Status: `ACTIVE` / `INACTIVE` only (transitions ACTIVE↔INACTIVE). New customers are created `ACTIVE`. Inactive remain for history; POS selection (`psSearchActiveCustomersForPos` / `psResolveSaleCustomer`) is ACTIVE-only; admin list may include INACTIVE.
- Customers are never hard-deleted from the application UI (`psDeleteCustomerBlocked`); deactivate instead. Legacy table `customers` rows (4) are not deleted.
- Append-only `customer_audit` records CREATE / UPDATE / ACTIVATE / DEACTIVATE (no fake historical backfill; no audit on view/history read).
- Production POS (`ajax_pos.php` → `ajax_pos_customer_search.php` → `submit_sale2.php` → `psResolveSaleCustomer`): optional ACTIVE `customer_master`; store `online_sales_ref.customer_master_id`; walk-in / Cash Customer → NULL (no auto-create). Posted name never establishes identity; display name from master.
- Legacy `online_sales_ref.customer_id` still means **`customers.id`** and is left NULL by the production POS path (never confused with `customer_master.id`).
- Profile/history (`customer_view.php` + `psCustomerSalesHistory`): link only via `customer_master_id`. Summaries are sales count/value — not balance owed / AR. History may span branches. Paginated; national_id on profile only (not list / POS / history tables).
- Helpers: `includes/customer_master.php` (+ `psCustomersByIds` batch for listings). UI via `ps_layout`.
- Legacy table `customers` / `customers.php` / empty `top_customers` / mobile free-text sync / `submit_sales.php` remain classified (not mass-migrated). Phase 5.4 hardened legacy write auth + raw-SQL exposure; no invented credit/loyalty.

## Customer accounts / receivables ledger (Phase 6.1)

```text
customer_master → customer_accounts (UNIQUE customer_master_id; ACC-###### via customer_account_seq)
                → customer_account_ledger (authoritative balance = SUM(debit)−SUM(credit))
                → customer_account_audit (append-only)
```

- Explicit account create from customer management (`create_customer_account.php`); never silent-create for legacy `customers` / historical sales / Cash Customer.
- Account status: `ACTIVE` / `SUSPENDED` / `CLOSED`. Never hard-delete (`psDeleteCustomerAccountBlocked`).
- Account numbers: dedicated `customer_account_seq` → `ACC-000001` (`SELECT … FOR UPDATE`). Never `customer_seq` / `sale_invoice_seq` / PO / GRV / return seq / `MAX(id)+1`.
- Ledger entry types: `OPENING_BALANCE`, `CREDIT_SALE`, `PAYMENT`, `PAYMENT_REVERSAL` (void), `CREDIT_NOTE`, `DEBIT_ADJUSTMENT`, `CREDIT_ADJUSTMENT`. Phase 6.1 posts admin **opening balance**; Phase 6.2 posts **CREDIT_SALE** from production POS; Phase 6.3 posts **PAYMENT** (and **PAYMENT_REVERSAL** on void). Phase 6.4 reports statements/ageing from these rows (derived views only).
- Balance: ledger-derived only (no cached balance column). Positive = customer owes; negative = customer credit; empty ledger = `0.00`.
- Inactive customer: account create requires ACTIVE `customer_master`; existing accounts remain viewable after customer deactivation.
- Legacy DB stubs (`accounts`, `account2`, `school_accounts`, `student_balances`, `supplier_invoices`) are **not** the AR model.

## Credit sales / account-charged sales (Phase 6.2)

```text
ajax_pos.php (CREDIT) → submit_sale2.php → sale + stock (SALE) + customer_account_ledger (CREDIT_SALE)
                                         ↘ sale_payments.method = CREDIT (not cash till)
```

- CREDIT only when: valid ACTIVE `customer_master_id`, ACTIVE account (lookup by `customer_master_id`; no silent create), server sale total > 0, existing POS validation + stock, same DB transaction commits.
- Cash Customer (`customer_master_id` NULL) always rejected for CREDIT.
- Credit limit: `credit_limit = 0.00` → no facility. `available_credit` (display) = `credit_limit − max(balance, 0)`. Authorise: reject if `(balance + server_sale_total) > credit_limit`. Server-side only; posted balance/limit/total ignored.
- Payment method `CREDIT` in `psPaymentMethods` / `psEvaluatePayment`: amount = server total, change = 0; no cash amount required; no cash till impact.
- Ledger: after sale + stock succeed, exactly one `CREDIT_SALE` with `debit = server_sale_total`, `credit = 0`, `reference_type = SALE`, `reference_id = invoice number`. Duplicate protection: `UNIQUE(reference_type, reference_id, entry_type)` + pre-insert check. Rollback if ledger fails.
- Inventory: normal `SALE` stock movements only; `goods.quantity` not authority / not written.
- Legacy `submit_sales.php` / mobile `sync_sales.php`: CREDIT disabled (unsafe). No historical CREDIT_SALE backfill. No mobile credit protocol.
- UI: payment-sheet Credit option + read-only account panel; `customer_account_view.php` shows limit / balance / available credit / credit-sale count+total; ledger lists `CREDIT_SALE` (paginated).

## Customer account payments / allocation (Phase 6.3)

```text
customer_accounts → customer_account_payments (PAY-###### via customer_account_payment_seq)
                  → customer_account_payment_allocations (optional; ACTIVE|VOIDED)
                  → customer_account_ledger (PAYMENT credit; PAYMENT_REVERSAL on void)
                  → customer_account_payment_audit (CREATE/POST/ALLOCATE/VOID)
```

- **Not** `sale_payments` — POS sale tender remains separate. Account payment ≠ POS sale payment.
- Eligibility: ACTIVE `customer_master` + existing account; CLOSED reject; SUSPENDED allow settle (do not reopen). Amount > 0 with money precision rules.
- Numbering: dedicated `customer_account_payment_seq` → `PAY-000001` (`SELECT … FOR UPDATE`). Never sale/customer/account/PO/GRV/return seq / `MAX(id)+1`.
- Methods: `CASH` / `CARD` / `ECOCASH` / `BANK_TRANSFER` / `OTHER` (not `CREDIT` — that is sale tender).
- Ledger: exactly one `PAYMENT` (`debit=0`, `credit=amount`, `reference_type=CUSTOMER_PAYMENT`, `reference_id=payment.id`) per posted payment; UNIQUE dup protection. Allocations do **not** create ledger rows.
- Allocation: only Phase 6.2 `CREDIT_SALE` invoices for the same account; outstanding = sale debit − SUM(ACTIVE allocations); multi-sale OK; unallocated allowed; default oldest-first; server revalidates; no cross-customer.
- Void: POSTED→VOIDED; post `PAYMENT_REVERSAL` debit; void allocations; retain original PAYMENT ledger row and payment amount (never delete / never zero amount).
- Till: integration **explicitly disabled** — cash method is a receipt label only; no till float/session writes.
- UI: list / create / view / allocate / payment audit; `customer_account_view.php` shows open credit-sale outstanding + recent payments.
- Out of scope for 6.3: ageing, collections, interest, statements, GL, VAT, bad debt, credit notes, debit adjustments, historical payment migration, mobile payment protocol, broad till redesign.

## Customer account statements & receivables ageing (Phase 6.4)

```text
customer_account_ledger → statement (derived: Date | Reference | Description | Debit | Credit | running Balance)
CREDIT_SALE outstanding (debit − ACTIVE allocations) → ageing buckets (Current / 1–30 / 31–60 / 61–90 / 91+)
```

- **Statement:** `customer_account_statement.php` via `psCustomerAccountStatement`. Source = `customer_account_ledger` chronologically (`entry_date ASC, id ASC`). Opening = ledger balance before period start; closing = opening + period debit − period credit. Inclusive date bounds. Presets: All / Today / This month / Current year / Custom. References: `CREDIT_SALE` → invoice; `CUSTOMER_PAYMENT` → `PAY-######` (batch-resolved; not internal ids as primary). `PAYMENT` shows full payment credit (not allocation). Voided payments remain as PAYMENT credit + PAYMENT_REVERSAL debit. Labels: Amount Due (positive) / Customer Credit (negative). Viewing creates no financial entries and no view-audit.
- **Ageing:** `customer_receivables_ageing.php` via `psCustomerReceivablesAgeing` / `psCustomerAccountAgeingSummary`. Outstanding = CREDIT_SALE debit − ACTIVE allocations (as-at aware). **Due-date source:** no `due_date` column — uses `CREDIT_SALE.entry_date` (sale/charge date); Current/Not Due = days overdue ≤ 0; no invented credit terms. Only outstanding portion ages; fully paid excluded; unallocated payments reduce account balance but not sale outstanding until allocated. Branch filter by **sale** branch (`online_sales_ref`), session-secured like purchasing reports. Management summary distinguishes Total Credit Sales Outstanding vs Total Customer Account Balance (ledger as-at).
- **As-at:** exclude ledger/sale rows with `entry_date` after as-at; ACTIVE allocations counted when `DATE(created_at) ≤ as-at`. Historical reconstruction imperfect if void timing differs from `created_at`. Future-dated sales excluded from earlier as-at (do not distort).
- **Helpers (authoritative):** reuse `psCustomerAccountBalance`, `psCustomerAccountAvailableCredit`, `psCustomerAccountSaleOutstanding`; new `psCustomerAccountStatementOpeningBalance`, `psCustomerReceivablesAgeing`, statement helpers in `includes/customer_account_reporting.php`. No cached balance column. No second statement ledger table. No export on statement/ageing screens (no existing receivables export pattern).
- Out of scope for 6.4: collections, promises, SMS/email, write-offs, bad debt, interest, penalties, credit notes, debit adjustments, GL, VAT, reconciliation, mobile receivables, historical migration, auto account creation, auto-allocation of unallocated payments.

## Credit controls & collections foundation (Phase 6.5)

```text
customer_accounts.credit_status (NORMAL|ON_HOLD)  ← separate from status ACTIVE|SUSPENDED|CLOSED
                → customer_account_audit (HOLD / RELEASE_HOLD / LIMIT_UPDATE)
customer_collection_activity (non-financial contact history)
customer_credit_control.php (dashboard: overdue via 6.4 ageing, on hold, near limit, activity)
```

- **Credit status:** `NORMAL` | `ON_HOLD`. Account lifecycle status unchanged. ACTIVE+NORMAL → credit if other 6.2 rules pass; ACTIVE+ON_HOLD / SUSPENDED / CLOSED → CREDIT prohibited.
- **Hold:** blocks new CREDIT sales only; viewing / statements / payments / allocations OK; no auto-close; no change to existing receivables; no auto-hold from ageing. Reason required (OVERDUE / CREDIT_LIMIT / COLLECTIONS / MANAGEMENT_REVIEW / CUSTOMER_REQUEST / OTHER) + note as needed. Append-only audit (prior reasons retained).
- **Limit:** server-side ≥ 0 DECIMAL; reason required; never alters ledger; payments do not change limit; admin-only manage; LIMIT_UPDATE audit old/new/reason.
- **Permissions:** hold/release/limit require session `level=admin` (strongest existing role gate). Cashiers cannot release hold to complete a sale — server still rejects ON_HOLD. Limitation: no finer-grained credit-control role.
- **Collections:** `customer_collection_activity` append-only; types/outcomes controlled; PROMISE_TO_PAY never posts PAYMENT/ledger; `next_action_date` planning only (no reminder engine).
- **POS:** same decision point `psCustomerAccountEvaluateCreditSale` in `submit_sale2.php`; frontend may disable but is not sole control. Legacy `submit_sales.php` / mobile CREDIT remain disabled.
- Out of scope for 6.5: SMS/email/WhatsApp, automated reminders/scheduling/calls/bots, interest, penalties, bad debt, write-offs, credit notes, debit adjustments, GL, VAT, credit bureau, mobile credit-control protocol, historical collection migration, automatic credit holds, automatic payment allocation, account closure from ageing.

## Critical rules (preserve unless a task explicitly changes them)

1. Identity = `product_id + branch` with `UNIQUE(product_id, branch)` only.
2. Session branch isolates product writes; do not trust client-posted foreign branches.
3. Stock ledger is authoritative; product master must not invent stock.
4. POS selling price is server `goods.price` for the session branch; client price is ignored.
5. Historical sale rows are not recalculated when prices change.
6. Category ≠ button_category; do not mass-swap.
7. Do not modify production products `1034` / `1063` for tests.
8. Do not “fix” invoice sequence because an older expected value differs — current verified DB state is the baseline.
9. Do not edit `vendor/`. Do not commit unless asked.
10. Supplier identity = global `supplier_code`; do not invent branch-supplier mapping without demonstrated need.
11. Purchasing eventually posts stock via `stock_movements`; do not change `goods.quantity` architecture.
12. PO numbers use `purchase_order_seq` only — never `sale_invoice_seq`. PO create/approve must not write stock or `goods.cost_price`.
13. GRV numbers use `goods_receipt_seq` only — never `sale_invoice_seq`, `purchase_order_seq`, or `MAX()+1`. Supplier invoice remains a separate user field.
14. Receiving posts `GOODS_RECEIPT` for actual received qty only; over-receive rejected; PO-linked supplier/cost from PO (not POST override).
15. Purchase return numbers use `purchase_return_seq` only (`PR-000001`). Movement type is `PURCHASE_RETURN` (not customer `REFUND`). Returns do not reopen CLOSED POs or change PO outstanding derivation.
16. No authoritative supplier payable/payment model — do not label GRV totals as “balance owed.” Legacy `supplier_invoices` is not authoritative.
17. Customer identity = global `customer_code` / `customer_master.id`; do not use name/phone/email/national_id as primary identity. Do not invent branch-specific customer masters without demonstrated need.
18. Customer codes use `customer_seq` only (`CUS-000001`). Do not hard-delete customers from normal UI. Optional POS selection uses `customer_master_id`; customer remains optional (not mandatory). No credit / loyalty / AR in Phase 5.
19. Customer sales history uses only `online_sales_ref.customer_master_id` → `customer_master.id`. Do not infer ownership from name/phone/email/legacy `customer_id`/Cash Customer. Do not mix `customer_audit` with sales history. Viewing a profile does not write UPDATE audits.
20. Customer receivable identity = optional `customer_accounts` row (one per `customer_master.id`). Account numbers use `customer_account_seq` only (`ACC-000001`). Ledger is balance authority. Do not hard-delete accounts. Do not accept posted balance/account number.
21. Phase 6.2 credit sales: CREDIT requires ACTIVE customer + ACTIVE account + credit_limit > 0 and projected balance ≤ limit. Never silent-create accounts. Never allow Cash Customer on CREDIT. CREDIT_SALE posts in the same sale transaction; no orphan sale/ledger. Do not invent payments / allocation / statements / ageing / GL in 6.2.
22. Phase 6.3 account payments: use `customer_account_payments` + dedicated `PAY-######` seq — never reuse `sale_payments` as account receipts. One PAYMENT ledger credit per posted payment; void via PAYMENT_REVERSAL (do not delete original PAYMENT or zero amount). Allocations only to same-account CREDIT_SALE outstanding. Till integration disabled unless a later module enables it safely.
23. Phase 6.4 statements/ageing: derive from ledger + allocation outstanding only. Do not invent due dates/terms, cached balances, or a second financial ledger. Do not force-match unallocated payments to sales for reporting. Do not hide voided payment history on statements.
24. Phase 6.5 credit controls: keep `credit_status` separate from account lifecycle `status`. Do not overload `status` with hold meaning. Hold blocks CREDIT sales only (not cash/payments/statements/viewing). No auto-hold from ageing. Collection activity never posts ledger/PAYMENT. Admin-only hold/release/limit.
25. Phase 7.1 cash ledger: `cash_movements` is authoritative for till cash (no cached balance). Amount always > 0; direction via movement type. Only CASH tender creates CASH_SALE; non-cash tenders and CREDIT do not. Do not post Phase 6.3 customer account payments to cash_movements. Do not edit/delete posted movements. Reuse existing till session open/identify/validate — do not invent a second till architecture. Do not redesign `sale_payments`.
26. Phase 7.2 cash reconciliation: expected cash from `psCashMovementBalance` only (ignore posted expected/variance/status). FINAL snapshots immutable; Phase 7.4 allows additional FINAL while OPEN (latest used for close currency). Does not edit the cash ledger, does not auto CASH_IN/OUT to balance, does not close/lock the till, and does not treat notes as accounting adjustments. SHORTAGE/SURPLUS require a non-empty note.
27. Phase 7.3 cash-in/out controls: strengthen manual CASH_IN / CASH_OUT on the existing ledger — controlled reason codes (not free-text-only); OTHER requires meaningful description; ignore posted user/cashier/branch/till/session/balance/refs; own OPEN active till only; no min-cash / negative-expected auto-block (`psCashMovementBlockNegativeExpectedOnCashOutEnabled() === false`); later IN/OUT after a FINAL recon allowed while till stays open (historical recon immutable); reason labels such as EXPENSE are operational only (not GL).
28. Phase 7.4 till closure: `sessions` remains the only till-session table (OPEN|CLOSED). Require current FINAL recon before close (latest expected == live ledger); no auto-create/alter recon; no cash_movements changes; snapshot on sessions + `till_closure_audit`; CLOSED rejects sale/IN/OUT/recon/CASH_REFUND operational path; no reopen; mid-session handover deferred — close then new open for next cashier.
29. Phase 7.5 cash reporting: read-only dashboard/reports only. Reuse `psCashMovementBalance` (no duplicate formula / no second ledger / no cached balances). OPEN figures from live ledger; CLOSED closure figures from sessions snapshot. Do not mutate movements/recon/closure from reporting screens. Do not net shortage+surplus. Do not add CARD/ECOCASH/BANK_TRANSFER/CREDIT/OTHER or Phase 6.3 payments into expected cash. Ordinary users: own working branch only.
30. Phase 8.1 refunds: refund existing sales by server line id / invoice only (never product name alone). Stock via `stock_movements` `REFUND`; branch = original sale branch. CASH + OPEN till → `CASH_REFUND`; CLOSED rejects cash; non-cash → no `CASH_REFUND`. CREDIT ledger unchanged. Dup: FOR UPDATE + cash UNIQUE + submit token + REF uniqueness.
31. Phase 8.2 authorisation: no immediate stock/cash commit from UI. Lifecycle on `sale_refund_requests`: REQUESTED → APPROVED → PROCESSED, or REQUESTED → REJECTED. Permissions reuse existing session `logger` (create/process) and `level=admin` (approve/reject). Reason immutable after approval; SoD blocks self-approval when another admin exists. `sale_refund_audit` events: REQUEST / APPROVAL / REJECTION / PROCESSING. Legacy `psProcessRefund` requires approved `request_id`. No voids (8.4). No monetary thresholds.
32. Phase 8.3 line-level / partial: remaining = original − SUM(PROCESSED request-line qty) via `psGetRefundableSaleLineQuantity` only. REQUESTED/APPROVED do not reserve. Multi-line one request → one REF; process all-or-nothing; never auto-shrink approved qty. Amount = `unit_price × qty`. `status=refunded` only when fully refunded. Cash ref = `REFUND` + REF-###### per processed request. Table `sale_refund_request_lines`. No recon/reporting (8.5–8.6).
33. Phase 8.4 voids/cancellation: discard uncommitted POS cart (audit `DISCARD_CART`); cancel only persisted sales with **zero** payment/stock/cash/AR/refund footprint (`CANCELLED`, history retained, no ledger movements). Never direct-void `COMPLETED` / paid / ledger-affected sales — refuse and direct to authorised refund workflow. Never invent VOID stock/cash types; never delete history; never update `goods.quantity`; closed tills / completed recons untouched. Audit: `sale_cancel_audit`.
34. Phase 8.5 refund recon: detection/review only. Never INSERT/UPDATE/DELETE stock/cash/sales/AR to “fix” discrepancies. Only PROCESSED counts; APPROVED/REQUESTED not completed. CASH tender → expect `CASH_REFUND` (`REFUND`+REF-######); non-cash → none. Legacy `status=refunded` without request-lines → INCONCLUSIVE. CREDIT AR non-reversal = known limitation (not invent credit notes). Re-runs must not duplicate ledger rows; review status keyed by stable `discrepancy_key`. Phase 7 cash formula unchanged.
35. Phase 8.6 refund recon reporting: read-only dashboard + filterable findings + detail + review history + CSV. Reuse `psSaleRefundReconciliationRun` (no second recon algorithm). Opening/filtering/exporting must not create findings or review rows. Distinguish DISCREPANCY vs INCONCLUSIVE; do not invent monetary loss totals. Branch via trusted resolution. Review history from `sale_refund_recon_reviews` / `sale_refund_recon_review_audit` read-only.
36. Phase 17 ZIMRA: one enabled device per branch. Fiscal receipts are numbered + signed inside the sale/refund transaction and never edited afterwards (only the credit-note `creditDebitNote` reference is upgraded to `receiptID` before sending — it is not signed data). No network calls inside DB transactions. Send in `receipt_global_no` order only. Device private keys only encrypted at rest. Roles compared case-insensitively.
37. Phase 18 platform: `companies.company_name` is the fixed company key (never renamed; edit `display_name`). Super Admins are `users.level = 'SuperAdmin'`, `company_name = '__platform__'`. A Super Admin works inside a company only when it is ACTIVE and `provider_managed = 1`, via `psPlatformEnter` (reason required, logged in `platform_audit`); the session then acts as that company's admin. While managing, sales, refunds, sale cancellation and till/cash pages are refused (page gate + `psCompleteSale` / `psRefundMay*` / `psSaleCancelMayAct`). Suspended companies cannot sign in (web or mobile). See `docs/POS_PLATFORM.md`.

## Customer refunds (Phase 8.1 + 8.2 + 8.3)

```text
refund.php (UI)
  → psRequestRefund → sale_refund_requests(REQUESTED) + sale_refund_request_lines + audit REQUEST (+ REF-######)
  → psApproveRefundRequest / psRejectRefundRequest (admin) + audit APPROVAL|REJECTION
  → psProcessApprovedRefund (APPROVED only)
       → lock request + sale lines FOR UPDATE
       → revalidate each approved qty ≤ remaining (PROCESSED sum); fail if insufficient (no shrink)
       → stock_movements(REFUND) per line [+ one CASH_REFUND if CASH + OPEN till]
       → status=refunded only when line remaining becomes 0
       → request PROCESSED + audit PROCESSING
```

### Entry / transaction boundary (8.1–8.3)

| Aspect | Behaviour |
|--------|-----------|
| **Entry** | Production UI `refund.php` only. Search → request lines with qty / all remaining; approve/reject/process. |
| **Helpers** | `psRequestRefund`, `psApproveRefundRequest`, `psRejectRefundRequest`, `psProcessApprovedRefund`; qty authority `psGetRefundableSaleLineQuantity`. |
| **Sale identity** | Server `online_sales.id` and/or `invoice_number` — never product name alone. |
| **Qty** | `original − SUM(PROCESSED request lines)`; requested > 0 and ≤ remaining. `psRefundPartialQuantityEnabled() === true`. Multiple partials until remaining 0. |
| **Reservation** | REQUESTED/APPROVED do **not** consume remaining. Only PROCESSED counts. Concurrency via locks at process. |
| **Amount** | `unit_price × qty` (fallback full-line `revenue` only when qty = sold). Process recalculates; mismatch → reject. Posted amount ignored. |
| **Multi-line** | One request → many lines; one REF; atomic process (one fail = whole fail). |
| **Stock / Cash** | Per processed line: stock `REFUND`. CASH+OPEN → one `CASH_REFUND` per request (`REFUND`+REF-######). Non-cash / CREDIT → no cash. |
| **status=refunded** | Compatibility only when processed ≥ original; partial must not look fully refunded. Cumulative records authoritative. |
| **CREDIT ledger** | Unchanged (`psRefundCustomerAccountLedgerEnabled() === false`). |
| **Permissions / SoD / Audit** | Same as 8.2 (logger / admin; multi-event audit). |
| **Legacy** | `submit_sales.php`, `ajax_pos2.php`, mobile do **not** implement refunds. Pre-8.3 whole-line `status=refunded` without request-lines treated as fully refunded (may be unmappable to line qty history). |

### Implemented vs deferred

| In 8.1–8.4 | Deferred |
|------------|----------|
| Controlled integrity + REF + reasons + tokens | **8.5** refund/cash/stock recon hardening |
| Authorisation workflow + SoD + request + audit | Monetary thresholds / multi-level approval |
| Line-level partial / cumulative / multi-line | Account credit notes; CARD/ECOCASH reversals; GL/VAT |
| Stock + cash rules | Phase 8 overall finalisation (if required separately) |
| **8.4** cart discard + safe uncompleted cancel; committed → refund only | |
| **8.5** refund/cash/stock recon (read-only + review status) | |
| **8.6** refund recon reporting (summary / detail / CSV) | |

## Refund / cash / stock reconciliation (Phase 8.5)

```text
refund_reconciliation.php
  → psSaleRefundReconciliationRun (read-only)
       → PROCESSED requests ↔ sale header/lines
       → request lines ↔ stock_movements(REFUND, REFUND+REF-######)
       → CASH tender ↔ cash_movements(CASH_REFUND); non-cash → none expected
       → PROCESSING audit links; CANCELLED ↔ sale_cancel_audit + zero footprint
       → legacy status=refunded without request-lines → INCONCLUSIVE
  → optional psSaleRefundReconRecordReview (append-only; does not alter ledgers)
```

- **Expected stock:** one `stock_movements` REFUND per processed request line (product+branch+qty; `reference_type=REFUND`, `reference_id=REF-######`).
- **Expected cash:** if `sale_payments.method=CASH`, one `CASH_REFUND` per processed request amount (`REFUND`+REF-######). CARD/ECOCASH/BANK_TRANSFER/OTHER/CREDIT → no cash movement expected.
- **Qty authority:** same as 8.3 — only PROCESSED request-line sums. Over-refund = PROCESSED sum > original sold.
- **No auto-fix.** Review status is separate from the live finding. CREDIT ledger non-reversal documented, not invented.
- **Out of scope for 8.5:** 8.6 reporting dashboard (delivered separately), GL/VAT, CREDIT notes, reopen tills, rewrite history.

## Refund reconciliation reporting (Phase 8.6)

```text
refund_recon_report.php
  → psSaleRefundReconReportRun → psSaleRefundReconciliationRun (8.5)
  → summary counts (filtered) + paginated findings + CSV export
refund_recon_finding.php
  → psSaleRefundReconReportFindingDetail + review history (read-only)
```

- **Helpers:** `includes/sale_refund_recon_reporting.php` (reuses 8.5; no second recon rule set).
- **Filters:** date range, authorised branch, category, severity, review status, sale/refund refs (SQL on PROCESSED requests + post-filter for non-request findings).
- **Does not:** create findings/reviews on open/filter/export; invent monetary loss totals; expose other-branch data; mutate ledgers/audits.
- **Phase 7 cash attribution limits** remain documented on the report (legacy sale paths outside modern cash ledger).
- **Out of scope for 8.6:** auto-resolve, bulk review status, GL/VAT, CREDIT notes, rewriting history.

## Voids / sale cancellation (Phase 8.4)

```text
ajax_pos.php → Clear cart → ajax_pos_discard_cart.php → psSaleCancelDiscardCart (+ sale_cancel_audit DISCARD_CART)
refund.php search → psSaleCancelClassify
  → UNCOMPLETED (no payment/stock/cash/AR/refund) → psSaleCancelUncompleted → status CANCELLED + audit (no ledger moves)
  → COMMITTED / refunded / pending / partial → REJECT (must use refund request → approve → process)
```

- **Discard vs reverse:** Discard = uncommitted browser cart only. Cancel uncompleted = mark `CANCELLED` when ledgers were never touched. Reverse of committed sales = existing refund workflow only (`psSaleCancelDirectVoidCommittedEnabled() === false`).
- **Auth:** session `logger`; working branch must match sale branch; posted branch ignored; no new roles/thresholds.
- **Integrity:** txn + `FOR UPDATE` on cancel; duplicate cancel rejected; originals retained; no VOID movement types; no `goods.quantity` writes; no till reopen / recon edit.
- **Out of scope for 8.4:** 8.6 reporting, GL/VAT, CREDIT ledger invents on refund, external payment reversals. (8.5 recon delivered separately.)

### Request header + lines (8.2 + 8.3)

`sale_refund_requests` holds workflow state. `sale_refund_request_lines` holds per-line requested qty + display price/amount snapshots (sale lines remain authoritative). Approval locks quantities (no mutation); change = reject + new request.

## Cash till ledger (Phase 7.1)

```text
sessions (existing till) → cash_movements (append-only ledger)
                         ↘ sessions.opening_float = open-time metadata snapshot only
ajax_pos.php → submit_sale2.php (CASH) → sale_payments + CASH_SALE
refund.php (CASH tender) → stock REFUND + CASH_REFUND (same txn; open till required)
cash_movement.php → CASH_IN / CASH_OUT (Phase 7.3 controlled reasons) + read-only history
```

- **Authoritative balance:** `psCashMovementBalance($tillSessionId)` = Opening + Sales + Cash In − Refunds − Cash Out. Not from `goods.quantity` or `sale_payments` alone.
- **Opening float:** on till open with amount > 0, atomic `OPENING_FLOAT` with session insert; duplicate blocked (app + UNIQUE). Zero float does not invent a movement. `sessions.opening_float` remains the snapshot written at open — ledger is cash authority after open.
- **CASH sale:** production path only; amount = server sale total; same txn as sale/payment/stock; rollback removes cash movement.
- **CASH refund:** only when `sale_payments.method = CASH`; amount = server refund total for the processed request; requires cashier’s open till on sale branch; one `CASH_REFUND` per request (`REFUND` + `REF-######`). Sales without `sale_payments` / non-cash tenders: no CASH_REFUND (limitation documented).
- **Permissions:** session `logger` + caller’s own OPEN till matching working branch (same gate as POS sales). No elevated cash role invented.
- **Out of scope for 7.1:** till closure, reconciliation, cash-count, handover, variance approval, deposits, bank rec, supplier payments, GL, VAT, periods, P&L, alerts/SMS, mobile cash protocol, historical migration, automatic adjustments, customer-account cash into this ledger.

## Cash-in / cash-out controls (Phase 7.3)

```text
cash_movement.php → psCashMovementPostManual (controlled reason + description)
                 → cash_movements + cash_movement_audit (CREATE)
                 → psCashMovementBalance (expected RO; no second ledger)
```

- **Movement type unchanged:** `CASH_IN` ↑ / `CASH_OUT` ↓ only. Reason is a controlled code; optional description; `OTHER` requires meaningful non-whitespace description (≥ 3 chars; not whitespace-only / trivial).
- **IN reasons:** FLOAT_ADJUSTMENT, CASH_TRANSFER_IN, MANAGEMENT_CASH_IN, OTHER. **OUT reasons:** PETTY_CASH, CASH_TRANSFER_OUT, MANAGEMENT_CASH_OUT, EXPENSE, OTHER. Labels are operational only — EXPENSE is **not** GL expense accounting.
- **Refs:** server `CIN-######` / `COUT-######` via `cash_movement_seq` (`SELECT … FOR UPDATE`). Posted refs ignored. Seq may advance from tests; never reset.
- **Ownership:** authenticated `logger` only; ignore posted user/cashier/branch/till/session/balance. Own OPEN till with ACTIVE register; reject foreign / closed / inactive / other-user. Branch from till session / working branch only.
- **Atomic post:** validate → lock till → re-read OPEN/own/active → allocate ref → insert movement → audit → COMMIT; fail = full rollback. Submit-token + UNIQUE ref. Append-only (no edit/delete; future reversal module).
- **Negative expected:** CASH_OUT is **not** auto-blocked when expected would go negative (`psCashMovementBlockNegativeExpectedOnCashOutEnabled() === false`). No invented min-cash / overdraft policy.
- **Recon interaction:** new IN/OUT immediately affect next `psCashMovementBalance` / recon expected. Does not modify existing `cash_reconciliations`. Later activity after FINAL recon OK while till open; historical recon immutable. Does not block IN/OUT merely because recon exists.
- **UI:** clear Cash In vs Cash Out; amount / reason / description / auto ref; current till + RO expected cash; history (datetime, type, amount, direction, ref, reason, description, user, session) — non-admin scoped; admin branch filter per existing model.
- **Out of scope for 7.3:** deposits, bank recon, GL, VAT, expense/supplier accounting, account-payment cash into ledger, shortage/surplus adjustment, auto balancing, reversal workflow, automated approvals, SMS/email/WhatsApp, mobile cash protocol, historical migration, app-wide permission/CSRF frameworks. (Till closure = Phase 7.4.)

## Till closure (Phase 7.4)

```text
sessions (OPEN) → cash_movements (ledger) → cash_reconciliations (latest FINAL, current)
                                          ↘ cash_till_close.php → sessions CLOSED (+ snapshot) + till_closure_audit
```

- **Lifecycle model:** `sessions.status` remains **OPEN | CLOSED** only. Business phases map as: OPEN = operational (“ACTIVE”); RECONCILED = current FINAL recon exists (not a sessions.status); CLOSED = irreversible. No competing status columns.
- **Prerequisites:** lock till FOR UPDATE → still OPEN/own/branch → recalculate ledger → latest FINAL for same session → current (recon.expected == ledger) → variance/notes valid → snapshot + CLOSED + audit → COMMIT. Fail → rollback, stay OPEN.
- **Non-zero variance:** BALANCED / SHORTAGE / SURPLUS close allowed when recon is current; notes required for non-zero (from recon and/or closure notes). No auto balancing / GL / cash_movements changes.
- **Snapshot fields on `sessions`:** `expected_cash`, `actual_cash`, `variance`, `closed_by`, `final_reconciliation_id`, `closure_notes`, `reconciliation_status` (prefer sessions over a duplicate closures table).
- **After CLOSED:** reject POS sale, CASH_IN/OUT, new recon, operational CASH_REFUND (open till required). No edit/delete of closure. No normal reopen (`psTillClosureReopenBlocked`). History via `psTillClosureHistory` (branch-scoped; admin may filter all).
- **Handover:** **not implemented** (`psTillClosureHandoverEnabled() === false`). Mid-session ownership transfer deferred — close, then incoming cashier opens a new session. Historical `created_by` never rewritten.
- **Legacy unprotected close:** `tillClose()` / `close_session.php` no longer flip OPEN→CLOSED without recon; POS “Close Till” links to `cash_till_close.php`.
- **Out of scope for 7.4:** mid-session handover, deposits/bank recon, GL/shortage accounting, VAT, supplier payments, account-payment cash into ledger, notifications, mobile close protocol, historical migration, app-wide CSRF/permission frameworks. (Management cash dashboard = Phase 7.5.)

## Cash management dashboard & reporting (Phase 7.5)

```text
sessions + cash_movements + cash_reconciliations + closure snapshot
  → includes/cash_reporting.php (read-only derived views)
  → cash_management.php / movement·recon·closure reports / session view / audit
```

- **Authoritative sources:** till = `sessions`; ledger = `cash_movements` via `psCashMovementBalance`; recon = `cash_reconciliations`; closure = sessions snapshot + `till_closure_audit`. Never `goods.quantity`, cached balances, browser totals, or legacy till calcs.
- **Expected cash (OPEN):** Opening + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT (existing helper only). **CLOSED:** closure figures from stored snapshot; ledger still audit-available.
- **Dashboard cards:** Open Tills, Expected Cash, Cash Sales, Cash In, Cash Out, Cash Refunds, Shortages, Surpluses (shortage and surplus never netted). Tables: open till status, recent recons, recent closures, movement summary. Prominent stale warning when latest FINAL expected ≠ live ledger.
- **Status vocabulary:** No recon / Awaiting / Current / Stale / Closed / Balanced (var=0) / Shortage / Surplus / Reconciled — balanced is never inferred merely because a recon exists.
- **Filters (server):** branch, till/register, cashier, status, date from/to (end date includes full local day). TZ = Africa/Harare. Ignore posted expected/totals/foreign ownership. Ordinary = own working branch; admin = all-branch filter.
- **Reports:** paginated movement / recon / closure; cashier / till / branch summaries (operational, not appraisal); closed till history with session drill-down; audit RO; CSV export respecting branch security.
- **Mutations:** `psCashReportingMutationsEnabled() === false` — reporting never creates/edits/deletes movements, recon, close, reopen, or session ownership.
- **Boundaries:** Phase 6.3 account payments and non-cash tenders excluded from expected / cash-sales cards. Legacy `submit_sales.php` / `ajax_pos2.php` / mobile `sync_sales` remain outside modern cash ledger (no retrofit).
- **Out of scope for 7.5:** GL, bank deposits/recon, VAT, supplier payments, account-payment cash into ledger, auto balancing, shortage/surplus adjustments, cash forecasting, SMS/email/WhatsApp, mobile dashboard, advanced BI, new permission/CSRF frameworks, historical cash migration, till reopen.

## Cash management finalisation (Phase 7.6)

- Integration audit of 7.1–7.5; single cash formula `psCashMovementComputeBalanceFromParts` / `psCashMovementBalance`; sessions money DECIMAL(14,2); consolidated regression; formal Phase 7 close.
- **Limitations documented:** legacy sale paths outside ledger; no browser testing; no mid-session handover; no app-wide CSRF; no deposits/bank recon/GL/shortage accounting.
- **Phase 7 COMPLETE** (READY WITH DOCUMENTED LIMITATIONS).

## Till cash reconciliation (Phase 7.2)

```text
sessions (open till) → cash_movements (ledger expected via psCashMovementBalance)
                      ↘ cash_reconciliations (FINAL snapshot: counted, variance, status)
                         → cash_reconciliation_audit (append-only CREATE/FINALISE)
```

- **Expected cash:** Opening + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT via existing `psCashMovementBalance` (no duplicate formula; not from `sale_payments` / browser / posted expected). Only CASH_REFUND rows on that till session count (Phase 7.1 refund attribution limitations preserved).
- **Counted cash:** physical count DECIMAL ≥ 0 (server). Variance = counted − expected. Status: =0 BALANCED, <0 SHORTAGE, >0 SURPLUS. No arbitrary tolerance.
- **Lifecycle:** FINAL on create; one FINAL per `till_session_id` (app + UNIQUE). Immutable after final — no edit counted/variance. Prefer single final (no multi-count workflow).
- **Notes:** required (non-empty non-whitespace) for SHORTAGE/SURPLUS; optional for BALANCED. Not a GL/shortage financial adjustment.
- **Timing:** current open till focus. Does **not** auto-close or lock till after recon (till closure = Phase 7.4 via `cash_till_close.php`). Does not block later cash activity solely because a recon exists. Phase 7.4 may require a **fresh** FINAL when prior expected no longer matches the ledger.
- **Ledger immutability:** recon never INSERT/UPDATE/DELETE `cash_movements`; no auto balancing movements.
- **Permissions:** session `logger` + caller's own OPEN till matching working branch (same as 7.1 / POS). Cannot recon another user's till via POST session id; ignore posted cashier/branch/till/expected/variance/status. History branch-scoped; admin may view all branches (purchasing-reports pattern).
- **Concurrency:** txn + till `FOR UPDATE` + re-read ledger inside txn + UNIQUE final + submit-token.
- **Customer-account boundary:** Phase 6.3 payments still excluded from expected cash (`psCashReconciliationCustomerAccountPaymentInExpectedEnabled() === false`).
- **Unused placeholders:** `sessions.expected_cash` / `actual_cash` / `variance` columns may exist from till schema prep — Phase 7.2 does **not** write them; `cash_reconciliations` is the snapshot authority.
- **Out of scope for 7.2:** till closure, cashier handover, auto session close/lock, cash deposits, bank recon, GL, shortage/surplus financial adjustments, auto CASH_IN/OUT balancing, account-payment cash into ledger, supplier payments, VAT, management dashboard, notifications, mobile recon protocol, historical cash migration, complex approval workflows.

## Cursor workflow

Before implementing a new feature:

1. Read `docs/POS_PROJECT_CONTEXT.md`
2. Read `docs/POS_BUSINESS_RULES.md`
3. Read `docs/POS_DATABASE_RULES.md`
4. Read `docs/POS_ROADMAP.md` (and `docs/POS_REGRESSION_BASELINE.md` when touching data or regressions)

Also:

- Do **not** modify unrelated modules merely because they contain legacy code.
- Preserve established business rules unless the current task explicitly changes them.
- Prefer CLI verification; do not claim browser testing unless performed.
- Prefer minimum fixes over redesigns.

## Known legacy / alternate paths

See `POS_REGRESSION_BASELINE.md` section on legacy paths. Production login for non-admin users lands on `ajax_pos.php`. Older POS UIs (`pos_dashboard.php`, `pos_desktopview.php`, `index.php`) still post to `submit_sales.php` and are not the primary wired path.
