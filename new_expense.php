<?php
/**
 * New expense. Health-check fix 2026-10-13: login required (was open to anyone);
 * admin-only via the central page-access rules, same as expenses.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
psBranchRequireAuthRedirect('login.php');

// Initialize variables
$expense_name = $amount = $narration = "";
$error = "";
$success = "";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Multi-currency (2026-10-20): an expense is recorded in an enabled currency with the rate to the company base.
require_once __DIR__ . '/includes/currency.php';
psFxEnsureTransactionColumns($conn);
$fxCompany = psFxSessionCompany();
$expCurrencies = psFxCompanyCurrencies($conn, $fxCompany);
$expCurrency = psFxNormalizeCode($_POST['currency'] ?? '') ?: psFxBase($conn, $fxCompany);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!function_exists('psCsrfValidate')) {
        require_once __DIR__ . '/includes/security.php';
    }
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $expense_name = trim((string)($_POST['expense_name'] ?? ''));
        $amount = trim((string)($_POST['amount'] ?? ''));
        $narration = trim((string)($_POST['narration'] ?? ''));

        if ($expense_name === '' || $amount === '') {
            $error = 'Expense Name and Amount are required.';
        } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
            $error = 'Enter the amount as a positive number, e.g. 25.50.';
        } elseif (empty(($expCheck = psFxCheckTransactionCurrency($conn, $fxCompany, $expCurrency))['ok'])) {
            $error = (string)$expCheck['error'];
        } elseif (empty(($expSnap = psFxSnapshot($conn, $fxCompany, $expCurrency))['ok'])) {
            $error = (string)$expSnap['error'];
        } elseif (psFxMoneyToCents($amount) !== psFxRoundCentsToDecimals((int)psFxMoneyToCents($amount), psFxDecimals($conn, $expCurrency))) {
            $error = $expCurrency . ' amounts have ' . psFxDecimals($conn, $expCurrency) . ' decimal places.';
        } else {
            // funding_account keeps its old meaning (the currency the expense was paid in).
            $v = psFxSnapshotSqlValues($conn, $expSnap);
            $expBase = psFxCentsToMoney(psFxToBaseCents($conn, $expSnap, (int)psFxMoneyToCents($amount)));
            $stmt = mysqli_prepare(
                $conn,
                'INSERT INTO expenses (expense_id, expense_name, funding_account, month, year, amount, narration, transaction_date'
                . (psFxHasColumn($conn, 'expenses', 'currency') ? ', currency, base_currency, fx_rate, fx_rate_ids, fx_path, base_amount' : '') . ')
                 VALUES (\'\', ?, ' . $v['currency'] . ', NOW(), NOW(), ?, ?, CURDATE()'
                . (psFxHasColumn($conn, 'expenses', 'currency') ? ', ' . implode(', ', $v) . ", '" . $expBase . "'" : '') . ')'
            );
            if (!$stmt) {
                error_log('new_expense: prepare failed');
                $error = 'Expense could not be saved.';
            } else {
                mysqli_stmt_bind_param($stmt, 'sss', $expense_name, $amount, $narration);
                if (mysqli_stmt_execute($stmt)) {
                    $success = 'New expense recorded successfully!';
                    $expense_name = $amount = $narration = '';
                    if (function_exists('psSecurityAudit')) {
                        psSecurityAudit($conn, 'EXPENSE_CREATE', [
                            'summary' => 'Expense recorded',
                            'target_type' => 'expense',
                        ]);
                    }
                } else {
                    error_log('new_expense: execute failed');
                    $error = 'Expense could not be saved.';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

mysqli_close($conn); // Close database connection
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Expense</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>New Expense</h1>

    <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
        <?php if (!empty($error)): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="success"><?php echo $success; ?></div><?php endif; ?>

        <label for="expense_name">Expense Name:</label>
        <input type="text" id="expense_name" name="expense_name" value="<?php echo htmlspecialchars($expense_name); ?>" required>

        <?php if (count($expCurrencies) > 1): ?>
        <label for="currency">Currency:</label>
        <select id="currency" name="currency">
            <?php foreach ($expCurrencies as $code => $c): ?>
                <option value="<?php echo htmlspecialchars($code); ?>"<?php echo $expCurrency === $code ? ' selected' : ''; ?>><?php echo htmlspecialchars($code . ' — ' . $c['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="amount">Amount:</label>
        <input type="text" id="amount" name="amount" value="<?php echo htmlspecialchars($amount); ?>" required>

        <label for="narration">Description:</label>
        <textarea id="narration" name="narration" rows="4"><?php echo htmlspecialchars($narration); ?></textarea>

        <button type="submit">Add Expense</button>
    </form>

    <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>
<?php ps_close(); ?>
</body>
</html>