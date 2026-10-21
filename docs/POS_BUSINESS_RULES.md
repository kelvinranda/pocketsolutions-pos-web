# POS Business Rules

Rules actually established in Phases 1–3. Unknown future-phase rules are omitted.

## Sales (Phase 1 + 3 touchpoints)

- Production checkout: cart in `ajax_pos.php` submits to `submit_sale2.php`.
- Sale lines are built with `psBuildSaleLines` / `psActiveGoodsForSale`:
  - Identity: `product_id` + session branch
  - Product must be `ACTIVE`
  - Exactly one matching `goods` row
  - Unit price taken from branch `goods.price` (client-submitted price ignored)
- Payment evaluation and sale integrity helpers live in `includes/sale_payment.php` (methods include CASH / CARD / ECOCASH / BANK_TRANSFER / **CREDIT** / OTHER — Phase 6.2).
- Invoice numbers are allocated via `sale_invoice_seq` (`psAllocateInvoiceNumber`). Module 3.10 records the verified sequence state; it does not redesign or “correct” sequencing.
- Historical sale / Z-reading / GRV stored amounts and names are **not** recalculated when product master prices or categories change.
- Mobile sale sync (`mobile-endpoints/mobile_api/sync_sales.php`) writes the same online sales shape as `submit_sale2.php` and validates products by identity (not by name alone). Phase 5.2 does not add master customer identity to the mobile payload; Phase 6.2 keeps CREDIT disabled on mobile.
- Phase 5.2 optional POS customer: `customer_master_id` on `online_sales_ref` (see Customers / POS customer section). Phase 6.2 CREDIT requires that identity plus an ACTIVE account.

## Inventory (Phase 2)

- Stock movements are recorded in `stock_movements`.
- Sale completion can create `SALE` movements in the same DB transaction as the sale when products are ledger-tracked.
- Stock availability checks use ledger balances for migrated/tracked products; products without opening-balance migration are not blocked solely for lacking ledger rows (see `psGuardSaleStock` behaviour).
- Opening stock, adjustments, transfers, refunds restoration, and GRV integrate with the ledger as implemented in Phase 2 modules.
- `goods.quantity` is **not** the authoritative stock balance.

## Products (Phase 3)

### Identity

- `product_id + branch` is authoritative.
- Duplicate names allowed; names are display/search only.
- Same `product_id` may exist on multiple branches with independent master fields and stock.

### Create / edit

- Create: `create_items.php` inserts master fields + `status=ACTIVE` + optional `reorder_level`; does not list `quantity` or `re_order_level` in the INSERT column list.
- Edit: `items_list.php` updates master fields by `goods.id` + session branch; does not write `quantity` or `re_order_level`.
- Status toggle uses `psSetGoodsStatusById` (branch-scoped; audits ACTIVATE/DEACTIVATE).

### Pricing

- `price` and `cost_price` validated with `psValidateProductMoney` (non-negative; max 2 decimal places; zero allowed).
- Prices are per branch row.
- Profit margin is calculated only (not stored).
- Changing price does not rewrite past sales.

### Categories

- `category` = product classification (free-text).
- `button_category` = POS button grouping.
- Fields stay independent; no mass correction of historical mismatches.

### Branch isolation

- Writes use session working branch.
- Foreign posted branch rejected.
- Import target branch must be authorised (admin: any `branchies` / All Branches export; non-admin: session-authorised only).
- Sheet `Branch` column on import is informational and does not override the selected form branch.

### Import / export

- Export headers (tab-separated): Product ID, Product, Price, Cost Price, Category, Button Category, Reorder Level, Pack Size, Status, Branch.
- Import matches by `product_id` + selected form branch.
- Import never writes `quantity` or `re_order_level`.
- Import never changes existing `status`; new rows are `ACTIVE`.
- Status is exported for reference only.

### Audit

- Append-only `product_audit` rows for actual field changes (UI + import).
- No silent rewrite or delete of audit history in application helpers.
- No backfill of changes that occurred before the audit table existed.

## Suppliers (Phase 4A)

- Authoritative master: `supplier_master` via `includes/supplier_master.php`.
- Identity: unique global `supplier_code` (not name). Names are display/search only; duplicate names are not blocked by UNIQUE (code is).
- Scope: suppliers are global; branch is recorded on purchasing / GRV transactions, not on the supplier row.
- Status: `ACTIVE` / `INACTIVE`. New suppliers are created `ACTIVE`. Inactive suppliers are not returned by `psActiveSuppliersForSelect` for new purchasing selection.
- CRUD: list/search/create/edit/activate/deactivate (`suppliers_list.php`, `create_supplier.php`). Auth requires session `logger`.
- Validation: required code + name; optional contact person / phone / email / address; light email format when email present; code character set letters/numbers/`.`/`-`/`_`.
- Never hard-delete suppliers from the application UI (`psDeleteSupplierBlocked`).
- Audit: append-only `supplier_audit` for actual CREATE / UPDATE / ACTIVATE / DEACTIVATE field changes. No backfill of legacy free-text GRV supplier names.
- Existing GRV (`grv.php` → `psSaveGoodsReceipt`) continues to accept free-text `supplier_name` and is **not** rewritten in 4A.
- Legacy DB tables `suppliers`, `suppliers_link`, `supplier_invoices` are not the Phase 4A master and are not mass-migrated.

## Purchase Orders (Phase 4B)

- Authoritative helpers: `includes/purchase_order.php`. UI: list / create-edit draft / view-print / audit.
- Architecture: Supplier → PO header → PO lines → Receiving (4C) → stock.
- Branch: session working branch (`psResolveProductWriteBranch` / `psRequireSessionProductBranch`) is authoritative; posted foreign branch is rejected. Line `branch` always equals header `branch`.
- Supplier: new POs require an **ACTIVE** `supplier_master` row (`psActiveSuppliersForSelect`). Inactive / invalid supplier ids are rejected.
- Products: new lines require `product_id` + PO branch identity (`psGoodsByProductIdBranch`) and **ACTIVE** status. Names are display only (labels include product id for duplicates). Duplicate `product_id` on the same PO is rejected.
- Qty: must be > 0, max 3 decimal places. Unit cost: `psValidateProductMoney` (non-negative; zero allowed; max 2 decimals; no silent round of input).
- Line total: server `qty × unit_cost` (2dp half-up for storage); browser totals ignored. Does **not** change `goods.cost_price`.
- Numbering: dedicated `purchase_order_seq` allocates `PO-000001` style numbers server-side inside the create transaction (`SELECT … FOR UPDATE`). Does **not** use `sale_invoice_seq`.
- Lifecycle: `DRAFT` editable; `APPROVE` → `APPROVED`; `CANCEL` from DRAFT or APPROVED → `CANCELLED` (does not reverse stock). Arbitrary status POST blocked. `CLOSED` only via Phase 4C receiving when all lines fully received (server calc — never browser force).
- Received qty: derived from `goods_receipts` by `purchase_order_line_id` (no stored `received_qty` on PO lines).
- Audit: append-only `purchase_order_audit` for CREATE / UPDATE / APPROVE / CANCEL / CLOSE. No backfill. Not `product_audit`.
- Auth: session `logger` required on every create/edit/approve/cancel/list/view. Prepared statements; branch checked on every load/action.
- Legacy `request_orders` not reused.

## Goods Receiving (Phase 4C)

- Authoritative path: `grv.php` → `psSaveGoodsReceipt` (`includes/stock_movement.php` + `includes/goods_receipt.php`).
- Modes: **Non-PO** (legacy free-text `supplier_name`) and **PO-linked** (APPROVED only; not DRAFT/CANCELLED/CLOSED; not fully received). Multiple GRVs per PO allowed (partial receiving). Prefer one GRV → one PO.
- PO-linked identity: receive against `purchase_order_lines.id`; supplier inherited from PO (`supplier_id` + name snapshot) — POST free-text supplier must not replace identity; unit cost from PO line; do **not** auto-update `goods.cost_price`.
- Validation: qty > 0 and ≤ remaining (reject over-receive; no silent truncate); zero/negative rejected; empty receipt rejected; product_id+branch valid; line belongs to PO; line branch = PO branch = session branch.
- Received qty model: **derive** from SUM of `goods_receipts.quantity` for `purchase_order_line_id` (single source of truth; no dual `received_quantity` column on PO lines).
- Numbering: `goods_receipt_seq` → `GRV-000001`. Supplier invoice number remains user field (`invoice_number` / `reference1`). Never `sale_invoice_seq` / PO seq / `MAX()+1`.
- Stock: atomic `goods_receipts` + `goods_receipts_ref` + `stock_movements` (`GOODS_RECEIPT`) for actual received qty only. No `goods.quantity` authority write. Rollback on any failure.
- PO status after receive: stay `APPROVED` while any remaining; `CLOSED` only when all lines fully received (server). Cancel does not reverse prior GRV stock.
- Audit: append-only `goods_receipt_audit` (CREATE_RECEIPT / RECEIVE / CLOSE_PO) + PO audit CLOSE. No backfill. Not `product_audit`.
- Auth: session `logger`; session branch; prepared statements; submit token for duplicate-submit protection (not app-wide CSRF).
- Listings: `goods_receipt_ref.php` shows GRV#, PO#, supplier, branch, date, total, user. PO list/view show ordered/received/remaining and Receive when APPROVED with remaining.
- Historical GRVs (pre-4C, nullable PO/GRV columns) remain readable. Legacy `submit_grv.php` / `online_grv_*.php` are not the production path.

## Purchase Returns (Phase 4D)

- Authoritative helpers: `includes/purchase_return.php`. UI: list / create / view / audit.
- Traceability: return lines require a reliable source `goods_receipts.id` on a numbered GRV (`grv_number` non-empty) with `product_id` + `branch`. Historical GRV lines without `grv_number` are not returnable through this module (no invented sources).
- Returnable qty: `received line qty − SUM(purchase_return_lines.quantity)` for that `goods_receipt_line_id`. Partial returns allowed; over / zero / negative / malformed rejected.
- Cost: original receiving `line_cost` (not current `goods.cost_price`).
- Numbering: `purchase_return_seq` → `PR-000001`. Never `sale_invoice_seq` / PO / GRV seq / `MAX()+1`.
- Stock: atomic header + lines + `stock_movements` (`PURCHASE_RETURN`, out / negative qty). Distinct from customer `REFUND`. No `goods.quantity` / `goods.cost_price` writes. Controlled-stock products (with `OPENING_BALANCE`) use the same availability rule as sales.
- Lifecycle: complete once on save (stock reverses once). Duplicate submit protection via session submit token (not app-wide CSRF). Dedicated `purchase_return_audit` (not `product_audit`).
- PO impact: returns do **not** reopen `CLOSED` POs and do **not** change PO received/remaining derivation (still from `goods_receipts` only).

## Supplier Purchasing History / Balance (Phase 4D)

- History UI: `supplier_purchasing_history.php` keyed by `supplier_master.id` (not name). Shows POs, GRVs (`supplier_id`), returns; summaries: PO count/value, GRV count/value, return value, net received value, latest date.
- Branch filter: session branch for normal users; admin may filter / all branches for reports and history.
- Inactive suppliers remain visible in history; not selectable for new purchasing.
- **Balance decision:** there is **no** reliable payable/payment model linked to `supplier_master` + GRV/PO. Do **not** invent “balance owed.” Legacy `supplier_invoices` (free-text supplier / amount / amount_out / account_balance) is **not** authoritative and was not promoted. Prefer Purchasing History / Received Purchases Summary language.

## Purchasing Reports (Phase 4D)

- `purchasing_reports.php`: PO report, GRV report, Purchase Return report with date / supplier / branch / status (PO) filters.
- Server-side branch security via `psPurchasingResolveReportBranch`.
- Clear separation: ordered (PO) vs received (GRV header totals) vs returned; returns do not reopen PO outstanding. Avoid double-counting dashboard-style combined totals (no full analytics dashboard).

## Legacy GRV paths (Phase 4D classification)

| Path | Classification | Action |
|------|----------------|--------|
| `grv.php` → `psSaveGoodsReceipt` | **active** | Authoritative receiving |
| `submit_grv.php` | **hardened / disabled** | Refuses writes; points to `grv.php` |
| `create_grv.php` | **legacy/dead** | Older UI posting to disabled `submit_grv.php`; not nav production path |
| `online_grv_*.php` | **legacy/dead** | Hardcoded remote DB credentials; unused by production nav |

## Customers (Phase 5.1)

- Authoritative master: `customer_master` via `includes/customer_master.php`.
- Identity: unique global `customer_code` (server `CUS-######` via `customer_seq`) and surrogate `id`. Names / phone / email / national_id are not primary identity.
- Scope: customers are global; no branch on the master row.
- Types: `INDIVIDUAL` (first + last name required) / `BUSINESS` (business name required).
- Status: `ACTIVE` / `INACTIVE`. New customers are created `ACTIVE`. Inactive remain listable for history; not returned by `psActiveCustomersForSelect`.
- CRUD UI: list/search/filter (`customers_list.php`), create (`create_customer.php`), view/edit/activate/deactivate (`customer_view.php`), audit (`customer_audit_history.php`). Auth requires session `logger`.
- Updates never change `id` or `customer_code`.
- Duplicate controls: **block** when supplied `national_id` already exists (UNIQUE + app check; empty stored as NULL). **Block** obvious phone (digit-normalized), email (case-insensitive), and business-name (case/whitespace-normalized, BUSINESS type) duplicates without making phone/email globally UNIQUE in schema.
- Audit: append-only `customer_audit` for actual CREATE / UPDATE / ACTIVATE / DEACTIVATE field changes. No backfill of legacy `customers` rows.
- Never hard-delete from UI (`psDeleteCustomerBlocked`).
- Legacy `customers.php` / table `customers` / `top_customers` are not the master.
- Submit-token: not used on customer create/edit (consistent with supplier master UI). Not app-wide CSRF.

## POS customer on sales (Phase 5.2)

- Production path: `ajax_pos.php` → `ajax_pos_customer_search.php` (ACTIVE search) → `submit_sale2.php` → `psResolveSaleCustomer`.
- Sale identity: `online_sales_ref.customer_master_id` = `customer_master.id` (nullable). Display name snapshot may still be stored in free-text `customer` for receipts/listings.
- Optional: walk-in / Cash Customer → `customer_master_id` NULL. Customer is **not** mandatory. No auto-create of `customer_master` for Cash Customer.
- New selection: ACTIVE only (server). Inactive / nonexistent / malformed ids rejected. Posted name/code cannot establish or override identity.
- Branch: customer master is global (selectable across branches if ACTIVE); sale remains branch-scoped via till/session.
- Legacy `online_sales_ref.customer_id` means **`customers.id`** and is **not** written by production POS (left NULL). Do not treat legacy ids as master ids.
- Mobile `sync_sales.php`: free-text `customer` only — no reliable master identity in payload; `customer_master_id` stays NULL (no invented mobile protocol).
- Legacy `submit_sales.php`: write path **retired** in Phase 9.3 (403 JSON; no inserts). Older UIs may still POST here but sales will not commit; production path remains `submit_sale2.php`.
- Reports: `all_receipts.php` resolves master display name when `customer_master_id` is set; no new statements/balances.
- Create-from-POS full form is out of scope — use `create_customer.php`.
- Profile / linked sales history: Phase 5.3 (`customer_view.php` + `psCustomerSalesHistory`) — read-only; not credit/AR.

## Customer profile & transaction history (Phase 5.3)

- Profile UI: `customer_view.php` — separate sections for **Profile**, **Transaction History** (linked sales), and **Audit History** (`customer_audit`). Do not mix audit with sales.
- Sales identity for history: only `online_sales_ref.customer_master_id` = `customer_master.id`. Never free-text `customer`, legacy `customer_id`, name, phone, email, or Cash Customer matching.
- Fields shown from reliable header columns: invoice #, date/time (`sold_at` or `date_`), branch, total (`totalz`), status when present, payment method/currency from `sale_payments` when present, refs (`order_number` / `till_session_id`). Invoice links to existing `my_receipt.php` (no second invoice system).
- Optional summaries: linked sales **count** + **total value** for the active date filter — clearly labeled as sales history summaries, **not** balance owed / receivable / AR / statement.
- Date filters: `all` / `today` / `month` (default) / `custom` with server validation + prepared statements. Pagination via LIMIT/OFFSET. Whitelisted sort keys.
- Branch: customer is global; history may span branches. No invented role-based branch filter on this view.
- Inactive customers remain viewable with history; POS selection stays ACTIVE-only (5.2 unchanged).
- Viewing profile/history does **not** insert UPDATE audits. Auth: session `logger`. National ID on profile only — not on list / POS search / history tables.
- List (`customers_list.php`): open profile; linked sale count via one aggregate join on `customer_master_id` (no N+1).

## Customer finalisation (Phase 5.4)

- Architecture closed: `customer_master` → `customer_audit` + optional `online_sales_ref.customer_master_id`. Same helpers as 5.1–5.3; no new credit/AR surface.
- Hardenings (defects only): `psCustomersByIds` batch lookup for receipt listings (no N+1); legacy `customers.php` requires session for writes, no raw SQL in UI, banner to master; `submit_sales.php` / `all_receipts.php` no longer echo `mysqli_error`.
- Status transitions remain ACTIVE↔INACTIVE only. Hard delete remains blocked. Legacy `customers` rows (4) not deleted; `customer_seq` not reset.
- POS ACTIVE-only search/resolve; admin list may include INACTIVE; national_id not on list/POS/history tables.
- No app-wide CSRF claim. Browser testing not performed for Phase 5.4.

## Customer accounts & receivables ledger (Phase 6.1)

- Authoritative helpers: `includes/customer_account.php`. UI: list / create / view / account audit (`customer_accounts_list.php`, `create_customer_account.php`, `customer_account_view.php`, `customer_account_audit_history.php`) via `ps_layout`.
- Architecture: `customer_master` → one optional `customer_accounts` row → `customer_account_ledger` (balance authority) + `customer_account_audit`.
- Create: explicit admin action only. Customer must exist and be **ACTIVE**. Duplicate account for same customer rejected (UNIQUE `customer_master_id`). Never silent-create for the 4 legacy `customers` rows, historical sales, names, or Cash Customer.
- Inactive customer: cannot create a new account until reactivated; existing accounts remain viewable after customer deactivation.
- Numbering: `customer_account_seq` → `ACC-######` server-side (`SELECT … FOR UPDATE`). Posted account number / balance / name / code ignored.
- Status lifecycle: `ACTIVE` / `SUSPENDED` / `CLOSED` via ACTIVATE / SUSPEND / CLOSE. Invalid status rejected. Never hard-delete.
- Ledger: DECIMAL debit/credit ≥ 0; not both > 0; no zero financial entries; entry types include OPENING_BALANCE / CREDIT_SALE / PAYMENT / CREDIT_NOTE / DEBIT_ADJUSTMENT / CREDIT_ADJUSTMENT.
- Opening balance: optional explicit admin workflow only (session auth + reason; one OPENING_BALANCE per account; auditable).
- Balance: `SUM(debit) − SUM(credit)`; positive = customer owes; negative = customer credit; empty ledger = `0.00`. No cached balance column (ledger authoritative).
- Customer profile (`customer_view.php`): shows whether account exists, ACC-#, status, ledger balance — sales history summaries remain separate and are still not AR statements.
- Auth: session `logger`; prepared statements; server-side validation. Not app-wide CSRF.

## Credit sales / account-charged sales (Phase 6.2)

- Production path only: `ajax_pos.php` → `submit_sale2.php` → `includes/sale_payment.php` + `includes/customer_account.php`.
- Payment method: `CREDIT` (`Credit (Account)`). `psEvaluatePayment` sets amount_paid = server sale total, change = 0; ignores posted cash amount. Not a cash till movement.
- Eligibility (server, mandatory): ACTIVE `customer_master_id` (not Cash Customer / NULL); ACTIVE `customer_accounts` row for that customer (lookup only — **no silent create**; clear reject if missing); account `credit_status=NORMAL` (Phase 6.5 — ON_HOLD rejects); sale total > 0 from server cart calc; account `credit_limit > 0`; all existing POS / stock / till / token validation.
- Credit limit maths:
  - `balance = SUM(debit) − SUM(credit)` (ledger)
  - `current_positive_balance = max(balance, 0.00)`
  - `available_credit` (display) = `credit_limit − current_positive_balance`
  - Authorisation: reject if `credit_limit = 0.00` (no facility) **or** `(balance + server_sale_total) > credit_limit`
  - Negative balance increases authorisation headroom under the projected-balance rule; display available_credit does not exceed the limit via the positive-balance formula
  - Posted balance / limit / eligibility / sale total ignored
- Same DB transaction as sale: after header/lines/`sale_payments`/stock `SALE` succeed, post exactly one `CREDIT_SALE` (`debit = server_sale_total`, `credit = 0`, `reference_type = SALE`, `reference_id = invoice number`). Account row locked (`FOR UPDATE`) during eligibility. Rollback on any failure — no sale without ledger, no orphan CREDIT_SALE.
- Duplicate protection: `UNIQUE(reference_type, reference_id, entry_type)` on `customer_account_ledger` + pre-insert existence check.
- Inventory: normal `SALE` movements only; do not invent special credit stock types; do not write `goods.quantity`.
- UI: payment sheet Credit option; read-only customer / account# / balance / limit / available credit (`ajax_pos_customer_account.php`). `customer_account_view.php`: available credit + CREDIT_SALE count/total; ledger shows CREDIT_SALE (paginated). Statements / ageing added in Phase 6.4.
- Legacy: `submit_sales.php` and mobile `sync_sales.php` keep CREDIT disabled (unsafe). No mobile credit protocol. No historical CREDIT_SALE backfill.
- Out of scope for 6.2: customer payments, allocation, statements, ageing, collections, GL, credit notes, debit adjustments, interest, VAT changes.

## Customer account payments & receivables allocation (Phase 6.3)

- Authoritative helpers: `includes/customer_account_payment.php`. UI: `customer_account_payments.php`, `create_customer_account_payment.php`, `customer_account_payment_view.php`, `customer_account_payment_allocate.php`, `customer_account_payment_audit_history.php` via `ps_layout`. Account view enhanced with open outstanding + recent payments.
- Architecture: `customer_accounts` → `customer_account_payments` + `customer_account_payment_allocations` + `PAYMENT` / `PAYMENT_REVERSAL` ledger + append-only `customer_account_payment_audit`.
- **Distinct from** `sale_payments` (POS sale tender). Do not reuse sale payment rows as account receipts. Account payment ≠ POS sale payment.
- Eligibility: ACTIVE `customer_master` + existing account. CLOSED rejected. SUSPENDED allowed to settle receivables (payment does not reopen to ACTIVE). Amount > 0; max 2 decimal places (`psValidateCustomerAccountMoney`).
- Numbering: `customer_account_payment_seq` → `PAY-######` (`SELECT … FOR UPDATE`). Never `sale_invoice_seq` / `customer_seq` / `customer_account_seq` / PO / GRV / return seq / `MAX(id)+1`. Posted payment number ignored.
- Methods: `CASH` / `CARD` / `ECOCASH` / `BANK_TRANSFER` / `OTHER`. `CREDIT` is sale tender only — rejected as account payment method.
- Lifecycle: create posts as `POSTED` in one transaction (CREATE + POST audit). Void: `POSTED` → `VOIDED` with reason.
- Ledger: exactly one `PAYMENT` credit (`debit=0`, `credit=amount`, `reference_type=CUSTOMER_PAYMENT`, `reference_id=payment.id`) per posted payment. Duplicate UNIQUE protection. Never invent `CREDIT_SALE` when recording payments. Allocations do **not** create ledger entries. Balance still `SUM(debit)−SUM(credit)` (no cached balance).
- Allocation: only Phase 6.2 `CREDIT_SALE` rows for the same `customer_account_id` (invoice = ledger `reference_id`). Outstanding = sale debit − SUM(ACTIVE allocations). Rules: allocated > 0, ≤ payment remaining, ≤ sale outstanding; multi-sale OK; no cross-customer. Default oldest-first; server revalidates inside txn. Unallocated remainder allowed (full PAYMENT credit still posts).
- Void (smallest safe reversal): mark payment `VOIDED`; post `PAYMENT_REVERSAL` debit equal to payment amount (same `CUSTOMER_PAYMENT` ref, different entry_type); set ACTIVE allocations to `VOIDED`; retain original PAYMENT ledger row and payment amount (never delete payment / never zero amount / never delete original PAYMENT).
- Concurrency: lock account (`FOR UPDATE`); lock payment on allocate/void; recalculate outstanding in transaction; reject over-allocation under concurrent users.
- Till: integration **explicitly disabled** (`psCustomerAccountPaymentTillIntegrationEnabled() === false`). Cash method is a receipt label only — no till session/float writes. Do not silently change till balances.
- Audit: append-only `customer_account_payment_audit` for CREATE / POST / ALLOCATE / VOID. No backfill.
- Auth: session `logger`; prepared statements; ignore posted balance / outstanding / allocation totals / payment number; submit-token on create/allocate/void (same pattern as purchase returns — not app-wide CSRF).
- Out of scope for 6.3: ageing, collections, interest, statements, GL, VAT, bad debt, credit notes, debit adjustments, historical payment/balance migration, mobile payment protocol, broad till redesign.

## Customer account statements & receivables ageing (Phase 6.4)

- Authoritative helpers: `includes/customer_account_reporting.php` (reuses `psCustomerAccountBalance`, `psCustomerAccountAvailableCredit`, `psCustomerAccountSaleOutstanding` — does not duplicate formulas).
- Statement UI: `customer_account_statement.php` (ps_layout). Header: name, code, account number, status, credit limit, current balance, available credit. Controls: All / Today / This month / Current year / Custom; Apply / Reset. Paginate LIMIT/OFFSET; order `entry_date ASC, id ASC`. Labels: Amount Due (positive) / Customer Credit (negative).
- Statement maths: opening = SUM(debit)−SUM(credit) for `entry_date < period_start` (0 when no start); period rows inclusive; closing = opening + period debits − period credits; running = previous + debit − credit. OPENING_BALANCE only when explicitly posted (never inferred). Cash/walk-in/CARD/ECOCASH/BANK_TRANSFER tenders appear only if genuinely on the ledger (production path does not put them there).
- Payment/reversal on statement: full PAYMENT credit amount; PAYMENT_REVERSAL debit on void; voided history remains visible. Allocations do not create statement lines and do not change the displayed payment credit.
- Ageing UI: `customer_receivables_ageing.php` — all or individual customer/account; filters: search (code/name/account — no national ID), account/customer status, as-at date, sale branch (session-secured). Columns: Customer | Account | Current | 1–30 | 31–60 | 61–90 | 91+ | Total Outstanding + totals row (sum buckets = total). Account view shows concise ageing via `psCustomerAccountAgeingSummary`.
- **Due-date source:** no `due_date` column on sales/ledger. Age uses `CREDIT_SALE.entry_date` as due date. Current/Not Due = days overdue ≤ 0 (including same-day). No invented grace/terms period.
- Ageing maths: outstanding portion only; fully paid excluded; partial ages remainder; unallocated payments affect ledger balance only (views stay distinct). No negative ageing buckets for credits.
- As-at: exclude CREDIT_SALE with `entry_date` after as-at; count ACTIVE allocations with `DATE(created_at) ≤ as-at`. Future-dated sales do not appear in earlier as-at. Historical reconstruction imperfect if void/status timing differs from `created_at`.
- Management summary: distinguish Total Credit Sales Outstanding vs Total Customer Account Balance (ledger as-at for matching accounts). Branch filter scopes outstanding by sale branch; account balances remain whole-account.
- Auth: session `logger`; prepared statements; server-side date/status/branch validation; ignore posted balances/outstanding/buckets. Viewing creates no ledger/audit rows. Export: not implemented (no existing receivables export pattern) — documented limitation.
- Out of scope for 6.4: collections, promises, SMS/email, write-offs, bad debt, interest, penalties, credit notes, debit adjustments, GL, VAT, reconciliation, mobile receivables, historical migration, auto account creation, auto-allocation of unallocated payments.

## Credit controls & collections foundation (Phase 6.5)

- Authoritative helpers: hold/release/limit in `includes/customer_account.php`; dashboard + collection activity in `includes/customer_credit_control.php`. UI: `customer_credit_control.php`, `customer_collection_activity.php`, account-view hold/release section.
- **Credit status** (`credit_status`): `NORMAL` | `ON_HOLD` — separate from account lifecycle `status` (ACTIVE / SUSPENDED / CLOSED). Do not overload lifecycle status with hold meaning.
- CREDIT eligibility (server, same `psCustomerAccountEvaluateCreditSale` as 6.2): ACTIVE customer + ACTIVE account + `credit_status=NORMAL` + credit_limit > 0 + projected balance ≤ limit. ON_HOLD → clear reject. SUSPENDED/CLOSED still reject. Cash / other tenders unaffected by hold.
- Hold: manual after review only — **no auto-hold** from ageing/overdue days. Requires controlled reason (OVERDUE, CREDIT_LIMIT, COLLECTIONS, MANAGEMENT_REVIEW, CUSTOMER_REQUEST, OTHER) + note when OTHER. Blocks new CREDIT sales only; payments / allocations / statements / viewing OK; no auto-close; existing receivables unchanged.
- Release: admin-only; cashiers cannot release hold from POS to complete a credit sale (server rejects regardless). Limitation: no granular credit-control role beyond admin vs non-admin.
- Credit limit: ≥ 0, DECIMAL precision; reason required; SELECT … FOR UPDATE; ignore posted balance/credit_status; changing limit never alters ledger; payments do not change limit; no auto limit from ageing. Audit action `LIMIT_UPDATE` (old/new/reason/user/time).
- Audit: extend `customer_account_audit` with `HOLD` / `RELEASE_HOLD` / `LIMIT_UPDATE` (append-only; prior hold reasons retained — not overwritten by clearing current display fields).
- Overdue: reuse Phase 6.4 ageing only. Overdue = outstanding in 1–30 / 31–60 / 61–90 / 91+ buckets (not Current, not unallocated credit, not negative balance). Utilisation = positive_balance/limit×100; limit 0 → no facility; negative balance → 0% (never negative %).
- Dashboard filters: customer, account number, credit status, account status, overdue bucket, sale branch (session-secured like 6.4), as-at. Summary totals from existing helpers only.
- Collection activity: append-only `customer_collection_activity`. Types: PHONE_CALL / VISIT / EMAIL / SMS / PROMISE_TO_PAY / FOLLOW_UP / OTHER. Outcomes: CONTACTED / NO_RESPONSE / PROMISE_RECEIVED / PROMISE_BROKEN / PAYMENT_RECEIVED / DISPUTE / ESCALATED / OTHER. PROMISE_TO_PAY: promised date required; optional non-financial amount — never PAYMENT/ledger. `next_action_date` planning only — no reminder engine. No national ID on lists.
- Concurrency: hold/release/limit use account FOR UPDATE; re-read inside txn; audit fail → ROLLBACK; ignore stale browser credit_status/limit/balance.
- Auth: session `logger` for viewing; admin for hold/release/limit. Prepared statements. Not app-wide CSRF. Browser testing not performed.
- Out of scope for 6.5: SMS/email/WhatsApp automation, scheduled reminders/calls/bots, interest, penalties, bad debt, write-offs, credit notes, debit adjustments, GL, VAT, credit bureau, mobile credit-control protocol, historical collection migration, automatic credit holds, automatic payment allocation, account closure from ageing.

## Cash movement & till ledger foundation (Phase 7.1)

- Authoritative helpers: `includes/cash_movement.php`. UI: `cash_movement.php` (manual CASH_IN / CASH_OUT + read-only session history) via `ps_layout`.
- Architecture: existing `sessions` till (`includes/till_session.php`) → append-only `cash_movements`. No second till model. No redesign of `sale_payments`. No cached running balance column.
- Movement types (controlled; direction via type; amount always DECIMAL > 0, never negative):
  - `OPENING_FLOAT` ↑ — till open with opening cash > 0 (atomic with session open; one per session)
  - `CASH_SALE` ↑ — production POS CASH tender only (amount = server sale total)
  - `CASH_REFUND` ↓ — physical cash returned when original tender was CASH
  - `CASH_IN` ↑ / `CASH_OUT` ↓ — manual movements with required reason
- References: OPENING_FLOAT → `TILL_SESSION` + session id; CASH_SALE → `SALE` + invoice; CASH_REFUND → `REFUND` + invoice (full) or `REFUND_LINE` + line id; CASH_IN/OUT → `CIN-######` / `COUT-######` via `cash_movement_seq`.
- Opening float dual field: `sessions.opening_float` = open-time metadata snapshot; `cash_movements` = cash calculation authority. Zero float does not invent OPENING_FLOAT.
- CASH POS path: `ajax_pos.php` → `submit_sale2.php` — CASH creates `sale_payments` + exactly one CASH_SALE in the same txn as sale/stock; non-cash / CREDIT create no cash movement. Dup protection: app + UNIQUE(reference_type, reference_id, movement_type) + payment token.
- Cash refunds: `refund.php` / `psProcessApprovedRefund` (`includes/sale_refund.php`) — after APPROVED request only; if `sale_payments.method = CASH`, post one CASH_REFUND per request (`REFUND`+REF-######) in same txn as stock restore; requires cashier’s open till on sale branch. Non-cash / missing sale_payments: no CASH_REFUND. Phase 8.1–8.3: controlled reasons, line-level partials, multi-event `sale_refund_audit`, `sale_refund_requests` + `sale_refund_request_lines`, submit-token.
- Balance: **single definition** — `psCashMovementComputeBalanceFromParts` / `psCashMovementBalance` = OPENING_FLOAT + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT (ledger-derived; reporting batch balances reuse the same helper).
- Auth: session `logger` required; operational posts require caller’s own OPEN till matching authenticated working branch. Posted foreign branch/till/session **rejected**; posted created_by / balance / reference **ignored** (server logger + allocated refs). Exact permission: same as POS sales (logger + own open till) — no elevated cash role.
- Append-only: no edit/delete helpers. Corrections = future reversal module (not 7.1). Manual CASH_IN/OUT also write `cash_movement_audit` CREATE rows.
- Customer account boundary: Phase 6.3 payments still do **not** post to `cash_movements` (`psCashMovementCustomerAccountPaymentIntegrationEnabled() === false`; till integration remains disabled).
- Legacy: `submit_sales.php` / `ajax_pos2.php` / mobile `sync_sales.php` do not write cash_movements (unsupported for production cash ledger).
- Out of scope for 7.1: till closure, cash reconciliation, cash-count sheets, cashier handover, variance approval, deposits, bank reconciliation, supplier payments, GL/journals, VAT, accounting periods, P&L, balance sheet, cash-flow accounting, automated alerts/SMS/email/WhatsApp, mobile cash protocol, historical cash migration, automatic cash adjustments, integrating account payments into this ledger.

## Till cash reconciliation (Phase 7.2)

- Authoritative helpers: `includes/cash_reconciliation.php`. UI: `cash_reconciliation.php` (current till count + branch-scoped history) via `ps_layout`.
- Architecture: reuse Phase 7.1 till (`sessions` + `cash_movements`) — no second till model. Reconciliation is a **snapshot** (`cash_reconciliations`), not a ledger replacement and not an edit of `cash_movements`.
- Expected cash: server-only via `psCashMovementBalance` (Opening + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT). Ignore posted expected / variance / status / ownership. Do not derive expected from `sale_payments` alone. CASH_REFUND attribution remains Phase 7.1 rules (only CASH_REFUND rows on that till session).
- Counted cash: DECIMAL ≥ 0, max 2 decimal places (zero allowed). Negative / malformed / >2dp rejected.
- Variance / status: variance = counted − expected; =0 BALANCED, <0 SHORTAGE, >0 SURPLUS. No arbitrary tolerance.
- Lifecycle: FINAL on create; historical FINAL rows immutable (counted/variance/status not editable). Phase 7.4 allows additional FINAL snapshots while the till stays OPEN so a stale count can be refreshed before close; close uses the latest FINAL. UNIQUE-per-session constraint removed in 7.4.
- Notes: SHORTAGE/SURPLUS require non-empty non-whitespace note; BALANCED optional. Note is explanation only — not shortage/surplus financial posting / GL / balancing movement.
- Timing: current open till. Does **not** auto-close or lock till after recon (till closure = Phase 7.4 via `cash_till_close.php`). Does not block later cash activity solely because reconciliation exists; activity after recon makes the prior count stale for close.
- Cash ledger immutability: never INSERT/UPDATE/DELETE `cash_movements` on reconcile; no auto CASH_IN/OUT to force balance (`psCashReconciliationAutoBalanceMovementsEnabled() === false`).
- Permissions: session `logger` + caller's own OPEN till matching authenticated working branch (same gate as POS / Phase 7.1). Cannot recon another user's till via POST session id. Posted cashier/branch/till/expected/variance/status ignored or rejected. History: non-admin working branch only; admin may filter all branches (same pattern as purchasing reports).
- Concurrency / integrity: BEGIN → lock till (`FOR UPDATE`) → verify open/own/branch → re-read ledger expected → validate counted → variance/status → note rules → INSERT recon (additional FINAL allowed while OPEN per 7.4) → audit → COMMIT; ROLLBACK on any fail. Submit-token idempotency (not app-wide CSRF).
- Audit: append-only `cash_reconciliation_audit` CREATE/FINALISE field snapshots. No UI edit/delete.
- Customer-account boundary: Phase 6.3 payments remain outside expected cash (`psCashReconciliationCustomerAccountPaymentInExpectedEnabled() === false`; till integration still disabled).
- `sessions.expected_cash` / `actual_cash` / `variance`: legacy/placeholder columns — **not** written by Phase 7.2; snapshot authority is `cash_reconciliations`.
- Out of scope for 7.2: till closure, cashier handover, auto session close/lock after recon, cash deposits, bank recon, GL, shortage/surplus financial adjustments, auto balancing movements, account-payment cash into ledger, supplier payments, VAT, management dashboard, notifications/SMS/email/WhatsApp, mobile recon protocol, historical cash migration, complex approval workflows.

## Cash-in / cash-out controls (Phase 7.3)

- Authoritative helpers remain `includes/cash_movement.php` (`psCashMovementPostManual`). UI: `cash_movement.php` via `ps_layout` — clear Cash In vs Cash Out.
- Architecture: same Phase 7.1 `cash_movements` ledger + `sessions` till — **no second ledger / no cached balance**. Movement type stays `CASH_IN` | `CASH_OUT`.
- Controlled reasons (server-validated; empty/invalid rejected):
  - CASH_IN: FLOAT_ADJUSTMENT, CASH_TRANSFER_IN, MANAGEMENT_CASH_IN, OTHER
  - CASH_OUT: PETTY_CASH, CASH_TRANSFER_OUT, MANAGEMENT_CASH_OUT, EXPENSE, OTHER
  - OTHER requires meaningful non-whitespace description (min length; not `...` / `--` placeholders). Optional description otherwise.
  - Reason labels are **operational only** — EXPENSE is not GL expense / supplier payment / accounting treatment.
- Amount: > 0, max 2 decimal places; zero / negative / malformed rejected (`psValidateCashMovementMoney`).
- References: server `CIN-######` / `COUT-######` via `cash_movement_seq`; posted refs ignored; UNIQUE + submit-token idempotency.
- Attribution: session `logger` only; ignore posted user / cashier / created_by / branch / till / session / balance / expected.
- Ownership: caller's own OPEN till matching working branch; ACTIVE register required; reject foreign / closed / inactive / other-user till.
- Balance effect: IN increases expected; OUT decreases via existing `psCashMovementBalance` formula (unchanged). **CASH_OUT is not auto-blocked when expected would go negative** (`psCashMovementBlockNegativeExpectedOnCashOutEnabled() === false`) — no min-cash policy invented.
- Atomic: BEGIN → lock till → re-read OPEN/own/active → allocate ref → insert movement → `cash_movement_audit` CREATE → COMMIT; any fail → ROLLBACK.
- Append-only: no edit/delete helpers; corrections = future reversal module (not 7.3).
- Reconciliation: new IN/OUT affect next expected immediately; existing FINAL `cash_reconciliations` rows immutable and unchanged; later activity after recon OK while till open; does not block IN/OUT merely because recon exists; no auto recon / balancing movements.
- History: datetime, type, amount, direction, ref, reason, description, user, session — non-admin working-branch / session scoped; admin branch filter (purchasing-reports pattern). Optional RO expected cash on UI.
- Auth boundary: existing roles only (logger + own till; admin history). No invented approval workflow for EXPENSE / transfers.
- Customer-account / legacy: Phase 6.3 payments still excluded; `submit_sales.php` / `ajax_pos2.php` / mobile `sync_sales.php` remain unsupported for cash ledger (unchanged).
- Out of scope for 7.3: till closure, handover, auto session close, deposits, bank recon, GL, VAT, expense/supplier accounting, shortage/surplus financial adjustments, auto balancing, reversal workflow, automated approvals, notifications, mobile cash protocol, historical migration, app-wide CSRF / permission frameworks.

## Till closure (Phase 7.4)

- Authoritative helpers: `includes/till_closure.php`. UI: `cash_till_close.php` via `ps_layout`. POS Close Till links here (unprotected `tillClose` / `close_session.php` no longer close without recon).
- Architecture: reuse `sessions` as the only till-session table. Status remains **OPEN | CLOSED**. “ACTIVE” = OPEN operational; “RECONCILED” = current FINAL `cash_reconciliations` row (not a sessions.status). No second till-session / closures ledger table when sessions snapshot fields suffice.
- Close prerequisites (atomic, server): auth → resolve own till (ignore posted session/branch/cashier) → FOR UPDATE → still OPEN → recalculate `psCashMovementBalance` → latest FINAL recon same session → current (expected == ledger) → notes for SHORTAGE/SURPLUS → write snapshot on sessions + CLOSED + `till_closure_audit` → COMMIT. Fail → rollback, stay OPEN. Does **not** insert/update/delete `cash_movements` or alter recon rows. Does **not** auto-create recon.
- Fresh recon: Phase 7.4 allows additional FINAL snapshots while OPEN when prior expected is stale; historical FINAL rows remain immutable; close uses latest.
- Non-zero variance: BALANCED / SHORTAGE / SURPLUS close allowed if recon current; notes required for non-zero. No auto balancing / GL.
- After CLOSED: reject POS sale, CASH_IN/OUT, new recon, operational open-till CASH_REFUND. No edit/delete closure. No reopen. History branch-scoped for authorised users.
- Handover: **not implemented** — close then new open for next cashier. Do not rewrite historical `created_by`.
- Auth: session `logger` + own OPEN till + working branch. Submit-token + explicit confirmation. Not app-wide CSRF. Browser testing not performed.
- Out of scope for 7.4: mid-session handover, deposits, bank recon, GL/shortage accounting, VAT, supplier payments, account-payment cash into ledger, notifications, mobile close, historical migration, app-wide CSRF/permission frameworks. (Management cash dashboard = Phase 7.5.)

## Cash management dashboard & reporting (Phase 7.5)

- Authoritative helpers: `includes/cash_reporting.php`. UI: `cash_management.php` (dashboard + cashier/till/branch/history tabs), `cash_movements_report.php`, `cash_reconciliation_report.php`, `cash_closure_report.php`, `cash_session_view.php`, `cash_audit_history.php` via `ps_layout`.
- Architecture: derived read-only views over existing Phase 7.1–7.4 tables. **No second cash ledger. No cached cash balances.** Expected cash reuses `psCashMovementBalance` only (Opening + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT).
- Current vs historical: OPEN tills → live ledger; CLOSED → closure snapshot on `sessions` for closure figures; ledger remains available for audit/drill-down.
- Cash sales / refunds on reports and cards: from `cash_movements` `CASH_SALE` / `CASH_REFUND` only (not `sale_payments` double-count; not Phase 6.3 account payments; not CARD/ECOCASH/BANK_TRANSFER/CREDIT/OTHER).
- Variance: BALANCED (var=0) / SHORTAGE (<0) / SURPLUS (>0) from actual recon/closure variance. Do **not** call balanced merely because reconciled. Shortage and surplus counts/amounts are never netted.
- Stale: latest FINAL recon.expected ≠ live ledger expected; dashboard shows prominent warning (cashier, till, last/current expected, difference, recon date) with link to recon UI.
- Filters: server-side branch / till/register / cashier / status / date from–to. End date includes full local day. Timezone Africa/Harare (not silent UTC). Ignore posted expected/totals/foreign branch/session ownership.
- Branch security: ordinary users forced to working branch; admin may filter all branches (purchasing-reports pattern). CSV export respects the same rules — no unfiltered company dump for ordinary users.
- Reports are paginated with aggregation SQL + batch balance/recon helpers (avoid N+1). Reporting never mutates financial data (`psCashReportingMutationsEnabled() === false`).
- Audit visibility: read-only lists from `cash_movement_audit` / `cash_reconciliation_audit` / `till_closure_audit` for authorised users; no edit/delete.
- Auth: existing session `logger` + admin level for all-branch; no new permission framework; not app-wide CSRF. Browser testing not performed.
- Out of scope for 7.5: GL, bank deposits/recon, VAT, supplier payments, account-payment cash into ledger, auto balancing, shortage/surplus financial adjustments, cash forecasting, notifications SMS/email/WhatsApp, mobile dashboard/reporting, advanced BI, historical cash migration, till reopen, redesign of ledger/recon/IN-OUT/closure.

## Cash management finalisation & closure (Phase 7.6)

- Purpose: integrate and formally close Phase 7 (7.1–7.5) as one cash-control architecture — no second ledger, no cached balances, no Phase 8 work.
- Authoritative objects unchanged: `sessions`, `cash_movements`, `cash_reconciliations`, `cash_movement_audit`, `cash_reconciliation_audit`, `till_closure_audit`; helpers `till_session.php`, `cash_movement.php`, `cash_reconciliation.php`, `till_closure.php`, `cash_reporting.php`.
- Cash formula: exactly one definition — Expected = OPENING_FLOAT + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT via `psCashMovementComputeBalanceFromParts` / `psCashMovementBalance` (dashboard/reports/recon/closure reuse it).
- Payment boundary unchanged: CASH → ledger; CARD/ECOCASH/BANK_TRANSFER/CREDIT/OTHER → no expected cash; Phase 6.3 account payments outside ledger.
- Lifecycle: OPEN → operational → recon → CLOSED (`sessions.status` OPEN|CLOSED only). Recon required and current before close; irreversible; no normal reopen; handover = close then new open.
- Security: auth + working branch + own open till; posted foreign branch/session rejected; ordinary vs admin reporting branch isolation; submit-tokens on IN/OUT/recon/close (not app-wide CSRF).
- Legacy: `submit_sales.php` / `ajax_pos2.php` / `mobile-endpoints/mobile_api/sync_sales.php` remain non-modern cash-ledger writers (unsupported for production cash).
- Production readiness: **READY WITH DOCUMENTED LIMITATIONS** (legacy outside ledger; no browser testing; no mid-session handover; no app-wide CSRF; no deposits/bank recon/GL/shortage accounting).
- **Phase 7 COMPLETE** (2026-10-08).

## Refund foundation (Phase 8.1)

- Authoritative helpers: `includes/sale_refund.php`. UI: `refund.php` via `ps_layout`.
- Identity: refund an existing sale by server `online_sales.id` (line) and/or `invoice_number` (receipt). Never by product name alone. Posted branch / user / amount / total / method / stock qty / till session **ignored**.
- Stock: `stock_movements` type `REFUND` (in / positive qty). Distinct from purchase `PURCHASE_RETURN`. Never write `goods.quantity`. Branch = original sale line branch.
- Cash: original tender `sale_payments.method = CASH` → `CASH_REFUND` on cashier’s **OPEN** till matching sale branch, same DB txn. CLOSED / no open till → cash refund rejected. Non-cash and missing `sale_payments` → **no** `CASH_REFUND`.
- CREDIT account ledger: **unchanged** (`psRefundCustomerAccountLedgerEnabled() === false`).
- External payment reversals: **not invented**.
- Refund reference: `REF-######` via `sale_refund_seq`. Stock `reference_id` = refund number.
- Reasons (controlled): CUSTOMER_RETURN, DAMAGED_ITEM, WRONG_ITEM, DUPLICATE_SALE, PRICING_ERROR, OTHER (+ meaningful description for OTHER).
- Dup protection: txn + row locks + cash UNIQUE + submit-token + unique refund number.
- Legacy: `submit_sales.php` / `ajax_pos2.php` / mobile do not implement controlled refunds.
- **Phase 8.1 COMPLETE** (2026-10-08).

## Refund authorisation (Phase 8.2)

- Workflow states on dedicated `sale_refund_requests` (not mutable audit): **REQUESTED → APPROVED → PROCESSED** or **REQUESTED → REJECTED**. Rejected never processable; rows retained for audit trail (no delete).
- Helpers: `psRequestRefund` (allocate REF + REQUEST audit), `psApproveRefundRequest`, `psRejectRefundRequest`, `psProcessApprovedRefund`. `psProcessRefund` requires approved `request_id` (immediate unauthorised commit rejected).
- Permission model (reuse existing roles — **no** parallel permission tables):
  - **REFUND_CREATE** ≡ session `logger` (`psRefundMayCreate`).
  - **REFUND_APPROVE** ≡ `logger` + `level=admin` (`psRefundMayApprove`).
  - **REFUND_PROCESS** ≡ same `logger` gate as CREATE (`psRefundMayProcess`).
- Monetary thresholds / multi-level approval / escalation: **not implemented** (`psRefundMonetaryThresholdEnabled() === false`).
- Branch: always from original sale. Create requires working branch = sale branch. Approver/processor must pass `psProductBranchIsAuthorised`.
- Reason + OTHER description immutable after approval.
- Approval integrity: lock request row; reject wrong/missing ID, already processed/rejected, double approve, process of pending/rejected.
- SoD: self-approval rejected when another admin user exists; if requester is the only admin, self-approval allowed (deadlock avoidance limitation).
- Audit: append-only `sale_refund_audit` events REQUEST / APPROVAL / REJECTION / PROCESSING. UNIQUE(`refund_number`,`event_type`).
- UI: Request / Pending / Approve / Reject / Process / Processed RO / Rejected RO; minimal history (not 8.6 reporting).
- Out of scope for 8.2: voids (**8.4**), recon (**8.5**), reporting (**8.6**), GL/VAT, CREDIT ledger invents, external payment reversals, app-wide CSRF.
- **Phase 8.2 COMPLETE** (2026-10-08). Phase 8 overall not closed.

## Line-level / partial refund integrity (Phase 8.3)

- Quantity authority: single helper `psGetRefundableSaleLineQuantity` — `original_qty` from sale line; `previously_refunded` = SUM(`sale_refund_request_lines.requested_qty`) for that exact `sale_line_id` where request `status=PROCESSED`; `remaining` = original − previously_refunded. Never trust browser remaining.
- `psRefundPartialQuantityEnabled() === true`. Requested qty must be > 0 and ≤ remaining. Multiple partials until remaining 0.
- Reservation: REQUESTED / APPROVED do **not** consume remaining (no complex hold). Only PROCESSED counts. Locks at process prevent double consume.
- Schema: `sale_refund_request_lines` (request_id, sale_line_id, product_id, requested_qty, unit_price/line_amount snapshots for display). Sale lines remain authoritative.
- Multi-line: one request → many lines; one `REF-######`; process validates all lines, calculates all amounts server-side, processes ALL atomically (one fail = whole fail).
- Amount: `refund_line = unit_price × qty` (cents helpers); total = SUM. Ignore posted totals/prices/discounts/tax. Display snapshot OK; at process reload sale lines — if amount ≠ approved `requested_amount` → reject. Fallback full-line `revenue` only when qty equals sold (legacy missing unit_price).
- Approval locks quantities/lines (no mutation after approval; change = reject + new request). Approve revalidates qty ≤ remaining and amount consistency.
- Process: lock request + lines → recalc processed remaining → if approved qty no longer fully available → **FAIL** (do not auto-shrink). Example: approved 7, another processes 5, remaining 5 → process of 7 fails with no stock/cash.
- `status=refunded` compatibility: set on `online_sales` only when processed ≥ original (fully refunded). Partial must not look fully refunded. Header `online_sales_ref` marked refunded only when every line remaining is 0. Cumulative request-lines authoritative. Pre-8.3 whole-line refunds without request-lines: `status=refunded` treated as fully refunded (may be unmappable to line qty history — documented).
- Stock: each processed line → `stock_movements` REFUND (+qty, product, sale branch, `reference_id`=REF). Cumulative stock = cumulative processed qty. Never `goods.quantity`.
- Cash: one `CASH_REFUND` per processed request = server total; ref `REFUND` + REF-###### (allows multiple partials); OPEN till required. Multiple partials = multiple CASH_REFUND movements. Non-cash → none. CREDIT limitation preserved.
- Audit PROCESSING reconstructs: REF, invoice, sale line, product, qty, unit price, line amount, total, actors, reason, time, stock ids, cash id if any.
- UI (`refund.php`): search → original / already refunded / remaining / unit price / request qty / display line amount; approval/process show locked lines + remaining; display total non-authoritative.
- Out of scope for 8.3: voids (**8.4**), recon (**8.5**), reporting (**8.6**), thresholds, GL/VAT, CREDIT ledger invents, external reversals.
- **Phase 8.3 COMPLETE** (2026-10-08). Phase 8 overall not closed (8.4–8.6 deferred at that time; 8.4–8.5 later completed).

## Voids / sale cancellation (Phase 8.4)

- Authoritative helpers: `includes/sale_cancel.php`. UI: POS Clear cart (`ajax_pos.php` → `ajax_pos_discard_cart.php`) + cancel panel on `refund.php` (same operational surface as refunds — not a separate redesign).
- **Sale lifecycle (inspected):** Production path `ajax_pos.php` → `submit_sale2.php` is atomic: cart is browser-only until commit; on success header is marked `COMPLETED` with `sale_payments` + stock `SALE` (+ `CASH_SALE` / `CREDIT_SALE` as applicable) in one txn. There is **no** durable unpaid/pending production draft. Legacy `online_sales_ref` rows may have empty `status` and `order_state=processing` without modern payments/stock.
- **Discard (uncommitted):** Clear cart discards browser cart only; server audits `DISCARD_CART` with controlled reason; no sale/stock/cash/AR rows created or reversed. Posted branch ignored (session working branch).
- **Cancel uncompleted (persisted):** Allowed only when classify finds **zero** `sale_payments`, stock `SALE`, cash `CASH_SALE`, AR `CREDIT_SALE`, and refund requests for that invoice, and status is not `COMPLETED` / `refunded` / `CANCELLED`. Marks header `CANCELLED` (lines retained, optionally line status `cancelled`); **no** stock/cash/AR movements. Duplicate cancel rejected. Working branch must equal sale branch; branch access required.
- **Committed / paid / ledger-affected:** Direct void **rejected** (`psSaleCancelDirectVoidCommittedEnabled() === false`). User directed to Phase 8.1–8.3 refund request → approve → process. Never delete history; never flip status to void without reversing ledgers; never invent VOID stock/cash types.
- Also rejected: already cancelled; fully/partially refunded; pending REQUESTED/APPROVED refund; unauthorised / wrong branch; missing invoice.
- Closed till: uncompleted cancel does not require open till; completed void still rejected (refund path). Completed cash reconciliations and closed tills are not modified/reopened.
- Reasons (controlled): WRONG_ITEMS, CUSTOMER_LEFT, PAYMENT_ABORTED, DUPLICATE_ENTRY, TEST_TRAINING, OTHER (+ meaningful description for OTHER). Submit-token on UI posts.
- Audit: append-only `sale_cancel_audit` (action, invoice, branch, previous/resulting status, reason, user, datetime, optional related refund ref). No silent delete/overwrite.
- Legacy `submit_sales.php` / `ajax_pos2.php` / mobile: not given a parallel void path (unsupported for modern cancel; committed still must use refund UI).
- Out of scope for 8.4: recon (**8.5**), reporting (**8.6**), GL/VAT, CREDIT ledger invents, external payment reversals, app-wide CSRF, browser testing claim.
- **Phase 8.4 COMPLETE** (2026-10-09). Phase 8 overall not closed (8.5–8.6 deferred at that time).

## Refund / cash / stock reconciliation (Phase 8.5)

- Authoritative helpers: `includes/sale_refund_reconciliation.php`. UI: `refund_reconciliation.php` (nav under Refunds) via `ps_layout`.
- **Read-only detection** of inconsistencies between PROCESSED refunds, original sales/lines, stock `REFUND`, `CASH_REFUND`, refund audit, and safe cancellations. `psSaleRefundReconMutationsEnabled() === false` for ledger/sale writes.
- Categories: REQUEST_SALE, REQUEST_LINES, QTY_OVER, AMOUNT, STOCK, CASH, AUDIT, CANCEL, LEGACY, UNMATCHED.
- Qty: only `status=PROCESSED` request-lines count (same as `psGetRefundableSaleLineQuantity`). APPROVED/REQUESTED do **not** expect stock/cash and are not flagged as missing ledger.
- Expected stock: per processed request line → `stock_movements` type `REFUND`, `reference_type=REFUND`, `reference_id=REF-######`, product+branch+qty (greedy one-to-one match; leftover = UNMATCHED/duplicate).
- Expected cash: original tender CASH → one `CASH_REFUND` amount = processed_amount (`REFUND`+REF-######). CARD/ECOCASH/BANK_TRANSFER/OTHER/CREDIT → **no** CASH_REFUND expected (UNEXPECTED_CASH if present). Phase 7 formula unchanged.
- Amounts: recalculate `unit_price × qty` vs snapshots / requested_amount / processed_amount.
- Legacy: `online_sales.status=refunded` without PROCESSED request-lines → severity INCONCLUSIVE (do not assume valid or invent links).
- Cancel: `CANCELLED` headers checked for zero payment/stock/cash/AR/refund footprint + `sale_cancel_audit` CANCEL_UNCOMPLETED presence.
- Filters: date range, branch (session-secured via `psPurchasingResolveReportBranch` — never trust posted foreign branch), category, discrepancy/review status. Pagination (no full history in memory).
- Auth: session `logger` to view; `level=admin` to set review status (same gate as refund approve).
- Review: optional `sale_refund_recon_reviews` keyed by stable `discrepancy_key` (UNIQUE — re-run safe) + append-only `sale_refund_recon_review_audit` (reviewer, time, reason, action). Original finding remains separate from resolution. Never silently mark resolved.
- **Does not:** auto-correct stock/cash/customer; invent CREDIT_NOTE/AR reversals; reopen tills; alter completed cash recons; rewrite historical refunds; use `goods.quantity` as stock; invent new movement types.
- CREDIT AR non-reversal on refund remains a **known limitation** (documented; not flagged as cash/stock miss).
- Out of scope for 8.5: **8.6** refund reporting (delivered separately), GL/VAT, monetary thresholds, external payment reversals, app-wide CSRF, browser testing claim.
- **Phase 8.5 COMPLETE** (2026-10-09). Phase 8 overall not closed (8.6 deferred at that time).

## Refund reconciliation reporting (Phase 8.6)

- Authoritative helpers: `includes/sale_refund_recon_reporting.php` (reuses `psSaleRefundReconciliationRun` / review loaders — **no** second recon algorithm). UI: `refund_recon_report.php`, `refund_recon_finding.php` via `ps_layout` (nav under Refunds).
- **Summary dashboard:** filtered counts — total findings; by category; by severity (DISCREPANCY vs INCONCLUSIVE); awaiting review / reviewed / inconclusive-ack; by branch; by period. Monetary loss totals are **not** invented; inconclusive is not labelled as financial loss.
- **Findings table:** filters date range, authorised branches only, category, review status, severity, sale/refund reference. Columns include discrepancy key, branch, sale/refund refs, category, description, expected/actual qty/amount when meaningful (else “Not available” / “Inconclusive”), recon status, review status, last review. Pagination; SQL filters on PROCESSED source queries; post-filter for LEGACY/CANCEL/UNMATCHED.
- **Finding detail:** authorised sale, refund request/lines, stock REFUND, CASH_REFUND, refund audit as applicable; plain-language explanation; expected vs observed; no other-branch data.
- **Review history:** read-only from `sale_refund_recon_reviews` + `sale_refund_recon_review_audit` (status changes, reviewer, timestamp, comments). Opening/filtering/exporting the report does **not** create findings or review rows (explicit 8.5 review action remains separate).
- **Export:** CSV (Excel-compatible) respecting filters + branch scope; safe filename; empty results clear; capped (no unrestricted dump).
- Auth: session `logger` to view; branch via `psPurchasingResolveReportBranch`; posted foreign branch rejected for non-admin. Review recording remains admin (8.5). No new permission framework; app-wide CSRF not claimed.
- Preserves 8.5 rules: PROCESSED only; APPROVED/REQUESTED not completed; over-refund = processed qty vs sold; amounts `unit_price×qty`; stock/cash expected rules unchanged; legacy without lines = INCONCLUSIVE; unknown payment + cash inconclusive where required; no CREDIT/AR invent; Phase 7 cash attribution limits documented on report.
- Out of scope for 8.6: auto-resolve, bulk review-status changes, new recon algorithm, GL/VAT, monetary thresholds, external reversals, rewriting history.
- **Phase 8.6 COMPLETE** (2026-10-09).

## Phase 8 formal closure (2026-10-09)

- **Verdict:** CLOSED WITH DOCUMENTED LIMITATIONS (final closure audit of 8.1–8.6).
- Documented limitations that do **not** block closure: CREDIT/AR no credit-note reversal; legacy `status=refunded` without request-lines may be unmappable; Phase 7 cash attribution limits; legacy/non-ledger sale paths do not implement controlled refunds; submit-token on refund/cancel (not app-wide CSRF; recon review POST has no submit-token); browser testing not performed; recon findings live-computed (no findings ledger).
- Deferred enhancements (separately approved): monetary thresholds / multi-level approval; CREDIT notes; external CARD/ECOCASH reversals; findings ledger; app-wide CSRF; browser E2E.

## Multi-branch control (Phase 9.1 audit + Phase 9.2–9.6 hardening — 2026-10-09)

- Authoritative registry: `branchies.branch_name` (exact string match after trim; no case-folding of names — PHP exact check after SQL lookup).
- **Home / working / authorised (Phase 9.2):**
  - Home = login `$_SESSION['branch']` (`users.branch`); never trusted from POST role/user id/home.
  - Working = authorised `selected_branch` if set; else home when usable; unauthorised selected fails closed.
  - Ordinary authorised = home only (not merely `selected_branch ∈ branchies`). Admin (`level` case-insensitive `admin`) may select any registered `branchies` name; switch does not grant unrelated elevated perms.
- Switch endpoint `set_branch.php`: auth required, POST only, registered + authorised validation, safe redirects only; open till on another/NULL branch blocks switch (till rows not moved/closed/reassigned; NULL branch not auto-assigned).
- `admin_dashboard.php`: authenticated admin required before branch list/controls; non-admin redirected; ordinary nav hides Switch branch.
- Shared resolvers updated: `psResolveProductWriteBranch`, `psRequireSessionProductBranch`, `psAuthorisedProductBranches`, `psPurchasingResolveReportBranch`, `psSessionStockBranches`, `tillSelectedBranch` / till open+sale gates via `includes/branch_access.php`.
- Shared (intentionally no branch): `supplier_master`, `customer_master`, `customer_accounts` / AR ledger (sale branch on transactions).
- CSRF: session auth + POST + authorisation only; **app-wide CSRF not claimed**.
- **NULL-branch OPEN tills:** block branch switch and reject operational till use; do not auto-assign branch. Phase 9.5 left both legacy OPEN NULL sessions unchanged — closure/assignment requires authorised business decision (deferred).
- **Phase 9.3 (Legacy / unauthenticated endpoint gates — 2026-10-09):**
  - `users.php` / `update_branch.php`: authenticated **admin** only; branch assignment must be ∈ `branchies`; posted role/level/admin flags ignored; ordinary cannot change own/others’ home branch.
  - `income_statement.php`: auth required; `online_sales` scoped to authorised branches (ordinary = working/home; admin = all registered authorised). **Expenses** on this statement remain company-global (`expenses` has no branch column — documented; not invented).
  - `expenses.php`: auth required; list is company-global (schema has no branch) — not a silent cross-branch bypass of sale data.
  - `InvestmentReport.php`: auth required; rows/summaries filtered by `goods.branch` ∈ authorised set after existing name join; GRV/Z name-only join limitation preserved (no historical rewrite).
  - `my_receipt.php`: auth required (not public/token); sale header branch must be authorised; blank/orphan header branch = admin only; unauthorised invoice → generic not-found.
  - `submit_sales.php`: **write path retired** (403 JSON even when authenticated). Callers still present in legacy UIs (`pos_dashboard.php`, `pos_desktopview.php`, `index.php`) but production POS is `ajax_pos.php` → `submit_sale2.php`. No second sale engine; no weakening of `submit_sale2.php`.
  - App-wide CSRF not claimed. Browser testing not performed.
- **Phase 9.4 (Mobile / API branch authorisation — 2026-10-09):**
  - Auth: Bearer / `X-Auth-Token` / body-or-query `token` → `mobile_tokens` row; every protected request re-loads live `users` by token cashier email. Branch = home `users.branch` when ∈ `branchies`. **Admin does not unlock multi-branch mobile.** Client-supplied branch = consistency check only.
  - `login.php`: returns `user.branch` + `user.branches` = authorised home only (not full `branchies`). Client that previously assumed a full branch picker must use the returned authorised branch only — server does not weaken to preserve that insecure assumption.
  - `products.php`: catalogue for authorised branch only; inactive excluded; posted/queried branch cannot override auth.
  - `sync_sales.php` (Phase 9.6): **fail-closed** for NEW sale posts — does not allocate invoice / insert incomplete sale rows without stock+payments+cash. Idempotent replay of already-mapped `client_uuid` still returns success. Production live sales path remains `submit_sale2.php`. Aligning mobile to full shared posting deferred (would invent payment/till protocol details).
  - `session_sync.php`: open sets `branch` + cashier from auth (never unscoped); cannot open/claim/close foreign tills; close does not auto-assign NULL-branch historical sessions.
  - Shared helper: `mobile-endpoints/mobile_api/mobile_branch_auth.php` (distinct from web session `branch_access.php`). App-wide CSRF not claimed. Browser/mobile client UI testing not performed.
- **Phase 9.5 (Historical orphan policy & soft integrity — 2026-10-09):**
  - Classification: **VALID_CURRENT** (in `branchies`), **HISTORICAL** (non-blank, not in today’s registry — may be historically valid), **MISSING** (NULL/blank), **PROVABLE_INCONSISTENCY** (linked evidence establishes intended registered branch), **UNRESOLVED_CONFLICT** (disagree / cannot establish).
  - Policy: preserve HISTORICAL/MISSING/UNRESOLVED; never infer branch from product name or caller’s current branch; never delete historical rows to pass integrity; NEW writes require registered names via `psBranchRequireRegisteredWrite` (till open, user home assign, and existing 9.2–9.4 gates).
  - Diagnostics: admin `branch_integrity_report.php` + `includes/branch_integrity.php` (read-only snapshot). Soft non-unique index `idx_branchies_branch_name` only — **no FK/UNIQUE** on `branch_name`.
  - Invoice sequence: left at **140**; numbers 132–139 have no sale rows (9.4 probe allocate+cleanup); broader gap 108–139 vs max committed invoice 107. **Do not reset sequence.**
  - Remediations performed in 9.5: **none** (no unambiguous safe rewrites). Sale line↔header mismatches (11 distinct lines / 12 join rows; inflated by duplicate invoice_number headers) left UNRESOLVED. Users outside registry (18), HARARE/RUSAPE/MUTARE/CHINHOYI historical strings, incomplete transfer/stock-take associations left unchanged.
- **Phase 9.6 (Unified helpers, constraints & regression closure — 2026-10-09):**
  - Helpers: `psBranchRequireAuthorisedWorking`, `psBranchTillRowOperational`, `psBranchRequireAuthJson`; `psBranchResolveWorking` requires authorised **and** registered for all roles; `psSaveStockTransfer` requires sending ∈ `psSessionStockBranches`.
  - Legacy UI gates: transfer page, opening stock, stock movements list, GRV AJAX, all_receipts, z_report, inventory control, stock list, product history, stock take sheet, movement list — auth + authorised registered working/report scope.
  - Constraints: `idx_branchies_branch_name` verified; **UNIQUE(branch_name)** and **FKs deferred** (historical orphan/blank strings block safe FK; UNIQUE deferred while orphans remain).
  - Integrity remediations: **none** conclusive — mismatch review 0 PROVABLE / 11 UNRESOLVED; 18 out-of-registry users not mass-reassigned (ops fail closed; home remap = business decision); 2 NULL OPEN tills unchanged (cannot claim/switch into/silently reassign).
  - Mobile sync: fail-closed incomplete posting (see above).
  - Consolidated CLI regression **61 PASS / 0 FAIL**; fingerprints restored; `sale_invoice_seq` remains **140** (not reset).
- **Phase 9.1–9.6 COMPLETE** (2026-10-09). **Phase 9 — Multi-Branch Control: COMPLETE** (READY WITH DOCUMENTED LIMITATIONS).

## Reporting & Dashboard (Phase 10 — COMPLETE 2026-10-09)

- **Read-only:** `psReportingMutationsEnabled() === false`. Reports never alter sales, payments, stock, cash, refunds, tills, or invoice sequences.
- **Surfaces:** `management_dashboard.php` (metrics cards, alerts, recent sales, cash summary reuse); `sales_reports.php` (list / by branch / cashier / product / payments / refunds / cancelled / invoice drill-down); `inventory_reports.php` (on hand / low / out / movements); purchasing CSV on `purchasing_reports.php`; cash + refund recon reports reused from Phases 7–8.
- **Helpers:** `includes/reporting.php`, `dashboard_reporting.php`, `sales_reporting.php`, `inventory_reporting.php`; purchasing export `psPurchasingReportExportCsv`.
- **Completed financial sales:** `status` COMPLETED or refunded, **or** a `sale_payments` row exists; **CANCELLED excluded**. Legacy empty-status headers without payments are **recorded legacy** — shown separately; not mixed into modern completed cards.
- **Payment methods:** from `sale_payments` only (aggregated per invoice to avoid join multiplication). `online_sales_ref.method` is currency on the production path — not used as tender.
- **Product sales:** always retain `product_id` + `branch` (never merge identically named products across branches).
- **Stock:** ledger-controlled only when `OPENING_BALANCE` exists for `product_id`+`branch`; on-hand = `SUM(stock_movements)`. Non-ledger rows may show `goods.quantity` as **legacy display only**. Low/out uses authoritative `goods.reorder_level`. Valuation = ledger qty × `cost_price` only when cost > 0; otherwise labelled incomplete/omitted.
- **Refunds:** PROCESSED = completed financial; REQUESTED/APPROVED/REJECTED listed separately and not counted as completed refund activity.
- **Branch ACL:** ordinary = home/working only (posted foreign branch ignored); admin = registered `branchies` only. Orphan historical branches (HARARE/RUSAPE/blank) excluded from scoped totals. Invoice drill-down / CSV / AJAX use the same helpers.
- **Exports:** CSV with formula-injection mitigation (`psReportingCsvCell`); authorised data only; empty filters return empty/labelled empty rows.
- **Cash cards:** reuse Phase 7.5 helpers; NULL-branch OPEN tills → branch cash totals labelled unreliable.
- **Not claimed:** app-wide CSRF; browser testing; inventing missing invoices from sequence gaps; company-wide cash if branch attribution unreliable; treating legacy empty-status as modern COMPLETED.

## Accounting Integration (Phase 11 — COMPLETE 2026-10-09)

- **Architecture:** Accounting-ready export + reconciliation layer (`includes/accounting.php`). No full GL subsystem. No invented account codes, tax rates, or cost values. External platform posting is **not** claimed — batches keep `external_status=NOT_SENT` until a real integration confirms.
- **Mappings:** Configurable keys in `accounting_account_mappings` (admin only). Empty `account_code` blocks export of dependent sources. Seeded keys cover sales revenue, tenders (CASH/CARD/ECOCASH/BANK_TRANSFER/CREDIT/OTHER), refund revenue/tenders, GRV inventory/payable, purchase-return inventory/payable, expense/funding, manual cash-in/out, AR receipt + AR tenders.
- **Eligible sources:** Modern completed sales (Phase 10 rules); PROCESSED refunds only; GRV headers with recorded `totalz`; COMPLETED purchase returns; expenses (admin all-branches, labelled COMPANY_WIDE); manual CASH_IN/CASH_OUT only (not CASH_SALE/CASH_REFUND — those follow SALE/REFUND); POSTED customer account payments (admin all-branches, UNALLOCATED).
- **Not exported as revenue:** Legacy empty-status sales without payments (exceptions only). REQUESTED/APPROVED/REJECTED refunds. Inventory ADJUSTMENT movements (no cost on `stock_movements`; sell price/name never used).
- **Tax (Phase 12):** COMPLETE `tax_data_status` sales split `revenue.sales` (excl tax) + `tax.output` when mapped; LEGACY_INCOMPLETE / UNCONFIGURED export gross to `revenue.sales` (no fabricated tax). PROCESSED refunds with `processed_tax_amount` reverse tax via `tax.output` debit. Missing `tax.output` mapping blocks COMPLETE tax-split export (`TAX_MAPPING_REQUIRED`).
- **Journal:** Each source builds balanced debit/credit pairs; export requires balance + required mappings. Decimal-safe money via existing helpers.
- **Batches:** Preview read-only; export writes `accounting_export_*` only inside a DB transaction; partial failure rolls back (not marked EXPORTED). Idempotency via `accounting_exported_sources` UNIQUE(source_type, source_id). Correction = admin safe reversal (opposite entries; unlock sources); no silent edit/delete of exported journals. `sale_invoice_seq` untouched.
- **UI:** `accounting.php` hub; mappings; preview/export; batch history/drill-down/CSV; recon. Phase 9 ACL: auth required; ordinary home only; admin registered/all; company-wide labelled. CSV formula escape via `psReportingCsvCell`.
- **Business decisions required:** Fill account codes to match the organisation’s chart of accounts; choose payable/funding/contra accounts; whether to include expenses/AR under all-branches; when to reverse batches.
- **Not claimed:** App-wide CSRF; browser testing; external accounting acceptance; inventing branch for expenses/AR/NULL tills.

## VAT / Tax (Phase 12 — COMPLETE 2026-10-09)

- **Configuration:** `tax_rates` (code, description, classification TAXABLE / ZERO_RATED / EXEMPT, rate %, pricing mode INCLUSIVE / EXCLUSIVE, effective_from / effective_to, active). Admin-only create/update/activate/deactivate + `tax_rate_audit`. Invalid config rejected (TAXABLE rate must be > 0; ZERO_RATED/EXEMPT must be 0; overlapping effective dates rejected). **No statutory rate is hardcoded** — rates are business-configured; legal confirmation required before production.
- **Enforcement:** `tax_settings.enforcement` = OFF (default) or REQUIRED. OFF: products without `goods.tax_code` sell as `UNCONFIGURED` (payable = price×qty; tax amounts NULL — not invented zero). REQUIRED: sale rejected without effective tax code for txn date.
- **Product assignment:** explicit `goods.tax_code` (nullable). Legacy `VAT_STATE` (`vatable` / blank) is **not** migrated or treated as rate evidence.
- **Sale calc order:** discounts (reserved; cart UI has none today) → taxable base → half-up cents rounding. Server-only via `psBuildSaleLines` / `psTaxApplyToSaleLines` — browser tax ignored. Inclusive: tax extracted from gross; exclusive: tax added to net. Payable line `revenue` / header `totalz` = customer total (incl tax).
- **Persistence:** line + header store `tax_code`, classification, rate %, pricing mode, discount, taxable_amount, tax_amount, line_total_excl/incl, `tax_data_status` (COMPLETE / UNCONFIGURED / LEGACY_INCOMPLETE / MIXED). Historical rows default LEGACY_INCOMPLETE; pre-Phase-12 `vat`/`vat_state`/`price_ex` are not trusted.
- **Refunds:** COMPLETE lines use stored incl/tax proportions for payable and tax snapshots; cumulative PROCESSED tax ≤ original line tax; never today’s rate. Legacy missing tax → no invented tax amounts. PENDING/APPROVED/REJECTED distinct from PROCESSED.
- **Receipts:** `my_receipt.php` + print queue show stored tax code/class/rate/amounts when COMPLETE; legacy labelled. Invoice numbers unchanged.
- **Reports:** `tax_reports.php` — taxable by code/rate; zero-rated and exempt separate; PROCESSED refund tax adjustments; net tax; branch+date ACL; CSV formula escape; drill-down. Separates fully-supported vs legacy incomplete vs unconfigured. **Not a statutory return.**
- **Accounting:** mapping keys `tax.output` (+ `refund.tax` seeded); COMPLETE splits; batches remain `external_status=NOT_SENT`.
- **Required confirmations before production:** legal VAT rates/classifications for the operating jurisdiction; inclusive vs exclusive shelf pricing policy; product tax-code assignments; set enforcement REQUIRED when ready; map `tax.output` (and tenders/revenue) to the chart of accounts; accountant acceptance of export format.
- **Not claimed:** statutory tax return filing; legal compliance verification; external GL acceptance; browser testing; app-wide CSRF; inventing historical tax; purchasing/expense VAT redesign.

## Barcode & Hardware (Phase 13 — COMPLETE 2026-10-09)

- **Data model:** nullable `goods.barcode` (VARCHAR(64)); empty → NULL. Identity remains `product_id` + `branch` (names may duplicate). UNIQUE `uniq_goods_branch_barcode` (`branch`,`barcode`) when data permits (multiple NULLs allowed). No barcodes invented for existing products.
- **Validation:** outer whitespace trimmed only; leading zeros and meaningful digits preserved; max 64 printable ASCII (`\x21-\x7E`); numeric PHP types rejected (spreadsheet leading-zero risk); duplicate barcode on same branch rejected (app + unique index).
- **Assignment:** create (`create_items.php`), edit (`items_list.php`), optional import column `Barcode`; audited via `product_audit` field `barcode`. Products without barcodes keep name/`product_id` search.
- **POS scanning:** keyboard-wedge / HID scanners into dedicated scan field on `ajax_pos.php` → `ajax_pos_barcode.php` exact match on authorised working branch → add via existing cart qty rules → sale still only via `submit_sale2`. Clear feedback for unknown / inactive / branch miss / stock limit. Enter on scan field does not submit payment. Keyboard/mouse/search remain.
- **Labels:** `barcode_labels.php` — authorised branch; selected products with barcodes; copies 1–50; Code 128 PNG via existing `picqer/php-barcode-generator` encoding the **exact stored value**; browser print. Does not auto-assign barcodes. No thermal printer API.
- **Receipts:** PDF via `my_receipt.php` unchanged; browser narrow (~80mm) print via `receipt_print.php` (same ACL; stored tax; no nav in print). Linked from receipts list.
- **Inventory lookups:** catalogue/items search includes barcode; GRV product suggestions include barcode; stock adjustment labels show barcode when present. Stock/ledger rules unchanged.
- **Hardware compatibility claims:** keyboard-wedge (USB/Bluetooth HID as keyboard) + OS browser print for labels/receipts are the designed model. **Not claimed / not tested on physical devices:** direct ESC/POS thermal commands, cash drawer kick, scale, customer display, device-specific SDKs. Vendor `mike42/escpos-php` remains in tree but is not a Phase 13 production integration.
- **Not claimed:** inventing barcodes; second sale path; bypassing stock validation; weakening Phase 9 ACL; physical peripheral certification.

## Security & Audit (Phase 14 — COMPLETE 2026-10-09)

- Passwords: hashed with `password_hash`; legacy plaintext accepted once then upgraded. Locked users (`lock_status=locked`) cannot authenticate (web/mobile).
- Sessions: regenerate on login/privilege sync; inactivity timeout; server-side revalidation; safe `logout.php`. Role/branch never trusted from POST.
- CSRF: inventoried state-changing web POSTs use session CSRF (`docs/POS_SECURITY.md`). Authz independent of CSRF. Mobile uses Bearer tokens (not web CSRF).
- Security audit table records privileged/security events without passwords/tokens. Domain audits from prior phases unchanged.
- Destructive product/sales truncate in settings requires admin + CSRF + confirmation phrase.
- **Not claimed:** fully secure / pen-test / tamper-proof audit storage.

## Backup, Recovery & Reliability (Phase 15 — COMPLETE 2026-10-09)

- Mass plaintext→hash migration completed and verified (plaintext remaining **0**); empty-password account reported fail-safe (no invented credential).
- Database backups via mysqldump to private storage outside `htdocs`; retention keeps SUCCESS dumps and never deletes the only known-good; credentials not placed on CLI argv/logs.
- Isolated restore test performed successfully; production not overwritten; invoice sequence not reset (`RESTORE_TEST_OK`; `sale_invoice_seq` **140**).
- Health endpoint reports non-sensitive connectivity/storage/backup-status flags.
- Admin backup console + CLI; host scheduler / off-site / encryption-at-rest are deployment actions (not claimed active).
- **Not claimed:** continuous replication, cloud DR, encryption-at-rest, activated host scheduler.

## Production Hardening (Phase 16 — COMPLETE 2026-10-09) — FINAL ROADMAP PHASE

- DB credentials load from env or private file outside webroot; example config uses placeholders only.
- Legacy unauthenticated mutation endpoints disabled (online_* / update_stock / printed / hardcoded-cred writers).
- Shared web session gate: timeout + optional live lock/privilege revalidation; login CSRF required; mobile Bearer CSRF still not imposed.
- Web deny rules for config/vendor/docs/.git/SQL/logs/CLI/tests (Apache `.htaccess`; equivalents documented).
- Deployment & ops guide: `docs/POS_DEPLOYMENT.md`. HTTPS / OS ACLs / scheduler / off-site encryption remain operator actions (**not claimed configured/applied** unless verified).
- **Not claimed:** live production cutover, pen-test, browser E2E, hardware certification. **No Phase 17.**

## Explicit non-goals / still deferred after Phase 4 / Phase 5 / Phase 6.1–6.5 / Phase 7 COMPLETE / Phase 8 COMPLETE / Phase 9 COMPLETE / Phase 10 COMPLETE / Phase 11 COMPLETE / Phase 12 COMPLETE / Phase 13 COMPLETE / Phase 14 COMPLETE / Phase 15 COMPLETE / Phase 16 COMPLETE

Supplier payments / AP statements / payable balance, live external GL API posting, procurement analytics dashboard, branch-supplier mapping, mass historical GRV→`supplier_master` migration, inventing supplier↔GRV mappings for incomplete history, updating `goods.cost_price` from receiving/returns, using `goods.quantity` as stock authority, automated collections/reminders / interest / write-offs / later AR packages, loyalty, making customer mandatory on sales, mass-migrating historical sales or free-text names to `customer_master` or auto-opening AR accounts, inventing mobile credit/customer/payment protocol or full mobile sale posting parity with `submit_sale2`, historical CREDIT_SALE or payment backfill, mid-session cashier handover / deposits / bank rec / variance financial adjustments, refund monetary thresholds / multi-level approval, CREDIT account credit-notes on refund, external CARD/ECOCASH reversals, direct ESC/POS / cash-drawer / scale / customer-display hardware drivers (Phase 13 supports keyboard-wedge + browser print only), approved historical remediations (NULL till close/assign, user home remaps for 18 out-of-registry users, sale line↔header mismatch rewrite, UNIQUE/FK on `branchies.branch_name` after cleanup), rewriting legacy sales to COMPLETED/payment rows for reporting, and later programme work beyond the closed roadmap phases above. Whole Phase 6 is not closed. Roadmap ends at Phase 16.
