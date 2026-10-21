<?php
/**
 * Phase 14 — Security helpers (authn/session, CSRF, password, security audit).
 *
 * Authz for branches remains in branch_access.php (Phase 9). CSRF is independent of authz.
 * Security audit storage is append-oriented application logging — not claimed tamper-proof.
 * Cookie Secure flag is applied only when the request is HTTPS (deployment-dependent).
 */

if (!defined('PS_SECURITY_LOADED')) {
    define('PS_SECURITY_LOADED', 1);
}

require_once __DIR__ . '/platform.php';

/** Session inactivity timeout (seconds). Matches prior session_check.php. */
if (!defined('PS_SESSION_TIMEOUT')) {
    define('PS_SESSION_TIMEOUT', 600);
}

/** Minimum password length for new / changed passwords (2026-10-15: 6, business decision). */
if (!defined('PS_PASSWORD_MIN_LENGTH')) {
    define('PS_PASSWORD_MIN_LENGTH', 6);
}

/**
 * Login ID stored in users.email: a valid email address, or a username (for staff without email)
 * of 3–50 letters, digits, dot, dash or underscore. Case-insensitive uniqueness is checked by callers.
 */
function psSecurityValidateLoginId(string $raw): array
{
    $id = trim($raw);
    if ($id === '') {
        return ['ok' => false, 'error' => 'Enter an email address or a username.'];
    }
    if (strpos($id, '@') !== false) {
        return filter_var($id, FILTER_VALIDATE_EMAIL)
            ? ['ok' => true, 'login_id' => $id, 'is_email' => true]
            : ['ok' => false, 'error' => 'That email address is not valid.'];
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $id)) {
        return ['ok' => false, 'error' => 'A username must be 3–50 letters or numbers (dot, dash and underscore allowed), with no spaces.'];
    }
    return ['ok' => true, 'login_id' => $id, 'is_email' => false];
}

/** Allowed web user levels for create/update (case-preserved for storage of canonical form). */
function psSecurityAllowedLevels(): array
{
    // Phase 17: Supervisor + Management (role names compared case-insensitively everywhere).
    return ['admin', 'Till', 'Admin', 'Supervisor', 'Management'];
}

function psSecurityNormalizeLevel(string $raw): string
{
    $raw = trim($raw);
    if (strcasecmp($raw, 'admin') === 0) {
        return 'admin';
    }
    if (strcasecmp($raw, 'till') === 0) {
        return 'Till';
    }
    if (strcasecmp($raw, 'supervisor') === 0) {
        return 'Supervisor';
    }
    if (strcasecmp($raw, 'management') === 0) {
        return 'Management';
    }
    return $raw;
}

function psSecurityLevelIsAllowed(string $raw): bool
{
    $n = psSecurityNormalizeLevel($raw);
    foreach (psSecurityAllowedLevels() as $allowed) {
        if (strcasecmp($allowed, $n) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Configure session cookie params before session_start when possible.
 * httponly always; samesite Lax; secure only on HTTPS.
 */
function psSecurityConfigureSessionCookie(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function psSecurityStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    psSecurityConfigureSessionCookie();
    session_start();
}

function psSecurityIsHashedPassword(string $stored): bool
{
    if ($stored === '') {
        return false;
    }
    $info = password_get_info($stored);
    return isset($info['algo']) && $info['algo'] !== 0 && $info['algo'] !== null;
}

/**
 * Verify password against stored value. Supports bcrypt/argon hashes and legacy plaintext.
 * Empty stored or empty candidate always fails.
 *
 * @return array{ok:bool,needs_rehash:bool}
 */
function psSecurityVerifyPassword(string $stored, string $candidate): array
{
    if ($candidate === '' || $stored === '') {
        return ['ok' => false, 'needs_rehash' => false];
    }
    if (psSecurityIsHashedPassword($stored)) {
        $ok = password_verify($candidate, $stored);
        $needs = $ok && password_needs_rehash($stored, PASSWORD_DEFAULT);
        return ['ok' => $ok, 'needs_rehash' => $needs];
    }
    // Legacy plaintext (timing-safe length-aware compare).
    if (!hash_equals($stored, $candidate)) {
        return ['ok' => false, 'needs_rehash' => false];
    }
    return ['ok' => true, 'needs_rehash' => true];
}

function psSecurityHashPassword(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * Ensure password column can hold bcrypt hashes (legacy installs may be short VARCHAR).
 */
function psSecurityEnsureUsersPasswordColumn(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'password'");
    if (!$res || mysqli_num_rows($res) === 0) {
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    $type = strtolower((string)($row['Type'] ?? ''));
    if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int)$m[1] >= 255) {
        return true;
    }
    if (strpos($type, 'text') !== false) {
        return true;
    }
    // Widen safely — does not rewrite password values.
    return (bool)mysqli_query($conn, 'ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
}

function psSecurityUserIsLocked(array $row): bool
{
    $lock = strtolower(trim((string)($row['lock_status'] ?? '')));
    return $lock === 'locked';
}

/**
 * @return array{ok:bool,user?:array,error?:string}
 */
function psSecurityLookupUserByEmail(mysqli $conn, string $email): array
{
    $email = trim($email);
    if ($email === '') {
        return ['ok' => false, 'error' => 'Invalid email/username or password.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, email, password, level, branch, company_name, status, lock_status
         FROM users WHERE email = ? LIMIT 1'
    );
    if (!$stmt) {
        error_log('security: user lookup prepare failed');
        return ['ok' => false, 'error' => 'Login failed.'];
    }
    mysqli_stmt_bind_param($stmt, 's', $email);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('security: user lookup execute failed');
        return ['ok' => false, 'error' => 'Login failed.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid email/username or password.'];
    }
    if (psSecurityUserIsLocked($row)) {
        return ['ok' => false, 'error' => 'This account is locked.'];
    }
    return ['ok' => true, 'user' => $row];
}

function psSecurityUpgradePasswordHash(mysqli $conn, int $userId, string $plain): bool
{
    if ($userId < 1 || $plain === '') {
        return false;
    }
    psSecurityEnsureUsersPasswordColumn($conn);
    $hash = psSecurityHashPassword($plain);
    $stmt = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE id = ? LIMIT 1');
    if (!$stmt) {
        error_log('security: password upgrade prepare failed');
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        error_log('security: password upgrade failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Count active users by password storage class.
 * "plaintext" = non-empty and not a recognized password_hash algorithm.
 * Empty passwords are counted separately (cannot authenticate; cannot migrate safely).
 *
 * @return array{total:int,hashed:int,plaintext:int,empty:int}
 */
function psSecurityPasswordStorageCounts(mysqli $conn): array
{
    $out = ['total' => 0, 'hashed' => 0, 'plaintext' => 0, 'empty' => 0];
    $res = mysqli_query($conn, 'SELECT id, password FROM users');
    if (!$res) {
        error_log('security: password storage count query failed');
        return $out;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $out['total']++;
        $stored = (string)($row['password'] ?? '');
        if ($stored === '') {
            $out['empty']++;
        } elseif (psSecurityIsHashedPassword($stored)) {
            $out['hashed']++;
        } else {
            $out['plaintext']++;
        }
    }
    return $out;
}

/**
 * One-time / idempotent migration: hash legacy plaintext passwords in place.
 * Does not re-hash existing hashes. Does not invent passwords for empty rows.
 * Preserves lock_status, level, branch, identity columns. Never logs password values.
 *
 * @return array{
 *   ok:bool,
 *   migrated:int,
 *   already_hashed:int,
 *   skipped_empty:int,
 *   failed:int,
 *   plaintext_remaining:int,
 *   empty_remaining:int,
 *   issues:list<array{user_id:int,issue:string}>,
 *   error?:string
 * }
 */
function psSecurityMigratePlaintextPasswords(mysqli $conn): array
{
    $result = [
        'ok' => false,
        'migrated' => 0,
        'already_hashed' => 0,
        'skipped_empty' => 0,
        'failed' => 0,
        'plaintext_remaining' => -1,
        'empty_remaining' => -1,
        'issues' => [],
    ];
    if (!psSecurityEnsureUsersPasswordColumn($conn)) {
        $result['error'] = 'users.password column could not be prepared for hashes.';
        return $result;
    }
    $res = mysqli_query($conn, 'SELECT id, password, lock_status, level, branch, email FROM users ORDER BY id ASC');
    if (!$res) {
        error_log('security: plaintext migration select failed');
        $result['error'] = 'Could not read users for password migration.';
        return $result;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $userId = (int)($row['id'] ?? 0);
        $stored = (string)($row['password'] ?? '');
        if ($userId < 1) {
            $result['failed']++;
            $result['issues'][] = ['user_id' => 0, 'issue' => 'invalid_user_id'];
            continue;
        }
        if ($stored === '') {
            // Fail-safe: do not invent a credential; empty cannot authenticate.
            $result['skipped_empty']++;
            $result['issues'][] = [
                'user_id' => $userId,
                'issue' => 'empty_password_cannot_migrate',
            ];
            continue;
        }
        if (psSecurityIsHashedPassword($stored)) {
            $result['already_hashed']++;
            continue;
        }
        // Legacy plaintext: hash the existing credential without changing it.
        if (!psSecurityUpgradePasswordHash($conn, $userId, $stored)) {
            $result['failed']++;
            $result['issues'][] = [
                'user_id' => $userId,
                'issue' => 'hash_write_failed',
            ];
            continue;
        }
        // Verify round-trip: hash detects as hashed; plaintext verify still works.
        $check = mysqli_prepare($conn, 'SELECT password FROM users WHERE id = ? LIMIT 1');
        if (!$check) {
            $result['failed']++;
            $result['issues'][] = ['user_id' => $userId, 'issue' => 'post_verify_prepare_failed'];
            continue;
        }
        mysqli_stmt_bind_param($check, 'i', $userId);
        mysqli_stmt_execute($check);
        $cr = mysqli_stmt_get_result($check);
        $crow = $cr ? mysqli_fetch_assoc($cr) : null;
        mysqli_stmt_close($check);
        $newStored = (string)($crow['password'] ?? '');
        if (!psSecurityIsHashedPassword($newStored)) {
            $result['failed']++;
            $result['issues'][] = ['user_id' => $userId, 'issue' => 'post_verify_not_hashed'];
            continue;
        }
        $v = psSecurityVerifyPassword($newStored, $stored);
        if (empty($v['ok'])) {
            $result['failed']++;
            $result['issues'][] = ['user_id' => $userId, 'issue' => 'post_verify_mismatch'];
            continue;
        }
        $result['migrated']++;
    }
    $counts = psSecurityPasswordStorageCounts($conn);
    $result['plaintext_remaining'] = (int)$counts['plaintext'];
    $result['empty_remaining'] = (int)$counts['empty'];
    // Success requires zero non-empty plaintext remaining and zero hash failures.
    $result['ok'] = ($result['failed'] === 0 && $result['plaintext_remaining'] === 0);
    if ($result['ok']) {
        psSecurityAudit($conn, 'PASSWORD_MIGRATION', [
            'actor' => 'system',
            'source' => 'cli',
            'summary' => 'Plaintext password migration completed',
            'after' => [
                'migrated' => $result['migrated'],
                'already_hashed' => $result['already_hashed'],
                'skipped_empty' => $result['skipped_empty'],
                'plaintext_remaining' => $result['plaintext_remaining'],
                'empty_remaining' => $result['empty_remaining'],
            ],
        ]);
    }
    return $result;
}

/**
 * Establish authenticated web session after successful credential check.
 * Regenerates session id; sets CSRF; does not trust posted role/branch.
 */
function psSecurityEstablishLoginSession(array $user): void
{
    psSecurityStartSession();
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION = [];
    $_SESSION['logger'] = (string)$user['email'];
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['level'] = (string)($user['level'] ?? '');
    $_SESSION['branch'] = trim((string)($user['branch'] ?? ''));
    $_SESSION['the_company'] = (string)($user['company_name'] ?? '');
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SESSION['ps_auth_at'] = time();
    unset($_SESSION['selected_branch']);
    if (psPlatformIsSuperAdminLevel((string)($user['level'] ?? ''))) {
        // Phase 18: Super Admin starts on the platform console (no company, no branch).
        psPlatformApplySession(null, $user);
    }
    psCsrfRotate();
}

function psSecurityDestroySession(): void
{
    psSecurityStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Re-validate session against live users row.
 * Syncs level/branch/company from DB (source of truth). Does not invent privileges.
 *
 * @return array{ok:bool,error?:string,reason?:string}
 */
function psSecurityValidateSessionUser(mysqli $conn): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['ok' => false, 'error' => 'Please sign in again.', 'reason' => 'no_session'];
    }
    $email = trim((string)($_SESSION['logger'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'error' => 'Please sign in again.', 'reason' => 'unauthenticated'];
    }
    $look = psSecurityLookupUserByEmail($conn, $email);
    if (empty($look['ok'])) {
        $reason = (isset($look['error']) && stripos((string)$look['error'], 'locked') !== false)
            ? 'locked'
            : 'missing';
        return ['ok' => false, 'error' => 'Please sign in again.', 'reason' => $reason];
    }
    $user = $look['user'];
    $dbLevel = (string)($user['level'] ?? '');
    $isSuper = psPlatformIsSuperAdminLevel($dbLevel);
    $wasSuper = psPlatformIsSuperAdminLevel((string)($_SESSION['ps_real_level'] ?? $_SESSION['level'] ?? ''));
    $sessLevel = $wasSuper ? PS_PLATFORM_LEVEL : (string)($_SESSION['level'] ?? '');
    $levelChanged = strcasecmp(trim($isSuper ? PS_PLATFORM_LEVEL : $dbLevel), trim($sessLevel)) !== 0;

    // Phase 18: users of a suspended company are signed out.
    if (!$isSuper && psPlatformCompanyIsSuspended($conn, (string)($user['company_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'This company account is suspended. Contact ' . PS_PLATFORM_PROVIDER_NAME . '.', 'reason' => 'suspended'];
    }

    $_SESSION['user_id'] = (int)$user['id'];
    if ($isSuper) {
        // Effective level/company: console, or the managed company's admin (psPlatformApplySession).
        psPlatformApplySession($conn, $user);
    } else {
        unset($_SESSION['ps_real_level'], $_SESSION['ps_platform']);
        $_SESSION['level'] = $dbLevel;
        $_SESSION['branch'] = trim((string)($user['branch'] ?? ''));
        $_SESSION['the_company'] = (string)($user['company_name'] ?? '');
    }

    if ($levelChanged) {
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        psCsrfRotate();
        psSecurityAudit($conn, 'SESSION_PRIVILEGE_SYNC', [
            'target' => $email,
            'summary' => 'Session level refreshed from database',
        ]);
    }
    return ['ok' => true];
}

function psSecurityTouchActivity(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['LAST_ACTIVITY'] = time();
    }
}

function psSecurityActivityExpired(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    if (!isset($_SESSION['LAST_ACTIVITY'])) {
        return false;
    }
    return (time() - (int)$_SESSION['LAST_ACTIVITY']) > PS_SESSION_TIMEOUT;
}

/**
 * Phase 16 — Shared web session gate (timeout + optional live lock/privilege revalidation).
 * Does not apply to mobile/API Bearer flows.
 *
 * @param mysqli|null $conn When provided, revalidate locked/missing users and sync level/branch.
 * @param array{redirect?:bool,login?:string,json?:bool,touch?:bool,csrf?:bool} $opts
 * @return array{ok:bool,error?:string,reason?:string}
 */
function psSecurityEnforceWebSession($conn = null, array $opts = []): array
{
    $redirect = array_key_exists('redirect', $opts) ? (bool)$opts['redirect'] : true;
    $json = !empty($opts['json']);
    $login = (string)($opts['login'] ?? 'login.php');
    $touch = !array_key_exists('touch', $opts) || (bool)$opts['touch'];
    $csrf = !array_key_exists('csrf', $opts) || (bool)$opts['csrf'];

    psSecurityStartSession();

    $deny = static function (string $reason, string $location) use ($redirect, $json, $login): array {
        if ($json) {
            psSecurityDestroySession();
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
            exit;
        }
        if ($redirect) {
            psSecurityDestroySession();
            $q = $reason === 'expired' ? '?expired=1' : ($reason === 'suspended' ? '?suspended=1' : '');
            header('Location: ' . $login . $q);
            exit;
        }
        return ['ok' => false, 'error' => 'Please sign in again.', 'reason' => $reason];
    };

    if (trim((string)($_SESSION['logger'] ?? '')) === '') {
        return $deny('unauthenticated', $login);
    }

    if (psSecurityActivityExpired()) {
        return $deny('expired', $login);
    }

    if ($conn instanceof mysqli) {
        $v = psSecurityValidateSessionUser($conn);
        if (empty($v['ok'])) {
            $reason = (string)($v['reason'] ?? 'missing');
            if ($reason === 'locked' || $reason === 'missing' || $reason === 'suspended') {
                psSecurityAudit($conn, 'SESSION_DENIED', [
                    'summary' => 'Session rejected: ' . $reason,
                    'source' => 'psSecurityEnforceWebSession',
                ]);
            }
            return $deny($reason, $login);
        }
    }

    if ($touch) {
        psSecurityTouchActivity();
    }
    if ($csrf) {
        psCsrfToken();
    }
    return ['ok' => true];
}

/* -------------------- CSRF -------------------- */

function psCsrfRotate(): string
{
    psSecurityStartSession();
    $token = bin2hex(random_bytes(32));
    $_SESSION['ps_csrf'] = $token;
    return $token;
}

function psCsrfToken(): string
{
    psSecurityStartSession();
    $token = (string)($_SESSION['ps_csrf'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return psCsrfRotate();
    }
    return $token;
}

function psCsrfField(string $name = 'csrf_token'): string
{
    $t = htmlspecialchars(psCsrfToken(), ENT_QUOTES, 'UTF-8');
    $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="' . $n . '" value="' . $t . '">';
}

function psCsrfMeta(): string
{
    $t = htmlspecialchars(psCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<meta name="csrf-token" content="' . $t . '">';
}

function psCsrfTokenFromRequest(): string
{
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return trim($_POST['csrf_token']);
    }
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($hdr) && $hdr !== '') {
        return trim($hdr);
    }
    return '';
}

function psCsrfValidate(?string $posted = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = (string)($_SESSION['ps_csrf'] ?? '');
    $posted = $posted === null ? psCsrfTokenFromRequest() : trim((string)$posted);
    if ($expected === '' || $posted === '') {
        return false;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $expected) || strlen($expected) !== strlen($posted)) {
        return false;
    }
    return hash_equals($expected, $posted);
}

function psCsrfReject(string $mode = 'html'): void
{
    if ($mode === 'json') {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload and try again.']);
        exit;
    }
    if ($mode === 'text') {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Invalid security token. Reload and try again.';
        exit;
    }
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo 'Invalid security token. Reload and try again.';
    exit;
}

/**
 * Require valid CSRF on state-changing requests. Does not perform the operation on failure.
 */
function psCsrfRequire(string $mode = 'html'): void
{
    if (!psCsrfValidate()) {
        psCsrfReject($mode);
    }
}

function psCsrfRequirePost(string $mode = 'html'): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        if ($mode === 'json') {
            if (!headers_sent()) {
                http_response_code(405);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        if (!headers_sent()) {
            http_response_code(405);
        }
        echo 'Method not allowed';
        exit;
    }
    psCsrfRequire($mode);
}

/* -------------------- Security audit -------------------- */

function psSecurityEnsureAuditSchema(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = @mysqli_query($conn, 'SELECT 1 FROM security_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS security_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        occurred_at DATETIME NOT NULL,
        actor VARCHAR(250) NOT NULL DEFAULT '',
        action_code VARCHAR(64) NOT NULL,
        target_type VARCHAR(64) NOT NULL DEFAULT '',
        target_id VARCHAR(64) NOT NULL DEFAULT '',
        source VARCHAR(64) NOT NULL DEFAULT 'web',
        ip_address VARCHAR(64) NOT NULL DEFAULT '',
        summary VARCHAR(500) NOT NULL DEFAULT '',
        before_json TEXT NULL,
        after_json TEXT NULL,
        PRIMARY KEY (id),
        KEY idx_security_audit_occurred (occurred_at),
        KEY idx_security_audit_actor (actor),
        KEY idx_security_audit_action (action_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('security: security_audit create failed');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * Append security audit row. Never stores passwords/tokens.
 *
 * @param array{target?:string,target_type?:string,target_id?:string,source?:string,summary?:string,before?:array|null,after?:array|null} $meta
 */
function psSecurityAudit(mysqli $conn, string $action, array $meta = []): bool
{
    if (!psSecurityEnsureAuditSchema($conn)) {
        return false;
    }
    $actor = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $actor = trim((string)($_SESSION['logger'] ?? ''));
    }
    if ($actor === '' && isset($meta['actor'])) {
        $actor = trim((string)$meta['actor']);
    }
    $action = strtoupper(trim($action));
    if ($action === '' || strlen($action) > 64) {
        return false;
    }
    $targetType = substr(trim((string)($meta['target_type'] ?? '')), 0, 64);
    $targetId = substr(trim((string)($meta['target_id'] ?? ($meta['target'] ?? ''))), 0, 64);
    $source = substr(trim((string)($meta['source'] ?? 'web')), 0, 64);
    $summary = trim((string)($meta['summary'] ?? ''));
    if (function_exists('psPlatformActing') && ($act = psPlatformActing()) !== null) {
        // Phase 18: label actions a Super Admin does inside a client company.
        $summary .= ' [by ' . PS_PLATFORM_PROVIDER_NAME . ' for ' . (string)$act['company'] . ']';
    }
    $summary = substr($summary, 0, 500);
    $ip = substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    $before = null;
    $after = null;
    if (isset($meta['before']) && is_array($meta['before'])) {
        $before = psSecurityAuditSanitizePayload($meta['before']);
    }
    if (isset($meta['after']) && is_array($meta['after'])) {
        $after = psSecurityAuditSanitizePayload($meta['after']);
    }
    $beforeJson = $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null;
    $afterJson = $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null;
    $occurred = date('Y-m-d H:i:s');

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO security_audit
            (occurred_at, actor, action_code, target_type, target_id, source, ip_address, summary, before_json, after_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('security: audit prepare failed');
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssss',
        $occurred,
        $actor,
        $action,
        $targetType,
        $targetId,
        $source,
        $ip,
        $summary,
        $beforeJson,
        $afterJson
    );
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        error_log('security: audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function psSecurityAuditSanitizePayload(array $payload): array
{
    $deny = [
        'password', 'pass', 'token', 'csrf_token', 'payment_token', 'authorization',
        'auth', 'secret', 'api_key', 'credit_card', 'card_number', 'cvv',
    ];
    $out = [];
    foreach ($payload as $k => $v) {
        $key = strtolower((string)$k);
        foreach ($deny as $d) {
            if ($key === $d || strpos($key, $d) !== false) {
                $out[$k] = '[redacted]';
                continue 2;
            }
        }
        if (is_array($v)) {
            $out[$k] = psSecurityAuditSanitizePayload($v);
        } elseif (is_scalar($v) || $v === null) {
            $out[$k] = $v;
        } else {
            $out[$k] = '[omitted]';
        }
    }
    return $out;
}

function psSecurityRequireAdminJson(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        psSecurityStartSession();
    }
    if (trim((string)($_SESSION['logger'] ?? '')) === '') {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
        exit;
    }
    if (strtolower(trim((string)($_SESSION['level'] ?? ''))) !== 'admin') {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => 'Not authorised.']);
        exit;
    }
}

/**
 * Safe relative redirect (basename allowlist style — no open redirects).
 */
function psSecuritySafeLocalRedirect(string $fallback, array $allowedBasenames): string
{
    $fallback = basename($fallback);
    $req = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
    if ($req === '') {
        return $fallback;
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $req) || strpos($req, '//') === 0 || strpos($req, '..') !== false) {
        return $fallback;
    }
    $base = basename(parse_url($req, PHP_URL_PATH) ?: $req);
    if (!in_array($base, $allowedBasenames, true)) {
        return $fallback;
    }
    return $base;
}

/* -------------------- Login throttling (health-check 2026-10-13) -------------------- */

if (!defined('PS_LOGIN_MAX_FAILS')) {
    define('PS_LOGIN_MAX_FAILS', 5);        // per email
}
if (!defined('PS_LOGIN_MAX_FAILS_IP')) {
    define('PS_LOGIN_MAX_FAILS_IP', 20);    // per client IP (many emails)
}
if (!defined('PS_LOGIN_LOCK_MINUTES')) {
    define('PS_LOGIN_LOCK_MINUTES', 15);
}

/**
 * Too many recent failed logins? Uses the existing security_audit rows (LOGIN_FAIL /
 * LOGIN_THROTTLED) — no new table. Counts only failures after the last success for that email.
 *
 * @return array{blocked:bool,minutes?:int}
 */
function psSecurityLoginThrottle(mysqli $conn, string $email): array
{
    if (!psSecurityEnsureAuditSchema($conn)) {
        return ['blocked' => false];
    }
    $since = date('Y-m-d H:i:s', time() - PS_LOGIN_LOCK_MINUTES * 60);
    // Match on target_id (the email typed at login; audit truncates it to 64 chars) — actor is
    // the session user when someone is already signed in, so it is not reliable here.
    $email = substr(trim($email), 0, 64);
    $ip = substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);

    if ($email !== '') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS n FROM security_audit
              WHERE action_code IN ('LOGIN_FAIL','LOGIN_THROTTLED') AND target_type = 'user' AND target_id = ? AND occurred_at >= ?
                AND occurred_at > COALESCE((SELECT MAX(s2.occurred_at) FROM security_audit s2
                                             WHERE s2.action_code = 'LOGIN_SUCCESS' AND s2.target_type = 'user' AND s2.target_id = ?), '1970-01-01')"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sss', $email, $since, $email);
            if (mysqli_stmt_execute($stmt)) {
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                if ((int)($row['n'] ?? 0) >= PS_LOGIN_MAX_FAILS) {
                    mysqli_stmt_close($stmt);
                    return ['blocked' => true, 'minutes' => PS_LOGIN_LOCK_MINUTES];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    if ($ip !== '') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS n FROM security_audit
              WHERE action_code = 'LOGIN_FAIL' AND ip_address = ? AND occurred_at >= ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ss', $ip, $since);
            if (mysqli_stmt_execute($stmt)) {
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                if ((int)($row['n'] ?? 0) >= PS_LOGIN_MAX_FAILS_IP) {
                    mysqli_stmt_close($stmt);
                    return ['blocked' => true, 'minutes' => PS_LOGIN_LOCK_MINUTES];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    return ['blocked' => false];
}
