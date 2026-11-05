<?php
require_once __DIR__ . '/tx_nesting.php';
/**
 * Phase 6.3 — Customer Payments & Receivables Allocation.
 *
 * Architecture:
 *   customer_accounts
 *     → customer_account_payments (PAY-###### via customer_account_payment_seq)
 *     → customer_account_payment_allocations
 *     → customer_account_ledger (exactly one PAYMENT credit per posted payment;
 *         PAYMENT_REVERSAL debit on void — original PAYMENT row retained)
 *     → customer_account_payment_audit (append-only CREATE/POST/ALLOCATE/VOID)
 *
 * Distinct from sale_payments (POS sale tender). Account payments never write
 * sale_payments and do not touch till session balances (till integration disabled).
 *
 * Eligibility: ACTIVE customer_master + existing account.
 *   CLOSED → reject. SUSPENDED → allow payments to settle (do not reopen).
 *
 * Balance = SUM(debit)−SUM(credit). Allocations do not create ledger entries.
 * Outstanding per CREDIT_SALE = sale debit − SUM(ACTIVE allocations).
 */

require_once __DIR__ . '/customer_account.php';
require_once __DIR__ . '/currency.php';

const PS_CAP_STATUS_POSTED = 'POSTED';
const PS_CAP_STATUS_VOIDED = 'VOIDED';

const PS_CAP_ALLOC_ACTIVE = 'ACTIVE';
const PS_CAP_ALLOC_VOIDED = 'VOIDED';

const PS_CAP_AUDIT_CREATE = 'CREATE';
const PS_CAP_AUDIT_POST = 'POST';
const PS_CAP_AUDIT_ALLOCATE = 'ALLOCATE';
const PS_CAP_AUDIT_VOID = 'VOID';
const PS_CAP_AUDIT_SOURCE_UI = 'UI';

/** Ledger entry type for void reversal (original PAYMENT retained). */
const PS_CUST_ACCT_ENTRY_PAYMENT_REVERSAL = 'PAYMENT_REVERSAL';

const PS_CAP_REF_TYPE = 'CUSTOMER_PAYMENT';

/**
 * Account-payment methods (subset of sale vocab excluding CREDIT).
 * CREDIT is a sale tender, not an account receipt method.
 *
 * @return array<string,string>
 */
function psCustomerAccountPaymentMethods(): array
{
    return [
        'CASH' => 'Cash',
        'CARD' => 'Card',
        'ECOCASH' => 'EcoCash',
        'BANK_TRANSFER' => 'Bank Transfer',
        'OTHER' => 'Other',
    ];
}

function psCustomerAccountPaymentMethodLabel($code): string
{
    $code = strtoupper(trim((string)$code));
    $methods = psCustomerAccountPaymentMethods();
    return $methods[$code] ?? ($code !== '' ? $code : 'Not recorded');
}

function psNormalizeCustomerAccountPaymentMethod($raw): array
{
    $value = strtoupper(trim((string)$raw));
    $methods = psCustomerAccountPaymentMethods();
    if (isset($methods[$value])) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Choose a valid payment method.'];
}

/**
 * Till integration for account payments is explicitly disabled.
 * Cash method on an account payment is a ledger receipt method only —
 * it must not open/close tills or adjust till floats/session cash.
 */
function psCustomerAccountPaymentTillIntegrationEnabled(): bool
{
    return false;
}

function psEnsureCustomerAccountPaymentSeq(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS customer_account_payment_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_account_payment_seq schema was not created');
        return false;
    }
    $exists = mysqli_query($conn, 'SELECT id FROM customer_account_payment_seq WHERE id = 1');
    if ($exists && mysqli_num_rows($exists) > 0) {
        $ready = true;
        return true;
    }
    $seed = mysqli_query($conn, 'INSERT INTO customer_account_payment_seq (id, next_number) VALUES (1, 1)');
    if ($seed || mysqli_errno($conn) === 1062) {
        $ready = true;
        return true;
    }
    error_log('customer_account_payment_seq was not seeded');
    return false;
}

function psEnsureCustomerAccountPaymentsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureCustomerAccountPaymentSeq($conn)) {
        return false;
    }
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_account_payments LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_account_payments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        payment_number VARCHAR(64) NOT NULL,
        customer_account_id INT NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        payment_method VARCHAR(32) NOT NULL,
        reference VARCHAR(120) NULL DEFAULT NULL,
        notes VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'POSTED',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        created_by VARCHAR(250) NOT NULL DEFAULT '',
        updated_by VARCHAR(250) NOT NULL DEFAULT '',
        voided_at DATETIME NULL DEFAULT NULL,
        voided_by VARCHAR(250) NULL DEFAULT NULL,
        UNIQUE KEY uniq_cust_acct_payment_number (payment_number),
        KEY idx_cust_acct_payment_account (customer_account_id),
        KEY idx_cust_acct_payment_date (payment_date),
        KEY idx_cust_acct_payment_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_account_payments schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureCustomerAccountPaymentAllocationsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_account_payment_allocations LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_account_payment_allocations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL,
        customer_account_id INT NOT NULL,
        sale_invoice_number VARCHAR(64) NOT NULL,
        allocated_amount DECIMAL(14,2) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL,
        created_by VARCHAR(250) NOT NULL DEFAULT '',
        voided_at DATETIME NULL DEFAULT NULL,
        voided_by VARCHAR(250) NULL DEFAULT NULL,
        KEY idx_cap_alloc_payment (payment_id),
        KEY idx_cap_alloc_account (customer_account_id),
        KEY idx_cap_alloc_sale (sale_invoice_number),
        KEY idx_cap_alloc_status (status),
        KEY idx_cap_alloc_acct_sale_status (customer_account_id, sale_invoice_number, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_account_payment_allocations schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureCustomerAccountPaymentAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $check = mysqli_query($conn, 'SELECT 1 FROM customer_account_payment_audit LIMIT 0');
    if ($check) {
        $ready = true;
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS customer_account_payment_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        payment_id INT NOT NULL,
        payment_number VARCHAR(64) NOT NULL,
        customer_account_id INT NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_cap_audit_payment (payment_id),
        KEY idx_cap_audit_number (payment_number),
        KEY idx_cap_audit_account (customer_account_id),
        KEY idx_cap_audit_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('customer_account_payment_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureCustomerAccountPaymentModule(mysqli $conn): bool
{
    return psEnsureCustomerAccountModule($conn)
        && psEnsureCustomerAccountPaymentsSchema($conn)
        && psEnsureCustomerAccountPaymentAllocationsSchema($conn)
        && psEnsureCustomerAccountPaymentAuditSchema($conn)
        && psCustomerAccountPaymentEnsureFx($conn);
}

/** Multi-currency (2026-10-20): currency columns on payments / accounts (DDL, before any transaction). */
function psCustomerAccountPaymentEnsureFx(mysqli $conn): bool
{
    require_once __DIR__ . '/currency.php';
    return psFxEnsureTransactionColumns($conn);
}

/**
 * Convert a payment tendered in another currency into the account currency (exact rate, rounded to its decimals).
 * @return array{ok:bool,amount?:string,tender_currency?:string,tender_amount?:string,tender_rate?:string,snap?:array,base_amount?:string,error?:string}
 */
function psCustomerAccountPaymentFx(mysqli $conn, array $account, string $amount, $tenderRaw, string $at): array
{
    $company = psFxSessionCompany();
    $acctCur = psCustomerAccountRowCurrency($conn, $account, $company);
    $tender = psFxNormalizeCode($tenderRaw) ?: $acctCur;
    $out = ['ok' => true, 'amount' => $amount, 'tender_currency' => $tender, 'tender_amount' => $amount, 'tender_rate' => '1'];
    if ($tender !== $acctCur) {
        $chk = psFxCheckTransactionCurrency($conn, $company, $tender);
        if (empty($chk['ok'])) {
            return ['ok' => false, 'error' => (string)$chk['error']];
        }
        $r = psFxRate($conn, $company, $tender, $acctCur, $at);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string)$r['error']];
        }
        $conv = psFxConvertCents((int)psFxMoneyToCents($amount), $r['frac'], psFxDecimals($conn, $acctCur));
        if ($conv <= 0) {
            return ['ok' => false, 'error' => 'The converted payment amount is too small.'];
        }
        $out['amount'] = psFxCentsToMoney($conv);
        $out['tender_rate'] = (string)$r['rate'];
    }
    $snap = psFxSnapshot($conn, $company, $acctCur, $at);
    if (empty($snap['ok'])) {
        return ['ok' => false, 'error' => (string)$snap['error']];
    }
    $out['snap'] = $snap;
    $out['base_amount'] = psFxCentsToMoney(psFxToBaseCents($conn, $snap, (int)psFxMoneyToCents($out['amount'])));
    $out['account_currency'] = $acctCur;
    return $out;
}

function psSessionCustomerAccountPaymentUser(): string
{
    return psSessionCustomerAccountUser();
}

/**
 * Allocate PAY-###### inside an open transaction (SELECT … FOR UPDATE).
 * Never uses sale / customer / account / PO / GRV / return seq or MAX(id)+1.
 *
 * @return array{ok:bool,number?:string,error?:string}
 */
function psAllocateCustomerAccountPaymentNumber(mysqli $conn): array
{
    if (!psEnsureCustomerAccountPaymentSeq($conn)) {
        return ['ok' => false, 'error' => 'Payment number could not be allocated.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM customer_account_payment_seq WHERE id = 1 FOR UPDATE');
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        error_log('customer account payment number allocation failed');
        return ['ok' => false, 'error' => 'Payment number could not be allocated.'];
    }
    $n = (int)$row['next_number'];
    if ($n < 1 || $n > 999999) {
        return ['ok' => false, 'error' => 'Payment number sequence is exhausted.'];
    }
    $number = 'PAY-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    $next = $n + 1;
    $upd = mysqli_prepare($conn, 'UPDATE customer_account_payment_seq SET next_number = ? WHERE id = 1');
    if (!$upd) {
        return ['ok' => false, 'error' => 'Payment number could not be allocated.'];
    }
    mysqli_stmt_bind_param($upd, 'i', $next);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        error_log('customer account payment number allocation update failed');
        return ['ok' => false, 'error' => 'Payment number could not be allocated.'];
    }
    mysqli_stmt_close($upd);
    return ['ok' => true, 'number' => $number];
}

function psCustomerAccountPaymentById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsureCustomerAccountPaymentsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_account_payments WHERE id = ? AND ' . psCustomerAccountIdScopeSql($conn, 'customer_account_id') . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function psCustomerAccountPaymentByNumber(mysqli $conn, string $paymentNumber): ?array
{
    $paymentNumber = trim($paymentNumber);
    if ($paymentNumber === '' || !psEnsureCustomerAccountPaymentsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_account_payments WHERE payment_number = ? AND ' . psCustomerAccountIdScopeSql($conn, 'customer_account_id') . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $paymentNumber);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function psCustomerAccountPaymentLockById(mysqli $conn, int $id): ?array
{
    if ($id < 1 || !psEnsureCustomerAccountPaymentsSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM customer_account_payments WHERE id = ? AND ' . psCustomerAccountIdScopeSql($conn, 'customer_account_id') . ' LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Eligibility for recording an account payment.
 * ACTIVE customer + existing non-CLOSED account. SUSPENDED allowed (settle only).
 *
 * @return array{ok:bool,account?:array,customer?:array,error?:string}
 */
function psCustomerAccountPaymentEvaluateEligibility(mysqli $conn, int $accountId, array $options = []): array
{
    if (!psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Payment could not be authorised.'];
    }
    $lock = !empty($options['lock']);
    $account = $lock
        ? psCustomerAccountLockById($conn, $accountId)
        : psCustomerAccountById($conn, $accountId);
    if (!$account) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    $status = strtoupper(trim((string)$account['status']));
    if ($status === PS_CUST_ACCT_STATUS_CLOSED) {
        return ['ok' => false, 'error' => 'Payments cannot be recorded on a closed account.', 'account' => $account];
    }
    $customer = psCustomerById($conn, (int)$account['customer_master_id']);
    if (!$customer) {
        return ['ok' => false, 'error' => 'Customer was not found.', 'account' => $account];
    }
    if (!psCustomerStatusIsActive($customer['status'] ?? '')) {
        return [
            'ok' => false,
            'error' => 'Payments require an active customer. Activate the customer first.',
            'account' => $account,
            'customer' => $customer,
        ];
    }
    // SUSPENDED: allowed — settle receivables without reopening the account.
    return ['ok' => true, 'account' => $account, 'customer' => $customer];
}

function psCustomerAccountPaymentNormalizeReference($raw): string
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

function psCustomerAccountPaymentNormalizeNotes($raw): string
{
    $raw = trim((string)$raw);
    $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
    if (!is_string($raw)) {
        return '';
    }
    if (strlen($raw) > 500) {
        $raw = substr($raw, 0, 500);
    }
    return $raw;
}

function psCustomerAccountPaymentAuditInsertRow(
    mysqli $conn,
    int $paymentId,
    string $paymentNumber,
    int $accountId,
    string $action,
    string $fieldName,
    $oldValue,
    $newValue,
    string $changedBy,
    string $source,
    string $changedAt
): bool {
    if (!psEnsureCustomerAccountPaymentAuditSchema($conn)) {
        return false;
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_account_payment_audit
         (payment_id, payment_number, customer_account_id, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('customer account payment audit insert prepare failed');
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'isisssssss',
        $paymentId,
        $paymentNumber,
        $accountId,
        $action,
        $fieldName,
        $oldStore,
        $newStore,
        $changedBy,
        $changedAt,
        $source
    );
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        error_log('customer account payment audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * @param list<array{field_name:string,old_value:mixed,new_value:mixed}> $changes
 */
function psCustomerAccountPaymentAuditRecordChanges(
    mysqli $conn,
    array $meta,
    array $changes
): bool {
    $paymentId = (int)($meta['payment_id'] ?? 0);
    $paymentNumber = (string)($meta['payment_number'] ?? '');
    $accountId = (int)($meta['customer_account_id'] ?? 0);
    $action = (string)($meta['action'] ?? '');
    $changedBy = (string)($meta['changed_by'] ?? '');
    $source = (string)($meta['source'] ?? PS_CAP_AUDIT_SOURCE_UI);
    $changedAt = (string)($meta['changed_at'] ?? date('Y-m-d H:i:s'));
    if ($paymentId < 1 || $accountId < 1 || $paymentNumber === '' || $action === '' || $changedBy === '') {
        return false;
    }
    foreach ($changes as $change) {
        if (!psCustomerAccountPaymentAuditInsertRow(
            $conn,
            $paymentId,
            $paymentNumber,
            $accountId,
            $action,
            (string)($change['field_name'] ?? ''),
            $change['old_value'] ?? null,
            $change['new_value'] ?? null,
            $changedBy,
            $source,
            $changedAt
        )) {
            return false;
        }
    }
    return true;
}

/**
 * Sum of ACTIVE allocations for a payment.
 *
 * @return array{ok:bool,allocated?:string,error?:string}
 */
function psCustomerAccountPaymentAllocatedTotal(mysqli $conn, int $paymentId): array
{
    if ($paymentId < 1 || !psEnsureCustomerAccountPaymentAllocationsSchema($conn)) {
        return ['ok' => false, 'error' => 'Allocation total could not be calculated.'];
    }
    $status = PS_CAP_ALLOC_ACTIVE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(allocated_amount), 0) AS total_alloc
         FROM customer_account_payment_allocations
         WHERE payment_id = ? AND status = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Allocation total could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'is', $paymentId, $status);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return [
        'ok' => true,
        'allocated' => number_format((float)($row['total_alloc'] ?? 0), 2, '.', ''),
    ];
}

/**
 * Payment remaining unallocated (POSTED payments only meaningful).
 *
 * @return array{ok:bool,amount?:string,allocated?:string,unallocated?:string,error?:string}
 */
function psCustomerAccountPaymentUnallocated(mysqli $conn, int $paymentId): array
{
    $payment = psCustomerAccountPaymentById($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'Payment was not found.'];
    }
    $alloc = psCustomerAccountPaymentAllocatedTotal($conn, $paymentId);
    if (!$alloc['ok']) {
        return $alloc;
    }
    $amount = number_format((float)$payment['amount'], 2, '.', '');
    $allocated = (string)$alloc['allocated'];
    $unallocated = number_format((float)$amount - (float)$allocated, 2, '.', '');
    return [
        'ok' => true,
        'amount' => $amount,
        'allocated' => $allocated,
        'unallocated' => $unallocated,
    ];
}

/**
 * Outstanding on a Phase 6.2 CREDIT_SALE for this account.
 * sale_total from CREDIT_SALE debit; allocations ACTIVE only.
 * Does not invent receivables from cash/walk-in/legacy sales.
 *
 * @return array{ok:bool,sale_total?:string,allocated?:string,outstanding?:string,exists?:bool,error?:string}
 */
function psCustomerAccountSaleOutstanding(mysqli $conn, int $accountId, string $saleInvoiceNumber): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Sale outstanding could not be calculated.'];
    }
    $invoice = trim($saleInvoiceNumber);
    if ($invoice === '' || !preg_match('/^\d+$/', $invoice)) {
        return ['ok' => false, 'error' => 'Sale reference is not valid.'];
    }

    $refType = 'SALE';
    $entryType = PS_CUST_ACCT_ENTRY_CREDIT_SALE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT debit FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_type = ? AND reference_type = ? AND reference_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Sale outstanding could not be calculated.'];
    }
    mysqli_stmt_bind_param($stmt, 'isss', $accountId, $entryType, $refType, $invoice);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return [
            'ok' => true,
            'exists' => false,
            'sale_total' => '0.00',
            'allocated' => '0.00',
            'outstanding' => '0.00',
        ];
    }
    $saleTotal = number_format((float)$row['debit'], 2, '.', '');

    $allocStatus = PS_CAP_ALLOC_ACTIVE;
    $aStmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(allocated_amount), 0) AS total_alloc
         FROM customer_account_payment_allocations
         WHERE customer_account_id = ? AND sale_invoice_number = ? AND status = ?'
    );
    if (!$aStmt) {
        return ['ok' => false, 'error' => 'Sale outstanding could not be calculated.'];
    }
    mysqli_stmt_bind_param($aStmt, 'iss', $accountId, $invoice, $allocStatus);
    mysqli_stmt_execute($aStmt);
    $aRes = mysqli_stmt_get_result($aStmt);
    $aRow = $aRes ? mysqli_fetch_assoc($aRes) : null;
    mysqli_stmt_close($aStmt);
    $allocated = number_format((float)($aRow['total_alloc'] ?? 0), 2, '.', '');
    $outstanding = number_format((float)$saleTotal - (float)$allocated, 2, '.', '');
    if ((float)$outstanding < 0) {
        $outstanding = '0.00';
    }
    return [
        'ok' => true,
        'exists' => true,
        'sale_total' => $saleTotal,
        'allocated' => $allocated,
        'outstanding' => $outstanding,
    ];
}

/**
 * CREDIT_SALE invoices for an account with outstanding > 0, oldest first.
 *
 * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string}
 */
function psCustomerAccountAllocatableCreditSales(mysqli $conn, int $accountId): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Allocatable sales could not be loaded.', 'rows' => []];
    }
    $entryType = PS_CUST_ACCT_ENTRY_CREDIT_SALE;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, entry_date, debit, reference_id, description, created_at
         FROM customer_account_ledger
         WHERE customer_account_id = ? AND entry_type = ? AND reference_type = \'SALE\'
           AND reference_id IS NOT NULL AND reference_id <> \'\'
         ORDER BY entry_date ASC, id ASC'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Allocatable sales could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 'is', $accountId, $entryType);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $invoice = trim((string)$row['reference_id']);
        $out = psCustomerAccountSaleOutstanding($conn, $accountId, $invoice);
        if (!$out['ok'] || empty($out['exists'])) {
            continue;
        }
        if ((float)$out['outstanding'] <= 0) {
            continue;
        }
        $rows[] = [
            'ledger_id' => (int)$row['id'],
            'entry_date' => (string)$row['entry_date'],
            'sale_invoice_number' => $invoice,
            'sale_total' => (string)$out['sale_total'],
            'allocated' => (string)$out['allocated'],
            'outstanding' => (string)$out['outstanding'],
            'description' => (string)$row['description'],
        ];
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Build default oldest-first allocations for a payment amount.
 *
 * @return list<array{sale_invoice_number:string,allocated_amount:string}>
 */
function psCustomerAccountPaymentDefaultAllocations(mysqli $conn, int $accountId, string $paymentAmount): array
{
    $list = psCustomerAccountAllocatableCreditSales($conn, $accountId);
    if (!$list['ok']) {
        return [];
    }
    $remaining = (float)$paymentAmount;
    $out = [];
    foreach ($list['rows'] as $sale) {
        if ($remaining <= 0) {
            break;
        }
        $outstanding = (float)$sale['outstanding'];
        if ($outstanding <= 0) {
            continue;
        }
        $take = min($remaining, $outstanding);
        $takeStr = number_format($take, 2, '.', '');
        if ((float)$takeStr <= 0) {
            continue;
        }
        $out[] = [
            'sale_invoice_number' => (string)$sale['sale_invoice_number'],
            'allocated_amount' => $takeStr,
        ];
        $remaining = round($remaining - (float)$takeStr, 2);
    }
    return $out;
}

/**
 * Post a customer account payment + optional allocations in one transaction.
 * Posts exactly one PAYMENT ledger credit. Allocations do not create ledger rows.
 * Posted balance / outstanding / allocation totals from the client are ignored.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,payment_number?:string,error?:string}
 */
function psCustomerAccountPaymentCreate(mysqli $conn, array $data): array
{
    if (!psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountPaymentUser();
    // 2026-10-17: desktop tills post payments taken at the till (audit source DESKTOP).
    $capSource = (isset($data['_source']) && preg_match('/^[A-Z_]{2,32}$/', (string)$data['_source'])) ? (string)$data['_source'] : PS_CAP_AUDIT_SOURCE_UI;
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to record a payment.'];
    }

    // Ignore client-trusted financial / identity fields.
    unset(
        $data['payment_number'],
        $data['balance'],
        $data['posted_balance'],
        $data['outstanding'],
        $data['allocated_total'],
        $data['unallocated'],
        $data['status']
    );

    $accountId = (int)($data['customer_account_id'] ?? 0);
    if ($accountId < 1) {
        return ['ok' => false, 'error' => 'A valid account is required.'];
    }

    $amountCheck = psValidateCustomerAccountMoney($data['amount'] ?? '', 'Payment amount');
    if (!$amountCheck['ok']) {
        return ['ok' => false, 'error' => $amountCheck['error']];
    }
    if ((float)$amountCheck['value'] <= 0) {
        return ['ok' => false, 'error' => 'Payment amount must be greater than zero.'];
    }
    $amount = $amountCheck['value'];

    // Multi-currency: a payment may be tendered in another enabled currency; it is converted into the account
    // currency at today's rate, and the ledger, allocations and balance stay in the account currency.
    $acctForFx = psCustomerAccountById($conn, $accountId);
    if (!$acctForFx) {
        return ['ok' => false, 'error' => 'A valid account is required.'];
    }
    $payFx = psCustomerAccountPaymentFx($conn, $acctForFx, $amount, $data['tender_currency'] ?? '', date('Y-m-d H:i:s'));
    if (empty($payFx['ok'])) {
        return ['ok' => false, 'error' => (string)$payFx['error']];
    }
    $amount = (string)$payFx['amount'];

    $methodCheck = psNormalizeCustomerAccountPaymentMethod($data['payment_method'] ?? '');
    if (!$methodCheck['ok']) {
        return ['ok' => false, 'error' => $methodCheck['error']];
    }
    $method = $methodCheck['value'];

    $paymentDate = trim((string)($data['payment_date'] ?? ''));
    if ($paymentDate === '') {
        $paymentDate = date('Y-m-d');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $paymentDate);
    if (!$dt || $dt->format('Y-m-d') !== $paymentDate) {
        return ['ok' => false, 'error' => 'Payment date must be a valid date (YYYY-MM-DD).'];
    }

    $reference = psCustomerAccountPaymentNormalizeReference($data['reference'] ?? '');
    $notes = psCustomerAccountPaymentNormalizeNotes($data['notes'] ?? '');

    $rawAllocations = $data['allocations'] ?? null;
    $autoAllocate = !empty($data['auto_allocate']);
    if ($rawAllocations === null && $autoAllocate) {
        $rawAllocations = psCustomerAccountPaymentDefaultAllocations($conn, $accountId, $amount);
    }
    if (!is_array($rawAllocations)) {
        $rawAllocations = [];
    }

    // Normalise allocation lines (server will revalidate outstanding inside txn).
    $allocLines = [];
    foreach ($rawAllocations as $line) {
        if (!is_array($line)) {
            continue;
        }
        $inv = trim((string)($line['sale_invoice_number'] ?? $line['invoice_number'] ?? ''));
        if ($inv === '') {
            continue;
        }
        $amtCheck = psValidateCustomerAccountMoney($line['allocated_amount'] ?? '', 'Allocated amount');
        if (!$amtCheck['ok']) {
            return ['ok' => false, 'error' => $amtCheck['error']];
        }
        if ((float)$amtCheck['value'] <= 0) {
            return ['ok' => false, 'error' => 'Allocated amount must be greater than zero.'];
        }
        if (!isset($allocLines[$inv])) {
            $allocLines[$inv] = '0.00';
        }
        $allocLines[$inv] = number_format((float)$allocLines[$inv] + (float)$amtCheck['value'], 2, '.', '');
    }

    $allocSum = 0.0;
    foreach ($allocLines as $a) {
        $allocSum += (float)$a;
    }
    $allocSum = round($allocSum, 2);
    if ($allocSum > (float)$amount + 0.00001) {
        return ['ok' => false, 'error' => 'Total allocation cannot exceed the payment amount.'];
    }

    if (!psTxBegin($conn)) {
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }

    $elig = psCustomerAccountPaymentEvaluateEligibility($conn, $accountId, ['lock' => true]);
    if (!$elig['ok']) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => (string)($elig['error'] ?? 'Payment could not be authorised.')];
    }
    $account = $elig['account'];

    // Re-validate each allocation against live outstanding (concurrency).
    $validatedAlloc = [];
    foreach ($allocLines as $invoice => $allocAmt) {
        $out = psCustomerAccountSaleOutstanding($conn, $accountId, (string)$invoice);
        if (!$out['ok'] || empty($out['exists'])) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'Payments can only be applied to this account\'s credit sales.'];
        }
        if ((float)$allocAmt > (float)$out['outstanding'] + 0.00001) {
            psTxRollback($conn);
            return [
                'ok' => false,
                'error' => 'Allocation exceeds outstanding for invoice ' . $invoice . '.',
            ];
        }
        // Cross-customer guard: CREDIT_SALE must belong to this account (already scoped by accountId query).
        $validatedAlloc[(string)$invoice] = $allocAmt;
    }

    $alloc = psAllocateCustomerAccountPaymentNumber($conn);
    if (!$alloc['ok']) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => (string)($alloc['error'] ?? 'The payment could not be saved.')];
    }
    $paymentNumber = (string)$alloc['number'];
    $now = date('Y-m-d H:i:s');
    $status = PS_CAP_STATUS_POSTED;
    $refStore = $reference === '' ? null : $reference;

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_account_payments
         (payment_number, customer_account_id, payment_date, amount, payment_method, reference, notes, status,
          created_at, updated_at, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        psTxRollback($conn);
        error_log('customer account payment insert prepare failed');
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sissssssssss',
        $paymentNumber,
        $accountId,
        $paymentDate,
        $amount,
        $method,
        $refStore,
        $notes,
        $status,
        $now,
        $now,
        $changedBy,
        $changedBy
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        psTxRollback($conn);
        error_log('customer account payment insert failed');
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }
    $paymentId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if (psFxHasColumn($conn, 'customer_account_payments', 'currency')) {
        $v = psFxSnapshotSqlValues($conn, $payFx['snap']);
        $fxSql = 'UPDATE customer_account_payments SET currency = ' . $v['currency'] . ', base_currency = ' . $v['base_currency']
            . ', fx_rate = ' . $v['fx_rate'] . ', fx_rate_ids = ' . $v['fx_rate_ids'] . ', fx_path = ' . $v['fx_path']
            . ", base_amount = '" . psFxCentsToMoney((int)psFxMoneyToCents($payFx['base_amount'])) . "'"
            . ", tender_currency = '" . mysqli_real_escape_string($conn, $payFx['tender_currency']) . "'"
            . ", tender_amount = '" . mysqli_real_escape_string($conn, $payFx['tender_amount']) . "'"
            . ", tender_rate = '" . mysqli_real_escape_string($conn, $payFx['tender_rate']) . "'"
            . ' WHERE id = ' . $paymentId;
        if (!mysqli_query($conn, $fxSql)) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The payment could not be saved.'];
        }
    }

    // Exactly one PAYMENT ledger credit; never invent CREDIT_SALE here.
    $ledger = psCustomerAccountLedgerInsert($conn, [
        'customer_account_id' => $accountId,
        'entry_type' => PS_CUST_ACCT_ENTRY_PAYMENT,
        'entry_date' => $paymentDate,
        'debit' => '0.00',
        'credit' => $amount,
        'reference_type' => PS_CAP_REF_TYPE,
        'reference_id' => (string)$paymentId,
        'description' => 'Customer payment ' . $paymentNumber,
        'created_by' => $changedBy,
    ]);
    if (!$ledger['ok']) {
        psTxRollback($conn);
        if (!empty($ledger['duplicate'])) {
            return ['ok' => false, 'error' => 'A payment ledger entry already exists for this payment.'];
        }
        return ['ok' => false, 'error' => (string)($ledger['error'] ?? 'The payment could not be saved.')];
    }

    $allocStatus = PS_CAP_ALLOC_ACTIVE;
    foreach ($validatedAlloc as $invoice => $allocAmt) {
        // Re-check outstanding after prior inserts in this txn (multi-sale).
        $out = psCustomerAccountSaleOutstanding($conn, $accountId, (string)$invoice);
        if (!$out['ok'] || empty($out['exists']) || (float)$allocAmt > (float)$out['outstanding'] + 0.00001) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'Allocation exceeds outstanding under concurrent update.'];
        }
        $aStmt = mysqli_prepare(
            $conn,
            'INSERT INTO customer_account_payment_allocations
             (payment_id, customer_account_id, sale_invoice_number, allocated_amount, status, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$aStmt) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The payment could not be saved.'];
        }
        $invStr = (string)$invoice;
        mysqli_stmt_bind_param(
            $aStmt,
            'iisssss',
            $paymentId,
            $accountId,
            $invStr,
            $allocAmt,
            $allocStatus,
            $now,
            $changedBy
        );
        if (!mysqli_stmt_execute($aStmt)) {
            mysqli_stmt_close($aStmt);
            psTxRollback($conn);
            error_log('customer account payment allocation insert failed');
            return ['ok' => false, 'error' => 'The payment could not be saved.'];
        }
        mysqli_stmt_close($aStmt);
    }

    $auditChanges = [
        ['field_name' => 'payment_number', 'old_value' => null, 'new_value' => $paymentNumber],
        ['field_name' => 'customer_account_id', 'old_value' => null, 'new_value' => (string)$accountId],
        ['field_name' => 'amount', 'old_value' => null, 'new_value' => $amount],
        ['field_name' => 'currency', 'old_value' => null, 'new_value' => (string)$payFx['account_currency']],
        ['field_name' => 'tender', 'old_value' => null, 'new_value' => $payFx['tender_amount'] . ' ' . $payFx['tender_currency']
            . ($payFx['tender_currency'] !== $payFx['account_currency'] ? ' @ ' . $payFx['tender_rate'] : '')],
        ['field_name' => 'payment_method', 'old_value' => null, 'new_value' => $method],
        ['field_name' => 'payment_date', 'old_value' => null, 'new_value' => $paymentDate],
        ['field_name' => 'status', 'old_value' => null, 'new_value' => $status],
        ['field_name' => 'ledger_entry_id', 'old_value' => null, 'new_value' => (string)($ledger['id'] ?? '')],
        [
            'field_name' => 'allocated_total',
            'old_value' => null,
            'new_value' => number_format($allocSum, 2, '.', ''),
        ],
    ];
    if ($reference !== '') {
        $auditChanges[] = ['field_name' => 'reference', 'old_value' => null, 'new_value' => $reference];
    }
    if (!psCustomerAccountPaymentAuditRecordChanges($conn, [
        'payment_id' => $paymentId,
        'payment_number' => $paymentNumber,
        'customer_account_id' => $accountId,
        'action' => PS_CAP_AUDIT_CREATE,
        'changed_by' => $changedBy,
        'source' => $capSource,
        'changed_at' => $now,
    ], $auditChanges)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }
    // POST mirrors create for posted-on-create lifecycle (single status POSTED).
    if (!psCustomerAccountPaymentAuditRecordChanges($conn, [
        'payment_id' => $paymentId,
        'payment_number' => $paymentNumber,
        'customer_account_id' => $accountId,
        'action' => PS_CAP_AUDIT_POST,
        'changed_by' => $changedBy,
        'source' => $capSource,
        'changed_at' => $now,
    ], [
        ['field_name' => 'status', 'old_value' => null, 'new_value' => PS_CAP_STATUS_POSTED],
    ])) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }
    if ($validatedAlloc !== []) {
        $allocAudit = [];
        foreach ($validatedAlloc as $invoice => $allocAmt) {
            $allocAudit[] = [
                'field_name' => 'allocation:' . $invoice,
                'old_value' => null,
                'new_value' => $allocAmt,
            ];
        }
        if (!psCustomerAccountPaymentAuditRecordChanges($conn, [
            'payment_id' => $paymentId,
            'payment_number' => $paymentNumber,
            'customer_account_id' => $accountId,
            'action' => PS_CAP_AUDIT_ALLOCATE,
            'changed_by' => $changedBy,
            'source' => $capSource,
            'changed_at' => $now,
        ], $allocAudit)) {
            psTxRollback($conn);
            return ['ok' => false, 'error' => 'The payment could not be saved.'];
        }
    }

    unset($account); // till unused — explicit boundary
    if (psCustomerAccountPaymentTillIntegrationEnabled()) {
        // Reserved for a future safe till cash-receipt path; currently never enabled.
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'Till integration for account payments is not available.'];
    }

    if (!psTxCommit($conn)) {
        psTxRollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be saved.'];
    }
    return ['ok' => true, 'id' => $paymentId, 'payment_number' => $paymentNumber];
}

/**
 * Add allocations to an existing POSTED payment (remaining unallocated only).
 *
 * @param list<array{sale_invoice_number:string,allocated_amount:string|float}> $allocations
 * @return array{ok:bool,error?:string}
 */
function psCustomerAccountPaymentAllocate(mysqli $conn, int $paymentId, array $allocations): array
{
    if (!psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Allocations could not be saved.'];
    }
    $changedBy = psSessionCustomerAccountPaymentUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to allocate a payment.'];
    }
    if ($allocations === []) {
        return ['ok' => false, 'error' => 'Provide at least one allocation.'];
    }

    $allocLines = [];
    foreach ($allocations as $line) {
        if (!is_array($line)) {
            continue;
        }
        $inv = trim((string)($line['sale_invoice_number'] ?? $line['invoice_number'] ?? ''));
        $amtCheck = psValidateCustomerAccountMoney($line['allocated_amount'] ?? '', 'Allocated amount');
        if ($inv === '' || !$amtCheck['ok'] || (float)$amtCheck['value'] <= 0) {
            return ['ok' => false, 'error' => 'Each allocation needs a valid sale and amount greater than zero.'];
        }
        if (!isset($allocLines[$inv])) {
            $allocLines[$inv] = '0.00';
        }
        $allocLines[$inv] = number_format((float)$allocLines[$inv] + (float)$amtCheck['value'], 2, '.', '');
    }
    if ($allocLines === []) {
        return ['ok' => false, 'error' => 'Provide at least one allocation.'];
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Allocations could not be saved.'];
    }

    $payment = psCustomerAccountPaymentLockById($conn, $paymentId);
    if (!$payment) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Payment was not found.'];
    }
    if (strtoupper(trim((string)$payment['status'])) !== PS_CAP_STATUS_POSTED) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Only posted payments can receive allocations.'];
    }
    $accountId = (int)$payment['customer_account_id'];
    $account = psCustomerAccountLockById($conn, $accountId);
    if (!$account) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $unalloc = psCustomerAccountPaymentUnallocated($conn, $paymentId);
    if (!$unalloc['ok']) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $unalloc['error'] ?? 'Allocations could not be saved.'];
    }
    $remaining = (float)$unalloc['unallocated'];
    $newSum = 0.0;
    foreach ($allocLines as $a) {
        $newSum += (float)$a;
    }
    $newSum = round($newSum, 2);
    if ($newSum > $remaining + 0.00001) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Allocation exceeds the payment unallocated amount.'];
    }

    $now = date('Y-m-d H:i:s');
    $allocStatus = PS_CAP_ALLOC_ACTIVE;
    $auditLines = [];
    foreach ($allocLines as $invoice => $allocAmt) {
        $out = psCustomerAccountSaleOutstanding($conn, $accountId, (string)$invoice);
        if (!$out['ok'] || empty($out['exists'])) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Payments can only be applied to this account\'s credit sales.'];
        }
        if ((float)$allocAmt > (float)$out['outstanding'] + 0.00001) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Allocation exceeds outstanding for invoice ' . $invoice . '.'];
        }
        $aStmt = mysqli_prepare(
            $conn,
            'INSERT INTO customer_account_payment_allocations
             (payment_id, customer_account_id, sale_invoice_number, allocated_amount, status, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$aStmt) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Allocations could not be saved.'];
        }
        $invStr = (string)$invoice;
        mysqli_stmt_bind_param(
            $aStmt,
            'iisssss',
            $paymentId,
            $accountId,
            $invStr,
            $allocAmt,
            $allocStatus,
            $now,
            $changedBy
        );
        if (!mysqli_stmt_execute($aStmt)) {
            mysqli_stmt_close($aStmt);
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Allocations could not be saved.'];
        }
        mysqli_stmt_close($aStmt);
        $auditLines[] = [
            'field_name' => 'allocation:' . $invoice,
            'old_value' => null,
            'new_value' => $allocAmt,
        ];
    }

    $paymentNumber = (string)$payment['payment_number'];
    $upd = mysqli_prepare(
        $conn,
        'UPDATE customer_account_payments SET updated_at = ?, updated_by = ? WHERE id = ?'
    );
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ssi', $now, $changedBy, $paymentId);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }

    if (!psCustomerAccountPaymentAuditRecordChanges($conn, [
        'payment_id' => $paymentId,
        'payment_number' => $paymentNumber,
        'customer_account_id' => $accountId,
        'action' => PS_CAP_AUDIT_ALLOCATE,
        'changed_by' => $changedBy,
        'source' => PS_CAP_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], $auditLines)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Allocations could not be saved.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Allocations could not be saved.'];
    }
    return ['ok' => true];
}

/**
 * Void a POSTED payment.
 * Smallest safe reversal: mark VOIDED; post PAYMENT_REVERSAL debit (same amount);
 * void ACTIVE allocations; retain original PAYMENT ledger row and payment amount.
 * Does not delete payment / ledger / allocations; does not zero payment amount.
 *
 * @return array{ok:bool,error?:string}
 */
function psCustomerAccountPaymentVoid(mysqli $conn, int $paymentId, string $reason = ''): array
{
    if (!psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }
    $changedBy = psSessionCustomerAccountPaymentUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to void a payment.'];
    }
    $reason = psCustomerAccountPaymentNormalizeNotes($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'A void reason is required.'];
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }

    $payment = psCustomerAccountPaymentLockById($conn, $paymentId);
    if (!$payment) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Payment was not found.'];
    }
    $status = strtoupper(trim((string)$payment['status']));
    if ($status === PS_CAP_STATUS_VOIDED) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'This payment is already voided.'];
    }
    if ($status !== PS_CAP_STATUS_POSTED) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Only posted payments can be voided.'];
    }

    $accountId = (int)$payment['customer_account_id'];
    $account = psCustomerAccountLockById($conn, $accountId);
    if (!$account) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Account was not found.'];
    }

    $amount = number_format((float)$payment['amount'], 2, '.', '');
    $paymentNumber = (string)$payment['payment_number'];
    $now = date('Y-m-d H:i:s');
    $voidStatus = PS_CAP_STATUS_VOIDED;

    $upd = mysqli_prepare(
        $conn,
        'UPDATE customer_account_payments
         SET status = ?, updated_at = ?, updated_by = ?, voided_at = ?, voided_by = ?
         WHERE id = ? AND status = ?'
    );
    if (!$upd) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }
    $posted = PS_CAP_STATUS_POSTED;
    mysqli_stmt_bind_param(
        $upd,
        'sssssis',
        $voidStatus,
        $now,
        $changedBy,
        $now,
        $changedBy,
        $paymentId,
        $posted
    );
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) !== 1) {
        mysqli_stmt_close($upd);
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }
    mysqli_stmt_close($upd);

    // Controlled reversal: PAYMENT_REVERSAL debit; original PAYMENT credit kept.
    $reversal = psCustomerAccountLedgerInsertPaymentReversal($conn, [
        'customer_account_id' => $accountId,
        'entry_date' => date('Y-m-d'),
        'debit' => $amount,
        'reference_id' => (string)$paymentId,
        'description' => 'Void of payment ' . $paymentNumber . ': ' . $reason,
        'created_by' => $changedBy,
    ]);
    if (!$reversal['ok']) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => (string)($reversal['error'] ?? 'The payment could not be voided.')];
    }

    $allocVoid = PS_CAP_ALLOC_VOIDED;
    $allocActive = PS_CAP_ALLOC_ACTIVE;
    $aUpd = mysqli_prepare(
        $conn,
        'UPDATE customer_account_payment_allocations
         SET status = ?, voided_at = ?, voided_by = ?
         WHERE payment_id = ? AND status = ?'
    );
    if (!$aUpd) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }
    mysqli_stmt_bind_param($aUpd, 'sssis', $allocVoid, $now, $changedBy, $paymentId, $allocActive);
    if (!mysqli_stmt_execute($aUpd)) {
        mysqli_stmt_close($aUpd);
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }
    mysqli_stmt_close($aUpd);

    if (!psCustomerAccountPaymentAuditRecordChanges($conn, [
        'payment_id' => $paymentId,
        'payment_number' => $paymentNumber,
        'customer_account_id' => $accountId,
        'action' => PS_CAP_AUDIT_VOID,
        'changed_by' => $changedBy,
        'source' => PS_CAP_AUDIT_SOURCE_UI,
        'changed_at' => $now,
    ], [
        ['field_name' => 'status', 'old_value' => PS_CAP_STATUS_POSTED, 'new_value' => PS_CAP_STATUS_VOIDED],
        ['field_name' => 'void_reason', 'old_value' => null, 'new_value' => $reason],
        ['field_name' => 'reversal_ledger_entry_id', 'old_value' => null, 'new_value' => (string)($reversal['id'] ?? '')],
    ])) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }

    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The payment could not be voided.'];
    }
    return ['ok' => true];
}

/**
 * Insert PAYMENT_REVERSAL ledger debit (void path). Extends allowed entry types
 * without routing through open admin adjustment APIs.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,error?:string,duplicate?:bool}
 */
function psCustomerAccountLedgerInsertPaymentReversal(mysqli $conn, array $data): array
{
    if (!psEnsureCustomerAccountLedgerSchema($conn)) {
        return ['ok' => false, 'error' => 'Payment reversal could not be saved.'];
    }
    $accountId = (int)($data['customer_account_id'] ?? 0);
    if ($accountId < 1 || !psCustomerAccountById($conn, $accountId)) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    $debitCheck = psValidateCustomerAccountMoney($data['debit'] ?? '', 'Reversal amount');
    if (!$debitCheck['ok'] || (float)$debitCheck['value'] <= 0) {
        return ['ok' => false, 'error' => 'Reversal amount must be greater than zero.'];
    }
    $debit = $debitCheck['value'];
    $entryDate = trim((string)($data['entry_date'] ?? date('Y-m-d')));
    $dt = DateTime::createFromFormat('Y-m-d', $entryDate);
    if (!$dt || $dt->format('Y-m-d') !== $entryDate) {
        return ['ok' => false, 'error' => 'Entry date must be a valid date (YYYY-MM-DD).'];
    }
    $refId = trim((string)($data['reference_id'] ?? ''));
    if ($refId === '') {
        return ['ok' => false, 'error' => 'Payment reference is required for reversal.'];
    }
    $description = trim((string)($data['description'] ?? ''));
    if (strlen($description) > 500) {
        $description = substr($description, 0, 500);
    }
    $createdBy = trim((string)($data['created_by'] ?? ''));
    if ($createdBy === '') {
        $createdBy = psSessionCustomerAccountPaymentUser();
    }
    if ($createdBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to post a reversal.'];
    }

    $entryType = PS_CUST_ACCT_ENTRY_PAYMENT_REVERSAL;
    $refType = PS_CAP_REF_TYPE;
    $credit = '0.00';
    $now = date('Y-m-d H:i:s');

    $dupStmt = mysqli_prepare(
        $conn,
        'SELECT id FROM customer_account_ledger
         WHERE reference_type = ? AND reference_id = ? AND entry_type = ?
         LIMIT 1'
    );
    if (!$dupStmt) {
        return ['ok' => false, 'error' => 'Payment reversal could not be saved.'];
    }
    mysqli_stmt_bind_param($dupStmt, 'sss', $refType, $refId, $entryType);
    mysqli_stmt_execute($dupStmt);
    $dupRes = mysqli_stmt_get_result($dupStmt);
    $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
    mysqli_stmt_close($dupStmt);
    if ($dupRow) {
        return ['ok' => false, 'error' => 'A payment reversal already exists for this payment.', 'duplicate' => true];
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_account_ledger
         (customer_account_id, entry_date, entry_type, debit, credit, reference_type, reference_id, description, created_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Payment reversal could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'isssssssss',
        $accountId,
        $entryDate,
        $entryType,
        $debit,
        $credit,
        $refType,
        $refId,
        $description,
        $now,
        $createdBy
    );
    if (!mysqli_stmt_execute($stmt)) {
        $dup = mysqli_errno($conn) === 1062 || mysqli_stmt_errno($stmt) === 1062;
        mysqli_stmt_close($stmt);
        if ($dup) {
            return ['ok' => false, 'error' => 'A payment reversal already exists for this payment.', 'duplicate' => true];
        }
        error_log('customer account payment reversal insert failed');
        return ['ok' => false, 'error' => 'Payment reversal could not be saved.'];
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $newId];
}

/**
 * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string}
 */
function psCustomerAccountPaymentListForAccount(mysqli $conn, int $accountId, int $limit = 50): array
{
    if ($accountId < 1 || !psEnsureCustomerAccountPaymentsSchema($conn)) {
        return ['ok' => false, 'error' => 'Payments could not be loaded.', 'rows' => []];
    }
    if ($limit < 1 || $limit > 200) {
        $limit = 50;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM customer_account_payments
         WHERE customer_account_id = ?
         ORDER BY payment_date DESC, id DESC
         LIMIT ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Payments could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $accountId, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['amount'] = number_format((float)$row['amount'], 2, '.', '');
        $payId = (int)$row['id'];
        $u = psCustomerAccountPaymentUnallocated($conn, $payId);
        $row['allocated'] = $u['ok'] ? (string)$u['allocated'] : '0.00';
        $row['unallocated'] = $u['ok'] ? (string)$u['unallocated'] : '0.00';
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Global payment list with account / customer labels.
 *
 * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string}
 */
function psCustomerAccountPaymentList(mysqli $conn, string $search = '', string $statusFilter = 'all'): array
{
    if (!psEnsureCustomerAccountPaymentModule($conn)) {
        return ['ok' => false, 'error' => 'Payments could not be loaded.', 'rows' => []];
    }
    $statusFilter = strtolower(trim($statusFilter));
    if (!in_array($statusFilter, ['all', 'posted', 'voided'], true)) {
        $statusFilter = 'all';
    }

    $sql = 'SELECT p.*, a.account_number, a.currency AS account_currency, c.customer_code, c.first_name, c.last_name, c.business_name, c.customer_type
            FROM customer_account_payments p
            INNER JOIN customer_accounts a ON a.id = p.customer_account_id
            INNER JOIN customer_master c ON c.id = a.customer_master_id
            WHERE ' . psCustomerCompanySql($conn, 'c');
    $types = '';
    $params = [];

    if ($statusFilter === 'posted') {
        $sql .= ' AND p.status = ?';
        $types .= 's';
        $params[] = PS_CAP_STATUS_POSTED;
    } elseif ($statusFilter === 'voided') {
        $sql .= ' AND p.status = ?';
        $types .= 's';
        $params[] = PS_CAP_STATUS_VOIDED;
    }

    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (p.payment_number LIKE ? OR a.account_number LIKE ?
                     OR c.customer_code LIKE ? OR c.first_name LIKE ?
                     OR c.last_name LIKE ? OR c.business_name LIKE ?
                     OR p.reference LIKE ?)';
        $types .= 'sssssss';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
        }
    }

    $sql .= ' ORDER BY p.payment_date DESC, p.id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Payments could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        $bind = [$stmt, $types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['amount'] = number_format((float)$row['amount'], 2, '.', '');
        $row['display_name'] = psCustomerDisplayName($row);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string}
 */
function psCustomerAccountPaymentAllocationsForPayment(mysqli $conn, int $paymentId): array
{
    if ($paymentId < 1 || !psEnsureCustomerAccountPaymentAllocationsSchema($conn)) {
        return ['ok' => false, 'error' => 'Allocations could not be loaded.', 'rows' => []];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM customer_account_payment_allocations
         WHERE payment_id = ?
         ORDER BY id ASC'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Allocations could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $row['allocated_amount'] = number_format((float)$row['allocated_amount'], 2, '.', '');
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * @return array{ok:bool,rows?:list<array<string,mixed>>,error?:string}
 */
function psCustomerAccountPaymentAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureCustomerAccountPaymentAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Payment audit history could not be loaded.', 'rows' => []];
    }
    $sql = 'SELECT * FROM customer_account_payment_audit WHERE ' . psCustomerAccountIdScopeSql($conn, 'customer_account_id');
    $types = '';
    $params = [];
    $paymentId = (int)($filters['payment_id'] ?? 0);
    $accountId = (int)($filters['customer_account_id'] ?? 0);
    $limit = (int)($filters['limit'] ?? 300);
    if ($limit < 1 || $limit > 1000) {
        $limit = 300;
    }
    if ($paymentId > 0) {
        $sql .= ' AND payment_id = ?';
        $types .= 'i';
        $params[] = $paymentId;
    }
    if ($accountId > 0) {
        $sql .= ' AND customer_account_id = ?';
        $types .= 'i';
        $params[] = $accountId;
    }
    $sql .= ' ORDER BY changed_at DESC, id DESC LIMIT ?';
    $types .= 'i';
    $params[] = $limit;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Payment audit history could not be loaded.', 'rows' => []];
    }
    $bind = [$stmt, $types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Account-level receivable totals for view: balance, open credit-sale outstanding, recent payments.
 *
 * @return array{ok:bool,open_receivable?:string,credit_sale_outstanding_count?:int,error?:string}
 */
function psCustomerAccountOpenReceivable(mysqli $conn, int $accountId): array
{
    $list = psCustomerAccountAllocatableCreditSales($conn, $accountId);
    if (!$list['ok']) {
        return ['ok' => false, 'error' => $list['error'] ?? 'Open receivable could not be calculated.'];
    }
    $sum = 0.0;
    foreach ($list['rows'] as $row) {
        $sum += (float)$row['outstanding'];
    }
    return [
        'ok' => true,
        'open_receivable' => number_format($sum, 2, '.', ''),
        'credit_sale_outstanding_count' => count($list['rows']),
    ];
}

function psCapIssueSubmitToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_cap_submit_token'] = $token;
    return $token;
}

function psCapConsumeSubmitToken($posted): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = (string)($_SESSION['ps_cap_submit_token'] ?? '');
    $posted = trim((string)$posted);
    if ($expected === '' || $posted === '' || !hash_equals($expected, $posted)) {
        return false;
    }
    unset($_SESSION['ps_cap_submit_token']);
    return true;
}
