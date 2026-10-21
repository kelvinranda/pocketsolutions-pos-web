<?php
/**
 * GET ?branch=... (optional consistency check)
 * Catalog from `goods` — identity is product_id + authorised branch.
 * Phase 9.4: working branch from trusted mobile auth; client branch cannot override.
 * Inactive products excluded. Duplicate names stay separate.
 */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/product_master.php';

$ctx = mobileAuthRequire($conn);

$branchCheck = mobileAuthAcceptClientBranch($ctx, $_GET['branch'] ?? '');
if (empty($branchCheck['ok'])) {
    respond([
        'success' => false,
        'error' => (string)($branchCheck['error'] ?? 'That branch is not available.'),
    ], 403);
}
$branch = (string)$branchCheck['branch'];

if (!psEnsureGoodsStatus($conn)) {
    respond([
        'success' => false,
        'error' => 'Could not load products.',
        'branch' => $branch,
    ], 500);
}

$statusActive = PS_PRODUCT_STATUS_ACTIVE;
$stmt = mysqli_prepare(
    $conn,
    'SELECT product_id, product, cost_price, price, pack_size, branch
     FROM goods
     WHERE branch = ? AND status = ?
     ORDER BY product, product_id'
);
if (!$stmt) {
    respond([
        'success' => false,
        'error' => 'Could not load products.',
        'branch' => $branch,
    ], 500);
}
mysqli_stmt_bind_param($stmt, 'ss', $branch, $statusActive);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    respond([
        'success' => false,
        'error' => 'Could not load products.',
        'branch' => $branch,
    ], 500);
}
$res = mysqli_stmt_get_result($stmt);
if (!$res) {
    mysqli_stmt_close($stmt);
    respond([
        'success' => false,
        'error' => 'Could not load products.',
        'branch' => $branch,
    ], 500);
}

$nameCounts = [];
$rows = [];
while ($row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;
    $name = (string)$row['product'];
    $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
}
mysqli_stmt_close($stmt);

$products = [];
foreach ($rows as $row) {
    $name = (string)$row['product'];
    $code = (string)$row['product_id'];
    $entry = [
        'product_id' => $code,
        'product' => $name,
        'cost_price' => (float)$row['cost_price'],
        'price' => (float)$row['price'],
        'branch' => (string)$row['branch'],
    ];
    if ($code !== '' && ($nameCounts[$name] ?? 0) > 1) {
        $entry['display_name'] = $name . ' (' . $code . ')';
    }
    $products[] = $entry;
}

respond([
    'success' => true,
    'branch' => $branch,
    'count' => count($products),
    'products' => $products,
]);
