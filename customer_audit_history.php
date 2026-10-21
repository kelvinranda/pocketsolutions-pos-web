<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAuditSchema($conn);

$error = '';
$rows = [];
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

$filterCustomerId = (int)($_GET['customer_id'] ?? 0);
$filterCode = trim((string)($_GET['customer_code'] ?? ''));

if (!$signedIn) {
    $error = 'You must be signed in to view customer audit history.';
} else {
    $result = psCustomerAuditFetchHistory($conn, [
        'customer_id' => $filterCustomerId,
        'customer_code' => $filterCode,
        'limit' => 300,
    ]);
    if (!$result['ok']) {
        $error = (string)($result['error'] ?? 'Customer audit history could not be loaded.');
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
    <title>Customer Audit History</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Customer Audit History</h1>
    <p class="audit-note">
        Field-level customer master changes only (CREATE / UPDATE / ACTIVATE / DEACTIVATE).
        Audit rows cannot be edited or deleted from this screen.
        No historical backfill of pre-audit or legacy <code>customers</code> table changes.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="audit-filters">
            <div>
                <label for="customer_id">Customer ID</label>
                <input type="number" id="customer_id" name="customer_id" min="1"
                       value="<?php echo $filterCustomerId > 0 ? (int)$filterCustomerId : ''; ?>">
            </div>
            <div>
                <label for="customer_code">Customer code</label>
                <input type="text" id="customer_code" name="customer_code"
                       value="<?php echo htmlspecialchars($filterCode, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <button type="submit">Filter</button>
                <a href="customers_list.php">Back to customers</a>
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
                        <th>Code</th>
                        <th>Customer ID</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>By</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['customer_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$row['customer_id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
