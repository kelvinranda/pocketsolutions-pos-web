<?php
/**
 * 2026-11-02 — Role permissions (admin; page_access keeps non-admins out).
 * The company's admin ticks what Till, Supervisor and Management users may do. Admin always has everything.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/role_permissions.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psSecurityEnforceWebSession($conn, ['redirect' => true, 'touch' => true]);
psBranchRequireAdminRedirect('ajax_pos.php');

$error = '';
$notice = !empty($_GET['saved']) ? 'Role permissions saved. They apply from each person\'s next page; Windows tills after their next sync.' : '';
if (!empty($_GET['reset'])) {
    $notice = 'Role permissions are back to the defaults.';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } elseif (isset($_POST['reset_defaults'])) {
        $r = psRolePermReset($conn, (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: role_permissions.php?reset=1');
            exit;
        }
        $error = (string)$r['error'];
    } else {
        $r = psRolePermSave($conn, (array)($_POST['perm'] ?? []), (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: role_permissions.php?saved=1');
            exit;
        }
        $error = (string)$r['error'];
    }
}
$company = trim((string)($_SESSION['the_company'] ?? ''));
$matrix = psRolePermMatrix($conn, $company);
$defs = psRolePermDefinitions();
mysqli_close($conn);
$h = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Permissions</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
    <style>
        .rp-table th, .rp-table td { vertical-align: middle; }
        .rp-table td.rp-role, .rp-table th.rp-role { text-align: center; width: 8.5rem; }
        .rp-table input[type=checkbox] { width: 20px; height: 20px; cursor: pointer; }
        .rp-help { color: var(--ps-muted); font-size: 13px; margin-top: 2px; }
        .rp-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Role Permissions</h1>
    <p class="ps-page-lead">Choose what each role may do in <?php echo $h($company); ?>. Admins can always do everything.
        Everyone can sell at the till, see their own receipts, use their own till cash and ask for refunds.</p>
    <?php if ($error !== ''): ?><div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="ps-alert ps-alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

    <form method="post" action="role_permissions.php">
        <?php echo psCsrfField(); ?>
        <div class="ps-table-wrap">
            <table class="ps-table rp-table">
                <thead>
                    <tr>
                        <th>Permission</th>
                        <?php foreach (PS_ROLES as $role => $label): ?><th class="rp-role"><?php echo $h($label); ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($defs as $key => $d): ?>
                    <tr>
                        <td><strong><?php echo $h($d[0]); ?></strong><div class="rp-help"><?php echo $h($d[1]); ?></div></td>
                        <?php foreach (PS_ROLES as $role => $label): ?>
                            <td class="rp-role">
                                <input type="checkbox" name="perm[<?php echo $h($role); ?>][<?php echo $h($key); ?>]" value="1"
                                       aria-label="<?php echo $h($label . ': ' . $d[0]); ?>" <?php echo !empty($matrix[$role][$key]) ? 'checked' : ''; ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="rp-actions">
            <button type="submit" class="ps-btn ps-btn-default">Save permissions</button>
            <button type="submit" name="reset_defaults" value="1" class="ps-btn ps-btn-outline" formnovalidate
                    data-confirm-reset>Back to defaults</button>
        </div>
        <p class="ps-field-hint" style="margin-top:10px">Defaults: Supervisors add and edit items, change prices, receive goods and manage customers.
            Management can also manage stock and see cash and other reports. Nobody but the admin sees everyone's sales or uses the mobile app until you tick it.</p>
    </form>
<?php ps_close(); ?>
</body>
</html>
