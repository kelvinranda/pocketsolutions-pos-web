<?php
/**
 * System Reset (formerly "Settings", which was a duplicate Import Products screen).
 *
 * Health-check 2026-10-13:
 *  - Product import now lives only on import_items.php (link below).
 *  - Admin-only page; the new-installation reset requires the confirmation phrase AND
 *    takes a full database backup first. If the backup fails, nothing is truncated.
 */
require 'session_check.php';
require_once 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/backup.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAdminRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

$flashOk = '';
$flashErr = '';

function psSettingsTruncateTables(mysqli $conn): bool
{
    $tables = ['goods', 'goods_receipts', 'goods_receipts_ref', 'online_sales', 'online_sales_ref'];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
            return false;
        }
        if (!$conn->query('TRUNCATE TABLE `' . $table . '`')) {
            error_log('settings: truncate failed for table');
            return false;
        }
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_install'])) {
    if (!psCsrfValidate()) {
        $flashErr = 'Invalid security token. Reload and try again.';
    } else {
        $confirm = trim((string)($_POST['confirm_phrase'] ?? ''));
        if ($confirm !== 'RESET PRODUCTS AND SALES') {
            $flashErr = 'Confirmation phrase did not match. No tables were changed.';
        } else {
            $backup = psBackupCreateDatabase($conn, $servername, $username, $password, $dbname, [
                'triggered_by' => (string)($_SESSION['logger'] ?? ''),
                'source' => 'pre_reset',
            ]);
            if (empty($backup['ok'])) {
                $flashErr = 'The safety backup could not be created, so nothing was reset. Check Backup & Recovery.';
                psSecurityAudit($conn, 'DESTRUCTIVE_RESET_BLOCKED', [
                    'summary' => 'Reset refused: pre-reset backup failed',
                    'target_type' => 'settings',
                ]);
            } elseif (!psSettingsTruncateTables($conn)) {
                $flashErr = 'Reset failed part-way. Restore from backup job #' . (int)$backup['job_id'] . ' if needed.';
            } else {
                psSecurityAudit($conn, 'DESTRUCTIVE_RESET', [
                    'summary' => 'Truncated goods/receipts/sales tables (pre-reset backup job #' . (int)$backup['job_id'] . ')',
                    'target_type' => 'settings',
                ]);
                $flashOk = 'Products, goods receipts and sales were cleared. A backup was taken first (job #' . (int)$backup['job_id'] . ').';
            }
        }
    }
}
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Reset</title>
    <?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
    <script>
        function confirmNewInstallation() {
            return confirm("This permanently deletes all products, goods receipts and sales. A backup is taken first. Continue?");
        }
    </script>
</head>
<body class="ps-app">
<?php ps_open('System Reset'); ?>
<h1>System Reset</h1>
<p class="ps-page-lead">For a brand-new installation only. To add or update products use <a href="import_items.php">Import Items</a>.</p>

<?php if ($flashOk !== ''): ?><div class="ps-alert ps-alert-success"><?php echo ps_h($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="ps-alert ps-alert-destructive"><?php echo ps_h($flashErr); ?></div><?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header">
        <div class="ps-card-title">New installation reset</div>
        <div class="ps-card-desc">Deletes every product, goods receipt and sale on all branches. Never use this on a live shop: sales records must be kept for tax purposes.</div>
    </div>
    <div class="ps-card-content">
        <form method="post" class="ps-form-stack" onsubmit="return confirmNewInstallation();">
            <?php echo psCsrfField(); ?>
            <label class="ps-label" for="confirm_phrase">Type <code>RESET PRODUCTS AND SALES</code> to confirm</label>
            <input class="ps-input" type="text" id="confirm_phrase" name="confirm_phrase" autocomplete="off" placeholder="RESET PRODUCTS AND SALES">
            <div class="ps-form-actions">
                <button type="submit" name="new_install" value="1" class="ps-btn ps-btn-destructive">Back up, then reset</button>
            </div>
        </form>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
