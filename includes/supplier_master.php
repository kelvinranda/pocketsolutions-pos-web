<?php
/**
 * Phase 4A — Supplier master helpers.
 * Supplier identity is supplier_code within the signed-in company (same pattern as customers).
 * Branch belongs on purchasing/GRV txns. Does not write stock, goods.quantity, or sale_invoice_seq.
 */

const PS_SUPPLIER_STATUS_ACTIVE = 'ACTIVE';
const PS_SUPPLIER_STATUS_INACTIVE = 'INACTIVE';

const PS_SUPPLIER_AUDIT_SOURCE_UI = 'UI';
const PS_SUPPLIER_AUDIT_ACTION_CREATE = 'CREATE';
const PS_SUPPLIER_AUDIT_ACTION_UPDATE = 'UPDATE';
const PS_SUPPLIER_AUDIT_ACTION_ACTIVATE = 'ACTIVATE';
const PS_SUPPLIER_AUDIT_ACTION_DEACTIVATE = 'DEACTIVATE';

/**
 * Ensure supplier_master exists. Safe to call repeatedly.
 */
function psEnsureSupplierMasterSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM supplier_master LIMIT 0');
    if ($check) {
        return $ready = psSupplierEnsureExtraColumns($conn) && psSupplierCompanyMigrate($conn);
    }
    $sql = "CREATE TABLE IF NOT EXISTS supplier_master (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        supplier_code VARCHAR(64) NOT NULL,
        supplier_name VARCHAR(250) NOT NULL,
        contact_person VARCHAR(250) NOT NULL DEFAULT '',
        phone VARCHAR(64) NOT NULL DEFAULT '',
        email VARCHAR(250) NOT NULL DEFAULT '',
        address VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        company_name VARCHAR(250) NULL DEFAULT NULL,
        UNIQUE KEY uniq_supplier_master_company_code (company_name, supplier_code),
        KEY idx_supplier_master_name (supplier_name),
        KEY idx_supplier_master_status (status),
        KEY idx_supplier_master_company (company_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('supplier_master schema was not created');
        return false;
    }
    return $ready = psSupplierEnsureExtraColumns($conn) && psSupplierCompanyMigrate($conn);
}

/** 2026-10-24: tax / registration numbers, payment terms and notes (all optional). */
function psSupplierExtraColumns(): array
{
    return [
        'tax_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'registration_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'payment_terms' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'notes' => 'TEXT NULL',
    ];
}

function psSupplierEnsureExtraColumns(mysqli $conn): bool
{
    $have = [];
    $res = mysqli_query($conn, 'SHOW COLUMNS FROM supplier_master');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $have[$r['Field']] = true;
    }
    foreach (psSupplierExtraColumns() as $col => $def) {
        if (!isset($have[$col]) && !mysqli_query($conn, "ALTER TABLE supplier_master ADD COLUMN $col $def")
            && mysqli_errno($conn) !== 1060) {
            error_log('supplier_master column was not added');
            return false;
        }
    }
    return true;
}

/**
 * 2026-10-24 — suppliers belong to one company, same as customers.
 * Adds company_name once, fills older rows from the creator's user company, then from PO/GRV branch.
 * Supplier code is unique within a company. Blank-company rows stay hidden from company users.
 * Historical PO/GRV supplier_id values are not rewritten.
 */
function psSupplierCompanyMigrate(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $done = true;
    $has = mysqli_query($conn, "SHOW COLUMNS FROM supplier_master LIKE 'company_name'");
    if (!$has || mysqli_num_rows($has) === 0) {
        if (!mysqli_query($conn, 'ALTER TABLE supplier_master ADD COLUMN company_name VARCHAR(250) NULL DEFAULT NULL, ADD KEY idx_supplier_master_company (company_name)')
            && mysqli_errno($conn) !== 1060) {
            error_log('supplier_master: company column was not added: #' . mysqli_errno($conn));
            $done = false;
            return false;
        }
    }
    psSupplierCompanyBackfill($conn);
    $idx = mysqli_query($conn, "SHOW INDEX FROM supplier_master WHERE Key_name = 'uniq_supplier_master_company_code'");
    if (!$idx || mysqli_num_rows($idx) === 0) {
        if (mysqli_query($conn, 'ALTER TABLE supplier_master ADD UNIQUE KEY uniq_supplier_master_company_code (company_name, supplier_code)')
            || mysqli_errno($conn) === 1061) {
            mysqli_query($conn, 'ALTER TABLE supplier_master DROP INDEX uniq_supplier_master_code');
        }
    } else {
        mysqli_query($conn, 'ALTER TABLE supplier_master DROP INDEX uniq_supplier_master_code');
    }
    return true;
}

/**
 * Fill company_name for suppliers that have none.
 * Creator first (supplier_audit CREATE → users), then majority company from PO/GRV branch.
 */
function psSupplierCompanyBackfill(mysqli $conn): void
{
    $blank = mysqli_query($conn, "SELECT id FROM supplier_master WHERE company_name IS NULL OR company_name = '' LIMIT 1");
    if (!$blank || mysqli_num_rows($blank) === 0) {
        return;
    }

    $users = [];
    $u = mysqli_query($conn, "SELECT email, company_name FROM users WHERE company_name IS NOT NULL AND company_name <> ''");
    while ($u && ($row = mysqli_fetch_assoc($u))) {
        $users[strtolower(trim((string)$row['email']))] = trim((string)$row['company_name']);
    }

    $upd = mysqli_prepare(
        $conn,
        "UPDATE supplier_master SET company_name = ? WHERE id = ? AND (company_name IS NULL OR company_name = '')"
    );
    if (!$upd) {
        error_log('supplier_master: company backfill prepare failed: #' . mysqli_errno($conn));
        return;
    }

    $auditOk = mysqli_query($conn, 'SELECT 1 FROM supplier_audit LIMIT 0');
    if ($auditOk) {
        $res = mysqli_query($conn, "SELECT supplier_id, changed_by FROM supplier_audit WHERE action = 'CREATE'");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $co = $users[strtolower(trim((string)$row['changed_by']))] ?? '';
            $sid = (int)$row['supplier_id'];
            if ($co === '' || $sid < 1) {
                continue;
            }
            mysqli_stmt_bind_param($upd, 'si', $co, $sid);
            if (!mysqli_stmt_execute($upd)) {
                error_log('supplier_master: company backfill audit write failed: #' . mysqli_errno($conn));
            }
        }
    }

    $branchCo = [];
    $hasBranchCo = mysqli_query($conn, "SHOW COLUMNS FROM branchies LIKE 'company_name'");
    if ($hasBranchCo && mysqli_num_rows($hasBranchCo) > 0) {
        $b = mysqli_query($conn, "SELECT branch_name, company_name FROM branchies WHERE company_name IS NOT NULL AND company_name <> ''");
        while ($b && ($row = mysqli_fetch_assoc($b))) {
            $branchCo[strtolower(trim((string)$row['branch_name']))] = trim((string)$row['company_name']);
        }
    }

    $votes = [];
    foreach (['purchase_orders' => 'SELECT supplier_id, branch FROM purchase_orders WHERE supplier_id > 0',
        'goods_receipts' => 'SELECT supplier_id, branch FROM goods_receipts WHERE supplier_id IS NOT NULL AND supplier_id > 0'] as $table => $sql) {
        $exists = mysqli_query($conn, 'SELECT 1 FROM ' . $table . ' LIMIT 0');
        if (!$exists) {
            continue;
        }
        $res = mysqli_query($conn, $sql);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $sid = (int)$row['supplier_id'];
            $co = $branchCo[strtolower(trim((string)$row['branch']))] ?? '';
            if ($sid < 1 || $co === '') {
                continue;
            }
            if (!isset($votes[$sid])) {
                $votes[$sid] = [];
            }
            $votes[$sid][$co] = ($votes[$sid][$co] ?? 0) + 1;
        }
    }
    foreach ($votes as $sid => $byCo) {
        arsort($byCo);
        $co = (string)array_key_first($byCo);
        if ($co === '') {
            continue;
        }
        mysqli_stmt_bind_param($upd, 'si', $co, $sid);
        if (!mysqli_stmt_execute($upd)) {
            error_log('supplier_master: company backfill document write failed: #' . mysqli_errno($conn));
        }
    }
    mysqli_stmt_close($upd);
}

/**
 * The company whose suppliers the caller may see: the stand-in user's company during desktop sync,
 * otherwise the signed-in company. '' only outside a company (platform console, CLI).
 */
function psSupplierCompany(mysqli $conn): string
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

/** SQL condition limiting supplier_master rows (optionally aliased) to the caller's company. */
function psSupplierCompanySql(mysqli $conn, string $alias = ''): string
{
    $co = psSupplierCompany($conn);
    if ($co === '') {
        $signedIn = session_status() === PHP_SESSION_ACTIVE && trim((string)($_SESSION['logger'] ?? '')) !== '';
        $actor = function_exists('psActorOverride') && psActorOverride() !== null;
        return ($signedIn || $actor) ? '0' : '1';
    }
    $col = ($alias !== '' ? $alias . '.' : '') . 'company_name';
    return $col . " = '" . mysqli_real_escape_string($conn, $co) . "'";
}

/**
 * Validate the optional extra fields.
 *
 * @return array{ok:bool,error?:string,values?:array<string,string>}
 */
function psSupplierValidateExtra(array $data): array
{
    $limits = ['tax_number' => ['Tax number', 64], 'registration_number' => ['Registration number', 64],
        'payment_terms' => ['Payment terms', 120], 'notes' => ['Notes', 2000]];
    $out = [];
    foreach ($limits as $field => [$label, $max]) {
        $check = psValidateSupplierOptionalText((string)($data[$field] ?? ''), $label, $max);
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error']];
        }
        $out[$field] = $check['value'];
    }
    foreach (['tax_number', 'registration_number'] as $field) {
        if ($out[$field] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/\-]*$/', $out[$field])) {
            return ['ok' => false, 'error' => $limits[$field][0] . ' may use letters, numbers, spaces, dot, slash or hyphen.'];
        }
    }
    return ['ok' => true, 'values' => $out];
}

/**
 * Ensure supplier_audit exists. Append-only in application code.
 */
function psEnsureSupplierAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM supplier_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS supplier_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        supplier_code VARCHAR(64) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_supplier_audit_supplier_id (supplier_id),
        KEY idx_supplier_audit_code (supplier_code),
        KEY idx_supplier_audit_changed_at (changed_at),
        KEY idx_supplier_audit_changed_by (changed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('supplier_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psSessionSupplierUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psNormalizeSupplierStatus($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if ($value === PS_SUPPLIER_STATUS_ACTIVE || $value === PS_SUPPLIER_STATUS_INACTIVE) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Status must be Active or Inactive.'];
}

function psSupplierStatusIsActive($status): bool
{
    $check = psNormalizeSupplierStatus($status);
    return $check['ok'] && $check['value'] === PS_SUPPLIER_STATUS_ACTIVE;
}

function psSupplierStatusLabel(string $status): string
{
    $check = psNormalizeSupplierStatus($status);
    if (!$check['ok']) {
        return 'Unknown';
    }
    return $check['value'] === PS_SUPPLIER_STATUS_ACTIVE ? 'Active' : 'Inactive';
}

/**
 * Validate supplier code: required, trimmed, unique within the signed-in company, safe characters.
 */
function psValidateSupplierCode(string $raw): array
{
    $code = trim($raw);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Supplier code is required.'];
    }
    if (strlen($code) > 64) {
        return ['ok' => false, 'error' => 'Supplier code is too long.'];
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', $code)) {
        return ['ok' => false, 'error' => 'Supplier code may use letters, numbers, dot, hyphen, or underscore.'];
    }
    return ['ok' => true, 'value' => $code];
}

function psValidateSupplierName(string $raw): array
{
    $name = trim($raw);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Supplier name is required.'];
    }
    if (strlen($name) > 250) {
        return ['ok' => false, 'error' => 'Supplier name is too long.'];
    }
    return ['ok' => true, 'value' => $name];
}

function psValidateSupplierOptionalText(string $raw, string $label, int $maxLen): array
{
    $value = trim($raw);
    if (strlen($value) > $maxLen) {
        return ['ok' => false, 'error' => $label . ' is too long.'];
    }
    return ['ok' => true, 'value' => $value];
}

/**
 * Email optional; light format check only when non-empty.
 */
function psValidateSupplierEmail(string $raw): array
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
 * @return array<int,string>
 */
function psSupplierAuditFieldNames(): array
{
    return [
        'supplier_code',
        'supplier_name',
        'contact_person',
        'phone',
        'email',
        'address',
        'tax_number',
        'registration_number',
        'payment_terms',
        'notes',
        'status',
    ];
}

function psSupplierAuditNormalizeValue(string $field, $raw): ?string
{
    if ($field === 'status') {
        if ($raw === null) {
            return null;
        }
        $value = strtoupper(trim((string)$raw));
        return $value === '' ? null : $value;
    }
    if ($raw === null) {
        return array_key_exists($field, psSupplierExtraColumns()) ? '' : null;
    }
    return trim((string)$raw);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,?string>
 */
function psSupplierAuditSnapshot(array $row): array
{
    $snap = [];
    foreach (psSupplierAuditFieldNames() as $field) {
        $snap[$field] = psSupplierAuditNormalizeValue(
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
function psSupplierAuditDiff(array $before, array $after): array
{
    $changes = [];
    foreach (psSupplierAuditFieldNames() as $field) {
        $oldN = psSupplierAuditNormalizeValue($field, $before[$field] ?? null);
        $newN = psSupplierAuditNormalizeValue($field, $after[$field] ?? null);
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

function psSupplierAuditInsertRow(
    mysqli $conn,
    int $supplierId,
    string $supplierCode,
    string $action,
    string $fieldName,
    $oldValue,
    $newValue,
    string $changedBy,
    string $source = PS_SUPPLIER_AUDIT_SOURCE_UI,
    string $changedAt = ''
): bool {
    if (!psEnsureSupplierAuditSchema($conn)) {
        return false;
    }
    $supplierCode = trim($supplierCode);
    $action = strtoupper(trim($action));
    $fieldName = trim($fieldName);
    $changedBy = trim($changedBy);
    $source = trim($source);
    if ($supplierId < 1 || $supplierCode === '' || $action === '' || $fieldName === '') {
        return false;
    }
    if ($changedBy === '') {
        $changedBy = psSessionSupplierUser();
    }
    if ($changedBy === '') {
        return false;
    }
    if ($source === '') {
        $source = PS_SUPPLIER_AUDIT_SOURCE_UI;
    }
    if ($changedAt === '') {
        $changedAt = date('Y-m-d H:i:s');
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO supplier_audit
         (supplier_id, supplier_code, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('supplier audit insert prepare failed');
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'issssssss',
        $supplierId,
        $supplierCode,
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
        error_log('supplier audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * @param array<string,mixed> $meta
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psSupplierAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    $supplierId = (int)($meta['supplier_id'] ?? 0);
    $supplierCode = trim((string)($meta['supplier_code'] ?? ''));
    $action = strtoupper(trim((string)($meta['action'] ?? '')));
    $source = trim((string)($meta['source'] ?? PS_SUPPLIER_AUDIT_SOURCE_UI));
    $changedBy = trim((string)($meta['changed_by'] ?? ''));
    $changedAt = trim((string)($meta['changed_at'] ?? ''));
    if ($changedBy === '') {
        $changedBy = psSessionSupplierUser();
    }
    foreach ($changes as $change) {
        $field = (string)($change['field_name'] ?? '');
        if ($field === '') {
            continue;
        }
        if (!psSupplierAuditInsertRow(
            $conn,
            $supplierId,
            $supplierCode,
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

function psSupplierById(mysqli $conn, int $id, bool $requireCompany = true): ?array
{
    if ($id < 1 || !psEnsureSupplierMasterSchema($conn)) {
        return null;
    }
    $sql = 'SELECT * FROM supplier_master WHERE id = ?';
    if ($requireCompany) {
        $sql .= ' AND ' . psSupplierCompanySql($conn);
    }
    $sql .= ' LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
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

/** Load by id for an already-authorised PO/GRV. Does not hide another company's historical supplier card. */
function psSupplierByIdForDocument(mysqli $conn, int $id): ?array
{
    return psSupplierById($conn, $id, false);
}

function psSupplierByCode(mysqli $conn, string $code): ?array
{
    $code = trim($code);
    if ($code === '' || !psEnsureSupplierMasterSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM supplier_master WHERE supplier_code = ? AND ' . psSupplierCompanySql($conn) . ' LIMIT 1'
    );
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

function psSupplierCodeExists(mysqli $conn, string $code, int $excludeId = 0): bool
{
    $code = trim($code);
    if ($code === '' || !psEnsureSupplierMasterSchema($conn)) {
        return false;
    }
    $scope = psSupplierCompanySql($conn);
    if ($excludeId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM supplier_master WHERE supplier_code = ? AND id <> ? AND ' . $scope . ' LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $code, $excludeId);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM supplier_master WHERE supplier_code = ? AND ' . $scope . ' LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $code);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (bool)$exists;
}

/**
 * Active suppliers only — for future purchasing / GRV selection.
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psActiveSuppliersForSelect(mysqli $conn): array
{
    if (!psEnsureSupplierMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Suppliers could not be loaded.', 'rows' => []];
    }
    $active = PS_SUPPLIER_STATUS_ACTIVE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, supplier_code, supplier_name, contact_person, phone, email, address, status
         FROM supplier_master
         WHERE status = ? AND ' . psSupplierCompanySql($conn) . '
         ORDER BY supplier_name, supplier_code'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Suppliers could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 's', $active);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * List/search suppliers (all statuses unless filtered).
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psSupplierList(mysqli $conn, string $search = '', string $statusFilter = 'all'): array
{
    if (!psEnsureSupplierMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Suppliers could not be loaded.', 'rows' => []];
    }
    $statusFilter = strtolower(trim($statusFilter));
    if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
        $statusFilter = 'all';
    }
    $sql = 'SELECT * FROM supplier_master WHERE ' . psSupplierCompanySql($conn);
    $types = '';
    $params = [];
    if ($statusFilter === 'active') {
        $sql .= ' AND status = ?';
        $types .= 's';
        $params[] = PS_SUPPLIER_STATUS_ACTIVE;
    } elseif ($statusFilter === 'inactive') {
        $sql .= ' AND status = ?';
        $types .= 's';
        $params[] = PS_SUPPLIER_STATUS_INACTIVE;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (supplier_code LIKE ? OR supplier_name LIKE ? OR phone LIKE ? OR email LIKE ? OR contact_person LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY supplier_name, supplier_code';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Suppliers could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * 2026-10-24 — One page of suppliers, filtered / sorted / counted in SQL.
 *
 * @return array{ok:bool,rows:array,total:int,error?:string}
 */
function psSupplierListPage(mysqli $conn, string $search, string $statusFilter, string $sort, string $dir, int $page, int $perPage): array
{
    if (!psEnsureSupplierMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Suppliers could not be loaded.', 'rows' => [], 'total' => 0];
    }
    $sorts = ['name' => 'supplier_name', 'code' => 'supplier_code', 'status' => 'status', 'created' => 'created_at', 'terms' => 'payment_terms'];
    $orderCol = $sorts[$sort] ?? 'supplier_name';
    $dirSql = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
    $where = ' WHERE ' . psSupplierCompanySql($conn);
    $types = '';
    $params = [];
    $statusFilter = strtolower(trim($statusFilter));
    if ($statusFilter === 'active' || $statusFilter === 'inactive') {
        $where .= ' AND status = ?';
        $types .= 's';
        $params[] = $statusFilter === 'active' ? PS_SUPPLIER_STATUS_ACTIVE : PS_SUPPLIER_STATUS_INACTIVE;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where .= ' AND (supplier_code LIKE ? OR supplier_name LIKE ? OR phone LIKE ? OR email LIKE ? OR contact_person LIKE ? OR tax_number LIKE ? OR registration_number LIKE ?)';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $run = static function (string $sql) use ($conn, $types, $params) {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        mysqli_stmt_close($stmt);
        return $rows;
    };
    $count = $run('SELECT COUNT(*) AS n FROM supplier_master' . $where);
    if ($count === null) {
        return ['ok' => false, 'error' => 'Suppliers could not be loaded.', 'rows' => [], 'total' => 0];
    }
    $perPage = max(1, min(200, $perPage));
    $offset = max(0, ($page - 1) * $perPage);
    $rows = $run('SELECT * FROM supplier_master' . $where . " ORDER BY $orderCol $dirSql, id $dirSql LIMIT $perPage OFFSET $offset");
    return ['ok' => $rows !== null, 'rows' => $rows ?? [], 'total' => (int)($count[0]['n'] ?? 0)];
}

/**
 * Create supplier. Never writes stock.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,id?:int}
 */
function psCreateSupplier(mysqli $conn, array $data): array
{
    if (!psEnsureSupplierMasterSchema($conn) || !psEnsureSupplierAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    $changedBy = psSessionSupplierUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to create a supplier.'];
    }
    $supplierCompany = psSupplierCompany($conn);
    if ($supplierCompany === '') {
        return ['ok' => false, 'error' => 'Select a company before adding suppliers.'];
    }

    $codeCheck = psValidateSupplierCode((string)($data['supplier_code'] ?? ''));
    $nameCheck = psValidateSupplierName((string)($data['supplier_name'] ?? ''));
    $personCheck = psValidateSupplierOptionalText((string)($data['contact_person'] ?? ''), 'Contact person', 250);
    $phoneCheck = psValidateSupplierOptionalText((string)($data['phone'] ?? ''), 'Phone', 64);
    $emailCheck = psValidateSupplierEmail((string)($data['email'] ?? ''));
    $addrCheck = psValidateSupplierOptionalText((string)($data['address'] ?? ''), 'Address', 500);

    if (!$codeCheck['ok']) {
        return ['ok' => false, 'error' => $codeCheck['error']];
    }
    if (!$nameCheck['ok']) {
        return ['ok' => false, 'error' => $nameCheck['error']];
    }
    if (!$personCheck['ok']) {
        return ['ok' => false, 'error' => $personCheck['error']];
    }
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'error' => $phoneCheck['error']];
    }
    if (!$emailCheck['ok']) {
        return ['ok' => false, 'error' => $emailCheck['error']];
    }
    if (!$addrCheck['ok']) {
        return ['ok' => false, 'error' => $addrCheck['error']];
    }
    $extra = psSupplierValidateExtra($data);
    if (!$extra['ok']) {
        return ['ok' => false, 'error' => $extra['error']];
    }
    if (psSupplierCodeExists($conn, $codeCheck['value'])) {
        return ['ok' => false, 'error' => 'That supplier code is already in use.'];
    }
    $x = $extra['values'];

    $code = $codeCheck['value'];
    $name = $nameCheck['value'];
    $person = $personCheck['value'];
    $phone = $phoneCheck['value'];
    $email = $emailCheck['value'];
    $address = $addrCheck['value'];
    $status = PS_SUPPLIER_STATUS_ACTIVE;
    $now = date('Y-m-d H:i:s');

    $owns = false;
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    $owns = true;

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO supplier_master
         (supplier_code, supplier_name, contact_person, phone, email, address, tax_number, registration_number, payment_terms, notes, status, created_at, updated_at, company_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('supplier create prepare failed');
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssssssss',
        $code,
        $name,
        $person,
        $phone,
        $email,
        $address,
        $x['tax_number'],
        $x['registration_number'],
        $x['payment_terms'],
        $x['notes'],
        $status,
        $now,
        $now,
        $supplierCompany
    );
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'That supplier code is already in use.'];
        }
        error_log('supplier create failed');
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $after = [
        'supplier_code' => $code,
        'supplier_name' => $name,
        'contact_person' => $person,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'tax_number' => $x['tax_number'],
        'registration_number' => $x['registration_number'],
        'payment_terms' => $x['payment_terms'],
        'notes' => $x['notes'],
        'status' => $status,
    ];
    $changes = [];
    foreach ($after as $field => $value) {
        $changes[] = [
            'field_name' => $field,
            'old_value' => null,
            'new_value' => psSupplierAuditNormalizeValue($field, $value),
        ];
    }
    if (!psSupplierAuditRecordChanges($conn, [
        'supplier_id' => $newId,
        'supplier_code' => $code,
        'action' => PS_SUPPLIER_AUDIT_ACTION_CREATE,
        'changed_by' => $changedBy,
        'source' => PS_SUPPLIER_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    return ['ok' => true, 'id' => $newId];
}

/**
 * Update supplier fields (not status — use psSetSupplierStatus).
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psUpdateSupplier(mysqli $conn, int $id, array $data): array
{
    if (!psEnsureSupplierMasterSchema($conn) || !psEnsureSupplierAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    $changedBy = psSessionSupplierUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to edit a supplier.'];
    }
    $existing = psSupplierById($conn, $id);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Supplier was not found.'];
    }

    $codeCheck = psValidateSupplierCode((string)($data['supplier_code'] ?? ''));
    $nameCheck = psValidateSupplierName((string)($data['supplier_name'] ?? ''));
    $personCheck = psValidateSupplierOptionalText((string)($data['contact_person'] ?? ''), 'Contact person', 250);
    $phoneCheck = psValidateSupplierOptionalText((string)($data['phone'] ?? ''), 'Phone', 64);
    $emailCheck = psValidateSupplierEmail((string)($data['email'] ?? ''));
    $addrCheck = psValidateSupplierOptionalText((string)($data['address'] ?? ''), 'Address', 500);

    if (!$codeCheck['ok']) {
        return ['ok' => false, 'error' => $codeCheck['error']];
    }
    if (!$nameCheck['ok']) {
        return ['ok' => false, 'error' => $nameCheck['error']];
    }
    if (!$personCheck['ok']) {
        return ['ok' => false, 'error' => $personCheck['error']];
    }
    if (!$phoneCheck['ok']) {
        return ['ok' => false, 'error' => $phoneCheck['error']];
    }
    if (!$emailCheck['ok']) {
        return ['ok' => false, 'error' => $emailCheck['error']];
    }
    if (!$addrCheck['ok']) {
        return ['ok' => false, 'error' => $addrCheck['error']];
    }
    // Extra fields not posted (older forms) keep their stored values.
    $extra = psSupplierValidateExtra(array_merge(array_intersect_key($existing, psSupplierExtraColumns()), array_intersect_key($data, psSupplierExtraColumns())));
    if (!$extra['ok']) {
        return ['ok' => false, 'error' => $extra['error']];
    }
    $x = $extra['values'];
    if (psSupplierCodeExists($conn, $codeCheck['value'], $id)) {
        return ['ok' => false, 'error' => 'That supplier code is already in use.'];
    }

    $before = psSupplierAuditSnapshot($existing);
    $after = [
        'supplier_code' => $codeCheck['value'],
        'supplier_name' => $nameCheck['value'],
        'contact_person' => $personCheck['value'],
        'phone' => $phoneCheck['value'],
        'email' => $emailCheck['value'],
        'address' => $addrCheck['value'],
        'tax_number' => $x['tax_number'],
        'registration_number' => $x['registration_number'],
        'payment_terms' => $x['payment_terms'],
        'notes' => $x['notes'],
        'status' => psSupplierAuditNormalizeValue('status', $existing['status'] ?? PS_SUPPLIER_STATUS_ACTIVE),
    ];
    $changes = psSupplierAuditDiff($before, $after);
    // Status is not edited here; strip accidental status diffs.
    $changes = array_values(array_filter($changes, static function ($c) {
        return ($c['field_name'] ?? '') !== 'status';
    }));
    if ($changes === []) {
        return ['ok' => true, 'changed' => false];
    }

    $now = date('Y-m-d H:i:s');
    $code = $codeCheck['value'];
    $name = $nameCheck['value'];
    $person = $personCheck['value'];
    $phone = $phoneCheck['value'];
    $email = $emailCheck['value'];
    $address = $addrCheck['value'];

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE supplier_master
         SET supplier_code = ?, supplier_name = ?, contact_person = ?, phone = ?, email = ?, address = ?,
             tax_number = ?, registration_number = ?, payment_terms = ?, notes = ?, updated_at = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('supplier update prepare failed');
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssssi',
        $code,
        $name,
        $person,
        $phone,
        $email,
        $address,
        $x['tax_number'],
        $x['registration_number'],
        $x['payment_terms'],
        $x['notes'],
        $now,
        $id
    );
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'That supplier code is already in use.'];
        }
        error_log('supplier update failed');
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psSupplierAuditRecordChanges($conn, [
        'supplier_id' => $id,
        'supplier_code' => $code,
        'action' => PS_SUPPLIER_AUDIT_ACTION_UPDATE,
        'changed_by' => $changedBy,
        'source' => PS_SUPPLIER_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Activate / deactivate. Never deletes. No stock writes.
 *
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psSetSupplierStatus(mysqli $conn, int $id, string $newStatus): array
{
    if (!psEnsureSupplierMasterSchema($conn) || !psEnsureSupplierAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Supplier status could not be saved.'];
    }
    $changedBy = psSessionSupplierUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to change supplier status.'];
    }
    $normalized = psNormalizeSupplierStatus($newStatus);
    if (!$normalized['ok']) {
        return ['ok' => false, 'error' => $normalized['error']];
    }
    $existing = psSupplierById($conn, $id);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Supplier was not found.'];
    }
    $status = $normalized['value'];
    $oldStatus = psSupplierAuditNormalizeValue('status', $existing['status'] ?? null);
    if ($oldStatus === null || $oldStatus === '') {
        $oldStatus = PS_SUPPLIER_STATUS_ACTIVE;
    }
    if ($oldStatus === $status) {
        return ['ok' => true, 'changed' => false];
    }

    $now = date('Y-m-d H:i:s');
    $action = $status === PS_SUPPLIER_STATUS_ACTIVE
        ? PS_SUPPLIER_AUDIT_ACTION_ACTIVATE
        : PS_SUPPLIER_AUDIT_ACTION_DEACTIVATE;
    $code = trim((string)$existing['supplier_code']);

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Supplier status could not be saved.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE supplier_master SET status = ?, updated_at = ? WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Supplier status could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssi', $status, $now, $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        error_log('supplier status update failed');
        return ['ok' => false, 'error' => 'Supplier status could not be saved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psSupplierAuditRecordChanges($conn, [
        'supplier_id' => $id,
        'supplier_code' => $code,
        'action' => $action,
        'changed_by' => $changedBy,
        'source' => PS_SUPPLIER_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $oldStatus,
        'new_value' => $status,
    ]])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Supplier status could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Supplier status could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Hard delete is not allowed when history exists; Phase 4A never deletes suppliers.
 *
 * @return array{ok:bool,error?:string}
 */
function psDeleteSupplierBlocked(mysqli $conn, int $id): array
{
    unset($conn, $id);
    return ['ok' => false, 'error' => 'Suppliers cannot be deleted. Deactivate instead.'];
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psSupplierAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureSupplierAuditSchema($conn) || !psEnsureSupplierMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Supplier audit history could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM supplier_audit WHERE supplier_id IN (SELECT id FROM supplier_master WHERE '
        . psSupplierCompanySql($conn) . ')';
    $types = '';
    $params = [];
    $supplierId = (int)($filters['supplier_id'] ?? 0);
    $code = trim((string)($filters['supplier_code'] ?? ''));
    $limit = (int)($filters['limit'] ?? 300);
    if ($limit < 1 || $limit > 1000) {
        $limit = 300;
    }
    if ($supplierId > 0) {
        $sql .= ' AND supplier_id = ?';
        $types .= 'i';
        $params[] = $supplierId;
    }
    if ($code !== '') {
        $sql .= ' AND supplier_code = ?';
        $types .= 's';
        $params[] = $code;
    }
    $sql .= ' ORDER BY changed_at DESC, id DESC LIMIT ' . $limit;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Supplier audit history could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}
