<?php
/**
 * Phase 7.4 — Till Closure (Cashier Handover deferred — see limitations).
 *
 * Lifecycle on existing `sessions` (authoritative till-session; no second till table):
 *   OPEN  = operational / "ACTIVE" (no separate ACTIVE status column)
 *   CLOSED = irreversible closed
 * "RECONCILED" is not a sessions.status — it means a current FINAL row exists in
 * cash_reconciliations whose expected_cash equals the live ledger balance.
 *
 * Closure prerequisites (atomic):
 *   lock till FOR UPDATE → still OPEN → recalculate ledger expected →
 *   latest FINAL recon for SAME session → current (recon.expected == ledger) →
 *   variance/notes valid for non-zero → snapshot on sessions + audit → CLOSED.
 *
 * Does NOT insert/update/delete cash_movements. Does NOT auto-create or alter recon.
 * Does NOT reopen CLOSED sessions. Handover is not implemented (documented limitation).
 */

require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/cash_movement.php';
require_once __DIR__ . '/cash_reconciliation.php';

const PS_TILL_CLOSE_AUDIT_CLOSE = 'CLOSE';
const PS_TILL_CLOSE_AUDIT_SOURCE_UI = 'UI';
const PS_TILL_CLOSE_AUDIT_SOURCE_SYSTEM = 'SYSTEM';

function psSessionTillClosureUser(): string
{
    return trim((string)($_SESSION['logger'] ?? ''));
}

function psTillClosureIsAdmin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Smallest schema change: reuse sessions.expected_cash / actual_cash / variance as
 * closure snapshot; add closed_by, final_reconciliation_id, closure_notes,
 * reconciliation_status. No second till-session table. No cash_session_closures
 * duplication when sessions fields suffice.
 */
function psEnsureTillClosureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    if (!tillEnsureSchema($conn)) {
        return false;
    }
    mysqli_report(MYSQLI_REPORT_OFF);

    $extra = [
        'closed_by' => 'VARCHAR(250) NULL',
        'final_reconciliation_id' => 'INT NULL',
        'closure_notes' => 'TEXT NULL',
        'reconciliation_status' => 'VARCHAR(32) NULL',
    ];
    foreach ($extra as $column => $definition) {
        if (!tillAddColumn($conn, 'sessions', $column, $definition)) {
            return false;
        }
    }

    // Phase 7.4: allow multiple FINAL recon snapshots per session (latest = current).
    // Historical rows remain immutable; does not redesign expected-cash maths.
    if (!psEnsureCashReconciliationsSchema($conn)) {
        return false;
    }
    if (tillHasIndex($conn, 'cash_reconciliations', 'uniq_cash_recon_session')) {
        $dropped = mysqli_query($conn, 'ALTER TABLE cash_reconciliations DROP INDEX uniq_cash_recon_session');
        if (!$dropped && mysqli_errno($conn) !== 1091) {
            error_log('till_closure: could not drop uniq_cash_recon_session');
            // Continue — app uses latest FINAL; UNIQUE would block fresh recon after stale.
        }
    }
    if (!tillHasIndex($conn, 'cash_reconciliations', 'idx_cash_recon_session')) {
        $idx = mysqli_query($conn, 'ALTER TABLE cash_reconciliations ADD KEY idx_cash_recon_session (till_session_id)');
        if (!$idx && mysqli_errno($conn) !== 1061) {
            error_log('till_closure: could not add idx_cash_recon_session');
        }
    }

    $ready = true;
    return true;
}

/**
 * Append-only closure audit (dedicated — not mixed into cash_movement / recon audits).
 */
function psEnsureTillClosureAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM till_closure_audit LIMIT 0');
    if (!$check) {
        $sql = "CREATE TABLE IF NOT EXISTS till_closure_audit (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            till_session_id INT NOT NULL,
            branch VARCHAR(250) NOT NULL,
            action VARCHAR(32) NOT NULL,
            field_name VARCHAR(64) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            changed_by VARCHAR(250) NOT NULL DEFAULT '',
            changed_at DATETIME NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'UI',
            KEY idx_till_close_audit_session (till_session_id),
            KEY idx_till_close_audit_branch (branch),
            KEY idx_till_close_audit_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) {
            error_log('till_closure_audit schema was not created');
            return false;
        }
    }
    $ready = true;
    return true;
}

function psEnsureTillClosureModule(mysqli $conn): bool
{
    return psEnsureTillClosureSchema($conn)
        && psEnsureTillClosureAuditSchema($conn)
        && psEnsureCashReconciliationModule($conn)
        && psEnsureCashMovementModule($conn);
}

function psTillClosureAuditInsert(
    mysqli $conn,
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
    if (!psEnsureTillClosureAuditSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO till_closure_audit
         (till_session_id, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('till_closure_audit prepare failed');
        return false;
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    mysqli_stmt_bind_param(
        $stmt,
        'issssssss',
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
        error_log('till_closure_audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

function psTillClosureIssueSubmitToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['till_close_submit_token'] = $token;
    return $token;
}

function psTillClosureConsumeSubmitToken($posted): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $posted = trim((string)$posted);
    $expected = trim((string)($_SESSION['till_close_submit_token'] ?? ''));
    if ($posted === '' || $expected === '' || !hash_equals($expected, $posted)) {
        return false;
    }
    unset($_SESSION['till_close_submit_token']);
    return true;
}

/**
 * Latest FINAL reconciliation for a till session (ORDER BY id DESC).
 * Phase 7.4: multiple FINAL snapshots allowed; close uses the latest.
 *
 * @return array{ok:bool,exists?:bool,row?:array|null,error?:string}
 */
function psTillClosureFindLatestFinalRecon(mysqli $conn, int $tillSessionId, bool $lock = false, string $currency = ''): array
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

/**
 * Current = latest FINAL expected_cash equals live ledger balance (string money).
 *
 * @return array{ok:bool,current?:bool,expected?:string,ledger?:string,recon?:array|null,error?:string,stale?:bool,missing?:bool}
 */
function psTillClosureAssessFinalReconCurrency(mysqli $conn, int $tillSessionId, string $currency = ''): array
{
    $ledgerRes = psCashMovementBalance($conn, $tillSessionId, $currency);
    if (empty($ledgerRes['ok'])) {
        return ['ok' => false, 'error' => $ledgerRes['error'] ?? 'Expected cash could not be calculated.'];
    }
    $ledger = number_format((float)$ledgerRes['balance'], 2, '.', '');

    $found = psTillClosureFindLatestFinalRecon($conn, $tillSessionId, false, $currency);
    if (!$found['ok']) {
        return ['ok' => false, 'error' => $found['error'] ?? 'Reconciliation could not be loaded.'];
    }
    if (empty($found['exists']) || empty($found['row'])) {
        return [
            'ok' => true,
            'current' => false,
            'missing' => true,
            'stale' => false,
            'ledger' => $ledger,
            'expected' => null,
            'recon' => null,
        ];
    }
    $recon = $found['row'];
    $expected = number_format((float)($recon['expected_cash'] ?? 0), 2, '.', '');
    $current = ($expected === $ledger);
    return [
        'ok' => true,
        'current' => $current,
        'missing' => false,
        'stale' => !$current,
        'ledger' => $ledger,
        'expected' => $expected,
        'recon' => $recon,
        'detail' => $ledgerRes['detail'] ?? [],
    ];
}

/**
 * Multi-currency (2026-10-20): assessment for every drawer currency on the till (base first).
 * @return array<string,array> currency => psTillClosureAssessFinalReconCurrency() result
 */
function psTillClosureAssessAllCurrencies(mysqli $conn, int $tillSessionId): array
{
    $out = [];
    foreach (psCashMovementTillCurrencies($conn, $tillSessionId) as $code) {
        $out[$code] = psTillClosureAssessFinalReconCurrency($conn, $tillSessionId, $code);
    }
    return $out;
}

/**
 * Resolve caller's own OPEN till for closure (ignore posted session/branch/cashier).
 *
 * @param array<string,mixed> $options
 * @return array{ok:bool,session?:array,branch?:string,cashier?:array,error?:string}
 */
function psTillClosureResolveOperationalSession(mysqli $conn, array $options = []): array
{
    return psCashMovementResolveOperationalSession($conn, $options);
}

/**
 * Atomic till close. Never alters cash_movements or cash_reconciliations rows.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,till_session_id?:int,error?:string,duplicate?:bool,stale?:bool,missing_recon?:bool}
 */
function psTillClosureClose(mysqli $conn, array $data, bool $manageTransaction = true): array
{
    if (!psEnsureTillClosureModule($conn)) {
        return ['ok' => false, 'error' => 'The till could not be closed.'];
    }
    $user = psSessionTillClosureUser();
    if ($user === '') {
        return ['ok' => false, 'error' => 'You must be signed in to close the till.'];
    }

    // Ignore tampered ownership / money / status posts — server recalculates.
    unset(
        $data['expected_cash'],
        $data['expected'],
        $data['counted_cash'],
        $data['counted'],
        $data['variance'],
        $data['status'],
        $data['reconciliation_status'],
        $data['closed_by'],
        $data['cashier'],
        $data['cashier_email'],
        $data['cashier_id'],
        $data['final_reconciliation_id']
    );

    $confirm = strtoupper(trim((string)($data['confirm_close'] ?? $data['confirm'] ?? '')));
    if ($confirm !== '1' && $confirm !== 'YES' && $confirm !== 'CLOSE') {
        return ['ok' => false, 'error' => 'Confirm till closure before submitting.'];
    }

    $notes = trim((string)($data['notes'] ?? $data['closure_notes'] ?? ''));
    if (strlen($notes) > 2000) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The till could not be closed.'];
    }

    // Phase 7.4 security: ignore posted till_session_id / branch / cashier entirely.
    // Always resolve the caller's own OPEN till on the authenticated working branch.
    $resolved = psTillClosureResolveOperationalSession($conn, [
        'posted_till_session_id' => 0,
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

    // Re-verify OPEN + own + branch after lock (concurrency with sale / IN / OUT / close).
    $recheck = tillSessionById($conn, $tillSessionId);
    if (!$recheck || !tillRowIsOpen($recheck) || !tillOwns($recheck, $cashier)) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        // Already closed by concurrent closer → treat as duplicate close.
        if ($recheck && !tillRowIsOpen($recheck)) {
            return [
                'ok' => false,
                'error' => 'This till session is already closed.',
                'duplicate' => true,
            ];
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
            'error' => 'This till has no branch assigned. Bind it via Cash Reconciliation before closing, or contact an administrator.',
        ];
    }
    if ($sessionBranch !== $branch) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'Your till is open on ' . $sessionBranch
                . '. Close that till from its own branch, or switch working branch back to '
                . $sessionBranch . '.',
        ];
    }

    // Already has closure snapshot? Immutable — reject edit/re-close.
    if ((int)($recheck['final_reconciliation_id'] ?? 0) > 0
        && strtoupper(trim((string)($recheck['status'] ?? ''))) === 'CLOSED') {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'This till session is already closed.',
            'duplicate' => true,
        ];
    }

    // Multi-currency: every other drawer currency on this till needs its own current count first.
    // The base currency then goes through the original checks below and fills the session's closing columns.
    $closeBase = psFxTillBase($conn, $tillSessionId);
    $otherNotes = [];
    foreach (psCashMovementTillCurrencies($conn, $tillSessionId) as $cur) {
        if ($cur === $closeBase) {
            continue;
        }
        $curBal = psCashMovementBalance($conn, $tillSessionId, $cur);
        $curFound = psTillClosureFindLatestFinalRecon($conn, $tillSessionId, true, $cur);
        if (empty($curBal['ok']) || empty($curFound['ok'])) {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return ['ok' => false, 'error' => 'Expected cash could not be calculated.'];
        }
        if (empty($curFound['exists']) || empty($curFound['row'])) {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return ['ok' => false, 'error' => 'Count the ' . $cur . ' cash too: a current final reconciliation is needed for every currency on the till before closing.',
                'missing_recon' => true, 'currency' => $cur];
        }
        $curRow = $curFound['row'];
        if (number_format((float)$curRow['expected_cash'], 2, '.', '') !== number_format((float)$curBal['balance'], 2, '.', '')) {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return ['ok' => false, 'error' => 'The latest ' . $cur . ' reconciliation is out of date. Count the ' . $cur . ' cash again before closing.',
                'stale' => true, 'currency' => $cur];
        }
        $curStatus = strtoupper(trim((string)($curRow['status'] ?? '')));
        if (($curStatus === PS_CASH_RECON_STATUS_SHORTAGE || $curStatus === PS_CASH_RECON_STATUS_SURPLUS)
            && $notes === '' && trim((string)($curRow['notes'] ?? '')) === '') {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return ['ok' => false, 'error' => 'A note is required when closing with a ' . $cur . ' shortage or surplus.'];
        }
        $otherNotes[] = $cur . ': expected ' . number_format((float)$curRow['expected_cash'], 2, '.', '') . ', counted '
            . number_format((float)$curRow['counted_cash'], 2, '.', '') . ' (' . strtolower($curStatus) . ', count #' . (int)$curRow['id'] . ')';
    }

    // Recalculate ledger expected inside txn (ignore previously displayed expected).
    $ledgerRes = psCashMovementBalance($conn, $tillSessionId, $closeBase);
    if (empty($ledgerRes['ok'])) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => $ledgerRes['error'] ?? 'Expected cash could not be calculated.'];
    }
    $ledger = number_format((float)$ledgerRes['balance'], 2, '.', '');

    $found = psTillClosureFindLatestFinalRecon($conn, $tillSessionId, true, $closeBase);
    if (!$found['ok']) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => $found['error'] ?? 'Reconciliation could not be loaded.'];
    }
    if (empty($found['exists']) || empty($found['row'])) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'A current final cash reconciliation is required before closing the till.',
            'missing_recon' => true,
        ];
    }
    $recon = $found['row'];
    if ((int)($recon['till_session_id'] ?? 0) !== $tillSessionId) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'The reconciliation does not belong to this till session.'];
    }

    $reconExpected = number_format((float)($recon['expected_cash'] ?? 0), 2, '.', '');
    if ($reconExpected !== $ledger) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'The latest reconciliation is out of date. Complete a fresh cash reconciliation before closing.',
            'stale' => true,
            'ledger' => $ledger,
            'recon_expected' => $reconExpected,
        ];
    }

    $counted = number_format((float)($recon['counted_cash'] ?? 0), 2, '.', '');
    $variance = number_format((float)($recon['variance'] ?? 0), 2, '.', '');
    $reconStatus = strtoupper(trim((string)($recon['status'] ?? '')));
    if (!in_array($reconStatus, psCashReconciliationStatuses(), true)) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'The reconciliation status is not valid.'];
    }

    // Non-zero variance: allow SHORTAGE/SURPLUS with notes (from recon and/or closure notes).
    $reconNotes = trim((string)($recon['notes'] ?? ''));
    if ($reconStatus === PS_CASH_RECON_STATUS_SHORTAGE || $reconStatus === PS_CASH_RECON_STATUS_SURPLUS) {
        $effectiveNotes = $notes !== '' ? $notes : $reconNotes;
        if ($effectiveNotes === '') {
            if ($owns) {
                mysqli_rollback($conn);
            }
            return [
                'ok' => false,
                'error' => 'A note is required when closing with a shortage or surplus.',
            ];
        }
        if ($notes === '') {
            $notes = $reconNotes;
        }
    }

    $now = date('Y-m-d H:i:s');
    $reconId = (int)$recon['id'];
    if ($otherNotes) {
        // The session's expected/actual/variance columns hold the base drawer; the other currencies are summarised here.
        $notes = trim($notes . "\n[Other currencies] " . implode('; ', $otherNotes));
        if (strlen($notes) > 2000) {
            $notes = substr($notes, 0, 2000);
        }
    }
    $notesStore = $notes === '' ? null : $notes;
    $prevStatus = strtoupper(trim((string)($recheck['status'] ?? 'OPEN')));

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE sessions
         SET status = 'CLOSED',
             session_end = ?,
             closed_at = ?,
             updated_at = ?,
             expected_cash = ?,
             actual_cash = ?,
             variance = ?,
             closed_by = ?,
             final_reconciliation_id = ?,
             closure_notes = ?,
             reconciliation_status = ?
         WHERE id = ? AND status = 'OPEN' AND session_end = ''"
    );
    if (!$stmt) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('till_closure: close update prepare failed');
        return ['ok' => false, 'error' => 'The till could not be closed.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssissi',
        $now,
        $now,
        $now,
        $ledger,
        $counted,
        $variance,
        $user,
        $reconId,
        $notesStore,
        $reconStatus,
        $tillSessionId
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('till_closure: close update failed');
        return ['ok' => false, 'error' => 'The till could not be closed.'];
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if ($affected < 1) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return [
            'ok' => false,
            'error' => 'This till session is already closed.',
            'duplicate' => true,
        ];
    }

    $fields = [
        'status' => ['old' => $prevStatus, 'new' => 'CLOSED'],
        'final_reconciliation_id' => ['old' => null, 'new' => (string)$reconId],
        'expected_cash' => ['old' => null, 'new' => $ledger],
        'actual_cash' => ['old' => null, 'new' => $counted],
        'variance' => ['old' => null, 'new' => $variance],
        'reconciliation_status' => ['old' => null, 'new' => $reconStatus],
        'closure_notes' => ['old' => null, 'new' => $notesStore],
        'closed_by' => ['old' => null, 'new' => $user],
        'closed_at' => ['old' => null, 'new' => $now],
    ];
    $auditOk = true;
    foreach ($fields as $fieldName => $pair) {
        $auditOk = $auditOk && psTillClosureAuditInsert(
            $conn,
            $tillSessionId,
            $branch,
            PS_TILL_CLOSE_AUDIT_CLOSE,
            $fieldName,
            $pair['old'],
            $pair['new'],
            $user,
            $now,
            PS_TILL_CLOSE_AUDIT_SOURCE_UI
        );
    }
    if (!$auditOk) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        return ['ok' => false, 'error' => 'The till could not be closed.'];
    }

    if ($owns && !mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The till could not be closed.'];
    }

    return [
        'ok' => true,
        'till_session_id' => $tillSessionId,
        'branch' => $branch,
        'final_reconciliation_id' => $reconId,
        'expected_cash' => $ledger,
        'counted_cash' => $counted,
        'variance' => $variance,
        'reconciliation_status' => $reconStatus,
        'closed_at' => $now,
        'closed_by' => $user,
    ];
}

/**
 * Explicitly blocked: no reopen of CLOSED till sessions.
 *
 * @return array{ok:bool,error?:string}
 */
function psTillClosureReopenBlocked(): array
{
    return [
        'ok' => false,
        'error' => 'Closed till sessions cannot be reopened. Open a new till session instead.',
    ];
}

/**
 * Cashier handover — not implemented. Session model keeps a single owner for the
 * life of an OPEN session; transferring mid-session risks open_guard races and
 * ambiguous attribution. Close the till, then the next cashier opens a new session.
 *
 * @return array{ok:bool,error?:string,enabled?:bool}
 */
function psTillClosureHandoverEnabled(): bool
{
    return false;
}

function psTillClosureHandover(mysqli $conn, array $data): array
{
    unset($conn, $data);
    return [
        'ok' => false,
        'enabled' => false,
        'error' => 'Cashier handover is not available. Close the till and open a new session for the incoming cashier.',
    ];
}

/**
 * Branch-scoped closed-session history for authorised users.
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,rows?:array,error?:string}
 */
function psTillClosureHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureTillClosureModule($conn)) {
        return ['ok' => false, 'error' => 'Closure history could not be loaded.'];
    }

    require_once __DIR__ . '/product_master.php';
    $sessionBranch = function_exists('psSessionProductBranch')
        ? psSessionProductBranch()
        : tillSelectedBranch();
    if ($sessionBranch === '') {
        $sessionBranch = tillSelectedBranch();
    }

    $limit = (int)($filters['limit'] ?? 50);
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }
    $offset = (int)($filters['offset'] ?? 0);
    if ($offset < 0) {
        $offset = 0;
    }

    $params = [];
    $types = '';
    $where = ["s.status = 'CLOSED'", 's.final_reconciliation_id IS NOT NULL'];

    if (psTillClosureIsAdmin()) {
        $branchFilter = trim((string)($filters['branch'] ?? ''));
        if ($branchFilter !== '' && strtolower($branchFilter) !== 'all') {
            $where[] = 's.branch = ?';
            $types .= 's';
            $params[] = $branchFilter;
        }
    } else {
        if ($sessionBranch === '') {
            return ['ok' => true, 'rows' => []];
        }
        $where[] = 's.branch = ?';
        $types .= 's';
        $params[] = $sessionBranch;
    }

    $sql = 'SELECT s.id, s.branch, s.register_id, s.cashier_email, s.opening_float, s.opened_at,
                   s.closed_at, s.closed_by, s.expected_cash, s.actual_cash, s.variance,
                   s.reconciliation_status, s.final_reconciliation_id, s.closure_notes,
                   r.name AS register_name
            FROM sessions s
            LEFT JOIN registers r ON r.id = s.register_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.closed_at DESC, s.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Closure history could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}
