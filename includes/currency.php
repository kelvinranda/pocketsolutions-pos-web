<?php
/**
 * 2026-10-20 — Multi-currency core (web; the desktop till mirrors the same rules in C#).
 *
 * Model
 *  - currencies            Platform catalogue (Super Admin): ISO 4217 code, name, symbol, decimals (0-2), symbol position, ACTIVE/DISABLED.
 *  - company_base_currency The company's base (reporting/accounting) currency. Set by a Super Admin.
 *                          A company that never chose one uses the legacy currency (config 'legacy_currency', default USD).
 *  - company_currencies    Which catalogue currencies a company may transact in (Super Admin). The base is always allowed.
 *  - exchange_rates        Company rates, append-only: "1 FROM = rate TO" from effective_from. Never edited; a newer row
 *                          supersedes, RETIRED stops future use. Rows used by posted transactions stay forever.
 *  - currency_audit        Every catalogue, base, enablement and rate change (before/after, actor, time).
 *
 * Rates and arithmetic
 *  - Rate lookup at a moment t: the latest ACTIVE row with effective_from <= t for FROM->TO (direct) or TO->FROM (used as 1/rate);
 *    the most recent of the two wins; if both start at the same moment and disagree by more than 0.01% the lookup fails (conflict).
 *    No direct/inverse rate: one hop through the company base currency (FROM->BASE->TO). Otherwise: missing rate, never a guess.
 *  - All money is integer cents (every money column stores 2 decimals). Rates are exact decimals (up to 8 places) held as fractions;
 *    conversion = round_half_up(cents * num / den), then rounded to the target currency's decimals (0, 1 or 2). No floats.
 *
 * Transactions (see docs/MULTI_CURRENCY.md)
 *  - A transaction's existing amount columns hold amounts in ITS currency; it also stores currency, the rate snapshot
 *    (1 BASE = fx_rate TXN, rate ids, path) and the base-currency total, computed once at posting.
 *  - Records from before this feature have no currency: they are treated as the legacy currency (the system was USD-only)
 *    and reported as "legacy", never rewritten.
 */

require_once __DIR__ . '/tx_nesting.php';

if (!defined('PS_FX_DEFAULT_LEGACY')) {
    define('PS_FX_DEFAULT_LEGACY', 'USD');
    define('PS_FX_RATE_SCALE', 8);          // max decimal places of a rate
    define('PS_FX_RATE_MAX', '999999999');  // rates must be below 1e9
}

// ============================================================ exact decimal helpers (non-negative digit strings)

function psFxBigNorm(string $a): string
{
    $a = ltrim($a, '0');
    return $a === '' ? '0' : $a;
}

function psFxBigCmp(string $a, string $b): int
{
    $a = psFxBigNorm($a);
    $b = psFxBigNorm($b);
    if (strlen($a) !== strlen($b)) {
        return strlen($a) < strlen($b) ? -1 : 1;
    }
    return $a <=> $b;
}

function psFxBigAdd(string $a, string $b): string
{
    $a = strrev($a);
    $b = strrev($b);
    $out = '';
    $carry = 0;
    $n = max(strlen($a), strlen($b));
    for ($i = 0; $i < $n; $i++) {
        $s = (int)($a[$i] ?? '0') + (int)($b[$i] ?? '0') + $carry;
        $out .= (string)($s % 10);
        $carry = intdiv($s, 10);
    }
    if ($carry) {
        $out .= (string)$carry;
    }
    return psFxBigNorm(strrev($out));
}

/** $a - $b where $a >= $b. */
function psFxBigSub(string $a, string $b): string
{
    $a = strrev(psFxBigNorm($a));
    $b = strrev(psFxBigNorm($b));
    $out = '';
    $borrow = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $d = (int)$a[$i] - (int)($b[$i] ?? '0') - $borrow;
        $borrow = $d < 0 ? 1 : 0;
        $out .= (string)($d + ($borrow ? 10 : 0));
    }
    return psFxBigNorm(strrev($out));
}

function psFxBigMul(string $a, string $b): string
{
    $a = psFxBigNorm($a);
    $b = psFxBigNorm($b);
    if ($a === '0' || $b === '0') {
        return '0';
    }
    $ra = array_map('intval', str_split(strrev($a)));
    $rb = array_map('intval', str_split(strrev($b)));
    $res = array_fill(0, count($ra) + count($rb), 0);
    foreach ($ra as $i => $da) {
        $carry = 0;
        foreach ($rb as $j => $db) {
            $t = $res[$i + $j] + $da * $db + $carry;
            $res[$i + $j] = $t % 10;
            $carry = intdiv($t, 10);
        }
        $k = $i + count($rb);
        while ($carry) {
            $t = $res[$k] + $carry;
            $res[$k] = $t % 10;
            $carry = intdiv($t, 10);
            $k++;
        }
    }
    return psFxBigNorm(strrev(implode('', $res)));
}

/** [quotient, remainder] of $n / $d (both digit strings, $d > 0). Schoolbook long division. */
function psFxBigDivMod(string $n, string $d): array
{
    $n = psFxBigNorm($n);
    $d = psFxBigNorm($d);
    $q = '';
    $r = '0';
    for ($i = 0, $len = strlen($n); $i < $len; $i++) {
        $r = psFxBigNorm($r . $n[$i]);
        $digit = 0;
        while (psFxBigCmp($r, $d) >= 0) {
            $r = psFxBigSub($r, $d);
            $digit++;
        }
        $q .= (string)$digit;
    }
    return [psFxBigNorm($q), $r];
}

/** round_half_up($n / $d). */
function psFxBigDivRound(string $n, string $d): string
{
    [$q, $r] = psFxBigDivMod($n, $d);
    if (psFxBigCmp(psFxBigMul($r, '2'), $d) >= 0) {
        $q = psFxBigAdd($q, '1');
    }
    return $q;
}

/**
 * Validate a positive decimal rate string; returns [num, den] (1 unit = num/den) or null.
 * At most PS_FX_RATE_SCALE decimals and below PS_FX_RATE_MAX.
 */
function psFxParseRate(string $raw, int $scale = PS_FX_RATE_SCALE): ?array
{
    $raw = trim(str_replace([',', ' '], '', $raw));
    if (!preg_match('/^(\d{1,9})(?:\.(\d{1,' . $scale . '}))?$/', $raw, $m)) {
        return null;
    }
    $int = $m[1];
    $frac = $m[2] ?? '';
    $num = psFxBigNorm($int . $frac);
    if ($num === '0') {
        return null;
    }
    $den = '1' . str_repeat('0', strlen($frac));
    if (psFxBigCmp($int, PS_FX_RATE_MAX) > 0) {
        return null;
    }
    return [$num, $den];
}

/** Fraction -> decimal string with $scale places (half-up). */
function psFxFracToDecimal(array $frac, int $scale = PS_FX_RATE_SCALE): string
{
    $scaled = psFxBigDivRound(psFxBigMul($frac[0], '1' . str_repeat('0', $scale)), $frac[1]);
    $scaled = str_pad($scaled, $scale + 1, '0', STR_PAD_LEFT);
    $int = substr($scaled, 0, -$scale);
    $dec = rtrim(substr($scaled, -$scale), '0');
    return $dec === '' ? $int : $int . '.' . $dec;
}

function psFxFracMul(array $a, array $b): array
{
    return [psFxBigMul($a[0], $b[0]), psFxBigMul($a[1], $b[1])];
}

function psFxFracInvert(array $a): array
{
    return [$a[1], $a[0]];
}

/** Round integer cents to a currency's decimals (2 = unchanged, 1 = nearest 10 cents, 0 = whole units), half away from zero. */
function psFxRoundCentsToDecimals(int $cents, int $decimals): int
{
    if ($decimals >= 2) {
        return $cents;
    }
    $step = $decimals === 1 ? 10 : 100;
    $sign = $cents < 0 ? -1 : 1;
    $abs = abs($cents);
    $rounded = intdiv($abs + intdiv($step, 2), $step) * $step;
    return $sign * $rounded;
}

/** Convert integer cents with a rate fraction (1 FROM = num/den TO) into TO cents rounded to $toDecimals. */
function psFxConvertCents(int $cents, array $frac, int $toDecimals = 2): int
{
    $sign = $cents < 0 ? -1 : 1;
    $v = psFxBigDivRound(psFxBigMul((string)abs($cents), $frac[0]), $frac[1]);
    if (strlen($v) > 17) {
        throw new RuntimeException('Converted amount is too large.');
    }
    return psFxRoundCentsToDecimals($sign * (int)$v, $toDecimals);
}

// ============================================================ schema

function psFxEnsureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sqls = [
        "CREATE TABLE IF NOT EXISTS currencies (
            code CHAR(3) NOT NULL PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            symbol VARCHAR(8) NOT NULL DEFAULT '',
            decimals TINYINT NOT NULL DEFAULT 2,
            symbol_position VARCHAR(6) NOT NULL DEFAULT 'before',
            status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE',
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS company_base_currency (
            company_name VARCHAR(250) NOT NULL PRIMARY KEY,
            base_code CHAR(3) NOT NULL,
            set_by VARCHAR(250) NOT NULL DEFAULT '',
            set_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS company_currencies (
            company_name VARCHAR(250) NOT NULL,
            code CHAR(3) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (company_name, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS exchange_rates (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL,
            from_code CHAR(3) NOT NULL,
            to_code CHAR(3) NOT NULL,
            rate DECIMAL(20,8) NOT NULL,
            effective_from DATETIME NOT NULL,
            source VARCHAR(120) NOT NULL DEFAULT '',
            note VARCHAR(250) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE',
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            retired_by VARCHAR(250) NULL,
            retired_at DATETIME NULL,
            KEY idx_fx_pair (company_name, from_code, to_code, effective_from),
            UNIQUE KEY uniq_fx_pair_start (company_name, from_code, to_code, effective_from, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS currency_audit (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NULL,
            entity VARCHAR(20) NOT NULL,
            entity_key VARCHAR(60) NOT NULL,
            action VARCHAR(40) NOT NULL,
            before_json TEXT NULL,
            after_json TEXT NULL,
            actor VARCHAR(250) NOT NULL DEFAULT '',
            at DATETIME NOT NULL,
            KEY idx_cur_audit_company (company_name, at),
            KEY idx_cur_audit_entity (entity, entity_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sqls as $sql) {
        if (!mysqli_query($conn, $sql)) {
            error_log('currency schema failed: #' . mysqli_errno($conn));
            return false;
        }
    }
    // Starter catalogue (only when empty). Super Admins edit or add others; nothing is enabled for a company by this.
    $res = mysqli_query($conn, 'SELECT COUNT(*) AS n FROM currencies');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($row && (int)$row['n'] === 0) {
        $now = date('Y-m-d H:i:s');
        foreach ([
            ['USD', 'US Dollar', '$', 2, 'before'],
            ['ZWG', 'Zimbabwe Gold', 'ZiG', 2, 'before'],
            ['ZAR', 'South African Rand', 'R', 2, 'before'],
        ] as [$c, $n, $s, $d, $p]) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO currencies (code, name, symbol, decimals, symbol_position, status, created_by, created_at, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, 'ACTIVE', 'system', ?, 'system', ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sssisss', $c, $n, $s, $d, $p, $now, $now);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }
    $ready = true;
    return true;
}

// ============================================================ audit / identity

function psFxActor(): string
{
    if (function_exists('psActorOverride') && ($a = psActorOverride()) !== null) {
        return $a;
    }
    return session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['logger'] ?? '')) : '';
}

function psFxAudit(mysqli $conn, ?string $company, string $entity, string $key, string $action, $before, $after): void
{
    $actor = psFxActor();
    $now = date('Y-m-d H:i:s');
    $b = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE);
    $a = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE);
    $stmt = mysqli_prepare($conn, 'INSERT INTO currency_audit (company_name, entity, entity_key, action, before_json, after_json, actor, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        error_log('currency audit prepare failed');
        return;
    }
    mysqli_stmt_bind_param($stmt, 'ssssssss', $company, $entity, $key, $action, $b, $a, $actor, $now);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('currency audit insert failed');
    }
    mysqli_stmt_close($stmt);
}

function psFxAuditRows(mysqli $conn, ?string $company = null, int $limit = 100): array
{
    if (!psFxEnsureSchema($conn)) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    if ($company === null) {
        $res = mysqli_query($conn, "SELECT * FROM currency_audit ORDER BY id DESC LIMIT $limit");
    } else {
        $c = mysqli_real_escape_string($conn, $company);
        $res = mysqli_query($conn, "SELECT * FROM currency_audit WHERE company_name = '$c' ORDER BY id DESC LIMIT $limit");
    }
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

function psFxSessionIsSuperAdmin(): bool
{
    require_once __DIR__ . '/platform.php';
    return function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin();
}

/** Company admins and managers (and a Super Admin managing the company) maintain exchange rates. */
function psFxMayManageRates(): bool
{
    if (psFxSessionIsSuperAdmin()) {
        return true;
    }
    $level = strtolower(trim((string)($_SESSION['level'] ?? '')));
    return in_array($level, ['admin', 'management'], true);
}

function psFxSessionCompany(): string
{
    return session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['the_company'] ?? '')) : '';
}

// ============================================================ catalogue (Super Admin)

function psFxNormalizeCode($raw): string
{
    return strtoupper(trim((string)$raw));
}

function psFxCurrency(mysqli $conn, string $code): ?array
{
    if (!psFxEnsureSchema($conn)) {
        return null;
    }
    $code = psFxNormalizeCode($code);
    $stmt = mysqli_prepare($conn, 'SELECT * FROM currencies WHERE code = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function psFxCurrencies(mysqli $conn, bool $activeOnly = false): array
{
    if (!psFxEnsureSchema($conn)) {
        return [];
    }
    $res = mysqli_query($conn, 'SELECT * FROM currencies' . ($activeOnly ? " WHERE status = 'ACTIVE'" : '') . ' ORDER BY code');
    $out = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[$r['code']] = $r;
    }
    return $out;
}

/** Add or edit a catalogue currency (Super Admin). The code is the identity and cannot be renamed. */
function psFxCurrencySave(mysqli $conn, array $in, bool $isNew): array
{
    if (!psFxSessionIsSuperAdmin()) {
        return ['ok' => false, 'error' => 'Only a Super Admin can manage currencies.'];
    }
    if (!psFxEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Currencies are not available right now.'];
    }
    $code = psFxNormalizeCode($in['code'] ?? '');
    if (!preg_match('/^[A-Z]{3}$/', $code)) {
        return ['ok' => false, 'error' => 'Enter the 3-letter ISO 4217 code, e.g. USD or ZWG.'];
    }
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'Enter the currency name (up to 80 characters).'];
    }
    $symbol = trim((string)($in['symbol'] ?? ''));
    if (mb_strlen($symbol) > 8) {
        return ['ok' => false, 'error' => 'The symbol can be at most 8 characters.'];
    }
    $decimals = (string)($in['decimals'] ?? '2');
    if (!in_array($decimals, ['0', '1', '2'], true)) {
        return ['ok' => false, 'error' => 'Decimal places must be 0, 1 or 2 (amounts are stored with 2 decimals).'];
    }
    $decimals = (int)$decimals;
    $pos = (string)($in['symbol_position'] ?? 'before') === 'after' ? 'after' : 'before';
    $existing = psFxCurrency($conn, $code);
    $now = date('Y-m-d H:i:s');
    $actor = psFxActor();
    if ($isNew) {
        if ($existing) {
            return ['ok' => false, 'error' => "Currency $code already exists."];
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO currencies (code, name, symbol, decimals, symbol_position, status, created_by, created_at, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sssisssss', $code, $name, $symbol, $decimals, $pos, $actor, $now, $actor, $now);
    } else {
        if (!$existing) {
            return ['ok' => false, 'error' => "Currency $code was not found."];
        }
        if ((int)$existing['decimals'] !== $decimals && psFxCurrencyIsUsed($conn, $code)) {
            return ['ok' => false, 'error' => "Decimal places of $code cannot change: transactions already use it."];
        }
        $stmt = mysqli_prepare($conn, 'UPDATE currencies SET name = ?, symbol = ?, decimals = ?, symbol_position = ?, updated_by = ?, updated_at = ? WHERE code = ?');
        mysqli_stmt_bind_param($stmt, 'ssissss', $name, $symbol, $decimals, $pos, $actor, $now, $code);
    }
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'The currency could not be saved.'];
    }
    psFxAudit($conn, null, 'currency', $code, $isNew ? 'CURRENCY_CREATE' : 'CURRENCY_UPDATE', $existing, psFxCurrency($conn, $code));
    return ['ok' => true];
}

/** Enable / disable a catalogue currency (Super Admin). Disabling never touches history; it only blocks new transactions and rates. */
function psFxCurrencySetStatus(mysqli $conn, string $code, bool $active): array
{
    if (!psFxSessionIsSuperAdmin()) {
        return ['ok' => false, 'error' => 'Only a Super Admin can manage currencies.'];
    }
    $cur = psFxCurrency($conn, $code);
    if (!$cur) {
        return ['ok' => false, 'error' => 'Currency was not found.'];
    }
    if (!$active) {
        $res = mysqli_query($conn, "SELECT company_name FROM company_base_currency WHERE base_code = '" . mysqli_real_escape_string($conn, $cur['code']) . "' LIMIT 1");
        if ($res && ($r = mysqli_fetch_assoc($res))) {
            return ['ok' => false, 'error' => $cur['code'] . ' is the base currency of ' . $r['company_name'] . ' and cannot be disabled.'];
        }
        if (strcasecmp($cur['code'], psFxLegacyCurrency()) === 0 && psFxCompaniesOnLegacyBase($conn)) {
            return ['ok' => false, 'error' => $cur['code'] . ' is the base currency of companies that have not chosen one, and cannot be disabled.'];
        }
    }
    $status = $active ? 'ACTIVE' : 'DISABLED';
    $now = date('Y-m-d H:i:s');
    $actor = psFxActor();
    $stmt = mysqli_prepare($conn, 'UPDATE currencies SET status = ?, updated_by = ?, updated_at = ? WHERE code = ?');
    mysqli_stmt_bind_param($stmt, 'ssss', $status, $actor, $now, $cur['code']);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok) {
        psFxAudit($conn, null, 'currency', $cur['code'], $active ? 'CURRENCY_ENABLE' : 'CURRENCY_DISABLE', ['status' => $cur['status']], ['status' => $status]);
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The currency could not be saved.'];
}

/** True when any stored transaction or rate uses the currency (decimals then cannot change). */
function psFxCurrencyIsUsed(mysqli $conn, string $code): bool
{
    $c = mysqli_real_escape_string($conn, psFxNormalizeCode($code));
    foreach (psFxCurrencyColumns() as [$table, $col]) {
        $res = @mysqli_query($conn, "SELECT 1 FROM `$table` WHERE `$col` = '$c' LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            return true;
        }
    }
    $res = mysqli_query($conn, "SELECT 1 FROM exchange_rates WHERE from_code = '$c' OR to_code = '$c' LIMIT 1");
    return $res && mysqli_num_rows($res) > 0;
}

/** Transaction tables that carry a currency column (kept in one place for checks and migrations). */
function psFxCurrencyColumns(): array
{
    return [
        ['online_sales_ref', 'currency'],
        ['sale_payments', 'currency'],
        ['cash_movements', 'currency'],
        ['cash_reconciliations', 'currency'],
        ['customer_accounts', 'currency'],
        ['customer_account_payments', 'currency'],
        ['goods_receipts_ref', 'currency'],
        ['purchase_orders', 'currency'],
        ['purchase_returns', 'currency'],
        ['expenses', 'currency'],
        ['quotations', 'currency'],
        ['sale_refund_requests', 'currency'],
    ];
}

// ============================================================ company base and enabled currencies

/** Currency of records made before multi-currency existed (the system was USD-only). Config 'legacy_currency'. */
function psFxLegacyCurrency(): string
{
    $raw = function_exists('psConfigValue') ? psConfigValue('legacy_currency', PS_FX_DEFAULT_LEGACY) : PS_FX_DEFAULT_LEGACY;
    $code = psFxNormalizeCode($raw);
    return preg_match('/^[A-Z]{3}$/', $code) ? $code : PS_FX_DEFAULT_LEGACY;
}

function psFxCompaniesOnLegacyBase(mysqli $conn): bool
{
    $res = mysqli_query($conn, 'SELECT COUNT(DISTINCT u.company_name) AS n FROM users u LEFT JOIN company_base_currency b ON b.company_name = u.company_name WHERE b.company_name IS NULL AND u.company_name IS NOT NULL AND u.company_name <> \'\'');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row && (int)$row['n'] > 0;
}

/** The company's base (reporting/accounting) currency code. */
function psFxBase(mysqli $conn, string $company): string
{
    static $cache = [];
    $key = strtolower(trim($company));
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $base = psFxLegacyCurrency();
    if ($company !== '' && psFxEnsureSchema($conn)) {
        $stmt = mysqli_prepare($conn, 'SELECT base_code FROM company_base_currency WHERE company_name = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $company);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($row) {
                $base = (string)$row['base_code'];
            }
        }
    }
    if ($key !== '' && $key === strtolower(psFxSessionCompany())) {
        $GLOBALS['ps_fx_session_base'] = $base; // ps_money() shows the signed-in company's base by default
    }
    return $cache[$key] = $base;
}

function psFxBaseCacheClear(): void
{
    // psFxBase caches per request; pages that change the base redirect afterwards, so a clear is only needed in tests.
}

/** Does the company already have financial records? (Then the base currency is locked.) */
function psFxCompanyHasTransactions(mysqli $conn, string $company): bool
{
    $c = mysqli_real_escape_string($conn, $company);
    $checks = [
        "SELECT 1 FROM online_sales_ref r INNER JOIN branchies b ON b.branch_name = r.branch WHERE b.company_name = '$c' LIMIT 1",
        "SELECT 1 FROM goods_receipts_ref g INNER JOIN branchies b ON b.branch_name = g.branch WHERE b.company_name = '$c' LIMIT 1",
        "SELECT 1 FROM customer_account_ledger l INNER JOIN customer_accounts a ON a.id = l.customer_account_id INNER JOIN customer_master m ON m.id = a.customer_master_id WHERE m.company_name = '$c' LIMIT 1",
        "SELECT 1 FROM expenses e INNER JOIN branchies b ON b.branch_name = e.branch WHERE b.company_name = '$c' LIMIT 1",
    ];
    foreach ($checks as $sql) {
        $res = @mysqli_query($conn, $sql);
        if ($res && mysqli_num_rows($res) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * Set the company's base currency (Super Admin). Allowed while the company has no financial records,
 * or when it confirms the currency it already uses. Changing it afterwards needs a separate, explicit
 * conversion project (docs/MULTI_CURRENCY.md) — history is never reinterpreted.
 */
function psFxSetBase(mysqli $conn, string $company, string $code): array
{
    if (!psFxSessionIsSuperAdmin()) {
        return ['ok' => false, 'error' => 'Only a Super Admin can set the base currency.'];
    }
    $company = trim($company);
    $cur = psFxCurrency($conn, $code);
    if ($company === '' || !$cur) {
        return ['ok' => false, 'error' => 'Choose a company and a currency.'];
    }
    if ($cur['status'] !== 'ACTIVE') {
        return ['ok' => false, 'error' => $cur['code'] . ' is disabled.'];
    }
    $current = psFxBase($conn, $company);
    if (strcasecmp($current, $cur['code']) !== 0 && psFxCompanyHasTransactions($conn, $company)) {
        return ['ok' => false, 'error' => "$company already has transactions in $current. Its base currency cannot be changed here: "
            . 'that needs a planned conversion (see the multi-currency guide). Nothing was changed.'];
    }
    $now = date('Y-m-d H:i:s');
    $actor = psFxActor();
    $stmt = mysqli_prepare($conn, 'INSERT INTO company_base_currency (company_name, base_code, set_by, set_at) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE base_code = VALUES(base_code), set_by = VALUES(set_by), set_at = VALUES(set_at)');
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $cur['code'], $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'The base currency could not be saved.'];
    }
    psFxSetCompanyCurrency($conn, $company, $cur['code'], true, false);
    psFxAudit($conn, $company, 'base', $company, 'BASE_SET', ['base' => $current], ['base' => $cur['code']]);
    return ['ok' => true];
}

/** Allow / stop a currency for a company's new transactions (Super Admin). The base cannot be switched off. */
function psFxSetCompanyCurrency(mysqli $conn, string $company, string $code, bool $enabled, bool $checkRole = true): array
{
    if ($checkRole && !psFxSessionIsSuperAdmin()) {
        return ['ok' => false, 'error' => 'Only a Super Admin can choose a company\'s currencies.'];
    }
    $cur = psFxCurrency($conn, $code);
    if (trim($company) === '' || !$cur) {
        return ['ok' => false, 'error' => 'Choose a company and a currency.'];
    }
    if (!$enabled && strcasecmp(psFxBase($conn, $company), $cur['code']) === 0) {
        return ['ok' => false, 'error' => $cur['code'] . ' is this company\'s base currency and stays enabled.'];
    }
    if ($enabled && $cur['status'] !== 'ACTIVE') {
        return ['ok' => false, 'error' => $cur['code'] . ' is disabled in the currency list.'];
    }
    $before = psFxCompanyCurrencyEnabled($conn, $company, $cur['code']);
    $v = $enabled ? 1 : 0;
    $now = date('Y-m-d H:i:s');
    $actor = psFxActor();
    $stmt = mysqli_prepare($conn, 'INSERT INTO company_currencies (company_name, code, enabled, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    mysqli_stmt_bind_param($stmt, 'ssiss', $company, $cur['code'], $v, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok && $checkRole) {
        psFxAudit($conn, $company, 'company_currency', $cur['code'], $enabled ? 'COMPANY_CURRENCY_ENABLE' : 'COMPANY_CURRENCY_DISABLE', ['enabled' => $before], ['enabled' => $enabled]);
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The setting could not be saved.'];
}

function psFxCompanyCurrencyEnabled(mysqli $conn, string $company, string $code): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT enabled FROM company_currencies WHERE company_name = ? AND code = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $code = psFxNormalizeCode($code);
    mysqli_stmt_bind_param($stmt, 'ss', $company, $code);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row !== null && (int)$row['enabled'] === 1;
}

/**
 * Currencies the company may use for NEW transactions: the base (when ACTIVE) plus enabled, ACTIVE catalogue entries.
 * @return array<string,array> code => catalogue row (base first)
 */
function psFxCompanyCurrencies(mysqli $conn, string $company): array
{
    if (!psFxEnsureSchema($conn)) {
        return [];
    }
    $catalogue = psFxCurrencies($conn, true);
    $base = psFxBase($conn, $company);
    $out = [];
    if (isset($catalogue[$base])) {
        $out[$base] = $catalogue[$base];
    } else {
        // Base missing from the catalogue (e.g. legacy USD on an install that removed it): still usable, plain formatting.
        $out[$base] = ['code' => $base, 'name' => $base, 'symbol' => $base === 'USD' ? '$' : '', 'decimals' => 2, 'symbol_position' => 'before', 'status' => 'ACTIVE'];
    }
    $stmt = mysqli_prepare($conn, 'SELECT code FROM company_currencies WHERE company_name = ? AND enabled = 1 ORDER BY code');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $company);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) {
            if (isset($catalogue[$r['code']]) && !isset($out[$r['code']])) {
                $out[$r['code']] = $catalogue[$r['code']];
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $out;
}

/** Validate a currency chosen for a new transaction. @return array{ok:bool,code?:string,currency?:array,error?:string} */
function psFxCheckTransactionCurrency(mysqli $conn, string $company, $raw): array
{
    $code = psFxNormalizeCode($raw);
    $base = psFxBase($conn, $company);
    if ($code === '') {
        $code = $base;
    }
    $allowed = psFxCompanyCurrencies($conn, $company);
    if (!isset($allowed[$code])) {
        $cur = psFxCurrency($conn, $code);
        if ($cur && $cur['status'] !== 'ACTIVE') {
            return ['ok' => false, 'error' => "$code is disabled. Choose another currency."];
        }
        return ['ok' => false, 'error' => "$code is not enabled for this company. Ask a Super Admin to enable it."];
    }
    return ['ok' => true, 'code' => $code, 'currency' => $allowed[$code]];
}

function psFxDecimals(mysqli $conn, string $code): int
{
    static $cache = [];
    $code = psFxNormalizeCode($code);
    if (!isset($cache[$code])) {
        $cur = psFxCurrency($conn, $code);
        $cache[$code] = $cur ? max(0, min(2, (int)$cur['decimals'])) : 2;
    }
    return $cache[$code];
}

// ============================================================ exchange rates (company admins / managers)

function psFxNormalizeDateTime($raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace('T', ' ', $raw);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $raw);
        if ($d && $d->format($fmt) === $raw) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/** Add a rate "1 FROM = rate TO" effective from a moment. Never overwrites: a newer row supersedes. */
function psFxRateAdd(mysqli $conn, string $company, array $in): array
{
    if (!psFxMayManageRates()) {
        return ['ok' => false, 'error' => 'Only an admin or manager can maintain exchange rates.'];
    }
    if (!psFxEnsureSchema($conn) || trim($company) === '') {
        return ['ok' => false, 'error' => 'Exchange rates are not available right now.'];
    }
    $from = psFxNormalizeCode($in['from_code'] ?? '');
    $to = psFxNormalizeCode($in['to_code'] ?? '');
    if (!preg_match('/^[A-Z]{3}$/', $from) || !preg_match('/^[A-Z]{3}$/', $to)) {
        return ['ok' => false, 'error' => 'Choose both currencies.'];
    }
    if ($from === $to) {
        return ['ok' => false, 'error' => 'Choose two different currencies.'];
    }
    $allowed = psFxCompanyCurrencies($conn, $company);
    foreach ([$from, $to] as $c) {
        if (!isset($allowed[$c])) {
            return ['ok' => false, 'error' => "$c is not enabled for this company (or is disabled)."];
        }
    }
    $rateRaw = trim((string)($in['rate'] ?? ''));
    $frac = psFxParseRate($rateRaw);
    if ($frac === null) {
        return ['ok' => false, 'error' => 'Enter a rate greater than zero, with at most ' . PS_FX_RATE_SCALE . ' decimal places (e.g. 25.50).'];
    }
    $rate = psFxFracToDecimal($frac);
    $eff = psFxNormalizeDateTime($in['effective_from'] ?? '') ?? date('Y-m-d H:i:s');
    $source = mb_substr(trim((string)($in['source'] ?? '')), 0, 120);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 250);
    $actor = psFxActor();
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "INSERT INTO exchange_rates (company_name, from_code, to_code, rate, effective_from, source, note, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?)");
    mysqli_stmt_bind_param($stmt, 'sssssssss', $company, $from, $to, $rate, $eff, $source, $note, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    $errno = mysqli_errno($conn);
    $id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        if ($errno === 1062) {
            return ['ok' => false, 'error' => "A $from to $to rate already starts at $eff. Add the new rate with a later start time (rates are never overwritten)."];
        }
        return ['ok' => false, 'error' => 'The rate could not be saved.'];
    }
    psFxAudit($conn, $company, 'rate', (string)$id, 'RATE_CREATE', null, ['from' => $from, 'to' => $to, 'rate' => $rate, 'effective_from' => $eff, 'source' => $source, 'note' => $note]);
    return ['ok' => true, 'id' => $id, 'rate' => $rate, 'effective_from' => $eff];
}

/** Stop a rate being used from now on. The row and every transaction that used it are kept. */
function psFxRateRetire(mysqli $conn, string $company, int $id, string $reason = ''): array
{
    if (!psFxMayManageRates()) {
        return ['ok' => false, 'error' => 'Only an admin or manager can maintain exchange rates.'];
    }
    $row = psFxRateById($conn, $company, $id);
    if (!$row) {
        return ['ok' => false, 'error' => 'Rate was not found.'];
    }
    if ($row['status'] !== 'ACTIVE') {
        return ['ok' => false, 'error' => 'That rate is already retired.'];
    }
    $actor = psFxActor();
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "UPDATE exchange_rates SET status = 'RETIRED', retired_by = ?, retired_at = ? WHERE id = ? AND company_name = ? AND status = 'ACTIVE'");
    mysqli_stmt_bind_param($stmt, 'ssis', $actor, $now, $id, $company);
    $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'The rate could not be retired. If another rate with the same start was retired before, add a new rate instead.'];
    }
    psFxAudit($conn, $company, 'rate', (string)$id, 'RATE_RETIRE', ['status' => 'ACTIVE'], ['status' => 'RETIRED', 'reason' => mb_substr($reason, 0, 200)]);
    return ['ok' => true];
}

function psFxRateById(mysqli $conn, string $company, int $id): ?array
{
    if (!psFxEnsureSchema($conn)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM exchange_rates WHERE id = ? AND company_name = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'is', $id, $company);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** All rates of a company with a derived state: CURRENT, FUTURE, SUPERSEDED or RETIRED (newest first). */
function psFxRateList(mysqli $conn, string $company, string $at = ''): array
{
    if (!psFxEnsureSchema($conn)) {
        return [];
    }
    $at = $at !== '' ? $at : date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, 'SELECT * FROM exchange_rates WHERE company_name = ? ORDER BY from_code, to_code, effective_from DESC, id DESC');
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $currentSeen = [];
    foreach ($rows as &$r) {
        $pair = $r['from_code'] . '>' . $r['to_code'];
        if ($r['status'] !== 'ACTIVE') {
            $r['state'] = 'RETIRED';
        } elseif ($r['effective_from'] > $at) {
            $r['state'] = 'FUTURE';
        } elseif (!isset($currentSeen[$pair])) {
            $r['state'] = 'CURRENT';
            $currentSeen[$pair] = true;
        } else {
            $r['state'] = 'SUPERSEDED';
        }
        $r['used'] = psFxRateIsUsed($conn, (int)$r['id']);
    }
    unset($r);
    return $rows;
}

/** Has any posted transaction used this rate? (Shown on the rates page; such rates can only be retired, never edited.) */
function psFxRateIsUsed(mysqli $conn, int $id): bool
{
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach (psFxCurrencyColumns() as [$table]) {
            $res = @mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE 'fx_rate_ids'");
            if ($res && mysqli_num_rows($res) > 0) {
                $cols[] = $table;
            }
        }
    }
    foreach ($cols as $table) {
        $res = @mysqli_query($conn, "SELECT 1 FROM `$table` WHERE FIND_IN_SET('" . (int)$id . "', fx_rate_ids) LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            return true;
        }
    }
    return false;
}

/** Latest ACTIVE row for FROM->TO effective at $at, or null. */
function psFxRateRow(mysqli $conn, string $company, string $from, string $to, string $at): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM exchange_rates WHERE company_name = ? AND from_code = ? AND to_code = ? AND status = 'ACTIVE' AND effective_from <= ?
        ORDER BY effective_from DESC, id DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $from, $to, $at);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** One leg FROM->TO at $at: direct or inverse (latest wins; equal starts must agree). */
function psFxLeg(mysqli $conn, string $company, string $from, string $to, string $at): array
{
    $direct = psFxRateRow($conn, $company, $from, $to, $at);
    $inverse = psFxRateRow($conn, $company, $to, $from, $at);
    if (!$direct && !$inverse) {
        return ['ok' => false];
    }
    $use = null;
    if ($direct && $inverse) {
        if ($direct['effective_from'] === $inverse['effective_from']) {
            $d = psFxParseRate((string)$direct['rate']);
            $i = psFxParseRate((string)$inverse['rate']);
            // direct * inverse should be 1; reject when they differ by more than 0.01%.
            $prod = psFxFracMul($d, $i);
            $scaled = psFxBigDivRound(psFxBigMul($prod[0], '1000000'), $prod[1]); // product * 1e6
            if (abs((int)$scaled - 1000000) > 100) {
                return ['ok' => false, 'conflict' => true, 'error' => "Conflicting rates: $from to $to (#{$direct['id']}) and $to to $from (#{$inverse['id']}) start at the same time but disagree. Retire one of them."];
            }
            $use = 'direct';
        } else {
            $use = $direct['effective_from'] > $inverse['effective_from'] ? 'direct' : 'inverse';
        }
    } else {
        $use = $direct ? 'direct' : 'inverse';
    }
    if ($use === 'direct') {
        return ['ok' => true, 'frac' => psFxParseRate((string)$direct['rate']), 'ids' => [(int)$direct['id']], 'path' => "$from>$to #{$direct['id']}"];
    }
    return ['ok' => true, 'frac' => psFxFracInvert(psFxParseRate((string)$inverse['rate'])), 'ids' => [(int)$inverse['id']], 'path' => "$to>$from #{$inverse['id']} inverse"];
}

/**
 * Rate to convert FROM into TO at moment $at for the company.
 * @return array{ok:bool,frac?:array,rate?:string,ids?:int[],path?:string,error?:string}
 *   frac/rate: 1 FROM = rate TO.
 */
function psFxRate(mysqli $conn, string $company, string $from, string $to, string $at = ''): array
{
    $from = psFxNormalizeCode($from);
    $to = psFxNormalizeCode($to);
    $at = $at !== '' ? $at : date('Y-m-d H:i:s');
    if ($from === $to) {
        return ['ok' => true, 'frac' => ['1', '1'], 'rate' => '1', 'ids' => [], 'path' => 'same currency'];
    }
    if (!psFxEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Exchange rates are not available right now.'];
    }
    $leg = psFxLeg($conn, $company, $from, $to, $at);
    if (!empty($leg['ok'])) {
        $leg['rate'] = psFxFracToDecimal($leg['frac'], 10);
        return $leg;
    }
    if (!empty($leg['conflict'])) {
        return $leg;
    }
    $base = psFxBase($conn, $company);
    if ($from !== $base && $to !== $base) {
        $a = psFxLeg($conn, $company, $from, $base, $at);
        $b = psFxLeg($conn, $company, $base, $to, $at);
        if (!empty($a['conflict'])) {
            return $a;
        }
        if (!empty($b['conflict'])) {
            return $b;
        }
        if (!empty($a['ok']) && !empty($b['ok'])) {
            $frac = psFxFracMul($a['frac'], $b['frac']);
            return ['ok' => true, 'frac' => $frac, 'rate' => psFxFracToDecimal($frac, 10), 'ids' => array_merge($a['ids'], $b['ids']),
                'path' => $a['path'] . ' · ' . $b['path'] . " (via $base)"];
        }
    }
    $when = substr($at, 0, 16);
    return ['ok' => false, 'error' => "No exchange rate from $from to $to for $when. Ask an admin or manager to add one on the Exchange Rates page."];
}

/**
 * Snapshot for a transaction in $txnCode: how one unit of the company base converts into the transaction currency.
 * @return array{ok:bool,currency?:string,base?:string,frac?:array,rate?:string,ids?:string,path?:string,error?:string}
 *   rate: 1 BASE = rate TXN (stored as fx_rate); ids: comma list for fx_rate_ids.
 */
function psFxSnapshot(mysqli $conn, string $company, string $txnCode, string $at = ''): array
{
    $base = psFxBase($conn, $company);
    $r = psFxRate($conn, $company, $base, $txnCode, $at);
    if (empty($r['ok'])) {
        return $r;
    }
    return ['ok' => true, 'currency' => psFxNormalizeCode($txnCode), 'base' => $base, 'frac' => $r['frac'], 'rate' => $r['rate'],
        'ids' => implode(',', $r['ids']), 'path' => $r['path']];
}

/** Base-currency cents of a transaction amount, using its snapshot (1 BASE = frac TXN). */
function psFxToBaseCents(mysqli $conn, array $snap, int $txnCents): int
{
    if ($snap['currency'] === $snap['base']) {
        return $txnCents;
    }
    return psFxConvertCents($txnCents, psFxFracInvert($snap['frac']), psFxDecimals($conn, $snap['base']));
}

/** Transaction-currency cents of a base amount (e.g. a product price), rounded to the transaction currency's decimals. */
function psFxFromBaseCents(mysqli $conn, array $snap, int $baseCents): int
{
    if ($snap['currency'] === $snap['base']) {
        return $baseCents;
    }
    return psFxConvertCents($baseCents, $snap['frac'], psFxDecimals($conn, $snap['currency']));
}

/** Snapshot rebuilt from stored columns (fx_rate = 1 BASE in TXN). Legacy rows: legacy currency at 1:1 to a legacy base. */
function psFxStoredSnapshot(mysqli $conn, array $row, string $company): array
{
    $cur = psFxNormalizeCode($row['currency'] ?? '');
    $legacy = $cur === '';
    if ($legacy) {
        $cur = psFxLegacyCurrency();
    }
    $base = psFxNormalizeCode($row['base_currency'] ?? '') ?: psFxBase($conn, $company);
    $rate = trim((string)($row['fx_rate'] ?? ''));
    // Stored snapshots keep 10 decimals (a cross rate via the base can need more than the 8 allowed when typing a rate).
    $frac = $rate !== '' ? psFxParseRate(strpos($rate, '.') !== false ? (rtrim(rtrim($rate, '0'), '.') ?: '1') : $rate, 10) : null;
    if ($frac === null) {
        $frac = $cur === $base ? ['1', '1'] : null;
    }
    return ['currency' => $cur, 'base' => $base, 'frac' => $frac, 'rate' => $rate, 'legacy' => $legacy];
}

// ============================================================ formatting

/** "$12.50", "ZiG 12.50", "12 XYZ" — symbol + configured decimals; the code is added when the symbol is ambiguous or empty. */
function psFxFormat(mysqli $conn, $amount, string $code, bool $withCode = false): string
{
    static $cat = null;
    if ($cat === null) {
        $cat = psFxCurrencies($conn);
    }
    $code = psFxNormalizeCode($code) ?: psFxLegacyCurrency();
    $c = $cat[$code] ?? ['symbol' => $code === 'USD' ? '$' : '', 'decimals' => 2, 'symbol_position' => 'before'];
    $dec = max(0, min(2, (int)$c['decimals']));
    $num = (float)$amount;
    $neg = $num < 0;
    $txt = number_format(abs($num), $dec, '.', ',');
    $sym = (string)$c['symbol'];
    if ($sym === '') {
        $out = $txt . ' ' . $code;
    } elseif (($c['symbol_position'] ?? 'before') === 'after') {
        $out = $txt . ' ' . $sym;
    } else {
        // "$2.20", "R 50.00", "ZiG 58.30": a space after a symbol that ends in a letter.
        $out = $sym . (preg_match('/\pL$/u', $sym) ? ' ' : '') . $txt;
    }
    if ($withCode && $sym !== '' && strcasecmp($sym, $code) !== 0) {
        $out .= ' ' . $code;
    }
    return ($neg ? '-' : '') . $out;
}

/** "1 USD = 25.00 ZWG" */
function psFxRateLabel(string $from, string $to, string $rate): string
{
    $r = rtrim(rtrim($rate, '0'), '.');
    if (strpos($r, '.') === false) {
        $r .= '.00';
    } elseif (strlen(substr(strrchr($r, '.'), 1)) < 2) {
        $r .= '0';
    }
    return "1 $from = $r $to";
}

// ============================================================ transaction columns (migration)

/**
 * Add currency snapshot columns to transaction tables (idempotent, non-destructive: existing rows stay NULL = legacy).
 * Run outside a transaction (DDL commits). Called from psFxEnsureSchema users before they open transactions.
 */
function psFxEnsureTransactionColumns(mysqli $conn): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    if (!psFxEnsureSchema($conn)) {
        return false;
    }
    $snapshotCols = [
        'currency' => 'CHAR(3) NULL DEFAULT NULL',
        'base_currency' => 'CHAR(3) NULL DEFAULT NULL',
        'fx_rate' => 'DECIMAL(24,10) NULL DEFAULT NULL',
        'fx_rate_ids' => 'VARCHAR(60) NULL DEFAULT NULL',
        'fx_path' => 'VARCHAR(160) NULL DEFAULT NULL',
    ];
    $plan = [
        'online_sales_ref' => $snapshotCols + ['base_total' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
        'sale_payments' => [], // already has currency
        'cash_movements' => $snapshotCols + ['base_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
        'cash_reconciliations' => ['currency' => 'CHAR(3) NULL DEFAULT NULL'],
        'customer_accounts' => ['currency' => 'CHAR(3) NULL DEFAULT NULL'],
        'customer_account_payments' => $snapshotCols + ['base_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL',
            'tender_currency' => 'CHAR(3) NULL DEFAULT NULL', 'tender_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL', 'tender_rate' => 'DECIMAL(24,10) NULL DEFAULT NULL'],
        'goods_receipts_ref' => $snapshotCols + ['base_total' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
        'purchase_orders' => $snapshotCols,
        'purchase_returns' => $snapshotCols + ['base_total' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
        'expenses' => $snapshotCols + ['base_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
        'quotations' => $snapshotCols + ['base_total' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
        'sale_refund_requests' => ['currency' => 'CHAR(3) NULL DEFAULT NULL', 'base_amount' => 'DECIMAL(14,2) NULL DEFAULT NULL'],
    ];
    foreach ($plan as $table => $cols) {
        $exists = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if (!$exists || mysqli_num_rows($exists) === 0) {
            continue; // module not installed yet; its own schema function adds the columns when it creates the table
        }
        foreach ($cols as $col => $def) {
            $has = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
            if ($has && mysqli_num_rows($has) > 0) {
                continue;
            }
            if (!mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def") && mysqli_errno($conn) !== 1060) {
                error_log("currency columns: $table.$col not added (#" . mysqli_errno($conn) . ')');
                return false;
            }
        }
    }
    $done = true;
    return true;
}

/**
 * Currencies and current rates for a selling screen (web POS / quotations). Prices are converted in the browser with the
 * same exact fraction the server uses; the server re-checks everything and refuses a sale if the rate changed meanwhile.
 * @return array{base:string,currencies:array<string,array>}
 */
function psFxSellingPayload(mysqli $conn, string $company, string $at = ''): array
{
    $at = $at !== '' ? $at : date('Y-m-d H:i:s');
    $base = psFxBase($conn, $company);
    $out = ['base' => $base, 'currencies' => []];
    foreach (psFxCompanyCurrencies($conn, $company) as $code => $c) {
        $row = ['code' => $code, 'name' => (string)$c['name'], 'symbol' => (string)$c['symbol'], 'decimals' => max(0, min(2, (int)$c['decimals'])),
            'position' => ($c['symbol_position'] ?? 'before') === 'after' ? 'after' : 'before'];
        $snap = psFxSnapshot($conn, $company, $code, $at);
        if (!empty($snap['ok'])) {
            $row += ['ok' => true, 'num' => $snap['frac'][0], 'den' => $snap['frac'][1], 'rate' => $snap['rate'], 'label' => psFxRateLabel($base, $code, $snap['rate'])];
        } else {
            $row += ['ok' => false, 'error' => (string)$snap['error']];
        }
        $out['currencies'][$code] = $row;
    }
    return $out;
}

// ============================================================ tills (cash per currency)

/** Company that owns a till session: the cashier's company (branch names are not unique across companies). */
function psFxTillCompany(mysqli $conn, int $tillSessionId): string
{
    static $cache = [];
    if ($tillSessionId < 1) {
        return psFxSessionCompany();
    }
    if (isset($cache[$tillSessionId])) {
        return $cache[$tillSessionId];
    }
    $company = '';
    $stmt = mysqli_prepare($conn, 'SELECT u.company_name FROM sessions s
        JOIN users u ON (s.cashier_id IS NOT NULL AND u.id = s.cashier_id) OR (s.cashier_id IS NULL AND u.email = s.cashier_email)
        WHERE s.id = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $tillSessionId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_row($res) : null;
        mysqli_stmt_close($stmt);
        $company = trim((string)($row[0] ?? ''));
    }
    if ($company === '') {
        $company = psFxSessionCompany();
    }
    return $cache[$tillSessionId] = $company;
}

/** Base currency of the company that owns a till. Cash rows with no currency (before multi-currency) count as this. */
function psFxTillBase(mysqli $conn, int $tillSessionId): string
{
    return psFxBase($conn, psFxTillCompany($conn, $tillSessionId));
}

/** True when the cash/ledger tables already carry currency columns (checked without DDL, safe inside a transaction). */
function psFxHasColumn(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $k = "$table.$column";
    if (!isset($cache[$k])) {
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
        $cache[$k] = $res && mysqli_num_rows($res) > 0;
    }
    return $cache[$k];
}

/** SQL value list for a snapshot: [currency, base_currency, fx_rate, fx_rate_ids, fx_path] as escaped literals. */
function psFxSnapshotSqlValues(mysqli $conn, array $snap): array
{
    $q = static function ($v) use ($conn) {
        return $v === null || $v === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
    };
    return [
        'currency' => $q($snap['currency'] ?? null),
        'base_currency' => $q($snap['base'] ?? null),
        'fx_rate' => $q($snap['rate'] ?? null),
        'fx_rate_ids' => $q($snap['ids'] ?? null),
        'fx_path' => $q($snap['path'] ?? null),
    ];
}

/** Base cents → "12.34" (2 dp storage). */
function psFxCentsToMoney(int $cents): string
{
    $neg = $cents < 0;
    $c = abs($cents);
    return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
}

/** "12.34" → 1234 (exact; up to 2 dp; null if not a plain number). */
function psFxMoneyToCents($raw): ?int
{
    $s = trim((string)$raw);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/', $s, $m)) {
        return null;
    }
    $c = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
    return $m[1] === '-' ? -$c : $c;
}

/**
 * Snapshot stored on a sale (online_sales_ref), for refunds and reports: the original currency and the original rate.
 * Sales from before multi-currency are the till company's base at 1:1.
 */
function psFxSaleSnapshot(mysqli $conn, string $invoiceNumber, string $company = ''): array
{
    $company = $company !== '' ? $company : psFxSessionCompany();
    $row = null;
    if (psFxHasColumn($conn, 'online_sales_ref', 'currency')) {
        $stmt = mysqli_prepare($conn, 'SELECT currency, base_currency, fx_rate, fx_rate_ids, fx_path FROM online_sales_ref WHERE invoice_number = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $invoiceNumber);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
        }
    }
    $snap = psFxStoredSnapshot($conn, $row ?: [], $company);
    if ($snap['frac'] === null) {
        $snap['frac'] = ['1', '1'];
    }
    $snap['ok'] = true;
    $snap['ids'] = (string)($row['fx_rate_ids'] ?? '');
    $snap['path'] = (string)($row['fx_path'] ?? ($snap['legacy'] ? 'before multi-currency' : ''));
    if ($snap['rate'] === '') {
        $snap['rate'] = '1';
    }
    return $snap;
}
