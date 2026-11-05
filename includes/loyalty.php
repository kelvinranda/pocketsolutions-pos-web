<?php
/**
 * 2026-10-24 — Customer loyalty points (web; desktop sales earn when they sync).
 *
 * Configuration: loyalty_programme (one row per company; admin). Nothing is hardcoded — the defaults below are
 * only what a company gets before an admin saves its own rules, and the programme starts DISABLED.
 *
 * Ledger: loyalty_ledger is append-only and is the only balance authority (balance = SUM(points)); there is no
 * cached balance column. Entry types:
 *   EARN            + points for a completed sale            (reference SALE / invoice)
 *   REDEEM          - points used as a discount on a sale    (reference SALE / invoice)
 *   REVERSE_EARN    - points taken back for a refund         (reference REFUND / REF-######)
 *   REVERSE_REDEEM  + redeemed points given back for a refund (reference REFUND / REF-######)
 *   EXPIRE          - points past their expiry date          (reference EXPIRY / customer+date)
 *   ADJUST          +/- manual admin correction with a reason (reference ADJUSTMENT / ADJ-<uuid>)
 * UNIQUE(company_name, reference_type, reference_id, entry_type) makes every posting happen at most once:
 * a repeated request, a replayed sale or a second refund process cannot earn, redeem or reverse twice.
 *
 * Conversion basis: qualifying spend and redemption value are in the company BASE currency. A sale in another
 * currency qualifies on its stored base_total (the sale's own rate snapshot); a redemption's base value is
 * converted into the sale currency with the same snapshot. USD and ZWG amounts are never compared raw.
 *
 * Earning: floor(qualifying base spend × points_per_unit). Qualifying spend = the amount actually charged
 * (after any points discount — points never earn points). Sales under min_purchase, sales with no registered
 * customer, cancelled/uncompleted sales and payment methods listed in excluded_methods earn nothing.
 *
 * Redemption: whole points; at least min_redeem_points; not more than the customer's balance; the discount
 * may not exceed max_redeem_percent of the sale. The discount is spread over the lines before tax
 * (psBuildSaleLines), so VAT is charged on the discounted amount and the sale still posts once through
 * the normal sale transaction (stock, cash, credit, accounting unchanged).
 *
 * Refunds (refund_policy PROPORTIONAL, default): when a refund is processed, earned points are taken back and
 * redeemed points are given back in proportion to the refund amount / sale total, capped so the totals can
 * never exceed what the sale earned or redeemed. Policy NONE leaves points unchanged.
 *
 * Expiry (expiry_months > 0): earned points expire that many months after the sale. Expiry is first-in
 * first-out: points used, reversed or already expired are taken from the oldest earned points first.
 */

require_once __DIR__ . '/cash_controls.php';

const PS_LOYALTY_EARN = 'EARN';
const PS_LOYALTY_REDEEM = 'REDEEM';
const PS_LOYALTY_REVERSE_EARN = 'REVERSE_EARN';
const PS_LOYALTY_REVERSE_REDEEM = 'REVERSE_REDEEM';
const PS_LOYALTY_EXPIRE = 'EXPIRE';
const PS_LOYALTY_ADJUST = 'ADJUST';

/** Defaults a company gets until an admin saves its rules (programme off). */
function psLoyaltyDefaults(): array
{
    return [
        'enabled' => 0,
        'points_per_unit' => '1.0000',   // points per 1.00 of base-currency qualifying spend
        'min_purchase' => '0.00',        // base currency
        'point_value' => '0.0100',       // base-currency value of one point when redeemed
        'min_redeem_points' => 100,
        'max_redeem_percent' => '50.00', // of the sale amount
        'expiry_months' => 0,            // 0 = points never expire
        'excluded_methods' => '',        // e.g. "CREDIT" — tenders that earn no points
        'refund_policy' => 'PROPORTIONAL',
    ];
}

function psLoyaltyEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $ok = mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loyalty_programme (
            company_name VARCHAR(250) NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            points_per_unit DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
            min_purchase DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            point_value DECIMAL(14,4) NOT NULL DEFAULT 0.0100,
            min_redeem_points INT NOT NULL DEFAULT 100,
            max_redeem_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            expiry_months INT NOT NULL DEFAULT 0,
            excluded_methods VARCHAR(120) NOT NULL DEFAULT '',
            refund_policy VARCHAR(16) NOT NULL DEFAULT 'PROPORTIONAL',
            updated_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS loyalty_ledger (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL,
            customer_master_id INT NOT NULL,
            entry_type VARCHAR(16) NOT NULL,
            points INT NOT NULL,
            base_amount DECIMAL(14,2) NULL,
            base_currency CHAR(3) NULL,
            reference_type VARCHAR(16) NOT NULL,
            reference_id VARCHAR(64) NOT NULL,
            branch VARCHAR(250) NOT NULL DEFAULT '',
            reason VARCHAR(250) NOT NULL DEFAULT '',
            expires_on DATE NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_loyalty_once (company_name, reference_type, reference_id, entry_type),
            KEY idx_loyalty_customer (company_name, customer_master_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = (bool)$ok;
    return $ok;
}

function psLoyaltyCompany(): string
{
    return psCashControlsCompany();
}

/** Effective rules for a company (saved row or defaults). */
function psLoyaltyConfig(mysqli $conn, ?string $company = null): array
{
    $company = trim((string)($company ?? psLoyaltyCompany()));
    $cfg = psLoyaltyDefaults();
    if ($company === '' || !psLoyaltyEnsureSchema($conn)) {
        return $cfg;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM loyalty_programme WHERE company_name = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $company);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            foreach ($cfg as $k => $_) {
                if (array_key_exists($k, $row)) {
                    $cfg[$k] = $row[$k];
                }
            }
            $cfg['updated_by'] = (string)$row['updated_by'];
            $cfg['updated_at'] = (string)$row['updated_at'];
        }
    }
    $cfg['enabled'] = (int)$cfg['enabled'];
    $cfg['min_redeem_points'] = (int)$cfg['min_redeem_points'];
    $cfg['expiry_months'] = (int)$cfg['expiry_months'];
    return $cfg;
}

/** Admin only. Validates and saves the rules. */
function psLoyaltySaveConfig(mysqli $conn, array $in, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change the loyalty programme.'];
    }
    $company = psLoyaltyCompany();
    if ($company === '' || !psLoyaltyEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The loyalty settings could not be saved.'];
    }
    $num = static function ($v, string $re) {
        $v = trim((string)$v);
        return preg_match($re, $v) ? $v : null;
    };
    $enabled = !empty($in['enabled']) ? 1 : 0;
    $ppu = $num($in['points_per_unit'] ?? '', '/^\d{1,6}(\.\d{1,4})?$/');
    $minPurchase = $num($in['min_purchase'] ?? '', '/^\d{1,10}(\.\d{1,2})?$/');
    $pointValue = $num($in['point_value'] ?? '', '/^\d{1,8}(\.\d{1,4})?$/');
    $minRedeem = $num($in['min_redeem_points'] ?? '', '/^\d{1,9}$/');
    $maxPct = $num($in['max_redeem_percent'] ?? '', '/^\d{1,3}(\.\d{1,2})?$/');
    $expiry = $num($in['expiry_months'] ?? '', '/^\d{1,3}$/');
    if ($ppu === null || (float)$ppu <= 0) {
        return ['ok' => false, 'error' => 'Points per 1.00 spent must be a number greater than 0 (up to 4 decimals).'];
    }
    if ($minPurchase === null) {
        return ['ok' => false, 'error' => 'Minimum qualifying purchase must be an amount, e.g. 0 or 5.00.'];
    }
    if ($pointValue === null || (float)$pointValue <= 0) {
        return ['ok' => false, 'error' => 'Value of one point must be greater than 0 (up to 4 decimals).'];
    }
    if ($minRedeem === null || (int)$minRedeem < 1) {
        return ['ok' => false, 'error' => 'Minimum points to redeem must be a whole number of at least 1.'];
    }
    if ($maxPct === null || (float)$maxPct <= 0 || (float)$maxPct > 100) {
        return ['ok' => false, 'error' => 'Maximum share of a sale paid with points must be between 0 and 100 percent.'];
    }
    if ($expiry === null || (int)$expiry > 120) {
        return ['ok' => false, 'error' => 'Expiry must be 0 (never) to 120 months.'];
    }
    $methods = [];
    foreach ((array)($in['excluded_methods'] ?? []) as $m) {
        $m = strtoupper(trim((string)$m));
        if (preg_match('/^[A-Z_]{2,20}$/', $m)) {
            $methods[] = $m;
        }
    }
    $methodsCsv = implode(',', array_unique($methods));
    $policy = strtoupper(trim((string)($in['refund_policy'] ?? 'PROPORTIONAL')));
    if (!in_array($policy, ['PROPORTIONAL', 'NONE'], true)) {
        return ['ok' => false, 'error' => 'Choose how refunds treat points.'];
    }
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, 'INSERT INTO loyalty_programme
        (company_name, enabled, points_per_unit, min_purchase, point_value, min_redeem_points, max_redeem_percent, expiry_months, excluded_methods, refund_policy, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), points_per_unit = VALUES(points_per_unit), min_purchase = VALUES(min_purchase),
            point_value = VALUES(point_value), min_redeem_points = VALUES(min_redeem_points), max_redeem_percent = VALUES(max_redeem_percent),
            expiry_months = VALUES(expiry_months), excluded_methods = VALUES(excluded_methods), refund_policy = VALUES(refund_policy),
            updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The loyalty settings could not be saved.'];
    }
    $minRedeemI = (int)$minRedeem;
    $expiryI = (int)$expiry;
    mysqli_stmt_bind_param($stmt, 'sisssisissss', $company, $enabled, $ppu, $minPurchase, $pointValue, $minRedeemI, $maxPct, $expiryI, $methodsCsv, $policy, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The loyalty settings could not be saved.'];
}

/** Insert one ledger row. Returns ['ok'=>true,'duplicate'=>bool]. */
function psLoyaltyPost(mysqli $conn, array $e): array
{
    $company = (string)$e['company'];
    $cid = (int)$e['customer_id'];
    $type = (string)$e['type'];
    $points = (int)$e['points'];
    $base = isset($e['base_amount']) && $e['base_amount'] !== null ? (string)$e['base_amount'] : null;
    $baseCur = isset($e['base_currency']) ? (string)$e['base_currency'] : null;
    $refType = (string)$e['ref_type'];
    $refId = substr((string)$e['ref_id'], 0, 64);
    $branch = (string)($e['branch'] ?? '');
    $reason = substr((string)($e['reason'] ?? ''), 0, 250);
    $expires = $e['expires_on'] ?? null;
    $by = (string)($e['by'] ?? psCashControlsUser());
    $now = (string)($e['at'] ?? date('Y-m-d H:i:s'));
    if ($cid < 1 || $points === 0 || $company === '') {
        return ['ok' => false, 'error' => 'Loyalty entry is not valid.'];
    }
    $stmt = mysqli_prepare($conn, 'INSERT INTO loyalty_ledger
        (company_name, customer_master_id, entry_type, points, base_amount, base_currency, reference_type, reference_id, branch, reason, expires_on, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Loyalty points could not be recorded.'];
    }
    mysqli_stmt_bind_param($stmt, 'sisisssssssss', $company, $cid, $type, $points, $base, $baseCur, $refType, $refId, $branch, $reason, $expires, $by, $now);
    $ok = mysqli_stmt_execute($stmt);
    $errno = mysqli_stmt_errno($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok && $errno === 1062) {
        return ['ok' => true, 'duplicate' => true];
    }
    return $ok ? ['ok' => true, 'duplicate' => false] : ['ok' => false, 'error' => 'Loyalty points could not be recorded.'];
}

/** Current balance (ledger sum). $lock: serialise redemptions on this customer (call inside a transaction). */
function psLoyaltyBalance(mysqli $conn, int $customerId, ?string $company = null, bool $lock = false): int
{
    $company = (string)($company ?? psLoyaltyCompany());
    if ($customerId < 1 || !psLoyaltyEnsureSchema($conn)) {
        return 0;
    }
    if ($lock) {
        // One row per customer to queue concurrent redemptions behind each other.
        mysqli_query($conn, 'SELECT id FROM customer_master WHERE id = ' . $customerId . ' FOR UPDATE');
    }
    $stmt = mysqli_prepare($conn, 'SELECT COALESCE(SUM(points), 0) AS b FROM loyalty_ledger WHERE company_name = ? AND customer_master_id = ?');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'si', $company, $customerId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int)($row['b'] ?? 0);
}

/**
 * Post EXPIRE for points past their date (FIFO). Idempotent per customer per day.
 * Expired = max(0, earned-and-due − everything already taken out) where "taken out" consumes oldest first.
 */
function psLoyaltyApplyExpiry(mysqli $conn, int $customerId, ?string $company = null, ?string $today = null): int
{
    $company = (string)($company ?? psLoyaltyCompany());
    $cfg = psLoyaltyConfig($conn, $company);
    if ($customerId < 1 || (int)$cfg['expiry_months'] < 1) {
        return 0;
    }
    $today = $today ?? date('Y-m-d');
    $stmt = mysqli_prepare($conn, "SELECT
            COALESCE(SUM(CASE WHEN points > 0 AND expires_on IS NOT NULL AND expires_on < ? THEN points ELSE 0 END), 0) AS due,
            COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END), 0) AS used
        FROM loyalty_ledger WHERE company_name = ? AND customer_master_id = ?");
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'ssi', $today, $company, $customerId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $expire = (int)$row['due'] - (int)$row['used'];
    if ($expire <= 0) {
        return 0;
    }
    $res = psLoyaltyPost($conn, [
        'company' => $company, 'customer_id' => $customerId, 'type' => PS_LOYALTY_EXPIRE, 'points' => -$expire,
        'ref_type' => 'EXPIRY', 'ref_id' => 'EXP-' . $customerId . '-' . $today,
        'reason' => 'Points earned before ' . $today . ' passed their expiry date', 'by' => 'system',
    ]);
    return (!empty($res['ok']) && empty($res['duplicate'])) ? $expire : 0;
}

/**
 * What a redemption of $points is worth on this sale.
 *
 * @param array $fxSnap psFxSnapshot of the sale
 * @return array{ok:bool,points?:int,discount_cents?:int,base_cents?:int,error?:string}
 */
function psLoyaltyRedemptionQuote(mysqli $conn, array $cfg, int $points, int $balance, int $saleCents, array $fxSnap): array
{
    if ($points === 0) {
        return ['ok' => true, 'points' => 0, 'discount_cents' => 0, 'base_cents' => 0];
    }
    if (empty($cfg['enabled'])) {
        return ['ok' => false, 'error' => 'The loyalty programme is not switched on.'];
    }
    if ($points < 0) {
        return ['ok' => false, 'error' => 'Enter the number of points to use.'];
    }
    if ($points < (int)$cfg['min_redeem_points']) {
        return ['ok' => false, 'error' => 'At least ' . (int)$cfg['min_redeem_points'] . ' points must be used at a time.'];
    }
    if ($points > $balance) {
        return ['ok' => false, 'error' => 'The customer has only ' . $balance . ' points.'];
    }
    // Base value of the points (exact: points × value with 4 dp → cents, rounded half-up).
    $valueMicro = (int)round((float)$cfg['point_value'] * 10000);
    $baseCents = intdiv($points * $valueMicro + 50, 100);
    require_once __DIR__ . '/currency.php';
    $discount = psFxFromBaseCents($conn, $fxSnap, $baseCents);
    $cap = intdiv($saleCents * (int)round((float)$cfg['max_redeem_percent'] * 100), 10000);
    if ($discount > $cap) {
        return ['ok' => false, 'error' => 'Points can pay at most ' . rtrim(rtrim((string)$cfg['max_redeem_percent'], '0'), '.') . '% of this sale. Use fewer points.'];
    }
    if ($discount < 1) {
        return ['ok' => false, 'error' => 'That many points is worth nothing on this sale.'];
    }
    return ['ok' => true, 'points' => $points, 'discount_cents' => $discount, 'base_cents' => $baseCents];
}

/** Points a sale would earn (base-currency qualifying spend in cents). */
/**
 * 2026-11-05 split payment: the part of a sale (in sale-currency cents) paid with methods that earn points.
 * Tenders in excluded_methods earn nothing; the change comes off the cash (change is always cash).
 */
function psLoyaltySplitEarningCents(array $cfg, array $tenderEval, int $totalCents): int
{
    $excluded = array_filter(array_map('trim', explode(',', strtoupper((string)($cfg['excluded_methods'] ?? '')))));
    $earn = 0;
    foreach ($tenderEval['tenders'] as $t) {
        if (!in_array($t['method'], $excluded, true)) {
            $earn += (int)$t['sale_cents'];
        }
    }
    if (!empty($tenderEval['change']) && !in_array('CASH', $excluded, true)) {
        $earn -= (int)$tenderEval['change']['sale_cents'];
    }
    return max(0, min($totalCents, $earn));
}

function psLoyaltyPointsForSpend(array $cfg, int $baseCents, string $method): int
{
    if (empty($cfg['enabled']) || $baseCents <= 0) {
        return 0;
    }
    $excluded = array_filter(array_map('trim', explode(',', strtoupper((string)$cfg['excluded_methods']))));
    if (in_array(strtoupper(trim($method)), $excluded, true)) {
        return 0;
    }
    $min = (int)round((float)$cfg['min_purchase'] * 100);
    if ($baseCents < $min) {
        return 0;
    }
    $ppuMicro = (int)round((float)$cfg['points_per_unit'] * 10000);
    return intdiv($baseCents * $ppuMicro, 1000000);
}

/**
 * Inside the sale transaction (after the sale, stock and payment rows): post REDEEM and EARN once each.
 *
 * @return array{ok:bool,earned?:int,redeemed?:int,error?:string}
 */
function psLoyaltyPostSale(mysqli $conn, array $sale): array
{
    $cfg = $sale['cfg'];
    $out = ['ok' => true, 'earned' => 0, 'redeemed' => 0];
    $cid = (int)($sale['customer_id'] ?? 0);
    if ($cid < 1 || empty($cfg['enabled'])) {
        return $out;
    }
    $common = ['company' => $sale['company'], 'customer_id' => $cid, 'ref_type' => 'SALE', 'ref_id' => (string)$sale['invoice'],
        'branch' => $sale['branch'], 'by' => $sale['by'], 'at' => $sale['at'], 'base_currency' => $sale['base_currency']];
    $redeem = (int)($sale['redeem_points'] ?? 0);
    if ($redeem > 0) {
        $r = psLoyaltyPost($conn, $common + ['type' => PS_LOYALTY_REDEEM, 'points' => -$redeem,
            'base_amount' => psFxCentsToMoney((int)$sale['redeem_base_cents']), 'reason' => 'Points used as a discount']);
        if (empty($r['ok']) || !empty($r['duplicate'])) {
            return ['ok' => false, 'error' => 'Loyalty points could not be used. Try again.'];
        }
        $out['redeemed'] = $redeem;
    }
    $earn = psLoyaltyPointsForSpend($cfg, (int)$sale['base_cents'], (string)$sale['method']);
    if ($earn > 0) {
        $expires = (int)$cfg['expiry_months'] > 0 ? date('Y-m-d', strtotime(substr((string)$sale['at'], 0, 10) . ' +' . (int)$cfg['expiry_months'] . ' months')) : null;
        $r = psLoyaltyPost($conn, $common + ['type' => PS_LOYALTY_EARN, 'points' => $earn,
            'base_amount' => psFxCentsToMoney((int)$sale['base_cents']), 'reason' => 'Points earned on sale', 'expires_on' => $expires]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => 'Loyalty points could not be recorded.'];
        }
        $out['earned'] = !empty($r['duplicate']) ? 0 : $earn;
    }
    return $out;
}

/** Ledger rows for one sale invoice, keyed by entry type → points. */
function psLoyaltySaleEntries(mysqli $conn, string $company, string $invoice): array
{
    $stmt = mysqli_prepare($conn, "SELECT entry_type, points, customer_master_id FROM loyalty_ledger WHERE company_name = ? AND reference_type = 'SALE' AND reference_id = ?");
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $company, $invoice);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[$r['entry_type']] = ['points' => (int)$r['points'], 'customer_id' => (int)$r['customer_master_id']];
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/** Sum of earlier refund reversals for a sale (to cap cumulative reversals). */
function psLoyaltyReversedForSale(mysqli $conn, string $company, string $invoice, string $type): int
{
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(l.points), 0) AS p FROM loyalty_ledger l
        JOIN sale_refund_requests r ON r.refund_number = l.reference_id
        WHERE l.company_name = ? AND l.reference_type = 'REFUND' AND l.entry_type = ? AND r.invoice_number = ?");
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'sss', $company, $type, $invoice);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return abs((int)($row['p'] ?? 0));
}

/**
 * Inside the refund-processing transaction: take back earned points and give back redeemed points in proportion.
 * $refundCents / $saleCents are in the sale currency. Idempotent per refund number.
 */
function psLoyaltyReverseForRefund(mysqli $conn, string $company, string $invoice, string $refundNumber, int $refundCents, int $saleCents, string $branch, string $by): array
{
    if (!psLoyaltyEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Loyalty points could not be updated.'];
    }
    $cfg = psLoyaltyConfig($conn, $company);
    if (strtoupper((string)$cfg['refund_policy']) !== 'PROPORTIONAL' || $saleCents <= 0 || $refundCents <= 0) {
        return ['ok' => true, 'reversed' => 0, 'restored' => 0];
    }
    $entries = psLoyaltySaleEntries($conn, $company, $invoice);
    if (!$entries) {
        return ['ok' => true, 'reversed' => 0, 'restored' => 0];
    }
    $refundCents = min($refundCents, $saleCents);
    $out = ['ok' => true, 'reversed' => 0, 'restored' => 0];
    $plan = [
        PS_LOYALTY_EARN => [PS_LOYALTY_REVERSE_EARN, -1, 'Points taken back for refund ' . $refundNumber],
        PS_LOYALTY_REDEEM => [PS_LOYALTY_REVERSE_REDEEM, 1, 'Points given back for refund ' . $refundNumber],
    ];
    foreach ($plan as $src => [$type, $sign, $reason]) {
        if (empty($entries[$src])) {
            continue;
        }
        $orig = abs($entries[$src]['points']);
        $already = psLoyaltyReversedForSale($conn, $company, $invoice, $type);
        $share = $refundCents >= $saleCents ? $orig - $already : min($orig - $already, intdiv($orig * $refundCents + intdiv($saleCents, 2), $saleCents));
        if ($share <= 0) {
            continue;
        }
        $r = psLoyaltyPost($conn, ['company' => $company, 'customer_id' => $entries[$src]['customer_id'], 'type' => $type,
            'points' => $sign * $share, 'ref_type' => 'REFUND', 'ref_id' => $refundNumber, 'branch' => $branch,
            'reason' => $reason, 'by' => $by]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => 'Loyalty points could not be updated.'];
        }
        if (empty($r['duplicate'])) {
            $out[$type === PS_LOYALTY_REVERSE_EARN ? 'reversed' : 'restored'] = $share;
        }
    }
    return $out;
}

/** Admin manual adjustment with a reason (positive or negative; never below zero). */
function psLoyaltyAdjust(mysqli $conn, int $customerId, $points, string $reason, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may adjust loyalty points.'];
    }
    $p = trim((string)$points);
    if (!preg_match('/^-?\d{1,9}$/', $p) || (int)$p === 0) {
        return ['ok' => false, 'error' => 'Enter a whole number of points (use a minus sign to remove points).'];
    }
    $reason = trim($reason);
    if (strlen($reason) < 3) {
        return ['ok' => false, 'error' => 'A reason is required for a loyalty adjustment.'];
    }
    $company = psLoyaltyCompany();
    if (!psLoyaltyEnsureSchema($conn) || !mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The adjustment could not be saved.'];
    }
    $balance = psLoyaltyBalance($conn, $customerId, $company, true);
    if ($balance + (int)$p < 0) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The customer has only ' . $balance . ' points.'];
    }
    $r = psLoyaltyPost($conn, ['company' => $company, 'customer_id' => $customerId, 'type' => PS_LOYALTY_ADJUST, 'points' => (int)$p,
        'ref_type' => 'ADJUSTMENT', 'ref_id' => 'ADJ-' . bin2hex(random_bytes(8)), 'reason' => $reason, 'by' => $actor]);
    if (empty($r['ok']) || !mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The adjustment could not be saved.'];
    }
    return ['ok' => true];
}

/** Paged history for one customer (newest first). */
function psLoyaltyHistory(mysqli $conn, int $customerId, int $limit = 25, int $offset = 0): array
{
    if (!psLoyaltyEnsureSchema($conn)) {
        return [];
    }
    $company = psLoyaltyCompany();
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $stmt = mysqli_prepare($conn, 'SELECT * FROM loyalty_ledger WHERE company_name = ? AND customer_master_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'siii', $company, $customerId, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function psLoyaltyEntryLabel(string $type): string
{
    return [
        PS_LOYALTY_EARN => 'Earned', PS_LOYALTY_REDEEM => 'Used', PS_LOYALTY_REVERSE_EARN => 'Taken back (refund)',
        PS_LOYALTY_REVERSE_REDEEM => 'Given back (refund)', PS_LOYALTY_EXPIRE => 'Expired', PS_LOYALTY_ADJUST => 'Adjustment',
    ][$type] ?? $type;
}
