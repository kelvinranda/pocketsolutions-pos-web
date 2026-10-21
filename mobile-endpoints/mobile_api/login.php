<?php
/**
 * POST { "username": "...", "password": "..." }
 * Phase 14: password_hash/verify (+ legacy plaintext upgrade). Locked users denied.
 * Phase 9.4: returns only the user's authorised mobile branch (home when
 * registered in branchies). Does not return the full branchies list.
 * Admin level does not unlock multi-branch mobile access.
 */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';

$body = json_body();
$user = trim((string)($body['username'] ?? ''));
$pass = (string)($body['password'] ?? '');

if ($user === '' || $pass === '') {
    respond(['success' => false, 'error' => 'Username and password required'], 400);
}

ensure_mobile_tables($conn);
psSecurityEnsureUsersPasswordColumn($conn);

$look = psSecurityLookupUserByEmail($conn, $user);
if (empty($look['ok'])) {
    $msg = (isset($look['error']) && stripos((string)$look['error'], 'locked') !== false)
        ? 'This account is locked.'
        : 'Invalid username or password';
    psSecurityAudit($conn, 'MOBILE_LOGIN_FAIL', [
        'actor' => $user,
        'source' => 'mobile',
        'summary' => $msg,
        'target_type' => 'user',
        'target_id' => $user,
    ]);
    respond(['success' => false, 'error' => $msg], 401);
}

$row = $look['user'];
$verified = psSecurityVerifyPassword((string)($row['password'] ?? ''), $pass);
if (empty($verified['ok'])) {
    psSecurityAudit($conn, 'MOBILE_LOGIN_FAIL', [
        'actor' => $user,
        'source' => 'mobile',
        'summary' => 'Invalid credentials',
        'target_type' => 'user',
        'target_id' => $user,
    ]);
    respond(['success' => false, 'error' => 'Invalid username or password'], 401);
}

// Phase 18: Super Admins never sell; suspended companies cannot sign in.
if (psPlatformIsSuperAdminLevel((string)($row['level'] ?? ''))) {
    respond(['success' => false, 'error' => 'This account cannot use the mobile till.'], 403);
}
if (psPlatformCompanyIsSuspended($conn, (string)($row['company_name'] ?? ''))) {
    respond(['success' => false, 'error' => 'This company account is suspended.'], 403);
}

if (!empty($verified['needs_rehash'])) {
    psSecurityUpgradePasswordHash($conn, (int)$row['id'], $pass);
}

$cashier = (string)$row['email'];
$level = (string)($row['level'] ?? 'Till');
$company = (string)($row['company_name'] ?? '');
$homeBranch = psBranchNormalizeName($row['branch'] ?? '');

$authorised = mobileAuthAuthorisedBranches($conn, $homeBranch);
$ctxPreview = [
    'authorised_branches' => $authorised,
    'working_branch' => $authorised[0] ?? '',
    'home_branch' => $homeBranch,
];
$branchPayload = mobileAuthLoginBranchPayload($ctxPreview);

$token = bin2hex(random_bytes(24));
$created = date('Y-m-d H:i:s');
$expires = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);

$ins = mysqli_prepare(
    $conn,
    'INSERT INTO mobile_tokens (token, username, cashier, level_name, company, created_at, expires_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
if (!$ins) {
    error_log('mobile login: token insert prepare failed');
    respond(['success' => false, 'error' => 'Login failed'], 500);
}
mysqli_stmt_bind_param(
    $ins,
    'sssssss',
    $token,
    $cashier,
    $cashier,
    $level,
    $company,
    $created,
    $expires
);
if (!mysqli_stmt_execute($ins)) {
    mysqli_stmt_close($ins);
    error_log('mobile login: token insert failed');
    respond(['success' => false, 'error' => 'Login failed'], 500);
}
mysqli_stmt_close($ins);

psSecurityAudit($conn, 'MOBILE_LOGIN_SUCCESS', [
    'actor' => $cashier,
    'source' => 'mobile',
    'summary' => 'Mobile token issued',
    'target_type' => 'user',
    'target_id' => $cashier,
]);

respond([
    'success' => true,
    'token' => $token,
    'user' => [
        'username' => $cashier,
        'cashier' => $cashier,
        'level' => $level,
        'company' => $company,
        'branch' => $branchPayload['branch'],
        'branches' => $branchPayload['branches'],
    ],
]);
