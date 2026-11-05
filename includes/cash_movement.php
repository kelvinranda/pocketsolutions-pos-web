<?php
/**
 * Phase 7.1 — Cash Movement & Till Ledger Foundation.
 * Phase 7.3 — Cash-In / Cash-Out Controls (controlled reasons, ownership, UI).
 *
 * Authoritative cash-in-till ledger: cash_movements (append-only).
 * No cached running balance column — derive with psCashMovementBalance().
 *
 * Movement types (controlled ENUM; direction via type, never negative amounts):
 *   OPENING_FLOAT  ↑  till open float (only when opening cash > 0)
 *   CASH_SALE      ↑  production POS CASH tender (sale total net of change)
 *   CASH_REFUND    ↓  physical cash returned on a CASH-tendered sale refund
 *   CASH_IN        ↑  manual cash in (authorised open till)
 *   CASH_OUT       ↓  manual cash out (authorised open till)
 *
 * Balance for a till session:
 *   Opening + Sales + Cash In − Refunds − Cash Out
 *
 * Sources of truth:
 *   - cash_movements is authoritative for cash calculations (Phase 7.1+).
 *   - sessions.opening_float remains the till-open metadata snapshot written at open;
 *     when opening cash > 0, exactly one OPENING_FLOAT row matches that snapshot.
 *     Do not derive till cash from sessions.opening_float alone after open.
 *
 * Phase 7.3 manual CASH_IN / CASH_OUT:
 *   - Movement type stays CASH_IN | CASH_OUT; reason is a controlled code (+ optional description).
 *   - OTHER requires a meaningful non-whitespace description.
 *   - Refs remain server CIN-###### / COUT-###### (users cannot supply refs).
 *   - Posted user / cashier / branch / till / session / balance / reference ignored.
 *   - CASH_OUT is NOT auto-blocked when expected cash would go negative (no min-cash policy).
 *   - Post-reconciliation: later IN/OUT allowed while till remains open; historical recon immutable.
 *   - No edit/delete after post; corrections = future reversal module.
 *
 * Boundaries:
 *   - No CARD / ECOCASH / BANK_TRANSFER / CREDIT cash movements from sale tenders.
 *   - Phase 6.3 customer_account_payments do NOT post here (till integration still disabled).
 *   - No till closure / deposits / GL / supplier payments / expense accounting / approvals.
 *   - Reason labels (e.g. EXPENSE) are operational only — not GL expense postings.
 *
 * Permissions: session logger required. Operational posts require the caller's own
 * OPEN active till session matching the authenticated working branch. No elevated role
 * invented — same gate as POS sales (logger + own open till). History: non-admin
 * working-branch / own session scope; admin per existing branch-security model.
 */

require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/currency.php';

const PS_CASH_MOV_OPENING_FLOAT = 'OPENING_FLOAT';
const PS_CASH_MOV_CASH_SALE = 'CASH_SALE';
const PS_CASH_MOV_CASH_REFUND = 'CASH_REFUND';
const PS_CASH_MOV_CASH_IN = 'CASH_IN';
const PS_CASH_MOV_CASH_OUT = 'CASH_OUT';

const PS_CASH_REF_TILL_SESSION = 'TILL_SESSION';
const PS_CASH_REF_SALE = 'SALE';
const PS_CASH_REF_REFUND = 'REFUND';
const PS_CASH_REF_REFUND_LINE = 'REFUND_LINE';
const PS_CASH_REF_CASH_IN = 'CASH_IN';
const PS_CASH_REF_CASH_OUT = 'CASH_OUT';

const PS_CASH_AUDIT_CREATE = 'CREATE';
const PS_CASH_AUDIT_SOURCE_UI = 'UI';
const PS_CASH_AUDIT_SOURCE_SYSTEM = 'SYSTEM';

/** Phase 7.3 — controlled CASH_IN reason codes (operational labels only; not GL). */
const PS_CASH_REASON_FLOAT_ADJUSTMENT = 'FLOAT_ADJUSTMENT';
const PS_CASH_REASON_CASH_TRANSFER_IN = 'CASH_TRANSFER_IN';
const PS_CASH_REASON_MANAGEMENT_CASH_IN = 'MANAGEMENT_CASH_IN';
const PS_CASH_REASON_OTHER = 'OTHER';

/** Phase 7.3 — controlled CASH_OUT reason codes (operational labels only; not GL expense). */
const PS_CASH_REASON_PETTY_CASH = 'PETTY_CASH';
const PS_CASH_REASON_CASH_TRANSFER_OUT = 'CASH_TRANSFER_OUT';
const PS_CASH_REASON_MANAGEMENT_CASH_OUT = 'MANAGEMENT_CASH_OUT';
const PS_CASH_REASON_EXPENSE = 'EXPENSE';

/**
 * Controlled movement types → direction (IN = cash into till, OUT = cash out of till).
 *
 * @return array<string,string>
 */
function psCashMovementTypes(): array
{
    return [
        PS_CASH_MOV_OPENING_FLOAT => 'IN',
        PS_CASH_MOV_CASH_SALE => 'IN',
        PS_CASH_MOV_CASH_REFUND => 'OUT',
        PS_CASH_MOV_CASH_IN => 'IN',
        PS_CASH_MOV_CASH_OUT => 'OUT',
    ];
}

function psCashMovementTypeLabel(string $type): string
{
    $labels = [
        PS_CASH_MOV_OPENING_FLOAT => 'Opening float',
        PS_CASH_MOV_CASH_SALE => 'Cash sale',
        PS_CASH_MOV_CASH_REFUND => 'Cash refund',
        PS_CASH_MOV_CASH_IN => 'Cash in',
        PS_CASH_MOV_CASH_OUT => 'Cash out',
    ];
    $type = strtoupper(trim($type));
    return $labels[$type] ?? $type;
}

function psCashMovementDirection(string $type): string
{
    $types = psCashMovementTypes();
    $type = strtoupper(trim($type));
    return $types[$type] ?? '';
}

function psNormalizeCashMovementType($raw): array
{
    $type = strtoupper(trim((string)$raw));
    if (!isset(psCashMovementTypes()[$type])) {
        return ['ok' => false, 'error' => 'Choose a valid cash movement type.'];
    }
    return ['ok' => true, 'value' => $type];
}

/**
 * Controlled CASH_IN reason codes (Phase 7.3). Operational labels only — not accounting treatments.
 *
 * @return list<string>
 */
function psCashMovementCashInReasons(): array
{
    return [
        PS_CASH_REASON_FLOAT_ADJUSTMENT,
        PS_CASH_REASON_CASH_TRANSFER_IN,
        PS_CASH_REASON_MANAGEMENT_CASH_IN,
        PS_CASH_REASON_OTHER,
    ];
}

/**
 * Controlled CASH_OUT reason codes (Phase 7.3). EXPENSE is an operational label only — not GL expense.
 *
 * @return list<string>
 */
function psCashMovementCashOutReasons(): array
{
    return [
        PS_CASH_REASON_PETTY_CASH,
        PS_CASH_REASON_CASH_TRANSFER_OUT,
        PS_CASH_REASON_MANAGEMENT_CASH_OUT,
        PS_CASH_REASON_EXPENSE,
        PS_CASH_REASON_OTHER,
    ];
}

/**
 * @return list<string>
 */
function psCashMovementReasonsForType(string $movementType): array
{
    $movementType = strtoupper(trim($movementType));
    if ($movementType === PS_CASH_MOV_CASH_IN) {
        return psCashMovementCashInReasons();
    }
    if ($movementType === PS_CASH_MOV_CASH_OUT) {
        return psCashMovementCashOutReasons();
    }
    return [];
}

function psCashMovementReasonLabel(string $reason): string
{
    $labels = [
        PS_CASH_REASON_FLOAT_ADJUSTMENT => 'Float adjustment',
        PS_CASH_REASON_CASH_TRANSFER_IN => 'Cash transfer in',
        PS_CASH_REASON_MANAGEMENT_CASH_IN => 'Management cash in',
        PS_CASH_REASON_PETTY_CASH => 'Petty cash',
        PS_CASH_REASON_CASH_TRANSFER_OUT => 'Cash transfer out',
        PS_CASH_REASON_MANAGEMENT_CASH_OUT => 'Management cash out',
        PS_CASH_REASON_EXPENSE => 'Expense (operational)',
        PS_CASH_REASON_OTHER => 'Other',
    ];
    $key = strtoupper(trim($reason));
    return $labels[$key] ?? $reason;
}

/**
 * Validate controlled reason for CASH_IN / CASH_OUT. Empty / unknown rejected.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeCashMovementManualReason($raw, string $movementType): array
{
    $reason = strtoupper(trim((string)$raw));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A reason is required.'];
    }
    $allowed = psCashMovementReasonsForType($movementType);
    if ($allowed === [] || !in_array($reason, $allowed, true)) {
        return ['ok' => false, 'error' => 'Choose a valid cash movement reason.'];
    }
    return ['ok' => true, 'value' => $reason];
}

/**
 * Optional description; OTHER requires meaningful non-whitespace text.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateCashMovementManualDescription($raw, string $reasonCode, bool $requiredForOther = true): array
{
    $description = trim((string)$raw);
    if (strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long.'];
    }
    $reasonCode = strtoupper(trim($reasonCode));
    if ($requiredForOther && $reasonCode === PS_CASH_REASON_OTHER) {
        if ($description === '') {
            return ['ok' => false, 'error' => 'A description is required when the reason is Other.'];
        }
        // Reject whitespace-only / trivial placeholders after trim (already trimmed).
        if (preg_match('/^\.+$/', $description) || preg_match('/^-{2,}$/', $description)) {
            return ['ok' => false, 'error' => 'Enter a meaningful description for Other.'];
        }
        if (strlen($description) < 3) {
            return ['ok' => false, 'error' => 'Enter a meaningful description for Other.'];
        }
    }
    return ['ok' => true, 'value' => $description];
}

/**
 * Explicit: CASH_OUT is not blocked solely because expected cash would go negative.
 * No min-cash / overdraft policy is defined in Phase 7.3.
 */
function psCashMovementBlockNegativeExpectedOnCashOutEnabled(): bool
{
    return false;
}

function psCashMovementIsAdmin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Money > 0, max 2 decimal places. Rejects zero and negative.
 * Uses string validation (not float money authority).
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateCashMovementMoney($raw, string $label = 'Amount'): array
{
    if ($raw === null) {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    $raw = str_replace(',', '', $raw);
    if ($raw !== '' && ($raw[0] === '+' || $raw[0] === '-')) {
        if ($raw[0] === '-') {
            return ['ok' => false, 'error' => $label . ' cannot be negative.'];
        }
        return ['ok' => false, 'error' => $label . ' must be a number.'];
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
    $normalised = number_format((float)$raw, 2, '.', '');
    if ((float)$normalised <= 0) {
        return ['ok' => false, 'error' => $label . ' must be greater than zero.'];
    }
    return ['ok' => true, 'value' => $normalised];
}

function psSessionCashMovementUser(): string
{
    return trim((string)($_SESSION['logger'] ?? ''));
}

/**
 * Dedicated counter for manual CASH_IN / CASH_OUT reference numbers (CIN-/COUT-######).
 */
function psEnsureCashMovementSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS cash_movement_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('cash_movement_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM cash_movement_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO cash_movement_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('cash_movement_seq was not seeded');
    return false;
}

function psEnsureCashMovementsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureCashMovementSeq($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM cash_movements LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS cash_movements (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            till_session_id INT NOT NULL,
            branch VARCHAR(250) NOT NULL,
            movement_at DATETIME NOT NULL,
            movement_type VARCHAR(32) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            reference_type VARCHAR(64) NULL,
            reference_id VARCHAR(250) NULL,
            reason VARCHAR(500) NULL,
            description VARCHAR(500) NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_cash_mov_ref_entry (reference_type, reference_id, movement_type),
            KEY idx_cash_mov_session (till_session_id),
            KEY idx_cash_mov_branch (branch),
            KEY idx_cash_mov_at (movement_at),
            KEY idx_cash_mov_type (movement_type),
            KEY idx_cash_mov_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('cash_movements schema was not created');
            return false;
        }
    }
    // Extra guard: at most one OPENING_FLOAT per till session (NULL-safe generated key).
    if (!tillHasColumn($conn, 'cash_movements', 'opening_guard')) {
        $added = mysqli_query($conn, "
            ALTER TABLE cash_movements
            ADD COLUMN opening_guard VARCHAR(64)
            GENERATED ALWAYS AS (
                IF(movement_type = 'OPENING_FLOAT', CONCAT('OF:', till_session_id), NULL)
            ) PERSISTENT
        ");
        if (!$added && mysqli_errno($conn) !== 1060) {
            // MariaDB PERSISTENT vs MySQL STORED — try STORED.
            $added = mysqli_query($conn, "
                ALTER TABLE cash_movements
                ADD COLUMN opening_guard VARCHAR(64)
                GENERATED ALWAYS AS (
                    IF(movement_type = 'OPENING_FLOAT', CONCAT('OF:', till_session_id), NULL)
                ) STORED
            ");
            if (!$added && mysqli_errno($conn) !== 1060) {
                error_log('cash_movements opening_guard column was not added');
                // Continue without generated column — app + uniq_cash_mov_ref_entry still protect.
            }
        }
    }
    if (tillHasColumn($conn, 'cash_movements', 'opening_guard') && !tillHasIndex($conn, 'cash_movements', 'uniq_cash_mov_opening')) {
        $indexed = mysqli_query($conn, 'ALTER TABLE cash_movements ADD UNIQUE KEY uniq_cash_mov_opening (opening_guard)');
        if (!$indexed && mysqli_errno($conn) !== 1061) {
            error_log('cash_movements opening unique was not added');
        }
    }
    $ready = true;
    return true;
}

/**
 * Append-only audit for manual CASH_IN / CASH_OUT (and system posts when recorded).
 * No UPDATE/DELETE helpers.
 */
function psEnsureCashMovementAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM cash_movement_audit LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS cash_movement_audit (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cash_movement_id INT NOT NULL,
            till_session_id INT NOT NULL,
            branch VARCHAR(250) NOT NULL,
            action VARCHAR(32) NOT NULL,
            field_name VARCHAR(64) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            changed_by VARCHAR(250) NOT NULL DEFAULT '',
            changed_at DATETIME NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'UI',
            KEY idx_cash_mov_audit_movement (cash_movement_id),
            KEY idx_cash_mov_audit_session (till_session_id),
            KEY idx_cash_mov_audit_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('cash_movement_audit schema was not created');
            return false;
        }
    }
    $ready = true;
    return true;
}

function psEnsureCashMovementModule(mysqli $conn): bool
{
    // Multi-currency (2026-10-20): currency + rate snapshot columns. DDL — this runs before callers open transactions.
    require_once __DIR__ . '/currency.php';
    return psEnsureCashMovementsSchema($conn) && psEnsureCashMovementAuditSchema($conn) && psFxEnsureTransactionColumns($conn);
}

/**
 * Currency + snapshot for a cash row. Posted 'currency' (blank = the till company's base) must be enabled for the company.
 * A caller may pass the sale's own 'fx' snapshot so the cash row matches the sale exactly.
 *
 * @return array{ok:bool,snap?:array,base_cents?:int,error?:string}
 */
function psCashMovementCurrencySnapshot(mysqli $conn, int $tillSessionId, array $data, string $amount, string $at): array
{
    require_once __DIR__ . '/currency.php';
    $company = psFxTillCompany($conn, $tillSessionId);
    $base = psFxBase($conn, $company);
    $snap = $data['fx'] ?? null;
    if (!is_array($snap) || empty($snap['currency'])) {
        $code = psFxNormalizeCode($data['currency'] ?? '') ?: $base;
        if ($code !== $base) {
            $chk = psFxCheckTransactionCurrency($conn, $company, $code);
            if (empty($chk['ok'])) {
                return ['ok' => false, 'error' => (string)$chk['error']];
            }
        }
        $snap = psFxSnapshot($conn, $company, $code, $at);
        if (empty($snap['ok'])) {
            return ['ok' => false, 'error' => (string)$snap['error']];
        }
    }
    $cents = psFxMoneyToCents($amount);
    if ($cents === null) {
        return ['ok' => false, 'error' => 'Amount is not valid.'];
    }
    return ['ok' => true, 'snap' => $snap, 'base_cents' => psFxToBaseCents($conn, $snap, $cents)];
}

/**
 * Allocate CIN-###### or COUT-###### inside an open transaction.
 *
 * @return array{ok:bool,number?:string,error?:string}
 */
function psAllocateCashMovementManualNumber(mysqli $conn, string $prefix): array
{
    $prefix = strtoupper(trim($prefix));
    if ($prefix !== 'CIN' && $prefix !== 'COUT') {
        return ['ok' => false, 'error' => 'Cash movement reference could not be allocated.'];
    }
    if (!psEnsureCashMovementSeq($conn)) {
        return ['ok' => false, 'error' => 'Cash movement reference could not be allocated.'];
    }
    $lock = mysqli_query($conn, 'SELECT next_number FROM cash_movement_seq WHERE id = 1 FOR UPDATE');
    if (!$lock || !($row = mysqli_fetch_assoc($lock))) {
        error_log('cash_movement_seq lock failed');
        return ['ok' => false, 'error' => 'Cash movement reference could not be allocated.'];
    }
    $next = (int)$row['next_number'];
    if ($next < 1) {
        $next = 1;
    }
    $upd = mysqli_query($conn, 'UPDATE cash_movement_seq SET next_number = ' . ($next + 1) . ' WHERE id = 1');
    if (!$upd) {
        error_log('cash_movement_seq update failed');
        return ['ok' => false, 'error' => 'Cash movement reference could not be allocated.'];
    }
    return ['ok' => true, 'number' => $prefix . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT)];
}

function psCashMovementAuditInsert(
    mysqli $conn,
    int $movementId,
    int $tillSessionId,
    string $branch,
    string $action,
    string $fieldName,
    $oldValue,
    $newValue,
    string $changedBy,
    string $changedAt,
    string $source
): bool {
    if (!psEnsureCashMovementAuditSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO cash_movement_audit
         (cash_movement_id, till_session_id, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('cash_movement_audit prepare failed');
        return false;
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    mysqli_stmt_bind_param(
        $stmt,
        'iissssssss',
        $movementId,
        $tillSessionId,
        $branch,
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
        error_log('cash_movement_audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Reject when the session's register is explicitly INACTIVE (Phase 7.3).
 *
 * @return array{ok:bool,error?:string}
 */
function psCashMovementAssertRegisterActive(mysqli $conn, array $session): array
{
    $registerId = (int)($session['register_id'] ?? 0);
    if ($registerId < 1) {
        // Unassigned register: session OPEN + ownership still required; no inactive register row.
        return ['ok' => true];
    }
    $stmt = mysqli_prepare($conn, 'SELECT id, status FROM registers WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not prepare the till session.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $registerId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'error' => 'This till is inactive or unavailable.'];
    }
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    if ($status !== 'ACTIVE') {
        return ['ok' => false, 'error' => 'This till is inactive or unavailable.'];
    }
    return ['ok' => true];
}

/**
 * Resolve and validate the caller's operational till session for cash posts.
 * Ignores posted ownership / foreign branch / closed / inactive / other-user sessions.
 *
 * @param array<string,mixed> $options posted_till_session_id?, posted_branch?, lock?
 * @return array{ok:bool,session?:array,branch?:string,cashier?:array,error?:string}
 */
function psCashMovementResolveOperationalSession(mysqli $conn, array $options = []): array
{
    if (!tillEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Could not prepare the till session.'];
    }
    $cashier = tillCurrentCashier($conn);
    if (trim((string)($cashier['email'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }

    $postedSessionId = (int)($options['posted_till_session_id'] ?? 0);
    $lock = !empty($options['lock']);

    if ($lock) {
        $locked = tillLockSaleSession($conn, $postedSessionId);
        if (!empty($locked['error'])) {
            return ['ok' => false, 'error' => $locked['error']];
        }
        $session = $locked['session'];
        $branch = (string)$locked['branch'];
    } else {
        $active = getActiveTillSession($conn);
        if (!$active || !tillRowIsOpen($active) || !tillOwns($active, $cashier)) {
            return ['ok' => false, 'error' => 'Please open a till session before recording cash movements.'];
        }
        if ($postedSessionId > 0 && $postedSessionId !== (int)$active['id']) {
            return ['ok' => false, 'error' => "You cannot access another cashier's till session."];
        }
        $session = $active;
        // Blank till bind + sync drifted working branch to the open till.
        $aligned = tillAlignWorkingBranchToOpenSession($conn, $session, $cashier);
        if (empty($aligned['ok'])) {
            return [
                'ok' => false,
                'error' => $aligned['error'] ?? 'Could not align the working branch to this till session.',
            ];
        }
        $session = $aligned['session'];
        $branch = (string)$aligned['branch'];
    }

    // Reject posted foreign branch (ignore as ownership — reject mismatch).
    if (isset($options['posted_branch']) && trim((string)$options['posted_branch']) !== '') {
        $postedBranch = trim((string)$options['posted_branch']);
        if ($postedBranch !== $branch) {
            return [
                'ok' => false,
                'error' => 'Your till is open on ' . $branch
                    . '. Close that till before using ' . $postedBranch
                    . ', or switch working branch back to ' . $branch . '.',
            ];
        }
    }

    $regCheck = psCashMovementAssertRegisterActive($conn, $session);
    if (!$regCheck['ok']) {
        return ['ok' => false, 'error' => $regCheck['error']];
    }

    return [
        'ok' => true,
        'session' => $session,
        'branch' => $branch,
        'cashier' => $cashier,
    ];
}

/**
 * Core insert. Caller must manage the transaction when atomicity with other writes is required.
 * Amount must be > 0. No UPDATE/DELETE API exists.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,duplicate?:bool,error?:string}
 */
function psCashMovementInsert(mysqli $conn, array $data): array
{
    if (!psEnsureCashMovementsSchema($conn)) {
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }

    $typeCheck = psNormalizeCashMovementType($data['movement_type'] ?? '');
    if (!$typeCheck['ok']) {
        return ['ok' => false, 'error' => $typeCheck['error']];
    }
    $movementType = $typeCheck['value'];

    $amountCheck = psValidateCashMovementMoney($data['amount'] ?? '', 'Amount');
    if (!$amountCheck['ok']) {
        return ['ok' => false, 'error' => $amountCheck['error']];
    }
    $amount = $amountCheck['value'];

    $tillSessionId = (int)($data['till_session_id'] ?? 0);
    if ($tillSessionId < 1) {
        return ['ok' => false, 'error' => 'A valid till session is required.'];
    }
    $branch = trim((string)($data['branch'] ?? ''));
    if ($branch === '' || strlen($branch) > 250) {
        return ['ok' => false, 'error' => 'A valid branch is required.'];
    }

    $movementAt = trim((string)($data['movement_at'] ?? ''));
    if ($movementAt === '') {
        $movementAt = date('Y-m-d H:i:s');
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $movementAt);
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $movementAt) {
        return ['ok' => false, 'error' => 'Movement date/time is not valid.'];
    }

    $refType = trim((string)($data['reference_type'] ?? ''));
    $refId = trim((string)($data['reference_id'] ?? ''));
    if (strlen($refType) > 64 || strlen($refId) > 250) {
        return ['ok' => false, 'error' => 'Reference is too long.'];
    }
    // Operational types require stable refs for duplicate protection.
    if ($refType === '' || $refId === '') {
        return ['ok' => false, 'error' => 'A cash movement reference is required.'];
    }

    $reason = trim((string)($data['reason'] ?? ''));
    if (strlen($reason) > 500) {
        return ['ok' => false, 'error' => 'Reason is too long.'];
    }
    $description = trim((string)($data['description'] ?? ''));
    if (strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long.'];
    }
    $reasonStore = $reason === '' ? null : $reason;
    $descStore = $description === '' ? null : $description;

    $createdBy = trim((string)($data['created_by'] ?? ''));
    if ($createdBy === '') {
        $createdBy = psSessionCashMovementUser();
    }
    if ($createdBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to post a cash movement.'];
    }
    $createdAt = date('Y-m-d H:i:s');

    // App-level duplicate check before insert.
    $dupStmt = mysqli_prepare(
        $conn,
        'SELECT id FROM cash_movements
         WHERE reference_type = ? AND reference_id = ? AND movement_type = ?
         LIMIT 1'
    );
    if ($dupStmt) {
        mysqli_stmt_bind_param($dupStmt, 'sss', $refType, $refId, $movementType);
        mysqli_stmt_execute($dupStmt);
        $dupRes = mysqli_stmt_get_result($dupStmt);
        $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
        mysqli_stmt_close($dupStmt);
        if ($dupRow) {
            return ['ok' => false, 'error' => 'A cash movement with this reference already exists.', 'duplicate' => true];
        }
    }

    // Multi-currency: every new cash row records its currency and the rate snapshot to the company base.
    $fxCols = '';
    $fxVals = '';
    if (psFxHasColumn($conn, 'cash_movements', 'currency')) {
        $fx = psCashMovementCurrencySnapshot($conn, $tillSessionId, $data, (string)$amount, $movementAt);
        if (empty($fx['ok'])) {
            return ['ok' => false, 'error' => (string)$fx['error']];
        }
        $v = psFxSnapshotSqlValues($conn, $fx['snap']);
        $fxCols = ', currency, base_currency, fx_rate, fx_rate_ids, fx_path, base_amount';
        $fxVals = ', ' . implode(', ', $v) . ", '" . psFxCentsToMoney((int)$fx['base_cents']) . "'";
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO cash_movements
         (till_session_id, branch, movement_at, movement_type, amount, reference_type, reference_id, reason, description, created_by, created_at' . $fxCols . ')
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . $fxVals . ')'
    );
    if (!$stmt) {
        error_log('cash_movements insert prepare failed');
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'issssssssss',
        $tillSessionId,
        $branch,
        $movementAt,
        $movementType,
        $amount,
        $refType,
        $refId,
        $reasonStore,
        $descStore,
        $createdBy,
        $createdAt
    );
    if (!mysqli_stmt_execute($stmt)) {
        $dup = mysqli_errno($conn) === 1062 || mysqli_stmt_errno($stmt) === 1062;
        mysqli_stmt_close($stmt);
        error_log('cash_movements insert failed');
        if ($dup) {
            return ['ok' => false, 'error' => 'A cash movement with this reference already exists.', 'duplicate' => true];
        }
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $newId];
}

/**
 * OPENING_FLOAT for a till session. Call inside the till-open transaction after sessions INSERT.
 * Only when opening cash > 0. Duplicate OPENING_FLOAT per session rejected.
 *
 * @return array{ok:bool,id?:int,skipped?:bool,error?:string}
 */
function psCashMovementPostOpeningFloat(
    mysqli $conn,
    int $tillSessionId,
    string $branch,
    $amountRaw,
    string $createdBy,
    string $movementAt = ''
): array {
    $amountCheck = psValidateCashMovementMoney($amountRaw, 'Opening float');
    if (!$amountCheck['ok']) {
        // Zero / empty: do not invent an OPENING_FLOAT row.
        $raw = trim((string)$amountRaw);
        if ($raw === '' || $raw === '0' || $raw === '0.0' || $raw === '0.00') {
            return ['ok' => true, 'skipped' => true];
        }
        // tillParseFloat already rejected negatives; treat other failures as hard errors.
        if (isset($amountCheck['error']) && strpos($amountCheck['error'], 'greater than zero') !== false) {
            return ['ok' => true, 'skipped' => true];
        }
        return ['ok' => false, 'error' => $amountCheck['error']];
    }

    return psCashMovementInsert($conn, [
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'movement_at' => $movementAt !== '' ? $movementAt : date('Y-m-d H:i:s'),
        'movement_type' => PS_CASH_MOV_OPENING_FLOAT,
        'amount' => $amountCheck['value'],
        'reference_type' => PS_CASH_REF_TILL_SESSION,
        'reference_id' => (string)$tillSessionId,
        'reason' => 'Opening float',
        'description' => 'Till opening float',
        'created_by' => $createdBy,
    ]);
}

/**
 * CASH_SALE for a completed CASH tender. Call inside sale transaction after sale_payments.
 * Amount = server sale total (cash into drawer net of change).
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function psCashMovementPostCashSale(
    mysqli $conn,
    int $tillSessionId,
    string $branch,
    $saleTotal,
    $invoiceNumber,
    string $createdBy,
    string $movementAt = '',
    ?array $fx = null
): array {
    return psCashMovementInsert($conn, [
        'fx' => $fx,
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'movement_at' => $movementAt !== '' ? $movementAt : date('Y-m-d H:i:s'),
        'movement_type' => PS_CASH_MOV_CASH_SALE,
        'amount' => $saleTotal,
        'reference_type' => PS_CASH_REF_SALE,
        'reference_id' => (string)$invoiceNumber,
        'reason' => 'Cash sale',
        'description' => 'Invoice ' . (string)$invoiceNumber,
        'created_by' => $createdBy,
    ]);
}

/**
 * 2026-11-05 split payment / split currency: the cash side of a sale, one drawer per currency.
 * $eval is psEvaluateTenders(); per currency, net = cash tendered in it - change given in it:
 *  - net > 0: CASH_SALE into that currency's drawer;
 *  - net < 0 (change given in a currency the customer did not pay cash in): CASH_OUT, reason "Change given".
 * The movement types keep their fixed direction (amounts are never negative). The first row of the sale uses the plain
 * invoice number as reference (as before); further currencies use "INVOICE~CUR", so each row stays unique.
 * $saleFx: the sale's own snapshot, used for the sale currency so that row matches the sale exactly.
 *
 * @return array{ok:bool,error?:string}
 */
function psCashMovementPostSaleTenders(
    mysqli $conn,
    int $tillSessionId,
    string $branch,
    $invoiceNumber,
    string $createdBy,
    array $eval,
    string $company,
    string $saleCurrency,
    ?array $saleFx = null,
    string $movementAt = ''
): array {
    require_once __DIR__ . '/sale_payment.php';
    require_once __DIR__ . '/currency.php';
    $at = $movementAt !== '' ? $movementAt : date('Y-m-d H:i:s');
    $invoice = (string)$invoiceNumber;
    $used = [];
    $nets = psTenderCashByCurrency($eval);
    // sale currency first, so it takes the plain invoice reference
    uksort($nets, static fn($a, $b) => ($a === $saleCurrency ? -1 : 0) - ($b === $saleCurrency ? -1 : 0));
    foreach ($nets as $cur => $cents) {
        $fx = ($cur === $saleCurrency && $saleFx) ? $saleFx : psFxSnapshot($conn, $company, $cur, $at);
        if (empty($fx['ok']) && $fx !== $saleFx) {
            return ['ok' => false, 'error' => 'No exchange rate for the ' . $cur . ' cash.'];
        }
        $type = $cents > 0 ? PS_CASH_MOV_CASH_SALE : PS_CASH_MOV_CASH_OUT;
        $ref = isset($used[$type]) ? $invoice . '~' . $cur : $invoice;
        $used[$type] = true;
        $r = psCashMovementInsert($conn, [
            'fx' => $fx,
            'till_session_id' => $tillSessionId,
            'branch' => $branch,
            'movement_at' => $at,
            'movement_type' => $type,
            'amount' => psMoneyFromCents(abs($cents)),
            'reference_type' => PS_CASH_REF_SALE,
            'reference_id' => $ref,
            'reason' => $cents > 0 ? 'Cash sale' : 'Change given',
            'description' => ($cents > 0 ? 'Invoice ' : 'Change in ' . $cur . ', invoice ') . $invoice,
            'created_by' => $createdBy,
        ]);
        if (empty($r['ok'])) {
            return $r;
        }
    }
    return ['ok' => true];
}

/**
 * CASH_REFUND when a production refund returns physical cash for a CASH-tendered sale.
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function psCashMovementPostCashRefund(
    mysqli $conn,
    int $tillSessionId,
    string $branch,
    $amount,
    string $referenceType,
    string $referenceId,
    string $createdBy,
    string $description = '',
    string $movementAt = '',
    ?array $fx = null
): array {
    return psCashMovementInsert($conn, [
        'fx' => $fx,
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'movement_at' => $movementAt !== '' ? $movementAt : date('Y-m-d H:i:s'),
        'movement_type' => PS_CASH_MOV_CASH_REFUND,
        'amount' => $amount,
        'reference_type' => $referenceType,
        'reference_id' => $referenceId,
        'reason' => 'Cash refund',
        'description' => $description !== '' ? $description : 'Cash refund',
        'created_by' => $createdBy,
    ]);
}

/**
 * Manual CASH_IN or CASH_OUT for the caller's open till (Phase 7.3 controls).
 * Atomic: validate → lock till → re-read OPEN/own/active → allocate ref → insert → audit → COMMIT.
 * Posted user/cashier/branch/till/session/balance/reference ignored. Server logger + CIN/COUT only.
 * CASH_OUT does not auto-block when expected cash would go negative (no min-cash policy).
 * Does not modify cash_reconciliations; later activity after a FINAL recon is allowed while till stays open.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,reference?:string,error?:string}
 */
function psCashMovementPostManual(mysqli $conn, array $data, bool $manageTransaction = true): array
{
    if (!psEnsureCashMovementModule($conn)) {
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }
    $user = psSessionCashMovementUser();
    if ($user === '') {
        return ['ok' => false, 'error' => 'You must be signed in to record cash movements.'];
    }

    $typeCheck = psNormalizeCashMovementType($data['movement_type'] ?? '');
    if (!$typeCheck['ok']) {
        return ['ok' => false, 'error' => $typeCheck['error']];
    }
    $movementType = $typeCheck['value'];
    if ($movementType !== PS_CASH_MOV_CASH_IN && $movementType !== PS_CASH_MOV_CASH_OUT) {
        return ['ok' => false, 'error' => 'Only cash in and cash out can be posted manually.'];
    }

    $amountCheck = psValidateCashMovementMoney($data['amount'] ?? '', 'Amount');
    if (!$amountCheck['ok']) {
        return ['ok' => false, 'error' => $amountCheck['error']];
    }

    $reasonCheck = psNormalizeCashMovementManualReason($data['reason'] ?? $data['reason_code'] ?? '', $movementType);
    if (!$reasonCheck['ok']) {
        return ['ok' => false, 'error' => $reasonCheck['error']];
    }
    $reason = $reasonCheck['value'];

    $descCheck = psValidateCashMovementManualDescription($data['description'] ?? '', $reason, true);
    if (!$descCheck['ok']) {
        return ['ok' => false, 'error' => $descCheck['error']];
    }
    $description = $descCheck['value'];

    // Multi-currency: the drawer currency of this cash in / out (blank = company base). Checked again on insert.
    $fxCompany = psFxSessionCompany();
    $fxBase = psFxBase($conn, $fxCompany);
    $manualCurrency = psFxNormalizeCode($data['currency'] ?? '') ?: $fxBase;
    $approvalAmount = (string)$amountCheck['value'];
    if ($manualCurrency !== $fxBase) {
        $chk = psFxCheckTransactionCurrency($conn, $fxCompany, $manualCurrency);
        if (empty($chk['ok'])) {
            return ['ok' => false, 'error' => (string)$chk['error']];
        }
        $snapNow = psFxSnapshot($conn, $fxCompany, $manualCurrency);
        if (empty($snapNow['ok'])) {
            return ['ok' => false, 'error' => (string)$snapNow['error']];
        }
        // The approval limit is set in the base currency.
        $approvalAmount = psFxCentsToMoney(psFxToBaseCents($conn, $snapNow, (int)psFxMoneyToCents($approvalAmount)));
    }

    // 2026-10-17: a Till user's cash out above the company limit needs a Supervisor / Management / admin
    // to confirm with their own username and password. Their name is kept on the movement.
    if ($movementType === PS_CASH_MOV_CASH_OUT) {
        require_once __DIR__ . '/cash_controls.php';
        if (psCashOutNeedsApproval($conn, $approvalAmount)) {
            $appr = psCashControlsVerifyApprover($conn, (string)($data['approver_login'] ?? ''), (string)($data['approver_password'] ?? ''));
            if (empty($appr['ok'])) {
                return ['ok' => false, 'needs_approval' => true,
                    'error' => 'Cash out over ' . psCashOutApprovalLimit($conn) . ' needs a supervisor. ' . (string)($appr['error'] ?? '')];
            }
            $tag = '[Approved by ' . $appr['email'] . ']';
            $description = trim(substr($description, 0, 500 - strlen($tag) - 1) . ' ' . $tag);
        }
    }

    // Ignore posted ownership / balance / refs / attribution — session logger + till only.
    unset(
        $data['balance'],
        $data['expected_cash'],
        $data['actual_cash'],
        $data['reference'],
        $data['reference_id'],
        $data['reference_type'],
        $data['created_by'],
        $data['user'],
        $data['cashier'],
        $data['cashier_email'],
        $data['cashier_id'],
        $data['logger']
    );

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }

    $resolved = psCashMovementResolveOperationalSession($conn, [
        'posted_till_session_id' => (int)($data['till_session_id'] ?? $data['posted_till_session_id'] ?? $data['session_id'] ?? 0),
        'posted_branch' => $data['branch'] ?? $data['posted_branch'] ?? '',
        'lock' => true,
    ]);
    if (!$resolved['ok']) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => $resolved['error']];
    }

    $session = $resolved['session'];
    $tillSessionId = (int)$session['id'];
    $branch = (string)$resolved['branch'];

    // Re-read session inside txn (OPEN + own + active register).
    $recheck = tillSessionById($conn, $tillSessionId);
    if (!$recheck || !tillRowIsOpen($recheck) || !tillOwns($recheck, $resolved['cashier'])) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'Your till session is no longer open. Please reopen the till.'];
    }
    $sessionBranch = trim((string)($recheck['branch'] ?? ''));
    if ($sessionBranch === '') {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'This till has no branch assigned. Open Cash Reconciliation to bind it, or close it from an authorised account.',
        ];
    }
    if ($sessionBranch !== $branch) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'Your till is open on ' . $sessionBranch
                . '. Close that till before using ' . $branch
                . ', or switch working branch back to ' . $sessionBranch . '.',
        ];
    }
    $regRecheck = psCashMovementAssertRegisterActive($conn, $recheck);
    if (!$regRecheck['ok']) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => $regRecheck['error']];
    }

    // No min-cash / negative-expected block (documented Phase 7.3 limitation).
    // psCashMovementBlockNegativeExpectedOnCashOutEnabled() === false.

    $prefix = $movementType === PS_CASH_MOV_CASH_IN ? 'CIN' : 'COUT';
    $alloc = psAllocateCashMovementManualNumber($conn, $prefix);
    if (!$alloc['ok']) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => $alloc['error']];
    }
    $refNumber = (string)$alloc['number'];
    $refType = $movementType === PS_CASH_MOV_CASH_IN ? PS_CASH_REF_CASH_IN : PS_CASH_REF_CASH_OUT;
    $now = date('Y-m-d H:i:s');

    $inserted = psCashMovementInsert($conn, [
        'currency' => $manualCurrency,
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'movement_at' => $now,
        'movement_type' => $movementType,
        'amount' => $amountCheck['value'],
        'reference_type' => $refType,
        'reference_id' => $refNumber,
        'reason' => $reason,
        'description' => $description,
        'created_by' => $user,
    ]);
    if (!$inserted['ok']) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return $inserted;
    }
    $movementId = (int)$inserted['id'];

    $auditFields = [
        'movement_type' => $movementType,
        'currency' => $manualCurrency,
        'amount' => $amountCheck['value'],
        'reason' => $reason,
        'description' => $description,
        'reference_id' => $refNumber,
        'reference_type' => $refType,
    ];
    $auditOk = true;
    foreach ($auditFields as $fieldName => $newValue) {
        $auditOk = $auditOk && psCashMovementAuditInsert(
            $conn,
            $movementId,
            $tillSessionId,
            $branch,
            PS_CASH_AUDIT_CREATE,
            $fieldName,
            null,
            $newValue === '' ? null : $newValue,
            $user,
            $now,
            PS_CASH_AUDIT_SOURCE_UI
        );
    }
    if (!$auditOk) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }

    if ($owns && !mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Cash movement could not be saved.'];
    }

    return [
        'ok' => true,
        'id' => $movementId,
        'reference' => $refNumber,
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'movement_type' => $movementType,
        'amount' => $amountCheck['value'],
        'reason' => $reason,
        'description' => $description,
    ];
}

/**
 * Single cash-formula definition (Phase 7.6):
 * Expected = OPENING_FLOAT + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT
 * Amounts are always positive; direction is by movement type.
 *
 * @param array{opening_float?:mixed,cash_sales?:mixed,cash_in?:mixed,cash_refunds?:mixed,cash_out?:mixed} $parts
 * @return array{balance:string,detail:array{opening_float:string,cash_sales:string,cash_in:string,cash_refunds:string,cash_out:string}}
 */
function psCashMovementComputeBalanceFromParts(array $parts): array
{
    $opening = number_format((float)($parts['opening_float'] ?? 0), 2, '.', '');
    $sales = number_format((float)($parts['cash_sales'] ?? 0), 2, '.', '');
    $cashIn = number_format((float)($parts['cash_in'] ?? 0), 2, '.', '');
    $refunds = number_format((float)($parts['cash_refunds'] ?? 0), 2, '.', '');
    $cashOut = number_format((float)($parts['cash_out'] ?? 0), 2, '.', '');
    if (function_exists('bcadd') && function_exists('bcsub')) {
        $in = bcadd(bcadd($opening, $sales, 2), $cashIn, 2);
        $out = bcadd($refunds, $cashOut, 2);
        $balance = bcsub($in, $out, 2);
    } else {
        $balance = number_format(
            ((float)$opening + (float)$sales + (float)$cashIn) - ((float)$refunds + (float)$cashOut),
            2,
            '.',
            ''
        );
    }
    return [
        'balance' => number_format((float)$balance, 2, '.', ''),
        'detail' => [
            'opening_float' => $opening,
            'cash_sales' => $sales,
            'cash_in' => $cashIn,
            'cash_refunds' => $refunds,
            'cash_out' => $cashOut,
        ],
    ];
}

/**
 * Derived till cash balance from the ledger (no cached column).
 * Formula: psCashMovementComputeBalanceFromParts (Opening + Sales + Cash In − Refunds − Cash Out).
 *
 * @return array{ok:bool,balance?:string,detail?:array,error?:string}
 */
function psCashMovementBalance(mysqli $conn, int $tillSessionId, string $currency = ''): array
{
    if ($tillSessionId < 1 || !psEnsureCashMovementsSchema($conn)) {
        return ['ok' => false, 'error' => 'Cash balance could not be calculated.'];
    }
    // Multi-currency: one drawer balance per currency. Blank = the till company's base; rows saved before
    // multi-currency (currency NULL) belong to the base, because a base cannot change once a company has transactions.
    $base = psFxTillBase($conn, $tillSessionId);
    $currency = psFxNormalizeCode($currency) ?: $base;
    $curWhere = '';
    if (psFxHasColumn($conn, 'cash_movements', 'currency')) {
        $curWhere = $currency === $base
            ? " AND (currency = '" . mysqli_real_escape_string($conn, $currency) . "' OR currency IS NULL)"
            : " AND currency = '" . mysqli_real_escape_string($conn, $currency) . "'";
    } elseif ($currency !== $base) {
        $curWhere = ' AND 1 = 0';
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            COALESCE(SUM(CASE WHEN movement_type = 'OPENING_FLOAT' THEN amount ELSE 0 END), 0) AS opening_float,
            COALESCE(SUM(CASE WHEN movement_type = 'CASH_SALE' THEN amount ELSE 0 END), 0) AS cash_sales,
            COALESCE(SUM(CASE WHEN movement_type = 'CASH_IN' THEN amount ELSE 0 END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN movement_type = 'CASH_REFUND' THEN amount ELSE 0 END), 0) AS cash_refunds,
            COALESCE(SUM(CASE WHEN movement_type = 'CASH_OUT' THEN amount ELSE 0 END), 0) AS cash_out
         FROM cash_movements
         WHERE till_session_id = ?" . $curWhere
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Cash balance could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $tillSessionId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        $empty = psCashMovementComputeBalanceFromParts([]);
        return [
            'ok' => true,
            'balance' => $empty['balance'],
            'detail' => $empty['detail'],
        ];
    }
    $computed = psCashMovementComputeBalanceFromParts($row);
    return [
        'ok' => true,
        'balance' => $computed['balance'],
        'detail' => $computed['detail'],
        'currency' => $currency,
    ];
}

/**
 * Currencies a till must count: its base (always) plus every currency with cash activity on it.
 * @return list<string> base first
 */
function psCashMovementTillCurrencies(mysqli $conn, int $tillSessionId): array
{
    $base = psFxTillBase($conn, $tillSessionId);
    $out = [$base];
    if ($tillSessionId > 0 && psFxHasColumn($conn, 'cash_movements', 'currency')) {
        $res = mysqli_query($conn, 'SELECT DISTINCT currency FROM cash_movements WHERE till_session_id = ' . (int)$tillSessionId
            . ' AND currency IS NOT NULL ORDER BY currency');
        while ($res && ($r = mysqli_fetch_row($res))) {
            $c = psFxNormalizeCode($r[0]);
            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
    }
    return $out;
}

/** @return array<string,array> currency => psCashMovementBalance() result, base first. */
function psCashMovementBalancesByCurrency(mysqli $conn, int $tillSessionId): array
{
    $out = [];
    foreach (psCashMovementTillCurrencies($conn, $tillSessionId) as $code) {
        $out[$code] = psCashMovementBalance($conn, $tillSessionId, $code);
    }
    return $out;
}

/**
 * Enrich a cash_movements row for display (direction, labels, display reference).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function psCashMovementEnrichHistoryRow(array $row): array
{
    $type = (string)($row['movement_type'] ?? '');
    $row['direction'] = psCashMovementDirection($type);
    $row['type_label'] = psCashMovementTypeLabel($type);
    $reason = trim((string)($row['reason'] ?? ''));
    $row['reason_label'] = $reason !== '' ? psCashMovementReasonLabel($reason) : '';
    $refType = trim((string)($row['reference_type'] ?? ''));
    $refId = trim((string)($row['reference_id'] ?? ''));
    // Prefer CIN-/COUT- display for manual; otherwise type + id without internal movement id.
    if ($refId !== '' && (strpos($refId, 'CIN-') === 0 || strpos($refId, 'COUT-') === 0)) {
        $row['reference_display'] = $refId;
    } elseif ($refType !== '' && $refId !== '') {
        $row['reference_display'] = $refType . ' ' . $refId;
    } else {
        $row['reference_display'] = $refId !== '' ? $refId : $refType;
    }
    return $row;
}

/**
 * Read-only history for a till session, scoped by branch.
 *
 * @return array{ok:bool,rows?:array,error?:string}
 */
function psCashMovementHistory(
    mysqli $conn,
    int $tillSessionId,
    string $branch,
    int $limit = 100,
    int $offset = 0
): array {
    if ($tillSessionId < 1 || $branch === '' || !psEnsureCashMovementsSchema($conn)) {
        return ['ok' => false, 'error' => 'Cash movements could not be loaded.'];
    }
    require_once __DIR__ . '/operator_scope.php';
    if (psOperatorCashScoped() && !psOperatorOwnsSession($conn, $tillSessionId)) {
        return ['ok' => true, 'rows' => []];
    }
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, till_session_id, branch, movement_at, movement_type, amount,' . (psFxHasColumn($conn, 'cash_movements', 'currency') ? ' currency,' : '') . '
                reference_type, reference_id, reason, description, created_by, created_at
         FROM cash_movements
         WHERE till_session_id = ? AND branch = ?
         ORDER BY movement_at DESC, id DESC
         LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Cash movements could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 'isii', $tillSessionId, $branch, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = psCashMovementEnrichHistoryRow($row);
        }
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Branch-scoped read-only movement history (Phase 7.3).
 * Non-admin: forced to authorised working branch (and optionally current till session).
 * Admin: optional branch filter; empty/'all' = all branches (purchasing-reports pattern).
 *
 * @param array<string,mixed> $filters branch?, till_session_id?, limit?, offset?
 * @return array{ok:bool,rows?:array,error?:string,branch?:string,all_branches?:bool}
 */
function psCashMovementListHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCashMovementsSchema($conn)) {
        return ['ok' => false, 'error' => 'Cash movements could not be loaded.'];
    }

    require_once __DIR__ . '/product_master.php';
    $sessionBranch = function_exists('psSessionProductBranch')
        ? psSessionProductBranch()
        : tillSelectedBranch();
    if ($sessionBranch === '') {
        $sessionBranch = tillSelectedBranch();
    }
    if ($sessionBranch === '') {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }

    $allBranches = false;
    $branchFilter = $sessionBranch;
    if (psCashMovementIsAdmin()) {
        $posted = trim((string)($filters['branch'] ?? ''));
        if ($posted === '' || strcasecmp($posted, 'all') === 0) {
            $allBranches = true;
            $branchFilter = '';
        } else {
            if (function_exists('psProductBranchIsValid') && function_exists('psProductBranchIsAuthorised')) {
                if (!psProductBranchIsValid($conn, $posted) || !psProductBranchIsAuthorised($conn, $posted)) {
                    return ['ok' => false, 'error' => 'That branch is not available.'];
                }
            }
            $branchFilter = $posted;
        }
    } else {
        $branchFilter = $sessionBranch;
    }

    $limit = (int)($filters['limit'] ?? 50);
    $offset = (int)($filters['offset'] ?? 0);
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $sessionFilter = (int)($filters['till_session_id'] ?? 0);
    // Till / supervisor: only movements they posted, on till sessions they own.
    require_once __DIR__ . '/operator_scope.php';
    $ownSql = '';
    if (psOperatorCashScoped()) {
        $ownSql = ' AND ' . psOperatorOwnSessionCondition($conn, 'till_session_id')
            . ' AND ' . psOperatorPostedBySql($conn, ['created_by']);
    }

    if ($allBranches && $sessionFilter < 1) {
        require_once __DIR__ . '/product_master.php';
        $sql = 'SELECT id, till_session_id, branch, movement_at, movement_type, amount,' . (psFxHasColumn($conn, 'cash_movements', 'currency') ? ' currency,' : '') . '
                       reference_type, reference_id, reason, description, created_by, created_at
                FROM cash_movements
                WHERE ' . psCompanyBranchesInSql($conn, 'branch') . $ownSql . '
                ORDER BY movement_at DESC, id DESC
                LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Cash movements could not be loaded.'];
        }
        mysqli_stmt_bind_param($stmt, 'ii', $limit, $offset);
    } elseif ($sessionFilter > 0) {
        $sql = 'SELECT id, till_session_id, branch, movement_at, movement_type, amount,' . (psFxHasColumn($conn, 'cash_movements', 'currency') ? ' currency,' : '') . '
                       reference_type, reference_id, reason, description, created_by, created_at
                FROM cash_movements
                WHERE till_session_id = ? AND branch = ?' . $ownSql . '
                ORDER BY movement_at DESC, id DESC
                LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Cash movements could not be loaded.'];
        }
        $useBranch = $branchFilter !== '' ? $branchFilter : $sessionBranch;
        mysqli_stmt_bind_param($stmt, 'isii', $sessionFilter, $useBranch, $limit, $offset);
    } else {
        $sql = 'SELECT id, till_session_id, branch, movement_at, movement_type, amount,' . (psFxHasColumn($conn, 'cash_movements', 'currency') ? ' currency,' : '') . '
                       reference_type, reference_id, reason, description, created_by, created_at
                FROM cash_movements
                WHERE branch = ?' . $ownSql . '
                ORDER BY movement_at DESC, id DESC
                LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Cash movements could not be loaded.'];
        }
        mysqli_stmt_bind_param($stmt, 'sii', $branchFilter, $limit, $offset);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = psCashMovementEnrichHistoryRow($row);
        }
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'branch' => $branchFilter,
        'all_branches' => $allBranches,
    ];
}

/**
 * Look up sale tender method for refund cash decisions.
 *
 * @return array{ok:bool,method?:string,error?:string}
 */
function psCashMovementSalePaymentMethod(mysqli $conn, string $invoiceNumber): array
{
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return ['ok' => false, 'error' => 'Invoice was not found.'];
    }
    // 2026-11-05: a sale paid with several methods (split payment) is "SPLIT"; its refund method is chosen on the
    // refund request (sale_refund_requests.refund_method). The change row (is_change = 1) is not a way it was paid.
    $hasChangeCol = psFxHasColumn($conn, 'sale_payments', 'is_change');
    $stmt = mysqli_prepare(
        $conn,
        'SELECT DISTINCT UPPER(TRIM(method)) AS method FROM sale_payments WHERE invoice_number = ?'
            . ($hasChangeCol ? ' AND is_change = 0' : '') . ' ORDER BY method'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Payment method could not be read.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    if (!$rows) {
        // Pre-sale_payments / legacy sales: cannot safely assume cash left the till.
        return ['ok' => true, 'method' => '', 'unknown' => true];
    }
    if (count($rows) > 1) {
        return ['ok' => true, 'method' => 'SPLIT', 'unknown' => false, 'methods' => array_column($rows, 'method')];
    }
    return ['ok' => true, 'method' => (string)$rows[0]['method'], 'unknown' => false];
}

/**
 * Explicit: Phase 6.3 account payments must not post to cash_movements in 7.1.
 */
function psCashMovementCustomerAccountPaymentIntegrationEnabled(): bool
{
    return false;
}

/**
 * Submit-token helpers for manual cash in/out (same pattern as purchase returns / CAP).
 */
function psCashMovementIssueSubmitToken(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['cash_movement_submit_token'] = $token;
    return $token;
}

function psCashMovementConsumeSubmitToken($posted): bool
{
    $posted = trim((string)$posted);
    $known = trim((string)($_SESSION['cash_movement_submit_token'] ?? ''));
    if ($posted === '' || $known === '' || !hash_equals($known, $posted)) {
        return false;
    }
    unset($_SESSION['cash_movement_submit_token']);
    return true;
}
