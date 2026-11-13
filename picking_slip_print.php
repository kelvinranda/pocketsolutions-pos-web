<?php
/**
 * 2026-10-24 — Browser print of the STOREROOM PICKING SLIP for a completed sale (two-document mode).
 * Built from the stored sale lines: no prices, payment or tax, and nothing is posted. The first print (when the
 * print agent has not printed the queued slip) is the original and stops the queued job printing as well; later
 * prints are copies and need a supervisor's single-use approval for Till users. Every print is audited.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/receipt_reprint.php';
require_once __DIR__ . '/includes/picking_slip.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if (!psBranchSessionAuthenticated()) {
    header('Location: login.php');
    exit;
}
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo '<!DOCTYPE html><html><body><p>The picking slip could not be opened.</p></body></html>';
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$invoice = trim((string)($_GET['invoice_number'] ?? ''));
$hdr = ($invoice !== '' && preg_match('/^\d{1,12}$/', $invoice)) ? psReprintLoadSale($conn, $invoice) : null;
if ($hdr === null || trim((string)($hdr['status'] ?? '')) === '' || !psPrintJobsEnsureSchema($conn)) {
    mysqli_close($conn);
    echo '<!DOCTYPE html><html><body><p>Sale not found.</p></body></html>';
    exit;
}
$branch = (string)($hdr['branch'] ?? '');

$original = !psPickingSlipOriginalPrinted($conn, $invoice);
if ($original) {
    psPickingSlipMarkQueuedPrinted($conn, $invoice);
    psReprintAudit($conn, $invoice, $branch, 'ORIGINAL', PS_REPRINT_DOC_PICKING, null, 'Printed from the browser');
    $copyNo = 0;
    $approvedBy = '';
} else {
    $grant = psReprintConsumeGrant($invoice, PS_REPRINT_DOC_PICKING, trim((string)($_GET['grant'] ?? '')));
    if (empty($grant['ok'])) {
        psReprintAudit($conn, $invoice, $branch, 'DENIED', PS_REPRINT_DOC_PICKING, null, 'Opened without a valid approval');
        mysqli_close($conn);
        psReprintDeniedPage('Reprinting this picking slip needs a supervisor to approve it first.');
        exit;
    }
    $approvedBy = (string)$grant['approved_by'];
    $copyNo = psReprintAudit($conn, $invoice, $branch, 'PRINTED', PS_REPRINT_DOC_PICKING, $approvedBy);
}
$items = psPickingSlipSaleLines($conn, $invoice);
mysqli_close($conn);
$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$customer = trim((string)($hdr['customer'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.ico" sizes="any">
    <title>Picking slip <?php echo $esc($invoice); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111; font-family: "Segoe UI", Arial, sans-serif; font-size: 13px; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; align-items: center; padding: 10px 14px; background: #fff; border-bottom: 1px solid #d1d5db; }
        .toolbar a, .toolbar button { font: inherit; padding: 8px 12px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; cursor: pointer; text-decoration: none; color: inherit; }
        .wrap { display: flex; justify-content: center; padding: 18px 12px 40px; }
        .slip { width: 80mm; max-width: 100%; background: #fff; padding: 10px 8px 16px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .center { text-align: center; }
        .title { font-size: 16px; font-weight: 800; letter-spacing: .04em; }
        .muted { color: #4b5563; font-size: 11px; }
        .rule { border: 0; border-top: 1px dashed #9ca3af; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 0; vertical-align: top; text-align: left; }
        th { font-size: 10px; text-transform: uppercase; color: #6b7280; }
        .qty { width: 18mm; font-weight: 700; }
        .box { width: 8mm; text-align: center; }
        .box span { display: inline-block; width: 4mm; height: 4mm; border: 1px solid #111; }
        .sign { margin-top: 14px; }
        @media print {
            .toolbar { display: none !important; }
            body { background: #fff; }
            .wrap { padding: 0; }
            .slip { box-shadow: none; }
            @page { margin: 4mm; size: 80mm auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print</button>
        <a href="all_receipts.php">Back</a>
    </div>
    <div class="wrap">
        <div class="slip">
            <div class="center">
                <div class="title">STOREROOM PICKING SLIP</div>
                <div class="muted" style="font-weight:700">NOT A RECEIPT</div>
                <?php if (!$original): ?>
                    <div style="font-weight:800;margin-top:4px">*** COPY · REPRINT <?php echo (int)$copyNo; ?> ***</div>
                    <div class="muted">Reprinted <?php echo $esc(date('Y-m-d H:i')); ?> · approved by <?php echo $esc($approvedBy); ?></div>
                <?php endif; ?>
            </div>
            <?php if (trim((string)($hdr['order_no'] ?? '')) !== ''): ?>
                <div class="center" style="font-size:16px;font-weight:800;letter-spacing:.04em;margin:6px 0">ORDER <?php echo $esc($hdr['order_no']); ?></div>
            <?php endif; ?>
            <?php if (trim((string)($hdr['dine_option'] ?? '')) !== ''): require_once __DIR__ . '/includes/dine_option.php'; ?>
                <div class="center" style="font-size:15px;font-weight:800;letter-spacing:.06em;margin:2px 0">*** <?php echo $esc(strtoupper(psDineOptionLabel($hdr['dine_option']))); ?> ***</div>
            <?php endif; ?>
            <hr class="rule">
            <div class="muted">
                Sale ref: <strong><?php echo $esc($invoice); ?></strong><br>
                Date: <?php echo $esc($hdr['sold_at'] ?? $hdr['date_'] ?? ''); ?><br>
                Branch: <?php echo $esc($branch); ?><br>
                Cashier: <?php echo $esc($hdr['cashier'] ?? ''); ?>
                <?php if ($customer !== '' && strcasecmp($customer, 'Cash Customer') !== 0): ?><br>Customer: <?php echo $esc($customer); ?><?php endif; ?>
            </div>
            <hr class="rule">
            <table>
                <thead><tr><th class="qty">Qty</th><th>Item</th><th class="box">Picked</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="qty"><?php echo $esc($it['quantity']); ?></td>
                        <td><?php echo $esc($it['product']); ?><?php if ($it['product_id'] !== '' && $it['product_id'] !== $it['product']): ?><div class="muted">Code <?php echo $esc($it['product_id']); ?></div><?php endif; ?></td>
                        <td class="box"><span></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <hr class="rule">
            <div class="muted">Lines: <?php echo count($items); ?></div>
            <div class="sign">Picked by: ______________________</div>
            <div class="sign">Collected: ______________________</div>
        </div>
    </div>
</body>
</html>
