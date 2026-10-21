<?php
/**
 * Phase 14 — Debug auth endpoints are disabled in application code.
 * Deployment may re-enable temporarily under controlled admin access only.
 */
require __DIR__ . '/_bootstrap.php';
respond(['success' => false, 'error' => 'Not available'], 404);
