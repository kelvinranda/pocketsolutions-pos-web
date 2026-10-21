<?php
/**
 * 2026-10-18 — Add a customer from the POS (JSON).
 * Supervisor, Management, Admin always; Till users when the company allows it (Customers page switch).
 * Same rules as customers_list.php (psCreateCustomer: names required, duplicate phone / email checks, audit).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_master.php';
require_once __DIR__ . '/includes/cash_controls.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'The customer could not be saved.']);
    exit;
}
mysqli_report(MYSQLI_REPORT_OFF);
mysqli_set_charset($conn, 'utf8mb4');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}
if (!psCsrfValidate()) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload and try again.']);
    exit;
}
if (!psSessionMayAddCustomer($conn)) {
    echo json_encode(['success' => false, 'error' => 'Adding customers at the POS is not switched on for till users. Ask a supervisor.']);
    exit;
}
$type = strtoupper(trim((string)($_POST['customer_type'] ?? 'INDIVIDUAL'))) === 'BUSINESS' ? 'BUSINESS' : 'INDIVIDUAL';
$data = [
    'customer_type' => $type,
    'first_name' => (string)($_POST['first_name'] ?? ''),
    'last_name' => (string)($_POST['last_name'] ?? ''),
    'business_name' => (string)($_POST['business_name'] ?? ''),
    'phone' => (string)($_POST['phone'] ?? ''),
    'email' => (string)($_POST['email'] ?? ''),
];
$r = psCreateCustomer($conn, $data);
if (empty($r['ok'])) {
    echo json_encode(['success' => false, 'error' => (string)($r['error'] ?? 'The customer could not be saved.')]);
    exit;
}
$row = psCustomerById($conn, (int)$r['id']);
echo json_encode([
    'success' => true,
    'id' => (int)$r['id'],
    'code' => (string)($r['customer_code'] ?? ''),
    'name' => $row ? psCustomerDisplayName($row) : '',
]);
mysqli_close($conn);
