<?php
/**
 * 2026-10-24 — JSON: approve one receipt reprint (POST, CSRF). See includes/receipt_reprint.php.
 * Returns a single-use grant the browser passes to the print page.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/receipt_reprint.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'The reprint could not be approved. Try again.']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');
psSecurityEnforceWebSession($conn, ['redirect' => false, 'json' => true, 'touch' => true, 'csrf' => true]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !psCsrfValidate()) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload and try again.']);
    mysqli_close($conn);
    exit;
}
if (function_exists('psPlatformIsActing') && psPlatformIsActing()) {
    echo json_encode(['success' => false, 'error' => psPlatformMoneyBlockedMessage()]);
    mysqli_close($conn);
    exit;
}

$res = psReprintAuthorise(
    $conn,
    (string)($_POST['invoice_number'] ?? ''),
    (string)($_POST['document'] ?? PS_REPRINT_DOC_RECEIPT),
    (string)($_POST['approver_login'] ?? ''),
    (string)($_POST['approver_password'] ?? '')
);
mysqli_close($conn);
echo json_encode(empty($res['ok'])
    ? ['success' => false, 'error' => (string)$res['error']]
    : ['success' => true, 'grant' => $res['grant'], 'approved_by' => $res['approved_by']]);
