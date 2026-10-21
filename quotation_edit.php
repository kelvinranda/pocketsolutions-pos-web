<?php
/**
 * 2026-10-17 — Create or change an open quotation for the working branch.
 * All staff may quote at the branch price; Supervisor, Management and Admin may change a line's unit price.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/reporting.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/quotation.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
psReportingRequireAuthRedirect();
psQuoteEnsureSchema($conn);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$company = trim((string)($_SESSION['the_company'] ?? ''));
$mayPrice = psQuoteSessionMayPrice();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';
$quote = null;

if ($id > 0) {
    $quote = psQuoteGet($conn, $id, $company);
    if ($quote === null) {
        $error = 'Quotation was not found.';
    } elseif ($quote['status'] !== PS_QUOTE_OPEN) {
        header('Location: quotation_view.php?id=' . $id);
        exit;
    }
    $branch = $quote ? (string)$quote['branch'] : '';
} else {
    $w = psBranchResolveWorking($conn);
    $branch = !empty($w['ok']) ? (string)$w['branch'] : '';
    if ($branch === '') {
        $error = (string)($w['error'] ?? 'Choose a working branch first (Switch branch).');
    }
}
// Branch must be one the user may report on.
if ($error === '' && $quote !== null) {
    $scope = psBranchReportScope($conn);
    if (empty($scope['ok']) || !in_array($branch, $scope['branches'], true)) {
        $error = 'That branch is not available.';
    }
}

// "Copy to new quote": same customer and items at today's prices (a supervisor's special prices are kept for supervisors).
$copyId = (int)($_GET['copy'] ?? 0);
if ($quote === null && $copyId > 0 && $error === '') {
    $src = psQuoteGet($conn, $copyId, $company);
    if ($src !== null) {
        $quote = null;
        $copyFrom = $src;
    }
}
$base = $quote ?? ($copyFrom ?? null);
$form = [
    'customer_master_id' => $base['customer_master_id'] ?? '',
    'customer_name' => $base['customer_name'] ?? '',
    'customer_phone' => $base['customer_phone'] ?? '',
    'customer_email' => $base['customer_email'] ?? '',
    'valid_until' => $quote['valid_until'] ?? date('Y-m-d', strtotime('+' . PS_QUOTE_DEFAULT_DAYS . ' days')),
    'notes' => $base['notes'] ?? '',
    'lines' => array_map(fn($l) => ['product_id' => $l['product_id'], 'product' => $l['product'], 'quantity' => rtrim(rtrim((string)$l['quantity'], '0'), '.'),
        'unit_price' => ($quote !== null || $mayPrice) ? $l['unit_price'] : '', 'list_price' => $quote !== null ? $l['list_price'] : ''], $base['lines'] ?? []),
];

// Multi-currency (2026-10-20): a new quote picks its currency (?cur=); an existing quote keeps its own.
require_once __DIR__ . '/includes/currency.php';
psFxEnsureTransactionColumns($conn);
$qCurrencies = psFxCompanyCurrencies($conn, $company);
$qBase = psFxBase($conn, $company);
$qCur = $quote ? (psFxNormalizeCode($quote['currency'] ?? '') ?: $qBase) : (psFxNormalizeCode($_POST['currency'] ?? $_GET['cur'] ?? '') ?: $qBase);
if (!isset($qCurrencies[$qCur]) && !$quote) {
    $qCur = $qBase;
}
$qSnap = psFxSnapshot($conn, $company, $qCur);
if (empty($qSnap['ok'])) {
    $error = (string)$qSnap['error'];
}

if ($error === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $lines = [];
        $pids = (array)($_POST['product_id'] ?? []);
        foreach ($pids as $i => $pid) {
            $lines[] = ['product_id' => (string)$pid, 'quantity' => (string)($_POST['quantity'][$i] ?? ''), 'unit_price' => (string)($_POST['unit_price'][$i] ?? '')];
        }
        $in = [
            'customer_master_id' => (int)($_POST['customer_master_id'] ?? 0),
            'customer_name' => (string)($_POST['customer_name'] ?? ''),
            'customer_phone' => (string)($_POST['customer_phone'] ?? ''),
            'customer_email' => (string)($_POST['customer_email'] ?? ''),
            'valid_until' => (string)($_POST['valid_until'] ?? ''),
            'notes' => (string)($_POST['notes'] ?? ''),
            'currency' => $qCur,
            'lines' => $lines,
        ];
        $r = psQuoteSave($conn, $quote ? $id : null, $in, $branch, $company, $mayPrice);
        if (!empty($r['ok'])) {
            mysqli_close($conn);
            header('Location: quotation_view.php?id=' . (int)$r['id'] . '&saved=1');
            exit;
        }
        $error = (string)$r['error'];
        $form = array_merge($form, $in);
        $form['lines'] = $lines;
    }
}

// Products of the branch for the picker.
$products = [];
if ($branch !== '') {
    $st = mysqli_prepare($conn, "SELECT product_id, product, price, barcode FROM goods
                                 WHERE branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = '') AND (status IS NULL OR UPPER(status) = 'ACTIVE')
                                 ORDER BY product LIMIT 5000");
    if ($st) {
        mysqli_stmt_bind_param($st, 'ss', $branch, $company);
        mysqli_stmt_execute($st);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $p) {
            $pc = (int)psDecimalCents(number_format((float)$p['price'], 2, '.', ''));
            if (!empty($qSnap['ok']) && $qCur !== $qBase) {
                $pc = psFxFromBaseCents($conn, $qSnap, $pc); // shown in the quote currency, same rounding as the server
            }
            $products[] = ['id' => (string)$p['product_id'], 'name' => (string)$p['product'], 'price' => psMoneyFromCents($pc), 'barcode' => (string)($p['barcode'] ?? '')];
        }
        mysqli_stmt_close($st);
    }
}
$names = array_column($products, 'name', 'id');
foreach ($form['lines'] as $i => $l) {
    if (!isset($l['product'])) {
        $form['lines'][$i]['product'] = $names[$l['product_id']] ?? '';
    }
}
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $quote ? 'Edit quotation' : 'New quotation'; ?></title>
<?php ps_head(); ?>
    <style>
        .q-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
        .q-grid label{display:flex;flex-direction:column;gap:4px}
        table.q-lines td{vertical-align:middle}
        table.q-lines input{width:100%;box-sizing:border-box}
        .q-lines .n{text-align:right;white-space:nowrap}
        .q-pick{position:relative;max-width:520px}
        .q-results{position:absolute;z-index:20;left:0;right:0;background:var(--ps-card,#fff);border:1px solid rgba(0,0,0,.2);border-radius:8px;max-height:280px;overflow:auto}
        .q-results button{display:flex;justify-content:space-between;gap:12px;width:100%;text-align:left;padding:8px 10px;border:0;background:none;cursor:pointer;color:inherit}
        .q-results button:hover,.q-results button:focus{background:rgba(23,138,67,.12)}
        .q-total{font-size:1.2em;font-weight:bold;text-align:right}
        .q-cust-results button{display:block}
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1><?php echo $quote ? 'Edit quotation ' . $h($quote['quote_number']) : 'New quotation'; ?></h1>
    <p><a href="quotations.php">All quotations</a></p>
    <?php if ($error !== ''): ?><p class="error"><?php echo $h($error); ?></p><?php endif; ?>
    <?php if ($branch !== ''): ?>
    <form method="post" action="quotation_edit.php<?php echo $quote ? '?id=' . (int)$id : ''; ?>" id="qform">
        <?php echo psCsrfField(); ?>
        <?php if ($quote): ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><?php endif; ?>
        <input type="hidden" name="currency" value="<?php echo $h($qCur); ?>">
        <?php if (!$quote && count($qCurrencies) > 1): ?>
            <p class="hint">Currency:
                <?php foreach ($qCurrencies as $code => $c): ?>
                    <a class="ps-btn ps-btn-sm <?php echo $code === $qCur ? 'ps-btn-default' : 'ps-btn-outline'; ?>" href="quotation_edit.php?cur=<?php echo rawurlencode($code); ?>"><?php echo $h($code); ?></a>
                <?php endforeach; ?>
                <?php if ($qCur !== $qBase && !empty($qSnap['ok'])): ?> · <?php echo $h(psFxRateLabel($qBase, $qCur, $qSnap['rate'])); ?><?php endif; ?>
            </p>
        <?php elseif ($qCur !== $qBase): ?>
            <p class="hint">Quotation currency: <b><?php echo $h($qCur); ?></b></p>
        <?php endif; ?>
        <p class="hint">Branch: <b><?php echo $h($branch); ?></b> · prices are this branch's current prices, in <?php echo $h($qCur); ?><?php echo $mayPrice ? '; you can change a unit price for this quote.' : '. Ask a supervisor to change a price.'; ?></p>

        <h2>Customer</h2>
        <div class="q-grid">
            <label class="q-pick">Find a customer (optional)
                <input type="text" id="custSearch" placeholder="Name, code or phone" autocomplete="off">
                <div class="q-results q-cust-results" id="custResults" hidden></div>
            </label>
            <label>Customer name
                <input type="text" name="customer_name" id="custName" value="<?php echo $h($form['customer_name']); ?>" maxlength="250" required>
            </label>
            <label>Phone <input type="text" name="customer_phone" id="custPhone" value="<?php echo $h($form['customer_phone']); ?>" maxlength="64"></label>
            <label>Email <input type="email" name="customer_email" value="<?php echo $h($form['customer_email']); ?>" maxlength="250"></label>
        </div>
        <input type="hidden" name="customer_master_id" id="custId" value="<?php echo $h($form['customer_master_id']); ?>">
        <p class="hint" id="custLinked"<?php echo $form['customer_master_id'] ? '' : ' hidden'; ?>>Linked to a customer record. <button type="button" class="ps-btn ps-btn-outline ps-btn-sm" onclick="unlinkCustomer()">Unlink</button></p>

        <h2>Items</h2>
        <div class="q-pick">
            <input type="text" id="prodSearch" placeholder="Type a product name, code or barcode to add it" autocomplete="off">
            <div class="q-results" id="prodResults" hidden></div>
        </div>
        <div style="overflow-x:auto;margin-top:12px">
        <table class="q-lines">
            <thead><tr><th>Product</th><th style="width:110px">Qty</th><th style="width:130px">Unit price</th><th class="n" style="width:120px">Amount</th><th style="width:60px"></th></tr></thead>
            <tbody id="lines"></tbody>
            <tfoot><tr><td colspan="3" class="q-total">Total (before VAT is split out)</td><td class="q-total" id="total">0.00</td><td></td></tr></tfoot>
        </table>
        </div>
        <p class="hint">VAT is worked out with the same rules as a sale when you save.</p>

        <h2>Terms</h2>
        <div class="q-grid">
            <label>Valid until <input type="date" name="valid_until" value="<?php echo $h($form['valid_until']); ?>" min="<?php echo $h(date('Y-m-d')); ?>" required></label>
            <label style="grid-column:1/-1">Notes (shown on the quotation)
                <textarea name="notes" rows="3" maxlength="4000"><?php echo $h($form['notes']); ?></textarea>
            </label>
        </div>
        <p style="margin-top:16px"><button type="submit" class="ps-btn"><?php echo $quote ? 'Save changes' : 'Save quotation'; ?></button></p>
    </form>

<script>
const PRODUCTS = <?php echo json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const MAY_PRICE = <?php echo $mayPrice ? 'true' : 'false'; ?>;
let lines = <?php echo json_encode(array_values($form['lines']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const byId = {};
PRODUCTS.forEach(function (p) { byId[p.id] = p; });
function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function money(n) { return (Math.round((Number(n) || 0) * 100) / 100).toFixed(2); }
function render() {
    const body = document.getElementById('lines');
    let total = 0;
    body.innerHTML = lines.map(function (l, i) {
        const p = byId[l.product_id];
        const list = l.list_price != null && l.list_price !== '' ? l.list_price : (p ? p.price : '');
        const price = l.unit_price !== '' && l.unit_price != null ? l.unit_price : list;
        const amount = (Number(l.quantity) || 0) * (Number(price) || 0);
        total += amount;
        const changed = Number(price) !== Number(list);
        return '<tr><td>' + esc(l.product || (p ? p.name : l.product_id)) + '<div class="ps-muted">' + esc(l.product_id) + (changed ? ' · list ' + esc(money(list)) : '') + '</div>'
            + '<input type="hidden" name="product_id[]" value="' + esc(l.product_id) + '"></td>'
            + '<td><input type="number" name="quantity[]" step="0.001" min="0.001" value="' + esc(l.quantity) + '" oninput="setLine(' + i + ',\'quantity\',this.value)" required></td>'
            + '<td><input type="number" name="unit_price[]" step="0.01" min="0" value="' + esc(money(price)) + '"' + (MAY_PRICE ? '' : ' readonly title="Only a supervisor can change prices"') + ' oninput="setLine(' + i + ',\'unit_price\',this.value)"></td>'
            + '<td class="n">' + money(amount) + '</td>'
            + '<td><button type="button" class="ps-btn ps-btn-outline ps-btn-sm" onclick="removeLine(' + i + ')" aria-label="Remove">✕</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="ps-muted">No items yet. Search above to add products.</td></tr>';
    document.getElementById('total').textContent = money(total);
}
function setLine(i, key, value) {
    lines[i][key] = value;
    const row = document.getElementById('lines').rows[i];
    const p = byId[lines[i].product_id];
    const price = lines[i].unit_price !== '' && lines[i].unit_price != null ? lines[i].unit_price : (lines[i].list_price || (p ? p.price : 0));
    row.cells[3].textContent = money((Number(lines[i].quantity) || 0) * (Number(price) || 0));
    let total = 0;
    lines.forEach(function (l) {
        const pp = byId[l.product_id];
        const pr = l.unit_price !== '' && l.unit_price != null ? l.unit_price : (l.list_price || (pp ? pp.price : 0));
        total += (Number(l.quantity) || 0) * (Number(pr) || 0);
    });
    document.getElementById('total').textContent = money(total);
}
function removeLine(i) { lines.splice(i, 1); render(); }
function addProduct(id) {
    const p = byId[id];
    if (!p) return;
    const found = lines.find(function (l) { return l.product_id === id; });
    if (found) { found.quantity = String((Number(found.quantity) || 0) + 1); }
    else { lines.push({ product_id: p.id, product: p.name, quantity: '1', unit_price: p.price, list_price: p.price }); }
    const box = document.getElementById('prodSearch');
    box.value = '';
    document.getElementById('prodResults').hidden = true;
    render();
    box.focus();
}
document.getElementById('prodSearch').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    const out = document.getElementById('prodResults');
    if (q.length < 1) { out.hidden = true; return; }
    const hits = PRODUCTS.filter(function (p) {
        return p.name.toLowerCase().indexOf(q) !== -1 || p.id.toLowerCase() === q || (p.barcode && p.barcode === q);
    }).slice(0, 30);
    out.innerHTML = hits.length ? hits.map(function (p) {
        return '<button type="button" onclick="addProduct(' + esc(JSON.stringify(p.id)) + ')"><span>' + esc(p.name) + ' <span class="ps-muted">' + esc(p.id) + '</span></span><span>' + esc(p.price) + '</span></button>';
    }).join('') : '<div style="padding:8px 10px" class="ps-muted">No product matches.</div>';
    out.hidden = false;
});
document.getElementById('prodSearch').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const q = this.value.trim().toLowerCase();
    const exact = PRODUCTS.find(function (p) { return p.id.toLowerCase() === q || (p.barcode && p.barcode === q); });
    const first = exact || PRODUCTS.find(function (p) { return p.name.toLowerCase().indexOf(q) !== -1; });
    if (first) addProduct(first.id);
});
let custTimer = null;
document.getElementById('custSearch').addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(custTimer);
    const out = document.getElementById('custResults');
    if (q.length < 2) { out.hidden = true; return; }
    custTimer = setTimeout(function () {
        fetch('ajax_pos_customer_search.php?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                const rows = (d && d.rows) || [];
                out.innerHTML = rows.length ? rows.map(function (c) {
                    return '<button type="button" onclick=\'pickCustomer(' + esc(JSON.stringify(c)) + ')\'>' + esc(c.display_name) + ' <span class="ps-muted">' + esc(c.customer_code) + ' ' + esc(c.phone) + '</span></button>';
                }).join('') : '<div style="padding:8px 10px" class="ps-muted">No customer found. Type the name in "Customer name" instead.</div>';
                out.hidden = false;
            }).catch(function () { out.hidden = true; });
    }, 250);
});
function pickCustomer(c) {
    document.getElementById('custId').value = c.id;
    document.getElementById('custName').value = c.display_name;
    if (c.phone) document.getElementById('custPhone').value = c.phone;
    document.getElementById('custResults').hidden = true;
    document.getElementById('custSearch').value = '';
    document.getElementById('custLinked').hidden = false;
}
function unlinkCustomer() {
    document.getElementById('custId').value = '';
    document.getElementById('custLinked').hidden = true;
}
document.getElementById('qform').addEventListener('submit', function (e) {
    if (!lines.length) { e.preventDefault(); alert('Add at least one product.'); }
});
render();
</script>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
