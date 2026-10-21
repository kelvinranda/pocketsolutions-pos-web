<?php
/**
 * Phase 9.2 — Authorised working-branch switch.
 *
 * Auth required (session logger). POST only.
 * Validates against branchies; role + home resolved server-side from session.
 * Ordinary: only home branch. Admin: registered branchies for session company only.
 * Posted level / user id / home branch are ignored.
 * Open till on another registered branch blocks switch — till rows are not mutated.
 * Legacy NULL/blank OPEN tills do not block session working-branch switch;
 * operational sale/recon/close may bind blank tills to the working branch once.
 * CSRF validated via psCsrfValidate().
 */
require_once __DIR__ . '/includes/security.php';
psSecurityStartSession();
require_once __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/till_session.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

if (!psBranchSessionAuthenticated()) {
    http_response_code(401);
    echo 'Please sign in again.';
    exit;
}

if (!psCsrfValidate()) {
    http_response_code(403);
    echo 'Invalid security token. Reload and try again.';
    exit;
}

$conn = @new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('set_branch: db connect failed');
    http_response_code(500);
    echo 'Could not switch branch.';
    exit;
}
$conn->set_charset('utf8mb4');

$posted = $_POST['selected_branch'] ?? '';
$before = psBranchSelectedRaw();
$result = psBranchApplySwitch($conn, $posted);

$redirect = psBranchSafeRedirect($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));

if (empty($result['ok'])) {
    psSecurityAudit($conn, 'BRANCH_SWITCH_DENIED', [
        'summary' => (string)($result['error'] ?? 'denied'),
        'target_type' => 'branch',
        'target_id' => psBranchNormalizeName($posted),
        'before' => ['selected_branch' => $before],
    ]);
    http_response_code((int)($result['http'] ?? 403));
    echo (string)($result['error'] ?? 'That branch is not available.');
    $conn->close();
    exit;
}

psSecurityAudit($conn, 'BRANCH_SWITCH', [
    'summary' => 'Working branch switched',
    'target_type' => 'branch',
    'target_id' => (string)($result['branch'] ?? ''),
    'before' => ['selected_branch' => $before],
    'after' => ['selected_branch' => (string)($result['branch'] ?? '')],
]);

if ($redirect !== '') {
    $conn->close();
    header('Location: ' . $redirect);
    exit;
}

http_response_code(200);
$conn->close();
exit;
