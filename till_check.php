<?php
/**
 * 2026-10-18 — Till open check (admin only, read-only).
 * Runs each step "Open till" uses for the signed-in user and shows the exact database
 * message where one fails. The test insert runs inside a transaction that is rolled back,
 * so nothing is saved. Safe to delete once the till opens.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/till_session.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/branch_integrity.php';

mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: text/html; charset=utf-8');

if (strtolower(trim((string)($_SESSION['level'] ?? ''))) !== 'admin') {
    http_response_code(403);
    echo 'Admins only.';
    exit;
}

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo 'Database connection failed: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8');
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$rows = [];
$add = function (string $step, bool $ok, string $detail = '') use (&$rows) {
    $rows[] = [$step, $ok, $detail];
};
$q = function (string $sql) use ($conn) {
    $res = mysqli_query($conn, $sql);
    return [$res, $res ? '' : ('#' . mysqli_errno($conn) . ' ' . mysqli_error($conn))];
};

// Server
[$res] = $q('SELECT VERSION() v, @@sql_mode m');
$srv = $res ? mysqli_fetch_assoc($res) : [];
$add('Database server', true, ($srv['v'] ?? '?') . ' — sql_mode: ' . ($srv['m'] ?? '?'));

// Schema steps, one by one, with the real error
[$r, $e] = $q("SHOW TABLES LIKE 'registers'");
$add('registers table present', $r && mysqli_num_rows($r) > 0, $e);
[$r, $e] = $q("SHOW COLUMNS FROM sessions");
$cols = [];
if ($r) { while ($c = mysqli_fetch_assoc($r)) { $cols[$c['Field']] = $c['Type'] . ($c['Null'] === 'NO' ? ' NOT NULL' : '') . ($c['Extra'] ? ' ' . $c['Extra'] : ''); } }
$add('sessions columns', (bool)$r, $e !== '' ? $e : implode('; ', array_map(fn($k, $v) => "$k $v", array_keys($cols), $cols)));
[$r, $e] = $q("SHOW INDEX FROM sessions WHERE Key_name = 'uniq_open_cashier'");
$add('one-open-till guard index', $r && mysqli_num_rows($r) > 0, $e !== '' ? $e : ($r && mysqli_num_rows($r) > 0 ? 'present' : 'missing (not fatal)'));
[$r, $e] = $q("UPDATE sessions SET status = status WHERE 1 = 0");
$add('UPDATE permission on sessions', (bool)$r, $e);
[$r, $e] = $q("UPDATE sessions SET opened_at = STR_TO_DATE(session_start, '%Y-%m-%d %H:%i:%s') WHERE 1 = 0 AND session_start REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'");
$add('Date sync statement', (bool)$r, $e);

$schemaOk = tillEnsureSchema($conn);
$add('Till schema check (tillEnsureSchema)', $schemaOk, $schemaOk ? '' : 'Failed — last database message: #' . mysqli_errno($conn) . ' ' . mysqli_error($conn));

// Who and where
$cashier = tillCurrentCashier($conn);
$add('Signed-in cashier', $cashier['email'] !== '', 'email: ' . $cashier['email'] . ' — user id: ' . $cashier['id']);
$working = psBranchResolveWorking($conn);
$add('Working branch', !empty($working['ok']), json_encode($working));
$branch = '';
if (!empty($working['ok'])) {
    $reg = psBranchRequireRegisteredWrite($conn, $working['branch'] ?? '');
    $add('Branch registered for this company', !empty($reg['ok']), json_encode($reg));
    $branch = (string)($reg['branch'] ?? '');
}

// Existing open sessions for this user (any shape)
$idSql = (int)$cashier['id'];
$emEsc = mysqli_real_escape_string($conn, $cashier['email']);
[$r, $e] = $q("SELECT id, session_name, status, session_end, branch, register_id, opened_at FROM sessions
               WHERE (cashier_id = $idSql OR LOWER(cashier_email) = LOWER('$emEsc')) AND status = 'OPEN' ORDER BY id DESC LIMIT 5");
$open = [];
if ($r) { while ($o = mysqli_fetch_assoc($r)) { $open[] = $o; } }
$add('OPEN sessions for this user', (bool)$r, $e !== '' ? $e : ($open ? json_encode($open) : 'none'));

$active = tillFetchActive($conn, $cashier, false);
$add('Active till lookup', !empty($active['ok']), !empty($active['ok']) ? ($active['row'] ? 'found session #' . $active['row']['id'] : 'no open till') : '#' . mysqli_errno($conn) . ' ' . mysqli_error($conn));

// Register + dry-run insert (rolled back)
if (!empty($active['row'])) {
    $add('Test insert', true, 'skipped — this user already has open till #' . $active['row']['id'] . '; Cash Reconciliation should show it');
} elseif ($branch !== '' && $schemaOk) {
    $bEsc = mysqli_real_escape_string($conn, $branch);
    [$r, $e] = $q("SELECT id, name, status FROM registers WHERE branch = '$bEsc'");
    $regs = [];
    if ($r) { while ($x = mysqli_fetch_assoc($r)) { $regs[] = $x; } }
    $add('Registers for ' . $branch, (bool)$r, $e !== '' ? $e : ($regs ? json_encode($regs) : 'none yet (one is created on first open)'));

    mysqli_begin_transaction($conn);
    $regId = $regs ? (int)$regs[0]['id'] : 0;
    if ($regId === 0) {
        [$r, $e] = $q("INSERT INTO registers (branch, name, status) VALUES ('$bEsc', 'Till 01', 'ACTIVE')");
        $add('Test: create register', (bool)$r, $e !== '' ? $e : 'ok (rolled back)');
        $regId = $r ? (int)mysqli_insert_id($conn) : 0;
    }
    $now = date('Y-m-d H:i:s');
    $cid = $idSql > 0 ? (string)$idSql : 'NULL';
    [$r, $e] = $q("INSERT INTO sessions
        (session_name, session_start, session_end, cashier_id, cashier_email, branch, register_id, status, opening_float, opened_at, closed_at, created_at, updated_at)
        VALUES ('$emEsc', '$now', '', $cid, '$emEsc', '$bEsc', " . ($regId ?: 'NULL') . ", 'OPEN', 0.00, '$now', NULL, '$now', '$now')");
    $add('Test: insert open till', (bool)$r, $e !== '' ? $e : 'ok (rolled back)');
    mysqli_rollback($conn);
}

$fails = array_filter($rows, fn($x) => !$x[1]);
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Till check</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:16px;color:#1f2937;background:#fff}
table{border-collapse:collapse;width:100%;font-size:14px}td,th{border:1px solid #d1d5db;padding:6px 8px;text-align:left;vertical-align:top}
td.d{font-family:Consolas,monospace;font-size:12px;word-break:break-word}.ok{color:#047857;font-weight:600}.bad{color:#b91c1c;font-weight:700}
</style></head><body>
<h2>Till open check</h2>
<p><?php echo $fails ? 'Failed steps are marked <span class="bad">FAIL</span>. Send a screenshot of this page.' : 'Every step passed.'; ?> Nothing is saved by this page.</p>
<table><tr><th>Step</th><th>Result</th><th>Detail</th></tr>
<?php foreach ($rows as [$s, $ok, $d]): ?>
<tr><td><?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></td><td class="<?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo $ok ? 'OK' : 'FAIL'; ?></td><td class="d"><?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></td></tr>
<?php endforeach; ?>
</table></body></html>
