<?php
/**
 * 2026-11-02 — Two companies may use the same branch name (e.g. both have "RUSAPE").
 *
 * Every record stores the branch by name, so the stored name must be unique across companies. When a company adds a
 * branch whose name another company already uses, the stored name (the "branch key") becomes NAME~COMPANY, e.g.
 * "RUSAPE~SKYTECH". People always see just "RUSAPE":
 *   - psBranchLabel()        turns a key into what people see,
 *   - the page output filter (psBranchLabelStartBuffer, started from pockets_config.php) swaps keys for labels in the
 *     visible text of every HTML page and in CSV downloads. Form values, links and scripts keep the key, so saving
 *     and filtering still use the unique key,
 *   - psBranchLabelHtml()    does the same for HTML handed to mPDF (PDF receipts, GRVs, quotations, reports).
 * The phone app and the Windows till cut the "~COMPANY" part off for display in the same way.
 */

if (!defined('PS_BRANCH_KEY_SEP')) {
    define('PS_BRANCH_KEY_SEP', '~');
}

/** What people see for a stored branch key: "RUSAPE~SKYTECH" → "RUSAPE". */
function psBranchLabel($key): string
{
    $key = trim((string)$key);
    $p = strpos($key, PS_BRANCH_KEY_SEP);
    return $p === false ? $key : rtrim(substr($key, 0, $p));
}

/**
 * The key to store for a new branch NAME of COMPANY: NAME itself when no other company uses it (case-insensitive),
 * else NAME~COMPANY. Returns ['ok'=>bool, 'key'=>string, 'error'=>string].
 */
function psBranchKeyFor(mysqli $conn, string $name, string $company): array
{
    $name = preg_replace('/\s+/', ' ', trim($name));
    $company = trim($company);
    if ($name === '' || $company === '') {
        return ['ok' => false, 'key' => '', 'error' => 'Enter a branch name.'];
    }
    if (strpos($name, PS_BRANCH_KEY_SEP) !== false) {
        return ['ok' => false, 'key' => '', 'error' => 'A branch name cannot contain "' . PS_BRANCH_KEY_SEP . '".'];
    }
    $taken = static function (string $key) use ($conn, $company): array {
        $st = mysqli_prepare($conn, 'SELECT company_name FROM branchies WHERE LOWER(TRIM(branch_name)) = LOWER(?)');
        mysqli_stmt_bind_param($st, 's', $key);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $out = ['mine' => false, 'other' => false];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            if (strcasecmp(trim((string)$r['company_name']), $company) === 0) {
                $out['mine'] = true;
            } else {
                $out['other'] = true;
            }
        }
        mysqli_stmt_close($st);
        return $out;
    };
    // The company already has a branch shown as NAME (plain or keyed)?
    $st = mysqli_prepare($conn, 'SELECT branch_name FROM branchies WHERE company_name = ?');
    mysqli_stmt_bind_param($st, 's', $company);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (strcasecmp(psBranchLabel($r['branch_name']), $name) === 0) {
            mysqli_stmt_close($st);
            return ['ok' => false, 'key' => '', 'error' => 'This company already has a branch called ' . $name . '.'];
        }
    }
    mysqli_stmt_close($st);
    $plain = $taken($name);
    if (!$plain['other']) {
        return ['ok' => true, 'key' => $name, 'error' => ''];
    }
    $key = $name . PS_BRANCH_KEY_SEP . $company;
    if (mb_strlen($key) > 250) {
        return ['ok' => false, 'key' => '', 'error' => 'That branch name is too long.'];
    }
    $k = $taken($key);
    if ($k['other'] || $k['mine']) {
        return ['ok' => false, 'key' => '', 'error' => 'That branch name is not available.'];
    }
    return ['ok' => true, 'key' => $key, 'error' => ''];
}

/** All stored keys that carry a company suffix (cached per request). */
function psBranchLabelKeys(?mysqli $conn = null): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $keys = [];
    $own = false;
    if ($conn === null) {
        if (!function_exists('psConfigResolve')) {
            return $keys;
        }
        $cfg = psConfigResolve();
        $conn = @mysqli_connect($cfg['servername'], $cfg['username'], $cfg['password'], $cfg['dbname']);
        if (!$conn) {
            return $keys;
        }
        mysqli_set_charset($conn, 'utf8mb4');
        $own = true;
    }
    $res = @mysqli_query($conn, "SELECT DISTINCT branch_name FROM branchies WHERE branch_name LIKE '%" . PS_BRANCH_KEY_SEP . "%'");
    while ($res && ($r = mysqli_fetch_row($res))) {
        $k = trim((string)$r[0]);
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    if ($own) {
        mysqli_close($conn);
    }
    // Longest first so "A~B C" is replaced before "A~B".
    usort($keys, static fn($a, $b) => strlen($b) <=> strlen($a));
    return $keys;
}

/** Replace branch keys by labels in the visible text of an HTML document (not in tags, scripts, styles or textareas). */
function psBranchLabelHtml(string $html, ?mysqli $conn = null): string
{
    if (strpos($html, PS_BRANCH_KEY_SEP) === false) {
        return $html;
    }
    $keys = psBranchLabelKeys($conn);
    if (!$keys) {
        return $html;
    }
    $map = [];
    foreach ($keys as $k) {
        $map[htmlspecialchars($k, ENT_QUOTES, 'UTF-8')] = htmlspecialchars(psBranchLabel($k), ENT_QUOTES, 'UTF-8');
        $map[htmlspecialchars($k, ENT_NOQUOTES, 'UTF-8')] = htmlspecialchars(psBranchLabel($k), ENT_NOQUOTES, 'UTF-8');
        $map[$k] = htmlspecialchars(psBranchLabel($k), ENT_NOQUOTES, 'UTF-8');
    }
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    $skip = '';
    foreach ($parts as $i => $part) {
        if ($part === '') {
            continue;
        }
        if ($part[0] === '<') {
            if ($skip !== '') {
                if (preg_match('#^</' . $skip . '\b#i', $part)) {
                    $skip = '';
                }
            } elseif (preg_match('#^<(script|style|textarea)\b#i', $part, $m) && substr($part, -2) !== '/>') {
                $skip = strtolower($m[1]);
            } elseif (preg_match('/\b(title|placeholder|aria-label|alt)\s*=/i', $part)) {
                // Tooltips and labels are visible too; values, names, hrefs and data-* keep the key.
                $parts[$i] = preg_replace_callback('/\b(title|placeholder|aria-label|alt)(\s*=\s*)(["\'])(.*?)\3/is',
                    static fn($mm) => $mm[1] . $mm[2] . $mm[3] . strtr($mm[4], $map) . $mm[3], $part);
            }
            continue;
        }
        if ($skip === '') {
            $parts[$i] = strtr($part, $map);
        }
    }
    return implode('', $parts);
}

/** Plain text (CSV, printed receipt text): every key becomes its label. */
function psBranchLabelText(string $text, ?mysqli $conn = null): string
{
    if (strpos($text, PS_BRANCH_KEY_SEP) === false) {
        return $text;
    }
    $map = [];
    foreach (psBranchLabelKeys($conn) as $k) {
        $map[$k] = psBranchLabel($k);
    }
    return $map ? strtr($text, $map) : $text;
}

/** Output filter for web pages (HTML and CSV only; JSON, PDF, files and other types pass through untouched). */
function psBranchLabelOutput(string $buffer, int $phase): string
{
    if ($buffer === '' || strpos($buffer, PS_BRANCH_KEY_SEP) === false) {
        return $buffer;
    }
    $type = 'text/html';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $type = strtolower(trim(substr($h, 13)));
        }
    }
    try {
        if (strpos($type, 'text/html') === 0) {
            return psBranchLabelHtml($buffer);
        }
        if (strpos($type, 'text/csv') === 0) {
            return psBranchLabelText($buffer);
        }
    } catch (Throwable $e) {
        error_log('branch label filter: ' . $e->getMessage());
    }
    return $buffer;
}

function psBranchLabelStartBuffer(): void
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli') {
        return;
    }
    $started = true;
    ob_start('psBranchLabelOutput');
}
