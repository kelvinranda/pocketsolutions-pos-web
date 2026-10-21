<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_master.php';

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

// Phase 9.6: authorised registered working branch only (not raw selected_branch).
psBranchRequireAuthRedirect();
$branchGate = psRequireSessionProductBranch($conn);
if (empty($branchGate['ok'])) {
    die(htmlspecialchars((string)($branchGate['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$loggedBranch = (string)$branchGate['branch'];

$branches = [];
$error = null;

/* Receiving dropdown: registered branchies only (sending locked to working). */
foreach (psBranchRegisteredNames($conn) as $name) {
    $branches[] = ['id' => '', 'branch_name' => $name];
}

/* Set default date to today for HTML (also validated server-side) */
$today = date('Y-m-d');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {


    // AJAX suggestions handled by grv.php, so ignore here

    if (isset($_POST['confirm'])) {
        // ===== Server validation =====
        $date = isset($_POST['date']) ? trim($_POST['date']) : '';
        $invoice_number = isset($_POST['invoice_number']) ? trim($_POST['invoice_number']) : '';
        $supplier_name = isset($_POST['supplier_name']) ? trim($_POST['supplier_name']) : '';
        $status = isset($_POST['status']) ? trim($_POST['status']) : '';

        $sending_branch = isset($_POST['sending_branch']) ? trim($_POST['sending_branch']) : '';
        $receiving_branch = isset($_POST['receiving_branch']) ? trim($_POST['receiving_branch']) : '';

        if ($sending_branch === '' || $receiving_branch === '') {
            die("Sending and receiving branches are required.");
        }

        // prevent same branch
        if ($sending_branch === $receiving_branch) {
            die("Sending branch and receiving branch must be different.");
        }
        

        // ensure sending branch matches session
        if ($sending_branch !== $loggedBranch) {
            die("Invalid sending branch selection.");
        }

        // validate date (optional: must be today or allow any date?)
        // Your request: "date must pick today by default"
        // We'll allow any valid date but default it to today. If you want only today, say so.
        if ($date === '') {
            die("Date is required.");
        }

        $postedProducts = $_POST['product'] ?? [];
        if (!is_array($postedProducts) || count($postedProducts) === 0) {
            die("No products submitted.");
        }

        $saved = psSaveStockTransfer($conn, [
            'date' => $date,
            'invoice_number' => $invoice_number,
            'supplier_name' => $supplier_name,
            'status' => $status,
            'sending_branch' => $sending_branch,
            'receiving_branch' => $receiving_branch,
            'products' => $postedProducts,
            'product_ids' => $_POST['product_id'] ?? [],
            'quantities' => $_POST['quantity'] ?? [],
            'vats' => $_POST['vat'] ?? [],
            'line_costs' => $_POST['line_cost'] ?? [],
        ]);
        if (!$saved['ok']) {
            echo "<div class='success'>" . htmlspecialchars((string)$saved['error'], ENT_QUOTES, 'UTF-8') . "</div>";
            exit;
        }

        echo "<div class='success'>Stock Transfer created successfully!</div>";
        exit;
    }

    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Movement</title>
    <script>
        let productCount = 1; // For tracking the number of products

        function showSuggestions(input, index) {
            const value = input.value;
            if (value.length === 0) {
                document.getElementById("suggestions_" + index).innerHTML = "";
                document.getElementById("suggestions_" + index).style.display = "none";
                return;
            }
            const xhr = new XMLHttpRequest();
            xhr.open("GET", "grv.php?term=" + encodeURIComponent(value), true); // Use 'grv.php' for fetching suggestions
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    const suggestions = JSON.parse(xhr.responseText);
                    let suggestionsHtml = "";
                    suggestions.forEach(function(suggestion) {
                        const label = suggestion.label || (suggestion.product + ' - $' + suggestion.cost_price);
                        suggestionsHtml += `<div onclick="selectSuggestion('${suggestion.product_id}', '${suggestion.product}', '${suggestion.cost_price}', ${index})">${label}</div>`;
                    });
                    const suggestionsContainer = document.getElementById("suggestions_" + index);
                    suggestionsContainer.innerHTML = suggestionsHtml;
                    suggestionsContainer.style.display = suggestions.length ? "block" : "none";
                }
            };
            xhr.send();
        }

        function selectSuggestion(product_id, product, cost_price, index) {
            document.getElementById("product_id_" + index).value = product_id;
            document.getElementById("product_" + index).value = product;
            document.getElementById("line_cost_" + index).value = cost_price; // Set line cost based on selected product cost price
            document.getElementById("suggestions_" + index).style.display = "none";
        }

        function addProductRow() {
            const productRow = `
                <tr>
                    <td>
                        <input type="text" id="product_${productCount}" name="product[]" placeholder="Product Name" onkeyup="showSuggestions(this, ${productCount})" required>
                        <input type="hidden" id="product_id_${productCount}" name="product_id[]" required>
                        <div id="suggestions_${productCount}" class="suggestions"></div>
                    </td>
                    <td><input type="number" name="quantity[]" placeholder="Quantity" required></td>
                    <td><input type="number" step="0.01" id="line_cost_${productCount}" name="line_cost[]" placeholder="Line Cost" required></td>
                    <td><input type="number" step="0.01" name="vat[]" placeholder="VAT" value="0" required></td>
                    <td><button type="button" class="delete-row" onclick="deleteProductRow(this)">Delete</button></td>
                </tr>
            `;
            document.getElementById("productTable").insertAdjacentHTML('beforeend', productRow);
            productCount++;
        }

        function deleteProductRow(button) {
            const row = button.parentElement.parentElement; // Get the row to delete
            row.parentElement.removeChild(row); // Remove the row from the table
        }

        function psAttr(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;');
        }

        function previewGRN() {
           const sendingBranch = document.querySelector('input[name="sending_branch"]').value;
const receivingBranch = document.querySelector('select[name="receiving_branch"]').value; 
            
            
            
           

if (!receivingBranch) {
    alert("Please select a receiving branch.");
    return;
}

if (sendingBranch === receivingBranch) {
    alert("Sending branch and receiving branch must be different.");
    return;
}
            
            
            
            
            
            
            
            
            const products = [];
            const productIds = [];
            const quantities = [];
            const lineCosts = [];
            const vats = [];
            let totalCosts = [];  // Array to hold the total costs for each product
            let grandTotal = 0; // Variable to hold the overall total

            const rows = document.querySelectorAll('#productTable tbody tr');
            rows.forEach(row => {
                const productName = row.querySelector('input[name="product[]"]').value;
                const productIdInput = row.querySelector('input[name="product_id[]"]');
                const quantity = parseFloat(row.querySelector('input[name="quantity[]"]').value);
                const lineCost = parseFloat(row.querySelector('input[name="line_cost[]"]').value);
                const vat = parseFloat(row.querySelector('input[name="vat[]"]').value);
                
                products.push(productName);
                productIds.push(productIdInput ? productIdInput.value : '');
                quantities.push(quantity);
                lineCosts.push(lineCost);
                vats.push(vat);
                
                // Calculate total cost for this product and store it
                const totalCost = quantity * lineCost;
                totalCosts.push(totalCost);

                // Accumulate grand total
                grandTotal += totalCost + vat; // Total cost including VAT
            });

            const date = document.querySelector('input[name="date"]').value;
            const invoiceNumber = document.querySelector('input[name="invoice_number"]').value;
            const supplierName = document.querySelector('input[name="supplier_name"]').value;
            const status = document.querySelector('input[name="status"]').value;

            let previewHtml = `<h2>Preview Goods Received Note</h2><table><tr><th>Product</th><th>Quantity</th><th>Line Cost</th><th>VAT</th><th>Total Cost</th></tr>`;
            
            for (let i = 0; i < products.length; i++) {
                previewHtml += `<tr>
                                    <td>${psAttr(products[i])}</td>
                                    <td>${psAttr(quantities[i])}</td>
                                    <td>${psAttr(lineCosts[i].toFixed(2))}</td>
                                    <td>${psAttr(vats[i].toFixed(2))}</td>
                                    <td>${psAttr(totalCosts[i].toFixed(2))}</td>
                                </tr>`;
            }

            // Add grand total row
            previewHtml += `<tr>
                                <td colspan="4" style="text-align:right;"><strong>Grand Total</strong></td>
                                <td><strong>${grandTotal.toFixed(2)}</strong></td>
                            </tr>`;

            previewHtml += `</table>
            <p>Date: ${psAttr(date)}</p>
            <p>Invoice Number: ${psAttr(invoiceNumber)}</p>
            <p>Supplier Name: ${psAttr(supplierName)}</p>
            <p>Status: ${psAttr(status)}</p>`;

            let lineFields = '';
            for (let i = 0; i < products.length; i++) {
                lineFields += `<input type="hidden" name="product[]" value="${psAttr(products[i])}">`;
                lineFields += `<input type="hidden" name="product_id[]" value="${psAttr(productIds[i])}">`;
                lineFields += `<input type="hidden" name="quantity[]" value="${psAttr(quantities[i])}">`;
                lineFields += `<input type="hidden" name="line_cost[]" value="${psAttr(lineCosts[i])}">`;
                lineFields += `<input type="hidden" name="vat[]" value="${psAttr(vats[i])}">`;
            }

           previewHtml += `<form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
    <input type="hidden" name="date" value="${psAttr(date)}">
    <input type="hidden" name="invoice_number" value="${psAttr(invoiceNumber)}">
    <input type="hidden" name="supplier_name" value="${psAttr(supplierName)}">
    <input type="hidden" name="status" value="${psAttr(status)}">

    <input type="hidden" name="sending_branch" value="${psAttr(sendingBranch)}">
    <input type="hidden" name="receiving_branch" value="${psAttr(receivingBranch)}">

    ${lineFields}

    <button type="submit" name="confirm">Confirm and Submit</button>
</form>`;

            document.getElementById('previewContainer').innerHTML = previewHtml;
        }
    </script>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <!-- Legacy in-page menu removed 2026-10-13: the shared sidebar (ps_open) is the only menu. -->
    <div class="content">
        <h1>Stock Transfers/Movement</h1>
        
        <button class="back-button" onclick="window.location.href='admin_dashboard.php'">Back to Dashboard</button>

        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <table id="productTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Line Cost</th>
                        <th>VAT</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <input type="text" id="product_0" name="product[]" placeholder="Product Name" onkeyup="showSuggestions(this, 0)" required>
                            <input type="hidden" id="product_id_0" name="product_id[]" required>
                            <div id="suggestions_0" class="suggestions"></div>
                        </td>
                        <td><input type="number" name="quantity[]" placeholder="Quantity" required></td>
                        <td><input type="number" step="0.01" id="line_cost_0" name="line_cost[]" placeholder="Line Cost" required></td>
                        <td><input type="number" step="0.01" name="vat[]" placeholder="VAT" value="0" required></td>
                        <td><button type="button" class="delete-row" onclick="deleteProductRow(this)">Delete</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" onclick="addProductRow()">Add Another Product</button>
            <br><br>
           <input type="date" name="date" value="<?php echo htmlspecialchars($today); ?>" required>
            <input type="text" name="invoice_number" placeholder="Invoice Number" required>
            <input type="text" name="supplier_name" placeholder="Supplier Name" required>
            <input type="text" name="status" placeholder="Status" value="Received" required>
       
       
       
<!-- Sending branch (logged in branch, cannot change) -->
<input type="hidden" name="sending_branch" value="<?php echo htmlspecialchars($loggedBranch); ?>" required>

<!-- Sending branch (logged in branch, cannot change) -->
<input type="hidden" name="sending_branch" value="<?php echo htmlspecialchars($loggedBranch); ?>" required>

<!-- Receiving branch (user must select) -->
<select name="receiving_branch" required>
    <option value="">Select receiving branch</option>
    <?php foreach ($branches as $b): ?>
        <?php if ($b['branch_name'] === $loggedBranch) continue; ?>
        <option value="<?php echo htmlspecialchars($b['branch_name']); ?>">
            <?php echo htmlspecialchars($b['branch_name']); ?>
        </option>
    <?php endforeach; ?>
</select>


            <button type="button" onclick="previewGRN()">Preview GRN</button>
        </form>

        <div id="previewContainer"></div>
    </div>
<?php ps_close(); ?>
</body>
</html>