<?php
/**
 * Phase 15 — Admin backup / recovery console.
 * Authz: admin only. CSRF on all mutations. Files served from private root (not public URL).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/backup.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

psBranchRequireAdminRedirect('ajax_pos.php');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    error_log('backup_admin: connection failed');
    die('Connection failed.');
}
$conn->set_charset('utf8mb4');
psBackupEnsureSchema($conn);

$flashOk = '';
$flashErr = '';
$actor = trim((string)($_SESSION['logger'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $flashErr = 'Invalid security token. Reload and try again.';
    } elseif (isset($_POST['create_backup'])) {
        $result = psBackupCreateDatabase($conn, $servername, $username, $password, $dbname, [
            'triggered_by' => $actor,
            'source' => 'web',
        ]);
        if (!empty($result['ok'])) {
            $flashOk = 'Backup created (job #' . (int)$result['job_id'] . ', '
                . number_format((int)$result['file_size']) . ' bytes).';
        } else {
            $flashErr = (string)($result['error'] ?? 'Backup failed.');
        }
    } elseif (isset($_POST['delete_backup'])) {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $del = psBackupDeleteJob($conn, $jobId);
        if (!empty($del['ok'])) {
            $flashOk = 'Backup deleted.';
            psSecurityAudit($conn, 'BACKUP_DELETE', [
                'summary' => 'Admin deleted backup via UI',
                'target_type' => 'backup',
                'target_id' => (string)$jobId,
            ]);
        } else {
            $flashErr = (string)($del['error'] ?? 'Delete failed.');
        }
    } elseif (isset($_POST['download_backup'])) {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $resolved = psBackupResolveJobFile($conn, $jobId);
        if (empty($resolved['ok'])) {
            $flashErr = (string)($resolved['error'] ?? 'Download unavailable.');
        } else {
            $path = $resolved['path'];
            $fn = (string)($resolved['job']['filename'] ?? 'backup.sql');
            psSecurityAudit($conn, 'BACKUP_DOWNLOAD', [
                'summary' => 'Admin downloaded backup',
                'target_type' => 'backup',
                'target_id' => (string)$jobId,
                'after' => ['filename' => $fn],
            ]);
            mysqli_close($conn);
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($fn) . '"');
            header('Content-Length: ' . (string)filesize($path));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    } elseif (isset($_POST['run_restore_test'])) {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $test = psBackupRestoreIsolatedTest($conn, $servername, $username, $password, $dbname, $jobId, [
            'keep_temp' => false,
        ]);
        if (!empty($test['ok'])) {
            $flashOk = 'Isolated restore test passed (temp DB dropped; production untouched).';
        } else {
            $flashErr = 'Isolated restore test failed: ' . (string)($test['error'] ?? 'mismatch');
        }
    } elseif (isset($_POST['apply_retention'])) {
        $ret = psBackupApplyRetention($conn, (int)PS_BACKUP_RETENTION_COUNT);
        $flashOk = 'Retention applied. Deleted ' . (int)$ret['deleted'] . ' old SUCCESS backup(s).';
        if (!empty($ret['errors'])) {
            $flashErr = 'Retention notes: ' . implode('; ', $ret['errors']);
        }
    }
}

$jobs = psBackupListJobs($conn, 40);
$health = psBackupHealthSnapshot($conn, true);
$manifest = psBackupAppFilesManifest();
$rootOk = psBackupEnsureRoot();

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup &amp; Recovery</title>
    <?php ps_head(); echo psCsrfMeta(); ?>
</head>
<body class="ps-app">
<?php ps_open('Backup & Recovery'); ?>
<div class="container">
    <h1>Backup &amp; Recovery</h1>
    <p class="hint">
        Database dumps use mysqldump (<code>--single-transaction</code>). Storage is outside the public web tree.
        Encryption at rest and off-site copy are <strong>not configured</strong> in this environment.
        Restore tests use an isolated temporary database only — production is never overwritten from this UI.
    </p>

    <?php if ($flashOk !== ''): ?><p class="ok"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($flashErr !== ''): ?><p class="err"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <section class="panel">
        <h2>Health</h2>
        <ul>
            <?php foreach ($health['checks'] as $k => $v): ?>
                <li><?php echo htmlspecialchars($k . ': ' . $v, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
            <li>Last backup status: <?php echo htmlspecialchars((string)($health['backup_last_status'] ?? 'none'), ENT_QUOTES, 'UTF-8'); ?>
                <?php if (isset($health['backup_last_age_hours']) && $health['backup_last_age_hours'] !== null): ?>
                    (age ~<?php echo (int)$health['backup_last_age_hours']; ?>h)
                <?php endif; ?>
            </li>
            <li>Storage configured: <?php echo !empty($rootOk['ok']) ? 'yes' : 'no'; ?>
                <?php if (empty($rootOk['ok'])): ?>
                    — <?php echo htmlspecialchars((string)($rootOk['error'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </li>
            <li>Retention keep count: <?php echo (int)PS_BACKUP_RETENTION_COUNT; ?> SUCCESS dumps</li>
        </ul>
    </section>

    <section class="panel">
        <h2>Create backup</h2>
        <form method="post" action="">
            <?php echo psCsrfField(); ?>
            <button type="submit" name="create_backup" value="1">Run database backup now</button>
        </form>
        <form method="post" action="" style="margin-top:8px;">
            <?php echo psCsrfField(); ?>
            <button type="submit" name="apply_retention" value="1">Apply retention now</button>
        </form>
    </section>

    <section class="panel">
        <h2>Backup jobs</h2>
        <?php if ($jobs === []): ?>
            <p class="hint">No backup jobs yet.</p>
        <?php else: ?>
            <table class="bk-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Started</th>
                    <th>Status</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Tables</th>
                    <th>Source</th>
                    <th>Error</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $j): ?>
                    <tr>
                        <td><?php echo (int)$j['id']; ?></td>
                        <td><?php echo htmlspecialchars((string)$j['started_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$j['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="ps-wrap-anywhere"><?php echo htmlspecialchars((string)$j['filename'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((int)$j['file_size']); ?></td>
                        <td><?php echo (int)$j['table_count']; ?></td>
                        <td><?php echo htmlspecialchars((string)$j['source'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$j['error_summary'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="actions">
                            <?php if (strtoupper((string)$j['status']) === 'SUCCESS' && (string)$j['filename'] !== ''): ?>
                                <form method="post" action="">
                                    <?php echo psCsrfField(); ?>
                                    <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
                                    <button type="submit" name="download_backup" value="1">Download</button>
                                </form>
                                <form method="post" action="">
                                    <?php echo psCsrfField(); ?>
                                    <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
                                    <button type="submit" name="run_restore_test" value="1">Restore test</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!in_array(strtoupper((string)$j['status']), ['DELETED', 'RUNNING'], true)): ?>
                                <form method="post" action="" onsubmit="return confirm('Delete this backup?');">
                                    <?php echo psCsrfField(); ?>
                                    <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
                                    <button type="submit" name="delete_backup" value="1">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Application files recovery notes</h2>
        <p class="hint"><?php echo htmlspecialchars((string)$manifest['manifest']['secret_restore'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>Include:</strong></p>
        <ul>
            <?php foreach ($manifest['manifest']['include_for_recovery'] as $line): ?>
                <li><?php echo htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
        <p><strong>Exclude:</strong></p>
        <ul>
            <?php foreach ($manifest['manifest']['exclude'] as $line): ?>
                <li><?php echo htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="panel">
        <h2>Scheduling (deployment)</h2>
        <p class="hint">
            Automation is <strong>not configured</strong> on this host by the application.
            Schedule via Windows Task Scheduler (or cron on Linux):
        </p>
        <pre style="white-space:pre-wrap;background:#f7f7f7;padding:10px;">c:\xampp\php\php.exe c:\xampp\htdocs\pocketsolutions-pos-web\cli_backup.php</pre>
        <p class="hint">Optional: set env <code>PS_BACKUP_ROOT</code> to a private path outside htdocs. Review admin UI for missed/failed jobs.</p>
    </section>
</div>
<?php ps_close(); ?>
</body>
</html>
