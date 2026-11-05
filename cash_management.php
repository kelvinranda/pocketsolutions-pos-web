<?php
/**
 * Phase 7.5 — Cash Management Dashboard (read-only).
 * Cards + open till status + stale warnings + recent recon/closure + movement summary.
 * Does not create/edit/delete movements, recon, or closures.
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
$isAdmin = psCashReportingIsAdmin();
$error = '';
$dash = null;
$cashierSummary = null;
$tillSummary = null;
$branchSummary = null;

$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$registerId = (int)($_GET['register_id'] ?? 0);
$cashier = trim((string)($_GET['cashier'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$view = strtolower(trim((string)($_GET['view'] ?? 'dashboard')));
if (!in_array($view, ['dashboard', 'cashiers', 'tills', 'branches', 'history'], true)) {
    $view = 'dashboard';
}

$branchOptions = [];
$registerOptions = [];

if ($signedIn) {
    $branchOptions = psAuthorisedProductBranches($conn);
    $filters = [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'branch' => $isAdmin ? $branchFilter : null,
        'register_id' => $registerId,
        'cashier' => $cashier,
        'status' => $status,
        'fx_view' => $_GET['fx_view'] ?? '',
        'fx_cur' => $_GET['fx_cur'] ?? '',
    ];
    $dash = psCashReportingDashboard($conn, $filters);
    if (empty($dash['ok'])) {
        $error = (string)($dash['error'] ?? 'Dashboard could not be loaded.');
        $dash = null;
    } else {
        $f = $dash['filters'] ?? $filters;
        $registerOptions = psCashReportingRegisterOptions($conn, $f);
        if ($view === 'cashiers') {
            $cashierSummary = psCashReportingCashierSummary($conn, $filters);
        } elseif ($view === 'tills') {
            $tillSummary = psCashReportingTillSummary($conn, $filters);
        } elseif ($view === 'branches') {
            $branchSummary = psCashReportingBranchSummary($conn, $filters);
        } elseif ($view === 'history') {
            $history = psCashReportingClosedTillHistory($conn, array_merge($filters, ['page' => max(1, (int)($_GET['page'] ?? 1))]));
        }
    }
} else {
    $error = 'You must be signed in to view cash management.';
}

// 2026-10-21 currency display: consolidated (base, converted marked) / by currency; counts always in their own currency.
$fx = isset($dash['filters']['fx']) ? $dash['filters']['fx'] : psFxrContext($conn, $_GET);
$M = static fn(?array $mix, array $o = []): string => psFxrAmount($conn, $fx, $mix ?? [], $o);
$B = static fn($v): string => htmlspecialchars(psFxFormat($conn, $v ?? 0, $fx['base']), ENT_QUOTES, 'UTF-8');
$C = static fn($v, $cur): string => htmlspecialchars(psFxFormat($conn, $v ?? 0, psFxNormalizeCode($cur ?? '') ?: $fx['base']), ENT_QUOTES, 'UTF-8');
$N = static fn(?array $mix): string => $mix ? psFxrAmount($conn, $fx, $mix, ['native' => true]) : htmlspecialchars(psFxFormat($conn, 0, $fx['base']), ENT_QUOTES, 'UTF-8');

$q = static function (array $extra = []) use ($fromDate, $toDate, $branchFilter, $registerId, $cashier, $status, $isAdmin, $fx): string {
    $base = psFxrQuery($fx) + [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'register_id' => $registerId > 0 ? (string)$registerId : '',
        'cashier' => $cashier,
        'status' => $status,
    ];
    if ($isAdmin) {
        $base['branch'] = $branchFilter;
    }
    $merged = array_merge($base, $extra);
    $parts = [];
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return $parts === [] ? '' : ('?' . implode('&', $parts));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Management</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash Management'); ?>
    <h1>Cash Management</h1>
    <p class="ps-page-lead">Expected cash in each open till = opening float + cash sales + cash in − cash refunds − cash out. Closed tills show the figures saved at closing. Shortages and surpluses are listed separately. Card, EcoCash and account payments are not counted as till cash.</p>

    <div class="nav-links">
        <a href="cash_movements_report.php">Cash movements report</a>
        <a href="cash_reconciliation_report.php">Reconciliation report</a>
        <a href="cash_closure_report.php">Closure report</a>
        <a href="cash_audit_history.php">Cash audit</a>
        <a href="cash_reconciliation.php">Reconcile till</a>
        <a href="cash_till_close.php">Close till</a>
        <a href="cash_movement.php">Cash in / out</a>
    </div>

    <div class="tabs" style="margin-top:12px;">
        <a class="<?php echo $view === 'dashboard' ? 'on' : ''; ?>" href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'dashboard']), ENT_QUOTES, 'UTF-8'); ?>">Dashboard</a>
        <a class="<?php echo $view === 'cashiers' ? 'on' : ''; ?>" href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'cashiers']), ENT_QUOTES, 'UTF-8'); ?>">Cashier summary</a>
        <a class="<?php echo $view === 'tills' ? 'on' : ''; ?>" href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'tills']), ENT_QUOTES, 'UTF-8'); ?>">Till summary</a>
        <a class="<?php echo $view === 'branches' ? 'on' : ''; ?>" href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'branches']), ENT_QUOTES, 'UTF-8'); ?>">Branch summary</a>
        <a class="<?php echo $view === 'history' ? 'on' : ''; ?>" href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'history']), ENT_QUOTES, 'UTF-8'); ?>">Closed till history</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" style="margin-top:12px;">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>" title="From">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>" title="To (full day)">
            <?php if ($isAdmin): ?>
                <select name="branch">
                    <option value="">All branches</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="register_id">
                <option value="0">All tills</option>
                <?php foreach ($registerOptions as $reg): ?>
                    <option value="<?php echo (int)$reg['id']; ?>"<?php echo $registerId === (int)$reg['id'] ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$reg['name'] . ' (' . (string)$reg['branch'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="cashier" placeholder="Cashier email" value="<?php echo htmlspecialchars($cashier, ENT_QUOTES, 'UTF-8'); ?>">
            <select name="status">
                <option value="">All statuses</option>
                <?php
                $statusOpts = [
                    'AWAITING' => 'Awaiting recon',
                    'CURRENT' => 'Current',
                    'STALE' => 'Stale',
                    'BALANCED' => 'Balanced',
                    'SHORTAGE' => 'Shortage',
                    'SURPLUS' => 'Surplus',
                    'RECONCILED' => 'Reconciled',
                    'CLOSED' => 'Closed',
                ];
                foreach ($statusOpts as $sk => $sl):
                ?>
                    <option value="<?php echo $sk; ?>"<?php echo strtoupper($status) === $sk ? ' selected' : ''; ?>><?php echo htmlspecialchars($sl, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php foreach (psFxrQuery($fx) as $fk => $fv): ?><input type="hidden" name="<?php echo $fk; ?>" value="<?php echo htmlspecialchars($fv, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
            <button type="submit">Apply filters</button>
        </form>
        <?php echo psFxrToolbar($fx, array_filter(['view' => $view, 'from_date' => $fromDate, 'to_date' => $toDate, 'branch' => $isAdmin ? $branchFilter : '', 'register_id' => $registerId > 0 ? (string)$registerId : '', 'cashier' => $cashier, 'status' => $status], fn($v) => $v !== '')); ?>
        <p class="fxr-label">Each till has one cash drawer per currency. Counts (expected / counted / variance) are always shown in the drawer's own currency: no exchange rate is stored with a count, so they are never converted.</p>
    <?php endif; ?>

    <?php if ($dash !== null && $view === 'dashboard'): ?>
        <?php $cards = $dash['cards']; ?>
        <div class="cm-cards">
            <div class="cm-card"><div class="lbl">Open tills</div><div class="val"><?php echo (int)$cards['open_tills']; ?></div>
                <div class="hint">Closed in period: <?php echo (int)$cards['closed_in_period']; ?><br><?php echo htmlspecialchars((string)$cards['branch'], ENT_QUOTES, 'UTF-8'); ?></div></div>
            <div class="cm-card"><div class="lbl">Expected cash (open)</div><div class="val"><?php echo $M($cards['fx_expected'] ?? []); ?></div></div>
            <div class="cm-card"><div class="lbl">Cash sales</div><div class="val"><?php echo $M($cards['fx_cash_sales'] ?? []); ?></div></div>
            <div class="cm-card"><div class="lbl">Cash in</div><div class="val"><?php echo $M($cards['fx_cash_in'] ?? []); ?></div></div>
            <div class="cm-card"><div class="lbl">Cash out</div><div class="val"><?php echo $M($cards['fx_cash_out'] ?? []); ?></div></div>
            <div class="cm-card"><div class="lbl">Cash refunds</div><div class="val"><?php echo $M($cards['fx_cash_refunds'] ?? []); ?></div></div>
            <div class="cm-card"><div class="lbl">Shortages</div><div class="val"><?php echo (int)$cards['shortages_count']; ?></div>
                <div class="hint"><?php echo $N($cards['fx_shortages'] ?? []); ?></div></div>
            <div class="cm-card"><div class="lbl">Surpluses</div><div class="val"><?php echo (int)$cards['surpluses_count']; ?></div>
                <div class="hint"><?php echo $N($cards['fx_surpluses'] ?? []); ?></div></div>
        </div>

        <?php echo psFxrSummary($conn, $fx, $cards['fx_expected'] ?? [], 'Expected cash in open tills, by drawer currency'); ?>
        <?php if (!empty($dash['stale_warnings'])): ?>
            <div class="cm-warn">
                <h3>Stale reconciliation warning</h3>
                <p class="hint">Latest FINAL expected cash no longer matches the live ledger. Reconcile again before closing.</p>
                <table>
                    <thead>
                        <tr><th>Cashier</th><th>Till</th><th>Branch</th><th>Last expected</th><th>Current expected</th><th>Difference</th><th>Recon date</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dash['stale_warnings'] as $w): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$w['cashier'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$w['till'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$w['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $B($w['last_expected']); ?></td>
                            <td><?php echo $B($w['current_expected']); ?></td>
                            <td class="stale"><?php echo $B($w['difference']); ?></td>
                            <td><?php echo htmlspecialchars((string)$w['recon_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><a href="cash_reconciliation.php">Reconcile</a> · <a href="cash_session_view.php?id=<?php echo (int)$w['till_session_id']; ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2>Open till status</h2>
        <table>
            <thead>
                <tr>
                    <th>Branch</th><th>Till</th><th>Cashier</th><th>Opened</th><th>Float<br><small><?php echo htmlspecialchars($fx['base']); ?> drawer</small></th>
                    <th>Sales<br><small><?php echo htmlspecialchars($fx['base']); ?> drawer</small></th><th>Refunds</th><th>In</th><th>Out</th><th>Expected<br><small>all drawers</small></th>
                    <th>Recon status</th><th>Recon expected</th><th>Variance</th><th>Count up to date?</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($dash['open_tills'])): ?>
                <tr><td colspan="15">No open tills for these filters.</td></tr>
            <?php else: foreach ($dash['open_tills'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['register_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['cashier'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['opened_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $B($row['opening_float']); ?></td>
                    <td><?php echo $B($row['cash_sales']); ?></td>
                    <td><?php echo $B($row['cash_refunds']); ?></td>
                    <td><?php echo $B($row['cash_in']); ?></td>
                    <td><?php echo $B($row['cash_out']); ?></td>
                    <td><?php echo $M($row['fx_expected'] ?? []); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['latest_recon_status'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $row['latest_recon_expected'] === null ? '—' : $B($row['latest_recon_expected']); ?></td>
                    <td><?php echo $row['variance'] === null ? '—' : $B($row['variance']); ?></td>
                    <td class="<?php echo !empty($row['stale']) ? 'stale' : 'ok'; ?>">
                        <?php echo htmlspecialchars(psCashReportingStatusLabel((string)$row['currency']), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td><a href="cash_session_view.php?id=<?php echo (int)$row['till_session_id']; ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Cash movement summary</h2>
        <?php $ms = $dash['movement_summary'] ?? []; $by = $ms['by_type'] ?? []; ?>
        <table>
            <thead><tr><th>Type</th><th>Direction</th><th>Count</th><th>Amount</th></tr></thead>
            <tbody>
            <?php foreach (psCashMovementTypes() as $mt => $dir): ?>
                <tr>
                    <td><?php echo htmlspecialchars(psCashMovementTypeLabel($mt), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($dir, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)($by[$mt]['count'] ?? 0); ?></td>
                    <td class="num"><?php echo $M($by[$mt]['fx'] ?? []); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $rb = $ms['reason_breakdown'] ?? [];
        if (!empty($rb['CASH_IN']) || !empty($rb['CASH_OUT'])):
        ?>
            <h3>Cash in / out by reason (operational)</h3>
            <table>
                <thead><tr><th>Type</th><th>Reason</th><th>Count</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach (['CASH_IN', 'CASH_OUT'] as $mt): foreach (($rb[$mt] ?? []) as $rr): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(psCashMovementTypeLabel($mt), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$rr['reason_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$rr['count']; ?></td>
                        <td class="num"><?php echo $M($rr['fx'] ?? []); ?></td>
                    </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Recent reconciliations</h2>
        <table>
            <thead>
                <tr><th>Date</th><th>Branch</th><th>Till</th><th>Cashier</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Status</th><th>Session</th></tr>
            </thead>
            <tbody>
            <?php if (empty($dash['recent_recons'])): ?>
                <tr><td colspan="9">No reconciliations.</td></tr>
            <?php else: foreach ($dash['recent_recons'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['reconciled_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['register_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['cashier_email'] ?? $row['reconciled_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $C($row['expected_cash'], $row['currency'] ?? null); ?></td>
                    <td><?php echo $C($row['counted_cash'], $row['currency'] ?? null); ?></td>
                    <td><?php echo $C($row['variance'], $row['currency'] ?? null); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['status_label'] ?? $row['status']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="cash_session_view.php?id=<?php echo (int)$row['till_session_id']; ?>">#<?php echo (int)$row['till_session_id']; ?></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Recent closures</h2>
        <table>
            <thead>
                <tr><th>Closed</th><th>Branch</th><th>Till</th><th>Cashier</th><th>Expected</th><th>Actual</th><th>Variance</th><th>Status</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php if (empty($dash['recent_closures'])): ?>
                <tr><td colspan="9">No closures.</td></tr>
            <?php else: foreach ($dash['recent_closures'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['closed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['register_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['cashier_email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $B($row['expected_cash']); ?></td>
                    <td><?php echo $B($row['actual_cash']); ?></td>
                    <td><?php echo $B($row['variance']); ?></td>
                    <td><?php echo htmlspecialchars(psCashReconciliationStatusLabel((string)($row['reconciliation_status'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['closed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($view === 'cashiers' && $cashierSummary && !empty($cashierSummary['ok'])): ?>
        <h2>Cashier summary</h2>
        <p class="hint">Operational figures — not a performance appraisal.</p>
        <table>
            <thead>
                <tr><th>Cashier</th><th>Sessions</th><th>Sales</th><th>Refunds</th><th>In</th><th>Out</th>
                    <th>Recons</th><th>Balanced</th><th>Shortage #</th><th>Shortage</th><th>Surplus #</th><th>Surplus</th></tr>
            </thead>
            <tbody>
            <?php if (empty($cashierSummary['rows'])): ?>
                <tr><td colspan="12">No rows.</td></tr>
            <?php else: foreach ($cashierSummary['rows'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['cashier'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$row['sessions']; ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_sales'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_refunds'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_in'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_out'] ?? []); ?></td>
                    <td><?php echo (int)$row['recon_count']; ?></td>
                    <td><?php echo (int)$row['balanced_count']; ?></td>
                    <td><?php echo (int)$row['shortage_count']; ?></td>
                    <td class="num"><?php echo $N($row['fx_shortage'] ?? []); ?></td>
                    <td><?php echo (int)$row['surplus_count']; ?></td>
                    <td class="num"><?php echo $N($row['fx_surplus'] ?? []); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($view === 'tills' && $tillSummary && !empty($tillSummary['ok'])): ?>
        <h2>Till / register summary</h2>
        <table>
            <thead>
                <tr><th>Branch</th><th>Till</th><th>Sessions</th><th>Open</th><th>Closed</th>
                    <th>Sales</th><th>Refunds</th><th>In</th><th>Out</th>
                    <th>Shortage #</th><th>Shortage</th><th>Surplus #</th><th>Surplus</th></tr>
            </thead>
            <tbody>
            <?php if (empty($tillSummary['rows'])): ?>
                <tr><td colspan="13">No rows.</td></tr>
            <?php else: foreach ($tillSummary['rows'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['register_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$row['sessions']; ?></td>
                    <td><?php echo (int)$row['open_sessions']; ?></td>
                    <td><?php echo (int)$row['closed_sessions']; ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_sales'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_refunds'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_in'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_out'] ?? []); ?></td>
                    <td><?php echo (int)$row['shortage_count']; ?></td>
                    <td class="num"><?php echo $N($row['fx_shortage'] ?? []); ?></td>
                    <td><?php echo (int)$row['surplus_count']; ?></td>
                    <td class="num"><?php echo $N($row['fx_surplus'] ?? []); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($view === 'branches' && $branchSummary && !empty($branchSummary['ok'])): ?>
        <h2>Branch summary</h2>
        <p class="hint">Worked out from till movements, counts and closures.</p>
        <table>
            <thead>
                <tr><th>Branch</th><th>Open tills</th><th>Closed sessions</th><th>Sales</th><th>Refunds</th>
                    <th>In</th><th>Out</th><th>Shortages</th><th>Shortage</th><th>Surpluses</th><th>Surplus</th></tr>
            </thead>
            <tbody>
            <?php if (empty($branchSummary['rows'])): ?>
                <tr><td colspan="11">No rows.</td></tr>
            <?php else: foreach ($branchSummary['rows'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$row['open_tills']; ?></td>
                    <td><?php echo (int)$row['closed_sessions']; ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_sales'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_refunds'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_in'] ?? []); ?></td>
                    <td class="num"><?php echo $M($row['fx_cash_out'] ?? []); ?></td>
                    <td><?php echo (int)$row['shortages_count']; ?></td>
                    <td class="num"><?php echo $N($row['fx_shortage'] ?? []); ?></td>
                    <td><?php echo (int)$row['surpluses_count']; ?></td>
                    <td class="num"><?php echo $N($row['fx_surplus'] ?? []); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($view === 'history' && isset($history) && !empty($history['ok'])): ?>
        <h2>Closed till history</h2>
        <p class="hint">Uses stored closure snapshot. Drill into session for movements / recon / audit (read-only).</p>
        <table>
            <thead>
                <tr><th>Session</th><th>Branch</th><th>Till</th><th>Cashier</th><th>Opened</th><th>Closed</th>
                    <th>Expected (<?php echo htmlspecialchars($fx['base']); ?> drawer)</th><th>Actual</th><th>Variance<br><small>other drawers below</small></th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($history['rows'])): ?>
                <tr><td colspan="11">No closed tills.</td></tr>
            <?php else: foreach ($history['rows'] as $row): ?>
                <tr>
                    <td>#<?php echo (int)$row['id']; ?></td>
                    <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['register_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['cashier_email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['opened_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['closed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo $B($row['expected_cash']); ?></td>
                    <td><?php echo $B($row['actual_cash']); ?></td>
                    <td><?php echo $B($row['variance']); ?><?php foreach (($row['currency_counts'] ?? []) as $cc => $cr): ?><br><small><?php echo $C($cr['counted_cash'], $cc); ?> counted · <?php echo $C($cr['variance'], $cc); ?></small><?php endforeach; ?></td>
                    <td><?php echo htmlspecialchars((string)($row['variance_status_label'] ?? $row['reconciliation_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="cash_session_view.php?id=<?php echo (int)$row['id']; ?>">Details</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
        $pag = $history['pagination'] ?? [];
        $page = (int)($pag['page'] ?? 1);
        $pages = (int)($pag['total_pages'] ?? 1);
        if ($pages > 1):
        ?>
            <p>
                <?php if ($page > 1): ?><a href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'history', 'page' => (string)($page - 1)]), ENT_QUOTES, 'UTF-8'); ?>">Prev</a><?php endif; ?>
                Page <?php echo $page; ?> / <?php echo $pages; ?>
                <?php if ($page < $pages): ?><a href="cash_management.php<?php echo htmlspecialchars($q(['view' => 'history', 'page' => (string)($page + 1)]), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
