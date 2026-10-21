<?php
/**
 * Phase 18 — Super Admin accounts (Pocket Solutions staff): list, add, lock / unlock, reset password.
 * At least one unlocked Super Admin must remain; you cannot lock yourself.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';

$conn = psPlatformPageBoot();
$errors = [];
$me = (int)($_SESSION['user_id'] ?? 0);

$load = static function (mysqli $conn): array {
    $res = mysqli_query($conn, "SELECT id, firstname, lastname, email, phonenumber, lock_status, date_created FROM users
        WHERE REPLACE(REPLACE(LOWER(TRIM(level)), ' ', ''), '_', '') = 'superadmin' ORDER BY email");
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($action === 'create') {
        if ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        } else {
            $r = psPlatformCreateUser($conn, [
                'firstname' => (string)($_POST['firstname'] ?? ''),
                'lastname' => (string)($_POST['lastname'] ?? ''),
                'phonenumber' => (string)($_POST['phonenumber'] ?? ''),
                'login_id' => (string)($_POST['login_id'] ?? ''),
                'password' => (string)($_POST['password'] ?? ''),
                'level' => PS_PLATFORM_LEVEL,
                'company_name' => PS_PLATFORM_COMPANY,
                'branch' => '',
            ]);
            if (!empty($r['ok'])) {
                psPlatformAudit($conn, 'SUPERADMIN_CREATE', null, '', ['login' => $r['login_id']]);
                psSecurityAudit($conn, 'USER_CREATE', [
                    'summary' => 'Super Admin created',
                    'target_type' => 'user',
                    'target_id' => (string)$r['id'],
                    'after' => ['email' => $r['login_id'], 'level' => PS_PLATFORM_LEVEL],
                ]);
                psPlatformFlash('ok', 'Super Admin ' . $r['login_id'] . ' added.');
                header('Location: platform_admins.php');
                exit;
            }
            $errors[] = $r['error'];
        }
    } elseif ($action === 'lock' || $action === 'unlock' || $action === 'password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $all = $load($conn);
        $target = null;
        $unlocked = 0;
        foreach ($all as $a) {
            if ((int)$a['id'] === $uid) {
                $target = $a;
            }
            if (!psSecurityUserIsLocked($a)) {
                $unlocked++;
            }
        }
        if (!$target) {
            $errors[] = 'Super Admin not found.';
        } elseif ($action === 'lock' && $uid === $me) {
            $errors[] = 'You cannot lock your own account.';
        } elseif ($action === 'lock' && $unlocked <= 1) {
            $errors[] = 'At least one Super Admin must stay unlocked.';
        } elseif ($action === 'password') {
            if ((string)($_POST['new_password'] ?? '') !== (string)($_POST['new_password2'] ?? '')) {
                $errors[] = 'The two passwords do not match.';
            } else {
                $r = psPlatformSetPassword($conn, $uid, (string)($_POST['new_password'] ?? ''));
                if (!empty($r['ok'])) {
                    psSecurityAudit($conn, 'PASSWORD_RESET', ['summary' => 'Super Admin password reset', 'target_type' => 'user', 'target_id' => (string)$uid]);
                    psPlatformFlash('ok', 'Password for ' . $target['email'] . ' changed.');
                    header('Location: platform_admins.php');
                    exit;
                }
                $errors[] = $r['error'];
            }
        } else {
            $new = $action === 'lock' ? 'locked' : 'Allowed';
            $stmt = mysqli_prepare($conn, 'UPDATE users SET lock_status = ? WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'si', $new, $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            psPlatformAudit($conn, $action === 'lock' ? 'SUPERADMIN_LOCK' : 'SUPERADMIN_UNLOCK', null, '', ['login' => $target['email']]);
            psPlatformFlash('ok', $target['email'] . ($action === 'lock' ? ' locked. They are signed out everywhere.' : ' unlocked.'));
            header('Location: platform_admins.php');
            exit;
        }
    }
}

$rows = $load($conn);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$v = static fn (string $k): string => htmlspecialchars((string)($_POST[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$minLen = PS_PASSWORD_MIN_LENGTH;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admins</title>
    <?php ps_head(); psPlatformPageCss(); ?>
</head>
<body class="ps-app">
<?php ps_open('Super Admins'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Super Admins</h1>
        <p class="ps-page-lead"><?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?> staff who can add companies and, where a client has agreed, manage them. Give each person their own account; lock it the day they leave.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>Name</th><th>Signs in as</th><th>Phone</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $locked = psSecurityUserIsLocked($r); ?>
            <tr>
                <td><?php echo $h($r['firstname'] . ' ' . $r['lastname']); ?><?php if ((int)$r['id'] === $me): ?> <span class="ps-badge ps-badge-secondary">You</span><?php endif; ?></td>
                <td><?php echo $h($r['email']); ?></td>
                <td><?php echo $h($r['phonenumber']); ?></td>
                <td><?php echo $locked ? '<span class="ps-badge ps-badge-destructive">Locked</span>' : '<span class="ps-badge ps-badge-success">Active</span>'; ?></td>
                <td><div class="pf-row-actions">
                    <?php if ((int)$r['id'] !== $me): ?>
                    <form method="post" action="platform_admins.php"><?php echo psCsrfField(); ?>
                        <input type="hidden" name="user_id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="action" value="<?php echo $locked ? 'unlock' : 'lock'; ?>">
                        <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"><?php echo $locked ? 'Unlock' : 'Lock'; ?></button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>

    <div class="pf-cols">
        <div class="ps-card">
            <div class="ps-card-header"><h2 class="ps-card-title">Add a Super Admin</h2></div>
            <div class="ps-card-content">
                <form method="post" action="platform_admins.php" class="pf-form" autocomplete="off">
                    <?php echo psCsrfField(); ?><input type="hidden" name="action" value="create">
                    <div class="pf-grid">
                        <label class="ps-field"><span class="ps-label">First name</span><input class="ps-input" name="firstname" required value="<?php echo $v('firstname'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Last name</span><input class="ps-input" name="lastname" required value="<?php echo $v('lastname'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Email or username</span><input class="ps-input" name="login_id" required value="<?php echo $v('login_id'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Phone</span><input class="ps-input" name="phonenumber" value="<?php echo $v('phonenumber'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Password</span><input class="ps-input" type="password" name="password" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                        <label class="ps-field"><span class="ps-label">Confirm password</span><input class="ps-input" type="password" name="password2" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                    </div>
                    <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Add Super Admin</button></div>
                </form>
            </div>
        </div>
        <div class="ps-card">
            <div class="ps-card-header"><h2 class="ps-card-title">Reset a Super Admin's password</h2></div>
            <div class="ps-card-content">
                <form method="post" action="platform_admins.php" class="pf-form" autocomplete="off">
                    <?php echo psCsrfField(); ?><input type="hidden" name="action" value="password">
                    <div class="pf-grid">
                        <label class="ps-field pf-span"><span class="ps-label">Account</span>
                            <select class="ps-input" name="user_id"><?php foreach ($rows as $r): ?><option value="<?php echo (int)$r['id']; ?>"><?php echo $h($r['email']); ?></option><?php endforeach; ?></select></label>
                        <label class="ps-field pf-span"><span class="ps-label">New password</span><input class="ps-input" type="password" name="new_password" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                        <label class="ps-field pf-span"><span class="ps-label">Confirm password</span><input class="ps-input" type="password" name="new_password2" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                    </div>
                    <div class="pf-actions"><button class="ps-btn ps-btn-outline" type="submit">Reset password</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
