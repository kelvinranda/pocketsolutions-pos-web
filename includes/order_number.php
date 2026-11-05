<?php
/**
 * 2026-10-31 — Restaurant order numbers (company setting "order_numbers", off by default).
 *
 * When on, every sale gets a short order number for calling out at the counter / kitchen, printed big on the
 * receipt, on the picking slip and shown on the "sale done" screen. It is extra to the invoice number and the
 * ZIMRA receipt number (it never replaces them).
 *
 *   <till prefix>-<sequence>     e.g. T01-001, T01-002 … T01-999, T01-1000 (no wrap)
 *
 * The sequence starts at 001 for each till session (opening the till starts again at 001).
 * Each till has its own prefix and sequence, so two tills open at once never give the same number:
 *   - Windows tills: their till code (T01, T02 …), numbered on the till itself (works offline) and sent with the sale.
 *   - Web POS:       W01, W02 … — the lowest number not used by another open web session on the branch.
 *   - Mobile app:    M01, M02 … — the same rule for mobile sessions.
 * Stored on online_sales_ref.order_no; the per-session counter is sessions.order_seq / sessions.order_prefix.
 */

require_once __DIR__ . '/cash_controls.php';

const PS_ORDER_SETTING = 'order_numbers';

/** Company switch (cash_control_settings 'order_numbers'): off unless an admin turns it on. */
function psOrderNumbersEnabled(mysqli $conn, ?string $company = null): bool
{
    $company = trim((string)($company ?? psCashControlsCompany()));
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT setting_value FROM cash_control_settings WHERE company_name = ? AND setting_key = 'order_numbers' LIMIT 1");
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
function psOrderNumbersSetEnabled(mysqli $conn, bool $on, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change this.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn) || !psOrderNumberEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    $v = $on ? '1' : '0';
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, "INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, 'order_numbers', ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The setting could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $v, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok && function_exists('psSecurityAudit')) {
        psSecurityAudit($conn, 'SETTING_CHANGE', ['target_type' => 'setting', 'target_id' => 'order_numbers',
            'summary' => 'Restaurant order numbers turned ' . ($on ? 'on' : 'off')]);
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The setting could not be saved.'];
}

/** online_sales_ref.order_no + sessions.order_seq / order_prefix. DDL — call before a transaction. Idempotent. */
function psOrderNumberEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $want = [
        ['online_sales_ref', 'order_no', 'VARCHAR(24) NULL DEFAULT NULL'],
        ['sessions', 'order_seq', 'INT NOT NULL DEFAULT 0'],
        ['sessions', 'order_prefix', 'VARCHAR(12) NULL DEFAULT NULL'],
    ];
    foreach ($want as [$table, $column, $def]) {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$res) {
            return $ok = false;
        }
        if (mysqli_num_rows($res) === 0 && !mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $def")) {
            error_log("order numbers: could not add $table.$column");
            return $ok = false;
        }
    }
    return $ok = true;
}

/** "T01" + 7 → "T01-007"; 1000 and up print in full ("T01-1000"). */
function psOrderNumberFormat(string $prefix, int $seq): string
{
    return $prefix . '-' . str_pad((string)max(1, $seq), 3, '0', STR_PAD_LEFT);
}

/**
 * Next order number for a till session, inside the sale transaction (row lock on the session, so two sales on the same
 * session never share a number; rolled back with a failed sale). $kind: 'W' web, 'M' mobile.
 * The session's prefix is chosen on its first order: the lowest W01, W02 … not held by another open session on the branch.
 * Returns null when it could not be allocated (the caller aborts the sale).
 */
function psOrderNumberNext(mysqli $conn, int $sessionId, string $kind = 'W'): ?string
{
    if ($sessionId <= 0) {
        return null;
    }
    $kind = $kind === 'M' ? 'M' : 'W';
    $stmt = mysqli_prepare($conn, 'SELECT order_seq, order_prefix, branch FROM sessions WHERE id = ? FOR UPDATE');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $sessionId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }
    $prefix = trim((string)($row['order_prefix'] ?? ''));
    if ($prefix === '') {
        // Prefix choice is serialised per branch so two first sales at once cannot both take W01.
        $lockName = 'ps_order_prefix_' . substr(sha1((string)$row['branch']), 0, 20);
        $lockRes = mysqli_query($conn, "SELECT GET_LOCK('" . mysqli_real_escape_string($conn, $lockName) . "', 5) AS l");
        $lockRow = $lockRes ? mysqli_fetch_assoc($lockRes) : null;
        if (!$lockRow || (string)$lockRow['l'] !== '1') {
            return null;
        }
        $used = [];
        $q = mysqli_prepare($conn, "SELECT order_prefix FROM sessions
            WHERE branch = ? AND id <> ? AND UPPER(status) = 'OPEN' AND order_prefix LIKE ?");
        $like = $kind . '%';
        if ($q) {
            mysqli_stmt_bind_param($q, 'sis', $row['branch'], $sessionId, $like);
            mysqli_stmt_execute($q);
            $r = mysqli_stmt_get_result($q);
            while ($u = mysqli_fetch_assoc($r)) {
                $used[strtoupper(trim((string)$u['order_prefix']))] = true;
            }
            mysqli_stmt_close($q);
        }
        $n = 1;
        while (isset($used[$kind . str_pad((string)$n, 2, '0', STR_PAD_LEFT)])) {
            $n++;
        }
        $prefix = $kind . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $set = mysqli_prepare($conn, 'UPDATE sessions SET order_prefix = ? WHERE id = ?');
        $okSet = $set && mysqli_stmt_bind_param($set, 'si', $prefix, $sessionId) && mysqli_stmt_execute($set);
        if ($set) {
            mysqli_stmt_close($set);
        }
        mysqli_query($conn, "SELECT RELEASE_LOCK('" . mysqli_real_escape_string($conn, $lockName) . "')");
        if (!$okSet) {
            return null;
        }
    }
    // The next number comes from this session's saved sales (online_sales_ref is InnoDB), so a sale that fails and is rolled
    // back does not use up a number — the legacy sessions table is MyISAM and cannot roll back. A per-session lock, held until
    // the request ends (after the commit), stops two sales at once taking the same number.
    $sessLock = 'ps_order_sess_' . $sessionId;
    $lr = mysqli_query($conn, "SELECT GET_LOCK('" . $sessLock . "', 10) AS l");
    $lrow = $lr ? mysqli_fetch_assoc($lr) : null;
    if (!$lrow || (string)$lrow['l'] !== '1') {
        return null;
    }
    $cnt = mysqli_prepare($conn, "SELECT COUNT(*) AS n FROM online_sales_ref WHERE till_session_id = ? AND order_no IS NOT NULL AND order_no <> ''");
    if (!$cnt) {
        return null;
    }
    mysqli_stmt_bind_param($cnt, 'i', $sessionId);
    mysqli_stmt_execute($cnt);
    $nRow = mysqli_fetch_assoc(mysqli_stmt_get_result($cnt));
    mysqli_stmt_close($cnt);
    $seq = (int)($nRow['n'] ?? 0) + 1;
    // Kept for information only (last number given).
    $up = mysqli_prepare($conn, 'UPDATE sessions SET order_seq = ? WHERE id = ?');
    if ($up) {
        mysqli_stmt_bind_param($up, 'ii', $seq, $sessionId);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }
    return psOrderNumberFormat($prefix, $seq);
}

/** Store the order number on the sale header (inside the sale transaction). */
function psOrderNumberSave(mysqli $conn, string $invoice, string $orderNo): bool
{
    $stmt = mysqli_prepare($conn, 'UPDATE online_sales_ref SET order_no = ? WHERE invoice_number = ?');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $orderNo, $invoice);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/** A desktop / mobile order number as sent by the device: "T01-007" style only, else null. */
function psOrderNumberClean($raw): ?string
{
    $v = strtoupper(trim((string)$raw));
    return preg_match('/^[A-Z0-9]{1,10}-\d{1,7}$/', $v) ? $v : null;
}

/** The big block printed near the top of a 32-column receipt / slip. */
function psOrderNumberReceiptBlock(string $orderNo, int $width = 32): string
{
    if ($orderNo === '') {
        return '';
    }
    $rule = str_repeat('=', $width) . "\n";
    return $rule . str_pad('ORDER ' . $orderNo, $width, ' ', STR_PAD_BOTH) . "\n" . $rule;
}
