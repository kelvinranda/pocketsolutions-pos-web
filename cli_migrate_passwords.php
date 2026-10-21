<?php
/**
 * Phase 15 — One-time / idempotent plaintext password migration (CLI).
 * Usage: php cli_migrate_passwords.php
 * Never prints password values. Safe to re-run.
 */
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/security.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$before = psSecurityPasswordStorageCounts($conn);
echo "BEFORE total={$before['total']} hashed={$before['hashed']} plaintext={$before['plaintext']} empty={$before['empty']}\n";

$result = psSecurityMigratePlaintextPasswords($conn);

echo "migrated={$result['migrated']} already_hashed={$result['already_hashed']} skipped_empty={$result['skipped_empty']} failed={$result['failed']}\n";
echo "plaintext_remaining={$result['plaintext_remaining']} empty_remaining={$result['empty_remaining']}\n";
if (!empty($result['issues'])) {
    foreach ($result['issues'] as $issue) {
        echo 'issue user_id=' . (int)$issue['user_id'] . ' code=' . (string)$issue['issue'] . "\n";
    }
}
if (!empty($result['error'])) {
    echo 'error=' . $result['error'] . "\n";
}

$after = psSecurityPasswordStorageCounts($conn);
echo "AFTER total={$after['total']} hashed={$after['hashed']} plaintext={$after['plaintext']} empty={$after['empty']}\n";
echo !empty($result['ok']) ? "MIGRATION_OK\n" : "MIGRATION_INCOMPLETE\n";

mysqli_close($conn);
exit(!empty($result['ok']) ? 0 : 2);
