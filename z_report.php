<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require 'vendor/autoload.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psBranchRequireAuthRedirect();
$zScope = psBranchRequireAuthorisedWorking($conn);
if (empty($zScope['ok'])) {
    die(htmlspecialchars((string)($zScope['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$zBranch = mysqli_real_escape_string($conn, (string)$zScope['branch']);

// Multi-currency (2026-10-20): totals in the company base (each sale at its own rate; older sales are base).
require_once __DIR__ . '/includes/currency.php';
$zSum = psFxHasColumn($conn, 'online_sales_ref', 'base_total') ? 'SUM(COALESCE(base_total, CAST(totalz AS DECIMAL(14,2))))' : 'SUM(totalz)';
$zBaseCur = psFxBase($conn, psFxSessionCompany());
$total_sales = 0;
$sales_data = [];
$start_date = $end_date = '';

if (isset($_POST['start_date']) && isset($_POST['end_date'])) {
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date = mysqli_real_escape_string($conn, $_POST['end_date']);

    $sql = "SELECT cashier, $zSum AS total_sales 
            FROM online_sales_ref 
            WHERE date_ BETWEEN '$start_date' AND '$end_date' AND  branch='$zBranch'
              AND COALESCE(status, '') <> 'CANCELLED'
            GROUP BY cashier";
} else {
    $current_date = date('Y-m-d');
    $sql = "SELECT cashier, $zSum AS total_sales 
            FROM online_sales_ref 
            WHERE date_ = '$current_date' AND branch='$zBranch'
              AND COALESCE(status, '') <> 'CANCELLED'
            GROUP BY cashier";
}

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $sales_data[] = $row;
        $total_sales += $row['total_sales'];
    }
} else {
    error_log('z_report: sales query failed');
}

mysqli_close($conn);

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$chartLabels = array_column($sales_data, 'cashier');
$chartSales = array_map(static function ($v) {
    return round((float)$v, 2);
}, array_column($sales_data, 'total_sales'));
$branchLabel = trim((string)($_SESSION['selected_branch'] ?? $_SESSION['branch'] ?? $zScope['branch'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Z Report</title>
    <script src="assets/lib/chart-4.4.1.umd.js"></script>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Z Report'); ?>

<h1>Z Report</h1>
<p class="ps-page-lead">Cashier sales totals for the working branch<?php echo $branchLabel !== '' ? ' (' . $h($branchLabel) . ')' : ''; ?>.</p>

<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="admin_dashboard.php">Dashboard</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="cashier_sales.php">Cashier Sales</a>
    <button class="ps-btn ps-btn-outline ps-btn-sm" type="button" onclick="window.print()">Print</button>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="generate_pdf.php?start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>">Generate PDF</a>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Optional date range. Defaults to today when blank.</p>
    </div>
    <div class="ps-card-content">
        <form method="POST" class="ps-form-inline filter-form">
            <label>
                <span class="ps-label">Start date</span>
                <input class="ps-input" type="date" name="start_date" value="<?php echo $h($start_date); ?>" required>
            </label>
            <label>
                <span class="ps-label">End date</span>
                <input class="ps-input" type="date" name="end_date" value="<?php echo $h($end_date); ?>" required>
            </label>
            <button class="ps-btn ps-btn-default" type="submit">Filter</button>
        </form>
    </div>
</div>

<div class="ps-kpi-grid" aria-label="Totals">
    <div class="ps-kpi">
        <div class="ps-kpi-label">Total sales (in <?php echo $h($zBaseCur); ?>)</div>
        <div class="ps-kpi-value"><?php echo $h(ps_money($total_sales, $zBaseCur)); ?></div>
        <?php if ($start_date && $end_date): ?>
            <div class="ps-kpi-sub"><?php echo $h($start_date); ?> → <?php echo $h($end_date); ?></div>
        <?php else: ?>
            <div class="ps-kpi-sub">Today</div>
        <?php endif; ?>
    </div>
    <div class="ps-kpi">
        <div class="ps-kpi-label">Cashiers</div>
        <div class="ps-kpi-value"><?php echo (int)count($sales_data); ?></div>
        <div class="ps-kpi-sub">With sales in range</div>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Sales by cashier</h2>
        <p class="ps-card-desc">Click a cashier to open their detailed sales.</p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th class="num">Total sales</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sales_data)): ?>
                    <tr>
                        <td colspan="2" class="ps-muted">No sales records found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sales_data as $data): ?>
                        <tr>
                            <td>
                                <a href="cashier_sales.php?cashier=<?php echo urlencode($data['cashier']); ?>&amp;start_date=<?php echo urlencode($start_date); ?>&amp;end_date=<?php echo urlencode($end_date); ?>">
                                    <?php echo $h($data['cashier']); ?>
                                </a>
                            </td>
                            <td class="num"><?php echo $h(ps_money($data['total_sales'], $zBaseCur)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="ps-card ps-chart-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Total Sales per Cashier</h2>
        <p class="ps-card-desc">Theme-aware bar chart of the table totals above.</p>
    </div>
    <div class="ps-card-content">
        <?php if (empty($sales_data)): ?>
            <div class="ps-chart-empty">No chart data for this range.</div>
        <?php else: ?>
            <div class="ps-chart-wrap">
                <canvas id="salesChart" aria-label="Total sales per cashier chart" role="img"></canvas>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($sales_data)): ?>
<script>
(function () {
    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }
    function themeColors() {
        var accent = cssVar('--ps-blue', '#3b82f6');
        var text = cssVar('--ps-text', '#e8eaed');
        var muted = cssVar('--ps-muted', '#9aa3b2');
        var line = cssVar('--ps-line', 'rgba(148,163,184,0.35)');
        var card = cssVar('--ps-card', '#1b1e25');
        return {
            accent: accent,
            fill: accent,
            text: text,
            muted: muted,
            line: line,
            tooltipBg: card,
            tooltipBorder: line
        };
    }
    function hexToRgba(hex, alpha) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        if (!m) return 'rgba(59,130,246,' + alpha + ')';
        return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + alpha + ')';
    }

    var labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
    var sales = <?php echo json_encode($chartSales); ?>;
    var canvas = document.getElementById('salesChart');
    if (!canvas || typeof Chart === 'undefined') return;

    var chart;

    function build() {
        var c = themeColors();
        var cfg = {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total sales ($)',
                    data: sales,
                    backgroundColor: hexToRgba(c.accent, 0.55),
                    hoverBackgroundColor: hexToRgba(c.accent, 0.85),
                    borderColor: c.accent,
                    borderWidth: 1.5,
                    borderRadius: 8,
                    borderSkipped: false,
                    maxBarThickness: 56
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 450, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    title: { display: false },
                    tooltip: {
                        backgroundColor: c.tooltipBg,
                        titleColor: c.text,
                        bodyColor: c.text,
                        borderColor: c.tooltipBorder,
                        borderWidth: 1,
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: function (ctx) {
                                var n = Number(ctx.parsed.y || 0);
                                return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: {
                            color: c.muted,
                            maxRotation: 45,
                            minRotation: 0,
                            autoSkip: true,
                            font: { size: 12, weight: '500' }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: c.line, drawBorder: false },
                        ticks: {
                            color: c.muted,
                            font: { size: 12 },
                            callback: function (v) {
                                return Number(v).toLocaleString();
                            }
                        }
                    }
                }
            }
        };
        if (chart) {
            chart.destroy();
        }
        chart = new Chart(canvas.getContext('2d'), cfg);
    }

    build();

    var obs = new MutationObserver(function () { build(); });
    obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
</script>
<?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
