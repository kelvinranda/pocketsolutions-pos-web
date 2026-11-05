<?php
/**
 * Phase 14 — Security audit viewer (admin). Append-oriented; not casually editable.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
psBranchRequireAdminRedirect('ajax_pos.php');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psSecurityEnsureAuditSchema($conn);

$rows = psSecurityAuditFetchHistory($conn, 200);
mysqli_close($conn);
$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Security Audit</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Security audit'); ?>
<h1>Security Audit</h1>
<p>Recent privileged and security events. Storage is application-controlled (not claimed tamper-proof). Passwords and tokens are not stored here.</p>
<table>
<tr>
    <th>ID</th><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Source</th><th>IP</th><th>Summary</th>
</tr>
<?php if (!$rows): ?>
<tr><td colspan="8">No security audit rows yet.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?php echo (int)$r['id']; ?></td>
    <td><?php echo $h($r['occurred_at']); ?></td>
    <td><?php echo $h($r['actor']); ?></td>
    <td><?php echo $h($r['action_code']); ?></td>
    <td><?php echo $h(trim($r['target_type'] . ' ' . $r['target_id'])); ?></td>
    <td><?php echo $h($r['source']); ?></td>
    <td><?php echo $h($r['ip_address']); ?></td>
    <td><?php echo $h($r['summary']); ?></td>
</tr>
<?php endforeach; ?>
</table>
<p><a href="users.php">Users</a> · <a href="admin_dashboard.php">Admin</a></p>
<?php ps_close(); ?>
</body>
</html>
