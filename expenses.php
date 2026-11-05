<?php
/**
 * Phase 9.3 — Expenses list.
 *
 * Auth required. The expenses table has no branch column; records are
 * company-global accounting (not invented branch associations). Access is
 * therefore session-gated, not branch-filtered. Posted role/branch ignored.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAuthRedirect('login.php');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    error_log('expenses: connection failed');
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
// Multi-currency (2026-10-20): amounts show in their own currency (Funding Account) plus the base equivalent.
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/expense_company.php'; // 2026-11-02: this company's expenses only
$expCo = psExpenseCompanySql($conn);
psFxEnsureTransactionColumns($conn);
$expBaseCol = psFxHasColumn($conn, 'expenses', 'base_amount') ? ', base_amount, currency, fx_rate, fx_rate_ids' : ', NULL AS base_amount, NULL AS currency, NULL AS fx_rate, NULL AS fx_rate_ids';
$expBaseCur = psFxBase($conn, psFxSessionCompany());

$expense_id = $expense_name = $month = $year = '';
$expenses_data = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $expense_id = trim((string)($_POST['expense_id'] ?? ''));
    $expense_name = trim((string)($_POST['expense_name'] ?? ''));
    $month = trim((string)($_POST['month'] ?? ''));
    $year = trim((string)($_POST['year'] ?? ''));

    $sql = 'SELECT expense_name, funding_account, narration, amount, transaction_date' . $expBaseCol . '
            FROM expenses WHERE ' . $expCo;
    $types = '';
    $params = [];

    if ($expense_id !== '') {
        $sql .= ' AND expense_id LIKE ?';
        $types .= 's';
        $params[] = '%' . $expense_id . '%';
    }
    if ($expense_name !== '') {
        $sql .= ' AND expense_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $expense_name . '%';
    }
    if ($month !== '') {
        $sql .= ' AND month = ?';
        $types .= 's';
        $params[] = $month;
    }
    if ($year !== '') {
        $sql .= ' AND year = ?';
        $types .= 's';
        $params[] = $year;
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('expenses: prepare failed');
        echo 'Expenses could not be loaded.';
        mysqli_close($conn);
        exit;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log('expenses: execute failed');
        echo 'Expenses could not be loaded.';
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $expenses_data[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
} else {
    $stmt = mysqli_prepare($conn, 'SELECT expense_name, funding_account, narration, amount, transaction_date' . $expBaseCol . ' FROM expenses WHERE ' . $expCo);
    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        error_log('expenses: list failed');
        echo 'Expenses could not be loaded.';
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }
        mysqli_close($conn);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $expenses_data[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// 2026-10-21: each expense in its own currency; base column = stored base amount; totals per currency, then base.
require_once __DIR__ . '/includes/fx_report.php';
$fx = psFxrContext($conn, $_GET);
$expensesData = array_values(array_filter($expenses_data, fn($e) => psFxrRowMatches($fx, $e['currency'] ?? null)));
$expMix = [];
foreach ($expensesData as $e) {
    psFxrAdd($expMix, $fx, $e['currency'] ?? null, $e['amount'], $e['base_amount'] ?? null, ['rate' => (string)($e['fx_rate'] ?? '')]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Expenses</h1>
    <p class="scope-note">Expenses are company-global (no branch column on expenses). Authentication required.</p>

    <form method="POST" action="">
        <input type="text" name="expense_id" placeholder="Expense ID" value="<?php echo htmlspecialchars($expense_id); ?>">
        <input type="text" name="expense_name" placeholder="Expense Name" value="<?php echo htmlspecialchars($expense_name); ?>">
        <input type="text" name="month" placeholder="Month" value="<?php echo htmlspecialchars($month); ?>">
        <input type="text" name="year" placeholder="Year" value="<?php echo htmlspecialchars($year); ?>">
        <button type="submit">Search</button>
    </form>

    <button class="new-expense-button" onclick="window.location.href='new_expense.php'">New Expense</button>
    <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>

    <table>
        <thead>
            <tr>
                <th>Expense Name</th>
                <th>Funding Account</th>
                <th>Narration</th>
                <th>Amount</th>
                <th>In <?php echo htmlspecialchars($expBaseCur); ?></th>
                <th>Transaction Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($expensesData)): ?>
                <tr>
                    <td colspan="5">No expenses found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($expensesData as $expense): $eCur = psFxNormalizeCode($expense['currency'] ?? '') ?: psFxLegacyCurrency(); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($expense['expense_name']); ?></td>
                        <td><?php echo htmlspecialchars($expense['funding_account']); ?></td>
                        <td><?php echo htmlspecialchars($expense['narration']); ?></td>
                        <td class="num"><?php echo htmlspecialchars(psFxFormat($conn, $expense['amount'], $eCur)); ?><?php if (($expense['currency'] ?? null) === null): ?> <span class="fxa-tag" title="Recorded before currencies were stored">legacy</span><?php endif; ?></td>
                        <td class="num"><?php echo psFxrOne($conn, ['view' => PS_FXR_CONSOLIDATED] + $fx, $expense['currency'] ?? null, $expense['amount'], $expense['base_amount'] ?? null, ['rate' => (string)($expense['fx_rate'] ?? ''), 'ids' => (string)($expense['fx_rate_ids'] ?? '')]); ?></td>
                        <td><?php echo htmlspecialchars($expense['transaction_date']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <?php if ($expensesData): ?>
        <tfoot><tr><th colspan="3">Total</th><th class="num"><?php echo psFxrAmount($conn, ['view' => PS_FXR_BREAKDOWN] + $fx, $expMix); ?></th><th class="num"><?php echo psFxrAmount($conn, ['view' => PS_FXR_CONSOLIDATED] + $fx, $expMix, ['strong' => true]); ?></th><th></th></tr></tfoot>
        <?php endif; ?>
    </table>
    <?php echo psFxrSummary($conn, $fx, $expMix, 'Expenses by currency'); ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
