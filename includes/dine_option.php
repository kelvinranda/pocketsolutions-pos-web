<?php
/**
 * 2026-11-13 — Restaurants: Sit in / Take away (company setting "dine_option", off by default).
 *
 * When on, the cashier must choose Sit in or Take away on the pay screen of every till (web, Windows, phone). The choice
 * is kept on the sale header (online_sales_ref.dine_option = SIT_IN | TAKE_AWAY), printed big on the receipt and the
 * picking slip (next to the order number), shown on the receipts list and totalled in the sales reports.
 */

require_once __DIR__ . '/cash_controls.php';

const PS_DINE_SETTING = 'dine_option';
const PS_DINE_SIT_IN = 'SIT_IN';
const PS_DINE_TAKE_AWAY = 'TAKE_AWAY';

/** Company switch: off unless an admin turns it on. */
function psDineOptionEnabled(mysqli $conn, ?string $company = null): bool
{
    $company = trim((string)($company ?? psCashControlsCompany()));
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM cash_control_settings WHERE company_name = ? AND setting_key = 'dine_option' LIMIT 1");
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
function psDineOptionSetEnabled(mysqli $conn, bool $on, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change this.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn) || !psDineOptionEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    $v = $on ? '1' : '0';
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, 'dine_option', ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $v, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok && function_exists('psSecurityAudit')) {
        psSecurityAudit($conn, 'SETTING_CHANGE', ['target_type' => 'setting', 'target_id' => 'dine_option',
            'summary' => 'Sit in / Take away turned ' . ($on ? 'on' : 'off')]);
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The setting could not be saved.'];
}

/** online_sales_ref.dine_option. DDL — call before a transaction. Idempotent. */
function psDineOptionEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `online_sales_ref` LIKE 'dine_option'");
    if (!$res) {
        return $ok = false;
    }
    if (mysqli_num_rows($res) === 0 && !mysqli_query($conn, 'ALTER TABLE `online_sales_ref` ADD COLUMN `dine_option` VARCHAR(12) NULL DEFAULT NULL')) {
        error_log('dine option: could not add online_sales_ref.dine_option');
        return $ok = false;
    }
    return $ok = true;
}

/** "SIT_IN" / "sit in" / "takeaway" … → SIT_IN | TAKE_AWAY, anything else → null. */
function psDineOptionClean($raw): ?string
{
    $v = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$raw));
    if ($v === 'SITIN' || $v === 'EATIN' || $v === 'DINEIN') {
        return PS_DINE_SIT_IN;
    }
    if ($v === 'TAKEAWAY' || $v === 'TAKEOUT') {
        return PS_DINE_TAKE_AWAY;
    }
    return null;
}

/** SIT_IN → "Sit in", TAKE_AWAY → "Take away", else ''. */
function psDineOptionLabel(?string $value): string
{
    $v = psDineOptionClean($value ?? '');
    return $v === PS_DINE_SIT_IN ? 'Sit in' : ($v === PS_DINE_TAKE_AWAY ? 'Take away' : '');
}

/** Store the choice on the sale header (inside the sale transaction). */
function psDineOptionSave(mysqli $conn, string $invoice, string $value): bool
{
    $stmt = mysqli_prepare($conn, 'UPDATE online_sales_ref SET dine_option = ? WHERE invoice_number = ?');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $value, $invoice);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/** The line printed big under the order number on a 32-column receipt / slip: "*** TAKE AWAY ***". */
function psDineOptionReceiptLine(?string $value, int $width = 32): string
{
    $label = psDineOptionLabel($value);
    if ($label === '') {
        return '';
    }
    return str_pad('*** ' . strtoupper($label) . ' ***', $width, ' ', STR_PAD_BOTH) . "\n" . str_repeat('-', $width) . "\n";
}
