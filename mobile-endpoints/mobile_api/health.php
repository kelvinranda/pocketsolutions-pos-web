<?php
/**
 * Mobile API reachability check (Phase 16 hardened).
 * Unauthenticated: boolean connectivity only — no database name, table maps, or row counts.
 */
require __DIR__ . '/_bootstrap.php';

$ok = isset($conn) && $conn instanceof mysqli;

respond([
    'success' => $ok,
    'ok' => $ok,
    'message' => $ok ? 'Mobile API is reachable' : 'Mobile API unavailable',
]);
