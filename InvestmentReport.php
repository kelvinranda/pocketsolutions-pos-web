<?php
/**
 * Phase 9.3 — Investment / GRV-sold report.
 *
 * Auth required. Where goods.branch is present after the name join, rows are
 * limited to authorised branches (ordinary = working/home; admin = all registered).
 * grv_report / z_reading still lack product_id/branch — name-based join limitation
 * preserved (no historical rewrite; no invented GRV branch associations).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAuthRedirect('login.php');

$conn = mysqli_connect(
    $servername,
    $username,
    $password,
    $dbname
);

if (!$conn) {
    error_log('InvestmentReport: connection failed');
    die('Database connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

$scope = psBranchReportScope($conn);
if (empty($scope['ok'])) {
    die(htmlspecialchars((string)($scope['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$authorisedBranches = $scope['branches'] ?? [];

/*
|--------------------------------------------------------------------------
| DAILY INVEST
|--------------------------------------------------------------------------
| Equivalent of the VB.NET daily_invest() sub
|
| INPUT  = GRV quantity × product price
| OUTPUT = SOLD quantity × product price
|--------------------------------------------------------------------------
*/

function daily_invest($conn, array $authorisedBranches)
{
    // LIMITATION (Module 3.4): grv_report and z_reading store product NAME only
    // (grv_report has no product_id / branch). Historical rows cannot be joined
    // safely by product_id+branch without guessing. Name join unchanged; scope
    // applied via goods.branch when the join returns a goods row.
    $in = psBranchSqlInList($authorisedBranches);
    if ($in === null) {
        return ['input' => 0, 'output' => 0];
    }

    $sql = "
        SELECT
            SUM(gr.qty * g.price) AS input,
            SUM(zr.total_currency * g.price) AS output
        FROM grv_report AS gr
        JOIN z_reading AS zr
            ON gr.product = zr.cashier
        JOIN goods AS g
            ON gr.product = g.product
        WHERE g.branch IN (" . $in['sql'] . ")
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('InvestmentReport: daily_invest prepare failed');
        return ['input' => 0, 'output' => 0];
    }
    $bind = [$in['types']];
    $params = $in['params'];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('InvestmentReport: daily_invest execute failed');
        mysqli_stmt_close($stmt);
        return ['input' => 0, 'output' => 0];
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    $input  = $row['input'] ?? 0;
    $output = $row['output'] ?? 0;

    return [
        'input'  => $input,
        'output' => $output
    ];
}


/*
|--------------------------------------------------------------------------
| LOAD GRV DATA
|--------------------------------------------------------------------------
| Equivalent of load_dataGRV()
|--------------------------------------------------------------------------
*/

function load_dataGRV($conn, array $authorisedBranches)
{
    // LIMITATION: same name-only schema as daily_invest(). Display may show
    // goods.product_id/branch when the name join returns a goods row; stock
    // ledger overlay still keys off that row's product_id+branch when present.
    // Phase 9.3: filter to authorised goods.branch only.
    $in = psBranchSqlInList($authorisedBranches);
    if ($in === null) {
        return [];
    }

    $sql = "
        SELECT
            gr.id,
            gr.product,
            g.product_id,
            g.branch,
            gr.qty,
            zr.total_currency,
            (gr.qty - zr.total_currency) AS leftt,
            (gr.qty * g.price) AS input,
            (zr.total_currency * g.price) AS output
        FROM grv_report AS gr
        JOIN z_reading AS zr
            ON gr.product = zr.cashier
        JOIN goods AS g
            ON gr.product = g.product
        WHERE g.branch IN (" . $in['sql'] . ")
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('InvestmentReport: load_dataGRV prepare failed');
        return [];
    }
    $bind = [$in['types']];
    $params = $in['params'];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('InvestmentReport: load_dataGRV execute failed');
        mysqli_stmt_close($stmt);
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);

    $data = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    // Period GRV and sold stay as they are. STOCK is replaced only for a controlled product at that goods row's branch.
    $ledgerByBranch = [];
    foreach ($data as $index => $row) {
        $productId = trim((string)($row['product_id'] ?? ''));
        $branch = trim((string)($row['branch'] ?? ''));
        if ($productId === '' || $branch === '') {
            continue;
        }
        if (!array_key_exists($branch, $ledgerByBranch)) {
            $ledgerByBranch[$branch] = psControlledLedgerBalances($conn, $branch);
        }
        if (array_key_exists($productId, $ledgerByBranch[$branch])) {
            $data[$index]['leftt'] = $ledgerByBranch[$branch][$productId];
        }
    }

    return $data;
}


/*
|--------------------------------------------------------------------------
| RUN FUNCTIONS
|--------------------------------------------------------------------------
*/

$invest = daily_invest($conn, $authorisedBranches);

$input  = $invest['input'];
$output = $invest['output'];

$grvData = load_dataGRV($conn, $authorisedBranches);
$investment_name_counts = [];
foreach ($grvData as $investment_row) {
    $investment_name = (string)($investment_row['product'] ?? '');
    $investment_name_counts[$investment_name] = ($investment_name_counts[$investment_name] ?? 0) + 1;
}


/*
|--------------------------------------------------------------------------
| DISPLAY
|--------------------------------------------------------------------------
*/
$balance = (float)$input - (float)$output;
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Report</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Investment Report'); ?>

<h1>Investment Report</h1>
<p class="ps-page-lead">GRV input versus sold output for authorised branches. Name-based joins are preserved where product_id/branch history is incomplete.</p>

<div class="ps-kpi-grid" aria-label="Daily invest summary">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Input</div>
        <div class="ps-kpi-value"><?php echo $h(number_format((float)$input, 2)); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Output</div>
        <div class="ps-kpi-value"><?php echo $h(number_format((float)$output, 2)); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Balance</div>
        <div class="ps-kpi-value"><?php echo $h(number_format($balance, 2)); ?></div>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">GRV / sold detail</h2>
        <p class="ps-card-desc">Per-product quantities and valued input/output.</p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th class="num">GRV qty</th>
                        <th class="num">Sold</th>
                        <th class="num">Stock</th>
                        <th class="num">Input</th>
                        <th class="num">Output</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($grvData) > 0): ?>
                    <?php foreach ($grvData as $row): ?>
                        <?php
                        $investment_label = (string)$row['product'];
                        $investment_code = trim((string)($row['product_id'] ?? ''));
                        if ($investment_code !== '' && ($investment_name_counts[$investment_label] ?? 0) > 1) {
                            $investment_label .= ' (' . $investment_code . ')';
                            $investment_branch = trim((string)($row['branch'] ?? ''));
                            if ($investment_branch !== '') {
                                $investment_label .= ' ' . $investment_branch;
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo $h($row['id'] ?? ''); ?></td>
                            <td title="<?php echo $h($investment_label); ?>"><?php echo $h($investment_label); ?></td>
                            <td class="num"><?php echo $h(number_format((float)$row['qty'], 2)); ?></td>
                            <td class="num"><?php echo $h(number_format((float)$row['total_currency'], 2)); ?></td>
                            <td class="num"><?php echo $h(number_format((float)$row['leftt'], 2)); ?></td>
                            <td class="num"><?php echo $h(number_format((float)$row['input'], 2)); ?></td>
                            <td class="num"><?php echo $h(number_format((float)$row['output'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="ps-muted">No records found</td>
                    </tr>
                <?php endif; ?>
                </tbody>
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