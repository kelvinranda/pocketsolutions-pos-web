# POS Security & Audit (Phase 14 + Phase 16 hardening)

Application-implementable controls for the PHP/mysqli Pocket Solutions POS.  
**Not a claim of “fully secure” or external certification.** Browser/pen-test not performed unless separately recorded.  
**Production deployment is not claimed** unless verified on a live host (see `docs/POS_DEPLOYMENT.md`).

## Model

| Area | Behaviour |
|------|-----------|
| Passwords | `password_hash` / `password_verify` (`includes/security.php`). Phase 15 mass-migrated legacy plaintext (idempotent CLI). Login still upgrades any residual plaintext. Empty passwords rejected / not inventable. |
| Sessions | `session_regenerate_id` on login / privilege sync (when headers allow). Inactivity timeout 600s. Live user revalidation via `psSecurityEnforceWebSession` / `session_check.php` (missing/locked → logout). Level/branch/company synced from DB (not from POST). |
| Logout | `logout.php` destroys session + cookie. Nav “Log out” points here (not bare `login.php`). |
| Cookies | `httponly` + `SameSite=Lax`; `Secure` only when request is HTTPS (**deployment-dependent** — HTTPS required for production; not claimed configured). |
| CSRF | Session token (`ps_csrf`, 64 hex). Validate via POST `csrf_token` or `X-CSRF-Token`. Independent of authz. Not placed in URLs/logs. Login POST requires CSRF (token minted on form render). |
| Branch ACL | Unchanged Phase 9 helpers (`includes/branch_access.php`): ordinary = home; admin = registered `branchies`. |
| Locks | `users.lock_status = locked` blocks web + mobile auth; admin UI `user_lock.php`. |
| Mobile | Token auth; expired tokens rejected; locked/missing users invalidate token. Password verify matches web. **No browser-session CSRF** on Bearer endpoints (by design). |
| Audit | `security_audit` table — append-oriented app logging (**not** claimed tamper-proof). Passwords/tokens redacted. Admin viewer `security_audit_history.php`. |
| Destructive reset | `settings.php` new-install truncate: admin + CSRF + typed phrase `RESET PRODUCTS AND SALES`. |
| ZIMRA (Phase 17) | Device private keys / activation keys encrypted with the master key (`pos-private\zimra\master.key` or `zimra_master_key`). `zimra_api_log` masks activation key, CSR and certificate. Set-up admin-only; fiscal day Supervisor/Management/admin. Audit: `ZIMRA_*` in `security_audit` + append-only `zimra_audit`. |
| Config | Phase 16: credentials load from env (`PS_DB_*`) or private file outside webroot; example placeholders only in `config/pockets_config.example.php`. |

## CSRF coverage inventory (verified in code)

**Gated (reject missing/invalid before mutation):**  
`login.php` (POST required), `set_branch.php`, `update_branch.php`, `create_user.php`, `user_lock.php`, `change_password.php`, `settings.php`, `import_items.php`, `export.php`, `submit_sale2.php`, `open_session.php`, `ajax_pos_discard_cart.php`, `refund.php`, `tax_rates.php`, `accounting_mappings.php`, `accounting_preview.php` (export), `accounting_batches.php` (reverse), `cash_movement.php`, `cash_reconciliation.php`, `cash_till_close.php`, `create_items.php`, `items_list.php`, `stock_adjustment.php`, `stock_movement.php`, `opening_stock.php`, `stock_migration.php`, `grv.php`, `barcode_labels.php`, `new_expense.php`, `create_customer.php`, `customers.php` (add), `create_supplier.php`, `create_purchase_order.php`, `create_purchase_return.php`, `purchase_order_view.php`, `create_customer_account.php`, `create_customer_account_payment.php`, `customer_account_payment_allocate.php`, `customer_view.php`, `customer_collection_activity.php`, `customer_accounts_list.php`, `customers_list.php`, `suppliers_list.php` (status **and** update), `customer_account_view.php` (status / credit hold / limit / opening balance), `customer_account_payment_view.php` (void), `backup_admin.php` (create/download/delete/restore-test/retention), `refund_reconciliation.php`, `movement_list.php` (filter POST).

**AJAX:** global header/`csrf_token` injection via `ps_scripts()` when jQuery/`fetch` used after layout scripts.

**Disabled / retired writers (Phase 16 + earlier):**  
`update_stock.php`, `printed.php`, `save_online_sales_ref.php`, `online_items.php`, `online_grv_ref.php`, `online_grv_singles.php`, `submit_sales.php`, `submit_grv.php` — HTTP 403; no mutations.

**Out of CSRF model (by design):** Mobile/API Bearer token endpoints (own auth model). Read-only GETs. Read-only filter POSTs (`stock_list.php`, `expenses.php`, `income_statement.php`, `all_receipts.php`, `users.php` search, `suggestions.php`).

**Residual gaps / limitations:** Some legacy pages may still use `session_start()` without full live lock revalidation on every hit (timeout + CSRF minting applied when `psBranchRequireAuthRedirect` / `psSecurityEnforceWebSession` / `session_check.php` is used). Dup-submit tokens alone are **not** CSRF. App-wide “every historical endpoint” absolute completeness is not claimed beyond this inventory.

## Security audit actions (implemented)

`LOGIN_SUCCESS`, `LOGIN_FAIL`, `LOGIN_DENIED`, `LOGOUT`, `MOBILE_LOGIN_SUCCESS`, `MOBILE_LOGIN_FAIL`, `PASSWORD_CHANGE`, `PASSWORD_CHANGE_FAIL`, `PASSWORD_MIGRATION`, `USER_CREATE`, `USER_BRANCH_ASSIGN`, `USER_LOCK`, `USER_UNLOCK`, `BRANCH_SWITCH`, `BRANCH_SWITCH_DENIED`, `SESSION_DENIED`, `SESSION_PRIVILEGE_SYNC`, `TAX_ENFORCEMENT`, `TAX_RATE_CREATE`, `TAX_RATE_UPDATE`, `ACCOUNTING_MAPPINGS_SAVE`, `ACCOUNTING_EXPORT`, `ACCOUNTING_REVERSE`, `PRODUCT_IMPORT`, `DESTRUCTIVE_RESET`, `TILL_OPEN`, `EXPENSE_CREATE`, `SECURITY_DENY`, `BACKUP_CREATE`, `BACKUP_DELETE`, `BACKUP_DOWNLOAD`, `BACKUP_RETENTION`, `BACKUP_RESTORE_TEST`.

Domain audits from earlier phases remain: product, purchasing, cash, refund, tax, customer, etc.

### Remaining gaps

- Not every stock/GRV/PO line mutation writes to `security_audit` (domain audits often cover).
- No WORM/off-host immutable store.
- No fabricated historical backfill.
- Not every legacy read/report page calls live privilege revalidation (financial mutation paths prioritized in Phase 16).

## Deployment actions still required (ops — not done in app alone)

1. Serve POS over **HTTPS** so session `Secure` cookies apply (**not claimed configured**).
2. Confirm private config / env vars on each host; rotate DB password if historically exposed under webroot.
3. Confirm `display_errors=0` in production `php.ini` (app already sets off in config bootstrap).
4. Phase 15 mass-migrated non-empty plaintext passwords (plaintext remaining **0**). Resolve empty-password `user_id=85` via admin set-password (no invented credential).
5. Review/revoke existing `mobile_tokens` if credentials may have leaked.
6. Restrict filesystem / OS ACLs to `vendor/`, private config, and `C:\xampp\pos-private\backups` (**ACLs not claimed applied** unless verified).
7. Optional: force password change policy / minimum length beyond create/change forms.
8. Schedule `cli_backup.php` and off-site copy if required (not activated by app alone).
9. Confirm Apache `AllowOverride` (or nginx equivalents) so `.htaccess` deny rules apply.

## Residual risks

- One empty-password user remains (cannot authenticate; not plaintext credential storage).
- CSRF inventory residual gaps may remain on obscure legacy filter-only POSTs; critical unauthenticated writers found in Phase 16 were disabled.
- `security_audit` is DB-writable by anyone with DB access — not tamper-proof.
- No browser/pen-test/external cert performed in Phase 14/15/16.
- Phase 9 historical orphan branches / NULL OPEN tills policies unchanged.
- `settings.php` destructive reset still exists (gated) — operational risk if misused.
- Backup encryption-at-rest / off-site / host scheduler not claimed active.
- Composer dependency advisories should be reviewed periodically on the deploy host (`composer audit` when available).
