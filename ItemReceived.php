<?php
/**
 * Items received / GRV report — admin shell page.
 * Session/auth must run before any HTML so ps_open() sees admin level.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

// ============================================================
// DATABASE CONNECTION
// ============================================================

$conn = mysqli_connect(
    $servername,
    $username,
    $password,
    $dbname
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
require_once __DIR__ . '/includes/role_permissions.php';
psRolePermRequire('stock_receive'); // 2026-11-02: company role permissions (was admin only)


// ============================================================
// GET SELECTED DATES
// ============================================================

$date_filter1 = isset($_GET['date_from'])
    ? $_GET['date_from']
    : date('Y-m-d');

$date_filter2 = isset($_GET['date_to'])
    ? $_GET['date_to']
    : date('Y-m-d');


// Validate dates

if (!DateTime::createFromFormat('Y-m-d', $date_filter1)) {
    $date_filter1 = date('Y-m-d');
}

if (!DateTime::createFromFormat('Y-m-d', $date_filter2)) {
    $date_filter2 = date('Y-m-d');
}


// ============================================================
// GRV PER ITEM — 2026-11-02: only this company's branches (psBranchReportScope),
// summed in memory (the shared grv_report table was emptied by every viewer and mixed companies).
// ============================================================

$rows = [];
$totalQty = 0;
$totalValue = 0;
$scope = psBranchReportScope($conn);
$in = !empty($scope['ok']) ? psBranchSqlInList($scope['branches'] ?? []) : null;
if ($in !== null) {
    $sql = 'SELECT product, COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(quantity * line_cost), 0) AS grv_value
        FROM goods_receipts
        WHERE product <> \'\' AND product IS NOT NULL AND DATE(date_) BETWEEN ? AND ? AND branch IN (' . $in['sql'] . ')
        GROUP BY product ORDER BY product';
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        $params = array_merge([$date_filter1, $date_filter2], $in['params']);
        mysqli_stmt_bind_param($stmt, 'ss' . $in['types'], ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $n = 0;
        while ($row = mysqli_fetch_assoc($res)) {
            $row['id'] = ++$n;
            $rows[] = $row;
            $totalQty += (float)$row['qty'];
            $totalValue += (float)$row['grv_value'];
        }
        mysqli_stmt_close($stmt);
    }
}

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items Received</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Items Received'); ?>

<h1>Items Received</h1>
<p class="ps-page-lead">Goods received (GRV) quantities and values for the selected date range.</p>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Choose a date range and load the GRV report.</p>
    </div>
    <div class="ps-card-content">
        <form method="GET" class="ps-form-inline filter-form">
            <label>
                <span class="ps-label">Date from</span>
                <input class="ps-input" type="date" name="date_from" value="<?php echo $h($date_filter1); ?>" required>
            </label>
            <label>
                <span class="ps-label">Date to</span>
                <input class="ps-input" type="date" name="date_to" value="<?php echo $h($date_filter2); ?>" required>
            </label>
            <button class="ps-btn ps-btn-default" type="submit">Load report</button>
        </form>
    </div>
</div>

<div class="ps-kpi-grid" aria-label="Summary">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Products</div>
        <div class="ps-kpi-value"><?php echo (int)count($rows); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total qty</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($totalQty, 2)); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total value</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($totalValue, 2)); ?></div>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">GRV by product</h2>
        <p class="ps-card-desc"><?php echo $h($date_filter1); ?> → <?php echo $h($date_filter2); ?></p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th class="num">Qty</th>
                        <th class="num">Value</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $product = (string)($row['product'] ?? ''); ?>
                        <tr>
                            <td><?php echo $h($row['id'] ?? ''); ?></td>
                            <td title="<?php echo $h($product); ?>"><?php echo $h($product); ?></td>
                            <td class="num"><?php echo $h(number_format((float)($row['qty'] ?? 0), 2)); ?></td>
                            <td class="num"><?php echo $h(number_format((float)($row['grv_value'] ?? 0), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="ps-muted">No GRV records found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <?php if (count($rows) > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="num"><?php echo $h(number_format($totalQty, 2)); ?></td>
                        <td class="num"><?php echo $h(number_format($totalValue, 2)); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php ps_close(); ?>
</body>
</html>
<?php
mysqli_close($conn);
?>