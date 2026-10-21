<?php
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/goods_receipt.php';

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

psEnsureGoodsReceiptAllSchema($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$notice = '';
$branch = '';
$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'non_po')));
if (!in_array($mode, ['non_po', 'po'], true)) {
    $mode = 'non_po';
}
$selectedPoId = (int)($_GET['po_id'] ?? $_POST['purchase_order_id'] ?? 0);
$poOptions = [];
$poHeader = null;
$poLines = [];
$poSupplierLabel = '';
$submitToken = '';

if (!$signedIn) {
    $error = 'You must be signed in to receive goods.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $poList = psApprovedPurchaseOrdersForReceiving($conn);
        if ($poList['ok']) {
            $poOptions = $poList['rows'] ?? [];
        }
        if ($mode === 'po' && $selectedPoId > 0) {
            $load = psPurchaseOrderLoadForSession($conn, $selectedPoId);
            if (!$load['ok']) {
                $error = (string)($load['error'] ?? 'Purchase order not found.');
                $selectedPoId = 0;
            } elseif (!psPoCanReceive((string)$load['header']['status'])) {
                $error = 'Only approved purchase orders with remaining quantity can be received.';
                $selectedPoId = 0;
            } else {
                $poHeader = $load['header'];
                $poLines = psPoLinesWithReceived($conn, $load['lines']);
                if (psPoLinesFullyReceived($poLines)) {
                    $error = 'That purchase order is already fully received.';
                    $selectedPoId = 0;
                    $poHeader = null;
                    $poLines = [];
                } else {
                    $sup = psSupplierById($conn, (int)$poHeader['supplier_id']);
                    if ($sup) {
                        $poSupplierLabel = trim((string)$sup['supplier_code'] . ' — ' . (string)$sup['supplier_name']);
                    }
                }
            }
        }
    }
}

// Product suggestion AJAX (non-PO)
if (isset($_GET['term'])) {
    require_once __DIR__ . '/includes/product_master.php';
    require_once __DIR__ . '/includes/barcode.php';
    $term = trim((string)$_GET['term']);
    // Phase 9.6: same authorised working branch as page gate (not raw session).
    $branchAjax = '';
    if ($signedIn) {
        $ajaxGate = psRequireSessionProductBranch($conn);
        if (!empty($ajaxGate['ok'])) {
            $branchAjax = (string)$ajaxGate['branch'];
        }
    }
    $like = '%' . $term . '%';
    $products = [];
    if ($signedIn && $branchAjax !== '' && $term !== '') {
        psEnsureGoodsBarcodeSchema($conn);
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, cost_price, pack_size, price, barcode
             FROM goods
             WHERE branch = ? AND (product LIKE ? OR product_id LIKE ? OR barcode LIKE ?)
             ORDER BY product, product_id
             LIMIT 20'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssss', $branchAjax, $like, $like, $like);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $rows = [];
            while ($result && $row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
            mysqli_stmt_close($stmt);
            $nameCounts = [];
            foreach ($rows as $row) {
                $name = (string)$row['product'];
                $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
            }
            foreach ($rows as $row) {
                $name = (string)$row['product'];
                $code = trim((string)$row['product_id']);
                $label = $name . ' - $' . $row['cost_price'];
                if ($code !== '' && ($nameCounts[$name] ?? 0) > 1) {
                    $label = psProductDisplayLabel($name, $code, (string)($row['pack_size'] ?? ''), $row['price'] ?? null)
                        . ' - $' . $row['cost_price'];
                }
                $products[] = [
                    'product_id' => $code,
                    'product' => $name,
                    'cost_price' => $row['cost_price'],
                    'label' => $label,
                ];
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($products);
    mysqli_close($conn);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $signedIn && $branch !== '') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!psGrvConsumeSubmitToken($_POST['grv_submit_token'] ?? '')) {
        $error = 'This receipt was already submitted or the form expired. Reload and try again.';
    } else {
        $postMode = strtolower(trim((string)($_POST['mode'] ?? 'non_po')));
        if ($postMode === 'po') {
            $saved = psSaveGoodsReceipt($conn, [
                'date' => $_POST['date'] ?? '',
                'invoice_number' => $_POST['invoice_number'] ?? '',
                'status' => $_POST['status'] ?? 'Received',
                'branch' => $_POST['branch'] ?? null,
                'purchase_order_id' => (int)($_POST['purchase_order_id'] ?? 0),
                'po_line_ids' => $_POST['po_line_id'] ?? [],
                'receive_quantities' => $_POST['receive_qty'] ?? [],
            ]);
        } else {
            $saved = psSaveGoodsReceipt($conn, [
                'date' => $_POST['date'] ?? '',
                'invoice_number' => $_POST['invoice_number'] ?? '',
                'supplier_name' => $_POST['supplier_name'] ?? '',
                'status' => $_POST['status'] ?? 'Received',
                'branch' => $_POST['branch'] ?? null,
                'products' => $_POST['product'] ?? [],
                'product_ids' => $_POST['product_id'] ?? [],
                'quantities' => $_POST['quantity'] ?? [],
                'vats' => $_POST['vat'] ?? [],
                'line_costs' => $_POST['line_cost'] ?? [],
                'currency' => $_POST['currency'] ?? '',
            ]);
        }
        if (!$saved['ok']) {
            $error = (string)($saved['error'] ?? 'The goods receipt could not be saved.');
        } else {
            $grv = (string)($saved['grv_number'] ?? '');
            $notice = 'Goods received note ' . $grv . ' created successfully.';
            if (!empty($saved['po_closed'])) {
                $notice .= ' Purchase order closed (fully received).';
            }
            $mode = $postMode === 'po' ? 'po' : 'non_po';
            $selectedPoId = 0;
            $poHeader = null;
            $poLines = [];
            $poList = psApprovedPurchaseOrdersForReceiving($conn);
            if ($poList['ok']) {
                $poOptions = $poList['rows'] ?? [];
            }
        }
    }

    }
}

if ($signedIn && $branch !== '' && $error === '') {
    $submitToken = psGrvIssueSubmitToken();
} elseif ($signedIn && $branch !== '') {
    $submitToken = psGrvIssueSubmitToken();
}

// Multi-currency (2026-10-20): currency of a receipt without a PO (PO receipts use the order's currency).
require_once __DIR__ . '/includes/currency.php';
$grvCurrencies = ($signedIn && $branch !== '') ? psFxCompanyCurrencies($conn, psFxSessionCompany()) : [];

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Received Note</title>
    <script>
        let productCount = 1;

        function showSuggestions(input, index) {
            const value = input.value;
            const box = document.getElementById('suggestions_' + index);
            // 2026-10-18: typing a new name drops the earlier pick, so a line never posts a stale product id.
            const idField = document.getElementById('product_id_' + index);
            if (idField) idField.value = '';
            if (value.trim().length === 0) {
                box.innerHTML = '';
                box.style.display = 'none';
                return;
            }
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'grv.php?term=' + encodeURIComponent(value), true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4 || xhr.status !== 200) return;
                if (input.value !== value) return; // a newer search is on its way
                let suggestions = [];
                try { suggestions = JSON.parse(xhr.responseText) || []; } catch (e) { suggestions = []; }
                // 2026-10-18: build the list with DOM nodes. The old onclick="..." text broke on the
                // double quotes from JSON.stringify, so clicking a product did nothing.
                box.innerHTML = '';
                suggestions.forEach(function (suggestion) {
                    const item = document.createElement('div');
                    item.textContent = suggestion.label || (suggestion.product + ' - $' + suggestion.cost_price);
                    item.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
                    item.addEventListener('click', function () {
                        selectSuggestion(String(suggestion.product_id), String(suggestion.product), String(suggestion.cost_price ?? ''), index);
                    });
                    box.appendChild(item);
                });
                box.style.display = suggestions.length ? 'block' : 'none';
            };
            xhr.send();
        }

        function selectSuggestion(product_id, product, cost_price, index) {
            document.getElementById('product_id_' + index).value = product_id;
            document.getElementById('product_' + index).value = product;
            document.getElementById('line_cost_' + index).value = cost_price;
            document.getElementById('suggestions_' + index).style.display = 'none';
        }

        function addProductRow() {
            const productRow =
                '<tr><td>'
                + '<input type="text" id="product_' + productCount + '" name="product[]" placeholder="Product Name" oninput="showSuggestions(this, ' + productCount + ')" required>'
                + '<input type="hidden" id="product_id_' + productCount + '" name="product_id[]" required>'
                + '<div id="suggestions_' + productCount + '" class="suggestions" style="display:none"></div>'
                + '</td>'
                + '<td><input type="number" step="0.001" name="quantity[]" placeholder="Quantity" required></td>'
                + '<td><input type="number" step="0.01" id="line_cost_' + productCount + '" name="line_cost[]" placeholder="Line Cost" required></td>'
                + '<td><input type="number" step="0.01" name="vat[]" placeholder="VAT" value="0" required></td>'
                + '<td><button type="button" class="delete-row" onclick="deleteProductRow(this)">Delete</button></td></tr>';
            document.getElementById('productTable').insertAdjacentHTML('beforeend', productRow);
            productCount++;
        }

        function deleteProductRow(button) {
            const row = button.parentElement.parentElement;
            row.parentElement.removeChild(row);
        }

        function psAttr(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;');
        }

        function previewNonPo() {
            const products = [], productIds = [], quantities = [], lineCosts = [], vats = [];
            let grandTotal = 0;
            document.querySelectorAll('#productTable tbody tr').forEach(function (row) {
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
                grandTotal += (quantity * lineCost) + vat;
            });
            const date = document.querySelector('input[name="date"]').value;
            const invoiceNumber = document.querySelector('input[name="invoice_number"]').value;
            const supplierName = document.querySelector('input[name="supplier_name"]').value;
            const curSel = document.getElementById('grv_currency');
            const currency = curSel ? curSel.value : '';
            const status = document.querySelector('input[name="status"]').value;
            const token = document.querySelector('input[name="grv_submit_token"]').value;
            let html = '<h2>Preview Goods Received Note</h2><table class="grv-table"><tr><th>Product</th><th>Qty</th><th>Cost</th><th>VAT</th></tr>';
            for (let i = 0; i < products.length; i++) {
                html += '<tr><td>' + psAttr(products[i]) + '</td><td>' + psAttr(quantities[i]) + '</td><td>'
                    + psAttr(lineCosts[i].toFixed(2)) + '</td><td>' + psAttr(vats[i].toFixed(2)) + '</td></tr>';
            }
            html += '</table><p>Date: ' + psAttr(date) + '</p><p>Supplier invoice: ' + psAttr(invoiceNumber)
                + '</p><p>Supplier: ' + psAttr(supplierName) + '</p><p>Status: ' + psAttr(status)
                + '</p><p>Total (preview): ' + grandTotal.toFixed(2) + (currency ? ' ' + psAttr(currency) : '') + '</p>';
            let fields = '';
            for (let i = 0; i < products.length; i++) {
                fields += '<input type="hidden" name="product[]" value="' + psAttr(products[i]) + '">';
                fields += '<input type="hidden" name="product_id[]" value="' + psAttr(productIds[i]) + '">';
                fields += '<input type="hidden" name="quantity[]" value="' + psAttr(quantities[i]) + '">';
                fields += '<input type="hidden" name="line_cost[]" value="' + psAttr(lineCosts[i]) + '">';
                fields += '<input type="hidden" name="vat[]" value="' + psAttr(vats[i]) + '">';
            }
            html += '<form method="POST" action="">'
                + <?php echo json_encode(function_exists('psCsrfField') ? psCsrfField() : ''); ?>
                + '<input type="hidden" name="mode" value="non_po">'
                + '<input type="hidden" name="grv_submit_token" value="' + psAttr(token) + '">'
                + '<input type="hidden" name="date" value="' + psAttr(date) + '">'
                + '<input type="hidden" name="invoice_number" value="' + psAttr(invoiceNumber) + '">'
                + '<input type="hidden" name="supplier_name" value="' + psAttr(supplierName) + '">'
                + '<input type="hidden" name="currency" value="' + psAttr(currency) + '">'
                + '<input type="hidden" name="status" value="' + psAttr(status) + '">'
                + fields
                + '<button type="submit" name="confirm" value="1">Confirm and Submit</button></form>';
            document.getElementById('previewContainer').innerHTML = html;
        }

        function previewPo() {
            const lineIds = [], qtys = [];
            document.querySelectorAll('.po-receive-row').forEach(function (row) {
                const id = row.getAttribute('data-line-id');
                const qtyInput = row.querySelector('input[name="receive_qty[]"]');
                const qty = qtyInput ? qtyInput.value.trim() : '';
                if (qty !== '' && parseFloat(qty) > 0) {
                    lineIds.push(id);
                    qtys.push(qty);
                }
            });
            if (lineIds.length === 0) {
                alert('Enter at least one receive quantity.');
                return;
            }
            const date = document.querySelector('input[name="date"]').value;
            const invoiceNumber = document.querySelector('input[name="invoice_number"]').value;
            const status = document.querySelector('input[name="status"]').value;
            const token = document.querySelector('input[name="grv_submit_token"]').value;
            const poId = document.querySelector('input[name="purchase_order_id"]').value;
            let html = '<h2>Preview PO Receiving</h2><table class="grv-table"><tr><th>PO line</th><th>Qty to receive</th></tr>';
            for (let i = 0; i < lineIds.length; i++) {
                html += '<tr><td>' + psAttr(lineIds[i]) + '</td><td>' + psAttr(qtys[i]) + '</td></tr>';
            }
            html += '</table><p>Date: ' + psAttr(date) + '</p><p>Supplier invoice: ' + psAttr(invoiceNumber) + '</p>';
            let fields = '';
            for (let i = 0; i < lineIds.length; i++) {
                fields += '<input type="hidden" name="po_line_id[]" value="' + psAttr(lineIds[i]) + '">';
                fields += '<input type="hidden" name="receive_qty[]" value="' + psAttr(qtys[i]) + '">';
            }
            html += '<form method="POST" action="">'
                + <?php echo json_encode(function_exists('psCsrfField') ? psCsrfField() : ''); ?>
                + '<input type="hidden" name="mode" value="po">'
                + '<input type="hidden" name="grv_submit_token" value="' + psAttr(token) + '">'
                + '<input type="hidden" name="purchase_order_id" value="' + psAttr(poId) + '">'
                + '<input type="hidden" name="date" value="' + psAttr(date) + '">'
                + '<input type="hidden" name="invoice_number" value="' + psAttr(invoiceNumber) + '">'
                + '<input type="hidden" name="status" value="' + psAttr(status) + '">'
                + fields
                + '<button type="submit" name="confirm" value="1">Confirm and Submit</button></form>';
            document.getElementById('previewContainer').innerHTML = html;
        }
    </script>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <div class="grv-wrap">
        <h1>Goods Received Note</h1>
        <p class="hint">
            Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : '(none)', ENT_QUOTES, 'UTF-8'); ?>.
            Stock posts to the ledger only for quantities actually received. GRV numbers are allocated server-side.
        </p>
        <p class="mode-links">
            <a href="grv.php?mode=non_po">Non-PO receiving</a>
            <a href="grv.php?mode=po">PO-linked receiving</a>
            <a href="goods_receipt_ref.php">Receipt list</a>
            <a href="goods_receipt_audit_history.php">Receiving audit</a>
        </p>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($signedIn && $branch !== ''): ?>
            <?php if ($mode === 'po'): ?>
                <form method="GET" action="" class="grv-toolbar">
                    <input type="hidden" name="mode" value="po">
                    <div>
                        <label for="po_id">Approved purchase order</label>
                        <select id="po_id" name="po_id" onchange="this.form.submit()">
                            <option value="">Select PO…</option>
                            <?php foreach ($poOptions as $opt): ?>
                                <option value="<?php echo (int)$opt['id']; ?>"<?php echo $selectedPoId === (int)$opt['id'] ? ' selected' : ''; ?>>
                                    <?php
                                    echo htmlspecialchars(
                                        (string)$opt['po_number'] . ' — ' . (string)($opt['supplier_code'] ?? '') . ' ' . (string)($opt['supplier_name'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <?php if ($poHeader): ?>
                    <div class="meta-box">
                        <div><strong>PO:</strong> <?php echo htmlspecialchars((string)$poHeader['po_number'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Supplier:</strong> <?php echo htmlspecialchars($poSupplierLabel, ENT_QUOTES, 'UTF-8'); ?> (from PO)</div>
                        <div><strong>Status:</strong> <?php echo htmlspecialchars(psPoStatusLabel((string)$poHeader['status']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Branch:</strong> <?php echo htmlspecialchars((string)$poHeader['branch'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <form method="POST" action="" onsubmit="return false;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <input type="hidden" name="mode" value="po">
                        <input type="hidden" name="grv_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="purchase_order_id" value="<?php echo (int)$selectedPoId; ?>">
                        <input type="hidden" name="status" value="Received">
                        <div class="grv-toolbar">
                            <div>
                                <label for="date">Receive date</label>
                                <input type="date" id="date" name="date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div>
                                <label for="invoice_number">Supplier invoice #</label>
                                <input type="text" id="invoice_number" name="invoice_number" required>
                            </div>
                        </div>
                        <table class="grv-table">
                            <thead>
                                <tr>
                                    <th>Product ID</th>
                                    <th>Description</th>
                                    <th class="num">Ordered</th>
                                    <th class="num">Received</th>
                                    <th class="num">Remaining</th>
                                    <th class="num">PO cost</th>
                                    <th class="num">Qty to receive</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($poLines as $line): ?>
                                <?php
                                $remaining = (string)$line['remaining_qty'];
                                $hasRemaining = function_exists('bccomp')
                                    ? bccomp($remaining, '0', 3) > 0
                                    : ((float)$remaining > 0);
                                ?>
                                <tr class="po-receive-row" data-line-id="<?php echo (int)$line['id']; ?>">
                                    <td><?php echo htmlspecialchars((string)$line['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$line['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="num"><?php echo htmlspecialchars((string)$line['ordered_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="num"><?php echo htmlspecialchars((string)$line['received_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="num"><?php echo htmlspecialchars($remaining, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="num"><?php echo htmlspecialchars((string)$line['unit_cost'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="num">
                                        <?php if ($hasRemaining): ?>
                                            <input type="number" step="0.001" min="0" name="receive_qty[]" placeholder="0">
                                            <input type="hidden" name="po_line_id_display[]" value="<?php echo (int)$line['id']; ?>">
                                        <?php else: ?>
                                            <span>Fully received</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="hint">Leave unused lines blank. Over-receiving is rejected. Cost comes from the PO line.</p>
                        <button type="button" onclick="previewPo()">Preview &amp; Confirm</button>
                    </form>
                <?php elseif ($poOptions === []): ?>
                    <p>No approved purchase orders with remaining quantity on this branch.</p>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST" action="" onsubmit="return false;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                    <input type="hidden" name="mode" value="non_po">
                    <input type="hidden" name="grv_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="status" value="Received">
                    <table class="grv-table" id="productTable">
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
                                    <input type="text" id="product_0" name="product[]" placeholder="Product Name" oninput="showSuggestions(this, 0)" required>
                                    <input type="hidden" id="product_id_0" name="product_id[]" required>
                                    <div id="suggestions_0" class="suggestions" style="display:none"></div>
                                </td>
                                <td><input type="number" step="0.001" name="quantity[]" placeholder="Quantity" required></td>
                                <td><input type="number" step="0.01" id="line_cost_0" name="line_cost[]" placeholder="Line Cost" required></td>
                                <td><input type="number" step="0.01" name="vat[]" placeholder="VAT" value="0" required></td>
                                <td><button type="button" class="delete-row" onclick="deleteProductRow(this)">Delete</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" onclick="addProductRow()">Add Another Product</button>
                    <div class="grv-toolbar">
                        <div>
                            <label for="date_np">Date</label>
                            <input type="date" id="date_np" name="date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div>
                            <label for="invoice_np">Supplier invoice #</label>
                            <input type="text" id="invoice_np" name="invoice_number" required>
                        </div>
                        <div>
                            <label for="supplier_np">Supplier name</label>
                            <input type="text" id="supplier_np" name="supplier_name" required>
                        </div>
                        <?php if (count($grvCurrencies) > 1): ?>
                        <div>
                            <label for="grv_currency">Currency of the costs</label>
                            <select id="grv_currency" name="currency">
                                <?php foreach ($grvCurrencies as $code => $c): ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" onclick="previewNonPo()">Preview GRN</button>
                </form>
            <?php endif; ?>
            <div id="previewContainer"></div>
        <?php endif; ?>
    </div>
<?php ps_close(); ?>
</body>
</html>
