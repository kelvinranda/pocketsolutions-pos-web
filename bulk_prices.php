<?php
/**
 * Phase 20 — Bulk price change (admin). Pick products by branch / category / button group / name,
 * choose +/- % or amount, or a fixed price, preview every change, then apply in one transaction.
 * Each changed product gets a product-audit row (field price or cost_price, source BULK).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/catalog_setup.php';
require_once __DIR__ . '/includes/ps_layout.php';

require_once __DIR__ . '/includes/role_permissions.php';
psRolePermRequire('prices_edit'); // 2026-11-02: company role permissions (was admin only)
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psCatalogEnsureSchema($conn);
psEnsureProductAuditSchema($conn);

$company = trim((string)($_SESSION['the_company'] ?? ''));
$branches = psAuthorisedProductBranches($conn);
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$f = [
    'branch' => (string)($in['branch'] ?? (psSessionProductBranch() ?: '')),
    'category' => trim((string)($in['category'] ?? '')),
    'button' => trim((string)($in['button'] ?? '')),
    'q' => trim((string)($in['q'] ?? '')),
    'field' => ($in['field'] ?? 'price') === 'cost_price' ? 'cost_price' : 'price',
    'mode' => in_array($in['mode'] ?? '', ['pct', 'amount', 'set'], true) ? $in['mode'] : 'pct',
    'dir' => ($in['dir'] ?? 'up') === 'down' ? 'down' : 'up',
    'value' => trim((string)($in['value'] ?? '')),
    'round' => in_array($in['round'] ?? '', ['0', '0.05', '0.10', '0.50', '1.00'], true) ? $in['round'] : '0',
];
if ($f['branch'] !== '*' && !in_array($f['branch'], $branches, true)) {
    $f['branch'] = $branches[0] ?? '';
}
$errors = [];
$message = '';
$rows = [];
$preview = false;

/** New price in cents for one product, or null when the result would be negative. */
function psBulkNewCents(int $oldCents, array $f, int $valueCents, float $pct): ?int
{
    if ($f['mode'] === 'set') {
        $new = $valueCents;
    } elseif ($f['mode'] === 'pct') {
        $new = (int)round($oldCents * (1 + ($f['dir'] === 'down' ? -$pct : $pct) / 100));
    } else {
        $new = $oldCents + ($f['dir'] === 'down' ? -$valueCents : $valueCents);
    }
    $step = (int)round((float)$f['round'] * 100);
    if ($step > 0) {
        $new = (int)(round($new / $step) * $step);
    }
    return $new < 0 ? null : $new;
}

$action = (string)($in['action'] ?? '');
if ($action === 'preview' || $action === 'apply') {
    $valueOk = preg_match('/^\d{1,7}(\.\d{1,2})?$/', $f['value']) === 1;
    if (!$valueOk || ($f['mode'] !== 'set' && (float)$f['value'] <= 0)) {
        $errors[] = $f['mode'] === 'pct' ? 'Enter the percentage, e.g. 5 or 12.5.' : 'Enter the amount in dollars, e.g. 0.20.';
    } elseif ($f['mode'] === 'pct' && $f['dir'] === 'down' && (float)$f['value'] >= 100) {
        $errors[] = 'A decrease must be less than 100%.';
    } elseif ($f['branch'] === '') {
        $errors[] = 'Choose a branch.';
    } elseif ($f['category'] === '' && $f['button'] === '' && $f['q'] === '' && empty($in['all_products'])) {
        $errors[] = 'Narrow it down by category, button group or name — or tick "All products" to change everything on the branch.';
    }
    if (!$errors) {
        $where = ["(company_name = ? OR company_name IS NULL OR company_name = '')", "status = 'ACTIVE'"];
        $types = 's';
        $params = [$company];
        if ($f['branch'] === '*') {
            $where[] = 'branch IN (' . implode(',', array_fill(0, count($branches), '?')) . ')';
            $types .= str_repeat('s', count($branches));
            array_push($params, ...$branches);
        } else {
            $where[] = 'branch = ?';
            $types .= 's';
            $params[] = $f['branch'];
        }
        if ($f['category'] !== '') {
            $where[] = 'TRIM(category) = ?';
            $types .= 's';
            $params[] = $f['category'];
        }
        if ($f['button'] !== '') {
            $where[] = 'TRIM(button_category) = ?';
            $types .= 's';
            $params[] = $f['button'];
        }
        if ($f['q'] !== '') {
            $where[] = 'product LIKE ?';
            $types .= 's';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['q']) . '%';
        }
        $stmt = mysqli_prepare($conn, 'SELECT id, product_id, product, branch, price, cost_price FROM goods WHERE ' . implode(' AND ', $where) . ' ORDER BY branch, product LIMIT 2000');
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $found = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        $valueCents = (int)round((float)$f['value'] * 100);
        $pct = (float)$f['value'];
        $negative = 0;
        foreach ($found as $r) {
            $oldCents = psDecimalCentsSafe((string)$r[$f['field']]);
            $newCents = psBulkNewCents($oldCents, $f, $valueCents, $pct);
            if ($newCents === null) {
                $negative++;
                continue;
            }
            if ($newCents === $oldCents) {
                continue;
            }
            $r['old_cents'] = $oldCents;
            $r['new_cents'] = $newCents;
            $rows[] = $r;
        }
        if ($negative > 0) {
            $errors[] = $negative . ' product(s) would go below 0 and are left out.';
        }
        $sig = hash_hmac('sha256', json_encode(array_map(static fn ($r) => [$r['id'], $r['old_cents'], $r['new_cents']], $rows)), (string)($_SESSION['ps_csrf'] ?? ''));
        $preview = true;

        if ($action === 'apply') {
            if (!psCsrfValidate()) {
                $errors[] = 'Invalid security token. Reload and try again.';
            } elseif (!hash_equals($sig, (string)($_POST['sig'] ?? ''))) {
                $errors[] = 'Prices changed since the preview. Check the new preview and apply again.';
            } elseif (!$rows) {
                $errors[] = 'Nothing to change.';
            } else {
                $tx = psProductAuditBegin($conn);
                $owns = !empty($tx['owns']);
                $ok = !empty($tx['ok']);
                $col = $f['field'];
                foreach ($ok ? $rows : [] as $r) {
                    $new = number_format($r['new_cents'] / 100, 2, '.', '');
                    $u = mysqli_prepare($conn, "UPDATE goods SET $col = ? WHERE id = ?");
                    $id = (int)$r['id'];
                    mysqli_stmt_bind_param($u, 'si', $new, $id);
                    $ok = mysqli_stmt_execute($u);
                    mysqli_stmt_close($u);
                    $ok = $ok && psProductAuditRecordChanges($conn, [
                        'product_id' => (string)$r['product_id'],
                        'goods_id' => $id,
                        'branch' => (string)$r['branch'],
                        'action' => PS_PRODUCT_AUDIT_ACTION_UPDATE,
                        'source' => 'BULK',
                    ], [['field_name' => $col, 'old_value' => number_format($r['old_cents'] / 100, 2, '.', ''), 'new_value' => $new]]);
                    if (!$ok) {
                        break;
                    }
                }
                if ($ok && psProductAuditCommit($conn, $owns)) {
                    $desc = ($f['mode'] === 'set' ? 'set to $' . $f['value'] : (($f['dir'] === 'up' ? '+' : '-') . ($f['mode'] === 'pct' ? $f['value'] . '%' : '$' . $f['value'])))
                        . ($f['round'] !== '0' ? ', rounded to ' . $f['round'] : '');
                    psSecurityAudit($conn, 'BULK_PRICE', [
                        'summary' => 'Bulk ' . ($col === 'price' ? 'selling' : 'cost') . ' price change on ' . count($rows) . ' product(s): ' . $desc,
                        'target_type' => 'goods',
                        'after' => ['branch' => $f['branch'], 'category' => $f['category'], 'button' => $f['button'], 'q' => $f['q']],
                    ]);
                    $_SESSION['ps_bulk_done'] = count($rows) . ' product ' . ($col === 'price' ? 'selling' : 'cost') . ' price(s) changed (' . $desc . ').';
                    header('Location: bulk_prices.php?' . http_build_query(array_diff_key($f, ['value' => 1])));
                    exit;
                }
                if (!empty($tx['ok'])) {
                    psProductAuditRollback($conn, $owns);
                }
                $errors[] = 'The prices could not be saved. Nothing was changed.';
            }
        }
    }
}

/** "1,234.50" / "$2" / "" -> cents (0 when not a number). */
function psDecimalCentsSafe(string $v): int
{
    $v = str_replace([',', '$', ' '], '', trim($v));
    return is_numeric($v) ? (int)round((float)$v * 100) : 0;
}

$done = $_SESSION['ps_bulk_done'] ?? '';
unset($_SESSION['ps_bulk_done']);
$cats = psCatNames($conn, 'category');
$btns = psCatNames($conn, 'button');
mysqli_close($conn);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$money = static fn (int $c): string => number_format($c / 100, 2);
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Price Change</title>
    <?php ps_head(); ?>
    <style>
        html[data-theme] body.ps-app .bp-wrap { max-width: 1100px; display: flex; flex-direction: column; gap: 20px; }
        html[data-theme] body.ps-app .bp-wrap > .ps-card { margin: 0; }
        html[data-theme] body.ps-app form.bp-form.bp-form { display: flex; flex-direction: column; gap: 20px; margin: 0; }
        html[data-theme] body.ps-app .bp-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 18px; }
        html[data-theme] body.ps-app .bp-grid .ps-field { margin: 0; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        html[data-theme] body.ps-app .bp-grid .ps-input { width: 100%; }
        html[data-theme] body.ps-app .bp-h { margin: 0 0 12px; font-size: 1rem; font-weight: 700; }
        html[data-theme] body.ps-app .bp-sec + .bp-sec { border-top: 1px solid var(--ps-line); padding-top: 18px; }
        html[data-theme] body.ps-app .bp-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        html[data-theme] body.ps-app .bp-check { display: flex; gap: 8px; align-items: center; }
        html[data-theme] body.ps-app .bp-up { color: var(--ps-green); font-weight: 700; }
        html[data-theme] body.ps-app .bp-down { color: var(--ps-danger); font-weight: 700; }
        html[data-theme] body.ps-app .bp-num { text-align: right; white-space: nowrap; }
        @media (max-width: 900px) { html[data-theme] body.ps-app .bp-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Bulk Price Change'); ?>
<div class="bp-wrap">
    <div>
        <h1>Bulk Price Change</h1>
        <p class="ps-page-lead">Change many prices at once — for example +5% on all Beverages, or round everything to the nearest 10c. Preview first; nothing changes until you press Apply.</p>
    </div>
    <?php if ($done !== ''): ?><div class="ps-alert ps-alert-success" role="status"><?php echo $h($done); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="ps-card"><div class="ps-card-content">
        <form method="get" action="bulk_prices.php" class="bp-form">
            <input type="hidden" name="action" value="preview">
            <section class="bp-sec">
                <h2 class="bp-h">Which products</h2>
                <div class="bp-grid">
                    <label class="ps-field"><span class="ps-label">Branch</span>
                        <select class="ps-input" name="branch">
                            <?php foreach ($branches as $b): ?><option value="<?php echo $h($b); ?>"<?php echo $sel($f['branch'], $b); ?>><?php echo $h($b); ?></option><?php endforeach; ?>
                            <?php if (count($branches) > 1): ?><option value="*"<?php echo $sel($f['branch'], '*'); ?>>All my branches</option><?php endif; ?>
                        </select></label>
                    <label class="ps-field"><span class="ps-label">Category</span>
                        <select class="ps-input" name="category"><option value="">Any</option>
                            <?php foreach ($cats as $c): ?><option value="<?php echo $h($c); ?>"<?php echo $sel($f['category'], $c); ?>><?php echo $h($c); ?></option><?php endforeach; ?></select></label>
                    <label class="ps-field"><span class="ps-label">Button group</span>
                        <select class="ps-input" name="button"><option value="">Any</option>
                            <?php foreach ($btns as $c): ?><option value="<?php echo $h($c); ?>"<?php echo $sel($f['button'], $c); ?>><?php echo $h($c); ?></option><?php endforeach; ?></select></label>
                    <label class="ps-field"><span class="ps-label">Name contains</span>
                        <input class="ps-input" name="q" value="<?php echo $h($f['q']); ?>" placeholder="e.g. Coca-Cola"></label>
                </div>
                <label class="bp-check" style="margin-top:12px"><input type="checkbox" name="all_products" value="1" <?php echo !empty($in['all_products']) ? 'checked' : ''; ?>> All products on the branch (no filter)</label>
            </section>
            <section class="bp-sec">
                <h2 class="bp-h">Change</h2>
                <div class="bp-grid">
                    <label class="ps-field"><span class="ps-label">Price to change</span>
                        <select class="ps-input" name="field"><option value="price"<?php echo $sel($f['field'], 'price'); ?>>Selling price</option><option value="cost_price"<?php echo $sel($f['field'], 'cost_price'); ?>>Cost price</option></select></label>
                    <label class="ps-field"><span class="ps-label">How</span>
                        <select class="ps-input" name="mode" id="bpMode">
                            <option value="pct"<?php echo $sel($f['mode'], 'pct'); ?>>By a percentage</option>
                            <option value="amount"<?php echo $sel($f['mode'], 'amount'); ?>>By an amount ($)</option>
                            <option value="set"<?php echo $sel($f['mode'], 'set'); ?>>Set to a fixed price ($)</option></select></label>
                    <label class="ps-field" id="bpDirWrap"><span class="ps-label">Up or down</span>
                        <select class="ps-input" name="dir"><option value="up"<?php echo $sel($f['dir'], 'up'); ?>>Increase</option><option value="down"<?php echo $sel($f['dir'], 'down'); ?>>Decrease</option></select></label>
                    <label class="ps-field"><span class="ps-label" id="bpValLbl">Value</span>
                        <input class="ps-input" name="value" inputmode="decimal" value="<?php echo $h($f['value']); ?>" placeholder="e.g. 5" required></label>
                    <label class="ps-field"><span class="ps-label">Round to</span>
                        <select class="ps-input" name="round">
                            <?php foreach (['0' => 'No rounding', '0.05' => 'Nearest 5c', '0.10' => 'Nearest 10c', '0.50' => 'Nearest 50c', '1.00' => 'Nearest $1'] as $k => $l): ?><option value="<?php echo $h($k); ?>"<?php echo $sel($f['round'], $k); ?>><?php echo $h($l); ?></option><?php endforeach; ?></select></label>
                </div>
            </section>
            <div class="bp-actions"><span class="ps-muted">Only active products of <?php echo $h($company); ?> are changed.</span>
                <button class="ps-btn ps-btn-outline" type="submit">Preview changes</button></div>
        </form>
    </div></div>

    <?php if ($preview): ?>
    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title"><?php echo count($rows); ?> product<?php echo count($rows) === 1 ? '' : 's'; ?> will change</h2>
            <p class="ps-card-desc"><?php echo $f['field'] === 'price' ? 'Selling price' : 'Cost price'; ?>. Products whose price stays the same are not listed.</p></div>
        <?php if ($rows): ?>
        <div class="ps-card-content">
            <form method="post" action="bulk_prices.php" class="bp-actions" onsubmit="return confirm('Change <?php echo count($rows); ?> price(s) now?');">
                <?php echo psCsrfField(); ?>
                <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?php echo $h($k); ?>" value="<?php echo $h($v); ?>"><?php endforeach; ?>
                <?php if (!empty($in['all_products'])): ?><input type="hidden" name="all_products" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="apply"><input type="hidden" name="sig" value="<?php echo $h($sig); ?>">
                <span class="ps-muted">Every change is recorded in Product Audit.</span>
                <button class="ps-btn ps-btn-default" type="submit">Apply to <?php echo count($rows); ?> product<?php echo count($rows) === 1 ? '' : 's'; ?></button>
            </form>
        </div>
        <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
            <thead><tr><th>Product</th><th>Branch</th><th class="bp-num">Now</th><th class="bp-num">New</th><th class="bp-num">Change</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, 500) as $r): $d = $r['new_cents'] - $r['old_cents']; ?>
                <tr><td><?php echo $h($r['product']); ?> <span class="ps-muted">#<?php echo $h($r['product_id']); ?></span></td><td><?php echo $h($r['branch']); ?></td>
                    <td class="bp-num"><?php echo $money($r['old_cents']); ?></td><td class="bp-num"><strong><?php echo $money($r['new_cents']); ?></strong></td>
                    <td class="bp-num <?php echo $d >= 0 ? 'bp-up' : 'bp-down'; ?>"><?php echo ($d >= 0 ? '+' : '−') . $money(abs($d)); ?></td></tr>
            <?php endforeach; ?>
            <?php if (count($rows) > 500): ?><tr><td colspan="5" class="ps-muted">… and <?php echo count($rows) - 500; ?> more (all will be changed).</td></tr><?php endif; ?>
            </tbody>
        </table></div></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var m = document.getElementById('bpMode'), dir = document.getElementById('bpDirWrap'), lbl = document.getElementById('bpValLbl');
    function sync() { dir.style.display = m.value === 'set' ? 'none' : ''; lbl.textContent = m.value === 'pct' ? 'Percentage (%)' : 'Amount ($)'; }
    m.addEventListener('change', sync); sync();
})();
</script>
<?php ps_close(); ?>
</body>
</html>
