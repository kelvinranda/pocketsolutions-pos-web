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
        <header class="dusk__bar">
            <div class="dusk__wrap">
                <img class="dusk__logo" src="assets/login/pockets-wordmark.png" alt="Pockets ICT Solutions" width="786" height="345">
                <?php ps_theme_switch(); ?>
            </div>
        </header>

        <main class="dusk__wrap dusk__stage">
            <section class="dusk__head" aria-labelledby="dusk-title">
                <h1 id="dusk-title">Sell, stock and close the day.</h1>
                <p>Ring up sales, track stock, and keep every receipt in one point of sale.</p>
            </section>

            <!-- Product preview: a built replica of the Pockets till with demo items (no real company,
                 user or stock data). It uses the page's colour tokens, so it follows Light/Dark. -->
            <figure class="till glass" role="img" aria-label="The Pockets till: product cards on the left, the cart with its total and the Print / Submit button on the right.">
                <div class="till__menu" aria-hidden="true">
                    <div class="till__cats">
                        <span class="till__cat till__cat--all is-on">All items</span>
                        <span class="till__cat till__cat--groc">Groceries</span>
                        <span class="till__cat till__cat--drink">Drinks</span>
                    </div>
                    <div class="till__grid">
                        <div class="till__card"><span class="till__name">Bread loaf</span><span class="till__stock">Stock 48</span><span class="till__foot"><span class="till__price">$1.20</span><span class="till__step"><span>−</span><span class="till__qty">2</span><span>+</span></span></span></div>
                        <div class="till__card"><span class="till__name">Cooking oil 2L</span><span class="till__stock">Stock 16</span><span class="till__foot"><span class="till__price">$4.50</span><span class="till__step"><span>−</span><span class="till__qty">1</span><span>+</span></span></span></div>
                        <div class="till__card"><span class="till__name">Sugar 2kg</span><span class="till__stock">Stock 30</span><span class="till__foot"><span class="till__price">$2.80</span><span class="till__step"><span>−</span><span class="till__qty">0</span><span>+</span></span></span></div>
                        <div class="till__card"><span class="till__name">Soft drink 500ml</span><span class="till__stock">Stock 120</span><span class="till__foot"><span class="till__price">$0.80</span><span class="till__step"><span>−</span><span class="till__qty">3</span><span>+</span></span></span></div>
                    </div>
                </div>
                <div class="till__order" aria-hidden="true">
                    <div class="till__currency"><span class="is-on">USD</span><span>ZWG</span></div>
                    <div class="till__line"><span>Bread loaf</span><span class="till__x">×2</span><span class="till__amt">$2.40</span></div>
                    <div class="till__line"><span>Cooking oil 2L</span><span class="till__x">×1</span><span class="till__amt">$4.50</span></div>
                    <div class="till__line"><span>Soft drink 500ml</span><span class="till__x">×3</span><span class="till__amt">$2.40</span></div>
                    <div class="till__total"><span>Total</span><span class="till__amt">$9.30</span></div>
                    <span class="till__pay">Print / Submit</span>
                </div>
            </figure>

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

                <div class="signin__new">
                    <p>New to Pockets? Call or WhatsApp our team.</p>
                    <div class="signin__contact">
                        <!-- Without JavaScript this is a plain tel: link; with it, it opens #callDialog -->
                        <a class="ghost" href="tel:+263779850816" data-open-dialog="callDialog" aria-haspopup="dialog">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 3.5h2.6l1.4 4.2-2 1.5a12 12 0 0 0 6.2 6.2l1.5-2 4.2 1.4v2.6a2 2 0 0 1-2.1 2A16.5 16.5 0 0 1 4.6 5.6a2 2 0 0 1 2-2.1z"/></svg>
                            Call
                        </a>
                        <a class="ghost" href="https://wa.me/263779850816?text=Hi%2C%20I%27d%20like%20to%20know%20more%20about%20Pockets%20POS." target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l1.3-4A8 8 0 1 1 8.2 19z"/><path d="M9 9.2c.2 2.3 1.6 4 3.8 4.9l1-1 1.7.8c-.2 1-1 1.6-2 1.6-3 0-5.6-2.6-5.6-5.6 0-1 .6-1.8 1.6-2l.8 1.7z"/></svg>
                            WhatsApp
                        </a>
                    </div>
                </div>
            </section>

            <ul class="caps" aria-label="What Pockets does">
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v18l-2.5-1.5L12 21l-2.5-1.5L7 21z"/><path d="M10 8h4M10 12h4"/></svg>
                    <span><strong>ZIMRA fiscal receipts</strong>Fiscal day and receipts handled at the till</span>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M14.5 9.5c-.4-.9-1.4-1.5-2.5-1.5-1.5 0-2.5.8-2.5 2s1 1.6 2.5 2 2.5.9 2.5 2-1 2-2.5 2c-1.1 0-2.1-.6-2.5-1.5M12 6.5V8M12 16v1.5"/></svg>
                    <span><strong>USD and ZWG</strong>Sell in either currency with your own rates</span>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10l8-5 8 5v9H4z"/><path d="M9 19v-5h6v5"/></svg>
                    <span><strong>Every branch's stock</strong>Receiving, adjustments and stock takes</span>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="13" height="10" rx="1.5"/><path d="M7 18.5h5"/><rect x="17.5" y="9" width="4" height="10" rx="1"/></svg>
                    <span><strong>Web, desktop and mobile tills</strong>One back office for every till</span>
                </li>
            </ul>
        </main>

        <footer class="dusk__wrap dusk__foot">
            <span>Pockets ICT Solutions</span>
        </footer>
    </div>

    <dialog class="modal" id="callDialog" aria-labelledby="callDialogTitle" aria-describedby="callDialogDesc">
        <form method="dialog" class="modal__close-form">
            <button class="modal__close" type="submit" aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </form>
        <h2 id="callDialogTitle">Call the Pockets team</h2>
        <p id="callDialogDesc">Ask about Pockets POS for your business. This number takes calls and WhatsApp.</p>
        <a class="modal__number" href="tel:+263779850816">+263 77 985 0816</a>
        <div class="modal__actions">
            <button class="ghost" type="button" data-copy="+263779850816">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                <span data-copy-label>Copy number</span>
            </button>
            <a class="btn modal__primary" href="tel:+263779850816">Call now</a>
        </div>
        <p class="modal__status" role="status" aria-live="polite"></p>
    </dialog>
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

    // Login modals: native <dialog> (focus trap, Esc to close) plus a click on the backdrop to close.
    (function () {
        document.querySelectorAll('[data-open-dialog]').forEach(function (trigger) {
            var dlg = document.getElementById(trigger.getAttribute('data-open-dialog'));
            if (!dlg || typeof dlg.showModal !== 'function') return; // old browser: keep the tel: link
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                dlg.showModal();
            });
        });
        document.querySelectorAll('dialog.modal').forEach(function (dlg) {
            dlg.addEventListener('click', function (e) {
                if (e.target === dlg) dlg.close(); // click outside the panel
            });
            dlg.addEventListener('close', function () {
                var status = dlg.querySelector('.modal__status');
                if (status) status.textContent = '';
            });
        });
        document.querySelectorAll('[data-copy]').forEach(function (b) {
            b.addEventListener('click', function () {
                var text = b.getAttribute('data-copy');
                var lbl = b.querySelector('[data-copy-label]');
                var status = b.closest('dialog') && b.closest('dialog').querySelector('.modal__status');
                var done = function (ok) {
                    if (lbl) lbl.textContent = ok ? 'Copied' : 'Copy number';
                    if (status) status.textContent = ok ? 'Number copied.' : 'Copy failed. Select the number above instead.';
                    setTimeout(function () { if (lbl) lbl.textContent = 'Copy number'; }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
                } else {
                    done(false);
                }
            });
        });
    })();
    </script>
</body>
</html>
