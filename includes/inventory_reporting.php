<?php
/**
 * Phase 10 — Inventory reporting (read-only).
 *
 * Ledger-controlled (OPENING_BALANCE present): on-hand = SUM(stock_movements).
 * Non-ledger: goods.quantity shown as legacy display only and labelled.
 * Low/out of stock: goods.reorder_level (authoritative).
 * Valuation: ledger qty × goods.cost_price only when cost > 0; otherwise omitted/incomplete.
 */

require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/stock_movement.php';

/**
 * Current stock rows for authorised branches (product_id + branch identity).
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,rows?:list,totals?:array,pagination?:array,limitations?:list,error?:string}
 */
function psInventoryReportingStockOnHand(mysqli $conn, array $filters): array
{
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Stock report could not be loaded.'];
    }
    $page = psReportingNormalizePage($filters['page'] ?? 1, $filters['page_size'] ?? 50);
    $branches = $filters['branches'] ?? [];
    $all = !empty($filters['all_branches']);
    $productId = trim((string)($filters['product_id'] ?? ''));
    $stockFilter = strtolower(trim((string)($filters['stock_filter'] ?? 'all')));
    // all | low | out | ledger | legacy

    $where = 'WHERE g.product_id <> \'\'';
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params, $branches, $all, 'g.branch'
    );
    if ($productId !== '') {
        $where .= ' AND g.product_id = ?';
        $types .= 's';
        $params[] = $productId;
    }

    // Ledger balances: products with OPENING_BALANCE per product_id+branch
    $ledgerSql = 'SELECT sm.product_id, sm.branch, SUM(sm.quantity) AS balance
                  FROM stock_movements sm
                  WHERE EXISTS (
                      SELECT 1 FROM stock_movements ob
                      WHERE ob.product_id = sm.product_id AND ob.branch = sm.branch
                        AND ob.movement_type = \'OPENING_BALANCE\'
                  )';
    $ledgerTypes = '';
    $ledgerParams = [];
    [$ledgerSql, $ledgerTypes, $ledgerParams] = psReportingAppendBranchFilter(
        $ledgerSql, $ledgerTypes, $ledgerParams, $branches, $all, 'sm.branch'
    );
    $ledgerSql .= ' GROUP BY sm.product_id, sm.branch';

    $ledgerMap = []; // "product_id\0branch" => qty
    $stmt = mysqli_prepare($conn, $ledgerSql);
    if ($stmt) {
        if ($ledgerTypes !== '') {
            mysqli_stmt_bind_param($stmt, $ledgerTypes, ...$ledgerParams);
        }
        if (mysqli_stmt_execute($stmt)) {
            $lres = mysqli_stmt_get_result($stmt);
            while ($lres && ($row = mysqli_fetch_assoc($lres))) {
                $bal = psStockDecimal($row['balance'] ?? '0');
                if ($bal === null) {
                    continue;
                }
                $key = (string)$row['product_id'] . "\0" . (string)$row['branch'];
                $ledgerMap[$key] = psStockQtyPlain($bal);
            }
        }
        mysqli_stmt_close($stmt);
    }

    $countSql = 'SELECT COUNT(*) AS c FROM goods g ' . $where;
    $stmt = mysqli_prepare($conn, $countSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Stock report could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Stock report could not be loaded.'];
    }
    // We filter in PHP for low/out/ledger after load — for efficiency load candidates.
    // For large catalogues, apply stock_filter in SQL where possible for out/low after join.
    mysqli_stmt_close($stmt);

    $listSql = 'SELECT g.id, g.product_id, g.product, g.branch, g.quantity, g.reorder_level,
                       g.cost_price, g.status
                FROM goods g
                ' . $where . '
                ORDER BY g.branch ASC, g.product ASC, g.product_id ASC
                LIMIT 5000';
    $stmt = mysqli_prepare($conn, $listSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Stock report could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Stock report could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $allRows = [];
    $lowCount = 0;
    $outCount = 0;
    $ledgerCount = 0;
    $legacyCount = 0;
    $valued = '0.00';
    $valuedLines = 0;
    $unvaluedLedger = 0;

    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $pid = (string)$row['product_id'];
        $br = (string)$row['branch'];
        $key = $pid . "\0" . $br;
        $isLedger = array_key_exists($key, $ledgerMap);
        $qty = $isLedger ? $ledgerMap[$key] : number_format((float)($row['quantity'] ?? 0), 3, '.', '');
        $reorderRaw = $row['reorder_level'];
        $reorder = ($reorderRaw === null || $reorderRaw === '')
            ? null
            : number_format((float)$reorderRaw, 3, '.', '');
        $qtyF = (float)$qty;
        $isOut = $qtyF <= 0;
        $isLow = (!$isOut && $reorder !== null && $qtyF <= (float)$reorder);
        if ($isOut) {
            $outCount++;
        } elseif ($isLow) {
            $lowCount++;
        }
        if ($isLedger) {
            $ledgerCount++;
        } else {
            $legacyCount++;
        }

        $cost = (float)($row['cost_price'] ?? 0);
        $lineValue = null;
        if ($isLedger && $cost > 0) {
            $lineValue = psReportingMoneyFormat($qtyF * $cost);
            $valued = psReportingMoneyAdd($valued, $lineValue);
            $valuedLines++;
        } elseif ($isLedger) {
            $unvaluedLedger++;
        }

        if ($stockFilter === 'low' && !$isLow) {
            continue;
        }
        if ($stockFilter === 'out' && !$isOut) {
            continue;
        }
        if ($stockFilter === 'ledger' && !$isLedger) {
            continue;
        }
        if ($stockFilter === 'legacy' && $isLedger) {
            continue;
        }

        $allRows[] = [
            'goods_id' => (int)$row['id'],
            'product_id' => $pid,
            'product' => (string)$row['product'],
            'branch' => $br,
            'qty' => $qty,
            'qty_source' => $isLedger ? 'ledger' : 'legacy_goods_quantity',
            'reorder_level' => $reorder,
            'is_low' => $isLow,
            'is_out' => $isOut,
            'cost_price' => $cost > 0 ? psReportingMoneyFormat($cost) : null,
            'stock_value' => $lineValue,
            'status' => (string)($row['status'] ?? ''),
        ];
    }
    mysqli_stmt_close($stmt);

    $totalRows = count($allRows);
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $page['page_size']) : 1;
    if ($page['page'] > $totalPages) {
        $page['page'] = $totalPages;
        $page['offset'] = ($page['page'] - 1) * $page['page_size'];
    }
    $slice = array_slice($allRows, $page['offset'], $page['page_size']);

    // 2026-10-21: product costs are kept in the company base currency, so the valuation is a base amount.
    require_once __DIR__ . '/currency.php';
    $invBaseCur = psFxBase($conn, psFxSessionCompany());
    $valuationNote = 'Amounts in ' . $invBaseCur . ' (product cost prices are kept in the base currency; quantities are never converted). '
        . 'Stock valuation includes ledger-controlled products with cost_price > 0 only ('
        . $valuedLines . ' lines). '
        . ($unvaluedLedger > 0
            ? ($unvaluedLedger . ' ledger-controlled lines lack defensible cost and are excluded from value.')
            : '');
    if ($ledgerCount === 0) {
        $valuationNote = 'No ledger-controlled products in scope (OPENING_BALANCE absent). Stock valuation omitted.';
        $valued = null;
    }

    return [
        'ok' => true,
        'rows' => $slice,
        'totals' => [
            'row_count' => $totalRows,
            'low_count' => $lowCount,
            'out_count' => $outCount,
            'ledger_count' => $ledgerCount,
            'legacy_count' => $legacyCount,
            'stock_value' => $valued,
            'valued_lines' => $valuedLines,
            'unvalued_ledger_lines' => $unvaluedLedger,
        ],
        'pagination' => [
            'page' => $page['page'],
            'page_size' => $page['page_size'],
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'limitations' => [
            'Ledger-controlled on-hand uses SUM(stock_movements) when OPENING_BALANCE exists for product_id+branch.',
            'Non-ledger rows show goods.quantity as legacy display only — not authoritative for controlled products.',
            'Low/out uses goods.reorder_level.',
            $valuationNote,
        ],
        'valuation_note' => $valuationNote,
    ];
}

/**
 * Stock movement history (paginated), branch-scoped.
 *
 * @return array{ok:bool,rows?:list,pagination?:array,error?:string}
 */
function psInventoryReportingMovements(mysqli $conn, array $filters): array
{
    if (!stockMovementEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Stock movements could not be loaded.'];
    }
    $page = psReportingNormalizePage($filters['page'] ?? 1, $filters['page_size'] ?? 50);
    $where = 'WHERE 1=1';
    $types = '';
    $params = [];
    [$where, $types, $params] = psReportingAppendBranchFilter(
        $where, $types, $params,
        $filters['branches'] ?? [], !empty($filters['all_branches']), 'sm.branch'
    );
    $productId = trim((string)($filters['product_id'] ?? ''));
    if ($productId !== '') {
        $where .= ' AND sm.product_id = ?';
        $types .= 's';
        $params[] = $productId;
    }
    $movType = strtoupper(trim((string)($filters['movement_type'] ?? '')));
    if ($movType !== '' && $movType !== 'ALL') {
        $where .= ' AND sm.movement_type = ?';
        $types .= 's';
        $params[] = $movType;
    }
    $movDateExpr = 'DATE(COALESCE(sm.movement_at, sm.created_at))';
    if (!empty($filters['from_date'])) {
        $where .= ' AND ' . $movDateExpr . ' >= ?';
        $types .= 's';
        $params[] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where .= ' AND ' . $movDateExpr . ' <= ?';
        $types .= 's';
        $params[] = $filters['to_date'];
    }

    $countSql = 'SELECT COUNT(*) AS c FROM stock_movements sm ' . $where;
    $stmt = mysqli_prepare($conn, $countSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Stock movements could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Stock movements could not be loaded.'];
    }
    $cres = mysqli_stmt_get_result($stmt);
    $crow = $cres ? mysqli_fetch_assoc($cres) : null;
    mysqli_stmt_close($stmt);
    $totalRows = (int)($crow['c'] ?? 0);
    $totalPages = $totalRows > 0 ? (int)ceil($totalRows / $page['page_size']) : 1;
    if ($page['page'] > $totalPages) {
        $page['page'] = $totalPages;
        $page['offset'] = ($page['page'] - 1) * $page['page_size'];
    }

    // Prefer movement_at when present; created_at is also on schema. User column is user_email.
    $listSql = 'SELECT sm.id, sm.product_id, sm.branch, sm.movement_type, sm.quantity,
                       sm.reference_type, sm.reference_id,
                       COALESCE(sm.movement_at, sm.created_at) AS movement_time,
                       sm.user_email
                FROM stock_movements sm
                ' . $where . '
                ORDER BY COALESCE(sm.movement_at, sm.created_at) DESC, sm.id DESC
                LIMIT ' . (int)$page['page_size'] . ' OFFSET ' . (int)$page['offset'];
    $stmt = mysqli_prepare($conn, $listSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Stock movements could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Stock movements could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $qty = psStockDecimal($row['quantity'] ?? '0');
        $rows[] = [
            'id' => (int)$row['id'],
            'product_id' => (string)$row['product_id'],
            'branch' => (string)$row['branch'],
            'movement_type' => (string)$row['movement_type'],
            'quantity' => $qty !== null ? psStockQtyPlain($qty) : (string)$row['quantity'],
            'reference_type' => (string)($row['reference_type'] ?? ''),
            'reference_id' => (string)($row['reference_id'] ?? ''),
            'created_at' => (string)($row['movement_time'] ?? ''),
            'created_by' => (string)($row['user_email'] ?? ''),
        ];
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'rows' => $rows,
        'pagination' => [
            'page' => $page['page'],
            'page_size' => $page['page_size'],
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ],
        'note' => 'Movement history is ledger-only. Opening, sales, refunds, transfers, adjustments, GRV, and stock takes appear when recorded as stock_movements rows.',
    ];
}

/**
 * Low / out-of-stock summary counts (reuses stock on hand helpers).
 *
 * @return array{ok:bool,low_count?:int,out_count?:int,error?:string}
 */
function psInventoryReportingAlerts(mysqli $conn, array $filters): array
{
    $filters['stock_filter'] = 'all';
    $filters['page'] = 1;
    $filters['page_size'] = 1;
    $res = psInventoryReportingStockOnHand($conn, $filters);
    if (empty($res['ok'])) {
        return $res;
    }
    return [
        'ok' => true,
        'low_count' => (int)($res['totals']['low_count'] ?? 0),
        'out_count' => (int)($res['totals']['out_count'] ?? 0),
        'ledger_count' => (int)($res['totals']['ledger_count'] ?? 0),
        'stock_value' => $res['totals']['stock_value'] ?? null,
        'valuation_note' => $res['valuation_note'] ?? '',
    ];
}

/**
 * CSV export.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,filename?:string,headers?:list,rows?:list,error?:string}
 */
function psInventoryReportingExportCsv(mysqli $conn, string $kind, array $rawFilters = []): array
{
    $norm = psReportingNormalizeFilters($conn, $rawFilters);
    if (empty($norm['ok'])) {
        return ['ok' => false, 'error' => $norm['error'] ?? 'Export failed.'];
    }
    $f = $norm['filters'];
    $f['page'] = 1;
    $f['page_size'] = 5000;
    $f['stock_filter'] = strtolower(trim((string)($rawFilters['stock_filter'] ?? 'all')));
    $f['movement_type'] = (string)($rawFilters['movement_type'] ?? '');
    $kind = strtolower(trim($kind));

    if ($kind === 'stock' || $kind === 'on_hand') {
        $res = psInventoryReportingStockOnHand($conn, $f);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        // Export all filtered (already capped at 5000 in helper via list LIMIT; re-fetch without slice)
        $f['page_size'] = 5000;
        $f['page'] = 1;
        $res = psInventoryReportingStockOnHand($conn, $f);
        $headers = [
            'Product ID', 'Product', 'Branch', 'Qty', 'Qty source', 'Reorder level',
            'Low', 'Out', 'Cost (' . psFxBase($conn, psFxSessionCompany()) . ')', 'Stock value (' . psFxBase($conn, psFxSessionCompany()) . ')', 'Status',
        ];
        $lines = [];
        // Need full filtered set — helper paginates; call with large page
        foreach ($res['rows'] as $row) {
            $lines[] = [
                $row['product_id'], $row['product'], $row['branch'], $row['qty'],
                $row['qty_source'], $row['reorder_level'] ?? '',
                $row['is_low'] ? 'Y' : 'N', $row['is_out'] ? 'Y' : 'N',
                $row['cost_price'] ?? '', $row['stock_value'] ?? '', $row['status'],
            ];
        }
        return ['ok' => true, 'filename' => 'inventory_stock.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'movements') {
        $res = psInventoryReportingMovements($conn, $f);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'ID', 'Product ID', 'Branch', 'Type', 'Quantity', 'Reference type',
            'Reference ID', 'Created at', 'Created by',
        ];
        $lines = [];
        foreach ($res['rows'] as $row) {
            $lines[] = [
                $row['id'], $row['product_id'], $row['branch'], $row['movement_type'],
                $row['quantity'], $row['reference_type'], $row['reference_id'],
                $row['created_at'], $row['created_by'],
            ];
        }
        return ['ok' => true, 'filename' => 'inventory_movements.csv', 'headers' => $headers, 'rows' => $lines];
    }
    return ['ok' => false, 'error' => 'Export type is not valid.'];
}
