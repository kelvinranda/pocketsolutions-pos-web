<?php
/**
 * Phase 12 — Tax configuration audit trail (admin).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psTaxRequireAuthRedirect();
psTaxRequireConfigureRedirect('tax_rates.php');
psEnsureTaxModule($conn);
$rows = psTaxListAudit($conn, 200);
mysqli_close($conn);

$h = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Audit</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Tax Audit'); ?>
<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="tax_rates.php">← Tax Rates</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="tax_reports.php">Tax Reports</a>
</div>
<h1>Tax configuration audit</h1>
<p class="ps-page-lead">Recent tax rate and enforcement configuration changes.</p>

<section class="ps-section">
    <div class="ps-table-wrap">
    <table class="ps-table">
        <thead>
            <tr><th>When</th><th>Actor</th><th>Action</th><th>Code</th><th>Rate ID</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6">No audit rows yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $h($r['created_at']); ?></td>
                <td><?php echo $h($r['actor']); ?></td>
                <td><?php echo $h($r['action']); ?></td>
                <td><?php echo $h($r['code']); ?></td>
                <td><?php echo $h($r['tax_rate_id'] ?? ''); ?></td>
                <td><pre><?php echo $h($r['details'] ?? ''); ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php if (function_exists('ps_close')) { ps_close(); } ?>
</body>
</html>
