<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php'; // Include your database connection config
require 'vendor/autoload.php'; // Include Composer's autoloader
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/product_packs.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Picqer\Barcode\BarcodeGeneratorPNG;

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

psBranchRequireAuthRedirect();
$stockTakeGate = psRequireSessionProductBranch($conn);
if (empty($stockTakeGate['ok'])) {
    die(htmlspecialchars((string)($stockTakeGate['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}

// Initialize variables
$items_data = [];
$search_term = '';
$stock_take_error = '';

// Health-check 2026-10-13: per-branch stock list cache, prepared statements, and no fatal
// error when legacy bond_value / variance_value columns are absent on an older database.
mysqli_report(MYSQLI_REPORT_OFF);
$stock_take_branch_q = (string)$stockTakeGate['branch'];
psEnsureStockListBranchColumn($conn);
// Build this branch's list on first use (same refresh the Stock List menu item runs).
$haveRows = mysqli_prepare($conn, 'SELECT 1 FROM stock_list33 WHERE branch = ? LIMIT 1');
if ($haveRows) {
    mysqli_stmt_bind_param($haveRows, 's', $stock_take_branch_q);
    mysqli_stmt_execute($haveRows);
    $hr = mysqli_stmt_get_result($haveRows);
    $exists = $hr && mysqli_fetch_row($hr);
    mysqli_stmt_close($haveRows);
    if (!$exists && !psSaveStockListCache($conn, $stock_take_branch_q)) {
        error_log('stock take: stock list could not be built');
    }
}
$hasVarianceCols = false;
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM stock_list33 LIKE 'variance_value'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $hasVarianceCols = true;
}
$selectCols = $hasVarianceCols
    ? 'id, product, product_id, qty, bond_value, variance_value'
    : "id, product, product_id, qty, '' AS bond_value, 0 AS variance_value";
if (isset($_POST['search'])) {
    $search_term = trim((string)($_POST['search_term'] ?? ''));
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search_term) . '%';
    $stmt = mysqli_prepare($conn, "SELECT $selectCols FROM stock_list33 WHERE branch = ? AND product LIKE ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $stock_take_branch_q, $like);
    }
} else {
    $stmt = mysqli_prepare($conn, "SELECT $selectCols FROM stock_list33 WHERE branch = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $stock_take_branch_q);
    }
}

$result = ($stmt && mysqli_stmt_execute($stmt)) ? mysqli_stmt_get_result($stmt) : false;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $items_data[] = $row;
    }
} else {
    error_log('stock take list was not loaded');
    $stock_take_error = 'The stock list could not be loaded.';
}
if ($stmt) {
    mysqli_stmt_close($stmt);
}

$stock_take_branch = (string)$stockTakeGate['branch'];
$items_data = psApplyControlledDisplayQty($conn, $items_data, $stock_take_branch);

$stock_take_name_counts = [];
foreach ($items_data as $item) {
    $stock_take_name = (string)$item['product'];
    $stock_take_name_counts[$stock_take_name] = ($stock_take_name_counts[$stock_take_name] ?? 0) + 1;
}

// INVOICE #
$inv = date('d-m-Y') . rand(1, 1000);

// Handle updates to the items
if (isset($_POST['update'])) {
    // 2026-10-28 packs: a count in packs (+ loose single units) becomes base units; whole units checked.
    $countBase = (string)($_POST['physical_stock'] ?? '');
    $countErr = null;
    $stCompany = trim((string)($_SESSION['the_company'] ?? ''));
    $stPid = trim((string)($_POST['product_id'] ?? ''));
    $stUnit = trim((string)($_POST['count_unit'] ?? ''));
    $stLoose = trim((string)($_POST['count_loose'] ?? ''));
    if ($stPid !== '' && ($stUnit !== '' && $stUnit !== 'base' || $stLoose !== '')) {
        $packPart = 0;
        if (trim($countBase) !== '' && (psStockMilli(trim($countBase)) ?? 0) > 0) {
            $cv = psPackConvertRows($conn, $stCompany, [$stPid], [$countBase], [$stUnit]);
            if (empty($cv['ok'])) {
                $countErr = $cv['error'];
            } else {
                $packPart = (int)psStockMilli((string)$cv['qtys'][0]);
            }
        } elseif (trim($countBase) !== '' && psStockMilli(trim($countBase)) === null) {
            $countErr = 'Enter the count as a number.';
        }
        $loosePart = 0;
        if ($countErr === null && $stLoose !== '') {
            $cl = psPackConvertRows($conn, $stCompany, [$stPid], [$stLoose], ['base']);
            if (empty($cl['ok'])) {
                $countErr = $cl['error'];
            } else {
                $loosePart = (int)psStockMilli((string)$cl['qtys'][0]);
            }
        }
        $countBase = psStockQtyPlain(psMilliToStock($packPart + $loosePart));
    } elseif ($stPid !== '' && trim($countBase) !== '' && (psStockMilli(trim($countBase)) ?? 0) > 0) {
        $cv = psPackConvertRows($conn, $stCompany, [$stPid], [$countBase], ['base']);
        if (empty($cv['ok'])) {
            $countErr = $cv['error'];
        }
    }
    $saved = $countErr !== null ? ['ok' => false, 'error' => $countErr] : psSaveStockTake($conn, [
        'token' => $_POST['stock_take_token'] ?? '',
        'item_id' => $_POST['item_id'] ?? '',
        'physical_stock' => $countBase,
        'product_id' => $_POST['product_id'] ?? '',
        'branch' => $stock_take_branch,
    ]);
    if (!empty($saved['ok'])) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?search_term=" . urlencode($search_term));
        exit();
    }
    $stock_take_error = (string)($saved['error'] ?? 'The stock take could not be saved.');
}

// PDF Generation logic
if (isset($_POST['print'])) {
    $generator = new BarcodeGeneratorPNG();
    $barcode = $generator->getBarcode($inv, $generator::TYPE_CODE_128);

    // Initialize totals
    $total_excess = 0;
    $total_shortage = 0;

    // HTML for the PDF
    $html = '
    <html>
        <head>
            <title>Stock Take Report</title>
        </head>
        <body>
            <h1>Stock Take Report</h1>
            <p>Date: ' . date('Y-m-d H:i:s') . '</p>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Variance</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($items_data as $item) {
        $html .= '
                    <tr>
                        <td>' . htmlspecialchars($item['id']) . '</td>
                        <td>' . htmlspecialchars($item['product']) . '</td>
                        <td>' . htmlspecialchars($item['qty']) . '</td>
                        <td>' . htmlspecialchars($item['bond_value']) . '</td>
                        <td>$' . htmlspecialchars($item['variance_value']) . '</td>
                    </tr>';
        
        // Calculate totals
        if ($item['bond_value'] > 0) {
            $total_excess += $item['variance_value'];
        } else {
            $total_shortage += abs($item['variance_value']); // Sum up the absolute value
        }
    }

    $html .= '
                </tbody>
            </table>
            <h3>Total Excess: $' . $total_excess . '</h3>
            <h3>Total Shortage: $' . $total_shortage . '</h3>
            <div class="barcode">
                <img src="data:image/png;base64,' . base64_encode($barcode) . '" alt="Barcode" />
            </div>
            <center>
            <p>Thank you for your stock take!</p>
            </center>
        </body>
    </html>';

    // Load HTML content into Dompdf
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("stock_take_report.pdf", array("Attachment" => false)); // Display the PDF in the browser
    exit(); // Ensure the script stops after generating the PDF
}

// 2026-10-28: connection kept for the unit pickers; closed at the end of the page.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Take</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
<h1>Stock Take</h1>
<p class="ps-page-lead">Branch: <?php echo htmlspecialchars($stock_take_branch_q, ENT_QUOTES, 'UTF-8'); ?></p>
<?php if ($stock_take_error !== ''): ?>
    <p class="stock-take-error"><?php echo htmlspecialchars($stock_take_error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
<?php
$stock_take_token = psStockTakeTokenPeek();
if ($stock_take_token === '') {
    $stock_take_token = psStockTakeTokenIssue();
}
?>

<div class="button-container">
    <form method="POST" style="display:inline;">
        <input type="text" name="search_term" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Search by product name">
        <button type="submit" name="search">Search</button>
    </form>
    <form method="POST" style="display:inline;">
        <button type="submit" name="print">Print</button>
    </form>
    <form method="POST" style="display:inline;">
        <button type="button" onclick="window.location.href='admin_dashboard.php';">Back to Dashboard</button>
    </form>
</div>

<table>
    <tr>
        <th>ID</th>
        <th>Product</th>
        <th>Theoretical Qty</th>
        <th>New Qty</th>
        <th>(Variance)</th>
        <th>Actions</th>
    </tr>

    <?php if (empty($items_data)): ?>
        <tr>
            <td colspan="6">No items found.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($items_data as $item): ?>
            <tr>
                <form method="POST">
                    <td><?php echo htmlspecialchars($item['id']); ?></td>
                    <td><?php
                        $stock_take_label = (string)$item['product'];
                        $stock_take_code = trim((string)($item['product_id'] ?? ''));
                        if ($stock_take_code !== '' && ($stock_take_name_counts[$stock_take_label] ?? 0) > 1) {
                            $stock_take_label .= ' (' . $stock_take_code . ')';
                        }
                        echo htmlspecialchars($stock_take_label);
                    ?></td>
                    <td><span class="theoretical-qty"><?php echo htmlspecialchars($item['qty']); ?></span></td> <!-- Theoretical qty -->
                    <td>
                        <input type="number" name="physical_stock" placeholder="Enter qty" required min="0" step="0.001"
                               oninput="calculateVariance(<?php echo json_encode((string)$item['id']); ?>, this, <?php echo json_encode((float)$item['qty']); ?>)">
                        <?php $stSel = psPackUnitSelect($conn, trim((string)($_SESSION['the_company'] ?? '')), (string)($item['product_id'] ?? ''), 'count_unit');
                        if ($stSel !== ''): ?>
                            <?php echo $stSel; ?> + <input type="number" name="count_loose" placeholder="loose" min="0" step="1" style="width:6em" title="Loose single units counted outside full packs">
                        <?php endif; ?>
                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars((string)($item['product_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="stock_take_token" value="<?php echo htmlspecialchars($stock_take_token, ENT_QUOTES, 'UTF-8'); ?>">
                    </td>
                    <td>
                        <span class="variance" id="bond-value-<?php echo $item['id']; ?>">
                            <?php echo htmlspecialchars($item['bond_value']); ?>
                        </span>
                    </td>
                    <td>
                        <button type="submit" name="update">Save</button>
                    </td>
                </form>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</table>

<script>
    function calculateVariance(itemId, input, theoreticalQty) {
        const varianceElem = document.getElementById('bond-value-' + itemId);
        const newQty = parseFloat(input.value);
        const counted = isNaN(newQty) ? 0 : newQty;
        if (varianceElem) {
            varianceElem.innerText = counted - theoreticalQty;
        }
    }
</script>

<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>