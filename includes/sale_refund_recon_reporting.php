<?php
/**
 * Phase 8.6 — Refund reconciliation reporting (read-only).
 *
 * Reuses Phase 8.5 detection helpers (`psSaleRefundReconciliationRun` / review loaders).
 * Opening, filtering, and exporting this report does NOT create findings or review rows.
 * Review recording remains only via the explicit 8.5 recon UI / `psSaleRefundReconRecordReview`.
 *
 * Absolute rules:
 *   - No second recon algorithm.
 *   - No ledger / sale / stock / cash / AR mutations.
 *   - No inventing monetary loss totals.
 *   - Inconclusive ≠ confirmed discrepancy / financial loss.
 *   - Branch via psPurchasingResolveReportBranch (never trust posted foreign branch).
 */

require_once __DIR__ . '/sale_refund_reconciliation.php';

const PS_REFUND_RECON_REPORT_NA = 'Not available';
const PS_REFUND_RECON_REPORT_INCONCLUSIVE = 'Inconclusive';

/**
 * Reporting never writes financial or review data.
 */
function psSaleRefundReconReportMutationsEnabled(): bool
{
    return false;
}

function psSaleRefundReconReportMayView(): bool
{
    return psSaleRefundReconMayView();
}

function psSaleRefundReconReportIsAdmin(): bool
{
    return psSaleRefundReconIsAdmin();
}

/**
 * Display helper — never invent values.
 */
function psSaleRefundReconReportDisplayValue($value, string $fallback = PS_REFUND_RECON_REPORT_NA): string
{
    if ($value === null) {
        return $fallback;
    }
    if (is_string($value) && trim($value) === '') {
        return $fallback;
    }
    return (string)$value;
}

/**
 * @param array<string,mixed> $raw
 * @return array{ok:bool,filters?:array,error?:string}
 */
function psSaleRefundReconReportNormaliseFilters(mysqli $conn, array $raw): array
{
    if (!psSaleRefundReconReportMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view refund reconciliation reporting.'];
    }
    // Reuse 8.5 normaliser (branch/date/category/status/severity/refs/page).
    return psSaleRefundReconNormaliseFilters($conn, $raw);
}

/**
 * Run report: summary + paginated findings. Does not insert review/finding rows.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,filters?:array,rows?:list,total?:int,summary?:array,limitations?:list,pagination?:array,error?:string}
 */
function psSaleRefundReconReportRun(mysqli $conn, array $rawFilters = []): array
{
    if (!psEnsureSaleRefundReconModule($conn)) {
        return ['ok' => false, 'error' => 'Refund reconciliation reporting could not be loaded.'];
    }
    $page = max(1, (int)($rawFilters['page'] ?? 1));
    $pageSize = (int)($rawFilters['page_size'] ?? 25);
    if ($pageSize < 5) {
        $pageSize = 25;
    }
    if ($pageSize > 100) {
        $pageSize = 100;
    }

    // Fetch filtered universe once for accurate summary + pagination (capped).
    $fetchFilters = $rawFilters;
    $fetchFilters['page'] = 1;
    $fetchFilters['page_size'] = 2000;
    $fetchFilters['report_fetch_all'] = true;

    $run = psSaleRefundReconciliationRun($conn, $fetchFilters);
    if (empty($run['ok'])) {
        return ['ok' => false, 'error' => $run['error'] ?? 'Report could not be loaded.'];
    }

    $all = is_array($run['all_filtered'] ?? null) ? $run['all_filtered'] : ($run['rows'] ?? []);
    $total = (int)($run['total'] ?? count($all));
    $offset = ($page - 1) * $pageSize;
    $rows = array_slice($all, $offset, $pageSize);
    $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;

    $filters = $run['filters'] ?? [];
    $filters['page'] = $page;
    $filters['page_size'] = $pageSize;
    unset($filters['report_fetch_all']);

    return [
        'ok' => true,
        'filters' => $filters,
        'rows' => $rows,
        'total' => $total,
        'summary' => $run['summary'] ?? [],
        'limitations' => array_merge($run['limitations'] ?? [], [
            'Phase 7 cash attribution: expected cash / CASH_REFUND validation uses sale_payments method and ledger refs only; legacy sale paths outside the modern cash ledger remain out of scope.',
            'Opening or filtering this report does not create findings or review records.',
            'Confirmed DISCREPANCY findings are distinct from INCONCLUSIVE items — inconclusive is not labelled as financial loss.',
        ]),
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
        'mutations_enabled' => psSaleRefundReconReportMutationsEnabled(),
    ];
}

/**
 * Read-only review history for a discrepancy key (authoritative audit table).
 *
 * @return array{ok:bool,rows?:list<array>,error?:string}
 */
function psSaleRefundReconReportReviewHistory(mysqli $conn, string $discrepancyKey): array
{
    if (!psSaleRefundReconReportMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view review history.', 'rows' => []];
    }
    $key = trim($discrepancyKey);
    if ($key === '' || strlen($key) > 191) {
        return ['ok' => false, 'error' => 'Invalid discrepancy key.', 'rows' => []];
    }
    if (!psEnsureSaleRefundReconReviewSchema($conn)) {
        return ['ok' => true, 'rows' => []];
    }
    $rows = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, discrepancy_key, action, previous_status, new_status, reason,
                reviewed_by, reviewed_at, source
         FROM sale_refund_recon_review_audit
         WHERE discrepancy_key = ?
         ORDER BY reviewed_at ASC, id ASC
         LIMIT 500'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Review history could not be loaded.', 'rows' => []];
    }
    mysqli_stmt_bind_param($stmt, 's', $key);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Review history could not be loaded.', 'rows' => []];
    }
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Latest review snapshot (may be empty — not an error).
 *
 * @return array{ok:bool,review?:?array,error?:string}
 */
function psSaleRefundReconReportLatestReview(mysqli $conn, string $discrepancyKey): array
{
    if (!psSaleRefundReconReportMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view review status.'];
    }
    $key = trim($discrepancyKey);
    if ($key === '' || strlen($key) > 191) {
        return ['ok' => false, 'error' => 'Invalid discrepancy key.'];
    }
    $map = psSaleRefundReconLoadReviews($conn, [$key]);
    return ['ok' => true, 'review' => $map[$key] ?? null];
}

/**
 * Authorised finding detail: finding + related sale/refund/stock/cash/audit (branch-scoped).
 * Read-only; does not create review rows.
 *
 * @param array<string,mixed> $rawFilters contextual filters used to locate the finding
 * @return array{ok:bool,finding?:array,context?:array,review_history?:list,limitations?:list,error?:string}
 */
function psSaleRefundReconReportFindingDetail(mysqli $conn, string $discrepancyKey, array $rawFilters = []): array
{
    if (!psSaleRefundReconReportMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view finding details.'];
    }
    $key = trim($discrepancyKey);
    if ($key === '' || strlen($key) > 191) {
        return ['ok' => false, 'error' => 'Invalid discrepancy key.'];
    }
    if (!psEnsureSaleRefundReconModule($conn)) {
        return ['ok' => false, 'error' => 'Finding detail could not be loaded.'];
    }

    $fetch = $rawFilters;
    $fetch['page'] = 1;
    $fetch['page_size'] = 2000;
    $fetch['report_fetch_all'] = true;
    $fetch['discrepancy_status'] = $fetch['discrepancy_status'] ?? 'ALL';

    $run = psSaleRefundReconciliationRun($conn, $fetch);
    if (empty($run['ok'])) {
        return ['ok' => false, 'error' => $run['error'] ?? 'Finding detail could not be loaded.'];
    }
    $all = is_array($run['all_filtered'] ?? null) ? $run['all_filtered'] : ($run['rows'] ?? []);
    $finding = null;
    foreach ($all as $row) {
        if ((string)($row['discrepancy_key'] ?? '') === $key) {
            $finding = $row;
            break;
        }
    }
    // Fallback: broader ALL status within same date/branch window
    if ($finding === null && ($rawFilters['discrepancy_status'] ?? '') !== 'ALL') {
        $fetch['discrepancy_status'] = 'ALL';
        $fetch['category'] = '';
        $fetch['severity'] = '';
        $run2 = psSaleRefundReconciliationRun($conn, $fetch);
        if (!empty($run2['ok'])) {
            $all2 = is_array($run2['all_filtered'] ?? null) ? $run2['all_filtered'] : ($run2['rows'] ?? []);
            foreach ($all2 as $row) {
                if ((string)($row['discrepancy_key'] ?? '') === $key) {
                    $finding = $row;
                    break;
                }
            }
        }
    }
    if ($finding === null) {
        return ['ok' => false, 'error' => 'Finding was not found for the authorised filters.'];
    }

    $branch = trim((string)($finding['branch'] ?? ''));
    if ($branch !== '' && !psProductBranchIsAuthorised($conn, $branch)) {
        return ['ok' => false, 'error' => 'You do not have access to this branch.'];
    }

    $invoice = trim((string)($finding['invoice_number'] ?? ''));
    $refundNumber = trim((string)($finding['refund_number'] ?? ''));
    $context = [
        'sale_header' => null,
        'sale_lines' => [],
        'payment_method' => PS_REFUND_RECON_REPORT_NA,
        'refund_request' => null,
        'refund_lines' => [],
        'stock_movements' => [],
        'cash_movements' => [],
        'refund_audits' => [],
        'expected_stock' => PS_REFUND_RECON_REPORT_NA,
        'expected_cash' => PS_REFUND_RECON_REPORT_NA,
        'plain_explanation' => (string)($finding['why'] ?? ''),
    ];

    if ($invoice !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT invoice_number, status, branch, totalz, sold_at, date_
             FROM online_sales_ref WHERE invoice_number = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $invoice);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                $hdr = $res ? mysqli_fetch_assoc($res) : null;
                if ($hdr) {
                    $hdrBranch = trim((string)($hdr['branch'] ?? ''));
                    if ($hdrBranch !== '' && !psProductBranchIsAuthorised($conn, $hdrBranch)) {
                        mysqli_stmt_close($stmt);
                        return ['ok' => false, 'error' => 'You do not have access to this branch.'];
                    }
                    $context['sale_header'] = $hdr;
                }
            }
            mysqli_stmt_close($stmt);
        }
        $stmt = mysqli_prepare(
            $conn,
            'SELECT id, invoice_number, product_id, branch, quantity, unit_price, revenue, status
             FROM online_sales WHERE invoice_number = ? ORDER BY id ASC LIMIT 200'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $invoice);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($ln = mysqli_fetch_assoc($res))) {
                    $lb = trim((string)($ln['branch'] ?? ''));
                    if ($lb !== '' && !psProductBranchIsAuthorised($conn, $lb)) {
                        continue;
                    }
                    $context['sale_lines'][] = $ln;
                }
            }
            mysqli_stmt_close($stmt);
        }
        $stmt = mysqli_prepare(
            $conn,
            'SELECT method FROM sale_payments WHERE invoice_number = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $invoice);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                $p = $res ? mysqli_fetch_assoc($res) : null;
                if ($p && trim((string)$p['method']) !== '') {
                    $context['payment_method'] = strtoupper(trim((string)$p['method']));
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    if ($refundNumber !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT * FROM sale_refund_requests WHERE refund_number = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $refundNumber);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                $req = $res ? mysqli_fetch_assoc($res) : null;
                if ($req) {
                    $rb = trim((string)($req['branch'] ?? ''));
                    if ($rb !== '' && !psProductBranchIsAuthorised($conn, $rb)) {
                        mysqli_stmt_close($stmt);
                        return ['ok' => false, 'error' => 'You do not have access to this branch.'];
                    }
                    $context['refund_request'] = $req;
                    $rid = (int)$req['id'];
                    $lst = mysqli_prepare(
                        $conn,
                        'SELECT * FROM sale_refund_request_lines WHERE request_id = ? ORDER BY id ASC LIMIT 200'
                    );
                    if ($lst) {
                        mysqli_stmt_bind_param($lst, 'i', $rid);
                        if (mysqli_stmt_execute($lst)) {
                            $lr = mysqli_stmt_get_result($lst);
                            while ($lr && ($rl = mysqli_fetch_assoc($lr))) {
                                $context['refund_lines'][] = $rl;
                            }
                        }
                        mysqli_stmt_close($lst);
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, product_id, branch, quantity, reference_type, reference_id, movement_type, movement_at, notes
             FROM stock_movements
             WHERE movement_type = 'REFUND' AND reference_type = 'REFUND' AND reference_id = ?
             ORDER BY id ASC LIMIT 200"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $refundNumber);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($sm = mysqli_fetch_assoc($res))) {
                    $sb = trim((string)($sm['branch'] ?? ''));
                    if ($sb !== '' && !psProductBranchIsAuthorised($conn, $sb)) {
                        continue;
                    }
                    $context['stock_movements'][] = $sm;
                }
            }
            mysqli_stmt_close($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, till_session_id, branch, amount, reference_type, reference_id, movement_type, movement_at
             FROM cash_movements
             WHERE movement_type = 'CASH_REFUND'
               AND reference_type = 'REFUND' AND reference_id = ?
             ORDER BY id ASC LIMIT 50"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $refundNumber);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($cm = mysqli_fetch_assoc($res))) {
                    $cb = trim((string)($cm['branch'] ?? ''));
                    if ($cb !== '' && !psProductBranchIsAuthorised($conn, $cb)) {
                        continue;
                    }
                    $context['cash_movements'][] = $cm;
                }
            }
            mysqli_stmt_close($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            'SELECT * FROM sale_refund_audit WHERE refund_number = ? ORDER BY id ASC LIMIT 50'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $refundNumber);
            if (mysqli_stmt_execute($stmt)) {
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($a = mysqli_fetch_assoc($res))) {
                    $context['refund_audits'][] = $a;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Expected stock/cash labels from finding severity + payment method (no invented amounts).
    $sev = (string)($finding['severity'] ?? '');
    if ($sev === PS_REFUND_RECON_SEVERITY_INCONCLUSIVE) {
        $context['expected_stock'] = PS_REFUND_RECON_REPORT_INCONCLUSIVE;
        $context['expected_cash'] = PS_REFUND_RECON_REPORT_INCONCLUSIVE;
    } else {
        if ($finding['qty_expected'] !== null) {
            $context['expected_stock'] = 'Expected qty ' . (string)$finding['qty_expected']
                . ' (actual ' . psSaleRefundReconReportDisplayValue($finding['qty_actual'] ?? null) . ')';
        } elseif (!empty($context['refund_lines'])) {
            $context['expected_stock'] = 'One stock REFUND per processed request line (product+branch+qty; REFUND + REF).';
        }
        $method = $context['payment_method'];
        if ($method === 'CASH') {
            if ($finding['amount_expected'] !== null) {
                $context['expected_cash'] = 'Expected CASH_REFUND '
                    . (string)$finding['amount_expected']
                    . ' (actual ' . psSaleRefundReconReportDisplayValue($finding['amount_actual'] ?? null) . ')';
            } else {
                $context['expected_cash'] = 'One CASH_REFUND equal to processed amount (REFUND + REF).';
            }
        } elseif ($method !== PS_REFUND_RECON_REPORT_NA && $method !== '') {
            $context['expected_cash'] = 'No CASH_REFUND expected for non-cash tender (' . $method . ').';
        } else {
            $context['expected_cash'] = PS_REFUND_RECON_REPORT_INCONCLUSIVE;
        }
    }

    $hist = psSaleRefundReconReportReviewHistory($conn, $key);
    $historyRows = (!empty($hist['ok']) && is_array($hist['rows'] ?? null)) ? $hist['rows'] : [];

    return [
        'ok' => true,
        'finding' => $finding,
        'context' => $context,
        'review_history' => $historyRows,
        'limitations' => $run['limitations'] ?? [],
        'mutations_enabled' => psSaleRefundReconReportMutationsEnabled(),
    ];
}

/**
 * CSV export respecting the same filters + branch scope. Cap 2000 rows.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,filename?:string,headers?:list,rows?:list,error?:string}
 */
function psSaleRefundReconReportExportCsv(mysqli $conn, array $rawFilters = []): array
{
    if (!psSaleRefundReconReportMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to export.'];
    }
    $rawFilters['page'] = 1;
    $rawFilters['page_size'] = 2000;
    $rawFilters['report_fetch_all'] = true;
    $run = psSaleRefundReconciliationRun($conn, $rawFilters);
    if (empty($run['ok'])) {
        return ['ok' => false, 'error' => $run['error'] ?? 'Export failed.'];
    }
    $all = is_array($run['all_filtered'] ?? null) ? $run['all_filtered'] : ($run['rows'] ?? []);
    $headers = [
        'Discrepancy key',
        'Branch',
        'Sale ref',
        'Refund ref',
        'Category',
        'Code',
        'Severity',
        'Description',
        'Qty expected',
        'Qty actual',
        'Amount expected',
        'Amount actual',
        'Recon status',
        'Review status',
        'Last reviewed at',
        'Last reviewer',
        'Processed at',
    ];
    $lines = [];
    foreach ($all as $row) {
        $sev = (string)($row['severity'] ?? '');
        $qtyExp = $row['qty_expected'] ?? null;
        $qtyAct = $row['qty_actual'] ?? null;
        $amtExp = $row['amount_expected'] ?? null;
        $amtAct = $row['amount_actual'] ?? null;
        if ($sev === PS_REFUND_RECON_SEVERITY_INCONCLUSIVE) {
            // Do not fabricate; show Inconclusive when expected values are absent.
            if ($qtyExp === null) {
                $qtyExp = PS_REFUND_RECON_REPORT_INCONCLUSIVE;
            }
            if ($amtExp === null) {
                $amtExp = PS_REFUND_RECON_REPORT_INCONCLUSIVE;
            }
        }
        $lines[] = [
            (string)($row['discrepancy_key'] ?? ''),
            (string)($row['branch'] ?? ''),
            (string)($row['invoice_number'] ?? ''),
            (string)($row['refund_number'] ?? ''),
            (string)($row['category'] ?? ''),
            (string)($row['code'] ?? ''),
            $sev,
            (string)($row['why'] ?? ''),
            $qtyExp === null || $qtyExp === '' ? PS_REFUND_RECON_REPORT_NA : (string)$qtyExp,
            $qtyAct === null || $qtyAct === '' ? PS_REFUND_RECON_REPORT_NA : (string)$qtyAct,
            $amtExp === null || $amtExp === '' ? PS_REFUND_RECON_REPORT_NA : (string)$amtExp,
            $amtAct === null || $amtAct === '' ? PS_REFUND_RECON_REPORT_NA : (string)$amtAct,
            $sev === PS_REFUND_RECON_SEVERITY_INCONCLUSIVE
                ? PS_REFUND_RECON_REPORT_INCONCLUSIVE
                : (string)$sev,
            (string)($row['review_status'] ?? ''),
            (string)($row['reviewed_at'] ?? ''),
            (string)($row['reviewed_by'] ?? ''),
            (string)($row['processed_at'] ?? ''),
        ];
    }
    $from = preg_replace('/[^0-9-]/', '', (string)($run['filters']['from_date'] ?? ''));
    $to = preg_replace('/[^0-9-]/', '', (string)($run['filters']['to_date'] ?? ''));
    $filename = 'refund_recon_report_' . ($from !== '' ? $from : 'all') . '_' . ($to !== '' ? $to : 'all') . '.csv';
    return [
        'ok' => true,
        'filename' => $filename,
        'headers' => $headers,
        'rows' => $lines,
        'empty' => count($lines) === 0,
    ];
}

/**
 * @param array{headers:list<string>,rows:list<list<string>>,filename:string} $export
 */
function psSaleRefundReconReportSendCsv(array $export): void
{
    $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($export['filename'] ?? 'refund_recon_report.csv'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, $export['headers'] ?? []);
    $rows = $export['rows'] ?? [];
    if (!$rows) {
        fputcsv($out, ['No findings for these filters.']);
    } else {
        foreach ($rows as $line) {
            fputcsv($out, $line);
        }
    }
    fclose($out);
}
