<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/purchase_order.php';
require_once __DIR__ . '/includes/goods_receipt.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsurePurchaseOrderAllSchema($conn);
psEnsureGoodsReceiptAllSchema($conn);

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$statusFilterRaw = array_key_exists('status', $_GET)
    ? (string)$_GET['status']
    : (array_key_exists('status', $_POST) ? (string)$_POST['status'] : 'all');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'draft', 'approved', 'cancelled', 'closed'], true)) {
    $statusFilterKey = 'all';
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$error = '';
$notice = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$branch = '';

if (!empty($_GET['created'])) {
    $notice = 'Purchase order saved as draft.';
}
if (!empty($_GET['updated'])) {
    $notice = 'Purchase order updated.';
}
if (!empty($_GET['approved'])) {
    $notice = 'Purchase order approved.';
}
if (!empty($_GET['cancelled'])) {
    $notice = 'Purchase order cancelled.';
}

$rows = [];
if (!$signedIn) {
    $error = 'You must be signed in to view purchase orders.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $list = psPurchaseOrderList($conn, [
            'search' => $search,
            'status' => $statusFilterKey,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        if (!$list['ok']) {
            $error = (string)($list['error'] ?? 'Purchase orders could not be loaded.');
        } else {
            $rows = $list['rows'] ?? [];
            foreach ($rows as $idx => $row) {
                $lineProg = psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, (int)$row['id']));
                $ord = '0';
                $rec = '0';
                $rem = '0';
                foreach ($lineProg as $lp) {
                    if (function_exists('bcadd')) {
                        $ord = bcadd($ord, (string)$lp['ordered_qty'], 3);
                        $rec = bcadd($rec, (string)$lp['received_qty'], 3);
                        $rem = bcadd($rem, (string)$lp['remaining_qty'], 3);
                    } else {
                        $ord = (string)((float)$ord + (float)$lp['ordered_qty']);
                        $rec = (string)((float)$rec + (float)$lp['received_qty']);
                        $rem = (string)((float)$rem + (float)$lp['remaining_qty']);
                    }
                }
                $rows[$idx]['_ordered'] = $ord;
                $rows[$idx]['_received'] = $rec;
                $rows[$idx]['_remaining'] = $rem;
                $rows[$idx]['_can_receive'] = strtoupper(trim((string)($row['status'] ?? ''))) === PS_PO_STATUS_APPROVED
                    && !psPoLinesFullyReceived($lineProg);
            }
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
    <title>Purchase Orders</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Purchase Orders</h1>
    <p class="hint">
        Purchase orders do not increase stock until goods are received (GRV).
        Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : '(none)', ENT_QUOTES, 'UTF-8'); ?>
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $branch !== ''): ?>
        <form method="GET" action="" class="po-toolbar">
            <div>
                <label for="search">Search</label>
                <input type="search" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="PO number, supplier…">
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['all' => 'All', 'draft' => 'Draft', 'approved' => 'Approved', 'cancelled' => 'Cancelled', 'closed' => 'Closed'] as $val => $label): ?>
                        <option value="<?php echo $val; ?>"<?php echo $statusFilterKey === $val ? ' selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
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
                <button type="submit">Filter</button>
            </div>
            <div>
                <a href="create_purchase_order.php">Create purchase order</a>
                &nbsp;|&nbsp;
                <a href="purchase_order_audit_history.php">PO audit</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p>No purchase orders match this filter.</p>
        <?php else: ?>
            <table class="po-table">
                <thead>
                    <tr>
                        <th>PO number</th>
                        <th>Supplier</th>
                        <th>Order date</th>
                        <th>Expected</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Receive progress</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = strtoupper(trim((string)($row['status'] ?? '')));
                    $statusClass = 'status-' . strtolower($status);
                    $supplierLabel = trim((string)($row['supplier_code'] ?? '')) . ' — ' . trim((string)($row['supplier_name'] ?? ''));
                    $ord = (string)($row['_ordered'] ?? '0');
                    $rec = (string)($row['_received'] ?? '0');
                    $rem = (string)($row['_remaining'] ?? '0');
                    $canReceive = !empty($row['_can_receive']);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['po_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($supplierLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['order_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['expected_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(psPoStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($ord . ' / ' . $rec . ' / ' . $rem, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a href="purchase_order_view.php?id=<?php echo (int)$row['id']; ?>">View</a>
                            <?php if ($canReceive): ?>
                                &nbsp;|&nbsp;
                                <a href="grv.php?mode=po&amp;po_id=<?php echo (int)$row['id']; ?>">Receive</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
