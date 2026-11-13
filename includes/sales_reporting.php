<?php
/**
 * Phase 10 — Sales & transaction reporting (read-only).
 * Reuses includes/reporting.php predicates. Avoids join multiplication via
 * aggregated sale_payments subquery and header-first aggregates.
 */

require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/sale_payment.php';
require_once __DIR__ . '/product_packs.php';

/**
 * 2026-10-21 currency-aware reporting: the report context (view, currency filter, base) travels in $filters['fx'];
 * callers that do not pass one get the consolidated view with no currency filter.
 */
function psSalesReportingFx(mysqli $conn, array $filters): array
{
    return isset($filters['fx']) && is_array($filters['fx']) ? $filters['fx'] : psFxrContext($conn, []);
}

/** WHERE for a sale class + branch / date / cashier / currency filters on headers (alias r). */
function psSalesReportingWhere(mysqli $conn, array $filters, string $saleClass, bool $withCashier = true): array
{
    $saleClass = strtolower(trim($saleClass));
    $where = 'WHERE 1=1';
    if ($saleClass === 'legacy') {
        $where .= ' AND ' . psReportingSaleLegacyRecordedSql('r');
    } elseif ($saleClass === 'recorded') {
        $where .= ' AND ' . psReportingSaleNotCancelledSql('r');
    } elseif ($saleClass === 'cancelled') {
        $where .= ' AND UPPER(TRIM(COALESCE(r.status, \'\'))) = \'CANCELLED\'';
    } else {
        $where .= ' AND ' . psReportingSaleCompletedSql('r');
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
    if ($withCashier && $cashier !== '') {
        $where .= ' AND r.cashier = ?';
        $types .= 's';
        $params[] = $cashier;
    }
    $where .= psFxrCurFilterSql($conn, psSalesReportingFx($conn, $filters), 'r.currency');
    return [$where, $types, $params];
}

/** Consolidated money string of a mix (base, resolvable rows only). */
function psSalesReportingMixMoney(array $mix): string
{
    return psFxrMoney(psFxrBase($mix));
}

/**
 * Aggregate totals for a sale class over filters.
 * revenue = consolidated base (each sale at its stored rate); fx = per-currency totals.
 *
 * @param array<string,mixed> $filters from psReportingNormalizeFilters (+ 'fx' context)
 * @return array{ok:bool,txn_count?:int,revenue?:string,atv?:string,fx?:array,error?:string}
 */
function psSalesReportingAggregate(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $ctx = psSalesReportingFx($conn, $filters);
    [$where, $types, $params] = psSalesReportingWhere($conn, $filters, $saleClass);
    $sql = 'SELECT ' . psFxrSaleAggSql($conn, $ctx, 'r') . ' FROM online_sales_ref r ' . $where . ' GROUP BY r.currency';
    $g = psFxrGrouped($conn, $ctx, $sql, $types, $params, 0);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => 'Sales totals could not be loaded.'];
    }
    $mix = $g['total']['mix'];
    $count = (int)$g['total']['n'];
    $baseC = psFxrBase($mix);
    $resolved = $count - array_sum(array_map(fn($p) => $p['missing_n'], $mix));
    return [
        'ok' => true,
        'txn_count' => $count,
        'revenue' => psFxrMoney($baseC),
        'atv' => $resolved > 0 ? psFxrMoney((int)round($baseC / $resolved)) : '0.00',
        'fx' => $mix,
        'sale_class' => strtolower(trim($saleClass)),
    ];
}

/** Shared shape for by-branch / by-cashier. */
function psSalesReportingGroupedBy(mysqli $conn, array $filters, string $saleClass, string $col, string $label, string $err, bool $withCashier): array
{
    $ctx = psSalesReportingFx($conn, $filters);
    [$where, $types, $params] = psSalesReportingWhere($conn, $filters, $saleClass, $withCashier);
    $sql = 'SELECT ' . $col . ' AS k0, ' . psFxrSaleAggSql($conn, $ctx, 'r') . '
            FROM online_sales_ref r ' . $where . '
            GROUP BY ' . $col . ', r.currency';
    $g = psFxrGrouped($conn, $ctx, $sql, $types, $params, 1);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => $err];
    }
    $rows = [];
    foreach (array_slice($g['groups'], 0, 500) as $grp) {
        $c = (int)$grp['n'];
        $rev = psSalesReportingMixMoney($grp['mix']);
        $resolved = $c - array_sum(array_map(fn($p) => $p['missing_n'], $grp['mix']));
        $rows[] = [
            $label => (string)$grp['keys'][0],
            'txn_count' => $c,
            'revenue' => $rev,
            'atv' => $resolved > 0 ? psFxrMoney((int)round(psFxrBase($grp['mix']) / $resolved)) : '0.00',
            'fx' => $grp['mix'],
        ];
    }
    $mix = $g['total']['mix'];
    $tc = (int)$g['total']['n'];
    $resolvedT = $tc - array_sum(array_map(fn($p) => $p['missing_n'], $mix));
    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => $tc,
            'revenue' => psSalesReportingMixMoney($mix),
            'atv' => $resolvedT > 0 ? psFxrMoney((int)round(psFxrBase($mix) / $resolvedT)) : '0.00',
            'fx' => $mix,
        ],
        'sale_class' => strtolower(trim($saleClass)),
    ];
}

/**
 * Sales by branch (authorised scope only).
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,error?:string}
 */
function psSalesReportingByBranch(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    return psSalesReportingGroupedBy($conn, $filters, $saleClass, 'r.branch', 'branch', 'Branch sales could not be loaded.', false);
}

/**
 * Sales by cashier.
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,error?:string}
 */
function psSalesReportingByCashier(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    return psSalesReportingGroupedBy($conn, $filters, $saleClass, 'r.cashier', 'cashier', 'Cashier sales could not be loaded.', true);
}

/**
 * 2026-11-13: restaurants — sales by Sit in / Take away (sales from before the switch, or with it off: "Not recorded").
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,error?:string}
 */
function psSalesReportingByDine(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    require_once __DIR__ . '/dine_option.php';
    if (!psDineOptionEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Sit in / Take away sales could not be loaded.'];
    }
    $r = psSalesReportingGroupedBy($conn, $filters, $saleClass, "COALESCE(NULLIF(r.dine_option, ''), 'NONE')", 'dine',
        'Sit in / Take away sales could not be loaded.', true);
    if (!empty($r['ok'])) {
        foreach ($r['rows'] as &$row) {
            $row['dine'] = psDineOptionLabel($row['dine']) ?: 'Not recorded';
        }
        unset($row);
    }
    return $r;
}

/**
 * Sales by product_id + branch (never merge identical names across branches).
 * Revenue per currency (lines at their sale's stored rate); quantities are never converted.
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,pagination?:array,error?:string}
 */
function psSalesReportingByProduct(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $ctx = psSalesReportingFx($conn, $filters);
    $saleClass = strtolower(trim($saleClass));
    $page = psReportingNormalizePage($filters['page'] ?? 1, $filters['page_size'] ?? 50);
    if ($saleClass === 'completed' || $saleClass === '') {
        [$where, $types, $params] = psSalesReportingWhere($conn, $filters, 'completed');
    } else {
        [$where, $types, $params] = psSalesReportingWhere($conn, $filters, $saleClass === 'legacy' ? 'legacy' : 'recorded');
    }
    $productId = trim((string)($filters['product_id'] ?? ''));
    if ($productId !== '') {
        $where .= ' AND s.product_id = ?';
        $types .= 's';
        $params[] = $productId;
    }
    $from = 'FROM online_sales s
             INNER JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
             ' . $where;

    $countSql = 'SELECT COUNT(*) AS c FROM (SELECT s.product_id, s.branch ' . $from . ' GROUP BY s.product_id, s.branch) x';
    $stmt = mysqli_prepare($conn, $countSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $cres = mysqli_stmt_get_result($stmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    mysqli_stmt_close($stmt);
    $totalRows = (int)($crow['c'] ?? 0);
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $page['page_size']) : 1;
    if ($page['page'] > $totalPages) {
        $page['page'] = $totalPages;
        $page['offset'] = ($page['page'] - 1) * $page['page_size'];
    }

    // Page of (product, branch) keys ordered by consolidated revenue, then the per-currency figures for those keys.
    $baseLine = psFxrBaseExprSql($conn, $ctx, 'r.currency', 'CAST(s.revenue AS DECIMAL(14,2))', 'ROUND(CAST(s.revenue AS DECIMAL(14,2)) / NULLIF(r.fx_rate, 0), 2)');
    $keySql = 'SELECT s.product_id, s.branch, IFNULL(SUM(' . $baseLine . '), 0) AS ord
               ' . $from . ' GROUP BY s.product_id, s.branch
               ORDER BY ord DESC, s.product_id ASC, s.branch ASC
               LIMIT ' . (int)$page['page_size'] . ' OFFSET ' . (int)$page['offset'];
    $stmt = mysqli_prepare($conn, $keySql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $kres = mysqli_stmt_get_result($stmt);
    $keys = [];
    while ($kres && ($k = mysqli_fetch_assoc($kres))) {
        $keys[] = [(string)$k['product_id'], (string)$k['branch']];
    }
    mysqli_stmt_close($stmt);

    $rows = [];
    if ($keys) {
        $in = [];
        $kTypes = $types;
        $kParams = $params;
        foreach ($keys as [$pid, $br]) {
            $in[] = '(s.product_id = ? AND s.branch = ?)';
            $kTypes .= 'ss';
            $kParams[] = $pid;
            $kParams[] = $br;
        }
        $sql = 'SELECT s.product_id AS k0, s.branch AS k1, MAX(s.product) AS product_name,
                       IFNULL(SUM(CAST(s.quantity AS DECIMAL(14,3))), 0) AS qty,
                       COUNT(DISTINCT s.invoice_number) AS inv_n, '
            . psFxrLineAggSql($conn, $ctx, 's', 'r', 'COUNT(DISTINCT s.invoice_number)') . '
                ' . $from . ' AND (' . implode(' OR ', $in) . ')
                GROUP BY s.product_id, s.branch, r.currency';
        $g = psFxrGrouped($conn, $ctx, $sql, $kTypes, $kParams, 2, ['qty', 'inv_n']);
        if (empty($g['ok'])) {
            return ['ok' => false, 'error' => 'Product sales could not be loaded.'];
        }
        foreach ($g['groups'] as $grp) {
            $rows[] = [
                'product_id' => (string)$grp['keys'][0],
                'product' => (string)($grp['row']['product_name'] ?? ''),
                'branch' => (string)$grp['keys'][1],
                'qty' => number_format((float)($grp['sum']['qty'] ?? 0), 3, '.', ''),
                'revenue' => psSalesReportingMixMoney($grp['mix']),
                'txn_count' => (int)($grp['sum']['inv_n'] ?? 0),
                'fx' => $grp['mix'],
            ];
        }
    }

    // Totals over the full filtered set (not the page)
    $totSql = 'SELECT IFNULL(SUM(CAST(s.quantity AS DECIMAL(14,3))), 0) AS qty, COUNT(DISTINCT s.invoice_number) AS inv_n, '
        . psFxrLineAggSql($conn, $ctx, 's', 'r', 'COUNT(DISTINCT s.invoice_number)') . ' ' . $from . ' GROUP BY r.currency';
    $t = psFxrGrouped($conn, $ctx, $totSql, $types, $params, 0, ['qty', 'inv_n']);
    $tmix = $t['total']['mix'] ?? [];
    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'qty' => number_format((float)($t['total']['sum']['qty'] ?? 0), 3, '.', ''),
            'revenue' => psSalesReportingMixMoney($tmix),
            'txn_count' => (int)($t['total']['sum']['inv_n'] ?? 0),
            'fx' => $tmix,
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
 * Payment-method breakdown from sale_payments for completed sales in scope, per currency.
 * Payments are in the sale currency; the base equivalent uses the sale's stored rate.
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,limitation?:string,error?:string}
 */
function psSalesReportingPaymentMethods(mysqli $conn, array $filters): array
{
    $ctx = psSalesReportingFx($conn, $filters);
    [$where, $types, $params] = psSalesReportingWhere($conn, $filters, 'completed', false);
    $sql = 'SELECT UPPER(TRIM(p.method)) AS k0, '
        . psFxrSaleAggSql($conn, $ctx, 'r') . ', '
        . psFxrAggSelectSql($conn, $ctx, 'r.currency', 'p.paid_total', null, 'r.fx_rate', 'fp_') . '
            FROM online_sales_ref r
            INNER JOIN (
                -- 2026-11-05 split payment: one row per sale AND method, valued in the sale currency, net of change
                -- (change rows are cash, so cash is net); sum over methods = the sale total.
                SELECT invoice_number, UPPER(TRIM(method)) AS method,
                       SUM(CASE WHEN is_change = 1 THEN -COALESCE(sale_amount, 0)
                                ELSE COALESCE(sale_amount, amount_paid) - change_amount END) AS paid_total
                FROM sale_payments GROUP BY invoice_number, UPPER(TRIM(method))
            ) p ON p.invoice_number = r.invoice_number
            ' . $where . '
            GROUP BY UPPER(TRIM(p.method)), r.currency';
    $g = psFxrGrouped($conn, $ctx, $sql, $types, $params, 1, [], ['fp_']);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => 'Payment methods could not be loaded.'];
    }
    $rows = [];
    foreach ($g['groups'] as $grp) {
        $m = (string)$grp['keys'][0];
        $rows[] = [
            'method' => $m !== '' ? $m : 'UNKNOWN',
            'txn_count' => (int)$grp['n'],
            // 2026-11-05: the part of the sales paid with this method (a split sale appears under each of its methods)
            'sale_total' => psSalesReportingMixMoney($grp['mixes']['fp_'] ?? []),
            'amount_paid' => psSalesReportingMixMoney($grp['mixes']['fp_'] ?? []),
            'fx' => $grp['mix'],
            'fx_paid' => $grp['mixes']['fp_'] ?? [],
        ];
    }
    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => (int)$g['total']['n'],
            'sale_total' => psSalesReportingMixMoney($g['total']['mixes']['fp_'] ?? []),
            'amount_paid' => psSalesReportingMixMoney($g['total']['mixes']['fp_'] ?? []),
            'fx' => $g['total']['mix'],
            'fx_paid' => $g['total']['mixes']['fp_'] ?? [],
        ],
        'limitation' => 'Payment-method totals are net of change; a sale paid with several methods counts under each. Payment-method totals use sale_payments only. Legacy sales without payment rows are excluded from this breakdown. Each payment is in its sale\'s currency; consolidated figures use each sale\'s stored rate.',
    ];
}

/**
 * Multi-currency: completed sales per sale currency — count, total in that currency, and the base equivalent at each
 * sale's own stored rate. The base column adds up to the consolidated revenue; missing rates are flagged.
 *
 * @return array{ok:bool,rows?:list<array>,base?:string,totals?:array,fx?:array,error?:string}
 */
function psSalesReportingByCurrency(mysqli $conn, array $filters): array
{
    $agg = psSalesReportingAggregate($conn, $filters, 'completed');
    if (empty($agg['ok'])) {
        return ['ok' => false, 'error' => 'Sales by currency could not be loaded.'];
    }
    $ctx = psSalesReportingFx($conn, $filters);
    $rows = [];
    foreach (psFxrOrder($agg['fx'], $ctx) as $code) {
        $p = $agg['fx'][$code];
        $rows[] = [
            'currency' => $code,
            'txn_count' => $p['n'],
            'amount' => psFxrMoney($p['txn']),
            'base_amount' => psFxrMoney($p['base']),
            'legacy_n' => $p['legacy_n'],
            'missing_n' => $p['missing_n'],
        ];
    }
    return ['ok' => true, 'rows' => $rows, 'base' => $ctx['base'], 'fx' => $agg['fx'],
        'totals' => ['txn_count' => $agg['txn_count'], 'base_amount' => $agg['revenue']]];
}

/**
 * Paginated sale header list (drill-down source).
 *
 * @return array{ok:bool,rows?:list<array>,totals?:array,pagination?:array,error?:string}
 */
function psSalesReportingList(mysqli $conn, array $filters, string $saleClass = 'completed'): array
{
    $ctx = psSalesReportingFx($conn, $filters);
    $saleClass = strtolower(trim($saleClass));
    $page = psReportingNormalizePage($filters['page'] ?? 1, $filters['page_size'] ?? 50);
    [$where, $types, $params] = psSalesReportingWhere($conn, $filters, $saleClass);
    $invoice = trim((string)($filters['invoice_number'] ?? ''));
    if ($invoice !== '') {
        $where .= ' AND r.invoice_number = ?';
        $types .= 's';
        $params[] = $invoice;
    }

    $g = psFxrGrouped($conn, $ctx, 'SELECT ' . psFxrSaleAggSql($conn, $ctx, 'r') . ' FROM online_sales_ref r ' . $where . ' GROUP BY r.currency', $types, $params, 0);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => 'Sales list could not be loaded.'];
    }
    $totMix = $g['total']['mix'];
    $totalRows = (int)$g['total']['n'];
    $totRev = psSalesReportingMixMoney($totMix);
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $page['page_size']) : 1;
    if ($page['page'] > $totalPages) {
        $page['page'] = $totalPages;
        $page['offset'] = ($page['page'] - 1) * $page['page_size'];
    }

    $dateExpr = psReportingSaleDateSql('r');
    $listSql = 'SELECT r.id, r.invoice_number, r.totalz, ' . psReportingSaleCurrencySql($conn, 'r') . ' AS sale_currency, ' . psFxrSaleColsSql('r') . ', r.status, r.date_, r.sold_at, r.branch,
                       r.cashier, r.customer, r.till_session_id, r.order_number,
                       sp.payment_method, sp.payment_currency
                FROM online_sales_ref r
                LEFT JOIN (
                    -- 2026-11-05: a sale paid with several methods / currencies shows as SPLIT / MIXED
                    SELECT invoice_number,
                           CASE WHEN COUNT(DISTINCT CASE WHEN is_change = 0 THEN UPPER(TRIM(method)) END) > 1 THEN \'SPLIT\'
                                ELSE MIN(CASE WHEN is_change = 0 THEN method END) END AS payment_method,
                           CASE WHEN COUNT(DISTINCT currency) > 1 THEN \'MIXED\' ELSE MIN(currency) END AS payment_currency
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
            // Stored snapshot: currency NULL = legacy; base_total / fx_rate as saved with the sale.
            'fx_currency' => $row['fx_currency'],
            'base_total' => $row['fx_base_total'],
            'fx_rate' => (string)($row['fx_rate'] ?? ''),
            'fx_rate_ids' => (string)($row['fx_rate_ids'] ?? ''),
        ];
    }
    mysqli_stmt_close($stmt);
    $resolvedT = $totalRows - array_sum(array_map(fn($p) => $p['missing_n'], $totMix));

    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'txn_count' => $totalRows,
            'revenue' => $totRev,
            'atv' => $resolvedT > 0 ? psFxrMoney((int)round(psFxrBase($totMix) / $resolvedT)) : '0.00',
            'fx' => $totMix,
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
                branch, till_session_id, order_number, customer_master_id,
                currency, base_currency, fx_rate, fx_rate_ids, fx_path, base_total
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
        'SELECT * FROM online_sales WHERE invoice_number = ? ORDER BY id ASC LIMIT 500'
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
                    // 2026-10-28 packs: "1 pack (10)" and the pack price when sold in a pack (quantity stays base units).
                    'sell_text' => !empty($row['sell_unit']) && function_exists('psPackLineQtyText') ? psPackLineQtyText($row) : '',
                    'sell_price' => !empty($row['sell_unit']) && $row['sell_price'] !== null ? psReportingMoneyFormat($row['sell_price']) : '',
                    'sell_unit' => (string)($row['sell_unit'] ?? ''),
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
            'fx_currency' => $header['currency'],
            'base_currency' => (string)($header['base_currency'] ?? ''),
            'base_total' => $header['base_total'],
            'fx_rate' => (string)($header['fx_rate'] ?? ''),
            'fx_rate_ids' => (string)($header['fx_rate_ids'] ?? ''),
            'fx_path' => (string)($header['fx_path'] ?? ''),
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

    // Amount: processed_amount for PROCESSED rows, requested_amount otherwise. Currency: the refund's own (= original
    // sale currency) — base at the ORIGINAL sale's stored rate (base_amount saved with the request when present).
    $ctx = psSalesReportingFx($conn, $filters);
    $req = isset($cols['requested_amount']) ? 'CAST(rr.requested_amount AS DECIMAL(14,2))' : '0';
    if (isset($cols['processed_amount']) && isset($cols['requested_amount'])) {
        $amt = "(CASE WHEN UPPER(TRIM(rr.status)) = 'PROCESSED' THEN CAST(COALESCE(rr.processed_amount, rr.requested_amount) AS DECIMAL(14,2)) ELSE $req END)";
    } elseif (isset($cols['processed_amount'])) {
        $amt = 'CAST(rr.processed_amount AS DECIMAL(14,2))';
    } else {
        $amt = $req;
    }
    $hasCur = isset($cols['currency']);
    $curCol = $hasCur ? 'COALESCE(rr.currency, fxr.currency)' : 'fxr.currency';
    $byRate = "ROUND($amt / NULLIF(fxr.fx_rate, 0), 2)";
    $baseExpr = isset($cols['base_amount']) ? "(CASE WHEN $amt = $req AND rr.base_amount IS NOT NULL THEN rr.base_amount ELSE $byRate END)" : $byRate;
    $where .= psFxrCurFilterSql($conn, $ctx, $curCol);
    $sql = 'SELECT UPPER(TRIM(rr.status)) AS k0, ' . psFxrAggSelectSql($conn, $ctx, $curCol, $amt, $baseExpr, 'fxr.fx_rate') . '
            FROM sale_refund_requests rr
            LEFT JOIN online_sales_ref fxr ON fxr.invoice_number = rr.invoice_number
            ' . $where . '
            GROUP BY UPPER(TRIM(rr.status)), ' . $curCol;
    $g = psFxrGrouped($conn, $ctx, $sql, $types, $params, 1, [], [], false);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => 'Refund summary could not be loaded.'];
    }
    $groups = $g['groups'];
    usort($groups, fn($x, $y) => strcmp((string)$x['keys'][0], (string)$y['keys'][0]));
    $byStatus = [];
    $processed = ['count' => 0, 'amount' => '0.00', 'fx' => []];
    foreach ($groups as $grp) {
        $st = (string)($grp['keys'][0] !== '' && $grp['keys'][0] !== null ? $grp['keys'][0] : 'UNKNOWN');
        $amtS = psSalesReportingMixMoney($grp['mix']);
        $byStatus[] = [
            'status' => $st,
            'count' => (int)$grp['n'],
            'amount' => $amtS,
            'is_completed_financial' => ($st === 'PROCESSED'),
            'fx' => $grp['mix'],
        ];
        if ($st === 'PROCESSED') {
            $processed = ['count' => (int)$grp['n'], 'amount' => $amtS, 'fx' => $grp['mix']];
        }
    }

    return [
        'ok' => true,
        'by_status' => $byStatus,
        'processed' => $processed,
        'note' => 'Only PROCESSED refunds count as completed financial refund activity. REQUESTED/APPROVED/REJECTED are listed separately. Each refund is in its original sale\'s currency; consolidated amounts use the original sale\'s stored rate.',
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

    $ctx = psFxrContext($conn, $rawFilters);
    $f['fx'] = $ctx;
    $byCur = static function (array $res, array $prefixCols, callable $prefix, string $mixKey = 'fx') use ($ctx): array {
        $lines = [];
        foreach ($res['rows'] as $row) {
            foreach (psFxrCsvAggRows($ctx, $row[$mixKey] ?? [], $prefix($row)) as $l) {
                $lines[] = $l;
            }
        }
        foreach (psFxrCsvAggRows($ctx, $res['totals'][$mixKey] ?? [], array_merge(['TOTAL'], array_fill(0, count($prefixCols) - 1, ''))) as $l) {
            $lines[] = $l;
        }
        return $lines;
    };

    if ($kind === 'sales' || $kind === 'list') {
        $res = psSalesReportingList($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = array_merge(['Invoice', 'Date', 'Branch', 'Cashier', 'Customer', 'Status', 'Payment method'], psFxrCsvHeaders($ctx));
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = array_merge([
                $row['invoice_number'], $row['date'], $row['branch'], $row['cashier'],
                $row['customer'], $row['status'], $row['payment_method'],
            ], psFxrCsvValues($ctx, $row['fx_currency'], $row['total'], $row['base_total'], $row['fx_rate'], $row['fx_rate_ids']));
        }
        foreach (psFxrCsvAggRows($ctx, $res['totals']['fx'] ?? [], ['TOTAL', '', '', '', '', '', '']) as $l) {
            $lines[] = $l;
        }
        return ['ok' => true, 'filename' => 'sales_report.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'by_branch') {
        $res = psSalesReportingByBranch($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        return ['ok' => true, 'filename' => 'sales_by_branch.csv', 'headers' => array_merge(['Branch'], psFxrCsvAggHeaders($ctx)),
            'rows' => $byCur($res, ['Branch'], fn($r) => [$r['branch']])];
    }
    if ($kind === 'by_cashier') {
        $res = psSalesReportingByCashier($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        return ['ok' => true, 'filename' => 'sales_by_cashier.csv', 'headers' => array_merge(['Cashier'], psFxrCsvAggHeaders($ctx)),
            'rows' => $byCur($res, ['Cashier'], fn($r) => [$r['cashier']])];
    }
    if ($kind === 'by_product') {
        $res = psSalesReportingByProduct($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        return ['ok' => true, 'filename' => 'sales_by_product.csv', 'headers' => array_merge(['Product ID', 'Product', 'Branch', 'Qty'], psFxrCsvAggHeaders($ctx)),
            'rows' => $byCur($res, ['Product ID', 'Product', 'Branch', 'Qty'], fn($r) => [$r['product_id'], $r['product'], $r['branch'], $r['qty']])];
    }
    if ($kind === 'payments') {
        $res = psSalesReportingPaymentMethods($conn, $f);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        return ['ok' => true, 'filename' => 'sales_payment_methods.csv', 'headers' => array_merge(['Method', 'Measure'], psFxrCsvAggHeaders($ctx)),
            'rows' => array_merge(
                $byCur($res, ['Method', 'Measure'], fn($r) => [$r['method'], 'Sale total']),
                $byCur($res, ['Method', 'Measure'], fn($r) => [$r['method'], 'Amount paid'], 'fx_paid')
            )];
    }
    if ($kind === 'by_currency') {
        $res = psSalesReportingAggregate($conn, $f, $saleClass);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $lines = psFxrCsvAggRows($ctx, $res['fx']);
        $lines[] = ['CONSOLIDATED', $res['txn_count'], '', $ctx['base'], $res['revenue'], psFxrIncomplete($res['fx']) ? 'Incomplete: some sales have no stored rate' : 'Each sale at its stored rate'];
        return ['ok' => true, 'filename' => 'sales_by_currency.csv', 'headers' => psFxrCsvAggHeaders($ctx), 'rows' => $lines];
    }

    return ['ok' => false, 'error' => 'Export type is not valid.'];
}
