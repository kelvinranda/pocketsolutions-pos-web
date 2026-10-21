<?php
/**
 * Phase 11 — Accounting account mappings (admin).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/accounting.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psAccountingRequireAuthRedirect();
psAccountingRequireConfigureRedirect('accounting.php');
psEnsureAccountingModule($conn);

$message = '';
$error = '';
$user = trim((string)($_SESSION['logger'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $codes = $_POST['account_code'] ?? [];
        $names = $_POST['account_name'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }
        if (!is_array($names)) {
            $names = [];
        }
        $res = psAccountingSaveMappings($conn, $codes, $names, $user);
        if (!empty($res['ok'])) {
            $message = 'Mappings saved. Empty codes still block export for those keys.';
            psSecurityAudit($conn, 'ACCOUNTING_MAPPINGS_SAVE', [
                'summary' => 'Accounting mappings saved',
                'target_type' => 'accounting_mappings',
            ]);
        } else {
            $error = (string)($res['error'] ?? 'Save failed.');
        }
    }
}

$maps = psAccountingLoadMappings($conn);
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
    <title>Accounting Mappings</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Accounting Mappings'); ?>
<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting.php">← Accounting</a>
</div>
<h1>Account mappings</h1>
<p class="ps-page-lead">Enter your chart-of-accounts codes. Codes are not invented by the POS. Required keys with blank codes prevent export of dependent sources.</p>

<?php if ($message !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status"><?php echo $h($message); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Mapping keys</h2>
        <p class="ps-card-desc">Account code and name for each debit/credit mapping key.</p>
    </div>
    <div class="ps-card-content">
        <form method="post" action="accounting_mappings.php" class="ps-form-stack">
            <?php echo psCsrfField(); ?>
            <div class="ps-table-wrap">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>Mapping key</th>
                        <th>Label</th>
                        <th>Account code</th>
                        <th>Account name</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($maps as $m): ?>
                    <tr>
                        <td><code><?php echo $h($m['mapping_key']); ?></code></td>
                        <td><?php echo $h($m['label']); ?></td>
                        <td>
                            <input type="text" name="account_code[<?php echo $h($m['mapping_key']); ?>]"
                                   value="<?php echo $h($m['account_code']); ?>" maxlength="64" autocomplete="off">
                        </td>
                        <td>
                            <input type="text" name="account_name[<?php echo $h($m['mapping_key']); ?>]"
                                   value="<?php echo $h($m['account_name']); ?>" maxlength="250" autocomplete="off">
                        </td>
                        <td class="ps-muted"><?php echo $h($m['description']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="ps-form-actions">
                <button class="ps-btn ps-btn-default" type="submit">Save mappings</button>
            </div>
        </form>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
