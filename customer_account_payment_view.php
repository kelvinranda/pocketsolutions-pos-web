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
$notice = '';
$paymentId = (int)($_GET['id'] ?? $_POST['payment_id'] ?? 0);
$payment = null;
$account = null;
$customer = null;
$allocRows = [];
$unalloc = ['amount' => '0.00', 'allocated' => '0.00', 'unallocated' => '0.00'];
$auditRows = [];

if (!empty($_GET['created'])) {
    $notice = 'Payment posted.';
}
if (!empty($_GET['allocated'])) {
    $notice = 'Allocations saved.';
}
if (!empty($_GET['voided'])) {
    $notice = 'Payment voided.';
}

if (!$signedIn) {
    $error = 'You must be signed in to view account payments.';
} elseif ($paymentId < 1) {
    $error = 'Payment was not found.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_payment'])) {
        if (!psCsrfValidate()) {
            $error = 'Invalid security token. Reload and try again.';
        } elseif (!psCapConsumeSubmitToken($_POST['cap_submit_token'] ?? '')) {
            $error = 'This form was already submitted or the session expired. Please try again.';
        } else {
            $voidResult = psCustomerAccountPaymentVoid($conn, $paymentId, (string)($_POST['void_reason'] ?? ''));
            if ($voidResult['ok']) {
                header('Location: customer_account_payment_view.php?id=' . $paymentId . '&voided=1');
                exit;
            }
            $error = (string)($voidResult['error'] ?? 'The payment could not be voided.');
        }
    }

    $payment = psCustomerAccountPaymentById($conn, $paymentId);
    if (!$payment) {
        $error = 'Payment was not found.';
    } else {
        $account = psCustomerAccountById($conn, (int)$payment['customer_account_id']);
        if ($account) {
            $customer = psCustomerById($conn, (int)$account['customer_master_id']);
        }
        $u = psCustomerAccountPaymentUnallocated($conn, $paymentId);
        if ($u['ok']) {
            $unalloc = $u;
        }
        $alloc = psCustomerAccountPaymentAllocationsForPayment($conn, $paymentId);
        if ($alloc['ok']) {
            $allocRows = $alloc['rows'] ?? [];
        }
        $audit = psCustomerAccountPaymentAuditFetchHistory($conn, [
            'payment_id' => $paymentId,
            'limit' => 50,
        ]);
        if ($audit['ok']) {
            $auditRows = $audit['rows'] ?? [];
        }
    }
}

$submitToken = ($signedIn && $payment && strtoupper((string)$payment['status']) === PS_CAP_STATUS_POSTED)
    ? psCapIssueSubmitToken()
    : '';

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Payment</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Account payment</h1>
    <p class="hint">
        Ledger effect: one PAYMENT credit. Void posts PAYMENT_REVERSAL debit and voids allocations —
        original PAYMENT ledger row and amount are retained. Till not updated.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $payment): ?>
        <?php
        $st = strtoupper(trim((string)$payment['status']));
        $stClass = $st === PS_CAP_STATUS_POSTED ? 'status-posted' : 'status-voided';
        $aid = (int)$payment['customer_account_id'];
        ?>
        <section class="section section-first">
            <h2>Payment</h2>
            <dl class="detail-grid">
                <dt>Payment number</dt>
                <dd class="mono"><?php echo htmlspecialchars((string)$payment['payment_number'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Account</dt>
                <dd class="mono">
                    <?php if ($account): ?>
                        <a href="customer_account_view.php?id=<?php echo $aid; ?>">
                            <?php echo htmlspecialchars((string)$account['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else: ?>
                        #<?php echo $aid; ?>
                    <?php endif; ?>
                </dd>
                <dt>Customer</dt>
                <dd>
                    <?php if ($customer): ?>
                        <a href="customer_view.php?id=<?php echo (int)$customer['id']; ?>">
                            <?php echo htmlspecialchars((string)$customer['customer_code'] . ' — ' . psCustomerDisplayName($customer), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </dd>
                <dt>Date</dt>
                <dd><?php echo htmlspecialchars((string)$payment['payment_date'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Method</dt>
                <dd><?php echo htmlspecialchars(psCustomerAccountPaymentMethodLabel($payment['payment_method']), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Amount</dt>
                <dd class="num mono"><?php echo htmlspecialchars(number_format((float)$payment['amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Allocated</dt>
                <dd class="num"><?php echo htmlspecialchars((string)$unalloc['allocated'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Unallocated</dt>
                <dd class="num"><?php echo htmlspecialchars((string)$unalloc['unallocated'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Status</dt>
                <dd class="<?php echo $stClass; ?>"><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Reference</dt>
                <dd><?php echo htmlspecialchars((string)($payment['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Notes</dt>
                <dd><?php echo htmlspecialchars((string)$payment['notes'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Created</dt>
                <dd><?php echo htmlspecialchars((string)$payment['created_at'] . ' by ' . (string)$payment['created_by'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <?php if ($st === PS_CAP_STATUS_VOIDED): ?>
                    <dt>Voided</dt>
                    <dd><?php echo htmlspecialchars((string)($payment['voided_at'] ?? '') . ' by ' . (string)($payment['voided_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                <?php endif; ?>
            </dl>

            <div class="actions">
                <?php if ($st === PS_CAP_STATUS_POSTED && (float)$unalloc['unallocated'] > 0): ?>
                    <a href="customer_account_payment_allocate.php?id=<?php echo $paymentId; ?>">Allocate remaining</a>
                <?php endif; ?>
                <a href="customer_account_payment_audit_history.php?payment_id=<?php echo $paymentId; ?>">Payment audit</a>
                <a href="customer_account_payments.php">Back to payments</a>
            </div>
        </section>

        <section class="section">
            <h2>Allocations</h2>
            <p class="hint">ACTIVE allocations reduce credit-sale outstanding. Voided allocations are retained for audit.</p>
            <?php if ($allocRows === []): ?>
                <p class="hint">No allocations (fully unallocated or none recorded).</p>
            <?php else: ?>
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>When</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allocRows as $ar): ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars((string)$ar['sale_invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$ar['allocated_amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ar['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ar['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ar['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <?php if ($st === PS_CAP_STATUS_POSTED): ?>
            <section class="section">
                <h2>Void payment</h2>
                <p class="hint">
                    Marks VOIDED, posts PAYMENT_REVERSAL, voids allocations. Does not delete the payment
                    or original PAYMENT ledger row. Does not reopen the account.
                </p>
                <form method="POST" action="" class="pay-form">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="payment_id" value="<?php echo $paymentId; ?>">
                    <input type="hidden" name="cap_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="void_reason">Reason</label>
                    <textarea id="void_reason" name="void_reason" required maxlength="500" rows="3"></textarea>
                    <div class="actions">
                        <button type="submit" name="void_payment" value="1">Void payment</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="section">
            <h2>Recent payment audit</h2>
            <?php if ($auditRows === []): ?>
                <p class="hint">No audit rows.</p>
            <?php else: ?>
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>Field</th>
                            <th>Old</th>
                            <th>New</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditRows as $ar): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$ar['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ar['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ar['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($ar['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($ar['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ar['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
