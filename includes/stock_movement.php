<?php

/**
 * Stock movement ledger. Schema is created on include when $conn exists.
 * Writes do not commit or roll back a transaction they did not open.
 */

function stockMovementTypes(): array
{
    return [
        'GOODS_RECEIPT' => 'in',
        'REFUND' => 'in',
        'TRANSFER_IN' => 'in',
        'OPENING_BALANCE' => 'opening',
        'SALE' => 'out',
        'TRANSFER_OUT' => 'out',
        'PURCHASE_RETURN' => 'out',
        'ADJUSTMENT' => 'either',
    ];
}

function stockMovementInTransaction(mysqli $conn): bool
{
    $res = mysqli_query($conn, 'SELECT @@in_transaction AS open_tx');
    if (!$res) {
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    return $row && (string)$row['open_tx'] === '1';
}

function stockMovementEnsureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    if (stockMovementInTransaction($conn)) {
        $check = mysqli_query($conn, 'SELECT 1 FROM stock_movements LIMIT 0');
        if ($check && stockMovementOpeningKeyReady($conn)) {
            $ready = true;
            return true;
        }
        error_log('stock movement schema was not created');
        return false;
    }
    $sql = "CREATE TABLE IF NOT EXISTS stock_movements (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id VARCHAR(250) NOT NULL,
        branch VARCHAR(250) NOT NULL,
        movement_type VARCHAR(32) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        reference_type VARCHAR(32) NULL,
        reference_id VARCHAR(250) NULL,
        movement_at DATETIME NOT NULL,
        user_id INT NULL,
        user_email VARCHAR(250) NULL,
        notes VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        opening_key VARCHAR(501)
            GENERATED ALWAYS AS (
                IF(movement_type = 'OPENING_BALANCE', CONCAT(product_id, '|', branch), NULL)
            ) PERSISTENT,
        UNIQUE KEY uniq_stock_movements_opening (opening_key),
        KEY idx_stock_movements_product (product_id),
        KEY idx_stock_movements_branch (branch),
        KEY idx_stock_movements_type (movement_type),
        KEY idx_stock_movements_reference (reference_type, reference_id),
        KEY idx_stock_movements_at (movement_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('stock movement schema was not created');
        return false;
    }
    if (!stockMovementEnsureOpeningKey($conn)) {
        return false;
    }
    $ready = true;
    return true;
}

function stockMovementOpeningKeyReady(mysqli $conn): bool
{
    $col = mysqli_query($conn, "SHOW COLUMNS FROM stock_movements LIKE 'opening_key'");
    if (!$col || mysqli_num_rows($col) < 1) {
        return false;
    }
    $idx = mysqli_query($conn, "SHOW INDEX FROM stock_movements WHERE Key_name = 'uniq_stock_movements_opening'");
    return $idx && mysqli_num_rows($idx) > 0;
}

function stockMovementEnsureOpeningKey(mysqli $conn): bool
{
    if (stockMovementOpeningKeyReady($conn)) {
        return true;
    }
    if (stockMovementInTransaction($conn)) {
        error_log('stock movement opening key was not created');
        return false;
    }
    $col = mysqli_query($conn, "SHOW COLUMNS FROM stock_movements LIKE 'opening_key'");
    if (!$col || mysqli_num_rows($col) < 1) {
        $added = mysqli_query(
            $conn,
            "ALTER TABLE stock_movements
             ADD COLUMN opening_key VARCHAR(501)
             GENERATED ALWAYS AS (
                 IF(movement_type = 'OPENING_BALANCE', CONCAT(product_id, '|', branch), NULL)
             ) PERSISTENT"
        );
        if (!$added && mysqli_errno($conn) !== 1060) {
            error_log('stock movement opening key was not created');
            return false;
        }
    }
    $idx = mysqli_query($conn, "SHOW INDEX FROM stock_movements WHERE Key_name = 'uniq_stock_movements_opening'");
    if (!$idx || mysqli_num_rows($idx) < 1) {
        $indexed = mysqli_query($conn, 'ALTER TABLE stock_movements ADD UNIQUE KEY uniq_stock_movements_opening (opening_key)');
        if (!$indexed && mysqli_errno($conn) !== 1061) {
            error_log('stock movement opening key was not created');
            return false;
        }
    }
    return stockMovementOpeningKeyReady($conn);
}

function psStockDecimal($raw, int $scale = 3): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '' || stripos($raw, 'e') !== false) {
        return null;
    }
    $sign = 1;
    if ($raw[0] === '+' || $raw[0] === '-') {
        $sign = $raw[0] === '-' ? -1 : 1;
        $raw = substr($raw, 1);
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return null;
    }
    [$whole, $frac] = array_pad(explode('.', $raw, 2), 2, '');
    $frac = substr($frac . str_repeat('0', $scale + 1), 0, $scale + 1);
    $roundUp = (int)$frac[$scale] >= 5;
    $frac = substr($frac, 0, $scale);
    $digits = ltrim($whole . $frac, '0');
    if ($digits === '') {
        $digits = '0';
    }
    if ($roundUp) {
        $chars = str_split($digits);
        $carry = 1;
        for ($i = count($chars) - 1; $i >= 0 && $carry; $i--) {
            $n = (int)$chars[$i] + $carry;
            $chars[$i] = (string)($n % 10);
            $carry = intdiv($n, 10);
        }
        if ($carry) {
            array_unshift($chars, '1');
        }
        $digits = implode('', $chars);
    }
    $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
    $wholeOut = ltrim(substr($digits, 0, -$scale), '0');
    if ($wholeOut === '') {
        $wholeOut = '0';
    }
    $fracOut = substr($digits, -$scale);
    $out = $wholeOut . '.' . $fracOut;
    if ($sign < 0 && !preg_match('/^0\.0+$/', $out)) {
        $out = '-' . $out;
    }
    return $out;
}

function psStockMilli($decimal): ?int
{
    $decimal = psStockDecimal($decimal);
    if ($decimal === null) {
        return null;
    }
    $negative = isset($decimal[0]) && $decimal[0] === '-';
    $body = ltrim($decimal, '-');
    [$whole, $frac] = explode('.', $body);
    if (strlen($whole) > 15) {
        return null;
    }
    $milli = ((int)$whole) * 1000 + (int)$frac;
    return $negative ? -$milli : $milli;
}

function psMilliToStock(int $milli): string
{
    $negative = $milli < 0;
    $abs = abs($milli);
    $whole = intdiv($abs, 1000);
    $frac = str_pad((string)($abs % 1000), 3, '0', STR_PAD_LEFT);
    $out = $whole . '.' . $frac;
    if ($negative && $out !== '0.000') {
        return '-' . $out;
    }
    return $out;
}

function psStockDecimalCmp(string $left, string $right): int
{
    $a = psStockDecimal($left);
    $b = psStockDecimal($right);
    if ($a === null || $b === null) {
        return 0;
    }
    $aNeg = isset($a[0]) && $a[0] === '-';
    $bNeg = isset($b[0]) && $b[0] === '-';
    if ($aNeg !== $bNeg) {
        return $aNeg ? -1 : 1;
    }
    $aBody = ltrim($a, '-');
    $bBody = ltrim($b, '-');
    [$aw, $af] = explode('.', $aBody);
    [$bw, $bf] = explode('.', $bBody);
    $aw = ltrim($aw, '0');
    $bw = ltrim($bw, '0');
    if ($aw === '') {
        $aw = '0';
    }
    if ($bw === '') {
        $bw = '0';
    }
    $cmp = 0;
    if (strlen($aw) !== strlen($bw)) {
        $cmp = strlen($aw) > strlen($bw) ? 1 : -1;
    } elseif ($aw !== $bw) {
        $cmp = $aw > $bw ? 1 : -1;
    } elseif ($af !== $bf) {
        $cmp = $af > $bf ? 1 : -1;
    }
    if ($aNeg) {
        $cmp = -$cmp;
    }
    return $cmp;
}

function psStockQtyPlain(string $decimal): string
{
    $neg = isset($decimal[0]) && $decimal[0] === '-';
    $body = ltrim($decimal, '-');
    if (strpos($body, '.') !== false) {
        $body = rtrim(rtrim($body, '0'), '.');
    }
    if ($body === '') {
        $body = '0';
    }
    if ($neg && $body !== '0') {
        return '-' . $body;
    }
    return $body;
}

function psFormatSignedQty($quantity): string
{
    $decimal = psStockDecimal($quantity);
    if ($decimal === null) {
        return '0';
    }
    $plain = psStockQtyPlain($decimal);
    if (psStockDecimalCmp($decimal, '0') > 0) {
        return '+' . $plain;
    }
    return $plain;
}

/**
 * Display label for one ledger row.
 * Stock take is an ADJUSTMENT whose reference_type is STOCK_TAKE.
 * Manual adjustments keep the Adjustment label.
 */
function psMovementKindLabel(string $movementType, string $referenceType = ''): string
{
    if ($movementType === 'ADJUSTMENT' && strcasecmp(trim($referenceType), 'STOCK_TAKE') === 0) {
        return 'Stock Take';
    }
    if ($movementType === 'OPENING_BALANCE' && strcasecmp(trim($referenceType), 'LEGACY_MIGRATION') === 0) {
        return 'Opening Balance / Legacy Stock Migration';
    }
    $labels = [
        'OPENING_BALANCE' => 'Opening Balance',
        'GOODS_RECEIPT' => 'Goods Receipt',
        'SALE' => 'Sale',
        'REFUND' => 'Refund',
        'TRANSFER_IN' => 'Transfer In',
        'TRANSFER_OUT' => 'Transfer Out',
        'PURCHASE_RETURN' => 'Purchase Return',
        'ADJUSTMENT' => 'Adjustment',
    ];
    return $labels[$movementType] ?? $movementType;
}

function stockMovementBranchExists(mysqli $conn, string $branch): bool
{
    $branch = trim((string)$branch);
    if ($branch === '') {
        return false;
    }
    // SQL collation may be case-insensitive; require PHP exact match after trim (Phase 9.2/9.5).
    $stmt = mysqli_prepare($conn, 'SELECT branch_name FROM branchies WHERE branch_name = ? LIMIT 1');
    if (!$stmt) {
        error_log('stock movement branch check failed');
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock movement branch check failed');
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return false;
    }
    return trim((string)($row['branch_name'] ?? '')) === $branch;
}

function stockMovementProductExists(mysqli $conn, string $productId): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM goods WHERE product_id = ? LIMIT 1');
    if (!$stmt) {
        error_log('stock movement product check failed');
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $productId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock movement product check failed');
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    $found = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (bool)$found;
}

/**
 * Distinct goods product codes that share one exact product name.
 * null means the lookup failed. An empty list means the name is unknown.
 */
function stockMovementIdsForExactName(mysqli $conn, string $productName): ?array
{
    $productName = trim($productName);
    if ($productName === '') {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT DISTINCT product_id FROM goods WHERE product = ? AND product_id <> \'\''
    );
    if (!$stmt) {
        error_log('stock movement product lookup failed');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $productName);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock movement product lookup failed');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        error_log('stock movement product lookup failed');
        return null;
    }
    $ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $id = trim((string)$row['product_id']);
        if ($id !== '') {
            $ids[$id] = $id;
        }
    }
    mysqli_stmt_close($stmt);
    return array_values($ids);
}

/**
 * Resolve stock identity from product_id only. Product name is display/search — never identity.
 */
function stockMovementResolveProduct(mysqli $conn, string $productId, string $productName = ''): ?string
{
    $productId = trim($productId);
    if ($productId === '') {
        return null;
    }
    return stockMovementProductExists($conn, $productId) ? $productId : null;
}

/**
 * Resolve product_id for a branch: exactly one goods row for product_id + branch.
 */
function stockMovementResolveProductOnBranch(mysqli $conn, string $productId, string $branch): ?string
{
    require_once __DIR__ . '/product_master.php';
    $row = psGoodsByProductIdBranch($conn, $productId, $branch);
    if (!$row) {
        return null;
    }
    $id = trim((string)($row['product_id'] ?? ''));
    return $id !== '' ? $id : null;
}

function stockMovementActor(mysqli $conn, array $movement): array
{
    $userId = null;
    if (array_key_exists('user_id', $movement) && $movement['user_id'] !== null && trim((string)$movement['user_id']) !== '') {
        $userId = (int)$movement['user_id'];
        if ($userId <= 0) {
            $userId = null;
        }
    }
    $email = trim((string)($movement['user_email'] ?? ''));
    if ($email === '' && $userId === null && session_status() === PHP_SESSION_ACTIVE) {
        $email = trim((string)($_SESSION['logger'] ?? ''));
    }
    if ($email !== '' && $userId === null) {
        $stmt = mysqli_prepare($conn, 'SELECT id, email FROM users WHERE email = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $email);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                if ($row) {
                    $userId = (int)$row['id'];
                    $email = (string)$row['email'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    return ['user_id' => $userId, 'user_email' => $email];
}

function recordStockMovement(mysqli $conn, array $movement): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Stock movements are not available.'];
    }

    $productId = trim((string)($movement['product_id'] ?? ''));
    $branch = trim((string)($movement['branch'] ?? ''));
    $type = trim((string)($movement['movement_type'] ?? ''));
    $types = stockMovementTypes();
    if ($productId === '' || !stockMovementProductExists($conn, $productId)) {
        return ['ok' => false, 'error' => 'Product was not found.'];
    }
    if ($branch === '') {
        return ['ok' => false, 'error' => 'Branch is required.'];
    }
    if (!stockMovementBranchExists($conn, $branch)) {
        return ['ok' => false, 'error' => 'Branch was not found.'];
    }
    if (!isset($types[$type])) {
        return ['ok' => false, 'error' => 'That movement type is not valid.'];
    }
    if (!array_key_exists('quantity', $movement) || trim((string)$movement['quantity']) === '' || psStockDecimal($movement['quantity']) === null) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $quantity = psStockDecimal($movement['quantity']);
    $direction = $types[$type];
    if ($type === 'OPENING_BALANCE') {
        if (psStockDecimalCmp($quantity, '0') < 0) {
            return ['ok' => false, 'error' => 'An opening quantity cannot be negative.'];
        }
    } elseif (psStockDecimalCmp($quantity, '0') === 0) {
        return ['ok' => false, 'error' => 'Quantity must not be zero.'];
    }
    if ($direction === 'in' && psStockDecimalCmp($quantity, '0') < 0) {
        if ($type === 'GOODS_RECEIPT') {
            return ['ok' => false, 'error' => 'A goods receipt quantity must be greater than zero.'];
        }
        return ['ok' => false, 'error' => 'That movement quantity must be greater than zero.'];
    }
    if ($direction === 'out' && psStockDecimalCmp($quantity, '0') > 0) {
        if ($type === 'PURCHASE_RETURN') {
            return ['ok' => false, 'error' => 'A purchase return quantity must reduce stock (use a negative movement quantity).'];
        }
        return ['ok' => false, 'error' => 'That movement quantity must be less than zero.'];
    }

    $referenceType = trim((string)($movement['reference_type'] ?? ''));
    $referenceId = trim((string)($movement['reference_id'] ?? ''));
    if (strlen($referenceType) > 32 || strlen($referenceId) > 250) {
        return ['ok' => false, 'error' => 'That reference is too long.'];
    }
    $notes = trim((string)($movement['notes'] ?? ''));
    if (strlen($notes) > 500) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }
    if (!isset($movement['movement_at']) || trim((string)$movement['movement_at']) === '') {
        $movementAt = date('Y-m-d H:i:s');
    } else {
        $parsed = DateTime::createFromFormat('Y-m-d H:i:s', trim((string)$movement['movement_at']));
        $errors = DateTime::getLastErrors();
        if (!$parsed || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return ['ok' => false, 'error' => 'Movement time is not valid.'];
        }
        $movementAt = $parsed->format('Y-m-d H:i:s');
    }

    if ($type === 'OPENING_BALANCE') {
        $existingQty = psOpeningQuantity($conn, $productId, $branch, stockMovementInTransaction($conn));
        if ($existingQty !== null) {
            return ['ok' => false, 'error' => psOpeningDuplicateMessage($existingQty)];
        }
    }

    $actor = stockMovementActor($conn, $movement);
    $userId = $actor['user_id'] === null ? '' : (string)$actor['user_id'];
    $userEmail = $actor['user_email'];
    $createdAt = date('Y-m-d H:i:s');

    $sql = 'INSERT INTO stock_movements
        (product_id, branch, movement_type, quantity, reference_type, reference_id, movement_at, user_id, user_email, notes, created_at)
        VALUES (?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?)';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('stock movement insert was not prepared');
        return ['ok' => false, 'error' => 'The stock movement could not be saved.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssss',
        $productId,
        $branch,
        $type,
        $quantity,
        $referenceType,
        $referenceId,
        $movementAt,
        $userId,
        $userEmail,
        $notes,
        $createdAt
    );
    if (!mysqli_stmt_execute($stmt)) {
        $duplicate = mysqli_errno($conn) === 1062;
        mysqli_stmt_close($stmt);
        if ($duplicate && $type === 'OPENING_BALANCE') {
            $existingQty = psOpeningQuantity($conn, $productId, $branch, false);
            if ($existingQty !== null) {
                return ['ok' => false, 'error' => psOpeningDuplicateMessage($existingQty)];
            }
            return ['ok' => false, 'error' => 'An opening balance already exists for this product at this branch.'];
        }
        error_log('stock movement insert failed');
        return ['ok' => false, 'error' => 'The stock movement could not be saved.'];
    }
    $id = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'id' => $id];
}

function getStockBalance(mysqli $conn, $productId, $branch): string
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $productId = trim((string)$productId);
    $branch = trim((string)$branch);
    if ($productId === '' || $branch === '') {
        return '0.000';
    }
    if (!stockMovementEnsureSchema($conn)) {
        return '0.000';
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(SUM(quantity), 0) AS balance FROM stock_movements WHERE product_id = ? AND branch = ?'
    );
    if (!$stmt) {
        error_log('stock balance read failed');
        return '0.000';
    }
    mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock balance read failed');
        return '0.000';
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    $balance = psStockDecimal($row['balance'] ?? '0');
    return $balance === null ? '0.000' : $balance;
}

/**
 * Sale-time ledger read for one product at one branch.
 * A product is ledger-controlled only when an OPENING_BALANCE row exists.
 * SALE or GOODS_RECEIPT rows alone do not control it.
 * When it is controlled, every movement row for that product and branch is locked
 * with FOR UPDATE and the balance is their quantity sum.
 *
 * @return array{ok:bool, tracked?:bool, milli?:int, balance?:string}
 */
function psSaleLedgerBalance(mysqli $conn, string $productId, string $branch): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || $branch === '') {
        error_log('sale: stock balance was not read');
        return ['ok' => false];
    }

    $look = mysqli_prepare(
        $conn,
        'SELECT id FROM stock_movements WHERE product_id = ? AND branch = ? AND movement_type = \'OPENING_BALANCE\' LIMIT 1'
    );
    if (!$look) {
        error_log('sale: stock balance was not read');
        return ['ok' => false];
    }
    mysqli_stmt_bind_param($look, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($look)) {
        mysqli_stmt_close($look);
        error_log('sale: stock balance was not read');
        return ['ok' => false];
    }
    $lookResult = mysqli_stmt_get_result($look);
    $found = $lookResult && mysqli_fetch_assoc($lookResult);
    mysqli_stmt_close($look);
    if (!$found) {
        return ['ok' => true, 'tracked' => false];
    }

    $lock = mysqli_prepare(
        $conn,
        'SELECT quantity FROM stock_movements WHERE product_id = ? AND branch = ? FOR UPDATE'
    );
    if (!$lock) {
        error_log('sale: stock balance was not read');
        return ['ok' => false];
    }
    mysqli_stmt_bind_param($lock, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($lock)) {
        mysqli_stmt_close($lock);
        error_log('sale: stock balance was not read');
        return ['ok' => false];
    }
    $locked = mysqli_stmt_get_result($lock);
    if (!$locked) {
        mysqli_stmt_close($lock);
        error_log('sale: stock balance was not read');
        return ['ok' => false];
    }
    $milli = 0;
    $count = 0;
    while ($row = mysqli_fetch_assoc($locked)) {
        $part = psStockMilli($row['quantity'] ?? '');
        if ($part === null) {
            mysqli_stmt_close($lock);
            error_log('sale: stock balance was not read');
            return ['ok' => false];
        }
        $count++;
        $milli += $part;
    }
    mysqli_stmt_close($lock);
    if ($count < 1) {
        return ['ok' => true, 'tracked' => false];
    }
    return [
        'ok' => true,
        'tracked' => true,
        'milli' => $milli,
        'balance' => psMilliToStock($milli),
    ];
}

/**
 * Ledger balances for products that are controlled at one branch.
 * One grouped SUM. A product is included only when that product_id and branch
 * has an OPENING_BALANCE row. Other movement types alone do not qualify.
 * Balances are not mixed with goods_receipts, online_sales, or movements_receipts.
 *
 * @return array<string,string> product_id => plain quantity
 */
function psControlledLedgerBalances(mysqli $conn, string $branch): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '' || !stockMovementEnsureSchema($conn)) {
        return [];
    }
    $sql = 'SELECT product_id, branch, SUM(quantity) AS balance
            FROM stock_movements
            WHERE branch = ?
            GROUP BY product_id, branch
            HAVING SUM(CASE WHEN movement_type = \'OPENING_BALANCE\' THEN 1 ELSE 0 END) > 0';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('stock balance list failed');
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock balance list failed');
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        error_log('stock balance list failed');
        return [];
    }
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $balance = psStockDecimal($row['balance'] ?? '0');
        if ($balance === null) {
            continue;
        }
        $map[(string)$row['product_id']] = psStockQtyPlain($balance);
    }
    mysqli_stmt_close($stmt);
    return $map;
}

function psLedgerQtyByProduct(mysqli $conn, string $branch, array $productIds): array
{
    $branch = trim($branch);
    $ids = [];
    foreach ($productIds as $productId) {
        $productId = trim((string)$productId);
        if ($productId !== '') {
            $ids[$productId] = $productId;
        }
    }
    if ($branch === '' || !$ids) {
        return [];
    }
    $balances = psControlledLedgerBalances($conn, $branch);
    $map = [];
    foreach ($ids as $productId) {
        if (array_key_exists($productId, $balances)) {
            $map[$productId] = $balances[$productId];
        }
    }
    return $map;
}

/**
 * Distinct goods product ids for each product name.
 * A name is usable for display only when it maps to exactly one id.
 * Several ids are left unresolved so the lowest id is never chosen.
 *
 * @return array<string,array<string,string>>
 */
function psGoodsNameProductIds(mysqli $conn): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $result = mysqli_query(
        $conn,
        "SELECT product, product_id FROM goods WHERE product <> '' AND product_id <> '' GROUP BY product, product_id"
    );
    if (!$result) {
        error_log('stock display names were not loaded');
        return [];
    }
    $idsByName = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $name = (string)($row['product'] ?? '');
        $productId = trim((string)($row['product_id'] ?? ''));
        if ($name === '' || $productId === '') {
            continue;
        }
        $idsByName[$name][$productId] = $productId;
    }
    return $idsByName;
}

/**
 * goods.price for each product id at one branch.
 * Every goods row is kept. A shared product id is not reduced with ORDER BY id LIMIT 1.
 *
 * @return array<string,array<int,array{product:string,price:mixed}>>|null
 */
function psGoodsSellingPrices(mysqli $conn, string $branch): ?array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '') {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT product_id, product, price FROM goods WHERE branch = ? AND product_id <> \'\''
    );
    if (!$stmt) {
        error_log('stock display prices were not loaded');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock display prices were not loaded');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        error_log('stock display prices were not loaded');
        return null;
    }
    $prices = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $productId = trim((string)($row['product_id'] ?? ''));
        if ($productId === '') {
            continue;
        }
        $prices[$productId][] = [
            'product' => (string)($row['product'] ?? ''),
            'price' => $row['price'],
        ];
    }
    mysqli_stmt_close($stmt);
    return $prices;
}

/**
 * Selling price for one catalogue row at the branch already loaded.
 * One goods row for the product id uses that row's goods.price.
 * Several rows are used only when the product name matches a single price.
 * Differing prices are not collapsed onto the lowest goods id.
 */
function psSellingPriceForGoodsRow(array $prices, string $productId, string $productName): ?string
{
    if ($productId === '' || !isset($prices[$productId])) {
        return null;
    }
    $rows = $prices[$productId];
    if (count($rows) === 1) {
        $price = $rows[0]['price'];
        return is_numeric($price) ? (string)$price : '0';
    }
    $matched = [];
    foreach ($rows as $row) {
        if ((string)$row['product'] === $productName) {
            $matched[] = $row['price'];
        }
    }
    if (!$matched) {
        return null;
    }
    $first = (string)$matched[0];
    foreach ($matched as $price) {
        if ((string)$price !== $first) {
            return null;
        }
    }
    return is_numeric($first) ? $first : '0';
}

function psOnHandSellingValue(string $qty, $price): string
{
    $qtyNumber = is_numeric($qty) ? (float)$qty : 0.0;
    $priceNumber = is_numeric($price) ? (float)$price : 0.0;
    return number_format($qtyNumber * $priceNumber, 2, '.', '');
}

/**
 * Nullable reorder level on the goods row for this product code at this branch.
 * The older goods.re_order_level column is varchar and NOT NULL, so it cannot
 * mean "no reorder level". Existing rows stay NULL. NULL is not zero.
 */
function psEnsureGoodsReorderLevel(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `goods` LIKE 'reorder_level'");
    if ($res && mysqli_num_rows($res) > 0) {
        $ready = true;
        return true;
    }
    if (stockMovementInTransaction($conn)) {
        error_log('goods reorder level column was not added');
        return false;
    }
    $ok = mysqli_query($conn, 'ALTER TABLE `goods` ADD COLUMN `reorder_level` DECIMAL(12,3) NULL');
    if ($ok || mysqli_errno($conn) === 1060) {
        $ready = true;
        return true;
    }
    error_log('goods reorder level column was not added');
    return false;
}

/**
 * Blank is no reorder level. Zero is allowed. Negative values and more than
 * three decimal places are rejected and are not rounded into a level.
 *
 * @return array{ok:bool,value?:?string,error?:string}
 */
function psParseReorderLevel($raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'value' => null];
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => true, 'value' => null];
    }
    if ($raw[0] === '-' || $raw[0] === '+') {
        return ['ok' => false, 'error' => 'Reorder level cannot be negative.'];
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return ['ok' => false, 'error' => 'Reorder level must be a number.'];
    }
    $dot = strpos($raw, '.');
    if ($dot !== false && strlen(substr($raw, $dot + 1)) > 3) {
        return ['ok' => false, 'error' => 'Reorder level can have at most 3 decimal places.'];
    }
    $decimal = psStockDecimal($raw);
    if ($decimal === null || psStockDecimalCmp($decimal, '0') < 0) {
        return ['ok' => false, 'error' => 'Reorder level cannot be negative.'];
    }
    return ['ok' => true, 'value' => $decimal];
}

/**
 * Saves reorder_level on one goods row. A missing posted field is left unchanged.
 * This does not change goods.quantity or the older re_order_level column.
 *
 * @return array{ok:bool,value?:?string,error?:string,skipped?:bool}
 */
function psSaveProductReorderLevel(mysqli $conn, $goodsId, $raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'value' => null, 'skipped' => true];
    }
    $parsed = psParseReorderLevel($raw);
    if (!$parsed['ok']) {
        return $parsed;
    }
    $goodsId = (int)$goodsId;
    if ($goodsId < 1) {
        return ['ok' => false, 'error' => 'Product was not found.'];
    }
    if (!psEnsureGoodsReorderLevel($conn)) {
        return ['ok' => false, 'error' => 'Reorder level could not be saved.'];
    }
    if ($parsed['value'] === null) {
        $stmt = mysqli_prepare($conn, 'UPDATE goods SET reorder_level = NULL WHERE id = ?');
        if (!$stmt) {
            error_log('reorder level was not saved');
            return ['ok' => false, 'error' => 'Reorder level could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 'i', $goodsId);
    } else {
        $value = $parsed['value'];
        $stmt = mysqli_prepare($conn, 'UPDATE goods SET reorder_level = ? WHERE id = ?');
        if (!$stmt) {
            error_log('reorder level was not saved');
            return ['ok' => false, 'error' => 'Reorder level could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 'si', $value, $goodsId);
    }
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok || $affected < 0) {
        error_log('reorder level was not saved');
        return ['ok' => false, 'error' => 'Reorder level could not be saved.'];
    }
    return ['ok' => true, 'value' => $parsed['value']];
}

/**
 * Quantity at or below zero is out of stock even when no reorder level is set,
 * so a negative balance is never hidden as "no reorder level".
 * A blank level does not mean low stock.
 */
function psInventoryStatus(string $quantity, $reorderLevel): string
{
    $qty = psStockDecimal($quantity);
    if ($qty === null || psStockDecimalCmp($qty, '0') <= 0) {
        return 'OUT_OF_STOCK';
    }
    if ($reorderLevel === null || trim((string)$reorderLevel) === '') {
        return 'NO_REORDER_LEVEL';
    }
    $level = psStockDecimal($reorderLevel);
    if ($level === null) {
        return 'NO_REORDER_LEVEL';
    }
    if (psStockDecimalCmp($qty, $level) <= 0) {
        return 'LOW_STOCK';
    }
    return 'IN_STOCK';
}

function psInventoryStatusLabel(string $status): string
{
    $labels = [
        'OUT_OF_STOCK' => 'Out of stock',
        'LOW_STOCK' => 'Low stock',
        'IN_STOCK' => 'In stock',
        'NO_REORDER_LEVEL' => 'No reorder level',
    ];
    return $labels[$status] ?? '';
}

/**
 * reorder_level for each product code on one branch. One read. NULL stays null.
 *
 * @return array<string,?string>
 */
function psGoodsReorderLevels(mysqli $conn, string $branch): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '' || !psEnsureGoodsReorderLevel($conn)) {
        return [];
    }
    $stmt = mysqli_prepare($conn, 'SELECT product_id, reorder_level FROM goods WHERE branch = ?');
    if (!$stmt) {
        error_log('reorder levels were not loaded');
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('reorder levels were not loaded');
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        error_log('reorder levels were not loaded');
        return [];
    }
    $map = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $productId = trim((string)($row['product_id'] ?? ''));
        if ($productId === '') {
            continue;
        }
        $raw = $row['reorder_level'];
        if ($raw === null || trim((string)$raw) === '') {
            $map[$productId] = null;
            continue;
        }
        $decimal = psStockDecimal($raw);
        $map[$productId] = $decimal === null ? null : psStockQtyPlain($decimal);
    }
    mysqli_stmt_close($stmt);
    return $map;
}

/**
 * Controlled products at one branch. On hand is the ledger sum for that
 * product code and branch. Names and reorder levels come from that branch's
 * goods rows. Products with no opening balance are omitted.
 *
 * @return array<int,array{product:string,product_id:string,branch:string,qty:string,reorder_level:?string,status:string}>
 */
function psInventoryControlRows(mysqli $conn, string $branch): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '') {
        return [];
    }
    $balances = psControlledLedgerBalances($conn, $branch);
    if (!$balances) {
        return [];
    }
    $names = [];
    if (psEnsureGoodsReorderLevel($conn)) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT product_id, product, reorder_level FROM goods WHERE branch = ? AND product_id <> \'\''
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $branch);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $productId = trim((string)($row['product_id'] ?? ''));
                        if ($productId === '') {
                            continue;
                        }
                        $raw = $row['reorder_level'];
                        $level = null;
                        if ($raw !== null && trim((string)$raw) !== '') {
                            $decimal = psStockDecimal($raw);
                            $level = $decimal === null ? null : psStockQtyPlain($decimal);
                        }
                        $names[$productId] = [
                            'product' => (string)($row['product'] ?? ''),
                            'reorder_level' => $level,
                        ];
                    }
                }
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log('inventory control rows were not loaded');
        }
    }
    $rows = [];
    foreach ($balances as $productId => $qty) {
        $goods = $names[$productId] ?? null;
        $level = is_array($goods) ? $goods['reorder_level'] : null;
        $rows[] = [
            'product' => (string)($goods['product'] ?? ''),
            'product_id' => (string)$productId,
            'branch' => $branch,
            'qty' => (string)$qty,
            'reorder_level' => $level,
            'status' => psInventoryStatus((string)$qty, $level),
        ];
    }
    $rank = [
        'OUT_OF_STOCK' => 0,
        'LOW_STOCK' => 1,
        'IN_STOCK' => 2,
        'NO_REORDER_LEVEL' => 3,
    ];
    usort($rows, function (array $a, array $b) use ($rank): int {
        $byStatus = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
        if ($byStatus !== 0) {
            return $byStatus;
        }
        $byName = strcasecmp($a['product'], $b['product']);
        if ($byName !== 0) {
            return $byName;
        }
        return strnatcasecmp($a['product_id'], $b['product_id']);
    });
    return $rows;
}

/**
 * Shown on-hand quantity and selling value for stock list rows.
 * Controlled products (an opening exists for product_id + this branch) use the
 * ledger sum from one grouped query. Their displayed value is that quantity
 * times the goods.price of the matching goods row at this branch, not cost_price
 * and not the stored stock_list33.usd_value. The cached quantity and cached
 * value are left in place for every other row. A missing product_id is resolved
 * only when the name maps to exactly one goods product id. Controlled rows also
 * receive inventory_status from that same ledger map and one reorder-level read.
 * Unmigrated rows are left without a status. This does not write the cache,
 * goods.quantity, or any movement.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function psApplyControlledDisplayQty(mysqli $conn, array $rows, string $branch): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '' || !$rows) {
        return $rows;
    }
    $ledger = psControlledLedgerBalances($conn, $branch);
    if (!$ledger) {
        return $rows;
    }
    $reorderLevels = psGoodsReorderLevels($conn, $branch);
    $sellingPrices = psGoodsSellingPrices($conn, $branch);
    if ($sellingPrices === null) {
        $sellingPrices = [];
    }
    $needsName = false;
    foreach ($rows as $row) {
        if (trim((string)($row['product_id'] ?? '')) === '') {
            $needsName = true;
            break;
        }
    }
    $uniqueNameId = [];
    if ($needsName) {
        foreach (psGoodsNameProductIds($conn) as $name => $ids) {
            if (count($ids) === 1) {
                $uniqueNameId[$name] = reset($ids);
            }
        }
    }
    foreach ($rows as $index => $row) {
        $productId = trim((string)($row['product_id'] ?? ''));
        if ($productId === '') {
            $name = (string)($row['product'] ?? '');
            if ($name === '' || !isset($uniqueNameId[$name])) {
                continue;
            }
            $productId = $uniqueNameId[$name];
        }
        if ($productId === '' || !isset($ledger[$productId])) {
            continue;
        }
        $rows[$index]['qty'] = $ledger[$productId];
        $level = array_key_exists($productId, $reorderLevels) ? $reorderLevels[$productId] : null;
        $rows[$index]['inventory_status'] = psInventoryStatus($ledger[$productId], $level);
        if (!array_key_exists('usd_value', $row)) {
            continue;
        }
        $price = psSellingPriceForGoodsRow($sellingPrices, $productId, (string)($row['product'] ?? ''));
        if ($price === null) {
            continue;
        }
        $rows[$index]['usd_value'] = psOnHandSellingValue($ledger[$productId], $price);
    }
    return $rows;
}

function listStockMovements(mysqli $conn, array $filters): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'rows' => [], 'error' => 'Stock movements are not available.'];
    }

    $allowed = [];
    foreach (($filters['allowed_branches'] ?? []) as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $allowed[$name] = $name;
        }
    }
    $branch = trim((string)($filters['branch'] ?? ''));
    if ($allowed && ($branch === '' || !isset($allowed[$branch]))) {
        return ['ok' => true, 'rows' => [], 'error' => ''];
    }
    if ($branch === '') {
        return ['ok' => true, 'rows' => [], 'error' => ''];
    }

    $where = ['m.branch = ?'];
    $types = 's';
    $params = [$branch];

    $movementType = trim((string)($filters['movement_type'] ?? ''));
    if ($movementType !== '') {
        if (!isset(stockMovementTypes()[$movementType])) {
            return ['ok' => false, 'rows' => [], 'error' => 'That movement type is not valid.'];
        }
        $where[] = 'm.movement_type = ?';
        $types .= 's';
        $params[] = $movementType;
    }

    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateFrom !== '' && !DateTime::createFromFormat('Y-m-d', $dateFrom)) {
        return ['ok' => false, 'rows' => [], 'error' => 'The start date is not valid.'];
    }
    if ($dateTo !== '' && !DateTime::createFromFormat('Y-m-d', $dateTo)) {
        return ['ok' => false, 'rows' => [], 'error' => 'The end date is not valid.'];
    }
    if ($dateFrom !== '') {
        $where[] = 'm.movement_at >= ?';
        $types .= 's';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = 'm.movement_at <= ?';
        $types .= 's';
        $params[] = $dateTo . ' 23:59:59';
    }

    $product = trim((string)($filters['product'] ?? ''));
    if ($product !== '') {
        $like = '%' . addcslashes($product, '%_\\') . '%';
        $where[] = '(m.product_id = ? OR EXISTS (SELECT 1 FROM goods g WHERE g.product_id = m.product_id AND g.product LIKE ?))';
        $types .= 'ss';
        $params[] = $product;
        $params[] = $like;
    }

    $sql = 'SELECT m.id, m.product_id, m.branch, m.movement_type, m.quantity, m.reference_type, m.reference_id,
            m.movement_at, m.user_id, m.user_email, m.notes, m.created_at,
            (SELECT g.product FROM goods g WHERE g.product_id = m.product_id
                ORDER BY CASE WHEN g.branch = m.branch THEN 0 ELSE 1 END, g.id LIMIT 1) AS item_name
        FROM stock_movements m
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY m.movement_at DESC, m.id DESC
        LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('stock movement list was not prepared');
        return ['ok' => false, 'rows' => [], 'error' => 'Stock movements could not be loaded.'];
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
        mysqli_stmt_close($stmt);
        error_log('stock movement list was not bound');
        return ['ok' => false, 'rows' => [], 'error' => 'Stock movements could not be loaded.'];
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock movement list failed');
        return ['ok' => false, 'rows' => [], 'error' => 'Stock movements could not be loaded.'];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/**
 * Save goods receipt (Phase 4C).
 * Modes:
 *   - Non-PO: free-text supplier_name; user supplier invoice; server GRV number; product_id+branch lines.
 *   - PO-linked: purchase_order_id + receive qtys keyed by purchase_order_line_id; supplier/cost from PO;
 *     reject over-receive; CLOSE PO only when all lines fully received (server calc).
 * Atomic: goods_receipts + goods_receipts_ref + GOODS_RECEIPT movements (+ PO status/audit).
 * Does not write goods.quantity or goods.cost_price.
 *
 * @return array{ok:bool,error?:string,invoice_number?:string,grv_number?:string,movement_ids?:array<int,int>,po_closed?:bool}
 */
function psSaveGoodsReceipt(mysqli $conn, array $receipt, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    require_once __DIR__ . '/goods_receipt.php';

    if (!stockMovementEnsureSchema($conn) || !psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'The goods receipt could not be saved.'];
    }

    $changedBy = psSessionGrvUser();
    if ($changedBy === '') {
        return ['ok' => false, 'error' => 'You must be signed in to receive goods.'];
    }

    $branchResolved = psResolveProductWriteBranch($conn, $receipt['branch'] ?? null);
    if (!$branchResolved['ok']) {
        return ['ok' => false, 'error' => $branchResolved['error'] ?? 'That branch is not available.'];
    }
    $branch = $branchResolved['branch'];

    $date = trim((string)($receipt['date'] ?? ''));
    $invoice = trim((string)($receipt['invoice_number'] ?? ''));
    $status = trim((string)($receipt['status'] ?? ''));
    if ($status === '') {
        $status = 'Received';
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'error' => 'Date is required.'];
    }
    if ($invoice === '') {
        return ['ok' => false, 'error' => 'Supplier invoice number is required.'];
    }

    $purchaseOrderId = (int)($receipt['purchase_order_id'] ?? 0);
    $poMode = $purchaseOrderId > 0;
    $poHeader = null;
    $poNumber = null;
    $supplierId = null;
    $supplier = '';
    $lines = [];
    $now = date('Y-m-d H:i:s');

    if ($poMode) {
        $load = psPurchaseOrderLoadForSession($conn, $purchaseOrderId);
        if (!$load['ok']) {
            return ['ok' => false, 'error' => $load['error'] ?? 'Purchase order not found.'];
        }
        $poHeader = $load['header'];
        $poNumber = (string)$poHeader['po_number'];
        if (!psPoCanReceive((string)$poHeader['status'])) {
            return ['ok' => false, 'error' => 'Only approved purchase orders with remaining quantity can be received.'];
        }
        if (trim((string)$poHeader['branch']) !== $branch) {
            return ['ok' => false, 'error' => 'That purchase order is not available on this branch.'];
        }
        // Supplier identity from PO only — never replace from POST free-text.
        $supplierId = (int)$poHeader['supplier_id'];
        $supplierRow = psSupplierByIdForDocument($conn, $supplierId);
        if ($supplierRow === null) {
            return ['ok' => false, 'error' => 'The purchase order supplier was not found.'];
        }
        $supplier = trim((string)$supplierRow['supplier_name']);
        if ($supplier === '') {
            $supplier = trim((string)$supplierRow['supplier_code']);
        }

        $enriched = psPoLinesWithReceived($conn, $load['lines']);
        $byId = [];
        foreach ($enriched as $poLine) {
            $byId[(int)$poLine['id']] = $poLine;
        }

        $poLineIds = $receipt['po_line_ids'] ?? [];
        $receiveQtys = $receipt['receive_quantities'] ?? [];
        if (!is_array($poLineIds) || !is_array($receiveQtys)) {
            return ['ok' => false, 'error' => 'Add at least one receive quantity.'];
        }
        $seenLine = [];
        $count = max(count($poLineIds), count($receiveQtys));
        for ($i = 0; $i < $count; $i++) {
            $poLineId = (int)($poLineIds[$i] ?? 0);
            $qtyRaw = trim((string)($receiveQtys[$i] ?? ''));
            if ($poLineId < 1 && $qtyRaw === '') {
                continue;
            }
            if ($poLineId < 1) {
                return ['ok' => false, 'error' => 'Receive against a purchase order line.'];
            }
            if (isset($seenLine[$poLineId])) {
                return ['ok' => false, 'error' => 'Each purchase order line can appear only once on a receipt.'];
            }
            $seenLine[$poLineId] = true;
            if (!isset($byId[$poLineId])) {
                return ['ok' => false, 'error' => 'That purchase order line does not belong to this purchase order.'];
            }
            $poLine = $byId[$poLineId];
            if (trim((string)$poLine['branch']) !== $branch || trim((string)$poLine['branch']) !== trim((string)$poHeader['branch'])) {
                return ['ok' => false, 'error' => 'Purchase order line branch does not match.'];
            }
            $productId = trim((string)$poLine['product_id']);
            $resolved = stockMovementResolveProductOnBranch($conn, $productId, $branch);
            if ($resolved === null) {
                return ['ok' => false, 'error' => 'Product was not found on the selected branch.'];
            }
            $qtyCheck = psValidatePoQuantity($qtyRaw);
            if (!$qtyCheck['ok']) {
                // Rephrase zero/negatives consistently for receiving.
                if (isset($qtyCheck['error']) && strpos((string)$qtyCheck['error'], 'greater than zero') !== false) {
                    return ['ok' => false, 'error' => 'A goods receipt quantity must be greater than zero.'];
                }
                return ['ok' => false, 'error' => $qtyCheck['error'] ?? 'Quantity must be a number.'];
            }
            $qty = psStockDecimal($qtyCheck['value']);
            if ($qty === null || psStockDecimalCmp($qty, '0') <= 0) {
                return ['ok' => false, 'error' => 'A goods receipt quantity must be greater than zero.'];
            }
            $remaining = (string)$poLine['remaining_qty'];
            if (psStockDecimalCmp($qty, $remaining) > 0) {
                return ['ok' => false, 'error' => 'Received quantity cannot exceed remaining ordered quantity.'];
            }
            $unitCost = psStockDecimal((string)$poLine['unit_cost'], 2);
            if ($unitCost === null) {
                $unitCost = number_format((float)$poLine['unit_cost'], 2, '.', '');
            }
            $name = trim((string)$poLine['description']);
            if ($name === '') {
                $goodsRow = psGoodsByProductIdBranch($conn, $productId, $branch);
                $name = $goodsRow ? (string)$goodsRow['product'] : $productId;
            }
            $lines[] = [
                'product_id' => $productId,
                'product' => $name,
                'quantity' => psStockQtyPlain($qty),
                'quantity_decimal' => $qty,
                'vat' => '0',
                'line_cost' => $unitCost,
                'purchase_order_line_id' => $poLineId,
            ];
        }
        if ($lines === []) {
            return ['ok' => false, 'error' => 'Add at least one receive quantity.'];
        }
    } else {
        $supplier = trim((string)($receipt['supplier_name'] ?? ''));
        if ($supplier === '') {
            return ['ok' => false, 'error' => 'Supplier is required.'];
        }
        $products = $receipt['products'] ?? [];
        $productIds = $receipt['product_ids'] ?? [];
        $quantities = $receipt['quantities'] ?? [];
        $vats = $receipt['vats'] ?? [];
        $lineCosts = $receipt['line_costs'] ?? [];
        if (!is_array($products) || count($products) === 0) {
            return ['ok' => false, 'error' => 'Add at least one product.'];
        }
        foreach ($products as $key => $productName) {
            $name = trim((string)$productName);
            $postedId = trim((string)($productIds[$key] ?? ''));
            $qtyRaw = trim((string)($quantities[$key] ?? ''));
            if ($name === '' && $postedId === '' && $qtyRaw === '') {
                continue;
            }
            if ($postedId === '') {
                return ['ok' => false, 'error' => 'Select a product from the list.'];
            }
            $productId = stockMovementResolveProductOnBranch($conn, $postedId, $branch);
            if ($productId === null) {
                return ['ok' => false, 'error' => 'Product was not found on the selected branch.'];
            }
            $qty = psStockDecimal($qtyRaw);
            if ($qty === null) {
                return ['ok' => false, 'error' => 'Quantity must be a number.'];
            }
            if (psStockDecimalCmp($qty, '0') <= 0) {
                return ['ok' => false, 'error' => 'A goods receipt quantity must be greater than zero.'];
            }
            if ($name === '') {
                $goodsRow = psGoodsByProductIdBranch($conn, $productId, $branch);
                if ($goodsRow) {
                    $name = (string)$goodsRow['product'];
                }
            }
            $costRaw = trim((string)($lineCosts[$key] ?? '0'));
            if ($costRaw === '') {
                $costRaw = '0';
            }
            $costCheck = psValidateProductMoney($costRaw, 'Line cost');
            if (!$costCheck['ok']) {
                return ['ok' => false, 'error' => $costCheck['error'] ?? 'Line cost is not valid.'];
            }
            $lines[] = [
                'product_id' => $productId,
                'product' => $name,
                'quantity' => psStockQtyPlain($qty),
                'quantity_decimal' => $qty,
                'vat' => trim((string)($vats[$key] ?? '0')),
                'line_cost' => $costCheck['value'],
                'purchase_order_line_id' => null,
            ];
        }
        if (count($lines) === 0) {
            return ['ok' => false, 'error' => 'Add at least one product.'];
        }
    }

    // Multi-currency (2026-10-20): costs are in the receipt currency — the PO's currency when receiving against a PO,
    // otherwise the posted one (blank = company base). The rate to the base is taken when the goods are received.
    require_once __DIR__ . '/currency.php';
    if (!psFxEnsureTransactionColumns($conn)) {
        return ['ok' => false, 'error' => 'The goods receipt could not be saved.'];
    }
    $fxCompany = psFxSessionCompany();
    $grvCurRaw = $poMode ? (string)($poHeader['currency'] ?? '') : (string)($receipt['currency'] ?? '');
    $grvCurCheck = psFxCheckTransactionCurrency($conn, $fxCompany, $grvCurRaw);
    if (empty($grvCurCheck['ok'])) {
        return ['ok' => false, 'error' => (string)$grvCurCheck['error']];
    }
    $grvSnap = psFxSnapshot($conn, $fxCompany, (string)$grvCurCheck['code'], $now);
    if (empty($grvSnap['ok'])) {
        return ['ok' => false, 'error' => (string)$grvSnap['error']];
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('goods receipt transaction did not start');
        return ['ok' => false, 'error' => 'The goods receipt could not be saved.'];
    }

    $lineStmt = null;
    $refStmt = null;
    try {
        $alloc = psAllocateGoodsReceiptNumber($conn);
        if (!$alloc['ok']) {
            throw new RuntimeException($alloc['error'] ?? 'Goods receipt numbering is not available.');
        }
        $grvNumber = $alloc['grv_number'];

        // Re-check remaining inside the transaction for PO-linked (race safety).
        if ($poMode) {
            $freshLines = psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, $purchaseOrderId));
            $freshById = [];
            foreach ($freshLines as $poLine) {
                $freshById[(int)$poLine['id']] = $poLine;
            }
            foreach ($lines as $line) {
                $poLineId = (int)$line['purchase_order_line_id'];
                if (!isset($freshById[$poLineId])) {
                    throw new RuntimeException('That purchase order line does not belong to this purchase order.');
                }
                if (psStockDecimalCmp($line['quantity_decimal'], (string)$freshById[$poLineId]['remaining_qty']) > 0) {
                    throw new RuntimeException('Received quantity cannot exceed remaining ordered quantity.');
                }
            }
            $locked = psPurchaseOrderById($conn, $purchaseOrderId);
            if ($locked === null || !psPoCanReceive((string)$locked['status'])) {
                throw new RuntimeException('Only approved purchase orders with remaining quantity can be received.');
            }
        }

        $lineStmt = mysqli_prepare(
            $conn,
            'INSERT INTO goods_receipts
             (product_id, product, quantity, date_, invoice_number, supplier_name, status, vat, line_cost, branch,
              grv_number, purchase_order_id, purchase_order_line_id, supplier_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?)'
        );
        if (!$lineStmt) {
            throw new RuntimeException('line');
        }
        $total = '0.00';
        $totalVat = 0.0;
        $movementIds = [];
        $movementAt = $now;
        $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
        if ($parsedDate && $parsedDate->format('Y-m-d') === $date) {
            $movementAt = $date . ' ' . date('H:i:s');
        }
        $poIdText = $poMode ? (string)$purchaseOrderId : '';
        $supplierIdText = ($supplierId !== null && $supplierId > 0) ? (string)$supplierId : '';

        foreach ($lines as $line) {
            $productId = $line['product_id'];
            $product = $line['product'];
            $quantity = $line['quantity'];
            $vat = $line['vat'] === '' ? '0' : $line['vat'];
            $lineCost = $line['line_cost'] === '' ? '0' : $line['line_cost'];
            $poLineText = $line['purchase_order_line_id'] !== null
                ? (string)(int)$line['purchase_order_line_id']
                : '';
            mysqli_stmt_bind_param(
                $lineStmt,
                'sssssssssssssss',
                $productId,
                $product,
                $quantity,
                $date,
                $invoice,
                $supplier,
                $status,
                $vat,
                $lineCost,
                $branch,
                $grvNumber,
                $poIdText,
                $poLineText,
                $supplierIdText,
                $changedBy
            );
            if (!mysqli_stmt_execute($lineStmt)) {
                throw new RuntimeException('line');
            }
            if (function_exists('bcadd')) {
                $total = bcadd($total, psPoComputeLineTotal($quantity, $lineCost), 2);
            } else {
                $total = number_format(((float)$total) + ((float)$lineCost * (float)$quantity), 2, '.', '');
            }
            $totalVat += (float)$vat;
            $refForMovement = $grvNumber;
            $recorded = recordStockMovement($conn, [
                'product_id' => $productId,
                'branch' => $branch,
                'movement_type' => 'GOODS_RECEIPT',
                'quantity' => $line['quantity_decimal'],
                'reference_type' => 'goods_receipt',
                'reference_id' => $refForMovement,
                'movement_at' => $movementAt,
                'notes' => $poMode ? ('PO ' . $poNumber) : '',
            ]);
            if (!$recorded['ok']) {
                throw new RuntimeException($recorded['error']);
            }
            $movementIds[] = $recorded['id'];
        }
        mysqli_stmt_close($lineStmt);
        $lineStmt = null;

        $totalText = is_string($total) ? $total : (string)$total;
        $vatText = (string)$totalVat;
        $refStmt = mysqli_prepare(
            $conn,
            'INSERT INTO goods_receipts_ref
             (product, reference1, status, date_, payment_mode, totalz, vat, branch, sending_branch,
              grv_number, purchase_order_id, supplier_id, created_by)
             VALUES (?, ?, ?, ?, \'USD\', ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?)'
        );
        if (!$refStmt) {
            throw new RuntimeException('header');
        }
        mysqli_stmt_bind_param(
            $refStmt,
            'ssssssssssss',
            $supplier,
            $invoice,
            $status,
            $date,
            $totalText,
            $vatText,
            $branch,
            $branch,
            $grvNumber,
            $poIdText,
            $supplierIdText,
            $changedBy
        );
        if (!mysqli_stmt_execute($refStmt)) {
            mysqli_stmt_close($refStmt);
            throw new RuntimeException('header');
        }
        $refId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($refStmt);
        $refStmt = null;
        if (psFxHasColumn($conn, 'goods_receipts_ref', 'currency')) {
            $v = psFxSnapshotSqlValues($conn, $grvSnap);
            $grvBase = psFxCentsToMoney(psFxToBaseCents($conn, $grvSnap, (int)psFxMoneyToCents($totalText)));
            if (!mysqli_query($conn, 'UPDATE goods_receipts_ref SET payment_mode = ' . $v['currency'] . ', currency = ' . $v['currency']
                . ', base_currency = ' . $v['base_currency'] . ', fx_rate = ' . $v['fx_rate'] . ', fx_rate_ids = ' . $v['fx_rate_ids']
                . ', fx_path = ' . $v['fx_path'] . ", base_total = '" . $grvBase . "' WHERE id = " . $refId)) {
                throw new RuntimeException('header');
            }
        }

        $lineSummary = [];
        foreach ($lines as $line) {
            $tag = $line['purchase_order_line_id'] !== null
                ? 'pol:' . (int)$line['purchase_order_line_id']
                : $line['product_id'];
            $lineSummary[] = $tag . ':' . $line['quantity'] . '@' . $line['line_cost'];
        }
        $auditChanges = [
            ['field_name' => 'grv_number', 'old_value' => null, 'new_value' => $grvNumber],
            ['field_name' => 'supplier_invoice', 'old_value' => null, 'new_value' => $invoice],
            ['field_name' => 'supplier_name', 'old_value' => null, 'new_value' => $supplier],
            ['field_name' => 'branch', 'old_value' => null, 'new_value' => $branch],
            ['field_name' => 'date', 'old_value' => null, 'new_value' => $date],
            ['field_name' => 'total', 'old_value' => null, 'new_value' => $totalText],
            ['field_name' => 'currency', 'old_value' => null, 'new_value' => $grvSnap['currency'] . ($grvSnap['currency'] !== $grvSnap['base'] ? ' @ ' . psFxRateLabel($grvSnap['base'], $grvSnap['currency'], $grvSnap['rate']) : '')],
            ['field_name' => 'lines', 'old_value' => null, 'new_value' => implode('|', $lineSummary)],
        ];
        if ($poMode) {
            $auditChanges[] = ['field_name' => 'purchase_order_id', 'old_value' => null, 'new_value' => (string)$purchaseOrderId];
            $auditChanges[] = ['field_name' => 'po_number', 'old_value' => null, 'new_value' => $poNumber];
            $auditChanges[] = ['field_name' => 'supplier_id', 'old_value' => null, 'new_value' => (string)$supplierId];
        }
        if (!psGrvAuditRecordChanges($conn, [
            'goods_receipt_ref_id' => $refId,
            'grv_number' => $grvNumber,
            'purchase_order_id' => $poMode ? $purchaseOrderId : null,
            'po_number' => $poNumber,
            'branch' => $branch,
            'action' => $poMode ? PS_GRV_AUDIT_RECEIVE : PS_GRV_AUDIT_CREATE_RECEIPT,
            'changed_by' => $changedBy,
            'source' => PS_GRV_AUDIT_SOURCE_UI,
            'changed_at' => $now,
        ], $auditChanges)) {
            throw new RuntimeException('The goods receipt could not be saved.');
        }

        $poClosed = false;
        if ($poMode) {
            $close = psClosePurchaseOrderIfFullyReceived($conn, $purchaseOrderId, $changedBy, $now, $grvNumber);
            if (!$close['ok']) {
                throw new RuntimeException($close['error'] ?? 'The purchase order could not be closed.');
            }
            $poClosed = !empty($close['closed']);
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('commit');
        }
        return [
            'ok' => true,
            'currency' => $grvSnap['currency'],
            'invoice_number' => $invoice,
            'grv_number' => $grvNumber,
            'movement_ids' => $movementIds,
            'po_closed' => $poClosed,
        ];
    } catch (Throwable $e) {
        if ($lineStmt instanceof mysqli_stmt) {
            mysqli_stmt_close($lineStmt);
        }
        if ($refStmt instanceof mysqli_stmt) {
            mysqli_stmt_close($refStmt);
        }
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('goods receipt save failed');
        $message = $e->getMessage();
        $safe = [
            'Product was not found.',
            'Quantity must be a number.',
            'Quantity must not be zero.',
            'A goods receipt quantity must be greater than zero.',
            'Branch is required.',
            'Branch was not found.',
            'The stock movement could not be saved.',
            'Received quantity cannot exceed remaining ordered quantity.',
            'Only approved purchase orders with remaining quantity can be received.',
            'That purchase order line does not belong to this purchase order.',
            'Purchase order line branch does not match.',
            'Each purchase order line can appear only once on a receipt.',
            'Receive against a purchase order line.',
            'Add at least one receive quantity.',
            'Goods receipt numbering is not available.',
            'You must be signed in to receive goods.',
            'The purchase order could not be closed.',
        ];
        if (!in_array($message, $safe, true)) {
            $message = 'The goods receipt could not be saved.';
        }
        return ['ok' => false, 'error' => $message];
    }
}

/**
 * movements_receipts and its header table were MyISAM, so a ledger failure could not undo them.
 * InnoDB lets the transfer rows and stock_movements share one commit or rollback. Rows are kept.
 */
function psEnsureTransferTablesTransactional(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (stockMovementInTransaction($conn)) {
        error_log('transfer tables were not checked');
        return false;
    }
    foreach (['movements_receipts', 'movements_receipts_ref'] as $table) {
        $safe = $table === 'movements_receipts' ? 'movements_receipts' : 'movements_receipts_ref';
        $status = mysqli_query($conn, "SHOW TABLE STATUS LIKE '" . $safe . "'");
        $row = $status ? mysqli_fetch_assoc($status) : null;
        if (!$row) {
            error_log('transfer tables were not checked');
            return false;
        }
        if (strtolower((string)$row['Engine']) === 'innodb') {
            continue;
        }
        if (!mysqli_query($conn, 'ALTER TABLE `' . $safe . '` ENGINE=InnoDB')) {
            error_log('transfer tables were not updated');
            return false;
        }
    }
    return true;
}

/**
 * Save one stock transfer the way stock_movement.php confirms it: one step, not a later receive.
 * Stock leaves the sending branch and arrives at the receiving branch in the same transaction.
 * Does not change goods.quantity and does not create an opening balance.
 * A posted product id that exists on goods is stored and used for the ledger.
 * A name-only line is used only when that exact name maps to one product id.
 * More than one product id for the same name is rejected. A posted id is never replaced by a name search.
 * recordStockMovement() does not commit or roll back.
 */
function psSaveStockTransfer(mysqli $conn, array $transfer, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!stockMovementEnsureSchema($conn) || !psEnsureTransferTablesTransactional($conn)) {
        return ['ok' => false, 'error' => 'The stock transfer could not be saved.'];
    }

    $sending = trim((string)($transfer['sending_branch'] ?? ''));
    $receiving = trim((string)($transfer['receiving_branch'] ?? ''));
    $date = trim((string)($transfer['date'] ?? ''));
    $invoice = trim((string)($transfer['invoice_number'] ?? ''));
    $supplier = trim((string)($transfer['supplier_name'] ?? ''));
    $status = trim((string)($transfer['status'] ?? ''));
    $products = $transfer['products'] ?? [];
    $productIds = $transfer['product_ids'] ?? [];
    $quantities = $transfer['quantities'] ?? [];
    $vats = $transfer['vats'] ?? [];
    $lineCosts = $transfer['line_costs'] ?? [];

    if ($sending === '' || $receiving === '') {
        return ['ok' => false, 'error' => 'Sending and receiving branches are required.'];
    }
    if ($sending === $receiving) {
        return ['ok' => false, 'error' => 'Sending branch and receiving branch must be different.'];
    }
    if (!stockMovementBranchExists($conn, $sending) || !stockMovementBranchExists($conn, $receiving)) {
        return ['ok' => false, 'error' => 'Branch was not found.'];
    }
    // Phase 9.6: sending branch must be the caller's authorised registered working set.
    // Receiving may be any registered branchies name (transfer destination).
    require_once __DIR__ . '/branch_access.php';
    $allowedSend = psSessionStockBranches($conn);
    if ($allowedSend === [] || !isset($allowedSend[$sending])) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    if ($date === '' || $invoice === '' || $supplier === '' || $status === '') {
        return ['ok' => false, 'error' => 'Date, invoice number, supplier, and status are required.'];
    }
    if (strlen($invoice) > 250) {
        return ['ok' => false, 'error' => 'That reference is too long.'];
    }
    if (!is_array($products) || count($products) === 0) {
        return ['ok' => false, 'error' => 'No products submitted.'];
    }

    $lines = [];
    foreach ($products as $key => $productName) {
        $name = trim((string)$productName);
        $postedId = trim((string)($productIds[$key] ?? ''));
        $qtyRaw = trim((string)($quantities[$key] ?? ''));
        if ($name === '' && $postedId === '' && $qtyRaw === '') {
            continue;
        }
        if ($postedId === '') {
            return ['ok' => false, 'error' => 'Select a product from the list.'];
        }
        $productId = stockMovementResolveProductOnBranch($conn, $postedId, $sending);
        if ($productId === null) {
            return ['ok' => false, 'error' => 'Product was not found on the sending branch.'];
        }
        $qty = psStockDecimal($qtyRaw);
        if ($qty === null) {
            return ['ok' => false, 'error' => 'Quantity must be a number.'];
        }
        if (psStockDecimalCmp($qty, '0') <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
        }
        if ($name === '') {
            require_once __DIR__ . '/product_master.php';
            $goodsRow = psGoodsByProductIdBranch($conn, $productId, $sending);
            if ($goodsRow) {
                $name = (string)$goodsRow['product'];
            }
        }
        $milli = psStockMilli($qty);
        if ($milli === null || $milli <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be a number.'];
        }
        $lines[] = [
            'product_id' => $productId,
            'product' => $name,
            'quantity' => psStockQtyPlain($qty),
            'quantity_decimal' => $qty,
            'milli' => $milli,
            'vat' => trim((string)($vats[$key] ?? '0')),
            'line_cost' => trim((string)($lineCosts[$key] ?? '0')),
        ];
    }
    if (count($lines) === 0) {
        return ['ok' => false, 'error' => 'No products submitted.'];
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('stock transfer transaction did not start');
        return ['ok' => false, 'error' => 'The stock transfer could not be saved.'];
    }

    $lockName = 'psxf:' . sha1($invoice . "\n" . $sending . "\n" . $receiving);
    $locked = false;
    $lineStmt = null;
    try {
        $lockStmt = mysqli_prepare($conn, 'SELECT GET_LOCK(?, 10) AS got');
        if (!$lockStmt) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        mysqli_stmt_bind_param($lockStmt, 's', $lockName);
        if (!mysqli_stmt_execute($lockStmt)) {
            mysqli_stmt_close($lockStmt);
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        $lockResult = mysqli_stmt_get_result($lockStmt);
        $lockRow = $lockResult ? mysqli_fetch_assoc($lockResult) : null;
        mysqli_stmt_close($lockStmt);
        if (!$lockRow || (string)$lockRow['got'] !== '1') {
            throw new RuntimeException('This transfer is already being saved.');
        }
        $locked = true;

        $refLock = mysqli_prepare(
            $conn,
            'SELECT id FROM movements_receipts_ref WHERE reference1 = ? AND sending_branch = ? AND branch = ? FOR UPDATE'
        );
        if (!$refLock) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        mysqli_stmt_bind_param($refLock, 'sss', $invoice, $sending, $receiving);
        $refOk = mysqli_stmt_execute($refLock);
        $refRows = $refOk ? mysqli_stmt_get_result($refLock) : false;
        $refHit = $refRows && mysqli_fetch_assoc($refRows);
        mysqli_stmt_close($refLock);
        if (!$refOk) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        $lineLock = mysqli_prepare(
            $conn,
            'SELECT id FROM movements_receipts WHERE invoice_number = ? AND sending_branch = ? AND receiving_branch = ? FOR UPDATE'
        );
        if (!$lineLock) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        mysqli_stmt_bind_param($lineLock, 'sss', $invoice, $sending, $receiving);
        $lineOk = mysqli_stmt_execute($lineLock);
        $lineRows = $lineOk ? mysqli_stmt_get_result($lineLock) : false;
        $lineHit = $lineRows && mysqli_fetch_assoc($lineRows);
        mysqli_stmt_close($lineLock);
        if (!$lineOk) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        if ($refHit || $lineHit) {
            throw new RuntimeException('This transfer was already received.');
        }

        $requested = [];
        $names = [];
        foreach ($lines as $line) {
            $productId = $line['product_id'];
            if (!isset($requested[$productId])) {
                $requested[$productId] = 0;
                $names[$productId] = $line['product'] !== '' ? $line['product'] : $productId;
            }
            $requested[$productId] += (int)$line['milli'];
        }
        $productOrder = array_keys($requested);
        sort($productOrder, SORT_STRING);
        $failures = [];
        foreach ($productOrder as $productId) {
            $ledger = psSaleLedgerBalance($conn, $productId, $sending);
            if (empty($ledger['ok'])) {
                throw new RuntimeException('The stock transfer could not be saved.');
            }
            if (empty($ledger['tracked'])) {
                continue;
            }
            $need = (int)$requested[$productId];
            $available = (int)$ledger['milli'];
            if ($need > $available) {
                $failures[] = 'Insufficient stock for ' . $names[$productId]
                    . ' at ' . $sending
                    . '. Requested: ' . psStockQtyPlain(psMilliToStock($need))
                    . '. Available: ' . psStockQtyPlain(psMilliToStock($available)) . '.';
            }
        }
        if ($failures) {
            throw new RuntimeException(implode(' ', $failures));
        }

        $lineStmt = mysqli_prepare(
            $conn,
            'INSERT INTO movements_receipts
                (product_id, product, quantity, date_, invoice_number, supplier_name, status, vat, line_cost, branch, sending_branch, receiving_branch)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$lineStmt) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        $total = 0.0;
        $totalVat = 0.0;
        foreach ($lines as $line) {
            $productId = $line['product_id'];
            $product = $line['product'];
            $quantity = $line['quantity'];
            $vat = $line['vat'] === '' ? '0' : $line['vat'];
            $lineCost = $line['line_cost'] === '' ? '0' : $line['line_cost'];
            mysqli_stmt_bind_param(
                $lineStmt,
                'ssssssssssss',
                $productId,
                $product,
                $quantity,
                $date,
                $invoice,
                $supplier,
                $status,
                $vat,
                $lineCost,
                $receiving,
                $sending,
                $receiving
            );
            if (!mysqli_stmt_execute($lineStmt)) {
                throw new RuntimeException('The stock transfer could not be saved.');
            }
            $total += (float)$lineCost * (float)$quantity;
            $totalVat += (float)$vat;
        }
        mysqli_stmt_close($lineStmt);
        $lineStmt = null;

        $totalText = (string)$total;
        $vatText = (string)$totalVat;
        $refStmt = mysqli_prepare(
            $conn,
            'INSERT INTO movements_receipts_ref (product, reference1, status, date_, payment_mode, totalz, vat, branch, sending_branch)
             VALUES (?, ?, ?, ?, \'USD\', ?, ?, ?, ?)'
        );
        if (!$refStmt) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        mysqli_stmt_bind_param($refStmt, 'ssssssss', $supplier, $invoice, $status, $date, $totalText, $vatText, $receiving, $sending);
        if (!mysqli_stmt_execute($refStmt)) {
            mysqli_stmt_close($refStmt);
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        mysqli_stmt_close($refStmt);

        $movementAt = date('Y-m-d H:i:s');
        $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
        if ($parsedDate && $parsedDate->format('Y-m-d') === $date) {
            $movementAt = $date . ' ' . date('H:i:s');
        }
        $movementIds = [];
        foreach ($lines as $line) {
            $outQty = '-' . $line['quantity_decimal'];
            $recordedOut = recordStockMovement($conn, [
                'product_id' => $line['product_id'],
                'branch' => $sending,
                'movement_type' => 'TRANSFER_OUT',
                'quantity' => $outQty,
                'reference_type' => 'TRANSFER',
                'reference_id' => $invoice,
                'movement_at' => $movementAt,
                'notes' => '',
            ]);
            if (empty($recordedOut['ok'])) {
                throw new RuntimeException((string)($recordedOut['error'] ?? 'The stock movement could not be saved.'));
            }
            $recordedIn = recordStockMovement($conn, [
                'product_id' => $line['product_id'],
                'branch' => $receiving,
                'movement_type' => 'TRANSFER_IN',
                'quantity' => $line['quantity_decimal'],
                'reference_type' => 'TRANSFER',
                'reference_id' => $invoice,
                'movement_at' => $movementAt,
                'notes' => '',
            ]);
            if (empty($recordedIn['ok'])) {
                throw new RuntimeException((string)($recordedIn['error'] ?? 'The stock movement could not be saved.'));
            }
            $movementIds[] = $recordedOut['id'];
            $movementIds[] = $recordedIn['id'];
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The stock transfer could not be saved.');
        }
        if ($locked) {
            $release = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
            if ($release) {
                mysqli_stmt_bind_param($release, 's', $lockName);
                mysqli_stmt_execute($release);
                mysqli_stmt_close($release);
            }
            $locked = false;
        }
        return ['ok' => true, 'invoice_number' => $invoice, 'movement_ids' => $movementIds];
    } catch (Throwable $e) {
        if ($lineStmt instanceof mysqli_stmt) {
            mysqli_stmt_close($lineStmt);
        }
        if ($owns) {
            mysqli_rollback($conn);
        }
        if ($locked) {
            $release = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
            if ($release) {
                mysqli_stmt_bind_param($release, 's', $lockName);
                mysqli_stmt_execute($release);
                mysqli_stmt_close($release);
            }
        }
        error_log('stock transfer save failed');
        $message = $e->getMessage();
        $safe = [
            'The stock transfer could not be saved.',
            'The stock movement could not be saved.',
            'This transfer was already received.',
            'This transfer is already being saved.',
            'Product was not found.',
            'That product name matches more than one item.',
            'Quantity must be a number.',
            'Quantity must be greater than zero.',
            'Quantity must not be zero.',
            'That movement quantity must be less than zero.',
            'That movement quantity must be greater than zero.',
            'Branch is required.',
            'Branch was not found.',
            'Sending and receiving branches are required.',
            'Sending branch and receiving branch must be different.',
            'Date is required.',
            'Date, invoice number, supplier, and status are required.',
            'No products submitted.',
            'That reference is too long.',
            'Stock movements are not available.',
        ];
        $insufficient = strncmp($message, 'Insufficient stock for ', 23) === 0;
        if (!$insufficient && !in_array($message, $safe, true)) {
            $message = 'The stock transfer could not be saved.';
        }
        if (stripos($message, 'select ') !== false || stripos($message, 'insert ') !== false) {
            $message = 'The stock transfer could not be saved.';
        }
        return ['ok' => false, 'error' => $message];
    }
}

function psOpeningDuplicateMessage(string $quantity): string
{
    return 'An opening balance already exists for this product at this branch. Existing quantity: '
        . psStockQtyPlain($quantity) . '.';
}

function psOpeningQuantity(mysqli $conn, string $productId, string $branch, bool $lock = false): ?string
{
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || $branch === '') {
        return null;
    }
    $sql = 'SELECT quantity FROM stock_movements WHERE product_id = ? AND branch = ? AND movement_type = \'OPENING_BALANCE\' LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('opening balance lookup failed');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('opening balance lookup failed');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }
    $quantity = psStockDecimal($row['quantity'] ?? '');
    return $quantity === null ? '0.000' : $quantity;
}

function psProductHasOpeningBalance(mysqli $conn, string $productId, string $branch): bool
{
    return psOpeningQuantity($conn, $productId, $branch, false) !== null;
}

/**
 * A product at a branch is ledger-controlled only when an OPENING_BALANCE row exists.
 * SALE, REFUND, TRANSFER_IN, TRANSFER_OUT, GOODS_RECEIPT, PURCHASE_RETURN, or ADJUSTMENT alone do not control it.
 */
function isStockControlled(mysqli $conn, $productId, $branch): bool
{
    return psProductHasOpeningBalance($conn, trim((string)$productId), trim((string)$branch));
}

/**
 * Stock the stock list already shows for one product name at one branch.
 * goods_receipts.quantity in, online_sales.quantity out, movements_receipts in on
 * receiving_branch and out on sending_branch. stock_movements and goods.quantity
 * are not included. Stock-take rows already sit in goods_receipts or online_sales.
 */
function psLegacyStockQuantity(mysqli $conn, string $productName, string $branch): ?string
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $productName = (string)$productName;
    $branch = trim($branch);
    if ($productName === '' || $branch === '') {
        return null;
    }
    $sql = 'SELECT (
            COALESCE((SELECT SUM(quantity) FROM goods_receipts WHERE product = ? AND branch = ?), 0)
          + COALESCE((SELECT SUM(quantity) FROM movements_receipts WHERE product = ? AND receiving_branch = ?), 0)
          - COALESCE((SELECT SUM(quantity) FROM online_sales WHERE product = ? AND branch = ?), 0)
          - COALESCE((SELECT SUM(quantity) FROM movements_receipts WHERE product = ? AND sending_branch = ?), 0)
        ) AS legacy';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('legacy stock was not calculated');
        return null;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssss',
        $productName,
        $branch,
        $productName,
        $branch,
        $productName,
        $branch,
        $productName,
        $branch
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('legacy stock was not calculated');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row || !array_key_exists('legacy', $row) || $row['legacy'] === null) {
        return null;
    }
    return psStockDecimal($row['legacy']);
}

function psEnsureStockListProductColumn(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $col = mysqli_query($conn, "SHOW COLUMNS FROM stock_list33 LIKE 'product_id'");
    if ($col && mysqli_num_rows($col) > 0) {
        return true;
    }
    $added = mysqli_query($conn, 'ALTER TABLE stock_list33 ADD COLUMN product_id VARCHAR(250) NULL DEFAULT NULL AFTER product');
    if ($added || mysqli_errno($conn) === 1060) {
        return true;
    }
    error_log('stock list product column was not added');
    return false;
}

/**
 * Health-check 2026-10-13: stock_list33 was one shared cache for every branch (TRUNCATE +
 * refill by whoever ran it last). Add a branch column so each branch keeps its own rows.
 */
function psEnsureStockListBranchColumn(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $col = mysqli_query($conn, "SHOW COLUMNS FROM stock_list33 LIKE 'branch'");
    if ($col && mysqli_num_rows($col) > 0) {
        $ready = true;
        return true;
    }
    $added = mysqli_query($conn, 'ALTER TABLE stock_list33 ADD COLUMN branch VARCHAR(250) NULL DEFAULT NULL, ADD KEY idx_stock_list33_branch (branch)');
    if ($added || mysqli_errno($conn) === 1060) {
        $ready = true;
        return true;
    }
    error_log('stock list branch column was not added');
    return false;
}

/**
 * Rows for the stock list at one branch.
 * One cache row per goods catalogue row. product_id is goods.product_id, the same
 * code the ledger stores (1034, 1063). goods.id is a different key and is never used.
 * An empty product code stays blank so the save writes NULL. Duplicate names stay
 * on separate rows and are never chosen with ORDER BY id LIMIT 1.
 * Controlled product ids use the ledger sum for this branch only. Unmigrated goods
 * rows keep the name-based legacy quantity, including the same name-total on each
 * row that shares a name, and are not given a ledger zero.
 * usd_value is that quantity times a unit selling price: the goods row's own price
 * when the product is controlled, otherwise the existing first price recorded for
 * that name. This does not insert movements.
 *
 * @return array<int,array{product:string,product_id:string,qty:string,usd_value:string}>|null
 */
function psStockListLines(mysqli $conn, string $branch): ?array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '') {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT product_id, product, price FROM goods WHERE branch = ? AND product <> \'\' ORDER BY product, product_id, id'
    );
    if (!$stmt) {
        error_log('stock list was not calculated');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock list was not calculated');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        error_log('stock list was not calculated');
        return null;
    }
    $goods = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $goods[] = $row;
    }
    mysqli_stmt_close($stmt);

    $ledger = psControlledLedgerBalances($conn, $branch);
    $legacy = [];
    $priceResult = mysqli_query($conn, 'SELECT product, price FROM goods ORDER BY id');
    if (!$priceResult) {
        error_log('stock list was not calculated');
        return null;
    }
    $priceCache = [];
    while ($priceRow = mysqli_fetch_assoc($priceResult)) {
        $priceName = (string)($priceRow['product'] ?? '');
        if ($priceName !== '' && !array_key_exists($priceName, $priceCache)) {
            $priceCache[$priceName] = $priceRow['price'];
        }
    }
    $lines = [];
    foreach ($goods as $row) {
        $productId = trim((string)($row['product_id'] ?? ''));
        $name = (string)$row['product'];
        if ($name === '') {
            continue;
        }
        $controlled = $productId !== '' && array_key_exists($productId, $ledger);
        if ($controlled) {
            $qty = $ledger[$productId];
            $price = $row['price'];
        } else {
            if (!array_key_exists($name, $legacy)) {
                $single = psLegacyStockQuantity($conn, $name, $branch);
                if ($single === null) {
                    error_log('stock list was not calculated');
                    return null;
                }
                $legacy[$name] = $single;
            }
            $qty = psStockQtyPlain($legacy[$name]);
            $price = $priceCache[$name] ?? 0;
        }
        $qtyNumber = (float)$qty;
        $priceNumber = is_numeric($price) ? (float)$price : 0.0;
        $lines[] = [
            'product' => $name,
            'product_id' => $productId,
            'qty' => $qty,
            'usd_value' => number_format($qtyNumber * $priceNumber, 2, '.', ''),
        ];
    }
    return $lines;
}

/**
 * Replace stock_list33 with one branch's catalogue.
 * The table has no branch column. It keeps the latest branch passed in, which
 * run_stocklist.php takes from the selected branch. Controlled quantities on
 * screen still come from product_id plus the branch being viewed.
 * Rebuild only reads goods and the ledger, then replaces this cache. It does not
 * insert movements, receipts, sales, transfers, or openings.
 */
function psSaveStockListCache(mysqli $conn, string $branch): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '') {
        return false;
    }
    if (!psEnsureStockListProductColumn($conn) || !psEnsureStockListBranchColumn($conn)) {
        return false;
    }
    $lines = psStockListLines($conn, $branch);
    if ($lines === null) {
        return false;
    }
    // Replace only this branch's rows (and legacy untagged rows from the old shared cache).
    if (!mysqli_begin_transaction($conn)) {
        error_log('stock list was not saved');
        return false;
    }
    $del = mysqli_prepare($conn, 'DELETE FROM stock_list33 WHERE branch = ? OR branch IS NULL');
    if (!$del) {
        mysqli_rollback($conn);
        error_log('stock list was not saved');
        return false;
    }
    mysqli_stmt_bind_param($del, 's', $branch);
    if (!mysqli_stmt_execute($del)) {
        mysqli_stmt_close($del);
        mysqli_rollback($conn);
        error_log('stock list was not saved');
        return false;
    }
    mysqli_stmt_close($del);
    if (!$lines) {
        return mysqli_commit($conn);
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO stock_list33 (product, product_id, qty, usd_value, branch) VALUES (?, NULLIF(?, \'\'), ?, ?, ?)'
    );
    if (!$stmt) {
        mysqli_rollback($conn);
        error_log('stock list was not saved');
        return false;
    }
    foreach ($lines as $line) {
        $product = $line['product'];
        $productId = $line['product_id'];
        $qty = $line['qty'];
        $value = $line['usd_value'];
        mysqli_stmt_bind_param($stmt, 'sssss', $product, $productId, $qty, $value, $branch);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_rollback($conn);
            error_log('stock list was not saved');
            return false;
        }
    }
    mysqli_stmt_close($stmt);
    return mysqli_commit($conn);
}

function psGoodsForOpening(mysqli $conn, string $productId, string $branch, string $company): ?array
{
    $productId = trim($productId);
    $branch = trim($branch);
    $company = trim($company);
    if ($productId === '' || $branch === '') {
        return null;
    }
    if ($company !== '') {
        $sql = 'SELECT product_id, product, branch, company_name FROM goods WHERE product_id = ? AND branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = \'\') LIMIT 1';
    } else {
        $sql = 'SELECT product_id, product, branch, company_name FROM goods WHERE product_id = ? AND branch = ? LIMIT 1';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('opening product lookup failed');
        return null;
    }
    if ($company !== '') {
        mysqli_stmt_bind_param($stmt, 'sss', $productId, $branch, $company);
    } else {
        mysqli_stmt_bind_param($stmt, 'ss', $productId, $branch);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('opening product lookup failed');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function psOpeningReferenceId(): string
{
    return 'OB' . bin2hex(random_bytes(8));
}

function psOpeningNotes(string $calculated, string $entered, string $reason): array
{
    $calculatedDecimal = psStockDecimal($calculated);
    $enteredDecimal = psStockDecimal($entered);
    if ($calculatedDecimal === null || $enteredDecimal === null) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $reason = trim($reason);
    if (strlen($reason) > 300) {
        return ['ok' => false, 'error' => 'The reason is too long.'];
    }
    $same = psStockDecimalCmp($calculatedDecimal, $enteredDecimal) === 0;
    if (!$same && $reason === '') {
        return ['ok' => false, 'error' => 'Enter a reason when the opening quantity is not the calculated stock.'];
    }
    $calculatedPlain = psStockQtyPlain($calculatedDecimal);
    $enteredPlain = psStockQtyPlain($enteredDecimal);
    if ($same) {
        $notes = 'Opening balance set to calculated stock ' . $calculatedPlain . '.';
        if ($reason !== '') {
            $notes .= ' ' . $reason;
        }
    } else {
        $notes = 'Calculated stock ' . $calculatedPlain . '. Entered ' . $enteredPlain . '. Reason: ' . $reason;
    }
    if (strlen($notes) > 500) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }
    return ['ok' => true, 'notes' => $notes, 'same' => $same];
}

/**
 * Insert one opening balance. Does not commit or roll back.
 */
function psRecordOpeningBalance(mysqli $conn, array $opening): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The opening balance could not be saved.'];
    }
    $productId = trim((string)($opening['product_id'] ?? ''));
    $branch = trim((string)($opening['branch'] ?? ''));
    $company = trim((string)($opening['company'] ?? ''));
    if ($productId === '' || $branch === '') {
        return ['ok' => false, 'error' => 'Product and branch are required.'];
    }
    if (!stockMovementBranchExists($conn, $branch)) {
        return ['ok' => false, 'error' => 'Branch was not found.'];
    }
    $goods = psGoodsForOpening($conn, $productId, $branch, $company);
    if (!$goods) {
        return ['ok' => false, 'error' => 'That product is not on this branch.'];
    }
    if (!array_key_exists('quantity', $opening) || trim((string)$opening['quantity']) === '') {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $entered = psStockDecimal($opening['quantity']);
    if ($entered === null) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    if (psStockDecimalCmp($entered, '0') < 0) {
        return ['ok' => false, 'error' => 'An opening quantity cannot be negative.'];
    }
    $legacy = psLegacyStockQuantity($conn, (string)$goods['product'], $branch);
    if ($legacy === null) {
        return ['ok' => false, 'error' => 'Calculated stock could not be read.'];
    }
    $notes = psOpeningNotes($legacy, $entered, (string)($opening['reason'] ?? ''));
    if (empty($notes['ok'])) {
        return ['ok' => false, 'error' => (string)$notes['error']];
    }
    $referenceId = trim((string)($opening['reference_id'] ?? ''));
    if ($referenceId === '') {
        $referenceId = psOpeningReferenceId();
    }
    if (strlen($referenceId) > 250) {
        return ['ok' => false, 'error' => 'That reference is too long.'];
    }
    $priorSum = getStockBalance($conn, (string)$goods['product_id'], $branch);
    $recorded = recordStockMovement($conn, [
        'product_id' => (string)$goods['product_id'],
        'branch' => $branch,
        'movement_type' => 'OPENING_BALANCE',
        'quantity' => $entered,
        'reference_type' => 'OPENING_BALANCE',
        'reference_id' => $referenceId,
        'movement_at' => date('Y-m-d H:i:s'),
        'user_id' => $opening['user_id'] ?? null,
        'user_email' => (string)($opening['user_email'] ?? ''),
        'notes' => $notes['notes'],
    ]);
    if (empty($recorded['ok'])) {
        return $recorded;
    }
    $net = psStockCancelPriorMovements($conn, (string)$goods['product_id'], $branch, $priorSum, $opening['user_id'] ?? null, (string)($opening['user_email'] ?? ''));
    if (empty($net['ok'])) {
        return ['ok' => false, 'error' => 'The opening balance could not be saved.'];
    }
    return [
        'ok' => true,
        'id' => $recorded['id'],
        'quantity' => $entered,
        'legacy' => $legacy,
        'notes' => $notes['notes'],
        'reference_id' => $referenceId,
        'product' => (string)$goods['product'],
    ];
}

/**
 * Save a confirmed batch. When $manageTransaction is false the caller owns commit and rollback.
 */
function psSaveOpeningBatch(mysqli $conn, array $lines, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!$lines) {
        return ['ok' => false, 'error' => 'Select at least one product.'];
    }
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The opening balances could not be saved.'];
    }
    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('opening balance transaction did not start');
        return ['ok' => false, 'error' => 'The opening balances could not be saved.'];
    }
    $ids = [];
    try {
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('Select at least one product.');
            }
            $productId = trim((string)($line['product_id'] ?? ''));
            $branch = trim((string)($line['branch'] ?? ''));
            $key = $productId . '|' . $branch;
            if ($productId === '' || isset($seen[$key])) {
                throw new RuntimeException('Each product can be opened once in this batch.');
            }
            $seen[$key] = true;
            $saved = psRecordOpeningBalance($conn, $line);
            if (empty($saved['ok'])) {
                $name = trim((string)($saved['product'] ?? $line['product_name'] ?? $productId));
                if ($name === '') {
                    $name = $productId !== '' ? $productId : 'Product';
                }
                throw new RuntimeException($name . ': ' . (string)($saved['error'] ?? 'The opening balance could not be saved.'));
            }
            $ids[] = $saved['id'];
        }
        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The opening balances could not be saved.');
        }
        return ['ok' => true, 'ids' => $ids];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('opening balance batch failed');
        $message = $e->getMessage();
        $safeStart = [
            'Select at least one product.',
            'Each product can be opened once in this batch.',
            'The opening balances could not be saved.',
        ];
        $known = $message !== '' && (
            in_array($message, $safeStart, true)
            || preg_match('/^.+: .+$/', $message) === 1
        );
        if (!$known || strlen($message) > 400 || stripos($message, 'select ') !== false && stripos($message, ' from ') !== false) {
            $message = 'The opening balances could not be saved.';
        }
        return ['ok' => false, 'error' => 'No opening balances were saved. ' . $message];
    }
}

function psOpeningStockRows(mysqli $conn, string $branch, string $company): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    $company = trim($company);
    if ($branch === '' || !stockMovementEnsureSchema($conn)) {
        return [];
    }
    if ($company !== '') {
        $sql = 'SELECT product_id, product, company_name FROM goods WHERE branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = \'\') ORDER BY product, product_id';
    } else {
        $sql = 'SELECT product_id, product, company_name FROM goods WHERE branch = ? ORDER BY product, product_id';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('opening stock list failed');
        return [];
    }
    if ($company !== '') {
        mysqli_stmt_bind_param($stmt, 'ss', $branch, $company);
    } else {
        mysqli_stmt_bind_param($stmt, 's', $branch);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('opening stock list failed');
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    $goods = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $goods[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    $nameCounts = [];
    foreach ($goods as $row) {
        $name = (string)$row['product'];
        if (!isset($nameCounts[$name])) {
            $nameCounts[$name] = 0;
        }
        $nameCounts[$name]++;
    }
    $legacyCache = [];
    $rows = [];
    foreach ($goods as $row) {
        $productId = trim((string)$row['product_id']);
        $name = (string)$row['product'];
        if ($productId === '') {
            continue;
        }
        if (!array_key_exists($name, $legacyCache)) {
            $legacyCache[$name] = psLegacyStockQuantity($conn, $name, $branch);
        }
        $legacy = $legacyCache[$name];
        $opening = psOpeningQuantity($conn, $productId, $branch, false);
        $migrated = $opening !== null;
        $rows[] = [
            'product_id' => $productId,
            'product' => $name,
            'legacy' => $legacy,
            'legacy_plain' => $legacy === null ? '' : psStockQtyPlain($legacy),
            'migrated' => $migrated,
            'opening' => $opening,
            'opening_plain' => $opening === null ? '' : psStockQtyPlain($opening),
            'ledger' => $migrated ? psStockQtyPlain(getStockBalance($conn, $productId, $branch)) : '',
            'shared_name' => ($nameCounts[$name] ?? 0) > 1,
        ];
    }
    return $rows;
}

function psAllBranchNames(mysqli $conn): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $names = [];
    $res = mysqli_query($conn, 'SELECT branch_name FROM branchies ORDER BY branch_name ASC');
    if (!$res) {
        error_log('branch list read failed');
        return $names;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $name = trim((string)($row['branch_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    mysqli_free_result($res);
    return $names;
}

function psMigrationDuplicateMessage(): string
{
    return 'This product/branch has already been migrated.';
}

/**
 * Opening quantity for legacy migration. Zero is valid; blank is not.
 *
 * @return array{ok:bool,quantity?:string,error?:string}
 */
function psParseMigrationOpeningQuantity(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    if ($raw[0] === '-' || $raw[0] === '+') {
        return ['ok' => false, 'error' => 'An opening quantity cannot be negative.'];
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $raw)) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $dot = strpos($raw, '.');
    if ($dot !== false && strlen(substr($raw, $dot + 1)) > 3) {
        return ['ok' => false, 'error' => 'Quantity can have at most 3 decimal places.'];
    }
    $decimal = psStockDecimal($raw);
    if ($decimal === null) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    if (psStockDecimalCmp($decimal, '0') < 0) {
        return ['ok' => false, 'error' => 'An opening quantity cannot be negative.'];
    }
    return ['ok' => true, 'quantity' => $decimal];
}

/**
 * @return array{ok:bool,reason?:string,error?:string}
 */
function psParseMigrationReason(string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Enter a migration reason.'];
    }
    if (strlen($reason) > 300) {
        return ['ok' => false, 'error' => 'The reason is too long.'];
    }
    return ['ok' => true, 'reason' => $reason];
}

function psMigrationNotes(string $enteredDecimal, string $reason): array
{
    $entered = psStockDecimal($enteredDecimal);
    if ($entered === null) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $reasonParsed = psParseMigrationReason($reason);
    if (empty($reasonParsed['ok'])) {
        return $reasonParsed;
    }
    $notes = 'Legacy stock migration. Opening quantity: '
        . psStockQtyPlain($entered)
        . '. Reason: '
        . $reasonParsed['reason'];
    if (strlen($notes) > 500) {
        return ['ok' => false, 'error' => 'Notes are too long.'];
    }
    return ['ok' => true, 'notes' => $notes];
}

/**
 * Insert one legacy migration opening balance. Does not commit or roll back.
 */
function psRecordLegacyStockMigration(mysqli $conn, array $migration): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The migration could not be saved.'];
    }
    $productId = trim((string)($migration['product_id'] ?? ''));
    $branch = trim((string)($migration['branch'] ?? ''));
    $company = trim((string)($migration['company'] ?? ''));
    if ($productId === '' || $branch === '') {
        return ['ok' => false, 'error' => 'Product and branch are required.'];
    }
    if (!stockMovementBranchExists($conn, $branch)) {
        return ['ok' => false, 'error' => 'Branch was not found.'];
    }
    $goods = psGoodsForOpening($conn, $productId, $branch, $company);
    if (!$goods) {
        return ['ok' => false, 'error' => 'That product is not on this branch.'];
    }
    if (!array_key_exists('quantity', $migration)) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $qtyParsed = psParseMigrationOpeningQuantity((string)$migration['quantity']);
    if (empty($qtyParsed['ok'])) {
        return ['ok' => false, 'error' => (string)($qtyParsed['error'] ?? 'Quantity must be a number.')];
    }
    $entered = $qtyParsed['quantity'];
    $notesParsed = psMigrationNotes($entered, (string)($migration['reason'] ?? ''));
    if (empty($notesParsed['ok'])) {
        return ['ok' => false, 'error' => (string)($notesParsed['error'] ?? 'Enter a migration reason.')];
    }
    $lock = stockMovementInTransaction($conn);
    $existing = psOpeningQuantity($conn, $productId, $branch, $lock);
    if ($existing !== null) {
        return ['ok' => false, 'error' => psMigrationDuplicateMessage()];
    }
    $referenceId = trim((string)($migration['reference_id'] ?? ''));
    if ($referenceId === '') {
        $referenceId = psOpeningReferenceId();
    }
    if (strlen($referenceId) > 250) {
        return ['ok' => false, 'error' => 'That reference is too long.'];
    }
    $priorSum = getStockBalance($conn, (string)$goods['product_id'], $branch);
    $recorded = recordStockMovement($conn, [
        'product_id' => (string)$goods['product_id'],
        'branch' => $branch,
        'movement_type' => 'OPENING_BALANCE',
        'quantity' => $entered,
        'reference_type' => 'LEGACY_MIGRATION',
        'reference_id' => $referenceId,
        'movement_at' => date('Y-m-d H:i:s'),
        'user_id' => $migration['user_id'] ?? null,
        'user_email' => (string)($migration['user_email'] ?? ''),
        'notes' => $notesParsed['notes'],
    ]);
    if (empty($recorded['ok'])) {
        $err = (string)($recorded['error'] ?? '');
        if (stripos($err, 'opening balance already exists') !== false
            || stripos($err, 'already been migrated') !== false) {
            return ['ok' => false, 'error' => psMigrationDuplicateMessage()];
        }
        return $recorded;
    }
    $net = psStockCancelPriorMovements($conn, (string)$goods['product_id'], $branch, $priorSum, $migration['user_id'] ?? null, (string)($migration['user_email'] ?? ''));
    if (empty($net['ok'])) {
        return ['ok' => false, 'error' => 'The migration could not be saved.'];
    }
    $legacy = psLegacyStockQuantity($conn, (string)$goods['product'], $branch);
    return [
        'ok' => true,
        'id' => $recorded['id'],
        'quantity' => $entered,
        'legacy' => $legacy,
        'notes' => $notesParsed['notes'],
        'reference_id' => $referenceId,
        'product' => (string)$goods['product'],
    ];
}

/**
 * Save a confirmed migration batch. When $manageTransaction is false the caller owns commit and rollback.
 */
function psSaveLegacyMigrationBatch(mysqli $conn, array $lines, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!$lines) {
        return ['ok' => false, 'error' => 'Select at least one product.'];
    }
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The migration could not be saved.'];
    }
    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('legacy migration transaction did not start');
        return ['ok' => false, 'error' => 'The migration could not be saved.'];
    }
    $ids = [];
    try {
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('Select at least one product.');
            }
            $productId = trim((string)($line['product_id'] ?? ''));
            $branch = trim((string)($line['branch'] ?? ''));
            $key = $productId . '|' . $branch;
            if ($productId === '' || isset($seen[$key])) {
                throw new RuntimeException('Each product can be migrated once in this batch.');
            }
            $seen[$key] = true;
            $saved = psRecordLegacyStockMigration($conn, $line);
            if (empty($saved['ok'])) {
                $name = trim((string)($saved['product'] ?? $line['product_name'] ?? $productId));
                if ($name === '') {
                    $name = $productId !== '' ? $productId : 'Product';
                }
                throw new RuntimeException($name . ': ' . (string)($saved['error'] ?? 'The migration could not be saved.'));
            }
            $ids[] = $saved['id'];
        }
        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The migration could not be saved.');
        }
        return ['ok' => true, 'ids' => $ids];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('legacy migration batch failed');
        $message = $e->getMessage();
        $safeStart = [
            'Select at least one product.',
            'Each product can be migrated once in this batch.',
            'The migration could not be saved.',
        ];
        $known = $message !== '' && (
            in_array($message, $safeStart, true)
            || preg_match('/^.+: .+$/', $message) === 1
        );
        if (!$known || strlen($message) > 400 || stripos($message, 'select ') !== false && stripos($message, ' from ') !== false) {
            $message = 'The migration could not be saved.';
        }
        return ['ok' => false, 'error' => 'No migrations were saved. ' . $message];
    }
}

function psLegacyMigrationRows(mysqli $conn, string $branch, string $company): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '') {
        return [];
    }
    $all = psOpeningStockRows($conn, $branch, $company);
    $rows = [];
    foreach ($all as $row) {
        if (!empty($row['migrated'])) {
            continue;
        }
        $legacy = $row['legacy'];
        $rows[] = [
            'product_id' => $row['product_id'],
            'product' => $row['product'],
            'branch' => $branch,
            'legacy' => $legacy,
            'legacy_plain' => $row['legacy_plain'],
            'legacy_label' => $legacy === null ? 'Not available' : $row['legacy_plain'],
            'status' => 'Not migrated',
            'shared_name' => $row['shared_name'],
        ];
    }
    return $rows;
}

/**
 * Session-scoped stock branches.
 * Phase 9.2: only authorised+registered names — never trust selected_branch alone.
 *
 * @return array<string,string> name => name
 */
function psSessionStockBranches(mysqli $conn): array
{
    $allowed = [];
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $allowed;
    }
    require_once __DIR__ . '/branch_access.php';
    foreach (psBranchAuthorisedRegisteredNames($conn) as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $allowed[$name] = $name;
        }
    }
    return $allowed;
}

function psAdjustmentReasons(): array
{
    return [
        'Stock count variance',
        'Damaged stock',
        'Expired stock',
        'Missing stock',
        'Data correction',
        'Other',
    ];
}

function psAdjustmentTokenIssue(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_adjustment_token'] = $token;
    $_SESSION['ps_adjustment_token_at'] = time();
    return $token;
}

function psAdjustmentTokenPeek(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $token = (string)($_SESSION['ps_adjustment_token'] ?? '');
    $at = (int)($_SESSION['ps_adjustment_token_at'] ?? 0);
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || (time() - $at) > 600) {
        return '';
    }
    return $token;
}

function psAdjustmentTokenCheck($posted): array
{
    $posted = trim((string)$posted);
    $current = psAdjustmentTokenPeek();
    $fresh = $current !== ''
        && strlen($current) === strlen($posted)
        && hash_equals($current, $posted);
    if (!$fresh) {
        return ['ok' => false, 'error' => 'This stock adjustment was already submitted.'];
    }
    return ['ok' => true, 'token' => $posted];
}

function psAdjustmentTokenConsume(): void
{
    unset($_SESSION['ps_adjustment_token'], $_SESSION['ps_adjustment_token_at']);
}

function psParseAdjustmentQuantity(string $raw, string $direction): array
{
    $raw = trim($raw);
    $direction = trim($direction);
    if ($direction !== 'increase' && $direction !== 'decrease') {
        return ['ok' => false, 'error' => 'Choose increase or decrease.'];
    }
    if ($raw !== '' && $raw[0] === '-') {
        return ['ok' => false, 'error' => 'Enter a quantity greater than zero.'];
    }
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/', $raw)) {
        if (preg_match('/^(?:0|[1-9]\d*)\.\d{4,}$/', $raw)) {
            return ['ok' => false, 'error' => 'Quantity can have at most 3 decimal places.'];
        }
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    $whole = explode('.', $raw, 2)[0];
    if (strlen($whole) > 9) {
        return ['ok' => false, 'error' => 'Quantity is too large.'];
    }
    $decimal = psStockDecimal($raw);
    if ($decimal === null) {
        return ['ok' => false, 'error' => 'Quantity must be a number.'];
    }
    if (psStockDecimalCmp($decimal, '0') === 0) {
        return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
    }
    if (psStockDecimalCmp($decimal, '0') < 0) {
        return ['ok' => false, 'error' => 'Enter a quantity greater than zero.'];
    }
    if ($direction === 'decrease') {
        $decimal = '-' . $decimal;
    }
    return ['ok' => true, 'quantity' => $decimal];
}

function psAdjustmentNotes(string $reason, string $detail): array
{
    $reason = trim($reason);
    $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
    if ($reason === '' || !in_array($reason, psAdjustmentReasons(), true)) {
        return ['ok' => false, 'error' => 'A reason is required.'];
    }
    if ($reason === 'Other') {
        if ($detail === '') {
            return ['ok' => false, 'error' => 'Enter a reason.'];
        }
        $notes = 'Other: ' . $detail;
    } else {
        $notes = $reason;
        if ($detail !== '') {
            $notes .= '. ' . $detail;
        }
    }
    if (strlen($notes) > 500) {
        return ['ok' => false, 'error' => 'The reason is too long.'];
    }
    return ['ok' => true, 'notes' => $notes];
}

/**
 * Ledger id is goods.product_id. goods.id is accepted only when it is not already a product code.
 */
function psGoodsForAdjustment(mysqli $conn, string $postedId, string $branch, string $company): ?array
{
    $postedId = trim($postedId);
    $branch = trim($branch);
    $company = trim($company);
    if ($postedId === '' || $branch === '') {
        return null;
    }
    $byCode = psGoodsForOpening($conn, $postedId, $branch, $company);
    if ($byCode && trim((string)$byCode['product_id']) !== '') {
        return $byCode;
    }
    if (!preg_match('/^[1-9]\d*$/', $postedId)) {
        return null;
    }
    if ($company !== '') {
        $sql = 'SELECT product_id, product, branch, company_name FROM goods WHERE id = ? AND branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = \'\') LIMIT 1';
    } else {
        $sql = 'SELECT product_id, product, branch, company_name FROM goods WHERE id = ? AND branch = ? LIMIT 1';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('stock adjustment product lookup failed');
        return null;
    }
    if ($company !== '') {
        mysqli_stmt_bind_param($stmt, 'sss', $postedId, $branch, $company);
    } else {
        mysqli_stmt_bind_param($stmt, 'ss', $postedId, $branch);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock adjustment product lookup failed');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row || trim((string)$row['product_id']) === '') {
        return null;
    }
    return $row;
}

function psAdjustmentProductChoices(mysqli $conn, string $branch, string $company): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    $company = trim($company);
    if ($branch === '') {
        return [];
    }
    if (function_exists('psEnsureGoodsBarcodeSchema')) {
        require_once __DIR__ . '/barcode.php';
        psEnsureGoodsBarcodeSchema($conn);
    }
    if ($company !== '') {
        $sql = 'SELECT id, product_id, product, barcode FROM goods WHERE branch = ? AND (company_name = ? OR company_name IS NULL OR company_name = \'\') AND product_id <> \'\' ORDER BY product, product_id, id';
    } else {
        $sql = 'SELECT id, product_id, product, barcode FROM goods WHERE branch = ? AND product_id <> \'\' ORDER BY product, product_id, id';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('stock adjustment product list failed');
        return [];
    }
    if ($company !== '') {
        mysqli_stmt_bind_param($stmt, 'ss', $branch, $company);
    } else {
        mysqli_stmt_bind_param($stmt, 's', $branch);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock adjustment product list failed');
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    $goods = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $goods[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    $nameCounts = [];
    foreach ($goods as $row) {
        $name = (string)$row['product'];
        if (!isset($nameCounts[$name])) {
            $nameCounts[$name] = 0;
        }
        $nameCounts[$name]++;
    }
    $rows = [];
    foreach ($goods as $row) {
        $productId = trim((string)$row['product_id']);
        if ($productId === '') {
            continue;
        }
        $name = (string)$row['product'];
        $rows[] = [
            'id' => (int)$row['id'],
            'product_id' => $productId,
            'product' => $name,
            'barcode' => isset($row['barcode']) ? trim((string)$row['barcode']) : '',
            'shared_name' => ($nameCounts[$name] ?? 0) > 1,
        ];
    }
    return $rows;
}

/**
 * Save one ADJUSTMENT. recordStockMovement() does not commit or roll back.
 * When $manageTransaction is false the caller owns commit and rollback.
 * The one-time token is removed only after a successful commit.
 */
function psSaveStockAdjustment(mysqli $conn, array $input, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $tokenCheck = psAdjustmentTokenCheck($input['token'] ?? '');
    if (empty($tokenCheck['ok'])) {
        return ['ok' => false, 'error' => (string)$tokenCheck['error']];
    }

    $userEmail = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $userEmail = trim((string)($_SESSION['logger'] ?? ''));
    }
    if ($userEmail === '') {
        return ['ok' => false, 'error' => 'Sign in before adjusting stock.'];
    }
    $actor = stockMovementActor($conn, ['user_email' => $userEmail]);
    if ($actor['user_id'] === null) {
        return ['ok' => false, 'error' => 'Sign in before adjusting stock.'];
    }

    $allowed = psSessionStockBranches($conn);
    $branch = trim((string)($input['branch'] ?? ''));
    if ($branch === '' || !isset($allowed[$branch])) {
        return ['ok' => false, 'error' => 'Select a branch you can use.'];
    }
    if (!stockMovementBranchExists($conn, $branch)) {
        return ['ok' => false, 'error' => 'Branch was not found.'];
    }

    $company = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $company = trim((string)($_SESSION['the_company'] ?? ''));
    }
    $parsed = psParseAdjustmentQuantity((string)($input['quantity'] ?? ''), (string)($input['direction'] ?? ''));
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => (string)$parsed['error']];
    }
    $notes = psAdjustmentNotes((string)($input['reason'] ?? ''), (string)($input['reason_detail'] ?? ''));
    if (empty($notes['ok'])) {
        return ['ok' => false, 'error' => (string)$notes['error']];
    }

    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Stock adjustment could not be saved. Please try again.'];
    }

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('stock adjustment transaction did not start');
        return ['ok' => false, 'error' => 'Stock adjustment could not be saved. Please try again.'];
    }

    try {
        $goods = psGoodsForAdjustment($conn, (string)($input['product_id'] ?? ''), $branch, $company);
        if (!$goods) {
            throw new RuntimeException('That product is not on this branch.');
        }
        $productId = trim((string)$goods['product_id']);
        $quantity = (string)$parsed['quantity'];
        $ledger = psSaleLedgerBalance($conn, $productId, $branch);
        if (empty($ledger['ok'])) {
            throw new RuntimeException('Stock adjustment could not be saved. Please try again.');
        }
        $tracked = !empty($ledger['tracked']);
        if ($tracked && psStockDecimalCmp($quantity, '0') < 0) {
            $need = psStockMilli($quantity);
            if ($need === null) {
                throw new RuntimeException('Stock adjustment could not be saved. Please try again.');
            }
            $need = abs($need);
            $availableMilli = (int)$ledger['milli'];
            if ($need > $availableMilli) {
                $name = trim((string)$goods['product']);
                if ($name === '') {
                    $name = $productId;
                }
                $requested = psStockQtyPlain(ltrim($quantity, '-'));
                $available = psStockQtyPlain((string)$ledger['balance']);
                throw new RuntimeException(
                    'Insufficient stock for ' . $name
                    . '. Requested decrease: ' . $requested
                    . '. Available: ' . $available . '.'
                );
            }
        }

        $recorded = recordStockMovement($conn, [
            'product_id' => $productId,
            'branch' => $branch,
            'movement_type' => 'ADJUSTMENT',
            'quantity' => $quantity,
            'reference_type' => 'ADJUSTMENT',
            'reference_id' => '',
            'movement_at' => date('Y-m-d H:i:s'),
            'user_id' => $actor['user_id'],
            'user_email' => (string)$actor['user_email'],
            'notes' => $notes['notes'],
        ]);
        if (empty($recorded['ok'])) {
            error_log('stock adjustment insert failed');
            throw new RuntimeException('Stock adjustment could not be saved. Please try again.');
        }
        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('Stock adjustment could not be saved. Please try again.');
        }
        if ($owns) {
            psAdjustmentTokenConsume();
        }
        $balance = null;
        if ($tracked) {
            $signed = psStockMilli($quantity);
            $balance = psMilliToStock((int)$ledger['milli'] + (int)$signed);
        }
        return [
            'ok' => true,
            'id' => (int)$recorded['id'],
            'product_id' => $productId,
            'branch' => $branch,
            'quantity' => $quantity,
            'tracked' => $tracked,
            'notes' => $notes['notes'],
            'balance' => $balance,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        $message = $e->getMessage();
        $insufficient = strncmp($message, 'Insufficient stock for ', 23) === 0;
        $known = $message === 'Stock adjustment could not be saved. Please try again.'
            || $message === 'That product is not on this branch.'
            || $insufficient;
        if (!$known || stripos($message, 'select ') !== false || stripos($message, 'insert ') !== false || stripos($message, 'update ') !== false) {
            $message = 'Stock adjustment could not be saved. Please try again.';
        }
        return ['ok' => false, 'error' => $message];
    }
}

function psStockTakeTokenIssue(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['ps_stock_take_token'] = $token;
    $_SESSION['ps_stock_take_token_at'] = time();
    return $token;
}

function psStockTakeTokenPeek(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $token = (string)($_SESSION['ps_stock_take_token'] ?? '');
    $at = (int)($_SESSION['ps_stock_take_token_at'] ?? 0);
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || (time() - $at) > 600) {
        return '';
    }
    return $token;
}

function psStockTakeTokenCheck($posted): array
{
    $posted = trim((string)$posted);
    $current = psStockTakeTokenPeek();
    $fresh = $current !== ''
        && strlen($current) === strlen($posted)
        && hash_equals($current, $posted);
    if (!$fresh) {
        return ['ok' => false, 'error' => 'This stock take was already submitted.'];
    }
    return ['ok' => true, 'token' => $posted];
}

function psStockTakeTokenConsume(): void
{
    unset($_SESSION['ps_stock_take_token'], $_SESSION['ps_stock_take_token_at']);
}

/**
 * Counted quantity for a stock take. Zero is allowed. More than 3 decimal places is not.
 */
function psParseStockTakeCount(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Counted quantity must be a number.'];
    }
    if ($raw[0] === '-') {
        return ['ok' => false, 'error' => 'Counted quantity cannot be negative.'];
    }
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/', $raw)) {
        if (preg_match('/^(?:0|[1-9]\d*)\.\d{4,}$/', $raw) || preg_match('/^\d+\.\d{4,}$/', $raw)) {
            return ['ok' => false, 'error' => 'Counted quantity can have at most 3 decimal places.'];
        }
        return ['ok' => false, 'error' => 'Counted quantity must be a number.'];
    }
    $whole = explode('.', $raw, 2)[0];
    if (strlen($whole) > 9) {
        return ['ok' => false, 'error' => 'Counted quantity is too large.'];
    }
    $decimal = psStockDecimal($raw);
    if ($decimal === null || psStockDecimalCmp($decimal, '0') < 0) {
        return ['ok' => false, 'error' => 'Counted quantity must be a number.'];
    }
    return ['ok' => true, 'quantity' => $decimal];
}

function psStockTakeSheetRow(mysqli $conn, string $itemId, bool $lock, string $branch = ''): ?array
{
    if (!preg_match('/^[1-9]\d*$/', $itemId)) {
        return null;
    }
    psEnsureStockListBranchColumn($conn);
    $sql = 'SELECT id, product, product_id, qty, branch FROM stock_list33 WHERE id = ? LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('stock take line was not read');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $itemId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock take line was not read');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if ($row && $branch !== '' && trim((string)($row['branch'] ?? '')) !== '' && trim((string)$row['branch']) !== $branch) {
        // A line from another branch's stock list is never counted against this branch.
        return null;
    }
    return $row ?: null;
}

function psStockTakeDistinctProductIds(mysqli $conn, string $name): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT DISTINCT product_id FROM goods WHERE product = ? AND product_id <> \'\''
    );
    if (!$stmt) {
        error_log('stock take product lookup failed');
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $name);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock take product lookup failed');
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        mysqli_stmt_close($stmt);
        error_log('stock take product lookup failed');
        return null;
    }
    $ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $productId = trim((string)($row['product_id'] ?? ''));
        if ($productId !== '') {
            $ids[$productId] = $productId;
        }
    }
    mysqli_stmt_close($stmt);
    return array_values($ids);
}

function psStockTakeProductMatchesName(mysqli $conn, string $productId, string $name): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id FROM goods WHERE product_id = ? AND product = ? LIMIT 1'
    );
    if (!$stmt) {
        error_log('stock take product lookup failed');
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $productId, $name);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        error_log('stock take product lookup failed');
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    $found = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return (bool)$found;
}

function psStockTakePrice(mysqli $conn, string $productId, string $productName, string $branch): string
{
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || $branch === '') {
        return '0';
    }
    require_once __DIR__ . '/product_master.php';
    $row = psGoodsByProductIdBranch($conn, $productId, $branch);
    if ($row && is_numeric($row['price'] ?? null)) {
        return (string)$row['price'];
    }
    return '0';
}

/**
 * Legacy helper kept for call sites; identity is product_id + branch only (no name).
 */
function psStockTakeLegacyGoods(mysqli $conn, string $productName, string $productId = '', string $branch = ''): ?array
{
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || $branch === '') {
        return null;
    }
    require_once __DIR__ . '/product_master.php';
    $row = psGoodsByProductIdBranch($conn, $productId, $branch);
    if (!$row) {
        return null;
    }
    return [
        'product_id' => (string)$row['product_id'],
        'price' => $row['price'],
    ];
}

function psStockTakeVarianceValue(string $price, int $varianceMilli): string
{
    if (!is_numeric($price)) {
        return '0';
    }
    $units = $varianceMilli / 1000;
    $value = ((float)$price) * $units;
    if (!is_finite($value)) {
        return '0';
    }
    $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    if ($text === '' || $text === '-0') {
        return '0';
    }
    return $text;
}

/**
 * Save one stock-take line the way stock_take_sheet.php posts it.
 * A product is controlled only when OPENING_BALANCE already exists for that product_id and branch.
 * Controlled lines write one ADJUSTMENT (reference_type STOCK_TAKE) and do not write a STOCK-TAKE receipt or sale.
 * Name-only lines with more than one product id are rejected when any of those ids is controlled at this branch.
 * Unmigrated lines keep the STOCK-TAKE goods receipt or online sale.
 * recordStockMovement() does not commit or roll back. This function does, unless the caller owns the transaction.
 * stock_list33, goods_receipts, and online_sales are InnoDB here, so the line update rolls back with the movement.
 * The one-time token is removed only after a successful commit.
 */
function psSaveStockTake(mysqli $conn, array $input, bool $manageTransaction = true): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $tokenCheck = psStockTakeTokenCheck($input['token'] ?? '');
    if (empty($tokenCheck['ok'])) {
        return ['ok' => false, 'error' => (string)$tokenCheck['error']];
    }

    $allowed = psSessionStockBranches($conn);
    $branch = trim((string)($input['branch'] ?? ''));
    if ($branch === '' || !isset($allowed[$branch])) {
        return ['ok' => false, 'error' => 'Select a branch you can use.'];
    }
    if (!stockMovementBranchExists($conn, $branch)) {
        return ['ok' => false, 'error' => 'Branch was not found.'];
    }

    $parsed = psParseStockTakeCount((string)($input['physical_stock'] ?? ''));
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => (string)$parsed['error']];
    }
    $counted = (string)$parsed['quantity'];
    $countedMilli = psStockMilli($counted);
    if ($countedMilli === null) {
        return ['ok' => false, 'error' => 'Counted quantity must be a number.'];
    }

    $itemId = trim((string)($input['item_id'] ?? ''));
    if (!preg_match('/^[1-9]\d*$/', $itemId)) {
        return ['ok' => false, 'error' => 'That stock line was not found.'];
    }
    $postedProductId = trim((string)($input['product_id'] ?? ''));

    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The stock take could not be saved.'];
    }
    // 2026-11-03: stock take records (tables are created here, before the transaction: CREATE TABLE would commit it).
    require_once __DIR__ . '/stock_take_records.php';
    $recordsReady = psStockTakeRecordsEnsureSchema($conn);

    $owns = $manageTransaction;
    if ($owns && !mysqli_begin_transaction($conn)) {
        error_log('stock take transaction did not start');
        return ['ok' => false, 'error' => 'The stock take could not be saved.'];
    }

    try {
        $sheet = psStockTakeSheetRow($conn, $itemId, true, $branch);
        if (!$sheet) {
            throw new RuntimeException('That stock line was not found.');
        }
        $productName = (string)$sheet['product'];
        $storedProductId = trim((string)($sheet['product_id'] ?? ''));
        if ($storedProductId !== '' && $postedProductId !== '' && $postedProductId !== $storedProductId) {
            throw new RuntimeException('That product does not match this stock line.');
        }
        $identified = $storedProductId !== '' ? $storedProductId : $postedProductId;
        if ($identified === '') {
            throw new RuntimeException('This stock line has no Product ID. Stock take was not saved.');
        }
        $productId = stockMovementResolveProductOnBranch($conn, $identified, $branch);
        if ($productId === null) {
            throw new RuntimeException('Product was not found on the selected branch.');
        }
        if ($productName !== '' && !psStockTakeProductMatchesName($conn, $productId, $productName)) {
            throw new RuntimeException('That product does not match this stock line.');
        }
        $controlled = isStockControlled($conn, $productId, $branch);

        $varianceMilli = 0;
        $movementId = 0;
        $legacyKind = '';
        if ($controlled) {
            $ledger = psSaleLedgerBalance($conn, $productId, $branch);
            if (empty($ledger['ok']) || empty($ledger['tracked'])) {
                throw new RuntimeException('The stock take could not be saved.');
            }
            $systemMilli = (int)$ledger['milli'];
            $recordSystemMilli = $systemMilli;
            $varianceMilli = $countedMilli - $systemMilli;
            if ($varianceMilli !== 0) {
                $systemPlain = psStockQtyPlain((string)$ledger['balance']);
                $countedPlain = psStockQtyPlain($counted);
                $notes = 'Stock take variance. System: ' . $systemPlain . ', Counted: ' . $countedPlain . '.';
                $recorded = recordStockMovement($conn, [
                    'product_id' => $productId,
                    'branch' => $branch,
                    'movement_type' => 'ADJUSTMENT',
                    'quantity' => psMilliToStock($varianceMilli),
                    'reference_type' => 'STOCK_TAKE',
                    'reference_id' => $itemId,
                    'movement_at' => date('Y-m-d H:i:s'),
                    'notes' => $notes,
                ]);
                if (empty($recorded['ok'])) {
                    error_log('stock take adjustment was not saved');
                    throw new RuntimeException('The stock take could not be saved.');
                }
                $movementId = (int)$recorded['id'];
            }
            $balance = getStockBalance($conn, $productId, $branch);
            $cacheQty = psStockQtyPlain($balance);
            if ($cacheQty !== psStockQtyPlain($counted)) {
                throw new RuntimeException('The stock take could not be saved.');
            }
        } else {
            $cacheQtyRaw = trim((string)($sheet['qty'] ?? ''));
            $cacheDecimal = psStockDecimal($cacheQtyRaw);
            if ($cacheDecimal === null) {
                $cacheDecimal = '0.000';
            }
            $cacheMilli = psStockMilli($cacheDecimal);
            if ($cacheMilli === null) {
                throw new RuntimeException('The stock take could not be saved.');
            }
            $recordSystemMilli = $cacheMilli;
            $varianceMilli = $countedMilli - $cacheMilli;
            $legacyGoods = psStockTakeLegacyGoods($conn, $productName, $productId, $branch);
            $legacyProductId = $productId;
            $price = $legacyGoods && is_numeric($legacyGoods['price'] ?? null)
                ? (string)$legacyGoods['price']
                : psStockTakePrice($conn, $productId, $productName, $branch);
            if ($varianceMilli > 0) {
                $invoice = date('d-m-Y') . random_int(1, 1000);
                $quantity = psStockQtyPlain(psMilliToStock($varianceMilli));
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO goods_receipts (product_id, product, quantity, date_, invoice_number, supplier_name, status, branch)
                     VALUES (?, ?, ?, CURDATE(), ?, \'STOCK-TAKE\', \'valid\', ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException('The stock take could not be saved.');
                }
                mysqli_stmt_bind_param($stmt, 'sssss', $legacyProductId, $productName, $quantity, $invoice, $branch);
                $savedLine = mysqli_stmt_execute($stmt);
                $legacyErrno = mysqli_errno($conn);
                mysqli_stmt_close($stmt);
                if (!$savedLine) {
                    error_log('stock take legacy line was not saved ' . $legacyErrno);
                    throw new RuntimeException('The stock take could not be saved.');
                }
                $legacyKind = 'goods_receipt';
            } elseif ($varianceMilli < 0) {
                $invoice = date('d-m-Y') . random_int(1, 1000);
                $quantity = psStockQtyPlain(psMilliToStock(abs($varianceMilli)));
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO online_sales (invoice_number, product_id, product, quantity, revenue, date_, customer, status, branch)
                     VALUES (?, ?, ?, ?, \'0.00\', CURDATE(), \'STOCK-TAKE\', \'valid\', ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException('The stock take could not be saved.');
                }
                mysqli_stmt_bind_param($stmt, 'sssss', $invoice, $legacyProductId, $productName, $quantity, $branch);
                $savedLine = mysqli_stmt_execute($stmt);
                $legacyErrno = mysqli_errno($conn);
                mysqli_stmt_close($stmt);
                if (!$savedLine) {
                    error_log('stock take legacy line was not saved ' . $legacyErrno);
                    throw new RuntimeException('The stock take could not be saved.');
                }
                $legacyKind = 'online_sale';
            }
            $cacheQty = psStockQtyPlain($counted);
        }

        if ($controlled) {
            $price = psStockTakePrice($conn, $productId, $productName, $branch);
        } elseif (!isset($price)) {
            $price = '0';
        }
        $varianceValue = psStockTakeVarianceValue($price, $varianceMilli);
        $variancePlain = psStockQtyPlain(psMilliToStock($varianceMilli));
        $update = mysqli_prepare(
            $conn,
            'UPDATE stock_list33 SET qty = ?, bond_value = ?, variance_value = ? WHERE id = ?'
        );
        if (!$update) {
            throw new RuntimeException('The stock take could not be saved.');
        }
        mysqli_stmt_bind_param($update, 'ssss', $cacheQty, $variancePlain, $varianceValue, $itemId);
        $updated = mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        if (!$updated) {
            throw new RuntimeException('The stock take could not be saved.');
        }

        // 2026-11-03: keep the count (also when it matched) for the Stock Take Records page.
        if ($recordsReady && !psStockTakeRecordsSaveLine($conn,
            trim((string)($_SESSION['the_company'] ?? '')), $branch, trim((string)($_SESSION['logger'] ?? '')), 'WEB', [
                'product_id' => $productId,
                'product' => $productName,
                'system_qty' => psMilliToStock($recordSystemMilli),
                'counted_qty' => psMilliToStock($countedMilli),
                'variance' => psMilliToStock($varianceMilli),
                'controlled' => $controlled,
                'movement_id' => $movementId ?: null,
            ])) {
            throw new RuntimeException('The stock take could not be saved.');
        }

        if ($owns && !mysqli_commit($conn)) {
            throw new RuntimeException('The stock take could not be saved.');
        }
        if ($owns) {
            psStockTakeTokenConsume();
        }
        return [
            'ok' => true,
            'item_id' => $itemId,
            'product_id' => $productId,
            'branch' => $branch,
            'controlled' => $controlled,
            'movement_id' => $movementId,
            'variance' => $variancePlain,
            'qty' => $cacheQty,
            'legacy' => $legacyKind,
        ];
    } catch (Throwable $e) {
        if ($owns) {
            mysqli_rollback($conn);
        }
        error_log('stock take save failed');
        $message = $e->getMessage();
        $safe = [
            'The stock take could not be saved.',
            'That stock line was not found.',
            'That product does not match this stock line.',
            'Product was not found.',
            'More than one product uses this name. Stock take was not saved.',
            'Counted quantity must be a number.',
            'Counted quantity cannot be negative.',
            'Counted quantity can have at most 3 decimal places.',
            'Counted quantity is too large.',
            'Select a branch you can use.',
            'Branch was not found.',
            'This stock take was already submitted.',
        ];
        if (!in_array($message, $safe, true) || stripos($message, 'select ') !== false || stripos($message, 'insert ') !== false || stripos($message, 'update ') !== false) {
            $message = 'The stock take could not be saved.';
        }
        return ['ok' => false, 'error' => $message];
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    stockMovementEnsureSchema($conn);
}

/**
 * 2026-10-14: movements recorded before a product's opening balance (e.g. sales made before
 * tracking began) are already reflected in the calculated stock the opening is based on.
 * Cancel them with one ADJUSTMENT so the ledger balance equals the opening. Call right after
 * the opening row is written, with the ledger sum read before it. Does not commit.
 */
function psStockCancelPriorMovements(mysqli $conn, string $productId, string $branch, string $priorSum, $userId, string $email): array
{
    $priorMilli = psStockMilli($priorSum);
    if ($priorMilli === null) {
        return ['ok' => false, 'error' => 'Stock could not be read.'];
    }
    if ($priorMilli === 0) {
        return ['ok' => true, 'adjusted' => false];
    }
    $rec = recordStockMovement($conn, [
        'product_id' => $productId,
        'branch' => $branch,
        'movement_type' => 'ADJUSTMENT',
        'quantity' => psMilliToStock(-$priorMilli),
        'reference_type' => 'OPENING_NET',
        'reference_id' => psOpeningReferenceId(),
        'movement_at' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'user_email' => $email,
        'notes' => 'Movements recorded before the opening (' . psStockQtyPlain($priorSum) . ') are already in the calculated stock; cancelled so the balance equals the opening.',
    ]);
    if (empty($rec['ok'])) {
        return $rec;
    }
    return ['ok' => true, 'adjusted' => true];
}

/**
 * 2026-10-14: start ledger tracking for every product at a branch that has no opening balance yet.
 *
 * Opening = calculated stock (goods receipts + transfers in - sales - transfers out, never below 0).
 * Movements already in the ledger for that product (e.g. sales recorded before tracking began) are
 * cancelled with one ADJUSTMENT, so the balance afterwards equals the calculated stock and nothing is
 * counted twice. Products sharing one name at the branch share one calculated figure: the first gets
 * it, the others start at 0. All-or-nothing in one transaction.
 *
 * @return array{ok:bool, started?:int, adjusted?:int, error?:string}
 */
function psStockStartTrackingBranch(mysqli $conn, string $branch, string $company, ?int $userId, string $email): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $branch = trim($branch);
    if ($branch === '' || !stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Stock tracking could not be started.'];
    }
    $rows = psOpeningStockRows($conn, $branch, $company);
    $pending = array_values(array_filter($rows, static function ($r) { return empty($r['migrated']); }));
    if (!$pending) {
        return ['ok' => true, 'started' => 0, 'adjusted' => 0];
    }
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Stock tracking could not be started.'];
    }
    $started = 0;
    $adjusted = 0;
    $namesUsed = [];
    $now = date('Y-m-d H:i:s');
    try {
        foreach ($pending as $row) {
            $productId = (string)$row['product_id'];
            $name = (string)$row['product'];
            $calc = $row['legacy'] === null ? '0.000' : (string)$row['legacy'];
            $notes = 'Tracking started automatically from calculated stock ' . psStockQtyPlain($calc) . '.';
            if (psStockDecimalCmp($calc, '0') < 0) {
                $calc = '0.000';
                $notes .= ' Calculated stock was below zero, so tracking starts at 0.';
            }
            if (!empty($row['shared_name'])) {
                if (isset($namesUsed[$name])) {
                    $calc = '0.000';
                    $notes .= ' Name shared with product ' . $namesUsed[$name] . ', which holds the calculated stock; this one starts at 0.';
                } else {
                    $namesUsed[$name] = $productId;
                }
            }
            $priorSum = getStockBalance($conn, $productId, $branch);
            $rec = recordStockMovement($conn, [
                'product_id' => $productId,
                'branch' => $branch,
                'movement_type' => 'OPENING_BALANCE',
                'quantity' => $calc,
                'reference_type' => 'AUTO_OPENING',
                'reference_id' => psOpeningReferenceId(),
                'movement_at' => $now,
                'user_id' => $userId,
                'user_email' => $email,
                'notes' => substr($notes, 0, 500),
            ]);
            if (empty($rec['ok'])) {
                throw new RuntimeException((string)($rec['error'] ?? 'opening'));
            }
            $net = psStockCancelPriorMovements($conn, $productId, $branch, $priorSum, $userId, $email);
            if (empty($net['ok'])) {
                throw new RuntimeException((string)($net['error'] ?? 'adjustment'));
            }
            if (!empty($net['adjusted'])) {
                $adjusted++;
            }
            $started++;
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('commit');
        }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('start tracking failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Stock tracking could not be started. Nothing was changed.'];
    }
    return ['ok' => true, 'started' => $started, 'adjusted' => $adjusted];
}

/**
 * 2026-10-14: a newly created product starts tracked at 0 on its branch.
 * Call inside the creating transaction, right after the goods row is inserted.
 */
function psStockOpenNewProduct(mysqli $conn, string $productId, string $branch, ?int $userId, string $email, string $quantity = '0'): bool
{
    // 2026-11-02: an opening quantity can come with the new product (item import "Opening Stock" column).
    $quantity = psStockDecimal($quantity) ?? '0';
    if (psStockDecimalCmp($quantity, '0') < 0) {
        return false;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $productId = trim($productId);
    $branch = trim($branch);
    if ($productId === '' || $branch === '' || !stockMovementEnsureSchema($conn)) {
        return false;
    }
    if (psOpeningQuantity($conn, $productId, $branch, false) !== null) {
        return true;
    }
    $rec = recordStockMovement($conn, [
        'product_id' => $productId,
        'branch' => $branch,
        'movement_type' => 'OPENING_BALANCE',
        'quantity' => $quantity,
        'reference_type' => 'NEW_PRODUCT',
        'reference_id' => psOpeningReferenceId(),
        'movement_at' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'user_email' => $email,
        'notes' => psStockDecimalCmp($quantity, '0') > 0
            ? 'New product with opening stock ' . psStockQtyPlain($quantity) . ' (item import).'
            : 'New product: stock is tracked from creation. Receive stock with a goods receipt.',
    ]);
    return !empty($rec['ok']);
}

/**
 * 2026-10-14: may the POS sell more than is on hand (stock goes below zero)?
 * On by default for now. Turn off with 'allow_negative_stock' => '0' in the private
 * pockets_config.php return array (or env PS_ALLOW_NEGATIVE_STOCK=0). Sales only:
 * transfers, purchase returns and adjustments still check stock.
 */
function psAllowNegativeStockSales(?mysqli $conn = null, ?string $company = null): bool
{
    // 2026-10-18: each company chooses (Opening Stock page); the config value is the default.
    if ($conn !== null) {
        require_once __DIR__ . '/cash_controls.php';
        return psCompanyAllowsNegativeStock($conn, $company);
    }
    $raw = function_exists('psConfigValue') ? psConfigValue('allow_negative_stock', '1') : '1';
    return !in_array(strtolower(trim($raw)), ['0', 'false', 'no', 'off'], true);
}
