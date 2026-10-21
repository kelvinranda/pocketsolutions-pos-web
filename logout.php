<?php
/**
 * Phase 14 — Safe logout: destroy server session + cookie.
 * Health-check 2026-10-13: logout now requires POST + CSRF (the shared logout confirm
 * submits a form). A plain GET shows a one-button confirm page instead of logging out,
 * so a link on another site cannot sign a cashier out.
 */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/pockets_config.php';

psSecurityStartSession();
$actor = trim((string)($_SESSION['logger'] ?? ''));

if ($actor === '') {
    psSecurityDestroySession();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !psCsrfValidate()) {
    $tok = htmlspecialchars(psCsrfToken(), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/includes/ps_layout.php';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Log out</title>';
    ps_head();
    echo '</head><body class="ps-app"><div class="ps-content" style="max-width:420px;margin:15vh auto;text-align:center">';
    echo '<h1>Log out?</h1><p class="ps-page-lead">You will need to sign in again.</p>';
    echo '<form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="' . $tok . '">';
    echo '<button class="ps-btn" type="submit">Log out</button> <a class="ps-btn ps-btn-outline" href="javascript:history.back()">Cancel</a></form>';
    echo '</div></body></html>';
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($servername, $username, $password, $dbname);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    psSecurityAudit($conn, 'LOGOUT', [
        'actor' => $actor,
        'summary' => 'User logged out',
        'target_type' => 'user',
        'target_id' => $actor,
    ]);
    mysqli_close($conn);
}

psSecurityDestroySession();
header('Location: login.php');
exit;
