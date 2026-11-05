<?php
/**
 * Phase 10 — Inventory reports (read-only) + CSV export.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/inventory_reporting.php';
require_once __DIR__ . '/includes/product_packs.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psReportingRequireAuthRedirect();
psReportingEnsureTimezone();

$isAdmin = psReportingIsAdmin();
$report = strtolower(trim((string)($_GET['report'] ?? 'stock')));
if (!in_array($report, ['stock', 'movements', 'low', 'out'], true)) {
    $report = 'stock';
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$productId = trim((string)($_GET['product_id'] ?? ''));
$movementType = trim((string)($_GET['movement_type'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$stockFilter = 'all';
if ($report === 'low') {
    $stockFilter = 'low';
} elseif ($report === 'out') {
    $stockFilter = 'out';
}

$raw = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch' => $isAdmin ? $branchFilter : null,
    'product_id' => $productId,
    'page' => $page,
    'page_size' => 50,
    'stock_filter' => $stockFilter,
    'movement_type' => $movementType,
];

if ($export === 'csv') {
    $kind = $report === 'movements' ? 'movements' : 'stock';
    $exp = psInventoryReportingExportCsv($conn, $kind, $raw);
    mysqli_close($conn);
    if (empty($exp['ok'])) {
        http_response_code(403);
        die(htmlspecialchars((string)($exp['error'] ?? 'Export failed.'), ENT_QUOTES, 'UTF-8'));
    }
    psReportingSendCsv($exp);
    exit;
}

$error = '';
$result = null;
$branchOptions = $isAdmin ? psBranchAuthorisedRegisteredNames($conn) : [];
$norm = psReportingNormalizeFilters($conn, $raw);
if (empty($norm['ok'])) {
    $error = (string)($norm['error'] ?? 'Report could not be loaded.');
} else {
    $f = array_merge($norm['filters'], [
        'stock_filter' => $stockFilter,
        'movement_type' => $movementType,
        'product_id' => $productId,
    ]);
    if ($report === 'movements') {
        $result = psInventoryReportingMovements($conn, $f);
    } else {
        $result = psInventoryReportingStockOnHand($conn, $f);
    }
    if ($result !== null && empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Report could not be loaded.');
        $result = null;
    }
}

require_once __DIR__ . '/includes/currency.php';
$invBase = psFxBase($conn, psFxSessionCompany());
// 2026-10-28: connection kept for the pack breakdown of stock figures; closed at the end of the page.

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$q = static function (array $extra = []) use ($report, $fromDate, $toDate, $branchFilter, $productId, $movementType, $isAdmin): string {
    $base = [
        'report' => $report,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'product_id' => $productId,
        'movement_type' => $movementType,
    ];
    if ($isAdmin) {
        $base['branch'] = $branchFilter;
    }
    $merged = array_merge($base, $extra);
    $parts = [];
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return $parts === [] ? '' : ('?' . implode('&', $parts));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Reports</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Inventory Reports</h1>
    <p class="hint">Stock on hand comes from the stock ledger for products that have an opening balance. Other products show the old stored quantity. Reorder alerts use each product's reorder level.</p>
    <p><a href="management_dashboard.php">Management dashboard</a></p>

    <div class="tabs">
        <?php
        $tabs = ['stock' => 'On hand', 'low' => 'Low stock', 'out' => 'Out of stock', 'movements' => 'Movements'];
        foreach ($tabs as $k => $label):
            ?>
            <a class="<?php echo $report === $k ? 'on' : ''; ?>" href="inventory_reports.php<?php echo $h($q(['report' => $k, 'page' => 1])); ?>"><?php echo $h($label); ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="inventory_reports.php" style="margin-top:16px;">
        <input type="hidden" name="report" value="<?php echo $h($report); ?>">
        <?php if ($isAdmin): ?>
            <label>Branch
                <select name="branch">
                    <option value="">All authorised</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo $h($bn); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>><?php echo $h($bn); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>Product ID <input type="text" name="product_id" value="<?php echo $h($productId); ?>" maxlength="250"></label>
        <?php if ($report === 'movements'): ?>
            <label>From <input type="date" name="from_date" value="<?php echo $h($fromDate); ?>"></label>
            <label>To <input type="date" name="to_date" value="<?php echo $h($toDate); ?>"></label>
            <label>Type
                <select name="movement_type">
                    <option value="">All</option>
                    <?php
                    $types = ['OPENING_BALANCE', 'SALE', 'REFUND', 'GOODS_RECEIPT', 'PURCHASE_RETURN', 'TRANSFER_IN', 'TRANSFER_OUT', 'ADJUSTMENT', 'STOCK_TAKE'];
                    foreach ($types as $t):
                        ?>
                        <option value="<?php echo $h($t); ?>"<?php echo strtoupper($movementType) === $t ? ' selected' : ''; ?>><?php echo $h($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button type="submit">Apply</button>
        <a href="inventory_reports.php<?php echo $h($q(['export' => 'csv'])); ?>">Export CSV</a>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?php echo $h($error); ?></p>
    <?php elseif ($result && $report === 'movements'): ?>
        <p class="hint"><?php echo $h($result['note'] ?? ''); ?></p>
        <table>
            <thead><tr><th>When</th><th>Product ID</th><th>Branch</th><th>Type</th><th>Qty</th><th>Ref type</th><th>Ref</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><?php echo $h($row['created_at']); ?></td>
                    <td><?php echo $h($row['product_id']); ?></td>
                    <td><?php echo $h($row['branch']); ?></td>
                    <td><?php echo $h($row['movement_type']); ?></td>
                    <td><?php echo $h($row['quantity']); ?></td>
                    <td><?php echo $h($row['reference_type']); ?></td>
                    <td><?php echo $h($row['reference_id']); ?></td>
                    <td><?php echo $h($row['created_by']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="8">No movements for these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    <?php elseif ($result): ?>
        <?php if (!empty($result['totals'])): ?>
            <p class="hint">
                Low: <?php echo (int)$result['totals']['low_count']; ?> ·
                Out: <?php echo (int)$result['totals']['out_count']; ?> ·
                Ledger SKUs: <?php echo (int)$result['totals']['ledger_count']; ?> ·
                Legacy display SKUs: <?php echo (int)$result['totals']['legacy_count']; ?> ·
                Value:
                <?php
                $sv = $result['totals']['stock_value'] ?? null;
                echo $sv === null ? '—' : $h($sv);
                ?>
            </p>
            <p class="hint"><?php echo $h($result['valuation_note'] ?? ''); ?></p>
        <?php endif; ?>
        <table>
            <thead>
            <tr>
                <th>Product ID</th><th>Product</th><th>Branch</th><th>Qty</th><th>Source</th>
                <th>Reorder</th><th>Cost (<?php echo $h($invBase ?? ''); ?>)</th><th>Value (<?php echo $h($invBase ?? ''); ?>)</th><th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <?php
                $cls = '';
                if (!empty($row['is_out'])) {
                    $cls = 'out';
                } elseif (!empty($row['is_low'])) {
                    $cls = 'low';
                }
                ?>
                <tr class="<?php echo $cls; ?>">
                    <td><?php echo $h($row['product_id']); ?></td>
                    <td><?php echo $h($row['product']); ?></td>
                    <td><?php echo $h($row['branch']); ?></td>
                    <td><?php echo $h($row['qty']); ?><?php $pkNote = psPackStockNote($conn, trim((string)($_SESSION['the_company'] ?? '')), (string)$row['product_id'], (string)$row['qty']);
                        if ($pkNote !== ''): ?><div class="hint" style="margin:0"><?php echo $h($pkNote); ?></div><?php endif; ?></td>
                    <td><?php echo $h($row['qty_source']); ?></td>
                    <td><?php echo $h($row['reorder_level'] ?? ''); ?></td>
                    <td><?php echo $h($row['cost_price'] ?? ''); ?></td>
                    <td><?php echo $h($row['stock_value'] ?? ''); ?></td>
                    <td><?php echo $h($row['status']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="9">No products for these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($result['limitations'])): ?>
            <ul class="hint">
                <?php foreach ($result['limitations'] as $lim): ?>
                    <li><?php echo $h($lim); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($result && !empty($result['pagination']) && (int)$result['pagination']['total_pages'] > 1): ?>
        <?php $pg = $result['pagination']; ?>
        <p>
            Page <?php echo (int)$pg['page']; ?> / <?php echo (int)$pg['total_pages']; ?>
            (<?php echo (int)$pg['total_rows']; ?> rows)
            <?php if ((int)$pg['page'] > 1): ?>
                · <a href="inventory_reports.php<?php echo $h($q(['page' => (int)$pg['page'] - 1])); ?>">Prev</a>
            <?php endif; ?>
            <?php if ((int)$pg['page'] < (int)$pg['total_pages']): ?>
                · <a href="inventory_reports.php<?php echo $h($q(['page' => (int)$pg['page'] + 1])); ?>">Next</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
