<?php
/**
 * 2026-10-28 — Packs & Units: a product's base stock unit and its packaging options (Admin).
 * Rules and arithmetic: includes/product_packs.php. Packs are per company product (all branches); prices are in the
 * company base currency and are NOT worked out from the unit price.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/product_packs.php';
require_once __DIR__ . '/includes/platform_page.php';
require_once __DIR__ . '/includes/currency.php';

require_once __DIR__ . '/includes/role_permissions.php';
psRolePermRequire('items_manage'); // 2026-11-02: company role permissions (was admin only)
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
if (!psPackEnsureSchema($conn)) {
    die('The packs tables could not be created.');
}
$company = trim((string)($_SESSION['the_company'] ?? ''));
$actor = trim((string)($_SESSION['logger'] ?? ''));
$h = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pid = trim((string)($_GET['product_id'] ?? $_POST['product_id'] ?? ''));
$self = 'product_packs.php' . ($pid !== '' ? '?product_id=' . rawurlencode($pid) : '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($action === 'base_unit') {
        $r = psPackSetBaseUnit($conn, $company, $pid, (string)($_POST['base_unit'] ?? ''), $actor);
        if (!empty($r['ok'])) {
            psPlatformFlash('ok', 'Base unit saved.');
            header('Location: ' . $self);
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'save_pack') {
        $r = psPackSave($conn, $company, $pid, (int)($_POST['pack_id'] ?? 0), [
            'name' => $_POST['name'] ?? '', 'factor' => $_POST['factor'] ?? '', 'price' => $_POST['price'] ?? '',
            'barcode' => $_POST['barcode'] ?? '', 'sort_order' => $_POST['sort_order'] ?? 0,
        ], $actor);
        if (!empty($r['ok'])) {
            psPlatformFlash('ok', 'Pack "' . $r['pack']['name'] . '" saved. Past sales keep the pack details they were sold with.');
            header('Location: ' . $self);
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'hide' || $action === 'show') {
        $r = psPackSetActive($conn, $company, $pid, (int)($_POST['pack_id'] ?? 0), $action === 'show', $actor);
        if (!empty($r['ok'])) {
            psPlatformFlash('ok', $action === 'show' ? 'Pack shown again.' : 'Pack hidden. It can no longer be sold or received, and past sales keep it.');
            header('Location: ' . $self);
            exit;
        }
        $errors[] = $r['error'];
    }
}

// Product search (this company).
$q = trim((string)($_GET['q'] ?? ''));
$found = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = mysqli_prepare($conn, 'SELECT product_id, MIN(product) AS product, MAX(base_unit) AS base_unit FROM goods WHERE company_name = ? AND (product LIKE ? OR product_id LIKE ? OR barcode = ?)
                                    GROUP BY product_id ORDER BY product LIMIT 50');
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $like, $like, $q);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $found[] = $r;
    }
    mysqli_stmt_close($stmt);
}
// Products that already have packs.
$withPacks = [];
$stmt = mysqli_prepare($conn, 'SELECT p.product_id, (SELECT MIN(g.product) FROM goods g WHERE g.product_id = p.product_id AND g.company_name = p.company_name) AS product,
                                       COUNT(*) AS n, SUM(p.active) AS active FROM product_packs p WHERE p.company_name = ? GROUP BY p.product_id ORDER BY product LIMIT 200');
mysqli_stmt_bind_param($stmt, 's', $company);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $withPacks[] = $r;
}
mysqli_stmt_close($stmt);

$info = $pid !== '' ? psPackProductInfo($conn, $company, $pid) : null;
if ($pid !== '' && !$info) {
    $errors[] = 'Product ' . $pid . ' was not found in this company.';
}
$allPacks = $info ? psPackList($conn, $company, $pid, false) : [];
$branch = trim((string)($_SESSION['selected_branch'] ?? $_SESSION['branch'] ?? ''));
$stockText = '';
if ($info && $branch !== '' && isStockControlled($conn, $pid, $branch)) {
    $stockText = psPackFormatStock((int)psStockMilli(getStockBalance($conn, $pid, $branch)), $info['packs'], $info['base_label']);
}
$basePrice = '';
if ($info && $branch !== '') {
    $g = psGoodsByProductIdBranch($conn, $pid, $branch);
    if ($g) {
        $basePrice = psFxFormat($conn, (string)$g['price'], psFxBase($conn, $company));
        $baseCents = psDecimalCentsOrNull(number_format((float)$g['price'], 2, '.', ''));
    }
}
// 2026-10-28 clearer page: plain-language wording, the product's own example, and a price check per pack.
$baseCents = $baseCents ?? null;
$unitLbl = $info ? (string)$info['base_label'] : 'unit';
$unitPl = psPackPlural($unitLbl);
$content = $info && trim((string)$info['pack_size']) !== '' && trim((string)$info['unit_of_measure']) !== ''
    ? trim((string)$info['pack_size']) . ' ' . trim((string)$info['unit_of_measure']) : '';
$money = static fn (int $c) => psFxFormat($conn, number_format($c / 100, 2, '.', ''), psFxBase($conn, $company));
/** "1 dozen = 12 packets (12 kg) · $20.00 for the dozen ($1.67 a packet)" and a warning when the price looks like a single price. */
$packSummary = static function (array $p) use ($info, $unitLbl, $baseCents, $money): array {
    $f = (int)$p['factor_milli'];
    $txt = psPackDescribe($p, $unitLbl, (string)$info['pack_size'], (string)$info['unit_of_measure']);
    $warn = '';
    if ($p['price'] === null || $p['price'] === '') {
        $txt .= ' · not sold at the till (receiving and counting only)';
    } else {
        $pc = (int)psDecimalCentsOrNull((string)$p['price']);
        $per = $f > 0 ? intdiv($pc * 1000 * 2 + $f, 2 * $f) : 0;
        $txt .= ' · ' . $money($pc) . ' for the whole ' . $p['name'] . ($f > 1000 ? ' (' . $money($per) . ' a ' . $unitLbl . ')' : '');
        if ($baseCents !== null && $baseCents > 0 && $f > 1000) {
            $singles = intdiv($baseCents * $f + 500, 1000);
            if ($pc <= $baseCents * 2 || $pc < intdiv($singles, 2)) {
                $warn = 'Check the price: it is for the whole ' . $p['name'] . ' (' . psPackCount($f, $unitLbl) . '). '
                    . psPackCount($f, $unitLbl) . ' bought one by one cost ' . $money($singles) . '.';
            }
        }
    }
    return [$txt, $warn];
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packs &amp; Units</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app .pk-row { display: grid; grid-template-columns: 1.1fr .9fr 1fr 1.1fr .5fr auto; gap: 8px; align-items: end; margin: 0; }
        html[data-theme] body.ps-app .pk-sum { grid-column: 1/-1; font-size: .9em; color: var(--ps-muted); }
        html[data-theme] body.ps-app .pk-sum b { color: var(--ps-text); }
        html[data-theme] body.ps-app .pk-warn { grid-column: 1/-1; font-size: .9em; color: #f5b400; font-weight: 600; }
        html[data-theme] body.ps-app .pk-steps { margin: 6px 0 0; padding-left: 20px; line-height: 1.6; }
        html[data-theme] body.ps-app .pk-ex { background: var(--ps-card-2); border: 1px solid var(--ps-line); border-radius: 12px; padding: 12px 14px; line-height: 1.6; }
        html[data-theme] body.ps-app .pk-row .ps-input { min-width: 0; }
        html[data-theme] body.ps-app .pk-hidden { opacity: .6; }
        html[data-theme] body.ps-app .pk-list a { text-decoration: none; }
        @media (max-width: 900px) { html[data-theme] body.ps-app .pk-row { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Packs & Units'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Packs &amp; Units</h1>
        <p class="ps-page-lead">Sell, receive and count a product in bigger packs as well as one by one.</p>
        <div class="pk-ex">
            <b>Example — sugar:</b> you sell single <b>1 kg packets</b> at $1.80, and also a <b>bale of 12 packets</b> at $20.00.
            <ol class="pk-steps">
                <li>Find the product (Sugar).</li>
                <li><b>Single unit</b>: type <b>packet</b> — what you sell one at a time. Its price is the normal product price ($1.80).</li>
                <li><b>Add a pack</b>: name <b>bale</b>, <b>12</b> packets in it, price <b>20.00</b> for the whole bale.</li>
            </ol>
            Stock is always counted in single packets: 30 packets shows as "30 packets = 2 bales + 6 packets", and selling a bale takes 12 packets off.
            Products without packs work exactly as before.
        </div>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-content">
            <form method="get" action="product_packs.php" style="display:flex;gap:10px;align-items:flex-end;margin:0">
                <label class="pf-field" style="flex:1"><span class="ps-label">Find a product</span>
                    <input class="ps-input" name="q" value="<?php echo $h($q); ?>" placeholder="Name, product ID or barcode" autofocus></label>
                <button class="ps-btn ps-btn-default" type="submit">Search</button>
            </form>
            <?php if ($q !== ''): ?>
                <ul class="pk-list" style="margin:12px 0 0;padding-left:18px">
                    <?php if (!$found): ?><li class="ps-muted">No products match.</li><?php endif; ?>
                    <?php foreach ($found as $f): ?>
                        <li><a href="product_packs.php?product_id=<?php echo rawurlencode((string)$f['product_id']); ?>"><?php echo $h($f['product'] . ' · ' . $f['product_id']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($info): ?>
    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title"><?php echo $h($info['product'] . ' · ' . $pid); ?></h2>
            <p class="ps-card-desc">
                Sold one at a time as: <b>1 <?php echo $h($unitLbl); ?></b><?php if ($content !== ''): ?> (<?php echo $h($content); ?>)<?php endif; ?><?php if ($basePrice !== ''): ?> at <b><?php echo $h($basePrice); ?></b> (<?php echo $h($branch); ?>; change it on Items)<?php endif; ?>
                <?php if ($stockText !== ''): ?><br>Stock at <?php echo $h($branch); ?>: <b><?php echo $h($stockText); ?></b><?php endif; ?>
                <br><?php echo $info['measure'] ? 'Sold by weight / measure: decimal quantities are allowed.' : ($info['whole'] ? 'Sold and counted in whole ' . $h($unitPl) . ' only.' : 'Name the single unit or add a pack to count it in whole units.'); ?>
            </p></div>
        <div class="ps-card-content">
            <form method="post" action="<?php echo $h($self); ?>" style="display:flex;gap:10px;align-items:flex-end;margin:0">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="action" value="base_unit"><input type="hidden" name="product_id" value="<?php echo $h($pid); ?>">
                <label class="pf-field" style="flex:1"><span class="ps-label">1. Single unit — what do you sell one at a time?</span>
                    <input class="ps-input" name="base_unit" maxlength="40" value="<?php echo $h((string)($info['base_unit'] ?? '')); ?>" placeholder="e.g. packet, tin, egg, bag"></label>
                <button class="ps-btn ps-btn-outline" type="submit">Save</button>
            </form>
        </div>
    </div>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">2. Packs — bigger units of <?php echo $h($unitPl); ?></h2>
            <p class="ps-card-desc">For each pack: its name, how many <?php echo $h($unitPl); ?> are inside, and the price for the <b>whole pack</b> in <?php echo $h(psFxBase($conn, $company)); ?> (all branches).
                Leave the price empty for a pack you only receive or count, never sell. Changing a pack never changes past sales.</p></div>
        <div class="ps-card-content pf-stack">
            <?php foreach ($allPacks as $p): ?>
                <form method="post" action="<?php echo $h($self); ?>" class="pk-row <?php echo $p['active'] ? '' : 'pk-hidden'; ?>">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="action" value="save_pack"><input type="hidden" name="product_id" value="<?php echo $h($pid); ?>"><input type="hidden" name="pack_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="pf-field"><span class="ps-label">Pack name</span><input class="ps-input" name="name" required maxlength="40" value="<?php echo $h($p['name']); ?>"></label>
                    <label class="pf-field"><span class="ps-label"><?php echo $h(ucfirst($unitPl)); ?> in one pack</span><input class="ps-input" name="factor" required inputmode="decimal" value="<?php echo $h($p['factor']); ?>"></label>
                    <label class="pf-field"><span class="ps-label">Price for whole pack</span><input class="ps-input" name="price" inputmode="decimal" value="<?php echo $h((string)$p['price']); ?>" placeholder="not sold"></label>
                    <label class="pf-field"><span class="ps-label">Barcode</span><input class="ps-input" name="barcode" maxlength="64" value="<?php echo $h($p['barcode']); ?>"></label>
                    <label class="pf-field"><span class="ps-label">Position</span><input class="ps-input" name="sort_order" type="number" value="<?php echo (int)$p['sort_order']; ?>"></label>
                    <div class="pf-row-actions">
                        <button class="ps-btn ps-btn-default ps-btn-sm" type="submit">Save</button>
                        <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit" name="action" value="<?php echo $p['active'] ? 'hide' : 'show'; ?>" formnovalidate><?php echo $p['active'] ? 'Hide' : 'Show'; ?></button>
                    </div>
                    <?php [$sumTxt, $sumWarn] = $packSummary($p); ?>
                    <div class="pk-sum" data-sum><?php echo $h($sumTxt); ?><?php echo $p['active'] ? '' : ' · hidden'; ?></div>
                    <div class="pk-warn" data-warn<?php echo $sumWarn === '' ? ' hidden' : ''; ?>><?php echo $h($sumWarn); ?></div>
                </form>
            <?php endforeach; ?>
            <form method="post" action="<?php echo $h($self); ?>" class="pk-row">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="action" value="save_pack"><input type="hidden" name="product_id" value="<?php echo $h($pid); ?>"><input type="hidden" name="pack_id" value="0">
                <label class="pf-field"><span class="ps-label">New pack name</span><input class="ps-input" name="name" required maxlength="40" placeholder="e.g. bale, case, crate"></label>
                <label class="pf-field"><span class="ps-label"><?php echo $h(ucfirst($unitPl)); ?> in one pack</span><input class="ps-input" name="factor" required inputmode="decimal" placeholder="e.g. 12"></label>
                <label class="pf-field"><span class="ps-label">Price for whole pack</span><input class="ps-input" name="price" inputmode="decimal" placeholder="e.g. 20.00"></label>
                <label class="pf-field"><span class="ps-label">Barcode (optional)</span><input class="ps-input" name="barcode" maxlength="64"></label>
                <label class="pf-field"><span class="ps-label">Position</span><input class="ps-input" name="sort_order" type="number" value="0"></label>
                <div><button class="ps-btn ps-btn-default ps-btn-sm" type="submit">Add pack</button></div>
                <div class="pk-sum" data-sum></div>
                <div class="pk-warn" data-warn hidden></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Products with packs</h2></div>
        <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
            <thead><tr><th>Product</th><th>Packs</th></tr></thead>
            <tbody>
            <?php if (!$withPacks): ?><tr><td colspan="2" class="ps-muted">No product has packs yet.</td></tr><?php endif; ?>
            <?php foreach ($withPacks as $w): ?>
                <tr><td><a href="product_packs.php?product_id=<?php echo rawurlencode((string)$w['product_id']); ?>"><?php echo $h(($w['product'] ?? '') . ' · ' . $w['product_id']); ?></a></td>
                    <td><?php echo (int)$w['active']; ?> active<?php echo (int)$w['n'] > (int)$w['active'] ? ' · ' . ((int)$w['n'] - (int)$w['active']) . ' hidden' : ''; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>
</div>
<?php if ($info): ?>
<script>
// Live summary under each pack row: "1 bale = 12 packets · $20.00 for the whole bale ($1.67 a packet)" + price check.
(function () {
    var unit = <?php echo json_encode($unitLbl); ?>, unitPl = <?php echo json_encode($unitPl); ?>;
    var base = <?php echo json_encode($baseCents); ?>, cur = <?php echo json_encode(psFxBase($conn, $company)); ?>;
    function plural(l, n) { return n === 1 ? l : (l === unit ? unitPl : (/(s|x|z|ch|sh)$/i.test(l) ? l + 'es' : (/[^aeiou]y$/i.test(l) ? l.slice(0, -1) + 'ies' : l + 's'))); }
    var tpl = <?php echo json_encode(psFxFormat($conn, '0.00', psFxBase($conn, $company))); ?>;
    function m(c) { return tpl.replace('0.00', (c / 100).toFixed(2)); }
    document.querySelectorAll('form.pk-row').forEach(function (f) {
        var name = f.querySelector('[name=name]'), fac = f.querySelector('[name=factor]'), price = f.querySelector('[name=price]');
        var sum = f.querySelector('[data-sum]'), warn = f.querySelector('[data-warn]');
        function upd() {
            var n = (name.value || '').trim() || 'pack', k = parseFloat(fac.value), p = price.value.trim();
            if (!(k > 0)) { if (!fac.value) { sum.textContent = ''; } return; }
            var t = '1 ' + n + ' = ' + k + ' ' + plural(unit, k);
            warn.hidden = true;
            if (p === '') { t += ' · not sold at the till (receiving and counting only)'; }
            else if (!isNaN(parseFloat(p))) {
                var pc = Math.round(parseFloat(p) * 100);
                t += ' · ' + m(pc) + ' for the whole ' + n + (k > 1 ? ' (' + m(Math.round(pc / k)) + ' a ' + unit + ')' : '');
                if (base && k > 1) {
                    var singles = Math.round(base * k);
                    if (pc <= base * 2 || pc < singles / 2) {
                        warn.textContent = 'Check the price: it is for the whole ' + n + ' (' + k + ' ' + plural(unit, k) + '). ' + k + ' ' + plural(unit, k) + ' bought one by one cost ' + m(singles) + '.';
                        warn.hidden = false;
                    }
                }
            }
            sum.textContent = t;
        }
        [name, fac, price].forEach(function (i) { i.addEventListener('input', upd); });
        if (sum.textContent === '' && fac.value) { upd(); }
    });
})();
</script>
<?php endif; ?>
<?php ps_close(); ?>
