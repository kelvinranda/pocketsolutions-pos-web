<?php
/**
 * Phase 7.4 — Till Closure UI.
 * Requires a current FINAL cash reconciliation (expected == live ledger).
 * Does not auto-create recon. Does not alter cash_movements.
 * Expected / variance are server-calculated (not user-typed).
 * Explicit confirmation + submit-token + server revalidation.
 */
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/till_closure.php';
require_once __DIR__ . '/includes/ps_layout.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/cash_controls.php';

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

psEnsureTillClosureModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$isAdmin = psTillClosureIsAdmin();
$error = '';
$success = '';
$notes = trim((string)($_POST['notes'] ?? ''));
$confirmClose = '';

$sessionRow = null;
$branch = '';
$balance = null;
$assess = null;
$branchSyncedNote = '';
$historyBranchFilter = trim((string)($_GET['history_branch'] ?? 'all'));
$history = [];

if ($signedIn) {
    $sessionRow = getActiveTillSession($conn);
    if ($sessionRow && tillRowIsOpen($sessionRow)) {
        // Blank bind + sync drifted working branch to this open till (never rewrite till).
        $aligned = tillAlignWorkingBranchToOpenSession($conn, $sessionRow, tillCurrentCashier($conn));
        if (!empty($aligned['ok'])) {
            $sessionRow = $aligned['session'];
            $branch = (string)$aligned['branch'];
            if (!empty($aligned['synced'])) {
                $branchSyncedNote = 'Working branch was set to this till\'s branch (' . $branch . ') so close can use the matching reconciliation.';
            } elseif (!empty($aligned['bound'])) {
                $branchSyncedNote = 'This till had no branch; it was assigned to ' . $branch . '.';
            }
        } else {
            $branch = trim((string)($sessionRow['branch'] ?? ''));
            if ($error === '' && !empty($aligned['error'])) {
                $error = (string)$aligned['error'];
            }
        }
        $bal = psCashMovementBalance($conn, (int)$sessionRow['id']);
        if (!empty($bal['ok'])) {
            $balance = $bal;
        }
        $assess = psTillClosureAssessFinalReconCurrency($conn, (int)$sessionRow['id']);
    }
    $histFilters = ['limit' => 50, 'offset' => 0];
    if ($isAdmin) {
        $histFilters['branch'] = $historyBranchFilter;
    }
    $hist = psTillClosureHistory($conn, $histFilters);
    if (!empty($hist['ok'])) {
        $history = $hist['rows'] ?? [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['till_close_submit'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to close the till.';
    } elseif (!psTillClosureConsumeSubmitToken($_POST['till_close_submit_token'] ?? '')) {
        $error = 'This form was already submitted or the session expired. Please try again.';
    } else {
        $result = psTillClosureClose($conn, [
            'confirm_close' => $_POST['confirm_close'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            // Tamper probes — ignored / rejected by helper:
            'till_session_id' => $_POST['till_session_id'] ?? '',
            'branch' => $_POST['branch'] ?? '',
            'expected_cash' => $_POST['expected_cash'] ?? '',
            'counted_cash' => $_POST['counted_cash'] ?? '',
            'variance' => $_POST['variance'] ?? '',
            'status' => $_POST['status'] ?? '',
            'cashier' => $_POST['cashier'] ?? '',
            'closed_by' => $_POST['closed_by'] ?? '',
            'final_reconciliation_id' => $_POST['final_reconciliation_id'] ?? '',
            'posted_till_session_id' => $_POST['till_session_id'] ?? '',
            'posted_branch' => $_POST['branch'] ?? '',
        ]);
        if (!empty($result['ok'])) {
            header(
                'Location: cash_till_close.php?ok=1&sid=' . rawurlencode((string)($result['till_session_id'] ?? ''))
                . '&var=' . rawurlencode((string)($result['variance'] ?? ''))
                . '&st=' . rawurlencode((string)($result['reconciliation_status'] ?? ''))
            );
            exit;
        }
        $error = (string)($result['error'] ?? 'The till could not be closed.');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $confirmClose = trim((string)($_POST['confirm_close'] ?? ''));
        $sessionRow = getActiveTillSession($conn);
        $balance = null;
        $assess = null;
        if ($sessionRow && tillRowIsOpen($sessionRow)) {
            $aligned = tillAlignWorkingBranchToOpenSession($conn, $sessionRow, tillCurrentCashier($conn));
            if (!empty($aligned['ok'])) {
                $sessionRow = $aligned['session'];
                $branch = (string)$aligned['branch'];
            } else {
                $branch = trim((string)($sessionRow['branch'] ?? ''));
            }
            $bal = psCashMovementBalance($conn, (int)$sessionRow['id']);
            if (!empty($bal['ok'])) {
                $balance = $bal;
            }
            $assess = psTillClosureAssessFinalReconCurrency($conn, (int)$sessionRow['id']);
        }
        $histFilters = ['limit' => 50, 'offset' => 0];
        if ($isAdmin) {
            $histFilters['branch'] = $historyBranchFilter;
        }
        $hist = psTillClosureHistory($conn, $histFilters);
        if (!empty($hist['ok'])) {
            $history = $hist['rows'] ?? [];
        }
    }

    }
}

if (isset($_GET['ok']) && (string)$_GET['ok'] === '1') {
    $st = strtoupper(trim((string)($_GET['st'] ?? '')));
    $var = trim((string)($_GET['var'] ?? ''));
    $sid = trim((string)($_GET['sid'] ?? ''));
    $label = $st !== '' ? psCashReconciliationStatusLabel($st) : 'Closed';
    $success = 'Till session closed'
        . ($sid !== '' ? ' (#' . $sid . ')' : '')
        . ' — ' . $label
        . ($var !== '' ? '; variance ' . $var : '')
        . '. Closed sessions cannot be reopened.';
    // Honest status: a different OPEN till may still exist (e.g. reopened elsewhere).
    if ($signedIn) {
        $stillOpen = getActiveTillSession($conn);
        if ($stillOpen && tillRowIsOpen($stillOpen)) {
            $stillBranch = trim((string)($stillOpen['branch'] ?? ''));
            $success .= ' Note: you still have an OPEN till'
                . ($stillBranch !== '' ? ' on ' . $stillBranch : '')
                . ' (#' . (int)$stillOpen['id'] . '). Close that session before switching branches to sell elsewhere.';
            $sessionRow = $stillOpen;
            $branch = $stillBranch;
            $tillOpen = true;
        } else {
            $success .= ' Open a new till on your working branch before the next sale.';
            $sessionRow = null;
            $tillOpen = false;
        }
    }
}

$submitToken = $signedIn ? psTillClosureIssueSubmitToken() : '';
$tillOpen = $sessionRow && tillRowIsOpen($sessionRow);
$cashierEmail = '';
if ($sessionRow) {
    $cashierEmail = trim((string)($sessionRow['cashier_email'] ?? ''));
    if ($cashierEmail === '') {
        $cashierEmail = trim((string)($sessionRow['session_name'] ?? ''));
    }
}
$detail = $balance['detail'] ?? [
    'opening_float' => '0.00',
    'cash_sales' => '0.00',
    'cash_in' => '0.00',
    'cash_refunds' => '0.00',
    'cash_out' => '0.00',
];
$expectedDisplay = $balance ? (string)$balance['balance'] : '0.00';
// 2026-10-17 blind count: Till users see the totals only once a current final count exists.
$blind = $tillOpen && $sessionRow && !psCashControlsShowExpected($conn, (int)$sessionRow['id']);

$canClose = false;
$blockReason = '';
$recon = null;
$countedDisplay = '';
$varianceDisplay = '';
$reconStatus = '';
if ($tillOpen) {
    if (empty($assess['ok'])) {
        $blockReason = (string)($assess['error'] ?? 'Reconciliation could not be assessed.');
    } elseif (!empty($assess['missing'])) {
        $blockReason = 'A final cash reconciliation is required before closing. Open Cash Reconciliation, enter counted cash, tap Finalise reconciliation, then return here to close.';
    } elseif (!empty($assess['stale'])) {
        $blockReason = 'The latest reconciliation is out of date (cash activity after the count). Finalise a fresh cash reconciliation, then return here to close.';
        $recon = $assess['recon'] ?? null;
    } elseif (!empty($assess['current']) && !empty($assess['recon'])) {
        $canClose = true;
        $recon = $assess['recon'];
        $countedDisplay = number_format((float)($recon['counted_cash'] ?? 0), 2, '.', '');
        $varianceDisplay = number_format((float)($recon['variance'] ?? 0), 2, '.', '');
        $reconStatus = strtoupper(trim((string)($recon['status'] ?? '')));
    } else {
        $blockReason = 'A current final cash reconciliation is required before closing.';
    }
}

// Multi-currency (2026-10-20): other drawer currencies need their own current count. The figures above are the base drawer.
$tillBase = '';
$otherCurrencies = [];
if ($tillOpen) {
    $tillBase = psFxTillBase($conn, (int)$sessionRow['id']);
    foreach (psTillClosureAssessAllCurrencies($conn, (int)$sessionRow['id']) as $code => $a) {
        if ($code === $tillBase) {
            continue;
        }
        $otherCurrencies[$code] = $a;
        if ($canClose && empty($a['current'])) {
            $canClose = false;
            $blockReason = 'Count the ' . $code . ' cash too: every currency in the drawer needs a current final reconciliation before the till can close.';
            $assess['missing'] = true;
        }
    }
}

$branches = $isAdmin ? psAuthorisedProductBranches($conn) : [];

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Close Till</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash Till Close'); ?>

<h1>Close Till</h1>
<p class="ps-page-lead">Close the current open till only when a <strong>current</strong> final cash reconciliation matches the live ledger expected cash. Closing does not change the cash ledger and cannot be undone. Cashier handover mid-session is not available — close, then open a new session for the next cashier.</p>

<div class="ps-link-row">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_reconciliation.php">Cash reconciliation</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_movement.php">Cash in / out</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="ajax_pos.php">Web POS</a>
</div>

<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo ps_h($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status"><?php echo ps_h($success); ?></div>
<?php endif; ?>
<?php if ($branchSyncedNote !== '' && $error === ''): ?>
    <div class="ps-alert ps-alert-info" role="status"><?php echo ps_h($branchSyncedNote); ?></div>
<?php endif; ?>

<?php if (!$signedIn): ?>
    <div class="ps-empty">Please sign in to close the till.</div>
<?php elseif (!$tillOpen): ?>
    <div class="ps-empty">
        No open till session. Open a till on the Web POS, reconcile cash, then return here to close.
        <span class="ps-link-row" style="margin-top:12px;display:flex">
            <a class="ps-btn ps-btn-outline ps-btn-sm" href="ajax_pos.php">Open Web POS</a>
            <a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_reconciliation.php">Cash reconciliation</a>
        </span>
    </div>
    <?php if ($history): ?>
        <section class="ps-section">
            <h2>Closed till history</h2>
            <?php if ($isAdmin): ?>
                <form method="get" class="filter-form">
                    <label for="history_branch">Branch</label>
                    <select id="history_branch" name="history_branch" onchange="this.form.submit()">
                        <option value="all"<?php echo $historyBranchFilter === 'all' || $historyBranchFilter === '' ? ' selected' : ''; ?>>All branches</option>
                        <?php foreach ($branches as $bn): ?>
                            <?php $bn = is_array($bn) ? (string)($bn['branch_name'] ?? $bn['name'] ?? '') : (string)$bn; ?>
                            <?php if ($bn === '') { continue; } ?>
                            <option value="<?php echo ps_h($bn); ?>"<?php echo $historyBranchFilter === $bn ? ' selected' : ''; ?>><?php echo ps_h($bn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <div class="ps-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Closed</th>
                            <th>Session</th>
                            <th>Branch</th>
                            <th>Expected</th>
                            <th>Counted</th>
                            <th>Variance</th>
                            <th>Status</th>
                            <th>By</th>
                            <th>Recon #</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <?php $st = (string)($row['reconciliation_status'] ?? ''); ?>
                            <tr>
                                <td><?php echo ps_h((string)($row['closed_at'] ?? '')); ?></td>
                                <td>#<?php echo (int)$row['id']; ?></td>
                                <td><?php echo ps_h((string)$row['branch']); ?></td>
                                <td><?php echo ps_h(number_format((float)($row['expected_cash'] ?? 0), 2, '.', '')); ?></td>
                                <td><?php echo ps_h(number_format((float)($row['actual_cash'] ?? 0), 2, '.', '')); ?></td>
                                <td><?php echo ps_h(number_format((float)($row['variance'] ?? 0), 2, '.', '')); ?></td>
                                <td><?php if ($st !== ''): ?><span class="ps-badge ps-badge-<?php echo ps_h($st); ?>"><?php echo ps_h(psCashReconciliationStatusLabel($st)); ?></span><?php endif; ?></td>
                                <td><?php echo ps_h((string)($row['closed_by'] ?? '')); ?></td>
                                <td><?php echo (int)($row['final_reconciliation_id'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php else: ?>
    <div class="ps-split">
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Close till</h2>
                <p class="ps-card-desc">Requires a current final reconciliation. Closing cannot be undone.</p>
            </div>
            <div class="ps-card-content">
            <div class="ps-meta">
                Branch: <?php echo ps_h($branch); ?><br>
                Till: <?php echo ps_h(tillRegisterName($sessionRow)); ?><br>
                Session #<?php echo (int)$sessionRow['id']; ?><br>
                Cashier: <?php echo ps_h($cashierEmail); ?><br>
                Opened: <?php echo ps_h(tillOpenedAt($sessionRow)); ?>
            </div>
<?php if ($blind): ?>
            <div class="ps-alert ps-alert-info">Count the drawer on Cash Reconciliation first. The totals are shown here once your count is saved.</div>
            <?php else: ?>
                        <div class="ps-kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));">
                <div class="ps-kpi"><div class="ps-kpi-label">Opening</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['opening_float']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Sales</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_sales']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Refunds</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_refunds']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Cash in</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_in']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Cash out</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_out']); ?></div></div>
            </div>
            <div class="ps-balance">Expected <?php echo ps_h($tillBase); ?> cash (ledger): <?php echo ps_h($expectedDisplay); ?></div>
            <?php endif; ?>

            <?php if ($recon): ?>
                <div class="ps-alert ps-alert-info">
                    Latest recon #<?php echo (int)$recon['id']; ?>
                    at <?php echo ps_h((string)($recon['reconciled_at'] ?? '')); ?><br>
                    Counted: <?php echo ps_h(number_format((float)($recon['counted_cash'] ?? 0), 2, '.', '')); ?><br>
                    Recon expected: <?php echo ps_h(number_format((float)($recon['expected_cash'] ?? 0), 2, '.', '')); ?><br>
                    Variance:
                    <span class="ps-status-<?php echo ps_h((string)($recon['status'] ?? '')); ?>">
                        <?php echo ps_h(number_format((float)($recon['variance'] ?? 0), 2, '.', '')); ?>
                        (<?php echo ps_h(psCashReconciliationStatusLabel((string)($recon['status'] ?? ''))); ?>)
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($otherCurrencies): ?>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead><tr><th>Drawer</th><th>Counted</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <tr><td><?php echo ps_h($tillBase); ?> (base)</td><td><?php echo $recon ? ps_h(number_format((float)$recon['counted_cash'], 2, '.', '')) : '—'; ?></td>
                            <td><?php echo !empty($assess['current']) ? 'Current' : (!empty($assess['stale']) ? 'Out of date' : 'Not counted'); ?></td>
                            <td><a href="cash_reconciliation.php?cur=<?php echo rawurlencode($tillBase); ?>">Count</a></td></tr>
                        <?php foreach ($otherCurrencies as $code => $a): ?>
                            <tr><td><?php echo ps_h($code); ?></td>
                                <td><?php echo !empty($a['recon']) ? ps_h(number_format((float)$a['recon']['counted_cash'], 2, '.', '')) : '—'; ?></td>
                                <td><?php echo !empty($a['current']) ? 'Current' : (!empty($a['stale']) ? 'Out of date' : 'Not counted'); ?>
                                    <?php if (!empty($a['current']) && !$blind): ?> · variance <?php echo ps_h(number_format((float)$a['recon']['variance'], 2, '.', '')); ?><?php endif; ?></td>
                                <td><a href="cash_reconciliation.php?cur=<?php echo rawurlencode($code); ?>">Count</a></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($blockReason !== ''): ?>
                <div class="ps-alert ps-alert-destructive" role="alert">
                    <span class="ps-alert-title">Cannot close yet</span>
                    <?php echo ps_h($blockReason); ?>
                    <?php if (!empty($assess['stale']) || !empty($assess['missing'])): ?>
                        <p class="ps-link-row"><a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_reconciliation.php">Go to Cash Reconciliation</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($canClose): ?>
                <form method="post" class="ps-form-stack" autocomplete="off">
                    <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                    <input type="hidden" name="till_close_submit_token" value="<?php echo ps_h($submitToken); ?>">
                    <label class="ps-label">Counted cash (from reconciliation)</label>
                    <input type="text" class="ps-input ps-ro" readonly value="<?php echo ps_h($countedDisplay); ?>">
                    <label class="ps-label">Variance (server)</label>
                    <input type="text" class="ps-input ps-ro" readonly value="<?php echo ps_h($varianceDisplay); ?> (<?php echo ps_h(psCashReconciliationStatusLabel($reconStatus)); ?>)">
                    <label class="ps-label" for="notes">Closure notes<?php echo ($reconStatus === 'SHORTAGE' || $reconStatus === 'SURPLUS') ? ' (required if not already on recon)' : ' (optional)'; ?></label>
                    <textarea class="ps-input" id="notes" name="notes" maxlength="2000"
                              placeholder="Explain shortage/surplus if needed"><?php echo ps_h($notes); ?></textarea>
                    <label class="ps-confirm-row">
                        <input type="checkbox" name="confirm_close" value="YES"<?php echo strtoupper($confirmClose) === 'YES' ? ' checked' : ''; ?> required>
                        <span>I confirm this till should be closed permanently. Closed sessions cannot be reopened.</span>
                    </label>
                    <p class="ps-helper">Server re-locks the till, recalculates expected cash, and re-checks that the latest reconciliation is still current.</p>
                    <div class="ps-form-actions">
                        <button type="submit" name="till_close_submit" value="1" class="ps-btn ps-btn-destructive">Close till</button>
                    </div>
                </form>
            <?php endif; ?>
            </div>
        </div>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Closed till history</h2>
                <p class="ps-card-desc"><?php echo $isAdmin ? 'Authorised branches.' : 'Working branch only.'; ?></p>
            </div>
            <div class="ps-card-content">
            <?php if ($isAdmin): ?>
                <form method="get" class="filter-form">
                    <label for="history_branch">Branch</label>
                    <select id="history_branch" name="history_branch" onchange="this.form.submit()">
                        <option value="all"<?php echo $historyBranchFilter === 'all' || $historyBranchFilter === '' ? ' selected' : ''; ?>>All branches</option>
                        <?php foreach ($branches as $bn): ?>
                            <?php $bn = is_array($bn) ? (string)($bn['branch_name'] ?? $bn['name'] ?? '') : (string)$bn; ?>
                            <?php if ($bn === '') { continue; } ?>
                            <option value="<?php echo ps_h($bn); ?>"<?php echo $historyBranchFilter === $bn ? ' selected' : ''; ?>><?php echo ps_h($bn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <p class="ps-page-lead">Showing closed tills for your working branch only.</p>
            <?php endif; ?>

            <?php if (!$history): ?>
                <div class="ps-empty">No controlled till closures recorded yet.</div>
            <?php else: ?>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead>
                            <tr>
                                <th>Closed</th>
                                <th>Session</th>
                                <th>Branch</th>
                                <th>Expected</th>
                                <th>Counted</th>
                                <th>Variance</th>
                                <th>Status</th>
                                <th>By</th>
                                <th>Recon #</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <?php $st = (string)($row['reconciliation_status'] ?? ''); ?>
                                <tr>
                                    <td><?php echo ps_h((string)($row['closed_at'] ?? '')); ?></td>
                                    <td>#<?php echo (int)$row['id']; ?></td>
                                    <td><?php echo ps_h((string)$row['branch']); ?></td>
                                    <td><?php echo ps_h(number_format((float)($row['expected_cash'] ?? 0), 2, '.', '')); ?></td>
                                    <td><?php echo ps_h(number_format((float)($row['actual_cash'] ?? 0), 2, '.', '')); ?></td>
                                    <td><?php echo ps_h(number_format((float)($row['variance'] ?? 0), 2, '.', '')); ?></td>
                                    <td><?php if ($st !== ''): ?><span class="ps-badge ps-badge-<?php echo ps_h($st); ?>"><?php echo ps_h(psCashReconciliationStatusLabel($st)); ?></span><?php endif; ?></td>
                                    <td><?php echo ps_h((string)($row['closed_by'] ?? '')); ?></td>
                                    <td><?php echo (int)($row['final_reconciliation_id'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php ps_close(); ?>
</body>
</html>
