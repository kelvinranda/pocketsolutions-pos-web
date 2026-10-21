<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/barcode.php';
require_once __DIR__ . '/includes/catalog_setup.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

psEnsureGoodsReorderLevel($conn);
psEnsureGoodsStatus($conn);
psEnsureGoodsBarcodeSchema($conn);
psEnsureTaxModule($conn);
$taxCodeOptions = psTaxListDistinctCodes($conn, false);

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$selected_button_category = psNormalizeCategoryField($_GET['button_category'] ?? $_POST['button_category_filter'] ?? $_GET['category'] ?? '');
$statusFilterRaw = array_key_exists('status_filter', $_GET)
    ? (string)$_GET['status_filter']
    : (array_key_exists('status_filter', $_POST) ? (string)$_POST['status_filter'] : 'active');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'active', 'inactive'], true)) {
    $statusFilterKey = 'active';
}
$items_data = [];
$update_error = '';

$buttonCategories = psProductButtonCategoryOptions($conn);
// Phase 19: managed lists for the inline editor.
psCatalogEnsureSchema($conn);
$categoryList = psCatNames($conn, 'category');
$unitList = psUnitList($conn, true);
$unitCodes = array_map(static fn ($u) => (string)$u['code'], $unitList);

$exportBranches = psAuthorisedProductBranches($conn);
$exportAllowAll = psSessionAllowsAllProductBranches();
$exportSelected = psSessionProductBranch();
if (!in_array($exportSelected, $exportBranches, true)) {
    $exportSelected = $exportBranches ? (string)$exportBranches[0] : '';
}

$sessionBranchCheck = psRequireSessionProductBranch($conn);
$branch = $sessionBranchCheck['ok'] ? (string)$sessionBranchCheck['branch'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!isset($_SESSION['logger']) || trim((string)$_SESSION['logger']) === '') {
        $update_error = 'You must be signed in to change product status.';
    } elseif (!$sessionBranchCheck['ok']) {
        $update_error = (string)($sessionBranchCheck['error'] ?? 'That branch is not available.');
    } else {
        $statusItemId = (int)($_POST['item_id'] ?? 0);
        $postedStatus = (string)($_POST['new_status'] ?? '');
        $statusResult = psSetGoodsStatusById($conn, $statusItemId, $branch, $postedStatus);
        if ($statusResult['ok']) {
            $redirectStatus = $statusFilterKey;
            $redirectSearch = $search !== '' ? '&search=' . rawurlencode($search) : '';
            $redirectCat = $selected_button_category !== '' ? '&button_category=' . rawurlencode($selected_button_category) : '';
            header('Location: items_list.php?status_filter=' . rawurlencode($redirectStatus) . $redirectSearch . $redirectCat);
            exit();
        }
        $update_error = (string)($statusResult['error'] ?? 'Product status could not be saved.');
    }

    }
}

$where = [];
if ($branch !== '') {
    $branchEsc = mysqli_real_escape_string($conn, $branch);
    $where[] = "branch = '$branchEsc'";
} else {
    // No session branch: do not list products from other branches.
    $where[] = '1 = 0';
}
if ($search !== '') {
    $searchLike = mysqli_real_escape_string($conn, $search);
    $searchLike = str_replace(['%', '_'], ['\\%', '\\_'], $searchLike);
    $where[] = psBarcodeSearchSqlClause($conn, $searchLike);
}
if ($selected_button_category !== '') {
    $catEsc = mysqli_real_escape_string($conn, $selected_button_category);
    $where[] = "button_category = '$catEsc'";
}
if ($statusFilterKey === 'active') {
    $where[] = "status = '" . mysqli_real_escape_string($conn, PS_PRODUCT_STATUS_ACTIVE) . "'";
} elseif ($statusFilterKey === 'inactive') {
    $where[] = "status = '" . mysqli_real_escape_string($conn, PS_PRODUCT_STATUS_INACTIVE) . "'";
}
$sql = 'SELECT * FROM goods';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY product';

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $items_data[] = $row;
    }
} else {
    echo 'The items could not be loaded.';
}

if (isset($_POST['update'])) {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $product = trim((string)($_POST['product'] ?? ''));
    $priceRaw = $_POST['price'] ?? '';
    $costRaw = $_POST['cost_price'] ?? '';
    $category = psNormalizeCategoryField($_POST['category'] ?? '');
    $button_category = psNormalizeCategoryField($_POST['button_category'] ?? '');
    $unit_of_measure = (string)($_POST['unit_of_measure'] ?? '');
    $pack_size = (string)($_POST['pack_size'] ?? '');
    $reorderRaw = array_key_exists('reorder_level', $_POST) ? $_POST['reorder_level'] : null;
    $barcodeRaw = array_key_exists('barcode', $_POST) ? $_POST['barcode'] : '';

    $priceCheck = psValidateProductMoney($priceRaw, 'Selling price');
    $costCheck = psValidateProductMoney($costRaw, 'Cost price');

    if (!isset($_SESSION['logger']) || trim((string)$_SESSION['logger']) === '') {
        $update_error = 'You must be signed in to change product prices.';
    } elseif ($item_id < 1) {
        $update_error = 'Product was not found.';
    } elseif ($product === '') {
        $update_error = 'Product name is required.';
    } elseif (!$priceCheck['ok']) {
        $update_error = $priceCheck['error'];
    } elseif (!$costCheck['ok']) {
        $update_error = $costCheck['error'];
    } elseif ($category === '') {
        $update_error = 'Category is required.';
    } elseif ($button_category === '') {
        $update_error = 'Button Category is required.';
    } elseif (!$sessionBranchCheck['ok'] || $branch === '') {
        $update_error = (string)($sessionBranchCheck['error'] ?? 'That branch is not available.');
    } else {
        $existing = psGoodsRowById($conn, $item_id, $branch);
        if (!$existing) {
            $update_error = 'Product was not found on the selected branch.';
        } else {
            $reorderParsed = psParseReorderLevel($reorderRaw);
            $barcodeCheck = psValidateBarcodeForBranch($conn, $branch, $barcodeRaw, $item_id);
            if (!$reorderParsed['ok']) {
                $update_error = $reorderParsed['error'];
            } elseif (!$barcodeCheck['ok']) {
                $update_error = $barcodeCheck['error'];
            } elseif (($catOk = psCatMatch($conn, 'category', $category)) === '' && strcasecmp($category, trim((string)($existing['category'] ?? ''))) !== 0) {
                $update_error = 'Choose a category from the list (manage them under Categories & Groups).';
            } elseif (($btnOk = psCatMatch($conn, 'button', $button_category)) === '' && strcasecmp($button_category, trim((string)($existing['button_category'] ?? ''))) !== 0) {
                $update_error = 'Choose a button group from the list (manage them under Categories & Groups).';
            } elseif (!in_array($unit_of_measure, $unitCodes, true) && $unit_of_measure !== (string)($existing['unit_of_measure'] ?? '')) {
                $update_error = 'Choose a unit from the list.';
            } elseif (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', trim($pack_size)) && trim($pack_size) !== trim((string)($existing['pack_size'] ?? ''))) {
                $update_error = 'Pack size must be a number, e.g. 500 or 1.5.';
            } elseif (!psEnsureGoodsReorderLevel($conn)) {
                $update_error = 'Reorder level could not be saved.';
            } elseif (!psEnsureGoodsBarcodeSchema($conn)) {
                $update_error = 'Barcode could not be saved.';
            } elseif (!psEnsureProductAuditSchema($conn)) {
                $update_error = 'The item could not be saved.';
            } else {
                $price = $priceCheck['value'];
                $cost_price = $costCheck['value'];
                $barcodeSave = $barcodeCheck['value'];
                $barcodeParam = psBarcodeBindParam($barcodeSave);
                $beforeSnap = psProductAuditSnapshotFromGoods($existing);
                $afterSnap = psProductAuditSnapshotFromGoods([
                    'product' => $product,
                    'price' => $price,
                    'cost_price' => $cost_price,
                    'category' => $category,
                    'button_category' => $button_category,
                    'reorder_level' => $reorderParsed['value'],
                    'pack_size' => $pack_size,
                    'status' => $existing['status'] ?? PS_PRODUCT_STATUS_ACTIVE,
                    'barcode' => $barcodeSave,
                ]);
                $auditChanges = psProductAuditDiff($beforeSnap, $afterSnap);
                $tx = psProductAuditBegin($conn);
                if (!$tx['ok']) {
                    $update_error = 'The item could not be saved.';
                } else {
                    $owns = !empty($tx['owns']);
                    $ok = false;
                    if ($reorderParsed['value'] === null) {
                        $stmt = mysqli_prepare(
                            $conn,
                            'UPDATE goods SET product = ?, price = ?, cost_price = ?, category = ?, button_category = ?, unit_of_measure = ?, pack_size = ?, reorder_level = NULL, barcode = ?
                             WHERE id = ? AND branch = ?'
                        );
                        if (!$stmt) {
                            error_log('product update prepare failed');
                            $update_error = 'The item could not be saved.';
                        } else {
                            mysqli_stmt_bind_param(
                                $stmt,
                                'ssssssssis',
                                $product,
                                $price,
                                $cost_price,
                                $category,
                                $button_category,
                                $unit_of_measure,
                                $pack_size,
                                $barcodeParam,
                                $item_id,
                                $branch
                            );
                            $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0;
                            mysqli_stmt_close($stmt);
                        }
                    } else {
                        $reorderValue = (string)$reorderParsed['value'];
                        $stmt = mysqli_prepare(
                            $conn,
                            'UPDATE goods SET product = ?, price = ?, cost_price = ?, category = ?, button_category = ?, unit_of_measure = ?, pack_size = ?, reorder_level = ?, barcode = ?
                             WHERE id = ? AND branch = ?'
                        );
                        if (!$stmt) {
                            error_log('product update prepare failed');
                            $update_error = 'The item could not be saved.';
                        } else {
                            mysqli_stmt_bind_param(
                                $stmt,
                                'sssssssssis',
                                $product,
                                $price,
                                $cost_price,
                                $category,
                                $button_category,
                                $unit_of_measure,
                                $pack_size,
                                $reorderValue,
                                $barcodeParam,
                                $item_id,
                                $branch
                            );
                            $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0;
                            mysqli_stmt_close($stmt);
                        }
                    }
                    if ($ok && array_key_exists('sell_by_measure', $_POST)) {
                        // Phase 20: sold by an entered amount (weight / volume / length units only).
                        $measureNew = !empty($_POST['sell_by_measure']) && psUnitIsMeasurable($conn, $unit_of_measure) ? 1 : 0;
                        $measureOld = !empty($existing['sell_by_measure']) ? 1 : 0;
                        if ($measureNew !== $measureOld) {
                            $mu = mysqli_prepare($conn, 'UPDATE goods SET sell_by_measure = ? WHERE id = ? AND branch = ?');
                            mysqli_stmt_bind_param($mu, 'iis', $measureNew, $item_id, $branch);
                            $ok = mysqli_stmt_execute($mu);
                            mysqli_stmt_close($mu);
                            $auditChanges[] = ['field_name' => 'sell_by_measure', 'old_value' => (string)$measureOld, 'new_value' => (string)$measureNew];
                        }
                    }
                    if ($ok) {
                        $postedTaxCode = array_key_exists('tax_code', $_POST) ? $_POST['tax_code'] : null;
                        $taxAssign = psTaxSetProductCode(
                            $conn,
                            $item_id,
                            $branch,
                            $postedTaxCode,
                            trim((string)($_SESSION['logger'] ?? ''))
                        );
                        if (empty($taxAssign['ok'])) {
                            $ok = false;
                            $update_error = (string)($taxAssign['error'] ?? 'Tax code could not be saved.');
                        }
                    }
                    if ($ok) {
                        $productIdAudit = trim((string)($existing['product_id'] ?? ''));
                        $audited = psProductAuditRecordChanges($conn, [
                            'product_id' => $productIdAudit,
                            'goods_id' => $item_id,
                            'branch' => $branch,
                            'action' => PS_PRODUCT_AUDIT_ACTION_UPDATE,
                            'source' => PS_PRODUCT_AUDIT_SOURCE_UI,
                        ], $auditChanges);
                        if ($audited && psProductAuditCommit($conn, $owns)) {
                            header('Location: items_list.php');
                            exit();
                        }
                        psProductAuditRollback($conn, $owns);
                        error_log('product update audit failed');
                        $update_error = 'The item could not be saved.';
                    } else {
                        psProductAuditRollback($conn, $owns);
                        if ($update_error === '') {
                            error_log('product update failed');
                            $update_error = 'The item could not be saved.';
                        }
                    }
                }
            }
        }
    }
}

mysqli_close($conn);
require_once __DIR__ . '/includes/ps_layout.php';
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items List</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>

<h1>Items</h1>
<p class="ps-page-lead">Edit products for the working branch. Inactive items stay for history and are hidden from POS.</p>

<?php if ($update_error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($update_error); ?></div>
<?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Search by name, product ID, or barcode.</p>
    </div>
    <div class="ps-card-content">
        <form method="GET" action="items_list.php" class="ps-form-inline filter-form">
            <input class="ps-input" type="text" name="search" placeholder="Search by name, Product ID, or barcode" value="<?php echo $h($search); ?>">
            <select class="ps-input" name="button_category">
                <option value="">All Button Categories</option>
                <?php foreach ($buttonCategories as $btnCat): ?>
                    <option value="<?php echo $h($btnCat); ?>" <?php echo $selected_button_category === (string)$btnCat ? 'selected' : ''; ?>><?php echo $h($btnCat); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="ps-input" name="status_filter">
                <option value="all"<?php echo $statusFilterKey === 'all' ? ' selected' : ''; ?>>All statuses</option>
                <option value="active"<?php echo $statusFilterKey === 'active' ? ' selected' : ''; ?>>Active</option>
                <option value="inactive"<?php echo $statusFilterKey === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
            </select>
            <button class="ps-btn ps-btn-default" type="submit">Search</button>
        </form>
    </div>
</div>

<div class="ps-items-toolbar ps-link-row" aria-label="Item actions">
    <a class="ps-btn ps-btn-default ps-btn-sm" href="create_items.php">Create item</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="barcode_labels.php">Barcode labels</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="import_items.php">Import</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="product_audit_history.php">Product audit</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="generate_items_pdf.php">Print</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="admin_dashboard.php">Dashboard</a>
    <form class="export-form" method="post" action="export.php">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
        <label class="ps-label" for="export_branch">Export branch</label>
        <select class="ps-input" id="export_branch" name="export_branch">
            <?php if ($exportAllowAll): ?>
                <option value="__all__">All Branches</option>
            <?php endif; ?>
            <?php foreach ($exportBranches as $exportBranch): ?>
                <option value="<?php echo $h($exportBranch); ?>"<?php echo $exportSelected === $exportBranch ? ' selected' : ''; ?>><?php echo $h($exportBranch); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Export Excel</button>
    </form>
</div>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Products</h2>
        <p class="ps-card-desc">Scroll horizontally when needed. Full values are available via hover title. Row actions are in the menu.</p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table ps-items-table">
                <thead>
                    <tr>
                        <th class="col-id">Product ID</th>
                        <th class="col-product">Product</th>
                        <th class="col-barcode">Barcode</th>
                        <th class="col-money">Price</th>
                        <th class="col-money">Cost</th>
                        <th class="col-money">Margin</th>
                        <th class="col-cat">Category</th>
                        <th class="col-cat">Button category</th>
                        <th>UoM</th>
                        <th>Reorder</th>
                        <th>Pack</th>
                        <th>Tax</th>
                        <th class="col-status">Status</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items_data)): ?>
                    <tr>
                        <td colspan="14" class="ps-muted">No items found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items_data as $item): ?>
                        <?php
                        $itemId = (int)$item['id'];
                        $formId = 'item-form-' . $itemId;
                        $rowButtonOptions = $buttonCategories;
                        $itemButton = (string)($item['button_category'] ?? '');
                        if ($itemButton !== '' && !in_array($itemButton, $rowButtonOptions, true)) {
                            $rowButtonOptions[] = $itemButton;
                        }
                        $itemStatus = strtoupper(trim((string)($item['status'] ?? PS_PRODUCT_STATUS_ACTIVE)));
                        $isActive = $itemStatus === PS_PRODUCT_STATUS_ACTIVE;
                        $productName = (string)($item['product'] ?? '');
                        $productId = (string)($item['product_id'] ?? '');
                        $barcodeVal = (string)($item['barcode'] ?? '');
                        $margin = number_format(psProductProfitMargin($item['price'], $item['cost_price']), 2);
                        $reorderShown = '';
                        $shownLevel = array_key_exists('reorder_level', $item) ? $item['reorder_level'] : null;
                        if ($shownLevel !== null && trim((string)$shownLevel) !== '') {
                            $shownDecimal = psStockDecimal($shownLevel);
                            if ($shownDecimal !== null) {
                                $reorderShown = psStockQtyPlain($shownDecimal);
                            }
                        }
                        $itemTax = strtoupper(trim((string)($item['tax_code'] ?? '')));
                        ?>
                        <tr data-ps-product="<?php echo $h($productId . ' ' . $productName . ' ' . $barcodeVal); ?>">
                            <td class="col-id">
                                <span class="ps-cell-clip" title="<?php echo $h($productId); ?>"><?php echo $h($productId); ?></span>
                                <form method="POST" action="" id="<?php echo $h($formId); ?>">
                                    <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                    <input type="hidden" name="item_id" value="<?php echo $itemId; ?>">
                                    <input type="hidden" name="status_filter" value="<?php echo $h($statusFilterKey); ?>">
                                </form>
                            </td>
                            <td class="col-product">
                                <input class="ps-input" form="<?php echo $h($formId); ?>" type="text" name="product" title="<?php echo $h($productName); ?>" value="<?php echo $h($productName); ?>">
                            </td>
                            <td class="col-barcode">
                                <input class="ps-input" form="<?php echo $h($formId); ?>" type="text" name="barcode" maxlength="64" autocomplete="off" inputmode="text"
                                       title="<?php echo $h($barcodeVal); ?>"
                                       value="<?php echo $h($barcodeVal); ?>"
                                       placeholder="optional">
                            </td>
                            <td class="col-money">
                                <input class="ps-input" form="<?php echo $h($formId); ?>" type="number" step="0.01" min="0" name="price" value="<?php echo $h(number_format(floatval(str_replace(',', '', $item['price'])), 2, '.', '')); ?>">
                            </td>
                            <td class="col-money">
                                <input class="ps-input" form="<?php echo $h($formId); ?>" type="number" step="0.01" min="0" name="cost_price" value="<?php echo $h(number_format(floatval($item['cost_price']), 2, '.', '')); ?>">
                            </td>
                            <td class="col-money" title="<?php echo $h($margin); ?>"><?php echo $h($margin); ?></td>
                            <td class="col-cat">
                                <?php $itemCat = (string)($item['category'] ?? ''); $rowCats = $categoryList; if (trim($itemCat) !== '' && !in_array($itemCat, $rowCats, true)) { $rowCats[] = $itemCat; } ?>
                                <select class="ps-input" form="<?php echo $h($formId); ?>" name="category" title="<?php echo $h($itemCat); ?>">
                                    <?php if (trim($itemCat) === ''): ?><option value="">—</option><?php endif; ?>
                                    <?php foreach ($rowCats as $catOpt): ?><option value="<?php echo $h($catOpt); ?>" <?php echo $itemCat === (string)$catOpt ? 'selected' : ''; ?>><?php echo $h($catOpt); ?></option><?php endforeach; ?>
                                </select>
                            </td>
                            <td class="col-cat">
                                <select class="ps-input" form="<?php echo $h($formId); ?>" name="button_category" title="<?php echo $h($itemButton); ?>">
                                    <?php foreach ($rowButtonOptions as $btnOpt): ?>
                                        <option value="<?php echo $h($btnOpt); ?>" <?php echo $itemButton === (string)$btnOpt ? 'selected' : ''; ?>><?php echo $h($btnOpt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php $itemUnit = (string)($item['unit_of_measure'] ?? ''); ?>
                                <select class="ps-input" form="<?php echo $h($formId); ?>" name="unit_of_measure" title="<?php echo $h($itemUnit); ?>">
                                    <?php if (!in_array($itemUnit, $unitCodes, true)): ?><option value="<?php echo $h($itemUnit); ?>" selected><?php echo $h($itemUnit !== '' ? $itemUnit : '—'); ?></option><?php endif; ?>
                                    <?php foreach ($unitList as $uOpt): ?><option value="<?php echo $h($uOpt['code']); ?>" <?php echo $itemUnit === (string)$uOpt['code'] ? 'selected' : ''; ?>><?php echo $h($uOpt['code']); ?></option><?php endforeach; ?>
                                </select>
                                <input type="hidden" form="<?php echo $h($formId); ?>" name="sell_by_measure" value="0">
                                <label class="il-measure" style="display:flex;gap:4px;align-items:center;font-size:.78rem;margin-top:4px;white-space:nowrap" title="Sold by an entered amount (e.g. 1.25 kg) — weight, volume or length units only"><input type="checkbox" form="<?php echo $h($formId); ?>" name="sell_by_measure" value="1" <?php echo !empty($item['sell_by_measure']) ? 'checked' : ''; ?>> by amount</label>
                            </td>
                            <td>
                                <input class="ps-input" form="<?php echo $h($formId); ?>" type="number" step="0.001" min="0" name="reorder_level" value="<?php echo $h($reorderShown); ?>">
                            </td>
                            <td>
                                <input class="ps-input" form="<?php echo $h($formId); ?>" type="text" name="pack_size" title="<?php echo $h((string)($item['pack_size'] ?? '')); ?>" value="<?php echo $h((string)($item['pack_size'] ?? '')); ?>">
                            </td>
                            <td>
                                <select class="ps-input" form="<?php echo $h($formId); ?>" name="tax_code" title="<?php echo $h($itemTax !== '' ? $itemTax : 'none'); ?>">
                                    <option value="">— none —</option>
                                    <?php foreach ($taxCodeOptions as $tc): ?>
                                        <option value="<?php echo $h($tc['code']); ?>" <?php echo $itemTax === $tc['code'] ? 'selected' : ''; ?>><?php echo $h($tc['code']); ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($itemTax !== '' && !in_array($itemTax, array_column($taxCodeOptions, 'code'), true)): ?>
                                        <option value="<?php echo $h($itemTax); ?>" selected><?php echo $h($itemTax); ?> (missing rate)</option>
                                    <?php endif; ?>
                                </select>
                            </td>
                            <td class="col-status">
                                <span class="ps-badge <?php echo $isActive ? 'ps-badge-success' : 'ps-badge-secondary'; ?>">
                                    <?php echo $h(psProductStatusLabel($itemStatus)); ?>
                                </span>
                            </td>
                            <td class="col-actions">
                                <div class="ps-action-menu">
                                    <button type="button" class="ps-btn ps-btn-outline ps-btn-sm ps-btn-icon" data-ps-action-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Row actions">⋯</button>
                                    <div class="ps-action-menu-panel" role="menu" hidden>
                                        <button type="submit" form="<?php echo $h($formId); ?>" name="update" value="1" role="menuitem">Save</button>
                                        <?php if ($isActive): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Deactivate this product? It will no longer appear on POS for new sales. Sales history and stock records are preserved.');">
                                                <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                                <input type="hidden" name="item_id" value="<?php echo $itemId; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $h(PS_PRODUCT_STATUS_INACTIVE); ?>">
                                                <input type="hidden" name="status_filter" value="<?php echo $h($statusFilterKey); ?>">
                                                <input type="hidden" name="search" value="<?php echo $h($search); ?>">
                                                <input type="hidden" name="button_category_filter" value="<?php echo $h($selected_button_category); ?>">
                                                <button type="submit" name="set_status" value="1" role="menuitem" class="is-danger">Deactivate</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="">
                                                <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                                <input type="hidden" name="item_id" value="<?php echo $itemId; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $h(PS_PRODUCT_STATUS_ACTIVE); ?>">
                                                <input type="hidden" name="status_filter" value="<?php echo $h($statusFilterKey); ?>">
                                                <input type="hidden" name="search" value="<?php echo $h($search); ?>">
                                                <input type="hidden" name="button_category_filter" value="<?php echo $h($selected_button_category); ?>">
                                                <button type="submit" name="set_status" value="1" role="menuitem">Activate</button>
                                            </form>
                                        <?php endif; ?>
                                        <a role="menuitem" href="product_history.php?product_id=<?php echo rawurlencode($productId); ?>">History</a>
                                        <a role="menuitem" href="product_audit_history.php?goods_id=<?php echo $itemId; ?>">Audit</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php ps_close(); ?>
</body>
</html>
