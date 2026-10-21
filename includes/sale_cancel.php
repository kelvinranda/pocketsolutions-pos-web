<?php
/**
 * Phase 8.4 — Voids and sale cancellation.
 *
 * Distinguishes:
 *   DISCARD  — uncommitted POS cart (browser-only; never wrote sale/stock/cash/AR).
 *   CANCEL   — persisted header that never affected payments/stock/cash/AR/refunds
 *              (mark CANCELLED; retain history; no ledger movements).
 *   REJECT   — completed / paid / ledger-affected sales: direct void refused;
 *              use Phase 8.1–8.3 authorised refund workflow (do not bypass).
 *
 * Never deletes sales/lines/payments/stock/cash/audit.
 * Never invents VOID stock/cash movement types.
 * Never updates goods.quantity. Never reopens closed tills / alters completed recons.
 * Never resets refund sequences or deletes refund requests.
 */

require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/sale_refund.php';

const PS_SALE_STATUS_COMPLETED = 'COMPLETED';
const PS_SALE_STATUS_CANCELLED = 'CANCELLED';
const PS_SALE_STATUS_REFUNDED = 'refunded';

const PS_CANCEL_ACTION_DISCARD_CART = 'DISCARD_CART';
const PS_CANCEL_ACTION_CANCEL_UNCOMPLETED = 'CANCEL_UNCOMPLETED';
const PS_CANCEL_ACTION_REJECT_COMMITTED = 'REJECT_COMMITTED';

const PS_CANCEL_CLASS_CART = 'CART';
const PS_CANCEL_CLASS_UNCOMPLETED = 'UNCOMPLETED';
const PS_CANCEL_CLASS_COMMITTED = 'COMMITTED';
const PS_CANCEL_CLASS_CANCELLED = 'ALREADY_CANCELLED';
const PS_CANCEL_CLASS_REFUNDED = 'REFUNDED';
const PS_CANCEL_CLASS_REFUND_PENDING = 'REFUND_PENDING';
const PS_CANCEL_CLASS_PARTIAL_REFUND = 'PARTIAL_REFUND';
const PS_CANCEL_CLASS_MISSING = 'MISSING';
const PS_CANCEL_CLASS_UNSAFE = 'UNSAFE';

const PS_CANCEL_REASON_WRONG_ITEMS = 'WRONG_ITEMS';
const PS_CANCEL_REASON_CUSTOMER_LEFT = 'CUSTOMER_LEFT';
const PS_CANCEL_REASON_PAYMENT_ABORTED = 'PAYMENT_ABORTED';
const PS_CANCEL_REASON_DUPLICATE_ENTRY = 'DUPLICATE_ENTRY';
const PS_CANCEL_REASON_TEST_TRAINING = 'TEST_TRAINING';
const PS_CANCEL_REASON_OTHER = 'OTHER';

const PS_CANCEL_AUDIT_SOURCE_UI = 'UI';
const PS_CANCEL_AUDIT_SOURCE_CLI = 'CLI';

/**
 * @return list<string>
 */
function psSaleCancelControlledReasons(): array
{
    return [
        PS_CANCEL_REASON_WRONG_ITEMS,
        PS_CANCEL_REASON_CUSTOMER_LEFT,
        PS_CANCEL_REASON_PAYMENT_ABORTED,
        PS_CANCEL_REASON_DUPLICATE_ENTRY,
        PS_CANCEL_REASON_TEST_TRAINING,
        PS_CANCEL_REASON_OTHER,
    ];
}

function psSaleCancelReasonLabel(string $reason): string
{
    $labels = [
        PS_CANCEL_REASON_WRONG_ITEMS => 'Wrong items in cart/order',
        PS_CANCEL_REASON_CUSTOMER_LEFT => 'Customer left',
        PS_CANCEL_REASON_PAYMENT_ABORTED => 'Payment aborted',
        PS_CANCEL_REASON_DUPLICATE_ENTRY => 'Duplicate entry',
        PS_CANCEL_REASON_TEST_TRAINING => 'Test / training',
        PS_CANCEL_REASON_OTHER => 'Other',
    ];
    $key = strtoupper(trim($reason));
    return $labels[$key] ?? $reason;
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeSaleCancelReason($raw): array
{
    $reason = strtoupper(trim((string)$raw));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A cancellation reason is required.'];
    }
    if (!in_array($reason, psSaleCancelControlledReasons(), true)) {
        return ['ok' => false, 'error' => 'Choose a valid cancellation reason.'];
    }
    return ['ok' => true, 'value' => $reason];
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateSaleCancelReasonDescription($raw, string $reasonCode): array
{
    $description = trim((string)$raw);
    if (strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long.'];
    }
    $reasonCode = strtoupper(trim($reasonCode));
    if ($reasonCode === PS_CANCEL_REASON_OTHER) {
        if ($description === '') {
            return ['ok' => false, 'error' => 'A description is required when the reason is Other.'];
        }
        if (preg_match('/^\.+$/', $description) || preg_match('/^-{2,}$/', $description)) {
            return ['ok' => false, 'error' => 'Enter a meaningful description for Other.'];
        }
        if (strlen($description) < 3) {
            return ['ok' => false, 'error' => 'Enter a meaningful description for Other.'];
        }
    }
    return ['ok' => true, 'value' => $description];
}

function psSessionSaleCancelUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

/**
 * Cancellation permission reuses session logger (same gate as refund create / POS).
 */
function psSaleCancelMayAct(): bool
{
    if (function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin()) {
        return false; // Phase 18: provider staff never cancel a client's sales.
    }
    return psSessionSaleCancelUser() !== '';
}

function psSaleCancelIssueSubmitToken(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_sale_cancel_submit_token'] = $token;
    return $token;
}

function psSaleCancelConsumeSubmitToken($posted): bool
{
    $posted = trim((string)$posted);
    $known = trim((string)($_SESSION['ps_sale_cancel_submit_token'] ?? ''));
    if ($posted === '' || $known === '' || !hash_equals($known, $posted)) {
        return false;
    }
    unset($_SESSION['ps_sale_cancel_submit_token']);
    return true;
}

/**
 * Explicit: committed paid/ledger sales are not direct-voided (use refund workflow).
 */
function psSaleCancelDirectVoidCommittedEnabled(): bool
{
    return false;
}

function psEnsureSaleCancelAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS sale_cancel_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(32) NOT NULL,
        invoice_number VARCHAR(250) NOT NULL DEFAULT '',
        branch VARCHAR(250) NOT NULL DEFAULT '',
        previous_status VARCHAR(250) NOT NULL DEFAULT '',
        resulting_status VARCHAR(250) NOT NULL DEFAULT '',
        reason VARCHAR(64) NOT NULL,
        reason_description VARCHAR(500) NOT NULL DEFAULT '',
        related_refund_ref VARCHAR(64) NOT NULL DEFAULT '',
        created_by VARCHAR(250) NOT NULL,
        created_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'UI',
        KEY idx_sale_cancel_invoice (invoice_number),
        KEY idx_sale_cancel_branch (branch),
        KEY idx_sale_cancel_created_at (created_at),
        KEY idx_sale_cancel_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale_cancel_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureSaleCancelModule(mysqli $conn): bool
{
    return psEnsureSaleCancelAuditSchema($conn);
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function psSaleCancelAuditInsert(mysqli $conn, array $data): array
{
    if (!psEnsureSaleCancelAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'The cancellation could not be audited.'];
    }
    $action = trim((string)($data['action'] ?? ''));
    $invoice = trim((string)($data['invoice_number'] ?? ''));
    $branch = trim((string)($data['branch'] ?? ''));
    $prev = trim((string)($data['previous_status'] ?? ''));
    $next = trim((string)($data['resulting_status'] ?? ''));
    $reason = trim((string)($data['reason'] ?? ''));
    $desc = trim((string)($data['reason_description'] ?? ''));
    $related = trim((string)($data['related_refund_ref'] ?? ''));
    $by = trim((string)($data['created_by'] ?? ''));
    $at = trim((string)($data['created_at'] ?? date('Y-m-d H:i:s')));
    $source = trim((string)($data['source'] ?? PS_CANCEL_AUDIT_SOURCE_UI));
    if ($action === '' || $reason === '' || $by === '') {
        return ['ok' => false, 'error' => 'The cancellation could not be audited.'];
    }
    if ($source !== PS_CANCEL_AUDIT_SOURCE_UI && $source !== PS_CANCEL_AUDIT_SOURCE_CLI) {
        $source = PS_CANCEL_AUDIT_SOURCE_UI;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO sale_cancel_audit
         (action, invoice_number, branch, previous_status, resulting_status, reason, reason_description,
          related_refund_ref, created_by, created_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The cancellation could not be audited.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssss',
        $action,
        $invoice,
        $branch,
        $prev,
        $next,
        $reason,
        $desc,
        $related,
        $by,
        $at,
        $source
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The cancellation could not be audited.'];
    }
    $id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $id];
}

/**
 * Count ledger / payment / refund effects for an invoice (server authority).
 *
 * @return array{ok:bool,error?:string,payments?:int,stock_sale?:int,cash_sale?:int,credit_sale?:int,refund_requests?:int,refund_processed?:int}
 */
function psSaleCancelLedgerFootprint(mysqli $conn, string $invoiceNumber): array
{
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return ['ok' => false, 'error' => 'Invoice was not found.'];
    }

    $payments = 0;
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM sale_payments WHERE invoice_number = ?');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The sale could not be checked.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The sale could not be checked.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $payments = (int)($row['c'] ?? 0);

    $stockSale = 0;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS c FROM stock_movements
         WHERE reference_type = 'SALE' AND reference_id = ? AND movement_type = 'SALE'"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The sale could not be checked.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The sale could not be checked.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $stockSale = (int)($row['c'] ?? 0);

    $cashSale = 0;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS c FROM cash_movements
         WHERE reference_type = 'SALE' AND reference_id = ? AND movement_type = 'CASH_SALE'"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $cashSale = (int)($row['c'] ?? 0);
        }
        mysqli_stmt_close($stmt);
    }

    $creditSale = 0;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS c FROM customer_account_ledger
         WHERE reference_type = 'SALE' AND reference_id = ? AND entry_type = 'CREDIT_SALE'"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $creditSale = (int)($row['c'] ?? 0);
        }
        mysqli_stmt_close($stmt);
    }

    $refundRequests = 0;
    $refundProcessed = 0;
    if (psEnsureSaleRefundRequestsSchema($conn)) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT status, COUNT(*) AS c FROM sale_refund_requests WHERE invoice_number = ? GROUP BY status'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($rr = mysqli_fetch_assoc($res))) {
                    $st = strtoupper(trim((string)($rr['status'] ?? '')));
                    $c = (int)($rr['c'] ?? 0);
                    $refundRequests += $c;
                    if ($st === PS_REFUND_STATUS_PROCESSED) {
                        $refundProcessed += $c;
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    return [
        'ok' => true,
        'payments' => $payments,
        'stock_sale' => $stockSale,
        'cash_sale' => $cashSale,
        'credit_sale' => $creditSale,
        'refund_requests' => $refundRequests,
        'refund_processed' => $refundProcessed,
    ];
}

/**
 * Classify a persisted sale for cancellation eligibility.
 *
 * @return array{
 *   ok:bool,
 *   class?:string,
 *   can_cancel?:bool,
 *   must_use_refund?:bool,
 *   message?:string,
 *   error?:string,
 *   header?:array,
 *   previous_status?:string,
 *   footprint?:array
 * }
 */
function psSaleCancelClassify(mysqli $conn, $invoiceNumber, bool $forUpdate = false): array
{
    $invoiceNumber = trim((string)$invoiceNumber);
    if ($invoiceNumber === '' || strlen($invoiceNumber) > 250) {
        return ['ok' => false, 'error' => 'The receipt was not found.', 'class' => PS_CANCEL_CLASS_MISSING];
    }

    $sql = 'SELECT id, invoice_number, status, branch, totalz, cashier, customer, till_session_id, sold_at
            FROM online_sales_ref WHERE invoice_number = ?';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The sale could not be checked.', 'class' => PS_CANCEL_CLASS_UNSAFE];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The sale could not be checked.', 'class' => PS_CANCEL_CLASS_UNSAFE];
    }
    $res = mysqli_stmt_get_result($stmt);
    $header = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$header) {
        return [
            'ok' => true,
            'class' => PS_CANCEL_CLASS_MISSING,
            'can_cancel' => false,
            'must_use_refund' => false,
            'message' => 'The receipt was not found.',
        ];
    }

    $statusRaw = (string)($header['status'] ?? '');
    $status = strtoupper(trim($statusRaw));
    $statusLower = strtolower(trim($statusRaw));

    if ($status === PS_SALE_STATUS_CANCELLED) {
        return [
            'ok' => true,
            'class' => PS_CANCEL_CLASS_CANCELLED,
            'can_cancel' => false,
            'must_use_refund' => false,
            'message' => 'This sale is already cancelled.',
            'header' => $header,
            'previous_status' => $statusRaw,
        ];
    }

    if ($statusLower === PS_SALE_STATUS_REFUNDED || $status === 'REFUNDED') {
        return [
            'ok' => true,
            'class' => PS_CANCEL_CLASS_REFUNDED,
            'can_cancel' => false,
            'must_use_refund' => false,
            'message' => 'This sale is already refunded. Direct cancellation is not allowed.',
            'header' => $header,
            'previous_status' => $statusRaw,
        ];
    }

    $foot = psSaleCancelLedgerFootprint($conn, $invoiceNumber);
    if (empty($foot['ok'])) {
        return [
            'ok' => false,
            'error' => $foot['error'] ?? 'The sale could not be checked.',
            'class' => PS_CANCEL_CLASS_UNSAFE,
            'header' => $header,
            'previous_status' => $statusRaw,
        ];
    }

    $hasPendingRefund = false;
    $hasPartialOrAnyRefund = ((int)$foot['refund_requests'] > 0);
    if ($hasPartialOrAnyRefund && psEnsureSaleRefundRequestsSchema($conn)) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id FROM sale_refund_requests
             WHERE invoice_number = ? AND status IN ('REQUESTED','APPROVED')
             LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                $hasPendingRefund = (bool)($res && mysqli_fetch_assoc($res));
            }
            mysqli_stmt_close($stmt);
        }
    }

    if ($hasPendingRefund) {
        return [
            'ok' => true,
            'class' => PS_CANCEL_CLASS_REFUND_PENDING,
            'can_cancel' => false,
            'must_use_refund' => true,
            'message' => 'A refund request is awaiting approval or processing. Direct cancellation is not allowed — continue the authorised refund workflow.',
            'header' => $header,
            'previous_status' => $statusRaw,
            'footprint' => $foot,
        ];
    }

    if ((int)$foot['refund_processed'] > 0) {
        return [
            'ok' => true,
            'class' => PS_CANCEL_CLASS_PARTIAL_REFUND,
            'can_cancel' => false,
            'must_use_refund' => true,
            'message' => 'This sale has refund activity. Direct cancellation is not allowed — use the authorised refund workflow for remaining quantities.',
            'header' => $header,
            'previous_status' => $statusRaw,
            'footprint' => $foot,
        ];
    }

    $hasLedgerEffects = ((int)$foot['payments'] > 0)
        || ((int)$foot['stock_sale'] > 0)
        || ((int)$foot['cash_sale'] > 0)
        || ((int)$foot['credit_sale'] > 0);

    // Production committed marker OR any payment/stock/cash/AR effect ⇒ not a draft.
    if ($status === PS_SALE_STATUS_COMPLETED || $hasLedgerEffects) {
        return [
            'ok' => true,
            'class' => PS_CANCEL_CLASS_COMMITTED,
            'can_cancel' => false,
            'must_use_refund' => true,
            'message' => 'This sale is completed or has ledger effects. Direct void is not allowed. Use the authorised refund request → approve → process workflow.',
            'header' => $header,
            'previous_status' => $statusRaw,
            'footprint' => $foot,
        ];
    }

    // Empty / non-COMPLETED status with zero ledger footprint: safe uncompleted cancel.
    return [
        'ok' => true,
        'class' => PS_CANCEL_CLASS_UNCOMPLETED,
        'can_cancel' => true,
        'must_use_refund' => false,
        'message' => 'This persisted sale never posted payments, stock, cash or customer-account ledger entries and may be cancelled (history retained).',
        'header' => $header,
        'previous_status' => $statusRaw,
        'footprint' => $foot,
    ];
}

/**
 * Discard uncommitted POS cart (no DB sale). Audits DISCARD_CART only.
 *
 * @return array{ok:bool,error?:string,action?:string,audit_id?:int}
 */
function psSaleCancelDiscardCart(mysqli $conn, array $request): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleCancelModule($conn)) {
        return ['ok' => false, 'error' => 'The cart could not be discarded.'];
    }
    if (!psSaleCancelMayAct()) {
        return ['ok' => false, 'error' => 'Please sign in before discarding a cart.'];
    }
    $actor = psSessionSaleCancelUser();

    $reasonCheck = psNormalizeSaleCancelReason($request['reason'] ?? '');
    if (empty($reasonCheck['ok'])) {
        return ['ok' => false, 'error' => $reasonCheck['error'] ?? 'A cancellation reason is required.'];
    }
    $reason = (string)$reasonCheck['value'];
    $descCheck = psValidateSaleCancelReasonDescription($request['reason_description'] ?? '', $reason);
    if (empty($descCheck['ok'])) {
        return ['ok' => false, 'error' => $descCheck['error'] ?? 'Description is not valid.'];
    }
    $desc = (string)$descCheck['value'];

    $branch = tillSelectedBranch();
    // Posted branch ignored.
    unset($request['branch'], $request['user'], $request['cashier'], $request['invoice_number']);

    $source = trim((string)($request['source'] ?? PS_CANCEL_AUDIT_SOURCE_UI));
    if ($source !== PS_CANCEL_AUDIT_SOURCE_UI && $source !== PS_CANCEL_AUDIT_SOURCE_CLI) {
        $source = PS_CANCEL_AUDIT_SOURCE_UI;
    }

    $audit = psSaleCancelAuditInsert($conn, [
        'action' => PS_CANCEL_ACTION_DISCARD_CART,
        'invoice_number' => '',
        'branch' => $branch,
        'previous_status' => 'CART',
        'resulting_status' => 'DISCARDED',
        'reason' => $reason,
        'reason_description' => $desc,
        'related_refund_ref' => '',
        'created_by' => $actor,
        'created_at' => date('Y-m-d H:i:s'),
        'source' => $source,
    ]);
    if (empty($audit['ok'])) {
        return ['ok' => false, 'error' => $audit['error'] ?? 'The cart could not be discarded.'];
    }

    return [
        'ok' => true,
        'action' => PS_CANCEL_ACTION_DISCARD_CART,
        'audit_id' => (int)($audit['id'] ?? 0),
        'message' => 'Cart discarded. No sale, stock, cash or customer-account records were changed.',
    ];
}

/**
 * Cancel a persisted uncompleted sale (no ledger effects). Marks CANCELLED; retains rows.
 * Rejects committed / refunded / pending-refund / already-cancelled / cross-branch / unauthorised.
 *
 * @return array{ok:bool,error?:string,action?:string,invoice_number?:string,class?:string,must_use_refund?:bool,audit_id?:int,message?:string}
 */
function psSaleCancelUncompleted(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleCancelModule($conn)) {
        return ['ok' => false, 'error' => 'The sale could not be cancelled.'];
    }
    if (!psSaleCancelMayAct()) {
        return ['ok' => false, 'error' => 'Please sign in before cancelling a sale.'];
    }
    $actor = psSessionSaleCancelUser();

    $reasonCheck = psNormalizeSaleCancelReason($request['reason'] ?? '');
    if (empty($reasonCheck['ok'])) {
        return ['ok' => false, 'error' => $reasonCheck['error'] ?? 'A cancellation reason is required.'];
    }
    $reason = (string)$reasonCheck['value'];
    $descCheck = psValidateSaleCancelReasonDescription($request['reason_description'] ?? '', $reason);
    if (empty($descCheck['ok'])) {
        return ['ok' => false, 'error' => $descCheck['error'] ?? 'Description is not valid.'];
    }
    $desc = (string)$descCheck['value'];

    $invoiceNumber = trim((string)($request['invoice_number'] ?? ''));
    // Ignore posted authority fields.
    unset(
        $request['branch'],
        $request['user'],
        $request['cashier'],
        $request['status'],
        $request['amount'],
        $request['total']
    );

    $source = trim((string)($request['source'] ?? PS_CANCEL_AUDIT_SOURCE_UI));
    if ($source !== PS_CANCEL_AUDIT_SOURCE_UI && $source !== PS_CANCEL_AUDIT_SOURCE_CLI) {
        $source = PS_CANCEL_AUDIT_SOURCE_UI;
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The sale could not be cancelled.'];
    }

    try {
        $classed = psSaleCancelClassify($conn, $invoiceNumber, true);
        if (empty($classed['ok']) && empty($classed['class'])) {
            throw new RuntimeException($classed['error'] ?? 'The sale could not be cancelled.');
        }
        if (($classed['class'] ?? '') !== PS_CANCEL_CLASS_UNCOMPLETED || empty($classed['can_cancel'])) {
            $msg = (string)($classed['message'] ?? $classed['error'] ?? 'This sale cannot be cancelled directly.');
            if (!empty($classed['must_use_refund'])) {
                if ($owns) {
                    mysqli_rollback($conn);
                }
                return [
                    'ok' => false,
                    'error' => $msg,
                    'class' => (string)($classed['class'] ?? PS_CANCEL_CLASS_COMMITTED),
                    'must_use_refund' => true,
                    'action' => PS_CANCEL_ACTION_REJECT_COMMITTED,
                ];
            }
            throw new RuntimeException($msg);
        }

        $header = $classed['header'];
        $saleBranch = trim((string)($header['branch'] ?? ''));
        $prevStatus = (string)($classed['previous_status'] ?? '');

        $workingBranch = tillSelectedBranch();
        if ($workingBranch === '' || $workingBranch !== $saleBranch) {
            throw new RuntimeException('The working branch does not match the sale branch.');
        }
        if (!psProductBranchIsAuthorised($conn, $saleBranch)) {
            throw new RuntimeException('You do not have access to this branch.');
        }

        // Re-check footprint inside txn (concurrent payment/refund).
        $foot = psSaleCancelLedgerFootprint($conn, $invoiceNumber);
        if (empty($foot['ok'])) {
            throw new RuntimeException($foot['error'] ?? 'The sale could not be cancelled.');
        }
        if (((int)$foot['payments'] > 0)
            || ((int)$foot['stock_sale'] > 0)
            || ((int)$foot['cash_sale'] > 0)
            || ((int)$foot['credit_sale'] > 0)
            || ((int)$foot['refund_requests'] > 0)
        ) {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return [
                'ok' => false,
                'error' => 'This sale now has ledger or refund activity. Direct cancellation is not allowed. Use the authorised refund workflow.',
                'class' => PS_CANCEL_CLASS_COMMITTED,
                'must_use_refund' => true,
                'action' => PS_CANCEL_ACTION_REJECT_COMMITTED,
            ];
        }

        $newStatus = PS_SALE_STATUS_CANCELLED;
        $headerId = (int)($header['id'] ?? 0);
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE online_sales_ref SET status = ? WHERE id = ? AND invoice_number = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('The sale could not be cancelled.');
        }
        mysqli_stmt_bind_param($stmt, 'sis', $newStatus, $headerId, $invoiceNumber);
        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('The sale could not be cancelled (concurrent change).');
        }
        mysqli_stmt_close($stmt);

        // Preserve line history; optionally mark lines cancelled for display (no delete).
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE online_sales SET status = ?
             WHERE invoice_number = ? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('refunded','cancelled')"
        );
        if ($stmt) {
            $lineStatus = 'cancelled';
            mysqli_stmt_bind_param($stmt, 'ss', $lineStatus, $invoiceNumber);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        $audit = psSaleCancelAuditInsert($conn, [
            'action' => PS_CANCEL_ACTION_CANCEL_UNCOMPLETED,
            'invoice_number' => $invoiceNumber,
            'branch' => $saleBranch,
            'previous_status' => $prevStatus,
            'resulting_status' => $newStatus,
            'reason' => $reason,
            'reason_description' => $desc,
            'related_refund_ref' => '',
            'created_by' => $actor,
            'created_at' => date('Y-m-d H:i:s'),
            'source' => $source,
        ]);
        if (empty($audit['ok'])) {
            throw new RuntimeException($audit['error'] ?? 'The cancellation could not be audited.');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The sale could not be cancelled.');
        }

        return [
            'ok' => true,
            'action' => PS_CANCEL_ACTION_CANCEL_UNCOMPLETED,
            'invoice_number' => $invoiceNumber,
            'class' => PS_CANCEL_CLASS_UNCOMPLETED,
            'audit_id' => (int)($audit['id'] ?? 0),
            'message' => 'Sale cancelled. Original lines retained; no stock, cash or customer-account movements were posted.',
            'previous_status' => $prevStatus,
            'resulting_status' => $newStatus,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        $msg = $e->getMessage();
        if ($msg === '') {
            $msg = 'The sale could not be cancelled.';
        }
        return ['ok' => false, 'error' => $msg];
    }
}

/**
 * Attempt direct void of any sale by invoice. Supported only for UNCOMPLETED class.
 * Committed / refunded / pending always rejected (must_use_refund).
 *
 * @return array{ok:bool,error?:string,must_use_refund?:bool,class?:string,action?:string,message?:string}
 */
function psSaleCancelAttempt(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    // 2026-10-17: a cashier (Till level) may not cancel a sale alone; a Supervisor / Management / admin
    // signs it off on the same screen (refund.php verifies them and sets _supervisor_verified).
    require_once __DIR__ . '/cash_controls.php';
    if (psCashControlsIsTillUser() && empty($request['_supervisor_verified'])) {
        return ['ok' => false, 'needs_approval' => true,
            'error' => 'Cancelling a sale needs a Supervisor, Manager or Admin. They type their username and password below.'];
    }
    if (!psSaleCancelDirectVoidCommittedEnabled()) {
        // Pre-classify without locking for clearer reject message (still revalidates in cancel).
        $invoice = trim((string)($request['invoice_number'] ?? ''));
        if ($invoice !== '') {
            $classed = psSaleCancelClassify($conn, $invoice, false);
            if (!empty($classed['ok']) && ($classed['class'] ?? '') === PS_CANCEL_CLASS_UNCOMPLETED) {
                return psSaleCancelUncompleted($conn, $request, $manageTransaction);
            }
            if (!empty($classed['must_use_refund']) || ($classed['class'] ?? '') === PS_CANCEL_CLASS_COMMITTED) {
                return [
                    'ok' => false,
                    'error' => (string)($classed['message'] ?? 'Direct void is not allowed. Use the authorised refund workflow.'),
                    'must_use_refund' => true,
                    'class' => (string)($classed['class'] ?? PS_CANCEL_CLASS_COMMITTED),
                    'action' => PS_CANCEL_ACTION_REJECT_COMMITTED,
                ];
            }
            if (($classed['class'] ?? '') === PS_CANCEL_CLASS_CANCELLED) {
                return [
                    'ok' => false,
                    'error' => 'This sale is already cancelled.',
                    'class' => PS_CANCEL_CLASS_CANCELLED,
                ];
            }
            if (($classed['class'] ?? '') === PS_CANCEL_CLASS_MISSING) {
                return ['ok' => false, 'error' => 'The receipt was not found.', 'class' => PS_CANCEL_CLASS_MISSING];
            }
            return [
                'ok' => false,
                'error' => (string)($classed['message'] ?? $classed['error'] ?? 'This sale cannot be cancelled safely.'),
                'class' => (string)($classed['class'] ?? PS_CANCEL_CLASS_UNSAFE),
                'must_use_refund' => !empty($classed['must_use_refund']),
            ];
        }
    }
    return ['ok' => false, 'error' => 'The sale could not be cancelled.'];
}
