<?php
require_once __DIR__ . '/tx_nesting.php';
/**
 * Phase 6.1 — Customer Account & Receivables Ledger Foundation.
 * Phase 6.2 — Credit sales post CREDIT_SALE ledger rows from the production POS path.
 * Phase 6.5 — Credit status (NORMAL|ON_HOLD) separate from account lifecycle; hold/release
 *             and credit-limit changes audited; CREDIT sales reject ON_HOLD at evaluate.
 *
 * Architecture: customer_master → customer_accounts (one per customer)
 *                             → customer_account_ledger
 *                             → customer_account_audit (incl. HOLD / RELEASE_HOLD / LIMIT_UPDATE)
 *
 * Balance = SUM(debit) − SUM(credit); positive = customer owes; negative = customer credit.
 * Ledger is authoritative (no cached balance column).
 *
 * Account lifecycle status: ACTIVE | SUSPENDED | CLOSED (unchanged).
 * Credit status (Phase 6.5): NORMAL | ON_HOLD — does NOT overload lifecycle status.
 * CREDIT sale eligibility: ACTIVE customer + ACTIVE account + credit_status=NORMAL + limit rules.
 * Hold blocks new CREDIT sales only; payments / allocations / statements / viewing remain OK.
 *
 * Credit limit (Phase 6.2/6.5): credit_limit = 0.00 means no credit facility.
 * available_credit (display) = credit_limit − current_positive_balance
 *   where current_positive_balance = max(balance, 0).
 * Authorisation: reject CREDIT sale if (balance + server_sale_total) > credit_limit,
 *   or if credit_limit is 0.00, or if customer/account/credit_status ineligible.
 * Does NOT silent-create accounts. Payments / allocation are Phase 6.3
 * (includes/customer_account_payment.php). Statements / ageing are Phase 6.4
 * (includes/customer_account_reporting.php). Collections UI: customer_credit_control.php.
 * Account numbers: ACC-###### via customer_account_seq (SELECT … FOR UPDATE); never MAX(id)+1.
 */

require_once __DIR__ . '/customer_master.php';

const PS_CUST_ACCT_STATUS_ACTIVE = 'ACTIVE';
const PS_CUST_ACCT_STATUS_SUSPENDED = 'SUSPENDED';
const PS_CUST_ACCT_STATUS_CLOSED = 'CLOSED';

/** Phase 6.5 — credit facility status (separate from account lifecycle status). */
const PS_CUST_ACCT_CREDIT_NORMAL = 'NORMAL';
const PS_CUST_ACCT_CREDIT_ON_HOLD = 'ON_HOLD';

const PS_CUST_ACCT_ENTRY_OPENING_BALANCE = 'OPENING_BALANCE';
const PS_CUST_ACCT_ENTRY_CREDIT_SALE = 'CREDIT_SALE';
const PS_CUST_ACCT_ENTRY_PAYMENT = 'PAYMENT';
const PS_CUST_ACCT_ENTRY_CREDIT_NOTE = 'CREDIT_NOTE';
const PS_CUST_ACCT_ENTRY_DEBIT_ADJUSTMENT = 'DEBIT_ADJUSTMENT';
const PS_CUST_ACCT_ENTRY_CREDIT_ADJUSTMENT = 'CREDIT_ADJUSTMENT';

const PS_CUST_ACCT_AUDIT_SOURCE_UI = 'UI';
const PS_CUST_ACCT_AUDIT_CREATE = 'CREATE';
const PS_CUST_ACCT_AUDIT_UPDATE = 'UPDATE';
const PS_CUST_ACCT_AUDIT_ACTIVATE = 'ACTIVATE';
const PS_CUST_ACCT_AUDIT_SUSPEND = 'SUSPEND';
const PS_CUST_ACCT_AUDIT_CLOSE = 'CLOSE';
const PS_CUST_ACCT_AUDIT_OPENING_BALANCE = 'OPENING_BALANCE';
const PS_CUST_ACCT_AUDIT_HOLD = 'HOLD';
const PS_CUST_ACCT_AUDIT_RELEASE_HOLD = 'RELEASE_HOLD';
const PS_CUST_ACCT_AUDIT_LIMIT_UPDATE = 'LIMIT_UPDATE';

/**
 * Dedicated account number counter. Format: ACC-000001.
 * Never uses customer_seq / sale_invoice_seq / PO / GRV / purchase-return seq / MAX(id)+1.
 */
function psEnsureCustomerAccountSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS customer_account_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_account_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM customer_account_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO customer_account_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('customer_account_seq was not seeded');
    return false;
}

/**
 * Ensure customer_accounts exists. Safe to call repeatedly.
 * No DB FK to customer_master (app-validated; consistent with Phase 5 safe rollout).
 */
function psEnsureCustomerAccountsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureCustomerAccountSeq($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_accounts LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS customer_accounts (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            customer_master_id INT NOT NULL,
            account_number VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            credit_status VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
            credit_hold_reason VARCHAR(64) NULL DEFAULT NULL,
            credit_hold_note VARCHAR(500) NULL DEFAULT NULL,
            credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_by VARCHAR(250) NOT NULL DEFAULT '',
            UNIQUE KEY uniq_customer_accounts_customer (customer_master_id),
            UNIQUE KEY uniq_customer_accounts_number (account_number),
            KEY idx_customer_accounts_status (status),
            KEY idx_customer_accounts_credit_status (credit_status),
            KEY idx_customer_accounts_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('customer_accounts schema was not created');
            return false;
        }
    }
    if (!psEnsureCustomerAccountsCreditControlColumns($conn)) {
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Phase 6.5 — add credit_status (+ optional current hold reason/note) without overloading status.
 * Safe to call repeatedly. Existing accounts default credit_status=NORMAL.
 */
function psEnsureCustomerAccountsCreditControlColumns(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $col = mysqli_query($conn, "SHOW COLUMNS FROM customer_accounts LIKE 'credit_status'");
    if (!$col || mysqli_num_rows($col) === 0) {
        $added = mysqli_query(
            $conn,
            "ALTER TABLE customer_accounts
             ADD COLUMN credit_status VARCHAR(20) NOT NULL DEFAULT 'NORMAL' AFTER status,
             ADD KEY idx_customer_accounts_credit_status (credit_status)"
        );
        if (!$added && mysqli_errno($conn) !== 1060) {
            error_log('customer_accounts.credit_status column was not added');
            return false;
        }
    }
    $reasonCol = mysqli_query($conn, "SHOW COLUMNS FROM customer_accounts LIKE 'credit_hold_reason'");
    if (!$reasonCol || mysqli_num_rows($reasonCol) === 0) {
        $addedReason = mysqli_query(
            $conn,
            "ALTER TABLE customer_accounts
             ADD COLUMN credit_hold_reason VARCHAR(64) NULL DEFAULT NULL AFTER credit_status,
             ADD COLUMN credit_hold_note VARCHAR(500) NULL DEFAULT NULL AFTER credit_hold_reason"
        );
        if (!$addedReason && mysqli_errno($conn) !== 1060) {
            error_log('customer_accounts credit hold reason/note columns were not added');
            return false;
        }
    }
    return true;
}

/**
 * Ensure customer_account_ledger exists. Ledger is balance authority.
 * Phase 6.2: UNIQUE(reference_type, reference_id, entry_type) for CREDIT_SALE duplicate protection
 * when reference fields are non-NULL (MySQL allows multiple NULL refs for opening balances).
 */
function psEnsureCustomerAccountLedgerSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_account_ledger LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS customer_account_ledger (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            customer_account_id INT NOT NULL,
            entry_date DATE NOT NULL,
            entry_type VARCHAR(32) NOT NULL,
            debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            reference_type VARCHAR(64) NULL DEFAULT NULL,
            reference_id VARCHAR(64) NULL DEFAULT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            KEY idx_cust_acct_ledger_account (customer_account_id),
            KEY idx_cust_acct_ledger_entry_date (entry_date),
            KEY idx_cust_acct_ledger_ref (reference_type, reference_id),
            KEY idx_cust_acct_ledger_type (entry_type),
            UNIQUE KEY uniq_cust_acct_ledger_ref_entry (reference_type, reference_id, entry_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('customer_account_ledger schema was not created');
            return false;
        }
    }
    if (!psEnsureCustomerAccountLedgerDupIndex($conn)) {
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Phase 6.2 — unique (reference_type, reference_id, entry_type) for one CREDIT_SALE per sale.
 */
function psEnsureCustomerAccountLedgerDupIndex(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $idx = mysqli_query(
        $conn,
        "SHOW INDEX FROM customer_account_ledger WHERE Key_name = 'uniq_cust_acct_ledger_ref_entry'"
    );
    if ($idx && mysqli_num_rows($idx) > 0) {
        return true;
    }
    $added = mysqli_query(
        $conn,
        'ALTER TABLE customer_account_ledger
         ADD UNIQUE KEY uniq_cust_acct_ledger_ref_entry (reference_type, reference_id, entry_type)'
    );
    if ($added || mysqli_errno($conn) === 1061) {
        return true;
    }
    error_log('customer_account_ledger duplicate-protection index was not added');
    return false;
}

/**
 * Ensure customer_account_audit exists. Append-only in application code.
 */
function psEnsureCustomerAccountAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_account_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_account_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_account_id INT NOT NULL,
        account_number VARCHAR(64) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_cust_acct_audit_account (customer_account_id),
        KEY idx_cust_acct_audit_number (account_number),
        KEY idx_cust_acct_audit_changed_at (changed_at),
        KEY idx_cust_acct_audit_changed_by (changed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_account_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureCustomerAccountModule(mysqli $conn): bool
{
    return psEnsureCustomerMasterSchema($conn)
        && psEnsureCustomerAccountsSchema($conn)
        && psEnsureCustomerAccountLedgerSchema($conn)
        && psEnsureCustomerAccountAuditSchema($conn);
}

function psSessionCustomerAccountUser(): string
{
    if (function_exists('psActorOverride') && ($a = psActorOverride()) !== null) {
        return $a;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psNormalizeCustomerAccountStatus($raw): array
{
    $value = strtoupper(trim((string)$raw));
    $allowed = [
        PS_CUST_ACCT_STATUS_ACTIVE,
        PS_CUST_ACCT_STATUS_SUSPENDED,
        PS_CUST_ACCT_STATUS_CLOSED,
    ];
    if (in_array($value, $allowed, true)) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Account status must be Active, Suspended, or Closed.'];
}

function psCustomerAccountStatusLabel(string $status): string
{
    $check = psNormalizeCustomerAccountStatus($status);
    if (!$check['ok']) {
        return 'Unknown';
    }
    if ($check['value'] === PS_CUST_ACCT_STATUS_ACTIVE) {
        return 'Active';
    }
    if ($check['value'] === PS_CUST_ACCT_STATUS_SUSPENDED) {
        return 'Suspended';
    }
    return 'Closed';
}

/**
 * Phase 6.5 — credit facility status (not account lifecycle).
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeCustomerAccountCreditStatus($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if ($value === PS_CUST_ACCT_CREDIT_NORMAL || $value === PS_CUST_ACCT_CREDIT_ON_HOLD) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Credit status must be Normal or On Hold.'];
}

function psCustomerAccountCreditStatusLabel(string $status): string
{
    $check = psNormalizeCustomerAccountCreditStatus($status);
    if (!$check['ok']) {
        return 'Unknown';
    }
    return $check['value'] === PS_CUST_ACCT_CREDIT_ON_HOLD ? 'On Hold' : 'Normal';
}

/**
 * Controlled hold reasons (Phase 6.5). Manual only — no auto-hold from ageing.
 *
 * @return list<string>
 */
function psCustomerAccountCreditHoldReasons(): array
{
    return [
        'OVERDUE',
        'CREDIT_LIMIT',
        'COLLECTIONS',
        'MANAGEMENT_REVIEW',
        'CUSTOMER_REQUEST',
        'OTHER',
    ];
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeCustomerAccountCreditHoldReason($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if (in_array($value, psCustomerAccountCreditHoldReasons(), true)) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'A valid credit hold reason is required.'];
}

function psCustomerAccountCreditHoldReasonLabel(string $reason): string
{
    $labels = [
        'OVERDUE' => 'Overdue',
        'CREDIT_LIMIT' => 'Credit limit',
        'COLLECTIONS' => 'Collections',
        'MANAGEMENT_REVIEW' => 'Management review',
        'CUSTOMER_REQUEST' => 'Customer request',
        'OTHER' => 'Other',
    ];
    $key = strtoupper(trim($reason));
    return $labels[$key] ?? $reason;
}

/**
 * Strongest existing role gate for hold/release/limit management: session admin.
 * Cashiers (non-admin with logger) must not release hold to complete a credit sale —
 * server CREDIT evaluate still rejects ON_HOLD regardless of role.
 * Limitation: no finer-grained credit-control role exists beyond admin vs non-admin.
 */
/**
 * 2026-10-17: who may set an account's credit limit — Supervisor, Management or admin (holds, opening balances
 * and account status stay admin-only). Limit 0.00 still means no credit facility.
 */
function psCustomerAccountMaySetLimit(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || trim((string)($_SESSION['logger'] ?? '')) === '') {
        return false;
    }
    return in_array(strtolower(trim((string)($_SESSION['level'] ?? ''))), ['admin', 'supervisor', 'management'], true);
}

function psCustomerAccountCreditControlMayManage(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (trim((string)($_SESSION['logger'] ?? '')) === '') {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Entry types allowed in schema design.
 * Phase 6.1 posts OPENING_BALANCE via admin workflow; Phase 6.2 posts CREDIT_SALE from POS.
 * Phase 6.3 posts PAYMENT (and PAYMENT_REVERSAL on void via payment helper — not listed here
 * for general insert; void uses a dedicated insert). CREDIT_NOTE / adjustments remain future.
 *
 * @return list<string>
 */
function psCustomerAccountEntryTypes(): array
{
    return [
        PS_CUST_ACCT_ENTRY_OPENING_BALANCE,
        PS_CUST_ACCT_ENTRY_CREDIT_SALE,
        PS_CUST_ACCT_ENTRY_PAYMENT,
        PS_CUST_ACCT_ENTRY_CREDIT_NOTE,
        PS_CUST_ACCT_ENTRY_DEBIT_ADJUSTMENT,
        PS_CUST_ACCT_ENTRY_CREDIT_ADJUSTMENT,
    ];
}

function psNormalizeCustomerAccountEntryType($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if (in_array($value, psCustomerAccountEntryTypes(), true)) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Ledger entry type is not valid.'];
}

/**
 * Non-negative money with max 2 decimal places (DECIMAL). Zero allowed at validator level;
 * ledger insert separately rejects zero financial entries (both debit and credit zero).
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateCustomerAccountMoney($raw, string $label): array
{
    if ($raw === null) {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    $raw = str_replace(',', '', $raw);
    if ($raw !== '' && $raw[0] === '+') {
        return ['ok' => false, 'error' => $label . ' must be a number.'];
    }
    if ($raw !== '' && $raw[0] === '-') {
        return ['ok' => false, 'error' => $label . ' cannot be negative.'];
    }
    if (preg_match('/^\d+\.\d{3,}$/', $raw)) {
        return ['ok' => false, 'error' => $label . ' cannot have more than 2 decimal places.'];
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return ['ok' => false, 'error' => $label . ' must be a number.'];
    }
    if (strlen($raw) > 20) {
        return ['ok' => false, 'error' => $label . ' is too large.'];
    }
    return ['ok' => true, 'value' => number_format((float)$raw, 2, '.', '')];
}

function psCustomerAccountAuditNormalizeValue(string $field, $value): ?string
{
    if ($value === null) {
        return null;
    }
    if ($field === 'credit_limit' || $field === 'debit' || $field === 'credit' || $field === 'balance') {
        return number_format((float)$value, 2, '.', '');
    }
    return (string)$value;
}

function psCustomerAccountAuditInsertRow(
    mysqli $conn,
    int $accountId,
    string $accountNumber,
    string $action,
    string $fieldName,
    $oldValue,
    $newValue,
    string $changedBy,
    string $changedAt,
    string $source
): bool {
    $accountNumber = trim($accountNumber);
    $action = strtoupper(trim($action));
    $fieldName = trim($fieldName);
    $changedBy = trim($changedBy);
    $source = trim($source);
    if ($accountId < 1 || $accountNumber === '' || $action === '' || $fieldName === '') {
        return false;
    }
    if ($changedBy === '') {
        $changedBy = psSessionCustomerAccountUser();
    }
    if ($changedBy === '') {
        return false;
    }
    if ($source === '') {
        $source = PS_CUST_ACCT_AUDIT_SOURCE_UI;
    }
    if ($changedAt === '') {
        $changedAt = date('Y-m-d H:i:s');
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_account_audit
         (customer_account_id, account_number, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('customer account audit insert prepare failed');
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'issssssss',
        $accountId,
        $accountNumber,
        $action,
        $fieldName,
        $oldStore,
        $newStore,
        $changedBy,
        $changedAt,
        $source
    );
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        error_log('customer account audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * @param array<string,mixed> $meta
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psCustomerAccountAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    $accountId = (int)($meta['customer_account_id'] ?? 0);
    $accountNumber = trim((string)($meta['account_number'] ?? ''));
    $action = strtoupper(trim((string)($meta['action'] ?? '')));
    $source = trim((string)($meta['source'] ?? PS_CUST_ACCT_AUDIT_SOURCE_UI));
    $changedBy = trim((string)($meta['changed_by'] ?? ''));
    $changedAt = trim((string)($meta['changed_at'] ?? ''));
    if ($changedBy === '') {
        $changedBy = psSessionCustomerAccountUser();
    }
    foreach ($changes as $change) {
        $field = (string)($change['field_name'] ?? '');
        if ($field === '') {
            continue;
        }
        if (!psCustomerAccountAuditInsertRow(
            $conn,
            $accountId,
            $accountNumber,
            $action,
            $field,
            $change['old_value'] ?? null,
            $change['new_value'] ?? null,
            $changedBy,
            $changedAt,
            $source
        )) {
            return false;
        }
    }
    return true;
}

/**
 * Allocate next ACC-###### inside an open transaction (SELECT … FOR UPDATE).
 *
 * @return array{ok:bool,number?:string,error?:string}
 */
function psAllocateCustomerAccountNumber(mysqli $conn): array
{
    if (!psEnsureCustomerAccountSeq($conn)) {
        return ['ok' => false, 'error' => 'Account number could not be allocated.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM customer_account_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('customer account number allocation failed');
        return ['ok' => false, 'error' => 'Account number could not be allocated.'];
    }
    $n = (int)$row['next_number'];
    if ($n < 1 || $n > 999999) {
        return ['ok' => false, 'error' => 'Account number sequence is exhausted.'];
    }
    $number = 'ACC-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    $next = $n + 1;
    $upd = mysqli_prepare($conn, 'UPDATE customer_account_seq SET next_number = ? WHERE id = 1');
    if (!$upd) {
        return ['ok' => false, 'error' => 'Account number could not be allocated.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $next);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        error_log('customer account number allocation update failed');
        return ['ok' => false, 'error' => 'Account number could not be allocated.'];
    }
    mysqli_stmt_close($upd);
    return ['ok' => true, 'number' => $number];
}

function psCustomerAccountById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsureCustomerAccountsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_accounts WHERE id = ? AND ' . psCustomerIdScopeSql($conn, 'customer_master_id') . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function psCustomerAccountByCustomerMasterId(mysqli $conn, int $customerMasterId): ?array
{
    if ($customerMasterId < 1 || !psEnsureCustomerAccountsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_accounts WHERE customer_master_id = ? AND ' . psCustomerIdScopeSql($conn, 'customer_master_id') . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $customerMasterId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Multi-currency (2026-10-20): the currency an account is kept in. Accounts made before multi-currency (NULL) are kept
 * in the company base. Returns '' when the customer has no account.
 */
function psCustomerAccountCurrency(mysqli $conn, int $customerMasterId, string $company = ''): string
{
    $acct = psCustomerAccountByCustomerMasterId($conn, $customerMasterId);
    if (!$acct) {
        return '';
    }
    return psCustomerAccountRowCurrency($conn, $acct, $company);
}

function psCustomerAccountRowCurrency(mysqli $conn, array $acct, string $company = ''): string
{
    require_once __DIR__ . '/currency.php';
    $cur = psFxNormalizeCode($acct['currency'] ?? '');
    if ($cur !== '') {
        return $cur;
    }
    return psFxBase($conn, $company !== '' ? $company : psFxSessionCompany());
}

function psCustomerAccountByNumber(mysqli $conn, string $accountNumber): ?array
{
    $accountNumber = trim($accountNumber);
    if ($accountNumber === '' || !psEnsureCustomerAccountsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_accounts WHERE account_number = ? AND ' . psCustomerIdScopeSql($conn, 'customer_master_id') . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $accountNumber);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Ledger-derived balance. Positive = customer owes; negative = customer credit; empty ledger = 0.00.
 *
 * @return array{ok:bool,balance?:string,error?:string}
 */
function psCustomerAccountBalance(mysqli $conn, int $accountId): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Account balance could not be calculated.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit
         FROM customer_account_ledger WHERE customer_account_id = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Account balance could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $accountId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => true, 'balance' => '0.00'];
    }
    $debit = (float)$row['total_debit'];
    $credit = (float)$row['total_credit'];
    $balance = $debit - $credit;
    return ['ok' => true, 'balance' => number_format($balance, 2, '.', '')];
}

/**
 * Phase 6.2 — display available credit from ledger balance + stored credit_limit.
 *
 * Formula:
 *   balance = SUM(debit) − SUM(credit)
 *   current_positive_balance = max(balance, 0.00)
 *   available_credit = credit_limit − current_positive_balance
 *
 * Negative balance (customer credit) does not inflate available_credit above credit_limit
 * for display. Authorisation uses projected balance (see psCustomerAccountEvaluateCreditSale).
 * credit_limit = 0.00 → available_credit ≤ 0 (no facility).
 *
 * @return array{ok:bool,balance?:string,credit_limit?:string,positive_balance?:string,available_credit?:string,error?:string}
 */
function psCustomerAccountAvailableCredit(mysqli $conn, int $accountId, $creditLimit = null): array
{
    $bal = psCustomerAccountBalance($conn, $accountId);
    if (!$bal['ok']) {
        return ['ok' => false, 'error' => $bal['error'] ?? 'Account balance could not be calculated.'];
    }
    $balance = (string)$bal['balance'];
    if ($creditLimit === null) {
        $account = psCustomerAccountById($conn, $accountId);
        if (!$account) {
            return ['ok' => false, 'error' => 'Account was not found.'];
        }
        $creditLimit = $account['credit_limit'];
    }
    $limit = number_format((float)$creditLimit, 2, '.', '');
    $balanceF = (float)$balance;
    $positive = $balanceF > 0 ? $balanceF : 0.0;
    $positiveStr = number_format($positive, 2, '.', '');
    $available = (float)$limit - $positive;
    return [
        'ok' => true,
        'balance' => $balance,
        'credit_limit' => $limit,
        'positive_balance' => $positiveStr,
        'available_credit' => number_format($available, 2, '.', ''),
    ];
}

/**
 * Lock account row inside an open sale transaction (credit-limit race protection).
 */
function psCustomerAccountLockById(mysqli $conn, int $accountId): ?array
{
    if ($accountId < 1 || !psEnsureCustomerAccountsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_accounts WHERE id = ? AND ' . psCustomerIdScopeSql($conn, 'customer_master_id') . ' LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $accountId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Phase 6.2 / 6.5 — server-side CREDIT sale eligibility + credit-limit authorisation.
 * Does not trust posted balance / limit / eligibility / credit_status. Does not silent-create accounts.
 * Cash Customer (NULL customer_master_id) is always rejected for CREDIT.
 *
 * Eligibility: ACTIVE customer + ACTIVE account + credit_status=NORMAL + credit_limit > 0
 * and (ledger_balance + server_sale_total) ≤ credit_limit.
 * ON_HOLD / SUSPENDED / CLOSED → credit prohibited (clear message). Hold does not block cash.
 *
 * @param array{lock?:bool} $options lock=true uses SELECT … FOR UPDATE (sale txn)
 * @return array{ok:bool,account?:array,customer?:array,balance?:string,credit_limit?:string,available_credit?:string,projected_balance?:string,credit_status?:string,error?:string}
 */
function psCustomerAccountEvaluateCreditSale(
    mysqli $conn,
    $customerMasterId,
    $saleTotal,
    array $options = []
): array {
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Credit sale could not be authorised.'];
    }

    if ($customerMasterId === null || $customerMasterId === '' || (int)$customerMasterId < 1) {
        return ['ok' => false, 'error' => 'Credit sales require an active registered customer with an account.'];
    }
    $customerId = (int)$customerMasterId;
    $customer = psCustomerById($conn, $customerId);
    if ($customer === null) {
        return ['ok' => false, 'error' => 'Customer was not found.'];
    }
    if (!psCustomerStatusIsActive($customer['status'] ?? '')) {
        return ['ok' => false, 'error' => 'That customer is not active.'];
    }

    $account = psCustomerAccountByCustomerMasterId($conn, $customerId);
    if ($account === null) {
        return ['ok' => false, 'error' => 'This customer has no account. Create an account before charging to credit.'];
    }
    if (!empty($options['lock'])) {
        $locked = psCustomerAccountLockById($conn, (int)$account['id']);
        if ($locked === null) {
            return ['ok' => false, 'error' => 'Credit sale could not be authorised.'];
        }
        $account = $locked;
    }

    $status = strtoupper(trim((string)($account['status'] ?? '')));
    if ($status !== PS_CUST_ACCT_STATUS_ACTIVE) {
        return ['ok' => false, 'error' => 'This customer account is not active for credit sales.'];
    }

    // Phase 6.5 — re-read credit_status inside txn (ignore stale browser credit_status).
    $creditStatus = strtoupper(trim((string)($account['credit_status'] ?? PS_CUST_ACCT_CREDIT_NORMAL)));
    if ($creditStatus === '') {
        $creditStatus = PS_CUST_ACCT_CREDIT_NORMAL;
    }
    if ($creditStatus === PS_CUST_ACCT_CREDIT_ON_HOLD) {
        return [
            'ok' => false,
            'error' => 'This account is on credit hold. New credit sales are not allowed until the hold is released.',
            'account' => $account,
            'customer' => $customer,
            'credit_status' => $creditStatus,
        ];
    }
    if ($creditStatus !== PS_CUST_ACCT_CREDIT_NORMAL) {
        return ['ok' => false, 'error' => 'This account credit status does not allow credit sales.'];
    }

    $totalCheck = psValidateCustomerAccountMoney($saleTotal, 'Sale total');
    if (!$totalCheck['ok']) {
        return ['ok' => false, 'error' => 'Sale total is not valid for credit.'];
    }
    $saleTotalStr = $totalCheck['value'];
    if ((float)$saleTotalStr <= 0) {
        return ['ok' => false, 'error' => 'Credit sales require a sale total greater than zero.'];
    }

    $limit = number_format((float)($account['credit_limit'] ?? 0), 2, '.', '');
    if ((float)$limit <= 0) {
        return ['ok' => false, 'error' => 'This account has no credit facility (credit limit is 0.00).'];
    }

    $avail = psCustomerAccountAvailableCredit($conn, (int)$account['id'], $limit);
    if (!$avail['ok']) {
        return ['ok' => false, 'error' => $avail['error'] ?? 'Credit sale could not be authorised.'];
    }
    $balance = (string)$avail['balance'];
    $projected = number_format((float)$balance + (float)$saleTotalStr, 2, '.', '');
    if ((float)$projected > (float)$limit) {
        return [
            'ok' => false,
            'error' => 'Sale would exceed the customer credit limit. Balance: '
                . $balance . ', limit: ' . $limit . ', sale: ' . $saleTotalStr . '.',
            'account' => $account,
            'customer' => $customer,
            'balance' => $balance,
            'credit_limit' => $limit,
            'available_credit' => (string)$avail['available_credit'],
            'projected_balance' => $projected,
            'credit_status' => $creditStatus,
        ];
    }

    return [
        'ok' => true,
        'account' => $account,
        'customer' => $customer,
        'balance' => $balance,
        'credit_limit' => $limit,
        'available_credit' => (string)$avail['available_credit'],
        'projected_balance' => $projected,
        'credit_status' => $creditStatus,
    ];
}

/**
 * Phase 6.2 — post exactly one CREDIT_SALE ledger debit for a completed sale.
 * Must run inside the same DB transaction as the sale when architecture permits.
 * reference_type=SALE, reference_id=invoice number (authoritative sale identity on this path).
 * Duplicate protection: UNIQUE(reference_type, reference_id, entry_type) + pre-insert check.
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function psCustomerAccountPostCreditSale(
    mysqli $conn,
    int $accountId,
    string $saleTotal,
    $invoiceNumber,
    string $createdBy = ''
): array {
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Credit sale ledger entry could not be saved.'];
    }
    $account = psCustomerAccountById($conn, $accountId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    $totalCheck = psValidateCustomerAccountMoney($saleTotal, 'Sale total');
    if (!$totalCheck['ok'] || (float)$totalCheck['value'] <= 0) {
        return ['ok' => false, 'error' => 'Sale total is not valid for credit.'];
    }
    $invoice = trim((string)$invoiceNumber);
    if ($invoice === '' || !preg_match('/^\d+$/', $invoice)) {
        return ['ok' => false, 'error' => 'Sale reference is not valid.'];
    }

    $refType = 'SALE';
    $entryType = PS_CUST_ACCT_ENTRY_CREDIT_SALE;
    $dupStmt = mysqli_prepare(
        $conn,
        'SELECT id FROM customer_account_ledger
         WHERE reference_type = ? AND reference_id = ? AND entry_type = ?
         LIMIT 1'
    );
    if (!$dupStmt) {
        return ['ok' => false, 'error' => 'Credit sale ledger entry could not be saved.'];
    }
    mysqli_stmt_bind_param($dupStmt, 'sss', $refType, $invoice, $entryType);
    mysqli_stmt_execute($dupStmt);
    $dupRes = mysqli_stmt_get_result($dupStmt);
    $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
    mysqli_stmt_close($dupStmt);
    if ($dupRow) {
        return ['ok' => false, 'error' => 'A credit sale ledger entry already exists for this sale.'];
    }

    if ($createdBy === '') {
        $createdBy = psSessionCustomerAccountUser();
    }
    $result = psCustomerAccountLedgerInsert($conn, [
        'customer_account_id' => $accountId,
        'entry_type' => PS_CUST_ACCT_ENTRY_CREDIT_SALE,
        'entry_date' => date('Y-m-d'),
        'debit' => $totalCheck['value'],
        'credit' => '0.00',
        'reference_type' => $refType,
        'reference_id' => $invoice,
        'description' => 'Credit sale invoice ' . $invoice,
        'created_by' => $createdBy,
    ]);
    if (!$result['ok']) {
        if (!empty($result['duplicate'])) {
            return ['ok' => false, 'error' => 'A credit sale ledger entry already exists for this sale.'];
        }
        return ['ok' => false, 'error' => $result['error'] ?? 'Credit sale ledger entry could not be saved.'];
    }
    return $result;
}

/**
 * POS read-only account snapshot for an ACTIVE customer (no posted balances trusted).
 *
 * @return array{ok:bool,has_account:bool,eligible?:bool,account_number?:string,status?:string,balance?:string,credit_limit?:string,available_credit?:string,customer_name?:string,customer_code?:string,error?:string}
 */
function psCustomerAccountPosSnapshot(mysqli $conn, int $customerMasterId): array
{
    if ($customerMasterId < 1) {
        return ['ok' => false, 'has_account' => false, 'error' => 'Invalid customer.'];
    }
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'has_account' => false, 'error' => 'Account info could not be loaded.'];
    }
    $customer = psCustomerById($conn, $customerMasterId);
    if ($customer === null) {
        return ['ok' => false, 'has_account' => false, 'error' => 'Customer was not found.'];
    }
    if (!psCustomerStatusIsActive($customer['status'] ?? '')) {
        return ['ok' => false, 'has_account' => false, 'error' => 'That customer is not active.'];
    }
    $account = psCustomerAccountByCustomerMasterId($conn, $customerMasterId);
    if (!$account) {
        return [
            'ok' => true,
            'has_account' => false,
            'eligible' => false,
            'customer_name' => psCustomerDisplayName($customer),
            'customer_code' => (string)$customer['customer_code'],
        ];
    }
    $avail = psCustomerAccountAvailableCredit($conn, (int)$account['id'], $account['credit_limit']);
    if (!$avail['ok']) {
        return ['ok' => false, 'has_account' => true, 'error' => $avail['error'] ?? 'Account info could not be loaded.'];
    }
    $status = strtoupper(trim((string)$account['status']));
    $creditStatus = strtoupper(trim((string)($account['credit_status'] ?? PS_CUST_ACCT_CREDIT_NORMAL)));
    if ($creditStatus === '') {
        $creditStatus = PS_CUST_ACCT_CREDIT_NORMAL;
    }
    $limit = (string)$avail['credit_limit'];
    $eligible = $status === PS_CUST_ACCT_STATUS_ACTIVE
        && $creditStatus === PS_CUST_ACCT_CREDIT_NORMAL
        && (float)$limit > 0;
    return [
        'ok' => true,
        'has_account' => true,
        'eligible' => $eligible,
        'account_id' => (int)$account['id'],
        'account_number' => (string)$account['account_number'],
        'status' => $status,
        'credit_status' => $creditStatus,
        'balance' => (string)$avail['balance'],
        'credit_limit' => $limit,
        'available_credit' => (string)$avail['available_credit'],
        'customer_name' => psCustomerDisplayName($customer),
        'customer_code' => (string)$customer['customer_code'],
    ];
}

/**
 * Efficient CREDIT_SALE count + debit total for account view.
 *
 * @return array{ok:bool,count?:int,total?:string,error?:string}
 */
function psCustomerAccountCreditSaleStats(mysqli $conn, int $accountId): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Credit sale stats could not be loaded.'];
    }
    $type = PS_CUST_ACCT_ENTRY_CREDIT_SALE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS c, COALESCE(SUM(debit), 0) AS total_debit
         FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_type = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Credit sale stats could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 'is', $accountId, $type);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return [
        'ok' => true,
        'count' => (int)($row['c'] ?? 0),
        'total' => number_format((float)($row['total_debit'] ?? 0), 2, '.', ''),
    ];
}

/**
 * Insert a ledger entry. Validates: non-negative debit/credit; not both > 0; not both zero.
 * Phase 6.1 posts OPENING_BALANCE via admin workflow; Phase 6.2 posts CREDIT_SALE from POS sales.
 * Phase 6.3 posts PAYMENT credits via customer_account_payment.php (same helper).
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,error?:string}
 */
function psCustomerAccountLedgerInsert(mysqli $conn, array $data): array
{
    if (!psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Ledger entry could not be saved.'];
    }
    $accountId = (int)($data['customer_account_id'] ?? 0);
    if ($accountId < 1 || !psCustomerAccountById($conn, $accountId)) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $typeCheck = psNormalizeCustomerAccountEntryType($data['entry_type'] ?? '');
    if (!$typeCheck['ok']) {
        return ['ok' => false, 'error' => $typeCheck['error']];
    }
    $entryType = $typeCheck['value'];

    $entryDate = trim((string)($data['entry_date'] ?? ''));
    if ($entryDate === '') {
        $entryDate = date('Y-m-d');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $entryDate);
    if (!$dt || $dt->format('Y-m-d') !== $entryDate) {
        return ['ok' => false, 'error' => 'Entry date must be a valid date (YYYY-MM-DD).'];
    }

    $debitRaw = $data['debit'] ?? '0';
    $creditRaw = $data['credit'] ?? '0';
    $debitCheck = psValidateCustomerAccountMoney($debitRaw, 'Debit');
    if (!$debitCheck['ok']) {
        return ['ok' => false, 'error' => $debitCheck['error']];
    }
    $creditCheck = psValidateCustomerAccountMoney($creditRaw, 'Credit');
    if (!$creditCheck['ok']) {
        return ['ok' => false, 'error' => $creditCheck['error']];
    }
    $debit = $debitCheck['value'];
    $credit = $creditCheck['value'];
    $debitF = (float)$debit;
    $creditF = (float)$credit;

    if ($debitF > 0 && $creditF > 0) {
        return ['ok' => false, 'error' => 'A ledger entry cannot have both debit and credit greater than zero.'];
    }
    if ($debitF <= 0 && $creditF <= 0) {
        return ['ok' => false, 'error' => 'A ledger entry must have a non-zero debit or credit.'];
    }

    $description = trim((string)($data['description'] ?? ''));
    if (strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long.'];
    }
    $refType = trim((string)($data['reference_type'] ?? ''));
    $refId = trim((string)($data['reference_id'] ?? ''));
    if (strlen($refType) > 64 || strlen($refId) > 64) {
        return ['ok' => false, 'error' => 'Reference is too long.'];
    }
    $refTypeStore = $refType === '' ? null : $refType;
    $refIdStore = $refId === '' ? null : $refId;

    $createdBy = trim((string)($data['created_by'] ?? ''));
    if ($createdBy === '') {
        $createdBy = psSessionCustomerAccountUser();
    }
    if ($createdBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to post a ledger entry.'];
    }
    $now = date('Y-m-d H:i:s');

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_account_ledger
         (customer_account_id, entry_date, entry_type, debit, credit, reference_type, reference_id, description, created_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('customer account ledger insert prepare failed');
        return ['ok' => false, 'error' => 'Ledger entry could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'isssssssss',
        $accountId,
        $entryDate,
        $entryType,
        $debit,
        $credit,
        $refTypeStore,
        $refIdStore,
        $description,
        $now,
        $createdBy
    );
    if (!mysqli_stmt_execute($stmt)) {
        $dup = mysqli_errno($conn) === 1062 || mysqli_stmt_errno($stmt) === 1062;
        mysqli_stmt_close($stmt);
        error_log('customer account ledger insert failed');
        if ($dup) {
            return ['ok' => false, 'error' => 'A ledger entry with this reference already exists.', 'duplicate' => true];
        }
        return ['ok' => false, 'error' => 'Ledger entry could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $newId];
}

/**
 * Explicit admin opening-balance workflow only.
 * One OPENING_BALANCE entry per account; reversible later via adjustment (not delete) in future modules.
 * Does not invent balances from historical sales.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,error?:string}
 */
function psCustomerAccountPostOpeningBalance(mysqli $conn, int $accountId, array $data): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Opening balance could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to post an opening balance.'];
    }
    $account = psCustomerAccountById($conn, $accountId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $reason = trim((string)($data['reason'] ?? $data['description'] ?? ''));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A reason is required for an opening balance.'];
    }
    if (strlen($reason) > 500) {
        return ['ok' => false, 'error' => 'Reason is too long.'];
    }

    $side = strtoupper(trim((string)($data['side'] ?? 'DEBIT')));
    if ($side !== 'DEBIT' && $side !== 'CREDIT') {
        return ['ok' => false, 'error' => 'Opening balance side must be debit (customer owes) or credit (customer credit).'];
    }
    $amountCheck = psValidateCustomerAccountMoney($data['amount'] ?? '', 'Opening balance amount');
    if (!$amountCheck['ok']) {
        return ['ok' => false, 'error' => $amountCheck['error']];
    }
    if ((float)$amountCheck['value'] <= 0) {
        return ['ok' => false, 'error' => 'Opening balance amount must be greater than zero.'];
    }

    // Ignore any posted account_number / balance / customer name — use server account only.
    unset($data['account_number'], $data['balance'], $data['posted_balance']);

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Opening balance could not be saved.'];
    }

    $chk = mysqli_prepare(
        $conn,
        'SELECT id FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_type = ? LIMIT 1 FOR UPDATE'
    );
    if (!$chk) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Opening balance could not be saved.'];
    }
    $obType = PS_CUST_ACCT_ENTRY_OPENING_BALANCE;
    mysqli_stmt_bind_param($chk, 'is', $accountId, $obType);
    mysqli_stmt_execute($chk);
    $chkRes = mysqli_stmt_get_result($chk);
    $existingOb = $chkRes ? mysqli_fetch_assoc($chkRes) : null;
    mysqli_stmt_close($chk);
    if ($existingOb) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'This account already has an opening balance entry.'];
    }

    $debit = $side === 'DEBIT' ? $amountCheck['value'] : '0.00';
    $credit = $side === 'CREDIT' ? $amountCheck['value'] : '0.00';
    $insert = psCustomerAccountLedgerInsert($conn, [
        'customer_account_id' => $accountId,
        'entry_date' => $data['entry_date'] ?? date('Y-m-d'),
        'entry_type' => PS_CUST_ACCT_ENTRY_OPENING_BALANCE,
        'debit' => $debit,
        'credit' => $credit,
        'reference_type' => 'OPENING_BALANCE',
        'reference_id' => (string)($account['account_number'] ?? ''),
        'description' => $reason,
        'created_by' => $changedBy,
    ]);
    if (!$insert['ok']) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => (string)($insert['error'] ?? 'Opening balance could not be saved.')];
    }

    $now = date('Y-m-d H:i:s');
    $accountNumber = (string)$account['account_number'];
    if (!psCustomerAccountAuditRecordChanges($conn, [
        'customer_account_id' => $accountId,
        'account_number' => $accountNumber,
        'action' => PS_CUST_ACCT_AUDIT_OPENING_BALANCE,
        'changed_by' => $changedBy,
        'source' => PS_CUST_ACCT_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [
        [
            'field_name' => 'opening_balance_side',
            'old_value' => null,
            'new_value' => $side,
        ],
        [
            'field_name' => 'opening_balance_amount',
            'old_value' => null,
            'new_value' => $amountCheck['value'],
        ],
        [
            'field_name' => 'opening_balance_reason',
            'old_value' => null,
            'new_value' => $reason,
        ],
        [
            'field_name' => 'ledger_entry_id',
            'old_value' => null,
            'new_value' => (string)($insert['id'] ?? ''),
        ],
    ])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Opening balance could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Opening balance could not be saved.'];
    }
    return ['ok' => true, 'id' => (int)$insert['id']];
}

/**
 * Create receivable account explicitly from customer management.
 * Requires existing customer_master; rejects duplicate account for same customer.
 * Inactive customer behaviour: create allowed only for ACTIVE customers (admin can still
 * view existing accounts after later deactivation). Never silent-creates for legacy table.
 * Posted account_number / balance / name / code are ignored — server allocates ACC-*.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,account_number?:string,error?:string}
 */
function psCreateCustomerAccount(mysqli $conn, array $data): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to create an account.'];
    }

    $customerMasterId = (int)($data['customer_master_id'] ?? 0);
    if ($customerMasterId < 1) {
        return ['ok' => false, 'error' => 'A valid customer is required.'];
    }
    $customer = psCustomerById($conn, $customerMasterId);
    if (!$customer) {
        return ['ok' => false, 'error' => 'Customer was not found.'];
    }
    if (!psCustomerStatusIsActive($customer['status'] ?? '')) {
        return ['ok' => false, 'error' => 'Accounts can only be created for active customers. Activate the customer first.'];
    }
    if (psCustomerAccountByCustomerMasterId($conn, $customerMasterId)) {
        return ['ok' => false, 'error' => 'This customer already has an account.'];
    }

    // Ignore client-posted identity / balance fields.
    unset($data['account_number'], $data['balance'], $data['posted_balance'], $data['customer_code'], $data['customer_name']);

    $creditLimit = '0.00';
    if (array_key_exists('credit_limit', $data) && trim((string)$data['credit_limit']) !== '') {
        $cl = psValidateCustomerAccountMoney($data['credit_limit'], 'Credit limit');
        if (!$cl['ok']) {
            return ['ok' => false, 'error' => $cl['error']];
        }
        $creditLimit = $cl['value'];
    }

    // Multi-currency (2026-10-20): an account is kept in one currency for life (blank = company base).
    require_once __DIR__ . '/currency.php';
    if (!psFxEnsureTransactionColumns($conn)) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    $acctCompany = psFxSessionCompany();
    $acctCurCheck = psFxCheckTransactionCurrency($conn, $acctCompany, $data['currency'] ?? '');
    if (empty($acctCurCheck['ok'])) {
        return ['ok' => false, 'error' => (string)$acctCurCheck['error']];
    }
    $acctCurrency = (string)$acctCurCheck['code'];

    $status = PS_CUST_ACCT_STATUS_ACTIVE;
    $now = date('Y-m-d H:i:s');

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }

    // Re-check uniqueness inside transaction.
    $dupStmt = mysqli_prepare(
        $conn,
        'SELECT id FROM customer_accounts WHERE customer_master_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$dupStmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    mysqli_stmt_bind_param($dupStmt, 'i', $customerMasterId);
    mysqli_stmt_execute($dupStmt);
    $dupRes = mysqli_stmt_get_result($dupStmt);
    $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
    mysqli_stmt_close($dupStmt);
    if ($dupRow) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'This customer already has an account.'];
    }

    $alloc = psAllocateCustomerAccountNumber($conn);
    if (!$alloc['ok']) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => (string)($alloc['error'] ?? 'The account could not be saved.')];
    }
    $accountNumber = (string)$alloc['number'];

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_accounts
         (customer_master_id, account_number, status, credit_limit, created_at, updated_at, created_by, updated_by, currency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'' . mysqli_real_escape_string($conn, $acctCurrency) . '\')'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('customer account create prepare failed');
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'isssssss',
        $customerMasterId,
        $accountNumber,
        $status,
        $creditLimit,
        $now,
        $now,
        $changedBy,
        $changedBy
    );
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'This customer already has an account, or the account number is in use.'];
        }
        error_log('customer account create failed');
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $changes = [
        ['field_name' => 'account_number', 'old_value' => null, 'new_value' => $accountNumber],
        ['field_name' => 'customer_master_id', 'old_value' => null, 'new_value' => (string)$customerMasterId],
        ['field_name' => 'status', 'old_value' => null, 'new_value' => $status],
        ['field_name' => 'credit_limit', 'old_value' => null, 'new_value' => $creditLimit],
        ['field_name' => 'currency', 'old_value' => null, 'new_value' => $acctCurrency],
    ];
    if (!psCustomerAccountAuditRecordChanges($conn, [
        'customer_account_id' => $newId,
        'account_number' => $accountNumber,
        'action' => PS_CUST_ACCT_AUDIT_CREATE,
        'changed_by' => $changedBy,
        'source' => PS_CUST_ACCT_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    return ['ok' => true, 'id' => $newId, 'account_number' => $accountNumber];
}

/**
 * Update credit_limit only (account_number / customer_master_id / ledger immutable).
 * Phase 6.5: admin-only; reason required; LIMIT_UPDATE audit (old/new/reason);
 * SELECT … FOR UPDATE; ignore posted balance / credit_status / account_number.
 * Changing limit never alters ledger balance or receivables.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psUpdateCustomerAccount(mysqli $conn, int $accountId, array $data): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to update an account.'];
    }
    if (!psCustomerAccountMaySetLimit()) {
        return ['ok' => false, 'error' => 'Only a Supervisor, Manager or Admin can change the credit limit.'];
    }

    // Ignore tampered identity / balance / credit_status / posted limit authority.
    unset(
        $data['account_number'],
        $data['balance'],
        $data['posted_balance'],
        $data['customer_master_id'],
        $data['credit_status'],
        $data['status']
    );

    if (!array_key_exists('credit_limit', $data)) {
        return ['ok' => true, 'changed' => false];
    }
    $cl = psValidateCustomerAccountMoney($data['credit_limit'], 'Credit limit');
    if (!$cl['ok']) {
        return ['ok' => false, 'error' => $cl['error']];
    }
    $newLimit = $cl['value'];

    $reason = trim((string)($data['reason'] ?? $data['limit_reason'] ?? ''));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A reason is required when changing the credit limit.'];
    }
    if (strlen($reason) > 500) {
        return ['ok' => false, 'error' => 'Reason is too long.'];
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    $existing = psCustomerAccountLockById($conn, $accountId);
    if (!$existing) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $oldLimit = number_format((float)($existing['credit_limit'] ?? 0), 2, '.', '');
    if ($oldLimit === $newLimit) {
        mysqli_commit($conn);
        return ['ok' => true, 'changed' => false];
    }

    $now = date('Y-m-d H:i:s');
    $accountNumber = (string)$existing['account_number'];

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE customer_accounts SET credit_limit = ?, updated_at = ?, updated_by = ? WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssi', $newLimit, $now, $changedBy, $accountId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        error_log('customer account update failed');
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psCustomerAccountAuditRecordChanges($conn, [
        'customer_account_id' => $accountId,
        'account_number' => $accountNumber,
        'action' => PS_CUST_ACCT_AUDIT_LIMIT_UPDATE,
        'changed_by' => $changedBy,
        'source' => PS_CUST_ACCT_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [
        [
            'field_name' => 'credit_limit',
            'old_value' => $oldLimit,
            'new_value' => $newLimit,
        ],
        [
            'field_name' => 'limit_reason',
            'old_value' => null,
            'new_value' => $reason,
        ],
    ])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The account could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Phase 6.5 — place credit hold (credit_status → ON_HOLD).
 * Blocks new CREDIT sales only. Does not close account, alter ledger, or auto-hold from ageing.
 * Reason required (controlled set). Append-only HOLD audit (prior reasons retained).
 * Admin-only. State change + audit in one txn; audit fail → ROLLBACK.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psCustomerAccountPlaceCreditHold(mysqli $conn, int $accountId, array $data): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Credit hold could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to place a credit hold.'];
    }
    if (!psCustomerAccountCreditControlMayManage()) {
        return ['ok' => false, 'error' => 'Only an administrator can place or release a credit hold.'];
    }

    $reasonCheck = psNormalizeCustomerAccountCreditHoldReason($data['reason'] ?? '');
    if (!$reasonCheck['ok']) {
        return ['ok' => false, 'error' => $reasonCheck['error']];
    }
    $reason = $reasonCheck['value'];
    $note = trim((string)($data['note'] ?? $data['hold_note'] ?? ''));
    if ($reason === 'OTHER' && $note === '') {
        return ['ok' => false, 'error' => 'A note is required when the hold reason is Other.'];
    }
    if (strlen($note) > 500) {
        return ['ok' => false, 'error' => 'Hold note is too long.'];
    }

    // Ignore stale browser credit_status / balance / limit / overdue flags.
    unset($data['credit_status'], $data['balance'], $data['credit_limit'], $data['status'], $data['overdue']);

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Credit hold could not be saved.'];
    }
    $existing = psCustomerAccountLockById($conn, $accountId);
    if (!$existing) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $oldCredit = strtoupper(trim((string)($existing['credit_status'] ?? PS_CUST_ACCT_CREDIT_NORMAL)));
    if ($oldCredit === '') {
        $oldCredit = PS_CUST_ACCT_CREDIT_NORMAL;
    }
    if ($oldCredit === PS_CUST_ACCT_CREDIT_ON_HOLD) {
        mysqli_commit($conn);
        return ['ok' => true, 'changed' => false];
    }

    $now = date('Y-m-d H:i:s');
    $accountNumber = (string)$existing['account_number'];
    $newCredit = PS_CUST_ACCT_CREDIT_ON_HOLD;
    $noteStore = $note; // empty string when no note (mysqli-safe; display treats blank as none)

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE customer_accounts
         SET credit_status = ?, credit_hold_reason = ?, credit_hold_note = ?,
             updated_at = ?, updated_by = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Credit hold could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssi',
        $newCredit,
        $reason,
        $noteStore,
        $now,
        $changedBy,
        $accountId
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        error_log('customer account credit hold failed');
        return ['ok' => false, 'error' => 'Credit hold could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    $auditChanges = [
        [
            'field_name' => 'credit_status',
            'old_value' => $oldCredit,
            'new_value' => $newCredit,
        ],
        [
            'field_name' => 'hold_reason',
            'old_value' => null,
            'new_value' => $reason,
        ],
    ];
    if ($note !== '') {
        $auditChanges[] = [
            'field_name' => 'hold_note',
            'old_value' => null,
            'new_value' => $note,
        ];
    }
    if (!psCustomerAccountAuditRecordChanges($conn, [
        'customer_account_id' => $accountId,
        'account_number' => $accountNumber,
        'action' => PS_CUST_ACCT_AUDIT_HOLD,
        'changed_by' => $changedBy,
        'source' => PS_CUST_ACCT_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $auditChanges)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Credit hold could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Credit hold could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Phase 6.5 — release credit hold (credit_status → NORMAL).
 * Does not reopen CLOSED/SUSPENDED lifecycle status. Append-only RELEASE_HOLD audit.
 * Admin-only. Cashiers cannot release hold via POS to complete a credit sale.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psCustomerAccountReleaseCreditHold(mysqli $conn, int $accountId, array $data = []): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Credit hold could not be released.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to release a credit hold.'];
    }
    if (!psCustomerAccountCreditControlMayManage()) {
        return ['ok' => false, 'error' => 'Only an administrator can place or release a credit hold.'];
    }

    $note = trim((string)($data['note'] ?? $data['release_note'] ?? ''));
    if (strlen($note) > 500) {
        return ['ok' => false, 'error' => 'Release note is too long.'];
    }
    unset($data['credit_status'], $data['balance'], $data['credit_limit'], $data['status']);

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Credit hold could not be released.'];
    }
    $existing = psCustomerAccountLockById($conn, $accountId);
    if (!$existing) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $oldCredit = strtoupper(trim((string)($existing['credit_status'] ?? PS_CUST_ACCT_CREDIT_NORMAL)));
    if ($oldCredit === '') {
        $oldCredit = PS_CUST_ACCT_CREDIT_NORMAL;
    }
    if ($oldCredit !== PS_CUST_ACCT_CREDIT_ON_HOLD) {
        mysqli_commit($conn);
        return ['ok' => true, 'changed' => false];
    }

    $prevReason = trim((string)($existing['credit_hold_reason'] ?? ''));
    $prevNote = trim((string)($existing['credit_hold_note'] ?? ''));
    $now = date('Y-m-d H:i:s');
    $accountNumber = (string)$existing['account_number'];
    $newCredit = PS_CUST_ACCT_CREDIT_NORMAL;
    // Clear current hold display fields (history retained in customer_account_audit).
    $clearReason = '';
    $clearNote = '';

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE customer_accounts
         SET credit_status = ?, credit_hold_reason = NULL, credit_hold_note = NULL,
             updated_at = ?, updated_by = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Credit hold could not be released.'];
    }
    // Keep unused clears for readability; SQL NULLs clear current display only.
    unset($clearReason, $clearNote);
    mysqli_stmt_bind_param(
        $stmt,
        'sssi',
        $newCredit,
        $now,
        $changedBy,
        $accountId
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        error_log('customer account credit hold release failed');
        return ['ok' => false, 'error' => 'Credit hold could not be released.'];
    }
    mysqli_stmt_close($stmt);

    $auditChanges = [
        [
            'field_name' => 'credit_status',
            'old_value' => $oldCredit,
            'new_value' => $newCredit,
        ],
    ];
    if ($prevReason !== '') {
        $auditChanges[] = [
            'field_name' => 'previous_hold_reason',
            'old_value' => $prevReason,
            'new_value' => null,
        ];
    }
    if ($prevNote !== '') {
        $auditChanges[] = [
            'field_name' => 'previous_hold_note',
            'old_value' => $prevNote,
            'new_value' => null,
        ];
    }
    if ($note !== '') {
        $auditChanges[] = [
            'field_name' => 'release_note',
            'old_value' => null,
            'new_value' => $note,
        ];
    }
    if (!psCustomerAccountAuditRecordChanges($conn, [
        'customer_account_id' => $accountId,
        'account_number' => $accountNumber,
        'action' => PS_CUST_ACCT_AUDIT_RELEASE_HOLD,
        'changed_by' => $changedBy,
        'source' => PS_CUST_ACCT_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $auditChanges)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Credit hold could not be released.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Credit hold could not be released.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Lifecycle: ACTIVE ↔ SUSPENDED ↔ CLOSED via ACTIVATE / SUSPEND / CLOSE.
 * Arbitrary invalid status rejected. Never hard-deletes.
 *
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psSetCustomerAccountStatus(mysqli $conn, int $accountId, string $newStatus): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Account status could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to change account status.'];
    }
    $normalized = psNormalizeCustomerAccountStatus($newStatus);
    if (!$normalized['ok']) {
        return ['ok' => false, 'error' => $normalized['error']];
    }
    $existing = psCustomerAccountById($conn, $accountId);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    $status = $normalized['value'];
    $oldStatus = strtoupper(trim((string)($existing['status'] ?? PS_CUST_ACCT_STATUS_ACTIVE)));
    if ($oldStatus === $status) {
        return ['ok' => true, 'changed' => false];
    }

    if ($status === PS_CUST_ACCT_STATUS_ACTIVE) {
        $action = PS_CUST_ACCT_AUDIT_ACTIVATE;
    } elseif ($status === PS_CUST_ACCT_STATUS_SUSPENDED) {
        $action = PS_CUST_ACCT_AUDIT_SUSPEND;
    } else {
        $action = PS_CUST_ACCT_AUDIT_CLOSE;
    }

    $now = date('Y-m-d H:i:s');
    $accountNumber = (string)$existing['account_number'];

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Account status could not be saved.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE customer_accounts SET status = ?, updated_at = ?, updated_by = ? WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account status could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssi', $status, $now, $changedBy, $accountId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        error_log('customer account status update failed');
        return ['ok' => false, 'error' => 'Account status could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psCustomerAccountAuditRecordChanges($conn, [
        'customer_account_id' => $accountId,
        'account_number' => $accountNumber,
        'action' => $action,
        'changed_by' => $changedBy,
        'source' => PS_CUST_ACCT_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $oldStatus,
        'new_value' => $status,
    ]])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account status could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account status could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Hard delete is not allowed.
 *
 * @return array{ok:bool,error?:string}
 */
function psDeleteCustomerAccountBlocked(mysqli $conn, int $id): array
{
    unset($conn, $id);
    return ['ok' => false, 'error' => 'Customer accounts cannot be deleted. Close or suspend instead.'];
}

/**
 * Account list with customer code/name and ledger-derived balance (single aggregate — no N+1).
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psCustomerAccountList(mysqli $conn, string $search = '', string $statusFilter = 'all'): array
{
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'error' => 'Accounts could not be loaded.', 'rows' => []];
    }

    $statusFilter = strtolower(trim($statusFilter));
    if (!in_array($statusFilter, ['all', 'active', 'suspended', 'closed'], true)) {
        $statusFilter = 'all';
    }

    $sql = 'SELECT a.*,
                   c.customer_code,
                   c.first_name,
                   c.last_name,
                   c.business_name,
                   c.customer_type,
                   c.status AS customer_status,
                   COALESCE(lb.balance, 0) AS balance
            FROM customer_accounts a
            INNER JOIN customer_master c ON c.id = a.customer_master_id
            LEFT JOIN (
                SELECT customer_account_id,
                       SUM(debit) - SUM(credit) AS balance
                FROM customer_account_ledger
                GROUP BY customer_account_id
            ) lb ON lb.customer_account_id = a.id
            WHERE ' . psCustomerCompanySql($conn, 'c');
    $types = '';
    $params = [];

    if ($statusFilter === 'active') {
        $sql .= ' AND a.status = ?';
        $types .= 's';
        $params[] = PS_CUST_ACCT_STATUS_ACTIVE;
    } elseif ($statusFilter === 'suspended') {
        $sql .= ' AND a.status = ?';
        $types .= 's';
        $params[] = PS_CUST_ACCT_STATUS_SUSPENDED;
    } elseif ($statusFilter === 'closed') {
        $sql .= ' AND a.status = ?';
        $types .= 's';
        $params[] = PS_CUST_ACCT_STATUS_CLOSED;
    }

    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (a.account_number LIKE ? OR c.customer_code LIKE ?
                     OR c.first_name LIKE ? OR c.last_name LIKE ?
                     OR c.business_name LIKE ?)';
        $types .= 'sssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT 500';

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('customer account list prepare failed');
        return ['ok' => false, 'error' => 'Accounts could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        $bind = [$stmt, $types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['balance'] = number_format((float)($row['balance'] ?? 0), 2, '.', '');
        $row['display_name'] = psCustomerDisplayName($row);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Paginated ledger history for an account.
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,total?:int,page?:int,page_size?:int,pages?:int,error?:string}
 */
function psCustomerAccountLedgerHistory(mysqli $conn, int $accountId, array $filters = []): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Ledger history could not be loaded.', 'rows' => []];
    }
    if (!psCustomerAccountById($conn, $accountId)) {
        return ['ok' => false, 'error' => 'Account was not found.', 'rows' => []];
    }

    $page = (int)($filters['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }
    $pageSize = (int)($filters['page_size'] ?? 25);
    if ($pageSize < 1 || $pageSize > 100) {
        $pageSize = 25;
    }
    $offset = ($page - 1) * $pageSize;

    $countStmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS c FROM customer_account_ledger WHERE customer_account_id = ?'
    );
    if (!$countStmt) {
        return ['ok' => false, 'error' => 'Ledger history could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($countStmt, 'i', $accountId);
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $countRow = $countRes ? mysqli_fetch_assoc($countRes) : null;
    mysqli_stmt_close($countStmt);
    $total = (int)($countRow['c'] ?? 0);
    $pages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 1;
    if ($pages < 1) {
        $pages = 1;
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM customer_account_ledger
         WHERE customer_account_id = ?
         ORDER BY entry_date DESC, id DESC
         LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Ledger history could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 'iii', $accountId, $pageSize, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['debit'] = number_format((float)$row['debit'], 2, '.', '');
        $row['credit'] = number_format((float)$row['credit'], 2, '.', '');
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages,
    ];
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psCustomerAccountAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCustomerAccountAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Account audit history could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM customer_account_audit WHERE ' . psCustomerAccountIdScopeSql($conn, 'customer_account_id');
    $types = '';
    $params = [];
    $accountId = (int)($filters['customer_account_id'] ?? 0);
    $number = trim((string)($filters['account_number'] ?? ''));
    $limit = (int)($filters['limit'] ?? 300);
    if ($limit < 1 || $limit > 1000) {
        $limit = 300;
    }
    if ($accountId > 0) {
        $sql .= ' AND customer_account_id = ?';
        $types .= 'i';
        $params[] = $accountId;
    }
    if ($number !== '') {
        $sql .= ' AND account_number = ?';
        $types .= 's';
        $params[] = $number;
    }
    $sql .= ' ORDER BY changed_at DESC, id DESC LIMIT ?';
    $types .= 'i';
    $params[] = $limit;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Account audit history could not be loaded.', 'rows' => []];
    }
    $bind = [$stmt, $types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Profile summary for customer_view: whether account exists, number, status, ledger balance.
 * Empty ledger → balance 0.00 (not labeled "amount owed" unless positive receivable later).
 *
 * @return array{ok:bool,has_account:bool,account?:?array,balance?:string,error?:string}
 */
function psCustomerAccountSummaryForCustomer(mysqli $conn, int $customerMasterId): array
{
    if ($customerMasterId < 1) {
        return ['ok' => false, 'has_account' => false, 'error' => 'Invalid customer.'];
    }
    if (!psEnsureCustomerAccountModule($conn)) {
        return ['ok' => false, 'has_account' => false, 'error' => 'Account summary could not be loaded.'];
    }
    $account = psCustomerAccountByCustomerMasterId($conn, $customerMasterId);
    if (!$account) {
        return ['ok' => true, 'has_account' => false, 'account' => null, 'balance' => '0.00'];
    }
    $bal = psCustomerAccountBalance($conn, (int)$account['id']);
    $balance = $bal['ok'] ? (string)$bal['balance'] : '0.00';
    return [
        'ok' => true,
        'has_account' => true,
        'account' => $account,
        'balance' => $balance,
    ];
}
