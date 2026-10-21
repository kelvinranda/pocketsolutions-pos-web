<?php
/**
 * Till sessions live in the existing `sessions` table.
 * `id` is the session identity. `session_name` stays filled (cashier email on
 * new rows) because that column is NOT NULL and older code still reads it.
 * Open rows keep session_end = '' so the legacy flag stays in sync with status.
 */

function tillEnsureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);

    $registers = "CREATE TABLE IF NOT EXISTS registers (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        branch VARCHAR(250) NOT NULL,
        name VARCHAR(100) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        UNIQUE KEY uniq_register_branch_name (branch, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $registers)) {
        error_log('till schema: could not ensure registers');
        return false;
    }

    $sessionColumns = [
        'cashier_id' => 'INT NULL',
        'cashier_email' => 'VARCHAR(250) NULL',
        'branch' => 'VARCHAR(250) NULL',
        'register_id' => 'INT NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'OPEN'",
        'opening_float' => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
        'opened_at' => 'DATETIME NULL',
        'closed_at' => 'DATETIME NULL',
        'created_at' => 'DATETIME NULL',
        'updated_at' => 'DATETIME NULL',
        'expected_cash' => 'DECIMAL(14,2) NULL',
        'actual_cash' => 'DECIMAL(14,2) NULL',
        'variance' => 'DECIMAL(14,2) NULL',
        // Phase 7.4 closure snapshot (written only by controlled close; unused until then)
        'closed_by' => 'VARCHAR(250) NULL',
        'final_reconciliation_id' => 'INT NULL',
        'closure_notes' => 'TEXT NULL',
        'reconciliation_status' => 'VARCHAR(32) NULL',
    ];
    foreach ($sessionColumns as $column => $definition) {
        if (!tillAddColumn($conn, 'sessions', $column, $definition)) {
            return false;
        }
    }
    if (!tillAddColumn($conn, 'online_sales_ref', 'till_session_id', 'INT NULL DEFAULT NULL')) {
        return false;
    }

    if (!tillSyncLegacySessions($conn)) {
        return false;
    }
    if (!tillEnsureOpenGuard($conn)) {
        return false;
    }
    // Phase 7.6 — align session money columns with cash_movements DECIMAL(14,2).
    if (!tillAlignSessionMoneyPrecision($conn)) {
        return false;
    }

    $ready = true;
    return true;
}

/**
 * Idempotent: widen sessions money snapshot columns to DECIMAL(14,2) to match the ledger.
 */
function tillAlignSessionMoneyPrecision(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $cols = [
        'opening_float' => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'expected_cash' => 'DECIMAL(14,2) NULL',
        'actual_cash' => 'DECIMAL(14,2) NULL',
        'variance' => 'DECIMAL(14,2) NULL',
    ];
    foreach ($cols as $column => $definition) {
        if (!tillHasColumn($conn, 'sessions', $column)) {
            continue;
        }
        $res = mysqli_query($conn, "SHOW COLUMNS FROM sessions LIKE '$column'");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        $type = strtolower((string)($row['Type'] ?? ''));
        if (strpos($type, 'decimal(14,2)') !== false) {
            continue;
        }
        $ok = mysqli_query($conn, "ALTER TABLE sessions MODIFY COLUMN `$column` $definition");
        if (!$ok) {
            error_log('till schema: money precision align failed for ' . $column);
            return false;
        }
    }
    $done = true;
    return true;
}

function tillAddColumn(mysqli $conn, string $table, string $column, string $definition): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    if (tillHasColumn($conn, $table, $column)) {
        return true;
    }
    $ok = mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    if ($ok || mysqli_errno($conn) === 1060) {
        return true;
    }
    error_log('till schema: add column failed');
    return false;
}

function tillHasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

function tillHasIndex(mysqli $conn, string $table, string $index): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $index = preg_replace('/[^A-Za-z0-9_]/', '', $index);
    $res = mysqli_query($conn, "SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && mysqli_num_rows($res) > 0;
}

function tillSyncLegacySessions(mysqli $conn): bool
{
    $status = mysqli_query($conn, "
        UPDATE sessions
        SET status = 'CLOSED'
        WHERE status = 'OPEN'
          AND session_end <> ''
    ");
    if (!$status) {
        error_log('till schema: status sync failed');
        return false;
    }

    $opened = mysqli_query($conn, "
        UPDATE sessions
        SET opened_at = STR_TO_DATE(session_start, '%Y-%m-%d %H:%i:%s')
        WHERE opened_at IS NULL
          AND session_start REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'
    ");
    if (!$opened) {
        error_log('till schema: opened_at sync failed');
        return false;
    }

    $closed = mysqli_query($conn, "
        UPDATE sessions
        SET closed_at = STR_TO_DATE(session_end, '%Y-%m-%d %H:%i:%s')
        WHERE closed_at IS NULL
          AND session_end REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'
    ");
    if (!$closed) {
        error_log('till schema: closed_at sync failed');
        return false;
    }

    $res = mysqli_query($conn, "SELECT id, session_name FROM sessions WHERE cashier_id IS NULL");
    if (!$res) {
        error_log('till schema: cashier backfill read failed');
        return false;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $name = (string)$row['session_name'];
        if (!preg_match('/^(.+)-\d{8}-\d{6}-\d+$/', $name, $match)) {
            continue;
        }
        $emailEsc = mysqli_real_escape_string($conn, $match[1]);
        $userRes = mysqli_query($conn, "SELECT id, email FROM users WHERE LOWER(email) = LOWER('$emailEsc') LIMIT 1");
        if (!$userRes || !($user = mysqli_fetch_assoc($userRes))) {
            continue;
        }
        $id = (int)$row['id'];
        $userId = (int)$user['id'];
        $storedEmail = mysqli_real_escape_string($conn, (string)$user['email']);
        if (!mysqli_query($conn, "UPDATE sessions SET cashier_id = $userId, cashier_email = '$storedEmail' WHERE id = $id AND cashier_id IS NULL")) {
            // 2026-10-18: an old OPEN row for a cashier who already has a newer open till hits the
            // one-open-till guard (1062). Leave that row as it is; failing here blocked every till.
            if (mysqli_errno($conn) === 1062) {
                error_log('till schema: cashier backfill skipped for session ' . $id . ' (cashier already has an open till)');
                continue;
            }
            error_log('till schema: cashier backfill write failed: #' . mysqli_errno($conn) . ' ' . mysqli_error($conn));
            return false;
        }
    }

    return true;
}

function tillEnsureOpenGuard(mysqli $conn): bool
{
    if (!tillHasColumn($conn, 'sessions', 'open_guard')) {
        tillCloseDuplicateOpens($conn);
        $added = mysqli_query($conn, "
            ALTER TABLE sessions
            ADD COLUMN open_guard VARCHAR(300)
            GENERATED ALWAYS AS (
                IF(status <> 'OPEN', NULL,
                    IF(cashier_id IS NOT NULL, CONCAT('id:', cashier_id),
                        IF(cashier_email IS NOT NULL AND cashier_email <> '', CONCAT('em:', cashier_email), NULL)
                    )
                )
            ) PERSISTENT
        ");
        if (!$added && mysqli_errno($conn) !== 1060) {
            error_log('till schema: open guard column was not added');
            return true;
        }
    }

    if (!tillHasIndex($conn, 'sessions', 'uniq_open_cashier')) {
        tillCloseDuplicateOpens($conn);
        $indexed = mysqli_query($conn, "ALTER TABLE sessions ADD UNIQUE KEY uniq_open_cashier (open_guard)");
        if (!$indexed && mysqli_errno($conn) !== 1061) {
            error_log('till schema: open guard index was not added');
        }
    }

    return true;
}

function tillCloseDuplicateOpens(mysqli $conn): void
{
    $now = date('Y-m-d H:i:s');
    $nowEsc = mysqli_real_escape_string($conn, $now);
    mysqli_query($conn, "
        UPDATE sessions s
        INNER JOIN (
            SELECT cashier_id, MAX(id) AS keep_id
            FROM sessions
            WHERE status = 'OPEN' AND cashier_id IS NOT NULL AND session_end = ''
            GROUP BY cashier_id
            HAVING COUNT(*) > 1
        ) d ON s.cashier_id = d.cashier_id AND s.id <> d.keep_id
        SET s.status = 'CLOSED', s.session_end = '$nowEsc', s.closed_at = '$nowEsc', s.updated_at = '$nowEsc'
        WHERE s.status = 'OPEN' AND s.session_end = ''
    ");
    mysqli_query($conn, "
        UPDATE sessions s
        INNER JOIN (
            SELECT cashier_email, MAX(id) AS keep_id
            FROM sessions
            WHERE status = 'OPEN' AND cashier_id IS NULL AND cashier_email IS NOT NULL AND cashier_email <> '' AND session_end = ''
            GROUP BY cashier_email
            HAVING COUNT(*) > 1
        ) d ON s.cashier_email = d.cashier_email AND s.id <> d.keep_id
        SET s.status = 'CLOSED', s.session_end = '$nowEsc', s.closed_at = '$nowEsc', s.updated_at = '$nowEsc'
        WHERE s.status = 'OPEN' AND s.cashier_id IS NULL AND s.session_end = ''
    ");
}

function tillCurrentCashier(mysqli $conn): array
{
    $email = trim((string)($_SESSION['logger'] ?? ''));
    $id = 0;
    if ($email !== '') {
        $emailEsc = mysqli_real_escape_string($conn, $email);
        $res = mysqli_query($conn, "SELECT id, email FROM users WHERE LOWER(email) = LOWER('$emailEsc') LIMIT 1");
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $id = (int)$row['id'];
            $email = (string)$row['email'];
        }
    }
    return ['id' => $id, 'email' => $email];
}

/**
 * Working branch for till operations.
 * Phase 9.2: ordinary users cannot use a foreign selected_branch; admin selected
 * is returned here and validated against branchies where a mysqli handle exists.
 */
function tillSelectedBranch(): string
{
    require_once __DIR__ . '/branch_access.php';
    if (!psBranchSessionAuthenticated()) {
        return '';
    }
    $selected = psBranchSelectedRaw();
    $home = psBranchHomeName();
    if (psBranchSessionIsAdmin()) {
        return $selected !== '' ? $selected : $home;
    }
    // Ordinary: authorised set is home only.
    if ($selected !== '') {
        return ($home !== '' && $selected === $home) ? $selected : '';
    }
    return $home;
}

function tillCashierMatchSql(mysqli $conn, array $cashier): string
{
    $parts = [];
    if (($cashier['id'] ?? 0) > 0) {
        $parts[] = 's.cashier_id = ' . (int)$cashier['id'];
    }
    $email = trim((string)($cashier['email'] ?? ''));
    if ($email !== '') {
        $emailEsc = mysqli_real_escape_string($conn, $email);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $emailEsc);
        $parts[] = "LOWER(s.cashier_email) = LOWER('$emailEsc')";
        $parts[] = "LOWER(s.session_name) = LOWER('$emailEsc')";
        $parts[] = "LOWER(s.session_name) LIKE LOWER('$like-%')";
    }
    if (!$parts) {
        return '0';
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function tillSessionSelect(): string
{
    return "s.id, s.session_name, s.session_start, s.session_end, s.cashier_id, s.cashier_email,
            s.branch, s.register_id, s.status, s.opening_float, s.opened_at, s.closed_at,
            s.expected_cash, s.actual_cash, s.variance, s.closed_by, s.final_reconciliation_id,
            s.closure_notes, s.reconciliation_status,
            r.name AS register_name";
}

function tillFetchActive(mysqli $conn, array $cashier, bool $lock = false): array
{
    $match = tillCashierMatchSql($conn, $cashier);
    $sql = "SELECT " . tillSessionSelect() . "
            FROM sessions s
            LEFT JOIN registers r ON r.id = s.register_id
            WHERE s.status = 'OPEN'
              AND s.session_end = ''
              AND $match
            ORDER BY (s.cashier_id IS NULL), s.id DESC
            LIMIT 1";
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $res = mysqli_query($conn, $sql);
    if (!$res && $lock) {
        $res = mysqli_query($conn, substr($sql, 0, -strlen(' FOR UPDATE')));
    }
    if (!$res) {
        error_log('till: active lookup failed');
        return ['ok' => false, 'row' => null];
    }
    $row = mysqli_fetch_assoc($res);
    return ['ok' => true, 'row' => $row ?: null];
}

function getActiveTillSession(mysqli $conn): ?array
{
    if (!tillEnsureSchema($conn)) {
        return null;
    }
    $found = tillFetchActive($conn, tillCurrentCashier($conn), false);
    if (!$found['ok']) {
        return null;
    }
    return $found['row'];
}

function tillEnsureRegister(mysqli $conn, string $branch): ?array
{
    $branchEsc = mysqli_real_escape_string($conn, $branch);
    $res = mysqli_query($conn, "SELECT id, name, status FROM registers WHERE branch = '$branchEsc' AND status = 'ACTIVE' ORDER BY id ASC LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        return $row;
    }
    $inserted = mysqli_query($conn, "INSERT INTO registers (branch, name, status) VALUES ('$branchEsc', 'Till 01', 'ACTIVE')");
    if ($inserted) {
        return [
            'id' => (string)mysqli_insert_id($conn),
            'name' => 'Till 01',
            'status' => 'ACTIVE',
        ];
    }
    if (mysqli_errno($conn) === 1062) {
        $again = mysqli_query($conn, "SELECT id, name, status FROM registers WHERE branch = '$branchEsc' ORDER BY id ASC LIMIT 1");
        if ($again && ($row = mysqli_fetch_assoc($again))) {
            return $row;
        }
    }
    error_log('till: register create failed');
    return null;
}

function tillParseFloat($raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'value' => 0.0];
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => true, 'value' => 0.0];
    }
    if (!is_numeric($raw)) {
        return ['ok' => false, 'error' => 'Opening float must be zero or greater.'];
    }
    $value = round((float)$raw, 2);
    if ($value < 0) {
        return ['ok' => false, 'error' => 'Opening float must be zero or greater.'];
    }
    if ($value > 9999999999.99) {
        return ['ok' => false, 'error' => 'Opening float is too large.'];
    }
    return ['ok' => true, 'value' => $value];
}

function tillRequestFloat()
{
    if (isset($_POST['opening_float'])) {
        return $_POST['opening_float'];
    }
    if (isset($_GET['opening_float'])) {
        return $_GET['opening_float'];
    }
    if (isset($_POST['float'])) {
        return $_POST['float'];
    }
    if (isset($_GET['float'])) {
        return $_GET['float'];
    }
    return '0';
}

function tillOpen(mysqli $conn): string
{
    if (!tillEnsureSchema($conn)) {
        return 'Could not open the till session.';
    }

    require_once __DIR__ . '/branch_access.php';
    require_once __DIR__ . '/branch_integrity.php';
    $branchResolved = psBranchResolveWorking($conn);
    if (empty($branchResolved['ok'])) {
        return $branchResolved['error'] ?? 'Select a branch before opening a till.';
    }
    // Phase 9.5 soft integrity: NEW till opens require registered branchies name (trim+exact).
    $regWrite = psBranchRequireRegisteredWrite($conn, $branchResolved['branch'] ?? '');
    if (empty($regWrite['ok'])) {
        return 'Select a branch before opening a till.';
    }
    $branch = (string)$regWrite['branch'];

    $parsed = tillParseFloat(tillRequestFloat());
    if (!$parsed['ok']) {
        return $parsed['error'];
    }
    $floatSql = sprintf('%.2f', $parsed['value']);

    $cashier = tillCurrentCashier($conn);
    if ($cashier['email'] === '') {
        return 'Could not open the till session.';
    }

    $existing = tillFetchActive($conn, $cashier, false);
    if (!$existing['ok']) {
        return 'Could not open the till session.';
    }
    if ($existing['row']) {
        return 'You already have an open till session.';
    }

    $register = tillEnsureRegister($conn, $branch);
    if (!$register) {
        return 'Could not open the till session.';
    }

    $now = date('Y-m-d H:i:s');
    $nowEsc = mysqli_real_escape_string($conn, $now);
    $emailEsc = mysqli_real_escape_string($conn, $cashier['email']);
    $branchEsc = mysqli_real_escape_string($conn, $branch);
    $cashierIdSql = $cashier['id'] > 0 ? (string)(int)$cashier['id'] : 'NULL';
    $registerId = (int)$register['id'];

    mysqli_begin_transaction($conn);
    $locked = tillFetchActive($conn, $cashier, true);
    if (!$locked['ok']) {
        mysqli_rollback($conn);
        return 'Could not open the till session.';
    }
    if ($locked['row']) {
        mysqli_rollback($conn);
        return 'You already have an open till session.';
    }

    $sql = "INSERT INTO sessions
        (session_name, session_start, session_end, cashier_id, cashier_email, branch, register_id, status, opening_float, opened_at, closed_at, created_at, updated_at)
        VALUES
        ('$emailEsc', '$nowEsc', '', $cashierIdSql, '$emailEsc', '$branchEsc', $registerId, 'OPEN', $floatSql, '$nowEsc', NULL, '$nowEsc', '$nowEsc')";
    $inserted = mysqli_query($conn, $sql);
    if (!$inserted) {
        $duplicate = mysqli_errno($conn) === 1062;
        mysqli_rollback($conn);
        if ($duplicate) {
            return 'You already have an open till session.';
        }
        error_log('till: open insert failed');
        return 'Could not open the till session.';
    }

    // Phase 7.1 — OPENING_FLOAT cash movement when opening cash > 0 (same txn).
    // sessions.opening_float remains the open-time metadata snapshot; cash_movements is
    // authoritative for cash calculations. Zero float does not invent a movement.
    $sessionId = (int)mysqli_insert_id($conn);
    if ($sessionId > 0 && (float)$parsed['value'] > 0) {
        require_once __DIR__ . '/cash_movement.php';
        if (!psEnsureCashMovementModule($conn)) {
            mysqli_rollback($conn);
            return 'Could not open the till session.';
        }
        $floatMoney = number_format((float)$parsed['value'], 2, '.', '');
        $floatPost = psCashMovementPostOpeningFloat(
            $conn,
            $sessionId,
            $branch,
            $floatMoney,
            $cashier['email'],
            $now
        );
        if (empty($floatPost['ok'])) {
            mysqli_rollback($conn);
            if (!empty($floatPost['duplicate'])) {
                return 'Opening float was already recorded for this till session.';
            }
            error_log('till: opening float cash movement failed');
            return 'Could not open the till session.';
        }
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return 'Could not open the till session.';
    }

    $tillName = (string)$register['name'];
    $money = number_format($parsed['value'], 2, '.', '');
    return 'Till opened. Till: ' . $tillName . '. Branch: ' . $branch . '. Opening float: ' . $money;
}

/**
 * Phase 7.4 — unprotected close removed.
 * Closing requires a current FINAL cash reconciliation via cash_till_close.php /
 * psTillClosureClose. This helper no longer flips OPEN→CLOSED without that path.
 */
function tillClose(mysqli $conn): string
{
    if (!tillEnsureSchema($conn)) {
        return 'Could not close the till session.';
    }
    $row = getActiveTillSession($conn);
    if (!$row) {
        return 'There is no open till session.';
    }
    // Do not close here — redirect callers to the controlled close UI.
    return 'Till closure requires a current final cash reconciliation. Open Cash Till Close to finish.';
}

function tillPlain(string $value): string
{
    return str_replace(["\r", "\n"], ' ', $value);
}

function tillRegisterName(array $row): string
{
    $name = trim((string)($row['register_name'] ?? ''));
    $name = $name !== '' ? $name : 'Unassigned';
    // 2026-10-13: every session on a branch shares register "Till 01", so show whose
    // session it is ("Till 01 · Kudakwashe") wherever a single till session is labelled.
    $who = tillCashierDisplayName((string)($row['cashier_email'] ?? ''));
    return $who !== '' ? $name . ' · ' . $who : $name;
}

/**
 * Short display name for a till's cashier: users.firstname when available,
 * else the part of the login before "@". Cached per request.
 */
function tillCashierDisplayName(string $email): string
{
    static $cache = [];
    $email = trim($email);
    if ($email === '') {
        return '';
    }
    $key = strtolower($email);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $name = '';
    if (function_exists('psConfigResolve')) {
        $cfg = psConfigResolve();
        if ($cfg['dbname'] !== '') {
            mysqli_report(MYSQLI_REPORT_OFF);
            $c = @mysqli_connect($cfg['servername'], $cfg['username'], $cfg['password'], $cfg['dbname']);
            if ($c) {
                mysqli_set_charset($c, 'utf8mb4');
                $stmt = mysqli_prepare($c, 'SELECT firstname FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 's', $email);
                    if (mysqli_stmt_execute($stmt)) {
                        $res = mysqli_stmt_get_result($stmt);
                        $row = $res ? mysqli_fetch_assoc($res) : null;
                        $name = trim((string)($row['firstname'] ?? ''));
                    }
                    mysqli_stmt_close($stmt);
                }
                mysqli_close($c);
            }
        }
    }
    if ($name === '') {
        $name = strstr($email, '@', true) ?: $email;
    }
    $name = ucwords(strtolower($name));
    if (strlen($name) > 24) {
        $name = substr($name, 0, 24);
    }
    $cache[$key] = $name;
    return $name;
}

function tillOpenedAt(array $row): string
{
    $opened = trim((string)($row['opened_at'] ?? ''));
    if ($opened === '' || strpos($opened, '0000-00-00') === 0) {
        $opened = trim((string)($row['session_start'] ?? ''));
    }
    return $opened;
}

function tillStatusText(mysqli $conn): string
{
    if (!tillEnsureSchema($conn)) {
        return 'Could not read the till session.';
    }
    $row = getActiveTillSession($conn);
    if (!$row) {
        return 'No active till session';
    }
    $cashier = tillCurrentCashier($conn);
    $aligned = tillAlignWorkingBranchToOpenSession($conn, $row, $cashier);
    if (!empty($aligned['ok'])) {
        $row = $aligned['session'];
    }
    $email = $cashier['email'] !== '' ? $cashier['email'] : (string)($row['cashier_email'] ?? '');
    $branch = trim((string)($row['branch'] ?? ''));
    require_once __DIR__ . '/branch_access.php';
    $uiWorking = psBranchResolveWorking($conn);
    $uiBranch = !empty($uiWorking['ok']) ? psBranchNormalizeName($uiWorking['branch'] ?? '') : psBranchSelectedRaw();
    $lines = [
        'Till: ' . tillPlain(tillRegisterName($row)),
        'Till branch: ' . tillPlain($branch !== '' ? $branch : '(not assigned)'),
        'UI branch: ' . tillPlain($uiBranch !== '' ? $uiBranch : '(none)'),
        'Cashier: ' . tillPlain($email),
        'Status: OPEN',
        'Opened at: ' . tillPlain(tillOpenedAt($row)),
        'Opening float: ' . number_format((float)$row['opening_float'], 2, '.', ''),
    ];
    if (!empty($aligned['ok']) && $branch !== '' && $uiBranch !== '' && $branch !== $uiBranch) {
        $lines[] = 'Note: Till branch (' . tillPlain($branch) . ') differs from UI selection ('
            . tillPlain($uiBranch) . '). Sales use the till branch.';
    }
    if ($branch === '') {
        $lines[] = 'Note: Till has no branch — open Cash Reconciliation to bind it to your working branch, then close.';
    } elseif (!empty($aligned['bound'])) {
        $lines[] = 'Note: Legacy till branch was set to ' . tillPlain($branch) . '.';
    } elseif (!empty($aligned['synced'])) {
        $lines[] = 'Note: Working branch synced to till branch ' . tillPlain($branch) . '.';
    } elseif (empty($aligned['ok']) && !empty($aligned['error'])) {
        $lines[] = 'Note: ' . tillPlain((string)$aligned['error']);
    }
    $lines[] = 'To close: Cash Reconciliation (Finalise) → Cash Till Close.';
    return implode("\n", $lines);
}

function tillStatusPayload(mysqli $conn): array
{
    if (!tillEnsureSchema($conn)) {
        return ['open' => false, 'label' => 'Till: Not Open'];
    }
    $row = getActiveTillSession($conn);
    if (!$row) {
        return ['open' => false, 'label' => 'Till: Not Open', 'status' => 'NONE'];
    }
    $cashier = tillCurrentCashier($conn);
    $aligned = tillAlignWorkingBranchToOpenSession($conn, $row, $cashier);
    $alignError = '';
    if (!empty($aligned['ok'])) {
        $row = $aligned['session'];
    } else {
        $alignError = (string)($aligned['error'] ?? '');
    }
    $till = tillRegisterName($row);
    $branch = trim((string)($row['branch'] ?? ''));
    require_once __DIR__ . '/branch_access.php';
    $uiWorking = psBranchResolveWorking($conn);
    $uiBranch = !empty($uiWorking['ok']) ? psBranchNormalizeName($uiWorking['branch'] ?? '') : psBranchSelectedRaw();
    $branchDiffers = ($branch !== '' && $uiBranch !== '' && $branch !== $uiBranch);
    $note = '';
    if ($alignError !== '') {
        $note = $alignError;
    } elseif (!empty($aligned['synced']) && $branch !== '') {
        $note = 'You\'re selling on ' . $branch . ' because your till is open there. To sell at another branch, close this till first.';
    } elseif (!empty($aligned['bound']) && $branch !== '') {
        $note = 'This till had no branch; it was assigned to ' . $branch . '.';
    } elseif ($branchDiffers) {
        $note = 'You\'re selling on ' . $branch . ' because your till is open there. To sell at another branch, close this till first.';
    }
    $label = 'Till: ' . $till . ' · OPEN';
    if ($branch !== '') {
        $label .= ' · ' . $branch;
    }
    // Health-check 2026-10-13: a till should be reconciled and closed every day.
    $openedAt = tillOpenedAt($row);
    $openHours = null;
    $openedTs = $openedAt !== '' ? strtotime($openedAt) : false;
    if ($openedTs !== false) {
        $openHours = (int)floor((time() - $openedTs) / 3600);
    }
    $staleWarning = '';
    if ($openHours !== null && $openHours >= 24) {
        $days = (int)floor($openHours / 24);
        $staleWarning = 'This till has been open for ' . ($days >= 1 ? $days . ' day' . ($days === 1 ? '' : 's') : $openHours . ' hours')
            . ' (since ' . $openedAt . '). Reconcile and close it, then open a new till for today.';
    }
    return [
        'open' => true,
        'label' => $label,
        'open_hours' => $openHours,
        'stale_warning' => $staleWarning,
        'till' => $till,
        'branch' => $branch,
        'ui_branch' => $uiBranch,
        'branch_differs' => $branchDiffers,
        'status' => 'OPEN',
        'opened_at' => tillOpenedAt($row),
        'opening_float' => number_format((float)$row['opening_float'], 2, '.', ''),
        'bound_branch' => !empty($aligned['bound']),
        'synced_branch' => !empty($aligned['synced']),
        'align_ok' => !empty($aligned['ok']),
        'align_error' => $alignError,
        'align_note' => $note,
        'needs_recon_to_close' => true,
        'sale_ready' => !empty($aligned['ok']),
    ];
}

function tillSessionById(mysqli $conn, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $res = mysqli_query($conn, "SELECT " . tillSessionSelect() . "
        FROM sessions s
        LEFT JOIN registers r ON r.id = s.register_id
        WHERE s.id = $id
        LIMIT 1");
    if (!$res) {
        error_log('till: session lookup failed');
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

function tillOwns(array $row, array $cashier): bool
{
    $email = strtolower(trim((string)($cashier['email'] ?? '')));
    if (($cashier['id'] ?? 0) > 0 && (int)($row['cashier_id'] ?? 0) === (int)$cashier['id']) {
        return true;
    }
    if ($email === '') {
        return false;
    }
    $rowEmail = strtolower(trim((string)($row['cashier_email'] ?? '')));
    if ($rowEmail !== '' && $rowEmail === $email) {
        return true;
    }
    $name = strtolower(trim((string)($row['session_name'] ?? '')));
    if ($name === $email || strpos($name, $email . '-') === 0) {
        return true;
    }
    return false;
}

function tillRowIsOpen(array $row): bool
{
    return strtoupper(trim((string)($row['status'] ?? ''))) === 'OPEN'
        && trim((string)($row['session_end'] ?? '')) === '';
}

/**
 * One-time bind of a legacy OPEN till with NULL/blank branch to the caller's
 * authorised working branch. Never overwrites a non-blank (real) till branch.
 *
 * Used so till operators can sell / reconcile / close without a dead-end
 * "branch does not match" error on pre-Phase-9 sessions.
 *
 * @return array{ok:bool,session?:array,branch?:string,bound?:bool,error?:string}
 */
function tillBindBlankBranchIfNeeded(mysqli $conn, array $session, array $cashier = []): array
{
    require_once __DIR__ . '/branch_access.php';
    require_once __DIR__ . '/branch_integrity.php';

    $sessionId = (int)($session['id'] ?? 0);
    if ($sessionId <= 0 || !tillRowIsOpen($session)) {
        return ['ok' => false, 'error' => 'Your till session is no longer open. Please reopen the till.'];
    }
    if ($cashier && !tillOwns($session, $cashier)) {
        return ['ok' => false, 'error' => "You cannot access another cashier's till session."];
    }

    $sessionBranch = psBranchNormalizeName($session['branch'] ?? '');
    if ($sessionBranch !== '') {
        return ['ok' => true, 'session' => $session, 'branch' => $sessionBranch, 'bound' => false];
    }

    $working = psBranchResolveWorking($conn);
    if (empty($working['ok'])) {
        return [
            'ok' => false,
            'error' => $working['error'] ?? 'Select a branch before using the till.',
        ];
    }
    $regWrite = psBranchRequireRegisteredWrite($conn, $working['branch'] ?? '');
    if (empty($regWrite['ok'])) {
        return ['ok' => false, 'error' => 'Select a branch before using the till.'];
    }
    $branch = (string)$regWrite['branch'];
    if (!psBranchIsAuthorised($conn, $branch)) {
        return [
            'ok' => false,
            'error' => 'This till has no branch assigned, and you are not authorised to bind it to '
                . $branch . '. Close the till from an authorised account, or contact an administrator.',
        ];
    }

    $register = tillEnsureRegister($conn, $branch);
    if (!$register) {
        return ['ok' => false, 'error' => 'Could not prepare the till session.'];
    }
    $registerId = (int)$register['id'];
    $branchEsc = mysqli_real_escape_string($conn, $branch);
    $nowEsc = mysqli_real_escape_string($conn, date('Y-m-d H:i:s'));

    // Concurrency-safe: only bind while branch is still NULL/blank.
    $sql = "UPDATE sessions
            SET branch = '$branchEsc',
                register_id = $registerId,
                updated_at = '$nowEsc'
            WHERE id = $sessionId
              AND status = 'OPEN'
              AND session_end = ''
              AND (branch IS NULL OR TRIM(branch) = '')";
    if (!mysqli_query($conn, $sql)) {
        error_log('till: blank branch bind failed for session ' . $sessionId);
        return ['ok' => false, 'error' => 'Could not assign a branch to this till session. Reload and try again.'];
    }
    $bound = mysqli_affected_rows($conn) > 0;

    $fresh = tillSessionById($conn, $sessionId);
    if (!$fresh || !tillRowIsOpen($fresh)) {
        return ['ok' => false, 'error' => 'Your till session is no longer open. Please reopen the till.'];
    }
    $freshBranch = psBranchNormalizeName($fresh['branch'] ?? '');
    if ($freshBranch === '') {
        return ['ok' => false, 'error' => 'Could not assign a branch to this till session. Reload and try again.'];
    }
    // Real branch already set (concurrent or race) — never reassign to a different one.
    if ($freshBranch !== $branch) {
        return [
            'ok' => false,
            'error' => 'Your till is open on ' . $freshBranch
                . '. Close that till before using ' . $branch
                . ', or switch working branch back to ' . $freshBranch . '.',
        ];
    }

    if ($bound) {
        error_log('till: bound blank OPEN till #' . $sessionId . ' to branch ' . $branch);
    }
    return ['ok' => true, 'session' => $fresh, 'branch' => $freshBranch, 'bound' => $bound];
}

/**
 * Align PHP working branch to the caller's own OPEN till for recon / close / cash ops.
 *
 * - Blank till → one-time bind via tillBindBlankBranchIfNeeded().
 * - Real till branch → if the caller is authorised for that branch, sync
 *   selected_branch to the till. Never rewrite a real till branch to match a
 *   drifted admin working branch (e.g. till Westlea + selected Village).
 *
 * @return array{ok:bool,session?:array,branch?:string,bound?:bool,synced?:bool,error?:string}
 */
function tillAlignWorkingBranchToOpenSession(mysqli $conn, array $session, array $cashier = []): array
{
    require_once __DIR__ . '/branch_access.php';

    $sessionId = (int)($session['id'] ?? 0);
    if ($sessionId <= 0 || !tillRowIsOpen($session)) {
        return ['ok' => false, 'error' => 'Your till session is no longer open. Please reopen the till.'];
    }
    if ($cashier === []) {
        $cashier = tillCurrentCashier($conn);
    }
    if (!tillOwns($session, $cashier)) {
        return ['ok' => false, 'error' => "You cannot access another cashier's till session."];
    }

    $bound = tillBindBlankBranchIfNeeded($conn, $session, $cashier);
    if (empty($bound['ok'])) {
        return [
            'ok' => false,
            'error' => (string)($bound['error'] ?? 'Could not align the working branch to this till session.'),
        ];
    }
    $session = $bound['session'];
    $tillBranch = psBranchNormalizeName($bound['branch'] ?? '');
    if ($tillBranch === '') {
        return ['ok' => false, 'error' => 'Select a branch before using the till.'];
    }
    if (!psBranchIsRegistered($conn, $tillBranch)) {
        return [
            'ok' => false,
            'error' => 'Your open till branch (' . $tillBranch . ') is not a registered branch. Contact an administrator.',
        ];
    }
    if (!psBranchIsAuthorised($conn, $tillBranch)) {
        return [
            'ok' => false,
            'error' => 'Your till is open on ' . $tillBranch
                . ', but you are not authorised for that branch. Close the till from an authorised account before continuing.',
        ];
    }

    $working = psBranchResolveWorking($conn);
    $workingBranch = !empty($working['ok']) ? psBranchNormalizeName($working['branch'] ?? '') : '';
    $synced = false;
    if ($workingBranch !== $tillBranch) {
        // Own open till wins over a drifted selected_branch so FINAL recon / sale can proceed.
        $_SESSION['selected_branch'] = $tillBranch;
        $synced = true;
        $working = psBranchResolveWorking($conn);
        $workingBranch = !empty($working['ok']) ? psBranchNormalizeName($working['branch'] ?? '') : '';
        if ($workingBranch !== $tillBranch) {
            return [
                'ok' => false,
                'error' => 'Your till is open on ' . $tillBranch
                    . '. Close that till before using another branch, or switch working branch back to '
                    . $tillBranch . '.',
            ];
        }
        error_log('till: synced working branch to open till #' . $sessionId . ' (' . $tillBranch . ')');
    }

    return [
        'ok' => true,
        'session' => $session,
        'branch' => $tillBranch,
        'bound' => !empty($bound['bound']),
        'synced' => $synced,
    ];
}

function tillLatestForCashier(mysqli $conn, array $cashier): ?array
{
    $match = tillCashierMatchSql($conn, $cashier);
    $res = mysqli_query($conn, "SELECT " . tillSessionSelect() . "
        FROM sessions s
        LEFT JOIN registers r ON r.id = s.register_id
        WHERE $match
        ORDER BY s.id DESC
        LIMIT 1");
    if (!$res) {
        error_log('till: latest lookup failed');
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

function tillClientSessionId(): int
{
    if (isset($_POST['till_session_id']) && $_POST['till_session_id'] !== '') {
        return (int)$_POST['till_session_id'];
    }
    if (isset($_POST['session_id']) && $_POST['session_id'] !== '') {
        return (int)$_POST['session_id'];
    }
    return 0;
}

function tillSaleDecision(mysqli $conn): array
{
    if (!tillEnsureSchema($conn)) {
        return ['error' => 'Could not prepare the till session.'];
    }
    $cashier = tillCurrentCashier($conn);
    if ($cashier['email'] === '') {
        return ['error' => 'Please open a till session before completing a sale.'];
    }

    $clientId = tillClientSessionId();
    if ($clientId > 0) {
        $named = tillSessionById($conn, $clientId);
        if (!$named || !tillOwns($named, $cashier)) {
            return ['error' => "You cannot access another cashier's till session."];
        }
        if (!tillRowIsOpen($named)) {
            return ['error' => 'Your till session is no longer open. Please reopen the till.'];
        }
    }

    $active = getActiveTillSession($conn);
    if ($active) {
        if ($clientId > 0 && $clientId !== (int)$active['id']) {
            return ['error' => "You cannot access another cashier's till session."];
        }
        return ['session' => $active];
    }

    $latest = tillLatestForCashier($conn, $cashier);
    if ($latest && !tillRowIsOpen($latest)) {
        return ['error' => 'Your till session is no longer open. Please reopen the till.'];
    }
    return ['error' => 'Please open a till session before completing a sale.'];
}

function tillLockSaleSession(mysqli $conn, int $clientId): array
{
    $cashier = tillCurrentCashier($conn);
    if (trim((string)($cashier['email'] ?? '')) === '') {
        return ['error' => 'Please sign in again.'];
    }

    if ($clientId > 0) {
        $named = tillSessionById($conn, $clientId);
        if (!$named || !tillOwns($named, $cashier)) {
            return ['error' => "You cannot access another cashier's till session."];
        }
        if (!tillRowIsOpen($named)) {
            return ['error' => 'Your till session is no longer open. Please reopen the till.'];
        }
    }

    $match = tillCashierMatchSql($conn, $cashier);
    $lockRes = mysqli_query($conn, "SELECT s.id
        FROM sessions s
        WHERE s.status = 'OPEN'
          AND s.session_end = ''
          AND $match
        ORDER BY s.id DESC
        LIMIT 1
        FOR UPDATE");
    if (!$lockRes) {
        error_log('till: sale lock failed');
        return ['error' => 'Could not prepare the till session.'];
    }
    $locked = mysqli_fetch_assoc($lockRes);
    if ($locked) {
        $active = tillSessionById($conn, (int)$locked['id']);
        if (!$active || !tillRowIsOpen($active) || !tillOwns($active, $cashier)) {
            return ['error' => 'Your till session is no longer open. Please reopen the till.'];
        }
        if ($clientId > 0 && $clientId !== (int)$active['id']) {
            return ['error' => "You cannot access another cashier's till session."];
        }
        // Blank till bind + sync drifted working branch to the open till (never rewrite till).
        $aligned = tillAlignWorkingBranchToOpenSession($conn, $active, $cashier);
        if (empty($aligned['ok'])) {
            return ['error' => $aligned['error'] ?? 'Could not align the working branch to this till session.'];
        }
        $active = $aligned['session'];
        $sessionBranch = (string)$aligned['branch'];
        return ['session' => $active, 'branch' => $sessionBranch, 'cashier' => $cashier];
    }

    $latest = tillLatestForCashier($conn, $cashier);
    if ($latest && !tillRowIsOpen($latest)) {
        return ['error' => 'Your till session is no longer open. Please reopen the till.'];
    }
    return ['error' => 'Please open a till session before completing a sale.'];
}
