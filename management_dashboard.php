<?php
/**
 * Phase 10 — Management dashboard (read-only).
 * Metrics from authorised-branch data only. Does not mutate financial/stock records.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/dashboard_reporting.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psReportingRequireAuthRedirect();
psReportingEnsureTimezone();

$isAdmin = psReportingIsAdmin();
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$error = '';
$dash = null;
$branchOptions = [];

if ($fromDate === '' && $toDate === '') {
    // Default: current month to date
    $bounds = psReportingPeriodBounds('month');
    $fromDate = $bounds['from_date'];
    $toDate = $bounds['to_date'];
}

$raw = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch' => $isAdmin ? $branchFilter : null,
];
$dash = psDashboardReportingBuild($conn, $raw);
if (empty($dash['ok'])) {
    $error = (string)($dash['error'] ?? 'Dashboard could not be loaded.');
    $dash = null;
} else {
    $branchOptions = $dash['filters']['branches'] ?? [];
    if ($isAdmin) {
        $branchOptions = psBranchAuthorisedRegisteredNames($conn);
    }
}

mysqli_close($conn);

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$money = static function ($v): string {
    return number_format((float)$v, 2, '.', ',');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Dashboard</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Management Dashboard'); ?>
    <h1>Management Dashboard</h1>
    <p class="ps-page-lead">Sales, stock, cash and refunds for the branches you can see. Cancelled sales are left out. Older sales recorded before payments were tracked are shown separately.</p>

    <div class="ps-link-row" aria-label="Related reports">
        <a class="ps-btn ps-btn-outline ps-btn-sm" href="sales_reports.php">Sales reports</a>
        <a class="ps-btn ps-btn-outline ps-btn-sm" href="inventory_reports.php">Inventory reports</a>
        <a class="ps-btn ps-btn-outline ps-btn-sm" href="purchasing_reports.php">Purchasing reports</a>
        <a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_management.php">Cash management</a>
        <a class="ps-btn ps-btn-outline ps-btn-sm" href="refund_recon_report.php">Refund recon</a>
        <?php if ($isAdmin): ?>
            <a class="ps-btn ps-btn-outline ps-btn-sm" href="admin_dashboard.php">Admin home</a>
        <?php endif; ?>
    </div>

    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title">Period filters</h2>
            <p class="ps-card-desc">Choose a date range<?php echo $isAdmin ? ' and optional branch' : ''; ?>. Metrics stay read-only.</p>
        </div>
        <div class="ps-card-content">
            <form method="get" action="management_dashboard.php" class="ps-form-inline">
                <label class="ps-label" for="from_date">From</label>
                <input class="ps-input" id="from_date" type="date" name="from_date" value="<?php echo $h($fromDate); ?>">
                <label class="ps-label" for="to_date">To</label>
                <input class="ps-input" id="to_date" type="date" name="to_date" value="<?php echo $h($toDate); ?>">
                <?php if ($isAdmin): ?>
                    <label class="ps-label" for="branch">Branch</label>
                    <select class="ps-input" id="branch" name="branch">
                        <option value="">All authorised</option>
                        <?php foreach ($branchOptions as $bn): ?>
                            <option value="<?php echo $h($bn); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>><?php echo $h($bn); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button class="ps-btn ps-btn-default" type="submit">Apply</button>
                <a class="ps-btn ps-btn-outline" href="management_dashboard.php">Reset</a>
            </form>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
    <?php elseif ($dash): ?>
        <?php
        $t = $dash['today'] ?? null;
        $w = $dash['week'] ?? null;
        $m = $dash['month'] ?? null;
        $rc = $dash['range_completed'] ?? null;
        $rl = $dash['range_legacy'] ?? null;
        $renderDelta = static function (?array $period) use ($h, $money): string {
            if (!$period || empty($period['ok'])) {
                return '';
            }
            $d = (float)($period['delta_revenue'] ?? 0);
            $cls = $d > 0 ? 'delta-up' : ($d < 0 ? 'delta-down' : '');
            $pct = $period['delta_pct'];
            $txt = 'vs prior: ' . $money($period['delta_revenue'] ?? 0);
            if ($pct !== null) {
                $txt .= ' (' . $h($pct) . '%)';
            } else {
                $txt .= ' (n/a %)';
            }
            return '<div class="ps-kpi-sub ' . $cls . '">' . $h($txt) . '</div>';
        };
        ?>

        <?php if (!empty($dash['alerts'])): ?>
            <section class="ps-section">
                <h2>Operational alerts</h2>
                <?php foreach ($dash['alerts'] as $a): ?>
                    <?php $sev = (string)($a['severity'] ?? 'info'); ?>
                    <div class="ps-alert <?php echo $sev === 'high' || $sev === 'medium' ? 'ps-alert-destructive' : 'ps-alert-info'; ?>" role="alert">
                        <?php echo $h($a['message'] ?? ''); ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="ps-section">
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Sales — modern completed</h2>
                    <p class="ps-card-desc">Today, week, month, and filtered-range revenue from modern completed sales.</p>
                </div>
                <div class="ps-card-content">
            <div class="ps-kpi-grid">
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Today</div>
                    <div class="ps-kpi-value"><?php echo $h($money($t['current']['revenue'] ?? 0)); ?></div>
                    <div class="ps-kpi-sub"><?php echo (int)($t['current']['txn_count'] ?? 0); ?> txn · ATV <?php echo $h($money($t['current']['atv'] ?? 0)); ?></div>
                    <?php echo $renderDelta($t); ?>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">This week</div>
                    <div class="ps-kpi-value"><?php echo $h($money($w['current']['revenue'] ?? 0)); ?></div>
                    <div class="ps-kpi-sub"><?php echo (int)($w['current']['txn_count'] ?? 0); ?> txn · ATV <?php echo $h($money($w['current']['atv'] ?? 0)); ?></div>
                    <?php echo $renderDelta($w); ?>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">This month</div>
                    <div class="ps-kpi-value"><?php echo $h($money($m['current']['revenue'] ?? 0)); ?></div>
                    <div class="ps-kpi-sub"><?php echo (int)($m['current']['txn_count'] ?? 0); ?> txn · ATV <?php echo $h($money($m['current']['atv'] ?? 0)); ?></div>
                    <?php echo $renderDelta($m); ?>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Filtered range (completed)</div>
                    <div class="ps-kpi-value"><?php echo $h($money($rc['revenue'] ?? 0)); ?></div>
                    <div class="ps-kpi-sub"><?php echo (int)($rc['txn_count'] ?? 0); ?> txn · ATV <?php echo $h($money($rc['atv'] ?? 0)); ?></div>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Filtered range (legacy recorded)</div>
                    <div class="ps-kpi-value"><?php echo $h($money($rl['revenue'] ?? 0)); ?></div>
                    <div class="ps-kpi-sub"><?php echo (int)($rl['txn_count'] ?? 0); ?> txn · not modern completed</div>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Cancelled (filtered)</div>
                    <div class="ps-kpi-value"><?php echo (int)($dash['cancelled']['txn_count'] ?? 0); ?></div>
                    <div class="ps-kpi-sub">Excluded from sales totals</div>
                </div>
            </div>
                </div>
            </div>
        </section>

        <section class="ps-section">
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Sales by branch (completed)</h2>
                    <p class="ps-card-desc">Modern completed sales grouped by branch for the selected filters.</p>
                </div>
                <div class="ps-card-content">
            <?php $bb = $dash['by_branch_completed'] ?? null; ?>
            <?php if ($bb && !empty($bb['rows'])): ?>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead><tr><th>Branch</th><th>Txns</th><th>Revenue</th><th>ATV</th></tr></thead>
                        <tbody>
                        <?php foreach ($bb['rows'] as $row): ?>
                            <tr>
                                <td><?php echo $h($row['branch']); ?></td>
                                <td><?php echo (int)$row['txn_count']; ?></td>
                                <td><?php echo $h($money($row['revenue'])); ?></td>
                                <td><?php echo $h($money($row['atv'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ps-empty">No modern completed sales in this filter. See legacy table below when present.</div>
            <?php endif; ?>

            <?php $bbl = $dash['by_branch_legacy'] ?? null; ?>
            <?php if ($bbl && !empty($bbl['rows'])): ?>
                <h3 class="ps-card-title ps-split-gap">Legacy recorded by branch</h3>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead><tr><th>Branch</th><th>Txns</th><th>Revenue</th><th>ATV</th></tr></thead>
                        <tbody>
                        <?php foreach ($bbl['rows'] as $row): ?>
                            <tr>
                                <td><?php echo $h($row['branch']); ?></td>
                                <td><?php echo (int)$row['txn_count']; ?></td>
                                <td><?php echo $h($money($row['revenue'])); ?></td>
                                <td><?php echo $h($money($row['atv'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="ps-section">
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Payment methods</h2>
                    <p class="ps-card-desc">How customers paid, for the selected period.</p>
                </div>
                <div class="ps-card-content">
            <?php $pay = $dash['payments'] ?? null; ?>
            <?php if ($pay && !empty($pay['rows'])): ?>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead><tr><th>Method</th><th>Txns</th><th>Sale total</th><th>Amount paid</th></tr></thead>
                        <tbody>
                        <?php foreach ($pay['rows'] as $row): ?>
                            <tr>
                                <td><?php echo $h($row['method']); ?></td>
                                <td><?php echo (int)$row['txn_count']; ?></td>
                                <td><?php echo $h($money($row['sale_total'])); ?></td>
                                <td><?php echo $h($money($row['amount_paid'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ps-empty"><?php echo $h($pay['limitation'] ?? 'No sale_payments rows in scope.'); ?></div>
            <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="ps-section">
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Refunds</h2>
                    <p class="ps-card-desc">Refund request counts and amounts by status.</p>
                </div>
                <div class="ps-card-content">
            <?php $ref = $dash['refunds'] ?? null; ?>
            <?php if ($ref && !empty($ref['by_status'])): ?>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead><tr><th>Status</th><th>Count</th><th>Amount</th><th>Financial?</th></tr></thead>
                        <tbody>
                        <?php foreach ($ref['by_status'] as $row): ?>
                            <tr>
                                <td><?php echo $h($row['status']); ?></td>
                                <td><?php echo (int)$row['count']; ?></td>
                                <td><?php echo $h($money($row['amount'])); ?></td>
                                <td><?php echo !empty($row['is_completed_financial']) ? 'Yes (PROCESSED)' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ps-empty">No refund requests in scope. <?php echo $h($ref['note'] ?? ''); ?></div>
            <?php endif; ?>
        </section>

        <section class="ps-section">
            <h2>Inventory</h2>
            <?php $ia = $dash['inventory_alerts'] ?? null; ?>
            <div class="ps-kpi-grid">
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Out of stock</div>
                    <div class="ps-kpi-value"><?php echo (int)($ia['out_count'] ?? 0); ?></div>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Low stock</div>
                    <div class="ps-kpi-value"><?php echo (int)($ia['low_count'] ?? 0); ?></div>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Ledger-controlled SKUs</div>
                    <div class="ps-kpi-value"><?php echo (int)($ia['ledger_count'] ?? 0); ?></div>
                </div>
                <div class="ps-kpi">
                    <div class="ps-kpi-label">Stock value</div>
                    <div class="ps-kpi-value">
                        <?php
                        $sv = $ia['stock_value'] ?? null;
                        echo $sv === null ? '—' : $h($money($sv));
                        ?>
                    </div>
                </div>
            </div>
            <p class="ps-link-row"><a class="ps-btn ps-btn-outline ps-btn-sm" href="inventory_reports.php">Open inventory reports</a></p>
        </section>

        <section class="ps-section">
            <h2>Cash / till</h2>
            <?php // 2026-11-04 Dusk: valuation note moved out of the Stock value box (it stretched the row); same pattern as Cash / till. ?>
            <?php if (trim((string)($ia['valuation_note'] ?? '')) !== ''): ?>
                <p class="ps-page-lead"><?php echo $h($ia['valuation_note']); ?></p>
            <?php endif; ?>
            <?php $cash = $dash['cash'] ?? null; ?>
            <?php if ($cash && !empty($cash['ok']) && is_array($cash['cards'] ?? null)): ?>
                <?php $cc = $cash['cards']; ?>
                <div class="ps-kpi-grid">
                    <div class="ps-kpi"><div class="ps-kpi-label">Open tills</div><div class="ps-kpi-value"><?php echo (int)($cc['open_tills'] ?? 0); ?></div></div>
                    <div class="ps-kpi"><div class="ps-kpi-label">Expected cash (open)</div><div class="ps-kpi-value"><?php echo $h($money($cc['expected_cash'] ?? 0)); ?></div></div>
                    <div class="ps-kpi"><div class="ps-kpi-label">Cash sales (period)</div><div class="ps-kpi-value"><?php echo $h($money($cc['cash_sales'] ?? 0)); ?></div></div>
                    <div class="ps-kpi"><div class="ps-kpi-label">Cash refunds (period)</div><div class="ps-kpi-value"><?php echo $h($money($cc['cash_refunds'] ?? 0)); ?></div></div>
                </div>
                <?php if (trim((string)($cash['limitation'] ?? '')) !== ''): ?>
                    <p class="ps-page-lead"><?php echo $h($cash['limitation']); ?></p>
                <?php endif; ?>
                <p class="ps-link-row"><a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_management.php">Open cash management</a></p>
            <?php else: ?>
                <div class="ps-empty"><?php echo $h($cash['limitation'] ?? $cash['error'] ?? 'Cash summary unavailable.'); ?></div>
            <?php endif; ?>
        </section>

        <section class="ps-section">
            <h2>Recent sales</h2>
            <?php
            $recent = [];
            if (!empty($dash['recent_completed']['rows'])) {
                $recent = $dash['recent_completed']['rows'];
                $recentLabel = 'Modern completed';
            } elseif (!empty($dash['recent_legacy']['rows'])) {
                $recent = $dash['recent_legacy']['rows'];
                $recentLabel = 'Legacy recorded (not modern completed)';
            } else {
                $recentLabel = 'None';
            }
            ?>
            <p class="ps-page-lead"><?php echo $h($recentLabel); ?></p>
            <?php if ($recent): ?>
                <div class="ps-table-wrap">
                    <table>
                        <thead><tr><th>Invoice</th><th>Date</th><th>Branch</th><th>Cashier</th><th>Status</th><th>Pay</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td><a href="sales_reports.php?report=invoice&amp;invoice_number=<?php echo $h($row['invoice_number']); ?>"><?php echo $h($row['invoice_number']); ?></a></td>
                                <td><?php echo $h($row['date']); ?></td>
                                <td><?php echo $h($row['branch']); ?></td>
                                <td><?php echo $h($row['cashier']); ?></td>
                                <td><?php echo $h($row['status']); ?></td>
                                <td><?php echo $h($row['payment_method']); ?></td>
                                <td><?php echo $h($money($row['total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="ps-empty">No recent sales in this view.</div>
            <?php endif; ?>
        </section>

        <section class="ps-section">
            <h2>Limitations</h2>
            <div class="ps-card">
                <div class="ps-card-content">
                    <ul class="ps-list">
                        <?php foreach (($dash['limitations'] ?? []) as $lim): ?>
                            <li><?php echo $h($lim); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
