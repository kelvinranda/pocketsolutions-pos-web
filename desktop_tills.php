<?php
/**
 * Phase 21 — Desktop Tills: add a Windows till for a branch, give it a one-time pairing code,
 * see when it last synced, make a new code (new PC) or revoke it.
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
if (!psDtEnsureSchema($conn)) {
    die('The desktop till tables could not be created. Run the Phase 21 SQL first.');
}
$company = trim((string)($_SESSION['the_company'] ?? ''));
$actor = trim((string)($_SESSION['logger'] ?? ''));
$branches = psBranchRegisteredNames($conn);
$errors = [];
$shownCode = $_SESSION['ps_dt_code'] ?? null; // shown once after create / new code
unset($_SESSION['ps_dt_code']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($company === '') {
        $errors[] = 'Choose a company first.';
    } elseif ($action === 'create') {
        $branch = trim((string)($_POST['branch'] ?? ''));
        if (!in_array($branch, $branches, true)) {
            $errors[] = 'Choose one of this company\'s branches.';
        } else {
            $r = psDtTillCreate($conn, $company, $branch, (string)($_POST['name'] ?? ''), $actor);
            if ($r['ok']) {
                psSecurityAudit($conn, 'DESKTOP_TILL_CREATE', ['summary' => 'Desktop till ' . $r['code'] . ' added for ' . $branch, 'target_type' => 'desktop_till', 'target_id' => (string)$r['id']]);
                $_SESSION['ps_dt_code'] = ['code' => $r['pairing'], 'till' => $r['code'] . ' · ' . trim((string)$_POST['name']) . ' · ' . $branch];
                header('Location: desktop_tills.php');
                exit;
            }
            $errors[] = $r['error'];
        }
    } elseif ($action === 'pair') {
        $r = psDtTillNewPairing($conn, $id, $company);
        if ($r['ok']) {
            psSecurityAudit($conn, 'DESKTOP_TILL_NEW_CODE', ['summary' => 'New pairing code for desktop till ' . $r['till']['till_code'], 'target_type' => 'desktop_till', 'target_id' => (string)$id]);
            $_SESSION['ps_dt_code'] = ['code' => $r['pairing'], 'till' => $r['till']['till_code'] . ' · ' . $r['till']['name'] . ' · ' . $r['till']['branch']];
            header('Location: desktop_tills.php');
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'revoke' || $action === 'restore') {
        $till = psDtTillById($conn, $id, $company);
        if ($till && psDtTillSetStatus($conn, $id, $company, $action === 'restore')) {
            psSecurityAudit($conn, $action === 'revoke' ? 'DESKTOP_TILL_REVOKE' : 'DESKTOP_TILL_RESTORE', ['summary' => 'Desktop till ' . $till['till_code'] . ($action === 'revoke' ? ' revoked' : ' restored'), 'target_type' => 'desktop_till', 'target_id' => (string)$id]);
            psPlatformFlash('ok', $action === 'revoke'
                ? 'Till ' . $till['till_code'] . ' revoked. It can no longer sync. Sales still waiting on that PC stay there until it is paired again.'
                : 'Till ' . $till['till_code'] . ' restored. Make a pairing code to connect a PC.');
            header('Location: desktop_tills.php');
            exit;
        }
        $errors[] = 'That till was not found.';
    }
}

$tills = $company !== '' ? psDtTillList($conn, $company) : [];
mysqli_close($conn);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$ago = static function ($at): string {
    if (!$at) {
        return 'Never';
    }
    $s = time() - strtotime((string)$at);
    if ($s < 120) {
        return 'Just now';
    }
    if ($s < 7200) {
        return (int)($s / 60) . ' min ago';
    }
    if ($s < 172800) {
        return (int)($s / 3600) . ' h ago';
    }
    return date('d M Y H:i', strtotime((string)$at));
};
$apiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desktop Tills</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app .dt-code { font: 700 32px/1.2 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .12em; margin: 6px 0; }
        html[data-theme] body.ps-app .dt-mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
        html[data-theme] body.ps-app form.dt-add.dt-add { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 2fr) auto; gap: 12px; align-items: end; }
        html[data-theme] body.ps-app .dt-table td { vertical-align: middle; }
        @media (max-width: 800px) { html[data-theme] body.ps-app form.dt-add.dt-add { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Desktop Tills'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Desktop Tills</h1>
        <p class="ps-page-lead">Windows tills that keep selling with no internet and send their sales here when the connection returns. Each till belongs to one branch and numbers its own receipts (T01-000001 …); the server also gives every synced sale a normal invoice number.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($shownCode): ?>
        <div class="ps-card" role="status">
            <div class="ps-card-header"><h2 class="ps-card-title">Pairing code for <?php echo $h($shownCode['till']); ?></h2>
                <p class="ps-card-desc">On the till PC open Pockets POS, enter the server address and this code. It works once and expires in <?php echo PS_DT_PAIRING_HOURS; ?> hours. It will not be shown again.</p></div>
            <div class="ps-card-content">
                <div class="dt-code"><?php echo $h($shownCode['code']); ?></div>
                <p class="ps-muted">Server address: <span class="dt-mono"><?php echo $h($apiUrl); ?></span></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Add a till</h2></div>
        <div class="ps-card-content">
            <form method="post" action="desktop_tills.php" class="dt-add">
                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="create">
                <label class="ps-field"><span class="ps-label">Branch</span>
                    <select class="ps-input" name="branch" required>
                        <option value="">Choose…</option>
                        <?php foreach ($branches as $b): ?><option value="<?php echo $h($b); ?>"><?php echo $h($b); ?></option><?php endforeach; ?>
                    </select></label>
                <label class="ps-field"><span class="ps-label">Name</span><input class="ps-input" name="name" required maxlength="100" placeholder="e.g. Front counter"></label>
                <button class="ps-btn ps-btn-default" type="submit">Add till</button>
            </form>
        </div>
    </div>

    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table dt-table">
        <thead><tr><th>Till</th><th>Branch</th><th>PC</th><th>Last seen</th><th>Last sale synced</th><th>Sales synced</th><th>Differences</th><th></th></tr></thead>
        <tbody>
        <?php if (!$tills): ?><tr><td colspan="8" class="ps-muted">No desktop tills yet.</td></tr><?php endif; ?>
        <?php foreach ($tills as $t): $active = $t['status'] === 'ACTIVE'; ?>
            <tr>
                <td><strong class="dt-mono"><?php echo $h($t['till_code']); ?></strong> <?php echo $h($t['name']); ?>
                    <?php if (!$active): ?> <span class="ps-badge ps-badge-secondary">Revoked</span>
                    <?php elseif (empty($t['token_hash'])): ?> <span class="ps-badge ps-badge-outline">Not paired</span><?php endif; ?></td>
                <td><?php echo $h($t['branch']); ?></td>
                <td><?php echo $h($t['machine_name'] ?: '—'); ?><?php if ($t['app_version']): ?><div class="ps-muted">v<?php echo $h($t['app_version']); ?></div><?php endif; ?></td>
                <td><?php echo $h($ago($t['last_seen_at'])); ?></td>
                <td><?php echo $h($ago($t['last_push_at'])); ?></td>
                <td><?php echo (int)$t['sales_synced']; ?></td>
                <td><?php if ((int)$t['open_issues'] > 0): ?><a href="desktop_sync_issues.php?till=<?php echo (int)$t['id']; ?>"><span class="ps-badge ps-badge-destructive"><?php echo (int)$t['open_issues']; ?> to review</span></a><?php else: ?><span class="ps-muted">None</span><?php endif; ?></td>
                <td><div class="pf-row-actions">
                    <?php if ($active): ?>
                        <form method="post" action="desktop_tills.php"><?php echo psCsrfField(); ?><input type="hidden" name="action" value="pair"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"><?php echo empty($t['token_hash']) ? 'Pairing code' : 'Move to new PC'; ?></button></form>
                        <form method="post" action="desktop_tills.php" onsubmit="return confirm('Revoke this till? It will stop syncing until paired again.');"><?php echo psCsrfField(); ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button class="ps-btn ps-btn-ghost ps-btn-sm" type="submit">Revoke</button></form>
                    <?php else: ?>
                        <form method="post" action="desktop_tills.php"><?php echo psCsrfField(); ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Restore</button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
    <p class="ps-muted">Offline sales are kept exactly as the till recorded them (price, tax, payment). Anything that differs from the server — an old price, stock going below zero, an inactive product — is listed on <a href="desktop_sync_issues.php">Sync Differences</a>.</p>
</div>
<?php ps_close(); ?>
</body>
</html>
