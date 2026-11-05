<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_account.php';
require_once __DIR__ . '/includes/customer_account_payment.php';
require_once __DIR__ . '/includes/customer_account_reporting.php';
require_once __DIR__ . '/includes/customer_credit_control.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerAccountModule($conn);
psEnsureCustomerAccountPaymentModule($conn);
psEnsureCustomerCreditControlModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$mayManageCredit = psCustomerAccountCreditControlMayManage();
// 2026-10-17: Supervisors / Management may set credit limits; status, holds and opening balances stay admin-only.
$maySetLimit = psCustomerAccountMaySetLimit();
$isAdminLevel = strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';
$error = '';
$notice = '';
$accountId = (int)($_GET['id'] ?? $_POST['account_id'] ?? 0);
$account = null;
$customer = null;
$balance = '0.00';
$availableCredit = '0.00';
$creditSaleStats = ['count' => 0, 'total' => '0.00'];
$openReceivable = ['open_receivable' => '0.00', 'credit_sale_outstanding_count' => 0];
$ageingSummary = null;
$recentPayments = [];
$recentCollections = [];
$ledger = null;
$auditRows = [];

$histPage = (int)($_GET['hist_page'] ?? 1);
$histPageSize = (int)($_GET['hist_page_size'] ?? 25);
if ($histPageSize < 1 || $histPageSize > 100) {
    $histPageSize = 25;
}

if (!empty($_GET['created'])) {
    $notice = 'Customer account created.';
}
if (!empty($_GET['updated'])) {
    $notice = 'Account updated.';
}
if (!empty($_GET['opening'])) {
    $notice = 'Opening balance posted.';
}
if (!empty($_GET['held'])) {
    $notice = 'Credit hold placed.';
}
if (!empty($_GET['released'])) {
    $notice = 'Credit hold released.';
}

if (!$signedIn) {
    $error = 'You must be signed in to view customer accounts.';
} elseif ($accountId < 1) {
    $error = 'Account was not found.';
} else {
    $mutatingPost = $_SERVER['REQUEST_METHOD'] === 'POST' && (
        isset($_POST['set_status'])
        || isset($_POST['place_credit_hold'])
        || isset($_POST['release_credit_hold'])
        || isset($_POST['update_limit'])
        || isset($_POST['post_opening'])
    );
    if ($mutatingPost && !psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status']) && !$isAdminLevel) {
        $error = 'Only an administrator can change the account status.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
        $postedStatus = (string)($_POST['new_status'] ?? '');
        $statusResult = psSetCustomerAccountStatus($conn, $accountId, $postedStatus);
        if ($statusResult['ok']) {
            header('Location: customer_account_view.php?id=' . $accountId);
            exit;
        }
        $error = (string)($statusResult['error'] ?? 'Account status could not be saved.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_credit_hold'])) {
        $holdResult = psCustomerAccountPlaceCreditHold($conn, $accountId, [
            'reason' => $_POST['hold_reason'] ?? '',
            'note' => $_POST['hold_note'] ?? '',
            // Tamper probes ignored:
            'credit_status' => $_POST['credit_status'] ?? 'NORMAL',
            'balance' => $_POST['balance'] ?? '',
            'credit_limit' => $_POST['credit_limit'] ?? '',
        ]);
        if ($holdResult['ok']) {
            header('Location: customer_account_view.php?id=' . $accountId . '&held=1');
            exit;
        }
        $error = (string)($holdResult['error'] ?? 'Credit hold could not be saved.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_credit_hold'])) {
        $relResult = psCustomerAccountReleaseCreditHold($conn, $accountId, [
            'note' => $_POST['release_note'] ?? '',
            'credit_status' => $_POST['credit_status'] ?? 'ON_HOLD',
            'balance' => $_POST['balance'] ?? '',
        ]);
        if ($relResult['ok']) {
            header('Location: customer_account_view.php?id=' . $accountId . '&released=1');
            exit;
        }
        $error = (string)($relResult['error'] ?? 'Credit hold could not be released.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_limit'])) {
        $result = psUpdateCustomerAccount($conn, $accountId, [
            'credit_limit' => $_POST['credit_limit'] ?? '0',
            'reason' => $_POST['limit_reason'] ?? '',
            // Tamper probes ignored by helper:
            'account_number' => $_POST['account_number'] ?? 'ACC-HACK',
            'balance' => $_POST['balance'] ?? '9999',
            'customer_master_id' => $_POST['customer_master_id'] ?? 0,
            'credit_status' => $_POST['credit_status'] ?? 'NORMAL',
        ]);
        if ($result['ok']) {
            header('Location: customer_account_view.php?id=' . $accountId . '&updated=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The account could not be saved.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_opening'])) {
        $obResult = psCustomerAccountPostOpeningBalance($conn, $accountId, [
            'amount' => $_POST['ob_amount'] ?? '',
            'side' => $_POST['ob_side'] ?? 'DEBIT',
            'reason' => $_POST['ob_reason'] ?? '',
            'entry_date' => $_POST['ob_date'] ?? date('Y-m-d'),
            'account_number' => $_POST['account_number'] ?? '',
            'balance' => $_POST['balance'] ?? '',
        ]);
        if ($obResult['ok']) {
            header('Location: customer_account_view.php?id=' . $accountId . '&opening=1');
            exit;
        }
        $error = (string)($obResult['error'] ?? 'Opening balance could not be saved.');
    }
    } // CSRF gate

    $account = psCustomerAccountById($conn, $accountId);
    if (!$account) {
        $error = 'Account was not found.';
    } else {
        $customer = psCustomerById($conn, (int)$account['customer_master_id']);
        $bal = psCustomerAccountBalance($conn, $accountId);
        $balance = $bal['ok'] ? (string)$bal['balance'] : '0.00';
        $avail = psCustomerAccountAvailableCredit($conn, $accountId, $account['credit_limit'] ?? 0);
        $availableCredit = $avail['ok'] ? (string)$avail['available_credit'] : '0.00';
        $csStats = psCustomerAccountCreditSaleStats($conn, $accountId);
        if ($csStats['ok']) {
            $creditSaleStats = [
                'count' => (int)$csStats['count'],
                'total' => (string)$csStats['total'],
            ];
        }
        $recv = psCustomerAccountOpenReceivable($conn, $accountId);
        if ($recv['ok']) {
            $openReceivable = [
                'open_receivable' => (string)$recv['open_receivable'],
                'credit_sale_outstanding_count' => (int)$recv['credit_sale_outstanding_count'],
            ];
        }
        $ageingSummary = psCustomerAccountAgeingSummary($conn, $accountId);
        if (!$ageingSummary['ok']) {
            $ageingSummary = null;
        }
        $payList = psCustomerAccountPaymentListForAccount($conn, $accountId, 10);
        if ($payList['ok']) {
            $recentPayments = $payList['rows'] ?? [];
        }
        $collList = psCustomerCollectionActivityRecentForAccount($conn, $accountId, 10);
        if ($collList['ok']) {
            $recentCollections = $collList['rows'] ?? [];
        }
        $ledger = psCustomerAccountLedgerHistory($conn, $accountId, [
            'page' => $histPage,
            'page_size' => $histPageSize,
        ]);
        if (!$ledger['ok'] && $error === '') {
            $error = (string)($ledger['error'] ?? 'Ledger history could not be loaded.');
        }
        $auditResult = psCustomerAccountAuditFetchHistory($conn, [
            'customer_account_id' => $accountId,
            'limit' => 50,
        ]);
        if ($auditResult['ok']) {
            $auditRows = $auditResult['rows'] ?? [];
        }
    }
}

$hasOpening = false;
if ($account) {
    $obCheck = mysqli_prepare(
        $conn,
        'SELECT id FROM customer_account_ledger WHERE customer_account_id = ? AND entry_type = ? LIMIT 1'
    );
    if ($obCheck) {
        $obType = PS_CUST_ACCT_ENTRY_OPENING_BALANCE;
        mysqli_stmt_bind_param($obCheck, 'is', $accountId, $obType);
        mysqli_stmt_execute($obCheck);
        $obRes = mysqli_stmt_get_result($obCheck);
        $hasOpening = $obRes && mysqli_fetch_assoc($obRes) ? true : false;
        mysqli_stmt_close($obCheck);
    }
}

$acctCurrencyLabel = $account ? psCustomerAccountRowCurrency($conn, $account) : '';
require_once __DIR__ . '/includes/currency.php';

function psAcctViewHistQuery(int $id, array $overrides = []): string
{
    global $histPage, $histPageSize;
    $q = array_merge([
        'id' => $id,
        'hist_page' => $histPage,
        'hist_page_size' => $histPageSize,
    ], $overrides);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Account</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Customer account</h1>
    <p class="hint">Positive balance = the customer owes you; negative = the customer is in credit. Available credit = credit limit minus what is owed. Putting an account On Hold blocks new credit sales only; payments still work.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $account): ?>
        <?php
        $st = strtoupper(trim((string)$account['status']));
        $stClass = 'status-closed';
        if ($st === PS_CUST_ACCT_STATUS_ACTIVE) {
            $stClass = 'status-active';
        } elseif ($st === PS_CUST_ACCT_STATUS_SUSPENDED) {
            $stClass = 'status-suspended';
        }
        $cs = strtoupper(trim((string)($account['credit_status'] ?? PS_CUST_ACCT_CREDIT_NORMAL)));
        if ($cs === '') {
            $cs = PS_CUST_ACCT_CREDIT_NORMAL;
        }
        $csClass = $cs === PS_CUST_ACCT_CREDIT_ON_HOLD ? 'status-suspended' : 'status-active';
        $cid = (int)$account['customer_master_id'];
        ?>

        <section class="section section-first">
            <h2>Account</h2>
            <dl class="detail-grid">
                <dt>Account number</dt>
                <dd class="mono"><?php echo htmlspecialchars((string)$account['account_number'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Currency</dt>
                <dd><?php echo htmlspecialchars($acctCurrencyLabel, ENT_QUOTES, 'UTF-8'); ?> <span class="hint">— balance, limit and ledger amounts are in this currency</span></dd>
                <dt>Customer</dt>
                <dd>
                    <?php if ($customer): ?>
                        <a href="customer_view.php?id=<?php echo $cid; ?>">
                            <?php echo htmlspecialchars((string)$customer['customer_code'] . ' — ' . psCustomerDisplayName($customer), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php if (!psCustomerStatusIsActive($customer['status'] ?? '')): ?>
                            <span class="hint">(customer inactive — account remains viewable)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        Customer #<?php echo $cid; ?>
                    <?php endif; ?>
                </dd>
                <dt>Account status</dt>
                <dd class="<?php echo $stClass; ?>">
                    <?php echo htmlspecialchars(psCustomerAccountStatusLabel($st), ENT_QUOTES, 'UTF-8'); ?>
                    <span class="hint"> (lifecycle — not credit hold)</span>
                </dd>
                <dt>Credit status</dt>
                <dd class="<?php echo $csClass; ?>">
                    <?php echo htmlspecialchars(psCustomerAccountCreditStatusLabel($cs), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($cs === PS_CUST_ACCT_CREDIT_ON_HOLD): ?>
                        <?php
                        $hr = trim((string)($account['credit_hold_reason'] ?? ''));
                        $hn = trim((string)($account['credit_hold_note'] ?? ''));
                        ?>
                        <?php if ($hr !== ''): ?>
                            <span class="hint">
                                — <?php echo htmlspecialchars(psCustomerAccountCreditHoldReasonLabel($hr), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($hn !== ''): ?>
                                    (<?php echo htmlspecialchars($hn, ENT_QUOTES, 'UTF-8'); ?>)
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <div class="hint">New CREDIT sales blocked. Payments, allocations, statements, and viewing remain OK.</div>
                    <?php endif; ?>
                </dd>
                <dt>Ledger balance</dt>
                <dd class="num mono"><?php echo htmlspecialchars(psFxFormat($conn, $balance, $acctCurrencyLabel ?: psFxLegacyCurrency()), ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Credit limit</dt>
                <dd class="num"><?php echo htmlspecialchars(psFxFormat($conn, $account['credit_limit'], $acctCurrencyLabel ?: psFxLegacyCurrency()), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ((float)$account['credit_limit'] <= 0): ?>
                        <span class="hint"> (no credit facility)</span>
                    <?php endif; ?>
                </dd>
                <dt>Available credit</dt>
                <dd class="num mono"><?php echo htmlspecialchars($availableCredit, ENT_QUOTES, 'UTF-8'); ?>
                    <span class="hint"> (limit − max(balance, 0))</span>
                </dd>
                <dt>Credit sales</dt>
                <dd class="num">
                    <?php echo (int)$creditSaleStats['count']; ?> sale(s) ·
                    $<?php echo htmlspecialchars($creditSaleStats['total'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="hint"> (CREDIT_SALE ledger total)</span>
                </dd>
                <dt>Open credit-sale outstanding</dt>
                <dd class="num mono">
                    <?php echo htmlspecialchars($openReceivable['open_receivable'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="hint">
                        (<?php echo (int)$openReceivable['credit_sale_outstanding_count']; ?> invoice(s) with remaining allocation)
                    </span>
                </dd>
                <?php if ($ageingSummary): ?>
                    <?php $ab = $ageingSummary['buckets']; ?>
                    <dt>Ageing (as-at <?php echo htmlspecialchars((string)$ageingSummary['as_at'], ENT_QUOTES, 'UTF-8'); ?>)</dt>
                    <dd class="num mono">
                        Current <?php echo htmlspecialchars((string)$ab['current'], ENT_QUOTES, 'UTF-8'); ?>
                        · 1–30 <?php echo htmlspecialchars((string)$ab['1_30'], ENT_QUOTES, 'UTF-8'); ?>
                        · 31–60 <?php echo htmlspecialchars((string)$ab['31_60'], ENT_QUOTES, 'UTF-8'); ?>
                        · 61–90 <?php echo htmlspecialchars((string)$ab['61_90'], ENT_QUOTES, 'UTF-8'); ?>
                        · 91+ <?php echo htmlspecialchars((string)$ab['91_plus'], ENT_QUOTES, 'UTF-8'); ?>
                        · Total <?php echo htmlspecialchars((string)$ageingSummary['total_outstanding'], ENT_QUOTES, 'UTF-8'); ?>
                        <span class="hint"> (due date = CREDIT_SALE entry date)</span>
                    </dd>
                <?php endif; ?>
                <dt>Created</dt>
                <dd><?php echo htmlspecialchars((string)$account['created_at'] . ' by ' . (string)$account['created_by'], ENT_QUOTES, 'UTF-8'); ?></dd>
                <dt>Updated</dt>
                <dd><?php echo htmlspecialchars((string)$account['updated_at'] . ' by ' . (string)$account['updated_by'], ENT_QUOTES, 'UTF-8'); ?></dd>
            </dl>

            <div class="acct-actions">
                <?php if ($isAdminLevel && $st !== PS_CUST_ACCT_STATUS_ACTIVE): ?>
                    <form method="POST" action="" style="display:inline;">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <input type="hidden" name="new_status" value="<?php echo PS_CUST_ACCT_STATUS_ACTIVE; ?>">
                        <button type="submit" name="set_status" value="1">Activate</button>
                    </form>
                <?php endif; ?>
                <?php if ($isAdminLevel && $st !== PS_CUST_ACCT_STATUS_SUSPENDED): ?>
                    <form method="POST" action="" style="display:inline;">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <input type="hidden" name="new_status" value="<?php echo PS_CUST_ACCT_STATUS_SUSPENDED; ?>">
                        <button type="submit" name="set_status" value="1">Suspend</button>
                    </form>
                <?php endif; ?>
                <?php if ($isAdminLevel && $st !== PS_CUST_ACCT_STATUS_CLOSED): ?>
                    <form method="POST" action="" style="display:inline;">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <input type="hidden" name="new_status" value="<?php echo PS_CUST_ACCT_STATUS_CLOSED; ?>">
                        <button type="submit" name="set_status" value="1">Close</button>
                    </form>
                <?php endif; ?>
                <?php if ($st !== PS_CUST_ACCT_STATUS_CLOSED): ?>
                    <a href="create_customer_account_payment.php?account_id=<?php echo $accountId; ?>">Record payment</a>
                <?php endif; ?>
                <a href="customer_account_payments.php">Account payments</a>
                <a href="customer_account_statement.php?account_id=<?php echo $accountId; ?>">Statement</a>
                <a href="customer_receivables_ageing.php?account_id=<?php echo $accountId; ?>">Ageing</a>
                <a href="customer_collection_activity.php?account_id=<?php echo $accountId; ?>">Collection activity</a>
                <a href="customer_credit_control.php">Credit control</a>
                <a href="customer_account_audit_history.php?customer_account_id=<?php echo $accountId; ?>">Audit history</a>
                <a href="customer_accounts_list.php">Back to accounts</a>
            </div>

            <section class="section" id="credit-hold">
                <h2>Credit hold</h2>
                <p class="hint">
                    Separate from Activate / Suspend / Close. Place or release hold here (not via profile edit).
                    Admin only. Cashiers cannot release hold from POS to complete a credit sale — server rejects ON_HOLD.
                </p>
                <?php if (!$mayManageCredit): ?>
                    <p class="hint">Only an administrator can place or release a credit hold.</p>
                <?php elseif ($cs === PS_CUST_ACCT_CREDIT_ON_HOLD): ?>
                    <form method="POST" action="" class="acct-form">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <label for="release_note">Release note (optional)</label>
                        <textarea id="release_note" name="release_note" maxlength="500" rows="2"></textarea>
                        <div class="acct-actions">
                            <button type="submit" name="release_credit_hold" value="1">Release credit hold</button>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="POST" action="" class="acct-form">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <label for="hold_reason">Hold reason</label>
                        <select id="hold_reason" name="hold_reason" required>
                            <option value="">Select reason</option>
                            <?php foreach (psCustomerAccountCreditHoldReasons() as $hr): ?>
                                <option value="<?php echo htmlspecialchars($hr, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(psCustomerAccountCreditHoldReasonLabel($hr), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="hold_note">Note (required when reason is Other)</label>
                        <textarea id="hold_note" name="hold_note" maxlength="500" rows="2"></textarea>
                        <div class="acct-actions">
                            <button type="submit" name="place_credit_hold" value="1">Place credit hold</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <form method="POST" action="" class="acct-form" style="margin-top: 16px;">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                <label for="credit_limit">Update credit limit</label>
                <input type="text" id="credit_limit" name="credit_limit"
                       value="<?php echo htmlspecialchars(number_format((float)$account['credit_limit'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo $maySetLimit ? '' : ' readonly'; ?>>
                <label for="limit_reason">Reason (required)</label>
                <input type="text" id="limit_reason" name="limit_reason" maxlength="500"
                       <?php echo $maySetLimit ? ' required' : ' readonly'; ?>
                       placeholder="Why is the limit changing?">
                <div class="acct-actions">
                    <?php if ($maySetLimit): ?>
                        <button type="submit" name="update_limit" value="1">Save credit limit</button>
                        <span class="hint">0.00 = no credit. Above 0, account sales are allowed up to this balance. Every change is recorded with your reason.</span>
                    <?php else: ?>
                        <span class="hint">Only a Supervisor, Manager or Admin can change the credit limit.</span>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="section">
            <h2>Opening balance</h2>
            <p class="hint">
                Explicit admin action only. One opening-balance entry per account. Requires a reason.
                Do not invent balances from historical sales. Reverse later via adjustment (not delete) when that module exists.
            </p>
            <?php if ($hasOpening): ?>
                <p class="hint">An opening balance has already been posted for this account.</p>
            <?php else: ?>
                <form method="POST" action="" class="acct-form">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                    <label for="ob_side">Side</label>
                    <select id="ob_side" name="ob_side">
                        <option value="DEBIT">Debit — customer owes</option>
                        <option value="CREDIT">Credit — customer credit</option>
                    </select>
                    <label for="ob_amount">Amount</label>
                    <input type="text" id="ob_amount" name="ob_amount" required placeholder="0.00">
                    <label for="ob_date">Entry date</label>
                    <input type="date" id="ob_date" name="ob_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="ob_reason">Reason</label>
                    <textarea id="ob_reason" name="ob_reason" required maxlength="500" rows="3"></textarea>
                    <div class="acct-actions">
                        <button type="submit" name="post_opening" value="1">Post opening balance</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="section" id="recent-collections">
            <h2>Recent collection activity</h2>
            <p class="hint">
                Non-financial contact history. Does not change balance.
                <a href="customer_collection_activity.php?account_id=<?php echo $accountId; ?>">Record / view all</a>
            </p>
            <?php if ($recentCollections === []): ?>
                <p class="hint">No collection activity yet.</p>
            <?php else: ?>
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Outcome</th>
                            <th>Notes</th>
                            <th>Next action</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentCollections as $ca): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$ca['activity_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(psCustomerCollectionActivityTypeLabel((string)$ca['activity_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(psCustomerCollectionActivityOutcomeLabel((string)$ca['outcome']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ca['notes'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($ca['next_action_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$ca['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="section" id="recent-payments">
            <h2>Recent account payments</h2>
            <p class="hint">
                PAY-###### receipts (not POS sale_payments). Allocations optional; unallocated allowed.
                Till cash integration disabled.
            </p>
            <?php if ($recentPayments === []): ?>
                <p class="hint">No account payments yet.</p>
            <?php else: ?>
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Payment #</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Allocated</th>
                            <th>Unallocated</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentPayments as $pr): ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars((string)$pr['payment_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$pr['payment_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(psCustomerAccountPaymentMethodLabel($pr['payment_method']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars(psFxFormat($conn, $pr['amount'], $acctCurrencyLabel ?: psFxLegacyCurrency()), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$pr['allocated'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars((string)$pr['unallocated'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$pr['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><a href="customer_account_payment_view.php?id=<?php echo (int)$pr['id']; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="section" id="ledger-history">
            <h2>Ledger history</h2>
            <p class="hint">Account transactions, newest first. Use Statement for a running balance.</p>
            <?php if ($ledger && !empty($ledger['ok'])): ?>
                <?php
                $rows = $ledger['rows'] ?? [];
                $total = (int)($ledger['total'] ?? 0);
                $page = (int)($ledger['page'] ?? 1);
                $pages = (int)($ledger['pages'] ?? 1);
                ?>
                <form method="GET" action="" class="acct-actions">
                    <input type="hidden" name="id" value="<?php echo $accountId; ?>">
                    <label for="hist_page_size">Per page
                        <select id="hist_page_size" name="hist_page_size" onchange="this.form.submit()">
                            <?php foreach ([10, 25, 50, 100] as $ps): ?>
                                <option value="<?php echo $ps; ?>"<?php echo $histPageSize === $ps ? ' selected' : ''; ?>><?php echo $ps; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
                <?php if ($rows === []): ?>
                    <p class="hint">No ledger entries yet. Balance is 0.00.</p>
                <?php else: ?>
                    <table class="hist-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Debit</th>
                                <th>Credit</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['entry_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['entry_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="num"><?php echo htmlspecialchars((string)$row['debit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="num"><?php echo htmlspecialchars((string)$row['credit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php
                                    $rt = trim((string)($row['reference_type'] ?? ''));
                                    $ri = trim((string)($row['reference_id'] ?? ''));
                                    echo htmlspecialchars(trim($rt . ($ri !== '' ? ' #' . $ri : '')), ENT_QUOTES, 'UTF-8');
                                ?></td>
                                <td><?php echo htmlspecialchars((string)$row['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pager">
                        <span>Page <?php echo $page; ?> of <?php echo $pages; ?> (<?php echo $total; ?> entries)</span>
                        <?php if ($page > 1): ?>
                            <a href="customer_account_view.php?<?php echo htmlspecialchars(psAcctViewHistQuery($accountId, ['hist_page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $pages): ?>
                            <a href="customer_account_view.php?<?php echo htmlspecialchars(psAcctViewHistQuery($accountId, ['hist_page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="section">
            <h2>Recent account audit</h2>
            <p class="hint">Every change to this account, newest first.</p>
            <?php if ($auditRows === []): ?>
                <p class="hint">No audit rows.</p>
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
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
