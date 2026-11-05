<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/purchasing_reports.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$admin = psSessionAllowsAllProductBranches();
$history = null;
$branchOptions = [];

if (!$signedIn) {
    $error = 'You must be signed in to view supplier purchasing history.';
} else {
    $branchOptions = psAuthorisedProductBranches($conn);
    if ($supplierId < 1) {
        $error = 'Select a supplier (by id) to view purchasing history.';
    } else {
        require_once __DIR__ . '/includes/fx_report.php';
        $fx = psFxrContext($conn, $_GET);
        $history = psSupplierPurchasingHistory($conn, $supplierId, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'branch' => $admin ? $branchFilter : null,
            'fx' => $fx,
        ]);
        if (!$history['ok']) {
            $error = (string)($history['error'] ?? 'History could not be loaded.');
            $history = null;
        }
    }
}

require_once __DIR__ . '/includes/fx_report.php';
$fx = $fx ?? psFxrContext($conn, $_GET);
$M = static fn(?array $mix): string => psFxrAmount($conn, $fx, $mix ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Purchasing History</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Supplier Purchasing History</h1>
    <p class="hint">Identity is supplier_master id/code. Inactive suppliers remain visible here. Values are purchasing activity — not payable balance owed.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="">
            <input type="number" name="supplier_id" min="1" required placeholder="Supplier id"
                   value="<?php echo $supplierId > 0 ? (int)$supplierId : ''; ?>">
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($admin): ?>
                <select name="branch">
                    <option value="">All branches</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit">Load</button>
            <a href="suppliers_list.php">Suppliers</a>
            <?php foreach (psFxrQuery($fx) as $fk => $fv): ?><input type="hidden" name="<?php echo $fk; ?>" value="<?php echo htmlspecialchars($fv, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
        </form>
        <?php echo psFxrToolbar($fx, $_GET); ?>
    <?php endif; ?>

    <?php if ($history): ?>
        <?php
        $s = $history['supplier'];
        $sum = $history['summary'];
        ?>
        <h2><?php echo htmlspecialchars((string)$s['supplier_code'] . ' — ' . (string)$s['supplier_name'], ENT_QUOTES, 'UTF-8'); ?>
            (<?php echo htmlspecialchars(psSupplierStatusLabel((string)$s['status']), ENT_QUOTES, 'UTF-8'); ?>)</h2>
        <p class="hint"><?php echo htmlspecialchars((string)$history['balance_note'], ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="summary">
            <div>PO count<br><strong><?php echo (int)$sum['po_count']; ?></strong></div>
            <div>PO value<br><strong><?php echo $M($sum['fx_po'] ?? []); ?></strong></div>
            <div>GRV count<br><strong><?php echo (int)$sum['grv_count']; ?></strong></div>
            <div>GRV / received value<br><strong><?php echo $M($sum['fx_grv'] ?? []); ?></strong></div>
            <div>Return value<br><strong><?php echo $M($sum['fx_return'] ?? []); ?></strong></div>
            <div>Net received value<br><strong><?php echo $M($sum['fx_net'] ?? []); ?></strong></div>
            <div>Latest date<br><strong><?php echo htmlspecialchars((string)($sum['latest_date'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>

        <h3>Purchase orders</h3>
        <table>
            <thead><tr><th>PO #</th><th>Date</th><th>Status</th><th>Branch</th><th>Total</th></tr></thead>
            <tbody>
            <?php if ($history['pos'] === []): ?>
                <tr><td colspan="5">None</td></tr>
            <?php else: foreach ($history['pos'] as $row): ?>
                <tr>
                    <td><a href="purchase_order_view.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars((string)$row['po_number'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><?php echo htmlspecialchars((string)$row['order_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="num"><?php echo psFxrOne($conn, $fx, $row['currency'] ?? null, $row['_total'], $row['_base_total'] ?? null, ['rate' => (string)($row['fx_rate'] ?? ''), 'ids' => (string)($row['fx_rate_ids'] ?? '')]); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h3>Received purchases (GRV)</h3>
        <table>
            <thead><tr><th>GRV #</th><th>PO #</th><th>Date</th><th>Branch</th><th>Total</th></tr></thead>
            <tbody>
            <?php if ($history['grvs'] === []): ?>
                <tr><td colspan="5">None</td></tr>
            <?php else: foreach ($history['grvs'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($row['grv_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['date_'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="num"><?php echo psFxrOne($conn, $fx, $row['currency'] ?? null, $row['totalz'], $row['base_total'] ?? null, ['rate' => (string)($row['fx_rate'] ?? ''), 'ids' => (string)($row['fx_rate_ids'] ?? '')]); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h3>Purchase returns</h3>
        <table>
            <thead><tr><th>Return #</th><th>GRV #</th><th>Date</th><th>Branch</th><th>Total</th></tr></thead>
            <tbody>
            <?php if ($history['returns'] === []): ?>
                <tr><td colspan="5">None</td></tr>
            <?php else: foreach ($history['returns'] as $row): ?>
                <tr>
                    <td><a href="purchase_return_view.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars((string)$row['return_number'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><?php echo htmlspecialchars((string)$row['grv_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['return_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="num"><?php echo psFxrOne($conn, $fx, $row['currency'] ?? null, $row['total'], $row['base_total'] ?? null, ['rate' => (string)($row['fx_rate'] ?? ''), 'ids' => (string)($row['fx_rate_ids'] ?? '')]); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php if (!empty($sum)) { echo psFxrSummary($conn, $fx, $sum['fx_net'] ?? [], 'Net received by currency'); } ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
