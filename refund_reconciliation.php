<?php
/**
 * Phase 8.5 — Refund / cash / stock reconciliation (read-only detection + optional review status).
 * Does not modify historical sales, stock_movements, cash_movements, or AR.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/sale_refund_reconciliation.php';
require_once __DIR__ . '/includes/ps_layout.php';

ini_set('display_errors', '0');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSaleRefundReconModule($conn);

$signedIn = psSaleRefundReconMayView();
$isAdmin = psSaleRefundReconIsAdmin();
$mayReview = psSaleRefundReconMayReview();
$error = '';
$success = '';
$result = null;

$filters = [
    'from_date' => trim((string)($_GET['from_date'] ?? $_POST['from_date'] ?? '')),
    'to_date' => trim((string)($_GET['to_date'] ?? $_POST['to_date'] ?? '')),
    'branch' => $isAdmin ? trim((string)($_GET['branch'] ?? $_POST['branch'] ?? '')) : null,
    'category' => trim((string)($_GET['category'] ?? $_POST['category'] ?? '')),
    'discrepancy_status' => trim((string)($_GET['discrepancy_status'] ?? $_POST['discrepancy_status'] ?? 'ALL_OPEN')),
    'page' => max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1)),
    'page_size' => 25,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_review'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'Please sign in to update review status.';
    } elseif (!$mayReview) {
        $error = 'You are not authorised to update review status.';
    } else {
        $rev = psSaleRefundReconRecordReview($conn, [
            'discrepancy_key' => (string)($_POST['discrepancy_key'] ?? ''),
            'category' => (string)($_POST['category'] ?? ''),
            'invoice_number' => (string)($_POST['invoice_number'] ?? ''),
            'refund_number' => (string)($_POST['refund_number'] ?? ''),
            'branch' => (string)($_POST['review_branch'] ?? ''),
            'new_status' => (string)($_POST['new_status'] ?? ''),
            'reason' => (string)($_POST['reason'] ?? ''),
            'source' => PS_REFUND_RECON_SOURCE_UI,
        ]);
        if (!empty($rev['ok'])) {
            $success = 'Review status recorded (append-only). Original discrepancy remains visible separately from resolution.';
        } else {
            $error = (string)($rev['error'] ?? 'Review status could not be saved.');
        }
    }

    }
}

$branchOptions = [];
if ($signedIn) {
    $branchOptions = psAuthorisedProductBranches($conn);
    $result = psSaleRefundReconciliationRun($conn, $filters);
    if (empty($result['ok'])) {
        $error = $error !== '' ? $error : (string)($result['error'] ?? 'Reconciliation could not be loaded.');
        $result = null;
    } else {
        $filters = array_merge($filters, $result['filters'] ?? []);
    }
} else {
    $error = 'Please sign in to view refund reconciliation.';
}

mysqli_close($conn);

$totalPages = 1;
if ($result && (int)($result['total'] ?? 0) > 0) {
    $totalPages = (int)ceil((int)$result['total'] / (int)($filters['page_size'] ?? 25));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Reconciliation</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Refund Reconciliation'); ?>
    <h1>Refund Reconciliation</h1>
    <p class="hint">
        Read-only detection of refund vs stock REFUND vs CASH_REFUND inconsistencies.
        Does <strong>not</strong> auto-correct ledgers, invent CREDIT notes, or rewrite history.
        Branch is resolved from session / permissions (posted foreign branch rejected).
        <a href="refund.php">← Refunds</a>
        · <a href="refund_recon_report.php">Recon report</a>
        · <a href="cash_management.php">Cash management</a>
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
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
            <select name="category">
                <option value="">All categories</option>
                <?php foreach (psSaleRefundReconCategories() as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($filters['category'] ?? '') === $cat ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars(psSaleRefundReconCategoryLabel($cat), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="discrepancy_status">
                <?php
                $statusOpts = [
                    'ALL_OPEN' => 'Open (unresolved)',
                    'UNRESOLVED' => 'Unresolved only',
                    'REVIEWED' => 'Reviewed',
                    'INCONCLUSIVE' => 'Inconclusive severity',
                    'INCONCLUSIVE_ACK' => 'Inconclusive acknowledged',
                    'ALL' => 'All findings',
                ];
                foreach ($statusOpts as $sk => $sl):
                ?>
                    <option value="<?php echo htmlspecialchars($sk, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($filters['discrepancy_status'] ?? '') === $sk ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($sl, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Run reconciliation</button>
        </form>

        <?php if ($result): ?>
            <?php $sum = $result['summary'] ?? []; ?>
            <div class="summary hint">
                <span>Processed scanned: <?php echo (int)($sum['processed_requests_scanned'] ?? 0); ?></span>
                <span>Findings: <?php echo (int)($sum['findings_total'] ?? 0); ?></span>
                <span>Discrepancies: <?php echo (int)($sum['discrepancies'] ?? 0); ?></span>
                <span>Inconclusive: <?php echo (int)($sum['inconclusive'] ?? 0); ?></span>
                <span>Unresolved: <?php echo (int)($sum['unresolved'] ?? 0); ?></span>
                <span>Showing <?php echo count($result['rows'] ?? []); ?> of <?php echo (int)($result['total'] ?? 0); ?></span>
            </div>
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
                    <th>Severity</th>
                    <th>Category</th>
                    <th>Invoice / REF</th>
                    <th>Product / Qty</th>
                    <th>Amount exp / act / diff</th>
                    <th>Why flagged</th>
                    <th>Review</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="7">No findings for these filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($result['rows'] as $row): ?>
                        <tr>
                            <td class="sev-<?php echo htmlspecialchars((string)$row['severity'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string)$row['severity'], ENT_QUOTES, 'UTF-8'); ?>
                                <div class="hint"><?php echo htmlspecialchars((string)$row['code'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars(psSaleRefundReconCategoryLabel((string)$row['category']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                Inv: <?php echo htmlspecialchars((string)$row['invoice_number'], ENT_QUOTES, 'UTF-8'); ?><br>
                                REF: <?php echo htmlspecialchars((string)$row['refund_number'], ENT_QUOTES, 'UTF-8'); ?><br>
                                <span class="hint"><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($row['payment_method'])): ?>
                                        · <?php echo htmlspecialchars((string)$row['payment_method'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)($row['product_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($row['sale_line_id'])): ?>
                                    <div class="hint">line #<?php echo (int)$row['sale_line_id']; ?></div>
                                <?php endif; ?>
                                <?php if ($row['qty_expected'] !== null || $row['qty_actual'] !== null): ?>
                                    <div class="hint">
                                        qty exp <?php echo htmlspecialchars((string)($row['qty_expected'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                        / act <?php echo htmlspecialchars((string)($row['qty_actual'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                        / diff <?php echo htmlspecialchars((string)($row['qty_diff'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['amount_expected'] !== null || $row['amount_actual'] !== null): ?>
                                    <?php echo htmlspecialchars((string)($row['amount_expected'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    /
                                    <?php echo htmlspecialchars((string)($row['amount_actual'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                    /
                                    <?php echo htmlspecialchars((string)($row['amount_diff'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars((string)$row['why'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($row['evidence'])): ?>
                                    <div class="hint"><?php echo htmlspecialchars((string)$row['evidence'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars((string)$row['review_status'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (!empty($row['reviewed_by'])): ?>
                                    <div class="hint">
                                        <?php echo htmlspecialchars((string)$row['reviewed_by'], ENT_QUOTES, 'UTF-8'); ?>
                                        @ <?php echo htmlspecialchars((string)$row['reviewed_at'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php if (!empty($row['review_reason'])): ?>
                                        <div class="hint"><?php echo htmlspecialchars((string)$row['review_reason'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($mayReview): ?>
                                    <form method="POST" class="review-box">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                        <input type="hidden" name="set_review" value="1">
                                        <input type="hidden" name="discrepancy_key" value="<?php echo htmlspecialchars((string)$row['discrepancy_key'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="category" value="<?php echo htmlspecialchars((string)$row['category'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="invoice_number" value="<?php echo htmlspecialchars((string)$row['invoice_number'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="refund_number" value="<?php echo htmlspecialchars((string)$row['refund_number'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="review_branch" value="<?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="from_date" value="<?php echo htmlspecialchars((string)$filters['from_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="to_date" value="<?php echo htmlspecialchars((string)$filters['to_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="category_filter" value="">
                                        <select name="new_status">
                                            <option value="REVIEWED">Reviewed</option>
                                            <option value="INCONCLUSIVE_ACK">Acknowledge inconclusive</option>
                                            <option value="UNRESOLVED">Reopen unresolved</option>
                                        </select>
                                        <input type="text" name="reason" placeholder="Reason (required)" required maxlength="500">
                                        <button type="submit">Save review</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pager hint">
                    Page <?php echo (int)$filters['page']; ?> of <?php echo $totalPages; ?>
                    <?php
                    $qBase = [
                        'from_date' => $filters['from_date'],
                        'to_date' => $filters['to_date'],
                        'branch' => $filters['branch'] ?? '',
                        'category' => $filters['category'] ?? '',
                        'discrepancy_status' => $filters['discrepancy_status'] ?? '',
                    ];
                    for ($p = 1; $p <= $totalPages && $p <= 50; $p++):
                        $qBase['page'] = $p;
                        $href = '?' . http_build_query($qBase);
                    ?>
                        <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
