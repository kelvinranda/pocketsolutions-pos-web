<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/purchase_order.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsurePurchaseOrderAuditSchema($conn);

$error = '';
$rows = [];
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

$filterPoId = (int)($_GET['purchase_order_id'] ?? 0);
$filterPoNumber = trim((string)($_GET['po_number'] ?? ''));
$branchFilter = '';

if (!$signedIn) {
    $error = 'You must be signed in to view purchase order audit history.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branchFilter = $branchCheck['branch'];
        $result = psPoAuditFetchHistory($conn, [
            'purchase_order_id' => $filterPoId,
            'po_number' => $filterPoNumber,
            'branch' => $branchFilter,
            'limit' => 300,
        ]);
        if (!$result['ok']) {
            $error = (string)($result['error'] ?? 'Purchase order audit history could not be loaded.');
        } else {
            $rows = $result['rows'] ?? [];
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
    <title>Purchase Order Audit History</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Purchase Order Audit History</h1>
    <p class="audit-note">
        Append-only CREATE / UPDATE / APPROVE / CANCEL for purchase orders on the current branch.
        Audit rows cannot be edited or deleted from this screen. No historical backfill.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $branchFilter !== ''): ?>
        <form method="GET" action="" class="audit-filters">
            <div>
                <label for="purchase_order_id">PO ID</label>
                <input type="number" id="purchase_order_id" name="purchase_order_id" min="1"
                       value="<?php echo $filterPoId > 0 ? (int)$filterPoId : ''; ?>">
            </div>
            <div>
                <label for="po_number">PO number</label>
                <input type="text" id="po_number" name="po_number"
                       value="<?php echo htmlspecialchars($filterPoNumber, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <button type="submit">Filter</button>
                <a href="purchase_orders_list.php">Back to purchase orders</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p class="empty">No audit rows for this filter.</p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>PO number</th>
                        <th>PO ID</th>
                        <th>Branch</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>By</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['po_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$row['purchase_order_id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
