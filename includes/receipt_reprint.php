<?php
/**
 * 2026-10-24 — Supervisor-authorised receipt reprints (web).
 *
 * The first receipt prints at the sale (print_jobs → print agent). Every later copy (receipt_print.php,
 * my_receipt.php PDF, zimra_receipt_print.php, picking slip copies) is a reprint:
 *   - Till users need a Supervisor / Management / admin to approve with their own username + password
 *     (psCashControlsVerifyApprover: same company, role checked from the users table, not themselves,
 *     5 wrong tries pause approvals for 15 minutes). Supervisors and up are authorised approvers already.
 *   - An approval issues a single-use grant held in the PHP session (never sent as a reusable secret),
 *     tied to one invoice and one document, valid for PS_REPRINT_GRANT_SECONDS.
 *   - receipt_reprints records every attempt: requester, approver, outcome (AUTHORISED / DENIED / PRINTED),
 *     document, timestamp. Nothing on the sale, invoice, payment, stock or accounting rows is touched.
 */

require_once __DIR__ . '/cash_controls.php';
require_once __DIR__ . '/operator_scope.php';
require_once __DIR__ . '/branch_access.php';

if (!defined('PS_REPRINT_GRANT_SECONDS')) {
    define('PS_REPRINT_GRANT_SECONDS', 120);
}
const PS_REPRINT_DOC_RECEIPT = 'RECEIPT';
const PS_REPRINT_DOC_PDF = 'RECEIPT_PDF';
const PS_REPRINT_DOC_FISCAL = 'FISCAL';
const PS_REPRINT_DOC_PICKING = 'PICKING_SLIP';

function psReprintDocuments(): array
{
    return [PS_REPRINT_DOC_RECEIPT, PS_REPRINT_DOC_PDF, PS_REPRINT_DOC_FISCAL, PS_REPRINT_DOC_PICKING];
}

/** Adds approval / outcome / document columns to receipt_reprints (idempotent). */
function psReprintEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!psCashControlsEnsureSchema($conn)) {
        return $ok = false;
    }
    $cols = [];
    $res = mysqli_query($conn, 'SHOW COLUMNS FROM receipt_reprints');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cols[strtolower((string)$r['Field'])] = true;
    }
    $add = [
        'approved_by' => 'ADD COLUMN approved_by VARCHAR(250) NULL',
        'outcome' => "ADD COLUMN outcome VARCHAR(16) NOT NULL DEFAULT 'PRINTED'",
        'document' => "ADD COLUMN document VARCHAR(20) NOT NULL DEFAULT 'RECEIPT'",
        'note' => 'ADD COLUMN note VARCHAR(250) NULL',
    ];
    $parts = [];
    foreach ($add as $col => $ddl) {
        if (!isset($cols[$col])) {
            $parts[] = $ddl;
        }
    }
    if ($parts && !mysqli_query($conn, 'ALTER TABLE receipt_reprints ' . implode(', ', $parts))) {
        error_log('receipt_reprint: schema update failed');
        return $ok = false;
    }
    return $ok = true;
}

/** Does the signed-in user need a supervisor to reprint? */
function psReprintNeedsApproval(): bool
{
    // 2026-11-02: company role permission "Reprint without approval" (default: Till needs approval, Supervisor+ do not).
    require_once __DIR__ . '/role_permissions.php';
    return !psRolePermSession('reprint_self');
}

/**
 * Append one audit row. Outcome: AUTHORISED | DENIED | PRINTED. Returns the PRINTED count for the invoice.
 */
function psReprintAudit(mysqli $conn, string $invoice, string $branch, string $outcome, string $document,
                        ?string $approvedBy, string $note = '', string $source = 'web'): int
{
    if (!psReprintEnsureSchema($conn)) {
        return 0;
    }
    $company = psCashControlsCompany();
    $user = psCashControlsUser();
    $now = date('Y-m-d H:i:s');
    $note = substr($note, 0, 250);
    $stmt = mysqli_prepare($conn, 'INSERT INTO receipt_reprints
        (company_name, branch, invoice_number, reprinted_by, reprinted_at, source, approved_by, outcome, document, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssssssssss', $company, $branch, $invoice, $user, $now, $source, $approvedBy, $outcome, $document, $note);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return psReprintPrintedCount($conn, $invoice, $document);
}

function psReprintPrintedCount(mysqli $conn, string $invoice, string $document = PS_REPRINT_DOC_RECEIPT): int
{
    if (!psReprintEnsureSchema($conn)) {
        return 0;
    }
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS n FROM receipt_reprints WHERE invoice_number = ? AND outcome = 'PRINTED' AND document = ?");
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $invoice, $document);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int)($row['n'] ?? 0);
}

/**
 * Sale header the signed-in user may reprint, or null (missing / other branch / not theirs / too old).
 */
function psReprintLoadSale(mysqli $conn, string $invoice): ?array
{
    $hdr = psOperatorSaleHeader($conn, $invoice);
    if ($hdr === null || !psBranchSaleBranchAuthorised($conn, $hdr['branch'] ?? '') || !psReceiptVisibleToUser($hdr)
        || !psOperatorMayOpenReceipt($conn, $hdr)) {
        return null;
    }
    $status = strtoupper(trim((string)($hdr['status'] ?? '')));
    if ($status === 'CANCELLED') {
        return null;
    }
    return $hdr;
}

/**
 * Supervisor approval for one reprint. Denials are audited.
 *
 * @return array{ok:bool,grant?:string,approved_by?:string,error?:string}
 */
function psReprintAuthorise(mysqli $conn, string $invoice, string $document, string $login, string $password): array
{
    $document = strtoupper(trim($document));
    if (!in_array($document, psReprintDocuments(), true)) {
        $document = PS_REPRINT_DOC_RECEIPT;
    }
    $hdr = psReprintLoadSale($conn, $invoice);
    if ($hdr === null) {
        psReprintAudit($conn, substr(trim($invoice), 0, 64), '', 'DENIED', $document, null, 'Receipt not available to this user');
        return ['ok' => false, 'error' => 'Receipt not found.'];
    }
    $invoice = (string)$hdr['invoice_number'];
    $branch = (string)($hdr['branch'] ?? '');
    if (!psReprintNeedsApproval()) {
        $approver = psCashControlsUser();
    } else {
        $check = psCashControlsVerifyApprover($conn, $login, $password);
        if (empty($check['ok'])) {
            psReprintAudit($conn, $invoice, $branch, 'DENIED', $document, null,
                'Approval refused (login tried: ' . substr(trim($login), 0, 120) . ')');
            return ['ok' => false, 'error' => (string)($check['error'] ?? 'Approval refused.')];
        }
        $approver = (string)$check['email'];
    }
    psReprintAudit($conn, $invoice, $branch, 'AUTHORISED', $document, $approver);
    $grant = bin2hex(random_bytes(16));
    $grants = is_array($_SESSION['ps_reprint_grants'] ?? null) ? $_SESSION['ps_reprint_grants'] : [];
    foreach ($grants as $k => $g) {
        if ((int)($g['exp'] ?? 0) < time()) {
            unset($grants[$k]);
        }
    }
    $grants[$grant] = ['invoice' => $invoice, 'document' => $document, 'approver' => $approver, 'exp' => time() + PS_REPRINT_GRANT_SECONDS];
    $_SESSION['ps_reprint_grants'] = $grants;
    return ['ok' => true, 'grant' => $grant, 'approved_by' => $approver];
}

/**
 * Use a grant once. Supervisors and up need none (they approve themselves).
 *
 * @return array{ok:bool,approved_by?:string,error?:string}
 */
function psReprintConsumeGrant(string $invoice, string $document, string $grant): array
{
    if (!psReprintNeedsApproval()) {
        return ['ok' => true, 'approved_by' => psCashControlsUser()];
    }
    $grants = is_array($_SESSION['ps_reprint_grants'] ?? null) ? $_SESSION['ps_reprint_grants'] : [];
    $g = ($grant !== '' && isset($grants[$grant])) ? $grants[$grant] : null;
    if ($g !== null) {
        unset($grants[$grant]);
        $_SESSION['ps_reprint_grants'] = $grants;
    }
    if ($g === null || (int)$g['exp'] < time() || (string)$g['invoice'] !== $invoice || (string)$g['document'] !== $document) {
        return ['ok' => false, 'error' => 'Reprinting this receipt needs a supervisor to approve it first.'];
    }
    return ['ok' => true, 'approved_by' => (string)$g['approver']];
}

/** Dialog markup + script for pages with [data-reprint] buttons (assets/ps-reprint.js). */
function psReprintDialogHtml(): string
{
    return '<dialog class="ps-dialog" id="reprintDialog" aria-labelledby="reprintDialogTitle">'
        . '<form class="ps-form-stack" autocomplete="off">'
        . '<div class="ps-dialog-head"><h2 class="ps-dialog-title" id="reprintDialogTitle">Supervisor approval</h2>'
        . '<button class="ps-btn ps-btn-ghost ps-btn-sm ps-btn-icon" type="button" data-dialog-close aria-label="Close">&times;</button></div>'
        . '<p>Reprinting <strong data-reprint-label></strong> needs a Supervisor, Manager or Admin. They sign in below; the copy is marked as a reprint and recorded.</p>'
        . '<div class="ps-alert ps-alert-destructive" role="alert" data-reprint-error hidden></div>'
        . '<div class="ps-dialog-grid">'
        . '<div class="ps-field"><label class="ps-label" for="rp_login">Supervisor username</label><input class="ps-input" id="rp_login" name="approver_login" required autocomplete="off"></div>'
        . '<div class="ps-field"><label class="ps-label" for="rp_pass">Password</label><input class="ps-input" id="rp_pass" name="approver_password" type="password" required autocomplete="new-password"></div>'
        . '</div>'
        . '<div class="ps-dialog-actions"><button class="ps-btn ps-btn-outline" type="button" data-dialog-close>Cancel</button>'
        . '<button class="ps-btn ps-btn-primary" type="submit" data-reprint-submit>Approve and reprint</button></div>'
        . '</form></dialog><script src="assets/ps-reprint.js"></script>';
}

/** Standard "needs approval" page for direct URL access without a valid grant. */
function psReprintDeniedPage(string $message): void
{
    http_response_code(403);
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Reprint</title>'
        . '<link rel="stylesheet" href="assets/ps-theme.css"></head><body class="ps-app">'
        . '<div style="max-width:460px;margin:15vh auto;padding:0 16px;text-align:center;font-family:system-ui,sans-serif">'
        . '<h1 style="font-size:1.3rem">Reprint not authorised</h1><p>' . $safe . '</p>'
        . '<p><a href="all_receipts.php">Back to Receipts</a></p></div></body></html>';
}
