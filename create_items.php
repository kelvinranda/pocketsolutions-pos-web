<?php
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/barcode.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/catalog_setup.php';
require_once __DIR__ . '/includes/tax.php';

/** "S, M , L,,XL" -> ['S','M','L','XL'] (unique, max 30 chars each). */
function psVariantValues(string $raw): array
{
    $out = [];
    foreach (preg_split('/[,\n;]+/', $raw) as $v) {
        $v = mb_substr(preg_replace('/\s+/', ' ', trim($v)), 0, 30);
        if ($v !== '' && !in_array(mb_strtolower($v), array_map('mb_strtolower', $out), true)) {
            $out[] = $v;
        }
    }
    return $out;
}

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

$product_id = $product = $price = $cost_price = $category = $button_category = $reorderRaw = $barcode = '';
$pack_size = '1';
$unit = 'ea';
$branch = psSessionProductBranch();
$error = '';

// Phase 19: managed lists (company categories / button groups, shared units of measure).
psCatalogEnsureSchema($conn);
$categoryOptions = psCatNames($conn, 'category');
$buttonCategoryOptions = psCatNames($conn, 'button');
$unitOptions = psUnitList($conn, true);
psEnsureTaxModule($conn);
$taxOptions = psTaxListDistinctCodes($conn, true);
$taxCode = '';
$created = $_SESSION['ps_item_created'] ?? null;
unset($_SESSION['ps_item_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    $product = trim((string)($_POST['product'] ?? ''));
    $price = trim((string)($_POST['price'] ?? ''));
    $cost_price = trim((string)($_POST['cost_price'] ?? ''));
    $category = psCatMatch($conn, 'category', psNormalizeCategoryField($_POST['category'] ?? ''));
    $button_category = psCatMatch($conn, 'button', psNormalizeCategoryField($_POST['button_category'] ?? ''));
    $unit = trim((string)($_POST['unit_of_measure'] ?? ''));
    $unitValid = in_array($unit, array_map(static fn ($u) => (string)$u['code'], $unitOptions), true);
    if (array_key_exists('reorder_level', $_POST)) {
        $reorderRaw = trim((string)$_POST['reorder_level']);
    } elseif (array_key_exists('re_order_level', $_POST)) {
        $reorderRaw = trim((string)$_POST['re_order_level']);
    } else {
        $reorderRaw = '';
    }
    $pack_size = (string)($_POST['pack_size'] ?? '');
    $barcode = (string)($_POST['barcode'] ?? '');
    $postedBranch = array_key_exists('branch', $_POST) ? (string)$_POST['branch'] : null;
    $branchResolved = psResolveProductWriteBranch($conn, $postedBranch);
    $branch = $branchResolved['ok'] ? (string)$branchResolved['branch'] : psSessionProductBranch();

    $priceCheck = psValidateProductMoney($price, 'Selling price');
    $costCheck = psValidateProductMoney($cost_price, 'Cost price');
    $reorder = psParseReorderLevel($reorderRaw);
    $barcodeCheck = ['ok' => true, 'value' => null];
    if ($branchResolved['ok']) {
        $barcodeCheck = psValidateBarcodeForBranch($conn, (string)$branchResolved['branch'], $barcode, 0);
    }

    if (!isset($_SESSION['logger']) || trim((string)$_SESSION['logger']) === '') {
        $error = 'You must be signed in to create a product.';
    } elseif (!$branchResolved['ok']) {
        $error = (string)($branchResolved['error'] ?? 'That branch is not available.');
    } elseif ($product === '') {
        $error = 'Product name is required.';
    } elseif (!$priceCheck['ok']) {
        $error = $priceCheck['error'];
    } elseif (!$costCheck['ok']) {
        $error = $costCheck['error'];
    } elseif ($category === '') {
        $error = 'Choose a category from the list (add new ones under Categories & Groups).';
    } elseif ($button_category === '') {
        $error = 'Choose a button group from the list (add new ones under Categories & Groups).';
    } elseif (!$reorder['ok']) {
        $error = $reorder['error'];
    } elseif (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', trim($pack_size)) || (float)$pack_size <= 0) {
        $error = 'Pack size must be a number greater than 0 (up to 3 decimals), e.g. 500 or 1.5.';
    } elseif (!$unitValid) {
        $error = 'Choose a unit for the pack size.';
    } elseif (!$barcodeCheck['ok']) {
        $error = $barcodeCheck['error'];
    } elseif (!psEnsureGoodsReorderLevel($conn)) {
        $error = 'Reorder level could not be saved.';
    } elseif (!psEnsureGoodsStatus($conn)) {
        $error = 'Product status could not be saved.';
    } elseif (!psEnsureGoodsBarcodeSchema($conn)) {
        $error = 'Barcode could not be saved.';
    } else {
        $pack_size = trim($pack_size);
        if (strpos($pack_size, '.') !== false) {
            $pack_size = rtrim(rtrim($pack_size, '0'), '.');
        }
        // Phase 20: sold by an entered amount (price is per 1 unit), tax code, variants.
        $measure = !empty($_POST['sell_by_measure']) ? 1 : 0;
        if ($measure) {
            $pack_size = '1';
        }
        $taxCode = strtoupper(trim((string)($_POST['tax_code'] ?? '')));
        $variantRows = [['label' => null]];
        $variantError = '';
        if (!empty($_POST['has_variants'])) {
            $optA = psVariantValues((string)($_POST['variant_values_1'] ?? ''));
            $optB = psVariantValues((string)($_POST['variant_values_2'] ?? ''));
            if (!$optA && !$optB) {
                $variantError = 'Type the variant values, separated by commas (e.g. S, M, L).';
            } else {
                $variantRows = [];
                foreach ($optA ?: [null] as $a) {
                    foreach ($optB ?: [null] as $b) {
                        $label = trim(implode(' / ', array_filter([$a, $b], static fn ($x) => $x !== null && $x !== '')));
                        $variantRows[] = ['label' => $label];
                    }
                }
                if (count($variantRows) > 60) {
                    $variantError = 'That makes ' . count($variantRows) . ' variants — 60 is the most in one go.';
                }
            }
        }
        if ($measure && !psUnitIsMeasurable($conn, $unit)) {
            $error = 'Only products measured in weight, volume or length (kg, g, l, ml, m …) can be sold by an entered amount.';
        } elseif ($taxCode !== '' && !in_array($taxCode, array_column($taxOptions, 'code'), true)) {
            $error = 'Choose a tax code from the list.';
        } elseif ($variantError !== '') {
            $error = $variantError;
        } elseif (count($variantRows) > 1 && trim($barcode) !== '') {
            $error = 'Leave the barcode empty when creating variants — add each variant\'s barcode on the Items page afterwards.';
        } elseif (!psEnsureProductAuditSchema($conn)) {
            $error = 'The item could not be saved.';
        } else {
            $priceSave = $priceCheck['value'];
            $costSave = $costCheck['value'];
            $barcodeParam = psBarcodeBindParam($barcodeCheck['value']);
            $statusActive = PS_PRODUCT_STATUS_ACTIVE;
            $companySave = trim((string)($_SESSION['the_company'] ?? ''));
            $reorderValue = $reorder['value'] === null ? null : (string)$reorder['value'];
            $actor = trim((string)($_SESSION['logger'] ?? ''));
            $isVariants = count($variantRows) > 1 || $variantRows[0]['label'] !== null;
            $groupKey = $isVariants ? 'V' . date('ymdHis') . bin2hex(random_bytes(3)) : null;
            $baseName = $isVariants ? $product : null;
            $createdIds = [];
            $ok = true;
            $tx = psProductAuditBegin($conn);
            if (!$tx['ok']) {
                $ok = false;
            }
            $owns = !empty($tx['owns']);
            foreach ($ok ? $variantRows : [] as $vr) {
                $label = $vr['label'];
                $name = $label !== null && $label !== '' ? $product . ' - ' . $label : $product;
                $product_id = psNextSharedProductId($conn);
                if (psProductIdExistsOnBranch($conn, $product_id, $branch)) {
                    $ok = false;
                    $error = psProductDuplicateMessage();
                    break;
                }
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO goods (product_id, product, price, date_, cost_price, category, button_category, reorder_level, pack_size,
                        unit_of_measure, branch, status, barcode, company_name, sell_by_measure, variant_group, variant_base, variant_label)
                     VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    error_log('product create prepare failed: ' . mysqli_error($conn));
                    $ok = false;
                    break;
                }
                $bc = $isVariants ? null : $barcodeParam;
                mysqli_stmt_bind_param(
                    $stmt,
                    'sssssssssssssisss',
                    $product_id, $name, $priceSave, $costSave, $category, $button_category, $reorderValue, $pack_size,
                    $unit, $branch, $statusActive, $bc, $companySave, $measure, $groupKey, $baseName, $label
                );
                $ok = mysqli_stmt_execute($stmt);
                $goodsId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                if (!$ok) {
                    break;
                }
                $snapshot = psProductAuditSnapshotFromGoods([
                    'product' => $name, 'price' => $priceSave, 'cost_price' => $costSave, 'category' => $category,
                    'button_category' => $button_category, 'reorder_level' => $reorder['value'], 'pack_size' => $pack_size,
                    'status' => $statusActive, 'barcode' => $isVariants ? null : $barcodeCheck['value'],
                ]);
                $ok = $goodsId > 0
                    && psProductAuditRecordCreate($conn, $product_id, $goodsId, $branch, $snapshot, PS_PRODUCT_AUDIT_SOURCE_UI, PS_PRODUCT_AUDIT_ACTION_CREATE)
                    // 2026-10-14: new products are stock-tracked from creation (opening 0).
                    && psStockOpenNewProduct($conn, (string)$product_id, (string)$branch, null, $actor);
                if ($ok && $taxCode !== '') {
                    $t = psTaxSetProductCode($conn, $goodsId, $branch, $taxCode, $actor);
                    $ok = !empty($t['ok']);
                    if (!$ok) {
                        $error = (string)($t['error'] ?? 'Tax code could not be saved.');
                    }
                }
                if (!$ok) {
                    break;
                }
                $createdIds[] = $product_id;
            }
            if ($ok && $createdIds && psProductAuditCommit($conn, $owns)) {
                $_SESSION['ps_item_created'] = [
                    'name' => $product, 'id' => count($createdIds) > 1 ? $createdIds[0] . '–' . end($createdIds) : $createdIds[0],
                    'count' => count($createdIds), 'branch' => $branch, 'category' => $category, 'button' => $button_category,
                    'unit' => $unit, 'tax' => $taxCode,
                ];
                header('Location: create_items.php');
                exit();
            }
            if (!empty($tx['ok'])) {
                psProductAuditRollback($conn, $owns);
            }
            if ($error === '') {
                $error = psProductDuplicateKeyError($conn) ? psProductDuplicateMessage() : 'The item could not be saved.';
                error_log('product create failed: ' . mysqli_error($conn));
            }
        }
    }

    }
}

mysqli_close($conn);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
// After success keep category / group / unit for the next item (fast entry of similar products).
if (is_array($created)) {
    $category = (string)$created['category'];
    $button_category = (string)$created['button'];
    $unit = (string)$created['unit'];
    $taxCode = (string)($created['tax'] ?? '');
}
$unitGroups = [];
foreach ($unitOptions as $u) {
    $unitGroups[psUnitDimensions()[$u['dimension']] ?? 'Other'][] = $u;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Item</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
    <style>
        html[data-theme] body.ps-app .ci-wrap { max-width: 880px; }
        html[data-theme] body.ps-app form.ci-form.ci-form { display: flex; flex-direction: column; gap: 22px; margin: 0; }
        html[data-theme] body.ps-app .ci-group + .ci-group { border-top: 1px solid var(--ps-line); padding-top: 20px; }
        html[data-theme] body.ps-app .ci-legend { margin: 0 0 14px; font-size: 1rem; font-weight: 700; line-height: 1.3; display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
        html[data-theme] body.ps-app .ci-legend a { font-size: .86rem; font-weight: 500; }
        html[data-theme] body.ps-app .ci-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 20px; }
        html[data-theme] body.ps-app .ci-grid .ps-field { margin: 0; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        html[data-theme] body.ps-app .ci-grid .ps-input { width: 100%; }
        html[data-theme] body.ps-app .ci-span { grid-column: 1 / -1; }
        html[data-theme] body.ps-app .ci-pack { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr); gap: 10px; }
        html[data-theme] body.ps-app .ci-help { color: var(--ps-muted); font-size: .86rem; margin: 0; }
        html[data-theme] body.ps-app .ci-check { display: flex; gap: 10px; align-items: flex-start; line-height: 1.4; margin-top: 4px; cursor: pointer; }
        html[data-theme] body.ps-app .ci-check input { margin-top: 3px; flex: 0 0 auto; }
        html[data-theme] body.ps-app .ci-variants-off #variantBox { display: none !important; }
        html[data-theme] body.ps-app .ci-actions { display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap; border-top: 1px solid var(--ps-line); padding-top: 20px; }
        @media (max-width: 640px) { html[data-theme] body.ps-app .ci-grid { grid-template-columns: 1fr; } html[data-theme] body.ps-app .ci-actions .ps-btn { flex: 1 1 auto; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
<div class="ci-wrap">
    <h1>Create Item</h1>
    <p class="ps-page-lead">Adds a product to <strong><?php echo $h($branch !== '' ? $branch : 'the selected branch'); ?></strong>. The Product ID is given automatically. Stock starts at 0 — add it with Opening Stock or Goods Receipt.</p>

    <?php if (is_array($created)): ?>
        <div class="ps-alert ps-alert-success" role="status">"<?php echo $h($created['name']); ?>" created on <?php echo $h($created['branch']); ?><?php if ((int)($created['count'] ?? 1) > 1): ?> as <?php echo (int)$created['count']; ?> variants, Product IDs<?php else: ?> with Product ID<?php endif; ?> <strong><?php echo $h($created['id']); ?></strong>. Add the next one below.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
    <?php endif; ?>
    <?php if (!$categoryOptions || !$buttonCategoryOptions): ?>
        <div class="ps-alert ps-alert-warn" role="alert">Add at least one category and one button group first: <a href="product_categories.php">Categories &amp; Groups</a>.</div>
    <?php endif; ?>

    <div class="ps-card"><div class="ps-card-content">
    <form method="POST" action="" class="ci-form" autocomplete="off">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
        <input type="hidden" name="branch" value="<?php echo $h($branch); ?>">

        <section class="ci-group">
            <h2 class="ci-legend">Product</h2>
            <div class="ci-grid">
                <label class="ps-field ci-span" for="product"><span class="ps-label">Product name</span>
                    <input class="ps-input" type="text" id="product" name="product" required maxlength="250" value="<?php echo $h($product); ?>" placeholder="e.g. Coca-Cola 500ml"></label>
                <label class="ps-field ci-span" for="barcode"><span class="ps-label">Barcode <span class="ps-muted">(optional)</span></span>
                    <input class="ps-input" type="text" id="barcode" name="barcode" maxlength="64" inputmode="text" value="<?php echo $h($barcode); ?>" placeholder="Scan or type — leading zeros are kept"></label>
            </div>
        </section>

        <section class="ci-group">
            <h2 class="ci-legend">Price</h2>
            <div class="ci-grid">
                <label class="ps-field" for="price"><span class="ps-label">Selling price</span>
                    <input class="ps-input" type="number" step="0.01" min="0" id="price" name="price" required value="<?php echo $h($price); ?>"></label>
                <label class="ps-field" for="cost_price"><span class="ps-label">Cost price</span>
                    <input class="ps-input" type="number" step="0.01" min="0" id="cost_price" name="cost_price" required value="<?php echo $h($cost_price); ?>"></label>
                <p class="ci-help ci-span" id="ciMargin" aria-live="polite"></p>
                <label class="ps-field ci-span" for="tax_code"><span class="ps-label">Tax code</span>
                    <select class="ps-input" id="tax_code" name="tax_code">
                        <option value="">No tax code (company default)</option>
                        <?php foreach ($taxOptions as $t): ?><option value="<?php echo $h($t['code']); ?>"<?php echo $taxCode === $t['code'] ? ' selected' : ''; ?>><?php echo $h($t['code'] . ($t['description'] !== '' ? ' — ' . $t['description'] : '')); ?></option><?php endforeach; ?>
                    </select>
                    <span class="ci-help">Decides the VAT on receipts and on ZIMRA fiscal invoices. Codes come from Tax Rates.</span></label>
            </div>
        </section>

        <section class="ci-group">
            <h2 class="ci-legend">Grouping <a href="product_categories.php">Manage categories &amp; groups</a></h2>
            <div class="ci-grid">
                <label class="ps-field" for="category"><span class="ps-label">Category</span>
                    <select class="ps-input" id="category" name="category" required>
                        <option value="">Choose a category</option>
                        <?php foreach ($categoryOptions as $opt): ?><option value="<?php echo $h($opt); ?>"<?php echo strcasecmp($category, $opt) === 0 ? ' selected' : ''; ?>><?php echo $h($opt); ?></option><?php endforeach; ?>
                    </select>
                    <span class="ci-help">What the product is — used in reports.</span></label>
                <label class="ps-field" for="button_category"><span class="ps-label">Button group</span>
                    <select class="ps-input" id="button_category" name="button_category" required>
                        <option value="">Choose a button group</option>
                        <?php foreach ($buttonCategoryOptions as $opt): ?><option value="<?php echo $h($opt); ?>"<?php echo strcasecmp($button_category, $opt) === 0 ? ' selected' : ''; ?>><?php echo $h($opt); ?></option><?php endforeach; ?>
                    </select>
                    <span class="ci-help">The button the cashier taps on the till.</span></label>
            </div>
        </section>

        <section class="ci-group">
            <h2 class="ci-legend">Pack &amp; stock</h2>
            <div class="ci-grid">
                <div class="ps-field"><span class="ps-label" id="packLbl">Pack size</span>
                    <div class="ci-pack" role="group" aria-labelledby="packLbl">
                        <input class="ps-input" type="number" step="0.001" min="0.001" id="pack_size" name="pack_size" required value="<?php echo $h($pack_size); ?>" aria-label="Pack size amount">
                        <select class="ps-input" id="unit_of_measure" name="unit_of_measure" required aria-label="Unit">
                            <?php foreach ($unitGroups as $group => $list): ?>
                                <optgroup label="<?php echo $h($group); ?>">
                                    <?php foreach ($list as $u): ?><option value="<?php echo $h($u['code']); ?>"<?php echo $unit === (string)$u['code'] ? ' selected' : ''; ?>><?php echo $h($u['name'] . ' (' . $u['code'] . ')'); ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <span class="ci-help" id="packHelp">e.g. 500 + Millilitres, 2 + Kilograms, 1 + Each.</span>
                    <label class="ci-check"><input type="checkbox" name="sell_by_measure" id="sell_by_measure" value="1" <?php echo !empty($_POST['sell_by_measure']) ? 'checked' : ''; ?>>
                        <span>Sold by weight / volume / length — the cashier types the amount (e.g. 1.25 kg)</span></label></div>
                <label class="ps-field" for="reorder_level"><span class="ps-label">Reorder level <span class="ps-muted">(optional)</span></span>
                    <input class="ps-input" type="number" step="0.001" min="0" id="reorder_level" name="reorder_level" value="<?php echo $h($reorderRaw); ?>">
                    <span class="ci-help">Warn when stock falls to this quantity.</span></label>
            </div>
        </section>

        <section class="ci-group">
            <h2 class="ci-legend">Variants</h2>
            <label class="ci-check"><input type="checkbox" name="has_variants" id="has_variants" value="1" <?php echo !empty($_POST['has_variants']) ? 'checked' : ''; ?>>
                <span>This product comes in variants (sizes, colours …). One product is created for each combination, each with its own stock and barcode.</span></label>
            <div class="ci-grid" id="variantBox" style="margin-top:14px">
                <label class="ps-field" for="variant_values_1"><span class="ps-label">First option</span>
                    <input class="ps-input" id="variant_values_1" name="variant_values_1" value="<?php echo $h((string)($_POST['variant_values_1'] ?? '')); ?>" placeholder="Sizes, e.g. S, M, L, XL"></label>
                <label class="ps-field" for="variant_values_2"><span class="ps-label">Second option <span class="ps-muted">(optional)</span></span>
                    <input class="ps-input" id="variant_values_2" name="variant_values_2" value="<?php echo $h((string)($_POST['variant_values_2'] ?? '')); ?>" placeholder="Colours, e.g. Black, White"></label>
                <p class="ci-help ci-span" id="variantPreview" aria-live="polite"></p>
            </div>
        </section>

        <div class="ci-actions">
            <a class="ps-btn ps-btn-outline" href="items_list.php">Back to items</a>
            <button class="ps-btn ps-btn-default" type="submit">Create item</button>
        </div>
    </form>
    </div></div>
</div>
<script>
(function () {
    var p = document.getElementById('price'), c = document.getElementById('cost_price'), out = document.getElementById('ciMargin');
    function upd() {
        var pv = parseFloat(p.value), cv = parseFloat(c.value);
        if (!(pv > 0) || isNaN(cv)) { out.textContent = ''; return; }
        var m = pv - cv, pct = (m / pv) * 100;
        out.textContent = 'Margin: ' + m.toFixed(2) + ' per item (' + pct.toFixed(1) + '% of the selling price)' + (m < 0 ? ' — selling below cost.' : '.');
    }
    p.addEventListener('input', upd); c.addEventListener('input', upd); upd();

    // Sold by measure: price is per 1 unit, so the pack amount is fixed at 1.
    var meas = document.getElementById('sell_by_measure'), pack = document.getElementById('pack_size'),
        unitSel = document.getElementById('unit_of_measure'), help = document.getElementById('packHelp');
    var measurable = <?php echo json_encode(array_values(array_map(static fn ($u) => (string)$u['code'], array_filter($unitOptions, static fn ($u) => in_array($u['dimension'], ['weight', 'volume', 'length', 'area'], true))))); ?>;
    function syncMeasure() {
        var ok = measurable.indexOf(unitSel.value) !== -1;
        meas.disabled = !ok;
        if (!ok) meas.checked = false;
        if (meas.checked) {
            pack.value = '1'; pack.readOnly = true;
            var opt = unitSel.options[unitSel.selectedIndex];
            help.textContent = 'Selling price is per 1 ' + (opt ? opt.text : '') + '. The till asks for the amount.';
        } else {
            pack.readOnly = false;
            help.textContent = ok ? 'e.g. 500 + Millilitres, 2 + Kilograms, 1 + Each. Tick below to sell by an entered amount.' : 'e.g. 500 + Millilitres, 2 + Kilograms, 1 + Each.';
        }
    }
    meas.addEventListener('change', syncMeasure); unitSel.addEventListener('change', syncMeasure); syncMeasure();

    // Variants preview.
    var hv = document.getElementById('has_variants'), v1 = document.getElementById('variant_values_1'), v2 = document.getElementById('variant_values_2'),
        prev = document.getElementById('variantPreview'), form = hv.form, name = document.getElementById('product'), bc = document.getElementById('barcode');
    function vals(el) { var seen = {}; return el.value.split(/[,;\n]+/).map(function (x) { return x.trim(); }).filter(function (x) { var k = x.toLowerCase(); if (!x || seen[k]) return false; seen[k] = 1; return true; }); }
    function syncVariants() {
        form.classList.toggle('ci-variants-off', !hv.checked);
        bc.disabled = hv.checked;
        bc.placeholder = hv.checked ? 'Add each variant\'s barcode on the Items page' : 'Scan or type — leading zeros are kept';
        if (!hv.checked) { prev.textContent = ''; return; }
        var a = vals(v1), b = vals(v2), combos = [];
        (a.length ? a : ['']).forEach(function (x) { (b.length ? b : ['']).forEach(function (y) { var l = [x, y].filter(Boolean).join(' / '); if (l) combos.push(l); }); });
        if (!combos.length) { prev.textContent = 'Type the values, separated by commas.'; return; }
        var base = name.value.trim() || 'Product';
        prev.textContent = 'Will create ' + combos.length + ' product' + (combos.length === 1 ? '' : 's') + ': ' + combos.slice(0, 6).map(function (l) { return base + ' - ' + l; }).join(', ') + (combos.length > 6 ? ', …' : '') + (combos.length > 60 ? ' — too many (60 max).' : '');
    }
    [hv, v1, v2, name].forEach(function (el) { el.addEventListener('input', syncVariants); el.addEventListener('change', syncVariants); }); syncVariants();
})();
</script>
<?php ps_close(); ?>
</body>
</html>
