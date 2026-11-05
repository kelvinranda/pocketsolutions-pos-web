<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/purchase_order.php';
require_once __DIR__ . '/includes/product_packs.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsurePurchaseOrderAllSchema($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$editId = (int)($_GET['id'] ?? $_POST['po_id'] ?? 0);
$isEdit = $editId > 0;

$supplier_id = (int)($_POST['supplier_id'] ?? 0);
$order_date = trim((string)($_POST['order_date'] ?? date('Y-m-d')));
$expected_date = trim((string)($_POST['expected_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$postedBranch = $_POST['branch'] ?? null;

$lineProductIds = $_POST['line_product_id'] ?? [];
$lineQtys = $_POST['line_quantity'] ?? [];
$lineCosts = $_POST['line_unit_cost'] ?? [];
if (!is_array($lineProductIds)) {
    $lineProductIds = [];
}
if (!is_array($lineQtys)) {
    $lineQtys = [];
}
if (!is_array($lineCosts)) {
    $lineCosts = [];
}

$branch = '';
$suppliers = [];
$products = [];
$header = null;
$existingLines = [];

if ($signedIn) {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $supList = psActiveSuppliersForSelect($conn);
        if ($supList['ok']) {
            $suppliers = $supList['rows'] ?? [];
        }
        $prodList = psActiveProductsForPoSelect($conn, $branch, '', 300);
        if ($prodList['ok']) {
            $products = $prodList['rows'] ?? [];
        }

        if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $load = psPurchaseOrderLoadForSession($conn, $editId);
            if (!$load['ok']) {
                $error = (string)($load['error'] ?? 'Purchase order not found.');
                $isEdit = false;
            } else {
                $header = $load['header'];
                if (!psPoIsEditableStatus((string)$header['status'])) {
                    header('Location: purchase_order_view.php?id=' . $editId);
                    exit;
                }
                $supplier_id = (int)$header['supplier_id'];
                $order_date = (string)$header['order_date'];
                $expected_date = (string)($header['expected_date'] ?? '');
                $notes = (string)$header['notes'];
                $existingLines = $load['lines'];
            }
        }
    }
} else {
    $error = 'You must be signed in to create a purchase order.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $signedIn && $branch !== '') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    // 2026-10-28 packs: an order line entered in packs is saved in base units, its cost per base unit.
    $poCv = psPackConvertRows($conn, trim((string)($_SESSION['the_company'] ?? '')), $lineProductIds, $lineQtys, (array)($_POST['line_unit'] ?? []), $lineCosts);
    if (empty($poCv['ok'])) {
        $error = (string)$poCv['error'];
    } else {
        $lineQtys = $poCv['qtys'];
        $lineCosts = $poCv['costs'] ?: $lineCosts;
    }
    $rawLines = [];
    $count = max(count($lineProductIds), count($lineQtys), count($lineCosts));
    for ($i = 0; $i < $count; $i++) {
        $rawLines[] = [
            'product_id' => $lineProductIds[$i] ?? '',
            'quantity' => $lineQtys[$i] ?? '',
            'unit_cost' => $lineCosts[$i] ?? '',
        ];
    }
    $payload = [
        'supplier_id' => $supplier_id,
        'branch' => $postedBranch,
        'order_date' => $order_date,
        'expected_date' => $expected_date,
        'notes' => $notes,
        'currency' => $_POST['currency'] ?? '',
    ];
    if ($error !== '' && $error !== null) {
        // pack conversion error: shown below, nothing saved
    } elseif ($isEdit) {
        $result = psUpdatePurchaseOrder($conn, $editId, $payload, $rawLines);
        if ($result['ok']) {
            header('Location: purchase_orders_list.php?updated=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The purchase order could not be saved.');
    } else {
        $result = psCreatePurchaseOrder($conn, $payload, $rawLines);
        if ($result['ok']) {
            header('Location: purchase_orders_list.php?created=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The purchase order could not be saved.');
    }

    }
}

$displayLines = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = max(count($lineProductIds), count($lineQtys), count($lineCosts), 1);
    for ($i = 0; $i < $count; $i++) {
        $displayLines[] = [
            'product_id' => (string)($lineProductIds[$i] ?? ''),
            'quantity' => (string)($lineQtys[$i] ?? ''),
            'unit_cost' => (string)($lineCosts[$i] ?? ''),
        ];
    }
} elseif ($existingLines !== []) {
    foreach ($existingLines as $line) {
        $displayLines[] = [
            'product_id' => (string)$line['product_id'],
            'quantity' => (string)$line['quantity'],
            'unit_cost' => (string)$line['unit_cost'],
        ];
    }
} else {
    $displayLines[] = ['product_id' => '', 'quantity' => '', 'unit_cost' => ''];
}

// Multi-currency (2026-10-20): order currency (fixed once the order is created) and indicative rates for cost suggestions.
require_once __DIR__ . '/includes/currency.php';
$poCurrencies = $signedIn ? psFxCompanyCurrencies($conn, psFxSessionCompany()) : [];
$poBase = $signedIn ? psFxBase($conn, psFxSessionCompany()) : '';
$poRates = [];
foreach ($poCurrencies as $code => $c) {
    $r = psFxSnapshot($conn, psFxSessionCompany(), $code);
    $poRates[$code] = !empty($r['ok']) ? (string)$r['rate'] : '';
}
$poCurrencyShown = $isEdit && isset($header) ? (psFxNormalizeCode($header['currency'] ?? '') ?: $poBase) : (psFxNormalizeCode($_POST['currency'] ?? '') ?: $poBase);
$packAssets = psPackUnitAssets($conn, trim((string)($_SESSION['the_company'] ?? '')));
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isEdit ? 'Edit Purchase Order' : 'Create Purchase Order'; ?></title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1><?php echo $isEdit ? 'Edit Purchase Order' : 'Create Purchase Order'; ?></h1>
    <p class="hint">
        Saves as <strong>Draft</strong>. Does not change stock or product cost.
        Branch is taken from your session
        (<?php echo htmlspecialchars($branch !== '' ? $branch : 'none', ENT_QUOTES, 'UTF-8'); ?>).
        <?php if ($isEdit && $header): ?>
            PO: <?php echo htmlspecialchars((string)$header['po_number'], ENT_QUOTES, 'UTF-8'); ?>
        <?php endif; ?>
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $branch !== ''): ?>
        <form method="POST" action="" class="po-form" id="po-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="po_id" value="<?php echo (int)$editId; ?>">
            <?php endif; ?>
            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="po-grid">
                <div>
                    <label for="supplier_id">Supplier (active only)</label>
                    <select id="supplier_id" name="supplier_id" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo (int)$sup['id']; ?>"<?php echo $supplier_id === (int)$sup['id'] ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$sup['supplier_code'] . ' — ' . (string)$sup['supplier_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Branch</label>
                    <input type="text" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                <div>
                    <?php if ($isEdit): ?>
                        <label>Currency</label>
                        <input type="text" value="<?php echo htmlspecialchars($poCurrencyShown, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    <?php else: ?>
                        <label for="po_currency">Currency</label>
                        <select id="po_currency" name="currency">
                            <?php foreach ($poCurrencies as $code => $c): ?>
                                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" data-rate="<?php echo htmlspecialchars($poRates[$code] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-dec="<?php echo (int)$c['decimals']; ?>"<?php echo $poCurrencyShown === $code ? ' selected' : ''; ?>><?php echo htmlspecialchars($code . ' — ' . $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="order_date">Order date</label>
                    <input type="date" id="order_date" name="order_date" required value="<?php echo htmlspecialchars($order_date, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="expected_date">Expected date</label>
                    <input type="date" id="expected_date" name="expected_date" value="<?php echo htmlspecialchars($expected_date, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" maxlength="1000"><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>

            <h2 style="margin-top:20px;font-size:16px;">Lines</h2>
            <p class="hint">Products must be active on this branch. Duplicate names show product ID. Line totals are calculated on the server.</p>
            <table class="po-lines" id="po-lines">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width:120px;">Qty</th>
                        <th style="width:140px;">Unit cost</th>
                        <th style="width:70px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($displayLines as $line): ?>
                    <tr>
                        <td>
                            <select name="line_product_id[]">
                                <option value="">Select product</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?php echo htmlspecialchars((string)$p['product_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-cost="<?php echo htmlspecialchars((string)$p['cost_price'], ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo (string)$line['product_id'] === (string)$p['product_id'] ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="line_quantity[]" inputmode="decimal" value="<?php echo htmlspecialchars((string)$line['quantity'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Qty">
                            <select name="line_unit[]" data-pack-unit-for="line_product_id[]" aria-label="Unit" hidden><option value="base"></option></select></td>
                        <td><input type="text" name="line_unit_cost[]" inputmode="decimal" value="<?php echo htmlspecialchars((string)$line['unit_cost'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="0.00"></td>
                        <td><button type="button" class="po-remove-line">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="po-actions">
                <button type="button" id="po-add-line">Add line</button>
                <button type="submit">Save draft</button>
                <a href="purchase_orders_list.php">Cancel</a>
            </div>
        </form>

        <template id="po-line-template">
            <tr>
                <td>
                    <select name="line_product_id[]">
                        <option value="">Select product</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo htmlspecialchars((string)$p['product_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-cost="<?php echo htmlspecialchars((string)$p['cost_price'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="line_quantity[]" inputmode="decimal" value="" placeholder="Qty">
                    <select name="line_unit[]" data-pack-unit-for="line_product_id[]" aria-label="Unit" hidden><option value="base"></option></select></td>
                <td><input type="text" name="line_unit_cost[]" inputmode="decimal" value="" placeholder="0.00"></td>
                <td><button type="button" class="po-remove-line">Remove</button></td>
            </tr>
        </template>
        <script>
        (function () {
            var table = document.getElementById('po-lines');
            var tpl = document.getElementById('po-line-template');
            var addBtn = document.getElementById('po-add-line');
            function bindRow(row) {
                var sel = row.querySelector('select[name="line_product_id[]"]');
                var cost = row.querySelector('input[name="line_unit_cost[]"]');
                if (sel && cost) {
                    sel.addEventListener('change', function () {
                        var opt = sel.options[sel.selectedIndex];
                        if (opt && opt.getAttribute('data-cost') && cost.value === '') {
                            // Product cost is kept in the company base; suggest it in the order currency.
                            var cur = document.getElementById('po_currency');
                            var copt = cur ? cur.options[cur.selectedIndex] : null;
                            var rate = copt ? parseFloat(copt.getAttribute('data-rate') || '1') : 1;
                            var dec = copt ? parseInt(copt.getAttribute('data-dec') || '2', 10) : 2;
                            var base = parseFloat(opt.getAttribute('data-cost'));
                            cost.value = (isFinite(base) && rate > 0) ? (base * rate).toFixed(dec) : opt.getAttribute('data-cost');
                        }
                    });
                }
                var rm = row.querySelector('.po-remove-line');
                if (rm) {
                    rm.addEventListener('click', function () {
                        var body = table.querySelector('tbody');
                        if (body.querySelectorAll('tr').length <= 1) {
                            sel.value = '';
                            row.querySelector('input[name="line_quantity[]"]').value = '';
                            cost.value = '';
                            return;
                        }
                        row.parentNode.removeChild(row);
                    });
                }
            }
            Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), bindRow);
            if (addBtn) {
                addBtn.addEventListener('click', function () {
                    var node = tpl.content.cloneNode(true);
                    var row = node.querySelector('tr');
                    table.querySelector('tbody').appendChild(node);
                    bindRow(table.querySelector('tbody').lastElementChild);
                    if (typeof psPackBindAll === 'function') psPackBindAll(table.querySelector('tbody').lastElementChild);
                });
            }
        })();
        </script>
    <?php endif; ?>
<?php echo $packAssets; ?>
<?php ps_close(); ?>
</body>
</html>
