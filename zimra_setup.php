<?php
/**
 * Phase 17 — ZIMRA device set-up (system administrator only).
 * One fiscal device per branch and environment. Register (key + CSR made on the server) or import an
 * existing key + certificate, sync GetConfig, map POS tax codes to ZIMRA taxes, enable fiscalisation.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/zimra.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

if (!psZimraMaySetup()) {
    http_response_code(403);
    die('ZIMRA device set-up is for administrators.');
}
psEnsureZimraModule($conn);
psEnsureTaxModule($conn);

$actor = psZimraSessionUser();
$message = '';
$error = '';
$selectedId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
// Phase 18: only this company's devices (branch names repeat across companies).
$otherCompanyNotice = '';
if ($selectedId > 0 && !psZimraDeviceInSessionCompany(psZimraDeviceGet($conn, $selectedId))) {
    $selectedId = 0;
    $otherCompanyNotice = 'That device is not one of ' . trim((string)($_SESSION['the_company'] ?? 'this company')) . '\'s devices. Pick a device from the list below.';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'create') {
        http_response_code(403);
        die('That device belongs to another company.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $res = ['ok' => false, 'error' => 'Unknown action.'];
        switch ($action) {
            case 'create':
                $res = psZimraDeviceCreate($conn, [
                    'branch' => $_POST['branch'] ?? '',
                    'company_name' => $_SESSION['the_company'] ?? '',
                    'environment' => $_POST['environment'] ?? 'test',
                    'device_id' => $_POST['device_id'] ?? 0,
                    'device_serial_no' => $_POST['device_serial_no'] ?? '',
                    'model_name' => $_POST['model_name'] ?? '',
                    'model_version' => $_POST['model_version'] ?? '',
                    'activation_key' => $_POST['activation_key'] ?? '',
                    'print_form' => $_POST['print_form'] ?? 'Receipt48',
                ], $actor);
                if ($res['ok']) {
                    $selectedId = (int)$res['id'];
                    $message = 'Device saved. Next: Register it with ZIMRA, or import an existing key and certificate.';
                }
                break;
            case 'activation_key':
                $res = psZimraDeviceSetActivationKey($conn, $selectedId, (string)($_POST['activation_key'] ?? ''), $actor);
                $message = $res['ok'] ? 'Activation key saved (encrypted).' : '';
                break;
            case 'register':
                // A key typed in the same form is saved first, so Register is one click.
                $typedKey = trim((string)($_POST['activation_key'] ?? ''));
                $res = $typedKey !== '' ? psZimraDeviceSetActivationKey($conn, $selectedId, $typedKey, $actor) : ['ok' => true];
                if ($res['ok']) {
                    $res = psZimraDeviceRegister($conn, $selectedId, $actor);
                }
                $message = $res['ok'] ? 'Device registered with ZIMRA. Certificate saved and configuration synced.' : '';
                break;
            case 'import':
                $res = psZimraDeviceImport($conn, $selectedId, (string)($_POST['private_key'] ?? ''), (string)($_POST['certificate'] ?? ''), $actor);
                $message = $res['ok'] ? 'Key and certificate imported. Configuration synced.' : '';
                break;
            case 'sync':
                $res = psZimraDeviceSyncConfig($conn, $selectedId, $actor);
                $message = $res['ok'] ? 'Configuration synced from ZIMRA.' : '';
                break;
            case 'status':
                $res = psZimraDeviceRefreshStatus($conn, $selectedId, $actor);
                $message = $res['ok'] ? 'ZIMRA status: ' . ($res['status']['fiscalDayStatus'] ?? '?')
                    . ', last fiscal day ' . (int)($res['status']['lastFiscalDayNo'] ?? 0)
                    . ', last receipt ' . (int)($res['status']['lastReceiptGlobalNo'] ?? 0) . '.' : '';
                break;
            case 'renew':
                $res = psZimraDeviceRenewCertificate($conn, $selectedId, $actor);
                $message = $res['ok'] ? 'Certificate renewed.' : '';
                break;
            case 'enable':
            case 'disable':
                $res = psZimraDeviceSetEnabled($conn, $selectedId, $action === 'enable', $actor);
                $message = $res['ok'] ? ($action === 'enable' ? 'Fiscalisation is ON for this branch.' : 'Fiscalisation is OFF for this branch.') : '';
                break;
            case 'logo':
                $up = $_FILES['logo'] ?? null;
                if (!$up || (int)$up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) {
                    $res = ['ok' => false, 'error' => 'Choose a logo image to upload (up to 500 KB).'];
                } else {
                    $res = psZimraDeviceSetLogo($conn, $selectedId, (string)$up['tmp_name'], $actor);
                }
                $message = $res['ok'] ? 'Receipt logo saved.' : '';
                break;
            case 'delete':
                $res = psZimraDeviceDelete($conn, $selectedId, $actor);
                if ($res['ok']) {
                    $message = 'Device deleted. Add it again with the correct details.';
                    $deletedId = $selectedId;
                    $selectedId = 0;
                }
                break;
            case 'move':
                $res = psZimraDeviceMoveBranch($conn, $selectedId, (string)($_POST['branch'] ?? ''), $actor);
                $message = $res['ok'] ? 'Device moved to ' . trim((string)$_POST['branch']) . '.' : '';
                break;
            case 'logo_remove':
                $res = psZimraDeviceSetLogo($conn, $selectedId, null, $actor);
                $message = $res['ok'] ? 'Receipt logo removed.' : '';
                break;
            case 'tax_map':
                $res = ['ok' => true];
                foreach ((array)($_POST['map'] ?? []) as $code => $taxId) {
                    $r = psZimraTaxMapSet($conn, $selectedId, (string)$code === '_NONE_' ? '' : (string)$code, (int)$taxId, $actor);
                    if (!$r['ok']) {
                        $res = $r;
                        break;
                    }
                }
                $message = $res['ok'] ? 'Tax mapping saved.' : '';
                break;
        }
        if (!$res['ok']) {
            $error = (string)($res['error'] ?? 'The action could not be completed.');
        } elseif (!empty($res['warning'])) {
            $message .= ' Warning: ' . $res['warning'];
        }
        if ($res['ok'] && $action !== '') {
            psSecurityAudit($conn, 'ZIMRA_' . strtoupper($action), ['summary' => 'ZIMRA device ' . $action, 'target_type' => 'zimra_device', 'target_id' => (string)($deletedId ?? $selectedId)]);
        }
    }
}

$devices = psZimraDeviceList($conn);
$device = $selectedId > 0 ? psZimraDeviceGet($conn, $selectedId) : null;
// Branches of the company being configured only (psBranchRegisteredNames is company-scoped).
$branches = psBranchRegisteredNames($conn);
sort($branches, SORT_NATURAL | SORT_FLAG_CASE);
$taxes = $device ? (psZimraQuery($conn, 'SELECT * FROM zimra_taxes WHERE device_row_id = ? ORDER BY tax_id', 'i', [(int)$device['id']]) ?? []) : [];
$maps = [];
foreach ($device ? (psZimraQuery($conn, 'SELECT pos_tax_code, tax_id FROM zimra_tax_map WHERE device_row_id = ?', 'i', [(int)$device['id']]) ?? []) : [] as $m) {
    $maps[$m['pos_tax_code']] = (int)$m['tax_id'];
}
$posCodes = array_values(array_unique(array_merge([''], array_map('strtoupper', array_column(psTaxListDistinctCodes($conn, false), 'code')))));
$day = $device ? psZimraDayLatest($conn, (int)$device['id']) : null;
$pendingCount = $device ? (int)(psZimraOne($conn, "SELECT COUNT(*) n FROM zimra_receipts WHERE device_row_id = ? AND status = 'PENDING'", 'i', [(int)$device['id']])['n'] ?? 0) : 0;
$masterKeyOk = psZimraMasterKey() !== null;
$noHistory = false;
if ($device) {
    $histDays = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_fiscal_days WHERE device_row_id = ?', 'i', [(int)$device['id']]);
    $histRcpt = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_receipts WHERE device_row_id = ?', 'i', [(int)$device['id']]);
    $noHistory = (int)($histDays['n'] ?? 0) === 0 && (int)($histRcpt['n'] ?? 0) === 0;
}
mysqli_close($conn);

$h = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$badge = static function (array $d): string {
    if ($d['status'] !== 'REGISTERED') {
        return '<span class="ps-badge ps-badge-secondary">Not registered</span>';
    }
    return (int)$d['enabled'] === 1
        ? '<span class="ps-badge ps-badge-success">Fiscalising</span>'
        : '<span class="ps-badge ps-badge-warning">Registered, off</span>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZIMRA Device Setup</title>
    <?php ps_head(); ?>
    <style>
        /* ZIMRA Device Setup — consistent spacing between steps, fields and buttons. */
        .zs-section { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--ps-line); }
        .zs-section:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
        .zs-section h3 { margin: 0 0 6px; font-size: 1.05rem; }
        .zs-section > p.ps-muted { margin: 0 0 16px; }
        .zs-form { display: flex; flex-direction: column; gap: 16px; }
        .zs-form .ps-field { margin: 0; display: flex; flex-direction: column; gap: 6px; }
        .zs-key { max-width: 320px; }
        .zs-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 4px 0 0; }
        .zs-actions form { display: inline-flex; margin: 0; }
        .zs-form + .zs-form, .ps-table-wrap + .zs-actions { margin-top: 16px; }
        .zs-section > p.zs-note { margin: 16px 0 0; }
        .zs-logo-preview { margin: 0 0 16px; padding: 12px; border: 1px solid var(--ps-line); border-radius: 10px; background: #fff; display: inline-block; }
        .zs-logo-preview img { display: block; max-width: 240px; max-height: 100px; }
        .zs-file { max-width: 420px; padding: 8px; }
    </style>
</head>
<body class="ps-app">
<?php ps_open('ZIMRA Device Setup'); ?>
<h1>ZIMRA Device Setup</h1>
<p class="ps-page-lead">One ZIMRA fiscal device per branch. Once a device is registered and switched on, every sale on that branch is a fiscal invoice and every processed refund is a credit note.</p>

<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="zimra_day.php">ZIMRA Fiscal Day</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="zimra_receipts.php">ZIMRA Fiscal Receipts</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="tax_rates.php">Tax Rates</a>
</div>

<?php if (!$masterKeyOk): ?>
    <div class="ps-alert ps-alert-destructive" role="alert">The ZIMRA master key could not be created or read in the private folder (pos-private\zimra). Device keys cannot be stored until this is fixed.</div>
<?php endif; ?>
<?php if ($message !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status"><?php echo $h($message); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>
<?php if ($otherCompanyNotice !== ''): ?>
    <div class="ps-alert ps-alert-warn" role="alert"><?php echo $h($otherCompanyNotice); ?></div>
<?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header"><h2 class="ps-card-title">Devices</h2></div>
    <div class="ps-card-content ps-card-flush">
        <div class="ps-table-wrap">
            <table class="ps-table">
                <thead><tr><th>Branch</th><th>Environment</th><th>Device ID</th><th>Serial</th><th>Status</th><th>Taxpayer</th><th>Certificate valid till</th><th></th></tr></thead>
                <tbody>
                <?php if (!$devices): ?>
                    <tr><td colspan="8" class="ps-muted">No ZIMRA devices yet. Add one below.</td></tr>
                <?php endif; ?>
                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><?php echo $h($d['branch']); ?></td>
                        <td><?php echo $h(ucfirst($d['environment'])); ?></td>
                        <td><?php echo $h($d['device_id']); ?></td>
                        <td><?php echo $h($d['device_serial_no']); ?></td>
                        <td><?php echo $badge($d); ?></td>
                        <td><?php echo $h(trim(($d['taxpayer_name'] ?? '') . ' ' . ($d['taxpayer_tin'] ? '(TIN ' . $d['taxpayer_tin'] . ')' : ''))); ?></td>
                        <td><?php echo $h($d['certificate_valid_till'] ?? ''); ?></td>
                        <td><a class="ps-btn ps-btn-outline ps-btn-sm" href="zimra_setup.php?id=<?php echo (int)$d['id']; ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($device): ?>
<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title"><?php echo $h($device['branch'] . ' — device ' . $device['device_id'] . ' (' . $device['environment'] . ')'); ?></h2>
        <p class="ps-card-desc">
            <?php echo $badge($device); ?>
            CN <?php echo $h(psZimraCommonName($device['device_serial_no'], (int)$device['device_id'])); ?> ·
            Model <?php echo $h($device['model_name'] . ' ' . $device['model_version']); ?> ·
            Last fiscal day <?php echo (int)$device['last_fiscal_day_no']; ?> · Last receipt <?php echo (int)$device['last_receipt_global_no']; ?>
            <?php if ($day): ?> · Current day <?php echo (int)$day['fiscal_day_no'] . ' ' . $h($day['status']); ?><?php endif; ?>
            <?php if ($pendingCount > 0): ?> · <strong><?php echo $pendingCount; ?> receipt(s) waiting to be sent</strong><?php endif; ?>
        </p>
    </div>
    <div class="ps-card-content">
        <?php if ($device['status'] !== 'REGISTERED'): ?>
            <section class="zs-section">
            <h3>1. Register with ZIMRA</h3>
            <p class="ps-muted">The server creates the device key and certificate request itself, then calls RegisterDevice with the 8-character activation key from the ZIMRA portal.</p>
            <form method="post" class="zs-form">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                <div class="ps-field"><label class="ps-label" for="ak">Activation key</label>
                    <input class="ps-input zs-key" id="ak" name="activation_key" maxlength="8" autocomplete="off"
                           placeholder="<?php echo empty($device['activation_key_enc']) ? '8 characters' : 'Saved — type a new one to replace it'; ?>"></div>
                <div class="zs-actions">
                    <button class="ps-btn ps-btn-outline" type="submit" name="action" value="activation_key">Save activation key</button>
                    <button class="ps-btn ps-btn-default" type="submit" name="action" value="register">Register device</button>
                </div>
            </form>
            </section>
            <section class="zs-section">
            <h3>Or import an existing key and certificate</h3>
            <p class="ps-muted">Use this for a device already registered elsewhere (for example the files in C:\FDMS-Test). Paste the full PEM text including the BEGIN/END lines. The key is stored encrypted.</p>
        <?php else: ?>
            <section class="zs-section">
            <div class="zs-actions">
                <?php foreach (['sync' => 'Sync configuration', 'status' => 'Check ZIMRA status', 'renew' => 'Renew certificate'] as $a => $label): ?>
                    <form method="post" style="display:inline">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                        <input type="hidden" name="action" value="<?php echo $a; ?>">
                        <button class="ps-btn ps-btn-outline" type="submit" <?php echo $a === 'renew' ? 'onclick="return confirm(\'Request a new certificate from ZIMRA now?\')"' : ''; ?>><?php echo $h($label); ?></button>
                    </form>
                <?php endforeach; ?>
                <form method="post" style="display:inline">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                    <input type="hidden" name="action" value="<?php echo (int)$device['enabled'] === 1 ? 'disable' : 'enable'; ?>">
                    <button class="ps-btn ps-btn-default" type="submit"><?php echo (int)$device['enabled'] === 1 ? 'Turn fiscalisation off' : 'Turn fiscalisation on'; ?></button>
                </form>
            </div>
            <p class="ps-muted zs-note">Taxpayer: <?php echo $h($device['taxpayer_name'] ?? ''); ?> · TIN <?php echo $h($device['taxpayer_tin'] ?? ''); ?>
                <?php if (!empty($device['vat_number'])): ?> · VAT <?php echo $h($device['vat_number']); ?><?php endif; ?>
                · Day limit <?php echo $h($device['day_max_hrs'] ?? '?'); ?> h · Config synced <?php echo $h($device['config_synced_at'] ?? 'never'); ?></p>

            </section>
            <section class="zs-section">
            <h3>Tax mapping</h3>
            <p class="ps-muted">Each POS tax code (Tax Rates) must point to one ZIMRA tax. With a single ZIMRA tax (for example a non-VAT taxpayer) unmapped codes use it automatically.</p>
            <form method="post" class="ps-form-stack">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="action" value="tax_map">
                <div class="ps-table-wrap"><table class="ps-table">
                    <thead><tr><th>POS tax code</th><th>ZIMRA tax</th></tr></thead>
                    <tbody>
                    <?php foreach ($posCodes as $code): $key = $code === '' ? '_NONE_' : $code; ?>
                        <tr>
                            <td><?php echo $code === '' ? '<span class="ps-muted">Products with no tax code</span>' : $h($code); ?></td>
                            <td><select class="ps-input" name="map[<?php echo $h($key); ?>]">
                                <option value="0"><?php echo count($taxes) === 1 ? 'Automatic (only one ZIMRA tax)' : '— not mapped —'; ?></option>
                                <?php foreach ($taxes as $t): ?>
                                    <option value="<?php echo (int)$t['tax_id']; ?>" <?php echo ($maps[$code] ?? 0) === (int)$t['tax_id'] ? 'selected' : ''; ?>>
                                        <?php echo $h($t['tax_id'] . ' — ' . $t['tax_name'] . ($t['tax_percent'] !== null ? ' (' . $t['tax_percent'] . '%)' : ' (exempt)')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="zs-actions"><button class="ps-btn ps-btn-default" type="submit">Save tax mapping</button></div>
            </form>
            </section>
            <section class="zs-section">
            <h3>Receipt logo</h3>
            <p class="ps-muted">Printed at the top of this branch's fiscal receipts. PNG or JPEG up to 500 KB; a wide, simple black-and-white logo prints best on till paper.</p>
            <?php if (!empty($device['receipt_logo'])): ?>
                <div class="zs-logo-preview"><img src="<?php echo $h($device['receipt_logo']); ?>" alt="Current receipt logo"></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="zs-form">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                <div class="ps-field"><label class="ps-label" for="logo">Logo image</label>
                    <input class="ps-input zs-file" id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"></div>
                <div class="zs-actions">
                    <button class="ps-btn ps-btn-default" type="submit" name="action" value="logo"><?php echo empty($device['receipt_logo']) ? 'Upload logo' : 'Replace logo'; ?></button>
                    <?php if (!empty($device['receipt_logo'])): ?>
                        <button class="ps-btn ps-btn-outline" type="submit" name="action" value="logo_remove" formnovalidate onclick="return confirm('Remove the receipt logo?')">Remove logo</button>
                    <?php endif; ?>
                </div>
            </form>
            </section>
            <section class="zs-section">
            <h3>Replace key and certificate</h3>
        <?php endif; ?>
        <form method="post" class="zs-form">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
            <input type="hidden" name="action" value="import">
            <div class="ps-field"><label class="ps-label" for="pk">Private key (PEM)</label>
                <textarea class="ps-input" id="pk" name="private_key" rows="5" autocomplete="off" spellcheck="false" required></textarea></div>
            <div class="ps-field"><label class="ps-label" for="cert">Certificate (PEM)</label>
                <textarea class="ps-input" id="cert" name="certificate" rows="8" spellcheck="false" required></textarea></div>
            <div class="zs-actions"><button class="ps-btn ps-btn-outline" type="submit">Import key and certificate</button></div>
        </form>
        </section>
        <?php
        $otherBranches = array_values(array_filter($branches, static fn ($b) => $b !== (string)$device['branch']));
        ?>
        <?php if ($noHistory && $otherBranches): ?>
        <section class="zs-section">
            <h3>Wrong branch?</h3>
            <p class="ps-muted">This device has no fiscal days or receipts yet, so it can be moved to another branch of this company.</p>
            <form method="post" class="zs-form">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="action" value="move">
                <div class="ps-field"><label class="ps-label" for="mvbr">Move to branch</label>
                    <select class="ps-input" id="mvbr" name="branch" required>
                        <?php foreach ($otherBranches as $b): ?><option value="<?php echo $h($b); ?>"><?php echo $h($b); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="zs-actions"><button class="ps-btn ps-btn-outline" type="submit">Move device</button></div>
            </form>
        </section>
        <?php endif; ?>
        <?php if ($device['status'] !== 'REGISTERED' && empty($device['certificate_pem'])): ?>
        <section class="zs-section">
            <h3>Entered the wrong details?</h3>
            <p class="ps-muted">This device is not registered with ZIMRA yet, so it can be deleted and added again. Registered devices cannot be deleted.</p>
            <form method="post" class="zs-form" onsubmit="return confirm('Delete device <?php echo (int)$device['device_id']; ?> for <?php echo $h(addslashes((string)$device['branch'])); ?>? This cannot be undone.');">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$device['id']; ?>">
                <input type="hidden" name="action" value="delete">
                <div class="zs-actions"><button class="ps-btn ps-btn-destructive" type="submit">Delete this device</button></div>
            </form>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Add a device</h2>
        <p class="ps-card-desc">Copy these from the ZIMRA portal (fdmstest.zimra.co.zw for test, the production portal for live devices).</p>
    </div>
    <div class="ps-card-content">
        <form method="post" class="ps-form-stack">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="action" value="create">
            <div class="ps-field"><label class="ps-label" for="br">Branch</label>
                <select class="ps-input" id="br" name="branch" required>
                    <option value="">Select branch</option>
                    <?php foreach ($branches as $b): ?><option value="<?php echo $h($b); ?>"><?php echo $h($b); ?></option><?php endforeach; ?>
                </select></div>
            <div class="ps-field"><label class="ps-label" for="env">Environment</label>
                <select class="ps-input" id="env" name="environment"><option value="test">Test</option><option value="production">Production</option></select></div>
            <div class="ps-field"><label class="ps-label" for="did">Device ID</label><input class="ps-input" id="did" name="device_id" inputmode="numeric" required></div>
            <div class="ps-field"><label class="ps-label" for="sn">Serial number</label><input class="ps-input" id="sn" name="device_serial_no" required></div>
            <div class="ps-field"><label class="ps-label" for="mn">Model name</label><input class="ps-input" id="mn" name="model_name" required></div>
            <div class="ps-field"><label class="ps-label" for="mv">Model version</label><input class="ps-input" id="mv" name="model_version" required></div>
            <div class="ps-field"><label class="ps-label" for="ak2">Activation key (optional now)</label><input class="ps-input" id="ak2" name="activation_key" maxlength="8" autocomplete="off"></div>
            <div class="ps-field"><label class="ps-label" for="pf">Receipt format</label>
                <select class="ps-input" id="pf" name="print_form"><option value="Receipt48">Till slip (Receipt48)</option><option value="InvoiceA4">A4 invoice</option></select></div>
            <div class="ps-form-actions"><button class="ps-btn ps-btn-default" type="submit">Save device</button></div>
        </form>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
