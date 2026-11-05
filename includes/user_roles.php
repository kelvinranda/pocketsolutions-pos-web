<?php
/**
 * 2026-10-28 — Change a user's role (Till / Supervisor / Management / Admin).
 *
 * Who: the company's Admin (users.php) for users of their own company, and Pocket Solutions Super Admins
 * (platform_company.php, or users.php while managing the company). Rules:
 *  - only company roles: Till, Supervisor, Management, Admin — never SuperAdmin, and Super Admin accounts are never changed;
 *  - nobody changes their own role;
 *  - the last Admin of a company cannot be moved to another role (a company is never left without an Admin);
 *  - making someone an Admin needs the person doing it to re-enter their own password (company Admin only;
 *    Super Admins confirm with a reason instead);
 *  - every change is written to security_audit (USER_ROLE_CHANGE, before / after / reason) and, when done by
 *    Pocket Solutions, to platform_audit too.
 * The web re-reads the role on every page (psSecurityValidateSessionUser), so a change applies on the user's next
 * click; desktop tills download users on every sync and use the new role from the user's next sign-in on the till.
 */

require_once __DIR__ . '/security.php';
if (is_file(__DIR__ . '/platform.php')) {
    require_once __DIR__ . '/platform.php';
}

/** Roles that can be given here, value => label, lowest first. */
function psUserRoleChoices(): array
{
    return ['Till' => 'Till', 'Supervisor' => 'Supervisor', 'Management' => 'Management', 'admin' => 'Admin'];
}

function psUserRoleLabel(string $level): string
{
    $n = psSecurityNormalizeLevel($level);
    $map = psUserRoleChoices();
    foreach ($map as $k => $label) {
        if (strcasecmp($k, $n) === 0) {
            return $label;
        }
    }
    return $level;
}

function psUserRoleIsAdmin(string $level): bool
{
    return strcasecmp(trim($level), 'admin') === 0;
}

/** Users of one company (never Super Admins) for the role screens. */
function psUserRoleCompanyUsers(mysqli $conn, string $company): array
{
    $stmt = mysqli_prepare($conn, 'SELECT id, firstname, lastname, email, level, branch, lock_status, status FROM users WHERE company_name = ? ORDER BY firstname, lastname, email');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (function_exists('psPlatformIsSuperAdminLevel') && psPlatformIsSuperAdminLevel((string)$r['level'])) {
            continue;
        }
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/** Check the signed-in person's own password (used before making someone an Admin). */
function psUserRoleConfirmPassword(mysqli $conn, string $actorEmail, string $password): bool
{
    if ($password === '' || $actorEmail === '') {
        return false;
    }
    $look = psSecurityLookupUserByEmail($conn, $actorEmail);
    if (empty($look['ok'])) {
        return false;
    }
    $v = psSecurityVerifyPassword((string)($look['user']['password'] ?? ''), $password);
    return !empty($v['ok']);
}

/**
 * Change one user's role.
 *
 * @param array{actor:string, platform?:bool, reason?:string, password?:string} $opts
 *        actor = signed-in login; platform = done by a Super Admin (on the platform page or while managing);
 *        password = the actor's own password, required (company Admins) when the new role is Admin.
 * @return array{ok:bool, error?:string, before?:string, after?:string, email?:string}
 */
function psUserRoleChange(mysqli $conn, string $company, int $userId, string $newLevelRaw, array $opts): array
{
    $company = trim($company);
    $actor = trim((string)($opts['actor'] ?? ''));
    $platform = !empty($opts['platform']);
    $reason = substr(trim((string)($opts['reason'] ?? '')), 0, 400);
    if ($company === '' || $userId < 1 || $actor === '') {
        return ['ok' => false, 'error' => 'Choose a user.'];
    }
    $choices = psUserRoleChoices();
    $new = psSecurityNormalizeLevel($newLevelRaw);
    if (!array_key_exists($new, $choices)) {
        return ['ok' => false, 'error' => 'Choose Till, Supervisor, Management or Admin.'];
    }
    if ($platform && $reason === '') {
        return ['ok' => false, 'error' => 'Give a reason (it is kept in the activity log).'];
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The role could not be changed. Try again.'];
    }
    $fail = static function (string $msg) use ($conn): array {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $msg];
    };

    // Lock the company's users so two Admins cannot demote each other at the same moment.
    $stmt = mysqli_prepare($conn, 'SELECT id, email, level, firstname, lastname FROM users WHERE company_name = ? FOR UPDATE');
    if (!$stmt) {
        return $fail('The role could not be changed. Try again.');
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $target = null;
    $admins = 0;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (psUserRoleIsAdmin((string)$r['level'])) {
            $admins++;
        }
        if ((int)$r['id'] === $userId) {
            $target = $r;
        }
    }
    mysqli_stmt_close($stmt);

    if (!$target) {
        return $fail('That user is not in this company.');
    }
    $email = (string)$target['email'];
    $before = (string)$target['level'];
    if (function_exists('psPlatformIsSuperAdminLevel') && psPlatformIsSuperAdminLevel($before)) {
        return $fail('Super Admin accounts cannot be changed here.');
    }
    if (strcasecmp(trim($email), $actor) === 0) {
        return $fail('You cannot change your own role. Ask another Admin.');
    }
    if (strcasecmp(psSecurityNormalizeLevel($before), $new) === 0) {
        return $fail($email . ' is already ' . psUserRoleLabel($new) . '.');
    }
    if (psUserRoleIsAdmin($before) && !psUserRoleIsAdmin($new) && $admins <= 1) {
        return $fail($email . ' is the only Admin of this company. Make someone else an Admin first.');
    }
    if (psUserRoleIsAdmin($new) && !$platform && !psUserRoleConfirmPassword($conn, $actor, (string)($opts['password'] ?? ''))) {
        return $fail('Enter your own password to make someone an Admin.');
    }

    $up = mysqli_prepare($conn, 'UPDATE users SET level = ? WHERE id = ? AND company_name = ? LIMIT 1');
    if (!$up) {
        return $fail('The role could not be changed. Try again.');
    }
    mysqli_stmt_bind_param($up, 'sis', $new, $userId, $company);
    $ok = mysqli_stmt_execute($up);
    mysqli_stmt_close($up);
    if (!$ok || !mysqli_commit($conn)) {
        return $fail('The role could not be changed. Try again.');
    }

    $summary = 'Role of ' . $email . ' changed from ' . psUserRoleLabel($before) . ' to ' . psUserRoleLabel($new)
        . ($reason !== '' ? ' — ' . $reason : '');
    psSecurityAudit($conn, 'USER_ROLE_CHANGE', [
        'summary' => $summary,
        'target_type' => 'user',
        'target_id' => (string)$userId,
        'before' => ['email' => $email, 'level' => $before, 'company_name' => $company],
        'after' => ['email' => $email, 'level' => $new, 'company_name' => $company, 'reason' => $reason, 'by_provider' => $platform],
    ]);
    if ($platform && function_exists('psPlatformAudit')) {
        psPlatformAudit($conn, 'USER_ROLE_CHANGE', $company, $email . ': ' . psUserRoleLabel($before) . ' → ' . psUserRoleLabel($new) . ' — ' . $reason,
            ['login' => $email, 'before' => psUserRoleLabel($before), 'after' => psUserRoleLabel($new)]);
    }
    return ['ok' => true, 'before' => $before, 'after' => $new, 'email' => $email];
}

/** Recent role changes of a company (newest first) from security_audit. */
function psUserRoleHistory(mysqli $conn, string $company, int $limit = 30): array
{
    if (!psSecurityEnsureAuditSchema($conn)) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $like = '%"company_name":' . json_encode($company, JSON_UNESCAPED_UNICODE) . '%';
    $stmt = mysqli_prepare($conn, "SELECT occurred_at, actor, summary, before_json, after_json FROM security_audit
                                   WHERE action_code = 'USER_ROLE_CHANGE' AND after_json LIKE ? ORDER BY occurred_at DESC, id DESC LIMIT " . $limit);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $b = json_decode((string)$r['before_json'], true) ?: [];
        $a = json_decode((string)$r['after_json'], true) ?: [];
        if (($a['company_name'] ?? '') !== $company) {
            continue;
        }
        $rows[] = [
            'at' => (string)$r['occurred_at'],
            'by' => (string)$r['actor'],
            'email' => (string)($a['email'] ?? ''),
            'before' => psUserRoleLabel((string)($b['level'] ?? '')),
            'after' => psUserRoleLabel((string)($a['level'] ?? '')),
            'reason' => (string)($a['reason'] ?? ''),
            'provider' => !empty($a['by_provider']) || strpos((string)$r['summary'], '[by ') !== false,
        ];
    }
    mysqli_stmt_close($stmt);
    return $rows;
}
