<?php
/**
 * Public webroot bootstrap for DB settings (Phase 16).
 *
 * Credentials are NOT stored in this file. They load from (in order):
 *   1) PS_DB_HOST / PS_DB_USER / PS_DB_PASS / PS_DB_NAME
 *   2) PS_CONFIG_FILE or C:\xampp\pos-private\pockets_config.php
 *   3) pockets_config.local.php (optional local override outside VCS)
 *
 * See config/pockets_config.example.php for placeholders.
 * Apache/.htaccess should deny HTTP download of this and related config files.
 */

require_once __DIR__ . '/includes/ps_config_bootstrap.php';

psConfigApplyDisplayHardening();

$cfg = psConfigResolve();
$servername = $cfg['servername'];
$username = $cfg['username'];
$password = $cfg['password'];
$dbname = $cfg['dbname'];

// Health-check 2026-10-13: one login / session-timeout / role floor for every web page.
require_once __DIR__ . '/includes/page_access.php';
psPageAccessEnforce();
