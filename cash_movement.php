<?php
/**
 * Phase 7.1 / 7.3 — Manual CASH_IN / CASH_OUT with controlled reasons + read-only history.
 * Auth: session logger + caller's own OPEN active till on working branch.
 * No edit/delete of posted movements. Posted ownership / balance / refs ignored.
 * Expected cash is server-calculated (psCashMovementBalance) — not postable.
 */
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/cash_movement.php';
require_once __DIR__ . '/includes/ps_layout.php';
require_once __DIR__ . '/includes/product_master.php';
require_once __DIR__ . '/includes/cash_controls.php';

ini_set('display_errors', '0');
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

psEnsureCashMovementModule($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$isAdmin = psCashMovementIsAdmin();
$error = '';
$success = '';
$movementType = strtoupper(trim((string)($_POST['movement_type'] ?? 'CASH_IN')));
if ($movementType !== PS_CASH_MOV_CASH_IN && $movementType !== PS_CASH_MOV_CASH_OUT) {
    $movementType = PS_CASH_MOV_CASH_IN;
}
$amount = trim((string)($_POST['amount'] ?? ''));
$reason = strtoupper(trim((string)($_POST['reason'] ?? '')));
$description = trim((string)($_POST['description'] ?? ''));
$historyBranchFilter = trim((string)($_GET['history_branch'] ?? 'all'));

$sessionRow = null;
$branch = '';
$balance = null;
$sessionHistory = [];
$branchHistory = [];
$branchSyncedNote = '';

if ($signedIn) {
    $sessionRow = getActiveTillSession($conn);
    if ($sessionRow && tillRowIsOpen($sessionRow)) {
        // Blank bind + sync drifted working branch to this open till (never rewrite till).
        $aligned = tillAlignWorkingBranchToOpenSession($conn, $sessionRow, tillCurrentCashier($conn));
        if (!empty($aligned['ok'])) {
            $sessionRow = $aligned['session'];
            $branch = (string)$aligned['branch'];
            if (!empty($aligned['synced'])) {
                $branchSyncedNote = 'Working branch was set to this till\'s branch (' . $branch . ') so cash movements use the open till.';
            } elseif (!empty($aligned['bound'])) {
                $branchSyncedNote = 'This till had no branch; it was assigned to ' . $branch . '.';
            }
        } else {
            $branch = trim((string)($sessionRow['branch'] ?? ''));
            if ($error === '' && !empty($aligned['error'])) {
                $error = (string)$aligned['error'];
            }
        }
        $bal = psCashMovementBalance($conn, (int)$sessionRow['id']);
        if (!empty($bal['ok'])) {
            $balance = $bal;
        }
        $hist = psCashMovementHistory($conn, (int)$sessionRow['id'], $branch, 100, 0);
        if (!empty($hist['ok'])) {
            $sessionHistory = $hist['rows'] ?? [];
        }
    }
    $listFilters = ['limit' => 50, 'offset' => 0];
    if ($isAdmin) {
        $listFilters['branch'] = $historyBranchFilter;
    } elseif ($sessionRow && tillRowIsOpen($sessionRow)) {
        $listFilters['till_session_id'] = (int)$sessionRow['id'];
        $listFilters['branch'] = $branch;
    }
    $list = psCashMovementListHistory($conn, $listFilters);
    if (!empty($list['ok'])) {
        $branchHistory = $list['rows'] ?? [];
    }
}

// 2026-10-17: admin sets the cash out amount above which a Till user needs a supervisor.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_out_limit_save'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $res = psCashOutSetApprovalLimit($conn, (string)($_POST['cash_out_limit'] ?? ''), (string)($_SESSION['logger'] ?? ''));
        $error = empty($res['ok']) ? (string)$res['error'] : '';
        if (!empty($res['ok'])) {
            $success = 'Cash out by a cashier above ' . $res['value'] . ' now needs a supervisor.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_mov_submit'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to record cash movements.';
    } elseif (!psCashMovementConsumeSubmitToken($_POST['cash_mov_submit_token'] ?? '')) {
        $error = 'This form was already submitted or the session expired. Please try again.';
    } else {
        $result = psCashMovementPostManual($conn, [
            'movement_type' => $_POST['movement_type'] ?? '',
            'amount' => $_POST['amount'] ?? '',
            'currency' => $_POST['currency'] ?? '',
            'reason' => $_POST['reason'] ?? '',
            'description' => $_POST['description'] ?? '',
            'approver_login' => $_POST['approver_login'] ?? '',
            'approver_password' => $_POST['approver_password'] ?? '',
            // Tamper probes — ignored / rejected by helper:
            'till_session_id' => $_POST['till_session_id'] ?? '',
            'session_id' => $_POST['session_id'] ?? '',
            'branch' => $_POST['branch'] ?? '',
            'balance' => $_POST['balance'] ?? '',
            'expected_cash' => $_POST['expected_cash'] ?? '',
            'reference' => $_POST['reference'] ?? '',
            'reference_id' => $_POST['reference_id'] ?? '',
            'created_by' => $_POST['created_by'] ?? '',
            'user' => $_POST['user'] ?? '',
            'cashier' => $_POST['cashier'] ?? '',
            'posted_till_session_id' => $_POST['till_session_id'] ?? '',
            'posted_branch' => $_POST['branch'] ?? '',
        ]);
        if (!empty($result['ok'])) {
            header('Location: cash_movement.php?ok=1&ref=' . rawurlencode((string)($result['reference'] ?? ''))
                . '&type=' . rawurlencode((string)($result['movement_type'] ?? '')));
            exit;
        }
        $error = (string)($result['error'] ?? 'Cash movement could not be saved.');
        $movementType = strtoupper(trim((string)($_POST['movement_type'] ?? 'CASH_IN')));
        if ($movementType !== PS_CASH_MOV_CASH_IN && $movementType !== PS_CASH_MOV_CASH_OUT) {
            $movementType = PS_CASH_MOV_CASH_IN;
        }
        $amount = trim((string)($_POST['amount'] ?? ''));
        $reason = strtoupper(trim((string)($_POST['reason'] ?? '')));
        $description = trim((string)($_POST['description'] ?? ''));
        $sessionRow = getActiveTillSession($conn);
        $balance = null;
        $sessionHistory = [];
        if ($sessionRow && tillRowIsOpen($sessionRow)) {
            $aligned = tillAlignWorkingBranchToOpenSession($conn, $sessionRow, tillCurrentCashier($conn));
            if (!empty($aligned['ok'])) {
                $sessionRow = $aligned['session'];
                $branch = (string)$aligned['branch'];
            } else {
                $branch = trim((string)($sessionRow['branch'] ?? ''));
            }
            $bal = psCashMovementBalance($conn, (int)$sessionRow['id']);
            if (!empty($bal['ok'])) {
                $balance = $bal;
            }
            $hist = psCashMovementHistory($conn, (int)$sessionRow['id'], $branch, 100, 0);
            if (!empty($hist['ok'])) {
                $sessionHistory = $hist['rows'] ?? [];
            }
        }
        $listFilters = ['limit' => 50, 'offset' => 0];
        if ($isAdmin) {
            $listFilters['branch'] = $historyBranchFilter;
        } elseif ($sessionRow && tillRowIsOpen($sessionRow)) {
            $listFilters['till_session_id'] = (int)$sessionRow['id'];
            $listFilters['branch'] = $branch;
        }
        $list = psCashMovementListHistory($conn, $listFilters);
        if (!empty($list['ok'])) {
            $branchHistory = $list['rows'] ?? [];
        }
    }

    }
}

if (isset($_GET['ok']) && (string)$_GET['ok'] === '1') {
    $ref = trim((string)($_GET['ref'] ?? ''));
    $okType = strtoupper(trim((string)($_GET['type'] ?? '')));
    $label = $okType === PS_CASH_MOV_CASH_IN ? 'Cash in' : ($okType === PS_CASH_MOV_CASH_OUT ? 'Cash out' : 'Cash movement');
    $success = $ref !== ''
        ? $label . ' recorded (' . $ref . ').'
        : $label . ' recorded.';
}

$submitToken = $signedIn ? psCashMovementIssueSubmitToken() : '';
$tillOpen = $sessionRow && tillRowIsOpen($sessionRow);
$inReasons = psCashMovementCashInReasons();
$outReasons = psCashMovementCashOutReasons();
$reasonOptions = $movementType === PS_CASH_MOV_CASH_OUT ? $outReasons : $inReasons;
if ($reason === '' || !in_array($reason, $reasonOptions, true)) {
    $reason = $reasonOptions[0] ?? '';
}
$branches = $isAdmin ? psAuthorisedProductBranches($conn) : [];
$tillName = $sessionRow ? tillRegisterName($sessionRow) : '';
$openedAt = $sessionRow ? tillOpenedAt($sessionRow) : '';
$expectedDisplay = $balance ? (string)$balance['balance'] : '0.00';
$detail = $balance['detail'] ?? [
    'opening_float' => '0.00',
    'cash_sales' => '0.00',
    'cash_in' => '0.00',
    'cash_refunds' => '0.00',
    'cash_out' => '0.00',
];
$historyRows = $tillOpen ? $sessionHistory : $branchHistory;

// 2026-10-17 blind count: a Till user does not see expected cash (or cash sales) until a current final count exists.
$blind = $tillOpen && !psCashControlsShowExpected($conn, (int)$sessionRow['id']);
if ($blind) {
    $historyRows = array_values(array_filter($historyRows, function ($r) {
        $t = strtoupper((string)($r['movement_type'] ?? ''));
        return $t === PS_CASH_MOV_CASH_IN || $t === PS_CASH_MOV_CASH_OUT;
    }));
}
$cashOutLimit = psCashOutApprovalLimit($conn);
$askApprover = psCashControlsIsTillUser();
$isAdminLevel = psCashControlsLevel() === 'admin';

$cmCurrencies = $signedIn ? psFxCompanyCurrencies($conn, psFxSessionCompany()) : [];
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash In / Cash Out</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Cash In / Cash Out'); ?>

<h1>Cash In / Cash Out</h1>
<p class="cm-hint">Manual cash movements for the current open till. References (CIN- / COUT-) are allocated by the server. Customer account payments are not recorded here. Posted movements cannot be edited or deleted — corrections use a future reversal flow.</p>

<?php if ($error !== ''): ?>
    <div class="cm-err"><?php echo ps_h($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="cm-ok"><?php echo ps_h($success); ?></div>
<?php endif; ?>
<?php if ($branchSyncedNote !== '' && $error === ''): ?>
    <div class="ps-alert ps-alert-info" role="status"><?php echo ps_h($branchSyncedNote); ?></div>
<?php endif; ?>

<?php if (!$signedIn): ?>
    <p>Please sign in to view cash movements.</p>
<?php elseif (!$tillOpen): ?>
    <p>Open a till session on the Web POS before recording cash in / cash out.</p>
    <?php if ($isAdmin && $branchHistory): ?>
        <h2 class="ps-subhead">Movement history</h2>
        <?php if ($branches): ?>
            <form method="get" class="cm-filter">
                <label for="history_branch">Branch</label>
                <select id="history_branch" name="history_branch" onchange="this.form.submit()">
                    <option value="all"<?php echo $historyBranchFilter === 'all' || $historyBranchFilter === '' ? ' selected' : ''; ?>>All branches</option>
                    <?php foreach ($branches as $b): ?>
                        <?php $bn = is_array($b) ? (string)($b['branch_name'] ?? $b['name'] ?? '') : (string)$b; ?>
                        <?php if ($bn === '') { continue; } ?>
                        <option value="<?php echo ps_h($bn); ?>"<?php echo $historyBranchFilter === $bn ? ' selected' : ''; ?>><?php echo ps_h($bn); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <table class="cm-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Type</th>
                    <th>Dir</th>
                    <th>Amount</th>
                    <th>Ref</th>
                    <th>Reason</th>
                    <th>Description</th>
                    <th>User</th>
                    <th>Session</th>
                    <th>Branch</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branchHistory as $row): ?>
                    <?php
                    $dir = (string)($row['direction'] ?? '');
                    $dirClass = $dir === 'IN' ? 'cm-dir-in' : ($dir === 'OUT' ? 'cm-dir-out' : '');
                    ?>
                    <tr>
                        <td><?php echo ps_h((string)$row['movement_at']); ?></td>
                        <td><?php echo ps_h((string)($row['type_label'] ?? $row['movement_type'])); ?></td>
                        <td class="<?php echo ps_h($dirClass); ?>"><?php echo ps_h($dir); ?></td>
                        <td><?php echo ps_h(number_format((float)$row['amount'], 2, '.', '') . ' ' . (string)($row['currency'] ?? '')); ?></td>
                        <td><?php echo ps_h((string)($row['reference_display'] ?? '')); ?></td>
                        <td><?php echo ps_h((string)($row['reason_label'] !== '' ? $row['reason_label'] : ($row['reason'] ?? ''))); ?></td>
                        <td><?php echo ps_h((string)($row['description'] ?? '')); ?></td>
                        <td><?php echo ps_h((string)($row['created_by'] ?? '')); ?></td>
                        <td><?php echo (int)($row['till_session_id'] ?? 0); ?></td>
                        <td><?php echo ps_h((string)($row['branch'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php else: ?>
    <div class="cm-grid">
        <div>
            <div class="cm-meta">
                Till: <?php echo ps_h($tillName); ?><br>
                Branch: <?php echo ps_h($branch); ?><br>
                Opened: <?php echo ps_h($openedAt); ?>
            </div>
            <?php if ($blind): ?>
            <p class="cm-hint">Expected cash is shown after you count the drawer at the end of your shift (Cash Reconciliation).</p>
            <?php else: ?>
            <div class="cm-bal">Expected cash: <?php echo ps_h($expectedDisplay); ?></div>
            <div class="cm-meta">
                Opening: <?php echo ps_h($detail['opening_float']); ?>
                · Sales: <?php echo ps_h($detail['cash_sales']); ?>
                · In: <?php echo ps_h($detail['cash_in']); ?>
                · Refunds: <?php echo ps_h($detail['cash_refunds']); ?>
                · Out: <?php echo ps_h($detail['cash_out']); ?>
            </div>
            <p class="cm-hint">Expected cash is calculated from the till ledger (read-only). Cash out is not blocked when expected would go negative.</p>
            <?php endif; ?>

            <form method="post" class="cm-form" autocomplete="off" id="cash_mov_form">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <input type="hidden" name="cash_mov_submit_token" value="<?php echo ps_h($submitToken); ?>">
                <span style="font-size:13px">Movement</span>
                <div class="cm-tabs">
                    <label>
                        <input type="radio" name="movement_type" value="CASH_IN"<?php echo $movementType === 'CASH_IN' ? ' checked' : ''; ?> onchange="psCmSyncReasons()">
                        Cash in
                    </label>
                    <label>
                        <input type="radio" name="movement_type" value="CASH_OUT"<?php echo $movementType === 'CASH_OUT' ? ' checked' : ''; ?> onchange="psCmSyncReasons()">
                        Cash out
                    </label>
                </div>
                <?php $cmCur = psFxNormalizeCode($_POST['currency'] ?? ''); ?>
                <?php if (count($cmCurrencies) > 1): ?>
                <label for="cm_currency">Currency</label>
                <select id="cm_currency" name="currency">
                    <?php foreach ($cmCurrencies as $code => $c): ?>
                        <option value="<?php echo ps_h($code); ?>"<?php echo $cmCur === $code ? ' selected' : ''; ?>><?php echo ps_h($code . ' — ' . $c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <label for="amount">Amount</label>
                <input id="amount" name="amount" type="text" inputmode="decimal" required value="<?php echo ps_h($amount); ?>" placeholder="0.00">
                <label for="reason">Reason</label>
                <select id="reason" name="reason" required>
                    <?php foreach ($reasonOptions as $code): ?>
                        <option value="<?php echo ps_h($code); ?>"<?php echo $reason === $code ? ' selected' : ''; ?>><?php echo ps_h(psCashMovementReasonLabel($code)); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="500" placeholder="Required when reason is Other"><?php echo ps_h($description); ?></textarea>
                <label for="ref_preview">Reference</label>
                <input id="ref_preview" class="cm-ro" type="text" readonly value="Allocated on save (CIN- / COUT-)" tabindex="-1">
                <p class="cm-hint">Reasons are operational labels only (not accounting / GL). EXPENSE does not post an expense journal.</p>
                <?php if ($askApprover): ?>
                <fieldset class="cm-approve" style="border:1px solid var(--ps-line,#ccc);border-radius:10px;padding:10px 12px;margin-top:12px">
                    <legend style="font-size:13px;padding:0 6px">Supervisor approval</legend>
                    <p class="cm-hint" style="margin-top:0">Cash out over <?php echo ps_h($cashOutLimit); ?> must be approved by a Supervisor, Manager or Admin. They type their own username and password here.</p>
                    <label for="approver_login">Supervisor username</label>
                    <input id="approver_login" name="approver_login" type="text" autocomplete="off" value="">
                    <label for="approver_password">Supervisor password</label>
                    <input id="approver_password" name="approver_password" type="password" autocomplete="new-password" value="">
                </fieldset>
                <?php endif; ?>
                <p style="margin-top:16px">
                    <button type="submit" name="cash_mov_submit" value="1">Record movement</button>
                </p>
            </form>
            <?php if ($isAdminLevel): ?>
            <form method="post" class="cm-form" autocomplete="off" style="margin-top:18px">
                <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <label for="cash_out_limit">Cashier cash out limit</label>
                <input id="cash_out_limit" name="cash_out_limit" type="text" inputmode="decimal" value="<?php echo ps_h($cashOutLimit); ?>">
                <p class="cm-hint">Cash out by a Till user above this amount needs a Supervisor, Manager or Admin to approve it. 0 = every cash out. Applies to the web POS and the Windows tills (they pick it up at their next download).</p>
                <p><button type="submit" name="cash_out_limit_save" value="1">Save limit</button></p>
            </form>
            <?php endif; ?>
        </div>
        <div>
            <h2 class="ps-subhead ps-subhead-first">Session history</h2>
            <?php if ($isAdmin && $branches): ?>
                <form method="get" class="cm-filter">
                    <label for="history_branch">Admin history branch</label>
                    <select id="history_branch" name="history_branch" onchange="this.form.submit()">
                        <option value="all"<?php echo $historyBranchFilter === 'all' || $historyBranchFilter === '' ? ' selected' : ''; ?>>All branches</option>
                        <?php foreach ($branches as $b): ?>
                            <?php $bn = is_array($b) ? (string)($b['branch_name'] ?? $b['name'] ?? '') : (string)$b; ?>
                            <?php if ($bn === '') { continue; } ?>
                            <option value="<?php echo ps_h($bn); ?>"<?php echo $historyBranchFilter === $bn ? ' selected' : ''; ?>><?php echo ps_h($bn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($historyBranchFilter !== '' && strcasecmp($historyBranchFilter, 'all') !== 0 && $historyBranchFilter !== $branch): ?>
                    <?php $historyRows = $branchHistory; ?>
                    <p class="cm-hint">Showing branch filter history (not limited to this till session).</p>
                <?php elseif (strcasecmp($historyBranchFilter, 'all') === 0): ?>
                    <?php $historyRows = $branchHistory; ?>
                    <p class="cm-hint">Showing all-branch history.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$historyRows): ?>
                <p class="cm-hint">No cash movements for this view yet.</p>
            <?php else: ?>
                <table class="cm-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Dir</th>
                            <th>Amount</th>
                            <th>Ref</th>
                            <th>Reason</th>
                            <th>Description</th>
                            <th>User</th>
                            <th>Session</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyRows as $row): ?>
                            <?php
                            $dir = (string)($row['direction'] ?? '');
                            $dirClass = $dir === 'IN' ? 'cm-dir-in' : ($dir === 'OUT' ? 'cm-dir-out' : '');
                            $reasonShow = (string)($row['reason_label'] ?? '');
                            if ($reasonShow === '') {
                                $reasonShow = (string)($row['reason'] ?? '');
                            }
                            ?>
                            <tr>
                                <td><?php echo ps_h((string)$row['movement_at']); ?></td>
                                <td><?php echo ps_h((string)($row['type_label'] ?? $row['movement_type'])); ?></td>
                                <td class="<?php echo ps_h($dirClass); ?>"><?php echo ps_h($dir); ?></td>
                                <td><?php echo ps_h(number_format((float)$row['amount'], 2, '.', '') . ' ' . (string)($row['currency'] ?? '')); ?></td>
                                <td><?php echo ps_h((string)($row['reference_display'] ?? '')); ?></td>
                                <td><?php echo ps_h($reasonShow); ?></td>
                                <td><?php echo ps_h((string)($row['description'] ?? '')); ?></td>
                                <td><?php echo ps_h((string)($row['created_by'] ?? '')); ?></td>
                                <td><?php echo (int)($row['till_session_id'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var inReasons = <?php echo json_encode(array_map(function ($c) {
            return ['code' => $c, 'label' => psCashMovementReasonLabel($c)];
        }, $inReasons)); ?>;
        var outReasons = <?php echo json_encode(array_map(function ($c) {
            return ['code' => $c, 'label' => psCashMovementReasonLabel($c)];
        }, $outReasons)); ?>;
        window.psCmSyncReasons = function () {
            var form = document.getElementById('cash_mov_form');
            if (!form) return;
            var typeEl = form.querySelector('input[name="movement_type"]:checked');
            var type = typeEl ? typeEl.value : 'CASH_IN';
            var list = type === 'CASH_OUT' ? outReasons : inReasons;
            var sel = document.getElementById('reason');
            if (!sel) return;
            var prev = sel.value;
            sel.innerHTML = '';
            for (var i = 0; i < list.length; i++) {
                var opt = document.createElement('option');
                opt.value = list[i].code;
                opt.textContent = list[i].label;
                if (list[i].code === prev) opt.selected = true;
                sel.appendChild(opt);
            }
        };
    })();
    </script>
<?php endif; ?>

<?php ps_close(); ?>
</body>
</html>
