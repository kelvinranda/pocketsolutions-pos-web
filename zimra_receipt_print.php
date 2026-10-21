<?php
/**
 * Phase 17 — Printable ZIMRA fiscal receipt (invoice or credit note), from the signed data that was
 * sent to ZIMRA. Browser print, 80 mm. Branch ACL same as receipt_print.php.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/zimra.php';

mysqli_report(MYSQLI_REPORT_OFF);
if (!psBranchSessionAuthenticated()) {
    header('Location: login.php');
    exit;
}
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    exit('The receipt could not be opened.');
}
mysqli_set_charset($conn, 'utf8mb4');
psEnsureZimraModule($conn);

$id = (int)($_GET['id'] ?? 0);
$r = $id > 0 ? psZimraOne($conn, 'SELECT * FROM zimra_receipts WHERE id = ?', 'i', [$id]) : null;
if (!$r || !psBranchSaleBranchAuthorised($conn, (string)$r['branch'])
    || !psZimraDeviceInSessionCompany(psZimraDeviceGet($conn, (int)$r['device_row_id']))) {
    mysqli_close($conn);
    exit('Fiscal receipt not found.');
}
$v = psZimraReceiptView($conn, $r);
$rc = json_decode((string)$r['request_json'], true) ?: [];
$qr = psZimraQrDataUri((string)$r['qr_url']);
mysqli_close($conn);

$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$m = static fn ($x): string => number_format((float)$x, 2);
$taxTotal = 0.0;
foreach (($rc['receiptTaxes'] ?? []) as $t) {
    $taxTotal += (float)$t['taxAmount'];
}
$total = (float)($rc['receiptTotal'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png"><link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16.png"><link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($v['title'] . ' ' . $r['invoice_no']); ?></title>
    <style>
        :root { --w: 80mm; }
        body { margin: 0; background: #e5e7eb; color: #111; font-family: "Courier New", monospace; font-size: 12px; }
        .bar { position: sticky; top: 0; padding: 10px 14px; background: #fff; border-bottom: 1px solid #d1d5db; font-family: system-ui, sans-serif; }
        .bar button, .bar a { font: inherit; padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: inherit; text-decoration: none; cursor: pointer; }
        .wrap { display: flex; justify-content: center; padding: 16px 10px 40px; }
        .rcpt { width: var(--w); max-width: 100%; background: #fff; padding: 10px 8px 16px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .c { text-align: center; } .b { font-weight: 700; } .r { text-align: right; white-space: nowrap; }
        hr { border: 0; border-top: 1px dashed #6b7280; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
        .qr img { width: 42mm; height: 42mm; }
        .logo img { max-width: 50mm; max-height: 22mm; margin: 0 auto 6px; display: block; }
        .warn { border: 1px solid #b45309; color: #b45309; padding: 4px; margin: 6px 0; text-align: center; }
        @media print { .bar { display: none; } body { background: #fff; } .wrap { padding: 0; } .rcpt { box-shadow: none; } @page { margin: 3mm; size: 80mm auto; } }
    </style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print</button> <a href="javascript:history.back()">Back</a></div>
<div class="wrap"><div class="rcpt">
    <?php if ($v['logo'] !== ''): ?><div class="c logo"><img src="<?php echo $h($v['logo']); ?>" alt=""></div><?php endif; ?>
    <div class="c b"><?php echo $h($v['taxpayer_name']); ?></div>
    <div class="c">TIN: <?php echo $h($v['tin']); ?><br>VAT No: <?php echo $h($v['vat_number']); ?></div>
    <div class="c"><?php echo $h($v['branch_name']); ?><br><?php echo $h($v['address']); ?>
        <?php if ($v['email'] !== ''): ?><br><?php echo $h($v['email']); ?><?php endif; ?>
        <?php if ($v['phone'] !== ''): ?><br><?php echo $h($v['phone']); ?><?php endif; ?></div>
    <hr><div class="c b"><?php echo $h($v['title']); ?></div><hr>
    <table>
        <tr><td>Invoice No: <?php echo $h($v['receipt_no']); ?></td><td class="r">Fiscal day: <?php echo $h($v['fiscal_day_no']); ?></td></tr>
        <tr><td colspan="2">Reference: <?php echo $h($r['invoice_no']); ?></td></tr>
        <?php if ($v['original_invoice'] !== ''): ?><tr><td colspan="2">Credit for invoice: <?php echo $h($v['original_invoice']); ?></td></tr><?php endif; ?>
        <?php if (!empty($rc['receiptNotes'])): ?><tr><td colspan="2">Reason: <?php echo $h($rc['receiptNotes']); ?></td></tr><?php endif; ?>
        <tr><td colspan="2">Device Serial No: <?php echo $h($v['serial_no']); ?></td></tr>
        <tr><td colspan="2">Device ID: <?php echo $h($v['device_id']); ?></td></tr>
        <tr><td colspan="2">Date: <?php echo $h($v['date']); ?></td></tr>
    </table>
    <hr>
    <table>
        <tr class="b"><td>Description</td><td class="r">Amount</td></tr>
        <?php foreach (($rc['receiptLines'] ?? []) as $l): ?>
            <tr><td><?php echo $h($l['receiptLineName']); ?><br>&nbsp;<?php echo $h(rtrim(rtrim(number_format((float)$l['receiptLineQuantity'], 3, '.', ''), '0'), '.')); ?> x <?php echo $m($l['receiptLinePrice']); ?></td>
                <td class="r"><?php echo $m($l['receiptLineTotal']); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <hr>
    <table>
        <tr class="b"><td>Total <?php echo $h($r['receipt_currency']); ?></td><td class="r"><?php echo $m($total); ?></td></tr>
        <?php foreach (($rc['receiptPayments'] ?? []) as $p): ?>
            <tr><td>&nbsp;<?php echo $h($r['receipt_currency'] . ' ' . $p['moneyTypeCode']); ?></td><td class="r"><?php echo $m($p['paymentAmount']); ?></td></tr>
        <?php endforeach; ?>
    </table>
    <hr>
    <table>
        <tr><td>Net Amount</td><td class="r"><?php echo $m($total - $taxTotal); ?></td></tr>
        <?php foreach (($rc['receiptTaxes'] ?? []) as $t): ?>
            <tr><td><?php echo isset($t['taxPercent']) ? 'VAT (' . $h(rtrim(rtrim(number_format((float)$t['taxPercent'], 2), '0'), '.')) . '%)' : 'Exempt'; ?></td><td class="r"><?php echo $m($t['taxAmount']); ?></td></tr>
        <?php endforeach; ?>
        <tr><td>Gross Amount</td><td class="r"><?php echo $m($total); ?></td></tr>
    </table>
    <hr>
    <?php if ($qr !== ''): ?><div class="c qr"><img src="<?php echo $h($qr); ?>" alt="ZIMRA verification QR code"></div><?php endif; ?>
    <div class="c">Verification code:<br><span class="b"><?php echo $h($v['verification_code']); ?></span><br>
        You can verify this receipt manually at<br><?php echo $h($v['verify_site']); ?></div>
    <?php if ($v['status'] === 'PENDING'): ?><div class="warn">Awaiting ZIMRA confirmation</div><?php endif; ?>
    <?php if ($v['environment'] !== 'production'): ?><div class="warn">ZIMRA TEST — NOT A TAX INVOICE</div><?php endif; ?>
</div></div>
</body>
</html>
