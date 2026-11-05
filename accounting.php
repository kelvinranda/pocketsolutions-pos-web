<?php
/**
 * Phase 11 — Accounting integration hub.
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
psEnsureAccountingModule($conn);

$isAdmin = psBranchSessionIsAdmin();
$batches = psAccountingListBatches($conn, 10);
$maps = psAccountingLoadMappings($conn);
$configured = 0;
$totalMaps = count($maps);
foreach ($maps as $m) {
    if ($m['account_code'] !== '') {
        $configured++;
    }
}
require_once __DIR__ . '/includes/currency.php';
$accBase = psFxBase($conn, psFxSessionCompany()); // journals are in the company base (each source at its stored rate)

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Export</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Accounting Integration'); ?>
<h1>Accounting Export</h1>
<p class="ps-page-lead">Accounting-ready journal export and reconciliation. External posting is <strong>not</strong> claimed — batches stay <code>external_status=NOT_SENT</code> until a real integration confirms.</p>

<div class="ps-alert <?php echo $configured < $totalMaps ? 'ps-alert-warn' : 'ps-alert-info'; ?>" role="status">
    Mappings configured: <?php echo (int)$configured; ?> / <?php echo (int)$totalMaps; ?>
    <?php if ($configured < $totalMaps): ?>
        — fill account codes before exporting eligible sources.
    <?php endif; ?>
</div>

<div class="ps-kpi-grid" aria-label="Accounting sections">
    <?php if ($isAdmin): ?>
    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title"><a href="accounting_mappings.php">Account mappings</a></h2>
            <p class="ps-card-desc">Configure debit/credit account codes</p>
        </div>
    </div>
    <?php endif; ?>
    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title"><a href="accounting_preview.php">Preview &amp; export</a></h2>
            <p class="ps-card-desc">Date/branch filtered eligible journals</p>
        </div>
    </div>
    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title"><a href="accounting_batches.php">Export batches</a></h2>
            <p class="ps-card-desc">History, drill-down, CSV</p>
        </div>
    </div>
    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title"><a href="accounting_recon.php">Reconciliation</a></h2>
            <p class="ps-card-desc">POS totals vs exported</p>
        </div>
    </div>
</div>

<section class="ps-section">
    <h2>Recent batches</h2>
    <?php if ($batches === []): ?>
    <p class="ps-muted">No export batches yet.</p>
    <?php else: ?>
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr>
                <th>Batch</th><th>Status</th><th>External</th><th>Dates</th><th>Branch</th><th>Debit</th><th>Credit</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr>
                <td><?php echo $h($b['batch_number']); ?></td>
                <td><?php echo $h($b['status']); ?></td>
                <td><?php echo $h($b['external_status']); ?></td>
                <td><?php echo $h($b['from_date'] . ' → ' . $b['to_date']); ?></td>
                <td><?php echo (int)$b['all_branches'] === 1 ? 'All authorised' : $h($b['branch_filter']); ?></td>
                <td class="num"><?php echo $h(psFxFormat($conn, $b['total_debit'], $accBase)); ?></td>
                <td class="num"><?php echo $h(psFxFormat($conn, $b['total_credit'], $accBase)); ?></td>
                <td><a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting_batches.php?id=<?php echo (int)$b['id']; ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="ps-section">
    <h2>Documented limitations</h2>
    <ul class="ps-muted">
    <?php foreach (psAccountingKnownLimitations() as $line): ?>
        <li><?php echo $h($line); ?></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
