<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_account.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAccountModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$customerMasterId = (int)($_GET['customer_master_id'] ?? $_POST['customer_master_id'] ?? 0);
$creditLimit = trim((string)($_POST['credit_limit'] ?? '0.00'));
$preselected = null;

if ($customerMasterId > 0) {
    $preselected = psCustomerById($conn, $customerMasterId);
    if (!$preselected) {
        $customerMasterId = 0;
    }
}

$activeCustomers = [];
// Multi-currency (2026-10-20): the account's currency is chosen once, here.
require_once __DIR__ . '/includes/currency.php';
$acctCurrencies = $signedIn ? psFxCompanyCurrencies($conn, psFxSessionCompany()) : [];
$acctCurrencyPick = psFxNormalizeCode($_POST['currency'] ?? '');
if ($signedIn) {
    $activeList = psActiveCustomersForSelect($conn);
    if (!empty($activeList['ok'])) {
        $activeCustomers = $activeList['rows'] ?? [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    $customerMasterId = (int)($_POST['customer_master_id'] ?? 0);
    $creditLimit = trim((string)($_POST['credit_limit'] ?? '0.00'));
    // Ignore tampered posted identity / balance fields.
    unset($_POST['account_number'], $_POST['balance'], $_POST['posted_balance']);

    if (!$signedIn) {
        $error = 'You must be signed in to create an account.';
    } else {
        $result = psCreateCustomerAccount($conn, [
            'customer_master_id' => $customerMasterId,
            'credit_limit' => $creditLimit,
            'currency' => $_POST['currency'] ?? '',
            // Tamper probes — must be ignored by helper:
            'account_number' => $_POST['account_number'] ?? 'ACC-HACKED',
            'balance' => $_POST['balance'] ?? '9999.99',
            'posted_balance' => $_POST['posted_balance'] ?? '9999.99',
        ]);
        if ($result['ok']) {
            $id = (int)($result['id'] ?? 0);
            header('Location: customer_account_view.php?id=' . $id . '&created=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The account could not be saved.');
        $preselected = $customerMasterId > 0 ? psCustomerById($conn, $customerMasterId) : null;
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
    <title>Create Customer Account</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Create customer account</h1>
    <p class="hint">
        Explicit create only — one account per customer. Account number (ACC-######) is allocated
        on the server. Active customers only. Posted balance / account number are ignored.
        Credit limit is stored but not used to authorise sales yet.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!$signedIn): ?>
        <div class="error">You must be signed in to create an account.</div>
    <?php else: ?>
        <form method="POST" action="" class="acct-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <label for="customer_master_id">Customer</label>
            <select id="customer_master_id" name="customer_master_id" required>
                <option value="">Select active customer…</option>
                <?php foreach ($activeCustomers as $c): ?>
                    <?php
                    $cid = (int)$c['id'];
                    $label = (string)$c['customer_code'] . ' — ' . psCustomerDisplayName($c);
                    $sel = $customerMasterId === $cid ? ' selected' : '';
                    ?>
                    <option value="<?php echo $cid; ?>"<?php echo $sel; ?>>
                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($preselected && !psCustomerStatusIsActive($preselected['status'] ?? '')): ?>
                <p class="hint">Selected customer is inactive — activate the customer before creating an account.</p>
            <?php endif; ?>

            <?php if (count($acctCurrencies) > 1): ?>
            <label for="currency">Account currency</label>
            <select id="currency" name="currency">
                <?php foreach ($acctCurrencies as $code => $c): ?>
                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $acctCurrencyPick === $code ? ' selected' : ''; ?>><?php echo htmlspecialchars($code . ' — ' . $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Credit sales and the balance of this account are kept in this currency. It cannot be changed later.</p>
            <?php endif; ?>

            <label for="credit_limit">Credit limit</label>
            <input type="text" id="credit_limit" name="credit_limit"
                   value="<?php echo htmlspecialchars($creditLimit, ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="0.00">

            <div class="acct-actions">
                <button type="submit">Create account</button>
                <a href="customer_accounts_list.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
