<?php
/**
 * Phase 18 — For a company's own admin: is Pocket Solutions allowed to administer this company,
 * and when did its staff go in (and why). Read-only.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/platform.php';
require_once __DIR__ . '/includes/ps_layout.php';

psBranchRequireAdminRedirect('ajax_pos.php');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
$companyKey = trim((string)($_SESSION['the_company'] ?? ''));
$c = psPlatformCompanyByName($conn, $companyKey);
$rows = psPlatformAuditRows($conn, $companyKey, 200);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$provider = PS_PLATFORM_PROVIDER_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Access</title>
    <?php ps_head(); echo psCsrfMeta(); ?>
</head>
<body class="ps-app">
<?php ps_open('Provider Access'); ?>
<h1>Provider Access</h1>
<p class="ps-page-lead">Whether <?php echo $h($provider); ?> may administer this company for you, and every time its staff did.</p>

<div class="ps-card">
    <div class="ps-card-content">
        <?php if ($c && (int)$c['provider_managed'] === 1): ?>
            <p style="margin:0"><span class="ps-badge ps-badge-default">On</span> <?php echo $h($provider); ?> may set up users, products, prices, tax and ZIMRA for you.
                They cannot make sales, refunds or till/cash entries. Agreement: <strong><?php echo $h((string)$c['managed_reference']); ?></strong>.</p>
            <p class="ps-muted" style="margin:8px 0 0">To stop this, contact <?php echo $h($provider); ?>.</p>
        <?php else: ?>
            <p style="margin:0"><span class="ps-badge ps-badge-secondary">Off</span> Your company runs its own administration. <?php echo $h($provider); ?> staff cannot work inside it
                (they can only reset an admin password if you ask them to).</p>
        <?php endif; ?>
    </div>
</div>

<div class="ps-card">
    <div class="ps-card-header"><h2 class="ps-card-title">Activity by <?php echo $h($provider); ?></h2></div>
    <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Reason</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="ps-muted">Nothing recorded.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr><td class="pf-nowrap" style="white-space:nowrap"><?php echo $h(date('d M Y H:i', strtotime((string)$r['occurred_at']))); ?></td>
                <td><?php echo $h($r['actor']); ?> <span class="ps-muted">(<?php echo $h($provider); ?>)</span></td>
                <td><?php echo $h(psPlatformActionLabel((string)$r['action_code'])); ?></td>
                <td><?php echo $h((string)$r['reason']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<p class="ps-muted">Changes they made while inside (users, products, settings) are also in the Security Audit, marked "by <?php echo $h($provider); ?>".</p>
<?php ps_close(); ?>
</body>
</html>
