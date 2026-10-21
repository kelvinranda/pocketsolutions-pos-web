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
$branch = '';
$returnNumber = trim((string)($_GET['return_number'] ?? ''));
$rows = [];

if (!$signedIn) {
    $error = 'You must be signed in to view purchase return audit.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $list = psPrAuditFetchHistory($conn, [
            'branch' => $branch,
            'return_number' => $returnNumber,
        ]);
        if (!$list['ok']) {
            $error = (string)($list['error'] ?? 'Audit could not be loaded.');
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
    <title>Purchase Return Audit</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Purchase Return Audit</h1>
    <p class="hint">Append-only return audit (not product_audit). Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : '(none)', ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($signedIn && $branch !== ''): ?>
        <form method="GET" action="">
            <input type="text" name="return_number" placeholder="PR-000001" value="<?php echo htmlspecialchars($returnNumber, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit">Filter</button>
            <a href="purchase_returns_list.php">Returns</a>
        </form>
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>Return #</th>
                    <th>GRV #</th>
                    <th>Action</th>
                    <th>Field</th>
                    <th>Old</th>
                    <th>New</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8">No audit rows.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['return_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['grv_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
