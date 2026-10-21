<?php
/**
 * Phase 17 — ZIMRA FDMS fiscalisation (core).
 *
 * One ZIMRA fiscal device per branch (and environment: test | production). This file holds:
 *   - schema (psEnsureZimraModule), roles, audit
 *   - secret storage (device private keys encrypted with a master key kept in pos-private)
 *   - key / CSR generation and signing
 *   - the pure FDMS rules: receipt build, receipt + fiscal-day signature strings, counters, QR
 *   - the HTTP client (mutual TLS) and device set-up (register / import / GetConfig / renew)
 * Fiscal days, receipts, credit notes and printing live in includes/zimra_receipts.php.
 *
 * Network calls never run inside a sale / refund DB transaction: receipts are numbered and
 * signed inside the caller's transaction, then sent after commit (psZimraAfterCommit) or by
 * cli_zimra_worker.php. Receipts are never edited after signing.
 */

require_once __DIR__ . '/ps_config_bootstrap.php';

if (!defined('PS_ZIMRA_LOADED')) {
    define('PS_ZIMRA_LOADED', 1);
}

const PS_ZIMRA_URL_TEST = 'https://fdmsapitest.zimra.co.zw';
const PS_ZIMRA_URL_PRODUCTION = 'https://fdmsapi.zimra.co.zw';
const PS_ZIMRA_QR_TEST = 'https://fdmstest.zimra.co.zw';
const PS_ZIMRA_DATE_FORMAT = 'Y-m-d\TH:i:s';

const PS_ZIMRA_RECEIPT_TYPES = ['FiscalInvoice', 'CreditNote', 'DebitNote'];
/** FDMS enum order — counters are sorted by these indexes. */
const PS_ZIMRA_COUNTER_TYPES = [
    'SaleByTax', 'SaleTaxByTax', 'CreditNoteByTax', 'CreditNoteTaxByTax',
    'DebitNoteByTax', 'DebitNoteTaxByTax', 'BalanceByMoneyType',
];
const PS_ZIMRA_MONEY_TYPES = ['Cash', 'Card', 'MobileWallet', 'Coupon', 'Credit', 'BankTransfer', 'Other'];

/** POS sale_payments.method → FDMS moneyTypeCode. */
function psZimraMoneyTypeForMethod(string $method): string
{
    $map = [
        'CASH' => 'Cash',
        'CARD' => 'Card',
        'ECOCASH' => 'MobileWallet',
        'BANK_TRANSFER' => 'BankTransfer',
        'CREDIT' => 'Credit',
        'OTHER' => 'Other',
    ];
    return $map[strtoupper(trim($method))] ?? 'Other';
}

// ---------------------------------------------------------------------------
// Roles (case-insensitive: 'admin' == 'Admin')
// ---------------------------------------------------------------------------

function psZimraSessionLevel(): string
{
    return strtolower(trim((string)($_SESSION['level'] ?? '')));
}

function psZimraSessionUser(): string
{
    return trim((string)($_SESSION['logger'] ?? ''));
}

/** Device set-up, keys, mappings, enable/disable: system administrator only. */
function psZimraMaySetup(): bool
{
    return psZimraSessionUser() !== '' && psZimraSessionLevel() === 'admin';
}

/** Open / close fiscal day, retry sending: Supervisor, Management, admin. */
function psZimraMayManageDay(): bool
{
    return psZimraSessionUser() !== ''
        && in_array(psZimraSessionLevel(), ['admin', 'supervisor', 'management'], true);
}

/** Fiscal receipts / days reports: Management and admin (Supervisor sees the day page only). */
function psZimraMayViewReports(): bool
{
    return psZimraSessionUser() !== ''
        && in_array(psZimraSessionLevel(), ['admin', 'management'], true);
}

// ---------------------------------------------------------------------------
// Small mysqli helpers
// ---------------------------------------------------------------------------

/**
 * Prepared query. Returns rows for SELECT, ['affected'=>n,'insert_id'=>id] otherwise, null on error.
 * @param list<mixed> $params
 */
function psZimraQuery(mysqli $conn, string $sql, string $types = '', array $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('zimra: prepare failed: ' . mysqli_error($conn));
        return null;
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log('zimra: execute failed: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    if ($res instanceof mysqli_result) {
        $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        return $rows;
    }
    $out = ['affected' => mysqli_stmt_affected_rows($stmt), 'insert_id' => (int)mysqli_stmt_insert_id($stmt)];
    mysqli_stmt_close($stmt);
    return $out;
}

function psZimraOne(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = psZimraQuery($conn, $sql, $types, $params);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

function psEnsureZimraModule(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $ddl = [
        "CREATE TABLE IF NOT EXISTS zimra_devices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch VARCHAR(250) NOT NULL,
            company_name VARCHAR(250) NOT NULL DEFAULT '',
            environment VARCHAR(12) NOT NULL DEFAULT 'test',
            device_id INT UNSIGNED NOT NULL,
            device_serial_no VARCHAR(50) NOT NULL,
            model_name VARCHAR(100) NOT NULL,
            model_version VARCHAR(50) NOT NULL,
            activation_key_enc TEXT NULL,
            private_key_enc TEXT NULL,
            certificate_pem TEXT NULL,
            certificate_valid_till DATETIME NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'NEW',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            operating_mode VARCHAR(20) NULL,
            taxpayer_name VARCHAR(250) NULL,
            taxpayer_tin VARCHAR(20) NULL,
            vat_number VARCHAR(20) NULL,
            fdms_branch_name VARCHAR(250) NULL,
            fdms_branch_address TEXT NULL,
            fdms_branch_contacts TEXT NULL,
            qr_url VARCHAR(250) NULL,
            day_max_hrs SMALLINT UNSIGNED NULL,
            day_end_notification_hrs SMALLINT UNSIGNED NULL,
            config_json MEDIUMTEXT NULL,
            config_synced_at DATETIME NULL,
            last_fiscal_day_no INT UNSIGNED NOT NULL DEFAULT 0,
            last_receipt_global_no INT UNSIGNED NOT NULL DEFAULT 0,
            print_form VARCHAR(12) NOT NULL DEFAULT 'Receipt48',
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_zimra_env_device (environment, device_id),
            UNIQUE KEY uq_zimra_company_branch_env (company_name, branch, environment)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS zimra_taxes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_row_id INT UNSIGNED NOT NULL,
            tax_id INT UNSIGNED NOT NULL,
            tax_code VARCHAR(3) NULL,
            tax_name VARCHAR(100) NOT NULL,
            tax_percent DECIMAL(5,2) NULL,
            valid_from DATETIME NULL,
            valid_till DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_zimra_device_tax (device_row_id, tax_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS zimra_tax_map (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_row_id INT UNSIGNED NOT NULL,
            pos_tax_code VARCHAR(32) NOT NULL,
            tax_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_zimra_tax_map (device_row_id, pos_tax_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS zimra_fiscal_days (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_row_id INT UNSIGNED NOT NULL,
            fiscal_day_no INT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'OPEN',
            open_state VARCHAR(12) NOT NULL DEFAULT 'PENDING',
            opened_at DATETIME NOT NULL,
            opened_by VARCHAR(250) NOT NULL,
            open_error TEXT NULL,
            last_receipt_counter INT UNSIGNED NOT NULL DEFAULT 0,
            last_receipt_hash VARCHAR(100) NULL,
            close_requested_at DATETIME NULL,
            close_requested_by VARCHAR(250) NULL,
            closed_at DATETIME NULL,
            counters_json MEDIUMTEXT NULL,
            close_error TEXT NULL,
            server_signature_json TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_zimra_device_day (device_row_id, fiscal_day_no),
            KEY ix_zimra_day_status (device_row_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS zimra_receipts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_row_id INT UNSIGNED NOT NULL,
            fiscal_day_row_id INT UNSIGNED NOT NULL,
            source_type VARCHAR(12) NOT NULL,
            source_ref VARCHAR(64) NOT NULL,
            invoice_number VARCHAR(64) NOT NULL,
            branch VARCHAR(250) NOT NULL,
            receipt_type VARCHAR(16) NOT NULL,
            receipt_currency CHAR(3) NOT NULL,
            receipt_counter INT UNSIGNED NOT NULL,
            receipt_global_no INT UNSIGNED NOT NULL,
            invoice_no VARCHAR(50) NOT NULL,
            receipt_date DATETIME NOT NULL,
            receipt_total DECIMAL(14,2) NOT NULL,
            receipt_hash VARCHAR(100) NOT NULL,
            receipt_signature TEXT NOT NULL,
            request_json MEDIUMTEXT NOT NULL,
            line_map_json MEDIUMTEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            zimra_receipt_id BIGINT UNSIGNED NULL,
            server_date DATETIME NULL,
            server_signature_json TEXT NULL,
            validation_errors_json TEXT NULL,
            qr_url VARCHAR(250) NOT NULL,
            verification_code CHAR(19) NOT NULL,
            original_receipt_row_id INT UNSIGNED NULL,
            created_by VARCHAR(250) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_zimra_device_global (device_row_id, receipt_global_no),
            UNIQUE KEY uq_zimra_day_counter (fiscal_day_row_id, receipt_counter),
            UNIQUE KEY uq_zimra_source (source_type, source_ref),
            KEY ix_zimra_invoice (invoice_number, branch),
            KEY ix_zimra_receipt_status (device_row_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS zimra_api_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_row_id INT UNSIGNED NULL,
            endpoint VARCHAR(100) NOT NULL,
            http_status SMALLINT NULL,
            operation_id VARCHAR(100) NULL,
            error_code VARCHAR(20) NULL,
            request_body MEDIUMTEXT NULL,
            response_body MEDIUMTEXT NULL,
            duration_ms INT UNSIGNED NULL,
            actor VARCHAR(250) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_zimra_api_device (device_row_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS zimra_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_row_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            actor VARCHAR(250) NOT NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_zimra_audit_device (device_row_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) {
        if (!mysqli_query($conn, $sql)) {
            error_log('zimra: schema failed: ' . mysqli_error($conn));
            return false;
        }
    }
    // Added after first release: receipt logo per device (data URI, max 500 KB).
    $col = mysqli_query($conn, "SHOW COLUMNS FROM zimra_devices LIKE 'receipt_logo'");
    if ($col && mysqli_num_rows($col) === 0 && !mysqli_query($conn, 'ALTER TABLE zimra_devices ADD COLUMN receipt_logo MEDIUMTEXT NULL')) {
        error_log('zimra: receipt_logo column failed: ' . mysqli_error($conn));
        return false;
    }
    // Phase 18: one device per branch *per company* (branch names repeat across companies).
    $old = mysqli_query($conn, "SHOW INDEX FROM zimra_devices WHERE Key_name = 'uq_zimra_branch_env'");
    if ($old && mysqli_num_rows($old) > 0
        && !mysqli_query($conn, 'ALTER TABLE zimra_devices DROP INDEX uq_zimra_branch_env, ADD UNIQUE KEY uq_zimra_company_branch_env (company_name, branch, environment)')) {
        error_log('zimra: device key upgrade failed: ' . mysqli_error($conn));
    }
    $done = true;
    return true;
}

/** Append-only audit of set-up and day actions (no secrets). */
function psZimraAudit(mysqli $conn, ?int $deviceRowId, string $action, string $actor, array $details = []): void
{
    psZimraQuery(
        $conn,
        'INSERT INTO zimra_audit (device_row_id, action, actor, details) VALUES (?,?,?,?)',
        'isss',
        [$deviceRowId, $action, $actor, $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null]
    );
}

// ---------------------------------------------------------------------------
// Secrets: device private keys and activation keys are encrypted at rest
// ---------------------------------------------------------------------------

/** Folder for ZIMRA files outside the webroot (master key). */
function psZimraPrivateDir(): string
{
    return psConfigPrivateRoot() . DIRECTORY_SEPARATOR . 'zimra';
}

/** Master key length (bytes) — same for both ciphers below. */
const PS_ZIMRA_KEY_BYTES = 32;

/**
 * 32-byte master key: private config 'zimra_master_key' (base64) / env PS_ZIMRA_MASTER_KEY,
 * else pos-private/zimra/master.key (created on first use; an empty/corrupt file left by a failed
 * first run is replaced). Losing it means every device must be re-registered — back it up.
 */
function psZimraMasterKey(): ?string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $configured = trim(psConfigValue('zimra_master_key', ''));
    if ($configured !== '') {
        $raw = base64_decode($configured, true);
        if ($raw !== false && strlen($raw) === PS_ZIMRA_KEY_BYTES) {
            return $key = $raw;
        }
        error_log('zimra: zimra_master_key is not 32 bytes base64');
        return null;
    }
    $dir = psZimraPrivateDir();
    $file = $dir . DIRECTORY_SEPARATOR . 'master.key';
    $current = is_file($file) ? trim((string)@file_get_contents($file)) : '';
    if ($current === '') {
        // Never overwrite a non-empty key file: only create, or replace an empty one.
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('zimra: cannot create private zimra folder');
            return null;
        }
        $fresh = base64_encode(random_bytes(PS_ZIMRA_KEY_BYTES));
        if (@file_put_contents($file, $fresh, LOCK_EX) === false) {
            error_log('zimra: cannot write master key file');
            return null;
        }
        @chmod($file, 0600);
        $current = $fresh;
    }
    $raw = base64_decode($current, true);
    if ($raw === false || strlen($raw) !== PS_ZIMRA_KEY_BYTES) {
        error_log('zimra: master key file is invalid');
        return null;
    }
    return $key = $raw;
}

/**
 * Encrypt a secret with OpenSSL AES-256-GCM ('v2:'). OpenSSL is already required for ZIMRA signing,
 * so no extra PHP extension is needed. 'v1:' (libsodium) values are still readable when sodium is loaded.
 */
function psZimraEncrypt(string $plain): ?string
{
    $key = psZimraMasterKey();
    if ($key === null) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        error_log('zimra: encryption failed');
        return null;
    }
    return 'v2:' . base64_encode($iv . $tag . $cipher);
}

function psZimraDecrypt(?string $stored): ?string
{
    $key = psZimraMasterKey();
    if ($key === null || $stored === null) {
        return null;
    }
    $version = substr($stored, 0, 3);
    $bin = base64_decode(substr($stored, 3), true);
    if ($bin === false) {
        return null;
    }
    if ($version === 'v1:') {
        if (!function_exists('sodium_crypto_secretbox_open') || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            error_log('zimra: secret was encrypted with sodium but the sodium extension is not loaded');
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
        return $plain === false ? null : $plain;
    }
    if ($version === 'v2:') {
        if (strlen($bin) <= 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $plain === false ? null : $plain;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Keys, CSR, signing
// ---------------------------------------------------------------------------

function psZimraOpensslOptions(): array
{
    return ['config' => __DIR__ . DIRECTORY_SEPARATOR . 'zimra_openssl.cnf'];
}

/** CN required by ZIMRA: ZIMRA-<serialNo>-<deviceId zero padded to 10>. */
function psZimraCommonName(string $serialNo, int $deviceId): string
{
    return 'ZIMRA-' . trim($serialNo) . '-' . str_pad((string)$deviceId, 10, '0', STR_PAD_LEFT);
}

function psZimraOpensslErrors(): string
{
    $e = [];
    while ($m = openssl_error_string()) {
        $e[] = $m;
    }
    return implode('; ', $e);
}

/** @return array{ok:bool, private_key?:string, csr?:string, error?:string} ECC P-256 key + CSR. */
function psZimraGenerateKeyAndCsr(string $serialNo, int $deviceId): array
{
    $cfg = psZimraOpensslOptions();
    $key = openssl_pkey_new($cfg + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
        error_log('zimra: key generation failed: ' . psZimraOpensslErrors());
        return ['ok' => false, 'error' => 'The device key could not be generated.'];
    }
    $dn = [
        'countryName' => 'ZW',
        'stateOrProvinceName' => 'Zimbabwe',
        'organizationName' => 'Zimbabwe Revenue Authority',
        'commonName' => psZimraCommonName($serialNo, $deviceId),
    ];
    $csr = openssl_csr_new($dn, $key, $cfg + ['digest_alg' => 'sha256']);
    if ($csr === false) {
        error_log('zimra: CSR failed: ' . psZimraOpensslErrors());
        return ['ok' => false, 'error' => 'The certificate request could not be generated.'];
    }
    openssl_csr_export($csr, $csrPem);
    openssl_pkey_export($key, $keyPem, null, $cfg);
    return ['ok' => true, 'private_key' => $keyPem, 'csr' => $csrPem];
}

function psZimraCertMatchesKey(string $certPem, string $keyPem): bool
{
    return @openssl_x509_check_private_key($certPem, $keyPem);
}

function psZimraCertInfo(string $certPem): ?array
{
    $info = @openssl_x509_parse($certPem);
    if (!$info) {
        return null;
    }
    return [
        'cn' => (string)($info['subject']['CN'] ?? ''),
        'valid_till' => date('Y-m-d H:i:s', (int)$info['validTo_time_t']),
    ];
}

/** {hash: base64(SHA-256(data)), signature: base64(sign(data, SHA-256, device key))}. */
function psZimraSign(string $data, string $privateKeyPem): ?array
{
    $key = openssl_pkey_get_private($privateKeyPem);
    if ($key === false || !openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256)) {
        error_log('zimra: signing failed: ' . psZimraOpensslErrors());
        return null;
    }
    return ['hash' => base64_encode(hash('sha256', $data, true)), 'signature' => base64_encode($sig)];
}

// ---------------------------------------------------------------------------
// Pure FDMS rules (verified on the ZIMRA test server 2026-10-15)
// ---------------------------------------------------------------------------

function psZimraCents($amount): string
{
    return (string)(int)round(((float)$amount) * 100);
}

function psZimraMoney($amount): float
{
    $v = round((float)$amount, 2);
    return $v == 0.0 ? 0.0 : $v; // never "-0.0" in JSON
}

/** "15.00" / "0.00", or "" for an exempt tax (no percent). */
function psZimraPercentForSignature($percent): string
{
    return $percent === null || $percent === '' ? '' : number_format((float)$percent, 2, '.', '');
}

/**
 * Build a SubmitReceipt "receipt" object, signed.
 * $r: deviceId, receiptType, currency, receiptCounter, receiptGlobalNo, invoiceNo, date (Y-m-d\TH:i:s),
 *     previousReceiptHash (?string), printForm, notes, creditDebitNote, privateKeyPem,
 *     lines: list of [name, quantity, unitPrice (tax inclusive, POSITIVE), taxId, taxPercent|null, taxCode|null, hsCode|null]
 *     payments: list of [moneyType, amount POSITIVE]
 * Credit notes are negated here.
 * @return array{ok:bool, receipt?:array, error?:string}
 */
function psZimraBuildReceipt(array $r): array
{
    $type = (string)$r['receiptType'];
    if (!in_array($type, PS_ZIMRA_RECEIPT_TYPES, true)) {
        return ['ok' => false, 'error' => 'Unknown fiscal receipt type.'];
    }
    if (empty($r['lines'])) {
        return ['ok' => false, 'error' => 'A fiscal receipt needs at least one line.'];
    }
    $sign = $type === 'CreditNote' ? -1 : 1;
    $lines = [];
    $taxes = [];
    $total = 0.0;
    $no = 0;
    foreach ($r['lines'] as $l) {
        $qty = round((float)$l['quantity'], 3);
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'A fiscal receipt line has no quantity.'];
        }
        $price = psZimraMoney($l['unitPrice']) * $sign;
        $lineTotal = psZimraMoney($qty * $price);
        $line = [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => ++$no,
        ];
        if (!empty($l['hsCode'])) {
            $line['receiptLineHSCode'] = (string)$l['hsCode'];
        }
        $name = trim((string)$l['name']);
        $line += [
            'receiptLineName' => $name === '' ? 'Item' : mb_substr($name, 0, 200),
            'receiptLinePrice' => $price,
            'receiptLineQuantity' => $qty,
            'receiptLineTotal' => $lineTotal,
        ];
        if (!empty($l['taxCode'])) {
            $line['taxCode'] = (string)$l['taxCode'];
        }
        if ($l['taxPercent'] !== null && $l['taxPercent'] !== '') {
            $line['taxPercent'] = psZimraMoney($l['taxPercent']);
        }
        $line['taxID'] = (int)$l['taxId'];
        $lines[] = $line;
        $total += $lineTotal;
        $key = $line['taxID'] . '|' . ($line['taxCode'] ?? '');
        if (!isset($taxes[$key])) {
            $taxes[$key] = ['taxCode' => $line['taxCode'] ?? null, 'taxPercent' => $line['taxPercent'] ?? null, 'taxID' => $line['taxID'], 'sales' => 0.0];
        }
        $taxes[$key]['sales'] += $lineTotal;
    }
    $total = psZimraMoney($total);

    $receiptTaxes = [];
    foreach ($taxes as $t) {
        $sales = psZimraMoney($t['sales']);
        $pct = $t['taxPercent'];
        $row = [];
        if ($t['taxCode'] !== null) {
            $row['taxCode'] = $t['taxCode'];
        }
        if ($pct !== null) {
            $row['taxPercent'] = $pct;
        }
        $row['taxID'] = $t['taxID'];
        $row['taxAmount'] = $pct === null ? 0.0 : psZimraMoney($sales * $pct / (100 + $pct));
        $row['salesAmountWithTax'] = $sales;
        $receiptTaxes[] = $row;
    }
    usort($receiptTaxes, static fn ($a, $b) => [$a['taxID'], $a['taxCode'] ?? ''] <=> [$b['taxID'], $b['taxCode'] ?? '']);

    $payments = [];
    $paid = 0.0;
    foreach ($r['payments'] as $p) {
        if (!in_array($p[0], PS_ZIMRA_MONEY_TYPES, true)) {
            return ['ok' => false, 'error' => 'Unknown fiscal money type.'];
        }
        $amt = psZimraMoney(abs((float)$p[1]) * $sign);
        $payments[] = ['moneyTypeCode' => $p[0], 'paymentAmount' => $amt];
        $paid += $amt;
    }
    if (abs(psZimraMoney($paid) - $total) > 0.001) {
        return ['ok' => false, 'error' => 'Fiscal payments do not equal the receipt total.'];
    }

    $receipt = [
        'receiptType' => $type,
        'receiptCurrency' => strtoupper((string)$r['currency']),
        'receiptCounter' => (int)$r['receiptCounter'],
        'receiptGlobalNo' => (int)$r['receiptGlobalNo'],
        'invoiceNo' => (string)$r['invoiceNo'],
    ];
    if (!empty($r['buyer'])) {
        $receipt['buyerData'] = $r['buyer'];
    }
    if (!empty($r['notes'])) {
        $receipt['receiptNotes'] = mb_substr((string)$r['notes'], 0, 500);
    }
    $receipt['receiptDate'] = (string)$r['date'];
    if (!empty($r['creditDebitNote'])) {
        $receipt['creditDebitNote'] = $r['creditDebitNote'];
    }
    $receipt += [
        'receiptLinesTaxInclusive' => true,
        'receiptLines' => $lines,
        'receiptTaxes' => $receiptTaxes,
        'receiptPayments' => $payments,
        'receiptTotal' => $total,
        'receiptPrintForm' => ($r['printForm'] ?? 'Receipt48') === 'InvoiceA4' ? 'InvoiceA4' : 'Receipt48',
    ];
    if ($type !== 'FiscalInvoice' && empty($receipt['receiptNotes'])) {
        return ['ok' => false, 'error' => 'Credit and debit notes need a reason.'];
    }
    $data = psZimraReceiptSignatureString((int)$r['deviceId'], $receipt, $r['previousReceiptHash'] ?? null);
    $sig = psZimraSign($data, (string)$r['privateKeyPem']);
    if ($sig === null) {
        return ['ok' => false, 'error' => 'The fiscal receipt could not be signed.'];
    }
    $receipt['receiptDeviceSignature'] = $sig;
    return ['ok' => true, 'receipt' => $receipt];
}

/**
 * deviceID + receiptType + receiptCurrency + receiptGlobalNo + receiptDate + receiptTotal(cents)
 * + taxes (taxCode + taxPercent + taxAmount(cents) + salesAmountWithTax(cents), by taxID, taxCode)
 * + previousReceiptHash (omitted for the first receipt of the fiscal day).
 */
function psZimraReceiptSignatureString(int $deviceId, array $receipt, ?string $previousReceiptHash): string
{
    $taxes = $receipt['receiptTaxes'];
    usort($taxes, static fn ($a, $b) => [$a['taxID'], $a['taxCode'] ?? ''] <=> [$b['taxID'], $b['taxCode'] ?? '']);
    $taxStr = '';
    foreach ($taxes as $t) {
        $taxStr .= ($t['taxCode'] ?? '')
            . psZimraPercentForSignature($t['taxPercent'] ?? null)
            . psZimraCents($t['taxAmount'])
            . psZimraCents($t['salesAmountWithTax']);
    }
    return $deviceId
        . strtoupper($receipt['receiptType'])
        . strtoupper($receipt['receiptCurrency'])
        . $receipt['receiptGlobalNo']
        . $receipt['receiptDate']
        . psZimraCents($receipt['receiptTotal'])
        . $taxStr
        . ($previousReceiptHash ?? '');
}

/** fiscalDayCounters from the day's submitted receipt objects; zero counters omitted. */
function psZimraDayCounters(array $receipts): array
{
    $acc = [];
    $add = static function (string $type, string $cur, ?int $taxId, $pct, ?string $money, float $value) use (&$acc) {
        $key = implode('|', [$type, $cur, (string)$taxId, $pct === null ? 'x' : (string)$pct, (string)$money]);
        if (!isset($acc[$key])) {
            $acc[$key] = ['type' => $type, 'cur' => $cur, 'taxId' => $taxId, 'pct' => $pct, 'money' => $money, 'value' => 0.0];
        }
        $acc[$key]['value'] += $value;
    };
    $prefix = ['FiscalInvoice' => 'Sale', 'CreditNote' => 'CreditNote', 'DebitNote' => 'DebitNote'];
    foreach ($receipts as $rc) {
        $p = $prefix[$rc['receiptType']] ?? null;
        if ($p === null) {
            continue;
        }
        $cur = strtoupper((string)$rc['receiptCurrency']);
        foreach ($rc['receiptTaxes'] as $t) {
            $pct = $t['taxPercent'] ?? null;
            $add($p . 'ByTax', $cur, (int)$t['taxID'], $pct, null, (float)$t['salesAmountWithTax']);
            $add($p . 'TaxByTax', $cur, (int)$t['taxID'], $pct, null, (float)$t['taxAmount']);
        }
        foreach ($rc['receiptPayments'] as $pay) {
            $add('BalanceByMoneyType', $cur, null, null, (string)$pay['moneyTypeCode'], (float)$pay['paymentAmount']);
        }
    }
    $out = [];
    foreach ($acc as $c) {
        $value = psZimraMoney($c['value']);
        if (abs($value) < 0.005) {
            continue;
        }
        $row = ['fiscalCounterType' => $c['type'], 'fiscalCounterCurrency' => $c['cur']];
        if ($c['type'] === 'BalanceByMoneyType') {
            $row['fiscalCounterMoneyType'] = $c['money'];
        } else {
            if ($c['pct'] !== null) {
                $row['fiscalCounterTaxPercent'] = psZimraMoney($c['pct']);
            }
            $row['fiscalCounterTaxID'] = $c['taxId'];
        }
        $row['fiscalCounterValue'] = $value;
        $out[] = $row;
    }
    return psZimraSortCounters($out);
}

function psZimraSortCounters(array $counters): array
{
    $key = static fn ($c) => [
        array_search($c['fiscalCounterType'], PS_ZIMRA_COUNTER_TYPES, true),
        $c['fiscalCounterCurrency'],
        $c['fiscalCounterTaxID'] ?? -1,
        isset($c['fiscalCounterMoneyType']) ? array_search($c['fiscalCounterMoneyType'], PS_ZIMRA_MONEY_TYPES, true) : -1,
    ];
    usort($counters, static fn ($a, $b) => $key($a) <=> $key($b));
    return $counters;
}

/** deviceID + fiscalDayNo + fiscalDayDate (yyyy-MM-dd opened) + TYPE CURRENCY (pct|MONEYTYPE) value(cents)… */
function psZimraDaySignatureString(int $deviceId, int $fiscalDayNo, string $fiscalDayDate, array $counters): string
{
    $s = $deviceId . $fiscalDayNo . $fiscalDayDate;
    foreach (psZimraSortCounters($counters) as $c) {
        $s .= strtoupper($c['fiscalCounterType'])
            . strtoupper($c['fiscalCounterCurrency'])
            . (isset($c['fiscalCounterMoneyType'])
                ? strtoupper($c['fiscalCounterMoneyType'])
                : psZimraPercentForSignature($c['fiscalCounterTaxPercent'] ?? null))
            . psZimraCents($c['fiscalCounterValue']);
    }
    return $s;
}

/** First 16 hex chars (upper) of MD5 over the device signature bytes. */
function psZimraQrData(string $signatureBase64): string
{
    return strtoupper(substr(md5((string)base64_decode($signatureBase64)), 0, 16));
}

function psZimraVerificationCode(string $signatureBase64): string
{
    return implode('-', str_split(psZimraQrData($signatureBase64), 4));
}

/** qrUrl/ + deviceID(10) + receiptDate(ddMMyyyy) + receiptGlobalNo(10) + qrData(16). */
function psZimraQrUrl(string $baseUrl, int $deviceId, string $receiptDate, int $globalNo, string $signatureBase64): string
{
    $d = DateTimeImmutable::createFromFormat(PS_ZIMRA_DATE_FORMAT, $receiptDate);
    return rtrim($baseUrl, '/') . '/'
        . str_pad((string)$deviceId, 10, '0', STR_PAD_LEFT)
        . ($d ? $d->format('dmY') : '')
        . str_pad((string)$globalNo, 10, '0', STR_PAD_LEFT)
        . psZimraQrData($signatureBase64);
}

// ---------------------------------------------------------------------------
// HTTP client (mutual TLS)
// ---------------------------------------------------------------------------

/**
 * @return array{ok:bool, status:int, body:array, error:string, error_code:?string, retryable:bool}
 * retryable = FDMS not reached / 5xx / 429 (safe to send again later).
 */
function psZimraHttp(mysqli $conn, array $device, string $method, string $path, ?array $body, bool $mtls, string $actor, ?int $timeout = null): array
{
    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $started = microtime(true);

    // CLI regression hook only (never honoured from the web).
    if ((PHP_SAPI === 'cli') && isset($GLOBALS['PS_ZIMRA_HTTP_FAKE']) && is_callable($GLOBALS['PS_ZIMRA_HTTP_FAKE'])) {
        $fake = ($GLOBALS['PS_ZIMRA_HTTP_FAKE'])($device, $method, $path, $body);
        $status = (int)($fake['status'] ?? 0);
        $raw = isset($fake['body']) ? json_encode($fake['body']) : false;
        $curlErr = $status === 0 ? 'simulated network failure' : '';
    } else {
        $url = (($device['environment'] ?? 'test') === 'production' ? PS_ZIMRA_URL_PRODUCTION : PS_ZIMRA_URL_TEST) . $path;
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'DeviceModelName: ' . $device['model_name'],
            'DeviceModelVersion: ' . $device['model_version'],
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout ?? 5),
            CURLOPT_TIMEOUT => $timeout ?? 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json ?? '');
        }
        $tmp = [];
        if ($mtls) {
            $keyPem = psZimraDecrypt($device['private_key_enc'] ?? null);
            if ($keyPem === null || empty($device['certificate_pem'])) {
                curl_close($ch);
                return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'The device certificate or key is missing.', 'error_code' => null, 'retryable' => false];
            }
            if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
                curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
                curl_setopt($ch, CURLOPT_SSLCERT_BLOB, (string)$device['certificate_pem']);
                curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
                curl_setopt($ch, CURLOPT_SSLKEY_BLOB, $keyPem);
            } else {
                $c = tempnam(sys_get_temp_dir(), 'zc');
                $k = tempnam(sys_get_temp_dir(), 'zk');
                file_put_contents($c, (string)$device['certificate_pem']);
                file_put_contents($k, $keyPem);
                @chmod($c, 0600);
                @chmod($k, 0600);
                curl_setopt($ch, CURLOPT_SSLCERT, $c);
                curl_setopt($ch, CURLOPT_SSLKEY, $k);
                $tmp = [$c, $k];
            }
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        foreach ($tmp as $f) {
            @unlink($f);
        }
    }
    $ms = (int)round((microtime(true) - $started) * 1000);
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $decoded = is_array($decoded) ? $decoded : [];

    // Log without secrets.
    $logReq = $json;
    $logResp = is_string($raw) ? $raw : null;
    if (strpos($path, 'RegisterDevice') !== false) {
        $logReq = '{"activationKey":"***","certificateRequest":"<CSR>"}';
    }
    if (strpos($path, 'IssueCertificate') !== false) {
        $logReq = '{"certificateRequest":"<CSR>"}';
    }
    if (strpos($path, 'RegisterDevice') !== false || strpos($path, 'IssueCertificate') !== false) {
        $logResp = '{"certificate":"<CERT>","operationID":"' . ($decoded['operationID'] ?? '') . '"}';
    }
    psZimraQuery(
        $conn,
        'INSERT INTO zimra_api_log (device_row_id, endpoint, http_status, operation_id, error_code, request_body, response_body, duration_ms, actor)
         VALUES (?,?,?,?,?,?,?,?,?)',
        'isissssis',
        [
            isset($device['id']) ? (int)$device['id'] : null, substr($path, 0, 100), $status,
            $decoded['operationID'] ?? null, $decoded['errorCode'] ?? null, $logReq, $logResp, $ms, $actor,
        ]
    );

    if ($raw === false || $status === 0) {
        return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'ZIMRA could not be reached (' . ($curlErr ?: 'no response') . ').', 'error_code' => null, 'retryable' => true];
    }
    if ($status < 200 || $status >= 300) {
        $detail = (string)($decoded['detail'] ?? $decoded['title'] ?? '');
        return [
            'ok' => false,
            'status' => $status,
            'body' => $decoded,
            'error' => 'ZIMRA returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '') . (isset($decoded['errorCode']) ? ' (' . $decoded['errorCode'] . ')' : ''),
            'error_code' => $decoded['errorCode'] ?? null,
            'retryable' => $status >= 500 || $status === 429,
        ];
    }
    return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => '', 'error_code' => null, 'retryable' => false];
}

// ---------------------------------------------------------------------------
// Devices
// ---------------------------------------------------------------------------

/**
 * Company the web session works in ('' on CLI / worker = no company restriction).
 * Branch names are only unique inside a company (two companies can both have "RUSAPE"),
 * so every branch -> device lookup and every device list is scoped by company too.
 */
function psZimraSessionCompany(): string
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['the_company'] ?? ''));
}

/** Device belongs to the session company (always true on CLI; legacy rows with no company match any). */
function psZimraDeviceInSessionCompany(?array $d): bool
{
    if (!$d) {
        return false;
    }
    $c = psZimraSessionCompany();
    $dc = trim((string)($d['company_name'] ?? ''));
    return $c === '' || $dc === '' || $dc === $c;
}

function psZimraDeviceGet(mysqli $conn, int $id, bool $forUpdate = false): ?array
{
    return psZimraOne($conn, 'SELECT * FROM zimra_devices WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$id]);
}

/** Registered + enabled device fiscalising sales for a branch (production preferred). */
function psZimraDeviceForBranch(mysqli $conn, string $branch, bool $forUpdate = false): ?array
{
    if (!psEnsureZimraModule($conn)) {
        return null;
    }
    $company = psZimraSessionCompany();
    if ($company === '') {
        return psZimraOne(
            $conn,
            "SELECT * FROM zimra_devices WHERE branch = ? AND status = 'REGISTERED' AND enabled = 1
             ORDER BY environment = 'production' DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''),
            's',
            [trim($branch)]
        );
    }
    return psZimraOne(
        $conn,
        "SELECT * FROM zimra_devices WHERE branch = ? AND (company_name = ? OR company_name = '')
           AND status = 'REGISTERED' AND enabled = 1
         ORDER BY company_name = ? DESC, environment = 'production' DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''),
        'sss',
        [trim($branch), $company, $company]
    );
}

/** Devices of the session company (all devices on CLI). */
function psZimraDeviceList(mysqli $conn): array
{
    $company = psZimraSessionCompany();
    if ($company === '') {
        return psZimraQuery($conn, 'SELECT * FROM zimra_devices ORDER BY branch, environment') ?? [];
    }
    return psZimraQuery($conn, "SELECT * FROM zimra_devices WHERE company_name = ? OR company_name = '' ORDER BY branch, environment", 's', [$company]) ?? [];
}

/** @return array{ok:bool, id?:int, error?:string} */
function psZimraDeviceCreate(mysqli $conn, array $in, string $actor): array
{
    $branch = trim((string)($in['branch'] ?? ''));
    $env = (string)($in['environment'] ?? '') === 'production' ? 'production' : 'test';
    $deviceId = (int)($in['device_id'] ?? 0);
    $serial = strtoupper(trim((string)($in['device_serial_no'] ?? '')));
    $model = trim((string)($in['model_name'] ?? ''));
    $version = trim((string)($in['model_version'] ?? ''));
    $activation = trim((string)($in['activation_key'] ?? ''));
    if ($branch === '' || $deviceId < 1 || $serial === '' || $model === '' || $version === '') {
        return ['ok' => false, 'error' => 'Branch, device ID, serial number, model name and model version are required.'];
    }
    if (!preg_match('/^[A-Za-z0-9\-]{1,50}$/', $serial)) {
        return ['ok' => false, 'error' => 'The serial number may contain only letters, digits and dashes.'];
    }
    if ($activation !== '' && !preg_match('/^[A-Za-z0-9]{8}$/', $activation)) {
        return ['ok' => false, 'error' => 'The activation key must be 8 letters or digits.'];
    }
    $branchRow = psZimraOne($conn, 'SELECT branch_name FROM branchies WHERE branch_name = ? LIMIT 1', 's', [$branch]);
    if ($branchRow && psZimraSessionCompany() !== '' && function_exists('psBranchRegisteredNames')
        && !in_array($branch, psBranchRegisteredNames($conn), true)) {
        $branchRow = null; // Phase 18: a branch of another company
    }
    if (!$branchRow) {
        return ['ok' => false, 'error' => 'Choose a registered branch.'];
    }
    $enc = $activation !== '' ? psZimraEncrypt($activation) : null;
    if ($activation !== '' && $enc === null) {
        return ['ok' => false, 'error' => 'The ZIMRA master key is not available. Check the pos-private folder.'];
    }
    $res = psZimraQuery(
        $conn,
        'INSERT INTO zimra_devices (branch, company_name, environment, device_id, device_serial_no, model_name, model_version, activation_key_enc, print_form, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        'sssissssss',
        [$branch, trim((string)($in['company_name'] ?? '')), $env, $deviceId, $serial, $model, $version, $enc,
            ($in['print_form'] ?? '') === 'InvoiceA4' ? 'InvoiceA4' : 'Receipt48', $actor]
    );
    if (!is_array($res) || ($res['insert_id'] ?? 0) < 1) {
        return ['ok' => false, 'error' => 'That device or branch already has a ' . $env . ' device.'];
    }
    psZimraAudit($conn, $res['insert_id'], 'DEVICE_CREATE', $actor, ['branch' => $branch, 'environment' => $env, 'device_id' => $deviceId]);
    return ['ok' => true, 'id' => $res['insert_id']];
}

function psZimraDeviceSetActivationKey(mysqli $conn, int $id, string $key, string $actor): array
{
    $key = trim($key);
    if (!preg_match('/^[A-Za-z0-9]{8}$/', $key)) {
        return ['ok' => false, 'error' => 'The activation key must be 8 letters or digits.'];
    }
    $enc = psZimraEncrypt($key);
    if ($enc === null) {
        return ['ok' => false, 'error' => 'The ZIMRA master key is not available.'];
    }
    psZimraQuery($conn, 'UPDATE zimra_devices SET activation_key_enc = ? WHERE id = ?', 'si', [$enc, $id]);
    psZimraAudit($conn, $id, 'ACTIVATION_KEY_SET', $actor);
    return ['ok' => true];
}

function psZimraDeviceSaveCertificate(mysqli $conn, int $id, string $keyPem, string $certPem): bool
{
    $enc = psZimraEncrypt($keyPem);
    $info = psZimraCertInfo($certPem);
    if ($enc === null || $info === null) {
        return false;
    }
    $res = psZimraQuery(
        $conn,
        "UPDATE zimra_devices SET private_key_enc = ?, certificate_pem = ?, certificate_valid_till = ?, status = 'REGISTERED' WHERE id = ?",
        'sssi',
        [$enc, $certPem, $info['valid_till'], $id]
    );
    return is_array($res);
}

/** Generate key + CSR on the server and call RegisterDevice with the stored activation key. */
function psZimraDeviceRegister(mysqli $conn, int $id, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    $activation = psZimraDecrypt($d['activation_key_enc'] ?? null);
    if ($activation === null) {
        return ['ok' => false, 'error' => 'Enter the activation key from the ZIMRA portal first.'];
    }
    $gen = psZimraGenerateKeyAndCsr($d['device_serial_no'], (int)$d['device_id']);
    if (!$gen['ok']) {
        return $gen;
    }
    $resp = psZimraHttp($conn, $d, 'POST', '/Public/v1/' . (int)$d['device_id'] . '/RegisterDevice',
        ['activationKey' => $activation, 'certificateRequest' => $gen['csr']], false, $actor);
    if (!$resp['ok']) {
        psZimraAudit($conn, $id, 'REGISTER_FAIL', $actor, ['error' => $resp['error']]);
        return ['ok' => false, 'error' => $resp['error']];
    }
    $cert = (string)($resp['body']['certificate'] ?? '');
    if ($cert === '' || !psZimraCertMatchesKey($cert, $gen['private_key'])) {
        return ['ok' => false, 'error' => 'ZIMRA did not return a certificate for this device key.'];
    }
    if (!psZimraDeviceSaveCertificate($conn, $id, $gen['private_key'], $cert)) {
        return ['ok' => false, 'error' => 'The certificate could not be saved.'];
    }
    psZimraQuery($conn, 'UPDATE zimra_devices SET activation_key_enc = NULL WHERE id = ?', 'i', [$id]);
    psZimraAudit($conn, $id, 'REGISTER', $actor, ['cn' => psZimraCertInfo($cert)['cn'] ?? '']);
    $sync = psZimraDeviceSyncConfig($conn, $id, $actor);
    psZimraDeviceRefreshStatus($conn, $id, $actor);
    return $sync['ok'] ? ['ok' => true] : ['ok' => true, 'warning' => $sync['error']];
}

/** Use a key + certificate created elsewhere (e.g. the C:\FDMS-Test files). */
function psZimraDeviceImport(mysqli $conn, int $id, string $keyPem, string $certPem, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    $keyPem = trim($keyPem) . "\n";
    $certPem = trim($certPem) . "\n";
    if (!psZimraCertMatchesKey($certPem, $keyPem)) {
        return ['ok' => false, 'error' => 'The certificate does not belong to this private key.'];
    }
    $info = psZimraCertInfo($certPem);
    $expected = psZimraCommonName($d['device_serial_no'], (int)$d['device_id']);
    if (!$info || $info['cn'] !== $expected) {
        return ['ok' => false, 'error' => 'The certificate is for ' . ($info['cn'] ?? 'another device') . ', expected ' . $expected . '.'];
    }
    if (!psZimraDeviceSaveCertificate($conn, $id, $keyPem, $certPem)) {
        return ['ok' => false, 'error' => 'The certificate could not be saved.'];
    }
    psZimraAudit($conn, $id, 'IMPORT_CERTIFICATE', $actor, ['cn' => $info['cn'], 'valid_till' => $info['valid_till']]);
    $sync = psZimraDeviceSyncConfig($conn, $id, $actor);
    psZimraDeviceRefreshStatus($conn, $id, $actor);
    return $sync['ok'] ? ['ok' => true] : ['ok' => true, 'warning' => $sync['error']];
}

/** GetConfig → taxpayer details, taxes, QR URL, day limits. */
function psZimraDeviceSyncConfig(mysqli $conn, int $id, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    $resp = psZimraHttp($conn, $d, 'GET', '/Device/v1/' . (int)$d['device_id'] . '/GetConfig', null, true, $actor);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }
    $c = $resp['body'];
    mysqli_begin_transaction($conn);
    psZimraQuery(
        $conn,
        'UPDATE zimra_devices SET operating_mode=?, taxpayer_name=?, taxpayer_tin=?, vat_number=?, fdms_branch_name=?,
         fdms_branch_address=?, fdms_branch_contacts=?, qr_url=?, day_max_hrs=?, day_end_notification_hrs=?, config_json=?, config_synced_at=NOW()
         WHERE id=?',
        'ssssssssiisi',
        [
            $c['deviceOperatingMode'] ?? null, $c['taxPayerName'] ?? null, $c['taxPayerTIN'] ?? null, $c['vatNumber'] ?? null,
            $c['deviceBranchName'] ?? null, json_encode($c['deviceBranchAddress'] ?? null), json_encode($c['deviceBranchContacts'] ?? null),
            $c['qrUrl'] ?? null, isset($c['taxPayerDayMaxHrs']) ? (int)$c['taxPayerDayMaxHrs'] : null,
            isset($c['taxpayerDayEndNotificationHrs']) ? (int)$c['taxpayerDayEndNotificationHrs'] : null,
            json_encode($c), $id,
        ]
    );
    $ids = [];
    foreach (($c['applicableTaxes'] ?? []) as $t) {
        $ids[] = (int)$t['taxID'];
        $pct = array_key_exists('taxPercent', $t) && $t['taxPercent'] !== null ? (string)$t['taxPercent'] : null;
        $from = isset($t['validFrom']) ? str_replace('T', ' ', substr((string)$t['validFrom'], 0, 19)) : null;
        $till = isset($t['validTill']) ? str_replace('T', ' ', substr((string)$t['validTill'], 0, 19)) : null;
        psZimraQuery(
            $conn,
            'INSERT INTO zimra_taxes (device_row_id, tax_id, tax_code, tax_name, tax_percent, valid_from, valid_till) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE tax_code=VALUES(tax_code), tax_name=VALUES(tax_name), tax_percent=VALUES(tax_percent),
             valid_from=VALUES(valid_from), valid_till=VALUES(valid_till)',
            'iisssss',
            [$id, (int)$t['taxID'], $t['taxCode'] ?? null, (string)($t['taxName'] ?? ''), $pct, $from, $till]
        );
    }
    if ($ids) {
        mysqli_query($conn, 'DELETE FROM zimra_taxes WHERE device_row_id = ' . $id . ' AND tax_id NOT IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
    mysqli_commit($conn);
    return ['ok' => true, 'config' => $c];
}

/** GetStatus → keep last fiscal day / global receipt numbers in step with FDMS. */
function psZimraDeviceRefreshStatus(mysqli $conn, int $id, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    $resp = psZimraHttp($conn, $d, 'GET', '/Device/v1/' . (int)$d['device_id'] . '/GetStatus', null, true, $actor, 15);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error'], 'retryable' => $resp['retryable']];
    }
    $s = $resp['body'];
    psZimraQuery(
        $conn,
        'UPDATE zimra_devices SET last_fiscal_day_no = GREATEST(last_fiscal_day_no, ?), last_receipt_global_no = GREATEST(last_receipt_global_no, ?) WHERE id = ?',
        'iii',
        [(int)($s['lastFiscalDayNo'] ?? 0), (int)($s['lastReceiptGlobalNo'] ?? 0), $id]
    );
    return ['ok' => true, 'status' => $s];
}

/** IssueCertificate with a fresh key (before certificate_valid_till). */
function psZimraDeviceRenewCertificate(mysqli $conn, int $id, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d || $d['status'] !== 'REGISTERED') {
        return ['ok' => false, 'error' => 'Only a registered device can renew its certificate.'];
    }
    $gen = psZimraGenerateKeyAndCsr($d['device_serial_no'], (int)$d['device_id']);
    if (!$gen['ok']) {
        return $gen;
    }
    $resp = psZimraHttp($conn, $d, 'POST', '/Device/v1/' . (int)$d['device_id'] . '/IssueCertificate', ['certificateRequest' => $gen['csr']], true, $actor);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }
    $cert = (string)($resp['body']['certificate'] ?? '');
    if ($cert === '' || !psZimraCertMatchesKey($cert, $gen['private_key']) || !psZimraDeviceSaveCertificate($conn, $id, $gen['private_key'], $cert)) {
        return ['ok' => false, 'error' => 'The renewed certificate could not be saved.'];
    }
    psZimraAudit($conn, $id, 'RENEW_CERTIFICATE', $actor, ['valid_till' => psZimraCertInfo($cert)['valid_till'] ?? '']);
    return ['ok' => true];
}

/**
 * Delete a device that was entered by mistake. Only allowed before it is registered with ZIMRA and
 * while it has no fiscal days or receipts (a registered device's records must be kept; turn it off instead).
 *
 * @return array{ok:bool, error?:string}
 */
function psZimraDeviceDelete(mysqli $conn, int $id, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d || !psZimraDeviceInSessionCompany($d)) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    if ($d['status'] === 'REGISTERED' || !empty($d['certificate_pem'])) {
        return ['ok' => false, 'error' => 'A device registered with ZIMRA cannot be deleted — its fiscal records must be kept. Turn fiscalisation off instead.'];
    }
    $days = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_fiscal_days WHERE device_row_id = ?', 'i', [$id]);
    $rcpt = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_receipts WHERE device_row_id = ?', 'i', [$id]);
    if ((int)($days['n'] ?? 0) > 0 || (int)($rcpt['n'] ?? 0) > 0) {
        return ['ok' => false, 'error' => 'This device has fiscal days or receipts and cannot be deleted.'];
    }
    psZimraQuery($conn, 'DELETE FROM zimra_tax_map WHERE device_row_id = ?', 'i', [$id]);
    psZimraQuery($conn, 'DELETE FROM zimra_taxes WHERE device_row_id = ?', 'i', [$id]);
    psZimraQuery($conn, 'DELETE FROM zimra_devices WHERE id = ? AND status <> \'REGISTERED\'', 'i', [$id]);
    psZimraAudit($conn, $id, 'DEVICE_DELETE', $actor, [
        'branch' => $d['branch'], 'environment' => $d['environment'], 'device_id' => (int)$d['device_id'],
        'serial' => $d['device_serial_no'], 'company' => $d['company_name'],
    ]);
    return ['ok' => true];
}

/**
 * Move a device to another branch of the same company (wrong branch chosen when it was added).
 * Allowed while the device has no fiscal days or receipts, registered or not.
 *
 * @return array{ok:bool, error?:string}
 */
function psZimraDeviceMoveBranch(mysqli $conn, int $id, string $branch, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d || !psZimraDeviceInSessionCompany($d)) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    $branch = trim($branch);
    $allowed = function_exists('psBranchRegisteredNames') ? psBranchRegisteredNames($conn) : [];
    if ($branch === '' || ($allowed && !in_array($branch, $allowed, true))) {
        return ['ok' => false, 'error' => 'Choose one of this company\'s branches.'];
    }
    if ($branch === (string)$d['branch']) {
        return ['ok' => false, 'error' => 'The device is already on that branch.'];
    }
    $days = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_fiscal_days WHERE device_row_id = ?', 'i', [$id]);
    $rcpt = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_receipts WHERE device_row_id = ?', 'i', [$id]);
    if ((int)($days['n'] ?? 0) > 0 || (int)($rcpt['n'] ?? 0) > 0) {
        return ['ok' => false, 'error' => 'This device already has fiscal days or receipts on ' . $d['branch'] . ' and cannot be moved.'];
    }
    $clash = psZimraOne($conn, 'SELECT id FROM zimra_devices WHERE company_name = ? AND branch = ? AND environment = ? AND id <> ?',
        'sssi', [(string)$d['company_name'], $branch, (string)$d['environment'], $id]);
    if ($clash) {
        return ['ok' => false, 'error' => $branch . ' already has a ' . $d['environment'] . ' device.'];
    }
    psZimraQuery($conn, 'UPDATE zimra_devices SET branch = ? WHERE id = ?', 'si', [$branch, $id]);
    psZimraAudit($conn, $id, 'DEVICE_MOVE', $actor, ['from' => $d['branch'], 'to' => $branch]);
    return ['ok' => true];
}

/**
 * 2026-10-20 — Super Admin: move a device to another company (and one of its branches), e.g. a device added under the
 * wrong test company. Allowed only while the device has no fiscal days or receipts: its counters, receipt chain and
 * fiscal history belong to the company that used it. Fiscalisation is switched off after the move so the new company's
 * admin checks the tax mapping and switches it on. Certificate, key and ZIMRA registration move with the device.
 */
function psZimraDeviceMoveCompany(mysqli $conn, int $id, string $company, string $branch, string $actor): array
{
    if (!function_exists('psPlatformSessionIsSuperAdmin') || !psPlatformSessionIsSuperAdmin()) {
        return ['ok' => false, 'error' => 'Only a Super Admin can move a device to another company.'];
    }
    $company = trim($company);
    $branch = trim($branch);
    if ($company === '' || $branch === '') {
        return ['ok' => false, 'error' => 'Choose the company and the branch.'];
    }
    $ok = psZimraOne($conn, 'SELECT id FROM branchies WHERE company_name = ? AND branch_name = ? LIMIT 1', 'ss', [$company, $branch]);
    if (!$ok) {
        return ['ok' => false, 'error' => $branch . ' is not a branch of ' . $company . '.'];
    }
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The device could not be moved. Try again.'];
    }
    $d = psZimraDeviceGet($conn, $id, true);
    if (!$d) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    if ((string)$d['company_name'] === $company && (string)$d['branch'] === $branch) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The device is already on that company and branch.'];
    }
    $days = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_fiscal_days WHERE device_row_id = ?', 'i', [$id]);
    $rcpt = psZimraOne($conn, 'SELECT COUNT(*) n FROM zimra_receipts WHERE device_row_id = ?', 'i', [$id]);
    if ((int)($days['n'] ?? 0) > 0 || (int)($rcpt['n'] ?? 0) > 0) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'This device already has fiscal days or receipts under ' . ($d['company_name'] !== '' ? $d['company_name'] : 'its company')
            . ' and cannot be moved: its fiscal history must stay with that company. Register another device for ' . $company . ' instead.'];
    }
    $clash = psZimraOne($conn, 'SELECT id FROM zimra_devices WHERE company_name = ? AND branch = ? AND environment = ? AND id <> ?',
        'sssi', [$company, $branch, (string)$d['environment'], $id]);
    if ($clash) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $branch . ' (' . $company . ') already has a ' . $d['environment'] . ' device.'];
    }
    if (psZimraQuery($conn, 'UPDATE zimra_devices SET company_name = ?, branch = ?, enabled = 0 WHERE id = ?', 'ssi', [$company, $branch, $id]) === null) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The device could not be moved. Try again.'];
    }
    psZimraAudit($conn, $id, 'DEVICE_MOVE_COMPANY', $actor, ['from_company' => $d['company_name'], 'from_branch' => $d['branch'],
        'to_company' => $company, 'to_branch' => $branch, 'device_id' => (int)$d['device_id']]);
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The device could not be moved. Try again.'];
    }
    return ['ok' => true];
}

function psZimraDeviceSetEnabled(mysqli $conn, int $id, bool $on, string $actor): array
{
    $d = psZimraDeviceGet($conn, $id);
    if (!$d) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    if ($on) {
        if ($d['status'] !== 'REGISTERED') {
            return ['ok' => false, 'error' => 'Register the device before turning fiscalisation on.'];
        }
        $taxes = psZimraQuery($conn, 'SELECT tax_id FROM zimra_taxes WHERE device_row_id = ?', 'i', [$id]) ?? [];
        if (!$taxes) {
            return ['ok' => false, 'error' => 'Sync the ZIMRA configuration (taxes) before turning fiscalisation on.'];
        }
        $other = psZimraOne($conn, "SELECT id FROM zimra_devices WHERE branch = ? AND company_name = ? AND enabled = 1 AND id <> ?", 'ssi', [$d['branch'], (string)$d['company_name'], $id]);
        if ($other) {
            return ['ok' => false, 'error' => 'Another device is already fiscalising this branch. Turn it off first.'];
        }
    }
    psZimraQuery($conn, 'UPDATE zimra_devices SET enabled = ? WHERE id = ?', 'ii', [$on ? 1 : 0, $id]);
    psZimraAudit($conn, $id, $on ? 'ENABLE' : 'DISABLE', $actor);
    return ['ok' => true];
}

/**
 * Receipt logo for a device (printed at the top of fiscal receipts). Stored as a data URI in the DB so
 * nothing is written to the webroot. PNG / JPEG / GIF / WEBP, max 500 KB. Pass $tmpPath = null to remove.
 */
function psZimraDeviceSetLogo(mysqli $conn, int $id, ?string $tmpPath, string $actor): array
{
    if (!psZimraDeviceGet($conn, $id)) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    if ($tmpPath === null) {
        psZimraQuery($conn, 'UPDATE zimra_devices SET receipt_logo = NULL WHERE id = ?', 'i', [$id]);
        psZimraAudit($conn, $id, 'LOGO_REMOVE', $actor);
        return ['ok' => true];
    }
    $size = @filesize($tmpPath);
    if (!$size || $size > 512000) {
        return ['ok' => false, 'error' => 'Choose an image up to 500 KB.'];
    }
    $info = @getimagesize($tmpPath);
    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (!$info || !in_array($info['mime'], $allowed, true)) {
        return ['ok' => false, 'error' => 'The logo must be a PNG, JPEG, GIF or WEBP image.'];
    }
    $data = 'data:' . $info['mime'] . ';base64,' . base64_encode((string)file_get_contents($tmpPath));
    psZimraQuery($conn, 'UPDATE zimra_devices SET receipt_logo = ? WHERE id = ?', 'si', [$data, $id]);
    psZimraAudit($conn, $id, 'LOGO_SET', $actor, ['mime' => $info['mime'], 'bytes' => $size, 'width' => $info[0], 'height' => $info[1]]);
    return ['ok' => true];
}

/** Map a POS tax code ('' = products with no tax code) to a ZIMRA tax ID. */
function psZimraTaxMapSet(mysqli $conn, int $id, string $posTaxCode, int $taxId, string $actor): array
{
    $posTaxCode = strtoupper(trim($posTaxCode));
    if ($taxId === 0) {
        psZimraQuery($conn, 'DELETE FROM zimra_tax_map WHERE device_row_id = ? AND pos_tax_code = ?', 'is', [$id, $posTaxCode]);
        psZimraAudit($conn, $id, 'TAX_MAP_CLEAR', $actor, ['pos_tax_code' => $posTaxCode]);
        return ['ok' => true];
    }
    if (!psZimraOne($conn, 'SELECT id FROM zimra_taxes WHERE device_row_id = ? AND tax_id = ?', 'ii', [$id, $taxId])) {
        return ['ok' => false, 'error' => 'That ZIMRA tax is not available for this device.'];
    }
    psZimraQuery(
        $conn,
        'INSERT INTO zimra_tax_map (device_row_id, pos_tax_code, tax_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE tax_id = VALUES(tax_id)',
        'isi',
        [$id, $posTaxCode, $taxId]
    );
    psZimraAudit($conn, $id, 'TAX_MAP_SET', $actor, ['pos_tax_code' => $posTaxCode, 'tax_id' => $taxId]);
    return ['ok' => true];
}

/** @return array{tax_id:int, tax_percent:?string, tax_code:?string}|null */
function psZimraResolveTax(mysqli $conn, int $deviceRowId, string $posTaxCode): ?array
{
    $posTaxCode = strtoupper(trim($posTaxCode));
    $row = psZimraOne(
        $conn,
        'SELECT t.tax_id, t.tax_percent, t.tax_code FROM zimra_tax_map m
         JOIN zimra_taxes t ON t.device_row_id = m.device_row_id AND t.tax_id = m.tax_id
         WHERE m.device_row_id = ? AND m.pos_tax_code = ?',
        'is',
        [$deviceRowId, $posTaxCode]
    );
    if ($row) {
        return $row;
    }
    $all = psZimraQuery($conn, 'SELECT tax_id, tax_percent, tax_code FROM zimra_taxes WHERE device_row_id = ?', 'i', [$deviceRowId]) ?? [];
    return count($all) === 1 ? $all[0] : null; // single tax (e.g. non-VAT taxpayer) needs no mapping
}

require_once __DIR__ . '/zimra_receipts.php';
