<?php
/**
 * Phase 6.5 — Credit control & collections dashboard.
 * Overdue from Phase 6.4 ageing only. Manual hold after review — no auto-hold.
 * No national ID on lists. No reminder engine.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/customer_credit_control.php';
require_once __DIR__ . '/includes/product_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerCreditControlModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$dashboard = null;
$admin = function_exists('psSessionAllowsAllProductBranches') && psSessionAllowsAllProductBranches();

$search = trim((string)($_GET['search'] ?? ''));
$accountNumber = trim((string)($_GET['account_number'] ?? ''));
$creditStatus = strtoupper(trim((string)($_GET['credit_status'] ?? 'all')));
$accountStatus = strtoupper(trim((string)($_GET['account_status'] ?? 'all')));
$overdueBucket = strtolower(trim((string)($_GET['overdue_bucket'] ?? 'all')));
$asAt = trim((string)($_GET['as_at'] ?? date('Y-m-d')));
$branchFilter = trim((string)($_GET['branch'] ?? ''));

$branchOptions = [];
if (!$signedIn) {
    $error = 'You must be signed in to view credit control.';
} else {
    $branchOptions = psAuthorisedProductBranches($conn);
    $dashboard = psCustomerCreditControlDashboard($conn, [
        'search' => $search,
        'account_number' => $accountNumber,
        'credit_status' => $creditStatus,
        'account_status' => $accountStatus,
        'overdue_bucket' => $overdueBucket,
        'as_at' => $asAt,
        'branch' => $admin ? $branchFilter : null,
    ]);
    if (!$dashboard['ok']) {
        $error = (string)($dashboard['error'] ?? 'Dashboard could not be loaded.');
        $dashboard = null;
    }
}

mysqli_close($conn);

function psCcSectionTable(array $rows, string $emptyMsg): void
{
    if ($rows === []) {
        echo '<p class="hint">' . htmlspecialchars($emptyMsg, ENT_QUOTES, 'UTF-8') . '</p>';
        return;
    }
    echo '<table class="cc-table"><thead><tr>';
    echo '<th>Customer</th><th>Account</th><th>Account status</th><th>Credit status</th>';
    echo '<th>Balance</th><th>Limit</th><th>Utilisation</th><th>Overdue</th><th>Outstanding</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $id = (int)$r['customer_account_id'];
        $utilLabel = !empty($r['has_facility'])
            ? ((string)($r['utilisation_label'] ?? ''))
            : 'No facility';
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string)$r['customer_code'] . ' — ' . (string)$r['display_name'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="mono">' . htmlspecialchars((string)$r['account_number'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars(psCustomerAccountStatusLabel((string)$r['account_status']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars(psCustomerAccountCreditStatusLabel((string)$r['credit_status']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="num">' . htmlspecialchars((string)$r['balance'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="num">' . htmlspecialchars((string)$r['credit_limit'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="num">' . htmlspecialchars($utilLabel, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="num">' . htmlspecialchars((string)$r['overdue_amount'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="num">' . htmlspecialchars((string)$r['total_outstanding'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td><a href="customer_account_view.php?id=' . $id . '">Account</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Control</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Credit Control</h1>
    <p class="hint">Overdue = unpaid credit sales older than their due date (1–30, 31–60, 61–90, 91+ days). Holds are placed by an administrator after review, never automatically, and only block new credit sales.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="cc-filters">
            <div>
                <label for="search">Customer</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Code / name">
            </div>
            <div>
                <label for="account_number">Account number</label>
                <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars($accountNumber, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="credit_status">Credit status</label>
                <select id="credit_status" name="credit_status">
                    <option value="all"<?php echo $creditStatus === 'ALL' ? ' selected' : ''; ?>>All</option>
                    <option value="NORMAL"<?php echo $creditStatus === 'NORMAL' ? ' selected' : ''; ?>>Normal</option>
                    <option value="ON_HOLD"<?php echo $creditStatus === 'ON_HOLD' ? ' selected' : ''; ?>>On hold</option>
                </select>
            </div>
            <div>
                <label for="account_status">Account status</label>
                <select id="account_status" name="account_status">
                    <option value="all"<?php echo $accountStatus === 'ALL' ? ' selected' : ''; ?>>All</option>
                    <option value="ACTIVE"<?php echo $accountStatus === 'ACTIVE' ? ' selected' : ''; ?>>Active</option>
                    <option value="SUSPENDED"<?php echo $accountStatus === 'SUSPENDED' ? ' selected' : ''; ?>>Suspended</option>
                    <option value="CLOSED"<?php echo $accountStatus === 'CLOSED' ? ' selected' : ''; ?>>Closed</option>
                </select>
            </div>
            <div>
                <label for="overdue_bucket">Overdue bucket</label>
                <select id="overdue_bucket" name="overdue_bucket">
                    <option value="all"<?php echo $overdueBucket === 'all' ? ' selected' : ''; ?>>All</option>
                    <option value="1_30"<?php echo $overdueBucket === '1_30' ? ' selected' : ''; ?>>1–30</option>
                    <option value="31_60"<?php echo $overdueBucket === '31_60' ? ' selected' : ''; ?>>31–60</option>
                    <option value="61_90"<?php echo $overdueBucket === '61_90' ? ' selected' : ''; ?>>61–90</option>
                    <option value="91_plus"<?php echo $overdueBucket === '91_plus' ? ' selected' : ''; ?>>91+</option>
                </select>
            </div>
            <div>
                <label for="as_at">As-at</label>
                <input type="date" id="as_at" name="as_at" value="<?php echo htmlspecialchars($asAt, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="branch">Sale branch</label>
                <select id="branch" name="branch"<?php echo $admin ? '' : ' disabled'; ?>>
                    <?php if ($admin): ?>
                        <option value="">All authorised</option>
                    <?php endif; ?>
                    <?php foreach ($branchOptions as $bn): ?>
                        <?php $bn = (string)$bn; ?>
                        <option value="<?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $branchFilter === $bn ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($bn, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Apply</button>
                <a href="customer_credit_control.php">Reset</a>
            </div>
        </form>

        <?php if ($dashboard): ?>
            <?php $sum = $dashboard['summary']; ?>
            <div class="cc-summary">
                <div class="card">
                    <div class="label">Customers with outstanding</div>
                    <div class="value"><?php echo (int)$sum['customers_with_outstanding']; ?></div>
                </div>
                <div class="card">
                    <div class="label">Overdue receivables</div>
                    <div class="value"><?php echo htmlspecialchars((string)$sum['overdue_receivables'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="card">
                    <div class="label">On credit hold</div>
                    <div class="value"><?php echo (int)$sum['on_hold_count']; ?></div>
                </div>
                <div class="card">
                    <div class="label">At / exceeding limit</div>
                    <div class="value"><?php echo (int)$sum['at_exceeding_limit_count']; ?></div>
                </div>
                <div class="card">
                    <div class="label">Collection activities (30d)</div>
                    <div class="value"><?php echo (int)$sum['recent_activities_30d']; ?></div>
                </div>
            </div>
            <p class="hint"><?php echo htmlspecialchars((string)$sum['note'], ENT_QUOTES, 'UTF-8'); ?></p>

            <section class="section">
                <h2>Overdue accounts</h2>
                <?php psCcSectionTable($dashboard['sections']['overdue'] ?? [], 'No overdue accounts for these filters.'); ?>
            </section>

            <section class="section">
                <h2>On credit hold</h2>
                <?php psCcSectionTable($dashboard['sections']['on_hold'] ?? [], 'No accounts on credit hold.'); ?>
            </section>

            <section class="section">
                <h2>Near / exceeding credit limit</h2>
                <p class="hint">Utilisation ≥ 80% of positive balance vs limit. Limit 0 = no facility (excluded).</p>
                <?php psCcSectionTable($dashboard['sections']['near_exceeding_limit'] ?? [], 'No accounts near or exceeding limit.'); ?>
            </section>

            <section class="section">
                <h2>Significant outstanding</h2>
                <p class="hint">Credit-sale outstanding or ledger balance ≥ 100.00.</p>
                <?php psCcSectionTable($dashboard['sections']['significant_outstanding'] ?? [], 'No significant outstanding accounts.'); ?>
            </section>

            <section class="section">
                <h2>Recent collection activity</h2>
                <p class="hint"><a href="customer_collection_activity.php">View / record collection activity</a></p>
                <?php
                $acts = $dashboard['sections']['recent_collection_activity'] ?? [];
                if ($acts === []):
                ?>
                    <p class="hint">No recent collection activity.</p>
                <?php else: ?>
                    <table class="act-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Outcome</th>
                                <th>Next action</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($acts as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$a['activity_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$a['customer_code'] . ' — ' . (string)$a['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="mono"><?php echo htmlspecialchars((string)$a['account_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psCustomerCollectionActivityTypeLabel((string)$a['activity_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psCustomerCollectionActivityOutcomeLabel((string)$a['outcome']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($a['next_action_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$a['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <p class="hint" style="margin-top:24px;">
            <a href="customer_receivables_ageing.php">Receivables ageing</a> ·
            <a href="customer_accounts_list.php">Customer accounts</a> ·
            <a href="customer_collection_activity.php">Collection activity</a>
        </p>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
