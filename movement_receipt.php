<?php
/**
 * Stock transfer document. Health-check fix 2026-10-13: login required and the
 * caller must be authorised for the sending or receiving branch.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);
psBranchRequireAuthRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

$invoice = trim((string)($_GET['invoice_number'] ?? ''));
$sending = trim((string)($_GET['sending_branch'] ?? ''));
$receiving = trim((string)($_GET['receiving_branch'] ?? ''));
$header = null;
$lines = [];
$pageError = '';

if ($invoice === '' && $sending === '' && $receiving === '') {
    $pageError = 'No transfer reference provided.';
} elseif (!psBranchSaleBranchAuthorised($conn, $sending) && !psBranchSaleBranchAuthorised($conn, $receiving)) {
    // Same generic message as not-found: no existence leak for other branches.
    $pageError = 'The stock transfer could not be loaded.';
} else {
    $headerSql = 'SELECT product, reference1, status, date_, payment_mode, totalz, vat, branch, sending_branch
        FROM movements_receipts_ref
        WHERE reference1 = ? AND sending_branch = ? AND branch = ?
        LIMIT 1';
    $headerStmt = mysqli_prepare($conn, $headerSql);
    if (!$headerStmt) {
        $pageError = 'The stock transfer could not be loaded.';
    } else {
        mysqli_stmt_bind_param($headerStmt, 'sss', $invoice, $sending, $receiving);
        if (!mysqli_stmt_execute($headerStmt)) {
            $pageError = 'The stock transfer could not be loaded.';
        } else {
            $headerResult = mysqli_stmt_get_result($headerStmt);
            $header = $headerResult ? mysqli_fetch_assoc($headerResult) : null;
        }
        mysqli_stmt_close($headerStmt);
    }

    if ($pageError === '') {
        $lineSql = 'SELECT product_id, product, quantity, date_, invoice_number, supplier_name, status, vat, line_cost, branch, sending_branch, receiving_branch
            FROM movements_receipts
            WHERE invoice_number = ? AND sending_branch = ? AND receiving_branch = ?
            ORDER BY id';
        $lineStmt = mysqli_prepare($conn, $lineSql);
        if (!$lineStmt) {
            $pageError = 'The stock transfer could not be loaded.';
        } else {
            mysqli_stmt_bind_param($lineStmt, 'sss', $invoice, $sending, $receiving);
            if (!mysqli_stmt_execute($lineStmt)) {
                $pageError = 'The stock transfer could not be loaded.';
            } else {
                $lineResult = mysqli_stmt_get_result($lineStmt);
                if ($lineResult) {
                    while ($row = mysqli_fetch_assoc($lineResult)) {
                        $lines[] = $row;
                    }
                }
            }
            mysqli_stmt_close($lineStmt);
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
    <title>Stock Transfer</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Stock Transfer</h1>
    <?php if ($pageError !== ''): ?>
        <p><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif (!$header && !$lines): ?>
        <p>No details found for this transfer.</p>
    <?php else: ?>
        <?php if ($header): ?>
            <p>Reference: <?php echo htmlspecialchars((string)$header['reference1'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Date: <?php echo htmlspecialchars((string)$header['date_'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Status: <?php echo htmlspecialchars((string)$header['status'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Supplier: <?php echo htmlspecialchars((string)$header['product'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Sending branch: <?php echo htmlspecialchars((string)$header['sending_branch'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Receiving branch: <?php echo htmlspecialchars((string)$header['branch'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Total: <?php echo htmlspecialchars((string)$header['totalz'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>VAT: <?php echo htmlspecialchars((string)$header['vat'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>Product ID</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Invoice Number</th>
                    <th>Sending Branch</th>
                    <th>Receiving Branch</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$lines): ?>
                    <tr>
                        <td colspan="8">No lines found for this transfer.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$line['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['product'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['sending_branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['receiving_branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['date_'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$line['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <button type="button" onclick="window.location.href='movement_list.php'">Back</button>
<?php ps_close(); ?>
</body>
</html>
