<?php
/**
 * Cashier sales detail (linked from the Z Report).
 *
 * Health-check fix 2026-10-13: previously unauthenticated and not branch-scoped.
 * Now: login required, scoped to the authorised working branch, prepared statements,
 * cancelled sales excluded (same rule as the Z Report).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
psBranchRequireAuthRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    error_log('cashier_sales: connection failed');
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

$scope = psBranchRequireAuthorisedWorking($conn);
if (empty($scope['ok'])) {
    die(htmlspecialchars((string)($scope['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$branch = (string)$scope['branch'];

$isDate = static function (string $v): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
};
$cashier = trim((string)($_GET['cashier'] ?? ''));
$start_date = trim((string)($_GET['start_date'] ?? ''));
$end_date = trim((string)($_GET['end_date'] ?? ''));
if (!$isDate($start_date)) {
    $start_date = date('Y-m-d');
}
if (!$isDate($end_date)) {
    $end_date = $start_date;
}

$sales_data = [];
$total = 0.0;
$loadError = '';
// Multi-currency: each line in its sale currency; per-currency totals; consolidated at each sale's stored rate.
require_once __DIR__ . '/includes/fx_report.php';
$fx = psFxrContext($conn, $_GET);
$csBase = $fx['base'];
$totalMix = [];
if ($cashier !== '') {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT s.product, s.quantity, s.revenue, s.date_, s.invoice_number, r.currency AS fx_currency, r.fx_rate, r.fx_rate_ids,
                ROUND(CAST(s.revenue AS DECIMAL(14,2)) / NULLIF(r.fx_rate, 0), 2) AS base_revenue
           FROM online_sales s
           LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number AND r.branch = s.branch
          WHERE s.cashier = ? AND s.branch = ?
            AND DATE(s.date_) BETWEEN ? AND ?
            AND COALESCE(r.status, '') <> 'CANCELLED'" . psFxrCurFilterSql($conn, $fx, 'r.currency') . "
          ORDER BY s.date_, s.id"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssss', $cashier, $branch, $start_date, $end_date);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $sales_data[] = $row;
                psFxrAdd($totalMix, $fx, $row['fx_currency'], $row['revenue'], $row['base_revenue'], ['rate' => (string)$row['fx_rate']]);
            }
        } else {
            $loadError = 'Sales could not be loaded.';
        }
        mysqli_stmt_close($stmt);
    } else {
        $loadError = 'Sales could not be loaded.';
    }
    if ($loadError !== '') {
        error_log('cashier_sales: query failed');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Sales</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cashier Sales'); ?>
<h1>Cashier Sales</h1>
<p class="ps-page-lead"><?php echo ps_h($cashier !== '' ? $cashier : 'No cashier selected'); ?> · <?php echo ps_h($branch); ?> · <?php echo ps_h($start_date); ?> to <?php echo ps_h($end_date); ?></p>
<div class="ps-link-row">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="z_report.php">Back to Z Report</a>
</div>
<?php echo psFxrToolbar($fx, ['cashier' => $cashier, 'start_date' => $start_date, 'end_date' => $end_date]); ?>
<?php if ($loadError !== ''): ?>
    <div class="ps-alert ps-alert-destructive"><?php echo ps_h($loadError); ?></div>
<?php endif; ?>
<div class="ps-card"><div class="ps-card-content">
    <div class="ps-table-wrap">
        <table class="ps-table">
            <thead>
                <tr><th>Product</th><th class="num">Quantity</th><th class="num">Revenue</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php if (empty($sales_data)): ?>
                <tr><td colspan="4">No sales found for this cashier in the selected date range.</td></tr>
            <?php else: ?>
                <?php foreach ($sales_data as $data): ?>
                    <tr>
                        <td><?php echo ps_h($data['product']); ?></td>
                        <td class="num"><?php echo ps_h($data['quantity']); ?></td>
                        <td class="num"><?php echo psFxrOne($conn, $fx, $data['fx_currency'], $data['revenue'], $data['base_revenue'], ['rate' => (string)$data['fx_rate'], 'ids' => (string)$data['fx_rate_ids'], 'ref' => 'Invoice ' . $data['invoice_number']]); ?></td>
                        <td><?php echo ps_h($data['date_']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><th colspan="2">Total<?php echo $fx['view'] === PS_FXR_BREAKDOWN ? ' (per currency)' : ' (in ' . ps_h($csBase) . ')'; ?></th><th class="num"><?php echo psFxrAmount($conn, $fx, $totalMix, ['strong' => true]); ?></th><th></th></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div></div>
<?php echo psFxrSummary($conn, $fx, $totalMix); ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
