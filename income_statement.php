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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($scopeError)) {
    $startDate = trim((string)($_POST['start_date'] ?? ''));
    $endDate = trim((string)($_POST['end_date'] ?? ''));

    if ($startDate !== '' && $endDate !== '') {
        $in = psBranchSqlInList($scope['branches'] ?? []);
        if ($in === null) {
            $error = 'That branch is not available.';
        } else {
            // Health-check 2026-10-13: exclude cancelled sales; unit cost = cost saved on the
            // sale line, else the product's current cost price (older lines never stored cost).
            // Multi-currency (2026-10-20): everything is brought into the company base. Sale lines (and the cost saved on
            // them) use their sale's rate; the product's current cost is already in the base.
            require_once __DIR__ . '/includes/currency.php';
            $fxOn = psFxHasColumn($conn, 'online_sales_ref', 'fx_rate');
            psFxBase($conn, psFxSessionCompany()); // amounts below are shown in this base
            $rate = $fxOn ? 'COALESCE(NULLIF(r.fx_rate, 0), 1)' : '1';
            $sql = 'SELECT s.revenue / ' . $rate . ' AS revenue, s.quantity,
                           CASE WHEN NULLIF(s.cost_price + 0, 0) IS NOT NULL THEN (s.cost_price + 0) / ' . $rate . '
                                ELSE COALESCE(g.cost_price + 0, 0) END AS cost_price
                      FROM online_sales s
                      LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number AND r.branch = s.branch
                      LEFT JOIN goods g ON g.product_id = s.product_id AND g.branch = s.branch
                     WHERE s.date_ BETWEEN ? AND ?
                       AND COALESCE(r.status, \'\') <> \'CANCELLED\'
                       AND s.branch IN (' . $in['sql'] . ')';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('income_statement: sales prepare failed');
                $error = 'Statement could not be generated.';
            } else {
                $types = 'ss' . $in['types'];
                $params = array_merge([$startDate, $endDate], $in['params']);
                $bind = [$types];
                foreach ($params as $k => $v) {
                    $bind[] = &$params[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
                if (!$stmt->execute()) {
                    error_log('income_statement: sales execute failed');
                    $error = 'Statement could not be generated.';
                } else {
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $revenue = (float)$row['revenue'];
                        $quantity = (float)$row['quantity'];
                        $cost_price = (float)$row['cost_price'];
                        $totalRevenue += $revenue;
                        $totalCost += $cost_price * $quantity;
                    }
                    // Processed refunds reduce revenue; refunded goods' cost comes off cost of sales.
                    $rrate = $fxOn ? 'COALESCE(NULLIF(rr.fx_rate, 0), 1)' : '1';
                    $rsql = 'SELECT COALESCE(SUM(q.processed_amount / ' . $rrate . '), 0) AS refunded,
                                    COALESCE(SUM(lc.refund_cost), 0) AS refunded_cost
                               FROM sale_refund_requests q
                               LEFT JOIN online_sales_ref rr ON rr.invoice_number = q.invoice_number
                               LEFT JOIN (
                                    SELECT rl.request_id,
                                           SUM(rl.requested_qty * CASE WHEN NULLIF(s.cost_price + 0, 0) IS NOT NULL
                                               THEN (s.cost_price + 0) / ' . ($fxOn ? 'COALESCE(NULLIF(r2.fx_rate, 0), 1)' : '1') . '
                                               ELSE COALESCE(g.cost_price + 0, 0) END) AS refund_cost
                                      FROM sale_refund_request_lines rl
                                      JOIN online_sales s ON s.id = rl.sale_line_id
                                      LEFT JOIN online_sales_ref r2 ON r2.invoice_number = s.invoice_number
                                      LEFT JOIN goods g ON g.product_id = s.product_id AND g.branch = s.branch
                                     GROUP BY rl.request_id
                               ) lc ON lc.request_id = q.id
                              WHERE q.status = \'PROCESSED\'
                                AND DATE(q.processed_at) BETWEEN ? AND ?
                                AND q.branch IN (' . $in['sql'] . ')';
                    $rstmt = $conn->prepare($rsql);
                    if ($rstmt) {
                        $rbind = [$types];
                        $rparams = $params;
                        foreach ($rparams as $k => $v) {
                            $rbind[] = &$rparams[$k];
                        }
                        call_user_func_array([$rstmt, 'bind_param'], $rbind);
                        if ($rstmt->execute()) {
                            $rrow = $rstmt->get_result()->fetch_assoc();
                            $totalRefunds = (float)($rrow['refunded'] ?? 0);
                            $totalRefundCost = (float)($rrow['refunded_cost'] ?? 0);
                        }
                        $rstmt->close();
                    }
                    $totalRevenue -= $totalRefunds;
                    $totalCost -= $totalRefundCost;
                    $totalGrossProfit = $totalRevenue - $totalCost;
                }
                $stmt->close();
            }

            // Global expenses (no branch column on expenses — company-wide).
            if ($error === '' || $error === $scopeError) {
                $stmt = $conn->prepare(
                    'SELECT SUM(' . (psFxHasColumn($conn, 'expenses', 'base_amount') ? 'COALESCE(base_amount, amount + 0)' : 'amount') . ') AS total_expenses FROM expenses
                     WHERE transaction_date BETWEEN ? AND ?'
                );
                if (!$stmt) {
                    error_log('income_statement: expenses prepare failed');
                    $error = 'Statement could not be generated.';
                } else {
                    $stmt->bind_param('ss', $startDate, $endDate);
                    if (!$stmt->execute()) {
                        error_log('income_statement: expenses execute failed');
                        $error = 'Statement could not be generated.';
                    } else {
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $totalExpenses = $row['total_expenses'] ? (float)$row['total_expenses'] : 0;
                        }
                        $totalNetProfit = $totalGrossProfit - $totalExpenses;
                    }
                    $stmt->close();
                }
            }
        }
    } else {
        $error = 'Please provide both start and end dates.';
    }
}

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
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $startDate !== '' && $endDate !== '' && ($error === '' || $error === $scopeError) && empty($scopeError)): ?>
            <h3>Results from <?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></h3>
            <div class="summary">
                <p><strong>Total Revenue (after refunds): </strong> <?php echo ps_money($totalRevenue); ?><?php if ($totalRefunds > 0): ?> <span class="ps-muted">(refunds <?php echo ps_money($totalRefunds); ?>)</span><?php endif; ?></p>
                <p><strong>Total Cost of Sales: </strong> <?php echo ps_money($totalCost); ?></p>
                <p><strong>Gross Profit: </strong> <?php echo ps_money($totalGrossProfit); ?></p>
                <p><strong>Total Expenses: </strong> <?php echo ps_money($totalExpenses); ?></p>
                <p><strong>Net Profit: </strong> <?php echo ps_money($totalNetProfit); ?></p>
            </div>
            <button class="print-button" onclick="printPage()">Print Statement</button>
        <?php endif; ?>
    </div>

    <a class="back-button" href="admin_dashboard.php">Back to Dashboard</a>
</div>

<?php
$conn->close();
?>

<?php ps_close(); ?>
</body>
</html>
