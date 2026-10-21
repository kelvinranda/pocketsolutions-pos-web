<?php
/**
 * Phase 9.3 — Assign a user's home branch (admin user-management only).
 *
 * Caller must be authenticated admin. Target user must belong to the same
 * company_name as the session. Branch must exist in branchies.
 * Posted role / level / admin flags / current branch are ignored.
 * Ordinary users cannot change own or others' assigned branch here.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/branch_integrity.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAdminRedirect('ajax_pos.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

if (!psCsrfValidate()) {
    http_response_code(403);
    echo 'Invalid security token. Reload and try again.';
    exit;
}

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('update_branch: connection failed');
    header('Location: users.php');
    exit;
}
$conn->set_charset('utf8mb4');

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$company = trim((string)($_SESSION['the_company'] ?? ''));

// Ignore forged role / level / home / selected / admin POST fields entirely.
// Phase 9.5: NEW home-branch assignments must be registered (trim + exact).
$regWrite = psBranchRequireRegisteredWrite($conn, $_POST['branch'] ?? '');
$branch = !empty($regWrite['ok']) ? (string)$regWrite['branch'] : '';
if ($userId < 1 || $branch === '' || $company === '') {
    $conn->close();
    header('Location: users.php');
    exit;
}

$beforeBranch = '';
$prev = $conn->prepare('SELECT email, branch FROM users WHERE id = ? AND company_name = ? LIMIT 1');
if ($prev) {
    $prev->bind_param('is', $userId, $company);
    $prev->execute();
    $pr = $prev->get_result();
    $prow = $pr ? $pr->fetch_assoc() : null;
    $prev->close();
    if (!$prow) {
        $conn->close();
        header('Location: users.php');
        exit;
    }
    $beforeBranch = trim((string)($prow['branch'] ?? ''));
    $targetEmail = (string)($prow['email'] ?? '');
} else {
    $targetEmail = (string)$userId;
}

$stmt = $conn->prepare(
    'UPDATE users SET branch = ? WHERE id = ? AND company_name = ? LIMIT 1'
);
if (!$stmt) {
    error_log('update_branch: prepare failed');
    $conn->close();
    header('Location: users.php');
    exit;
}
$stmt->bind_param('sis', $branch, $userId, $company);
if (!$stmt->execute()) {
    error_log('update_branch: execute failed');
} else {
    psSecurityAudit($conn, 'USER_BRANCH_ASSIGN', [
        'summary' => 'Home branch assignment updated',
        'target_type' => 'user',
        'target_id' => (string)$userId,
        'before' => ['branch' => $beforeBranch, 'email' => $targetEmail],
        'after' => ['branch' => $branch, 'email' => $targetEmail],
    ]);
}
$stmt->close();
$conn->close();

header('Location: users.php');
exit;
