<?php
/**
 * 2026-11-03 — Stock take records.
 *
 * Every counted line (web Stock Take sheet and phone Stock Take) is saved with the stock the system expected, the
 * count and the difference, also when the count matched. Lines are grouped into one stock take per company, branch,
 * person, source (WEB / PHONE) and day. The Stock Take Records page (stock_take_records.php) lists them; who may open
 * it is a company role permission ("stock_take_records").
 *
 * Older web stock takes saved before this only left a stock movement for a difference ("Stock take variance.
 * System: X, Counted: Y."); psStockTakeRecordsBackfill() turns those into HISTORY stock takes (differences only).
 *
 * The phone API (mobile_api_deploy/stock_take.php) writes the same two tables with the same columns.
 */

function psStockTakeRecordsEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $ok = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_takes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(250) NOT NULL DEFAULT '',
            branch VARCHAR(250) NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'WEB',
            counted_by VARCHAR(250) NOT NULL DEFAULT '',
            take_date DATE NOT NULL,
            started_at DATETIME NOT NULL,
            last_count_at DATETIME NOT NULL,
            KEY idx_st_company_date (company_name, take_date),
            KEY idx_st_group (company_name, branch, counted_by, source, take_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_take_lines (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            take_id INT NOT NULL,
            product_id VARCHAR(64) NOT NULL DEFAULT '',
            product VARCHAR(250) NOT NULL DEFAULT '',
            system_qty DECIMAL(16,3) NULL,
            counted_qty DECIMAL(16,3) NULL,
            variance DECIMAL(16,3) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) NULL,
            unit_price DECIMAL(14,2) NULL,
            controlled TINYINT(1) NOT NULL DEFAULT 0,
            movement_id INT NULL,
            counted_at DATETIME NOT NULL,
            KEY idx_stl_take (take_id),
            UNIQUE KEY uq_stl_movement (movement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ok;
}

/** The stock take (header) a count belongs to: same company, branch, person, source and day. Returns its id or 0. */
function psStockTakeRecordsTakeId(mysqli $conn, string $company, string $branch, string $countedBy, string $source, string $at): int
{
    $day = substr($at, 0, 10);
    $st = mysqli_prepare($conn, 'SELECT id FROM stock_takes WHERE company_name = ? AND branch = ? AND counted_by = ? AND source = ? AND take_date = ?
        ORDER BY id DESC LIMIT 1');
    if (!$st) {
        return 0;
    }
    mysqli_stmt_bind_param($st, 'sssss', $company, $branch, $countedBy, $source, $day);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if ($row) {
        $id = (int)$row['id'];
        $u = mysqli_prepare($conn, 'UPDATE stock_takes SET last_count_at = GREATEST(last_count_at, ?) WHERE id = ?');
        if ($u) {
            mysqli_stmt_bind_param($u, 'si', $at, $id);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }
        return $id;
    }
    $ins = mysqli_prepare($conn, 'INSERT INTO stock_takes (company_name, branch, source, counted_by, take_date, started_at, last_count_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$ins) {
        return 0;
    }
    mysqli_stmt_bind_param($ins, 'sssssss', $company, $branch, $source, $countedBy, $day, $at, $at);
    $ok = mysqli_stmt_execute($ins);
    $id = $ok ? (int)mysqli_insert_id($conn) : 0;
    mysqli_stmt_close($ins);
    return $id;
}

/**
 * Save one counted line. Call inside the stock take's own transaction (after psStockTakeRecordsEnsureSchema() ran
 * BEFORE the transaction began: CREATE TABLE would commit it).
 * $line: product_id, product, system_qty, counted_qty, variance (decimal strings), controlled, movement_id.
 */
function psStockTakeRecordsSaveLine(mysqli $conn, string $company, string $branch, string $countedBy, string $source, array $line): bool
{
    $at = date('Y-m-d H:i:s');
    $takeId = psStockTakeRecordsTakeId($conn, $company, $branch, $countedBy, $source, $at);
    if ($takeId < 1) {
        return false;
    }
    $productId = (string)($line['product_id'] ?? '');
    $cost = null;
    $price = null;
    $g = mysqli_prepare($conn, 'SELECT price, cost_price FROM goods WHERE product_id = ? AND branch = ? LIMIT 1');
    if ($g) {
        mysqli_stmt_bind_param($g, 'ss', $productId, $branch);
        mysqli_stmt_execute($g);
        $gr = mysqli_fetch_assoc(mysqli_stmt_get_result($g));
        mysqli_stmt_close($g);
        if ($gr) {
            $price = is_numeric($gr['price'] ?? null) ? (string)$gr['price'] : null;
            $cost = is_numeric($gr['cost_price'] ?? null) ? (string)$gr['cost_price'] : null;
        }
    }
    $product = mb_substr((string)($line['product'] ?? ''), 0, 250);
    $system = isset($line['system_qty']) ? (string)$line['system_qty'] : null;
    $counted = isset($line['counted_qty']) ? (string)$line['counted_qty'] : null;
    $variance = (string)($line['variance'] ?? '0');
    $controlled = !empty($line['controlled']) ? 1 : 0;
    $movementId = !empty($line['movement_id']) ? (int)$line['movement_id'] : null;
    $ins = mysqli_prepare($conn, 'INSERT INTO stock_take_lines (take_id, product_id, product, system_qty, counted_qty, variance, unit_cost, unit_price, controlled, movement_id, counted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$ins) {
        return false;
    }
    mysqli_stmt_bind_param($ins, 'isssssssiis', $takeId, $productId, $product, $system, $counted, $variance, $cost, $price, $controlled, $movementId, $at);
    $ok = mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    return $ok;
}

/**
 * Older web stock takes (before 2026-11-03): only their differences exist, as ADJUSTMENT / STOCK_TAKE stock movements.
 * Each becomes a line of a HISTORY stock take (company from the branch). Idempotent (movement_id is unique).
 * Run before any transaction (it may create the tables).
 */
function psStockTakeRecordsBackfill(mysqli $conn): int
{
    if (!psStockTakeRecordsEnsureSchema($conn)) {
        return 0;
    }
    $res = mysqli_query($conn, "SELECT m.id, m.product_id, m.branch, m.quantity, m.movement_at, m.user_email, m.notes
        FROM stock_movements m
        LEFT JOIN stock_take_lines l ON l.movement_id = m.id
        WHERE m.movement_type = 'ADJUSTMENT' AND m.reference_type = 'STOCK_TAKE' AND l.id IS NULL
        ORDER BY m.id LIMIT 5000");
    if (!$res) {
        return 0;
    }
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    if (!$rows) {
        return 0;
    }
    $companyOf = [];
    $done = 0;
    foreach ($rows as $m) {
        $branch = (string)$m['branch'];
        if (!array_key_exists($branch, $companyOf)) {
            $st = mysqli_prepare($conn, 'SELECT company_name FROM branchies WHERE branch_name = ? LIMIT 1');
            mysqli_stmt_bind_param($st, 's', $branch);
            mysqli_stmt_execute($st);
            $companyOf[$branch] = (string)((mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: [])['company_name'] ?? '');
            mysqli_stmt_close($st);
        }
        $system = null;
        $counted = null;
        if (preg_match('/System:\s*(-?[\d.]+),\s*Counted:\s*(-?[\d.]+)/', (string)$m['notes'], $mm)) {
            $system = $mm[1];
            $counted = $mm[2];
        }
        $g = mysqli_prepare($conn, 'SELECT product, price, cost_price FROM goods WHERE product_id = ? AND branch = ? LIMIT 1');
        mysqli_stmt_bind_param($g, 'ss', $m['product_id'], $branch);
        mysqli_stmt_execute($g);
        $gr = mysqli_fetch_assoc(mysqli_stmt_get_result($g)) ?: [];
        mysqli_stmt_close($g);
        $product = (string)($gr['product'] ?? '');
        $price = is_numeric($gr['price'] ?? null) ? (string)$gr['price'] : null;   // today's prices: the old record kept none
        $cost = is_numeric($gr['cost_price'] ?? null) ? (string)$gr['cost_price'] : null;
        $at = (string)$m['movement_at'];
        $takeId = psStockTakeRecordsTakeIdAt($conn, $companyOf[$branch], $branch, (string)($m['user_email'] ?? ''), 'HISTORY', $at);
        if ($takeId < 1) {
            continue;
        }
        $ins = mysqli_prepare($conn, 'INSERT IGNORE INTO stock_take_lines (take_id, product_id, product, system_qty, counted_qty, variance, unit_cost, unit_price, controlled, movement_id, counted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
        $mid = (int)$m['id'];
        $qty = (string)$m['quantity'];
        mysqli_stmt_bind_param($ins, 'isssssssis', $takeId, $m['product_id'], $product, $system, $counted, $qty, $cost, $price, $mid, $at);
        if (mysqli_stmt_execute($ins)) {
            $done++;
        }
        mysqli_stmt_close($ins);
    }
    return $done;
}

/** Like psStockTakeRecordsTakeId() but for a past time (keeps started_at / last_count_at as the earliest / latest). */
function psStockTakeRecordsTakeIdAt(mysqli $conn, string $company, string $branch, string $countedBy, string $source, string $at): int
{
    $id = psStockTakeRecordsTakeId($conn, $company, $branch, $countedBy, $source, $at);
    if ($id > 0) {
        $u = mysqli_prepare($conn, 'UPDATE stock_takes SET started_at = LEAST(started_at, ?) WHERE id = ?');
        mysqli_stmt_bind_param($u, 'si', $at, $id);
        mysqli_stmt_execute($u);
        mysqli_stmt_close($u);
    }
    return $id;
}

/**
 * Stock takes of the signed-in company's branches with their totals.
 * $f: from, to (Y-m-d), branch ('' = all of the company's), person, source.
 */
function psStockTakeRecordsList(mysqli $conn, array $branches, array $f): array
{
    $in = psBranchSqlInList($branches);
    if ($in === null) {
        return [];
    }
    $sql = 'SELECT t.id, t.branch, t.source, t.counted_by, t.take_date, t.started_at, t.last_count_at,
            COUNT(l.id) AS line_count,
            SUM(l.variance <> 0) AS lines_diff,
            SUM(CASE WHEN l.variance < 0 THEN -l.variance ELSE 0 END) AS short_qty,
            SUM(CASE WHEN l.variance > 0 THEN l.variance ELSE 0 END) AS surplus_qty,
            SUM(CASE WHEN l.variance < 0 THEN -l.variance * COALESCE(l.unit_cost, 0) ELSE 0 END) AS short_cost,
            SUM(CASE WHEN l.variance > 0 THEN l.variance * COALESCE(l.unit_cost, 0) ELSE 0 END) AS surplus_cost,
            SUM(CASE WHEN l.variance < 0 THEN -l.variance * COALESCE(l.unit_price, 0) ELSE 0 END) AS short_price,
            SUM(CASE WHEN l.variance > 0 THEN l.variance * COALESCE(l.unit_price, 0) ELSE 0 END) AS surplus_price
        FROM stock_takes t JOIN stock_take_lines l ON l.take_id = t.id
        WHERE t.branch IN (' . $in['sql'] . ') AND t.take_date BETWEEN ? AND ?';
    $types = $in['types'] . 'ss';
    $params = array_merge($in['params'], [$f['from'], $f['to']]);
    if (($f['branch'] ?? '') !== '') {
        $sql .= ' AND t.branch = ?';
        $types .= 's';
        $params[] = $f['branch'];
    }
    if (($f['person'] ?? '') !== '') {
        $sql .= ' AND t.counted_by = ?';
        $types .= 's';
        $params[] = $f['person'];
    }
    if (($f['source'] ?? '') !== '') {
        $sql .= ' AND t.source = ?';
        $types .= 's';
        $params[] = $f['source'];
    }
    $sql .= ' GROUP BY t.id ORDER BY t.take_date DESC, t.id DESC LIMIT 1000';
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        error_log('stock take records list failed: ' . mysqli_error($conn));
        return [];
    }
    mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    mysqli_stmt_close($st);
    return $rows;
}

/** One stock take with its lines, only when its branch is one of $branches. */
function psStockTakeRecordsGet(mysqli $conn, array $branches, int $id): ?array
{
    $st = mysqli_prepare($conn, 'SELECT * FROM stock_takes WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $take = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$take || !psBranchNameInList((string)$take['branch'], $branches)) {
        return null;
    }
    $st = mysqli_prepare($conn, 'SELECT * FROM stock_take_lines WHERE take_id = ? ORDER BY counted_at, id');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $take['lines'] = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    mysqli_stmt_close($st);
    return $take;
}

/** People who did stock takes at these branches (for the filter). */
function psStockTakeRecordsPeople(mysqli $conn, array $branches): array
{
    $in = psBranchSqlInList($branches);
    if ($in === null) {
        return [];
    }
    $st = mysqli_prepare($conn, 'SELECT DISTINCT counted_by FROM stock_takes WHERE branch IN (' . $in['sql'] . ") AND counted_by <> '' ORDER BY counted_by");
    mysqli_stmt_bind_param($st, $in['types'], ...$in['params']);
    mysqli_stmt_execute($st);
    $out = array_column(mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC), 'counted_by');
    mysqli_stmt_close($st);
    return $out;
}
