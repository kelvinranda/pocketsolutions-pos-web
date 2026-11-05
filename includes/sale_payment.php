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
    if ($code === 'SPLIT') {
        return 'Split payment'; // 2026-11-05: several methods / currencies on one sale
    }
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

/**
 * 2026-11-05 — split payment and split currency (web, desktop and mobile tills share these rules).
 *
 * A sale has one currency and total (unchanged). It may be paid with several tenders, each in its own method and
 * currency: [{method, currency, amount, reference}]. Rules:
 *  1. A tender in another currency counts at the rate in force at sale time, converted exactly into the sale currency
 *     (psFxRate tender -> sale, psFxConvertCents, half-up to the sale currency's decimals).
 *  2. The tenders together must cover the total. Only CASH gives change: non-cash tenders together may not exceed it.
 *  3. Change = covered - total, given in cash in the currency the cashier picks (default the sale currency), converted
 *     sale -> change currency at the same moment. The change currency must be one the company sells in.
 *  4. CREDIT (customer account) is a whole-sale method: it cannot be combined with other tenders.
 *  5. One tender in the sale currency is exactly the old single payment.
 *
 * Returns ['ok' => true, 'tenders' => [...], 'change' => {...}|null, 'paid_sale_cents', 'total_cents', 'is_credit',
 * 'is_split'] or ['ok' => false, 'error' => ...]. Each tender: method, method_label, currency, amount_cents (in its
 * currency), sale_cents (in the sale currency), rate (1 tender = rate sale; '1' for the sale currency), rate_ids,
 * reference. change: currency, amount_cents (in its currency), sale_cents, rate (1 sale = rate change), rate_ids.
 */
function psEvaluateTenders(mysqli $conn, string $company, string $saleCurrency, int $totalCents, array $tenders,
    string $changeCurrency = '', string $at = ''): array
{
    require_once __DIR__ . '/currency.php';
    $methods = psPaymentMethods();
    $saleCurrency = strtoupper(trim($saleCurrency)) !== '' ? strtoupper(trim($saleCurrency)) : 'USD';
    $at = $at !== '' ? $at : date('Y-m-d H:i:s');
    if ($totalCents < 0) {
        return ['ok' => false, 'error' => 'The sale total is not valid.'];
    }
    $tenders = array_values(array_filter($tenders, 'is_array'));
    if (!$tenders) {
        return ['ok' => false, 'error' => 'Add at least one payment.'];
    }
    if (count($tenders) > 8) {
        return ['ok' => false, 'error' => 'A sale can be paid with at most 8 payments.'];
    }
    $sellable = function_exists('psFxCompanyCurrencies') ? psFxCompanyCurrencies($conn, $company) : [];
    $currencyOk = static function (string $code) use ($sellable, $saleCurrency): bool {
        return $code === $saleCurrency || isset($sellable[$code]);
    };

    // CREDIT stays a whole-sale method (account currency = sale currency is checked by the caller).
    foreach ($tenders as $t) {
        if (psPaymentMethodIsCredit($t['method'] ?? '')) {
            if (count($tenders) > 1) {
                return ['ok' => false, 'error' => 'Credit (account) cannot be combined with other payments.'];
            }
            $single = psEvaluatePayment($totalCents, '', 'CREDIT', (string)($t['reference'] ?? ''), $saleCurrency);
            if (empty($single['ok'])) {
                return $single;
            }
            return ['ok' => true, 'is_credit' => true, 'is_split' => false, 'total_cents' => $totalCents,
                'paid_sale_cents' => $totalCents, 'change' => null,
                'tenders' => [['method' => 'CREDIT', 'method_label' => $methods['CREDIT'], 'currency' => $saleCurrency,
                    'amount_cents' => $totalCents, 'sale_cents' => $totalCents, 'rate' => '1', 'rate_ids' => '',
                    'reference' => $single['reference']]]];
        }
    }

    $out = [];
    $paidSale = 0;
    $nonCashSale = 0;
    $cashSale = 0;
    foreach ($tenders as $i => $t) {
        $n = $i + 1;
        $method = strtoupper(trim((string)($t['method'] ?? '')));
        if (!isset($methods[$method])) {
            return ['ok' => false, 'error' => "Payment $n: choose a valid payment method."];
        }
        $cur = strtoupper(trim((string)($t['currency'] ?? '')));
        $cur = $cur !== '' ? $cur : $saleCurrency;
        if (!preg_match('/^[A-Z]{3}$/', $cur) || !$currencyOk($cur)) {
            return ['ok' => false, 'error' => "Payment $n: $cur is not a currency this company sells in."];
        }
        $cents = psDecimalCents($t['amount'] ?? '');
        if ($cents === null || $cents <= 0) {
            return ['ok' => false, 'error' => "Payment $n: enter an amount above zero."];
        }
        if ($cur === $saleCurrency) {
            $saleCents = $cents;
            $rate = '1';
            $rateIds = '';
        } else {
            $r = psFxRate($conn, $company, $cur, $saleCurrency, $at);
            if (empty($r['ok'])) {
                return ['ok' => false, 'error' => "Payment $n: no exchange rate from $cur to $saleCurrency."];
            }
            $saleCents = psFxConvertCents($cents, $r['frac'], psFxDecimals($conn, $saleCurrency));
            $rate = psFxFracToDecimal($r['frac'], 10);
            $rateIds = implode(',', $r['ids'] ?? []);
        }
        $paidSale += $saleCents;
        if ($method === 'CASH') {
            $cashSale += $saleCents;
        } else {
            $nonCashSale += $saleCents;
        }
        $out[] = ['method' => $method, 'method_label' => $methods[$method], 'currency' => $cur, 'amount_cents' => $cents,
            'sale_cents' => $saleCents, 'rate' => $rate, 'rate_ids' => substr($rateIds, 0, 60),
            'reference' => $method === 'CASH' ? '' : psNormalizeReference($t['reference'] ?? '')];
    }
    if ($nonCashSale > $totalCents) {
        return ['ok' => false, 'error' => 'Card, EcoCash and other non-cash payments cannot be more than the total. Only cash gets change.'];
    }
    if ($paidSale < $totalCents) {
        return ['ok' => false, 'error' => 'The payments are short of the total by ' . psMoneyFromCents($totalCents - $paidSale) . ' ' . $saleCurrency . '.'];
    }

    $change = null;
    $changeSale = $paidSale - $totalCents;
    if ($changeSale > 0) {
        $cc = strtoupper(trim($changeCurrency));
        $cc = $cc !== '' ? $cc : $saleCurrency;
        if (!preg_match('/^[A-Z]{3}$/', $cc) || !$currencyOk($cc)) {
            return ['ok' => false, 'error' => "Change cannot be given in $cc."];
        }
        if ($cc === $saleCurrency) {
            $change = ['currency' => $cc, 'amount_cents' => $changeSale, 'sale_cents' => $changeSale, 'rate' => '1', 'rate_ids' => ''];
        } else {
            $r = psFxRate($conn, $company, $saleCurrency, $cc, $at);
            if (empty($r['ok'])) {
                return ['ok' => false, 'error' => "No exchange rate from $saleCurrency to $cc for the change."];
            }
            $change = ['currency' => $cc, 'amount_cents' => psFxConvertCents($changeSale, $r['frac'], psFxDecimals($conn, $cc)),
                'sale_cents' => $changeSale, 'rate' => psFxFracToDecimal($r['frac'], 10), 'rate_ids' => substr(implode(',', $r['ids'] ?? []), 0, 60)];
        }
    }
    $isSplit = count($out) > 1 || $out[0]['currency'] !== $saleCurrency || ($change !== null && $change['currency'] !== $saleCurrency);
    return ['ok' => true, 'is_credit' => false, 'is_split' => $isSplit, 'total_cents' => $totalCents,
        'paid_sale_cents' => $paidSale, 'tenders' => $out, 'change' => $change];
}

/**
 * Tenders from a request: the new `payments` list (JSON string or array), or the old single fields
 * (payment_method / amount_paid / payment_reference) as one tender in the sale currency.
 */
function psTendersFromRequest(array $src, string $saleCurrency): array
{
    $raw = $src['payments'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $raw = json_decode($raw, true);
    }
    if (is_array($raw) && $raw) {
        $list = [];
        foreach (array_slice($raw, 0, 8) as $t) {
            if (is_array($t)) {
                $list[] = ['method' => (string)($t['method'] ?? ''), 'currency' => (string)($t['currency'] ?? ''),
                    'amount' => (string)($t['amount'] ?? ''), 'reference' => (string)($t['reference'] ?? '')];
            }
        }
        return $list;
    }
    return [['method' => (string)($src['payment_method'] ?? ''), 'currency' => $saleCurrency,
        'amount' => (string)($src['amount_paid'] ?? ''), 'reference' => (string)($src['payment_reference'] ?? '')]];
}

/**
 * Insert one sale_payments row per tender and one change row (is_change = 1). $token makes every row unique
 * (token-1, token-2 …, token-C). The first tender row keeps the old meaning for old readers:
 * amount_paid in its currency and change_amount (only when it is the single tender in the sale currency).
 */
function psInsertSaleTenders(mysqli $conn, string $invoice, array $eval, array $who, string $token): bool
{
    $single = count($eval['tenders']) === 1 && !$eval['is_split'];
    $changeCents = $eval['change']['amount_cents'] ?? 0;
    $stmt = mysqli_prepare($conn, 'INSERT INTO sale_payments
        (invoice_number, method, amount_paid, change_amount, currency, reference, cashier_id, cashier_email, till_session_id,
         created_at, payment_token, tender_seq, sale_amount, tender_rate, tender_rate_ids, is_change)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    $rows = [];
    foreach ($eval['tenders'] as $i => $t) {
        $rows[] = [$t['method'], psMoneyFromCents($t['amount_cents']), $single ? psMoneyFromCents($changeCents) : '0.00',
            $t['currency'], $t['reference'] !== '' ? $t['reference'] : null, $token !== '' ? $token . '-' . ($i + 1) : null,
            $i + 1, psMoneyFromCents($t['sale_cents']), $t['rate'], $t['rate_ids'] !== '' ? $t['rate_ids'] : null, 0];
    }
    if (!$single && $eval['change'] !== null) {
        $c = $eval['change'];
        $rows[] = ['CASH', psMoneyFromCents($c['amount_cents']), '0.00', $c['currency'], null,
            $token !== '' ? $token . '-C' : null, count($eval['tenders']) + 1, psMoneyFromCents($c['sale_cents']),
            $c['rate'], $c['rate_ids'] !== '' ? $c['rate_ids'] : null, 1];
    }
    $cashierId = $who['cashier_id'] ?? null;
    $cashierEmail = $who['cashier_email'] ?? null;
    $session = $who['till_session_id'] ?? null;
    $created = $who['created_at'] ?? date('Y-m-d H:i:s');
    foreach ($rows as $r) {
        [$method, $paid, $chg, $cur, $ref, $tok, $seq, $saleAmt, $rate, $rateIds, $isChange] = $r;
        mysqli_stmt_bind_param($stmt, 'ssssssisississsi', $invoice, $method, $paid, $chg, $cur, $ref, $cashierId, $cashierEmail,
            $session, $created, $tok, $seq, $saleAmt, $rate, $rateIds, $isChange);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return true;
}

/**
 * Net cash per currency for a sale: cash tendered in that currency minus change given in it (change can make it
 * negative, e.g. a ZiG sale paid in USD with change in ZiG). Feeds one CASH_SALE movement per currency drawer.
 *
 * @return array<string,int> currency => cents
 */
function psTenderCashByCurrency(array $eval): array
{
    $net = [];
    foreach ($eval['tenders'] as $t) {
        if ($t['method'] === 'CASH') {
            $net[$t['currency']] = ($net[$t['currency']] ?? 0) + $t['amount_cents'];
        }
    }
    if (!empty($eval['change'])) {
        $c = $eval['change'];
        $net[$c['currency']] = ($net[$c['currency']] ?? 0) - $c['amount_cents'];
    }
    return array_filter($net, static fn($v) => $v !== 0);
}

/** "Paid EcoCash $20.00" lines for receipts; the change line last. $fmt formats (cents, currency). */
function psTenderReceiptLines(array $eval, callable $fmt): array
{
    $lines = [];
    foreach ($eval['tenders'] as $t) {
        $lines[] = ['Paid ' . $t['method_label'] . ($t['reference'] !== '' ? ' (' . $t['reference'] . ')' : ''), $fmt($t['amount_cents'], $t['currency'])];
    }
    if (!empty($eval['change'])) {
        $lines[] = ['Change', $fmt($eval['change']['amount_cents'], $eval['change']['currency'])];
    }
    return $lines;
}

/**
 * 2026-11-05: the payment lines of a sale for receipts — one per tender, each in its own currency, then the change.
 * Returns [] for a sale with a single payment row in its sale currency (show the classic Method / Paid / Change).
 *
 * @return list<array{label:string,amount:string,currency:string}>
 */
function psSaleTenderLines(mysqli $conn, string $invoiceNumber): array
{
    if (!psEnsureSalePayments($conn)) {
        return [];
    }
    $stmt = mysqli_prepare($conn, 'SELECT method, amount_paid, currency, reference, is_change, tender_seq FROM sale_payments
        WHERE invoice_number = ? ORDER BY tender_seq IS NULL, tender_seq, id');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    if (count($rows) < 2 || $rows[0]['tender_seq'] === null) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $ref = trim((string)($r['reference'] ?? ''));
        $out[] = [
            'label' => (int)$r['is_change'] === 1 ? 'Change' : 'Paid ' . psPaymentMethodLabel($r['method']) . ($ref !== '' ? ' (' . $ref . ')' : ''),
            'amount' => (string)$r['amount_paid'],
            'currency' => (string)$r['currency'],
        ];
    }
    return $out;
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
    // 2026-11-05 split payment / split currency: one row per tender (+ one change row). Older rows (one per sale) have
    // tender_seq NULL, is_change 0 and sale_amount NULL = amount_paid - change_amount in the sale currency.
    foreach ([
        'tender_seq' => 'SMALLINT NULL DEFAULT NULL',
        'sale_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL',
        'tender_rate' => 'DECIMAL(24,10) NULL DEFAULT NULL',
        'tender_rate_ids' => 'VARCHAR(60) NULL DEFAULT NULL',
        'is_change' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $col => $def) {
        if (!psEnsureColumn($conn, 'sale_payments', $col, $def)) {
            return false;
        }
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
/**
 * Spread a sale-level discount over lines by line amount (revenue cents), largest remainder, as discount_cents.
 *
 * @return array{ok:bool,lines?:array,error?:string}
 */
function psSpreadSaleDiscount(array $lines, int $discountCents): array
{
    $amounts = [];
    $total = 0;
    foreach ($lines as $i => $l) {
        $c = (int)psDecimalCents((string)($l['revenue'] ?? '0'));
        $amounts[$i] = max(0, $c);
        $total += $amounts[$i];
    }
    if ($discountCents > $total) {
        return ['ok' => false, 'error' => 'The discount is more than the sale amount.'];
    }
    if ($total === 0 || $discountCents === 0) {
        return ['ok' => true, 'lines' => $lines];
    }
    $given = 0;
    $rema = [];
    foreach ($amounts as $i => $c) {
        $exact = $discountCents * $c;
        $share = intdiv($exact, $total);
        $lines[$i]['discount_cents'] = $share;
        $given += $share;
        $rema[$i] = $exact % $total;
    }
    arsort($rema);
    foreach (array_keys($rema) as $i) {
        if ($given >= $discountCents) {
            break;
        }
        if ($lines[$i]['discount_cents'] < $amounts[$i]) {
            $lines[$i]['discount_cents']++;
            $given++;
        }
    }
    return ['ok' => true, 'lines' => $lines];
}

function psBuildSaleLines(mysqli $conn, array $cart, string $branch, string $company, ?string $txnDate = null, array $priceOverrides = [], ?array $fx = null, int $saleDiscountCents = 0): array
{
    // $saleDiscountCents (2026-10-24, loyalty redemption): a sale-level discount in the sale currency, spread over the
    // lines in proportion to their amounts (largest remainder) as each line's discount_cents, so tax is worked out on the
    // discounted amount by psTaxApplyToSaleLines. It may not exceed the cart amount.
    if ($saleDiscountCents < 0) {
        return ['ok' => false, 'error' => 'Discount cannot be negative.'];
    }
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
    // 2026-10-28 packs: per product, its packs and whole-unit rule (includes/product_packs.php).
    require_once __DIR__ . '/product_packs.php';
    $packInfo = [];
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
        $pid = (string)$product['product_id'];
        if (!array_key_exists($pid, $packInfo)) {
            $packInfo[$pid] = psPackProductInfo($conn, $company, $pid);
        }
        $pinfo = $packInfo[$pid];
        $whole = $pinfo !== null && !empty($pinfo['whole']);
        $baseLabel = $pinfo !== null ? (string)$pinfo['base_label'] : 'unit';
        $packId = (int)($item['pack_id'] ?? 0);
        if ($packId > 0) {
            // Sold in a pack: the pack's own price (company base, converted like every price), whole packs only;
            // the line quantity becomes base units (packs × units per pack) for stock, cost and reports.
            $pack = psPackGet($conn, $company, $packId);
            if (!$pack || $pack['product_id'] !== $pid || !$pack['active'] || $pack['price'] === null) {
                return ['ok' => false, 'error' => 'A pack of ' . trim((string)$product['product']) . ' is no longer sold. Remove it from the cart and add it again.'];
            }
            $conv = psPackToBase($qtyRaw, $pack, $whole, $baseLabel);
            if (empty($conv['ok'])) {
                return ['ok' => false, 'error' => trim((string)$product['product']) . ': ' . $conv['error']];
            }
            $packCents = (int)psDecimalCents((string)$pack['price']);
            if ($fxOn) {
                $packCents = psFxFromBaseCents($conn, $fx, $packCents);
            }
            $packLine = psLineCents($packCents, $qtyRaw);
            if ($fxOn && $packLine !== null) {
                $packLine = psFxRoundCentsToDecimals($packLine, $fxDec);
            }
            if ($packLine === null || $packLine < 0) {
                return ['ok' => false, 'error' => 'Sale total is not valid.'];
            }
            $baseMilli = (int)$conv['base_milli'];
            $name = trim((string)$product['product']);
            if (strlen($name) > 250) {
                $name = substr($name, 0, 250);
            }
            $lines[] = [
                'product_id' => $pid,
                'product' => $name,
                'quantity' => psStockQtyPlain(psMilliToStock($baseMilli)),
                // Price per base unit (rounded, display only); the line amount is the exact pack amount (pre_cents).
                'price' => psMoneyFromCents(intdiv($packLine * 1000 * 2 + $baseMilli, 2 * $baseMilli)),
                'pre_cents' => $packLine,
                'revenue' => psMoneyFromCents($packLine),
                'cost_price' => (isset($product['cost_price']) && is_numeric($product['cost_price']))
                    ? ($fxOn
                        ? psMoneyFromCents(psFxFromBaseCents($conn, $fx, (int)psDecimalCents(number_format((float)$product['cost_price'], 2, '.', ''))))
                        : number_format((float)$product['cost_price'], 2, '.', ''))
                    : null,
                'base_price' => (string)$pack['price'],
                'tax_code' => isset($product['tax_code']) ? strtoupper(trim((string)$product['tax_code'])) : '',
                'quoted' => false,
                'pack_id' => (int)$pack['id'],
                'sell_unit' => (string)$pack['name'],
                'sell_qty' => psPackQtyText((int)$conv['qty_milli']),
                'sell_factor' => (string)$pack['factor'],
                'sell_price' => psMoneyFromCents($packCents),
                'base_label' => $baseLabel,
            ];
            continue;
        }
        if ($whole) {
            $conv = psPackToBase($qtyRaw, null, true, $baseLabel);
            if (empty($conv['ok'])) {
                return ['ok' => false, 'error' => trim((string)$product['product']) . ': ' . $conv['error']];
            }
        }
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
            'base_label' => $pinfo !== null && trim((string)($pinfo['base_unit'] ?? '')) !== '' ? $baseLabel : '',
        ];
    }
    $saleDate = $txnDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($txnDate))
        ? trim($txnDate)
        : date('Y-m-d');
    if ($saleDiscountCents > 0) {
        $spread = psSpreadSaleDiscount($lines, $saleDiscountCents);
        if (empty($spread['ok'])) {
            return ['ok' => false, 'error' => (string)$spread['error']];
        }
        $lines = $spread['lines'];
    }
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
