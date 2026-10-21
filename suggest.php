<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/product_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode([]);
    exit;
}

$term = trim((string)($_GET['term'] ?? ''));
$branchCheck = psRequireSessionProductBranch($conn);
$branch = $branchCheck['ok'] ? (string)$branchCheck['branch'] : '';
$suggestions = [];
$rows = [];

if ($term === '' || $branch === '') {
    echo json_encode([]);
    mysqli_close($conn);
    exit;
}

$like = '%' . $term . '%';
$stmt = mysqli_prepare(
    $conn,
    'SELECT product_id, product, branch, pack_size, price
     FROM goods
     WHERE branch = ? AND (product LIKE ? OR product_id LIKE ?)
     ORDER BY product, product_id
     LIMIT 40'
);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'sss', $branch, $like, $like);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$name_counts = [];
foreach ($rows as $row) {
    $name = (string)$row['product'];
    $name_counts[$name] = ($name_counts[$name] ?? 0) + 1;
}

foreach ($rows as $row) {
    $name = (string)$row['product'];
    $code = trim((string)$row['product_id']);
    $label = $name;
    if ($code !== '' && ($name_counts[$name] ?? 0) > 1) {
        $label = psProductDisplayLabel(
            $name,
            $code,
            (string)($row['pack_size'] ?? ''),
            $row['price'] ?? null
        );
    }
    $suggestions[] = [
        'product_id' => $code,
        'product' => $name,
        'branch' => (string)($row['branch'] ?? ''),
        'label' => $label,
    ];
}

echo json_encode($suggestions);
mysqli_close($conn);
