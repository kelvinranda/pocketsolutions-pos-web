<?php
/**
 * Phase 11 — Export batch history + drill-down + CSV + admin reversal.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/accounting.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psAccountingRequireAuthRedirect();
psEnsureAccountingModule($conn);

$isAdmin = psBranchSessionIsAdmin();
$user = trim((string)($_SESSION['logger'] ?? ''));
$batchId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reverse']) && $batchId > 0) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $rev = psAccountingReverseBatch($conn, $batchId, $user);
        if (!empty($rev['ok'])) {
            psSecurityAudit($conn, 'ACCOUNTING_REVERSE', [
                'summary' => 'Accounting batch reversed',
                'target_type' => 'accounting_batch',
                'target_id' => (string)$batchId,
            ]);
            mysqli_close($conn);
            header('Location: accounting_batches.php?id=' . (int)$rev['batch_id'] . '&reversed=1');
            exit;
        }
        $error = (string)($rev['error'] ?? 'Reversal failed.');
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $batchId > 0) {
    $csv = psAccountingBatchCsv($conn, $batchId);
    mysqli_close($conn);
    if (empty($csv['ok'])) {
        http_response_code(403);
        die(htmlspecialchars((string)($csv['error'] ?? 'Export failed.'), ENT_QUOTES, 'UTF-8'));
    }
    psReportingSendCsv($csv);
    exit;
}

$detail = null;
if ($batchId > 0) {
    $detail = psAccountingGetBatch($conn, $batchId);
    if (empty($detail['ok'])) {
        $error = $error !== '' ? $error : (string)($detail['error'] ?? 'Batch not found.');
        $detail = null;
    }
}
$list = psAccountingListBatches($conn, 50);

if (isset($_GET['exported'])) {
    $message = 'Batch exported locally (CSV-ready). external_status remains NOT_SENT — not confirmed in an external system.';
}
if (isset($_GET['reversed'])) {
    $message = 'Reversal batch created. Original marked REVERSED; sources unlocked for re-export.';
}

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
// 2026-10-21: journals are in the company base (each source converted at its own stored rate).
require_once __DIR__ . '/includes/fx_report.php';
$fx = ['view' => PS_FXR_CONSOLIDATED] + psFxrContext($conn, []);
$money = static function ($v) use ($conn, $fx): string {
    return htmlspecialchars(psFxFormat($conn, $v ?? 0, $fx['base']), ENT_QUOTES, 'UTF-8');
};
// A journal amount with its source document's currency and stored rate (marker + tooltip when converted).
$E = static function (array $e) use ($conn, $fx): string {
    $cur = (string)($e['txn_currency'] ?? '');
    if ($cur === '' || $cur === $fx['base']) {
        return htmlspecialchars(psFxFormat($conn, $e['amount'] ?? 0, $fx['base']), ENT_QUOTES, 'UTF-8');
    }
    return psFxrOne($conn, $fx, $cur, $e['txn_amount'] ?? null, $e['amount'] ?? null,
        ['rate' => (string)($e['fx_rate'] ?? ''), 'ref' => trim((string)($e['source_type'] ?? '') . ' #' . (string)($e['source_id'] ?? '') . ' ' . (string)($e['doc_number'] ?? ''))]);
};
$fxMixOf = static function (array $entries) use ($fx): array {
    $mix = [];
    foreach ($entries as $e) {
        $cur = (string)($e['txn_currency'] ?? '');
        psFxrAdd($mix, $fx, $cur === '' ? $fx['base'] : $cur, ($e['txn_amount'] ?? null) !== null && $cur !== '' ? $e['txn_amount'] : ($e['amount'] ?? 0), $e['amount'] ?? null, ['rate' => (string)($e['fx_rate'] ?? '')]);
    }
    return $mix;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Batches</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Accounting Batches'); ?>
<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting.php">← Accounting</a>
</div>
<h1>Export batches</h1>
<p class="ps-page-lead">Batch history, drill-down, CSV download, and admin-safe reversal.</p>

<?php if ($message !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status"><?php echo $h($message); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>

<?php if ($detail): ?>
<?php $b = $detail['batch']; ?>
<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title"><?php echo $h($b['batch_number']); ?></h2>
        <p class="ps-card-desc">
            Status: <strong><?php echo $h($b['status']); ?></strong>
            · External: <strong><?php echo $h($b['external_status']); ?></strong>
            (NOT_SENT = not confirmed externally)
        </p>
    </div>
    <div class="ps-card-content">
        <p class="ps-muted">Dates: <?php echo $h($b['from_date'] . ' → ' . $b['to_date']); ?></p>
        <p class="ps-muted">Branch: <?php echo (int)$b['all_branches'] === 1 ? 'All authorised (+ company-wide where applicable)' : $h($b['branch_filter']); ?></p>
        <p class="ps-muted">Sources: <?php echo (int)$b['source_count']; ?> · Entries: <?php echo (int)$b['entry_count']; ?></p>
        <p class="ps-muted">Debit <?php echo $money($b['total_debit']); ?> / Credit <?php echo $money($b['total_credit']); ?></p>
        <p class="ps-muted">Created by <?php echo $h($b['created_by']); ?> at <?php echo $h($b['created_at']); ?></p>
        <?php if (!empty($b['notes'])): ?><p class="ps-muted"><?php echo $h($b['notes']); ?></p><?php endif; ?>
        <div class="ps-link-row ps-split-gap">
            <a class="ps-btn ps-btn-default ps-btn-sm" href="accounting_batches.php?id=<?php echo (int)$b['id']; ?>&amp;export=csv">Download CSV</a>
            <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting_batches.php">Back to list</a>
        </div>
        <?php if ($isAdmin && strtoupper((string)$b['status']) === 'EXPORTED'): ?>
        <form method="post" action="accounting_batches.php" class="ps-form-stack ps-split-gap" onsubmit="return confirm('Create a reversal batch for this export?');">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
            <div class="ps-form-actions">
                <button class="ps-btn ps-btn-default" type="submit" name="do_reverse" value="1">Create safe reversal</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<section class="ps-section">
    <h2>Sources in batch</h2>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead><tr><th>Type</th><th>ID</th><th>Doc</th><th>Date</th><th>Branch</th><th>Label</th><th>Amount</th><th>Method</th></tr></thead>
        <tbody>
        <?php foreach ($detail['sources'] as $s): ?>
            <tr>
                <td><?php echo $h($s['source_type']); ?></td>
                <td><?php echo $h($s['source_id']); ?></td>
                <td><?php echo $h($s['doc_number']); ?></td>
                <td><?php echo $h($s['txn_date']); ?></td>
                <td><?php echo $h($s['branch']); ?></td>
                <td><?php echo $h($s['branch_label']); ?></td>
                <td><?php echo $money($s['amount']); ?></td>
                <td><?php echo $h($s['payment_method']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="ps-section">
    <h2>Journal entries</h2>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr>
                <th>#</th><th>Date</th><th>Source</th><th>Doc</th>
                <th>Debit</th><th>Credit</th><th>Amount</th><th>Memo</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($detail['entries'] as $e): ?>
            <tr>
                <td><?php echo (int)$e['entry_seq']; ?></td>
                <td><?php echo $h($e['txn_date']); ?></td>
                <td><?php echo $h($e['source_type'] . ' #' . $e['source_id']); ?></td>
                <td><?php echo $h($e['doc_number']); ?></td>
                <td><?php echo $h($e['debit_account_code']); ?></td>
                <td><?php echo $h($e['credit_account_code']); ?></td>
                <td class="num"><?php echo $E($e); ?></td>
                <td><?php echo $h($e['memo']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php else: ?>
<section class="ps-section">
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr>
                <th>Batch</th><th>Status</th><th>External</th><th>Dates</th><th>Branch</th>
                <th>Debit</th><th>Credit</th><th>Sources</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($list === []): ?>
            <tr><td colspan="9">No batches.</td></tr>
        <?php else: ?>
            <?php foreach ($list as $b): ?>
            <tr>
                <td><?php echo $h($b['batch_number']); ?></td>
                <td><?php echo $h($b['status']); ?></td>
                <td><?php echo $h($b['external_status']); ?></td>
                <td><?php echo $h($b['from_date'] . ' → ' . $b['to_date']); ?></td>
                <td><?php echo (int)$b['all_branches'] === 1 ? 'All authorised' : $h($b['branch_filter']); ?></td>
                <td><?php echo $money($b['total_debit']); ?></td>
                <td><?php echo $money($b['total_credit']); ?></td>
                <td><?php echo (int)$b['source_count']; ?></td>
                <td><a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting_batches.php?id=<?php echo (int)$b['id']; ?>">View</a></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="ps-muted">CSV columns: batch_number, batch_status, external_status, entry_seq, source_type, source_id, doc_number, txn_date, branch, debit/credit account code+name, amount, currency, payment_method, mapping keys, memo. Formula-injection escaped via psReportingCsvCell.</p>
</section>
<?php endif; ?>
<?php if (!empty($detail['entries'])) { echo psFxrSummary($conn, $fx, $fxMixOf($detail['entries']), 'Batch journals by source currency'); } ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
