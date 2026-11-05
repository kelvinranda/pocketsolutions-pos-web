<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_master.php';
require_once __DIR__ . '/includes/customer_account.php';
require_once __DIR__ . '/includes/loyalty.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerMasterSchema($conn);
psEnsureCustomerAuditSchema($conn);
psEnsureCustomerAccountModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$notice = '';
$customerId = (int)($_GET['id'] ?? $_POST['customer_id'] ?? 0);
$row = null;
$salesHistory = null;
$auditRows = [];
$accountSummary = ['ok' => true, 'has_account' => false, 'account' => null, 'balance' => '0.00'];
$loyaltyCfg = psLoyaltyDefaults();
$loyaltyBalance = 0;
$loyaltyRows = [];
$loyaltyMore = false;
$loyaltyPage = 1;

if (!empty($_GET['created'])) {
    $notice = 'Customer created.';
}
if (!empty($_GET['updated'])) {
    $notice = 'Customer updated.';
}

// Sales history filters (read-only; GET only — viewing does not write audit).
$histPreset = strtolower(trim((string)($_GET['hist_preset'] ?? 'month')));
if (!in_array($histPreset, ['all', 'today', 'month', 'custom'], true)) {
    $histPreset = 'month';
}
$histFrom = trim((string)($_GET['hist_from'] ?? ''));
$histTo = trim((string)($_GET['hist_to'] ?? ''));
$histPage = (int)($_GET['hist_page'] ?? 1);
$histSort = strtolower(trim((string)($_GET['hist_sort'] ?? 'date_desc')));
if (!in_array($histSort, ['date_desc', 'date_asc', 'invoice_desc', 'invoice_asc'], true)) {
    $histSort = 'date_desc';
}
$histPageSize = (int)($_GET['hist_page_size'] ?? 25);
if ($histPageSize < 1 || $histPageSize > 100) {
    $histPageSize = 25;
}

if (!$signedIn) {
    $error = 'You must be signed in to view customers.';
} elseif ($customerId < 1) {
    $error = 'Customer was not found.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

        $postedStatus = (string)($_POST['new_status'] ?? '');
        $statusResult = psSetCustomerStatus($conn, $customerId, $postedStatus);
        if ($statusResult['ok']) {
            header('Location: customer_view.php?id=' . $customerId);
            exit;
        }
        $error = (string)($statusResult['error'] ?? 'Customer status could not be saved.');
    
    }
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
        $result = psUpdateCustomer($conn, $customerId, [
            'customer_type' => $_POST['customer_type'] ?? '',
            'first_name' => $_POST['first_name'] ?? '',
            'last_name' => $_POST['last_name'] ?? '',
            'business_name' => $_POST['business_name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'address' => $_POST['address'] ?? '',
            'national_id' => $_POST['national_id'] ?? '',
            'notes' => $_POST['notes'] ?? '',
        ]);
        if ($result['ok']) {
            header('Location: customer_view.php?id=' . $customerId . '&updated=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The customer could not be saved.');
    }

    // 2026-10-24: loyalty points — admin adjustment with a reason (ledger entry, never an edited balance).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loyalty_adjust'])) {
        if (!psCsrfValidate()) {
            $error = 'Invalid security token. Reload and try again.';
        } elseif (psCustomerById($conn, $customerId) === null) {
            $error = 'Customer was not found.';
        } else {
            $adj = psLoyaltyAdjust($conn, $customerId, $_POST['loyalty_points'] ?? '', (string)($_POST['loyalty_reason'] ?? ''), (string)($_SESSION['logger'] ?? ''));
            if (!empty($adj['ok'])) {
                header('Location: customer_view.php?id=' . $customerId . '&loyalty=1#loyalty');
                exit;
            }
            $error = (string)$adj['error'];
        }
    }
    if (!empty($_GET['loyalty'])) {
        $notice = 'Loyalty points adjusted.';
    }

    $row = psCustomerById($conn, $customerId);
    if (!$row) {
        $error = 'Customer was not found.';
    } else {
        psLoyaltyApplyExpiry($conn, $customerId);
        $loyaltyCfg = psLoyaltyConfig($conn);
        $loyaltyBalance = psLoyaltyBalance($conn, $customerId);
        $loyaltyPage = max(1, (int)($_GET['loyalty_page'] ?? 1));
        $loyaltyRows = psLoyaltyHistory($conn, $customerId, 26, ($loyaltyPage - 1) * 25);
        $loyaltyMore = count($loyaltyRows) > 25;
        $loyaltyRows = array_slice($loyaltyRows, 0, 25);
        $salesHistory = psCustomerSalesHistory($conn, $customerId, [
            'preset' => $histPreset,
            'from_date' => $histFrom,
            'to_date' => $histTo,
            'page' => $histPage,
            'page_size' => $histPageSize,
            'sort' => $histSort,
        ]);
        if (!$salesHistory['ok']) {
            // Keep profile visible; surface history filter/load errors separately.
            if ($error === '') {
                $error = (string)($salesHistory['error'] ?? 'Sales history could not be loaded.');
            }
        }
        $auditResult = psCustomerAuditFetchHistory($conn, [
            'customer_id' => $customerId,
            'limit' => 50,
        ]);
        if ($auditResult['ok']) {
            $auditRows = $auditResult['rows'] ?? [];
        }
        $accountSummary = psCustomerAccountSummaryForCustomer($conn, $customerId);
        if (!$accountSummary['ok'] && $error === '') {
            $error = (string)($accountSummary['error'] ?? 'Account summary could not be loaded.');
        }
    }
}

mysqli_close($conn);

$editMode = $signedIn && $row && isset($_GET['edit']);

/**
 * Build query string preserving history filters for pagination links.
 */
function psCustomerViewHistQuery(int $id, array $overrides = []): string
{
    global $histPreset, $histFrom, $histTo, $histPage, $histSort, $histPageSize;
    $q = array_merge([
        'id' => $id,
        'hist_preset' => $histPreset,
        'hist_from' => $histFrom,
        'hist_to' => $histTo,
        'hist_page' => $histPage,
        'hist_sort' => $histSort,
        'hist_page_size' => $histPageSize,
    ], $overrides);
    if (($q['hist_preset'] ?? '') !== 'custom') {
        unset($q['hist_from'], $q['hist_to']);
    }
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Customer profile</h1>
    <p class="hint">Customer code and id are stable identity and cannot be changed after create. Viewing this page does not write audit rows.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $row): ?>
        <?php
        $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
        $isActive = $status === PS_CUSTOMER_STATUS_ACTIVE;
        $id = (int)$row['id'];
        ?>

        <?php if ($editMode): ?>
            <form method="POST" action="" class="customer-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <input type="hidden" name="customer_id" value="<?php echo $id; ?>">

                <label>Customer code</label>
                <input type="text" class="readonly-code" value="<?php echo htmlspecialchars((string)$row['customer_code'], ENT_QUOTES, 'UTF-8'); ?>" readonly disabled>

                <label for="customer_type">Customer type</label>
                <select id="customer_type" name="customer_type" required>
                    <option value="<?php echo PS_CUSTOMER_TYPE_INDIVIDUAL; ?>"<?php echo strtoupper((string)$row['customer_type']) === PS_CUSTOMER_TYPE_INDIVIDUAL ? ' selected' : ''; ?>>Individual</option>
                    <option value="<?php echo PS_CUSTOMER_TYPE_BUSINESS; ?>"<?php echo strtoupper((string)$row['customer_type']) === PS_CUSTOMER_TYPE_BUSINESS ? ' selected' : ''; ?>>Business</option>
                </select>

                <label for="first_name">First name</label>
                <input type="text" id="first_name" name="first_name" maxlength="250"
                       value="<?php echo htmlspecialchars((string)$row['first_name'], ENT_QUOTES, 'UTF-8'); ?>">

                <label for="last_name">Last name</label>
                <input type="text" id="last_name" name="last_name" maxlength="250"
                       value="<?php echo htmlspecialchars((string)$row['last_name'], ENT_QUOTES, 'UTF-8'); ?>">

                <label for="business_name">Business name</label>
                <input type="text" id="business_name" name="business_name" maxlength="250"
                       value="<?php echo htmlspecialchars((string)($row['business_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" maxlength="64"
                       value="<?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?>">

                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="250"
                       value="<?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?>">

                <label for="national_id">National ID</label>
                <input type="text" id="national_id" name="national_id" maxlength="64"
                       value="<?php echo htmlspecialchars((string)($row['national_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="address">Address</label>
                <textarea id="address" name="address" maxlength="500"><?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?></textarea>

                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" maxlength="4000"><?php echo htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

                <div class="customer-actions">
                    <button type="submit" name="update" value="1">Save changes</button>
                    <a href="customer_view.php?id=<?php echo $id; ?>">Cancel</a>
                </div>
            </form>
        <?php else: ?>

            <section class="section section-first">
                <h2>Profile</h2>
                <dl class="detail-grid">
                    <dt>Code</dt>
                    <dd class="readonly-code"><?php echo htmlspecialchars((string)$row['customer_code'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Name / business</dt>
                    <dd><?php echo htmlspecialchars(psCustomerDisplayName($row), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Type</dt>
                    <dd><?php echo htmlspecialchars(psCustomerTypeLabel((string)$row['customer_type']), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <?php if (strtoupper((string)$row['customer_type']) === PS_CUSTOMER_TYPE_INDIVIDUAL): ?>
                        <dt>First name</dt>
                        <dd><?php echo htmlspecialchars((string)$row['first_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                        <dt>Last name</dt>
                        <dd><?php echo htmlspecialchars((string)$row['last_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <?php else: ?>
                        <dt>Business name</dt>
                        <dd><?php echo htmlspecialchars((string)($row['business_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <?php endif; ?>
                    <dt>Phone</dt>
                    <dd><?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Email</dt>
                    <dd><?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Address</dt>
                    <dd><?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>National ID</dt>
                    <dd><?php echo htmlspecialchars((string)($row['national_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Status</dt>
                    <dd class="<?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo htmlspecialchars(psCustomerStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!$isActive): ?>
                            <span class="hint"> — inactive customers remain viewable with history; they are not selectable on POS.</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Notes</dt>
                    <dd><?php echo nl2br(htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></dd>
                    <dt>Created</dt>
                    <dd><?php echo htmlspecialchars((string)$row['created_at'] . ' by ' . (string)$row['created_by'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Updated</dt>
                    <dd><?php echo htmlspecialchars((string)$row['updated_at'] . ' by ' . (string)$row['updated_by'], ENT_QUOTES, 'UTF-8'); ?></dd>
                </dl>

                <div class="customer-actions">
                    <a href="customer_view.php?id=<?php echo $id; ?>&amp;edit=1">Edit</a>
                    <form method="POST" action="" style="display:inline;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <input type="hidden" name="customer_id" value="<?php echo $id; ?>">
                        <?php if ($isActive): ?>
                            <input type="hidden" name="new_status" value="<?php echo PS_CUSTOMER_STATUS_INACTIVE; ?>">
                            <button type="submit" name="set_status" value="1">Deactivate</button>
                        <?php else: ?>
                            <input type="hidden" name="new_status" value="<?php echo PS_CUSTOMER_STATUS_ACTIVE; ?>">
                            <button type="submit" name="set_status" value="1">Activate</button>
                        <?php endif; ?>
                    </form>
                    <a href="customers_list.php">Back to list</a>
                </div>
            </section>

            <section class="section" id="customer-account">
                <h2>Customer account</h2>
                <p class="hint">Credit account for this customer, if one has been opened. The balance is what is owed on account, not total purchases.</p>
                <?php if (!empty($accountSummary['has_account']) && !empty($accountSummary['account'])): ?>
                    <?php
                    $acct = $accountSummary['account'];
                    $acctId = (int)$acct['id'];
                    $acctBal = (string)($accountSummary['balance'] ?? '0.00');
                    $acctSt = strtoupper(trim((string)($acct['status'] ?? '')));
                    ?>
                    <dl class="detail-grid">
                        <dt>Account exists</dt>
                        <dd>Yes</dd>
                        <dt>Account number</dt>
                        <dd class="readonly-code">
                            <a href="customer_account_view.php?id=<?php echo $acctId; ?>">
                                <?php echo htmlspecialchars((string)$acct['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </dd>
                        <dt>Account status</dt>
                        <dd><?php echo htmlspecialchars(psCustomerAccountStatusLabel($acctSt), ENT_QUOTES, 'UTF-8'); ?></dd>
                        <dt>Ledger balance</dt>
                        <dd><?php echo htmlspecialchars($acctBal, ENT_QUOTES, 'UTF-8'); ?></dd>
                    </dl>
                    <div class="customer-actions">
                        <a href="customer_account_view.php?id=<?php echo $acctId; ?>">Open account</a>
                    </div>
                <?php else: ?>
                    <dl class="detail-grid">
                        <dt>Account exists</dt>
                        <dd>No</dd>
                    </dl>
                    <div class="customer-actions">
                        <?php if ($isActive): ?>
                            <a href="create_customer_account.php?customer_master_id=<?php echo $id; ?>">Create account</a>
                        <?php else: ?>
                            <span class="hint">Activate this customer before creating an account.</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="section" id="loyalty">
                <h2>Loyalty points</h2>
                <p class="hint">Balance is the sum of the entries below (earned, used, taken back for refunds, expired and adjusted).
                    <?php echo !empty($loyaltyCfg['enabled']) ? '' : 'The loyalty programme is switched off: no points are earned or used.'; ?></p>
                <dl class="detail-grid">
                    <dt>Balance</dt>
                    <dd><strong><?php echo (int)$loyaltyBalance; ?></strong> points</dd>
                </dl>
                <?php if ($loyaltyRows): ?>
                    <div class="ps-table-wrap">
                    <table>
                        <thead><tr><th>When</th><th>Entry</th><th class="num">Points</th><th>Reference</th><th>Branch</th><th>Reason</th><th>By</th><th>Expires</th></tr></thead>
                        <tbody>
                        <?php foreach ($loyaltyRows as $lr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$lr['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psLoyaltyEntryLabel((string)$lr['entry_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="num"><?php echo ((int)$lr['points'] > 0 ? '+' : '') . (int)$lr['points']; ?></td>
                                <td><?php
                                    $refType = (string)$lr['reference_type'];
                                    $refId = (string)$lr['reference_id'];
                                    echo $refType === 'SALE'
                                        ? 'Invoice ' . htmlspecialchars($refId, ENT_QUOTES, 'UTF-8')
                                        : htmlspecialchars($refType === 'REFUND' ? $refId : ($refType === 'ADJUSTMENT' ? 'Adjustment' : 'Expiry'), ENT_QUOTES, 'UTF-8');
                                ?></td>
                                <td><?php echo htmlspecialchars((string)$lr['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$lr['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$lr['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($lr['expires_on'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="customer-actions">
                        <?php if ($loyaltyPage > 1): ?><a href="customer_view.php?id=<?php echo $id; ?>&amp;loyalty_page=<?php echo $loyaltyPage - 1; ?>#loyalty">Newer</a><?php endif; ?>
                        <?php if ($loyaltyMore): ?><a href="customer_view.php?id=<?php echo $id; ?>&amp;loyalty_page=<?php echo $loyaltyPage + 1; ?>#loyalty">Older</a><?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="hint">No loyalty entries yet.</p>
                <?php endif; ?>
                <?php if (strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin'): ?>
                    <form method="post" action="customer_view.php?id=<?php echo $id; ?>" class="ps-form-stack" style="max-width:520px;margin-top:10px">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="customer_id" value="<?php echo $id; ?>">
                        <label class="ps-label" for="loyalty_points">Adjust points (use a minus sign to remove)</label>
                        <input class="ps-input" id="loyalty_points" name="loyalty_points" inputmode="numeric" maxlength="10" required>
                        <label class="ps-label" for="loyalty_reason">Reason (required)</label>
                        <input class="ps-input" id="loyalty_reason" name="loyalty_reason" maxlength="250" required>
                        <div class="ps-form-actions"><button type="submit" class="ps-btn ps-btn-outline" name="loyalty_adjust" value="1">Record adjustment</button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="section" id="transaction-history">
                <h2>Transaction history</h2>
                <p class="hint">
                    Linked sales only where <code>online_sales_ref.customer_master_id</code> equals this customer.
                    Free-text names, Cash Customer, and legacy <code>customer_id</code> are not used.
                    <?php if ($salesHistory && !empty($salesHistory['branch_note'])): ?>
                        <?php echo htmlspecialchars((string)$salesHistory['branch_note'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </p>

                <form method="GET" action="" class="hist-toolbar">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <div>
                        <label for="hist_preset">Date range</label>
                        <select id="hist_preset" name="hist_preset">
                            <option value="month"<?php echo $histPreset === 'month' ? ' selected' : ''; ?>>This month</option>
                            <option value="today"<?php echo $histPreset === 'today' ? ' selected' : ''; ?>>Today</option>
                            <option value="all"<?php echo $histPreset === 'all' ? ' selected' : ''; ?>>All</option>
                            <option value="custom"<?php echo $histPreset === 'custom' ? ' selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    <div>
                        <label for="hist_from">From</label>
                        <input type="date" id="hist_from" name="hist_from"
                               value="<?php echo htmlspecialchars($histFrom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label for="hist_to">To</label>
                        <input type="date" id="hist_to" name="hist_to"
                               value="<?php echo htmlspecialchars($histTo, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label for="hist_sort">Sort</label>
                        <select id="hist_sort" name="hist_sort">
                            <option value="date_desc"<?php echo $histSort === 'date_desc' ? ' selected' : ''; ?>>Date (newest)</option>
                            <option value="date_asc"<?php echo $histSort === 'date_asc' ? ' selected' : ''; ?>>Date (oldest)</option>
                            <option value="invoice_desc"<?php echo $histSort === 'invoice_desc' ? ' selected' : ''; ?>>Invoice (high)</option>
                            <option value="invoice_asc"<?php echo $histSort === 'invoice_asc' ? ' selected' : ''; ?>>Invoice (low)</option>
                        </select>
                    </div>
                    <div>
                        <label for="hist_page_size">Per page</label>
                        <select id="hist_page_size" name="hist_page_size">
                            <?php foreach ([10, 25, 50, 100] as $ps): ?>
                                <option value="<?php echo $ps; ?>"<?php echo $histPageSize === $ps ? ' selected' : ''; ?>><?php echo $ps; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit">Apply</button>
                    </div>
                </form>

                <?php if ($salesHistory && !empty($salesHistory['ok'])): ?>
                    <?php
                    $sum = $salesHistory['summary'];
                    $pager = $salesHistory['pagination'];
                    $histRows = $salesHistory['rows'] ?? [];
                    ?>
                    <div class="summary">
                        <div>Linked sales (filter)<br><strong><?php echo (int)$sum['sale_count']; ?></strong></div>
                        <div>Sales history total value<br><strong><?php echo htmlspecialchars((string)$sum['sale_total'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                    <p class="hint"><?php echo htmlspecialchars((string)$sum['note'], ENT_QUOTES, 'UTF-8'); ?></p>

                    <table class="hist-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date / time</th>
                                <th>Branch</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Refs</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($histRows === []): ?>
                            <tr><td colspan="7">No linked sales for this filter.</td></tr>
                        <?php else: foreach ($histRows as $sale): ?>
                            <?php
                            $inv = (string)$sale['invoice_number'];
                            $when = trim((string)($sale['sold_at'] ?? ''));
                            if ($when === '') {
                                $when = (string)($sale['date_'] ?? '');
                            }
                            $pay = trim((string)($sale['payment_method'] ?? ''));
                            $payCur = trim((string)($sale['payment_currency'] ?? ''));
                            if ($pay !== '' && $payCur !== '') {
                                $payLabel = $pay . ' (' . $payCur . ')';
                            } elseif ($pay !== '') {
                                $payLabel = $pay;
                            } else {
                                $payLabel = '—';
                            }
                            $st = trim((string)($sale['status'] ?? ''));
                            $refs = [];
                            if (trim((string)($sale['order_number'] ?? '')) !== '') {
                                $refs[] = 'order ' . trim((string)$sale['order_number']);
                            }
                            if (!empty($sale['till_session_id'])) {
                                $refs[] = 'till ' . (int)$sale['till_session_id'];
                            }
                            $refLabel = $refs !== [] ? implode(', ', $refs) : '—';
                            ?>
                            <tr>
                                <td>
                                    <a href="my_receipt.php?invoice_number=<?php echo rawurlencode($inv); ?>"
                                       target="_blank" rel="noopener noreferrer">
                                        <?php echo htmlspecialchars($inv, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($sale['branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($sale['totalz'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($payLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($st !== '' ? $st : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($refLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>

                    <?php if ((int)$pager['total_pages'] > 1): ?>
                        <div class="pager">
                            <span>
                                Page <?php echo (int)$pager['page']; ?> of <?php echo (int)$pager['total_pages']; ?>
                                (<?php echo (int)$pager['total_rows']; ?> sales)
                            </span>
                            <?php if ((int)$pager['page'] > 1): ?>
                                <a href="customer_view.php?<?php echo htmlspecialchars(psCustomerViewHistQuery($id, ['hist_page' => (int)$pager['page'] - 1]), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                            <?php endif; ?>
                            <?php if ((int)$pager['page'] < (int)$pager['total_pages']): ?>
                                <a href="customer_view.php?<?php echo htmlspecialchars(psCustomerViewHistQuery($id, ['hist_page' => (int)$pager['page'] + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="section" id="audit-history">
                <h2>Audit history</h2>
                <p class="hint">
                    Field-level customer master changes only (CREATE / UPDATE / ACTIVATE / DEACTIVATE).
                    Separate from sales transaction history. Viewing does not create UPDATE audits.
                </p>
                <?php if ($auditRows === []): ?>
                    <p class="hint">No audit rows for this customer.</p>
                <?php else: ?>
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Field</th>
                                <th>Old</th>
                                <th>New</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($auditRows as $ar): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$ar['changed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$ar['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$ar['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($ar['old_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($ar['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$ar['changed_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="hint"><a href="customer_audit_history.php?customer_id=<?php echo $id; ?>">Full audit history</a></p>
                <?php endif; ?>
            </section>

        <?php endif; ?>
    <?php elseif ($signedIn): ?>
        <p><a href="customers_list.php">Back to customers</a></p>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
