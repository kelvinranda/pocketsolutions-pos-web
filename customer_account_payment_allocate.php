<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_account_payment.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAccountPaymentModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$paymentId = (int)($_GET['id'] ?? $_POST['payment_id'] ?? 0);
$payment = null;
$unalloc = null;
$allocatable = [];

if (!$signedIn) {
    $error = 'You must be signed in to allocate payments.';
} elseif ($paymentId < 1) {
    $error = 'Payment was not found.';
} else {
    $payment = psCustomerAccountPaymentById($conn, $paymentId);
    if (!$payment) {
        $error = 'Payment was not found.';
    } elseif (strtoupper(trim((string)$payment['status'])) !== PS_CAP_STATUS_POSTED) {
        $error = 'Only posted payments can receive allocations.';
    } else {
        $unalloc = psCustomerAccountPaymentUnallocated($conn, $paymentId);
        $sales = psCustomerAccountAllocatableCreditSales($conn, (int)$payment['customer_account_id']);
        if (!empty($sales['ok'])) {
            $allocatable = $sales['rows'] ?? [];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alloc_submit']) && $signedIn && $payment) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!psCapConsumeSubmitToken($_POST['cap_submit_token'] ?? '')) {
        $error = 'This form was already submitted or the session expired. Please try again.';
    } else {
        $lines = [];
        $invoices = $_POST['alloc_invoice'] ?? [];
        $amounts = $_POST['alloc_amount'] ?? [];
        if (is_array($invoices)) {
            foreach ($invoices as $idx => $inv) {
                $inv = trim((string)$inv);
                $amt = is_array($amounts) ? trim((string)($amounts[$idx] ?? '')) : '';
                if ($inv === '' || $amt === '' || (float)$amt <= 0) {
                    continue;
                }
                $lines[] = [
                    'sale_invoice_number' => $inv,
                    'allocated_amount' => $amt,
                ];
            }
        }
        $result = psCustomerAccountPaymentAllocate($conn, $paymentId, $lines);
        if ($result['ok']) {
            header('Location: customer_account_payment_view.php?id=' . $paymentId . '&allocated=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'Allocations could not be saved.');
        $unalloc = psCustomerAccountPaymentUnallocated($conn, $paymentId);
        $sales = psCustomerAccountAllocatableCreditSales($conn, (int)$payment['customer_account_id']);
        if (!empty($sales['ok'])) {
            $allocatable = $sales['rows'] ?? [];
        }
    }

    }
}

$submitToken = ($signedIn && $payment) ? psCapIssueSubmitToken() : '';

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allocate Account Payment</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Allocate payment</h1>
    <p class="hint">Apply the unapplied part of this payment to unpaid credit sales on the same account. You cannot apply more than is owed.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $payment && $unalloc && $unalloc['ok']): ?>
        <p>
            Payment <span class="mono"><?php echo htmlspecialchars((string)$payment['payment_number'], ENT_QUOTES, 'UTF-8'); ?></span>
            — unallocated
            <strong><?php echo htmlspecialchars((string)$unalloc['unallocated'], ENT_QUOTES, 'UTF-8'); ?></strong>
            of <?php echo htmlspecialchars((string)$unalloc['amount'], ENT_QUOTES, 'UTF-8'); ?>
        </p>

        <?php if ((float)$unalloc['unallocated'] <= 0): ?>
            <p class="hint">Nothing left to allocate.</p>
            <p><a href="customer_account_payment_view.php?id=<?php echo $paymentId; ?>">Back to payment</a></p>
        <?php elseif ($allocatable === []): ?>
            <p class="hint">No open credit-sale outstanding on this account.</p>
            <p><a href="customer_account_payment_view.php?id=<?php echo $paymentId; ?>">Back to payment</a></p>
        <?php else: ?>
            <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
                <input type="hidden" name="cap_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                <table class="alloc-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Outstanding</th>
                            <th>Allocate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allocatable as $i => $sale): ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars((string)$sale['sale_invoice_number'], ENT_QUOTES, 'UTF-8'); ?>
                                <input type="hidden" name="alloc_invoice[<?php echo $i; ?>]"
                                       value="<?php echo htmlspecialchars((string)$sale['sale_invoice_number'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td><?php echo htmlspecialchars((string)$sale['entry_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$sale['outstanding'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><input type="text" name="alloc_amount[<?php echo $i; ?>]" placeholder="0.00"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="actions">
                    <button type="submit" name="alloc_submit" value="1">Save allocations</button>
                    <a href="customer_account_payment_view.php?id=<?php echo $paymentId; ?>">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
