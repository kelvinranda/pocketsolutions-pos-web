# Multi-currency (web + desktop till) — 2026-10-20

This guide covers how currencies work, what changed in the database, how to deploy it, and how staff use it.
Code: `includes/currency.php` (web) and `PocketsPos.Core/Domain/Fx.cs` (desktop). Both use the same integer arithmetic,
checked against 2,994 PHP-generated test vectors.

---

## 1. The model

| Term | Meaning |
|---|---|
| **Catalogue** | Platform-wide list of currencies (`currencies`): ISO code, name, symbol, decimals (0–2), symbol position, ACTIVE / DISABLED. Super Admin only (Platform › Currencies). |
| **Base currency** | One per company (`company_base_currency`). Reports, accounting export and consolidated totals are in the base. Set by a Super Admin; **cannot be changed once the company has transactions**. A company that never chose one uses the legacy currency (USD). |
| **Enabled currencies** | Per company (`company_currencies`): which catalogue currencies it may transact in. The base is always enabled. Super Admin only. |
| **Exchange rate** | `exchange_rates`, per company, **append-only**: "1 FROM = rate TO" from an effective date/time. A newer row supersedes; a wrong row is RETIRED (with a reason), never edited or deleted. Managed by admins / Management on Finance › Exchange Rates. No external rate feed. |
| **Transaction currency** | The currency a sale, receipt, payment, expense, purchase order, goods receipt, quotation or cash movement is made in. |
| **Rate snapshot** | Stored on every new transaction at posting time: `currency`, `base_currency`, `fx_rate` (1 BASE = fx_rate TXN), `fx_rate_ids` (exchange_rates rows used), `fx_path`, and the base equivalent (`base_total` / `base_amount`). History is never re-valued. |

### Rate lookup
At moment *t*: the latest ACTIVE row with `effective_from <= t` for FROM→TO, or TO→FROM used as 1/rate (most recent wins;
two rows starting at the same moment that disagree by more than 0.01% are a conflict and block). If neither exists: one hop
through the base (FROM→BASE→TO). Otherwise the transaction is refused with "No exchange rate …" — the system never guesses.

### Arithmetic
All money is integer cents. Rates are exact decimals (up to 8 places) held as fractions. Conversion =
round-half-up(cents × num / den), then rounded to the target currency's decimals. No floating point.

### Prices
Product prices and costs stay in the **base**. A sale in another currency converts each **unit price** once with the exact
rate (rounded to that currency's decimals); quantity, discounts and VAT are then worked out **in the sale currency**, so the
receipt, the stored lines, the tax and the ZIMRA receipt all agree.

---

## 2. Where currency now applies

### Web
- **POS (ajax_pos.php / submit_sale2.php):** currency picker under the till line (shown when the company has more than one).
  The browser converts with the same fraction the server uses. The server re-validates the currency, takes a fresh snapshot
  inside the sale transaction and **refuses the sale if the rate changed after the cashier saw the prices**
  (the till then reprices with the new rate; no invoice number is used).
- **Payments:** tender is in the sale currency (`sale_payments.currency`). Change is in the sale currency.
- **Receipts:** printed / PDF receipts show the sale currency and its symbol. The exchange rate is stored with the sale (and used for refunds, reports and accounting) but is **not printed** on the customer receipt.
- **Tills and cash:** every cash movement records its currency + snapshot. Each till has **one drawer per currency**
  (base first). Cash reconciliation counts each currency separately (tabs); **a till closes only when every currency on it
  has a current count**. Session closing figures are the base drawer; other currencies are listed in the closing notes and
  remain in `cash_reconciliations` (with `currency`). Cash in / out has a currency picker; the cash-out approval limit is in the base.
- **Refunds:** always in the original sale currency at the original sale's rate (cash comes out of that drawer). ZIMRA
  credit notes use the original receipt currency.
- **Customer accounts:** each account is kept in **one currency**, chosen when it is opened (default base). Credit sales must
  be in the account currency. Repayments may be received in another enabled currency; they are converted into the account
  currency at today's rate (tender currency, amount and rate are kept on the payment).
- **Purchasing:** purchase orders carry a currency (unit costs are in it; the suggested cost is converted from the base cost).
  Goods receipts use the PO currency (or a chosen currency without a PO) and store the rate on the day goods arrive and the
  base total. Purchase returns use the goods receipt's currency and rate.
- **Expenses:** currency picker; amount stored in it plus snapshot and base amount (`funding_account` = currency, as before).
- **Quotations:** priced in their own currency; a quote can only be sold in its currency (the POS switches to it).
- **Accounting export:** every journal is converted into the base with the **source document's own stored rate** (pairs
  converted as a whole so debit = credit stays exact). Document currency, amount and rate are kept on each entry and in the
  CSV (`txn_currency`, `txn_amount`, `fx_rate`). Sources with no usable rate are blocked (`MISSING_EXCHANGE_RATE`). Existing
  NOT_SENT status and duplicate-source protection are unchanged.
- **Reports:** sales, Z report, cashier sales, income statement, profit, purchasing, refunds and receivables ageing total in the
  base (each document at its own rate; older documents are base). Sales reports have a **By currency** tab
  (count, total in each currency, base equivalent). Receivables ageing shows each account in its currency with base totals.

### Desktop till (offline)
- Currencies and rates (current and future-dated) download with each sync and are used offline.
- Sell screen currency picker; conversion and rounding identical to the web.
- Each sale stores currency + snapshot and sends them to the server. The server keeps the **till's rate** if it differs from
  its own for that moment (the customer paid at it) and raises an **FX_RATE** sync issue; an unknown currency is refused;
  a currency disabled since raises **FX_CURRENCY**.
- Drawers, cash in / out, counts and close per currency (same rules as the web). Refunds at the original sale rate.
- Account payments are taken in the account's currency (cash goes into that drawer).
- ZIMRA receipts are issued in the sale currency.
- Quotes made on the till are in the base; a quote in another currency is edited on the web (the till can still sell it).

---

## 3. Database changes (all additive)

New tables (created automatically on first use): `currencies`, `company_base_currency`, `company_currencies`,
`exchange_rates`, `currency_audit`. The catalogue is seeded with USD, ZWG ("ZiG") and ZAR if empty.

New **nullable** columns (added automatically, existing rows stay NULL):

| Table | Columns |
|---|---|
| online_sales_ref | currency, base_currency, fx_rate, fx_rate_ids, fx_path, base_total |
| cash_movements | currency, base_currency, fx_rate, fx_rate_ids, fx_path, base_amount |
| cash_reconciliations | currency |
| customer_accounts | currency |
| customer_account_payments | snapshot + base_amount, tender_currency, tender_amount, tender_rate |
| goods_receipts_ref, purchase_returns, quotations | snapshot + base_total |
| purchase_orders | snapshot |
| expenses | snapshot + base_amount |
| sale_refund_requests | currency, base_amount |
| accounting_export_entries | txn_currency, txn_amount, fx_rate |

Desktop SQLite: new `currency` columns on sales, cash movements, counts, LAN sales; setting `fx_json`.

### Legacy records
Rows without a currency are **legacy**: they are treated as the legacy currency (config `legacy_currency`, default USD) at
1:1 to the base. Because a company's base cannot change once it has transactions, legacy rows of a company always belong
to its base. Nothing is rewritten. The old `currency` table (ECOCASH, ZIG, PULA, … with text rates) from the earlier app
is **not used** by this feature and was left untouched.

---

## 4. Deployment

1. **Back up the live database** (Backup & Recovery, or mysqldump).
2. Upload the changed web files (list in the hand-over message). Do not upload `vendor/` or config files.
3. Sign in as a **Super Admin** → Platform › Currencies:
   - check the catalogue (USD, ZWG, ZAR are seeded; add others with their ISO code and decimals);
   - for each company: confirm the **base currency** (do this before it has transactions if it should not be USD) and
     **enable** the currencies it sells in.
4. Sign in as the company admin / manager → Finance › Exchange Rates: add a rate for each enabled currency
   (e.g. "1 USD = 26.50 ZWG", effective now).
5. Desktop tills: install the new build, then **Sync** so currencies and rates arrive. Until they sync, a till sells in the base only.
6. ZIMRA: if a fiscal device will issue ZWG (or other) receipts, make sure the device is allowed that currency on the ZIMRA portal.
7. Open a till, make a small sale in each currency, count each drawer and close — on the web and on a desktop till.

Rollback: the changes are additive. Restoring the pre-deployment backup and the previous files returns the old behaviour;
data entered after deployment in other currencies would be lost in that case.

---

## 5. User instructions (short)

- **Change the sale currency (cashier):** choose it in the currency box under the till line before adding items. Prices on
  screen convert; the receipt shows the amounts in that currency. If the rate changes while you are selling, the till tells you,
  updates the prices — check the total and pay again.
- **Count the till:** Cash Reconciliation shows one tab per currency in the drawer. Count each one. Close Till lists every
  currency and only closes when all are counted and current.
- **Cash in / out in another currency:** choose the currency before the amount.
- **Refunds:** nothing to choose — they go back in the currency the customer paid, at the rate of the original sale.
- **Customer accounts:** pick the account currency when opening it (it cannot change). When a customer pays in another
  currency, choose "Received in" — the amount is converted at today's rate.
- **Purchase orders / goods received / expenses:** pick the currency the supplier invoiced in.
- **Exchange rates (managers):** Finance › Exchange Rates → add "1 [base] = x [currency]" with the time it starts. To
  correct a mistake, retire the wrong rate with a reason and add the right one. Rates already used by sales stay on record.
- **Reports:** see section 7 — Consolidated (base, at stored rates) or By currency, a Currency filter, a ⇄ tag on converted amounts (hover / focus + Enter / tap for the original amount and rate), and a red warning when a rate is missing.

---

## 6. Known limitations / decisions to approve

- **Cross-currency tender is off:** a sale is paid in its own currency (a ZWG sale is paid in ZWG). The system has no split
  tenders (one payment method per sale) — unchanged.
- **Opening float** is in the base; add other currencies with Cash in.
- **FX gains / losses** are not calculated: a repayment in another currency is converted at today's rate into the account
  currency; no realised / unrealised FX journal is exported.
- **Base change after transactions is blocked** (would need a conversion project).
- **Currency decimals 0–2 only**; rates up to 8 decimal places.
- **Expenses** have no company column (pre-existing); their base is the signed-in company's base.
- **Desktop counts** of non-base drawers reach the server in the session's closing notes (the desktop sends its counts with
  the close, as before for the base drawer); web tills store a `cash_reconciliations` row per currency.
- **Mobile app** (on hold) still sells in the base only.
- **ZIMRA:** receipts in ZWG/other currencies are tested against the built-in simulator only; live FDMS acceptance of each
  currency depends on the device's ZIMRA configuration.

---

## 7. Currency-specific reporting and auditability (2026-10-21)

Rule: **no report adds amounts of different currencies together.** Shared code: web `includes/fx_report.php`
(the "mix" model), desktop `PocketsPos.Core/Domain/FxMix.cs` (same rules, same colours).

### 7.1 Model
- Every total is kept **per transaction currency** (count, amount in that currency, base equivalent, rates used, first/last
  date, up to 5 references).
- The **consolidated** total is in the company base and uses the base amount **stored with each transaction**
  (`base_total`, `base_amount`, `fx_rate` of the sale / refund / PO / GRV / expense / payment). Today's rate is never used.
- **Missing rate:** a non-base row without a stored base amount is shown in its own currency, **left out** of the
  consolidated total (never counted 1:1), and the total is flagged incomplete (`*`, red warning on the page, "(incomplete)"
  in exports).
- **Legacy:** a row with no currency (recorded before multi-currency) counts in the base and is flagged "legacy".
- **Cash** (drawer balances, counts, variances, cash in / out) has no stored rate by design: always shown per currency
  ("native"), never converted.
- Line-level amounts (products, tax, profit) use the sale's own stored ratio `base_total ÷ total`.

### 7.2 Display
- Toolbar on every report: **Consolidated (base) / By currency** toggle (`fx_view=breakdown`) and **Currency** filter
  (`fx_cur=XXX`) — combined with the existing date / branch / cashier filters. Desktop: the same chips under the period filter
  (shown only when the company has more than one currency).
- Converted amounts carry a tag with the **currency code** (never colour alone) in that currency's colour
  (ZWG amber, ZAR teal, USD blue, BWP violet, EUR rose, GBP green; others by crc32, same on web and desktop).
  The detail (original amount, rate with direction "1 USD = 26.50 ZWG", rate date / recorded date, references, missing rows)
  opens on hover, keyboard focus + Enter/Space, and click / tap; Escape closes. Web: `role=button`, `aria-describedby`,
  `role=tooltip`; desktop: tooltip + popup, `AutomationProperties.HelpText`.
- Per-currency subtotals are shown before the consolidated total (Sales › By currency, Z report, tax, cash, summaries).

### 7.3 Coverage
| Area | Web | Desktop till |
|---|---|---|
| Sales (list, cashier, product, methods, by currency, invoice) | sales_reports.php, all_receipts.php, cashier_sales.php | Sales Reports (incl. By currency tab) |
| Z report (screen, PDF, print) | z_report.php, generate_pdf.php | Z Report + printed Z (per currency then total) |
| Refunds / refund reconciliation | sales refunds tab, refund_recon_*.php | Refunds tab, Refund reconciliation (amounts in the sale currency) |
| Dashboards | management_dashboard.php | Management Dashboard |
| Till / cash | cash_management.php, movements, reconciliation, closure, session view (drawer per currency) | Cash Management (KPIs, movements, counts per currency, shop tills), Cash audit, till KPIs labelled "base drawer" |
| Tax | tax_reports.php (per code per currency, at each sale's rate) | Tax Reports (same) |
| Profit / income | profit_report.php, income_statement.php | Profit & Margin |
| Inventory valuation | inventory_reports.php, product_history.php (base cost / price, labelled) | Inventory Reports (base prices, labelled) |
| Purchases / suppliers | purchasing_reports.php, supplier history, PO / GRV / return views | — (not on the till) |
| Customer accounts | ageing (each sale's stored rate), statements, credit control, payments | — (account payments only) |
| Expenses | expenses.php | — |
| Accounting export / preview / recon | accounting*.php (source currency + base) | — |
| ZIMRA fiscal receipts | — | per-currency totals (no consolidation) |
| CSV / PDF | currency, original amount, rate, base columns; per-currency TOTAL rows | `[CODE]` tag on converted cells, "Currency detail" column (CSV) / section (PDF), warning line |

### 7.4 Bugs fixed while doing this (they mixed currencies)
Web: tax summary, Z report PDF, cash movement totals and variances, open-till batch balances, supplier PO / return totals,
credit-control overdue total, dashboard recent-sales total, income statement (1:1 fallback for missing rates), receivables
ageing (re-valued at the as-at rate). Desktop: `COALESCE(base_total_cents, total_cents)` counted missing rates at face value;
tax, cash in / out / refunds, shop-till totals, till ledger totals, the session sales total, ZIMRA receipt totals and the cash
audit all added amounts across currencies.

### 7.5 Database
Additive only. Desktop schema v12: `v_sales` exposes `base_raw` (stored base, NULL kept) and `fx_rate`; `lan_cash.currency`
(other tills' cash in / out), `v_cash_movements.currency`. Web: no new columns (uses the 2026-10-20 ones).

### 7.6 Tests
- Web: `t_fxr.php` 41/41 (mix maths, display, tooltip accessibility, legacy / missing flags, CSV columns, sales reconcile to
  the database, currency filter, drawer balances match the till ledger, cash per currency, tax with stored lines, ageing not
  re-valued, branch scope); existing suites unchanged.
- Desktop: "Currency-specific reporting" + "…screens" tests; full suite 47/47.
- **Not verified:** real browser rendering / tooltip behaviour with a screen reader, the WPF screens on Windows (the WPF
  project cannot be compiled in the build container), printed output on a receipt printer, PDF appearance by eye.

### 7.7 Limitations
- Cash counts and cash movements have no stored rate, so cash figures are per currency only.
- Other tills' cash refunds on the shop network take the currency of the refunded sale; movements from tills older than this
  version have no currency and count in the base (flagged legacy).
- Credit control's "significant outstanding" threshold is a fixed 100 in each account's own currency (ZiG 100 is not $100); the overdue totals themselves are per currency.
- Desktop reports cover this PC (or the shop network); live has every till.
