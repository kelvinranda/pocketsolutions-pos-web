<?php
/**
 * Phase 18 — Everything Super Admins did on the platform (newest first).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';

$conn = psPlatformPageBoot();
$company = trim((string)($_GET['company'] ?? ''));
$rows = psPlatformAuditRows($conn, $company !== '' ? $company : null, 300);
$companies = [];
$res = mysqli_query($conn, 'SELECT company_name, display_name FROM companies ORDER BY display_name');
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $companies[] = $r;
}
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Activity</title>
    <?php ps_head(); psPlatformPageCss(); ?>
</head>
<body class="ps-app">
<?php ps_open('Platform Activity'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Platform Activity</h1>
        <p class="ps-page-lead">Companies added or changed, admins reset, and every time a Super Admin went into a company (with the reason). Changes made inside a company are also in that company's own Security Audit, marked "by <?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?>".</p>
    </div>
    <form method="get" class="ps-form-actions">
        <label class="ps-label">Company <select class="ps-input" name="company"><option value="">All</option>
            <?php foreach ($companies as $c): ?><option value="<?php echo $h($c['company_name']); ?>" <?php echo $company === $c['company_name'] ? 'selected' : ''; ?>><?php echo $h($c['display_name'] ?: $c['company_name']); ?></option><?php endforeach; ?>
        </select></label>
        <button class="ps-btn ps-btn-outline" type="submit">Filter</button>
    </form>
    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>When</th><th>Who</th><th>Company</th><th>What</th><th>Reason / detail</th><th>IP</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="ps-muted">Nothing recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $detail = json_decode((string)$r['detail_json'], true);
            $extra = is_array($detail) && isset($detail['login']) ? 'User: ' . $detail['login'] : (is_array($detail) && isset($detail['minutes']) ? $detail['minutes'] . ' min' : ''); ?>
            <tr><td class="pf-nowrap" style="white-space:nowrap"><?php echo $h(date('d M Y H:i', strtotime((string)$r['occurred_at']))); ?></td>
                <td><?php echo $h($r['actor']); ?></td>
                <td><?php echo $h((string)$r['company_name'] !== '' ? $r['company_name'] : '—'); ?></td>
                <td><?php echo $h(psPlatformActionLabel((string)$r['action_code'])); ?></td>
                <td><?php echo $h(trim((string)$r['reason'] . ($extra !== '' ? ' · ' . $extra : ''), ' ·')); ?></td>
                <td class="pf-meta"><?php echo $h($r['ip_address']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
</div>
<?php ps_close(); ?>
</body>
</html>
