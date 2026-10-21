<?php
/**
 * Goods receipt lines for one GRV reference (linked from goods_receipt_ref.php "View lines").
 *
 * Health-check fix 2026-10-13: previously unauthenticated, matched invoice numbers with LIKE
 * across every branch. Now: login + authorised working branch (same gate as the GRV list),
 * exact reference match, prepared statement, shared layout.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
psBranchRequireAuthRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    error_log('goods_receipt: connection failed');
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

$gate = psRequireSessionProductBranch($conn);
if (empty($gate['ok'])) {
    die(htmlspecialchars((string)($gate['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$branch = (string)$gate['branch'];

$invoice_number = trim((string)($_GET['invoice_number'] ?? ''));
$receipts_data = [];
$pageError = '';
if ($invoice_number === '') {
    $pageError = 'No goods receipt reference was provided.';
} else {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT product_id, product, quantity, invoice_number, supplier_name, date_
           FROM goods_receipts
          WHERE invoice_number = ? AND branch = ?
          ORDER BY id'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $invoice_number, $branch);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $receipts_data[] = $row;
            }
        } else {
            $pageError = 'The goods receipt could not be loaded.';
        }
        mysqli_stmt_close($stmt);
    } else {
        $pageError = 'The goods receipt could not be loaded.';
    }
}
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Receipt Lines</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Goods Receipt Lines'); ?>
<h1>Goods Receipt Lines</h1>
<p class="ps-page-lead">Reference <?php echo ps_h($invoice_number !== '' ? $invoice_number : '—'); ?> · <?php echo ps_h($branch); ?></p>
<div class="ps-link-row">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="goods_receipt_ref.php">Back to Goods Receipt</a>
</div>
<?php if ($pageError !== ''): ?>
    <div class="ps-alert ps-alert-destructive"><?php echo ps_h($pageError); ?></div>
<?php endif; ?>
<div class="ps-card"><div class="ps-card-content">
    <div class="ps-table-wrap">
        <table class="ps-table">
            <thead>
                <tr>
                    <th>Product ID</th>
                    <th>Product</th>
                    <th class="num">Quantity</th>
                    <th>Reference</th>
                    <th>Supplier</th>
                    <th>Date received</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($receipts_data)): ?>
                <tr><td colspan="6">No lines found for this goods receipt on this branch.</td></tr>
            <?php else: ?>
                <?php foreach ($receipts_data as $receipt): ?>
                    <tr>
                        <td><?php echo ps_h($receipt['product_id']); ?></td>
                        <td><?php echo ps_h($receipt['product']); ?></td>
                        <td class="num"><?php echo ps_h($receipt['quantity']); ?></td>
                        <td><?php echo ps_h($receipt['invoice_number']); ?></td>
                        <td><?php echo ps_h($receipt['supplier_name']); ?></td>
                        <td><?php echo ps_h($receipt['date_']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div></div>
<?php ps_close(); ?>
</body>
</html>
