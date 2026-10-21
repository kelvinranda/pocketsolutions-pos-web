<?php
/**
 * GET ?current=1.2.0 — the newest active Pockets POS release above the till's version (or "update": null).
 * Also records the version this till runs (Desktop Tills shows it).
 */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_updates.php';
apiRequireMethod('GET');
$till = apiTill($conn);
$current = trim((string)($_GET['current'] ?? ''));
psDuNoteTillVersion($conn, (int)$till['id'], $current);
$r = psDuLatestFor($conn, $current);
if (!$r) {
    apiRespond(['success' => true, 'update' => null]);
}
apiRespond(['success' => true, 'update' => [
    'version' => (string)$r['version'],
    'sha256' => (string)$r['sha256'],
    'size' => (int)$r['size_bytes'],
    'notes' => (string)($r['notes'] ?? ''),
    'published_at' => (string)$r['uploaded_at'],
    'download' => 'update_download.php?version=' . rawurlencode((string)$r['version']),
]]);
