<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/purchasing_reports.php';
require_once __DIR__ . '/includes/reporting.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$report = strtolower(trim((string)($_GET['report'] ?? 'po')));
if (!in_array($report, ['po', 'grv', 'returns'], true)) {
    $report = 'po';
}
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$grvFilter = trim((string)($_GET['grv_number'] ?? ''));
$export = strtolower(trim((string)($_GET['export'] ?? '')));
$admin = psSessionAllowsAllProductBranches();
$branchOptions = [];
$supplierOptions = [];
$rows = [];

if (!$signedIn) {
    $error = 'You must be signed in to view purchasing reports.';
} else {
    $branchOptions = psAuthorisedProductBranches($conn);
    $supList = psSupplierList($conn, '', 'all');
    if ($supList['ok']) {
        $supplierOptions = $supList['rows'] ?? [];
    }
    $filters = [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'branch' => $admin ? $branchFilter : null,
        'supplier_id' => $supplierId,
        'status' => $statusFilter,
        'grv_number' => $grvFilter,
    ];
    if ($export === 'csv') {
        $exp = psPurchasingReportExportCsv($conn, $report, $filters);
        mysqli_close($conn);
        if (empty($exp['ok'])) {
            http_response_code(403);
            die(htmlspecialchars((string)($exp['error'] ?? 'Export failed.'), ENT_QUOTES, 'UTF-8'));
        }
        psReportingSendCsv($exp);
        exit;
    }
    if ($report === 'po') {
        $result = psPurchasingPoReport($conn, $filters);
    } elseif ($report === 'grv') {
        $result = psPurchasingGrvReport($conn, $filters);
    } else {
        $result = psPurchasingReturnReport($conn, $filters);
    }
    if (!$result['ok']) {
        $error = (string)($result['error'] ?? 'Report could not be loaded.');
    } else {
        $rows = $result['rows'] ?? [];
    }
}
// 2026-10-21 currency: each document in its own currency with its stored rate; totals per currency, then base.
require_once __DIR__ . '/includes/fx_report.php';
$fx = psFxrContext($conn, $_GET);
$rows = array_values(array_filter($rows, fn($r) => psFxrRowMatches($fx, $r['currency'] ?? null)));
$prMix = [];
$prCell = function (array $r) use ($conn, $fx, $report, &$prMix): string {
    if ($report === 'po') {
        $amt = (string)($r['_ordered_value'] ?? '0');
        $rate = (float)($r['fx_rate'] ?? 0);
        $base = $rate > 0 ? number_format(round((float)$amt / $rate, 2), 2, '.', '') : null;
        $ref = 'PO ' . ($r['po_number'] ?? '');
    } elseif ($report === 'grv') {
        $amt = (string)($r['totalz'] ?? '0');
        $base = $r['base_total'] ?? null;
        $ref = 'GRV ' . ($r['grv_number'] ?? '');
    } else {
        $amt = (string)($r['total'] ?? '0');
        $base = $r['base_total'] ?? null;
        $ref = 'Return ' . ($r['return_number'] ?? '');
    }
    $meta = ['rate' => (string)($r['fx_rate'] ?? ''), 'ids' => (string)($r['fx_rate_ids'] ?? ''), 'ref' => $ref];
    psFxrAdd($prMix, $fx, $r['currency'] ?? null, $amt, $base, $meta);
    return psFxrOne($conn, $fx, $r['currency'] ?? null, $amt, $base, $meta);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchasing Reports</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Purchasing Reports</h1>
    <p class="hint">
        Ordered (PO) vs received (GRV header totals) vs returned are separate.
        Returns do not reopen PO outstanding. Do not add PO + GRV + return totals together as a single “purchasing spend” figure.
        Legacy supplier_invoices is not used here and is not payable authority.
    </p>

    <div class="tabs">
        <a class="<?php echo $report === 'po' ? 'on' : ''; ?>" href="purchasing_reports.php?report=po">PO report</a>
        <a class="<?php echo $report === 'grv' ? 'on' : ''; ?>" href="purchasing_reports.php?report=grv">GRV report</a>
        <a class="<?php echo $report === 'returns' ? 'on' : ''; ?>" href="purchasing_reports.php?report=returns">Purchase return report</a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="">
            <input type="hidden" name="report" value="<?php echo htmlspecialchars($report, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="from_date" value="<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="to_date" value="<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($admin): ?>
                <select name="branch">
                    <option value="">All branches</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="supplier_id">
                <option value="0">All suppliers</option>
                <?php foreach ($supplierOptions as $sup): ?>
                    <option value="<?php echo (int)$sup['id']; ?>"<?php echo $supplierId === (int)$sup['id'] ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$sup['supplier_code'] . ' — ' . (string)$sup['supplier_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($report === 'po'): ?>
                <select name="status">
                    <?php foreach (['all', 'draft', 'approved', 'cancelled', 'closed'] as $st): ?>
                        <option value="<?php echo $st; ?>"<?php echo $statusFilter === $st ? ' selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($report === 'grv' || $report === 'returns'): ?>
                <input type="text" name="grv_number" placeholder="GRV #" value="<?php echo htmlspecialchars($grvFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <button type="submit">Run</button>
            <a href="?report=<?php echo htmlspecialchars($report, ENT_QUOTES, 'UTF-8'); ?>&amp;from_date=<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>&amp;to_date=<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>&amp;branch=<?php echo htmlspecialchars($branchFilter, ENT_QUOTES, 'UTF-8'); ?>&amp;supplier_id=<?php echo (int)$supplierId; ?>&amp;status=<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>&amp;grv_number=<?php echo htmlspecialchars($grvFilter, ENT_QUOTES, 'UTF-8'); ?>&amp;export=csv">Export CSV</a>
            <?php foreach (psFxrQuery($fx) as $fk => $fv): ?><input type="hidden" name="<?php echo $fk; ?>" value="<?php echo htmlspecialchars($fv, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
        </form>
        <?php $keep = $_GET; unset($keep['export']); echo psFxrToolbar($fx, $keep); ?>

        <?php if ($report === 'po'): ?>
            <table>
                <thead>
                    <tr>
                        <th>PO #</th><th>Supplier</th><th>Status</th><th>Branch</th><th>Order date</th>
                        <th>Ordered qty</th><th>Received qty</th><th>Outstanding qty</th><th>Ordered value</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9">No rows.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['po_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(trim((string)($row['supplier_code'] ?? '') . ' ' . (string)($row['supplier_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['order_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['_ordered_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['_received_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['_remaining_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo $prCell($row); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php elseif ($report === 'grv'): ?>
            <table>
                <thead>
                    <tr>
                        <th>GRV #</th><th>PO #</th><th>Supplier</th><th>Supplier invoice</th>
                        <th>Date</th><th>Branch</th><th>Received total</th><th>User</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8">No rows.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($row['grv_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(trim((string)($row['supplier_code'] ?? '') . ' ' . (string)($row['product'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['reference1'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['date_'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo $prCell($row); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['created_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Return #</th><th>GRV #</th><th>PO #</th><th>Supplier</th>
                        <th>Date</th><th>Branch</th><th>Return total</th><th>User</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8">No rows.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['return_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['grv_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(trim((string)($row['supplier_code'] ?? '') . ' ' . (string)$row['supplier_name']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['return_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo $prCell($row); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php echo psFxrSummary($conn, $fx, $prMix, 'Totals by currency'); ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
