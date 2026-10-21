<?php
/**
 * EXAMPLE ONLY — copy to a path outside the public webroot, e.g.:
 *   C:\xampp\pos-private\pockets_config.php
 * or set environment variables / PS_CONFIG_FILE.
 *
 * Do NOT commit real credentials. Do NOT place this filled file under htdocs.
 *
 * Supported forms:
 *   return [ ... ];   (preferred)
 *   or assign $servername / $username / $password / $dbname
 */

return [
    'servername' => 'localhost',
    'username' => 'YOUR_DB_USER',
    'password' => 'YOUR_DB_PASSWORD',
    'dbname' => 'YOUR_DB_NAME',
];

/*
Environment variable alternative (no file required):

  PS_DB_HOST=localhost
  PS_DB_USER=YOUR_DB_USER
  PS_DB_PASS=YOUR_DB_PASSWORD
  PS_DB_NAME=YOUR_DB_NAME

Optional:
  PS_CONFIG_FILE=C:\xampp\pos-private\pockets_config.php
  PS_BACKUP_ROOT=C:\xampp\pos-private\backups
*/
