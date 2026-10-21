<?php
/**
 * Phase 21 — API v1 for Pockets POS for Windows (desktop / offline tills).
 * Auth: "Authorization: Bearer <till token>" issued by register.php. Only the SHA-256 is stored.
 * Not behind the web page gate (subfolder); every endpoint authenticates the till itself.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('Africa/Harare');

require dirname(__DIR__, 2) . '/pockets_config.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_sync.php';

const PS_API_VERSION = '1';

function apiRespond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload + ['api_version' => PS_API_VERSION], JSON_UNESCAPED_SLASHES);
    exit;
}

function apiFail(string $error, int $code = 400, string $errorCode = 'bad_request'): void
{
    apiRespond(['success' => false, 'error' => $error, 'error_code' => $errorCode], $code);
}

function apiBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8 * 1024 * 1024) {
        apiFail('Request is too large.', 413);
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function apiRequireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        apiFail('Use ' . $method . '.', 405, 'method');
    }
}

function apiBearer(): string
{
    foreach ([$_SERVER['HTTP_AUTHORIZATION'] ?? '', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''] as $h) {
        if (preg_match('/Bearer\s+(\S+)/i', (string)$h, $m)) {
            return $m[1];
        }
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0 && preg_match('/Bearer\s+(\S+)/i', (string)$v, $m)) {
                return $m[1];
            }
        }
    }
    return '';
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    apiFail('The server database is not available. Try again later.', 503, 'unavailable');
}
mysqli_set_charset($conn, 'utf8mb4');
if (!psDtEnsureSchema($conn) || !tillEnsureSchema($conn) || !psEnsureSaleIntegritySchema($conn)
    || !stockMovementEnsureSchema($conn) || !psEnsureCashMovementModule($conn) || !psEnsureTaxModule($conn)) {
    apiFail('The server is not ready. Try again later.', 503, 'unavailable');
}

/** The calling till, or a 401. Suspended companies get 403. */
function apiTill(mysqli $conn): array
{
    $till = psDtTillForToken($conn, apiBearer());
    if (!$till) {
        apiFail('This till is not registered or was revoked. Pair it again from Desktop Tills.', 401, 'unauthorized');
    }
    if (psPlatformCompanyIsSuspended($conn, (string)$till['company_name'])) {
        apiFail('This company account is suspended.', 403, 'suspended');
    }
    return $till;
}
