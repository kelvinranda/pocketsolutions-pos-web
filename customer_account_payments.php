<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_account_payment.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAccountPaymentModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$rows = [];

if (!$signedIn) {
    $error = 'You must be signed in to view account payments.';
} else {
    $list = psCustomerAccountPaymentList($conn, $search, $statusFilter);
    if (!$list['ok']) {
        $error = (string)($list['error'] ?? 'Payments could not be loaded.');
    } else {
        $rows = $list['rows'] ?? [];
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Payments</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Account Payments</h1>
    <p class="hint">
        Receivable receipts (PAY-######) posted to the customer account ledger. Distinct from POS
        <code>sale_payments</code>. Till cash integration is disabled — method is recorded on the
        payment only. Allocations reduce sale outstanding; they do not create ledger rows.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <div class="actions">
            <a href="create_customer_account_payment.php">Record payment</a>
            <a href="customer_accounts_list.php">Customer accounts</a>
        </div>

        <form method="GET" action="" class="filters">
            <div>
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="PAY- / ACC- / customer / reference">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['all' => 'All', 'posted' => 'Posted', 'voided' => 'Voided'] as $k => $lab): ?>
                        <option value="<?php echo $k; ?>"<?php echo $statusFilter === $k ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Filter</button>
        </form>

        <?php if ($rows === []): ?>
            <p class="hint">No payments found.</p>
        <?php else: ?>
            <table class="pay-table">
                <thead>
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $st = strtoupper(trim((string)$row['status']));
                    $stClass = $st === PS_CAP_STATUS_POSTED ? 'status-posted' : 'status-voided';
                    ?>
                    <tr>
                        <td class="mono"><?php echo htmlspecialchars((string)$row['payment_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['payment_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="mono">
                            <a href="customer_account_view.php?id=<?php echo (int)$row['customer_account_id']; ?>">
                                <?php echo htmlspecialchars((string)$row['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars((string)$row['customer_code'] . ' — ' . (string)$row['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psCustomerAccountPaymentMethodLabel($row['payment_method']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo htmlspecialchars((string)$row['amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="<?php echo $stClass; ?>"><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><a href="customer_account_payment_view.php?id=<?php echo (int)$row['id']; ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
