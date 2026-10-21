<?php
/**
 * Phase 6.5 — Receivables Credit Controls & Collections Foundation.
 *
 * Reuses Phase 6.4 ageing helpers for overdue identification (no second ledger).
 * Collection activity is non-financial (never posts ledger / PAYMENT / reduces receivables).
 * Credit hold / limit helpers live in includes/customer_account.php (coherent account audit).
 */

require_once __DIR__ . '/customer_account.php';
require_once __DIR__ . '/customer_account_reporting.php';
require_once __DIR__ . '/customer_account_payment.php';

const PS_COLL_TYPE_PHONE_CALL = 'PHONE_CALL';
const PS_COLL_TYPE_VISIT = 'VISIT';
const PS_COLL_TYPE_EMAIL = 'EMAIL';
const PS_COLL_TYPE_SMS = 'SMS';
const PS_COLL_TYPE_PROMISE_TO_PAY = 'PROMISE_TO_PAY';
const PS_COLL_TYPE_FOLLOW_UP = 'FOLLOW_UP';
const PS_COLL_TYPE_OTHER = 'OTHER';

const PS_COLL_OUTCOME_CONTACTED = 'CONTACTED';
const PS_COLL_OUTCOME_NO_RESPONSE = 'NO_RESPONSE';
const PS_COLL_OUTCOME_PROMISE_RECEIVED = 'PROMISE_RECEIVED';
const PS_COLL_OUTCOME_PROMISE_BROKEN = 'PROMISE_BROKEN';
const PS_COLL_OUTCOME_PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';
const PS_COLL_OUTCOME_DISPUTE = 'DISPUTE';
const PS_COLL_OUTCOME_ESCALATED = 'ESCALATED';
const PS_COLL_OUTCOME_OTHER = 'OTHER';

/**
 * Ensure collection activity table. Append-only in application (create only; no edit/delete helpers).
 */
function psEnsureCustomerCollectionActivitySchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_collection_activity LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_collection_activity (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_account_id INT NOT NULL,
        activity_date DATE NOT NULL,
        activity_type VARCHAR(32) NOT NULL,
        outcome VARCHAR(32) NOT NULL,
        notes VARCHAR(1000) NOT NULL DEFAULT '',
        next_action_date DATE NULL DEFAULT NULL,
        promise_date DATE NULL DEFAULT NULL,
        promise_amount DECIMAL(14,2) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        created_by VARCHAR(250) NOT NULL,
        KEY idx_coll_activity_account (customer_account_id),
        KEY idx_coll_activity_date (activity_date),
        KEY idx_coll_activity_next (next_action_date),
        KEY idx_coll_activity_type (activity_type),
        KEY idx_coll_activity_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_collection_activity schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureCustomerCreditControlModule(mysqli $conn): bool
{
    return psEnsureCustomerAccountModule($conn)
        && psEnsureCustomerAccountPaymentModule($conn)
        && psEnsureCustomerCollectionActivitySchema($conn);
}

/**
 * @return list<string>
 */
function psCustomerCollectionActivityTypes(): array
{
    return [
        PS_COLL_TYPE_PHONE_CALL,
        PS_COLL_TYPE_VISIT,
        PS_COLL_TYPE_EMAIL,
        PS_COLL_TYPE_SMS,
        PS_COLL_TYPE_PROMISE_TO_PAY,
        PS_COLL_TYPE_FOLLOW_UP,
        PS_COLL_TYPE_OTHER,
    ];
}

/**
 * @return list<string>
 */
function psCustomerCollectionActivityOutcomes(): array
{
    return [
        PS_COLL_OUTCOME_CONTACTED,
        PS_COLL_OUTCOME_NO_RESPONSE,
        PS_COLL_OUTCOME_PROMISE_RECEIVED,
        PS_COLL_OUTCOME_PROMISE_BROKEN,
        PS_COLL_OUTCOME_PAYMENT_RECEIVED,
        PS_COLL_OUTCOME_DISPUTE,
        PS_COLL_OUTCOME_ESCALATED,
        PS_COLL_OUTCOME_OTHER,
    ];
}

function psCustomerCollectionActivityTypeLabel(string $type): string
{
    $labels = [
        PS_COLL_TYPE_PHONE_CALL => 'Phone call',
        PS_COLL_TYPE_VISIT => 'Visit',
        PS_COLL_TYPE_EMAIL => 'Email',
        PS_COLL_TYPE_SMS => 'SMS',
        PS_COLL_TYPE_PROMISE_TO_PAY => 'Promise to pay',
        PS_COLL_TYPE_FOLLOW_UP => 'Follow-up',
        PS_COLL_TYPE_OTHER => 'Other',
    ];
    $key = strtoupper(trim($type));
    return $labels[$key] ?? $type;
}

function psCustomerCollectionActivityOutcomeLabel(string $outcome): string
{
    $labels = [
        PS_COLL_OUTCOME_CONTACTED => 'Contacted',
        PS_COLL_OUTCOME_NO_RESPONSE => 'No response',
        PS_COLL_OUTCOME_PROMISE_RECEIVED => 'Promise received',
        PS_COLL_OUTCOME_PROMISE_BROKEN => 'Promise broken',
        PS_COLL_OUTCOME_PAYMENT_RECEIVED => 'Payment received',
        PS_COLL_OUTCOME_DISPUTE => 'Dispute',
        PS_COLL_OUTCOME_ESCALATED => 'Escalated',
        PS_COLL_OUTCOME_OTHER => 'Other',
    ];
    $key = strtoupper(trim($outcome));
    return $labels[$key] ?? $outcome;
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeCustomerCollectionActivityType($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if (in_array($value, psCustomerCollectionActivityTypes(), true)) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Activity type is not valid.'];
}

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeCustomerCollectionActivityOutcome($raw): array
{
    $value = strtoupper(trim((string)$raw));
    if (in_array($value, psCustomerCollectionActivityOutcomes(), true)) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Activity outcome is not valid.'];
}

/**
 * Optional Y-m-d date (empty → null).
 *
 * @return array{ok:bool,value?:?string,error?:string}
 */
function psCustomerCreditControlOptionalDate($raw, string $label): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => true, 'value' => null];
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return ['ok' => false, 'error' => $label . ' must be a valid date (YYYY-MM-DD).'];
    }
    return ['ok' => true, 'value' => $raw];
}

/**
 * Utilisation % = positive_balance / credit_limit * 100 when limit > 0.
 * credit_limit = 0 → no facility (not a percentage).
 * Negative balance → 0.00% (never nonsensical negative %).
 *
 * @return array{ok:bool,utilisation_pct?:?string,has_facility?:bool,label?:string,positive_balance?:string,credit_limit?:string,error?:string}
 */
function psCustomerAccountCreditUtilisation(mysqli $conn, int $accountId, $creditLimit = null, $balance = null): array
{
    if ($creditLimit === null || $balance === null) {
        $account = psCustomerAccountById($conn, $accountId);
        if (!$account) {
            return ['ok' => false, 'error' => 'Account was not found.'];
        }
        if ($creditLimit === null) {
            $creditLimit = $account['credit_limit'];
        }
        if ($balance === null) {
            $bal = psCustomerAccountBalance($conn, $accountId);
            if (!$bal['ok']) {
                return ['ok' => false, 'error' => $bal['error'] ?? 'Balance could not be calculated.'];
            }
            $balance = $bal['balance'];
        }
    }
    $limit = number_format((float)$creditLimit, 2, '.', '');
    $balanceStr = number_format((float)$balance, 2, '.', '');
    $positive = (float)$balanceStr > 0 ? (float)$balanceStr : 0.0;
    $positiveStr = number_format($positive, 2, '.', '');
    if ((float)$limit <= 0) {
        return [
            'ok' => true,
            'utilisation_pct' => null,
            'has_facility' => false,
            'label' => 'No credit facility',
            'positive_balance' => $positiveStr,
            'credit_limit' => $limit,
        ];
    }
    $pct = ($positive / (float)$limit) * 100.0;
    return [
        'ok' => true,
        'utilisation_pct' => number_format($pct, 2, '.', ''),
        'has_facility' => true,
        'label' => number_format($pct, 1, '.', '') . '%',
        'positive_balance' => $positiveStr,
        'credit_limit' => $limit,
    ];
}

/**
 * Create append-only collection activity. Never posts ledger / PAYMENT / allocations.
 * PROMISE_TO_PAY may store promise_date + optional non-financial promise_amount.
 * next_action_date is planning only (no reminder engine).
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,error?:string}
 */
function psCustomerCollectionActivityCreate(mysqli $conn, array $data): array
{
    if (!psEnsureCustomerCreditControlModule($conn)) {
        return ['ok' => false, 'error' => 'Collection activity could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to record collection activity.'];
    }

    $accountId = (int)($data['customer_account_id'] ?? 0);
    if ($accountId < 1) {
        return ['ok' => false, 'error' => 'A valid account is required.'];
    }
    $account = psCustomerAccountById($conn, $accountId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $typeCheck = psNormalizeCustomerCollectionActivityType($data['activity_type'] ?? '');
    if (!$typeCheck['ok']) {
        return ['ok' => false, 'error' => $typeCheck['error']];
    }
    $outcomeCheck = psNormalizeCustomerCollectionActivityOutcome($data['outcome'] ?? '');
    if (!$outcomeCheck['ok']) {
        return ['ok' => false, 'error' => $outcomeCheck['error']];
    }
    $type = $typeCheck['value'];
    $outcome = $outcomeCheck['value'];

    $activityDateRaw = trim((string)($data['activity_date'] ?? date('Y-m-d')));
    $activityDateCheck = psCustomerCreditControlOptionalDate($activityDateRaw, 'Activity date');
    if (!$activityDateCheck['ok'] || $activityDateCheck['value'] === null) {
        return ['ok' => false, 'error' => $activityDateCheck['error'] ?? 'Activity date is required.'];
    }
    $activityDate = (string)$activityDateCheck['value'];

    $nextCheck = psCustomerCreditControlOptionalDate($data['next_action_date'] ?? '', 'Next action date');
    if (!$nextCheck['ok']) {
        return ['ok' => false, 'error' => $nextCheck['error']];
    }
    $nextAction = $nextCheck['value'];

    $notes = trim((string)($data['notes'] ?? ''));
    if (strlen($notes) > 1000) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }

    $promiseDate = null;
    $promiseAmount = null;
    if ($type === PS_COLL_TYPE_PROMISE_TO_PAY) {
        $pCheck = psCustomerCreditControlOptionalDate($data['promise_date'] ?? '', 'Promise date');
        if (!$pCheck['ok']) {
            return ['ok' => false, 'error' => $pCheck['error']];
        }
        $promiseDate = $pCheck['value'];
        if ($promiseDate === null) {
            return ['ok' => false, 'error' => 'Promise to pay requires a promised date.'];
        }
        $amtRaw = trim((string)($data['promise_amount'] ?? ''));
        if ($amtRaw !== '') {
            $amtCheck = psValidateCustomerAccountMoney($amtRaw, 'Promise amount');
            if (!$amtCheck['ok']) {
                return ['ok' => false, 'error' => $amtCheck['error']];
            }
            // Optional display-only amount — never creates PAYMENT or ledger rows.
            $promiseAmount = $amtCheck['value'];
        }
    }

    // Ignore any posted balance / payment / ledger fields.
    unset($data['balance'], $data['amount'], $data['payment_number'], $data['ledger_id']);

    $now = date('Y-m-d H:i:s');
    // Build INSERT dynamically so optional DATE/DECIMAL NULLs bind correctly.
    $cols = 'customer_account_id, activity_date, activity_type, outcome, notes, created_at, created_by';
    $placeholders = '?, ?, ?, ?, ?, ?, ?';
    $bindTypes = 'issssss';
    $bindValues = [$accountId, $activityDate, $type, $outcome, $notes, $now, $changedBy];

    if ($nextAction !== null) {
        $cols .= ', next_action_date';
        $placeholders .= ', ?';
        $bindTypes .= 's';
        $bindValues[] = $nextAction;
    }
    if ($promiseDate !== null) {
        $cols .= ', promise_date';
        $placeholders .= ', ?';
        $bindTypes .= 's';
        $bindValues[] = $promiseDate;
    }
    if ($promiseAmount !== null) {
        $cols .= ', promise_amount';
        $placeholders .= ', ?';
        $bindTypes .= 's';
        $bindValues[] = $promiseAmount;
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_collection_activity (' . $cols . ') VALUES (' . $placeholders . ')'
    );
    if (!$stmt) {
        error_log('collection activity insert prepare failed');
        return ['ok' => false, 'error' => 'Collection activity could not be saved.'];
    }
    $bind = [$stmt, $bindTypes];
    foreach ($bindValues as $k => $v) {
        $bind[] = &$bindValues[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('collection activity insert failed');
        return ['ok' => false, 'error' => 'Collection activity could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $newId];
}

/**
 * Paginated collection activity list (no national_id).
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,rows?:list<array<string,mixed>>,total?:int,page?:int,page_size?:int,pages?:int,error?:string}
 */
function psCustomerCollectionActivityList(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCustomerCreditControlModule($conn)) {
        return ['ok' => false, 'error' => 'Collection activity could not be loaded.', 'rows' => []];
    }

    $page = (int)($filters['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }
    $pageSize = (int)($filters['page_size'] ?? 25);
    if ($pageSize < 1 || $pageSize > 100) {
        $pageSize = 25;
    }
    $offset = ($page - 1) * $pageSize;

    $where = ' WHERE ' . psCustomerCompanySql($conn, 'c');
    $types = '';
    $params = [];

    $accountId = (int)($filters['customer_account_id'] ?? 0);
    if ($accountId > 0) {
        $where .= ' AND a.customer_account_id = ?';
        $types .= 'i';
        $params[] = $accountId;
    }
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= ' AND (ca.account_number LIKE ? OR c.customer_code LIKE ?
                     OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.business_name LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $typeFilter = strtoupper(trim((string)($filters['activity_type'] ?? 'all')));
    if ($typeFilter !== 'ALL' && $typeFilter !== '') {
        $tCheck = psNormalizeCustomerCollectionActivityType($typeFilter);
        if ($tCheck['ok']) {
            $where .= ' AND a.activity_type = ?';
            $types .= 's';
            $params[] = $tCheck['value'];
        }
    }

    $countSql = 'SELECT COUNT(*) AS c
                 FROM customer_collection_activity a
                 INNER JOIN customer_accounts ca ON ca.id = a.customer_account_id
                 INNER JOIN customer_master c ON c.id = ca.customer_master_id'
        . $where;
    $countStmt = mysqli_prepare($conn, $countSql);
    if (!$countStmt) {
        return ['ok' => false, 'error' => 'Collection activity could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        $bind = [$countStmt, $types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind);
    }
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $countRow = $countRes ? mysqli_fetch_assoc($countRes) : null;
    mysqli_stmt_close($countStmt);
    $total = (int)($countRow['c'] ?? 0);
    $pages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 1;
    if ($pages < 1) {
        $pages = 1;
    }

    $sql = 'SELECT a.*,
                   ca.account_number,
                   c.customer_code,
                   c.first_name,
                   c.last_name,
                   c.business_name,
                   c.customer_type
            FROM customer_collection_activity a
            INNER JOIN customer_accounts ca ON ca.id = a.customer_account_id
            INNER JOIN customer_master c ON c.id = ca.customer_master_id'
        . $where
        . ' ORDER BY a.activity_date DESC, a.id DESC LIMIT ? OFFSET ?';
    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $pageSize;
    $listParams[] = $offset;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Collection activity could not be loaded.', 'rows' => []];
    }
    $bind = [$stmt, $listTypes];
    foreach ($listParams as $k => $v) {
        $bind[] = &$listParams[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['display_name'] = psCustomerDisplayName($row);
        if ($row['promise_amount'] !== null && $row['promise_amount'] !== '') {
            $row['promise_amount'] = number_format((float)$row['promise_amount'], 2, '.', '');
        }
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => $pages,
    ];
}

/**
 * Recent activities for one account (account view).
 *
 * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string}
 */
function psCustomerCollectionActivityRecentForAccount(mysqli $conn, int $accountId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $list = psCustomerCollectionActivityList($conn, [
        'customer_account_id' => $accountId,
        'page' => 1,
        'page_size' => $limit,
    ]);
    if (!$list['ok']) {
        return ['ok' => false, 'error' => $list['error'] ?? 'Collection activity could not be loaded.', 'rows' => []];
    }
    return ['ok' => true, 'rows' => $list['rows'] ?? []];
}

/**
 * Credit-control dashboard: overdue / on hold / near-exceeding limit / significant outstanding / recent activity.
 * Overdue = outstanding in 1–30..91+ ageing buckets (Phase 6.4 helpers only).
 * Manual hold after review — NO auto hold. No national_id on lists.
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,summary?:array,sections?:array,filters?:array,error?:string}
 */
function psCustomerCreditControlDashboard(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCustomerCreditControlModule($conn)) {
        return ['ok' => false, 'error' => 'Credit control dashboard could not be loaded.'];
    }

    $asAt = trim((string)($filters['as_at'] ?? date('Y-m-d')));
    $asAtCheck = psCustomerAccountReceivablesResolveAsAt($asAt);
    if (!$asAtCheck['ok']) {
        return ['ok' => false, 'error' => $asAtCheck['error'] ?? 'As-at date is not valid.'];
    }
    $asAt = (string)$asAtCheck['as_at'];

    $search = trim((string)($filters['search'] ?? ''));
    $accountNumber = trim((string)($filters['account_number'] ?? ''));
    $creditStatus = strtoupper(trim((string)($filters['credit_status'] ?? 'all')));
    $accountStatus = strtoupper(trim((string)($filters['account_status'] ?? 'all')));
    $overdueBucket = strtolower(trim((string)($filters['overdue_bucket'] ?? 'all')));
    $branch = $filters['branch'] ?? null;
    $nearLimitPct = 80.0; // near utilisation threshold for "near/exceeding" section
    $significantOutstanding = 100.0; // significant absolute outstanding (local currency units)

    $ageing = psCustomerReceivablesAgeing($conn, [
        'as_at' => $asAt,
        'search' => $search !== '' ? $search : ($accountNumber !== '' ? $accountNumber : ''),
        'account_status' => $accountStatus,
        'branch' => $branch,
        'detail' => false,
    ]);
    if (!$ageing['ok']) {
        return ['ok' => false, 'error' => $ageing['error'] ?? 'Ageing could not be loaded.'];
    }

    $ageingByAccount = [];
    foreach ($ageing['rows'] ?? [] as $row) {
        $aid = (int)($row['customer_account_id'] ?? $row['account_id'] ?? 0);
        if ($aid < 1) {
            continue;
        }
        $ageingByAccount[$aid] = $row;
    }

    // Load accounts with customer display (no national_id).
    $acctList = psCustomerAccountList($conn, $search !== '' ? $search : $accountNumber, 'all');
    if (!$acctList['ok']) {
        return ['ok' => false, 'error' => $acctList['error'] ?? 'Accounts could not be loaded.'];
    }

    $overdue = [];
    $onHold = [];
    $nearLimit = [];
    $significant = [];
    $customersWithOutstanding = 0;
    $overdueReceivablesTotal = 0.0;
    $onHoldCount = 0;
    $atExceedingCount = 0;

    foreach ($acctList['rows'] as $acct) {
        $aid = (int)$acct['id'];
        $aStatus = strtoupper(trim((string)$acct['status']));
        $cStatus = strtoupper(trim((string)($acct['credit_status'] ?? PS_CUST_ACCT_CREDIT_NORMAL)));
        if ($cStatus === '') {
            $cStatus = PS_CUST_ACCT_CREDIT_NORMAL;
        }

        if ($accountStatus !== 'ALL' && $accountStatus !== '' && $aStatus !== $accountStatus) {
            continue;
        }
        if ($creditStatus !== 'ALL' && $creditStatus !== '' && $cStatus !== $creditStatus) {
            continue;
        }
        if ($accountNumber !== '' && strcasecmp((string)$acct['account_number'], $accountNumber) !== 0
            && stripos((string)$acct['account_number'], $accountNumber) === false) {
            // Allow partial match already done via search; exact-ish filter when dedicated field set.
            if ($search === '' && stripos((string)$acct['account_number'], $accountNumber) === false) {
                continue;
            }
        }

        $balance = (float)($acct['balance'] ?? 0);
        $limit = (float)($acct['credit_limit'] ?? 0);
        $util = psCustomerAccountCreditUtilisation($conn, $aid, $limit, $balance);
        $utilisationPct = $util['ok'] && !empty($util['has_facility']) ? (float)$util['utilisation_pct'] : null;

        $buckets = $ageingByAccount[$aid]['buckets'] ?? psCustomerReceivablesAgeingEmptyBuckets();
        $overdueAmt = (float)($buckets['1_30'] ?? 0)
            + (float)($buckets['31_60'] ?? 0)
            + (float)($buckets['61_90'] ?? 0)
            + (float)($buckets['91_plus'] ?? 0);
        $totalOutstanding = (float)($buckets['total'] ?? 0);
        // If ageing row missing (no open CREDIT_SALE), outstanding from ageing is 0 —
        // do not treat ledger credit/unallocated as overdue.

        $primaryOverdueBucket = '';
        if ((float)($buckets['91_plus'] ?? 0) > 0) {
            $primaryOverdueBucket = '91_plus';
        } elseif ((float)($buckets['61_90'] ?? 0) > 0) {
            $primaryOverdueBucket = '61_90';
        } elseif ((float)($buckets['31_60'] ?? 0) > 0) {
            $primaryOverdueBucket = '31_60';
        } elseif ((float)($buckets['1_30'] ?? 0) > 0) {
            $primaryOverdueBucket = '1_30';
        }

        if ($overdueBucket !== 'all' && $overdueBucket !== '') {
            $bucketMap = ['1_30' => '1_30', '31_60' => '31_60', '61_90' => '61_90', '91_plus' => '91_plus', '1-30' => '1_30', '31-60' => '31_60', '61-90' => '61_90', '91+' => '91_plus'];
            $want = $bucketMap[$overdueBucket] ?? $overdueBucket;
            if ((float)($buckets[$want] ?? 0) <= 0) {
                continue;
            }
        }

        $entry = [
            'customer_account_id' => $aid,
            'account_number' => (string)$acct['account_number'],
            'customer_code' => (string)($acct['customer_code'] ?? ''),
            'display_name' => (string)($acct['display_name'] ?? ''),
            'account_status' => $aStatus,
            'credit_status' => $cStatus,
            'credit_hold_reason' => (string)($acct['credit_hold_reason'] ?? ''),
            'balance' => number_format($balance, 2, '.', ''),
            'credit_limit' => number_format($limit, 2, '.', ''),
            'utilisation_pct' => $utilisationPct !== null ? number_format($utilisationPct, 2, '.', '') : null,
            'utilisation_label' => $util['label'] ?? '',
            'has_facility' => !empty($util['has_facility']),
            'buckets' => $buckets,
            'overdue_amount' => number_format($overdueAmt, 2, '.', ''),
            'total_outstanding' => number_format($totalOutstanding, 2, '.', ''),
            'primary_overdue_bucket' => $primaryOverdueBucket,
        ];

        if ($totalOutstanding > 0.00001) {
            $customersWithOutstanding++;
        }
        if ($overdueAmt > 0.00001) {
            $overdueReceivablesTotal += $overdueAmt;
            $overdue[] = $entry;
        }
        if ($cStatus === PS_CUST_ACCT_CREDIT_ON_HOLD) {
            $onHoldCount++;
            $onHold[] = $entry;
        }
        if (!empty($util['has_facility']) && $utilisationPct !== null && $utilisationPct >= $nearLimitPct) {
            if ($utilisationPct >= 100.0) {
                $atExceedingCount++;
            }
            $nearLimit[] = $entry;
        }
        if ($totalOutstanding >= $significantOutstanding || $balance >= $significantOutstanding) {
            $significant[] = $entry;
        }
    }

    $recentActivity = psCustomerCollectionActivityList($conn, [
        'page' => 1,
        'page_size' => 15,
        'search' => $search,
    ]);

    $recentCount = 0;
    $recentRows = [];
    if (!empty($recentActivity['ok'])) {
        $recentRows = $recentActivity['rows'] ?? [];
        // Count activities in last 30 days for summary.
        $since = date('Y-m-d', strtotime($asAt . ' -30 days'));
        $cntStmt = mysqli_prepare(
            $conn,
            'SELECT COUNT(*) AS c FROM customer_collection_activity WHERE activity_date >= ? AND activity_date <= ? AND ' . psCustomerAccountIdScopeSql($conn, 'customer_account_id')
        );
        if ($cntStmt) {
            mysqli_stmt_bind_param($cntStmt, 'ss', $since, $asAt);
            mysqli_stmt_execute($cntStmt);
            $cntRes = mysqli_stmt_get_result($cntStmt);
            $cntRow = $cntRes ? mysqli_fetch_assoc($cntRes) : null;
            mysqli_stmt_close($cntStmt);
            $recentCount = (int)($cntRow['c'] ?? 0);
        }
    }

    return [
        'ok' => true,
        'filters' => [
            'as_at' => $asAt,
            'search' => $search,
            'account_number' => $accountNumber,
            'credit_status' => $creditStatus,
            'account_status' => $accountStatus,
            'overdue_bucket' => $overdueBucket,
            'branch' => $ageing['branch'] ?? null,
        ],
        'summary' => [
            'customers_with_outstanding' => $customersWithOutstanding,
            'overdue_receivables' => number_format($overdueReceivablesTotal, 2, '.', ''),
            'on_hold_count' => $onHoldCount,
            'at_exceeding_limit_count' => $atExceedingCount,
            'recent_activities_30d' => $recentCount,
            'total_credit_sales_outstanding' => (string)($ageing['management']['total_credit_sales_outstanding'] ?? '0.00'),
            'total_customer_account_balance' => (string)($ageing['management']['total_customer_account_balance'] ?? '0.00'),
            'note' => 'Overdue = outstanding in 1–30 / 31–60 / 61–90 / 91+ ageing buckets only (not Current). '
                . 'Unallocated payments and negative balances are not treated as overdue. No auto-hold.',
        ],
        'sections' => [
            'overdue' => $overdue,
            'on_hold' => $onHold,
            'near_exceeding_limit' => $nearLimit,
            'significant_outstanding' => $significant,
            'recent_collection_activity' => $recentRows,
        ],
        'ageing_totals' => $ageing['totals'] ?? psCustomerReceivablesAgeingEmptyBuckets(),
    ];
}
