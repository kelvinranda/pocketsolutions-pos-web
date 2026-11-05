<?php
/**
 * Z Report PDF (linked from z_report.php).
 * Health-check fix 2026-10-13: login required, same working-branch scope and
 * cancelled-sale exclusion as the on-screen Z Report, prepared statement.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'vendor/autoload.php'; // Include MPDF
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);
psBranchRequireAuthRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    error_log('generate_pdf: connection failed');
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

$zScope = psBranchRequireAuthorisedWorking($conn);
if (empty($zScope['ok'])) {
    die(htmlspecialchars((string)($zScope['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$zBranch = (string)$zScope['branch'];

$isDate = static function (string $v): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
};
$start_date = trim((string)($_GET['start_date'] ?? ''));
$end_date = trim((string)($_GET['end_date'] ?? ''));
if (!$isDate($start_date)) {
    $start_date = date('Y-m-d');
}
if (!$isDate($end_date)) {
    $end_date = $start_date;
}

// 2026-10-21: per-currency totals; consolidated in the base at each sale's stored rate (same as the on-screen Z Report).
require_once __DIR__ . '/includes/fx_report.php';
$fx = psFxrContext($conn, $_GET);
$where = "date_ BETWEEN ? AND ? AND branch = ? AND COALESCE(status, '') <> 'CANCELLED'" . psFxrCurFilterSql($conn, $fx, 'currency');
$zg = psFxrGrouped($conn, $fx,
    'SELECT cashier AS k0, ' . psFxrAggSelectSql($conn, $fx, 'currency', 'CAST(totalz AS DECIMAL(14,2))', 'base_total', 'fx_rate') . '
     FROM online_sales_ref WHERE ' . $where . ' GROUP BY cashier, currency',
    'sss', [$start_date, $end_date, $zBranch], 1);
if (empty($zg['ok'])) {
    error_log('generate_pdf: query failed');
    $zg = ['groups' => [], 'total' => ['mix' => []]];
}
$sales_data = [];
foreach ($zg['groups'] as $grp) {
    $sales_data[] = ['cashier' => (string)$grp['keys'][0], 'mix' => $grp['mix']];
}
$totalMix = $zg['total']['mix'];
$T = static fn(array $mix): string => htmlspecialchars(psFxrText($conn, $fx, $mix), ENT_QUOTES, 'UTF-8');
$curRows = '';
foreach (psFxrOrder($totalMix, $fx) as $code) {
    $p = $totalMix[$code];
    $curRows .= '<tr><td>' . htmlspecialchars($code) . ($p['legacy_n'] > 0 ? ' (' . $p['legacy_n'] . ' legacy)' : '') . '</td><td>' . (int)$p['n'] . '</td><td>'
        . htmlspecialchars(psFxrFmt($conn, $p['txn'], $code)) . '</td><td>'
        . ($p['missing_n'] > 0 ? 'no stored rate for ' . $p['missing_n'] : htmlspecialchars(psFxrFmt($conn, $p['base'], $fx['base']))) . '</td></tr>';
}

if (empty($sales_data)) {
    $sales_data = [['cashier' => 'No data available', 'mix' => []]];
}

// Create PDF
$mpdf = new \Mpdf\Mpdf();
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Z Report PDF</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
        }
        h1 {
            text-align: center;
            color: #007BFF;
        }
        h2, h3 {
            text-align: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 12px;
        }
        th {
            background-color: #007BFF;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <h1>Z Report</h1>
    <h3>' . htmlspecialchars($zBranch) . '</h3>
    <h2>Total Sales: ' . $T($totalMix) . '</h2>
    <p style="text-align:center">' . htmlspecialchars(psFxrLabel($fx)) . '</p>
    <h3>Report Date Range: ' . htmlspecialchars($start_date) . ' to ' . htmlspecialchars($end_date) . '</h3>

    <table>
        <thead>
            <tr>
                <th>Cashier</th>
                <th>Total Sales</th>
            </tr>
        </thead>
        <tbody>';

foreach ($sales_data as $data) {
    $html .= '
            <tr>
                <td>' . htmlspecialchars($data['cashier']) . '</td>
                <td>' . $T($data['mix']) . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>
    <h3>Totals by currency</h3>
    <table><thead><tr><th>Currency</th><th>Sales</th><th>Total in currency</th><th>In ' . htmlspecialchars($fx['base']) . ' (stored rates)</th></tr></thead>
    <tbody>' . $curRows . '<tr><th colspan="3">Consolidated</th><th>' . htmlspecialchars(psFxrFmt($conn, psFxrBase($totalMix), $fx['base'])) . (psFxrIncomplete($totalMix) ? ' (incomplete)' : '') . '</th></tr></tbody></table>
</body>
</html>';

$mpdf->WriteHTML(function_exists('psBranchLabelHtml') ? psBranchLabelHtml($html) : $html); // 2026-11-02 shared branch names
$pdfFilePath = 'Z_Report.pdf';
$mpdf->Output($pdfFilePath, \Mpdf\Output\Destination::INLINE);
exit;
?>