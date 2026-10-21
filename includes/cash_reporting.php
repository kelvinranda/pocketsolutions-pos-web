<?php
/**
 * Phase 7.5 — Cash Management Dashboard & Reporting (read-only).
 *
 * Authoritative sources (never goods.quantity / cached balances / browser totals):
 *   - Till sessions: sessions
 *   - Cash ledger: cash_movements via psCashMovementBalance()
 *   - Reconciliation: cash_reconciliations
 *   - Closure: sessions closure snapshot + till_closure_audit
 *   - Audits: cash_movement_audit, cash_reconciliation_audit, till_closure_audit
 *
 * Expected cash formula (OPEN tills / live): reuse psCashMovementBalance only —
 *   OPENING_FLOAT + CASH_SALE + CASH_IN − CASH_REFUND − CASH_OUT
 * CLOSED tills: use stored closure snapshot for closure figures; ledger remains audit-available.
 *
 * Reporting never mutates financial data (no create/edit/delete movements, recon, close, reopen).
 *
 * Phase 6.3 customer account payments and non-cash tenders are excluded from expected cash
 * (same boundary as 7.1–7.4). Legacy submit_sales.php / ajax_pos2.php / mobile sync_sales
 * remain outside the modern cash ledger.
 *
 * Timezone: Africa/Harare (Zimbabwe local) — not silent UTC.
 */

require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/cash_movement.php';
require_once __DIR__ . '/cash_reconciliation.php';
require_once __DIR__ . '/till_closure.php';
require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/purchasing_reports.php';

const PS_CASH_REPORT_TZ = 'Africa/Harare';

const PS_CASH_REPORT_STATUS_NO_RECON = 'NO_RECON';
const PS_CASH_REPORT_STATUS_AWAITING = 'AWAITING';
const PS_CASH_REPORT_STATUS_CURRENT = 'CURRENT';
const PS_CASH_REPORT_STATUS_STALE = 'STALE';
const PS_CASH_REPORT_STATUS_CLOSED = 'CLOSED';
const PS_CASH_REPORT_STATUS_BALANCED = 'BALANCED';
const PS_CASH_REPORT_STATUS_SHORTAGE = 'SHORTAGE';
const PS_CASH_REPORT_STATUS_SURPLUS = 'SURPLUS';
const PS_CASH_REPORT_STATUS_RECONCILED = 'RECONCILED';

/**
 * Ensure Africa/Harare for cash reporting date bounds (app local TZ).
 */
function psCashReportingEnsureTimezone(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (function_exists('date_default_timezone_set')) {
        @date_default_timezone_set(PS_CASH_REPORT_TZ);
    }
    $done = true;
}

function psCashReportingIsAdmin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

function psSessionCashReportingUser(): string
{
    return trim((string)($_SESSION['logger'] ?? ''));
}

/**
 * Money add/sub helpers (string decimals).
 */
function psCashReportingMoneyAdd(string $a, string $b): string
{
    if (function_exists('bcadd')) {
        return number_format((float)bcadd($a, $b, 2), 2, '.', '');
    }
    return number_format((float)$a + (float)$b, 2, '.', '');
}

function psCashReportingMoneySub(string $a, string $b): string
{
    if (function_exists('bcsub')) {
        return number_format((float)bcsub($a, $b, 2), 2, '.', '');
    }
    return number_format((float)$a - (float)$b, 2, '.', '');
}

/**
 * Resolve inclusive date range. End date includes full local day (… 23:59:59).
 * Empty dates → no bound. Uses Africa/Harare.
 *
 * @return array{ok:bool,from_dt?:?string,to_dt?:?string,from_date?:?string,to_date?:?string,error?:string}
 */
function psCashReportingResolveDateRange($fromDate = '', $toDate = ''): array
{
    psCashReportingEnsureTimezone();
    $from = trim((string)$fromDate);
    $to = trim((string)$toDate);
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return ['ok' => false, 'error' => 'From date is not valid.'];
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return ['ok' => false, 'error' => 'To date is not valid.'];
    }
    if ($from !== '' && $to !== '' && strcmp($from, $to) > 0) {
        return ['ok' => false, 'error' => 'From date cannot be after to date.'];
    }
    $fromDt = $from !== '' ? ($from . ' 00:00:00') : null;
    $toDt = $to !== '' ? ($to . ' 23:59:59') : null;
    return [
        'ok' => true,
        'from_date' => $from !== '' ? $from : null,
        'to_date' => $to !== '' ? $to : null,
        'from_dt' => $fromDt,
        'to_dt' => $toDt,
    ];
}

/**
 * Branch security: ordinary → own working branch only; admin → all + filter.
 * Ignores posted foreign branch for non-admin. Reuses purchasing-reports pattern.
 *
 * @return array{ok:bool,branch?:string,all_branches?:bool,error?:string}
 */
function psCashReportingResolveBranch(mysqli $conn, $postedBranch = null): array
{
    return psPurchasingResolveReportBranch($conn, $postedBranch);
}

/**
 * Ensure reporting indexes (idempotent). Only necessary composites.
 */
function psEnsureCashReportingIndexes(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!tillEnsureSchema($conn) || !psEnsureCashMovementModule($conn)
        || !psEnsureCashReconciliationModule($conn) || !psEnsureTillClosureModule($conn)) {
        return false;
    }

    $indexes = [
        ['sessions', 'idx_sessions_status_branch', 'ALTER TABLE sessions ADD KEY idx_sessions_status_branch (status, branch)'],
        ['sessions', 'idx_sessions_closed_at', 'ALTER TABLE sessions ADD KEY idx_sessions_closed_at (closed_at)'],
        ['sessions', 'idx_sessions_cashier_email', 'ALTER TABLE sessions ADD KEY idx_sessions_cashier_email (cashier_email)'],
        ['cash_movements', 'idx_cash_mov_branch_at', 'ALTER TABLE cash_movements ADD KEY idx_cash_mov_branch_at (branch, movement_at)'],
        ['cash_reconciliations', 'idx_cash_recon_branch_at', 'ALTER TABLE cash_reconciliations ADD KEY idx_cash_recon_branch_at (branch, reconciled_at)'],
    ];
    foreach ($indexes as $spec) {
        [$table, $name, $ddl] = $spec;
        if (!tillHasIndex($conn, $table, $name)) {
            $ok = mysqli_query($conn, $ddl);
            if (!$ok && mysqli_errno($conn) !== 1061) {
                error_log('cash reporting index was not added: ' . $name);
            }
        }
    }
    $ready = true;
    return true;
}

function psEnsureCashReportingModule(mysqli $conn): bool
{
    psCashReportingEnsureTimezone();
    return psEnsureCashReportingIndexes($conn);
}

/**
 * Human labels for reporting status codes.
 */
function psCashReportingStatusLabel(string $status): string
{
    $labels = [
        PS_CASH_REPORT_STATUS_NO_RECON => 'No recon',
        PS_CASH_REPORT_STATUS_AWAITING => 'Awaiting',
        PS_CASH_REPORT_STATUS_CURRENT => 'Current',
        PS_CASH_REPORT_STATUS_STALE => 'Stale',
        PS_CASH_REPORT_STATUS_CLOSED => 'Closed',
        PS_CASH_REPORT_STATUS_BALANCED => 'Balanced',
        PS_CASH_REPORT_STATUS_SHORTAGE => 'Shortage',
        PS_CASH_REPORT_STATUS_SURPLUS => 'Surplus',
        PS_CASH_REPORT_STATUS_RECONCILED => 'Reconciled',
    ];
    $status = strtoupper(trim($status));
    return $labels[$status] ?? $status;
}

/**
 * Classify open-till recon currency + variance status from latest FINAL + live ledger.
 * Does NOT call "balanced" merely because a recon exists — uses actual variance.
 *
 * @param array<string,mixed>|null $recon
 * @return array{
 *   ok:bool,
 *   session_status:string,
 *   has_recon:bool,
 *   currency:string,
 *   variance_status:?string,
 *   variance:?string,
 *   recon_expected:?string,
 *   ledger_expected:string,
 *   stale:bool,
 *   current:bool,
 *   display_status:string,
 *   recon?:?array,
 *   detail?:array
 * }
 */
function psCashReportingClassifySession(
    mysqli $conn,
    array $session,
    ?array $recon = null,
    ?array $ledgerBalance = null
): array {
    $sessionStatus = strtoupper(trim((string)($session['status'] ?? '')));
    $tillSessionId = (int)($session['id'] ?? 0);

    if ($sessionStatus === 'CLOSED') {
        $varStatus = strtoupper(trim((string)($session['reconciliation_status'] ?? '')));
        $variance = isset($session['variance'])
            ? number_format((float)$session['variance'], 2, '.', '')
            : null;
        $expected = isset($session['expected_cash'])
            ? number_format((float)$session['expected_cash'], 2, '.', '')
            : '0.00';
        return [
            'ok' => true,
            'session_status' => 'CLOSED',
            'has_recon' => !empty($session['final_reconciliation_id']),
            'currency' => PS_CASH_REPORT_STATUS_CLOSED,
            'variance_status' => $varStatus !== '' ? $varStatus : null,
            'variance' => $variance,
            'recon_expected' => $expected,
            'ledger_expected' => $expected,
            'stale' => false,
            'current' => false,
            'display_status' => PS_CASH_REPORT_STATUS_CLOSED,
            'recon' => $recon,
            'detail' => [],
        ];
    }

    if ($ledgerBalance === null) {
        $ledgerBalance = psCashMovementBalance($conn, $tillSessionId);
    }
    if (empty($ledgerBalance['ok'])) {
        return ['ok' => false, 'error' => $ledgerBalance['error'] ?? 'Expected cash could not be calculated.'];
    }
    $ledger = number_format((float)$ledgerBalance['balance'], 2, '.', '');
    $detail = $ledgerBalance['detail'] ?? [];

    if ($recon === null) {
        $found = psCashReconciliationFindFinalBySession($conn, $tillSessionId, false);
        $recon = (!empty($found['ok']) && !empty($found['exists'])) ? $found['row'] : null;
    }

    if ($recon === null) {
        return [
            'ok' => true,
            'session_status' => 'OPEN',
            'has_recon' => false,
            'currency' => PS_CASH_REPORT_STATUS_AWAITING,
            'variance_status' => null,
            'variance' => null,
            'recon_expected' => null,
            'ledger_expected' => $ledger,
            'stale' => false,
            'current' => false,
            'display_status' => PS_CASH_REPORT_STATUS_AWAITING,
            'recon' => null,
            'detail' => $detail,
        ];
    }

    $reconExpected = number_format((float)($recon['expected_cash'] ?? 0), 2, '.', '');
    $variance = number_format((float)($recon['variance'] ?? 0), 2, '.', '');
    $varStatus = strtoupper(trim((string)($recon['status'] ?? '')));
    $isCurrent = ($reconExpected === $ledger);
    $currency = $isCurrent ? PS_CASH_REPORT_STATUS_CURRENT : PS_CASH_REPORT_STATUS_STALE;

    // Prefer variance status for display when recon exists; attach currency separately.
    $display = $varStatus !== '' ? $varStatus : PS_CASH_REPORT_STATUS_RECONCILED;

    return [
        'ok' => true,
        'session_status' => 'OPEN',
        'has_recon' => true,
        'currency' => $currency,
        'variance_status' => $varStatus !== '' ? $varStatus : null,
        'variance' => $variance,
        'recon_expected' => $reconExpected,
        'ledger_expected' => $ledger,
        'stale' => !$isCurrent,
        'current' => $isCurrent,
        'display_status' => $display,
        'recon' => $recon,
        'detail' => $detail,
        'reconciled' => true,
    ];
}

/**
 * Batch ledger balances for many till session ids (one aggregation query).
 *
 * @param list<int> $sessionIds
 * @return array<int,array{ok:bool,balance:string,detail:array}>
 */
function psCashReportingBatchBalances(mysqli $conn, array $sessionIds): array
{
    $out = [];
    $ids = [];
    foreach ($sessionIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return $out;
    }
    if (!psEnsureCashMovementsSchema($conn)) {
        return $out;
    }
    $idList = implode(',', array_map('intval', array_values($ids)));
    $sql = "SELECT till_session_id,
                COALESCE(SUM(CASE WHEN movement_type = 'OPENING_FLOAT' THEN amount ELSE 0 END), 0) AS opening_float,
                COALESCE(SUM(CASE WHEN movement_type = 'CASH_SALE' THEN amount ELSE 0 END), 0) AS cash_sales,
                COALESCE(SUM(CASE WHEN movement_type = 'CASH_IN' THEN amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN movement_type = 'CASH_REFUND' THEN amount ELSE 0 END), 0) AS cash_refunds,
                COALESCE(SUM(CASE WHEN movement_type = 'CASH_OUT' THEN amount ELSE 0 END), 0) AS cash_out
            FROM cash_movements
            WHERE till_session_id IN ($idList)
            GROUP BY till_session_id";
    $res = mysqli_query($conn, $sql);
    $found = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = (int)$row['till_session_id'];
            // Same formula as psCashMovementBalance — via psCashMovementComputeBalanceFromParts.
            $computed = psCashMovementComputeBalanceFromParts($row);
            $found[$sid] = true;
            $out[$sid] = [
                'ok' => true,
                'balance' => $computed['balance'],
                'detail' => $computed['detail'],
            ];
        }
    }
    foreach ($ids as $sid) {
        if (empty($found[$sid])) {
            $empty = psCashMovementComputeBalanceFromParts([]);
            $out[$sid] = [
                'ok' => true,
                'balance' => $empty['balance'],
                'detail' => $empty['detail'],
            ];
        }
    }
    return $out;
}

/**
 * Latest FINAL recon per till session (batch).
 *
 * @param list<int> $sessionIds
 * @return array<int,array>
 */
function psCashReportingBatchLatestRecons(mysqli $conn, array $sessionIds): array
{
    $out = [];
    $ids = [];
    foreach ($sessionIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === [] || !psEnsureCashReconciliationsSchema($conn)) {
        return $out;
    }
    $idList = implode(',', array_map('intval', array_values($ids)));
    $lifecycle = PS_CASH_RECON_LIFECYCLE_FINAL;
    // Latest FINAL per session via id MAX.
    $sql = "SELECT cr.*
            FROM cash_reconciliations cr
            INNER JOIN (
                SELECT till_session_id, MAX(id) AS max_id
                FROM cash_reconciliations
                WHERE till_session_id IN ($idList) AND lifecycle = '" . mysqli_real_escape_string($conn, $lifecycle) . "'
                GROUP BY till_session_id
            ) t ON t.max_id = cr.id";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[(int)$row['till_session_id']] = $row;
        }
    }
    return $out;
}

/**
 * Normalise dashboard / report filters (server-side). Ignores posted expected/totals.
 *
 * @param array<string,mixed> $raw
 * @return array{ok:bool,filters?:array,error?:string}
 */
function psCashReportingNormaliseFilters(mysqli $conn, array $raw): array
{
    $branchRes = psCashReportingResolveBranch($conn, $raw['branch'] ?? null);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
    }
    $dates = psCashReportingResolveDateRange($raw['from_date'] ?? '', $raw['to_date'] ?? '');
    if (!$dates['ok']) {
        return ['ok' => false, 'error' => $dates['error'] ?? 'Date range is not valid.'];
    }

    $tillSessionId = (int)($raw['till_session_id'] ?? $raw['session_id'] ?? 0);
    $registerId = (int)($raw['register_id'] ?? $raw['till'] ?? 0);
    $cashier = trim((string)($raw['cashier'] ?? $raw['cashier_email'] ?? $raw['user'] ?? ''));
    $status = strtoupper(trim((string)($raw['status'] ?? '')));
    $movementType = strtoupper(trim((string)($raw['movement_type'] ?? $raw['type'] ?? '')));
    $ref = trim((string)($raw['ref'] ?? $raw['reference'] ?? ''));
    $sessionStatus = strtoupper(trim((string)($raw['session_status'] ?? '')));
    $page = max(1, (int)($raw['page'] ?? 1));
    $pageSize = (int)($raw['page_size'] ?? 25);
    if ($pageSize < 1) {
        $pageSize = 25;
    }
    if ($pageSize > 200) {
        $pageSize = 200;
    }

    if ($movementType !== '' && !isset(psCashMovementTypes()[$movementType])) {
        return ['ok' => false, 'error' => 'Movement type is not valid.'];
    }
    if ($sessionStatus !== '' && !in_array($sessionStatus, ['OPEN', 'CLOSED'], true)) {
        return ['ok' => false, 'error' => 'Session status is not valid.'];
    }

    $allowedStatus = [
        '', 'ALL',
        PS_CASH_REPORT_STATUS_NO_RECON,
        PS_CASH_REPORT_STATUS_AWAITING,
        PS_CASH_REPORT_STATUS_CURRENT,
        PS_CASH_REPORT_STATUS_STALE,
        PS_CASH_REPORT_STATUS_CLOSED,
        PS_CASH_REPORT_STATUS_BALANCED,
        PS_CASH_REPORT_STATUS_SHORTAGE,
        PS_CASH_REPORT_STATUS_SURPLUS,
        PS_CASH_REPORT_STATUS_RECONCILED,
        'OPEN',
    ];
    if ($status !== '' && !in_array($status, $allowedStatus, true)) {
        // Allow recon variance statuses already covered; otherwise soft-ignore unknown.
        $status = '';
    }
    if ($status === 'ALL') {
        $status = '';
    }

    return [
        'ok' => true,
        'filters' => [
            'branch' => (string)($branchRes['branch'] ?? ''),
            'all_branches' => !empty($branchRes['all_branches']),
            'from_date' => $dates['from_date'],
            'to_date' => $dates['to_date'],
            'from_dt' => $dates['from_dt'],
            'to_dt' => $dates['to_dt'],
            'till_session_id' => $tillSessionId > 0 ? $tillSessionId : 0,
            'register_id' => $registerId > 0 ? $registerId : 0,
            'cashier' => $cashier,
            'status' => $status,
            'movement_type' => $movementType,
            'ref' => $ref,
            'session_status' => $sessionStatus,
            'page' => $page,
            'page_size' => $pageSize,
        ],
    ];
}

/**
 * Dashboard summary cards + open till rows + recent lists + movement summary.
 * Read-only. Shortage and surplus are separate (never netted).
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,cards?:array,open_tills?:array,stale_warnings?:array,recent_recons?:array,recent_closures?:array,movement_summary?:array,error?:string}
 */
function psCashReportingDashboard(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Cash management dashboard could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in to view cash management.'];
    }

    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    // --- Open tills (live ledger) ---
    $where = ["s.status = 'OPEN'"];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 's.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['register_id'] > 0) {
        $where[] = 's.register_id = ?';
        $types .= 'i';
        $params[] = $f['register_id'];
    }
    if ($f['cashier'] !== '') {
        $where[] = 's.cashier_email = ?';
        $types .= 's';
        $params[] = $f['cashier'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 's.id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }

    $sql = 'SELECT s.id, s.branch, s.register_id, s.cashier_email, s.opening_float, s.opened_at,
                   s.status, s.expected_cash, s.actual_cash, s.variance, s.reconciliation_status,
                   s.final_reconciliation_id, s.closed_at, s.closed_by, s.closure_notes,
                   r.name AS register_name
            FROM sessions s
            LEFT JOIN registers r ON r.id = s.register_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.opened_at DESC, s.id DESC
            LIMIT 200';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Cash management dashboard could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $openSessions = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $openSessions[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    $openIds = array_map(static function ($r) {
        return (int)$r['id'];
    }, $openSessions);
    $balances = psCashReportingBatchBalances($conn, $openIds);
    $recons = psCashReportingBatchLatestRecons($conn, $openIds);

    $openRows = [];
    $staleWarnings = [];
    $expectedOpenTotal = '0.00';
    $statusFilter = $f['status'];

    foreach ($openSessions as $session) {
        $sid = (int)$session['id'];
        $bal = $balances[$sid] ?? ['ok' => true, 'balance' => '0.00', 'detail' => []];
        $recon = $recons[$sid] ?? null;
        $cls = psCashReportingClassifySession($conn, $session, $recon, $bal);
        if (empty($cls['ok'])) {
            continue;
        }
        $detail = $cls['detail'] ?? ($bal['detail'] ?? []);
        $row = [
            'till_session_id' => $sid,
            'branch' => (string)$session['branch'],
            'register_id' => (int)($session['register_id'] ?? 0),
            'register_name' => (string)($session['register_name'] ?? tillRegisterName($session)),
            'cashier' => (string)($session['cashier_email'] ?? ''),
            'opened_at' => tillOpenedAt($session),
            'opening_float' => number_format((float)($detail['opening_float'] ?? $session['opening_float'] ?? 0), 2, '.', ''),
            'cash_sales' => number_format((float)($detail['cash_sales'] ?? 0), 2, '.', ''),
            'cash_refunds' => number_format((float)($detail['cash_refunds'] ?? 0), 2, '.', ''),
            'cash_in' => number_format((float)($detail['cash_in'] ?? 0), 2, '.', ''),
            'cash_out' => number_format((float)($detail['cash_out'] ?? 0), 2, '.', ''),
            'expected_cash' => (string)$cls['ledger_expected'],
            'latest_recon_status' => $cls['variance_status'],
            'latest_recon_expected' => $cls['recon_expected'],
            'variance' => $cls['variance'],
            'currency' => $cls['currency'],
            'stale' => !empty($cls['stale']),
            'current' => !empty($cls['current']),
            'has_recon' => !empty($cls['has_recon']),
            'display_status' => $cls['display_status'],
            'recon_id' => $recon ? (int)$recon['id'] : null,
            'recon_at' => $recon ? (string)($recon['reconciled_at'] ?? '') : null,
        ];

        if ($statusFilter !== '') {
            $match = false;
            if ($statusFilter === PS_CASH_REPORT_STATUS_AWAITING || $statusFilter === PS_CASH_REPORT_STATUS_NO_RECON) {
                $match = !$row['has_recon'];
            } elseif ($statusFilter === PS_CASH_REPORT_STATUS_CURRENT) {
                $match = $row['current'];
            } elseif ($statusFilter === PS_CASH_REPORT_STATUS_STALE) {
                $match = $row['stale'];
            } elseif ($statusFilter === PS_CASH_REPORT_STATUS_RECONCILED) {
                $match = $row['has_recon'];
            } elseif (in_array($statusFilter, [PS_CASH_REPORT_STATUS_BALANCED, PS_CASH_REPORT_STATUS_SHORTAGE, PS_CASH_REPORT_STATUS_SURPLUS], true)) {
                $match = ($row['latest_recon_status'] === $statusFilter);
            } elseif ($statusFilter === 'OPEN') {
                $match = true;
            } elseif ($statusFilter === PS_CASH_REPORT_STATUS_CLOSED) {
                $match = false;
            }
            if (!$match) {
                continue;
            }
        }

        $openRows[] = $row;
        $expectedOpenTotal = psCashReportingMoneyAdd($expectedOpenTotal, (string)$row['expected_cash']);

        if ($row['stale']) {
            $diff = psCashReportingMoneySub((string)$row['expected_cash'], (string)$row['latest_recon_expected']);
            $staleWarnings[] = [
                'cashier' => $row['cashier'],
                'till' => $row['register_name'],
                'till_session_id' => $sid,
                'branch' => $row['branch'],
                'last_expected' => $row['latest_recon_expected'],
                'current_expected' => $row['expected_cash'],
                'difference' => $diff,
                'recon_date' => $row['recon_at'],
                'recon_url' => 'cash_reconciliation.php',
            ];
        }
    }

    // Closed in period (count only for card context).
    $closedCount = 0;
    $cw = ["s.status = 'CLOSED'", 's.final_reconciliation_id IS NOT NULL'];
    $ct = '';
    $cp = [];
    if (!$f['all_branches']) {
        $cw[] = 's.branch = ?';
        $ct .= 's';
        $cp[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $cw[] = 's.closed_at >= ?';
        $ct .= 's';
        $cp[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $cw[] = 's.closed_at <= ?';
        $ct .= 's';
        $cp[] = $f['to_dt'];
    }
    $csql = 'SELECT COUNT(*) AS c FROM sessions s WHERE ' . implode(' AND ', $cw);
    $cstmt = mysqli_prepare($conn, $csql);
    if ($cstmt) {
        if ($ct !== '') {
            mysqli_stmt_bind_param($cstmt, $ct, ...$cp);
        }
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
        $crow = $cres ? mysqli_fetch_assoc($cres) : null;
        $closedCount = (int)($crow['c'] ?? 0);
        mysqli_stmt_close($cstmt);
    }

    // Period movement totals (ledger types only — CASH_SALE / CASH_REFUND / CASH_IN / CASH_OUT).
    $movSummary = psCashReportingMovementTypeTotals($conn, $f);
    $sales = (string)($movSummary['by_type'][PS_CASH_MOV_CASH_SALE]['amount'] ?? '0.00');
    $refunds = (string)($movSummary['by_type'][PS_CASH_MOV_CASH_REFUND]['amount'] ?? '0.00');
    $cashIn = (string)($movSummary['by_type'][PS_CASH_MOV_CASH_IN]['amount'] ?? '0.00');
    $cashOut = (string)($movSummary['by_type'][PS_CASH_MOV_CASH_OUT]['amount'] ?? '0.00');

    // Shortage / surplus from FINAL recons in period (separate — never net).
    $varTotals = psCashReportingVarianceTotals($conn, $f);

    $cards = [
        'open_tills' => count($openRows),
        'closed_in_period' => $closedCount,
        'expected_cash' => $expectedOpenTotal,
        'cash_sales' => $sales,
        'cash_in' => $cashIn,
        'cash_out' => $cashOut,
        'cash_refunds' => $refunds,
        'shortages_count' => (int)($varTotals['shortage_count'] ?? 0),
        'shortages_amount' => (string)($varTotals['shortage_amount'] ?? '0.00'),
        'surpluses_count' => (int)($varTotals['surplus_count'] ?? 0),
        'surpluses_amount' => (string)($varTotals['surplus_amount'] ?? '0.00'),
        'branch' => $f['all_branches'] ? 'All branches' : $f['branch'],
    ];

    $recentRecons = psCashReportingRecentReconciliations($conn, $f, 15);
    $recentClosures = psCashReportingRecentClosures($conn, $f, 15);

    return [
        'ok' => true,
        'cards' => $cards,
        'open_tills' => $openRows,
        'stale_warnings' => $staleWarnings,
        'recent_recons' => $recentRecons['rows'] ?? [],
        'recent_closures' => $recentClosures['rows'] ?? [],
        'movement_summary' => $movSummary,
        'filters' => $f,
        'customer_account_in_expected' => false,
        'non_cash_in_expected' => false,
    ];
}

/**
 * Movement type totals for filters (aggregation SQL).
 *
 * @param array<string,mixed> $f normalised filters
 * @return array{ok:bool,by_type:array,reason_breakdown:array,total_count:int}
 */
function psCashReportingMovementTypeTotals(mysqli $conn, array $f): array
{
    $emptyTypes = [];
    foreach (array_keys(psCashMovementTypes()) as $t) {
        $emptyTypes[$t] = ['count' => 0, 'amount' => '0.00'];
    }
    if (!psEnsureCashMovementsSchema($conn)) {
        return ['ok' => false, 'by_type' => $emptyTypes, 'reason_breakdown' => [], 'total_count' => 0];
    }

    $where = ['1=1'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 'cm.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'cm.movement_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'cm.movement_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 'cm.till_session_id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }
    if ($f['cashier'] !== '') {
        $where[] = 'cm.created_by = ?';
        $types .= 's';
        $params[] = $f['cashier'];
    }
    if ($f['register_id'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM sessions s WHERE s.id = cm.till_session_id AND s.register_id = ?)';
        $types .= 'i';
        $params[] = $f['register_id'];
    }
    if ($f['movement_type'] !== '') {
        $where[] = 'cm.movement_type = ?';
        $types .= 's';
        $params[] = $f['movement_type'];
    }
    if ($f['session_status'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM sessions s WHERE s.id = cm.till_session_id AND s.status = ?)';
        $types .= 's';
        $params[] = $f['session_status'];
    }

    $sql = 'SELECT cm.movement_type, COUNT(*) AS cnt, COALESCE(SUM(cm.amount), 0) AS amt
            FROM cash_movements cm
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY cm.movement_type';
    $stmt = mysqli_prepare($conn, $sql);
    $byType = $emptyTypes;
    $totalCount = 0;
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $mt = (string)$row['movement_type'];
                $cnt = (int)$row['cnt'];
                $amt = number_format((float)$row['amt'], 2, '.', '');
                $byType[$mt] = ['count' => $cnt, 'amount' => $amt];
                $totalCount += $cnt;
            }
        }
        mysqli_stmt_close($stmt);
    }

    // Reason breakdown for CASH_IN / CASH_OUT (operational labels).
    $reasonBreakdown = ['CASH_IN' => [], 'CASH_OUT' => []];
    $rsql = 'SELECT cm.movement_type, cm.reason, COUNT(*) AS cnt, COALESCE(SUM(cm.amount), 0) AS amt
             FROM cash_movements cm
             WHERE ' . implode(' AND ', $where) . "
               AND cm.movement_type IN ('CASH_IN','CASH_OUT')
             GROUP BY cm.movement_type, cm.reason
             ORDER BY cm.movement_type, cm.reason";
    $rstmt = mysqli_prepare($conn, $rsql);
    if ($rstmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($rstmt, $types, ...$params);
        }
        mysqli_stmt_execute($rstmt);
        $rres = mysqli_stmt_get_result($rstmt);
        if ($rres) {
            while ($row = mysqli_fetch_assoc($rres)) {
                $mt = (string)$row['movement_type'];
                $reason = trim((string)($row['reason'] ?? ''));
                $reasonBreakdown[$mt][] = [
                    'reason' => $reason,
                    'reason_label' => $reason !== '' ? psCashMovementReasonLabel($reason) : '(none)',
                    'count' => (int)$row['cnt'],
                    'amount' => number_format((float)$row['amt'], 2, '.', ''),
                ];
            }
        }
        mysqli_stmt_close($rstmt);
    }

    return [
        'ok' => true,
        'by_type' => $byType,
        'reason_breakdown' => $reasonBreakdown,
        'total_count' => $totalCount,
    ];
}

/**
 * Shortage / surplus counts + monetary totals from FINAL recons (do not net).
 *
 * @param array<string,mixed> $f
 * @return array{shortage_count:int,shortage_amount:string,surplus_count:int,surplus_amount:string,balanced_count:int}
 */
function psCashReportingVarianceTotals(mysqli $conn, array $f): array
{
    $out = [
        'shortage_count' => 0,
        'shortage_amount' => '0.00',
        'surplus_count' => 0,
        'surplus_amount' => '0.00',
        'balanced_count' => 0,
    ];
    if (!psEnsureCashReconciliationsSchema($conn)) {
        return $out;
    }
    $where = ["cr.lifecycle = 'FINAL'"];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 'cr.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'cr.reconciled_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'cr.reconciled_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['cashier'] !== '') {
        $where[] = 'cr.reconciled_by = ?';
        $types .= 's';
        $params[] = $f['cashier'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 'cr.till_session_id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }
    if ($f['register_id'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM sessions s WHERE s.id = cr.till_session_id AND s.register_id = ?)';
        $types .= 'i';
        $params[] = $f['register_id'];
    }

    $sql = 'SELECT cr.status,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(ABS(cr.variance)), 0) AS amt
            FROM cash_reconciliations cr
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY cr.status';
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $st = strtoupper(trim((string)$row['status']));
                $cnt = (int)$row['cnt'];
                $amt = number_format((float)$row['amt'], 2, '.', '');
                if ($st === PS_CASH_RECON_STATUS_SHORTAGE) {
                    $out['shortage_count'] = $cnt;
                    $out['shortage_amount'] = $amt;
                } elseif ($st === PS_CASH_RECON_STATUS_SURPLUS) {
                    $out['surplus_count'] = $cnt;
                    $out['surplus_amount'] = $amt;
                } elseif ($st === PS_CASH_RECON_STATUS_BALANCED) {
                    $out['balanced_count'] = $cnt;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $out;
}

/**
 * @param array<string,mixed> $f
 * @return array{ok:bool,rows?:array}
 */
function psCashReportingRecentReconciliations(mysqli $conn, array $f, int $limit = 15): array
{
    $limit = max(1, min(50, $limit));
    $where = ['1=1'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 'cr.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'cr.reconciled_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'cr.reconciled_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    $sql = 'SELECT cr.id, cr.till_session_id, cr.branch, cr.reconciled_at, cr.expected_cash,
                   cr.counted_cash, cr.variance, cr.status, cr.reconciled_by, cr.notes,
                   s.cashier_email, s.status AS session_status, r.name AS register_name
            FROM cash_reconciliations cr
            LEFT JOIN sessions s ON s.id = cr.till_session_id
            LEFT JOIN registers r ON r.id = s.register_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY cr.reconciled_at DESC, cr.id DESC
            LIMIT ' . $limit;
    $stmt = mysqli_prepare($conn, $sql);
    $rows = [];
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $row['status_label'] = psCashReconciliationStatusLabel((string)$row['status']);
                $rows[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * @param array<string,mixed> $f
 * @return array{ok:bool,rows?:array}
 */
function psCashReportingRecentClosures(mysqli $conn, array $f, int $limit = 15): array
{
    $limit = max(1, min(50, $limit));
    $where = ["s.status = 'CLOSED'", 's.final_reconciliation_id IS NOT NULL'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 's.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 's.closed_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 's.closed_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    $sql = 'SELECT s.id, s.branch, s.register_id, s.cashier_email, s.opened_at, s.closed_at,
                   s.opening_float, s.expected_cash, s.actual_cash, s.variance,
                   s.reconciliation_status, s.final_reconciliation_id, s.closed_by, s.closure_notes,
                   r.name AS register_name
            FROM sessions s
            LEFT JOIN registers r ON r.id = s.register_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.closed_at DESC, s.id DESC
            LIMIT ' . $limit;
    $stmt = mysqli_prepare($conn, $sql);
    $rows = [];
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Paginated cash movements report.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,rows?:array,totals?:array,pagination?:array,error?:string}
 */
function psCashReportingMovementsReport(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Cash movements report could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    $where = ['1=1'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 'cm.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'cm.movement_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'cm.movement_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 'cm.till_session_id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }
    if ($f['cashier'] !== '') {
        $where[] = 'cm.created_by = ?';
        $types .= 's';
        $params[] = $f['cashier'];
    }
    if ($f['movement_type'] !== '') {
        $where[] = 'cm.movement_type = ?';
        $types .= 's';
        $params[] = $f['movement_type'];
    }
    if ($f['ref'] !== '') {
        $where[] = '(cm.reference_id LIKE ? OR cm.reference_type LIKE ?)';
        $types .= 'ss';
        $like = '%' . $f['ref'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($f['register_id'] > 0) {
        $where[] = 's.register_id = ?';
        $types .= 'i';
        $params[] = $f['register_id'];
    }
    if ($f['session_status'] !== '') {
        $where[] = 's.status = ?';
        $types .= 's';
        $params[] = $f['session_status'];
    }

    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) AS c
                 FROM cash_movements cm
                 LEFT JOIN sessions s ON s.id = cm.till_session_id
                 WHERE $whereSql";
    $cstmt = mysqli_prepare($conn, $countSql);
    if (!$cstmt) {
        return ['ok' => false, 'error' => 'Cash movements report could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($cstmt, $types, ...$params);
    }
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    $totalRows = (int)($crow['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $page = $f['page'];
    $pageSize = $f['page_size'];
    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $listSql = "SELECT cm.id, cm.till_session_id, cm.branch, cm.movement_at, cm.movement_type, cm.amount,
                       cm.reference_type, cm.reference_id, cm.reason, cm.description, cm.created_by, cm.created_at,
                       s.status AS session_status, s.cashier_email, r.name AS register_name
                FROM cash_movements cm
                LEFT JOIN sessions s ON s.id = cm.till_session_id
                LEFT JOIN registers r ON r.id = s.register_id
                WHERE $whereSql
                ORDER BY cm.movement_at DESC, cm.id DESC
                LIMIT ? OFFSET ?";
    $lstmt = mysqli_prepare($conn, $listSql);
    if (!$lstmt) {
        return ['ok' => false, 'error' => 'Cash movements report could not be loaded.'];
    }
    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $pageSize;
    $listParams[] = $offset;
    mysqli_stmt_bind_param($lstmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($lstmt);
    $lres = mysqli_stmt_get_result($lstmt);
    $rows = [];
    if ($lres) {
        while ($row = mysqli_fetch_assoc($lres)) {
            $rows[] = psCashMovementEnrichHistoryRow($row);
        }
    }
    mysqli_stmt_close($lstmt);

    $totals = psCashReportingMovementTypeTotals($conn, $f);

    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => $totals,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'filters' => $f,
    ];
}

/**
 * Paginated reconciliation report.
 *
 * @param array<string,mixed> $rawFilters
 */
function psCashReportingReconciliationReport(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Reconciliation report could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    $where = ['1=1'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 'cr.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'cr.reconciled_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'cr.reconciled_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['cashier'] !== '') {
        $where[] = 'cr.reconciled_by = ?';
        $types .= 's';
        $params[] = $f['cashier'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 'cr.till_session_id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }
    if ($f['register_id'] > 0) {
        $where[] = 's.register_id = ?';
        $types .= 'i';
        $params[] = $f['register_id'];
    }
    if ($f['status'] !== '' && in_array($f['status'], psCashReconciliationStatuses(), true)) {
        $where[] = 'cr.status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }
    if ($f['session_status'] !== '') {
        $where[] = 's.status = ?';
        $types .= 's';
        $params[] = $f['session_status'];
    }

    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) AS c
                 FROM cash_reconciliations cr
                 LEFT JOIN sessions s ON s.id = cr.till_session_id
                 WHERE $whereSql";
    $cstmt = mysqli_prepare($conn, $countSql);
    if (!$cstmt) {
        return ['ok' => false, 'error' => 'Reconciliation report could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($cstmt, $types, ...$params);
    }
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    $totalRows = (int)($crow['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $page = $f['page'];
    $pageSize = $f['page_size'];
    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $listSql = "SELECT cr.id, cr.till_session_id, cr.branch, cr.reconciled_at, cr.expected_cash,
                       cr.counted_cash, cr.variance, cr.status, cr.lifecycle, cr.notes, cr.reconciled_by,
                       s.status AS session_status, s.cashier_email, r.name AS register_name
                FROM cash_reconciliations cr
                LEFT JOIN sessions s ON s.id = cr.till_session_id
                LEFT JOIN registers r ON r.id = s.register_id
                WHERE $whereSql
                ORDER BY cr.reconciled_at DESC, cr.id DESC
                LIMIT ? OFFSET ?";
    $lstmt = mysqli_prepare($conn, $listSql);
    if (!$lstmt) {
        return ['ok' => false, 'error' => 'Reconciliation report could not be loaded.'];
    }
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$pageSize, $offset]);
    mysqli_stmt_bind_param($lstmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($lstmt);
    $lres = mysqli_stmt_get_result($lstmt);
    $rows = [];
    if ($lres) {
        while ($row = mysqli_fetch_assoc($lres)) {
            $row['status_label'] = psCashReconciliationStatusLabel((string)$row['status']);
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($lstmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'variance_totals' => psCashReportingVarianceTotals($conn, $f),
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'filters' => $f,
    ];
}

/**
 * Closure report — uses stored closure snapshot (not recomputed from today's ledger).
 *
 * @param array<string,mixed> $rawFilters
 */
function psCashReportingClosureReport(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Closure report could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    $where = ["s.status = 'CLOSED'", 's.final_reconciliation_id IS NOT NULL'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 's.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 's.closed_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 's.closed_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['cashier'] !== '') {
        $where[] = '(s.cashier_email = ? OR s.closed_by = ?)';
        $types .= 'ss';
        $params[] = $f['cashier'];
        $params[] = $f['cashier'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 's.id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }
    if ($f['register_id'] > 0) {
        $where[] = 's.register_id = ?';
        $types .= 'i';
        $params[] = $f['register_id'];
    }
    if ($f['status'] !== '' && in_array($f['status'], psCashReconciliationStatuses(), true)) {
        $where[] = 's.reconciliation_status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }

    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) AS c FROM sessions s WHERE $whereSql";
    $cstmt = mysqli_prepare($conn, $countSql);
    if (!$cstmt) {
        return ['ok' => false, 'error' => 'Closure report could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($cstmt, $types, ...$params);
    }
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    $totalRows = (int)($crow['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $page = $f['page'];
    $pageSize = $f['page_size'];
    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $listSql = "SELECT s.id, s.branch, s.register_id, s.cashier_email, s.opened_at, s.closed_at,
                       s.opening_float, s.expected_cash, s.actual_cash, s.variance,
                       s.reconciliation_status, s.final_reconciliation_id, s.closed_by, s.closure_notes,
                       r.name AS register_name
                FROM sessions s
                LEFT JOIN registers r ON r.id = s.register_id
                WHERE $whereSql
                ORDER BY s.closed_at DESC, s.id DESC
                LIMIT ? OFFSET ?";
    $lstmt = mysqli_prepare($conn, $listSql);
    if (!$lstmt) {
        return ['ok' => false, 'error' => 'Closure report could not be loaded.'];
    }
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$pageSize, $offset]);
    mysqli_stmt_bind_param($lstmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($lstmt);
    $lres = mysqli_stmt_get_result($lstmt);
    $rows = [];
    if ($lres) {
        while ($row = mysqli_fetch_assoc($lres)) {
            $st = strtoupper(trim((string)($row['reconciliation_status'] ?? '')));
            $row['variance_status'] = $st;
            $row['variance_status_label'] = psCashReconciliationStatusLabel($st);
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($lstmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'filters' => $f,
        'source' => 'closure_snapshot',
    ];
}

/**
 * Closed till history (searchable) — snapshot-based.
 *
 * @param array<string,mixed> $rawFilters
 */
function psCashReportingClosedTillHistory(mysqli $conn, array $rawFilters = []): array
{
    return psCashReportingClosureReport($conn, $rawFilters);
}

/**
 * Cashier operational summary (not appraisal).
 *
 * @param array<string,mixed> $rawFilters
 */
function psCashReportingCashierSummary(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Cashier summary could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    $where = ['1=1'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 's.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'COALESCE(s.opened_at, s.created_at) >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'COALESCE(s.opened_at, s.created_at) <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['cashier'] !== '') {
        $where[] = 's.cashier_email = ?';
        $types .= 's';
        $params[] = $f['cashier'];
    }

    $sql = 'SELECT s.cashier_email,
                   COUNT(*) AS sessions_count,
                   SUM(CASE WHEN s.status = \'OPEN\' THEN 1 ELSE 0 END) AS open_count,
                   SUM(CASE WHEN s.status = \'CLOSED\' THEN 1 ELSE 0 END) AS closed_count
            FROM sessions s
            WHERE ' . implode(' AND ', $where) . '
              AND s.cashier_email IS NOT NULL AND s.cashier_email <> \'\'
            GROUP BY s.cashier_email
            ORDER BY s.cashier_email ASC
            LIMIT 200';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Cashier summary could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $cashiers = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cashiers[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    $rows = [];
    foreach ($cashiers as $c) {
        $email = (string)$c['cashier_email'];
        $mf = $f;
        $mf['cashier'] = $email;
        $mov = psCashReportingMovementTypeTotals($conn, $mf);
        $var = psCashReportingVarianceTotals($conn, $mf);
        $rows[] = [
            'cashier' => $email,
            'sessions' => (int)$c['sessions_count'],
            'open_sessions' => (int)$c['open_count'],
            'closed_sessions' => (int)$c['closed_count'],
            'cash_sales' => (string)($mov['by_type'][PS_CASH_MOV_CASH_SALE]['amount'] ?? '0.00'),
            'cash_refunds' => (string)($mov['by_type'][PS_CASH_MOV_CASH_REFUND]['amount'] ?? '0.00'),
            'cash_in' => (string)($mov['by_type'][PS_CASH_MOV_CASH_IN]['amount'] ?? '0.00'),
            'cash_out' => (string)($mov['by_type'][PS_CASH_MOV_CASH_OUT]['amount'] ?? '0.00'),
            'recon_count' => (int)$var['shortage_count'] + (int)$var['surplus_count'] + (int)$var['balanced_count'],
            'balanced_count' => (int)$var['balanced_count'],
            'shortage_count' => (int)$var['shortage_count'],
            'surplus_count' => (int)$var['surplus_count'],
            'shortage_amount' => (string)$var['shortage_amount'],
            'surplus_amount' => (string)$var['surplus_amount'],
        ];
    }

    return ['ok' => true, 'rows' => $rows, 'filters' => $f, 'note' => 'Operational summary — not performance appraisal.'];
}

/**
 * Till/register operational summary.
 *
 * @param array<string,mixed> $rawFilters
 */
function psCashReportingTillSummary(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Till summary could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    $where = ['s.register_id IS NOT NULL'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 's.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'COALESCE(s.opened_at, s.created_at) >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'COALESCE(s.opened_at, s.created_at) <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['register_id'] > 0) {
        $where[] = 's.register_id = ?';
        $types .= 'i';
        $params[] = $f['register_id'];
    }

    $sql = 'SELECT s.register_id, s.branch, r.name AS register_name,
                   COUNT(*) AS sessions_count,
                   SUM(CASE WHEN s.status = \'OPEN\' THEN 1 ELSE 0 END) AS open_count,
                   SUM(CASE WHEN s.status = \'CLOSED\' THEN 1 ELSE 0 END) AS closed_count
            FROM sessions s
            LEFT JOIN registers r ON r.id = s.register_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY s.register_id, s.branch, r.name
            ORDER BY s.branch ASC, r.name ASC
            LIMIT 200';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Till summary could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $tills = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $tills[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    $rows = [];
    foreach ($tills as $t) {
        $rid = (int)$t['register_id'];
        $mf = $f;
        $mf['register_id'] = $rid;
        $mov = psCashReportingMovementTypeTotals($conn, $mf);
        $var = psCashReportingVarianceTotals($conn, $mf);
        $rows[] = [
            'register_id' => $rid,
            'register_name' => (string)($t['register_name'] ?? ''),
            'branch' => (string)$t['branch'],
            'sessions' => (int)$t['sessions_count'],
            'open_sessions' => (int)$t['open_count'],
            'closed_sessions' => (int)$t['closed_count'],
            'cash_sales' => (string)($mov['by_type'][PS_CASH_MOV_CASH_SALE]['amount'] ?? '0.00'),
            'cash_refunds' => (string)($mov['by_type'][PS_CASH_MOV_CASH_REFUND]['amount'] ?? '0.00'),
            'cash_in' => (string)($mov['by_type'][PS_CASH_MOV_CASH_IN]['amount'] ?? '0.00'),
            'cash_out' => (string)($mov['by_type'][PS_CASH_MOV_CASH_OUT]['amount'] ?? '0.00'),
            'shortage_count' => (int)$var['shortage_count'],
            'surplus_count' => (int)$var['surplus_count'],
            'shortage_amount' => (string)$var['shortage_amount'],
            'surplus_amount' => (string)$var['surplus_amount'],
            'balanced_count' => (int)$var['balanced_count'],
        ];
    }

    return ['ok' => true, 'rows' => $rows, 'filters' => $f];
}

/**
 * Admin branch summary from records (no cached branch balance).
 *
 * @param array<string,mixed> $rawFilters
 */
function psCashReportingBranchSummary(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Branch summary could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    if (!psCashReportingIsAdmin()) {
        // Ordinary users: single working-branch summary only.
        $rawFilters['branch'] = psSessionProductBranch();
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];

    $branches = [];
    if ($f['all_branches']) {
        $branches = psAuthorisedProductBranches($conn);
    } else {
        $branches = [$f['branch']];
    }

    $rows = [];
    foreach ($branches as $bn) {
        $bn = trim((string)$bn);
        if ($bn === '') {
            continue;
        }
        $mf = $f;
        $mf['branch'] = $bn;
        $mf['all_branches'] = false;

        $openSql = "SELECT COUNT(*) AS c FROM sessions WHERE status = 'OPEN' AND branch = ?";
        $ost = mysqli_prepare($conn, $openSql);
        $openCount = 0;
        if ($ost) {
            mysqli_stmt_bind_param($ost, 's', $bn);
            mysqli_stmt_execute($ost);
            $ores = mysqli_stmt_get_result($ost);
            $orow = $ores ? mysqli_fetch_assoc($ores) : null;
            $openCount = (int)($orow['c'] ?? 0);
            mysqli_stmt_close($ost);
        }

        $cw = ["status = 'CLOSED'", 'final_reconciliation_id IS NOT NULL', 'branch = ?'];
        $ct = 's';
        $cp = [$bn];
        if ($f['from_dt'] !== null) {
            $cw[] = 'closed_at >= ?';
            $ct .= 's';
            $cp[] = $f['from_dt'];
        }
        if ($f['to_dt'] !== null) {
            $cw[] = 'closed_at <= ?';
            $ct .= 's';
            $cp[] = $f['to_dt'];
        }
        $closedCount = 0;
        $cst = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM sessions WHERE ' . implode(' AND ', $cw));
        if ($cst) {
            mysqli_stmt_bind_param($cst, $ct, ...$cp);
            mysqli_stmt_execute($cst);
            $cres = mysqli_stmt_get_result($cst);
            $crow = $cres ? mysqli_fetch_assoc($cres) : null;
            $closedCount = (int)($crow['c'] ?? 0);
            mysqli_stmt_close($cst);
        }

        $mov = psCashReportingMovementTypeTotals($conn, $mf);
        $var = psCashReportingVarianceTotals($conn, $mf);
        $rows[] = [
            'branch' => $bn,
            'open_tills' => $openCount,
            'closed_sessions' => $closedCount,
            'cash_sales' => (string)($mov['by_type'][PS_CASH_MOV_CASH_SALE]['amount'] ?? '0.00'),
            'cash_refunds' => (string)($mov['by_type'][PS_CASH_MOV_CASH_REFUND]['amount'] ?? '0.00'),
            'cash_in' => (string)($mov['by_type'][PS_CASH_MOV_CASH_IN]['amount'] ?? '0.00'),
            'cash_out' => (string)($mov['by_type'][PS_CASH_MOV_CASH_OUT]['amount'] ?? '0.00'),
            'shortages_count' => (int)$var['shortage_count'],
            'shortages_amount' => (string)$var['shortage_amount'],
            'surpluses_count' => (int)$var['surplus_count'],
            'surpluses_amount' => (string)$var['surplus_amount'],
        ];
    }

    return ['ok' => true, 'rows' => $rows, 'filters' => $f];
}

/**
 * Read-only session detail drill-down (movements + recons + closure + audits).
 */
function psCashReportingSessionDetail(mysqli $conn, int $tillSessionId): array
{
    if (!psEnsureCashReportingModule($conn) || $tillSessionId < 1) {
        return ['ok' => false, 'error' => 'Session could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $session = tillSessionById($conn, $tillSessionId);
    if (!$session) {
        return ['ok' => false, 'error' => 'Session was not found.'];
    }
    $branchRes = psCashReportingResolveBranch($conn, null);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error']];
    }
    $sessionBranch = trim((string)($session['branch'] ?? ''));
    if (empty($branchRes['all_branches']) && $sessionBranch !== (string)$branchRes['branch']) {
        return ['ok' => false, 'error' => 'That session is not available for this branch.'];
    }

    $status = strtoupper(trim((string)($session['status'] ?? '')));
    $live = null;
    $expectedSource = 'closure_snapshot';
    if ($status === 'OPEN') {
        $live = psCashMovementBalance($conn, $tillSessionId);
        $expectedSource = 'live_ledger';
    } else {
        // Ledger still audit-available for CLOSED.
        $live = psCashMovementBalance($conn, $tillSessionId);
    }

    $mov = psCashMovementHistory($conn, $tillSessionId, $sessionBranch, 200, 0);
    $reconFound = psCashReconciliationFindFinalBySession($conn, $tillSessionId, false);
    $cls = psCashReportingClassifySession(
        $conn,
        $session,
        (!empty($reconFound['ok']) && !empty($reconFound['exists'])) ? $reconFound['row'] : null,
        $live
    );

    // All FINAL recons for session (history).
    $reconRows = [];
    $rst = mysqli_prepare(
        $conn,
        "SELECT id, till_session_id, branch, reconciled_at, expected_cash, counted_cash, variance,
                status, lifecycle, notes, reconciled_by, created_at
         FROM cash_reconciliations
         WHERE till_session_id = ?
         ORDER BY id DESC
         LIMIT 50"
    );
    if ($rst) {
        mysqli_stmt_bind_param($rst, 'i', $tillSessionId);
        mysqli_stmt_execute($rst);
        $rres = mysqli_stmt_get_result($rst);
        if ($rres) {
            while ($row = mysqli_fetch_assoc($rres)) {
                $row['status_label'] = psCashReconciliationStatusLabel((string)$row['status']);
                $reconRows[] = $row;
            }
        }
        mysqli_stmt_close($rst);
    }

    $audits = psCashReportingSessionAudits($conn, $tillSessionId, $sessionBranch);

    return [
        'ok' => true,
        'session' => $session,
        'classification' => $cls,
        'expected_source' => $expectedSource,
        'live_ledger' => $live,
        'movements' => $mov['rows'] ?? [],
        'reconciliations' => $reconRows,
        'audits' => $audits,
        'read_only' => true,
    ];
}

/**
 * Read-only audit visibility for a session (authorised / branch-scoped).
 *
 * @return array{movements:array,reconciliations:array,closures:array}
 */
function psCashReportingSessionAudits(mysqli $conn, int $tillSessionId, string $branch): array
{
    $out = ['movements' => [], 'reconciliations' => [], 'closures' => []];
    if ($tillSessionId < 1) {
        return $out;
    }

    if (psEnsureCashMovementAuditSchema($conn)) {
        $st = mysqli_prepare(
            $conn,
            'SELECT id, cash_movement_id, till_session_id, branch, action, field_name, old_value, new_value,
                    changed_by, changed_at, source
             FROM cash_movement_audit
             WHERE till_session_id = ? AND branch = ?
             ORDER BY changed_at DESC, id DESC
             LIMIT 100'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'is', $tillSessionId, $branch);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $out['movements'][] = $row;
                }
            }
            mysqli_stmt_close($st);
        }
    }

    if (psEnsureCashReconciliationAuditSchema($conn)) {
        $st = mysqli_prepare(
            $conn,
            'SELECT id, cash_reconciliation_id, till_session_id, branch, action, field_name, old_value, new_value,
                    changed_by, changed_at, source
             FROM cash_reconciliation_audit
             WHERE till_session_id = ? AND branch = ?
             ORDER BY changed_at DESC, id DESC
             LIMIT 100'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'is', $tillSessionId, $branch);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $out['reconciliations'][] = $row;
                }
            }
            mysqli_stmt_close($st);
        }
    }

    if (psEnsureTillClosureAuditSchema($conn)) {
        $st = mysqli_prepare(
            $conn,
            'SELECT id, till_session_id, branch, action, field_name, old_value, new_value,
                    changed_by, changed_at, source
             FROM till_closure_audit
             WHERE till_session_id = ? AND branch = ?
             ORDER BY changed_at DESC, id DESC
             LIMIT 100'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'is', $tillSessionId, $branch);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $out['closures'][] = $row;
                }
            }
            mysqli_stmt_close($st);
        }
    }

    return $out;
}

/**
 * Branch-scoped audit list (paginated) — read-only.
 *
 * @param array<string,mixed> $rawFilters
 * @param string $kind movements|reconciliations|closures
 */
function psCashReportingAuditList(mysqli $conn, string $kind, array $rawFilters = []): array
{
    if (!psEnsureCashReportingModule($conn)) {
        return ['ok' => false, 'error' => 'Audit could not be loaded.'];
    }
    if (psSessionCashReportingUser() === '') {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    $norm = psCashReportingNormaliseFilters($conn, $rawFilters);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error']];
    }
    $f = $norm['filters'];
    $kind = strtolower(trim($kind));

    if ($kind === 'movements') {
        $table = 'cash_movement_audit';
        $psEnsure = 'psEnsureCashMovementAuditSchema';
    } elseif ($kind === 'reconciliations') {
        $table = 'cash_reconciliation_audit';
        $psEnsure = 'psEnsureCashReconciliationAuditSchema';
    } elseif ($kind === 'closures') {
        $table = 'till_closure_audit';
        $psEnsure = 'psEnsureTillClosureAuditSchema';
    } else {
        return ['ok' => false, 'error' => 'Audit type is not valid.'];
    }
    if (!call_user_func($psEnsure, $conn)) {
        return ['ok' => false, 'error' => 'Audit could not be loaded.'];
    }

    $where = ['1=1'];
    $types = '';
    $params = [];
    if (!$f['all_branches']) {
        $where[] = 'branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if ($f['from_dt'] !== null) {
        $where[] = 'changed_at >= ?';
        $types .= 's';
        $params[] = $f['from_dt'];
    }
    if ($f['to_dt'] !== null) {
        $where[] = 'changed_at <= ?';
        $types .= 's';
        $params[] = $f['to_dt'];
    }
    if ($f['till_session_id'] > 0) {
        $where[] = 'till_session_id = ?';
        $types .= 'i';
        $params[] = $f['till_session_id'];
    }

    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) AS c FROM `$table` WHERE $whereSql";
    $cstmt = mysqli_prepare($conn, $countSql);
    if (!$cstmt) {
        return ['ok' => false, 'error' => 'Audit could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($cstmt, $types, ...$params);
    }
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    $totalRows = (int)($crow['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $page = $f['page'];
    $pageSize = $f['page_size'];
    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $listSql = "SELECT * FROM `$table` WHERE $whereSql ORDER BY changed_at DESC, id DESC LIMIT ? OFFSET ?";
    $lstmt = mysqli_prepare($conn, $listSql);
    if (!$lstmt) {
        return ['ok' => false, 'error' => 'Audit could not be loaded.'];
    }
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$pageSize, $offset]);
    mysqli_stmt_bind_param($lstmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($lstmt);
    $lres = mysqli_stmt_get_result($lstmt);
    $rows = [];
    if ($lres) {
        while ($row = mysqli_fetch_assoc($lres)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($lstmt);

    return [
        'ok' => true,
        'kind' => $kind,
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'filters' => $f,
        'read_only' => true,
    ];
}

/**
 * CSV export for a report kind. Respects branch security / filters. No unfiltered dump for ordinary users.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,filename?:string,headers?:list<string>,rows?:list<list<string>>,error?:string}
 */
function psCashReportingExportCsv(mysqli $conn, string $kind, array $rawFilters = []): array
{
    $kind = strtolower(trim($kind));
    $rawFilters['page'] = 1;
    $rawFilters['page_size'] = 200;

    if ($kind === 'movements') {
        $res = psCashReportingMovementsReport($conn, $rawFilters);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'Datetime', 'Branch', 'Till session', 'Register', 'User', 'Type', 'Direction',
            'Amount', 'Reference', 'Reason', 'Description', 'Session status',
        ];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [
                (string)($row['movement_at'] ?? ''),
                (string)($row['branch'] ?? ''),
                (string)($row['till_session_id'] ?? ''),
                (string)($row['register_name'] ?? ''),
                (string)($row['created_by'] ?? ''),
                (string)($row['movement_type'] ?? ''),
                (string)($row['direction'] ?? ''),
                (string)($row['amount'] ?? ''),
                (string)($row['reference_display'] ?? ''),
                (string)($row['reason_label'] ?? $row['reason'] ?? ''),
                (string)($row['description'] ?? ''),
                (string)($row['session_status'] ?? ''),
            ];
        }
        return ['ok' => true, 'filename' => 'cash_movements.csv', 'headers' => $headers, 'rows' => $lines];
    }

    if ($kind === 'reconciliations') {
        $res = psCashReportingReconciliationReport($conn, $rawFilters);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'Session', 'Branch', 'Till', 'Cashier', 'Recon date', 'Expected', 'Counted',
            'Variance', 'Status', 'Notes', 'By', 'Session status',
        ];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [
                (string)($row['till_session_id'] ?? ''),
                (string)($row['branch'] ?? ''),
                (string)($row['register_name'] ?? ''),
                (string)($row['cashier_email'] ?? ''),
                (string)($row['reconciled_at'] ?? ''),
                (string)($row['expected_cash'] ?? ''),
                (string)($row['counted_cash'] ?? ''),
                (string)($row['variance'] ?? ''),
                (string)($row['status'] ?? ''),
                (string)($row['notes'] ?? ''),
                (string)($row['reconciled_by'] ?? ''),
                (string)($row['session_status'] ?? ''),
            ];
        }
        return ['ok' => true, 'filename' => 'cash_reconciliations.csv', 'headers' => $headers, 'rows' => $lines];
    }

    if ($kind === 'closures') {
        $res = psCashReportingClosureReport($conn, $rawFilters);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'Session', 'Branch', 'Till', 'Cashier', 'Opened', 'Closed', 'Float', 'Expected',
            'Actual', 'Variance', 'Recon status', 'Final recon', 'Notes', 'Closed by',
        ];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [
                (string)($row['id'] ?? ''),
                (string)($row['branch'] ?? ''),
                (string)($row['register_name'] ?? ''),
                (string)($row['cashier_email'] ?? ''),
                (string)($row['opened_at'] ?? ''),
                (string)($row['closed_at'] ?? ''),
                (string)($row['opening_float'] ?? ''),
                (string)($row['expected_cash'] ?? ''),
                (string)($row['actual_cash'] ?? ''),
                (string)($row['variance'] ?? ''),
                (string)($row['reconciliation_status'] ?? ''),
                (string)($row['final_reconciliation_id'] ?? ''),
                (string)($row['closure_notes'] ?? ''),
                (string)($row['closed_by'] ?? ''),
            ];
        }
        return ['ok' => true, 'filename' => 'cash_closures.csv', 'headers' => $headers, 'rows' => $lines];
    }

    return ['ok' => false, 'error' => 'Export type is not valid.'];
}

/**
 * Explicit: reporting never mutates cash financial tables.
 */
function psCashReportingMutationsEnabled(): bool
{
    return false;
}

/**
 * Explicit: Phase 6.3 account payments remain outside expected cash / cash sales cards.
 */
function psCashReportingCustomerAccountPaymentInExpectedEnabled(): bool
{
    return false;
}

/**
 * Explicit: non-cash tenders not in expected cash.
 */
function psCashReportingNonCashTendersInExpectedEnabled(): bool
{
    return false;
}

/**
 * Registers list for filter dropdowns (branch-scoped).
 *
 * @return list<array{id:int,name:string,branch:string}>
 */
function psCashReportingRegisterOptions(mysqli $conn, array $f): array
{
    $rows = [];
    $sql = 'SELECT id, name, branch FROM registers WHERE status = \'ACTIVE\'';
    $types = '';
    $params = [];
    if (empty($f['all_branches'])) {
        $sql .= ' AND branch = ?';
        $types = 's';
        $params[] = $f['branch'] ?? '';
    }
    $sql .= ' ORDER BY branch ASC, name ASC LIMIT 200';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $rows;
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'branch' => (string)$row['branch'],
            ];
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Stream CSV to browser (caller must not have output yet).
 *
 * @param array{headers:list<string>,rows:list<list<string>>,filename:string} $export
 */
function psCashReportingSendCsv(array $export): void
{
    $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($export['filename'] ?? 'cash_export.csv'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, $export['headers'] ?? []);
    foreach ($export['rows'] ?? [] as $line) {
        fputcsv($out, $line);
    }
    fclose($out);
}
