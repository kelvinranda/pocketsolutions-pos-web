<?php
/**
 * 2026-10-20 — Exchange rates for the signed-in company (admins and managers; others may view).
 * "1 FROM = rate TO" from a start date/time. Rates are never edited: add a newer one to replace a rate going forward,
 * or retire a wrong one. Sales and other records keep the rate they were posted with.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/currency.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);
psFxEnsureSchema($conn);

$company = psFxSessionCompany();
$mayManage = psFxMayManageRates();
$error = '';
$notice = '';
if (!empty($_SESSION['ps_fx_notice'])) {
    $notice = (string)$_SESSION['ps_fx_notice'];
    unset($_SESSION['ps_fx_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } elseif (!$mayManage) {
        $error = 'Only an admin or manager can maintain exchange rates.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            $r = psFxRateAdd($conn, $company, $_POST);
            if (!empty($r['ok'])) {
                $_SESSION['ps_fx_notice'] = 'Rate saved: ' . psFxRateLabel(psFxNormalizeCode($_POST['from_code'] ?? ''), psFxNormalizeCode($_POST['to_code'] ?? ''), $r['rate'])
                    . ' from ' . $r['effective_from'] . '.';
                header('Location: exchange_rates.php');
                exit;
            }
            $error = (string)$r['error'];
        } elseif ($action === 'retire') {
            $r = psFxRateRetire($conn, $company, (int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
            if (!empty($r['ok'])) {
                $_SESSION['ps_fx_notice'] = 'Rate retired. It is no longer used for new transactions; records that used it keep it.';
                header('Location: exchange_rates.php');
                exit;
            }
            $error = (string)$r['error'];
        }
    }
}

$base = psFxBase($conn, $company);
$allowed = psFxCompanyCurrencies($conn, $company);
$rates = psFxRateList($conn, $company);
$now = date('Y-m-d H:i:s');

// Check a conversion (read-only helper on the page).
$check = null;
$cFrom = psFxNormalizeCode($_GET['check_from'] ?? '');
$cTo = psFxNormalizeCode($_GET['check_to'] ?? '');
$cAmt = trim((string)($_GET['check_amount'] ?? ''));
$cAt = psFxNormalizeDateTime($_GET['check_at'] ?? '') ?? $now;
if ($cFrom !== '' && $cTo !== '') {
    require_once __DIR__ . '/includes/sale_payment.php';
    $cents = psDecimalCents($cAmt === '' ? '1' : $cAmt);
    $r = psFxRate($conn, $company, $cFrom, $cTo, $cAt);
    if ($cents === null) {
        $check = ['ok' => false, 'error' => 'Enter a valid amount.'];
    } elseif (empty($r['ok'])) {
        $check = $r;
    } else {
        $out = psFxConvertCents($cents, $r['frac'], psFxDecimals($conn, $cTo));
        $check = ['ok' => true, 'text' => psFxFormat($conn, $cents / 100, $cFrom, true) . ' = ' . psFxFormat($conn, $out / 100, $cTo, true),
            'rate' => psFxRateLabel($cFrom, $cTo, $r['rate']), 'path' => $r['path']];
    }
}
$audit = psFxAuditRows($conn, $company, 30);
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$stateLabel = ['CURRENT' => 'In use', 'FUTURE' => 'Starts later', 'SUPERSEDED' => 'Replaced', 'RETIRED' => 'Retired'];
$stateBadge = ['CURRENT' => 'ps-badge-success', 'FUTURE' => 'ps-badge-warning', 'SUPERSEDED' => '', 'RETIRED' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exchange Rates</title>
    <?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
    <style>
        .fx-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .fx-grid label { display: flex; flex-direction: column; gap: 6px; }
        .fx-mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
        .fx-preview { font-size: 1.1rem; font-weight: 700; margin: 6px 0 0; }
        .fx-retire { display: flex; gap: 6px; }
        .fx-retire input { min-width: 120px; }
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Exchange Rates</h1>
    <p class="ps-page-lead">Base currency: <strong><?php echo $h($base); ?></strong> · currencies in use: <?php echo $h(implode(', ', array_keys($allowed))); ?>.
        A rate reads <span class="fx-mono">1 FROM = rate TO</span> and applies from its start time. Each sale, payment or document keeps the rate it was posted with;
        later rates never change past records.</p>
    <?php if ($notice !== ''): ?><div class="ps-alert ps-alert-success" role="status"><?php echo $h($notice); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div><?php endif; ?>
    <?php if (count($allowed) < 2): ?>
        <div class="ps-alert ps-alert-info">Only <?php echo $h($base); ?> is enabled for this company. A Super Admin can allow more currencies; rates are needed only then.</div>
    <?php endif; ?>

    <?php if ($mayManage && count($allowed) >= 2): ?>
    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Add a rate</h2>
            <p class="ps-card-desc">To change a rate, add the new one with its start time; the old one stays in history. Rates cannot be edited or deleted.</p></div>
        <div class="ps-card-content">
            <form method="post" id="fxAdd">
                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="add">
                <div class="fx-grid">
                    <label><span class="ps-label">1 unit of</span><select class="ps-input" name="from_code" id="fxFrom"><?php foreach ($allowed as $c => $row): ?><option<?php echo $c === $base ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></select></label>
                    <label><span class="ps-label">equals (rate)</span><input class="ps-input fx-mono" name="rate" id="fxRate" required inputmode="decimal" placeholder="e.g. 26.50"></label>
                    <label><span class="ps-label">units of</span><select class="ps-input" name="to_code" id="fxTo"><?php $i = 0; foreach ($allowed as $c => $row): ?><option<?php echo ($c !== $base && $i++ === 0) ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></select></label>
                    <label><span class="ps-label">Starts</span><input class="ps-input" type="datetime-local" name="effective_from" value="<?php echo $h(date('Y-m-d\TH:i')); ?>"></label>
                    <label><span class="ps-label">Source / reference</span><input class="ps-input" name="source" maxlength="120" placeholder="e.g. RBZ interbank"></label>
                    <label><span class="ps-label">Note</span><input class="ps-input" name="note" maxlength="250"></label>
                </div>
                <p class="fx-preview" id="fxPreview">1 <?php echo $h($base); ?> = … </p>
                <div style="margin-top:10px"><button class="ps-btn ps-btn-default" type="submit">Save rate</button></div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var f = document.getElementById('fxFrom'), t = document.getElementById('fxTo'), r = document.getElementById('fxRate'), p = document.getElementById('fxPreview');
        function show() { p.textContent = '1 ' + f.value + ' = ' + (r.value.trim() || '…') + ' ' + t.value + (f.value === t.value ? '  (choose two different currencies)' : ''); }
        [f, t, r].forEach(function (el) { el.addEventListener('input', show); el.addEventListener('change', show); }); show();
    })();
    </script>
    <?php endif; ?>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Rates and history</h2>
            <p class="ps-card-desc">"In use" is the rate applied now for each pair. A pair without its own rate is converted with the reverse rate, or through <?php echo $h($base); ?>.</p></div>
        <div class="ps-card-content"><div class="ps-table-wrap"><table>
            <thead><tr><th>Rate</th><th>Starts</th><th>State</th><th>Source / note</th><th>Added by</th><th>Used</th><?php echo $mayManage ? '<th></th>' : ''; ?></tr></thead>
            <tbody>
            <?php if (!$rates): ?><tr><td colspan="7">No rates yet.</td></tr><?php endif; ?>
            <?php foreach ($rates as $r): ?>
                <tr>
                    <td class="fx-mono"><strong><?php echo $h(psFxRateLabel($r['from_code'], $r['to_code'], (string)$r['rate'])); ?></strong> <span class="hint">#<?php echo (int)$r['id']; ?></span></td>
                    <td><?php echo $h(substr($r['effective_from'], 0, 16)); ?></td>
                    <td><span class="ps-badge <?php echo $stateBadge[$r['state']]; ?>"><?php echo $h($stateLabel[$r['state']]); ?></span>
                        <?php if ($r['state'] === 'RETIRED'): ?><div class="hint">by <?php echo $h($r['retired_by']); ?> · <?php echo $h(substr((string)$r['retired_at'], 0, 16)); ?></div><?php endif; ?></td>
                    <td><?php echo $h(trim($r['source'] . ($r['note'] !== '' ? ' · ' . $r['note'] : ''))); ?></td>
                    <td><?php echo $h($r['created_by']); ?><div class="hint"><?php echo $h(substr($r['created_at'], 0, 16)); ?></div></td>
                    <td><?php echo $r['used'] ? 'Yes' : '—'; ?></td>
                    <?php if ($mayManage): ?><td>
                        <?php if ($r['state'] !== 'RETIRED'): ?>
                            <form method="post" class="fx-retire" onsubmit="return confirm('Retire this rate? It will no longer be used for new transactions.');">
                                <?php echo psCsrfField(); ?><input type="hidden" name="action" value="retire"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <input class="ps-input" name="reason" maxlength="200" placeholder="Reason">
                                <button class="ps-btn ps-btn-outline ps-btn-sm" type="submit">Retire</button>
                            </form>
                        <?php endif; ?>
                    </td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Check a conversion</h2>
            <p class="ps-card-desc">Shows which rate the system would use at a given moment — the same rule sales and documents use.</p></div>
        <div class="ps-card-content">
            <form method="get" class="fx-grid">
                <label><span class="ps-label">Amount</span><input class="ps-input fx-mono" name="check_amount" value="<?php echo $h($cAmt !== '' ? $cAmt : '1'); ?>"></label>
                <label><span class="ps-label">From</span><select class="ps-input" name="check_from"><?php foreach ($allowed as $c => $row): ?><option<?php echo $c === ($cFrom ?: $base) ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></select></label>
                <label><span class="ps-label">To</span><select class="ps-input" name="check_to"><?php foreach ($allowed as $c => $row): ?><option<?php echo $c === $cTo ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></select></label>
                <label><span class="ps-label">At</span><input class="ps-input" type="datetime-local" name="check_at" value="<?php echo $h(str_replace(' ', 'T', substr($cAt, 0, 16))); ?>"></label>
                <label><span class="ps-label">&nbsp;</span><button class="ps-btn ps-btn-outline" type="submit">Convert</button></label>
            </form>
            <?php if ($check !== null): ?>
                <?php if (!empty($check['ok'])): ?>
                    <p class="fx-preview"><?php echo $h($check['text']); ?></p>
                    <p class="hint">Rate used: <?php echo $h($check['rate']); ?> · <?php echo $h($check['path']); ?></p>
                <?php else: ?>
                    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($check['error']); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="ps-card">
        <div class="ps-card-header"><h2 class="ps-card-title">Change history</h2></div>
        <div class="ps-card-content"><div class="ps-table-wrap"><table>
            <thead><tr><th>When</th><th>Who</th><th>Change</th><th>Details</th></tr></thead>
            <tbody>
            <?php if (!$audit): ?><tr><td colspan="4">No changes yet.</td></tr><?php endif; ?>
            <?php foreach ($audit as $a): ?>
                <tr><td><?php echo $h($a['at']); ?></td><td><?php echo $h($a['actor']); ?></td><td><?php echo $h($a['action']); ?></td>
                    <td class="hint"><?php echo $h(mb_substr((string)($a['after_json'] ?? $a['before_json']), 0, 180)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>
<?php ps_close(); ?>
</body>
</html>
