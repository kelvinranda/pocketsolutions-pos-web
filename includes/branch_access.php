<?php
/**
 * Phase 9.2 — Trusted branch resolution (home / working / authorised).
 *
 * Home branch: assigned at login into $_SESSION['branch'] from users.branch.
 * Working branch: currently selected for the request (selected_branch when authorised).
 * Authorised branches:
 *   - Ordinary (non-admin): only the home branch. selected_branch ∈ branchies alone
 *     does NOT grant access.
 *   - Admin (session level, case-insensitive): registered branchies.branch_name rows
 *     for the same company as $_SESSION['the_company'] (users.company_name at login).
 *     Not all tenants' branches.
 *
 * Role/home are resolved from the authenticated session only — never from POST.
 * Exact string match after trim (no case-folding of branch names).
 * App-wide CSRF is not provided here (document at call sites).
 */

/**
 * Authenticated POS session (existing convention: logger).
 */
function psBranchSessionAuthenticated(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return trim((string)($_SESSION['logger'] ?? '')) !== '';
}

/**
 * Admin gate — matches existing helpers (strtolower level === 'admin').
 */
function psBranchSessionIsAdmin(): bool
{
    if (!psBranchSessionAuthenticated()) {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Normalize a branch name for comparison (trim only; preserve casing).
 */
function psBranchNormalizeName($raw): string
{
    return trim((string)$raw);
}

/**
 * Home branch from authenticated session (login users.branch). Never from POST.
 */
function psBranchHomeName(): string
{
    if (!psBranchSessionAuthenticated()) {
        return '';
    }
    return psBranchNormalizeName($_SESSION['branch'] ?? '');
}

/**
 * Company for the authenticated session (users.company_name at login → the_company).
 * Never from POST.
 */
function psBranchSessionCompany(): string
{
    if (!psBranchSessionAuthenticated()) {
        return '';
    }
    return trim((string)($_SESSION['the_company'] ?? ''));
}

/**
 * 2026-10-13: does branchies have a company_name column? The per-company branch
 * scoping below needs it; databases without it fall back to name-only checks
 * (otherwise every branch looks "not registered" and tills/POS stop working).
 */
function psBranchRegistryHasCompany(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM branchies LIKE 'company_name'");
    $has = $res && mysqli_num_rows($res) > 0;
    if ($res) {
        mysqli_free_result($res);
    }
    return $has;
}

/**
 * Raw selected_branch session field (may be unauthorised — do not trust alone).
 */
function psBranchSelectedRaw(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return psBranchNormalizeName($_SESSION['selected_branch'] ?? '');
}

/**
 * Registered branch names from branchies for the session company only.
 * Admins must not see / switch into other tenants' branches.
 *
 * @return array<int,string>
 */
function psBranchRegisteredNames(mysqli $conn): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $names = [];
    if (!psBranchRegistryHasCompany($conn)) {
        // Single-company database (no branchies.company_name): every registered branch.
        if (function_exists('psAllBranchNames')) {
            return psAllBranchNames($conn);
        }
        $res = mysqli_query($conn, 'SELECT branch_name FROM branchies ORDER BY branch_name ASC');
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $name = psBranchNormalizeName($row['branch_name'] ?? '');
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }
    $company = psBranchSessionCompany();
    if ($company === '') {
        // No company on session → do not expose every tenant's branchies.
        return $names;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT branch_name, company_name FROM branchies WHERE company_name = ? ORDER BY branch_name ASC'
    );
    if (!$stmt) {
        error_log('branch_access: branchies company list prepare failed');
        return $names;
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('branch_access: branchies company list failed');
        return $names;
    }
    $res = mysqli_stmt_get_result($stmt);
    if (!$res) {
        mysqli_stmt_close($stmt);
        error_log('branch_access: branchies company list failed');
        return $names;
    }
    $seen = [];
    while ($row = mysqli_fetch_assoc($res)) {
        // Collation may fold case — enforce PHP exact company + branch match.
        if (trim((string)($row['company_name'] ?? '')) !== $company) {
            continue;
        }
        $name = psBranchNormalizeName($row['branch_name'] ?? '');
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $names[] = $name;
    }
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    return $names;
}

function psBranchIsRegistered(mysqli $conn, string $branch): bool
{
    $branch = psBranchNormalizeName($branch);
    if ($branch === '') {
        return false;
    }
    $company = psBranchRegistryHasCompany($conn) ? psBranchSessionCompany() : '';
    if ($company !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT branch_name, company_name FROM branchies WHERE branch_name = ? AND company_name = ? LIMIT 5'
        );
        if (!$stmt) {
            error_log('branch_access: registered check failed');
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $branch, $company);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            error_log('branch_access: registered check failed');
            return false;
        }
        $result = mysqli_stmt_get_result($stmt);
        $ok = false;
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            if (trim((string)($row['company_name'] ?? '')) === $company
                && psBranchNormalizeName($row['branch_name'] ?? '') === $branch
            ) {
                $ok = true;
                break;
            }
        }
        mysqli_stmt_close($stmt);
        return $ok;
    }
    // No company in session (CLI/tests): name-only registry check.
    if (function_exists('stockMovementBranchExists')) {
        return stockMovementBranchExists($conn, $branch);
    }
    $stmt = mysqli_prepare($conn, 'SELECT branch_name FROM branchies WHERE branch_name = ? LIMIT 1');
    if (!$stmt) {
        error_log('branch_access: registered check failed');
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('branch_access: registered check failed');
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return false;
    }
    return psBranchNormalizeName($row['branch_name'] ?? '') === $branch;
}

/**
 * Exact match against a list of branch names (trim; case-sensitive).
 *
 * @param array<int,string> $names
 */
function psBranchNameInList(string $branch, array $names): bool
{
    $branch = psBranchNormalizeName($branch);
    if ($branch === '') {
        return false;
    }
    foreach ($names as $name) {
        if (psBranchNormalizeName($name) === $branch) {
            return true;
        }
    }
    return false;
}

/**
 * Branches the authenticated user may use as working branch.
 *
 * @return array<int,string>
 */
function psBranchAuthorisedNames(mysqli $conn): array
{
    if (!psBranchSessionAuthenticated()) {
        return [];
    }
    if (psBranchSessionIsAdmin()) {
        return psBranchRegisteredNames($conn);
    }
    $home = psBranchHomeName();
    if ($home === '') {
        return [];
    }
    // Ordinary: home only — not "any selected_branch that exists in branchies".
    return [$home];
}

function psBranchIsAuthorised(mysqli $conn, string $branch): bool
{
    return psBranchNameInList($branch, psBranchAuthorisedNames($conn));
}

/**
 * Authorised names that are also registered in branchies (for selectors / writes).
 *
 * @return array<int,string>
 */
function psBranchAuthorisedRegisteredNames(mysqli $conn): array
{
    $out = [];
    foreach (psBranchAuthorisedNames($conn) as $name) {
        $name = psBranchNormalizeName($name);
        if ($name !== '' && psBranchIsRegistered($conn, $name)) {
            $out[] = $name;
        }
    }
    return $out;
}

/**
 * Resolve working branch for the current request.
 * - Prefer selected_branch only when authorised AND registered in branchies.
 * - Else use home when authorised AND registered.
 * - Contradictory / blank / unregistered selected_branch → fail (no silent fallback).
 * - Ordinary authorised list remains home-only (may include historical unregistered
 *   home for receipt ownership checks); working ops still require registry.
 *
 * @return array{ok:bool,branch?:string,error?:string,source?:string}
 */
function psBranchResolveWorking(mysqli $conn): array
{
    if (!psBranchSessionAuthenticated()) {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }

    $selected = psBranchSelectedRaw();
    $home = psBranchHomeName();

    if ($selected !== '') {
        if (!psBranchIsAuthorised($conn, $selected) || !psBranchIsRegistered($conn, $selected)) {
            return ['ok' => false, 'error' => 'That branch is not available.'];
        }
        return ['ok' => true, 'branch' => $selected, 'source' => 'selected'];
    }

    // No selected_branch: fall back to home when authorised + registered.
    if ($home === '') {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if (!psBranchIsAuthorised($conn, $home) || !psBranchIsRegistered($conn, $home)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    return ['ok' => true, 'branch' => $home, 'source' => 'home'];
}

/**
 * Phase 9.6 — single gate for page/API ops that need an authorised registered working branch.
 *
 * @return array{ok:bool,branch?:string,error?:string,source?:string}
 */
function psBranchRequireAuthorisedWorking(mysqli $conn): array
{
    if (!psBranchSessionAuthenticated()) {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }
    return psBranchResolveWorking($conn);
}

/**
 * Phase 9.6 — JSON denial when session missing (fail closed).
 */
function psBranchRequireAuthJson(string $error = 'Please sign in again.'): void
{
    if (!psBranchSessionAuthenticated()) {
        psBranchDenyJson(401, $error);
    }
}

/**
 * Whether a till/session row may be claimed or switched into by branch tooling.
 * NULL/blank branch OPEN tills: never claim or switch into here.
 * Own-till operational use may one-time bind via tillBindBlankBranchIfNeeded().
 *
 * @return array{ok:bool,error?:string}
 */
function psBranchTillRowOperational(array $row): array
{
    $branch = psBranchNormalizeName($row['branch'] ?? '');
    if ($branch === '') {
        return ['ok' => false, 'error' => 'That till session has no branch assigned.'];
    }
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    $ended = trim((string)($row['session_end'] ?? ''));
    if ($status !== 'OPEN' || $ended !== '') {
        return ['ok' => false, 'error' => 'That till session is not open.'];
    }
    return ['ok' => true];
}

/**
 * Working branch name or '' on failure (callers that need fail-closed should use resolve).
 */
function psBranchWorkingName(mysqli $conn): string
{
    $resolved = psBranchResolveWorking($conn);
    return !empty($resolved['ok']) ? (string)$resolved['branch'] : '';
}

/**
 * Safe internal redirect targets for branch switch (no open redirect).
 *
 * @return array<int,string>
 */
function psBranchSafeRedirectTargets(): array
{
    return [
        'admin_dashboard.php',
        'ajax_pos.php',
        'index.php',
    ];
}

function psBranchSafeRedirect(?string $requested): string
{
    $requested = trim((string)$requested);
    if ($requested === '') {
        return '';
    }
    // Reject absolute / protocol-relative / path traversal style targets.
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $requested)) {
        return '';
    }
    if (strpos($requested, '//') === 0 || strpos($requested, '..') !== false || strpos($requested, '\\') !== false) {
        return '';
    }
    $base = basename(parse_url($requested, PHP_URL_PATH) ?: $requested);
    if (!in_array($base, psBranchSafeRedirectTargets(), true)) {
        return '';
    }
    return $base;
}

/**
 * Locate caller's OPEN till without mutating it.
 * Uses till helpers when loaded; otherwise a minimal lookup.
 *
 * @return array{ok:bool,row?:?array,error?:string}
 */
function psBranchFindOpenTill(mysqli $conn): array
{
    if (!psBranchSessionAuthenticated()) {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }
    if (function_exists('tillEnsureSchema') && function_exists('tillCurrentCashier') && function_exists('tillFetchActive')) {
        if (!tillEnsureSchema($conn)) {
            return ['ok' => false, 'error' => 'Could not check till session.'];
        }
        $cashier = tillCurrentCashier($conn);
        if (trim((string)($cashier['email'] ?? '')) === '') {
            return ['ok' => true, 'row' => null];
        }
        return tillFetchActive($conn, $cashier, false);
    }
    $email = trim((string)($_SESSION['logger'] ?? ''));
    if ($email === '') {
        return ['ok' => true, 'row' => null];
    }
    $emailEsc = mysqli_real_escape_string($conn, $email);
    $sql = "SELECT id, branch, status, session_end, cashier_email
            FROM sessions
            WHERE status = 'OPEN'
              AND session_end = ''
              AND LOWER(cashier_email) = LOWER('$emailEsc')
            ORDER BY id DESC
            LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        error_log('branch_access: open till lookup failed');
        return ['ok' => false, 'error' => 'Could not check till session.'];
    }
    $row = mysqli_fetch_assoc($res) ?: null;
    return ['ok' => true, 'row' => $row];
}

/**
 * Whether an open till blocks switching to $targetBranch.
 * Does not move/close/reassign tills during a branch switch.
 * Blank-branch OPEN tills do not block session working-branch switch (deadlock avoid);
 * operational sale/recon/close paths may bind blank tills via tillBindBlankBranchIfNeeded().
 *
 * @return array{ok:bool,blocked?:bool,error?:string,till_branch?:string}
 */
function psBranchOpenTillSwitchGate(mysqli $conn, string $targetBranch): array
{
    $targetBranch = psBranchNormalizeName($targetBranch);
    $found = psBranchFindOpenTill($conn);
    if (empty($found['ok'])) {
        return ['ok' => false, 'error' => $found['error'] ?? 'Could not check till session.'];
    }
    $row = $found['row'] ?? null;
    if (!$row) {
        return ['ok' => true, 'blocked' => false];
    }
    $tillBranch = psBranchNormalizeName($row['branch'] ?? '');
    // Legacy NULL/blank OPEN tills: do not rewrite here (switch is session-only).
    // Operational bind happens on sale / recon / close for the owning cashier.
    if ($tillBranch === '') {
        return [
            'ok' => true,
            'blocked' => false,
            'till_branch' => '',
        ];
    }
    if ($tillBranch !== $targetBranch) {
        return [
            'ok' => true,
            'blocked' => true,
            'till_branch' => $tillBranch,
            'error' => 'Close your open till before switching branch.',
        ];
    }
    return ['ok' => true, 'blocked' => false, 'till_branch' => $tillBranch];
}

/**
 * Apply an authorised working-branch switch into session.
 * Ignores posted role / user id / home branch. Does not rewrite till rows.
 *
 * @return array{ok:bool,branch?:string,error?:string,http?:int}
 */
function psBranchApplySwitch(mysqli $conn, $postedSelected): array
{
    if (!psBranchSessionAuthenticated()) {
        return ['ok' => false, 'error' => 'Please sign in again.', 'http' => 401];
    }

    $selected = psBranchNormalizeName($postedSelected);
    if ($selected === '') {
        return ['ok' => false, 'error' => 'Branch required', 'http' => 400];
    }

    // Reject whitespace-only already handled; reject control / absurd length.
    if (strlen($selected) > 250 || preg_match('/[\x00-\x1F\x7F]/', $selected)) {
        return ['ok' => false, 'error' => 'That branch is not available.', 'http' => 400];
    }

    if (!psBranchIsRegistered($conn, $selected)) {
        return ['ok' => false, 'error' => 'That branch is not available.', 'http' => 403];
    }

    if (!psBranchIsAuthorised($conn, $selected)) {
        return ['ok' => false, 'error' => 'That branch is not available.', 'http' => 403];
    }

    $gate = psBranchOpenTillSwitchGate($conn, $selected);
    if (empty($gate['ok'])) {
        return ['ok' => false, 'error' => $gate['error'] ?? 'Could not switch branch.', 'http' => 500];
    }
    if (!empty($gate['blocked'])) {
        return ['ok' => false, 'error' => $gate['error'] ?? 'Close your open till before switching branch.', 'http' => 409];
    }

    $_SESSION['selected_branch'] = $selected;
    return ['ok' => true, 'branch' => $selected, 'http' => 200];
}

/**
 * Phase 9.3 / 16 — HTML page gate: authenticated session or redirect to login.
 * Uses secure session cookie params, inactivity timeout, and CSRF token minting.
 * Pass $conn when available so locked/missing users are rejected and privileges re-synced.
 */
function psBranchRequireAuthRedirect(string $login = 'login.php', $conn = null): void
{
    if (!function_exists('psSecurityEnforceWebSession')) {
        $sec = __DIR__ . '/security.php';
        if (is_file($sec)) {
            require_once $sec;
        }
    }
    if (function_exists('psSecurityEnforceWebSession')) {
        psSecurityEnforceWebSession($conn instanceof mysqli ? $conn : null, [
            'redirect' => true,
            'login' => $login,
            'touch' => true,
            'csrf' => true,
        ]);
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!psBranchSessionAuthenticated()) {
        header('Location: ' . $login);
        exit;
    }
}

/**
 * Phase 9.3 — HTML page gate: authenticated admin or redirect.
 */
function psBranchRequireAdminRedirect(string $fallback = 'ajax_pos.php'): void
{
    psBranchRequireAuthRedirect();
    if (!psBranchSessionIsAdmin()) {
        header('Location: ' . $fallback);
        exit;
    }
}

/**
 * Phase 9.3 — JSON/API style denial (AJAX endpoints).
 */
function psBranchDenyJson(int $http, string $error): void
{
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $error, 'success' => false]);
    exit;
}

/**
 * Whether the caller may view a sale/receipt whose header branch is $saleBranch.
 * Blank/orphan sale branches: admin only (fail closed for ordinary — no inventing ownership).
 */
function psBranchSaleBranchAuthorised(mysqli $conn, $saleBranch): bool
{
    if (!psBranchSessionAuthenticated()) {
        return false;
    }
    $saleBranch = psBranchNormalizeName($saleBranch);
    if ($saleBranch === '') {
        return psBranchSessionIsAdmin();
    }
    return psBranchIsAuthorised($conn, $saleBranch);
}

/**
 * Branches an authenticated user may include in financial report queries.
 * Ordinary: working branch only (fail closed). Admin: all registered authorised names.
 *
 * @return array{ok:bool,branches?:array<int,string>,error?:string,all_authorised?:bool}
 */
function psBranchReportScope(mysqli $conn): array
{
    if (!psBranchSessionAuthenticated()) {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }
    if (psBranchSessionIsAdmin()) {
        $names = psBranchAuthorisedRegisteredNames($conn);
        if ($names === []) {
            return ['ok' => false, 'error' => 'That branch is not available.'];
        }
        return ['ok' => true, 'branches' => $names, 'all_authorised' => true];
    }
    $resolved = psBranchResolveWorking($conn);
    if (empty($resolved['ok'])) {
        return ['ok' => false, 'error' => $resolved['error'] ?? 'That branch is not available.'];
    }
    $branch = psBranchNormalizeName($resolved['branch'] ?? '');
    if ($branch === '' || !psBranchIsRegistered($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    return ['ok' => true, 'branches' => [$branch], 'all_authorised' => false];
}

/**
 * Build mysqli-safe IN list placeholders for authorised branch names.
 *
 * @param array<int,string> $branches
 * @return array{sql:string,types:string,params:array<int,string>}|null
 */
function psBranchSqlInList(array $branches): ?array
{
    $clean = [];
    foreach ($branches as $b) {
        $b = psBranchNormalizeName($b);
        if ($b !== '') {
            $clean[] = $b;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    return [
        'sql' => $placeholders,
        'types' => str_repeat('s', count($clean)),
        'params' => $clean,
    ];
}
