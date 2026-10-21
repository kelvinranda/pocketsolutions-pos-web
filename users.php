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

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAdminRedirect('ajax_pos.php');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('users: connection failed');
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');

$search = '';
if (isset($_POST['search'])) {
    $search = trim((string)$_POST['search']);
}

$company = trim((string)($_SESSION['the_company'] ?? ''));
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

<table>

<tr>
    <th>ID</th>
    <th>Firstname</th>
    <th>Lastname</th>
    <th>Email</th>
    <th>Level</th>
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
echo "<td>" . htmlspecialchars((string)$row['level']) . "</td>";
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
