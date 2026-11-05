<?php
/**
 * Phase 10 — Shared reporting primitives (read-only).
 *
 * Sales authority: online_sales_ref (+ online_sales lines) + sale_payments.
 * Completed financial sales: status COMPLETED / refunded, or a sale_payments row exists.
 * CANCELLED excluded. Legacy empty-status rows without payments are "recorded legacy"
 * (shown separately; not treated as modern completed activity).
 *
 * Stock: ledger SUM(stock_movements) only when OPENING_BALANCE exists for product_id+branch.
 * goods.quantity is never authoritative for ledger-controlled products.
 * Reorder: goods.reorder_level.
 *
 * Branch: psPurchasingResolveReportBranch / authorised registered names only.
 * Orphan historical branches are excluded from scoped reports (documented limitation).
 *
 * Never mutates sales, stock, payments, refunds, tills, or sequences.
 */

require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/purchasing_reports.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/fx_report.php';

const PS_REPORT_TZ = 'Africa/Harare';

/** Modern completed / refunded-compatible financial sale statuses. */
const PS_REPORT_SALE_STATUS_COMPLETED = 'COMPLETED';
const PS_REPORT_SALE_STATUS_CANCELLED = 'CANCELLED';
const PS_REPORT_SALE_STATUS_REFUNDED = 'REFUNDED';

function psReportingEnsureTimezone(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (function_exists('date_default_timezone_set')) {
        @date_default_timezone_set(PS_REPORT_TZ);
    }
    $done = true;
}

function psReportingIsAdmin(): bool
{
    return function_exists('psBranchSessionIsAdmin')
        ? psBranchSessionIsAdmin()
        : (session_status() === PHP_SESSION_ACTIVE
            && strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin');
}

function psReportingMayView(): bool
{
    return function_exists('psBranchSessionAuthenticated')
        ? psBranchSessionAuthenticated()
        : (session_status() === PHP_SESSION_ACTIVE
            && trim((string)($_SESSION['logger'] ?? '')) !== '');
}

function psReportingRequireAuthRedirect(string $login = 'login.php'): void
{
    if (!psReportingMayView()) {
        header('Location: ' . $login);
        exit;
    }
}

/**
 * Inclusive local date range (Africa/Harare). Empty → unbound.
 *
 * @return array{ok:bool,from_dt?:?string,to_dt?:?string,from_date?:?string,to_date?:?string,error?:string}
 */
function psReportingResolveDateRange($fromDate = '', $toDate = ''): array
{
    psReportingEnsureTimezone();
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
    return [
        'ok' => true,
        'from_date' => $from !== '' ? $from : null,
        'to_date' => $to !== '' ? $to : null,
        'from_dt' => $from !== '' ? ($from . ' 00:00:00') : null,
        'to_dt' => $to !== '' ? ($to . ' 23:59:59') : null,
    ];
}

/**
 * Preset periods for dashboard cards (today / week / month) in Africa/Harare.
 *
 * @return array{ok:bool,from_date:string,to_date:string,label:string,prev_from:string,prev_to:string}
 */
function psReportingPeriodBounds(string $period): array
{
    psReportingEnsureTimezone();
    $period = strtolower(trim($period));
    $today = date('Y-m-d');
    if ($period === 'week') {
        $dow = (int)date('N'); // 1=Mon
        $from = date('Y-m-d', strtotime($today . ' -' . ($dow - 1) . ' days'));
        $to = $today;
        $len = (strtotime($to) - strtotime($from)) / 86400 + 1;
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ((int)$len - 1) . ' days'));
        return [
            'ok' => true,
            'from_date' => $from,
            'to_date' => $to,
            'label' => 'This week',
            'prev_from' => $prevFrom,
            'prev_to' => $prevTo,
        ];
    }
    if ($period === 'month') {
        $from = date('Y-m-01');
        $to = $today;
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-01', strtotime($prevTo));
        return [
            'ok' => true,
            'from_date' => $from,
            'to_date' => $to,
            'label' => 'This month',
            'prev_from' => $prevFrom,
            'prev_to' => $prevTo,
        ];
    }
    // today (default)
    $prev = date('Y-m-d', strtotime($today . ' -1 day'));
    return [
        'ok' => true,
        'from_date' => $today,
        'to_date' => $today,
        'label' => 'Today',
        'prev_from' => $prev,
        'prev_to' => $prev,
    ];
}

/**
 * Branch filter for reports. Ordinary → home/working only; admin → optional registered.
 *
 * @return array{ok:bool,branch?:string,all_branches?:bool,branches?:list<string>,error?:string}
 */
function psReportingResolveBranch(mysqli $conn, $postedBranch = null): array
{
    $res = psPurchasingResolveReportBranch($conn, $postedBranch);
    if (empty($res['ok'])) {
        return $res;
    }
    if (!empty($res['all_branches'])) {
        $names = psBranchAuthorisedRegisteredNames($conn);
        if ($names === []) {
            return ['ok' => false, 'error' => 'That branch is not available.'];
        }
        return [
            'ok' => true,
            'branch' => '',
            'all_branches' => true,
            'branches' => $names,
        ];
    }
    $branch = psBranchNormalizeName($res['branch'] ?? '');
    return [
        'ok' => true,
        'branch' => $branch,
        'all_branches' => false,
        'branches' => [$branch],
    ];
}

/**
 * Sale calendar date expression (sold_at preferred; else date_ varchar Y-m-d).
 */
function psReportingSaleDateSql(string $alias = 'r'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 'r';
    }
    return 'COALESCE(DATE(' . $a . '.sold_at), STR_TO_DATE(' . $a . '.date_, \'%Y-%m-%d\'))';
}

/**
 * SQL fragment: header is not CANCELLED.
 */
function psReportingSaleNotCancelledSql(string $alias = 'r'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    return 'UPPER(TRIM(COALESCE(' . $a . '.status, \'\'))) <> \'CANCELLED\'';
}

/**
 * SQL fragment: modern completed financial sale
 * (COMPLETED / refunded status, or payment row present). Excludes CANCELLED.
 */
function psReportingSaleCompletedSql(string $alias = 'r'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    return '(' . psReportingSaleNotCancelledSql($a) . '
        AND (
            UPPER(TRIM(COALESCE(' . $a . '.status, \'\'))) IN (\'COMPLETED\', \'REFUNDED\')
            OR EXISTS (
                SELECT 1 FROM sale_payments spc
                WHERE spc.invoice_number = ' . $a . '.invoice_number
                LIMIT 1
            )
        ))';
}

/**
 * SQL fragment: legacy recorded sale (non-cancelled, empty/other status, no payment, not COMPLETED/REFUNDED).
 */
function psReportingSaleLegacyRecordedSql(string $alias = 'r'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    return '(' . psReportingSaleNotCancelledSql($a) . '
        AND UPPER(TRIM(COALESCE(' . $a . '.status, \'\'))) NOT IN (\'COMPLETED\', \'REFUNDED\')
        AND NOT EXISTS (
            SELECT 1 FROM sale_payments spl
            WHERE spl.invoice_number = ' . $a . '.invoice_number
            LIMIT 1
        ))';
}

/**
 * Append branch IN / equality to WHERE. Returns updated types/params.
 *
 * @param list<string> $branches
 * @param list<mixed> $params
 * @return array{0:string,1:string,2:list<mixed>}
 */
function psReportingAppendBranchFilter(
    string $where,
    string $types,
    array $params,
    array $branches,
    bool $allBranches,
    string $columnSql
): array {
    if ($allBranches) {
        $in = psBranchSqlInList($branches);
        if ($in === null) {
            return [$where . ' AND 1=0', $types, $params];
        }
        $where .= ' AND ' . $columnSql . ' IN (' . $in['sql'] . ')';
        $types .= $in['types'];
        foreach ($in['params'] as $p) {
            $params[] = $p;
        }
        return [$where, $types, $params];
    }
    $branch = $branches[0] ?? '';
    $where .= ' AND ' . $columnSql . ' = ?';
    $types .= 's';
    $params[] = $branch;
    return [$where, $types, $params];
}

/**
 * Append inclusive sale-date bounds using psReportingSaleDateSql.
 *
 * @param list<mixed> $params
 * @return array{0:string,1:string,2:list<mixed>}
 */
function psReportingAppendSaleDateFilter(
    string $where,
    string $types,
    array $params,
    ?string $fromDate,
    ?string $toDate,
    string $alias = 'r'
): array {
    $expr = psReportingSaleDateSql($alias);
    if ($fromDate !== null && $fromDate !== '') {
        $where .= ' AND ' . $expr . ' >= ?';
        $types .= 's';
        $params[] = $fromDate;
    }
    if ($toDate !== null && $toDate !== '') {
        $where .= ' AND ' . $expr . ' <= ?';
        $types .= 's';
        $params[] = $toDate;
    }
    return [$where, $types, $params];
}

function psReportingMoneyAdd(string $a, string $b): string
{
    if (function_exists('bcadd')) {
        return number_format((float)bcadd($a, $b, 2), 2, '.', '');
    }
    return number_format((float)$a + (float)$b, 2, '.', '');
}

function psReportingMoneySub(string $a, string $b): string
{
    if (function_exists('bcsub')) {
        return number_format((float)bcsub($a, $b, 2), 2, '.', '');
    }
    return number_format((float)$a - (float)$b, 2, '.', '');
}

function psReportingMoneyFormat($raw): string
{
    return number_format((float)$raw, 2, '.', '');
}

/**
 * Prevent CSV formula injection (=, +, -, @, tab, CR).
 */
function psReportingCsvCell($value): string
{
    $s = (string)$value;
    if ($s === '') {
        return '';
    }
    $trim = ltrim($s);
    $first = $trim !== '' ? $trim[0] : '';
    if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
        || $first === "\t" || $first === "\r") {
        return "'" . $s;
    }
    return $s;
}

/**
 * @param list<mixed> $row
 * @return list<string>
 */
function psReportingCsvRow(array $row): array
{
    $out = [];
    foreach ($row as $cell) {
        $out[] = psReportingCsvCell($cell);
    }
    return $out;
}

/**
 * @param array{headers:list<string>,rows:list<list<mixed>>,filename:string} $export
 */
function psReportingSendCsv(array $export): void
{
    $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($export['filename'] ?? 'report.csv'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, psReportingCsvRow($export['headers'] ?? []));
    $rows = $export['rows'] ?? [];
    if ($rows === []) {
        fputcsv($out, psReportingCsvRow(['No rows for these filters.']));
    } else {
        foreach ($rows as $line) {
            fputcsv($out, psReportingCsvRow(is_array($line) ? $line : [$line]));
        }
    }
    fclose($out);
}

/**
 * @return array{page:int,page_size:int,offset:int}
 */
function psReportingNormalizePage($page, $pageSize, int $defaultSize = 50, int $maxSize = 200): array
{
    $page = (int)$page;
    if ($page < 1) {
        $page = 1;
    }
    $pageSize = (int)$pageSize;
    if ($pageSize < 1 || $pageSize > $maxSize) {
        $pageSize = $defaultSize;
    }
    return [
        'page' => $page,
        'page_size' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
    ];
}

/**
 * Resolve common report filters (dates + branch + pagination + optional cashier/product/status).
 *
 * @param array<string,mixed> $raw
 * @return array{ok:bool,filters?:array<string,mixed>,error?:string}
 */
function psReportingNormalizeFilters(mysqli $conn, array $raw): array
{
    if (!psReportingMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view reports.'];
    }
    $dates = psReportingResolveDateRange($raw['from_date'] ?? '', $raw['to_date'] ?? '');
    if (empty($dates['ok'])) {
        return ['ok' => false, 'error' => $dates['error'] ?? 'Date filter is not valid.'];
    }
    $postedBranch = psReportingIsAdmin() ? ($raw['branch'] ?? null) : null;
    $branchRes = psReportingResolveBranch($conn, $postedBranch);
    if (empty($branchRes['ok'])) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
    }
    $page = psReportingNormalizePage($raw['page'] ?? 1, $raw['page_size'] ?? 50);
    $cashier = trim((string)($raw['cashier'] ?? ''));
    if (strlen($cashier) > 250) {
        $cashier = substr($cashier, 0, 250);
    }
    $productId = trim((string)($raw['product_id'] ?? ''));
    if (strlen($productId) > 250) {
        $productId = substr($productId, 0, 250);
    }
    $status = strtoupper(trim((string)($raw['status'] ?? '')));
    $supplierId = (int)($raw['supplier_id'] ?? 0);
    if ($supplierId < 0) {
        $supplierId = 0;
    }
    $invoice = trim((string)($raw['invoice_number'] ?? $raw['invoice'] ?? ''));
    if (strlen($invoice) > 250) {
        $invoice = substr($invoice, 0, 250);
    }

    return [
        'ok' => true,
        'filters' => [
            'from_date' => $dates['from_date'],
            'to_date' => $dates['to_date'],
            'from_dt' => $dates['from_dt'],
            'to_dt' => $dates['to_dt'],
            'branch' => (string)($branchRes['branch'] ?? ''),
            'all_branches' => !empty($branchRes['all_branches']),
            'branches' => $branchRes['branches'] ?? [],
            'cashier' => $cashier,
            'product_id' => $productId,
            'status' => $status,
            'supplier_id' => $supplierId,
            'invoice_number' => $invoice,
            'page' => $page['page'],
            'page_size' => $page['page_size'],
            'offset' => $page['offset'],
            'sale_class' => strtolower(trim((string)($raw['sale_class'] ?? 'completed'))),
        ],
    ];
}

/**
 * Explicit: Phase 10 reporting never mutates operational/financial tables.
 */
function psReportingMutationsEnabled(): bool
{
    return false;
}

/**
 * Documented limitations for UI footnotes.
 *
 * @return list<string>
 */
function psReportingKnownLimitations(): array
{
    return [
        'Sales metrics for modern completed activity require COMPLETED/refunded status or a sale_payments row. Legacy empty-status headers without payments are shown separately as recorded legacy and are not treated as modern completed financial activity.',
        'online_sales_ref.method stores currency on the production path; payment-method breakdown uses sale_payments only. Legacy sales without payments show no payment-method split.',
        'Authorised-branch filters exclude historical orphan/blank branch strings (HARARE/RUSAPE/blank, etc.).',
        'Stock on hand for ledger-controlled products is SUM(stock_movements) only when OPENING_BALANCE exists; goods.quantity is not used for those products.',
        'Stock valuation uses goods.cost_price only where cost > 0 and the product is ledger-controlled; otherwise valuation is omitted or labelled incomplete.',
        'NULL-branch OPEN till sessions prevent reliable branch cash totals; cash/till cards reuse Phase 7 helpers and preserve that limitation.',
        'Invoice sequence gaps are not treated as missing sales.',
        'App-wide CSRF is not claimed. Browser testing is not claimed unless performed.',
    ];
}

// ============================================================ multi-currency (2026-10-20)

/**
 * Consolidated sale amount in the company base: base_total (saved with each sale since multi-currency); sales from
 * before then are already in the base. No DDL here — falls back to totalz when the column does not exist yet.
 */
function psReportingBaseTotalSql(mysqli $conn, string $alias = 'r'): string
{
    $cast = "CAST($alias.totalz AS DECIMAL(14,2))";
    return psFxHasColumn($conn, 'online_sales_ref', 'base_total') ? "COALESCE($alias.base_total, $cast)" : $cast;
}

/** A sale line's revenue in the base, using the header's stored rate (1 BASE = fx_rate TXN). Rounded per line. */
function psReportingBaseLineSql(mysqli $conn, string $lineAlias = 's', string $headerAlias = 'r'): string
{
    $cast = "CAST($lineAlias.revenue AS DECIMAL(14,2))";
    return psFxHasColumn($conn, 'online_sales_ref', 'fx_rate')
        ? "ROUND($cast / COALESCE(NULLIF($headerAlias.fx_rate, 0), 1), 2)"
        : $cast;
}

/** Sale currency of a header row (NULL = the legacy currency, which is the base for every company with old sales). */
function psReportingSaleCurrencySql(mysqli $conn, string $alias = 'r'): string
{
    return psFxHasColumn($conn, 'online_sales_ref', 'currency')
        ? "COALESCE($alias.currency, '" . mysqli_real_escape_string($conn, psFxLegacyCurrency()) . "')"
        : "'" . mysqli_real_escape_string($conn, psFxLegacyCurrency()) . "'";
}

