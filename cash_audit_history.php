<?php
/**
 * Phase 7.5 — Read-only cash audit visibility (movement / recon / closure).
 * Authorised users only; no edit/delete.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/cash_reporting.php';
require_once __DIR__ . '/includes/ps_layout.php';
require_once __DIR__ . '/includes/cash_controls.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCashReportingModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$isAdmin = psCashReportingIsAdmin();
$error = '';
$result = null;

$kind = strtolower(trim((string)($_GET['kind'] ?? 'movements')));
if (!in_array($kind, ['movements', 'reconciliations', 'closures', 'reprints'], true)) {
    $kind = 'movements';
}

$filters = [
    'from_date' => trim((string)($_GET['from_date'] ?? '')),
    'to_date' => trim((string)($_GET['to_date'] ?? '')),
    'branch' => $isAdmin ? trim((string)($_GET['branch'] ?? '')) : null,
    'till_session_id' => (int)($_GET['till_session_id'] ?? 0),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'page_size' => 50,
];

$branchOptions = [];
if ($signedIn) {
    $branchOptions = psAuthorisedProductBranches($conn);
    if ($kind === 'reprints') {
        // 2026-10-17: receipt reprints (web receipt_print.php and desktop tills).
        $rf = $filters['from_date'] !== '' ? $filters['from_date'] : date('Y-m-d', strtotime('-7 days'));
        $rt = $filters['to_date'] !== '' ? $filters['to_date'] : date('Y-m-d');
        $reprints = psReceiptReprintList($conn, $rf, $rt, (string)($filters['branch'] ?? ''));
        $result = ['ok' => true, 'rows' => [], 'reprints' => true];
    } else {
        $result = psCashReportingAuditList($conn, $kind, $filters);
    }
    if (empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Audit could not be loaded.');
        $result = null;
    }
} else {
    $error = 'You must be signed in.';
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Audit History</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash Audit History'); ?>
    <h1>Cash audit history</h1>
    <p class="hint">
        Read-only visibility into movement create, reconciliation, and closure audits.
        No edit or delete. <a href="cash_management.php">← Cash management</a>
    </p>

    <div class="tabs">
        <a class="<?php echo $kind === 'movements' ? 'on' : ''; ?>" href="?kind=movements">Movement audit</a>
        <a class="<?php echo $kind === 'reconciliations' ? 'on' : ''; ?>" href="?kind=reconciliations">Reconciliation audit</a>
        <a class="<?php echo $kind === 'closures' ? 'on' : ''; ?>" href="?kind=closures">Closure audit</a>
        <a class="<?php echo $kind === 'reprints' ? 'on' : ''; ?>" href="?kind=reprints">Receipt reprints</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="">
            <input type="hidden" name="kind" value="<?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>">
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
            <input type="number" name="till_session_id" placeholder="Session #" min="0" value="<?php echo (int)$filters['till_session_id'] > 0 ? (int)$filters['till_session_id'] : ''; ?>">
            <button type="submit">Filter</button>
        </form>

        <?php if ($kind === 'reprints'): ?>
            <p class="hint">Every receipt printed again after the sale, plus approvals and refused attempts, newest first (last 7 days unless you pick dates). Many reprints of one receipt, or by one cashier, are worth a look.</p>
            <table>
                <thead><tr><th>When</th><th>Receipt</th><th>Document</th><th>Branch</th><th>Requested by</th><th>Approved by</th><th>Outcome</th><th>Where</th></tr></thead>
                <tbody>
                <?php if (empty($reprints)): ?>
                    <tr><td colspan="8">No reprints in this period.</td></tr>
                <?php else: foreach ($reprints as $rp): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$rp['reprinted_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$rp['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', (string)($rp['document'] ?? 'RECEIPT')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$rp['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$rp['reprinted_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($rp['approved_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td title="<?php echo htmlspecialchars((string)($rp['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($rp['outcome'] ?? 'PRINTED'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$rp['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php elseif ($result): ?>
            <table>
                <thead>
                    <tr>
                        <th>When</th><th>Branch</th><th>Session</th><th>Action</th><th>Field</th>
                        <th>Old</th><th>New</th><th>By</th><th>Source</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="9">No audit rows.</td></tr>
                <?php else: foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if (!empty($row['till_session_id'])): ?>
                                <a href="cash_session_view.php?id=<?php echo (int)$row['till_session_id']; ?>">#<?php echo (int)$row['till_session_id']; ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($row['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['field_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['changed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php
            $pag = $result['pagination'] ?? [];
            $page = (int)($pag['page'] ?? 1);
            $pages = (int)($pag['total_pages'] ?? 1);
            $qs = $_GET;
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
<?php ps_close(); ?>
</body>
</html>
