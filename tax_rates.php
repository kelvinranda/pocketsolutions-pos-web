<?php
/**
 * Phase 12 — Tax rate configuration (admin).
 * Rates are configurable; no verified statutory rate is hardcoded.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psTaxRequireAuthRedirect();
psEnsureTaxModule($conn);

$isAdmin = psTaxMayConfigure();
$message = '';
$error = '';
$action = '';
$actor = trim((string)($_SESSION['logger'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
        $action = '';
    } else {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'set_enforcement') {
        $res = psTaxSetEnforcement($conn, (string)($_POST['enforcement'] ?? ''), $actor);
        if (!empty($res['ok'])) {
            $message = 'Enforcement setting saved. REQUIRED blocks sales without product tax codes.';
        } else {
            $error = (string)($res['error'] ?? 'Could not save setting.');
        }
    } elseif ($action === 'create') {
        $res = psTaxCreateRate($conn, [
            'code' => $_POST['code'] ?? '',
            'description' => $_POST['description'] ?? '',
            'classification' => $_POST['classification'] ?? '',
            'rate_percent' => $_POST['rate_percent'] ?? '',
            'pricing_mode' => $_POST['pricing_mode'] ?? '',
            'effective_from' => $_POST['effective_from'] ?? '',
            'effective_to' => $_POST['effective_to'] ?? '',
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ], $actor);
        if (!empty($res['ok'])) {
            $message = 'Tax rate created.';
        } else {
            $error = (string)($res['error'] ?? 'Could not create rate.');
        }
    } elseif ($action === 'update') {
        $res = psTaxUpdateRate($conn, (int)($_POST['id'] ?? 0), [
            'description' => $_POST['description'] ?? '',
            'classification' => $_POST['classification'] ?? '',
            'rate_percent' => $_POST['rate_percent'] ?? '',
            'pricing_mode' => $_POST['pricing_mode'] ?? '',
            'effective_from' => $_POST['effective_from'] ?? '',
            'effective_to' => $_POST['effective_to'] ?? '',
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ], $actor);
        if (!empty($res['ok'])) {
            $message = 'Tax rate updated.';
            psSecurityAudit($conn, 'TAX_RATE_UPDATE', [
                'summary' => 'Tax rate updated',
                'target_type' => 'tax_rate',
                'target_id' => (string)(int)($_POST['id'] ?? 0),
            ]);
        } else {
            $error = (string)($res['error'] ?? 'Could not update rate.');
        }
    } elseif ($action === 'set_enforcement' || $action === 'create') {
        // audited below via success paths
    }
    if ($action === 'set_enforcement' && $message !== '') {
        psSecurityAudit($conn, 'TAX_ENFORCEMENT', [
            'summary' => 'Tax enforcement updated',
            'target_type' => 'tax_config',
            'after' => ['enforcement' => (string)($_POST['enforcement'] ?? '')],
        ]);
    }
    if ($action === 'create' && $message !== '') {
        psSecurityAudit($conn, 'TAX_RATE_CREATE', [
            'summary' => 'Tax rate created',
            'target_type' => 'tax_rate',
            'after' => ['code' => (string)($_POST['code'] ?? '')],
        ]);
    }
    } // csrf ok
}

$rates = psTaxListRates($conn, false);
$enforcement = psTaxGetEnforcement($conn);
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
    <title>Tax Rates</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Tax Rates'); ?>
<h1>Tax Rates</h1>
<p class="ps-page-lead">Tax and VAT rates for products. Zero-rated and exempt are separate classes, and each rate says whether prices include tax.</p>

<div class="ps-link-row" aria-label="Related pages">
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="tax_reports.php">Tax Reports</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="tax_audit_history.php">Tax Audit</a>
    <a class="ps-btn ps-btn-outline ps-btn-sm" href="accounting_mappings.php">Accounting Mappings</a>
</div>

<?php if ($message !== ''): ?>
    <div class="ps-alert ps-alert-success" role="status"><?php echo $h($message); ?></div>
<?php endif; ?>
<?php if ($error !== '' && !in_array($action, ['create', 'update'], true)): ?>
    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="ps-card">
    <div class="ps-card-header">
        <h2 class="ps-card-title">Enforcement</h2>
        <p class="ps-card-desc">Control whether products must have an effective tax code before sale.</p>
    </div>
    <div class="ps-card-content">
        <form method="post" class="ps-form-stack">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="action" value="set_enforcement">
            <div class="ps-field">
                <label class="ps-label" for="tax_enforcement">Sales tax enforcement</label>
                <select class="ps-input" id="tax_enforcement" name="enforcement">
                    <option value="OFF" <?php echo $enforcement === 'OFF' ? 'selected' : ''; ?>>Off: products without a tax code can still be sold (no tax is added)</option>
                    <option value="REQUIRED" <?php echo $enforcement === 'REQUIRED' ? 'selected' : ''; ?>>Required: block the sale when a product has no current tax code</option>
                </select>
            </div>
            <div class="ps-form-actions">
                <button class="ps-btn ps-btn-default" type="submit">Save enforcement</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
    <div class="ps-alert ps-alert-info" role="status">View only. An administrator must configure rates and enforcement.</div>
    <p class="ps-muted">Current enforcement: <strong><?php echo $h($enforcement); ?></strong></p>
<?php endif; ?>

<?php
$classLabels = ['TAXABLE' => 'Taxable', 'ZERO_RATED' => 'Zero-rated', 'EXEMPT' => 'Exempt'];
$modeLabels = ['INCLUSIVE' => 'Inclusive', 'EXCLUSIVE' => 'Exclusive'];
$fmtDate = static function ($d): string {
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date('d M Y', $ts) : $d;
};
?>
<div class="ps-card">
    <div class="ps-card-header ps-card-header-row">
        <div>
            <h2 class="ps-card-title">Configured rates</h2>
            <p class="ps-card-desc">Tax and VAT rates available to products.</p>
        </div>
        <?php if ($isAdmin): ?>
        <button class="ps-btn ps-btn-default ps-btn-sm" type="button" data-rate-new>Add rate</button>
        <?php endif; ?>
    </div>
    <div class="ps-card-content ps-card-flush">
    <div class="ps-table-wrap">
    <table class="ps-table ps-rates-table">
        <thead>
            <tr>
                <th>Code</th><th>Description</th><th>Class</th><th class="num">Rate</th>
                <th>Pricing</th><th>Effective</th><th>Status</th><?php if ($isAdmin): ?><th class="act"><span class="ps-sr-only">Actions</span></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rates): ?>
            <tr><td colspan="<?php echo $isAdmin ? '8' : '7'; ?>" class="ps-muted">No tax rates configured yet.<?php echo $isAdmin ? ' Use Add rate to create one.' : ''; ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rates as $r): ?>
            <?php
            $rid = (int)$r['id'];
            $active = (int)$r['is_active'] === 1;
            $from = $fmtDate($r['effective_from'] ?? '');
            $to = $fmtDate($r['effective_to'] ?? '');
            $rateTxt = rtrim(rtrim(number_format((float)$r['rate_percent'], 2, '.', ''), '0'), '.') . '%';
            $payload = [
                'id' => $rid,
                'code' => (string)$r['code'],
                'description' => (string)$r['description'],
                'classification' => (string)$r['classification'],
                'rate_percent' => rtrim(rtrim(number_format((float)$r['rate_percent'], 4, '.', ''), '0'), '.'),
                'pricing_mode' => (string)$r['pricing_mode'],
                'effective_from' => (string)($r['effective_from'] ?? ''),
                'effective_to' => (string)($r['effective_to'] ?? ''),
                'is_active' => $active ? 1 : 0,
            ];
            ?>
            <tr>
                <td><strong><?php echo $h($r['code']); ?></strong></td>
                <td><?php echo $h($r['description']); ?></td>
                <td><?php echo $h($classLabels[$r['classification']] ?? $r['classification']); ?></td>
                <td class="num"><?php echo $h($rateTxt); ?></td>
                <td><?php echo $h($modeLabels[$r['pricing_mode']] ?? $r['pricing_mode']); ?></td>
                <td class="nowrap"><?php echo $h($from); ?><?php echo $to !== '' ? ' – ' . $h($to) : ' onwards'; ?></td>
                <td><span class="ps-badge <?php echo $active ? 'ps-badge-success' : 'ps-badge-secondary'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span></td>
                <?php if ($isAdmin): ?>
                <td class="act">
                    <button class="ps-btn ps-btn-outline ps-btn-sm" type="button"
                            data-rate-edit="<?php echo $h(json_encode($payload)); ?>"
                            aria-label="Edit rate <?php echo $h($r['code']); ?>">Edit</button>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<?php
// Reopen the dialog with what was typed when a save fails.
$reopen = null;
if ($error !== '' && in_array($action ?? '', ['create', 'update'], true)) {
    $reopen = [
        'id' => ($action === 'update') ? (int)($_POST['id'] ?? 0) : 0,
        'code' => (string)($_POST['code'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
        'classification' => (string)($_POST['classification'] ?? 'TAXABLE'),
        'rate_percent' => (string)($_POST['rate_percent'] ?? ''),
        'pricing_mode' => (string)($_POST['pricing_mode'] ?? 'INCLUSIVE'),
        'effective_from' => (string)($_POST['effective_from'] ?? ''),
        'effective_to' => (string)($_POST['effective_to'] ?? ''),
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        'error' => $error,
    ];
    if ($action === 'update') {
        foreach ($rates as $r) {
            if ((int)$r['id'] === $reopen['id']) {
                $reopen['code'] = (string)$r['code'];
            }
        }
    }
}
?>
<dialog class="ps-dialog" id="rateDialog" aria-labelledby="rateDialogTitle">
    <form method="post" class="ps-form-stack" id="rateForm">
        <div class="ps-dialog-head">
            <h2 class="ps-dialog-title" id="rateDialogTitle">Edit rate</h2>
            <button class="ps-btn ps-btn-ghost ps-btn-sm ps-btn-icon" type="button" data-dialog-close aria-label="Close">&times;</button>
        </div>
        <div class="ps-alert ps-alert-destructive" role="alert" id="rateDialogError" hidden></div>
        <?php echo psCsrfField(); ?>
        <input type="hidden" name="action" id="rate_action" value="update">
        <input type="hidden" name="id" id="rate_id" value="">
        <div class="ps-dialog-grid">
            <div class="ps-field">
                <label class="ps-label" for="rate_code">Code</label>
                <input class="ps-input" id="rate_code" name="code" maxlength="32" placeholder="e.g. STD" autocomplete="off" required>
                <p class="ps-field-hint" id="rate_code_hint" hidden>The code can't be changed after the rate is created.</p>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="rate_description">Description</label>
                <input class="ps-input" id="rate_description" name="description" maxlength="250" autocomplete="off">
            </div>
            <div class="ps-field">
                <label class="ps-label" for="rate_classification">Classification</label>
                <select class="ps-input" id="rate_classification" name="classification" required>
                    <option value="TAXABLE">Taxable</option>
                    <option value="ZERO_RATED">Zero-rated</option>
                    <option value="EXEMPT">Exempt</option>
                </select>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="rate_percent">Rate %</label>
                <input class="ps-input" id="rate_percent" name="rate_percent" required inputmode="decimal" placeholder="0 for zero-rated or exempt">
            </div>
            <div class="ps-field">
                <label class="ps-label" for="rate_pricing_mode">Pricing</label>
                <select class="ps-input" id="rate_pricing_mode" name="pricing_mode" required>
                    <option value="INCLUSIVE">Inclusive (price includes tax)</option>
                    <option value="EXCLUSIVE">Exclusive (tax added on top)</option>
                </select>
            </div>
            <div class="ps-field">
                <span class="ps-label">Status</span>
                <label class="ps-confirm-row">
                    <input type="checkbox" name="is_active" id="rate_active" value="1">
                    <span>Active</span>
                </label>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="rate_from">Effective from</label>
                <input class="ps-input" id="rate_from" type="date" name="effective_from" required>
            </div>
            <div class="ps-field">
                <label class="ps-label" for="rate_to">Effective to <span class="ps-muted">(optional)</span></label>
                <input class="ps-input" id="rate_to" type="date" name="effective_to">
            </div>
        </div>
        <div class="ps-dialog-actions">
            <button class="ps-btn ps-btn-outline" type="button" data-dialog-close>Cancel</button>
            <button class="ps-btn ps-btn-default" type="submit" id="rateSubmit">Save changes</button>
        </div>
    </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('rateDialog');
  if (!dlg) return;
  var $ = function (id) { return document.getElementById(id); };
  function fill(d, isNew) {
    $('rate_action').value = isNew ? 'create' : 'update';
    $('rate_id').value = isNew ? '' : (d.id || '');
    $('rate_code').value = d.code || '';
    $('rate_code').readOnly = !isNew;
    $('rate_code_hint').hidden = isNew;
    $('rate_description').value = d.description || '';
    $('rate_classification').value = d.classification || 'TAXABLE';
    $('rate_percent').value = (d.rate_percent !== undefined && d.rate_percent !== '') ? d.rate_percent : (isNew ? '0' : '');
    $('rate_pricing_mode').value = d.pricing_mode || 'INCLUSIVE';
    $('rate_from').value = d.effective_from || '';
    $('rate_to').value = d.effective_to || '';
    $('rate_active').checked = isNew && d.is_active === undefined ? true : !!Number(d.is_active);
    $('rateDialogTitle').textContent = isNew ? 'Add rate' : 'Edit rate ' + (d.code || '');
    $('rateSubmit').textContent = isNew ? 'Create rate' : 'Save changes';
    ['rate_classification', 'rate_pricing_mode'].forEach(function (id) {
      $(id).dispatchEvent(new Event('change', { bubbles: true }));
    });
    var err = $('rateDialogError');
    err.textContent = d.error || '';
    err.hidden = !d.error;
  }
  function open(d, isNew) {
    fill(d, isNew);
    if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    (isNew ? $('rate_code') : $('rate_description')).focus();
  }
  function close() { if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); } }
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-rate-edit],[data-rate-new],[data-dialog-close]') : null;
    if (!t) return;
    if (t.hasAttribute('data-dialog-close')) { close(); return; }
    if (t.hasAttribute('data-rate-new')) { open({}, true); return; }
    try { open(JSON.parse(t.getAttribute('data-rate-edit')), false); } catch (err) {}
  });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
  var reopen = <?php echo json_encode($reopen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  if (reopen) { open(reopen, !reopen.id); }
})();
</script>
<?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
