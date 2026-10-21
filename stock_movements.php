<?php
require 'session_check.php';
require 'pockets_config.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';

// Phase 9.6: authorised registered branches only (not merely ∈ branchies).
$allowed = psSessionStockBranches($conn);

$product = trim((string)($_GET['product'] ?? ''));
$movementType = trim((string)($_GET['movement_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$branch = trim((string)($_GET['branch'] ?? ''));
if (!isset($allowed[$branch])) {
    $resolved = psBranchResolveWorking($conn);
    $branch = !empty($resolved['ok']) ? (string)$resolved['branch'] : '';
    if (!isset($allowed[$branch])) {
        $branch = $allowed ? (string)reset($allowed) : '';
    }
}

$list = listStockMovements($conn, [
    'product' => $product,
    'branch' => $branch,
    'allowed_branches' => array_values($allowed),
    'movement_type' => $movementType,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);
$rows = $list['rows'] ?? [];
$pageError = $list['ok'] ? '' : (string)($list['error'] ?? 'Stock movements could not be loaded.');
$activeBranch = $branch !== '' ? $branch : 'No branch selected';

function psMovementLabel(string $type, string $referenceType = ''): string
{
    return psMovementKindLabel($type, $referenceType);
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Movements</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Stock Movements</h1>
    <p>Branch: <?php echo htmlspecialchars($activeBranch, ENT_QUOTES, 'UTF-8'); ?></p>
    <p>Ledger rows for opening balances, goods receipts, sales, transfers, and adjustments. Product History still shows the earlier receipts, sales, and transfers.</p>

    <form method="GET" action="">
        <input type="text" name="product" placeholder="Item name or product id" value="<?php echo htmlspecialchars($product, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="branch">
            <?php if (!$allowed): ?>
                <option value="">No branch</option>
            <?php else: ?>
                <?php foreach ($allowed as $branchName): ?>
                    <option value="<?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $branchName === $branch ? ' selected' : ''; ?>><?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <select name="movement_type">
            <option value="">All types</option>
            <?php foreach (array_keys(stockMovementTypes()) as $type): ?>
                <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $movementType === $type ? ' selected' : ''; ?>><?php echo htmlspecialchars(psMovementLabel($type), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Search</button>
    </form>

    <?php if ($pageError !== ''): ?>
        <p><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Item</th>
                <th>Branch</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Reference</th>
                <th>User</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="8">No stock movements yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $user = trim((string)($row['user_email'] ?? ''));
                    if ($user === '' && (int)($row['user_id'] ?? 0) > 0) {
                        $user = 'User ' . (int)$row['user_id'];
                    }
                    $reference = trim((string)($row['reference_id'] ?? ''));
                    $referenceType = trim((string)($row['reference_type'] ?? ''));
                    if ($referenceType !== '' && $reference !== '') {
                        $reference = $referenceType . ' ' . $reference;
                    } elseif ($reference === '' && $referenceType !== '') {
                        $reference = $referenceType;
                    }
                    $item = trim((string)($row['item_name'] ?? ''));
                    if ($item === '') {
                        $item = (string)$row['product_id'];
                    }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['movement_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psMovementLabel((string)$row['movement_type'], $referenceType), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php
                            $qtyLabel = psFormatSignedQty($row['quantity']);
                            if ((string)$row['movement_type'] === 'OPENING_BALANCE') {
                                $qtyLabel .= ' OPENING BALANCE';
                            } elseif ((string)$row['movement_type'] === 'GOODS_RECEIPT') {
                                $qtyLabel .= ' GOODS RECEIPT';
                            } elseif ((string)$row['movement_type'] === 'SALE') {
                                $qtyLabel .= ' SALE';
                            } elseif ((string)$row['movement_type'] === 'REFUND') {
                                $qtyLabel .= ' REFUND';
                            } elseif ((string)$row['movement_type'] === 'PURCHASE_RETURN') {
                                $qtyLabel .= ' PURCHASE RETURN';
                            } elseif ((string)$row['movement_type'] === 'TRANSFER_OUT') {
                                $qtyLabel .= ' TRANSFER OUT';
                            } elseif ((string)$row['movement_type'] === 'TRANSFER_IN') {
                                $qtyLabel .= ' TRANSFER IN';
                            } elseif ((string)$row['movement_type'] === 'ADJUSTMENT') {
                                $qtyLabel .= strcasecmp($referenceType, 'STOCK_TAKE') === 0 ? ' STOCK TAKE' : ' ADJUSTMENT';
                            }
                            echo htmlspecialchars($qtyLabel, ENT_QUOTES, 'UTF-8');
                        ?></td>
                        <td><?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
<?php ps_close(); ?>
</body>
</html>
