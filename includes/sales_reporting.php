<?php
/**
 * Phase 10 — Sales & transaction reporting (read-only).
 * Reuses includes/reporting.php predicates. Avoids join multiplication via
 * aggregated sale_payments subquery and header-first aggregates.
 */

require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/sale_payment.php';

/**
 * Aggregate totals for a sale class over filters.
 *
 * @param array<string,mixed> $filters from psReportingNormalizeFilters
 * @return array{ok:bool,txn_count?:int,revenue?:string,atv?:string,error?:string}
 */
function psSalesReportingAggregate(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $saleClass = strtolower(trim($saleClass));
    $where = 'WHERE 1=1';
    $types = '';
    $params = [];
    if ($saleClass === 'legacy') {
        $where .= ' AND ' . psReportingSaleLegacyRecordedSql('r');
    } elseif ($saleClass === 'recorded') {
        $where .= ' AND ' . psReportingSaleNotCancelledSql('r');
    } else {
        $where .= ' AND ' . psReportingSaleCompletedSql('r');
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where,
        $types,
        $params,
        $filters['branches'] ?? [],
        !empty($filters['all_branches']),
        'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where,
        $types,
        $params,
        $filters['from_date'] ?? null,
        $filters['to_date'] ?? null,
        'r'
    );
    $cashier = trim((string)($filters['cashier'] ?? ''));
    if ($cashier !== '') {
        $where .= ' AND r.cashier = ?';
        $types .= 's';
        $params[] = $cashier;
    }

    $sql = 'SELECT COUNT(*) AS txn_count,
                   IFNULL(SUM(' . psReportingBaseTotalSql($conn, 'r') . '), 0) AS revenue
            FROM online_sales_ref r
            ' . $where;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('sales reporting aggregate prepare failed');
        return ['ok' => false, 'error' => 'Sales totals could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('sales reporting aggregate execute failed');
        return ['ok' => false, 'error' => 'Sales totals could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $count = (int)($row['txn_count'] ?? 0);
    $revenue = psReportingMoneyFormat($row['revenue'] ?? 0);
    $atv = $count > 0
        ? psReportingMoneyFormat(((float)$revenue) / $count)
        : '0.00';
    return [
        'ok' => true,
        'txn_count' => $count,
        'revenue' => $revenue,
        'atv' => $atv,
        'sale_class' => $saleClass,
    ];
}

/**
 * Sales by branch (authorised scope only).
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,error?:string}
 */
function psSalesReportingByBranch(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $saleClass = strtolower(trim($saleClass));
    $where = 'WHERE 1=1';
    $types = '';
    $params = [];
    if ($saleClass === 'legacy') {
        $where .= ' AND ' . psReportingSaleLegacyRecordedSql('r');
    } elseif ($saleClass === 'recorded') {
        $where .= ' AND ' . psReportingSaleNotCancelledSql('r');
    } else {
        $where .= ' AND ' . psReportingSaleCompletedSql('r');
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params,
        $filters['from_date'] ?? null, $filters['to_date'] ?? null, 'r'
    );

    $sql = 'SELECT r.branch,
                   COUNT(*) AS txn_count,
                   IFNULL(SUM(' . psReportingBaseTotalSql($conn, 'r') . '), 0) AS revenue
            FROM online_sales_ref r
            ' . $where . '
            GROUP BY r.branch
            ORDER BY revenue DESC, r.branch ASC';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Branch sales could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Branch sales could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $totCount = 0;
    $totRev = '0.00';
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $c = (int)$row['txn_count'];
        $rev = psReportingMoneyFormat($row['revenue']);
        $rows[] = [
            'branch' => (string)$row['branch'],
            'txn_count' => $c,
            'revenue' => $rev,
            'atv' => $c > 0 ? psReportingMoneyFormat(((float)$rev) / $c) : '0.00',
        ];
        $totCount += $c;
        $totRev = psReportingMoneyAdd($totRev, $rev);
    }
    mysqli_stmt_close($stmt);
    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => $totCount,
            'revenue' => $totRev,
            'atv' => $totCount > 0 ? psReportingMoneyFormat(((float)$totRev) / $totCount) : '0.00',
        ],
        'sale_class' => $saleClass,
    ];
}

/**
 * Sales by cashier.
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,error?:string}
 */
function psSalesReportingByCashier(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $saleClass = strtolower(trim($saleClass));
    $where = 'WHERE 1=1';
    $types = '';
    $params = [];
    if ($saleClass === 'legacy') {
        $where .= ' AND ' . psReportingSaleLegacyRecordedSql('r');
    } elseif ($saleClass === 'recorded') {
        $where .= ' AND ' . psReportingSaleNotCancelledSql('r');
    } else {
        $where .= ' AND ' . psReportingSaleCompletedSql('r');
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params,
        $filters['from_date'] ?? null, $filters['to_date'] ?? null, 'r'
    );
    $cashier = trim((string)($filters['cashier'] ?? ''));
    if ($cashier !== '') {
        $where .= ' AND r.cashier = ?';
        $types .= 's';
        $params[] = $cashier;
    }

    $sql = 'SELECT r.cashier,
                   COUNT(*) AS txn_count,
                   IFNULL(SUM(' . psReportingBaseTotalSql($conn, 'r') . '), 0) AS revenue
            FROM online_sales_ref r
            ' . $where . '
            GROUP BY r.cashier
            ORDER BY revenue DESC, r.cashier ASC
            LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Cashier sales could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Cashier sales could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $totCount = 0;
    $totRev = '0.00';
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $c = (int)$row['txn_count'];
        $rev = psReportingMoneyFormat($row['revenue']);
        $rows[] = [
            'cashier' => (string)$row['cashier'],
            'txn_count' => $c,
            'revenue' => $rev,
            'atv' => $c > 0 ? psReportingMoneyFormat(((float)$rev) / $c) : '0.00',
        ];
        $totCount += $c;
        $totRev = psReportingMoneyAdd($totRev, $rev);
    }
    mysqli_stmt_close($stmt);
    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => $totCount,
            'revenue' => $totRev,
            'atv' => $totCount > 0 ? psReportingMoneyFormat(((float)$totRev) / $totCount) : '0.00',
        ],
        'sale_class' => $saleClass,
    ];
}

/**
 * Sales by product_id + branch (never merge identical names across branches).
 * Lines joined to filtered completed/recorded headers only (no dup payment join).
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,pagination?:array,error?:string}
 */
function psSalesReportingByProduct(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $saleClass = strtolower(trim($saleClass));
    $page = psReportingNormalizePage($filters['page'] ?? 1, $filters['page_size'] ?? 50);

    $where = 'WHERE ' . psReportingSaleNotCancelledSql('r');
    if ($saleClass === 'legacy') {
        $where = 'WHERE ' . psReportingSaleLegacyRecordedSql('r');
    } elseif ($saleClass !== 'recorded') {
        $where = 'WHERE ' . psReportingSaleCompletedSql('r');
    }
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params,
        $filters['from_date'] ?? null, $filters['to_date'] ?? null, 'r'
    );
    $productId = trim((string)($filters['product_id'] ?? ''));
    if ($productId !== '') {
        $where .= ' AND s.product_id = ?';
        $types .= 's';
        $params[] = $productId;
    }
    $cashier = trim((string)($filters['cashier'] ?? ''));
    if ($cashier !== '') {
        $where .= ' AND r.cashier = ?';
        $types .= 's';
        $params[] = $cashier;
    }

    $from = 'FROM online_sales s
             INNER JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
             ' . $where;

    $countSql = 'SELECT COUNT(*) AS c FROM (
                    SELECT s.product_id, s.branch
                    ' . $from . '
                    GROUP BY s.product_id, s.branch
                 ) x';
    $stmt = mysqli_prepare($conn, $countSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
    }
    $cres = mysqli_stmt_get_result($stmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    mysqli_stmt_close($stmt);
    $totalRows = (int)($crow['c'] ?? 0);
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $page['page_size']) : 1;
    if ($page['page'] > $totalPages) {
        $page['page'] = $totalPages;
        $page['offset'] = ($page['page'] - 1) * $page['page_size'];
    }

    $listSql = 'SELECT s.product_id, s.branch,
                       MAX(s.product) AS product_name,
                       IFNULL(SUM(CAST(s.quantity AS DECIMAL(14,3))), 0) AS qty,
                       IFNULL(SUM(' . psReportingBaseLineSql($conn, 's', 'r') . '), 0) AS revenue,
                       COUNT(DISTINCT s.invoice_number) AS txn_count
                ' . $from . '
                GROUP BY s.product_id, s.branch
                ORDER BY revenue DESC, s.product_id ASC, s.branch ASC
                LIMIT ' . (int)$page['page_size'] . ' OFFSET ' . (int)$page['offset'];
    $stmt = mysqli_prepare($conn, $listSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'product_id' => (string)$row['product_id'],
            'product' => (string)$row['product_name'],
            'branch' => (string)$row['branch'],
            'qty' => number_format((float)$row['qty'], 3, '.', ''),
            'revenue' => psReportingMoneyFormat($row['revenue']),
            'txn_count' => (int)$row['txn_count'],
        ];
    }
    mysqli_stmt_close($stmt);

    // Totals over full filtered set (not page)
    $totSql = 'SELECT IFNULL(SUM(CAST(s.quantity AS DECIMAL(14,3))), 0) AS qty,
                      IFNULL(SUM(' . psReportingBaseLineSql($conn, 's', 'r') . '), 0) AS revenue,
                      COUNT(DISTINCT s.invoice_number) AS txn_count
               ' . $from;
    $stmt = mysqli_prepare($conn, $totSql);
    $totQty = '0.000';
    $totRev = '0.00';
    $totTxn = 0;
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        if (mysqli_stmt_execute($stmt)) {
            $tres = mysqli_stmt_get_result($stmt);
            $trow = $tres ? mysqli_fetch_assoc($tres) : null;
            if ($trow) {
                $totQty = number_format((float)$trow['qty'], 3, '.', '');
                $totRev = psReportingMoneyFormat($trow['revenue']);
                $totTxn = (int)$trow['txn_count'];
            }
        }
        mysqli_stmt_close($stmt);
    }

    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'qty' => $totQty,
            'revenue' => $totRev,
            'txn_count' => $totTxn,
        ],
        'pagination' => [
            'page' => $page['page'],
            'page_size' => $page['page_size'],
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'sale_class' => $saleClass,
    ];
}

/**
 * Payment-method breakdown from sale_payments for completed sales in scope.
 * One payment row group per invoice via subquery to avoid line duplication.
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,limitation?:string,error?:string}
 */
function psSalesReportingPaymentMethods(mysqli $conn, array $filters): array
{
    $where = 'WHERE ' . psReportingSaleCompletedSql('r');
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params,
        $filters['from_date'] ?? null, $filters['to_date'] ?? null, 'r'
    );

    // Aggregate payments per invoice first, then join headers once.
    $sql = 'SELECT UPPER(TRIM(p.method)) AS method,
                   COUNT(*) AS txn_count,
                   IFNULL(SUM(' . (psFxHasColumn($conn, 'online_sales_ref', 'fx_rate') ? 'ROUND(p.paid_total / COALESCE(NULLIF(r.fx_rate, 0), 1), 2)' : 'p.paid_total') . '), 0) AS amount_paid,
                   IFNULL(SUM(' . psReportingBaseTotalSql($conn, 'r') . '), 0) AS sale_total
            FROM online_sales_ref r
            INNER JOIN (
                SELECT invoice_number,
                       MIN(method) AS method,
                       SUM(amount_paid) AS paid_total
                FROM sale_payments
                GROUP BY invoice_number
            ) p ON p.invoice_number = r.invoice_number
            ' . $where . '
            GROUP BY UPPER(TRIM(p.method))
            ORDER BY sale_total DESC';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Payment methods could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Payment methods could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $totCount = 0;
    $totSale = '0.00';
    $totPaid = '0.00';
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $c = (int)$row['txn_count'];
        $sale = psReportingMoneyFormat($row['sale_total']);
        $paid = psReportingMoneyFormat($row['amount_paid']);
        $rows[] = [
            'method' => (string)($row['method'] !== '' ? $row['method'] : 'UNKNOWN'),
            'txn_count' => $c,
            'sale_total' => $sale,
            'amount_paid' => $paid,
        ];
        $totCount += $c;
        $totSale = psReportingMoneyAdd($totSale, $sale);
        $totPaid = psReportingMoneyAdd($totPaid, $paid);
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => $totCount,
            'sale_total' => $totSale,
            'amount_paid' => $totPaid,
        ],
        'limitation' => 'Payment-method totals use sale_payments only. Legacy sales without payment rows are excluded from this breakdown. Amounts are in the base currency (each sale converted at its own rate); see Sales by currency for the amounts in each currency.',
    ];
}

/**
 * Multi-currency (2026-10-20): completed sales per sale currency — count, total in that currency, and the base
 * equivalent at each sale's own rate. The base column adds up to the consolidated revenue.
 *
 * @return array{ok:bool,rows?:list<array>,base?:string,totals?:array,error?:string}
 */
function psSalesReportingByCurrency(mysqli $conn, array $filters): array
{
    $where = 'WHERE ' . psReportingSaleCompletedSql('r');
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params,
        $filters['from_date'] ?? null, $filters['to_date'] ?? null, 'r'
    );
    $cur = psReportingSaleCurrencySql($conn, 'r');
    $sql = 'SELECT ' . $cur . ' AS currency, COUNT(*) AS txn_count,
                   IFNULL(SUM(CAST(r.totalz AS DECIMAL(14,2))), 0) AS amount,
                   IFNULL(SUM(' . psReportingBaseTotalSql($conn, 'r') . '), 0) AS base_amount
            FROM online_sales_ref r
            ' . $where . '
            GROUP BY ' . $cur . '
            ORDER BY base_amount DESC';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Sales by currency could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Sales by currency could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $totBase = '0.00';
    $totCount = 0;
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'currency' => (string)$row['currency'],
            'txn_count' => (int)$row['txn_count'],
            'amount' => psReportingMoneyFormat($row['amount']),
            'base_amount' => psReportingMoneyFormat($row['base_amount']),
        ];
        $totBase = psReportingMoneyAdd($totBase, psReportingMoneyFormat($row['base_amount']));
        $totCount += (int)$row['txn_count'];
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows, 'base' => psFxBase($conn, psFxSessionCompany()),
        'totals' => ['txn_count' => $totCount, 'base_amount' => $totBase]];
}

/**
 * Paginated sale header list (drill-down source).
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,pagination?:array,error?:string}
 */
function psSalesReportingList(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $saleClass = strtolower(trim($saleClass));
    $page = psReportingNormalizePage($filters['page'] ?? 1, $filters['page_size'] ?? 50);

    if ($saleClass === 'legacy') {
        $where = 'WHERE ' . psReportingSaleLegacyRecordedSql('r');
    } elseif ($saleClass === 'cancelled') {
        $where = 'WHERE UPPER(TRIM(COALESCE(r.status, \'\'))) = \'CANCELLED\'';
    } elseif ($saleClass === 'recorded') {
        $where = 'WHERE ' . psReportingSaleNotCancelledSql('r');
    } else {
        $where = 'WHERE ' . psReportingSaleCompletedSql('r');
    }
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'r.branch'
    );
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params,
        $filters['from_date'] ?? null, $filters['to_date'] ?? null, 'r'
    );
    $cashier = trim((string)($filters['cashier'] ?? ''));
    if ($cashier !== '') {
        $where .= ' AND r.cashier = ?';
        $types .= 's';
        $params[] = $cashier;
    }
    $invoice = trim((string)($filters['invoice_number'] ?? ''));
    if ($invoice !== '') {
        $where .= ' AND r.invoice_number = ?';
        $types .= 's';
        $params[] = $invoice;
    }

    $countSql = 'SELECT COUNT(*) AS c,
                        IFNULL(SUM(' . psReportingBaseTotalSql($conn, 'r') . '), 0) AS revenue
                 FROM online_sales_ref r ' . $where;
    $stmt = mysqli_prepare($conn, $countSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Sales list could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Sales list could not be loaded.'];
    }
    $cres = mysqli_stmt_get_result($stmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    mysqli_stmt_close($stmt);
    $totalRows = (int)($crow['c'] ?? 0);
    $totRev = psReportingMoneyFormat($crow['revenue'] ?? 0);
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $page['page_size']) : 1;
    if ($page['page'] > $totalPages) {
        $page['page'] = $totalPages;
        $page['offset'] = ($page['page'] - 1) * $page['page_size'];
    }

    $dateExpr = psReportingSaleDateSql('r');
    $listSql = 'SELECT r.id, r.invoice_number, r.totalz, ' . psReportingSaleCurrencySql($conn, 'r') . ' AS sale_currency, r.status, r.date_, r.sold_at, r.branch,
                       r.cashier, r.customer, r.till_session_id, r.order_number,
                       sp.payment_method, sp.payment_currency
                FROM online_sales_ref r
                LEFT JOIN (
                    SELECT invoice_number,
                           MIN(method) AS payment_method,
                           MIN(currency) AS payment_currency
                    FROM sale_payments
                    GROUP BY invoice_number
                ) sp ON sp.invoice_number = r.invoice_number
                ' . $where . '
                ORDER BY ' . $dateExpr . ' DESC, r.id DESC
                LIMIT ' . (int)$page['page_size'] . ' OFFSET ' . (int)$page['offset'];
    $stmt = mysqli_prepare($conn, $listSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Sales list could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Sales list could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $status = trim((string)($row['status'] ?? ''));
        $pay = trim((string)($row['payment_method'] ?? ''));
        $rows[] = [
            'id' => (int)$row['id'],
            'invoice_number' => (string)$row['invoice_number'],
            'total' => psReportingMoneyFormat($row['totalz']),
            'currency' => (string)($row['sale_currency'] ?? ''),
            'status' => $status !== '' ? $status : '(empty/legacy)',
            'date' => $row['sold_at'] ? (string)$row['sold_at'] : (string)$row['date_'],
            'branch' => (string)$row['branch'],
            'cashier' => (string)$row['cashier'],
            'customer' => (string)$row['customer'],
            'payment_method' => $pay !== '' ? strtoupper($pay) : '—',
            'payment_currency' => (string)($row['payment_currency'] ?? ''),
            'till_session_id' => $row['till_session_id'] !== null ? (int)$row['till_session_id'] : null,
            'order_number' => (string)($row['order_number'] ?? ''),
        ];
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => $totalRows,
            'revenue' => $totRev,
            'atv' => $totalRows > 0 ? psReportingMoneyFormat(((float)$totRev) / $totalRows) : '0.00',
        ],
        'pagination' => [
            'page' => $page['page'],
            'page_size' => $page['page_size'],
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'sale_class' => $saleClass,
    ];
}

/**
 * Invoice detail (header + lines + payments). Branch ACL enforced.
 *
 * @return array{ok:bool,header?:array,lines?:list,payments?:list,error?:string}
 */
function psSalesReportingInvoiceDetail(mysqli $conn, string $invoiceNumber): array
{
    if (!psReportingMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view this invoice.'];
    }
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return ['ok' => false, 'error' => 'Invoice was not found.'];
    }
    $branchRes = psReportingResolveBranch($conn, psReportingIsAdmin() ? 'all' : null);
    if (empty($branchRes['ok'])) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
    }
    $branches = $branchRes['branches'] ?? [];

    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, invoice_number, totalz, method, cashier, customer, status, date_, sold_at,
                branch, till_session_id, order_number, customer_master_id
         FROM online_sales_ref WHERE invoice_number = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Invoice could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Invoice could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $header = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$header) {
        return ['ok' => false, 'error' => 'Invoice was not found.'];
    }
    $saleBranch = psBranchNormalizeName($header['branch'] ?? '');
    if ($saleBranch === '' || !psBranchNameInList($saleBranch, $branches)) {
        // Orphan / unauthorised — deny (do not leak).
        return ['ok' => false, 'error' => 'Invoice was not found.'];
    }
    if (!psBranchSaleBranchAuthorised($conn, $saleBranch)) {
        return ['ok' => false, 'error' => 'Invoice was not found.'];
    }

    $lines = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, invoice_number, product_id, product, quantity, revenue, unit_price, status, branch, cashier
         FROM online_sales WHERE invoice_number = ? ORDER BY id ASC LIMIT 500'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
        if (mysqli_stmt_execute($stmt)) {
            $lres = mysqli_stmt_get_result($stmt);
            while ($lres && ($row = mysqli_fetch_assoc($lres))) {
                $lines[] = [
                    'id' => (int)$row['id'],
                    'product_id' => (string)$row['product_id'],
                    'product' => (string)$row['product'],
                    'quantity' => (string)$row['quantity'],
                    'unit_price' => $row['unit_price'] !== null
                        ? psReportingMoneyFormat($row['unit_price'])
                        : '',
                    'revenue' => psReportingMoneyFormat($row['revenue']),
                    'status' => (string)($row['status'] ?? ''),
                    'branch' => (string)$row['branch'],
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }

    $payments = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT method, amount_paid, change_amount, currency, reference, cashier_email, created_at, till_session_id
         FROM sale_payments WHERE invoice_number = ? ORDER BY id ASC LIMIT 50'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
        if (mysqli_stmt_execute($stmt)) {
            $pres = mysqli_stmt_get_result($stmt);
            while ($pres && ($row = mysqli_fetch_assoc($pres))) {
                $payments[] = [
                    'method' => strtoupper(trim((string)$row['method'])),
                    'amount_paid' => psReportingMoneyFormat($row['amount_paid']),
                    'change_amount' => psReportingMoneyFormat($row['change_amount']),
                    'currency' => (string)$row['currency'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'cashier_email' => (string)($row['cashier_email'] ?? ''),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'till_session_id' => $row['till_session_id'] !== null ? (int)$row['till_session_id'] : null,
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }

    $status = trim((string)($header['status'] ?? ''));
    return [
        'ok' => true,
        'header' => [
            'invoice_number' => (string)$header['invoice_number'],
            'total' => psReportingMoneyFormat($header['totalz']),
            'status' => $status !== '' ? $status : '(empty/legacy)',
            'date' => $header['sold_at'] ? (string)$header['sold_at'] : (string)$header['date_'],
            'branch' => $saleBranch,
            'cashier' => (string)$header['cashier'],
            'customer' => (string)$header['customer'],
            'header_method_note' => 'Header method field is currency on production path (' . (string)$header['method'] . ').',
            'till_session_id' => $header['till_session_id'] !== null ? (int)$header['till_session_id'] : null,
            'order_number' => (string)($header['order_number'] ?? ''),
            'customer_master_id' => $header['customer_master_id'] !== null ? (int)$header['customer_master_id'] : null,
        ],
        'lines' => $lines,
        'payments' => $payments,
    ];
}

/**
 * Refund request summary by status (PROCESSED = completed financial; others distinguished).
 *
 * @return array{ok:bool,by_status?:list,processed?:array,error?:string}
 */
function psSalesReportingRefundSummary(mysqli $conn, array $filters): array
{
    // Table may be empty / absent on older DBs — fail soft.
    $chk = @mysqli_query($conn, "SHOW TABLES LIKE 'sale_refund_requests'");
    if (!$chk || mysqli_num_rows($chk) < 1) {
        return [
            'ok' => true,
            'by_status' => [],
            'processed' => ['count' => 0, 'amount' => '0.00'],
            'limitation' => 'Refund request table not present.',
        ];
    }

    $where = 'WHERE 1=1';
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'rr.branch'
    );
    // Prefer processed_at / requested_at when present
    $dateCol = 'COALESCE(DATE(rr.processed_at), DATE(rr.requested_at), DATE(rr.created_at))';
    // Discover columns
    $cols = [];
    $cr = mysqli_query($conn, 'SHOW COLUMNS FROM sale_refund_requests');
    while ($cr && ($c = mysqli_fetch_assoc($cr))) {
        $cols[strtolower((string)$c['Field'])] = true;
    }
    if (isset($cols['processed_at']) && isset($cols['requested_at'])) {
        $dateCol = 'COALESCE(DATE(rr.processed_at), DATE(rr.requested_at))';
    } elseif (isset($cols['requested_at'])) {
        $dateCol = 'DATE(rr.requested_at)';
    } elseif (isset($cols['created_at'])) {
        $dateCol = 'DATE(rr.created_at)';
    } else {
        $dateCol = null;
    }
    if ($dateCol !== null) {
        if (!empty($filters['from_date'])) {
            $where .= ' AND ' . $dateCol . ' >= ?';
            $types .= 's';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $where .= ' AND ' . $dateCol . ' <= ?';
            $types .= 's';
            $params[] = $filters['to_date'];
        }
    }

    // Prefer processed_amount for PROCESSED rows; requested_amount otherwise / for counts group.
    $amountExpr = '0';
    if (isset($cols['processed_amount']) && isset($cols['requested_amount'])) {
        $amountExpr = 'IFNULL(SUM(CASE
            WHEN UPPER(TRIM(rr.status)) = \'PROCESSED\' THEN CAST(COALESCE(rr.processed_amount, rr.requested_amount) AS DECIMAL(14,2))
            ELSE CAST(rr.requested_amount AS DECIMAL(14,2))
        END), 0)';
    } elseif (isset($cols['requested_amount'])) {
        $amountExpr = 'IFNULL(SUM(CAST(rr.requested_amount AS DECIMAL(14,2))), 0)';
    } elseif (isset($cols['processed_amount'])) {
        $amountExpr = 'IFNULL(SUM(CAST(rr.processed_amount AS DECIMAL(14,2))), 0)';
    }

    // Multi-currency (2026-10-20): each refund in the base at its original sale's rate.
    $fxJoin = '';
    if (psFxHasColumn($conn, 'online_sales_ref', 'fx_rate') && $amountExpr !== '0') {
        $amountExpr = str_replace(['AS DECIMAL(14,2))', 'SUM(CAST('], ['AS DECIMAL(14,2)) / COALESCE(NULLIF(fxr.fx_rate, 0), 1)', 'SUM(CAST('], $amountExpr);
        $fxJoin = ' LEFT JOIN online_sales_ref fxr ON fxr.invoice_number = rr.invoice_number ';
    }
    $sql = 'SELECT UPPER(TRIM(rr.status)) AS status,
                   COUNT(*) AS cnt,
                   ' . $amountExpr . ' AS amount
            FROM sale_refund_requests rr' . $fxJoin . '
            ' . $where . '
            GROUP BY UPPER(TRIM(rr.status))
            ORDER BY status ASC';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Refund summary could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Refund summary could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $byStatus = [];
    $processed = ['count' => 0, 'amount' => '0.00'];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $st = (string)($row['status'] !== '' ? $row['status'] : 'UNKNOWN');
        $cnt = (int)$row['cnt'];
        $amt = psReportingMoneyFormat($row['amount']);
        $byStatus[] = [
            'status' => $st,
            'count' => $cnt,
            'amount' => $amt,
            'is_completed_financial' => ($st === 'PROCESSED'),
        ];
        if ($st === 'PROCESSED') {
            $processed = ['count' => $cnt, 'amount' => $amt];
        }
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'by_status' => $byStatus,
        'processed' => $processed,
        'note' => 'Only PROCESSED refunds count as completed financial refund activity. REQUESTED/APPROVED/REJECTED are listed separately. Amounts are in the base currency, each refund at its original sale\'s rate.',
    ];
}

/**
 * Cancelled / void list (CANCELLED headers only).
 *
 * @return array{ok:bool,rows?:list,totals?:array,pagination?:array,error?:string}
 */
function psSalesReportingCancelled(mysqli $conn, array $filters): array
{
    return psSalesReportingList($conn, $filters, 'cancelled');
}

/**
 * CSV export for a sales report kind.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,filename?:string,headers?:list,rows?:list,error?:string}
 */
function psSalesReportingExportCsv(mysqli $conn, string $kind, array $rawFilters = []): array
{
    $norm = psReportingNormalizeFilters($conn, $rawFilters);
    if (empty($norm['ok'])) {
        return ['ok' => false, 'error' => $norm['error'] ?? 'Export failed.'];
    }
    $f = $norm['filters'];
    $f['page'] = 1;
    $f['page_size'] = 2000;
    $saleClass = strtolower(trim((string)($rawFilters['sale_class'] ?? $f['sale_class'] ?? 'completed')));
    if (!in_array($saleClass, ['completed', 'legacy', 'recorded', 'cancelled'], true)) {
        $saleClass = 'completed';
    }
    $kind = strtolower(trim($kind));

    if ($kind === 'sales' || $kind === 'list') {
        $res = psSalesReportingList($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = ['Invoice', 'Date', 'Branch', 'Cashier', 'Customer', 'Status', 'Payment method', 'Total'];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [
                $row['invoice_number'], $row['date'], $row['branch'], $row['cashier'],
                $row['customer'], $row['status'], $row['payment_method'], $row['total'],
            ];
        }
        return ['ok' => true, 'filename' => 'sales_report.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'by_branch') {
        $res = psSalesReportingByBranch($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = ['Branch', 'Txn count', 'Revenue', 'ATV'];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [$row['branch'], $row['txn_count'], $row['revenue'], $row['atv']];
        }
        return ['ok' => true, 'filename' => 'sales_by_branch.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'by_cashier') {
        $res = psSalesReportingByCashier($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = ['Cashier', 'Txn count', 'Revenue', 'ATV'];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [$row['cashier'], $row['txn_count'], $row['revenue'], $row['atv']];
        }
        return ['ok' => true, 'filename' => 'sales_by_cashier.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'by_product') {
        $res = psSalesReportingByProduct($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = ['Product ID', 'Product', 'Branch', 'Qty', 'Revenue', 'Txn count'];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [
                $row['product_id'], $row['product'], $row['branch'],
                $row['qty'], $row['revenue'], $row['txn_count'],
            ];
        }
        return ['ok' => true, 'filename' => 'sales_by_product.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'payments') {
        $res = psSalesReportingPaymentMethods($conn, $f);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = ['Method', 'Txn count', 'Sale total', 'Amount paid'];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [$row['method'], $row['txn_count'], $row['sale_total'], $row['amount_paid']];
        }
        return ['ok' => true, 'filename' => 'sales_payment_methods.csv', 'headers' => $headers, 'rows' => $lines];
    }

    return ['ok' => false, 'error' => 'Export type is not valid.'];
}
