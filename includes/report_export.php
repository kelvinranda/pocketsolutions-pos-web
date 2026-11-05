<?php
/**
 * 2026-10-17 — Reusable report export: PDF (mPDF) and Excel (.xlsx, PhpSpreadsheet).
 *
 * $sections = [
 *   ['heading' => 'Goods receipts', 'columns' => ['Invoice', 'Qty', ...], 'rows' => [[...], ...], 'note' => 'optional'],
 *   ...
 * ];
 * psReportExportSend('pdf'|'xlsx', 'Product History', 'Product 1092 · Main · 2026-10-01 to 2026-10-17', $sections, 'product_history_1092');
 * Sends the file and exits. Values are plain text (no HTML); numbers stay numbers in Excel.
 */

function psReportExportFormats(): array
{
    return ['pdf', 'xlsx'];
}

/** Toolbar for a report page: Print / PDF / Excel. $query = the GET parameters that reproduce the report. */
function psReportExportToolbar(array $query): string
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $pdf = $query + ['export' => 'pdf'];
    $xls = $query + ['export' => 'xlsx'];
    return '<p class="no-print ps-link-row" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px">'
        . '<button type="button" class="ps-btn ps-btn-outline ps-btn-sm" onclick="window.print()">Print</button>'
        . '<a class="ps-btn ps-btn-outline ps-btn-sm" href="?' . $h(http_build_query($pdf)) . '">Download PDF</a>'
        . '<a class="ps-btn ps-btn-outline ps-btn-sm" href="?' . $h(http_build_query($xls)) . '">Download Excel</a>'
        . '</p>';
}

function psReportExportSend(string $format, string $title, string $subtitle, array $sections, string $filename): void
{
    if (function_exists('psBranchLabelText')) { // 2026-11-02: shared branch names show without the company suffix
        $subtitle = psBranchLabelText($subtitle);
        array_walk_recursive($sections, static function (&$v) {
            if (is_string($v)) {
                $v = psBranchLabelText($v);
            }
        });
    }
    $filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $filename) ?: 'report';
    $company = trim((string)($_SESSION['the_company'] ?? ''));
    $by = trim((string)($_SESSION['logger'] ?? ''));
    $stamp = date('Y-m-d H:i');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($format === 'xlsx') {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 31));
        $r = 1;
        $sheet->setCellValue([1, $r], $title);
        $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(14);
        $r++;
        $sheet->setCellValue([1, $r], trim($company . ' · ' . $subtitle, ' ·'));
        $r++;
        $sheet->setCellValue([1, $r], 'Printed ' . $stamp . ($by !== '' ? ' by ' . $by : ''));
        $r += 2;
        $maxCols = 1;
        foreach ($sections as $sec) {
            $cols = array_values($sec['columns'] ?? []);
            $maxCols = max($maxCols, count($cols));
            $sheet->setCellValue([1, $r], (string)($sec['heading'] ?? ''));
            $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(12);
            $r++;
            if (!empty($sec['note'])) {
                $sheet->setCellValue([1, $r], (string)$sec['note']);
                $sheet->getStyle([1, $r])->getFont()->setItalic(true);
                $r++;
            }
            foreach ($cols as $i => $c) {
                $sheet->setCellValue([$i + 1, $r], (string)$c);
                $sheet->getStyle([$i + 1, $r])->getFont()->setBold(true);
            }
            $r++;
            $rows = $sec['rows'] ?? [];
            if (!$rows) {
                $sheet->setCellValue([1, $r], (string)($sec['empty'] ?? 'No rows.'));
                $r++;
            }
            // Identifier columns (IDs, invoices, references, codes, barcodes) stay text.
            $textCol = array_map(fn($c) => (bool)preg_match('/\b(id|invoice|reference|ref|code|barcode|number|no\.?)\b/i', (string)$c), $cols);
            foreach ($rows as $row) {
                foreach (array_values($row) as $i => $v) {
                    $s = (string)$v;
                    // Plain numbers as numbers; codes like 0012 and identifier columns as text.
                    if (empty($textCol[$i]) && $s !== '' && preg_match('/^-?\d{1,12}(\.\d+)?$/', $s) && !preg_match('/^-?0\d/', $s)) {
                        $sheet->setCellValue([$i + 1, $r], (float)$s);
                    } else {
                        $sheet->setCellValueExplicit([$i + 1, $r], $s, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }
                $r++;
            }
            $r++;
        }
        for ($i = 1; $i <= $maxCols; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        exit;
    }

    // PDF
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<style>body{font-family:dejavusans,sans-serif;font-size:9pt;color:#111}h1{font-size:15pt;margin:0}'
        . '.sub{color:#555;margin:2px 0 10px}h2{font-size:11pt;margin:14px 0 4px}'
        . 'table{border-collapse:collapse;width:100%;margin-bottom:6px}th{background:#eef1ee;text-align:left;font-size:8pt}'
        . 'th,td{border:0.5px solid #bbb;padding:3px 5px;vertical-align:top}.note{color:#555;font-style:italic}</style>';
    $html .= '<h1>' . $e($title) . '</h1><div class="sub">' . $e(trim($company . ' · ' . $subtitle, ' ·')) . '<br>Printed ' . $e($stamp) . ($by !== '' ? ' by ' . $e($by) : '') . '</div>';
    foreach ($sections as $sec) {
        $html .= '<h2>' . $e($sec['heading'] ?? '') . '</h2>';
        if (!empty($sec['note'])) {
            $html .= '<div class="note">' . $e($sec['note']) . '</div>';
        }
        $cols = array_values($sec['columns'] ?? []);
        $html .= '<table><thead><tr>';
        foreach ($cols as $c) {
            $html .= '<th>' . $e($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $rows = $sec['rows'] ?? [];
        if (!$rows) {
            $html .= '<tr><td colspan="' . max(1, count($cols)) . '">' . $e($sec['empty'] ?? 'No rows.') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_values($row) as $v) {
                $html .= '<td>' . $e($v) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    }
    $mpdf = new \Mpdf\Mpdf(['format' => 'A4-L', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 12, 'margin_bottom' => 12, 'tempDir' => sys_get_temp_dir()]);
    $mpdf->SetTitle($title);
    $mpdf->SetFooter($title . '||Page {PAGENO} of {nbpg}');
    $mpdf->WriteHTML(function_exists('psBranchLabelHtml') ? psBranchLabelHtml($html) : $html); // 2026-11-02 shared branch names
    $mpdf->Output($filename . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}
