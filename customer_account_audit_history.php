<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_account.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAccountAuditSchema($conn);

$error = '';
$rows = [];
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

$filterAccountId = (int)($_GET['customer_account_id'] ?? 0);
$filterNumber = trim((string)($_GET['account_number'] ?? ''));

if (!$signedIn) {
    $error = 'You must be signed in to view account audit history.';
} else {
    $result = psCustomerAccountAuditFetchHistory($conn, [
        'customer_account_id' => $filterAccountId,
        'account_number' => $filterNumber,
        'limit' => 300,
    ]);
    if (!$result['ok']) {
        $error = (string)($result['error'] ?? 'Account audit history could not be loaded.');
    } else {
        $rows = $result['rows'] ?? [];
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Account Audit History</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Customer account audit history</h1>
    <p class="audit-note">
        Field-level account changes only (CREATE / UPDATE / ACTIVATE / SUSPEND / CLOSE / OPENING_BALANCE).
        Audit rows cannot be edited or deleted from this screen. No historical backfill.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="audit-filters">
            <div>
                <label for="customer_account_id">Account ID</label>
                <input type="number" id="customer_account_id" name="customer_account_id" min="1"
                       value="<?php echo $filterAccountId > 0 ? (int)$filterAccountId : ''; ?>">
            </div>
            <div>
                <label for="account_number">Account number</label>
                <input type="text" id="account_number" name="account_number"
                       value="<?php echo htmlspecialchars($filterNumber, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <button type="submit">Filter</button>
                <a href="customer_accounts_list.php">Back to accounts</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p class="empty">No audit rows for this filter.</p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>Account #</th>
                        <th>Account ID</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['account_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$row['customer_account_id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
