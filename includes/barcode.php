<?php

/**
 * Phase 13 — Barcode helpers.
 *
 * Storage: goods.barcode (nullable). Identity remains product_id + branch.
 * Empty barcodes are NULL. Leading zeros and meaningful digits are preserved
 * (outer whitespace trimmed only). Uniqueness: UNIQUE(branch, barcode) when
 * data permits (multiple NULLs allowed).
 *
 * Does not invent barcodes for existing products. Does not post sales or
 * mutate stock — POS resolve returns catalogue identity for the existing cart
 * / submit_sale2 path only.
 */

require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/sale_payment.php';

const PS_BARCODE_MAX_LEN = 64;

/**
 * Ensure goods.barcode exists and UNIQUE(branch, barcode) when safe.
 */
function psEnsureGoodsBarcodeSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureColumn($conn, 'goods', 'barcode', 'VARCHAR(64) NULL DEFAULT NULL')) {
        error_log('goods barcode column was not added');
        return false;
    }
    // Normalise accidental empty strings to NULL so UNIQUE permits multiples.
    mysqli_query($conn, "UPDATE `goods` SET `barcode` = NULL WHERE `barcode` = ''");

    $idx = mysqli_query($conn, "SHOW INDEX FROM `goods` WHERE Key_name = 'uniq_goods_branch_barcode'");
    if ($idx && mysqli_num_rows($idx) > 0) {
        $ready = true;
        return true;
    }
    if (function_exists('stockMovementInTransaction') && stockMovementInTransaction($conn)) {
        // Column is usable without the unique index inside a transaction.
        $ready = true;
        return true;
    }
    $dup = mysqli_query(
        $conn,
        "SELECT branch, barcode, COUNT(*) AS c
         FROM goods
         WHERE barcode IS NOT NULL AND barcode <> ''
         GROUP BY branch, barcode
         HAVING c > 1
         LIMIT 1"
    );
    if ($dup && mysqli_num_rows($dup) > 0) {
        error_log('goods barcode unique index deferred: duplicate branch+barcode rows exist');
        $ready = true;
        return true;
    }
    $ok = mysqli_query(
        $conn,
        'ALTER TABLE `goods` ADD UNIQUE KEY `uniq_goods_branch_barcode` (`branch`, `barcode`)'
    );
    if (!$ok && mysqli_errno($conn) !== 1061) {
        // 1061 = duplicate key name. Other errors: keep column, soft uniqueness via app.
        error_log('goods barcode unique index was not added: ' . mysqli_errno($conn));
    }
    $ready = true;
    return true;
}

/**
 * Normalise barcode for storage/lookup.
 * Trims outer whitespace only — does not strip leading zeros or change digits.
 *
 * @return array{ok:bool,value?:?string,error?:string}
 */
function psNormalizeBarcode($raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'value' => null];
    }
    if (is_int($raw) || is_float($raw)) {
        // Spreadsheet numeric cells can drop leading zeros — reject for safety.
        return [
            'ok' => false,
            'error' => 'Barcode must be entered as text so leading zeros are preserved.',
        ];
    }
    $value = trim((string)$raw);
    if ($value === '') {
        return ['ok' => true, 'value' => null];
    }
    if (strlen($value) > PS_BARCODE_MAX_LEN) {
        return ['ok' => false, 'error' => 'Barcode must be at most ' . PS_BARCODE_MAX_LEN . ' characters.'];
    }
    // Printable ASCII excluding space-only control; allow common Code 128 set.
    if (!preg_match('/^[\x21-\x7E]+$/', $value)) {
        return ['ok' => false, 'error' => 'Barcode contains invalid characters.'];
    }
    return ['ok' => true, 'value' => $value];
}

/**
 * Whether another goods row on the same branch already has this barcode.
 */
function psBarcodeExistsOnBranch(
    mysqli $conn,
    string $branch,
    string $barcode,
    int $excludeGoodsId = 0
): bool {
    $branch = trim($branch);
    if ($branch === '' || $barcode === '') {
        return false;
    }
    if (!psEnsureGoodsBarcodeSchema($conn)) {
        return true; // fail closed
    }
    if ($excludeGoodsId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM goods WHERE branch = ? AND barcode = ? AND id <> ? LIMIT 1'
        );
        if (!$stmt) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'ssi', $branch, $barcode, $excludeGoodsId);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id FROM goods WHERE branch = ? AND barcode = ? LIMIT 1'
        );
        if (!$stmt) {
            return true;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $branch, $barcode);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return true;
    }
    $res = mysqli_stmt_get_result($stmt);
    $found = $res && mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return (bool)$found;
}

/**
 * Validate + uniqueness for assign/update. Blank clears barcode.
 *
 * @return array{ok:bool,value?:?string,error?:string}
 */
function psValidateBarcodeForBranch(
    mysqli $conn,
    string $branch,
    $raw,
    int $excludeGoodsId = 0
): array {
    $norm = psNormalizeBarcode($raw);
    if (!$norm['ok']) {
        return $norm;
    }
    $value = $norm['value'];
    if ($value === null) {
        return ['ok' => true, 'value' => null];
    }
    $branch = trim($branch);
    if ($branch === '' || !psProductBranchIsValid($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if (!psEnsureGoodsBarcodeSchema($conn)) {
        return ['ok' => false, 'error' => 'Barcode could not be saved.'];
    }
    if (psBarcodeExistsOnBranch($conn, $branch, $value, $excludeGoodsId)) {
        return ['ok' => false, 'error' => 'That barcode is already assigned to another product on this branch.'];
    }
    return ['ok' => true, 'value' => $value];
}

/**
 * Build POS resolve payload from a goods row (branch-scoped, ACTIVE checked by caller).
 *
 * @param array<string,mixed> $row
 * @return array{ok:bool,product?:array<string,mixed>,error?:string,code?:string}
 */
function psPosResolveProductPayload(mysqli $conn, array $row, string $branch): array
{
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    if ($status !== PS_PRODUCT_STATUS_ACTIVE) {
        return ['ok' => false, 'error' => 'That product is inactive and cannot be sold.', 'code' => 'inactive'];
    }

    $productId = trim((string)($row['product_id'] ?? ''));
    $ledgerQty = null;
    if (function_exists('psControlledLedgerBalances') && $productId !== '') {
        $ledger = psControlledLedgerBalances($conn, $branch);
        if (array_key_exists($productId, $ledger)) {
            $ledgerQty = $ledger[$productId];
        }
    }

    return [
        'ok' => true,
        'product' => [
            'product_id' => $productId,
            'product' => (string)($row['product'] ?? ''),
            'display_name' => psProductDisplayLabel(
                (string)($row['product'] ?? ''),
                $productId,
                (string)($row['pack_size'] ?? ''),
                $row['price'] ?? null
            ),
            'price' => (float)($row['price'] ?? 0),
            'barcode' => (string)($row['barcode'] ?? ''),
            'quantity' => isset($row['quantity']) ? (float)$row['quantity'] : 0,
            'ledger_qty' => $ledgerQty,
            'status' => $status,
            'branch' => (string)($row['branch'] ?? $branch),
            'goods_id' => (int)($row['id'] ?? 0),
        ],
    ];
}

/**
 * Exact goods lookup on authorised working branch (barcode or product_id column).
 *
 * @return array{ok:bool,rows?:array<int,array<string,mixed>>,error?:string,code?:string}
 */
function psPosExactGoodsLookup(
    mysqli $conn,
    string $column,
    string $value,
    string $branch,
    string $company = ''
): array {
    if ($column !== 'barcode' && $column !== 'product_id') {
        return ['ok' => false, 'error' => 'Barcode lookup failed.', 'code' => 'lookup'];
    }
    $company = trim($company);
    if ($company !== '') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, product_id, product, price, status, branch, pack_size, barcode, quantity
             FROM goods WHERE `$column` = ? AND branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = '')"
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Barcode lookup failed.', 'code' => 'lookup'];
        }
        mysqli_stmt_bind_param($stmt, 'sss', $value, $branch, $company);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, product_id, product, price, status, branch, pack_size, barcode, quantity
             FROM goods WHERE `$column` = ? AND branch = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Barcode lookup failed.', 'code' => 'lookup'];
        }
        mysqli_stmt_bind_param($stmt, 'ss', $value, $branch);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Barcode lookup failed.', 'code' => 'lookup'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Exact barcode (then exact product_id) resolve on authorised working branch for POS.
 * Matches catalogue search identity for a full code: same branch/company/ACTIVE rules.
 * Identity returned by product_id (not name). Does not invent barcodes or name matches.
 *
 * @return array{
 *   ok:bool,
 *   error?:string,
 *   code?:string,
 *   product?:array<string,mixed>
 * }
 */
function psResolveBarcodeForPos(mysqli $conn, string $rawCode, string $branch, string $company = ''): array
{
    $norm = psNormalizeBarcode($rawCode);
    if (!$norm['ok']) {
        return ['ok' => false, 'error' => $norm['error'], 'code' => 'invalid'];
    }
    if ($norm['value'] === null) {
        return ['ok' => false, 'error' => 'Scan a barcode.', 'code' => 'empty'];
    }
    $code = $norm['value'];
    $branch = trim($branch);
    if ($branch === '') {
        return ['ok' => false, 'error' => 'That branch is not available.', 'code' => 'branch'];
    }
    if (!psEnsureGoodsBarcodeSchema($conn) || !psEnsureGoodsStatus($conn)) {
        return ['ok' => false, 'error' => 'Barcode lookup failed.', 'code' => 'schema'];
    }

    $company = trim($company);

    // 1) Exact barcode (scanner labels) — preferred over product_id when both could match.
    $byBarcode = psPosExactGoodsLookup($conn, 'barcode', $code, $branch, $company);
    if (empty($byBarcode['ok'])) {
        return $byBarcode;
    }
    $rows = $byBarcode['rows'] ?? [];
    if (count($rows) > 1) {
        return ['ok' => false, 'error' => 'Barcode matches more than one product on this branch.', 'code' => 'ambiguous'];
    }
    if (count($rows) === 1) {
        return psPosResolveProductPayload($conn, $rows[0], $branch);
    }

    // 2) Exact product_id — same identity search products can find when no barcode is assigned.
    $byId = psPosExactGoodsLookup($conn, 'product_id', $code, $branch, $company);
    if (empty($byId['ok'])) {
        return $byId;
    }
    $rows = $byId['rows'] ?? [];
    if (count($rows) > 1) {
        return ['ok' => false, 'error' => 'Product code matches more than one product on this branch.', 'code' => 'ambiguous'];
    }
    if (count($rows) === 1) {
        return psPosResolveProductPayload($conn, $rows[0], $branch);
    }

    return ['ok' => false, 'error' => 'Unknown barcode.', 'code' => 'unknown'];
}

/**
 * Goods rows for label printing (authorised branch, optional product_id list).
 *
 * @param array<int,string> $productIds
 * @return array{ok:bool,error?:string,rows?:array<int,array<string,mixed>>}
 */
function psBarcodeLabelProducts(mysqli $conn, string $branch, array $productIds): array
{
    $branch = trim($branch);
    if ($branch === '' || !psProductBranchIsValid($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if (!psEnsureGoodsBarcodeSchema($conn)) {
        return ['ok' => false, 'error' => 'Barcode labels could not be prepared.'];
    }
    $ids = [];
    foreach ($productIds as $pid) {
        $pid = trim((string)$pid);
        if ($pid === '' || strlen($pid) > 250) {
            continue;
        }
        $ids[$pid] = $pid;
    }
    if (!$ids) {
        return ['ok' => false, 'error' => 'Select at least one product with a barcode.'];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 's' . str_repeat('s', count($ids));
    $sql = "SELECT id, product_id, product, price, barcode, pack_size, status
            FROM goods
            WHERE branch = ? AND product_id IN ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Barcode labels could not be prepared.'];
    }
    $bind = array_merge([$branch], array_values($ids));
    $refs = [];
    $refs[] = &$types;
    foreach ($bind as $k => $v) {
        $refs[] = &$bind[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Barcode labels could not be prepared.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $bc = trim((string)($row['barcode'] ?? ''));
        if ($bc === '') {
            continue;
        }
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    if (!$rows) {
        return ['ok' => false, 'error' => 'None of the selected products have a barcode on this branch.'];
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Render Code 128 PNG for the exact stored barcode string (preserves leading zeros).
 *
 * @return array{ok:bool,png?:string,error?:string}
 */
function psBarcodeRenderPng(string $barcode, int $widthFactor = 2, int $height = 48): array
{
    $norm = psNormalizeBarcode($barcode);
    if (!$norm['ok'] || $norm['value'] === null) {
        return ['ok' => false, 'error' => 'Invalid barcode.'];
    }
    $code = $norm['value'];
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return ['ok' => false, 'error' => 'Barcode library is not available.'];
    }
    require_once $autoload;
    try {
        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
        $png = $generator->getBarcode($code, $generator::TYPE_CODE_128, $widthFactor, $height);
        if ($png === '' || $png === false) {
            return ['ok' => false, 'error' => 'Barcode image could not be generated.'];
        }
        return ['ok' => true, 'png' => $png];
    } catch (Throwable $e) {
        error_log('barcode render failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Barcode image could not be generated.'];
    }
}

/**
 * SQL fragment helpers for search including barcode (prepared callers preferred).
 * Returns LIKE-safe escaped fragment for legacy escaped queries.
 */
function psBarcodeSearchSqlClause(mysqli $conn, string $searchLikeEscaped): string
{
    // Assumes column exists (caller ensures schema).
    return "(LOWER(product) LIKE LOWER('%$searchLikeEscaped%')
            OR LOWER(product_id) LIKE LOWER('%$searchLikeEscaped%')
            OR barcode LIKE '%$searchLikeEscaped%')";
}

/**
 * Bind-safe barcode for mysqli: empty → null string variable that stays null.
 * Callers must use SQL `?` and type `s`; PHP mysqlnd stores NULL when the
 * bound variable is null (not empty string).
 */
function psBarcodeBindParam(?string $barcode): ?string
{
    if ($barcode === null || $barcode === '') {
        return null;
    }
    return $barcode;
}
