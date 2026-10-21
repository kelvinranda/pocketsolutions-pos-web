<?php
/**
 * 2026-10-17 — Quotations.
 *
 * A quotation is a priced offer for a customer: products, quantities and prices, valid until a date. It is not a
 * sale and is never fiscalised. Totals use the same tax rules as a sale (psTaxApplyToSaleLines), so the quote total
 * is what the sale will be.
 *
 *   quotations       one per quote, number Q-000001 per company (quotation_seq); status OPEN | CONVERTED | CANCELLED
 *                    (an OPEN quote past valid_until shows as Expired)
 *   quotation_lines  one line per product (repeats are merged)
 *
 * Prices: the branch's current price. Supervisor, Management and Admin may set another unit price on a line;
 * Till users cannot. Accepted quotes are loaded into the POS: while the quote is OPEN and not expired, the sale uses
 * the quoted unit price for a product up to the quoted quantity (more than quoted → the current price). The sale then
 * marks the quote CONVERTED with its invoice number, so a quote is used once.
 * Desktop tills create quotes offline (till number T01-Q000001) and send them with the next sync.
 */

require_once __DIR__ . '/sale_payment.php';
require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/tax.php';
require_once __DIR__ . '/tx_nesting.php';

const PS_QUOTE_OPEN = 'OPEN';
const PS_QUOTE_CONVERTED = 'CONVERTED';
const PS_QUOTE_CANCELLED = 'CANCELLED';
const PS_QUOTE_DEFAULT_DAYS = 14;
const PS_QUOTE_MAX_LINES = 200;

function psQuoteEnsureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = [
        "CREATE TABLE IF NOT EXISTS quotation_seq (
            company_name VARCHAR(250) NOT NULL PRIMARY KEY,
            next_no INT UNSIGNED NOT NULL DEFAULT 1
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS quotations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL,
            branch VARCHAR(250) NOT NULL,
            quote_number VARCHAR(40) NOT NULL,
            customer_master_id INT NULL,
            customer_name VARCHAR(250) NOT NULL DEFAULT '',
            customer_phone VARCHAR(64) NOT NULL DEFAULT '',
            customer_email VARCHAR(250) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
            valid_until DATE NOT NULL,
            notes TEXT NULL,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            taxable_amount DECIMAL(14,2) NULL,
            tax_amount DECIMAL(14,2) NULL,
            tax_data_status VARCHAR(20) NOT NULL DEFAULT '',
            source VARCHAR(20) NOT NULL DEFAULT 'WEB',
            till_ref VARCHAR(40) NULL,
            client_uuid CHAR(36) NULL,
            created_by VARCHAR(250) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_by VARCHAR(250) NOT NULL,
            updated_at DATETIME NOT NULL,
            converted_invoice VARCHAR(64) NULL,
            converted_at DATETIME NULL,
            converted_by VARCHAR(250) NULL,
            cancelled_reason VARCHAR(500) NULL,
            UNIQUE KEY uq_quote_number (company_name, quote_number),
            UNIQUE KEY uq_quote_client_uuid (client_uuid),
            KEY idx_quote_branch (company_name, branch, status),
            KEY idx_quote_customer (customer_master_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS quotation_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quotation_id INT UNSIGNED NOT NULL,
            line_no INT NOT NULL,
            product_id VARCHAR(250) NOT NULL,
            product VARCHAR(250) NOT NULL,
            quantity DECIMAL(14,3) NOT NULL,
            unit_price DECIMAL(14,2) NOT NULL,
            list_price DECIMAL(14,2) NOT NULL,
            line_total DECIMAL(14,2) NOT NULL,
            tax_code VARCHAR(20) NULL,
            tax_rate_percent VARCHAR(20) NULL,
            tax_amount DECIMAL(14,2) NULL,
            line_total_excl DECIMAL(14,2) NULL,
            KEY idx_quote_lines (quotation_id, line_no)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sql as $q) {
        if (!mysqli_query($conn, $q)) {
            error_log('quotations schema: ' . mysqli_error($conn));
            return false;
        }
    }
    $ready = true;
    return true;
}

function psQuoteSessionUser(): string
{
    if (function_exists('psActorOverride') && ($a = psActorOverride()) !== null) {
        return $a;
    }
    return session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['logger'] ?? '')) : '';
}

/** Supervisor, Management or Admin (case-insensitive) may change prices on a quote. */
function psQuoteLevelMayPrice(string $level): bool
{
    return in_array(strtolower(trim($level)), ['supervisor', 'management', 'admin'], true);
}

function psQuoteSessionMayPrice(): bool
{
    if (function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin()) {
        return true;
    }
    return session_status() === PHP_SESSION_ACTIVE && psQuoteLevelMayPrice((string)($_SESSION['level'] ?? ''));
}

function psQuoteIsExpired(array $q): bool
{
    return ($q['status'] ?? '') === PS_QUOTE_OPEN && (string)($q['valid_until'] ?? '') < date('Y-m-d');
}

function psQuoteStatusLabel(array $q): string
{
    if (psQuoteIsExpired($q)) {
        return 'Expired';
    }
    return ['OPEN' => 'Open', 'CONVERTED' => 'Sold', 'CANCELLED' => 'Cancelled'][$q['status'] ?? ''] ?? (string)($q['status'] ?? '');
}

/**
 * Validate and price quote lines for a branch (one line per product; repeats merged).
 *
 * @param array<int,array{product_id?:mixed,quantity?:mixed,unit_price?:mixed}> $rawLines
 * @return array{ok:bool,error?:string,lines?:array,total?:string,total_cents?:int,taxable_amount?:?string,tax_amount?:?string,tax_data_status?:string}
 */
function psQuotePriceLines(mysqli $conn, array $rawLines, string $branch, string $company, bool $mayPrice, ?array $existingPrices = null, ?array $fx = null): array
{
    // Multi-currency (2026-10-20): list prices are converted from the company base into the quote currency.
    $fxOn = is_array($fx) && !empty($fx['currency']) && $fx['currency'] !== ($fx['base'] ?? '');
    $merged = [];
    foreach ($rawLines as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $pid = trim((string)($raw['product_id'] ?? ''));
        $qty = trim((string)($raw['quantity'] ?? ''));
        if ($pid === '' && $qty === '') {
            continue;
        }
        if ($pid === '') {
            return ['ok' => false, 'error' => 'Choose a product on every line.'];
        }
        if (!preg_match('/^\d+(\.\d{1,3})?$/', $qty) || (float)$qty <= 0) {
            return ['ok' => false, 'error' => 'Enter a quantity greater than zero on every line.'];
        }
        $price = trim((string)($raw['unit_price'] ?? ''));
        if (isset($merged[$pid])) {
            $merged[$pid]['quantity'] = rtrim(rtrim(number_format((float)$merged[$pid]['quantity'] + (float)$qty, 3, '.', ''), '0'), '.');
        } else {
            $merged[$pid] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price];
        }
    }
    if (!$merged) {
        return ['ok' => false, 'error' => 'Add at least one product.'];
    }
    if (count($merged) > PS_QUOTE_MAX_LINES) {
        return ['ok' => false, 'error' => 'A quotation can have up to ' . PS_QUOTE_MAX_LINES . ' products.'];
    }
    $lines = [];
    foreach ($merged as $m) {
        $g = psActiveGoodsForSale($conn, $m['product_id'], $branch, $company);
        if (empty($g['ok'])) {
            return ['ok' => false, 'error' => 'Product ' . $m['product_id'] . ' is not available at ' . $branch . '.'];
        }
        $row = $g['row'];
        $listCents = psDecimalCents(trim((string)$row['price']));
        if ($listCents === null || $listCents < 0) {
            return ['ok' => false, 'error' => 'Product ' . $row['product'] . ' has no valid price.'];
        }
        if ($fxOn) {
            $listCents = psFxFromBaseCents($conn, $fx, $listCents);
        }
        $unitCents = $listCents;
        if ($m['unit_price'] !== '') {
            $asked = psDecimalCents($m['unit_price']);
            if ($asked === null || $asked < 0) {
                return ['ok' => false, 'error' => 'Enter a valid price for ' . $row['product'] . '.'];
            }
            if ($asked !== $listCents) {
                // Till users keep a price a supervisor already set on this quote, but cannot set a new one.
                $kept = $existingPrices[(string)$row['product_id']] ?? null;
                if (!$mayPrice && $kept !== $asked) {
                    return ['ok' => false, 'error' => 'Only a Supervisor, Manager or Admin can change prices on a quotation (' . $row['product'] . ').'];
                }
                $unitCents = $asked;
            }
        }
        $lineCents = psLineCents($unitCents, $m['quantity']);
        if ($lineCents === null) {
            return ['ok' => false, 'error' => 'A line total is not valid.'];
        }
        $lines[] = [
            'product_id' => (string)$row['product_id'],
            'product' => substr(trim((string)$row['product']), 0, 250),
            'quantity' => rtrim(rtrim(number_format((float)$m['quantity'], 3, '.', ''), '0'), '.'),
            'price' => psMoneyFromCents($unitCents),
            'list_price' => psMoneyFromCents($listCents),
            'revenue' => psMoneyFromCents($lineCents),
            'tax_code' => strtoupper(trim((string)($row['tax_code'] ?? ''))),
        ];
    }
    $taxed = psTaxApplyToSaleLines($conn, $lines, $branch, date('Y-m-d'));
    if (empty($taxed['ok'])) {
        return ['ok' => false, 'error' => (string)($taxed['error'] ?? 'The quotation total is not valid.')];
    }
    foreach ($taxed['lines'] as $i => $l) {
        $taxed['lines'][$i]['list_price'] = $lines[$i]['list_price'];
    }
    return [
        'ok' => true,
        'lines' => $taxed['lines'],
        'total' => (string)$taxed['total'],
        'total_cents' => (int)$taxed['total_cents'],
        'taxable_amount' => $taxed['taxable_amount'],
        'tax_amount' => $taxed['tax_amount'],
        'tax_data_status' => (string)$taxed['tax_data_status'],
    ];
}

/** Next Q-000001 for the company (inside a transaction). */
function psQuoteAllocateNumber(mysqli $conn, string $company): ?string
{
    $ins = mysqli_prepare($conn, 'INSERT IGNORE INTO quotation_seq (company_name, next_no) VALUES (?, 1)');
    mysqli_stmt_bind_param($ins, 's', $company);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    $sel = mysqli_prepare($conn, 'SELECT next_no FROM quotation_seq WHERE company_name = ? FOR UPDATE');
    mysqli_stmt_bind_param($sel, 's', $company);
    mysqli_stmt_execute($sel);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($sel));
    mysqli_stmt_close($sel);
    if (!$row) {
        return null;
    }
    $n = (int)$row['next_no'];
    $upd = mysqli_prepare($conn, 'UPDATE quotation_seq SET next_no = next_no + 1 WHERE company_name = ?');
    mysqli_stmt_bind_param($upd, 's', $company);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    return sprintf('Q-%06d', $n);
}

/**
 * Create ($id = null) or change an OPEN quotation.
 *
 * $in: customer_master_id, customer_name, customer_phone, customer_email, valid_until (Y-m-d), notes,
 *      lines [{product_id, quantity, unit_price?}], and for desktop: source, till_ref, client_uuid.
 * @return array{ok:bool,error?:string,id?:int,quote_number?:string}
 */
function psQuoteSave(mysqli $conn, ?int $id, array $in, string $branch, string $company, bool $mayPrice): array
{
    if (!psQuoteEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The quotation could not be saved.'];
    }
    $actor = psQuoteSessionUser();
    if ($actor === '') {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }
    $existing = null;
    $existingPrices = [];
    if ($id !== null) {
        $existing = psQuoteGet($conn, $id, $company);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'Quotation was not found.'];
        }
        if ($existing['status'] !== PS_QUOTE_OPEN) {
            return ['ok' => false, 'error' => 'Only open quotations can be changed.'];
        }
        $branch = (string)$existing['branch'];
        foreach ($existing['lines'] as $l) {
            $existingPrices[(string)$l['product_id']] = psDecimalCents((string)$l['unit_price']);
        }
    }
    // Desktop: the price the till showed as the branch price when the quote was made (catalogue may have moved since).
    foreach ((array)($in['allowed_prices'] ?? []) as $pid => $cents) {
        if (!isset($existingPrices[(string)$pid]) && is_int($cents)) {
            $existingPrices[(string)$pid] = $cents;
        }
    }

    // Customer: a known customer of the company, or a name typed for this quote.
    $custId = (int)($in['customer_master_id'] ?? 0);
    $custName = substr(trim((string)($in['customer_name'] ?? '')), 0, 250);
    if ($custId > 0) {
        require_once __DIR__ . '/customer_master.php';
        $c = psEnsureCustomerMasterSchema($conn) ? psCustomerById($conn, $custId) : null;
        if ($c === null) {
            return ['ok' => false, 'error' => 'Customer was not found.'];
        }
        $custName = trim((string)($c['business_name'] ?? '')) !== '' ? trim((string)$c['business_name']) : trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    } else {
        $custId = 0;
    }
    if ($custName === '') {
        return ['ok' => false, 'error' => 'Enter the customer\'s name or choose a customer.'];
    }
    $phone = substr(trim((string)($in['customer_phone'] ?? '')), 0, 64);
    $email = substr(trim((string)($in['customer_email'] ?? '')), 0, 250);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address or leave it empty.'];
    }
    $valid = trim((string)($in['valid_until'] ?? ''));
    if ($valid === '') {
        $valid = date('Y-m-d', strtotime('+' . PS_QUOTE_DEFAULT_DAYS . ' days'));
    }
    $d = DateTime::createFromFormat('Y-m-d', $valid);
    if (!$d || $d->format('Y-m-d') !== $valid) {
        return ['ok' => false, 'error' => 'Valid until must be a date.'];
    }
    if ($valid < date('Y-m-d') && ($in['source'] ?? 'WEB') === 'WEB') {
        return ['ok' => false, 'error' => 'Valid until cannot be in the past.'];
    }
    $notes = substr(trim((string)($in['notes'] ?? '')), 0, 4000);

    // Multi-currency: chosen when the quote is made; kept when it is edited. Desktop quotes are in the base.
    require_once __DIR__ . '/currency.php';
    if (!psFxEnsureTransactionColumns($conn)) {
        return ['ok' => false, 'error' => 'The quotation could not be saved.'];
    }
    $quoteCurRaw = $existing !== null ? (string)($existing['currency'] ?? '') : (string)($in['currency'] ?? '');
    $quoteCurCheck = psFxCheckTransactionCurrency($conn, $company, $quoteCurRaw);
    if (empty($quoteCurCheck['ok'])) {
        return ['ok' => false, 'error' => (string)$quoteCurCheck['error']];
    }
    $quoteSnap = psFxSnapshot($conn, $company, (string)$quoteCurCheck['code']);
    if (empty($quoteSnap['ok'])) {
        return ['ok' => false, 'error' => (string)$quoteSnap['error']];
    }
    $priced = psQuotePriceLines($conn, is_array($in['lines'] ?? null) ? $in['lines'] : [], $branch, $company, $mayPrice, $existingPrices, $quoteSnap);
    if (!$priced['ok']) {
        return $priced;
    }
    $now = date('Y-m-d H:i:s');
    $custIdSql = $custId > 0 ? $custId : null;
    $taxable = $priced['taxable_amount'];
    $tax = $priced['tax_amount'];

    if (!psTxBegin($conn)) {
        return ['ok' => false, 'error' => 'The quotation could not be saved.'];
    }
    if ($existing === null) {
        $number = psQuoteAllocateNumber($conn, $company);
        if ($number === null) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The quotation could not be saved.'];
        }
        $source = ($in['source'] ?? 'WEB') === 'DESKTOP' ? 'DESKTOP' : 'WEB';
        $tillRef = isset($in['till_ref']) && $in['till_ref'] !== '' ? substr((string)$in['till_ref'], 0, 40) : null;
        $uuid = isset($in['client_uuid']) && $in['client_uuid'] !== '' ? strtolower((string)$in['client_uuid']) : null;
        $status = PS_QUOTE_OPEN;
        $stmt = mysqli_prepare($conn, 'INSERT INTO quotations (company_name, branch, quote_number, customer_master_id, customer_name, customer_phone, customer_email,
                status, valid_until, notes, total, taxable_amount, tax_amount, tax_data_status, source, till_ref, client_uuid, created_by, created_at, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $total = $priced['total'];
        $tds = $priced['tax_data_status'];
        mysqli_stmt_bind_param($stmt, 'sssisssssssssssssssss', $company, $branch, $number, $custIdSql, $custName, $phone, $email, $status, $valid, $notes,
            $total, $taxable, $tax, $tds, $source, $tillRef, $uuid, $actor, $now, $actor, $now);
        if (!mysqli_stmt_execute($stmt)) {
            error_log('quotation insert: ' . mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The quotation could not be saved.'];
        }
        $id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
    } else {
        $number = (string)$existing['quote_number'];
        $stmt = mysqli_prepare($conn, 'UPDATE quotations SET customer_master_id = ?, customer_name = ?, customer_phone = ?, customer_email = ?, valid_until = ?,
                notes = ?, total = ?, taxable_amount = ?, tax_amount = ?, tax_data_status = ?, updated_by = ?, updated_at = ?
                WHERE id = ? AND status = \'OPEN\'');
        $total = $priced['total'];
        $tds = $priced['tax_data_status'];
        mysqli_stmt_bind_param($stmt, 'isssssssssssi', $custIdSql, $custName, $phone, $email, $valid, $notes, $total, $taxable, $tax, $tds, $actor, $now, $id);
        $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0;
        mysqli_stmt_close($stmt);
        $del = mysqli_prepare($conn, 'DELETE FROM quotation_lines WHERE quotation_id = ?');
        mysqli_stmt_bind_param($del, 'i', $id);
        $ok = $ok && mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        if (!$ok) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The quotation could not be saved.'];
        }
    }
    $ins = mysqli_prepare($conn, 'INSERT INTO quotation_lines (quotation_id, line_no, product_id, product, quantity, unit_price, list_price, line_total,
            tax_code, tax_rate_percent, tax_amount, line_total_excl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($priced['lines'] as $i => $l) {
        $n = $i + 1;
        $code = ($l['tax_code'] ?? '') !== '' ? $l['tax_code'] : null;
        $rate = isset($l['tax_rate_percent']) && $l['tax_rate_percent'] !== '' ? (string)$l['tax_rate_percent'] : null;
        $lt = isset($l['tax_amount']) && $l['tax_amount'] !== '' ? (string)$l['tax_amount'] : null;
        $ex = isset($l['line_total_excl']) && $l['line_total_excl'] !== '' ? (string)$l['line_total_excl'] : null;
        mysqli_stmt_bind_param($ins, 'iissssssssss', $id, $n, $l['product_id'], $l['product'], $l['quantity'], $l['price'], $l['list_price'], $l['revenue'],
            $code, $rate, $lt, $ex);
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The quotation could not be saved.'];
        }
    }
    mysqli_stmt_close($ins);
    if (psFxHasColumn($conn, 'quotations', 'currency')) {
        $v = psFxSnapshotSqlValues($conn, $quoteSnap);
        $qBase = psFxCentsToMoney(psFxToBaseCents($conn, $quoteSnap, (int)$priced['total_cents']));
        if (!mysqli_query($conn, 'UPDATE quotations SET currency = ' . $v['currency'] . ', base_currency = ' . $v['base_currency'] . ', fx_rate = ' . $v['fx_rate']
            . ', fx_rate_ids = ' . $v['fx_rate_ids'] . ', fx_path = ' . $v['fx_path'] . ", base_total = '" . $qBase . "' WHERE id = " . (int)$id)) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The quotation could not be saved.'];
        }
    }
    if (!psTxCommit($conn)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The quotation could not be saved.'];
    }
    return ['ok' => true, 'id' => $id, 'quote_number' => $number];
}

/** One quotation of the company, with its lines (null when not found). */
function psQuoteGet(mysqli $conn, int $id, string $company, bool $forUpdate = false): ?array
{
    if (!psQuoteEnsureSchema($conn) || $id < 1) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM quotations WHERE id = ? AND company_name = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    mysqli_stmt_bind_param($stmt, 'is', $id, $company);
    mysqli_stmt_execute($stmt);
    $q = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$q) {
        return null;
    }
    $l = mysqli_prepare($conn, 'SELECT * FROM quotation_lines WHERE quotation_id = ? ORDER BY line_no');
    mysqli_stmt_bind_param($l, 'i', $id);
    mysqli_stmt_execute($l);
    $q['lines'] = mysqli_fetch_all(mysqli_stmt_get_result($l), MYSQLI_ASSOC);
    mysqli_stmt_close($l);
    return $q;
}

/**
 * Quotations for these branches, newest first.
 * $status: '' (all) | open | expired | sold | cancelled
 */
function psQuoteList(mysqli $conn, string $company, array $branches, string $search = '', string $status = '', int $limit = 300): array
{
    if (!psQuoteEnsureSchema($conn) || !$branches) {
        return [];
    }
    $in = implode(',', array_fill(0, count($branches), '?'));
    $where = "company_name = ? AND branch IN ($in)";
    $types = 's' . str_repeat('s', count($branches));
    $params = array_merge([$company], array_values($branches));
    $today = date('Y-m-d');
    switch ($status) {
        case 'open':
            $where .= " AND status = 'OPEN' AND valid_until >= ?";
            $types .= 's';
            $params[] = $today;
            break;
        case 'expired':
            $where .= " AND status = 'OPEN' AND valid_until < ?";
            $types .= 's';
            $params[] = $today;
            break;
        case 'sold':
            $where .= " AND status = 'CONVERTED'";
            break;
        case 'cancelled':
            $where .= " AND status = 'CANCELLED'";
            break;
    }
    $search = trim($search);
    if ($search !== '') {
        $where .= ' AND (quote_number LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR till_ref LIKE ? OR converted_invoice = ?)';
        $like = '%' . str_replace(['%', '_'], '', $search) . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $search);
    }
    $types .= 'i';
    $params[] = $limit;
    $stmt = mysqli_prepare($conn, "SELECT * FROM quotations WHERE $where ORDER BY created_at DESC, id DESC LIMIT ?");
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function psQuoteCancel(mysqli $conn, int $id, string $company, string $reason): array
{
    $actor = psQuoteSessionUser();
    $q = psQuoteGet($conn, $id, $company);
    if ($q === null) {
        return ['ok' => false, 'error' => 'Quotation was not found.'];
    }
    if ($q['status'] !== PS_QUOTE_OPEN) {
        return ['ok' => false, 'error' => 'Only open quotations can be cancelled.'];
    }
    $reason = substr(trim($reason), 0, 500);
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "UPDATE quotations SET status = 'CANCELLED', cancelled_reason = ?, updated_by = ?, updated_at = ? WHERE id = ? AND status = 'OPEN'");
    mysqli_stmt_bind_param($stmt, 'sssi', $reason, $actor, $now, $id);
    $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The quotation could not be cancelled.'];
}

/**
 * For a sale from a quote (inside the sale transaction): lock the quote and return the quoted prices.
 * @return array{ok:bool,error?:string,quote?:array,overrides?:array<string,array{unit_cents:int,max_milli:int}>}
 */
function psQuoteForSale(mysqli $conn, int $id, string $company, string $branch): array
{
    $q = psQuoteGet($conn, $id, $company, true);
    if ($q === null) {
        return ['ok' => false, 'error' => 'The quotation was not found.'];
    }
    if ($q['status'] === PS_QUOTE_CONVERTED) {
        return ['ok' => false, 'error' => 'Quotation ' . $q['quote_number'] . ' was already sold (invoice ' . $q['converted_invoice'] . ').'];
    }
    if ($q['status'] !== PS_QUOTE_OPEN) {
        return ['ok' => false, 'error' => 'Quotation ' . $q['quote_number'] . ' was cancelled.'];
    }
    if (psQuoteIsExpired($q)) {
        return ['ok' => false, 'error' => 'Quotation ' . $q['quote_number'] . ' expired on ' . $q['valid_until'] . '. Remove it from the sale or ask for a new quote.'];
    }
    if (strcasecmp(trim((string)$q['branch']), trim($branch)) !== 0) {
        return ['ok' => false, 'error' => 'Quotation ' . $q['quote_number'] . ' is for ' . $q['branch'] . ', not ' . $branch . '.'];
    }
    $over = [];
    foreach ($q['lines'] as $l) {
        $over[(string)$l['product_id']] = [
            'unit_cents' => (int)psDecimalCents((string)$l['unit_price']),
            'max_milli' => (int)round((float)$l['quantity'] * 1000),
        ];
    }
    return ['ok' => true, 'quote' => $q, 'overrides' => $over];
}

/** Mark a quote sold (inside the sale transaction). */
function psQuoteMarkConverted(mysqli $conn, int $id, string $invoice, string $actor): bool
{
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "UPDATE quotations SET status = 'CONVERTED', converted_invoice = ?, converted_at = ?, converted_by = ?, updated_at = ?, updated_by = ?
                                   WHERE id = ? AND status = 'OPEN'");
    mysqli_stmt_bind_param($stmt, 'sssssi', $invoice, $now, $actor, $now, $actor, $id);
    $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    return $ok;
}

/** Company letterhead for a printed quote: display name, contacts, and taxpayer details from the branch's ZIMRA device. */
function psQuoteLetterhead(mysqli $conn, string $company, string $branch): array
{
    $h = ['name' => $company, 'lines' => []];
    $c = mysqli_prepare($conn, 'SELECT display_name, contact_phone, contact_email FROM companies WHERE company_name = ? LIMIT 1');
    if ($c) {
        mysqli_stmt_bind_param($c, 's', $company);
        mysqli_stmt_execute($c);
        if ($r = mysqli_fetch_assoc(mysqli_stmt_get_result($c))) {
            $h['name'] = trim((string)$r['display_name']) ?: $company;
            $contact = trim(implode(' · ', array_filter([trim((string)$r['contact_phone']), trim((string)$r['contact_email'])])));
            if ($contact !== '') {
                $h['lines'][] = $contact;
            }
        }
        mysqli_stmt_close($c);
    }
    $z = @mysqli_prepare($conn, 'SELECT taxpayer_name, taxpayer_tin, vat_number, fdms_branch_address FROM zimra_devices WHERE company_name = ? AND branch = ? ORDER BY id DESC LIMIT 1');
    if ($z) {
        mysqli_stmt_bind_param($z, 'ss', $company, $branch);
        mysqli_stmt_execute($z);
        if ($r = mysqli_fetch_assoc(mysqli_stmt_get_result($z))) {
            $addr = json_decode((string)$r['fdms_branch_address'], true);
            if (is_array($addr)) {
                $a = trim(implode(', ', array_filter([trim(($addr['houseNo'] ?? '') . ' ' . ($addr['street'] ?? '')), $addr['city'] ?? '', $addr['province'] ?? ''])));
                if ($a !== '') {
                    array_unshift($h['lines'], $a);
                }
            }
            $ids = array_filter(['TIN ' . trim((string)$r['taxpayer_tin']), trim((string)$r['vat_number']) !== '' ? 'VAT ' . trim((string)$r['vat_number']) : '']);
            if (trim((string)$r['taxpayer_tin']) !== '') {
                $h['lines'][] = implode(' · ', $ids);
            }
        }
        mysqli_stmt_close($z);
    }
    array_unshift($h['lines'], 'Branch: ' . $branch);
    return $h;
}

/** The quote as a self-contained HTML document (print page and PDF). */
function psQuoteDocumentHtml(array $q, array $letterhead): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $m = fn($v) => number_format((float)$v, 2);
    $rows = '';
    foreach ($q['lines'] as $l) {
        $rows .= '<tr><td>' . $e($l['product']) . '<div class="pid">' . $e($l['product_id']) . '</div></td>'
            . '<td class="n">' . $e(rtrim(rtrim((string)$l['quantity'], '0'), '.')) . '</td>'
            . '<td class="n">' . $m($l['unit_price']) . '</td>'
            . '<td class="n">' . ($l['tax_code'] ? $e($l['tax_code']) . ($l['tax_rate_percent'] !== null && $l['tax_rate_percent'] !== '' ? ' ' . $e(rtrim(rtrim((string)$l['tax_rate_percent'], '0'), '.')) . '%' : '') : '') . '</td>'
            . '<td class="n">' . $m($l['line_total']) . '</td></tr>';
    }
    $tax = '';
    if ($q['tax_amount'] !== null && $q['tax_amount'] !== '') {
        $tax = '<tr><td>Total excluding VAT</td><td class="n">' . $m((float)$q['total'] - (float)$q['tax_amount']) . '</td></tr>'
            . '<tr><td>VAT</td><td class="n">' . $m($q['tax_amount']) . '</td></tr>';
    }
    $cust = $e($q['customer_name']);
    foreach (['customer_phone', 'customer_email'] as $f) {
        if (trim((string)$q[$f]) !== '') {
            $cust .= '<br>' . $e($q[$f]);
        }
    }
    $state = psQuoteStatusLabel($q);
    $stamp = $state === 'Open' ? '' : '<div class="stamp">' . $e(strtoupper($state)) . '</div>';
    return '<div class="qdoc">' . $stamp
        . '<table class="head"><tr><td><div class="co">' . $e($letterhead['name']) . '</div>'
        . implode('', array_map(fn($x) => '<div class="sub">' . $e($x) . '</div>', $letterhead['lines'])) . '</td>'
        . '<td class="r"><div class="title">QUOTATION</div><div class="sub">No. <b>' . $e($q['quote_number']) . '</b></div>'
        . ($q['till_ref'] ? '<div class="sub">Till ref ' . $e($q['till_ref']) . '</div>' : '')
        . '<div class="sub">Date ' . $e(substr((string)$q['created_at'], 0, 10)) . '</div>'
        . '<div class="sub">Valid until <b>' . $e($q['valid_until']) . '</b></div></td></tr></table>'
        . '<div class="to"><div class="lbl">Quotation for</div>' . $cust . '</div>'
        . '<table class="lines"><thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Unit price</th><th class="n">Tax</th><th class="n">Amount</th></tr></thead><tbody>'
        . $rows . '</tbody></table>'
        . '<table class="tot">' . $tax . '<tr class="grand"><td>TOTAL (' . $e((string)($q['currency'] ?? '') !== '' ? $q['currency'] : (function_exists('psFxLegacyCurrency') ? psFxLegacyCurrency() : 'USD')) . ')</td><td class="n">' . $m($q['total']) . '</td></tr></table>'
        . (trim((string)$q['notes']) !== '' ? '<div class="notes"><div class="lbl">Notes</div>' . nl2br($e($q['notes'])) . '</div>' : '')
        . '<div class="terms">Prices are valid until ' . $e($q['valid_until']) . ' and subject to stock availability. This quotation is not a tax invoice; '
        . 'a fiscal tax invoice is issued when the goods are paid for.</div>'
        . '<div class="by">Prepared by ' . $e($q['created_by']) . '</div></div>';
}

function psQuoteDocumentCss(bool $pdf = false): string
{
    if ($pdf) {
        // mPDF: plain class selectors work reliably inside tables; one font.
        return str_replace(['.qdoc .', '"Source Sans 3",Arial,sans-serif'], ['.', 'sans-serif'], psQuoteDocumentCss(false)) . 'body{font-family:dejavusans}.stamp{position:static;display:inline-block;transform:none;margin-bottom:8px}';
    }
    return '.qdoc{position:relative;font-family:dejavusans,"Source Sans 3",Arial,sans-serif;color:#111;font-size:10pt;max-width:800px}'
        . '.qdoc table{border-collapse:collapse;width:100%}.qdoc .head td{vertical-align:top;padding:0}.qdoc .r{text-align:right}'
        . '.qdoc .co{font-size:16pt;font-weight:bold}.qdoc .title{font-size:18pt;font-weight:bold;letter-spacing:2px;color:#178a43}'
        . '.qdoc .sub{color:#444;font-size:9pt;margin-top:2px}.qdoc .to{margin:18px 0 12px;padding:10px 12px;border:1px solid #ccc;border-radius:6px}'
        . '.qdoc .lbl{font-size:8pt;text-transform:uppercase;letter-spacing:1px;color:#666;margin-bottom:3px}'
        . '.qdoc .lines th{background:#eef3ef;text-align:left;font-size:8.5pt;padding:6px;border-bottom:1px solid #999}'
        . '.qdoc .lines td{padding:6px;border-bottom:1px solid #ddd;vertical-align:top}.qdoc .pid{font-size:8pt;color:#777}'
        . '.qdoc .n{text-align:right;white-space:nowrap}.qdoc .tot{width:45%;margin:10px 0 0 auto}.qdoc .tot td{padding:4px 6px}'
        . '.qdoc .grand td{font-weight:bold;font-size:12pt;border-top:2px solid #111}.qdoc .notes{margin-top:16px}'
        . '.qdoc .terms{margin-top:18px;font-size:8.5pt;color:#555}.qdoc .by{margin-top:6px;font-size:8.5pt;color:#555}'
        . '.qdoc .stamp{position:absolute;right:10px;top:110px;border:3px solid #c2410c;color:#c2410c;font-weight:bold;font-size:16pt;padding:4px 12px;transform:rotate(-8deg);opacity:.85}';
}
