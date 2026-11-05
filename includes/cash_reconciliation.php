<?php
/**
 * Phase 7.2 — Till Cash Reconciliation.
 *
 * Snapshot of physical cash count vs ledger-derived expected cash for one till session.
 * Does NOT replace cash_movements. Does NOT insert/edit/delete cash_movements.
 * Does NOT auto-close or lock the till. Till closure = Phase 7.4.
 *
 * Expected cash (ledger only via psCashMovementBalance):
 *   Opening + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT
 * Posted expected / variance / status / ownership are ignored.
 *
 * Counted cash: DECIMAL >= 0 (server). Variance = counted − expected.
 * Status: BALANCED (=0) / SHORTAGE (<0) / SURPLUS (>0). No tolerance.
 *
 * Lifecycle: FINAL on create. Historical FINAL rows are immutable (no edit of counted/
 * variance/status). Phase 7.4 allows additional FINAL snapshots while the till stays
 * OPEN so a stale count can be refreshed before close; close uses the latest FINAL.
 * SHORTAGE / SURPLUS require a non-empty non-whitespace note; BALANCED optional.
 * Note is not an accounting adjustment.
 *
 * Customer-account payments (Phase 6.3) remain outside the cash ledger — expected
 * cash stays Phase 7.1 ledger only.
 */

require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/cash_movement.php';

const PS_CASH_RECON_STATUS_BALANCED = 'BALANCED';
const PS_CASH_RECON_STATUS_SHORTAGE = 'SHORTAGE';
const PS_CASH_RECON_STATUS_SURPLUS = 'SURPLUS';

const PS_CASH_RECON_LIFECYCLE_FINAL = 'FINAL';

const PS_CASH_RECON_AUDIT_CREATE = 'CREATE';
const PS_CASH_RECON_AUDIT_FINALISE = 'FINALISE';
const PS_CASH_RECON_AUDIT_SOURCE_UI = 'UI';
const PS_CASH_RECON_AUDIT_SOURCE_SYSTEM = 'SYSTEM';

/**
 * @return list<string>
 */
function psCashReconciliationStatuses(): array
{
    return [
        PS_CASH_RECON_STATUS_BALANCED,
        PS_CASH_RECON_STATUS_SHORTAGE,
        PS_CASH_RECON_STATUS_SURPLUS,
    ];
}

function psCashReconciliationStatusLabel(string $status): string
{
    $labels = [
        PS_CASH_RECON_STATUS_BALANCED => 'Balanced',
        PS_CASH_RECON_STATUS_SHORTAGE => 'Shortage',
        PS_CASH_RECON_STATUS_SURPLUS => 'Surplus',
    ];
    $status = strtoupper(trim($status));
    return $labels[$status] ?? $status;
}

function psSessionCashReconciliationUser(): string
{
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psCashReconciliationIsAdmin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Counted cash: DECIMAL >= 0, max 2 decimal places. Zero allowed.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateCashReconciliationCountedMoney($raw, string $label = 'Counted cash'): array
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
    if ((float)$normalised < 0) {
        return ['ok' => false, 'error' => $label . ' cannot be negative.'];
    }
    return ['ok' => true, 'value' => $normalised];
}

function psEnsureCashReconciliationsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM cash_reconciliations LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS cash_reconciliations (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            till_session_id INT NOT NULL,
            branch VARCHAR(250) NOT NULL,
            reconciled_at DATETIME NOT NULL,
            expected_cash DECIMAL(14,2) NOT NULL,
            counted_cash DECIMAL(14,2) NOT NULL,
            variance DECIMAL(14,2) NOT NULL,
            status VARCHAR(32) NOT NULL,
            lifecycle VARCHAR(32) NOT NULL DEFAULT 'FINAL',
            notes TEXT NULL,
            reconciled_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            KEY idx_cash_recon_session (till_session_id),
            KEY idx_cash_recon_branch (branch),
            KEY idx_cash_recon_at (reconciled_at),
            KEY idx_cash_recon_status (status),
            KEY idx_cash_recon_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('cash_reconciliations schema was not created');
            return false;
        }
    }
    // Phase 7.4: multiple FINAL snapshots per session (latest used for close currency).
    if (function_exists('tillHasIndex') && tillHasIndex($conn, 'cash_reconciliations', 'uniq_cash_recon_session')) {
        $dropped = mysqli_query($conn, 'ALTER TABLE cash_reconciliations DROP INDEX uniq_cash_recon_session');
        if (!$dropped && mysqli_errno($conn) !== 1091) {
            error_log('cash_reconciliations: could not drop uniq_cash_recon_session');
        }
    }
    if (function_exists('tillHasIndex') && !tillHasIndex($conn, 'cash_reconciliations', 'idx_cash_recon_session')) {
        $idx = mysqli_query($conn, 'ALTER TABLE cash_reconciliations ADD KEY idx_cash_recon_session (till_session_id)');
        if (!$idx && mysqli_errno($conn) !== 1061) {
            error_log('cash_reconciliations: could not add idx_cash_recon_session');
        }
    }
    $ready = true;
    return true;
}

/**
 * Append-only create/finalise audit. No UPDATE/DELETE helpers.
 */
function psEnsureCashReconciliationAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM cash_reconciliation_audit LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS cash_reconciliation_audit (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cash_reconciliation_id INT NOT NULL,
            till_session_id INT NOT NULL,
            branch VARCHAR(250) NOT NULL,
            action VARCHAR(32) NOT NULL,
            field_name VARCHAR(64) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            changed_by VARCHAR(250) NOT NULL DEFAULT '',
            changed_at DATETIME NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'UI',
            KEY idx_cash_recon_audit_recon (cash_reconciliation_id),
            KEY idx_cash_recon_audit_session (till_session_id),
            KEY idx_cash_recon_audit_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('cash_reconciliation_audit schema was not created');
            return false;
        }
    }
    $ready = true;
    return true;
}

function psEnsureCashReconciliationModule(mysqli $conn): bool
{
    return psEnsureCashReconciliationsSchema($conn) && psEnsureCashReconciliationAuditSchema($conn);
}

function psCashReconciliationAuditInsert(
    mysqli $conn,
    int $reconId,
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
    if (!psEnsureCashReconciliationAuditSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO cash_reconciliation_audit
         (cash_reconciliation_id, till_session_id, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('cash_reconciliation_audit prepare failed');
        return false;
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    mysqli_stmt_bind_param(
        $stmt,
        'iissssssss',
        $reconId,
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
        error_log('cash_reconciliation_audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/** Multi-currency (2026-10-20): ", currency" when the column exists. */
function psCashReconciliationCurrencySelect(mysqli $conn): string
{
    require_once __DIR__ . '/currency.php';
    return psFxHasColumn($conn, 'cash_reconciliations', 'currency') ? ', currency' : '';
}

/**
 * Filter for one drawer currency. Counts saved before multi-currency (currency NULL) belong to the till's base.
 * Blank currency = the base.
 */
function psCashReconciliationCurrencyWhere(mysqli $conn, int $tillSessionId, string $currency): string
{
    require_once __DIR__ . '/currency.php';
    $base = psFxTillBase($conn, $tillSessionId);
    $currency = psFxNormalizeCode($currency) ?: $base;
    if (!psFxHasColumn($conn, 'cash_reconciliations', 'currency')) {
        return $currency === $base ? '' : ' AND 1 = 0';
    }
    $esc = mysqli_real_escape_string($conn, $currency);
    return $currency === $base ? " AND (currency = '$esc' OR currency IS NULL)" : " AND currency = '$esc'";
}

/**
 * Expected cash for a till session = ledger balance via psCashMovementBalance (no duplicate formula).
 *
 * @return array{ok:bool,expected?:string,detail?:array,error?:string}
 */
function psCashReconciliationExpectedFromLedger(mysqli $conn, int $tillSessionId, string $currency = ''): array
{
    $bal = psCashMovementBalance($conn, $tillSessionId, $currency);
    if (empty($bal['ok'])) {
        return ['ok' => false, 'error' => $bal['error'] ?? 'Expected cash could not be calculated.'];
    }
    return [
        'ok' => true,
        'expected' => (string)$bal['balance'],
        'detail' => $bal['detail'] ?? [],
    ];
}

/**
 * Server variance + status from counted and expected (ignore posted status/variance).
 *
 * @return array{ok:bool,variance?:string,status?:string,error?:string}
 */
function psCashReconciliationComputeVarianceStatus(string $counted, string $expected): array
{
    if (function_exists('bcsub')) {
        $variance = bcsub($counted, $expected, 2);
    } else {
        $variance = number_format((float)$counted - (float)$expected, 2, '.', '');
    }
    $variance = number_format((float)$variance, 2, '.', '');
    $cmp = (float)$variance;
    if ($cmp === 0.0 || abs($cmp) < 0.0000001) {
        $status = PS_CASH_RECON_STATUS_BALANCED;
        $variance = '0.00';
    } elseif ($cmp < 0) {
        $status = PS_CASH_RECON_STATUS_SHORTAGE;
    } else {
        $status = PS_CASH_RECON_STATUS_SURPLUS;
    }
    return ['ok' => true, 'variance' => $variance, 'status' => $status];
}

/**
 * Latest FINAL reconciliation for this till session (ORDER BY id DESC).
 * Phase 7.4: multiple FINAL snapshots may exist; callers that need "current" must
 * also compare expected_cash to psCashMovementBalance.
 *
 * @return array{ok:bool,exists?:bool,row?:array|null,error?:string}
 */
function psCashReconciliationFindFinalBySession(mysqli $conn, int $tillSessionId, bool $lock = false, string $currency = ''): array
{
    if ($tillSessionId < 1 || !psEnsureCashReconciliationsSchema($conn)) {
        return ['ok' => false, 'error' => 'Reconciliation could not be loaded.'];
    }
    $sql = 'SELECT id, till_session_id, branch, reconciled_at, expected_cash, counted_cash, variance,
                   status, lifecycle, notes, reconciled_by, created_at' . psCashReconciliationCurrencySelect($conn) . '
            FROM cash_reconciliations
            WHERE till_session_id = ? AND lifecycle = ?' . psCashReconciliationCurrencyWhere($conn, $tillSessionId, $currency) . '
            ORDER BY id DESC
            LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Reconciliation could not be loaded.'];
    }
    $lifecycle = PS_CASH_RECON_LIFECYCLE_FINAL;
    mysqli_stmt_bind_param($stmt, 'is', $tillSessionId, $lifecycle);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'exists' => $row !== null, 'row' => $row];
}

function psCashReconciliationById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsureCashReconciliationsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, till_session_id, branch, reconciled_at, expected_cash, counted_cash, variance,
                status, lifecycle, notes, reconciled_by, created_at' . psCashReconciliationCurrencySelect($conn) . '
         FROM cash_reconciliations WHERE id = ? LIMIT 1'
    );
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

/**
 * Resolve operational till for reconciliation (own OPEN till on working branch).
 * Ignores posted cashier / branch / till ownership — rejects foreign session IDs.
 *
 * @param array<string,mixed> $options
 * @return array{ok:bool,session?:array,branch?:string,cashier?:array,error?:string}
 */
function psCashReconciliationResolveOperationalSession(mysqli $conn, array $options = []): array
{
    // Same gate as Phase 7.1 operational cash posts.
    return psCashMovementResolveOperationalSession($conn, $options);
}

/**
 * Atomic FINAL reconciliation.
 * Validate auth → resolve till → FOR UPDATE till → verify OPEN (CLOSED rejected) →
 * recalculate expected from cash_movements → validate counted → variance/status →
 * note if needed → insert → audit → COMMIT.
 *
 * Phase 7.4: additional FINAL snapshots are allowed while the till remains OPEN
 * (e.g. after cash activity that stale-dates a prior count). Prior FINAL rows stay
 * immutable. Does not write cash_movements. Does not close/lock till. Does not update
 * sessions.expected_cash / actual_cash / variance at reconcile time (closure writes those).
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,duplicate?:bool,error?:string,status?:string,variance?:string,expected?:string,counted?:string}
 */
function psCashReconciliationFinalise(mysqli $conn, array $data, bool $manageTransaction = true): array
{
    if (!psEnsureCashReconciliationModule($conn)) {
        return ['ok' => false, 'error' => 'Reconciliation could not be saved.'];
    }
    $user = psSessionCashReconciliationUser();
    if ($user === '') {
        return ['ok' => false, 'error' => 'You must be signed in to reconcile the till.'];
    }

    // Ignore tampered posted expected / variance / status / ownership fields.
    unset(
        $data['expected_cash'],
        $data['expected'],
        $data['variance'],
        $data['status'],
        $data['lifecycle'],
        $data['reconciled_by'],
        $data['cashier'],
        $data['cashier_email'],
        $data['cashier_id']
    );

    $countedCheck = psValidateCashReconciliationCountedMoney($data['counted_cash'] ?? $data['counted'] ?? '', 'Counted cash');
    if (!$countedCheck['ok']) {
        return ['ok' => false, 'error' => $countedCheck['error']];
    }
    $counted = $countedCheck['value'];

    $notes = trim((string)($data['notes'] ?? $data['reason'] ?? ''));
    if (strlen($notes) > 2000) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Reconciliation could not be saved.'];
    }

    $resolved = psCashReconciliationResolveOperationalSession($conn, [
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
    $cashier = $resolved['cashier'];

    // Re-verify till state inside txn after lock.
    $recheck = tillSessionById($conn, $tillSessionId);
    if (!$recheck || !tillRowIsOpen($recheck) || !tillOwns($recheck, $cashier)) {
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
            'error' => 'This till has no branch assigned. Reload Cash Reconciliation to bind it to your working branch, then finalise.',
        ];
    }
    if ($sessionBranch !== $branch) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'Your till is open on ' . $sessionBranch
                . '. Switch working branch back to ' . $sessionBranch
                . ' (or close that till) before finalising reconciliation.',
        ];
    }

    // Phase 7.4: several FINAL rows may exist per open till (prior rows immutable); a new one needs cash activity since the last (below).
    // CLOSED tills are already rejected above via tillRowIsOpen.

    // Multi-currency: each drawer currency is counted on its own. Blank = the till company's base.
    $reconBase = psFxTillBase($conn, $tillSessionId);
    $reconCurrency = psFxNormalizeCode($data['currency'] ?? '') ?: $reconBase;
    if (!in_array($reconCurrency, psCashMovementTillCurrencies($conn, $tillSessionId), true)) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'There is no ' . $reconCurrency . ' cash on this till to count.'];
    }

    // Re-read ledger expected cash inside the transaction.
    $expectedRes = psCashReconciliationExpectedFromLedger($conn, $tillSessionId, $reconCurrency);
    if (!$expectedRes['ok']) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => $expectedRes['error'] ?? 'Expected cash could not be calculated.'];
    }
    $expected = (string)$expectedRes['expected'];

    // 2026-10-17: one final count per state of the drawer. While the latest FINAL still matches the
    // ledger (no cash sale, refund or cash in / out since), a second count is refused; it would only
    // duplicate the first (or let a cashier recount until a shortage disappears).
    $latest = psCashReconciliationFindFinalBySession($conn, $tillSessionId, true, $reconCurrency);
    if (!empty($latest['ok']) && !empty($latest['exists']) && is_array($latest['row'])) {
        $prevCents = (int)round(((float)$latest['row']['expected_cash']) * 100);
        $nowCents = (int)round(((float)$expected) * 100);
        if ($prevCents === $nowCents) {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return [
                'ok' => false,
                'already_current' => true,
                'error' => ($reconCurrency !== $reconBase ? $reconCurrency . ': ' : '') . 'This till was already reconciled at ' . (string)$latest['row']['reconciled_at']
                    . ' (' . strtolower(psCashReconciliationStatusLabel((string)$latest['row']['status'])) . ', counted '
                    . number_format((float)$latest['row']['counted_cash'], 2, '.', '')
                    . '). No cash has moved since, so that count still stands. You can count again after the next cash sale, refund or cash in / out, or close the till now.',
            ];
        }
    }

    $vs = psCashReconciliationComputeVarianceStatus($counted, $expected);
    $variance = (string)$vs['variance'];
    $status = (string)$vs['status'];

    // Blind count (2026-10-17): a Till user cannot see expected cash, so asking for a note here would tell them
    // the count is off and let them retry until it balances. They explain the difference when closing the till.
    require_once __DIR__ . '/cash_controls.php';
    $blindCount = psCashControlsIsTillUser();
    if (($status === PS_CASH_RECON_STATUS_SHORTAGE || $status === PS_CASH_RECON_STATUS_SURPLUS) && !$blindCount) {
        if ($notes === '') {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return ['ok' => false, 'error' => 'A note is required when the till is short or over.'];
        }
    }

    $now = date('Y-m-d H:i:s');
    $lifecycle = PS_CASH_RECON_LIFECYCLE_FINAL;
    $notesStore = $notes === '' ? null : $notes;

    $hasCurCol = psFxHasColumn($conn, 'cash_reconciliations', 'currency');
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO cash_reconciliations
         (till_session_id, branch, reconciled_at, expected_cash, counted_cash, variance, status, lifecycle, notes, reconciled_by, created_at'
        . ($hasCurCol ? ', currency' : '') . ')
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($hasCurCol ? ", '" . mysqli_real_escape_string($conn, $reconCurrency) . "'" : '') . ')'
    );
    if (!$stmt) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('cash_reconciliations insert prepare failed');
        return ['ok' => false, 'error' => 'Reconciliation could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'issssssssss',
        $tillSessionId,
        $branch,
        $now,
        $expected,
        $counted,
        $variance,
        $status,
        $lifecycle,
        $notesStore,
        $user,
        $now
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('cash_reconciliations insert failed');
        return ['ok' => false, 'error' => 'Reconciliation could not be saved.'];
    }
    $reconId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $fields = [
        'lifecycle' => $lifecycle,
        'currency' => $reconCurrency,
        'status' => $status,
        'expected_cash' => $expected,
        'counted_cash' => $counted,
        'variance' => $variance,
        'notes' => $notesStore,
        'till_session_id' => (string)$tillSessionId,
        'branch' => $branch,
    ];
    $auditOk = true;
    foreach ($fields as $fieldName => $newValue) {
        $action = $fieldName === 'lifecycle' ? PS_CASH_RECON_AUDIT_FINALISE : PS_CASH_RECON_AUDIT_CREATE;
        $auditOk = $auditOk && psCashReconciliationAuditInsert(
            $conn,
            $reconId,
            $tillSessionId,
            $branch,
            $action,
            $fieldName,
            null,
            $newValue,
            $user,
            $now,
            PS_CASH_RECON_AUDIT_SOURCE_UI
        );
    }
    if (!$auditOk) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'Reconciliation could not be saved.'];
    }

    if ($owns && !mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Reconciliation could not be saved.'];
    }

    return [
        'ok' => true,
        'id' => $reconId,
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'expected' => $expected,
        'counted' => $counted,
        'currency' => $reconCurrency,
        'variance' => $variance,
        'status' => $status,
        'lifecycle' => $lifecycle,
    ];
}

/**
 * Branch-scoped read-only history.
 * Non-admin: forced to authorised working branch.
 * Admin: optional branch filter; empty/'all' = all branches (same pattern as purchasing reports).
 *
 * @param array<string,mixed> $filters branch?, limit?, offset?
 * @return array{ok:bool,rows?:array,error?:string,branch?:string,all_branches?:bool}
 */
function psCashReconciliationHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCashReconciliationsSchema($conn)) {
        return ['ok' => false, 'error' => 'Reconciliation history could not be loaded.'];
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
    if (psCashReconciliationIsAdmin()) {
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
        // Non-admin: ignore posted foreign branch; always session working branch.
        $branchFilter = $sessionBranch;
    }

    $limit = (int)($filters['limit'] ?? 50);
    $offset = (int)($filters['offset'] ?? 0);
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    // Till / Supervisor: only the counts they posted. Session ownership alone leaks other
    // cashiers on the same till (cashier_id / session_name prefix match).
    require_once __DIR__ . '/operator_scope.php';
    $ownSql = '';
    $who = [];
    if (psOperatorCashScoped()) {
        $me = trim(psOperatorUser());
        $email = trim((string)(tillCurrentCashier($conn)['email'] ?? ''));
        $who = array_values(array_unique(array_filter([$me, $email], static fn($v) => $v !== '')));
        if ($who === []) {
            return ['ok' => true, 'rows' => [], 'branch' => $branchFilter, 'all_branches' => $allBranches];
        }
        $ownSql = ' AND LOWER(TRIM(reconciled_by)) IN (' . implode(',', array_fill(0, count($who), 'LOWER(?)')) . ')';
    }
    $whoTypes = str_repeat('s', count($who));

    if ($allBranches) {
        require_once __DIR__ . '/product_master.php';
        $sql = 'SELECT id, till_session_id, branch, reconciled_at, expected_cash, counted_cash, variance,
                       status, lifecycle, notes, reconciled_by, created_at' . psCashReconciliationCurrencySelect($conn) . '
                FROM cash_reconciliations
                WHERE ' . psCompanyBranchesInSql($conn, 'branch') . $ownSql . '
                ORDER BY reconciled_at DESC, id DESC
                LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Reconciliation history could not be loaded.'];
        }
        $bind = array_merge($who, [$limit, $offset]);
        mysqli_stmt_bind_param($stmt, $whoTypes . 'ii', ...$bind);
    } else {
        $sql = 'SELECT id, till_session_id, branch, reconciled_at, expected_cash, counted_cash, variance,
                       status, lifecycle, notes, reconciled_by, created_at' . psCashReconciliationCurrencySelect($conn) . '
                FROM cash_reconciliations
                WHERE branch = ?' . $ownSql . '
                ORDER BY reconciled_at DESC, id DESC
                LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Reconciliation history could not be loaded.'];
        }
        $bind = array_merge([$branchFilter], $who, [$limit, $offset]);
        mysqli_stmt_bind_param($stmt, 's' . $whoTypes . 'ii', ...$bind);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['status_label'] = psCashReconciliationStatusLabel((string)$row['status']);
            $rows[] = $row;
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
 * Explicit: Phase 6.3 account payments still do not enter expected cash.
 */
function psCashReconciliationCustomerAccountPaymentInExpectedEnabled(): bool
{
    return false;
}

/**
 * Explicit: reconciling does not post balancing CASH_IN / CASH_OUT.
 */
function psCashReconciliationAutoBalanceMovementsEnabled(): bool
{
    return false;
}

function psCashReconciliationIssueSubmitToken(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['cash_reconciliation_submit_token'] = $token;
    return $token;
}

function psCashReconciliationConsumeSubmitToken($posted): bool
{
    $posted = trim((string)$posted);
    $known = trim((string)($_SESSION['cash_reconciliation_submit_token'] ?? ''));
    if ($posted === '' || $known === '' || !hash_equals($known, $posted)) {
        return false;
    }
    unset($_SESSION['cash_reconciliation_submit_token']);
    return true;
}
