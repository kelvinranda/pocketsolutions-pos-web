# POS Backup, Recovery & Reliability (Phase 15)

Application-implementable backup/recovery for the PHP/mysqli Pocket Solutions POS.  
**Not a claim of off-site DR, encryption-at-rest, or fully automated scheduling** unless separately configured and verified on the host.

## Environment observed (this deployment)

| Item | Value |
|------|--------|
| Engine | MariaDB **10.4.21** (mysqldump Ver 10.19) |
| App DB | configured in `pockets_config.php` (credentials never printed) |
| Tables (approx.) | ~208 in schema |
| Dump tool | `C:\xampp\mysql\bin\mysqldump.exe` |
| Client | `C:\xampp\mysql\bin\mysql.exe` |
| Default private storage | `C:\xampp\pos-private\backups` (outside `htdocs`) |
| Encryption at rest | **Not configured** (no separate key management) |
| Off-site / cloud copy | **Not configured** |
| Scheduler | **Instructions only** — not activated by the app (Phase 16: still **unconfigured** unless verified on host) |

Override storage with env `PS_BACKUP_ROOT` (must remain outside public web dirs). Optional `PS_MYSQLDUMP_PATH` / `PS_MYSQL_PATH`.

## Password migration (mandatory Phase 15 first step)

- Helpers: `psSecurityMigratePlaintextPasswords` / `psSecurityPasswordStorageCounts` in `includes/security.php`.
- CLI: `php cli_migrate_passwords.php` (idempotent; never prints password values).
- Hashes existing plaintext **without changing credentials**; never re-hashes bcrypt/argon; preserves lock/role/branch/identity.
- **Outcome on this DB (2026-10-09):** migrated **22**; already hashed **0**; plaintext remaining **0**; empty password remaining **1** (`user_id=85`, `empty_password_cannot_migrate` — cannot invent a credential; account cannot authenticate).
- Web + mobile continue to use `psSecurityVerifyPassword` (hash path).

## Database backup mechanism

- `psBackupCreateDatabase()` → mysqldump with `--single-transaction --routines --triggers --events --hex-blob`.
- Credentials passed via temporary **defaults-extra-file** (mode 0600), never argv/logs; file deleted after run.
- Exit status checked; empty/incomplete dumps rejected (`psBackupVerifyDumpFile`); SHA-256 recorded.
- Metadata in `backup_jobs` (status, size, checksum, table_count, engine_version, error_summary) — **no credentials / no financial payloads**.
- Concurrent runs blocked via flock lock file under backup root.
- Admin UI: `backup_admin.php` (admin + CSRF). CLI: `cli_backup.php`.

## Retention / storage / protection

- Default keep **14** SUCCESS dumps (`PS_BACKUP_RETENTION_COUNT`).
- Never deletes the only known-good SUCCESS.
- Does **not** silently delete FAILED / VERIFY_FAILED via retention.
- Filename allowlist + path confinement under backup root (no traversal).
- File perms targeted `0600`; root created `0700` with deny `.htaccess` / empty `index.html` (defence in depth).

## Restore & DR

- Integrity check before restore.
- **Isolated restore only:** temp DB `{production}_restore_test` — refuses production DB name.
- CLI: `php cli_restore_test.php [job_id]`; admin “Restore test” button.
- Validates fingerprint counts: users, branches, goods, sales/lines, payments, refunds, cash, stock, tax, accounting batches/exported-sources, invoice seq (no reset/advance as part of test).
- **Actual restore-test result (2026-10-09):** `RESTORE_TEST_OK` — all fingerprint keys **match**; production `sale_invoice_seq` unchanged (**140**); temp DB dropped after test.
- Production overwrite restore is **not** provided by the UI (ops disaster restore = manual mysqldump import after change control).

## Application files recovery

- Manifest via `psBackupAppFilesManifest()` / admin UI notes.
- Include: app PHP, `includes/`, `mobile-endpoints/` (minus secrets), `docs/`, `assets/`, composer lock, uploads if present.
- Exclude: `vendor/` (reinstall), temp/cache/logs, web-tree dumps, config credentials from ordinary downloads.
- Secret restore: sealed ops process for `pockets_config.php` — never exposed via backup download payload metadata.

## Scheduling / admin

| Mode | Status |
|------|--------|
| Manual admin backup | **Implemented** (`backup_admin.php`) |
| CLI backup | **Implemented** (`cli_backup.php`) |
| Windows Task Scheduler / cron | **Not configured** — deploy instruction: `c:\xampp\php\php.exe c:\xampp\htdocs\pocketsolutions-pos-web\cli_backup.php` |
| Missed/failed visibility | `backup_jobs` list + health `backup_last` |

## Health / reliability

- `health.php` — unauthenticated: boolean checks only (db / backup_storage / temp_writable / backup_last). No paths, secrets, or PII.
- Admin `?detail=1`: last backup status + age hours only.
- Financial/inventory paths retain existing txn + rollback / idempotency (sales, refunds, stock, accounting export locks) from prior phases.
- Backup/restore failures logged via `error_log` + `backup_jobs` + `security_audit` actions `BACKUP_*` (no credentials).

## Remaining deployment actions

1. Schedule `cli_backup.php` on the host (Task Scheduler/cron) if automation required — see also `docs/POS_DEPLOYMENT.md`.
2. Copy SUCCESS dumps off-host if off-site recovery required (not done by app).
3. If encryption at rest required: provision OS/volume or tool-level encryption with separate key custody (not claimed here; no app-side key beside backups).
4. Resolve empty-password `user_id=85` via admin set-password workflow (no invented credential).
5. Restrict OS ACLs on `C:\xampp\pos-private` (**not claimed applied** unless verified).
6. Serve POS over HTTPS (Phase 14/16 residual — **not claimed configured**).

## Phase 15 evidence retained

- Isolated restore: `RESTORE_TEST_OK` (2026-10-09).
- Production `sale_invoice_seq` remained **140** through restore test (not reset).

## Residual limitations

- No claim of continuous replication / PITR / cloud DR.
- No encryption-at-rest in application.
- Scheduler not activated by Phase 15/16.
- Ordinary app-file zip archive of whole tree is not auto-produced (manifest + DB dump are the implemented recovery artefacts).
- Browser E2E of backup UI not performed.
- Production overwrite restore is intentionally not offered in UI.
