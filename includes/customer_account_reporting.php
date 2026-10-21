<?php
/**
 * Phase 6.4 — Customer Account Statements & Receivables Ageing.
 *
 * Derived views only (no second financial ledger table).
 * Statement source = customer_account_ledger chronologically.
 * Ageing = sale-level CREDIT_SALE outstanding (debit − ACTIVE allocations).
 *
 * Authoritative reused helpers (do not duplicate formulas):
 *   - psCustomerAccountBalance / psCustomerAccountAvailableCredit
 *   - psCustomerAccountSaleOutstanding (live outstanding)
 *
 * Due-date source (documented): no due_date column exists on sales or ledger.
 * Ageing uses CREDIT_SALE.entry_date as the due date (sale/charge date).
 * Current / Not Due = days_overdue <= 0 (including future-dated vs as-at).
 * No invented credit terms / grace periods.
 *
 * Viewing statements/ageing creates no financial entries and no view-audit.
 */

require_once __DIR__ . '/customer_account.php';
require_once __DIR__ . '/customer_account_payment.php';
require_once __DIR__ . '/purchasing_reports.php';

/**
 * Statement / ageing date presets.
 * Extends Phase 5 history presets with "year" (current calendar year to today).
 *
 * @return array{ok:bool,preset?:string,from_date?:?string,to_date?:?string,error?:string}
 */
function psCustomerAccountStatementResolveDates(string $preset, string $fromDate = '', string $toDate = ''): array
{
    $preset = strtolower(trim($preset));
    if ($preset === '') {
        $preset = 'month';
    }
    if (!in_array($preset, ['all', 'today', 'month', 'year', 'custom'], true)) {
        return ['ok' => false, 'error' => 'Date filter is not valid.'];
    }

    $today = date('Y-m-d');
    if ($preset === 'all') {
        return ['ok' => true, 'preset' => 'all', 'from_date' => null, 'to_date' => null];
    }
    if ($preset === 'today') {
        return ['ok' => true, 'preset' => 'today', 'from_date' => $today, 'to_date' => $today];
    }
    if ($preset === 'month') {
        return ['ok' => true, 'preset' => 'month', 'from_date' => date('Y-m-01'), 'to_date' => $today];
    }
    if ($preset === 'year') {
        return ['ok' => true, 'preset' => 'year', 'from_date' => date('Y-01-01'), 'to_date' => $today];
    }

    $from = trim($fromDate);
    $to = trim($toDate);
    if ($from === '' && $to === '') {
        return ['ok' => false, 'error' => 'Enter a from and/or to date for a custom range.'];
    }
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return ['ok' => false, 'error' => 'From date is not valid.'];
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return ['ok' => false, 'error' => 'To date is not valid.'];
    }
    if ($from !== '' && $to !== '' && strcmp($from, $to) > 0) {
        return ['ok' => false, 'error' => 'From date cannot be after to date.'];
    }
    return [
        'ok' => true,
        'preset' => 'custom',
        'from_date' => $from !== '' ? $from : null,
        'to_date' => $to !== '' ? $to : null,
    ];
}

/**
 * Validate as-at date (Y-m-d). Empty → today.
 *
 * @return array{ok:bool,as_at?:string,error?:string}
 */
function psCustomerAccountReceivablesResolveAsAt($raw): array
{
    $v = trim((string)$raw);
    if ($v === '') {
        return ['ok' => true, 'as_at' => date('Y-m-d')];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return ['ok' => false, 'error' => 'As-at date is not valid.'];
    }
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) {
        return ['ok' => false, 'error' => 'As-at date is not valid.'];
    }
    return ['ok' => true, 'as_at' => $v];
}

/**
 * Balance label for UI: Amount Due (positive) / Customer Credit (negative) / Settled (zero).
 *
 * @return array{amount:string,label:string}
 */
function psCustomerAccountBalanceLabel(string $balance): array
{
    $f = (float)$balance;
    $amount = number_format(abs($f), 2, '.', '');
    if ($f > 0.00001) {
        return ['amount' => number_format($f, 2, '.', ''), 'label' => 'Amount Due'];
    }
    if ($f < -0.00001) {
        return ['amount' => number_format($f, 2, '.', ''), 'label' => 'Customer Credit'];
    }
    return ['amount' => '0.00', 'label' => 'Settled'];
}

/**
 * Statement opening balance = ledger SUM(debit)−SUM(credit) for entries strictly
 * before the period start date. When period has no start (All / open-ended from),
 * opening = 0.00 (full history starts at zero; OPENING_BALANCE rows appear in period).
 *
 * Inclusive period rules use entry_date >= from AND entry_date <= to when set.
 * Does not invent opening balances from history — only explicit ledger rows count.
 *
 * @return array{ok:bool,opening_balance?:string,error?:string}
 */
function psCustomerAccountStatementOpeningBalance(mysqli $conn, int $accountId, ?string $fromDate): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Opening balance could not be calculated.'];
    }
    if ($fromDate === null || $fromDate === '') {
        return ['ok' => true, 'opening_balance' => '0.00'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        return ['ok' => false, 'error' => 'From date is not valid.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit
         FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_date < ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Opening balance could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'is', $accountId, $fromDate);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    $debit = (float)($row['total_debit'] ?? 0);
    $credit = (float)($row['total_credit'] ?? 0);
    return ['ok' => true, 'opening_balance' => number_format($debit - $credit, 2, '.', '')];
}

/**
 * Ledger-derived balance as-at inclusive end of as_at date (entry_date <= as_at).
 * Reuses same SUM(debit)−SUM(credit) formula as psCustomerAccountBalance with a date cut-off.
 *
 * @return array{ok:bool,balance?:string,error?:string}
 */
function psCustomerAccountBalanceAsAt(mysqli $conn, int $accountId, string $asAt): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Account balance could not be calculated.'];
    }
    $asAtCheck = psCustomerAccountReceivablesResolveAsAt($asAt);
    if (!$asAtCheck['ok']) {
        return ['ok' => false, 'error' => $asAtCheck['error'] ?? 'As-at date is not valid.'];
    }
    $asAt = (string)$asAtCheck['as_at'];

    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit
         FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_date <= ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Account balance could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'is', $accountId, $asAt);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    $debit = (float)($row['total_debit'] ?? 0);
    $credit = (float)($row['total_credit'] ?? 0);
    return ['ok' => true, 'balance' => number_format($debit - $credit, 2, '.', '')];
}

/**
 * Resolve display reference for a ledger row.
 * CREDIT_SALE → invoice number; CUSTOMER_PAYMENT → PAY-###### (not internal id).
 *
 * @param array<string,mixed> $row
 * @param array<string,string> $paymentNumberById payment.id => payment_number
 */
function psCustomerAccountStatementResolveReference(array $row, array $paymentNumberById = []): string
{
    $entryType = (string)($row['entry_type'] ?? '');
    $refType = (string)($row['reference_type'] ?? '');
    $refId = trim((string)($row['reference_id'] ?? ''));

    if ($entryType === PS_CUST_ACCT_ENTRY_CREDIT_SALE || $refType === 'SALE') {
        return $refId !== '' ? $refId : '—';
    }
    if ($refType === PS_CAP_REF_TYPE
        || $entryType === PS_CUST_ACCT_ENTRY_PAYMENT
        || $entryType === PS_CUST_ACCT_ENTRY_PAYMENT_REVERSAL
    ) {
        if ($refId !== '' && isset($paymentNumberById[$refId])) {
            return $paymentNumberById[$refId];
        }
        if ($refId !== '' && preg_match('/^PAY-\d+$/i', $refId)) {
            return strtoupper($refId);
        }
        return $refId !== '' ? ('PAY#' . $refId) : '—';
    }
    if ($entryType === PS_CUST_ACCT_ENTRY_OPENING_BALANCE) {
        return $refId !== '' ? $refId : 'OPENING';
    }
    return $refId !== '' ? $refId : '—';
}

/**
 * Batch-load PAY-###### numbers for CUSTOMER_PAYMENT reference_ids (payment ids).
 *
 * @param list<string|int> $paymentIds
 * @return array<string,string>
 */
function psCustomerAccountPaymentNumbersByIds(mysqli $conn, array $paymentIds): array
{
    $ids = [];
    foreach ($paymentIds as $id) {
        $n = (int)$id;
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
    if ($ids === [] || !psEnsureCustomerAccountPaymentModule($conn)) {
        return [];
    }
    $idList = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $types = str_repeat('i', count($idList));
    $sql = "SELECT id, payment_number FROM customer_account_payments WHERE id IN ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    $bind = [$stmt, $types];
    foreach ($idList as $k => $v) {
        $bind[] = &$idList[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $map = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $map[(string)$row['id']] = (string)$row['payment_number'];
    }
    mysqli_stmt_close($stmt);
    return $map;
}

/**
 * Customer account statement (derived from ledger).
 * Columns: Date | Reference | Description | Debit | Credit | Balance (running).
 * Running: previous + debit − credit. Order: entry_date ASC, id ASC (stable).
 * Paginate via LIMIT/OFFSET on period rows; running balance continues across pages.
 *
 * Cash / walk-in / CARD / ECOCASH / BANK_TRANSFER tender sales are excluded unless
 * they genuinely appear on customer_account_ledger (they do not on the production path).
 *
 * @param array<string,mixed> $filters preset, from_date, to_date, page, page_size
 * @return array{
 *   ok:bool,
 *   account?:array,
 *   customer?:?array,
 *   header?:array,
 *   opening_balance?:string,
 *   closing_balance?:string,
 *   period_debit?:string,
 *   period_credit?:string,
 *   rows?:list<array<string,mixed>>,
 *   pagination?:array{page:int,page_size:int,total_rows:int,total_pages:int},
 *   filters?:array{preset:string,from_date:?string,to_date:?string},
 *   balance_label?:array{amount:string,label:string},
 *   error?:string
 * }
 */
function psCustomerAccountStatement(mysqli $conn, int $accountId, array $filters = []): array
{
    if ($accountId < 1) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    if (!psEnsureCustomerAccountModule($conn) || !psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Statement could not be loaded.'];
    }
    $account = psCustomerAccountById($conn, $accountId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    $customer = psCustomerById($conn, (int)$account['customer_master_id']);

    $dates = psCustomerAccountStatementResolveDates(
        (string)($filters['preset'] ?? 'month'),
        (string)($filters['from_date'] ?? ''),
        (string)($filters['to_date'] ?? '')
    );
    if (!$dates['ok']) {
        return ['ok' => false, 'error' => $dates['error'] ?? 'Date filter is not valid.'];
    }
    $fromDate = $dates['from_date'];
    $toDate = $dates['to_date'];
    $preset = (string)$dates['preset'];

    $page = (int)($filters['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }
    $pageSize = (int)($filters['page_size'] ?? 25);
    if ($pageSize < 1 || $pageSize > 100) {
        $pageSize = 25;
    }

    $openRes = psCustomerAccountStatementOpeningBalance($conn, $accountId, $fromDate);
    if (!$openRes['ok']) {
        return ['ok' => false, 'error' => $openRes['error'] ?? 'Opening balance could not be calculated.'];
    }
    $opening = (string)$openRes['opening_balance'];

    $where = 'customer_account_id = ?';
    $types = 'i';
    $params = [$accountId];
    if ($fromDate !== null) {
        $where .= ' AND entry_date >= ?';
        $types .= 's';
        $params[] = $fromDate;
    }
    if ($toDate !== null) {
        $where .= ' AND entry_date <= ?';
        $types .= 's';
        $params[] = $toDate;
    }

    $countSql = "SELECT COUNT(*) AS c, COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS csum
                 FROM customer_account_ledger WHERE $where";
    $countStmt = mysqli_prepare($conn, $countSql);
    if (!$countStmt) {
        return ['ok' => false, 'error' => 'Statement could not be loaded.'];
    }
    $bind = [$countStmt, $types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $countRow = $countRes ? mysqli_fetch_assoc($countRes) : null;
    mysqli_stmt_close($countStmt);
    $totalRows = (int)($countRow['c'] ?? 0);
    $periodDebit = number_format((float)($countRow['d'] ?? 0), 2, '.', '');
    $periodCredit = number_format((float)($countRow['csum'] ?? 0), 2, '.', '');
    $closing = number_format((float)$opening + (float)$periodDebit - (float)$periodCredit, 2, '.', '');
    $totalPages = $pageSize > 0 ? (int)ceil($totalRows / $pageSize) : 1;
    if ($totalPages < 1) {
        $totalPages = 1;
    }
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    // Running balance before this page = opening + sum of prior period rows.
    $runningBefore = (float)$opening;
    if ($offset > 0) {
        $priorSql = "SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c
                     FROM (
                       SELECT debit, credit, entry_date, id FROM customer_account_ledger
                       WHERE $where
                       ORDER BY entry_date ASC, id ASC
                       LIMIT ?
                     ) prior_rows";
        $priorParams = $params;
        $priorTypes = $types . 'i';
        $priorParams[] = $offset;
        $priorStmt = mysqli_prepare($conn, $priorSql);
        if ($priorStmt) {
            $pBind = [$priorStmt, $priorTypes];
            foreach ($priorParams as $k => $v) {
                $pBind[] = &$priorParams[$k];
            }
            call_user_func_array('mysqli_stmt_bind_param', $pBind);
            mysqli_stmt_execute($priorStmt);
            $pRes = mysqli_stmt_get_result($priorStmt);
            $pRow = $pRes ? mysqli_fetch_assoc($pRes) : null;
            mysqli_stmt_close($priorStmt);
            $runningBefore += (float)($pRow['d'] ?? 0) - (float)($pRow['c'] ?? 0);
        }
    }

    $sql = "SELECT * FROM customer_account_ledger
            WHERE $where
            ORDER BY entry_date ASC, id ASC
            LIMIT ? OFFSET ?";
    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $pageSize;
    $listParams[] = $offset;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Statement could not be loaded.'];
    }
    $lBind = [$stmt, $listTypes];
    foreach ($listParams as $k => $v) {
        $lBind[] = &$listParams[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $lBind);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rawRows = [];
    $paymentIds = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rawRows[] = $row;
        $refType = (string)($row['reference_type'] ?? '');
        $entryType = (string)($row['entry_type'] ?? '');
        if ($refType === PS_CAP_REF_TYPE
            || $entryType === PS_CUST_ACCT_ENTRY_PAYMENT
            || $entryType === PS_CUST_ACCT_ENTRY_PAYMENT_REVERSAL
        ) {
            $rid = trim((string)($row['reference_id'] ?? ''));
            if ($rid !== '' && preg_match('/^\d+$/', $rid)) {
                $paymentIds[] = $rid;
            }
        }
    }
    mysqli_stmt_close($stmt);

    $payMap = psCustomerAccountPaymentNumbersByIds($conn, $paymentIds);
    $running = $runningBefore;
    $rows = [];
    foreach ($rawRows as $row) {
        $debit = number_format((float)$row['debit'], 2, '.', '');
        $credit = number_format((float)$row['credit'], 2, '.', '');
        $running = $running + (float)$debit - (float)$credit;
        $rows[] = [
            'id' => (int)$row['id'],
            'entry_date' => (string)$row['entry_date'],
            'entry_type' => (string)$row['entry_type'],
            'reference_type' => (string)($row['reference_type'] ?? ''),
            'reference_id' => (string)($row['reference_id'] ?? ''),
            'reference_display' => psCustomerAccountStatementResolveReference($row, $payMap),
            'description' => (string)($row['description'] ?? ''),
            'debit' => $debit,
            'credit' => $credit,
            'balance' => number_format($running, 2, '.', ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    $bal = psCustomerAccountBalance($conn, $accountId);
    $currentBalance = $bal['ok'] ? (string)$bal['balance'] : '0.00';
    $avail = psCustomerAccountAvailableCredit($conn, $accountId, $account['credit_limit'] ?? 0);
    $availableCredit = $avail['ok'] ? (string)$avail['available_credit'] : '0.00';

    return [
        'ok' => true,
        'account' => $account,
        'customer' => $customer,
        'header' => [
            'customer_name' => $customer ? psCustomerDisplayName($customer) : '',
            'customer_code' => $customer ? (string)$customer['customer_code'] : '',
            'account_number' => (string)$account['account_number'],
            'status' => (string)$account['status'],
            'credit_limit' => number_format((float)$account['credit_limit'], 2, '.', ''),
            'current_balance' => $currentBalance,
            'available_credit' => $availableCredit,
        ],
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'period_debit' => $periodDebit,
        'period_credit' => $periodCredit,
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'filters' => [
            'preset' => $preset,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ],
        'balance_label' => psCustomerAccountBalanceLabel($currentBalance),
    ];
}

/**
 * Ageing bucket keys.
 *
 * @return list<string>
 */
function psCustomerReceivablesAgeingBucketKeys(): array
{
    return ['current', '1_30', '31_60', '61_90', '91_plus'];
}

/**
 * Map days overdue to bucket. days <= 0 → current (Not Due).
 * No negative bucket amounts for credits — caller excludes non-positive outstanding.
 */
function psCustomerReceivablesAgeingBucketForDays(int $daysOverdue): string
{
    if ($daysOverdue <= 0) {
        return 'current';
    }
    if ($daysOverdue <= 30) {
        return '1_30';
    }
    if ($daysOverdue <= 60) {
        return '31_60';
    }
    if ($daysOverdue <= 90) {
        return '61_90';
    }
    return '91_plus';
}

/**
 * Empty ageing bucket totals (money strings).
 *
 * @return array{current:string,1_30:string,31_60:string,61_90:string,91_plus:string,total:string}
 */
function psCustomerReceivablesAgeingEmptyBuckets(): array
{
    return [
        'current' => '0.00',
        '1_30' => '0.00',
        '31_60' => '0.00',
        '61_90' => '0.00',
        '91_plus' => '0.00',
        'total' => '0.00',
    ];
}

/**
 * CREDIT_SALE outstanding as-at a date.
 * Sale included only if entry_date <= as_at (future-dated vs as-at excluded from earlier ageing).
 * Allocations counted only if ACTIVE and DATE(created_at) <= as_at.
 * Limitation: historical reconstruction is imperfect if allocations/voids after as-at
 * are reconstructed only via created_at / status (voided allocations excluded by status).
 *
 * Due date = CREDIT_SALE.entry_date (no due_date column; no invented terms).
 *
 * @return array{ok:bool,sale_total?:string,allocated?:string,outstanding?:string,due_date?:string,exists?:bool,error?:string}
 */
function psCustomerAccountSaleOutstandingAsAt(
    mysqli $conn,
    int $accountId,
    string $saleInvoiceNumber,
    string $asAt
): array {
    if ($accountId < 1 || !psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Sale outstanding could not be calculated.'];
    }
    $asAtCheck = psCustomerAccountReceivablesResolveAsAt($asAt);
    if (!$asAtCheck['ok']) {
        return ['ok' => false, 'error' => $asAtCheck['error'] ?? 'As-at date is not valid.'];
    }
    $asAt = (string)$asAtCheck['as_at'];
    $invoice = trim($saleInvoiceNumber);
    if ($invoice === '' || !preg_match('/^\d+$/', $invoice)) {
        return ['ok' => false, 'error' => 'Sale reference is not valid.'];
    }

    $refType = 'SALE';
    $entryType = PS_CUST_ACCT_ENTRY_CREDIT_SALE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT debit, entry_date FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_type = ? AND reference_type = ? AND reference_id = ?
           AND entry_date <= ?
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Sale outstanding could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'issss', $accountId, $entryType, $refType, $invoice, $asAt);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return [
            'ok' => true,
            'exists' => false,
            'sale_total' => '0.00',
            'allocated' => '0.00',
            'outstanding' => '0.00',
            'due_date' => '',
        ];
    }
    $saleTotal = number_format((float)$row['debit'], 2, '.', '');
    $dueDate = (string)$row['entry_date'];

    $allocStatus = PS_CAP_ALLOC_ACTIVE;
    $aStmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(allocated_amount), 0) AS total_alloc
         FROM customer_account_payment_allocations
         WHERE customer_account_id = ? AND sale_invoice_number = ? AND status = ?
           AND DATE(created_at) <= ?'
    );
    if (!$aStmt) {
        return ['ok' => false, 'error' => 'Sale outstanding could not be calculated.'];
    }
    mysqli_stmt_bind_param($aStmt, 'isss', $accountId, $invoice, $allocStatus, $asAt);
    mysqli_stmt_execute($aStmt);
    $aRes = mysqli_stmt_get_result($aStmt);
    $aRow = $aRes ? mysqli_fetch_assoc($aRes) : null;
    mysqli_stmt_close($aStmt);
    $allocated = number_format((float)($aRow['total_alloc'] ?? 0), 2, '.', '');
    $outstanding = number_format((float)$saleTotal - (float)$allocated, 2, '.', '');
    if ((float)$outstanding < 0) {
        $outstanding = '0.00';
    }
    return [
        'ok' => true,
        'exists' => true,
        'sale_total' => $saleTotal,
        'allocated' => $allocated,
        'outstanding' => $outstanding,
        'due_date' => $dueDate,
    ];
}

/**
 * One reusable customer/account ageing helper.
 * Only outstanding portion ages; fully paid excluded; partial ages remainder.
 * Unallocated payments reduce account balance but NOT sale outstanding (views stay distinct).
 *
 * Branch filter (optional): by sale branch on online_sales_ref for the invoice —
 * not by account branch (accounts are global). Uses psPurchasingResolveReportBranch security.
 *
 * @param array<string,mixed> $filters
 *   customer_account_id?, customer_master_id?, search?, account_status?, customer_status?,
 *   as_at?, branch?, detail? (bool — include sale lines)
 * @return array{
 *   ok:bool,
 *   as_at?:string,
 *   due_date_source?:string,
 *   rows?:list<array<string,mixed>>,
 *   totals?:array{current:string,1_30:string,31_60:string,61_90:string,91_plus:string,total:string},
 *   management?:array{total_credit_sales_outstanding:string,total_customer_account_balance:string,note:string},
 *   branch?:array{branch:string,all_branches:bool},
 *   as_at_notes?:list<string>,
 *   error?:string
 * }
 */
function psCustomerReceivablesAgeing(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCustomerAccountModule($conn) || !psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Receivables ageing could not be loaded.'];
    }

    $asAtCheck = psCustomerAccountReceivablesResolveAsAt($filters['as_at'] ?? date('Y-m-d'));
    if (!$asAtCheck['ok']) {
        return ['ok' => false, 'error' => $asAtCheck['error'] ?? 'As-at date is not valid.'];
    }
    $asAt = (string)$asAtCheck['as_at'];

    // Account-scoped summary may include all sale branches for that account.
    // Management/list reports use session-secured branch resolution.
    $forceAllSaleBranches = !empty($filters['all_sale_branches']);
    if ($forceAllSaleBranches) {
        $branch = '';
        $allBranches = true;
        $branchRes = ['ok' => true, 'branch' => '', 'all_branches' => true];
    } else {
        $branchRes = psPurchasingResolveReportBranch($conn, $filters['branch'] ?? null);
        if (!$branchRes['ok']) {
            return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
        }
        $branch = (string)($branchRes['branch'] ?? '');
        $allBranches = !empty($branchRes['all_branches']);
    }

    $accountIdFilter = (int)($filters['customer_account_id'] ?? 0);
    $customerIdFilter = (int)($filters['customer_master_id'] ?? 0);
    $search = trim((string)($filters['search'] ?? ''));
    $accountStatus = strtoupper(trim((string)($filters['account_status'] ?? 'all')));
    if ($accountStatus !== 'ALL' && !in_array($accountStatus, [
        PS_CUST_ACCT_STATUS_ACTIVE,
        PS_CUST_ACCT_STATUS_SUSPENDED,
        PS_CUST_ACCT_STATUS_CLOSED,
    ], true)) {
        return ['ok' => false, 'error' => 'Account status filter is not valid.'];
    }
    $customerStatus = strtoupper(trim((string)($filters['customer_status'] ?? 'all')));
    if ($customerStatus !== 'ALL' && !in_array($customerStatus, ['ACTIVE', 'INACTIVE'], true)) {
        return ['ok' => false, 'error' => 'Customer status filter is not valid.'];
    }
    $wantDetail = !empty($filters['detail']);

    // Batch CREDIT_SALE rows as-at, left join sale branch + customer/account.
    $sql = 'SELECT l.id AS ledger_id, l.customer_account_id, l.entry_date, l.debit, l.reference_id AS invoice_number,
                   a.account_number, a.status AS account_status, a.customer_master_id, a.credit_limit,
                   c.customer_code, c.first_name, c.last_name, c.business_name, c.customer_type, c.status AS customer_status,
                   osr.branch AS sale_branch
            FROM customer_account_ledger l
            INNER JOIN customer_accounts a ON a.id = l.customer_account_id
            INNER JOIN customer_master c ON c.id = a.customer_master_id
            LEFT JOIN online_sales_ref osr ON osr.invoice_number = l.reference_id
            WHERE l.entry_type = ?
              AND l.reference_type = \'SALE\'
              AND l.reference_id IS NOT NULL AND l.reference_id <> \'\'
              AND l.entry_date <= ?
              AND ' . psCustomerCompanySql($conn, 'c');
    $types = 'ss';
    $params = [PS_CUST_ACCT_ENTRY_CREDIT_SALE, $asAt];

    if ($accountIdFilter > 0) {
        $sql .= ' AND l.customer_account_id = ?';
        $types .= 'i';
        $params[] = $accountIdFilter;
    }
    if ($customerIdFilter > 0) {
        $sql .= ' AND a.customer_master_id = ?';
        $types .= 'i';
        $params[] = $customerIdFilter;
    }
    if ($accountStatus !== 'ALL') {
        $sql .= ' AND a.status = ?';
        $types .= 's';
        $params[] = $accountStatus;
    }
    if ($customerStatus !== 'ALL') {
        $sql .= ' AND c.status = ?';
        $types .= 's';
        $params[] = $customerStatus;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (c.customer_code LIKE ? OR a.account_number LIKE ?
                   OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.business_name LIKE ?
                   OR CONCAT(IFNULL(c.first_name,\'\'), \' \', IFNULL(c.last_name,\'\')) LIKE ?)';
        $types .= 'ssssss';
        for ($i = 0; $i < 6; $i++) {
            $params[] = $like;
        }
    }
    if (!$allBranches) {
        $sql .= ' AND osr.branch = ?';
        $types .= 's';
        $params[] = $branch;
    }
    $sql .= ' ORDER BY c.customer_code ASC, a.account_number ASC, l.entry_date ASC, l.id ASC';

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Receivables ageing could not be loaded.'];
    }
    $bind = [$stmt, $types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $saleRows = [];
    $accountIds = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $saleRows[] = $row;
        $accountIds[(int)$row['customer_account_id']] = (int)$row['customer_account_id'];
    }
    mysqli_stmt_close($stmt);

    // Batch ACTIVE allocations as-at for these accounts.
    $allocMap = []; // "accountId|invoice" => amount
    if ($saleRows !== []) {
        $acctList = array_values($accountIds);
        if ($acctList === []) {
            $acctList = [0];
        }
        $ph = implode(',', array_fill(0, count($acctList), '?'));
        $allocStatus = PS_CAP_ALLOC_ACTIVE;
        $aSql = "SELECT customer_account_id, sale_invoice_number, COALESCE(SUM(allocated_amount),0) AS total_alloc
                 FROM customer_account_payment_allocations
                 WHERE status = ? AND DATE(created_at) <= ?
                   AND customer_account_id IN ($ph)
                 GROUP BY customer_account_id, sale_invoice_number";
        $aTypes = 'ss' . str_repeat('i', count($acctList));
        $aParams = array_merge([$allocStatus, $asAt], $acctList);
        $aStmt = mysqli_prepare($conn, $aSql);
        if ($aStmt) {
            $aBind = [$aStmt, $aTypes];
            foreach ($aParams as $k => $v) {
                $aBind[] = &$aParams[$k];
            }
            call_user_func_array('mysqli_stmt_bind_param', $aBind);
            mysqli_stmt_execute($aStmt);
            $aRes = mysqli_stmt_get_result($aStmt);
            while ($aRes && ($aRow = mysqli_fetch_assoc($aRes))) {
                $key = (int)$aRow['customer_account_id'] . '|' . (string)$aRow['sale_invoice_number'];
                $allocMap[$key] = (float)$aRow['total_alloc'];
            }
            mysqli_stmt_close($aStmt);
        }
    }

    require_once __DIR__ . '/currency.php';
    $fxBase = psFxBase($conn, psFxSessionCompany());
    $acctCur = [];
    $fxToBase = [];
    $fxMissing = [];
    $byCurrency = [];
    $byAccount = [];
    $grand = [
        'current' => 0.0,
        '1_30' => 0.0,
        '31_60' => 0.0,
        '61_90' => 0.0,
        '91_plus' => 0.0,
        'total' => 0.0,
    ];

    foreach ($saleRows as $sale) {
        $aid = (int)$sale['customer_account_id'];
        $invoice = (string)$sale['invoice_number'];
        $saleTotal = (float)$sale['debit'];
        $key = $aid . '|' . $invoice;
        $allocated = (float)($allocMap[$key] ?? 0);
        $outstanding = $saleTotal - $allocated;
        if ($outstanding <= 0.00001) {
            continue; // fully paid excluded
        }
        $outstanding = round($outstanding, 2);
        $dueDate = (string)$sale['entry_date'];
        // days overdue = as_at - due_date (sale/charge date). Negative -> Current/Not Due.
        $days = (int)floor((strtotime($asAt . ' UTC') - strtotime($dueDate . ' UTC')) / 86400);
        if ($days < 0) {
            $days = 0;
        }
        $bucket = psCustomerReceivablesAgeingBucketForDays($days);

        // Multi-currency (2026-10-20): each account is kept in its own currency. Grand totals are in the company base
        // at today's rate (receivables are an open balance, so they are valued at the current rate); per-currency
        // totals are kept as well.
        if (!isset($acctCur[$aid])) {
            $acctRow = psCustomerAccountById($conn, $aid);
            $acctCur[$aid] = $acctRow ? psCustomerAccountRowCurrency($conn, $acctRow) : $fxBase;
        }
        $cur = $acctCur[$aid];
        if (!isset($fxToBase[$cur])) {
            $rr = psFxRate($conn, psFxSessionCompany(), $cur, $fxBase, $asAt . ' 23:59:59');
            $fxToBase[$cur] = !empty($rr['ok']) ? $rr['frac'] : null;
            if (empty($rr['ok'])) {
                $fxMissing[$cur] = (string)$rr['error'];
            }
        }
        $outstandingBase = $fxToBase[$cur] !== null
            ? psFxConvertCents((int)round($outstanding * 100), $fxToBase[$cur], psFxDecimals($conn, $fxBase)) / 100
            : 0.0;
        if (!isset($byCurrency[$cur])) {
            $byCurrency[$cur] = psCustomerReceivablesAgeingEmptyBuckets();
            foreach ($byCurrency[$cur] as $bk => $v) {
                $byCurrency[$cur][$bk] = 0.0;
            }
        }
        $byCurrency[$cur][$bucket] += $outstanding;
        $byCurrency[$cur]['total'] += $outstanding;

        if (!isset($byAccount[$aid])) {
            $byAccount[$aid] = [
                'customer_account_id' => $aid,
                'customer_master_id' => (int)$sale['customer_master_id'],
                'customer_code' => (string)$sale['customer_code'],
                'customer_name' => psCustomerDisplayName([
                    'customer_type' => $sale['customer_type'],
                    'first_name' => $sale['first_name'],
                    'last_name' => $sale['last_name'],
                    'business_name' => $sale['business_name'],
                ]),
                'customer_status' => (string)$sale['customer_status'],
                'account_number' => (string)$sale['account_number'],
                'account_status' => (string)$sale['account_status'],
                'currency' => $cur,
                'buckets' => [
                    'current' => 0.0,
                    '1_30' => 0.0,
                    '31_60' => 0.0,
                    '61_90' => 0.0,
                    '91_plus' => 0.0,
                    'total' => 0.0,
                ],
                'sales' => [],
            ];
        }
        $byAccount[$aid]['buckets'][$bucket] += $outstanding;
        $byAccount[$aid]['buckets']['total'] += $outstanding;
        $grand[$bucket] += $outstandingBase;
        $grand['total'] += $outstandingBase;

        if ($wantDetail) {
            $byAccount[$aid]['sales'][] = [
                'invoice_number' => $invoice,
                'due_date' => $dueDate,
                'sale_date' => $dueDate,
                'days_overdue' => $days,
                'bucket' => $bucket,
                'sale_total' => number_format($saleTotal, 2, '.', ''),
                'allocated' => number_format($allocated, 2, '.', ''),
                'outstanding' => number_format($outstanding, 2, '.', ''),
                'sale_branch' => (string)($sale['sale_branch'] ?? ''),
            ];
        }
    }

    // Format rows; include accounts with zero outstanding only when specifically filtered
    // and they have no open sales — for scoped account view still show empty buckets.
    $rows = [];
    foreach ($byAccount as $aid => $row) {
        $formatted = $row;
        foreach (['current', '1_30', '31_60', '61_90', '91_plus', 'total'] as $bk) {
            $formatted['buckets'][$bk] = number_format((float)$row['buckets'][$bk], 2, '.', '');
        }
        $rows[] = $formatted;
    }

    // If single-account filter and no open sales, still return one empty row for summary UI.
    if ($accountIdFilter > 0 && $rows === []) {
        $account = psCustomerAccountById($conn, $accountIdFilter);
        if ($account) {
            $customer = psCustomerById($conn, (int)$account['customer_master_id']);
            $rows[] = [
                'customer_account_id' => $accountIdFilter,
                'customer_master_id' => (int)$account['customer_master_id'],
                'customer_code' => $customer ? (string)$customer['customer_code'] : '',
                'customer_name' => $customer ? psCustomerDisplayName($customer) : '',
                'customer_status' => $customer ? (string)$customer['status'] : '',
                'account_number' => (string)$account['account_number'],
                'account_status' => (string)$account['status'],
                'buckets' => psCustomerReceivablesAgeingEmptyBuckets(),
                'sales' => [],
            ];
        }
    }

    $totals = [];
    foreach (['current', '1_30', '31_60', '61_90', '91_plus', 'total'] as $bk) {
        $totals[$bk] = number_format((float)$grand[$bk], 2, '.', '');
    }
    $totalsByCurrency = [];
    foreach ($byCurrency as $cur => $b) {
        foreach ($b as $bk => $v) {
            $totalsByCurrency[$cur][$bk] = number_format((float)$v, 2, '.', '');
        }
    }

    // Management summary: Total Credit Sales Outstanding vs Total Customer Account Balance.
    // Balance scope: same accounts that appear in filters (or all accounts matching filters even with 0 outstanding).
    $balanceScopeSql = 'SELECT a.id FROM customer_accounts a
                        INNER JOIN customer_master c ON c.id = a.customer_master_id
                        WHERE ' . psCustomerCompanySql($conn, 'c');
    $bTypes = '';
    $bParams = [];
    if ($accountIdFilter > 0) {
        $balanceScopeSql .= ' AND a.id = ?';
        $bTypes .= 'i';
        $bParams[] = $accountIdFilter;
    }
    if ($customerIdFilter > 0) {
        $balanceScopeSql .= ' AND a.customer_master_id = ?';
        $bTypes .= 'i';
        $bParams[] = $customerIdFilter;
    }
    if ($accountStatus !== 'ALL') {
        $balanceScopeSql .= ' AND a.status = ?';
        $bTypes .= 's';
        $bParams[] = $accountStatus;
    }
    if ($customerStatus !== 'ALL') {
        $balanceScopeSql .= ' AND c.status = ?';
        $bTypes .= 's';
        $bParams[] = $customerStatus;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $balanceScopeSql .= ' AND (c.customer_code LIKE ? OR a.account_number LIKE ?
                   OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.business_name LIKE ?
                   OR CONCAT(IFNULL(c.first_name,\'\'), \' \', IFNULL(c.last_name,\'\')) LIKE ?)';
        $bTypes .= 'ssssss';
        for ($i = 0; $i < 6; $i++) {
            $bParams[] = $like;
        }
    }
    $balanceTotal = 0.0;
    $bStmt = mysqli_prepare($conn, $balanceScopeSql);
    if ($bStmt) {
        if ($bTypes !== '') {
            $bBind = [$bStmt, $bTypes];
            foreach ($bParams as $k => $v) {
                $bBind[] = &$bParams[$k];
            }
            call_user_func_array('mysqli_stmt_bind_param', $bBind);
        }
        mysqli_stmt_execute($bStmt);
        $bRes = mysqli_stmt_get_result($bStmt);
        while ($bRes && ($bRow = mysqli_fetch_assoc($bRes))) {
            $bal = psCustomerAccountBalanceAsAt($conn, (int)$bRow['id'], $asAt);
            if ($bal['ok']) {
                $bAcct = psCustomerAccountById($conn, (int)$bRow['id']);
                $bCur = $bAcct ? psCustomerAccountRowCurrency($conn, $bAcct) : $fxBase;
                if ($bCur === $fxBase) {
                    $balanceTotal += (float)$bal['balance'];
                } else {
                    $br = psFxRate($conn, psFxSessionCompany(), $bCur, $fxBase, $asAt . ' 23:59:59');
                    if (!empty($br['ok'])) {
                        $balanceTotal += psFxConvertCents((int)round((float)$bal['balance'] * 100), $br['frac'], psFxDecimals($conn, $fxBase)) / 100;
                    } else {
                        $fxMissing[$bCur] = (string)$br['error'];
                    }
                }
            }
        }
        mysqli_stmt_close($bStmt);
    }

    // When branch-filtered, account balance is still whole-account (accounts are global);
    // outstanding is branch-scoped. Document this distinction.
    $mgmtNote = 'Total Credit Sales Outstanding is sale-level (allocation-based) for the filter scope. '
        . 'Total Customer Account Balance is ledger SUM(debit)−SUM(credit) as-at for matching accounts. '
        . 'Unallocated payments reduce account balance but not sale outstanding until allocated.';
    if (!$allBranches) {
        $mgmtNote .= ' Branch filter scopes outstanding by sale branch only; account balances remain whole-account.';
    }

    return [
        'ok' => true,
        'as_at' => $asAt,
        'due_date_source' => 'CREDIT_SALE.entry_date (sale/charge date); no due_date column; no invented credit terms. Current/Not Due = days overdue ≤ 0.',
        'rows' => $rows,
        'totals' => $totals,
        'base_currency' => $fxBase,
        'totals_by_currency' => $totalsByCurrency,
        'fx_missing' => $fxMissing,
        'fx_note' => count($totalsByCurrency) > 1 || array_keys($totalsByCurrency) !== [$fxBase]
            ? 'Accounts are listed in their own currencies. Grand totals are in ' . $fxBase . ' at the rate on the as-at date.'
            : '',
        'management' => [
            'total_credit_sales_outstanding' => $totals['total'],
            'total_customer_account_balance' => number_format($balanceTotal, 2, '.', ''),
            'note' => $mgmtNote,
        ],
        'branch' => [
            'branch' => $branch,
            'all_branches' => $allBranches,
        ],
        'as_at_notes' => [
            'CREDIT_SALE rows with entry_date after as-at are excluded.',
            'ACTIVE allocations counted only when DATE(created_at) ≤ as-at.',
            'Historical reconstruction is imperfect if void/status timing differs from created_at.',
            'Future-dated sales relative to as-at are excluded (do not distort earlier ageing).',
        ],
    ];
}

/**
 * Concise ageing summary for one account (shared helper wrapper).
 *
 * @return array{ok:bool,as_at?:string,buckets?:array,total_outstanding?:string,due_date_source?:string,error?:string}
 */
function psCustomerAccountAgeingSummary(mysqli $conn, int $accountId, string $asAt = ''): array
{
    if ($accountId < 1) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    $result = psCustomerReceivablesAgeing($conn, [
        'customer_account_id' => $accountId,
        'as_at' => $asAt !== '' ? $asAt : date('Y-m-d'),
        'all_sale_branches' => true,
        'account_status' => 'all',
        'customer_status' => 'all',
    ]);
    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error'] ?? 'Ageing summary could not be loaded.'];
    }
    $buckets = psCustomerReceivablesAgeingEmptyBuckets();
    $total = '0.00';
    if (!empty($result['rows'][0]['buckets'])) {
        $buckets = $result['rows'][0]['buckets'];
        $total = (string)$buckets['total'];
    } elseif (!empty($result['totals'])) {
        $buckets = $result['totals'];
        $total = (string)$result['totals']['total'];
    }
    return [
        'ok' => true,
        'as_at' => (string)$result['as_at'],
        'buckets' => $buckets,
        'total_outstanding' => $total,
        'due_date_source' => (string)($result['due_date_source'] ?? ''),
    ];
}
