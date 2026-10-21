<?php
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/barcode.php';
require_once __DIR__ . '/includes/catalog_setup.php';

header('Content-Type: application/json; charset=utf-8');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load the catalogue.']);
    exit;
}

if (!psEnsureGoodsStatus($conn) || !psEnsureGoodsBarcodeSchema($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load the catalogue.']);
    exit;
}

$branchRequired = psRequireSessionProductBranch($conn);
if (!$branchRequired['ok']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'That branch is not available.']);
    exit;
}
$branch = (string)$branchRequired['branch'];
$company = trim((string)($_SESSION['the_company'] ?? ''));
$branchEsc = mysqli_real_escape_string($conn, $branch);
$companyEsc = mysqli_real_escape_string($conn, $company);
$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$statusActive = PS_PRODUCT_STATUS_ACTIVE;

$where = [
    "status = '" . mysqli_real_escape_string($conn, $statusActive) . "'",
    "branch = '$branchEsc'",
];
if ($company !== '') {
    // Health-check 2026-10-13: products created/imported without a company tag are
    // still the branch's products (create_items/import never wrote company_name).
    $where[] = "(company_name = '$companyEsc' OR company_name IS NULL OR company_name = '')";
}
if ($search !== '') {
    $searchLike = mysqli_real_escape_string($conn, $search);
    $searchLike = str_replace(['%', '_'], ['\\%', '\\_'], $searchLike);
    $where[] = psBarcodeSearchSqlClause($conn, $searchLike);
}
// POS button tabs use goods.button_category only (not goods.category).
if ($category !== '' && $search === '') {
    $catEsc = mysqli_real_escape_string($conn, $category);
    $where[] = "button_category = '$catEsc'";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

psCatalogEnsureSchema($conn);
$res = mysqli_query($conn, "SELECT product_id, product, price, button_category, category, quantity, pack_size, barcode, unit_of_measure, sell_by_measure, variant_group, variant_base, variant_label FROM goods $whereSql ORDER BY product, product_id LIMIT 600");
if (!$res) {
    $res = mysqli_query($conn, "SELECT product_id, product, price, button_category, category, quantity, pack_size, barcode FROM goods $whereSql ORDER BY product, product_id LIMIT 400");
}
if (!$res) {
    $res = mysqli_query($conn, "SELECT product_id, product, price, button_category, category, quantity, pack_size FROM goods $whereSql ORDER BY product, product_id LIMIT 400");
}
if (!$res) {
    $res = mysqli_query($conn, "SELECT product_id, product, price, button_category, category, quantity FROM goods $whereSql ORDER BY product, product_id LIMIT 400");
}
if (!$res) {
    $res = mysqli_query($conn, "SELECT product_id, product, price, button_category, category FROM goods $whereSql ORDER BY product, product_id LIMIT 400");
}

$products = [];
$categories = [];
$nameCounts = [];
$rawRows = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $rawRows[] = $row;
        $name = (string)$row['product'];
        $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
    }
}
foreach ($rawRows as $row) {
    // Button grouping label comes from button_category; empty falls back for display only.
    $cat = psNormalizeCategoryField($row['button_category'] ?? '');
    if ($cat === '') {
        $cat = psNormalizeCategoryField($row['category'] ?? '');
    }
    if ($cat === '') {
        $cat = 'General';
    }
    $categories[$cat] = true;
    $name = (string)$row['product'];
    $code = (string)$row['product_id'];
    $display = $name;
    if ($code !== '' && ($nameCounts[$name] ?? 0) > 1) {
        $display = psProductDisplayLabel(
            $name,
            $code,
            (string)($row['pack_size'] ?? ''),
            $row['price'] ?? null
        );
    }
    $products[] = [
        'product_id' => $code,
        'product' => $name,
        'display_name' => $display,
        'price' => (float)$row['price'],
        'category' => $cat,
        'quantity' => isset($row['quantity']) ? (float)$row['quantity'] : 0,
        'barcode' => isset($row['barcode']) ? (string)$row['barcode'] : '',
        'ledger_qty' => null,
        // Phase 20: sold by an entered amount (kg, litres ...) and variant grouping.
        'unit' => (string)($row['unit_of_measure'] ?? ''),
        'measure' => !empty($row['sell_by_measure']),
        'variant_group' => (string)($row['variant_group'] ?? ''),
        'variant_base' => (string)($row['variant_base'] ?? ''),
        'variant_label' => (string)($row['variant_label'] ?? ''),
    ];
}

if ($products && $branch !== '') {
    // One branch SUM. ledger_qty stays null until that product has an opening balance here.
    $ledger = psControlledLedgerBalances($conn, $branch);
    foreach ($products as $index => $product) {
        $productId = (string)$product['product_id'];
        if (array_key_exists($productId, $ledger)) {
            $products[$index]['ledger_qty'] = $ledger[$productId];
        }
    }
}

// Phase 20: button group order and colours from Categories & Groups (unknown groups after, A–Z).
$catNames = array_keys($categories);
$catColors = [];
$catOrder = [];
foreach (psCatList($conn, 'button', false) as $i => $c) {
    $catOrder[strtolower((string)$c['name'])] = [(int)$c['sort_order'], $i];
    if (!empty($c['color'])) {
        $catColors[(string)$c['name']] = (string)$c['color'];
    }
}
usort($catNames, static function ($a, $b) use ($catOrder) {
    $oa = $catOrder[strtolower($a)] ?? [PHP_INT_MAX, 0];
    $ob = $catOrder[strtolower($b)] ?? [PHP_INT_MAX, 0];
    return $oa <=> $ob ?: strcasecmp($a, $b);
});
echo json_encode([
    'success' => true,
    'branch' => $branch,
    'categories' => $catNames,
    'category_colors' => (object)$catColors,
    'products' => $products,
]);
