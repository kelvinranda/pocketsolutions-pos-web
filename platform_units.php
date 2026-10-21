<?php
/**
 * Phase 19 — Super Admin: the shared Units of Measure list used by every company's products.
 * A unit's code is stored on products, so it cannot be changed or deleted — only renamed, regrouped,
 * reordered or hidden.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';
require_once __DIR__ . '/includes/catalog_setup.php';

$conn = psPlatformPageBoot();
psCatalogEnsureSchema($conn);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($action === 'save') {
        $r = psUnitSave($conn, $id, (string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['dimension'] ?? 'count'), (int)($_POST['sort_order'] ?? 0));
        if ($r['ok']) {
            psPlatformAudit($conn, $id > 0 ? 'UNIT_UPDATE' : 'UNIT_CREATE', null, '', ['code' => (string)($_POST['code'] ?? ''), 'name' => (string)($_POST['name'] ?? '')]);
            psPlatformFlash('ok', $id > 0 ? 'Unit saved.' : 'Unit added.');
            header('Location: platform_units.php');
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'hide' || $action === 'show') {
        psUnitSetActive($conn, $id, $action === 'show');
        psPlatformAudit($conn, $action === 'hide' ? 'UNIT_HIDE' : 'UNIT_SHOW', null, '', ['id' => $id]);
        psPlatformFlash('ok', $action === 'hide' ? 'Unit hidden from product forms. Products already using it keep it.' : 'Unit shown again.');
        header('Location: platform_units.php');
        exit;
    }
}

$units = psUnitList($conn, false);
$usage = psUnitUsage($conn);
$dims = psUnitDimensions();
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$v = static fn (string $k, string $d = ''): string => htmlspecialchars((string)($_POST[$k] ?? $d), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Units of Measure</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app form.pu-row.pu-row { display: contents; }
        html[data-theme] body.ps-app .pu-table td { vertical-align: middle; }
        html[data-theme] body.ps-app .pu-table .ps-input { min-width: 0; width: 100%; }
        html[data-theme] body.ps-app .pu-code form { display: none; }
        html[data-theme] body.ps-app .pu-code { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 600; }
        html[data-theme] body.ps-app .pu-hidden td { opacity: .6; }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Units of Measure'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Units of Measure</h1>
        <p class="ps-page-lead">One list for every company. Products store the unit code with their pack size (500 + ml = "500 ml"), so a code cannot be changed once added — rename, regroup or hide it instead.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Add a unit</h2></div>
        <div class="ps-card-content">
            <form method="post" action="platform_units.php" class="pf-form">
                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
                <div class="pf-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
                    <label class="ps-field"><span class="ps-label">Code</span><input class="ps-input" name="code" required maxlength="16" placeholder="e.g. oz" value="<?php echo $v('code'); ?>"></label>
                    <label class="ps-field"><span class="ps-label">Name</span><input class="ps-input" name="name" required maxlength="60" placeholder="e.g. Ounces" value="<?php echo $v('name'); ?>"></label>
                    <label class="ps-field"><span class="ps-label">Group</span><select class="ps-input" name="dimension"><?php foreach ($dims as $k => $l): ?><option value="<?php echo $h($k); ?>" <?php echo ($_POST['dimension'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?></select></label>
                    <label class="ps-field"><span class="ps-label">Order</span><input class="ps-input" type="number" name="sort_order" value="<?php echo $v('sort_order', '500'); ?>"></label>
                </div>
                <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Add unit</button></div>
            </form>
        </div>
    </div>

    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table pu-table">
        <thead><tr><th>Code</th><th>Name</th><th>Group</th><th>Order</th><th>Products</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($units as $u): $on = (int)$u['active'] === 1; $fid = 'u' . (int)$u['id']; ?>
            <tr class="<?php echo $on ? '' : 'pu-hidden'; ?>">
                <td class="pu-code"><?php echo $h($u['code']); ?>
                    <form method="post" action="platform_units.php" id="<?php echo $fid; ?>"><?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="code" value="<?php echo $h($u['code']); ?>"></form></td>
                <td><input class="ps-input ps-input-sm" form="<?php echo $fid; ?>" name="name" value="<?php echo $h($u['name']); ?>" maxlength="60" aria-label="Name"></td>
                <td><select class="ps-input ps-input-sm" form="<?php echo $fid; ?>" name="dimension" aria-label="Group"><?php foreach ($dims as $k => $l): ?><option value="<?php echo $h($k); ?>" <?php echo $u['dimension'] === $k ? 'selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?></select></td>
                <td style="width:90px"><input class="ps-input ps-input-sm" form="<?php echo $fid; ?>" type="number" name="sort_order" value="<?php echo (int)$u['sort_order']; ?>" aria-label="Order"></td>
                <td><?php echo (int)($usage[strtolower((string)$u['code'])] ?? 0); ?></td>
                <td><div class="pf-row-actions">
                    <button class="ps-btn ps-btn-ghost ps-btn-sm" type="submit" form="<?php echo $fid; ?>">Save</button>
                    <form method="post" action="platform_units.php"><?php echo psCsrfField(); ?>
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="action" value="<?php echo $on ? 'hide' : 'show'; ?>">
                        <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"><?php echo $on ? 'Hide' : 'Show'; ?></button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
</div>
<?php ps_close(); ?>
</body>
</html>
