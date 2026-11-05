<?php
/**
 * Phase 12 — VAT / Tax configuration, calculation, and sale/refund persistence.
 *
 * Rates and classifications are configurable (never hardcoded as verified law).
 * ZERO_RATED and EXEMPT are distinct. Inclusive vs exclusive is explicit per rate.
 * Historical rows without Phase-12 tax columns populated are LEGACY_INCOMPLETE —
 * never inferred from product names, VAT_STATE, or today's rate.
 * Browser/mobile tax amounts are never trusted; server recomputes from config + prices.
 */

require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/sale_payment.php';

const PS_TAX_CLASS_TAXABLE = 'TAXABLE';
const PS_TAX_CLASS_ZERO_RATED = 'ZERO_RATED';
const PS_TAX_CLASS_EXEMPT = 'EXEMPT';

const PS_TAX_MODE_INCLUSIVE = 'INCLUSIVE';
const PS_TAX_MODE_EXCLUSIVE = 'EXCLUSIVE';

const PS_TAX_STATUS_COMPLETE = 'COMPLETE';
const PS_TAX_STATUS_LEGACY = 'LEGACY_INCOMPLETE';
const PS_TAX_STATUS_UNCONFIGURED = 'UNCONFIGURED';

const PS_TAX_ENFORCEMENT_OFF = 'OFF';
const PS_TAX_ENFORCEMENT_REQUIRED = 'REQUIRED';

/**
 * @return list<string>
 */
function psTaxClassifications(): array
{
    return [PS_TAX_CLASS_TAXABLE, PS_TAX_CLASS_ZERO_RATED, PS_TAX_CLASS_EXEMPT];
}

/**
 * @return list<string>
 */
function psTaxPricingModes(): array
{
    return [PS_TAX_MODE_INCLUSIVE, PS_TAX_MODE_EXCLUSIVE];
}

function psTaxMayConfigure(): bool
{
    return psBranchSessionIsAdmin();
}

function psTaxMayViewReports(): bool
{
    return psBranchSessionAuthenticated();
}

function psTaxRequireAuthRedirect(string $login = 'login.php'): void
{
    psBranchRequireAuthRedirect($login);
}

function psTaxRequireConfigureRedirect(string $fallback = 'tax_rates.php'): void
{
    if (!psTaxMayConfigure()) {
        header('Location: ' . $fallback);
        exit;
    }
}

function psEnsureTaxRatesSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS tax_rates (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        description VARCHAR(250) NOT NULL DEFAULT '',
        classification VARCHAR(16) NOT NULL,
        rate_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
        pricing_mode VARCHAR(16) NOT NULL,
        effective_from DATE NOT NULL,
        effective_to DATE NULL DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(250) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_by VARCHAR(250) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        company_name VARCHAR(250) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_tax_rate_company_code_from (company_name, code, effective_from),
        KEY idx_tax_rates_code_active (code, is_active),
        KEY idx_tax_rates_effective (effective_from, effective_to),
        KEY idx_tax_rates_company (company_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('tax_rates schema was not created');
        return false;
    }
    if (!psTaxRatesCompanyMigrate($conn)) {
        return false;
    }
    $ready = true;
    psTaxEnsureCompanyHasRates($conn);
    return true;
}

/**
 * The company whose tax rates the caller may see: stand-in user during desktop sync,
 * otherwise the signed-in company.
 */
function psTaxCompany(mysqli $conn): string
{
    static $byActor = [];
    if (function_exists('psActorOverride') && ($a = psActorOverride()) !== null) {
        $key = strtolower($a);
        if (!array_key_exists($key, $byActor)) {
            $stmt = mysqli_prepare($conn, 'SELECT company_name FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
            $co = '';
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $a);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                $row = $r ? mysqli_fetch_assoc($r) : null;
                mysqli_stmt_close($stmt);
                $co = trim((string)($row['company_name'] ?? ''));
            }
            $byActor[$key] = $co;
        }
        return $byActor[$key];
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['the_company'] ?? ''));
}

/** SQL condition limiting tax_rates rows (optionally aliased) to the caller's company. */
function psTaxCompanySql(mysqli $conn, string $alias = '', ?string $company = null): string
{
    $co = $company !== null ? trim($company) : psTaxCompany($conn);
    if ($co === '') {
        $signedIn = session_status() === PHP_SESSION_ACTIVE && trim((string)($_SESSION['logger'] ?? '')) !== '';
        $actor = function_exists('psActorOverride') && psActorOverride() !== null;
        return ($signedIn || $actor) ? '0' : '1';
    }
    $col = ($alias !== '' ? $alias . '.' : '') . 'company_name';
    return $col . " = '" . mysqli_real_escape_string($conn, $co) . "'";
}

/** @return list<string> */
function psTaxRealCompanies(mysqli $conn): array
{
    $names = [];
    $skip = defined('PS_PLATFORM_COMPANY') ? PS_PLATFORM_COMPANY : '__platform__';
    $sources = [];
    $coTable = mysqli_query($conn, 'SELECT 1 FROM companies LIMIT 0');
    if ($coTable) {
        $sources[] = "SELECT DISTINCT TRIM(company_name) AS n FROM companies WHERE company_name IS NOT NULL AND company_name <> ''";
    }
    $sources[] = "SELECT DISTINCT TRIM(company_name) AS n FROM users WHERE company_name IS NOT NULL AND company_name <> ''";
    $hasBranch = mysqli_query($conn, "SHOW COLUMNS FROM branchies LIKE 'company_name'");
    if ($hasBranch && mysqli_num_rows($hasBranch) > 0) {
        $sources[] = "SELECT DISTINCT TRIM(company_name) AS n FROM branchies WHERE company_name IS NOT NULL AND company_name <> ''";
    }
    foreach ($sources as $sql) {
        $res = mysqli_query($conn, $sql);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $n = trim((string)$row['n']);
            if ($n !== '' && $n !== $skip) {
                $names[$n] = true;
            }
        }
    }
    return array_keys($names);
}

/**
 * 2026-10-24 — tax rates belong to one company, same as customers.
 * Existing rows are assigned, then copied per other company so goods/sales tax codes keep resolving.
 */
function psTaxRatesCompanyMigrate(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $cols = [];
    $res = mysqli_query($conn, 'SHOW COLUMNS FROM tax_rates');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cols[strtolower((string)$r['Field'])] = true;
    }
    if (!isset($cols['company_name'])) {
        if (!mysqli_query($conn, "ALTER TABLE tax_rates ADD COLUMN company_name VARCHAR(250) NOT NULL DEFAULT '', ADD KEY idx_tax_rates_company (company_name)")
            && mysqli_errno($conn) !== 1060) {
            error_log('tax_rates: company column was not added: #' . mysqli_errno($conn));
            return false;
        }
    }
    $old = mysqli_query($conn, "SHOW INDEX FROM tax_rates WHERE Key_name = 'uniq_tax_rate_code_from'");
    if ($old && mysqli_num_rows($old) > 0) {
        mysqli_query($conn, 'ALTER TABLE tax_rates DROP INDEX uniq_tax_rate_code_from');
    }
    $companies = psTaxRealCompanies($conn);
    $blank = mysqli_query($conn, "SELECT id FROM tax_rates WHERE company_name = '' LIMIT 1");
    if ($blank && mysqli_num_rows($blank) > 0 && $companies !== []) {
        $template = $companies[0];
        $set = mysqli_prepare($conn, "UPDATE tax_rates SET company_name = ? WHERE company_name = ''");
        if ($set) {
            mysqli_stmt_bind_param($set, 's', $template);
            if (!mysqli_stmt_execute($set)) {
                error_log('tax_rates: company backfill failed: #' . mysqli_errno($conn));
            }
            mysqli_stmt_close($set);
        }
        foreach ($companies as $co) {
            if ($co === $template) {
                continue;
            }
            $have = mysqli_prepare($conn, 'SELECT id FROM tax_rates WHERE company_name = ? LIMIT 1');
            if (!$have) {
                continue;
            }
            mysqli_stmt_bind_param($have, 's', $co);
            mysqli_stmt_execute($have);
            $hr = mysqli_stmt_get_result($have);
            $exists = $hr && mysqli_fetch_assoc($hr);
            mysqli_stmt_close($have);
            if ($exists) {
                continue;
            }
            $copy = mysqli_prepare(
                $conn,
                'INSERT INTO tax_rates
                 (code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
                  is_active, created_by, created_at, updated_by, updated_at, company_name)
                 SELECT code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
                        is_active, created_by, created_at, updated_by, updated_at, ?
                 FROM tax_rates WHERE company_name = ?'
            );
            if ($copy) {
                mysqli_stmt_bind_param($copy, 'ss', $co, $template);
                if (!mysqli_stmt_execute($copy)) {
                    error_log('tax_rates: company copy failed: #' . mysqli_errno($conn));
                }
                mysqli_stmt_close($copy);
            }
        }
    }
    $uniq = mysqli_query($conn, "SHOW INDEX FROM tax_rates WHERE Key_name = 'uniq_tax_rate_company_code_from'");
    if (!$uniq || mysqli_num_rows($uniq) === 0) {
        if (!mysqli_query($conn, 'ALTER TABLE tax_rates ADD UNIQUE KEY uniq_tax_rate_company_code_from (company_name, code, effective_from)')
            && mysqli_errno($conn) !== 1061) {
            error_log('tax_rates: company unique was not added: #' . mysqli_errno($conn));
        }
    }
    $done = true;
    return true;
}

/** Copy catalogue rates into a company that has none yet (new tenant after the first migrate). */
function psTaxEnsureCompanyHasRates(mysqli $conn, ?string $company = null): void
{
    static $checked = [];
    $co = $company !== null ? trim($company) : psTaxCompany($conn);
    if ($co === '' || isset($checked[$co])) {
        return;
    }
    $checked[$co] = true;
    $have = mysqli_prepare($conn, 'SELECT id FROM tax_rates WHERE company_name = ? LIMIT 1');
    if (!$have) {
        return;
    }
    mysqli_stmt_bind_param($have, 's', $co);
    mysqli_stmt_execute($have);
    $hr = mysqli_stmt_get_result($have);
    $exists = $hr && mysqli_fetch_assoc($hr);
    mysqli_stmt_close($have);
    if ($exists) {
        return;
    }
    $src = mysqli_query(
        $conn,
        "SELECT company_name FROM tax_rates WHERE company_name <> '' GROUP BY company_name ORDER BY COUNT(*) DESC LIMIT 1"
    );
    $template = $src ? mysqli_fetch_assoc($src) : null;
    $from = trim((string)($template['company_name'] ?? ''));
    if ($from === '' || $from === $co) {
        return;
    }
    $copy = mysqli_prepare(
        $conn,
        'INSERT INTO tax_rates
         (code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
          is_active, created_by, created_at, updated_by, updated_at, company_name)
         SELECT code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
                is_active, created_by, created_at, updated_by, updated_at, ?
         FROM tax_rates WHERE company_name = ?'
    );
    if ($copy) {
        mysqli_stmt_bind_param($copy, 'ss', $co, $from);
        if (!mysqli_stmt_execute($copy)) {
            error_log('tax_rates: late company copy failed: #' . mysqli_errno($conn));
        }
        mysqli_stmt_close($copy);
    }
}

function psEnsureTaxRateAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS tax_rate_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tax_rate_id INT NULL DEFAULT NULL,
        code VARCHAR(32) NOT NULL DEFAULT '',
        action VARCHAR(32) NOT NULL,
        actor VARCHAR(250) NOT NULL DEFAULT '',
        details TEXT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_tax_rate_audit_code (code),
        KEY idx_tax_rate_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('tax_rate_audit schema was not created');
        return false;
    }
    $cols = [];
    $res = @mysqli_query($conn, 'SHOW COLUMNS FROM tax_rate_audit');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cols[strtolower((string)$r['Field'])] = true;
    }
    if (!isset($cols['company_name'])) {
        if (!mysqli_query($conn, "ALTER TABLE tax_rate_audit ADD COLUMN company_name VARCHAR(250) NOT NULL DEFAULT ''")
            && mysqli_errno($conn) !== 1060) {
            error_log('tax_rate_audit company column was not added');
            return false;
        }
        mysqli_query($conn, "UPDATE tax_rate_audit a
            INNER JOIN users u ON u.email = a.actor
            SET a.company_name = u.company_name
            WHERE a.company_name = '' AND u.company_name <> ''");
    }
    $ready = true;
    return true;
}

function psEnsureTaxSettingsSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS tax_settings (
        setting_key VARCHAR(64) NOT NULL,
        setting_value VARCHAR(250) NOT NULL DEFAULT '',
        updated_by VARCHAR(250) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        company_name VARCHAR(250) NOT NULL DEFAULT '',
        PRIMARY KEY (company_name, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('tax_settings schema was not created');
        return false;
    }
    if (!psTaxSettingsCompanyMigrate($conn)) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    $seed = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO tax_settings (setting_key, setting_value, updated_by, updated_at, company_name)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$seed) {
        error_log('tax_settings seed prepare failed');
        return false;
    }
    $key = 'enforcement';
    $val = PS_TAX_ENFORCEMENT_OFF;
    $by = 'system';
    $co = '';
    mysqli_stmt_bind_param($seed, 'sssss', $key, $val, $by, $now, $co);
    if (!mysqli_stmt_execute($seed)) {
        mysqli_stmt_close($seed);
        error_log('tax_settings seed failed');
        return false;
    }
    mysqli_stmt_close($seed);
    $ready = true;
    return true;
}

function psTaxSettingsCompanyMigrate(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    $cols = [];
    $res = mysqli_query($conn, 'SHOW COLUMNS FROM tax_settings');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cols[strtolower((string)$r['Field'])] = true;
    }
    if (!isset($cols['company_name'])) {
        if (!mysqli_query($conn, "ALTER TABLE tax_settings ADD COLUMN company_name VARCHAR(250) NOT NULL DEFAULT ''")
            && mysqli_errno($conn) !== 1060) {
            error_log('tax_settings: company column was not added');
            return false;
        }
    }
    $pkCols = [];
    $pk = mysqli_query($conn, "SHOW INDEX FROM tax_settings WHERE Key_name = 'PRIMARY'");
    while ($pk && ($r = mysqli_fetch_assoc($pk))) {
        $pkCols[] = strtolower((string)$r['Column_name']);
    }
    if ($pkCols === ['setting_key']) {
        if (!mysqli_query($conn, 'ALTER TABLE tax_settings DROP PRIMARY KEY')
            || (!mysqli_query($conn, 'ALTER TABLE tax_settings ADD PRIMARY KEY (company_name, setting_key)')
                && mysqli_errno($conn) !== 1068 && mysqli_errno($conn) !== 1061)) {
            error_log('tax_settings: company primary key was not updated: #' . mysqli_errno($conn));
            return false;
        }
    }
    foreach (psTaxRealCompanies($conn) as $co) {
        $have = mysqli_prepare($conn, 'SELECT setting_key FROM tax_settings WHERE company_name = ? LIMIT 1');
        if (!$have) {
            continue;
        }
        mysqli_stmt_bind_param($have, 's', $co);
        mysqli_stmt_execute($have);
        $hr = mysqli_stmt_get_result($have);
        $exists = $hr && mysqli_fetch_assoc($hr);
        mysqli_stmt_close($have);
        if ($exists) {
            continue;
        }
        $copy = mysqli_prepare(
            $conn,
            'INSERT IGNORE INTO tax_settings (setting_key, setting_value, updated_by, updated_at, company_name)
             SELECT setting_key, setting_value, updated_by, updated_at, ?
             FROM tax_settings WHERE company_name = \'\''
        );
        if ($copy) {
            mysqli_stmt_bind_param($copy, 's', $co);
            mysqli_stmt_execute($copy);
            mysqli_stmt_close($copy);
        }
    }
    $done = true;
    return true;
}

function psEnsureGoodsTaxCodeColumn(mysqli $conn): bool
{
    return psEnsureColumn($conn, 'goods', 'tax_code', "VARCHAR(32) NULL DEFAULT NULL");
}

function psEnsureSaleTaxColumns(mysqli $conn): bool
{
    $ok = true;
    $ok = psEnsureColumn($conn, 'online_sales', 'tax_code', 'VARCHAR(32) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'tax_classification', 'VARCHAR(16) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'tax_rate_percent', 'DECIMAL(8,4) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'tax_pricing_mode', 'VARCHAR(16) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'discount_amount', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'taxable_amount', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'tax_amount', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'line_total_excl', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'line_total_incl', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales', 'tax_data_status', "VARCHAR(24) NOT NULL DEFAULT 'LEGACY_INCOMPLETE'") && $ok;

    $ok = psEnsureColumn($conn, 'online_sales_ref', 'taxable_amount', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales_ref', 'tax_amount', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales_ref', 'tax_pricing_mode', 'VARCHAR(16) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'online_sales_ref', 'tax_data_status', "VARCHAR(24) NOT NULL DEFAULT 'LEGACY_INCOMPLETE'") && $ok;

    $ok = psEnsureColumn($conn, 'sale_refund_request_lines', 'tax_amount_snapshot', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'sale_refund_request_lines', 'taxable_amount_snapshot', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'sale_refund_request_lines', 'tax_data_status', 'VARCHAR(24) NULL DEFAULT NULL') && $ok;
    $ok = psEnsureColumn($conn, 'sale_refund_requests', 'processed_tax_amount', 'DECIMAL(14,2) NULL DEFAULT NULL') && $ok;

    return $ok;
}

function psEnsureTaxModule(mysqli $conn): bool
{
    return psEnsureTaxRatesSchema($conn)
        && psEnsureTaxRateAuditSchema($conn)
        && psEnsureTaxSettingsSchema($conn)
        && psEnsureGoodsTaxCodeColumn($conn)
        && psEnsureSaleTaxColumns($conn);
}

function psTaxWriteAudit(mysqli $conn, $taxRateId, string $code, string $action, string $actor, array $details): bool
{
    if (!psEnsureTaxRateAuditSchema($conn)) {
        return false;
    }
    $json = json_encode($details, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{}';
    }
    $now = date('Y-m-d H:i:s');
    $id = $taxRateId === null ? null : (int)$taxRateId;
    $company = session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['the_company'] ?? '')) : '';

    if ($id === null) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO tax_rate_audit (tax_rate_id, code, action, actor, details, created_at, company_name)
             VALUES (NULL, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ssssss', $code, $action, $actor, $json, $now, $company);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO tax_rate_audit (tax_rate_id, code, action, actor, details, created_at, company_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'issssss', $id, $code, $action, $actor, $json, $now, $company);
    }
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool)$ok;
}

function psTaxGetSetting(mysqli $conn, string $key, string $default = '', ?string $company = null): string
{
    if (!psEnsureTaxSettingsSchema($conn)) {
        return $default;
    }
    $co = $company !== null ? trim($company) : psTaxCompany($conn);
    $stmt = mysqli_prepare($conn, 'SELECT setting_value FROM tax_settings WHERE setting_key = ? AND company_name = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $key, $co);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $default;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if ($row) {
        return (string)$row['setting_value'];
    }
    if ($co === '') {
        return $default;
    }
    $tmpl = mysqli_prepare($conn, "SELECT setting_value FROM tax_settings WHERE setting_key = ? AND company_name = '' LIMIT 1");
    if (!$tmpl) {
        return $default;
    }
    mysqli_stmt_bind_param($tmpl, 's', $key);
    mysqli_stmt_execute($tmpl);
    $tr = mysqli_stmt_get_result($tmpl);
    $trow = $tr ? mysqli_fetch_assoc($tr) : null;
    mysqli_stmt_close($tmpl);
    if (!$trow) {
        return $default;
    }
    $now = date('Y-m-d H:i:s');
    $ins = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO tax_settings (setting_key, setting_value, updated_by, updated_at, company_name)
         VALUES (?, ?, ?, ?, ?)'
    );
    if ($ins) {
        $by = 'system';
        $val = (string)$trow['setting_value'];
        mysqli_stmt_bind_param($ins, 'sssss', $key, $val, $by, $now, $co);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    }
    return (string)$trow['setting_value'];
}

/**
 * @return array{ok:bool,error?:string}
 */
function psTaxSetSetting(mysqli $conn, string $key, string $value, string $actor): array
{
    if (!psTaxMayConfigure()) {
        return ['ok' => false, 'error' => 'Only an administrator may change tax settings.'];
    }
    if (!psEnsureTaxSettingsSchema($conn)) {
        return ['ok' => false, 'error' => 'Tax settings could not be saved.'];
    }
    $key = trim($key);
    if ($key === '' || strlen($key) > 64) {
        return ['ok' => false, 'error' => 'Invalid tax setting key.'];
    }
    if (strlen($value) > 250) {
        return ['ok' => false, 'error' => 'Tax setting value is too long.'];
    }
    $now = date('Y-m-d H:i:s');
    $co = psTaxCompany($conn);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO tax_settings (setting_key, setting_value, updated_by, updated_at, company_name)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Tax settings could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssss', $key, $value, $actor, $now, $co);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Tax settings could not be saved.'];
    }
    psTaxWriteAudit($conn, null, $key, 'SETTING_UPDATE', $actor, ['key' => $key, 'value' => $value]);
    return ['ok' => true];
}

function psTaxGetEnforcement(mysqli $conn, ?string $company = null): string
{
    $v = strtoupper(trim(psTaxGetSetting($conn, 'enforcement', PS_TAX_ENFORCEMENT_OFF, $company)));
    return $v === PS_TAX_ENFORCEMENT_REQUIRED ? PS_TAX_ENFORCEMENT_REQUIRED : PS_TAX_ENFORCEMENT_OFF;
}

/**
 * @return array{ok:bool,error?:string}
 */
function psTaxSetEnforcement(mysqli $conn, string $mode, string $actor): array
{
    $mode = strtoupper(trim($mode));
    if ($mode !== PS_TAX_ENFORCEMENT_OFF && $mode !== PS_TAX_ENFORCEMENT_REQUIRED) {
        return ['ok' => false, 'error' => 'Enforcement must be OFF or REQUIRED.'];
    }
    return psTaxSetSetting($conn, 'enforcement', $mode, $actor);
}

/**
 * Validate tax rate payload. Rejects invalid config (no silent zero for taxable).
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,row?:array,error?:string}
 */
function psTaxValidateRateInput(array $input, bool $isUpdate = false): array
{
    $code = strtoupper(trim((string)($input['code'] ?? '')));
    if ($code === '' || !preg_match('/^[A-Z0-9_]{1,32}$/', $code)) {
        return ['ok' => false, 'error' => 'Tax code must be 1–32 characters: A–Z, 0–9, underscore.'];
    }
    $description = trim((string)($input['description'] ?? ''));
    if (strlen($description) > 250) {
        return ['ok' => false, 'error' => 'Description is too long.'];
    }
    $classification = strtoupper(trim((string)($input['classification'] ?? '')));
    if (!in_array($classification, psTaxClassifications(), true)) {
        return ['ok' => false, 'error' => 'Classification must be TAXABLE, ZERO_RATED, or EXEMPT.'];
    }
    $pricingMode = strtoupper(trim((string)($input['pricing_mode'] ?? '')));
    if (!in_array($pricingMode, psTaxPricingModes(), true)) {
        return ['ok' => false, 'error' => 'Pricing mode must be INCLUSIVE or EXCLUSIVE.'];
    }
    $rateRaw = trim((string)($input['rate_percent'] ?? ''));
    if ($rateRaw === '' || !preg_match('/^\d{1,4}(\.\d{1,4})?$/', $rateRaw)) {
        return ['ok' => false, 'error' => 'Rate percent is not valid.'];
    }
    $rate = (float)$rateRaw;
    if ($classification === PS_TAX_CLASS_TAXABLE) {
        if ($rate <= 0 || $rate > 100) {
            return ['ok' => false, 'error' => 'TAXABLE rates must be greater than 0 and at most 100.'];
        }
    } else {
        if (abs($rate) > 0.00005) {
            return ['ok' => false, 'error' => 'ZERO_RATED and EXEMPT rates must be 0.'];
        }
        $rate = 0.0;
    }
    $from = trim((string)($input['effective_from'] ?? ''));
    if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return ['ok' => false, 'error' => 'Effective from date is required (YYYY-MM-DD).'];
    }
    $to = trim((string)($input['effective_to'] ?? ''));
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return ['ok' => false, 'error' => 'Effective to date is not valid.'];
    }
    if ($to !== '' && strcmp($to, $from) < 0) {
        return ['ok' => false, 'error' => 'Effective to cannot be before effective from.'];
    }
    $active = !empty($input['is_active']) ? 1 : 0;
    if ($isUpdate && array_key_exists('is_active', $input)) {
        $active = ((int)$input['is_active']) ? 1 : 0;
    }

    return [
        'ok' => true,
        'row' => [
            'code' => $code,
            'description' => $description,
            'classification' => $classification,
            'rate_percent' => number_format($rate, 4, '.', ''),
            'pricing_mode' => $pricingMode,
            'effective_from' => $from,
            'effective_to' => $to === '' ? null : $to,
            'is_active' => $active,
        ],
    ];
}

/**
 * @return array{ok:bool,id?:int,error?:string}
 */
function psTaxCreateRate(mysqli $conn, array $input, string $actor): array
{
    if (!psTaxMayConfigure()) {
        return ['ok' => false, 'error' => 'Only an administrator may manage tax rates.'];
    }
    if (!psEnsureTaxModule($conn)) {
        return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
    }
    $rateCompany = psTaxCompany($conn);
    if ($rateCompany === '') {
        return ['ok' => false, 'error' => 'Select a company before adding tax rates.'];
    }
    $v = psTaxValidateRateInput($input, false);
    if (!$v['ok']) {
        return $v;
    }
    $row = $v['row'];
    $overlap = psTaxFindOverlappingRate(
        $conn,
        $row['code'],
        $row['effective_from'],
        $row['effective_to'],
        null
    );
    if ($overlap) {
        return ['ok' => false, 'error' => 'Effective dates overlap an existing row for this tax code.'];
    }
    $now = date('Y-m-d H:i:s');
    $isActive = (int)$row['is_active'];
    $effTo = $row['effective_to'];
    if ($effTo === null) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO tax_rates
             (code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
              is_active, created_by, created_at, updated_by, updated_at, company_name)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'ssssssisssss',
            $row['code'],
            $row['description'],
            $row['classification'],
            $row['rate_percent'],
            $row['pricing_mode'],
            $row['effective_from'],
            $isActive,
            $actor,
            $now,
            $actor,
            $now,
            $rateCompany
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO tax_rates
             (code, description, classification, rate_percent, pricing_mode, effective_from, effective_to,
              is_active, created_by, created_at, updated_by, updated_at, company_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssssssisssss',
            $row['code'],
            $row['description'],
            $row['classification'],
            $row['rate_percent'],
            $row['pricing_mode'],
            $row['effective_from'],
            $effTo,
            $isActive,
            $actor,
            $now,
            $actor,
            $now,
            $rateCompany
        );
    }
    $ok = mysqli_stmt_execute($stmt);
    $id = (int)mysqli_insert_id($conn);
    $errno = mysqli_errno($conn);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'A tax rate with this code and effective-from already exists.'];
        }
        return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
    }
    psTaxWriteAudit($conn, $id, $row['code'], 'CREATE', $actor, $row);
    return ['ok' => true, 'id' => $id];
}

/**
 * @return array{ok:bool,error?:string}
 */
function psTaxUpdateRate(mysqli $conn, int $id, array $input, string $actor): array
{
    if (!psTaxMayConfigure()) {
        return ['ok' => false, 'error' => 'Only an administrator may manage tax rates.'];
    }
    if (!psEnsureTaxModule($conn) || $id < 1) {
        return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
    }
    $existing = psTaxGetRateById($conn, $id);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Tax rate was not found.'];
    }
    $input['code'] = $existing['code']; // code immutable after create
    $v = psTaxValidateRateInput($input, true);
    if (!$v['ok']) {
        return $v;
    }
    $row = $v['row'];
    $overlap = psTaxFindOverlappingRate(
        $conn,
        $row['code'],
        $row['effective_from'],
        $row['effective_to'],
        $id
    );
    if ($overlap) {
        return ['ok' => false, 'error' => 'Effective dates overlap an existing row for this tax code.'];
    }
    $now = date('Y-m-d H:i:s');
    $isActive = (int)$row['is_active'];
    $effTo = $row['effective_to'];
    if ($effTo === null) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE tax_rates SET description = ?, classification = ?, rate_percent = ?, pricing_mode = ?,
             effective_from = ?, effective_to = NULL, is_active = ?, updated_by = ?, updated_at = ?
             WHERE id = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssssissi',
            $row['description'],
            $row['classification'],
            $row['rate_percent'],
            $row['pricing_mode'],
            $row['effective_from'],
            $isActive,
            $actor,
            $now,
            $id
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE tax_rates SET description = ?, classification = ?, rate_percent = ?, pricing_mode = ?,
             effective_from = ?, effective_to = ?, is_active = ?, updated_by = ?, updated_at = ?
             WHERE id = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'ssssssissi',
            $row['description'],
            $row['classification'],
            $row['rate_percent'],
            $row['pricing_mode'],
            $row['effective_from'],
            $effTo,
            $isActive,
            $actor,
            $now,
            $id
        );
    }
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Tax configuration could not be saved.'];
    }
    $action = 'UPDATE';
    if ((int)$existing['is_active'] === 1 && $isActive === 0) {
        $action = 'DEACTIVATE';
    } elseif ((int)$existing['is_active'] === 0 && $isActive === 1) {
        $action = 'ACTIVATE';
    }
    psTaxWriteAudit($conn, $id, $row['code'], $action, $actor, ['before' => $existing, 'after' => $row]);
    return ['ok' => true];
}

/**
 * @return ?array<string,mixed>
 */
function psTaxGetRateById(mysqli $conn, int $id): ?array
{
    if (!psEnsureTaxRatesSchema($conn) || $id < 1) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM tax_rates WHERE id = ? AND ' . psTaxCompanySql($conn) . ' LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function psTaxListRates(mysqli $conn, bool $activeOnly = false): array
{
    if (!psEnsureTaxRatesSchema($conn)) {
        return [];
    }
    psTaxEnsureCompanyHasRates($conn);
    $sql = 'SELECT * FROM tax_rates WHERE ' . psTaxCompanySql($conn);
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY code ASC, effective_from DESC, id DESC';
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * @return list<array{code:string,description:string}>
 */
function psTaxListDistinctCodes(mysqli $conn, bool $activeOnly = true): array
{
    if (!psEnsureTaxRatesSchema($conn)) {
        return [];
    }
    psTaxEnsureCompanyHasRates($conn);
    $scope = psTaxCompanySql($conn);
    $sql = $activeOnly
        ? 'SELECT code, MAX(description) AS description FROM tax_rates WHERE is_active = 1 AND ' . $scope . ' GROUP BY code ORDER BY code ASC'
        : 'SELECT code, MAX(description) AS description FROM tax_rates WHERE ' . $scope . ' GROUP BY code ORDER BY code ASC';
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = [
                'code' => (string)$row['code'],
                'description' => (string)$row['description'],
            ];
        }
    }
    return $rows;
}

/**
 * Overlap check for same code (exclusive of $excludeId).
 */
function psTaxFindOverlappingRate(
    mysqli $conn,
    string $code,
    string $from,
    ?string $to,
    ?int $excludeId
): bool {
    $toBound = $to === null || $to === '' ? '9999-12-31' : $to;
    $sql = 'SELECT id FROM tax_rates
            WHERE code = ?
              AND ' . psTaxCompanySql($conn) . '
              AND effective_from <= ?
              AND IFNULL(effective_to, \'9999-12-31\') >= ?';
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id <> ' . (int)$excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return true; // fail closed
    }
    mysqli_stmt_bind_param($stmt, 'sss', $code, $toBound, $from);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return true;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (bool)$row;
}

/**
 * Resolve effective rate for code + transaction date (active rows only).
 *
 * @return array{ok:bool,rate?:array,error?:string}
 */
function psTaxResolveEffectiveRate(mysqli $conn, string $code, string $txnDate, ?string $company = null): array
{
    $code = strtoupper(trim($code));
    $txnDate = trim($txnDate);
    if ($code === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) {
        return ['ok' => false, 'error' => 'Tax code or transaction date is not valid.'];
    }
    if (!psEnsureTaxRatesSchema($conn)) {
        return ['ok' => false, 'error' => 'Tax configuration is not available.'];
    }
    psTaxEnsureCompanyHasRates($conn, $company);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM tax_rates
         WHERE code = ? AND is_active = 1 AND ' . psTaxCompanySql($conn, '', $company) . '
           AND effective_from <= ?
           AND (effective_to IS NULL OR effective_to >= ?)
         ORDER BY effective_from DESC, id DESC
         LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Tax configuration is not available.'];
    }
    mysqli_stmt_bind_param($stmt, 'sss', $code, $txnDate, $txnDate);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Tax configuration is not available.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['ok' => false, 'error' => 'No active tax rate is effective for code ' . $code . ' on ' . $txnDate . '.'];
    }
    return ['ok' => true, 'rate' => $row];
}

/**
 * Compute tax for one line after discount.
 * Order: discounts → taxable base → rounding (half-up to cents).
 *
 * @param array<string,mixed> $rateRow
 * @return array{ok:bool,error?:string,tax_code?:string,classification?:string,rate_percent?:string,pricing_mode?:string,discount_amount?:string,taxable_amount?:string,tax_amount?:string,line_total_excl?:string,line_total_incl?:string,payable_cents?:int,tax_data_status?:string}
 */
function psTaxComputeLine(array $rateRow, int $grossOrNetCentsBeforeTaxMeaning, int $discountCents = 0): array
{
    if ($discountCents < 0) {
        return ['ok' => false, 'error' => 'Discount cannot be negative.'];
    }
    $classification = strtoupper(trim((string)($rateRow['classification'] ?? '')));
    $mode = strtoupper(trim((string)($rateRow['pricing_mode'] ?? '')));
    $ratePercent = number_format((float)($rateRow['rate_percent'] ?? 0), 4, '.', '');
    $code = strtoupper(trim((string)($rateRow['code'] ?? '')));
    if (!in_array($classification, psTaxClassifications(), true) || !in_array($mode, psTaxPricingModes(), true) || $code === '') {
        return ['ok' => false, 'error' => 'Tax rate configuration is not valid.'];
    }
    if ($grossOrNetCentsBeforeTaxMeaning < 0) {
        return ['ok' => false, 'error' => 'Line amount is not valid.'];
    }
    if ($discountCents > $grossOrNetCentsBeforeTaxMeaning) {
        return ['ok' => false, 'error' => 'Discount cannot exceed the line amount.'];
    }
    $baseCents = $grossOrNetCentsBeforeTaxMeaning - $discountCents;
    $rate = (float)$ratePercent;

    $taxCents = 0;
    $exclCents = 0;
    $inclCents = 0;
    $taxableCents = 0;

    if ($classification === PS_TAX_CLASS_EXEMPT) {
        $taxCents = 0;
        $taxableCents = 0;
        if ($mode === PS_TAX_MODE_INCLUSIVE) {
            $inclCents = $baseCents;
            $exclCents = $baseCents;
        } else {
            $exclCents = $baseCents;
            $inclCents = $baseCents;
        }
    } elseif ($classification === PS_TAX_CLASS_ZERO_RATED) {
        $taxCents = 0;
        if ($mode === PS_TAX_MODE_INCLUSIVE) {
            $inclCents = $baseCents;
            $exclCents = $baseCents;
            $taxableCents = $baseCents; // zero-rated turnover base
        } else {
            $exclCents = $baseCents;
            $inclCents = $baseCents;
            $taxableCents = $baseCents;
        }
    } else {
        // TAXABLE
        if ($rate <= 0) {
            return ['ok' => false, 'error' => 'TAXABLE rate must be greater than zero.'];
        }
        if ($mode === PS_TAX_MODE_INCLUSIVE) {
            $inclCents = $baseCents;
            $taxCents = (int)round($inclCents * $rate / (100.0 + $rate));
            $exclCents = $inclCents - $taxCents;
            $taxableCents = $exclCents;
        } else {
            $exclCents = $baseCents;
            $taxCents = (int)round($exclCents * $rate / 100.0);
            $inclCents = $exclCents + $taxCents;
            $taxableCents = $exclCents;
        }
    }

    return [
        'ok' => true,
        'tax_code' => $code,
        'classification' => $classification,
        'rate_percent' => $ratePercent,
        'pricing_mode' => $mode,
        'discount_amount' => psMoneyFromCents($discountCents),
        'taxable_amount' => psMoneyFromCents($taxableCents),
        'tax_amount' => psMoneyFromCents($taxCents),
        'line_total_excl' => psMoneyFromCents($exclCents),
        'line_total_incl' => psMoneyFromCents($inclCents),
        'payable_cents' => $inclCents,
        'tax_data_status' => PS_TAX_STATUS_COMPLETE,
    ];
}

/**
 * Apply tax to cart lines built from authoritative prices.
 * Mutates/extends each line; recomputes payable total.
 *
 * @param list<array<string,mixed>> $lines
 * @return array{ok:bool,lines?:list,total?:string,total_cents?:int,taxable_amount?:string,tax_amount?:string,tax_data_status?:string,tax_pricing_mode?:?string,error?:string}
 */
function psTaxApplyToSaleLines(mysqli $conn, array $lines, string $branch, string $txnDate): array
{
    if (!psEnsureTaxModule($conn)) {
        return ['ok' => false, 'error' => 'Tax configuration is not available.'];
    }
    $enforcement = psTaxGetEnforcement($conn);
    $out = [];
    $totalPayable = 0;
    $totalTaxable = 0;
    $totalTax = 0;
    $statuses = [];
    $modes = [];

    foreach ($lines as $line) {
        $productId = trim((string)($line['product_id'] ?? ''));
        $unitCents = psDecimalCents((string)($line['price'] ?? ''));
        $qty = (string)($line['quantity'] ?? '');
        if ($productId === '' || $unitCents === null || $unitCents < 0) {
            return ['ok' => false, 'error' => 'A product price is not valid.'];
        }
        // 2026-10-28 packs: a line sold in a pack carries its exact amount (packs × pack price) in pre_cents;
        // its quantity is in base units and its price per base unit is rounded, so price × quantity is not used.
        $preDiscountCents = isset($line['pre_cents']) ? (int)$line['pre_cents'] : psLineCents($unitCents, $qty);
        if ($preDiscountCents === null || $preDiscountCents < 0) {
            return ['ok' => false, 'error' => 'Sale total is not valid.'];
        }
        // Discount order reserved: cart discounts not currently posted by POS UI.
        $discountCents = 0;
        if (isset($line['discount_cents'])) {
            $discountCents = (int)$line['discount_cents'];
        }

        $taxCode = '';
        if (isset($line['tax_code']) && trim((string)$line['tax_code']) !== '') {
            $taxCode = strtoupper(trim((string)$line['tax_code']));
        } else {
            $taxCode = psTaxProductCode($conn, $productId, $branch);
        }

        if ($taxCode === '') {
            if ($enforcement === PS_TAX_ENFORCEMENT_REQUIRED) {
                return [
                    'ok' => false,
                    'error' => 'Tax code is required for product ' . ($line['product'] ?? $productId) . '. Assign a tax code or set enforcement to OFF.',
                ];
            }
            // Unconfigured: payable = pre-discount line (no invented tax).
            $payable = $preDiscountCents - $discountCents;
            if ($payable < 0) {
                return ['ok' => false, 'error' => 'Sale total is not valid.'];
            }
            $statuses[] = PS_TAX_STATUS_UNCONFIGURED;
            $out[] = array_merge($line, [
                'price' => psMoneyFromCents($unitCents),
                'revenue' => psMoneyFromCents($payable),
                'tax_code' => null,
                'tax_classification' => null,
                'tax_rate_percent' => null,
                'tax_pricing_mode' => null,
                'discount_amount' => psMoneyFromCents($discountCents),
                'taxable_amount' => null,
                'tax_amount' => null,
                'line_total_excl' => null,
                'line_total_incl' => null,
                'tax_data_status' => PS_TAX_STATUS_UNCONFIGURED,
            ]);
            $totalPayable += $payable;
            continue;
        }

        $resolved = psTaxResolveEffectiveRate($conn, $taxCode, $txnDate);
        if (!$resolved['ok']) {
            return ['ok' => false, 'error' => $resolved['error']];
        }
        $computed = psTaxComputeLine($resolved['rate'], $preDiscountCents, $discountCents);
        if (!$computed['ok']) {
            return ['ok' => false, 'error' => $computed['error']];
        }
        $statuses[] = PS_TAX_STATUS_COMPLETE;
        $modes[] = (string)$computed['pricing_mode'];
        $payable = (int)$computed['payable_cents'];
        $totalPayable += $payable;
        $totalTaxable += (int)psDecimalCents((string)$computed['taxable_amount']);
        $totalTax += (int)psDecimalCents((string)$computed['tax_amount']);
        $out[] = array_merge($line, [
            'price' => psMoneyFromCents($unitCents),
            'revenue' => psMoneyFromCents($payable),
            'tax_code' => $computed['tax_code'],
            'tax_classification' => $computed['classification'],
            'tax_rate_percent' => $computed['rate_percent'],
            'tax_pricing_mode' => $computed['pricing_mode'],
            'discount_amount' => $computed['discount_amount'],
            'taxable_amount' => $computed['taxable_amount'],
            'tax_amount' => $computed['tax_amount'],
            'line_total_excl' => $computed['line_total_excl'],
            'line_total_incl' => $computed['line_total_incl'],
            'tax_data_status' => PS_TAX_STATUS_COMPLETE,
        ]);
    }

    $headerStatus = PS_TAX_STATUS_COMPLETE;
    if (in_array(PS_TAX_STATUS_UNCONFIGURED, $statuses, true)) {
        $headerStatus = in_array(PS_TAX_STATUS_COMPLETE, $statuses, true)
            ? 'MIXED'
            : PS_TAX_STATUS_UNCONFIGURED;
    }
    $headerMode = null;
    $uniqModes = array_values(array_unique($modes));
    if (count($uniqModes) === 1) {
        $headerMode = $uniqModes[0];
    } elseif (count($uniqModes) > 1) {
        $headerMode = 'MIXED';
    }

    return [
        'ok' => true,
        'lines' => $out,
        'total' => psMoneyFromCents($totalPayable),
        'total_cents' => $totalPayable,
        'taxable_amount' => $headerStatus === PS_TAX_STATUS_COMPLETE ? psMoneyFromCents($totalTaxable) : null,
        'tax_amount' => $headerStatus === PS_TAX_STATUS_COMPLETE ? psMoneyFromCents($totalTax) : null,
        'tax_data_status' => $headerStatus,
        'tax_pricing_mode' => $headerMode,
    ];
}

function psTaxProductCode(mysqli $conn, string $productId, string $branch): string
{
    if (!psEnsureGoodsTaxCodeColumn($conn)) {
        return '';
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT tax_code FROM goods WHERE product_id = ? AND branch = ? LIMIT 1'
    );
    if (!$stmt) {
        return '';
    }
    mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return '';
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return strtoupper(trim((string)($row['tax_code'] ?? '')));
}

/**
 * @return array{ok:bool,error?:string}
 */
function psTaxSetProductCode(mysqli $conn, int $goodsId, string $branch, $taxCode, string $actor): array
{
    if (!psTaxMayConfigure() && !psBranchSessionAuthenticated()) {
        return ['ok' => false, 'error' => 'You must be signed in.'];
    }
    // Product tax assignment: signed-in staff on authorised branch (same as product edit).
    if (!psEnsureTaxModule($conn)) {
        return ['ok' => false, 'error' => 'Tax code could not be saved.'];
    }
    $branch = trim($branch);
    $code = $taxCode === null || trim((string)$taxCode) === ''
        ? null
        : strtoupper(trim((string)$taxCode));
    if ($code !== null) {
        if (!preg_match('/^[A-Z0-9_]{1,32}$/', $code)) {
            return ['ok' => false, 'error' => 'Tax code is not valid.'];
        }
        // Must exist as a configured code (any row); effectiveness checked at sale time.
        $chk = mysqli_prepare($conn, 'SELECT id FROM tax_rates WHERE code = ? AND ' . psTaxCompanySql($conn) . ' LIMIT 1');
        if (!$chk) {
            return ['ok' => false, 'error' => 'Tax code could not be saved.'];
        }
        mysqli_stmt_bind_param($chk, 's', $code);
        mysqli_stmt_execute($chk);
        $cres = mysqli_stmt_get_result($chk);
        $crow = $cres ? mysqli_fetch_assoc($cres) : null;
        mysqli_stmt_close($chk);
        if (!$crow) {
            return ['ok' => false, 'error' => 'Tax code is not configured. Create it under Tax Rates first.'];
        }
    }
    if ($goodsId < 1 || $branch === '') {
        return ['ok' => false, 'error' => 'Product was not found.'];
    }
    if ($code === null) {
        $stmt = mysqli_prepare($conn, 'UPDATE goods SET tax_code = NULL WHERE id = ? AND branch = ?');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Tax code could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 'is', $goodsId, $branch);
    } else {
        $stmt = mysqli_prepare($conn, 'UPDATE goods SET tax_code = ? WHERE id = ? AND branch = ?');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Tax code could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 'sis', $code, $goodsId, $branch);
    }
    $ok = mysqli_stmt_execute($stmt);
    $aff = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok || $aff < 0) {
        return ['ok' => false, 'error' => 'Tax code could not be saved.'];
    }
    psTaxWriteAudit($conn, null, $code ?? '', 'PRODUCT_ASSIGN', $actor, [
        'goods_id' => $goodsId,
        'branch' => $branch,
        'tax_code' => $code,
    ]);
    return ['ok' => true];
}

/**
 * Proportional tax for a partial refund of a sale line (uses stored amounts only).
 *
 * @param array<string,mixed> $saleLine
 * @return array{ok:bool,legacy?:bool,payable_cents?:int,tax_cents?:int,taxable_cents?:int,tax_data_status?:string,error?:string}
 */
function psTaxRefundLineAllocation(array $saleLine, string $refundQty): array
{
    $status = strtoupper(trim((string)($saleLine['tax_data_status'] ?? PS_TAX_STATUS_LEGACY)));
    $soldQty = trim((string)($saleLine['quantity'] ?? ''));
    $soldDec = function_exists('psStockDecimal') ? psStockDecimal($soldQty) : null;
    $refDec = function_exists('psStockDecimal') ? psStockDecimal($refundQty) : null;
    if ($soldDec === null || $refDec === null || psStockDecimalCmp($refDec, '0') <= 0) {
        return ['ok' => false, 'error' => 'Refund quantity is not valid.'];
    }
    if (psStockDecimalCmp($refDec, $soldDec) > 0) {
        return ['ok' => false, 'error' => 'Refund quantity exceeds sold quantity.'];
    }

    if ($status !== PS_TAX_STATUS_COMPLETE) {
        // Legacy / unconfigured: do not invent tax; payable via existing unit_price×qty path.
        return [
            'ok' => true,
            'legacy' => true,
            'tax_cents' => null,
            'taxable_cents' => null,
            'tax_data_status' => $status === '' ? PS_TAX_STATUS_LEGACY : $status,
        ];
    }

    $inclCents = psDecimalCents((string)($saleLine['line_total_incl'] ?? $saleLine['revenue'] ?? ''));
    $taxCentsOrig = psDecimalCents((string)($saleLine['tax_amount'] ?? ''));
    $taxableOrig = psDecimalCents((string)($saleLine['taxable_amount'] ?? ''));
    if ($inclCents === null || $inclCents < 0 || $taxCentsOrig === null || $taxCentsOrig < 0 || $taxableOrig === null || $taxableOrig < 0) {
        return [
            'ok' => true,
            'legacy' => true,
            'tax_cents' => null,
            'taxable_cents' => null,
            'tax_data_status' => PS_TAX_STATUS_LEGACY,
            'error' => null,
        ];
    }

    // Quantity proportion via milli (3dp) for stability.
    $soldMilli = function_exists('psStockMilli') ? psStockMilli($soldQty) : null;
    $refMilli = function_exists('psStockMilli') ? psStockMilli($refundQty) : null;
    if ($soldMilli === null || $refMilli === null || $soldMilli <= 0) {
        return ['ok' => false, 'error' => 'Refund quantity is not valid.'];
    }
    if ($refMilli === $soldMilli) {
        $pay = $inclCents;
        $tax = $taxCentsOrig;
        $taxable = $taxableOrig;
    } else {
        $pay = (int)round($inclCents * $refMilli / $soldMilli);
        $tax = (int)round($taxCentsOrig * $refMilli / $soldMilli);
        $taxable = (int)round($taxableOrig * $refMilli / $soldMilli);
        if ($tax > $taxCentsOrig) {
            $tax = $taxCentsOrig;
        }
        if ($taxable > $taxableOrig) {
            $taxable = $taxableOrig;
        }
        if ($pay > $inclCents) {
            $pay = $inclCents;
        }
    }

    return [
        'ok' => true,
        'legacy' => false,
        'payable_cents' => $pay,
        'tax_cents' => $tax,
        'taxable_cents' => $taxable,
        'tax_data_status' => PS_TAX_STATUS_COMPLETE,
    ];
}

/**
 * Sum PROCESSED tax already refunded for a sale line.
 */
function psTaxProcessedRefundTaxCents(mysqli $conn, int $saleLineId): int
{
    if ($saleLineId < 1 || !psEnsureSaleTaxColumns($conn)) {
        return 0;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(l.tax_amount_snapshot), 0) AS t
         FROM sale_refund_request_lines l
         INNER JOIN sale_refund_requests r ON r.id = l.request_id
         WHERE l.sale_line_id = ? AND r.status = 'PROCESSED'
           AND l.tax_amount_snapshot IS NOT NULL"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $saleLineId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return 0;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $cents = psDecimalCents((string)($row['t'] ?? '0'));
    return $cents === null ? 0 : max(0, $cents);
}

/**
 * Classify a sale header for tax reporting (stored values only).
 */
function psTaxHeaderStatusFromRow(array $header): string
{
    $s = strtoupper(trim((string)($header['tax_data_status'] ?? '')));
    if ($s === '') {
        return PS_TAX_STATUS_LEGACY;
    }
    return $s;
}

/**
 * @return list<array<string,mixed>>
 */
function psTaxListAudit(mysqli $conn, int $limit = 100): array
{
    if (!psEnsureTaxRateAuditSchema($conn)) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $super = function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin()
        && !(function_exists('psPlatformIsActing') && psPlatformIsActing());
    if ($super) {
        $res = mysqli_query($conn, 'SELECT * FROM tax_rate_audit ORDER BY id DESC LIMIT ' . (int)$limit);
    } else {
        $company = session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['the_company'] ?? '')) : '';
        if ($company === '') {
            return [];
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM tax_rate_audit WHERE company_name = ? ORDER BY id DESC LIMIT ' . (int)$limit);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 's', $company);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    }
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    if (isset($stmt) && $stmt) {
        mysqli_stmt_close($stmt);
    }
    return $rows;
}
