<?php
/**
 * Phase 4D — Purchase Returns.
 *
 * Architecture:
 *   GRV (goods_receipts_ref / goods_receipts) → return header/lines → stock_movements(PURCHASE_RETURN).
 * Only GRV lines with a reliable identity (non-empty grv_number + product_id + branch) are returnable.
 * returnable_qty = received line qty − previously returned against that goods_receipts.id.
 * Cost from original receiving line_cost (not goods.cost_price).
 * Does NOT reopen CLOSED POs; does NOT change PO outstanding/received derivation.
 * Does NOT write goods.quantity or goods.cost_price.
 * Numbering: purchase_return_seq → PR-000001 (never sale/PO/GRV seq or MAX+1).
 * Movement type PURCHASE_RETURN is distinct from customer REFUND.
 */

require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/supplier_master.php';
require_once __DIR__ . '/purchase_order.php';
require_once __DIR__ . '/goods_receipt.php';
require_once __DIR__ . '/stock_movement.php';

const PS_PR_AUDIT_SOURCE_UI = 'UI';
const PS_PR_AUDIT_CREATE = 'CREATE_RETURN';
const PS_PR_STATUS_COMPLETED = 'COMPLETED';

/**
 * @return bool
 */
function psEnsurePurchaseReturnSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS purchase_return_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_return_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM purchase_return_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO purchase_return_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('purchase_return_seq was not seeded');
    return false;
}

/**
 * @return bool
 */
function psEnsurePurchaseReturnsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM purchase_returns LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS purchase_returns (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        return_number VARCHAR(32) NOT NULL,
        grv_number VARCHAR(32) NOT NULL,
        goods_receipt_ref_id INT NULL,
        purchase_order_id INT NULL,
        po_number VARCHAR(32) NULL,
        supplier_id INT NULL,
        supplier_name VARCHAR(250) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        return_date DATE NOT NULL,
        reason VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'COMPLETED',
        total DECIMAL(14,2) NOT NULL,
        created_by VARCHAR(250) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_purchase_returns_number (return_number),
        KEY idx_pr_grv (grv_number),
        KEY idx_pr_supplier_id (supplier_id),
        KEY idx_pr_branch (branch),
        KEY idx_pr_return_date (return_date),
        KEY idx_pr_po_id (purchase_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_returns schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * @return bool
 */
function psEnsurePurchaseReturnLinesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM purchase_return_lines LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS purchase_return_lines (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        purchase_return_id INT NOT NULL,
        goods_receipt_line_id INT NOT NULL,
        product_id VARCHAR(250) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        description VARCHAR(250) NOT NULL DEFAULT '',
        quantity DECIMAL(12,3) NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL,
        line_total DECIMAL(14,2) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_prl_return_id (purchase_return_id),
        KEY idx_prl_gr_line (goods_receipt_line_id),
        KEY idx_prl_product_branch (product_id, branch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_return_lines schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Append-only purchase return audit (not product_audit).
 *
 * @return bool
 */
function psEnsurePurchaseReturnAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM purchase_return_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS purchase_return_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        purchase_return_id INT NOT NULL,
        return_number VARCHAR(32) NOT NULL,
        grv_number VARCHAR(32) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_pr_audit_return_id (purchase_return_id),
        KEY idx_pr_audit_return_number (return_number),
        KEY idx_pr_audit_grv (grv_number),
        KEY idx_pr_audit_branch (branch),
        KEY idx_pr_audit_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('purchase_return_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsurePurchaseReturnAllSchema(mysqli $conn): bool
{
    return psEnsurePurchaseReturnSeq($conn)
        && psEnsurePurchaseReturnsSchema($conn)
        && psEnsurePurchaseReturnLinesSchema($conn)
        && psEnsurePurchaseReturnAuditSchema($conn)
        && psEnsureGoodsReceiptAllSchema($conn)
        && stockMovementEnsureSchema($conn);
}

function psSessionPrUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psFormatPurchaseReturnNumber(int $n): string
{
    if ($n < 1) {
        return '';
    }
    return 'PR-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

/**
 * Concurrent-safe PR allocator. Must run inside an open transaction.
 *
 * @return array{ok:bool,return_number?:string,error?:string}
 */
function psAllocatePurchaseReturnNumber(mysqli $conn): array
{
    if (!psEnsurePurchaseReturnSeq($conn) || !psEnsurePurchaseReturnsSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM purchase_return_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('PR number allocation failed');
        return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
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
        $returnNumber = psFormatPurchaseReturnNumber($candidate);
        if ($returnNumber === '') {
            continue;
        }
        $stmt = mysqli_prepare($conn, 'SELECT id FROM purchase_returns WHERE return_number = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
        }
        mysqli_stmt_bind_param($stmt, 's', $returnNumber);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
        }
        $takenRes = mysqli_stmt_get_result($stmt);
        $taken = $takenRes && mysqli_fetch_assoc($takenRes);
        mysqli_stmt_close($stmt);
        if ($taken) {
            continue;
        }
        $next = $candidate + 1;
        $upd = mysqli_prepare($conn, 'UPDATE purchase_return_seq SET next_number = ? WHERE id = 1');
        if (!$upd) {
            return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
        }
        mysqli_stmt_bind_param($upd, 'i', $next);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
        }
        mysqli_stmt_close($upd);
        return ['ok' => true, 'return_number' => $returnNumber];
    }
    return ['ok' => false, 'error' => 'Purchase return numbering is not available.'];
}

/**
 * Sum previously returned qty per goods_receipts line id.
 *
 * @param array<int,int> $lineIds
 * @return array<int,string>
 */
function psPrReturnedQtyByGoodsReceiptLineIds(mysqli $conn, array $lineIds): array
{
    $out = [];
    $ids = [];
    foreach ($lineIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
            $out[$id] = '0.000';
        }
    }
    if ($ids === [] || !psEnsurePurchaseReturnLinesSchema($conn)) {
        return $out;
    }
    $list = implode(',', array_map('intval', array_values($ids)));
    $sql = "SELECT goods_receipt_line_id AS lid,
                   COALESCE(SUM(quantity), 0) AS returned_qty
            FROM purchase_return_lines
            WHERE goods_receipt_line_id IN ({$list})
            GROUP BY goods_receipt_line_id";
    $res = mysqli_query($conn, $sql);
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $lid = (int)$row['lid'];
        $qty = psStockDecimal((string)$row['returned_qty']);
        $out[$lid] = $qty !== null ? $qty : '0.000';
    }
    return $out;
}

/**
 * True when a goods_receipts row is a reliable return source.
 */
function psPrReceivingLineIsReliable(array $line): bool
{
    $id = (int)($line['id'] ?? 0);
    $grv = trim((string)($line['grv_number'] ?? ''));
    $productId = trim((string)($line['product_id'] ?? ''));
    $branch = trim((string)($line['branch'] ?? ''));
    if ($id < 1 || $grv === '' || $productId === '' || $branch === '') {
        return false;
    }
    $qty = psStockDecimal((string)($line['quantity'] ?? ''));
    return $qty !== null && psStockDecimalCmp($qty, '0') > 0;
}

/**
 * Load GRV header for session branch by grv_number.
 *
 * @return array{ok:bool,header?:array<string,mixed>,error?:string}
 */
function psPrLoadGrvHeaderForSession(mysqli $conn, string $grvNumber): array
{
    $grvNumber = trim($grvNumber);
    if ($grvNumber === '') {
        return ['ok' => false, 'error' => 'Goods receipt number is required.'];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.'];
    }
    $branch = $branchCheck['branch'];
    if (!psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Goods receipt could not be loaded.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT r.*, po.po_number
         FROM goods_receipts_ref r
         LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
         WHERE r.grv_number = ? AND r.branch = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Goods receipt could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $grvNumber, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Goods receipt could not be loaded.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $header = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$header) {
        return ['ok' => false, 'error' => 'Goods receipt was not found on this branch.'];
    }
    return ['ok' => true, 'header' => $header];
}

/**
 * Returnable lines for a GRV on the session branch.
 *
 * @return array{ok:bool,header?:array<string,mixed>,lines?:array<int,array<string,mixed>>,error?:string}
 */
function psPrReturnableLinesForGrv(mysqli $conn, string $grvNumber): array
{
    $load = psPrLoadGrvHeaderForSession($conn, $grvNumber);
    if (!$load['ok']) {
        return $load;
    }
    $header = $load['header'];
    $branch = trim((string)$header['branch']);
    $grvNumber = trim((string)$header['grv_number']);

    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, product_id, product, quantity, line_cost, branch, grv_number,
                purchase_order_id, purchase_order_line_id, supplier_id, supplier_name, date_, invoice_number
         FROM goods_receipts
         WHERE grv_number = ? AND branch = ?
         ORDER BY id ASC'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Goods receipt lines could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $grvNumber, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Goods receipt lines could not be loaded.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $raw = [];
    $ids = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        if (!psPrReceivingLineIsReliable($row)) {
            continue;
        }
        $raw[] = $row;
        $ids[] = (int)$row['id'];
    }
    mysqli_stmt_close($stmt);

    $returnedMap = psPrReturnedQtyByGoodsReceiptLineIds($conn, $ids);
    $lines = [];
    foreach ($raw as $row) {
        $lid = (int)$row['id'];
        $received = psStockDecimal((string)$row['quantity']);
        if ($received === null) {
            continue;
        }
        $returned = $returnedMap[$lid] ?? '0.000';
        $returnable = '0.000';
        if (function_exists('bcsub')) {
            $returnable = bcsub($received, $returned, 3);
            if (bccomp($returnable, '0', 3) < 0) {
                $returnable = '0.000';
            }
        } else {
            $returnable = number_format(max(0, (float)$received - (float)$returned), 3, '.', '');
        }
        $unitCost = psStockDecimal((string)$row['line_cost'], 2);
        if ($unitCost === null) {
            $costCheck = psValidateProductMoney((string)$row['line_cost'], 'Unit cost');
            $unitCost = $costCheck['ok'] ? $costCheck['value'] : '0.00';
        }
        $row['received_qty'] = $received;
        $row['returned_qty'] = $returned;
        $row['returnable_qty'] = $returnable;
        $row['unit_cost'] = $unitCost;
        $lines[] = $row;
    }
    return ['ok' => true, 'header' => $header, 'lines' => $lines];
}

/**
 * GRVs on session branch that still have returnable qty.
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psPrReturnableGrvListForSession(mysqli $conn): array
{
    if (!psEnsurePurchaseReturnAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Goods receipts could not be loaded.', 'rows' => []];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $branch = $branchCheck['branch'];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT r.id, r.grv_number, r.product AS supplier_name, r.reference1, r.date_, r.totalz,
                r.supplier_id, r.purchase_order_id, r.created_by, po.po_number
         FROM goods_receipts_ref r
         LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
         WHERE r.branch = ? AND r.grv_number IS NOT NULL AND r.grv_number <> \'\'
         ORDER BY r.id DESC
         LIMIT 200'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Goods receipts could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Goods receipts could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $candidates = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $candidates[] = $row;
    }
    mysqli_stmt_close($stmt);

    $rows = [];
    foreach ($candidates as $cand) {
        $detail = psPrReturnableLinesForGrv($conn, (string)$cand['grv_number']);
        if (!$detail['ok']) {
            continue;
        }
        $hasReturnable = false;
        foreach ($detail['lines'] as $line) {
            if (psStockDecimalCmp((string)$line['returnable_qty'], '0') > 0) {
                $hasReturnable = true;
                break;
            }
        }
        if ($hasReturnable) {
            $rows[] = $cand;
        }
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Negative movement qty for PURCHASE_RETURN (out).
 */
function psPrMovementQuantity(string $positiveQty): ?string
{
    $qty = psStockDecimal($positiveQty);
    if ($qty === null || psStockDecimalCmp($qty, '0') <= 0) {
        return null;
    }
    if (function_exists('bcmul')) {
        return bcmul($qty, '-1', 3);
    }
    return number_format(-1 * (float)$qty, 3, '.', '');
}

/**
 * Controlled-stock availability check for return qty (same OPENING_BALANCE rule as sales).
 *
 * @param array<int,array{product_id:string,quantity:string,description?:string}> $lines
 * @return array{ok:bool,error?:string}
 */
function psPrGuardStockAvailability(mysqli $conn, array $lines, string $branch): array
{
    $grouped = [];
    $order = [];
    foreach ($lines as $item) {
        $productId = trim((string)($item['product_id'] ?? ''));
        $qty = psStockDecimal((string)($item['quantity'] ?? ''));
        if ($productId === '' || $qty === null || psStockDecimalCmp($qty, '0') <= 0) {
            return ['ok' => false, 'error' => 'Return quantity must be greater than zero.'];
        }
        if (!isset($grouped[$productId])) {
            $name = trim((string)($item['description'] ?? ''));
            if ($name === '') {
                $name = $productId;
            }
            $grouped[$productId] = ['qty' => '0.000', 'name' => $name];
            $order[] = $productId;
        }
        if (function_exists('bcadd')) {
            $grouped[$productId]['qty'] = bcadd($grouped[$productId]['qty'], $qty, 3);
        } else {
            $grouped[$productId]['qty'] = number_format((float)$grouped[$productId]['qty'] + (float)$qty, 3, '.', '');
        }
    }
    foreach ($order as $productId) {
        $ledger = psSaleLedgerBalance($conn, $productId, $branch);
        if (empty($ledger['ok'])) {
            return ['ok' => false, 'error' => 'Stock availability could not be checked.'];
        }
        if (empty($ledger['tracked'])) {
            continue;
        }
        $requested = $grouped[$productId]['qty'];
        $available = (string)$ledger['balance'];
        if (psStockDecimalCmp($requested, $available) > 0) {
            return [
                'ok' => false,
                'error' => 'Insufficient stock for ' . $grouped[$productId]['name']
                    . '. Requested return: ' . psStockQtyPlain($requested)
                    . '. Available: ' . psStockQtyPlain($available) . '.',
            ];
        }
    }
    return ['ok' => true];
}

/**
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psPrAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    if (!psEnsurePurchaseReturnAuditSchema($conn)) {
        return false;
    }
    $returnId = (int)($meta['purchase_return_id'] ?? 0);
    $returnNumber = (string)($meta['return_number'] ?? '');
    $grvNumber = (string)($meta['grv_number'] ?? '');
    $branch = (string)($meta['branch'] ?? '');
    $action = (string)($meta['action'] ?? '');
    $changedBy = (string)($meta['changed_by'] ?? '');
    $source = (string)($meta['source'] ?? PS_PR_AUDIT_SOURCE_UI);
    $changedAt = (string)($meta['changed_at'] ?? date('Y-m-d H:i:s'));
    if ($returnId < 1 || $returnNumber === '' || $grvNumber === '' || $branch === '' || $action === '' || $changedBy === '') {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO purchase_return_audit
         (purchase_return_id, return_number, grv_number, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('PR audit prepare failed');
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
            'issssssssss',
            $returnId,
            $returnNumber,
            $grvNumber,
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
            error_log('PR audit insert failed');
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

/**
 * Complete a purchase return atomically.
 *
 * @return array{ok:bool,error?:string,return_number?:string,return_id?:int,movement_ids?:array<int,int>}
 */
function psSavePurchaseReturn(mysqli $conn, array $payload, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsurePurchaseReturnAllSchema($conn)) {
        return ['ok' => false, 'error' => 'The purchase return could not be saved.'];
    }
    $changedBy = psSessionPrUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to create a purchase return.'];
    }
    $branchResolved = psResolveProductWriteBranch($conn, $payload['branch'] ?? null);
    if (!$branchResolved['ok']) {
        return ['ok' => false, 'error' => $branchResolved['error'] ?? 'That branch is not available.'];
    }
    $branch = $branchResolved['branch'];

    $grvNumber = trim((string)($payload['grv_number'] ?? ''));
    $returnDate = trim((string)($payload['return_date'] ?? ''));
    $reason = trim((string)($payload['reason'] ?? ''));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A return reason is required.'];
    }
    if (strlen($reason) > 500) {
        return ['ok' => false, 'error' => 'Return reason is too long.'];
    }
    if ($returnDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        return ['ok' => false, 'error' => 'Return date is required.'];
    }

    $detail = psPrReturnableLinesForGrv($conn, $grvNumber);
    if (!$detail['ok']) {
        return ['ok' => false, 'error' => $detail['error'] ?? 'Goods receipt not found.'];
    }
    $header = $detail['header'];
    if (trim((string)$header['branch']) !== $branch) {
        return ['ok' => false, 'error' => 'That goods receipt is not available on this branch.'];
    }
    $byId = [];
    foreach ($detail['lines'] as $line) {
        $byId[(int)$line['id']] = $line;
    }

    $lineIds = $payload['goods_receipt_line_ids'] ?? [];
    $returnQtys = $payload['return_quantities'] ?? [];
    if (!is_array($lineIds) || !is_array($returnQtys)) {
        return ['ok' => false, 'error' => 'Add at least one return quantity.'];
    }
    $lines = [];
    $seen = [];
    $count = max(count($lineIds), count($returnQtys));
    for ($i = 0; $i < $count; $i++) {
        $lineId = (int)($lineIds[$i] ?? 0);
        $qtyRaw = trim((string)($returnQtys[$i] ?? ''));
        if ($lineId < 1 && $qtyRaw === '') {
            continue;
        }
        if ($lineId < 1) {
            return ['ok' => false, 'error' => 'Return against a goods receipt line.'];
        }
        if (isset($seen[$lineId])) {
            return ['ok' => false, 'error' => 'Each goods receipt line can appear only once on a return.'];
        }
        $seen[$lineId] = true;
        if (!isset($byId[$lineId])) {
            return ['ok' => false, 'error' => 'That receiving line is not a valid return source for this goods receipt.'];
        }
        $src = $byId[$lineId];
        if (trim((string)$src['branch']) !== $branch) {
            return ['ok' => false, 'error' => 'Cross-branch purchase returns are not allowed.'];
        }
        $qtyCheck = psValidatePoQuantity($qtyRaw);
        if (!$qtyCheck['ok']) {
            if (isset($qtyCheck['error']) && strpos((string)$qtyCheck['error'], 'greater than zero') !== false) {
                return ['ok' => false, 'error' => 'Return quantity must be greater than zero.'];
            }
            return ['ok' => false, 'error' => $qtyCheck['error'] ?? 'Quantity must be a number.'];
        }
        $qty = psStockDecimal($qtyCheck['value']);
        if ($qty === null || psStockDecimalCmp($qty, '0') <= 0) {
            return ['ok' => false, 'error' => 'Return quantity must be greater than zero.'];
        }
        $returnable = (string)$src['returnable_qty'];
        if (psStockDecimalCmp($qty, $returnable) > 0) {
            return ['ok' => false, 'error' => 'Return quantity cannot exceed returnable quantity.'];
        }
        $unitCost = (string)$src['unit_cost'];
        $lines[] = [
            'goods_receipt_line_id' => $lineId,
            'product_id' => trim((string)$src['product_id']),
            'branch' => $branch,
            'description' => trim((string)$src['product']),
            'quantity' => psStockQtyPlain($qty),
            'quantity_decimal' => $qty,
            'unit_cost' => $unitCost,
            'line_total' => psPoComputeLineTotal($qty, $unitCost),
        ];
    }
    if ($lines === []) {
        return ['ok' => false, 'error' => 'Add at least one return quantity.'];
    }

    $stockGuard = psPrGuardStockAvailability($conn, $lines, $branch);
    if (!$stockGuard['ok']) {
        return ['ok' => false, 'error' => $stockGuard['error'] ?? 'Insufficient stock for return.'];
    }

    $supplierId = null;
    if (isset($header['supplier_id']) && (int)$header['supplier_id'] > 0) {
        $supplierId = (int)$header['supplier_id'];
    }
    $supplierName = trim((string)($header['product'] ?? ''));
    if ($supplierName === '' && $supplierId !== null) {
        $sup = psSupplierById($conn, $supplierId);
        if ($sup) {
            $supplierName = trim((string)$sup['supplier_name']);
        }
    }
    if ($supplierName === '') {
        $supplierName = 'Unknown supplier';
    }
    $poId = isset($header['purchase_order_id']) && (int)$header['purchase_order_id'] > 0
        ? (int)$header['purchase_order_id']
        : null;
    $poNumber = trim((string)($header['po_number'] ?? ''));
    if ($poNumber === '') {
        $poNumber = null;
    }
    $refId = (int)($header['id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    // Multi-currency (2026-10-20): a return is in the goods receipt's currency at the receipt's own rate.
    require_once __DIR__ . '/currency.php';
    if (!psFxEnsureTransactionColumns($conn)) {
        return ['ok' => false, 'error' => 'The purchase return could not be saved.'];
    }
    $grvFxRow = [];
    if ($refId > 0 && psFxHasColumn($conn, 'goods_receipts_ref', 'currency')) {
        $fxRes = mysqli_query($conn, 'SELECT currency, base_currency, fx_rate, fx_rate_ids, fx_path FROM goods_receipts_ref WHERE id = ' . $refId);
        $grvFxRow = ($fxRes && ($r = mysqli_fetch_assoc($fxRes))) ? $r : [];
    }
    $prSnap = psFxStoredSnapshot($conn, $grvFxRow, psFxSessionCompany());
    if ($prSnap['frac'] === null) {
        $prSnap['frac'] = ['1', '1'];
    }
    $prSnap['ids'] = (string)($grvFxRow['fx_rate_ids'] ?? '');
    $prSnap['path'] = (string)($grvFxRow['fx_path'] ?? '');
    if ($prSnap['rate'] === '') {
        $prSnap['rate'] = '1';
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('purchase return transaction did not start');
        return ['ok' => false, 'error' => 'The purchase return could not be saved.'];
    }

    try {
        // Re-check returnable inside transaction.
        $fresh = psPrReturnableLinesForGrv($conn, $grvNumber);
        if (!$fresh['ok']) {
            throw new RuntimeException($fresh['error'] ?? 'Goods receipt not found.');
        }
        $freshById = [];
        foreach ($fresh['lines'] as $fline) {
            $freshById[(int)$fline['id']] = $fline;
        }
        foreach ($lines as $line) {
            $lid = (int)$line['goods_receipt_line_id'];
            if (!isset($freshById[$lid])) {
                throw new RuntimeException('That receiving line is not a valid return source for this goods receipt.');
            }
            if (psStockDecimalCmp($line['quantity_decimal'], (string)$freshById[$lid]['returnable_qty']) > 0) {
                throw new RuntimeException('Return quantity cannot exceed returnable quantity.');
            }
        }
        $stockGuard2 = psPrGuardStockAvailability($conn, $lines, $branch);
        if (!$stockGuard2['ok']) {
            throw new RuntimeException($stockGuard2['error'] ?? 'Insufficient stock for return.');
        }

        $alloc = psAllocatePurchaseReturnNumber($conn);
        if (!$alloc['ok']) {
            throw new RuntimeException($alloc['error'] ?? 'Purchase return numbering is not available.');
        }
        $returnNumber = $alloc['return_number'];

        $total = '0.00';
        foreach ($lines as $line) {
            if (function_exists('bcadd')) {
                $total = bcadd($total, $line['line_total'], 2);
            } else {
                $total = number_format((float)$total + (float)$line['line_total'], 2, '.', '');
            }
        }

        $status = PS_PR_STATUS_COMPLETED;
        $poIdText = $poId !== null ? (string)$poId : '';
        $poNumberText = $poNumber !== null ? $poNumber : '';
        $supplierIdText = $supplierId !== null ? (string)$supplierId : '';
        $refIdText = $refId > 0 ? (string)$refId : '';

        $hdrStmt = mysqli_prepare(
            $conn,
            'INSERT INTO purchase_returns
             (return_number, grv_number, goods_receipt_ref_id, purchase_order_id, po_number,
              supplier_id, supplier_name, branch, return_date, reason, status, total, created_by, created_at)
             VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'),
                     NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$hdrStmt) {
            throw new RuntimeException('header');
        }
        mysqli_stmt_bind_param(
            $hdrStmt,
            'ssssssssssssss',
            $returnNumber,
            $grvNumber,
            $refIdText,
            $poIdText,
            $poNumberText,
            $supplierIdText,
            $supplierName,
            $branch,
            $returnDate,
            $reason,
            $status,
            $total,
            $changedBy,
            $now
        );
        if (!mysqli_stmt_execute($hdrStmt)) {
            mysqli_stmt_close($hdrStmt);
            throw new RuntimeException('header');
        }
        $returnId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($hdrStmt);
        if (psFxHasColumn($conn, 'purchase_returns', 'currency')) {
            $v = psFxSnapshotSqlValues($conn, $prSnap);
            $prBase = psFxCentsToMoney(psFxToBaseCents($conn, $prSnap, (int)psFxMoneyToCents($total)));
            if (!mysqli_query($conn, 'UPDATE purchase_returns SET currency = ' . $v['currency'] . ', base_currency = ' . $v['base_currency']
                . ', fx_rate = ' . $v['fx_rate'] . ', fx_rate_ids = ' . $v['fx_rate_ids'] . ', fx_path = ' . $v['fx_path']
                . ", base_total = '" . $prBase . "' WHERE id = " . $returnId)) {
                throw new RuntimeException('header');
            }
        }

        $lineStmt = mysqli_prepare(
            $conn,
            'INSERT INTO purchase_return_lines
             (purchase_return_id, goods_receipt_line_id, product_id, branch, description,
              quantity, unit_cost, line_total, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$lineStmt) {
            throw new RuntimeException('line');
        }

        $movementIds = [];
        $movementAt = $returnDate . ' ' . date('H:i:s');
        $lineSummary = [];
        foreach ($lines as $line) {
            $grLineId = (int)$line['goods_receipt_line_id'];
            $productId = $line['product_id'];
            $description = $line['description'];
            $qty = $line['quantity'];
            $unitCost = $line['unit_cost'];
            $lineTotal = $line['line_total'];
            mysqli_stmt_bind_param(
                $lineStmt,
                'iisssssss',
                $returnId,
                $grLineId,
                $productId,
                $branch,
                $description,
                $qty,
                $unitCost,
                $lineTotal,
                $now
            );
            if (!mysqli_stmt_execute($lineStmt)) {
                mysqli_stmt_close($lineStmt);
                throw new RuntimeException('line');
            }
            $movQty = psPrMovementQuantity($line['quantity_decimal']);
            if ($movQty === null) {
                throw new RuntimeException('Return quantity must be greater than zero.');
            }
            $recorded = recordStockMovement($conn, [
                'product_id' => $productId,
                'branch' => $branch,
                'movement_type' => 'PURCHASE_RETURN',
                'quantity' => $movQty,
                'reference_type' => 'purchase_return',
                'reference_id' => $returnNumber,
                'movement_at' => $movementAt,
                'notes' => 'GRV ' . $grvNumber,
            ]);
            if (!$recorded['ok']) {
                throw new RuntimeException($recorded['error'] ?? 'Stock movement failed.');
            }
            $movementIds[] = (int)$recorded['id'];
            $lineSummary[] = 'grl:' . $grLineId . ':' . $qty . '@' . $unitCost;
        }
        mysqli_stmt_close($lineStmt);

        // Explicit: do not change PO status (never reopen CLOSED).
        if ($poId !== null) {
            $poRow = psPurchaseOrderById($conn, $poId);
            if ($poRow !== null) {
                // No-op status touch — verify CLOSED stays CLOSED by not updating.
                unset($poRow);
            }
        }

        $auditChanges = [
            ['field_name' => 'return_number', 'old_value' => null, 'new_value' => $returnNumber],
            ['field_name' => 'grv_number', 'old_value' => null, 'new_value' => $grvNumber],
            ['field_name' => 'supplier_name', 'old_value' => null, 'new_value' => $supplierName],
            ['field_name' => 'branch', 'old_value' => null, 'new_value' => $branch],
            ['field_name' => 'return_date', 'old_value' => null, 'new_value' => $returnDate],
            ['field_name' => 'reason', 'old_value' => null, 'new_value' => $reason],
            ['field_name' => 'total', 'old_value' => null, 'new_value' => $total],
            ['field_name' => 'lines', 'old_value' => null, 'new_value' => implode('|', $lineSummary)],
            ['field_name' => 'status', 'old_value' => null, 'new_value' => $status],
        ];
        if ($supplierId !== null) {
            $auditChanges[] = ['field_name' => 'supplier_id', 'old_value' => null, 'new_value' => (string)$supplierId];
        }
        if ($poId !== null) {
            $auditChanges[] = ['field_name' => 'purchase_order_id', 'old_value' => null, 'new_value' => (string)$poId];
            if ($poNumber !== null) {
                $auditChanges[] = ['field_name' => 'po_number', 'old_value' => null, 'new_value' => $poNumber];
            }
        }
        if (!psPrAuditRecordChanges($conn, [
            'purchase_return_id' => $returnId,
            'return_number' => $returnNumber,
            'grv_number' => $grvNumber,
            'branch' => $branch,
            'action' => PS_PR_AUDIT_CREATE,
            'changed_by' => $changedBy,
            'source' => PS_PR_AUDIT_SOURCE_UI,
            'changed_at' => $now,
        ], $auditChanges)) {
            throw new RuntimeException('audit');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('commit');
        }
        return [
            'ok' => true,
            'return_number' => $returnNumber,
            'return_id' => $returnId,
            'movement_ids' => $movementIds,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        $msg = $e->getMessage();
        if ($msg === 'header' || $msg === 'line' || $msg === 'audit' || $msg === 'commit') {
            error_log('purchase return save failed: ' . $msg);
            return ['ok' => false, 'error' => 'The purchase return could not be saved.'];
        }
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'The purchase return could not be saved.'];
    }
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psPurchaseReturnListForSession(mysqli $conn, array $filters = []): array
{
    if (!psEnsurePurchaseReturnAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase returns could not be loaded.', 'rows' => []];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $branch = $branchCheck['branch'];
    $sql = 'SELECT * FROM purchase_returns WHERE branch = ?';
    $types = 's';
    $params = [$branch];
    $from = trim((string)($filters['from_date'] ?? ''));
    $to = trim((string)($filters['to_date'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND return_date >= ?';
        $types .= 's';
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND return_date <= ?';
        $types .= 's';
        $params[] = $to;
    }
    $grv = trim((string)($filters['grv_number'] ?? ''));
    if ($grv !== '') {
        $sql .= ' AND grv_number = ?';
        $types .= 's';
        $params[] = $grv;
    }
    $supplierId = (int)($filters['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        $sql .= ' AND supplier_id = ?';
        $types .= 'i';
        $params[] = $supplierId;
    }
    $sql .= ' ORDER BY id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Purchase returns could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase returns could not be loaded.', 'rows' => []];
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
 * @return array{ok:bool,header?:array<string,mixed>,lines?:array<int,array<string,mixed>>,error?:string}
 */
function psPurchaseReturnLoadForSession(mysqli $conn, int $id): array
{
    if ($id < 1 || !psEnsurePurchaseReturnAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase return not found.'];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.'];
    }
    $branch = $branchCheck['branch'];
    $stmt = mysqli_prepare($conn, 'SELECT * FROM purchase_returns WHERE id = ? AND branch = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Purchase return not found.'];
    }
    mysqli_stmt_bind_param($stmt, 'is', $id, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase return not found.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $header = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$header) {
        return ['ok' => false, 'error' => 'Purchase return not found.'];
    }
    $lines = [];
    $lineStmt = mysqli_prepare(
        $conn,
        'SELECT * FROM purchase_return_lines WHERE purchase_return_id = ? ORDER BY id ASC'
    );
    if ($lineStmt) {
        mysqli_stmt_bind_param($lineStmt, 'i', $id);
        if (mysqli_stmt_execute($lineStmt)) {
            $lr = mysqli_stmt_get_result($lineStmt);
            while ($lr && $row = mysqli_fetch_assoc($lr)) {
                $lines[] = $row;
            }
        }
        mysqli_stmt_close($lineStmt);
    }
    return ['ok' => true, 'header' => $header, 'lines' => $lines];
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psPrAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsurePurchaseReturnAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase return audit could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM purchase_return_audit WHERE ' . psCompanyBranchesInSql($conn, 'branch');
    $types = '';
    $params = [];
    $returnNumber = trim((string)($filters['return_number'] ?? ''));
    if ($returnNumber !== '') {
        $sql .= ' AND return_number = ?';
        $types .= 's';
        $params[] = $returnNumber;
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
        return ['ok' => false, 'error' => 'Purchase return audit could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase return audit could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

function psPrIssueSubmitToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_pr_submit_token'] = $token;
    return $token;
}

function psPrConsumeSubmitToken($posted): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = (string)($_SESSION['ps_pr_submit_token'] ?? '');
    $posted = trim((string)$posted);
    if ($expected === '' || $posted === '' || !hash_equals($expected, $posted)) {
        return false;
    }
    unset($_SESSION['ps_pr_submit_token']);
    return true;
}
