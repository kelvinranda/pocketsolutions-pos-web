<?php
/**
 * Phase 6.5 — Collection activity list + create (append-only).
 * Non-financial: never posts ledger / PAYMENT. next_action_date is planning only.
 * No national ID on lists. No reminder engine.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_credit_control.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerCreditControlModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$notice = '';
$accountId = (int)($_GET['account_id'] ?? $_POST['customer_account_id'] ?? 0);
$page = (int)($_GET['page'] ?? 1);
$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = strtoupper(trim((string)($_GET['activity_type'] ?? 'all')));

$list = null;
$accounts = [];
$preselectAccount = null;

if ($signedIn) {
    $acctList = psCustomerAccountList($conn, '', 'all');
    if (!empty($acctList['ok'])) {
        $accounts = $acctList['rows'] ?? [];
    }
    if ($accountId > 0) {
        $preselectAccount = psCustomerAccountById($conn, $accountId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_activity'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to record collection activity.';
    } else {
        $result = psCustomerCollectionActivityCreate($conn, [
            'customer_account_id' => (int)($_POST['customer_account_id'] ?? 0),
            'activity_date' => $_POST['activity_date'] ?? date('Y-m-d'),
            'activity_type' => $_POST['activity_type'] ?? '',
            'outcome' => $_POST['outcome'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'next_action_date' => $_POST['next_action_date'] ?? '',
            'promise_date' => $_POST['promise_date'] ?? '',
            'promise_amount' => $_POST['promise_amount'] ?? '',
            // Tamper probes ignored:
            'balance' => $_POST['balance'] ?? '',
            'amount' => $_POST['amount'] ?? '',
        ]);
        if ($result['ok']) {
            $aid = (int)($_POST['customer_account_id'] ?? 0);
            header('Location: customer_collection_activity.php?account_id=' . $aid . '&created=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'Collection activity could not be saved.');
        $accountId = (int)($_POST['customer_account_id'] ?? 0);
    }

    }
}

if (!empty($_GET['created'])) {
    $notice = 'Collection activity recorded.';
}

if ($signedIn) {
    $list = psCustomerCollectionActivityList($conn, [
        'customer_account_id' => $accountId,
        'search' => $search,
        'activity_type' => $typeFilter,
        'page' => $page,
        'page_size' => 25,
    ]);
    if (!$list['ok']) {
        $error = $error !== '' ? $error : (string)($list['error'] ?? 'List could not be loaded.');
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection Activity</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Collection Activity</h1>
    <p class="hint">
        Append-only contact history. Does not change ledger balance, create payments, or allocate receivables.
        Promise to pay is a note with optional promised date/amount — never a PAYMENT ledger entry.
        Next action date is planning only (no reminders). National ID is not shown.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <section class="section section-first">
            <h2>Record activity</h2>
            <form method="POST" action="" class="act-form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <label for="customer_account_id">Account</label>
                <select id="customer_account_id" name="customer_account_id" required>
                    <option value="">Select account</option>
                    <?php foreach ($accounts as $a): ?>
                        <?php
                        $aid = (int)$a['id'];
                        $label = (string)$a['account_number'] . ' — '
                            . (string)($a['customer_code'] ?? '') . ' '
                            . (string)($a['display_name'] ?? '');
                        ?>
                        <option value="<?php echo $aid; ?>"<?php echo $accountId === $aid ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="activity_date">Activity date</label>
                <input type="date" id="activity_date" name="activity_date" required
                       value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="activity_type">Type</label>
                <select id="activity_type" name="activity_type" required>
                    <?php foreach (psCustomerCollectionActivityTypes() as $t): ?>
                        <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(psCustomerCollectionActivityTypeLabel($t), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="outcome">Outcome</label>
                <select id="outcome" name="outcome" required>
                    <?php foreach (psCustomerCollectionActivityOutcomes() as $o): ?>
                        <option value="<?php echo htmlspecialchars($o, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(psCustomerCollectionActivityOutcomeLabel($o), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3" maxlength="1000"></textarea>

                <label for="next_action_date">Next action date (planning only)</label>
                <input type="date" id="next_action_date" name="next_action_date">

                <label for="promise_date">Promise date (for Promise to pay)</label>
                <input type="date" id="promise_date" name="promise_date">

                <label for="promise_amount">Promise amount (optional, non-financial)</label>
                <input type="text" id="promise_amount" name="promise_amount" placeholder="0.00">

                <div style="margin-top:12px;">
                    <button type="submit" name="create_activity" value="1">Save activity</button>
                </div>
            </form>
        </section>

        <section class="section">
            <h2>History</h2>
            <form method="GET" action="" class="filters">
                <div>
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Code / name / account">
                </div>
                <div>
                    <label for="account_id">Account id</label>
                    <input type="number" id="account_id" name="account_id" value="<?php echo $accountId > 0 ? $accountId : ''; ?>">
                </div>
                <div>
                    <label for="activity_type_f">Type</label>
                    <select id="activity_type_f" name="activity_type">
                        <option value="all">All</option>
                        <?php foreach (psCustomerCollectionActivityTypes() as $t): ?>
                            <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $typeFilter === $t ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars(psCustomerCollectionActivityTypeLabel($t), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit">Filter</button>
                    <a href="customer_collection_activity.php">Reset</a>
                </div>
            </form>

            <?php if ($list && !empty($list['ok'])): ?>
                <?php if (($list['rows'] ?? []) === []): ?>
                    <p class="hint">No collection activity found.</p>
                <?php else: ?>
                    <table class="hist-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Outcome</th>
                                <th>Notes</th>
                                <th>Next</th>
                                <th>Promise</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($list['rows'] as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$row['activity_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['customer_code'] . ' — ' . (string)$row['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="mono">
                                    <a href="customer_account_view.php?id=<?php echo (int)$row['customer_account_id']; ?>">
                                        <?php echo htmlspecialchars((string)$row['account_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars(psCustomerCollectionActivityTypeLabel((string)$row['activity_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psCustomerCollectionActivityOutcomeLabel((string)$row['outcome']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['notes'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['next_action_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php
                                    $pd = (string)($row['promise_date'] ?? '');
                                    $pa = (string)($row['promise_amount'] ?? '');
                                    echo htmlspecialchars(trim($pd . ($pa !== '' ? ' / ' . $pa : '')), ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)$row['created_by'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ((int)($list['pages'] ?? 1) > 1): ?>
                        <div class="pager">
                            <?php
                            $pages = (int)$list['pages'];
                            $cur = (int)$list['page'];
                            $qBase = [
                                'search' => $search,
                                'account_id' => $accountId > 0 ? $accountId : '',
                                'activity_type' => $typeFilter,
                            ];
                            if ($cur > 1) {
                                $q = http_build_query(array_merge($qBase, ['page' => $cur - 1]));
                                echo '<a href="?' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">Previous</a>';
                            }
                            echo '<span>Page ' . $cur . ' of ' . $pages . '</span>';
                            if ($cur < $pages) {
                                $q = http_build_query(array_merge($qBase, ['page' => $cur + 1]));
                                echo '<a href="?' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">Next</a>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <p class="hint">
            <a href="customer_credit_control.php">Credit control dashboard</a> ·
            <a href="customer_accounts_list.php">Customer accounts</a>
        </p>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
