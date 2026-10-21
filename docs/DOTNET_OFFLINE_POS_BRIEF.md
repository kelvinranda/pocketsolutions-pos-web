# Handover brief: Pockets POS for Windows (.NET, offline-first) based on the Pockets web POS

**Purpose.** Paste or attach this file at the start of a new conversation. It gives the full context of the existing
web POS, so the .NET desktop POS reuses its rules, data and server instead of starting from zero.
Written 2026-10-16 from the code in `C:\xampp\htdocs\pocketsolutions-pos-web` (PHP 8.1+, mysqli, MariaDB).
The other docs in `docs\` (POS_PROJECT_CONTEXT, POS_BUSINESS_RULES, POS_DATABASE_RULES, POS_ZIMRA, POS_PLATFORM)
are the detailed source of truth.

---

## 1. The goal

A Windows desktop till that:
- sells, takes payment, prints receipts and runs the till session **with no internet**;
- keeps its own local copy of products, prices, tax, users and customers;
- **syncs** every transaction to the live web POS database when the connection returns (and pulls catalogue / price / user
  changes down);
- behaves exactly like the web POS (same rules, same numbers on reports), so a company can mix web tills, desktop tills
  and the mobile app.

The web POS stays the **back office and the source of truth** (products, prices, users, reports, ZIMRA set-up,
Super Admin). The desktop app is a **till**, not a second back office.

## 2. What the web POS already is (the parts that matter for a till)

**Multi-company, multi-branch.** `companies.company_name` is the company key used everywhere
(`users.company_name`, `branchies.company_name`, `goods.company_name`, `zimra_devices.company_name`).
Branch names repeat across companies, so **(company, branch) is the real branch identity**.

**Roles** (compared case-insensitively): Super Admin (platform, never sells), admin, Management, Supervisor, Till.
Sales: everyone except Super Admin. Refund approval: admin / Supervisor / Management, never your own refund unless no
other approver exists. Passwords: bcrypt (`password_hash`), minimum 6 characters; login is email **or** username.
Note: 32 of 34 users in the 13 Oct live database still had plaintext passwords; the web POS upgrades them to bcrypt
at login.

**Products — `goods`** (one row per product per branch): `product_id` (Product ID, numeric string, must be unique within
a branch), `product`, `price`, `cost_price`, `category`, `button_category` (till button group), `pack_size` +
`unit_of_measure` (code from `units_of_measure`), `barcode` (unique per branch), `tax_code`, `status` ACTIVE/INACTIVE,
`reorder_level`, `company_name`, and (Phase 20) `sell_by_measure` (cashier types 1.25 kg; price is per unit),
`variant_group` / `variant_base` / `variant_label` (sizes/colours; each variant is its own product).
Lists: `product_categories` (per company, kind category|button, colour + order for till buttons),
`units_of_measure` (shared).

**A sale** (`submit_sale2.php` → `psCompleteSale`, all in ONE database transaction):
1. Lock the cashier's open till session (`sessions`, one OPEN till per cashier); sale branch = till's branch.
2. Rebuild every line from `goods` on the server — **client prices are ignored**; quantity up to 3 decimals;
   line cents = unit cents × qty.
3. Tax per line from `tax_rates` (code, TAXABLE/ZERO_RATED/EXEMPT, rate, INCLUSIVE/EXCLUSIVE, effective dates).
4. Stock check against the stock ledger (`stock_movements`), unless negative stock is allowed.
5. Invoice number from `sale_invoice_seq` (one global sequence) → header `online_sales_ref`, lines `online_sales`,
   payment `sale_payments` (method CASH/CARD/ECOCASH/BANK_TRANSFER/CREDIT/OTHER, currency, amount, change,
   `payment_token` unique = double-submit guard), stock movement −qty per line, cash movement for cash,
   customer-account ledger for CREDIT.
6. ZIMRA fiscal receipt numbered + signed inside the same transaction (if the branch has an enabled device), sent to
   ZIMRA **after** commit.
7. Print job for the receipt printer agent (`print_jobs`, `get_receipt.php`/`printed.php`).

**Refunds** are request → approve → process (`sale_refund_requests` / `_lines`), with a CreditNote to ZIMRA when processed.
**Till**: open session with float → cash in/out → reconcile → close (expected vs counted, variance).
**Customers / accounts**: `customer_master`, `customer_accounts` (credit limit), ledger + payments.

**ZIMRA FDMS** (Phase 17, `includes/zimra.php`, `includes/zimra_receipts.php`, `docs/POS_ZIMRA.md`):
one device per (company, branch, environment); ECC P-256 key + certificate (mTLS); receipts are numbered
(receiptCounter, receiptGlobalNo), hashed and **signed by the device key**, chained by previousReceiptHash; fiscal day
open → receipts → CloseDay with signed counters; QR = qrUrl/deviceID/date/globalNo/MD5(signature). Offline receipts
stay PENDING and are sent in global-number order. Fiscal day auto-opens on the first sale and must be closed within
`taxPayerDayMaxHrs`.

**Existing API for non-web tills** — `mobile-endpoints/mobile_api/` (Bearer token in `mobile_tokens`):
`login.php` (returns token + authorised branch; Super Admin / suspended companies refused), `products.php`,
`session_sync.php`, `sync_sales.php`. **`sync_sales.php` is deliberately fail-closed today** (Phase 9.6): it only answers
idempotently for already-mapped `client_uuid`s and does NOT post new sales, because it bypassed payments / stock / cash.
So **the server side of "sync a sale" still has to be built properly** — this is the main server work for the new project.

## 3. Recommended architecture (to confirm in the new chat)

| Layer | Recommendation | Why |
|---|---|---|
| Desktop UI | **.NET 8, WPF** (MVVM, CommunityToolkit.Mvvm) | Mature on Windows tills, touch-friendly, easy printing/scanners. (WinUI 3 or .NET MAUI are alternatives.) |
| Local database | **SQLite** via EF Core (or Dapper) | Zero install, one file per till, survives power cuts (WAL mode). |
| Sync | **Outbox pattern**: every completed sale/refund/cash/till event is written locally with a `client_uuid` (GUID) in the same local transaction, then a background service posts the outbox to the server in order, retrying until acknowledged | Nothing is lost offline; idempotent on the server. |
| Server | New **`/api/v1/` PHP endpoints** in the web POS (device registration, catalogue pull with `updated_since`, sales/refund/cash/till push with `client_uuid`), reusing `psCompleteSale`-style logic so a synced sale writes exactly the same tables as a web sale | One source of truth; reports keep working. |
| Auth | Each till is **registered once** by an admin (device token, bound to company + branch); cashiers log in offline against locally cached bcrypt hashes (BCrypt.Net) | Works with no internet; revocable. |
| Printing | ESC/POS to thermal printer (e.g. ESCPOS_NET) + QR | Same receipt layout as `zimra_receipt_print.php`. |

## 4. Decisions to make first (they change the design)

1. **Invoice numbers offline.** The web POS uses one global sequence. Offline tills cannot. Options: per-till prefix
   (e.g. `T03-000123`), or a server-allocated number range per till, then map to a server invoice number on sync.
2. **Prices & stock while offline.** Offline sales use the last synced price list; the server should accept the till's
   price for offline sales (unlike web sales) but record it — or reject/flag differences. Stock can go negative on the
   server when two offline tills sell the last item; decide "allow and report" vs "block".
3. **ZIMRA while offline (most important).** A fiscal receipt must be signed by the device that issues it. For a true
   offline till, **the fiscal device (key + certificate + counters) must live on the till PC**, and the desktop app
   signs receipts itself and sends them to ZIMRA when online (the same queue rules as `zimra_receipts.php`), syncing the
   signed result to the server for reports. One ZIMRA device per till (not per branch) is the clean model for this —
   that means a schema change on the server (`zimra_devices` keyed to a till) and ZIMRA's offline/`SubmitFile` mode for
   long outages. Port the signing rules from `includes/zimra.php` exactly (they were verified live on the test server).
4. **What runs offline**: sales, payments, receipts, till open/close, cash in/out — yes. Refund approval, new users,
   price changes, reports — recommend online only (back office stays the web POS).
5. **Which companies / tills first** (pilot), and the minimum Windows version / hardware (scanner, printer, cash drawer).

## 5. Suggested build order

1. **Server API v1** (in this PHP codebase): till registration + token, catalogue/users/tax/customers pull with
   `updated_since`, idempotent sale push (`client_uuid`) that writes the same tables as `psCompleteSale`, acknowledgement
   with the server invoice number. Regression test on a database copy (like `cli_zimra_regression.php`).
2. **Desktop skeleton**: WPF app, SQLite schema mirroring the till-relevant tables, first-run "register this till".
3. **Selling offline**: login, catalogue grid (button groups, variants, sell-by-weight), cart, payment, receipt print,
   till session — all local.
4. **Sync service**: outbox push + catalogue pull, status indicator ("3 sales waiting to sync"), conflict reporting.
5. **ZIMRA on the till** (port signing, fiscal day, queue, QR).
6. Installer (MSIX or Inno Setup), auto-update, backups of the local SQLite file.

## 6. How to start the new conversation

Attach this file (and, if possible, `docs/POS_PROJECT_CONTEXT.md`, `POS_BUSINESS_RULES.md`, `POS_DATABASE_RULES.md`,
`POS_ZIMRA.md`, `POS_PLATFORM.md`) and say, for example:

> "Start the Pockets POS for Windows project described in DOTNET_OFFLINE_POS_BRIEF.md. Visual Studio 2022 with .NET 8 is
> installed. First confirm the decisions in section 4 with me, then begin with section 5 step 1 (server API) and step 2
> (WPF skeleton). My web POS is in C:\xampp\htdocs\pocketsolutions-pos-web; put the new solution in
> C:\Projects\PocketsPOS.Desktop."

If the new conversation is linked to this computer, it can read the web POS code directly from that folder.
