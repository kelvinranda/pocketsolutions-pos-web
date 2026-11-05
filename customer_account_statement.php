<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_account_reporting.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAccountModule($conn);
psEnsureCustomerAccountPaymentModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$accountId = (int)($_GET['account_id'] ?? $_GET['id'] ?? 0);

$preset = strtolower(trim((string)($_GET['preset'] ?? 'month')));
if (!in_array($preset, ['all', 'today', 'month', 'year', 'custom'], true)) {
    $preset = 'month';
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
$pageSize = (int)($_GET['page_size'] ?? 25);
if ($pageSize < 1 || $pageSize > 100) {
    $pageSize = 25;
}

// Ignore posted financial fields if present (security).
unset($_GET['balance'], $_GET['opening_balance'], $_GET['closing_balance'], $_GET['outstanding']);

$statement = null;
if (!$signedIn) {
    $error = 'You must be signed in to view account statements.';
} elseif ($accountId < 1) {
    $error = 'Account was not found.';
} else {
    $statement = psCustomerAccountStatement($conn, $accountId, [
        'preset' => $preset,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'page' => $page,
        'page_size' => $pageSize,
    ]);
    if (!$statement['ok']) {
        $error = (string)($statement['error'] ?? 'Statement could not be loaded.');
        $statement = null;
    } else {
        $preset = (string)$statement['filters']['preset'];
        $fromDate = (string)($statement['filters']['from_date'] ?? '');
        $toDate = (string)($statement['filters']['to_date'] ?? '');
        $page = (int)$statement['pagination']['page'];
        $pageSize = (int)$statement['pagination']['page_size'];
    }
}

function psStmtQuery(int $accountId, array $overrides = []): string
{
    global $preset, $fromDate, $toDate, $page, $pageSize;
    $q = array_merge([
        'account_id' => $accountId,
        'preset' => $preset,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'page' => $page,
        'page_size' => $pageSize,
    ], $overrides);
    if (($q['preset'] ?? '') !== 'custom') {
        unset($q['from_date'], $q['to_date']);
    }
    return 'customer_account_statement.php?' . http_build_query($q);
}

require_once __DIR__ . '/includes/currency.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Account Statement</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Customer Account Statement'); ?>
    <h1>Customer Account Statement</h1>
    <p class="hint">
        Derived from <code>customer_account_ledger</code> only. Viewing does not post financial entries.
        Labels: Amount Due (positive balance) / Customer Credit (negative). Export is not available on this screen.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $statement): ?>
        <?php
        $h = $statement['header'];
        $label = $statement['balance_label'];
        $pager = $statement['pagination'];
        $rows = $statement['rows'] ?? [];
        $st = (string)$h['status'];
        // Every amount on a statement is in the account's own currency.
        $acCur = (string)($h['currency'] ?? '');
        $S = static fn($v): string => htmlspecialchars(psFxFormat($conn, $v ?? 0, $acCur !== '' ? $acCur : psFxLegacyCurrency()), ENT_QUOTES, 'UTF-8');
        ?>
        <dl class="stmt-header">
            <div><dt>Customer</dt><dd><?php echo htmlspecialchars((string)$h['customer_code'] . ' — ' . (string)$h['customer_name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Account</dt><dd class="mono"><?php echo htmlspecialchars((string)$h['account_number'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Status</dt><dd><?php echo htmlspecialchars(psCustomerAccountStatusLabel($st), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Currency</dt><dd><strong><?php echo htmlspecialchars($acCur, ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($h['currency_legacy'])): ?> <span class="hint">(account opened before currencies were recorded: treated as <?php echo htmlspecialchars($acCur, ENT_QUOTES, 'UTF-8'); ?>)</span><?php endif; ?> <span class="hint">— all amounts below are in this currency</span></dd></div>
            <div><dt>Credit limit</dt><dd class="num"><?php echo $S($h['credit_limit']); ?></dd></div>
            <div><dt>Current balance</dt><dd class="num mono"><?php echo $S($h['current_balance']); ?>
                <span class="hint">(<?php echo htmlspecialchars((string)$label['label'], ENT_QUOTES, 'UTF-8'); ?>)</span></dd></div>
            <div><dt>Available credit</dt><dd class="num mono"><?php echo $S($h['available_credit']); ?></dd></div>
        </dl>

        <form method="GET" action="" class="stmt-toolbar">
            <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
            <div>
                <label for="preset">Date range</label>
                <select id="preset" name="preset">
                    <option value="all"<?php echo $preset === 'all' ? ' selected' : ''; ?>>All</option>
                    <option value="today"<?php echo $preset === 'today' ? ' selected' : ''; ?>>Today</option>
                    <option value="month"<?php echo $preset === 'month' ? ' selected' : ''; ?>>This month</option>
                    <option value="year"<?php echo $preset === 'year' ? ' selected' : ''; ?>>Current year</option>
                    <option value="custom"<?php echo $preset === 'custom' ? ' selected' : ''; ?>>Custom</option>
                </select>
            </div>
            <div>
                <label for="from_date">From</label>
                <input type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="to_date">To</label>
                <input type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="page_size">Per page</label>
                <select id="page_size" name="page_size">
                    <?php foreach ([10, 25, 50, 100] as $ps): ?>
                        <option value="<?php echo $ps; ?>"<?php echo $pageSize === $ps ? ' selected' : ''; ?>><?php echo $ps; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Apply</button>
                <a href="customer_account_statement.php?account_id=<?php echo $accountId; ?>">Reset</a>
            </div>
        </form>

        <div class="stmt-summary">
            <div>Opening: <strong class="mono"><?php echo $S($statement['opening_balance']); ?></strong></div>
            <div>Period debit: <strong class="mono"><?php echo $S($statement['period_debit']); ?></strong></div>
            <div>Period credit: <strong class="mono"><?php echo $S($statement['period_credit']); ?></strong></div>
            <div>Closing: <strong class="mono"><?php echo $S($statement['closing_balance']); ?></strong></div>
        </div>

        <table class="stmt-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Description</th>
                    <th class="num">Debit</th>
                    <th class="num">Credit</th>
                    <th class="num">Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6">No ledger entries for this period.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['entry_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="mono"><?php echo htmlspecialchars((string)$row['reference_display'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['description'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="hint">(<?php echo htmlspecialchars((string)$row['entry_type'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                        </td>
                        <td class="num"><?php echo (float)$row['debit'] != 0.0 ? $S($row['debit']) : ''; ?></td>
                        <td class="num"><?php echo (float)$row['credit'] != 0.0 ? $S($row['credit']) : ''; ?></td>
                        <td class="num mono"><?php echo $S($row['balance']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pager">
            <span>
                Page <?php echo (int)$pager['page']; ?> of <?php echo (int)$pager['total_pages']; ?>
                (<?php echo (int)$pager['total_rows']; ?> row(s))
            </span>
            <?php if ((int)$pager['page'] > 1): ?>
                <a href="<?php echo htmlspecialchars(psStmtQuery($accountId, ['page' => (int)$pager['page'] - 1]), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
            <?php endif; ?>
            <?php if ((int)$pager['page'] < (int)$pager['total_pages']): ?>
                <a href="<?php echo htmlspecialchars(psStmtQuery($accountId, ['page' => (int)$pager['page'] + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
            <?php endif; ?>
            <a href="customer_account_view.php?id=<?php echo $accountId; ?>">Back to account</a>
            <a href="customer_receivables_ageing.php?account_id=<?php echo $accountId; ?>">Ageing</a>
        </div>
    <?php elseif ($signedIn && $accountId < 1): ?>
        <p class="hint">Open a statement from an account, or choose an account first.</p>
        <p><a href="customer_accounts_list.php">Customer accounts</a></p>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
