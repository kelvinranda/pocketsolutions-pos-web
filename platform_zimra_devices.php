<?php
/**
 * 2026-10-20 — Super Admin: every company's ZIMRA fiscal devices, and moving a device that was added under the wrong
 * company (only while it has no fiscal days or receipts). Day-to-day set-up stays with each company's admin
 * (ZIMRA Device Setup).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';
require_once __DIR__ . '/includes/zimra.php';

$conn = psPlatformPageBoot();
psEnsureZimraModule($conn);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ((string)($_POST['action'] ?? '') === 'move') {
        $id = (int)($_POST['id'] ?? 0);
        [$toCompany, $toBranch] = array_pad(explode("||", (string)($_POST['target'] ?? ''), 2), 2, '');
        $before = psZimraDeviceGet($conn, $id);
        $r = psZimraDeviceMoveCompany($conn, $id, $toCompany, $toBranch, (string)($_SESSION['logger'] ?? 'superadmin'));
        if (!empty($r['ok'])) {
            psPlatformAudit($conn, 'ZIMRA_DEVICE_MOVE_COMPANY', $toCompany, trim((string)($_POST['reason'] ?? '')), [
                'device_row_id' => $id, 'device_id' => (int)($before['device_id'] ?? 0),
                'from' => ($before['company_name'] ?? '') . ' / ' . ($before['branch'] ?? ''), 'to' => $toCompany . ' / ' . $toBranch,
            ]);
            psPlatformFlash('ok', 'Device ' . (int)($before['device_id'] ?? 0) . ' moved to ' . $toCompany . ' · ' . $toBranch
                . '. Fiscalisation is off: the company admin checks the tax mapping on ZIMRA Device Setup and switches it on.');
            header('Location: platform_zimra_devices.php');
            exit;
        }
        $errors[] = (string)($r['error'] ?? 'The device could not be moved.');
    }
}

$devices = psZimraQuery($conn, "SELECT d.*,
        (SELECT COUNT(*) FROM zimra_fiscal_days f WHERE f.device_row_id = d.id) AS days_n,
        (SELECT COUNT(*) FROM zimra_receipts r WHERE r.device_row_id = d.id) AS receipts_n
    FROM zimra_devices d ORDER BY d.company_name, d.branch, d.environment") ?? [];
$targets = psZimraQuery($conn, "SELECT b.company_name, b.branch_name, COALESCE(NULLIF(c.display_name, ''), b.company_name) AS label
    FROM branchies b LEFT JOIN companies c ON c.company_name = b.company_name
    WHERE b.company_name <> '' ORDER BY label, b.branch_name") ?? [];
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZIMRA Devices</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app .zd-move { display: grid; grid-template-columns: minmax(180px, 1.4fr) minmax(140px, 1fr) auto; gap: 6px; align-items: center; }
        @media (max-width: 760px) { html[data-theme] body.ps-app .zd-move { grid-template-columns: 1fr; } }
        html[data-theme] body.ps-app .zd-mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
    </style>
</head>
<body class="ps-app">
<?php ps_open('ZIMRA Devices'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>ZIMRA devices</h1>
        <p class="ps-page-lead">Fiscal devices of every company. A device added under the wrong company can be moved to the right company and branch
            while it has no fiscal days or receipts; its ZIMRA registration, key and certificate move with it. A device that has already
            fiscalised receipts stays with its company, because its counters and receipt history belong there.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="ps-alert ps-alert-info">One ZIMRA device can only be used by one system. A device registered on a desktop till PC lives on that PC and is not listed here;
        the web and each till PC need their own devices.</div>

    <div class="ps-card">
        <div class="ps-card-content">
            <?php if (!$devices): ?>
                <div class="ps-empty">No ZIMRA devices have been added on the web yet.</div>
            <?php else: ?>
            <div class="ps-table-wrap"><table>
                <thead><tr><th>Device</th><th>Company · branch</th><th>Environment</th><th>Status</th><th>Fiscal days / receipts</th><th>Move to</th></tr></thead>
                <tbody>
                <?php foreach ($devices as $d):
                    $used = (int)$d['days_n'] > 0 || (int)$d['receipts_n'] > 0; ?>
                    <tr>
                        <td class="zd-mono"><?php echo (int)$d['device_id']; ?><div class="hint"><?php echo $h($d['device_serial_no']); ?></div></td>
                        <td><?php echo $h($d['company_name'] !== '' ? $d['company_name'] : '(no company)'); ?><div class="hint"><?php echo $h($d['branch']); ?></div></td>
                        <td><?php echo $h(ucfirst((string)$d['environment'])); ?></td>
                        <td><?php echo $h($d['status']); ?><?php echo (int)$d['enabled'] ? ' · <span class="ps-badge ps-badge-success">On</span>' : ' · Off'; ?></td>
                        <td><?php echo (int)$d['days_n']; ?> / <?php echo (int)$d['receipts_n']; ?></td>
                        <td>
                            <?php if ($used): ?>
                                <span class="hint">Has fiscal history — cannot move</span>
                            <?php else: ?>
                                <form method="post" class="zd-move" onsubmit="return confirm('Move this device? Fiscalisation will be switched off until the new company\'s admin switches it on.');">
                                    <?php echo psCsrfField(); ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                    <select class="ps-input" name="target" required aria-label="Company and branch">
                                        <option value="">Company · branch…</option>
                                        <?php foreach ($targets as $t): ?>
                                            <option value="<?php echo $h($t['company_name'] . "||" . $t['branch_name']); ?>"><?php echo $h($t['label'] . ' · ' . $t['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="ps-input" name="reason" maxlength="200" placeholder="Reason (optional)" aria-label="Reason">
                                    <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Move</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
