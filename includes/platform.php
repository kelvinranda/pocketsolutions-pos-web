<?php
/**
 * Phase 18 — Platform layer: companies register, Super Admin (Pocket Solutions staff) and
 * delegated administration ("Administered by Pocket Solutions").
 *
 *  - companies.company_name is the fixed key that every existing table already uses
 *    (users.company_name, branchies.company_name, ...). It is never renamed; display_name is editable.
 *  - Super Admins are users with level 'SuperAdmin' and company_name '__platform__'. They belong to no
 *    company, so company admins never see or edit them.
 *  - A Super Admin may "manage" a company only when that company is ACTIVE and provider_managed = 1.
 *    While managing, the session acts as that company's admin (level 'admin', the_company = company),
 *    and $_SESSION['ps_platform'] records who and why. Every enter/exit is in platform_audit.
 *  - While managing, sales, refunds, sale cancellation and till/cash operations are refused
 *    (page gate + library guards). Those stay with the client's own staff.
 *  - Users of a SUSPENDED company cannot sign in (web or mobile); open sessions end on the next request.
 */

if (!defined('PS_PLATFORM_LEVEL')) {
    define('PS_PLATFORM_LEVEL', 'SuperAdmin');
}
if (!defined('PS_PLATFORM_COMPANY')) {
    /** users.company_name for Super Admin accounts (matches no real company). */
    define('PS_PLATFORM_COMPANY', '__platform__');
}
if (!defined('PS_PLATFORM_PROVIDER_NAME')) {
    define('PS_PLATFORM_PROVIDER_NAME', 'Pocket Solutions');
}
if (!defined('PS_PLATFORM_ACT_MAX_SECONDS')) {
    /** A managing session ends automatically after this long (re-enter to continue). */
    define('PS_PLATFORM_ACT_MAX_SECONDS', 4 * 3600);
}

if (!function_exists('psPlatformIsSuperAdminLevel')) {

    function psPlatformIsSuperAdminLevel(string $level): bool
    {
        $l = strtolower(str_replace([' ', '_', '-'], '', trim($level)));
        return $l === 'superadmin';
    }

    /** The signed-in person is a Super Admin (whether or not they are managing a company). */
    function psPlatformSessionIsSuperAdmin(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        return psPlatformIsSuperAdminLevel((string)($_SESSION['ps_real_level'] ?? $_SESSION['level'] ?? ''));
    }

    /** Managing context or null: ['company','display','since','reason','actor']. */
    function psPlatformActing(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $a = $_SESSION['ps_platform'] ?? null;
        if (!is_array($a) || trim((string)($a['company'] ?? '')) === '') {
            return null;
        }
        if (!psPlatformIsSuperAdminLevel((string)($_SESSION['ps_real_level'] ?? ''))) {
            return null;
        }
        return $a;
    }

    function psPlatformIsActing(): bool
    {
        return psPlatformActing() !== null;
    }

    /** Message used wherever a managing Super Admin is refused a money action. */
    function psPlatformMoneyBlockedMessage(): string
    {
        return PS_PLATFORM_PROVIDER_NAME . ' staff cannot make sales, refunds or till/cash entries for a client. '
            . 'These must be done by the company\'s own staff.';
    }

    /** Pages refused while managing a company (sales, refunds, cancellation, till sessions, cash in/out). */
    function psPlatformBlockedPagesWhileActing(): array
    {
        return [
            'ajax_pos.php',
            'ajax_pos_barcode.php',
            'ajax_pos_customer_account.php',
            'ajax_pos_customer_search.php',
            'ajax_pos_discard_cart.php',
            'fetch_pos_catalog.php',
            'submit_sale2.php',
            'open_session.php',
            'close_session.php',
            'cash_movement.php',
            'cash_reconciliation.php',
            'cash_till_close.php',
            'refund.php',
        ];
    }

    /** Pages for a Super Admin who is not managing a company. */
    function psPlatformConsolePages(): array
    {
        return [
            'platform.php',
            'platform_company.php',
            'platform_admins.php',
            'platform_activity.php',
            'platform_units.php',
            'platform_currencies.php',
            'platform_zimra_devices.php',
            'platform_desktop_releases.php',
            'platform_exit.php',
            'change_password.php',
        ];
    }

    // ------------------------------------------------------------------ schema

    function psPlatformTableExists(mysqli $conn, string $table): bool
    {
        $t = mysqli_real_escape_string($conn, $table);
        $res = @mysqli_query($conn, "SHOW TABLES LIKE '" . $t . "'");
        $ok = $res && mysqli_num_rows($res) > 0;
        if ($res) {
            mysqli_free_result($res);
        }
        return $ok;
    }

    /**
     * Creates companies + platform_audit (additive) and adds any company names in use that are not
     * registered yet. Safe to call repeatedly; called by platform pages, not on every request.
     */
    function psPlatformEnsureSchema(mysqli $conn): bool
    {
        static $done = false;
        if ($done) {
            return true;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $ok = @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS companies (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            display_name VARCHAR(250) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
            provider_managed TINYINT(1) NOT NULL DEFAULT 0,
            managed_since DATETIME NULL,
            managed_reference VARCHAR(250) NULL,
            contact_name VARCHAR(250) NULL,
            contact_email VARCHAR(250) NULL,
            contact_phone VARCHAR(100) NULL,
            notes TEXT NULL,
            suspended_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_at DATETIME NULL,
            updated_by VARCHAR(250) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_companies_name (company_name),
            KEY idx_companies_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $ok = $ok && @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS platform_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME NOT NULL,
            actor_user_id INT NULL,
            actor VARCHAR(250) NOT NULL DEFAULT '',
            company_name VARCHAR(250) NULL,
            action_code VARCHAR(64) NOT NULL,
            reason VARCHAR(500) NULL,
            detail_json TEXT NULL,
            ip_address VARCHAR(64) NULL,
            PRIMARY KEY (id),
            KEY idx_platform_audit_company (company_name, occurred_at),
            KEY idx_platform_audit_time (occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        if (!$ok) {
            error_log('platform: schema create failed');
            return false;
        }
        psPlatformBackfillCompanies($conn);
        $done = true;
        return true;
    }

    /** Register every company name already used by users / branchies (INSERT IGNORE). */
    function psPlatformBackfillCompanies(mysqli $conn): void
    {
        $now = date('Y-m-d H:i:s');
        $p = mysqli_real_escape_string($conn, PS_PLATFORM_COMPANY);
        $sources = ["SELECT DISTINCT TRIM(company_name) AS n FROM users"];
        if (function_exists('psBranchRegistryHasCompany') ? psBranchRegistryHasCompany($conn) : false) {
            $sources[] = "SELECT DISTINCT TRIM(company_name) AS n FROM branchies";
        } else {
            $res = @mysqli_query($conn, "SHOW COLUMNS FROM branchies LIKE 'company_name'");
            if ($res && mysqli_num_rows($res) > 0) {
                $sources[] = "SELECT DISTINCT TRIM(company_name) AS n FROM branchies";
            }
            if ($res) {
                mysqli_free_result($res);
            }
        }
        $sql = "INSERT IGNORE INTO companies (company_name, display_name, status, provider_managed, created_at, created_by)
                SELECT CONVERT(x.n USING utf8mb4), CONVERT(x.n USING utf8mb4), 'ACTIVE', 0, '" . $now . "', 'backfill'
                FROM (" . implode(' UNION ', $sources) . ") x
                WHERE x.n IS NOT NULL AND x.n <> '' AND x.n <> '" . $p . "'";
        if (!@mysqli_query($conn, $sql)) {
            error_log('platform: company backfill failed');
        }
    }

    // ------------------------------------------------------------------ companies

    function psPlatformCompanyByName(mysqli $conn, string $name): ?array
    {
        static $tableOk = null;
        if ($tableOk === null) {
            $tableOk = psPlatformTableExists($conn, 'companies');
        }
        if (!$tableOk || trim($name) === '') {
            return null;
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM companies WHERE company_name = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $n = trim($name);
        mysqli_stmt_bind_param($stmt, 's', $n);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }

    function psPlatformCompanyById(mysqli $conn, int $id): ?array
    {
        if ($id < 1 || !psPlatformTableExists($conn, 'companies')) {
            return null;
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM companies WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }

    /**
     * Suspended companies cannot sign in. Unknown company (no companies table yet, or not registered) = allowed,
     * so an install that has not run the migration keeps working.
     */
    function psPlatformCompanyIsSuspended(mysqli $conn, string $companyName): bool
    {
        $c = psPlatformCompanyByName($conn, $companyName);
        return $c !== null && strtoupper((string)$c['status']) === 'SUSPENDED';
    }

    function psPlatformCompanyLabel(array $c): string
    {
        $d = trim((string)($c['display_name'] ?? ''));
        return $d !== '' ? $d : (string)$c['company_name'];
    }

    // ------------------------------------------------------------------ audit

    function psPlatformAudit(mysqli $conn, string $action, ?string $company = null, string $reason = '', array $detail = []): bool
    {
        if (!psPlatformTableExists($conn, 'platform_audit') && !psPlatformEnsureSchema($conn)) {
            return false;
        }
        $at = date('Y-m-d H:i:s');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $actor = substr(trim((string)($_SESSION['logger'] ?? ($detail['actor'] ?? 'system'))), 0, 250);
        $action = substr(strtoupper(trim($action)), 0, 64);
        $reason = substr(trim($reason), 0, 500);
        $json = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO platform_audit (occurred_at, actor_user_id, actor, company_name, action_code, reason, detail_json, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            error_log('platform: audit prepare failed');
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sissssss', $at, $uid, $actor, $company, $action, $reason, $json, $ip);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (bool)$ok;
    }

    // ------------------------------------------------------------------ session

    /**
     * Called by psSecurityValidateSessionUser / login for a Super Admin row. Sets the effective session
     * (console, or managing a company as its admin). Ends managing when the company is no longer
     * ACTIVE + provider_managed, or after PS_PLATFORM_ACT_MAX_SECONDS. Returns the effective level.
     */
    function psPlatformApplySession(?mysqli $conn, array $user): string
    {
        $_SESSION['ps_real_level'] = PS_PLATFORM_LEVEL;
        $acting = $_SESSION['ps_platform'] ?? null;
        if (is_array($acting) && trim((string)($acting['company'] ?? '')) !== '') {
            $endReason = '';
            if ((time() - (int)($acting['since'] ?? 0)) > PS_PLATFORM_ACT_MAX_SECONDS) {
                $endReason = 'time limit reached';
            } elseif ($conn instanceof mysqli) {
                $c = psPlatformCompanyByName($conn, (string)$acting['company']);
                if ($c === null) {
                    $endReason = 'company not found';
                } elseif (strtoupper((string)$c['status']) !== 'ACTIVE') {
                    $endReason = 'company suspended';
                } elseif ((int)$c['provider_managed'] !== 1) {
                    $endReason = 'company no longer administered by ' . PS_PLATFORM_PROVIDER_NAME;
                }
            }
            if ($endReason === '') {
                $_SESSION['level'] = 'admin';
                $_SESSION['the_company'] = (string)$acting['company'];
                $_SESSION['branch'] = '';
                return 'admin';
            }
            if ($conn instanceof mysqli) {
                psPlatformAudit($conn, 'MANAGE_END', (string)$acting['company'], $endReason, ['automatic' => true]);
            }
            unset($_SESSION['ps_platform'], $_SESSION['selected_branch']);
            $_SESSION['ps_platform_notice'] = 'You left ' . (string)($acting['display'] ?? $acting['company']) . ': ' . $endReason . '.';
        }
        $_SESSION['level'] = PS_PLATFORM_LEVEL;
        $_SESSION['the_company'] = PS_PLATFORM_COMPANY;
        $_SESSION['branch'] = '';
        return PS_PLATFORM_LEVEL;
    }

    /** Start managing a company. Returns ['ok'=>bool,'error'?]. Caller has validated CSRF. */
    function psPlatformEnter(mysqli $conn, int $companyId, string $reason): array
    {
        if (!psPlatformSessionIsSuperAdmin()) {
            return ['ok' => false, 'error' => 'Only ' . PS_PLATFORM_PROVIDER_NAME . ' Super Admins can do this.'];
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return ['ok' => false, 'error' => 'Say briefly why you are going in (at least 5 characters), e.g. "Add new cashier".'];
        }
        $c = psPlatformCompanyById($conn, $companyId);
        if (!$c) {
            return ['ok' => false, 'error' => 'Company not found.'];
        }
        if (strtoupper((string)$c['status']) !== 'ACTIVE') {
            return ['ok' => false, 'error' => 'This company is suspended. Reactivate it first.'];
        }
        if ((int)$c['provider_managed'] !== 1) {
            return ['ok' => false, 'error' => 'This company manages itself. Turn on "Administered by ' . PS_PLATFORM_PROVIDER_NAME . '" first (only with the client\'s agreement).'];
        }
        $prev = psPlatformActing();
        if ($prev) {
            psPlatformAudit($conn, 'MANAGE_END', (string)$prev['company'], 'switched to another company');
        }
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        unset($_SESSION['selected_branch']);
        $_SESSION['ps_platform'] = [
            'company' => (string)$c['company_name'],
            'display' => psPlatformCompanyLabel($c),
            'company_id' => (int)$c['id'],
            'since' => time(),
            'reason' => mb_substr($reason, 0, 500),
        ];
        $_SESSION['level'] = 'admin';
        $_SESSION['the_company'] = (string)$c['company_name'];
        $_SESSION['branch'] = '';
        if (function_exists('psCsrfRotate')) {
            psCsrfRotate();
        }
        psPlatformAudit($conn, 'MANAGE_START', (string)$c['company_name'], $reason);
        return ['ok' => true];
    }

    function psPlatformExit(mysqli $conn): void
    {
        $a = psPlatformActing();
        if ($a) {
            $mins = (int)round((time() - (int)($a['since'] ?? time())) / 60);
            psPlatformAudit($conn, 'MANAGE_END', (string)$a['company'], 'left', ['minutes' => $mins]);
        }
        unset($_SESSION['ps_platform'], $_SESSION['selected_branch']);
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['level'] = PS_PLATFORM_LEVEL;
        $_SESSION['the_company'] = PS_PLATFORM_COMPANY;
        $_SESSION['branch'] = '';
        if (function_exists('psCsrfRotate')) {
            psCsrfRotate();
        }
    }

    // ------------------------------------------------------------------ users

    /** Login ID already used by any user (case-insensitive). */
    function psPlatformLoginIdTaken(mysqli $conn, string $loginId): bool
    {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1');
        if (!$stmt) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 's', $loginId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $taken = $res && mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        return (bool)$taken;
    }

    /**
     * Insert a user row (company admin or Super Admin). Validates login ID, password length, uniqueness.
     * @return array{ok:bool,error?:string,id?:int}
     */
    function psPlatformCreateUser(mysqli $conn, array $u): array
    {
        $first = trim((string)($u['firstname'] ?? ''));
        $last = trim((string)($u['lastname'] ?? ''));
        $phone = trim((string)($u['phonenumber'] ?? ''));
        $level = (string)($u['level'] ?? '');
        $company = trim((string)($u['company_name'] ?? ''));
        $branch = trim((string)($u['branch'] ?? ''));
        $plain = (string)($u['password'] ?? '');
        if ($first === '' || $last === '' || $company === '') {
            return ['ok' => false, 'error' => 'First name and last name are required.'];
        }
        $chk = psSecurityValidateLoginId((string)($u['login_id'] ?? ''));
        if (!$chk['ok']) {
            return ['ok' => false, 'error' => $chk['error']];
        }
        $login = $chk['login_id'];
        if (strlen($plain) < PS_PASSWORD_MIN_LENGTH) {
            return ['ok' => false, 'error' => 'Password must be at least ' . PS_PASSWORD_MIN_LENGTH . ' characters.'];
        }
        if (psPlatformLoginIdTaken($conn, $login)) {
            return ['ok' => false, 'error' => 'That email or username is already used by another user.'];
        }
        psSecurityEnsureUsersPasswordColumn($conn);
        $hash = psSecurityHashPassword($plain);
        $phone = $phone !== '' ? $phone : 'NA';
        $lock = 'Allowed';
        $status = 'Posted';
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO users (firstname, lastname, email, password, level, phonenumber, company_name, branch, lock_status, status, date_created)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'User could not be created.'];
        }
        mysqli_stmt_bind_param($stmt, 'ssssssssss', $first, $last, $login, $hash, $level, $phone, $company, $branch, $lock, $status);
        $ok = mysqli_stmt_execute($stmt);
        $id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            error_log('platform: user insert failed: ' . mysqli_error($conn));
            return ['ok' => false, 'error' => 'User could not be created.'];
        }
        return ['ok' => true, 'id' => $id, 'login_id' => $login];
    }

    /** Set a new password for one user (by id). */
    function psPlatformSetPassword(mysqli $conn, int $userId, string $plain): array
    {
        if (strlen($plain) < PS_PASSWORD_MIN_LENGTH) {
            return ['ok' => false, 'error' => 'Password must be at least ' . PS_PASSWORD_MIN_LENGTH . ' characters.'];
        }
        psSecurityEnsureUsersPasswordColumn($conn);
        $hash = psSecurityHashPassword($plain);
        $stmt = mysqli_prepare($conn, 'UPDATE users SET password = ?, lock_status = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Password could not be changed.'];
        }
        $free = 'Allowed';
        mysqli_stmt_bind_param($stmt, 'ssi', $hash, $free, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Password could not be changed.'];
    }

    /** Admin users (level admin, any case) of a company. */
    function psPlatformCompanyAdmins(mysqli $conn, string $company): array
    {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, firstname, lastname, email, level, lock_status, branch FROM users
             WHERE company_name = ? AND LOWER(TRIM(level)) = 'admin' ORDER BY email"
        );
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 's', $company);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        mysqli_stmt_close($stmt);
        return $rows;
    }

    /** Recent platform_audit rows (optionally for one company). */
    function psPlatformAuditRows(mysqli $conn, ?string $company = null, int $limit = 100): array
    {
        if (!psPlatformTableExists($conn, 'platform_audit')) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        if ($company !== null) {
            $stmt = mysqli_prepare($conn, 'SELECT * FROM platform_audit WHERE company_name = ? ORDER BY id DESC LIMIT ' . $limit);
            mysqli_stmt_bind_param($stmt, 's', $company);
        } else {
            $stmt = mysqli_prepare($conn, 'SELECT * FROM platform_audit ORDER BY id DESC LIMIT ' . $limit);
        }
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        mysqli_stmt_close($stmt);
        return $rows;
    }

    /** Readable label for platform_audit.action_code. */
    function psPlatformActionLabel(string $code): string
    {
        $map = [
            'MANAGE_START' => 'Started managing',
            'MANAGE_END' => 'Stopped managing',
            'COMPANY_CREATE' => 'Company created',
            'COMPANY_UPDATE' => 'Company details changed',
            'COMPANY_SUSPEND' => 'Company suspended',
            'COMPANY_ACTIVATE' => 'Company reactivated',
            'MANAGED_ON' => 'Administration by ' . PS_PLATFORM_PROVIDER_NAME . ' turned on',
            'MANAGED_OFF' => 'Administration by ' . PS_PLATFORM_PROVIDER_NAME . ' turned off',
            'ADMIN_CREATE' => 'Company admin created',
            'ADMIN_PASSWORD_RESET' => 'Company admin password reset',
            'USER_ROLE_CHANGE' => 'User role changed',
            'SUPERADMIN_CREATE' => 'Super Admin created',
            'SUPERADMIN_LOCK' => 'Super Admin locked',
            'SUPERADMIN_UNLOCK' => 'Super Admin unlocked',
            'UNIT_CREATE' => 'Unit of measure added',
            'UNIT_UPDATE' => 'Unit of measure changed',
            'UNIT_HIDE' => 'Unit of measure hidden',
            'UNIT_SHOW' => 'Unit of measure shown',
        ];
        return $map[strtoupper($code)] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
    }
}
