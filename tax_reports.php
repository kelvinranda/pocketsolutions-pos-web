<?php
/**
 * Phase 12 — Tax reports (read-only). Not a statutory tax return.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/tax_reporting.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psTaxRequireAuthRedirect();
psReportingEnsureTimezone();
psEnsureTaxModule($conn);

$isAdmin = psReportingIsAdmin();
$report = strtolower(trim((string)($_GET['report'] ?? 'summary')));
if (!in_array($report, ['summary', 'drilldown'], true)) {
    $report = 'summary';
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$taxCode = trim((string)($_GET['tax_code'] ?? ''));
$classification = trim((string)($_GET['classification'] ?? ''));
$dataClass = trim((string)($_GET['data_class'] ?? 'SUPPORTED'));
$page = max(1, (int)($_GET['page'] ?? 1));
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$raw = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch' => $isAdmin ? $branchFilter : null,
    'tax_code' => $taxCode,
    'classification' => $classification,
    'data_class' => $dataClass,
    'page' => $page,
    'page_size' => 50,
];

if ($export === 'csv') {
    $exp = psTaxReportingExportCsv($conn, $report, $raw);
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
if ($report === 'summary') {
    $result = psTaxReportingSummary($conn, $raw);
} else {
    $result = psTaxReportingDrilldown($conn, $raw);
}
if (empty($result['ok'])) {
    $error = (string)($result['error'] ?? 'Report failed.');
}
$branchOptions = $isAdmin ? psBranchAuthorisedRegisteredNames($conn) : [];
$codes = psTaxListDistinctCodes($conn, false);
mysqli_close($conn);

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$q = static function (array $extra = []) use ($report, $fromDate, $toDate, $branchFilter, $taxCode, $classification, $dataClass, $page): string {
    $base = [
        'report' => $report,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'branch' => $branchFilter,
        'tax_code' => $taxCode,
        'classification' => $classification,
        'data_class' => $dataClass,
        'page' => $page,
    ];
    return http_build_query(array_merge($base, $extra));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Reports</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Tax Reports'); ?>
<h1>Tax Reports</h1>
<p class="ps-page-lead">Stored line tax only. Not a statutory tax return. PENDING/APPROVED/REJECTED refunds are excluded from completed tax adjustments. Legacy incomplete history is counted separately — amounts are never invented.</p>

<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="tax_rates.php">Tax Rates</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="sales_reports.php">Sales Reports</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting.php">Accounting</a>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Date range, tax code, classification, and data class for this report.</p>
    </div>
    <div class="ps-card-content">
        <form method="get" action="tax_reports.php" class="filter-form">
            <input type="hidden" name="report" value="<?php echo $h($report); ?>">
            <label>From <input type="date" name="from_date" value="<?php echo $h($fromDate); ?>"></label>
            <label>To <input type="date" name="to_date" value="<?php echo $h($toDate); ?>"></label>
            <?php if ($isAdmin): ?>
            <label>Branch
                <select name="branch">
                    <option value="">All authorised</option>
                    <?php foreach ($branchOptions as $b): ?>
                    <option value="<?php echo $h($b); ?>" <?php echo $branchFilter === $b ? 'selected' : ''; ?>><?php echo $h($b); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label>Tax code
                <select name="tax_code">
                    <option value="">All</option>
                    <?php foreach ($codes as $c): ?>
                    <option value="<?php echo $h($c['code']); ?>" <?php echo strtoupper($taxCode) === $c['code'] ? 'selected' : ''; ?>><?php echo $h($c['code']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Classification
                <select name="classification">
                    <option value="">All</option>
                    <?php foreach (['TAXABLE','ZERO_RATED','EXEMPT'] as $c): ?>
                    <option value="<?php echo $c; ?>" <?php echo strtoupper($classification) === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Data class
                <select name="data_class">
                    <?php foreach (['SUPPORTED' => 'Fully supported (COMPLETE)', 'LEGACY' => 'Legacy incomplete', 'UNCONFIGURED' => 'Unconfigured / provisional', 'ALL' => 'All (summary labels)'] as $k => $lab): ?>
                    <option value="<?php echo $k; ?>" <?php echo strtoupper($dataClass) === $k ? 'selected' : ''; ?>><?php echo $h($lab); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="ps-btn ps-btn-default" type="submit">Run</button>
        </form>
    </div>
</div>

<div class="ps-link-row" aria-label="Report views">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="?<?php echo $h($q(['report' => 'summary'])); ?>">Summary</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="?<?php echo $h($q(['report' => 'drilldown'])); ?>">Drill-down</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="?<?php echo $h($q(['export' => 'csv'])); ?>">CSV</a>
</div>

<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>

<?php if ($result && !empty($result['ok']) && $report === 'summary'): ?>
<section class="ps-section">
    <p class="ps-muted"><?php echo $h($result['note'] ?? ''); ?></p>
    <p class="ps-muted">Legacy incomplete headers: <?php echo (int)($result['legacy_count'] ?? 0); ?> ·
       Unconfigured/mixed: <?php echo (int)($result['unconfigured_count'] ?? 0); ?></p>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr>
                <th>Tax code</th><th>Class</th><th>Rate %</th><th>Mode</th><th>Lines</th>
                <th class="num">Base</th><th class="num">Tax</th><th class="num">Zero-rated</th><th class="num">Exempt</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td><a href="?<?php echo $h($q(['report' => 'drilldown', 'tax_code' => $row['tax_code']])); ?>"><?php echo $h($row['tax_code']); ?></a></td>
                <td><?php echo $h($row['classification']); ?></td>
                <td><?php echo $h($row['rate_percent']); ?></td>
                <td><?php echo $h($row['pricing_mode']); ?></td>
                <td><?php echo (int)$row['line_count']; ?></td>
                <td class="num"><?php echo $h($row['taxable_base']); ?></td>
                <td class="num"><?php echo $h($row['tax_collected']); ?></td>
                <td class="num"><?php echo $h($row['zero_rated_base']); ?></td>
                <td class="num"><?php echo $h($row['exempt_turnover']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$result['rows']): ?>
            <tr><td colspan="9">No COMPLETE tax lines for these filters.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <?php $t = $result['totals']; ?>
            <tr>
                <th colspan="5">Totals (supported)</th>
                <th class="num"><?php echo $h($t['taxable_base']); ?></th>
                <th class="num"><?php echo $h($t['tax_collected']); ?></th>
                <th class="num"><?php echo $h($t['zero_rated_base']); ?></th>
                <th class="num"><?php echo $h($t['exempt_turnover']); ?></th>
            </tr>
            <tr><td colspan="6">PROCESSED refund tax adjustments</td><td class="num"><?php echo $h($t['refund_tax']); ?></td><td colspan="2"></td></tr>
            <tr><td colspan="6">Net tax (collected − refund tax)</td><td class="num"><?php echo $h($t['net_tax']); ?></td><td colspan="2"></td></tr>
        </tfoot>
    </table>
    </div>
</section>
<?php endif; ?>

<?php if ($result && !empty($result['ok']) && $report === 'drilldown'): ?>
<section class="ps-section">
    <p class="ps-muted">Rows: <?php echo (int)($result['total_rows'] ?? 0); ?></p>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr>
                <th>Invoice</th><th>Date</th><th>Branch</th><th>Product</th><th>Qty</th>
                <th>Code</th><th>Class</th><th>Rate</th><th class="num">Taxable</th><th class="num">Tax</th><th class="num">Incl</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td><a href="my_receipt.php?invoice_number=<?php echo rawurlencode($row['invoice_number']); ?>"><?php echo $h($row['invoice_number']); ?></a></td>
                <td><?php echo $h($row['date']); ?></td>
                <td><?php echo $h($row['branch']); ?></td>
                <td><?php echo $h($row['product_id'] . ' — ' . $row['product']); ?></td>
                <td><?php echo $h($row['quantity']); ?></td>
                <td><?php echo $h($row['tax_code']); ?></td>
                <td><?php echo $h($row['classification']); ?></td>
                <td><?php echo $h($row['rate_percent']); ?></td>
                <td class="num"><?php echo $h($row['taxable_amount']); ?></td>
                <td class="num"><?php echo $h($row['tax_amount']); ?></td>
                <td class="num"><?php echo $h($row['line_total_incl']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$result['rows']): ?>
            <tr><td colspan="11">No rows.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
<?php
$totalPages = max(1, (int)ceil(((int)($result['total_rows'] ?? 0)) / 50));
if ($totalPages > 1):
?>
<div class="ps-link-row">
<?php if ($page > 1): ?><a class="ps-btn ps-btn-outline ps-btn-sm" href="?<?php echo $h($q(['page' => $page - 1])); ?>">Prev</a><?php endif; ?>
 <span class="ps-muted">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
<?php if ($page < $totalPages): ?><a class="ps-btn ps-btn-outline ps-btn-sm" href="?<?php echo $h($q(['page' => $page + 1])); ?>">Next</a><?php endif; ?>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
if (function_exists('ps_close')) {
    ps_close();
}
?>
</body>
</html>
