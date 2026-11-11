<?php
/**
 * Phase 4C — Goods Receiving / PO-linked Receiving.
 *
 * Architecture:
 *   Non-PO GRV (legacy free-text supplier) and PO-linked GRV share one path:
 *   grv.php → psSaveGoodsReceipt → goods_receipts + goods_receipts_ref + stock_movements(GOODS_RECEIPT).
 *
 * Received qty decision (single source of truth):
 *   Derive ordered/received/remaining from purchase_order_lines.quantity and SUM of
 *   goods_receipts.quantity for matching purchase_order_line_id. Do NOT store a separate
 *   received_quantity on PO lines (avoids dual authority).
 *
 * Numbering:
 *   Dedicated goods_receipt_seq → GRV-000001. Never sale_invoice_seq / purchase_order_seq / MAX+1.
 *   goods_receipts.invoice_number and goods_receipts_ref.reference1 remain the user supplier invoice field.
 *
 * Stock: GOODS_RECEIPT for actual received qty only. No goods.quantity / goods.cost_price writes.
 */

require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/purchase_order.php';
require_once __DIR__ . '/stock_movement.php';

const PS_GRV_AUDIT_SOURCE_UI = 'UI';
const PS_GRV_AUDIT_CREATE_RECEIPT = 'CREATE_RECEIPT';
const PS_GRV_AUDIT_RECEIVE = 'RECEIVE';
const PS_GRV_AUDIT_CLOSE_PO = 'CLOSE_PO';
const PS_GRV_AUDIT_EDIT = 'EDIT'; // 2026-11-11: a saved receipt corrected (includes/goods_receipt_edit.php)

/**
 * @return bool
 */
function psGrvColumnExists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '``', $table);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

/**
 * @return bool
 */
function psGrvEnsureColumn(mysqli $conn, string $table, string $column, string $definition): bool
{
    if (psGrvColumnExists($conn, $table, $column)) {
        return true;
    }
    $safeTable = str_replace('`', '``', $table);
    $ok = mysqli_query($conn, "ALTER TABLE `{$safeTable}` ADD COLUMN `{$column}` {$definition}");
    if (!$ok) {
        error_log('goods receipt column was not added: ' . $table . '.' . $column);
        return false;
    }
    return true;
}

/**
 * Dedicated GRV counter. Format: GRV-000001.
 */
function psEnsureGoodsReceiptSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS goods_receipt_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('goods_receipt_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM goods_receipt_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO goods_receipt_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('goods_receipt_seq was not seeded');
    return false;
}

/**
 * Extend legacy goods_receipts / goods_receipts_ref for PO refs + GRV number.
 * Historical rows remain readable (new columns nullable).
 */
function psEnsureGoodsReceiptTablesExtended(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM goods_receipts LIMIT 0');
    if (!$check) {
        error_log('goods_receipts table missing');
        return false;
    }
    $checkRef = mysqli_query($conn, 'SELECT 1 FROM goods_receipts_ref LIMIT 0');
    if (!$checkRef) {
        error_log('goods_receipts_ref table missing');
        return false;
    }

    $ok = true;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts', 'grv_number', "VARCHAR(32) NULL DEFAULT NULL") && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts', 'purchase_order_id', 'INT NULL DEFAULT NULL') && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts', 'purchase_order_line_id', 'INT NULL DEFAULT NULL') && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts', 'supplier_id', 'INT NULL DEFAULT NULL') && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts', 'created_by', "VARCHAR(250) NULL DEFAULT NULL") && $ok;

    $ok = psGrvEnsureColumn($conn, 'goods_receipts_ref', 'grv_number', "VARCHAR(32) NULL DEFAULT NULL") && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts_ref', 'purchase_order_id', 'INT NULL DEFAULT NULL') && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts_ref', 'supplier_id', 'INT NULL DEFAULT NULL') && $ok;
    $ok = psGrvEnsureColumn($conn, 'goods_receipts_ref', 'created_by', "VARCHAR(250) NULL DEFAULT NULL") && $ok;

    if ($ok) {
        // Lines share one grv_number — non-unique index only. Uniqueness lives on goods_receipts_ref.
        $badUniq = mysqli_query($conn, "SHOW INDEX FROM goods_receipts WHERE Key_name = 'uniq_goods_receipts_grv_number'");
        if ($badUniq && mysqli_num_rows($badUniq) > 0) {
            @mysqli_query($conn, 'ALTER TABLE goods_receipts DROP INDEX uniq_goods_receipts_grv_number');
        }
        $idxLineGrv = mysqli_query($conn, "SHOW INDEX FROM goods_receipts WHERE Key_name = 'idx_gr_grv_number'");
        if (!$idxLineGrv || mysqli_num_rows($idxLineGrv) === 0) {
            @mysqli_query($conn, 'ALTER TABLE goods_receipts ADD KEY idx_gr_grv_number (grv_number)');
        }
        $idxRef = mysqli_query($conn, "SHOW INDEX FROM goods_receipts_ref WHERE Key_name = 'uniq_goods_receipts_ref_grv_number'");
        if (!$idxRef || mysqli_num_rows($idxRef) === 0) {
            @mysqli_query($conn, 'ALTER TABLE goods_receipts_ref ADD UNIQUE KEY uniq_goods_receipts_ref_grv_number (grv_number)');
        }
        $idxPo = mysqli_query($conn, "SHOW INDEX FROM goods_receipts WHERE Key_name = 'idx_gr_po_line'");
        if (!$idxPo || mysqli_num_rows($idxPo) === 0) {
            @mysqli_query($conn, 'ALTER TABLE goods_receipts ADD KEY idx_gr_po_line (purchase_order_line_id)');
        }
        $idxPoId = mysqli_query($conn, "SHOW INDEX FROM goods_receipts WHERE Key_name = 'idx_gr_po_id'");
        if (!$idxPoId || mysqli_num_rows($idxPoId) === 0) {
            @mysqli_query($conn, 'ALTER TABLE goods_receipts ADD KEY idx_gr_po_id (purchase_order_id)');
        }
    }

    if (!$ok) {
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Append-only receiving audit (not product_audit / supplier_audit / purchase_order_audit alone).
 * CLOSE_PO is also recorded here when receiving closes a PO; purchase_order_audit gets a parallel CLOSE row.
 */
function psEnsureGoodsReceiptAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM goods_receipt_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS goods_receipt_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        goods_receipt_ref_id INT NULL,
        grv_number VARCHAR(32) NOT NULL,
        purchase_order_id INT NULL,
        po_number VARCHAR(32) NULL,
        branch VARCHAR(250) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_grv_audit_grv (grv_number),
        KEY idx_grv_audit_po_id (purchase_order_id),
        KEY idx_grv_audit_branch (branch),
        KEY idx_grv_audit_changed_at (changed_at),
        KEY idx_grv_audit_changed_by (changed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('goods_receipt_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureGoodsReceiptAllSchema(mysqli $conn): bool
{
    return psEnsureGoodsReceiptSeq($conn)
        && psEnsureGoodsReceiptTablesExtended($conn)
        && psEnsureGoodsReceiptAuditSchema($conn)
        && psEnsurePurchaseOrderAllSchema($conn);
}

function psSessionGrvUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psFormatGoodsReceiptNumber(int $n): string
{
    if ($n < 1) {
        return '';
    }
    return 'GRV-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

/**
 * Concurrent-safe GRV allocator. Must be inside an open transaction.
 * Does not touch sale_invoice_seq or purchase_order_seq.
 *
 * @return array{ok:bool,grv_number?:string,error?:string}
 */
function psAllocateGoodsReceiptNumber(mysqli $conn): array
{
    if (!psEnsureGoodsReceiptSeq($conn) || !psEnsureGoodsReceiptTablesExtended($conn)) {
        return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM goods_receipt_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('GRV number allocation failed');
        return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
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
        $grvNumber = psFormatGoodsReceiptNumber($candidate);
        if ($grvNumber === '') {
            continue;
        }
        $stmt = mysqli_prepare($conn, 'SELECT id FROM goods_receipts_ref WHERE grv_number = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
        }
        mysqli_stmt_bind_param($stmt, 's', $grvNumber);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
        }
        $takenRes = mysqli_stmt_get_result($stmt);
        $taken = $takenRes && mysqli_fetch_assoc($takenRes);
        mysqli_stmt_close($stmt);
        if ($taken) {
            continue;
        }
        $next = $candidate + 1;
        $upd = mysqli_prepare($conn, 'UPDATE goods_receipt_seq SET next_number = ? WHERE id = 1');
        if (!$upd) {
            return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
        }
        mysqli_stmt_bind_param($upd, 'i', $next);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
        }
        mysqli_stmt_close($upd);
        return ['ok' => true, 'grv_number' => $grvNumber];
    }
    return ['ok' => false, 'error' => 'Goods receipt numbering is not available.'];
}

/**
 * Sum received qty per PO line from authoritative goods_receipts rows.
 *
 * @return array<int,string> map purchase_order_line_id => qty string
 */
function psGrvReceivedQtyByPoLineIds(mysqli $conn, array $lineIds): array
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
    if ($ids === [] || !psEnsureGoodsReceiptTablesExtended($conn)) {
        return $out;
    }
    if (!psGrvColumnExists($conn, 'goods_receipts', 'purchase_order_line_id')) {
        return $out;
    }
    $list = implode(',', array_map('intval', array_values($ids)));
    $sql = "SELECT purchase_order_line_id AS lid,
                   COALESCE(SUM(CAST(quantity AS DECIMAL(18,3))), 0) AS received_qty
            FROM goods_receipts
            WHERE purchase_order_line_id IN ({$list})
            GROUP BY purchase_order_line_id";
    $res = mysqli_query($conn, $sql);
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $lid = (int)$row['lid'];
        $qty = psStockDecimal((string)$row['received_qty']);
        $out[$lid] = $qty !== null ? $qty : '0.000';
    }
    return $out;
}

/**
 * Enrich PO lines with ordered / received / remaining.
 *
 * @param array<int,array<string,mixed>> $lines
 * @return array<int,array<string,mixed>>
 */
function psPoLinesWithReceived(mysqli $conn, array $lines): array
{
    $ids = [];
    foreach ($lines as $line) {
        $ids[] = (int)($line['id'] ?? 0);
    }
    $receivedMap = psGrvReceivedQtyByPoLineIds($conn, $ids);
    $enriched = [];
    foreach ($lines as $line) {
        $lid = (int)($line['id'] ?? 0);
        $ordered = psStockDecimal((string)($line['quantity'] ?? '0'));
        if ($ordered === null) {
            $ordered = '0.000';
        }
        $received = $receivedMap[$lid] ?? '0.000';
        $remaining = '0.000';
        if (function_exists('bcsub')) {
            $remaining = bcsub($ordered, $received, 3);
            if (bccomp($remaining, '0', 3) < 0) {
                $remaining = '0.000';
            }
        } else {
            $remaining = number_format(max(0, (float)$ordered - (float)$received), 3, '.', '');
        }
        $line['ordered_qty'] = $ordered;
        $line['received_qty'] = $received;
        $line['remaining_qty'] = $remaining;
        $enriched[] = $line;
    }
    return $enriched;
}

/**
 * True when every PO line has remaining <= 0.
 *
 * @param array<int,array<string,mixed>> $enrichedLines
 */
function psPoLinesFullyReceived(array $enrichedLines): bool
{
    if ($enrichedLines === []) {
        return false;
    }
    foreach ($enrichedLines as $line) {
        $remaining = (string)($line['remaining_qty'] ?? '0');
        if (function_exists('bccomp')) {
            if (bccomp($remaining, '0', 3) > 0) {
                return false;
            }
        } elseif ((float)$remaining > 0) {
            return false;
        }
    }
    return true;
}

function psPoCanReceive(string $status): bool
{
    $check = psNormalizePoStatus($status);
    return $check['ok'] && $check['value'] === PS_PO_STATUS_APPROVED;
}

/**
 * APPROVED POs on session branch that still have remaining qty.
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psApprovedPurchaseOrdersForReceiving(mysqli $conn): array
{
    if (!psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase orders could not be loaded.', 'rows' => []];
    }
    $list = psPurchaseOrderList($conn, ['status' => 'approved']);
    if (!$list['ok']) {
        return $list;
    }
    $rows = [];
    foreach ($list['rows'] as $header) {
        $lines = psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, (int)$header['id']));
        if (!psPoLinesFullyReceived($lines)) {
            $header['lines'] = $lines;
            $rows[] = $header;
        }
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psGrvAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    if (!psEnsureGoodsReceiptAuditSchema($conn)) {
        return false;
    }
    $refId = array_key_exists('goods_receipt_ref_id', $meta) && $meta['goods_receipt_ref_id'] !== null
        ? (int)$meta['goods_receipt_ref_id']
        : null;
    $grvNumber = (string)($meta['grv_number'] ?? '');
    $poId = array_key_exists('purchase_order_id', $meta) && $meta['purchase_order_id'] !== null && (int)$meta['purchase_order_id'] > 0
        ? (int)$meta['purchase_order_id']
        : null;
    $poNumber = array_key_exists('po_number', $meta) ? (string)$meta['po_number'] : null;
    if ($poNumber === '') {
        $poNumber = null;
    }
    $branch = (string)($meta['branch'] ?? '');
    $action = (string)($meta['action'] ?? '');
    $changedBy = (string)($meta['changed_by'] ?? '');
    $source = (string)($meta['source'] ?? PS_GRV_AUDIT_SOURCE_UI);
    $changedAt = (string)($meta['changed_at'] ?? date('Y-m-d H:i:s'));
    if ($grvNumber === '' || $branch === '' || $action === '' || $changedBy === '') {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO goods_receipt_audit
         (goods_receipt_ref_id, grv_number, purchase_order_id, po_number, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('GRV audit prepare failed');
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
        $refIdText = $refId === null || $refId < 1 ? '' : (string)$refId;
        $poIdText = $poId === null || $poId < 1 ? '' : (string)$poId;
        $poNumberText = $poNumber === null ? '' : (string)$poNumber;
        mysqli_stmt_bind_param(
            $stmt,
            'ssssssssssss',
            $refIdText,
            $grvNumber,
            $poIdText,
            $poNumberText,
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
            error_log('GRV audit insert failed');
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

/**
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psGrvAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureGoodsReceiptAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Goods receipt audit could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM goods_receipt_audit WHERE ' . psCompanyBranchesInSql($conn, 'branch');
    $types = '';
    $params = [];
    $grv = trim((string)($filters['grv_number'] ?? ''));
    if ($grv !== '') {
        $sql .= ' AND grv_number = ?';
        $types .= 's';
        $params[] = $grv;
    }
    $poId = (int)($filters['purchase_order_id'] ?? 0);
    if ($poId > 0) {
        $sql .= ' AND purchase_order_id = ?';
        $types .= 'i';
        $params[] = $poId;
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
        return ['ok' => false, 'error' => 'Goods receipt audit could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Goods receipt audit could not be loaded.', 'rows' => []];
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
 * Branch-scoped GRV listing for goods_receipt_ref.php.
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psGoodsReceiptListForSession(mysqli $conn, array $filters = []): array
{
    if (!psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Goods receipts could not be loaded.', 'rows' => []];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        return ['ok' => false, 'error' => $branchCheck['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $branch = $branchCheck['branch'];
    $sql = 'SELECT r.*, po.po_number
            FROM goods_receipts_ref r
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            WHERE r.branch = ?';
    $types = 's';
    $params = [$branch];
    $date = trim((string)($filters['date'] ?? ''));
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $sql .= ' AND r.date_ = ?';
        $types .= 's';
        $params[] = $date;
    }
    $supplier = trim((string)($filters['supplier'] ?? ''));
    if ($supplier !== '') {
        $like = '%' . $supplier . '%';
        $sql .= ' AND r.product LIKE ?';
        $types .= 's';
        $params[] = $like;
    }
    $invoice = trim((string)($filters['invoice_number'] ?? ''));
    if ($invoice !== '') {
        $like = '%' . $invoice . '%';
        $sql .= ' AND (r.reference1 LIKE ? OR r.grv_number LIKE ?)';
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Goods receipts could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Goods receipts could not be loaded.', 'rows' => []];
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
 * Close PO only when server calculates all lines fully received. Never from browser status POST.
 * Must run inside the receiving transaction.
 *
 * @return array{ok:bool,closed?:bool,error?:string}
 */
function psClosePurchaseOrderIfFullyReceived(mysqli $conn, int $poId, string $changedBy, string $now, string $grvNumber): array
{
    $header = psPurchaseOrderById($conn, $poId);
    if ($header === null) {
        return ['ok' => false, 'error' => 'Purchase order not found.'];
    }
    $status = strtoupper(trim((string)$header['status']));
    if ($status !== PS_PO_STATUS_APPROVED) {
        return ['ok' => true, 'closed' => false];
    }
    $branch = trim((string)$header['branch']);
    $lines = psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, $poId));
    if (!psPoLinesFullyReceived($lines)) {
        return ['ok' => true, 'closed' => false];
    }
    $closed = PS_PO_STATUS_CLOSED;
    $approved = PS_PO_STATUS_APPROVED;
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE purchase_orders SET status = ?, updated_by = ?, updated_at = ?
         WHERE id = ? AND branch = ? AND status = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The purchase order could not be closed.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssiss', $closed, $changedBy, $now, $poId, $branch, $approved);
    if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The purchase order could not be closed.'];
    }
    mysqli_stmt_close($stmt);

    if (!psPoAuditRecordChanges($conn, [
        'purchase_order_id' => $poId,
        'po_number' => (string)$header['po_number'],
        'branch' => $branch,
        'action' => PS_PO_AUDIT_ACTION_CLOSE,
        'changed_by' => $changedBy,
        'source' => PS_PO_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $approved,
        'new_value' => $closed,
    ]])) {
        return ['ok' => false, 'error' => 'The purchase order could not be closed.'];
    }

    if (!psGrvAuditRecordChanges($conn, [
        'goods_receipt_ref_id' => null,
        'grv_number' => $grvNumber,
        'purchase_order_id' => $poId,
        'po_number' => (string)$header['po_number'],
        'branch' => $branch,
        'action' => PS_GRV_AUDIT_CLOSE_PO,
        'changed_by' => $changedBy,
        'source' => PS_GRV_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [[
        'field_name' => 'status',
        'old_value' => $approved,
        'new_value' => $closed,
    ]])) {
        return ['ok' => false, 'error' => 'The purchase order could not be closed.'];
    }

    return ['ok' => true, 'closed' => true];
}

/**
 * Simple duplicate-submit guard token (session). Not app-wide CSRF.
 */
function psGrvIssueSubmitToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_grv_submit_token'] = $token;
    return $token;
}

function psGrvConsumeSubmitToken($posted): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = (string)($_SESSION['ps_grv_submit_token'] ?? '');
    $posted = trim((string)$posted);
    if ($expected === '' || $posted === '' || !hash_equals($expected, $posted)) {
        return false;
    }
    unset($_SESSION['ps_grv_submit_token']);
    return true;
}
