<?php
/**
 * Phase 9.6 — Receipt list gated to authorised registered working branch.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/customer_master.php';
require_once __DIR__ . '/includes/cash_controls.php';

psBranchRequireAuthRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSaleIntegritySchema($conn);

$scope = psBranchRequireAuthorisedWorking($conn);
if (empty($scope['ok'])) {
    die(htmlspecialchars((string)($scope['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$workingBranch = (string)$scope['branch'];

$search = '';
$date_search = '';
$receipts_data = [];
$limit = 10;
$branchEsc = mysqli_real_escape_string($conn, $workingBranch);
// 2026-10-17: Till users see only their own sales; Supervisor / Management / admin see the branch.
$mineOnly = psCashControlsIsTillUser();
$mineSql = $mineOnly ? " AND cashier = '" . mysqli_real_escape_string($conn, psCashControlsUser()) . "'" : '';

if (isset($_POST['search']) || isset($_POST['date_search'])) {
    $search = mysqli_real_escape_string($conn, (string)($_POST['search'] ?? ''));
    $date_search = mysqli_real_escape_string($conn, (string)($_POST['date_search'] ?? ''));
    $sql = "SELECT * FROM online_sales_ref WHERE branch='$branchEsc'$mineSql AND customer LIKE '%$search%' AND date_ LIKE '%$date_search%' ORDER BY date_ DESC LIMIT 200";
} else {
    $sql = "SELECT * FROM online_sales_ref WHERE branch='$branchEsc'$mineSql ORDER BY date_ DESC LIMIT $limit";
}

$result = mysqli_query($conn, $sql);

if ($result) {
    $rawRows = [];
    $masterIds = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $masterId = isset($row['customer_master_id']) ? (int)$row['customer_master_id'] : 0;
        if ($masterId > 0) {
            $masterIds[] = $masterId;
        }
        $rawRows[] = $row;
    }
    $masters = $masterIds !== [] ? psCustomersByIds($conn, $masterIds) : [];
    foreach ($rawRows as $row) {
        $masterId = isset($row['customer_master_id']) ? (int)$row['customer_master_id'] : 0;
        if ($masterId > 0 && isset($masters[$masterId])) {
            $row['customer'] = psCustomerDisplayName($masters[$masterId]);
        }
        $receipts_data[] = $row;
    }
} else {
    error_log('all_receipts: query failed');
    echo 'Receipts could not be loaded.';
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Receipts</h1>
    <p>Branch: <?php echo htmlspecialchars($workingBranch, ENT_QUOTES, 'UTF-8'); ?></p>

    <form method="POST" action="">
        <label for="search">Customer</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="date_search">Date</label>
        <input type="text" id="date_search" name="date_search" value="<?php echo htmlspecialchars($date_search, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Search</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Date</th>
                <th>Cashier</th>
                <th>Method</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($receipts_data): ?>
                <?php foreach ($receipts_data as $row): ?>
                    <?php
                    $inv = (string)($row['invoice_number'] ?? '');
                    $invUrl = 'my_receipt.php?invoice_number=' . rawurlencode($inv);
                    $printUrl = 'receipt_print.php?invoice_number=' . rawurlencode($inv);
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo htmlspecialchars($invUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($inv, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            ·
                            <a href="<?php echo htmlspecialchars($printUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Print</a>
                        </td>
                        <td><?php echo htmlspecialchars((string)($row['customer'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['totalz'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['date_'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['cashier'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7">No receipts found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
<?php ps_close(); ?>
</body>
</html>
