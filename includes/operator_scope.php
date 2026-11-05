<?php
/**
 * 2026-10-24 — Till operator record scope (server-side).
 *
 * A Till or Supervisor user may only see records that belong to them:
 *   - sales they processed (online_sales_ref.cashier = their sign-in),
 *   - cash movements, reconciliations and closures on till sessions they own,
 *   - refund requests they raised, and refund lookups on their own sales.
 * Records with no reliable owner (blank cashier, unknown till session) are never shown.
 * Management and admin keep their existing (branch-scoped / admin all-branch) access.
 *
 * Receipt history for Till and Supervisor is the PS_OPERATOR_TILL_RECEIPT_LIMIT most recent own
 * receipts; older receipts cannot be opened or reprinted by changing the invoice number in the address.
 * Till reprints still need a Supervisor / Management / admin to approve (includes/receipt_reprint.php).
 */

require_once __DIR__ . '/cash_controls.php';
require_once __DIR__ . '/till_session.php';

if (!defined('PS_OPERATOR_TILL_RECEIPT_LIMIT')) {
    define('PS_OPERATOR_TILL_RECEIPT_LIMIT', 5);
}

/** True for a plain cashier (anything below Supervisor; unknown levels fail closed as Till). */
function psOperatorIsTill(): bool
{
    return psCashControlsIsTillUser();
}

function psOperatorIsSupervisor(): bool
{
    return psCashControlsLevel() === 'supervisor';
}

/** Till and Supervisor: own sales / cash / refunds / receipts only. */
/**
 * Sees only their own receipts / quotations (not everyone's sales).
 * 2026-11-02: company role permission "See sales" (includes/role_permissions.php); admin never scoped.
 * Defaults: Till, Supervisor and Management all see only their own.
 */
function psOperatorIsScoped(): bool
{
    require_once __DIR__ . '/role_permissions.php';
    return !psRolePermSession('sales_view');
}

/**
 * Sees only their own till sessions / cash (till close, cash reconciliation, cash movements).
 * 2026-11-02: company role permission "Cash reports"; default as before — Till and Supervisor own only, Management all.
 */
function psOperatorCashScoped(): bool
{
    require_once __DIR__ . '/role_permissions.php';
    return !psRolePermSession('cash_reports');
}

function psOperatorUser(): string
{
    return psCashControlsUser();
}

/** Sign-in names for this operator: session logger and users.email. */
function psOperatorIdentities(mysqli $conn): array
{
    $me = trim(psOperatorUser());
    $email = trim((string)(tillCurrentCashier($conn)['email'] ?? ''));
    return array_values(array_unique(array_filter([$me, $email], static fn($v) => $v !== '')));
}

/**
 * Exact match: one of $columns is this operator (case-insensitive). No session_name prefix.
 *
 * @param list<string> $columns
 * @return array{sql:string,types:string,params:list<string>}
 */
function psOperatorPostedByCondition(mysqli $conn, array $columns): array
{
    $who = psOperatorIdentities($conn);
    if ($who === [] || $columns === []) {
        return ['sql' => '0', 'types' => '', 'params' => []];
    }
    $ors = [];
    $params = [];
    $types = '';
    foreach ($columns as $col) {
        $ors[] = 'LOWER(TRIM(' . $col . ')) IN (' . implode(',', array_fill(0, count($who), 'LOWER(?)')) . ')';
        foreach ($who as $w) {
            $params[] = $w;
            $types .= 's';
        }
    }
    return ['sql' => '(' . implode(' OR ', $ors) . ')', 'types' => $types, 'params' => $params];
}

/** Inlined posted-by match (escaped identities). Use when the query cannot take extra bind params. */
function psOperatorPostedBySql(mysqli $conn, array $columns): string
{
    $who = psOperatorIdentities($conn);
    if ($who === [] || $columns === []) {
        return '0';
    }
    $ors = [];
    foreach ($columns as $col) {
        $ins = [];
        foreach ($who as $w) {
            $ins[] = "LOWER('" . mysqli_real_escape_string($conn, $w) . "')";
        }
        $ors[] = 'LOWER(TRIM(' . $col . ')) IN (' . implode(',', $ins) . ')';
    }
    return '(' . implode(' OR ', $ors) . ')';
}

/** Does this sale header belong to the signed-in operator? Blank cashier never matches. */
function psOperatorOwnsSale(array $saleHeader): bool
{
    $me = psOperatorUser();
    $cashier = trim((string)($saleHeader['cashier'] ?? ''));
    return $me !== '' && $cashier !== '' && strcasecmp($cashier, $me) === 0;
}

/**
 * Exact owner of a till session (lists / history). Not tillCashierMatchSql: that
 * prefix-matches session_name and pulls in other cashiers on the same counter.
 */
function psOperatorSessionOwnerSql(mysqli $conn): string
{
    $cashier = tillCurrentCashier($conn);
    $parts = [];
    if (($cashier['id'] ?? 0) > 0) {
        $parts[] = 's.cashier_id = ' . (int)$cashier['id'];
    }
    foreach (psOperatorIdentities($conn) as $w) {
        $esc = mysqli_real_escape_string($conn, $w);
        $parts[] = "LOWER(TRIM(s.cashier_email)) = LOWER('$esc')";
        $parts[] = "LOWER(TRIM(s.session_name)) = LOWER('$esc')";
        $parts[] = "LOWER(TRIM(IFNULL(s.closed_by,''))) = LOWER('$esc')";
    }
    return $parts === [] ? '0' : '(' . implode(' OR ', $parts) . ')';
}

/** SQL: "$column is a till session this operator opened or closed". */
function psOperatorOwnSessionCondition(mysqli $conn, string $column): string
{
    $match = psOperatorSessionOwnerSql($conn);
    if ($match === '0') {
        return '0';
    }
    return $column . ' IN (SELECT s.id FROM sessions s WHERE ' . $match . ')';
}

/** Does the signed-in operator own this till session id? */
function psOperatorOwnsSession(mysqli $conn, int $sessionId): bool
{
    if ($sessionId < 1) {
        return false;
    }
    $match = psOperatorSessionOwnerSql($conn);
    if ($match === '0') {
        return false;
    }
    $res = mysqli_query($conn, 'SELECT s.id FROM sessions s WHERE s.id = ' . $sessionId . ' AND ' . $match . ' LIMIT 1');
    return $res && mysqli_num_rows($res) === 1;
}

/**
 * The operator's most recent own receipts (newest first), limited in SQL.
 * Till / Supervisor: PS_OPERATOR_TILL_RECEIPT_LIMIT, own sales only. Others: branch list, $otherLimit rows.
 *
 * @return list<array<string,mixed>>
 */
function psOperatorReceiptHistory(mysqli $conn, string $branch, array $filters = [], int $otherLimit = 200): array
{
    $till = psOperatorIsScoped();
    $where = ['branch = ?'];
    $types = 's';
    $params = [$branch];
    if ($till) {
        $me = psOperatorUser();
        if ($me === '') {
            return [];
        }
        $where[] = 'cashier = ?';
        $types .= 's';
        $params[] = $me;
    }
    $customer = trim((string)($filters['customer'] ?? ''));
    if ($customer !== '') {
        $where[] = 'customer LIKE ?';
        $types .= 's';
        $params[] = '%' . addcslashes($customer, '%_\\') . '%';
    }
    $date = trim((string)($filters['date'] ?? ''));
    if ($date !== '') {
        $where[] = 'date_ LIKE ?';
        $types .= 's';
        $params[] = '%' . addcslashes($date, '%_\\') . '%';
    }
    $limit = $till ? PS_OPERATOR_TILL_RECEIPT_LIMIT : max(1, min(500, $otherLimit));
    // Till: search only within the operator's latest N receipts, so a filter can never reach older ones.
    if ($till) {
        $sql = 'SELECT * FROM (SELECT * FROM online_sales_ref WHERE branch = ? AND cashier = ?
                    ORDER BY id DESC LIMIT ' . PS_OPERATOR_TILL_RECEIPT_LIMIT . ') recent
                WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit;
        $types = 'ss' . $types;
        array_unshift($params, $branch, psOperatorUser());
    } else {
        $sql = 'SELECT * FROM online_sales_ref WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit;
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('operator_scope: receipt history prepare failed');
        return [];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * May the signed-in user open / reprint this receipt? Branch ACL is checked by the caller
 * (psBranchSaleBranchAuthorised). Till: own sale AND among their latest N receipts on that branch.
 */
function psOperatorMayOpenReceipt(mysqli $conn, array $saleHeader): bool
{
    if (!psOperatorIsScoped()) {
        return true;
    }
    if (!psOperatorOwnsSale($saleHeader)) {
        return false;
    }
    $branch = trim((string)($saleHeader['branch'] ?? ''));
    $id = (int)($saleHeader['id'] ?? 0);
    if ($id < 1) {
        return false;
    }
    foreach (psOperatorReceiptHistory($conn, $branch) as $r) {
        if ((int)$r['id'] === $id) {
            return true;
        }
    }
    return false;
}

/** Refund request visible / actionable for the signed-in user (Supervisor+ keep branch rules elsewhere). */
function psOperatorMayAccessRefundRequest(array $request): bool
{
    if (!psOperatorIsScoped()) {
        return true;
    }
    $me = psOperatorUser();
    return $me !== '' && strcasecmp(trim((string)($request['requester'] ?? '')), $me) === 0;
}

/** Load the sale header for an invoice (no ACL). */
function psOperatorSaleHeader(mysqli $conn, string $invoiceNumber): ?array
{
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '' || strlen($invoiceNumber) > 250) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM online_sales_ref WHERE invoice_number = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** Till users may only look up / request refunds on sales they processed. */
function psOperatorMayRefundSale(mysqli $conn, string $invoiceNumber): bool
{
    if (!psOperatorIsScoped()) {
        return true;
    }
    $hdr = psOperatorSaleHeader($conn, $invoiceNumber);
    return $hdr !== null && psOperatorOwnsSale($hdr);
}
