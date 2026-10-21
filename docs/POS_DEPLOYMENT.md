# POS Production Deployment & Operations (Phase 16)

Application-implementable production hardening for the PHP/mysqli Pocket Solutions POS.  
**This document does not claim the app has been deployed to a live production host** unless separately verified.

## Supported runtime (observed / required by code)

| Item | Guidance |
|------|----------|
| PHP | **8.2.x** observed on this XAMPP host (`8.2.12`). Target **8.1+** with mysqli. |
| Extensions | `mysqli`, `json`, `mbstring`, `openssl`, `curl`, `gd`, `zip`, `fileinfo`, `xml` / SimpleXML, `ctype`, `tokenizer`, `iconv`, `pdo_mysql` (Composer deps). |
| Database | **MariaDB 10.4.x** observed (Phase 15). InnoDB assumed for transactions / `FOR UPDATE`. |
| Web server | Apache (XAMPP) with `AllowOverride` so root `.htaccess` applies; or equivalent nginx deny rules. |
| Composer deps | See root `composer.json` / `composer.lock`. Reinstall with `composer install --no-dev` on deploy hosts. `vendor/` HTTP access denied. **Composer CLI audit was NOT VERIFIED on this host** (CLI unavailable); review advisories before upgrading. Wildcard pins (`guzzlehttp/guzzle: *`, `ergebnis/json-printer: *`) left unchanged pending compatible lock review — no blind vendor edits. |

## Deploy order

1. Provision PHP + MariaDB/MySQL + Apache/nginx with HTTPS termination (**HTTPS is a deployment requirement**; not claimed configured here).
2. Place application files outside casual write paths; keep `vendor/` installable via Composer (HTTP access denied via `.htaccess`).
3. Create private config **outside webroot**, e.g. `C:\xampp\pos-private\pockets_config.php` (see `config/pockets_config.example.php`) **or** set `PS_DB_HOST` / `PS_DB_USER` / `PS_DB_PASS` / `PS_DB_NAME` (optional `PS_CONFIG_FILE`).
4. Ensure `display_errors=0` in `php.ini` (app also forces off in config bootstrap).
5. Run schema/migrations only via controlled admin/CLI paths from prior phases — **do not** enable destructive auto-schema on ordinary customer requests beyond existing ensure-* helpers that are additive/`CREATE IF NOT EXISTS`.
6. Set filesystem permissions: web user write only where needed (`uploads/`, logs if used, export temp, `C:\xampp\pos-private\backups`). **OS ACLs not claimed applied** unless verified on the host.
7. Confirm Apache reads project `.htaccess` (denies config, vendor, docs tree, `.git`, SQL/logs, `_test_*`, `cli_*`).
8. Post-deploy verification checklist (below).

## Secrets & cookies

- Never commit real DB passwords. Rotate DB password if it was ever stored under `htdocs`.
- Session cookies: `HttpOnly` + `SameSite=Lax`; `Secure` **only when the request is HTTPS** (local HTTP XAMPP still works).
- Do not print `pockets_config` credentials, SQL, stack traces, or filesystem paths to end users.

## Migrations / schema

- Prior phases use additive `psEnsure*Schema` helpers. Prefer running once under admin/CLI after deploy.
- Failures should surface as controlled user messages; operators check PHP `error_log` (without expecting passwords/tokens in logs).
- Never reset `sale_invoice_seq` during deploy or testing.

## Backup / retention / restore

See `docs/POS_BACKUP.md` (Phase 15). Summary:

| Control | Status |
|---------|--------|
| CLI backup `php cli_backup.php` | Implemented (CLI-only) |
| Admin UI `backup_admin.php` | Admin + CSRF |
| Retention | SUCCESS dumps only; never sole known-good |
| Isolated restore test | Temp DB only — refuses production name |
| Windows Task Scheduler / Linux cron | **Instructions only — not configured unless verified** |
| Off-site copy + encryption-at-rest | **Ops controls** — need infra + separate key custody; not claimed active |

### Windows Task Scheduler (example — not activated by app)

`c:\xampp\php\php.exe c:\xampp\htdocs\pocketsolutions-pos-web\cli_backup.php`

### Linux cron (example — not activated by app)

`15 2 * * * /usr/bin/php /var/www/pocketsolutions-pos-web/cli_backup.php`

## Health / logging / troubleshooting

- `health.php` — unauthenticated booleans only; admin `?detail=1` adds last backup status/age.
- Mobile `mobile-endpoints/mobile_api/health.php` — reachability boolean only (no DB name/counts).
- User-facing errors: actionable, no SQL/stack/paths/config.
- Server log via `error_log` / `psConfigSafeErrorLog` — no credential dumps.

## Post-deploy validation

- [ ] Login works over intended URL scheme (HTTP local / HTTPS production).
- [ ] `health.php` returns ok when DB + backup storage writable.
- [ ] Admin backup create + isolated restore test (non-production temp DB).
- [ ] Ordinary user cannot switch to unregistered / foreign branches.
- [ ] Locked user cannot keep an open session after revalidation.
- [ ] CSRF rejects forged POSTs on inventory-gated forms.
- [ ] Mobile token login still works (**no browser CSRF on Bearer endpoints**).
- [ ] `sale_invoice_seq` unchanged by deploy/tests unless a real sale posts.
- [ ] Confirm empty-password `user_id=85` resolved via admin set-password (no invented credential).

## ZIMRA fiscalisation (Phase 17)

- PHP extensions also required: `openssl`, `curl` (mutual TLS). Outbound HTTPS to `fdmsapi.zimra.co.zw` / `fdmsapitest.zimra.co.zw`.
- Back up `pos-private\zimra\master.key` (or set `zimra_master_key` in the private config) with the other secrets.
- Schedule `cli_zimra_worker.php` every 1–5 minutes, e.g. `c:\xampp\php\php.exe c:\xampp\htdocs\pocketsolutions-pos-web\cli_zimra_worker.php`.
- Device set-up and operations: `docs/POS_ZIMRA.md`.

## Residual operator actions

1. Enable HTTPS + HSTS at the reverse proxy / Apache vhost.
2. Rotate DB credentials after moving them out of historical webroot copies.
3. Schedule backups; copy SUCCESS dumps off-host; encrypt with separate key custody if required.
4. Restrict OS ACLs on `pos-private`.
5. Review Composer advisories periodically (`composer audit` when Composer CLI available).
6. Disable or remove unused XAMPP services; keep `display_errors` off in production `php.ini`.
7. Browser E2E / pen-test / hardware peripheral certification — not performed in Phase 16 unless separately recorded.
