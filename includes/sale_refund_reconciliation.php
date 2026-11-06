<?php
/**
 * Phase 8.5 — Refund / cash / stock reconciliation (read-only detection + review status).
 *
 * Detects inconsistencies between PROCESSED refund requests, stock REFUND movements,
 * CASH_REFUND cash movements, refund audit, and safe cancellations.
 *
 * Absolute rules:
 *   - Never INSERT/UPDATE/DELETE stock_movements, cash_movements, sales, payments, AR.
 *   - Never invent CREDIT_NOTE / AR reversals / compensating movements.
 *   - Never reopen tills or alter cash_reconciliations.
 *   - Never rewrite historical refunds to make recon pass.
 *   - Never silently mark discrepancies resolved.
 *   - goods.quantity is never stock authority.
 *   - Only PROCESSED request lines count toward cumulative refunded qty.
 *   - APPROVED/REQUESTED are not completed (no expected stock/cash yet).
 *   - Non-cash tenders must NOT expect CASH_REFUND.
 *   - Legacy incomplete records → INCONCLUSIVE (do not falsely flag or assume valid).
 *   - CREDIT AR non-reversal is a known limitation (document; do not invent).
 *   - Phase 7 cash formula unchanged (psCashMovementBalance / ComputeBalanceFromParts).
 */

require_once __DIR__ . '/sale_refund.php';
require_once __DIR__ . '/sale_cancel.php';
require_once __DIR__ . '/cash_movement.php';
require_once __DIR__ . '/purchasing_reports.php';
require_once __DIR__ . '/product_master.php';

const PS_REFUND_RECON_SOURCE_UI = 'UI';
const PS_REFUND_RECON_SOURCE_CLI = 'CLI';

const PS_REFUND_RECON_SEVERITY_DISCREPANCY = 'DISCREPANCY';
const PS_REFUND_RECON_SEVERITY_INCONCLUSIVE = 'INCONCLUSIVE';
const PS_REFUND_RECON_SEVERITY_OK = 'OK';

const PS_REFUND_RECON_REVIEW_UNRESOLVED = 'UNRESOLVED';
const PS_REFUND_RECON_REVIEW_REVIEWED = 'REVIEWED';
const PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK = 'INCONCLUSIVE_ACK';

const PS_REFUND_RECON_CAT_REQUEST_SALE = 'REQUEST_SALE';
const PS_REFUND_RECON_CAT_REQUEST_LINES = 'REQUEST_LINES';
const PS_REFUND_RECON_CAT_QTY_OVER = 'QTY_OVER';
const PS_REFUND_RECON_CAT_AMOUNT = 'AMOUNT';
const PS_REFUND_RECON_CAT_STOCK = 'STOCK';
const PS_REFUND_RECON_CAT_CASH = 'CASH';
const PS_REFUND_RECON_CAT_AUDIT = 'AUDIT';
const PS_REFUND_RECON_CAT_CANCEL = 'CANCEL';
const PS_REFUND_RECON_CAT_LEGACY = 'LEGACY';
const PS_REFUND_RECON_CAT_UNMATCHED = 'UNMATCHED';

/**
 * @return list<string>
 */
function psSaleRefundReconCategories(): array
{
    return [
        PS_REFUND_RECON_CAT_REQUEST_SALE,
        PS_REFUND_RECON_CAT_REQUEST_LINES,
        PS_REFUND_RECON_CAT_QTY_OVER,
        PS_REFUND_RECON_CAT_AMOUNT,
        PS_REFUND_RECON_CAT_STOCK,
        PS_REFUND_RECON_CAT_CASH,
        PS_REFUND_RECON_CAT_AUDIT,
        PS_REFUND_RECON_CAT_CANCEL,
        PS_REFUND_RECON_CAT_LEGACY,
        PS_REFUND_RECON_CAT_UNMATCHED,
    ];
}

function psSaleRefundReconCategoryLabel(string $cat): string
{
    $map = [
        PS_REFUND_RECON_CAT_REQUEST_SALE => 'Processed request vs sale',
        PS_REFUND_RECON_CAT_REQUEST_LINES => 'Request lines vs sale lines',
        PS_REFUND_RECON_CAT_QTY_OVER => 'Quantity over-refund',
        PS_REFUND_RECON_CAT_AMOUNT => 'Amount mismatch',
        PS_REFUND_RECON_CAT_STOCK => 'Stock REFUND',
        PS_REFUND_RECON_CAT_CASH => 'Cash CASH_REFUND',
        PS_REFUND_RECON_CAT_AUDIT => 'Refund audit',
        PS_REFUND_RECON_CAT_CANCEL => 'Cancellation',
        PS_REFUND_RECON_CAT_LEGACY => 'Legacy / incomplete',
        PS_REFUND_RECON_CAT_UNMATCHED => 'Unmatched ledger',
    ];
    return $map[$cat] ?? $cat;
}

function psSaleRefundReconMayView(): bool
{
    return psSessionRefundUser() !== '';
}

function psSaleRefundReconMayReview(): bool
{
    return psRefundMayApprove();
}

function psSaleRefundReconMutationsEnabled(): bool
{
    return false;
}

function psSaleRefundReconIsAdmin(): bool
{
    return strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
}

/**
 * Ensure review-status tables (optional; detection itself needs no persistence).
 * Discrepancy findings are computed live; only manual review status is stored.
 */
function psEnsureSaleRefundReconReviewSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS sale_refund_recon_reviews (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        discrepancy_key VARCHAR(191) NOT NULL,
        category VARCHAR(32) NOT NULL,
        invoice_number VARCHAR(250) NOT NULL DEFAULT '',
        refund_number VARCHAR(32) NOT NULL DEFAULT '',
        branch VARCHAR(250) NOT NULL DEFAULT '',
        review_status VARCHAR(32) NOT NULL DEFAULT 'UNRESOLVED',
        last_reason VARCHAR(500) NOT NULL DEFAULT '',
        last_reviewed_by VARCHAR(250) NOT NULL DEFAULT '',
        last_reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_sale_refund_recon_key (discrepancy_key),
        KEY idx_sale_refund_recon_cat (category),
        KEY idx_sale_refund_recon_status (review_status),
        KEY idx_sale_refund_recon_branch (branch),
        KEY idx_sale_refund_recon_invoice (invoice_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('sale_refund_recon_reviews schema was not created');
        return false;
    }
    $audit = "CREATE TABLE IF NOT EXISTS sale_refund_recon_review_audit (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        discrepancy_key VARCHAR(191) NOT NULL,
        action VARCHAR(32) NOT NULL,
        previous_status VARCHAR(32) NOT NULL DEFAULT '',
        new_status VARCHAR(32) NOT NULL,
        reason VARCHAR(500) NOT NULL DEFAULT '',
        reviewed_by VARCHAR(250) NOT NULL,
        reviewed_at DATETIME NOT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'UI',
        KEY idx_sale_refund_recon_aud_key (discrepancy_key),
        KEY idx_sale_refund_recon_aud_at (reviewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $audit)) {
        error_log('sale_refund_recon_review_audit schema was not created');
        return false;
    }
    $ready = true;
    return true;
}

function psEnsureSaleRefundReconModule(mysqli $conn): bool
{
    return psEnsureSaleRefundModule($conn)
        && psEnsureSaleCancelModule($conn)
        && psEnsureSaleRefundReconReviewSchema($conn);
}

/**
 * Indexes that help date/branch filtered recon reads (idempotent; review before inventing more).
 */
function psEnsureSaleRefundReconIndexes(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psEnsureSaleRefundRequestsSchema($conn)) {
        return false;
    }
    $idx = mysqli_query($conn, "SHOW INDEX FROM sale_refund_requests WHERE Key_name = 'idx_sale_refund_req_processed_at'");
    if (!$idx || mysqli_num_rows($idx) === 0) {
        mysqli_query(
            $conn,
            'ALTER TABLE sale_refund_requests ADD KEY idx_sale_refund_req_processed_at (processed_at)'
        );
    }
    if (function_exists('stockMovementEnsureSchema')) {
        stockMovementEnsureSchema($conn);
    }
    $smIdx = mysqli_query($conn, "SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_stock_mov_type_ref'");
    if (!$smIdx || mysqli_num_rows($smIdx) === 0) {
        // Composite helps REFUND + reference_id lookups; ignore failure if columns differ.
        @mysqli_query(
            $conn,
            'ALTER TABLE stock_movements ADD KEY idx_stock_mov_type_ref (movement_type, reference_type, reference_id)'
        );
    }
    return true;
}

/**
 * @return array{ok:bool,from?:string,to?:string,from_dt?:string,to_dt?:string,error?:string}
 */
function psSaleRefundReconResolveDateRange($fromDate = '', $toDate = ''): array
{
    if (function_exists('date_default_timezone_set')) {
        @date_default_timezone_set('Africa/Harare');
    }
    $from = trim((string)$fromDate);
    $to = trim((string)$toDate);
    if ($from === '' && $to === '') {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-30 days'));
    }
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return ['ok' => false, 'error' => 'Invalid from date.'];
    }
    if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return ['ok' => false, 'error' => 'Invalid to date.'];
    }
    if ($from === '') {
        $from = $to;
    }
    if ($to === '') {
        $to = $from;
    }
    if ($from > $to) {
        $tmp = $from;
        $from = $to;
        $to = $tmp;
    }
    return [
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'from_dt' => $from . ' 00:00:00',
        'to_dt' => $to . ' 23:59:59',
    ];
}

/**
 * @param array<string,mixed> $raw
 * @return array{ok:bool,filters?:array,error?:string}
 */
function psSaleRefundReconNormaliseFilters(mysqli $conn, array $raw): array
{
    if (!psSaleRefundReconMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view refund reconciliation.'];
    }
    $branchRes = psPurchasingResolveReportBranch($conn, $raw['branch'] ?? null);
    if (empty($branchRes['ok'])) {
        return ['ok' => false, 'error' => $branchRes['error'] ?? 'That branch is not available.'];
    }
    $dates = psSaleRefundReconResolveDateRange($raw['from_date'] ?? '', $raw['to_date'] ?? '');
    if (empty($dates['ok'])) {
        return ['ok' => false, 'error' => $dates['error'] ?? 'Invalid date range.'];
    }
    $category = strtoupper(trim((string)($raw['category'] ?? '')));
    if ($category !== '' && !in_array($category, psSaleRefundReconCategories(), true)) {
        $category = '';
    }
    $status = strtoupper(trim((string)($raw['discrepancy_status'] ?? $raw['status'] ?? '')));
    $allowedStatus = ['', 'UNRESOLVED', 'REVIEWED', 'INCONCLUSIVE', 'INCONCLUSIVE_ACK', 'ALL_OPEN', 'ALL'];
    if (!in_array($status, $allowedStatus, true)) {
        $status = 'ALL_OPEN';
    }
    if ($status === '') {
        $status = 'ALL_OPEN';
    }
    $severity = strtoupper(trim((string)($raw['severity'] ?? '')));
    if ($severity !== '' && !in_array($severity, [
        PS_REFUND_RECON_SEVERITY_DISCREPANCY,
        PS_REFUND_RECON_SEVERITY_INCONCLUSIVE,
    ], true)) {
        $severity = '';
    }
    $saleRef = trim((string)($raw['sale_ref'] ?? $raw['invoice_number'] ?? ''));
    if (strlen($saleRef) > 250) {
        $saleRef = substr($saleRef, 0, 250);
    }
    $refundRef = trim((string)($raw['refund_ref'] ?? $raw['refund_number'] ?? ''));
    if (strlen($refundRef) > 64) {
        $refundRef = substr($refundRef, 0, 64);
    }
    $page = max(1, (int)($raw['page'] ?? 1));
    $pageSize = (int)($raw['page_size'] ?? 25);
    if ($pageSize < 5) {
        $pageSize = 25;
    }
    // Reporting/export may request a larger fetch; still hard-capped (no unrestricted dump).
    $maxPage = !empty($raw['report_fetch_all']) ? 2000 : 100;
    if ($pageSize > $maxPage) {
        $pageSize = $maxPage;
    }
    return [
        'ok' => true,
        'filters' => [
            'from_date' => $dates['from'],
            'to_date' => $dates['to'],
            'from_dt' => $dates['from_dt'],
            'to_dt' => $dates['to_dt'],
            'branch' => (string)($branchRes['branch'] ?? ''),
            'all_branches' => !empty($branchRes['all_branches']),
            'category' => $category,
            'discrepancy_status' => $status,
            'severity' => $severity,
            'sale_ref' => $saleRef,
            'refund_ref' => $refundRef,
            'page' => $page,
            'page_size' => $pageSize,
            'report_fetch_all' => !empty($raw['report_fetch_all']),
        ],
    ];
}

/**
 * Build a stable discrepancy key (unique identity; re-runs reuse the same key).
 */
function psSaleRefundReconKey(string $category, string $code, string $refundNumber = '', string $invoice = '', $extra = ''): string
{
    $parts = [
        strtoupper(trim($category)),
        strtoupper(trim($code)),
        trim($refundNumber),
        trim($invoice),
        trim((string)$extra),
    ];
    $key = implode('|', $parts);
    if (strlen($key) > 190) {
        $key = substr($key, 0, 150) . '|' . substr(sha1($key), 0, 16);
    }
    return $key;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function psSaleRefundReconFinding(
    string $category,
    string $code,
    string $severity,
    string $why,
    array $row = []
): array {
    $refundNumber = (string)($row['refund_number'] ?? '');
    $invoice = (string)($row['invoice_number'] ?? '');
    $extra = (string)($row['extra_key'] ?? ($row['sale_line_id'] ?? ($row['product_id'] ?? '')));
    $key = psSaleRefundReconKey($category, $code, $refundNumber, $invoice, $extra);
    return [
        'discrepancy_key' => $key,
        'category' => $category,
        'code' => $code,
        'severity' => $severity,
        'why' => $why,
        'invoice_number' => $invoice,
        'refund_number' => $refundNumber,
        'branch' => (string)($row['branch'] ?? ''),
        'product_id' => (string)($row['product_id'] ?? ''),
        'sale_line_id' => isset($row['sale_line_id']) ? (int)$row['sale_line_id'] : null,
        'qty_expected' => $row['qty_expected'] ?? null,
        'qty_actual' => $row['qty_actual'] ?? null,
        'qty_diff' => $row['qty_diff'] ?? null,
        'amount_expected' => $row['amount_expected'] ?? null,
        'amount_actual' => $row['amount_actual'] ?? null,
        'amount_diff' => $row['amount_diff'] ?? null,
        'payment_method' => (string)($row['payment_method'] ?? ''),
        'evidence' => (string)($row['evidence'] ?? ''),
        'processed_at' => (string)($row['processed_at'] ?? ''),
        'review_status' => PS_REFUND_RECON_REVIEW_UNRESOLVED,
    ];
}

/**
 * Money helpers (2dp).
 */
/**
 * 2026-11-06: a refund amount in the sale currency as cash in another currency, at the rate on the sale date (the same
 * conversion psSaleRefundProcess uses when it pays the cash out). Null when there is no rate.
 */
function psSaleRefundReconCashInCurrency(mysqli $conn, string $invoice, string $amount, string $saleCur, string $cashCur, int $tillSessionId): ?string
{
    require_once __DIR__ . '/currency.php';
    $st = mysqli_prepare($conn, "SELECT COALESCE(sold_at, CONCAT(date_, ' 12:00:00')) AS at FROM online_sales_ref WHERE invoice_number = ? LIMIT 1");
    $soldAt = '';
    if ($st) {
        mysqli_stmt_bind_param($st, 's', $invoice);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: [];
        mysqli_stmt_close($st);
        $soldAt = (string)($row['at'] ?? '');
    }
    $conv = psFxRate($conn, psFxTillCompany($conn, $tillSessionId), $saleCur, $cashCur, $soldAt !== '' ? $soldAt : date('Y-m-d H:i:s'));
    if (empty($conv['ok'])) {
        return null;
    }
    return psSaleRefundReconMoney(psFxCentsToMoney(psFxConvertCents((int)psFxMoneyToCents($amount), $conv['frac'], psFxDecimals($conn, $cashCur))));
}

function psSaleRefundReconMoney($raw): string
{
    return number_format((float)$raw, 2, '.', '');
}

function psSaleRefundReconMoneyDiff(string $expected, string $actual): string
{
    if (function_exists('bcsub')) {
        return number_format((float)bcsub($actual, $expected, 2), 2, '.', '');
    }
    return number_format((float)$actual - (float)$expected, 2, '.', '');
}

function psSaleRefundReconQtyDiff(string $expected, string $actual): string
{
    if (function_exists('bcsub')) {
        return bcsub($actual, $expected, 3);
    }
    return number_format((float)$actual - (float)$expected, 3, '.', '');
}

/**
 * Batch IN-list helper for ints/strings (escaped).
 *
 * @param list<int|string> $ids
 */
function psSaleRefundReconInList(mysqli $conn, array $ids, bool $asInt = false): string
{
    $out = [];
    foreach ($ids as $id) {
        if ($asInt) {
            $out[] = (string)(int)$id;
        } else {
            $out[] = "'" . mysqli_real_escape_string($conn, (string)$id) . "'";
        }
    }
    return $out ? implode(',', $out) : "''";
}

/**
 * Load review status map for keys.
 *
 * @param list<string> $keys
 * @return array<string,array>
 */
function psSaleRefundReconLoadReviews(mysqli $conn, array $keys): array
{
    $map = [];
    if (!$keys || !psEnsureSaleRefundReconReviewSchema($conn)) {
        return $map;
    }
    $chunks = array_chunk(array_values(array_unique($keys)), 100);
    foreach ($chunks as $chunk) {
        $in = psSaleRefundReconInList($conn, $chunk, false);
        $sql = "SELECT discrepancy_key, review_status, last_reason, last_reviewed_by, last_reviewed_at
                FROM sale_refund_recon_reviews WHERE discrepancy_key IN ($in)";
        $res = mysqli_query($conn, $sql);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $map[(string)$row['discrepancy_key']] = $row;
        }
    }
    return $map;
}

/**
 * Append-only review action. Does not alter sales/stock/cash ledgers.
 *
 * @param array{discrepancy_key:string,category?:string,invoice_number?:string,refund_number?:string,branch?:string,new_status:string,reason:string,source?:string} $data
 * @return array{ok:bool,error?:string}
 */
function psSaleRefundReconRecordReview(mysqli $conn, array $data): array
{
    if (!psSaleRefundReconMayReview()) {
        return ['ok' => false, 'error' => 'You are not authorised to update review status.'];
    }
    if (!psEnsureSaleRefundReconReviewSchema($conn)) {
        return ['ok' => false, 'error' => 'Review status could not be saved.'];
    }
    $key = trim((string)($data['discrepancy_key'] ?? ''));
    if ($key === '' || strlen($key) > 191) {
        return ['ok' => false, 'error' => 'Invalid discrepancy key.'];
    }
    $newStatus = strtoupper(trim((string)($data['new_status'] ?? '')));
    $allowed = [
        PS_REFUND_RECON_REVIEW_UNRESOLVED,
        PS_REFUND_RECON_REVIEW_REVIEWED,
        PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK,
    ];
    if (!in_array($newStatus, $allowed, true)) {
        return ['ok' => false, 'error' => 'Invalid review status.'];
    }
    $reason = trim((string)($data['reason'] ?? ''));
    if ($reason === '' || strlen($reason) < 3) {
        return ['ok' => false, 'error' => 'A review reason is required.'];
    }
    if (strlen($reason) > 500) {
        $reason = substr($reason, 0, 500);
    }
    $actor = psSessionRefundUser();
    $now = date('Y-m-d H:i:s');
    $source = trim((string)($data['source'] ?? PS_REFUND_RECON_SOURCE_UI));
    if ($source !== PS_REFUND_RECON_SOURCE_UI && $source !== PS_REFUND_RECON_SOURCE_CLI) {
        $source = PS_REFUND_RECON_SOURCE_UI;
    }
    $category = strtoupper(trim((string)($data['category'] ?? '')));
    $invoice = trim((string)($data['invoice_number'] ?? ''));
    $refundNumber = trim((string)($data['refund_number'] ?? ''));
    $branch = trim((string)($data['branch'] ?? ''));
    if ($branch !== '' && !psProductBranchIsAuthorised($conn, $branch)) {
        return ['ok' => false, 'error' => 'You do not have access to this branch.'];
    }

    $prev = PS_REFUND_RECON_REVIEW_UNRESOLVED;
    $sel = mysqli_prepare(
        $conn,
        'SELECT review_status FROM sale_refund_recon_reviews WHERE discrepancy_key = ? LIMIT 1'
    );
    if ($sel) {
        mysqli_stmt_bind_param($sel, 's', $key);
        if (mysqli_stmt_execute($sel)) {
            $res = mysqli_stmt_get_result($sel);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($row) {
                $prev = strtoupper(trim((string)$row['review_status']));
            }
        }
        mysqli_stmt_close($sel);
    }

    $owns = true;
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Review status could not be saved.'];
    }
    try {
        $upsert = mysqli_prepare(
            $conn,
            'INSERT INTO sale_refund_recon_reviews
                (discrepancy_key, category, invoice_number, refund_number, branch, review_status,
                 last_reason, last_reviewed_by, last_reviewed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                review_status = VALUES(review_status),
                last_reason = VALUES(last_reason),
                last_reviewed_by = VALUES(last_reviewed_by),
                last_reviewed_at = VALUES(last_reviewed_at),
                category = IF(VALUES(category) = \'\', category, VALUES(category)),
                invoice_number = IF(VALUES(invoice_number) = \'\', invoice_number, VALUES(invoice_number)),
                refund_number = IF(VALUES(refund_number) = \'\', refund_number, VALUES(refund_number)),
                branch = IF(VALUES(branch) = \'\', branch, VALUES(branch))'
        );
        if (!$upsert) {
            throw new RuntimeException('Review status could not be saved.');
        }
        mysqli_stmt_bind_param(
            $upsert,
            'ssssssssss',
            $key,
            $category,
            $invoice,
            $refundNumber,
            $branch,
            $newStatus,
            $reason,
            $actor,
            $now,
            $now
        );
        if (!mysqli_stmt_execute($upsert)) {
            mysqli_stmt_close($upsert);
            throw new RuntimeException('Review status could not be saved.');
        }
        mysqli_stmt_close($upsert);

        $aud = mysqli_prepare(
            $conn,
            'INSERT INTO sale_refund_recon_review_audit
                (discrepancy_key, action, previous_status, new_status, reason, reviewed_by, reviewed_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$aud) {
            throw new RuntimeException('Review status could not be saved.');
        }
        $action = 'SET_STATUS';
        mysqli_stmt_bind_param(
            $aud,
            'ssssssss',
            $key,
            $action,
            $prev,
            $newStatus,
            $reason,
            $actor,
            $now,
            $source
        );
        if (!mysqli_stmt_execute($aud)) {
            mysqli_stmt_close($aud);
            throw new RuntimeException('Review status could not be saved.');
        }
        mysqli_stmt_close($aud);

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Review status could not be saved.');
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Review status could not be saved.'];
    }
}

/**
 * Core read-only reconciliation run. Never mutates sales/stock/cash/AR.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,filters?:array,rows?:list,total?:int,summary?:array,limitations?:list,error?:string}
 */
function psSaleRefundReconciliationRun(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureSaleRefundReconModule($conn)) {
        return ['ok' => false, 'error' => 'Refund reconciliation could not be loaded.'];
    }
    psEnsureSaleRefundReconIndexes($conn);

    $norm = psSaleRefundReconNormaliseFilters($conn, $rawFilters);
    if (empty($norm['ok'])) {
        return ['ok' => false, 'error' => $norm['error'] ?? 'Invalid filters.'];
    }
    $f = $norm['filters'];
    $findings = [];
    $limitations = [
        'CREDIT account ledger is not reversed on refund (psRefundCustomerAccountLedgerEnabled=false) — not flagged as cash/stock discrepancy.',
        'APPROVED/REQUESTED refunds do not expect stock or cash movements until PROCESSED.',
        'Pre-8.3 whole-line status=refunded without request-lines may be unmappable — reported as INCONCLUSIVE, not assumed valid.',
        'Detection is read-only; re-runs do not insert duplicate ledger rows or alter historical sales.',
        'Phase 7 cash formula unchanged; only CASH tender expects CASH_REFUND.',
    ];

    $fromDt = $f['from_dt'];
    $toDt = $f['to_dt'];
    $branch = $f['branch'];
    $allBranches = !empty($f['all_branches']);

    // --- 1) PROCESSED requests in range ---
    $reqSql = "SELECT r.*
               FROM sale_refund_requests r
               WHERE r.status = 'PROCESSED'
                 AND r.processed_at >= ? AND r.processed_at <= ?";
    $types = 'ss';
    $params = [$fromDt, $toDt];
    if (!$allBranches) {
        $reqSql .= ' AND r.branch = ?';
        $types .= 's';
        $params[] = $branch;
    } else {
        // 2026-11-02: "all branches" = this company's branches only.
        $reqSql .= ' AND ' . psCompanyBranchesInSql($conn, 'r.branch');
    }
    $saleRef = trim((string)($f['sale_ref'] ?? ''));
    if ($saleRef !== '') {
        $reqSql .= ' AND r.invoice_number = ?';
        $types .= 's';
        $params[] = $saleRef;
    }
    $refundRef = trim((string)($f['refund_ref'] ?? ''));
    if ($refundRef !== '') {
        $reqSql .= ' AND r.refund_number = ?';
        $types .= 's';
        $params[] = $refundRef;
    }
    $reqSql .= ' ORDER BY r.processed_at ASC, r.id ASC LIMIT 2000';
    $processed = [];
    $stmt = mysqli_prepare($conn, $reqSql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Refund reconciliation could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Refund reconciliation could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $processed[] = $row;
    }
    mysqli_stmt_close($stmt);

    $requestIds = [];
    $refundNumbers = [];
    $invoices = [];
    foreach ($processed as $r) {
        $requestIds[] = (int)$r['id'];
        $refundNumbers[] = (string)$r['refund_number'];
        $invoices[] = (string)$r['invoice_number'];
    }
    $requestIds = array_values(array_unique(array_filter($requestIds)));
    $refundNumbers = array_values(array_unique(array_filter($refundNumbers)));
    $invoices = array_values(array_unique(array_filter($invoices)));

    // Request lines
    $linesByRequest = [];
    if ($requestIds) {
        $in = psSaleRefundReconInList($conn, $requestIds, true);
        $sql = "SELECT * FROM sale_refund_request_lines WHERE request_id IN ($in) ORDER BY id ASC";
        $res = mysqli_query($conn, $sql);
        while ($res && ($ln = mysqli_fetch_assoc($res))) {
            $rid = (int)$ln['request_id'];
            if (!isset($linesByRequest[$rid])) {
                $linesByRequest[$rid] = [];
            }
            $linesByRequest[$rid][] = $ln;
        }
    }

    // Sale headers
    $saleHeaders = [];
    if ($invoices) {
        $in = psSaleRefundReconInList($conn, $invoices, false);
        $sql = "SELECT invoice_number, status, branch, totalz FROM online_sales_ref WHERE invoice_number IN ($in)";
        $res = mysqli_query($conn, $sql);
        while ($res && ($h = mysqli_fetch_assoc($res))) {
            $saleHeaders[(string)$h['invoice_number']] = $h;
        }
    }

    // Sale lines for those invoices
    $saleLinesById = [];
    $saleLinesByInvoice = [];
    if ($invoices) {
        $in = psSaleRefundReconInList($conn, $invoices, false);
        $sql = "SELECT * /* 2026-10-28: incl. pack snapshot + tax for the refund amount rule */
                FROM online_sales WHERE invoice_number IN ($in)";
        $res = mysqli_query($conn, $sql);
        while ($res && ($sl = mysqli_fetch_assoc($res))) {
            $sid = (int)$sl['id'];
            $saleLinesById[$sid] = $sl;
            $inv = (string)$sl['invoice_number'];
            if (!isset($saleLinesByInvoice[$inv])) {
                $saleLinesByInvoice[$inv] = [];
            }
            $saleLinesByInvoice[$inv][] = $sl;
        }
    }

    // Payment methods
    $payByInvoice = [];
    if ($invoices) {
        $in = psSaleRefundReconInList($conn, $invoices, false);
        // 2026-11-05 split payment: several methods on one sale = SPLIT (the change row is not a way it was paid).
        $hasChange = psFxHasColumn($conn, 'sale_payments', 'is_change');
        $sql = "SELECT invoice_number, method FROM sale_payments WHERE invoice_number IN ($in)" . ($hasChange ? ' AND is_change = 0' : '');
        $res = mysqli_query($conn, $sql);
        while ($res && ($p = mysqli_fetch_assoc($res))) {
            $inv = (string)$p['invoice_number'];
            $m = strtoupper(trim((string)$p['method']));
            $payByInvoice[$inv] = (isset($payByInvoice[$inv]) && $payByInvoice[$inv] !== $m) ? 'SPLIT' : $m;
        }
    }

    // Stock REFUND movements for these refs
    $stockByRef = [];
    if ($refundNumbers) {
        $in = psSaleRefundReconInList($conn, $refundNumbers, false);
        $sql = "SELECT id, product_id, branch, quantity, reference_type, reference_id, movement_type, movement_at, notes
                FROM stock_movements
                WHERE movement_type = 'REFUND' AND reference_type = 'REFUND' AND reference_id IN ($in)
                  AND " . psCompanyBranchesInSql($conn, 'branch'); // 2026-11-02 company only
        $res = mysqli_query($conn, $sql);
        while ($res && ($sm = mysqli_fetch_assoc($res))) {
            $ref = (string)$sm['reference_id'];
            if (!isset($stockByRef[$ref])) {
                $stockByRef[$ref] = [];
            }
            $stockByRef[$ref][] = $sm;
        }
    }

    // Cash CASH_REFUND for these refs (and legacy REFUND_LINE / invoice patterns)
    $cashByRef = [];
    if ($refundNumbers) {
        $in = psSaleRefundReconInList($conn, $refundNumbers, false);
        $sql = "SELECT id, till_session_id, branch, amount, currency, reference_type, reference_id, movement_type, movement_at
                FROM cash_movements
                WHERE movement_type = 'CASH_REFUND'
                  AND (
                    (reference_type = 'REFUND' AND reference_id IN ($in))
                    OR (reference_type = 'REFUND_LINE')
                  )
                  AND " . psCompanyBranchesInSql($conn, 'branch'); // 2026-11-02 company only
        $res = mysqli_query($conn, $sql);
        while ($res && ($cm = mysqli_fetch_assoc($res))) {
            $ref = (string)$cm['reference_id'];
            $rtype = (string)$cm['reference_type'];
            $key = $rtype . ':' . $ref;
            if (!isset($cashByRef[$key])) {
                $cashByRef[$key] = [];
            }
            $cashByRef[$key][] = $cm;
            if ($rtype === 'REFUND') {
                if (!isset($cashByRef['REF:' . $ref])) {
                    $cashByRef['REF:' . $ref] = [];
                }
                $cashByRef['REF:' . $ref][] = $cm;
            }
        }
    }

    // PROCESSING audits
    $auditByRef = [];
    if ($refundNumbers) {
        $in = psSaleRefundReconInList($conn, $refundNumbers, false);
        $sql = "SELECT * FROM sale_refund_audit
                WHERE event_type = 'PROCESSING' AND refund_number IN ($in)";
        $res = mysqli_query($conn, $sql);
        while ($res && ($a = mysqli_fetch_assoc($res))) {
            $auditByRef[(string)$a['refund_number']] = $a;
        }
    }

    // Cumulative PROCESSED qty per sale_line (global, for over-refund)
    $processedQtyByLine = [];
    $sumSql = "SELECT l.sale_line_id, COALESCE(SUM(l.requested_qty), 0) AS processed_qty
               FROM sale_refund_request_lines l
               INNER JOIN sale_refund_requests r ON r.id = l.request_id
               WHERE r.status = 'PROCESSED'
               GROUP BY l.sale_line_id";
    $sumRes = mysqli_query($conn, $sumSql);
    while ($sumRes && ($sr = mysqli_fetch_assoc($sumRes))) {
        $processedQtyByLine[(int)$sr['sale_line_id']] = psStockDecimal((string)$sr['processed_qty']) ?? '0.000';
    }

    foreach ($processed as $req) {
        $rid = (int)$req['id'];
        $refNum = (string)$req['refund_number'];
        $inv = (string)$req['invoice_number'];
        $reqBranch = (string)$req['branch'];
        $procAt = (string)($req['processed_at'] ?? '');
        $procAmt = psSaleRefundReconMoney($req['processed_amount'] ?? $req['requested_amount'] ?? 0);
        $reqLines = $linesByRequest[$rid] ?? [];
        $header = $saleHeaders[$inv] ?? null;
        $method = $payByInvoice[$inv] ?? '';
        // 2026-11-05: when the cashier chose how the money went back, that is what the cash ledger must match.
        if (trim((string)($req['refund_method'] ?? '')) !== '') {
            $method = strtoupper(trim((string)$req['refund_method']));
        }
        $methodKnown = $method !== '';
        $isCash = $methodKnown && $method === 'CASH';

        // 1. Request vs original sale
        if ($header === null) {
            $findings[] = psSaleRefundReconFinding(
                PS_REFUND_RECON_CAT_REQUEST_SALE,
                'MISSING_SALE',
                PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                'PROCESSED refund has no matching online_sales_ref invoice.',
                [
                    'refund_number' => $refNum,
                    'invoice_number' => $inv,
                    'branch' => $reqBranch,
                    'processed_at' => $procAt,
                    'payment_method' => $method,
                    'evidence' => 'request_id=' . $rid,
                ]
            );
        } else {
            $saleBranch = trim((string)$header['branch']);
            if ($saleBranch !== '' && $saleBranch !== $reqBranch) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_REQUEST_SALE,
                    'BRANCH_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Refund request branch does not match original sale branch.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'processed_at' => $procAt,
                        'evidence' => 'sale_branch=' . $saleBranch,
                    ]
                );
            }
        }

        // 2. Request lines vs sale lines + amounts
        if (!$reqLines) {
            $findings[] = psSaleRefundReconFinding(
                PS_REFUND_RECON_CAT_REQUEST_LINES,
                'MISSING_LINES',
                PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                'PROCESSED refund has no sale_refund_request_lines.',
                [
                    'refund_number' => $refNum,
                    'invoice_number' => $inv,
                    'branch' => $reqBranch,
                    'processed_at' => $procAt,
                ]
            );
        }

        $lineAmtSum = '0.00';
        $matchedStockIds = [];
        $stockPool = $stockByRef[$refNum] ?? [];
        foreach ($reqLines as $rl) {
            $saleLineId = (int)$rl['sale_line_id'];
            $qty = psStockDecimal((string)$rl['requested_qty']) ?? '0.000';
            $productId = trim((string)$rl['product_id']);
            $saleLine = $saleLinesById[$saleLineId] ?? null;

            if ($saleLine === null) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_REQUEST_LINES,
                    'ORPHAN_LINE',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Request line sale_line_id not found on online_sales.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'sale_line_id' => $saleLineId,
                        'product_id' => $productId,
                        'qty_expected' => $qty,
                        'processed_at' => $procAt,
                        'extra_key' => 'line:' . $saleLineId,
                    ]
                );
                continue;
            }

            if (trim((string)$saleLine['invoice_number']) !== $inv) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_REQUEST_LINES,
                    'LINE_INVOICE_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Request line belongs to a different invoice than the request header.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'sale_line_id' => $saleLineId,
                        'product_id' => $productId,
                        'processed_at' => $procAt,
                        'extra_key' => 'line:' . $saleLineId,
                        'evidence' => 'sale_invoice=' . $saleLine['invoice_number'],
                    ]
                );
            }

            if ($productId !== '' && trim((string)$saleLine['product_id']) !== $productId) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_REQUEST_LINES,
                    'PRODUCT_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Request line product_id does not match original sale line.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'sale_line_id' => $saleLineId,
                        'product_id' => $productId,
                        'processed_at' => $procAt,
                        'extra_key' => 'line:' . $saleLineId,
                        'evidence' => 'sale_product=' . $saleLine['product_id'],
                    ]
                );
            }

            // Amount = unit_price × qty (same rule as process)
            $amtPair = psRefundComputeAmountForLineQtys([
                ['line' => $saleLine, 'quantity' => $qty],
            ]);
            $expectedLineAmt = null;
            if (!empty($amtPair['ok'])) {
                $expectedLineAmt = psSaleRefundReconMoney($amtPair['amount']);
                $lineAmtSum = psSaleRefundReconMoney(
                    (function_exists('bcadd')
                        ? bcadd($lineAmtSum, $expectedLineAmt, 2)
                        : ((float)$lineAmtSum + (float)$expectedLineAmt))
                );
                $snap = psSaleRefundReconMoney($rl['line_amount_snapshot'] ?? 0);
                if ($snap !== $expectedLineAmt) {
                    $findings[] = psSaleRefundReconFinding(
                        PS_REFUND_RECON_CAT_AMOUNT,
                        'LINE_AMOUNT_SNAPSHOT',
                        PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                        'Line amount snapshot does not match unit_price × qty from sale line.',
                        [
                            'refund_number' => $refNum,
                            'invoice_number' => $inv,
                            'branch' => $reqBranch,
                            'sale_line_id' => $saleLineId,
                            'product_id' => $productId,
                            'qty_expected' => $qty,
                            'amount_expected' => $expectedLineAmt,
                            'amount_actual' => $snap,
                            'amount_diff' => psSaleRefundReconMoneyDiff($expectedLineAmt, $snap),
                            'processed_at' => $procAt,
                            'extra_key' => 'line:' . $saleLineId,
                        ]
                    );
                }
            }

            // 4. Stock: one-to-one greedy match (notes prefer "Sale line {id}"; product+branch+qty).
            $saleProd = trim((string)$saleLine['product_id']);
            $saleBr = trim((string)$saleLine['branch']);
            $bestIdx = null;
            $bestScore = -1;
            foreach ($stockPool as $idx => $sm) {
                $sid = (int)$sm['id'];
                if (!empty($matchedStockIds[$sid])) {
                    continue;
                }
                if (trim((string)$sm['product_id']) !== $saleProd
                    || trim((string)$sm['branch']) !== $saleBr
                ) {
                    continue;
                }
                $q = psStockDecimal((string)$sm['quantity']) ?? '0.000';
                $score = 0;
                $notes = (string)($sm['notes'] ?? '');
                if (strpos($notes, 'Sale line ' . $saleLineId) !== false) {
                    $score += 10;
                }
                if (psStockDecimalCmp($q, $qty) === 0) {
                    $score += 5;
                } else {
                    $score += 1;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIdx = $idx;
                }
            }
            if ($bestIdx === null) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_STOCK,
                    'MISSING_STOCK',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Expected stock REFUND movement missing for processed refund line.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'sale_line_id' => $saleLineId,
                        'product_id' => $saleProd,
                        'qty_expected' => $qty,
                        'qty_actual' => '0.000',
                        'qty_diff' => psSaleRefundReconQtyDiff($qty, '0.000'),
                        'processed_at' => $procAt,
                        'extra_key' => 'line:' . $saleLineId,
                        'evidence' => 'reference_type=REFUND reference_id=' . $refNum,
                    ]
                );
            } else {
                $sm = $stockPool[$bestIdx];
                $sid = (int)$sm['id'];
                $matchedStockIds[$sid] = true;
                $matchedQty = psStockDecimal((string)$sm['quantity']) ?? '0.000';
                if (psStockDecimalCmp($matchedQty, $qty) !== 0) {
                    $code = psStockDecimalCmp($matchedQty, $qty) > 0 ? 'EXCESS_STOCK' : 'STOCK_QTY_MISMATCH';
                    $findings[] = psSaleRefundReconFinding(
                        PS_REFUND_RECON_CAT_STOCK,
                        $code,
                        PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                        'Stock REFUND quantity does not match processed request line qty.',
                        [
                            'refund_number' => $refNum,
                            'invoice_number' => $inv,
                            'branch' => $reqBranch,
                            'sale_line_id' => $saleLineId,
                            'product_id' => $saleProd,
                            'qty_expected' => $qty,
                            'qty_actual' => $matchedQty,
                            'qty_diff' => psSaleRefundReconQtyDiff($qty, $matchedQty),
                            'processed_at' => $procAt,
                            'extra_key' => 'line:' . $saleLineId,
                            'evidence' => 'stock_movement_id=' . $sid,
                        ]
                    );
                }
                // Detect duplicate unused stock rows with same product/branch/qty still in pool
                $dupCount = 0;
                foreach ($stockPool as $sm2) {
                    $sid2 = (int)$sm2['id'];
                    if ($sid2 === $sid || !empty($matchedStockIds[$sid2])) {
                        continue;
                    }
                    if (trim((string)$sm2['product_id']) === $saleProd
                        && trim((string)$sm2['branch']) === $saleBr
                        && psStockDecimalCmp(psStockDecimal((string)$sm2['quantity']) ?? '0', $qty) === 0
                    ) {
                        $dupCount++;
                    }
                }
                // Only flag duplicate when there are more matching rows than remaining unmatched request lines for same product — handled via leftover unmatched below.
            }
        }

        // Extra unmatched stock on this REF (not consumed by any request line)
        foreach ($stockPool as $sm) {
            $sid = (int)$sm['id'];
            if (!empty($matchedStockIds[$sid])) {
                continue;
            }
            $findings[] = psSaleRefundReconFinding(
                PS_REFUND_RECON_CAT_STOCK,
                'UNMATCHED_STOCK',
                PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                'Stock REFUND movement on REF not matched to a processed request line (duplicate/excess).',
                [
                    'refund_number' => $refNum,
                    'invoice_number' => $inv,
                    'branch' => (string)$sm['branch'],
                    'product_id' => (string)$sm['product_id'],
                    'qty_actual' => psStockDecimal((string)$sm['quantity']) ?? '0.000',
                    'processed_at' => $procAt,
                    'extra_key' => 'stock:' . $sid,
                    'evidence' => 'stock_movement_id=' . $sid,
                ]
            );
        }

        // Request total vs line totals vs processed_amount
        if ($reqLines) {
            $reqAmt = psSaleRefundReconMoney($req['requested_amount'] ?? 0);
            if ($lineAmtSum !== '0.00' && $lineAmtSum !== $reqAmt) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_AMOUNT,
                    'REQUEST_TOTAL_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Sum of line amounts does not match request requested_amount.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => $lineAmtSum,
                        'amount_actual' => $reqAmt,
                        'amount_diff' => psSaleRefundReconMoneyDiff($lineAmtSum, $reqAmt),
                        'processed_at' => $procAt,
                    ]
                );
            }
            if ($lineAmtSum !== '0.00' && $procAmt !== $lineAmtSum) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_AMOUNT,
                    'PROCESSED_AMOUNT_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'processed_amount does not match recalculated line totals.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => $lineAmtSum,
                        'amount_actual' => $procAmt,
                        'amount_diff' => psSaleRefundReconMoneyDiff($lineAmtSum, $procAmt),
                        'processed_at' => $procAt,
                    ]
                );
            }
        }

        // 5. Cash
        $cashKey = 'REF:' . $refNum;
        $cashRows = $cashByRef[$cashKey] ?? ($cashByRef['REFUND:' . $refNum] ?? []);
        // de-dup by id
        $cashUnique = [];
        foreach ($cashRows as $cm) {
            $cashUnique[(int)$cm['id']] = $cm;
        }
        $cashRows = array_values($cashUnique);
        $cashCount = count($cashRows);
        $cashSum = '0.00';
        foreach ($cashRows as $cm) {
            $cashSum = psSaleRefundReconMoney(
                function_exists('bcadd')
                    ? bcadd($cashSum, psSaleRefundReconMoney($cm['amount']), 2)
                    : ((float)$cashSum + (float)$cm['amount'])
            );
        }

        // 2026-11-06: a cash refund the cashier gave in another currency (split currency) is posted in that currency, so
        // it is checked against the refund converted the same way as when it was paid out (psSaleRefundProcess).
        $cashExpectAmt = $procAmt;
        if ($isCash && $cashCount === 1) {
            $refundCur = strtoupper(trim((string)($req['refund_currency'] ?? '')));
            $cashCur = strtoupper(trim((string)($cashRows[0]['currency'] ?? '')));
            $saleCur = strtoupper(trim((string)($req['currency'] ?? '')));
            if ($refundCur !== '' && $saleCur !== '' && $refundCur !== $saleCur && $cashCur === $refundCur) {
                $expectCash = psSaleRefundReconCashInCurrency($conn, $inv, $procAmt, $saleCur, $refundCur, (int)$cashRows[0]['till_session_id']);
                if ($expectCash !== null) {
                    $cashExpectAmt = $expectCash;
                }
            }
        }

        if ($isCash) {
            if ($cashCount === 0) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_CASH,
                    'MISSING_CASH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Expected CASH_REFUND missing for processed CASH refund.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => $cashExpectAmt,
                        'amount_actual' => '0.00',
                        'amount_diff' => psSaleRefundReconMoneyDiff($cashExpectAmt, '0.00'),
                        'payment_method' => $method,
                        'processed_at' => $procAt,
                        'evidence' => 'reference_type=REFUND reference_id=' . $refNum,
                    ]
                );
            } elseif ($cashCount > 1) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_CASH,
                    'DUPLICATE_CASH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Duplicate CASH_REFUND movements for the same REF.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => $cashExpectAmt,
                        'amount_actual' => $cashSum,
                        'amount_diff' => psSaleRefundReconMoneyDiff($cashExpectAmt, $cashSum),
                        'payment_method' => $method,
                        'processed_at' => $procAt,
                        'evidence' => 'cash_rows=' . $cashCount,
                    ]
                );
            } elseif ($cashSum !== $cashExpectAmt) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_CASH,
                    'CASH_AMOUNT_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'CASH_REFUND amount does not match processed refund amount.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => $cashExpectAmt,
                        'amount_actual' => $cashSum,
                        'amount_diff' => psSaleRefundReconMoneyDiff($cashExpectAmt, $cashSum),
                        'payment_method' => $method,
                        'processed_at' => $procAt,
                        'evidence' => 'cash_movement_id=' . (int)$cashRows[0]['id'],
                    ]
                );
            }
        } elseif ($methodKnown) {
            // Non-cash: must NOT have CASH_REFUND for this REF
            if ($cashCount > 0) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_CASH,
                    'UNEXPECTED_CASH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Non-cash refund has CASH_REFUND movement(s); none expected.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => '0.00',
                        'amount_actual' => $cashSum,
                        'amount_diff' => $cashSum,
                        'payment_method' => $method,
                        'processed_at' => $procAt,
                        'evidence' => 'cash_rows=' . $cashCount,
                    ]
                );
            }
        } else {
            // Unknown payment method — inconclusive for cash expectation
            if ($cashCount > 0) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_CASH,
                    'CASH_UNKNOWN_METHOD',
                    PS_REFUND_RECON_SEVERITY_INCONCLUSIVE,
                    'CASH_REFUND present but sale_payments method unknown — cannot reliably validate.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_actual' => $cashSum,
                        'payment_method' => 'UNKNOWN',
                        'processed_at' => $procAt,
                    ]
                );
            }
        }

        // 6. Audit PROCESSING
        $audit = $auditByRef[$refNum] ?? null;
        if ($audit === null) {
            $findings[] = psSaleRefundReconFinding(
                PS_REFUND_RECON_CAT_AUDIT,
                'MISSING_PROCESSING_AUDIT',
                PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                'PROCESSED request has no sale_refund_audit PROCESSING event.',
                [
                    'refund_number' => $refNum,
                    'invoice_number' => $inv,
                    'branch' => $reqBranch,
                    'processed_at' => $procAt,
                    'evidence' => 'request_id=' . $rid,
                ]
            );
        } else {
            $auditAmt = psSaleRefundReconMoney($audit['amount'] ?? 0);
            if ($auditAmt !== $procAmt) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_AUDIT,
                    'AUDIT_AMOUNT_MISMATCH',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'PROCESSING audit amount does not match processed_amount.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'amount_expected' => $procAmt,
                        'amount_actual' => $auditAmt,
                        'amount_diff' => psSaleRefundReconMoneyDiff($procAmt, $auditAmt),
                        'processed_at' => $procAt,
                    ]
                );
            }
            if ($isCash && (int)($audit['cash_movement_id'] ?? 0) < 1 && $cashCount > 0) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_AUDIT,
                    'AUDIT_CASH_LINK_MISSING',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'CASH_REFUND exists but PROCESSING audit cash_movement_id is empty.',
                    [
                        'refund_number' => $refNum,
                        'invoice_number' => $inv,
                        'branch' => $reqBranch,
                        'processed_at' => $procAt,
                    ]
                );
            }
        }
    }

    // 3. Over-refund qty (PROCESSED sum > sold) for lines touched in range
    $touchedLineIds = [];
    foreach ($linesByRequest as $rLines) {
        foreach ($rLines as $rl) {
            $touchedLineIds[(int)$rl['sale_line_id']] = true;
        }
    }
    foreach (array_keys($touchedLineIds) as $saleLineId) {
        $saleLine = $saleLinesById[$saleLineId] ?? null;
        if ($saleLine === null) {
            continue;
        }
        $orig = psStockDecimal((string)$saleLine['quantity']);
        if ($orig === null) {
            continue;
        }
        $proc = $processedQtyByLine[$saleLineId] ?? '0.000';
        if (psStockDecimalCmp($proc, $orig) > 0) {
            $findings[] = psSaleRefundReconFinding(
                PS_REFUND_RECON_CAT_QTY_OVER,
                'OVER_REFUND_QTY',
                PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                'Cumulative PROCESSED refund qty exceeds original sold qty.',
                [
                    'invoice_number' => (string)$saleLine['invoice_number'],
                    'branch' => (string)$saleLine['branch'],
                    'sale_line_id' => $saleLineId,
                    'product_id' => (string)$saleLine['product_id'],
                    'qty_expected' => $orig,
                    'qty_actual' => $proc,
                    'qty_diff' => psSaleRefundReconQtyDiff($orig, $proc),
                    'extra_key' => 'line:' . $saleLineId,
                ]
            );
        }
    }

    // 7. Cancelled sales — no unintended ledger; cancel audit present
    $cancelSql = "SELECT invoice_number, status, branch, sold_at, date_
                  FROM online_sales_ref
                  WHERE status = 'CANCELLED'";
    $cTypes = '';
    $cParams = [];
    // Date on cancel is imperfect; use sold_at/date_ when present, else include all CANCELLED in branch
    if (!$allBranches) {
        $cancelSql .= ' AND branch = ?';
        $cTypes .= 's';
        $cParams[] = $branch;
    } else {
        $cancelSql .= ' AND ' . psCompanyBranchesInSql($conn, 'branch'); // 2026-11-02 company only
    }
    $cancelSql .= ' ORDER BY id DESC LIMIT 500';
    $cancelled = [];
    if ($cTypes !== '') {
        $cStmt = mysqli_prepare($conn, $cancelSql);
        if ($cStmt) {
            mysqli_stmt_bind_param($cStmt, $cTypes, ...$cParams);
            if (mysqli_stmt_execute($cStmt)) {
                $cRes = mysqli_stmt_get_result($cStmt);
                while ($cRes && ($cr = mysqli_fetch_assoc($cRes))) {
                    $cancelled[] = $cr;
                }
            }
            mysqli_stmt_close($cStmt);
        }
    } else {
        $cRes = mysqli_query($conn, $cancelSql);
        while ($cRes && ($cr = mysqli_fetch_assoc($cRes))) {
            $cancelled[] = $cr;
        }
    }

    $cancelInvoices = [];
    foreach ($cancelled as $cr) {
        $cancelInvoices[] = (string)$cr['invoice_number'];
    }
    $cancelAuditByInvoice = [];
    if ($cancelInvoices) {
        $in = psSaleRefundReconInList($conn, $cancelInvoices, false);
        $sql = "SELECT * FROM sale_cancel_audit
                WHERE action = 'CANCEL_UNCOMPLETED' AND invoice_number IN ($in)";
        $res = mysqli_query($conn, $sql);
        while ($res && ($a = mysqli_fetch_assoc($res))) {
            $cancelAuditByInvoice[(string)$a['invoice_number']] = $a;
        }
    }

    foreach ($cancelled as $cr) {
        $cinv = (string)$cr['invoice_number'];
        $cbranch = (string)$cr['branch'];
        // Date filter soft: if sold_at within range OR unknown date include
        $soldAt = trim((string)($cr['sold_at'] ?? ''));
        if ($soldAt !== '' && $soldAt < $fromDt) {
            continue;
        }
        if ($soldAt !== '' && $soldAt > $toDt) {
            continue;
        }

        $fp = psSaleCancelLedgerFootprint($conn, $cinv);
        if (!empty($fp['ok'])) {
            $bad = ((int)$fp['payments'] > 0)
                || ((int)$fp['stock_sale'] > 0)
                || ((int)$fp['cash_sale'] > 0)
                || ((int)$fp['credit_sale'] > 0)
                || ((int)$fp['refund_processed'] > 0);
            if ($bad) {
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_CANCEL,
                    'CANCEL_WITH_LEDGER',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'CANCELLED sale still has payment/stock/cash/AR/refund footprint.',
                    [
                        'invoice_number' => $cinv,
                        'branch' => $cbranch,
                        'evidence' => 'payments=' . (int)$fp['payments']
                            . ' stock=' . (int)$fp['stock_sale']
                            . ' cash=' . (int)$fp['cash_sale']
                            . ' ar=' . (int)$fp['credit_sale']
                            . ' refunds=' . (int)$fp['refund_processed'],
                    ]
                );
            }
        }
        if (empty($cancelAuditByInvoice[$cinv])) {
            $findings[] = psSaleRefundReconFinding(
                PS_REFUND_RECON_CAT_CANCEL,
                'CANCEL_AUDIT_MISSING',
                PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                'CANCELLED sale has no sale_cancel_audit CANCEL_UNCOMPLETED row.',
                [
                    'invoice_number' => $cinv,
                    'branch' => $cbranch,
                ]
            );
        }
    }

    // 8 / Legacy: status=refunded lines without PROCESSED request-line coverage
    $legacySql = "SELECT id, invoice_number, product_id, branch, quantity, status
                  FROM online_sales
                  WHERE status = 'refunded'";
    if (!$allBranches) {
        $legacySql .= " AND branch = '" . mysqli_real_escape_string($conn, $branch) . "'";
    } else {
        $legacySql .= ' AND ' . psCompanyBranchesInSql($conn, 'branch'); // 2026-11-02 company only
    }
    $legacySql .= ' ORDER BY id DESC LIMIT 500';
    $legRes = mysqli_query($conn, $legacySql);
    while ($legRes && ($lg = mysqli_fetch_assoc($legRes))) {
        $lid = (int)$lg['id'];
        $proc = $processedQtyByLine[$lid] ?? '0.000';
        if (psStockDecimalCmp($proc, '0') > 0) {
            continue; // covered by modern request-lines
        }
        $findings[] = psSaleRefundReconFinding(
            PS_REFUND_RECON_CAT_LEGACY,
            'LEGACY_REFUNDED_INCOMPLETE',
            PS_REFUND_RECON_SEVERITY_INCONCLUSIVE,
            'Line status=refunded without PROCESSED request-lines — historical qty/stock/cash links may be unmappable; not assumed valid or invalid.',
            [
                'invoice_number' => (string)$lg['invoice_number'],
                'branch' => (string)$lg['branch'],
                'sale_line_id' => $lid,
                'product_id' => (string)$lg['product_id'],
                'qty_expected' => psStockDecimal((string)$lg['quantity']) ?? null,
                'extra_key' => 'line:' . $lid,
                'evidence' => 'legacy pre-line-level refunded marker',
            ]
        );
    }

    // Unmatched CASH_REFUND in range with REF reference not in PROCESSED set
    $unmatchedCashSql = "SELECT id, branch, amount, reference_type, reference_id, movement_at
                         FROM cash_movements
                         WHERE movement_type = 'CASH_REFUND'
                           AND movement_at >= ? AND movement_at <= ?
                           AND reference_type = 'REFUND'";
    $ucTypes = 'ss';
    $ucParams = [$fromDt, $toDt];
    if (!$allBranches) {
        $unmatchedCashSql .= ' AND branch = ?';
        $ucTypes .= 's';
        $ucParams[] = $branch;
    } else {
        $unmatchedCashSql .= ' AND ' . psCompanyBranchesInSql($conn, 'branch'); // 2026-11-02 company only
    }
    $unmatchedCashSql .= ' ORDER BY id ASC LIMIT 1000';
    $ucStmt = mysqli_prepare($conn, $unmatchedCashSql);
    if ($ucStmt) {
        mysqli_stmt_bind_param($ucStmt, $ucTypes, ...$ucParams);
        if (mysqli_stmt_execute($ucStmt)) {
            $ucRes = mysqli_stmt_get_result($ucStmt);
            $procSet = array_flip($refundNumbers);
            while ($ucRes && ($cm = mysqli_fetch_assoc($ucRes))) {
                $rid = (string)$cm['reference_id'];
                if (isset($procSet[$rid])) {
                    continue;
                }
                // Could be out-of-range PROCESSED — check existence
                $chk = mysqli_prepare(
                    $conn,
                    "SELECT id FROM sale_refund_requests WHERE refund_number = ? AND status = 'PROCESSED' LIMIT 1"
                );
                $exists = false;
                if ($chk) {
                    mysqli_stmt_bind_param($chk, 's', $rid);
                    if (mysqli_stmt_execute($chk)) {
                        $cr = mysqli_stmt_get_result($chk);
                        $exists = $cr && mysqli_fetch_assoc($cr);
                    }
                    mysqli_stmt_close($chk);
                }
                if ($exists) {
                    continue; // processed outside filter window — not unmatched
                }
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_UNMATCHED,
                    'UNMATCHED_CASH_REFUND',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'CASH_REFUND with REFUND reference has no matching PROCESSED refund request.',
                    [
                        'refund_number' => $rid,
                        'branch' => (string)$cm['branch'],
                        'amount_actual' => psSaleRefundReconMoney($cm['amount']),
                        'processed_at' => (string)$cm['movement_at'],
                        'extra_key' => 'cash:' . (int)$cm['id'],
                        'evidence' => 'cash_movement_id=' . (int)$cm['id'],
                    ]
                );
            }
        }
        mysqli_stmt_close($ucStmt);
    }

    // Unmatched stock REFUND in range
    $unmatchedStockSql = "SELECT id, product_id, branch, quantity, reference_id, movement_at
                          FROM stock_movements
                          WHERE movement_type = 'REFUND' AND reference_type = 'REFUND'
                            AND movement_at >= ? AND movement_at <= ?";
    $usTypes = 'ss';
    $usParams = [$fromDt, $toDt];
    if (!$allBranches) {
        $unmatchedStockSql .= ' AND branch = ?';
        $usTypes .= 's';
        $usParams[] = $branch;
    } else {
        $unmatchedStockSql .= ' AND ' . psCompanyBranchesInSql($conn, 'branch'); // 2026-11-02 company only
    }
    $unmatchedStockSql .= ' ORDER BY id ASC LIMIT 1000';
    $usStmt = mysqli_prepare($conn, $unmatchedStockSql);
    if ($usStmt) {
        mysqli_stmt_bind_param($usStmt, $usTypes, ...$usParams);
        if (mysqli_stmt_execute($usStmt)) {
            $usRes = mysqli_stmt_get_result($usStmt);
            $procSet = array_flip($refundNumbers);
            while ($usRes && ($sm = mysqli_fetch_assoc($usRes))) {
                $rid = (string)$sm['reference_id'];
                if (isset($procSet[$rid])) {
                    continue;
                }
                $chk = mysqli_prepare(
                    $conn,
                    "SELECT id FROM sale_refund_requests WHERE refund_number = ? AND status = 'PROCESSED' LIMIT 1"
                );
                $exists = false;
                if ($chk) {
                    mysqli_stmt_bind_param($chk, 's', $rid);
                    if (mysqli_stmt_execute($chk)) {
                        $cr = mysqli_stmt_get_result($chk);
                        $exists = $cr && mysqli_fetch_assoc($cr);
                    }
                    mysqli_stmt_close($chk);
                }
                if ($exists) {
                    continue;
                }
                $findings[] = psSaleRefundReconFinding(
                    PS_REFUND_RECON_CAT_UNMATCHED,
                    'UNMATCHED_STOCK_REFUND',
                    PS_REFUND_RECON_SEVERITY_DISCREPANCY,
                    'Stock REFUND movement has no matching PROCESSED refund request.',
                    [
                        'refund_number' => $rid,
                        'branch' => (string)$sm['branch'],
                        'product_id' => (string)$sm['product_id'],
                        'qty_actual' => psStockDecimal((string)$sm['quantity']) ?? '0.000',
                        'processed_at' => (string)$sm['movement_at'],
                        'extra_key' => 'stock:' . (int)$sm['id'],
                        'evidence' => 'stock_movement_id=' . (int)$sm['id'],
                    ]
                );
            }
        }
        mysqli_stmt_close($usStmt);
    }

    // Deduplicate by discrepancy_key (re-run safe; no duplicate rows)
    $unique = [];
    foreach ($findings as $finding) {
        $unique[$finding['discrepancy_key']] = $finding;
    }
    $findings = array_values($unique);

    // Attach review status
    $keys = array_map(static function ($f) {
        return $f['discrepancy_key'];
    }, $findings);
    $reviews = psSaleRefundReconLoadReviews($conn, $keys);
    foreach ($findings as &$finding) {
        $rk = $finding['discrepancy_key'];
        if (isset($reviews[$rk])) {
            $finding['review_status'] = strtoupper(trim((string)$reviews[$rk]['review_status']));
            $finding['review_reason'] = (string)($reviews[$rk]['last_reason'] ?? '');
            $finding['reviewed_by'] = (string)($reviews[$rk]['last_reviewed_by'] ?? '');
            $finding['reviewed_at'] = (string)($reviews[$rk]['last_reviewed_at'] ?? '');
        } else {
            $finding['review_status'] = PS_REFUND_RECON_REVIEW_UNRESOLVED;
            $finding['review_reason'] = '';
            $finding['reviewed_by'] = '';
            $finding['reviewed_at'] = '';
        }
    }
    unset($finding);

    // Category / status / severity / ref filters (refs also applied in SQL on PROCESSED requests;
    // post-filter covers LEGACY/CANCEL/UNMATCHED rows that are not request-scoped).
    $categoryFilter = $f['category'];
    $statusFilter = $f['discrepancy_status'];
    $severityFilter = (string)($f['severity'] ?? '');
    $saleRefFilter = trim((string)($f['sale_ref'] ?? ''));
    $refundRefFilter = trim((string)($f['refund_ref'] ?? ''));
    $filtered = [];
    foreach ($findings as $finding) {
        if ($categoryFilter !== '' && $finding['category'] !== $categoryFilter) {
            continue;
        }
        if ($severityFilter !== '' && $finding['severity'] !== $severityFilter) {
            continue;
        }
        if ($saleRefFilter !== '' && (string)($finding['invoice_number'] ?? '') !== $saleRefFilter) {
            continue;
        }
        if ($refundRefFilter !== '' && (string)($finding['refund_number'] ?? '') !== $refundRefFilter) {
            continue;
        }
        $rs = $finding['review_status'];
        $sev = $finding['severity'];
        if ($statusFilter === 'UNRESOLVED') {
            if ($rs !== PS_REFUND_RECON_REVIEW_UNRESOLVED) {
                continue;
            }
        } elseif ($statusFilter === 'REVIEWED') {
            if ($rs !== PS_REFUND_RECON_REVIEW_REVIEWED) {
                continue;
            }
        } elseif ($statusFilter === 'INCONCLUSIVE') {
            if ($sev !== PS_REFUND_RECON_SEVERITY_INCONCLUSIVE) {
                continue;
            }
        } elseif ($statusFilter === 'INCONCLUSIVE_ACK') {
            if ($rs !== PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK) {
                continue;
            }
        } elseif ($statusFilter === 'ALL_OPEN') {
            // Unresolved discrepancies + unresolved inconclusive (default view)
            if ($rs === PS_REFUND_RECON_REVIEW_REVIEWED || $rs === PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK) {
                continue;
            }
        }
        // ALL = no status filter
        $filtered[] = $finding;
    }

    $total = count($filtered);
    $page = (int)$f['page'];
    $pageSize = (int)$f['page_size'];
    $offset = ($page - 1) * $pageSize;
    $pageRows = array_slice($filtered, $offset, $pageSize);

    // Summary matches the same filter set as the findings table (not unfiltered universe).
    $summary = [
        'processed_requests_scanned' => count($processed),
        'findings_total' => count($findings),
        'findings_filtered' => $total,
        'discrepancies' => 0,
        'inconclusive' => 0,
        'reviewed' => 0,
        'unresolved' => 0,
        'awaiting_review' => 0,
        'inconclusive_ack' => 0,
        'by_category' => [],
        'by_severity' => [
            PS_REFUND_RECON_SEVERITY_DISCREPANCY => 0,
            PS_REFUND_RECON_SEVERITY_INCONCLUSIVE => 0,
        ],
        'by_review_status' => [
            PS_REFUND_RECON_REVIEW_UNRESOLVED => 0,
            PS_REFUND_RECON_REVIEW_REVIEWED => 0,
            PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK => 0,
        ],
        'by_branch' => [],
        'amount_totals_supported' => false,
        'amount_totals_note' => 'Monetary loss totals are not invented — amount fields exist only per finding when meaningful; do not treat every finding as financial loss.',
    ];
    foreach (psSaleRefundReconCategories() as $cat) {
        $summary['by_category'][$cat] = 0;
    }
    foreach ($filtered as $finding) {
        if ($finding['severity'] === PS_REFUND_RECON_SEVERITY_DISCREPANCY) {
            $summary['discrepancies']++;
            $summary['by_severity'][PS_REFUND_RECON_SEVERITY_DISCREPANCY]++;
        }
        if ($finding['severity'] === PS_REFUND_RECON_SEVERITY_INCONCLUSIVE) {
            $summary['inconclusive']++;
            $summary['by_severity'][PS_REFUND_RECON_SEVERITY_INCONCLUSIVE]++;
        }
        $rs = $finding['review_status'];
        if ($rs === PS_REFUND_RECON_REVIEW_REVIEWED) {
            $summary['reviewed']++;
            $summary['by_review_status'][PS_REFUND_RECON_REVIEW_REVIEWED]++;
        } elseif ($rs === PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK) {
            $summary['inconclusive_ack']++;
            $summary['reviewed']++;
            $summary['by_review_status'][PS_REFUND_RECON_REVIEW_INCONCLUSIVE_ACK]++;
        } else {
            $summary['unresolved']++;
            $summary['awaiting_review']++;
            $summary['by_review_status'][PS_REFUND_RECON_REVIEW_UNRESOLVED]++;
        }
        $cat = (string)$finding['category'];
        if (!isset($summary['by_category'][$cat])) {
            $summary['by_category'][$cat] = 0;
        }
        $summary['by_category'][$cat]++;
        $br = trim((string)($finding['branch'] ?? ''));
        if ($br === '') {
            $br = '(unspecified)';
        }
        if (!isset($summary['by_branch'][$br])) {
            $summary['by_branch'][$br] = 0;
        }
        $summary['by_branch'][$br]++;
    }

    return [
        'ok' => true,
        'filters' => $f,
        'rows' => $pageRows,
        'all_filtered' => !empty($f['report_fetch_all']) ? $filtered : null,
        'total' => $total,
        'summary' => $summary,
        'limitations' => $limitations,
        'mutations_enabled' => psSaleRefundReconMutationsEnabled(),
    ];
}
