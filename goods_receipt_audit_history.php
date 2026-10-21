<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/goods_receipt.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureGoodsReceiptAuditSchema($conn);

$error = '';
$rows = [];
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

$filterGrv = trim((string)($_GET['grv_number'] ?? ''));
$filterPoId = (int)($_GET['purchase_order_id'] ?? 0);

if (!$signedIn) {
    $error = 'You must be signed in to view goods receipt audit history.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $result = psGrvAuditFetchHistory($conn, [
            'grv_number' => $filterGrv,
            'purchase_order_id' => $filterPoId,
            'branch' => $branchCheck['branch'],
            'limit' => 300,
        ]);
        if (!$result['ok']) {
            $error = (string)($result['error'] ?? 'Goods receipt audit history could not be loaded.');
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
    <title>Goods Receipt Audit</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Goods Receipt Audit</h1>
    <p class="audit-note">Append-only receiving audit (CREATE_RECEIPT / RECEIVE / CLOSE_PO). No historical backfill.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" class="audit-filters">
            <div>
                <label for="grv_number">GRV number</label>
                <input type="text" id="grv_number" name="grv_number" value="<?php echo htmlspecialchars($filterGrv, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="purchase_order_id">PO ID</label>
                <input type="number" id="purchase_order_id" name="purchase_order_id" min="1"
                       value="<?php echo $filterPoId > 0 ? (int)$filterPoId : ''; ?>">
            </div>
            <div>
                <button type="submit">Filter</button>
            </div>
            <div>
                <a href="goods_receipt_ref.php">Back to receipts</a>
                &nbsp;|&nbsp;
                <a href="grv.php">New receipt</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p>No audit rows match this filter.</p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>GRV</th>
                        <th>PO</th>
                        <th>Action</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>By</th>
                        <th>Branch</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['grv_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
