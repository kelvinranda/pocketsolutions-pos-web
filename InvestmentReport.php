<?php
/**
 * Phase 9.3 — Investment / GRV-sold report.
 *
 * Auth required. Where goods.branch is present after the name join, rows are
 * limited to authorised branches (ordinary = working/home; admin = all registered).
 * 2026-11-02: computed from goods_receipts + online_sales for the chosen dates, per product_id + branch,
 * this company's branches only (no longer reads the shared grv_report / z_reading tables).
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
| 2026-11-02 — computed straight from goods_receipts and online_sales for the chosen dates,
| per product_id + branch, for this company's branches only. It used to read the shared
| grv_report / z_reading tables, which held whatever the last person (of any company) loaded.
| INPUT = GRV quantity × current price; OUTPUT = sold quantity × current price.
|--------------------------------------------------------------------------
*/
$invFrom = (string)($_GET['date_from'] ?? date('Y-m-d'));
$invTo = (string)($_GET['date_to'] ?? date('Y-m-d'));
if (!DateTime::createFromFormat('Y-m-d', $invFrom)) { $invFrom = date('Y-m-d'); }
if (!DateTime::createFromFormat('Y-m-d', $invTo)) { $invTo = date('Y-m-d'); }

function psInvestmentRows(mysqli $conn, array $authorisedBranches, string $from, string $to): array
{
    $in = psBranchSqlInList($authorisedBranches);
    if ($in === null) {
        return [];
    }
    $byKey = [];
    $grvSql = 'SELECT product_id, branch, MAX(product) AS product, COALESCE(SUM(quantity), 0) AS qty
        FROM goods_receipts WHERE DATE(date_) BETWEEN ? AND ? AND branch IN (' . $in['sql'] . ')
        GROUP BY product_id, branch';
    $soldSql = 'SELECT s.product_id, s.branch, MAX(s.product) AS product, COALESCE(SUM(s.quantity), 0) AS qty
        FROM online_sales s LEFT JOIN online_sales_ref r ON r.invoice_number = s.invoice_number
        WHERE DATE(s.date_) BETWEEN ? AND ? AND s.branch IN (' . $in['sql'] . ')
          AND UPPER(TRIM(COALESCE(r.status, \'\'))) <> \'CANCELLED\'
        GROUP BY s.product_id, s.branch';
    foreach (['grv' => $grvSql, 'sold' => $soldSql] as $kind => $sql) {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            error_log('InvestmentReport: ' . $kind . ' prepare failed');
            continue;
        }
        $params = array_merge([$from, $to], $in['params']);
        mysqli_stmt_bind_param($stmt, 'ss' . $in['types'], ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $k = (string)$r['product_id'] . "\x1f" . (string)$r['branch'];
            if (!isset($byKey[$k])) {
                $byKey[$k] = ['product_id' => (string)$r['product_id'], 'branch' => (string)$r['branch'],
                    'product' => (string)$r['product'], 'qty' => 0.0, 'total_currency' => 0.0];
            }
            $byKey[$k][$kind === 'grv' ? 'qty' : 'total_currency'] += (float)$r['qty'];
        }
        mysqli_stmt_close($stmt);
    }
    $data = [];
    $ledgerByBranch = [];
    $n = 0;
    foreach ($byKey as $row) {
        $price = 0.0;
        $p = mysqli_prepare($conn, 'SELECT product, price FROM goods WHERE product_id = ? AND branch = ? LIMIT 1');
        if ($p) {
            mysqli_stmt_bind_param($p, 'ss', $row['product_id'], $row['branch']);
            mysqli_stmt_execute($p);
            $g = mysqli_fetch_assoc(mysqli_stmt_get_result($p));
            mysqli_stmt_close($p);
            if ($g) {
                $price = (float)$g['price'];
                $row['product'] = (string)($g['product'] ?: $row['product']);
            }
        }
        $row['id'] = ++$n;
        $row['leftt'] = $row['qty'] - $row['total_currency'];
        $branch = $row['branch'];
        if ($row['product_id'] !== '' && $branch !== '') {
            if (!array_key_exists($branch, $ledgerByBranch)) {
                $ledgerByBranch[$branch] = psControlledLedgerBalances($conn, $branch);
            }
            if (array_key_exists($row['product_id'], $ledgerByBranch[$branch])) {
                $row['leftt'] = $ledgerByBranch[$branch][$row['product_id']];
            }
        }
        $row['input'] = $row['qty'] * $price;
        $row['output'] = $row['total_currency'] * $price;
        $data[] = $row;
    }
    usort($data, static fn($x, $y) => strcasecmp($x['product'], $y['product']));
    return $data;
}

$grvData = psInvestmentRows($conn, $authorisedBranches, $invFrom, $invTo);
$input = 0.0;
$output = 0.0;
foreach ($grvData as $r) {
    $input += $r['input'];
    $output += $r['output'];
}
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
<?php require_once __DIR__ . '/includes/currency.php'; $invBase = psFxBase($conn, psFxSessionCompany()); ?>
<p class="ps-page-lead">Goods received versus goods sold in your branches for the chosen dates.</p>
<p class="fxr-label">Values are quantities × the product's current selling price, so they are in <?php echo $h($invBase); ?> (product prices are kept in the base currency). They are a valuation at today's price list, not the amounts actually received; for money received in each currency see Sales Reports.</p>

<div class="ps-card">
    <div class="ps-card-content">
        <form method="GET" class="ps-form-inline filter-form">
            <label><span class="ps-label">Date from</span>
                <input class="ps-input" type="date" name="date_from" value="<?php echo $h($invFrom); ?>" required></label>
            <label><span class="ps-label">Date to</span>
                <input class="ps-input" type="date" name="date_to" value="<?php echo $h($invTo); ?>" required></label>
            <button class="ps-btn ps-btn-default" type="submit">Load report</button>
        </form>
    </div>
</div>

<div class="ps-kpi-grid" aria-label="Daily invest summary">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Input (<?php echo $h($invBase); ?>)</div>
        <div class="ps-kpi-value"><?php echo $h(number_format((float)$input, 2)); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Output (<?php echo $h($invBase); ?>)</div>
        <div class="ps-kpi-value"><?php echo $h(number_format((float)$output, 2)); ?></div>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Balance (<?php echo $h($invBase); ?>)</div>
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
                        <th class="num">Input (<?php echo $h($invBase); ?>)</th>
                        <th class="num">Output (<?php echo $h($invBase); ?>)</th>
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