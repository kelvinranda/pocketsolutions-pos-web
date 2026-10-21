<?php
/**
 * Phase 11 — Accounting preview + export (same ACL/filters).
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
psReportingEnsureTimezone();
psEnsureAccountingModule($conn);

$isAdmin = psBranchSessionIsAdmin();
$user = trim((string)($_SESSION['logger'] ?? ''));
$message = '';
$error = '';

$fromDate = trim((string)($_REQUEST['from_date'] ?? ''));
$toDate = trim((string)($_REQUEST['to_date'] ?? ''));
if ($fromDate === '' && $toDate === '') {
    $bounds = psReportingPeriodBounds('month');
    $fromDate = $bounds['from_date'];
    $toDate = $bounds['to_date'];
}
$branchFilter = trim((string)($_REQUEST['branch'] ?? ''));
$sourceTypes = $_REQUEST['source_types'] ?? psAccountingDefaultSourceTypes();
if (!is_array($sourceTypes)) {
    $sourceTypes = psAccountingNormalizeSourceTypes((string)$sourceTypes);
}

$raw = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch' => $isAdmin ? $branchFilter : null,
    'source_types' => $sourceTypes,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $exp = psAccountingCreateExportBatch($conn, $raw, $user);
        if (!empty($exp['ok'])) {
            psSecurityAudit($conn, 'ACCOUNTING_EXPORT', [
                'summary' => 'Accounting export batch created',
                'target_type' => 'accounting_batch',
                'target_id' => (string)(int)($exp['batch_id'] ?? 0),
            ]);
            mysqli_close($conn);
            header('Location: accounting_batches.php?id=' . (int)$exp['batch_id'] . '&exported=1');
            exit;
        }
        $error = (string)($exp['error'] ?? 'Export failed.');
    }
}

$preview = psAccountingBuildPreview($conn, $raw);
if (empty($preview['ok'])) {
    $error = $error !== '' ? $error : (string)($preview['error'] ?? 'Preview failed.');
    $preview = null;
}
$branchOptions = $isAdmin ? psBranchAuthorisedRegisteredNames($conn) : [];
mysqli_close($conn);

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$money = static function ($v): string {
    return number_format((float)$v, 2, '.', ',');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Preview</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Accounting Preview'); ?>
<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting.php">← Accounting</a>
</div>
<h1>Preview &amp; export</h1>
<p class="ps-page-lead">Preview is read-only. Export writes accounting batch tables only — it does not change sales, payments, cash, refunds, purchases, stock, or invoice sequences. Status: <strong>PREVIEW</strong> until you export; exported batches remain <code>external_status=NOT_SENT</code>.</p>

<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>
<?php if ($message !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status"><?php echo $h($message); ?></div>
<?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Date range, branch, and source types for the journal preview.</p>
    </div>
    <div class="ps-card-content">
        <form method="get" action="accounting_preview.php" class="filter-form">
            <label>From <input type="date" name="from_date" value="<?php echo $h($fromDate); ?>"></label>
            <label>To <input type="date" name="to_date" value="<?php echo $h($toDate); ?>"></label>
            <?php if ($isAdmin): ?>
                <label>Branch
                    <select name="branch">
                        <option value="">All authorised</option>
                        <?php foreach ($branchOptions as $bn): ?>
                            <option value="<?php echo $h($bn); ?>"<?php echo $branchFilter === $bn ? ' selected' : ''; ?>><?php echo $h($bn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <div class="ps-muted">
                <?php foreach (psAccountingDefaultSourceTypes() as $st): ?>
                    <label style="display:inline-block;margin-right:10px;">
                        <input type="checkbox" name="source_types[]" value="<?php echo $h($st); ?>"
                            <?php echo in_array($st, $sourceTypes, true) ? ' checked' : ''; ?>>
                        <?php echo $h($st); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button class="ps-btn ps-btn-default" type="submit">Refresh preview</button>
        </form>
    </div>
</div>

<?php if ($preview): ?>
<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Preview totals</h2>
        <p class="ps-card-desc"><?php echo $h($preview['status_note'] ?? ''); ?></p>
    </div>
    <div class="ps-card-content">
        <p class="ps-muted">Eligible sources: <?php echo (int)$preview['totals']['eligible_count']; ?></p>
        <p class="ps-muted">Journal pairs: <?php echo (int)$preview['totals']['entry_count']; ?></p>
        <p class="ps-muted">Debit total: <?php echo $money($preview['totals']['debit']); ?>
            / Credit total: <?php echo $money($preview['totals']['credit']); ?>
            — <?php echo !empty($preview['balanced']) ? 'BALANCED' : 'NOT BALANCED'; ?></p>
        <p class="ps-muted">Blocked: <?php echo (int)$preview['totals']['blocked_count']; ?>
            · Exceptions / legacy: <?php echo (int)$preview['totals']['exception_count']; ?></p>
        <?php if (!empty($preview['missing_mappings'])): ?>
            <div class="ps-alert ps-alert-destructive" role="alert">Missing mappings: <?php echo $h(implode(', ', $preview['missing_mappings'])); ?></div>
        <?php endif; ?>
        <?php if (!empty($preview['can_export'])): ?>
        <form method="post" action="accounting_preview.php" class="ps-form-stack ps-split-gap">
            <input type="hidden" name="from_date" value="<?php echo $h($fromDate); ?>">
            <input type="hidden" name="to_date" value="<?php echo $h($toDate); ?>">
            <input type="hidden" name="branch" value="<?php echo $h($branchFilter); ?>">
            <?php foreach ($sourceTypes as $st): ?>
                <input type="hidden" name="source_types[]" value="<?php echo $h($st); ?>">
            <?php endforeach; ?>
            <?php echo psCsrfField(); ?>
            <div class="ps-form-actions">
                <button class="ps-btn ps-btn-default" type="submit" name="do_export" value="1">Create export batch (CSV-ready)</button>
            </div>
        </form>
        <?php else: ?>
            <p class="ps-muted">Export disabled until mappings are complete and at least one balanced eligible source exists.</p>
        <?php endif; ?>
    </div>
</div>

<section class="ps-section">
    <h2>Eligible journal preview</h2>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr>
                <th>Date</th><th>Source</th><th>Doc</th><th>Branch</th>
                <th>Debit a/c</th><th>Credit a/c</th><th>Amount</th><th>Method</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($preview['entries'])): ?>
            <tr><td colspan="8">No eligible entries.</td></tr>
        <?php else: ?>
            <?php foreach ($preview['entries'] as $e): ?>
            <tr>
                <td><?php echo $h($e['txn_date']); ?></td>
                <td><?php echo $h($e['source_type'] . ' #' . $e['source_id']); ?></td>
                <td><?php echo $h($e['doc_number']); ?></td>
                <td><?php echo $h($e['branch'] !== '' ? $e['branch'] : '(unallocated)'); ?></td>
                <td><?php echo $h($e['debit_account_code'] . ' ' . $e['debit_account_name']); ?></td>
                <td><?php echo $h($e['credit_account_code'] . ' ' . $e['credit_account_name']); ?></td>
                <td><?php echo $money($e['amount']); ?></td>
                <td><?php echo $h($e['payment_method']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="ps-section">
    <h2>Blocked / incomplete sources</h2>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead><tr><th>Source</th><th>Doc</th><th>Amount</th><th>Exceptions</th><th>Missing maps</th></tr></thead>
        <tbody>
        <?php if (empty($preview['blocked_sources'])): ?>
            <tr><td colspan="5">None.</td></tr>
        <?php else: ?>
            <?php foreach ($preview['blocked_sources'] as $s): ?>
            <tr>
                <td><?php echo $h(($s['source_type'] ?? '') . ' #' . ($s['source_id'] ?? '')); ?></td>
                <td><?php echo $h($s['doc_number'] ?? ''); ?></td>
                <td><?php echo $money($s['amount'] ?? 0); ?></td>
                <td><?php echo $h(implode(', ', $s['exceptions'] ?? [])); ?></td>
                <td><?php echo $h(implode(', ', $s['missing_mappings'] ?? [])); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="ps-section">
    <h2>Exceptions / legacy limits</h2>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead><tr><th>Type</th><th>Doc</th><th>Flags</th><th>Note</th></tr></thead>
        <tbody>
        <?php if (empty($preview['exceptions'])): ?>
            <tr><td colspan="4">None in this window (or none within cap).</td></tr>
        <?php else: ?>
            <?php foreach (array_slice($preview['exceptions'], 0, 100) as $ex): ?>
            <tr>
                <td><?php echo $h($ex['source_type'] ?? ''); ?></td>
                <td><?php echo $h($ex['doc_number'] ?? ''); ?></td>
                <td><?php echo $h(implode(', ', $ex['exceptions'] ?? [])); ?></td>
                <td><?php echo $h($ex['note'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
