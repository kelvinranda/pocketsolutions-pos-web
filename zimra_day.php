<?php
/**
 * Phase 17 — ZIMRA fiscal day (Supervisor, Management, admin).
 * The day opens automatically on the first sale; this page closes it at end of day (within the
 * ZIMRA hour limit), opens it manually if wanted, and resends receipts that were saved offline.
 * Non-admins see their working branch's device only.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/zimra.php';
require_once __DIR__ . '/includes/till_session.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

if (!psZimraMayManageDay()) {
    http_response_code(403);
    die('The ZIMRA fiscal day is managed by a Supervisor or Management.');
}
psEnsureZimraModule($conn);
$actor = psZimraSessionUser();
$isAdmin = psZimraMaySetup();

$devices = array_values(array_filter(psZimraDeviceList($conn), static fn ($d) => $d['status'] === 'REGISTERED'));
if (!$isAdmin) {
    $branch = tillSelectedBranch();
    $devices = array_values(array_filter($devices, static fn ($d) => $d['branch'] === $branch));
}
$selectedId = (int)($_GET['id'] ?? $_POST['id'] ?? ($devices[0]['id'] ?? 0));
$device = null;
foreach ($devices as $d) {
    if ((int)$d['id'] === $selectedId) {
        $device = $d;
    }
}

$message = '';
$error = '';
if ($device && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'open') {
            $r = psZimraDayOpenManual($conn, (int)$device['id'], $actor);
            $r['ok'] ? $message = 'Fiscal day ' . $r['fiscal_day_no'] . ' is open.' : $error = $r['error'];
        } elseif ($action === 'close') {
            $r = psZimraDayClose($conn, (int)$device['id'], $actor, 12);
            if ($r['ok'] && $r['status'] === 'CLOSED') {
                $message = 'Fiscal day ' . $r['fiscal_day_no'] . ' is closed and accepted by ZIMRA.';
            } elseif ($r['ok']) {
                $message = 'Close sent. ZIMRA is still processing fiscal day ' . $r['fiscal_day_no'] . '; refresh in a minute.';
            } else {
                $error = $r['error'] !== '' ? $r['error'] : 'ZIMRA did not accept the close.';
            }
        } elseif ($action === 'send') {
            $r = psZimraSubmitPending($conn, (int)$device['id'], $actor);
            $r['ok'] ? $message = $r['sent'] . ' receipt(s) sent to ZIMRA.' : $error = $r['error'];
        } elseif ($action === 'poll') {
            $r = psZimraDayPollClose($conn, (int)$device['id'], $actor);
            $message = 'Fiscal day status: ' . ($r['status'] ?? 'unknown') . '.';
        }
        psSecurityAudit($conn, 'ZIMRA_DAY_' . strtoupper($action), ['summary' => 'ZIMRA fiscal day ' . $action, 'target_type' => 'zimra_device', 'target_id' => (string)$device['id']]);
        $device = psZimraDeviceGet($conn, (int)$device['id']);
    }
}

$day = $device ? psZimraDayLatest($conn, (int)$device['id']) : null;
$active = $device ? psZimraDayActive($conn, (int)$device['id']) : null;
$hoursLeft = ($device && $active) ? psZimraDayHoursLeft($device, $active) : null;
$summary = [];
$problems = [];
if ($day) {
    $summary = psZimraQuery($conn,
        'SELECT receipt_type, status, COUNT(*) n, SUM(receipt_total) total FROM zimra_receipts WHERE fiscal_day_row_id = ? GROUP BY receipt_type, status ORDER BY receipt_type, status',
        'i', [(int)$day['id']]) ?? [];
    $problems = psZimraQuery($conn,
        "SELECT * FROM zimra_receipts WHERE device_row_id = ? AND status IN ('PENDING','REJECTED','SUBMITTED_WITH_ERRORS') ORDER BY receipt_global_no DESC LIMIT 50",
        'i', [(int)$device['id']]) ?? [];
}
mysqli_close($conn);

$h = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$statusBadge = static function (?array $day): string {
    if (!$day) {
        return '<span class="ps-badge ps-badge-secondary">No fiscal day yet</span>';
    }
    $map = ['OPEN' => 'success', 'CLOSE_PENDING' => 'info', 'CLOSED' => 'secondary', 'CLOSE_FAILED' => 'destructive'];
    $labels = ['OPEN' => 'Open', 'CLOSE_PENDING' => 'Closing', 'CLOSED' => 'Closed', 'CLOSE_FAILED' => 'Close failed'];
    return '<span class="ps-badge ps-badge-' . ($map[$day['status']] ?? 'secondary') . '">' . ($labels[$day['status']] ?? $day['status']) . '</span>';
};
$form = static function (int $id, string $action, string $label, string $class = 'ps-btn-outline', string $confirm = '') use ($h): string {
    return '<form method="post" style="display:inline">' . psCsrfField()
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $h($action) . '">'
        . '<button class="ps-btn ' . $class . '" type="submit"' . ($confirm !== '' ? ' onclick="return confirm(\'' . $h($confirm) . '\')"' : '') . '>' . $h($label) . '</button></form> ';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZIMRA Fiscal Day</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('ZIMRA Fiscal Day'); ?>
<h1>ZIMRA Fiscal Day</h1>
<p class="ps-page-lead">The fiscal day opens with the first sale. Close it at the end of trading, and always within the ZIMRA hour limit, or sales stop.</p>

<?php if ($message !== ''): ?><div class="ps-alert ps-alert-success" role="status"><?php echo $h($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div><?php endif; ?>

<?php if (!$devices): ?>
    <div class="ps-alert ps-alert-info">No ZIMRA device is set up for <?php echo $isAdmin ? 'any branch' : 'your branch'; ?>. <?php echo $isAdmin ? '<a href="zimra_setup.php">Set one up</a>.' : 'Ask an administrator.'; ?></div>
<?php else: ?>
    <?php if (count($devices) > 1): ?>
        <form method="get" class="ps-form-actions">
            <select class="ps-input" name="id" onchange="this.form.submit()">
                <?php foreach ($devices as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>" <?php echo (int)$d['id'] === $selectedId ? 'selected' : ''; ?>><?php echo $h($d['branch'] . ' — ' . $d['environment'] . ' device ' . $d['device_id']); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
    <?php if ($device): ?>
    <?php if ((int)$device['enabled'] !== 1): ?>
        <div class="ps-alert ps-alert-warn">Fiscalisation is switched off for this branch, so sales are not being fiscalised.</div>
    <?php endif; ?>
    <?php if ($hoursLeft !== null && $hoursLeft <= (float)($device['day_end_notification_hrs'] ?: 2)): ?>
        <div class="ps-alert <?php echo $hoursLeft <= 0 ? 'ps-alert-destructive' : 'ps-alert-warn'; ?>">
            <?php echo $hoursLeft <= 0 ? 'The fiscal day is over the ZIMRA limit. Sales are blocked until it is closed.' : 'Close the fiscal day within ' . $h(round($hoursLeft, 1)) . ' hours.'; ?>
        </div>
    <?php endif; ?>

    <div class="ps-kpi-grid">
        <div class="ps-kpi"><div class="ps-kpi-label">Branch</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo $h($device['branch']); ?></div><div class="ps-kpi-sub"><?php echo $h(ucfirst($device['environment']) . ' device ' . $device['device_id']); ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Fiscal day</div><div class="ps-kpi-value"><?php echo $day ? (int)$day['fiscal_day_no'] : '—'; ?></div><div class="ps-kpi-sub"><?php echo $statusBadge($day); ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Opened</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo $day ? $h(date('d M H:i', strtotime($day['opened_at']))) : '—'; ?></div>
            <div class="ps-kpi-sub"><?php echo $day ? ($day['open_state'] === 'CONFIRMED' ? 'Confirmed by ZIMRA' : 'Waiting for ZIMRA confirmation') : ''; ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Hours left</div><div class="ps-kpi-value"><?php echo $hoursLeft === null ? '—' : $h(max(0, round($hoursLeft, 1))); ?></div><div class="ps-kpi-sub">Limit <?php echo $h($device['day_max_hrs'] ?? '?'); ?> h</div></div>
    </div>

    <div class="ps-form-actions">
        <?php if (!$active): echo $form((int)$device['id'], 'open', 'Open fiscal day', 'ps-btn-outline'); endif; ?>
        <?php if ($active && in_array($active['status'], ['OPEN', 'CLOSE_FAILED'], true)): echo $form((int)$device['id'], 'close', 'Close fiscal day', 'ps-btn-default', 'Close the fiscal day now? Sales after this start a new fiscal day.'); endif; ?>
        <?php if ($active && $active['status'] === 'CLOSE_PENDING'): echo $form((int)$device['id'], 'poll', 'Check close status'); endif; ?>
        <?php echo $form((int)$device['id'], 'send', 'Send waiting receipts now'); ?>
    </div>
    <?php if ($day && $day['status'] === 'CLOSE_FAILED'): ?>
        <div class="ps-alert ps-alert-destructive">ZIMRA rejected the close (<?php echo $h($day['close_error'] ?? ''); ?>). Try closing again; if it fails again, contact support with this fiscal day number.</div>
    <?php endif; ?>
    <?php if ($day && !empty($day['open_error']) && $day['open_state'] !== 'CONFIRMED'): ?>
        <div class="ps-alert ps-alert-warn">ZIMRA has not confirmed this fiscal day yet: <?php echo $h($day['open_error']); ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">This fiscal day</h2></div>
        <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
            <thead><tr><th>Type</th><th>Status</th><th class="num">Receipts</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php if (!$summary): ?><tr><td colspan="4" class="ps-muted">No fiscal receipts yet.</td></tr><?php endif; ?>
            <?php foreach ($summary as $s): ?>
                <tr><td><?php echo $h($s['receipt_type'] === 'CreditNote' ? 'Credit notes' : 'Invoices'); ?></td><td><?php echo $h($s['status']); ?></td>
                    <td class="num"><?php echo (int)$s['n']; ?></td><td class="num"><?php echo $h(ps_money($s['total'])); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>

    <?php if ($problems): ?>
    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Receipts needing attention</h2>
            <p class="ps-card-desc">Waiting = not sent yet (sent automatically when online). Rejected or with errors = ZIMRA did not accept them cleanly; send the details to support.</p></div>
        <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
            <thead><tr><th>Receipt</th><th>Invoice / refund</th><th>Status</th><th>Detail</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($problems as $p): ?>
                <tr><td><?php echo $h($p['receipt_counter'] . '/' . $p['receipt_global_no']); ?></td><td><?php echo $h($p['invoice_no']); ?></td>
                    <td><?php echo $h($p['status'] === 'PENDING' ? 'Waiting to send' : $p['status']); ?></td>
                    <td><?php echo $h($p['last_error'] ?: $p['validation_errors_json']); ?></td>
                    <td><a class="ps-btn ps-btn-outline ps-btn-sm" href="zimra_receipt_print.php?id=<?php echo (int)$p['id']; ?>">View</a></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
