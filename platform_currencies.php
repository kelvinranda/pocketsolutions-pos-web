<?php
/**
 * 2026-10-20 — Super Admin: currency catalogue, each company's base currency and the currencies it may transact in.
 * Exchange rates are kept by each company's admins/managers on exchange_rates.php.
 * Nothing here deletes or rewrites history: disabling only stops new transactions and new rates.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';
require_once __DIR__ . '/includes/currency.php';

$conn = psPlatformPageBoot();
if (!psFxEnsureSchema($conn)) {
    exit('Currencies are not available right now.');
}
$errors = [];
$company = trim((string)($_GET['company'] ?? $_POST['company'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back = 'platform_currencies.php' . ($company !== '' ? '?company=' . rawurlencode($company) : '');
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } else {
        $r = ['ok' => false, 'error' => 'Unknown action.'];
        $msg = '';
        if ($action === 'add' || $action === 'edit') {
            $r = psFxCurrencySave($conn, $_POST, $action === 'add');
            $msg = $action === 'add' ? 'Currency added.' : 'Currency saved.';
        } elseif ($action === 'disable' || $action === 'enable') {
            $r = psFxCurrencySetStatus($conn, (string)($_POST['code'] ?? ''), $action === 'enable');
            $msg = $action === 'enable' ? 'Currency enabled.' : 'Currency disabled for new transactions and rates. History is kept.';
        } elseif ($action === 'base') {
            $r = psFxSetBase($conn, $company, (string)($_POST['code'] ?? ''));
            $msg = 'Base currency saved.';
        } elseif ($action === 'company_on' || $action === 'company_off') {
            $r = psFxSetCompanyCurrency($conn, $company, (string)($_POST['code'] ?? ''), $action === 'company_on');
            $msg = $action === 'company_on' ? 'Currency allowed for this company.' : 'Currency stopped for this company\'s new transactions.';
        }
        if (!empty($r['ok'])) {
            psPlatformAudit($conn, 'CURRENCY_' . strtoupper($action), $company !== '' ? $company : null, '', ['code' => (string)($_POST['code'] ?? '')]);
            psPlatformFlash('ok', $msg);
            header('Location: ' . $back);
            exit;
        }
        $errors[] = (string)($r['error'] ?? 'The change could not be saved.');
    }
}

$catalogue = psFxCurrencies($conn);
$companies = [];
$res = mysqli_query($conn, 'SELECT company_name, display_name FROM companies ORDER BY display_name, company_name');
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $companies[] = $row;
}
$base = $company !== '' ? psFxBase($conn, $company) : '';
$hasTx = $company !== '' ? psFxCompanyHasTransactions($conn, $company) : false;
$baseRow = null;
if ($company !== '') {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM company_base_currency WHERE company_name = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $baseRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
}
$audit = psFxAuditRows($conn, $company !== '' ? $company : null, 40);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currencies</title>
    <?php ps_head(); psPlatformPageCss(); ?>
    <style>
        html[data-theme] body.ps-app .cur-code { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; }
        html[data-theme] body.ps-app .cur-off td { opacity: .6; }
        html[data-theme] body.ps-app .cur-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        html[data-theme] body.ps-app .cur-actions form { display: inline; }
        html[data-theme] body.ps-app .cur-edit { display: grid; grid-template-columns: 1.4fr .7fr .6fr .8fr auto; gap: 6px; align-items: center; }
        @media (max-width: 760px) { html[data-theme] body.ps-app .cur-edit { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Currencies'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Currencies</h1>
        <p class="ps-page-lead">The currency list for every company (ISO 4217 codes), each company's base currency, and which currencies it may sell and buy in.
            Exchange rates are kept by each company's admins and managers on its Exchange Rates page.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Currency list</h2>
            <p class="ps-card-desc">Disabling a currency stops new transactions and new rates in it. Past sales, balances, rates and exports are kept.
                Decimal places can only change while nothing uses the currency.</p></div>
        <div class="ps-card-content">
            <div class="ps-table-wrap"><table>
                <thead><tr><th>Code</th><th>Details (name · symbol · decimals · symbol position)</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($catalogue as $c): ?>
                    <tr class="<?php echo $c['status'] === 'ACTIVE' ? '' : 'cur-off'; ?>">
                        <td class="cur-code"><?php echo $h($c['code']); ?></td>
                        <td>
                            <form method="post" class="cur-edit">
                                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="edit"><input type="hidden" name="code" value="<?php echo $h($c['code']); ?>">
                                <input class="ps-input" name="name" value="<?php echo $h($c['name']); ?>" maxlength="80" required aria-label="Name">
                                <input class="ps-input" name="symbol" value="<?php echo $h($c['symbol']); ?>" maxlength="8" aria-label="Symbol">
                                <select class="ps-input" name="decimals" aria-label="Decimal places"><?php foreach (['0', '1', '2'] as $d): ?><option value="<?php echo $d; ?>"<?php echo (string)$c['decimals'] === $d ? ' selected' : ''; ?>><?php echo $d; ?> dp</option><?php endforeach; ?></select>
                                <select class="ps-input" name="symbol_position" aria-label="Symbol position"><option value="before"<?php echo $c['symbol_position'] !== 'after' ? ' selected' : ''; ?>>Before</option><option value="after"<?php echo $c['symbol_position'] === 'after' ? ' selected' : ''; ?>>After</option></select>
                                <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Save</button>
                            </form>
                            <div class="hint">Shown as <?php echo $h(psFxFormat($conn, 1234.5, $c['code'])); ?></div>
                        </td>
                        <td><?php echo $c['status'] === 'ACTIVE' ? '<span class="ps-badge ps-badge-success">Active</span>' : '<span class="ps-badge">Disabled</span>'; ?></td>
                        <td class="cur-actions">
                            <form method="post"><?php echo psCsrfField(); ?><input type="hidden" name="code" value="<?php echo $h($c['code']); ?>">
                                <input type="hidden" name="action" value="<?php echo $c['status'] === 'ACTIVE' ? 'disable' : 'enable'; ?>">
                                <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"><?php echo $c['status'] === 'ACTIVE' ? 'Disable' : 'Enable'; ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <h3 style="margin-top:18px">Add a currency</h3>
            <form method="post" class="pf-form">
                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="add">
                <div class="pf-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
                    <label class="ps-field"><span class="ps-label">ISO code</span><input class="ps-input" name="code" required maxlength="3" placeholder="e.g. BWP" pattern="[A-Za-z]{3}"></label>
                    <label class="ps-field"><span class="ps-label">Name</span><input class="ps-input" name="name" required maxlength="80" placeholder="e.g. Botswana Pula"></label>
                    <label class="ps-field"><span class="ps-label">Symbol</span><input class="ps-input" name="symbol" maxlength="8" placeholder="e.g. P"></label>
                    <label class="ps-field"><span class="ps-label">Decimal places</span><select class="ps-input" name="decimals"><option>2</option><option>1</option><option>0</option></select></label>
                    <label class="ps-field"><span class="ps-label">Symbol position</span><select class="ps-input" name="symbol_position"><option value="before">Before amount</option><option value="after">After amount</option></select></label>
                </div>
                <div><button class="ps-btn ps-btn-default" type="submit">Add currency</button></div>
            </form>
        </div>
    </div>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Company currencies</h2>
            <p class="ps-card-desc">Pick a company to set its base currency (used for its consolidated reports and accounting) and the currencies its staff may use.</p></div>
        <div class="ps-card-content">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <select class="ps-input" name="company" style="min-width:260px">
                    <option value="">Choose a company…</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo $h($c['company_name']); ?>"<?php echo $c['company_name'] === $company ? ' selected' : ''; ?>><?php echo $h(($c['display_name'] ?: $c['company_name'])); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="ps-btn ps-btn-outline" type="submit">Show</button>
            </form>
            <?php if ($company !== ''): ?>
                <p style="margin-top:14px"><strong>Base currency:</strong> <?php echo $h($base); ?>
                    <?php echo $baseRow ? '· set by ' . $h($baseRow['set_by']) . ' on ' . $h($baseRow['set_at']) : '· not chosen yet (the legacy currency ' . $h(psFxLegacyCurrency()) . ' is used)'; ?></p>
                <?php if ($hasTx): ?>
                    <div class="ps-alert ps-alert-info">This company already has transactions, so its base currency is locked. Changing it later needs a planned conversion (see the multi-currency guide); history is never re-valued automatically.</div>
                <?php endif; ?>
                <div class="ps-table-wrap"><table>
                    <thead><tr><th>Currency</th><th>Status in list</th><th>This company</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($catalogue as $c):
                        $isBase = $c['code'] === $base;
                        $on = $isBase || psFxCompanyCurrencyEnabled($conn, $company, $c['code']); ?>
                        <tr>
                            <td><span class="cur-code"><?php echo $h($c['code']); ?></span> <?php echo $h($c['name']); ?></td>
                            <td><?php echo $c['status'] === 'ACTIVE' ? 'Active' : 'Disabled'; ?></td>
                            <td><?php echo $isBase ? '<span class="ps-badge ps-badge-success">Base</span>' : ($on ? '<span class="ps-badge ps-badge-success">Allowed</span>' : 'Not allowed'); ?></td>
                            <td class="cur-actions">
                                <?php if (!$isBase): ?>
                                    <form method="post"><?php echo psCsrfField(); ?><input type="hidden" name="company" value="<?php echo $h($company); ?>"><input type="hidden" name="code" value="<?php echo $h($c['code']); ?>">
                                        <input type="hidden" name="action" value="<?php echo $on ? 'company_off' : 'company_on'; ?>">
                                        <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit"<?php echo (!$on && $c['status'] !== 'ACTIVE') ? ' disabled' : ''; ?>><?php echo $on ? 'Stop' : 'Allow'; ?></button></form>
                                    <?php if (!$hasTx && $c['status'] === 'ACTIVE'): ?>
                                        <form method="post"><?php echo psCsrfField(); ?><input type="hidden" name="company" value="<?php echo $h($company); ?>"><input type="hidden" name="code" value="<?php echo $h($c['code']); ?>">
                                            <input type="hidden" name="action" value="base"><button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Make base</button></form>
                                    <?php endif; ?>
                                <?php elseif (!$baseRow): ?>
                                    <form method="post"><?php echo psCsrfField(); ?><input type="hidden" name="company" value="<?php echo $h($company); ?>"><input type="hidden" name="code" value="<?php echo $h($c['code']); ?>">
                                        <input type="hidden" name="action" value="base"><button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Confirm as base</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Currency history<?php echo $company !== '' ? ' · ' . $h($company) : ''; ?></h2></div>
        <div class="ps-card-content"><div class="ps-table-wrap"><table>
            <thead><tr><th>When</th><th>Who</th><th>Change</th><th>Item</th><th>Before</th><th>After</th></tr></thead>
            <tbody>
            <?php if (!$audit): ?><tr><td colspan="6">No changes yet.</td></tr><?php endif; ?>
            <?php foreach ($audit as $a): ?>
                <tr><td><?php echo $h($a['at']); ?></td><td><?php echo $h($a['actor']); ?></td><td><?php echo $h($a['action']); ?></td>
                    <td><?php echo $h(($a['company_name'] ? $a['company_name'] . ' · ' : '') . $a['entity'] . ' ' . $a['entity_key']); ?></td>
                    <td class="hint"><?php echo $h(mb_substr((string)$a['before_json'], 0, 140)); ?></td><td class="hint"><?php echo $h(mb_substr((string)$a['after_json'], 0, 140)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
