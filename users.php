<?php
/**
 * Phase 9.3 — User list + branch assignment UI (admin only).
 * Page and state-changing/data-returning requests require authenticated admin.
 * Assigned branch options come from branchies only.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/user_roles.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAdminRedirect('ajax_pos.php');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('users: connection failed');
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');

$company = trim((string)($_SESSION['the_company'] ?? ''));
$me = trim((string)($_SESSION['logger'] ?? ''));
$roleMsg = null;
// 2026-10-28: change a user's role (rules in includes/user_roles.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_role') {
    if (!psCsrfValidate()) {
        $roleMsg = ['err', 'Invalid security token. Reload and try again.'];
    } else {
        $r = psUserRoleChange($conn, $company, (int)($_POST['user_id'] ?? 0), (string)($_POST['level'] ?? ''), [
            'actor' => $me,
            'platform' => function_exists('psPlatformActing') && psPlatformActing() !== null,
            'reason' => (string)($_POST['reason'] ?? ''),
            'password' => (string)($_POST['my_password'] ?? ''),
        ]);
        if (!empty($r['ok'])) {
            $_SESSION['ps_users_flash'] = ['ok', $r['email'] . ' is now ' . psUserRoleLabel((string)$r['after'])
                . '. It applies on their next click on the web, and from their next sign-in on a till (after the till syncs).'];
            header('Location: users.php');
            exit;
        }
        $roleMsg = ['err', (string)$r['error']];
    }
}
if (!$roleMsg && isset($_SESSION['ps_users_flash'])) {
    $roleMsg = $_SESSION['ps_users_flash'];
    unset($_SESSION['ps_users_flash']);
}
$roleUsers = psUserRoleCompanyUsers($conn, $company);
$roleHistory = psUserRoleHistory($conn, $company, 20);
$actingProvider = function_exists('psPlatformActing') && psPlatformActing() !== null;

$search = '';
if (isset($_POST['search'])) {
    $search = trim((string)$_POST['search']);
}

$branches = psBranchRegisteredNames($conn);

$sql = "SELECT id, firstname, lastname, email, level, phonenumber, company_name, branch
        FROM users
        WHERE company_name = ?";

if ($search !== '') {
    $sql .= " AND (
                firstname LIKE ?
                OR lastname LIKE ?
                OR email LIKE ?
                OR phonenumber LIKE ?
             )";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('users: prepare failed');
    die('Users could not be loaded.');
}

if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $stmt->bind_param(
        'sssss',
        $company,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
} else {
    $stmt->bind_param('s', $company);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Users</title>



<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>

<body class="ps-app">
<?php ps_open(); ?>
<div class="container">

<h1>Users</h1>

<div class="search-container">

<form method="POST">

<input
type="text"
name="search"
placeholder="Search by firstname, lastname, email or phone"
value="<?php echo htmlspecialchars($search); ?>">

<button type="submit">Search</button>

</form>

</div>

<?php if ($roleMsg): ?>
<div class="ps-alert <?php echo $roleMsg[0] === 'ok' ? 'ps-alert-success' : 'ps-alert-destructive'; ?>" role="status" style="margin:12px 0"><?php echo htmlspecialchars($roleMsg[1]); ?></div>
<?php endif; ?>

<div class="ps-card" style="margin:12px 0">
    <div class="ps-card-header"><h2 class="ps-card-title">Change a user's role</h2>
        <p class="ps-card-desc">Till, Supervisor, Management or Admin. You cannot change your own role, and the company always keeps at least one Admin.
        The new role applies on the user's next click on the web, and from their next sign-in on a till (tills download users when they sync).</p></div>
    <div class="ps-card-content">
        <form method="POST" autocomplete="off" id="role-form">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="action" value="change_role">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end">
                <label class="ps-field"><span class="ps-label">User</span>
                    <select class="ps-input" name="user_id" required>
                        <option value="">Choose a user…</option>
                        <?php foreach ($roleUsers as $u): if (strcasecmp((string)$u['email'], $me) === 0) { continue; } ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo (int)($_POST['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(trim($u['firstname'] . ' ' . $u['lastname']) . ' · ' . $u['email'] . ' · now ' . psUserRoleLabel((string)$u['level'])); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="ps-field"><span class="ps-label">New role</span>
                    <select class="ps-input" name="level" id="role-level" required>
                        <?php foreach (psUserRoleChoices() as $val => $label): ?>
                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($_POST['level'] ?? '') === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="ps-field"><span class="ps-label">Reason<?php echo $actingProvider ? '' : ' (optional)'; ?></span>
                    <input class="ps-input" name="reason" maxlength="400" <?php echo $actingProvider ? 'required' : ''; ?> placeholder="e.g. Promoted to shop manager" value="<?php echo htmlspecialchars((string)($_POST['reason'] ?? '')); ?>"></label>
                <?php if (!$actingProvider): ?>
                <label class="ps-field" id="role-pw" hidden><span class="ps-label">Your password (to make someone an Admin)</span>
                    <input class="ps-input" type="password" name="my_password" autocomplete="current-password"></label>
                <?php endif; ?>
                <div><button class="ps-btn ps-btn-default" type="submit">Change role</button></div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('role-level'), pw = document.getElementById('role-pw');
    if (!sel || !pw) { return; }
    function sync() { var on = sel.value === 'admin'; pw.hidden = !on; pw.querySelector('input').required = on; }
    sel.addEventListener('change', sync); sync();
    document.getElementById('role-form').addEventListener('submit', function (e) {
        if (sel.value === 'admin' && !window.confirm('An Admin has full control of this company: users, settings, prices and reports. Continue?')) { e.preventDefault(); }
    });
})();
</script>

<table>

<tr>
    <th>ID</th>
    <th>Firstname</th>
    <th>Lastname</th>
    <th>Email</th>
    <th>Role</th>
    <th>Phone</th>
    <th>Company</th>
    <th>Branch</th>
</tr>

<?php

if ($result && $result->num_rows > 0) {

while ($row = $result->fetch_assoc()) {

echo "<tr>";

echo "<td>" . htmlspecialchars((string)$row['id']) . "</td>";
echo "<td>" . htmlspecialchars((string)$row['firstname']) . "</td>";
echo "<td>" . htmlspecialchars((string)$row['lastname']) . "</td>";
echo "<td>" . htmlspecialchars((string)$row['email']) . "</td>";
echo "<td>" . htmlspecialchars(psUserRoleLabel((string)$row['level'])) . "</td>";
echo "<td>" . htmlspecialchars((string)$row['phonenumber']) . "</td>";
echo "<td>" . htmlspecialchars((string)$row['company_name']) . "</td>";

echo "<td>";

echo "<form method='POST' action='update_branch.php'>";
echo psCsrfField();
echo "<input type='hidden' name='user_id' value='" . (int)$row['id'] . "'>";

echo "<select name='branch' onchange='this.form.submit()'>";

foreach ($branches as $branch) {

$selected = '';

if (psBranchNormalizeName($row['branch'] ?? '') === psBranchNormalizeName($branch)) {
    $selected = 'selected';
}

echo "<option value='" . htmlspecialchars($branch) . "' $selected>"
        . htmlspecialchars($branch) .
     "</option>";

}

echo "</select>";

echo "</form>";

echo "</td>";

echo "</tr>";

}

} else {

echo "<tr><td colspan='8'>No users found.</td></tr>";

}

?>

</table>

<div class="ps-card" style="margin:16px 0">
    <div class="ps-card-header"><h2 class="ps-card-title">Recent role changes</h2></div>
    <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>When</th><th>User</th><th>From</th><th>To</th><th>Changed by</th><th>Reason</th></tr></thead>
        <tbody>
        <?php if (!$roleHistory): ?><tr><td colspan="6" class="ps-muted">No role changes yet.</td></tr><?php endif; ?>
        <?php foreach ($roleHistory as $hrow): ?>
        <tr><td style="white-space:nowrap"><?php echo htmlspecialchars(date('d M Y H:i', strtotime($hrow['at']))); ?></td>
            <td><?php echo htmlspecialchars($hrow['email']); ?></td><td><?php echo htmlspecialchars($hrow['before']); ?></td><td><?php echo htmlspecialchars($hrow['after']); ?></td>
            <td><?php echo htmlspecialchars($hrow['by']) . ($hrow['provider'] ? ' <span class="ps-badge ps-badge-secondary">Pocket Solutions</span>' : ''); ?></td>
            <td><?php echo htmlspecialchars($hrow['reason']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

<br>

<a class="back-button" href="create_user.php">Create New User</a>
<a class="back-button" href="change_password.php">Change my password</a>
<a class="back-button" href="user_lock.php">Lock / unlock users</a>
<a class="back-button" href="security_audit_history.php">Security audit</a>

<button class="back" onclick="history.back()">Back</button>

</div>

<?php

$stmt->close();
$conn->close();

?>

<?php ps_close(); ?>
</body>
</html>
