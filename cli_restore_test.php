<?php
/**
 * Phase 15 — Isolated restore test (temp DB only; never production).
 * Usage: php cli_restore_test.php [job_id]
 * If job_id omitted, uses latest SUCCESS database backup.
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
psBackupEnsureSchema($conn);

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($jobId < 1) {
    $r = mysqli_query(
        $conn,
        "SELECT id FROM backup_jobs WHERE status='SUCCESS' AND backup_type='database' AND filename<>'' ORDER BY id DESC LIMIT 1"
    );
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $jobId = $row ? (int)$row['id'] : 0;
}
if ($jobId < 1) {
    echo "RESTORE_TEST_FAIL no SUCCESS backup job found\n";
    mysqli_close($conn);
    exit(2);
}

echo "Using job_id={$jobId}\n";
$fpBefore = psBackupIntegrityFingerprint($conn);

$result = psBackupRestoreIsolatedTest($conn, $servername, $username, $password, $dbname, $jobId, [
    'keep_temp' => false,
]);

$fpAfter = psBackupIntegrityFingerprint($conn);
$seqSame = ((string)$fpBefore['sale_invoice_seq'] === (string)$fpAfter['sale_invoice_seq']);

echo 'production_invoice_seq_unchanged=' . ($seqSame ? 'yes' : 'no') . "\n";
echo 'production_intact=' . (!empty($result['production_intact']) ? 'yes' : 'no') . "\n";
if (!empty($result['checks'])) {
    foreach ($result['checks'] as $k => $v) {
        echo "check {$k}={$v}\n";
    }
}

if (!empty($result['ok'])) {
    echo "RESTORE_TEST_OK temp_db_was={$result['temp_db']}\n";
    mysqli_close($conn);
    exit(0);
}

echo 'RESTORE_TEST_FAIL ' . (string)($result['error'] ?? 'unknown') . "\n";
mysqli_close($conn);
exit(2);
