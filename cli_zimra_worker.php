<?php
/**
 * Phase 17 — ZIMRA background job. Run every 1–5 minutes:
 *   Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\pocketsolutions-pos-web\cli_zimra_worker.php
 *   cPanel cron:             * * * * * /usr/local/bin/php /home/USER/public_html/cli_zimra_worker.php
 *
 * - confirms fiscal days opened while offline and resends receipts saved offline (in order)
 * - finishes CloseDay requests ZIMRA is still processing
 * - re-reads GetConfig once a day (tax changes)
 * - prints warnings: fiscal day close due / overdue, certificate expiring within 30 days
 * Exit code 0 = nothing wrong, 1 = warnings / errors printed.
 */
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}
require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/zimra.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');
psEnsureZimraModule($conn);

$lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ps_zimra_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still busy
}

$problems = 0;
$devices = psZimraQuery($conn, "SELECT * FROM zimra_devices WHERE status = 'REGISTERED'") ?? [];
foreach ($devices as $d) {
    $id = (int)$d['id'];
    $tag = '[' . $d['branch'] . ' / ' . $d['environment'] . ' ' . $d['device_id'] . ']';
    $sent = psZimraSubmitPending($conn, $id, 'worker');
    if ($sent['sent'] > 0) {
        echo "$tag sent {$sent['sent']} receipt(s)\n";
    }
    if (!$sent['ok']) {
        echo "$tag WAITING {$sent['error']}\n";
        $problems++;
    }
    psZimraDayPollClose($conn, $id, 'worker');

    if (empty($d['config_synced_at']) || strtotime((string)$d['config_synced_at']) < time() - 86400) {
        $sync = psZimraDeviceSyncConfig($conn, $id, 'worker');
        if (!$sync['ok']) {
            echo "$tag config sync failed: {$sync['error']}\n";
        }
    }
    $day = psZimraDayActive($conn, $id);
    if ($day) {
        $left = psZimraDayHoursLeft($d, $day);
        if ($left !== null && $left <= (float)($d['day_end_notification_hrs'] ?: 2)) {
            echo $left <= 0
                ? "$tag OVERDUE fiscal day {$day['fiscal_day_no']} is over the ZIMRA limit — sales are blocked until it is closed\n"
                : "$tag fiscal day {$day['fiscal_day_no']} must be closed within " . round($left, 1) . " h\n";
            $problems++;
        }
        if ($day['status'] === 'CLOSE_FAILED') {
            echo "$tag fiscal day {$day['fiscal_day_no']} close FAILED: {$day['close_error']}\n";
            $problems++;
        }
    }
    if (!empty($d['certificate_valid_till']) && strtotime((string)$d['certificate_valid_till']) < time() + 30 * 86400) {
        echo "$tag certificate expires {$d['certificate_valid_till']} — renew in ZIMRA Device Setup\n";
        $problems++;
    }
}
mysqli_close($conn);
exit($problems ? 1 : 0);
