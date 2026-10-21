<?php
/**
 * Phase 15 — Health check endpoint.
 * Unauthenticated: non-sensitive ok/fail booleans only (no paths, secrets, PII).
 * Authenticated admin (?detail=1): last backup status age without paths/credentials.
 */
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/backup.php';

psSecurityStartSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$conn = @mysqli_connect($servername, $username, $password, $dbname);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
}

$isAdmin = false;
if (trim((string)($_SESSION['logger'] ?? '')) !== ''
    && strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin') {
    $isAdmin = true;
}

$wantDetail = $isAdmin && isset($_GET['detail']) && (string)$_GET['detail'] === '1';
$snap = psBackupHealthSnapshot($conn ?: null, $wantDetail);

$payload = [
    'ok' => !empty($snap['ok']),
    'checks' => $snap['checks'],
];
if ($wantDetail) {
    $payload['backup_last_status'] = $snap['backup_last_status'] ?? 'none';
    $payload['backup_last_age_hours'] = $snap['backup_last_age_hours'] ?? null;
}

if ($conn) {
    mysqli_close($conn);
}

http_response_code(!empty($payload['ok']) ? 200 : 503);
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
