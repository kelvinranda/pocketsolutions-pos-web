<?php
/**
 * Phase 9.6 — Receipt list gated to authorised registered working branch.
 * 2026-10-24 — Till and Supervisor: their own 5 most recent receipts (limit applied in SQL).
 * Till reprints need a supervisor's approval (includes/receipt_reprint.php).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/customer_master.php';
require_once __DIR__ . '/includes/cash_controls.php';
require_once __DIR__ . '/includes/operator_scope.php';
require_once __DIR__ . '/includes/receipt_reprint.php';
require_once __DIR__ . '/includes/picking_slip.php';
require_once __DIR__ . '/includes/order_number.php';
require_once __DIR__ . '/includes/company_logo.php';

psBranchRequireAuthRedirect();

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSaleIntegritySchema($conn);

$scope = psBranchRequireAuthorisedWorking($conn);
if (empty($scope['ok'])) {
    die(htmlspecialchars((string)($scope['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$workingBranch = (string)$scope['branch'];

// 2026-10-24: company switch — print a storeroom picking slip with every sale (admin).
$settingError = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['picking_slip_mode'])) {
    if (!psCsrfValidate()) {
        $settingError = 'Invalid security token. Reload and try again.';
    } else {
        $r = psPickingSlipSetEnabled($conn, $_POST['picking_slip_mode'] === '1', (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: all_receipts.php?slip_setting=1');
            exit;
        }
        $settingError = (string)$r['error'];
    }
    $_POST = [];
}
// 2026-10-31: the company's logo on the A4 invoice (admin upload / remove).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['invoice_logo_action'])) {
    if (!psCsrfValidate()) {
        $settingError = 'Invalid security token. Reload and try again.';
    } else {
        $remove = $_POST['invoice_logo_action'] === 'remove';
        $up = $_FILES['invoice_logo'] ?? null;
        if (!$remove && (!$up || (int)($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name']))) {
            $settingError = 'Choose a PNG or JPEG image first (up to 500 KB).';
        } else {
            $r = psCompanyLogoSet($conn, $remove ? null : (string)$up['tmp_name'], (string)($_SESSION['logger'] ?? ''));
            if (!empty($r['ok'])) {
                header('Location: all_receipts.php?slip_setting=1');
                exit;
            }
            $settingError = (string)$r['error'];
        }
    }
    $_POST = [];
}
// 2026-11-13: company switch — Sit in / Take away on every sale (admin).
require_once __DIR__ . '/includes/dine_option.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['dine_option_mode'])) {
    if (!psCsrfValidate()) {
        $settingError = 'Invalid security token. Reload and try again.';
    } else {
        $r = psDineOptionSetEnabled($conn, $_POST['dine_option_mode'] === '1', (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: all_receipts.php?slip_setting=1');
            exit;
        }
        $settingError = (string)$r['error'];
    }
    $_POST = [];
}
// 2026-10-31: company switch — restaurant order numbers (admin).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['order_numbers_mode'])) {
    if (!psCsrfValidate()) {
        $settingError = 'Invalid security token. Reload and try again.';
    } else {
        $r = psOrderNumbersSetEnabled($conn, $_POST['order_numbers_mode'] === '1', (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: all_receipts.php?slip_setting=1');
            exit;
        }
        $settingError = (string)$r['error'];
    }
    $_POST = [];
}

$search = trim((string)($_POST['search'] ?? ''));
$date_search = trim((string)($_POST['date_search'] ?? ''));
$searching = isset($_POST['search']) || isset($_POST['date_search']);
$tillOnly = psOperatorIsScoped();
$receipts_data = [];

// Management / admin: 10 newest by default, up to 200 when searching. Till / Supervisor: always their own 5.
$rawRows = psOperatorReceiptHistory($conn, $workingBranch, ['customer' => $search, 'date' => $date_search], $searching ? 200 : 10);
$masterIds = [];
foreach ($rawRows as $row) {
    $masterId = isset($row['customer_master_id']) ? (int)$row['customer_master_id'] : 0;
    if ($masterId > 0) {
        $masterIds[] = $masterId;
    }
}
$masters = $masterIds !== [] ? psCustomersByIds($conn, $masterIds) : [];
foreach ($rawRows as $row) {
    $masterId = isset($row['customer_master_id']) ? (int)$row['customer_master_id'] : 0;
    if ($masterId > 0 && isset($masters[$masterId])) {
        $row['customer'] = psCustomerDisplayName($masters[$masterId]);
    }
    $receipts_data[] = $row;
}
$pickingOn = psPickingSlipEnabled($conn);
$orderOn = psOrderNumbersEnabled($conn);
$invoiceLogo = psCompanyLogoGet($conn, psCashControlsCompany());
$showOrderCol = $orderOn;
foreach ($receipts_data as $r0) {
    if (trim((string)($r0['order_no'] ?? '')) !== '') {
        $showOrderCol = true;
        break;
    }
}
$dineOn = psDineOptionEnabled($conn);
$showDineCol = $dineOn;
foreach ($receipts_data as $r0) {
    if (trim((string)($r0['dine_option'] ?? '')) !== '') {
        $showDineCol = true;
        break;
    }
}

// Each receipt in its own currency (a lookup list, not a total): By-currency display, legacy receipts flagged.
require_once __DIR__ . '/includes/fx_report.php';
$fxR = ['view' => PS_FXR_BREAKDOWN] + psFxrContext($conn, []);
$needsApproval = psReprintNeedsApproval();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app" data-needs-approval="<?php echo $needsApproval ? '1' : '0'; ?>">
<?php ps_open(); ?>
    <h1>Receipts</h1>
    <p>Branch: <?php echo htmlspecialchars($workingBranch, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($tillOnly): ?>
        <p class="ps-field-hint">Your <?php echo (int)PS_OPERATOR_TILL_RECEIPT_LIMIT; ?> most recent receipts<?php echo psReprintNeedsApproval() ? '. Reprinting needs a supervisor.' : '.'; ?></p>
    <?php endif; ?>
    <?php if ($settingError !== ''): ?>
        <div class="ps-alert ps-alert-destructive"><?php echo htmlspecialchars($settingError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!empty($_GET['slip_setting'])): ?>
        <div class="ps-alert ps-alert-success">Setting saved.</div>
    <?php endif; ?>
    <?php if (psCashControlsLevel() === 'admin'): ?>
    <form method="post" action="all_receipts.php" class="ps-card" style="padding:14px 16px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <?php echo psCsrfField(); ?>
        <div style="flex:1;min-width:260px">
            <strong>Print a storeroom picking slip with every sale:</strong> <?php echo $pickingOn ? 'On (receipt + picking slip)' : 'Off (receipt only)'; ?>
            <div class="ps-field-hint">The slip lists the items and quantities to collect, with the sale reference — no prices, and it is not a receipt.
                Nothing extra is posted: it is the same sale and invoice number. Web sales queue both documents for the print agent
                (one printer, or a separate storeroom printer polling <code>get_receipt.php?doc=PICKING_SLIP</code>).
                Windows tills print both documents on the receipt printer, or a separate storeroom printer chosen in Till Settings.</div>
        </div>
        <input type="hidden" name="picking_slip_mode" value="<?php echo $pickingOn ? '0' : '1'; ?>">
        <button type="submit" class="ps-btn <?php echo $pickingOn ? 'ps-btn-outline' : 'ps-btn-default'; ?>"><?php echo $pickingOn ? 'Turn off' : 'Turn on'; ?></button>
    </form>
    <form method="post" action="all_receipts.php" class="ps-card" style="padding:14px 16px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <?php echo psCsrfField(); ?>
        <div style="flex:1;min-width:260px">
            <strong>Restaurant order numbers:</strong> <?php echo $orderOn ? 'On' : 'Off'; ?>
            <div class="ps-field-hint">Every sale gets a short order number to call out, printed big on the receipt and the picking slip and shown when the sale is done.
                It starts at 001 each time a till is opened, and each till has its own: Windows tills use their till code (T01-001), web tills W01-001, phones M01-001.
                The invoice and ZIMRA numbers do not change.</div>
        </div>
        <input type="hidden" name="order_numbers_mode" value="<?php echo $orderOn ? '0' : '1'; ?>">
        <button type="submit" class="ps-btn <?php echo $orderOn ? 'ps-btn-outline' : 'ps-btn-default'; ?>"><?php echo $orderOn ? 'Turn off' : 'Turn on'; ?></button>
    </form>
    <form method="post" action="all_receipts.php" class="ps-card" style="padding:14px 16px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <?php echo psCsrfField(); ?>
        <div style="flex:1;min-width:260px">
            <strong>Sit in / Take away:</strong> <?php echo $dineOn ? 'On' : 'Off'; ?>
            <div class="ps-field-hint">For restaurants: the cashier must choose Sit in or Take away on the pay screen of every till (web, Windows and phone).
                It is printed big on the receipt and the picking slip, shown on this list and totalled in the sales reports.</div>
        </div>
        <input type="hidden" name="dine_option_mode" value="<?php echo $dineOn ? '0' : '1'; ?>">
        <button type="submit" class="ps-btn <?php echo $dineOn ? 'ps-btn-outline' : 'ps-btn-default'; ?>"><?php echo $dineOn ? 'Turn off' : 'Turn on'; ?></button>
    </form>
    <form method="post" action="all_receipts.php" enctype="multipart/form-data" class="ps-card" style="padding:14px 16px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <?php echo psCsrfField(); ?>
        <div style="flex:1;min-width:260px">
            <strong>Logo on the A4 invoice:</strong> <?php echo $invoiceLogo ? 'Uploaded' : 'None'; ?>
            <div class="ps-field-hint">Shown at the top left of the A4 invoice (the receipt PDF). PNG or JPEG up to 500 KB; a wide logo on a white background looks best.</div>
            <?php if ($invoiceLogo): ?>
                <img src="<?php echo htmlspecialchars($invoiceLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="Current invoice logo" style="max-height:56px;max-width:200px;margin-top:8px;background:#fff;padding:4px;border-radius:6px">
            <?php endif; ?>
        </div>
        <input class="ps-input" type="file" name="invoice_logo" accept="image/png,image/jpeg" style="max-width:260px">
        <button type="submit" name="invoice_logo_action" value="upload" class="ps-btn ps-btn-default"><?php echo $invoiceLogo ? 'Replace logo' : 'Upload logo'; ?></button>
        <?php if ($invoiceLogo): ?>
            <button type="submit" name="invoice_logo_action" value="remove" class="ps-btn ps-btn-outline" formnovalidate>Remove</button>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <form method="POST" action="">
        <?php echo psCsrfField(); ?>
        <label for="search">Customer</label>
        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="date_search">Date</label>
        <input type="text" id="date_search" name="date_search" value="<?php echo htmlspecialchars($date_search, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Search</button>
    </form>

    <div class="ps-table-wrap">
    <table>
        <thead>
            <tr>
                <?php if ($showOrderCol): ?><th>Order</th><?php endif; ?>
                <?php if ($showDineCol): ?><th>Sit in / Take away</th><?php endif; ?>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Date</th>
                <th>Cashier</th>
                <th>Method</th>
                <th>Status</th>
                <th>Reprint</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($receipts_data): ?>
                <?php foreach ($receipts_data as $row): ?>
                    <?php
                    $inv = (string)($row['invoice_number'] ?? '');
                    $invEsc = htmlspecialchars($inv, ENT_QUOTES, 'UTF-8');
                    $printUrl = htmlspecialchars('receipt_print.php?invoice_number=' . rawurlencode($inv), ENT_QUOTES, 'UTF-8');
                    $pdfUrl = htmlspecialchars('my_receipt.php?invoice_number=' . rawurlencode($inv), ENT_QUOTES, 'UTF-8');
                    $slipUrl = htmlspecialchars('picking_slip_print.php?invoice_number=' . rawurlencode($inv), ENT_QUOTES, 'UTF-8');
                    $rowStatus = strtoupper(trim((string)($row['status'] ?? '')));
                    $completed = $rowStatus !== 'CANCELLED' && $rowStatus !== '';
                    $slipOriginal = $pickingOn && $completed && !psPickingSlipOriginalPrinted($conn, $inv);
                    ?>
                    <tr>
                        <?php if ($showOrderCol): ?><td class="nowrap"><strong><?php echo htmlspecialchars((string)($row['order_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td><?php endif; ?>
                        <?php if ($showDineCol): ?><td class="nowrap"><?php echo htmlspecialchars(psDineOptionLabel($row['dine_option'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                        <td><?php echo $invEsc; ?></td>
                        <td><?php echo htmlspecialchars((string)($row['customer'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="num"><?php echo psFxrOne($conn, $fxR, $row['currency'] ?? null, $row['totalz'] ?? '0', $row['base_total'] ?? null, ['rate' => (string)($row['fx_rate'] ?? '')]); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['date_'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['cashier'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['method'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="nowrap">
                            <?php if ($completed): ?>
                                <button type="button" class="ps-btn ps-btn-outline ps-btn-sm" data-reprint data-label="Receipt" data-invoice="<?php echo $invEsc; ?>" data-doc="RECEIPT" data-url="<?php echo $printUrl; ?>">Receipt</button>
                                <button type="button" class="ps-btn ps-btn-ghost ps-btn-sm" data-reprint data-label="Receipt PDF" data-invoice="<?php echo $invEsc; ?>" data-doc="RECEIPT_PDF" data-url="<?php echo $pdfUrl; ?>">PDF</button>
                                <?php if ($slipOriginal): ?>
                                    <a class="ps-btn ps-btn-ghost ps-btn-sm" href="<?php echo $slipUrl; ?>" target="_blank" rel="noopener" title="The picking slip has not printed yet">Print picking slip</a>
                                <?php elseif ($pickingOn): ?>
                                    <button type="button" class="ps-btn ps-btn-ghost ps-btn-sm" data-reprint data-label="Picking slip" data-invoice="<?php echo $invEsc; ?>" data-doc="PICKING_SLIP" data-url="<?php echo $slipUrl; ?>">Picking slip</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?php echo $showOrderCol ? 9 : 8; ?>">No receipts found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
<?php echo psReprintDialogHtml(); ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
