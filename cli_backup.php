<?php
/**
 * Phase 15 — CLI database backup.
 * Usage: php cli_backup.php
 * Credentials loaded from pockets_config.php into a temp defaults file (not argv/logs).
 */
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/backup.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$result = psBackupCreateDatabase($conn, $servername, $username, $password, $dbname, [
    'triggered_by' => 'cli',
    'source' => 'cli',
]);

if (!empty($result['ok'])) {
    echo "BACKUP_OK job_id={$result['job_id']} file={$result['filename']} size={$result['file_size']}\n";
    echo "checksum={$result['checksum_sha256']}\n";
    mysqli_close($conn);
    exit(0);
}

echo 'BACKUP_FAIL ' . (string)($result['error'] ?? 'unknown') . "\n";
mysqli_close($conn);
exit(2);
