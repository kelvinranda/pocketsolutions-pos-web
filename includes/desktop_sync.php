<?php
/**
 * Phase 21 — Desktop (offline) tills: registration, sync map and sync-differences report.
 *
 * A desktop till is a Windows PC running Pockets POS for Windows. It is paired once to one
 * company + branch with a one-time pairing code made on desktop_tills.php, then talks to
 * api/v1/ with its own long token (only the SHA-256 of the token is stored).
 *
 * The till sells offline and later pushes events (till open, sale, cash in/out, till close).
 * Every event carries a client_uuid; desktop_sync_map makes each push idempotent.
 * Decision (2026-10-16): offline sales are ACCEPTED as the till recorded them (price, tax,
 * paid amount) and any difference from the server's data is written to desktop_sync_issues
 * for the Sync Differences report — never silently changed and never rejected for price.
 */

require_once __DIR__ . '/sale_payment.php';
require_once __DIR__ . '/till_session.php';
require_once __DIR__ . '/stock_movement.php';
require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/cash_movement.php';
require_once __DIR__ . '/tax.php';
require_once __DIR__ . '/order_number.php';
require_once __DIR__ . '/dine_option.php'; // 2026-11-13

const PS_DT_PAIRING_HOURS = 24;
const PS_DT_SOURCE = 'DESKTOP';

/** Issue kinds shown on the Sync Differences report. */
function psDtIssueKinds(): array
{
    return [
        'PRICE_DIFF' => 'Price differs from the server',
        'TAX_DIFF' => 'Tax differs from the server',
        'NEGATIVE_STOCK' => 'Stock went below zero',
        'PRODUCT_MISSING' => 'Product not found on the server',
        'PRODUCT_INACTIVE' => 'Product is inactive on the server',
        'CASHIER_UNKNOWN' => 'Cashier not found in this company',
        'SESSION_CONFLICT' => 'Cashier had another till open',
        'CASH_DIFF' => 'Till expected cash differs from the server',
        'NOT_FISCALISED' => 'Sale not fiscalised (branch has a ZIMRA device)',
        'CREDIT_RULE' => 'Account sale broke a credit rule',
        'CUSTOMER_UNKNOWN' => 'Customer not found on the server',
        'REFUND_CONFLICT' => 'Refund quantity was no longer available on the server',
        'REFUND_DIFF' => 'Refund amount differs from the server',
        'QUOTE' => 'Quotation was no longer open on the server',
        'FX_RATE' => 'Sold at a different exchange rate than the server',
        'FX_CURRENCY' => 'Sold in a currency no longer enabled',
        'PAYMENT_DIFF' => 'Split payment change differs from the server',
        'REJECTED' => 'Event rejected — needs attention',
    ];
}

function psDtEnsureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = [
        "CREATE TABLE IF NOT EXISTS desktop_tills (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL,
            branch VARCHAR(250) NOT NULL,
            till_code VARCHAR(16) NOT NULL,
            name VARCHAR(100) NOT NULL,
            invoice_prefix VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
            token_hash CHAR(64) NULL,
            pairing_hash CHAR(64) NULL,
            pairing_expires DATETIME NULL,
            register_id INT NULL,
            machine_name VARCHAR(120) NULL,
            app_version VARCHAR(40) NULL,
            registered_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            last_push_at DATETIME NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_dt_company_code (company_name, till_code),
            UNIQUE KEY uniq_dt_token (token_hash),
            UNIQUE KEY uniq_dt_pairing (pairing_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS desktop_sync_map (
            client_uuid VARCHAR(64) NOT NULL PRIMARY KEY,
            till_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            server_ref VARCHAR(64) NOT NULL DEFAULT '',
            till_ref VARCHAR(64) NULL,
            result_json TEXT NULL,
            synced_at DATETIME NOT NULL,
            KEY idx_dsm_till (till_id, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS desktop_sync_issues (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL,
            branch VARCHAR(250) NOT NULL,
            till_id INT NOT NULL,
            kind VARCHAR(24) NOT NULL,
            client_uuid VARCHAR(64) NULL,
            invoice_number VARCHAR(64) NULL,
            till_ref VARCHAR(64) NULL,
            product_id VARCHAR(250) NULL,
            detail VARCHAR(500) NOT NULL DEFAULT '',
            till_value VARCHAR(64) NULL,
            server_value VARCHAR(64) NULL,
            occurred_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            resolved_by VARCHAR(250) NULL,
            KEY idx_dsi_company (company_name, resolved_at),
            KEY idx_dsi_till (till_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sql as $q) {
        if (!mysqli_query($conn, $q)) {
            error_log('desktop sync: schema failed');
            return false;
        }
    }
    foreach ([
        'till_invoice_number' => 'VARCHAR(32) NULL DEFAULT NULL',
        'desktop_till_id' => 'INT NULL DEFAULT NULL',
        'sale_source' => 'VARCHAR(16) NULL DEFAULT NULL',
        // ZIMRA fiscal invoice issued on the till PC (its own FDMS device), for receipts and reports.
        'till_fiscal_device_id' => 'INT NULL DEFAULT NULL',
        'till_fiscal_day_no' => 'INT NULL DEFAULT NULL',
        'till_fiscal_global_no' => 'INT NULL DEFAULT NULL',
        'till_fiscal_verification' => 'VARCHAR(19) NULL DEFAULT NULL',
        'till_fiscal_qr_url' => 'VARCHAR(255) NULL DEFAULT NULL',
    ] as $col => $def) {
        if (!psEnsureColumn($conn, 'online_sales_ref', $col, $def)) {
            return false;
        }
    }
    // Refunds made on a desktop till (request → approve → process happened on the PC).
    require_once __DIR__ . '/sale_refund.php';
    if (psEnsureSaleRefundModule($conn)) {
        psEnsureColumn($conn, 'sale_refund_requests', 'till_refund_number', 'VARCHAR(32) NULL DEFAULT NULL');
        psEnsureColumn($conn, 'sale_refund_requests', 'desktop_till_id', 'INT NULL DEFAULT NULL');
        psEnsureColumn($conn, 'sale_refund_requests', 'till_fiscal_global_no', 'INT NULL DEFAULT NULL');
    }
    $idx = mysqli_query($conn, "SHOW INDEX FROM online_sales_ref WHERE Key_name = 'idx_osr_till_invoice'");
    if ($idx && mysqli_num_rows($idx) < 1) {
        mysqli_query($conn, 'ALTER TABLE online_sales_ref ADD KEY idx_osr_till_invoice (till_invoice_number)');
    }
    $ready = true;
    return true;
}

// ---------------------------------------------------------------- tills (web admin side)

function psDtHash(string $secret): string
{
    return hash('sha256', $secret);
}

/** XXXX-XXXX from an alphabet without 0/O/1/I/L. */
function psDtNewPairingCode(): string
{
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    return substr($out, 0, 4) . '-' . substr($out, 4);
}

function psDtNormalizePairing(string $raw): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
}

/** @return list<array<string,mixed>> */
function psDtTillList(mysqli $conn, string $company): array
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT t.*,
            (SELECT COUNT(*) FROM desktop_sync_issues i WHERE i.till_id = t.id AND i.resolved_at IS NULL) AS open_issues,
            (SELECT COUNT(*) FROM desktop_sync_map m WHERE m.till_id = t.id AND m.kind = 'sale') AS sales_synced
         FROM desktop_tills t WHERE t.company_name = ? ORDER BY t.till_code"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function psDtTillById(mysqli $conn, int $id, string $company): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT * FROM desktop_tills WHERE id = ? AND company_name = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'is', $id, $company);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Create a till for (company, branch) and its first pairing code.
 * @return array{ok:bool,error?:string,id?:int,code?:string,pairing?:string}
 */
function psDtTillCreate(mysqli $conn, string $company, string $branch, string $name, string $actor): array
{
    $company = trim($company);
    $branch = trim($branch);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($company === '' || $branch === '') {
        return ['ok' => false, 'error' => 'Choose a branch.'];
    }
    if ($name === '' || strlen($name) > 100) {
        return ['ok' => false, 'error' => 'Give the till a name (up to 100 characters), e.g. "Front counter".'];
    }
    // Next T01, T02 … for this company.
    $stmt = mysqli_prepare($conn, "SELECT till_code FROM desktop_tills WHERE company_name = ?");
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $max = 0;
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
        if (preg_match('/^T(\d+)$/', (string)$r['till_code'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    mysqli_stmt_close($stmt);
    $code = 'T' . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
    $pairing = psDtNewPairingCode();
    $pairHash = psDtHash(psDtNormalizePairing($pairing));
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + PS_DT_PAIRING_HOURS * 3600);
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO desktop_tills (company_name, branch, till_code, name, invoice_prefix, status, pairing_hash, pairing_expires, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, \'ACTIVE\', ?, ?, ?, ?)'
    );
    mysqli_stmt_bind_param($ins, 'sssssssss', $company, $branch, $code, $name, $code, $pairHash, $expires, $actor, $now);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);
        return ['ok' => false, 'error' => 'The till could not be created. Try again.'];
    }
    $id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    return ['ok' => true, 'id' => $id, 'code' => $code, 'pairing' => $pairing];
}

/** New pairing code (e.g. moving the till to a new PC). The old PC keeps working until it is paired. */
function psDtTillNewPairing(mysqli $conn, int $id, string $company): array
{
    $till = psDtTillById($conn, $id, $company);
    if (!$till || $till['status'] !== 'ACTIVE') {
        return ['ok' => false, 'error' => 'That till was not found or is revoked.'];
    }
    $pairing = psDtNewPairingCode();
    $hash = psDtHash(psDtNormalizePairing($pairing));
    $expires = date('Y-m-d H:i:s', time() + PS_DT_PAIRING_HOURS * 3600);
    $stmt = mysqli_prepare($conn, 'UPDATE desktop_tills SET pairing_hash = ?, pairing_expires = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'ssi', $hash, $expires, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true, 'pairing' => $pairing, 'till' => $till] : ['ok' => false, 'error' => 'Could not make a new code.'];
}

function psDtTillSetStatus(mysqli $conn, int $id, string $company, bool $active): bool
{
    // Revoking clears the token: the PC can no longer sync until paired again.
    $sql = $active
        ? "UPDATE desktop_tills SET status = 'ACTIVE' WHERE id = ? AND company_name = ?"
        : "UPDATE desktop_tills SET status = 'REVOKED', token_hash = NULL, pairing_hash = NULL WHERE id = ? AND company_name = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'is', $id, $company);
    $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

// ---------------------------------------------------------------- API side

/**
 * Exchange a pairing code for the till's long token.
 * @return array{ok:bool,error?:string,token?:string,till?:array}
 */
function psDtPair(mysqli $conn, string $pairingRaw, string $machine, string $version): array
{
    $norm = psDtNormalizePairing($pairingRaw);
    if (strlen($norm) !== 8) {
        return ['ok' => false, 'error' => 'Enter the 8-character pairing code from Desktop Tills.'];
    }
    $hash = psDtHash($norm);
    $stmt = mysqli_prepare($conn, "SELECT * FROM desktop_tills WHERE pairing_hash = ? AND status = 'ACTIVE' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $till = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$till || strtotime((string)$till['pairing_expires']) < time()) {
        return ['ok' => false, 'error' => 'That pairing code is not valid or has expired. Make a new one on Desktop Tills.'];
    }
    if (psPlatformCompanyIsSuspended($conn, (string)$till['company_name'])) {
        return ['ok' => false, 'error' => 'This company account is suspended.'];
    }
    $token = bin2hex(random_bytes(32));
    $tokenHash = psDtHash($token);
    $now = date('Y-m-d H:i:s');
    $machine = substr(trim($machine), 0, 120);
    $version = substr(trim($version), 0, 40);
    $id = (int)$till['id'];
    $upd = mysqli_prepare(
        $conn,
        'UPDATE desktop_tills SET token_hash = ?, pairing_hash = NULL, pairing_expires = NULL,
            machine_name = ?, app_version = ?, registered_at = ?, last_seen_at = ? WHERE id = ? AND pairing_hash = ?'
    );
    mysqli_stmt_bind_param($upd, 'sssssis', $tokenHash, $machine, $version, $now, $now, $id, $hash);
    $ok = mysqli_stmt_execute($upd) && mysqli_stmt_affected_rows($upd) === 1;
    mysqli_stmt_close($upd);
    if (!$ok) {
        return ['ok' => false, 'error' => 'That pairing code was already used.'];
    }
    $till['machine_name'] = $machine;
    return ['ok' => true, 'token' => $token, 'till' => $till];
}

/** Till for a bearer token, or null. Updates last_seen_at. */
function psDtTillForToken(mysqli $conn, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = psDtHash($token);
    $stmt = mysqli_prepare($conn, "SELECT * FROM desktop_tills WHERE token_hash = ? AND status = 'ACTIVE' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $till = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$till) {
        return null;
    }
    $id = (int)$till['id'];
    mysqli_query($conn, "UPDATE desktop_tills SET last_seen_at = '" . date('Y-m-d H:i:s') . "' WHERE id = $id");
    return $till;
}

function psDtPublicTill(array $till): array
{
    return [
        'id' => (int)$till['id'],
        'code' => (string)$till['till_code'],
        'name' => (string)$till['name'],
        'invoice_prefix' => (string)$till['invoice_prefix'],
        'company' => (string)$till['company_name'],
        'branch' => (string)$till['branch'],
    ];
}

/** Can this user sell on this till? (company match, not Super Admin, branch rule). */
function psDtUserMayUseTill(array $user, array $till): array
{
    $level = (string)($user['level'] ?? '');
    if (psPlatformIsSuperAdminLevel($level)) {
        return ['ok' => false, 'error' => 'Super Admin accounts cannot sell on a till.'];
    }
    if (strcasecmp(trim((string)$user['company_name']), trim((string)$till['company_name'])) !== 0) {
        return ['ok' => false, 'error' => 'This account belongs to another company.'];
    }
    $lv = strtolower(trim($level));
    $isAdmin = in_array($lv, ['admin', 'management'], true);
    if (!$isAdmin && strcasecmp(trim((string)$user['branch']), trim((string)$till['branch'])) !== 0) {
        return ['ok' => false, 'error' => 'This account works at ' . trim((string)$user['branch']) . ', not ' . $till['branch'] . '.'];
    }
    return ['ok' => true];
}

/** Everything a till needs to sell offline. */
function psDtCatalogue(mysqli $conn, array $till): array
{
    require_once __DIR__ . '/barcode.php';
    require_once __DIR__ . '/catalog_setup.php';
    $company = (string)$till['company_name'];
    $branch = (string)$till['branch'];
    psEnsureGoodsStatus($conn);
    psEnsureGoodsBarcodeSchema($conn);
    psEnsureTaxModule($conn);
    psTaxEnsureCompanyHasRates($conn, $company);
    psCatalogEnsureSchema($conn);
    require_once __DIR__ . '/product_packs.php';
    $packsOn = psPackEnsureSchema($conn);
    $packsBy = $packsOn ? psPackListForCompany($conn, $company, true) : [];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT product_id, product, price, button_category, category, pack_size, unit_of_measure, barcode, tax_code, status,
                sell_by_measure, variant_group, variant_base, variant_label, cost_price" . ($packsOn ? ', base_unit' : '') . "
         FROM goods WHERE branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = '')
         ORDER BY product, product_id"
    );
    mysqli_stmt_bind_param($stmt, 'ss', $branch, $company);
    mysqli_stmt_execute($stmt);
    $products = [];
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) {
        $group = psNormalizeCategoryField($r['button_category'] ?? '');
        if ($group === '' || $group === '1') {
            $group = psNormalizeCategoryField($r['category'] ?? '');
        }
        $cents = psDecimalCents(trim((string)$r['price']));
        // 2026-10-17 — cost price for the till's Profit & Margin page (Management and up only); null when not set.
        $costCents = psDecimalCents(trim((string)($r['cost_price'] ?? '')));
        $products[] = [
            'product_id' => (string)$r['product_id'],
            'name' => (string)$r['product'],
            'price' => $cents === null ? null : psMoneyFromCents($cents),
            'group' => $group !== '' ? $group : 'General',
            'category' => (string)$r['category'],
            'pack_size' => (string)$r['pack_size'],
            'unit' => (string)($r['unit_of_measure'] ?? ''),
            'barcode' => (string)($r['barcode'] ?? ''),
            'tax_code' => strtoupper(trim((string)($r['tax_code'] ?? ''))),
            'active' => strtoupper(trim((string)$r['status'])) === PS_PRODUCT_STATUS_ACTIVE,
            'sell_by_measure' => !empty($r['sell_by_measure']),
            'variant_group' => (string)($r['variant_group'] ?? ''),
            'variant_base' => (string)($r['variant_base'] ?? ''),
            'variant_label' => (string)($r['variant_label'] ?? ''),
            'cost' => ($costCents !== null && $costCents > 0) ? psMoneyFromCents($costCents) : null,
            // 2026-10-28 packs: base unit label and the packs (company base price; null = not sold, receive / count only).
            'base_unit' => trim((string)($r['base_unit'] ?? '')),
            'packs' => array_map(static fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'factor' => $p['factor'],
                'price' => $p['price'], 'barcode' => $p['barcode']], $packsBy[(string)$r['product_id']] ?? []),
        ];
    }
    mysqli_stmt_close($stmt);

    $groups = [];
    $g = mysqli_prepare($conn, "SELECT name, color, sort_order, active FROM product_categories WHERE company_name = ? AND kind = 'button' ORDER BY sort_order, name");
    if ($g) {
        mysqli_stmt_bind_param($g, 's', $company);
        mysqli_stmt_execute($g);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($g), MYSQLI_ASSOC) as $r) {
            $groups[] = ['name' => (string)$r['name'], 'color' => (string)($r['color'] ?? ''), 'sort_order' => (int)$r['sort_order'], 'active' => (int)$r['active'] === 1];
        }
        mysqli_stmt_close($g);
    }

    $taxRates = [];
    $tr = mysqli_prepare(
        $conn,
        'SELECT code, description, classification, rate_percent, pricing_mode, effective_from, effective_to
         FROM tax_rates WHERE is_active = 1 AND company_name = ? ORDER BY code, effective_from'
    );
    if ($tr) {
        mysqli_stmt_bind_param($tr, 's', $company);
        mysqli_stmt_execute($tr);
        $trRes = mysqli_stmt_get_result($tr);
        while ($trRes && ($r = mysqli_fetch_assoc($trRes))) {
            $taxRates[] = [
                'code' => strtoupper((string)$r['code']),
                'description' => (string)$r['description'],
                'classification' => (string)$r['classification'],
                'rate_percent' => number_format((float)$r['rate_percent'], 4, '.', ''),
                'pricing_mode' => (string)$r['pricing_mode'],
                'effective_from' => (string)$r['effective_from'],
                'effective_to' => $r['effective_to'] === null ? null : (string)$r['effective_to'],
            ];
        }
        mysqli_stmt_close($tr);
    }

    $units = [];
    foreach (psUnitList($conn, false) as $u) {
        $units[] = ['code' => (string)$u['code'], 'name' => (string)$u['name'], 'dimension' => (string)$u['dimension']];
    }

    $methods = psPaymentMethods();
    unset($methods['CREDIT']); // account sales need live credit checks — online web POS only for now

    return [
        'till' => psDtPublicTill($till),
        'server_time' => date('Y-m-d H:i:s'),
        'tax_enforcement' => psTaxGetEnforcement($conn, $company),
        // 2026-10-17: cash out by a Till user above this needs a supervisor sign-in on the till too.
        'cash_out_approval_limit' => (function () use ($conn, $till) {
            require_once __DIR__ . '/cash_controls.php';
            return psCashOutApprovalLimit($conn, (string)$till['company_name']);
        })(),
        // 2026-10-18: Till users may add new customers at the till (company switch on the Customers page).
        'till_add_customers' => psTillMayAddCustomers($conn, (string)$till['company_name']),
        // 2026-11-06: split currency (company switch on the Exchange Rates page): payments / change in another currency.
        'split_currency' => psCompanySplitCurrency($conn, (string)$till['company_name']),
        // 2026-11-06: split payment (company switch on the Exchange Rates page): "Add another payment" at the till.
        'split_payment' => psCompanySplitPayment($conn, (string)$till['company_name']),
        'picking_slip' => (function () use ($conn, $till) {
            require_once __DIR__ . '/picking_slip.php';
            return psPickingSlipEnabled($conn, (string)$till['company_name']);
        })(),
        // 2026-10-31: restaurant order numbers (the till numbers its own orders T01-001 … per till session).
        'order_numbers' => psOrderNumbersEnabled($conn, (string)$till['company_name']),
        // 2026-11-13: restaurants — the till asks Sit in / Take away on every sale.
        'dine_option' => psDineOptionEnabled($conn, (string)$till['company_name']),
        // 2026-11-02: company role permissions {role: {key: bool}} (Role Permissions on the web).
        'role_permissions' => (function () use ($conn, $till) {
            require_once __DIR__ . '/role_permissions.php';
            return psRolePermExport($conn, (string)$till['company_name']);
        })(),
        'products' => $products,
        'button_groups' => $groups,
        'tax_rates' => $taxRates,
        'units' => $units,
        'payment_methods' => $methods,
    ];
}

/**
 * Phase 21b — everything a standalone desktop POS needs to run without the web: company + branch,
 * users who may sell at this branch (with bcrypt hashes — plaintext passwords are hashed here, never sent),
 * customers with credit accounts and balances, branch stock on hand, plus the catalogue.
 */
/**
 * Multi-currency (2026-10-20): what a till needs to sell in other currencies offline — the company base, its enabled
 * currencies, and for each the rate in force now plus every rate that starts later (the till picks the one in force at
 * the moment of each sale). Rates are exact fractions (num/den), the same the server converts with.
 */
function psDtFxPayload(mysqli $conn, string $company): array
{
    require_once __DIR__ . '/currency.php';
    psFxEnsureSchema($conn);
    $base = psFxBase($conn, $company);
    $now = date('Y-m-d H:i:s');
    $out = ['base' => $base, 'currencies' => [], 'rates' => []];
    foreach (psFxCompanyCurrencies($conn, $company) as $code => $c) {
        $out['currencies'][] = ['code' => $code, 'name' => (string)$c['name'], 'symbol' => (string)$c['symbol'],
            'decimals' => max(0, min(2, (int)$c['decimals'])), 'position' => ($c['symbol_position'] ?? 'before') === 'after' ? 'after' : 'before'];
        if ($code === $base) {
            continue;
        }
        $moments = [$now];
        $st = mysqli_prepare($conn, "SELECT DISTINCT effective_from FROM exchange_rates WHERE company_name = ? AND status = 'ACTIVE'
            AND effective_from > ? AND (from_code = ? OR to_code = ?) ORDER BY effective_from LIMIT 50");
        if ($st) {
            mysqli_stmt_bind_param($st, 'ssss', $company, $now, $code, $code);
            mysqli_stmt_execute($st);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($st)) as $r) {
                $moments[] = (string)$r[0];
            }
            mysqli_stmt_close($st);
        }
        // A cross rate via the base can also change when the other leg changes.
        $st = mysqli_prepare($conn, "SELECT DISTINCT effective_from FROM exchange_rates WHERE company_name = ? AND status = 'ACTIVE'
            AND effective_from > ? AND (from_code = ? OR to_code = ?) ORDER BY effective_from LIMIT 50");
        if ($st) {
            mysqli_stmt_bind_param($st, 'ssss', $company, $now, $base, $base);
            mysqli_stmt_execute($st);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($st)) as $r) {
                $moments[] = (string)$r[0];
            }
            mysqli_stmt_close($st);
        }
        $moments = array_values(array_unique($moments));
        sort($moments);
        $last = null;
        foreach ($moments as $i => $at) {
            $snap = psFxSnapshot($conn, $company, $code, $at);
            if (empty($snap['ok']) || ($last !== null && $last === $snap['rate'] . '|' . $snap['ids'])) {
                continue;
            }
            $last = $snap['rate'] . '|' . $snap['ids'];
            // The current one is stamped far in the past so the till can use it for any sale until the next rate starts.
            $out['rates'][] = ['code' => $code, 'num' => $snap['frac'][0], 'den' => $snap['frac'][1], 'rate' => $snap['rate'],
                'ids' => $snap['ids'], 'path' => $snap['path'], 'effective_from' => $i === 0 ? '2000-01-01 00:00:00' : $at];
        }
    }
    return $out;
}

function psDtSeed(mysqli $conn, array $till): array
{
    require_once __DIR__ . '/customer_master.php';
    require_once __DIR__ . '/customer_account.php';
    $company = (string)$till['company_name'];
    $branch = (string)$till['branch'];
    $data = psDtCatalogue($conn, $till);

    $info = ['name' => $company, 'display_name' => $company, 'phone' => '', 'email' => ''];
    $c = mysqli_prepare($conn, 'SELECT company_name, display_name, contact_phone, contact_email FROM companies WHERE company_name = ? LIMIT 1');
    if ($c) {
        mysqli_stmt_bind_param($c, 's', $company);
        mysqli_stmt_execute($c);
        if ($r = mysqli_fetch_assoc(mysqli_stmt_get_result($c))) {
            $info = ['name' => $company, 'display_name' => trim((string)$r['display_name']) ?: $company,
                'phone' => (string)($r['contact_phone'] ?? ''), 'email' => (string)($r['contact_email'] ?? '')];
        }
        mysqli_stmt_close($c);
    }
    $data['company'] = $info;

    psSecurityEnsureUsersPasswordColumn($conn);
    $users = [];
    $u = mysqli_prepare($conn, 'SELECT id, firstname, lastname, email, password, level, branch, status, lock_status, company_name FROM users WHERE company_name = ?');
    mysqli_stmt_bind_param($u, 's', $company);
    mysqli_stmt_execute($u);
    $companyEmails = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($u), MYSQLI_ASSOC) as $row) {
        $companyEmails[] = strtolower(trim((string)$row['email']));
        if (!psDtUserMayUseTill($row, $till)['ok']) {
            continue;
        }
        $stored = (string)$row['password'];
        $hash = psSecurityIsHashedPassword($stored) ? $stored : password_hash($stored, PASSWORD_BCRYPT);
        if (str_starts_with($hash, '$argon')) {
            continue; // not bcrypt: the cashier must change password on the web first
        }
        $users[] = [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'name' => trim($row['firstname'] . ' ' . $row['lastname']) ?: (string)$row['email'],
            'level' => (string)$row['level'],
            'branch' => (string)$row['branch'],
            'password_hash' => $hash,
            'locked' => psSecurityUserIsLocked($row),
        ];
    }
    mysqli_stmt_close($u);
    $data['users'] = $users;

    // 2026-10-18: customers carry their company (customer_master.company_name).
    $customers = [];
    if (psEnsureCustomerMasterSchema($conn) && psEnsureCustomerAccountModule($conn)) {
        $tillCompany = (string)$till['company_name'];
        $sql = "SELECT m.id, m.customer_code, m.first_name, m.last_name, m.business_name, m.phone, m.status,
                       m.customer_type, m.email, m.address, m.notes,
                       a.id AS account_id, a.account_number, a.status AS account_status, a.credit_status, a.credit_limit,
                       " . (psFxHasColumn($conn, 'customer_accounts', 'currency') ? 'a.currency' : 'NULL') . " AS account_currency,
                       (SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) FROM customer_account_ledger l WHERE l.customer_account_id = a.id) AS balance
                FROM customer_master m LEFT JOIN customer_accounts a ON a.customer_master_id = m.id
                WHERE m.company_name = ? ORDER BY m.id";
        $q = mysqli_prepare($conn, $sql);
        if ($q) {
            mysqli_stmt_bind_param($q, 's', $tillCompany);
            mysqli_stmt_execute($q);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC) as $r) {
                $name = trim((string)$r['business_name']) !== '' ? trim((string)$r['business_name']) : trim($r['first_name'] . ' ' . $r['last_name']);
                $customers[] = [
                    'id' => (int)$r['id'],
                    'code' => (string)$r['customer_code'],
                    'name' => $name,
                    'phone' => (string)$r['phone'],
                    'active' => strtoupper((string)$r['status']) === 'ACTIVE',
                    // 2026-10-17: the till's Customers page edits these.
                    'type' => strtoupper((string)($r['customer_type'] ?? '')) === 'BUSINESS' ? 'BUSINESS' : 'INDIVIDUAL',
                    'first_name' => (string)($r['first_name'] ?? ''),
                    'last_name' => (string)($r['last_name'] ?? ''),
                    'business_name' => (string)($r['business_name'] ?? ''),
                    'email' => (string)($r['email'] ?? ''),
                    'address' => (string)($r['address'] ?? ''),
                    'notes' => (string)($r['notes'] ?? ''),
                    'account' => $r['account_id'] === null ? null : [
                        'id' => (int)$r['account_id'],
                        'number' => (string)$r['account_number'],
                        'status' => strtoupper((string)$r['account_status']),
                        'credit_status' => strtoupper((string)($r['credit_status'] ?: 'NORMAL')),
                        'credit_limit' => number_format((float)$r['credit_limit'], 2, '.', ''),
                        'balance' => number_format((float)$r['balance'], 2, '.', ''),
                        'currency' => (string)($r['account_currency'] ?? '') !== '' ? (string)$r['account_currency'] : psFxBase($conn, $tillCompany),
                    ],
                ];
            }
            mysqli_stmt_close($q);
        }
    }
    $data['customers'] = $customers;
    // Products with an opening balance at this branch: quantity on hand (others are not stock-controlled).
    $data['stock'] = (object)psControlledLedgerBalances($conn, $branch);
    // 2026-10-17: open quotations of this branch (load into the till's cart; quotes made on any till or the web).
    require_once __DIR__ . '/quotation.php';
    $data['quotations'] = psQuoteEnsureSchema($conn) ? psDtQuotesForTill($conn, $company, $branch) : [];
    require_once __DIR__ . '/cash_controls.php';
    $data['allow_negative_stock'] = psCompanyAllowsNegativeStock($conn, $company); // 2026-10-18: per company
    $data['fx'] = psDtFxPayload($conn, $company); // 2026-10-20: currencies + current / future rates for offline selling
    return $data;
}

// ---------------------------------------------------------------- push

function psDtIssue(mysqli $conn, array $till, string $kind, array $f): void
{
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO desktop_sync_issues (company_name, branch, till_id, kind, client_uuid, invoice_number, till_ref, product_id, detail, till_value, server_value, occurred_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'' . date('Y-m-d H:i:s') . '\')'
    );
    if (!$stmt) {
        return;
    }
    $company = (string)$till['company_name'];
    $branch = (string)$till['branch'];
    $tillId = (int)$till['id'];
    $uuid = $f['client_uuid'] ?? null;
    $inv = isset($f['invoice_number']) ? (string)$f['invoice_number'] : null;
    $tref = $f['till_ref'] ?? null;
    $pid = $f['product_id'] ?? null;
    $detail = substr((string)($f['detail'] ?? ''), 0, 500);
    $tv = isset($f['till_value']) ? substr((string)$f['till_value'], 0, 64) : null;
    $sv = isset($f['server_value']) ? substr((string)$f['server_value'], 0, 64) : null;
    $at = $f['occurred_at'] ?? null;
    mysqli_stmt_bind_param($stmt, 'ssisssssssss', $company, $branch, $tillId, $kind, $uuid, $inv, $tref, $pid, $detail, $tv, $sv, $at);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function psDtHasOpenIssue(mysqli $conn, string $uuid, string $kind): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM desktop_sync_issues WHERE client_uuid = ? AND kind = ? AND resolved_at IS NULL LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ss', $uuid, $kind);
    mysqli_stmt_execute($stmt);
    $found = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $found;
}

function psDtMapGet(mysqli $conn, string $uuid): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT * FROM desktop_sync_map WHERE client_uuid = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $uuid);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function psDtMapPut(mysqli $conn, string $uuid, int $tillId, string $kind, string $serverRef, ?string $tillRef, array $result): bool
{
    $json = json_encode($result);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO desktop_sync_map (client_uuid, till_id, kind, server_ref, till_ref, result_json, synced_at) VALUES (?, ?, ?, ?, ?, ?, \'' . date('Y-m-d H:i:s') . '\')'
    );
    mysqli_stmt_bind_param($stmt, 'sissss', $uuid, $tillId, $kind, $serverRef, $tillRef, $json);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function psDtValidUuid($v): bool
{
    return is_string($v) && preg_match('/^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}$/', $v) === 1;
}

/** 'Y-m-d H:i:s' from the till, clamped to not be in the future (+10 min clock slack). */
function psDtTime($raw): ?string
{
    $raw = trim((string)$raw);
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw);
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $raw) {
        return null;
    }
    if ($dt->getTimestamp() > time() + 600) {
        // Till clock ahead of the server: keep the record, stamp it with the server time.
        return date('Y-m-d H:i:s');
    }
    return $raw;
}

/** Live user row for an email in the till's company (null when unknown). */
function psDtCashier(mysqli $conn, array $till, string $email): ?array
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT id, email, level, branch, company_name FROM users WHERE email = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || strcasecmp(trim((string)$row['company_name']), trim((string)$till['company_name'])) !== 0) {
        return null;
    }
    return $row;
}

/** sessions.id for a pushed till-session uuid owned by this till. */
function psDtSessionId(mysqli $conn, array $till, $sessionUuid): ?int
{
    if (!psDtValidUuid($sessionUuid)) {
        return null;
    }
    $map = psDtMapGet($conn, strtolower($sessionUuid));
    if (!$map || $map['kind'] !== 'session_open' || (int)$map['till_id'] !== (int)$till['id']) {
        return null;
    }
    return (int)$map['server_ref'];
}

/**
 * Process one pushed event. Each event is its own DB transaction.
 * @return array{client_uuid:string,status:string,error?:string,server_ref?:string,invoice_number?:string,issues?:int}
 *   status: ok | duplicate | rejected (permanent, needs a person) | retry (try again later, e.g. waiting for its session)
 */
function psDtProcessEvent(mysqli $conn, array $till, array $ev): array
{
    $uuid = strtolower(trim((string)($ev['client_uuid'] ?? '')));
    $type = (string)($ev['type'] ?? '');
    if (!psDtValidUuid($uuid)) {
        return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => 'client_uuid is not a valid GUID.'];
    }
    $existing = psDtMapGet($conn, $uuid);
    if ($existing) {
        if ((int)$existing['till_id'] !== (int)$till['id']) {
            return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => 'This id belongs to another till.'];
        }
        $prev = json_decode((string)$existing['result_json'], true) ?: [];
        $prev['client_uuid'] = $uuid;
        $prev['status'] = 'duplicate';
        return $prev;
    }
    $handlers = [
        'session_open' => 'psDtPushSessionOpen',
        'sale' => 'psDtPushSale',
        'cash_movement' => 'psDtPushCashMovement',
        'session_close' => 'psDtPushSessionClose',
        'refund' => 'psDtPushRefund',
        'receipt_reprint' => 'psDtPushReprint',
        'customer_save' => 'psDtPushCustomerSave',
        'account_payment' => 'psDtPushAccountPayment',
        'quotation_save' => 'psDtPushQuotationSave',
        'quotation_cancel' => 'psDtPushQuotationCancel',
    ];
    if (!isset($handlers[$type])) {
        return ['client_uuid' => $uuid, 'status' => 'rejected', 'error' => 'Unknown event type.'];
    }
    // 2026-10-17: create / check the customer tables BEFORE the transaction. "CREATE TABLE IF NOT EXISTS" and
    // "ALTER TABLE" commit any open transaction in MySQL, which would save half an event (seen in testing: an
    // account payment posted twice). Each check runs once per request (static flags).
    if (in_array($type, ['sale', 'refund', 'customer_save', 'account_payment'], true)) {
        require_once __DIR__ . '/customer_account_payment.php';
        psEnsureCustomerSeq($conn);
        psEnsureCustomerAuditSchema($conn);
        psEnsureCustomerAccountSeq($conn);
        psEnsureCustomerAccountsCreditControlColumns($conn);
        psEnsureCustomerAccountPaymentModule($conn);
        psEnsureCustomerAccountPaymentSeq($conn);
    }
    if (in_array($type, ['sale', 'quotation_save', 'quotation_cancel'], true)) {
        require_once __DIR__ . '/quotation.php';
        psQuoteEnsureSchema($conn);
    }
    psDtWarmSchemas($conn);
    if ($type === 'sale') {
        require_once __DIR__ . '/product_packs.php';
        psPackEnsureSchema($conn); // DDL before the transaction (pack lines)
    }
    if (!mysqli_begin_transaction($conn)) {
        return ['client_uuid' => $uuid, 'status' => 'retry', 'error' => 'Server busy.'];
    }
    $issues = [];
    try {
        $r = $handlers[$type]($conn, $till, $ev, $uuid, $issues);
    } catch (Throwable $e) {
        error_log('desktop push: ' . $e->getMessage());
        $r = ['status' => 'retry', 'error' => 'Server error. It will be retried.'];
    }
    $r['client_uuid'] = $uuid;
    if ($r['status'] !== 'ok') {
        mysqli_rollback($conn);
        if ($r['status'] === 'rejected' && !psDtHasOpenIssue($conn, $uuid, 'REJECTED')) {
            psDtIssue($conn, $till, 'REJECTED', ['client_uuid' => $uuid, 'till_ref' => $ev['till_invoice_number'] ?? null, 'detail' => ucfirst(str_replace('_', ' ', $type)) . ': ' . ($r['error'] ?? '')]);
        }
        return $r;
    }
    $r['issues'] = count($issues);
    $mapped = psDtMapPut($conn, $uuid, (int)$till['id'], $type, (string)($r['server_ref'] ?? ''), $ev['till_invoice_number'] ?? null, $r);
    if (!$mapped) {
        $dup = mysqli_errno($conn) === 1062;
        mysqli_rollback($conn);
        return $dup ? (psDtProcessEvent($conn, $till, $ev)) : ['client_uuid' => $uuid, 'status' => 'retry', 'error' => 'Could not record the sync.'];
    }
    foreach ($issues as $iss) {
        psDtIssue($conn, $till, $iss['kind'], $iss + ['client_uuid' => $uuid]);
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        return ['client_uuid' => $uuid, 'status' => 'retry', 'error' => 'Could not save. It will be retried.'];
    }
    $tid = (int)$till['id'];
    mysqli_query($conn, "UPDATE desktop_tills SET last_push_at = '" . date('Y-m-d H:i:s') . "' WHERE id = $tid");
    return $r;
}

function psDtRegister(mysqli $conn, array $till): ?int
{
    if (!empty($till['register_id'])) {
        return (int)$till['register_id'];
    }
    $branch = (string)$till['branch'];
    $name = 'Desktop #' . (int)$till['id'] . ' ' . $till['till_code'];
    $stmt = mysqli_prepare($conn, "INSERT INTO registers (branch, name, status) VALUES (?, ?, 'ACTIVE')");
    mysqli_stmt_bind_param($stmt, 'ss', $branch, $name);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok) {
        $rid = (int)mysqli_insert_id($conn);
    } else {
        $q = mysqli_prepare($conn, 'SELECT id FROM registers WHERE branch = ? AND name = ? LIMIT 1');
        mysqli_stmt_bind_param($q, 'ss', $branch, $name);
        mysqli_stmt_execute($q);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
        mysqli_stmt_close($q);
        if (!$row) {
            return null;
        }
        $rid = (int)$row['id'];
    }
    $tid = (int)$till['id'];
    mysqli_query($conn, "UPDATE desktop_tills SET register_id = $rid WHERE id = $tid");
    return $rid;
}

function psDtPushSessionOpen(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    $openedAt = psDtTime($ev['opened_at'] ?? '');
    if ($openedAt === null) {
        return ['status' => 'rejected', 'error' => 'opened_at is not a valid time.'];
    }
    $float = psDecimalCents((string)($ev['opening_float'] ?? '0'));
    if ($float === null) {
        return ['status' => 'rejected', 'error' => 'Opening float is not valid.'];
    }
    $email = trim((string)($ev['cashier_email'] ?? ''));
    $cashier = psDtCashier($conn, $till, $email);
    if ($cashier === null) {
        $issues[] = ['kind' => 'CASHIER_UNKNOWN', 'detail' => 'Till opened by ' . $email . ' who is not a user of this company.'];
    }
    $registerId = psDtRegister($conn, $till);
    if ($registerId === null) {
        return ['status' => 'retry', 'error' => 'Register could not be created.'];
    }
    $branch = (string)$till['branch'];
    $cashierId = $cashier ? (int)$cashier['id'] : null;
    $floatSql = psMoneyFromCents($float);
    $sql = 'INSERT INTO sessions (session_name, session_start, session_end, cashier_id, cashier_email, branch, register_id, status, opening_float, opened_at, closed_at, created_at, updated_at)
            VALUES (?, ?, \'\', ?, ?, ?, ?, ?, ?, ?, NULL, \'' . date('Y-m-d H:i:s') . '\', \'' . date('Y-m-d H:i:s') . '\')';
    $status = 'OPEN';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssississs', $email, $openedAt, $cashierId, $email, $branch, $registerId, $status, $floatSql, $openedAt);
        $ok = mysqli_stmt_execute($stmt);
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        if ($ok) {
            break;
        }
        if ($errno === 1062 && $status === 'OPEN') {
            // The cashier already has an OPEN till on the server (web or another PC). One open till
            // per cashier is a web rule; keep this desktop session out of that guard and report it.
            $status = 'DESKTOP_OPEN';
            $issues[] = ['kind' => 'SESSION_CONFLICT', 'detail' => $email . ' already had a till open on the server when this desktop till opened. Both are kept.'];
            continue;
        }
        return ['status' => 'retry', 'error' => 'Till session could not be saved.'];
    }
    $sessionId = (int)mysqli_insert_id($conn);
    if ($sessionId < 1) {
        return ['status' => 'retry', 'error' => 'Till session could not be saved.'];
    }
    if ($float > 0) {
        $posted = psCashMovementPostOpeningFloat($conn, $sessionId, $branch, $floatSql, $email !== '' ? $email : 'desktop', $openedAt);
        if (empty($posted['ok'])) {
            return ['status' => 'retry', 'error' => 'Opening float could not be saved.'];
        }
    }
    return ['status' => 'ok', 'server_ref' => (string)$sessionId];
}

function psDtPushCashMovement(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    $sessionId = psDtSessionId($conn, $till, $ev['session_uuid'] ?? '');
    if ($sessionId === null) {
        return ['status' => 'retry', 'error' => 'Waiting for its till session to sync.'];
    }
    $type = strtoupper(trim((string)($ev['movement_type'] ?? '')));
    if (!in_array($type, [PS_CASH_MOV_CASH_IN, PS_CASH_MOV_CASH_OUT], true)) {
        return ['status' => 'rejected', 'error' => 'Only CASH_IN and CASH_OUT can be pushed.'];
    }
    $at = psDtTime($ev['occurred_at'] ?? '');
    $cents = psDecimalCents((string)($ev['amount'] ?? ''));
    if ($at === null || $cents === null || $cents <= 0) {
        return ['status' => 'rejected', 'error' => 'Amount or time is not valid.'];
    }
    $r = psCashMovementInsert($conn, [
        'till_session_id' => $sessionId,
        'branch' => (string)$till['branch'],
        'movement_at' => $at,
        'movement_type' => $type,
        'amount' => psMoneyFromCents($cents),
        'reference_type' => 'DESKTOP',
        'reference_id' => $uuid,
        'reason' => substr(trim((string)($ev['reason'] ?? '')), 0, 500),
        'description' => substr(trim((string)($ev['description'] ?? '')) . ' (desktop ' . $till['till_code'] . ')', 0, 500),
        'created_by' => trim((string)($ev['cashier_email'] ?? '')) ?: 'desktop',
        // Multi-currency (2026-10-20): the drawer currency (blank / older tills = the base); rate taken at the movement time.
        'currency' => psFxNormalizeCode($ev['currency'] ?? ''),
    ]);
    if (empty($r['ok'])) {
        return ['status' => 'retry', 'error' => (string)($r['error'] ?? 'Cash movement could not be saved.')];
    }
    return ['status' => 'ok', 'server_ref' => (string)$r['id']];
}

/** 2026-10-17: a receipt printed again on a desktop till → receipt_reprints (Cash Audit History › Receipt reprints). */
function psDtPushReprint(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    require_once __DIR__ . '/cash_controls.php';
    $at = psDtTime($ev['occurred_at'] ?? '');
    $invoice = substr(trim((string)($ev['server_invoice'] ?? '')) ?: trim((string)($ev['till_invoice_number'] ?? '')), 0, 64);
    if ($at === null || $invoice === '') {
        return ['status' => 'rejected', 'error' => 'Reprint time or receipt number is not valid.'];
    }
    $approved = trim((string)($ev['approved_by'] ?? ''));
    $doc = strtoupper(trim((string)($ev['document'] ?? 'RECEIPT')));
    psReceiptReprintLog($conn, $invoice, (string)$till['branch'], substr('desktop ' . $till['till_code'], 0, 20),
        (string)$till['company_name'], trim((string)($ev['cashier_email'] ?? '')) ?: 'desktop', $at,
        $approved !== '' ? $approved : null, $doc);
    return ['status' => 'ok', 'server_ref' => $invoice];
}

function psDtPushSessionClose(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    $sessionId = psDtSessionId($conn, $till, $ev['session_uuid'] ?? '');
    if ($sessionId === null) {
        return ['status' => 'retry', 'error' => 'Waiting for its till session to sync.'];
    }
    $closedAt = psDtTime($ev['closed_at'] ?? '');
    $counted = psDecimalCents((string)($ev['counted_cash'] ?? ''));
    $tillExpected = psDecimalCents((string)($ev['expected_cash'] ?? ''));
    if ($closedAt === null || $counted === null || $tillExpected === null) {
        return ['status' => 'rejected', 'error' => 'Close time or cash amounts are not valid.'];
    }
    $bal = psCashMovementBalance($conn, $sessionId);
    if (empty($bal['ok'])) {
        return ['status' => 'retry', 'error' => 'Cash balance could not be calculated.'];
    }
    $serverExpected = psDecimalCents(ltrim((string)$bal['balance'], '-')) ?? 0;
    if (str_starts_with((string)$bal['balance'], '-')) {
        $serverExpected = -$serverExpected;
    }
    if ($serverExpected !== $tillExpected) {
        $issues[] = ['kind' => 'CASH_DIFF', 'detail' => 'Till session #' . $sessionId . ' closed: the till expected different cash than the server ledger.',
            'till_value' => psMoneyFromCents($tillExpected), 'server_value' => psMoneyFromCents($serverExpected), 'occurred_at' => $closedAt];
    }
    $variance = $counted - $serverExpected;
    $closedBy = trim((string)($ev['cashier_email'] ?? ''));
    // Multi-currency (2026-10-20): the till's counts of its other drawer currencies (the fields above are the base drawer).
    $otherText = [];
    foreach ((is_array($ev['currency_counts'] ?? null) ? $ev['currency_counts'] : []) as $cc) {
        $cur = psFxNormalizeCode($cc['currency'] ?? '');
        $cCounted = psDecimalCents(ltrim((string)($cc['counted_cash'] ?? ''), '-'));
        $cTill = (string)($cc['expected_cash'] ?? '');
        if ($cur === '' || $cCounted === null) {
            continue;
        }
        $cBal = psCashMovementBalance($conn, $sessionId, $cur);
        $cServer = !empty($cBal['ok']) ? (string)$cBal['balance'] : '?';
        if ($cServer !== '?' && psFxMoneyToCents($cServer) !== psFxMoneyToCents($cTill)) {
            $issues[] = ['kind' => 'CASH_DIFF', 'detail' => 'Till session #' . $sessionId . ' closed: the till expected different ' . $cur . ' cash than the server ledger.',
                'till_value' => $cTill . ' ' . $cur, 'server_value' => $cServer . ' ' . $cur, 'occurred_at' => $closedAt];
        }
        $cVar = $cServer !== '?' ? psFxCentsToMoney($cCounted - (int)psFxMoneyToCents($cServer)) : '?';
        $otherText[] = $cur . ': expected ' . $cServer . ', counted ' . psMoneyFromCents($cCounted) . ', variance ' . $cVar
            . ' (' . strtolower((string)($cc['status'] ?? '')) . (trim((string)($cc['notes'] ?? '')) !== '' ? ', ' . trim((string)$cc['notes']) : '') . ')';
    }
    $notes = substr('Closed on desktop till ' . $till['till_code'] . '. ' . trim((string)($ev['notes'] ?? ''))
        . ($otherText ? "\n[Other currencies] " . implode('; ', $otherText) : ''), 0, 2000);
    $exp = psMoneyFromCents($serverExpected);
    $act = psMoneyFromCents($counted);
    $var = psMoneyFromCents($variance);
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE sessions SET status = 'CLOSED', session_end = ?, closed_at = ?, updated_at = '" . date('Y-m-d H:i:s') . "',
            expected_cash = ?, actual_cash = ?, variance = ?, closed_by = ?, closure_notes = ?, reconciliation_status = ?
         WHERE id = ? AND status IN ('OPEN', 'DESKTOP_OPEN')"
    );
    // The till counted the drawer (FINAL reconciliation) before closing; keep its status (BALANCED / SHORTAGE / SURPLUS).
    $recon = is_array($ev['reconciliation'] ?? null) ? $ev['reconciliation'] : null;
    $reconStatus = $recon !== null && in_array((string)($recon['status'] ?? ''), ['BALANCED', 'SHORTAGE', 'SURPLUS'], true)
        ? (string)$recon['status'] : 'DESKTOP';
    mysqli_stmt_bind_param($stmt, 'ssssssssi', $closedAt, $closedAt, $exp, $act, $var, $closedBy, $notes, $reconStatus, $sessionId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['status' => 'retry', 'error' => 'Till session could not be closed.'];
    }
    return ['status' => 'ok', 'server_ref' => (string)$sessionId, 'expected_cash' => $exp, 'variance' => $var];
}

/**
 * A completed offline sale. Writes the same tables as psCompleteSale (header, lines, payment,
 * stock movements, cash movement) using the till's prices and tax, then reports differences.
 */
function psDtPushSale(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    $sessionId = psDtSessionId($conn, $till, $ev['session_uuid'] ?? '');
    if ($sessionId === null) {
        return ['status' => 'retry', 'error' => 'Waiting for its till session to sync.'];
    }
    $soldAt = psDtTime($ev['sold_at'] ?? '');
    if ($soldAt === null) {
        return ['status' => 'rejected', 'error' => 'sold_at is not a valid time.'];
    }
    $tillInvoice = trim((string)($ev['till_invoice_number'] ?? ''));
    $prefix = (string)$till['invoice_prefix'];
    if (!preg_match('/^' . preg_quote($prefix, '/') . '-\d{1,9}$/', $tillInvoice)) {
        return ['status' => 'rejected', 'error' => 'Till invoice number must look like ' . $prefix . '-000123.'];
    }
    $branch = (string)$till['branch'];
    $company = (string)$till['company_name'];
    $saleDate = substr($soldAt, 0, 10);
    $email = trim((string)($ev['cashier_email'] ?? ''));
    $cashier = psDtCashier($conn, $till, $email);
    if ($cashier === null) {
        $issues[] = ['kind' => 'CASHIER_UNKNOWN', 'till_ref' => $tillInvoice, 'detail' => 'Sale by ' . $email . ' who is not a user of this company.', 'occurred_at' => $soldAt];
    }

    // --- multi-currency (2026-10-20): the till sold in a currency at the rate it had synced. The server's own rate for
    // sold_at is used when it agrees; when it differs (rate added / changed after the till last synced) the till's rate
    // is kept — it is what the customer paid at — and reported. A foreign sale with no rate at all is refused.
    $fxBase = psFxBase($conn, $company);
    $saleCurrency = psFxNormalizeCode($ev['currency'] ?? '') ?: $fxBase;
    $fxSnap = null;
    if ($saleCurrency === $fxBase) {
        $fxSnap = psFxSnapshot($conn, $company, $fxBase, $soldAt);
    } else {
        if (psFxCurrency($conn, $saleCurrency) === null) {
            return ['status' => 'rejected', 'error' => 'Unknown currency ' . $saleCurrency . '.'];
        }
        if (!psFxCompanyCurrencyEnabled($conn, $company, $saleCurrency)) {
            $issues[] = ['kind' => 'FX_CURRENCY', 'till_ref' => $tillInvoice, 'detail' => $saleCurrency . ' is not enabled for the company now; the till sold in it while it was.', 'occurred_at' => $soldAt];
        }
        $tillFx = is_array($ev['fx'] ?? null) ? $ev['fx'] : [];
        $tillFrac = isset($tillFx['num'], $tillFx['den']) && preg_match('/^\d{1,40}$/', (string)$tillFx['num']) && preg_match('/^[1-9]\d{0,39}$/', (string)$tillFx['den'])
            ? [psFxBigNorm((string)$tillFx['num']), psFxBigNorm((string)$tillFx['den'])] : null;
        $server = psFxSnapshot($conn, $company, $saleCurrency, $soldAt);
        if ($tillFrac === null || $tillFrac[0] === '0') {
            if (empty($server['ok'])) {
                return ['status' => 'rejected', 'error' => 'Sale in ' . $saleCurrency . ' has no exchange rate.'];
            }
            $fxSnap = $server;
            $issues[] = ['kind' => 'FX_RATE', 'till_ref' => $tillInvoice, 'detail' => 'The till sent no rate for a ' . $saleCurrency . ' sale; the server rate was used.', 'occurred_at' => $soldAt];
        } elseif (!empty($server['ok']) && psFxBigCmp(psFxBigMul($server['frac'][0], $tillFrac[1]), psFxBigMul($tillFrac[0], $server['frac'][1])) === 0) {
            $fxSnap = $server;
        } else {
            $tillRate = psFxFracToDecimal($tillFrac, 10);
            $fxSnap = ['ok' => true, 'currency' => $saleCurrency, 'base' => $fxBase, 'frac' => $tillFrac, 'rate' => $tillRate,
                'ids' => substr(preg_replace('/[^0-9,]/', '', (string)($tillFx['ids'] ?? '')), 0, 60),
                'path' => substr('till rate: ' . (string)($tillFx['path'] ?? ''), 0, 160)];
            $issues[] = ['kind' => 'FX_RATE', 'till_ref' => $tillInvoice, 'detail' => 'Sold in ' . $saleCurrency . ' at the till\'s rate; the server rate for that time differs.',
                'till_value' => psFxRateLabel($fxBase, $saleCurrency, $tillRate),
                'server_value' => !empty($server['ok']) ? psFxRateLabel($fxBase, $saleCurrency, $server['rate']) : 'no rate', 'occurred_at' => $soldAt];
        }
    }
    $fxDec = psFxDecimals($conn, $saleCurrency);

    // --- lines exactly as the till recorded them (validated for arithmetic, not for price)
    $rawLines = $ev['lines'] ?? null;
    if (!is_array($rawLines) || !$rawLines || count($rawLines) > 500) {
        return ['status' => 'rejected', 'error' => 'The sale has no lines.'];
    }
    $lines = [];
    $sumCents = 0;
    $taxableSum = 0;
    $taxSum = 0;
    $statuses = [];
    $modes = [];
    foreach ($rawLines as $i => $l) {
        if (!is_array($l)) {
            return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' is not valid.'];
        }
        $pid = trim((string)($l['product_id'] ?? ''));
        $qtyRaw = trim((string)($l['quantity'] ?? ''));
        $unit = psDecimalCents((string)($l['unit_price'] ?? ''));
        $rev = psDecimalCents((string)($l['revenue'] ?? ''));
        if ($pid === '' || strlen($pid) > 250 || !preg_match('/^\d+(\.\d{1,3})?$/', $qtyRaw) || $unit === null || $rev === null) {
            return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' has an invalid product, quantity or price.'];
        }
        $lineCents = psLineCents($unit, $qtyRaw);
        if ($lineCents === null) {
            return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' quantity must be greater than zero.'];
        }
        // 2026-10-28 packs: a line sold in a pack carries its snapshot (pack, packs sold, units per pack, pack price).
        // quantity stays in base units (packs × units per pack); the line amount is pack price × packs.
        $packSnap = null;
        if ((int)($l['pack_id'] ?? 0) > 0) {
            $sq = trim((string)($l['sell_qty'] ?? ''));
            $sf = trim((string)($l['sell_factor'] ?? ''));
            $spC = psDecimalCents((string)($l['sell_price'] ?? ''));
            $su = trim((string)($l['sell_unit'] ?? ''));
            $sqM = preg_match('/^\d{1,9}(\.\d{1,3})?$/', $sq) ? psStockMilli($sq) : null;
            $sfM = preg_match('/^\d{1,9}(\.\d{1,3})?$/', $sf) ? psStockMilli($sf) : null;
            if ($su === '' || strlen($su) > 40 || $spC === null || !$sqM || !$sfM || $sqM <= 0 || $sfM <= 0 || $sqM % 1000 !== 0) {
                return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' pack details are not valid (whole packs only).'];
            }
            if ((int)psStockMilli($qtyRaw) !== intdiv($sqM, 1000) * $sfM) {
                return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' quantity is not packs × units per pack.'];
            }
            $packLine = psLineCents($spC, $sq);
            if ($packLine === null) {
                return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' pack price is not valid.'];
            }
            $lineCents = $packLine;
            $baseMilli = (int)psStockMilli($qtyRaw);
            $unit = intdiv(psFxRoundCentsToDecimals($packLine, $fxDec) * 1000 * 2 + $baseMilli, 2 * $baseMilli); // per base unit, display only
            $packSnap = ['pack_id' => (int)$l['pack_id'], 'sell_unit' => $su, 'sell_qty' => psPackQtyText($sqM), 'sell_factor' => psStockQtyPlain(psMilliToStock($sfM)),
                'sell_price' => psMoneyFromCents($spC), 'pre_cents' => $packLine];
        }
        $taxCode = strtoupper(trim((string)($l['tax_code'] ?? '')));
        $complete = $taxCode !== '';
        if ($complete) {
            $exclC = psDecimalCents((string)($l['line_total_excl'] ?? ''));
            $inclC = psDecimalCents((string)($l['line_total_incl'] ?? ''));
            $taxC = psDecimalCents((string)($l['tax_amount'] ?? ''));
            $txblC = psDecimalCents((string)($l['taxable_amount'] ?? ''));
            if ($exclC === null || $inclC === null || $taxC === null || $txblC === null || $inclC !== $rev) {
                return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' tax amounts do not add up.'];
            }
            $taxableSum += $txblC;
            $taxSum += $taxC;
            $modes[] = strtoupper((string)($l['tax_pricing_mode'] ?? ''));
            $statuses[] = PS_TAX_STATUS_COMPLETE;
        } else {
            if ($rev !== psFxRoundCentsToDecimals($lineCents, $fxDec)) {
                return ['status' => 'rejected', 'error' => 'Line ' . ($i + 1) . ' total does not equal price × quantity.'];
            }
            $statuses[] = PS_TAX_STATUS_UNCONFIGURED;
        }
        $sumCents += $rev;
        $name = trim((string)($l['product'] ?? ''));
        $lines[] = [
            'product_id' => $pid,
            'product' => substr($name !== '' ? $name : $pid, 0, 250),
            'quantity' => rtrim(rtrim(number_format((float)$qtyRaw, 3, '.', ''), '0'), '.'),
            'price' => psMoneyFromCents($unit),
            'unit_cents' => $unit,
            'revenue' => psMoneyFromCents($rev),
            'tax_code' => $complete ? $taxCode : null,
            'tax_classification' => $complete ? strtoupper((string)($l['tax_classification'] ?? '')) : null,
            'tax_rate_percent' => $complete ? number_format((float)($l['tax_rate_percent'] ?? 0), 4, '.', '') : null,
            'tax_pricing_mode' => $complete ? strtoupper((string)($l['tax_pricing_mode'] ?? '')) : null,
            'discount_amount' => '0.00',
            'taxable_amount' => $complete ? (string)$l['taxable_amount'] : null,
            'tax_amount' => $complete ? (string)$l['tax_amount'] : null,
            'line_total_excl' => $complete ? (string)$l['line_total_excl'] : null,
            'line_total_incl' => $complete ? (string)$l['line_total_incl'] : null,
            'tax_data_status' => $complete ? PS_TAX_STATUS_COMPLETE : PS_TAX_STATUS_UNCONFIGURED,
        ] + ($packSnap ?? []);
    }
    $totalCents = psDecimalCents((string)($ev['total'] ?? ''));
    if ($totalCents === null || $totalCents !== $sumCents) {
        return ['status' => 'rejected', 'error' => 'Sale total does not equal the sum of its lines.'];
    }
    $headerStatus = in_array(PS_TAX_STATUS_UNCONFIGURED, $statuses, true)
        ? (in_array(PS_TAX_STATUS_COMPLETE, $statuses, true) ? 'MIXED' : PS_TAX_STATUS_UNCONFIGURED)
        : PS_TAX_STATUS_COMPLETE;
    $um = array_values(array_unique($modes));
    $headerMode = count($um) === 1 ? $um[0] : (count($um) > 1 ? 'MIXED' : null);

    // --- payment (same rules as the web: paid − change = total)
    $pay = is_array($ev['payment'] ?? null) ? $ev['payment'] : [];
    $method = strtoupper(trim((string)($pay['method'] ?? '')));

    // Customer (optional). Account sales are accepted as made on the till; broken credit rules are reported.
    require_once __DIR__ . '/customer_master.php';
    require_once __DIR__ . '/customer_account.php';
    $customerId = null;
    $customerName = 'Cash Customer';
    $creditAccountId = null;
    $postedCustomer = (int)($ev['customer_master_id'] ?? 0);
    // 2026-10-17: a customer added on the till (not on live yet) travels as its client uuid.
    if ($postedCustomer <= 0 && psDtValidUuid($ev['customer_uuid'] ?? '')) {
        $cmap = psDtMapGet($conn, strtolower((string)$ev['customer_uuid']));
        if (!$cmap || $cmap['kind'] !== 'customer_save' || (int)$cmap['till_id'] !== (int)$till['id']) {
            return ['status' => 'retry', 'error' => 'Waiting for its new customer to sync.'];
        }
        $postedCustomer = (int)$cmap['server_ref'];
    }
    if ($postedCustomer > 0 && psEnsureCustomerMasterSchema($conn)) {
        $cust = psCustomerById($conn, $postedCustomer);
        if ($cust !== null && !psDtCustomerInCompany($conn, $till, $cust)) {
            $cust = null; // 2026-10-18: never attach another company's customer
        }
        if ($cust === null) {
            $issues[] = ['kind' => 'CUSTOMER_UNKNOWN', 'till_ref' => $tillInvoice, 'detail' => 'Customer #' . $postedCustomer . ' (' . substr((string)($ev['customer_name'] ?? ''), 0, 100) . ') was not found; sold as Cash Customer.', 'occurred_at' => $soldAt];
        } else {
            $customerId = $postedCustomer;
            $customerName = trim((string)($cust['business_name'] ?? '')) !== '' ? trim((string)$cust['business_name']) : trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''));
        }
    }
    if ($method === 'CREDIT') {
        if ($customerId === null) {
            return ['status' => 'rejected', 'error' => 'Account sale without a known customer.'];
        }
        psEnsureCustomerAccountModule($conn);
        $account = psCustomerAccountByCustomerMasterId($conn, $customerId);
        if ($account === null) {
            return ['status' => 'rejected', 'error' => 'Account sale: the customer has no account on the server.'];
        }
        $creditAccountId = (int)$account['id'];
        $acctCur = psCustomerAccountRowCurrency($conn, $account, $company);
        if ($acctCur !== $saleCurrency) {
            return ['status' => 'rejected', 'error' => 'Account sale in ' . $saleCurrency . ' but the customer account is kept in ' . $acctCur . '.'];
        }
        $check = psCustomerAccountEvaluateCreditSale($conn, $customerId, psMoneyFromCents($totalCents), ['lock' => true]);
        if (empty($check['ok'])) {
            $issues[] = ['kind' => 'CREDIT_RULE', 'till_ref' => $tillInvoice, 'detail' => $customerName . ': ' . (string)($check['error'] ?? 'credit check failed') . ' (sale kept, account charged).', 'till_value' => psMoneyFromCents($totalCents), 'occurred_at' => $soldAt];
        }
    }
    // 2026-11-05 split payment / split currency: a till sale paid several ways sends `payments` (each in its own currency)
    // and the change currency; the server checks them with the same rules as the web till (psEvaluateTenders). The sale was
    // made, so a difference in the change (e.g. a rate changed after the till last synced) is reported, not refused.
    $tenderEval = null;
    if (is_array($ev['payments'] ?? null) && $ev['payments']) {
        $tenderEval = psEvaluateTenders($conn, $company, $saleCurrency, $totalCents, psTendersFromRequest($ev, $saleCurrency),
            (string)($ev['change_currency'] ?? ''), $soldAt);
        if (empty($tenderEval['ok'])) {
            return ['status' => 'rejected', 'error' => (string)$tenderEval['error']];
        }
        $tillChangeAmt = psDecimalCents((string)($ev['change_amount'] ?? '0'));
        $srvChange = (int)($tenderEval['change']['amount_cents'] ?? 0);
        if ($tillChangeAmt !== null && $tillChangeAmt !== $srvChange) {
            $issues[] = ['kind' => 'PAYMENT_DIFF', 'till_ref' => $tillInvoice, 'detail' => 'The till gave change of ' . psMoneyFromCents($tillChangeAmt) . ' '
                . (string)($ev['change_currency'] ?? $saleCurrency) . '; the server works it out as ' . psMoneyFromCents($srvChange) . '.',
                'till_value' => psMoneyFromCents($tillChangeAmt), 'server_value' => psMoneyFromCents($srvChange), 'occurred_at' => $soldAt];
        }
        $changeSale = (int)($tenderEval['change']['sale_cents'] ?? 0);
        $payment = ['ok' => true, 'method' => 'SPLIT', 'method_label' => 'Split payment',
            'amount_paid' => psMoneyFromCents((int)$tenderEval['paid_sale_cents']), 'change_amount' => psMoneyFromCents($changeSale),
            'amount_cents' => (int)$tenderEval['paid_sale_cents'], 'change_cents' => $changeSale, 'total_cents' => $totalCents,
            'currency' => $saleCurrency, 'reference' => '', 'is_credit' => false];
    } else {
        $payment = psEvaluatePayment($totalCents, (string)($pay['amount_paid'] ?? ''), $method, (string)($pay['reference'] ?? ''), $saleCurrency);
        if (!$payment['ok']) {
            return ['status' => 'rejected', 'error' => (string)$payment['error']];
        }
        $tillChange = psDecimalCents((string)($pay['change'] ?? '0'));
        if ($tillChange !== null && $tillChange !== (int)$payment['change_cents']) {
            return ['status' => 'rejected', 'error' => 'Change given does not match amount paid minus total.'];
        }
    }

    // 2026-10-17: sale made from a quotation (server id, or the till's uuid for a quote made on the till).
    $saleQuote = null;
    $quotePrices = [];
    $quoteId = (int)($ev['quotation_id'] ?? 0);
    if ($quoteId <= 0 && psDtValidUuid($ev['quotation_uuid'] ?? '')) {
        require_once __DIR__ . '/quotation.php';
        $quoteId = psDtQuoteIdByUuid($conn, $company, (string)$ev['quotation_uuid']);
        if ($quoteId === null) {
            return ['status' => 'retry', 'error' => 'Waiting for its quotation to sync.'];
        }
    }
    if ($quoteId > 0) {
        require_once __DIR__ . '/quotation.php';
        $saleQuote = psQuoteGet($conn, $quoteId, $company, true);
        foreach ($saleQuote['lines'] ?? [] as $ql) {
            $quotePrices[(string)$ql['product_id']] = (int)psDecimalCents((string)$ql['unit_price']);
        }
    }

    // --- compare with the server's current data (report, never change)
    $stockDelta = [];
    foreach ($lines as $idx => $line) {
        $pid = $line['product_id'];
        $found = psActiveGoodsForSale($conn, $pid, $branch, $company);
        if (!$found['ok']) {
            $exists = stockMovementResolveProductOnBranch($conn, $pid, $branch) !== null;
            $issues[] = ['kind' => $exists ? 'PRODUCT_INACTIVE' : 'PRODUCT_MISSING', 'till_ref' => $tillInvoice, 'product_id' => $pid,
                'detail' => $line['product'] . ($exists ? ' is inactive on the server.' : ' was not found on the server at ' . $branch . '.'), 'occurred_at' => $soldAt];
            $lines[$idx]['skip_stock'] = !$exists;
        } else {
            $row = $found['row'];
            $lines[$idx]['cost_price'] = (isset($row['cost_price']) && is_numeric($row['cost_price']))
                ? psMoneyFromCents(psFxFromBaseCents($conn, $fxSnap, (int)psDecimalCents(number_format((float)$row['cost_price'], 2, '.', '')))) : null;
            $serverCents = psDecimalCents(trim((string)$row['price']));
            if ($serverCents !== null) {
                $serverCents = psFxFromBaseCents($conn, $fxSnap, $serverCents); // compare in the sale currency
            }
            if (!empty($line['pack_id'])) {
                // Pack line: compare the pack price with the server's pack (report, never change).
                $sPack = psPackGet($conn, $company, (int)$line['pack_id']);
                $tillPack = (int)psDecimalCents($line['sell_price']);
                if (!$sPack || $sPack['product_id'] !== (string)$pid) {
                    $issues[] = ['kind' => 'PRICE_DIFF', 'till_ref' => $tillInvoice, 'product_id' => $pid, 'detail' => $line['product'] . ' sold as ' . $line['sell_unit'] . ', a pack the server does not have.',
                        'till_value' => $line['sell_price'], 'server_value' => 'no pack', 'occurred_at' => $soldAt];
                } else {
                    $sp = $sPack['price'] !== null ? psFxFromBaseCents($conn, $fxSnap, (int)psDecimalCents((string)$sPack['price'])) : null;
                    if ($sp !== $tillPack || psStockMilli($sPack['factor']) !== psStockMilli($line['sell_factor'])) {
                        $issues[] = ['kind' => 'PRICE_DIFF', 'till_ref' => $tillInvoice, 'product_id' => $pid, 'detail' => $line['product'] . ' (' . $line['sell_unit'] . ' of ' . $line['sell_factor'] . ') sold at the till pack price.',
                            'till_value' => $line['sell_price'], 'server_value' => ($sp !== null ? psMoneyFromCents($sp) : 'not sold') . ' (' . $sPack['factor'] . ' per pack' . (!$sPack['active'] ? ', hidden' : '') . ')', 'occurred_at' => $soldAt];
                    }
                }
            } elseif ($serverCents !== null && $serverCents !== $line['unit_cents'] && ($quotePrices[$pid] ?? null) !== $line['unit_cents']) {
                $issues[] = ['kind' => 'PRICE_DIFF', 'till_ref' => $tillInvoice, 'product_id' => $pid, 'detail' => $line['product'] . ' sold at the till price.',
                    'till_value' => $line['price'], 'server_value' => psMoneyFromCents($serverCents), 'occurred_at' => $soldAt];
            }
            $serverTax = strtoupper(trim((string)($row['tax_code'] ?? '')));
            if ($serverTax !== (string)($line['tax_code'] ?? '')) {
                $issues[] = ['kind' => 'TAX_DIFF', 'till_ref' => $tillInvoice, 'product_id' => $pid, 'detail' => $line['product'] . ' tax code differs.',
                    'till_value' => (string)($line['tax_code'] ?? '(none)'), 'server_value' => $serverTax !== '' ? $serverTax : '(none)', 'occurred_at' => $soldAt];
            } elseif ($serverTax !== '') {
                $rate = psTaxResolveEffectiveRate($conn, $serverTax, $saleDate, $company);
                if ($rate['ok']) {
                    $calc = psTaxComputeLine($rate['rate'], (int)($line['pre_cents'] ?? psLineCents($line['unit_cents'], $line['quantity'])));
                    if ($calc['ok'] && ($calc['tax_amount'] !== psMoneyFromCents((int)psDecimalCents((string)$line['tax_amount'])) || $calc['line_total_incl'] !== $line['revenue'])) {
                        $issues[] = ['kind' => 'TAX_DIFF', 'till_ref' => $tillInvoice, 'product_id' => $pid, 'detail' => $line['product'] . ' tax amount differs from the server rate.',
                            'till_value' => (string)$line['tax_amount'], 'server_value' => (string)$calc['tax_amount'], 'occurred_at' => $soldAt];
                    }
                }
            }
        }
        if (empty($lines[$idx]['skip_stock'])) {
            $stockDelta[$pid] = ($stockDelta[$pid] ?? 0) + (int)psStockMilli($line['quantity']);
        }
    }

    // --- write like psCompleteSale
    $invoiceNumber = psAllocateInvoiceNumber($conn);
    if ($invoiceNumber === null) {
        return ['status' => 'retry', 'error' => 'Invoice number could not be allocated.'];
    }
    $total = psMoneyFromCents($totalCents);
    $headerTaxable = $headerStatus === PS_TAX_STATUS_COMPLETE ? psMoneyFromCents($taxableSum) : null;
    $headerTax = $headerStatus === PS_TAX_STATUS_COMPLETE ? psMoneyFromCents($taxSum) : null;
    $tillId = (int)$till['id'];
    $source = PS_DT_SOURCE;
    $status = '';
    $invStr = (string)$invoiceNumber;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO online_sales_ref
            (invoice_number, totalz, method, cashier, customer, status, date_, branch, till_session_id, sold_at, customer_id, customer_master_id,
             taxable_amount, tax_amount, tax_pricing_mode, tax_data_status, till_invoice_number, desktop_till_id, sale_source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'ssssssssisisssssis', $invStr, $total, $saleCurrency, $email, $customerName, $status, $saleDate, $branch, $sessionId, $soldAt, $customerId,
        $headerTaxable, $headerTax, $headerMode, $headerStatus, $tillInvoice, $tillId, $source);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['status' => 'retry', 'error' => 'Sale header could not be saved.'];
    }
    $headerId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    // 2026-10-31: the till's own order number (T01-007), kept as sent; older tills send none.
    $tillOrderNo = psOrderNumberClean($ev['order_no'] ?? '');
    if ($tillOrderNo !== null && psOrderNumberEnsureSchema($conn) && !psOrderNumberSave($conn, $invStr, $tillOrderNo)) {
        return ['status' => 'retry', 'error' => 'Sale header could not be saved.'];
    }
    // 2026-11-13: Sit in / Take away as chosen on the till (kept as sent, even if the switch changed since).
    $tillDine = psDineOptionClean($ev['dine_option'] ?? '');
    if ($tillDine !== null && psDineOptionEnsureSchema($conn) && !psDineOptionSave($conn, $invStr, $tillDine)) {
        return ['status' => 'retry', 'error' => 'Sale header could not be saved.'];
    }
    if (psFxHasColumn($conn, 'online_sales_ref', 'currency')) {
        $v = psFxSnapshotSqlValues($conn, $fxSnap);
        $bt = psFxCentsToMoney(psFxToBaseCents($conn, $fxSnap, $totalCents));
        if (!mysqli_query($conn, 'UPDATE online_sales_ref SET currency = ' . $v['currency'] . ', base_currency = ' . $v['base_currency'] . ', fx_rate = ' . $v['fx_rate']
            . ', fx_rate_ids = ' . $v['fx_rate_ids'] . ', fx_path = ' . $v['fx_path'] . ", base_total = '" . $bt . "' WHERE id = " . $headerId)) {
            return ['status' => 'retry', 'error' => 'Sale currency could not be saved.'];
        }
    }

    $lineStmt = mysqli_prepare(
        $conn,
        'INSERT INTO online_sales
            (invoice_number, product_id, product, quantity, revenue, unit_price, cost_price, date_, customer, cashier, branch,
             tax_code, tax_classification, tax_rate_percent, tax_pricing_mode, discount_amount,
             taxable_amount, tax_amount, line_total_excl, line_total_incl, tax_data_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    require_once __DIR__ . '/product_packs.php';
    $packCols = psPackEnsureSchema($conn);
    foreach ($lines as $line) {
        if (!empty($line['pack_id']) && !$packCols) {
            mysqli_stmt_close($lineStmt);
            return ['status' => 'retry', 'error' => 'Pack columns are not available on the server yet.'];
        }
        $cost = $line['cost_price'] ?? '0';
        mysqli_stmt_bind_param($lineStmt, 'sssssssssssssssssssss', $invStr, $line['product_id'], $line['product'], $line['quantity'], $line['revenue'],
            $line['price'], $cost, $saleDate, $customerName, $email, $branch, $line['tax_code'], $line['tax_classification'], $line['tax_rate_percent'],
            $line['tax_pricing_mode'], $line['discount_amount'], $line['taxable_amount'], $line['tax_amount'], $line['line_total_excl'],
            $line['line_total_incl'], $line['tax_data_status']);
        if (!mysqli_stmt_execute($lineStmt)) {
            mysqli_stmt_close($lineStmt);
            return ['status' => 'retry', 'error' => 'Sale line could not be saved.'];
        }
        if (!empty($line['pack_id'])) {
            $lid = (int)mysqli_insert_id($conn);
            $pu = mysqli_prepare($conn, 'UPDATE online_sales SET sell_unit = ?, sell_qty = ?, sell_factor = ?, sell_price = ?, pack_id = ? WHERE id = ?');
            $pk = (int)$line['pack_id'];
            $okPack = $pu && mysqli_stmt_bind_param($pu, 'ssssii', $line['sell_unit'], $line['sell_qty'], $line['sell_factor'], $line['sell_price'], $pk, $lid)
                && mysqli_stmt_execute($pu);
            if ($pu) {
                mysqli_stmt_close($pu);
            }
            if (!$okPack) {
                mysqli_stmt_close($lineStmt);
                return ['status' => 'retry', 'error' => 'Sale line pack could not be saved.'];
            }
        }
    }
    mysqli_stmt_close($lineStmt);

    $token = 'D' . str_replace('-', '', $uuid); // unique per sale; reuses the web double-submit guard
    $ref = $payment['reference'] === '' ? null : $payment['reference'];
    $cashierId = $cashier ? (int)$cashier['id'] : null;
    if ($tenderEval !== null) {
        // 2026-11-05: one row per payment (+ the change row), tokens D…-1, D…-2, D…-C
        if (!psInsertSaleTenders($conn, $invStr, $tenderEval, ['cashier_id' => $cashierId, 'cashier_email' => $email,
            'till_session_id' => $sessionId, 'created_at' => $soldAt], $token)) {
            return ['status' => 'retry', 'error' => 'Payment could not be saved.'];
        }
    } else {
        $payStmt = mysqli_prepare(
            $conn,
            'INSERT INTO sale_payments (invoice_number, method, amount_paid, change_amount, currency, reference, cashier_id, cashier_email, till_session_id, created_at, payment_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param($payStmt, 'ssssssisiss', $invStr, $payment['method'], $payment['amount_paid'], $payment['change_amount'], $payment['currency'],
            $ref, $cashierId, $email, $sessionId, $soldAt, $token);
        if (!mysqli_stmt_execute($payStmt)) {
            mysqli_stmt_close($payStmt);
            return ['status' => 'retry', 'error' => 'Payment could not be saved.'];
        }
        mysqli_stmt_close($payStmt);
    }

    if (!mysqli_query($conn, "UPDATE online_sales_ref SET status = 'COMPLETED' WHERE id = $headerId AND status = ''") || mysqli_affected_rows($conn) !== 1) {
        return ['status' => 'retry', 'error' => 'Sale could not be completed.'];
    }

    foreach ($lines as $line) {
        if (!empty($line['skip_stock'])) {
            continue;
        }
        $mq = psSaleMovementQuantity($line['quantity']);
        $rec = recordStockMovement($conn, [
            'product_id' => $line['product_id'],
            'branch' => $branch,
            'movement_type' => 'SALE',
            'quantity' => $mq,
            'reference_type' => 'SALE',
            'reference_id' => $invStr,
            'movement_at' => $soldAt,
            'user_id' => $cashierId,
            'user_email' => $email,
        ]);
        if (empty($rec['ok'])) {
            return ['status' => 'retry', 'error' => 'Stock movement could not be saved: ' . ($rec['error'] ?? '')];
        }
    }
    foreach ($stockDelta as $pid => $milli) {
        $bal = psSaleLedgerBalance($conn, (string)$pid, $branch);
        // Report once, when this sale takes the branch stock from zero-or-more to below zero.
        if (!empty($bal['ok']) && !empty($bal['tracked']) && (int)$bal['milli'] < 0 && (int)$bal['milli'] + $milli >= 0) {
            $issues[] = ['kind' => 'NEGATIVE_STOCK', 'till_ref' => $tillInvoice, 'product_id' => (string)$pid, 'invoice_number' => $invStr,
                'detail' => 'Stock at ' . $branch . ' is now below zero.', 'server_value' => psStockQtyPlain(psMilliToStock((int)$bal['milli'])), 'occurred_at' => $soldAt];
        }
    }

    if ($creditAccountId !== null) {
        $ledger = psCustomerAccountPostCreditSale($conn, $creditAccountId, $total, $invStr, $email !== '' ? $email : 'desktop');
        if (empty($ledger['ok'])) {
            return ['status' => 'retry', 'error' => 'Account ledger could not be posted: ' . ($ledger['error'] ?? '')];
        }
    }
    if ($tenderEval !== null) {
        // 2026-11-05: cash per drawer currency (cash paid in it minus change given in it)
        if (psTenderCashByCurrency($tenderEval)) {
            $cash = psCashMovementPostSaleTenders($conn, $sessionId, $branch, $invStr, $email !== '' ? $email : 'desktop', $tenderEval,
                $company, $saleCurrency, $fxSnap, $soldAt);
            if (empty($cash['ok'])) {
                return ['status' => 'retry', 'error' => 'Cash movement could not be saved.'];
            }
        }
    } elseif ($payment['method'] === 'CASH') {
        $cash = psCashMovementPostCashSale($conn, $sessionId, $branch, $total, $invStr, $email !== '' ? $email : 'desktop', $soldAt, $fxSnap);
        if (empty($cash['ok'])) {
            return ['status' => 'retry', 'error' => 'Cash movement could not be saved.'];
        }
    }

    // ZIMRA: the till PC signs with its own FDMS device and sends the receipt to ZIMRA itself.
    // Keep its fiscal reference with the sale; flag sales from fiscal branches that were NOT fiscalised on the till.
    $fiscal = is_array($ev['fiscal'] ?? null) ? $ev['fiscal'] : null;
    if ($fiscal !== null && !empty($fiscal['receipt_global_no'])) {
        $fd = isset($fiscal['device_id']) ? (int)$fiscal['device_id'] : null;
        $fday = isset($fiscal['fiscal_day_no']) ? (int)$fiscal['fiscal_day_no'] : null;
        $fg = (int)$fiscal['receipt_global_no'];
        $fv = substr((string)($fiscal['verification_code'] ?? ''), 0, 19);
        $fq = substr((string)($fiscal['qr_url'] ?? ''), 0, 255);
        $fs = mysqli_prepare($conn, 'UPDATE online_sales_ref SET till_fiscal_device_id = ?, till_fiscal_day_no = ?, till_fiscal_global_no = ?,
            till_fiscal_verification = ?, till_fiscal_qr_url = ? WHERE id = ?');
        if ($fs) {
            mysqli_stmt_bind_param($fs, 'iiissi', $fd, $fday, $fg, $fv, $fq, $headerId);
            mysqli_stmt_execute($fs);
            mysqli_stmt_close($fs);
        }
    }
    $z = $fiscal !== null ? false : mysqli_prepare($conn, "SELECT id FROM zimra_devices WHERE branch = ? AND (company_name = ? OR company_name = '') AND status = 'REGISTERED' AND enabled = 1 LIMIT 1");
    if ($z) {
        mysqli_stmt_bind_param($z, 'ss', $branch, $company);
        mysqli_stmt_execute($z);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($z))) {
            $issues[] = ['kind' => 'NOT_FISCALISED', 'till_ref' => $tillInvoice, 'detail' => 'Desktop sale was not fiscalised on the till PC. Set up and switch on ZIMRA on that PC (More > ZIMRA fiscal).', 'occurred_at' => $soldAt];
        }
        mysqli_stmt_close($z);
    }

    if ($saleQuote !== null) {
        $who = trim((string)($ev['cashier_email'] ?? '')) ?: 'desktop';
        if ($saleQuote['status'] !== PS_QUOTE_OPEN || !psQuoteMarkConverted($conn, (int)$saleQuote['id'], $invStr, $who)) {
            $issues[] = ['kind' => 'QUOTE', 'till_ref' => $tillInvoice, 'detail' => 'Quotation ' . $saleQuote['quote_number'] . ' was '
                . strtolower(psQuoteStatusLabel($saleQuote)) . ($saleQuote['converted_invoice'] ? ' (invoice ' . $saleQuote['converted_invoice'] . ')' : '')
                . ' on live; the till sold it at the quoted prices anyway.', 'occurred_at' => $soldAt];
        }
    }

    foreach ($issues as &$iss) {
        $iss['invoice_number'] = $invStr;
    }
    unset($iss);
    return ['status' => 'ok', 'server_ref' => $invStr, 'invoice_number' => $invStr];
}

/** psSaleMovementQuantity lives in submit_sale2.php; same rule here: −qty with 3 decimals. */
if (!function_exists('psSaleMovementQuantity')) {
    function psSaleMovementQuantity(string $soldQty): ?string
    {
        $q = psStockDecimal($soldQty);
        if ($q === null || psStockDecimalCmp($q, '0') <= 0) {
            return null;
        }
        return '-' . $q;
    }
}

/**
 * A refund processed on a desktop till (it was requested, approved and paid out on the PC).
 * Writes what psProcessApprovedRefund writes — PROCESSED request + lines, REFUND stock movements, refunded line
 * status, one CASH_REFUND in the till's session for a cash sale, audit — without a web session.
 * Accept + report: quantity no longer refundable or a different amount is recorded and flagged, never rejected.
 */
function psDtPushRefund(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    require_once __DIR__ . '/sale_refund.php';
    if (!psEnsureSaleRefundModule($conn) || !psEnsureSaleRefundRequestLinesSchema($conn)) {
        return ['status' => 'retry', 'error' => 'Refunds are not available on the server.'];
    }
    $saleUuid = strtolower(trim((string)($ev['sale_uuid'] ?? '')));
    if (!psDtValidUuid($saleUuid)) {
        return ['status' => 'rejected', 'error' => 'The refund does not name its sale.'];
    }
    $saleMap = psDtMapGet($conn, $saleUuid);
    if (!$saleMap) {
        return ['status' => 'retry', 'error' => 'Waiting for its sale to sync.'];
    }
    // A till may refund a sale made on another till of the shop network — but only within its own company.
    if ((int)$saleMap['till_id'] !== (int)$till['id']) {
        $owner = mysqli_prepare($conn, 'SELECT company_name FROM desktop_tills WHERE id = ? LIMIT 1');
        $ownerId = (int)$saleMap['till_id'];
        mysqli_stmt_bind_param($owner, 'i', $ownerId);
        mysqli_stmt_execute($owner);
        $ownerRow = mysqli_fetch_assoc(mysqli_stmt_get_result($owner));
        mysqli_stmt_close($owner);
        if (!$ownerRow || strcasecmp(trim((string)$ownerRow['company_name']), trim((string)$till['company_name'])) !== 0) {
            return ['status' => 'rejected', 'error' => 'The sale belongs to another company.'];
        }
    }
    $invoice = (string)$saleMap['server_ref'];
    $tillRefund = substr(trim((string)($ev['till_refund_number'] ?? '')), 0, 32);
    $processedAt = psDtTime($ev['processed_at'] ?? '');
    $requestedAt = psDtTime($ev['requested_at'] ?? '') ?? $processedAt;
    $amountCents = psDecimalCents((string)($ev['amount'] ?? ''));
    $reason = strtoupper(trim((string)($ev['reason'] ?? '')));
    if ($tillRefund === '' || $processedAt === null || $amountCents === null || $amountCents <= 0 || !in_array($reason, psRefundControlledReasons(), true)) {
        return ['status' => 'rejected', 'error' => 'Refund number, time, amount or reason is not valid.'];
    }
    $rawLines = $ev['lines'] ?? null;
    if (!is_array($rawLines) || !$rawLines) {
        return ['status' => 'rejected', 'error' => 'The refund has no lines.'];
    }
    // Server lines of the sale, in the order the till sent them (sale_line_no 1..n).
    $saleLines = [];
    $q = mysqli_prepare($conn, 'SELECT id FROM online_sales WHERE invoice_number = ? ORDER BY id');
    mysqli_stmt_bind_param($q, 's', $invoice);
    mysqli_stmt_execute($q);
    $res = mysqli_stmt_get_result($q);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $saleLines[] = (int)$row['id'];
    }
    mysqli_stmt_close($q);
    if (!$saleLines) {
        return ['status' => 'retry', 'error' => 'The sale lines were not found on the server yet.'];
    }
    $processor = trim((string)($ev['processor'] ?? '')) ?: 'desktop';
    $pairs = [];
    $serverCents = 0;
    foreach ($rawLines as $l) {
        $no = (int)($l['sale_line_no'] ?? 0);
        $qty = psStockDecimal((string)($l['quantity'] ?? ''));
        $lineCents = psDecimalCents((string)($l['amount'] ?? ''));
        if ($no < 1 || !isset($saleLines[$no - 1]) || $qty === null || psStockDecimalCmp($qty, '0') <= 0 || $lineCents === null) {
            return ['status' => 'rejected', 'error' => 'A refund line does not match the sale.'];
        }
        $info = psGetRefundableSaleLineQuantity($conn, $saleLines[$no - 1], true);
        if (empty($info['ok'])) {
            return ['status' => 'retry', 'error' => (string)($info['error'] ?? 'The sale line could not be read.')];
        }
        $line = $info['line'];
        if (psStockDecimalCmp($qty, (string)$info['remaining']) > 0) {
            $issues[] = ['kind' => 'REFUND_CONFLICT', 'till_ref' => $tillRefund,
                'detail' => 'Refund ' . $tillRefund . ': ' . ($line['product'] ?? 'item') . ' qty ' . $qty . ' but only ' . $info['remaining'] . ' was still refundable on the server.',
                'till_value' => $qty, 'server_value' => (string)$info['remaining'], 'occurred_at' => $processedAt];
        }
        $sc = psRefundLineAmountCents($line, $qty);
        $serverCents += $sc ?? $lineCents;
        $pairs[] = ['line' => $line, 'quantity' => $qty, 'cents' => $lineCents, 'remaining' => (string)$info['remaining'], 'original' => (string)$info['original_qty']];
    }
    if ($serverCents !== $amountCents) {
        $issues[] = ['kind' => 'REFUND_DIFF', 'till_ref' => $tillRefund, 'detail' => 'Refund ' . $tillRefund . ' amount differs from the server calculation.',
            'till_value' => psMoneyFromCents($amountCents), 'server_value' => psMoneyFromCents($serverCents), 'occurred_at' => $processedAt];
    }
    $branch = trim((string)($pairs[0]['line']['branch'] ?? '')) ?: (string)$till['branch'];
    $method = strtoupper(trim((string)($ev['method'] ?? '')));
    $sessionId = null;
    if ($method === 'CASH') {
        $sessionId = psDtSessionId($conn, $till, $ev['session_uuid'] ?? '');
        if ($sessionId === null) {
            return ['status' => 'retry', 'error' => 'Waiting for its till session to sync.'];
        }
    }
    $num = psAllocateRefundNumber($conn);
    if (empty($num['ok'])) {
        return ['status' => 'retry', 'error' => 'A refund number could not be allocated.'];
    }
    $refundNumber = (string)$num['refund_number'];
    $amount = psMoneyFromCents($amountCents);
    $desc = substr(trim((string)($ev['reason_description'] ?? '')), 0, 500);
    $requester = substr(trim((string)($ev['requester'] ?? '')) ?: $processor, 0, 250);
    $approver = substr(trim((string)($ev['approver'] ?? '')), 0, 250);
    $approvalNote = substr(trim((string)($ev['approval_note'] ?? '')), 0, 500);
    $mode = PS_REFUND_MODE_LINES;
    $status = PS_REFUND_STATUS_PROCESSED;
    $tillId = (int)$till['id'];
    $fiscalNo = isset($ev['fiscal']['receipt_global_no']) ? (int)$ev['fiscal']['receipt_global_no'] : null;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO sale_refund_requests (refund_number, invoice_number, line_id, mode, branch, requested_amount, reason, reason_description, status,
            requester, requested_at, approver, approval_note, approved_at, processor, processed_at, processed_amount, till_refund_number, desktop_till_id, till_fiscal_global_no)
         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    mysqli_stmt_bind_param($stmt, 'sssssssssssssssssii', $refundNumber, $invoice, $mode, $branch, $amount, $reason, $desc, $status,
        $requester, $requestedAt, $approver, $approvalNote, $processedAt, $processor, $processedAt, $amount, $tillRefund, $tillId, $fiscalNo);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['status' => 'retry', 'error' => 'The refund could not be saved.'];
    }
    $requestId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if (psFxHasColumn($conn, 'sale_refund_requests', 'currency')) {
        $rfSnap = psFxSaleSnapshot($conn, (string)$invoice, (string)$till['company_name']);
        $rfBase = psFxCentsToMoney(psFxToBaseCents($conn, $rfSnap, (int)psFxMoneyToCents((string)$amount)));
        $rfCur = (string)$rfSnap['currency'];
        mysqli_query($conn, "UPDATE sale_refund_requests SET currency = '" . mysqli_real_escape_string($conn, $rfCur) . "', base_amount = '" . $rfBase . "' WHERE id = " . $requestId);
        // 2026-11-05: how the money went back (the till's choice), so reports and accounting follow it like a web refund.
        if (psEnsureSaleRefundRequestsSchema($conn) && psFxHasColumn($conn, 'sale_refund_requests', 'refund_method')) {
            $rfM = mysqli_real_escape_string($conn, $method);
            $rfC = mysqli_real_escape_string($conn, strtoupper(trim((string)($ev['refund_currency'] ?? ''))) ?: $rfCur);
            mysqli_query($conn, "UPDATE sale_refund_requests SET refund_method = '$rfM', refund_currency = '$rfC' WHERE id = " . $requestId);
        }
    }

    $lineStmt = mysqli_prepare(
        $conn,
        'INSERT INTO sale_refund_request_lines (request_id, sale_line_id, product_id, requested_qty, unit_price_snapshot, line_amount_snapshot, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $markFull = mysqli_prepare($conn, "UPDATE online_sales SET status = 'refunded' WHERE id = ? AND status <> 'refunded'");
    $movementIds = [];
    $summary = [];
    foreach ($pairs as $p) {
        $line = $p['line'];
        $lid = (int)$line['id'];
        $pid = trim((string)($line['product_id'] ?? ''));
        $unit = isset($line['unit_price']) && $line['unit_price'] !== null && $line['unit_price'] !== '' ? (string)$line['unit_price'] : null;
        $lineAmount = psMoneyFromCents((int)$p['cents']);
        $qty = (string)$p['quantity'];
        mysqli_stmt_bind_param($lineStmt, 'iisssss', $requestId, $lid, $pid, $qty, $unit, $lineAmount, $processedAt);
        if (!mysqli_stmt_execute($lineStmt)) {
            mysqli_stmt_close($lineStmt);
            mysqli_stmt_close($markFull);
            return ['status' => 'retry', 'error' => 'The refund lines could not be saved.'];
        }
        $mv = recordStockMovement($conn, [
            'product_id' => $pid,
            'branch' => (string)($line['branch'] ?? $branch),
            'movement_type' => 'REFUND',
            'quantity' => $qty,
            'reference_type' => 'REFUND',
            'reference_id' => $refundNumber,
            'movement_at' => $processedAt,
            'user_email' => $processor,
            'notes' => 'Desktop refund ' . $tillRefund . ' line ' . $lid . ' invoice ' . $invoice . ' qty ' . $qty,
        ]);
        if (empty($mv['ok'])) {
            mysqli_stmt_close($lineStmt);
            mysqli_stmt_close($markFull);
            return ['status' => 'retry', 'error' => 'The refund stock movement could not be saved.'];
        }
        $movementIds[] = (int)$mv['id'];
        $summary[] = $lid . 'x' . $qty;
        $after = psGetRefundableSaleLineQuantity($conn, $lid, false);
        if (!empty($after['ok']) && psStockDecimalCmp((string)$after['remaining'], '0') <= 0) {
            mysqli_stmt_bind_param($markFull, 'i', $lid);
            mysqli_stmt_execute($markFull);
        }
    }
    mysqli_stmt_close($lineStmt);
    mysqli_stmt_close($markFull);
    $still = false;
    foreach ($saleLines as $sid) {
        $qq = psGetRefundableSaleLineQuantity($conn, $sid, false);
        if (!empty($qq['ok']) && psStockDecimalCmp((string)$qq['remaining'], '0') > 0) {
            $still = true;
            break;
        }
    }
    if (!$still) {
        $hdr = mysqli_prepare($conn, "UPDATE online_sales_ref SET status = 'refunded' WHERE invoice_number = ?");
        mysqli_stmt_bind_param($hdr, 's', $invoice);
        mysqli_stmt_execute($hdr);
        mysqli_stmt_close($hdr);
    }
    $cashId = null;
    if ($sessionId !== null) {
        // Multi-currency: cash back in the sale's currency at the sale's rate.
        // 2026-11-05: or in the currency the cashier picked on the till (refund_currency + the cash amount it gave).
        $cashFx = psFxSaleSnapshot($conn, (string)$invoice, (string)$till['company_name']);
        $cashAmt = $amount;
        $rfSaleCur = (string)($cashFx['currency'] ?? '');
        $rfPick = strtoupper(trim((string)($ev['refund_currency'] ?? '')));
        if ($rfPick !== '' && $rfPick !== (string)($cashFx['currency'] ?? '') && psDecimalCents((string)($ev['cash_amount'] ?? '')) !== null) {
            $cashAmt = psMoneyFromCents((int)psDecimalCents((string)$ev['cash_amount']));
            $cashFx = psFxSnapshot($conn, (string)$till['company_name'], $rfPick, $processedAt);
            if (empty($cashFx['ok'])) {
                return ['status' => 'rejected', 'error' => 'No exchange rate for the ' . $rfPick . ' cash refund.'];
            }
        }
        $cash = psCashMovementPostCashRefund($conn, $sessionId, $branch, $cashAmt, PS_CASH_REF_REFUND, $refundNumber, $processor,
            'Cash refund ' . $refundNumber . ' (desktop ' . $tillRefund . ') invoice ' . $invoice . ($cashAmt !== $amount ? ' (' . $amount . ' ' . $rfSaleCur . ' given as ' . $cashAmt . ' ' . $rfPick . ')' : ''), $processedAt,
            $cashFx);
        if (empty($cash['ok'])) {
            return ['status' => 'retry', 'error' => 'The cash refund could not be saved.'];
        }
        $cashId = (int)($cash['id'] ?? 0);
    }
    $audit = psSaleRefundAuditInsert($conn, [
        'refund_number' => $refundNumber,
        'invoice_number' => $invoice,
        'mode' => $mode,
        'branch' => $branch,
        'payment_method' => $method !== '' ? $method : 'UNKNOWN',
        'amount' => $amount,
        'reason' => $reason,
        'reason_description' => $desc,
        'line_summary' => implode(';', $summary),
        'stock_movement_ids' => implode(',', $movementIds),
        'cash_movement_id' => $cashId,
        'till_session_id' => $sessionId,
        'created_by' => $processor,
        'source' => PS_DT_SOURCE,
        'event_type' => PS_REFUND_EVENT_PROCESSING,
        'request_id' => $requestId,
        'event_note' => 'Desktop till ' . $till['till_code'] . ' refund ' . $tillRefund,
    ]);
    if (empty($audit['ok'])) {
        return ['status' => 'retry', 'error' => 'The refund audit could not be saved.'];
    }
    return ['status' => 'ok', 'server_ref' => $refundNumber, 'refund_number' => $refundNumber];
}


/** 2026-10-17: Supervisor, Management or Admin (case-insensitive) — who may add / edit customers and take account payments on a till. */
function psDtIsSupervisorUp(array $user): bool
{
    return in_array(strtolower(trim((string)($user['level'] ?? ''))), ['supervisor', 'management', 'admin'], true);
}

/** A customer belongs to the till's company when it was created by one of the company's users. */
function psDtCustomerInCompany(mysqli $conn, array $till, array $cust): bool
{
    // 2026-10-18: customers carry their company; older rows were filled from their creator's company.
    return strcasecmp(trim((string)($cust['company_name'] ?? '')), trim((string)$till['company_name'])) === 0;
}

/**
 * 2026-10-17: a customer added or changed on a desktop till (Customers page). Same rules as the web
 * (psCreateCustomer / psUpdateCustomer: required names, duplicate phone / email checks, audit), done as the cashier.
 * An edit only changes the fields the till sends; the rest (e.g. national ID) are kept.
 */
function psDtPushCustomerSave(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    require_once __DIR__ . '/customer_master.php';
    $user = psDtCashier($conn, $till, (string)($ev['cashier_email'] ?? ''));
    if ($user === null) {
        return ['status' => 'rejected', 'error' => 'The user who saved this customer is not a user of this company.'];
    }
    if (!psDtIsSupervisorUp($user)) {
        // 2026-10-18: Till users may ADD customers when the company allows it (never change them).
        require_once __DIR__ . '/cash_controls.php';
        if ((int)($ev['customer_id'] ?? 0) > 0 || !psTillMayAddCustomers($conn, (string)$till['company_name'])) {
            return ['status' => 'rejected', 'error' => (int)($ev['customer_id'] ?? 0) > 0
                ? 'Only a Supervisor, Manager or Admin can change customers.'
                : 'Adding customers is switched off for till users (Customers page on the web).'];
        }
    }
    if (!psEnsureCustomerMasterSchema($conn)) {
        return ['status' => 'retry', 'error' => 'Customers are not available on the server right now.'];
    }
    $in = is_array($ev['customer'] ?? null) ? $ev['customer'] : [];
    $fields = ['customer_type', 'first_name', 'last_name', 'business_name', 'phone', 'email', 'address', 'notes'];
    $id = (int)($ev['customer_id'] ?? 0);
    $data = [];
    if ($id > 0) {
        $cur = psCustomerById($conn, $id);
        if ($cur === null || !psDtCustomerInCompany($conn, $till, $cur)) {
            return ['status' => 'rejected', 'error' => 'Customer #' . $id . ' was not found on live.'];
        }
        foreach (array_merge($fields, ['national_id']) as $f) {
            $data[$f] = (string)($cur[$f] ?? '');
        }
    }
    foreach ($fields as $f) {
        if (array_key_exists($f, $in)) {
            $data[$f] = substr(trim((string)$in[$f]), 0, $f === 'notes' ? 4000 : 500);
        }
    }
    $email = (string)$user['email'];
    $r = psActorRun($email, fn() => psTxOuterRun(fn() => $id > 0 ? psUpdateCustomer($conn, $id, $data) : psCreateCustomer($conn, $data)));
    if (empty($r['ok'])) {
        return ['status' => 'rejected', 'error' => (string)($r['error'] ?? 'The customer could not be saved.')];
    }
    $newId = $id > 0 ? $id : (int)($r['id'] ?? 0);
    return ['status' => 'ok', 'server_ref' => (string)$newId, 'customer_code' => (string)($r['customer_code'] ?? '')];
}

/**
 * 2026-10-17: an account payment taken on a desktop till → customer_account_payments (PAY-######), one PAYMENT
 * ledger credit, allocated oldest account sale first — psCustomerAccountPaymentCreate, done as the cashier.
 * Cash taken at the till is also in the till's cash as a separate "Cash in" (pushed as its own cash_movement).
 */
function psDtPushAccountPayment(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    require_once __DIR__ . '/customer_account_payment.php';
    $user = psDtCashier($conn, $till, (string)($ev['cashier_email'] ?? ''));
    if ($user === null) {
        return ['status' => 'rejected', 'error' => 'The user who took this payment is not a user of this company.'];
    }
    if (!psDtIsSupervisorUp($user)) {
        return ['status' => 'rejected', 'error' => 'Only a Supervisor, Manager or Admin can take account payments.'];
    }
    $custId = (int)($ev['customer_id'] ?? 0);
    $cust = $custId > 0 ? psCustomerById($conn, $custId) : null;
    if ($cust === null || !psDtCustomerInCompany($conn, $till, $cust)) {
        return ['status' => 'rejected', 'error' => 'Customer #' . $custId . ' was not found on live.'];
    }
    if (!psEnsureCustomerAccountPaymentModule($conn)) {
        return ['status' => 'retry', 'error' => 'Account payments are not available on the server right now.'];
    }
    $account = psCustomerAccountByCustomerMasterId($conn, $custId);
    if ($account === null) {
        return ['status' => 'rejected', 'error' => 'The customer has no account on live.'];
    }
    $cents = psDecimalCents((string)($ev['amount'] ?? ''));
    $at = psDtTime($ev['paid_at'] ?? '');
    if ($cents === null || $cents <= 0 || $at === null) {
        return ['status' => 'rejected', 'error' => 'Amount or time is not valid.'];
    }
    // Multi-currency: the till takes payments in the account's currency.
    $evCur = psFxNormalizeCode($ev['currency'] ?? '');
    $acctCur = psCustomerAccountRowCurrency($conn, $account, (string)$till['company_name']);
    if ($evCur !== '' && $evCur !== $acctCur) {
        return ['status' => 'rejected', 'error' => 'The till took this payment in ' . $evCur . ' but the account is kept in ' . $acctCur . '.'];
    }
    $tillRef = substr(trim((string)($ev['till_payment_number'] ?? '')), 0, 40);
    $notes = trim((string)($ev['notes'] ?? ''));
    $notes = substr(trim($notes . ' (desktop ' . $till['till_code'] . ($tillRef !== '' ? ', ' . $tillRef : '') . ')'), 0, 1000);
    $r = psActorRun((string)$user['email'], fn() => psTxOuterRun(fn() => psCustomerAccountPaymentCreate($conn, [
        'customer_account_id' => (int)$account['id'],
        'amount' => psMoneyFromCents($cents),
        'payment_method' => (string)($ev['method'] ?? ''),
        'payment_date' => substr($at, 0, 10),
        'reference' => substr(trim((string)($ev['reference'] ?? '')), 0, 120),
        'notes' => $notes,
        'auto_allocate' => true,
        '_source' => 'DESKTOP',
    ])));
    if (empty($r['ok'])) {
        return ['status' => 'rejected', 'error' => (string)($r['error'] ?? 'The payment could not be saved.')];
    }
    return ['status' => 'ok', 'server_ref' => (string)($r['payment_number'] ?? '')];
}


/** 2026-10-17: server id of a quotation made on a till (by its uuid), or null when it has not arrived. */
function psDtQuoteIdByUuid(mysqli $conn, string $company, string $uuid): ?int
{
    $uuid = strtolower(trim($uuid));
    $st = mysqli_prepare($conn, 'SELECT id FROM quotations WHERE client_uuid = ? AND company_name = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'ss', $uuid, $company);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    return $row ? (int)$row['id'] : null;
}

/** Open, unexpired quotations of a branch for a till's download. */
function psDtQuotesForTill(mysqli $conn, string $company, string $branch): array
{
    $out = [];
    foreach (psQuoteList($conn, $company, [$branch], '', 'open', 500) as $q) {
        $full = psQuoteGet($conn, (int)$q['id'], $company);
        if ($full === null) {
            continue;
        }
        $out[] = [
            'id' => (int)$full['id'],
            'number' => (string)$full['quote_number'],
            'till_ref' => (string)($full['till_ref'] ?? ''),
            'uuid' => (string)($full['client_uuid'] ?? ''),
            'customer_id' => $full['customer_master_id'] !== null ? (int)$full['customer_master_id'] : null,
            'customer_name' => (string)$full['customer_name'],
            'customer_phone' => (string)$full['customer_phone'],
            'customer_email' => (string)$full['customer_email'],
            'valid_until' => (string)$full['valid_until'],
            'notes' => (string)($full['notes'] ?? ''),
            'currency' => (string)($full['currency'] ?? ''), // 2026-10-20: '' = the company base
            'total' => (string)$full['total'],
            'tax' => $full['tax_amount'] !== null ? (string)$full['tax_amount'] : null,
            'created_by' => (string)$full['created_by'],
            'created_at' => (string)$full['created_at'],
            'lines' => array_map(fn($l) => [
                'product_id' => (string)$l['product_id'],
                'product' => (string)$l['product'],
                'quantity' => rtrim(rtrim((string)$l['quantity'], '0'), '.'),
                'unit_price' => (string)$l['unit_price'],
                'list_price' => (string)$l['list_price'],
                'line_total' => (string)$l['line_total'],
            ], $full['lines']),
        ];
    }
    return $out;
}

/**
 * 2026-10-17: a quotation made or changed on a till. New quotes keep the till's number (till_ref) and get the next
 * Q-number on live; changes go to the same quote (by id, or by the till's uuid). Same rules as the web
 * (psQuoteSave), done as the cashier; a price the till showed as the branch price is accepted.
 */
function psDtPushQuotationSave(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    $user = psDtCashier($conn, $till, (string)($ev['cashier_email'] ?? ''));
    if ($user === null) {
        return ['status' => 'rejected', 'error' => 'The user who made this quotation is not a user of this company.'];
    }
    $company = (string)$till['company_name'];
    // Quotes made on a till carry the till's uuid; quotes made on the web carry live's id.
    $quoteUuid = strtolower(trim((string)($ev['quote_uuid'] ?? '')));
    $id = (int)($ev['quote_id'] ?? 0);
    if (!psDtValidUuid($quoteUuid)) {
        if ($id <= 0) {
            return ['status' => 'rejected', 'error' => 'Quotation id is not valid.'];
        }
        $quoteUuid = '';
    }
    if ($id <= 0) {
        $id = (int)(psDtQuoteIdByUuid($conn, $company, $quoteUuid) ?? 0);
    }
    $custId = (int)($ev['customer_id'] ?? 0);
    if ($custId <= 0 && psDtValidUuid($ev['customer_uuid'] ?? '')) {
        $cmap = psDtMapGet($conn, strtolower((string)$ev['customer_uuid']));
        if (!$cmap || $cmap['kind'] !== 'customer_save') {
            return ['status' => 'retry', 'error' => 'Waiting for its new customer to sync.'];
        }
        $custId = (int)$cmap['server_ref'];
    }
    $lines = [];
    $allowed = [];
    foreach ((array)($ev['lines'] ?? []) as $l) {
        if (!is_array($l)) {
            continue;
        }
        $lines[] = ['product_id' => (string)($l['product_id'] ?? ''), 'quantity' => (string)($l['quantity'] ?? ''), 'unit_price' => (string)($l['unit_price'] ?? '')];
        $lc = psDecimalCents((string)($l['list_price'] ?? ''));
        if ($lc !== null) {
            $allowed[(string)($l['product_id'] ?? '')] = $lc;
        }
    }
    $in = [
        'customer_master_id' => $custId,
        'customer_name' => (string)($ev['customer_name'] ?? ''),
        'customer_phone' => (string)($ev['customer_phone'] ?? ''),
        'customer_email' => (string)($ev['customer_email'] ?? ''),
        'valid_until' => (string)($ev['valid_until'] ?? ''),
        'notes' => (string)($ev['notes'] ?? ''),
        'lines' => $lines,
        'allowed_prices' => $allowed,
        'source' => 'DESKTOP',
        'till_ref' => substr(trim((string)($ev['till_quote_number'] ?? '')), 0, 40),
        'client_uuid' => $quoteUuid,
    ];
    if ($id > 0) {
        $cur = psQuoteGet($conn, $id, $company);
        if ($cur !== null && $cur['status'] !== PS_QUOTE_OPEN) {
            $issues[] = ['kind' => 'QUOTE', 'till_ref' => $in['till_ref'], 'detail' => 'Change to quotation ' . $cur['quote_number'] . ' was not applied: it is ' . strtolower(psQuoteStatusLabel($cur)) . ' on live.'];
            return ['status' => 'ok', 'server_ref' => (string)$id, 'quote_number' => (string)$cur['quote_number']];
        }
        // Multi-currency: tills price quotes in the base; a quote in another currency is changed on the web only.
        $qCur = psFxNormalizeCode($cur['currency'] ?? '');
        if ($cur !== null && $qCur !== '' && $qCur !== psFxBase($conn, $company)) {
            $issues[] = ['kind' => 'QUOTE', 'till_ref' => $in['till_ref'], 'detail' => 'Change to quotation ' . $cur['quote_number'] . ' was not applied: it is in ' . $qCur . '; change it on the web.'];
            return ['status' => 'ok', 'server_ref' => (string)$id, 'quote_number' => (string)$cur['quote_number']];
        }
    }
    $mayPrice = psQuoteLevelMayPrice((string)$user['level']);
    $r = psActorRun((string)$user['email'], fn() => psTxOuterRun(fn() => psQuoteSave($conn, $id > 0 ? $id : null, $in, (string)$till['branch'], $company, $mayPrice)));
    if (empty($r['ok'])) {
        return ['status' => 'rejected', 'error' => (string)($r['error'] ?? 'The quotation could not be saved.')];
    }
    return ['status' => 'ok', 'server_ref' => (string)$r['id'], 'quote_number' => (string)$r['quote_number']];
}

/** 2026-10-17: a quotation cancelled on a till (the person who made it, or Supervisor+). */
function psDtPushQuotationCancel(mysqli $conn, array $till, array $ev, string $uuid, array &$issues): array
{
    $user = psDtCashier($conn, $till, (string)($ev['cashier_email'] ?? ''));
    if ($user === null) {
        return ['status' => 'rejected', 'error' => 'The user who cancelled this quotation is not a user of this company.'];
    }
    $company = (string)$till['company_name'];
    $id = (int)($ev['quote_id'] ?? 0);
    if ($id <= 0 && psDtValidUuid($ev['quote_uuid'] ?? '')) {
        $id = (int)(psDtQuoteIdByUuid($conn, $company, (string)$ev['quote_uuid']) ?? 0);
        if ($id <= 0) {
            return ['status' => 'retry', 'error' => 'Waiting for its quotation to sync.'];
        }
    }
    $q = psQuoteGet($conn, $id, $company);
    if ($q === null) {
        return ['status' => 'rejected', 'error' => 'Quotation was not found on live.'];
    }
    if ($q['status'] !== PS_QUOTE_OPEN) {
        return ['status' => 'ok', 'server_ref' => (string)$id];
    }
    if (!psQuoteLevelMayPrice((string)$user['level']) && strcasecmp((string)$q['created_by'], (string)$user['email']) !== 0) {
        return ['status' => 'rejected', 'error' => 'Only the person who made the quotation or a Supervisor, Manager or Admin can cancel it.'];
    }
    $r = psActorRun((string)$user['email'], fn() => psQuoteCancel($conn, $id, $company, (string)($ev['reason'] ?? '')));
    return empty($r['ok']) ? ['status' => 'rejected', 'error' => (string)$r['error']] : ['status' => 'ok', 'server_ref' => (string)$id];
}


/**
 * 2026-10-17: every table check the event handlers use, run once per request BEFORE the event's transaction.
 * The checks may run CREATE TABLE / ALTER TABLE, which in MySQL commit the open transaction — inside an event
 * that would save half of it. After this they are cached (static flags) and do no DDL.
 */
function psDtWarmSchemas(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/sale_refund.php';
    psEnsureGoodsStatus($conn);
    psEnsureGoodsTaxCodeColumn($conn);
    psEnsureTaxModule($conn);
    tillEnsureSchema($conn);
    psOrderNumberEnsureSchema($conn);
    psEnsureSaleIntegritySchema($conn);
    stockMovementEnsureSchema($conn);
    psEnsureCashMovementModule($conn);
    if (function_exists('psEnsureSaleRefundModule')) {
        psEnsureSaleRefundModule($conn);
    }
    if (is_file(__DIR__ . '/zimra.php')) {
        require_once __DIR__ . '/zimra.php';
        if (function_exists('psEnsureZimraModule')) {
            psEnsureZimraModule($conn);
        }
    }
}
