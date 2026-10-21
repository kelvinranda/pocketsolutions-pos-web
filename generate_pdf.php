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

$sales_data = [];
$total_sales = 0;
$stmt = mysqli_prepare(
    $conn,
    "SELECT cashier, SUM(totalz) AS total_sales
       FROM online_sales_ref
      WHERE date_ BETWEEN ? AND ? AND branch = ?
        AND COALESCE(status, '') <> 'CANCELLED'
      GROUP BY cashier"
);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'sss', $start_date, $end_date, $zBranch);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $sales_data[] = $row;
            $total_sales += (float)$row['total_sales'];
        }
    } else {
        error_log('generate_pdf: query failed');
    }
    mysqli_stmt_close($stmt);
} else {
    error_log('generate_pdf: prepare failed');
}

mysqli_close($conn);

// Check if there are no sales data to display
if (empty($sales_data)) {
    $sales_data = [['cashier' => 'No data available', 'total_sales' => 0]];
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
    <h2>Total Sales: $' . number_format($total_sales, 2) . '</h2>
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
                <td>$' . number_format((float)$data['total_sales'], 2) . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>
</body>
</html>';

$mpdf->WriteHTML($html);
$pdfFilePath = 'Z_Report.pdf';
$mpdf->Output($pdfFilePath, \Mpdf\Output\Destination::INLINE);
exit;
?>