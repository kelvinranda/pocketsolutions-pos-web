<?php
/**
 * Phase 4A — Supplier master helpers.
 * Global supplier identity by supplier_code (not name). Branch belongs on purchasing/GRV txns.
 * Does not write stock, goods.quantity, or sale_invoice_seq.
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
        $ready = true;
        return true;
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
        UNIQUE KEY uniq_supplier_master_code (supplier_code),
        KEY idx_supplier_master_name (supplier_name),
        KEY idx_supplier_master_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('supplier_master schema was not created');
        return false;
    }
    $ready = true;
    return true;
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
 * Validate supplier code: required, trimmed, unique-scope GLOBAL, safe characters.
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
        return null;
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

function psSupplierById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsureSupplierMasterSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM supplier_master WHERE id = ? LIMIT 1');
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

function psSupplierByCode(mysqli $conn, string $code): ?array
{
    $code = trim($code);
    if ($code === '' || !psEnsureSupplierMasterSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM supplier_master WHERE supplier_code = ? LIMIT 1');
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
    if ($excludeId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM supplier_master WHERE supplier_code = ? AND id <> ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $code, $excludeId);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM supplier_master WHERE supplier_code = ? LIMIT 1'
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
         WHERE status = ?
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
    $sql = 'SELECT * FROM supplier_master WHERE 1=1';
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
    if (psSupplierCodeExists($conn, $codeCheck['value'])) {
        return ['ok' => false, 'error' => 'That supplier code is already in use.'];
    }

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
         (supplier_code, supplier_name, contact_person, phone, email, address, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('supplier create prepare failed');
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssss',
        $code,
        $name,
        $person,
        $phone,
        $email,
        $address,
        $status,
        $now,
        $now
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
         SET supplier_code = ?, supplier_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, updated_at = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('supplier update prepare failed');
        return ['ok' => false, 'error' => 'The supplier could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssi',
        $code,
        $name,
        $person,
        $phone,
        $email,
        $address,
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
    if (!psEnsureSupplierAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Supplier audit history could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM supplier_audit WHERE 1=1';
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
