<?php
/**
 * Phase 14 — Authenticated password change (self-service).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('change_password: connection failed');
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');
psSecurityEnsureUsersPasswordColumn($conn);

$message = '';
$error = '';
$email = trim((string)($_SESSION['logger'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    psCsrfRequire('html');
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($new) < PS_PASSWORD_MIN_LENGTH) {
        $error = 'New password must be at least ' . PS_PASSWORD_MIN_LENGTH . ' characters.';
    } elseif ($new === PS_DEFAULT_RESET_PASSWORD) {
        $error = 'Choose a password other than the default one you were given.';
    } elseif ($new !== $confirm) {
        $error = 'New password confirmation does not match.';
    } else {
        $look = psSecurityLookupUserByEmail($conn, $email);
        if (empty($look['ok'])) {
            $error = 'Please sign in again.';
        } else {
            $v = psSecurityVerifyPassword((string)$look['user']['password'], $current);
            if (empty($v['ok'])) {
                $error = 'Current password is incorrect.';
                psSecurityAudit($conn, 'PASSWORD_CHANGE_FAIL', [
                    'summary' => 'Current password mismatch',
                    'target_type' => 'user',
                    'target_id' => $email,
                ]);
            } elseif (!psSecurityUpgradePasswordHash($conn, (int)$look['user']['id'], $new)) {
                $error = 'Password could not be updated.';
            } else {
                // 2026-11-06: the default password from a reset is replaced: the user can use the POS again.
                psSecuritySetMustChangePassword($conn, (int)$look['user']['id'], false);
                unset($_SESSION['ps_must_change_pw']);
                session_regenerate_id(true);
                psCsrfRotate();
                psSecurityAudit($conn, 'PASSWORD_CHANGE', [
                    'summary' => 'Password changed by user',
                    'target_type' => 'user',
                    'target_id' => $email,
                ]);
                $message = 'Password updated.';
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <?php ps_head(); echo psCsrfMeta(); ?>
</head>
<body class="ps-app">
<?php ps_open('Change Password'); ?>
<div class="ps-narrow">
    <h1>Change Password</h1>
    <?php if (!empty($_SESSION['ps_must_change_pw'])): ?>
    <div class="ps-alert ps-alert-warning" role="alert">Your password was reset to the default. Choose your own password to continue: enter the default one you were given as the current password.</div>
    <?php endif; ?>
    <p class="ps-page-lead">Update the password for <?php echo htmlspecialchars($email !== '' ? $email : 'your account', ENT_QUOTES, 'UTF-8'); ?>. You will stay signed in after a successful change.</p>

    <?php if ($message !== ''): ?>
        <div class="ps-alert ps-alert-success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title">Account password</h2>
            <p class="ps-card-desc">Use at least <?php echo PS_PASSWORD_MIN_LENGTH; ?> characters. Confirm the new password before saving.</p>
        </div>
        <div class="ps-card-content">
            <form method="post" action="" class="ps-form-stack" autocomplete="off">
                <?php echo psCsrfField(); ?>
                <div class="ps-field">
                    <label class="ps-label" for="current_password">Current password</label>
                    <input class="ps-input" id="current_password" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="ps-field">
                    <label class="ps-label" for="new_password">New password</label>
                    <input class="ps-input" id="new_password" type="password" name="new_password" required minlength="<?php echo PS_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                    <span class="ps-helper">Minimum <?php echo PS_PASSWORD_MIN_LENGTH; ?> characters.</span>
                </div>
                <div class="ps-field">
                    <label class="ps-label" for="confirm_password">Confirm new password</label>
                    <input class="ps-input" id="confirm_password" type="password" name="confirm_password" required minlength="<?php echo PS_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password">
                </div>
                <div class="ps-form-actions">
                    <button class="ps-btn ps-btn-default" type="submit">Update password</button>
                    <a class="ps-btn ps-btn-ghost" href="javascript:history.back()">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
