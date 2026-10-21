<?php
/**
 * Pockets POS for Windows — releases for auto-update.
 * A Super Admin uploads a release zip (made by installer/build.ps1 in the desktop project) on
 * platform_desktop_releases.php. Tills ask api/v1/update.php for the newest active release above their
 * version and download it from api/v1/update_download.php (till token required). Files are stored in the
 * private root (outside the web tree); each one is checked against its SHA-256 by the till before use.
 */

const PS_DU_MAX_BYTES = 300 * 1024 * 1024;

function psDuEnsureSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $ok = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS desktop_releases (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(40) NOT NULL,
        filename VARCHAR(190) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        size_bytes BIGINT NOT NULL,
        notes TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        uploaded_by VARCHAR(190) NOT NULL,
        uploaded_at DATETIME NOT NULL,
        downloads INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_desktop_release_version (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = $ok;
    return $ok;
}

/** Private folder for release files. */
function psDuRoot(): string
{
    $base = function_exists('psConfigPrivateRoot') ? psConfigPrivateRoot() : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'pos-private';
    return $base . DIRECTORY_SEPARATOR . 'desktop-releases';
}

function psDuValidVersion(string $v): bool
{
    return (bool)preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,6}(\.\d{1,6})?$/', $v);
}

/** @return list<array<string,mixed>> newest version first */
function psDuList(mysqli $conn): array
{
    if (!psDuEnsureSchema($conn)) {
        return [];
    }
    $rows = [];
    $res = mysqli_query($conn, 'SELECT * FROM desktop_releases');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    usort($rows, static fn ($a, $b) => version_compare((string)$b['version'], (string)$a['version']));
    return $rows;
}

/** Newest active release above $current, or null. */
function psDuLatestFor(mysqli $conn, string $current): ?array
{
    foreach (psDuList($conn) as $r) {
        if ((int)$r['active'] !== 1) {
            continue;
        }
        if (!psDuValidVersion($current) || version_compare((string)$r['version'], $current, '>')) {
            return $r;
        }
        return null;
    }
    return null;
}

function psDuFind(mysqli $conn, string $version): ?array
{
    if (!psDuEnsureSchema($conn)) {
        return null;
    }
    $st = mysqli_prepare($conn, 'SELECT * FROM desktop_releases WHERE version = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 's', $version);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($st);
    return $row ?: null;
}

/**
 * Store an uploaded release zip.
 * @return array{ok:bool,error?:string,version?:string,sha256?:string}
 */
function psDuSave(mysqli $conn, array $file, string $version, string $notes, string $actor): array
{
    $version = trim($version);
    if (!psDuValidVersion($version)) {
        return ['ok' => false, 'error' => 'Version must look like 1.2.0.'];
    }
    if (psDuFind($conn, $version)) {
        return ['ok' => false, 'error' => 'Version ' . $version . ' is already uploaded. Use a higher version number.'];
    }
    if (!isset($file['tmp_name'], $file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        return ['ok' => false, 'error' => $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'The file is larger than the server allows (upload_max_filesize / post_max_size).'
            : 'Choose the release zip to upload.'];
    }
    $tmp = (string)$file['tmp_name'];
    $size = (int)filesize($tmp);
    if ($size <= 0 || $size > PS_DU_MAX_BYTES) {
        return ['ok' => false, 'error' => 'The file must be a zip up to 300 MB.'];
    }
    $fh = fopen($tmp, 'rb');
    $magic = $fh ? fread($fh, 4) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($magic !== "PK\x03\x04") {
        return ['ok' => false, 'error' => 'That is not a zip file.'];
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            return ['ok' => false, 'error' => 'The zip could not be opened.'];
        }
        $hasExe = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (strpos($name, '..') !== false || substr($name, 0, 1) === '/' || preg_match('/^[A-Za-z]:/', $name)) {
                $zip->close();
                return ['ok' => false, 'error' => 'The zip contains unsafe paths.'];
            }
            if (strcasecmp(basename($name), 'PocketsPOS.exe') === 0) {
                $hasExe = true;
            }
        }
        $zip->close();
        if (!$hasExe) {
            return ['ok' => false, 'error' => 'The zip does not contain PocketsPOS.exe — upload the PocketsPOS-x.y.z.zip made by build.ps1.'];
        }
    }
    $root = psDuRoot();
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
        return ['ok' => false, 'error' => 'The release folder could not be created on the server.'];
    }
    @file_put_contents($root . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\n");
    $filename = 'PocketsPOS-' . $version . '.zip';
    $dest = $root . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'The file could not be saved on the server.'];
    }
    $sha = hash_file('sha256', $dest);
    $now = date('Y-m-d H:i:s');
    $notes = mb_substr(trim($notes), 0, 4000);
    $st = mysqli_prepare($conn, 'INSERT INTO desktop_releases (version, filename, sha256, size_bytes, notes, active, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
    if (!$st) {
        @unlink($dest);
        return ['ok' => false, 'error' => 'The release could not be recorded.'];
    }
    mysqli_stmt_bind_param($st, 'sssisss', $version, $filename, $sha, $size, $notes, $actor, $now);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    if (!$ok) {
        @unlink($dest);
        return ['ok' => false, 'error' => 'The release could not be recorded.'];
    }
    return ['ok' => true, 'version' => $version, 'sha256' => $sha];
}

function psDuSetActive(mysqli $conn, string $version, bool $active): bool
{
    $a = $active ? 1 : 0;
    $st = mysqli_prepare($conn, 'UPDATE desktop_releases SET active = ? WHERE version = ?');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'is', $a, $version);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    return $ok;
}

function psDuDelete(mysqli $conn, string $version): bool
{
    $r = psDuFind($conn, $version);
    if (!$r) {
        return false;
    }
    $path = psDuRoot() . DIRECTORY_SEPARATOR . basename((string)$r['filename']);
    if (is_file($path)) {
        @unlink($path);
    }
    $st = mysqli_prepare($conn, 'DELETE FROM desktop_releases WHERE version = ?');
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 's', $version);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    return $ok;
}

/** Remember which version a till runs (shown on Desktop Tills). */
function psDuNoteTillVersion(mysqli $conn, int $tillId, string $version): void
{
    if ($tillId < 1 || !psDuValidVersion($version)) {
        return;
    }
    $st = mysqli_prepare($conn, 'UPDATE desktop_tills SET app_version = ? WHERE id = ?');
    if ($st) {
        mysqli_stmt_bind_param($st, 'si', $version, $tillId);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
}

function psDuCountDownload(mysqli $conn, string $version): void
{
    $st = mysqli_prepare($conn, 'UPDATE desktop_releases SET downloads = downloads + 1 WHERE version = ?');
    if ($st) {
        mysqli_stmt_bind_param($st, 's', $version);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
}
