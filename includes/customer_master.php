<?php
require_once __DIR__ . '/tx_nesting.php';
/**
 * Phase 5.1 — Customer master & identity foundation.
 * Phase 5.2 — POS sale identity uses customer_master.id (online_sales_ref.customer_master_id).
 * Phase 5.3 — Customer profile + read-only sales history via customer_master_id only.
 * Phase 5.4 — Finalisation / regression closure (same helpers; no credit/AR).
 * Global customer identity by customer_code / id (not name/phone/email/national_id alone).
 * Does not write stock, goods.quantity, sale_invoice_seq.
 *
 * Legacy table `customers` (fullname/contact/provisional_number/…) remains untouched;
 * it is not this master. Legacy online_sales_ref.customer_id still means customers.id
 * and is distinct from customer_master_id.
 *
 * Transaction history (5.3) links ONLY online_sales_ref.customer_master_id → customer_master.id.
 * Never name/phone/email/legacy customer_id/Cash Customer. Not a balance/AR view.
 * Customers are global — history may span branches (no invented role filter).
 */

const PS_CUSTOMER_STATUS_ACTIVE = 'ACTIVE';
const PS_CUSTOMER_STATUS_INACTIVE = 'INACTIVE';

const PS_CUSTOMER_TYPE_INDIVIDUAL = 'INDIVIDUAL';
const PS_CUSTOMER_TYPE_BUSINESS = 'BUSINESS';

const PS_CUSTOMER_AUDIT_SOURCE_UI = 'UI';
const PS_CUSTOMER_AUDIT_ACTION_CREATE = 'CREATE';
const PS_CUSTOMER_AUDIT_ACTION_UPDATE = 'UPDATE';
const PS_CUSTOMER_AUDIT_ACTION_ACTIVATE = 'ACTIVATE';
const PS_CUSTOMER_AUDIT_ACTION_DEACTIVATE = 'DEACTIVATE';

/**
 * Dedicated customer code counter. Format: CUS-000001.
 * Never uses sale_invoice_seq / purchase_order_seq / goods_receipt_seq / purchase_return_seq / MAX(id)+1.
 */
function psEnsureCustomerSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS customer_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM customer_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO customer_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('customer_seq was not seeded');
    return false;
}

/**
 * Ensure authoritative customer_master exists. Safe to call repeatedly.
 * No branch column — customers are global (like supplier_master).
 */
function psEnsureCustomerMasterSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureCustomerSeq($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_master LIMIT 0');
    if ($check) {
        psCustomerCompanyMigrate($conn);
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_master (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(64) NOT NULL,
        first_name VARCHAR(250) NOT NULL DEFAULT '',
        last_name VARCHAR(250) NOT NULL DEFAULT '',
        business_name VARCHAR(250) NULL DEFAULT NULL,
        phone VARCHAR(64) NOT NULL DEFAULT '',
        email VARCHAR(250) NOT NULL DEFAULT '',
        address VARCHAR(500) NOT NULL DEFAULT '',
        national_id VARCHAR(64) NULL DEFAULT NULL,
        customer_type VARCHAR(20) NOT NULL DEFAULT 'INDIVIDUAL',
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        created_by VARCHAR(250) NOT NULL DEFAULT '',
        updated_by VARCHAR(250) NOT NULL DEFAULT '',
        company_name VARCHAR(250) NULL DEFAULT NULL,
        UNIQUE KEY uniq_customer_master_code (customer_code),
        UNIQUE KEY uniq_customer_master_company_nid (company_name, national_id),
        KEY idx_customer_master_company (company_name),
        KEY idx_customer_master_status (status),
        KEY idx_customer_master_type (customer_type),
        KEY idx_customer_master_phone (phone),
        KEY idx_customer_master_email (email),
        KEY idx_customer_master_last_name (last_name),
        KEY idx_customer_master_first_name (first_name),
        KEY idx_customer_master_business_name (business_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_master schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * 2026-10-18 — customers belong to one company.
 * Adds customer_master.company_name once, then fills it for older customers from the company of the
 * user who created them. National ID is unique within a company, not across companies.
 * Customers whose creator is unknown keep a blank company and are not shown to any company.
 */
function psCustomerCompanyMigrate(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $has = mysqli_query($conn, "SHOW COLUMNS FROM customer_master LIKE 'company_name'");
    if (!$has || mysqli_num_rows($has) === 0) {
        if (!mysqli_query($conn, 'ALTER TABLE customer_master ADD COLUMN company_name VARCHAR(250) NULL DEFAULT NULL, ADD KEY idx_customer_master_company (company_name)')
            && mysqli_errno($conn) !== 1060) {
            error_log('customer_master: company column was not added: #' . mysqli_errno($conn));
            return;
        }
    }
    $idx = mysqli_query($conn, "SHOW INDEX FROM customer_master WHERE Key_name = 'uniq_customer_master_national_id'");
    if ($idx && mysqli_num_rows($idx) > 0) {
        if (mysqli_query($conn, 'ALTER TABLE customer_master ADD UNIQUE KEY uniq_customer_master_company_nid (company_name, national_id)')
            || mysqli_errno($conn) === 1061) {
            mysqli_query($conn, 'ALTER TABLE customer_master DROP INDEX uniq_customer_master_national_id');
        }
    }
    psCustomerCompanyBackfill($conn);
}

/**
 * Fill company_name for customers that have none, from the company of the user who created them.
 * Done in PHP (not a cross-table SQL join) so differing text collations between tables cannot stop it.
 */
function psCustomerCompanyBackfill(mysqli $conn): void
{
    $res = mysqli_query($conn, "SELECT DISTINCT created_by FROM customer_master WHERE company_name IS NULL OR company_name = ''");
    if (!$res) {
        error_log('customer_master: company backfill read failed: #' . mysqli_errno($conn));
        return;
    }
    $creators = [];
    while ($row = mysqli_fetch_row($res)) {
        $by = trim((string)$row[0]);
        if ($by !== '') {
            $creators[] = $by;
        }
    }
    if ($creators === []) {
        return;
    }
    $users = [];
    $u = mysqli_query($conn, "SELECT email, company_name FROM users WHERE company_name IS NOT NULL AND company_name <> ''");
    while ($u && ($row = mysqli_fetch_assoc($u))) {
        $users[strtolower(trim((string)$row['email']))] = trim((string)$row['company_name']);
    }
    $upd = mysqli_prepare($conn, "UPDATE customer_master SET company_name = ? WHERE created_by = ? AND (company_name IS NULL OR company_name = '')");
    if (!$upd) {
        error_log('customer_master: company backfill prepare failed: #' . mysqli_errno($conn));
        return;
    }
    foreach ($creators as $by) {
        $co = $users[strtolower($by)] ?? '';
        if ($co === '') {
            continue;
        }
        mysqli_stmt_bind_param($upd, 'ss', $co, $by);
        if (!mysqli_stmt_execute($upd)) {
            error_log('customer_master: company backfill write failed: #' . mysqli_errno($conn));
        }
    }
    mysqli_stmt_close($upd);
}

/**
 * The company whose customers the caller may see: the stand-in user's company during desktop sync,
 * otherwise the signed-in company. '' only outside a company (platform console, CLI).
 */
function psCustomerCompany(mysqli $conn): string
{
    static $byActor = [];
    if (function_exists('psActorOverride') && ($a = psActorOverride()) !== null) {
        $key = strtolower($a);
        if (!array_key_exists($key, $byActor)) {
            $stmt = mysqli_prepare($conn, 'SELECT company_name FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
            $co = '';
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $a);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                $row = $r ? mysqli_fetch_assoc($r) : null;
                mysqli_stmt_close($stmt);
                $co = trim((string)($row['company_name'] ?? ''));
            }
            $byActor[$key] = $co;
        }
        return $byActor[$key];
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['the_company'] ?? ''));
}

/** "<col> IN (customers of the caller's company)" for tables keyed by customer_master id. */
function psCustomerIdScopeSql(mysqli $conn, string $col): string
{
    return $col . ' IN (SELECT id FROM customer_master WHERE ' . psCustomerCompanySql($conn) . ')';
}

/** "<col> IN (accounts of the caller's company's customers)" for tables keyed by customer_accounts id. */
function psCustomerAccountIdScopeSql(mysqli $conn, string $col): string
{
    return $col . ' IN (SELECT sa.id FROM customer_accounts sa INNER JOIN customer_master sc ON sc.id = sa.customer_master_id WHERE '
        . psCustomerCompanySql($conn, 'sc') . ')';
}

/** SQL condition limiting customer_master rows (optionally aliased) to the caller's company. */
function psCustomerCompanySql(mysqli $conn, string $alias = ''): string
{
    $co = psCustomerCompany($conn);
    if ($co === '') {
        // Signed in with no company (e.g. Super Admin console): never list other companies' customers.
        $signedIn = session_status() === PHP_SESSION_ACTIVE && trim((string)($_SESSION['logger'] ?? '')) !== '';
        $actor = function_exists('psActorOverride') && psActorOverride() !== null;
        return ($signedIn || $actor) ? '0' : '1';
    }
    $col = ($alias !== '' ? $alias . '.' : '') . 'company_name';
    return $col . " = '" . mysqli_real_escape_string($conn, $co) . "'";
}

/**
 * Ensure customer_audit exists. Append-only in application code.
 */
function psEnsureCustomerAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        customer_code VARCHAR(64) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_customer_audit_customer_id (customer_id),
        KEY idx_customer_audit_code (customer_code),
        KEY idx_customer_audit_changed_at (changed_at),
        KEY idx_customer_audit_changed_by (changed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psSessionCustomerUser(): string
{
    if (function_exists('psActorOverride') && ($a = psActorOverride()) !== null) {
        return $a;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psNormalizeCustomerStatus($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if ($value === PS_CUSTOMER_STATUS_ACTIVE || $value === PS_CUSTOMER_STATUS_INACTIVE) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Status must be Active or Inactive.'];
}

function psCustomerStatusIsActive($status): bool
{
    $check = psNormalizeCustomerStatus($status);
    return $check['ok'] && $check['value'] === PS_CUSTOMER_STATUS_ACTIVE;
}

function psCustomerStatusLabel(string $status): string
{
    $check = psNormalizeCustomerStatus($status);
    if (!$check['ok']) {
        return 'Unknown';
    }
    return $check['value'] === PS_CUSTOMER_STATUS_ACTIVE ? 'Active' : 'Inactive';
}

function psNormalizeCustomerType($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if ($value === PS_CUSTOMER_TYPE_INDIVIDUAL || $value === PS_CUSTOMER_TYPE_BUSINESS) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Customer type must be Individual or Business.'];
}

function psCustomerTypeLabel(string $type): string
{
    $check = psNormalizeCustomerType($type);
    if (!$check['ok']) {
        return 'Unknown';
    }
    return $check['value'] === PS_CUSTOMER_TYPE_BUSINESS ? 'Business' : 'Individual';
}

/**
 * Display label for lists/views (not identity).
 *
 * @param array<string,mixed> $row
 */
function psCustomerDisplayName(array $row): string
{
    $type = strtoupper(trim((string)($row['customer_type'] ?? PS_CUSTOMER_TYPE_INDIVIDUAL)));
    $business = trim((string)($row['business_name'] ?? ''));
    if ($type === PS_CUSTOMER_TYPE_BUSINESS && $business !== '') {
        return $business;
    }
    $first = trim((string)($row['first_name'] ?? ''));
    $last = trim((string)($row['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    if ($name !== '') {
        return $name;
    }
    if ($business !== '') {
        return $business;
    }
    $code = trim((string)($row['customer_code'] ?? ''));
    return $code !== '' ? $code : 'Customer';
}

function psValidateCustomerOptionalText(string $raw, string $label, int $maxLen): array
{
    $value = trim($raw);
    if (strlen($value) > $maxLen) {
        return ['ok' => false, 'error' => $label . ' is too long.'];
    }
    return ['ok' => true, 'value' => $value];
}

function psValidateCustomerEmail(string $raw): array
{
    $email = trim($raw);
    if ($email === '') {
        return ['ok' => true, 'value' => ''];
    }
    if (strlen($email) > 250) {
        return ['ok' => false, 'error' => 'Email is too long.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email format is not valid.'];
    }
    return ['ok' => true, 'value' => $email];
}

/**
 * National ID optional; empty stored as NULL so UNIQUE allows many blanks.
 */
function psValidateCustomerNationalId(string $raw): array
{
    $value = trim($raw);
    if ($value === '') {
        return ['ok' => true, 'value' => null];
    }
    if (strlen($value) > 64) {
        return ['ok' => false, 'error' => 'National ID is too long.'];
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\/\- ]*$/', $value)) {
        return ['ok' => false, 'error' => 'National ID format is not valid.'];
    }
    return ['ok' => true, 'value' => $value];
}

/**
 * Normalize phone for duplicate comparison (digits and leading + only).
 */
function psNormalizePhoneKey(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    $plus = strpos($phone, '+') === 0 ? '+' : '';
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    return $plus . $digits;
}

function psNormalizeEmailKey(string $email): string
{
    return strtolower(trim($email));
}

function psNormalizeBusinessNameKey(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name);
    return is_string($name) ? $name : '';
}

/**
 * @return array<int,string>
 */
function psCustomerAuditFieldNames(): array
{
    return [
        'customer_code',
        'first_name',
        'last_name',
        'business_name',
        'phone',
        'email',
        'address',
        'national_id',
        'customer_type',
        'status',
        'notes',
    ];
}

function psCustomerAuditNormalizeValue(string $field, $raw): ?string
{
    if ($field === 'status' || $field === 'customer_type') {
        if ($raw === null) {
            return null;
        }
        $value = strtoupper(trim((string)$raw));
        return $value === '' ? null : $value;
    }
    if ($field === 'business_name' || $field === 'national_id' || $field === 'notes') {
        if ($raw === null) {
            return null;
        }
        $value = trim((string)$raw);
        return $value === '' ? null : $value;
    }
    if ($raw === null) {
        return null;
    }
    return trim((string)$raw);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,?string>
 */
function psCustomerAuditSnapshot(array $row): array
{
    $snap = [];
    foreach (psCustomerAuditFieldNames() as $field) {
        $snap[$field] = psCustomerAuditNormalizeValue(
            $field,
            array_key_exists($field, $row) ? $row[$field] : null
        );
    }
    return $snap;
}

/**
 * @param array<string,?string> $before
 * @param array<string,?string> $after
 * @return array<int,array{field_name:string,old_value:?string,new_value:?string}>
 */
function psCustomerAuditDiff(array $before, array $after): array
{
    $changes = [];
    foreach (psCustomerAuditFieldNames() as $field) {
        $oldN = psCustomerAuditNormalizeValue($field, $before[$field] ?? null);
        $newN = psCustomerAuditNormalizeValue($field, $after[$field] ?? null);
        if ($oldN === $newN) {
            continue;
        }
        $changes[] = [
            'field_name' => $field,
            'old_value' => $oldN,
            'new_value' => $newN,
        ];
    }
    return $changes;
}

function psCustomerAuditInsertRow(
    mysqli $conn,
    int $customerId,
    string $customerCode,
    string $action,
    string $fieldName,
    $oldValue,
    $newValue,
    string $changedBy,
    string $source = PS_CUSTOMER_AUDIT_SOURCE_UI,
    string $changedAt = ''
): bool {
    if (!psEnsureCustomerAuditSchema($conn)) {
        return false;
    }
    $customerCode = trim($customerCode);
    $action = strtoupper(trim($action));
    $fieldName = trim($fieldName);
    $changedBy = trim($changedBy);
    $source = trim($source);
    if ($customerId < 1 || $customerCode === '' || $action === '' || $fieldName === '') {
        return false;
    }
    if ($changedBy === '') {
        $changedBy = psSessionCustomerUser();
    }
    if ($changedBy === '') {
        return false;
    }
    if ($source === '') {
        $source = PS_CUSTOMER_AUDIT_SOURCE_UI;
    }
    if ($changedAt === '') {
        $changedAt = date('Y-m-d H:i:s');
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_audit
         (customer_id, customer_code, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('customer audit insert prepare failed');
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'issssssss',
        $customerId,
        $customerCode,
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
        error_log('customer audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * @param array<string,mixed> $meta
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psCustomerAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    $customerId = (int)($meta['customer_id'] ?? 0);
    $customerCode = trim((string)($meta['customer_code'] ?? ''));
    $action = strtoupper(trim((string)($meta['action'] ?? '')));
    $source = trim((string)($meta['source'] ?? PS_CUSTOMER_AUDIT_SOURCE_UI));
    $changedBy = trim((string)($meta['changed_by'] ?? ''));
    $changedAt = trim((string)($meta['changed_at'] ?? ''));
    if ($changedBy === '') {
        $changedBy = psSessionCustomerUser();
    }
    foreach ($changes as $change) {
        $field = (string)($change['field_name'] ?? '');
        if ($field === '') {
            continue;
        }
        if (!psCustomerAuditInsertRow(
            $conn,
            $customerId,
            $customerCode,
            $action,
            $field,
            $change['old_value'] ?? null,
            $change['new_value'] ?? null,
            $changedBy,
            $source,
            $changedAt
        )) {
            return false;
        }
    }
    return true;
}

/**
 * Allocate next CUS-###### inside an open transaction (SELECT … FOR UPDATE).
 *
 * @return array{ok:bool,code?:string,error?:string}
 */
function psAllocateCustomerCode(mysqli $conn): array
{
    if (!psEnsureCustomerSeq($conn)) {
        return ['ok' => false, 'error' => 'Customer code could not be allocated.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM customer_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('customer code allocation failed');
        return ['ok' => false, 'error' => 'Customer code could not be allocated.'];
    }
    $n = (int)$row['next_number'];
    if ($n < 1 || $n > 999999) {
        return ['ok' => false, 'error' => 'Customer code sequence is exhausted.'];
    }
    $code = 'CUS-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    $next = $n + 1;
    $upd = mysqli_prepare($conn, 'UPDATE customer_seq SET next_number = ? WHERE id = 1');
    if (!$upd) {
        return ['ok' => false, 'error' => 'Customer code could not be allocated.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $next);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        error_log('customer code allocation update failed');
        return ['ok' => false, 'error' => 'Customer code could not be allocated.'];
    }
    mysqli_stmt_close($upd);
    return ['ok' => true, 'code' => $code];
}

function psCustomerById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsureCustomerMasterSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_master WHERE id = ? AND ' . psCustomerCompanySql($conn) . ' LIMIT 1');
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

function psCustomerByCode(mysqli $conn, string $code): ?array
{
    $code = trim($code);
    if ($code === '' || !psEnsureCustomerMasterSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_master WHERE customer_code = ? AND ' . psCustomerCompanySql($conn) . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Batch-load customer_master rows by id (single query; avoids N+1 on listings).
 *
 * @param array<int,int|string> $ids
 * @return array<int,array<string,mixed>> map id => row
 */
function psCustomersByIds(mysqli $conn, array $ids): array
{
    if (!psEnsureCustomerMasterSchema($conn)) {
        return [];
    }
    $clean = [];
    foreach ($ids as $id) {
        $n = (int)$id;
        if ($n > 0) {
            $clean[$n] = $n;
        }
    }
    if ($clean === []) {
        return [];
    }
    $idList = implode(',', array_map('intval', array_values($clean)));
    $sql = 'SELECT * FROM customer_master WHERE id IN (' . $idList . ') AND ' . psCustomerCompanySql($conn);
    $res = mysqli_query($conn, $sql);
    $out = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

/**
 * Stronger national_id duplicate check (application + UNIQUE).
 */
function psCustomerNationalIdExists(mysqli $conn, string $nationalId, int $excludeId = 0): bool
{
    $nationalId = trim($nationalId);
    if ($nationalId === '' || !psEnsureCustomerMasterSchema($conn)) {
        return false;
    }
    if ($excludeId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM customer_master WHERE national_id = ? AND id <> ? AND ' . psCustomerCompanySql($conn) . ' LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $nationalId, $excludeId);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM customer_master WHERE national_id = ? AND ' . psCustomerCompanySql($conn) . ' LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $nationalId);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (bool)$exists;
}

/**
 * Detect obvious phone / email / business_name duplicates (no UNIQUE on those columns).
 *
 * @return array{ok:bool,error?:string}
 */
function psCustomerDuplicateControls(
    mysqli $conn,
    string $phone,
    string $email,
    ?string $businessName,
    string $customerType,
    int $excludeId = 0
): array {
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Customers could not be validated.'];
    }

    $phoneKey = psNormalizePhoneKey($phone);
    if ($phoneKey !== '') {
        $sql = 'SELECT id, phone, customer_code FROM customer_master WHERE ' . psCustomerCompanySql($conn);
        if ($excludeId > 0) {
            $sql .= ' AND id <> ' . (int)$excludeId;
        }
        $res = mysqli_query($conn, $sql);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            if (psNormalizePhoneKey((string)$row['phone']) === $phoneKey) {
                return [
                    'ok' => false,
                    'error' => 'A customer with this phone number already exists ('
                        . trim((string)$row['customer_code']) . ').',
                ];
            }
        }
    }

    $emailKey = psNormalizeEmailKey($email);
    if ($emailKey !== '') {
        $stmt = null;
        if ($excludeId > 0) {
            $stmt = mysqli_prepare(
                $conn,
                'SELECT id, customer_code FROM customer_master WHERE LOWER(TRIM(email)) = ? AND id <> ? AND ' . psCustomerCompanySql($conn) . ' LIMIT 1'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $emailKey, $excludeId);
            }
        } else {
            $stmt = mysqli_prepare(
                $conn,
                'SELECT id, customer_code FROM customer_master WHERE LOWER(TRIM(email)) = ? AND ' . psCustomerCompanySql($conn) . ' LIMIT 1'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $emailKey);
            }
        }
        if ($stmt) {
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $hit = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);
            if ($hit) {
                return [
                    'ok' => false,
                    'error' => 'A customer with this email already exists ('
                        . trim((string)$hit['customer_code']) . ').',
                ];
            }
        }
    }

    $type = strtoupper(trim($customerType));
    $bizKey = psNormalizeBusinessNameKey((string)($businessName ?? ''));
    if ($type === PS_CUSTOMER_TYPE_BUSINESS && $bizKey !== '') {
        $sql = 'SELECT id, business_name, customer_code, customer_type FROM customer_master WHERE ' . psCustomerCompanySql($conn);
        if ($excludeId > 0) {
            $sql .= ' AND id <> ' . (int)$excludeId;
        }
        $res = mysqli_query($conn, $sql);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $rowType = strtoupper(trim((string)($row['customer_type'] ?? '')));
            if ($rowType !== PS_CUSTOMER_TYPE_BUSINESS) {
                continue;
            }
            if (psNormalizeBusinessNameKey((string)($row['business_name'] ?? '')) === $bizKey) {
                return [
                    'ok' => false,
                    'error' => 'A business customer with this name already exists ('
                        . trim((string)$row['customer_code']) . ').',
                ];
            }
        }
    }

    return ['ok' => true];
}

/**
 * Validate and normalize create/update payload (excludes immutable code on update).
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,value?:array<string,mixed>}
 */
function psValidateCustomerPayload(array $data): array
{
    $typeCheck = psNormalizeCustomerType((string)($data['customer_type'] ?? PS_CUSTOMER_TYPE_INDIVIDUAL));
    if (!$typeCheck['ok']) {
        return ['ok' => false, 'error' => $typeCheck['error']];
    }
    $type = $typeCheck['value'];

    $firstCheck = psValidateCustomerOptionalText((string)($data['first_name'] ?? ''), 'First name', 250);
    $lastCheck = psValidateCustomerOptionalText((string)($data['last_name'] ?? ''), 'Last name', 250);
    $bizRaw = trim((string)($data['business_name'] ?? ''));
    $bizCheck = psValidateCustomerOptionalText($bizRaw, 'Business name', 250);
    $phoneCheck = psValidateCustomerOptionalText((string)($data['phone'] ?? ''), 'Phone', 64);
    $emailCheck = psValidateCustomerEmail((string)($data['email'] ?? ''));
    $addrCheck = psValidateCustomerOptionalText((string)($data['address'] ?? ''), 'Address', 500);
    $nidCheck = psValidateCustomerNationalId((string)($data['national_id'] ?? ''));
    $notesCheck = psValidateCustomerOptionalText((string)($data['notes'] ?? ''), 'Notes', 4000);

    foreach ([$firstCheck, $lastCheck, $bizCheck, $phoneCheck, $emailCheck, $addrCheck, $nidCheck, $notesCheck] as $check) {
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error']];
        }
    }

    $first = $firstCheck['value'];
    $last = $lastCheck['value'];
    $business = $bizCheck['value'] === '' ? null : $bizCheck['value'];

    if ($type === PS_CUSTOMER_TYPE_INDIVIDUAL) {
        if ($first === '' || $last === '') {
            return ['ok' => false, 'error' => 'First name and last name are required for individuals.'];
        }
    } else {
        if ($business === null || $business === '') {
            return ['ok' => false, 'error' => 'Business name is required for business customers.'];
        }
    }

    return [
        'ok' => true,
        'value' => [
            'first_name' => $first,
            'last_name' => $last,
            'business_name' => $business,
            'phone' => $phoneCheck['value'],
            'email' => $emailCheck['value'],
            'address' => $addrCheck['value'],
            'national_id' => $nidCheck['value'],
            'customer_type' => $type,
            'notes' => $notesCheck['value'] === '' ? null : $notesCheck['value'],
        ],
    ];
}

/**
 * Active customers only — for selection lists (global; no branch filter).
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psActiveCustomersForSelect(mysqli $conn): array
{
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Customers could not be loaded.', 'rows' => []];
    }
    $active = PS_CUSTOMER_STATUS_ACTIVE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, customer_code, first_name, last_name, business_name, phone, email,
                customer_type, status
         FROM customer_master
         WHERE status = ? AND ' . psCustomerCompanySql($conn) . '
         ORDER BY last_name, first_name, business_name, customer_code'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Customers could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 's', $active);
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
 * POS customer autocomplete — ACTIVE only; search code / first / last / business / phone.
 * Global (no branch). Does not search legacy `customers` table.
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psSearchActiveCustomersForPos(mysqli $conn, string $search, int $limit = 20): array
{
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Customers could not be loaded.', 'rows' => []];
    }
    $limit = max(1, min(50, $limit));
    $search = trim($search);
    $active = PS_CUSTOMER_STATUS_ACTIVE;
    $sql = 'SELECT id, customer_code, first_name, last_name, business_name, phone,
                   customer_type, status
            FROM customer_master
            WHERE status = ? AND ' . psCustomerCompanySql($conn);
    $types = 's';
    $params = [$active];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (
            customer_code LIKE ?
            OR first_name LIKE ?
            OR last_name LIKE ?
            OR business_name LIKE ?
            OR phone LIKE ?
            OR CONCAT(TRIM(first_name), \' \', TRIM(last_name)) LIKE ?
        )';
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY last_name, first_name, business_name, customer_code LIMIT ' . (int)$limit;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Customers could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
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
 * List/search customers (single query; no N+1).
 * Optional linked_sale_count via one aggregate LEFT JOIN on customer_master_id
 * (not name / legacy customer_id). National ID is not selected for list display.
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psCustomerList(mysqli $conn, string $search = '', string $statusFilter = 'all', string $typeFilter = 'all'): array
{
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Customers could not be loaded.', 'rows' => []];
    }
    $statusFilter = strtolower(trim($statusFilter));
    if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
        $statusFilter = 'all';
    }
    $typeFilter = strtolower(trim($typeFilter));
    if (!in_array($typeFilter, ['all', 'individual', 'business'], true)) {
        $typeFilter = 'all';
    }

    // Linked sale counts only via customer_master_id (Phase 5.2/5.3 identity).
    require_once __DIR__ . '/product_master.php'; // psCompanyBranchesInSql (2026-11-02)
    $sql = 'SELECT c.id, c.customer_code, c.first_name, c.last_name, c.business_name,
                   c.phone, c.email, c.address, c.customer_type, c.status, c.notes,
                   c.created_at, c.updated_at, c.created_by, c.updated_by,
                   COALESCE(s.linked_sale_count, 0) AS linked_sale_count
            FROM customer_master c
            LEFT JOIN (
                SELECT customer_master_id, COUNT(*) AS linked_sale_count
                FROM online_sales_ref
                WHERE customer_master_id IS NOT NULL AND ' . psCompanyBranchesInSql($conn, 'branch') . '
                GROUP BY customer_master_id
            ) s ON s.customer_master_id = c.id
            WHERE ' . psCustomerCompanySql($conn, 'c');
    $types = '';
    $params = [];
    if ($statusFilter === 'active') {
        $sql .= ' AND c.status = ?';
        $types .= 's';
        $params[] = PS_CUSTOMER_STATUS_ACTIVE;
    } elseif ($statusFilter === 'inactive') {
        $sql .= ' AND c.status = ?';
        $types .= 's';
        $params[] = PS_CUSTOMER_STATUS_INACTIVE;
    }
    if ($typeFilter === 'individual') {
        $sql .= ' AND c.customer_type = ?';
        $types .= 's';
        $params[] = PS_CUSTOMER_TYPE_INDIVIDUAL;
    } elseif ($typeFilter === 'business') {
        $sql .= ' AND c.customer_type = ?';
        $types .= 's';
        $params[] = PS_CUSTOMER_TYPE_BUSINESS;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        // Admin list search: code/name/phone/email (not national_id in list UI).
        $sql .= ' AND (
            c.customer_code LIKE ?
            OR c.first_name LIKE ?
            OR c.last_name LIKE ?
            OR c.business_name LIKE ?
            OR c.phone LIKE ?
            OR c.email LIKE ?
            OR CONCAT(TRIM(c.first_name), \' \', TRIM(c.last_name)) LIKE ?
        )';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY c.last_name, c.first_name, c.business_name, c.customer_code';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Customers could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
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
 * Create customer. Allocates CUS-* code. Status ACTIVE. Never writes stock / sales numbering.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,id?:int,customer_code?:string}
 */
function psCreateCustomer(mysqli $conn, array $data): array
{
    if (!psEnsureCustomerMasterSchema($conn) || !psEnsureCustomerAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    $changedBy = psSessionCustomerUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to create a customer.'];
    }
    $customerCompany = psCustomerCompany($conn);
    if ($customerCompany === '') {
        return ['ok' => false, 'error' => 'Select a company before adding customers.'];
    }

    $validated = psValidateCustomerPayload($data);
    if (!$validated['ok']) {
        return ['ok' => false, 'error' => $validated['error']];
    }
    /** @var array<string,mixed> $v */
    $v = $validated['value'];

    if ($v['national_id'] !== null && psCustomerNationalIdExists($conn, (string)$v['national_id'])) {
        return ['ok' => false, 'error' => 'A customer with this national ID already exists.'];
    }
    $dup = psCustomerDuplicateControls(
        $conn,
        (string)$v['phone'],
        (string)$v['email'],
        $v['business_name'] !== null ? (string)$v['business_name'] : null,
        (string)$v['customer_type'],
        0
    );
    if (!$dup['ok']) {
        return ['ok' => false, 'error' => $dup['error']];
    }

    $status = PS_CUSTOMER_STATUS_ACTIVE;
    $now = date('Y-m-d H:i:s');

    if (!psTxBegin($conn)) {
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }

    $alloc = psAllocateCustomerCode($conn);
    if (!$alloc['ok']) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => (string)($alloc['error'] ?? 'The customer could not be saved.')];
    }
    $code = (string)$alloc['code'];

    $first = (string)$v['first_name'];
    $last = (string)$v['last_name'];
    $business = $v['business_name'];
    $phone = (string)$v['phone'];
    $email = (string)$v['email'];
    $address = (string)$v['address'];
    $nationalId = $v['national_id'];
    $ctype = (string)$v['customer_type'];
    $notes = $v['notes'];

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_master
         (customer_code, first_name, last_name, business_name, phone, email, address,
          national_id, customer_type, status, notes, created_at, updated_at, created_by, updated_by, company_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        psTxRollback($conn);
        error_log('customer create prepare failed');
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssssssssss',
        $code,
        $first,
        $last,
        $business,
        $phone,
        $email,
        $address,
        $nationalId,
        $ctype,
        $status,
        $notes,
        $now,
        $now,
        $changedBy,
        $changedBy,
        $customerCompany
    );
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        psTxRollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'A customer with this code or national ID already exists.'];
        }
        error_log('customer create failed');
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $after = [
        'customer_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'business_name' => $business,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'national_id' => $nationalId,
        'customer_type' => $ctype,
        'status' => $status,
        'notes' => $notes,
    ];
    $changes = [];
    foreach ($after as $field => $value) {
        $changes[] = [
            'field_name' => $field,
            'old_value' => null,
            'new_value' => psCustomerAuditNormalizeValue($field, $value),
        ];
    }
    if (!psCustomerAuditRecordChanges($conn, [
        'customer_id' => $newId,
        'customer_code' => $code,
        'action' => PS_CUSTOMER_AUDIT_ACTION_CREATE,
        'changed_by' => $changedBy,
        'source' => PS_CUSTOMER_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }

    if (!psTxCommit($conn)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    return ['ok' => true, 'id' => $newId, 'customer_code' => $code];
}

/**
 * Update customer fields. Does not change id or customer_code. Status via psSetCustomerStatus.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psUpdateCustomer(mysqli $conn, int $id, array $data): array
{
    if (!psEnsureCustomerMasterSchema($conn) || !psEnsureCustomerAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    $changedBy = psSessionCustomerUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to edit a customer.'];
    }
    $existing = psCustomerById($conn, $id);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Customer was not found.'];
    }

    $validated = psValidateCustomerPayload($data);
    if (!$validated['ok']) {
        return ['ok' => false, 'error' => $validated['error']];
    }
    /** @var array<string,mixed> $v */
    $v = $validated['value'];

    if ($v['national_id'] !== null && psCustomerNationalIdExists($conn, (string)$v['national_id'], $id)) {
        return ['ok' => false, 'error' => 'A customer with this national ID already exists.'];
    }
    $dup = psCustomerDuplicateControls(
        $conn,
        (string)$v['phone'],
        (string)$v['email'],
        $v['business_name'] !== null ? (string)$v['business_name'] : null,
        (string)$v['customer_type'],
        $id
    );
    if (!$dup['ok']) {
        return ['ok' => false, 'error' => $dup['error']];
    }

    $code = trim((string)$existing['customer_code']);
    $before = psCustomerAuditSnapshot($existing);
    $after = [
        'customer_code' => $code,
        'first_name' => (string)$v['first_name'],
        'last_name' => (string)$v['last_name'],
        'business_name' => $v['business_name'],
        'phone' => (string)$v['phone'],
        'email' => (string)$v['email'],
        'address' => (string)$v['address'],
        'national_id' => $v['national_id'],
        'customer_type' => (string)$v['customer_type'],
        'status' => psCustomerAuditNormalizeValue('status', $existing['status'] ?? PS_CUSTOMER_STATUS_ACTIVE),
        'notes' => $v['notes'],
    ];
    $changes = psCustomerAuditDiff($before, $after);
    $changes = array_values(array_filter($changes, static function ($c) {
        $f = (string)($c['field_name'] ?? '');
        return $f !== 'status' && $f !== 'customer_code';
    }));
    if ($changes === []) {
        return ['ok' => true, 'changed' => false];
    }

    $now = date('Y-m-d H:i:s');
    $first = (string)$v['first_name'];
    $last = (string)$v['last_name'];
    $business = $v['business_name'];
    $phone = (string)$v['phone'];
    $email = (string)$v['email'];
    $address = (string)$v['address'];
    $nationalId = $v['national_id'];
    $ctype = (string)$v['customer_type'];
    $notes = $v['notes'];

    if (!psTxBegin($conn)) {
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE customer_master
         SET first_name = ?, last_name = ?, business_name = ?, phone = ?, email = ?, address = ?,
             national_id = ?, customer_type = ?, notes = ?, updated_at = ?, updated_by = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        psTxRollback($conn);
        error_log('customer update prepare failed');
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssssi',
        $first,
        $last,
        $business,
        $phone,
        $email,
        $address,
        $nationalId,
        $ctype,
        $notes,
        $now,
        $changedBy,
        $id
    );
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        psTxRollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'A customer with this national ID already exists.'];
        }
        error_log('customer update failed');
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psCustomerAuditRecordChanges($conn, [
        'customer_id' => $id,
        'customer_code' => $code,
        'action' => PS_CUSTOMER_AUDIT_ACTION_UPDATE,
        'changed_by' => $changedBy,
        'source' => PS_CUSTOMER_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }

    if (!psTxCommit($conn)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The customer could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Activate / deactivate. Never deletes. No stock / sales writes.
 *
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psSetCustomerStatus(mysqli $conn, int $id, string $newStatus): array
{
    if (!psEnsureCustomerMasterSchema($conn) || !psEnsureCustomerAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Customer status could not be saved.'];
    }
    $changedBy = psSessionCustomerUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to change customer status.'];
    }
    $normalized = psNormalizeCustomerStatus($newStatus);
    if (!$normalized['ok']) {
        return ['ok' => false, 'error' => $normalized['error']];
    }
    $existing = psCustomerById($conn, $id);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Customer was not found.'];
    }
    $status = $normalized['value'];
    $oldStatus = psCustomerAuditNormalizeValue('status', $existing['status'] ?? null);
    if ($oldStatus === null || $oldStatus === '') {
        $oldStatus = PS_CUSTOMER_STATUS_ACTIVE;
    }
    if ($oldStatus === $status) {
        return ['ok' => true, 'changed' => false];
    }

    $now = date('Y-m-d H:i:s');
    $action = $status === PS_CUSTOMER_STATUS_ACTIVE
        ? PS_CUSTOMER_AUDIT_ACTION_ACTIVATE
        : PS_CUSTOMER_AUDIT_ACTION_DEACTIVATE;
    $code = trim((string)$existing['customer_code']);

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Customer status could not be saved.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE customer_master SET status = ?, updated_at = ?, updated_by = ? WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Customer status could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssi', $status, $now, $changedBy, $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        error_log('customer status update failed');
        return ['ok' => false, 'error' => 'Customer status could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psCustomerAuditRecordChanges($conn, [
        'customer_id' => $id,
        'customer_code' => $code,
        'action' => $action,
        'changed_by' => $changedBy,
        'source' => PS_CUSTOMER_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $oldStatus,
        'new_value' => $status,
    ]])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Customer status could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Customer status could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Hard delete is not allowed from normal UI.
 *
 * @return array{ok:bool,error?:string}
 */
function psDeleteCustomerBlocked(mysqli $conn, int $id): array
{
    unset($conn, $id);
    return ['ok' => false, 'error' => 'Customers cannot be deleted. Deactivate instead.'];
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psCustomerAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCustomerAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Customer audit history could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM customer_audit WHERE customer_id IN (SELECT id FROM customer_master WHERE ' . psCustomerCompanySql($conn) . ')';
    $types = '';
    $params = [];
    $customerId = (int)($filters['customer_id'] ?? 0);
    $code = trim((string)($filters['customer_code'] ?? ''));
    $limit = (int)($filters['limit'] ?? 300);
    if ($limit < 1 || $limit > 1000) {
        $limit = 300;
    }
    if ($customerId > 0) {
        $sql .= ' AND customer_id = ?';
        $types .= 'i';
        $params[] = $customerId;
    }
    if ($code !== '') {
        $sql .= ' AND customer_code = ?';
        $types .= 's';
        $params[] = $code;
    }
    $sql .= ' ORDER BY changed_at DESC, id DESC LIMIT ' . $limit;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Customer audit history could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
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
 * Whitelist date preset for customer sales history.
 * Presets: all | today | month | custom. Default: month (recent calendar month).
 *
 * @return array{ok:bool,preset?:string,from_date?:?string,to_date?:?string,error?:string}
 */
function psCustomerSalesHistoryResolveDates(string $preset, string $fromDate = '', string $toDate = ''): array
{
    $preset = strtolower(trim($preset));
    if ($preset === '') {
        $preset = 'month';
    }
    if (!in_array($preset, ['all', 'today', 'month', 'custom'], true)) {
        return ['ok' => false, 'error' => 'Date filter is not valid.'];
    }

    $today = date('Y-m-d');
    if ($preset === 'all') {
        return ['ok' => true, 'preset' => 'all', 'from_date' => null, 'to_date' => null];
    }
    if ($preset === 'today') {
        return ['ok' => true, 'preset' => 'today', 'from_date' => $today, 'to_date' => $today];
    }
    if ($preset === 'month') {
        $from = date('Y-m-01');
        return ['ok' => true, 'preset' => 'month', 'from_date' => $from, 'to_date' => $today];
    }

    // custom
    $from = trim($fromDate);
    $to = trim($toDate);
    if ($from === '' && $to === '') {
        return ['ok' => false, 'error' => 'Enter a from and/or to date for a custom range.'];
    }
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return ['ok' => false, 'error' => 'From date is not valid.'];
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return ['ok' => false, 'error' => 'To date is not valid.'];
    }
    if ($from !== '' && $to !== '' && strcmp($from, $to) > 0) {
        return ['ok' => false, 'error' => 'From date cannot be after to date.'];
    }
    return [
        'ok' => true,
        'preset' => 'custom',
        'from_date' => $from !== '' ? $from : null,
        'to_date' => $to !== '' ? $to : null,
    ];
}

/**
 * Confirm idx_online_sales_ref_customer_master_id exists (created in Phase 5.2).
 */
function psCustomerSalesHistoryIndexPresent(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = mysqli_query(
        $conn,
        "SHOW INDEX FROM online_sales_ref WHERE Key_name = 'idx_online_sales_ref_customer_master_id'"
    );
    return $res && mysqli_num_rows($res) > 0;
}

/**
 * Read-only sales history for a customer_master row.
 * Source ONLY: online_sales_ref.customer_master_id = customer.id.
 * Does not use free-text customer, legacy customer_id, name, phone, or email.
 * Summaries are sales history count/value — NOT balance owed / receivable / AR.
 * History may span branches (customer is global).
 *
 * @param array<string,mixed> $filters preset, from_date, to_date, page, page_size, sort
 * @return array{
 *   ok:bool,
 *   customer?:array<string,mixed>,
 *   rows?:array<int,array<string,mixed>>,
 *   summary?:array{sale_count:int,sale_total:string,note:string},
 *   pagination?:array{page:int,page_size:int,total_rows:int,total_pages:int},
 *   filters?:array{preset:string,from_date:?string,to_date:?string},
 *   branch_note?:string,
 *   error?:string
 * }
 */
function psCustomerSalesHistory(mysqli $conn, int $customerId, array $filters = []): array
{
    if ($customerId < 1) {
        return ['ok' => false, 'error' => 'Customer was not found.'];
    }
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Sales history could not be loaded.'];
    }
    $customer = psCustomerById($conn, $customerId);
    if ($customer === null) {
        return ['ok' => false, 'error' => 'Customer was not found.'];
    }

    $presetRaw = (string)($filters['preset'] ?? 'month');
    $dates = psCustomerSalesHistoryResolveDates(
        $presetRaw,
        (string)($filters['from_date'] ?? ''),
        (string)($filters['to_date'] ?? '')
    );
    if (!$dates['ok']) {
        return ['ok' => false, 'error' => (string)($dates['error'] ?? 'Date filter is not valid.')];
    }
    $from = $dates['from_date'];
    $to = $dates['to_date'];
    $preset = (string)$dates['preset'];

    $pageSize = (int)($filters['page_size'] ?? 25);
    if ($pageSize < 1 || $pageSize > 100) {
        $pageSize = 25;
    }
    $page = (int)($filters['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }

    $sort = strtolower(trim((string)($filters['sort'] ?? 'date_desc')));
    if (!in_array($sort, ['date_desc', 'date_asc', 'invoice_desc', 'invoice_asc'], true)) {
        $sort = 'date_desc';
    }
    $orderSql = 'ORDER BY COALESCE(r.sold_at, STR_TO_DATE(r.date_, \'%Y-%m-%d\')) DESC, r.id DESC';
    if ($sort === 'date_asc') {
        $orderSql = 'ORDER BY COALESCE(r.sold_at, STR_TO_DATE(r.date_, \'%Y-%m-%d\')) ASC, r.id ASC';
    } elseif ($sort === 'invoice_desc') {
        $orderSql = 'ORDER BY CAST(r.invoice_number AS UNSIGNED) DESC, r.id DESC';
    } elseif ($sort === 'invoice_asc') {
        $orderSql = 'ORDER BY CAST(r.invoice_number AS UNSIGNED) ASC, r.id ASC';
    }

    $where = 'WHERE r.customer_master_id = ?';
    $types = 'i';
    $params = [$customerId];
    // Prefer sold_at date when present; else date_ (Y-m-d varchar used by production POS).
    $saleDateExpr = 'COALESCE(DATE(r.sold_at), STR_TO_DATE(r.date_, \'%Y-%m-%d\'))';
    if ($from !== null) {
        $where .= ' AND ' . $saleDateExpr . ' >= ?';
        $types .= 's';
        $params[] = $from;
    }
    if ($to !== null) {
        $where .= ' AND ' . $saleDateExpr . ' <= ?';
        $types .= 's';
        $params[] = $to;
    }

    // Summary over full filtered set (not page slice) — sales history only, not AR.
    $sumSql = 'SELECT COUNT(*) AS sale_count,
                      IFNULL(SUM(CAST(r.totalz AS DECIMAL(14,2))), 0) AS sale_total
               FROM online_sales_ref r
               ' . $where;
    $saleCount = 0;
    $saleTotal = '0.00';
    $stmt = mysqli_prepare($conn, $sumSql);
    if (!$stmt) {
        error_log('customer sales history summary prepare failed');
        return ['ok' => false, 'error' => 'Sales history could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('customer sales history summary execute failed');
        return ['ok' => false, 'error' => 'Sales history could not be loaded.'];
    }
    $sumRes = mysqli_stmt_get_result($stmt);
    $sumRow = $sumRes ? mysqli_fetch_assoc($sumRes) : null;
    mysqli_stmt_close($stmt);
    if ($sumRow) {
        $saleCount = (int)$sumRow['sale_count'];
        $saleTotal = number_format((float)$sumRow['sale_total'], 2, '.', '');
    }

    $totalPages = $saleCount > 0 ? (int)ceil($saleCount / $pageSize) : 1;
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    // Payment method from sale_payments when present (header method is currency on production path).
    // Aggregate join avoids duplicate history rows if multiple payment lines share an invoice.
    $listSql = 'SELECT r.id, r.invoice_number, r.totalz, r.status, r.date_, r.sold_at, r.branch,
                       r.order_number, r.till_session_id, r.cashier,
                       sp.payment_method, sp.payment_currency
                FROM online_sales_ref r
                LEFT JOIN (
                    SELECT invoice_number,
                           CASE WHEN COUNT(DISTINCT CASE WHEN is_change = 0 THEN UPPER(TRIM(method)) END) > 1 THEN \'SPLIT\'
                                ELSE MIN(CASE WHEN is_change = 0 THEN method END) END AS payment_method,
                           CASE WHEN COUNT(DISTINCT currency) > 1 THEN \'MIXED\' ELSE MIN(currency) END AS payment_currency
                    FROM sale_payments
                    GROUP BY invoice_number
                ) sp ON sp.invoice_number = r.invoice_number
                ' . $where . '
                ' . $orderSql . '
                LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
    $stmt = mysqli_prepare($conn, $listSql);
    if (!$stmt) {
        error_log('customer sales history list prepare failed');
        return ['ok' => false, 'error' => 'Sales history could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('customer sales history list execute failed');
        return ['ok' => false, 'error' => 'Sales history could not be loaded.'];
    }
    $listRes = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($listRes && ($row = mysqli_fetch_assoc($listRes))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'customer' => $customer,
        'rows' => $rows,
        'summary' => [
            'sale_count' => $saleCount,
            'sale_total' => $saleTotal,
            'note' => 'Sales history summaries (linked sales count and total value). Not a balance owed, receivable, or account statement.',
        ],
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $saleCount,
            'total_pages' => $totalPages,
        ],
        'filters' => [
            'preset' => $preset,
            'from_date' => $from,
            'to_date' => $to,
            'sort' => $sort,
        ],
        'branch_note' => 'Customers are global. Linked sales may span branches; this view does not invent a role-based branch filter.',
    ];
}
