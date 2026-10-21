<?php
/**
 * Phase 14 — Login with password_hash/verify, session regeneration, legacy plaintext upgrade.
 */
require_once __DIR__ . '/includes/security.php';
require __DIR__ . '/pockets_config.php';

psSecurityStartSession();
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('login: connection failed');
    $error_message = 'Login is temporarily unavailable.';
    $conn = null;
} else {
    $conn->set_charset('utf8mb4');
    psSecurityEnsureUsersPasswordColumn($conn);
}

$error_message = $error_message ?? null;

// Always mint a CSRF token for the login form (GET and failed POST re-render).
psCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    // Phase 16: require CSRF on login POST (form always includes token after GET).
    if (!psCsrfValidate()) {
        $error_message = 'Invalid security token. Reload and try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $plain = (string)($_POST['password'] ?? '');

        // Health-check 2026-10-13: brute-force throttle (5 fails per email / 20 per IP in 15 min).
        $throttle = psSecurityLoginThrottle($conn, $email);
        if (!empty($throttle['blocked'])) {
            psSecurityAudit($conn, 'LOGIN_THROTTLED', [
                'actor' => $email,
                'summary' => 'Login temporarily blocked after repeated failures',
                'target_type' => 'user',
                'target_id' => $email,
            ]);
            $error_message = 'Too many failed attempts. Try again in ' . (int)$throttle['minutes'] . ' minutes.';
            $look = ['ok' => false];
        } else {
            $look = psSecurityLookupUserByEmail($conn, $email);
        }
        $verified = ['ok' => false, 'needs_rehash' => false];
        if ($error_message !== null) {
            // throttled — do not verify
        } elseif (!empty($look['ok'])) {
            $verified = psSecurityVerifyPassword((string)($look['user']['password'] ?? ''), $plain);
        } else {
            // Uniform failure path (avoid user enumeration beyond locked message).
            if (isset($look['error']) && stripos((string)$look['error'], 'locked') !== false) {
                psSecurityAudit($conn, 'LOGIN_DENIED', [
                    'actor' => $email,
                    'summary' => 'Locked account login attempt',
                    'target_type' => 'user',
                    'target_id' => $email,
                ]);
                $error_message = 'This account is locked.';
            }
        }

        if ($error_message === null && !empty($look['ok']) && !empty($verified['ok'])
            && !psPlatformIsSuperAdminLevel((string)($look['user']['level'] ?? ''))
            && psPlatformCompanyIsSuspended($conn, (string)($look['user']['company_name'] ?? ''))) {
            // Phase 18: suspended company — correct password, but no sign-in.
            psSecurityAudit($conn, 'LOGIN_DENIED', [
                'actor' => (string)$look['user']['email'],
                'summary' => 'Company suspended',
                'target_type' => 'user',
                'target_id' => (string)$look['user']['email'],
            ]);
            $error_message = 'This company account is suspended. Contact ' . PS_PLATFORM_PROVIDER_NAME . '.';
        }

        if ($error_message === null && !empty($look['ok']) && !empty($verified['ok'])) {
            if (!empty($verified['needs_rehash'])) {
                psSecurityUpgradePasswordHash($conn, (int)$look['user']['id'], $plain);
            }
            psSecurityEstablishLoginSession($look['user']);
            psSecurityAudit($conn, 'LOGIN_SUCCESS', [
                'actor' => (string)$look['user']['email'],
                'summary' => 'Web login success',
                'target_type' => 'user',
                'target_id' => (string)$look['user']['email'],
            ]);
            $level = strtolower(trim((string)($look['user']['level'] ?? '')));
            if (psPlatformIsSuperAdminLevel($level)) {
                header('Location: platform.php');
            } elseif ($level === 'admin') {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: ajax_pos.php');
            }
            $conn->close();
            exit;
        }

        if ($error_message === null) {
            psSecurityAudit($conn, 'LOGIN_FAIL', [
                'actor' => $email,
                'summary' => 'Invalid credentials',
                'target_type' => 'user',
                'target_id' => $email,
            ]);
            $error_message = 'Invalid email/username or password.';
        }
    }
}

if ($conn) {
    $conn->close();
}

// Ensure CSRF token exists for the form.
psCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); echo psCsrfMeta(); ?>
</head>
<body class="ps-login">
    <div class="ps-login-split">
        <section class="ps-login-hero" aria-label="Pockets ICT Solutions">
            <img class="ps-login-logo" src="assets/pockets-logo.png" alt="Pockets ICT Solutions">
            <h1>Hello<br>Pockets!</h1>
            <p>Ring up sales, track stock, and keep every receipt in one point of sale.</p>
            <p class="ps-login-copy">Pockets ICT Solutions</p>
        </section>
        <section class="ps-login-panel">
            <div class="ps-login-tools"><?php ps_theme_switch(); ?></div>
            <div class="ps-login-card">
                <div class="ps-login-wordmark">
                    <img src="assets/pockets-logo.png" alt="">
                    <span>Pockets ICT Solutions</span>
                </div>
                <h2>Welcome back</h2>
                <form method="post" action="">
                    <?php echo psCsrfField(); ?>
                    <label class="ps-login-field">
                        <span>Email</span>
                        <input type="text" name="email" placeholder="Email or username" required autocomplete="username">
                    </label>
                    <label class="ps-login-field">
                        <span>Password</span>
                        <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                    </label>
                    <input type="submit" value="Login Now">
                </form>
<?php
if (isset($_GET['suspended'])) {
    echo '<div class="error">This company account is suspended. Contact ' . htmlspecialchars(PS_PLATFORM_PROVIDER_NAME) . '.</div>';
}
if (isset($_GET['expired'])) {
    echo '<div class="error">Your session expired due to inactivity. Please log in again.</div>';
}
?>
                <?php if (isset($error_message)): ?>
                    <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php ps_scripts(); ?>
</body>
</html>
