<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

psBranchRequireAuthRedirect();
$mlReport = psBranchReportScope($conn);
if (empty($mlReport['ok']) || empty($mlReport['branches'])) {
    die(htmlspecialchars((string)($mlReport['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$mlIn = [];
foreach ($mlReport['branches'] as $bn) {
    $bn = psBranchNormalizeName($bn);
    if ($bn !== '') {
        $mlIn[] = "'" . mysqli_real_escape_string($conn, $bn) . "'";
    }
}
if ($mlIn === []) {
    die('That branch is not available.');
}
$mlInSql = implode(',', $mlIn);
$mlBranchFilter = "(sending_branch IN ($mlInSql) OR branch IN ($mlInSql))";

// Initialize variables
$date = $supplier_name = $invoice_number = "";  
$receipts_data = [];

// Phase 9.6: scope transfers to authorised registered branches (send or receive).
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!function_exists('psCsrfValidate')) {
        require_once __DIR__ . '/includes/security.php';
    }
    if (!psCsrfValidate()) {
        echo 'Invalid security token. Reload and try again.';
        exit;
    }
    $date = mysqli_real_escape_string($conn, (string)($_POST['date'] ?? ''));
    $supplier_name = mysqli_real_escape_string($conn, (string)($_POST['supplier_name'] ?? ''));
    $invoice_number = mysqli_real_escape_string($conn, (string)($_POST['invoice_number'] ?? ''));

    $sql = "SELECT * FROM movements_receipts_ref WHERE $mlBranchFilter";

    if ($date !== '') {
        $sql .= " AND date_ = '$date'";
    }
    if ($supplier_name !== '') {
        $sql .= " AND reference1 LIKE '%$supplier_name%'";
    }
    if ($invoice_number !== '') {
        $sql .= " AND reference1 LIKE '%$invoice_number%'";
    }
} else {
    $sql = "SELECT * FROM movements_receipts_ref WHERE $mlBranchFilter";
}

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $receipts_data[] = $row;
    }
} else {
    echo "Stock transfers could not be loaded.";
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Transfers</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Stock Transfers</h1>
    <p>Branch:<?php  echo $_SESSION['selected_branch'];                ?></p>

    <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
        <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>">
        <input type="text" name="supplier_name" placeholder="Supplier Name" value="<?php echo htmlspecialchars($supplier_name); ?>">
        <input type="text" name="invoice_number" placeholder="Invoice Number" value="<?php echo htmlspecialchars($invoice_number); ?>">
        <button type="submit">Search</button>
    </form>

    <button class="new-order-button" onclick="window.location.href='stock_movement.php'">New Stock Transfer</button>
    <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Reference</th>
                <th>Status</th>
                <th>Date Received</th>
                
                <th>Total</th>
                <th>VAT</th>
                <th>Receiving Branch</th>
                <th>Action</th> <!-- Added Action column -->
            </tr>
        </thead>
        <tbody>
            <?php if (empty($receipts_data)): ?>
                <tr>
                    <td colspan="9">No goods receipts found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($receipts_data as $receipt): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($receipt['product']); ?></td>
                        <td><?php echo htmlspecialchars($receipt['reference1']); ?></td>
                        <td><?php echo htmlspecialchars($receipt['status']); ?></td>
                        <td><?php echo htmlspecialchars($receipt['date_']); ?></td>
                        
                        <td>$<?php echo htmlspecialchars($receipt['totalz']); ?></td>
                        <td>$<?php echo htmlspecialchars($receipt['vat']); ?></td>
                        <td><?php echo htmlspecialchars($receipt['branch']); ?></td>
                        <td>
                            <?php
                            $transferView = 'movement_receipt.php?invoice_number=' . urlencode((string)$receipt['reference1'])
                                . '&sending_branch=' . urlencode((string)($receipt['sending_branch'] ?? ''))
                                . '&receiving_branch=' . urlencode((string)($receipt['branch'] ?? ''));
                            ?>
                            <button type="button" onclick="window.location.href=<?php echo htmlspecialchars(json_encode($transferView), ENT_QUOTES, 'UTF-8'); ?>">View More</button> 
                        </td> <!-- View More Button -->
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
<?php ps_close(); ?>
</body>
</html>