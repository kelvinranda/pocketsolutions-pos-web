<?php

/**
 * Product master helpers for create/edit.
 * Identity is product_id + branch. goods.category and goods.button_category stay separate.
 */

require_once __DIR__ . '/stock_movement.php';
require_once __DIR__ . '/branch_access.php';

const PS_PRODUCT_STATUS_ACTIVE = 'ACTIVE';
const PS_PRODUCT_STATUS_INACTIVE = 'INACTIVE';

/**
 * Ensure goods.status exists (ACTIVE / INACTIVE). Safe to call repeatedly.
 */
function psEnsureGoodsStatus(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `goods` LIKE 'status'");
    if ($res && mysqli_num_rows($res) > 0) {
        $ready = true;
        return true;
    }
    if (function_exists('stockMovementInTransaction') && stockMovementInTransaction($conn)) {
        error_log('goods status column was not added');
        return false;
    }
    $ok = mysqli_query(
        $conn,
        "ALTER TABLE `goods` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'"
    );
    if ($ok || mysqli_errno($conn) === 1060) {
        mysqli_query($conn, "UPDATE `goods` SET `status` = 'ACTIVE' WHERE `status` = '' OR `status` IS NULL");
        $ready = true;
        return true;
    }
    error_log('goods status column was not added');
    return false;
}

/**
 * Normalize and validate product status. Only ACTIVE / INACTIVE allowed.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psNormalizeProductStatus($raw): array
{
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Status is required.'];
    }
    $value = strtoupper(trim((string)$raw));
    if ($value === PS_PRODUCT_STATUS_ACTIVE || $value === PS_PRODUCT_STATUS_INACTIVE) {
        return ['ok' => true, 'value' => $value];
    }
    return ['ok' => false, 'error' => 'Status must be Active or Inactive.'];
}

function psProductStatusIsActive($status): bool
{
    $check = psNormalizeProductStatus($status);
    return $check['ok'] && $check['value'] === PS_PRODUCT_STATUS_ACTIVE;
}

function psProductStatusLabel(string $status): string
{
    $check = psNormalizeProductStatus($status);
    if (!$check['ok']) {
        return 'Unknown';
    }
    return $check['value'] === PS_PRODUCT_STATUS_ACTIVE ? 'Active' : 'Inactive';
}

/**
 * Update status by goods.id with session branch match. Does not touch stock.
 * Writes ACTIVATE/DEACTIVATE audit when status actually changes.
 *
 * @return array{ok:bool,error?:string,changed?:bool}
 */
function psSetGoodsStatusById(mysqli $conn, int $goodsId, string $branch, string $newStatus): array
{
    if (!psEnsureGoodsStatus($conn)) {
        return ['ok' => false, 'error' => 'Product status could not be saved.'];
    }
    $normalized = psNormalizeProductStatus($newStatus);
    if (!$normalized['ok']) {
        return ['ok' => false, 'error' => $normalized['error']];
    }
    $branch = trim($branch);
    if ($branch === '' || !psProductBranchIsValid($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if ($goodsId < 1) {
        return ['ok' => false, 'error' => 'Product was not found.'];
    }
    $existing = psGoodsRowById($conn, $goodsId, $branch);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Product was not found on the selected branch.'];
    }
    $status = $normalized['value'];
    $oldStatus = psProductAuditNormalizeValue('status', $existing['status'] ?? null);
    if ($oldStatus === null || $oldStatus === '') {
        $oldStatus = PS_PRODUCT_STATUS_ACTIVE;
    }
    if ($oldStatus === $status) {
        return ['ok' => true, 'changed' => false];
    }
    if (!psEnsureProductAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Product status could not be saved.'];
    }
    $changedBy = psSessionProductAuditUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to change product status.'];
    }
    $action = $status === PS_PRODUCT_STATUS_ACTIVE
        ? PS_PRODUCT_AUDIT_ACTION_ACTIVATE
        : PS_PRODUCT_AUDIT_ACTION_DEACTIVATE;
    $tx = psProductAuditBegin($conn);
    if (!$tx['ok']) {
        return ['ok' => false, 'error' => $tx['error'] ?? 'Product status could not be saved.'];
    }
    $owns = !empty($tx['owns']);
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE goods SET status = ? WHERE id = ? AND branch = ?'
    );
    if (!$stmt) {
        psProductAuditRollback($conn, $owns);
        error_log('product status update prepare failed');
        return ['ok' => false, 'error' => 'Product status could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'sis', $status, $goodsId, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        psProductAuditRollback($conn, $owns);
        error_log('product status update failed');
        return ['ok' => false, 'error' => 'Product status could not be saved.'];
    }
    mysqli_stmt_close($stmt);
    $productId = trim((string)($existing['product_id'] ?? ''));
    $audited = psProductAuditRecordChanges($conn, [
        'product_id' => $productId,
        'goods_id' => $goodsId,
        'branch' => $branch,
        'action' => $action,
        'source' => PS_PRODUCT_AUDIT_SOURCE_UI,
        'changed_by' => $changedBy,
    ], [[
        'field_name' => 'status',
        'old_value' => $oldStatus,
        'new_value' => $status,
    ]]);
    if (!$audited) {
        psProductAuditRollback($conn, $owns);
        return ['ok' => false, 'error' => 'Product status could not be saved.'];
    }
    if (!psProductAuditCommit($conn, $owns)) {
        psProductAuditRollback($conn, $owns);
        return ['ok' => false, 'error' => 'Product status could not be saved.'];
    }
    return ['ok' => true, 'changed' => true];
}

/**
 * Branch names from branchies (no hard-coded names).
 *
 * @return array<int,string>
 */
function psProductBranchNames(mysqli $conn): array
{
    return psAllBranchNames($conn);
}

function psProductBranchIsValid(mysqli $conn, string $branch): bool
{
    $branch = trim($branch);
    if ($branch === '') {
        return false;
    }
    return stockMovementBranchExists($conn, $branch);
}

/**
 * Working branch for product create/edit/list/POS.
 * Phase 9.2: uses trusted resolution — unauthorised selected_branch fails closed
 * (empty string) rather than trusting session alone.
 */
function psSessionProductBranch(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    // Prefer trusted resolver when a DB handle is available via callers' patterns;
    // without conn, only return selected/home if they pass the non-DB auth shape.
    // Callers that need hard fail use psRequireSessionProductBranch / resolve helpers.
    if (function_exists('psBranchSessionAuthenticated') && !psBranchSessionAuthenticated()) {
        return '';
    }
    $selected = function_exists('psBranchSelectedRaw')
        ? psBranchSelectedRaw()
        : trim((string)($_SESSION['selected_branch'] ?? ''));
    $home = function_exists('psBranchHomeName')
        ? psBranchHomeName()
        : trim((string)($_SESSION['branch'] ?? ''));
    $isAdmin = function_exists('psBranchSessionIsAdmin')
        ? psBranchSessionIsAdmin()
        : (strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin');

    if ($selected !== '') {
        if ($isAdmin) {
            // Without conn here we cannot verify branchies; return selected and let
            // require/resolve helpers validate registration. Ordinary path below
            // never trusts selected unless it equals home.
            return $selected;
        }
        // Ordinary: selected must equal home (authorised set = home only).
        if ($home !== '' && $selected === $home) {
            return $selected;
        }
        return '';
    }
    return $home;
}

/**
 * Admin may export All Branches / pick any active branchies name for import.
 * Create/edit/list/POS still use the session working branch.
 */
function psSessionAllowsAllProductBranches(): bool
{
    if (function_exists('psBranchSessionIsAdmin')) {
        return psBranchSessionIsAdmin();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Branches the session may target for import/export selection.
 * Phase 9.2: ordinary = home only (when registered); admin = all branchies.
 * selected_branch ∈ branchies alone does not authorise ordinary users.
 *
 * @return array<int,string>
 */
function psAuthorisedProductBranches(mysqli $conn): array
{
    return psBranchAuthorisedRegisteredNames($conn);
}

/**
 * 2026-10-18: "<col> IN (...)" for the signed-in company's authorised branches ('0' when there are none).
 * Use when a page shows "all branches", so other companies' branches are never included.
 */
function psCompanyBranchesInSql(mysqli $conn, string $col): string
{
    $names = array_values(array_filter(array_map('strval', psAuthorisedProductBranches($conn)), fn($b) => trim($b) !== ''));
    if ($names === []) {
        return '0';
    }
    return $col . ' IN (' . implode(',', array_map(fn($b) => "'" . mysqli_real_escape_string($conn, $b) . "'", $names)) . ')';
}

function psProductBranchIsAuthorised(mysqli $conn, string $branch): bool
{
    $branch = trim($branch);
    if ($branch === '') {
        return false;
    }
    // Must be authorised for the user AND registered (writes / selectors).
    return psBranchIsAuthorised($conn, $branch) && psProductBranchIsValid($conn, $branch);
}

/**
 * Session branch for writes. Posted branch is never trusted over session.
 * A posted foreign/unauthorised branch is rejected (not silently applied).
 *
 * @return array{ok:bool,branch?:string,error?:string}
 */
function psResolveProductWriteBranch(mysqli $conn, $postedBranch = null): array
{
    $resolved = psBranchResolveWorking($conn);
    if (empty($resolved['ok'])) {
        return ['ok' => false, 'error' => $resolved['error'] ?? 'That branch is not available.'];
    }
    $sessionBranch = (string)$resolved['branch'];
    if ($sessionBranch === '' || !psProductBranchIsValid($conn, $sessionBranch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if (!psProductBranchIsAuthorised($conn, $sessionBranch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if ($postedBranch !== null) {
        $posted = trim((string)$postedBranch);
        if ($posted !== '' && $posted !== $sessionBranch) {
            return ['ok' => false, 'error' => 'That branch is not available.'];
        }
    }
    return ['ok' => true, 'branch' => $sessionBranch];
}

/**
 * Require a valid authorised session working branch (list/catalogue).
 *
 * @return array{ok:bool,branch?:string,error?:string}
 */
function psRequireSessionProductBranch(mysqli $conn): array
{
    // Phase 9.6: shared authorised+registered working gate.
    $resolved = psBranchRequireAuthorisedWorking($conn);
    if (empty($resolved['ok'])) {
        return ['ok' => false, 'error' => $resolved['error'] ?? 'That branch is not available.'];
    }
    $branch = (string)$resolved['branch'];
    if ($branch === '' || !psProductBranchIsValid($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if (!psProductBranchIsAuthorised($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    return ['ok' => true, 'branch' => $branch];
}

/**
 * Trim a category / button_category label. Fields stay independent —
 * this never copies or swaps one into the other.
 */
function psNormalizeCategoryField($raw): string
{
    return trim((string)$raw);
}

/**
 * Distinct button_category values used by Items List / Create Item selectors.
 * Source: existing goods.button_category values (DB DISTINCT), not a category table.
 * Empty / whitespace-only values are omitted.
 *
 * @return array<int,string>
 */
function psProductButtonCategoryOptions(mysqli $conn): array
{
    // Phase 19: the company's managed Button group list (never other companies' values).
    if (is_file(__DIR__ . '/catalog_setup.php')) {
        require_once __DIR__ . '/catalog_setup.php';
        if (psCatCompany() !== '') {
            return psCatNames($conn, 'button');
        }
    }
    $options = [];
    $seen = [];
    $res = mysqli_query($conn, 'SELECT DISTINCT button_category FROM goods ORDER BY button_category ASC');
    if (!$res) {
        error_log('button category options could not be loaded');
        return $options;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $value = psNormalizeCategoryField($row['button_category'] ?? '');
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $options[] = $value;
    }
    return $options;
}

/**
 * Distinct category values for optional selectors / labels.
 * Source: existing goods.category values (DB DISTINCT), free-text on create/edit.
 *
 * @return array<int,string>
 */
function psProductCategoryOptions(mysqli $conn): array
{
    if (is_file(__DIR__ . '/catalog_setup.php')) {
        require_once __DIR__ . '/catalog_setup.php';
        if (psCatCompany() !== '') {
            return psCatNames($conn, 'category');
        }
    }
    $options = [];
    $seen = [];
    $res = mysqli_query($conn, 'SELECT DISTINCT category FROM goods ORDER BY category ASC');
    if (!$res) {
        error_log('category options could not be loaded');
        return $options;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $value = psNormalizeCategoryField($row['category'] ?? '');
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $options[] = $value;
    }
    return $options;
}

/**
 * Next shared product_id: MAX(product_id)+1 across all branches (same code may exist per branch).
 */
function psNextSharedProductId(mysqli $conn): string
{
    $result = mysqli_query($conn, 'SELECT MAX(CAST(product_id AS UNSIGNED)) AS max_id FROM goods');
    $row = $result ? mysqli_fetch_assoc($result) : null;
    $max = $row && $row['max_id'] !== null && $row['max_id'] !== '' ? (int)$row['max_id'] : 0;
    return (string)($max > 0 ? $max + 1 : 1);
}

/**
 * Selling price / cost price for goods (varchar money fields).
 * Non-negative; zero allowed (existing behaviour). Up to 2 decimal places —
 * matches catalogue display and POS money (psDecimalCents). No silent rounding.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
function psValidateProductMoney($raw, string $label): array
{
    if ($raw === null) {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => $label . ' is required.'];
    }
    $raw = str_replace(',', '', $raw);
    if ($raw !== '' && $raw[0] === '+') {
        return ['ok' => false, 'error' => $label . ' must be a number.'];
    }
    if ($raw !== '' && $raw[0] === '-') {
        return ['ok' => false, 'error' => $label . ' cannot be negative.'];
    }
    if (preg_match('/^\d+\.\d{3,}$/', $raw)) {
        return ['ok' => false, 'error' => $label . ' cannot have more than 2 decimal places.'];
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        if (is_numeric($raw) && (float)$raw < 0) {
            return ['ok' => false, 'error' => $label . ' cannot be negative.'];
        }
        return ['ok' => false, 'error' => $label . ' must be a number.'];
    }
    if (strlen($raw) > 250) {
        return ['ok' => false, 'error' => $label . ' is too long.'];
    }
    return ['ok' => true, 'value' => $raw];
}

/**
 * Profit margin from selling price and cost. Calculated only — never stored.
 */
function psProductProfitMargin($price, $costPrice): float
{
    return (float)$price - (float)$costPrice;
}

function psProductDuplicateKeyError(mysqli $conn): bool
{
    return (int)mysqli_errno($conn) === 1062;
}

function psProductDuplicateMessage(): string
{
    return 'A product with this Product ID already exists on the selected branch.';
}

/**
 * Whether product_id already exists on the given branch.
 */
function psProductIdExistsOnBranch(mysqli $conn, string $productId, string $branch): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM goods WHERE product_id = ? AND branch = ? LIMIT 1');
    if (!$stmt) {
        error_log('product identity check failed');
        return true;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('product identity check failed');
        return true;
    }
    $result = mysqli_stmt_get_result($stmt);
    $found = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (bool)$found;
}

/**
 * Load one goods row by surrogate id, optionally requiring a branch.
 *
 * @return array<string,mixed>|null
 */
function psGoodsRowById(mysqli $conn, int $goodsId, string $branch = ''): ?array
{
    if ($goodsId < 1) {
        return null;
    }
    if ($branch !== '') {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM goods WHERE id = ? AND branch = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'is', $goodsId, $branch);
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM goods WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $goodsId);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Load goods by product_id + branch. Returns null unless exactly one row.
 * Product name is never used for identity.
 *
 * @return array<string,mixed>|null
 */
function psGoodsByProductIdBranch(mysqli $conn, string $productId, string $branch): ?array
{
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || $branch === '') {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM goods WHERE product_id = ? AND branch = ?'
    );
    if (!$stmt) {
        error_log('product identity lookup failed');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('product identity lookup failed');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    if (count($rows) !== 1) {
        return null;
    }
    return $rows[0];
}

/**
 * Sale/catalogue identity: product_id + session branch, ACTIVE, exactly one row.
 * Optional company_name filter when the session has a company.
 *
 * @return array{ok:bool,row?:array<string,mixed>,error?:string}
 */
function psActiveGoodsForSale(mysqli $conn, string $productId, string $branch, string $company = ''): array
{
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || strlen($productId) > 250) {
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    if ($branch === '') {
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    if (!psEnsureGoodsStatus($conn)) {
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    require_once __DIR__ . '/tax.php';
    if (!psEnsureGoodsTaxCodeColumn($conn)) {
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    $company = trim($company);
    if ($company !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, price, cost_price, status, branch, pack_size, tax_code
             FROM goods WHERE product_id = ? AND branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = \'\')'
        );
        if (!$stmt) {
            error_log('sale product identity lookup failed');
            return ['ok' => false, 'error' => 'A product in the cart is not available.'];
        }
        mysqli_stmt_bind_param($stmt, 'sss', $productId, $branch, $company);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, price, cost_price, status, branch, pack_size, tax_code
             FROM goods WHERE product_id = ? AND branch = ?'
        );
        if (!$stmt) {
            error_log('sale product identity lookup failed');
            return ['ok' => false, 'error' => 'A product in the cart is not available.'];
        }
        mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('sale product identity lookup failed');
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    if (count($rows) === 0) {
        return ['ok' => false, 'error' => 'A product in the cart (code ' . $productId . ') is no longer available — it may have been deleted. Remove it from the cart, then continue.'];
    }
    if (count($rows) !== 1) {
        return ['ok' => false, 'error' => 'A product in the cart is not available.'];
    }
    $product = $rows[0];
    $rowStatus = strtoupper(trim((string)($product['status'] ?? '')));
    if ($rowStatus !== PS_PRODUCT_STATUS_ACTIVE) {
        // 2026-10-31: name it, so the cashier knows which line to remove (a screen opened before it was deactivated).
        return ['ok' => false, 'error' => trim((string)($product['product'] ?? 'A product')) . ' is no longer sold (it was deactivated). Remove it from the cart, then continue.'];
    }
    return ['ok' => true, 'row' => $product];
}

/**
 * Display label that keeps duplicate names separately selectable.
 */
function psProductDisplayLabel(string $name, string $productId, string $packSize = '', $price = null): string
{
    $label = trim($name);
    if ($label === '') {
        $label = $productId !== '' ? $productId : 'Product';
    }
    $bits = [];
    if ($productId !== '') {
        $bits[] = $productId;
    }
    $pack = trim($packSize);
    if ($pack !== '') {
        $bits[] = $pack;
    }
    if ($price !== null && $price !== '' && is_numeric($price)) {
        $bits[] = '$' . number_format((float)$price, 2, '.', '');
    }
    if ($bits) {
        $label .= ' (' . implode(' · ', $bits) . ')';
    }
    return $label;
}

/**
 * Clean product-master export column headings (tab-separated sheet).
 * Branch is informational on re-import; Status is exported for reference.
 *
 * @return array<int,string>
 */
function psProductMasterExportHeaders(): array
{
    return [
        'Product ID',
        'Product',
        'Price',
        'Cost Price',
        'Category',
        'Button Category',
        'Reorder Level',
        'Pack Size',
        'Status',
        'Branch',
    ];
}

/**
 * Format goods.reorder_level for export. Blank = no level. Zero stays 0.
 * Never reads goods.re_order_level.
 */
function psProductMasterExportReorderLevel($raw): string
{
    if ($raw === null) {
        return '';
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if ($raw[0] === '+') {
        $raw = substr($raw, 1);
    }
    if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return '';
    }
    if (strpos($raw, '.') !== false) {
        $raw = rtrim(rtrim($raw, '0'), '.');
    }
    if ($raw === '') {
        return '0';
    }
    return $raw;
}

/**
 * One export row for product-master columns. Category and Button Category stay independent.
 *
 * @param array<string,mixed> $row
 * @return array<int,string>
 */
function psProductMasterExportRow(array $row): array
{
    $statusOut = strtoupper(trim((string)($row['status'] ?? PS_PRODUCT_STATUS_ACTIVE)));
    if ($statusOut === '') {
        $statusOut = PS_PRODUCT_STATUS_ACTIVE;
    }
    return [
        (string)($row['product_id'] ?? ''),
        (string)($row['product'] ?? ''),
        number_format(floatval(str_replace(',', '', (string)($row['price'] ?? '0'))), 2),
        number_format(floatval($row['cost_price'] ?? 0), 2),
        (string)($row['category'] ?? ''),
        (string)($row['button_category'] ?? ''),
        psProductMasterExportReorderLevel(array_key_exists('reorder_level', $row) ? $row['reorder_level'] : null),
        (string)($row['pack_size'] ?? ''),
        $statusOut,
        (string)($row['branch'] ?? ''),
    ];
}

/** Append-only product field audit (not stock movements). */
const PS_PRODUCT_AUDIT_SOURCE_UI = 'UI';
const PS_PRODUCT_AUDIT_SOURCE_IMPORT = 'IMPORT';

const PS_PRODUCT_AUDIT_ACTION_CREATE = 'CREATE';
const PS_PRODUCT_AUDIT_ACTION_UPDATE = 'UPDATE';
const PS_PRODUCT_AUDIT_ACTION_ACTIVATE = 'ACTIVATE';
const PS_PRODUCT_AUDIT_ACTION_DEACTIVATE = 'DEACTIVATE';
const PS_PRODUCT_AUDIT_ACTION_IMPORT = 'IMPORT';

/**
 * Ensure product_audit exists. Safe to call repeatedly. Skips DDL inside a txn.
 */
function psEnsureProductAuditSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (function_exists('stockMovementInTransaction') && stockMovementInTransaction($conn)) {
        $check = mysqli_query($conn, 'SELECT 1 FROM product_audit LIMIT 0');
        if ($check) {
            $ready = true;
            return true;
        }
        error_log('product audit schema was not created');
        return false;
    }
    $sql = "CREATE TABLE IF NOT EXISTS product_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id VARCHAR(250) NOT NULL,
        goods_id INT NOT NULL,
        branch VARCHAR(250) NOT NULL,
        action VARCHAR(32) NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by VARCHAR(250) NOT NULL,
        changed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL,
        KEY idx_product_audit_goods_id (goods_id),
        KEY idx_product_audit_product_branch (product_id, branch),
        KEY idx_product_audit_branch (branch),
        KEY idx_product_audit_changed_at (changed_at),
        KEY idx_product_audit_changed_by (changed_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('product audit schema was not created');
        return false;
    }
    // 2026-10-24: optional reason (price edits from the Items page require one).
    $col = mysqli_query($conn, "SHOW COLUMNS FROM product_audit LIKE 'reason'");
    if ($col && mysqli_num_rows($col) === 0 && !mysqli_query($conn, 'ALTER TABLE product_audit ADD COLUMN reason VARCHAR(250) NULL')) {
        error_log('product audit reason column was not added');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * 2026-10-24 — Items page / POS catalogue: one index serves "this branch, this status, ordered by name".
 * DDL: call outside a transaction.
 */
function psEnsureGoodsListIndex(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $res = mysqli_query($conn, "SHOW INDEX FROM goods WHERE Key_name = 'idx_goods_branch_status_product'");
    if ($res && mysqli_num_rows($res) > 0) {
        return $ready = true;
    }
    if (function_exists('stockMovementInTransaction') && stockMovementInTransaction($conn)) {
        return false;
    }
    if (!psEnsureGoodsStatus($conn)) {
        return false;
    }
    $ok = mysqli_query($conn, 'ALTER TABLE goods ADD INDEX idx_goods_branch_status_product (branch, status, product)')
        || mysqli_errno($conn) === 1061
        || mysqli_query($conn, 'ALTER TABLE goods ADD INDEX idx_goods_branch_status_product (branch(100), status, product(100))');
    if (!$ok) {
        error_log('goods list index was not added');
    }
    return $ready = (bool)$ok;
}

/**
 * Most stable session identity used across POS pages: logger (signed-in user).
 */
function psSessionProductAuditUser(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return trim((string)($_SESSION['logger'] ?? ''));
}

/**
 * Audited master fields. Never includes quantity.
 *
 * @return array<int,string>
 */
function psProductAuditFieldNames(): array
{
    return [
        'product',
        'price',
        'cost_price',
        'category',
        'button_category',
        'reorder_level',
        'pack_size',
        'status',
        'barcode',
    ];
}

/**
 * Normalize a field value for equality / storage in audit rows.
 */
function psProductAuditNormalizeValue(string $field, $raw): ?string
{
    if ($field === 'reorder_level') {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        if (function_exists('psStockDecimal')) {
            $dec = psStockDecimal($raw);
            if ($dec === null) {
                return $raw;
            }
            return function_exists('psStockQtyPlain') ? psStockQtyPlain($dec) : $dec;
        }
        return $raw;
    }
    if ($field === 'price' || $field === 'cost_price') {
        if ($raw === null) {
            return null;
        }
        $raw = str_replace(',', '', trim((string)$raw));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return $raw;
        }
        return number_format((float)$raw, 2, '.', '');
    }
    if ($field === 'status') {
        if ($raw === null) {
            return null;
        }
        $value = strtoupper(trim((string)$raw));
        return $value === '' ? null : $value;
    }
    if ($raw === null) {
        return null;
    }
    return trim((string)$raw);
}

/**
 * Snapshot of audited fields from a goods row.
 *
 * @param array<string,mixed> $row
 * @return array<string,?string>
 */
function psProductAuditSnapshotFromGoods(array $row): array
{
    $snap = [];
    foreach (psProductAuditFieldNames() as $field) {
        $snap[$field] = psProductAuditNormalizeValue(
            $field,
            array_key_exists($field, $row) ? $row[$field] : null
        );
    }
    return $snap;
}

/**
 * Diff two snapshots. Only fields with an actual change.
 *
 * @param array<string,?string> $before
 * @param array<string,?string> $after
 * @return array<int,array{field_name:string,old_value:?string,new_value:?string}>
 */
function psProductAuditDiff(array $before, array $after): array
{
    $changes = [];
    foreach (psProductAuditFieldNames() as $field) {
        $old = array_key_exists($field, $before) ? $before[$field] : null;
        $new = array_key_exists($field, $after) ? $after[$field] : null;
        $oldN = psProductAuditNormalizeValue($field, $old);
        $newN = psProductAuditNormalizeValue($field, $new);
        if ($oldN === $newN) {
            continue;
        }
        $changes[] = [
            'field_name' => $field,
            'old_value' => $oldN,
            'new_value' => $newN,
        ];
    }
    return $changes;
}

/**
 * Insert one append-only audit row. No UPDATE/DELETE helpers.
 *
 * @return bool
 */
function psProductAuditInsertRow(
    mysqli $conn,
    string $productId,
    int $goodsId,
    string $branch,
    string $action,
    string $fieldName,
    $oldValue,
    $newValue,
    string $changedBy,
    string $source,
    string $changedAt = '',
    ?string $reason = null
): bool {
    if (!psEnsureProductAuditSchema($conn)) {
        return false;
    }
    $productId = trim($productId);
    $branch = trim($branch);
    $action = strtoupper(trim($action));
    $fieldName = trim($fieldName);
    $changedBy = trim($changedBy);
    $source = trim($source);
    if ($productId === '' || $branch === '' || $action === '' || $fieldName === '' || $goodsId < 1) {
        return false;
    }
    if ($changedBy === '') {
        $changedBy = psSessionProductAuditUser();
    }
    if ($changedBy === '') {
        return false;
    }
    if ($source === '') {
        $source = PS_PRODUCT_AUDIT_SOURCE_UI;
    }
    if ($changedAt === '') {
        $changedAt = date('Y-m-d H:i:s');
    }
    $oldStore = $oldValue === null ? null : (string)$oldValue;
    $newStore = $newValue === null ? null : (string)$newValue;
    $reason = ($reason === null || trim($reason) === '') ? null : substr(trim($reason), 0, 250);
    static $hasReason = null;
    if ($hasReason === null) {
        $rc = mysqli_query($conn, "SHOW COLUMNS FROM product_audit LIKE 'reason'");
        $hasReason = $rc && mysqli_num_rows($rc) > 0;
    }
    $stmt = mysqli_prepare(
        $conn,
        $hasReason
            ? 'INSERT INTO product_audit
         (product_id, goods_id, branch, action, field_name, old_value, new_value, changed_by, changed_at, source, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO product_audit
         (product_id, goods_id, branch, action, field_name, old_value, new_value, changed_by, changed_at, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('product audit insert prepare failed');
        return false;
    }
    $bind = [$productId, $goodsId, $branch, $action, $fieldName, $oldStore, $newStore, $changedBy, $changedAt, $source];
    if ($hasReason) {
        $bind[] = $reason;
    }
    mysqli_stmt_bind_param($stmt, 'sissssssss' . ($hasReason ? 's' : ''), ...$bind);
    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) {
        error_log('product audit insert failed');
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Write field-level audit rows. Empty $changes is a no-op success.
 *
 * @param array<string,mixed> $meta product_id, goods_id, branch, action, source?, changed_by?, changed_at?
 * @param array<int,array{field_name:string,old_value:?string,new_value:?string}> $changes
 */
function psProductAuditRecordChanges(mysqli $conn, array $meta, array $changes): bool
{
    if ($changes === []) {
        return true;
    }
    $productId = trim((string)($meta['product_id'] ?? ''));
    $goodsId = (int)($meta['goods_id'] ?? 0);
    $branch = trim((string)($meta['branch'] ?? ''));
    $action = strtoupper(trim((string)($meta['action'] ?? '')));
    $source = trim((string)($meta['source'] ?? PS_PRODUCT_AUDIT_SOURCE_UI));
    $changedBy = trim((string)($meta['changed_by'] ?? psSessionProductAuditUser()));
    $changedAt = trim((string)($meta['changed_at'] ?? ''));
    foreach ($changes as $change) {
        $field = trim((string)($change['field_name'] ?? ''));
        if ($field === '') {
            return false;
        }
        if (!psProductAuditInsertRow(
            $conn,
            $productId,
            $goodsId,
            $branch,
            $action,
            $field,
            array_key_exists('old_value', $change) ? $change['old_value'] : null,
            array_key_exists('new_value', $change) ? $change['new_value'] : null,
            $changedBy,
            $source,
            $changedAt,
            isset($meta['reason']) ? (string)$meta['reason'] : null
        )) {
            return false;
        }
    }
    return true;
}

/**
 * CREATE audit: one row per initial audited field (old empty).
 *
 * @param array<string,?string> $snapshot
 */
function psProductAuditRecordCreate(
    mysqli $conn,
    string $productId,
    int $goodsId,
    string $branch,
    array $snapshot,
    string $source = PS_PRODUCT_AUDIT_SOURCE_UI,
    string $action = PS_PRODUCT_AUDIT_ACTION_CREATE,
    string $changedBy = ''
): bool {
    $changes = [];
    foreach (psProductAuditFieldNames() as $field) {
        if (!array_key_exists($field, $snapshot)) {
            continue;
        }
        $new = psProductAuditNormalizeValue($field, $snapshot[$field]);
        $changes[] = [
            'field_name' => $field,
            'old_value' => null,
            'new_value' => $new,
        ];
    }
    return psProductAuditRecordChanges($conn, [
        'product_id' => $productId,
        'goods_id' => $goodsId,
        'branch' => $branch,
        'action' => $action,
        'source' => $source,
        'changed_by' => $changedBy !== '' ? $changedBy : psSessionProductAuditUser(),
    ], $changes);
}

/**
 * Begin a product+audit transaction when not already inside one.
 *
 * @return array{ok:bool,owns:bool,error?:string}
 */
function psProductAuditBegin(mysqli $conn): array
{
    if (function_exists('stockMovementInTransaction') && stockMovementInTransaction($conn)) {
        return ['ok' => true, 'owns' => false];
    }
    if (!mysqli_begin_transaction($conn)) {
        error_log('product audit transaction did not start');
        return ['ok' => false, 'owns' => false, 'error' => 'The change could not be saved.'];
    }
    return ['ok' => true, 'owns' => true];
}

function psProductAuditCommit(mysqli $conn, bool $owns): bool
{
    if (!$owns) {
        return true;
    }
    if (!mysqli_commit($conn)) {
        error_log('product audit transaction commit failed');
        return false;
    }
    return true;
}

function psProductAuditRollback(mysqli $conn, bool $owns): void
{
    if (!$owns) {
        return;
    }
    mysqli_rollback($conn);
}

/**
 * Branches the current session may view in product audit history.
 *
 * @return array<int,string>
 */
function psProductAuditViewBranches(mysqli $conn): array
{
    return psAuthorisedProductBranches($conn);
}

/**
 * Load audit history with branch isolation. Manipulated filters cannot expose
 * rows outside authorised branches.
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string}
 */
function psProductAuditFetchHistory(mysqli $conn, array $filters = []): array
{
    if (!psEnsureProductAuditSchema($conn)) {
        return ['ok' => false, 'error' => 'Product audit history is not available.'];
    }
    $allowed = psProductAuditViewBranches($conn);
    if ($allowed === []) {
        return ['ok' => true, 'rows' => []];
    }

    $branchFilter = trim((string)($filters['branch'] ?? ''));
    $productId = trim((string)($filters['product_id'] ?? ''));
    $goodsId = (int)($filters['goods_id'] ?? 0);
    $limit = (int)($filters['limit'] ?? 200);
    if ($limit < 1) {
        $limit = 200;
    }
    if ($limit > 500) {
        $limit = 500;
    }

    $branches = $allowed;
    if ($branchFilter !== '') {
        if (!in_array($branchFilter, $allowed, true)) {
            return ['ok' => true, 'rows' => []];
        }
        $branches = [$branchFilter];
    }

    // If goods_id requested, resolve and enforce its branch is authorised.
    if ($goodsId > 0) {
        $goods = psGoodsRowById($conn, $goodsId);
        if (!$goods) {
            // Still allow history for deleted goods only when product_id+branch constrain.
            // Without a goods row, restrict by authorised branches + goods_id match only.
        } else {
            $goodsBranch = trim((string)($goods['branch'] ?? ''));
            if ($goodsBranch === '' || !in_array($goodsBranch, $allowed, true)) {
                return ['ok' => true, 'rows' => []];
            }
            $branches = [$goodsBranch];
            if ($productId === '') {
                $productId = trim((string)($goods['product_id'] ?? ''));
            }
        }
    }

    $placeholders = implode(',', array_fill(0, count($branches), '?'));
    $types = str_repeat('s', count($branches));
    $values = $branches;
    $sql = 'SELECT id, product_id, goods_id, branch, action, field_name, old_value, new_value,
                   changed_by, changed_at, source, reason
            FROM product_audit
            WHERE branch IN (' . $placeholders . ')';
    if ($productId !== '') {
        $sql .= ' AND product_id = ?';
        $types .= 's';
        $values[] = $productId;
    }
    if ($goodsId > 0) {
        $sql .= ' AND goods_id = ?';
        $types .= 'i';
        $values[] = $goodsId;
    }
    $sql .= ' ORDER BY changed_at DESC, id DESC LIMIT ' . $limit;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('product audit history prepare failed');
        return ['ok' => false, 'error' => 'Product audit history could not be loaded.'];
    }
    $bind = [$types];
    foreach ($values as $i => $v) {
        $bind[] = &$values[$i];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
        mysqli_stmt_close($stmt);
        error_log('product audit history bind failed');
        return ['ok' => false, 'error' => 'Product audit history could not be loaded.'];
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('product audit history execute failed');
        return ['ok' => false, 'error' => 'Product audit history could not be loaded.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}



// ------------------------------------------------------------------------------------------------
// 2026-10-31 — Delete a product (Admin). A product that was never used at this branch (no sales,
// receipts, stock movements, transfers, orders, returns, quotations or refunds, and no stock) is
// removed. A product with any history is DEACTIVATED instead, so reports and receipts stay correct.
// Same rule in the mobile API (admin_products.php action=delete).
// ------------------------------------------------------------------------------------------------
const PS_PRODUCT_AUDIT_ACTION_DELETE = 'DELETE';

/**
 * Where a product is still referenced at a branch.
 *
 * @return list<string> human labels, e.g. ["12 sales lines", "stock on hand"]
 */
function psProductUsage(mysqli $conn, string $productId, string $branch, ?array $row = null): array
{
    $productId = trim($productId);
    $used = [];
    if ($productId === '') {
        return $used;
    }
    $checks = [
        ['online_sales', 'branch', 'sales line'],
        ['goods_receipts', 'branch', 'goods received line'],
        ['stock_movements', 'branch', 'stock movement'],
        ['movements_receipts', 'branch|sending_branch', 'transfer line'],
        ['purchase_order_lines', 'branch', 'purchase order line'],
        ['purchase_return_lines', 'branch', 'purchase return line'],
        ['quotation_lines', '', 'quotation line'],
        ['sale_refund_request_lines', '', 'refund line'],
    ];
    foreach ($checks as [$table, $branchCols, $label]) {
        $t = mysqli_real_escape_string($conn, $table);
        $exists = @mysqli_query($conn, "SHOW TABLES LIKE '$t'");
        if (!$exists || mysqli_num_rows($exists) === 0) {
            continue;
        }
        $where = 'product_id = ?';
        $types = 's';
        $params = [$productId];
        if ($branchCols !== '') {
            $or = [];
            foreach (explode('|', $branchCols) as $col) {
                $or[] = "`$col` = ?";
                $types .= 's';
                $params[] = $branch;
            }
            $where .= ' AND (' . implode(' OR ', $or) . ')';
        }
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM `$table` WHERE $where");
        if (!$stmt) {
            // Cannot check → treat as used (never delete what we could not verify).
            $used[] = $label . 's (could not check)';
            continue;
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $n = 0;
        mysqli_stmt_bind_result($stmt, $n);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        if ((int)$n > 0) {
            $used[] = (int)$n . ' ' . $label . ((int)$n === 1 ? '' : 's');
        }
    }
    if ($row !== null && abs((float)($row['quantity'] ?? 0)) > 0.0005) {
        $used[] = 'opening stock';
    }
    return $used;
}

/**
 * @return array{ok:bool,error?:string,mode?:string,reason?:string}
 *         mode = 'deleted' | 'deactivated' | 'already_inactive'
 */
function psDeleteGoodsById(mysqli $conn, int $goodsId, string $branch): array
{
    $branch = trim($branch);
    if ($branch === '' || !psProductBranchIsValid($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    $row = psGoodsRowById($conn, $goodsId, $branch);
    if (!$row) {
        return ['ok' => false, 'error' => 'Product was not found on the selected branch.'];
    }
    $company = session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['the_company'] ?? '')) : '';
    $rowCompany = trim((string)($row['company_name'] ?? ''));
    if ($company !== '' && $rowCompany !== '' && $rowCompany !== $company) {
        return ['ok' => false, 'error' => 'Product was not found on the selected branch.'];
    }
    $productId = trim((string)($row['product_id'] ?? ''));
    $name = trim((string)($row['product'] ?? ''));
    $used = psProductUsage($conn, $productId, $branch, $row);
    require_once __DIR__ . '/security.php';
    if ($used) {
        $reason = 'It has history at ' . $branch . ' (' . implode(', ', $used) . '), so it was deactivated instead of deleted.';
        if (!psProductStatusIsActive((string)($row['status'] ?? PS_PRODUCT_STATUS_ACTIVE))) {
            return ['ok' => true, 'mode' => 'already_inactive', 'reason' => 'It has history at ' . $branch . ' (' . implode(', ', $used) . '), so it cannot be deleted. It is already inactive.'];
        }
        $st = psSetGoodsStatusById($conn, $goodsId, $branch, PS_PRODUCT_STATUS_INACTIVE);
        if (empty($st['ok'])) {
            return ['ok' => false, 'error' => $st['error'] ?? 'Product could not be deactivated.'];
        }
        psSecurityAudit($conn, 'PRODUCT_DEACTIVATE', ['target_type' => 'product', 'target_id' => $productId,
            'summary' => 'Delete requested for ' . $name . ' at ' . $branch . ': deactivated (has ' . implode(', ', $used) . ')']);
        return ['ok' => true, 'mode' => 'deactivated', 'reason' => $reason];
    }
    psEnsureProductAuditSchema($conn);
    $changedBy = psSessionProductAuditUser();
    $stmt = mysqli_prepare($conn, 'DELETE FROM goods WHERE id = ? AND branch = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Product could not be deleted.'];
    }
    mysqli_stmt_bind_param($stmt, 'is', $goodsId, $branch);
    $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Product could not be deleted.'];
    }
    psProductAuditRecordChanges($conn, [
        'product_id' => $productId, 'goods_id' => $goodsId, 'branch' => $branch,
        'action' => PS_PRODUCT_AUDIT_ACTION_DELETE, 'source' => PS_PRODUCT_AUDIT_SOURCE_UI, 'changed_by' => $changedBy,
    ], [['field_name' => 'product', 'old_value' => $name, 'new_value' => null]]);
    psSecurityAudit($conn, 'PRODUCT_DELETE', ['target_type' => 'product', 'target_id' => $productId,
        'summary' => 'Deleted ' . $name . ' (' . $productId . ') at ' . $branch . ' — it had never been used',
        'before' => ['product' => $name, 'price' => $row['price'] ?? null, 'cost_price' => $row['cost_price'] ?? null, 'category' => $row['category'] ?? null]]);
    return ['ok' => true, 'mode' => 'deleted'];
}
