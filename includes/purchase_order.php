<?php
/**
 * Phase 4B — Purchase Orders.
 * Architecture: Supplier → PO → PO Lines → Receiving (4C) → stock_movements.
 * PO create/approve/cancel does NOT write stock_movements, goods.quantity, or goods.cost_price.
 * Numbering uses purchase_order_seq (never sale_invoice_seq).
 * CLOSED is set only by Phase 4C receiving when all lines are fully received (server-side).
 * Received quantity: derived from goods_receipts linked by purchase_order_line_id (no received_qty column).
 */

require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/supplier_master.php';

const PS_PO_STATUS_DRAFT = 'DRAFT';
const PS_PO_STATUS_APPROVED = 'APPROVED';
const PS_PO_STATUS_CANCELLED = 'CANCELLED';
const PS_PO_STATUS_CLOSED = 'CLOSED';

const PS_PO_AUDIT_SOURCE_UI = 'UI';
const PS_PO_AUDIT_ACTION_CREATE = 'CREATE';
const PS_PO_AUDIT_ACTION_UPDATE = 'UPDATE';
const PS_PO_AUDIT_ACTION_APPROVE = 'APPROVE';
const PS_PO_AUDIT_ACTION_CANCEL = 'CANCEL';
const PS_PO_AUDIT_ACTION_CLOSE = 'CLOSE';

/**
 * Ensure purchase_order_seq exists. Format: PO-000001 (PO- + zero-padded 6 digits).
 */
function psEnsurePurchaseOrderSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS purchase_order_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_order_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM purchase_order_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO purchase_order_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('purchase_order_seq was not seeded');
    return false;
}

/**
 * Ensure purchase_orders header table.
 * No FK to supplier_master: legacy DBs may lack InnoDB FK readiness; app validates supplier_id.
 */
function psEnsurePurchaseOrdersSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM purchase_orders LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(32) NOT NULL,
        supplier_id INT NOT NULL,
        branch VARCHAR(250) NOT NULL,
        order_date DATE NOT NULL,
        expected_date DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
        notes VARCHAR(1000) NOT NULL DEFAULT '',
        created_by VARCHAR(250) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_by VARCHAR(250) NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_purchase_orders_po_number (po_number),
        KEY idx_po_supplier_id (supplier_id),
        KEY idx_po_branch (branch),
        KEY idx_po_status (status),
        KEY idx_po_order_date (order_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_orders schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Ensure purchase_order_lines.
 * Identity for product is product_id + branch (matches goods UNIQUE).
 * No received_qty column — Phase 4C derives received from goods_receipts by purchase_order_line_id.
 */
function psEnsurePurchaseOrderLinesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM purchase_order_lines LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS purchase_order_lines (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        purchase_order_id INT NOT NULL,
        product_id VARCHAR(250) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        description VARCHAR(250) NOT NULL DEFAULT '',
        quantity DECIMAL(12,3) NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL,
        line_total DECIMAL(14,2) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_pol_po_id (purchase_order_id),
        KEY idx_pol_product_branch (product_id, branch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_order_lines schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Append-only PO audit (not product_audit / supplier_audit).
 */
function psEnsurePurchaseOrderAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM purchase_order_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS purchase_order_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        purchase_order_id INT NOT NULL,
        po_number VARCHAR(32) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_po_audit_po_id (purchase_order_id),
        KEY idx_po_audit_po_number (po_number),
        KEY idx_po_audit_branch (branch),
        KEY idx_po_audit_changed_at (changed_at),
        KEY idx_po_audit_changed_by (changed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_order_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsurePurchaseOrderAllSchema(mysqli $conn): bool
{
    return psEnsurePurchaseOrderSeq($conn)
        && psEnsurePurchaseOrdersSchema($conn)
        && psEnsurePurchaseOrderLinesSchema($conn)
        && psEnsurePurchaseOrderAuditSchema($conn)
        && psEnsureSupplierMasterSchema($conn);
}

function psSessionPoUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

/**
 * @return array{ok:bool,branch?:string,error?:string}
 */
function psResolvePoBranch(mysqli $conn, $postedBranch = null): array
{
    return psResolveProductWriteBranch($conn, $postedBranch);
}

function psNormalizePoStatus($raw): array
{
    $value = strtoupper(trim((string)$raw));
    $allowed = [
        PS_PO_STATUS_DRAFT,
        PS_PO_STATUS_APPROVED,
        PS_PO_STATUS_CANCELLED,
        PS_PO_STATUS_CLOSED,
    ];
    if (in_array($value, $allowed, true)) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'That purchase order status is not valid.'];
}

function psPoStatusLabel(string $status): string
{
    $check = psNormalizePoStatus($status);
    if (!$check['ok']) {
        return 'Unknown';
    }
    switch ($check['value']) {
        case PS_PO_STATUS_DRAFT:
            return 'Draft';
        case PS_PO_STATUS_APPROVED:
            return 'Approved';
        case PS_PO_STATUS_CANCELLED:
            return 'Cancelled';
        case PS_PO_STATUS_CLOSED:
            return 'Closed';
        default:
            return $check['value'];
    }
}

function psPoIsEditableStatus(string $status): bool
{
    $check = psNormalizePoStatus($status);
    return $check['ok'] && $check['value'] === PS_PO_STATUS_DRAFT;
}

function psPoCanApprove(string $status): bool
{
    return psPoIsEditableStatus($status);
}

function psPoCanCancel(string $status): bool
{
    $check = psNormalizePoStatus($status);
    if (!$check['ok']) {
        return false;
    }
    return in_array($check['value'], [PS_PO_STATUS_DRAFT, PS_PO_STATUS_APPROVED], true);
}

/**
 * PO quantity: required, > 0, max 3 decimal places. No silent truncation.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidatePoQuantity($raw): array
{
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Quantity is required.'];
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Quantity is required.'];
    }
    $raw = str_replace(',', '', $raw);
    if ($raw !== '' && ($raw[0] === '+' || $raw[0] === '-')) {
        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
    }
    if (!preg_match('/^\d+(\.\d{1,3})?$/', $raw)) {
        if (preg_match('/^\d+\.\d{4,}$/', $raw)) {
            return ['ok' => false, 'error' => 'Quantity cannot have more than 3 decimal places.'];
        }
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    if (function_exists('bccomp')) {
        if (bccomp($raw, '0', 3) <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
        }
    } elseif ((float)$raw <= 0) {
        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
    }
    return ['ok' => true, 'value' => $raw];
}

/**
 * Unit cost via existing money rules (non-negative; zero allowed; max 2 decimals).
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidatePoUnitCost($raw): array
{
    return psValidateProductMoney($raw, 'Unit cost');
}

/**
 * Server-side line total = qty × unit_cost, stored to 2 decimal places (half-up).
 * Browser totals are ignored.
 */
function psPoComputeLineTotal(string $qty, string $unitCost): string
{
    if (function_exists('bcmul') && function_exists('bcadd')) {
        $product = bcmul($qty, $unitCost, 4);
        $neg = (strlen($product) > 0 && $product[0] === '-');
        $abs = $neg ? substr($product, 1) : $product;
        if (strpos($abs, '.') === false) {
            $abs .= '.00';
        }
        [$whole, $frac] = array_pad(explode('.', $abs, 2), 2, '00');
        $frac = str_pad(substr($frac . '0000', 0, 4), 4, '0');
        $cents = (int)substr($frac, 0, 2);
        $next = (int)substr($frac, 2, 1);
        if ($next >= 5) {
            $cents++;
        }
        if ($cents >= 100) {
            $whole = (string)((int)$whole + 1);
            $cents -= 100;
        }
        $out = $whole . '.' . str_pad((string)$cents, 2, '0', STR_PAD_LEFT);
        return $neg ? '-' . $out : $out;
    }
    $n = round(((float)$qty) * ((float)$unitCost), 2, PHP_ROUND_HALF_UP);
    return number_format($n, 2, '.', '');
}

/**
 * Format allocated number as PO-000001.
 */
function psFormatPurchaseOrderNumber(int $n): string
{
    if ($n < 1) {
        return '';
    }
    return 'PO-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

/**
 * Concurrent-safe allocator. Must be called inside an open transaction.
 * Does not touch sale_invoice_seq.
 *
 * @return array{ok:bool,po_number?:string,error?:string}
 */
function psAllocatePurchaseOrderNumber(mysqli $conn): array
{
    if (!psEnsurePurchaseOrderSeq($conn) || !psEnsurePurchaseOrdersSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM purchase_order_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('PO number allocation failed');
        return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
    }
    $start = (int)$row['next_number'];
    if ($start < 1) {
        $start = 1;
    }
    for ($step = 0; $step < 50; $step++) {
        $candidate = $start + $step;
        if ($candidate >= 2000000000) {
            break;
        }
        $poNumber = psFormatPurchaseOrderNumber($candidate);
        if ($poNumber === '') {
            continue;
        }
        $stmt = mysqli_prepare($conn, 'SELECT id FROM purchase_orders WHERE po_number = ? LIMIT 1');
        if (!$stmt) {
            error_log('PO number lookup prepare failed');
            return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
        }
        mysqli_stmt_bind_param($stmt, 's', $poNumber);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            error_log('PO number lookup failed');
            return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
        }
        $takenRes = mysqli_stmt_get_result($stmt);
        $taken = $takenRes && mysqli_fetch_assoc($takenRes);
        mysqli_stmt_close($stmt);
        if ($taken) {
            continue;
        }
        $next = $candidate + 1;
        $upd = mysqli_prepare($conn, 'UPDATE purchase_order_seq SET next_number = ? WHERE id = 1');
        if (!$upd) {
            error_log('PO number update prepare failed');
            return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
        }
        mysqli_stmt_bind_param($upd, 'i', $next);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            error_log('PO number update failed');
            return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
        }
        mysqli_stmt_close($upd);
        return ['ok' => true, 'po_number' => $poNumber];
    }
    error_log('PO number allocation found no free number');
    return ['ok' => false, 'error' => 'Purchase order numbering is not available.'];
}

function psPoAuditNormalizeValue(string $field, $value): ?string
{
    if ($value === null) {
        return null;
    }
    return (string)$value;
}

/**
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psPoAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    if (!psEnsurePurchaseOrderAuditSchema($conn)) {
        return false;
    }
    $poId = (int)($meta['purchase_order_id'] ?? 0);
    $poNumber = (string)($meta['po_number'] ?? '');
    $branch = (string)($meta['branch'] ?? '');
    $action = (string)($meta['action'] ?? '');
    $changedBy = (string)($meta['changed_by'] ?? '');
    $source = (string)($meta['source'] ?? PS_PO_AUDIT_SOURCE_UI);
    $changedAt = (string)($meta['changed_at'] ?? date('Y-m-d H:i:s'));
    if ($poId < 1 || $poNumber === '' || $branch === '' || $action === '' || $changedBy === '') {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO purchase_order_audit
         (purchase_order_id, po_number, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('PO audit prepare failed');
        return false;
    }
    foreach ($changes as $change) {
        $field = (string)($change['field_name'] ?? '');
        $old = array_key_exists('old_value', $change) ? $change['old_value'] : null;
        $new = array_key_exists('new_value', $change) ? $change['new_value'] : null;
        if ($field === '') {
            mysqli_stmt_close($stmt);
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'isssssssss',
            $poId,
            $poNumber,
            $branch,
            $action,
            $field,
            $old,
            $new,
            $changedBy,
            $changedAt,
            $source
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            error_log('PO audit insert failed');
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psPoAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsurePurchaseOrderAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase order audit could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM purchase_order_audit WHERE ' . psCompanyBranchesInSql($conn, 'branch');
    $types = '';
    $params = [];
    $poId = (int)($filters['purchase_order_id'] ?? 0);
    if ($poId > 0) {
        $sql .= ' AND purchase_order_id = ?';
        $types .= 'i';
        $params[] = $poId;
    }
    $poNumber = trim((string)($filters['po_number'] ?? ''));
    if ($poNumber !== '') {
        $sql .= ' AND po_number = ?';
        $types .= 's';
        $params[] = $poNumber;
    }
    $branch = trim((string)($filters['branch'] ?? ''));
    if ($branch !== '') {
        $sql .= ' AND branch = ?';
        $types .= 's';
        $params[] = $branch;
    }
    $limit = (int)($filters['limit'] ?? 300);
    if ($limit < 1) {
        $limit = 300;
    }
    if ($limit > 1000) {
        $limit = 1000;
    }
    $sql .= ' ORDER BY changed_at DESC, id DESC LIMIT ' . $limit;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Purchase order audit could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase order audit could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Validate and normalize posted lines for the PO branch.
 * Product must exist on branch and be ACTIVE for new lines.
 *
 * @param array<int,array<string,mixed>> $rawLines
 * @return array{ok:bool,lines?:array<int,array<string,mixed>>,error?:string}
 */
function psValidatePoLines(mysqli $conn, string $branch, array $rawLines): array
{
    $branch = trim($branch);
    if ($branch === '') {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    $normalized = [];
    $seenProduct = [];
    foreach ($rawLines as $idx => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $productId = trim((string)($raw['product_id'] ?? ''));
        $qtyRaw = $raw['quantity'] ?? '';
        $costRaw = $raw['unit_cost'] ?? '';
        if ($productId === '' && trim((string)$qtyRaw) === '' && trim((string)$costRaw) === '') {
            continue;
        }
        if ($productId === '') {
            return ['ok' => false, 'error' => 'Each line needs a product.'];
        }
        if (isset($seenProduct[$productId])) {
            return ['ok' => false, 'error' => 'Duplicate product on the same purchase order is not allowed.'];
        }
        $qtyCheck = psValidatePoQuantity($qtyRaw);
        if (!$qtyCheck['ok']) {
            return ['ok' => false, 'error' => $qtyCheck['error']];
        }
        $costCheck = psValidatePoUnitCost($costRaw);
        if (!$costCheck['ok']) {
            return ['ok' => false, 'error' => $costCheck['error']];
        }
        $goods = psGoodsByProductIdBranch($conn, $productId, $branch);
        if ($goods === null) {
            return ['ok' => false, 'error' => 'A product on this order is not available on the selected branch.'];
        }
        $status = strtoupper(trim((string)($goods['status'] ?? '')));
        if ($status !== PS_PRODUCT_STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'Inactive products cannot be added to a purchase order.'];
        }
        $desc = trim((string)($goods['product'] ?? ''));
        if (strlen($desc) > 250) {
            $desc = substr($desc, 0, 250);
        }
        $lineTotal = psPoComputeLineTotal($qtyCheck['value'], $costCheck['value']);
        $seenProduct[$productId] = true;
        $normalized[] = [
            'product_id' => $productId,
            'branch' => $branch,
            'description' => $desc,
            'quantity' => $qtyCheck['value'],
            'unit_cost' => $costCheck['value'],
            'line_total' => $lineTotal,
        ];
    }
    if ($normalized === []) {
        return ['ok' => false, 'error' => 'Add at least one purchase order line.'];
    }
    return ['ok' => true, 'lines' => $normalized];
}

/**
 * ACTIVE products on branch for PO line picker (disambiguate duplicate names).
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psActiveProductsForPoSelect(mysqli $conn, string $branch, string $search = '', int $limit = 200): array
{
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $sessionBranch = $branchCheck['branch'];
    $branch = trim($branch);
    if ($branch === '' || $branch !== $sessionBranch) {
        return ['ok' => false, 'error' => 'That branch is not available.', 'rows' => []];
    }
    if (!psEnsureGoodsStatus($conn)) {
        return ['ok' => false, 'error' => 'Products could not be loaded.', 'rows' => []];
    }
    $limit = max(1, min(500, $limit));
    $active = PS_PRODUCT_STATUS_ACTIVE;
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, pack_size, cost_price, price, status, branch
             FROM goods
             WHERE branch = ? AND status = ?
               AND (product_id LIKE ? OR product LIKE ? OR pack_size LIKE ?)
             ORDER BY product, product_id
             LIMIT ' . $limit
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Products could not be loaded.', 'rows' => []];
        }
        mysqli_stmt_bind_param($stmt, 'sssss', $branch, $active, $like, $like, $like);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, pack_size, cost_price, price, status, branch
             FROM goods
             WHERE branch = ? AND status = ?
             ORDER BY product, product_id
             LIMIT ' . $limit
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Products could not be loaded.', 'rows' => []];
        }
        mysqli_stmt_bind_param($stmt, 'ss', $branch, $active);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Products could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $row['label'] = psProductDisplayLabel(
            (string)($row['product'] ?? ''),
            (string)($row['product_id'] ?? ''),
            (string)($row['pack_size'] ?? ''),
            $row['cost_price'] ?? null
        );
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

function psPurchaseOrderById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsurePurchaseOrdersSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM purchase_orders WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function psPurchaseOrderLines(mysqli $conn, int $poId): array
{
    if ($poId < 1 || !psEnsurePurchaseOrderLinesSchema($conn)) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM purchase_order_lines WHERE purchase_order_id = ? ORDER BY id ASC'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $poId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Branch-scoped load. Rejects foreign branch.
 *
 * @return array{ok:bool,header?:array<string,mixed>,lines?:array<int,array<string,mixed>>,error?:string}
 */
function psPurchaseOrderLoadForSession(mysqli $conn, int $id): array
{
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.'];
    }
    $header = psPurchaseOrderById($conn, $id);
    if ($header === null) {
        return ['ok' => false, 'error' => 'Purchase order not found.'];
    }
    if (trim((string)$header['branch']) !== $branchCheck['branch']) {
        return ['ok' => false, 'error' => 'That purchase order is not available on this branch.'];
    }
    return [
        'ok' => true,
        'header' => $header,
        'lines' => psPurchaseOrderLines($conn, $id),
    ];
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psPurchaseOrderList(mysqli $conn, array $filters = []): array
{
    if (!psEnsurePurchaseOrderAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase orders could not be loaded.', 'rows' => []];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $branch = $branchCheck['branch'];
    $sql = 'SELECT po.*, sm.supplier_code, sm.supplier_name
            FROM purchase_orders po
            LEFT JOIN supplier_master sm ON sm.id = po.supplier_id
            WHERE po.branch = ?';
    $types = 's';
    $params = [$branch];

    $statusFilter = strtolower(trim((string)($filters['status'] ?? 'all')));
    if (!in_array($statusFilter, ['all', 'draft', 'approved', 'cancelled', 'closed'], true)) {
        $statusFilter = 'all';
    }
    if ($statusFilter !== 'all') {
        $sql .= ' AND po.status = ?';
        $types .= 's';
        $params[] = strtoupper($statusFilter);
    }
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (po.po_number LIKE ? OR sm.supplier_code LIKE ? OR sm.supplier_name LIKE ? OR po.notes LIKE ?)';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    $fromDate = trim((string)($filters['from_date'] ?? ''));
    if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $sql .= ' AND po.order_date >= ?';
        $types .= 's';
        $params[] = $fromDate;
    }
    $toDate = trim((string)($filters['to_date'] ?? ''));
    if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $sql .= ' AND po.order_date <= ?';
        $types .= 's';
        $params[] = $toDate;
    }
    $sql .= ' ORDER BY po.order_date DESC, po.id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Purchase orders could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase orders could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * @param array<int,array<string,mixed>> $lines
 */
function psPoReplaceLines(mysqli $conn, int $poId, string $branch, array $lines, string $now): bool
{
    $del = mysqli_prepare($conn, 'DELETE FROM purchase_order_lines WHERE purchase_order_id = ?');
    if (!$del) {
        return false;
    }
    mysqli_stmt_bind_param($del, 'i', $poId);
    if (!mysqli_stmt_execute($del)) {
        mysqli_stmt_close($del);
        return false;
    }
    mysqli_stmt_close($del);

    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO purchase_order_lines
         (purchase_order_id, product_id, branch, description, quantity, unit_cost, line_total, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        return false;
    }
    foreach ($lines as $line) {
        $productId = (string)$line['product_id'];
        $lineBranch = (string)$line['branch'];
        if ($lineBranch !== $branch) {
            mysqli_stmt_close($ins);
            return false;
        }
        $description = (string)$line['description'];
        $quantity = (string)$line['quantity'];
        $unitCost = (string)$line['unit_cost'];
        $lineTotal = (string)$line['line_total'];
        mysqli_stmt_bind_param(
            $ins,
            'issssssss',
            $poId,
            $productId,
            $lineBranch,
            $description,
            $quantity,
            $unitCost,
            $lineTotal,
            $now,
            $now
        );
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            return false;
        }
    }
    mysqli_stmt_close($ins);
    return true;
}

/**
 * Create DRAFT PO. Allocates po_number server-side. Does not touch stock.
 *
 * @param array<string,mixed> $data
 * @param array<int,array<string,mixed>> $rawLines
 * @return array{ok:bool,id?:int,po_number?:string,error?:string}
 */
function psCreatePurchaseOrder(mysqli $conn, array $data, array $rawLines): array
{
    $changedBy = psSessionPoUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to create a purchase order.'];
    }
    if (!psEnsurePurchaseOrderAllSchema($conn)) {
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    $branchResolved = psResolvePoBranch($conn, $data['branch'] ?? null);
    if (!$branchResolved['ok']) {
        return ['ok' => false, 'error' => $branchResolved['error'] ?? 'That branch is not available.'];
    }
    $branch = $branchResolved['branch'];

    $supplierId = (int)($data['supplier_id'] ?? 0);
    if ($supplierId < 1) {
        return ['ok' => false, 'error' => 'Select an active supplier.'];
    }
    $supplier = psSupplierById($conn, $supplierId);
    if ($supplier === null) {
        return ['ok' => false, 'error' => 'Select an active supplier.'];
    }
    if (!psSupplierStatusIsActive($supplier['status'] ?? '')) {
        return ['ok' => false, 'error' => 'Inactive suppliers cannot be used on a new purchase order.'];
    }

    $orderDate = trim((string)($data['order_date'] ?? ''));
    if ($orderDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
        return ['ok' => false, 'error' => 'Order date is required.'];
    }
    $expectedDate = trim((string)($data['expected_date'] ?? ''));
    if ($expectedDate === '') {
        $expectedDate = null;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)) {
        return ['ok' => false, 'error' => 'Expected date is not valid.'];
    }

    $notes = trim((string)($data['notes'] ?? ''));
    if (strlen($notes) > 1000) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }

    $linesCheck = psValidatePoLines($conn, $branch, $rawLines);
    if (!$linesCheck['ok']) {
        return ['ok' => false, 'error' => $linesCheck['error']];
    }
    $lines = $linesCheck['lines'];
    $status = PS_PO_STATUS_DRAFT;
    $now = date('Y-m-d H:i:s');

    // Multi-currency (2026-10-20): the order's unit costs are in its currency (blank = company base). The rate kept here
    // is indicative; goods receipts take the rate on the day the goods arrive.
    require_once __DIR__ . '/currency.php';
    if (!psFxEnsureTransactionColumns($conn)) {
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }
    $poCurCheck = psFxCheckTransactionCurrency($conn, psFxSessionCompany(), $data['currency'] ?? '');
    if (empty($poCurCheck['ok'])) {
        return ['ok' => false, 'error' => (string)$poCurCheck['error']];
    }
    $poSnap = psFxSnapshot($conn, psFxSessionCompany(), (string)$poCurCheck['code'], $now);
    if (empty($poSnap['ok'])) {
        return ['ok' => false, 'error' => (string)$poSnap['error']];
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    $alloc = psAllocatePurchaseOrderNumber($conn);
    if (!$alloc['ok']) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $alloc['error'] ?? 'The purchase order could not be saved.'];
    }
    $poNumber = $alloc['po_number'];

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO purchase_orders
         (po_number, supplier_id, branch, order_date, expected_date, status, notes, created_by, created_at, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('PO create prepare failed');
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sisssssssss',
        $poNumber,
        $supplierId,
        $branch,
        $orderDate,
        $expectedDate,
        $status,
        $notes,
        $changedBy,
        $now,
        $changedBy,
        $now
    );
    if (!mysqli_stmt_execute($stmt)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'That purchase order number is already in use.'];
        }
        error_log('PO create failed');
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if (psFxHasColumn($conn, 'purchase_orders', 'currency')) {
        $v = psFxSnapshotSqlValues($conn, $poSnap);
        if (!mysqli_query($conn, 'UPDATE purchase_orders SET currency = ' . $v['currency'] . ', base_currency = ' . $v['base_currency']
            . ', fx_rate = ' . $v['fx_rate'] . ', fx_rate_ids = ' . $v['fx_rate_ids'] . ', fx_path = ' . $v['fx_path'] . ' WHERE id = ' . $newId)) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
        }
    }

    if (!psPoReplaceLines($conn, $newId, $branch, $lines, $now)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    $lineSummary = [];
    foreach ($lines as $line) {
        $lineSummary[] = $line['product_id'] . ':' . $line['quantity'] . '@' . $line['unit_cost'];
    }
    $changes = [
        ['field_name' => 'po_number', 'old_value' => null, 'new_value' => $poNumber],
        ['field_name' => 'supplier_id', 'old_value' => null, 'new_value' => (string)$supplierId],
        ['field_name' => 'branch', 'old_value' => null, 'new_value' => $branch],
        ['field_name' => 'currency', 'old_value' => null, 'new_value' => (string)$poSnap['currency']],
        ['field_name' => 'order_date', 'old_value' => null, 'new_value' => $orderDate],
        ['field_name' => 'expected_date', 'old_value' => null, 'new_value' => $expectedDate],
        ['field_name' => 'status', 'old_value' => null, 'new_value' => $status],
        ['field_name' => 'notes', 'old_value' => null, 'new_value' => $notes],
        ['field_name' => 'lines', 'old_value' => null, 'new_value' => implode('|', $lineSummary)],
    ];
    if (!psPoAuditRecordChanges($conn, [
        'purchase_order_id' => $newId,
        'po_number' => $poNumber,
        'branch' => $branch,
        'action' => PS_PO_AUDIT_ACTION_CREATE,
        'changed_by' => $changedBy,
        'source' => PS_PO_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }
    return ['ok' => true, 'id' => $newId, 'po_number' => $poNumber];
}

/**
 * Update DRAFT only. APPROVED blocks supplier/branch/lines/qty/cost changes.
 *
 * @param array<string,mixed> $data
 * @param array<int,array<string,mixed>> $rawLines
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psUpdatePurchaseOrder(mysqli $conn, int $id, array $data, array $rawLines): array
{
    $changedBy = psSessionPoUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to edit a purchase order.'];
    }
    $load = psPurchaseOrderLoadForSession($conn, $id);
    if (!$load['ok']) {
        return ['ok' => false, 'error' => $load['error'] ?? 'Purchase order not found.'];
    }
    $header = $load['header'];
    $oldLines = $load['lines'];
    if (!psPoIsEditableStatus((string)$header['status'])) {
        return ['ok' => false, 'error' => 'Only draft purchase orders can be edited.'];
    }

    $branchResolved = psResolvePoBranch($conn, $data['branch'] ?? $header['branch']);
    if (!$branchResolved['ok']) {
        return ['ok' => false, 'error' => $branchResolved['error'] ?? 'That branch is not available.'];
    }
    $branch = $branchResolved['branch'];
    if ($branch !== trim((string)$header['branch'])) {
        return ['ok' => false, 'error' => 'Purchase order branch cannot be changed.'];
    }

    $supplierId = (int)($data['supplier_id'] ?? 0);
    if ($supplierId < 1) {
        return ['ok' => false, 'error' => 'Select an active supplier.'];
    }
    $supplier = psSupplierById($conn, $supplierId);
    if ($supplier === null) {
        return ['ok' => false, 'error' => 'Select an active supplier.'];
    }
    if (!psSupplierStatusIsActive($supplier['status'] ?? '') && $supplierId !== (int)$header['supplier_id']) {
        return ['ok' => false, 'error' => 'Inactive suppliers cannot be used on a purchase order.'];
    }
    if (!psSupplierStatusIsActive($supplier['status'] ?? '') && $supplierId === (int)$header['supplier_id']) {
        // Keep existing supplier on draft if it became inactive — still cannot switch TO inactive.
        // Re-saving same inactive supplier is allowed only if already on header; prefer require ACTIVE:
        return ['ok' => false, 'error' => 'Inactive suppliers cannot be used on a purchase order.'];
    }

    $orderDate = trim((string)($data['order_date'] ?? ''));
    if ($orderDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
        return ['ok' => false, 'error' => 'Order date is required.'];
    }
    $expectedDate = trim((string)($data['expected_date'] ?? ''));
    if ($expectedDate === '') {
        $expectedDate = null;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)) {
        return ['ok' => false, 'error' => 'Expected date is not valid.'];
    }
    $notes = trim((string)($data['notes'] ?? ''));
    if (strlen($notes) > 1000) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }

    $linesCheck = psValidatePoLines($conn, $branch, $rawLines);
    if (!$linesCheck['ok']) {
        return ['ok' => false, 'error' => $linesCheck['error']];
    }
    $lines = $linesCheck['lines'];
    $now = date('Y-m-d H:i:s');

    $oldLineSummary = [];
    foreach ($oldLines as $line) {
        $oldLineSummary[] = $line['product_id'] . ':' . $line['quantity'] . '@' . $line['unit_cost'];
    }
    $newLineSummary = [];
    foreach ($lines as $line) {
        $newLineSummary[] = $line['product_id'] . ':' . $line['quantity'] . '@' . $line['unit_cost'];
    }

    $fieldMap = [
        'supplier_id' => [(string)(int)$header['supplier_id'], (string)$supplierId],
        'order_date' => [(string)$header['order_date'], $orderDate],
        'expected_date' => [
            $header['expected_date'] !== null && $header['expected_date'] !== '' ? (string)$header['expected_date'] : null,
            $expectedDate,
        ],
        'notes' => [(string)$header['notes'], $notes],
        'lines' => [implode('|', $oldLineSummary), implode('|', $newLineSummary)],
    ];
    $changes = [];
    foreach ($fieldMap as $field => $pair) {
        $old = $pair[0];
        $new = $pair[1];
        if ((string)($old ?? '') !== (string)($new ?? '')) {
            $changes[] = [
                'field_name' => $field,
                'old_value' => $old,
                'new_value' => $new,
            ];
        }
    }
    if ($changes === []) {
        return ['ok' => true, 'changed' => false];
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE purchase_orders
         SET supplier_id = ?, order_date = ?, expected_date = ?, notes = ?, updated_by = ?, updated_at = ?
         WHERE id = ? AND branch = ? AND status = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }
    $draft = PS_PO_STATUS_DRAFT;
    mysqli_stmt_bind_param(
        $stmt,
        'isssssiss',
        $supplierId,
        $orderDate,
        $expectedDate,
        $notes,
        $changedBy,
        $now,
        $id,
        $branch,
        $draft
    );
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Only draft purchase orders can be edited.'];
    }
    mysqli_stmt_close($stmt);

    if (!psPoReplaceLines($conn, $id, $branch, $lines, $now)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    if (!psPoAuditRecordChanges($conn, [
        'purchase_order_id' => $id,
        'po_number' => (string)$header['po_number'],
        'branch' => $branch,
        'action' => PS_PO_AUDIT_ACTION_UPDATE,
        'changed_by' => $changedBy,
        'source' => PS_PO_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $changes)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * DRAFT → APPROVED. No stock writes.
 *
 * @return array{ok:bool,error?:string}
 */
function psApprovePurchaseOrder(mysqli $conn, int $id): array
{
    $changedBy = psSessionPoUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to approve a purchase order.'];
    }
    $load = psPurchaseOrderLoadForSession($conn, $id);
    if (!$load['ok']) {
        return ['ok' => false, 'error' => $load['error'] ?? 'Purchase order not found.'];
    }
    $header = $load['header'];
    if (!psPoCanApprove((string)$header['status'])) {
        return ['ok' => false, 'error' => 'Only draft purchase orders can be approved.'];
    }
    if ($load['lines'] === []) {
        return ['ok' => false, 'error' => 'A purchase order needs at least one line before approval.'];
    }
    $supplier = psSupplierByIdForDocument($conn, (int)$header['supplier_id']);
    if ($supplier === null || !psSupplierStatusIsActive($supplier['status'] ?? '')) {
        return ['ok' => false, 'error' => 'The supplier must be active to approve this purchase order.'];
    }

    $branch = trim((string)$header['branch']);
    $now = date('Y-m-d H:i:s');
    $approved = PS_PO_STATUS_APPROVED;
    $draft = PS_PO_STATUS_DRAFT;

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The purchase order could not be approved.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE purchase_orders SET status = ?, updated_by = ?, updated_at = ?
         WHERE id = ? AND branch = ? AND status = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be approved.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssiss', $approved, $changedBy, $now, $id, $branch, $draft);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Only draft purchase orders can be approved.'];
    }
    mysqli_stmt_close($stmt);

    if (!psPoAuditRecordChanges($conn, [
        'purchase_order_id' => $id,
        'po_number' => (string)$header['po_number'],
        'branch' => $branch,
        'action' => PS_PO_AUDIT_ACTION_APPROVE,
        'changed_by' => $changedBy,
        'source' => PS_PO_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $draft,
        'new_value' => $approved,
    ]])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be approved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be approved.'];
    }
    return ['ok' => true];
}

/**
 * Cancel DRAFT or APPROVED. CLOSED / already CANCELLED rejected.
 * Does not reverse stock (PO never posts stock).
 *
 * @return array{ok:bool,error?:string}
 */
function psCancelPurchaseOrder(mysqli $conn, int $id): array
{
    $changedBy = psSessionPoUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to cancel a purchase order.'];
    }
    $load = psPurchaseOrderLoadForSession($conn, $id);
    if (!$load['ok']) {
        return ['ok' => false, 'error' => $load['error'] ?? 'Purchase order not found.'];
    }
    $header = $load['header'];
    $oldStatus = strtoupper(trim((string)$header['status']));
    if (!psPoCanCancel($oldStatus)) {
        return ['ok' => false, 'error' => 'That purchase order cannot be cancelled.'];
    }

    $branch = trim((string)$header['branch']);
    $now = date('Y-m-d H:i:s');
    $cancelled = PS_PO_STATUS_CANCELLED;

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The purchase order could not be cancelled.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE purchase_orders SET status = ?, updated_by = ?, updated_at = ?
         WHERE id = ? AND branch = ? AND status = ?'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be cancelled.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssiss', $cancelled, $changedBy, $now, $id, $branch, $oldStatus);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'That purchase order cannot be cancelled.'];
    }
    mysqli_stmt_close($stmt);

    if (!psPoAuditRecordChanges($conn, [
        'purchase_order_id' => $id,
        'po_number' => (string)$header['po_number'],
        'branch' => $branch,
        'action' => PS_PO_AUDIT_ACTION_CANCEL,
        'changed_by' => $changedBy,
        'source' => PS_PO_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $oldStatus,
        'new_value' => $cancelled,
    ]])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be cancelled.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The purchase order could not be cancelled.'];
    }
    return ['ok' => true];
}

/**
 * Reject arbitrary status POST — only approve/cancel helpers and receiving close may change status.
 * CLOSED must never be forced from browser POST.
 *
 * @return array{ok:bool,error?:string}
 */
function psSetPurchaseOrderStatusBlocked(mysqli $conn, int $id, string $status): array
{
    return ['ok' => false, 'error' => 'Purchase order status cannot be set directly.'];
}
