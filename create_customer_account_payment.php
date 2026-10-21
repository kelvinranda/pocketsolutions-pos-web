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
$accountId = (int)($_GET['account_id'] ?? $_POST['customer_account_id'] ?? 0);
$amount = trim((string)($_POST['amount'] ?? ''));
$method = strtoupper(trim((string)($_POST['payment_method'] ?? 'CASH')));
$paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
$reference = trim((string)($_POST['reference'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$autoAllocate = !isset($_POST['cap_submit']) || !empty($_POST['auto_allocate']);

$accounts = [];
$allocatable = [];
$account = null;

if ($signedIn) {
    $acctList = psCustomerAccountList($conn, '', 'all');
    if (!empty($acctList['ok'])) {
        foreach ($acctList['rows'] as $a) {
            $st = strtoupper(trim((string)$a['status']));
            if ($st === PS_CUST_ACCT_STATUS_CLOSED) {
                continue;
            }
            $accounts[] = $a;
        }
    }
    if ($accountId > 0) {
        $account = psCustomerAccountById($conn, $accountId);
        if ($account) {
            $sales = psCustomerAccountAllocatableCreditSales($conn, $accountId);
            if (!empty($sales['ok'])) {
                $allocatable = $sales['rows'] ?? [];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cap_submit'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to record a payment.';
    } elseif (!psCapConsumeSubmitToken($_POST['cap_submit_token'] ?? '')) {
        $error = 'This form was already submitted or the session expired. Please try again.';
    } else {
        $accountId = (int)($_POST['customer_account_id'] ?? 0);
        $manualAlloc = [];
        if (empty($_POST['auto_allocate']) && isset($_POST['alloc_invoice']) && is_array($_POST['alloc_invoice'])) {
            $amounts = $_POST['alloc_amount'] ?? [];
            foreach ($_POST['alloc_invoice'] as $idx => $inv) {
                $inv = trim((string)$inv);
                $amt = is_array($amounts) ? trim((string)($amounts[$idx] ?? '')) : '';
                if ($inv === '' || $amt === '' || (float)$amt <= 0) {
                    continue;
                }
                $manualAlloc[] = [
                    'sale_invoice_number' => $inv,
                    'allocated_amount' => $amt,
                ];
            }
        }
        $result = psCustomerAccountPaymentCreate($conn, [
            'customer_account_id' => $accountId,
            'amount' => $_POST['amount'] ?? '',
            'tender_currency' => $_POST['tender_currency'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? '',
            'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
            'reference' => $_POST['reference'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'auto_allocate' => !empty($_POST['auto_allocate']),
            'allocations' => empty($_POST['auto_allocate']) ? $manualAlloc : null,
            // Tamper probes — ignored by helper:
            'payment_number' => $_POST['payment_number'] ?? 'PAY-HACK',
            'balance' => $_POST['balance'] ?? '0',
            'outstanding' => $_POST['outstanding'] ?? '0',
            'status' => $_POST['status'] ?? 'POSTED',
        ]);
        if ($result['ok']) {
            header('Location: customer_account_payment_view.php?id=' . (int)$result['id'] . '&created=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The payment could not be saved.');
        $amount = trim((string)($_POST['amount'] ?? ''));
        $method = strtoupper(trim((string)($_POST['payment_method'] ?? 'CASH')));
        $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $autoAllocate = !empty($_POST['auto_allocate']);
        if ($accountId > 0) {
            $account = psCustomerAccountById($conn, $accountId);
            $sales = psCustomerAccountAllocatableCreditSales($conn, $accountId);
            if (!empty($sales['ok'])) {
                $allocatable = $sales['rows'] ?? [];
            }
        }
    }

    }
}

$submitToken = $signedIn ? psCapIssueSubmitToken() : '';
// Multi-currency (2026-10-20): the account's own currency, and other currencies a payment can be received in.
$payCurrencies = $signedIn ? psFxCompanyCurrencies($conn, psFxSessionCompany()) : [];
$payAcctCur = $account ? psCustomerAccountRowCurrency($conn, $account) : '';
$payTender = psFxNormalizeCode($_POST['tender_currency'] ?? '') ?: $payAcctCur;
$payRates = [];
if ($payAcctCur !== '') {
    foreach ($payCurrencies as $code => $c) {
        if ($code !== $payAcctCur) {
            $r = psFxRate($conn, psFxSessionCompany(), $code, $payAcctCur);
            $payRates[$code] = !empty($r['ok']) ? psFxRateLabel($code, $payAcctCur, (string)$r['rate']) : 'no rate yet';
        }
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Customer Account Payment</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Record account payment</h1>
    <p class="hint">Records money received from an account customer and reduces their balance. You can apply it to specific unpaid credit sales (oldest first by default) or leave part unapplied. Closed accounts cannot take payments. This payment is not added to the till.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!$signedIn): ?>
        <div class="error">You must be signed in to record a payment.</div>
    <?php else: ?>
        <form method="POST" action="" class="pay-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <input type="hidden" name="cap_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="customer_account_id">Account</label>
            <select id="customer_account_id" name="customer_account_id" required
                    onchange="if(this.value){ location='create_customer_account_payment.php?account_id='+this.value; }">
                <option value="">Select account…</option>
                <?php foreach ($accounts as $a): ?>
                    <?php
                    $aid = (int)$a['id'];
                    $label = (string)$a['account_number'] . ' — ' . (string)$a['customer_code']
                        . ' — ' . psCustomerDisplayName($a)
                        . ' [' . psCustomerAccountStatusLabel((string)$a['status']) . ']';
                    $sel = $accountId === $aid ? ' selected' : '';
                    ?>
                    <option value="<?php echo $aid; ?>"<?php echo $sel; ?>>
                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="payment_date">Payment date</label>
            <input type="date" id="payment_date" name="payment_date"
                   value="<?php echo htmlspecialchars($paymentDate, ENT_QUOTES, 'UTF-8'); ?>" required>

            <?php if ($payAcctCur !== '' && count($payCurrencies) > 1): ?>
            <label for="tender_currency">Received in</label>
            <select id="tender_currency" name="tender_currency">
                <?php foreach ($payCurrencies as $code => $c): ?>
                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $payTender === $code ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($code . ($code === $payAcctCur ? ' (account currency)' : ' — ' . ($payRates[$code] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">This account is kept in <?php echo htmlspecialchars($payAcctCur, ENT_QUOTES, 'UTF-8'); ?>. Money received in another currency is converted at today's rate; allocations below are in <?php echo htmlspecialchars($payAcctCur, ENT_QUOTES, 'UTF-8'); ?>.</p>
            <?php endif; ?>

            <label for="amount">Amount<?php echo $payAcctCur !== '' ? ' (in the currency received)' : ''; ?></label>
            <input type="text" id="amount" name="amount" required placeholder="0.00"
                   value="<?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="payment_method">Method</label>
            <select id="payment_method" name="payment_method" required>
                <?php foreach (psCustomerAccountPaymentMethods() as $code => $lab): ?>
                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $method === $code ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="reference">Reference (optional)</label>
            <input type="text" id="reference" name="reference" maxlength="120"
                   value="<?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?>">

            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" rows="2" maxlength="500"><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>

            <div class="check-row">
                <input type="checkbox" id="auto_allocate" name="auto_allocate" value="1"
                    <?php echo $autoAllocate ? ' checked' : ''; ?>>
                <label for="auto_allocate" style="margin:0;">Auto-allocate oldest credit sales first</label>
            </div>

            <?php if ($accountId > 0): ?>
                <p class="hint">
                    Open credit-sale receivables for this account (optional manual lines when auto-allocate is off).
                    Cross-customer and non–credit-sale invoices are rejected by the server.
                </p>
                <?php if ($allocatable === []): ?>
                    <p class="hint">No open credit-sale outstanding — payment may remain fully unallocated.</p>
                <?php else: ?>
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
                                <td>
                                    <input type="text" name="alloc_amount[<?php echo $i; ?>]" placeholder="0.00"
                                           <?php echo $autoAllocate ? ' disabled' : ''; ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <div class="actions">
                <button type="submit" name="cap_submit" value="1">Post payment</button>
                <a href="customer_account_payments.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
