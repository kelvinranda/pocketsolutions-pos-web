<?php
/**
 * Phase 6.2 — POS read-only customer account snapshot for credit payment UI.
 * Returns ledger-derived balance / credit limit / available credit. Never trusts posted balances.
 * Does not create accounts. ACTIVE customer_master only.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_account.php';

header('Content-Type: application/json; charset=utf-8');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Account info could not be loaded.']);
    exit;
}

if (trim((string)($_SESSION['logger'] ?? '')) === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
    mysqli_close($conn);
    exit;
}

$idRaw = trim((string)($_GET['customer_master_id'] ?? $_GET['id'] ?? ''));
if ($idRaw === '' || !preg_match('/^\d+$/', $idRaw) || (int)$idRaw < 1) {
    echo json_encode(['success' => false, 'error' => 'Customer was not found.', 'has_account' => false]);
    mysqli_close($conn);
    exit;
}

$snap = psCustomerAccountPosSnapshot($conn, (int)$idRaw);
if (!$snap['ok']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $snap['error'] ?? 'Account info could not be loaded.',
        'has_account' => !empty($snap['has_account']),
    ]);
    mysqli_close($conn);
    exit;
}

echo json_encode([
    'success' => true,
    'has_account' => !empty($snap['has_account']),
    'eligible' => !empty($snap['eligible']),
    'customer_name' => (string)($snap['customer_name'] ?? ''),
    'customer_code' => (string)($snap['customer_code'] ?? ''),
    'account_number' => (string)($snap['account_number'] ?? ''),
    'status' => (string)($snap['status'] ?? ''),
    'credit_status' => (string)($snap['credit_status'] ?? 'NORMAL'),
    'balance' => (string)($snap['balance'] ?? '0.00'),
    'credit_limit' => (string)($snap['credit_limit'] ?? '0.00'),
    'available_credit' => (string)($snap['available_credit'] ?? '0.00'),
]);
mysqli_close($conn);
