<?php
/**
 * Phase 5.2 — POS customer autocomplete against customer_master (ACTIVE only).
 * Global search: code / first / last / business / phone. Session required.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_master.php';

header('Content-Type: application/json; charset=utf-8');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not search customers.', 'rows' => []]);
    exit;
}

if (trim((string)($_SESSION['logger'] ?? '')) === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in again.', 'rows' => []]);
    mysqli_close($conn);
    exit;
}

$search = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$result = psSearchActiveCustomersForPos($conn, $search, 20);
if (!$result['ok']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not search customers.', 'rows' => []]);
    mysqli_close($conn);
    exit;
}

$rows = [];
foreach ($result['rows'] as $row) {
    $rows[] = [
        'id' => (int)$row['id'],
        'customer_code' => (string)$row['customer_code'],
        'display_name' => psCustomerDisplayName($row),
        'phone' => (string)($row['phone'] ?? ''),
        'customer_type' => (string)($row['customer_type'] ?? ''),
    ];
}

echo json_encode(['success' => true, 'rows' => $rows]);
mysqli_close($conn);
