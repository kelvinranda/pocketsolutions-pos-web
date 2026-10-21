<?php
/**
 * Phase 14 — Open till via POST + CSRF only (no state-changing GET).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/till_session.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}
psCsrfRequire('text');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo 'Could not open the till session.';
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

// Preserve prior GET opening_float behaviour via POST field.
if (!isset($_POST['opening_float']) && isset($_GET['opening_float'])) {
    $_POST['opening_float'] = $_GET['opening_float'];
}

echo tillOpen($conn);
psSecurityAudit($conn, 'TILL_OPEN', [
    'summary' => 'Till open requested',
    'target_type' => 'till',
]);
mysqli_close($conn);
