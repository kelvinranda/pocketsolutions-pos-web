<?php
/**
 * 2026-10-24 — POS "View Ticket": the customer-facing summary of the current cart, priced on the server exactly as
 * the sale will be (branch prices, exchange-rate snapshot, quotation prices, loyalty discount, tax).
 * Read-only: no sale, invoice number, stock, payment or loyalty entry is created here. The returned fingerprint is
 * sent with the sale; submit_sale2.php refuses the sale if the order changed after the customer confirmed it.
 */
define('PS_SALE_LIBRARY', true);
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/submit_sale2.php';
require_once __DIR__ . '/includes/branch_access.php';

header('Content-Type: application/json; charset=utf-8');

$fail = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
};

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    $fail('The ticket could not be loaded.', 500);
}
mysqli_report(MYSQLI_REPORT_OFF);
mysqli_set_charset($conn, 'utf8mb4');

if (trim((string)($_SESSION['logger'] ?? '')) === '') {
    $fail('Please sign in again.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !psCsrfValidate()) {
    $fail('Invalid security token. Reload and try again.');
}

$cart = json_decode((string)($_POST['cart'] ?? ''), true);
if (!is_array($cart) || !$cart) {
    $fail('No products in the cart.');
}
$company = trim((string)($_SESSION['the_company'] ?? ''));
$branch = psBranchNormalizeName((string)($_SESSION['selected_branch'] ?? $_SESSION['branch'] ?? ''));
if ($branch === '') {
    $fail('Select a branch first.');
}
if (!psFxEnsureTransactionColumns($conn) || !psLoyaltyEnsureSchema($conn)) {
    $fail('The ticket could not be loaded.', 500);
}
$fxCheck = psFxCheckTransactionCurrency($conn, $company, $_POST['currency'] ?? '');
if (empty($fxCheck['ok'])) {
    $fail((string)$fxCheck['error']);
}
$currency = (string)$fxCheck['code'];
$fxSnap = psFxSnapshot($conn, $company, $currency, date('Y-m-d H:i:s'));
if (empty($fxSnap['ok'])) {
    $fail((string)$fxSnap['error']);
}

$overrides = [];
$quoteId = (int)($_POST['quotation_id'] ?? 0);
if ($quoteId > 0) {
    require_once __DIR__ . '/includes/quotation.php';
    $qs = psQuoteEnsureSchema($conn) ? psQuoteForSale($conn, $quoteId, $company, $branch) : ['ok' => false, 'error' => 'The quotation was not found.'];
    if (empty($qs['ok'])) {
        $fail((string)$qs['error']);
    }
    foreach ($qs['overrides'] as $k => $ov) {
        $overrides[$k] = $ov + ['currency' => psFxNormalizeCode($qs['quote']['currency'] ?? '') ?: $fxSnap['base']];
    }
}

$built = psBuildSaleLines($conn, $cart, $branch, $company, null, $overrides, $fxSnap);
if (empty($built['ok'])) {
    $fail((string)$built['error']);
}
$subtotalCents = (int)$built['total_cents'];

$customerId = (int)($_POST['customer_master_id'] ?? 0);
$customer = psResolveSaleCustomer($conn, $customerId > 0 ? (string)$customerId : '', '');
if (empty($customer['ok'])) {
    $fail((string)$customer['error']);
}

$redeemRaw = trim((string)($_POST['loyalty_redeem_points'] ?? ''));
if ($redeemRaw !== '' && !preg_match('/^\d{1,9}$/', $redeemRaw)) {
    $fail('Enter the number of loyalty points to use as a whole number.');
}
$redeemPoints = (int)$redeemRaw;
$cfg = psLoyaltyConfig($conn, $company);
$loyalty = ['enabled' => !empty($cfg['enabled']), 'balance' => 0, 'min_redeem' => (int)$cfg['min_redeem_points'], 'used' => 0, 'discount' => '0.00', 'earn' => 0, 'error' => ''];
if ($customer['id'] !== null && $loyalty['enabled']) {
    $loyalty['balance'] = psLoyaltyBalance($conn, (int)$customer['id'], $company);
}
if ($redeemPoints > 0) {
    if ($customer['id'] === null) {
        $fail('Choose the customer whose loyalty points are being used.');
    }
    $q = psLoyaltyRedemptionQuote($conn, $cfg, $redeemPoints, $loyalty['balance'], $subtotalCents, $fxSnap);
    if (empty($q['ok'])) {
        $loyalty['error'] = (string)$q['error'];
        $redeemPoints = 0;
    } else {
        $built = psBuildSaleLines($conn, $cart, $branch, $company, null, $overrides, $fxSnap, (int)$q['discount_cents']);
        if (empty($built['ok'])) {
            $fail((string)$built['error']);
        }
        $loyalty['used'] = $redeemPoints;
        $loyalty['discount'] = psFxCentsToMoney((int)$q['discount_cents']);
    }
}
if ($customer['id'] !== null && $loyalty['enabled']) {
    $loyalty['earn'] = psLoyaltyPointsForSpend($cfg, psFxToBaseCents($conn, $fxSnap, (int)$built['total_cents']), (string)($_POST['payment_method'] ?? ''));
}

$lines = [];
$discountCents = 0;
foreach ($built['lines'] as $l) {
    $disc = (int)(psDecimalCents((string)($l['discount_amount'] ?? '0')) ?? 0);
    $discountCents += $disc;
    $lines[] = [
        // 2026-10-31: identify the cart line so the ticket can change or remove it.
        'product_id' => (string)($l['product_id'] ?? ''),
        'pack_id' => (int)($l['pack_id'] ?? 0),
        'product' => (string)$l['product'],
        'quantity' => (string)$l['quantity'],
        // 2026-10-28 packs: "1 pack (10 bags)" at the pack price; base-unit lines as before.
        'qty_text' => !empty($l['sell_unit']) ? psPackLineQtyText($l, (string)($l['base_label'] ?? '')) : null,
        'unit_price' => !empty($l['sell_unit']) ? (string)$l['sell_price'] : (string)$l['price'],
        'line_total' => (string)$l['revenue'],
        'discount' => psFxCentsToMoney($disc),
        'tax' => ($l['tax_data_status'] ?? '') === PS_TAX_STATUS_COMPLETE ? (string)($l['tax_amount'] ?? '0.00') : null,
        'tax_label' => ($l['tax_data_status'] ?? '') === PS_TAX_STATUS_COMPLETE ? trim((string)($l['tax_code'] ?? '') . ' ' . (string)($l['tax_rate_percent'] ?? '') . '%') : '',
    ];
}
$taxComplete = ($built['tax_data_status'] ?? '') === PS_TAX_STATUS_COMPLETE && $built['tax_amount'] !== null;

echo json_encode([
    'success' => true,
    'currency' => $currency,
    'customer' => (string)$customer['name'],
    'branch' => $branch,
    'lines' => $lines,
    'subtotal' => psFxCentsToMoney($subtotalCents),
    'discount' => psFxCentsToMoney($discountCents),
    'tax' => $taxComplete ? (string)$built['tax_amount'] : null,
    'tax_mode' => (string)($built['tax_pricing_mode'] ?? ''),
    'total' => (string)$built['total'],
    'total_cents' => (int)$built['total_cents'],
    'loyalty' => $loyalty,
    'fingerprint' => psSaleReviewFingerprint($cart, (string)($_POST['currency'] ?? ''), $customerId, $quoteId, $redeemPoints),
]);
mysqli_close($conn);
