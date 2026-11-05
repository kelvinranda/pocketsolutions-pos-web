<?php
/**
 * Phase 8.6 — Refund reconciliation finding detail + read-only review history.
 * Does not create or modify review/audit records.
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
$detail = null;
$key = trim((string)($_GET['key'] ?? ''));

$filters = [
    'from_date' => trim((string)($_GET['from_date'] ?? '')),
    'to_date' => trim((string)($_GET['to_date'] ?? '')),
    'branch' => $isAdmin ? trim((string)($_GET['branch'] ?? '')) : null,
    'discrepancy_status' => 'ALL',
    'page' => 1,
    'page_size' => 25,
];

if (!$signedIn) {
    $error = 'Please sign in to view finding details.';
} elseif ($key === '') {
    $error = 'A discrepancy key is required.';
} else {
    $detail = psSaleRefundReconReportFindingDetail($conn, $key, $filters);
    if (empty($detail['ok'])) {
        $error = (string)($detail['error'] ?? 'Finding detail could not be loaded.');
        $detail = null;
    }
}

$rfCur = '';
$rfInv = (string)($detail['finding']['invoice_number'] ?? '');
if ($rfInv !== '') {
    require_once __DIR__ . '/includes/currency.php';
    $cst = mysqli_prepare($conn, 'SELECT currency FROM online_sales_ref WHERE invoice_number = ? LIMIT 1');
    if ($cst) {
        $inv = $rfInv;
        mysqli_stmt_bind_param($cst, 's', $inv);
        mysqli_stmt_execute($cst);
        $cr = mysqli_fetch_assoc(mysqli_stmt_get_result($cst));
        mysqli_stmt_close($cst);
        $rfCur = $cr ? (psFxNormalizeCode($cr['currency'] ?? '') ?: psFxLegacyCurrency() . ' (legacy)') : '';
    }
}
mysqli_close($conn);

$finding = $detail['finding'] ?? null;
$ctx = $detail['context'] ?? [];
$history = $detail['review_history'] ?? [];
$backQs = http_build_query([
    'from_date' => $filters['from_date'] ?? '',
    'to_date' => $filters['to_date'] ?? '',
    'branch' => $filters['branch'] ?? '',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Reconciliation Finding</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Refund recon finding'); ?>
    <h1>Refund Reconciliation Finding</h1>
    <p class="hint">
        Read-only. Review status changes are recorded only on the operational recon screen.
        <a href="refund_recon_report.php?<?php echo htmlspecialchars($backQs, ENT_QUOTES, 'UTF-8'); ?>">← Report</a>
        · <a href="refund_reconciliation.php">Operational recon</a>
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($finding): ?>
        <?php $sev = (string)($finding['severity'] ?? ''); ?>
        <div class="block">
            <h2>Summary</h2>
            <dl class="kv">
                <dt>Discrepancy key</dt>
                <dd><code><?php echo htmlspecialchars((string)$finding['discrepancy_key'], ENT_QUOTES, 'UTF-8'); ?></code></dd>
                <dt>Category / code</dt>
                <dd>
                    <?php echo htmlspecialchars(psSaleRefundReconCategoryLabel((string)$finding['category']), ENT_QUOTES, 'UTF-8'); ?>
                    · <?php echo htmlspecialchars((string)$finding['code'], ENT_QUOTES, 'UTF-8'); ?>
                </dd>
                <dt>Recon status</dt>
                <dd class="sev-<?php echo htmlspecialchars($sev, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($sev === 'INCONCLUSIVE' ? 'Inconclusive (not confirmed financial loss)' : $sev, ENT_QUOTES, 'UTF-8'); ?>
                </dd>
                <dt>Review status</dt>
                <dd>
                    <?php echo htmlspecialchars((string)($finding['review_status'] ?? 'UNRESOLVED'), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($finding['reviewed_at'])): ?>
                        · <?php echo htmlspecialchars((string)$finding['reviewed_at'], ENT_QUOTES, 'UTF-8'); ?>
                        · <?php echo htmlspecialchars((string)($finding['reviewed_by'] !== '' ? $finding['reviewed_by'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                    <?php else: ?>
                        · last review: Not available
                    <?php endif; ?>
                </dd>
                <dt>Branch</dt>
                <dd><?php echo htmlspecialchars((string)($finding['branch'] !== '' ? $finding['branch'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Sale / refund refs</dt>
                <dd>
                    Sale: <?php echo htmlspecialchars((string)($finding['invoice_number'] !== '' ? $finding['invoice_number'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                    · REF: <?php echo htmlspecialchars((string)($finding['refund_number'] !== '' ? $finding['refund_number'] : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                </dd>
                <dt>Explanation</dt>
                <dd><?php echo htmlspecialchars((string)($ctx['plain_explanation'] ?? $finding['why'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Expected vs observed (qty)</dt>
                <dd>
                    Expected: <?php echo htmlspecialchars(psSaleRefundReconReportDisplayValue($finding['qty_expected'] ?? null, $sev === 'INCONCLUSIVE' ? 'Inconclusive' : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                    · Actual: <?php echo htmlspecialchars(psSaleRefundReconReportDisplayValue($finding['qty_actual'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                    · Diff: <?php echo htmlspecialchars(psSaleRefundReconReportDisplayValue($finding['qty_diff'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                </dd>
                <dt>Expected vs observed (amount<?php echo $rfCur !== '' ? ', ' . htmlspecialchars($rfCur, ENT_QUOTES, 'UTF-8') : ''; ?>)</dt>
                <dd>
                    Expected: <?php echo htmlspecialchars(psSaleRefundReconReportDisplayValue($finding['amount_expected'] ?? null, $sev === 'INCONCLUSIVE' ? 'Inconclusive' : 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                    · Actual: <?php echo htmlspecialchars(psSaleRefundReconReportDisplayValue($finding['amount_actual'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                    · Diff: <?php echo htmlspecialchars(psSaleRefundReconReportDisplayValue($finding['amount_diff'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                </dd>
                <dt>Expected stock (rule)</dt>
                <dd><?php echo htmlspecialchars((string)($ctx['expected_stock'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Expected cash (rule)</dt>
                <dd><?php echo htmlspecialchars((string)($ctx['expected_cash'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Payment method</dt>
                <dd><?php echo htmlspecialchars((string)($ctx['payment_method'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></dd>
                <?php if (!empty($finding['evidence'])): ?>
                    <dt>Evidence</dt>
                    <dd><?php echo htmlspecialchars((string)$finding['evidence'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="block">
            <h2>Sale</h2>
            <?php if (empty($ctx['sale_header'])): ?>
                <p class="hint">Not available</p>
            <?php else: $h = $ctx['sale_header']; ?>
                <p class="kv">
                    Invoice <?php echo htmlspecialchars((string)$h['invoice_number'], ENT_QUOTES, 'UTF-8'); ?>
                    · status <?php echo htmlspecialchars((string)($h['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    · branch <?php echo htmlspecialchars((string)($h['branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    · total <?php echo htmlspecialchars((string)($h['totalz'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <table>
                    <thead><tr><th>Line</th><th>Product</th><th>Qty</th><th>Unit price</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (empty($ctx['sale_lines'])): ?>
                        <tr><td colspan="5">Not available</td></tr>
                    <?php else: foreach ($ctx['sale_lines'] as $sl): ?>
                        <tr>
                            <td><?php echo (int)$sl['id']; ?></td>
                            <td><?php echo htmlspecialchars((string)$sl['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$sl['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($sl['unit_price'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($sl['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="block">
            <h2>Refund request / processed lines</h2>
            <?php if (empty($ctx['refund_request'])): ?>
                <p class="hint">Not available</p>
            <?php else: $r = $ctx['refund_request']; ?>
                <p class="kv">
                    <?php echo htmlspecialchars((string)$r['refund_number'], ENT_QUOTES, 'UTF-8'); ?>
                    · status <?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8'); ?>
                    · requested <?php echo htmlspecialchars((string)($r['requested_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    · processed <?php echo htmlspecialchars((string)($r['processed_amount'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                    · at <?php echo htmlspecialchars((string)($r['processed_at'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <table>
                    <thead><tr><th>Sale line</th><th>Product</th><th>Qty</th><th>Line amount snapshot</th></tr></thead>
                    <tbody>
                    <?php if (empty($ctx['refund_lines'])): ?>
                        <tr><td colspan="4">Not available</td></tr>
                    <?php else: foreach ($ctx['refund_lines'] as $rl): ?>
                        <tr>
                            <td><?php echo (int)$rl['sale_line_id']; ?></td>
                            <td><?php echo htmlspecialchars((string)$rl['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$rl['requested_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($rl['line_amount_snapshot'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="block">
            <h2>Stock REFUND movements</h2>
            <table>
                <thead><tr><th>Id</th><th>Product</th><th>Branch</th><th>Qty</th><th>At</th><th>Notes</th></tr></thead>
                <tbody>
                <?php if (empty($ctx['stock_movements'])): ?>
                    <tr><td colspan="6">Not available</td></tr>
                <?php else: foreach ($ctx['stock_movements'] as $sm): ?>
                    <tr>
                        <td><?php echo (int)$sm['id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$sm['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$sm['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$sm['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($sm['movement_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($sm['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="block">
            <h2>Cash CASH_REFUND movements</h2>
            <table>
                <thead><tr><th>Id</th><th>Branch</th><th>Amount</th><th>Till session</th><th>At</th></tr></thead>
                <tbody>
                <?php if (empty($ctx['cash_movements'])): ?>
                    <tr><td colspan="5">Not available</td></tr>
                <?php else: foreach ($ctx['cash_movements'] as $cm): ?>
                    <tr>
                        <td><?php echo (int)$cm['id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$cm['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$cm['amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($cm['till_session_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($cm['movement_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="block">
            <h2>Refund audit</h2>
            <table>
                <thead><tr><th>Event</th><th>Amount</th><th>By</th><th>At</th><th>Note</th></tr></thead>
                <tbody>
                <?php if (empty($ctx['refund_audits'])): ?>
                    <tr><td colspan="5">Not available</td></tr>
                <?php else: foreach ($ctx['refund_audits'] as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($a['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($a['amount'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($a['actor_email'] ?? $a['created_by'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($a['created_at'] ?? $a['event_at'] ?? 'Not available'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($a['event_note'] ?? $a['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="block">
            <h2>Review history (read-only)</h2>
            <p class="hint">Source: <code>sale_refund_recon_reviews</code> / <code>sale_refund_recon_review_audit</code>. This page does not append rows.</p>
            <table>
                <thead><tr><th>When</th><th>Action</th><th>From → To</th><th>Reviewer</th><th>Reason</th><th>Source</th></tr></thead>
                <tbody>
                <?php if (empty($history)): ?>
                    <tr><td colspan="6">No review history.</td></tr>
                <?php else: foreach ($history as $hr): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($hr['reviewed_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($hr['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php echo htmlspecialchars((string)($hr['previous_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            →
                            <?php echo htmlspecialchars((string)($hr['new_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($hr['reviewed_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($hr['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($hr['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
