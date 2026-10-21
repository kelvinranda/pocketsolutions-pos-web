<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require 'vendor/autoload.php'; // Include MPDF
require 'pockets_config.php';
require_once __DIR__ . '/includes/product_master.php';

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$branchCheck = psRequireSessionProductBranch($conn);
$items_data = [];
if ($branchCheck['ok']) {
    $branch = (string)$branchCheck['branch'];
    $stmt = mysqli_prepare($conn, 'SELECT * FROM goods WHERE branch = ? ORDER BY product');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $branch);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($result && $row = mysqli_fetch_assoc($result)) {
                $items_data[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log('items pdf query failed');
    }
}

mysqli_close($conn);

// Create PDF
$mpdf = new \Mpdf\Mpdf();
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Items List PDF</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        h1 {
            text-align: center;
            color: white;
            background-color: #007BFF; /* Blue background */
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #007BFF; /* Blue header background */
            color: white; /* White text */
        }
        tr:nth-child(even) {
            background-color: #f2f2f2; /* Light gray for even rows */
        }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th>Product ID</th>
                <th>Product</th>
                <th>Price</th>
                <th>Cost Price</th>
                <th>Profit Margin</th>
                <th>Category</th>
                <th>Button Category</th>
                <th>Unit of Measure</th>
                <th>Pack Size</th>
                <th>Branch</th>
            </tr>
        </thead>
        <tbody>';

foreach ($items_data as $item) {
    $profit_margin = psProductProfitMargin($item['price'] ?? 0, $item['cost_price'] ?? 0);
    $html .= '<tr>
        <td>' . htmlspecialchars((string)$item['product_id'], ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)$item['product'], ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)$item['price'], ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)$item['cost_price'], ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)$profit_margin, ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)($item['category'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)($item['button_category'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)($item['unit_of_measure'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)($item['pack_size'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
        <td>' . htmlspecialchars((string)($item['branch'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>
    </tr>';
}

$html .= '
        </tbody>
    </table>
</body>
</html>';

$mpdf->WriteHTML($html);
$mpdf->Output('items_list.pdf', 'I');
