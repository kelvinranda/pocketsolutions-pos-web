<?php
/**
 * 2026-10-31 — The company's own logo on the A4 invoice (receipt PDF, my_receipt.php).
 *
 * Uploaded by an admin on the Receipts page. Stored as a data URI in company_branding (one row per company), like the
 * ZIMRA receipt logo, so nothing is written to the webroot. PNG or JPEG, up to 500 KB. No logo = no picture on the invoice.
 */

require_once __DIR__ . '/cash_controls.php';

function psCompanyLogoEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $ok = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS company_branding (
        company_name VARCHAR(250) NOT NULL PRIMARY KEY,
        invoice_logo MEDIUMTEXT NULL,
        updated_by VARCHAR(250) NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ok;
}

/** The company's invoice logo as a data URI, or null. */
function psCompanyLogoGet(mysqli $conn, string $company): ?string
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $t = mysqli_query($conn, "SHOW TABLES LIKE 'company_branding'");
    if (!$t || mysqli_num_rows($t) === 0) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT invoice_logo FROM company_branding WHERE company_name = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $v = trim((string)($row['invoice_logo'] ?? ''));
    return preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $v) ? $v : null;
}

/** Admin only. $tmpPath = uploaded file, or null to remove the logo. */
function psCompanyLogoSet(mysqli $conn, ?string $tmpPath, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change the invoice logo.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCompanyLogoEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The logo could not be saved.'];
    }
    $data = null;
    if ($tmpPath !== null) {
        $size = @filesize($tmpPath);
        if (!$size || $size > 512000) {
            return ['ok' => false, 'error' => 'Choose an image up to 500 KB.'];
        }
        $info = @getimagesize($tmpPath);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg'], true)) {
            return ['ok' => false, 'error' => 'The logo must be a PNG or JPEG image.'];
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > 4000 || $info[1] > 4000) {
            return ['ok' => false, 'error' => 'The logo must be between 16 and 4000 pixels wide and high.'];
        }
        $data = 'data:' . $info['mime'] . ';base64,' . base64_encode((string)file_get_contents($tmpPath));
    }
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, 'INSERT INTO company_branding (company_name, invoice_logo, updated_by, updated_at) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE invoice_logo = VALUES(invoice_logo), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The logo could not be saved.'];
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $company, $data, $actor, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok && function_exists('psSecurityAudit')) {
        psSecurityAudit($conn, 'SETTING_CHANGE', ['target_type' => 'setting', 'target_id' => 'invoice_logo',
            'summary' => $data === null ? 'Invoice logo removed' : 'Invoice logo uploaded']);
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The logo could not be saved.'];
}

/** The logo cell for the A4 invoice header ('' when none). */
function psCompanyLogoInvoiceCell(?string $dataUri, callable $esc): string
{
    if ($dataUri === null) {
        return '';
    }
    $raw = base64_decode((string)substr($dataUri, (int)strpos($dataUri, ',') + 1), true);
    $size = $raw !== false ? @getimagesizefromstring($raw) : false;
    $h = 64;
    $w = 64;
    if ($size && $size[0] > 0 && $size[1] > 0) {
        $w = (int)round($size[0] * ($h / $size[1]));
        if ($w > 180) {
            $w = 180;
            $h = (int)max(24, round($size[1] * (180 / $size[0])));
        }
    }
    return '<td class="brand-logo" width="' . $w . '"><img src="' . $esc($dataUri) . '" width="' . $w . '" height="' . $h . '" alt=""></td>';
}
