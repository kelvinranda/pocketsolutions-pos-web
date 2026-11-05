<?php
/**
 * Phase 4D — Purchasing history / reports helpers.
 *
 * Balance decision: legacy supplier_invoices is NOT authoritative payable.
 * No payment / AP model exists against supplier_master + GRV/PO.
 * These helpers expose purchasing activity value (PO / GRV / returns), not "balance owed".
 */

require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/supplier_master.php';
require_once __DIR__ . '/purchase_order.php';
require_once __DIR__ . '/goods_receipt.php';
require_once __DIR__ . '/purchase_return.php';

/**
 * Resolve report branch filter with server-side security.
 * Non-admin: forced to trusted working branch (Phase 9.2).
 * Admin: optional branch; empty string means all authorised branches.
 * Unauthorised / invalid selected_branch fails closed (no silent foreign fallback).
 *
 * @return array{ok:bool,branch?:string,all_branches?:bool,error?:string}
 */
/**
 * 2026-10-18: "All branches" means all of THIS company's branches, never other companies'.
 * Returns "<col> IN (...)" for the signed-in company's authorised branches ('0' when there are none).
 */
function psPurchasingCompanyBranchesSql(mysqli $conn, string $col): string
{
    return psCompanyBranchesInSql($conn, $col);
}

function psPurchasingResolveReportBranch(mysqli $conn, $postedBranch = null): array
{
    require_once __DIR__ . '/branch_access.php';
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
    if (!psSessionAllowsAllProductBranches()) {
        return ['ok' => true, 'branch' => $sessionBranch, 'all_branches' => false];
    }
    $posted = trim((string)($postedBranch ?? ''));
    if ($posted === '' || strcasecmp($posted, 'all') === 0) {
        return ['ok' => true, 'branch' => '', 'all_branches' => true];
    }
    if (!psProductBranchIsValid($conn, $posted) || !psProductBranchIsAuthorised($conn, $posted)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    return ['ok' => true, 'branch' => $posted, 'all_branches' => false];
}

/**
 * Supplier purchasing history by supplier_master.id (identity by ID).
 * Summaries are purchasing values — not payable balance.
 *
 * @return array{ok:bool,supplier?:array,summary?:array,pos?:array,grvs?:array,returns?:array,error?:string,balance_note?:string}
 */
function psSupplierPurchasingHistory(mysqli $conn, int $supplierId, array $filters = []): array
{
    if ($supplierId < 1) {
        return ['ok' => false, 'error' => 'Supplier was not found.'];
    }
    if (!psEnsureSupplierMasterSchema($conn) || !psEnsurePurchaseOrderAllSchema($conn)
        || !psEnsureGoodsReceiptAllSchema($conn) || !psEnsurePurchaseReturnAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchasing history could not be loaded.'];
    }
    $supplier = psSupplierById($conn, $supplierId);
    if ($supplier === null) {
        return ['ok' => false, 'error' => 'Supplier was not found.'];
    }

    $branchRes = psPurchasingResolveReportBranch($conn, $filters['branch'] ?? null);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
    }
    $branch = (string)($branchRes['branch'] ?? '');
    $allBranches = !empty($branchRes['all_branches']);

    $from = trim((string)($filters['from_date'] ?? ''));
    $to = trim((string)($filters['to_date'] ?? ''));
    // 2026-10-21: per-currency totals; consolidated base from each document's stored base total / rate.
    require_once __DIR__ . '/fx_report.php';
    $ctx = $filters['fx'] ?? psFxrContext($conn, []);
    $poMix = $grvMix = $retMix = [];

    // POs
    $poSql = 'SELECT * FROM purchase_orders WHERE supplier_id = ?';
    $poTypes = 'i';
    $poParams = [$supplierId];
    if (!$allBranches) {
        $poSql .= ' AND branch = ?';
        $poTypes .= 's';
        $poParams[] = $branch;
    } else {
        $poSql .= ' AND ' . psPurchasingCompanyBranchesSql($conn, 'branch');
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $poSql .= ' AND order_date >= ?';
        $poTypes .= 's';
        $poParams[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $poSql .= ' AND order_date <= ?';
        $poTypes .= 's';
        $poParams[] = $to;
    }
    $poSql .= ' ORDER BY order_date DESC, id DESC LIMIT 500';
    $pos = [];
    $poCount = 0;
    $poValue = '0.00';
    $latestPo = null;
    $stmt = mysqli_prepare($conn, $poSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $poTypes, ...$poParams);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($res && $row = mysqli_fetch_assoc($res)) {
                $lines = psPurchaseOrderLines($conn, (int)$row['id']);
                $hdrTotal = '0.00';
                foreach ($lines as $line) {
                    if (function_exists('bcadd')) {
                        $hdrTotal = bcadd($hdrTotal, (string)$line['line_total'], 2);
                    } else {
                        $hdrTotal = number_format((float)$hdrTotal + (float)$line['line_total'], 2, '.', '');
                    }
                }
                $row['_total'] = $hdrTotal;
                if (!psFxrRowMatches($ctx, $row['currency'] ?? null)) {
                    continue;
                }
                $poRate = (float)($row['fx_rate'] ?? 0);
                $row['_base_total'] = $poRate > 0 ? number_format(round((float)$hdrTotal / $poRate, 2), 2, '.', '') : null;
                psFxrAdd($poMix, $ctx, $row['currency'] ?? null, $hdrTotal, $row['_base_total'], ['rate' => (string)($row['fx_rate'] ?? ''), 'ids' => (string)($row['fx_rate_ids'] ?? '')]);
                $pos[] = $row;
                $poCount++;
                if (function_exists('bcadd')) {
                    $poValue = bcadd($poValue, $hdrTotal, 2);
                } else {
                    $poValue = number_format((float)$poValue + (float)$hdrTotal, 2, '.', '');
                }
                $d = (string)$row['order_date'];
                if ($latestPo === null || strcmp($d, $latestPo) > 0) {
                    $latestPo = $d;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }

    // GRVs linked by supplier_id (reliable master identity only)
    $grvSql = 'SELECT r.*, po.po_number
               FROM goods_receipts_ref r
               LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
               WHERE r.supplier_id = ?';
    $grvTypes = 'i';
    $grvParams = [$supplierId];
    if (!$allBranches) {
        $grvSql .= ' AND r.branch = ?';
        $grvTypes .= 's';
        $grvParams[] = $branch;
    } else {
        $grvSql .= ' AND ' . psPurchasingCompanyBranchesSql($conn, 'r.branch');
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $grvSql .= ' AND r.date_ >= ?';
        $grvTypes .= 's';
        $grvParams[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $grvSql .= ' AND r.date_ <= ?';
        $grvTypes .= 's';
        $grvParams[] = $to;
    }
    $grvSql .= ' ORDER BY r.id DESC LIMIT 500';
    $grvs = [];
    $grvCount = 0;
    $grvValue = '0.00';
    $latestGrv = null;
    $stmt = mysqli_prepare($conn, $grvSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $grvTypes, ...$grvParams);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($res && $row = mysqli_fetch_assoc($res)) {
                if (!psFxrRowMatches($ctx, $row['currency'] ?? null)) {
                    continue;
                }
                $grvs[] = $row;
                $grvCount++;
                psFxrAdd($grvMix, $ctx, $row['currency'] ?? null, (string)($row['totalz'] ?? '0'), $row['base_total'] ?? null, ['rate' => (string)($row['fx_rate'] ?? ''), 'ids' => (string)($row['fx_rate_ids'] ?? '')]);
                // Multi-currency: value in the company base (each receipt at its own rate; older receipts are base).
                $tv = psStockDecimal((string)((($row['base_total'] ?? null) !== null && $row['base_total'] !== '') ? $row['base_total'] : ($row['totalz'] ?? '0')), 2);
                if ($tv === null) {
                    $tv = '0.00';
                }
                if (function_exists('bcadd')) {
                    $grvValue = bcadd($grvValue, $tv, 2);
                } else {
                    $grvValue = number_format((float)$grvValue + (float)$tv, 2, '.', '');
                }
                $d = (string)($row['date_'] ?? '');
                if ($d !== '' && ($latestGrv === null || strcmp($d, $latestGrv) > 0)) {
                    $latestGrv = $d;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }

    // Returns by supplier_id
    $retSql = 'SELECT * FROM purchase_returns WHERE supplier_id = ?';
    $retTypes = 'i';
    $retParams = [$supplierId];
    if (!$allBranches) {
        $retSql .= ' AND branch = ?';
        $retTypes .= 's';
        $retParams[] = $branch;
    } else {
        $retSql .= ' AND ' . psPurchasingCompanyBranchesSql($conn, 'branch');
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $retSql .= ' AND return_date >= ?';
        $retTypes .= 's';
        $retParams[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $retSql .= ' AND return_date <= ?';
        $retTypes .= 's';
        $retParams[] = $to;
    }
    $retSql .= ' ORDER BY id DESC LIMIT 500';
    $returns = [];
    $returnValue = '0.00';
    $latestReturn = null;
    $stmt = mysqli_prepare($conn, $retSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $retTypes, ...$retParams);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($res && $row = mysqli_fetch_assoc($res)) {
                if (!psFxrRowMatches($ctx, $row['currency'] ?? null)) {
                    continue;
                }
                $returns[] = $row;
                psFxrAdd($retMix, $ctx, $row['currency'] ?? null, (string)($row['total'] ?? '0'), $row['base_total'] ?? null, ['rate' => (string)($row['fx_rate'] ?? ''), 'ids' => (string)($row['fx_rate_ids'] ?? '')]);
                $tv = (string)($row['total'] ?? '0.00');
                if (function_exists('bcadd')) {
                    $returnValue = bcadd($returnValue, $tv, 2);
                } else {
                    $returnValue = number_format((float)$returnValue + (float)$tv, 2, '.', '');
                }
                $d = (string)($row['return_date'] ?? '');
                if ($d !== '' && ($latestReturn === null || strcmp($d, $latestReturn) > 0)) {
                    $latestReturn = $d;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }

    $netReceived = '0.00';
    if (function_exists('bcsub')) {
        $netReceived = bcsub($grvValue, $returnValue, 2);
    } else {
        $netReceived = number_format((float)$grvValue - (float)$returnValue, 2, '.', '');
    }

    $latest = null;
    foreach ([$latestPo, $latestGrv, $latestReturn] as $d) {
        if ($d !== null && ($latest === null || strcmp($d, $latest) > 0)) {
            $latest = $d;
        }
    }

    $netMix = $grvMix;
    psFxrMerge($netMix, psFxrNegate($retMix));
    return [
        'ok' => true,
        'supplier' => $supplier,
        'summary' => [
            'po_count' => count($pos),
            // Consolidated base (each document at its stored rate); per-currency mixes in fx_*.
            'po_value' => psFxrMoney(psFxrBase($poMix)),
            'grv_count' => $grvCount,
            'grv_value' => psFxrMoney(psFxrBase($grvMix)),
            'return_value' => psFxrMoney(psFxrBase($retMix)),
            'net_received_value' => psFxrMoney(psFxrBase($netMix)),
            'latest_date' => $latest,
            'fx_po' => $poMix,
            'fx_grv' => $grvMix,
            'fx_return' => $retMix,
            'fx_net' => $netMix,
        ],
        'pos' => $pos,
        'grvs' => $grvs,
        'returns' => $returns,
        'balance_note' => 'No authoritative supplier payable/payment model is linked to supplier_master. '
            . 'Values shown are purchasing activity (ordered / received / returned), not balance owed. '
            . 'Legacy supplier_invoices is not authoritative.',
    ];
}

/**
 * PO report rows with ordered/received/remaining (returns do not reopen outstanding).
 *
 * @return array{ok:bool,rows?:array,error?:string}
 */
function psPurchasingPoReport(mysqli $conn, array $filters = []): array
{
    if (!psEnsurePurchaseOrderAllSchema($conn) || !psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase order report could not be loaded.', 'rows' => []];
    }
    $branchRes = psPurchasingResolveReportBranch($conn, $filters['branch'] ?? null);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $sql = 'SELECT po.*, sm.supplier_code, sm.supplier_name
            FROM purchase_orders po
            LEFT JOIN supplier_master sm ON sm.id = po.supplier_id
            WHERE 1=1';
    $types = '';
    $params = [];
    if (empty($branchRes['all_branches'])) {
        $sql .= ' AND po.branch = ?';
        $types .= 's';
        $params[] = $branchRes['branch'];
    } else {
        $sql .= ' AND ' . psPurchasingCompanyBranchesSql($conn, 'po.branch');
    }
    $status = strtolower(trim((string)($filters['status'] ?? 'all')));
    if (in_array($status, ['draft', 'approved', 'cancelled', 'closed'], true)) {
        $sql .= ' AND po.status = ?';
        $types .= 's';
        $params[] = strtoupper($status);
    }
    $supplierId = (int)($filters['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        $sql .= ' AND po.supplier_id = ?';
        $types .= 'i';
        $params[] = $supplierId;
    }
    $from = trim((string)($filters['from_date'] ?? ''));
    $to = trim((string)($filters['to_date'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND po.order_date >= ?';
        $types .= 's';
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND po.order_date <= ?';
        $types .= 's';
        $params[] = $to;
    }
    $sql .= ' ORDER BY po.order_date DESC, po.id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Purchase order report could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase order report could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $prog = psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, (int)$row['id']));
        $ord = '0';
        $rec = '0';
        $rem = '0';
        $ordVal = '0.00';
        foreach ($prog as $lp) {
            if (function_exists('bcadd')) {
                $ord = bcadd($ord, (string)$lp['ordered_qty'], 3);
                $rec = bcadd($rec, (string)$lp['received_qty'], 3);
                $rem = bcadd($rem, (string)$lp['remaining_qty'], 3);
                $ordVal = bcadd($ordVal, (string)$lp['line_total'], 2);
            } else {
                $ord = (string)((float)$ord + (float)$lp['ordered_qty']);
                $rec = (string)((float)$rec + (float)$lp['received_qty']);
                $rem = (string)((float)$rem + (float)$lp['remaining_qty']);
                $ordVal = number_format((float)$ordVal + (float)$lp['line_total'], 2, '.', '');
            }
        }
        $row['_ordered_qty'] = $ord;
        $row['_received_qty'] = $rec;
        $row['_remaining_qty'] = $rem;
        $row['_ordered_value'] = $ordVal;
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * GRV report (header-level; values from goods_receipts_ref — do not double-count with line sums in UI).
 *
 * @return array{ok:bool,rows?:array,error?:string}
 */
function psPurchasingGrvReport(mysqli $conn, array $filters = []): array
{
    if (!psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Goods receipt report could not be loaded.', 'rows' => []];
    }
    $branchRes = psPurchasingResolveReportBranch($conn, $filters['branch'] ?? null);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $sql = 'SELECT r.*, po.po_number, sm.supplier_code
            FROM goods_receipts_ref r
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            LEFT JOIN supplier_master sm ON sm.id = r.supplier_id
            WHERE 1=1';
    $types = '';
    $params = [];
    if (empty($branchRes['all_branches'])) {
        $sql .= ' AND r.branch = ?';
        $types .= 's';
        $params[] = $branchRes['branch'];
    } else {
        $sql .= ' AND ' . psPurchasingCompanyBranchesSql($conn, 'r.branch');
    }
    $supplierId = (int)($filters['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        $sql .= ' AND r.supplier_id = ?';
        $types .= 'i';
        $params[] = $supplierId;
    }
    $from = trim((string)($filters['from_date'] ?? ''));
    $to = trim((string)($filters['to_date'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND r.date_ >= ?';
        $types .= 's';
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND r.date_ <= ?';
        $types .= 's';
        $params[] = $to;
    }
    $grv = trim((string)($filters['grv_number'] ?? ''));
    if ($grv !== '') {
        $sql .= ' AND r.grv_number = ?';
        $types .= 's';
        $params[] = $grv;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Goods receipt report could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Goods receipt report could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Purchase return report.
 *
 * @return array{ok:bool,rows?:array,error?:string}
 */
function psPurchasingReturnReport(mysqli $conn, array $filters = []): array
{
    if (!psEnsurePurchaseReturnAllSchema($conn)) {
        return ['ok' => false, 'error' => 'Purchase return report could not be loaded.', 'rows' => []];
    }
    $branchRes = psPurchasingResolveReportBranch($conn, $filters['branch'] ?? null);
    if (!$branchRes['ok']) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.', 'rows' => []];
    }
    $sql = 'SELECT pr.*, sm.supplier_code
            FROM purchase_returns pr
            LEFT JOIN supplier_master sm ON sm.id = pr.supplier_id
            WHERE 1=1';
    $types = '';
    $params = [];
    if (empty($branchRes['all_branches'])) {
        $sql .= ' AND pr.branch = ?';
        $types .= 's';
        $params[] = $branchRes['branch'];
    } else {
        $sql .= ' AND ' . psPurchasingCompanyBranchesSql($conn, 'pr.branch');
    }
    $supplierId = (int)($filters['supplier_id'] ?? 0);
    if ($supplierId > 0) {
        $sql .= ' AND pr.supplier_id = ?';
        $types .= 'i';
        $params[] = $supplierId;
    }
    $from = trim((string)($filters['from_date'] ?? ''));
    $to = trim((string)($filters['to_date'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND pr.return_date >= ?';
        $types .= 's';
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND pr.return_date <= ?';
        $types .= 's';
        $params[] = $to;
    }
    $grv = trim((string)($filters['grv_number'] ?? ''));
    if ($grv !== '') {
        $sql .= ' AND pr.grv_number = ?';
        $types .= 's';
        $params[] = $grv;
    }
    $sql .= ' ORDER BY pr.id DESC LIMIT 500';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Purchase return report could not be loaded.', 'rows' => []];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Purchase return report could not be loaded.', 'rows' => []];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Phase 10 — CSV export for purchasing reports (authorised scope only).
 *
 * @param array<string,mixed> $filters
 * @return array{ok:bool,filename?:string,headers?:list,rows?:list,error?:string}
 */
function psPurchasingReportExportCsv(mysqli $conn, string $kind, array $filters = []): array
{
    require_once __DIR__ . '/reporting.php';
    $kind = strtolower(trim($kind));
    require_once __DIR__ . '/fx_report.php';
    $ctx = psFxrContext($conn, $filters + $_GET);
    $keepRow = fn($r) => psFxrRowMatches($ctx, $r['currency'] ?? null);
    if ($kind === 'po') {
        $res = psPurchasingPoReport($conn, $filters);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'PO #', 'Supplier code', 'Supplier name', 'Status', 'Branch', 'Order date',
            'Ordered qty', 'Received qty', 'Outstanding qty',
        ];
        $headers = array_merge($headers, psFxrCsvHeaders($ctx));
        $lines = [];
        foreach (array_filter($res['rows'], $keepRow) as $row) {
            $poRate = (float)($row['fx_rate'] ?? 0);
            $lines[] = [
                (string)$row['po_number'],
                (string)($row['supplier_code'] ?? ''),
                (string)($row['supplier_name'] ?? ''),
                (string)$row['status'],
                (string)$row['branch'],
                (string)$row['order_date'],
                (string)$row['_ordered_qty'],
                (string)$row['_received_qty'],
                (string)$row['_remaining_qty'],
            ];
            $lines[count($lines) - 1] = array_merge($lines[count($lines) - 1], psFxrCsvValues($ctx, $row['currency'] ?? null, (string)$row['_ordered_value'],
                $poRate > 0 ? number_format(round((float)$row['_ordered_value'] / $poRate, 2), 2, '.', '') : null, (string)($row['fx_rate'] ?? ''), (string)($row['fx_rate_ids'] ?? '')));
        }
        return ['ok' => true, 'filename' => 'purchasing_po_report.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'grv') {
        $res = psPurchasingGrvReport($conn, $filters);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'GRV #', 'PO #', 'Supplier code', 'Supplier', 'Supplier invoice',
            'Date', 'Branch', 'User',
        ];
        $headers = array_merge($headers, psFxrCsvHeaders($ctx));
        $lines = [];
        foreach (array_filter($res['rows'], $keepRow) as $row) {
            $lines[] = [
                (string)($row['grv_number'] ?? ''),
                (string)($row['po_number'] ?? ''),
                (string)($row['supplier_code'] ?? ''),
                (string)($row['product'] ?? ''),
                (string)($row['reference1'] ?? ''),
                (string)$row['date_'],
                (string)$row['branch'],
                (string)($row['created_by'] ?? ''),
            ];
            $lines[count($lines) - 1] = array_merge($lines[count($lines) - 1], psFxrCsvValues($ctx, $row['currency'] ?? null, (string)$row['totalz'], $row['base_total'] ?? null, (string)($row['fx_rate'] ?? ''), (string)($row['fx_rate_ids'] ?? '')));
        }
        return ['ok' => true, 'filename' => 'purchasing_grv_report.csv', 'headers' => $headers, 'rows' => $lines];
    }
    if ($kind === 'returns') {
        $res = psPurchasingReturnReport($conn, $filters);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Export failed.'];
        }
        $headers = [
            'Return #', 'GRV #', 'PO #', 'Supplier code', 'Supplier',
            'Date', 'Branch', 'User',
        ];
        $headers = array_merge($headers, psFxrCsvHeaders($ctx));
        $lines = [];
        foreach (array_filter($res['rows'], $keepRow) as $row) {
            $lines[] = [
                (string)$row['return_number'],
                (string)$row['grv_number'],
                (string)($row['po_number'] ?? ''),
                (string)($row['supplier_code'] ?? ''),
                (string)$row['supplier_name'],
                (string)$row['return_date'],
                (string)$row['branch'],
                (string)$row['created_by'],
            ];
            $lines[count($lines) - 1] = array_merge($lines[count($lines) - 1], psFxrCsvValues($ctx, $row['currency'] ?? null, (string)$row['total'], $row['base_total'] ?? null, (string)($row['fx_rate'] ?? ''), (string)($row['fx_rate_ids'] ?? '')));
        }
        return ['ok' => true, 'filename' => 'purchasing_returns_report.csv', 'headers' => $headers, 'rows' => $lines];
    }
    return ['ok' => false, 'error' => 'Export type is not valid.'];
}
