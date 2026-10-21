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
$branch = '';
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$grvFilter = trim((string)($_GET['grv_number'] ?? ''));
$rows = [];

if (!empty($_GET['created'])) {
    $notice = 'Purchase return saved.';
}

if (!$signedIn) {
    $error = 'You must be signed in to view purchase returns.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $list = psPurchaseReturnListForSession($conn, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'grv_number' => $grvFilter,
        ]);
        if (!$list['ok']) {
            $error = (string)($list['error'] ?? 'Purchase returns could not be loaded.');
        } else {
            $rows = $list['rows'] ?? [];
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
    <title>Purchase Returns</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Purchase Returns</h1>
    <p class="hint">Returns reverse stock via PURCHASE_RETURN movements against numbered GRV lines. They do not reopen closed purchase orders.</p>
    <p class="hint">Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : '(none)', ENT_QUOTES, 'UTF-8'); ?></p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $branch !== ''): ?>
        <form method="GET" action="">
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="grv_number" placeholder="GRV #" value="<?php echo htmlspecialchars($grvFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit">Filter</button>
        </form>
        <a class="btn" href="create_purchase_return.php">New purchase return</a>
        <a href="purchase_return_audit_history.php">Return audit</a>
        <a href="purchasing_reports.php?report=returns">Return report</a>

        <table>
            <thead>
                <tr>
                    <th>Return #</th>
                    <th>GRV #</th>
                    <th>PO #</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>User</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8">No purchase returns found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$row['return_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['grv_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['supplier_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['return_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['total'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><a href="purchase_return_view.php?id=<?php echo (int)$row['id']; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
