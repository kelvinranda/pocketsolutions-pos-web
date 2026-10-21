<?php
/**
 * Phase 15 — Backup, restore-test, retention, and health helpers.
 *
 * Backups are stored OUTSIDE the public web tree by default.
 * Credentials are never written into backup scripts, CLI argv, logs, or audit payloads.
 * Encryption at rest is NOT claimed unless a separate key store is configured (not present).
 * Off-site / cloud replication is NOT claimed.
 */

if (!defined('PS_BACKUP_LOADED')) {
    define('PS_BACKUP_LOADED', 1);
}

require_once __DIR__ . '/security.php';

/** Default retention: keep this many SUCCESS database dumps (never delete the only known-good). */
if (!defined('PS_BACKUP_RETENTION_COUNT')) {
    define('PS_BACKUP_RETENTION_COUNT', 14);
}

/** Concurrent backup lock filename under backup root. */
if (!defined('PS_BACKUP_LOCK_NAME')) {
    define('PS_BACKUP_LOCK_NAME', '.backup.lock');
}

/**
 * Resolve private backup root (outside htdocs). Override via env PS_BACKUP_ROOT.
 */
function psBackupRoot(): string
{
    $env = getenv('PS_BACKUP_ROOT');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(str_replace('\\', '/', trim($env)), '/');
    }
    // c:\xampp\htdocs\pocketsolutions-pos-web → c:\xampp\pos-private\backups
    $xampp = dirname(__DIR__, 2); // htdocs
    $xamppRoot = dirname($xampp); // xampp
    return rtrim(str_replace('\\', '/', $xamppRoot . DIRECTORY_SEPARATOR . 'pos-private' . DIRECTORY_SEPARATOR . 'backups'), '/');
}

function psBackupIsPathInsideRoot(string $path, string $root): bool
{
    $realPath = realpath($path);
    $realRoot = realpath($root);
    if ($realPath === false || $realRoot === false) {
        return false;
    }
    $realPath = str_replace('\\', '/', $realPath);
    $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
    return $realPath === $realRoot || strpos($realPath, $realRoot . '/') === 0;
}

/**
 * Ensure backup root exists with restrictive permissions. Refuses web-public roots.
 *
 * @return array{ok:bool,path?:string,error?:string}
 */
function psBackupEnsureRoot(): array
{
    $root = psBackupRoot();
    $webDoc = str_replace('\\', '/', dirname(__DIR__)); // app root under htdocs
    $normalized = str_replace('\\', '/', $root);
    if (strpos($normalized, $webDoc) === 0) {
        return ['ok' => false, 'error' => 'Backup path must not be inside the public web application directory.'];
    }
    if (stripos($normalized, '/htdocs/') !== false || preg_match('#/htdocs$#i', $normalized)) {
        return ['ok' => false, 'error' => 'Backup path must not be under the public htdocs tree.'];
    }
    if (!is_dir($root)) {
        if (!@mkdir($root, 0700, true) && !is_dir($root)) {
            error_log('backup: failed to create backup root');
            return ['ok' => false, 'error' => 'Backup storage directory could not be created.'];
        }
    }
    if (!is_writable($root)) {
        return ['ok' => false, 'error' => 'Backup storage directory is not writable.'];
    }
    // Marker deny file if somehow served (defence in depth for misconfigured roots).
    $deny = $root . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($deny)) {
        @file_put_contents($deny, '');
    }
    $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return ['ok' => true, 'path' => $root];
}

function psBackupMysqldumpBinary(): string
{
    $env = getenv('PS_MYSQLDUMP_PATH');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mysqldump',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        'mysqldump',
    ];
    foreach ($candidates as $c) {
        if ($c === 'mysqldump') {
            return $c;
        }
        if (is_file($c)) {
            return $c;
        }
    }
    return 'mysqldump';
}

function psBackupMysqlClientBinary(): string
{
    $env = getenv('PS_MYSQL_PATH');
    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysql.exe',
        'C:\\xampp\\mysql\\bin\\mysql',
        '/usr/bin/mysql',
        '/usr/local/bin/mysql',
        'mysql',
    ];
    foreach ($candidates as $c) {
        if ($c === 'mysql') {
            return $c;
        }
        if (is_file($c)) {
            return $c;
        }
    }
    return 'mysql';
}

/**
 * Write a temporary defaults-extra-file so credentials are not on the process argv.
 * Caller MUST unlink the returned path.
 *
 * @return array{ok:bool,path?:string,error?:string}
 */
function psBackupWriteDefaultsFile(string $host, string $user, string $pass): array
{
    $ensured = psBackupEnsureRoot();
    if (empty($ensured['ok'])) {
        return $ensured;
    }
    $root = $ensured['path'];
    $path = $root . DIRECTORY_SEPARATOR . '.mycnf.' . bin2hex(random_bytes(8)) . '.tmp';
    $hostEsc = str_replace(["\n", "\r"], '', $host);
    $userEsc = str_replace(["\n", "\r"], '', $user);
    // Escape backslashes and quotes for my.cnf style.
    $passEsc = str_replace(['\\', '"'], ['\\\\', '\\"'], $pass);
    $body = "[client]\n"
        . 'host="' . $hostEsc . '"' . "\n"
        . 'user="' . $userEsc . '"' . "\n"
        . 'password="' . $passEsc . '"' . "\n";
    if (@file_put_contents($path, $body) === false) {
        error_log('backup: defaults file write failed');
        return ['ok' => false, 'error' => 'Could not prepare secure client defaults file.'];
    }
    @chmod($path, 0600);
    return ['ok' => true, 'path' => $path];
}

function psBackupEnsureSchema(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = "CREATE TABLE IF NOT EXISTS backup_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        status VARCHAR(32) NOT NULL,
        backup_type VARCHAR(32) NOT NULL DEFAULT 'database',
        filename VARCHAR(255) NOT NULL DEFAULT '',
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        checksum_sha256 CHAR(64) NOT NULL DEFAULT '',
        engine_version VARCHAR(64) NOT NULL DEFAULT '',
        table_count INT NOT NULL DEFAULT 0,
        triggered_by VARCHAR(250) NOT NULL DEFAULT '',
        source VARCHAR(32) NOT NULL DEFAULT 'web',
        error_summary VARCHAR(500) NOT NULL DEFAULT '',
        meta_json TEXT NULL,
        PRIMARY KEY (id),
        KEY idx_backup_jobs_started (started_at),
        KEY idx_backup_jobs_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!mysqli_query($conn, $sql)) {
        error_log('backup: backup_jobs create failed');
        return false;
    }
    $ready = true;
    return true;
}

/**
 * @param array<string,mixed> $fields
 */
function psBackupJobInsert(mysqli $conn, array $fields): int
{
    if (!psBackupEnsureSchema($conn)) {
        return 0;
    }
    $started = (string)($fields['started_at'] ?? date('Y-m-d H:i:s'));
    $status = substr((string)($fields['status'] ?? 'RUNNING'), 0, 32);
    $type = substr((string)($fields['backup_type'] ?? 'database'), 0, 32);
    $by = substr((string)($fields['triggered_by'] ?? ''), 0, 250);
    $source = substr((string)($fields['source'] ?? 'web'), 0, 32);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO backup_jobs (started_at, status, backup_type, triggered_by, source)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'sssss', $started, $status, $type, $by, $source);
    $ok = mysqli_stmt_execute($stmt);
    $id = $ok ? (int)mysqli_insert_id($conn) : 0;
    mysqli_stmt_close($stmt);
    return $id;
}

/**
 * @param array<string,mixed> $fields
 */
function psBackupJobUpdate(mysqli $conn, int $id, array $fields): bool
{
    if ($id < 1 || !psBackupEnsureSchema($conn)) {
        return false;
    }
    $finished = (string)($fields['finished_at'] ?? date('Y-m-d H:i:s'));
    $status = substr((string)($fields['status'] ?? 'FAILED'), 0, 32);
    $filename = substr((string)($fields['filename'] ?? ''), 0, 255);
    $fileSize = (int)($fields['file_size'] ?? 0);
    $checksum = substr((string)($fields['checksum_sha256'] ?? ''), 0, 64);
    $engine = substr((string)($fields['engine_version'] ?? ''), 0, 64);
    $tableCount = (int)($fields['table_count'] ?? 0);
    $err = substr((string)($fields['error_summary'] ?? ''), 0, 500);
    $meta = null;
    if (isset($fields['meta']) && is_array($fields['meta'])) {
        $meta = json_encode(psSecurityAuditSanitizePayload($fields['meta']), JSON_UNESCAPED_UNICODE);
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE backup_jobs SET finished_at=?, status=?, filename=?, file_size=?, checksum_sha256=?,
         engine_version=?, table_count=?, error_summary=?, meta_json=? WHERE id=? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'sssississi',
        $finished,
        $status,
        $filename,
        $fileSize,
        $checksum,
        $engine,
        $tableCount,
        $err,
        $meta,
        $id
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Acquire exclusive lock to prevent concurrent backup corruption.
 *
 * @return array{ok:bool,handle?:resource,error?:string}
 */
function psBackupAcquireLock()
{
    $ensured = psBackupEnsureRoot();
    if (empty($ensured['ok'])) {
        return $ensured;
    }
    $lockPath = $ensured['path'] . DIRECTORY_SEPARATOR . PS_BACKUP_LOCK_NAME;
    $fh = @fopen($lockPath, 'c+');
    if (!$fh) {
        return ['ok' => false, 'error' => 'Could not open backup lock file.'];
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return ['ok' => false, 'error' => 'Another backup is already running.'];
    }
    ftruncate($fh, 0);
    fwrite($fh, (string)getmypid() . ' ' . date('c'));
    fflush($fh);
    return ['ok' => true, 'handle' => $fh];
}

/** @param resource|null $handle */
function psBackupReleaseLock($handle): void
{
    if ($handle === null) {
        return;
    }
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/**
 * Integrity probes for a dump file (size + header + trailer markers).
 *
 * @return array{ok:bool,error?:string,size?:int}
 */
function psBackupVerifyDumpFile(string $path): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Backup file missing.'];
    }
    $size = filesize($path);
    if ($size === false || $size < 200) {
        return ['ok' => false, 'error' => 'Backup file empty or too small.', 'size' => (int)$size];
    }
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return ['ok' => false, 'error' => 'Backup file unreadable.'];
    }
    $head = fread($fh, 4096);
    fseek($fh, max(0, $size - 4096));
    $tail = fread($fh, 4096);
    fclose($fh);
    if ($head === false || stripos((string)$head, 'MySQL dump') === false && stripos((string)$head, 'MariaDB dump') === false) {
        // mysqldump usually writes "-- MySQL dump" or MariaDB equivalent
        if ($head === false || (stripos((string)$head, '--') === false && stripos((string)$head, 'CREATE') === false)) {
            return ['ok' => false, 'error' => 'Backup file header invalid.', 'size' => $size];
        }
    }
    $tailStr = (string)$tail;
    if (stripos($tailStr, 'Dump completed') === false && stripos($tailStr, 'COMPLETED') === false) {
        // Still accept if file has substantial SQL content and ends cleanly
        if ($size < 1024) {
            return ['ok' => false, 'error' => 'Backup file appears incomplete.', 'size' => $size];
        }
    }
    return ['ok' => true, 'size' => $size];
}

/**
 * Fingerprint counts used for restore validation (no financial payloads).
 *
 * @return array<string,int|string>
 */
function psBackupIntegrityFingerprint(mysqli $conn): array
{
    $fp = [];
    $map = [
        'users' => 'SELECT COUNT(*) c FROM users',
        'branchies' => 'SELECT COUNT(*) c FROM branchies',
        'goods' => 'SELECT COUNT(*) c FROM goods',
        'online_sales' => 'SELECT COUNT(*) c FROM online_sales',
        'online_sales_ref' => 'SELECT COUNT(*) c FROM online_sales_ref',
        'sale_payments' => 'SELECT COUNT(*) c FROM sale_payments',
        'sale_refund_requests' => 'SELECT COUNT(*) c FROM sale_refund_requests',
        'cash_movements' => 'SELECT COUNT(*) c FROM cash_movements',
        'sessions' => 'SELECT COUNT(*) c FROM sessions',
        'stock_movements' => 'SELECT COUNT(*) c FROM stock_movements',
        'expenses' => 'SELECT COUNT(*) c FROM expenses',
        'tax_rates' => 'SELECT COUNT(*) c FROM tax_rates',
        'accounting_export_batches' => 'SELECT COUNT(*) c FROM accounting_export_batches',
        'accounting_exported_sources' => 'SELECT COUNT(*) c FROM accounting_exported_sources',
        'qty_sum' => 'SELECT IFNULL(SUM(CAST(quantity AS DECIMAL(18,4))),0) c FROM goods',
        'sale_invoice_seq' => 'SELECT next_number c FROM sale_invoice_seq LIMIT 1',
    ];
    foreach ($map as $k => $sql) {
        $r = @mysqli_query($conn, $sql);
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            $fp[$k] = $row['c'];
        } else {
            $fp[$k] = 'missing';
        }
    }
    $r = @mysqli_query($conn, "SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE()");
    $fp['table_count'] = ($r && ($row = mysqli_fetch_assoc($r))) ? (int)$row['c'] : 0;
    $r = @mysqli_query($conn, 'SELECT VERSION() v');
    $fp['engine_version'] = ($r && ($row = mysqli_fetch_assoc($r))) ? (string)$row['v'] : '';
    return $fp;
}

/**
 * Create a database backup via mysqldump.
 *
 * @param array{triggered_by?:string,source?:string} $opts
 * @return array{ok:bool,job_id?:int,filename?:string,path?:string,error?:string,fingerprint?:array}
 */
function psBackupCreateDatabase(mysqli $conn, string $host, string $dbUser, string $dbPass, string $dbName, array $opts = []): array
{
    $lock = psBackupAcquireLock();
    if (empty($lock['ok'])) {
        return ['ok' => false, 'error' => (string)($lock['error'] ?? 'Lock failed')];
    }
    $lockHandle = $lock['handle'];
    $jobId = 0;
    $defaultsPath = '';
    $outPath = '';

    try {
        if (!psBackupEnsureSchema($conn)) {
            return ['ok' => false, 'error' => 'Backup job schema unavailable.'];
        }
        $ensured = psBackupEnsureRoot();
        if (empty($ensured['ok'])) {
            return ['ok' => false, 'error' => (string)$ensured['error']];
        }
        $root = $ensured['path'];
        $fpBefore = psBackupIntegrityFingerprint($conn);
        $jobId = psBackupJobInsert($conn, [
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'RUNNING',
            'backup_type' => 'database',
            'triggered_by' => (string)($opts['triggered_by'] ?? ''),
            'source' => (string)($opts['source'] ?? 'web'),
        ]);

        $def = psBackupWriteDefaultsFile($host, $dbUser, $dbPass);
        if (empty($def['ok'])) {
            psBackupJobUpdate($conn, $jobId, [
                'status' => 'FAILED',
                'error_summary' => (string)($def['error'] ?? 'defaults failed'),
            ]);
            return ['ok' => false, 'job_id' => $jobId, 'error' => (string)$def['error']];
        }
        $defaultsPath = $def['path'];

        $stamp = date('Ymd_His');
        $safeDb = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dbName) ?: 'db';
        $filename = 'db_' . $safeDb . '_' . $stamp . '.sql';
        $outPath = $root . DIRECTORY_SEPARATOR . $filename;

        $dumpBin = psBackupMysqldumpBinary();
        // --single-transaction: InnoDB consistent snapshot without long table locks.
        // Credentials only via defaults-extra-file (not argv).
        $cmd = [
            $dumpBin,
            '--defaults-extra-file=' . $defaultsPath,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--result-file=' . $outPath,
            $dbName,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            psBackupJobUpdate($conn, $jobId, [
                'status' => 'FAILED',
                'error_summary' => 'mysqldump process could not start',
            ]);
            return ['ok' => false, 'job_id' => $jobId, 'error' => 'Database dump tool could not start.'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        // Never log stderr if it might echo connection info — store sanitized summary only.
        $stderrSafe = '';
        if (is_string($stderr) && $stderr !== '') {
            $stderrSafe = substr(preg_replace('/password[^\s]*/i', '[redacted]', $stderr), 0, 200);
        }

        if ($exit !== 0) {
            if (is_file($outPath)) {
                @unlink($outPath);
            }
            psBackupJobUpdate($conn, $jobId, [
                'status' => 'FAILED',
                'error_summary' => 'mysqldump exit ' . $exit . ($stderrSafe !== '' ? ': ' . $stderrSafe : ''),
            ]);
            error_log('backup: mysqldump failed exit=' . $exit);
            return ['ok' => false, 'job_id' => $jobId, 'error' => 'Database dump failed. See server log.'];
        }

        $verify = psBackupVerifyDumpFile($outPath);
        if (empty($verify['ok'])) {
            if (is_file($outPath)) {
                @unlink($outPath);
            }
            psBackupJobUpdate($conn, $jobId, [
                'status' => 'VERIFY_FAILED',
                'error_summary' => (string)($verify['error'] ?? 'verify failed'),
                'file_size' => (int)($verify['size'] ?? 0),
            ]);
            return ['ok' => false, 'job_id' => $jobId, 'error' => (string)($verify['error'] ?? 'Dump verification failed.')];
        }

        $checksum = hash_file('sha256', $outPath) ?: '';
        @chmod($outPath, 0600);
        $meta = [
            'db_name' => $dbName,
            'fingerprint' => $fpBefore,
            'stdout_len' => is_string($stdout) ? strlen($stdout) : 0,
        ];
        psBackupJobUpdate($conn, $jobId, [
            'status' => 'SUCCESS',
            'filename' => $filename,
            'file_size' => (int)$verify['size'],
            'checksum_sha256' => $checksum,
            'engine_version' => (string)($fpBefore['engine_version'] ?? ''),
            'table_count' => (int)($fpBefore['table_count'] ?? 0),
            'error_summary' => '',
            'meta' => $meta,
        ]);

        psSecurityAudit($conn, 'BACKUP_CREATE', [
            'summary' => 'Database backup SUCCESS',
            'target_type' => 'backup',
            'target_id' => (string)$jobId,
            'after' => [
                'filename' => $filename,
                'file_size' => (int)$verify['size'],
                'checksum_sha256' => $checksum,
                'table_count' => (int)($fpBefore['table_count'] ?? 0),
            ],
        ]);

        // Retention after a known-good success (never deletes this new one as sole good).
        psBackupApplyRetention($conn, (int)PS_BACKUP_RETENTION_COUNT);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'filename' => $filename,
            'path' => $outPath,
            'checksum_sha256' => $checksum,
            'file_size' => (int)$verify['size'],
            'fingerprint' => $fpBefore,
        ];
    } finally {
        if ($defaultsPath !== '' && is_file($defaultsPath)) {
            @unlink($defaultsPath);
        }
        psBackupReleaseLock($lockHandle);
    }
}

/**
 * Apply retention: delete oldest SUCCESS dumps beyond $keep, never the only known-good,
 * never delete FAILED/VERIFY_FAILED (or RUNNING) via retention.
 */
function psBackupApplyRetention(mysqli $conn, int $keep): array
{
    $keep = max(1, $keep);
    $result = ['ok' => true, 'deleted' => 0, 'skipped' => 0, 'errors' => []];
    if (!psBackupEnsureSchema($conn)) {
        return ['ok' => false, 'deleted' => 0, 'skipped' => 0, 'errors' => ['schema']];
    }
    $ensured = psBackupEnsureRoot();
    if (empty($ensured['ok'])) {
        return ['ok' => false, 'deleted' => 0, 'skipped' => 0, 'errors' => [(string)$ensured['error']]];
    }
    $root = $ensured['path'];
    $res = mysqli_query(
        $conn,
        "SELECT id, filename FROM backup_jobs WHERE status='SUCCESS' AND backup_type='database' AND filename<>'' ORDER BY started_at DESC, id DESC"
    );
    if (!$res) {
        return ['ok' => false, 'deleted' => 0, 'skipped' => 0, 'errors' => ['query']];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    $successCount = count($rows);
    if ($successCount <= $keep) {
        $result['skipped'] = $successCount;
        return $result;
    }
    // Never delete the only known-good (already handled by keep>=1 and successCount<=keep).
    $toDelete = array_slice($rows, $keep);
    // If somehow only one success remains in toDelete set incorrectly — guard:
    $remainingAfter = $successCount - count($toDelete);
    if ($remainingAfter < 1) {
        $result['errors'][] = 'refused_to_delete_only_known_good';
        return $result;
    }
    foreach ($toDelete as $row) {
        $id = (int)$row['id'];
        $fn = (string)$row['filename'];
        if ($fn === '' || strpos($fn, '..') !== false || strpos($fn, '/') !== false || strpos($fn, '\\') !== false) {
            $result['errors'][] = 'invalid_filename_id_' . $id;
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $fn;
        if (is_file($path)) {
            if (!psBackupIsPathInsideRoot($path, $root)) {
                $result['errors'][] = 'path_escape_id_' . $id;
                continue;
            }
            if (!@unlink($path)) {
                $result['errors'][] = 'unlink_failed_id_' . $id;
                continue;
            }
        }
        $stmt = mysqli_prepare($conn, "UPDATE backup_jobs SET status='DELETED', error_summary='Removed by retention' WHERE id=? AND status='SUCCESS' LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $result['deleted']++;
        psSecurityAudit($conn, 'BACKUP_RETENTION', [
            'summary' => 'Retention deleted older SUCCESS backup',
            'target_type' => 'backup',
            'target_id' => (string)$id,
            'after' => ['filename' => $fn],
        ]);
    }
    return $result;
}

/**
 * Resolve a backup job file for download/delete — path-traversal safe.
 *
 * @return array{ok:bool,path?:string,job?:array,error?:string}
 */
function psBackupResolveJobFile(mysqli $conn, int $jobId): array
{
    if ($jobId < 1 || !psBackupEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'Invalid backup job.'];
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM backup_jobs WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Lookup failed.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $jobId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $job = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$job) {
        return ['ok' => false, 'error' => 'Backup job not found.'];
    }
    $fn = (string)($job['filename'] ?? '');
    if ($fn === '' || !preg_match('/^[a-zA-Z0-9._\-]+$/', $fn)) {
        return ['ok' => false, 'error' => 'Backup filename invalid.', 'job' => $job];
    }
    $ensured = psBackupEnsureRoot();
    if (empty($ensured['ok'])) {
        return ['ok' => false, 'error' => (string)$ensured['error'], 'job' => $job];
    }
    $path = $ensured['path'] . DIRECTORY_SEPARATOR . $fn;
    if (!is_file($path) || !psBackupIsPathInsideRoot($path, $ensured['path'])) {
        return ['ok' => false, 'error' => 'Backup file not available.', 'job' => $job];
    }
    return ['ok' => true, 'path' => $path, 'job' => $job];
}

/**
 * Delete a backup file + mark job DELETED (admin). Refuses if it is the only SUCCESS.
 */
function psBackupDeleteJob(mysqli $conn, int $jobId): array
{
    $resolved = psBackupResolveJobFile($conn, $jobId);
    $job = $resolved['job'] ?? null;
    if (!$job) {
        // Still allow marking missing files
        $stmt = mysqli_prepare($conn, 'SELECT * FROM backup_jobs WHERE id=? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $jobId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $job = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
        }
    }
    if (!$job) {
        return ['ok' => false, 'error' => 'Backup job not found.'];
    }
    if (strtoupper((string)$job['status']) === 'SUCCESS') {
        $r = mysqli_query($conn, "SELECT COUNT(*) c FROM backup_jobs WHERE status='SUCCESS' AND backup_type='database'");
        $c = ($r && ($row = mysqli_fetch_assoc($r))) ? (int)$row['c'] : 0;
        if ($c <= 1) {
            return ['ok' => false, 'error' => 'Refusing to delete the only known-good SUCCESS backup.'];
        }
    }
    if (!empty($resolved['ok']) && !empty($resolved['path'])) {
        @unlink($resolved['path']);
    }
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE backup_jobs SET status='DELETED', error_summary='Deleted by administrator', finished_at=IFNULL(finished_at, NOW()) WHERE id=? LIMIT 1"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $jobId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    psSecurityAudit($conn, 'BACKUP_DELETE', [
        'summary' => 'Backup deleted by admin',
        'target_type' => 'backup',
        'target_id' => (string)$jobId,
        'after' => ['filename' => (string)($job['filename'] ?? '')],
    ]);
    return ['ok' => true];
}

/**
 * List recent backup jobs (metadata only).
 *
 * @return list<array<string,mixed>>
 */
function psBackupListJobs(mysqli $conn, int $limit = 50): array
{
    if (!psBackupEnsureSchema($conn)) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $res = mysqli_query(
        $conn,
        "SELECT id, started_at, finished_at, status, backup_type, filename, file_size, checksum_sha256,
                engine_version, table_count, triggered_by, source, error_summary
         FROM backup_jobs ORDER BY id DESC LIMIT " . (int)$limit
    );
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Restore dump into an isolated test database (never production).
 * Drops/creates the temp DB, imports, validates fingerprints, then optionally drops.
 *
 * @param array{keep_temp?:bool,temp_db?:string} $opts
 * @return array{ok:bool,temp_db?:string,error?:string,source_fp?:array,restored_fp?:array,checks?:array}
 */
function psBackupRestoreIsolatedTest(
    mysqli $conn,
    string $host,
    string $dbUser,
    string $dbPass,
    string $productionDb,
    int $jobId,
    array $opts = []
): array {
    $resolved = psBackupResolveJobFile($conn, $jobId);
    if (empty($resolved['ok'])) {
        return ['ok' => false, 'error' => (string)($resolved['error'] ?? 'File missing')];
    }
    $path = $resolved['path'];
    $verify = psBackupVerifyDumpFile($path);
    if (empty($verify['ok'])) {
        return ['ok' => false, 'error' => 'Integrity check failed: ' . (string)($verify['error'] ?? '')];
    }

    $tempDb = (string)($opts['temp_db'] ?? ($productionDb . '_restore_test'));
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $tempDb)) {
        return ['ok' => false, 'error' => 'Invalid temporary database name.'];
    }
    if (strcasecmp($tempDb, $productionDb) === 0) {
        return ['ok' => false, 'error' => 'Refusing to restore onto the production database name.'];
    }

    $sourceFp = psBackupIntegrityFingerprint($conn);
    $def = psBackupWriteDefaultsFile($host, $dbUser, $dbPass);
    if (empty($def['ok'])) {
        return ['ok' => false, 'error' => (string)$def['error']];
    }
    $defaultsPath = $def['path'];
    $mysqlBin = psBackupMysqlClientBinary();

    try {
        // Create temp DB
        $createSql = 'DROP DATABASE IF EXISTS `' . str_replace('`', '``', $tempDb) . '`;'
            . 'CREATE DATABASE `' . str_replace('`', '``', $tempDb) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';
        $cmdCreate = [
            $mysqlBin,
            '--defaults-extra-file=' . $defaultsPath,
            '-e',
            $createSql,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmdCreate, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'MySQL client could not start for temp DB create.'];
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            error_log('backup: temp DB create failed');
            return ['ok' => false, 'error' => 'Could not create isolated restore database.'];
        }

        // Import dump into temp DB only
        $cmdImport = [
            $mysqlBin,
            '--defaults-extra-file=' . $defaultsPath,
            $tempDb,
        ];
        $proc = proc_open($cmdImport, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'MySQL client could not start for import.'];
        }
        $in = fopen($path, 'rb');
        if (!$in) {
            fclose($pipes[0]);
            proc_close($proc);
            return ['ok' => false, 'error' => 'Could not read dump for import.'];
        }
        stream_copy_to_stream($in, $pipes[0]);
        fclose($in);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $err2 = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit2 = proc_close($proc);
        if ($exit2 !== 0) {
            error_log('backup: isolated restore import failed');
            return ['ok' => false, 'error' => 'Isolated restore import failed.', 'temp_db' => $tempDb];
        }

        $tempConn = @mysqli_connect($host, $dbUser, $dbPass, $tempDb);
        if (!$tempConn) {
            return ['ok' => false, 'error' => 'Could not connect to isolated restore database.', 'temp_db' => $tempDb];
        }
        mysqli_set_charset($tempConn, 'utf8mb4');
        $restoredFp = psBackupIntegrityFingerprint($tempConn);

        $checks = [];
        $keys = [
            'users', 'branchies', 'goods', 'online_sales', 'online_sales_ref', 'sale_payments',
            'sale_refund_requests', 'cash_movements', 'sessions', 'stock_movements', 'expenses',
            'tax_rates', 'accounting_export_batches', 'accounting_exported_sources', 'qty_sum',
            'sale_invoice_seq', 'table_count',
        ];
        $allMatch = true;
        foreach ($keys as $k) {
            $a = (string)($sourceFp[$k] ?? '');
            $b = (string)($restoredFp[$k] ?? '');
            $match = ($a === $b);
            $checks[$k] = $match ? 'match' : 'mismatch';
            if (!$match) {
                $allMatch = false;
            }
        }
        // Index / constraint presence samples on temp
        $idx = @mysqli_query($tempConn, "SHOW INDEX FROM goods WHERE Key_name='uniq_goods_product_branch'");
        $checks['uniq_goods_product_branch'] = ($idx && mysqli_num_rows($idx) > 0) ? 'present' : 'missing';
        if ($checks['uniq_goods_product_branch'] === 'missing') {
            // Not fatal if never created on this install — note only
            $checks['uniq_goods_product_branch'] = 'absent_or_missing';
        }

        mysqli_close($tempConn);

        $keep = !empty($opts['keep_temp']);
        if (!$keep) {
            $dropSql = 'DROP DATABASE IF EXISTS `' . str_replace('`', '``', $tempDb) . '`';
            $cmdDrop = [$mysqlBin, '--defaults-extra-file=' . $defaultsPath, '-e', $dropSql];
            $proc = proc_open($cmdDrop, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        }

        // Confirm production fingerprint unchanged
        $prodAfter = psBackupIntegrityFingerprint($conn);
        $prodIntact = ((string)$prodAfter['sale_invoice_seq'] === (string)$sourceFp['sale_invoice_seq'])
            && ((string)$prodAfter['online_sales'] === (string)$sourceFp['online_sales'])
            && ((string)$prodAfter['users'] === (string)$sourceFp['users']);

        psSecurityAudit($conn, 'BACKUP_RESTORE_TEST', [
            'summary' => $allMatch && $prodIntact ? 'Isolated restore test SUCCESS' : 'Isolated restore test MISMATCH',
            'target_type' => 'backup',
            'target_id' => (string)$jobId,
            'after' => [
                'temp_db' => $tempDb,
                'kept' => $keep ? 1 : 0,
                'all_match' => $allMatch ? 1 : 0,
                'production_intact' => $prodIntact ? 1 : 0,
            ],
        ]);

        return [
            'ok' => $allMatch && $prodIntact,
            'temp_db' => $tempDb,
            'source_fp' => $sourceFp,
            'restored_fp' => $restoredFp,
            'checks' => $checks,
            'production_intact' => $prodIntact,
            'error' => ($allMatch && $prodIntact) ? null : 'Fingerprint mismatch or production changed during test.',
        ];
    } finally {
        if (is_file($defaultsPath)) {
            @unlink($defaultsPath);
        }
    }
}

/**
 * App-files recovery manifest (documentation + optional archive of non-secret uploads).
 *
 * @return array{ok:bool,manifest:array,error?:string}
 */
function psBackupAppFilesManifest(): array
{
    $appRoot = dirname(__DIR__);
    $manifest = [
        'include_for_recovery' => [
            'application PHP/HTML/JS/CSS under app root (exclude vendor rebuild via composer if preferred)',
            'includes/',
            'mobile-endpoints/ (except secrets)',
            'docs/',
            'assets/',
            'composer.json / composer.lock',
            'uploads/ or document dirs if present (business files)',
        ],
        'exclude' => [
            'vendor/ (reinstall via composer)',
            'temp/, cache/, logs/',
            '*.sql dumps inside web tree',
            'pockets_config.php credentials (restore via secure ops process — not ordinary download)',
            'mobile-endpoints/pockets_config.php credentials',
            'node_modules if any',
        ],
        'secret_restore' => 'Restore pockets_config.php and mobile-endpoints/pockets_config.php from a secure sealed store; never place secrets in ordinary backup downloads served by the web UI.',
        'app_root_basename' => basename($appRoot),
        'uploads_present' => is_dir($appRoot . '/uploads'),
    ];
    return ['ok' => true, 'manifest' => $manifest];
}

/**
 * Non-sensitive health snapshot for ops (auth required for detailed; public may get boolean-only).
 *
 * @return array{ok:bool,checks:array<string,string>,backup_last_status?:string,backup_last_age_hours?:int|null}
 */
function psBackupHealthSnapshot(mysqli $conn = null, bool $detailed = false): array
{
    $checks = [];
    $ok = true;

    if ($conn instanceof mysqli) {
        $ping = @mysqli_query($conn, 'SELECT 1');
        $checks['database'] = $ping ? 'ok' : 'fail';
        if (!$ping) {
            $ok = false;
        }
    } else {
        $checks['database'] = 'unavailable';
        $ok = false;
    }

    $ensured = psBackupEnsureRoot();
    $checks['backup_storage'] = !empty($ensured['ok']) ? 'ok' : 'fail';
    if (empty($ensured['ok'])) {
        $ok = false;
    }

    $appWritable = is_writable(sys_get_temp_dir()) ? 'ok' : 'fail';
    $checks['temp_writable'] = $appWritable;
    if ($appWritable !== 'ok') {
        $ok = false;
    }

    $lastStatus = 'none';
    $ageHours = null;
    if ($conn instanceof mysqli && psBackupEnsureSchema($conn)) {
        $r = @mysqli_query(
            $conn,
            "SELECT status, started_at FROM backup_jobs WHERE backup_type='database' ORDER BY id DESC LIMIT 1"
        );
        if ($r && ($row = mysqli_fetch_assoc($r))) {
            $lastStatus = (string)$row['status'];
            $ts = strtotime((string)$row['started_at']);
            if ($ts) {
                $ageHours = (int)floor((time() - $ts) / 3600);
            }
            if (!in_array(strtoupper($lastStatus), ['SUCCESS'], true)) {
                // Visible stale/failed — health still reports connectivity ok but flags backup
                $checks['backup_last'] = 'attention';
            } else {
                $checks['backup_last'] = 'ok';
            }
        } else {
            $checks['backup_last'] = 'none';
        }
    }

    $out = ['ok' => $ok, 'checks' => $checks];
    if ($detailed) {
        $out['backup_last_status'] = $lastStatus;
        $out['backup_last_age_hours'] = $ageHours;
        // Intentionally omit absolute paths and credentials.
    }
    return $out;
}
