<?php
/**
 * Phase 7.5 — Cash till closure report (paginated, read-only).
 * Uses stored closure snapshot on sessions — does not recompute from today's ledger.
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
$result = null;

$filters = [
    'from_date' => trim((string)($_GET['from_date'] ?? '')),
    'to_date' => trim((string)($_GET['to_date'] ?? '')),
    'branch' => $isAdmin ? trim((string)($_GET['branch'] ?? '')) : null,
    'register_id' => (int)($_GET['register_id'] ?? 0),
    'cashier' => trim((string)($_GET['cashier'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'till_session_id' => (int)($_GET['till_session_id'] ?? 0),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'page_size' => 25,
    'fx_view' => $_GET['fx_view'] ?? '',
    'fx_cur' => $_GET['fx_cur'] ?? '',
];

if ($signedIn && isset($_GET['export']) && (string)$_GET['export'] === 'csv') {
    $export = psCashReportingExportCsv($conn, 'closures', $filters);
    if (!empty($export['ok'])) {
        mysqli_close($conn);
        psCashReportingSendCsv($export);
        exit;
    }
    $error = (string)($export['error'] ?? 'Export failed.');
}

$branchOptions = [];
$registerOptions = [];
if ($signedIn) {
    $branchOptions = psAuthorisedProductBranches($conn);
    $result = psCashReportingClosureReport($conn, $filters);
    if (empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Report could not be loaded.');
        $result = null;
    } else {
        $registerOptions = psCashReportingRegisterOptions($conn, $result['filters'] ?? []);
    }
} else {
    $error = 'You must be signed in to view this report.';
}

// Counts are kept per drawer currency and store no exchange rate: always shown in the drawer's own currency.
$fx = $result['filters']['fx'] ?? psFxrContext($conn, $_GET);
$C = static fn($v, $cur): string => htmlspecialchars(psFxFormat($conn, $v ?? 0, psFxNormalizeCode($cur ?? '') ?: $fx['base']), ENT_QUOTES, 'UTF-8');
$N = static fn(?array $mix): string => $mix ? psFxrAmount($conn, $fx, $mix, ['native' => true]) : htmlspecialchars(psFxFormat($conn, 0, $fx['base']), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Till Closure Report</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash Closure Report'); ?>
    <h1>Till Closure Report</h1>
    <p class="hint">
        Source: stored closure snapshot on <code>sessions</code> (not recomputed from today's ledger).
        BALANCED / SHORTAGE / SURPLUS from closure. Read-only.
        <a href="cash_management.php">← Cash management</a>
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="">
            <input type="date" name="from_date" value="<?php echo htmlspecialchars((string)$filters['from_date'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars((string)$filters['to_date'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($isAdmin): ?>
                <select name="branch">
                    <option value="">All branches</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($filters['branch'] ?? '') === $bn ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="register_id">
                <option value="0">All tills</option>
                <?php foreach ($registerOptions as $reg): ?>
                    <option value="<?php echo (int)$reg['id']; ?>"<?php echo (int)$filters['register_id'] === (int)$reg['id'] ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$reg['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="cashier" placeholder="Cashier" value="<?php echo htmlspecialchars((string)$filters['cashier'], ENT_QUOTES, 'UTF-8'); ?>">
            <select name="status">
                <option value="">All variance statuses</option>
                <?php foreach (psCashReconciliationStatuses() as $st): ?>
                    <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>"<?php echo strtoupper((string)$filters['status']) === $st ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars(psCashReconciliationStatusLabel($st), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Run</button>
            <?php if ($fx['cur'] !== ''): ?><input type="hidden" name="fx_cur" value="<?php echo htmlspecialchars($fx['cur'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <button type="submit" name="export" value="csv">Export CSV</button>
        </form>
        <?php if (!empty($fx['multi'])): $keep = $_GET; unset($keep['export'], $keep['page'], $keep['fx_cur'], $keep['fx_view']); ?>
        <form method="get" class="fxr-bar no-print"><?php foreach ($keep as $kk => $kv): if (!is_array($kv)): ?><input type="hidden" name="<?php echo htmlspecialchars($kk, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$kv, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; endforeach; ?>
            <label class="fxr-cur">Drawer currency <select name="fx_cur" onchange="this.form.submit()"><option value="">All currencies</option><?php foreach ($fx['currencies'] as $code => $cinfo): ?><option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $fx['cur'] === $code ? ' selected' : ''; ?>><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        </form>
        <p class="fxr-label">Each till has one cash drawer per currency. Counts are shown in the drawer's own currency and never converted (no exchange rate is stored with a count); totals are per currency.</p>
        <?php endif; ?>

        <?php if ($result): ?>
            <table>
                <thead>
                    <tr>
                        <th>Session</th><th>Branch</th><th>Till</th><th>Cashier</th><th>Opened</th><th>Closed</th>
                        <th>Float</th><th>Expected</th><th>Actual</th><th>Variance<br><small>base drawer, then other drawers</small></th><th>Status</th>
                        <th>Final recon</th><th>Notes</th><th>Closed by</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="14">No rows.</td></tr>
                <?php else: foreach ($result['rows'] as $row): ?>
                    <?php $st = strtoupper(trim((string)($row['reconciliation_status'] ?? ''))); ?>
                    <tr>
                        <td><a href="cash_session_view.php?id=<?php echo (int)$row['id']; ?>">#<?php echo (int)$row['id']; ?></a></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['register_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['cashier_email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['opened_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['closed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo $C($row['opening_float'], null); ?></td>
                        <td class="num"><?php echo $C($row['expected_cash'], null); ?></td>
                        <td class="num"><?php echo $C($row['actual_cash'], null); ?></td>
                        <td class="num"><?php echo $C($row['variance'], null); ?><?php foreach (($row['currency_counts'] ?? []) as $cc => $cr): ?><br><small><?php echo htmlspecialchars($cc, ENT_QUOTES, 'UTF-8'); ?> drawer: expected <?php echo $C($cr['expected_cash'], $cc); ?>, counted <?php echo $C($cr['counted_cash'], $cc); ?>, variance <?php echo $C($cr['variance'], $cc); ?></small><?php endforeach; ?></td>
                        <td class="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($row['variance_status_label'] ?? $st), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td><?php echo (int)($row['final_reconciliation_id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['closure_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['closed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php
            $pag = $result['pagination'] ?? [];
            $page = (int)($pag['page'] ?? 1);
            $pages = (int)($pag['total_pages'] ?? 1);
            $qs = $_GET;
            unset($qs['export']);
            if ($pages > 1):
            ?>
                <p>
                    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a href="?<?php echo htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8'); ?>">Prev</a><?php endif; ?>
                    Page <?php echo $page; ?> / <?php echo $pages; ?>
                    <?php if ($page < $pages): $qs['page'] = $page + 1; ?><a href="?<?php echo htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
