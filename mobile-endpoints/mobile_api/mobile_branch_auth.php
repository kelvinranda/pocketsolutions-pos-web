<?php
/**
 * Phase 9.4 — Mobile token auth + trusted branch binding.
 *
 * Distinct from web PHP session helpers in includes/branch_access.php.
 * Tokens prove identity only after DB lookup; branch comes from users.branch
 * (live), never from client POST/GET alone.
 *
 * Mobile policy: authorised working branch = home branch when registered in
 * branchies. Admin level does NOT unlock all branchies on mobile.
 */

require_once dirname(__DIR__, 2) . '/includes/branch_access.php';

/**
 * Resolve live user row from a validated mobile_tokens row.
 *
 * @return array{id:int,email:string,level:string,branch:string,company_name:string}|null
 */
function mobileAuthLoadUser(mysqli $conn, array $tokenRow): ?array
{
    $email = trim((string)($tokenRow['cashier'] ?? $tokenRow['username'] ?? ''));
    if ($email === '') {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, email, level, branch, company_name FROM users WHERE email = ? LIMIT 1'
    );
    if (!$stmt) {
        error_log('mobile auth: user lookup prepare failed');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $email);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('mobile auth: user lookup failed');
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'level' => (string)($row['level'] ?? ''),
        'branch' => psBranchNormalizeName($row['branch'] ?? ''),
        'company_name' => (string)($row['company_name'] ?? ''),
    ];
}

/**
 * Branches the mobile user may use (home only, when registered).
 *
 * @return array<int,string>
 */
function mobileAuthAuthorisedBranches(mysqli $conn, string $homeBranch): array
{
    $home = psBranchNormalizeName($homeBranch);
    if ($home === '') {
        return [];
    }
    if (!psBranchIsRegistered($conn, $home)) {
        return [];
    }
    return [$home];
}

/**
 * Full auth context from a token row. Re-reads users on every call.
 *
 * @return array{
 *   token:array,
 *   user_id:int,
 *   email:string,
 *   cashier:string,
 *   level:string,
 *   company:string,
 *   home_branch:string,
 *   authorised_branches:array<int,string>,
 *   working_branch:string
 * }|null
 */
function mobileAuthContext(mysqli $conn, ?array $tokenRow): ?array
{
    if (!$tokenRow) {
        return null;
    }
    $user = mobileAuthLoadUser($conn, $tokenRow);
    if ($user === null) {
        return null;
    }
    $authorised = mobileAuthAuthorisedBranches($conn, $user['branch']);
    $working = $authorised[0] ?? '';
    return [
        'token' => $tokenRow,
        'user_id' => $user['id'],
        'email' => $user['email'],
        'cashier' => $user['email'],
        'level' => $user['level'],
        'company' => $user['company_name'],
        'home_branch' => $user['branch'],
        'authorised_branches' => $authorised,
        'working_branch' => $working,
    ];
}

/**
 * Resolve token from bearer / override and build context, or 401.
 *
 * @return array
 */
function mobileAuthRequire(mysqli $conn, ?string $tokenOverride = null): array
{
    $tokenRow = auth_user($conn, $tokenOverride);
    $ctx = mobileAuthContext($conn, $tokenRow);
    if ($ctx === null) {
        respond([
            'success' => false,
            'error' => 'Unauthorized',
            'hint' => 'Log out and log in again so the phone gets a fresh server token.',
        ], 401);
    }
    return $ctx;
}

/**
 * Client-supplied branch is a consistency check only — never sole permission.
 *
 * @return array{ok:bool,branch?:string,error?:string,source?:string}
 */
function mobileAuthAcceptClientBranch(array $ctx, $clientBranch): array
{
    $working = psBranchNormalizeName($ctx['working_branch'] ?? '');
    if ($working === '') {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    $client = psBranchNormalizeName($clientBranch);
    if ($client === '') {
        return ['ok' => true, 'branch' => $working, 'source' => 'auth'];
    }
    if ($client !== $working) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    return ['ok' => true, 'branch' => $working, 'source' => 'consistent'];
}

/**
 * Login payload branch fields: authorised home only (no full branchies list).
 *
 * @return array{branch:string,branches:array<int,string>}
 */
function mobileAuthLoginBranchPayload(array $ctx): array
{
    $branches = [];
    foreach ($ctx['authorised_branches'] as $name) {
        $name = psBranchNormalizeName($name);
        if ($name !== '') {
            $branches[] = $name;
        }
    }
    return [
        'branch' => $branches[0] ?? '',
        'branches' => $branches,
    ];
}

/**
 * Optional till/session id on a sale: must be this cashier's OPEN till on working branch.
 * Blank/NULL till branch is rejected (no auto-assign). Omitted id = allowed (legacy mobile).
 *
 * @return array{ok:bool,session_id?:int,error?:string}
 */
function mobileAuthValidateTillForSale(mysqli $conn, array $ctx, $tillSessionId, string $workingBranch): array
{
    $raw = trim((string)$tillSessionId);
    if ($raw === '' || $raw === '0') {
        return ['ok' => true, 'session_id' => 0];
    }
    if (!preg_match('/^\d+$/', $raw)) {
        return ['ok' => false, 'error' => 'That till session is not available.'];
    }
    $sessionId = (int)$raw;
    if ($sessionId < 1) {
        return ['ok' => false, 'error' => 'That till session is not available.'];
    }

    require_once dirname(__DIR__, 2) . '/includes/till_session.php';
    if (!tillEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Could not validate till session.'];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, cashier_id, cashier_email, branch, status, session_end
         FROM sessions WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        error_log('mobile auth: till lookup prepare failed');
        return ['ok' => false, 'error' => 'Could not validate till session.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $sessionId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Could not validate till session.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'error' => 'That till session is not available.'];
    }

    $status = strtoupper(trim((string)($row['status'] ?? '')));
    $ended = trim((string)($row['session_end'] ?? ''));
    if ($status !== 'OPEN' || $ended !== '') {
        return ['ok' => false, 'error' => 'That till session is not open.'];
    }

    $tillBranch = psBranchNormalizeName($row['branch'] ?? '');
    if ($tillBranch === '') {
        return ['ok' => false, 'error' => 'That till session has no branch assigned.'];
    }
    if ($tillBranch !== psBranchNormalizeName($workingBranch)) {
        return ['ok' => false, 'error' => 'That till session is not available.'];
    }

    $email = strtolower(trim((string)$ctx['email']));
    $ownerOk = false;
    if ((int)($row['cashier_id'] ?? 0) > 0 && (int)$row['cashier_id'] === (int)$ctx['user_id']) {
        $ownerOk = true;
    }
    if (!$ownerOk && strtolower(trim((string)($row['cashier_email'] ?? ''))) === $email) {
        $ownerOk = true;
    }
    if (!$ownerOk) {
        return ['ok' => false, 'error' => 'That till session is not available.'];
    }

    return ['ok' => true, 'session_id' => $sessionId];
}
