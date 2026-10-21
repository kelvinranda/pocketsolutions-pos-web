<?php
/**
 * POST { "login": "email or username", "password": "..." }
 * Verifies a cashier online. The till then keeps its own PBKDF2 hash so the same person can
 * sign in on this till while offline. Password hashes are never sent to the till.
 */
require __DIR__ . '/_bootstrap.php';
apiRequireMethod('POST');
$till = apiTill($conn);
$body = apiBody();
$login = trim((string)($body['login'] ?? ''));
$pass = (string)($body['password'] ?? '');
if ($login === '' || $pass === '') {
    apiFail('Enter your username and password.');
}
psSecurityEnsureUsersPasswordColumn($conn);
$throttle = psSecurityLoginThrottle($conn, $login);
if (!empty($throttle['blocked'])) {
    apiFail('Too many failed sign-ins. Try again in ' . (int)$throttle['minutes'] . ' minutes.', 429, 'throttled');
}
$fail = static function (string $msg) use ($conn, $login, $till): void {
    psSecurityAudit($conn, 'LOGIN_FAIL', ['actor' => $login, 'source' => 'desktop', 'summary' => 'Desktop till ' . $till['till_code'] . ': ' . $msg, 'target_type' => 'user', 'target_id' => $login]);
    apiFail($msg, 401, 'login');
};
$look = psSecurityLookupUserByEmail($conn, $login);
if (empty($look['ok'])) {
    $fail(stripos((string)($look['error'] ?? ''), 'locked') !== false ? 'This account is locked.' : 'Invalid username or password.');
}
$user = $look['user'];
$v = psSecurityVerifyPassword((string)$user['password'], $pass);
if (empty($v['ok'])) {
    $fail('Invalid username or password.');
}
$may = psDtUserMayUseTill($user, $till);
if (!$may['ok']) {
    $fail($may['error']);
}
if (!empty($v['needs_rehash'])) {
    psSecurityUpgradePasswordHash($conn, (int)$user['id'], $pass);
}
$name = '';
$st = mysqli_prepare($conn, 'SELECT firstname, lastname FROM users WHERE id = ?');
if ($st) {
    $uid = (int)$user['id'];
    mysqli_stmt_bind_param($st, 'i', $uid);
    mysqli_stmt_execute($st);
    $nr = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    $name = trim(($nr['firstname'] ?? '') . ' ' . ($nr['lastname'] ?? ''));
}
psSecurityAudit($conn, 'LOGIN_SUCCESS', ['actor' => (string)$user['email'], 'source' => 'desktop', 'summary' => 'Desktop till ' . $till['till_code'], 'target_type' => 'user', 'target_id' => $login]);
apiRespond(['success' => true, 'user' => [
    'id' => (int)$user['id'],
    'email' => (string)$user['email'],
    'name' => $name !== '' ? $name : (string)$user['email'],
    'level' => (string)$user['level'],
    'branch' => (string)$user['branch'],
]]);
