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
$admin = function_exists('psSessionAllowsAllProductBranches') && psSessionAllowsAllProductBranches();

$accountId = (int)($_GET['account_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
$asAt = trim((string)($_GET['as_at'] ?? date('Y-m-d')));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$accountStatus = strtolower(trim((string)($_GET['account_status'] ?? 'all')));
if (!in_array($accountStatus, ['all', 'active', 'suspended', 'closed'], true)) {
    $accountStatus = 'all';
}
$customerStatus = strtolower(trim((string)($_GET['customer_status'] ?? 'all')));
if (!in_array($customerStatus, ['all', 'active', 'inactive'], true)) {
    $customerStatus = 'all';
}

// Ignore posted financial / bucket fields.
unset(
    $_GET['balance'],
    $_GET['outstanding'],
    $_GET['current'],
    $_GET['1_30'],
    $_GET['31_60'],
    $_GET['61_90'],
    $_GET['91_plus'],
    $_GET['total']
);

$ageing = null;
$branchOptions = [];
if (!$signedIn) {
    $error = 'You must be signed in to view receivables ageing.';
} else {
    $branchOptions = psAuthorisedProductBranches($conn);
    $ageing = psCustomerReceivablesAgeing($conn, [
        'customer_account_id' => $accountId,
        'customer_master_id' => $customerId,
        'search' => $search,
        'as_at' => $asAt,
        'branch' => $admin ? $branchFilter : null,
        'account_status' => $accountStatus === 'all' ? 'all' : strtoupper($accountStatus),
        'customer_status' => $customerStatus === 'all' ? 'all' : strtoupper($customerStatus),
        'detail' => false,
    ]);
    if (!$ageing['ok']) {
        $error = (string)($ageing['error'] ?? 'Receivables ageing could not be loaded.');
        $ageing = null;
    } else {
        $asAt = (string)$ageing['as_at'];
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receivables Ageing</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Receivables Ageing'); ?>
    <h1>Receivables Ageing</h1>
    <p class="hint">
        Sale-level outstanding only (CREDIT_SALE debit − ACTIVE allocations). Fully paid excluded.
        Due date = CREDIT_SALE entry date (sale/charge date) — no invented credit terms.
        Unallocated payments reduce account balance but not sale outstanding until allocated.
        National ID is not shown. Export is not available on this screen.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="age-toolbar">
            <div>
                <label for="search">Customer / code / account</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search">
            </div>
            <div>
                <label for="account_id">Account id</label>
                <input type="number" id="account_id" name="account_id" value="<?php echo $accountId > 0 ? $accountId : ''; ?>" min="1">
            </div>
            <div>
                <label for="as_at">As-at date</label>
                <input type="date" id="as_at" name="as_at" value="<?php echo htmlspecialchars($asAt, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="account_status">Account status</label>
                <select id="account_status" name="account_status">
                    <?php foreach (['all' => 'All', 'active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'] as $k => $lab): ?>
                        <option value="<?php echo $k; ?>"<?php echo $accountStatus === $k ? ' selected' : ''; ?>><?php echo $lab; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="customer_status">Customer status</label>
                <select id="customer_status" name="customer_status">
                    <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $k => $lab): ?>
                        <option value="<?php echo $k; ?>"<?php echo $customerStatus === $k ? ' selected' : ''; ?>><?php echo $lab; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($admin): ?>
                <div>
                    <label for="branch">Sale branch</label>
                    <select id="branch" name="branch">
                        <option value="all"<?php echo ($branchFilter === '' || strcasecmp($branchFilter, 'all') === 0) ? ' selected' : ''; ?>>All branches</option>
                        <?php foreach ($branchOptions as $bn): ?>
                            <?php $bn = (string)$bn; ?>
                            <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <button type="submit">Apply</button>
                <a href="customer_receivables_ageing.php">Reset</a>
            </div>
        </form>

        <?php if ($ageing): ?>
            <p class="hint">
                As-at <?php echo htmlspecialchars((string)$ageing['as_at'], ENT_QUOTES, 'UTF-8'); ?>.
                <?php echo htmlspecialchars((string)$ageing['due_date_source'], ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <div class="mgmt">
                <div>Total Credit Sales Outstanding:
                    <strong class="mono"><?php echo htmlspecialchars((string)$ageing['management']['total_credit_sales_outstanding'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>Total Customer Account Balance (ledger as-at):
                    <strong class="mono"><?php echo htmlspecialchars((string)$ageing['management']['total_customer_account_balance'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <p class="hint"><?php echo htmlspecialchars((string)$ageing['management']['note'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if (!empty($ageing['fx_note'])): ?><p class="hint"><?php echo htmlspecialchars((string)$ageing['fx_note'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <?php foreach (($ageing['fx_missing'] ?? []) as $mc => $msg): ?>
                    <p class="error"><?php echo htmlspecialchars($mc . ' balances are left out of the ' . (string)($ageing['base_currency'] ?? '') . ' totals: ' . $msg, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endforeach; ?>
            </div>

            <table class="age-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Account</th>
                        <th class="num">Current</th>
                        <th class="num">1–30</th>
                        <th class="num">31–60</th>
                        <th class="num">61–90</th>
                        <th class="num">91+</th>
                        <th class="num">Total Outstanding</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rows = $ageing['rows'] ?? [];
                if ($rows === []):
                ?>
                    <tr><td colspan="9">No outstanding credit sales for this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $b = $row['buckets']; ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars((string)$row['customer_code'] . ' — ' . (string)$row['customer_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="hint">(<?php echo htmlspecialchars((string)$row['customer_status'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                            </td>
                            <td class="mono">
                                <?php echo htmlspecialchars((string)$row['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="hint">(<?php echo htmlspecialchars(psCustomerAccountStatusLabel((string)$row['account_status']), ENT_QUOTES, 'UTF-8'); ?>)</span>
                                <?php if (!empty($row['currency'])): ?><b><?php echo htmlspecialchars((string)$row['currency'], ENT_QUOTES, 'UTF-8'); ?></b><?php endif; ?>
                            </td>
                            <td class="num"><?php echo htmlspecialchars((string)$b['current'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$b['1_30'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$b['31_60'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$b['61_90'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$b['91_plus'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num mono"><?php echo htmlspecialchars((string)$b['total'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <a href="customer_account_view.php?id=<?php echo (int)$row['customer_account_id']; ?>">Account</a>
                                ·
                                <a href="customer_account_statement.php?account_id=<?php echo (int)$row['customer_account_id']; ?>">Statement</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if ($ageing && !empty($ageing['totals'])): ?>
                    <?php $t = $ageing['totals']; ?>
                    <tfoot>
                        <?php if (count($ageing['totals_by_currency'] ?? []) > 1 || array_keys($ageing['totals_by_currency'] ?? []) !== [($ageing['base_currency'] ?? '')]): ?>
                            <?php foreach (($ageing['totals_by_currency'] ?? []) as $tcur => $tc): ?>
                                <tr class="hint">
                                    <td colspan="2">Totals in <?php echo htmlspecialchars((string)$tcur, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php foreach (['current', '1_30', '31_60', '61_90', '91_plus', 'total'] as $bk): ?>
                                        <td class="num"><?php echo htmlspecialchars((string)$tc[$bk], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endforeach; ?>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr>
                            <td colspan="2">Totals<?php echo !empty($ageing['base_currency']) ? ' (' . htmlspecialchars((string)$ageing['base_currency'], ENT_QUOTES, 'UTF-8') . ')' : ''; ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$t['current'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$t['1_30'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$t['31_60'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$t['61_90'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$t['91_plus'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num mono"><?php echo htmlspecialchars((string)$t['total'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>

            <?php if (!empty($ageing['as_at_notes'])): ?>
                <ul class="hint">
                    <?php foreach ($ageing['as_at_notes'] as $note): ?>
                        <li><?php echo htmlspecialchars((string)$note, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
