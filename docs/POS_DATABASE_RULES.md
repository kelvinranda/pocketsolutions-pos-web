# POS Database Rules

Focused on product / branch / stock / audit structures established through Phase 3, plus Phase 4A–4D purchasing, Phase 5 customer master / sale identity / profile history (5.1–5.4 closed), Phase 6.1–6.5 customer accounts / AR / payments / statements / credit controls, and Phase 7 COMPLETE (7.1–7.6): cash till ledger (`cash_movements`), reconciliation (`cash_reconciliations`), controlled IN/OUT, till closure snapshot + `till_closure_audit`, reporting indexes, and Phase 7.6 finalisation (single balance formula helper; sessions money columns DECIMAL(14,2)). This is **not** a full schema dump.

## `goods` (product master)

### Identity & constraints

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate row id |
| UNIQUE `uniq_goods_product_branch` | `product_id`, `branch` | Authoritative identity |
| UNIQUE `uniq_goods_branch_barcode` | `branch`, `barcode` | Phase 13; multiple NULLs allowed |
| — | — | **No** UNIQUE on `product_id` alone |
| — | — | **No** UNIQUE on `product` (name) alone |

### Authoritative / active columns (Phase 3)

| Column | Observed type | Role |
|--------|---------------|------|
| `product_id` | varchar(250) NOT NULL | Shared code; unique per branch |
| `product` | varchar(250) NOT NULL | Display name (not identity) |
| `price` | varchar(250) NOT NULL | Selling price (branch-scoped) |
| `cost_price` | varchar(250) NOT NULL | Cost price |
| `category` | varchar(250) NOT NULL | Classification (independent) |
| `button_category` | varchar(250) NOT NULL | POS button group (independent) |
| `reorder_level` | decimal(12,3) NULL | Authoritative reorder level |
| `pack_size` | varchar(250) NOT NULL | Pack size |
| `branch` | varchar(250) NOT NULL | Branch ownership |
| `status` | varchar(20) NOT NULL DEFAULT 'ACTIVE' | ACTIVE / INACTIVE |
| `date_` | varchar(250) NOT NULL | Create/date field used by create path |
| `barcode` | varchar(64) NULL | Phase 13 optional barcode; empty stored as NULL; UNIQUE with branch |

### Legacy columns (retained; not removed)

| Column | Observed type | Role |
|--------|---------------|------|
| `quantity` | varchar(250) NOT NULL | Legacy on-row qty; **not** stock authority |
| `re_order_level` | varchar(250) NOT NULL DEFAULT `10` | Legacy reorder; **not** written by Phase 3 product-master paths |

Other historical UI/layout columns may exist on `goods` (e.g. button colour, legacy `VAT_STATE`). Phase 12 adds authoritative nullable `tax_code` (VARCHAR(32)) — explicit assignment to `tax_rates.code`. Legacy `VAT_STATE` is **not** used as tax evidence and is not bulk-migrated. Phase 13 adds nullable `barcode` + UNIQUE `uniq_goods_branch_barcode` (`branch`,`barcode`) when no duplicate non-null pairs exist (ensured by `psEnsureGoodsBarcodeSchema`).

## Phase 13 — Barcode

| Object | Role |
|--------|------|
| `goods.barcode` | Nullable VARCHAR(64); NULL = no barcode; leading zeros preserved as text |
| `uniq_goods_branch_barcode` | UNIQUE(`branch`,`barcode`) — multiple NULLs allowed; prevents accidental duplicate assignment per branch |
| Validation | `includes/barcode.php` — trim only; printable ASCII; max 64; reject PHP numeric types |
| Resolve | Exact match on authorised working branch; identity returned as `product_id` |
| Labels | Browser-printable Code 128 via existing `picqer/php-barcode-generator` (exact stored value) |
| No auto-assign | Existing products keep NULL barcode until explicitly set |

## Phase 12 — Tax tables / sale tax columns

| Table / column | Role |
|----------------|------|
| `tax_rates` | Configurable codes: classification TAXABLE/ZERO_RATED/EXEMPT, rate_percent, pricing_mode INCLUSIVE/EXCLUSIVE, effective_from/to, is_active. UNIQUE(code, effective_from). |
| `tax_rate_audit` | Append-only CREATE/UPDATE/ACTIVATE/DEACTIVATE/SETTING_UPDATE/PRODUCT_ASSIGN |
| `tax_settings` | Key/value; `enforcement` = OFF \| REQUIRED (default OFF) |
| `goods.tax_code` | Nullable product tax assignment (not inferred from VAT_STATE) |
| `online_sales` tax_* / taxable_amount / tax_amount / line_total_* / tax_data_status | Per-line stored tax for reproducibility; default LEGACY_INCOMPLETE for history |
| `online_sales_ref` taxable_amount / tax_amount / tax_pricing_mode / tax_data_status | Header totals when COMPLETE |
| `sale_refund_request_lines` tax_*_snapshot / tax_data_status | Stored original tax share for refunds |
| `sale_refund_requests.processed_tax_amount` | PROCESSED refund tax total (NULL when legacy) |

Historical `online_sales.vat` / `vat_state` / `price_ex` remain but are **not** treated as reliable Phase-12 tax detail.

### Application write rules

- Create/edit/import/status/pricing/category paths must not UPDATE `quantity` or `re_order_level`.
- Create INSERT column lists omit `quantity` and `re_order_level`; MySQL column defaults may still populate omitted columns on insert — that is schema default behaviour, not a ledger stock write.
- Status updates: `UPDATE goods SET status = ? WHERE id = ? AND branch = ?`.

## `branchies`

Authoritative branch registry.

| Column | Role |
|--------|------|
| `id` | Surrogate |
| `branch_name` | Canonical branch string stored on `goods.branch` and stock rows |
| `date_created` | Metadata |

Product branch validation checks existence in `branchies` (via stock/product helpers).

### Phase 9.1 integrity baseline (read-only, 2026-10-09)

- `branchies` rows: Village, Concession bar, Westlea sports bar (ids 5–7). No duplicate names; no leading/trailing whitespace; **no UNIQUE** on `branch_name`; **no FKs** referencing `branchies`.
- `goods` 221: all branches match `branchies`; blank branch 0; duplicate `(product_id,branch)` 0; `uniq_goods_product_branch` present; quantity sum 0; `re_order_level` sum 2864.
- `online_sales_ref` 107: Westlea 83, Village 6, blank 9, orphan strings HARARE 8 / RUSAPE 1; Concession bar sales 0. Line vs header branch mismatch count 12. `sale_invoice_seq.next_number` **132** (unchanged by audit).
- `sessions` 2 legacy OPEN rows with **NULL** `branch`; `registers` 3 (one ACTIVE Till 01 per current `branchies` name).
- Shared no-branch tables confirmed: `supplier_master`, `customer_master`, `customer_accounts`, `customer_account_ledger`, legacy `suppliers`/`customers`.
- Audit did **not** clean data, add constraints, or advance sequences.

### Phase 9.2 notes (2026-10-09)

- No schema changes. No historical rewrite. NULL-branch OPEN `sessions` left unchanged (switch blocked; no auto-assign).
- Application helpers read `branchies` for registration checks; ordinary authorisation is session home, not “any name present in `branchies`”.
- Integrity after 9.2 CLI suite + cleanup: `goods` 221, `stock_movements` 0, `sale_invoice_seq.next_number` **132**, `online_sales_ref` 107, `sessions` 2 (both OPEN NULL branch), `branchies` 3 names unchanged.

### Phase 9.3 notes (2026-10-09)

- No schema changes. No historical rewrite. No sequence reset/advance. NULL-branch OPEN `sessions` unchanged.
- `expenses` has **no branch column** — treated as company-global accounting under auth (not invented branch associations).
- `grv_report` / `z_reading` still lack product_id/branch; InvestmentReport continues name join, scoped only via joined `goods.branch`.
- `submit_sales.php` no longer INSERTs sales (write path retired); production writes remain `submit_sale2.php`.
- Integrity after 9.3 CLI suite + cleanup: `goods` 221, `stock_movements` 0, `sale_invoice_seq.next_number` **132**, `online_sales` 235, `online_sales_ref` 107, `sessions` 2 (both OPEN NULL branch), `branchies` 3 names unchanged, legacy `customers` 4.

### Phase 9.4 notes (2026-10-09)

- No schema changes to sales/goods/sessions. `mobile_tokens` / `mobile_sale_map` / `mobile_session_map` remain as before (created if missing by mobile bootstrap).
- Mobile DB config aligned to web POS database (`pocketso_android_pos`).
- Branch binding is application-layer (live `users.branch` + `branchies`); not a new FK.
- NULL-branch OPEN `sessions` left unchanged (not auto-assigned on mobile open/close).
- Integrity after 9.4 suite + cleanup: `goods` 221, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sessions` 2 (both OPEN NULL branch), `branchies` 3 names unchanged, `users` 23. `sale_invoice_seq.next_number` **140** (probe sync advanced; not reset).

### Phase 9.5 notes (2026-10-09)

- Soft integrity only: non-unique index `idx_branchies_branch_name` on `branchies(branch_name)` for registry lookups. **No UNIQUE / FK** on `branch_name`. No branch-history table. No historical rewrite of sales/lines/payments/stock/sessions/users.
- Registry checks (`psBranchIsRegistered` / `stockMovementBranchExists`) confirm PHP exact match after SQL lookup (blocks collation case-fold).
- Helpers: `includes/branch_integrity.php` (classification, write gate, snapshot). Admin report: `branch_integrity_report.php`.
- Invoice: `sale_invoice_seq.next_number` remains **140**; max committed numeric invoice in `online_sales_ref` = **107**; gap **108–139** (32 numbers) with no header rows — includes pre-existing advancement plus 9.4 probe numbers 132–139 (sales cleaned; seq not reset).
- Integrity after 9.5: `goods` 221, UNIQUE(product_id,branch) present, qty sum 0, `re_order_level` sum 2864, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sessions` 2 (both OPEN NULL branch unchanged), `users` 23, `branchies` 3 names unchanged, `cash_movements` 0, `sale_payments` 0, `sale_invoice_seq` **140**.

### Phase 9.6 notes (2026-10-09)

- No new UNIQUE/FK. Constraint assessment (`psBranchIntegrityConstraintAssessment`): duplicate `branchies` names = 0, but FKs blocked by historical orphan/blank strings across sales/users/sessions/transfers/GRV; UNIQUE deferred while orphans remain (avoid implying FK readiness). `idx_branchies_branch_name` verified present.
- No historical rewrite. Mismatch review: 11 distinct lines, 0 PROVABLE, 11 UNRESOLVED (duplicate headers / disagreeing branches). NULL OPEN tills = 2 unchanged. Users outside registry = 18 unchanged.
- Invoice: `sale_invoice_seq.next_number` remains **140** (not reset); probes 132–139 remain absent from sales.
- Integrity after 9.6 regression + cleanup: `goods` 221, qty sum 0, `re_order_level` sum 2864, UNIQUE(product_id,branch) present, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sessions` 2 (both OPEN NULL branch), `users` 23, `branchies` 3 names unchanged, `cash_movements` 0, `sale_payments` 0, `sale_invoice_seq` **140**, mismatch lines 11.

### Phase 11 notes (2026-10-09)

- **New tables** (safe `CREATE IF NOT EXISTS` via `psEnsureAccountingModule`):
  - `accounting_account_mappings` — PK `mapping_key`; `account_code` / `account_name` empty until configured (not invented).
  - `accounting_export_seq` — batch number counter AE-###### (not `sale_invoice_seq`).
  - `accounting_export_batches` — UNIQUE `batch_number`; status EXPORTING|EXPORTED|FAILED|REVERSED|REVERSAL; `external_status` default NOT_SENT; date/branch scope; debit/credit totals.
  - `accounting_export_batch_sources` — UNIQUE(`batch_id`,`source_type`,`source_id`); preserves branch label COMPANY_WIDE/UNALLOCATED/BRANCH.
  - `accounting_export_entries` — UNIQUE(`batch_id`,`entry_seq`); debit/credit mapping keys + account codes; amount; source refs.
  - `accounting_exported_sources` — PK(`source_type`,`source_id`) idempotency lock; status EXPORTED|REVERSED.
- **Rollback:** DROP the six `accounting_*` tables only if no retained export history is required; does not affect POS financial tables. Do not reset `sale_invoice_seq`.
- **Source financials preserved:** preview/export must not mutate `online_sales*`, `sale_payments`, refunds, cash, purchases, stock, tills, or invoice seq.
- Integrity after Phase 11 CLI suite (temp probes cleaned): `goods` 221, qty sum 0, `re_order_level` sum 2864, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sale_payments` 0, `cash_movements` 0, `sale_refund_requests` 0, `sessions` 2, `branchies` 3, `expenses` 1, `sale_invoice_seq` **140**. `accounting_export_seq` may be advanced by tests (not reset).

### Phase 10 notes (2026-10-09)

- **No new financial tables** and **no cached dashboard totals**. Reporting is derived from existing `online_sales_ref` / `online_sales` / `sale_payments` / `stock_movements` / `sale_refund_requests` / purchasing tables / Phase 7 cash tables.
- **No schema migrations** for Phase 10 (no new indexes required for correctness at current volumes; optional reporting indexes deferred).
- **No historical rewrite** of sale statuses, payments, stock, or branch strings to make reports reconcile.
- Integrity after Phase 10 CLI suite (read-only reporting tests; no permanent probe rows): `goods` 221, qty sum 0, `re_order_level` sum 2864, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sale_payments` 0, `cash_movements` 0, `sale_refund_requests` 0, `sessions` 2 (OPEN NULL branch unchanged), `branchies` 3, `sale_invoice_seq` **140**.

### Phase 14 notes (2026-10-09)

- **New table:** `security_audit` (append-oriented security events; not claimed tamper-proof / WORM).
- **Column widen:** `users.password` → `VARCHAR(255)` to hold bcrypt hashes. Existing rows **not** mass-rewritten in Phase 14; plaintext verified then upgraded on successful login (superseded by Phase 15 mass migration).
- **No** historical rewrite of sales/stock/tax/accounting/branch data. **No** invoice sequence reset (`sale_invoice_seq` remains **140**).
- Integrity after Phase 14 CLI suite (temp users/tokens/audit cleaned): `goods` 221, qty sum 0, `re_order_level` sum 2864, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sale_payments` 0, `cash_movements` 0, `sale_refund_requests` 0, `sessions` 2, `users` 23, `branchies` 3, `expenses` 1, `security_audit` 0, `sale_invoice_seq` **140**.

### Phase 15 notes (2026-10-09)

- **New table:** `backup_jobs` (backup metadata: status/size/checksum/table_count — no credentials / no financial payloads).
- **Password migration:** non-empty plaintext passwords hashed in place (idempotent). Verified plaintext remaining **0**; empty password rows **1** (not inventable).
- **No** production overwrite during restore tests (isolated temp DB only). **No** invoice sequence reset/advance from backup/restore tests (`sale_invoice_seq` remains **140**).
- Integrity after Phase 15 CLI suite: `goods` 221, qty sum 0, `stock_movements` 0, `online_sales` 235, `online_sales_ref` 107, `sale_payments` 0, `cash_movements` 0, `sale_refund_requests` 0, `sessions` 2, `users` 23, `branchies` 3, `expenses` 1, `tax_rates` 0, `accounting_export_batches` 0, `accounting_exported_sources` 0, `sale_invoice_seq` **140**.

## `stock_movements`

Phase 2 ledger. Authoritative quantity for tracked products is derived from movements, not from `goods.quantity`.

Product-master operations must not insert stock movements.

Movement types include (among others): `OPENING_BALANCE`, `GOODS_RECEIPT` (in), `SALE` / `TRANSFER_OUT` / `PURCHASE_RETURN` (out), `REFUND` / `TRANSFER_IN` (in), `ADJUSTMENT` (either). `PURCHASE_RETURN` is distinct from customer `REFUND`.

(Exact movement type vocabulary and column list: see `includes/stock_movement.php` — not duplicated here.)

## `product_audit`

Append-only field audit for product master (Module 3.9).

| Column | Role |
|--------|------|
| `id` | AUTO_INCREMENT PK |
| `product_id` | Product code |
| `goods_id` | `goods.id` at time of change |
| `branch` | Branch scope |
| `action` | CREATE / UPDATE / ACTIVATE / DEACTIVATE / IMPORT |
| `field_name` | Audited field |
| `old_value` / `new_value` | Text snapshots (nullable) |
| `changed_by` | Session `logger` |
| `changed_at` | Datetime |
| `source` | UI / IMPORT |

Indexes: `goods_id`, `(product_id, branch)`, `branch`, `changed_at`, `changed_by`.

Application code inserts rows only; no UPDATE/DELETE helpers in `product_master.php`.

Audited fields: `product`, `price`, `cost_price`, `category`, `button_category`, `reorder_level`, `pack_size`, `status` — never `quantity`.

## Sales integrity tables (Phase 1; recorded for baseline)

- `sale_invoice_seq` — next invoice number allocation.
- `sale_payments` — payment rows keyed by invoice number.
- `online_sales` / `online_sales_ref` — sale header/lines used by production submit path.

Invoice sequence is **not** redesigned in Phase 3. Verified values at Phase 3 close are recorded in `POS_REGRESSION_BASELINE.md`.

## `supplier_master` (Phase 4A)

Global supplier master. **No** branch column.

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_supplier_master_code` | `supplier_code` | Authoritative identity (global) |

| Column | Role |
|--------|------|
| `supplier_code` | Unique code (identity) |
| `supplier_name` | Display name |
| `contact_person`, `phone`, `email`, `address` | Contact fields (optional except as validated) |
| `status` | ACTIVE / INACTIVE |
| `created_at`, `updated_at` | Datetime metadata |

No FK from GRV tables to `supplier_master` in Phase 4A (legacy free-text `supplier_name` preserved).

## `supplier_audit` (Phase 4A)

Append-only field audit for supplier master.

| Column | Role |
|--------|------|
| `id` | AUTO_INCREMENT PK |
| `supplier_id` | `supplier_master.id` |
| `supplier_code` | Code at time of change |
| `action` | CREATE / UPDATE / ACTIVATE / DEACTIVATE |
| `field_name` | Audited field |
| `old_value` / `new_value` | Text snapshots (nullable) |
| `changed_by` | Session `logger` |
| `changed_at` | Datetime |
| `source` | UI |

Application code inserts rows only; no UPDATE/DELETE helpers.

## Legacy supplier-related tables (not Phase 4A master)

| Table | Role | Phase 4A action |
|-------|------|-----------------|
| `suppliers` | Older name-based rows (terms/contact/email/address); no code/status/unique | Left intact; not reused as master |
| `suppliers_link` | Name↔product link rows (legacy/pharmacy-era) | Left intact; unused by app PHP |
| `supplier_invoices` | Free-text supplier invoice / balance-style rows | Left intact; balances deferred |

## `purchase_order_seq` (Phase 4B)

Dedicated PO counter. **Not** `sale_invoice_seq`.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format applied in application: `PO-` + zero-padded 6 digits (e.g. `PO-000001`). Allocation uses `SELECT … FOR UPDATE` inside the PO create transaction.

## `purchase_orders` (Phase 4B)

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_purchase_orders_po_number` | `po_number` | Server-allocated |

| Column | Role |
|--------|------|
| `po_number` | Unique display/number (e.g. `PO-000001`) |
| `supplier_id` | `supplier_master.id` (app-validated; **no DB FK** — legacy DB safety) |
| `branch` | Session working branch (`branchies` name) |
| `order_date` / `expected_date` | Dates (`expected_date` nullable) |
| `status` | `DRAFT` / `APPROVED` / `CANCELLED` / `CLOSED` (`CLOSED` only via 4C when fully received) |
| `notes` | Optional |
| `created_by` / `created_at` / `updated_by` / `updated_at` | Metadata |

PO create/update/approve/cancel must **not** insert `stock_movements` or update `goods.quantity` / `goods.cost_price`.

## `purchase_order_lines` (Phase 4B)

| Column | Role |
|--------|------|
| `purchase_order_id` | Header id |
| `product_id` + `branch` | Product identity (same as goods UNIQUE pair; line branch = header branch) |
| `description` | Name snapshot at save time |
| `quantity` | DECIMAL(12,3), must be > 0 |
| `unit_cost` | DECIMAL(12,2) |
| `line_total` | DECIMAL(14,2), server `qty × unit_cost` |
| `created_at` / `updated_at` | Metadata |

**Received qty:** no `received_qty` column. Phase 4C derives received from `SUM(goods_receipts.quantity)` grouped by `purchase_order_line_id` (authoritative receiving records).

No FK to `goods` (app validates ACTIVE `product_id`+`branch`).

## `purchase_order_audit` (Phase 4B + CLOSE from 4C)

Append-only field audit for POs (not `product_audit`).

| Column | Role |
|--------|------|
| `purchase_order_id` / `po_number` / `branch` | Scope |
| `action` | CREATE / UPDATE / APPROVE / CANCEL / CLOSE |
| `field_name` / `old_value` / `new_value` | Field snapshots |
| `changed_by` / `changed_at` / `source` | Who / when / UI |

## Legacy `request_orders`

Exists in DB (`id`, `product`, `email`, `date_created`) — **not** the Phase 4B PO module; left intact and unused by PO PHP.

## `goods_receipt_seq` (Phase 4C)

Dedicated GRV counter. **Not** `sale_invoice_seq` or `purchase_order_seq`.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format: `GRV-` + zero-padded 6 digits. Allocation uses `SELECT … FOR UPDATE` inside the receive transaction.

## GRV / receiving tables (Phase 4C extended)

| Table | Purpose |
|-------|---------|
| `goods_receipts` | Line-level goods received. Legacy free-text fields retained. 4C adds nullable `grv_number`, `purchase_order_id`, `purchase_order_line_id`, `supplier_id`, `created_by`. Historical rows remain readable. |
| `goods_receipts_ref` | Header ref (`product` = supplier display; `reference1` = **supplier invoice** number). 4C adds nullable `grv_number` (UNIQUE), `purchase_order_id`, `supplier_id`, `created_by`. |
| `goods_receipt_audit` | Append-only receiving audit: CREATE_RECEIPT / RECEIVE / CLOSE_PO |
| `grv_report` | Derived/reporting table rebuilt by `ItemReceived.php` (product name, qty, value) — not redesigned |
| `movements_receipts` / `movements_receipts_ref` | Branch transfer / movement receipt lines (also free-text `supplier_name`) — not GRV module |

Active production receive path: `grv.php` → `psSaveGoodsReceipt` → `goods_receipts` + `goods_receipts_ref` + `stock_movements` (`GOODS_RECEIPT`) + audit (+ optional PO CLOSE).

**Received qty decision:** derive from `goods_receipts` by `purchase_order_line_id`; do not store a competing `received_quantity` on `purchase_order_lines`.

## `purchase_return_seq` (Phase 4D)

Dedicated purchase return counter. **Not** `sale_invoice_seq`, `purchase_order_seq`, or `goods_receipt_seq`.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format: `PR-` + zero-padded 6 digits. Allocation uses `SELECT … FOR UPDATE` inside the return transaction.

## `purchase_returns` / `purchase_return_lines` / `purchase_return_audit` (Phase 4D)

| Table | Purpose |
|-------|---------|
| `purchase_returns` | Header: `return_number`, `grv_number`, optional `purchase_order_id` / `po_number` / `supplier_id`, `supplier_name` snapshot, `branch`, `return_date`, `reason`, `status` (`COMPLETED`), `total`, `created_by` |
| `purchase_return_lines` | `goods_receipt_line_id` (source `goods_receipts.id`), `product_id`+`branch`, qty, `unit_cost` from original receiving, `line_total` |
| `purchase_return_audit` | Append-only CREATE_RETURN field audit (not `product_audit`) |

Stock: `stock_movements.movement_type = PURCHASE_RETURN` (direction **out**, negative quantity), `reference_type = purchase_return`, `reference_id = PR-*`.

Returnable qty is derived: receiving line qty − SUM of return lines for that `goods_receipt_line_id`. Only lines with non-empty `grv_number` are eligible.

Returns must **not** UPDATE `purchase_orders.status` (never reopen `CLOSED`).

## Balance / payable tables (Phase 4D decision)

| Table | Role | Decision |
|-------|------|----------|
| `supplier_invoices` | Legacy free-text supplier invoice / amount / amount_out / account_balance | **Not authoritative** payable. Left intact; not wired to `supplier_master` purchasing history as balance owed. |

## `customer_seq` (Phase 5.1)

Dedicated customer code counter. **Not** `sale_invoice_seq`, `purchase_order_seq`, `goods_receipt_seq`, or `purchase_return_seq`.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format applied in application: `CUS-` + zero-padded 6 digits (e.g. `CUS-000001`). Allocation uses `SELECT … FOR UPDATE` inside the customer create transaction. Never `MAX(id)+1`.

## `customer_master` (Phase 5.1)

Global customer master. **No** branch column.

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_customer_master_code` | `customer_code` | Authoritative display/code identity (global); immutable after create |
| UNIQUE `uniq_customer_master_national_id` | `national_id` | Stronger duplicate control when supplied; empty stored as NULL (multiple NULLs allowed) |

| Column | Role |
|--------|------|
| `customer_code` | Unique code (`CUS-######`) — identity with `id` |
| `first_name`, `last_name` | Individual name fields |
| `business_name` | Nullable; required for BUSINESS type |
| `phone`, `email`, `address` | Contact (optional; not globally UNIQUE) |
| `national_id` | Nullable; UNIQUE when non-NULL |
| `customer_type` | `INDIVIDUAL` / `BUSINESS` |
| `status` | `ACTIVE` / `INACTIVE` |
| `notes` | Optional |
| `created_at`, `updated_at`, `created_by`, `updated_by` | Metadata |

Indexes for search: status, type, phone, email, first/last/business name.

Phone / email / business-name duplicate blocking is **application-level** (not UNIQUE constraints), except national_id as above.

No FK from sales tables to `customer_master` (Phase 5 — app validation only; FK not added because legacy NULL history and safe rollout). Batch read helper: `psCustomersByIds` (IN-list of ints) for listings.

## `online_sales_ref` customer columns (Phase 5.2)

Authoritative completed-sale **header** table for production POS.

| Column | Role |
|--------|------|
| `customer` | Free-text display snapshot (receipts / listings); not identity |
| `customer_id` | **Legacy** nullable INT — historically intended as `customers.id`. Production POS leaves NULL. Do **not** store `customer_master.id` here. |
| `customer_master_id` | Phase 5.2 nullable INT — authoritative optional link to `customer_master.id`. Indexed (`idx_online_sales_ref_customer_master_id`). **No DB FK** (app-validated ACTIVE id). Walk-in / Cash Customer → NULL. |

Only one master identity column on the authoritative header (`customer_master_id`). Line table `online_sales` keeps free-text `customer` snapshot only (no duplicate master id column).

### Customer sales history reads (Phase 5.3)

- Query path: `psCustomerSalesHistory` filters `online_sales_ref` by `customer_master_id` only (uses existing index `idx_online_sales_ref_customer_master_id`).
- Date filter uses `COALESCE(DATE(sold_at), STR_TO_DATE(date_, '%Y-%m-%d'))` with validated `Y-m-d` bounds.
- Optional LEFT JOIN aggregate on `sale_payments` (by `invoice_number`) for payment method/currency — does not invent payment allocation.
- List screen may LEFT JOIN a `COUNT(*)` subquery grouped by `customer_master_id` for linked sale counts (single query; no N+1).
- No schema change in 5.3; no FK added; no mass rewrite of historical NULL `customer_master_id` rows.
- Pre-5.2 / walk-in / Cash Customer / free-text / legacy `customer_id` sales correctly appear with NULL `customer_master_id` and are **excluded** from registered-customer history.

## `customer_audit` (Phase 5.1)

Append-only field audit for customer master (not `product_audit` / `supplier_audit`).

| Column | Role |
|--------|------|
| `id` | AUTO_INCREMENT PK |
| `customer_id` | `customer_master.id` |
| `customer_code` | Code at time of change |
| `action` | CREATE / UPDATE / ACTIVATE / DEACTIVATE |
| `field_name` | Audited field |
| `old_value` / `new_value` | Text snapshots (nullable) |
| `changed_by` | Session `logger` |
| `changed_at` | Datetime |
| `source` | UI |

Application code inserts rows only; no UPDATE/DELETE helpers.

## Legacy customer-related tables (not Phase 5.1 master)

| Table | Role | Phase 5.1 action |
|-------|------|------------------|
| `customers` | Older driving-school-era rows (`fullname`, `contact`, `provisional_number` UNIQUE, `instructor`, `class`, …); 4 rows at audit | Left intact; not reused as master; still referenced by `psResolveSaleCustomer` |
| `top_customers` | Empty loyalty-ish stub (`customer_id`, `customer_name`, `points`) | Left intact; unused by 5.1 |
| `customers.php` | Legacy UI against table `customers` | Retained as legacy path; nav points to `customers_list.php` |

`online_sales_ref.customer_id` (nullable INT) remains a **legacy** sale-header column (`customers.id`); at 5.1 verification **0** sales rows populate it. Phase 5.2 adds distinct `customer_master_id` and does not repurpose `customer_id`. Free-text `customer` remains a display snapshot.

## `customer_account_seq` (Phase 6.1)

Dedicated customer account number counter. **Not** `customer_seq`, `sale_invoice_seq`, `purchase_order_seq`, `goods_receipt_seq`, or `purchase_return_seq`.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format applied in application: `ACC-` + zero-padded 6 digits (e.g. `ACC-000001`). Allocation uses `SELECT … FOR UPDATE` inside the account create transaction. Never `MAX(id)+1`.

## `customer_accounts` (Phase 6.1)

One receivable account per `customer_master` (optional).

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_customer_accounts_customer` | `customer_master_id` | One account per customer |
| UNIQUE `uniq_customer_accounts_number` | `account_number` | Server-allocated `ACC-######` |

| Column | Role |
|--------|------|
| `customer_master_id` | `customer_master.id` (app-validated; **no DB FK** — safe rollout consistent with Phase 5) |
| `account_number` | Unique display/number |
| `status` | `ACTIVE` / `SUSPENDED` / `CLOSED` (lifecycle — not credit hold) |
| `credit_status` | Phase 6.5: `NORMAL` / `ON_HOLD` — separate from lifecycle `status` |
| `credit_hold_reason` / `credit_hold_note` | Phase 6.5: current hold display only (history in audit; cleared on release) |
| `credit_limit` | DECIMAL(14,2) NOT NULL DEFAULT 0.00 — stored; Phase 6.2 authorises credit sales (0.00 = no facility) |
| `created_at` / `updated_at` / `created_by` / `updated_by` | Metadata |

Indexes: status, credit_status, created_at. No cached balance column — balance is ledger-derived.

## `customer_account_ledger` (Phase 6.1 + 6.2)

Authoritative receivables ledger. Balance = `SUM(debit) − SUM(credit)`.

| Column | Role |
|--------|------|
| `customer_account_id` | Account id (indexed; app-validated; no DB FK) |
| `entry_date` | DATE |
| `entry_type` | `OPENING_BALANCE` / `CREDIT_SALE` / `PAYMENT` / `PAYMENT_REVERSAL` / `CREDIT_NOTE` / `DEBIT_ADJUSTMENT` / `CREDIT_ADJUSTMENT` (6.1 opening balance; 6.2 CREDIT_SALE from POS; 6.3 PAYMENT + PAYMENT_REVERSAL on void; notes/adjustments future) |
| `debit` / `credit` | DECIMAL(14,2) ≥ 0; application rejects both > 0 and both zero |
| `reference_type` / `reference_id` | Optional refs (indexed together). Phase 6.2 CREDIT_SALE uses `SALE` + invoice number |
| `description` | Text |
| `created_at` / `created_by` | Metadata |

Indexes: account, entry_date, (reference_type, reference_id), entry_type.

| Constraint | Columns | Notes |
|------------|---------|--------|
| UNIQUE `uniq_cust_acct_ledger_ref_entry` | `reference_type`, `reference_id`, `entry_type` | Phase 6.2 duplicate protection for one CREDIT_SALE per sale when refs are non-NULL (MySQL allows multiple NULL refs for opening balances) |

**Credit limit / available credit (Phase 6.2 application maths, not stored columns):**
- `balance = SUM(debit) − SUM(credit)`
- `current_positive_balance = max(balance, 0)`
- `available_credit` (display) = `credit_limit − current_positive_balance`
- Authorise CREDIT sale when `credit_limit > 0` and `(balance + server_sale_total) ≤ credit_limit`

**Reconciliation note:** No denormalised/cached balance on `customer_accounts`. Always derive from the ledger. If a cached balance is added in a later module, document reconciliation and keep this ledger authoritative.

## `customer_account_audit` (Phase 6.1)

Append-only field audit for accounts (not `customer_audit`).

| Column | Role |
|--------|------|
| `customer_account_id` / `account_number` | Scope |
| `action` | CREATE / UPDATE / ACTIVATE / SUSPEND / CLOSE / OPENING_BALANCE / **HOLD** / **RELEASE_HOLD** / **LIMIT_UPDATE** (Phase 6.5) |
| `field_name` / `old_value` / `new_value` | Field snapshots (hold reason/note; limit old/new + reason) |
| `changed_by` / `changed_at` / `source` | Who / when / UI |

Application code inserts rows only; no UPDATE/DELETE helpers. No backfill. Prefer this coherent account audit for credit-control events (no duplicate credit-control audit table).

## Legacy account-like tables (not Phase 6.1 AR)

| Table | Role | Phase 6.1 action |
|-------|------|------------------|
| `accounts` / `account2` | Empty / school-era stubs | Left intact; unused by POS AR |
| `school_accounts` / `student_balances` | Driving-school fee stubs | Left intact; not receivables |
| `fuel_balance` / `stock_balances` | Unrelated | Left intact |
| `supplier_invoices` | Legacy free-text supplier balance-style rows | Still **not** AP authority; not customer AR |

## Historical/reporting tables (limitation)

- `grv_report` and `z_reading` may store product **name** (and amounts) without stable `product_id` linkage in older rows (Module 3.4 limitation). Do not treat name as identity when joining modern product master.
- Historical GRV supplier fields are free-text names; they are not mass-migrated onto `supplier_master`.
- Pre-4C GRV rows have NULL `grv_number` / PO refs; new receipts allocate `GRV-*` server-side. Pre-4C lines without `grv_number` are not returnable via Phase 4D.
- Legacy `customers` rows are not mass-migrated onto `customer_master`. Historical sales are not rewritten to `customer_master.id`.
- Pre-5.2 sales have NULL `customer_master_id`; new optional selections populate it.
- Phase 5.4: no further schema migration; indexes/uniques verified (`uniq_customer_master_code`, `uniq_customer_master_national_id`, `idx_online_sales_ref_customer_master_id`).
- Phase 6.1: new `customer_accounts` / `customer_account_ledger` / `customer_account_audit` / `customer_account_seq`. No mass-migration of legacy customers/sales into AR. No production opening-balance rows left after CLI cleanup (`customer_account_seq` may be advanced).
- Phase 6.2: added UNIQUE `uniq_cust_acct_ledger_ref_entry` on ledger; CREDIT payment method posts `CREDIT_SALE` from production POS only. No historical CREDIT_SALE backfill. No production test credit-sale rows left after CLI cleanup (`customer_seq` / `customer_account_seq` may be advanced; `sale_invoice_seq` preferred left at baseline).
- Phase 6.3: new `customer_account_payments`, `customer_account_payment_allocations`, `customer_account_payment_seq`, `customer_account_payment_audit`. Ledger posts `PAYMENT` / `PAYMENT_REVERSAL`. **Not** a reuse of `sale_payments`. No permanent test payment/allocation/ledger rows after CLI cleanup (`customer_account_payment_seq` may be advanced; `sale_invoice_seq` left at baseline).
- Phase 6.4: **no new financial tables**. Statements and ageing are derived from `customer_account_ledger` + `customer_account_payment_allocations` (+ optional `online_sales_ref.branch` for sale-branch filter). No `due_date` column added — ageing uses `CREDIT_SALE.entry_date`. No cached balance column. Existing ledger indexes (`customer_account_id`, `entry_date`, ref, type) used; no new composite index added. No permanent test rows after CLI cleanup (seqs may be advanced; not reset).
- Phase 6.5: ALTER `customer_accounts` add `credit_status` (+ optional current hold reason/note) + index; new non-financial `customer_collection_activity`. No second financial ledger. No permanent test holds/activities/payments/sales after CLI cleanup (seqs may be advanced; not reset).

## `customer_collection_activity` (Phase 6.5)

Non-financial collections contact history. Never posts ledger / PAYMENT / allocations.

| Column | Role |
|--------|------|
| `customer_account_id` | Account id (app-validated; no DB FK) |
| `activity_date` | DATE |
| `activity_type` | PHONE_CALL / VISIT / EMAIL / SMS / PROMISE_TO_PAY / FOLLOW_UP / OTHER |
| `outcome` | CONTACTED / NO_RESPONSE / PROMISE_RECEIVED / PROMISE_BROKEN / PAYMENT_RECEIVED / DISPUTE / ESCALATED / OTHER |
| `notes` | Text |
| `next_action_date` | Optional planning date (no reminder engine) |
| `promise_date` / `promise_amount` | Optional for PROMISE_TO_PAY; amount is display-only (not ledger) |
| `created_at` / `created_by` | Metadata |

Indexes: account, activity_date, next_action_date, type, created_at. Append-only create in application (no edit/delete helpers).

## `customer_account_payment_seq` (Phase 6.3)

Dedicated account-payment counter. **Not** `sale_invoice_seq`, `customer_seq`, `customer_account_seq`, PO / GRV / return seq.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format: `PAY-` + zero-padded 6 digits. Allocation uses `SELECT … FOR UPDATE` inside the payment create transaction. Never `MAX(id)+1`.

## `customer_account_payments` (Phase 6.3)

Account receivable receipts (not POS `sale_payments`).

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_cust_acct_payment_number` | `payment_number` | Server-allocated `PAY-######` |

| Column | Role |
|--------|------|
| `payment_number` | Unique display/number |
| `customer_account_id` | Account id (app-validated; no DB FK) |
| `payment_date` | DATE |
| `amount` | DECIMAL(14,2) > 0 |
| `payment_method` | `CASH` / `CARD` / `ECOCASH` / `BANK_TRANSFER` / `OTHER` |
| `reference` / `notes` | Optional |
| `status` | `POSTED` / `VOIDED` |
| `created_at` / `updated_at` / `created_by` / `updated_by` | Metadata |
| `voided_at` / `voided_by` | Set on void |

## `customer_account_payment_allocations` (Phase 6.3)

Optional allocation of a payment to Phase 6.2 credit-sale invoices. Does **not** create ledger rows.

| Column | Role |
|--------|------|
| `payment_id` | `customer_account_payments.id` |
| `customer_account_id` | Same account as payment (cross-customer rejected in app) |
| `sale_invoice_number` | Authoritative sale invoice (`CREDIT_SALE.reference_id`) |
| `allocated_amount` | DECIMAL(14,2) > 0 |
| `status` | `ACTIVE` / `VOIDED` (voided on payment void; rows retained) |
| `created_at` / `created_by` / `voided_at` / `voided_by` | Metadata |

Outstanding for a sale = `CREDIT_SALE.debit − SUM(ACTIVE allocations)` for that account + invoice.

## `customer_account_payment_audit` (Phase 6.3)

Append-only payment audit (not `customer_account_audit`).

| Column | Role |
|--------|------|
| `payment_id` / `payment_number` / `customer_account_id` | Scope |
| `action` | `CREATE` / `POST` / `ALLOCATE` / `VOID` |
| `field_name` / `old_value` / `new_value` | Field snapshots |
| `changed_by` / `changed_at` / `source` | Who / when / UI |

Application code inserts rows only; no UPDATE/DELETE helpers. No backfill.

**Void reversal note:** Original `PAYMENT` ledger row is retained. Financial reverse is a separate `PAYMENT_REVERSAL` debit with the same `reference_type=CUSTOMER_PAYMENT` and `reference_id=payment.id` (distinct `entry_type` satisfies UNIQUE). Payment amount is never zeroed; payment row is never deleted.

## Phase 6.4 reporting reads (no schema migration)

| View | Source | Notes |
|------|--------|-------|
| Account statement | `customer_account_ledger` | Running balance derived in app; payment refs resolved via batch join to `customer_account_payments.payment_number` |
| Opening / as-at balance | `customer_account_ledger` | `SUM(debit)−SUM(credit)` with `entry_date` cut-off |
| Sale outstanding (live) | ledger CREDIT_SALE + ACTIVE allocations | Existing `psCustomerAccountSaleOutstanding` |
| Sale outstanding (as-at) | same + `entry_date ≤ as_at` and `DATE(allocation.created_at) ≤ as_at` | Imperfect historical reconstruction documented |
| Ageing | CREDIT_SALE outstanding by due=`entry_date` | Buckets in application; LEFT JOIN `online_sales_ref` for sale branch only |

No second statement financial ledger table. No denormalised ageing cache.

## Phase 6.5 credit-control reads (no second financial ledger)

| View | Source | Notes |
|------|--------|-------|
| Overdue list / dashboard | `psCustomerReceivablesAgeing` (6.4) | Overdue = buckets 1–30..91+ only |
| On hold | `customer_accounts.credit_status` | Manual hold; no auto from ageing |
| Utilisation | ledger balance + `credit_limit` | positive_balance/limit; limit 0 = no facility |
| Collection history | `customer_collection_activity` | Non-financial; paginated |

## `cash_movement_seq` (Phase 7.1)

Dedicated counter for manual CASH_IN / CASH_OUT reference numbers. **Not** `sale_invoice_seq` or AR seqs.

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format: `CIN-` / `COUT-` + zero-padded 6 digits. Allocation uses `SELECT … FOR UPDATE` inside the manual post transaction. Never `MAX(id)+1`.

## `cash_movements` (Phase 7.1)

Append-only till cash ledger. Authoritative for till cash calculations (no cached balance column).

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_cash_mov_ref_entry` | `reference_type`, `reference_id`, `movement_type` | Dup protection for SALE / REFUND / OPENING / CIN / COUT |
| UNIQUE `uniq_cash_mov_opening` | `opening_guard` | Generated: one OPENING_FLOAT per `till_session_id` (NULL for other types) |

| Column | Role |
|--------|------|
| `till_session_id` | Existing `sessions.id` (app-validated; no DB FK) |
| `branch` | Till session / working branch name |
| `movement_at` | Datetime of movement |
| `movement_type` | `OPENING_FLOAT` / `CASH_SALE` / `CASH_REFUND` / `CASH_IN` / `CASH_OUT` |
| `amount` | DECIMAL(14,2) always > 0 (direction via type, not sign) |
| `reference_type` / `reference_id` | Stable refs (required for operational types) |
| `reason` / `description` | Phase 7.3: manual IN/OUT store controlled **reason code** in `reason`; optional `description` (required meaningful text when reason=`OTHER`). System types may still use free-text reasons (e.g. Opening float) |
| `created_by` / `created_at` | Session logger + timestamp |

Indexes: till_session_id, branch, movement_at, movement_type, created_at.

**Direction convention:** IN = OPENING_FLOAT, CASH_SALE, CASH_IN; OUT = CASH_REFUND, CASH_OUT.

**Balance:** Opening + Sales + Cash In − Refunds − Cash Out via `psCashMovementComputeBalanceFromParts` / `psCashMovementBalance` (SUM by type). Unchanged in Phase 7.3; consolidated in Phase 7.6 (reporting batch balances reuse the same helper).

**Phase 7.3 reason codes (application-controlled; stored in `reason`):**
- CASH_IN: `FLOAT_ADJUSTMENT`, `CASH_TRANSFER_IN`, `MANAGEMENT_CASH_IN`, `OTHER`
- CASH_OUT: `PETTY_CASH`, `CASH_TRANSFER_OUT`, `MANAGEMENT_CASH_OUT`, `EXPENSE`, `OTHER`
- No schema ENUM change required — VARCHAR(500) already holds codes. No second reason table.

**`sessions.opening_float`:** retained as open-time metadata snapshot. When opening cash > 0, exactly one OPENING_FLOAT matches that snapshot. Do not treat `sessions.opening_float` as the running till cash authority after open.

**Not posted in 7.1/7.3:** CARD / ECOCASH / BANK_TRANSFER / CREDIT / OTHER sale tenders; Phase 6.3 customer account payments; supplier/bank/journal / GL types. EXPENSE reason is operational label only.

Application code inserts rows only; no UPDATE/DELETE helpers.

## `cash_movement_audit` (Phase 7.1)

Append-only audit for manual CASH_IN / CASH_OUT CREATE snapshots (not a second cash ledger).

| Column | Role |
|--------|------|
| `cash_movement_id` / `till_session_id` / `branch` | Scope |
| `action` | `CREATE` |
| `field_name` / `old_value` / `new_value` | Field snapshots |
| `changed_by` / `changed_at` / `source` | Who / when / UI |

## Phase 7.1 historical note

- New tables: `cash_movements`, `cash_movement_seq`, `cash_movement_audit`. No mass historical cash migration.
- No permanent test cash movements after CLI cleanup (`cash_movement_seq` may be advanced; not reset).
- Existing till tables (`sessions`, `registers`, `sale_payments`) unchanged in role; `sale_payments` remains sale tender only.

## `cash_reconciliations` (Phase 7.2)

Snapshot of physical cash count vs ledger-derived expected cash. **Not** a second cash ledger and **not** a replacement for `cash_movements`.

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| KEY `idx_cash_recon_session` | `till_session_id` | Phase 7.4: multiple FINAL snapshots allowed; close uses latest (`ORDER BY id DESC`). Former UNIQUE `uniq_cash_recon_session` dropped. |

| Column | Role |
|--------|------|
| `till_session_id` | Existing `sessions.id` (app-validated; no DB FK) |
| `branch` | Till / working branch name snapshot |
| `reconciled_at` | Datetime of reconciliation |
| `expected_cash` | DECIMAL(14,2) — server from `psCashMovementBalance` at finalise |
| `counted_cash` | DECIMAL(14,2) ≥ 0 — physical count |
| `variance` | DECIMAL(14,2) — counted − expected (server) |
| `status` | `BALANCED` / `SHORTAGE` / `SURPLUS` (server; ignore posted) |
| `lifecycle` | `FINAL` on create (row immutable thereafter) |
| `notes` | Optional for BALANCED; required for SHORTAGE/SURPLUS (not an accounting adjustment) |
| `reconciled_by` / `created_at` | Session logger + timestamp |

Indexes: till_session_id, branch, reconciled_at, status, created_at.

Application code inserts FINAL rows only; no UPDATE/DELETE helpers for counted/variance/status. Prior FINAL rows remain history when a fresh count is added while OPEN.

## `sessions` closure snapshot columns (Phase 7.4 + 7.6 precision)

Authoritative till-session remains `sessions` (OPEN|CLOSED). Closure writes snapshot fields (not a second till-session table; no `cash_session_closures` duplication):

| Column | Role |
|--------|------|
| `opening_float` / `expected_cash` / `actual_cash` / `variance` | DECIMAL(14,2) aligned with `cash_movements.amount` (Phase 7.6). Closure snapshot from ledger expected / recon counted / variance (written on controlled close only). `opening_float` remains open-time metadata; ledger is cash calculation authority |
| `closed_by` | Session logger at close |
| `final_reconciliation_id` | Ref to latest FINAL `cash_reconciliations.id` used |
| `closure_notes` | Optional / required for non-zero variance |
| `reconciliation_status` | Snapshot BALANCED / SHORTAGE / SURPLUS |
| `closed_at` / `session_end` / `status=CLOSED` | Irreversible close |

## `till_closure_audit` (Phase 7.4)

Append-only CLOSE audit (not mixed into cash_movement / recon audits).

| Column | Role |
|--------|------|
| `till_session_id` / `branch` | Scope |
| `action` | `CLOSE` |
| `field_name` / `old_value` / `new_value` | Field snapshots (status, recon ref, expected/counted/variance, notes, closed_by, …) |
| `changed_by` / `changed_at` / `source` | Who / when / UI |

Application code inserts rows only; no UPDATE/DELETE helpers.

## `cash_reconciliation_audit` (Phase 7.2)

Append-only create/finalise audit (not a second cash ledger; not mixed into `cash_movement_audit`).

| Column | Role |
|--------|------|
| `cash_reconciliation_id` / `till_session_id` / `branch` | Scope |
| `action` | `CREATE` / `FINALISE` |
| `field_name` / `old_value` / `new_value` | Field snapshots |
| `changed_by` / `changed_at` / `source` | Who / when / UI |

Application code inserts rows only; no UPDATE/DELETE helpers.

## Phase 7.2 historical note

- New tables: `cash_reconciliations`, `cash_reconciliation_audit`. No mass historical reconciliation migration.
- No permanent test reconciliations / recon audit / cash movements after CLI cleanup (`cash_movement_seq` may be advanced; not reset).
- Does not alter Phase 7.1 `cash_movements` role or formulas.

## Phase 7.3 schema note

- **No new tables.** Strengthens application validation on existing `cash_movements.reason` / `description` + `cash_movement_audit` CREATE snapshots (includes reason, description, reference fields).
- Manual refs remain `CIN-######` / `COUT-######` via `cash_movement_seq` (may advance from tests; not reset).
- No permanent test cash movements / reconciliations after CLI cleanup.
- Does not change Phase 7.1 balance formula or Phase 7.2 recon snapshot schema.

## Phase 7.4 schema note

- **No second till-session table.** Closure snapshot on existing `sessions` columns (+ `closed_by`, `final_reconciliation_id`, `closure_notes`, `reconciliation_status`).
- New append-only `till_closure_audit`.
- `cash_reconciliations`: drop UNIQUE-per-session; index `idx_cash_recon_session`; multiple FINAL snapshots allowed while OPEN.
- No permanent test closures / cash / recon after CLI cleanup (`cash_movement_seq` may advance; not reset).

## Phase 7.5 schema note

- **No new financial tables** and **no cached cash balance columns**. Dashboard/reports are derived from `sessions`, `cash_movements`, `cash_reconciliations`, and existing audit tables.
- Indexes added where justified for filters/pagination (idempotent via `psEnsureCashReportingIndexes`):
  - `sessions`: `idx_sessions_status_branch (status, branch)`, `idx_sessions_closed_at (closed_at)`, `idx_sessions_cashier_email (cashier_email)`
  - `cash_movements`: `idx_cash_mov_branch_at (branch, movement_at)` (existing per-column indexes retained)
  - `cash_reconciliations`: `idx_cash_recon_branch_at (branch, reconciled_at)`
- Reporting helpers batch ledger balances and latest FINAL recons (avoid N+1). Pagination on all report lists.
- No permanent test cash / recon / closure / audit rows after CLI cleanup (`cash_movement_seq` may advance; not reset).

## Phase 7.6 schema note

- **No new tables / no second ledger / no cached balances.** Formula consolidated in `psCashMovementComputeBalanceFromParts` (used by `psCashMovementBalance` and reporting batch balances).
- Sessions money columns widened to DECIMAL(14,2) to match `cash_movements.amount` / recon money fields (`tillAlignSessionMoneyPrecision`).
- Authoritative objects unchanged: `sessions`, `cash_movements`, `cash_reconciliations`, `cash_movement_audit`, `cash_reconciliation_audit`, `till_closure_audit`, `cash_movement_seq`.
- Sequences not reset (`cash_movement_seq` / `sale_invoice_seq` may advance from tests).
- No permanent P76 test cash / recon / closure / audit / users after CLI cleanup. Pre-existing legacy OPEN `sessions` rows (empty branch) left untouched.

## Phase 8.1–8.3 refund schema

### `sale_refund_seq`

| Column | Role |
|--------|------|
| `id` | TINYINT PK; single row `id=1` |
| `next_number` | Next integer to allocate |

Format: `REF-` + zero-padded 6 digits. Allocation uses `SELECT … FOR UPDATE` at **request** time (8.2). Never `MAX(id)+1`. May advance from tests; **never reset**.

### `sale_refund_requests` (Phase 8.2)

Dedicated mutable workflow header — **not** overloaded onto `sale_refund_audit`.

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Request id |
| UNIQUE `uniq_sale_refund_req_number` | `refund_number` | One request per REF |

| Column | Role |
|--------|------|
| `refund_number` | `REF-######` allocated on request |
| `invoice_number` / `line_id` / `mode` | Sale target (`LINE`\|`LINES`\|`RECEIPT`; `line_id` set for single-line) |
| `branch` | Original sale branch only |
| `requested_amount` | Locked expectation at request; process recalculates and must match |
| `reason` / `reason_description` | Immutable after approval |
| `status` | `REQUESTED` \| `APPROVED` \| `REJECTED` \| `PROCESSED` |
| `requester` / `requested_at` | Create actor |
| `approver` / `approval_note` / `approved_at` | Approval (nullable) |
| `rejector` / `rejection_note` / `rejected_at` | Rejection (nullable) |
| `processor` / `processed_at` / `processed_amount` | Final process (nullable) |

### `sale_refund_request_lines` (Phase 8.3)

Per-request sale-line quantities. Sale `online_sales` rows remain authoritative; snapshots are display-only.

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_sale_refund_req_line` | `request_id`, `sale_line_id` | One row per sale line per request |

| Column | Role |
|--------|------|
| `request_id` | `sale_refund_requests.id` (app-validated; no DB FK) |
| `sale_line_id` | `online_sales.id` |
| `product_id` | Snapshot of product code |
| `requested_qty` | DECIMAL(12,3) > 0 — locked after approval; PROCESSED sum is remaining authority |
| `unit_price_snapshot` / `line_amount_snapshot` | Display only; process recalculates from sale |
| `created_at` | Datetime |

Indexes: `request_id`, `sale_line_id`. Only parent `status=PROCESSED` rows count in `psGetRefundableSaleLineQuantity` (REQUESTED/APPROVED do not reserve).

### `sale_refund_audit`

Append-only multi-event refund traceability (not a second stock or cash ledger).

| Constraint | Columns | Notes |
|------------|---------|--------|
| PRIMARY | `id` | Surrogate |
| UNIQUE `uniq_sale_refund_event` | `refund_number`, `event_type` | One row per event type per REF |

| Column | Role |
|--------|------|
| `refund_number` | `REF-######` |
| `invoice_number` | Original sale invoice |
| `mode` | `LINE` \| `LINES` \| `RECEIPT` |
| `branch` | Original sale branch |
| `payment_method` | From `sale_payments` or `UNKNOWN` |
| `amount` | DECIMAL(14,2) |
| `reason` / `reason_description` | Controlled reason + description |
| `line_summary` | `lineId:productId:qty:amount;…` (REQUEST/APPROVAL/PROCESSING) |
| `stock_movement_ids` | Comma-separated ids (PROCESSING) |
| `cash_movement_id` / `till_session_id` | Nullable; set when CASH_REFUND posted |
| `created_by` / `created_at` / `source` | Actor + timestamp + UI\|CLI |
| `event_type` | `REQUEST` \| `APPROVAL` \| `REJECTION` \| `PROCESSING` |
| `request_id` | Nullable link to `sale_refund_requests.id` (no DB FK) |
| `event_note` | Approval/rejection note (else empty) |

**No** historical approval backfill. **No** changes to `goods.quantity` authority.

**`online_sales.status='refunded'`:** compatibility marker only when a line is fully refunded (processed ≥ original). Partials must not set it. Pre-8.3 whole-line refunds without request-lines remain readable as fully refunded via status (line-qty history may be unmappable).

**Cash refs (8.3):** one `CASH_REFUND` per processed request uses `reference_type=REFUND`, `reference_id=REF-######` (UNIQUE with movement_type allows multiple partials). Legacy 8.1/8.2 may have used `REFUND`+invoice or `REFUND_LINE`+line id for whole-line refunds.

**Stock movement refs:** `reference_type=REFUND`, `reference_id=REF-######` (one movement row per sale line on the request).

## Phase 8.4 voids / cancellation schema

### `sale_cancel_audit`

Append-only cancellation / discard audit (not a stock or cash ledger; not mixed into `sale_refund_audit`).

| Column | Role |
|--------|------|
| `id` | AUTO_INCREMENT PK |
| `action` | `DISCARD_CART` \| `CANCEL_UNCOMPLETED` (reject of committed is not required to write a row) |
| `invoice_number` | Empty for cart discard; otherwise original invoice |
| `branch` | Session working branch (discard) or original sale branch (cancel) |
| `previous_status` / `resulting_status` | Header status snapshots (`CART`→`DISCARDED`, or prior→`CANCELLED`) |
| `reason` / `reason_description` | Controlled reason + description |
| `related_refund_ref` | Optional link (unused when directing user to refund UI without creating a REF) |
| `created_by` / `created_at` / `source` | Actor + timestamp + UI\|CLI |

Indexes: invoice, branch, created_at, action. Application inserts only; no UPDATE/DELETE helpers. No backfill.

### Sale header status (application convention)

| Status | Meaning |
|--------|---------|
| `''` (empty) | Legacy / pre-complete marker; may still be committed if payments/stock/cash/AR exist |
| `COMPLETED` | Production `submit_sale2.php` committed sale |
| `CANCELLED` | Phase 8.4 safely cancelled **uncompleted** sale (no ledger footprint at cancel time) |
| `refunded` | Compatibility fully-refunded marker (Phase 8.1–8.3) |

**No** new VOID stock or cash `movement_type` values. **No** deletion of `online_sales` / `online_sales_ref` / `sale_payments` / movements / audits on cancel. **No** `goods.quantity` writes. Sequences (`sale_invoice_seq`, `sale_refund_seq`, …) are **not** reset.

## Phase 8.5 refund / cash / stock reconciliation schema

Detection is **computed live** (no findings ledger that grows on each run). Optional review status only:

### `sale_refund_recon_reviews`

| Column | Role |
|--------|------|
| `discrepancy_key` | UNIQUE stable identity (category\|code\|REF\|invoice\|extra) — re-run safe |
| `category` | REQUEST_SALE / REQUEST_LINES / QTY_OVER / AMOUNT / STOCK / CASH / AUDIT / CANCEL / LEGACY / UNMATCHED |
| `invoice_number` / `refund_number` / `branch` | Display scope |
| `review_status` | `UNRESOLVED` / `REVIEWED` / `INCONCLUSIVE_ACK` |
| `last_reason` / `last_reviewed_by` / `last_reviewed_at` | Latest review snapshot |
| `created_at` | First seen |

### `sale_refund_recon_review_audit`

Append-only review actions (reviewer, time, reason, previous/new status, source). **No** UPDATE/DELETE helpers. Does **not** modify `stock_movements`, `cash_movements`, sales, payments, or AR.

### Indexes (idempotent)

- `sale_refund_requests.idx_sale_refund_req_processed_at (processed_at)` for date-filtered PROCESSED scans
- Optional `stock_movements.idx_stock_mov_type_ref (movement_type, reference_type, reference_id)` when creatable

**Linking conventions (unchanged from 8.1–8.3):** processed refund `REF-######` ↔ request lines ↔ stock `REFUND`/`REFUND`/`REF-######` ↔ (CASH only) cash `CASH_REFUND`/`REFUND`/`REF-######` ↔ audit `PROCESSING` (`stock_movement_ids`, `cash_movement_id`).

**No** permanent test recon/review rows after CLI cleanup. Sequences not reset.

## Phase 8.6 refund reconciliation reporting

**No new financial tables.** Reporting is derived from live 8.5 detection + existing review tables.

| Surface | Role |
|---------|------|
| `includes/sale_refund_recon_reporting.php` | Read-only report helpers (wraps `psSaleRefundReconciliationRun`) |
| `refund_recon_report.php` | Summary dashboard + filterable findings + CSV |
| `refund_recon_finding.php` | Authorised finding detail + read-only review history |

**Rules:** opening/filtering/exporting does **not** INSERT into `sale_refund_recon_reviews` / `sale_refund_recon_review_audit`. Findings remain computed live (no findings ledger). Export capped (≤2000 rows). Branch isolation via existing report branch resolver. Sequences not reset.

**Phase 8 COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS. No schema changes in the final closure audit.
