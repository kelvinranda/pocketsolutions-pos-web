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
$paymentId = (int)($_GET['payment_id'] ?? 0);
$accountId = (int)($_GET['customer_account_id'] ?? 0);
$rows = [];
$payment = null;

if (!$signedIn) {
    $error = 'You must be signed in to view payment audit history.';
} else {
    if ($paymentId > 0) {
        $payment = psCustomerAccountPaymentById($conn, $paymentId);
    }
    $result = psCustomerAccountPaymentAuditFetchHistory($conn, [
        'payment_id' => $paymentId,
        'customer_account_id' => $accountId,
        'limit' => 500,
    ]);
    if (!$result['ok']) {
        $error = (string)($result['error'] ?? 'Audit history could not be loaded.');
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
    <title>Account Payment Audit</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Account payment audit</h1>
    <p class="hint">Append-only CREATE / POST / ALLOCATE / VOID. No backfill. Not account-master audit.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <div class="actions">
            <?php if ($payment): ?>
                <a href="customer_account_payment_view.php?id=<?php echo $paymentId; ?>">
                    Back to <?php echo htmlspecialchars((string)$payment['payment_number'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endif; ?>
            <a href="customer_account_payments.php">Payments list</a>
        </div>

        <?php if ($rows === []): ?>
            <p class="hint">No audit rows.</p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Payment</th>
                        <th>Action</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>By</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $ar): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$ar['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="mono">
                            <a href="customer_account_payment_view.php?id=<?php echo (int)$ar['payment_id']; ?>">
                                <?php echo htmlspecialchars((string)$ar['payment_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars((string)$ar['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$ar['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($ar['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($ar['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$ar['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$ar['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
