<?php
/**
 * Phase 7.2 — Till Cash Reconciliation.
 * Current open till: ledger expected (RO) + physical counted cash → FINAL snapshot.
 * Does not close/lock till. Does not alter cash_movements.
 * History: branch-scoped (admin may view all branches).
 */
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/cash_reconciliation.php';
require_once __DIR__ . '/includes/ps_layout.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/cash_controls.php';
require_once __DIR__ . '/includes/till_closure.php';

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

psEnsureCashReconciliationModule($conn);
psEnsureCashMovementModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$isAdmin = psCashReconciliationIsAdmin();
$error = '';
$success = '';
$counted = trim((string)($_POST['counted_cash'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

$sessionRow = null;
$branch = '';
$balance = null;
$existingFinal = null;
$branchSyncedNote = '';
$historyBranchFilter = trim((string)($_GET['history_branch'] ?? 'all'));
$history = [];
// Multi-currency (2026-10-20): each drawer currency is counted separately. ?cur= / posted currency; blank = base.
$tillCurrencies = [];
$reconCur = '';
$reconBase = '';
$curStates = [];
$pickReconCurrency = static function (mysqli $conn, ?array $row) use (&$tillCurrencies, &$reconCur, &$reconBase, &$curStates): void {
    if (!$row || !tillRowIsOpen($row)) {
        return;
    }
    $sid = (int)$row['id'];
    $tillCurrencies = psCashMovementTillCurrencies($conn, $sid);
    $reconBase = $tillCurrencies[0];
    $want = psFxNormalizeCode($_POST['currency'] ?? $_GET['cur'] ?? '');
    $reconCur = in_array($want, $tillCurrencies, true) ? $want : $reconBase;
    $curStates = psTillClosureAssessAllCurrencies($conn, $sid);
};

if ($signedIn) {
    $sessionRow = getActiveTillSession($conn);
    if ($sessionRow && tillRowIsOpen($sessionRow)) {
        // Blank bind + sync drifted working branch to this open till (never rewrite till).
        $aligned = tillAlignWorkingBranchToOpenSession($conn, $sessionRow, tillCurrentCashier($conn));
        if (!empty($aligned['ok'])) {
            $sessionRow = $aligned['session'];
            $branch = (string)$aligned['branch'];
            if (!empty($aligned['synced'])) {
                $branchSyncedNote = 'Working branch was set to this till\'s branch (' . $branch . ') so reconciliation can be saved.';
            } elseif (!empty($aligned['bound'])) {
                $branchSyncedNote = 'This till had no branch; it was assigned to ' . $branch . '.';
            }
        } else {
            $branch = trim((string)($sessionRow['branch'] ?? ''));
            if ($error === '' && !empty($aligned['error'])) {
                $error = (string)$aligned['error'];
            }
        }
        $pickReconCurrency($conn, $sessionRow);
        $bal = psCashMovementBalance($conn, (int)$sessionRow['id'], $reconCur);
        if (!empty($bal['ok'])) {
            $balance = $bal;
        }
        $found = psCashReconciliationFindFinalBySession($conn, (int)$sessionRow['id'], false, $reconCur);
        if (!empty($found['ok']) && !empty($found['exists'])) {
            $existingFinal = $found['row'];
        }
    }
    $histFilters = ['limit' => 50, 'offset' => 0];
    if ($isAdmin) {
        $histFilters['branch'] = $historyBranchFilter;
    }
    $hist = psCashReconciliationHistory($conn, $histFilters);
    if (!empty($hist['ok'])) {
        $history = $hist['rows'] ?? [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_recon_submit'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to reconcile the till.';
    } elseif (!psCashReconciliationConsumeSubmitToken($_POST['cash_recon_submit_token'] ?? '')) {
        $error = 'This form was already submitted or the session expired. Please try again.';
    } else {
        $result = psCashReconciliationFinalise($conn, [
            'counted_cash' => $_POST['counted_cash'] ?? '',
            'currency' => $_POST['currency'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            // Tamper probes — ignored / rejected by helper:
            'till_session_id' => $_POST['till_session_id'] ?? '',
            'branch' => $_POST['branch'] ?? '',
            'expected_cash' => $_POST['expected_cash'] ?? '',
            'variance' => $_POST['variance'] ?? '',
            'status' => $_POST['status'] ?? '',
            'cashier' => $_POST['cashier'] ?? '',
            'reconciled_by' => $_POST['reconciled_by'] ?? '',
            'posted_till_session_id' => $_POST['till_session_id'] ?? '',
            'posted_branch' => $_POST['branch'] ?? '',
        ]);
        if (!empty($result['ok'])) {
            header(
                'Location: cash_reconciliation.php?ok=1&status=' . rawurlencode((string)($result['status'] ?? ''))
                . '&var=' . rawurlencode((string)($result['variance'] ?? ''))
                . '&cur=' . rawurlencode((string)($result['currency'] ?? ''))
            );
            exit;
        }
        $error = (string)($result['error'] ?? 'Reconciliation could not be saved.');
        $counted = trim((string)($_POST['counted_cash'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $sessionRow = getActiveTillSession($conn);
        $existingFinal = null;
        $balance = null;
        if ($sessionRow && tillRowIsOpen($sessionRow)) {
            $aligned = tillAlignWorkingBranchToOpenSession($conn, $sessionRow, tillCurrentCashier($conn));
            if (!empty($aligned['ok'])) {
                $sessionRow = $aligned['session'];
                $branch = (string)$aligned['branch'];
            } else {
                $branch = trim((string)($sessionRow['branch'] ?? ''));
            }
            $pickReconCurrency($conn, $sessionRow);
            $bal = psCashMovementBalance($conn, (int)$sessionRow['id'], $reconCur);
            if (!empty($bal['ok'])) {
                $balance = $bal;
            }
            $found = psCashReconciliationFindFinalBySession($conn, (int)$sessionRow['id'], false, $reconCur);
            if (!empty($found['ok']) && !empty($found['exists'])) {
                $existingFinal = $found['row'];
            }
        }
        $histFilters = ['limit' => 50, 'offset' => 0];
        if ($isAdmin) {
            $histFilters['branch'] = $historyBranchFilter;
        }
        $hist = psCashReconciliationHistory($conn, $histFilters);
        if (!empty($hist['ok'])) {
            $history = $hist['rows'] ?? [];
        }
    }

    }
}

if (isset($_GET['ok']) && (string)$_GET['ok'] === '1') {
    $st = strtoupper(trim((string)($_GET['status'] ?? '')));
    $var = trim((string)($_GET['var'] ?? ''));
    $label = $st !== '' ? psCashReconciliationStatusLabel($st) : 'Final';
    $okCur = psFxNormalizeCode($_GET['cur'] ?? '');
    $success = 'Final ' . ($okCur !== '' ? $okCur . ' ' : '') . 'cash reconciliation saved (' . $label
        . ($var !== '' ? '; variance ' . $var : '')
        . '). Till remains open — open Cash Till Close to finish.';
}

$submitToken = $signedIn ? psCashReconciliationIssueSubmitToken() : '';
$tillOpen = $sessionRow && tillRowIsOpen($sessionRow);
$cashierEmail = '';
if ($sessionRow) {
    $cashierEmail = trim((string)($sessionRow['cashier_email'] ?? ''));
    if ($cashierEmail === '') {
        $cashierEmail = trim((string)($sessionRow['session_name'] ?? ''));
    }
}
$expectedDisplay = $balance ? (string)$balance['balance'] : '0.00';
$detail = $balance['detail'] ?? [
    'opening_float' => '0.00',
    'cash_sales' => '0.00',
    'cash_in' => '0.00',
    'cash_refunds' => '0.00',
    'cash_out' => '0.00',
];

$reconCurrent = false;
$reconStale = false;
if ($existingFinal && $tillOpen) {
    $reconExpected = number_format((float)($existingFinal['expected_cash'] ?? 0), 2, '.', '');
    $reconCurrent = ($reconExpected === $expectedDisplay);
    $reconStale = !$reconCurrent;
}

// 2026-10-17 blind count: a Till user sees expected cash only after a current final count.
$blind = psCashControlsIsTillUser() && !$reconCurrent;

$previewVariance = '';
$previewStatus = '';
if ($counted !== '' && $tillOpen && !$blind) {
    $cCheck = psValidateCashReconciliationCountedMoney($counted);
    if (!empty($cCheck['ok'])) {
        $vs = psCashReconciliationComputeVarianceStatus($cCheck['value'], $expectedDisplay);
        $previewVariance = (string)$vs['variance'];
        $previewStatus = (string)$vs['status'];
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
    <title>Cash Reconciliation</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash Reconciliation'); ?>

<h1>Cash Reconciliation</h1>
<p class="ps-page-lead">Physical cash count for the current open till. Expected cash comes from the till ledger only (Opening + Cash sales + Cash in − Refunds − Cash out). Reconciliation does not close the till and does not change the cash ledger. After cash activity, submit a fresh count before till close.</p>

<div class="ps-link-row">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_till_close.php">Cash till close</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="cash_movement.php">Cash in / out</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="ajax_pos.php">Web POS</a>
</div>

<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo ps_h($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status">
        <?php echo ps_h($success); ?>
        <p class="ps-link-row">
            <a class="ps-btn ps-btn-default ps-btn-sm" href="cash_till_close.php">Go to Cash Till Close</a>
        </p>
    </div>
<?php endif; ?>
<?php if ($branchSyncedNote !== '' && $error === ''): ?>
    <div class="ps-alert ps-alert-info" role="status"><?php echo ps_h($branchSyncedNote); ?></div>
<?php endif; ?>

<?php if (!$signedIn): ?>
    <div class="ps-empty">Please sign in to reconcile the till.</div>
<?php elseif (!$tillOpen): ?>
    <div class="ps-empty">
        No open till session. Open a till on the Web POS, then return here to count cash.
        <span class="ps-link-row"><a class="ps-btn ps-btn-outline ps-btn-sm" href="ajax_pos.php">Open Web POS</a></span>
    </div>
    <?php if ($history): ?>
        <section class="ps-section">
            <h2>Reconciliation history</h2>
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
                            <th>Date</th>
                            <th>Till / Session</th>
                            <th>Branch</th>
                            <th>Expected</th>
                            <th>Counted</th>
                            <th>Variance</th>
                            <th>Status</th>
                            <th>By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <?php $st = (string)$row['status']; ?>
                            <tr>
                                <td><?php echo ps_h((string)$row['reconciled_at']); ?></td>
                                <td>#<?php echo (int)$row['till_session_id']; ?></td>
                                <td><?php echo ps_h((string)$row['branch']); ?></td>
                                <td><?php echo ps_h(number_format((float)$row['expected_cash'], 2, '.', '') . ' ' . (string)($row['currency'] ?? '')); ?></td>
                                <td><?php echo ps_h(number_format((float)$row['counted_cash'], 2, '.', '')); ?></td>
                                <td><?php echo ps_h(number_format((float)$row['variance'], 2, '.', '')); ?></td>
                                <td><span class="ps-badge ps-badge-<?php echo ps_h($st); ?>"><?php echo ps_h((string)($row['status_label'] ?? $st)); ?></span></td>
                                <td><?php echo ps_h((string)$row['reconciled_by']); ?></td>
                                <td><?php echo ps_h((string)($row['notes'] ?? '')); ?></td>
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
                <h2 class="ps-card-title">Current till</h2>
                <p class="ps-card-desc">Count physical cash against the live ledger expected amount.</p>
            </div>
            <div class="ps-card-content">
            <div class="ps-meta">
                Branch: <?php echo ps_h($branch); ?><br>
                Till: <?php echo ps_h(tillRegisterName($sessionRow)); ?><br>
                Session #<?php echo (int)$sessionRow['id']; ?><br>
                Cashier: <?php echo ps_h($cashierEmail); ?><br>
                Opened: <?php echo ps_h(tillOpenedAt($sessionRow)); ?>
            </div>
            <?php if (count($tillCurrencies) > 1): ?>
                <div class="ps-cur-tabs" role="tablist" aria-label="Drawer currency">
                    <?php foreach ($tillCurrencies as $tc): ?>
                        <?php $cs = $curStates[$tc] ?? []; $csLabel = !empty($cs['current']) ? 'counted' : (!empty($cs['stale']) ? 'recount' : 'not counted'); ?>
                        <a role="tab" aria-selected="<?php echo $tc === $reconCur ? 'true' : 'false'; ?>"
                           class="ps-btn ps-btn-sm <?php echo $tc === $reconCur ? 'ps-btn-default' : 'ps-btn-outline'; ?>"
                           href="cash_reconciliation.php?cur=<?php echo rawurlencode($tc); ?>">
                            <?php echo ps_h($tc); ?> <span class="ps-badge ps-badge-<?php echo !empty($cs['current']) ? 'BALANCED' : 'SHORTAGE'; ?>"><?php echo ps_h($csLabel); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <p class="ps-helper">This till holds cash in <?php echo count($tillCurrencies); ?> currencies. Count each one separately; the till can close once every currency has a current count.</p>
            <?php endif; ?>
            <?php if ($blind): ?>
                <div class="ps-alert ps-alert-info">Count every note and coin in the drawer and enter the total below. The expected amount is shown after your count is saved.</div>
            <?php else: ?>
            <div class="ps-kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));">
                <div class="ps-kpi"><div class="ps-kpi-label">Opening</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['opening_float']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Sales</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_sales']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Refunds</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_refunds']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Cash in</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_in']); ?></div></div>
                <div class="ps-kpi"><div class="ps-kpi-label">Cash out</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo ps_h($detail['cash_out']); ?></div></div>
            </div>
            <div class="ps-balance">Expected <?php echo ps_h($reconCur); ?> cash: <?php echo ps_h($expectedDisplay); ?></div>
            <?php endif; ?>

            <?php if ($existingFinal): ?>
                <div class="ps-alert <?php echo $reconStale ? 'ps-alert-destructive' : 'ps-alert-info'; ?>">
                    Latest final reconciliation
                    (<span class="ps-badge ps-badge-<?php echo ps_h((string)$existingFinal['status']); ?>"><?php echo ps_h(psCashReconciliationStatusLabel((string)$existingFinal['status'])); ?></span>).
                    Counted: <?php echo ps_h(number_format((float)$existingFinal['counted_cash'], 2, '.', '')); ?>;
                    Variance: <?php echo ps_h(number_format((float)$existingFinal['variance'], 2, '.', '')); ?>;
                    Expected at count: <?php echo ps_h(number_format((float)$existingFinal['expected_cash'], 2, '.', '')); ?>.
                    Prior rows cannot be edited.
                    <?php if ($reconCurrent): ?>
                        <br><strong>Current</strong> — matches ledger expected cash. You can close the till now.
                        <p class="ps-link-row">
                            <a class="ps-btn ps-btn-default ps-btn-sm" href="cash_till_close.php">Go to Cash Till Close</a>
                        </p>
                    <?php elseif ($reconStale): ?>
                        <br><strong>Out of date</strong> — cash activity changed the ledger. Submit a fresh count before closing the till.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($reconCurrent): ?>
                <p class="ps-helper">This till is reconciled and no cash has moved since, so there is nothing new to count. A fresh count opens again after the next cash sale, refund or cash in / out.</p>
            <?php else: ?>
            <form method="post" class="ps-form-stack" autocomplete="off">
                <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <input type="hidden" name="cash_recon_submit_token" value="<?php echo ps_h($submitToken); ?>">
                <input type="hidden" name="currency" value="<?php echo ps_h($reconCur); ?>">
                <label class="ps-label" for="counted_cash"><?php echo $existingFinal ? 'Fresh counted cash' : 'Counted cash'; ?> (<?php echo ps_h($reconCur); ?>)</label>
                <input class="ps-input" id="counted_cash" name="counted_cash" type="text" inputmode="decimal" required
                       value="<?php echo ps_h($counted); ?>" placeholder="0.00">
                <span class="ps-helper"><?php echo $blind ? 'Enter the cash you counted in the drawer.' : 'Enter the physical till count. Server recalculates variance. Use the expected cash amount when the drawer matches the ledger.'; ?></span>
                <label class="ps-label" for="notes">Notes / reason</label>
                <textarea class="ps-input" id="notes" name="notes" maxlength="2000"
                          placeholder="<?php echo $blind ? 'Optional. Required if the system finds a shortage or surplus.' : 'Required for shortage or surplus'; ?>"><?php echo ps_h($notes); ?></textarea>
                <?php if ($previewStatus !== ''): ?>
                    <div class="ps-meta">
                        Preview variance:
                        <span class="ps-badge ps-badge-<?php echo ps_h($previewStatus); ?>">
                            <?php echo ps_h($previewVariance); ?>
                            (<?php echo ps_h(psCashReconciliationStatusLabel($previewStatus)); ?>)
                        </span>
                    </div>
                <?php endif; ?>
                <p class="ps-helper">This creates a FINAL reconciliation for this open till session. No cash ledger adjustment is posted.</p>
                <div class="ps-form-actions">
                    <button class="ps-btn ps-btn-default" type="submit" name="cash_recon_submit" value="1"><?php echo $existingFinal ? 'Finalise fresh reconciliation' : 'Finalise reconciliation'; ?></button>
                </div>
            </form>
            <?php endif; ?>
            </div>
        </div>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Reconciliation history</h2>
                <p class="ps-card-desc"><?php echo $isAdmin ? 'Branch-scoped history for authorised branches.' : 'Working branch only.'; ?></p>
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
                <p class="ps-page-lead">Showing reconciliations for your working branch only.</p>
            <?php endif; ?>

            <?php if (!$history): ?>
                <div class="ps-empty">No reconciliations recorded yet.</div>
            <?php else: ?>
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Till / Session</th>
                                <th>Branch</th>
                                <th>Expected</th>
                                <th>Counted</th>
                                <th>Variance</th>
                                <th>Status</th>
                                <th>By</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <?php $st = (string)$row['status']; ?>
                                <tr>
                                    <td><?php echo ps_h((string)$row['reconciled_at']); ?></td>
                                    <td>#<?php echo (int)$row['till_session_id']; ?></td>
                                    <td><?php echo ps_h((string)$row['branch']); ?></td>
                                    <td><?php echo ps_h(number_format((float)$row['expected_cash'], 2, '.', '') . ' ' . (string)($row['currency'] ?? '')); ?></td>
                                    <td><?php echo ps_h(number_format((float)$row['counted_cash'], 2, '.', '')); ?></td>
                                    <td><?php echo ps_h(number_format((float)$row['variance'], 2, '.', '')); ?></td>
                                    <td><span class="ps-badge ps-badge-<?php echo ps_h($st); ?>"><?php echo ps_h((string)($row['status_label'] ?? $st)); ?></span></td>
                                    <td><?php echo ps_h((string)$row['reconciled_by']); ?></td>
                                    <td><?php echo ps_h((string)($row['notes'] ?? '')); ?></td>
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
