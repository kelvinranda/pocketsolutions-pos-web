<?php
/**
 * POST {
 *   token?,
 *   action: "open" | "close",
 *   client_uuid,
 *   session_name?,
 *   cashier?,   // ignored — auth cashier used
 *   branch?,    // consistency check only
 *   opened_at?,
 *   closed_at?
 * }
 *
 * Phase 9.4: branch + cashier from trusted mobile auth. Never create unscoped
 * (NULL/blank branch) sessions. Cannot claim/modify another user's till.
 * Does not auto-assign branch on historical NULL-branch sessions.
 */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/till_session.php';

$body = json_body();
$tokenFromQuery = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$tokenFromBody = isset($body['token']) ? trim((string)$body['token']) : '';

$tokenOverride = null;
if ($tokenFromBody !== '') {
    $tokenOverride = $tokenFromBody;
} elseif ($tokenFromQuery !== '') {
    $tokenOverride = $tokenFromQuery;
}
$ctx = mobileAuthRequire($conn, $tokenOverride);

tillEnsureSchema($conn);
ensure_mobile_tables($conn);

$action = strtolower(trim((string)($body['action'] ?? '')));
$clientUuid = trim((string)($body['client_uuid'] ?? ''));
if ($clientUuid === '') {
    respond(['success' => false, 'error' => 'client_uuid required'], 400);
}
if ($action !== 'open' && $action !== 'close') {
    respond(['success' => false, 'error' => 'action must be open or close'], 400);
}

function mobile_to_mysql_datetime(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return date('Y-m-d H:i:s');
    }
    try {
        $dt = new DateTimeImmutable($raw);
        $dt = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $ts = strtotime($raw);
        if ($ts === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $ts);
    }
}

/**
 * Confirm a sessions row is owned by the authenticated mobile user.
 */
function mobile_session_owned_by(array $row, array $ctx): bool
{
    if ((int)($row['cashier_id'] ?? 0) > 0 && (int)$row['cashier_id'] === (int)$ctx['user_id']) {
        return true;
    }
    $email = strtolower(trim((string)$ctx['email']));
    if ($email !== '' && strtolower(trim((string)($row['cashier_email'] ?? ''))) === $email) {
        return true;
    }
    return false;
}

$uuidEsc = mysqli_real_escape_string($conn, $clientUuid);
$mapRes = mysqli_query($conn, "SELECT session_id, session_name FROM mobile_session_map WHERE client_uuid = '$uuidEsc' LIMIT 1");
$map = $mapRes ? mysqli_fetch_assoc($mapRes) : null;

if ($action === 'open') {
    $branchCheck = mobileAuthAcceptClientBranch($ctx, $body['branch'] ?? '');
    if (empty($branchCheck['ok'])) {
        respond([
            'success' => false,
            'error' => (string)($branchCheck['error'] ?? 'That branch is not available.'),
        ], 403);
    }
    $branch = (string)$branchCheck['branch'];
    if ($branch === '' || !psBranchIsRegistered($conn, $branch)) {
        respond(['success' => false, 'error' => 'That branch is not available.'], 403);
    }

    if ($map) {
        $mappedId = (int)$map['session_id'];
        $chk = mysqli_query(
            $conn,
            'SELECT id, cashier_id, cashier_email, branch, status, session_end, session_name
             FROM sessions WHERE id = ' . $mappedId . ' LIMIT 1'
        );
        $existing = $chk ? mysqli_fetch_assoc($chk) : null;
        if ($existing
            && mobile_session_owned_by($existing, $ctx)
            && psBranchNormalizeName($existing['branch'] ?? '') === $branch
        ) {
            respond([
                'success' => true,
                'action' => 'open',
                'client_uuid' => $clientUuid,
                'session_id' => (int)$existing['id'],
                'session_name' => (string)$existing['session_name'],
                'branch' => $branch,
                'idempotent' => true,
            ]);
        }
        // Stale / foreign map — do not reclaim; fail closed.
        respond([
            'success' => false,
            'error' => 'That session mapping is not valid for this user or branch.',
        ], 403);
    }

    $cashier = (string)$ctx['cashier'];
    $cashierId = (int)$ctx['user_id'];
    $openedAt = mobile_to_mysql_datetime($body['opened_at'] ?? null);
    $now = date('Y-m-d H:i:s');

    $register = tillEnsureRegister($conn, $branch);
    if (!$register) {
        respond(['success' => false, 'error' => 'Could not open till session.'], 500);
    }
    $registerId = (int)$register['id'];

    // One OPEN till per cashier (same guard as web).
    $cashierLookup = ['id' => $cashierId, 'email' => $cashier];
    $active = tillFetchActive($conn, $cashierLookup, false);
    if (!$active['ok']) {
        respond(['success' => false, 'error' => 'Could not open till session.'], 500);
    }
    if (!empty($active['row'])) {
        $openRow = $active['row'];
        $openBranch = psBranchNormalizeName($openRow['branch'] ?? '');
        if ($openBranch === '') {
            respond([
                'success' => false,
                'error' => 'You already have an open till with no branch assigned. Close it on the web POS first.',
            ], 409);
        }
        if ($openBranch !== $branch) {
            respond([
                'success' => false,
                'error' => 'You already have an open till on another branch.',
            ], 409);
        }
        // Reuse existing open till on same branch — map it.
        $sessionId = (int)$openRow['id'];
        $sessionName = (string)$openRow['session_name'];
        $nameEsc = mysqli_real_escape_string($conn, $sessionName);
        $nowEsc = mysqli_real_escape_string($conn, $now);
        mysqli_query($conn, "
            INSERT INTO mobile_session_map (client_uuid, session_id, session_name, synced_at)
            VALUES ('$uuidEsc', $sessionId, '$nameEsc', '$nowEsc')
        ");
        respond([
            'success' => true,
            'action' => 'open',
            'client_uuid' => $clientUuid,
            'session_id' => $sessionId,
            'session_name' => $sessionName,
            'branch' => $branch,
            'reused' => true,
        ]);
    }

    $emailEsc = mysqli_real_escape_string($conn, $cashier);
    $branchEsc = mysqli_real_escape_string($conn, $branch);
    $startEsc = mysqli_real_escape_string($conn, $openedAt);
    $nowEsc = mysqli_real_escape_string($conn, $now);
    $cashierIdSql = $cashierId > 0 ? (string)$cashierId : 'NULL';

    // session_name = cashier email (matches web tillOpen convention).
    $sql = "INSERT INTO sessions
        (session_name, session_start, session_end, cashier_id, cashier_email, branch, register_id, status, opening_float, opened_at, closed_at, created_at, updated_at)
        VALUES
        ('$emailEsc', '$startEsc', '', $cashierIdSql, '$emailEsc', '$branchEsc', $registerId, 'OPEN', 0, '$startEsc', NULL, '$nowEsc', '$nowEsc')";

    if (!mysqli_query($conn, $sql)) {
        if (mysqli_errno($conn) === 1062) {
            respond(['success' => false, 'error' => 'You already have an open till session.'], 409);
        }
        error_log('mobile session open failed');
        respond(['success' => false, 'error' => 'Could not open till session.'], 500);
    }

    $sessionId = (int)mysqli_insert_id($conn);
    if ($sessionId < 1) {
        respond(['success' => false, 'error' => 'Could not open till session.'], 500);
    }

    mysqli_query($conn, "
        INSERT INTO mobile_session_map (client_uuid, session_id, session_name, synced_at)
        VALUES ('$uuidEsc', $sessionId, '$emailEsc', '$nowEsc')
    ");

    respond([
        'success' => true,
        'action' => 'open',
        'client_uuid' => $clientUuid,
        'session_id' => $sessionId,
        'session_name' => $cashier,
        'branch' => $branch,
    ]);
}

// --- close ---
$closedAt = mobile_to_mysql_datetime($body['closed_at'] ?? null);
$endEsc = mysqli_real_escape_string($conn, $closedAt);

$sessionId = $map ? (int)$map['session_id'] : 0;
$sessionName = $map ? (string)$map['session_name'] : '';

// Optional explicit session_id from client — still ownership-checked.
$postedSessionId = trim((string)($body['session_id'] ?? ''));
if ($sessionId <= 0 && preg_match('/^\d+$/', $postedSessionId)) {
    $sessionId = (int)$postedSessionId;
}

if ($sessionId <= 0) {
    respond([
        'success' => false,
        'error' => 'No matching open session on server. Open session online once, then close again.',
    ], 404);
}

$find = mysqli_query(
    $conn,
    'SELECT id, session_name, cashier_id, cashier_email, branch, status, session_end
     FROM sessions WHERE id = ' . (int)$sessionId . ' LIMIT 1'
);
$row = $find ? mysqli_fetch_assoc($find) : null;
if (!$row) {
    respond(['success' => false, 'error' => 'No matching open session on server.'], 404);
}
if (!mobile_session_owned_by($row, $ctx)) {
    respond(['success' => false, 'error' => 'That till session is not available.'], 403);
}

// Do not auto-assign branch on NULL/blank historical sessions — close in place only.
$sessionName = (string)$row['session_name'];
$sessionId = (int)$row['id'];

$closed = mysqli_query(
    $conn,
    "UPDATE sessions
     SET session_end = '$endEsc', status = 'CLOSED', closed_at = '$endEsc', updated_at = '$endEsc'
     WHERE id = $sessionId
       AND cashier_id = " . (int)$ctx['user_id']
);
if (!$closed || mysqli_affected_rows($conn) < 1) {
    // Fallback when cashier_id null but email matches (legacy rows) — still no branch rewrite.
    $emailEsc = mysqli_real_escape_string($conn, (string)$ctx['email']);
    $closed = mysqli_query(
        $conn,
        "UPDATE sessions
         SET session_end = '$endEsc', status = 'CLOSED', closed_at = '$endEsc', updated_at = '$endEsc'
         WHERE id = $sessionId
           AND LOWER(cashier_email) = LOWER('$emailEsc')"
    );
}
if (!$closed || mysqli_affected_rows($conn) < 1) {
    // Soft close legacy columns only if ownership already verified.
    $closed = mysqli_query($conn, "UPDATE sessions SET session_end = '$endEsc' WHERE id = $sessionId");
}
if (!$closed) {
    error_log('mobile session close failed');
    respond(['success' => false, 'error' => 'Close failed'], 500);
}

if (!$map) {
    $nameEsc = mysqli_real_escape_string($conn, $sessionName);
    $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "
        INSERT INTO mobile_session_map (client_uuid, session_id, session_name, synced_at)
        VALUES ('$uuidEsc', $sessionId, '$nameEsc', '$now')
        ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), session_name = VALUES(session_name), synced_at = VALUES(synced_at)
    ");
}

respond([
    'success' => true,
    'action' => 'close',
    'client_uuid' => $clientUuid,
    'session_id' => $sessionId,
    'session_name' => $sessionName,
    'session_end' => $closedAt,
    'branch' => psBranchNormalizeName($row['branch'] ?? ''),
]);
