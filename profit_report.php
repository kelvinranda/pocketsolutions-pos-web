<?php
/**
 * 2026-10-17 — Profit & Margin report (Management, Admin).
 *
 * Gross profit = net sales − cost of sales, per day / product / category / branch.
 *  - Net sales are VAT-exclusive where the line carries tax data (line_total_excl, tax_data_status
 *    COMPLETE); older lines without tax data use the recorded revenue.
 *  - Unit cost = cost saved on the sale line, else the product's current cost price; lines with
 *    neither are counted as "cost unknown" (their profit is overstated, so they are flagged).
 *  - Cancelled sales are left out; processed refunds are subtracted on the day they were processed.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/reporting.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/report_export.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
psReportingRequireAuthRedirect();
if (function_exists('psReportingEnsureTimezone')) {
    psReportingEnsureTimezone();
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$validDate = function (string $d): bool {
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t !== false && $t->format('Y-m-d') === $d;
};

$view = strtolower(trim((string)($_GET['view'] ?? 'day')));
$views = ['day' => 'By day', 'product' => 'By product', 'category' => 'By category', 'branch' => 'By branch'];
if (!isset($views[$view])) {
    $view = 'day';
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
if (!$validDate($fromDate)) {
    $fromDate = date('Y-m-d'); // default: today; other ranges via the filter
}
if (!$validDate($toDate)) {
    $toDate = date('Y-m-d');
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$export = strtolower(trim((string)($_GET['export'] ?? '')));
require_once __DIR__ . '/includes/fx_report.php';
$fx = psFxrContext($conn, $_GET);

$error = '';
$scope = psBranchReportScope($conn);
$branches = [];
if (empty($scope['ok'])) {
    $error = (string)($scope['error'] ?? 'That branch is not available.');
} else {
    $branches = $scope['branches'];
    if ($branchFilter !== '') {
        if (in_array($branchFilter, $branches, true)) {
            $branches = [$branchFilter];
        } else {
            $branchFilter = '';
        }
    }
}
$branchOptions = empty($scope['ok']) ? [] : $scope['branches'];

/** Bind helper for mysqli prepared statements with a dynamic list. */
$run = function (string $sql, string $types, array $params) use ($conn): ?array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('profit_report: prepare failed: ' . $conn->error);
        return null;
    }
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!$stmt->execute()) {
        error_log('profit_report: execute failed');
        $stmt->close();
        return null;
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};

$groups = [];   // key => [label, extra, qty, sales, cost, unknown]
$days = [];     // Y-m-d => [sales, cost]
$tot = ['qty' => 0.0, 'sales' => 0.0, 'cost' => 0.0, 'unknown' => 0, 'refund_sales' => 0.0, 'refund_cost' => 0.0, 'lines' => 0,
    'sm' => [], 'cm' => [], 'rsm' => [], 'rcm' => []];

$keyFor = function (array $r) use ($view): array {
    switch ($view) {
        case 'product':
            return [$r['branch'] . "\x1f" . $r['product_id'], (string)$r['product'], (string)$r['product_id'] . ' · ' . $r['branch']];
        case 'category':
            $c = trim((string)$r['cat']);
            return [$c === '' ? '(none)' : strtolower($c), $c === '' ? '(No category)' : $c, ''];
        case 'branch':
            return [(string)$r['branch'], (string)$r['branch'], ''];
        default:
            return [(string)$r['d'], (string)$r['d'], ''];
    }
};
// 2026-10-21 multi-currency: every line keeps its sale currency. Sales and the cost saved on the line are in the
// sale currency (base via the sale's stored rate); a cost taken from the product is already in the base.
// The float fields below are the consolidated base (resolvable lines only); 'sm' / 'cm' are the per-currency mixes.
$toBase = function (?string $cur, float $amt, $rate) use ($fx): ?float {
    $code = psFxNormalizeCode($cur ?? '');
    if ($code === '' || $code === $fx['base']) {
        return $amt;
    }
    $r = (float)$rate;
    return $r > 0 ? round($amt / $r, 2) : null;
};
$add = function (array $r, float $sign) use (&$groups, &$days, &$tot, $keyFor, $toBase, $fx) {
    $qty = (float)$r['qty'];
    $salesTxn = $sign * (float)$r['sales_txn'];
    $lineCost = (int)$r['line_cost'] === 1;
    $costCur = $lineCost ? $r['cur'] : $fx['base'];
    $costTxn = $sign * (float)$r['unit_cost_txn'] * $qty;
    $salesB = $toBase($r['cur'], $salesTxn, $r['rate']);
    $costB = $lineCost ? $toBase($r['cur'], $costTxn, $r['rate']) : $costTxn;
    $sales = $sign * ($salesB ?? 0.0);
    $cost = $sign * ($costB ?? 0.0);
    $mm = fn(float $v) => number_format($v, 2, '.', '');
    [$k, $label, $extra] = $keyFor($r);
    if (!isset($groups[$k])) {
        $groups[$k] = ['label' => $label, 'extra' => $extra, 'qty' => 0.0, 'sales' => 0.0, 'cost' => 0.0, 'unknown' => 0, 'sm' => [], 'cm' => []];
    }
    $meta = ['rate' => (string)$r['rate']];
    psFxrAdd($groups[$k]['sm'], $fx, $r['cur'], $mm($salesTxn), $salesB === null ? null : $mm($salesB), $meta);
    psFxrAdd($groups[$k]['cm'], $fx, $costCur, $mm($costTxn), $costB === null ? null : $mm($costB), $lineCost ? $meta : []);
    psFxrAdd($tot['sm'], $fx, $r['cur'], $mm($salesTxn), $salesB === null ? null : $mm($salesB), $meta);
    psFxrAdd($tot['cm'], $fx, $costCur, $mm($costTxn), $costB === null ? null : $mm($costB), $lineCost ? $meta : []);
    if ($sign < 0) {
        psFxrAdd($tot['rsm'], $fx, $r['cur'], $mm(-$salesTxn), $salesB === null ? null : $mm(-$salesB), $meta);
        psFxrAdd($tot['rcm'], $fx, $costCur, $mm(-$costTxn), $costB === null ? null : $mm(-$costB), $lineCost ? $meta : []);
    }
    $groups[$k]['qty'] += $sign * $qty;
    $groups[$k]['sales'] += $sign * $sales;
    $groups[$k]['cost'] += $sign * $cost;
    $d = (string)$r['d'];
    if (!isset($days[$d])) {
        $days[$d] = ['sales' => 0.0, 'cost' => 0.0];
    }
    $days[$d]['sales'] += $sign * $sales;
    $days[$d]['cost'] += $sign * $cost;
    $tot['qty'] += $sign * $qty;
    $tot['sales'] += $sign * $sales;
    $tot['cost'] += $sign * $cost;
    if ($sign > 0) {
        $tot['lines']++;
        if ((float)$r['unit_cost_txn'] <= 0) {
            $groups[$k]['unknown']++;
            $tot['unknown']++;
        }
    } else {
        $tot['refund_sales'] += $sales;
        $tot['refund_cost'] += $cost;
    }
};

if ($error === '') {
    $in = psBranchSqlInList($branches);
    if ($in === null) {
        $error = 'That branch is not available.';
    } else {
        // Line amounts in the sale currency; currency + stored rate returned so each line is converted at its own rate.
        $exclSql = "(CASE WHEN s.tax_data_status = 'COMPLETE' AND s.line_total_excl IS NOT NULL THEN s.line_total_excl + 0 ELSE s.revenue + 0 END)";
        $costSql = "CASE WHEN NULLIF(s.cost_price + 0, 0) IS NOT NULL THEN s.cost_price + 0 ELSE COALESCE(g.cost_price + 0, 0) END";
        $fxCols = "r.currency AS cur, r.fx_rate AS rate, (NULLIF(s.cost_price + 0, 0) IS NOT NULL) AS line_cost";
        $curF = psFxrCurFilterSql($conn, $fx, 'r.currency');
        $types = 'ss' . $in['types'];
        $params = array_merge([$fromDate, $toDate], $in['params']);

        $sales = $run(
            "SELECT DATE(s.date_) AS d, s.product_id, s.product, COALESCE(g.category, '') AS cat, s.branch,
                    s.quantity + 0 AS qty, $exclSql AS sales_txn, $costSql AS unit_cost_txn, $fxCols
               FROM online_sales s
               LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number AND r.branch = s.branch
               LEFT JOIN goods g ON g.product_id = s.product_id AND g.branch = s.branch
              WHERE DATE(s.date_) BETWEEN ? AND ?
                AND COALESCE(r.status, '') <> 'CANCELLED'
                AND s.branch IN (" . $in['sql'] . ')' . $curF,
            $types,
            $params
        );
        // Refunded quantities, priced at the sale line's own unit values, on the day processed.
        $refunds = $run(
            "SELECT DATE(q.processed_at) AS d, s.product_id, s.product, COALESCE(g.category, '') AS cat, s.branch,
                    rl.requested_qty + 0 AS qty,
                    CASE WHEN (s.quantity + 0) <> 0 THEN ($exclSql) / (s.quantity + 0) * (rl.requested_qty + 0) ELSE 0 END AS sales_txn,
                    $costSql AS unit_cost_txn, $fxCols
               FROM sale_refund_requests q
               JOIN sale_refund_request_lines rl ON rl.request_id = q.id
               JOIN online_sales s ON s.id = rl.sale_line_id
               LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number AND r.branch = s.branch
               LEFT JOIN goods g ON g.product_id = s.product_id AND g.branch = s.branch
              WHERE q.status = 'PROCESSED'
                AND DATE(q.processed_at) BETWEEN ? AND ?
                AND q.branch IN (" . $in['sql'] . ')' . $curF,
            $types,
            $params
        );
        if ($sales === null) {
            $error = 'The report could not be generated.';
        } else {
            foreach ($sales as $r) {
                $add($r, 1.0);
            }
            foreach ($refunds ?? [] as $r) {
                $add($r, -1.0);
            }
        }
    }
}
$profit = fn(array $g) => $g['sales'] - $g['cost'];
$profitMix = function (array $g): array {
    $m = $g['sm'];
    psFxrMerge($m, psFxrNegate($g['cm']));
    return $m;
};
$M = fn(array $mix, array $o = []) => psFxrAmount($conn, $fx, $mix, $o);
$margin = fn(array $g) => abs($g['sales']) > 0.00001 ? ($g['sales'] - $g['cost']) / $g['sales'] * 100 : null;
$pct = fn(?float $m) => $m === null ? '—' : number_format($m, 1) . '%';
$num = fn(float $v) => number_format($v, 2, '.', '');
$qtyText = fn(float $v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');

// Sort: days ascending, others by profit descending.
if ($view === 'day') {
    ksort($groups);
} else {
    uasort($groups, fn($a, $b) => ($b['sales'] - $b['cost']) <=> ($a['sales'] - $a['cost']));
}
ksort($days);

$scopeLabel = $branchFilter !== '' ? $branchFilter : (count($branchOptions) > 1 ? 'All authorised branches' : implode(', ', $branchOptions));
$subtitle = $scopeLabel . ' · ' . $fromDate . ' to ' . $toDate . ' · ' . $views[$view];
$exportQuery = array_filter(['view' => $view, 'from_date' => $fromDate, 'to_date' => $toDate, 'branch' => $branchFilter], fn($v) => $v !== '') + psFxrQuery($fx);

$firstCol = ['day' => 'Date', 'product' => 'Product', 'category' => 'Category', 'branch' => 'Branch'][$view];

if ($error === '' && in_array($export, psReportExportFormats(), true)) {
    $T = fn(array $mix) => psFxrText($conn, $fx, $mix);
    $sumRows = [
        ['Reporting currency', $fx['view'] === PS_FXR_BREAKDOWN ? 'Original currencies (no conversion)' : $fx['base'] . ' (each sale at its stored rate)'],
        ['Net sales (excl. VAT, after refunds)', $T($tot['sm'])],
        ['Cost of sales', $T($tot['cm'])],
        ['Gross profit', $T($profitMix($tot))],
        ['Margin', $pct($margin($tot))],
        ['Refunds deducted (sales / cost)', $num($tot['refund_sales']) . ' / ' . $num($tot['refund_cost'])],
        ['Sale lines with unknown cost', (string)$tot['unknown']],
    ];
    $cols = [$firstCol];
    if ($view === 'product') {
        $cols[] = 'Product ID · Branch';
    }
    $cols = array_merge($cols, ['Qty', 'Net sales', 'Cost of sales', 'Gross profit', 'Margin %', 'Unknown cost lines']);
    $rows = [];
    foreach ($groups as $g) {
        $row = [$g['label']];
        if ($view === 'product') {
            $row[] = $g['extra'];
        }
        $rows[] = array_merge($row, [$qtyText($g['qty']), $T($g['sm']), $T($g['cm']), $T($profitMix($g)), $margin($g) === null ? '' : number_format($margin($g), 1), (string)$g['unknown']]);
    }
    $curRows = psFxrCsvAggRows($fx, $tot['sm']);
    psReportExportSend($export, 'Profit & Margin', $subtitle, [
        ['heading' => 'Summary', 'columns' => ['Measure', 'Value'], 'rows' => $sumRows,
         'note' => 'Sales are VAT-exclusive where tax data exists. Cancelled sales are left out; processed refunds are deducted.'],
        ['heading' => $views[$view], 'columns' => $cols, 'rows' => $rows, 'empty' => 'No sales in this period.'],
        ['heading' => 'Net sales by currency', 'columns' => psFxrCsvAggHeaders($fx), 'rows' => $curRows,
         'note' => 'Each sale line is converted at the rate stored with its sale. Margin % is on the ' . $fx['base'] . ' figures.'],
    ], 'profit_margin_' . $view . '_' . $fromDate . '_' . $toDate);
}

// Trend chart (SVG): daily gross profit bars + margin line.
$chart = '';
if ($days) {
    $w = 760;
    $hgt = 220;
    $padL = 56;
    $padR = 44;
    $padT = 14;
    $padB = 28;
    $n = count($days);
    $vals = array_map(fn($d) => $d['sales'] - $d['cost'], $days);
    $maxV = max(0.0, max($vals));
    $minV = min(0.0, min($vals));
    if ($maxV - $minV < 0.01) {
        $maxV = $minV + 1;
    }
    $iw = $w - $padL - $padR;
    $ih = $hgt - $padT - $padB;
    $y = fn(float $v) => $padT + ($maxV - $v) / ($maxV - $minV) * $ih;
    $slot = $iw / $n;
    $bw = max(2.0, min(28.0, $slot * 0.7));
    $parts = [];
    $parts[] = '<line x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . round($y(0), 1) . '" y2="' . round($y(0), 1) . '" class="pr-axis"/>';
    foreach ([$maxV, $minV < 0 ? $minV : ($maxV / 2)] as $tick) {
        $parts[] = '<text x="' . ($padL - 6) . '" y="' . round($y($tick) + 4, 1) . '" text-anchor="end" class="pr-tick">' . $h(number_format($tick, 0)) . '</text>';
    }
    $line = [];
    $i = 0;
    $labelEvery = max(1, (int)ceil($n / 10));
    foreach ($days as $d => $dv) {
        $p = $dv['sales'] - $dv['cost'];
        $cx = $padL + $slot * ($i + 0.5);
        $top = min($y($p), $y(0));
        $bh = abs($y($p) - $y(0));
        $m = abs($dv['sales']) > 0.00001 ? $p / $dv['sales'] * 100 : null;
        $parts[] = '<rect x="' . round($cx - $bw / 2, 1) . '" y="' . round($top, 1) . '" width="' . round($bw, 1) . '" height="' . round(max(0.5, $bh), 1) . '" class="' . ($p < 0 ? 'pr-neg' : 'pr-bar') . '"><title>'
            . $h($d . ': profit ' . number_format($p, 2) . ', sales ' . number_format($dv['sales'], 2) . ', margin ' . $pct($m)) . '</title></rect>';
        if ($m !== null) {
            $my = $padT + (100 - max(-100, min(100, $m))) / 200 * $ih;
            $line[] = round($cx, 1) . ',' . round($my, 1);
        }
        if ($i % $labelEvery === 0) {
            $parts[] = '<text x="' . round($cx, 1) . '" y="' . ($hgt - 8) . '" text-anchor="middle" class="pr-tick">' . $h(substr($d, 5)) . '</text>';
        }
        $i++;
    }
    if (count($line) > 1) {
        $parts[] = '<polyline points="' . implode(' ', $line) . '" class="pr-line"/>';
    }
    $parts[] = '<text x="' . ($w - $padR + 6) . '" y="' . ($padT + 4) . '" class="pr-tick">100%</text>';
    $parts[] = '<text x="' . ($w - $padR + 6) . '" y="' . round($padT + $ih / 2 + 4, 1) . '" class="pr-tick">0%</text>';
    $chart = '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Daily gross profit and margin" style="width:100%;height:auto;max-width:' . $w . 'px">' . implode('', $parts) . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit &amp; Margin</title>
<?php ps_head(); ?>
    <style>
        .pr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0}
        .pr-kpis div{border:1px solid rgba(0,0,0,.12);border-radius:10px;padding:12px 14px}
        .pr-kpis b{display:block;font-size:1.35em}
        .pr-kpis span{font-size:.85em;opacity:.75}
        .pr-bar{fill:#178a43}.pr-neg{fill:#c2410c}.pr-line{fill:none;stroke:#f5b400;stroke-width:2}
        .pr-axis{stroke:#888;stroke-width:1}.pr-tick{font-size:11px;fill:#666}
        .pr-legend{font-size:.85em;opacity:.8;margin:4px 0 12px}
        .pr-legend i{display:inline-block;width:10px;height:10px;margin:0 4px 0 10px;border-radius:2px}
        td.num,th.num{text-align:right;white-space:nowrap}
        tr.neg td{color:#c2410c}
        @media print{form,.tabs,.no-print{display:none!important}}
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Profit &amp; Margin</h1>
    <p class="hint">Gross profit = net sales − cost of sales. Sales are VAT-exclusive where tax data exists; cancelled sales are left out and processed refunds are deducted.</p>

    <div class="tabs">
        <?php foreach ($views as $k => $label): ?>
            <a class="<?php echo $view === $k ? 'on' : ''; ?>" href="profit_report.php?<?php echo $h(http_build_query(['view' => $k] + $exportQuery)); ?>"><?php echo $h($label); ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="profit_report.php" style="margin-top:16px;">
        <input type="hidden" name="view" value="<?php echo $h($view); ?>">
        <label>From <input type="date" name="from_date" value="<?php echo $h($fromDate); ?>"></label>
        <label>To <input type="date" name="to_date" value="<?php echo $h($toDate); ?>"></label>
        <?php if (count($branchOptions) > 1): ?>
            <label>Branch
                <select name="branch">
                    <option value="">All authorised</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo $h($bn); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>><?php echo $h($bn); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button type="submit">Apply</button>
    </form>
    <p class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0">
        <?php
        $quick = [
            'Today' => [date('Y-m-d'), date('Y-m-d')],
            'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'This week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
            'This month' => [date('Y-m-01'), date('Y-m-d')],
            'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
        ];
        foreach ($quick as $label => [$qf, $qt]):
            $on = $qf === $fromDate && $qt === $toDate;
            ?>
            <a class="ps-btn ps-btn-sm <?php echo $on ? '' : 'ps-btn-outline'; ?>" href="profit_report.php?<?php echo $h(http_build_query(array_filter(['view' => $view, 'from_date' => $qf, 'to_date' => $qt, 'branch' => $branchFilter], fn($v) => $v !== ''))); ?>"><?php echo $h($label); ?></a>
        <?php endforeach; ?>
    </p>

    <?php if ($error !== ''): ?>
        <p class="error"><?php echo $h($error); ?></p>
    <?php else: ?>
        <?php echo psReportExportToolbar($exportQuery); ?>
        <?php echo psFxrToolbar($fx, array_filter(['view' => $view, 'from_date' => $fromDate, 'to_date' => $toDate, 'branch' => $branchFilter], fn($v) => $v !== '')); ?>
        <p class="hint"><?php echo $h($subtitle); ?></p>
        <div class="pr-kpis">
            <div><span>Net sales</span><b><?php echo $M($tot['sm']); ?></b></div>
            <div><span>Cost of sales</span><b><?php echo $M($tot['cm']); ?></b></div>
            <div><span>Gross profit</span><b><?php echo $M($profitMix($tot)); ?></b></div>
            <div><span>Margin (on <?php echo $h($fx['base']); ?>)</span><b><?php echo $h($pct($margin($tot))); ?></b></div>
        </div>
        <?php if ($tot['refund_sales'] != 0.0): ?>
            <p class="hint">Refunds deducted: <?php echo $M($tot['rsm']); ?> sales, <?php echo $M($tot['rcm']); ?> cost.</p>
        <?php endif; ?>
        <?php if ($tot['unknown'] > 0): ?>
            <p class="error"><?php echo (int)$tot['unknown']; ?> sale line(s) have no cost price, so their profit shows as the full sale. Set cost prices on those items for an accurate margin.</p>
        <?php endif; ?>

        <?php if ($chart !== ''): ?>
            <h2>Daily trend (in <?php echo $h($fx['base']); ?>)</h2>
            <?php echo $chart; ?>
            <p class="pr-legend"><i style="background:#178a43"></i>Gross profit <i style="background:#c2410c"></i>Loss <i style="background:#f5b400"></i>Margin %</p>
        <?php endif; ?>

        <h2><?php echo $h($views[$view]); ?></h2>
        <?php if (!$groups): ?>
            <p>No sales in this period.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table>
                <thead><tr>
                    <th><?php echo $h($firstCol); ?></th>
                    <?php if ($view === 'product'): ?><th>Product ID · Branch</th><?php endif; ?>
                    <th class="num">Qty</th><th class="num">Net sales</th><th class="num">Cost of sales</th><th class="num">Gross profit</th><th class="num">Margin</th>
                </tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr class="<?php echo $profit($g) < 0 ? 'neg' : ''; ?>">
                        <td><?php echo $h($g['label']); ?><?php if ($g['unknown'] > 0): ?> <span class="ps-muted" title="Lines without a cost price">(<?php echo (int)$g['unknown']; ?> no cost)</span><?php endif; ?></td>
                        <?php if ($view === 'product'): ?><td><?php echo $h($g['extra']); ?></td><?php endif; ?>
                        <td class="num"><?php echo $h($qtyText($g['qty'])); ?></td>
                        <td class="num"><?php echo $M($g['sm']); ?></td>
                        <td class="num"><?php echo $M($g['cm']); ?></td>
                        <td class="num"><?php echo $M($profitMix($g)); ?></td>
                        <td class="num"><?php echo $h($pct($margin($g))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th>Total</th><?php if ($view === 'product'): ?><th></th><?php endif; ?>
                    <th class="num"><?php echo $h($qtyText($tot['qty'])); ?></th>
                    <th class="num"><?php echo $M($tot['sm'], ['strong' => true]); ?></th>
                    <th class="num"><?php echo $M($tot['cm'], ['strong' => true]); ?></th>
                    <th class="num"><?php echo $M($profitMix($tot), ['strong' => true]); ?></th>
                    <th class="num"><?php echo $h($pct($margin($tot))); ?></th>
                </tr></tfoot>
            </table>
            </div>
            <?php echo psFxrSummary($conn, $fx, $tot['sm'], 'Net sales by currency (sale lines, after refunds)'); ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
