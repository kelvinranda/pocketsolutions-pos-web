<?php
/**
 * Phase 12 — Tax reports (read-only). Not a statutory tax return.
 * Reconciles to stored line tax; PENDING refunds excluded from completed adjustments.
 * Separates COMPLETE vs LEGACY_INCOMPLETE vs UNCONFIGURED / provisional.
 */

require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/tax.php';

/**
 * @return array{ok:bool,filters?:array,error?:string}
 */
function psTaxReportingResolveFilters(mysqli $conn, array $raw): array
{
    $dates = psReportingResolveDateRange($raw['from_date'] ?? '', $raw['to_date'] ?? '');
    if (!$dates['ok']) {
        return $dates;
    }
    $postedBranch = array_key_exists('branch', $raw) ? $raw['branch'] : null;
    if (psReportingIsAdmin() && ($postedBranch === null || trim((string)$postedBranch) === '')) {
        $postedBranch = 'all';
    }
    $branchRes = psReportingResolveBranch($conn, $postedBranch);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'Branch filter is not authorised.'];
    }
    $classFilter = strtoupper(trim((string)($raw['classification'] ?? '')));
    if ($classFilter !== '' && !in_array($classFilter, psTaxClassifications(), true)) {
        return ['ok' => false, 'error' => 'Classification filter is not valid.'];
    }
    $codeFilter = strtoupper(trim((string)($raw['tax_code'] ?? '')));
    if ($codeFilter !== '' && !preg_match('/^[A-Z0-9_]{1,32}$/', $codeFilter)) {
        return ['ok' => false, 'error' => 'Tax code filter is not valid.'];
    }
    $dataClass = strtoupper(trim((string)($raw['data_class'] ?? 'SUPPORTED')));
    if (!in_array($dataClass, ['SUPPORTED', 'LEGACY', 'UNCONFIGURED', 'ALL'], true)) {
        $dataClass = 'SUPPORTED';
    }
    return [
        'ok' => true,
        'filters' => [
            'from_date' => $dates['from_date'],
            'to_date' => $dates['to_date'],
            'branches' => $branchRes['branches'] ?? [],
            'all_branches' => !empty($branchRes['all_branches']),
            'classification' => $classFilter,
            'tax_code' => $codeFilter,
            'data_class' => $dataClass,
            'page' => max(1, (int)($raw['page'] ?? 1)),
            'page_size' => min(100, max(10, (int)($raw['page_size'] ?? 50))),
        ],
    ];
}

/**
 * Summary by tax code / rate for COMPLETE modern completed sales.
 *
 * @return array{ok:bool,rows?:list,totals?:array,legacy_count?:int,unconfigured_count?:int,error?:string}
 */
function psTaxReportingSummary(mysqli $conn, array $raw): array
{
    if (!psTaxMayViewReports()) {
        return ['ok' => false, 'error' => 'Not authorised.'];
    }
    if (!psEnsureTaxModule($conn)) {
        return ['ok' => false, 'error' => 'Tax reporting is not available.'];
    }
    $resolved = psTaxReportingResolveFilters($conn, $raw);
    if (!$resolved['ok']) {
        return $resolved;
    }
    $f = $resolved['filters'];

    $where = '1=1 AND ' . psReportingSaleCompletedSql('r');
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params, $f['from_date'], $f['to_date'], 'r'
    );
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $f['branches'], !empty($f['all_branches']), 'r.branch'
    );

    // Counts of non-supported headers for labelling.
    $legacyCount = 0;
    $unconfCount = 0;
    $cntSql = "SELECT r.tax_data_status, COUNT(*) AS c
               FROM online_sales_ref r
               WHERE {$where}
               GROUP BY r.tax_data_status";
    $cntStmt = mysqli_prepare($conn, $cntSql);
    if ($cntStmt) {
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$cntStmt, 'bind_param'], $bind);
        }
        if (mysqli_stmt_execute($cntStmt)) {
            $cres = mysqli_stmt_get_result($cntStmt);
            while ($cres && ($crow = mysqli_fetch_assoc($cres))) {
                $st = strtoupper(trim((string)($crow['tax_data_status'] ?? PS_TAX_STATUS_LEGACY)));
                if ($st === '' || $st === PS_TAX_STATUS_LEGACY) {
                    $legacyCount += (int)$crow['c'];
                } elseif ($st === PS_TAX_STATUS_UNCONFIGURED || $st === 'MIXED') {
                    $unconfCount += (int)$crow['c'];
                }
            }
        }
        mysqli_stmt_close($cntStmt);
    }

    $lineWhere = $where . " AND s.tax_data_status = 'COMPLETE'";
    if ($f['data_class'] === 'LEGACY') {
        return [
            'ok' => true,
            'rows' => [],
            'totals' => [
                'taxable_base' => '0.00',
                'tax_collected' => '0.00',
                'zero_rated_base' => '0.00',
                'exempt_turnover' => '0.00',
                'refund_tax' => '0.00',
                'net_tax' => '0.00',
            ],
            'legacy_count' => $legacyCount,
            'unconfigured_count' => $unconfCount,
            'note' => 'LEGACY_INCOMPLETE sales have no reliable stored tax split — listed by count only; amounts not invented.',
            'filters' => $f,
        ];
    }
    if ($f['data_class'] === 'UNCONFIGURED') {
        return [
            'ok' => true,
            'rows' => [],
            'totals' => [
                'taxable_base' => '0.00',
                'tax_collected' => '0.00',
                'zero_rated_base' => '0.00',
                'exempt_turnover' => '0.00',
                'refund_tax' => '0.00',
                'net_tax' => '0.00',
            ],
            'legacy_count' => $legacyCount,
            'unconfigured_count' => $unconfCount,
            'note' => 'UNCONFIGURED / MIXED sales have no complete tax split.',
            'filters' => $f,
        ];
    }

    if ($f['classification'] !== '') {
        $lineWhere .= ' AND s.tax_classification = ?';
        $types .= 's';
        $params[] = $f['classification'];
    }
    if ($f['tax_code'] !== '') {
        $lineWhere .= ' AND s.tax_code = ?';
        $types .= 's';
        $params[] = $f['tax_code'];
    }

    // 2026-10-21 multi-currency: every tax line is in its sale's currency. Totals are kept per currency (mixes) and the
    // consolidated figures use each sale's STORED rate (line amount / sale fx_rate). Lines are never added across
    // currencies without that conversion.
    require_once __DIR__ . '/fx_report.php';
    $ctx = psFxrContext($conn, $raw);
    $lineWhere .= psFxrCurFilterSql($conn, $ctx, 'r.currency');
    $zrAmt = "CASE WHEN s.tax_classification = 'ZERO_RATED' THEN CAST(IFNULL(s.line_total_excl, s.taxable_amount) AS DECIMAL(14,2)) ELSE 0 END";
    $exAmt = "CASE WHEN s.tax_classification = 'EXEMPT' THEN CAST(IFNULL(s.line_total_excl, s.revenue) AS DECIMAL(14,2)) ELSE 0 END";
    $sql = "SELECT s.tax_code AS k0, s.tax_classification AS k1, s.tax_rate_percent AS k2, s.tax_pricing_mode AS k3, "
        . psFxrAggSelectSql($conn, $ctx, 'r.currency', 'CAST(s.taxable_amount AS DECIMAL(14,2))', null, 'r.fx_rate') . ', '
        . psFxrAggSelectSql($conn, $ctx, 'r.currency', 'CAST(s.tax_amount AS DECIMAL(14,2))', null, 'r.fx_rate', 'tc_') . ', '
        . psFxrAggSelectSql($conn, $ctx, 'r.currency', $zrAmt, null, 'r.fx_rate', 'zr_') . ', '
        . psFxrAggSelectSql($conn, $ctx, 'r.currency', $exAmt, null, 'r.fx_rate', 'ex_') . "
            FROM online_sales s
            INNER JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
            WHERE {$lineWhere}
            GROUP BY s.tax_code, s.tax_classification, s.tax_rate_percent, s.tax_pricing_mode, r.currency";
    $g = psFxrGrouped($conn, $ctx, $sql, $types, $params, 4, [], ['tc_', 'zr_', 'ex_'], false);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => 'Tax report could not be loaded.'];
    }
    $groups = $g['groups'];
    usort($groups, fn($x, $y) => strcmp((string)$x['keys'][0], (string)$y['keys'][0]) ?: ((float)$x['keys'][2] <=> (float)$y['keys'][2]));
    $rows = [];
    $mTaxable = $mTax = $mZero = $mExempt = [];
    foreach ($groups as $grp) {
        [$code, $classRaw, $rate, $mode] = $grp['keys'];
        $class = strtoupper(trim((string)$classRaw));
        $mixTaxable = $grp['mix'];
        $mixTax = $grp['mixes']['tc_'] ?? [];
        $mixZero = $grp['mixes']['zr_'] ?? [];
        $mixExempt = $grp['mixes']['ex_'] ?? [];
        // For TAXABLE rows taxable_base is excl-tax; for ZERO_RATED use zero_rated_base as the reported base.
        $displayMix = $class === PS_TAX_CLASS_ZERO_RATED ? $mixZero : ($class === PS_TAX_CLASS_EXEMPT ? $mixExempt : $mixTaxable);
        $rows[] = [
            'tax_code' => (string)$code,
            'classification' => $class,
            'rate_percent' => number_format((float)$rate, 4, '.', ''),
            'pricing_mode' => (string)$mode,
            'line_count' => (int)$grp['n'],
            'taxable_base' => psFxrMoney(psFxrBase($displayMix)),
            'tax_collected' => psFxrMoney(psFxrBase($mixTax)),
            'zero_rated_base' => psFxrMoney(psFxrBase($mixZero)),
            'exempt_turnover' => psFxrMoney(psFxrBase($mixExempt)),
            'fx_base' => $displayMix,
            'fx_tax' => $mixTax,
            'fx_zero' => $mixZero,
            'fx_exempt' => $mixExempt,
        ];
        if ($class === PS_TAX_CLASS_TAXABLE) {
            psFxrMerge($mTaxable, $mixTaxable);
            psFxrMerge($mTax, $mixTax);
        } elseif ($class === PS_TAX_CLASS_ZERO_RATED) {
            psFxrMerge($mZero, $mixZero);
        } elseif ($class === PS_TAX_CLASS_EXEMPT) {
            psFxrMerge($mExempt, $mixExempt);
        }
    }

    // PROCESSED refund tax adjustments only (from stored snapshots), in the original sale currency.
    $mRefund = psTaxReportingRefundTaxMix($conn, $f, $ctx);
    $mNet = $mTax;
    psFxrMerge($mNet, psFxrNegate($mRefund));

    return [
        'ok' => true,
        'rows' => $rows,
        'totals' => [
            'taxable_base' => psFxrMoney(psFxrBase($mTaxable)),
            'tax_collected' => psFxrMoney(psFxrBase($mTax)),
            'zero_rated_base' => psFxrMoney(psFxrBase($mZero)),
            'exempt_turnover' => psFxrMoney(psFxrBase($mExempt)),
            'refund_tax' => psFxrMoney(psFxrBase($mRefund)),
            'net_tax' => psFxrMoney(psFxrBase($mNet)),
            'fx_taxable' => $mTaxable,
            'fx_tax' => $mTax,
            'fx_zero' => $mZero,
            'fx_exempt' => $mExempt,
            'fx_refund' => $mRefund,
            'fx_net' => $mNet,
        ],
        'legacy_count' => $legacyCount,
        'unconfigured_count' => $unconfCount,
        'note' => 'Supported COMPLETE tax lines only. Not a statutory return. PENDING/APPROVED/REJECTED refunds excluded. Each line is in its sale currency; consolidated amounts use each sale\'s stored rate. VAT returns in a single currency should use the consolidated figures or file per currency as ZIMRA requires.',
        'filters' => $f,
        'fx' => $ctx,
    ];
}

/** Processed refund tax per currency (original sale currency; base at the original sale's stored rate). */
function psTaxReportingRefundTaxMix(mysqli $conn, array $f, array $ctx): array
{
    $where = "rr.status = 'PROCESSED' AND rr.processed_tax_amount IS NOT NULL";
    $types = '';
    $params = [];
    if ($f['from_date'] !== null && $f['from_date'] !== '') {
        $where .= ' AND DATE(rr.processed_at) >= ?';
        $types .= 's';
        $params[] = $f['from_date'];
    }
    if ($f['to_date'] !== null && $f['to_date'] !== '') {
        $where .= ' AND DATE(rr.processed_at) <= ?';
        $types .= 's';
        $params[] = $f['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $f['branches'], !empty($f['all_branches']), 'rr.branch'
    );
    $cur = psFxHasColumn($conn, 'sale_refund_requests', 'currency') ? 'COALESCE(rr.currency, sr.currency)' : 'sr.currency';
    $where .= psFxrCurFilterSql($conn, $ctx, $cur);
    $g = psFxrGrouped($conn, $ctx, 'SELECT ' . psFxrAggSelectSql($conn, $ctx, $cur, 'CAST(rr.processed_tax_amount AS DECIMAL(14,2))', null, 'sr.fx_rate') . "
            FROM sale_refund_requests rr LEFT JOIN online_sales_ref sr ON sr.invoice_number = rr.invoice_number
            WHERE {$where} GROUP BY {$cur}", $types, $params, 0);
    return $g['total']['mix'] ?? [];
}

function psTaxReportingRefundTaxTotal(mysqli $conn, array $f): string
{
    $where = "rr.status = 'PROCESSED' AND rr.processed_tax_amount IS NOT NULL";
    $types = '';
    $params = [];
    if ($f['from_date'] !== null && $f['from_date'] !== '') {
        $where .= ' AND DATE(rr.processed_at) >= ?';
        $types .= 's';
        $params[] = $f['from_date'];
    }
    if ($f['to_date'] !== null && $f['to_date'] !== '') {
        $where .= ' AND DATE(rr.processed_at) <= ?';
        $types .= 's';
        $params[] = $f['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $f['branches'], !empty($f['all_branches']), 'rr.branch'
    );
    $sql = "SELECT IFNULL(SUM(CAST(rr.processed_tax_amount AS DECIMAL(14,2))), 0) AS t
            FROM sale_refund_requests rr WHERE {$where}";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return '0.00';
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return '0.00';
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return psReportingMoneyFormat($row['t'] ?? 0);
}

/**
 * Drill-down invoice lines for a tax code in range.
 *
 * @return array{ok:bool,rows?:list,total_rows?:int,error?:string}
 */
function psTaxReportingDrilldown(mysqli $conn, array $raw): array
{
    if (!psTaxMayViewReports()) {
        return ['ok' => false, 'error' => 'Not authorised.'];
    }
    if (!psEnsureTaxModule($conn)) {
        return ['ok' => false, 'error' => 'Tax reporting is not available.'];
    }
    $resolved = psTaxReportingResolveFilters($conn, $raw);
    if (!$resolved['ok']) {
        return $resolved;
    }
    $f = $resolved['filters'];
    $where = '1=1 AND ' . psReportingSaleCompletedSql('r') . " AND s.tax_data_status = 'COMPLETE'";
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params, $f['from_date'], $f['to_date'], 'r'
    );
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $f['branches'], !empty($f['all_branches']), 'r.branch'
    );
    if ($f['tax_code'] !== '') {
        $where .= ' AND s.tax_code = ?';
        $types .= 's';
        $params[] = $f['tax_code'];
    }
    if ($f['classification'] !== '') {
        $where .= ' AND s.tax_classification = ?';
        $types .= 's';
        $params[] = $f['classification'];
    }
    require_once __DIR__ . '/fx_report.php';
    $where .= psFxrCurFilterSql($conn, psFxrContext($conn, $raw), 'r.currency');
    $countSql = "SELECT COUNT(*) AS c FROM online_sales s
                 INNER JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
                 WHERE {$where}";
    $countStmt = mysqli_prepare($conn, $countSql);
    $totalRows = 0;
    if ($countStmt) {
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$countStmt, 'bind_param'], $bind);
        }
        if (mysqli_stmt_execute($countStmt)) {
            $cres = mysqli_stmt_get_result($countStmt);
            $crow = $cres ? mysqli_fetch_assoc($cres) : null;
            $totalRows = (int)($crow['c'] ?? 0);
        }
        mysqli_stmt_close($countStmt);
    }
    $offset = ((int)$f['page'] - 1) * (int)$f['page_size'];
    $limit = (int)$f['page_size'];
    $sql = "SELECT s.id, s.invoice_number, s.product_id, s.product, s.quantity, s.branch,
                   s.tax_code, s.tax_classification, s.tax_rate_percent, s.tax_pricing_mode,
                   s.taxable_amount, s.tax_amount, s.line_total_excl, s.line_total_incl, s.revenue,
                   r.date_, r.sold_at, r.currency AS fx_currency, r.fx_rate, r.fx_rate_ids
            FROM online_sales s
            INNER JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
            WHERE {$where}
            ORDER BY COALESCE(DATE(r.sold_at), STR_TO_DATE(r.date_, '%Y-%m-%d')) ASC, s.id ASC
            LIMIT {$limit} OFFSET {$offset}";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Tax drill-down could not be loaded.'];
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Tax drill-down could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'invoice_number' => (string)$row['invoice_number'],
            'product_id' => (string)$row['product_id'],
            'product' => (string)$row['product'],
            'branch' => (string)$row['branch'],
            'quantity' => (string)$row['quantity'],
            'tax_code' => (string)$row['tax_code'],
            'classification' => (string)$row['tax_classification'],
            'rate_percent' => number_format((float)$row['tax_rate_percent'], 4, '.', ''),
            'pricing_mode' => (string)$row['tax_pricing_mode'],
            'taxable_amount' => psReportingMoneyFormat($row['taxable_amount']),
            'tax_amount' => psReportingMoneyFormat($row['tax_amount']),
            'line_total_incl' => psReportingMoneyFormat($row['line_total_incl'] ?? $row['revenue']),
            'date' => (string)($row['sold_at'] ?? $row['date_'] ?? ''),
            'fx_currency' => $row['fx_currency'],
            'fx_rate' => (string)($row['fx_rate'] ?? ''),
            'fx_rate_ids' => (string)($row['fx_rate_ids'] ?? ''),
        ];
    }
    mysqli_stmt_close($stmt);
    return [
        'ok' => true,
        'rows' => $rows,
        'total_rows' => $totalRows,
        'filters' => $f,
    ];
}

/**
 * @return array{ok:bool,headers?:list,lines?:list,filename?:string,error?:string}
 */
function psTaxReportingExportCsv(mysqli $conn, string $kind, array $raw): array
{
    if (!psTaxMayViewReports()) {
        return ['ok' => false, 'error' => 'Not authorised.'];
    }
    if ($kind === 'summary') {
        $rep = psTaxReportingSummary($conn, $raw);
        if (!$rep['ok']) {
            return $rep;
        }
        $ctx = $rep['fx'];
        $headers = [
            'Tax code', 'Classification', 'Rate %', 'Pricing mode', 'Currency', 'Line count',
            'Taxable / class base (original)', 'Tax collected (original)',
            'Reporting currency', 'Taxable / class base (' . $ctx['base'] . ')', 'Tax collected (' . $ctx['base'] . ')', 'Currency status',
        ];
        $lines = [];
        $cur = static function (array $mix, string $code): array {
            return $mix[$code] ?? ['txn' => 0, 'base' => 0, 'n' => 0, 'missing_n' => 0, 'legacy_n' => 0];
        };
        foreach ($rep['rows'] as $row) {
            $codes = array_unique(array_merge(array_keys($row['fx_base']), array_keys($row['fx_tax'])));
            foreach ($codes as $code) {
                $b = $cur($row['fx_base'], $code);
                $t = $cur($row['fx_tax'], $code);
                $status = $code === $ctx['base'] ? 'Base currency' : (($b['missing_n'] ?? 0) > 0 ? 'No stored rate on some lines - left out of the base total' : 'Converted at each sale\'s stored rate');
                $lines[] = [
                    $row['tax_code'], $row['classification'], $row['rate_percent'], $row['pricing_mode'], $code, max($b['n'], $t['n']),
                    psFxrMoney($b['txn']), psFxrMoney($t['txn']), $ctx['base'], psFxrMoney($b['base']), psFxrMoney($t['base']), $status,
                ];
            }
        }
        $t = $rep['totals'];
        foreach (['Taxable base' => 'fx_taxable', 'Tax collected' => 'fx_tax', 'Zero-rated base' => 'fx_zero', 'Exempt turnover' => 'fx_exempt', 'Refund tax (PROCESSED)' => 'fx_refund', 'Net tax' => 'fx_net'] as $label => $key) {
            foreach (psFxrCsvAggRows($ctx, $t[$key] ?? []) as $r) {
                // [Currency, Transactions, Total original, Reporting, Total base, Status]
                $lines[] = ['TOTAL ' . $label, '', '', '', $r[0], $r[1], $r[2], '', $r[3], $r[4], '', $r[5]];
            }
            $lines[] = ['TOTAL ' . $label . ' (consolidated)', '', '', '', '', '', '', '', $ctx['base'], psFxrMoney(psFxrBase($t[$key] ?? [])), '', psFxrIncomplete($t[$key] ?? []) ? 'Incomplete: rate missing' : 'Each sale at its stored rate'];
        }
        $lines[] = ['Legacy incomplete headers', (string)$rep['legacy_count'], '', '', '', '', '', '', '', '', '', ''];
        $lines[] = ['Unconfigured/mixed headers', (string)$rep['unconfigured_count'], '', '', '', '', '', '', '', '', '', ''];
        $lines[] = ['NOTE', $rep['note'] ?? '', '', '', '', '', '', '', '', '', '', ''];
        return [
            'ok' => true,
            'headers' => $headers,
            'lines' => $lines,
            'filename' => 'tax_summary_' . date('Ymd_His') . '.csv',
        ];
    }
    if ($kind === 'drilldown') {
        $raw['page'] = 1;
        $raw['page_size'] = 5000;
        $rep = psTaxReportingDrilldown($conn, $raw);
        if (!$rep['ok']) {
            return $rep;
        }
        $headers = [
            'Invoice', 'Date', 'Branch', 'Product ID', 'Product', 'Qty', 'Tax code',
            'Classification', 'Rate %', 'Mode', 'Currency', 'Taxable', 'Tax', 'Total incl', 'Exchange rate (stored)',
        ];
        $lines = [];
        foreach ($rep['rows'] as $row) {
            $lines[] = [
                $row['invoice_number'], $row['date'], $row['branch'], $row['product_id'], $row['product'],
                $row['quantity'], $row['tax_code'], $row['classification'], $row['rate_percent'],
                $row['pricing_mode'], psFxNormalizeCode($row['fx_currency'] ?? '') ?: (psFxLegacyCurrency() . ' (legacy)'),
                $row['taxable_amount'], $row['tax_amount'], $row['line_total_incl'], psFxrRateText((string)($row['fx_rate'] ?? '')),
            ];
        }
        return [
            'ok' => true,
            'headers' => $headers,
            'lines' => $lines,
            'filename' => 'tax_drilldown_' . date('Ymd_His') . '.csv',
        ];
    }
    return ['ok' => false, 'error' => 'Unknown export kind.'];
}
