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
$allowed = ['list', 'by_branch', 'by_cashier', 'by_product', 'payments', 'by_currency', 'by_dine', 'refunds', 'cancelled', 'invoice'];
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
// 2026-10-21: currency view (Consolidated in the base / By currency) and currency filter.
$fx = psFxrContext($conn, $_GET);

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
    'fx_view' => $fx['view'],
    'fx_cur' => $fx['cur'],
];

if ($export === 'csv') {
    $kindMap = [
        'list' => 'list',
        'by_branch' => 'by_branch',
        'by_cashier' => 'by_cashier',
        'by_product' => 'by_product',
        'payments' => 'payments',
        'cancelled' => 'list',
        'by_currency' => 'by_currency',
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
    $f['fx'] = $fx;
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
    } elseif ($report === 'by_dine') {
        $result = psSalesReportingByDine($conn, $f, $saleClass); // 2026-11-13
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

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$q = static function (array $extra = []) use ($report, $saleClass, $fromDate, $toDate, $branchFilter, $cashier, $productId, $invoiceNumber, $isAdmin, $fx): string {
    $base = psFxrQuery($fx) + [
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
// Money cells: one helper for every amount on the page (consolidated with conversion marker / original currencies).
$m = static fn(array $mix, array $opt = []): string => psFxrAmount($conn, $fx, $mix, $opt);
$keepQuery = array_filter([
    'report' => $report, 'sale_class' => $saleClass, 'from_date' => $fromDate, 'to_date' => $toDate,
    'cashier' => $cashier, 'product_id' => $productId, 'invoice_number' => $invoiceNumber,
    'branch' => $isAdmin ? $branchFilter : '',
], fn($v) => $v !== '' && $v !== null);
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
            'by_dine' => 'Sit in / Take away',
            'refunds' => 'Refunds',
            'cancelled' => 'Cancelled',
        ];
        // 2026-11-13: the Sit in / Take away tab only for companies that use it (or when it is open).
        require_once __DIR__ . '/includes/dine_option.php';
        if ($report !== 'by_dine' && !psDineOptionEnabled($conn)) {
            unset($tabs['by_dine']);
        }
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
        <?php if ($fx['view'] === PS_FXR_BREAKDOWN): ?><input type="hidden" name="fx_view" value="breakdown"><?php endif; ?>
        <?php if ($fx['cur'] !== ''): ?><input type="hidden" name="fx_cur" value="<?php echo $h($fx['cur']); ?>"><?php endif; ?>
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
        <?php if (in_array($report, ['list', 'by_branch', 'by_cashier', 'by_product', 'payments', 'cancelled', 'by_currency'], true)): ?>
            <a href="sales_reports.php<?php echo $h($q(['export' => 'csv'])); ?>">Export CSV</a>
        <?php endif; ?>
    </form>
    <?php if ($report !== 'invoice') { echo psFxrToolbar($fx, $keepQuery); } ?>

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
            Total: <?php echo $h(psFxFormat($conn, $hdr['total'], psFxNormalizeCode($hdr['fx_currency'] ?? '') ?: psFxLegacyCurrency())); ?>
        </p>
        <?php
        // Full conversion details on the transaction itself (not only in a tooltip).
        $invCur = psFxNormalizeCode($hdr['fx_currency'] ?? '');
        if ($invCur === '') {
            echo '<p class="hint">Legacy sale: recorded before currencies were stored. Its currency is assumed to be ' . $h(psFxLegacyCurrency()) . ' (1:1 with the base).</p>';
        } elseif ($invCur !== $fx['base']) {
            $invRateFrom = psFxrRateFrom($conn, (string)$hdr['fx_rate_ids']);
            echo '<div class="fxr-sum"><table class="fxr-sum-table"><tbody>'
                . '<tr><th>Sale currency</th><td>' . $h($invCur) . '</td></tr>'
                . '<tr><th>Total in sale currency</th><td>' . $h(psFxFormat($conn, $hdr['total'], $invCur)) . '</td></tr>'
                . '<tr><th>Exchange rate (stored)</th><td>' . $h($hdr['fx_rate'] !== '' ? '1 ' . $fx['base'] . ' = ' . psFxrRateText($hdr['fx_rate']) . ' ' . $invCur : 'not stored') . '</td></tr>'
                . ($invRateFrom !== '' ? '<tr><th>Rate in force from</th><td>' . $h($invRateFrom) . '</td></tr>' : '')
                . ($hdr['fx_path'] !== '' ? '<tr><th>Rate path</th><td>' . $h($hdr['fx_path']) . '</td></tr>' : '')
                . '<tr><th>Total in ' . $h($fx['base']) . '</th><td>' . ($hdr['base_total'] !== null ? $h(psFxFormat($conn, $hdr['base_total'], $fx['base'])) : '<span class="fxa-tag fxa-tag-warn">⚠ no stored rate</span>') . '</td></tr>'
                . '</tbody></table></div>';
        }
        $lineCur = $invCur !== '' ? $invCur : psFxLegacyCurrency();
        ?>
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
                    <td><?php echo $h(($line['sell_text'] ?? '') !== '' ? $line['sell_text'] : $line['quantity']); ?></td>
                    <td><?php echo ($line['sell_price'] ?? '') !== '' ? $h(psFxFormat($conn, $line['sell_price'], $lineCur) . ' / ' . $line['sell_unit']) : ($line['unit_price'] !== '' ? $h(psFxFormat($conn, $line['unit_price'], $lineCur)) : ''); ?></td>
                    <td><?php echo $h(psFxFormat($conn, $line['revenue'], $lineCur)); ?></td>
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
                        <td><?php echo $h(psFxFormat($conn, $p['amount_paid'], $p['currency'] !== '' ? $p['currency'] : $lineCur)); ?></td>
                        <td><?php echo $h(psFxFormat($conn, $p['change_amount'], $p['currency'] !== '' ? $p['currency'] : $lineCur)); ?></td>
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
        <?php if ($report === 'by_branch' || $report === 'by_cashier' || $report === 'by_dine'): ?>
            <?php $key = $report === 'by_branch' ? 'branch' : ($report === 'by_dine' ? 'dine' : 'cashier'); ?>
            <table>
                <thead><tr><th><?php echo $report === 'by_branch' ? 'Branch' : ($report === 'by_dine' ? 'Sit in / Take away' : 'Cashier'); ?></th><th>Txns</th><th>Revenue</th><th>ATV<?php echo $fx['view'] === PS_FXR_BREAKDOWN ? '' : ' (' . $h($fx['base']) . ')'; ?></th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row[$key]); ?></td>
                        <td><?php echo (int)$row['txn_count']; ?></td>
                        <td class="num"><?php echo $m($row['fx']); ?></td>
                        <td class="num"><?php echo $fx['view'] === PS_FXR_BREAKDOWN ? '—' : $h(psFxFormat($conn, $row['atv'], $fx['base'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($result['totals'])): ?>
                <p class="totals">Total: <?php echo (int)$result['totals']['txn_count']; ?> txn · <?php echo $m($result['totals']['fx'], ['strong' => true]); ?><?php if ($fx['view'] !== PS_FXR_BREAKDOWN): ?> · ATV <?php echo $h(psFxFormat($conn, $result['totals']['atv'], $fx['base'])); ?><?php endif; ?></p>
                <?php echo psFxrSummary($conn, $fx, $result['totals']['fx']); ?>
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
                        <td class="num"><?php echo $m($row['fx']); ?></td>
                        <td><?php echo (int)$row['txn_count']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($result['totals'])): ?>
                <p class="totals">Filtered total: qty <?php echo $h($result['totals']['qty']); ?> · <?php echo $m($result['totals']['fx'], ['strong' => true]); ?> · <?php echo (int)$result['totals']['txn_count']; ?> invoices</p>
                <?php echo psFxrSummary($conn, $fx, $result['totals']['fx'], 'Product revenue by currency'); ?>
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
                        <td class="num"><?php echo $m($row['fx']); ?></td>
                        <td class="num"><?php echo $m($row['fx_paid']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($result['totals'])): ?>
                <tfoot><tr><th>Total</th><th><?php echo (int)$result['totals']['txn_count']; ?></th><th class="num"><?php echo $m($result['totals']['fx'], ['strong' => true]); ?></th><th class="num"><?php echo $m($result['totals']['fx_paid'], ['strong' => true]); ?></th></tr></tfoot>
                <?php endif; ?>
            </table>
            <?php echo psFxrSummary($conn, $fx, $result['totals']['fx_paid'] ?? [], 'Payments received by currency'); ?>
        <?php elseif ($report === 'by_currency'): ?>
            <p class="hint">Completed sales in each currency they were sold in. The <?php echo $h($fx['base']); ?> column converts each sale at the rate saved with it; it adds up to the consolidated revenue on the other tabs.</p>
            <?php $fx['multi'] = true; echo psFxrSummary($conn, $fx, $result['fx'] ?? [], 'Completed sales by currency'); ?>
            <?php if (empty($result['rows'])): ?><p class="hint">No sales for these filters.</p><?php endif; ?>
        <?php elseif ($report === 'refunds'): ?>
            <p class="hint"><?php echo $h($result['note'] ?? ''); ?></p>
            <table>
                <thead><tr><th>Status</th><th>Count</th><th>Amount</th><th>Completed financial</th></tr></thead>
                <tbody>
                <?php foreach ($result['by_status'] as $row): ?>
                    <tr>
                        <td><?php echo $h($row['status']); ?></td>
                        <td><?php echo (int)$row['count']; ?></td>
                        <td class="num"><?php echo $m($row['fx']); ?></td>
                        <td><?php echo !empty($row['is_completed_financial']) ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($result['by_status'])): ?>
                    <tr><td colspan="4">No refund requests for these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php echo psFxrSummary($conn, $fx, $result['processed']['fx'] ?? [], 'Processed refunds by currency'); ?>
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
                        <td class="num"><?php echo psFxrOne($conn, $fx, $row['fx_currency'], $row['total'], $row['base_total'], ['rate' => $row['fx_rate'], 'ids' => $row['fx_rate_ids'], 'ref' => 'Invoice ' . $row['invoice_number']], ['at' => $row['date']]); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="7">No rows for these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if (!empty($result['totals'])): ?>
                <p class="totals">Filtered total: <?php echo (int)$result['totals']['txn_count']; ?> txn · <?php echo $m($result['totals']['fx'], ['strong' => true]); ?></p>
                <?php echo psFxrSummary($conn, $fx, $result['totals']['fx']); ?>
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
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
