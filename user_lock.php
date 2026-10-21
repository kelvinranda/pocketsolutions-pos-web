<?php
/**
 * Phase 14 — Admin lock/unlock users (lock_status). Does not delete users or wipe sessions globally.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
psBranchRequireAdminRedirect('ajax_pos.php');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');

$company = trim((string)($_SESSION['the_company'] ?? ''));
$self = trim((string)($_SESSION['logger'] ?? ''));
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    psCsrfRequire('html');
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $newLock = $action === 'lock' ? 'locked' : 'Allowed';
    if ($userId < 1 || !in_array($action, ['lock', 'unlock'], true)) {
        $error = 'Invalid request.';
    } else {
        $stmt = $conn->prepare('SELECT id, email, lock_status FROM users WHERE id = ? AND company_name = ? LIMIT 1');
        $stmt->bind_param('is', $userId, $company);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            $error = 'User not found.';
        } elseif (strcasecmp((string)$row['email'], $self) === 0 && $action === 'lock') {
            $error = 'You cannot lock your own account.';
        } else {
            $upd = $conn->prepare('UPDATE users SET lock_status = ? WHERE id = ? AND company_name = ? LIMIT 1');
            $upd->bind_param('sis', $newLock, $userId, $company);
            if ($upd->execute()) {
                psSecurityAudit($conn, $action === 'lock' ? 'USER_LOCK' : 'USER_UNLOCK', [
                    'summary' => 'User lock_status changed',
                    'target_type' => 'user',
                    'target_id' => (string)$userId,
                    'before' => ['lock_status' => $row['lock_status'], 'email' => $row['email']],
                    'after' => ['lock_status' => $newLock, 'email' => $row['email']],
                ]);
                $message = 'User updated.';
            } else {
                $error = 'Update failed.';
            }
            $upd->close();
        }
    }
}

$stmt = $conn->prepare('SELECT id, firstname, lastname, email, level, lock_status FROM users WHERE company_name = ? ORDER BY email ASC');
$stmt->bind_param('s', $company);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lock / Unlock Users</title>
    <?php ps_head(); echo psCsrfMeta(); ?>
</head>
<body class="ps-app">
<?php ps_open('User lock'); ?>
<h1>Lock / Unlock Users</h1>
<p>Locked users cannot sign in (web or mobile). Existing PHP sessions are rejected on the next page load.</p>
<?php if ($message !== ''): ?><p class="notice"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
<table>
<tr><th>ID</th><th>Name</th><th>Email</th><th>Level</th><th>Lock</th><th>Action</th></tr>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?php echo (int)$row['id']; ?></td>
    <td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></td>
    <td><?php echo htmlspecialchars($row['email']); ?></td>
    <td><?php echo htmlspecialchars($row['level']); ?></td>
    <td><?php echo htmlspecialchars((string)$row['lock_status']); ?></td>
    <td>
        <form method="post" style="display:inline">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
            <?php if (strtolower(trim((string)$row['lock_status'])) === 'locked'): ?>
                <button type="submit" name="action" value="unlock">Unlock</button>
            <?php else: ?>
                <button type="submit" name="action" value="lock">Lock</button>
            <?php endif; ?>
        </form>
    </td>
</tr>
<?php endwhile; ?>
</table>
<p><a href="users.php">Back to users</a></p>
<?php
$stmt->close();
$conn->close();
ps_close();
?>
</body>
</html>
