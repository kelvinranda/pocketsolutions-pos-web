<?php
/**
 * 2026-10-24 — Two-document mode: customer receipt + STOREROOM PICKING SLIP (web).
 *
 * When the company switch is on, the sale transaction queues two print jobs for the SAME invoice:
 * the customer receipt (document RECEIPT) and a picking slip (document PICKING_SLIP). The slip is an
 * operational list of items and quantities to collect — no prices, payment, tax or new number — and is
 * built from the same server-validated sale lines. The sale, stock, cash and invoice are posted once.
 *
 * print_jobs is InnoDB so both jobs roll back with a failed sale. A print agent may poll all jobs
 * (get_receipt.php) or one document (?doc=RECEIPT / ?doc=PICKING_SLIP) to use a separate storeroom printer.
 * A slip that did not print can be printed from the browser (picking_slip_print.php): the first print is the
 * original; later copies are reprints and need a supervisor for Till users (includes/receipt_reprint.php).
 */

require_once __DIR__ . '/cash_controls.php';

const PS_PRINT_DOC_RECEIPT = 'RECEIPT';
const PS_PRINT_DOC_PICKING = 'PICKING_SLIP';

/** Company switch (cash_control_settings 'picking_slip'): off unless an admin turns it on. */
function psPickingSlipEnabled(mysqli $conn, ?string $company = null): bool
{
    $company = trim((string)($company ?? psCashControlsCompany()));
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM cash_control_settings WHERE company_name = ? AND setting_key = 'picking_slip' LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row !== null && (string)$row['setting_value'] === '1';
}

/** Admin only. */
function psPickingSlipSetEnabled(mysqli $conn, bool $on, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change this.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    $v = $on ? '1' : '0';
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, 'picking_slip', ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $v, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The setting could not be saved.'];
}

/**
 * print_jobs: InnoDB (rolls back with the sale) + document column + invoice index. DDL — call before a transaction.
 */
function psPrintJobsEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $res = mysqli_query($conn, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'print_jobs'");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return $ok = false;
    }
    if (strcasecmp((string)$row['ENGINE'], 'InnoDB') !== 0 && !mysqli_query($conn, 'ALTER TABLE print_jobs ENGINE=InnoDB')) {
        error_log('print_jobs: engine change failed');
        return $ok = false;
    }
    $col = mysqli_query($conn, "SHOW COLUMNS FROM print_jobs LIKE 'document'");
    if ($col && mysqli_num_rows($col) === 0
        && !mysqli_query($conn, "ALTER TABLE print_jobs ADD COLUMN document VARCHAR(20) NOT NULL DEFAULT 'RECEIPT', ADD KEY idx_print_jobs_invoice (invoice_number)")) {
        error_log('print_jobs: document column failed');
        return $ok = false;
    }
    return $ok = true;
}

/**
 * Items to collect: sale lines grouped by product (quantities summed), in sale order.
 *
 * @param list<array{product_id:string,product:string,quantity:string}> $lines
 * @return list<array{product_id:string,product:string,quantity:string}>
 */
function psPickingSlipGroup(array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        $pid = trim((string)($l['product_id'] ?? ''));
        $key = $pid !== '' ? $pid : trim((string)($l['product'] ?? ''));
        if (!isset($out[$key])) {
            $out[$key] = ['product_id' => $pid, 'product' => trim((string)($l['product'] ?? $pid)), 'milli' => 0];
        }
        $out[$key]['milli'] += (int)round((float)($l['quantity'] ?? 0) * 1000);
    }
    $rows = [];
    foreach ($out as $r) {
        $q = rtrim(rtrim(number_format($r['milli'] / 1000, 3, '.', ''), '0'), '.');
        $rows[] = ['product_id' => $r['product_id'], 'product' => $r['product'], 'quantity' => $q === '' ? '0' : $q];
    }
    return $rows;
}

/** Sale lines of a completed invoice (for browser print / copies). */
function psPickingSlipSaleLines(mysqli $conn, string $invoice): array
{
    $stmt = mysqli_prepare($conn, 'SELECT product_id, product, quantity FROM online_sales WHERE invoice_number = ? ORDER BY id ASC');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $invoice);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return psPickingSlipGroup($rows);
}

/**
 * Plain-text slip for the print agent (32 columns, same as the receipt).
 *
 * @param array{invoice:string,date:string,branch:string,cashier:string,customer?:string} $hdr
 */
function psPickingSlipText(array $hdr, array $items, string $copyLabel = ''): string
{
    $w = 32;
    $line = str_repeat('-', $w) . "\n";
    $t = str_pad('STOREROOM PICKING SLIP', $w, ' ', STR_PAD_BOTH) . "\n";
    $t .= str_pad('NOT A RECEIPT', $w, ' ', STR_PAD_BOTH) . "\n";
    if ($copyLabel !== '') {
        $t .= str_pad('*** ' . $copyLabel . ' ***', $w, ' ', STR_PAD_BOTH) . "\n";
    }
    // 2026-10-31: restaurant order number, big, so the kitchen / storeroom can match it to the customer.
    if (!empty($hdr['order'])) {
        if (!function_exists('psOrderNumberReceiptBlock')) {
            require_once __DIR__ . '/order_number.php';
        }
        $t .= psOrderNumberReceiptBlock((string)$hdr['order'], $w);
    } else {
        $t .= $line;
    }
    $t .= 'Sale ref: ' . $hdr['invoice'] . "\n";
    $t .= 'Date    : ' . $hdr['date'] . "\n";
    $t .= 'Branch  : ' . (function_exists('psBranchLabel') ? psBranchLabel($hdr['branch']) : $hdr['branch']) . "\n";
    $t .= 'Cashier : ' . $hdr['cashier'] . "\n";
    if (!empty($hdr['customer'])) {
        $t .= 'Customer: ' . $hdr['customer'] . "\n";
    }
    $t .= $line;
    $t .= str_pad('QTY', 7) . str_pad('ITEM', $w - 11) . "[ ]\n";
    $t .= $line;
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $name = (string)$it['product'];
        $first = true;
        foreach (str_split($name, $w - 11) as $chunk) {
            $t .= ($first ? str_pad((string)$it['quantity'], 7) : str_repeat(' ', 7)) . str_pad($chunk, $w - 11) . ($first ? "[ ]\n" : "\n");
            $first = false;
        }
        if ($it['product_id'] !== '' && $it['product_id'] !== $name) {
            $t .= str_repeat(' ', 7) . 'Code ' . $it['product_id'] . "\n";
        }
    }
    $t .= $line;
    $t .= 'Lines: ' . $n . "\n\n";
    $t .= "Picked by : ________________\n\n";
    $t .= "Collected : ________________\n\n";
    return $t;
}

/** Queue a slip in the current transaction. */
function psPickingSlipQueue(mysqli $conn, string $invoice, string $text): bool
{
    $inv = (int)$invoice;
    $stmt = mysqli_prepare($conn, "INSERT INTO print_jobs (invoice_number, receipt, status, document) VALUES (?, ?, 'Pending', 'PICKING_SLIP')");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'is', $inv, $text);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Has the original slip already gone out (agent printed it, or the browser printed the original)?
 * Used to decide whether a browser print is the original or a copy.
 */
function psPickingSlipOriginalPrinted(mysqli $conn, string $invoice): bool
{
    $inv = (int)$invoice;
    $res = mysqli_query($conn, "SELECT 1 FROM print_jobs WHERE invoice_number = $inv AND document = 'PICKING_SLIP' AND status = 'Printed' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        return true;
    }
    require_once __DIR__ . '/receipt_reprint.php';
    if (!psReprintEnsureSchema($conn)) {
        return true;
    }
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM receipt_reprints WHERE invoice_number = ? AND document = 'PICKING_SLIP' AND outcome IN ('ORIGINAL', 'PRINTED') LIMIT 1");
    if (!$stmt) {
        return true; // fail closed: treat as a copy
    }
    mysqli_stmt_bind_param($stmt, 's', $invoice);
    mysqli_stmt_execute($stmt);
    $found = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    return $found;
}

/** The browser printed the original slip: stop the agent printing the queued one as well. */
function psPickingSlipMarkQueuedPrinted(mysqli $conn, string $invoice): void
{
    $inv = (int)$invoice;
    mysqli_query($conn, "UPDATE print_jobs SET status = 'Printed' WHERE invoice_number = $inv AND document = 'PICKING_SLIP' AND status = 'Pending'");
}
