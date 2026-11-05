<?php
/**
 * Item sales report — admin shell page.
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
psRolePermRequire('sales_view'); // 2026-11-02: company role permissions (was admin only)


// ============================================================
// GET DATE
// ============================================================

$date_filter1 = isset($_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');


// Validate date

$dateObject = DateTime::createFromFormat('Y-m-d', $date_filter1);

if (!$dateObject || $dateObject->format('Y-m-d') !== $date_filter1) {
    $date_filter1 = date('Y-m-d');
}


// ============================================================
// SALES PER ITEM — 2026-11-02: only this company's branches (psBranchReportScope), summed in memory.
// (The shared z_reading table was emptied by every viewer and mixed every company's sales.)
// ============================================================

$rows = [];
$totalSold = 0;
$scope = psBranchReportScope($conn);
$in = !empty($scope['ok']) ? psBranchSqlInList($scope['branches'] ?? []) : null;
if ($in !== null) {
    $sql = 'SELECT s.product AS cashier, COALESCE(SUM(s.quantity), 0) AS total_currency
        FROM online_sales s
        LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
        WHERE s.product <> \'\' AND s.product IS NOT NULL AND DATE(s.date_) = ?
          AND s.branch IN (' . $in['sql'] . ')
          AND UPPER(TRIM(COALESCE(r.status, \'\'))) <> \'CANCELLED\'
        GROUP BY s.product HAVING total_currency <> 0 ORDER BY s.product';
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        $params = array_merge([$date_filter1], $in['params']);
        mysqli_stmt_bind_param($stmt, 's' . $in['types'], ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $n = 0;
        while ($row = mysqli_fetch_assoc($res)) {
            $row['id'] = ++$n;
            $rows[] = $row;
            $totalSold += (float)$row['total_currency'];
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
    <title>Item Sales</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Item Sales'); ?>

<h1>Item Sales</h1>
<p class="ps-page-lead">Quantity sold per product for the selected date.</p>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Choose a sales date and load the report.</p>
    </div>
    <div class="ps-card-content">
        <form method="GET" class="ps-form-inline filter-form">
            <label>
                <span class="ps-label">Sales date</span>
                <input class="ps-input" type="date" name="date" value="<?php echo $h($date_filter1); ?>" required>
            </label>
            <button class="ps-btn ps-btn-default" type="submit">Load sales</button>
        </form>
    </div>
</div>

<div class="ps-kpi-grid" aria-label="Summary">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Products</div>
        <div class="ps-kpi-value"><?php echo (int)count($rows); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total sold</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($totalSold, 2)); ?></div>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Sales by product</h2>
        <p class="ps-card-desc">Date: <?php echo $h($date_filter1); ?></p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th class="num">Qty sold</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $product = (string)($row['cashier'] ?? ''); ?>
                        <tr>
                            <td><?php echo $h($row['id'] ?? ''); ?></td>
                            <td title="<?php echo $h($product); ?>"><?php echo $h($product); ?></td>
                            <td class="num"><?php echo $h(number_format((float)($row['total_currency'] ?? 0), 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="ps-muted">No sales found for this date.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <?php if (count($rows) > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="num"><?php echo $h(number_format($totalSold, 2)); ?></td>
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