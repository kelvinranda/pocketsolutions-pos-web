<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/product_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

psEnsureProductAuditSchema($conn);

$error = '';
$rows = [];
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

$viewBranches = psProductAuditViewBranches($conn);
$sessionBranch = psSessionProductBranch();
$isAdmin = psSessionAllowsAllProductBranches();

$filterBranch = trim((string)($_GET['branch'] ?? ''));
$filterProductId = trim((string)($_GET['product_id'] ?? ''));
$filterGoodsId = (int)($_GET['goods_id'] ?? 0);

if ($filterBranch === '') {
    $filterBranch = $sessionBranch;
}
if ($filterBranch !== '' && !in_array($filterBranch, $viewBranches, true)) {
    // Manipulated branch: do not fall through to other branches.
    $filterBranch = '';
    $rows = [];
} elseif (!$signedIn) {
    $error = 'You must be signed in to view product audit history.';
} elseif ($viewBranches === []) {
    $error = 'That branch is not available.';
} else {
    $result = psProductAuditFetchHistory($conn, [
        'branch' => $filterBranch,
        'product_id' => $filterProductId,
        'goods_id' => $filterGoodsId,
        'limit' => 300,
    ]);
    if (!$result['ok']) {
        $error = (string)($result['error'] ?? 'Product audit history could not be loaded.');
    } else {
        $rows = $result['rows'] ?? [];
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Audit History</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Product Audit History</h1>
    <p class="audit-note">
        Field-level product master changes only. Stock movements are separate.
        Audit rows cannot be edited or deleted from this screen.
        <?php if ($isAdmin): ?>
            Admin can select any authorised branch.
        <?php else: ?>
            Showing your session branch only.
        <?php endif; ?>
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $viewBranches): ?>
        <form method="GET" action="" class="audit-filters">
            <div>
                <label for="branch">Branch</label>
                <?php if ($isAdmin): ?>
                    <select id="branch" name="branch">
                        <?php foreach ($viewBranches as $bName): ?>
                            <option value="<?php echo htmlspecialchars($bName); ?>"<?php echo $filterBranch === $bName ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($bName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" id="branch" name="branch" readonly value="<?php echo htmlspecialchars($sessionBranch); ?>">
                <?php endif; ?>
            </div>
            <div>
                <label for="product_id">Product ID</label>
                <input type="text" id="product_id" name="product_id" value="<?php echo htmlspecialchars($filterProductId); ?>">
            </div>
            <div>
                <label for="goods_id">Goods ID</label>
                <input type="number" id="goods_id" name="goods_id" min="1" value="<?php echo $filterGoodsId > 0 ? (int)$filterGoodsId : ''; ?>">
            </div>
            <div>
                <button type="submit">Filter</button>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p class="empty">No audit rows for this filter.</p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Date/time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Branch</th>
                        <th>Product ID</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>Source</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($row['changed_at'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['changed_by'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['action'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['branch'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['product_id'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['field_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['old_value'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['new_value'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['source'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['reason'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <p style="margin-top:16px;">
        <a href="items_list.php">Items List</a>
        &nbsp;|&nbsp;
        <a href="product_history.php">Product History</a>
    </p>
<?php ps_close(); ?>
</body>
</html>
