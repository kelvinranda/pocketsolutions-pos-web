<?php
/**
 * Phase 13 — POS barcode / product-code resolve (keyboard-wedge scanners).
 * Exact barcode, then exact product_id, on authorised working branch.
 * Same branch/company/ACTIVE scope as catalogue search. Adds nothing to stock;
 * returns catalogue identity for the existing cart / submit_sale2 path.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/barcode.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_packs.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Barcode lookup failed.', 'code' => 'db']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$branchRequired = psRequireSessionProductBranch($conn);
if (!$branchRequired['ok']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => (string)($branchRequired['error'] ?? 'That branch is not available.'),
        'code' => 'branch',
    ]);
    exit;
}
$branch = (string)$branchRequired['branch'];
$company = trim((string)($_SESSION['the_company'] ?? ''));
$code = (string)($_GET['code'] ?? $_POST['code'] ?? '');

$resolved = psResolveBarcodeForPos($conn, $code, $branch, $company);
// 2026-10-28: a pack's own barcode adds one pack of its product (same branch / company / ACTIVE rules for the product).
$packHit = null;
if (empty($resolved['ok']) && $company !== '' && ($pk = psPackByBarcode($conn, $company, trim($code))) !== null && $pk['price'] !== null) {
    $viaProduct = psResolveBarcodeForPos($conn, (string)$pk['product_id'], $branch, $company);
    if (!empty($viaProduct['ok'])) {
        $resolved = $viaProduct;
        $packHit = ['id' => $pk['id'], 'name' => $pk['name'], 'factor' => $pk['factor'], 'factor_milli' => $pk['factor_milli'], 'price' => (float)$pk['price']];
    }
}
if (empty($resolved['ok'])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => (string)($resolved['error'] ?? 'Unknown barcode.'),
        'code' => (string)($resolved['code'] ?? 'unknown'),
    ]);
    mysqli_close($conn);
    exit;
}

echo json_encode([
    'success' => true,
    'branch' => $branch,
    'product' => $resolved['product'],
    'pack' => $packHit,
]);
mysqli_close($conn);
