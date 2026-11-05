<?php
/**
 * Phase 9.3 — Single-invoice receipt PDF.
 *
 * Receipts are private operational/financial documents (not a public token URL).
 * Requires authenticated session. Sale header branch must be authorised for the
 * caller (ordinary = home/working only; admin = any authorised/registered;
 * blank/orphan header branch = admin only). Unauthorised invoice IDs get the
 * same generic not-found response (no existence leak). Callers: all_receipts.php,
 * customer_view.php (authenticated staff UIs).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'vendor/autoload.php'; // Include MPDF
require 'pockets_config.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/product_packs.php';
require_once __DIR__ . '/includes/cash_controls.php';
require_once __DIR__ . '/includes/receipt_reprint.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/tax.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if (!psBranchSessionAuthenticated()) {
    header('Location: login.php');
    exit;
}

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    echo receipt_fallback_page('The receipt could not be opened.');
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

// Get invoice number from GET request (do not trust branch/role POST params).
$invoice_raw = isset($_GET['invoice_number']) ? trim((string)$_GET['invoice_number']) : '';
$invoice_number = $invoice_raw !== '' ? $invoice_raw : null;

if ($invoice_number) {
    $details_array = [];
    $total_revenue = 0;
    $receipt_info = null;

    $hdrStmt = mysqli_prepare(
        $conn,
        'SELECT * FROM online_sales_ref WHERE invoice_number = ? LIMIT 1'
    );
    if (!$hdrStmt) {
        error_log('my_receipt: header prepare failed');
        echo receipt_fallback_page('The receipt could not be opened.');
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($hdrStmt, 's', $invoice_number);
    if (!mysqli_stmt_execute($hdrStmt)) {
        error_log('my_receipt: header execute failed');
        mysqli_stmt_close($hdrStmt);
        echo receipt_fallback_page('The receipt could not be opened.');
        mysqli_close($conn);
        exit;
    }
    $receipt_result = mysqli_stmt_get_result($hdrStmt);
    $receipt_info = $receipt_result ? mysqli_fetch_assoc($receipt_result) : null;
    mysqli_stmt_close($hdrStmt);

    if (!$receipt_info || !psBranchSaleBranchAuthorised($conn, $receipt_info['branch'] ?? '') || !psReceiptVisibleToUser($receipt_info)
        || !psOperatorMayOpenReceipt($conn, $receipt_info)) {
        // Same message for missing and unauthorised (no cross-branch existence leak).
        echo receipt_fallback_page('Receipt not found.');
        mysqli_close($conn);
        exit;
    }
    // 2026-10-24: a PDF of a completed receipt is a copy — Till users need a supervisor's approval first.
    $grant = psReprintConsumeGrant((string)$invoice_number, PS_REPRINT_DOC_PDF, trim((string)($_GET['grant'] ?? '')));
    if (empty($grant['ok'])) {
        psReprintAudit($conn, (string)$invoice_number, (string)($receipt_info['branch'] ?? ''), 'DENIED', PS_REPRINT_DOC_PDF, null, 'Opened without a valid approval');
        mysqli_close($conn);
        psReprintDeniedPage((string)$grant['error']);
        exit;
    }
    psReceiptReprintLog($conn, (string)$invoice_number, (string)($receipt_info['branch'] ?? ''), 'web', null, null, null,
        (string)$grant['approved_by'], PS_REPRINT_DOC_PDF);

    $lineStmt = mysqli_prepare(
        $conn,
        'SELECT * FROM online_sales WHERE invoice_number = ?'
    );
    if (!$lineStmt) {
        error_log('my_receipt: lines prepare failed');
        echo receipt_fallback_page('The receipt could not be opened.');
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_bind_param($lineStmt, 's', $invoice_number);
    if (!mysqli_stmt_execute($lineStmt)) {
        error_log('my_receipt: lines execute failed');
        mysqli_stmt_close($lineStmt);
        echo receipt_fallback_page('The receipt could not be opened.');
        mysqli_close($conn);
        exit;
    }
    $sales_result = mysqli_stmt_get_result($lineStmt);
    if ($sales_result) {
        while ($row = mysqli_fetch_assoc($sales_result)) {
            $details_array[] = $row;
            $total_revenue += (float)$row['revenue'];
        }
    }
    mysqli_stmt_close($lineStmt);

    if ($receipt_info) {
        $esc = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        // Multi-currency (2026-10-20): amounts in the sale's own currency.
        require_once __DIR__ . '/includes/currency.php';
        $fxSnap = psFxSaleSnapshot($conn, (string)$invoice_number);
        $saleCur = (string)$fxSnap['currency'];
        $money = static function ($v) use ($conn, $saleCur) {
            return psFxFormat($conn, $v, $saleCur);
        };
        $fxRateLine = ($saleCur !== $fxSnap['base'] && $fxSnap['rate'] !== '') ? psFxRateLabel($fxSnap['base'], $saleCur, $fxSnap['rate']) : '';

        $companyTitle = 'Receipt';
        foreach (['company_name', 'company', 'business_name'] as $companyKey) {
            if (isset($receipt_info[$companyKey]) && trim((string) $receipt_info[$companyKey]) !== '') {
                $companyTitle = $receipt_info[$companyKey];
                break;
            }
        }
        if ($companyTitle === 'Receipt') {
            $cashierName = isset($receipt_info['cashier']) ? trim((string) $receipt_info['cashier']) : '';
            if ($cashierName !== '') {
                $cashierEsc = mysqli_real_escape_string($conn, $cashierName);
                $companyResult = mysqli_query(
                    $conn,
                    "SELECT company_name FROM users WHERE firstname = '$cashierEsc' OR email = '$cashierEsc' OR CONCAT(firstname, ' ', lastname) = '$cashierEsc' LIMIT 1"
                );
                if ($companyResult && ($companyRow = mysqli_fetch_assoc($companyResult)) && trim((string) $companyRow['company_name']) !== '') {
                    $companyTitle = $companyRow['company_name'];
                }
            }
        }
        if ($companyTitle === 'Receipt') {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            if (!empty($_SESSION['the_company'])) {
                $companyTitle = $_SESSION['the_company'];
            }
        }

        // 2026-10-31: the company's own logo, uploaded by an admin on the Receipts page (none = no picture).
        // It used to pick a hard-coded company logo by name.
        require_once __DIR__ . '/includes/company_logo.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $logoHtml = psCompanyLogoInvoiceCell(psCompanyLogoGet($conn, trim((string)($_SESSION['the_company'] ?? ''))), $esc);

        $storedTotal = null;
        if (isset($receipt_info['totalz']) && is_numeric(trim((string)$receipt_info['totalz']))) {
            $storedTotal = (float)trim((string)$receipt_info['totalz']);
        }
        if ($storedTotal === null) {
            $storedTotal = (float)$total_revenue;
        }
        $totalFormatted = $money($storedTotal);

        $saleWhen = '';
        if (isset($receipt_info['sold_at']) && trim((string)$receipt_info['sold_at']) !== '' && strpos((string)$receipt_info['sold_at'], '0000-00-00') !== 0) {
            $saleWhen = (string)$receipt_info['sold_at'];
        } elseif (isset($receipt_info['date_']) && trim((string)$receipt_info['date_']) !== '') {
            $saleWhen = (string)$receipt_info['date_'];
        }

        $tillLabel = 'Not recorded';
        $tillId = isset($receipt_info['till_session_id']) ? (int)$receipt_info['till_session_id'] : 0;
        if ($tillId > 0) {
            $tillRes = mysqli_query(
                $conn,
                "SELECT r.name AS register_name
                 FROM sessions s
                 LEFT JOIN registers r ON r.id = s.register_id
                 WHERE s.id = $tillId
                 LIMIT 1"
            );
            if ($tillRes && ($tillRow = mysqli_fetch_assoc($tillRes))) {
                $registerName = trim((string)($tillRow['register_name'] ?? ''));
                if ($registerName !== '') {
                    $tillLabel = $registerName;
                }
            }
        }

        $metaItems = [];
        if ($saleWhen !== '') {
            $metaItems[] = ['Date', $saleWhen];
        }
        foreach ([
            'Cashier' => 'cashier',
            'Customer' => 'customer',
            'Branch' => 'branch',
        ] as $label => $key) {
            if (isset($receipt_info[$key]) && trim((string)$receipt_info[$key]) !== '') {
                $metaItems[] = [$label, $receipt_info[$key]];
            }
        }
        $metaItems[] = ['Till', $tillLabel];

        $metaHtml = '';
        if ($metaItems) {
            $metaHtml = '<table class="meta" width="100%"><tr>';
            foreach ($metaItems as $item) {
                $metaHtml .= '<td><div class="meta-label">' . $esc($item[0]) . '</div><div class="meta-value">' . $esc($item[1]) . '</div></td>';
            }
            $metaHtml .= '</tr></table>';
        }

        $paymentView = psReceiptPaymentView(psPaymentForInvoice($conn, $invoice_number));
        $paymentRows = '';
        // 2026-11-05: a split / multi-currency payment lists every tender in its own currency, then the change.
        $tenderLines = psSaleTenderLines($conn, (string)$invoice_number);
        if ($tenderLines) {
            foreach ($tenderLines as $tl) {
                $paymentRows .= '<tr><td>' . $esc($tl['label']) . '</td><td class="num">' . $esc(psFxFormat($conn, $tl['amount'], $tl['currency'])) . '</td></tr>';
            }
        } elseif (!$paymentView['recorded']) {
            $paymentRows = '<tr><td>Payment</td><td class="num">Not recorded</td></tr>';
        } else {
            $paymentRows = '<tr><td>Payment method</td><td class="num">' . $esc($paymentView['method_label']) . '</td></tr>';
            $paymentRows .= '<tr><td>Amount paid</td><td class="num">' . $money($paymentView['amount_paid']) . '</td></tr>';
            $paymentRows .= '<tr><td>Change</td><td class="num">' . $money($paymentView['change_amount']) . '</td></tr>';
            $paymentRows .= '<tr><td>Currency</td><td class="num">' . $esc($paymentView['currency']) . '</td></tr>';
            if ($paymentView['reference'] !== '') {
                $paymentRows .= '<tr><td>Reference</td><td class="num">' . $esc($paymentView['reference']) . '</td></tr>';
            }
        }

        // DejaVu Sans is bundled with mPDF. Segoe UI is not embedded and would fail the PDF.
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 16,
            'margin_bottom' => 18,
        ]);
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
        <link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png"><link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16.png"><link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
            <meta charset="UTF-8">
            <title>Receipt - Invoice #' . $esc($invoice_number) . '</title>
            <style>
                body {
                    font-family: dejavusans, sans-serif;
                    margin: 0;
                    padding: 0;
                    color: #1c1c1c;
                    background: #ffffff;
                    font-size: 10.5pt;
                }
                table.brand {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 0 0 8px 0;
                }
                table.brand td {
                    border: none;
                    vertical-align: middle;
                    padding: 0;
                }
                .brand-logo {
                    padding: 0 14px 0 0;
                }
                .doc-title {
                    font-size: 22pt;
                    font-weight: bold;
                    color: #111111;
                    letter-spacing: 0.2pt;
                    margin: 0;
                    padding: 0;
                }
                .accent {
                    border-bottom: 3px solid #1a86d8; /* Pockets logo blue (2026-10-31) */
                    font-size: 1pt;
                    line-height: 1pt;
                    margin: 0 0 16px 0;
                }
                .invoice-label {
                    font-size: 8.5pt;
                    color: #6b7280;
                    letter-spacing: 0.6pt;
                    margin: 0;
                    padding: 0;
                }
                .invoice-number {
                    font-size: 16pt;
                    font-weight: bold;
                    color: #111111;
                    margin: 2px 0 14px 0;
                    padding: 0;
                }
                table.meta {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 0 0 22px 0;
                }
                table.meta td {
                    vertical-align: top;
                    padding: 0 16px 0 0;
                    border: none;
                }
                .meta-label {
                    font-size: 8pt;
                    color: #6b7280;
                    letter-spacing: 0.4pt;
                    margin: 0 0 2px 0;
                }
                .meta-value {
                    font-size: 10.5pt;
                    color: #1c1c1c;
                }
                table.items {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 0;
                }
                table.items th {
                    background-color: #f3f4f6;
                    color: #374151;
                    font-size: 8.5pt;
                    font-weight: bold;
                    letter-spacing: 0.3pt;
                    text-align: left;
                    padding: 8px 10px;
                    border: none;
                    border-bottom: 1px solid #e5e7eb;
                }
                table.items td {
                    padding: 9px 10px;
                    border: none;
                    border-bottom: 1px solid #eeeeee;
                    font-size: 10.5pt;
                    color: #1c1c1c;
                    vertical-align: top;
                }
                table.items th.num,
                table.items td.num {
                    text-align: right;
                    white-space: nowrap;
                }
                table.totals-wrap {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 14px;
                }
                table.totals-wrap td {
                    border: none;
                    padding: 0;
                    vertical-align: top;
                }
                table.totals {
                    width: 100%;
                    border-collapse: collapse;
                }
                table.totals td {
                    border: none;
                    padding: 4px 0 4px 10px;
                    font-size: 10.5pt;
                    color: #374151;
                }
                table.totals td.num {
                    text-align: right;
                    white-space: nowrap;
                    width: 42%;
                }
                table.totals tr.grand td {
                    border-top: 1.5px solid #111111;
                    padding-top: 8px;
                    font-size: 14pt;
                    font-weight: bold;
                    color: #111111;
                }
                .thanks {
                    margin: 28px 0 0 0;
                    padding-top: 12px;
                    border-top: 1px solid #eeeeee;
                    font-size: 10pt;
                    color: #6b7280;
                }
            </style>
        </head>
        <body>
            <table class="brand">
                <tr>
                    ' . $logoHtml . '
                    <td><div class="doc-title">' . $esc($companyTitle) . '</div></td>
                </tr>
            </table>
            <div class="accent">&nbsp;</div>
            ' . (trim((string)($receipt_info['order_no'] ?? '')) !== '' ? '<p class="invoice-label">ORDER</p><p class="invoice-number">' . $esc($receipt_info['order_no']) . '</p>' : '') . '
            <p class="invoice-label">INVOICE · COPY</p>
            <p class="invoice-number">' . $esc($invoice_number) . '</p>
            ' . $metaHtml . '
            <table class="items">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit price</th>
                        <th>Tax</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>';

        $headerTaxStatus = strtoupper(trim((string)($receipt_info['tax_data_status'] ?? PS_TAX_STATUS_LEGACY)));
        $sumTax = 0.0;
        $sumTaxable = 0.0;
        $hasCompleteTaxLines = false;
        foreach ($details_array as $item) {
            if (!empty($item['sell_unit']) && is_numeric($item['sell_price'] ?? null)) {
                // 2026-10-28: sold in a pack — the pack price.
                $unitFormatted = $money((float)$item['sell_price']) . ' / ' . (string)$item['sell_unit'];
            } elseif (isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== '' && is_numeric($item['unit_price'])) {
                $unitFormatted = $money((float)$item['unit_price']);
            } else {
                $unitFormatted = 'Not recorded';
            }
            $lineAmount = is_numeric($item['revenue'] ?? null) ? (float)$item['revenue'] : 0;
            $lineTaxStatus = strtoupper(trim((string)($item['tax_data_status'] ?? '')));
            $taxLabel = '—';
            if ($lineTaxStatus === PS_TAX_STATUS_COMPLETE) {
                $hasCompleteTaxLines = true;
                $code = trim((string)($item['tax_code'] ?? ''));
                $class = trim((string)($item['tax_classification'] ?? ''));
                $rate = isset($item['tax_rate_percent']) ? number_format((float)$item['tax_rate_percent'], 2) . '%' : '';
                $taxAmt = is_numeric($item['tax_amount'] ?? null) ? (float)$item['tax_amount'] : 0;
                $taxableAmt = is_numeric($item['taxable_amount'] ?? null) ? (float)$item['taxable_amount'] : 0;
                $sumTax += $taxAmt;
                $sumTaxable += $taxableAmt;
                if ($class === PS_TAX_CLASS_EXEMPT) {
                    $taxLabel = $esc($code . ' EXEMPT');
                } elseif ($class === PS_TAX_CLASS_ZERO_RATED) {
                    $taxLabel = $esc($code . ' ZERO-RATED @ 0%');
                } else {
                    $taxLabel = $esc($code . ' ' . $class . ' @ ' . $rate) . '<br>' . $money($taxAmt);
                }
            } elseif ($lineTaxStatus === PS_TAX_STATUS_UNCONFIGURED) {
                $taxLabel = ''; // customer receipt: no tax wording when no VAT rate is set up
            } elseif ($lineTaxStatus === PS_TAX_STATUS_LEGACY || $lineTaxStatus === '') {
                $taxLabel = '';
            }
            $html .= '
                    <tr>
                        <td>' . $esc($item['product']) . '</td>
                        <td class="num">' . $esc(!empty($item['sell_unit']) ? psPackLineQtyText($item) : $item['quantity']) . '</td>
                        <td class="num">' . $esc($unitFormatted) . '</td>
                        <td>' . $taxLabel . '</td>
                        <td class="num">' . $money($lineAmount) . '</td>
                    </tr>';
        }

        $taxTotalRows = '';
        if ($hasCompleteTaxLines || $headerTaxStatus === PS_TAX_STATUS_COMPLETE) {
            $storedTaxable = isset($receipt_info['taxable_amount']) && is_numeric($receipt_info['taxable_amount'])
                ? (float)$receipt_info['taxable_amount'] : $sumTaxable;
            $storedTax = isset($receipt_info['tax_amount']) && is_numeric($receipt_info['tax_amount'])
                ? (float)$receipt_info['tax_amount'] : $sumTax;
            $modeLabel = trim((string)($receipt_info['tax_pricing_mode'] ?? ''));
            $taxTotalRows = '
                            <tr>
                                <td>Taxable amount' . ($modeLabel !== '' ? ' (' . $esc($modeLabel) . ')' : '') . '</td>
                                <td class="num">' . $money($storedTaxable) . '</td>
                            </tr>
                            <tr>
                                <td>Tax</td>
                                <td class="num">' . $money($storedTax) . '</td>
                            </tr>';
        } elseif ($headerTaxStatus === PS_TAX_STATUS_LEGACY || $headerTaxStatus === '') {
            $taxTotalRows = '
                            <tr>
                                <td colspan="2">Tax split not recorded (legacy)</td>
                            </tr>';
        }

        $html .= '
                </tbody>
            </table>
            <table class="totals-wrap">
                <tr>
                    <td width="58%">&nbsp;</td>
                    <td width="42%">
                        <table class="totals">
                            <tr>
                                <td>Subtotal</td>
                                <td class="num">' . $money((float)$total_revenue) . '</td>
                            </tr>
                            ' . $taxTotalRows . '
                            <tr class="grand">
                                <td>Total</td>
                                <td class="num">' . $totalFormatted . '</td>
                            </tr>
                            ' . $paymentRows . '
                        </table>
                    </td>
                </tr>
            </table>
            <p class="thanks">Thank you.</p>
        </body>
        </html>';

        $mpdf->WriteHTML(function_exists('psBranchLabelHtml') ? psBranchLabelHtml($html) : $html); // 2026-11-02 shared branch names
        $mpdf->Output('Receipt_Invoice_' . $invoice_number . '.pdf', \Mpdf\Output\Destination::INLINE); // Open PDF in browser
        exit;
    } else {
        echo receipt_fallback_page('Receipt not found.');
    }
} else {
    echo receipt_fallback_page('No invoice number provided.');
}

// Close connection
mysqli_close($conn);

function receipt_fallback_page($message) {
    $safe = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt</title><style>
        body { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; background: #ffffff; color: #1c1c1c; margin: 48px; }
        p { font-size: 16px; margin: 0 0 16px 0; }
        .rule { width: 72px; height: 3px; background: #0088D8; margin: 0 0 18px 0; }
        .back-button { background: #ffffff; color: #1c1c1c; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 14px; font: inherit; cursor: pointer; }
    </style></head><body><div class="rule"></div><p>' . $safe . '</p><button class="back-button" onclick="window.location.href=\'all_receipts.php\'">Back to Receipts</button></body></html>';
}
?>