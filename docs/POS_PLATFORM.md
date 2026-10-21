# Platform: companies, Super Admin, administration for clients (Phase 18)

**Status:** implemented 2026-10-15. Verified in a browser against a copy of the 13 Oct 2026 database
(create / suspend / reactivate company, reset admin password, manage + exit, blocked sale / refund / cash pages,
labelled audit, mobile-width layout). ZIMRA regression still passes.

## Roles

| Layer | Role | Can do |
|-------|------|--------|
| Platform | **Super Admin** (`users.level = 'SuperAdmin'`, `company_name = '__platform__'`) | Companies console: add company (first branch + first admin), edit details, suspend / reactivate, reset a company admin's password, add admins, manage Super Admins, see platform activity. **Manage** a company that has agreed to be administered. |
| Company | Admin | Everything in their company (unchanged). Sees **Provider Access** (whether Pocket Solutions may administer, and every visit). |
| Company | Management / Supervisor / Till | Unchanged (Phase 17). |

## Managing a company ("Administered by Pocket Solutions")

- Allowed only when the company is ACTIVE and `provider_managed = 1` (turned on with an agreement reference).
- Super Admin gives a reason → session becomes that company's admin (`level = admin`, `the_company = company`).
  Banner on every page with **Exit company**. Ends automatically after 4 hours, or when the company is suspended
  or administration is turned off.
- **Refused while managing:** Web POS and its AJAX endpoints, `submit_sale2.php`, till session open/close,
  cash in/out, cash reconciliation, close till, refunds (request / approve / process), sale cancellation.
  Enforced in `includes/page_access.php` and again in `psCompleteSale`, `psRefundMayCreate/Approve`, `psSaleCancelMayAct`.
- Allowed: users, branches, products / prices / stock, purchasing, customers, tax, ZIMRA device set-up and fiscal day,
  reports, settings.
- Every enter / exit / company change is in `platform_audit`. Changes inside the company keep the Super Admin's own
  login as actor, and `security_audit.summary` gets ` [by Pocket Solutions for <company>]`.

## Data

- `companies` — one row per client. `company_name` (utf8mb4_bin, unique) is the key every other table already uses;
  `display_name` is editable. `status` ACTIVE | SUSPENDED. `provider_managed`, `managed_since`, `managed_reference`.
  Existing companies are registered automatically from `users` / `branchies`.
- `platform_audit` — actor, company, action, reason, detail, IP.
- SQL for an existing database: `db/2026-10-15_phase17_18_zimra_platform.sql` (idempotent, MariaDB 10.3+).

## First Super Admin

`php cli_create_super_admin.php --login=pocketsadmin --first=Name --last=Surname` (asks for the password), or promote
a normal user with the commented `UPDATE` in the SQL file. Further Super Admins: console → **Super Admins**.

## Limitations

- `security_audit_history.php` is not filtered by company (pre-existing): a company admin can see other companies'
  security events. Follow-up recommended.
- Company name (key) cannot be renamed; change the display name instead.
- No separate read-only "Support" role yet.
