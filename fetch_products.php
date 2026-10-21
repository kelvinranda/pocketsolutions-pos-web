<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/product_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode([]);
    exit;
}

if (isset($_GET['term'])) {
    $term = trim((string)$_GET['term']);
    $branchCheck = psRequireSessionProductBranch($conn);
    $branch = $branchCheck['ok'] ? (string)$branchCheck['branch'] : '';
    $products = [];
    $rows = [];
    if ($term !== '' && $branch !== '') {
        $like = '%' . $term . '%';
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, cost_price, price, pack_size
             FROM goods
             WHERE branch = ? AND (product LIKE ? OR product_id LIKE ?)
             ORDER BY product, product_id
             LIMIT 20'
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
    }
    $nameCounts = [];
    foreach ($rows as $row) {
        $name = (string)$row['product'];
        $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
    }
    foreach ($rows as $row) {
        $name = (string)$row['product'];
        $code = trim((string)$row['product_id']);
        $entry = [
            'product_id' => $code,
            'product' => $name,
            'cost_price' => $row['cost_price'],
            'price' => $row['price'],
        ];
        if ($code !== '' && ($nameCounts[$name] ?? 0) > 1) {
            $entry['label'] = psProductDisplayLabel(
                $name,
                $code,
                (string)($row['pack_size'] ?? ''),
                $row['price'] ?? null
            );
        }
        $products[] = $entry;
    }
    echo json_encode($products);
    exit;
}

mysqli_close($conn);
