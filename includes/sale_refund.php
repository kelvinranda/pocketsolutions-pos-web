<?php
/**
 * Phase 8.1 — Controlled customer refund foundation.
 * Phase 8.2 — Refund authorisation (REQUESTED → APPROVED → PROCESSED | REQUESTED → REJECTED).
 * Phase 8.3 — Line-level / partial refund integrity (cumulative remaining from PROCESSED only).
 *
 * Production entry: refund.php → request / approve / reject / process helpers (not immediate commit).
 * Rules:
 *   - Refunds an existing sale by server-side line id and/or invoice_number (never product name alone).
 *   - Quantity: original − SUM(PROCESSED request-line qty); requested > 0 and ≤ remaining (server only).
 *   - Multiple partials / multi-line requests allowed until remaining 0. One REF-###### per request.
 *   - REQUESTED/APPROVED do not reserve qty; only PROCESSED consumes remaining. Process fails if insufficient.
 *   - Amount = unit_price × requested qty (fallback: full-line revenue only when qty = sold). Posted totals ignored.
 *   - Branch = original sale line branch. Posted branch ignored.
 *   - Stock via stock_movements REFUND (not PURCHASE_RETURN); never goods.quantity.
 *   - CASH tender + OPEN till → one CASH_REFUND per processed request (ref REFUND + REF-######); non-cash none.
 *   - status=refunded only when line fully refunded (processed ≥ original); partials keep non-refunded status.
 *   - CREDIT: account ledger unchanged. Dup: FOR UPDATE + cash UNIQUE + submit tokens + REF uniqueness.
 *   - Auth: existing session logger + admin level only. Thresholds deferred.
 *   - Voids / uncompleted cancellation: Phase 8.4 (`includes/sale_cancel.php`) — does not bypass this workflow for committed sales.
 */

require_once __DIR__ . '/stock_movement.php';
require_once __DIR__ . '/sale_payment.php';
require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/cash_movement.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/product_master.php';

const PS_REFUND_REASON_CUSTOMER_RETURN = 'CUSTOMER_RETURN';
const PS_REFUND_REASON_DAMAGED_ITEM = 'DAMAGED_ITEM';
const PS_REFUND_REASON_WRONG_ITEM = 'WRONG_ITEM';
const PS_REFUND_REASON_DUPLICATE_SALE = 'DUPLICATE_SALE';
const PS_REFUND_REASON_PRICING_ERROR = 'PRICING_ERROR';
const PS_REFUND_REASON_OTHER = 'OTHER';

const PS_REFUND_MODE_LINE = 'LINE';
const PS_REFUND_MODE_RECEIPT = 'RECEIPT';
const PS_REFUND_MODE_LINES = 'LINES';

const PS_REFUND_AUDIT_SOURCE_UI = 'UI';
const PS_REFUND_AUDIT_SOURCE_CLI = 'CLI';

const PS_REFUND_STATUS_REQUESTED = 'REQUESTED';
const PS_REFUND_STATUS_APPROVED = 'APPROVED';
const PS_REFUND_STATUS_REJECTED = 'REJECTED';
const PS_REFUND_STATUS_PROCESSED = 'PROCESSED';

const PS_REFUND_EVENT_REQUEST = 'REQUEST';
const PS_REFUND_EVENT_APPROVAL = 'APPROVAL';
const PS_REFUND_EVENT_REJECTION = 'REJECTION';
const PS_REFUND_EVENT_PROCESSING = 'PROCESSING';

/**
 * @return list<string>
 */
function psRefundControlledReasons(): array
{
    return [
        PS_REFUND_REASON_CUSTOMER_RETURN,
        PS_REFUND_REASON_DAMAGED_ITEM,
        PS_REFUND_REASON_WRONG_ITEM,
        PS_REFUND_REASON_DUPLICATE_SALE,
        PS_REFUND_REASON_PRICING_ERROR,
        PS_REFUND_REASON_OTHER,
    ];
}

function psRefundReasonLabel(string $reason): string
{
    $labels = [
        PS_REFUND_REASON_CUSTOMER_RETURN => 'Customer return',
        PS_REFUND_REASON_DAMAGED_ITEM => 'Damaged item',
        PS_REFUND_REASON_WRONG_ITEM => 'Wrong item',
        PS_REFUND_REASON_DUPLICATE_SALE => 'Duplicate sale',
        PS_REFUND_REASON_PRICING_ERROR => 'Pricing error',
        PS_REFUND_REASON_OTHER => 'Other',
    ];
    $key = strtoupper(trim($reason));
    return $labels[$key] ?? $reason;
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeRefundReason($raw): array
{
    $reason = strtoupper(trim((string)$raw));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A refund reason is required.'];
    }
    if (!in_array($reason, psRefundControlledReasons(), true)) {
        return ['ok' => false, 'error' => 'Choose a valid refund reason.'];
    }
    return ['ok' => true, 'value' => $reason];
}

/**
 * Optional description; OTHER requires meaningful non-whitespace text (≥ 3 chars).
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateRefundReasonDescription($raw, string $reasonCode): array
{
    $description = trim((string)$raw);
    if (strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long.'];
    }
    $reasonCode = strtoupper(trim($reasonCode));
    if ($reasonCode === PS_REFUND_REASON_OTHER) {
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

function psSessionRefundUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

/**
 * Logical REFUND_CREATE — reuse existing session logger identity (no parallel permission table).
 */
function psRefundMayCreate(): bool
{
    if (function_exists('psPlatformIsActing') && psPlatformIsActing()) {
        return false; // Phase 18: provider staff never request / process refunds for a client.
    }
    return psSessionRefundUser() !== '';
}

/**
 * Logical REFUND_APPROVE — admin, plus Phase 17 Supervisor / Management (case-insensitive).
 * No monetary thresholds. No multi-level approval hierarchy. SoD self-approval rule unchanged.
 */
/**
 * 2026-10-17: a Supervisor / Management / admin approving on a cashier's screen. refund.php verifies their
 * username + password (psCashControlsVerifyApprover) and sets this for the one approve / reject call.
 */
function psRefundApproverOverride(?string $email = null, bool $clear = false): string
{
    static $override = '';
    if ($clear) {
        $override = '';
    } elseif ($email !== null) {
        $override = trim($email);
    }
    return $override;
}

function psRefundMayApprove(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (psRefundApproverOverride() !== '') {
        return true; // verified supervisor signing off on this screen
    }
    if (psSessionRefundUser() === '') {
        return false;
    }
    if (function_exists('psPlatformIsActing') && psPlatformIsActing()) {
        return false; // Phase 18: provider staff never approve refunds for a client.
    }
    return in_array(strtolower(trim((string)($_SESSION['level'] ?? ''))), ['admin', 'supervisor', 'management'], true);
}

/**
 * Logical REFUND_PROCESS — same session logger gate as CREATE.
 * No distinct elevated "process" role exists in this app; process still requires APPROVED request
 * server-side and open till for CASH. Documented simplification (not a hidden UI-only gate).
 */
function psRefundMayProcess(): bool
{
    return psRefundMayCreate();
}

/**
 * Approver / processor must have access to the original sale branch (admin = all; else authorised set).
 */
function psRefundBranchAccess(mysqli $conn, string $branch): bool
{
    $branch = trim($branch);
    if ($branch === '') {
        return false;
    }
    return psProductBranchIsAuthorised($conn, $branch);
}

/**
 * True when at least one other admin user exists (email case-insensitive), for SoD self-approval.
 */
function psRefundOtherApproverExists(mysqli $conn, string $excludeEmail): bool
{
    $excludeEmail = trim($excludeEmail);
    if ($excludeEmail === '') {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM users
         WHERE LOWER(TRIM(level)) IN ('admin', 'supervisor', 'management')
           AND LOWER(TRIM(email)) <> LOWER(?)
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $excludeEmail);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return !empty($row);
}

/**
 * SoD: reject self-approval when another authorised approver exists.
 * Limitation: if the requester is the only admin, self-approval is allowed to avoid deadlock.
 *
 * @return array{ok:bool,error?:string}
 */
function psRefundCheckSelfApproval(mysqli $conn, string $requester, string $approver): array
{
    $requester = trim($requester);
    $approver = trim($approver);
    if ($requester === '' || $approver === '') {
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }
    if (strcasecmp($requester, $approver) !== 0) {
        return ['ok' => true];
    }
    if (psRefundOtherApproverExists($conn, $approver)) {
        return ['ok' => false, 'error' => 'You cannot approve your own refund request.'];
    }
    return ['ok' => true];
}

function psRefundIssueSubmitToken(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_refund_submit_token'] = $token;
    return $token;
}

function psRefundConsumeSubmitToken($posted): bool
{
    $posted = trim((string)$posted);
    $known = trim((string)($_SESSION['ps_refund_submit_token'] ?? ''));
    if ($posted === '' || $known === '' || !hash_equals($known, $posted)) {
        return false;
    }
    unset($_SESSION['ps_refund_submit_token']);
    return true;
}

/**
 * Explicit: does not post customer-account ledger refunds / credit notes.
 */
function psRefundCustomerAccountLedgerEnabled(): bool
{
    return false;
}

/**
 * Phase 8.3: partial / cumulative line quantities enabled (server remaining only).
 */
function psRefundPartialQuantityEnabled(): bool
{
    return true;
}

/**
 * Explicit: monetary approval thresholds are not implemented (deferred; not in 8.2 scope as built).
 */
function psRefundMonetaryThresholdEnabled(): bool
{
    return false;
}

function psEnsureSaleRefundSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS sale_refund_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale_refund_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM sale_refund_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO sale_refund_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('sale_refund_seq was not seeded');
    return false;
}

function psEnsureSaleRefundAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundSeq($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM sale_refund_audit LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS sale_refund_audit (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            refund_number VARCHAR(32) NOT NULL,
            invoice_number VARCHAR(250) NOT NULL,
            mode VARCHAR(16) NOT NULL,
            branch VARCHAR(250) NOT NULL,
            payment_method VARCHAR(32) NOT NULL DEFAULT '',
            amount DECIMAL(14,2) NOT NULL,
            reason VARCHAR(64) NOT NULL,
            reason_description VARCHAR(500) NOT NULL DEFAULT '',
            line_summary TEXT NOT NULL,
            stock_movement_ids VARCHAR(500) NOT NULL DEFAULT '',
            cash_movement_id INT NULL,
            till_session_id INT NULL,
            created_by VARCHAR(250) NOT NULL,
            created_at DATETIME NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'UI',
            event_type VARCHAR(32) NOT NULL DEFAULT 'PROCESSING',
            request_id INT NULL,
            event_note VARCHAR(500) NOT NULL DEFAULT '',
            UNIQUE KEY uniq_sale_refund_event (refund_number, event_type),
            KEY idx_sale_refund_invoice (invoice_number),
            KEY idx_sale_refund_branch (branch),
            KEY idx_sale_refund_created_at (created_at),
            KEY idx_sale_refund_reason (reason),
            KEY idx_sale_refund_event (event_type),
            KEY idx_sale_refund_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('sale_refund_audit schema was not created');
            return false;
        }
        $ready = true;
        return true;
    }

    // Phase 8.2 migration: multi-event audit (do not backfill historical approvals).
    $eventCol = mysqli_query($conn, "SHOW COLUMNS FROM sale_refund_audit LIKE 'event_type'");
    if (!$eventCol || mysqli_num_rows($eventCol) === 0) {
        if (!mysqli_query(
            $conn,
            "ALTER TABLE sale_refund_audit
             ADD COLUMN event_type VARCHAR(32) NOT NULL DEFAULT 'PROCESSING' AFTER source"
        )) {
            error_log('sale_refund_audit event_type column was not added');
            return false;
        }
    }
    $reqCol = mysqli_query($conn, "SHOW COLUMNS FROM sale_refund_audit LIKE 'request_id'");
    if (!$reqCol || mysqli_num_rows($reqCol) === 0) {
        if (!mysqli_query(
            $conn,
            "ALTER TABLE sale_refund_audit
             ADD COLUMN request_id INT NULL AFTER event_type"
        )) {
            error_log('sale_refund_audit request_id column was not added');
            return false;
        }
    }
    $noteCol = mysqli_query($conn, "SHOW COLUMNS FROM sale_refund_audit LIKE 'event_note'");
    if (!$noteCol || mysqli_num_rows($noteCol) === 0) {
        if (!mysqli_query(
            $conn,
            "ALTER TABLE sale_refund_audit
             ADD COLUMN event_note VARCHAR(500) NOT NULL DEFAULT '' AFTER request_id"
        )) {
            error_log('sale_refund_audit event_note column was not added');
            return false;
        }
    }
    $oldUniq = mysqli_query($conn, "SHOW INDEX FROM sale_refund_audit WHERE Key_name = 'uniq_sale_refund_number'");
    if ($oldUniq && mysqli_num_rows($oldUniq) > 0) {
        if (!mysqli_query($conn, 'ALTER TABLE sale_refund_audit DROP INDEX uniq_sale_refund_number')) {
            error_log('sale_refund_audit old unique index was not dropped');
            return false;
        }
    }
    $newUniq = mysqli_query($conn, "SHOW INDEX FROM sale_refund_audit WHERE Key_name = 'uniq_sale_refund_event'");
    if (!$newUniq || mysqli_num_rows($newUniq) === 0) {
        if (!mysqli_query(
            $conn,
            'ALTER TABLE sale_refund_audit ADD UNIQUE KEY uniq_sale_refund_event (refund_number, event_type)'
        )) {
            error_log('sale_refund_audit event unique index was not added');
            return false;
        }
    }
    $reqIdx = mysqli_query($conn, "SHOW INDEX FROM sale_refund_audit WHERE Key_name = 'idx_sale_refund_request'");
    if (!$reqIdx || mysqli_num_rows($reqIdx) === 0) {
        mysqli_query($conn, 'ALTER TABLE sale_refund_audit ADD KEY idx_sale_refund_request (request_id)');
    }
    $evtIdx = mysqli_query($conn, "SHOW INDEX FROM sale_refund_audit WHERE Key_name = 'idx_sale_refund_event'");
    if (!$evtIdx || mysqli_num_rows($evtIdx) === 0) {
        mysqli_query($conn, 'ALTER TABLE sale_refund_audit ADD KEY idx_sale_refund_event (event_type)');
    }

    $ready = true;
    return true;
}

function psEnsureSaleRefundRequestsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundSeq($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM sale_refund_requests LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS sale_refund_requests (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        refund_number VARCHAR(32) NOT NULL,
        invoice_number VARCHAR(250) NOT NULL,
        line_id INT NULL,
        mode VARCHAR(16) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        requested_amount DECIMAL(14,2) NOT NULL,
        reason VARCHAR(64) NOT NULL,
        reason_description VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL,
        requester VARCHAR(250) NOT NULL,
        requested_at DATETIME NOT NULL,
        approver VARCHAR(250) NULL,
        approval_note VARCHAR(500) NULL,
        approved_at DATETIME NULL,
        rejector VARCHAR(250) NULL,
        rejection_note VARCHAR(500) NULL,
        rejected_at DATETIME NULL,
        processor VARCHAR(250) NULL,
        processed_at DATETIME NULL,
        processed_amount DECIMAL(14,2) NULL,
        UNIQUE KEY uniq_sale_refund_req_number (refund_number),
        KEY idx_sale_refund_req_status (status),
        KEY idx_sale_refund_req_invoice (invoice_number),
        KEY idx_sale_refund_req_branch (branch),
        KEY idx_sale_refund_req_line (line_id),
        KEY idx_sale_refund_req_requested_at (requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale_refund_requests schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Phase 8.3 — per-request sale line quantities (display snapshots; sale lines remain authoritative).
 */
function psEnsureSaleRefundRequestLinesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundRequestsSchema($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM sale_refund_request_lines LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS sale_refund_request_lines (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        sale_line_id INT NOT NULL,
        product_id VARCHAR(250) NOT NULL DEFAULT '',
        requested_qty DECIMAL(12,3) NOT NULL,
        unit_price_snapshot DECIMAL(14,2) NULL,
        line_amount_snapshot DECIMAL(14,2) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sale_refund_req_line (request_id, sale_line_id),
        KEY idx_sale_refund_req_lines_request (request_id),
        KEY idx_sale_refund_req_lines_sale_line (sale_line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale_refund_request_lines schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureSaleRefundModule(mysqli $conn): bool
{
    return psEnsureSaleRefundSeq($conn)
        && psEnsureSaleRefundAuditSchema($conn)
        && psEnsureSaleRefundRequestsSchema($conn)
        && psEnsureSaleRefundRequestLinesSchema($conn)
        && stockMovementEnsureSchema($conn)
        && psEnsureCashMovementModule($conn);
}

/**
 * Allocate REF-###### inside an open transaction (SELECT … FOR UPDATE).
 *
 * @return array{ok:bool,refund_number?:string,error?:string}
 */
function psAllocateRefundNumber(mysqli $conn): array
{
    if (!psEnsureSaleRefundSeq($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM sale_refund_seq WHERE id = 1 FOR UPDATE');
    if (!$res) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $row = mysqli_fetch_assoc($res);
    if (!$row) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $next = (int)$row['next_number'];
    if ($next < 1) {
        $next = 1;
    }
    $refundNumber = 'REF-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    $updNext = $next + 1;
    $upd = mysqli_prepare($conn, 'UPDATE sale_refund_seq SET next_number = ? WHERE id = 1');
    if (!$upd) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $updNext);
    $ok = mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    if (!$ok) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    return ['ok' => true, 'refund_number' => $refundNumber];
}

/**
 * Authoritative refund line amount in cents.
 * Phase 12: COMPLETE tax lines use stored line_total_incl (or revenue) proportionally —
 * never today's rate. Exclusive shelf unit_price × qty is not used as payable when tax
 * was recorded (would understate refund). Legacy/unconfigured keep unit_price × qty.
 *
 * @param array<string,mixed> $line
 */
function psRefundLineAmountCents(array $line, $qtyOverride = null): ?int
{
    $soldRaw = trim((string)($line['quantity'] ?? ''));
    $qtyRaw = $qtyOverride !== null ? trim((string)$qtyOverride) : $soldRaw;
    $qtyDec = psStockDecimal($qtyRaw);
    $soldDec = psStockDecimal($soldRaw);
    if ($qtyDec === null || psStockDecimalCmp($qtyDec, '0') <= 0) {
        return null;
    }

    require_once __DIR__ . '/tax.php';
    $taxStatus = strtoupper(trim((string)($line['tax_data_status'] ?? '')));
    if ($taxStatus === PS_TAX_STATUS_COMPLETE) {
        $alloc = psTaxRefundLineAllocation($line, $qtyRaw);
        if (!empty($alloc['ok']) && empty($alloc['legacy']) && isset($alloc['payable_cents']) && (int)$alloc['payable_cents'] > 0) {
            return (int)$alloc['payable_cents'];
        }
        // Incomplete COMPLETE row → fall through; do not invent.
    }

    $unitRaw = $line['unit_price'] ?? null;
    if ($unitRaw !== null && trim((string)$unitRaw) !== '') {
        $unitCents = psDecimalCents((string)$unitRaw);
        if ($unitCents === null || $unitCents < 0) {
            return null;
        }
        $lineCents = psLineCents($unitCents, $qtyRaw);
        if ($lineCents !== null && $lineCents > 0) {
            return $lineCents;
        }
    }

    // Legacy / missing unit_price: full-line revenue only when refunding the entire sold qty.
    if ($soldDec !== null && psStockDecimalCmp($qtyDec, $soldDec) === 0) {
        $revCents = psDecimalCents((string)($line['revenue'] ?? ''));
        if ($revCents === null || $revCents <= 0) {
            return null;
        }
        return $revCents;
    }
    return null;
}

/**
 * SUM of PROCESSED refund quantities for an exact sale line (authoritative consume).
 * REQUESTED / APPROVED rows do not count. Legacy whole-line status=refunded with no request-lines
 * is treated as fully refunded (compatible; pre-8.3 rows may be unmappable to request lines).
 *
 * @return array{ok:bool,original_qty?:string,previously_refunded?:string,remaining?:string,fully_refunded?:bool,error?:string}
 */
function psGetRefundableSaleLineQuantity(mysqli $conn, $saleLineId, bool $forUpdate = false): array
{
    if (!psEnsureSaleRefundRequestLinesSchema($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $saleLineId = (int)$saleLineId;
    if ($saleLineId < 1) {
        return ['ok' => false, 'error' => 'The item was not found.'];
    }
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    if (function_exists('psEnsureSaleTaxColumns')) {
        psEnsureSaleTaxColumns($conn);
    } elseif (is_file(__DIR__ . '/tax.php')) {
        require_once __DIR__ . '/tax.php';
        psEnsureSaleTaxColumns($conn);
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, quantity, status, unit_price, revenue, product_id, branch, invoice_number,
                tax_code, tax_classification, tax_rate_percent, tax_pricing_mode,
                taxable_amount, tax_amount, line_total_excl, line_total_incl, tax_data_status
         FROM online_sales WHERE id = ? LIMIT 1' . $lock
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $saleLineId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $line = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$line) {
        return ['ok' => false, 'error' => 'The item was not found.'];
    }

    $original = psStockDecimal((string)($line['quantity'] ?? ''));
    if ($original === null || psStockDecimalCmp($original, '0') <= 0) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }

    $sumStmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(l.requested_qty), 0) AS processed_qty
         FROM sale_refund_request_lines l
         INNER JOIN sale_refund_requests r ON r.id = l.request_id
         WHERE l.sale_line_id = ? AND r.status = 'PROCESSED'"
    );
    if (!$sumStmt) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    mysqli_stmt_bind_param($sumStmt, 'i', $saleLineId);
    if (!mysqli_stmt_execute($sumStmt)) {
        mysqli_stmt_close($sumStmt);
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $sumRes = mysqli_stmt_get_result($sumStmt);
    $sumRow = $sumRes ? mysqli_fetch_assoc($sumRes) : null;
    mysqli_stmt_close($sumStmt);
    $processed = psStockDecimal((string)($sumRow['processed_qty'] ?? '0'));
    if ($processed === null) {
        $processed = '0.000';
    }

    $status = strtolower(trim((string)($line['status'] ?? '')));
    // Legacy 8.1/8.2 whole-line refund without request-lines: status=refunded ⇒ fully consumed.
    if ($status === 'refunded' && psStockDecimalCmp($processed, '0') <= 0) {
        $processed = $original;
    }
    if (psStockDecimalCmp($processed, $original) > 0) {
        $processed = $original;
    }

    $remaining = '0.000';
    if (function_exists('bcsub')) {
        $remaining = bcsub($original, $processed, 3);
    } else {
        $remaining = number_format((float)$original - (float)$processed, 3, '.', '');
    }
    if (psStockDecimalCmp($remaining, '0') < 0) {
        $remaining = '0.000';
    }
    $fully = psStockDecimalCmp($remaining, '0') <= 0;

    return [
        'ok' => true,
        'line' => $line,
        'original_qty' => $original,
        'previously_refunded' => $processed,
        'remaining' => $remaining,
        'fully_refunded' => $fully,
        'sold' => $original,
    ];
}

/**
 * Remaining refundable qty for a sale line (requires $conn for cumulative PROCESSED sum).
 * Prefer psGetRefundableSaleLineQuantity. This wrapper accepts either a qty snapshot array
 * (with precomputed remaining) or falls back to binary status when no conn context.
 *
 * @param array<string,mixed> $line
 * @return array{ok:bool,sold?:string,remaining?:string,error?:string}
 */
function psRefundRemainingQty(array $line, ?mysqli $conn = null): array
{
    if ($conn !== null && isset($line['id']) && (int)$line['id'] > 0) {
        $q = psGetRefundableSaleLineQuantity($conn, (int)$line['id'], false);
        if (empty($q['ok'])) {
            return ['ok' => false, 'error' => $q['error'] ?? 'The refund could not be saved.'];
        }
        return [
            'ok' => true,
            'sold' => (string)$q['original_qty'],
            'remaining' => (string)$q['remaining'],
            'previously_refunded' => (string)$q['previously_refunded'],
        ];
    }
    if (isset($line['_remaining']) && isset($line['_sold'])) {
        return [
            'ok' => true,
            'sold' => (string)$line['_sold'],
            'remaining' => (string)$line['_remaining'],
            'previously_refunded' => (string)($line['_previously_refunded'] ?? '0.000'),
        ];
    }
    $sold = psStockDecimal((string)($line['quantity'] ?? ''));
    if ($sold === null || psStockDecimalCmp($sold, '0') <= 0) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $status = strtolower(trim((string)($line['status'] ?? '')));
    if ($status === 'refunded') {
        return ['ok' => true, 'sold' => $sold, 'remaining' => '0.000', 'previously_refunded' => $sold];
    }
    return ['ok' => true, 'sold' => $sold, 'remaining' => $sold, 'previously_refunded' => '0.000'];
}

/**
 * Validate requested qty against remaining. Empty posted qty ⇒ remaining (full remaining refund).
 *
 * @param array<string,mixed> $line must include _remaining / _sold when conn not used for resolve
 * @return array{ok:bool,quantity?:string,error?:string}
 */
function psRefundResolveQuantity(array $line, $postedQty, ?mysqli $conn = null): array
{
    $rem = psRefundRemainingQty($line, $conn);
    if (empty($rem['ok'])) {
        return ['ok' => false, 'error' => $rem['error'] ?? 'The refund could not be saved.'];
    }
    $remaining = (string)$rem['remaining'];
    $sold = (string)$rem['sold'];
    if (psStockDecimalCmp($remaining, '0') <= 0) {
        return ['ok' => false, 'error' => 'This item has already been refunded.'];
    }
    $posted = trim((string)$postedQty);
    if ($posted === '') {
        return ['ok' => true, 'quantity' => $remaining];
    }
    $qty = psStockDecimal($posted);
    if ($qty === null) {
        return ['ok' => false, 'error' => 'Refund quantity is not valid.'];
    }
    if (psStockDecimalCmp($qty, '0') <= 0) {
        return ['ok' => false, 'error' => 'Refund quantity must be greater than zero.'];
    }
    if (psStockDecimalCmp($qty, $sold) > 0) {
        return ['ok' => false, 'error' => 'Refund quantity cannot exceed the sold quantity.'];
    }
    if (psStockDecimalCmp($qty, $remaining) > 0) {
        return ['ok' => false, 'error' => 'Refund quantity cannot exceed the remaining refundable quantity.'];
    }
    if (!psRefundPartialQuantityEnabled() && psStockDecimalCmp($qty, $remaining) !== 0) {
        return ['ok' => false, 'error' => 'Partial quantity refunds are not supported.'];
    }
    return ['ok' => true, 'quantity' => $qty];
}

/**
 * Append-only refund audit row (CREATE only). Supports REQUEST / APPROVAL / REJECTION / PROCESSING.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,error?:string,duplicate?:bool}
 */
function psSaleRefundAuditInsert(mysqli $conn, array $data): array
{
    if (!psEnsureSaleRefundAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $refundNumber = trim((string)($data['refund_number'] ?? ''));
    $invoiceNumber = trim((string)($data['invoice_number'] ?? ''));
    $mode = trim((string)($data['mode'] ?? ''));
    $branch = trim((string)($data['branch'] ?? ''));
    $paymentMethod = strtoupper(trim((string)($data['payment_method'] ?? '')));
    $amount = number_format((float)($data['amount'] ?? 0), 2, '.', '');
    $reason = strtoupper(trim((string)($data['reason'] ?? '')));
    $reasonDescription = trim((string)($data['reason_description'] ?? ''));
    $lineSummary = (string)($data['line_summary'] ?? '');
    if ($lineSummary === '') {
        $lineSummary = '-';
    }
    $stockIds = trim((string)($data['stock_movement_ids'] ?? ''));
    $cashMovementId = isset($data['cash_movement_id']) && $data['cash_movement_id'] !== null
        ? (int)$data['cash_movement_id']
        : null;
    $tillSessionId = isset($data['till_session_id']) && (int)$data['till_session_id'] > 0
        ? (int)$data['till_session_id']
        : null;
    $createdBy = trim((string)($data['created_by'] ?? ''));
    $source = trim((string)($data['source'] ?? PS_REFUND_AUDIT_SOURCE_UI));
    $eventType = strtoupper(trim((string)($data['event_type'] ?? PS_REFUND_EVENT_PROCESSING)));
    $requestId = isset($data['request_id']) && (int)$data['request_id'] > 0 ? (int)$data['request_id'] : null;
    $eventNote = trim((string)($data['event_note'] ?? ''));
    $createdAt = date('Y-m-d H:i:s');

    $allowedEvents = [
        PS_REFUND_EVENT_REQUEST,
        PS_REFUND_EVENT_APPROVAL,
        PS_REFUND_EVENT_REJECTION,
        PS_REFUND_EVENT_PROCESSING,
    ];
    if ($refundNumber === '' || $invoiceNumber === '' || $branch === '' || $createdBy === '') {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    if ($mode !== PS_REFUND_MODE_LINE && $mode !== PS_REFUND_MODE_RECEIPT && $mode !== PS_REFUND_MODE_LINES) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    if (!in_array($eventType, $allowedEvents, true)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    if ($paymentMethod === '') {
        $paymentMethod = 'UNKNOWN';
    }
    if (strlen($stockIds) > 500 || strlen($reasonDescription) > 500 || strlen($source) > 32 || strlen($eventNote) > 500) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }

    $cashStr = ($cashMovementId !== null && $cashMovementId > 0) ? (string)$cashMovementId : '';
    $tillStr = ($tillSessionId !== null && $tillSessionId > 0) ? (string)$tillSessionId : '';
    $reqStr = ($requestId !== null && $requestId > 0) ? (string)$requestId : '';

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO sale_refund_audit
         (refund_number, invoice_number, mode, branch, payment_method, amount, reason, reason_description,
          line_summary, stock_movement_ids, cash_movement_id, till_session_id, created_by, created_at, source,
          event_type, request_id, event_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, ?, NULLIF(?, \'\'), ?)'
    );
    if (!$stmt) {
        error_log('sale_refund_audit insert prepare failed');
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssssssssssss',
        $refundNumber,
        $invoiceNumber,
        $mode,
        $branch,
        $paymentMethod,
        $amount,
        $reason,
        $reasonDescription,
        $lineSummary,
        $stockIds,
        $cashStr,
        $tillStr,
        $createdBy,
        $createdAt,
        $source,
        $eventType,
        $reqStr,
        $eventNote
    );
    if (!mysqli_stmt_execute($stmt)) {
        $dup = mysqli_errno($conn) === 1062;
        mysqli_stmt_close($stmt);
        if ($dup) {
            return ['ok' => false, 'error' => 'This refund was already recorded.', 'duplicate' => true];
        }
        error_log('sale_refund_audit insert failed');
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $id];
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateRefundDecisionNote($raw, string $label = 'Note'): array
{
    $note = trim((string)$raw);
    if ($note === '') {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    if (strlen($note) > 500) {
        return ['ok' => false, 'error' => $label . ' is too long.'];
    }
    if (preg_match('/^\.+$/', $note) || preg_match('/^-{2,}$/', $note) || strlen($note) < 3) {
        return ['ok' => false, 'error' => 'Enter a meaningful ' . strtolower($label) . '.'];
    }
    return ['ok' => true, 'value' => $note];
}

function psRefundStatusLabel(string $status): string
{
    $map = [
        PS_REFUND_STATUS_REQUESTED => 'Pending',
        PS_REFUND_STATUS_APPROVED => 'Approved',
        PS_REFUND_STATUS_REJECTED => 'Rejected',
        PS_REFUND_STATUS_PROCESSED => 'Processed',
    ];
    $key = strtoupper(trim($status));
    return $map[$key] ?? $status;
}

/**
 * Load sale lines for an invoice or single line id. Enriches with remaining from PROCESSED qty.
 * status=refunded is compatibility only — remaining is authoritative.
 *
 * @return array{ok:bool,error?:string,lines?:list<array>,all_lines?:list<array>,invoice?:string,branch?:string,line_mode?:bool}
 */
function psRefundLoadSaleLines(mysqli $conn, $lineId, $invoiceNumber, bool $forUpdate = false): array
{
    $lineId = trim((string)$lineId);
    $invoiceNumber = trim((string)$invoiceNumber);
    $lineMode = $lineId !== '';
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    require_once __DIR__ . '/tax.php';
    psEnsureSaleTaxColumns($conn);
    $taxSelect = 'id, invoice_number, product_id, product, quantity, revenue, unit_price, branch, status,
                tax_code, tax_classification, tax_rate_percent, tax_pricing_mode,
                taxable_amount, tax_amount, line_total_excl, line_total_incl, tax_data_status';
    if ($lineMode) {
        if (!preg_match('/^[1-9][0-9]*$/', $lineId)) {
            return ['ok' => false, 'error' => 'The item was not found.'];
        }
        $stmt = mysqli_prepare(
            $conn,
            'SELECT ' . $taxSelect . ' FROM online_sales WHERE id = ? LIMIT 1' . $lock
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'The refund could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 's', $lineId);
    } else {
        if ($invoiceNumber === '' || strlen($invoiceNumber) > 250) {
            return ['ok' => false, 'error' => 'The receipt was not found.'];
        }
        $stmt = mysqli_prepare(
            $conn,
            'SELECT ' . $taxSelect . ' FROM online_sales WHERE invoice_number = ? ORDER BY id ASC' . $lock
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'The refund could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $lines = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $lines[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    if (!$lines) {
        return ['ok' => false, 'error' => $lineMode ? 'The item was not found.' : 'The receipt was not found.'];
    }

    $headerInvoice = trim((string)($lines[0]['invoice_number'] ?? $invoiceNumber));
    $saleBranch = trim((string)($lines[0]['branch'] ?? ''));
    if ($saleBranch === '') {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    foreach ($lines as $line) {
        if (trim((string)($line['branch'] ?? '')) !== $saleBranch) {
            return ['ok' => false, 'error' => 'The refund could not be saved.'];
        }
    }

    $enriched = [];
    $refundable = [];
    foreach ($lines as $line) {
        $q = psGetRefundableSaleLineQuantity($conn, (int)$line['id'], false);
        if (empty($q['ok'])) {
            return ['ok' => false, 'error' => $q['error'] ?? 'The refund could not be saved.'];
        }
        $line['_sold'] = (string)$q['original_qty'];
        $line['_previously_refunded'] = (string)$q['previously_refunded'];
        $line['_remaining'] = (string)$q['remaining'];
        $enriched[] = $line;
        if (psStockDecimalCmp((string)$q['remaining'], '0') > 0) {
            $refundable[] = $line;
        }
    }
    if (!$refundable) {
        return [
            'ok' => false,
            'error' => $lineMode ? 'This item has already been refunded.' : 'This receipt has already been refunded.',
            'all_lines' => $enriched,
            'invoice' => $headerInvoice,
            'branch' => $saleBranch,
        ];
    }

    return [
        'ok' => true,
        'lines' => $refundable,
        'all_lines' => $enriched,
        'invoice' => $headerInvoice,
        'branch' => $saleBranch,
        'line_mode' => $lineMode,
    ];
}

/**
 * Compute amount for explicit line qty pairs.
 *
 * @param list<array{line:array,quantity:string}> $pairs
 * @return array{ok:bool,amount?:string,cents?:int,line_summaries?:list<string>,error?:string}
 */
function psRefundComputeAmountForLineQtys(array $pairs): array
{
    $cents = 0;
    $summaries = [];
    foreach ($pairs as $pair) {
        $line = $pair['line'];
        $qty = (string)$pair['quantity'];
        $lineCents = psRefundLineAmountCents($line, $qty);
        if ($lineCents === null || $lineCents <= 0) {
            return ['ok' => false, 'error' => 'The refund amount could not be determined.'];
        }
        $cents += $lineCents;
        $summaries[] = (string)$line['id'] . ':' . trim((string)($line['product_id'] ?? '')) . ':'
            . $qty . ':' . psMoneyFromCents($lineCents);
    }
    if ($cents <= 0) {
        return ['ok' => false, 'error' => 'The refund amount could not be determined.'];
    }
    return [
        'ok' => true,
        'amount' => psMoneyFromCents($cents),
        'cents' => $cents,
        'line_summaries' => $summaries,
    ];
}

/**
 * Compute display/process amount for full remaining on each line (receipt / whole remaining).
 *
 * @param list<array<string,mixed>> $lines
 * @return array{ok:bool,amount?:string,cents?:int,error?:string}
 */
function psRefundComputeAmountFromLines(array $lines, ?mysqli $conn = null): array
{
    $pairs = [];
    foreach ($lines as $line) {
        $qtyResolve = psRefundResolveQuantity($line, '', $conn);
        if (empty($qtyResolve['ok'])) {
            return ['ok' => false, 'error' => $qtyResolve['error'] ?? 'The refund amount could not be determined.'];
        }
        $pairs[] = ['line' => $line, 'quantity' => (string)$qtyResolve['quantity']];
    }
    return psRefundComputeAmountForLineQtys($pairs);
}

/**
 * Insert request lines (immutable after insert; approval does not mutate).
 * Phase 12 tax snapshots are stored when original sale line has COMPLETE tax data.
 *
 * @param list<array{sale_line_id:int,product_id:string,requested_qty:string,unit_price_snapshot:?string,line_amount_snapshot:string,tax_amount_snapshot?:?string,taxable_amount_snapshot?:?string,tax_data_status?:?string}> $rows
 * @return array{ok:bool,error?:string}
 */
function psRefundInsertRequestLines(mysqli $conn, int $requestId, array $rows): array
{
    if ($requestId < 1 || !$rows) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    if (!psEnsureSaleRefundRequestLinesSchema($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    require_once __DIR__ . '/tax.php';
    if (!psEnsureSaleTaxColumns($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO sale_refund_request_lines
         (request_id, sale_line_id, product_id, requested_qty, unit_price_snapshot, line_amount_snapshot,
          tax_amount_snapshot, taxable_amount_snapshot, tax_data_status, created_at)
         VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
        $saleLineId = (int)$row['sale_line_id'];
        $productId = trim((string)$row['product_id']);
        $qty = (string)$row['requested_qty'];
        $unitSnap = $row['unit_price_snapshot'] !== null && $row['unit_price_snapshot'] !== ''
            ? number_format((float)$row['unit_price_snapshot'], 2, '.', '')
            : '';
        $amtSnap = number_format((float)$row['line_amount_snapshot'], 2, '.', '');
        $taxSnap = isset($row['tax_amount_snapshot']) && $row['tax_amount_snapshot'] !== null && $row['tax_amount_snapshot'] !== ''
            ? number_format((float)$row['tax_amount_snapshot'], 2, '.', '')
            : '';
        $taxableSnap = isset($row['taxable_amount_snapshot']) && $row['taxable_amount_snapshot'] !== null && $row['taxable_amount_snapshot'] !== ''
            ? number_format((float)$row['taxable_amount_snapshot'], 2, '.', '')
            : '';
        $taxStatus = isset($row['tax_data_status']) ? trim((string)$row['tax_data_status']) : '';
        mysqli_stmt_bind_param(
            $stmt,
            'iissssssss',
            $requestId,
            $saleLineId,
            $productId,
            $qty,
            $unitSnap,
            $amtSnap,
            $taxSnap,
            $taxableSnap,
            $taxStatus,
            $now
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return ['ok' => false, 'error' => 'The refund could not be saved.'];
        }
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true];
}

/**
 * @return list<array<string,mixed>>
 */
function psRefundFetchRequestLines(mysqli $conn, int $requestId): array
{
    if ($requestId < 1 || !psEnsureSaleRefundRequestLinesSchema($conn)) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM sale_refund_request_lines WHERE request_id = ? ORDER BY id ASC'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Normalise posted lines for a request: list of {sale_line_id, quantity}.
 * Supports legacy line_id / invoice_number (full remaining) and multi-line arrays.
 *
 * @param array<string,mixed> $request
 * @return array{ok:bool,pairs?:list<array{line:array,quantity:string}>,mode?:string,header_line_id?:?int,invoice?:string,branch?:string,error?:string}
 */
function psRefundBuildRequestLinePairs(mysqli $conn, array $request, bool $forUpdate = false): array
{
    $postedLines = array_key_exists('lines', $request) ? $request['lines'] : null;
    $lineId = trim((string)($request['line_id'] ?? ''));
    $invoiceNumber = trim((string)($request['invoice_number'] ?? ''));

    if (is_array($postedLines)) {
        if ($postedLines === []) {
            return ['ok' => false, 'error' => 'Select at least one refund line.'];
        }
        $byId = [];
        $firstInvoice = '';
        $saleBranch = '';
        $pairs = [];
        foreach ($postedLines as $pl) {
            if (!is_array($pl)) {
                return ['ok' => false, 'error' => 'Refund line data is not valid.'];
            }
            $sid = (int)($pl['sale_line_id'] ?? $pl['line_id'] ?? $pl['id'] ?? 0);
            if ($sid < 1) {
                return ['ok' => false, 'error' => 'The item was not found.'];
            }
            if (isset($byId[$sid])) {
                return ['ok' => false, 'error' => 'Duplicate sale lines are not allowed on one refund request.'];
            }
            $byId[$sid] = true;
            $qInfo = psGetRefundableSaleLineQuantity($conn, $sid, $forUpdate);
            if (empty($qInfo['ok'])) {
                return ['ok' => false, 'error' => $qInfo['error'] ?? 'The item was not found.'];
            }
            $line = $qInfo['line'];
            $line['_sold'] = (string)$qInfo['original_qty'];
            $line['_previously_refunded'] = (string)$qInfo['previously_refunded'];
            $line['_remaining'] = (string)$qInfo['remaining'];
            $inv = trim((string)($line['invoice_number'] ?? ''));
            $br = trim((string)($line['branch'] ?? ''));
            if ($firstInvoice === '') {
                $firstInvoice = $inv;
                $saleBranch = $br;
            } elseif ($inv !== $firstInvoice || $br !== $saleBranch) {
                return ['ok' => false, 'error' => 'All refund lines must belong to the same sale.'];
            }
            $qtyResolve = psRefundResolveQuantity($line, $pl['quantity'] ?? $pl['qty'] ?? '', $conn);
            if (empty($qtyResolve['ok'])) {
                return ['ok' => false, 'error' => $qtyResolve['error'] ?? 'Refund quantity is not valid.'];
            }
            $pairs[] = ['line' => $line, 'quantity' => (string)$qtyResolve['quantity']];
        }
        if (!$pairs) {
            return ['ok' => false, 'error' => 'Select at least one refund line.'];
        }
        $mode = count($pairs) === 1 ? PS_REFUND_MODE_LINE : PS_REFUND_MODE_LINES;
        $headerLineId = count($pairs) === 1 ? (int)$pairs[0]['line']['id'] : null;
        return [
            'ok' => true,
            'pairs' => $pairs,
            'mode' => $mode,
            'header_line_id' => $headerLineId,
            'invoice' => $firstInvoice,
            'branch' => $saleBranch,
        ];
    }

    if ($lineId !== '') {
        $loaded = psRefundLoadSaleLines($conn, $lineId, '', $forUpdate);
        if (empty($loaded['ok'])) {
            return ['ok' => false, 'error' => $loaded['error'] ?? 'The item was not found.'];
        }
        $line = $loaded['lines'][0];
        $qtyResolve = psRefundResolveQuantity($line, $request['quantity'] ?? '', $conn);
        if (empty($qtyResolve['ok'])) {
            return ['ok' => false, 'error' => $qtyResolve['error'] ?? 'Refund quantity is not valid.'];
        }
        return [
            'ok' => true,
            'pairs' => [['line' => $line, 'quantity' => (string)$qtyResolve['quantity']]],
            'mode' => PS_REFUND_MODE_LINE,
            'header_line_id' => (int)$line['id'],
            'invoice' => (string)$loaded['invoice'],
            'branch' => (string)$loaded['branch'],
        ];
    }

    if ($invoiceNumber === '') {
        return ['ok' => false, 'error' => 'The receipt was not found.'];
    }
    $loaded = psRefundLoadSaleLines($conn, '', $invoiceNumber, $forUpdate);
    if (empty($loaded['ok'])) {
        return ['ok' => false, 'error' => $loaded['error'] ?? 'The receipt was not found.'];
    }
    $pairs = [];
    foreach ($loaded['lines'] as $line) {
        $qtyResolve = psRefundResolveQuantity($line, '', $conn);
        if (empty($qtyResolve['ok'])) {
            return ['ok' => false, 'error' => $qtyResolve['error'] ?? 'Refund quantity is not valid.'];
        }
        $pairs[] = ['line' => $line, 'quantity' => (string)$qtyResolve['quantity']];
    }
    return [
        'ok' => true,
        'pairs' => $pairs,
        'mode' => PS_REFUND_MODE_RECEIPT,
        'header_line_id' => null,
        'invoice' => (string)$loaded['invoice'],
        'branch' => (string)$loaded['branch'],
    ];
}

/**
 * @return array{ok:bool,request?:array,error?:string}
 */
function psRefundFetchRequestForUpdate(mysqli $conn, int $requestId): array
{
    if ($requestId < 1) {
        return ['ok' => false, 'error' => 'The refund request was not found.'];
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM sale_refund_requests WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'error' => 'The refund request was not found.'];
    }
    return ['ok' => true, 'request' => $row];
}

/**
 * Create a refund request (REQUESTED). Allocates REF-######. No stock/cash writes.
 * Lines/qty stored on sale_refund_request_lines. REQUESTED does not reserve remaining.
 *
 * @param array<string,mixed> $request keys: lines[]|{line_id,quantity?}|invoice_number, reason, …
 * @return array{ok:bool,error?:string,request_id?:int,refund_number?:string,amount?:string,status?:string}
 */
function psRequestRefund(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundModule($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    if (!psRefundMayCreate()) {
        return ['ok' => false, 'error' => 'Please sign in before requesting a refund.'];
    }
    $actorEmail = psSessionRefundUser();

    $reasonCheck = psNormalizeRefundReason($request['reason'] ?? '');
    if (empty($reasonCheck['ok'])) {
        return ['ok' => false, 'error' => $reasonCheck['error'] ?? 'A refund reason is required.'];
    }
    $reason = (string)$reasonCheck['value'];
    $descCheck = psValidateRefundReasonDescription($request['reason_description'] ?? $request['description'] ?? '', $reason);
    if (empty($descCheck['ok'])) {
        return ['ok' => false, 'error' => $descCheck['error'] ?? 'Description is not valid.'];
    }
    $reasonDescription = (string)$descCheck['value'];

    $source = trim((string)($request['source'] ?? PS_REFUND_AUDIT_SOURCE_UI));
    if ($source !== PS_REFUND_AUDIT_SOURCE_UI && $source !== PS_REFUND_AUDIT_SOURCE_CLI) {
        $source = PS_REFUND_AUDIT_SOURCE_UI;
    }

    // Ignore posted authority fields (keep lines / line_id / invoice / quantity / reason).
    unset(
        $request['amount'],
        $request['total'],
        $request['branch'],
        $request['user'],
        $request['cashier'],
        $request['method'],
        $request['payment_method'],
        $request['status'],
        $request['approver'],
        $request['processor'],
        $request['refund_number']
    );

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }

    try {
        $built = psRefundBuildRequestLinePairs($conn, $request, true);
        if (empty($built['ok'])) {
            throw new RuntimeException($built['error'] ?? 'The refund could not be saved.');
        }
        /** @var list<array{line:array,quantity:string}> $pairs */
        $pairs = $built['pairs'];
        $headerInvoice = (string)$built['invoice'];
        $saleBranch = (string)$built['branch'];
        $mode = (string)$built['mode'];
        $headerLineId = $built['header_line_id'];

        $authorisedBranch = tillSelectedBranch();
        if ($authorisedBranch === '' || $authorisedBranch !== $saleBranch) {
            throw new RuntimeException('The working branch does not match the sale branch.');
        }
        if (!psRefundBranchAccess($conn, $saleBranch)) {
            throw new RuntimeException('You do not have access to this branch.');
        }

        // No reservation for REQUESTED/APPROVED — only PROCESSED consumes remaining (8.3).

        $amt = psRefundComputeAmountForLineQtys($pairs);
        if (empty($amt['ok'])) {
            throw new RuntimeException($amt['error'] ?? 'The refund amount could not be determined.');
        }
        $requestedAmount = (string)$amt['amount'];
        $requestLineSummary = implode(';', $amt['line_summaries'] ?? []);

        $alloc = psAllocateRefundNumber($conn);
        if (empty($alloc['ok'])) {
            throw new RuntimeException('The refund could not be saved.');
        }
        $refundNumber = (string)$alloc['refund_number'];
        $status = PS_REFUND_STATUS_REQUESTED;
        $now = date('Y-m-d H:i:s');
        $lineIdStr = $headerLineId !== null ? (string)(int)$headerLineId : '';

        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO sale_refund_requests
             (refund_number, invoice_number, line_id, mode, branch, requested_amount, reason, reason_description,
              status, requester, requested_at)
             VALUES (?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('The refund could not be saved.');
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssssssssss',
            $refundNumber,
            $headerInvoice,
            $lineIdStr,
            $mode,
            $saleBranch,
            $requestedAmount,
            $reason,
            $reasonDescription,
            $status,
            $actorEmail,
            $now
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('The refund could not be saved.');
        }
        $requestId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        if ($requestId < 1) {
            throw new RuntimeException('The refund could not be saved.');
        }
        // Multi-currency: the refund is in the original sale currency; base_amount uses the original sale rate.
        if (psFxHasColumn($conn, 'sale_refund_requests', 'currency')) {
            $rfSnap = psFxSaleSnapshot($conn, $headerInvoice);
            $rfBase = psFxCentsToMoney(psFxToBaseCents($conn, $rfSnap, (int)psFxMoneyToCents($requestedAmount)));
            $rfCur = (string)$rfSnap['currency'];
            $upd = mysqli_prepare($conn, 'UPDATE sale_refund_requests SET currency = ?, base_amount = ? WHERE id = ?');
            if (!$upd) {
                throw new RuntimeException('The refund could not be saved.');
            }
            mysqli_stmt_bind_param($upd, 'ssi', $rfCur, $rfBase, $requestId);
            $okUpd = mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            if (!$okUpd) {
                throw new RuntimeException('The refund could not be saved.');
            }
        }

        $lineRows = [];
        require_once __DIR__ . '/tax.php';
        foreach ($pairs as $pair) {
            $line = $pair['line'];
            $qty = (string)$pair['quantity'];
            $lineCents = psRefundLineAmountCents($line, $qty);
            $unitRaw = $line['unit_price'] ?? null;
            $unitSnap = ($unitRaw !== null && trim((string)$unitRaw) !== '')
                ? number_format((float)$unitRaw, 2, '.', '')
                : null;
            $taxSnap = null;
            $taxableSnap = null;
            $taxStatus = null;
            $alloc = psTaxRefundLineAllocation($line, $qty);
            if (!empty($alloc['ok']) && empty($alloc['legacy']) && isset($alloc['tax_cents'])) {
                $origTax = psDecimalCents((string)($line['tax_amount'] ?? '0')) ?? 0;
                $already = psTaxProcessedRefundTaxCents($conn, (int)$line['id']);
                $remainingTax = max(0, $origTax - $already);
                $taxCents = min((int)$alloc['tax_cents'], $remainingTax);
                $taxSnap = psMoneyFromCents($taxCents);
                $taxableSnap = psMoneyFromCents((int)$alloc['taxable_cents']);
                $taxStatus = PS_TAX_STATUS_COMPLETE;
            } else {
                $taxStatus = (string)($alloc['tax_data_status'] ?? PS_TAX_STATUS_LEGACY);
            }
            $lineRows[] = [
                'sale_line_id' => (int)$line['id'],
                'product_id' => trim((string)($line['product_id'] ?? '')),
                'requested_qty' => $qty,
                'unit_price_snapshot' => $unitSnap,
                'line_amount_snapshot' => psMoneyFromCents((int)$lineCents),
                'tax_amount_snapshot' => $taxSnap,
                'taxable_amount_snapshot' => $taxableSnap,
                'tax_data_status' => $taxStatus,
            ];
        }
        $insLines = psRefundInsertRequestLines($conn, $requestId, $lineRows);
        if (empty($insLines['ok'])) {
            throw new RuntimeException('The refund could not be saved.');
        }

        $payMethod = psCashMovementSalePaymentMethod($conn, $headerInvoice);
        $auditMethod = (!empty($payMethod['ok']) && empty($payMethod['unknown']))
            ? strtoupper(trim((string)($payMethod['method'] ?? '')))
            : 'UNKNOWN';
        if ($auditMethod === '') {
            $auditMethod = 'UNKNOWN';
        }

        $audit = psSaleRefundAuditInsert($conn, [
            'refund_number' => $refundNumber,
            'invoice_number' => $headerInvoice,
            'mode' => $mode,
            'branch' => $saleBranch,
            'payment_method' => $auditMethod,
            'amount' => $requestedAmount,
            'reason' => $reason,
            'reason_description' => $reasonDescription,
            'line_summary' => $requestLineSummary !== '' ? $requestLineSummary : 'REQUEST',
            'created_by' => $actorEmail,
            'source' => $source,
            'event_type' => PS_REFUND_EVENT_REQUEST,
            'request_id' => $requestId,
            'event_note' => '',
        ]);
        if (empty($audit['ok'])) {
            throw new RuntimeException('The refund could not be saved.');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The refund could not be saved.');
        }
        return [
            'ok' => true,
            'request_id' => $requestId,
            'refund_number' => $refundNumber,
            'amount' => $requestedAmount,
            'status' => $status,
            'line_count' => count($pairs),
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => psRefundSafeError($e->getMessage())];
    }
}

/**
 * Approve a REQUESTED refund request.
 *
 * @param array<string,mixed> $request keys: request_id, approval_note?, source?
 */
function psApproveRefundRequest(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundModule($conn)) {
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }
    if (!psRefundMayApprove()) {
        return ['ok' => false, 'error' => 'You are not authorised to approve refunds.'];
    }
    $actorEmail = psRefundApproverOverride() !== '' ? psRefundApproverOverride() : psSessionRefundUser();
    $requestId = (int)($request['request_id'] ?? 0);
    $noteCheck = psValidateRefundDecisionNote($request['approval_note'] ?? $request['note'] ?? 'Approved', 'Approval note');
    if (empty($noteCheck['ok'])) {
        return ['ok' => false, 'error' => $noteCheck['error'] ?? 'Approval note is required.'];
    }
    $note = (string)$noteCheck['value'];
    $source = trim((string)($request['source'] ?? PS_REFUND_AUDIT_SOURCE_UI));
    if ($source !== PS_REFUND_AUDIT_SOURCE_UI && $source !== PS_REFUND_AUDIT_SOURCE_CLI) {
        $source = PS_REFUND_AUDIT_SOURCE_UI;
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }

    try {
        $fetched = psRefundFetchRequestForUpdate($conn, $requestId);
        if (empty($fetched['ok'])) {
            throw new RuntimeException($fetched['error'] ?? 'The refund request was not found.');
        }
        $row = $fetched['request'];
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if ($status === PS_REFUND_STATUS_APPROVED) {
            throw new RuntimeException('This refund request is already approved.');
        }
        if ($status === PS_REFUND_STATUS_REJECTED) {
            throw new RuntimeException('This refund request was rejected and cannot be approved.');
        }
        if ($status === PS_REFUND_STATUS_PROCESSED) {
            throw new RuntimeException('This refund request has already been processed.');
        }
        if ($status !== PS_REFUND_STATUS_REQUESTED) {
            throw new RuntimeException('This refund request cannot be approved.');
        }

        $saleBranch = trim((string)($row['branch'] ?? ''));
        if (!psRefundBranchAccess($conn, $saleBranch)) {
            throw new RuntimeException('You do not have access to this branch.');
        }

        $sod = psRefundCheckSelfApproval($conn, (string)$row['requester'], $actorEmail);
        if (empty($sod['ok'])) {
            throw new RuntimeException($sod['error'] ?? 'You cannot approve your own refund request.');
        }

        // Validate locked request lines still fit remaining (no mutation of approved lines).
        $reqLines = psRefundFetchRequestLines($conn, $requestId);
        if (!$reqLines) {
            throw new RuntimeException('The refund request has no lines.');
        }
        $pairs = [];
        foreach ($reqLines as $rl) {
            $sid = (int)$rl['sale_line_id'];
            $qInfo = psGetRefundableSaleLineQuantity($conn, $sid, true);
            if (empty($qInfo['ok'])) {
                throw new RuntimeException($qInfo['error'] ?? 'The sale changed and this refund cannot be approved.');
            }
            $line = $qInfo['line'];
            $line['_sold'] = (string)$qInfo['original_qty'];
            $line['_previously_refunded'] = (string)$qInfo['previously_refunded'];
            $line['_remaining'] = (string)$qInfo['remaining'];
            $qty = psStockDecimal((string)$rl['requested_qty']);
            if ($qty === null || psStockDecimalCmp($qty, '0') <= 0) {
                throw new RuntimeException('Refund quantity must be greater than zero.');
            }
            if (psStockDecimalCmp($qty, (string)$qInfo['remaining']) > 0) {
                throw new RuntimeException('Refund quantity cannot exceed the remaining refundable quantity.');
            }
            $pairs[] = ['line' => $line, 'quantity' => $qty];
        }
        $amt = psRefundComputeAmountForLineQtys($pairs);
        if (empty($amt['ok'])) {
            throw new RuntimeException($amt['error'] ?? 'The refund amount could not be determined.');
        }
        $expectedAmount = number_format((float)$amt['amount'], 2, '.', '');
        $requestedAmount = number_format((float)($row['requested_amount'] ?? 0), 2, '.', '');
        if ($expectedAmount !== $requestedAmount) {
            throw new RuntimeException('The sale changed and this refund cannot be approved.');
        }
        $approvalSummary = implode(';', $amt['line_summaries'] ?? ['APPROVAL']);

        $now = date('Y-m-d H:i:s');
        $newStatus = PS_REFUND_STATUS_APPROVED;
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE sale_refund_requests
             SET status = ?, approver = ?, approval_note = ?, approved_at = ?
             WHERE id = ? AND status = 'REQUESTED'"
        );
        if (!$stmt) {
            throw new RuntimeException('The refund request could not be updated.');
        }
        mysqli_stmt_bind_param($stmt, 'ssssi', $newStatus, $actorEmail, $note, $now, $requestId);
        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('This refund request could not be approved.');
        }
        mysqli_stmt_close($stmt);

        $audit = psSaleRefundAuditInsert($conn, [
            'refund_number' => (string)$row['refund_number'],
            'invoice_number' => (string)$row['invoice_number'],
            'mode' => (string)$row['mode'],
            'branch' => $saleBranch,
            'payment_method' => 'UNKNOWN',
            'amount' => $requestedAmount,
            'reason' => (string)$row['reason'],
            'reason_description' => (string)$row['reason_description'],
            'line_summary' => $approvalSummary,
            'created_by' => $actorEmail,
            'source' => $source,
            'event_type' => PS_REFUND_EVENT_APPROVAL,
            'request_id' => $requestId,
            'event_note' => $note,
        ]);
        if (empty($audit['ok'])) {
            throw new RuntimeException(!empty($audit['duplicate'])
                ? 'This refund was already recorded.'
                : 'The refund request could not be updated.');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The refund request could not be updated.');
        }
        return [
            'ok' => true,
            'request_id' => $requestId,
            'refund_number' => (string)$row['refund_number'],
            'status' => $newStatus,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => psRefundSafeError($e->getMessage())];
    }
}

/**
 * Reject a REQUESTED refund request (never processable afterward).
 *
 * @param array<string,mixed> $request keys: request_id, rejection_note, source?
 */
function psRejectRefundRequest(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundModule($conn)) {
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }
    if (!psRefundMayApprove()) {
        return ['ok' => false, 'error' => 'You are not authorised to reject refunds.'];
    }
    $actorEmail = psRefundApproverOverride() !== '' ? psRefundApproverOverride() : psSessionRefundUser();
    $requestId = (int)($request['request_id'] ?? 0);
    $noteCheck = psValidateRefundDecisionNote($request['rejection_note'] ?? $request['note'] ?? '', 'Rejection note');
    if (empty($noteCheck['ok'])) {
        return ['ok' => false, 'error' => $noteCheck['error'] ?? 'Rejection note is required.'];
    }
    $note = (string)$noteCheck['value'];
    $source = trim((string)($request['source'] ?? PS_REFUND_AUDIT_SOURCE_UI));
    if ($source !== PS_REFUND_AUDIT_SOURCE_UI && $source !== PS_REFUND_AUDIT_SOURCE_CLI) {
        $source = PS_REFUND_AUDIT_SOURCE_UI;
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The refund request could not be updated.'];
    }

    try {
        $fetched = psRefundFetchRequestForUpdate($conn, $requestId);
        if (empty($fetched['ok'])) {
            throw new RuntimeException($fetched['error'] ?? 'The refund request was not found.');
        }
        $row = $fetched['request'];
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if ($status === PS_REFUND_STATUS_REJECTED) {
            throw new RuntimeException('This refund request is already rejected.');
        }
        if ($status === PS_REFUND_STATUS_PROCESSED) {
            throw new RuntimeException('This refund request has already been processed.');
        }
        if ($status === PS_REFUND_STATUS_APPROVED) {
            throw new RuntimeException('This refund request is already approved and cannot be rejected.');
        }
        if ($status !== PS_REFUND_STATUS_REQUESTED) {
            throw new RuntimeException('This refund request cannot be rejected.');
        }

        $saleBranch = trim((string)($row['branch'] ?? ''));
        if (!psRefundBranchAccess($conn, $saleBranch)) {
            throw new RuntimeException('You do not have access to this branch.');
        }

        $now = date('Y-m-d H:i:s');
        $newStatus = PS_REFUND_STATUS_REJECTED;
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE sale_refund_requests
             SET status = ?, rejector = ?, rejection_note = ?, rejected_at = ?
             WHERE id = ? AND status = 'REQUESTED'"
        );
        if (!$stmt) {
            throw new RuntimeException('The refund request could not be updated.');
        }
        mysqli_stmt_bind_param($stmt, 'ssssi', $newStatus, $actorEmail, $note, $now, $requestId);
        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('This refund request could not be rejected.');
        }
        mysqli_stmt_close($stmt);

        $audit = psSaleRefundAuditInsert($conn, [
            'refund_number' => (string)$row['refund_number'],
            'invoice_number' => (string)$row['invoice_number'],
            'mode' => (string)$row['mode'],
            'branch' => $saleBranch,
            'payment_method' => 'UNKNOWN',
            'amount' => (string)$row['requested_amount'],
            'reason' => (string)$row['reason'],
            'reason_description' => (string)$row['reason_description'],
            'line_summary' => 'REJECTION',
            'created_by' => $actorEmail,
            'source' => $source,
            'event_type' => PS_REFUND_EVENT_REJECTION,
            'request_id' => $requestId,
            'event_note' => $note,
        ]);
        if (empty($audit['ok'])) {
            throw new RuntimeException('The refund request could not be updated.');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The refund request could not be updated.');
        }
        return [
            'ok' => true,
            'request_id' => $requestId,
            'refund_number' => (string)$row['refund_number'],
            'status' => $newStatus,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => psRefundSafeError($e->getMessage())];
    }
}

/**
 * Process an APPROVED refund request — stock REFUND + optional CASH_REFUND + audit PROCESSING.
 * Revalidates exact approved line qtys against remaining (PROCESSED only). Never auto-shrinks.
 * One fail = whole txn rollback. status=refunded only when a line's remaining becomes 0.
 *
 * @param array<string,mixed> $request keys: request_id, source?, _force_fail?
 */
function psProcessApprovedRefund(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundModule($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }
    if (!psRefundMayProcess()) {
        return ['ok' => false, 'error' => 'Please sign in before processing a refund.'];
    }
    $actorEmail = psSessionRefundUser();
    $requestId = (int)($request['request_id'] ?? 0);
    $source = trim((string)($request['source'] ?? PS_REFUND_AUDIT_SOURCE_UI));
    if ($source !== PS_REFUND_AUDIT_SOURCE_UI && $source !== PS_REFUND_AUDIT_SOURCE_CLI) {
        $source = PS_REFUND_AUDIT_SOURCE_UI;
    }
    $forceFail = !empty($request['_force_fail']);

    unset(
        $request['amount'],
        $request['total'],
        $request['branch'],
        $request['user'],
        $request['cashier'],
        $request['method'],
        $request['payment_method'],
        $request['reason'],
        $request['reason_description'],
        $request['status'],
        $request['till_session_id'],
        $request['lines'],
        $request['quantity']
    );

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The refund could not be saved.'];
    }

    try {
        $fetched = psRefundFetchRequestForUpdate($conn, $requestId);
        if (empty($fetched['ok'])) {
            throw new RuntimeException($fetched['error'] ?? 'The refund request was not found.');
        }
        $reqRow = $fetched['request'];
        $status = strtoupper(trim((string)($reqRow['status'] ?? '')));
        if ($status === PS_REFUND_STATUS_REQUESTED) {
            throw new RuntimeException('This refund request is still pending approval.');
        }
        if ($status === PS_REFUND_STATUS_REJECTED) {
            throw new RuntimeException('This refund request was rejected and cannot be processed.');
        }
        if ($status === PS_REFUND_STATUS_PROCESSED) {
            throw new RuntimeException('This refund has already been processed.');
        }
        if ($status !== PS_REFUND_STATUS_APPROVED) {
            throw new RuntimeException('This refund request cannot be processed.');
        }

        $saleBranch = trim((string)($reqRow['branch'] ?? ''));
        if (!psRefundBranchAccess($conn, $saleBranch)) {
            throw new RuntimeException('You do not have access to this branch.');
        }

        $reason = strtoupper(trim((string)($reqRow['reason'] ?? '')));
        $reasonDescription = trim((string)($reqRow['reason_description'] ?? ''));
        $reasonCheck = psNormalizeRefundReason($reason);
        if (empty($reasonCheck['ok'])) {
            throw new RuntimeException('The refund could not be saved.');
        }
        $descCheck = psValidateRefundReasonDescription($reasonDescription, $reason);
        if (empty($descCheck['ok'])) {
            throw new RuntimeException($descCheck['error'] ?? 'The refund could not be saved.');
        }

        $mode = strtoupper(trim((string)($reqRow['mode'] ?? '')));
        $headerInvoice = trim((string)($reqRow['invoice_number'] ?? ''));
        $refundNumber = trim((string)($reqRow['refund_number'] ?? ''));
        $requestedAmount = number_format((float)($reqRow['requested_amount'] ?? 0), 2, '.', '');
        if ($refundNumber === '' || $headerInvoice === '') {
            throw new RuntimeException('The refund could not be saved.');
        }

        $reqLines = psRefundFetchRequestLines($conn, $requestId);
        if (!$reqLines) {
            throw new RuntimeException('The refund request has no lines.');
        }

        // Lock + revalidate every approved line against live remaining (no auto-shrink).
        $pairs = [];
        foreach ($reqLines as $rl) {
            $sid = (int)$rl['sale_line_id'];
            $approvedQty = psStockDecimal((string)$rl['requested_qty']);
            if ($approvedQty === null || psStockDecimalCmp($approvedQty, '0') <= 0) {
                throw new RuntimeException('Refund quantity must be greater than zero.');
            }
            $qInfo = psGetRefundableSaleLineQuantity($conn, $sid, true);
            if (empty($qInfo['ok'])) {
                throw new RuntimeException($qInfo['error'] ?? 'The sale changed and this refund cannot be processed.');
            }
            $line = $qInfo['line'];
            if (trim((string)($line['branch'] ?? '')) !== $saleBranch) {
                throw new RuntimeException('The sale changed and this refund cannot be processed.');
            }
            if (trim((string)($line['invoice_number'] ?? '')) !== $headerInvoice) {
                throw new RuntimeException('The sale changed and this refund cannot be processed.');
            }
            $remaining = (string)$qInfo['remaining'];
            // Stale example: approved 7, another processed 5, remaining 5 → process of 7 FAILS.
            if (psStockDecimalCmp($approvedQty, $remaining) > 0) {
                throw new RuntimeException(
                    'Approved quantity is no longer available. Remaining refundable quantity is insufficient.'
                );
            }
            $line['_sold'] = (string)$qInfo['original_qty'];
            $line['_previously_refunded'] = (string)$qInfo['previously_refunded'];
            $line['_remaining'] = $remaining;
            $pairs[] = ['line' => $line, 'quantity' => $approvedQty, 'req_line' => $rl];
        }

        $amt = psRefundComputeAmountForLineQtys($pairs);
        if (empty($amt['ok'])) {
            throw new RuntimeException($amt['error'] ?? 'The refund amount could not be determined.');
        }
        $refundAmount = number_format((float)$amt['amount'], 2, '.', '');
        if ($refundAmount !== $requestedAmount) {
            throw new RuntimeException('The sale changed and this refund cannot be processed.');
        }

        $payMethod = psCashMovementSalePaymentMethod($conn, $headerInvoice);
        if (empty($payMethod['ok'])) {
            throw new RuntimeException('The refund could not be saved.');
        }
        $methodKnown = empty($payMethod['unknown']);
        $method = $methodKnown ? strtoupper(trim((string)($payMethod['method'] ?? ''))) : '';
        $isCashRefund = $methodKnown && $method === 'CASH';
        $auditMethod = $methodKnown ? $method : 'UNKNOWN';

        $tillSessionId = 0;
        if ($isCashRefund) {
            $tillLock = tillLockSaleSession($conn, 0);
            if (!empty($tillLock['error'])) {
                throw new RuntimeException($tillLock['error']);
            }
            if ((string)$tillLock['branch'] !== $saleBranch) {
                throw new RuntimeException(
                    'Your till is open on ' . (string)$tillLock['branch']
                    . '. Cash refunds for this sale require a till open on ' . $saleBranch
                    . '. Close that till and open one on ' . $saleBranch . ' before continuing.'
                );
            }
            $tillSessionId = (int)$tillLock['session']['id'];
        }

        $markFull = mysqli_prepare(
            $conn,
            "UPDATE online_sales SET status = 'refunded' WHERE id = ? AND status <> 'refunded'"
        );
        if (!$markFull) {
            throw new RuntimeException('The refund could not be saved.');
        }

        $movementAt = date('Y-m-d H:i:s');
        $movementIds = [];
        $cashMovementId = null;
        $lineSummaryParts = $amt['line_summaries'] ?? [];

        foreach ($pairs as $pair) {
            $line = $pair['line'];
            $quantity = (string)$pair['quantity'];
            $id = (int)$line['id'];
            $productId = trim((string)($line['product_id'] ?? ''));
            $branch = trim((string)($line['branch'] ?? ''));

            $recorded = recordStockMovement($conn, [
                'product_id' => $productId,
                'branch' => $branch,
                'movement_type' => 'REFUND',
                'quantity' => $quantity,
                'reference_type' => 'REFUND',
                'reference_id' => $refundNumber,
                'movement_at' => $movementAt,
                'user_email' => $actorEmail,
                'notes' => 'Sale line ' . $id . ' invoice ' . $headerInvoice . ' qty ' . $quantity,
            ]);
            if (empty($recorded['ok'])) {
                mysqli_stmt_close($markFull);
                throw new RuntimeException('The refund could not be saved.');
            }
            $movementIds[] = (int)$recorded['id'];

            // Mark status=refunded only when this process exhausts remaining (fully refunded).
            $prev = (string)($line['_previously_refunded'] ?? '0.000');
            $orig = (string)($line['_sold'] ?? $line['quantity']);
            $after = '0.000';
            if (function_exists('bcadd')) {
                $after = bcadd($prev, $quantity, 3);
            } else {
                $after = number_format((float)$prev + (float)$quantity, 3, '.', '');
            }
            if (psStockDecimalCmp($after, $orig) >= 0) {
                mysqli_stmt_bind_param($markFull, 'i', $id);
                if (!mysqli_stmt_execute($markFull)) {
                    mysqli_stmt_close($markFull);
                    throw new RuntimeException('The refund could not be saved.');
                }
            }
        }
        mysqli_stmt_close($markFull);

        // Mark request PROCESSED before remaining re-read so header/full checks include this consume.
        // Phase 12: processed_tax_amount from request-line snapshots (stored original tax only).
        require_once __DIR__ . '/tax.php';
        psEnsureSaleTaxColumns($conn);
        $processedTaxCents = 0;
        $hasCompleteTax = false;
        foreach (psRefundFetchRequestLines($conn, $requestId) as $rlTax) {
            if (strtoupper(trim((string)($rlTax['tax_data_status'] ?? ''))) === PS_TAX_STATUS_COMPLETE
                && isset($rlTax['tax_amount_snapshot']) && $rlTax['tax_amount_snapshot'] !== null && $rlTax['tax_amount_snapshot'] !== '') {
                $tc = psDecimalCents((string)$rlTax['tax_amount_snapshot']);
                if ($tc !== null) {
                    $processedTaxCents += $tc;
                    $hasCompleteTax = true;
                }
            }
        }
        $processedTaxSql = $hasCompleteTax ? psMoneyFromCents($processedTaxCents) : null;
        $newStatus = PS_REFUND_STATUS_PROCESSED;
        if ($processedTaxSql !== null) {
            $updReq = mysqli_prepare(
                $conn,
                "UPDATE sale_refund_requests
                 SET status = ?, processor = ?, processed_at = ?, processed_amount = ?, processed_tax_amount = ?
                 WHERE id = ? AND status = 'APPROVED'"
            );
            if (!$updReq) {
                throw new RuntimeException('The refund could not be saved.');
            }
            mysqli_stmt_bind_param(
                $updReq,
                'sssssi',
                $newStatus,
                $actorEmail,
                $movementAt,
                $refundAmount,
                $processedTaxSql,
                $requestId
            );
        } else {
            $updReq = mysqli_prepare(
                $conn,
                "UPDATE sale_refund_requests
                 SET status = ?, processor = ?, processed_at = ?, processed_amount = ?
                 WHERE id = ? AND status = 'APPROVED'"
            );
            if (!$updReq) {
                throw new RuntimeException('The refund could not be saved.');
            }
            mysqli_stmt_bind_param($updReq, 'ssssi', $newStatus, $actorEmail, $movementAt, $refundAmount, $requestId);
        }
        if (!mysqli_stmt_execute($updReq) || mysqli_stmt_affected_rows($updReq) !== 1) {
            mysqli_stmt_close($updReq);
            throw new RuntimeException('This refund has already been processed.');
        }
        mysqli_stmt_close($updReq);

        // Header status=refunded only when every invoice line remaining is 0 (after this PROCESS).
        $stillRemaining = false;
        $scanStmt = mysqli_prepare(
            $conn,
            'SELECT id FROM online_sales WHERE invoice_number = ? ORDER BY id ASC'
        );
        if ($scanStmt) {
            mysqli_stmt_bind_param($scanStmt, 's', $headerInvoice);
            if (mysqli_stmt_execute($scanStmt)) {
                $scanRes = mysqli_stmt_get_result($scanStmt);
                while ($scanRes && ($sr = mysqli_fetch_assoc($scanRes))) {
                    $qq = psGetRefundableSaleLineQuantity($conn, (int)$sr['id'], false);
                    if (!empty($qq['ok']) && psStockDecimalCmp((string)$qq['remaining'], '0') > 0) {
                        $stillRemaining = true;
                        break;
                    }
                }
            }
            mysqli_stmt_close($scanStmt);
        }
        if (!$stillRemaining) {
            $ref = mysqli_prepare(
                $conn,
                "UPDATE online_sales_ref SET status = 'refunded' WHERE invoice_number = ?"
            );
            if ($ref) {
                mysqli_stmt_bind_param($ref, 's', $headerInvoice);
                mysqli_stmt_execute($ref);
                mysqli_stmt_close($ref);
            }
        }

        if ($isCashRefund) {
            if ($tillSessionId < 1) {
                throw new RuntimeException('The cash refund amount could not be determined.');
            }
            // One CASH_REFUND per processed request; UNIQUE via REFUND + REF-###### (allows multiple partials).
            $cashRefType = PS_CASH_REF_REFUND;
            $cashRefId = $refundNumber;
            $cashDesc = 'Cash refund ' . $refundNumber . ' invoice ' . $headerInvoice;
            // Multi-currency: cash goes back in the sale's own currency at the sale's own rate.
            $cashPost = psCashMovementPostCashRefund(
                $conn,
                $tillSessionId,
                $saleBranch,
                $refundAmount,
                $cashRefType,
                $cashRefId,
                $actorEmail,
                $cashDesc,
                $movementAt,
                psFxSaleSnapshot($conn, $headerInvoice)
            );
            if (empty($cashPost['ok'])) {
                if (!empty($cashPost['duplicate'])) {
                    throw new RuntimeException('This cash refund was already recorded.');
                }
                throw new RuntimeException('The refund could not be saved.');
            }
            $cashMovementId = (int)($cashPost['id'] ?? 0);
        }

        if ($auditMethod === 'CREDIT' && psRefundCustomerAccountLedgerEnabled()) {
            // Reserved for a later module.
        }

        $audit = psSaleRefundAuditInsert($conn, [
            'refund_number' => $refundNumber,
            'invoice_number' => $headerInvoice,
            'mode' => $mode !== '' ? $mode : PS_REFUND_MODE_LINES,
            'branch' => $saleBranch,
            'payment_method' => $auditMethod,
            'amount' => $refundAmount,
            'reason' => $reason,
            'reason_description' => $reasonDescription,
            'line_summary' => implode(';', $lineSummaryParts),
            'stock_movement_ids' => implode(',', array_map('strval', $movementIds)),
            'cash_movement_id' => $cashMovementId,
            'till_session_id' => $tillSessionId > 0 ? $tillSessionId : null,
            'created_by' => $actorEmail,
            'source' => $source,
            'event_type' => PS_REFUND_EVENT_PROCESSING,
            'request_id' => $requestId,
            'event_note' => '',
        ]);
        if (empty($audit['ok'])) {
            throw new RuntimeException(!empty($audit['duplicate'])
                ? 'This refund was already recorded.'
                : 'The refund could not be saved.');
        }

        // Phase 17 — ZIMRA credit note for exactly the processed lines/quantities (same transaction,
        // no network). Skipped when the original sale was never fiscalised (legacy / no device).
        require_once __DIR__ . '/zimra.php';
        $zimraNote = trim(psRefundReasonLabel($reason) . ($reasonDescription !== '' ? ': ' . $reasonDescription : ''));
        $zimraCredit = psZimraIssueForRefund($conn, $refundNumber, $headerInvoice, $saleBranch, $pairs, $zimraNote, $actorEmail);
        if (empty($zimraCredit['ok'])) {
            throw new RuntimeException((string)($zimraCredit['error'] ?? 'The refund could not be saved.'));
        }

        if ($forceFail) {
            throw new RuntimeException('The refund could not be saved.');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The refund could not be saved.');
        }
        if ($owns && !empty($zimraCredit['device_row_id'])) {
            psZimraAfterCommit($conn, (int)$zimraCredit['device_row_id'], $actorEmail);
        }
        return [
            'zimra_receipt_row_id' => $zimraCredit['receipt_row_id'] ?? null,
            'ok' => true,
            'request_id' => $requestId,
            'refund_number' => $refundNumber,
            'refunded_lines' => count($pairs),
            'movement_ids' => $movementIds,
            'cash_movement_id' => $cashMovementId,
            'cash_refund' => $isCashRefund,
            'amount' => $refundAmount,
            'payment_method' => $auditMethod,
            'reason' => $reason,
            'status' => $newStatus,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        $message = $e->getMessage();
        if ($message === 'The refund could not be saved.') {
            error_log('refund: process failed');
        }
        if ($message === 'Please open a till session before completing a sale.') {
            $message = 'Please open a till session before processing a cash refund.';
        }
        return ['ok' => false, 'error' => psRefundSafeError($message)];
    }
}

/**
 * Legacy name — Phase 8.2 requires an approved request_id. Immediate unauthorised commit is rejected.
 *
 * @param array<string,mixed> $request
 */
function psProcessRefund(mysqli $conn, array $request, bool $manageTransaction = true): array
{
    $requestId = (int)($request['request_id'] ?? 0);
    if ($requestId < 1) {
        return [
            'ok' => false,
            'error' => 'Refund processing requires an approved refund request.',
        ];
    }
    return psProcessApprovedRefund($conn, $request, $manageTransaction);
}

/**
 * Minimal request history for refund.php (not Phase 8.6 reporting).
 *
 * @return list<array<string,mixed>>
 */
function psRefundListRecentRequests(mysqli $conn, int $limit = 40): array
{
    if (!psEnsureSaleRefundRequestsSchema($conn)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $mayApprove = psRefundMayApprove();
    $actor = psSessionRefundUser();
    $rows = [];

    if ($mayApprove) {
        $sql = 'SELECT * FROM sale_refund_requests ORDER BY id DESC LIMIT ' . (int)$limit;
        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (psRefundBranchAccess($conn, trim((string)($row['branch'] ?? '')))) {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM sale_refund_requests WHERE requester = ? ORDER BY id DESC LIMIT ' . (int)$limit
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $actor);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * @return array{ok:bool,request?:array,error?:string}
 */
function psRefundGetRequest(mysqli $conn, int $requestId): array
{
    if ($requestId < 1 || !psEnsureSaleRefundRequestsSchema($conn)) {
        return ['ok' => false, 'error' => 'The refund request was not found.'];
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM sale_refund_requests WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The refund request was not found.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'error' => 'The refund request was not found.'];
    }
    $lines = psRefundFetchRequestLines($conn, $requestId);
    foreach ($lines as &$rl) {
        $q = psGetRefundableSaleLineQuantity($conn, (int)$rl['sale_line_id'], false);
        $rl['_remaining'] = !empty($q['ok']) ? (string)$q['remaining'] : null;
        $rl['_original_qty'] = !empty($q['ok']) ? (string)$q['original_qty'] : null;
        $rl['_previously_refunded'] = !empty($q['ok']) ? (string)$q['previously_refunded'] : null;
        if (!empty($q['ok']) && !empty($q['line']['product'])) {
            $rl['_product'] = (string)$q['line']['product'];
        } elseif (!empty($q['ok']) && !empty($q['line']['product_id'])) {
            $rl['_product'] = (string)$q['line']['product_id'];
        } else {
            $rl['_product'] = (string)($rl['product_id'] ?? '');
        }
        $unit = $rl['unit_price_snapshot'] ?? null;
        if (($unit === null || $unit === '') && !empty($q['ok'])) {
            $unit = $q['line']['unit_price'] ?? null;
        }
        $rl['_unit_price'] = $unit;
        $expectedCents = null;
        if (!empty($q['ok'])) {
            $expectedCents = psRefundLineAmountCents($q['line'], (string)$rl['requested_qty']);
        }
        $rl['_expected_amount'] = $expectedCents !== null ? psMoneyFromCents($expectedCents) : (string)($rl['line_amount_snapshot'] ?? '');
    }
    unset($rl);
    $row['lines'] = $lines;
    return ['ok' => true, 'request' => $row];
}

function psRefundSafeError(string $message): string
{
    $safe = [
        'The item was not found.',
        'The receipt was not found.',
        'This item has already been refunded.',
        'This receipt has already been refunded.',
        'The refund could not be saved.',
        'Please sign in before processing a refund.',
        'Please sign in before requesting a refund.',
        'Please sign in before processing a cash refund.',
        'The working branch does not match the sale branch.',
        'The till session branch does not match the sale branch.',
        'Please open a till session before completing a sale.',
        'Please open a till session before processing a cash refund.',
        'Your till session is no longer open. Please reopen the till.',
        "You cannot access another cashier's till session.",
        'The cash refund amount could not be determined.',
        'The refund amount could not be determined.',
        'This cash refund was already recorded.',
        'This refund was already recorded.',
        'Could not prepare the till session.',
        'Please sign in again.',
        'A refund reason is required.',
        'Choose a valid refund reason.',
        'A description is required when the reason is Other.',
        'Enter a meaningful description for Other.',
        'Description is too long.',
        'Refund quantity is not valid.',
        'Refund quantity must be greater than zero.',
        'Refund quantity cannot exceed the sold quantity.',
        'Refund quantity cannot exceed the remaining refundable quantity.',
        'Partial quantity refunds are not supported.',
        'You are not authorised to approve refunds.',
        'You are not authorised to reject refunds.',
        'You cannot approve your own refund request.',
        'You do not have access to this branch.',
        'The refund request was not found.',
        'The refund request could not be updated.',
        'This refund request is already approved.',
        'This refund request was rejected and cannot be approved.',
        'This refund request has already been processed.',
        'This refund request cannot be approved.',
        'This refund request could not be approved.',
        'This refund request is already rejected.',
        'This refund request is already approved and cannot be rejected.',
        'This refund request cannot be rejected.',
        'This refund request could not be rejected.',
        'This refund request is still pending approval.',
        'This refund request was rejected and cannot be processed.',
        'This refund has already been processed.',
        'This refund request cannot be processed.',
        'The sale changed and this refund cannot be processed.',
        'A refund request is already pending or approved for this sale.',
        'Refund processing requires an approved refund request.',
        'Approval note is required.',
        'Rejection note is required.',
        'Enter a meaningful approval note.',
        'Enter a meaningful rejection note.',
        'Approval note is too long.',
        'Rejection note is too long.',
        'The refund request has no lines.',
        'Select at least one refund line.',
        'Duplicate sale lines are not allowed on one refund request.',
        'All refund lines must belong to the same sale.',
        'Refund line data is not valid.',
        'Approved quantity is no longer available. Remaining refundable quantity is insufficient.',
        'The sale changed and this refund cannot be approved.',
    ];
    if (in_array($message, $safe, true)) {
        return $message;
    }
    // Phase 17 — ZIMRA fiscal guidance (no secrets; written in includes/zimra*.php).
    if (stripos($message, 'fiscal') !== false || stripos($message, 'ZIMRA') !== false) {
        return $message;
    }
    // Clear till/branch guidance from tillAlign / cash-refund gate (no secrets).
    if (strpos($message, 'Your till is open on ') === 0
        || strpos($message, 'Your open till branch (') === 0
        || strpos($message, 'This till has no branch assigned') === 0
        || strpos($message, 'Could not align the working branch') === 0
    ) {
        return $message;
    }
    return 'The refund could not be saved.';
}
