<?php
/**
 * Phase 11 — POS vs exported reconciliation.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
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
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
if ($fromDate === '' && $toDate === '') {
    $bounds = psReportingPeriodBounds('month');
    $fromDate = $bounds['from_date'];
    $toDate = $bounds['to_date'];
}
$branchFilter = trim((string)($_GET['branch'] ?? ''));
$raw = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch' => $isAdmin ? $branchFilter : null,
];
$error = '';
$recon = psAccountingReconcile($conn, $raw);
if (empty($recon['ok'])) {
    $error = (string)($recon['error'] ?? 'Reconciliation failed.');
    $recon = null;
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
    <title>Accounting Reconciliation</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Accounting Reconciliation'); ?>
<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting.php">← Accounting</a>
</div>
<h1>Reconciliation</h1>
<p class="ps-page-lead">Compares buildable POS journal amounts (same rules as preview) with EXPORTED batch entry totals. Does not claim external GL acceptance.</p>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Filters</h2>
        <p class="ps-card-desc">Date range and branch for POS vs exported totals.</p>
    </div>
    <div class="ps-card-content">
        <form method="get" action="accounting_recon.php" class="filter-form">
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
            <button class="ps-btn ps-btn-default" type="submit">Run</button>
        </form>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>

<?php if ($recon): ?>
<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Reconciliation totals</h2>
        <p class="ps-card-desc">POS buildable amounts vs EXPORTED batch journal entries.</p>
    </div>
    <div class="ps-card-content">
        <p class="ps-muted">POS buildable entry total (incl. already exported buildable): <?php echo $money($recon['pos_buildable_entries_total']); ?></p>
        <p class="ps-muted">POS open eligible (not yet EXPORTED): <?php echo $money($recon['pos_open_eligible_total']); ?></p>
        <p class="ps-muted">Exported journal entry total (EXPORTED batches): <?php echo $money($recon['exported_entries_total']); ?></p>
        <p class="ps-muted">Exported source count: <?php echo (int)$recon['exported_source_count']; ?></p>
        <p class="ps-muted">Exception / legacy rows surfaced: <?php echo (int)$recon['exception_count']; ?></p>
    </div>
</div>

<section class="ps-section">
    <h2>Notes</h2>
    <ul class="ps-muted">
    <?php foreach ($recon['notes'] as $n): ?>
        <li><?php echo $h($n); ?></li>
    <?php endforeach; ?>
    </ul>
</section>

<section class="ps-section">
    <h2>Legacy / blocked samples</h2>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead><tr><th>Type</th><th>Doc</th><th>Flags</th><th>Note</th></tr></thead>
        <tbody>
        <?php
        $rows = array_slice($recon['legacy_and_blocked'] ?? [], 0, 80);
        if ($rows === []):
        ?>
            <tr><td colspan="4">None listed for this window.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $ex): ?>
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
