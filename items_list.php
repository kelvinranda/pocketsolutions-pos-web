<?php
/**
 * Items (working branch).
 * 2026-10-24 — Large catalogues: filtering, sorting and paging happen in the database (one page of rows, named
 * columns, one COUNT), the page no longer renders an editable form per product, and editing happens in one
 * dialog that saves over AJAX (falls back to a normal post). Price / cost changes need a reason, are refused if the
 * product changed since the dialog opened, and are written to product_audit with the reason.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/role_permissions.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/barcode.php';
require_once __DIR__ . '/includes/catalog_setup.php';
require_once __DIR__ . '/includes/currency.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

psEnsureGoodsReorderLevel($conn);
psEnsureGoodsStatus($conn);
psEnsureGoodsBarcodeSchema($conn);
psEnsureGoodsListIndex($conn);
psEnsureTaxModule($conn);
psEnsureProductAuditSchema($conn);
$taxCodeOptions = psTaxListDistinctCodes($conn, false);

const PS_ITEMS_PAGE_SIZES = [25, 50, 100, 200];
const PS_ITEMS_SORTS = [
    'product' => 'product',
    'product_id' => 'product_id',
    'price' => 'CAST(price AS DECIMAL(14,2))',
    'category' => 'category',
    'status' => 'status',
];

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$selected_button_category = psNormalizeCategoryField($_GET['button_category'] ?? $_POST['button_category_filter'] ?? $_GET['category'] ?? '');
$statusFilterRaw = array_key_exists('status_filter', $_GET)
    ? (string)$_GET['status_filter']
    : (array_key_exists('status_filter', $_POST) ? (string)$_POST['status_filter'] : 'active');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'active', 'inactive'], true)) {
    $statusFilterKey = 'active';
}
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, PS_ITEMS_PAGE_SIZES, true)) {
    $perPage = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = (string)($_GET['sort'] ?? 'product');
if (!isset(PS_ITEMS_SORTS[$sort])) {
    $sort = 'product';
}
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

$items_data = [];
$update_error = '';
$update_notice = !empty($_GET['saved']) ? 'Item saved.' : '';
if (!empty($_GET['deleted'])) {
    $update_notice = substr(trim((string)$_GET['deleted']), 0, 400);
}

$buttonCategories = psProductButtonCategoryOptions($conn);
psCatalogEnsureSchema($conn);
$categoryList = psCatNames($conn, 'category');
$unitList = psUnitList($conn, true);
$unitCodes = array_map(static fn ($u) => (string)$u['code'], $unitList);
$baseCurrency = psFxBase($conn, trim((string)($_SESSION['the_company'] ?? '')));

$exportBranches = psAuthorisedProductBranches($conn);
$exportAllowAll = psSessionAllowsAllProductBranches();
$exportSelected = psSessionProductBranch();
if (!in_array($exportSelected, $exportBranches, true)) {
    $exportSelected = $exportBranches ? (string)$exportBranches[0] : '';
}

$sessionBranchCheck = psRequireSessionProductBranch($conn);
// 2026-11-02: company role permissions (admin always both).
$permItems = psRolePermSession('items_manage', $conn);
$permPrices = psRolePermSession('prices_edit', $conn);
$branch = $sessionBranchCheck['ok'] ? (string)$sessionBranchCheck['branch'] : '';

/** Query string of the current list view (filters, sort, page) with overrides. */
$listQuery = static function (array $over = []) use ($search, $selected_button_category, $statusFilterKey, $perPage, $page, $sort, $dir): string {
    $q = array_merge([
        'search' => $search, 'button_category' => $selected_button_category, 'status_filter' => $statusFilterKey,
        'per_page' => $perPage, 'page' => $page, 'sort' => $sort, 'dir' => $dir,
    ], $over);
    return http_build_query(array_filter($q, static fn ($v) => $v !== '' && $v !== null));
};

/** Row fields the list and the edit dialog need. */
function psItemsRowView(array $item): array
{
    $reorder = '';
    if (array_key_exists('reorder_level', $item) && $item['reorder_level'] !== null && trim((string)$item['reorder_level']) !== '') {
        $dec = psStockDecimal($item['reorder_level']);
        $reorder = $dec !== null ? psStockQtyPlain($dec) : '';
    }
    return [
        'id' => (int)$item['id'],
        'product_id' => (string)($item['product_id'] ?? ''),
        'product' => (string)($item['product'] ?? ''),
        'barcode' => (string)($item['barcode'] ?? ''),
        'price' => number_format((float)str_replace(',', '', (string)($item['price'] ?? '0')), 2, '.', ''),
        'cost_price' => number_format((float)str_replace(',', '', (string)($item['cost_price'] ?? '0')), 2, '.', ''),
        'margin' => number_format(psProductProfitMargin($item['price'] ?? 0, $item['cost_price'] ?? 0), 2),
        'category' => (string)($item['category'] ?? ''),
        'button_category' => (string)($item['button_category'] ?? ''),
        'unit_of_measure' => (string)($item['unit_of_measure'] ?? ''),
        'sell_by_measure' => !empty($item['sell_by_measure']) ? 1 : 0,
        'reorder_level' => $reorder,
        'pack_size' => (string)($item['pack_size'] ?? ''),
        'tax_code' => strtoupper(trim((string)($item['tax_code'] ?? ''))),
        'status' => strtoupper(trim((string)($item['status'] ?? PS_PRODUCT_STATUS_ACTIVE))),
    ];
}

/**
 * Save one product from the edit dialog. Same validation and audit as before, plus: the posted product_id must be
 * the row's, a price/cost change needs a reason, and a price/cost changed by someone else since the dialog
 * opened is refused rather than overwritten.
 *
 * @return array{ok:bool,error?:string,item?:array}
 */
function psItemsSaveUpdate(mysqli $conn, array $post, string $branch, array $sessionBranchCheck, array $unitCodes): array
{
    $item_id = (int)($post['item_id'] ?? 0);
    $product = trim((string)($post['product'] ?? ''));
    $priceRaw = $post['price'] ?? '';
    $costRaw = $post['cost_price'] ?? '';
    $category = psNormalizeCategoryField($post['category'] ?? '');
    $button_category = psNormalizeCategoryField($post['button_category'] ?? '');
    $unit_of_measure = (string)($post['unit_of_measure'] ?? '');
    $pack_size = (string)($post['pack_size'] ?? '');
    $reorderRaw = array_key_exists('reorder_level', $post) ? $post['reorder_level'] : null;
    $barcodeRaw = array_key_exists('barcode', $post) ? $post['barcode'] : '';
    $reason = trim((string)($post['price_reason'] ?? ''));

    $priceCheck = psValidateProductMoney($priceRaw, 'Selling price');
    $costCheck = psValidateProductMoney($costRaw, 'Cost price');

    if (!isset($_SESSION['logger']) || trim((string)$_SESSION['logger']) === '') {
        return ['ok' => false, 'error' => 'You must be signed in to change product prices.'];
    }
    if ($item_id < 1) {
        return ['ok' => false, 'error' => 'Product was not found.'];
    }
    if ($product === '') {
        return ['ok' => false, 'error' => 'Product name is required.'];
    }
    if (!$priceCheck['ok']) {
        return ['ok' => false, 'error' => $priceCheck['error']];
    }
    if (!$costCheck['ok']) {
        return ['ok' => false, 'error' => $costCheck['error']];
    }
    if ($category === '') {
        return ['ok' => false, 'error' => 'Category is required.'];
    }
    if ($button_category === '') {
        return ['ok' => false, 'error' => 'Button Category is required.'];
    }
    if (!$sessionBranchCheck['ok'] || $branch === '') {
        return ['ok' => false, 'error' => (string)($sessionBranchCheck['error'] ?? 'That branch is not available.')];
    }
    $existing = psGoodsRowById($conn, $item_id, $branch);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Product was not found on the selected branch.'];
    }
    if (isset($post['product_id']) && trim((string)$post['product_id']) !== trim((string)($existing['product_id'] ?? ''))) {
        return ['ok' => false, 'error' => 'This product changed while the dialog was open. Reload the page and try again.'];
    }
    // 2026-11-02: company role permissions. Prices only → every other field stays as it is; items only → prices stay.
    $mayItems = psRolePermSession('items_manage', $conn);
    $mayPrices = psRolePermSession('prices_edit', $conn);
    if (!$mayItems && !$mayPrices) {
        return ['ok' => false, 'error' => 'Your role may not change items. Ask an admin.'];
    }
    if (!$mayItems) {
        $product = (string)($existing['product'] ?? '');
        $category = psNormalizeCategoryField($existing['category'] ?? '');
        $button_category = psNormalizeCategoryField($existing['button_category'] ?? '');
        $unit_of_measure = (string)($existing['unit_of_measure'] ?? '');
        $pack_size = (string)($existing['pack_size'] ?? '');
        $reorderRaw = $existing['reorder_level'] ?? null;
        $barcodeRaw = $existing['barcode'] ?? '';
        unset($post['sell_by_measure']);
        $post['tax_code'] = (string)($existing['tax_code'] ?? '');
    }
    if (!$mayPrices) {
        $priceCheck = psValidateProductMoney((string)($existing['price'] ?? ''), 'Selling price');
        $costCheck = psValidateProductMoney((string)($existing['cost_price'] ?? ''), 'Cost price');
        if (!$priceCheck['ok'] || !$costCheck['ok']) {
            return ['ok' => false, 'error' => 'Your role may not change prices. Ask an admin.'];
        }
        $postedPrice = psValidateProductMoney($priceRaw, 'Selling price');
        $postedCost = psValidateProductMoney($costRaw, 'Cost price');
        if (($postedPrice['ok'] && psProductAuditNormalizeValue('price', $postedPrice['value']) !== psProductAuditNormalizeValue('price', $priceCheck['value']))
            || ($postedCost['ok'] && psProductAuditNormalizeValue('cost_price', $postedCost['value']) !== psProductAuditNormalizeValue('cost_price', $costCheck['value']))) {
            return ['ok' => false, 'error' => 'Your role may not change prices. Ask an admin.'];
        }
    }
    $priceBefore = psProductAuditNormalizeValue('price', $existing['price'] ?? null);
    $costBefore = psProductAuditNormalizeValue('cost_price', $existing['cost_price'] ?? null);
    foreach (['expected_price' => $priceBefore, 'expected_cost' => $costBefore] as $k => $now) {
        if (isset($post[$k]) && trim((string)$post[$k]) !== '' && psProductAuditNormalizeValue('price', $post[$k]) !== $now) {
            return ['ok' => false, 'error' => 'Someone changed this product\'s prices since you opened it (now ' . $priceBefore . ' / cost ' . $costBefore . '). Close the dialog, reload and check before saving.'];
        }
    }
    $priceChanged = psProductAuditNormalizeValue('price', $priceCheck['value']) !== $priceBefore
        || psProductAuditNormalizeValue('cost_price', $costCheck['value']) !== $costBefore;
    if ($priceChanged && strlen($reason) < 3) {
        return ['ok' => false, 'error' => 'Enter a reason for the price change (for the audit trail).'];
    }
    $reorderParsed = psParseReorderLevel($reorderRaw);
    $barcodeCheck = psValidateBarcodeForBranch($conn, $branch, $barcodeRaw, $item_id);
    if (!$reorderParsed['ok']) {
        return ['ok' => false, 'error' => $reorderParsed['error']];
    }
    if (!$barcodeCheck['ok']) {
        return ['ok' => false, 'error' => $barcodeCheck['error']];
    }
    if (psCatMatch($conn, 'category', $category) === '' && strcasecmp($category, trim((string)($existing['category'] ?? ''))) !== 0) {
        return ['ok' => false, 'error' => 'Choose a category from the list (manage them under Categories & Groups).'];
    }
    if (psCatMatch($conn, 'button', $button_category) === '' && strcasecmp($button_category, trim((string)($existing['button_category'] ?? ''))) !== 0) {
        return ['ok' => false, 'error' => 'Choose a button group from the list (manage them under Categories & Groups).'];
    }
    if (!in_array($unit_of_measure, $unitCodes, true) && $unit_of_measure !== (string)($existing['unit_of_measure'] ?? '')) {
        return ['ok' => false, 'error' => 'Choose a unit from the list.'];
    }
    if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', trim($pack_size)) && trim($pack_size) !== trim((string)($existing['pack_size'] ?? ''))) {
        return ['ok' => false, 'error' => 'Pack size must be a number, e.g. 500 or 1.5.'];
    }

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
        return ['ok' => false, 'error' => 'The item could not be saved.'];
    }
    $owns = !empty($tx['owns']);
    $reorderValue = $reorderParsed['value'] === null ? null : (string)$reorderParsed['value'];
    // Lock the row and re-check the prices inside the transaction so two editors cannot overwrite each other.
    $lock = mysqli_prepare($conn, 'SELECT price, cost_price FROM goods WHERE id = ? AND branch = ? FOR UPDATE');
    mysqli_stmt_bind_param($lock, 'is', $item_id, $branch);
    mysqli_stmt_execute($lock);
    $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    mysqli_stmt_close($lock);
    if (!$locked || psProductAuditNormalizeValue('price', $locked['price']) !== $priceBefore
        || psProductAuditNormalizeValue('cost_price', $locked['cost_price']) !== $costBefore) {
        psProductAuditRollback($conn, $owns);
        return ['ok' => false, 'error' => 'Someone changed this product while you were saving. Reload and try again.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE goods SET product = ?, price = ?, cost_price = ?, category = ?, button_category = ?, unit_of_measure = ?, pack_size = ?, reorder_level = ?, barcode = ?
         WHERE id = ? AND branch = ?'
    );
    $ok = false;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssssssssis', $product, $price, $cost_price, $category, $button_category, $unit_of_measure, $pack_size, $reorderValue, $barcodeParam, $item_id, $branch);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    $error = '';
    if ($ok && array_key_exists('sell_by_measure', $post)) {
        // Phase 20: sold by an entered amount (weight / volume / length units only).
        $measureNew = !empty($post['sell_by_measure']) && psUnitIsMeasurable($conn, $unit_of_measure) ? 1 : 0;
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
        $taxAssign = psTaxSetProductCode($conn, $item_id, $branch, array_key_exists('tax_code', $post) ? $post['tax_code'] : null, trim((string)($_SESSION['logger'] ?? '')));
        if (empty($taxAssign['ok'])) {
            $ok = false;
            $error = (string)($taxAssign['error'] ?? 'Tax code could not be saved.');
        }
    }
    if ($ok && psProductAuditRecordChanges($conn, [
        'product_id' => trim((string)($existing['product_id'] ?? '')),
        'goods_id' => $item_id,
        'branch' => $branch,
        'action' => PS_PRODUCT_AUDIT_ACTION_UPDATE,
        'source' => PS_PRODUCT_AUDIT_SOURCE_UI,
        'reason' => $reason,
    ], $auditChanges) && psProductAuditCommit($conn, $owns)) {
        return ['ok' => true, 'item' => psItemsRowView(psGoodsRowById($conn, $item_id, $branch) ?: $existing)];
    }
    psProductAuditRollback($conn, $owns);
    error_log('product update failed');
    return ['ok' => false, 'error' => $error !== '' ? $error : 'The item could not be saved.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $update_error = 'Invalid security token. Reload and try again.';
    } elseif (!psRolePermSession('items_manage', $conn)) {
        $update_error = 'Your role may not activate or deactivate items. Ask an admin.';
    } elseif (!isset($_SESSION['logger']) || trim((string)$_SESSION['logger']) === '') {
        $update_error = 'You must be signed in to change product status.';
    } elseif (!$sessionBranchCheck['ok']) {
        $update_error = (string)($sessionBranchCheck['error'] ?? 'That branch is not available.');
    } else {
        $statusResult = psSetGoodsStatusById($conn, (int)($_POST['item_id'] ?? 0), $branch, (string)($_POST['new_status'] ?? ''));
        if ($statusResult['ok']) {
            header('Location: items_list.php?' . $listQuery(['page' => (int)($_POST['page'] ?? 1)]));
            exit();
        }
        $update_error = (string)($statusResult['error'] ?? 'Product status could not be saved.');
    }
}

// 2026-10-31: Admin delete (removes an unused product; deactivates one with history).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    if (!psCsrfValidate()) {
        $update_error = 'Invalid security token. Reload and try again.';
    } elseif (!psBranchSessionIsAdmin()) {
        $update_error = 'Only an Admin can delete products.';
    } elseif (!$sessionBranchCheck['ok']) {
        $update_error = (string)($sessionBranchCheck['error'] ?? 'That branch is not available.');
    } else {
        $del = psDeleteGoodsById($conn, (int)($_POST['item_id'] ?? 0), $branch);
        if (!empty($del['ok'])) {
            $msg = $del['mode'] === 'deleted' ? 'Product deleted.' : (string)($del['reason'] ?? 'Product deactivated.');
            header('Location: items_list.php?' . $listQuery(['page' => (int)($_POST['page'] ?? 1), 'deleted' => $msg]));
            exit();
        }
        $update_error = (string)($del['error'] ?? 'Product could not be deleted.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $ajax = ($_POST['ajax'] ?? '') === '1';
    $result = psCsrfValidate()
        ? psItemsSaveUpdate($conn, $_POST, $branch, $sessionBranchCheck, $unitCodes)
        : ['ok' => false, 'error' => 'Invalid security token. Reload and try again.'];
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        mysqli_close($conn);
        exit;
    }
    if (!empty($result['ok'])) {
        header('Location: items_list.php?saved=1&' . $listQuery(['page' => (int)($_POST['page'] ?? 1)]));
        exit();
    }
    $update_error = (string)$result['error'];
}

// List: one page, needed columns only, filtered / sorted / counted in SQL.
$where = [];
if ($branch !== '') {
    $where[] = "branch = '" . mysqli_real_escape_string($conn, $branch) . "'";
} else {
    $where[] = '1 = 0';
}
if ($search !== '') {
    $searchLike = mysqli_real_escape_string($conn, $search);
    $searchLike = str_replace(['%', '_'], ['\\%', '\\_'], $searchLike);
    $where[] = psBarcodeSearchSqlClause($conn, $searchLike);
}
if ($selected_button_category !== '') {
    $where[] = "button_category = '" . mysqli_real_escape_string($conn, $selected_button_category) . "'";
}
if ($statusFilterKey === 'active') {
    $where[] = "status = '" . mysqli_real_escape_string($conn, PS_PRODUCT_STATUS_ACTIVE) . "'";
} elseif ($statusFilterKey === 'inactive') {
    $where[] = "status = '" . mysqli_real_escape_string($conn, PS_PRODUCT_STATUS_INACTIVE) . "'";
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$total = 0;
$countRes = mysqli_query($conn, 'SELECT COUNT(*) AS n FROM goods' . $whereSql);
if ($countRes && ($cr = mysqli_fetch_assoc($countRes))) {
    $total = (int)$cr['n'];
}
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$orderSql = PS_ITEMS_SORTS[$sort] . ' ' . strtoupper($dir) . ', id ' . strtoupper($dir);
$result = mysqli_query($conn, 'SELECT id, product_id, product, barcode, price, cost_price, category, button_category, unit_of_measure,
        sell_by_measure, reorder_level, pack_size, tax_code, status
    FROM goods' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $items_data[] = psItemsRowView($row);
    }
} else {
    $update_error = $update_error !== '' ? $update_error : 'The items could not be loaded.';
}

mysqli_close($conn);
require_once __DIR__ . '/includes/ps_layout.php';
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$sortLink = static function (string $key, string $label) use ($sort, $dir, $listQuery, $h): string {
    $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="items_list.php?' . $h($listQuery(['sort' => $key, 'dir' => $next, 'page' => 1])) . '">' . $h($label) . $arrow . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items List</title>
    <?php ps_head(); ?>
    <style>
        /* 2026-10-31: keep the Edit + ⋯ actions visible (the ⋯ menu holds Deactivate / Delete). */
        html[data-theme] body.ps-app .ps-items-table { overflow: visible; }
        html[data-theme] body.ps-app .ps-items-table .col-actions { width: auto; min-width: 8.5rem; position: sticky; right: 0; z-index: 2; background: var(--ps-card); box-shadow: -8px 0 8px -8px rgba(0,0,0,.45); }
        html[data-theme] body.ps-app .ps-items-table td.col-actions:focus-within { z-index: 5; }
        html[data-theme] body.ps-app .ps-items-table td.col-actions .ps-action-menu { display: inline-block; vertical-align: middle; }
        .il-confirm { max-width: 28rem; }
        #itemDialog .il-locked { pointer-events: none; opacity: .55; }
        #itemDialog input[readonly] { opacity: .55; }
        .il-confirm-text { margin: .25rem 0 1rem; color: var(--ps-muted, inherit); line-height: 1.5; }
        .il-pager { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; padding: .75rem 1rem; }
        .il-pager .ps-field-hint { margin: 0 auto 0 0; }
        .il-edit-head { display: grid; grid-template-columns: 1fr auto; gap: .25rem .75rem; margin: .5rem 0 .75rem; }
        .il-price-box { border: 1px solid var(--ps-line); border-radius: 12px; padding: .75rem; margin-bottom: .75rem; }
        .il-cur { font-size: .75rem; color: var(--ps-muted); }
        .il-check { display: flex; gap: .4rem; align-items: center; font-size: .85rem; }
        .ps-items-table td[data-item-edit] { cursor: pointer; }
        @media (max-width: 560px) {
            .ps-dialog#itemDialog { width: 100vw; max-height: 100vh; border-radius: 0; margin: 0; }
        }
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>

<h1>Items</h1>
<p class="ps-page-lead">Edit products for the working branch<?php echo $branch !== '' ? ' (' . $h($branch) . ')' : ''; ?>. Inactive items stay for history and are hidden from POS.</p>

<div id="itemsNotice" class="ps-alert ps-alert-success" role="status"<?php echo $update_notice === '' ? ' hidden' : ''; ?>><?php echo $h($update_notice); ?></div>
<?php if ($update_error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($update_error); ?></div>
<?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Search the whole catalogue by name, product ID, or barcode.</p>
    </div>
    <div class="ps-card-content">
        <form method="GET" action="items_list.php" class="ps-form-inline filter-form" id="itemsFilter">
            <input class="ps-input" type="search" name="search" id="itemsSearch" placeholder="Search by name, Product ID, or barcode" value="<?php echo $h($search); ?>" autocomplete="off">
            <select class="ps-input" name="button_category" onchange="this.form.submit()">
                <option value="">All Button Categories</option>
                <?php foreach ($buttonCategories as $btnCat): ?>
                    <option value="<?php echo $h($btnCat); ?>" <?php echo $selected_button_category === (string)$btnCat ? 'selected' : ''; ?>><?php echo $h($btnCat); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="ps-input" name="status_filter" onchange="this.form.submit()">
                <option value="all"<?php echo $statusFilterKey === 'all' ? ' selected' : ''; ?>>All statuses</option>
                <option value="active"<?php echo $statusFilterKey === 'active' ? ' selected' : ''; ?>>Active</option>
                <option value="inactive"<?php echo $statusFilterKey === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
            </select>
            <select class="ps-input" name="per_page" onchange="this.form.submit()" aria-label="Items per page">
                <?php foreach (PS_ITEMS_PAGE_SIZES as $n): ?>
                    <option value="<?php echo $n; ?>"<?php echo $perPage === $n ? ' selected' : ''; ?>><?php echo $n; ?> per page</option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="sort" value="<?php echo $h($sort); ?>">
            <input type="hidden" name="dir" value="<?php echo $h($dir); ?>">
            <button class="ps-btn ps-btn-default" type="submit">Search</button>
        </form>
    </div>
</div>

<div class="ps-items-toolbar ps-link-row" aria-label="Item actions">
    <?php if ($permItems): ?>
    <a class="ps-btn ps-btn-default ps-btn-sm" href="create_items.php">Create item</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="barcode_labels.php">Barcode labels</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="import_items.php">Import</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="product_audit_history.php">Product audit</a>
    <?php endif; ?>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="generate_items_pdf.php">Print</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="admin_dashboard.php">Dashboard</a>
    <form class="export-form" method="post" action="export.php">
        <?php echo psCsrfField(); ?>
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
        <p class="ps-card-desc"><?php echo number_format($total); ?> item<?php echo $total === 1 ? '' : 's'; ?><?php echo $search !== '' ? ' matching “' . $h($search) . '”' : ''; ?>. Prices in <?php echo $h($baseCurrency); ?>. Use Edit to change a product.</p>
    </div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table ps-items-table">
                <thead>
                    <tr>
                        <th class="col-id"><?php echo $sortLink('product_id', 'Product ID'); ?></th>
                        <th class="col-product"><?php echo $sortLink('product', 'Product'); ?></th>
                        <th class="col-barcode">Barcode</th>
                        <th class="col-money num"><?php echo $sortLink('price', 'Price'); ?></th>
                        <th class="col-money num">Cost</th>
                        <th class="col-money num">Margin</th>
                        <th class="col-cat"><?php echo $sortLink('category', 'Category'); ?></th>
                        <th class="col-cat">Button category</th>
                        <th>UoM</th>
                        <th>Reorder</th>
                        <th>Pack</th>
                        <th>Tax</th>
                        <th class="col-status"><?php echo $sortLink('status', 'Status'); ?></th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items_data)): ?>
                    <tr><td colspan="14" class="ps-muted">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items_data as $item): ?>
                        <?php $isActive = $item['status'] === PS_PRODUCT_STATUS_ACTIVE; ?>
                        <tr data-ps-product="<?php echo $h($item['product_id'] . ' ' . $item['product'] . ' ' . $item['barcode']); ?>" data-item-row="<?php echo (int)$item['id']; ?>" data-item="<?php echo $h(json_encode($item)); ?>">
                            <td class="col-id"><span class="ps-cell-clip" title="<?php echo $h($item['product_id']); ?>"><?php echo $h($item['product_id']); ?></span></td>
                            <td class="col-product" data-f="product" data-item-edit="<?php echo (int)$item['id']; ?>"><?php echo $h($item['product']); ?></td>
                            <td class="col-barcode" data-f="barcode"><?php echo $h($item['barcode']); ?></td>
                            <td class="col-money num" data-f="price"><?php echo $h($item['price']); ?></td>
                            <td class="col-money num" data-f="cost_price"><?php echo $h($item['cost_price']); ?></td>
                            <td class="col-money num" data-f="margin"><?php echo $h($item['margin']); ?></td>
                            <td class="col-cat" data-f="category"><?php echo $h($item['category']); ?></td>
                            <td class="col-cat" data-f="button_category"><?php echo $h($item['button_category']); ?></td>
                            <td data-f="unit_of_measure"><?php echo $h($item['unit_of_measure']); ?><?php echo $item['sell_by_measure'] ? ' <span class="ps-muted">(by amount)</span>' : ''; ?></td>
                            <td data-f="reorder_level"><?php echo $h($item['reorder_level']); ?></td>
                            <td data-f="pack_size"><?php echo $h($item['pack_size']); ?></td>
                            <td data-f="tax_code"><?php echo $h($item['tax_code'] !== '' ? $item['tax_code'] : '—'); ?></td>
                            <td class="col-status">
                                <span class="ps-badge <?php echo $isActive ? 'ps-badge-success' : 'ps-badge-secondary'; ?>"><?php echo $h(psProductStatusLabel($item['status'])); ?></span>
                            </td>
                            <td class="col-actions nowrap">
                                <button type="button" class="ps-btn ps-btn-outline ps-btn-sm" data-item-edit="<?php echo (int)$item['id']; ?>">Edit</button>
                                <div class="ps-action-menu">
                                    <button type="button" class="ps-btn ps-btn-ghost ps-btn-sm ps-btn-icon" data-ps-action-toggle aria-haspopup="menu" aria-expanded="false" aria-label="More actions">⋯</button>
                                    <div class="ps-action-menu-panel" role="menu" hidden>
                                        <?php if ($permItems): ?>
                                        <form method="POST" action="items_list.php?<?php echo $h($listQuery()); ?>"<?php if ($isActive): ?> data-confirm data-confirm-title="Deactivate <?php echo $h($item['product']); ?>?" data-confirm-text="It will no longer appear on the POS for new sales. Sales history and stock records are kept, and you can activate it again later." data-confirm-ok="Deactivate"<?php endif; ?>>
                                            <?php echo psCsrfField(); ?>
                                            <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $h($isActive ? PS_PRODUCT_STATUS_INACTIVE : PS_PRODUCT_STATUS_ACTIVE); ?>">
                                            <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
                                            <button type="submit" name="set_status" value="1" role="menuitem"<?php echo $isActive ? ' class="is-danger"' : ''; ?>><?php echo $isActive ? 'Deactivate' : 'Activate'; ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if (psBranchSessionIsAdmin()): ?>
                                        <form method="POST" action="items_list.php?<?php echo $h($listQuery()); ?>" data-confirm data-confirm-title="Delete <?php echo $h($item['product']); ?>?" data-confirm-text="If this product has never been sold, received or moved, it is removed for good. If it has history, it is deactivated instead so your sales and stock records stay correct." data-confirm-ok="Delete">
                                            <?php echo psCsrfField(); ?>
                                            <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
                                            <button type="submit" name="delete_item" value="1" role="menuitem" class="is-danger">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                        <a role="menuitem" href="product_history.php?product_id=<?php echo rawurlencode($item['product_id']); ?>">History</a>
                                        <a role="menuitem" href="product_audit_history.php?goods_id=<?php echo (int)$item['id']; ?>">Audit</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav class="il-pager" aria-label="Pages">
            <p class="ps-field-hint">
                <?php if ($total > 0): ?>Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $perPage, $total)); ?> of <?php echo number_format($total); ?><?php endif; ?>
            </p>
            <?php if ($page > 1): ?>
                <a class="ps-btn ps-btn-outline ps-btn-sm" href="items_list.php?<?php echo $h($listQuery(['page' => 1])); ?>">First</a>
                <a class="ps-btn ps-btn-outline ps-btn-sm" href="items_list.php?<?php echo $h($listQuery(['page' => $page - 1])); ?>" rel="prev">Previous</a>
            <?php endif; ?>
            <span class="ps-field-hint" style="margin:0">Page <?php echo (int)$page; ?> of <?php echo (int)$pages; ?></span>
            <?php if ($page < $pages): ?>
                <a class="ps-btn ps-btn-outline ps-btn-sm" href="items_list.php?<?php echo $h($listQuery(['page' => $page + 1])); ?>" rel="next">Next</a>
                <a class="ps-btn ps-btn-outline ps-btn-sm" href="items_list.php?<?php echo $h($listQuery(['page' => $pages])); ?>">Last</a>
            <?php endif; ?>
        </nav>
    </div>
</div>

<dialog class="ps-dialog" id="itemDialog" aria-labelledby="itemDialogTitle" data-perm-items="<?php echo $permItems ? '1' : '0'; ?>" data-perm-prices="<?php echo $permPrices ? '1' : '0'; ?>">
    <form method="POST" action="items_list.php?<?php echo $h($listQuery()); ?>" id="itemForm" class="ps-form-stack" autocomplete="off" novalidate>
        <?php echo psCsrfField(); ?>
        <input type="hidden" name="update" value="1">
        <input type="hidden" name="item_id">
        <input type="hidden" name="product_id">
        <input type="hidden" name="expected_price">
        <input type="hidden" name="expected_cost">
        <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
        <div class="ps-dialog-head">
            <h2 class="ps-dialog-title" id="itemDialogTitle">Edit item</h2>
            <button class="ps-btn ps-btn-ghost ps-btn-sm ps-btn-icon" type="button" data-item-close aria-label="Close">&times;</button>
        </div>
        <div class="il-edit-head">
            <strong data-show="product">—</strong>
            <span class="ps-badge ps-badge-secondary" data-show="status"></span>
            <span class="ps-field-hint" style="margin:0">Product ID <span data-show="product_id"></span> · Branch <?php echo $h($branch); ?></span>
        </div>
        <div class="ps-alert ps-alert-destructive" role="alert" id="itemError" hidden></div>

        <div class="il-price-box">
            <div class="ps-field-hint" style="margin:0 0 .5rem">Current: price <strong data-show="price"></strong> · cost <strong data-show="cost_price"></strong> <span class="il-cur"><?php echo $h($baseCurrency); ?></span></div>
            <div class="ps-dialog-grid">
                <div class="ps-field">
                    <label class="ps-label" for="ie_price">Selling price (<?php echo $h($baseCurrency); ?>)</label>
                    <input class="ps-input" id="ie_price" name="price" type="text" inputmode="decimal" required>
                </div>
                <div class="ps-field">
                    <label class="ps-label" for="ie_cost">Cost price (<?php echo $h($baseCurrency); ?>)</label>
                    <input class="ps-input" id="ie_cost" name="cost_price" type="text" inputmode="decimal" required>
                </div>
            </div>
            <p class="ps-field-hint" id="ie_margin"></p>
            <div class="ps-field" id="ie_reason_wrap" hidden>
                <label class="ps-label" for="ie_reason">Reason for the price change (required)</label>
                <input class="ps-input" id="ie_reason" name="price_reason" maxlength="250" placeholder="e.g. Supplier price increase">
            </div>
        </div>

        <div class="ps-dialog-grid">
            <div class="ps-field">
                <label class="ps-label" for="ie_product">Product name</label>
                <input class="ps-input" id="ie_product" name="product" maxlength="250" required>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_barcode">Barcode</label>
                <input class="ps-input" id="ie_barcode" name="barcode" maxlength="64" placeholder="optional">
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_category">Category</label>
                <select class="ps-input" id="ie_category" name="category">
                    <?php foreach ($categoryList as $c): ?><option value="<?php echo $h($c); ?>"><?php echo $h($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_button">Button category</label>
                <select class="ps-input" id="ie_button" name="button_category">
                    <?php foreach ($buttonCategories as $c): ?><option value="<?php echo $h($c); ?>"><?php echo $h($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_unit">Unit</label>
                <select class="ps-input" id="ie_unit" name="unit_of_measure">
                    <?php foreach ($unitList as $u): ?><option value="<?php echo $h($u['code']); ?>"><?php echo $h($u['code']); ?></option><?php endforeach; ?>
                </select>
                <input type="hidden" name="sell_by_measure" value="0">
                <label class="il-check" title="Sold by an entered amount (e.g. 1.25 kg) — weight, volume or length units only"><input type="checkbox" id="ie_measure" name="sell_by_measure" value="1"> Sold by amount</label>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_tax">Tax code</label>
                <select class="ps-input" id="ie_tax" name="tax_code">
                    <option value="">— none —</option>
                    <?php foreach ($taxCodeOptions as $tc): ?><option value="<?php echo $h($tc['code']); ?>"><?php echo $h($tc['code']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_reorder">Reorder level</label>
                <input class="ps-input" id="ie_reorder" name="reorder_level" type="text" inputmode="decimal">
            </div>
            <div class="ps-field">
                <label class="ps-label" for="ie_pack">Pack size</label>
                <input class="ps-input" id="ie_pack" name="pack_size" type="text">
            </div>
        </div>
        <div class="ps-dialog-actions">
            <button class="ps-btn ps-btn-outline" type="button" data-item-close>Cancel</button>
            <button class="ps-btn ps-btn-primary" type="submit" id="itemSave">Save</button>
        </div>
    </form>
</dialog>

<dialog class="ps-dialog il-confirm" id="confirmDialog" aria-labelledby="confirmTitle">
    <div class="ps-dialog-head">
        <h2 class="ps-dialog-title" id="confirmTitle">Are you sure?</h2>
        <button class="ps-btn ps-btn-ghost ps-btn-sm ps-btn-icon" type="button" data-confirm-cancel aria-label="Close">&times;</button>
    </div>
    <p class="il-confirm-text" id="confirmText"></p>
    <div class="ps-dialog-actions">
        <button class="ps-btn ps-btn-outline" type="button" data-confirm-cancel>Cancel</button>
        <button class="ps-btn ps-btn-destructive" type="button" id="confirmOk">Confirm</button>
    </div>
</dialog>

<script>
(function () {
    var dlg = document.getElementById('itemDialog');
    var form = document.getElementById('itemForm');
    var err = document.getElementById('itemError');
    var saveBtn = document.getElementById('itemSave');
    var current = null, dirty = false, busy = false;
    function cents(v) { var m = /^\s*(\d{1,9})(?:\.(\d{1,2}))?\s*$/.exec(String(v)); return m ? parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10) : null; }
    function ensureOption(sel, value) {
        if (value === '' || Array.prototype.some.call(sel.options, function (o) { return o.value === value; })) return;
        var o = document.createElement('option'); o.value = value; o.textContent = value; o.dataset.extra = '1'; sel.appendChild(o);
    }
    function show(name, v) { dlg.querySelectorAll('[data-show="' + name + '"]').forEach(function (el) { el.textContent = v; }); }
    function priceChanged() {
        return current && (cents(form.price.value) !== cents(current.price) || cents(form.cost_price.value) !== cents(current.cost_price));
    }
    function syncPrice() {
        var p = cents(form.price.value), c = cents(form.cost_price.value);
        document.getElementById('ie_margin').textContent = (p !== null && c !== null && p > 0)
            ? 'Margin after change: ' + ((p - c) / p * 100).toFixed(2) + '%' : '';
        var changed = priceChanged();
        document.getElementById('ie_reason_wrap').hidden = !changed;
        form.price_reason.required = changed;
    }
    function open(item) {
        current = item; dirty = false; err.hidden = true;
        dlg.querySelectorAll('option[data-extra]').forEach(function (o) { o.remove(); });
        form.item_id.value = item.id; form.product_id.value = item.product_id;
        form.expected_price.value = item.price; form.expected_cost.value = item.cost_price;
        form.product.value = item.product; form.barcode.value = item.barcode;
        form.price.value = item.price; form.cost_price.value = item.cost_price; form.price_reason.value = '';
        [['category', 'ie_category'], ['button_category', 'ie_button'], ['unit_of_measure', 'ie_unit'], ['tax_code', 'ie_tax']].forEach(function (p) {
            var sel = document.getElementById(p[1]); ensureOption(sel, item[p[0]]); sel.value = item[p[0]];
        });
        document.getElementById('ie_measure').checked = !!item.sell_by_measure;
        form.reorder_level.value = item.reorder_level; form.pack_size.value = item.pack_size;
        ['product', 'product_id', 'price', 'cost_price'].forEach(function (k) { show(k, item[k]); });
        show('status', item.status === 'ACTIVE' ? 'Active' : 'Inactive');
        syncPrice();
        // 2026-11-02: role permissions — lock what this role may not change (the server enforces it too).
        var mayItems = dlg.dataset.permItems === '1', mayPrices = dlg.dataset.permPrices === '1';
        form.querySelectorAll('input, select').forEach(function (el) {
            if (el.type === 'hidden') return;
            var isPrice = el === form.price || el === form.cost_price || el === form.price_reason;
            var lock = isPrice ? !mayPrices : !mayItems;
            if (el.tagName === 'SELECT' || el.type === 'checkbox') { el.classList.toggle('il-locked', lock); el.tabIndex = lock ? -1 : 0; }
            else { el.readOnly = lock; }
        });
        if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
        if (mayPrices) { form.price.focus(); form.price.select(); } else { form.product.focus(); }
    }
    // 2026-10-31: in-app confirm (the page's confirm box) instead of the browser's "localhost says".
    function askConfirm(title, text, okLabel, danger) {
        return new Promise(function (resolve) {
            var d = document.getElementById('confirmDialog');
            if (!d || typeof d.showModal !== 'function') { resolve(window.confirm(text)); return; }
            var ok = document.getElementById('confirmOk');
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmText').textContent = text;
            ok.textContent = okLabel;
            ok.className = 'ps-btn ' + (danger ? 'ps-btn-destructive' : 'ps-btn-primary');
            ok.disabled = false;
            var done = false;
            function finish(v) {
                if (done) return;
                done = true;
                ok.removeEventListener('click', onOk);
                d.removeEventListener('close', onClose);
                if (d.open) d.close();
                ok.className = 'ps-btn ps-btn-destructive';
                resolve(v);
            }
            function onOk() { finish(true); }
            function onClose() { finish(false); }
            ok.addEventListener('click', onOk);
            d.addEventListener('close', onClose);
            d.showModal();
            ok.focus();
        });
    }
    function close(force) {
        if (busy) return;
        if (!force && dirty) {
            askConfirm('Discard your changes?', 'Your changes to ' + (current ? current.product : 'this item') + ' have not been saved.', 'Discard changes', true)
                .then(function (yes) { if (yes) { dirty = false; close(true); } });
            return;
        }
        if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open');
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-item-edit]');
        if (b) { var row = b.closest('tr'); open(JSON.parse(row.dataset.item)); return; }
        if (e.target.closest('[data-item-close]')) close(false);
    });
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });
    form.addEventListener('input', function (e) { dirty = true; if (e.target === form.price || e.target === form.cost_price) syncPrice(); });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        if (cents(form.price.value) === null || cents(form.cost_price.value) === null) { err.textContent = 'Prices must be amounts like 12.50.'; err.hidden = false; return; }
        if (priceChanged() && form.price_reason.value.trim().length < 3) { err.textContent = 'Enter a reason for the price change.'; err.hidden = false; form.price_reason.focus(); return; }
        if (priceChanged()) {
            askConfirm('Change the price?', current.product + ': price ' + current.price + ' → ' + form.price.value.trim()
                + ', cost ' + current.cost_price + ' → ' + form.cost_price.value.trim() + '.', 'Change price', false)
                .then(function (yes) { if (yes) save(); });
            return;
        }
        save();
    });
    function save() {
        busy = true; saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; err.hidden = true;
        var data = new FormData(form); data.append('ajax', '1');
        fetch(form.action, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false; saveBtn.disabled = false; saveBtn.textContent = 'Save';
                if (!d || !d.ok) { err.textContent = (d && d.error) || 'The item could not be saved.'; err.hidden = false; return; }
                var row = document.querySelector('tr[data-item-row="' + d.item.id + '"]');
                if (row) {
                    row.dataset.item = JSON.stringify(d.item);
                    row.querySelectorAll('[data-f]').forEach(function (td) {
                        var f = td.dataset.f, v = d.item[f];
                        td.textContent = f === 'tax_code' ? (v || '—') : (f === 'unit_of_measure' && d.item.sell_by_measure ? v + ' (by amount)' : v);                    });
                    row.dataset.psProduct = d.item.product_id + ' ' + d.item.product + ' ' + d.item.barcode;
                }
                var n = document.getElementById('itemsNotice'); n.textContent = d.item.product + ' saved.'; n.hidden = false;
                dirty = false; close(true);
            })
            .catch(function () {
                busy = false; saveBtn.disabled = false; saveBtn.textContent = 'Save';
                err.textContent = 'The item could not be saved. Check the connection; nothing was changed unless the list shows the new values after a reload.'; err.hidden = false;
            });
    }
    // Debounced search across the whole catalogue (server-side).
    var search = document.getElementById('itemsSearch'), timer = null, last = search.value;
    if (last !== '') { search.focus(); search.setSelectionRange(last.length, last.length); }
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var v = search.value.trim();
            if (v === last.trim() || (v.length > 0 && v.length < 2)) return;
            last = v; document.getElementById('itemsFilter').submit();
        }, 450);
    });
})();

/* 2026-10-31: in-app confirm for Deactivate / Delete instead of the browser's "localhost says" box. */
(function () {
    var dlg = document.getElementById('confirmDialog');
    if (!dlg || typeof dlg.showModal !== 'function') return; // very old browser: forms submit without a prompt
    var title = document.getElementById('confirmTitle');
    var text = document.getElementById('confirmText');
    var ok = document.getElementById('confirmOk');
    var pending = null;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;
        e.preventDefault();
        var btn = e.submitter || form.querySelector('button[type=submit]');
        pending = { form: form, name: btn && btn.name, value: btn && btn.value };
        title.textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
        text.textContent = form.getAttribute('data-confirm-text') || '';
        ok.textContent = form.getAttribute('data-confirm-ok') || 'Confirm';
        ok.disabled = false;
        dlg.showModal();
        ok.focus();
    }, true);
    function close() { pending = null; if (dlg.open) dlg.close(); }
    Array.prototype.forEach.call(dlg.querySelectorAll('[data-confirm-cancel]'), function (b) { b.addEventListener('click', close); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    ok.addEventListener('click', function () {
        if (!pending) return;
        var p = pending;
        ok.disabled = true;
        p.form.dataset.confirmed = '1';
        if (p.name) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = p.name; h.value = p.value || '1';
            p.form.appendChild(h);
        }
        p.form.submit();
    });
})();
</script>
<?php ps_close(); ?>
</body>
</html>
