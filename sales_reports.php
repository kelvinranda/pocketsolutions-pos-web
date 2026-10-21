<?php
/**
 * Phase 10 — Sales & transaction reports (read-only) + CSV export.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/sales_reporting.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psReportingRequireAuthRedirect();
psReportingEnsureTimezone();

$isAdmin = psReportingIsAdmin();
$report = strtolower(trim((string)($_GET['report'] ?? 'list')));
$allowed = ['list', 'by_branch', 'by_cashier', 'by_product', 'payments', 'by_currency', 'refunds', 'cancelled', 'invoice'];
if (!in_array($report, $allowed, true)) {
    $report = 'list';
}
$saleClass = strtolower(trim((string)($_GET['sale_class'] ?? 'completed')));
if (!in_array($saleClass, ['completed', 'legacy', 'recorded'], true)) {
    $saleClass = 'completed';
}

$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$cashier = trim((string)($_GET['cashier'] ?? ''));
$productId = trim((string)($_GET['product_id'] ?? ''));
$invoiceNumber = trim((string)($_GET['invoice_number'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$raw = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch' => $isAdmin ? $branchFilter : null,
    'cashier' => $cashier,
    'product_id' => $productId,
    'invoice_number' => $invoiceNumber,
    'page' => $page,
    'page_size' => 50,
    'sale_class' => $saleClass,
];

if ($export === 'csv') {
    $kindMap = [
        'list' => 'list',
        'by_branch' => 'by_branch',
        'by_cashier' => 'by_cashier',
        'by_product' => 'by_product',
        'payments' => 'payments',
        'cancelled' => 'list',
    ];
    $kind = $kindMap[$report] ?? '';
    if ($kind === '') {
        mysqli_close($conn);
        http_response_code(400);
        die('Export not available for this report.');
    }
    $exportClass = $report === 'cancelled' ? 'cancelled' : $saleClass;
    $raw['sale_class'] = $exportClass;
    $exp = psSalesReportingExportCsv($conn, $kind, array_merge($raw, ['sale_class' => $exportClass]));
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
$invoiceDetail = null;
$branchOptions = $isAdmin ? psBranchAuthorisedRegisteredNames($conn) : [];

$norm = psReportingNormalizeFilters($conn, $raw);
if (empty($norm['ok'])) {
    $error = (string)($norm['error'] ?? 'Report could not be loaded.');
} else {
    $f = $norm['filters'];
    if ($report === 'invoice') {
        $invoiceDetail = psSalesReportingInvoiceDetail($conn, $invoiceNumber);
        if (empty($invoiceDetail['ok'])) {
            $error = (string)($invoiceDetail['error'] ?? 'Invoice not found.');
            $invoiceDetail = null;
        }
    } elseif ($report === 'by_branch') {
        $result = psSalesReportingByBranch($conn, $f, $saleClass);
    } elseif ($report === 'by_cashier') {
        $result = psSalesReportingByCashier($conn, $f, $saleClass);
    } elseif ($report === 'by_product') {
        $result = psSalesReportingByProduct($conn, $f, $saleClass);
    } elseif ($report === 'payments') {
        $result = psSalesReportingPaymentMethods($conn, $f);
    } elseif ($report === 'by_currency') {
        $result = psSalesReportingByCurrency($conn, $f);
    } elseif ($report === 'refunds') {
        $result = psSalesReportingRefundSummary($conn, $f);
    } elseif ($report === 'cancelled') {
        $result = psSalesReportingCancelled($conn, $f);
    } else {
        $result = psSalesReportingList($conn, $f, $saleClass);
    }
    if ($result !== null && empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Report could not be loaded.');
        $result = null;
    }
}

mysqli_close($conn);

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$q = static function (array $extra = []) use ($report, $saleClass, $fromDate, $toDate, $branchFilter, $cashier, $productId, $invoiceNumber, $isAdmin): string {
    $base = [
        'report' => $report,
        'sale_class' => $saleClass,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'cashier' => $cashier,
        'product_id' => $productId,
        'invoice_number' => $invoiceNumber,
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
    <title>Sales Reports</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Sales Reports</h1>
    <p class="hint">Cancelled sales are left out of the totals.</p>
    <p><a href="management_dashboard.php">Management dashboard</a></p>

    <div class="tabs">
        <?php
        $tabs = [
            'list' => 'Transactions',
            'by_branch' => 'By branch',
            'by_cashier' => 'By cashier',
            'by_product' => 'By product',
            'payments' => 'Payment methods',
            'by_currency' => 'By currency',
            'refunds' => 'Refunds',
            'cancelled' => 'Cancelled',
        ];
        foreach ($tabs as $k => $label):
            $cls = $report === $k ? 'on' : '';
            ?>
            <a class="<?php echo $cls; ?>" href="sales_reports.php<?php echo $h($q(['report' => $k, 'page' => 1])); ?>"><?php echo $h($label); ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="sales_reports.php" style="margin-top:16px;">
        <input type="hidden" name="report" value="<?php echo $h($report); ?>">
        <?php if (!in_array($report, ['payments', 'by_currency', 'refunds', 'cancelled', 'invoice'], true)): ?>
            <label>Sale class
                <select name="sale_class">
                    <option value="completed"<?php echo $saleClass === 'completed' ? ' selected' : ''; ?>>Modern completed</option>
                    <option value="legacy"<?php echo $saleClass === 'legacy' ? ' selected' : ''; ?>>Legacy recorded</option>
                    <option value="recorded"<?php echo $saleClass === 'recorded' ? ' selected' : ''; ?>>All non-cancelled</option>
                </select>
            </label>
        <?php endif; ?>
        <label>From <input type="date" name="from_date" value="<?php echo $h($fromDate); ?>"></label>
        <label>To <input type="date" name="to_date" value="<?php echo $h($toDate); ?>"></label>
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
        <?php if (in_array($report, ['list', 'by_cashier', 'by_product'], true)): ?>
            <label>Cashier <input type="text" name="cashier" value="<?php echo $h($cashier); ?>" maxlength="250"></label>
        <?php endif; ?>
        <?php if ($report === 'by_product'): ?>
            <label>Product ID <input type="text" name="product_id" value="<?php echo $h($productId); ?>" maxlength="250"></label>
        <?php endif; ?>
        <?php if ($report === 'list' || $report === 'invoice'): ?>
            <label>Invoice <input type="text" name="invoice_number" value="<?php echo $h($invoiceNumber); ?>" maxlength="250"></label>
        <?php endif; ?>
        <button type="submit">Apply</button>
        <?php if (in_array($report, ['list', 'by_branch', 'by_cashier', 'by_product', 'payments', 'cancelled'], true)): ?>
            <a href="sales_reports.php<?php echo $h($q(['export' => 'csv'])); ?>">Export CSV</a>
        <?php endif; ?>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?php echo $h($error); ?></p>
    <?php elseif ($invoiceDetail): ?>
        <?php $hdr = $invoiceDetail['header']; ?>
        <h2>Invoice <?php echo $h($hdr['invoice_number']); ?></h2>
        <p>
            Branch: <?php echo $h($hdr['branch']); ?> ·
            Date: <?php echo $h($hdr['date']); ?> ·
            Cashier: <?php echo $h($hdr['cashier']); ?> ·
            Status: <?php echo $h($hdr['status']); ?> ·
            Total: <?php echo $h($hdr['total']); ?>
        </p>
        <p class="hint"><?php echo $h($hdr['header_method_note']); ?></p>
        <p><a href="my_receipt.php?invoice_number=<?php echo $h($hdr['invoice_number']); ?>">Open receipt</a></p>
        <h3>Lines</h3>
        <table>
            <thead><tr><th>Product ID</th><th>Product</th><th>Branch</th><th>Qty</th><th>Unit</th><th>Revenue</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($invoiceDetail['lines'] as $line): ?>
                <tr>
                    <td><?php echo $h($line['product_id']); ?></td>
                    <td><?php echo $h($line['product']); ?></td>
                    <td><?php echo $h($line['branch']); ?></td>
                    <td><?php echo $h($line['quantity']); ?></td>
                    <td><?php echo $h($line['unit_price']); ?></td>
                    <td><?php echo $h($line['revenue']); ?></td>
                    <td><?php echo $h($line['status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h3>Payments</h3>
        <?php if ($invoiceDetail['payments']): ?>
            <table>
                <thead><tr><th>Method</th><th>Paid</th><th>Change</th><th>Currency</th><th>Ref</th><th>At</th></tr></thead>
                <tbody>
                <?php foreach ($invoiceDetail['payments'] as $p): ?>
                    <tr>
                        <td><?php echo $h($p['method']); ?></td>
                        <td><?php echo $h($p['amount_paid']); ?></td>
                        <td><?php echo $h($p['change_amount']); ?></td>
                        <td><?php echo $h($p['currency']); ?></td>
                        <td><?php echo $h($p['reference']); ?></td>
                        <td><?php echo $h($p['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="hint">No payment is recorded for this invoice (older sale or not paid).</p>
        <?php endif; ?>
    <?php elseif ($result): ?>
        <?php if ($report === 'by_branch' || $report === 'by_cashier'): ?>
            <?php $key = $report === 'by_branch' ? 'branch' : 'cashier'; ?>
            <table>
                <thead><tr><th><?php echo $report === 'by_branch' ? 'Branch' : 'Cashier'; ?></th><th>Txns</th><th>Revenue</th><th>ATV</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row[$key]); ?></td>
                        <td><?php echo (int)$row['txn_count']; ?></td>
                        <td><?php echo $h($row['revenue']); ?></td>
                        <td><?php echo $h($row['atv']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($result['totals'])): ?>
                <p class="totals">Total: <?php echo (int)$result['totals']['txn_count']; ?> txn · <?php echo $h($result['totals']['revenue']); ?> · ATV <?php echo $h($result['totals']['atv']); ?></p>
            <?php endif; ?>
        <?php elseif ($report === 'by_product'): ?>
            <table>
                <thead><tr><th>Product ID</th><th>Product</th><th>Branch</th><th>Qty</th><th>Revenue</th><th>Txns</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row['product_id']); ?></td>
                        <td><?php echo $h($row['product']); ?></td>
                        <td><?php echo $h($row['branch']); ?></td>
                        <td><?php echo $h($row['qty']); ?></td>
                        <td><?php echo $h($row['revenue']); ?></td>
                        <td><?php echo (int)$row['txn_count']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($result['totals'])): ?>
                <p class="totals">Filtered total: qty <?php echo $h($result['totals']['qty']); ?> · <?php echo $h($result['totals']['revenue']); ?> · <?php echo (int)$result['totals']['txn_count']; ?> invoices</p>
            <?php endif; ?>
        <?php elseif ($report === 'payments'): ?>
            <p class="hint"><?php echo $h($result['limitation'] ?? ''); ?></p>
            <table>
                <thead><tr><th>Method</th><th>Txns</th><th>Sale total</th><th>Amount paid</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row['method']); ?></td>
                        <td><?php echo (int)$row['txn_count']; ?></td>
                        <td><?php echo $h($row['sale_total']); ?></td>
                        <td><?php echo $h($row['amount_paid']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($report === 'by_currency'): ?>
            <p class="hint">Completed sales in each currency they were sold in. "In <?php echo $h($result['base'] ?? ''); ?>" converts each sale at the rate saved with it; that column adds up to the consolidated revenue shown on the other tabs.</p>
            <table>
                <thead><tr><th>Currency</th><th>Txns</th><th>Amount (in that currency)</th><th>In <?php echo $h($result['base'] ?? ''); ?></th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row['currency']); ?></td>
                        <td><?php echo (int)$row['txn_count']; ?></td>
                        <td><?php echo $h($row['amount']); ?></td>
                        <td><?php echo $h($row['base_amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="totals">Consolidated: <?php echo $h(($result['totals']['base_amount'] ?? '0.00') . ' ' . ($result['base'] ?? '')); ?> · <?php echo (int)($result['totals']['txn_count'] ?? 0); ?> sales</p>
        <?php elseif ($report === 'refunds'): ?>
            <p class="hint"><?php echo $h($result['note'] ?? ''); ?></p>
            <table>
                <thead><tr><th>Status</th><th>Count</th><th>Amount</th><th>Completed financial</th></tr></thead>
                <tbody>
                <?php foreach ($result['by_status'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row['status']); ?></td>
                        <td><?php echo (int)$row['count']; ?></td>
                        <td><?php echo $h($row['amount']); ?></td>
                        <td><?php echo !empty($row['is_completed_financial']) ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($result['by_status'])): ?>
                    <tr><td colspan="4">No refund requests for these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead><tr><th>Invoice</th><th>Date</th><th>Branch</th><th>Cashier</th><th>Status</th><th>Pay</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><a href="sales_reports.php<?php echo $h($q(['report' => 'invoice', 'invoice_number' => $row['invoice_number']])); ?>"><?php echo $h($row['invoice_number']); ?></a></td>
                        <td><?php echo $h($row['date']); ?></td>
                        <td><?php echo $h($row['branch']); ?></td>
                        <td><?php echo $h($row['cashier']); ?></td>
                        <td><?php echo $h($row['status']); ?></td>
                        <td><?php echo $h($row['payment_method']); ?></td>
                        <td><?php echo $h($row['total'] . ' ' . ($row['currency'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="7">No rows for these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if (!empty($result['totals'])): ?>
                <p class="totals">Filtered total: <?php echo (int)$result['totals']['txn_count']; ?> txn · <?php echo $h($result['totals']['revenue']); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($result['pagination']) && (int)$result['pagination']['total_pages'] > 1): ?>
            <?php $pg = $result['pagination']; ?>
            <p>
                Page <?php echo (int)$pg['page']; ?> / <?php echo (int)$pg['total_pages']; ?>
                <?php if ((int)$pg['page'] > 1): ?>
                    · <a href="sales_reports.php<?php echo $h($q(['page' => (int)$pg['page'] - 1])); ?>">Prev</a>
                <?php endif; ?>
                <?php if ((int)$pg['page'] < (int)$pg['total_pages']): ?>
                    · <a href="sales_reports.php<?php echo $h($q(['page' => (int)$pg['page'] + 1])); ?>">Next</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
