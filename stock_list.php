<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';
psBranchRequireAuthRedirect();

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Handle search functionality
$search_product = "";
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['search_product'])) {
    $search_product = mysqli_real_escape_string($conn, $_POST['search_product']);
}

// Phase 9.6: authorised registered working branch for controlled qty display.
$stock_branch = '';
$br = psBranchResolveWorking($conn);
if (!empty($br['ok'])) {
    $stock_branch = (string)$br['branch'];
}

// Health-check 2026-10-13: the cache is per branch now — only this branch's rows.
$stock_data = [];
psEnsureStockListBranchColumn($conn);
$stock_stmt = $stock_branch === '' ? false : (empty($search_product)
    ? mysqli_prepare($conn, 'SELECT * FROM stock_list33 WHERE branch = ?')
    : mysqli_prepare($conn, 'SELECT * FROM stock_list33 WHERE branch = ? AND product = ?'));
if ($stock_stmt) {
    if (empty($search_product)) {
        mysqli_stmt_bind_param($stock_stmt, 's', $stock_branch);
    } else {
        $search_raw = trim((string)($_POST['search_product'] ?? ''));
        mysqli_stmt_bind_param($stock_stmt, 'ss', $stock_branch, $search_raw);
    }
    if (mysqli_stmt_execute($stock_stmt)) {
        $stock_result = mysqli_stmt_get_result($stock_stmt);
        while ($stock_result && ($row = mysqli_fetch_assoc($stock_result))) {
            $stock_data[] = $row;
        }
    } else {
        error_log('stock list was not loaded');
        echo 'The stock list could not be loaded.';
    }
    mysqli_stmt_close($stock_stmt);
} elseif ($stock_branch !== '') {
    error_log('stock list was not loaded');
    echo 'The stock list could not be loaded.';
}
$stock_data = psApplyControlledDisplayQty($conn, $stock_data, $stock_branch);

$name_counts = [];
foreach ($stock_data as $stock) {
    $name = (string)$stock['product'];
    $name_counts[$name] = ($name_counts[$name] ?? 0) + 1;
}

// Total of the values shown on this page. Controlled rows already carry
// ledger quantity × that row's goods.price. Unmigrated rows still carry the
// cached usd_value. A shared name that is still unmigrated keeps the same
// legacy name total on each cache row, so that cached figure is added once
// per row. Those rows are not folded together here.
$total_stock_value = 0;
foreach ($stock_data as $stock) {
    $total_stock_value += $stock['usd_value'];
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock List</title>
    <script>
        function searchSuggestions() {
            const input = document.getElementById("search_product");
            const suggestionsDiv = document.getElementById("suggestions");
            const query = input.value;

            if (query.length > 1) {
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "suggestions.php", true);
                xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        const suggestions = JSON.parse(xhr.responseText);
                        suggestionsDiv.innerHTML = "";

                        if (suggestions.length > 0) {
                            suggestions.forEach(function(product) {
                                const div = document.createElement("div");
                                div.textContent = product.label || product.product;
                                div.onclick = function() {
                                    input.value = product.product;
                                    suggestionsDiv.innerHTML = "";
                                };
                                suggestionsDiv.appendChild(div);
                            });
                        }
                    }
                };
                xhr.send("query=" + encodeURIComponent(query));
            } else {
                suggestionsDiv.innerHTML = ""; // Clear suggestions if input is too short
            }
        }
    </script>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <!-- Legacy in-page menu removed 2026-10-13: the shared sidebar (ps_open) is the only menu. -->

    <div class="content">
        <h1>Stock List</h1>
        <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>
        <button class="new-stock-button" onclick="window.location.href='stock_take_sheet.php'">New Stock Take</button>
        
        <form class="search-form" method="POST" action="">
            <div style="position: relative;">
                <input type="text" id="search_product" name="search_product" placeholder="Search for a product" value="<?php echo htmlspecialchars($search_product); ?>" onkeyup="searchSuggestions()">
                <div id="suggestions" class="suggestions"></div>
            </div>
            <button class="search-button" type="submit">Search</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity in Stock</th>
                    <th>Total Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stock_data)): ?>
                    <tr>
                        <td colspan="3">No stock data found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stock_data as $stock): ?>
                        <?php
                        $label = (string)$stock['product'];
                        $code = trim((string)($stock['product_id'] ?? ''));
                        if ($code !== '' && ($name_counts[$label] ?? 0) > 1) {
                            $label .= ' (' . $code . ')';
                        }
                        ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($label); ?>
                                <?php if (!empty($stock['inventory_status'])): ?>
                                    <div class="ps-inv-status"><?php echo htmlspecialchars(psInventoryStatusLabel((string)$stock['inventory_status'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($stock['qty']); ?></td>
                            <td><?php echo number_format($stock['usd_value'], 2); ?> USD</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="total">Total Stock Value: <?php echo number_format($total_stock_value, 2); ?> USD</div>
    </div>
<?php ps_close(); ?>
</body>
</html>