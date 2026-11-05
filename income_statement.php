<?php
/**
 * Phase 9.3 — Income statement (Daily Profits).
 *
 * Auth required. Sales revenue/cost scoped to authorised branches
 * (ordinary = working/home only; admin = all registered authorised).
 * Expenses table has no branch column — treated as company-global accounting
 * for authenticated viewers of this report (documented limitation; not invented
 * branch associations). Posted branch/role ignored.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAuthRedirect('login.php');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('income_statement: connection failed');
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');

$scope = psBranchReportScope($conn);
$scopeError = '';
if (empty($scope['ok'])) {
    $scopeError = (string)($scope['error'] ?? 'That branch is not available.');
}

$startDate = '';
$endDate = '';
$totalRevenue = 0;
$totalCost = 0;
$totalRefunds = 0.0;
$totalRefundCost = 0.0;
$totalGrossProfit = 0;
$totalExpenses = 0;
$totalNetProfit = 0;
$error = $scopeError;

// 2026-10-21: per-currency totals, then the consolidated base (each sale / refund / expense at its stored rate).
// A sale or refund with no stored rate is never counted 1:1 in the base: it stays in its own currency and the
// consolidated figure is flagged incomplete. Product costs are kept in the base, so a cost taken from the product
// (older lines that did not store a cost) is a base amount.
require_once __DIR__ . '/includes/fx_report.php';
require_once __DIR__ . '/includes/expense_company.php';
$fx = psFxrContext($conn, $_GET);
$revMix = $costMix = $refMix = $refCostMix = $expMix = [];
$isIn = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$run = isset($isIn['start_date'], $isIn['end_date']);
$fxRun = static function (mysqli $conn, array $fx, string $sql, string $types, array $params, array &$mix): bool {
    $g = psFxrGrouped($conn, $fx, $sql, $types, $params, 0);
    if (empty($g['ok'])) {
        return false;
    }
    psFxrMerge($mix, $g['total']['mix']);
    return true;
};

if ($run && empty($scopeError)) {
    $startDate = trim((string)($isIn['start_date'] ?? ''));
    $endDate = trim((string)($isIn['end_date'] ?? ''));

    if ($startDate !== '' && $endDate !== '') {
        $in = psBranchSqlInList($scope['branches'] ?? []);
        if ($in === null) {
            $error = 'That branch is not available.';
        } else {
            $base = mysqli_real_escape_string($conn, $fx['base']);
            $types = 'ss' . $in['types'];
            $params = array_merge([$startDate, $endDate], $in['params']);
            $curF = psFxrCurFilterSql($conn, $fx, 'r.currency');
            $from = ' FROM online_sales s
                      LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number AND r.branch = s.branch
                      LEFT JOIN goods g ON g.product_id = s.product_id AND g.branch = s.branch
                     WHERE s.date_ BETWEEN ? AND ?
                       AND COALESCE(r.status, \'\') <> \'CANCELLED\'
                       AND s.branch IN (' . $in['sql'] . ')' . $curF;
            // Revenue: each line in its sale currency, base at the sale's stored rate.
            $ok = $fxRun($conn, $fx, 'SELECT ' . psFxrLineAggSql($conn, $fx, 's', 'r', 'COUNT(DISTINCT s.invoice_number)') . $from . ' GROUP BY r.currency', $types, $params, $revMix);
            // Cost of sales: the cost saved on the line (sale currency) or the product's cost (base).
            $lineCost = 'NULLIF(s.cost_price + 0, 0) IS NOT NULL';
            $costCur = "(CASE WHEN $lineCost THEN r.currency ELSE '$base' END)";
            $costAmt = "ROUND(CAST(s.quantity AS DECIMAL(14,3)) * (CASE WHEN $lineCost THEN s.cost_price + 0 ELSE COALESCE(g.cost_price + 0, 0) END), 2)";
            $ok = $ok && $fxRun($conn, $fx, 'SELECT ' . psFxrAggSelectSql($conn, $fx, $costCur, $costAmt, null, "(CASE WHEN $lineCost THEN r.fx_rate ELSE 1 END)") . $from . ' GROUP BY ' . $costCur, $types, $params, $costMix);

            // Processed refunds (original sale currency and rate) and the cost of the refunded goods.
            $hasRfCur = psFxHasColumn($conn, 'sale_refund_requests', 'currency');
            $rfCur = $hasRfCur ? 'COALESCE(q.currency, rr.currency)' : 'rr.currency';
            $rfAmt = 'CAST(q.processed_amount AS DECIMAL(14,2))';
            $rfBase = $hasRfCur && psFxHasColumn($conn, 'sale_refund_requests', 'base_amount')
                ? "(CASE WHEN q.base_amount IS NOT NULL AND q.processed_amount = q.requested_amount THEN q.base_amount ELSE ROUND($rfAmt / NULLIF(rr.fx_rate, 0), 2) END)"
                : "ROUND($rfAmt / NULLIF(rr.fx_rate, 0), 2)";
            $rfWhere = ' FROM sale_refund_requests q
                         LEFT JOIN online_sales_ref rr ON rr.invoice_number = q.invoice_number
                        WHERE q.status = \'PROCESSED\'
                          AND DATE(q.processed_at) BETWEEN ? AND ?
                          AND q.branch IN (' . $in['sql'] . ')' . psFxrCurFilterSql($conn, $fx, $rfCur);
            $ok = $ok && $fxRun($conn, $fx, 'SELECT ' . psFxrAggSelectSql($conn, $fx, $rfCur, $rfAmt, $rfBase, 'rr.fx_rate') . $rfWhere . ' GROUP BY ' . $rfCur, $types, $params, $refMix);
            $rcCur = "(CASE WHEN NULLIF(s.cost_price + 0, 0) IS NOT NULL THEN r2.currency ELSE '$base' END)";
            $rcAmt = 'ROUND(rl.requested_qty * (CASE WHEN NULLIF(s.cost_price + 0, 0) IS NOT NULL THEN s.cost_price + 0 ELSE COALESCE(g.cost_price + 0, 0) END), 2)';
            $ok = $ok && $fxRun($conn, $fx, 'SELECT ' . psFxrAggSelectSql($conn, $fx, $rcCur, $rcAmt, null, '(CASE WHEN NULLIF(s.cost_price + 0, 0) IS NOT NULL THEN r2.fx_rate ELSE 1 END)') . '
                  FROM sale_refund_request_lines rl
                  JOIN sale_refund_requests q ON q.id = rl.request_id
                  JOIN online_sales s ON s.id = rl.sale_line_id
                  LEFT JOIN online_sales_ref r2 ON r2.invoice_number = s.invoice_number
                  LEFT JOIN goods g ON g.product_id = s.product_id AND g.branch = s.branch
                 WHERE q.status = \'PROCESSED\'
                   AND DATE(q.processed_at) BETWEEN ? AND ?
                   AND q.branch IN (' . $in['sql'] . ')' . psFxrCurFilterSql($conn, $fx, 'r2.currency') . '
                 GROUP BY ' . $rcCur, $types, $params, $refCostMix);

            // Expenses: company-wide (no branch column), each in its own currency with its stored base amount.
            $ok = $ok && $fxRun($conn, $fx, 'SELECT ' . psFxrAggSelectSql($conn, $fx, 'currency', 'CAST(amount AS DECIMAL(14,2))', 'base_amount', 'fx_rate') . '
                  FROM expenses WHERE ' . psExpenseCompanySql($conn) . ' AND transaction_date BETWEEN ? AND ?' . psFxrCurFilterSql($conn, $fx, 'currency') . ' GROUP BY currency',
                'ss', [$startDate, $endDate], $expMix);
            if (!$ok) {
                error_log('income_statement: query failed');
                $error = 'Statement could not be generated.';
            }
        }
    } else {
        $error = 'Please provide both start and end dates.';
    }
}
// Statement lines (mixes): revenue after refunds, cost after refunded goods, gross and net profit.
$netRevMix = $revMix;
psFxrMerge($netRevMix, psFxrNegate($refMix));
$netCostMix = $costMix;
psFxrMerge($netCostMix, psFxrNegate($refCostMix));
$grossMix = $netRevMix;
psFxrMerge($grossMix, psFxrNegate($netCostMix));
$netMix = $grossMix;
psFxrMerge($netMix, psFxrNegate($expMix));
$totalRefunds = psFxrBase($refMix) / 100;

$scopeLabel = '';
if (!empty($scope['ok'])) {
    if (!empty($scope['all_authorised'])) {
        $scopeLabel = 'All authorised branches';
    } else {
        $scopeLabel = implode(', ', $scope['branches'] ?? []);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Income Statement</title>
    <script>
        function printPage() {
            window.print();
        }
    </script>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
<div class="container">
    <h2>Income Statement</h2>
    <?php if ($scopeLabel !== ''): ?>
        <p class="scope-note">Sales scope: <?php echo htmlspecialchars($scopeLabel); ?>. Expenses are for the whole company (they are not recorded per branch). Cancelled sales are excluded and processed refunds are deducted.</p>
    <?php endif; ?>

    <form method="POST" action="">
        <label for="start_date">Start Date:</label>
        <input type="date" name="start_date" id="start_date" required value="<?php echo htmlspecialchars($startDate); ?>">

        <label for="end_date">End Date:</label>
        <input type="date" name="end_date" id="end_date" required value="<?php echo htmlspecialchars($endDate); ?>">

        <button type="submit">Generate Statement</button>
    </form>

    <div class="error">
        <?php if ($error !== '') echo htmlspecialchars($error); ?>
    </div>

    <div class="results">
        <?php if ($run && $startDate !== '' && $endDate !== '' && ($error === '' || $error === $scopeError) && empty($scopeError)): ?>
            <h3>Results from <?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></h3>
            <?php echo psFxrToolbar($fx, ['start_date' => $startDate, 'end_date' => $endDate]); ?>
            <?php $m = fn(array $x, array $o = []) => psFxrAmount($conn, $fx, $x, $o + ['empty' => psFxFormat($conn, 0, $fx['base'])]); ?>
            <div class="summary">
                <p><strong>Total Revenue (after refunds): </strong> <?php echo $m($netRevMix); ?><?php if ($refMix): ?> <span class="ps-muted">(refunds <?php echo $m($refMix); ?>)</span><?php endif; ?></p>
                <p><strong>Total Cost of Sales: </strong> <?php echo $m($netCostMix); ?></p>
                <p><strong>Gross Profit: </strong> <?php echo $m($grossMix); ?></p>
                <p><strong>Total Expenses: </strong> <?php echo $m($expMix); ?></p>
                <p><strong>Net Profit: </strong> <?php echo $m($netMix, ['strong' => true]); ?></p>
            </div>
            <?php if ($fx['view'] === PS_FXR_BREAKDOWN): ?><p class="fxr-label">By currency: each line is shown per currency without conversion. Product costs are kept in <?php echo htmlspecialchars($fx['base']); ?>, so a cost of older sales appears in <?php echo htmlspecialchars($fx['base']); ?>.</p><?php endif; ?>
            <?php echo psFxrSummary($conn, $fx, $revMix, 'Sales revenue by currency (before refunds)'); ?>
            <?php echo psFxrSummary($conn, $fx, $expMix, 'Expenses by currency'); ?>
            <button class="print-button" onclick="printPage()">Print Statement</button>
        <?php endif; ?>
    </div>

    <a class="back-button" href="admin_dashboard.php">Back to Dashboard</a>
</div>

<?php ps_close(); $conn->close(); ?>
</body>
</html>
