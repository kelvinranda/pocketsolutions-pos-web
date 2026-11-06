<?php
/**
 * Phase 14/16 — Authenticated session gate for protected pages.
 * Timeout, cookie-safe destroy, live user revalidation when DB is available.
 */
require_once __DIR__ . '/includes/security.php';

psSecurityStartSession();

$configPath = __DIR__ . '/pockets_config.php';
$psSecConn = null;
if (is_file($configPath)) {
    require_once $configPath;
    if (isset($servername, $username, $password, $dbname) && $dbname !== '') {
        mysqli_report(MYSQLI_REPORT_OFF);
        $psSecConn = @mysqli_connect($servername, $username, $password, $dbname);
        if ($psSecConn) {
            mysqli_set_charset($psSecConn, 'utf8mb4');
        }
    }
}

psSecurityEnforceWebSession($psSecConn instanceof mysqli ? $psSecConn : null, [
    'redirect' => true,
    'login' => 'login.php',
    'touch' => true,
    'csrf' => true,
]);

if ($psSecConn instanceof mysqli) {
    mysqli_close($psSecConn);
}

// 2026-11-06: after a Super Admin reset the password to the default (000000), the user must choose a new one first.
if (!empty($_SESSION['ps_must_change_pw'])
    && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['change_password.php', 'logout.php'], true)) {
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Choose a new password first (Change password).']);
    } else {
        header('Location: change_password.php');
    }
    exit;
}
