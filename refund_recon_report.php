<?php
/**
 * Phase 8.6 — Refund reconciliation reporting (summary + filterable findings + CSV).
 * Read-only. Does not create findings or review records.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/sale_refund_recon_reporting.php';
require_once __DIR__ . '/includes/ps_layout.php';

ini_set('display_errors', '0');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSaleRefundReconModule($conn);

$signedIn = psSaleRefundReconReportMayView();
$isAdmin = psSaleRefundReconReportIsAdmin();
$error = '';
$result = null;

$filters = [
    'from_date' => trim((string)($_GET['from_date'] ?? '')),
    'to_date' => trim((string)($_GET['to_date'] ?? '')),
    'branch' => $isAdmin ? trim((string)($_GET['branch'] ?? '')) : null,
    'category' => trim((string)($_GET['category'] ?? '')),
    'discrepancy_status' => trim((string)($_GET['discrepancy_status'] ?? 'ALL')),
    'severity' => trim((string)($_GET['severity'] ?? '')),
    'sale_ref' => trim((string)($_GET['sale_ref'] ?? '')),
    'refund_ref' => trim((string)($_GET['refund_ref'] ?? '')),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'page_size' => 25,
];

if ($signedIn && isset($_GET['export']) && (string)$_GET['export'] === 'csv') {
    $export = psSaleRefundReconReportExportCsv($conn, $filters);
    if (!empty($export['ok'])) {
        mysqli_close($conn);
        psSaleRefundReconReportSendCsv($export);
        exit;
    }
    $error = (string)($export['error'] ?? 'Export failed.');
}

$branchOptions = [];
if ($signedIn) {
    $branchOptions = psAuthorisedProductBranches($conn);
    $result = psSaleRefundReconReportRun($conn, $filters);
    if (empty($result['ok'])) {
        $error = $error !== '' ? $error : (string)($result['error'] ?? 'Report could not be loaded.');
        $result = null;
    } else {
        $filters = array_merge($filters, $result['filters'] ?? []);
    }
} else {
    $error = 'Please sign in to view refund reconciliation reporting.';
}

// 2026-10-21: a finding compares amounts of ONE sale / refund, so they are in that sale's currency (never summed).
require_once __DIR__ . '/includes/fx_report.php';
$rrCur = [];
$rrInv = array_values(array_unique(array_filter(array_map(fn($r) => (string)($r['invoice_number'] ?? ''), $result['rows'] ?? []))));
if ($rrInv) {
    $in = implode(',', array_map(fn($v) => "'" . mysqli_real_escape_string($conn, $v) . "'", $rrInv));
    $cres = mysqli_query($conn, "SELECT invoice_number, currency FROM online_sales_ref WHERE invoice_number IN ($in)");
    while ($cres && ($cr = mysqli_fetch_assoc($cres))) {
        $rrCur[(string)$cr['invoice_number']] = psFxNormalizeCode($cr['currency'] ?? '') ?: psFxLegacyCurrency();
    }
}
mysqli_close($conn);

$sum = $result['summary'] ?? [];
$pag = $result['pagination'] ?? [];
$totalPages = (int)($pag['total_pages'] ?? 1);
$page = (int)($pag['page'] ?? 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Reconciliation Report</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Refund Reconciliation Report'); ?>
    <h1>Refund Reconciliation Report</h1>
    <p class="hint">
        Checks that each processed refund matches its sale, stock return and cash paid out. Viewing or exporting this report changes nothing.
        Confirmed problems are listed separately from items that could not be checked.
        <a href="refund_reconciliation.php">Refund Reconciliation</a>
        · <a href="refund.php">Refunds</a>
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
                    <option value="">All authorised branches</option>
                    <?php foreach ($branchOptions as $bn): ?>
                        <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($filters['branch'] ?? '') === $bn ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select name="category">
                <option value="">All categories</option>
                <?php foreach (psSaleRefundReconCategories() as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($filters['category'] ?? '') === $cat ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars(psSaleRefundReconCategoryLabel($cat), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="severity">
                <option value="">All severities</option>
                <option value="DISCREPANCY"<?php echo ($filters['severity'] ?? '') === 'DISCREPANCY' ? ' selected' : ''; ?>>Confirmed discrepancy</option>
                <option value="INCONCLUSIVE"<?php echo ($filters['severity'] ?? '') === 'INCONCLUSIVE' ? ' selected' : ''; ?>>Inconclusive</option>
            </select>
            <select name="discrepancy_status">
                <?php
                $statusOpts = [
                    'ALL' => 'All review statuses',
                    'ALL_OPEN' => 'Awaiting review (open)',
                    'UNRESOLVED' => 'Unresolved',
                    'REVIEWED' => 'Reviewed',
                    'INCONCLUSIVE' => 'Inconclusive severity',
                    'INCONCLUSIVE_ACK' => 'Inconclusive acknowledged',
                ];
                foreach ($statusOpts as $sk => $sl):
                ?>
                    <option value="<?php echo htmlspecialchars($sk, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($filters['discrepancy_status'] ?? '') === $sk ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($sl, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="sale_ref" placeholder="Sale / invoice ref" value="<?php echo htmlspecialchars((string)($filters['sale_ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="refund_ref" placeholder="Refund REF" value="<?php echo htmlspecialchars((string)($filters['refund_ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit">Run report</button>
            <button type="submit" name="export" value="csv">Export CSV</button>
        </form>

        <?php if ($result): ?>
            <div class="cards">
                <div class="card">
                    <strong><?php echo (int)($sum['findings_filtered'] ?? 0); ?></strong>
                    <span class="lbl">Findings (filtered)</span>
                </div>
                <div class="card">
                    <strong class="sev-DISCREPANCY"><?php echo (int)($sum['discrepancies'] ?? 0); ?></strong>
                    <span class="lbl">Confirmed discrepancies</span>
                </div>
                <div class="card">
                    <strong class="sev-INCONCLUSIVE"><?php echo (int)($sum['inconclusive'] ?? 0); ?></strong>
                    <span class="lbl">Inconclusive</span>
                </div>
                <div class="card">
                    <strong><?php echo (int)($sum['awaiting_review'] ?? $sum['unresolved'] ?? 0); ?></strong>
                    <span class="lbl">Awaiting review</span>
                </div>
                <div class="card">
                    <strong><?php echo (int)($sum['reviewed'] ?? 0); ?></strong>
                    <span class="lbl">Reviewed / ack</span>
                </div>
                <div class="card">
                    <strong><?php echo htmlspecialchars((string)($filters['from_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        → <?php echo htmlspecialchars((string)($filters['to_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="lbl">Period</span>
                </div>
            </div>

            <p class="hint">
                <?php echo htmlspecialchars((string)($sum['amount_totals_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                Counts above match the filtered findings table (same filters).
            </p>

            <?php if (!empty($sum['by_category'])): ?>
                <div class="summary-grid hint">
                    <?php foreach ($sum['by_category'] as $cat => $cnt): ?>
                        <?php if ((int)$cnt < 1) {
                            continue;
                        } ?>
                        <span><?php echo htmlspecialchars(psSaleRefundReconCategoryLabel((string)$cat), ENT_QUOTES, 'UTF-8'); ?>:
                            <?php echo (int)$cnt; ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($sum['by_branch'])): ?>
                <div class="summary-grid hint">
                    <strong>By branch:</strong>
                    <?php foreach ($sum['by_branch'] as $br => $cnt): ?>
                        <span><?php echo htmlspecialchars((string)$br, ENT_QUOTES, 'UTF-8'); ?>: <?php echo (int)$cnt; ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($result['limitations'])): ?>
                <ul class="limitations">
                    <?php foreach ($result['limitations'] as $lim): ?>
                        <li><?php echo htmlspecialchars((string)$lim, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <table>
                <thead>
                <tr>
                    <th>Key</th>
                    <th>Branch</th>
                    <th>Sale / Refund</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Qty exp / act</th>
                    <th>Amt exp / act</th>
                    <th>Recon</th>
                    <th>Review</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="10">No findings for these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($result['rows'] as $row): ?>
                        <?php
                        $sev = (string)($row['severity'] ?? '');
                        $qtyExp = $row['qty_expected'] !== null && $row['qty_expected'] !== ''
                            ? (string)$row['qty_expected']
                            : ($sev === 'INCONCLUSIVE' ? 'Inconclusive' : 'Not available');
                        $qtyAct = $row['qty_actual'] !== null && $row['qty_actual'] !== ''
                            ? (string)$row['qty_actual']
                            : 'Not available';
                        $rc = $rrCur[(string)($row['invoice_number'] ?? '')] ?? '';
                        $amtExp = $row['amount_expected'] !== null && $row['amount_expected'] !== ''
                            ? (string)$row['amount_expected'] . ($rc !== '' ? ' ' . $rc : '')
                            : ($sev === 'INCONCLUSIVE' ? 'Inconclusive' : 'Not available');
                        $amtAct = $row['amount_actual'] !== null && $row['amount_actual'] !== ''
                            ? (string)$row['amount_actual'] . ($rc !== '' ? ' ' . $rc : '')
                            : 'Not available';
                        $detailQs = http_build_query([
                            'key' => (string)$row['discrepancy_key'],
                            'from_date' => $filters['from_date'] ?? '',
                            'to_date' => $filters['to_date'] ?? '',
                            'branch' => $filters['branch'] ?? '',
                        ]);
                        ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars((string)$row['discrepancy_key'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($row['branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                Sale: <?php echo htmlspecialchars((string)($row['invoice_number'] !== '' ? $row['invoice_number'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?><br>
                                REF: <?php echo htmlspecialchars((string)($row['refund_number'] !== '' ? $row['refund_number'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(psSaleRefundReconCategoryLabel((string)$row['category']), ENT_QUOTES, 'UTF-8'); ?>
                                <div class="hint"><?php echo htmlspecialchars((string)$row['code'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars((string)$row['why'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($qtyExp . ' / ' . $qtyAct, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($amtExp . ' / ' . $amtAct, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="sev-<?php echo htmlspecialchars($sev, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($sev === 'INCONCLUSIVE' ? 'Inconclusive' : $sev, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)$row['review_status'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($row['reviewed_at'])): ?>
                                    <div class="hint">
                                        <?php echo htmlspecialchars((string)$row['reviewed_at'], ENT_QUOTES, 'UTF-8'); ?>
                                        · <?php echo htmlspecialchars((string)($row['reviewed_by'] !== '' ? $row['reviewed_by'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="hint">Not available</div>
                                <?php endif; ?>
                            </td>
                            <td><a href="refund_recon_finding.php?<?php echo htmlspecialchars($detailQs, ENT_QUOTES, 'UTF-8'); ?>">Detail</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pager hint">
                    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                    (<?php echo (int)($pag['total'] ?? 0); ?> findings)
                    <?php
                    $qs = $_GET;
                    unset($qs['export']);
                    if ($page > 1):
                        $qs['page'] = $page - 1;
                    ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8'); ?>">Prev</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages):
                        $qs['page'] = $page + 1;
                    ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
