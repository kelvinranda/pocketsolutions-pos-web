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
