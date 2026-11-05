<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/report_export.php';

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

psBranchRequireAuthRedirect();

// Initialize variables
$search = "";
$results = [];
$goods_receipts = [];
$online_sales = [];
$movements_receipts = [];
$movements_receipts2 = [];
$ledger_rows = [];
$stock_controlled = false;
$product_id = "";
$ambiguous_products = [];
$start_date = date('Y-m-d'); // Default to today
$end_date = date('Y-m-d'); // Default to today
// Phase 9.6: authorised registered working branch (not raw selected_branch).
$historyGate = psRequireSessionProductBranch($conn);
$history_branch = !empty($historyGate['ok']) ? (string)$historyGate['branch'] : '';

// A blank hidden product_id is always posted, so only an actual id should win over the typed name.
$postedProductId = trim((string)($_POST['product_id'] ?? $_GET['product_id'] ?? ''));
if ($postedProductId !== '') {
    $product_id = $postedProductId;
}
if (isset($_POST['search']) || isset($_GET['search'])) {
    $search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
}

// Check date input
if (isset($_POST['start_date']) && isset($_POST['end_date'])) {
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date = mysqli_real_escape_string($conn, $_POST['end_date']);
} elseif (isset($_GET['start_date'], $_GET['end_date'])
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['end_date'])) {
    // 2026-10-17: export / print links carry the dates in the address.
    $start_date = (string)$_GET['start_date'];
    $end_date = (string)$_GET['end_date'];
}

// Fetch the product on this branch. An id is never replaced with a different product that shares the name.
$branchEsc = mysqli_real_escape_string($conn, $history_branch);
$goods_row = null;
if ($history_branch !== '' && $product_id !== '') {
    $idEsc = mysqli_real_escape_string($conn, $product_id);
    $goods_result = mysqli_query($conn, "SELECT * FROM goods WHERE product_id = '$idEsc' AND branch = '$branchEsc' LIMIT 1");
    if ($goods_result) {
        $goods_row = mysqli_fetch_assoc($goods_result) ?: null;
    }
} elseif ($history_branch !== '' && $search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $exact = mysqli_query($conn, "SELECT * FROM goods WHERE product_id = '$searchEsc' AND branch = '$branchEsc' LIMIT 1");
    if ($exact && ($exact_row = mysqli_fetch_assoc($exact))) {
        $goods_row = $exact_row;
        $product_id = trim((string)$exact_row['product_id']);
    } else {
        $searchLike = str_replace(['%', '_'], ['\\%', '\\_'], $searchEsc);
        $nameWhere = "(LOWER(product) LIKE LOWER('%$searchLike%') OR LOWER(product_id) LIKE LOWER('%$searchLike%'))";
        $match_result = mysqli_query(
            $conn,
            "SELECT * FROM goods WHERE branch = '$branchEsc' AND $nameWhere ORDER BY product, product_id, id"
        );
        $matched = [];
        if ($match_result) {
            while ($match_row = mysqli_fetch_assoc($match_result)) {
                $match_id = trim((string)$match_row['product_id']);
                if ($match_id === '' || isset($matched[$match_id])) {
                    continue;
                }
                $matched[$match_id] = $match_row;
            }
        }
        if (count($matched) === 1) {
            $goods_row = reset($matched);
            $product_id = trim((string)$goods_row['product_id']);
        } elseif (count($matched) > 1) {
            $ambiguous_products = array_values($matched);
        }
    }
}

if ($goods_row) {
    $history_name_esc = mysqli_real_escape_string($conn, (string)$goods_row['product']);
    $history_product_id = trim((string)$goods_row['product_id']);
    $idEsc = mysqli_real_escape_string($conn, $history_product_id);
    $share_result = mysqli_query(
        $conn,
        "SELECT COUNT(DISTINCT product_id) AS shared_names FROM goods WHERE branch = '$branchEsc' AND product = '$history_name_esc'"
    );
    $share_row = $share_result ? mysqli_fetch_assoc($share_result) : null;
    $name_shared = $share_row && (int)$share_row['shared_names'] > 1;
    // An id keys the documents. A unique name still includes older rows that have no product id.
    // A shared name is not given those rows, and it is not given another product's id.
    if ($history_product_id !== '' && $name_shared) {
        $doc_match = "product_id = '$idEsc'";
    } elseif ($history_product_id !== '') {
        $doc_match = "(product_id = '$idEsc' OR ((product_id = '' OR product_id IS NULL) AND product = '$history_name_esc'))";
    } else {
        $doc_match = "product = '$history_name_esc'";
    }

    // Fetch quantities from goods_receipts WITHOUT date filter
    $receipts_sql = "SELECT SUM(quantity) AS total_received FROM goods_receipts 
                     WHERE $doc_match";
    $receipts_result = mysqli_query($conn, $receipts_sql);
    $receipts_row = $receipts_result ? mysqli_fetch_assoc($receipts_result) : null;
    $total_received = $receipts_row['total_received'] ?? 0;

    // Fetch quantities from online_sales WITHOUT date filter
    $sales_sql = "SELECT SUM(quantity) AS total_sold FROM online_sales 
                  WHERE status <> 'refunded' AND $doc_match";
    $sales_result = mysqli_query($conn, $sales_sql);
    $sales_row = $sales_result ? mysqli_fetch_assoc($sales_result) : null;
    $total_sold = $sales_row['total_sold'] ?? 0;
    
    // Fetch quantities from Stock movements Received IN WITHOUT date filter
    $movement_sql = "SELECT SUM(quantity) AS total_moved FROM `movements_receipts` 
                  WHERE  $doc_match AND receiving_branch='$branchEsc'   ";
    $movement_result = mysqli_query($conn, $movement_sql);
    $movement_row = $movement_result ? mysqli_fetch_assoc($movement_result) : null;
    $total_movement = $movement_row['total_moved'] ?? 0;
    
    // Fetch quantities from Stock movements Received OUT WITHOUT date filter
    $movement_out_sql = "SELECT SUM(quantity) AS total_moved_out FROM `movements_receipts` 
                  WHERE  $doc_match AND sending_branch='$branchEsc'   ";
    $movement_out_result = mysqli_query($conn, $movement_out_sql);
    $movement_row_out = $movement_out_result ? mysqli_fetch_assoc($movement_out_result) : null;
    $total_movement_out = $movement_row_out['total_moved_out'] ?? 0;

    // Current stock uses product_id document totals (or the ledger when controlled).
    // Shared display names such as 1034 and 1063 are never combined.
    $current_stock = ($total_received + $total_movement) - ($total_sold + $total_movement_out);

    if ($history_branch !== '' && $history_product_id !== '' && isStockControlled($conn, $history_product_id, $history_branch)) {
        $stock_controlled = true;
        $current_stock = psStockQtyPlain(getStockBalance($conn, $history_product_id, $history_branch));
        $listed = listStockMovements($conn, [
            'branch' => $history_branch,
            'allowed_branches' => [$history_branch],
            'product' => $history_product_id,
            'date_from' => $start_date,
            'date_to' => $end_date,
        ]);
        if (!empty($listed['ok'])) {
            foreach ($listed['rows'] as $ledger_row) {
                if ((string)$ledger_row['product_id'] === $history_product_id && (string)$ledger_row['branch'] === $history_branch) {
                    $ledger_rows[] = $ledger_row;
                }
            }
        }
    }

    // Calculate stock value based on price and cost price
    $stock_value_price = $current_stock * floatval($goods_row['price']);
    $stock_value_cost = $current_stock * floatval($goods_row['cost_price']);

    // Store results
    $results[] = [
        'product_id' => $goods_row['product_id'],
        'product' => $goods_row['product'],
        'branch' => $goods_row['branch'] ?? $history_branch,
        'price' => number_format(floatval($goods_row['price']), 2),
        'cost_price' => number_format(floatval($goods_row['cost_price']), 2),
        'total_received' => $total_received,
        'total_sold' => $total_sold,
        'current_stock' => $current_stock,
        'stock_value_price' => number_format($stock_value_price, 2),
        'stock_value_cost' => number_format($stock_value_cost, 2)
    ];

    // Filter goods_receipts for the product by selected dates
    $goods_receipts_sql = "SELECT * FROM goods_receipts 
                           WHERE $doc_match 
                           AND date_ BETWEEN '$start_date' AND '$end_date'";
    $goods_receipts_result = mysqli_query($conn, $goods_receipts_sql);
    if ($goods_receipts_result) {
        while ($receipt_row = mysqli_fetch_assoc($goods_receipts_result)) {
            $goods_receipts[] = $receipt_row;
        }
    }
    
    // Filter movements_receipts for the product by selected dates
    $movements_receipts_sql = "SELECT * FROM movements_receipts 
                           WHERE $doc_match 
                           AND date_ BETWEEN '$start_date' AND '$end_date'   AND receiving_branch='$branchEsc'";
    $movements_receipts_result = mysqli_query($conn, $movements_receipts_sql);
    if ($movements_receipts_result) {
        while ($movements_row = mysqli_fetch_assoc($movements_receipts_result)) {
            $movements_receipts[] = $movements_row;
        }
    }

 // Filter movements_receipts for the product by selected dates OUT OUT
    $movements_receipts_sql2 = "SELECT * FROM movements_receipts 
                           WHERE $doc_match 
                           AND date_ BETWEEN '$start_date' AND '$end_date' AND sending_branch='$branchEsc'    ";
    $movements_receipts_result2 = mysqli_query($conn, $movements_receipts_sql2);
    if ($movements_receipts_result2) {
        while ($movements_row2 = mysqli_fetch_assoc($movements_receipts_result2)) {
            $movements_receipts2[] = $movements_row2;
        }
    }

    // Filter online_sales for the product by selected dates
    $online_sales_sql = "SELECT * FROM online_sales 
                         WHERE $doc_match 
                         AND date_ BETWEEN '$start_date' AND '$end_date' ";
    $online_sales_result = mysqli_query($conn, $online_sales_sql);
    if ($online_sales_result) {
        while ($sale_row = mysqli_fetch_assoc($online_sales_result)) {
            $online_sales[] = $sale_row;
        }
    }
}

// 2026-10-17: Print / PDF / Excel of the same history.
$exportQuery = ['product_id' => (string)($results[0]['product_id'] ?? $product_id), 'search' => $search, 'start_date' => $start_date, 'end_date' => $end_date];
$exportFormat = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportFormat, psReportExportFormats(), true) && $results) {
    $item = $results[0];
    $d = fn($v) => date('Y-m-d', strtotime((string)$v));
    $sections = [
        ['heading' => 'Summary', 'columns' => (function () use ($conn) {
            require_once __DIR__ . '/includes/currency.php';
            $b = psFxBase($conn, psFxSessionCompany()); // product prices and costs are kept in the base currency
            return ['Product ID', 'Product', 'Branch', "Price ($b)", "Cost price ($b)", 'Total received', 'Transferred in', 'Transferred out', 'Total sold', 'Current stock', "Stock value at price ($b)", "Stock value at cost ($b)"];
        })(),
         'rows' => array_map(fn($it) => [$it['product_id'], $it['product'], $it['branch'] ?? '', $it['price'], $it['cost_price'], $it['total_received'], $total_movement, $total_movement_out, $it['total_sold'], $it['current_stock'], $it['stock_value_price'], $it['stock_value_cost']], $results)],
        ['heading' => 'Goods receipts', 'columns' => ['Invoice', 'Product', 'Quantity', 'Date', 'Supplier', 'Status'], 'empty' => 'No receipts in this period.',
         'rows' => array_map(fn($r) => [$r['invoice_number'], $r['product'], $r['quantity'], $d($r['date_']), $r['supplier_name'], $r['status']], $goods_receipts)],
        ['heading' => 'Stock transfers in', 'columns' => ['Invoice', 'Product', 'Quantity', 'Date', 'Receiving branch', 'Status'], 'empty' => 'No transfers in this period.',
         'rows' => array_map(fn($r) => [$r['invoice_number'], $r['product'], $r['quantity'], $d($r['date_']), $r['receiving_branch'], $r['status']], $movements_receipts)],
        ['heading' => 'Stock transfers out', 'columns' => ['Invoice', 'Product', 'Quantity', 'Date', 'Sending branch', 'Status'], 'empty' => 'No transfers in this period.',
         'rows' => array_map(fn($r) => [$r['invoice_number'], $r['product'], $r['quantity'], $d($r['date_']), $r['sending_branch'], $r['status']], $movements_receipts2)],
    ];
    if ($stock_controlled) {
        $sections[] = ['heading' => 'Ledger movements', 'columns' => ['Date', 'Type', 'Quantity', 'Reference', 'Notes'], 'empty' => 'No ledger movements in this period.',
            'note' => 'Current stock is the ledger total for this product at this branch.',
            'rows' => array_map(fn($r) => [$d($r['movement_at']), psMovementKindLabel((string)$r['movement_type'], (string)($r['reference_type'] ?? '')), psFormatSignedQty($r['quantity']),
                trim((string)$r['reference_type'] . ' ' . (string)$r['reference_id']), (string)($r['notes'] ?? '')], $ledger_rows)];
    }
    $sections[] = ['heading' => 'Sales', 'columns' => ['Invoice', 'Product', 'Quantity', 'Date', 'Cashier', 'Customer', 'Status'], 'empty' => 'No sales in this period.',
        'rows' => array_map(fn($r) => [$r['invoice_number'], $r['product'], $r['quantity'], $d($r['date_']), $r['cashier'], $r['customer'], $r['status']], $online_sales)];
    mysqli_close($conn);
    psReportExportSend($exportFormat, 'Product History', $item['product'] . ' (' . $item['product_id'] . ') · ' . ($item['branch'] ?? $history_branch) . ' · ' . $start_date . ' to ' . $end_date,
        $sections, 'product_history_' . $item['product_id'] . '_' . $start_date . '_' . $end_date);
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product History</title>
    <script>
        function showSuggestions(str) {
            var productId = document.getElementById("product_id");
            if (productId) productId.value = "";
            if (str.length === 0) {
                document.getElementById("suggestions").innerHTML = "";
                document.getElementById("suggestions").style.display = "none";
                return;
            }
            const xhr = new XMLHttpRequest();
            xhr.open("GET", "suggest.php?term=" + str, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const suggestions = JSON.parse(xhr.responseText);
                    const suggestionsContainer = document.getElementById("suggestions");
                    suggestionsContainer.innerHTML = "";
                    suggestions.forEach(function(suggestion) {
                        var div = document.createElement("div");
                        div.textContent = suggestion.label || suggestion.product;
                        div.onclick = function() {
                            selectSuggestion(suggestion.product_id, suggestion.product);
                        };
                        suggestionsContainer.appendChild(div);
                    });
                    suggestionsContainer.style.display = suggestions.length ? "block" : "none";
                }
            };
            xhr.send();
        }

        function selectSuggestion(productId, productName) {
            document.getElementById("search").value = productName;
            document.getElementById("product_id").value = productId;
            document.getElementById("suggestions").style.display = "none";
        }
    </script>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <!-- Legacy in-page menu removed 2026-10-13: the shared sidebar (ps_open) is the only menu. -->
    <div class="content">
        <h1>Product History</h1>
        <p><a href="product_audit_history.php">Product Audit History</a> (master field changes — separate from stock history below)</p>
        <form method="POST" action="">
            <div style="position: relative;">
                <input type="text" id="search" name="search" placeholder="Search by product name" onkeyup="showSuggestions(this.value)" value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" id="product_id" name="product_id" value="<?php echo htmlspecialchars($product_id, ENT_QUOTES, 'UTF-8'); ?>">
                <div id="suggestions" class="suggestions"></div>
            </div>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            <button type="submit">Search</button>
        </form>
        <style>@media print { form, .suggestions { display: none !important; } }</style>

        <?php if ($results): echo psReportExportToolbar($exportQuery); endif; ?>
        <?php if ($ambiguous_products): ?>
            <p>More than one product matches. Choose the product code. These are not combined into one stock figure.</p>
            <table>
                <thead>
                    <tr>
                        <th>Product ID</th>
                        <th>Product</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ambiguous_products as $choice): ?>
                        <tr>
                            <td>
                                <a href="?search=<?php echo urlencode((string)$choice['product']); ?>&amp;product_id=<?php echo urlencode((string)$choice['product_id']); ?>">
                                    <?php echo htmlspecialchars((string)$choice['product_id']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars((string)$choice['product']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Product ID</th>
                    <th>Product</th>
                    <th>Branch</th>
                    <th>Price</th>
                    <th>Cost Price</th>
                    <th>Total Received</th>
                     <th>Transfered IN</th>
                      <th>Transfered OUT</th>
                    <th>Total Sold</th>
                    <th>Current Stock</th>
                    <th>Stock Value (Price)</th>
                    <th>Stock Value (Cost Price)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="11">No results found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_id']); ?></td>
                            <td><?php echo htmlspecialchars($item['product']); ?></td>
                            <td><?php echo htmlspecialchars((string)($item['branch'] ?? '')); ?></td>
                            <td><?php echo $item['price']; ?> USD</td>
                            <td><?php echo $item['cost_price']; ?> USD</td>
                            <td><?php echo htmlspecialchars($item['total_received']); ?></td>
                            
                            
                            
                            
                            <td><?php echo htmlspecialchars($total_movement); ?></td>
                            
                            <td><?php echo htmlspecialchars($total_movement_out); ?></td>
                            
                            
                            
                            
                            <td><?php echo htmlspecialchars($item['total_sold']); ?></td>
                            <td><?php echo htmlspecialchars($item['current_stock']); ?></td>
                            <td><?php echo $item['stock_value_price']; ?> USD</td>
                            <td><?php echo $item['stock_value_cost']; ?> USD</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Table for goods_receipts -->
        <h2>Goods Receipts</h2>
        <table>
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Date</th>
                    <th>Supplier Name</th>
                    <th>Status</th> <!-- New Column for Status -->
                </tr>
            </thead>
            <tbody>
                <?php if (empty($goods_receipts)): ?>
                    <tr>
                        <td colspan="6">No receipts found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($goods_receipts as $receipt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($receipt['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($receipt['product']); ?></td>
                            <td><?php echo htmlspecialchars($receipt['quantity']); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($receipt['date_']))); ?></td>
                            <td><?php echo htmlspecialchars($receipt['supplier_name']); ?></td>
                            <td><?php echo htmlspecialchars($receipt['status']); ?></td> <!-- Display Status -->
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        
         <!-- Table for Movements -->
        <h2>Stock Transfers IN</h2>
        <table>
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Date</th>
                    <th>Receiving Branch</th>
                    <th>Status</th> <!-- New Column for Status -->
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements_receipts)): ?>
                    <tr>
                        <td colspan="6">No receipts found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements_receipts as $movements1): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($movements1['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($movements1['product']); ?></td>
                            <td><?php echo htmlspecialchars($movements1['quantity']); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($movements1['date_']))); ?></td>
                            <td><?php echo htmlspecialchars($movements1['receiving_branch']); ?></td>
                            <td><?php echo htmlspecialchars($movements1['status']); ?></td> <!-- Display Status -->
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>





  <!-- Table for Movements -->
        <h2>Stock Transfers OUT</h2>
        <table>
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Date</th>
                    <th>Sending Branch</th>
                    <th>Status</th> <!-- New Column for Status -->
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements_receipts2)): ?>
                    <tr>
                        <td colspan="6">No receipts found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements_receipts2 as $movements12): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($movements12['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($movements12['product']); ?></td>
                            <td><?php echo htmlspecialchars($movements12['quantity']); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($movements12['date_']))); ?></td>
                            <td><?php echo htmlspecialchars($movements12['sending_branch']); ?></td>
                            <td><?php echo htmlspecialchars($movements12['status']); ?></td> <!-- Display Status -->
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($stock_controlled): ?>
        <h2>Ledger movements</h2>
        <p>Current stock is the ledger total for this product at this branch. The receipt, sale, and transfer lists are history text and are not added to that total.</p>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Reference</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger_rows)): ?>
                    <tr>
                        <td colspan="5">No ledger movements found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ledger_rows as $ledger_row): ?>
                        <?php
                        $ledger_label = psMovementKindLabel(
                            (string)$ledger_row['movement_type'],
                            (string)($ledger_row['reference_type'] ?? '')
                        );
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime((string)$ledger_row['movement_at']))); ?></td>
                            <td><?php echo htmlspecialchars($ledger_label); ?></td>
                            <td><?php echo htmlspecialchars(psFormatSignedQty($ledger_row['quantity'])); ?></td>
                            <td><?php echo htmlspecialchars(trim((string)$ledger_row['reference_type'] . ' ' . (string)$ledger_row['reference_id'])); ?></td>
                            <td><?php echo htmlspecialchars((string)($ledger_row['notes'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Table for online_sales -->
        <h2>Online Sales</h2>
        <table>
            <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Date</th>
                    <th>Cashier</th>
                     <th>Customer</th>
                    <th>Status</th> <!-- New Column for Status -->
                </tr>
            </thead>
            <tbody>
                <?php if (empty($online_sales)): ?>
                    <tr>
                        <td colspan="6">No sales found for the selected date range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($online_sales as $sale): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($sale['product']); ?></td>
                            <td><?php echo htmlspecialchars($sale['quantity']); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($sale['date_']))); ?></td>
                            <td><?php echo htmlspecialchars($sale['cashier']); ?></td>
                             <td><?php echo htmlspecialchars($sale['customer']); ?></td>
                            <td><?php echo htmlspecialchars($sale['status']); ?></td> <!-- Display Status -->
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php ps_close(); ?>
</body>
</html>