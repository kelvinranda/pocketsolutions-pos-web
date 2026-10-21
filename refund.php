<?php
/**
 * Production refund UI - Phase 8.2 authorisation + Phase 8.3 line-level / partial qty.
 * Phase 8.4 - voids / cancellation for safe uncompleted sales; committed -> refund path only.
 * Request -> Approve/Reject -> Process. Server remaining only; browser totals non-authoritative.
 * Posted amount / branch / user / method / stock qty ignored by helpers.
 */
require_once __DIR__ . '/includes/cash_controls.php';
require_once __DIR__ . '/includes/sale_refund.php';
require_once __DIR__ . '/includes/sale_cancel.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ps_layout.php';

if (defined('PS_REFUND_LIBRARY')) {
    return;
}

require 'pockets_config.php';

ini_set('display_errors', '0');

$invoice_number = '';
$sales_data = [];
$error = '';
$success = '';
$reason = PS_REFUND_REASON_CUSTOMER_RETURN;
$reason_description = '';
$viewRequest = null;
$cancelInfo = null;
$cancelReason = PS_CANCEL_REASON_WRONG_ITEMS;
$cancelReasonDescription = '';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psSecurityEnforceWebSession($conn, ['redirect' => true, 'login' => 'login.php']);

psEnsureSaleRefundModule($conn);
psEnsureSaleCancelModule($conn);

$signedIn = psSessionRefundUser() !== '';
$mayCreate = psRefundMayCreate();
$mayApprove = psRefundMayApprove();
$mayProcess = psRefundMayProcess();
$mayCancel = psSaleCancelMayAct();
$isRequester = false;
$workingBranch = tillSelectedBranch();

/**
 * Reload sale lines with remaining for display (non-authoritative UI).
 */
function psRefundUiLoadInvoiceLines(mysqli $conn, string $invoiceNumber): array
{
    $loaded = psRefundLoadSaleLines($conn, '', $invoiceNumber, false);
    if (!empty($loaded['all_lines'])) {
        return $loaded['all_lines'];
    }
    if (!empty($loaded['ok']) && !empty($loaded['lines'])) {
        return $loaded['lines'];
    }
    return [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$signedIn) {
        $error = 'Please sign in before using refunds.';
    } elseif (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } elseif (isset($_POST['cancel_sale'])) {
        if (!$mayCancel) {
            $error = 'Please sign in before cancelling a sale.';
        } elseif (!psSaleCancelConsumeSubmitToken($_POST['cancel_submit_token'] ?? '')) {
            $error = 'This form was already submitted or the session expired. Please try again.';
        } else {
            $invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
            $cancelReason = strtoupper(trim((string)($_POST['cancel_reason'] ?? '')));
            $cancelReasonDescription = trim((string)($_POST['cancel_reason_description'] ?? ''));
            $confirm = trim((string)($_POST['cancel_confirm'] ?? ''));
            if ($confirm !== 'CANCEL') {
                $error = 'Type CANCEL to confirm cancellation.';
                $cancelInfo = psSaleCancelClassify($conn, $invoice_number, false);
            } else {
                // 2026-10-17: a cashier needs a Supervisor / Manager / Admin to sign the cancellation off here.
                $cancelApproved = !psCashControlsIsTillUser();
                $cancelDesc = $cancelReasonDescription;
                if (!$cancelApproved) {
                    $appr = psCashControlsVerifyApprover($conn, (string)($_POST['approver_login'] ?? ''), (string)($_POST['approver_password'] ?? ''));
                    if (!empty($appr['ok'])) {
                        $cancelApproved = true;
                        $tag = '[Approved by ' . $appr['email'] . ']';
                        $cancelDesc = trim(substr($cancelDesc, 0, 500 - strlen($tag) - 1) . ' ' . $tag);
                    }
                }
                $result = $cancelApproved ? psSaleCancelAttempt($conn, [
                    'invoice_number' => $invoice_number,
                    'reason' => $cancelReason,
                    'reason_description' => $cancelDesc,
                    '_supervisor_verified' => true,
                    'branch' => $_POST['branch'] ?? '',
                    'user' => $_POST['user'] ?? '',
                    'source' => PS_CANCEL_AUDIT_SOURCE_UI,
                ]) : ['ok' => false, 'error' => 'Cancelling a sale needs a Supervisor, Manager or Admin. ' . (string)($appr['error'] ?? '')];
                if (!empty($result['ok'])) {
                    $success = (string)($result['message'] ?? 'Sale cancelled.');
                    $sales_data = [];
                    $cancelInfo = null;
                } else {
                    $error = (string)($result['error'] ?? 'The sale could not be cancelled.');
                    $cancelInfo = psSaleCancelClassify($conn, $invoice_number, false);
                    if (!empty($result['must_use_refund'])) {
                        $sales_data = psRefundUiLoadInvoiceLines($conn, $invoice_number);
                    }
                }
            }
        }
    } elseif (isset($_POST['invoice_number']) && !isset($_POST['request_lines']) && !isset($_POST['request_receipt'])
        && !isset($_POST['approve_request']) && !isset($_POST['reject_request']) && !isset($_POST['process_request'])
        && !isset($_POST['cancel_sale'])) {
        $invoice_number = trim((string)$_POST['invoice_number']);
        if ($invoice_number === '' || strlen($invoice_number) > 250) {
            $error = 'The receipt was not found.';
        } else {
            $cancelInfo = psSaleCancelClassify($conn, $invoice_number, false);
            $sales_data = psRefundUiLoadInvoiceLines($conn, $invoice_number);
            if (!$sales_data && empty($cancelInfo['can_cancel']) && ($cancelInfo['class'] ?? '') === PS_CANCEL_CLASS_MISSING) {
                $error = 'The receipt was not found.';
            } elseif (!$sales_data && empty($cancelInfo['can_cancel'])) {
                if (empty($cancelInfo['must_use_refund']) && ($cancelInfo['class'] ?? '') !== PS_CANCEL_CLASS_CANCELLED) {
                    $error = 'No refundable items found for this receipt.';
                }
            }
        }
    } elseif (isset($_POST['request_lines']) || isset($_POST['request_receipt'])) {
        if (!$mayCreate) {
            $error = 'Please sign in before requesting a refund.';
        } elseif (!psRefundConsumeSubmitToken($_POST['refund_submit_token'] ?? '')) {
            $error = 'This form was already submitted or the session expired. Please try again.';
        } else {
            $invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
            $reason = strtoupper(trim((string)($_POST['reason'] ?? '')));
            $reason_description = trim((string)($_POST['reason_description'] ?? ''));
            $payload = [
                'reason' => $reason,
                'reason_description' => $reason_description,
                'source' => PS_REFUND_AUDIT_SOURCE_UI,
                'amount' => $_POST['amount'] ?? '',
                'total' => $_POST['total'] ?? '',
                'branch' => $_POST['branch'] ?? '',
                'user' => $_POST['user'] ?? '',
                'cashier' => $_POST['cashier'] ?? '',
                'method' => $_POST['method'] ?? '',
            ];
            $skipRequest = false;
            if (isset($_POST['request_receipt'])) {
                $payload['invoice_number'] = $invoice_number;
            } else {
                $rawLines = $_POST['lines'] ?? [];
                $lines = [];
                if (is_array($rawLines)) {
                    foreach ($rawLines as $pl) {
                        if (!is_array($pl)) {
                            continue;
                        }
                        $sid = (int)($pl['sale_line_id'] ?? 0);
                        $qty = trim((string)($pl['quantity'] ?? ''));
                        if ($sid < 1 || $qty === '' || $qty === '0') {
                            continue;
                        }
                        $lines[] = [
                            'sale_line_id' => $sid,
                            'quantity' => $qty,
                        ];
                    }
                }
                if (!$lines) {
                    $error = 'Select at least one refund line.';
                    $skipRequest = true;
                    if ($invoice_number !== '') {
                        $sales_data = psRefundUiLoadInvoiceLines($conn, $invoice_number);
                    }
                } else {
                    $payload['lines'] = $lines;
                    $payload['invoice_number'] = $invoice_number;
                }
            }
            if (!$skipRequest) {
                $result = psRequestRefund($conn, $payload);
                if (!empty($result['ok'])) {
                    $refNo = (string)($result['refund_number'] ?? '');
                    $success = 'Refund requested' . ($refNo !== '' ? ' (' . $refNo . ').' : '.')
                        . ' Awaiting approval.';
                    $sales_data = [];
                    $reason = PS_REFUND_REASON_CUSTOMER_RETURN;
                    $reason_description = '';
                } else {
                    $error = (string)($result['error'] ?? 'The refund could not be saved.');
                    if ($invoice_number !== '') {
                        $sales_data = psRefundUiLoadInvoiceLines($conn, $invoice_number);
                    }
                }
            }
        }
    } elseif (isset($_POST['approve_request'])) {
        // 2026-10-17: a cashier's screen — a supervisor types their own username + password to approve.
        $approver = $mayApprove ? ['ok' => true] : psCashControlsVerifyApprover($conn, (string)($_POST['approver_login'] ?? ''), (string)($_POST['approver_password'] ?? ''));
        if (empty($approver['ok'])) {
            $error = (string)($approver['error'] ?? 'You are not authorised to approve refunds.');
        } elseif (!psRefundConsumeSubmitToken($_POST['refund_submit_token'] ?? '')) {
            $error = 'This form was already submitted or the session expired. Please try again.';
        } else {
            if (!$mayApprove) {
                psRefundApproverOverride((string)$approver['email']);
            }
            $result = psApproveRefundRequest($conn, [
                'request_id' => (int)($_POST['request_id'] ?? 0),
                'approval_note' => (string)($_POST['approval_note'] ?? ''),
                'source' => PS_REFUND_AUDIT_SOURCE_UI,
            ]);
            psRefundApproverOverride(null, true);
            if (!empty($result['ok'])) {
                $success = 'Refund request approved (' . (string)($result['refund_number'] ?? '') . ').';
            } else {
                $error = (string)($result['error'] ?? 'The refund request could not be updated.');
            }
        }
    } elseif (isset($_POST['reject_request'])) {
        $approver = $mayApprove ? ['ok' => true] : psCashControlsVerifyApprover($conn, (string)($_POST['approver_login'] ?? ''), (string)($_POST['approver_password'] ?? ''));
        if (empty($approver['ok'])) {
            $error = (string)($approver['error'] ?? 'You are not authorised to reject refunds.');
        } elseif (!psRefundConsumeSubmitToken($_POST['refund_submit_token'] ?? '')) {
            $error = 'This form was already submitted or the session expired. Please try again.';
        } else {
            if (!$mayApprove) {
                psRefundApproverOverride((string)$approver['email']);
            }
            $result = psRejectRefundRequest($conn, [
                'request_id' => (int)($_POST['request_id'] ?? 0),
                'rejection_note' => (string)($_POST['rejection_note'] ?? ''),
                'source' => PS_REFUND_AUDIT_SOURCE_UI,
            ]);
            psRefundApproverOverride(null, true);
            if (!empty($result['ok'])) {
                $success = 'Refund request rejected (' . (string)($result['refund_number'] ?? '') . ').';
            } else {
                $error = (string)($result['error'] ?? 'The refund request could not be updated.');
            }
        }
    } elseif (isset($_POST['process_request'])) {
        if (!$mayProcess) {
            $error = 'Please sign in before processing a refund.';
        } elseif (!psRefundConsumeSubmitToken($_POST['refund_submit_token'] ?? '')) {
            $error = 'This form was already submitted or the session expired. Please try again.';
        } else {
            $result = psProcessApprovedRefund($conn, [
                'request_id' => (int)($_POST['request_id'] ?? 0),
                'source' => PS_REFUND_AUDIT_SOURCE_UI,
                'amount' => $_POST['amount'] ?? '',
                'branch' => $_POST['branch'] ?? '',
                'reason' => $_POST['reason'] ?? '',
            ]);
            if (!empty($result['ok'])) {
                $success = 'Refund processed (' . (string)($result['refund_number'] ?? '') . ').';
            } else {
                $error = (string)($result['error'] ?? 'The refund could not be saved.');
            }
        }
    }
}

if (isset($_GET['request_id']) && $signedIn) {
    $got = psRefundGetRequest($conn, (int)$_GET['request_id']);
    if (!empty($got['ok'])) {
        $viewRequest = $got['request'];
        $vb = trim((string)($viewRequest['branch'] ?? ''));
        $isRequester = strcasecmp((string)($viewRequest['requester'] ?? ''), psSessionRefundUser()) === 0;
        if (!$mayApprove && !$isRequester) {
            $viewRequest = null;
            $error = $error !== '' ? $error : 'The refund request was not found.';
        } elseif ($mayApprove && !psRefundBranchAccess($conn, $vb)) {
            $viewRequest = null;
            $error = $error !== '' ? $error : 'You do not have access to this branch.';
        }
    }
}

$history = $signedIn ? psRefundListRecentRequests($conn, 40) : [];
$submitToken = $signedIn ? psRefundIssueSubmitToken() : '';
$cancelSubmitToken = $signedIn ? psSaleCancelIssueSubmitToken() : '';

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refunds</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Refunds'); ?>
    <h1>Refunds</h1>
    <p class="ps-page-lead">
        Working branch: <?php echo htmlspecialchars($workingBranch !== '' ? $workingBranch : '(none)', ENT_QUOTES, 'UTF-8'); ?>.
        Uncommitted carts are discarded on the POS. Persisted sales with no payment/stock/cash/account effects may be cancelled here (history retained).
        Completed or paid sales cannot be voided directly - use the refund request -> approve -> process workflow.
        Display totals are estimates - the server recalculates on request/approve/process.
    </p>

    <?php if (!$signedIn): ?>
        <div class="ps-alert ps-alert-destructive" role="alert">Please sign in before using refunds. <a href="login.php">Login</a></div>
    <?php else: ?>
        <?php if ($mayCreate): ?>
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Find receipt</h2>
                    <p class="ps-card-desc">Look up a sale by receipt number to cancel or request a refund.</p>
                </div>
                <div class="ps-card-content">
                    <form method="POST" action="" class="filter-form" style="background:transparent;border:0;box-shadow:none;padding:0;margin:0;">
                        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <label class="ps-label" for="invoice_search">Receipt number</label>
                        <input class="ps-input" id="invoice_search" type="text" name="invoice_number" placeholder="Enter receipt number" value="<?php echo htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'); ?>" required>
                        <button class="ps-btn ps-btn-default" type="submit">Search</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="ps-alert ps-alert-success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($viewRequest): ?>
        <?php
        $st = strtoupper(trim((string)($viewRequest['status'] ?? '')));
        $rid = (int)$viewRequest['id'];
        $viewLines = is_array($viewRequest['lines'] ?? null) ? $viewRequest['lines'] : [];
        ?>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">
                    Request <?php echo htmlspecialchars((string)$viewRequest['refund_number'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="ps-badge ps-badge-<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(psRefundStatusLabel($st), ENT_QUOTES, 'UTF-8'); ?></span>
                </h2>
                <p class="ps-card-desc">
                    Invoice <?php echo htmlspecialchars((string)$viewRequest['invoice_number'], ENT_QUOTES, 'UTF-8'); ?>
                    Â· <?php echo htmlspecialchars((string)$viewRequest['branch'], ENT_QUOTES, 'UTF-8'); ?>
                    Â· <?php echo htmlspecialchars(psRefundReasonLabel((string)$viewRequest['reason']), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (trim((string)$viewRequest['reason_description']) !== ''): ?>
                        - <?php echo htmlspecialchars((string)$viewRequest['reason_description'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="ps-card-content">
                <div class="ps-meta">
                    Amount (display): <?php echo htmlspecialchars((string)$viewRequest['requested_amount'], ENT_QUOTES, 'UTF-8'); ?><br>
                    Requester: <?php echo htmlspecialchars((string)$viewRequest['requester'], ENT_QUOTES, 'UTF-8'); ?>
                    at <?php echo htmlspecialchars((string)$viewRequest['requested_at'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($viewRequest['approver'])): ?>
                        Â· Approver: <?php echo htmlspecialchars((string)$viewRequest['approver'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                    <?php if (!empty($viewRequest['rejector'])): ?>
                        Â· Rejector: <?php echo htmlspecialchars((string)$viewRequest['rejector'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                    <?php if (!empty($viewRequest['processor'])): ?>
                        Â· Processor: <?php echo htmlspecialchars((string)$viewRequest['processor'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>

                <?php if ($viewLines): ?>
                    <div class="ps-table-wrap">
                        <table class="ps-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Sale line</th>
                                    <th>Requested qty</th>
                                    <th>Remaining now</th>
                                    <th>Unit price</th>
                                    <th>Expected amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewLines as $vl): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($vl['_product'] ?? $vl['product_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$vl['sale_line_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$vl['requested_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($vl['_remaining'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($vl['_unit_price'] ?? $vl['unit_price_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($vl['_expected_amount'] ?? $vl['line_amount_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="ps-helper">Quantities are locked after approval - changes require reject + a new request. Process revalidates remaining server-side.</p>
                <?php endif; ?>

                <?php if ($st === PS_REFUND_STATUS_REQUESTED && ($mayApprove || $isRequester)): ?>
                    <?php if (!$mayApprove): ?>
                        <div class="ps-alert ps-alert-info">A Supervisor, Manager or Admin approves or rejects this here with their own username and password.</div>
                    <?php endif; ?>
                    <div class="ps-split ps-split-gap">
                        <form method="POST" action="" class="ps-form-stack">
                            <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                            <input type="hidden" name="request_id" value="<?php echo $rid; ?>">
                            <input type="hidden" name="refund_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="ps-label" for="approval_note">Approval note</label>
                            <textarea class="ps-input" id="approval_note" name="approval_note" placeholder="Approval note" required></textarea>
<?php if (!$mayApprove): ?>
                            <fieldset class="ps-form-stack" style="border:1px solid var(--ps-line,#ccc);border-radius:10px;padding:10px 12px">
                                <legend style="font-size:13px;padding:0 6px">Supervisor</legend>
                                <label class="ps-label" for="appr_login">Supervisor username</label>
                                <input class="ps-input" id="appr_login" name="approver_login" type="text" autocomplete="off" required>
                                <label class="ps-label" for="appr_pw">Supervisor password</label>
                                <input class="ps-input" id="appr_pw" name="approver_password" type="password" autocomplete="new-password" required>
                            </fieldset>
                            <?php endif; ?>
                            <div class="ps-form-actions">
                                <button class="ps-btn ps-btn-default" type="submit" name="approve_request">Approve</button>
                            </div>
                        </form>
                        <form method="POST" action="" class="ps-form-stack">
                            <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                            <input type="hidden" name="request_id" value="<?php echo $rid; ?>">
                            <input type="hidden" name="refund_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="ps-label" for="rejection_note">Rejection note</label>
                            <textarea class="ps-input" id="rejection_note" name="rejection_note" placeholder="Rejection note (required)" required></textarea>
<?php if (!$mayApprove): ?>
                            <fieldset class="ps-form-stack" style="border:1px solid var(--ps-line,#ccc);border-radius:10px;padding:10px 12px">
                                <legend style="font-size:13px;padding:0 6px">Supervisor</legend>
                                <label class="ps-label" for="rej_login">Supervisor username</label>
                                <input class="ps-input" id="rej_login" name="approver_login" type="text" autocomplete="off" required>
                                <label class="ps-label" for="rej_pw">Supervisor password</label>
                                <input class="ps-input" id="rej_pw" name="approver_password" type="password" autocomplete="new-password" required>
                            </fieldset>
                            <?php endif; ?>
                            <div class="ps-form-actions">
                                <button class="ps-btn ps-btn-destructive" type="submit" name="reject_request">Reject</button>
                            </div>
                        </form>
                    </div>
                <?php elseif ($st === PS_REFUND_STATUS_APPROVED && $mayProcess): ?>
                    <form method="POST" action="" class="ps-form-stack">
                        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <input type="hidden" name="request_id" value="<?php echo $rid; ?>">
                        <input type="hidden" name="refund_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="ps-form-actions">
                            <button class="ps-btn ps-btn-primary" type="submit" name="process_request">Process refund</button>
                        </div>
                    </form>
                <?php elseif ($st === PS_REFUND_STATUS_PROCESSED): ?>
                    <div class="ps-alert ps-alert-success">Processed (read-only). Amount: <?php echo htmlspecialchars((string)($viewRequest['processed_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php elseif ($st === PS_REFUND_STATUS_REJECTED): ?>
                    <div class="ps-alert ps-alert-destructive">Rejected (read-only). Note: <?php echo htmlspecialchars((string)($viewRequest['rejection_note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php elseif ($st === PS_REFUND_STATUS_REQUESTED): ?>
                    <div class="ps-alert ps-alert-info">Pending approval.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($signedIn && $cancelInfo && $invoice_number !== ''): ?>
        <?php
        $cClass = (string)($cancelInfo['class'] ?? '');
        $canCancel = !empty($cancelInfo['can_cancel']);
        $mustRefund = !empty($cancelInfo['must_use_refund']);
        ?>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Sale cancellation</h2>
                <p class="ps-card-desc">
                    Invoice <?php echo htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'); ?>
                    Â· class <span class="ps-badge ps-badge-default"><?php echo htmlspecialchars($cClass, ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
            </div>
            <div class="ps-card-content">
                <p class="ps-page-lead"><?php echo htmlspecialchars((string)($cancelInfo['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if ($canCancel && $mayCancel): ?>
                    <form method="POST" action="" id="cancel_sale_form" class="ps-form-stack" onsubmit="return confirm('Cancel this uncompleted sale? History is retained. No stock or cash movements will be posted.');">
                        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                        <input type="hidden" name="invoice_number" value="<?php echo htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="cancel_submit_token" value="<?php echo htmlspecialchars($cancelSubmitToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="ps-label" for="cancel_reason">Cancellation reason</label>
                        <select class="ps-input" id="cancel_reason" name="cancel_reason" required>
                            <?php foreach (psSaleCancelControlledReasons() as $code): ?>
                                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $cancelReason === $code ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars(psSaleCancelReasonLabel($code), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="ps-label" for="cancel_reason_description">Description (required for Other)</label>
                        <textarea class="ps-input" id="cancel_reason_description" name="cancel_reason_description"><?php echo htmlspecialchars($cancelReasonDescription, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <label class="ps-label" for="cancel_confirm">Type CANCEL to confirm</label>
                        <input class="ps-input" type="text" id="cancel_confirm" name="cancel_confirm" required autocomplete="off" placeholder="CANCEL">
                        <?php if (psCashControlsIsTillUser()): ?>
                            <fieldset class="ps-form-stack" style="border:1px solid var(--ps-line,#ccc);border-radius:10px;padding:10px 12px">
                                <legend style="font-size:13px;padding:0 6px">Supervisor approval</legend>
                                <p class="ps-helper" style="margin:0">A Supervisor, Manager or Admin must approve cancelling a sale. They type their own username and password.</p>
                                <label class="ps-label" for="cancel_appr_login">Supervisor username</label>
                                <input class="ps-input" id="cancel_appr_login" name="approver_login" type="text" autocomplete="off" required>
                                <label class="ps-label" for="cancel_appr_pw">Supervisor password</label>
                                <input class="ps-input" id="cancel_appr_pw" name="approver_password" type="password" autocomplete="new-password" required>
                            </fieldset>
                        <?php endif; ?>
                        <div class="ps-form-actions">
                            <button class="ps-btn ps-btn-destructive" type="submit" name="cancel_sale" value="1">Cancel uncompleted sale</button>
                        </div>
                    </form>
                <?php elseif ($mustRefund): ?>
                    <div class="ps-alert ps-alert-info"><strong>Direct void rejected.</strong> Use the refund request form below (authorised refund workflow).</div>
                <?php elseif ($cClass === PS_CANCEL_CLASS_CANCELLED): ?>
                    <div class="ps-alert ps-alert-default">Already cancelled - history retained.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($signedIn && $mayCreate && !empty($sales_data) && (empty($cancelInfo) || empty($cancelInfo['can_cancel']))): ?>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Request refund</h2>
                <p class="ps-card-desc">Select lines and quantities. Browser totals are non-authoritative.</p>
            </div>
            <div class="ps-card-content">
                <form method="POST" action="" id="refund_lines_form" class="ps-form-stack">
                    <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                    <label class="ps-label" for="refund_reason">Reason</label>
                    <select class="ps-input" id="refund_reason" name="reason">
                        <?php foreach (psRefundControlledReasons() as $code): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $reason === $code ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars(psRefundReasonLabel($code), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label class="ps-label" for="refund_reason_description">Description (required for Other)</label>
                    <textarea class="ps-input" id="refund_reason_description" name="reason_description"><?php echo htmlspecialchars($reason_description, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <input type="hidden" name="invoice_number" value="<?php echo htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="refund_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="ps-table-wrap">
                        <table class="ps-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Original qty</th>
                                    <th>Already refunded</th>
                                    <th>Remaining</th>
                                    <th>Unit price</th>
                                    <th>Request qty</th>
                                    <th>Line amount (display)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sales_data as $idx => $sale): ?>
                                    <?php
                                    $rem = (string)($sale['_remaining'] ?? '0.000');
                                    $sold = (string)($sale['_sold'] ?? $sale['quantity'] ?? '0');
                                    $prev = (string)($sale['_previously_refunded'] ?? '0.000');
                                    $unit = $sale['unit_price'] ?? '';
                                    $canRequest = psStockDecimalCmp($rem, '0') > 0;
                                    $unitCents = ($unit !== '' && $unit !== null) ? psDecimalCents((string)$unit) : null;
                                    ?>
                                    <tr data-unit-cents="<?php echo $unitCents !== null ? (int)$unitCents : ''; ?>" data-remaining="<?php echo htmlspecialchars($rem, ENT_QUOTES, 'UTF-8'); ?>">
                                        <td><?php echo htmlspecialchars((string)($sale['product'] ?? $sale['product_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($sold, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($prev, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($rem, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$unit, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($canRequest): ?>
                                                <input type="hidden" name="lines[<?php echo (int)$idx; ?>][sale_line_id]" value="<?php echo (int)$sale['id']; ?>">
                                                <input class="ps-input qty-input" type="number" name="lines[<?php echo (int)$idx; ?>][quantity]" min="0" step="any" value="" placeholder="0" title="Leave blank or 0 to skip" style="min-width:5rem;height:2.25rem;min-height:2.25rem;">
                                            <?php else: ?>
                                                <span class="ps-badge ps-badge-secondary">Fully refunded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="line-amt ps-muted">-</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="ps-display-total">Display total (non-authoritative): <span id="display_total">0.00</span></p>
                    <div class="ps-form-actions">
                        <button class="ps-btn ps-btn-default" type="submit" name="request_lines">Request selected lines</button>
                        <button class="ps-btn ps-btn-outline" type="submit" name="request_receipt">Request all remaining on receipt</button>
                    </div>
                </form>
                <script>
                (function () {
                    function moneyFromCents(c) {
                        return (c / 100).toFixed(2);
                    }
                    function recalc() {
                        var total = 0;
                        document.querySelectorAll('#refund_lines_form tbody tr').forEach(function (tr) {
                            var inp = tr.querySelector('.qty-input');
                            var cell = tr.querySelector('.line-amt');
                            if (!inp || !cell) return;
                            var qty = parseFloat(inp.value || '0');
                            var unitCents = parseInt(tr.getAttribute('data-unit-cents') || '', 10);
                            if (!qty || qty <= 0 || isNaN(unitCents)) {
                                cell.textContent = '-';
                                return;
                            }
                            var rem = parseFloat(tr.getAttribute('data-remaining') || '0');
                            if (qty > rem + 1e-9) {
                                cell.textContent = 'exceeds remaining';
                                return;
                            }
                            var lineCents = Math.round(unitCents * qty);
                            total += lineCents;
                            cell.textContent = moneyFromCents(lineCents);
                        });
                        var el = document.getElementById('display_total');
                        if (el) el.textContent = moneyFromCents(total);
                    }
                    document.querySelectorAll('.qty-input').forEach(function (el) {
                        el.addEventListener('input', recalc);
                    });
                    recalc();
                })();
                </script>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($signedIn && $history): ?>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Recent refund requests</h2>
                <p class="ps-card-desc">Latest requests in your authorised scope.</p>
            </div>
            <div class="ps-card-content" style="padding-top:0;padding-left:0;padding-right:0;padding-bottom:0;">
                <div class="ps-table-wrap" style="margin:0;border:0;border-radius:0;">
                    <table class="ps-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Invoice</th>
                                <th>Branch</th>
                                <th>Reason</th>
                                <th>Amount</th>
                                <th>Requester</th>
                                <th>Status</th>
                                <th>Approver</th>
                                <th>Processor</th>
                                <th>Requested</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                                <?php $hst = strtoupper(trim((string)($h['status'] ?? ''))); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$h['refund_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$h['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$h['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(psRefundReasonLabel((string)$h['reason']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$h['requested_amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$h['requester'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="ps-badge ps-badge-<?php echo htmlspecialchars($hst, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(psRefundStatusLabel($hst), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)($h['approver'] ?? $h['rejector'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['processor'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$h['requested_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><a class="ps-btn ps-btn-ghost ps-btn-sm" href="refund.php?request_id=<?php echo (int)$h['id']; ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif ($signedIn && !$history): ?>
        <div class="ps-empty">No recent refund requests.</div>
    <?php endif; ?>

    <div class="ps-form-actions">
        <?php if (strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin'): ?>
        <a class="ps-btn ps-btn-outline" href="admin_dashboard.php">Back to Dashboard</a>
        <?php else: ?>
        <a class="ps-btn ps-btn-outline" href="ajax_pos.php">Back to POS</a>
        <?php endif; ?>
    </div>
<?php ps_close(); ?>
</body>
</html>

