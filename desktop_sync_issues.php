<?php
/**
 * Phase 21 — Sync Differences: what desktop tills sold differently from the server's data.
 * Offline sales are accepted as recorded; this page is where a manager reviews and ticks them off.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/desktop_sync.php';
require_once __DIR__ . '/includes/platform_page.php';

psBranchRequireAdminRedirect('ajax_pos.php');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psDtEnsureSchema($conn);
$company = trim((string)($_SESSION['the_company'] ?? ''));
$actor = trim((string)($_SESSION['logger'] ?? ''));
$kinds = psDtIssueKinds();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } else {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn ($i) => $i > 0));
        if ($ids) {
            $in = implode(',', $ids);
            $stmt = mysqli_prepare($conn, "UPDATE desktop_sync_issues SET resolved_at = '" . date('Y-m-d H:i:s') . "', resolved_by = ? WHERE company_name = ? AND resolved_at IS NULL AND id IN ($in)");
            mysqli_stmt_bind_param($stmt, 'ss', $actor, $company);
            mysqli_stmt_execute($stmt);
            $n = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            psSecurityAudit($conn, 'DESKTOP_ISSUES_REVIEWED', ['summary' => $n . ' sync difference(s) marked reviewed', 'target_type' => 'desktop_sync_issue']);
            psPlatformFlash('ok', $n . ' marked as reviewed.');
        }
        header('Location: desktop_sync_issues.php?' . http_build_query(array_intersect_key($_GET, ['till' => 1, 'kind' => 1, 'show' => 1])));
        exit;
    }
}

$tillF = (int)($_GET['till'] ?? 0);
$kindF = isset($kinds[$_GET['kind'] ?? '']) ? (string)$_GET['kind'] : '';
$show = ($_GET['show'] ?? 'open') === 'all' ? 'all' : 'open';
$where = ['i.company_name = ?'];
$types = 's';
$args = [$company];
if ($tillF > 0) { $where[] = 'i.till_id = ?'; $types .= 'i'; $args[] = $tillF; }
if ($kindF !== '') { $where[] = 'i.kind = ?'; $types .= 's'; $args[] = $kindF; }
if ($show === 'open') { $where[] = 'i.resolved_at IS NULL'; }
$stmt = mysqli_prepare($conn, 'SELECT i.*, t.till_code, t.name AS till_name FROM desktop_sync_issues i LEFT JOIN desktop_tills t ON t.id = i.till_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY i.id DESC LIMIT 500');
mysqli_stmt_bind_param($stmt, $types, ...$args);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
$tills = psDtTillList($conn, $company);
mysqli_close($conn);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Differences</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app form.ds-filter.ds-filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        html[data-theme] body.ps-app .ds-table td { vertical-align: top; }
        html[data-theme] body.ps-app .ds-mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Sync Differences'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Sync Differences</h1>
        <p class="ps-page-lead">Sales from <a href="desktop_tills.php">desktop tills</a> are kept as the till recorded them. These are the places where that differed from the server — check them, correct prices or stock if needed, then mark them reviewed.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?><div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="ps-card"><div class="ps-card-content">
        <form method="get" action="desktop_sync_issues.php" class="ds-filter">
            <label class="ps-field"><span class="ps-label">Till</span><select class="ps-input" name="till"><option value="0">All tills</option>
                <?php foreach ($tills as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo $tillF === (int)$t['id'] ? 'selected' : ''; ?>><?php echo $h($t['till_code'] . ' · ' . $t['name'] . ' · ' . $t['branch']); ?></option><?php endforeach; ?></select></label>
            <label class="ps-field"><span class="ps-label">Kind</span><select class="ps-input" name="kind"><option value="">All kinds</option>
                <?php foreach ($kinds as $k => $l): ?><option value="<?php echo $h($k); ?>" <?php echo $kindF === $k ? 'selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?></select></label>
            <label class="ps-field"><span class="ps-label">Show</span><select class="ps-input" name="show"><option value="open">To review</option><option value="all" <?php echo $show === 'all' ? 'selected' : ''; ?>>All</option></select></label>
            <button class="ps-btn ps-btn-outline" type="submit">Filter</button>
        </form>
    </div></div>

    <form method="post" action="desktop_sync_issues.php?<?php echo $h(http_build_query(['till' => $tillF, 'kind' => $kindF, 'show' => $show])); ?>">
        <?php echo psCsrfField(); ?>
        <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table ds-table">
            <thead><tr><th><input type="checkbox" aria-label="Select all" onclick="document.querySelectorAll('.ds-pick').forEach(c => c.checked = this.checked)"></th>
                <th>When</th><th>Till</th><th>Kind</th><th>Sale</th><th>Detail</th><th>Till</th><th>Server</th><th>Reviewed</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="9" class="ps-muted">Nothing to review.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $kind = (string)$r['kind']; ?>
                <tr>
                    <td><?php if ($r['resolved_at'] === null): ?><input class="ds-pick" type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>" aria-label="Select"><?php endif; ?></td>
                    <td><?php echo $h(date('d M H:i', strtotime((string)($r['occurred_at'] ?: $r['created_at'])))); ?></td>
                    <td class="ds-mono"><?php echo $h($r['till_code'] ?? ('#' . $r['till_id'])); ?></td>
                    <td><span class="ps-badge <?php echo in_array($kind, ['REJECTED', 'NEGATIVE_STOCK', 'CASH_DIFF'], true) ? 'ps-badge-destructive' : 'ps-badge-secondary'; ?>"><?php echo $h($kinds[$kind] ?? $kind); ?></span></td>
                    <td class="ds-mono"><?php echo $h($r['till_ref'] ?? ''); ?><?php if ($r['invoice_number']): ?><div class="ps-muted">Inv <?php echo $h($r['invoice_number']); ?></div><?php endif; ?></td>
                    <td><?php echo $h($r['detail']); ?><?php if ($r['product_id']): ?><div class="ps-muted">Product ID <?php echo $h($r['product_id']); ?></div><?php endif; ?></td>
                    <td class="ds-mono"><?php echo $h($r['till_value'] ?? ''); ?></td>
                    <td class="ds-mono"><?php echo $h($r['server_value'] ?? ''); ?></td>
                    <td><?php echo $r['resolved_at'] ? $h(date('d M', strtotime((string)$r['resolved_at'])) . ' · ' . $r['resolved_by']) : ''; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div></div>
        <?php if ($rows && $show === 'open'): ?><div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Mark selected as reviewed</button></div><?php endif; ?>
    </form>
</div>
<?php ps_close(); ?>
</body>
</html>
