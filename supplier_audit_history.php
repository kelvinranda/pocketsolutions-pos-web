<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/supplier_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSupplierAuditSchema($conn);

$error = '';
$rows = [];
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

$filterSupplierId = (int)($_GET['supplier_id'] ?? 0);
$filterCode = trim((string)($_GET['supplier_code'] ?? ''));

if (!$signedIn) {
    $error = 'You must be signed in to view supplier audit history.';
} else {
    $result = psSupplierAuditFetchHistory($conn, [
        'supplier_id' => $filterSupplierId,
        'supplier_code' => $filterCode,
        'limit' => 300,
    ]);
    if (!$result['ok']) {
        $error = (string)($result['error'] ?? 'Supplier audit history could not be loaded.');
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
    <title>Supplier Audit History</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Supplier Audit History</h1>
    <p class="audit-note">
        Field-level supplier master changes only (CREATE / UPDATE / ACTIVATE / DEACTIVATE).
        Audit rows cannot be edited or deleted from this screen.
        No historical backfill of pre-audit changes.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="audit-filters">
            <div>
                <label for="supplier_id">Supplier ID</label>
                <input type="number" id="supplier_id" name="supplier_id" min="1"
                       value="<?php echo $filterSupplierId > 0 ? (int)$filterSupplierId : ''; ?>">
            </div>
            <div>
                <label for="supplier_code">Supplier code</label>
                <input type="text" id="supplier_code" name="supplier_code"
                       value="<?php echo htmlspecialchars($filterCode, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <button type="submit">Filter</button>
                <a href="suppliers_list.php">Back to suppliers</a>
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
                        <th>Code</th>
                        <th>Supplier ID</th>
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
                        <td><?php echo htmlspecialchars((string)$row['supplier_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$row['supplier_id']; ?></td>
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
