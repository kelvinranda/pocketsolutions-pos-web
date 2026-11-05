<?php
/**
 * 2026-10-24 — Loyalty programme rules (admin; page_access keeps non-admins out).
 * Rules apply from the moment they are saved. Past sales are never granted or charged points retroactively.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/loyalty.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/sale_payment.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psSecurityEnforceWebSession($conn, ['redirect' => true, 'touch' => true]);

$error = '';
$notice = !empty($_GET['saved']) ? 'Loyalty settings saved.' : '';
$isAdmin = psCashControlsLevel() === 'admin';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $r = psLoyaltySaveConfig($conn, $_POST, psCashControlsUser());
        if (!empty($r['ok'])) {
            header('Location: loyalty_settings.php?saved=1');
            exit;
        }
        $error = (string)$r['error'];
    }
}
$cfg = $error !== '' ? array_merge(psLoyaltyConfig($conn), array_intersect_key($_POST, psLoyaltyDefaults()), ['enabled' => !empty($_POST['enabled']) ? 1 : 0]) : psLoyaltyConfig($conn);
$base = psFxBase($conn, psLoyaltyCompany());
$excluded = array_filter(array_map('trim', explode(',', strtoupper((string)(is_array($cfg['excluded_methods']) ? implode(',', $cfg['excluded_methods']) : $cfg['excluded_methods'])))));
$stats = ['customers' => 0, 'outstanding' => 0];
$res = mysqli_query($conn, "SELECT COUNT(DISTINCT customer_master_id) AS c, COALESCE(SUM(points), 0) AS p FROM loyalty_ledger WHERE company_name = '" . mysqli_real_escape_string($conn, psLoyaltyCompany()) . "'");
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $stats = ['customers' => (int)$row['c'], 'outstanding' => (int)$row['p']];
}
mysqli_close($conn);
$h = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Programme</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Loyalty Programme</h1>
    <p class="ps-page-lead">Customers earn points on completed sales and can use them as a discount at the till.
        Amounts are in the company base currency (<?php echo $h($base); ?>); sales in other currencies count at the sale's own exchange rate.
        Points are kept in a ledger — every point earned, used, taken back, expired or adjusted is a separate entry.</p>

    <?php if ($error !== ''): ?><div class="ps-alert ps-alert-destructive"><?php echo $h($error); ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="ps-alert ps-alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

    <div class="ps-card" style="padding:14px 16px;margin:12px 0">
        <strong><?php echo (int)$stats['customers']; ?></strong> customers with points ·
        <strong><?php echo (int)$stats['outstanding']; ?></strong> points outstanding
        <?php if (!empty($cfg['updated_by'])): ?><div class="ps-field-hint">Last changed by <?php echo $h($cfg['updated_by']); ?> on <?php echo $h($cfg['updated_at']); ?></div><?php endif; ?>
    </div>

    <form method="post" action="loyalty_settings.php" class="ps-form-stack">
        <?php echo psCsrfField(); ?>

        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Programme</h2>
                <p class="ps-card-desc">Master switch for this company. Off stops earn and redeem; balances stay.</p>
            </div>
            <div class="ps-card-content" style="padding:0">
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="loyalty_enabled">Programme switched on</label>
                        <p class="ps-field-hint">When off, no points are earned or used. Customer balances and history stay as they are.</p>
                    </div>
                    <div class="ps-setting-control">
                        <label class="ps-field" style="display:inline-flex;gap:8px;align-items:center">
                            <input type="checkbox" id="loyalty_enabled" name="enabled" value="1"<?php echo !empty($cfg['enabled']) ? ' checked' : ''; ?><?php echo $isAdmin ? '' : ' disabled'; ?>>
                            <span>On</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Earning</h2>
                <p class="ps-card-desc">How a completed sale for a registered customer earns points. Amounts are in <?php echo $h($base); ?>.</p>
            </div>
            <div class="ps-card-content" style="padding:0">
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="points_per_unit">Points per 1.00 <?php echo $h($base); ?> spent</label>
                        <p class="ps-field-hint">Whole points only, rounded down. Spend is the amount actually charged after any points discount — points never earn more points.</p>
                    </div>
                    <div class="ps-setting-control">
                        <input class="ps-input" id="points_per_unit" name="points_per_unit" inputmode="decimal" value="<?php echo $h($cfg['points_per_unit']); ?>" required>
                    </div>
                </div>
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="min_purchase">Minimum qualifying sale (<?php echo $h($base); ?>)</label>
                        <p class="ps-field-hint">Sales below this earn nothing. 0 means every completed sale can earn.</p>
                    </div>
                    <div class="ps-setting-control">
                        <input class="ps-input" id="min_purchase" name="min_purchase" inputmode="decimal" value="<?php echo $h($cfg['min_purchase']); ?>" required>
                    </div>
                </div>
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <div class="ps-label">Payment methods that earn no points</div>
                        <p class="ps-field-hint">Walk-in (Cash Customer) and cancelled sales never earn, even if unchecked here. Refunds take points back below.</p>
                    </div>
                    <div class="ps-setting-control">
                        <div class="ps-setting-methods">
                            <?php foreach (psPaymentMethods() as $code => $label): ?>
                                <label><input type="checkbox" name="excluded_methods[]" value="<?php echo $h($code); ?>"<?php echo in_array($code, $excluded, true) ? ' checked' : ''; ?>> <?php echo $h($label); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Using points</h2>
                <p class="ps-card-desc">How many points a customer may spend on one sale, and what each point is worth.</p>
            </div>
            <div class="ps-card-content" style="padding:0">
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="point_value">Value of 1 point (<?php echo $h($base); ?>)</label>
                        <p class="ps-field-hint">At 0.01, 100 points = 1.00 <?php echo $h($base); ?> off. The discount is applied before tax, so VAT is on the reduced amount.</p>
                    </div>
                    <div class="ps-setting-control">
                        <input class="ps-input" id="point_value" name="point_value" inputmode="decimal" value="<?php echo $h($cfg['point_value']); ?>" required>
                    </div>
                </div>
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="min_redeem_points">Minimum points used at a time</label>
                        <p class="ps-field-hint">The till will not accept a smaller redeem. The customer also cannot use more than their balance.</p>
                    </div>
                    <div class="ps-setting-control">
                        <input class="ps-input" id="min_redeem_points" name="min_redeem_points" inputmode="numeric" value="<?php echo $h($cfg['min_redeem_points']); ?>" required>
                    </div>
                </div>
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="max_redeem_percent">Most of a sale that points may pay (%)</label>
                        <p class="ps-field-hint">50 means points can cover at most half the sale. The rest is paid with cash, card or account as usual.</p>
                    </div>
                    <div class="ps-setting-control">
                        <input class="ps-input" id="max_redeem_percent" name="max_redeem_percent" inputmode="decimal" value="<?php echo $h($cfg['max_redeem_percent']); ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Refunds and expiry</h2>
                <p class="ps-card-desc">What happens to points when a sale is refunded, and whether unused points die.</p>
            </div>
            <div class="ps-card-content" style="padding:0">
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="refund_policy">When a sale is refunded</label>
                        <p class="ps-field-hint">Proportional takes back points earned and returns points used, in line with the refund amount. Leave unchanged does neither.</p>
                    </div>
                    <div class="ps-setting-control">
                        <select class="ps-input" id="refund_policy" name="refund_policy">
                            <option value="PROPORTIONAL"<?php echo strtoupper((string)$cfg['refund_policy']) === 'PROPORTIONAL' ? ' selected' : ''; ?>>Take points back in proportion to the refund</option>
                            <option value="NONE"<?php echo strtoupper((string)$cfg['refund_policy']) === 'NONE' ? ' selected' : ''; ?>>Leave points unchanged</option>
                        </select>
                    </div>
                </div>
                <div class="ps-setting">
                    <div class="ps-setting-copy">
                        <label class="ps-label" for="expiry_months">Points expire after (months)</label>
                        <p class="ps-field-hint">0 = never. Counted from the sale date; oldest points are used first. A change applies to points earned after you save.</p>
                    </div>
                    <div class="ps-setting-control">
                        <input class="ps-input" id="expiry_months" name="expiry_months" inputmode="numeric" value="<?php echo $h($cfg['expiry_months']); ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="ps-form-actions"><button class="ps-btn ps-btn-default" type="submit">Save</button></div>
        <?php endif; ?>
    </form>
    <p class="ps-field-hint">Saving does not give or take points for past sales. Balances and history are on the customer profile (Customers → Open profile).</p>
<?php ps_close(); ?>
</body>
</html>
