<?php
/** GET ?version=1.2.0 — the release zip (till token required). The till checks it against the SHA-256 from update.php. */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_updates.php';
apiRequireMethod('GET');
apiTill($conn);
$version = trim((string)($_GET['version'] ?? ''));
$r = psDuValidVersion($version) ? psDuFind($conn, $version) : null;
if (!$r || (int)$r['active'] !== 1) {
    apiFail('That release is not available.', 404, 'not_found');
}
$path = psDuRoot() . DIRECTORY_SEPARATOR . basename((string)$r['filename']);
if (!is_file($path)) {
    apiFail('The release file is missing on the server.', 404, 'not_found');
}
psDuCountDownload($conn, $version);
mysqli_close($conn);
header_remove('Content-Type');
header('Content-Type: application/zip');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('X-Content-SHA256: ' . (string)$r['sha256']);
header('X-Content-Type-Options: nosniff');
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
exit;
