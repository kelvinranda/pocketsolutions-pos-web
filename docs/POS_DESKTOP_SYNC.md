# Desktop tills and API v1 (Phase 21, 2026-10-16)

Pockets POS for Windows (`pocketsolutions-pos-desktop/`) is an offline-first till. It keeps its own SQLite copy of
the catalogue and records sales locally, then syncs them here. Decisions: **till invoice prefix** (T01-000123; the
server also gives every synced sale a normal invoice number), **ZIMRA device per till** (later milestone),
**accept + report** conflicts, **WPF on .NET 8**.

## Back office pages
- `desktop_tills.php` (Till & Cash › Desktop Tills, admin) — add a till for a branch → one-time pairing code
  (24 h). "Move to new PC" makes a new code; "Revoke" stops the till syncing.
- `desktop_sync_issues.php` (Till & Cash › Sync Differences) — what tills sold differently from the server:
  PRICE_DIFF, TAX_DIFF, NEGATIVE_STOCK (when stock first drops below zero), PRODUCT_MISSING / INACTIVE,
  CASHIER_UNKNOWN, SESSION_CONFLICT, CASH_DIFF, NOT_FISCALISED, REJECTED. Tick and "Mark reviewed".

## API (`api/v1/`, JSON, `Authorization: Bearer <till token>`)
| Endpoint | Purpose |
|---|---|
| `POST register.php` `{pairing_code, machine_name, app_version}` | pairing code → till token (only its SHA-256 is stored) |
| `GET ping.php` | token still valid? till info + server time |
| `POST login.php` `{login, password}` | checks a cashier (company, not Super Admin, Till/Supervisor must belong to the till's branch). The till caches its own PBKDF2 hash for offline sign-in (14 days). |
| `GET pull.php` | full catalogue of the till's company + branch: products (incl. inactive), button groups, tax rates, units, payment methods (no CREDIT), tax enforcement, `catalogue_hash` |
| `POST push.php` `{events:[…]}` | oldest first, ≤200. Types `session_open`, `sale`, `cash_movement`, `session_close`, `refund`. |
| `GET update.php?current=x.y.z` | newest active release above the till's version (`update`: version, sha256, size, notes, download) or `update: null`; records the version on `desktop_tills.app_version` |
| `GET update_download.php?version=x.y.z` | the release zip (`Content-Type: application/zip`, `X-Content-SHA256`); the till checks size + SHA-256 before installing |

Every event has a `client_uuid`. Result per event: `ok`, `duplicate` (already synced — same answer again),
`retry` (e.g. waiting for its till session), `rejected` (bad arithmetic etc.; listed on Sync Differences).

A pushed sale writes the same tables as `psCompleteSale` in one transaction: `online_sales_ref` (+ new
`till_invoice_number`, `desktop_till_id`, `sale_source='DESKTOP'`), `online_sales`, `sale_payments`
(`payment_token = 'D'+uuid`), `stock_movements` (SALE), `cash_movements` (CASH_SALE). The till's price, tax and
payment are kept; the server re-checks them against `goods` / `tax_rates` and records differences. No
`print_jobs` row (the till prints itself). Till sessions become `sessions` rows on a per-till register
("Desktop #id Txx"); if the cashier already has an OPEN till on the server the desktop session is stored as
`DESKTOP_OPEN` and a SESSION_CONFLICT is reported. Close sets CLOSED with server-ledger expected cash.

Account (CREDIT) sales post to the customer account ledger. A sale fiscalised on the till PC (its own ZIMRA
device) carries `fiscal` {device, day, global no., verification code, QR}; it is stored on `online_sales_ref.till_fiscal_*`
and is not flagged NOT_FISCALISED.

`session_close` carries the till's FINAL cash `reconciliation` (counted, expected, status, notes); its status
(BALANCED / SHORTAGE / SURPLUS) is stored as `sessions.reconciliation_status`.

`refund` (request → approve → process happened on the PC) writes what `psProcessApprovedRefund` writes: a
PROCESSED `sale_refund_requests` row with a new REF-number (+ `till_refund_number`, `desktop_till_id`,
`till_fiscal_global_no` for the till's ZIMRA credit note), `sale_refund_request_lines`, REFUND `stock_movements`,
refunded line/header status, one CASH_REFUND in the till's session for a cash sale, and the refund audit row
(source DESKTOP). Accept + report: a quantity no longer refundable (REFUND_CONFLICT) or a different amount
(REFUND_DIFF) is recorded and listed on Sync Differences. Waits (`retry`) until its sale and till session have synced.

## Shop network (several desktop tills)
Desktop tills of one shop can share their records over the shop LAN (main till hosts the others; see the desktop
README). Each PC is still its own registered till on live and pushes its own events (directly, or through the main
till's relay, which forwards `api/v1/*.php` calls with that till's own token). A `refund` may therefore name a sale
pushed by another till: it is accepted when both tills belong to the same company (else `rejected`), and the cash
refund goes into the refunding till's session.

## Desktop releases (auto-update)
Super Admin › *Platform › Desktop Releases* (`platform_desktop_releases.php`): upload the `PocketsPOS-x.y.z.zip` made by
`installer\build.ps1` in the desktop project, with release notes. Files are stored in the private root
(`<PS_PRIVATE_ROOT>/desktop-releases`, never under htdocs); table `desktop_releases` (created by the app). A release can be
stopped (*Stop offering*) or deleted; the page also lists active tills by the version they last reported. Uploads need
`upload_max_filesize` / `post_max_size` above the zip size (about 30–80 MB framework-dependent).

SQL: `db/2026-10-16_phase21_desktop_tills.sql` (optional — the app creates the tables itself).
