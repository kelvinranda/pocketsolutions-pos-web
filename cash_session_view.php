<?php
/**
 * Phase 7.5 — Read-only till session drill-down (movements / recon / closure / audit).
 * OPEN → live ledger expected; CLOSED → closure snapshot for closure figures.
 * Never mutates financial data.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/cash_reporting.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCashReportingModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$detail = null;
$id = (int)($_GET['id'] ?? 0);

if (!$signedIn) {
    $error = 'You must be signed in.';
} elseif ($id < 1) {
    $error = 'Session was not found.';
} else {
    $detail = psCashReportingSessionDetail($conn, $id);
    if (empty($detail['ok'])) {
        $error = (string)($detail['error'] ?? 'Session could not be loaded.');
        $detail = null;
    }
}

// 2026-10-21: every amount in its drawer's own currency (the session detail is a transaction record).
require_once __DIR__ . '/includes/fx_report.php';
$fxBase = psFxBase($conn, psFxSessionCompany());
$C = static fn($v, $cur = null): string => htmlspecialchars(psFxFormat($conn, $v ?? 0, psFxNormalizeCode($cur ?? '') ?: $fxBase), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Till Session <?php echo $id > 0 ? '#' . $id : ''; ?></title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Till Session'); ?>
    <h1>Till session details</h1>
    <p class="hint"><a href="cash_management.php">← Cash Management</a></p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($detail): ?>
        <?php
        $s = $detail['session'];
        $cls = $detail['classification'] ?? [];
        $live = $detail['live_ledger'] ?? null;
        $status = strtoupper(trim((string)($s['status'] ?? '')));
        ?>
        <div class="meta">
            <span><strong>Session</strong> #<?php echo (int)$s['id']; ?></span>
            <span><strong>Branch</strong> <?php echo htmlspecialchars((string)$s['branch'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span><strong>Till</strong> <?php echo htmlspecialchars(tillRegisterName($s), ENT_QUOTES, 'UTF-8'); ?></span>
            <span><strong>Cashier</strong> <?php echo htmlspecialchars((string)($s['cashier_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            <span><strong>Status</strong> <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
            <span><strong>Opened</strong> <?php echo htmlspecialchars(tillOpenedAt($s), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($status === 'CLOSED'): ?>
                <span><strong>Closed</strong> <?php echo htmlspecialchars((string)($s['closed_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                <span><strong>Closed by</strong> <?php echo htmlspecialchars((string)($s['closed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>

        <h2>Expected cash</h2>
        <p class="hint">
            Source for operational figures:
            <strong><?php echo htmlspecialchars((string)$detail['expected_source'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <?php if ($status === 'OPEN'): ?>
                — live ledger via <code>psCashMovementBalance</code>.
            <?php else: ?>
                — closure snapshot on session; ledger remains available below for audit.
            <?php endif; ?>
        </p>
        <?php if ($status === 'CLOSED'): ?>
            <table>
                <thead><tr><th>Float</th><th>Expected (snapshot, <?php echo htmlspecialchars($fxBase); ?> drawer)</th><th>Actual</th><th>Variance</th><th>Status</th><th>Final recon</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr>
                        <td><?php echo $C($s['opening_float'] ?? 0); ?></td>
                        <td><?php echo $C($s['expected_cash'] ?? 0); ?></td>
                        <td><?php echo $C($s['actual_cash'] ?? 0); ?></td>
                        <td><?php echo $C($s['variance'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars(psCashReconciliationStatusLabel((string)($s['reconciliation_status'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)($s['final_reconciliation_id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string)($s['closure_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($live && !empty($live['ok'])): ?>
            <table>
                <thead><tr><th>Drawer</th><th>Opening</th><th>Sales</th><th>In</th><th>Refunds</th><th>Out</th><th>Ledger expected</th><th>Count up to date?</th></tr></thead>
                <tbody>
                <?php foreach (($detail['drawers'] ?? [$fxBase => $live]) as $dCode => $dBal): $d = $dBal['detail'] ?? []; ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$dCode, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td><?php echo $C($d['opening_float'] ?? 0, $dCode); ?></td>
                        <td><?php echo $C($d['cash_sales'] ?? 0, $dCode); ?></td>
                        <td><?php echo $C($d['cash_in'] ?? 0, $dCode); ?></td>
                        <td><?php echo $C($d['cash_refunds'] ?? 0, $dCode); ?></td>
                        <td><?php echo $C($d['cash_out'] ?? 0, $dCode); ?></td>
                        <td><?php echo $C($dBal['balance'] ?? 0, $dCode); ?></td>
                        <td class="<?php echo $dCode === $fxBase && !empty($cls['stale']) ? 'stale' : ''; ?>">
                            <?php if ($dCode === $fxBase): ?>
                            <?php echo htmlspecialchars(psCashReportingStatusLabel((string)($cls['currency'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($cls['variance_status'])): ?>
                                / <?php echo htmlspecialchars(psCashReconciliationStatusLabel((string)$cls['variance_status']), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                            <?php else: ?>see Reconciliations<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="fxr-label">One drawer per currency. Drawers are never added together: each is counted on its own.</p>
            <?php if (!empty($cls['stale'])): ?>
                <p class="stale">
                    Stale: last recon expected <?php echo $C($cls['recon_expected']); ?>
                    vs live <?php echo $C($cls['ledger_expected']); ?>.
                    <a href="cash_reconciliation.php">Reconcile</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Cash movements</h2>
        <table>
            <thead>
                <tr><th>Datetime</th><th>Type</th><th>Dir</th><th>Amount</th><th>Ref</th><th>Reason</th><th>Description</th><th>User</th></tr>
            </thead>
            <tbody>
            <?php if (empty($detail['movements'])): ?>
                <tr><td colspan="8">No movements.</td></tr>
            <?php else: foreach ($detail['movements'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['movement_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['type_label'] ?? $row['movement_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['direction'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $C($row['amount'], $row['currency'] ?? null); ?><?php if (!array_key_exists('currency', $row) || $row['currency'] === null): ?> <span class="fxa-tag" title="Recorded before drawer currencies">legacy</span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars((string)($row['reference_display'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['reason_label'] ?? $row['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['created_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Reconciliations</h2>
        <table>
            <thead>
                <tr><th>Date</th><th>Drawer</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Status</th><th>Notes</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php if (empty($detail['reconciliations'])): ?>
                <tr><td colspan="8">No reconciliations.</td></tr>
            <?php else: foreach ($detail['reconciliations'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['reconciled_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(psFxNormalizeCode($row['currency'] ?? '') ?: $fxBase, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $C($row['expected_cash'], $row['currency'] ?? null); ?></td>
                    <td><?php echo $C($row['counted_cash'], $row['currency'] ?? null); ?></td>
                    <td><?php echo $C($row['variance'], $row['currency'] ?? null); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['status_label'] ?? $row['status']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['reconciled_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Audit (read-only)</h2>
        <?php $aud = $detail['audits'] ?? []; ?>
        <h3>Movement audit</h3>
        <table>
            <thead><tr><th>When</th><th>Action</th><th>Field</th><th>Old</th><th>New</th><th>By</th></tr></thead>
            <tbody>
            <?php if (empty($aud['movements'])): ?>
                <tr><td colspan="6">No movement audit rows.</td></tr>
            <?php else: foreach ($aud['movements'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['field_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['changed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <h3>Reconciliation audit</h3>
        <table>
            <thead><tr><th>When</th><th>Action</th><th>Field</th><th>Old</th><th>New</th><th>By</th></tr></thead>
            <tbody>
            <?php if (empty($aud['reconciliations'])): ?>
                <tr><td colspan="6">No reconciliation audit rows.</td></tr>
            <?php else: foreach ($aud['reconciliations'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['field_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['changed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <h3>Closure audit</h3>
        <table>
            <thead><tr><th>When</th><th>Action</th><th>Field</th><th>Old</th><th>New</th><th>By</th></tr></thead>
            <tbody>
            <?php if (empty($aud['closures'])): ?>
                <tr><td colspan="6">No closure audit rows.</td></tr>
            <?php else: foreach ($aud['closures'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['field_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['changed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
