<?php
/**
 * Phase 18 — Create the first Super Admin (Pocket Solutions staff) from the command line.
 *
 *   php cli_create_super_admin.php --login=pocketsadmin --first=Kelvin --last=Moyo [--phone=077...]
 *
 * The password is asked for (not shown, not stored in shell history). Later Super Admins can be
 * added from the console page "Super Admins".
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/platform.php';

$o = getopt('', ['login:', 'first:', 'last:', 'phone::']);
if (empty($o['login']) || empty($o['first']) || empty($o['last'])) {
    fwrite(STDERR, "Usage: php cli_create_super_admin.php --login=<email or username> --first=<first name> --last=<last name> [--phone=<phone>]\n");
    exit(2);
}
$ask = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $tty = stripos(PHP_OS, 'WIN') !== 0 && function_exists('posix_isatty') && @posix_isatty(STDIN);
    if ($tty) {
        @shell_exec('stty -echo 2>/dev/null');
    }
    $v = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) {
        @shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");
    return $v;
};
$pw = $ask('Password (at least ' . PS_PASSWORD_MIN_LENGTH . ' characters): ');
if ($pw !== $ask('Repeat password: ')) {
    fwrite(STDERR, "The two passwords do not match.\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');
psPlatformEnsureSchema($conn);
$r = psPlatformCreateUser($conn, [
    'firstname' => (string)$o['first'],
    'lastname' => (string)$o['last'],
    'phonenumber' => (string)($o['phone'] ?? ''),
    'login_id' => (string)$o['login'],
    'password' => $pw,
    'level' => PS_PLATFORM_LEVEL,
    'company_name' => PS_PLATFORM_COMPANY,
    'branch' => '',
]);
if (empty($r['ok'])) {
    fwrite(STDERR, $r['error'] . "\n");
    exit(1);
}
psPlatformAudit($conn, 'SUPERADMIN_CREATE', null, 'command line', ['login' => $r['login_id'], 'actor' => 'cli']);
fwrite(STDOUT, 'Super Admin "' . $r['login_id'] . "\" created. Sign in at login.php; you will land on the Companies page.\n");
exit(0);
