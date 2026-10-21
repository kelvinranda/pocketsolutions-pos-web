<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/purchase_order.php';
require_once __DIR__ . '/includes/goods_receipt.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsurePurchaseOrderAllSchema($conn);
psEnsureGoodsReceiptAllSchema($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$notice = '';
$id = (int)($_GET['id'] ?? $_POST['po_id'] ?? 0);
$header = null;
$lines = [];
$supplier = null;
$printMode = !empty($_GET['print']);

if (!$signedIn) {
    $error = 'You must be signed in to view purchase orders.';
} elseif ($id < 1) {
    $error = 'Purchase order not found.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

        $action = trim((string)($_POST['po_action'] ?? ''));
        if ($action === 'approve') {
            $result = psApprovePurchaseOrder($conn, $id);
            if ($result['ok']) {
                header('Location: purchase_orders_list.php?approved=1');
                exit;
            }
            $error = (string)($result['error'] ?? 'Approve failed.');
        } elseif ($action === 'cancel') {
            $result = psCancelPurchaseOrder($conn, $id);
            if ($result['ok']) {
                header('Location: purchase_orders_list.php?cancelled=1');
                exit;
            }
            $error = (string)($result['error'] ?? 'Cancel failed.');
        } else {
            $error = 'That action is not allowed.';
        }
    
    }
}

    $load = psPurchaseOrderLoadForSession($conn, $id);
    if (!$load['ok']) {
        $error = $error !== '' ? $error : (string)($load['error'] ?? 'Purchase order not found.');
    } else {
        $header = $load['header'];
        $lines = psPoLinesWithReceived($conn, $load['lines']);
        $supplier = psSupplierById($conn, (int)$header['supplier_id']);
    }
}

$grandTotal = '0.00';
if ($lines !== []) {
    $sum = '0';
    foreach ($lines as $line) {
        if (function_exists('bcadd')) {
            $sum = bcadd($sum, (string)$line['line_total'], 2);
        } else {
            $sum = number_format(((float)$sum) + ((float)$line['line_total']), 2, '.', '');
        }
    }
    $grandTotal = $sum;
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order<?php echo $header ? ' ' . htmlspecialchars((string)$header['po_number'], ENT_QUOTES, 'UTF-8') : ''; ?></title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app<?php echo $printMode ? ' po-print' : ''; ?>">
<?php ps_open(); ?>
    <div class="po-view">
        <h1>Purchase Order</h1>
        <p class="hint">Purchase order document. Approving does not increase stock; receiving posts GOODS_RECEIPT movements.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($header): ?>
            <?php
            $status = strtoupper(trim((string)$header['status']));
            $statusClass = 'status-' . strtolower($status);
            $supCode = $supplier ? (string)$supplier['supplier_code'] : '';
            $supName = $supplier ? (string)$supplier['supplier_name'] : '';
            ?>
            <div class="po-meta">
                <div><strong>PO number:</strong> <?php echo htmlspecialchars((string)$header['po_number'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Status:</strong> <span class="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(psPoStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div><strong>Supplier:</strong> <?php echo htmlspecialchars(trim($supCode . ' — ' . $supName, ' —'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Branch:</strong> <?php echo htmlspecialchars((string)$header['branch'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Order date:</strong> <?php echo htmlspecialchars((string)$header['order_date'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Expected:</strong> <?php echo htmlspecialchars((string)($header['expected_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Created by:</strong> <?php echo htmlspecialchars((string)$header['created_by'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div><strong>Updated:</strong> <?php echo htmlspecialchars((string)$header['updated_at'] . ' / ' . (string)$header['updated_by'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php if (trim((string)$header['notes']) !== ''): ?>
                <p><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars((string)$header['notes'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>

            <table class="po-table">
                <thead>
                    <tr>
                        <th>Product ID</th>
                        <th>Description</th>
                        <th class="num">Ordered</th>
                        <th class="num">Received</th>
                        <th class="num">Remaining</th>
                        <th class="num">Unit cost</th>
                        <th class="num">Line total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$line['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$line['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo htmlspecialchars((string)($line['ordered_qty'] ?? $line['quantity']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo htmlspecialchars((string)($line['received_qty'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo htmlspecialchars((string)($line['remaining_qty'] ?? $line['quantity']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo htmlspecialchars((string)$line['unit_cost'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo htmlspecialchars((string)$line['line_total'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6" class="num">Order total</th>
                        <th class="num"><?php echo htmlspecialchars($grandTotal . ' ' . (string)(($header['currency'] ?? '') !== '' ? $header['currency'] : ''), ENT_QUOTES, 'UTF-8'); ?></th>
                    </tr>
                </tfoot>
            </table>

            <div class="po-actions">
                <a href="purchase_orders_list.php">Back to list</a>
                <a href="purchase_order_view.php?id=<?php echo (int)$id; ?>&amp;print=1" onclick="window.print(); return false;">Print</a>
                <a href="purchase_order_audit_history.php?purchase_order_id=<?php echo (int)$id; ?>">Audit</a>
                <?php
                $canReceive = $status === PS_PO_STATUS_APPROVED && !psPoLinesFullyReceived($lines);
                if ($canReceive):
                ?>
                    <a href="grv.php?mode=po&amp;po_id=<?php echo (int)$id; ?>">Receive</a>
                <?php endif; ?>
                <?php if (psPoIsEditableStatus($status)): ?>
                    <a href="create_purchase_order.php?id=<?php echo (int)$id; ?>">Edit draft</a>
                    <form method="POST" action="" style="display:inline;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <input type="hidden" name="po_id" value="<?php echo (int)$id; ?>">
                        <input type="hidden" name="po_action" value="approve">
                        <button type="submit">Approve</button>
                    </form>
                <?php endif; ?>
                <?php if (psPoCanCancel($status)): ?>
                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Cancel this purchase order?');">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <input type="hidden" name="po_id" value="<?php echo (int)$id; ?>">
                        <input type="hidden" name="po_action" value="cancel">
                        <button type="submit">Cancel PO</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php ps_close(); ?>
</body>
</html>
