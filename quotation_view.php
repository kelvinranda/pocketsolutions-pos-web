<?php
/**
 * 2026-10-17 — One quotation: printable document, PDF download, edit, load into the POS, copy, cancel.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/reporting.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/quotation.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
psReportingRequireAuthRedirect();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$company = trim((string)($_SESSION['the_company'] ?? ''));
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';
$notice = isset($_GET['saved']) ? 'Quotation saved.' : '';

$quote = psQuoteGet($conn, $id, $company);
$scope = psBranchReportScope($conn);
if ($quote === null || empty($scope['ok']) || !in_array($quote['branch'], $scope['branches'], true)) {
    mysqli_close($conn);
    http_response_code(404);
    die('Quotation was not found.');
}

$me = strtolower(trim((string)($_SESSION['logger'] ?? '')));
$mayPrice = psQuoteSessionMayPrice();
$mayCancel = $quote['status'] === PS_QUOTE_OPEN && ($mayPrice || strtolower((string)$quote['created_by']) === $me);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } elseif (!$mayCancel) {
        $error = 'Only the person who made this quotation or a Supervisor, Manager or Admin can cancel it.';
    } else {
        $r = psQuoteCancel($conn, $id, $company, (string)($_POST['reason'] ?? ''));
        if (!empty($r['ok'])) {
            mysqli_close($conn);
            header('Location: quotation_view.php?id=' . $id);
            exit;
        }
        $error = (string)$r['error'];
    }
}

$letter = psQuoteLetterhead($conn, $company, (string)$quote['branch']);
$doc = psQuoteDocumentHtml($quote, $letter);

if (($_GET['export'] ?? '') === 'pdf') {
    mysqli_close($conn);
    require_once __DIR__ . '/vendor/autoload.php';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 14, 'tempDir' => sys_get_temp_dir()]);
    $mpdf->SetTitle('Quotation ' . $quote['quote_number']);
    $mpdf->SetFooter('Quotation ' . $quote['quote_number'] . '||Page {PAGENO} of {nbpg}');
    $mpdf->WriteHTML('<style>' . psQuoteDocumentCss(true) . '</style>' . (function_exists('psBranchLabelHtml') ? psBranchLabelHtml($doc) : $doc)); // 2026-11-02 shared branch names
    $mpdf->Output('Quotation_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$quote['quote_number']) . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

$isOpen = $quote['status'] === PS_QUOTE_OPEN && !psQuoteIsExpired($quote);
$isSuper = function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin();
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation <?php echo $h($quote['quote_number']); ?></title>
<?php ps_head(); ?>
    <style>
        <?php echo psQuoteDocumentCss(); ?>
        .q-paper{background:#fff;color:#111;border-radius:10px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.15);max-width:860px}
        /* The paper is always printed-looking: undo the app's dark table styling inside it. */
        html[data-theme] body.ps-app .q-paper table, html[data-theme] body.ps-app .q-paper thead, html[data-theme] body.ps-app .q-paper tbody,
        html[data-theme] body.ps-app .q-paper tr, html[data-theme] body.ps-app .q-paper th, html[data-theme] body.ps-app .q-paper td{
            background:transparent !important;color:#111 !important;border-radius:0 !important;box-shadow:none !important;text-transform:none;letter-spacing:normal}
        html[data-theme] body.ps-app .q-paper table{border:0 !important;overflow:visible !important}
        html[data-theme] body.ps-app .q-paper .lines th{background:#eef3ef !important;font-size:8.5pt;border-bottom:1px solid #999 !important}
        html[data-theme] body.ps-app .q-paper .lines td{border-bottom:1px solid #ddd !important}
        html[data-theme] body.ps-app .q-paper .head td, html[data-theme] body.ps-app .q-paper .tot td{border:0 !important;padding:2px 6px}
        html[data-theme] body.ps-app .q-paper .grand td{border-top:2px solid #111 !important;padding-top:6px}
        html[data-theme] body.ps-app .q-paper .title{color:#178a43 !important}
        html[data-theme] body.ps-app .q-paper .sub, html[data-theme] body.ps-app .q-paper .pid, html[data-theme] body.ps-app .q-paper .terms,
        html[data-theme] body.ps-app .q-paper .by, html[data-theme] body.ps-app .q-paper .lbl{color:#555 !important}
        html[data-theme] body.ps-app .q-paper .head td.r, html[data-theme] body.ps-app .q-paper .n{text-align:right !important}
        .q-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 16px}
        @media print{
            body *{visibility:hidden}
            .q-paper,.q-paper *{visibility:visible}
            .q-paper{position:absolute;left:0;top:0;box-shadow:none;padding:0;max-width:none}
        }
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Quotation <?php echo $h($quote['quote_number']); ?> <span class="ps-muted" style="font-size:.6em"><?php echo $h(psQuoteStatusLabel($quote)); ?></span></h1>
    <p><a href="quotations.php">All quotations</a></p>
    <?php if ($notice !== ''): ?><p class="success"><?php echo $h($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?php echo $h($error); ?></p><?php endif; ?>
    <?php if ($quote['status'] === PS_QUOTE_CONVERTED): ?>
        <p class="hint">Sold on <?php echo $h($quote['converted_at']); ?> by <?php echo $h($quote['converted_by']); ?> · invoice
            <a href="my_receipt.php?invoice_number=<?php echo $h($quote['converted_invoice']); ?>"><?php echo $h($quote['converted_invoice']); ?></a></p>
    <?php elseif ($quote['status'] === PS_QUOTE_CANCELLED): ?>
        <p class="hint">Cancelled by <?php echo $h($quote['updated_by']); ?> on <?php echo $h($quote['updated_at']); ?><?php echo $quote['cancelled_reason'] ? ': ' . $h($quote['cancelled_reason']) : ''; ?>.</p>
    <?php elseif (psQuoteIsExpired($quote)): ?>
        <p class="hint">This quotation expired on <?php echo $h($quote['valid_until']); ?>. Make a copy to quote again at today's prices.</p>
    <?php endif; ?>

    <div class="q-actions">
        <button type="button" class="ps-btn ps-btn-outline" onclick="window.print()">Print</button>
        <a class="ps-btn ps-btn-outline" href="quotation_view.php?id=<?php echo (int)$id; ?>&amp;export=pdf">Download PDF</a>
        <?php if ($isOpen && !$isSuper): ?>
            <a class="ps-btn ps-btn-default" href="ajax_pos.php?quote=<?php echo (int)$id; ?>">Load into POS</a>
        <?php endif; ?>
        <?php if ($quote['status'] === PS_QUOTE_OPEN): ?>
            <a class="ps-btn ps-btn-outline" href="quotation_edit.php?id=<?php echo (int)$id; ?>">Edit</a>
        <?php endif; ?>
        <a class="ps-btn ps-btn-outline" href="quotation_edit.php?copy=<?php echo (int)$id; ?>">Copy to new quote</a>
    </div>

    <div class="q-paper"><?php echo $doc; ?></div>

    <?php if ($mayCancel): ?>
        <form method="post" action="quotation_view.php" style="margin-top:20px" onsubmit="return confirm('Cancel this quotation? It cannot be used for a sale afterwards.');">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <input type="hidden" name="action" value="cancel">
            <label>Reason <input type="text" name="reason" maxlength="500" placeholder="e.g. customer declined"></label>
            <button type="submit" class="ps-btn ps-btn-outline">Cancel quotation</button>
        </form>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
