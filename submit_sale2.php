<?php
require_once __DIR__ . '/includes/till_session.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/customer_account.php';
require_once __DIR__ . '/includes/cash_movement.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/zimra.php';
require_once __DIR__ . '/includes/currency.php';

function psSaleAbort(mysqli $conn, bool $open, string $error): array
{
    if ($open) {
        mysqli_rollback($conn);
    }
    return ['success' => false, 'error' => $error];
}

function psPostedTillId(array $post): int
{
    if (isset($post['till_session_id']) && $post['till_session_id'] !== '') {
        return (int)$post['till_session_id'];
    }
    if (isset($post['session_id']) && $post['session_id'] !== '') {
        return (int)$post['session_id'];
    }
    return 0;
}

function psSaleMovementQuantity(string $soldQty): ?string
{
    $decimal = psStockDecimal($soldQty);
    if ($decimal === null || psStockDecimalCmp($decimal, '0') <= 0) {
        return null;
    }
    return '-' . $decimal;
}

function psGuardSaleStock(mysqli $conn, array $lines, string $branch): array
{
    $grouped = [];
    $order = [];
    foreach ($lines as $item) {
        $productId = trim((string)($item['product_id'] ?? ''));
        $milli = psStockMilli($item['quantity'] ?? '');
        if ($productId === '' || $milli === null || $milli <= 0) {
            error_log('sale: stock quantity was not valid');
            return ['ok' => false, 'error' => 'Payment could not be completed. Please try again.'];
        }
        if (!isset($grouped[$productId])) {
            $name = trim((string)($item['product'] ?? ''));
            if ($name === '') {
                $name = $productId;
            }
            $grouped[$productId] = ['milli' => 0, 'name' => $name];
            $order[] = $productId;
        }
        $grouped[$productId]['milli'] += $milli;
    }

    $failures = [];
    // 2026-10-14: selling below zero is allowed while 'allow_negative_stock' is on (default).
    // The ledger rows are still locked and the SALE movement still reduces stock.
    $allowNegative = psAllowNegativeStockSales($conn);
    foreach ($order as $productId) {
        $ledger = psSaleLedgerBalance($conn, $productId, $branch);
        if (empty($ledger['ok'])) {
            return ['ok' => false, 'error' => 'Payment could not be completed. Please try again.'];
        }
        // Not migrated until an OPENING_BALANCE row exists. A SALE or goods receipt alone does not block the sale.
        if (empty($ledger['tracked']) || $allowNegative) {
            continue;
        }
        $requested = (int)$grouped[$productId]['milli'];
        $available = (int)$ledger['milli'];
        if ($requested > $available) {
            $failures[] = 'Insufficient stock for ' . $grouped[$productId]['name']
                . '. Requested: ' . psStockQtyPlain(psMilliToStock($requested))
                . '. Available: ' . psStockQtyPlain(psMilliToStock($available)) . '.';
        }
    }
    if ($failures) {
        return ['ok' => false, 'error' => implode(' ', $failures)];
    }
    return ['ok' => true];
}

function psCompleteSale(mysqli $conn, array $post): array
{
    mysqli_report(MYSQLI_REPORT_OFF);

    if (trim((string)($_SESSION['logger'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Please sign in again.'];
    }
    if (function_exists('psPlatformIsActing') && (psPlatformIsActing() || psPlatformSessionIsSuperAdmin())) {
        return ['success' => false, 'error' => psPlatformMoneyBlockedMessage()];
    }

    $cart = $post['cart'] ?? null;
    if (is_string($cart)) {
        $cart = json_decode($cart, true);
    }
    if (!is_array($cart)) {
        return ['success' => false, 'error' => 'No cart data received.'];
    }

    if (!tillEnsureSchema($conn) || !psEnsureSaleIntegritySchema($conn) || !stockMovementEnsureSchema($conn) || !psEnsureCashMovementModule($conn) || !psEnsureTaxModule($conn)) {
        return ['success' => false, 'error' => 'Payment could not be completed. Please try again.'];
    }

    $signedIn = tillCurrentCashier($conn);
    if (isset($post['cashier_id']) && trim((string)$post['cashier_id']) !== '' && (int)$post['cashier_id'] !== (int)$signedIn['id']) {
        return ['success' => false, 'error' => "You cannot access another cashier's till session."];
    }
    if (isset($post['cashier_email']) && trim((string)$post['cashier_email']) !== '') {
        $postedCashier = strtolower(trim((string)$post['cashier_email']));
        $knownCashier = strtolower(trim((string)($signedIn['email'] ?? '')));
        $loggerCashier = strtolower(trim((string)($_SESSION['logger'] ?? '')));
        if ($postedCashier !== $knownCashier && $postedCashier !== $loggerCashier) {
            return ['success' => false, 'error' => "You cannot access another cashier's till session."];
        }
    }

    // 2026-10-17: sale from a quotation — tables checked before the transaction (DDL would commit it).
    $quoteId = (int)($post['quotation_id'] ?? 0);
    if ($quoteId > 0) {
        require_once __DIR__ . '/includes/quotation.php';
        if (!psQuoteEnsureSchema($conn)) {
            return ['success' => false, 'error' => 'Payment could not be completed. Please try again.'];
        }
    }

    // Multi-currency (2026-10-20): validate the sale currency and take the rate snapshot before the transaction
    // (column checks may run DDL). The rate is read again inside the transaction; if it changed after the cashier saw
    // the converted prices, the sale is refused and the till reprices with the new rate.
    if (!psFxEnsureTransactionColumns($conn)) {
        return ['success' => false, 'error' => 'Payment could not be completed. Please try again.'];
    }
    $fxCompany = trim((string)($_SESSION['the_company'] ?? ''));
    $fxCheck = psFxCheckTransactionCurrency($conn, $fxCompany, $post['currency'] ?? '');
    if (empty($fxCheck['ok'])) {
        return ['success' => false, 'error' => (string)$fxCheck['error']];
    }
    $saleCurrency = (string)$fxCheck['code'];

    $tokenCheck = psSaleTokenCheck($post['payment_token'] ?? '');
    if (!$tokenCheck['ok']) {
        return ['success' => false, 'error' => $tokenCheck['error']];
    }
    $paymentToken = (string)$tokenCheck['token'];

    if (!mysqli_begin_transaction($conn)) {
        return psSaleAbort($conn, false, 'Payment could not be completed. Please try again.');
    }

    $till = tillLockSaleSession($conn, psPostedTillId($post));
    if (!empty($till['error'])) {
        return psSaleAbort($conn, true, $till['error']);
    }

    // Sale branch is always the open till's branch (tillLockSaleSession already aligned
    // selected_branch to the till when authorised). Never compare against home
    // $_SESSION['branch'] — e.g. home Westlea + open Village till must still sell on Village.
    require_once __DIR__ . '/includes/branch_access.php';
    $branch = psBranchNormalizeName($till['branch'] ?? '');
    if ($branch === '') {
        return psSaleAbort(
            $conn,
            true,
            'This till has no branch assigned. Open Cash Reconciliation to bind it, then try again.'
        );
    }
    // Tamper probes only — ignore blank posts; never treat home branch as a claim.
    foreach (['branch', 'branch_id', 'selected_branch'] as $branchKey) {
        if (!isset($post[$branchKey]) || trim((string)$post[$branchKey]) === '') {
            continue;
        }
        if (psBranchNormalizeName($post[$branchKey]) !== $branch) {
            return psSaleAbort(
                $conn,
                true,
                'Your till is open on ' . $branch
                . '. Close that till before selling on another branch, or switch working branch back to '
                . $branch . '.'
            );
        }
    }

    $company = trim((string)($_SESSION['the_company'] ?? ''));
    if (!psEnsureGoodsStatus($conn)) {
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }
    $quote = null;
    $overrides = [];
    if ($quoteId > 0) {
        $qs = psQuoteForSale($conn, $quoteId, $company, $branch);
        if (empty($qs['ok'])) {
            return psSaleAbort($conn, true, (string)$qs['error']);
        }
        $quote = $qs['quote'];
        $overrides = $qs['overrides'];
    }
    $fxNow = date('Y-m-d H:i:s');
    $fxSnap = psFxSnapshot($conn, $company, $saleCurrency, $fxNow);
    if (empty($fxSnap['ok'])) {
        return psSaleAbort($conn, true, (string)$fxSnap['error']);
    }
    $fxSeen = trim((string)($post['fx_rate_seen'] ?? ''));
    if ($saleCurrency !== $fxSnap['base'] && $fxSeen !== '' && $fxSeen !== (string)$fxSnap['rate']) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'error' => 'The exchange rate changed to ' . psFxRateLabel($fxSnap['base'], $saleCurrency, $fxSnap['rate'])
                . '. Prices have been updated — check the total and pay again.',
            'fx' => ['code' => $saleCurrency, 'ok' => true, 'rate' => $fxSnap['rate'], 'num' => $fxSnap['frac'][0], 'den' => $fxSnap['frac'][1],
                'label' => psFxRateLabel($fxSnap['base'], $saleCurrency, $fxSnap['rate'])],
        ];
    }
    if ($quote !== null) {
        $quoteCur = psFxNormalizeCode($quote['currency'] ?? '') ?: $fxSnap['base'];
        if ($quoteCur !== $saleCurrency) {
            return psSaleAbort($conn, true, 'Quotation ' . $quote['quote_number'] . ' is in ' . $quoteCur . '. Switch the till currency to ' . $quoteCur . ' to sell it.');
        }
        foreach ($overrides as $k => $ov) {
            $overrides[$k]['currency'] = $quoteCur;
        }
    }
    $cartSale = psBuildSaleLines($conn, $cart, $branch, $company, null, $overrides, $fxSnap);
    if (!$cartSale['ok']) {
        return psSaleAbort($conn, true, $cartSale['error']);
    }

    $payment = psEvaluatePayment(
        (int)$cartSale['total_cents'],
        $post['amount_paid'] ?? '',
        $post['payment_method'] ?? '',
        $post['payment_reference'] ?? '',
        $saleCurrency
    );
    if (!$payment['ok']) {
        return psSaleAbort($conn, true, $payment['error']);
    }
    if (((int)$payment['amount_cents'] - (int)$payment['change_cents']) !== (int)$cartSale['total_cents']) {
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }

    // Only customer_master_id — never treat legacy customer_id / name / code as identity.
    $customer = psResolveSaleCustomer($conn, $post['customer_master_id'] ?? '', $post['customer_name'] ?? '');
    if (!$customer['ok']) {
        return psSaleAbort($conn, true, $customer['error']);
    }

    // Phase 6.2 / 6.5 — CREDIT: ACTIVE customer + ACTIVE account + credit_status=NORMAL + facility;
    // lock account. ON_HOLD rejects here (same server decision point). Posted balance / limit /
    // credit_status / eligibility / sale total ignored; server cart total is authoritative.
    $creditAccountId = null;
    if (!empty($payment['is_credit']) || psPaymentMethodIsCredit($payment['method'])) {
        // Multi-currency: a customer account runs in one currency; credit sales must be in it.
        if ($customer['id'] !== null && function_exists('psCustomerAccountCurrency')) {
            $acctCur = psCustomerAccountCurrency($conn, (int)$customer['id'], $company);
            if ($acctCur !== '' && $acctCur !== $saleCurrency) {
                return psSaleAbort($conn, true, 'This customer account is kept in ' . $acctCur . '. Switch the till currency to ' . $acctCur . ' for a credit sale.');
            }
        }
        $creditCheck = psCustomerAccountEvaluateCreditSale(
            $conn,
            $customer['id'],
            (string)$cartSale['total'],
            ['lock' => true]
        );
        if (!$creditCheck['ok']) {
            return psSaleAbort($conn, true, $creditCheck['error']);
        }
        $creditAccountId = (int)$creditCheck['account']['id'];
    }

    $stock = psGuardSaleStock($conn, $cartSale['lines'], $branch);
    if (!$stock['ok']) {
        return psSaleAbort($conn, true, $stock['error']);
    }

    $invoiceNumber = psAllocateInvoiceNumber($conn);
    if ($invoiceNumber === null) {
        error_log('sale: invoice number was not allocated');
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }

    $cashier = $till['cashier'];
    $cashierEmail = trim((string)($cashier['email'] ?? ''));
    if ($cashierEmail === '') {
        $cashierEmail = trim((string)($_SESSION['logger'] ?? ''));
    }
    $tillSessionId = (int)$till['session']['id'];
    $saleTotal = (string)$cartSale['total'];
    $paidSql = (string)$payment['amount_paid'];
    $changeSql = (string)$payment['change_amount'];
    $now = date('Y-m-d H:i:s');
    $saleDate = date('Y-m-d');
    $customerSql = mysqli_real_escape_string($conn, $customer['name']);
    $cashierSql = mysqli_real_escape_string($conn, $cashierEmail);
    $branchSql = mysqli_real_escape_string($conn, $branch);
    $methodSql = mysqli_real_escape_string($conn, $payment['method']);
    $currencySql = mysqli_real_escape_string($conn, $payment['currency']);
    $tokenSql = mysqli_real_escape_string($conn, $paymentToken);
    $nowSql = mysqli_real_escape_string($conn, $now);
    $dateSql = mysqli_real_escape_string($conn, $saleDate);
    // Legacy customer_id = customers.id (untouched). Master identity = customer_master_id.
    $customerMasterIdSql = $customer['id'] === null ? 'NULL' : (string)(int)$customer['id'];
    $cashierIdSql = ((int)($cashier['id'] ?? 0) > 0) ? (string)(int)$cashier['id'] : 'NULL';
    $referenceSql = $payment['reference'] === ''
        ? 'NULL'
        : "'" . mysqli_real_escape_string($conn, $payment['reference']) . "'";

    $taxDataStatus = (string)($cartSale['tax_data_status'] ?? PS_TAX_STATUS_UNCONFIGURED);
    $taxDataStatusSql = mysqli_real_escape_string($conn, $taxDataStatus);
    $headerTaxableSql = $cartSale['taxable_amount'] === null
        ? 'NULL'
        : "'" . mysqli_real_escape_string($conn, (string)$cartSale['taxable_amount']) . "'";
    $headerTaxSql = $cartSale['tax_amount'] === null
        ? 'NULL'
        : "'" . mysqli_real_escape_string($conn, (string)$cartSale['tax_amount']) . "'";
    $headerModeSql = ($cartSale['tax_pricing_mode'] ?? null) === null || $cartSale['tax_pricing_mode'] === ''
        ? 'NULL'
        : "'" . mysqli_real_escape_string($conn, (string)$cartSale['tax_pricing_mode']) . "'";

    $fxV = psFxSnapshotSqlValues($conn, $fxSnap);
    $baseTotalSql = psFxCentsToMoney(psFxToBaseCents($conn, $fxSnap, (int)$cartSale['total_cents']));
    $saleCurrencySql = mysqli_real_escape_string($conn, $saleCurrency);
    // method keeps its old meaning (the sale currency marker); currency + snapshot columns are authoritative.
    $sqlRef = "INSERT INTO online_sales_ref
        (invoice_number, totalz, method, cashier, customer, status, date_, branch, till_session_id, sold_at, customer_id, customer_master_id,
         taxable_amount, tax_amount, tax_pricing_mode, tax_data_status,
         currency, base_currency, fx_rate, fx_rate_ids, fx_path, base_total)
        VALUES
        ($invoiceNumber, '$saleTotal', '$saleCurrencySql', '$cashierSql', '$customerSql', '', '$dateSql', '$branchSql', $tillSessionId, '$nowSql', NULL, $customerMasterIdSql,
         $headerTaxableSql, $headerTaxSql, $headerModeSql, '$taxDataStatusSql',
         {$fxV['currency']}, {$fxV['base_currency']}, {$fxV['fx_rate']}, {$fxV['fx_rate_ids']}, {$fxV['fx_path']}, '$baseTotalSql')";
    if (!mysqli_query($conn, $sqlRef)) {
        error_log('sale: header insert failed');
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }
    $headerId = (int)mysqli_insert_id($conn);

    foreach ($cartSale['lines'] as $item) {
        $productId = mysqli_real_escape_string($conn, $item['product_id']);
        $product = mysqli_real_escape_string($conn, $item['product']);
        $quantity = mysqli_real_escape_string($conn, $item['quantity']);
        $revenue = (string)$item['revenue'];
        $unitPrice = (string)$item['price'];
        $costSql = (isset($item['cost_price']) && $item['cost_price'] !== null && is_numeric($item['cost_price']))
            ? "'" . mysqli_real_escape_string($conn, (string)$item['cost_price']) . "'"
            : "'0'"; // column is legacy NOT NULL-safe; 0 = unknown cost (reports fall back to goods.cost_price)
        $lineTaxStatus = mysqli_real_escape_string($conn, (string)($item['tax_data_status'] ?? PS_TAX_STATUS_UNCONFIGURED));
        $taxCodeSql = empty($item['tax_code'])
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['tax_code']) . "'";
        $taxClassSql = empty($item['tax_classification'])
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['tax_classification']) . "'";
        $taxRateSql = !isset($item['tax_rate_percent']) || $item['tax_rate_percent'] === null || $item['tax_rate_percent'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['tax_rate_percent']) . "'";
        $taxModeSql = empty($item['tax_pricing_mode'])
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['tax_pricing_mode']) . "'";
        $discSql = !isset($item['discount_amount']) || $item['discount_amount'] === null || $item['discount_amount'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['discount_amount']) . "'";
        $taxableSql = !isset($item['taxable_amount']) || $item['taxable_amount'] === null || $item['taxable_amount'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['taxable_amount']) . "'";
        $taxAmtSql = !isset($item['tax_amount']) || $item['tax_amount'] === null || $item['tax_amount'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['tax_amount']) . "'";
        $exclSql = !isset($item['line_total_excl']) || $item['line_total_excl'] === null || $item['line_total_excl'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['line_total_excl']) . "'";
        $inclSql = !isset($item['line_total_incl']) || $item['line_total_incl'] === null || $item['line_total_incl'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($conn, (string)$item['line_total_incl']) . "'";
        $sql = "INSERT INTO online_sales
            (invoice_number, product_id, product, quantity, revenue, unit_price, cost_price, date_, customer, cashier, branch,
             tax_code, tax_classification, tax_rate_percent, tax_pricing_mode, discount_amount,
             taxable_amount, tax_amount, line_total_excl, line_total_incl, tax_data_status)
            VALUES
            ($invoiceNumber, '$productId', '$product', '$quantity', '$revenue', '$unitPrice', $costSql, '$dateSql', '$customerSql', '$cashierSql', '$branchSql',
             $taxCodeSql, $taxClassSql, $taxRateSql, $taxModeSql, $discSql,
             $taxableSql, $taxAmtSql, $exclSql, $inclSql, '$lineTaxStatus')";
        if (!mysqli_query($conn, $sql)) {
            error_log('sale: line insert failed');
            return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
        }
    }

    $sqlPay = "INSERT INTO sale_payments
        (invoice_number, method, amount_paid, change_amount, currency, reference, cashier_id, cashier_email, till_session_id, created_at, payment_token)
        VALUES
        ('$invoiceNumber', '$methodSql', $paidSql, $changeSql, '$currencySql', $referenceSql, $cashierIdSql, '$cashierSql', $tillSessionId, '$nowSql', '$tokenSql')";
    if (!mysqli_query($conn, $sqlPay)) {
        $replay = mysqli_errno($conn) === 1062;
        error_log('sale: payment insert failed');
        $message = $replay
            ? 'This payment was already submitted.'
            : 'Payment could not be completed. Please try again.';
        return psSaleAbort($conn, true, $message);
    }

    $marked = $headerId > 0 && mysqli_query($conn, "UPDATE online_sales_ref SET status = 'COMPLETED' WHERE id = $headerId AND status = ''");
    if (!$marked || mysqli_affected_rows($conn) !== 1) {
        error_log('sale: could not mark the sale completed');
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }

    $invoiceSql = (string)(int)$invoiceNumber;
    $savedLines = mysqli_query(
        $conn,
        "SELECT product_id, quantity FROM online_sales WHERE invoice_number = '$invoiceSql' ORDER BY id ASC"
    );
    if (!$savedLines) {
        error_log('sale: saved lines could not be read');
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }
    $savedCount = 0;
    while ($savedLine = mysqli_fetch_assoc($savedLines)) {
        $savedCount++;
        $movementQty = psSaleMovementQuantity((string)($savedLine['quantity'] ?? ''));
        if ($movementQty === null) {
            error_log('sale: stock quantity was not valid');
            return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
        }
        $recorded = recordStockMovement($conn, [
            'product_id' => trim((string)($savedLine['product_id'] ?? '')),
            'branch' => $branch,
            'movement_type' => 'SALE',
            'quantity' => $movementQty,
            'reference_type' => 'SALE',
            'reference_id' => (string)$invoiceNumber,
            'movement_at' => $now,
            'user_id' => ((int)($cashier['id'] ?? 0) > 0) ? (int)$cashier['id'] : null,
            'user_email' => $cashierEmail,
        ]);
        if (empty($recorded['ok'])) {
            error_log('sale: stock movement failed');
            return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
        }
    }
    if ($savedCount !== count($cartSale['lines']) || $savedCount < 1) {
        error_log('sale: stock movements did not match the saved lines');
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }

    // Phase 6.2 — CREDIT_SALE ledger in the same transaction (after sale + stock succeed).
    // Exactly one debit = server sale total; rollback if ledger fails (no orphan sale / ledger).
    if ($creditAccountId !== null) {
        $ledgerPost = psCustomerAccountPostCreditSale(
            $conn,
            $creditAccountId,
            (string)$saleTotal,
            $invoiceNumber,
            $cashierEmail
        );
        if (!$ledgerPost['ok']) {
            error_log('sale: credit sale ledger failed');
            return psSaleAbort($conn, true, $ledgerPost['error'] ?? 'Credit sale could not be completed.');
        }
    }

    // Phase 7.1 — CASH_SALE cash ledger only for CASH tender (sale total = cash into till).
    // CARD / ECOCASH / BANK_TRANSFER / CREDIT / OTHER do not create cash movements.
    if (strtoupper(trim((string)$payment['method'])) === 'CASH') {
        $cashPost = psCashMovementPostCashSale(
            $conn,
            $tillSessionId,
            $branch,
            (string)$saleTotal,
            $invoiceNumber,
            $cashierEmail,
            $now,
            $fxSnap
        );
        if (empty($cashPost['ok'])) {
            error_log('sale: cash sale movement failed');
            $message = !empty($cashPost['duplicate'])
                ? 'This payment was already submitted.'
                : 'Payment could not be completed. Please try again.';
            return psSaleAbort($conn, true, $message);
        }
    }

    // Phase 17 — ZIMRA fiscal invoice: numbered + signed in this transaction (no network here).
    // Branches without an enabled ZIMRA device are skipped. Any failure aborts the whole sale.
    $zimraIssued = psZimraIssueForSale($conn, (string)$invoiceNumber, $branch, $cashierEmail);
    if (empty($zimraIssued['ok'])) {
        error_log('sale: zimra fiscal receipt failed');
        return psSaleAbort($conn, true, (string)($zimraIssued['error'] ?? 'Payment could not be completed. Please try again.'));
    }
    $zimraReceiptRow = !empty($zimraIssued['receipt_row_id'])
        ? psZimraOne($conn, 'SELECT * FROM zimra_receipts WHERE id = ?', 'i', [(int)$zimraIssued['receipt_row_id']])
        : null;

    $money = static function ($v) use ($conn, $saleCurrency) {
        return psFxFormat($conn, $v, $saleCurrency);
    };
    $receipt = "EARTHQUIP\n";
    $receipt .= "--------------------------------\n";
    $receipt .= "Invoice : $invoiceNumber\n";
    $receipt .= 'Customer: ' . $customer['name'] . "\n";
    $receipt .= 'Cashier : ' . $cashierEmail . "\n";
    $receipt .= "Date    : $now\n";
    $receipt .= 'Branch  : ' . $branch . "\n";
    $receipt .= 'Currency: ' . $saleCurrency . "\n";
    $receipt .= "--------------------------------\n";
    foreach ($cartSale['lines'] as $item) {
        $receipt .= $item['product'] . "\n";
        $receipt .= $item['quantity'] . ' x ' .
            $money($item['price']) .
            ' = ' .
            $money($item['revenue']) . "\n";
        if (($item['tax_data_status'] ?? '') === PS_TAX_STATUS_COMPLETE) {
            $receipt .= '  Tax ' . ($item['tax_code'] ?? '') . ' '
                . ($item['tax_classification'] ?? '') . ' @ '
                . ($item['tax_rate_percent'] ?? '') . '% = '
                . $money($item['tax_amount'] ?? 0) . "\n";
        }
    }
    $receipt .= "--------------------------------\n";
    if (($cartSale['tax_data_status'] ?? '') === PS_TAX_STATUS_COMPLETE && $cartSale['tax_amount'] !== null) {
        $receipt .= 'Taxable: ' . $money($cartSale['taxable_amount']) . "\n";
        $receipt .= 'Tax    : ' . $money($cartSale['tax_amount']) . "\n";
    }
    $receipt .= 'TOTAL : ' . $money($saleTotal) . "\n";
    $receipt .= 'Paid  : ' . $money($payment['amount_paid']) . "\n";
    $receipt .= 'Method: ' . $payment['method_label'] . "\n";
    $receipt .= 'Change: ' . $money($payment['change_amount']) . "\n";
    // 2026-10-20: the exchange rate is kept with the sale but not printed on the customer receipt.
    if ($payment['reference'] !== '') {
        $receipt .= 'Ref   : ' . $payment['reference'] . "\n";
    }
    if ($zimraReceiptRow) {
        $receipt .= psZimraReceiptTextBlock(psZimraReceiptView($conn, $zimraReceiptRow));
    }
    $receipt .= "\nThank you!\n\n";
    $receiptSql = mysqli_real_escape_string($conn, $receipt);
    $sqlPrint = "INSERT INTO print_jobs (invoice_number, receipt, status) VALUES ($invoiceNumber, '$receiptSql', 'Pending')";
    if (!mysqli_query($conn, $sqlPrint)) {
        error_log('sale: print job failed');
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }

    if ($quote !== null && !psQuoteMarkConverted($conn, (int)$quote['id'], (string)$invoiceNumber, $cashierEmail)) {
        return psSaleAbort($conn, true, 'Quotation ' . $quote['quote_number'] . ' was used by another sale. Remove it and try again.');
    }

    if (!mysqli_commit($conn)) {
        return psSaleAbort($conn, true, 'Payment could not be completed. Please try again.');
    }
    psSaleTokenConsume();

    // Phase 17 — send to ZIMRA after commit (short timeout). Offline: stays PENDING; the worker resends.
    $zimraNote = '';
    if ($zimraReceiptRow) {
        $zimraSent = psZimraAfterCommit($conn, (int)$zimraIssued['device_row_id'], $cashierEmail);
        $zimraNote = empty($zimraSent['ok'])
            ? "\nZIMRA: receipt saved; it will be sent when the connection is back."
            : "\nZIMRA: fiscal receipt sent.";
        $zDevice = psZimraDeviceGet($conn, (int)$zimraIssued['device_row_id']);
        $zDay = $zDevice ? psZimraDayActive($conn, (int)$zDevice['id']) : null;
        $zLeft = ($zDevice && $zDay) ? psZimraDayHoursLeft($zDevice, $zDay) : null;
        if ($zLeft !== null && $zLeft <= (float)($zDevice['day_end_notification_hrs'] ?: 2)) {
            $zimraNote .= "\nWarning: the fiscal day must be closed within " . max(0, round($zLeft, 1)) . ' hours. Ask a supervisor.';
        }
    }

    return [
        'success' => true,
        'message' => "✅ Sale completed successfully!\n\nInvoice No: " . $invoiceNumber . "\nThe receipt has been queued for printing." . $zimraNote,
        'invoice_number' => $invoiceNumber,
        'currency' => $saleCurrency,
        'quote_number' => $quote['quote_number'] ?? null,
        'url' => 'ajax_pos.php',
    ];
}

if (defined('PS_SALE_LIBRARY')) {
    return;
}

require_once __DIR__ . '/includes/security.php';
require __DIR__ . '/pockets_config.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Payment could not be completed. Please try again.']);
    exit;
}
mysqli_report(MYSQLI_REPORT_OFF);
mysqli_set_charset($conn, 'utf8mb4');
ini_set('display_errors', '0');

psSecurityEnforceWebSession($conn, ['redirect' => false, 'json' => true, 'touch' => true, 'csrf' => true]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    mysqli_close($conn);
    exit;
}

if (!psCsrfValidate()) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Reload and try again.']);
    mysqli_close($conn);
    exit;
}

echo json_encode(psCompleteSale($conn, $_POST));
mysqli_close($conn);
