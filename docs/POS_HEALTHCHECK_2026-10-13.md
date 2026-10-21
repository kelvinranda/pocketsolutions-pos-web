# POS health-check fix-up — 2026-10-13

Fixes made after the full-app review. ZIMRA fiscalisation was deliberately left out (separate project).
A full code backup taken before any change is in `_to_delete/backup_code_before_fixes_2026-10-13.tgz`.

## Security
- Moved out of the web root into `_to_delete/` (web access blocked by its own `.htaccess`):
  source/vendor zips (one held an old DB password), `error_log`, sample receipt files,
  `online_sales_receipt_web.php` (hard-coded production DB login + unauthenticated sale writer),
  `smsAPI.PHP` (hard-coded SMS gateway login; sent an SMS on every visit), `export_to_excel.php`,
  debug/recovery scripts, test/demo pages, retired POS/GRV pages, and the 403 stubs.
- Do not upload `_to_delete/` to any server; delete it once reviewed.
- `.htaccess`: denies archives (`zip`, `gz`, `tgz`, `7z`, `rar`), `error_log`, `.py`, `.md`,
  spreadsheets/CSV (except `pos_import_template.xlsx`), any file or folder starting with `_`,
  and `includes/`. `display_errors` forced off when PHP runs as an Apache module.
- PHP error log now goes to `<pos-private>/logs/php_error.log` (outside the web root).
- `includes/page_access.php` (loaded by `pockets_config.php`, so every web page):
  login required except login/logout/index/health and the print agent; the 10-minute idle
  timeout + locked-user check now apply to every page; Till users can open only the till
  pages (POS, receipts, refunds, cash in/out, reconciliation, close till, change password).
  To allow Till users one more page add `'till_extra_pages' => 'grv.php,stock_take_sheet.php'`
  to the private `pockets_config.php` return array.
- Gated + branch-scoped: `cashier_sales.php`, `generate_pdf.php` (Z PDF), `goods_receipt.php`,
  `movement_receipt.php`, `new_expense.php` (also validates the amount).
- Print agent: `get_receipt.php` and `printed.php` need the shared token
  (`PS_PRINT_AGENT_TOKEN` env or `'print_agent_token'` in the private config, 16+ chars), sent as
  header `X-Print-Token`. `printed.php` works again (it was disabled, so jobs never cleared).
- Login throttling: 5 failures per email or 20 per IP in 15 minutes → blocked 15 minutes
  (uses `security_audit`, no new table).
- Logout requires POST + CSRF (shared logout dialog submits a form; a GET shows a confirm page).

## Broken features fixed
- POS catalogue / sale lookup / stock lookups: rows with an empty company tag now match.
  New and imported items are tagged with the user's company.
- Income statement: cancelled sales excluded; processed refunds deducted (and their cost);
  cost of sales uses the cost saved on the sale line, else the product's cost price.
  New sales now save the unit cost on each line (`online_sales.cost_price`).
- Stock List / Stock Take: `stock_list33` now has a `branch` column; each branch keeps its own
  rows (was one shared table wiped by whichever branch ran last). Stock Take builds the list on
  first use and never counts a line from another branch.
- Z Report + PDF + Cashier Sales exclude cancelled sales and share the same branch scope.
- One timezone for the whole app: Africa/Harare (override with `PS_TIMEZONE` / `'timezone'`).
  Note: older rows were written in the server's previous timezone.
- Stock Take no longer crashes if the legacy `bond_value` / `variance_value` columns are missing.
- POS shows a warning when the till has been open for more than 24 hours.
- Settings → **System Reset**: admin-only, import removed (use Import Items), and a full database
  backup is taken before the reset runs; if the backup fails nothing is deleted.
- `index.php` is now only a redirect to login. Legacy in-page menus removed from 5 pages.
- Branch Integrity page now loads the theme (it had no `<head>`).

## Uniformity
- Sidebar grouped: Sell · Till & Cash · Products & Stock · Purchasing · Customers & Accounts ·
  Reports · Finance · Admin. Menu label = page heading = browser title for every menu page.
- `ps_money()` helper: `$1,234.50` for USD, `ZWG 1,234.50` for other currencies (used on the
  Z Report, Cashier Sales, Income Statement).
- Developer wording ("Phase 6.3", "sale_payments", "OPENING_BALANCE", ...) replaced with plain
  staff language on the main screens.
- jQuery 3.7.1 and Chart.js 4.4.1 are served from `assets/lib/` (no CDN; works offline).
  Unused Font Awesome CDN links removed.
- Header search says what it does ("Search menu" for Till users; Enter only jumps to Items for admins).
- Legacy Customers page is read-only (add customers on Customers).

## Not changed (needs a decision or a larger refactor)
- Stock is still not ledger-controlled until Opening Stock is entered per branch.
- No VAT rates configured (part of the ZIMRA work).
- ~90 pages still carry their own `<style>` block (the theme overrides them); runtime
  `CREATE/ALTER TABLE` checks on each request remain.

## Follow-up — 2026-10-14
- Branch check: tills on branches registered without a company tag no longer fail with
  "not a registered branch" (registry check falls back to name-only when `branchies` has no
  `company_name` column). Verified on Westlea: POS, Cash In/Out, Cash Reconciliation, Close Till,
  Receipts and Refunds all load for the cashier.
- Tax Rates: the Configured rates table is now read-only rows with an **Edit** button per rate that
  opens a dialog; **Add rate** opens the same dialog (the separate Create card is gone). A failed save
  reopens the dialog with what was typed and the error. Shared `.ps-dialog` styles added to
  `assets/ps-theme.css` for reuse on other pages.
- Refunds: "Back to Dashboard" now shows "Back to POS" for Till users (the dashboard is admin-only).
- Moved to `_to_delete/2026-10-14/`: `pocketsolutions-pos-web.zip` (a 62 MB copy of the project taken
  before the 2026-10-13 fixes — uploading it would undo them) and the `_test_phase12–16` scripts.
- Checked: every page asks for login when signed out; Till users get "Not available" on all admin
  pages; no PHP errors on any admin page.

## Follow-up — 2026-10-14 (afternoon)
### One shared look
- The per-page `<style>` blocks are gone from 76 pages. Everything they did now lives once in
  `assets/ps-theme.css` ("Shared page components"), using the theme colours, so light and dark mode
  work on every page. The POS screen's styles moved to `assets/ps-pos.css`.
- Kept on purpose: the printed receipt, the receipt preview and the two PDF pages
  (`receipt_print.php`, `my_receipt.php`, `generate_pdf.php`, `generate_items_pdf.php`) carry their own
  print styles because they are printed or rendered outside the app theme.
- Inline colour styles replaced with theme classes (credit-hold note, user lock messages, etc.).
  Remaining inline styles are layout only (e.g. hidden-until-needed panels, dashboard tile colours).

### Stock is tracked for every product
- All 221 products at Village, Westlea sports bar and Concession bar are now on the stock ledger,
  started at their calculated stock (receipts and transfers in, less sales and transfers out; never
  below 0). Checked: every ledger balance equals its calculated stock.
- Movements recorded before a product's opening (e.g. sales) are cancelled with one adjustment, so they
  aren't counted twice. This also fixes the same double count on the manual Opening Stock and Legacy
  Stock Migration screens.
- New products (Create Item and Import Items) start tracked at 0. Receive stock with a goods receipt.
- The POS now shows stock on each product and stops a sale when there isn't enough.
- Opening Stock page: new "Start tracking all products" button for any branch with untracked products.
- Selling below zero is allowed for now: the POS and the server no longer block a sale when stock
  runs out; the sale still reduces stock, so it can show negative. To block again, add
  `'allow_negative_stock' => '0'` to the private pockets_config.php return array.
  Transfers, purchase returns and adjustments still check stock.
