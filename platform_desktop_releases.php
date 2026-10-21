<?php
/**
 * Super Admin — Pockets POS for Windows releases (auto-update).
 * Upload the PocketsPOS-x.y.z.zip made by installer/build.ps1 in the desktop project. Every till that checks the
 * live server (Till Settings › Updates) is offered the newest active release above its own version, downloads it
 * with its till token and checks the SHA-256 before installing. Deactivate a release to stop offering it.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';
require_once __DIR__ . '/includes/desktop_updates.php';

$conn = psPlatformPageBoot();
psDuEnsureSchema($conn);
$errors = [];
$actor = trim((string)($_SESSION['logger'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $version = trim((string)($_POST['version'] ?? ''));
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($action === 'upload') {
        $r = psDuSave($conn, $_FILES['release'] ?? [], $version, (string)($_POST['notes'] ?? ''), $actor);
        if ($r['ok']) {
            psPlatformAudit($conn, 'DESKTOP_RELEASE_UPLOAD', null, '', ['version' => $r['version'], 'sha256' => $r['sha256']]);
            psPlatformFlash('ok', 'Release ' . $r['version'] . ' uploaded. Tills checking the live server will be offered it.');
            header('Location: platform_desktop_releases.php');
            exit;
        }
        $errors[] = $r['error'];
    } elseif (($action === 'activate' || $action === 'deactivate') && psDuValidVersion($version)) {
        psDuSetActive($conn, $version, $action === 'activate');
        psPlatformAudit($conn, $action === 'activate' ? 'DESKTOP_RELEASE_ON' : 'DESKTOP_RELEASE_OFF', null, '', ['version' => $version]);
        psPlatformFlash('ok', $action === 'activate' ? "Release $version is offered again." : "Release $version is no longer offered. Tills that already installed it keep it.");
        header('Location: platform_desktop_releases.php');
        exit;
    } elseif ($action === 'delete' && psDuValidVersion($version)) {
        psDuDelete($conn, $version);
        psPlatformAudit($conn, 'DESKTOP_RELEASE_DELETE', null, '', ['version' => $version]);
        psPlatformFlash('ok', "Release $version deleted.");
        header('Location: platform_desktop_releases.php');
        exit;
    }
}

$releases = psDuList($conn);
$versions = [];
$res = mysqli_query($conn, "SELECT COALESCE(NULLIF(app_version, ''), 'unknown') AS v, COUNT(*) AS n FROM desktop_tills WHERE status = 'ACTIVE' GROUP BY v");
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $versions[(string)$row['v']] = (int)$row['n'];
}
uksort($versions, static fn ($a, $b) => version_compare($b, $a));
$maxUpload = ini_get('upload_max_filesize') . ' / post ' . ini_get('post_max_size');
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$size = static function (int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desktop Releases</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app .dr-sha { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; }
        html[data-theme] body.ps-app .dr-off td { opacity: .6; }
        html[data-theme] body.ps-app .dr-notes { white-space: pre-line; max-width: 420px; }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Desktop Releases'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Desktop Releases</h1>
        <p class="ps-page-lead">New versions of Pockets POS for Windows. Tills that check the live server (Till Settings › Updates) are offered the newest active release above their own version; they download it with their till token, check its SHA-256 and install it when an admin presses Install — never during a sale.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Upload a release</h2>
            <p class="ps-card-description">The zip from <code>installer\build.ps1</code> (file <code>PocketsPOS-x.y.z.zip</code> in <code>installer\out</code>). Server limit: <?php echo $h($maxUpload); ?>.</p></div>
        <div class="ps-card-content">
            <form method="post" action="platform_desktop_releases.php" enctype="multipart/form-data" class="pf-form">
                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="upload">
                <div class="pf-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
                    <label class="ps-field"><span class="ps-label">Version</span><input class="ps-input" name="version" required maxlength="40" placeholder="e.g. 1.2.0" pattern="\d{1,4}\.\d{1,4}\.\d{1,6}(\.\d{1,6})?" value="<?php echo $h($_POST['version'] ?? ''); ?>"></label>
                    <label class="ps-field" style="grid-column:span 2"><span class="ps-label">Release zip</span><input class="ps-input" type="file" name="release" accept=".zip" required></label>
                </div>
                <label class="ps-field"><span class="ps-label">What's new (shown on the tills)</span><textarea class="ps-input" name="notes" rows="3" maxlength="4000"><?php echo $h($_POST['notes'] ?? ''); ?></textarea></label>
                <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Upload release</button></div>
            </form>
        </div>
    </div>

    <div class="ps-card"><div class="ps-card-header"><h2 class="ps-card-title">Releases</h2></div>
        <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>Version</th><th>Uploaded</th><th>Size</th><th>SHA-256</th><th>Downloads</th><th>What's new</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$releases): ?><tr><td colspan="8">No releases uploaded yet.</td></tr><?php endif; ?>
        <?php foreach ($releases as $r): $on = (int)$r['active'] === 1; ?>
            <tr class="<?php echo $on ? '' : 'dr-off'; ?>">
                <td><strong><?php echo $h($r['version']); ?></strong></td>
                <td><?php echo $h($r['uploaded_at']); ?><br><span class="ps-muted"><?php echo $h($r['uploaded_by']); ?></span></td>
                <td><?php echo $h($size((int)$r['size_bytes'])); ?></td>
                <td class="dr-sha" title="<?php echo $h($r['sha256']); ?>"><?php echo $h(substr((string)$r['sha256'], 0, 16)); ?>…</td>
                <td><?php echo (int)$r['downloads']; ?></td>
                <td class="dr-notes"><?php echo $h($r['notes']); ?></td>
                <td><span class="ps-badge"><?php echo $on ? 'Offered' : 'Not offered'; ?></span></td>
                <td><div class="pf-row-actions">
                    <form method="post" action="platform_desktop_releases.php"><?php echo psCsrfField(); ?>
                        <input type="hidden" name="version" value="<?php echo $h($r['version']); ?>"><input type="hidden" name="action" value="<?php echo $on ? 'deactivate' : 'activate'; ?>">
                        <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"><?php echo $on ? 'Stop offering' : 'Offer again'; ?></button></form>
                    <form method="post" action="platform_desktop_releases.php" onsubmit="return confirm('Delete release <?php echo $h($r['version']); ?>? Tills that installed it keep it.');"><?php echo psCsrfField(); ?>
                        <input type="hidden" name="version" value="<?php echo $h($r['version']); ?>"><input type="hidden" name="action" value="delete">
                        <button class="ps-btn ps-btn-ghost ps-btn-sm" type="submit">Delete</button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>

    <div class="ps-card"><div class="ps-card-header"><h2 class="ps-card-title">Tills by version</h2>
        <p class="ps-card-description">Active desktop tills across all companies, by the version they last reported.</p></div>
        <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>Version</th><th>Tills</th></tr></thead>
        <tbody>
        <?php if (!$versions): ?><tr><td colspan="2">No desktop tills yet.</td></tr><?php endif; ?>
        <?php foreach ($versions as $v => $n): ?><tr><td><?php echo $h($v); ?></td><td><?php echo (int)$n; ?></td></tr><?php endforeach; ?>
        </tbody>
    </table></div></div></div>
</div>
<?php ps_close(); ?>
</body>
</html>
