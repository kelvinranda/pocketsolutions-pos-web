<?php
/**
 * Shared bootstrap for Pocket Solutions mobile sync API (XAMPP).
 * Phase 9.4: token auth + trusted branch binding via mobile_branch_auth.php.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Auth-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Match Zimbabwe / web POS wall-clock times (phone UI is also local UTC+2)
date_default_timezone_set('Africa/Harare');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$configCandidates = [
    __DIR__ . '/../pockets_config.php',
    __DIR__ . '/pockets_config.php',
];

$configLoaded = false;
foreach ($configCandidates as $cfg) {
    if (file_exists($cfg)) {
        require $cfg;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'pockets_config.php not found']);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'DB connection failed',
    ]);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/mobile_branch_auth.php';

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bearer_token(): ?string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '',
    ];

    // Some Apache builds expose it via apache_request_headers()
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0 || strcasecmp($key, 'X-Auth-Token') === 0) {
                $candidates[] = $value;
            }
        }
    }

    foreach ($candidates as $hdr) {
        $hdr = trim((string)$hdr);
        if ($hdr === '') {
            continue;
        }
        if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
            return $m[1];
        }
        // Raw token via X-Auth-Token
        if (!str_contains(strtolower($hdr), 'bearer ')) {
            return $hdr;
        }
    }

    return null;
}

function auth_user(mysqli $conn, ?string $tokenOverride = null): ?array
{
    ensure_mobile_tables($conn);
    $token = $tokenOverride ?: bearer_token();
    if (!$token) {
        return null;
    }
    // Parameterised token lookup (Phase 14).
    $stmt = mysqli_prepare(
        $conn,
        'SELECT token, username, cashier, level_name, company, created_at, expires_at
         FROM mobile_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1'
    );
    if (!$stmt) {
        error_log('mobile auth_user: prepare failed');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $token);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }

    // Reject tokens for missing/locked users (do not invent privileges from token alone).
    $secPath = dirname(__DIR__, 2) . '/includes/security.php';
    if (is_file($secPath)) {
        require_once $secPath;
        $email = trim((string)($row['username'] ?? $row['cashier'] ?? ''));
        $look = psSecurityLookupUserByEmail($conn, $email);
        if (!empty($look['ok']) && (psPlatformIsSuperAdminLevel((string)($look['user']['level'] ?? ''))
            || psPlatformCompanyIsSuspended($conn, (string)($look['user']['company_name'] ?? '')))) {
            // Phase 18: Super Admin or suspended company — token no longer valid.
            $look = ['ok' => false];
        }
        if (empty($look['ok'])) {
            // Expire token so clients must re-auth.
            $del = mysqli_prepare($conn, 'DELETE FROM mobile_tokens WHERE token = ? LIMIT 1');
            if ($del) {
                mysqli_stmt_bind_param($del, 's', $token);
                mysqli_stmt_execute($del);
                mysqli_stmt_close($del);
            }
            return null;
        }
        // Keep token level in sync with live user level (no client override).
        $row['level_name'] = (string)($look['user']['level'] ?? $row['level_name']);
        $row['company'] = (string)($look['user']['company_name'] ?? $row['company']);
        $row['username'] = (string)$look['user']['email'];
        $row['cashier'] = (string)$look['user']['email'];
        $row['user_id'] = (int)$look['user']['id'];
        $row['home_branch'] = trim((string)($look['user']['branch'] ?? ''));
    }
    return $row;
}

function ensure_mobile_tables(mysqli $conn): void
{
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS mobile_tokens (
            token VARCHAR(64) PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            cashier VARCHAR(100) NOT NULL,
            level_name VARCHAR(50) NULL,
            company VARCHAR(150) NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS mobile_sale_map (
            client_uuid VARCHAR(64) PRIMARY KEY,
            invoice_number VARCHAR(64) NOT NULL,
            synced_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS mobile_session_map (
            client_uuid VARCHAR(64) PRIMARY KEY,
            session_id INT NOT NULL,
            session_name VARCHAR(250) NOT NULL,
            synced_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function respond($payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}
