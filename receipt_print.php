<?php
/**
 * Phase 13 — Browser-printable receipt (narrow / thermal-compatible CSS).
 * Same auth + branch ACL as my_receipt.php. Data matches stored transaction
 * (incl. tax). No ESC/POS direct hardware path — use OS browser print.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/tax.php';
require_once __DIR__ . '/includes/zimra.php';
require_once __DIR__ . '/includes/cash_controls.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if (!psBranchSessionAuthenticated()) {
    header('Location: login.php');
    exit;
}

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo '<!DOCTYPE html><html><body><p>The receipt could not be opened.</p></body></html>';
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$invoice_number = isset($_GET['invoice_number']) ? trim((string)$_GET['invoice_number']) : '';
if ($invoice_number === '') {
    echo '<!DOCTYPE html><html><body><p>No invoice number provided.</p></body></html>';
    mysqli_close($conn);
    exit;
}

$hdrStmt = mysqli_prepare($conn, 'SELECT * FROM online_sales_ref WHERE invoice_number = ? LIMIT 1');
if (!$hdrStmt) {
    echo '<!DOCTYPE html><html><body><p>The receipt could not be opened.</p></body></html>';
    mysqli_close($conn);
    exit;
}
mysqli_stmt_bind_param($hdrStmt, 's', $invoice_number);
mysqli_stmt_execute($hdrStmt);
$hdrRes = mysqli_stmt_get_result($hdrStmt);
$receipt_info = $hdrRes ? mysqli_fetch_assoc($hdrRes) : null;
mysqli_stmt_close($hdrStmt);

if (!$receipt_info || !psBranchSaleBranchAuthorised($conn, $receipt_info['branch'] ?? '') || !psReceiptVisibleToUser($receipt_info)) {
    echo '<!DOCTYPE html><html><body><p>Receipt not found.</p></body></html>';
    mysqli_close($conn);
    exit;
}
// 2026-10-17: the first receipt prints at the sale; anything printed from here is a copy. Record who and when.
$reprintNo = psReceiptReprintLog($conn, $invoice_number, (string)($receipt_info['branch'] ?? ''), 'web');

$details = [];
$total_revenue = 0.0;
$lineStmt = mysqli_prepare($conn, 'SELECT * FROM online_sales WHERE invoice_number = ?');
if ($lineStmt) {
    mysqli_stmt_bind_param($lineStmt, 's', $invoice_number);
    mysqli_stmt_execute($lineStmt);
    $lineRes = mysqli_stmt_get_result($lineStmt);
    while ($lineRes && ($row = mysqli_fetch_assoc($lineRes))) {
        $details[] = $row;
        $total_revenue += (float)$row['revenue'];
    }
    mysqli_stmt_close($lineStmt);
}

$storedTotal = null;
if (isset($receipt_info['totalz']) && is_numeric(trim((string)$receipt_info['totalz']))) {
    $storedTotal = (float)trim((string)$receipt_info['totalz']);
}
if ($storedTotal === null) {
    $storedTotal = $total_revenue;
}

$companyTitle = trim((string)($_SESSION['the_company'] ?? 'Receipt'));
foreach (['company_name', 'company', 'business_name'] as $ck) {
    if (!empty($receipt_info[$ck]) && trim((string)$receipt_info[$ck]) !== '') {
        $companyTitle = trim((string)$receipt_info[$ck]);
        break;
    }
}

$paymentView = psReceiptPaymentView(psPaymentForInvoice($conn, $invoice_number));
$headerTaxStatus = strtoupper(trim((string)($receipt_info['tax_data_status'] ?? PS_TAX_STATUS_LEGACY)));
$sumTax = 0.0;
$sumTaxable = 0.0;
$hasCompleteTax = false;

// Phase 17 — ZIMRA fiscal block (verification code + QR) when this sale was fiscalised.
$zimraRow = psZimraReceiptForSale($conn, $invoice_number, (string)($receipt_info['branch'] ?? ''));
$zimraView = $zimraRow ? psZimraReceiptView($conn, $zimraRow) : null;
$zimraQr = $zimraRow ? psZimraQrDataUri((string)$zimraRow['qr_url']) : '';

$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
// Multi-currency (2026-10-20): amounts print in the sale's own currency, with the rate when it is not the base.
require_once __DIR__ . '/includes/currency.php';
$fxSnap = psFxSaleSnapshot($conn, $invoice_number);
$saleCur = (string)$fxSnap['currency'];
$money = static function ($v) use ($conn, $saleCur) {
    return psFxFormat($conn, $v, $saleCur);
};
$money(0); // loads the currency list before the connection closes
$fxRateLine = ($saleCur !== $fxSnap['base'] && $fxSnap['rate'] !== '') ? psFxRateLabel($fxSnap['base'], $saleCur, $fxSnap['rate']) : '';
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png"><link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16.png"><link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo $esc($invoice_number); ?></title>
    <style>
        :root { --receipt-width: 80mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e5e7eb;
            color: #111;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            font-size: 13px;
        }
        .toolbar {
            position: sticky; top: 0; z-index: 2;
            display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
            padding: 10px 14px; background: #fff; border-bottom: 1px solid #d1d5db;
        }
        .toolbar a, .toolbar button {
            font: inherit; padding: 8px 12px; border: 1px solid #d1d5db;
            background: #fff; border-radius: 6px; cursor: pointer; text-decoration: none; color: inherit;
        }
        .toolbar .hint { color: #6b7280; font-size: 12px; }
        .sheet-wrap { display: flex; justify-content: center; padding: 18px 12px 40px; }
        .receipt {
            width: var(--receipt-width);
            max-width: 100%;
            background: #fff;
            padding: 10px 8px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }
        .center { text-align: center; }
        .brand { font-size: 16px; font-weight: 800; margin: 0 0 4px; }
        .muted { color: #6b7280; font-size: 11px; }
        .rule { border: 0; border-top: 1px dashed #9ca3af; margin: 8px 0; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { padding: 3px 0; vertical-align: top; }
        table.items th { font-size: 10px; text-transform: uppercase; color: #6b7280; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { padding: 2px 0; }
        .totals .grand td { font-weight: 800; font-size: 14px; padding-top: 6px; }
        @media print {
            .toolbar { display: none !important; }
            body { background: #fff; }
            .sheet-wrap { padding: 0; }
            .receipt { box-shadow: none; width: var(--receipt-width); }
            @page { margin: 4mm; size: 80mm auto; }
        }
        @media (max-width: 480px) {
            :root { --receipt-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" onclick="window.print()">Print</button>
        <a href="my_receipt.php?invoice_number=<?php echo rawurlencode($invoice_number); ?>">PDF</a>
        <a href="all_receipts.php">Back</a>
        <span class="hint">Sized for 80 mm receipt paper</span>
    </div>
    <div class="sheet-wrap">
        <div class="receipt">
            <div class="center">
                <?php if ($zimraView && $zimraView['logo'] !== ''): ?>
                    <img src="<?php echo $esc($zimraView['logo']); ?>" alt="" style="max-width:50mm;max-height:22mm;display:block;margin:0 auto 6px">
                <?php endif; ?>
                <div class="brand"><?php echo $esc($companyTitle); ?></div>
                <div class="muted">INVOICE <?php echo $esc($invoice_number); ?></div>
                <div style="font-weight:800;margin-top:4px;letter-spacing:.06em">*** COPY<?php echo $reprintNo > 0 ? ' · REPRINT ' . (int)$reprintNo : ''; ?> ***</div>
            </div>
            <hr class="rule">
            <div class="muted">
                <?php if (!empty($receipt_info['sold_at'])): ?>Date: <?php echo $esc($receipt_info['sold_at']); ?><br><?php endif; ?>
                <?php if (!empty($receipt_info['cashier'])): ?>Cashier: <?php echo $esc($receipt_info['cashier']); ?><br><?php endif; ?>
                <?php if (!empty($receipt_info['customer'])): ?>Customer: <?php echo $esc($receipt_info['customer']); ?><br><?php endif; ?>
                <?php if (!empty($receipt_info['branch'])): ?>Branch: <?php echo $esc($receipt_info['branch']); ?><br><?php endif; ?>
            </div>
            <hr class="rule">
            <table class="items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <th class="num">Amt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($details as $item):
                    $lineTaxStatus = strtoupper(trim((string)($item['tax_data_status'] ?? '')));
                    $taxNote = '';
                    if ($lineTaxStatus === PS_TAX_STATUS_COMPLETE) {
                        $hasCompleteTax = true;
                        $sumTax += is_numeric($item['tax_amount'] ?? null) ? (float)$item['tax_amount'] : 0;
                        $sumTaxable += is_numeric($item['taxable_amount'] ?? null) ? (float)$item['taxable_amount'] : 0;
                        $code = trim((string)($item['tax_code'] ?? ''));
                        $class = trim((string)($item['tax_classification'] ?? ''));
                        $rate = isset($item['tax_rate_percent']) ? number_format((float)$item['tax_rate_percent'], 2) . '%' : '';
                        $taxNote = $code . ' ' . $class . ($rate !== '' ? ' @ ' . $rate : '');
                    } elseif ($lineTaxStatus === PS_TAX_STATUS_UNCONFIGURED) {
                        $taxNote = ''; // customer receipt: no tax wording when no VAT rate is set up
                    } elseif ($lineTaxStatus === PS_TAX_STATUS_LEGACY || $lineTaxStatus === '') {
                        $taxNote = '';
                    }
                    $amt = is_numeric($item['revenue'] ?? null) ? (float)$item['revenue'] : 0;
                    ?>
                    <tr>
                        <td>
                            <?php echo $esc($item['product'] ?? ''); ?>
                            <?php if ($taxNote !== ''): ?><div class="muted"><?php echo $esc($taxNote); ?></div><?php endif; ?>
                        </td>
                        <td class="num"><?php echo $esc($item['quantity'] ?? ''); ?></td>
                        <td class="num"><?php echo $esc($money($amt)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <hr class="rule">
            <table class="items totals">
                <tr><td>Subtotal</td><td class="num"><?php echo $esc($money($total_revenue)); ?></td></tr>
                <?php if ($hasCompleteTax || $headerTaxStatus === PS_TAX_STATUS_COMPLETE):
                    $storedTaxable = isset($receipt_info['taxable_amount']) && is_numeric($receipt_info['taxable_amount'])
                        ? (float)$receipt_info['taxable_amount'] : $sumTaxable;
                    $storedTax = isset($receipt_info['tax_amount']) && is_numeric($receipt_info['tax_amount'])
                        ? (float)$receipt_info['tax_amount'] : $sumTax;
                    ?>
                    <tr><td>Taxable</td><td class="num"><?php echo $esc($money($storedTaxable)); ?></td></tr>
                    <tr><td>Tax</td><td class="num"><?php echo $esc($money($storedTax)); ?></td></tr>
                <?php elseif ($headerTaxStatus === PS_TAX_STATUS_LEGACY || $headerTaxStatus === ''): ?>
                    <tr><td colspan="2" class="muted">Tax split not recorded (legacy)</td></tr>
                <?php endif; ?>
                <tr class="grand"><td>Total</td><td class="num"><?php echo $esc($money($storedTotal)); ?></td></tr>
                <?php if (!$paymentView['recorded']): ?>
                    <tr><td>Payment</td><td class="num">Not recorded</td></tr>
                <?php else: ?>
                    <tr><td>Method</td><td class="num"><?php echo $esc($paymentView['method_label']); ?></td></tr>
                    <tr><td>Paid</td><td class="num"><?php echo $esc($money($paymentView['amount_paid'])); ?></td></tr>
                    <tr><td>Change</td><td class="num"><?php echo $esc($money($paymentView['change_amount'])); ?></td></tr>
                <?php endif; ?>
            </table>
            <?php if ($zimraView): ?>
            <hr class="rule">
            <div class="center">
                <div style="font-weight:700"><?php echo $esc($zimraView['title']); ?></div>
                <div class="muted"><?php echo $esc($zimraView['taxpayer_name']); ?> · TIN <?php echo $esc($zimraView['tin']); ?><?php if ($zimraView['vat_number'] !== ''): ?> · VAT <?php echo $esc($zimraView['vat_number']); ?><?php endif; ?></div>
                <?php if ($zimraView['address'] !== ''): ?><div class="muted"><?php echo $esc($zimraView['address']); ?></div><?php endif; ?>
                <div class="muted">Fiscal day <?php echo $esc($zimraView['fiscal_day_no']); ?> · Receipt <?php echo $esc($zimraView['receipt_no']); ?></div>
                <div class="muted">Device <?php echo $esc($zimraView['device_id']); ?> · S/N <?php echo $esc($zimraView['serial_no']); ?></div>
                <?php if ($zimraQr !== ''): ?><img src="<?php echo $esc($zimraQr); ?>" alt="ZIMRA verification QR code" style="width:40mm;height:40mm;margin:4px auto;display:block"><?php endif; ?>
                <div class="muted">Verification code</div>
                <div style="font-weight:700;font-family:monospace"><?php echo $esc($zimraView['verification_code']); ?></div>
                <div class="muted">Verify at <?php echo $esc($zimraView['verify_site']); ?></div>
                <?php if ($zimraView['status'] === 'PENDING'): ?><div class="muted">Awaiting ZIMRA confirmation</div><?php endif; ?>
                <?php if ($zimraView['environment'] !== 'production'): ?><div class="muted" style="font-weight:700">ZIMRA TEST — NOT A TAX INVOICE</div><?php endif; ?>
            </div>
            <?php endif; ?>
            <hr class="rule">
            <div class="center muted">Thank you.</div>
        </div>
    </div>
</body>
</html>
