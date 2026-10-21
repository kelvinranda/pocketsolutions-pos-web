<?php
/**
 * One payment row per sale in sale_payments.
 * online_sales_ref.method stays the legacy currency marker (USD).
 *
 * Phase 5.2 — sale customer identity is online_sales_ref.customer_master_id
 * (customer_master.id). Legacy online_sales_ref.customer_id remains customers.id
 * and is not written by the production POS path.
 *
 * Phase 6.2 — CREDIT charges an eligible customer account (no cash amount/change;
 * no cash till impact). Eligibility and CREDIT_SALE ledger posting are enforced in
 * submit_sale2.php via includes/customer_account.php — not trusted from the client.
 */

require_once __DIR__ . '/customer_master.php';

const PS_SALE_TOKEN_TTL = 600;

function psPaymentMethods(): array
{
    return [
        'CASH' => 'Cash',
        'CARD' => 'Card',
        'ECOCASH' => 'EcoCash',
        'BANK_TRANSFER' => 'Bank Transfer',
        'CREDIT' => 'Credit (Account)',
        'OTHER' => 'Other',
    ];
}

function psPaymentMethodIsCredit($code): bool
{
    return strtoupper(trim((string)$code)) === 'CREDIT';
}

function psPaymentMethodLabel($code): string
{
    $code = strtoupper(trim((string)$code));
    $methods = psPaymentMethods();
    return $methods[$code] ?? ($code !== '' ? $code : 'Not recorded');
}

function psCents($amount): int
{
    return (int)round(((float)$amount) * 100);
}

function psParseAmount($raw): array
{
    if (is_bool($raw) || is_array($raw) || $raw === null) {
        return ['ok' => false];
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return ['ok' => false];
    }
    $value = round((float)$raw, 2);
    if ($value < 0 || $value > 9999999999.99) {
        return ['ok' => false];
    }
    return ['ok' => true, 'value' => $value];
}

function psNormalizeReference($raw): string
{
    $raw = trim((string)$raw);
    $raw = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
    if (!is_string($raw)) {
        return '';
    }
    if (strlen($raw) > 120) {
        $raw = substr($raw, 0, 120);
    }
    return $raw;
}

function psCartTotal(array $cart): array
{
    $total = 0.0;
    $lines = [];
    foreach ($cart as $item) {
        if (!is_array($item) || !isset($item['price'], $item['quantity'])) {
            return ['ok' => false, 'error' => 'No cart data received.'];
        }
        if (!is_numeric($item['price']) || !is_numeric($item['quantity'])) {
            return ['ok' => false, 'error' => 'No cart data received.'];
        }
        $price = (float)$item['price'];
        $quantity = (float)$item['quantity'];
        $revenue = $price * $quantity;
        $total += $revenue;
        $lines[] = [
            'product_id' => (string)($item['product_id'] ?? ''),
            'product' => (string)($item['product'] ?? ''),
            'quantity' => $quantity,
            'price' => $price,
            'revenue' => $revenue,
        ];
    }
    if (!$lines) {
        return ['ok' => false, 'error' => 'No cart data received.'];
    }
    return ['ok' => true, 'total' => round($total, 2), 'lines' => $lines];
}

function psEvaluatePayment($total, $amountRaw, $methodRaw, $referenceRaw = '', string $currency = 'USD'): array
{
    // Multi-currency: the tender currency is the sale currency (cross-currency tender is not enabled).
    $currency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'USD';
    $methods = psPaymentMethods();
    $method = strtoupper(trim((string)$methodRaw));
    if (!isset($methods[$method])) {
        return ['ok' => false, 'error' => 'Choose a valid payment method.'];
    }
    if (is_int($total)) {
        $saleCents = $total;
    } else {
        $saleCents = psDecimalCents(is_string($total) ? $total : psMoney((float)$total));
    }
    if ($saleCents === null || $saleCents < 0) {
        return ['ok' => false, 'error' => 'Enter a valid amount paid.'];
    }

    // Phase 6.2 — CREDIT: charge account for server sale total; ignore cash amount/change.
    // Eligibility (customer/account/limit) is enforced separately in submit_sale2.php.
    if ($method === 'CREDIT') {
        if ($saleCents <= 0) {
            return ['ok' => false, 'error' => 'Credit sales require a sale total greater than zero.'];
        }
        return [
            'ok' => true,
            'method' => $method,
            'method_label' => $methods[$method],
            'amount_paid' => psMoneyFromCents($saleCents),
            'change_amount' => psMoneyFromCents(0),
            'amount_cents' => $saleCents,
            'change_cents' => 0,
            'total_cents' => $saleCents,
            'currency' => $currency,
            'reference' => psNormalizeReference($referenceRaw),
            'is_credit' => true,
        ];
    }

    $paidCents = psDecimalCents($amountRaw);
    if ($paidCents === null) {
        return ['ok' => false, 'error' => 'Enter a valid amount paid.'];
    }
    if ($paidCents < $saleCents) {
        return ['ok' => false, 'error' => 'Amount paid is less than the sale total.'];
    }
    $changeCents = $paidCents - $saleCents;
    return [
        'ok' => true,
        'method' => $method,
        'method_label' => $methods[$method],
        'amount_paid' => psMoneyFromCents($paidCents),
        'change_amount' => psMoneyFromCents($changeCents),
        'amount_cents' => $paidCents,
        'change_cents' => $changeCents,
        'total_cents' => $saleCents,
        'currency' => $currency,
        'reference' => psNormalizeReference($referenceRaw),
        'is_credit' => false,
    ];
}

function psReceiptPaymentView(?array $row): array
{
    if (!$row || trim((string)($row['method'] ?? '')) === '') {
        return ['recorded' => false];
    }
    $currency = trim((string)($row['currency'] ?? ''));
    return [
        'recorded' => true,
        'method_label' => psPaymentMethodLabel($row['method']),
        'amount_paid' => (float)$row['amount_paid'],
        'change_amount' => (float)$row['change_amount'],
        'currency' => $currency !== '' ? $currency : 'USD',
        'reference' => trim((string)($row['reference'] ?? '')),
    ];
}

function psEnsureSalePayments(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS sale_payments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(250) NOT NULL,
        method VARCHAR(32) NOT NULL,
        amount_paid DECIMAL(12,2) NOT NULL,
        change_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        reference VARCHAR(120) NULL,
        cashier_id INT NULL,
        cashier_email VARCHAR(250) NULL,
        till_session_id INT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_sale_payments_invoice (invoice_number),
        KEY idx_sale_payments_method (method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale payment schema failed');
        return false;
    }
    $ready = true;
    return true;
}

function psPaymentForInvoice(mysqli $conn, $invoiceNumber): ?array
{
    if (!psEnsureSalePayments($conn)) {
        return null;
    }
    $invoice = mysqli_real_escape_string($conn, (string)$invoiceNumber);
    $res = mysqli_query(
        $conn,
        "SELECT method, amount_paid, change_amount, currency, reference
         FROM sale_payments
         WHERE invoice_number = '$invoice'
         ORDER BY id ASC
         LIMIT 1"
    );
    if (!$res) {
        error_log('sale payment lookup failed');
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

function psSaleTokenIssue(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_sale_token'] = $token;
    $_SESSION['ps_sale_token_at'] = time();
    return $token;
}

function psSaleTokenEnsure(): string
{
    $token = (string)($_SESSION['ps_sale_token'] ?? '');
    $at = (int)($_SESSION['ps_sale_token_at'] ?? 0);
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || (time() - $at) > PS_SALE_TOKEN_TTL) {
        return psSaleTokenIssue();
    }
    return $token;
}

function psSaleTokenCheck($posted): array
{
    $posted = trim((string)$posted);
    $current = (string)($_SESSION['ps_sale_token'] ?? '');
    $at = (int)($_SESSION['ps_sale_token_at'] ?? 0);
    $fresh = preg_match('/^[a-f0-9]{32}$/', $current)
        && (time() - $at) <= PS_SALE_TOKEN_TTL
        && strlen($current) === strlen($posted)
        && hash_equals($current, $posted);
    if (!$fresh) {
        return ['ok' => false, 'error' => 'This payment was already submitted.'];
    }
    return ['ok' => true, 'token' => $posted];
}

function psSaleTokenConsume(): void
{
    unset($_SESSION['ps_sale_token'], $_SESSION['ps_sale_token_at']);
}

function psSaleTokenTake($posted): array
{
    $check = psSaleTokenCheck($posted);
    if (!$check['ok']) {
        return $check;
    }
    psSaleTokenConsume();
    return $check;
}

function psEnsureColumn(mysqli $conn, string $table, string $column, string $definition): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res && mysqli_num_rows($res) > 0) {
        return true;
    }
    $ok = mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    if ($ok || mysqli_errno($conn) === 1060) {
        return true;
    }
    error_log('sale schema: column was not added');
    return false;
}

function psEnsureInvoiceSeq(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS sale_invoice_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale schema: invoice sequence was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM sale_invoice_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        return true;
    }
    $seed = mysqli_query($conn, "INSERT INTO sale_invoice_seq (id, next_number)
        SELECT 1, IFNULL((
            SELECT MAX(CAST(invoice_number AS UNSIGNED))
            FROM online_sales_ref
            WHERE invoice_number REGEXP '^[0-9]{1,9}$'
        ), 0) + 1");
    if ($seed || mysqli_errno($conn) === 1062) {
        return true;
    }
    error_log('sale schema: invoice sequence was not seeded');
    return false;
}

function psEnsureSaleIntegritySchema(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSalePayments($conn)) {
        return false;
    }
    if (!psEnsureColumn($conn, 'online_sales_ref', 'sold_at', 'DATETIME NULL DEFAULT NULL')) {
        return false;
    }
    if (!psEnsureColumn($conn, 'online_sales_ref', 'customer_id', 'INT NULL DEFAULT NULL')) {
        return false;
    }
    // Phase 5.2 — distinct from legacy customer_id (customers.id). No DB FK (app-validated).
    if (!psEnsureColumn($conn, 'online_sales_ref', 'customer_master_id', 'INT NULL DEFAULT NULL')) {
        return false;
    }
    $cmIdx = mysqli_query(
        $conn,
        "SHOW INDEX FROM online_sales_ref WHERE Key_name = 'idx_online_sales_ref_customer_master_id'"
    );
    if (!$cmIdx || mysqli_num_rows($cmIdx) < 1) {
        $addedCm = mysqli_query(
            $conn,
            'ALTER TABLE online_sales_ref ADD KEY idx_online_sales_ref_customer_master_id (customer_master_id)'
        );
        if (!$addedCm && mysqli_errno($conn) !== 1061) {
            error_log('sale schema: customer_master_id index was not added');
            return false;
        }
    }
    if (!psEnsureColumn($conn, 'online_sales', 'unit_price', 'DECIMAL(12,2) NULL DEFAULT NULL')) {
        return false;
    }
    if (!psEnsureColumn($conn, 'sale_payments', 'payment_token', 'VARCHAR(64) NULL DEFAULT NULL')) {
        return false;
    }
    $printCol = mysqli_query($conn, "SHOW COLUMNS FROM print_jobs LIKE 'invoice_number'");
    $printType = '';
    if ($printCol && ($printRow = mysqli_fetch_assoc($printCol))) {
        $printType = strtolower((string)$printRow['Type']);
    }
    if ($printType !== '' && strpos($printType, 'bigint') === false) {
        $widened = mysqli_query($conn, 'ALTER TABLE print_jobs MODIFY invoice_number BIGINT NOT NULL');
        if (!$widened) {
            error_log('sale schema: print invoice column was not widened');
            return false;
        }
    }
    $idx = mysqli_query($conn, "SHOW INDEX FROM sale_payments WHERE Key_name = 'uniq_sale_payment_token'");
    if (!$idx || mysqli_num_rows($idx) < 1) {
        $added = mysqli_query($conn, 'ALTER TABLE sale_payments ADD UNIQUE KEY uniq_sale_payment_token (payment_token)');
        if (!$added && mysqli_errno($conn) !== 1061) {
            error_log('sale schema: payment token index was not added');
            return false;
        }
    }
    return psEnsureInvoiceSeq($conn);
}

function psAllocateInvoiceNumber(mysqli $conn): ?int
{
    $res = mysqli_query($conn, 'SELECT next_number FROM sale_invoice_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('sale: invoice allocation failed');
        return null;
    }
    $floorRes = mysqli_query(
        $conn,
        "SELECT IFNULL(MAX(CAST(invoice_number AS UNSIGNED)), 0) AS n
         FROM online_sales_ref
         WHERE invoice_number REGEXP '^[0-9]{1,9}$'"
    );
    $floorRow = $floorRes ? mysqli_fetch_assoc($floorRes) : null;
    if (!$floorRow) {
        error_log('sale: invoice floor failed');
        return null;
    }
    $start = (int)$floorRow['n'] + 1;
    $seq = (int)$row['next_number'];
    if ($seq >= $start && $seq < 2000000000 && $seq <= $start + 1000) {
        $start = $seq;
    }
    if ($start < 1) {
        return null;
    }
    for ($step = 0; $step < 50; $step++) {
        $candidate = $start + $step;
        if ($candidate >= 2000000000) {
            break;
        }
        $candidateSql = (string)$candidate;
        $takenRef = mysqli_query($conn, "SELECT id FROM online_sales_ref WHERE invoice_number = '$candidateSql' LIMIT 1");
        $takenLine = mysqli_query($conn, "SELECT id FROM online_sales WHERE invoice_number = '$candidateSql' LIMIT 1");
        if (!$takenRef || !$takenLine) {
            error_log('sale: invoice lookup failed');
            return null;
        }
        if (mysqli_fetch_assoc($takenRef) || mysqli_fetch_assoc($takenLine)) {
            continue;
        }
        $next = $candidate + 1;
        if (!mysqli_query($conn, "UPDATE sale_invoice_seq SET next_number = $next WHERE id = 1")) {
            error_log('sale: invoice allocation update failed');
            return null;
        }
        return $candidate;
    }
    error_log('sale: invoice allocation found no free number');
    return null;
}

function psDecimalCents($raw): ?int
{
    if (is_bool($raw) || is_array($raw) || is_object($raw) || $raw === null) {
        return null;
    }
    $raw = trim((string)$raw);
    if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $raw, $match)) {
        return null;
    }
    if (strlen($match[1]) > 10) {
        return null;
    }
    $frac = $match[2] ?? '';
    $centsText = substr($frac . '000', 0, 3);
    $cents = (int)substr($centsText, 0, 2);
    if ((int)substr($centsText, 2, 1) >= 5) {
        $cents++;
    }
    $total = ((int)$match[1]) * 100 + $cents;
    if ($total < 0 || $total > 999999999999) {
        return null;
    }
    return $total;
}

function psMoneyFromCents(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function psLineCents(int $unitCents, string $qty): ?int
{
    if ($unitCents < 0 || !preg_match('/^(\d+)(?:\.(\d+))?$/', $qty, $match)) {
        return null;
    }
    $frac = substr(($match[2] ?? '') . '0000', 0, 4);
    $scale = ((int)$match[1]) * 10000 + (int)$frac;
    if ($scale <= 0) {
        return null;
    }
    if ($unitCents > 0 && $scale > intdiv(PHP_INT_MAX, $unitCents)) {
        return null;
    }
    return intdiv(($unitCents * $scale) + 5000, 10000);
}

function psMoney(float $amount): string
{
    $cents = psDecimalCents(sprintf('%.2f', $amount));
    if ($cents === null) {
        return sprintf('%.2f', $amount);
    }
    return psMoneyFromCents($cents);
}

/**
 * $priceOverrides (2026-10-17, quotations): product_id => [unit_cents, max_milli] — the quoted unit price is used for a
 * line whose quantity is within the quoted quantity; otherwise the branch price. Only built server-side from a locked,
 * open quotation (psQuoteForSale), never from the browser.
 */
function psBuildSaleLines(mysqli $conn, array $cart, string $branch, string $company, ?string $txnDate = null, array $priceOverrides = [], ?array $fx = null): array
{
    // Multi-currency (2026-10-20): $fx is the server snapshot (psFxSnapshot) of the sale currency. Branch prices are kept
    // in the company base; each unit price (and unit cost) is converted once with the exact rate and rounded to the
    // currency's decimals, then quantity and tax are worked out in the sale currency. Quoted prices already in the sale
    // currency (override 'currency' equal to the sale's) are not converted again.
    $fxOn = is_array($fx) && !empty($fx['currency']) && !empty($fx['base']) && $fx['currency'] !== $fx['base'];
    if ($fxOn) {
        require_once __DIR__ . '/currency.php';
    }
    $fxDec = $fxOn ? psFxDecimals($conn, $fx['currency']) : 2;
    require_once __DIR__ . '/product_master.php';
    require_once __DIR__ . '/tax.php';
    if (!$cart) {
        return ['ok' => false, 'error' => 'No products in the cart.'];
    }
    if (!psEnsureTaxModule($conn)) {
        return ['ok' => false, 'error' => 'Payment could not be completed. Please try again.'];
    }
    $lines = [];
    foreach ($cart as $item) {
        if (!is_array($item)) {
            return ['ok' => false, 'error' => 'No cart data received.'];
        }
        $qtyRaw = $item['quantity'] ?? null;
        if (is_bool($qtyRaw) || is_array($qtyRaw) || $qtyRaw === null) {
            return ['ok' => false, 'error' => 'Enter a valid quantity.'];
        }
        $qtyRaw = trim((string)$qtyRaw);
        if (preg_match('/^-\d+(\.\d+)?$/', $qtyRaw)) {
            return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
        }
        if ($qtyRaw === '' || !preg_match('/^\d+(\.\d+)?$/', $qtyRaw)) {
            return ['ok' => false, 'error' => 'Enter a valid quantity.'];
        }
        if (psLineCents(1, $qtyRaw) === null) {
            return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
        }
        $productId = trim((string)($item['product_id'] ?? ''));
        $identified = psActiveGoodsForSale($conn, $productId, $branch, $company);
        if (!$identified['ok']) {
            return ['ok' => false, 'error' => (string)$identified['error']];
        }
        $product = $identified['row'];
        // Authoritative branch price from goods. Client-submitted price/tax is ignored.
        $priceRaw = trim((string)$product['price']);
        $unitCents = psDecimalCents($priceRaw);
        if ($unitCents === null || $unitCents < 0) {
            return ['ok' => false, 'error' => 'A product price is not valid.'];
        }
        $baseUnitCents = $unitCents;
        if ($fxOn) {
            $unitCents = psFxFromBaseCents($conn, $fx, $unitCents);
        }
        $quoted = false;
        $ov = $priceOverrides[(string)$product['product_id']] ?? null;
        if ($ov !== null && (int)round((float)$qtyRaw * 1000) <= (int)$ov['max_milli']) {
            $unitCents = (int)$ov['unit_cents'];
            $ovCur = strtoupper(trim((string)($ov['currency'] ?? '')));
            if ($fxOn && ($ovCur === '' || $ovCur === $fx['base']) && $ovCur !== $fx['currency']) {
                $unitCents = psFxFromBaseCents($conn, $fx, $unitCents);
            }
            $quoted = true;
        }
        $lineCents = psLineCents($unitCents, $qtyRaw);
        if ($fxOn && $lineCents !== null) {
            $lineCents = psFxRoundCentsToDecimals($lineCents, $fxDec);
        }
        if ($lineCents === null || $lineCents < 0) {
            return ['ok' => false, 'error' => 'Sale total is not valid.'];
        }
        $qtySql = rtrim(rtrim(number_format((float)$qtyRaw, 3, '.', ''), '0'), '.');
        if ($qtySql === '' || $qtySql === '0') {
            return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
        }
        $name = trim((string)$product['product']);
        if (strlen($name) > 250) {
            $name = substr($name, 0, 250);
        }
        $lines[] = [
            'product_id' => (string)$product['product_id'],
            'product' => $name,
            'quantity' => $qtySql,
            'price' => psMoneyFromCents($unitCents),
            'revenue' => psMoneyFromCents($lineCents),
            // Health-check 2026-10-13: unit cost at time of sale, so profit reports have a real cost.
            // Multi-currency: kept in the sale currency like the price, so line margin stays meaningful.
            'cost_price' => (isset($product['cost_price']) && is_numeric($product['cost_price']))
                ? ($fxOn
                    ? psMoneyFromCents(psFxFromBaseCents($conn, $fx, (int)psDecimalCents(number_format((float)$product['cost_price'], 2, '.', ''))))
                    : number_format((float)$product['cost_price'], 2, '.', ''))
                : null,
            'base_price' => psMoneyFromCents($baseUnitCents),
            // Server product tax_code only — never from cart/browser.
            'tax_code' => isset($product['tax_code']) ? strtoupper(trim((string)$product['tax_code'])) : '',
            'quoted' => $quoted,
        ];
    }
    $saleDate = $txnDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($txnDate))
        ? trim($txnDate)
        : date('Y-m-d');
    $taxed = psTaxApplyToSaleLines($conn, $lines, $branch, $saleDate);
    if (!$taxed['ok']) {
        return ['ok' => false, 'error' => (string)($taxed['error'] ?? 'Sale total is not valid.')];
    }
    if ((int)$taxed['total_cents'] < 0) {
        return ['ok' => false, 'error' => 'Sale total is not valid.'];
    }
    return [
        'ok' => true,
        'total' => (string)$taxed['total'],
        'total_cents' => (int)$taxed['total_cents'],
        'lines' => $taxed['lines'],
        'taxable_amount' => $taxed['taxable_amount'],
        'tax_amount' => $taxed['tax_amount'],
        'tax_data_status' => $taxed['tax_data_status'],
        'tax_pricing_mode' => $taxed['tax_pricing_mode'],
    ];
}

/**
 * Phase 5.2 — optional customer_master identity for production POS sales.
 * Posted name/code cannot establish or override identity. Walk-in → NULL + Cash Customer.
 * Does not read or write legacy table `customers` / online_sales_ref.customer_id.
 *
 * @return array{ok:bool,id:?int,name:string,code:string,error?:string}
 */
function psResolveSaleCustomer(mysqli $conn, $postedMasterId, $postedName = ''): array
{
    unset($postedName); // display/name never establishes identity
    $idRaw = trim((string)$postedMasterId);
    if ($idRaw === '' || $idRaw === '0') {
        return ['ok' => true, 'id' => null, 'name' => 'Cash Customer', 'code' => ''];
    }
    if (!preg_match('/^\d+$/', $idRaw)) {
        return ['ok' => false, 'error' => 'Customer was not found.', 'id' => null, 'name' => '', 'code' => ''];
    }
    $id = (int)$idRaw;
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Customer was not found.', 'id' => null, 'name' => '', 'code' => ''];
    }
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['ok' => false, 'error' => 'Customer was not found.', 'id' => null, 'name' => '', 'code' => ''];
    }
    $row = psCustomerById($conn, $id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Customer was not found.', 'id' => null, 'name' => '', 'code' => ''];
    }
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    if ($status !== PS_CUSTOMER_STATUS_ACTIVE) {
        return ['ok' => false, 'error' => 'That customer is not active.', 'id' => null, 'name' => '', 'code' => ''];
    }
    $name = psCustomerDisplayName($row);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    if (!is_string($name) || $name === '') {
        $name = 'Cash Customer';
    }
    if (strlen($name) > 250) {
        $name = substr($name, 0, 250);
    }
    $code = trim((string)($row['customer_code'] ?? ''));
    return ['ok' => true, 'id' => $id, 'name' => $name, 'code' => $code];
}
