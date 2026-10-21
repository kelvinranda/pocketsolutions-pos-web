<?php
/**
 * Phase 7.5 — Cash movements report (paginated, read-only).
 * Cash sales / refunds from cash_movements CASH_SALE / CASH_REFUND only.
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
    'movement_type' => trim((string)($_GET['type'] ?? '')),
    'ref' => trim((string)($_GET['ref'] ?? '')),
    'session_status' => trim((string)($_GET['session_status'] ?? '')),
    'till_session_id' => (int)($_GET['till_session_id'] ?? 0),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'page_size' => 25,
];

if ($signedIn && isset($_GET['export']) && (string)$_GET['export'] === 'csv') {
    $export = psCashReportingExportCsv($conn, 'movements', $filters);
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
    $result = psCashReportingMovementsReport($conn, $filters);
    if (empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Report could not be loaded.');
        $result = null;
    } else {
        $registerOptions = psCashReportingRegisterOptions($conn, $result['filters'] ?? []);
    }
} else {
    $error = 'You must be signed in to view this report.';
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Movements Report</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash Movements Report'); ?>
    <h1>Cash Movements Report</h1>
    <p class="hint">
        Source: <code>cash_movements</code> only. Cash sales = CASH_SALE; refunds = CASH_REFUND.
        Amounts are positive; direction comes from movement type. Read-only — no mutations.
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
            <input type="text" name="cashier" placeholder="Cashier / user" value="<?php echo htmlspecialchars((string)$filters['cashier'], ENT_QUOTES, 'UTF-8'); ?>">
            <select name="type">
                <option value="">All types</option>
                <?php foreach (array_keys(psCashMovementTypes()) as $mt): ?>
                    <option value="<?php echo htmlspecialchars($mt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo strtoupper((string)$filters['movement_type']) === $mt ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars(psCashMovementTypeLabel($mt), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="ref" placeholder="Reference" value="<?php echo htmlspecialchars((string)$filters['ref'], ENT_QUOTES, 'UTF-8'); ?>">
            <select name="session_status">
                <option value="">Any session</option>
                <option value="OPEN"<?php echo strtoupper((string)$filters['session_status']) === 'OPEN' ? ' selected' : ''; ?>>Open</option>
                <option value="CLOSED"<?php echo strtoupper((string)$filters['session_status']) === 'CLOSED' ? ' selected' : ''; ?>>Closed</option>
            </select>
            <input type="number" name="till_session_id" placeholder="Session #" min="0" value="<?php echo (int)$filters['till_session_id'] > 0 ? (int)$filters['till_session_id'] : ''; ?>">
            <button type="submit">Run</button>
            <button type="submit" name="export" value="csv">Export CSV</button>
        </form>

        <?php if ($result): ?>
            <?php $totals = $result['totals']['by_type'] ?? []; ?>
            <h2>Totals by type</h2>
            <table>
                <thead><tr><th>Type</th><th>Direction</th><th>Count</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach (psCashMovementTypes() as $mt => $dir): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(psCashMovementTypeLabel($mt), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($dir, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)($totals[$mt]['count'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string)($totals[$mt]['amount'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php $rb = $result['totals']['reason_breakdown'] ?? []; ?>
            <?php if (!empty($rb['CASH_IN']) || !empty($rb['CASH_OUT'])): ?>
                <h3>IN / OUT by reason</h3>
                <table>
                    <thead><tr><th>Type</th><th>Reason</th><th>Count</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach (['CASH_IN', 'CASH_OUT'] as $mt): foreach (($rb[$mt] ?? []) as $rr): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($mt, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$rr['reason_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int)$rr['count']; ?></td>
                            <td><?php echo htmlspecialchars((string)$rr['amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Movements</h2>
            <table>
                <thead>
                    <tr>
                        <th>Datetime</th><th>Branch</th><th>Till / session</th><th>User</th><th>Type</th>
                        <th>Dir</th><th>Amount</th><th>Ref</th><th>Reason</th><th>Description</th><th>Session</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="11">No rows.</td></tr>
                <?php else: foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['movement_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php echo htmlspecialchars((string)($row['register_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            <a href="cash_session_view.php?id=<?php echo (int)$row['till_session_id']; ?>">#<?php echo (int)$row['till_session_id']; ?></a>
                        </td>
                        <td><?php echo htmlspecialchars((string)$row['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['type_label'] ?? $row['movement_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['direction'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['reference_display'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['reason_label'] ?? $row['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['session_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
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
                    Page <?php echo $page; ?> / <?php echo $pages; ?> (<?php echo (int)($pag['total_rows'] ?? 0); ?> rows)
                    <?php if ($page < $pages): $qs['page'] = $page + 1; ?><a href="?<?php echo htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
