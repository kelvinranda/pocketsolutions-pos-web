<?php
/**
 * Phase 17 — ZIMRA fiscal days, fiscal receipts, credit notes, sending and printing.
 * Loaded by includes/zimra.php.
 *
 * Inside a sale / refund transaction (no network):
 *   psZimraIssueForSale()        — FiscalInvoice for a COMPLETED sale (auto-opens the fiscal day locally)
 *   psZimraIssueForRefund()      — CreditNote for a PROCESSED refund (exact lines + quantities)
 * After commit (network, best effort, short timeout):
 *   psZimraAfterCommit()         — confirm a pending OpenDay, then send pending receipts in order
 * Supervisor / Management:
 *   psZimraDayOpenManual(), psZimraDayClose(), psZimraDayPollClose()
 */

const PS_ZIMRA_DAY_OPEN = 'OPEN';
const PS_ZIMRA_DAY_CLOSE_PENDING = 'CLOSE_PENDING';
const PS_ZIMRA_DAY_CLOSED = 'CLOSED';
const PS_ZIMRA_DAY_CLOSE_FAILED = 'CLOSE_FAILED';

const PS_ZIMRA_RC_PENDING = 'PENDING';
const PS_ZIMRA_RC_SUBMITTED = 'SUBMITTED';
const PS_ZIMRA_RC_SUBMITTED_ERRORS = 'SUBMITTED_WITH_ERRORS';
const PS_ZIMRA_RC_REJECTED = 'REJECTED';

function psZimraNow(): string
{
    return date(PS_ZIMRA_DATE_FORMAT);
}

// ---------------------------------------------------------------------------
// Fiscal days
// ---------------------------------------------------------------------------

/** Day that is OPEN or being closed (the one blocking a new day), latest first. */
function psZimraDayActive(mysqli $conn, int $deviceRowId, bool $forUpdate = false): ?array
{
    return psZimraOne(
        $conn,
        "SELECT * FROM zimra_fiscal_days WHERE device_row_id = ? AND status <> 'CLOSED' ORDER BY fiscal_day_no DESC LIMIT 1"
        . ($forUpdate ? ' FOR UPDATE' : ''),
        'i',
        [$deviceRowId]
    );
}

function psZimraDayLatest(mysqli $conn, int $deviceRowId): ?array
{
    return psZimraOne($conn, 'SELECT * FROM zimra_fiscal_days WHERE device_row_id = ? ORDER BY fiscal_day_no DESC LIMIT 1', 'i', [$deviceRowId]);
}

/** Hours until the open day reaches taxPayerDayMaxHrs (negative = over the limit). */
function psZimraDayHoursLeft(array $device, array $day): ?float
{
    if (empty($device['day_max_hrs'])) {
        return null;
    }
    $opened = strtotime((string)$day['opened_at']);
    return ($opened + (int)$device['day_max_hrs'] * 3600 - time()) / 3600;
}

/** Insert a locally opened day (ZIMRA OpenDay is sent after commit). Caller holds the device row lock. */
function psZimraDayOpenLocal(mysqli $conn, array $device, string $actor): ?array
{
    $dayNo = (int)$device['last_fiscal_day_no'] + 1;
    $res = psZimraQuery(
        $conn,
        "INSERT INTO zimra_fiscal_days (device_row_id, fiscal_day_no, status, open_state, opened_at, opened_by) VALUES (?,?, 'OPEN', 'PENDING', ?, ?)",
        'iiss',
        [(int)$device['id'], $dayNo, date('Y-m-d H:i:s'), $actor]
    );
    if (!is_array($res) || $res['insert_id'] < 1) {
        return null;
    }
    psZimraQuery($conn, 'UPDATE zimra_devices SET last_fiscal_day_no = ? WHERE id = ?', 'ii', [$dayNo, (int)$device['id']]);
    psZimraAudit($conn, (int)$device['id'], 'DAY_OPEN_LOCAL', $actor, ['fiscal_day_no' => $dayNo]);
    return psZimraOne($conn, 'SELECT * FROM zimra_fiscal_days WHERE id = ? FOR UPDATE', 'i', [$res['insert_id']]);
}

/** Send OpenDay for a locally opened day that ZIMRA has not confirmed yet. */
function psZimraDayConfirmOpen(mysqli $conn, array $device, string $actor, ?int $timeout = null): array
{
    $day = psZimraOne(
        $conn,
        "SELECT * FROM zimra_fiscal_days WHERE device_row_id = ? AND open_state = 'PENDING' ORDER BY fiscal_day_no LIMIT 1",
        'i',
        [(int)$device['id']]
    );
    if (!$day) {
        return ['ok' => true];
    }
    $resp = psZimraHttp($conn, $device, 'POST', '/Device/v1/' . (int)$device['device_id'] . '/OpenDay', [
        'fiscalDayNo' => (int)$day['fiscal_day_no'],
        'fiscalDayOpened' => date(PS_ZIMRA_DATE_FORMAT, strtotime((string)$day['opened_at'])),
    ], true, $actor, $timeout);
    if (!$resp['ok']) {
        psZimraQuery($conn, 'UPDATE zimra_fiscal_days SET open_error = ? WHERE id = ?', 'si', [$resp['error'], (int)$day['id']]);
        return ['ok' => false, 'error' => $resp['error'], 'retryable' => $resp['retryable']];
    }
    psZimraQuery($conn, "UPDATE zimra_fiscal_days SET open_state = 'CONFIRMED', open_error = NULL WHERE id = ?", 'i', [(int)$day['id']]);
    psZimraAudit($conn, (int)$device['id'], 'DAY_OPEN_CONFIRMED', $actor, ['fiscal_day_no' => (int)$day['fiscal_day_no']]);
    return ['ok' => true];
}

/** Supervisor "Open fiscal day": checks ZIMRA status first, then opens and confirms immediately. */
function psZimraDayOpenManual(mysqli $conn, int $deviceRowId, string $actor): array
{
    $device = psZimraDeviceGet($conn, $deviceRowId);
    if (!$device || $device['status'] !== 'REGISTERED') {
        return ['ok' => false, 'error' => 'The device is not registered with ZIMRA.'];
    }
    if (psZimraDayActive($conn, $deviceRowId)) {
        return ['ok' => false, 'error' => 'A fiscal day is already open for this branch. Close it first.'];
    }
    $st = psZimraDeviceRefreshStatus($conn, $deviceRowId, $actor);
    if (!$st['ok']) {
        return ['ok' => false, 'error' => $st['error']];
    }
    $server = (string)($st['status']['fiscalDayStatus'] ?? 'FiscalDayClosed');
    if ($server !== 'FiscalDayClosed') {
        return ['ok' => false, 'error' => 'ZIMRA reports the fiscal day as ' . $server . '. Close it before opening a new day.'];
    }
    mysqli_begin_transaction($conn);
    $locked = psZimraDeviceGet($conn, $deviceRowId, true);
    if (psZimraDayActive($conn, $deviceRowId, true)) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'A fiscal day was opened a moment ago.'];
    }
    $day = psZimraDayOpenLocal($conn, $locked, $actor);
    if (!$day) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The fiscal day could not be opened.'];
    }
    mysqli_commit($conn);
    $conf = psZimraDayConfirmOpen($conn, psZimraDeviceGet($conn, $deviceRowId), $actor);
    if (!$conf['ok']) {
        return ['ok' => false, 'error' => 'Opened locally; ZIMRA has not confirmed yet: ' . $conf['error'] . ' It will be retried automatically.'];
    }
    return ['ok' => true, 'fiscal_day_no' => (int)$day['fiscal_day_no']];
}

/**
 * Close the active fiscal day: send pending receipts, sign the day counters, CloseDay, then poll.
 * FDMS closes asynchronously; when still processing the day stays CLOSE_PENDING (worker finishes).
 */
function psZimraDayClose(mysqli $conn, int $deviceRowId, string $actor, int $pollSeconds = 10): array
{
    $device = psZimraDeviceGet($conn, $deviceRowId);
    if (!$device) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    $sent = psZimraSubmitPending($conn, $deviceRowId, $actor);
    if (!$sent['ok']) {
        return ['ok' => false, 'error' => 'Some receipts could not be sent to ZIMRA: ' . $sent['error']];
    }

    mysqli_begin_transaction($conn);
    $device = psZimraDeviceGet($conn, $deviceRowId, true);
    $day = psZimraOne(
        $conn,
        "SELECT * FROM zimra_fiscal_days WHERE device_row_id = ? AND status IN ('OPEN','CLOSE_FAILED') ORDER BY fiscal_day_no DESC LIMIT 1 FOR UPDATE",
        'i',
        [$deviceRowId]
    );
    if (!$day) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'There is no open fiscal day to close.'];
    }
    if ($day['open_state'] !== 'CONFIRMED') {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'ZIMRA has not confirmed this fiscal day yet. Check the connection and try again.'];
    }
    $pending = psZimraOne($conn, "SELECT COUNT(*) AS n FROM zimra_receipts WHERE fiscal_day_row_id = ? AND status = 'PENDING'", 'i', [(int)$day['id']]);
    if ((int)($pending['n'] ?? 0) > 0) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Some receipts have not reached ZIMRA yet. Try again when online.'];
    }
    $rows = psZimraQuery(
        $conn,
        "SELECT request_json FROM zimra_receipts WHERE fiscal_day_row_id = ? AND status IN ('SUBMITTED','SUBMITTED_WITH_ERRORS') ORDER BY receipt_counter",
        'i',
        [(int)$day['id']]
    ) ?? [];
    $receipts = array_map(static fn ($r) => json_decode($r['request_json'], true), $rows);
    $counters = psZimraDayCounters($receipts);
    $dayDate = substr((string)$day['opened_at'], 0, 10);
    $sig = psZimraSign(
        psZimraDaySignatureString((int)$device['device_id'], (int)$day['fiscal_day_no'], $dayDate, $counters),
        (string)psZimraDecrypt($device['private_key_enc'])
    );
    if ($sig === null) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'The fiscal day could not be signed.'];
    }
    $request = [
        'fiscalDayNo' => (int)$day['fiscal_day_no'],
        'fiscalDayCounters' => $counters,
        'fiscalDayDeviceSignature' => $sig,
        'receiptCounter' => (int)$day['last_receipt_counter'],
    ];
    psZimraQuery(
        $conn,
        "UPDATE zimra_fiscal_days SET status = 'CLOSE_PENDING', close_requested_at = NOW(), close_requested_by = ?, counters_json = ?, close_error = NULL WHERE id = ?",
        'ssi',
        [$actor, json_encode($counters), (int)$day['id']]
    );
    mysqli_commit($conn);

    $resp = psZimraHttp($conn, $device, 'POST', '/Device/v1/' . (int)$device['device_id'] . '/CloseDay', $request, true, $actor);
    if (!$resp['ok']) {
        psZimraQuery($conn, 'UPDATE zimra_fiscal_days SET status = ?, close_error = ? WHERE id = ?', 'ssi',
            [$resp['retryable'] ? 'OPEN' : 'CLOSE_FAILED', $resp['error'], (int)$day['id']]);
        psZimraAudit($conn, $deviceRowId, 'DAY_CLOSE_FAIL', $actor, ['fiscal_day_no' => (int)$day['fiscal_day_no'], 'error' => $resp['error']]);
        return ['ok' => false, 'error' => $resp['error']];
    }
    psZimraAudit($conn, $deviceRowId, 'DAY_CLOSE_REQUEST', $actor, ['fiscal_day_no' => (int)$day['fiscal_day_no']]);
    $deadline = time() + $pollSeconds;
    do {
        if ($pollSeconds > 0) {
            sleep(2);
        }
        $result = psZimraDayPollClose($conn, $deviceRowId, $actor);
    } while (($result['status'] ?? '') === 'CLOSE_PENDING' && time() < $deadline);
    return ['ok' => ($result['status'] ?? '') !== 'CLOSE_FAILED', 'status' => $result['status'] ?? 'CLOSE_PENDING',
        'error' => $result['close_error'] ?? '', 'fiscal_day_no' => (int)$day['fiscal_day_no']];
}

/** GetStatus for a CLOSE_PENDING day → CLOSED / CLOSE_FAILED. */
function psZimraDayPollClose(mysqli $conn, int $deviceRowId, string $actor): array
{
    $day = psZimraOne($conn, "SELECT * FROM zimra_fiscal_days WHERE device_row_id = ? AND status = 'CLOSE_PENDING' ORDER BY fiscal_day_no DESC LIMIT 1", 'i', [$deviceRowId]);
    if (!$day) {
        return psZimraDayLatest($conn, $deviceRowId) ?? [];
    }
    $st = psZimraDeviceRefreshStatus($conn, $deviceRowId, $actor);
    if ($st['ok']) {
        $s = $st['status'];
        $server = (string)($s['fiscalDayStatus'] ?? '');
        if ($server === 'FiscalDayClosed' && (int)($s['lastFiscalDayNo'] ?? 0) >= (int)$day['fiscal_day_no']) {
            psZimraQuery($conn, "UPDATE zimra_fiscal_days SET status = 'CLOSED', closed_at = NOW(), server_signature_json = ? WHERE id = ?",
                'si', [json_encode($s['fiscalDayServerSignature'] ?? null), (int)$day['id']]);
            psZimraAudit($conn, $deviceRowId, 'DAY_CLOSED', $actor, ['fiscal_day_no' => (int)$day['fiscal_day_no'], 'reconciliation' => $s['fiscalDayReconciliationMode'] ?? '']);
        } elseif ($server === 'FiscalDayCloseFailed') {
            psZimraQuery($conn, "UPDATE zimra_fiscal_days SET status = 'CLOSE_FAILED', close_error = ? WHERE id = ?",
                'si', [(string)($s['fiscalDayClosingErrorCode'] ?? 'Close failed'), (int)$day['id']]);
            psZimraAudit($conn, $deviceRowId, 'DAY_CLOSE_FAILED', $actor, ['fiscal_day_no' => (int)$day['fiscal_day_no'], 'code' => $s['fiscalDayClosingErrorCode'] ?? '']);
        }
    }
    return psZimraOne($conn, 'SELECT * FROM zimra_fiscal_days WHERE id = ?', 'i', [(int)$day['id']]) ?? [];
}

// ---------------------------------------------------------------------------
// Issuing receipts (inside the caller's transaction — no network)
// ---------------------------------------------------------------------------

/**
 * Lock device, find or auto-open the fiscal day, allocate counter + global number, sign and store.
 * $spec: receiptType, currency, invoiceNo, lines (psZimraBuildReceipt format), payments, notes?,
 *        creditDebitNote?, source_type, source_ref, invoice_number, line_map, original_receipt_row_id?
 * @return array{ok:bool, receipt_row_id?:int, error?:string}
 */
function psZimraIssue(mysqli $conn, int $deviceRowId, array $spec, string $actor, bool $requireEnabled = true): array
{
    $device = psZimraDeviceGet($conn, $deviceRowId, true); // serialises numbering per device
    // Credit notes for already-fiscalised sales are still issued when the device was switched off later.
    if (!$device || $device['status'] !== 'REGISTERED' || ($requireEnabled && (int)$device['enabled'] !== 1)) {
        return ['ok' => false, 'error' => 'Fiscalisation is not active for this branch.'];
    }
    $keyPem = psZimraDecrypt($device['private_key_enc'] ?? null);
    if ($keyPem === null) {
        return ['ok' => false, 'error' => 'The fiscal device key is not available. Ask an administrator to check ZIMRA set-up.'];
    }
    $day = psZimraDayActive($conn, $deviceRowId, true);
    if ($day && $day['status'] !== PS_ZIMRA_DAY_OPEN) {
        return ['ok' => false, 'error' => 'The fiscal day is being closed. Wait for the close to finish, then try again.'];
    }
    if ($day) {
        $left = psZimraDayHoursLeft($device, $day);
        if ($left !== null && $left <= 0) {
            return ['ok' => false, 'error' => 'The ZIMRA fiscal day has reached its time limit. A supervisor must close the fiscal day before more sales.'];
        }
    } else {
        $day = psZimraDayOpenLocal($conn, $device, $actor); // auto-open on first receipt of the day
        if (!$day) {
            return ['ok' => false, 'error' => 'The fiscal day could not be opened.'];
        }
        $device['last_fiscal_day_no'] = (int)$day['fiscal_day_no'];
    }

    $counter = (int)$day['last_receipt_counter'] + 1;
    $globalNo = (int)$device['last_receipt_global_no'] + 1;
    $date = psZimraNow();
    $built = psZimraBuildReceipt([
        'deviceId' => (int)$device['device_id'],
        'receiptType' => $spec['receiptType'],
        'currency' => $spec['currency'],
        'receiptCounter' => $counter,
        'receiptGlobalNo' => $globalNo,
        'invoiceNo' => $spec['invoiceNo'],
        'date' => $date,
        'previousReceiptHash' => $counter === 1 ? null : $day['last_receipt_hash'],
        'lines' => $spec['lines'],
        'payments' => $spec['payments'],
        'printForm' => $device['print_form'],
        'notes' => $spec['notes'] ?? null,
        'creditDebitNote' => $spec['creditDebitNote'] ?? null,
        'privateKeyPem' => $keyPem,
    ]);
    if (!$built['ok']) {
        return $built;
    }
    $receipt = $built['receipt'];
    $sig = $receipt['receiptDeviceSignature'];
    $qr = psZimraQrUrl((string)($device['qr_url'] ?: PS_ZIMRA_QR_TEST), (int)$device['device_id'], $date, $globalNo, $sig['signature']);
    $res = psZimraQuery(
        $conn,
        'INSERT INTO zimra_receipts (device_row_id, fiscal_day_row_id, source_type, source_ref, invoice_number, branch, receipt_type,
         receipt_currency, receipt_counter, receipt_global_no, invoice_no, receipt_date, receipt_total, receipt_hash, receipt_signature,
         request_json, line_map_json, qr_url, verification_code, original_receipt_row_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        'iissssssiisssssssssis',
        [
            $deviceRowId, (int)$day['id'], $spec['source_type'], $spec['source_ref'], $spec['invoice_number'], $device['branch'],
            $receipt['receiptType'], $receipt['receiptCurrency'], $counter, $globalNo, $receipt['invoiceNo'],
            date('Y-m-d H:i:s', strtotime($date)), number_format($receipt['receiptTotal'], 2, '.', ''), $sig['hash'], $sig['signature'],
            json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), json_encode($spec['line_map'] ?? []),
            $qr, psZimraVerificationCode($sig['signature']), $spec['original_receipt_row_id'] ?? null, $actor,
        ]
    );
    if (!is_array($res) || $res['insert_id'] < 1) {
        return ['ok' => false, 'error' => 'The fiscal receipt could not be saved.'];
    }
    psZimraQuery($conn, 'UPDATE zimra_fiscal_days SET last_receipt_counter = ?, last_receipt_hash = ? WHERE id = ?', 'isi', [$counter, $sig['hash'], (int)$day['id']]);
    psZimraQuery($conn, 'UPDATE zimra_devices SET last_receipt_global_no = ? WHERE id = ?', 'ii', [$globalNo, $deviceRowId]);
    return ['ok' => true, 'receipt_row_id' => $res['insert_id'], 'device_row_id' => $deviceRowId];
}

/** Line price/qty for FDMS: price×qty must equal the line total, else send one unit at the line total. */
function psZimraLineFor(string $name, string $qty, string $unitPrice, string $lineTotal): array
{
    $q = (float)$qty;
    if ($q > 0 && abs(psZimraMoney(psZimraMoney($unitPrice) * $q) - psZimraMoney($lineTotal)) < 0.001) {
        return ['name' => $name, 'quantity' => $q, 'unitPrice' => psZimraMoney($unitPrice)];
    }
    $qtyLabel = rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    return ['name' => $name . ' (x' . $qtyLabel . ')', 'quantity' => 1, 'unitPrice' => psZimraMoney($lineTotal)];
}

/**
 * FiscalInvoice for a completed sale. Call inside the sale transaction after lines + payment are saved.
 * Returns ok with skipped=true when the branch has no active ZIMRA device.
 */
function psZimraIssueForSale(mysqli $conn, string $invoiceNumber, string $branch, string $actor): array
{
    if (!psEnsureZimraModule($conn)) {
        return ['ok' => false, 'error' => 'Fiscalisation is not available.'];
    }
    $device = psZimraDeviceForBranch($conn, $branch);
    if (!$device) {
        return ['ok' => true, 'skipped' => true];
    }
    $lines = psZimraQuery(
        $conn,
        'SELECT * FROM online_sales WHERE invoice_number = ? AND branch = ? ORDER BY id',
        'ss',
        [$invoiceNumber, $branch]
    ) ?? [];
    if (!$lines) {
        return ['ok' => false, 'error' => 'The sale lines could not be read for fiscalisation.'];
    }
    $pay = psZimraOne($conn, 'SELECT method, currency FROM sale_payments WHERE invoice_number = ? ORDER BY id DESC LIMIT 1', 's', [$invoiceNumber]);
    if (!$pay) {
        return ['ok' => false, 'error' => 'The sale payment could not be read for fiscalisation.'];
    }
    $zLines = [];
    $map = [];
    $total = 0.0;
    foreach ($lines as $i => $l) {
        $tax = psZimraResolveTax($conn, (int)$device['id'], (string)($l['tax_code'] ?? ''));
        if ($tax === null) {
            $code = trim((string)($l['tax_code'] ?? '')) ?: '(no tax code)';
            return ['ok' => false, 'error' => 'Tax code ' . $code . ' is not mapped to a ZIMRA tax. Ask an administrator to map it in ZIMRA Device Setup.'];
        }
        $unit = (string)($l['unit_price'] ?? '');
        if ($unit === '' || !is_numeric($unit)) {
            $unit = (string)((float)$l['revenue'] / max((float)$l['quantity'], 0.001));
        }
        if (!empty($l['sell_unit']) && is_numeric($l['sell_qty'] ?? null) && is_numeric($l['sell_price'] ?? null)) {
            // 2026-10-28: sold in a pack — the fiscal line is in packs at the pack price ("Sugar 2kg (pack of 10)").
            $zl = psZimraLineFor((string)$l['product'] . ' (' . $l['sell_unit'] . ' of ' . rtrim(rtrim(number_format((float)$l['sell_factor'], 3, '.', ''), '0'), '.') . ')',
                (string)$l['sell_qty'], (string)$l['sell_price'], (string)$l['revenue']);
        } else {
            $zl = psZimraLineFor((string)$l['product'], (string)$l['quantity'], $unit, (string)$l['revenue']);
        }
        $zl += [
            'taxId' => (int)$tax['tax_id'],
            'taxPercent' => $tax['tax_percent'] === null ? null : (float)$tax['tax_percent'],
            'taxCode' => $tax['tax_code'] ?: null,
            'hsCode' => null,
        ];
        $zLines[] = $zl;
        $total += psZimraMoney($zl['quantity'] * $zl['unitPrice']);
        $map[] = ['sale_line_id' => (int)$l['id'], 'line_no' => $i + 1, 'tax_id' => $zl['taxId'], 'tax_percent' => $zl['taxPercent'], 'tax_code' => $zl['taxCode']];
    }
    // 2026-11-05 split payment / split currency: the receipt is in the SALE currency (from the sale header; the last
    // payment row can be the change row in another currency). One fiscal payment per tender, valued in the sale
    // currency (sale_amount); change is taken off the cash tenders; the payments then sum exactly to the receipt total.
    $hdr = psZimraOne($conn, 'SELECT currency FROM online_sales_ref WHERE invoice_number = ? LIMIT 1', 's', [$invoiceNumber]);
    $receiptCur = strtoupper(trim((string)($hdr['currency'] ?? ''))) ?: strtoupper((string)($pay['currency'] ?: 'USD'));
    $tenderRows = psZimraQuery($conn, 'SELECT method, amount_paid, change_amount, sale_amount, is_change FROM sale_payments
        WHERE invoice_number = ? ORDER BY tender_seq IS NULL, tender_seq, id', 's', [$invoiceNumber]) ?? [];
    $byType = [];
    $changeLeft = 0.0;
    foreach ($tenderRows as $tr) {
        $val = $tr['sale_amount'] !== null ? (float)$tr['sale_amount'] : (float)$tr['amount_paid'] - (float)$tr['change_amount'];
        if ((int)$tr['is_change'] === 1) {
            $changeLeft += $val;
            continue;
        }
        $type = psZimraMoneyTypeForMethod((string)$tr['method']);
        $byType[$type] = ($byType[$type] ?? 0.0) + $val;
    }
    if ($changeLeft > 0 && isset($byType['Cash'])) {
        $byType['Cash'] -= $changeLeft;
    }
    $payments = [];
    foreach ($byType as $type => $amt) {
        if (psZimraMoney($amt) > 0) {
            $payments[] = [$type, psZimraMoney($amt)];
        }
    }
    if (!$payments) {
        $payments = [[psZimraMoneyTypeForMethod((string)$pay['method']), psZimraMoney($total)]];
    }
    // Rounding: the fiscal lines can differ from the stored tenders by a cent; the largest payment takes the difference.
    $sumPay = array_sum(array_map(static fn($p) => $p[1], $payments));
    if (abs($sumPay - $total) > 0.0001) {
        usort($payments, static fn($a, $b) => $b[1] <=> $a[1]);
        $payments[0][1] = psZimraMoney($payments[0][1] + ($total - $sumPay));
    }
    return psZimraIssue($conn, (int)$device['id'], [
        'receiptType' => 'FiscalInvoice',
        'currency' => $receiptCur,
        'invoiceNo' => $invoiceNumber,
        'lines' => $zLines,
        'payments' => $payments,
        'source_type' => 'SALE',
        'source_ref' => $invoiceNumber,
        'invoice_number' => $invoiceNumber,
        'line_map' => $map,
    ], $actor);
}

/**
 * CreditNote for a processed refund. $pairs = [['line' => online_sales row, 'quantity' => '2.000'], ...].
 * Skipped when the original sale was never fiscalised (legacy / before Phase 17).
 */
function psZimraIssueForRefund(mysqli $conn, string $refundNumber, string $invoiceNumber, string $branch, array $pairs, string $reasonText, string $actor): array
{
    if (!psEnsureZimraModule($conn)) {
        return ['ok' => false, 'error' => 'Fiscalisation is not available.'];
    }
    $orig = psZimraOne(
        $conn,
        "SELECT * FROM zimra_receipts WHERE source_type = 'SALE' AND source_ref = ? AND branch = ? LIMIT 1",
        'ss',
        [$invoiceNumber, $branch]
    );
    if (!$orig) {
        return ['ok' => true, 'skipped' => true];
    }
    if ($orig['status'] === PS_ZIMRA_RC_REJECTED) {
        return ['ok' => false, 'error' => 'The original fiscal invoice was rejected by ZIMRA, so a credit note cannot be issued. Ask an administrator.'];
    }
    $origReq = json_decode((string)$orig['request_json'], true);
    $byLine = [];
    foreach (json_decode((string)$orig['line_map_json'], true) ?: [] as $m) {
        $byLine[(int)$m['sale_line_id']] = $m;
    }
    $zLines = [];
    $total = 0.0;
    foreach ($pairs as $p) {
        $line = $p['line'];
        if (!isset($line['product'], $line['unit_price'])) {
            $full = psZimraOne($conn, 'SELECT product, unit_price, revenue, quantity FROM online_sales WHERE id = ?', 'i', [(int)$line['id']]);
            $line = array_merge($full ?? [], $line);
        }
        $m = $byLine[(int)$line['id']] ?? null;
        if ($m === null) {
            return ['ok' => false, 'error' => 'A refunded line is not on the original fiscal invoice.'];
        }
        $cents = function_exists('psRefundLineAmountCents') ? psRefundLineAmountCents($line, (string)$p['quantity']) : null;
        $amount = $cents === null ? psZimraMoney((float)$line['unit_price'] * (float)$p['quantity']) : $cents / 100;
        $zl = psZimraLineFor((string)$line['product'], (string)$p['quantity'], (string)$line['unit_price'], (string)$amount);
        $zl += ['taxId' => (int)$m['tax_id'], 'taxPercent' => $m['tax_percent'], 'taxCode' => $m['tax_code'] ?: null, 'hsCode' => null];
        $zLines[] = $zl;
        $total += psZimraMoney($zl['quantity'] * $zl['unitPrice']);
    }
    $moneyType = (string)($origReq['receiptPayments'][0]['moneyTypeCode'] ?? 'Cash');
    $cdn = $orig['zimra_receipt_id']
        ? ['receiptID' => (int)$orig['zimra_receipt_id']]
        : psZimraCreditNoteReference($conn, $orig);
    return psZimraIssue($conn, (int)$orig['device_row_id'], [
        'receiptType' => 'CreditNote',
        'currency' => (string)$orig['receipt_currency'],
        'invoiceNo' => $refundNumber,
        'lines' => $zLines,
        'payments' => [[$moneyType, psZimraMoney($total)]],
        'notes' => $reasonText !== '' ? $reasonText : 'Refund ' . $refundNumber,
        'creditDebitNote' => $cdn,
        'source_type' => 'REFUND',
        'source_ref' => $refundNumber,
        'invoice_number' => $invoiceNumber,
        'line_map' => [],
        'original_receipt_row_id' => (int)$orig['id'],
    ], $actor, false);
}

/** Reference to the original receipt by device + global number + fiscal day (when receiptID is not known yet). */
function psZimraCreditNoteReference(mysqli $conn, array $orig): array
{
    $dev = psZimraDeviceGet($conn, (int)$orig['device_row_id']);
    $day = psZimraOne($conn, 'SELECT fiscal_day_no FROM zimra_fiscal_days WHERE id = ?', 'i', [(int)$orig['fiscal_day_row_id']]);
    return [
        'deviceID' => (int)($dev['device_id'] ?? 0),
        'receiptGlobalNo' => (int)$orig['receipt_global_no'],
        'fiscalDayNo' => (int)($day['fiscal_day_no'] ?? 0),
    ];
}

// ---------------------------------------------------------------------------
// Sending (after commit / worker)
// ---------------------------------------------------------------------------

/**
 * Confirm a pending OpenDay, then send PENDING receipts in global-number order.
 * Stops at the first network error so order is preserved; the worker retries.
 */
function psZimraSubmitPending(mysqli $conn, int $deviceRowId, string $actor, ?int $timeout = null): array
{
    $device = psZimraDeviceGet($conn, $deviceRowId);
    if (!$device) {
        return ['ok' => false, 'error' => 'Device not found.', 'sent' => 0];
    }
    $conf = psZimraDayConfirmOpen($conn, $device, $actor, $timeout);
    if (!$conf['ok']) {
        return ['ok' => false, 'error' => $conf['error'], 'sent' => 0];
    }
    $rows = psZimraQuery(
        $conn,
        "SELECT r.*, d.open_state FROM zimra_receipts r JOIN zimra_fiscal_days d ON d.id = r.fiscal_day_row_id
         WHERE r.device_row_id = ? AND r.status = 'PENDING' ORDER BY r.receipt_global_no",
        'i',
        [$deviceRowId]
    ) ?? [];
    $sent = 0;
    foreach ($rows as $r) {
        if ($r['open_state'] !== 'CONFIRMED') {
            return ['ok' => false, 'error' => 'Waiting for ZIMRA to confirm the fiscal day.', 'sent' => $sent];
        }
        $receipt = json_decode((string)$r['request_json'], true);
        // A credit note may reference the original by receiptID once ZIMRA has accepted it (not signed data).
        if ($r['receipt_type'] === 'CreditNote' && !empty($r['original_receipt_row_id']) && empty($receipt['creditDebitNote']['receiptID'])) {
            $o = psZimraOne($conn, 'SELECT zimra_receipt_id FROM zimra_receipts WHERE id = ?', 'i', [(int)$r['original_receipt_row_id']]);
            if (!empty($o['zimra_receipt_id'])) {
                $receipt['creditDebitNote'] = ['receiptID' => (int)$o['zimra_receipt_id']];
            }
        }
        $resp = psZimraHttp($conn, $device, 'POST', '/Device/v1/' . (int)$device['device_id'] . '/SubmitReceipt', ['receipt' => $receipt], true, $actor, $timeout);
        if ($resp['ok']) {
            $errors = $resp['body']['validationErrors'] ?? [];
            psZimraQuery(
                $conn,
                'UPDATE zimra_receipts SET status = ?, attempts = attempts + 1, last_error = NULL, zimra_receipt_id = ?, server_date = ?,
                 server_signature_json = ?, validation_errors_json = ?, submitted_at = NOW() WHERE id = ?',
                'sisssi',
                [
                    $errors ? PS_ZIMRA_RC_SUBMITTED_ERRORS : PS_ZIMRA_RC_SUBMITTED,
                    isset($resp['body']['receiptID']) ? (int)$resp['body']['receiptID'] : null,
                    isset($resp['body']['serverDate']) ? str_replace('T', ' ', substr((string)$resp['body']['serverDate'], 0, 19)) : null,
                    json_encode($resp['body']['receiptServerSignature'] ?? null),
                    $errors ? json_encode($errors) : null,
                    (int)$r['id'],
                ]
            );
            $sent++;
            continue;
        }
        psZimraQuery($conn, 'UPDATE zimra_receipts SET status = ?, attempts = attempts + 1, last_error = ? WHERE id = ?', 'ssi',
            [$resp['retryable'] ? PS_ZIMRA_RC_PENDING : PS_ZIMRA_RC_REJECTED, $resp['error'], (int)$r['id']]);
        if ($resp['retryable']) {
            return ['ok' => false, 'error' => $resp['error'], 'sent' => $sent];
        }
    }
    return ['ok' => true, 'sent' => $sent];
}

/** Best-effort send right after a sale / refund commits (short timeout; never throws). */
function psZimraAfterCommit(mysqli $conn, ?int $deviceRowId, string $actor): array
{
    if (!$deviceRowId) {
        return ['ok' => true, 'sent' => 0];
    }
    // ZIMRA unreachable in the last minute → don't make the cashier wait again; the worker resends.
    $recent = psZimraOne(
        $conn,
        'SELECT http_status FROM zimra_api_log WHERE device_row_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1',
        'i',
        [$deviceRowId]
    );
    if ($recent && (int)$recent['http_status'] === 0) {
        return ['ok' => false, 'error' => 'ZIMRA recently unreachable; queued.', 'sent' => 0];
    }
    try {
        return psZimraSubmitPending($conn, $deviceRowId, $actor, 8);
    } catch (Throwable $e) {
        error_log('zimra: after-commit send failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'send failed', 'sent' => 0];
    }
}

// ---------------------------------------------------------------------------
// Reading + printing
// ---------------------------------------------------------------------------

function psZimraReceiptForSale(mysqli $conn, string $invoiceNumber, string $branch = ''): ?array
{
    if (!psEnsureZimraModule($conn)) {
        return null;
    }
    if ($branch !== '') {
        return psZimraOne($conn, "SELECT * FROM zimra_receipts WHERE source_type = 'SALE' AND source_ref = ? AND branch = ? LIMIT 1", 'ss', [$invoiceNumber, $branch]);
    }
    return psZimraOne($conn, "SELECT * FROM zimra_receipts WHERE source_type = 'SALE' AND source_ref = ? LIMIT 1", 's', [$invoiceNumber]);
}

/** Header + footer details for a printed fiscal receipt. */
function psZimraReceiptView(mysqli $conn, array $r): array
{
    $device = psZimraDeviceGet($conn, (int)$r['device_row_id']) ?? [];
    $day = psZimraOne($conn, 'SELECT fiscal_day_no FROM zimra_fiscal_days WHERE id = ?', 'i', [(int)$r['fiscal_day_row_id']]);
    $addr = json_decode((string)($device['fdms_branch_address'] ?? ''), true) ?: [];
    $contacts = json_decode((string)($device['fdms_branch_contacts'] ?? ''), true) ?: [];
    $address = trim(implode(', ', array_filter([$addr['houseNo'] ?? '', $addr['street'] ?? '', $addr['city'] ?? '', $addr['province'] ?? ''])));
    $vat = trim((string)($device['vat_number'] ?? ''));
    $titles = ['FiscalInvoice' => $vat !== '' ? 'FISCAL TAX INVOICE' : 'FISCAL INVOICE', 'CreditNote' => 'CREDIT NOTE', 'DebitNote' => 'DEBIT NOTE'];
    $orig = null;
    if (!empty($r['original_receipt_row_id'])) {
        $orig = psZimraOne($conn, 'SELECT invoice_no, receipt_global_no FROM zimra_receipts WHERE id = ?', 'i', [(int)$r['original_receipt_row_id']]);
    }
    return [
        'title' => $titles[$r['receipt_type']] ?? 'FISCAL RECEIPT',
        'taxpayer_name' => (string)($device['taxpayer_name'] ?? ''),
        'tin' => (string)($device['taxpayer_tin'] ?? ''),
        'vat_number' => $vat,
        'branch_name' => (string)($device['fdms_branch_name'] ?? ''),
        'address' => $address,
        'phone' => (string)($contacts['phoneNo'] ?? ''),
        'email' => (string)($contacts['email'] ?? ''),
        'device_id' => (string)($device['device_id'] ?? ''),
        'serial_no' => (string)($device['device_serial_no'] ?? ''),
        'fiscal_day_no' => (string)($day['fiscal_day_no'] ?? ''),
        'receipt_no' => $r['receipt_counter'] . '/' . $r['receipt_global_no'],
        'date' => date('d/m/Y H:i', strtotime((string)$r['receipt_date'])),
        'verification_code' => (string)$r['verification_code'],
        'qr_url' => (string)$r['qr_url'],
        'verify_site' => (string)($device['qr_url'] ?? PS_ZIMRA_QR_TEST),
        'status' => (string)$r['status'],
        'original_invoice' => $orig ? (string)$orig['invoice_no'] : '',
        'environment' => (string)($device['environment'] ?? 'test'),
        'logo' => (string)($device['receipt_logo'] ?? ''),
    ];
}

/** Plain-text fiscal block for print_jobs (agent prints text; QR shown on the browser receipt). */
function psZimraReceiptTextBlock(array $v, int $width = 32): string
{
    $line = str_repeat('-', $width) . "\n";
    $out = $line . str_pad($v['title'], $width, ' ', STR_PAD_BOTH) . "\n";
    $out .= $v['taxpayer_name'] . "\n";
    $out .= 'TIN: ' . $v['tin'] . ($v['vat_number'] !== '' ? '  VAT: ' . $v['vat_number'] : '') . "\n";
    if ($v['address'] !== '') {
        $out .= $v['address'] . "\n";
    }
    $out .= 'Fiscal day: ' . $v['fiscal_day_no'] . '  Rcpt: ' . $v['receipt_no'] . "\n";
    $out .= 'Device: ' . $v['device_id'] . ' S/N ' . $v['serial_no'] . "\n";
    if ($v['original_invoice'] !== '') {
        $out .= 'Credit for invoice: ' . $v['original_invoice'] . "\n";
    }
    $out .= "Verification code:\n" . str_pad($v['verification_code'], $width, ' ', STR_PAD_BOTH) . "\n";
    $out .= 'Verify at ' . $v['verify_site'] . "\n";
    if ($v['environment'] !== 'production') {
        $out .= "** ZIMRA TEST - NOT A TAX INVOICE **\n";
    }
    return $out;
}

/** QR image as a data URI (endroid/qr-code from vendor), or '' when unavailable. */
function psZimraQrDataUri(string $text): string
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if ($text === '' || !is_file($autoload)) {
        return '';
    }
    require_once $autoload;
    try {
        if (class_exists('Endroid\\QrCode\\Builder\\Builder')) {
            $builder = new \Endroid\QrCode\Builder\Builder(
                writer: new \Endroid\QrCode\Writer\SvgWriter(),
                data: $text,
                size: 180,
                margin: 4
            );
            return $builder->build()->getDataUri();
        }
    } catch (Throwable $e) {
        error_log('zimra: QR render failed: ' . $e->getMessage());
    }
    return '';
}
