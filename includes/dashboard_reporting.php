<?php
/**
 * Phase 10 — Management dashboard metrics (read-only).
 * Composes sales_reporting, inventory_reporting, refund summary, and Phase 7 cash helpers.
 */

require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/sales_reporting.php';
require_once __DIR__ . '/inventory_reporting.php';

/**
 * Period comparison: current vs previous equivalent window.
 *
 * @return array{ok:bool,current?:array,previous?:array,delta_revenue?:string,delta_pct?:?string,error?:string}
 */
function psDashboardPeriodSales(mysqli $conn, array $branchFilters, string $period): array
{
    $bounds = psReportingPeriodBounds($period);
    $curFilters = array_merge($branchFilters, [
        'from_date' => $bounds['from_date'],
        'to_date' => $bounds['to_date'],
    ]);
    $prevFilters = array_merge($branchFilters, [
        'from_date' => $bounds['prev_from'],
        'to_date' => $bounds['prev_to'],
    ]);
    $cur = psSalesReportingAggregate($conn, $curFilters, 'completed');
    $prev = psSalesReportingAggregate($conn, $prevFilters, 'completed');
    $legacyCur = psSalesReportingAggregate($conn, $curFilters, 'legacy');
    if (empty($cur['ok']) || empty($prev['ok'])) {
        return ['ok' => false, 'error' => $cur['error'] ?? $prev['error'] ?? 'Sales could not be loaded.'];
    }
    $curRev = (float)$cur['revenue'];
    $prevRev = (float)$prev['revenue'];
    $delta = psReportingMoneySub($cur['revenue'], $prev['revenue']);
    $deltaPct = null;
    if ($prevRev != 0.0) {
        $deltaPct = number_format((($curRev - $prevRev) / abs($prevRev)) * 100, 1, '.', '');
    }
    return [
        'ok' => true,
        'period' => $bounds,
        'current' => $cur,
        'previous' => $prev,
        'legacy_current' => $legacyCur['ok'] ? $legacyCur : null,
        'delta_revenue' => $delta,
        'delta_pct' => $deltaPct,
    ];
}

/**
 * Build management dashboard payload.
 *
 * @param array<string,mixed> $rawFilters
 * @return array{ok:bool,error?:string}
 */
function psDashboardReportingBuild(mysqli $conn, array $rawFilters = []): array
{
    psReportingEnsureTimezone();
    if (!psReportingMayView()) {
        return ['ok' => false, 'error' => 'Please sign in to view the dashboard.'];
    }
    $norm = psReportingNormalizeFilters($conn, $rawFilters);
    if (empty($norm['ok'])) {
        return ['ok' => false, 'error' => $norm['error'] ?? 'Dashboard could not be loaded.'];
    }
    $f = $norm['filters'];
    $branchOnly = [
        'branches' => $f['branches'],
        'all_branches' => $f['all_branches'],
        'branch' => $f['branch'],
        'cashier' => '',
    ];

    $today = psDashboardPeriodSales($conn, $branchOnly, 'today');
    $week = psDashboardPeriodSales($conn, $branchOnly, 'week');
    $month = psDashboardPeriodSales($conn, $branchOnly, 'month');

    // Custom range cards (filters from_date/to_date) — completed + legacy
    $rangeCompleted = psSalesReportingAggregate($conn, $f, 'completed');
    $rangeLegacy = psSalesReportingAggregate($conn, $f, 'legacy');
    $byBranch = psSalesReportingByBranch($conn, $f, 'completed');
    $byBranchLegacy = psSalesReportingByBranch($conn, $f, 'legacy');
    $payments = psSalesReportingPaymentMethods($conn, $f);
    $refunds = psSalesReportingRefundSummary($conn, $f);

    $recentFilters = array_merge($f, ['page' => 1, 'page_size' => 10]);
    $recentCompleted = psSalesReportingList($conn, $recentFilters, 'completed');
    $recentLegacy = psSalesReportingList($conn, $recentFilters, 'legacy');

    $invAlerts = psInventoryReportingAlerts($conn, $f);

    // Operational alerts
    $alerts = [];
    if (!empty($invAlerts['ok'])) {
        if ((int)($invAlerts['out_count'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'stock_out',
                'severity' => 'high',
                'message' => (int)$invAlerts['out_count'] . ' product(s) out of stock (ledger or legacy qty ≤ 0 vs reorder).',
            ];
        }
        if ((int)($invAlerts['low_count'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'stock_low',
                'severity' => 'medium',
                'message' => (int)$invAlerts['low_count'] . ' product(s) at or below reorder level.',
            ];
        }
        if ((int)($invAlerts['ledger_count'] ?? 0) === 0) {
            $alerts[] = [
                'type' => 'ledger_empty',
                'severity' => 'info',
                'message' => 'No ledger-controlled stock (OPENING_BALANCE) in scope — inventory valuation deferred.',
            ];
        }
    }
    if (!empty($rangeLegacy['ok']) && (int)$rangeLegacy['txn_count'] > 0
        && !empty($rangeCompleted['ok']) && (int)$rangeCompleted['txn_count'] === 0) {
        $alerts[] = [
            'type' => 'legacy_sales_only',
            'severity' => 'info',
            'message' => 'Filtered range has recorded legacy sales without modern COMPLETED/payment markers. Completed financial cards remain zero until modern sales exist.',
        ];
    }

    // Cash / till summary — reuse Phase 7 when module available; label NULL-branch limitation
    $cashSummary = null;
    $cashLimitation = 'Cash/till cards require Phase 7 ledger data. NULL-branch OPEN tills prevent reliable branch cash attribution.';
    if (is_file(__DIR__ . '/cash_reporting.php')) {
        require_once __DIR__ . '/cash_reporting.php';
        if (function_exists('psEnsureCashReportingModule') && function_exists('psCashReportingDashboard')) {
            psEnsureCashReportingModule($conn);
            $cashFilters = [
                'from_date' => $f['from_date'] ?? '',
                'to_date' => $f['to_date'] ?? '',
                'branch' => !empty($f['all_branches']) ? '' : ($f['branch'] ?? ''),
            ];
            $cashDash = psCashReportingDashboard($conn, $cashFilters);
            if (!empty($cashDash['ok'])) {
                $cashSummary = [
                    'ok' => true,
                    'cards' => $cashDash['cards'] ?? null,
                    'open_tills' => $cashDash['open_tills'] ?? [],
                    'warnings' => $cashDash['stale_warnings'] ?? [],
                    'read_only' => true,
                    'limitation' => $cashLimitation,
                ];
                $nullTill = false;
                foreach ($cashSummary['open_tills'] as $t) {
                    if (is_array($t) && trim((string)($t['branch'] ?? '')) === '') {
                        $nullTill = true;
                        break;
                    }
                }
                // Also probe raw OPEN sessions with NULL branch (may be outside branch filter).
                $nr = @mysqli_query(
                    $conn,
                    "SELECT COUNT(*) AS c FROM sessions WHERE status = 'OPEN' AND (branch IS NULL OR TRIM(branch) = '')"
                );
                $nrow = $nr ? mysqli_fetch_assoc($nr) : null;
                if ($nrow && (int)$nrow['c'] > 0) {
                    $nullTill = true;
                }
                if ($nullTill) {
                    $alerts[] = [
                        'type' => 'null_branch_till',
                        'severity' => 'high',
                        'message' => 'Open till session(s) have NULL/empty branch — branch cash totals are unreliable.',
                    ];
                }
            } else {
                $cashSummary = [
                    'ok' => false,
                    'error' => $cashDash['error'] ?? 'Cash dashboard unavailable.',
                    'limitation' => $cashLimitation,
                ];
            }
        }
    }

    $cancelledList = psSalesReportingList($conn, array_merge($f, ['page' => 1, 'page_size' => 1]), 'cancelled');

    return [
        'ok' => true,
        'filters' => $f,
        'today' => $today,
        'week' => $week,
        'month' => $month,
        'range_completed' => $rangeCompleted,
        'range_legacy' => $rangeLegacy,
        'by_branch_completed' => $byBranch,
        'by_branch_legacy' => $byBranchLegacy,
        'payments' => $payments,
        'refunds' => $refunds,
        'recent_completed' => $recentCompleted,
        'recent_legacy' => $recentLegacy,
        'inventory_alerts' => $invAlerts,
        'cancelled' => [
            'ok' => !empty($cancelledList['ok']),
            'txn_count' => (int)($cancelledList['totals']['txn_count'] ?? 0),
            'revenue' => (string)($cancelledList['totals']['revenue'] ?? '0.00'),
        ],
        'cash' => $cashSummary,
        'alerts' => $alerts,
        'limitations' => psReportingKnownLimitations(),
        'mutations_enabled' => psReportingMutationsEnabled(),
        'read_only' => true,
    ];
}
