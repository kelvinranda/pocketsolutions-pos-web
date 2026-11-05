<?php
/**
 * Phase 11 — Accounting integration (export + reconciliation layer).
 *
 * No external GL credentials / platform in this codebase: produces balanced,
 * accounting-ready journal CSV batches with configurable mapping keys.
 * Does not mutate sales, payments, cash, refunds, purchasing, stock, or
 * sale_invoice_seq. Preview is read-only; export writes only accounting_* tables.
 *
 * Phase 12: COMPLETE stored tax splits journals (revenue net + tax.output).
 * LEGACY_INCOMPLETE / UNCONFIGURED / MIXED sales keep gross revenue.sales posting
 * (no fabricated tax). Inventory stock adjustments without a recorded cost on the
 * movement are not posted (exceptions only).
 */

require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/sale_payment.php';
require_once __DIR__ . '/tax.php';
require_once __DIR__ . '/expense_company.php';
require_once __DIR__ . '/customer_master.php';

const PS_ACCT_BATCH_EXPORTING = 'EXPORTING';
const PS_ACCT_BATCH_EXPORTED = 'EXPORTED';
const PS_ACCT_BATCH_FAILED = 'FAILED';
const PS_ACCT_BATCH_REVERSED = 'REVERSED';
const PS_ACCT_BATCH_REVERSAL = 'REVERSAL';

const PS_ACCT_SRC_SALE = 'SALE';
const PS_ACCT_SRC_REFUND = 'REFUND';
const PS_ACCT_SRC_GRV = 'GRV';
const PS_ACCT_SRC_PURCHASE_RETURN = 'PURCHASE_RETURN';
const PS_ACCT_SRC_EXPENSE = 'EXPENSE';
const PS_ACCT_SRC_CASH_IN = 'CASH_IN';
const PS_ACCT_SRC_CASH_OUT = 'CASH_OUT';
const PS_ACCT_SRC_ACCOUNT_PAYMENT = 'ACCOUNT_PAYMENT';

/**
 * Seed mapping keys (account codes empty until configured).
 *
 * @return list<array{mapping_key:string,label:string,description:string,is_required:int}>
 */
function psAccountingMappingSeed(): array
{
    $tenders = ['CASH', 'CARD', 'ECOCASH', 'BANK_TRANSFER', 'CREDIT', 'OTHER'];
    $rows = [
        [
            'mapping_key' => 'revenue.sales',
            'label' => 'Sales revenue',
            'description' => 'Credit side for modern completed POS sales (net of tax when COMPLETE tax data; otherwise gross).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'tax.output',
            'label' => 'Output tax / VAT payable',
            'description' => 'Credit side for COMPLETE taxable sales tax; debit side for PROCESSED refund tax. Configure before exporting COMPLETE tax splits.',
            'is_required' => 0,
        ],
        [
            'mapping_key' => 'refund.revenue',
            'label' => 'Refund revenue reversal',
            'description' => 'Debit side for PROCESSED sale refunds (revenue reduction; net of tax when COMPLETE).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'refund.tax',
            'label' => 'Refund tax adjustment',
            'description' => 'Credit side paired with tax.output debit for PROCESSED refund tax (COMPLETE snapshots only).',
            'is_required' => 0,
        ],
        [
            'mapping_key' => 'purchase.inventory',
            'label' => 'Purchases / inventory (GRV)',
            'description' => 'Debit side for goods receipts with recorded header total.',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'purchase.payable',
            'label' => 'Purchase payable / clearing (GRV)',
            'description' => 'Credit side for goods receipts (AP or clearing — configure per books).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'purchase_return.inventory',
            'label' => 'Purchase return inventory',
            'description' => 'Credit side for COMPLETED purchase returns (reduce inventory/purchases).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'purchase_return.payable',
            'label' => 'Purchase return payable',
            'description' => 'Debit side for COMPLETED purchase returns.',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'expense.expense',
            'label' => 'Expense',
            'description' => 'Debit side for recorded expenses (company-wide; no branch column).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'expense.funding',
            'label' => 'Expense funding / cash',
            'description' => 'Credit side for expenses (funding account clearing).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'cash_in.cash',
            'label' => 'Cash in (till cash)',
            'description' => 'Debit till cash for manual CASH_IN movements.',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'cash_in.contra',
            'label' => 'Cash in contra',
            'description' => 'Credit contra for manual CASH_IN.',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'cash_out.cash',
            'label' => 'Cash out (till cash)',
            'description' => 'Credit till cash for manual CASH_OUT movements.',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'cash_out.contra',
            'label' => 'Cash out contra',
            'description' => 'Debit contra for manual CASH_OUT (petty cash / expense clearing — configure).',
            'is_required' => 1,
        ],
        [
            'mapping_key' => 'ar.receipt',
            'label' => 'Accounts receivable (receipt)',
            'description' => 'Credit AR for POSTED customer account payments.',
            'is_required' => 1,
        ],
    ];
    foreach ($tenders as $m) {
        $rows[] = [
            'mapping_key' => 'tender.' . $m,
            'label' => 'Sale tender ' . $m,
            'description' => 'Debit side for sale payment method ' . $m . '.',
            'is_required' => 1,
        ];
        $rows[] = [
            'mapping_key' => 'refund.tender.' . $m,
            'label' => 'Refund tender ' . $m,
            'description' => 'Credit side for PROCESSED refunds attributed to tender ' . $m . '.',
            'is_required' => 1,
        ];
        $rows[] = [
            'mapping_key' => 'ar.tender.' . $m,
            'label' => 'AR receipt tender ' . $m,
            'description' => 'Debit side for customer account payments received via ' . $m . '.',
            'is_required' => 1,
        ];
    }
    return $rows;
}

function psAccountingMayView(): bool
{
    return psBranchSessionAuthenticated();
}

function psAccountingMayConfigure(): bool
{
    return psBranchSessionIsAdmin();
}

function psAccountingMayExport(): bool
{
    // Ordinary home-branch users may export their authorised scope; admin any registered.
    return psBranchSessionAuthenticated();
}

function psAccountingRequireAuthRedirect(string $login = 'login.php'): void
{
    psBranchRequireAuthRedirect($login);
}

function psAccountingRequireConfigureRedirect(string $fallback = 'accounting.php'): void
{
    if (!psAccountingMayConfigure()) {
        header('Location: ' . $fallback);
        exit;
    }
}

function psAccountingMoneyAdd(string $a, string $b): string
{
    return psReportingMoneyAdd($a, $b);
}

function psAccountingMoneySub(string $a, string $b): string
{
    return psReportingMoneySub($a, $b);
}

function psAccountingMoneyFormat($raw): string
{
    return psReportingMoneyFormat($raw);
}

/**
 * Parse a recorded money string to decimal string, or null if invalid/empty.
 */
function psAccountingParseMoney($raw): ?string
{
    if (is_bool($raw) || is_array($raw) || $raw === null) {
        return null;
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    // Allow optional thousands commas then normalise.
    $raw = str_replace(',', '', $raw);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
        return null;
    }
    $cents = psDecimalCents(ltrim($raw, '+'));
    if ($raw[0] === '-') {
        $pos = psDecimalCents(substr($raw, 1));
        if ($pos === null) {
            return null;
        }
        return psMoneyFromCents(-$pos);
    }
    if ($cents === null) {
        return null;
    }
    return psMoneyFromCents($cents);
}

function psAccountingHasTable(mysqli $conn, string $table): bool
{
    $esc = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$esc}'");
    return $res && mysqli_num_rows($res) > 0;
}

function psAccountingHasIndex(mysqli $conn, string $table, string $index): bool
{
    $t = mysqli_real_escape_string($conn, $table);
    $i = mysqli_real_escape_string($conn, $index);
    $res = mysqli_query($conn, "SHOW INDEX FROM `{$t}` WHERE Key_name = '{$i}'");
    return $res && mysqli_num_rows($res) > 0;
}

function psEnsureAccountingMappingsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS accounting_account_mappings (
        mapping_key VARCHAR(64) NOT NULL,
        label VARCHAR(120) NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL DEFAULT '',
        account_code VARCHAR(64) NOT NULL DEFAULT '',
        account_name VARCHAR(250) NOT NULL DEFAULT '',
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        updated_by VARCHAR(250) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (mapping_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('accounting_account_mappings schema failed');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureAccountingBatchesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS accounting_export_batches (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_number VARCHAR(32) NOT NULL,
        status VARCHAR(16) NOT NULL,
        from_date DATE NULL,
        to_date DATE NULL,
        branch_filter VARCHAR(250) NOT NULL DEFAULT '',
        all_branches TINYINT(1) NOT NULL DEFAULT 0,
        source_types VARCHAR(500) NOT NULL DEFAULT '',
        created_by VARCHAR(250) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        exported_at DATETIME NULL,
        reversed_at DATETIME NULL,
        reversal_of_batch_id INT NULL,
        total_debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        total_credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        source_count INT NOT NULL DEFAULT 0,
        entry_count INT NOT NULL DEFAULT 0,
        error_message VARCHAR(500) NOT NULL DEFAULT '',
        notes VARCHAR(500) NOT NULL DEFAULT '',
        external_status VARCHAR(32) NOT NULL DEFAULT 'NOT_SENT',
        UNIQUE KEY uniq_acct_batch_number (batch_number),
        KEY idx_acct_batch_status (status),
        KEY idx_acct_batch_created (created_at),
        KEY idx_acct_batch_dates (from_date, to_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('accounting_export_batches schema failed');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureAccountingBatchSourcesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS accounting_export_batch_sources (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        source_type VARCHAR(32) NOT NULL,
        source_id VARCHAR(64) NOT NULL,
        doc_number VARCHAR(64) NOT NULL DEFAULT '',
        txn_date DATE NOT NULL,
        branch VARCHAR(250) NOT NULL DEFAULT '',
        branch_label VARCHAR(64) NOT NULL DEFAULT '',
        amount DECIMAL(14,2) NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        payment_method VARCHAR(32) NOT NULL DEFAULT '',
        exception_flags VARCHAR(500) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_acct_batch_source (batch_id, source_type, source_id),
        KEY idx_acct_batch_src_type (source_type, source_id),
        KEY idx_acct_batch_src_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('accounting_export_batch_sources schema failed');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureAccountingEntriesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS accounting_export_entries (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        entry_seq INT NOT NULL,
        source_type VARCHAR(32) NOT NULL,
        source_id VARCHAR(64) NOT NULL,
        doc_number VARCHAR(64) NOT NULL DEFAULT '',
        txn_date DATE NOT NULL,
        branch VARCHAR(250) NOT NULL DEFAULT '',
        debit_mapping_key VARCHAR(64) NOT NULL,
        credit_mapping_key VARCHAR(64) NOT NULL,
        debit_account_code VARCHAR(64) NOT NULL DEFAULT '',
        debit_account_name VARCHAR(250) NOT NULL DEFAULT '',
        credit_account_code VARCHAR(64) NOT NULL DEFAULT '',
        credit_account_name VARCHAR(250) NOT NULL DEFAULT '',
        amount DECIMAL(14,2) NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        payment_method VARCHAR(32) NOT NULL DEFAULT '',
        memo VARCHAR(500) NOT NULL DEFAULT '',
        KEY idx_acct_entry_batch (batch_id),
        KEY idx_acct_entry_source (source_type, source_id),
        UNIQUE KEY uniq_acct_entry_seq (batch_id, entry_seq)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('accounting_export_entries schema failed');
        return false;
    }
    // Multi-currency (2026-10-20): amount/currency are the company base; the document's own currency is kept beside it.
    foreach (['txn_currency' => 'CHAR(3) NULL DEFAULT NULL', 'txn_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL', 'fx_rate' => 'DECIMAL(24,10) NULL DEFAULT NULL'] as $col => $def) {
        $has = mysqli_query($conn, "SHOW COLUMNS FROM accounting_export_entries LIKE '$col'");
        if ($has && mysqli_num_rows($has) === 0 && !mysqli_query($conn, "ALTER TABLE accounting_export_entries ADD COLUMN $col $def") && mysqli_errno($conn) !== 1060) {
            error_log('accounting_export_entries ' . $col . ' not added');
            return false;
        }
    }
    $ready = true;
    return true;
}

/**
 * Multi-currency (2026-10-20): the rate snapshot stored on a source document. Rows from before multi-currency are the
 * company base at 1:1. Returns null when a non-base document has no usable rate (it is then blocked from export).
 */
function psAccountingSourceSnapshot(mysqli $conn, string $type, string $id, string $company): ?array
{
    require_once __DIR__ . '/currency.php';
    $map = [
        PS_ACCT_SRC_SALE => 'online_sales_ref',
        PS_ACCT_SRC_GRV => 'goods_receipts_ref',
        PS_ACCT_SRC_PURCHASE_RETURN => 'purchase_returns',
        PS_ACCT_SRC_EXPENSE => 'expenses',
        PS_ACCT_SRC_CASH_IN => 'cash_movements',
        PS_ACCT_SRC_CASH_OUT => 'cash_movements',
        PS_ACCT_SRC_ACCOUNT_PAYMENT => 'customer_account_payments',
    ];
    $idInt = (int)$id;
    if ($type === PS_ACCT_SRC_REFUND) {
        // A refund goes back at the original sale's rate.
        $res = mysqli_query($conn, 'SELECT invoice_number FROM sale_refund_requests WHERE id = ' . $idInt);
        $inv = ($res && ($r = mysqli_fetch_row($res))) ? (string)$r[0] : '';
        $snap = psFxSaleSnapshot($conn, $inv, $company);
        return $snap['frac'] !== null ? $snap : null;
    }
    $table = $map[$type] ?? '';
    $row = [];
    if ($table !== '' && psFxHasColumn($conn, $table, 'currency')) {
        $res = mysqli_query($conn, "SELECT currency, base_currency, fx_rate, fx_rate_ids, fx_path FROM `$table` WHERE id = " . $idInt);
        $row = ($res && ($r = mysqli_fetch_assoc($res))) ? $r : [];
    }
    $snap = psFxStoredSnapshot($conn, $row, $company);
    if ($snap['frac'] === null) {
        return null;
    }
    $snap['ids'] = (string)($row['fx_rate_ids'] ?? '');
    return $snap;
}

/** Keep the document currency / amount / rate beside a stored base entry (no-op for entries without them). */
function psAccountingStoreEntryFx(mysqli $conn, int $batchId, int $seq, array $e): void
{
    if (!isset($e['txn_currency']) || $e['txn_currency'] === null || $e['txn_currency'] === '') {
        return;
    }
    $stmt = mysqli_prepare($conn, 'UPDATE accounting_export_entries SET txn_currency = ?, txn_amount = ?, fx_rate = ? WHERE batch_id = ? AND entry_seq = ?');
    if (!$stmt) {
        throw new RuntimeException('Entry currency was not saved');
    }
    $c = (string)$e['txn_currency'];
    $a = (string)$e['txn_amount'];
    $r = (string)$e['fx_rate'];
    mysqli_stmt_bind_param($stmt, 'sssii', $c, $a, $r, $batchId, $seq);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        throw new RuntimeException('Entry currency was not saved');
    }
}

/**
 * Convert every journal pair of a source into the company base with the source's own stored rate (each pair is converted
 * as a whole, so debit = credit stays exact). The document currency, amount and rate are kept on each entry.
 */
function psAccountingApplyFx(mysqli $conn, array $sources): array
{
    require_once __DIR__ . '/currency.php';
    $company = psFxSessionCompany();
    $base = psFxBase($conn, $company);
    foreach ($sources as $i => $src) {
        if (empty($src['source_id']) || (string)$src['source_id'] === '0') {
            continue;
        }
        $snap = psAccountingSourceSnapshot($conn, (string)$src['source_type'], (string)$src['source_id'], $company);
        if ($snap === null) {
            $sources[$i]['exceptions'][] = 'MISSING_EXCHANGE_RATE';
            $sources[$i]['eligible'] = false;
            continue;
        }
        if (($snap['base'] ?? $base) !== $base) {
            $sources[$i]['exceptions'][] = 'BASE_CURRENCY_MISMATCH';
            $sources[$i]['eligible'] = false;
            continue;
        }
        $sources[$i]['txn_currency'] = $snap['currency'];
        $sources[$i]['txn_amount'] = $src['amount'] ?? null;
        $sources[$i]['fx_rate'] = $snap['currency'] === $base ? '1' : (string)$snap['rate'];
        if (isset($src['amount']) && $src['amount'] !== null && $src['amount'] !== '') {
            $sources[$i]['amount'] = psFxCentsToMoney(psFxToBaseCents($conn, $snap, (int)psFxMoneyToCents(psAccountingMoneyFormat($src['amount']))));
        }
        $sources[$i]['currency'] = $base;
        foreach (($src['entries'] ?? []) as $j => $e) {
            $txnAmt = psAccountingMoneyFormat($e['amount']);
            $baseAmt = psFxCentsToMoney(psFxToBaseCents($conn, $snap, (int)psFxMoneyToCents($txnAmt)));
            $sources[$i]['entries'][$j]['txn_currency'] = $snap['currency'];
            $sources[$i]['entries'][$j]['txn_amount'] = $txnAmt;
            $sources[$i]['entries'][$j]['fx_rate'] = $sources[$i]['fx_rate'];
            $sources[$i]['entries'][$j]['amount'] = $baseAmt;
            $sources[$i]['entries'][$j]['currency'] = $base;
            if ($snap['currency'] !== $base) {
                $sources[$i]['entries'][$j]['memo'] = substr((string)$e['memo'] . ' [' . $txnAmt . ' ' . $snap['currency'] . ' @ ' . $sources[$i]['fx_rate'] . ']', 0, 500);
            }
            if ((float)$baseAmt <= 0) {
                // A tiny foreign amount can round to zero in the base; such a pair cannot be posted.
                $sources[$i]['exceptions'][] = 'ROUNDS_TO_ZERO_IN_BASE';
                $sources[$i]['eligible'] = false;
            }
        }
        $sources[$i]['exceptions'] = array_values(array_unique($sources[$i]['exceptions'] ?? []));
    }
    return $sources;
}

function psEnsureAccountingExportedSourcesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS accounting_exported_sources (
        source_type VARCHAR(32) NOT NULL,
        source_id VARCHAR(64) NOT NULL,
        batch_id INT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'EXPORTED',
        exported_at DATETIME NOT NULL,
        reversed_at DATETIME NULL,
        reversed_by_batch_id INT NULL,
        PRIMARY KEY (source_type, source_id),
        KEY idx_acct_exported_batch (batch_id),
        KEY idx_acct_exported_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('accounting_exported_sources schema failed');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureAccountingSeqSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS accounting_export_seq (
        id TINYINT NOT NULL PRIMARY KEY,
        next_number BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('accounting_export_seq schema failed');
        return false;
    }
    $check = mysqli_query($conn, 'SELECT next_number FROM accounting_export_seq WHERE id = 1 LIMIT 1');
    if (!$check || mysqli_num_rows($check) === 0) {
        if (!mysqli_query($conn, 'INSERT INTO accounting_export_seq (id, next_number) VALUES (1, 1)')) {
            error_log('accounting_export_seq seed failed');
            return false;
        }
    }
    $ready = true;
    return true;
}

/** 2026-11-02: the signed-in company (accounting mappings and batches are per company). */
function psAccountingCompany(): string
{
    return session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['the_company'] ?? '')) : '';
}

/**
 * 2026-11-02 — Per-company mappings and batches. Mappings were one shared set for every company and
 * batches were listed for every company. Adds company_name to both tables (idempotent).
 */
function psAccountingEnsureCompanyColumns(mysqli $conn): bool
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }
    $done = true;
    $c = mysqli_query($conn, "SHOW COLUMNS FROM accounting_account_mappings LIKE 'company_name'");
    if ($c && mysqli_num_rows($c) === 0) {
        $done = (bool)mysqli_query($conn, "ALTER TABLE accounting_account_mappings
            ADD COLUMN company_name VARCHAR(250) NOT NULL DEFAULT '' FIRST,
            DROP PRIMARY KEY, ADD PRIMARY KEY (company_name, mapping_key)");
    }
    $c = mysqli_query($conn, "SHOW COLUMNS FROM accounting_export_batches LIKE 'company_name'");
    if ($c && mysqli_num_rows($c) === 0) {
        $done = $done && (bool)mysqli_query($conn, 'ALTER TABLE accounting_export_batches
            ADD COLUMN company_name VARCHAR(250) NULL, ADD KEY idx_acct_batch_company (company_name)');
    }
    return $done;
}

/**
 * Batches the signed-in company may see: its own, plus older (unlabelled) single-branch batches of its branches.
 */
function psAccountingBatchCompanySql(mysqli $conn, string $alias = ''): string
{
    $co = psAccountingCompany();
    if ($co === '') {
        return '0';
    }
    $a = $alias !== '' ? $alias . '.' : '';
    require_once __DIR__ . '/product_master.php';
    return '(' . $a . "company_name = '" . mysqli_real_escape_string($conn, $co) . "' OR (" . $a . 'company_name IS NULL AND '
        . $a . 'all_branches = 0 AND ' . psCompanyBranchesInSql($conn, $a . 'branch_filter') . '))';
}

/**
 * Ensure schema + seed empty mapping rows (does not invent account codes).
 */
function psEnsureAccountingModule(mysqli $conn): bool
{
    if (!psEnsureAccountingMappingsSchema($conn)
        || !psEnsureAccountingBatchesSchema($conn)
        || !psEnsureAccountingBatchSourcesSchema($conn)
        || !psEnsureAccountingEntriesSchema($conn)
        || !psEnsureAccountingExportedSourcesSchema($conn)
        || !psEnsureAccountingSeqSchema($conn)
        || !psAccountingEnsureCompanyColumns($conn)) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    $company = psAccountingCompany();
    if ($company === '') {
        return true;
    }
    // First use by a company: take over the old shared codes only if they were last saved by one of its own users.
    $own = mysqli_prepare($conn, "SELECT COUNT(*) AS n FROM accounting_account_mappings WHERE company_name = ?");
    if ($own) {
        mysqli_stmt_bind_param($own, 's', $company);
        mysqli_stmt_execute($own);
        $hasOwn = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($own))['n'] ?? 0) > 0;
        mysqli_stmt_close($own);
        if (!$hasOwn) {
            $copy = mysqli_prepare($conn, "INSERT IGNORE INTO accounting_account_mappings
                    (company_name, mapping_key, label, description, account_code, account_name, is_required, updated_by, updated_at)
                SELECT ?, m.mapping_key, m.label, m.description, m.account_code, m.account_name, m.is_required, m.updated_by, m.updated_at
                  FROM accounting_account_mappings m
                 WHERE m.company_name = '' AND EXISTS (SELECT 1 FROM users u WHERE u.company_name = ?
                       AND (u.email = m.updated_by OR CONCAT(u.firstname, ' ', u.lastname) = m.updated_by))");
            if ($copy) {
                mysqli_stmt_bind_param($copy, 'ss', $company, $company);
                mysqli_stmt_execute($copy);
                mysqli_stmt_close($copy);
            }
        }
    }
    foreach (psAccountingMappingSeed() as $row) {
        $key = $row['mapping_key'];
        $stmt = mysqli_prepare(
            $conn,
            'INSERT IGNORE INTO accounting_account_mappings
                (company_name, mapping_key, label, description, account_code, account_name, is_required, updated_by, updated_at)
             VALUES (?, ?, ?, ?, \'\', \'\', ?, \'system\', ?)'
        );
        if (!$stmt) {
            error_log('accounting mapping seed prepare failed');
            return false;
        }
        $req = (int)$row['is_required'];
        $label = $row['label'];
        $desc = $row['description'];
        mysqli_stmt_bind_param($stmt, 'ssssis', $company, $key, $label, $desc, $req, $now);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            error_log('accounting mapping seed failed');
            return false;
        }
        mysqli_stmt_close($stmt);
    }
    return true;
}

/**
 * @return array<string,array{mapping_key:string,label:string,description:string,account_code:string,account_name:string,is_required:int}>
 */
function psAccountingLoadMappings(mysqli $conn): array
{
    if (!psEnsureAccountingModule($conn)) {
        return [];
    }
    $out = [];
    $res = mysqli_query(
        $conn,
        "SELECT mapping_key, label, description, account_code, account_name, is_required
         FROM accounting_account_mappings WHERE company_name = '" . mysqli_real_escape_string($conn, psAccountingCompany()) . "'
         ORDER BY mapping_key ASC"
    );
    if (!$res) {
        return [];
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $k = (string)$row['mapping_key'];
        $out[$k] = [
            'mapping_key' => $k,
            'label' => (string)$row['label'],
            'description' => (string)$row['description'],
            'account_code' => trim((string)$row['account_code']),
            'account_name' => trim((string)$row['account_name']),
            'is_required' => (int)$row['is_required'],
        ];
    }
    return $out;
}

/**
 * @param array<string,string> $codes mapping_key => account_code
 * @param array<string,string> $names mapping_key => account_name
 */
function psAccountingSaveMappings(mysqli $conn, array $codes, array $names, string $user): array
{
    if (!psAccountingMayConfigure()) {
        return ['ok' => false, 'error' => 'Only an administrator may change accounting mappings.'];
    }
    if (!psEnsureAccountingModule($conn)) {
        return ['ok' => false, 'error' => 'Accounting storage is not available.'];
    }
    $user = trim($user);
    if ($user === '') {
        $user = 'unknown';
    }
    $now = date('Y-m-d H:i:s');
    $existing = psAccountingLoadMappings($conn);
    foreach ($existing as $key => $meta) {
        $code = isset($codes[$key]) ? trim((string)$codes[$key]) : $meta['account_code'];
        $name = isset($names[$key]) ? trim((string)$names[$key]) : $meta['account_name'];
        if (strlen($code) > 64 || strlen($name) > 250) {
            return ['ok' => false, 'error' => 'Account code or name is too long for ' . $key . '.'];
        }
        if (preg_match('/[^\x20-\x7E]/', $code) || preg_match('/[^\x20-\x7E]/', $name)) {
            // Allow printable ASCII only for export safety; names may need unicode — relax name.
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $code) || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return ['ok' => false, 'error' => 'Invalid characters in mapping for ' . $key . '.'];
        }
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE accounting_account_mappings
             SET account_code = ?, account_name = ?, updated_by = ?, updated_at = ?
             WHERE mapping_key = ? AND company_name = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Mappings could not be saved.'];
        }
        $mapCompany = psAccountingCompany();
        mysqli_stmt_bind_param($stmt, 'ssssss', $code, $name, $user, $now, $key, $mapCompany);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return ['ok' => false, 'error' => 'Mappings could not be saved.'];
        }
        mysqli_stmt_close($stmt);
    }
    return ['ok' => true];
}

/**
 * @param list<string> $keys
 * @return array{ok:bool,missing:list<string>,error?:string}
 */
function psAccountingValidateMappings(mysqli $conn, array $keys): array
{
    $maps = psAccountingLoadMappings($conn);
    $missing = [];
    foreach ($keys as $key) {
        $key = (string)$key;
        if ($key === '') {
            continue;
        }
        if (!isset($maps[$key]) || trim((string)$maps[$key]['account_code']) === '') {
            $missing[] = $key;
        }
    }
    $missing = array_values(array_unique($missing));
    if ($missing !== []) {
        return [
            'ok' => false,
            'missing' => $missing,
            'error' => 'Required account mappings are not configured: ' . implode(', ', $missing),
        ];
    }
    return ['ok' => true, 'missing' => []];
}

/**
 * @return list<string>
 */
function psAccountingDefaultSourceTypes(): array
{
    return [
        PS_ACCT_SRC_SALE,
        PS_ACCT_SRC_REFUND,
        PS_ACCT_SRC_GRV,
        PS_ACCT_SRC_PURCHASE_RETURN,
        PS_ACCT_SRC_EXPENSE,
        PS_ACCT_SRC_CASH_IN,
        PS_ACCT_SRC_CASH_OUT,
        PS_ACCT_SRC_ACCOUNT_PAYMENT,
    ];
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function psAccountingNormalizeSourceTypes($raw): array
{
    $allowed = psAccountingDefaultSourceTypes();
    if (is_string($raw)) {
        $raw = preg_split('/[,\s]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return $allowed;
    }
    $out = [];
    foreach ($raw as $t) {
        $t = strtoupper(trim((string)$t));
        if (in_array($t, $allowed, true)) {
            $out[] = $t;
        }
    }
    return $out !== [] ? array_values(array_unique($out)) : $allowed;
}

/**
 * Normalize filters with Phase 9/10 branch ACL.
 *
 * @return array{ok:bool,filters?:array,error?:string}
 */
function psAccountingNormalizeFilters(mysqli $conn, array $raw): array
{
    if (!psAccountingMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view accounting.'];
    }
    $dates = psReportingResolveDateRange($raw['from_date'] ?? '', $raw['to_date'] ?? '');
    if (empty($dates['ok'])) {
        return ['ok' => false, 'error' => $dates['error'] ?? 'Date filter is not valid.'];
    }
    $postedBranch = psBranchSessionIsAdmin() ? ($raw['branch'] ?? null) : null;
    $branchRes = psReportingResolveBranch($conn, $postedBranch);
    if (empty($branchRes['ok'])) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
    }
    return [
        'ok' => true,
        'filters' => [
            'from_date' => $dates['from_date'],
            'to_date' => $dates['to_date'],
            'branch' => (string)($branchRes['branch'] ?? ''),
            'all_branches' => !empty($branchRes['all_branches']),
            'branches' => $branchRes['branches'] ?? [],
            'source_types' => psAccountingNormalizeSourceTypes($raw['source_types'] ?? null),
            'include_exported' => !empty($raw['include_exported']),
        ],
    ];
}

/**
 * Branch attribution label for export/display.
 *
 * @return array{branch:string,label:string,in_scope:bool}
 */
function psAccountingBranchMeta(string $branch, array $filters): array
{
    $branch = psBranchNormalizeName($branch);
    if ($branch === '') {
        return ['branch' => '', 'label' => 'UNALLOCATED', 'in_scope' => false];
    }
    $allowed = $filters['branches'] ?? [];
    $in = psBranchNameInList($branch, $allowed);
    if (!$in) {
        // Orphan / foreign — preserve string but out of authorised export scope.
        return ['branch' => $branch, 'label' => 'ORPHAN_OR_UNSCOPED', 'in_scope' => false];
    }
    return ['branch' => $branch, 'label' => 'BRANCH', 'in_scope' => true];
}

/**
 * @return array{exported:bool,batch_id:?int,status:string}
 */
function psAccountingSourceExportState(mysqli $conn, string $type, string $id): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT batch_id, status FROM accounting_exported_sources
         WHERE source_type = ? AND source_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['exported' => false, 'batch_id' => null, 'status' => ''];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $type, $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['exported' => false, 'batch_id' => null, 'status' => ''];
    }
    $st = strtoupper(trim((string)$row['status']));
    if ($st === 'EXPORTED') {
        return ['exported' => true, 'batch_id' => (int)$row['batch_id'], 'status' => $st];
    }
    return ['exported' => false, 'batch_id' => (int)$row['batch_id'], 'status' => $st];
}

/**
 * Build one balanced journal pair (amount > 0).
 *
 * @return array{ok:bool,entry?:array,error?:string,missing?:list<string>}
 */
function psAccountingBuildPair(
    array $maps,
    string $debitKey,
    string $creditKey,
    string $amount,
    array $meta
): array {
    $amount = psAccountingMoneyFormat($amount);
    if ((float)$amount <= 0) {
        return ['ok' => false, 'error' => 'Amount must be positive.'];
    }
    $missing = [];
    if (!isset($maps[$debitKey]) || $maps[$debitKey]['account_code'] === '') {
        $missing[] = $debitKey;
    }
    if (!isset($maps[$creditKey]) || $maps[$creditKey]['account_code'] === '') {
        $missing[] = $creditKey;
    }
    if ($missing !== []) {
        return ['ok' => false, 'missing' => $missing, 'error' => 'Missing mappings'];
    }
    return [
        'ok' => true,
        'entry' => [
            'source_type' => (string)($meta['source_type'] ?? ''),
            'source_id' => (string)($meta['source_id'] ?? ''),
            'doc_number' => (string)($meta['doc_number'] ?? ''),
            'txn_date' => (string)($meta['txn_date'] ?? ''),
            'branch' => (string)($meta['branch'] ?? ''),
            'debit_mapping_key' => $debitKey,
            'credit_mapping_key' => $creditKey,
            'debit_account_code' => $maps[$debitKey]['account_code'],
            'debit_account_name' => $maps[$debitKey]['account_name'],
            'credit_account_code' => $maps[$creditKey]['account_code'],
            'credit_account_name' => $maps[$creditKey]['account_name'],
            'amount' => $amount,
            'currency' => (string)($meta['currency'] ?? 'USD'),
            'payment_method' => (string)($meta['payment_method'] ?? ''),
            'memo' => (string)($meta['memo'] ?? ''),
        ],
    ];
}

/**
 * 2026-11-05 split payment / split currency: sale_payments rows -> one row per method in the SALE currency, net.
 * Tenders in another currency count at sale_amount (their value in the sale currency); the change row (is_change = 1,
 * always cash) comes off the cash. Old single-row sales pass through unchanged.
 */
function psAccountingNormaliseTenders(mysqli $conn, string $invoice, array $payments): array
{
    $split = false;
    foreach ($payments as $p) {
        if ($p['tender_seq'] !== null && (count($payments) > 1 || (int)$p['is_change'] === 1)) {
            $split = true;
        }
    }
    if (!$split) {
        return $payments;
    }
    $saleCur = 'USD';
    $h = mysqli_prepare($conn, 'SELECT currency FROM online_sales_ref WHERE invoice_number = ? LIMIT 1');
    if ($h) {
        mysqli_stmt_bind_param($h, 's', $invoice);
        mysqli_stmt_execute($h);
        $hr = mysqli_fetch_assoc(mysqli_stmt_get_result($h));
        mysqli_stmt_close($h);
        $saleCur = trim((string)($hr['currency'] ?? '')) ?: $saleCur;
    }
    $byMethod = [];
    $change = 0;
    foreach ($payments as $p) {
        $cents = (int)round(((float)($p['sale_amount'] ?? $p['amount_paid'])) * 100);
        if ((int)$p['is_change'] === 1) {
            $change += $cents;
            continue;
        }
        $m = strtoupper(trim((string)$p['method']));
        $byMethod[$m] = ($byMethod[$m] ?? 0) + $cents - (int)round(((float)$p['change_amount']) * 100);
    }
    if ($change > 0) {
        $byMethod['CASH'] = ($byMethod['CASH'] ?? 0) - $change;
    }
    $out = [];
    foreach ($byMethod as $m => $cents) {
        $out[] = ['id' => 0, 'method' => $m, 'amount_paid' => psMoneyFromCents($cents), 'change_amount' => '0.00', 'currency' => $saleCur];
    }
    return $out;
}

/**
 * Collect SALE candidates (modern completed only).
 *
 * @return list<array>
 */
function psAccountingCollectSales(mysqli $conn, array $filters, array $maps, bool $includeExported): array
{
    $items = [];
    $where = '1=1 AND ' . psReportingSaleCompletedSql('r');
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params, $filters['from_date'], $filters['to_date'], 'r'
    );
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'r.branch'
    );

    psEnsureSaleTaxColumns($conn);
    $sql = "SELECT r.id, r.invoice_number, r.totalz, r.branch, r.status, r.sold_at, r.date_,
                   r.tax_data_status, r.tax_amount, r.taxable_amount,
                   COALESCE(DATE(r.sold_at), STR_TO_DATE(r.date_, '%Y-%m-%d')) AS txn_date
            FROM online_sales_ref r
            WHERE {$where}
            ORDER BY txn_date ASC, r.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $invoice = (string)$row['invoice_number'];
        $sourceId = (string)$row['id'];
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $exp = psAccountingSourceExportState($conn, PS_ACCT_SRC_SALE, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        // Payments for this invoice
        $pst = mysqli_prepare(
            $conn,
            'SELECT id, method, amount_paid, change_amount, currency, sale_amount, is_change, tender_seq
             FROM sale_payments WHERE invoice_number = ? ORDER BY id ASC'
        );
        $payments = [];
        if ($pst) {
            mysqli_stmt_bind_param($pst, 's', $invoice);
            mysqli_stmt_execute($pst);
            $pres = mysqli_stmt_get_result($pst);
            while ($pres && ($p = mysqli_fetch_assoc($pres))) {
                $payments[] = $p;
            }
            mysqli_stmt_close($pst);
        }
        $payments = psAccountingNormaliseTenders($conn, $invoice, $payments);
        if ($payments === []) {
            $exceptions[] = 'MISSING_PAYMENT';
        }
        $entries = [];
        $missingKeys = [];
        $netTotal = '0.00';
        $currency = 'USD';
        $methods = [];
        foreach ($payments as $p) {
            $method = strtoupper(trim((string)$p['method']));
            if ($method === '') {
                $exceptions[] = 'MISSING_PAYMENT_METHOD';
                continue;
            }
            $paid = psAccountingParseMoney($p['amount_paid']);
            $change = psAccountingParseMoney($p['change_amount']);
            if ($paid === null || $change === null) {
                $exceptions[] = 'INVALID_PAYMENT_AMOUNT';
                continue;
            }
            $net = psAccountingMoneySub($paid, $change);
            if ((float)$net <= 0) {
                $exceptions[] = 'NON_POSITIVE_NET_PAYMENT';
                continue;
            }
            $currency = trim((string)($p['currency'] ?? '')) ?: 'USD';
            $methods[] = $method;
            $netTotal = psAccountingMoneyAdd($netTotal, $net);
        }

        $taxStatus = strtoupper(trim((string)($row['tax_data_status'] ?? PS_TAX_STATUS_LEGACY)));
        $splitTax = ($taxStatus === PS_TAX_STATUS_COMPLETE);
        $headerTax = $splitTax ? psAccountingParseMoney($row['tax_amount']) : null;
        $headerTaxable = $splitTax ? psAccountingParseMoney($row['taxable_amount']) : null;
        if ($splitTax && ($headerTax === null || $headerTaxable === null)) {
            // Header incomplete despite COMPLETE flag — do not fabricate; fall back to gross + exception.
            $splitTax = false;
            $exceptions[] = 'TAX_HEADER_INCOMPLETE';
        }

        if ($splitTax && count($payments) === 1 && (float)$headerTax > 0) {
            $p = $payments[0];
            $method = strtoupper(trim((string)$p['method']));
            $paid = psAccountingParseMoney($p['amount_paid']);
            $change = psAccountingParseMoney($p['change_amount']);
            $gross = psAccountingMoneySub($paid, $change);
            $revenueAmt = psAccountingMoneySub($gross, $headerTax);
            if ((float)$revenueAmt < 0) {
                $exceptions[] = 'TAX_EXCEEDS_GROSS';
            } else {
                if ((float)$revenueAmt > 0) {
                    $pair = psAccountingBuildPair(
                        $maps,
                        'tender.' . $method,
                        'revenue.sales',
                        $revenueAmt,
                        [
                            'source_type' => PS_ACCT_SRC_SALE,
                            'source_id' => $sourceId,
                            'doc_number' => $invoice,
                            'txn_date' => (string)$row['txn_date'],
                            'branch' => $bMeta['branch'],
                            'currency' => $currency,
                            'payment_method' => $method,
                            'memo' => 'Sale ' . $invoice . ' (excl tax)',
                        ]
                    );
                    if (empty($pair['ok'])) {
                        if (!empty($pair['missing'])) {
                            $missingKeys = array_merge($missingKeys, $pair['missing']);
                        }
                        $exceptions[] = 'MAPPING_OR_BUILD';
                    } else {
                        $entries[] = $pair['entry'];
                    }
                }
                $taxPair = psAccountingBuildPair(
                    $maps,
                    'tender.' . $method,
                    'tax.output',
                    $headerTax,
                    [
                        'source_type' => PS_ACCT_SRC_SALE,
                        'source_id' => $sourceId,
                        'doc_number' => $invoice,
                        'txn_date' => (string)$row['txn_date'],
                        'branch' => $bMeta['branch'],
                        'currency' => $currency,
                        'payment_method' => $method,
                        'memo' => 'Sale ' . $invoice . ' output tax',
                    ]
                );
                if (empty($taxPair['ok'])) {
                    if (!empty($taxPair['missing'])) {
                        $missingKeys = array_merge($missingKeys, $taxPair['missing']);
                    }
                    $exceptions[] = 'TAX_MAPPING_REQUIRED';
                } else {
                    $entries[] = $taxPair['entry'];
                }
            }
        } elseif ($splitTax && count($payments) > 1 && (float)$headerTax > 0) {
            // Multi-tender: allocate tax proportionally across payments; remainder on last.
            $taxTotalCents = (int)round(((float)$headerTax) * 100);
            $allocatedTaxCents = 0;
            $validPays = [];
            foreach ($payments as $p) {
                $method = strtoupper(trim((string)$p['method']));
                $paid = psAccountingParseMoney($p['amount_paid']);
                $change = psAccountingParseMoney($p['change_amount']);
                if ($method === '' || $paid === null || $change === null) {
                    continue;
                }
                $net = psAccountingMoneySub($paid, $change);
                if ((float)$net <= 0) {
                    continue;
                }
                $validPays[] = ['method' => $method, 'net' => $net, 'net_cents' => (int)round(((float)$net) * 100)];
            }
            $grossCents = 0;
            foreach ($validPays as $vp) {
                $grossCents += $vp['net_cents'];
            }
            if ($validPays === [] || $grossCents < 1) {
                $exceptions[] = 'MAPPING_OR_BUILD';
            }
            foreach ($validPays as $idx => $vp) {
                $isLast = ($idx === count($validPays) - 1);
                $taxShareCents = $isLast
                    ? max(0, $taxTotalCents - $allocatedTaxCents)
                    : (int)round($taxTotalCents * $vp['net_cents'] / $grossCents);
                if (!$isLast) {
                    $allocatedTaxCents += $taxShareCents;
                }
                $taxShare = psMoneyFromCents(max(0, $taxShareCents));
                $revShare = psAccountingMoneySub($vp['net'], $taxShare);
                if ((float)$revShare > 0) {
                    $pair = psAccountingBuildPair(
                        $maps,
                        'tender.' . $vp['method'],
                        'revenue.sales',
                        $revShare,
                        [
                            'source_type' => PS_ACCT_SRC_SALE,
                            'source_id' => $sourceId,
                            'doc_number' => $invoice,
                            'txn_date' => (string)$row['txn_date'],
                            'branch' => $bMeta['branch'],
                            'currency' => $currency,
                            'payment_method' => $vp['method'],
                            'memo' => 'Sale ' . $invoice . ' (excl tax)',
                        ]
                    );
                    if (empty($pair['ok'])) {
                        if (!empty($pair['missing'])) {
                            $missingKeys = array_merge($missingKeys, $pair['missing']);
                        }
                        $exceptions[] = 'MAPPING_OR_BUILD';
                    } else {
                        $entries[] = $pair['entry'];
                    }
                }
                if ((float)$taxShare > 0) {
                    $taxPair = psAccountingBuildPair(
                        $maps,
                        'tender.' . $vp['method'],
                        'tax.output',
                        $taxShare,
                        [
                            'source_type' => PS_ACCT_SRC_SALE,
                            'source_id' => $sourceId,
                            'doc_number' => $invoice,
                            'txn_date' => (string)$row['txn_date'],
                            'branch' => $bMeta['branch'],
                            'currency' => $currency,
                            'payment_method' => $vp['method'],
                            'memo' => 'Sale ' . $invoice . ' output tax',
                        ]
                    );
                    if (empty($taxPair['ok'])) {
                        if (!empty($taxPair['missing'])) {
                            $missingKeys = array_merge($missingKeys, $taxPair['missing']);
                        }
                        $exceptions[] = 'TAX_MAPPING_REQUIRED';
                    } else {
                        $entries[] = $taxPair['entry'];
                    }
                }
            }
        } else {
            // Gross posting (legacy / unconfigured / zero tax COMPLETE / incomplete).
            foreach ($payments as $p) {
                $method = strtoupper(trim((string)$p['method']));
                if ($method === '') {
                    continue;
                }
                $paid = psAccountingParseMoney($p['amount_paid']);
                $change = psAccountingParseMoney($p['change_amount']);
                if ($paid === null || $change === null) {
                    continue;
                }
                $net = psAccountingMoneySub($paid, $change);
                if ((float)$net <= 0) {
                    continue;
                }
                $pair = psAccountingBuildPair(
                    $maps,
                    'tender.' . $method,
                    'revenue.sales',
                    $net,
                    [
                        'source_type' => PS_ACCT_SRC_SALE,
                        'source_id' => $sourceId,
                        'doc_number' => $invoice,
                        'txn_date' => (string)$row['txn_date'],
                        'branch' => $bMeta['branch'],
                        'currency' => $currency,
                        'payment_method' => $method,
                        'memo' => 'Sale ' . $invoice,
                    ]
                );
                if (empty($pair['ok'])) {
                    if (!empty($pair['missing'])) {
                        $missingKeys = array_merge($missingKeys, $pair['missing']);
                    }
                    $exceptions[] = 'MAPPING_OR_BUILD';
                } else {
                    $entries[] = $pair['entry'];
                }
            }
        }
        $eligible = $exceptions === [] && $entries !== [] && $missingKeys === [];
        $items[] = [
            'source_type' => PS_ACCT_SRC_SALE,
            'source_id' => $sourceId,
            'doc_number' => $invoice,
            'txn_date' => (string)$row['txn_date'],
            'branch' => $bMeta['branch'],
            'branch_label' => $bMeta['label'],
            'amount' => $netTotal,
            'currency' => $currency,
            'payment_method' => implode(',', array_unique($methods)),
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
            'tax_data_status' => $taxStatus,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * Legacy empty-status sales (not eligible as modern completed revenue) for recon visibility.
 *
 * @return list<array>
 */
function psAccountingCollectLegacySalesExceptions(mysqli $conn, array $filters): array
{
    $items = [];
    $where = '1=1 AND ' . psReportingSaleLegacyRecordedSql('r');
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendSaleDateFilter(
        $where, $types, $params, $filters['from_date'], $filters['to_date'], 'r'
    );
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'r.branch'
    );
    $sql = "SELECT r.id, r.invoice_number, r.totalz, r.branch, r.status,
                   COALESCE(DATE(r.sold_at), STR_TO_DATE(r.date_, '%Y-%m-%d')) AS txn_date
            FROM online_sales_ref r
            WHERE {$where}
            ORDER BY txn_date ASC, r.id ASC
            LIMIT 500";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $items[] = [
            'source_type' => 'LEGACY_SALE',
            'source_id' => (string)$row['id'],
            'doc_number' => (string)$row['invoice_number'],
            'txn_date' => (string)$row['txn_date'],
            'branch' => $bMeta['branch'],
            'branch_label' => $bMeta['label'],
            'amount' => psAccountingParseMoney($row['totalz']) ?? '0.00',
            'exceptions' => ['LEGACY_EMPTY_STATUS_NOT_COMPLETED'],
            'eligible' => false,
            'note' => 'Legacy recorded sale (empty/other status, no sale_payments) — not modern completed revenue.',
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * @return list<array>
 */
function psAccountingCollectRefunds(mysqli $conn, array $filters, array $maps, bool $includeExported): array
{
    $items = [];
    $where = "rr.status = 'PROCESSED'";
    $types = '';
    $params = [];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= ' AND DATE(rr.processed_at) >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= ' AND DATE(rr.processed_at) <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'rr.branch'
    );
    psEnsureSaleTaxColumns($conn);
    require_once __DIR__ . '/currency.php';
    $sql = "SELECT rr.id, rr.refund_number, rr.invoice_number, rr.branch, rr.processed_amount,
                   rr.processed_tax_amount, rr.processed_at, DATE(rr.processed_at) AS txn_date" . (psFxHasColumn($conn, 'sale_refund_requests', 'refund_method') ? ', rr.refund_method' : '') . "
            FROM sale_refund_requests rr
            WHERE {$where}
            ORDER BY rr.processed_at ASC, rr.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sourceId = (string)$row['id'];
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $exp = psAccountingSourceExportState($conn, PS_ACCT_SRC_REFUND, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        $amount = psAccountingParseMoney($row['processed_amount']);
        if ($amount === null || (float)$amount <= 0) {
            $exceptions[] = 'INVALID_REFUND_AMOUNT';
            $amount = '0.00';
        }
        // Tender from original sale_payments (do not fabricate splits).
        $invoice = (string)$row['invoice_number'];
        $method = '';
        $pst = mysqli_prepare(
            $conn,
            'SELECT method FROM sale_payments WHERE invoice_number = ? ORDER BY id ASC LIMIT 1'
        );
        if ($pst) {
            mysqli_stmt_bind_param($pst, 's', $invoice);
            mysqli_stmt_execute($pst);
            $pres = mysqli_stmt_get_result($pst);
            $prow = $pres ? mysqli_fetch_assoc($pres) : null;
            mysqli_stmt_close($pst);
            if ($prow) {
                $method = strtoupper(trim((string)$prow['method']));
            }
        }
        // 2026-11-05: the method the money actually went back by (chosen on the refund) wins over the sale's.
        $chosenRefund = strtoupper(trim((string)($row['refund_method'] ?? '')));
        if ($chosenRefund !== '') {
            $method = $chosenRefund;
        }
        if ($method === '') {
            $exceptions[] = 'MISSING_ORIGINAL_PAYMENT_METHOD';
        }
        $entries = [];
        $missingKeys = [];
        $refundTax = psAccountingParseMoney($row['processed_tax_amount'] ?? null);
        $splitRefundTax = ($refundTax !== null && (float)$refundTax > 0);
        if ($method !== '' && (float)$amount > 0) {
            if ($splitRefundTax) {
                $revAmt = psAccountingMoneySub($amount, $refundTax);
                if ((float)$revAmt < 0) {
                    $exceptions[] = 'REFUND_TAX_EXCEEDS_AMOUNT';
                } else {
                    if ((float)$revAmt > 0) {
                        $pair = psAccountingBuildPair(
                            $maps,
                            'refund.revenue',
                            'refund.tender.' . $method,
                            $revAmt,
                            [
                                'source_type' => PS_ACCT_SRC_REFUND,
                                'source_id' => $sourceId,
                                'doc_number' => (string)$row['refund_number'],
                                'txn_date' => (string)$row['txn_date'],
                                'branch' => $bMeta['branch'],
                                'currency' => 'USD',
                                'payment_method' => $method,
                                'memo' => 'Refund ' . $row['refund_number'] . ' revenue (excl tax)',
                            ]
                        );
                        if (empty($pair['ok'])) {
                            if (!empty($pair['missing'])) {
                                $missingKeys = array_merge($missingKeys, $pair['missing']);
                            }
                            $exceptions[] = 'MAPPING_OR_BUILD';
                        } else {
                            $entries[] = $pair['entry'];
                        }
                    }
                    // Debit tax.output / Credit refund.tax (or refund.tender) to reverse output tax.
                    $taxPair = psAccountingBuildPair(
                        $maps,
                        'tax.output',
                        'refund.tender.' . $method,
                        $refundTax,
                        [
                            'source_type' => PS_ACCT_SRC_REFUND,
                            'source_id' => $sourceId,
                            'doc_number' => (string)$row['refund_number'],
                            'txn_date' => (string)$row['txn_date'],
                            'branch' => $bMeta['branch'],
                            'currency' => 'USD',
                            'payment_method' => $method,
                            'memo' => 'Refund ' . $row['refund_number'] . ' tax',
                        ]
                    );
                    if (empty($taxPair['ok'])) {
                        if (!empty($taxPair['missing'])) {
                            $missingKeys = array_merge($missingKeys, $taxPair['missing']);
                        }
                        $exceptions[] = 'TAX_MAPPING_REQUIRED';
                    } else {
                        $entries[] = $taxPair['entry'];
                    }
                }
            } else {
                $pair = psAccountingBuildPair(
                    $maps,
                    'refund.revenue',
                    'refund.tender.' . $method,
                    $amount,
                    [
                        'source_type' => PS_ACCT_SRC_REFUND,
                        'source_id' => $sourceId,
                        'doc_number' => (string)$row['refund_number'],
                        'txn_date' => (string)$row['txn_date'],
                        'branch' => $bMeta['branch'],
                        'currency' => 'USD',
                        'payment_method' => $method,
                        'memo' => 'Refund ' . $row['refund_number'] . ' for sale ' . $invoice,
                    ]
                );
                if (empty($pair['ok'])) {
                    if (!empty($pair['missing'])) {
                        $missingKeys = array_merge($missingKeys, $pair['missing']);
                    }
                    $exceptions[] = 'MAPPING_OR_BUILD';
                } else {
                    $entries[] = $pair['entry'];
                }
            }
        }
        $eligible = $exceptions === [] && $entries !== [];
        $items[] = [
            'source_type' => PS_ACCT_SRC_REFUND,
            'source_id' => $sourceId,
            'doc_number' => (string)$row['refund_number'],
            'txn_date' => (string)$row['txn_date'],
            'branch' => $bMeta['branch'],
            'branch_label' => $bMeta['label'],
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => $method,
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * @return list<array>
 */
function psAccountingCollectGrv(mysqli $conn, array $filters, array $maps, bool $includeExported): array
{
    $items = [];
    $where = "ref.grv_number IS NOT NULL AND TRIM(ref.grv_number) <> ''";
    $types = '';
    $params = [];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= " AND STR_TO_DATE(ref.date_, '%Y-%m-%d') >= ?";
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= " AND STR_TO_DATE(ref.date_, '%Y-%m-%d') <= ?";
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'ref.branch'
    );
    $sql = "SELECT ref.id, ref.grv_number, ref.totalz, ref.branch, ref.date_,
                   STR_TO_DATE(ref.date_, '%Y-%m-%d') AS txn_date
            FROM goods_receipts_ref ref
            WHERE {$where}
            ORDER BY txn_date ASC, ref.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sourceId = (string)$row['id'];
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $exp = psAccountingSourceExportState($conn, PS_ACCT_SRC_GRV, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        $txnDate = (string)($row['txn_date'] ?? '');
        if ($txnDate === '') {
            $exceptions[] = 'INVALID_DATE';
        }
        $amount = psAccountingParseMoney($row['totalz']);
        if ($amount === null || (float)$amount <= 0) {
            $exceptions[] = 'INVALID_OR_MISSING_GRV_TOTAL';
            $amount = '0.00';
        }
        $entries = [];
        $missingKeys = [];
        if ((float)$amount > 0 && $txnDate !== '') {
            $pair = psAccountingBuildPair(
                $maps,
                'purchase.inventory',
                'purchase.payable',
                $amount,
                [
                    'source_type' => PS_ACCT_SRC_GRV,
                    'source_id' => $sourceId,
                    'doc_number' => (string)$row['grv_number'],
                    'txn_date' => $txnDate,
                    'branch' => $bMeta['branch'],
                    'currency' => 'USD',
                    'payment_method' => '',
                    'memo' => 'GRV ' . $row['grv_number'],
                ]
            );
            if (empty($pair['ok'])) {
                if (!empty($pair['missing'])) {
                    $missingKeys = array_merge($missingKeys, $pair['missing']);
                }
                $exceptions[] = 'MAPPING_OR_BUILD';
            } else {
                $entries[] = $pair['entry'];
            }
        }
        $eligible = $exceptions === [] && $entries !== [];
        $items[] = [
            'source_type' => PS_ACCT_SRC_GRV,
            'source_id' => $sourceId,
            'doc_number' => (string)$row['grv_number'],
            'txn_date' => $txnDate,
            'branch' => $bMeta['branch'],
            'branch_label' => $bMeta['label'],
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => '',
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * @return list<array>
 */
function psAccountingCollectPurchaseReturns(mysqli $conn, array $filters, array $maps, bool $includeExported): array
{
    $items = [];
    $where = "pr.status = 'COMPLETED'";
    $types = '';
    $params = [];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= ' AND pr.return_date >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= ' AND pr.return_date <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'pr.branch'
    );
    $sql = "SELECT pr.id, pr.return_number, pr.branch, pr.total, pr.return_date AS txn_date
            FROM purchase_returns pr
            WHERE {$where}
            ORDER BY pr.return_date ASC, pr.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sourceId = (string)$row['id'];
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $exp = psAccountingSourceExportState($conn, PS_ACCT_SRC_PURCHASE_RETURN, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        $amount = psAccountingParseMoney($row['total']);
        if ($amount === null || (float)$amount <= 0) {
            $exceptions[] = 'INVALID_RETURN_TOTAL';
            $amount = '0.00';
        }
        $entries = [];
        $missingKeys = [];
        if ((float)$amount > 0) {
            $pair = psAccountingBuildPair(
                $maps,
                'purchase_return.payable',
                'purchase_return.inventory',
                $amount,
                [
                    'source_type' => PS_ACCT_SRC_PURCHASE_RETURN,
                    'source_id' => $sourceId,
                    'doc_number' => (string)$row['return_number'],
                    'txn_date' => (string)$row['txn_date'],
                    'branch' => $bMeta['branch'],
                    'currency' => 'USD',
                    'payment_method' => '',
                    'memo' => 'Purchase return ' . $row['return_number'],
                ]
            );
            if (empty($pair['ok'])) {
                if (!empty($pair['missing'])) {
                    $missingKeys = array_merge($missingKeys, $pair['missing']);
                }
                $exceptions[] = 'MAPPING_OR_BUILD';
            } else {
                $entries[] = $pair['entry'];
            }
        }
        $eligible = $exceptions === [] && $entries !== [];
        $items[] = [
            'source_type' => PS_ACCT_SRC_PURCHASE_RETURN,
            'source_id' => $sourceId,
            'doc_number' => (string)$row['return_number'],
            'txn_date' => (string)$row['txn_date'],
            'branch' => $bMeta['branch'],
            'branch_label' => $bMeta['label'],
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => '',
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * Expenses are company-global (no branch column) — admin all-branches only; labelled COMPANY_WIDE.
 *
 * @return list<array>
 */
function psAccountingCollectExpenses(mysqli $conn, array $filters, array $maps, bool $includeExported): array
{
    $items = [];
    if (empty($filters['all_branches']) && !psBranchSessionIsAdmin()) {
        // Ordinary user: expenses not branch-attributable — surface as exception list only when scoped.
        return [[
            'source_type' => PS_ACCT_SRC_EXPENSE,
            'source_id' => '0',
            'doc_number' => '',
            'txn_date' => (string)$filters['from_date'],
            'branch' => '',
            'branch_label' => 'COMPANY_WIDE',
            'amount' => '0.00',
            'currency' => 'USD',
            'payment_method' => '',
            'exceptions' => ['COMPANY_WIDE_REQUIRES_ADMIN_ALL_BRANCHES'],
            'missing_mappings' => [],
            'eligible' => false,
            'exported' => false,
            'export_batch_id' => null,
            'entries' => [],
            'note' => 'Expenses have no branch column; export only under admin all-branches scope.',
        ]];
    }
    if (empty($filters['all_branches'])) {
        return [[
            'source_type' => PS_ACCT_SRC_EXPENSE,
            'source_id' => '0',
            'doc_number' => '',
            'txn_date' => (string)$filters['from_date'],
            'branch' => '',
            'branch_label' => 'COMPANY_WIDE',
            'amount' => '0.00',
            'currency' => 'USD',
            'payment_method' => '',
            'exceptions' => ['COMPANY_WIDE_SELECT_ALL_BRANCHES'],
            'missing_mappings' => [],
            'eligible' => false,
            'exported' => false,
            'export_batch_id' => null,
            'entries' => [],
            'note' => 'Select all branches (admin) to include company-wide expenses.',
        ]];
    }
    $where = '1=1';
    $types = '';
    $params = [];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= " AND STR_TO_DATE(e.transaction_date, '%Y-%m-%d') >= ?";
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= " AND STR_TO_DATE(e.transaction_date, '%Y-%m-%d') <= ?";
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    $sql = "SELECT e.id, e.expense_id, e.expense_name, e.amount, e.transaction_date,
                   STR_TO_DATE(e.transaction_date, '%Y-%m-%d') AS txn_date
            FROM expenses e
            WHERE {$where} AND " . psExpenseCompanySql($conn, 'e') . "
            ORDER BY txn_date ASC, e.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sourceId = (string)$row['id'];
        $exp = psAccountingSourceExportState($conn, PS_ACCT_SRC_EXPENSE, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        $txnDate = (string)($row['txn_date'] ?? '');
        if ($txnDate === '') {
            $exceptions[] = 'INVALID_DATE';
        }
        $amount = psAccountingParseMoney($row['amount']);
        if ($amount === null || (float)$amount <= 0) {
            $exceptions[] = 'INVALID_EXPENSE_AMOUNT';
            $amount = '0.00';
        }
        $entries = [];
        $missingKeys = [];
        if ((float)$amount > 0 && $txnDate !== '') {
            $pair = psAccountingBuildPair(
                $maps,
                'expense.expense',
                'expense.funding',
                $amount,
                [
                    'source_type' => PS_ACCT_SRC_EXPENSE,
                    'source_id' => $sourceId,
                    'doc_number' => (string)$row['expense_id'],
                    'txn_date' => $txnDate,
                    'branch' => '',
                    'currency' => 'USD',
                    'payment_method' => '',
                    'memo' => 'Expense ' . $row['expense_name'] . ' (company-wide)',
                ]
            );
            if (empty($pair['ok'])) {
                if (!empty($pair['missing'])) {
                    $missingKeys = array_merge($missingKeys, $pair['missing']);
                }
                $exceptions[] = 'MAPPING_OR_BUILD';
            } else {
                $entries[] = $pair['entry'];
            }
        }
        $eligible = $exceptions === [] && $entries !== [];
        $items[] = [
            'source_type' => PS_ACCT_SRC_EXPENSE,
            'source_id' => $sourceId,
            'doc_number' => (string)$row['expense_id'],
            'txn_date' => $txnDate,
            'branch' => '',
            'branch_label' => 'COMPANY_WIDE',
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => '',
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * Manual CASH_IN / CASH_OUT only (not CASH_SALE/CASH_REFUND — those follow sale/refund entries).
 *
 * @return list<array>
 */
function psAccountingCollectCashMovements(
    mysqli $conn,
    array $filters,
    array $maps,
    bool $includeExported,
    string $movementType
): array {
    $items = [];
    $sourceType = $movementType === 'CASH_OUT' ? PS_ACCT_SRC_CASH_OUT : PS_ACCT_SRC_CASH_IN;
    $where = 'cm.movement_type = ?';
    $types = 's';
    $params = [$movementType];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= ' AND DATE(cm.movement_at) >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= ' AND DATE(cm.movement_at) <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'cm.branch'
    );
    $sql = "SELECT cm.id, cm.branch, cm.amount, cm.movement_at, cm.reference_id, cm.reason,
                   DATE(cm.movement_at) AS txn_date
            FROM cash_movements cm
            WHERE {$where}
            ORDER BY cm.movement_at ASC, cm.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sourceId = (string)$row['id'];
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $exp = psAccountingSourceExportState($conn, $sourceType, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        $amount = psAccountingParseMoney($row['amount']);
        if ($amount === null || (float)$amount <= 0) {
            $exceptions[] = 'INVALID_CASH_AMOUNT';
            $amount = '0.00';
        }
        $debitKey = $movementType === 'CASH_OUT' ? 'cash_out.contra' : 'cash_in.cash';
        $creditKey = $movementType === 'CASH_OUT' ? 'cash_out.cash' : 'cash_in.contra';
        $entries = [];
        $missingKeys = [];
        if ((float)$amount > 0) {
            $doc = trim((string)($row['reference_id'] ?? ''));
            if ($doc === '') {
                $doc = $movementType . '-' . $sourceId;
            }
            $pair = psAccountingBuildPair(
                $maps,
                $debitKey,
                $creditKey,
                $amount,
                [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'doc_number' => $doc,
                    'txn_date' => (string)$row['txn_date'],
                    'branch' => $bMeta['branch'],
                    'currency' => 'USD',
                    'payment_method' => 'CASH',
                    'memo' => $movementType . ' ' . trim((string)($row['reason'] ?? '')),
                ]
            );
            if (empty($pair['ok'])) {
                if (!empty($pair['missing'])) {
                    $missingKeys = array_merge($missingKeys, $pair['missing']);
                }
                $exceptions[] = 'MAPPING_OR_BUILD';
            } else {
                $entries[] = $pair['entry'];
            }
        }
        $eligible = $exceptions === [] && $entries !== [];
        $items[] = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'doc_number' => trim((string)($row['reference_id'] ?? '')) ?: ($movementType . '-' . $sourceId),
            'txn_date' => (string)$row['txn_date'],
            'branch' => $bMeta['branch'],
            'branch_label' => $bMeta['label'],
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => 'CASH',
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * @return list<array>
 */
function psAccountingCollectAccountPayments(mysqli $conn, array $filters, array $maps, bool $includeExported): array
{
    $items = [];
    // AR payments have no branch on the payment row; attribute via optional sale allocations is unreliable.
    // Export only under admin all-branches, labelled COMPANY_WIDE / UNALLOCATED.
    if (empty($filters['all_branches'])) {
        return [[
            'source_type' => PS_ACCT_SRC_ACCOUNT_PAYMENT,
            'source_id' => '0',
            'doc_number' => '',
            'txn_date' => (string)$filters['from_date'],
            'branch' => '',
            'branch_label' => 'UNALLOCATED',
            'amount' => '0.00',
            'currency' => 'USD',
            'payment_method' => '',
            'exceptions' => ['AR_PAYMENT_REQUIRES_ADMIN_ALL_BRANCHES'],
            'missing_mappings' => [],
            'eligible' => false,
            'exported' => false,
            'export_batch_id' => null,
            'entries' => [],
            'note' => 'Customer account payments have no reliable branch column; include only under admin all-branches.',
        ]];
    }
    $where = "cap.status = 'POSTED'";
    $types = '';
    $params = [];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= ' AND cap.payment_date >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= ' AND cap.payment_date <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    $sql = "SELECT cap.id, cap.payment_number, cap.amount, cap.payment_method, cap.payment_date AS txn_date
            FROM customer_account_payments cap
            WHERE {$where} AND " . psCustomerAccountIdScopeSql($conn, 'cap.customer_account_id') . "
            ORDER BY cap.payment_date ASC, cap.id ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sourceId = (string)$row['id'];
        $exp = psAccountingSourceExportState($conn, PS_ACCT_SRC_ACCOUNT_PAYMENT, $sourceId);
        if ($exp['exported'] && !$includeExported) {
            continue;
        }
        $exceptions = [];
        if ($exp['exported']) {
            $exceptions[] = 'ALREADY_EXPORTED';
        }
        $method = strtoupper(trim((string)$row['payment_method']));
        if ($method === '') {
            $exceptions[] = 'MISSING_PAYMENT_METHOD';
        }
        $amount = psAccountingParseMoney($row['amount']);
        if ($amount === null || (float)$amount <= 0) {
            $exceptions[] = 'INVALID_PAYMENT_AMOUNT';
            $amount = '0.00';
        }
        $entries = [];
        $missingKeys = [];
        if ($method !== '' && (float)$amount > 0) {
            $pair = psAccountingBuildPair(
                $maps,
                'ar.tender.' . $method,
                'ar.receipt',
                $amount,
                [
                    'source_type' => PS_ACCT_SRC_ACCOUNT_PAYMENT,
                    'source_id' => $sourceId,
                    'doc_number' => (string)$row['payment_number'],
                    'txn_date' => (string)$row['txn_date'],
                    'branch' => '',
                    'currency' => 'USD',
                    'payment_method' => $method,
                    'memo' => 'AR payment ' . $row['payment_number'] . ' (unallocated branch)',
                ]
            );
            if (empty($pair['ok'])) {
                if (!empty($pair['missing'])) {
                    $missingKeys = array_merge($missingKeys, $pair['missing']);
                }
                $exceptions[] = 'MAPPING_OR_BUILD';
            } else {
                $entries[] = $pair['entry'];
            }
        }
        $eligible = $exceptions === [] && $entries !== [];
        $items[] = [
            'source_type' => PS_ACCT_SRC_ACCOUNT_PAYMENT,
            'source_id' => $sourceId,
            'doc_number' => (string)$row['payment_number'],
            'txn_date' => (string)$row['txn_date'],
            'branch' => '',
            'branch_label' => 'UNALLOCATED',
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => $method,
            'exceptions' => array_values(array_unique($exceptions)),
            'missing_mappings' => array_values(array_unique($missingKeys)),
            'eligible' => $eligible,
            'exported' => $exp['exported'],
            'export_batch_id' => $exp['batch_id'],
            'entries' => $entries,
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * Inventory adjustments: stock_movements has no unit cost — cannot establish accounting treatment.
 *
 * @return list<array>
 */
function psAccountingCollectInventoryAdjustmentExceptions(mysqli $conn, array $filters): array
{
    $items = [];
    $where = "sm.movement_type IN ('ADJUSTMENT','ADJUSTMENT_IN','ADJUSTMENT_OUT','STOCK_ADJUSTMENT','WRITE_OFF')";
    $types = '';
    $params = [];
    if ($filters['from_date'] !== null && $filters['from_date'] !== '') {
        $where .= ' AND DATE(sm.movement_at) >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null && $filters['to_date'] !== '') {
        $where .= ' AND DATE(sm.movement_at) <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $filters['branches'], !empty($filters['all_branches']), 'sm.branch'
    );
    $sql = "SELECT sm.id, sm.product_id, sm.branch, sm.movement_type, sm.quantity, sm.movement_at,
                   DATE(sm.movement_at) AS txn_date
            FROM stock_movements sm
            WHERE {$where}
            ORDER BY sm.movement_at ASC
            LIMIT 200";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $items;
    }
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $items;
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $bMeta = psAccountingBranchMeta((string)$row['branch'], $filters);
        if (!$bMeta['in_scope']) {
            continue;
        }
        $items[] = [
            'source_type' => 'INVENTORY_ADJUSTMENT',
            'source_id' => (string)$row['id'],
            'doc_number' => (string)$row['movement_type'] . ':' . $row['product_id'],
            'txn_date' => (string)$row['txn_date'],
            'branch' => $bMeta['branch'],
            'exceptions' => ['COST_NOT_ESTABLISHABLE_ON_MOVEMENT'],
            'eligible' => false,
            'note' => 'stock_movements has no cost column; sell price / product name not used.',
        ];
    }
    mysqli_stmt_close($stmt);
    return $items;
}

/**
 * Full preview package.
 *
 * @return array{ok:bool,error?:string,filters?:array,sources?:list,entries?:list,exceptions?:list,totals?:array,balanced?:bool,can_export?:bool,missing_mappings?:list}
 */
function psAccountingBuildPreview(mysqli $conn, array $rawFilters): array
{
    if (!psEnsureAccountingModule($conn)) {
        return ['ok' => false, 'error' => 'Accounting storage is not available.'];
    }
    $norm = psAccountingNormalizeFilters($conn, $rawFilters);
    if (empty($norm['ok'])) {
        return $norm;
    }
    $filters = $norm['filters'];
    $maps = psAccountingLoadMappings($conn);
    $includeExported = !empty($filters['include_exported']);
    $types = $filters['source_types'];

    $sources = [];
    $exceptions = [];

    if (in_array(PS_ACCT_SRC_SALE, $types, true)) {
        $sources = array_merge($sources, psAccountingCollectSales($conn, $filters, $maps, $includeExported));
        $exceptions = array_merge($exceptions, psAccountingCollectLegacySalesExceptions($conn, $filters));
    }
    if (in_array(PS_ACCT_SRC_REFUND, $types, true)) {
        $sources = array_merge($sources, psAccountingCollectRefunds($conn, $filters, $maps, $includeExported));
    }
    if (in_array(PS_ACCT_SRC_GRV, $types, true)) {
        $sources = array_merge($sources, psAccountingCollectGrv($conn, $filters, $maps, $includeExported));
    }
    if (in_array(PS_ACCT_SRC_PURCHASE_RETURN, $types, true)) {
        $sources = array_merge($sources, psAccountingCollectPurchaseReturns($conn, $filters, $maps, $includeExported));
    }
    if (in_array(PS_ACCT_SRC_EXPENSE, $types, true)) {
        $sources = array_merge($sources, psAccountingCollectExpenses($conn, $filters, $maps, $includeExported));
    }
    if (in_array(PS_ACCT_SRC_CASH_IN, $types, true)) {
        $sources = array_merge(
            $sources,
            psAccountingCollectCashMovements($conn, $filters, $maps, $includeExported, 'CASH_IN')
        );
    }
    if (in_array(PS_ACCT_SRC_CASH_OUT, $types, true)) {
        $sources = array_merge(
            $sources,
            psAccountingCollectCashMovements($conn, $filters, $maps, $includeExported, 'CASH_OUT')
        );
    }
    if (in_array(PS_ACCT_SRC_ACCOUNT_PAYMENT, $types, true)) {
        $sources = array_merge($sources, psAccountingCollectAccountPayments($conn, $filters, $maps, $includeExported));
    }
    $exceptions = array_merge($exceptions, psAccountingCollectInventoryAdjustmentExceptions($conn, $filters));
    $sources = psAccountingApplyFx($conn, $sources);

    $entries = [];
    $eligibleSources = [];
    $blocked = [];
    $missingAll = [];
    $debit = '0.00';
    $credit = '0.00';

    foreach ($sources as $src) {
        if (!empty($src['missing_mappings'])) {
            $missingAll = array_merge($missingAll, $src['missing_mappings']);
        }
        if (!empty($src['eligible']) && empty($src['exported'])) {
            $eligibleSources[] = $src;
            foreach ($src['entries'] as $e) {
                $entries[] = $e;
                $debit = psAccountingMoneyAdd($debit, $e['amount']);
                $credit = psAccountingMoneyAdd($credit, $e['amount']);
            }
        } else {
            if (!empty($src['source_id']) && (string)$src['source_id'] !== '0') {
                $blocked[] = $src;
            } elseif (!empty($src['exceptions'])) {
                $exceptions[] = $src;
            }
        }
    }

    $balanced = psAccountingMoneyFormat($debit) === psAccountingMoneyFormat($credit);
    $missingAll = array_values(array_unique($missingAll));
    $canExport = $balanced && $entries !== [] && $missingAll === [] && psAccountingMayExport();

    return [
        'ok' => true,
        'filters' => $filters,
        'sources' => $sources,
        'eligible_sources' => $eligibleSources,
        'blocked_sources' => $blocked,
        'entries' => $entries,
        'exceptions' => $exceptions,
        'totals' => [
            'debit' => $debit,
            'credit' => $credit,
            'eligible_count' => count($eligibleSources),
            'entry_count' => count($entries),
            'blocked_count' => count($blocked),
            'exception_count' => count($exceptions),
        ],
        'balanced' => $balanced,
        'can_export' => $canExport,
        'missing_mappings' => $missingAll,
        'status_note' => 'PREVIEW only — not exported; external_status remains NOT_SENT until a real external integration confirms.',
    ];
}

function psAccountingAllocateBatchNumber(mysqli $conn): array
{
    if (!psEnsureAccountingSeqSchema($conn)) {
        return ['ok' => false, 'error' => 'Batch sequence unavailable.'];
    }
    $res = mysqli_query($conn, 'SELECT next_number FROM accounting_export_seq WHERE id = 1 FOR UPDATE');
    if (!$res) {
        return ['ok' => false, 'error' => 'Batch sequence unavailable.'];
    }
    $row = mysqli_fetch_assoc($res);
    if (!$row) {
        return ['ok' => false, 'error' => 'Batch sequence unavailable.'];
    }
    $next = (int)$row['next_number'];
    if ($next < 1) {
        $next = 1;
    }
    $upd = mysqli_query($conn, 'UPDATE accounting_export_seq SET next_number = ' . ($next + 1) . ' WHERE id = 1');
    if (!$upd) {
        return ['ok' => false, 'error' => 'Batch sequence unavailable.'];
    }
    return ['ok' => true, 'batch_number' => 'AE-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT)];
}

/**
 * Create EXPORTED batch from same filters as preview. Full txn; rollback on any failure.
 *
 * @return array{ok:bool,batch_id?:int,batch_number?:string,error?:string}
 */
function psAccountingCreateExportBatch(mysqli $conn, array $rawFilters, string $user): array
{
    if (!psAccountingMayExport()) {
        return ['ok' => false, 'error' => 'Not authorised to export.'];
    }
    $preview = psAccountingBuildPreview($conn, $rawFilters);
    if (empty($preview['ok'])) {
        return ['ok' => false, 'error' => $preview['error'] ?? 'Preview failed.'];
    }
    if (empty($preview['can_export'])) {
        $msg = 'Export blocked.';
        if (!empty($preview['missing_mappings'])) {
            $msg = 'Configure required mappings first: ' . implode(', ', $preview['missing_mappings']);
        } elseif (empty($preview['entries'])) {
            $msg = 'No eligible balanced entries for these filters.';
        } elseif (empty($preview['balanced'])) {
            $msg = 'Journal preview does not balance.';
        }
        return ['ok' => false, 'error' => $msg];
    }

    $filters = $preview['filters'];
    $user = trim($user) !== '' ? trim($user) : 'unknown';
    $now = date('Y-m-d H:i:s');

    mysqli_begin_transaction($conn);
    try {
        $alloc = psAccountingAllocateBatchNumber($conn);
        if (empty($alloc['ok'])) {
            throw new RuntimeException($alloc['error'] ?? 'Sequence failed');
        }
        $batchNumber = $alloc['batch_number'];
        $branchFilter = !empty($filters['all_branches']) ? '' : (string)$filters['branch'];
        $allBranches = !empty($filters['all_branches']) ? 1 : 0;
        $sourceTypes = implode(',', $filters['source_types']);
        $from = $filters['from_date'];
        $to = $filters['to_date'];
        $status = PS_ACCT_BATCH_EXPORTING;
        $debit = $preview['totals']['debit'];
        $credit = $preview['totals']['credit'];
        $srcCount = (int)$preview['totals']['eligible_count'];
        $entCount = (int)$preview['totals']['entry_count'];

        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO accounting_export_batches
                (batch_number, status, from_date, to_date, branch_filter, all_branches, source_types,
                 created_by, created_at, total_debit, total_credit, source_count, entry_count, external_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'NOT_SENT\')'
        );
        if (!$stmt) {
            throw new RuntimeException('Batch insert prepare failed');
        }
        // s s s s s i s s s s s i i
        mysqli_stmt_bind_param(
            $stmt,
            'sssssisssssii',
            $batchNumber,
            $status,
            $from,
            $to,
            $branchFilter,
            $allBranches,
            $sourceTypes,
            $user,
            $now,
            $debit,
            $credit,
            $srcCount,
            $entCount
        );
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new RuntimeException('Batch insert failed');
        }
        $batchId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        mysqli_query($conn, "UPDATE accounting_export_batches SET company_name = '"
            . mysqli_real_escape_string($conn, psAccountingCompany()) . "' WHERE id = " . $batchId); // 2026-11-02

        // Re-check locks inside txn for each eligible source
        foreach ($preview['eligible_sources'] as $src) {
            $st = (string)$src['source_type'];
            $sid = (string)$src['source_id'];
            $chk = mysqli_prepare(
                $conn,
                'SELECT status FROM accounting_exported_sources
                 WHERE source_type = ? AND source_id = ? FOR UPDATE'
            );
            if (!$chk) {
                throw new RuntimeException('Lock check failed');
            }
            mysqli_stmt_bind_param($chk, 'ss', $st, $sid);
            mysqli_stmt_execute($chk);
            $cres = mysqli_stmt_get_result($chk);
            $crow = $cres ? mysqli_fetch_assoc($cres) : null;
            mysqli_stmt_close($chk);
            if ($crow && strtoupper(trim((string)$crow['status'])) === 'EXPORTED') {
                throw new RuntimeException('Source already exported: ' . $st . '/' . $sid);
            }

            $exFlags = implode(',', $src['exceptions'] ?? []);
            $doc = (string)$src['doc_number'];
            $td = (string)$src['txn_date'];
            $br = (string)$src['branch'];
            $bl = (string)($src['branch_label'] ?? '');
            $amt = (string)$src['amount'];
            $cur = (string)($src['currency'] ?? 'USD');
            $pm = (string)($src['payment_method'] ?? '');
            $bstmt = mysqli_prepare(
                $conn,
                'INSERT INTO accounting_export_batch_sources
                    (batch_id, source_type, source_id, doc_number, txn_date, branch, branch_label,
                     amount, currency, payment_method, exception_flags)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$bstmt) {
                throw new RuntimeException('Source insert failed');
            }
            mysqli_stmt_bind_param(
                $bstmt,
                'issssssssss',
                $batchId,
                $st,
                $sid,
                $doc,
                $td,
                $br,
                $bl,
                $amt,
                $cur,
                $pm,
                $exFlags
            );
            if (!mysqli_stmt_execute($bstmt)) {
                mysqli_stmt_close($bstmt);
                throw new RuntimeException('Source insert execute failed');
            }
            mysqli_stmt_close($bstmt);

            $del = mysqli_prepare(
                $conn,
                'DELETE FROM accounting_exported_sources
                 WHERE source_type = ? AND source_id = ? AND status = \'REVERSED\''
            );
            if ($del) {
                mysqli_stmt_bind_param($del, 'ss', $st, $sid);
                mysqli_stmt_execute($del);
                mysqli_stmt_close($del);
            }
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO accounting_exported_sources
                    (source_type, source_id, batch_id, status, exported_at)
                 VALUES (?, ?, ?, \'EXPORTED\', ?)'
            );
            if (!$ins) {
                throw new RuntimeException('Export lock insert failed');
            }
            mysqli_stmt_bind_param($ins, 'ssis', $st, $sid, $batchId, $now);
            if (!mysqli_stmt_execute($ins)) {
                mysqli_stmt_close($ins);
                throw new RuntimeException('Duplicate export prevented for ' . $st . '/' . $sid);
            }
            mysqli_stmt_close($ins);
        }

        $seq = 0;
        $sumDebit = '0.00';
        $sumCredit = '0.00';
        foreach ($preview['entries'] as $e) {
            $seq++;
            $estmt = mysqli_prepare(
                $conn,
                'INSERT INTO accounting_export_entries
                    (batch_id, entry_seq, source_type, source_id, doc_number, txn_date, branch,
                     debit_mapping_key, credit_mapping_key, debit_account_code, debit_account_name,
                     credit_account_code, credit_account_name, amount, currency, payment_method, memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$estmt) {
                throw new RuntimeException('Entry insert prepare failed');
            }
            $st = (string)$e['source_type'];
            $sid = (string)$e['source_id'];
            $doc = (string)$e['doc_number'];
            $td = (string)$e['txn_date'];
            $br = (string)$e['branch'];
            $dk = (string)$e['debit_mapping_key'];
            $ck = (string)$e['credit_mapping_key'];
            $dc = (string)$e['debit_account_code'];
            $dn = (string)$e['debit_account_name'];
            $cc = (string)$e['credit_account_code'];
            $cn = (string)$e['credit_account_name'];
            $amt = (string)$e['amount'];
            $cur = (string)$e['currency'];
            $pm = (string)$e['payment_method'];
            $memo = (string)$e['memo'];
            mysqli_stmt_bind_param(
                $estmt,
                'iisssssssssssssss',
                $batchId,
                $seq,
                $st,
                $sid,
                $doc,
                $td,
                $br,
                $dk,
                $ck,
                $dc,
                $dn,
                $cc,
                $cn,
                $amt,
                $cur,
                $pm,
                $memo
            );
            if (!mysqli_stmt_execute($estmt)) {
                mysqli_stmt_close($estmt);
                throw new RuntimeException('Entry insert failed');
            }
            mysqli_stmt_close($estmt);
            psAccountingStoreEntryFx($conn, $batchId, $seq, $e);
            $sumDebit = psAccountingMoneyAdd($sumDebit, $amt);
            $sumCredit = psAccountingMoneyAdd($sumCredit, $amt);
        }

        if ($sumDebit !== $sumCredit || $sumDebit !== psAccountingMoneyFormat($debit)) {
            throw new RuntimeException('Batch totals do not balance');
        }

        $exported = PS_ACCT_BATCH_EXPORTED;
        $ustmt = mysqli_prepare(
            $conn,
            'UPDATE accounting_export_batches
             SET status = ?, exported_at = ?, total_debit = ?, total_credit = ?
             WHERE id = ?'
        );
        if (!$ustmt) {
            throw new RuntimeException('Batch finalize failed');
        }
        mysqli_stmt_bind_param($ustmt, 'ssssi', $exported, $now, $sumDebit, $sumCredit, $batchId);
        if (!mysqli_stmt_execute($ustmt)) {
            mysqli_stmt_close($ustmt);
            throw new RuntimeException('Batch finalize execute failed');
        }
        mysqli_stmt_close($ustmt);

        mysqli_commit($conn);
        return [
            'ok' => true,
            'batch_id' => $batchId,
            'batch_number' => $batchNumber,
            'external_status' => 'NOT_SENT',
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('accounting export failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Export failed and was rolled back. No batch was marked exported.'];
    }
}

/**
 * Safe reversal: opposite journal entries in a new REVERSAL batch; unlock sources.
 *
 * @return array{ok:bool,batch_id?:int,batch_number?:string,error?:string}
 */
function psAccountingReverseBatch(mysqli $conn, int $batchId, string $user): array
{
    if (!psAccountingMayConfigure()) {
        return ['ok' => false, 'error' => 'Only an administrator may reverse an export batch.'];
    }
    if (!psEnsureAccountingModule($conn)) {
        return ['ok' => false, 'error' => 'Accounting storage is not available.'];
    }
    $user = trim($user) !== '' ? trim($user) : 'unknown';
    $now = date('Y-m-d H:i:s');

    mysqli_begin_transaction($conn);
    try {
        $bstmt = mysqli_prepare(
            $conn,
            'SELECT * FROM accounting_export_batches WHERE id = ? AND ' . psAccountingBatchCompanySql($conn) . ' FOR UPDATE'
        );
        if (!$bstmt) {
            throw new RuntimeException('Batch lock failed');
        }
        mysqli_stmt_bind_param($bstmt, 'i', $batchId);
        mysqli_stmt_execute($bstmt);
        $bres = mysqli_stmt_get_result($bstmt);
        $batch = $bres ? mysqli_fetch_assoc($bres) : null;
        mysqli_stmt_close($bstmt);
        if (!$batch) {
            throw new RuntimeException('Batch not found');
        }
        if (strtoupper(trim((string)$batch['status'])) !== PS_ACCT_BATCH_EXPORTED) {
            throw new RuntimeException('Only EXPORTED batches can be reversed');
        }

        $alloc = psAccountingAllocateBatchNumber($conn);
        if (empty($alloc['ok'])) {
            throw new RuntimeException('Sequence failed');
        }
        $batchNumber = $alloc['batch_number'];
        $status = PS_ACCT_BATCH_REVERSAL;
        $from = $batch['from_date'];
        $to = $batch['to_date'];
        $branchFilter = (string)$batch['branch_filter'];
        $allBranches = (int)$batch['all_branches'];
        $sourceTypes = (string)$batch['source_types'];
        $debit = (string)$batch['total_debit'];
        $credit = (string)$batch['total_credit'];
        $srcCount = (int)$batch['source_count'];
        $entCount = (int)$batch['entry_count'];
        $notes = 'Reversal of ' . $batch['batch_number'];

        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO accounting_export_batches
                (batch_number, status, from_date, to_date, branch_filter, all_branches, source_types,
                 created_by, created_at, exported_at, reversal_of_batch_id,
                 total_debit, total_credit, source_count, entry_count, notes, external_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'NOT_SENT\')'
        );
        if (!$ins) {
            throw new RuntimeException('Reversal batch insert failed');
        }
        // batch_number s, status s, from s, to s, branch s, all i, types s, user s, created s,
        // exported s, reversal_of i, debit s, credit s, src i, ent i, notes s
        mysqli_stmt_bind_param(
            $ins,
            'sssssissssissiis',
            $batchNumber,
            $status,
            $from,
            $to,
            $branchFilter,
            $allBranches,
            $sourceTypes,
            $user,
            $now,
            $now,
            $batchId,
            $debit,
            $credit,
            $srcCount,
            $entCount,
            $notes
        );
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            throw new RuntimeException('Reversal batch execute failed');
        }
        $newId = (int)mysqli_insert_id($conn);
        mysqli_query($conn, "UPDATE accounting_export_batches SET company_name = '"
            . mysqli_real_escape_string($conn, psAccountingCompany()) . "' WHERE id = " . $newId); // 2026-11-02
        mysqli_stmt_close($ins);

        $eres = mysqli_prepare(
            $conn,
            'SELECT * FROM accounting_export_entries WHERE batch_id = ? ORDER BY entry_seq ASC'
        );
        mysqli_stmt_bind_param($eres, 'i', $batchId);
        mysqli_stmt_execute($eres);
        $er = mysqli_stmt_get_result($eres);
        $seq = 0;
        while ($er && ($e = mysqli_fetch_assoc($er))) {
            $seq++;
            // Swap debit/credit for reversal
            $estmt = mysqli_prepare(
                $conn,
                'INSERT INTO accounting_export_entries
                    (batch_id, entry_seq, source_type, source_id, doc_number, txn_date, branch,
                     debit_mapping_key, credit_mapping_key, debit_account_code, debit_account_name,
                     credit_account_code, credit_account_name, amount, currency, payment_method, memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st = (string)$e['source_type'];
            $sid = (string)$e['source_id'];
            $doc = (string)$e['doc_number'];
            $td = (string)$e['txn_date'];
            $br = (string)$e['branch'];
            $dk = (string)$e['credit_mapping_key'];
            $ck = (string)$e['debit_mapping_key'];
            $dc = (string)$e['credit_account_code'];
            $dn = (string)$e['credit_account_name'];
            $cc = (string)$e['debit_account_code'];
            $cn = (string)$e['debit_account_name'];
            $amt = (string)$e['amount'];
            $cur = (string)$e['currency'];
            $pm = (string)$e['payment_method'];
            $memo = 'REVERSAL: ' . (string)$e['memo'];
            mysqli_stmt_bind_param(
                $estmt,
                'iisssssssssssssss',
                $newId,
                $seq,
                $st,
                $sid,
                $doc,
                $td,
                $br,
                $dk,
                $ck,
                $dc,
                $dn,
                $cc,
                $cn,
                $amt,
                $cur,
                $pm,
                $memo
            );
            if (!mysqli_stmt_execute($estmt)) {
                mysqli_stmt_close($estmt);
                throw new RuntimeException('Reversal entry failed');
            }
            mysqli_stmt_close($estmt);
            psAccountingStoreEntryFx($conn, $newId, $seq, $e);
        }
        mysqli_stmt_close($eres);

        $sres = mysqli_prepare(
            $conn,
            'SELECT source_type, source_id FROM accounting_export_batch_sources WHERE batch_id = ?'
        );
        mysqli_stmt_bind_param($sres, 'i', $batchId);
        mysqli_stmt_execute($sres);
        $sr = mysqli_stmt_get_result($sres);
        while ($sr && ($s = mysqli_fetch_assoc($sr))) {
            $st = (string)$s['source_type'];
            $sid = (string)$s['source_id'];
            $ust = mysqli_prepare(
                $conn,
                'UPDATE accounting_exported_sources
                 SET status = \'REVERSED\', reversed_at = ?, reversed_by_batch_id = ?
                 WHERE source_type = ? AND source_id = ? AND batch_id = ? AND status = \'EXPORTED\''
            );
            mysqli_stmt_bind_param($ust, 'sissi', $now, $newId, $st, $sid, $batchId);
            if (!mysqli_stmt_execute($ust)) {
                mysqli_stmt_close($ust);
                throw new RuntimeException('Unlock source failed');
            }
            mysqli_stmt_close($ust);

            // Copy source row to reversal batch for drill-down
            $cs = mysqli_prepare(
                $conn,
                'INSERT INTO accounting_export_batch_sources
                    (batch_id, source_type, source_id, doc_number, txn_date, branch, branch_label,
                     amount, currency, payment_method, exception_flags)
                 SELECT ?, source_type, source_id, doc_number, txn_date, branch, branch_label,
                        amount, currency, payment_method, \'REVERSAL\'
                 FROM accounting_export_batch_sources
                 WHERE batch_id = ? AND source_type = ? AND source_id = ?'
            );
            mysqli_stmt_bind_param($cs, 'iiss', $newId, $batchId, $st, $sid);
            mysqli_stmt_execute($cs);
            mysqli_stmt_close($cs);
        }
        mysqli_stmt_close($sres);

        $revStatus = PS_ACCT_BATCH_REVERSED;
        $ub = mysqli_prepare(
            $conn,
            'UPDATE accounting_export_batches SET status = ?, reversed_at = ? WHERE id = ?'
        );
        mysqli_stmt_bind_param($ub, 'ssi', $revStatus, $now, $batchId);
        if (!mysqli_stmt_execute($ub)) {
            mysqli_stmt_close($ub);
            throw new RuntimeException('Mark reversed failed');
        }
        mysqli_stmt_close($ub);

        mysqli_commit($conn);
        return ['ok' => true, 'batch_id' => $newId, 'batch_number' => $batchNumber];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('accounting reverse failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Reversal failed and was rolled back.'];
    }
}

/**
 * @return array{ok:bool,batch?:array,sources?:list,entries?:array,error?:string}
 */
function psAccountingGetBatch(mysqli $conn, int $batchId): array
{
    if (!psEnsureAccountingModule($conn)) {
        return ['ok' => false, 'error' => 'Accounting storage is not available.'];
    }
    if (!psAccountingMayView()) {
        return ['ok' => false, 'error' => 'Please sign in.'];
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM accounting_export_batches WHERE id = ? AND ' . psAccountingBatchCompanySql($conn) . ' LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $batchId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $batch = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$batch) {
        return ['ok' => false, 'error' => 'Batch not found.'];
    }

    // Branch ACL: ordinary users only see batches for their branch (or all_branches with their home in filter — we store single branch).
    if (!psBranchSessionIsAdmin()) {
        $home = psBranchHomeName();
        $bf = psBranchNormalizeName($batch['branch_filter'] ?? '');
        if ((int)$batch['all_branches'] === 1 || $bf === '' || $bf !== $home) {
            // Allow if batch is specifically their home branch
            if ($bf !== $home) {
                return ['ok' => false, 'error' => 'That batch is not available for your branch.'];
            }
        }
    }

    $sources = [];
    $sst = mysqli_prepare($conn, 'SELECT * FROM accounting_export_batch_sources WHERE batch_id = ? ORDER BY id ASC');
    mysqli_stmt_bind_param($sst, 'i', $batchId);
    mysqli_stmt_execute($sst);
    $sr = mysqli_stmt_get_result($sst);
    while ($sr && ($row = mysqli_fetch_assoc($sr))) {
        $sources[] = $row;
    }
    mysqli_stmt_close($sst);

    $entries = [];
    $est = mysqli_prepare($conn, 'SELECT * FROM accounting_export_entries WHERE batch_id = ? ORDER BY entry_seq ASC');
    mysqli_stmt_bind_param($est, 'i', $batchId);
    mysqli_stmt_execute($est);
    $er = mysqli_stmt_get_result($est);
    while ($er && ($row = mysqli_fetch_assoc($er))) {
        $entries[] = $row;
    }
    mysqli_stmt_close($est);

    return ['ok' => true, 'batch' => $batch, 'sources' => $sources, 'entries' => $entries];
}

/**
 * @return list<array>
 */
function psAccountingListBatches(mysqli $conn, int $limit = 50): array
{
    if (!psEnsureAccountingModule($conn) || !psAccountingMayView()) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $rows = [];
    if (psBranchSessionIsAdmin()) {
        $sql = 'SELECT * FROM accounting_export_batches WHERE ' . psAccountingBatchCompanySql($conn) . " ORDER BY id DESC LIMIT {$limit}";
        $res = mysqli_query($conn, $sql);
    } else {
        $home = psBranchHomeName();
        $stmt = mysqli_prepare(
            $conn,
            "SELECT * FROM accounting_export_batches
             WHERE all_branches = 0 AND branch_filter = ? AND " . psAccountingBatchCompanySql($conn) . "
             ORDER BY id DESC LIMIT {$limit}"
        );
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 's', $home);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    }
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    if (!psBranchSessionIsAdmin() && isset($stmt)) {
        mysqli_stmt_close($stmt);
    }
    return $rows;
}

/**
 * CSV for a batch (documented format). Formula-escaped.
 *
 * @return array{ok:bool,headers?:list,rows?:list,filename?:string,error?:string}
 */
function psAccountingBatchCsv(mysqli $conn, int $batchId): array
{
    $detail = psAccountingGetBatch($conn, $batchId);
    if (empty($detail['ok'])) {
        return $detail;
    }
    $batch = $detail['batch'];
    $headers = [
        'batch_number', 'batch_status', 'external_status', 'entry_seq',
        'source_type', 'source_id', 'doc_number', 'txn_date', 'branch',
        'debit_account_code', 'debit_account_name', 'credit_account_code', 'credit_account_name',
        'amount', 'currency', 'payment_method',
        'debit_mapping_key', 'credit_mapping_key', 'memo',
        'txn_currency', 'txn_amount', 'fx_rate',
    ];
    $rows = [];
    foreach ($detail['entries'] as $e) {
        $rows[] = [
            $batch['batch_number'],
            $batch['status'],
            $batch['external_status'],
            $e['entry_seq'],
            $e['source_type'],
            $e['source_id'],
            $e['doc_number'],
            $e['txn_date'],
            $e['branch'],
            $e['debit_account_code'],
            $e['debit_account_name'],
            $e['credit_account_code'],
            $e['credit_account_name'],
            $e['amount'],
            $e['currency'],
            $e['payment_method'],
            $e['debit_mapping_key'],
            $e['credit_mapping_key'],
            $e['memo'],
            (string)($e['txn_currency'] ?? '') !== '' ? $e['txn_currency'] : $e['currency'],
            (string)($e['txn_amount'] ?? '') !== '' ? $e['txn_amount'] : $e['amount'],
            (string)($e['fx_rate'] ?? '') !== '' ? rtrim(rtrim((string)$e['fx_rate'], '0'), '.') : '1',
        ];
    }
    return [
        'ok' => true,
        'headers' => $headers,
        'rows' => $rows,
        'filename' => 'accounting_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$batch['batch_number']) . '.csv',
    ];
}

/**
 * Reconciliation: POS eligible/source totals vs exported batch totals for filters.
 *
 * @return array{ok:bool,...}
 */
function psAccountingReconcile(mysqli $conn, array $rawFilters): array
{
    $preview = psAccountingBuildPreview($conn, array_merge($rawFilters, ['include_exported' => true]));
    if (empty($preview['ok'])) {
        return $preview;
    }
    $filters = $preview['filters'];

    // Sum currently EXPORTED sources overlapping filter window (by batch dates / source txn in batch_sources)
    $exportedDebit = '0.00';
    $exportedCount = 0;
    $where = 'b.status IN (\'EXPORTED\')';
    $types = '';
    $params = [];
    if ($filters['from_date'] !== '') {
        $where .= ' AND s.txn_date >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if ($filters['to_date'] !== '') {
        $where .= ' AND s.txn_date <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }
    if (!empty($filters['all_branches'])) {
        [$where, $types, $params] = psReportingAppendBranchFilter(
            $where, $types, $params, $filters['branches'], true, 's.branch'
        );
        // Also include company-wide blank branch rows
        // Rewrite: (branch IN (...) OR branch = '')
        // Simpler post-filter in PHP below.
    } else {
        $where .= ' AND s.branch = ?';
        $types .= 's';
        $params[] = $filters['branch'];
    }

    $sql = "SELECT s.source_type, s.source_id, s.amount, s.branch, e.amount AS entry_amount
            FROM accounting_export_batch_sources s
            INNER JOIN accounting_export_batches b ON b.id = s.batch_id
            INNER JOIN accounting_export_entries e ON e.batch_id = s.batch_id
                AND e.source_type = s.source_type AND e.source_id = s.source_id
            WHERE {$where} AND " . psAccountingBatchCompanySql($conn, 'b');
    $stmt = mysqli_prepare($conn, $sql);
    $bySource = [];
    if ($stmt) {
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $br = psBranchNormalizeName($row['branch'] ?? '');
                if (!empty($filters['all_branches'])) {
                    if ($br !== '' && !psBranchNameInList($br, $filters['branches'])) {
                        continue;
                    }
                }
                $key = $row['source_type'] . ':' . $row['source_id'];
                if (!isset($bySource[$key])) {
                    $bySource[$key] = '0.00';
                    $exportedCount++;
                }
                $bySource[$key] = psAccountingMoneyAdd($bySource[$key], (string)$row['entry_amount']);
            }
        }
        mysqli_stmt_close($stmt);
    }
    foreach ($bySource as $amt) {
        $exportedDebit = psAccountingMoneyAdd($exportedDebit, $amt);
    }

    // Preview totals for eligible+already exported (include_exported true) entry amounts
    $posDebit = $preview['totals']['debit'];
    // When include_exported, eligible empty for exported; recompute entry sum from all sources with entries
    $posAll = '0.00';
    $posEligibleOpen = '0.00';
    foreach ($preview['sources'] as $src) {
        foreach ($src['entries'] ?? [] as $e) {
            $posAll = psAccountingMoneyAdd($posAll, $e['amount']);
            if (empty($src['exported'])) {
                $posEligibleOpen = psAccountingMoneyAdd($posEligibleOpen, $e['amount']);
            }
        }
    }

    return [
        'ok' => true,
        'filters' => $filters,
        'pos_buildable_entries_total' => $posAll,
        'pos_open_eligible_total' => $posEligibleOpen,
        'exported_entries_total' => $exportedDebit,
        'exported_source_count' => $exportedCount,
        'preview_totals' => $preview['totals'],
        'exception_count' => count($preview['exceptions']),
        'legacy_and_blocked' => array_merge($preview['exceptions'], $preview['blocked_sources']),
        'notes' => [
            'POS open eligible = sources that would export now (mappings ok, not yet EXPORTED).',
            'Exported total = sum of journal entry amounts on EXPORTED batches for sources in date/branch scope.',
            'Legacy empty-status sales are exceptions, not completed revenue.',
            'external_status NOT_SENT means CSV export only — not confirmed in an external accounting system.',
            'Genuine discrepancies: buildable vs exported mismatches for the same source set.',
            'Known legacy limits: empty-status sales, orphan branches, expenses/AR without branch, inventory adjustments without cost.',
        ],
    ];
}

/**
 * @return list<string>
 */
function psAccountingKnownLimitations(): array
{
    return [
        'No external accounting platform credentials or API — CSV export is accounting-ready only; external_status stays NOT_SENT until a real integration confirms.',
        'COMPLETE tax_data_status sales split revenue.sales (excl tax) + tax.output when tax.output is mapped; otherwise TAX_MAPPING_REQUIRED blocks export.',
        'LEGACY_INCOMPLETE / UNCONFIGURED sales export gross to revenue.sales (no fabricated tax). Historical vat/vat_state columns are not used.',
        'Modern completed sales only (COMPLETED/REFUNDED or sale_payments). Legacy empty-status sales are exceptions, not revenue.',
        'PROCESSED refunds only; REQUESTED/APPROVED/REJECTED are not exported.',
        'CASH_SALE / CASH_REFUND cash movements are not double-exported (covered by SALE/REFUND entries).',
        'Expenses and AR payments have no reliable branch — company-wide / unallocated; admin all-branches only.',
        'Inventory adjustments are not posted: stock_movements has no cost; sell price/name never used.',
        'Orphan/NULL till branches are preserved and excluded from authorised-branch export scope.',
        'App-wide CSRF is not claimed. Browser / external accounting testing is not claimed unless performed.',
    ];
}
