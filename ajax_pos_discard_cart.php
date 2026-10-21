<?php
/**
 * Phase 8.4 — Discard uncommitted POS cart (no persisted sale).
 * Server validates auth + reason; audits DISCARD_CART. Posted branch ignored.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/sale_cancel.php';
require_once __DIR__ . '/includes/security.php';
require __DIR__ . '/pockets_config.php';

psSecurityStartSession();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'The cart could not be discarded.']);
    exit;
}
mysqli_report(MYSQLI_REPORT_OFF);

if (!psSaleCancelMayAct()) {
    echo json_encode(['success' => false, 'error' => 'Please sign in before discarding a cart.']);
    mysqli_close($conn);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    mysqli_close($conn);
    exit;
}

if (!psCsrfValidate()) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload and try again.']);
    mysqli_close($conn);
    exit;
}

if (!psSaleCancelConsumeSubmitToken($_POST['cancel_submit_token'] ?? '')) {
    echo json_encode([
        'success' => false,
        'error' => 'This form was already submitted or the session expired. Please try again.',
        'cancel_submit_token' => psSaleCancelIssueSubmitToken(),
    ]);
    mysqli_close($conn);
    exit;
}

$result = psSaleCancelDiscardCart($conn, [
    'reason' => $_POST['reason'] ?? '',
    'reason_description' => $_POST['reason_description'] ?? '',
    'branch' => $_POST['branch'] ?? '',
    'user' => $_POST['user'] ?? '',
    'source' => PS_CANCEL_AUDIT_SOURCE_UI,
]);

$newToken = psSaleCancelIssueSubmitToken();
if (!empty($result['ok'])) {
    echo json_encode([
        'success' => true,
        'message' => (string)($result['message'] ?? 'Cart discarded.'),
        'audit_id' => (int)($result['audit_id'] ?? 0),
        'cancel_submit_token' => $newToken,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => (string)($result['error'] ?? 'The cart could not be discarded.'),
        'cancel_submit_token' => $newToken,
    ]);
}

mysqli_close($conn);
