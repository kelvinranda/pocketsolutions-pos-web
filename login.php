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
    <title>Sign in · Pockets</title>
    <?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); echo psCsrfMeta(); ?>
    <meta name="theme-color" content="#14161d">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Wix+Madefor+Display:wght@400;500;600&family=Wix+Madefor+Text:wght@400;500;600&display=swap" rel="stylesheet">
    <?php $psDuskCss = __DIR__ . '/assets/login/dusk.css'; ?>
    <link rel="stylesheet" href="assets/login/dusk.css?v=<?php echo rawurlencode(is_file($psDuskCss) ? (string)filemtime($psDuskCss) : '1'); ?>">
</head>
<?php
// Hallmark Dusk login (2026-11-04): markup and styles only; auth, CSRF, session and throttle logic above are unchanged.
$psLoginNotices = [];
if (isset($_GET['suspended'])) {
    $psLoginNotices[] = 'This company account is suspended. Contact ' . PS_PLATFORM_PROVIDER_NAME . '.';
}
if (isset($_GET['expired'])) {
    $psLoginNotices[] = 'Your session expired due to inactivity. Please log in again.';
}
if (isset($error_message)) {
    $psLoginNotices[] = (string)$error_message;
}
?>
<body class="hm-dusk">
    <div class="dusk">
        <figure class="dusk__photo" aria-hidden="true">
            <img src="assets/login/dusk-valley.jpg" alt="" width="1920" height="1254" decoding="async" fetchpriority="high">
        </figure>
        <div class="dusk__scrim" aria-hidden="true"></div>

        <header class="dusk__bar">
            <div class="dusk__wrap">
                <span class="dusk__brand">Pockets ICT Solutions</span>
                <?php ps_theme_switch(); ?>
            </div>
        </header>

        <main class="dusk__wrap dusk__stage">
            <section class="dusk__head" aria-labelledby="dusk-title">
                <img class="dusk__logo" src="assets/login/pockets-wordmark.png" alt="Pockets ICT Solutions" width="786" height="345">
                <h1 id="dusk-title">Hello Pockets!</h1>
                <p>Ring up sales, track stock, and keep every receipt in one point of sale.</p>
            </section>

            <section class="signin glass" aria-labelledby="signin-title">
                <h2 id="signin-title">Welcome back</h2>
                <p class="signin__lead">Sign in with your email or username.</p>

                <?php foreach ($psLoginNotices as $psNotice): ?>
                <div class="notice" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5"/><path d="M12 16.2v.3"/></svg>
                    <span><?php echo htmlspecialchars($psNotice); ?></span>
                </div>
                <?php endforeach; ?>

                <form method="post" action="" data-dusk-signin>
                    <?php echo psCsrfField(); ?>
                    <label class="field">
                        <span class="field__label">Email or username</span>
                        <input type="text" name="email" placeholder="you@company.com" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?php echo htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?php echo isset($_POST['email']) ? '' : ' autofocus'; ?>>
                    </label>
                    <label class="field">
                        <span class="field__label">Password</span>
                        <input type="password" name="password" placeholder="Password" required autocomplete="current-password"<?php echo isset($_POST['email']) ? ' autofocus' : ''; ?>>
                    </label>
                    <button type="submit" class="btn">
                        <span class="btn__spinner" aria-hidden="true"></span>
                        <span class="btn__text">Sign in</span>
                    </button>
                </form>
            </section>
        </main>

        <footer class="dusk__wrap dusk__foot">
            <span>Pockets ICT Solutions</span>
            <span>Photo: <a href="https://commons.wikimedia.org/wiki/File:Vallee-brouillard.jpg" target="_blank" rel="noopener">Thomas Bresson</a>, <a href="https://creativecommons.org/licenses/by/3.0/" target="_blank" rel="noopener">CC BY 3.0</a></span>
        </footer>
    </div>
    <?php ps_scripts(); ?>
    <script>
    (function () {
        var form = document.querySelector('[data-dusk-signin]');
        if (!form) return;
        var btn = form.querySelector('button[type="submit"]');
        var label = btn && btn.querySelector('.btn__text');
        form.addEventListener('submit', function () {
            if (!btn) return;
            btn.setAttribute('aria-busy', 'true');
            btn.disabled = true;
            if (label) label.textContent = 'Signing in';
        });
        window.addEventListener('pageshow', function () {
            if (!btn) return;
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
            if (label) label.textContent = 'Sign in';
        });
    })();
    </script>
</body>
</html>
