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

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$statusFilterRaw = array_key_exists('status_filter', $_GET)
    ? (string)$_GET['status_filter']
    : (array_key_exists('status_filter', $_POST) ? (string)$_POST['status_filter'] : 'all');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'active', 'suspended', 'closed'], true)) {
    $statusFilterKey = 'all';
}

$error = '';
$notice = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

if (!empty($_GET['created'])) {
    $notice = 'Customer account created.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to change account status.';
    } else {
        $statusId = (int)($_POST['account_id'] ?? 0);
        $postedStatus = (string)($_POST['new_status'] ?? '');
        $statusResult = psSetCustomerAccountStatus($conn, $statusId, $postedStatus);
        if ($statusResult['ok']) {
            $q = 'status_filter=' . rawurlencode($statusFilterKey);
            if ($search !== '') {
                $q .= '&search=' . rawurlencode($search);
            }
            header('Location: customer_accounts_list.php?' . $q);
            exit;
        }
        $error = (string)($statusResult['error'] ?? 'Account status could not be saved.');
    }

    }
}

$list = ['ok' => false, 'rows' => []];
if (!$signedIn) {
    $error = $error !== '' ? $error : 'You must be signed in to view customer accounts.';
} else {
    $list = psCustomerAccountList($conn, $search, $statusFilterKey);
    if (!$list['ok']) {
        $error = (string)($list['error'] ?? 'Accounts could not be loaded.');
    }
}
$rows = $list['rows'] ?? [];

require_once __DIR__ . '/includes/currency.php';
$listBaseCurrency = psFxBase($conn, psFxSessionCompany());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Accounts</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Customer Accounts</h1>
    <p class="hint">
        Receivables ledger foundation. One account per customer. Balance is ledger-derived
        (debit − credit); empty ledger shows 0.00. Credit sales and payments are not in this module.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="acct-toolbar">
            <div>
                <label for="search">Search</label>
                <input type="text" id="search" name="search"
                       value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="Account #, code, name">
            </div>
            <div>
                <label for="status_filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="all"<?php echo $statusFilterKey === 'all' ? ' selected' : ''; ?>>All</option>
                    <option value="active"<?php echo $statusFilterKey === 'active' ? ' selected' : ''; ?>>Active</option>
                    <option value="suspended"<?php echo $statusFilterKey === 'suspended' ? ' selected' : ''; ?>>Suspended</option>
                    <option value="closed"<?php echo $statusFilterKey === 'closed' ? ' selected' : ''; ?>>Closed</option>
                </select>
            </div>
            <div>
                <button type="submit">Filter</button>
                <?php if (strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin'): ?><a href="create_customer_account.php">Create account</a><?php endif; ?>
                <a href="customers_list.php">Customers</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p class="hint">No customer accounts match this filter.</p>
        <?php else: ?>
            <table class="acct-table">
                <thead>
                    <tr>
                        <th>Account #</th>
                        <th>Customer code</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Balance</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $aid = (int)$row['id'];
                    $st = strtoupper(trim((string)$row['status']));
                    $stClass = 'status-closed';
                    if ($st === PS_CUST_ACCT_STATUS_ACTIVE) {
                        $stClass = 'status-active';
                    } elseif ($st === PS_CUST_ACCT_STATUS_SUSPENDED) {
                        $stClass = 'status-suspended';
                    }
                    ?>
                    <tr>
                        <td class="mono">
                            <a href="customer_account_view.php?id=<?php echo $aid; ?>">
                                <?php echo htmlspecialchars((string)$row['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                        <td class="mono"><?php echo htmlspecialchars((string)$row['customer_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="<?php echo $stClass; ?>">
                            <?php echo htmlspecialchars(psCustomerAccountStatusLabel($st), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="num"><?php echo htmlspecialchars(psFxFormat($conn, $row['balance'], (string)($row['currency'] ?? '') !== '' ? (string)$row['currency'] : $listBaseCurrency), ENT_QUOTES, 'UTF-8'); ?><?php if ((string)($row['currency'] ?? '') === ''): ?> <span class="fxa-tag" title="Account opened before currencies were recorded">legacy</span><?php endif; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="customer_account_view.php?id=<?php echo $aid; ?>">Open</a>
                                <?php if ($st !== PS_CUST_ACCT_STATUS_ACTIVE): ?>
                                    <form method="POST" action="" style="display:inline;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                        <input type="hidden" name="account_id" value="<?php echo $aid; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="new_status" value="<?php echo PS_CUST_ACCT_STATUS_ACTIVE; ?>">
                                        <button type="submit" name="set_status" value="1">Activate</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($st !== PS_CUST_ACCT_STATUS_SUSPENDED): ?>
                                    <form method="POST" action="" style="display:inline;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                        <input type="hidden" name="account_id" value="<?php echo $aid; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="new_status" value="<?php echo PS_CUST_ACCT_STATUS_SUSPENDED; ?>">
                                        <button type="submit" name="set_status" value="1">Suspend</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($st !== PS_CUST_ACCT_STATUS_CLOSED): ?>
                                    <form method="POST" action="" style="display:inline;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                        <input type="hidden" name="account_id" value="<?php echo $aid; ?>">
                                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="new_status" value="<?php echo PS_CUST_ACCT_STATUS_CLOSED; ?>">
                                        <button type="submit" name="set_status" value="1">Close</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
