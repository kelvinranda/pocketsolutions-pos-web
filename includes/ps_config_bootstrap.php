<?php
/**
 * Phase 16 — DB config loader.
 *
 * Preference order (first complete set wins):
 * 1. Environment: PS_DB_HOST, PS_DB_USER, PS_DB_PASS, PS_DB_NAME
 * 2. External PHP file: PS_CONFIG_FILE, or C:\xampp\pos-private\pockets_config.php /
 *    dirname(webroot)/../pos-private/pockets_config.php
 * 3. Optional local non-example override at project root pockets_config.local.php (gitignored pattern)
 *
 * Never echo credentials, paths containing secrets, or connection strings to clients.
 * display_errors is forced off when this bootstrap runs.
 */

if (!defined('PS_CONFIG_BOOTSTRAP_LOADED')) {
    define('PS_CONFIG_BOOTSTRAP_LOADED', 1);
}

function psConfigApplyDisplayHardening(): void
{
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    psConfigApplyPrivateErrorLog();
    psConfigApplyTimezone();
}

/**
 * Private root outside the public webroot (same convention as pockets_config.php / backups):
 * PS_PRIVATE_ROOT env, else C:\xampp\pos-private on XAMPP, else <parent of webroot>/pos-private.
 */
function psConfigPrivateRoot(): string
{
    $env = getenv('PS_PRIVATE_ROOT');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '\\/');
    }
    $webroot = dirname(__DIR__);
    $candidates = [
        'C:\\xampp\\pos-private',
        dirname($webroot) . DIRECTORY_SEPARATOR . 'pos-private',
        dirname(dirname($webroot)) . DIRECTORY_SEPARATOR . 'pos-private',
    ];
    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }
    return dirname($webroot) . DIRECTORY_SEPARATOR . 'pos-private';
}

/**
 * Health-check fix (2026-10-13): PHP's default error_log on cPanel is a file named
 * "error_log" inside the webroot, which was publicly downloadable. Send it to the private root.
 */
function psConfigApplyPrivateErrorLog(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $dir = psConfigPrivateRoot() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @ini_set('error_log', $dir . DIRECTORY_SEPARATOR . 'php_error.log');
    }
}

/**
 * One business timezone for every page (sales, tills, reports). Override with PS_TIMEZONE
 * or 'timezone' in the private config file.
 */
function psConfigApplyTimezone(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $tz = psConfigValue('timezone', 'Africa/Harare');
    if (!@date_default_timezone_set($tz)) {
        date_default_timezone_set('Africa/Harare');
    }
}

/**
 * Optional non-DB setting: env PS_<KEY> first, then the private config file's returned array.
 */
function psConfigValue(string $key, string $default = ''): string
{
    $env = getenv('PS_' . strtoupper($key));
    if (is_string($env) && $env !== '') {
        return $env;
    }
    psConfigResolve();
    $extra = $GLOBALS['__ps_config_extra'] ?? null;
    if (is_array($extra) && array_key_exists($key, $extra) && is_scalar($extra[$key])) {
        return (string)$extra[$key];
    }
    return $default;
}

/**
 * @return list<string>
 */
function psConfigCandidatePaths(): array
{
    $paths = [];
    $env = getenv('PS_CONFIG_FILE');
    if (is_string($env) && trim($env) !== '') {
        $paths[] = trim($env);
    }
    // Documented XAMPP private root (outside htdocs).
    $paths[] = 'C:\\xampp\\pos-private\\pockets_config.php';
    $webroot = dirname(__DIR__);
    $paths[] = dirname($webroot) . DIRECTORY_SEPARATOR . 'pos-private' . DIRECTORY_SEPARATOR . 'pockets_config.php';
    $paths[] = dirname(dirname($webroot)) . DIRECTORY_SEPARATOR . 'pos-private' . DIRECTORY_SEPARATOR . 'pockets_config.php';
    $paths[] = $webroot . DIRECTORY_SEPARATOR . 'pockets_config.local.php';
    return $paths;
}

/**
 * @param mixed $data
 * @return array{servername?:string,username?:string,password?:string,dbname?:string}|null
 */
function psConfigNormalizeArray($data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    $map = [
        'servername' => ['servername', 'host', 'db_host', 'PS_DB_HOST'],
        'username' => ['username', 'user', 'db_user', 'PS_DB_USER'],
        'password' => ['password', 'pass', 'db_pass', 'PS_DB_PASS'],
        'dbname' => ['dbname', 'database', 'db_name', 'PS_DB_NAME'],
    ];
    $out = [];
    foreach ($map as $canon => $aliases) {
        foreach ($aliases as $a) {
            if (array_key_exists($a, $data) && is_scalar($data[$a])) {
                $out[$canon] = (string)$data[$a];
                break;
            }
        }
    }
    return $out;
}

/**
 * @return array{servername:string,username:string,password:string,dbname:string}|null
 */
function psConfigFromEnv(): ?array
{
    $host = getenv('PS_DB_HOST');
    $user = getenv('PS_DB_USER');
    $pass = getenv('PS_DB_PASS');
    $name = getenv('PS_DB_NAME');
    if ($host === false && $user === false && $pass === false && $name === false) {
        return null;
    }
    $host = is_string($host) ? $host : '';
    $user = is_string($user) ? $user : '';
    $pass = is_string($pass) ? $pass : '';
    $name = is_string($name) ? $name : '';
    if ($host === '' || $user === '' || $name === '') {
        return null;
    }
    return [
        'servername' => $host,
        'username' => $user,
        'password' => $pass,
        'dbname' => $name,
    ];
}

/**
 * Load a config file that either returns an array or assigns $servername/… globals.
 *
 * @return array{servername:string,username:string,password:string,dbname:string}|null
 */
function psConfigFromFile(string $path): ?array
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $servername = null;
    $username = null;
    $password = null;
    $dbname = null;
    /** @var mixed $returned */
    $returned = include $path;
    if (is_array($returned)) {
        $GLOBALS['__ps_config_extra'] = $returned;
    }
    $fromReturn = psConfigNormalizeArray($returned);
    if ($fromReturn !== null
        && isset($fromReturn['servername'], $fromReturn['username'], $fromReturn['dbname'])
        && $fromReturn['servername'] !== ''
        && $fromReturn['username'] !== ''
        && $fromReturn['dbname'] !== ''
    ) {
        if (!array_key_exists('password', $fromReturn)) {
            $fromReturn['password'] = '';
        }
        return [
            'servername' => (string)$fromReturn['servername'],
            'username' => (string)$fromReturn['username'],
            'password' => (string)$fromReturn['password'],
            'dbname' => (string)$fromReturn['dbname'],
        ];
    }
    if (is_string($servername) && $servername !== ''
        && is_string($username) && $username !== ''
        && is_string($dbname) && $dbname !== ''
    ) {
        return [
            'servername' => $servername,
            'username' => $username,
            'password' => is_string($password) ? $password : '',
            'dbname' => $dbname,
        ];
    }
    return null;
}

/**
 * @return array{servername:string,username:string,password:string,dbname:string,source:string}
 */
function psConfigResolve(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $fromEnv = psConfigFromEnv();
    if ($fromEnv !== null) {
        $cached = $fromEnv + ['source' => 'env'];
        return $cached;
    }

    foreach (psConfigCandidatePaths() as $path) {
        $cfg = psConfigFromFile($path);
        if ($cfg !== null) {
            $cached = $cfg + ['source' => 'file'];
            return $cached;
        }
    }

    error_log('ps_config: no DB credentials found (set PS_DB_* or private pockets_config.php)');
    $cached = [
        'servername' => '',
        'username' => '',
        'password' => '',
        'dbname' => '',
        'source' => 'missing',
    ];
    return $cached;
}

/**
 * Populate classic globals expected by legacy requires of pockets_config.php.
 */
function psConfigBootstrapGlobals(): void
{
    psConfigApplyDisplayHardening();
    $cfg = psConfigResolve();
    $GLOBALS['servername'] = $cfg['servername'];
    $GLOBALS['username'] = $cfg['username'];
    $GLOBALS['password'] = $cfg['password'];
    $GLOBALS['dbname'] = $cfg['dbname'];
    // Also assign in caller scope when included via require from pockets_config.php
    $servername = $cfg['servername'];
    $username = $cfg['username'];
    $password = $cfg['password'];
    $dbname = $cfg['dbname'];
}

/**
 * Controlled server-side log line — never attach credentials/tokens/PII blobs.
 */
function psConfigSafeErrorLog(string $channel, string $message): void
{
    $channel = preg_replace('/[^a-z0-9._-]/i', '', $channel) ?: 'app';
    $message = str_replace(["\r", "\n"], ' ', $message);
    if (strlen($message) > 500) {
        $message = substr($message, 0, 500) . '…';
    }
    error_log($channel . ': ' . $message);
}
