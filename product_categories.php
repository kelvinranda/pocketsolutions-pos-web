<?php
/**
 * Phase 19 — Company admin: manage product Categories and POS Button groups.
 * Lists are per company (a Super Admin managing a company edits that company's lists).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/catalog_setup.php';
require_once __DIR__ . '/includes/platform_page.php';

require_once __DIR__ . '/includes/role_permissions.php';
psRolePermRequire('items_manage'); // 2026-11-02: company role permissions (was admin only)
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
if (!psCatalogEnsureSchema($conn)) {
    die('The category tables could not be created. Run the Phase 19 SQL first.');
}
$company = psCatCompany();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $kind = (string)($_POST['kind'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $label = PS_CAT_KINDS[$kind] ?? 'Entry';
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($action === 'add' && isset(PS_CAT_KINDS[$kind])) {
        $r = psCatAdd($conn, $kind, (string)($_POST['name'] ?? ''));
        if ($r['ok']) {
            psSecurityAudit($conn, 'CATEGORY_ADD', ['summary' => $label . ' added: ' . $r['name'], 'target_type' => 'product_category']);
            psPlatformFlash('ok', $label . ' "' . $r['name'] . '" added.');
            header('Location: product_categories.php#' . $kind);
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'rename') {
        $r = psCatRename($conn, $id, (string)($_POST['name'] ?? ''));
        if ($r['ok']) {
            psCatSetLook($conn, $id, (string)($_POST['color'] ?? ''), (int)($_POST['sort_order'] ?? 0));
            if (empty($r['old'])) {
                psPlatformFlash('ok', 'Saved.');
            }
            if (!empty($r['old'])) {
                psSecurityAudit($conn, 'CATEGORY_RENAME', ['summary' => 'Renamed "' . $r['old'] . '" to "' . $r['name'] . '" on ' . (int)$r['products'] . ' product(s)', 'target_type' => 'product_category', 'target_id' => (string)$id]);
                psPlatformFlash('ok', 'Renamed to "' . $r['name'] . '". ' . (int)$r['products'] . ' product(s) updated.');
            }
            header('Location: product_categories.php');
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'hide' || $action === 'show') {
        $r = psCatSetActive($conn, $id, $action === 'show');
        if ($r['ok']) {
            psSecurityAudit($conn, 'CATEGORY_' . strtoupper($action), ['summary' => ($action === 'hide' ? 'Hidden: ' : 'Shown: ') . $r['name'], 'target_type' => 'product_category', 'target_id' => (string)$id]);
            psPlatformFlash('ok', '"' . $r['name'] . '" ' . ($action === 'hide' ? 'hidden from the lists. Products already using it keep it.' : 'is back in the lists.'));
            header('Location: product_categories.php');
            exit;
        }
        $errors[] = $r['error'];
    }
}

$usage = psCatUsage($conn);
$lists = ['category' => psCatList($conn, 'category', false), 'button' => psCatList($conn, 'button', false)];
mysqli_close($conn);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$help = [
    'category' => 'What the product is (Groceries, Drinks, Hardware…). Used to group products in reports.',
    'button' => 'The group button the cashier taps on the till (Drinks, Bakery…). Set its colour and order on the till here.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories &amp; Button Groups</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app .pc-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; align-items: start; }
        html[data-theme] body.ps-app .pc-cols > * { min-width: 0; margin: 0; }
        html[data-theme] body.ps-app form.pc-add.pc-add { display: flex; gap: 10px; align-items: flex-end; margin: 0; }
        html[data-theme] body.ps-app .pc-add .pf-field { flex: 1 1 auto; }
        html[data-theme] body.ps-app form.pc-rename.pc-rename { display: flex; gap: 8px; align-items: center; margin: 0; }
        html[data-theme] body.ps-app .pc-rename .ps-input { min-width: 0; flex: 1 1 auto; }
        html[data-theme] body.ps-app .pc-table td { vertical-align: middle; }
        html[data-theme] body.ps-app .pc-color { width: 38px; height: 34px; padding: 2px; border: 1px solid var(--ps-line); border-radius: 8px; background: var(--ps-card); flex: 0 0 auto; cursor: pointer; }
        html[data-theme] body.ps-app .pc-order { width: 64px !important; flex: 0 0 64px !important; }
        html[data-theme] body.ps-app .pc-hidden .ps-input { opacity: .6; }
        @media (max-width: 1000px) { html[data-theme] body.ps-app .pc-cols { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Categories & Button Groups'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Categories &amp; Button Groups</h1>
        <p class="ps-page-lead">The lists offered when creating or editing products for <?php echo $h($company); ?>. Renaming changes every product that uses the name. Hiding removes it from the lists without touching existing products.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="pc-cols">
        <?php foreach (PS_CAT_KINDS as $kind => $label): ?>
        <div class="ps-card" id="<?php echo $h($kind); ?>">
            <div class="ps-card-header">
                <h2 class="ps-card-title"><?php echo $kind === 'category' ? 'Categories' : 'Button groups'; ?></h2>
                <p class="ps-card-desc"><?php echo $h($help[$kind]); ?></p>
            </div>
            <div class="ps-card-content">
                <form method="post" action="product_categories.php" class="pc-add">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="<?php echo $h($kind); ?>">
                    <label class="pf-field"><span class="ps-label">New <?php echo $h(strtolower($label)); ?></span>
                        <input class="ps-input" name="name" required maxlength="100" placeholder="<?php echo $kind === 'category' ? 'e.g. Beverages' : 'e.g. Drinks'; ?>"></label>
                    <button class="ps-btn ps-btn-default" type="submit">Add</button>
                </form>
            </div>
            <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table pc-table">
                <thead><tr><th>Name<?php echo $kind === 'button' ? ' · colour · order on till' : ' · order'; ?></th><th>Products</th><th></th></tr></thead>
                <tbody>
                <?php if (!$lists[$kind]): ?><tr><td colspan="3" class="ps-muted">Nothing yet — add the first one above.</td></tr><?php endif; ?>
                <?php foreach ($lists[$kind] as $r): $on = (int)$r['active'] === 1; ?>
                    <tr class="<?php echo $on ? '' : 'pc-hidden'; ?>">
                        <td>
                            <form method="post" action="product_categories.php" class="pc-rename">
                                <?php echo psCsrfField(); ?>
                                <input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <input class="ps-input ps-input-sm" name="name" value="<?php echo $h($r['name']); ?>" maxlength="100" aria-label="Name">
                                <?php if ($kind === 'button'): ?>
                                    <input class="pc-color" type="color" name="color" data-auto="<?php echo empty($r['color']) ? '1' : '0'; ?>" value="<?php echo $h(!empty($r['color']) ? $r['color'] : '#3b82f6'); ?>" aria-label="Button colour" title="<?php echo empty($r['color']) ? 'Automatic colour — pick one to set it' : 'Button colour on the till'; ?>">
                                <?php endif; ?>
                                <input class="ps-input ps-input-sm pc-order" type="number" name="sort_order" value="<?php echo (int)$r['sort_order']; ?>" aria-label="Order" title="Order (lower shows first)">
                                <button class="ps-btn ps-btn-ghost ps-btn-sm" type="submit">Save</button>
                            </form>
                        </td>
                        <td><?php echo (int)($usage[$kind][strtolower((string)$r['name'])] ?? 0); ?></td>
                        <td><div class="pf-row-actions">
                            <?php if (!$on): ?><span class="ps-badge ps-badge-secondary">Hidden</span><?php endif; ?>
                            <form method="post" action="product_categories.php"><?php echo psCsrfField(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <input type="hidden" name="action" value="<?php echo $on ? 'hide' : 'show'; ?>">
                                <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"><?php echo $on ? 'Hide' : 'Show'; ?></button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="ps-muted">Units of measure (g, kg, ml, litres…) are one shared list looked after by <?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?>. Ask us if you need one added.</p>
</div>
<script>
// A colour picker always has a value; keep "automatic" (no saved colour) unless the user picks one.
document.querySelectorAll('.pc-color').forEach(function (el) {
    el.addEventListener('input', function () { el.dataset.auto = '0'; });
    el.form.addEventListener('submit', function () { if (el.dataset.auto === '1') { el.disabled = true; } });
});
</script>
<?php ps_close(); ?>
</body>
</html>
