# ZIMRA FDMS Fiscalisation (Phase 17)

**Status:** implemented 2026-10-15. Signing, receipt chaining, QR / verification code and CloseDay counters were
proven live on the ZIMRA **test** server (device 39308: fiscal day 1, receipts 11908389 + 11908393, closed with
Auto reconciliation). The POS integration (sales, refunds, pages, worker) is verified by `cli_zimra_regression.php`
against a database copy with a simulated FDMS that checks signatures, the hash chain and counters.
**Not claimed:** production ZIMRA device, VAT-registered taxpayer, offline (SubmitFile) mode, thermal-printer QR.

## Model

```text
zimra_devices (one per branch + environment; key encrypted, certificate, GetConfig snapshot, last day / global no)
  ├─ zimra_taxes        (GetConfig applicableTaxes)
  ├─ zimra_tax_map      (POS tax_rates.code → ZIMRA taxID; '' = products with no tax code)
  ├─ zimra_fiscal_days  (OPEN → CLOSE_PENDING → CLOSED | CLOSE_FAILED; open_state PENDING → CONFIRMED)
  ├─ zimra_receipts     (FiscalInvoice per sale, CreditNote per processed refund; signed JSON, status, QR)
  ├─ zimra_api_log      (every FDMS call; activation key / CSR / certificate masked)
  └─ zimra_audit        (append-only set-up and day actions)
```

Tables are created by `psEnsureZimraModule()` (`includes/zimra.php`) — additive, `CREATE TABLE IF NOT EXISTS`.

## Flow

| Event | What happens | Network |
|-------|--------------|---------|
| Sale (`submit_sale2.php`) | Inside the sale transaction: lock device row, find open fiscal day (auto-open locally if none), allocate receipt counter + global number, sign, store `PENDING`, add fiscal block to the print job. Any failure rolls back the whole sale. | After commit: OpenDay (if pending) + SubmitReceipt, 8 s timeout |
| Offline | Sale completes; receipt stays `PENDING` with a valid verification code; cashier is told it will be sent later. | `cli_zimra_worker.php` resends in global-number order |
| Refund PROCESSED (`psProcessApprovedRefund`) | CreditNote for exactly the processed lines/quantities, same money type/currency as the original; reason = refund reason. References the original by `receiptID` (or device + global no + fiscal day before it is accepted). Legacy (never fiscalised) sales are skipped. | After commit |
| Fiscal day limit | Sales and refunds are refused once `taxPayerDayMaxHrs` has passed until the day is closed. Warning in the sale message and worker within `taxpayerDayEndNotificationHrs`. | — |
| Close day (`zimra_day.php`) | Sends pending receipts, builds + signs counters (SaleByTax, SaleTaxByTax, CreditNote*, BalanceByMoneyType; zero counters omitted), CloseDay, polls GetStatus. Still processing → `CLOSE_PENDING`; worker finishes. | Yes |

Payment methods → FDMS money types: CASH→Cash, CARD→Card, ECOCASH→MobileWallet, BANK_TRANSFER→BankTransfer,
CREDIT→Credit, OTHER→Other. Currency = `sale_payments.currency`.

Line rule: FDMS requires price × quantity = line total. When the POS line total is not exactly `unit_price × qty`
(exclusive tax pricing, odd decimal quantities) the line is sent as 1 unit at the line total, named `Item (x3)`.
Tax amounts on the fiscal receipt use the FDMS formula per tax group (`sales × rate / (100 + rate)`), which can differ
by a cent from the POS per-line tax report.

## Roles (case-insensitive)

| Role | ZIMRA | Other access added in Phase 17 |
|------|-------|-------------------------------|
| admin | Device set-up, keys, tax mapping, on/off; day; reports | — |
| Management | Fiscal day, fiscal receipts report | Refund approval; read-only dashboard / sales / Z / inventory / tax / cash / refund-recon reports (own working branch) |
| Supervisor | Fiscal day | Refund approval |
| Till | Sales are fiscalised automatically; can print fiscal receipts | — |

Page lists: `psPageAccessSupervisorPages()` / `psPageAccessManagementPages()` in `includes/page_access.php`.

## Secrets

- Device private keys and activation keys are encrypted (OpenSSL AES-256-GCM) with a 32-byte master key:
  private config `zimra_master_key` (base64) / env `PS_ZIMRA_MASTER_KEY`, else `pos-private\zimra\master.key`
  (created on first use). **Back the master key up with the private config.** Losing it means re-registering devices.
- The key is decrypted only in memory for signing / mutual TLS (curl PEM blobs; temp 0600 files on old libcurl).

## Operations

1. Admin → **ZIMRA Device Setup** → add device (branch, environment, device ID, serial, model name/version from the
   ZIMRA portal) → Register (activation key) **or** Import an existing key + certificate.
2. Check taxes synced; map POS tax codes if more than one ZIMRA tax → **Turn fiscalisation on**.
3. Schedule `cli_zimra_worker.php` every 1–5 minutes (Task Scheduler / cron). Exit code 1 = warnings printed.
4. Supervisor closes the fiscal day at the end of trading (**ZIMRA Fiscal Day**).
5. Before `certificate_valid_till`: **Renew certificate** (worker warns 30 days ahead).

Regression (database copy only): `php cli_zimra_regression.php --on-a-database-copy`.

## Limitations

- Mobile `sync_sales.php` remains fail-closed (no fiscal path for mobile sales).
- FDMS offline mode / `SubmitFile` not implemented — the device stays in Online mode; receipts queue locally.
- A day opened locally while offline is confirmed later with its original open time; if ZIMRA rejects it the day page
  shows the error and receipts wait.
- Print agent (`get_receipt.php`) prints text: verification code + link, no QR image. Browser receipt shows the QR.
- HS codes are not sent (non-VAT test taxpayer). VAT-registered taxpayers need `goods` HS codes — future change.
- Buyer (TIN/VAT) details on invoices not captured.
