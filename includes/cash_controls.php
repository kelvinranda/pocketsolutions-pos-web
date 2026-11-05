<?php
/**
 * 2026-10-17 — Till-role cash controls (web; the desktop till applies the same rules).
 *
 *  - Blind cash count: Till users do not see expected cash (or the totals it is made of) until they have
 *    saved a final count that is still current. Supervisor / Management / admin always see it.
 *  - Cash out above a limit by a Till user needs a Supervisor / Management / admin to sign it off with
 *    their own username and password. The limit is per company (default 20.00, 0 = every cash out).
 *  - Receipt reprints are recorded (who, when, which receipt) and printed as COPY.
 *  - Till users only see and reprint their own sales.
 */

if (!defined('PS_CASH_OUT_APPROVAL_DEFAULT')) {
    define('PS_CASH_OUT_APPROVAL_DEFAULT', '20.00');
}

function psCashControlsLevel(): string
{
    return strtolower(trim((string)($_SESSION['level'] ?? '')));
}

/** Plain cashier (Till level): not Supervisor, Management or admin. */
function psCashControlsIsTillUser(): bool
{
    return !in_array(psCashControlsLevel(), ['admin', 'supervisor', 'management'], true);
}

function psCashControlsCompany(): string
{
    return trim((string)($_SESSION['the_company'] ?? ''));
}

function psCashControlsUser(): string
{
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psCashControlsEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $ok = mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS cash_control_settings (
            company_name VARCHAR(250) NOT NULL,
            setting_key VARCHAR(64) NOT NULL,
            setting_value VARCHAR(250) NOT NULL,
            updated_by VARCHAR(250) NOT NULL DEFAULT \'\',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (company_name, setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
        && mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS receipt_reprints (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL DEFAULT \'\',
            branch VARCHAR(250) NOT NULL DEFAULT \'\',
            invoice_number VARCHAR(64) NOT NULL,
            reprinted_by VARCHAR(250) NOT NULL,
            reprinted_at DATETIME NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT \'web\',
            KEY idx_rr_invoice (invoice_number),
            KEY idx_rr_at (reprinted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $ok = (bool)$ok;
    return $ok;
}

// ------------------------------------------------------------------ cash out approval limit

/** Cash out above this amount (string, 2 dp) by a Till user needs a supervisor. "0.00" = every cash out. */
function psCashOutApprovalLimit(mysqli $conn, ?string $company = null): string
{
    $company = $company ?? psCashControlsCompany();
    if (!psCashControlsEnsureSchema($conn)) {
        return PS_CASH_OUT_APPROVAL_DEFAULT;
    }
    $stmt = mysqli_prepare($conn, 'SELECT setting_value FROM cash_control_settings WHERE company_name = ? AND setting_key = \'cash_out_approval_limit\' LIMIT 1');
    if (!$stmt) {
        return PS_CASH_OUT_APPROVAL_DEFAULT;
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $v = $row ? (string)$row['setting_value'] : PS_CASH_OUT_APPROVAL_DEFAULT;
    return preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v) ? number_format((float)$v, 2, '.', '') : PS_CASH_OUT_APPROVAL_DEFAULT;
}

/** Admin only. */
function psCashOutSetApprovalLimit(mysqli $conn, string $value, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change the cash out limit.'];
    }
    $value = trim($value);
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value)) {
        return ['ok' => false, 'error' => 'Enter the limit as an amount, e.g. 20.00 (0 = every cash out needs a supervisor).'];
    }
    $value = number_format((float)$value, 2, '.', '');
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The limit could not be saved.'];
    }
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, 'INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, \'cash_out_approval_limit\', ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The limit could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $value, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true, 'value' => $value] : ['ok' => false, 'error' => 'The limit could not be saved.'];
}

// ------------------------------------------------------------------ till users adding customers (2026-10-18)

/** Company switch: may Till users add NEW customers at the POS (web and desktop)? Off unless an admin turns it on. */
function psTillMayAddCustomers(mysqli $conn, ?string $company = null): bool
{
    $company = $company ?? psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM cash_control_settings WHERE company_name = ? AND setting_key = 'till_add_customers' LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row !== null && (string)$row['setting_value'] === '1';
}

/** Admin only. */
function psTillSetMayAddCustomers(mysqli $conn, bool $on, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change this.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    $v = $on ? '1' : '0';
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, 'till_add_customers', ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $v, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The setting could not be saved.'];
}

/**
 * 2026-10-18 — Company switch: may the POS sell more than is in stock (stock goes below zero)?
 * Admins set it on Opening Stock. A company that never chose follows the server default
 * ('allow_negative_stock' in the private pockets_config.php, on unless set to 0).
 * Web POS and Windows tills (sent on every sync). Only products with an opening stock are checked.
 */
function psCompanyAllowsNegativeStock(mysqli $conn, ?string $company = null): bool
{
    $company = trim((string)($company ?? psCashControlsCompany()));
    if ($company !== '' && psCashControlsEnsureSchema($conn)) {
        $stmt = mysqli_prepare($conn, "SELECT setting_value FROM cash_control_settings WHERE company_name = ? AND setting_key = 'allow_negative_stock' LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $company);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($row !== null) {
                return (string)$row['setting_value'] === '1';
            }
        }
    }
    $raw = function_exists('psConfigValue') ? psConfigValue('allow_negative_stock', '1') : '1';
    return !in_array(strtolower(trim($raw)), ['0', 'false', 'no', 'off'], true);
}

/** Admin only. $allow = true lets the POS sell below zero; false stops a sale when stock runs out. */
function psSetCompanyAllowsNegativeStock(mysqli $conn, bool $allow, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change this.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    $v = $allow ? '1' : '0';
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, 'allow_negative_stock', ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $v, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The setting could not be saved.'];
}

/** May the signed-in user add a new customer at the POS? Supervisor, Management, Admin: yes; Till: when the company allows it. */
function psSessionMayAddCustomer(mysqli $conn): bool
{
    if (trim((string)($_SESSION['logger'] ?? '')) === '') {
        return false;
    }
    return !psCashControlsIsTillUser() || psTillMayAddCustomers($conn);
}

/** Does this cash out (by the signed-in user) need a supervisor? */
function psCashOutNeedsApproval(mysqli $conn, string $amount): bool
{
    if (!psCashControlsIsTillUser()) {
        return false;
    }
    $limit = psCashOutApprovalLimit($conn);
    return (int)round((float)$amount * 100) > (int)round((float)$limit * 100);
}

/**
 * A Supervisor / Management / admin of the same company confirms with their own username and password.
 * Five wrong tries pause approvals in this browser session for 15 minutes.
 *
 * @return array{ok:bool,email?:string,error?:string}
 */
function psCashControlsVerifyApprover(mysqli $conn, string $login, string $password): array
{
    require_once __DIR__ . '/security.php';
    $login = trim($login);
    if ($login === '' || $password === '') {
        return ['ok' => false, 'error' => 'A supervisor must enter their username and password to approve this.'];
    }
    $until = (int)($_SESSION['ps_approver_pause_until'] ?? 0);
    if ($until > time()) {
        return ['ok' => false, 'error' => 'Too many wrong supervisor passwords. Try again at ' . date('H:i', $until) . '.'];
    }
    $fail = function (string $msg) {
        $n = (int)($_SESSION['ps_approver_fails'] ?? 0) + 1;
        $_SESSION['ps_approver_fails'] = $n;
        if ($n >= 5) {
            $_SESSION['ps_approver_fails'] = 0;
            $_SESSION['ps_approver_pause_until'] = time() + 15 * 60;
        }
        return ['ok' => false, 'error' => $msg];
    };
    $found = psSecurityLookupUserByEmail($conn, $login);
    if (empty($found['ok'])) {
        return $fail('Supervisor username or password is not correct.');
    }
    $u = $found['user'];
    if (!psSecurityVerifyPassword((string)$u['password'], $password)['ok']) {
        return $fail('Supervisor username or password is not correct.');
    }
    if (strcasecmp(trim((string)$u['company_name']), psCashControlsCompany()) !== 0) {
        return $fail('Supervisor username or password is not correct.');
    }
    $level = strtolower(trim((string)$u['level']));
    if (!in_array($level, ['admin', 'supervisor', 'management'], true)) {
        return ['ok' => false, 'error' => 'Only a Supervisor, Manager or Admin can approve this.'];
    }
    if (strcasecmp(trim((string)$u['email']), psCashControlsUser()) === 0) {
        return ['ok' => false, 'error' => 'Another person must approve this.'];
    }
    $_SESSION['ps_approver_fails'] = 0;
    return ['ok' => true, 'email' => (string)$u['email']];
}

// ------------------------------------------------------------------ blind count

/**
 * Show expected cash and the till totals to this user? Supervisors and up: always.
 * Till users: only once a final count exists that still matches the ledger (the count is done).
 */
function psCashControlsShowExpected(mysqli $conn, int $tillSessionId): bool
{
    if (!psCashControlsIsTillUser()) {
        return true;
    }
    if ($tillSessionId < 1) {
        return false;
    }
    require_once __DIR__ . '/cash_reconciliation.php';
    require_once __DIR__ . '/cash_movement.php';
    $found = psCashReconciliationFindFinalBySession($conn, $tillSessionId, false);
    if (empty($found['ok']) || empty($found['exists']) || !is_array($found['row'])) {
        return false;
    }
    $bal = psCashMovementBalance($conn, $tillSessionId);
    if (empty($bal['ok'])) {
        return false;
    }
    return (int)round((float)$found['row']['expected_cash'] * 100) === (int)round((float)$bal['balance'] * 100);
}

// ------------------------------------------------------------------ receipts

/** Till users: only their own sales. */
function psReceiptVisibleToUser(array $saleRow): bool
{
    if (!psCashControlsIsTillUser()) {
        return true;
    }
    $me = psCashControlsUser();
    return $me !== '' && strcasecmp(trim((string)($saleRow['cashier'] ?? '')), $me) === 0;
}

/** A copy that was actually printed (outcome PRINTED). Returns the number of printed copies of that document. */
function psReceiptReprintLog(mysqli $conn, string $invoice, string $branch, string $source = 'web',
                             ?string $company = null, ?string $user = null, ?string $at = null,
                             ?string $approvedBy = null, string $document = 'RECEIPT'): int
{
    require_once __DIR__ . '/receipt_reprint.php';
    if (!psReprintEnsureSchema($conn)) {
        return 0;
    }
    $company = $company ?? psCashControlsCompany();
    $user = $user ?? psCashControlsUser();
    $now = $at ?? date('Y-m-d H:i:s');
    $document = in_array($document, psReprintDocuments(), true) ? $document : 'RECEIPT';
    $stmt = mysqli_prepare($conn, "INSERT INTO receipt_reprints (company_name, branch, invoice_number, reprinted_by, reprinted_at, source, approved_by, outcome, document)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'PRINTED', ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssssssss', $company, $branch, $invoice, $user, $now, $source, $approvedBy, $document);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return psReprintPrintedCount($conn, $invoice, $document);
}

/** Recent reprints for managers (branch filter optional). */
function psReceiptReprintList(mysqli $conn, string $from, string $to, string $branch = ''): array
{
    require_once __DIR__ . '/receipt_reprint.php';
    if (!psReprintEnsureSchema($conn)) {
        return [];
    }
    $company = psCashControlsCompany();
    $sql = 'SELECT invoice_number, branch, reprinted_by, reprinted_at, source, approved_by, outcome, document, note FROM receipt_reprints
            WHERE company_name = ? AND reprinted_at >= ? AND reprinted_at <= ?' . ($branch !== '' ? ' AND branch = ?' : '') . '
            ORDER BY id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    $f = $from . ' 00:00:00';
    $t = $to . ' 23:59:59';
    if ($branch !== '') {
        mysqli_stmt_bind_param($stmt, 'ssss', $company, $f, $t, $branch);
    } else {
        mysqli_stmt_bind_param($stmt, 'sss', $company, $f, $t);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}
