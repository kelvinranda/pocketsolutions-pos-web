<?php
/**
 * Phase 14 — Admin user create: authz, CSRF, hashed passwords, no posted privilege escalation for self.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/branch_integrity.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAdminRedirect('ajax_pos.php');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('create_user: connection failed');
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');
psSecurityEnsureUsersPasswordColumn($conn);

$errors = [];
$success = '';
$branches = psBranchRegisteredNames($conn);
$companyName = trim((string)($_SESSION['the_company'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } else {
        $firstname = trim((string)($_POST['firstname'] ?? ''));
        $lastname = trim((string)($_POST['lastname'] ?? ''));
        $email = trim((string)($_POST['email'] ?? '')); // login ID: email or username
        $plain = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $levelRaw = (string)($_POST['level'] ?? '');
        $phonenumber = trim((string)($_POST['phonenumber'] ?? ''));
        $branchRaw = (string)($_POST['branch'] ?? '');

        // Company always from session — never from POST.
        $company = $companyName;

        if ($firstname === '' || $lastname === '' || $email === '' || $plain === '' || $phonenumber === '' || $company === '') {
            $errors[] = 'All fields are required.';
        }
        $loginCheck = psSecurityValidateLoginId($email);
        if (!$loginCheck['ok']) {
            $errors[] = $loginCheck['error'];
        }
        if (strlen($plain) < PS_PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . PS_PASSWORD_MIN_LENGTH . ' characters.';
        } elseif ($plain !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        if (!psSecurityLevelIsAllowed($levelRaw)) {
            $errors[] = 'That user level is not allowed.';
        }
        $level = psSecurityNormalizeLevel($levelRaw);

        $regWrite = psBranchRequireRegisteredWrite($conn, $branchRaw);
        $branch = !empty($regWrite['ok']) ? (string)$regWrite['branch'] : '';
        if ($branch === '') {
            $errors[] = 'A registered branch is required.';
        }

        if (!$errors) {
            $dup = mysqli_prepare($conn, 'SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1');
            if ($dup) {
                mysqli_stmt_bind_param($dup, 's', $email);
                mysqli_stmt_execute($dup);
                $dr = mysqli_stmt_get_result($dup);
                if ($dr && mysqli_fetch_assoc($dr)) {
                    $errors[] = 'That email or username is already used by another user.';
                }
                mysqli_stmt_close($dup);
            }
        }

        if (!$errors) {
            $hash = psSecurityHashPassword($plain);
            $lock = 'Allowed';
            $status = 'Posted';
            $stmt = $conn->prepare(
                'INSERT INTO users (firstname, lastname, email, password, level, phonenumber, company_name, branch, lock_status, status, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            if (!$stmt) {
                error_log('create_user: prepare failed');
                $errors[] = 'User could not be created.';
            } else {
                $stmt->bind_param(
                    'ssssssssss',
                    $firstname,
                    $lastname,
                    $email,
                    $hash,
                    $level,
                    $phonenumber,
                    $company,
                    $branch,
                    $lock,
                    $status
                );
                if ($stmt->execute()) {
                    $newId = (int)$stmt->insert_id;
                    psSecurityAudit($conn, 'USER_CREATE', [
                        'summary' => 'User created',
                        'target_type' => 'user',
                        'target_id' => (string)$newId,
                        'after' => [
                            'email' => $email,
                            'level' => $level,
                            'branch' => $branch,
                            'company_name' => $company,
                        ],
                    ]);
                    $success = 'User ' . $email . ' created. They sign in with "' . $email . '" and the password you set.';
                    $_POST = [];
                } else {
                    error_log('create_user: execute failed');
                    $errors[] = 'User could not be created.';
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User</title>
    <?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); echo psCsrfMeta(); ?>
    <style>
        .cu-wrap { max-width: 860px; }
        .cu-form { display: flex; flex-direction: column; gap: 24px; }
        .cu-group { border: 0; margin: 0; padding: 0; min-width: 0; }
        .cu-group + .cu-group { border-top: 1px solid var(--ps-line); padding-top: 22px; }
        .cu-legend { padding: 0; margin: 0 0 14px; line-height: 1.3; font-weight: 700; font-size: 1rem; }
        .cu-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 20px; }
        .cu-grid .ps-field { margin: 0; display: flex; flex-direction: column; gap: 6px; }
        .cu-grid .ps-input { width: 100%; }
        .cu-span { grid-column: 1 / -1; }
        .cu-note { margin: 16px 0 4px; }
        .cu-actions { display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap; border-top: 1px solid var(--ps-line); padding-top: 20px; }
        .cu-wrap .ps-alert div + div { margin-top: 4px; }
        @media (max-width: 640px) { .cu-grid { grid-template-columns: 1fr; } .cu-actions .ps-btn { flex: 1 1 auto; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Create user');
$v = static fn (string $k): string => htmlspecialchars((string)($_POST[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$postedLevel = (string)($_POST['level'] ?? 'Till');
$postedBranch = (string)($_POST['branch'] ?? '');
$levels = ['Till' => 'Till', 'Supervisor' => 'Supervisor', 'Management' => 'Management', 'admin' => 'Admin'];
?>
<div class="cu-wrap">
    <h1>Create User</h1>
    <p class="ps-page-lead">Add a staff account for <?php echo htmlspecialchars($companyName !== '' ? $companyName : 'your company'); ?>. Staff without an email address can sign in with a username instead.</p>

    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert">
            <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="ps-alert ps-alert-success" role="status"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-content">
            <form method="post" action="" class="cu-form" autocomplete="off">
                <?php echo psCsrfField(); ?>
                <section class="cu-group">
                    <h2 class="cu-legend">Person</h2>
                    <div class="cu-grid">
                        <div class="ps-field">
                            <label class="ps-label" for="firstname">First name</label>
                            <input class="ps-input" id="firstname" type="text" name="firstname" value="<?php echo $v('firstname'); ?>" required>
                        </div>
                        <div class="ps-field">
                            <label class="ps-label" for="lastname">Last name</label>
                            <input class="ps-input" id="lastname" type="text" name="lastname" value="<?php echo $v('lastname'); ?>" required>
                        </div>
                        <div class="ps-field">
                            <label class="ps-label" for="phonenumber">Phone</label>
                            <input class="ps-input" id="phonenumber" type="tel" name="phonenumber" value="<?php echo $v('phonenumber'); ?>" required>
                        </div>
                    </div>
                </section>

                <section class="cu-group">
                    <h2 class="cu-legend">Sign-in</h2>
                    <div class="cu-grid">
                        <div class="ps-field cu-span">
                            <label class="ps-label" for="email">Email or username</label>
                            <input class="ps-input" id="email" type="text" name="email" value="<?php echo $v('email'); ?>" required autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="250">
                            <span class="ps-helper">An email address, or a username such as <em>tendai.m</em> (3–50 letters or numbers, no spaces).</span>
                        </div>
                        <div class="ps-field">
                            <label class="ps-label" for="password">Password</label>
                            <input class="ps-input" id="password" type="password" name="password" required minlength="<?php echo PS_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                            <span class="ps-helper">At least <?php echo PS_PASSWORD_MIN_LENGTH; ?> characters.</span>
                        </div>
                        <div class="ps-field">
                            <label class="ps-label" for="confirm_password">Confirm password</label>
                            <input class="ps-input" id="confirm_password" type="password" name="confirm_password" required minlength="<?php echo PS_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                        </div>
                    </div>
                </section>

                <section class="cu-group">
                    <h2 class="cu-legend">Access</h2>
                    <div class="cu-grid">
                        <div class="ps-field">
                            <label class="ps-label" for="level">Role</label>
                            <select class="ps-input" id="level" name="level" required>
                                <?php foreach ($levels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo strcasecmp($postedLevel, $value) === 0 ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ps-field">
                            <label class="ps-label" for="branch">Branch</label>
                            <select class="ps-input" id="branch" name="branch" required>
                                <option value="">Select branch</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $postedBranch === $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="ps-muted cu-note">Company: <strong><?php echo htmlspecialchars($companyName); ?></strong> (from your session)</p>
                </section>

                <div class="cu-actions">
                    <a class="ps-btn ps-btn-outline" href="users.php">Back to users</a>
                    <button class="ps-btn ps-btn-default" type="submit">Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
<?php $conn->close(); ?>
