<?php
require 'session_check.php';
require 'pockets_config.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_master.php';

// Phase 9.6: authorised registered working branch (not raw selected_branch).
$branchGate = psRequireSessionProductBranch($conn);
$branch = !empty($branchGate['ok']) ? (string)$branchGate['branch'] : '';
$filter = trim((string)($_GET['status'] ?? ''));
$allowedFilters = [
    '' => 'All',
    'OUT_OF_STOCK' => 'Out of stock',
    'LOW_STOCK' => 'Low stock',
    'IN_STOCK' => 'In stock',
    'NO_REORDER_LEVEL' => 'No reorder level',
];
if (!isset($allowedFilters[$filter])) {
    $filter = '';
}

$rows = $branch === '' ? [] : psInventoryControlRows($conn, $branch);
if ($filter !== '') {
    $rows = array_values(array_filter($rows, function (array $row) use ($filter): bool {
        return $row['status'] === $filter;
    }));
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Control</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Inventory Control</h1>
    <p>Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : 'No branch selected', ENT_QUOTES, 'UTF-8'); ?></p>
    <p>This page lists controlled inventory only (products with an opening balance at this branch).</p>

    <form method="GET" action="">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach ($allowedFilters as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $filter === $value ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Code</th>
                <th>Branch</th>
                <th>On Hand</th>
                <th>Reorder Level</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($branch === ''): ?>
                <tr>
                    <td colspan="6">No branch selected.</td>
                </tr>
            <?php elseif (!$rows): ?>
                <tr>
                    <td colspan="6">No controlled products for this filter.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr data-code="<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <td><?php echo htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $row['reorder_level'] === null ? '' : htmlspecialchars($row['reorder_level'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psInventoryStatusLabel($row['status']), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
<?php ps_close(); ?>
</body>
</html>
