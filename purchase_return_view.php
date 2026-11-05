<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/purchase_return.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsurePurchaseReturnAllSchema($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$notice = '';
$id = (int)($_GET['id'] ?? 0);
$header = null;
$lines = [];

if (!empty($_GET['created'])) {
    $notice = 'Purchase return completed. Stock was reversed once.';
}

if (!$signedIn) {
    $error = 'You must be signed in to view purchase returns.';
} else {
    $load = psPurchaseReturnLoadForSession($conn, $id);
    if (!$load['ok']) {
        $error = (string)($load['error'] ?? 'Purchase return not found.');
    } else {
        $header = $load['header'];
        $lines = $load['lines'];
    }
}

require_once __DIR__ . '/includes/currency.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Return</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Purchase Return</h1>
    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($header): ?>
        <div class="meta">
            <div><strong><?php echo htmlspecialchars((string)$header['return_number'], ENT_QUOTES, 'UTF-8'); ?></strong>
                (<?php echo htmlspecialchars((string)$header['status'], ENT_QUOTES, 'UTF-8'); ?>)</div>
            <div>GRV: <?php echo htmlspecialchars((string)$header['grv_number'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($header['po_number'])): ?>
                    | PO: <?php echo htmlspecialchars((string)$header['po_number'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
            <div>Supplier: <?php echo htmlspecialchars((string)$header['supplier_name'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($header['supplier_id'])): ?>
                    (id <?php echo (int)$header['supplier_id']; ?>)
                <?php endif; ?>
            </div>
            <div>Branch: <?php echo htmlspecialchars((string)$header['branch'], ENT_QUOTES, 'UTF-8'); ?>
                | Date: <?php echo htmlspecialchars((string)$header['return_date'], ENT_QUOTES, 'UTF-8'); ?>
                | Total: <?php $prCur = psFxNormalizeCode($header['currency'] ?? '') ?: psFxLegacyCurrency(); echo htmlspecialchars(psFxFormat($conn, $header['total'], $prCur), ENT_QUOTES, 'UTF-8'); ?><?php if (($header['base_total'] ?? null) !== null && $prCur !== psFxBase($conn, psFxSessionCompany())): ?> (<?php echo htmlspecialchars(psFxFormat($conn, $header['base_total'], psFxBase($conn, psFxSessionCompany())), ENT_QUOTES, 'UTF-8'); ?> at the receipt's stored rate)<?php endif; ?>
            </div>
            <div>User: <?php echo htmlspecialchars((string)$header['created_by'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div>Reason: <?php echo htmlspecialchars((string)$header['reason'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <p class="hint">Returned quantities are taken off stock. The purchase order is not changed.</p>
        <table>
            <thead>
                <tr>
                    <th>Source GRV line</th>
                    <th>Product ID</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Unit cost</th>
                    <th>Line total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?php echo (int)$line['goods_receipt_line_id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$line['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$line['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$line['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psFxFormat($conn, $line['unit_cost'], $prCur), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psFxFormat($conn, $line['line_total'], $prCur), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <a href="purchase_returns_list.php">Back to list</a>
            | <a href="purchase_return_audit_history.php?return_number=<?php echo rawurlencode((string)$header['return_number']); ?>">Audit</a>
        </p>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
