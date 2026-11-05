<?php
/**
 * 2026-10-21 — Currency-aware reporting: one place for how money is shown, totalled, filtered and exported in reports.
 *
 * Rules (docs/MULTI_CURRENCY.md §7):
 *  - Every amount is kept with its own currency. Totals are made per currency first ("mix" below).
 *  - The consolidated figure is in the company BASE currency and uses each transaction's STORED base amount / rate
 *    (snapshot taken when it was posted). Today's rates are never used for history; nothing is re-valued.
 *  - Legacy rows (made before currencies existed, currency NULL) are counted in the legacy currency at 1:1 and are
 *    flagged as legacy — their currency is assumed, not recorded.
 *  - A non-base row with no stored base amount / rate is "missing": it stays in its own currency total, is left OUT of
 *    the consolidated total, and the total is flagged incomplete. It is never treated as zero silently.
 *  - Two views: Consolidated (base, converted amounts marked) and By currency (original currencies, no conversion).
 *  - The marker never changes an amount; it only says a conversion happened. Details are in an accessible tooltip
 *    (hover, keyboard focus, click / tap) and in CSV / PDF columns, so colour is never the only signal.
 *
 * A "mix" is array<string code, part> — part = [code, n, legacy_n, txn (cents), base (cents, resolvable rows only),
 * missing_n, missing_txn (cents), rates (rate => true), ids (ids => true), refs (list), at (list)].
 */

require_once __DIR__ . '/currency.php';

const PS_FXR_CONSOLIDATED = 'consolidated';
const PS_FXR_BREAKDOWN = 'breakdown';

// ------------------------------------------------------------------ context, view and filter

/**
 * Report context: base currency, view (consolidated / breakdown), optional currency filter, enabled currencies.
 * GET parameters: fx_view=breakdown, fx_cur=ZWG.
 * @return array{company:string,base:string,view:string,cur:string,currencies:array<string,array>,multi:bool}
 */
function psFxrContext(mysqli $conn, ?array $get = null, string $company = ''): array
{
    $get = $get ?? $_GET;
    $company = $company !== '' ? $company : psFxSessionCompany();
    psFxEnsureSchema($conn);
    psFxEnsureTransactionColumns($conn);
    $base = psFxBase($conn, $company);
    $currencies = psFxCompanyCurrencies($conn, $company);
    $all = psFxCurrencies($conn);
    $view = (string)($get['fx_view'] ?? '') === PS_FXR_BREAKDOWN ? PS_FXR_BREAKDOWN : PS_FXR_CONSOLIDATED;
    $cur = psFxNormalizeCode($get['fx_cur'] ?? '');
    if ($cur !== '' && !isset($currencies[$cur]) && !isset($all[$cur]) && $cur !== psFxLegacyCurrency()) {
        $cur = '';
    }
    // A currency that has history but is no longer enabled must still be selectable for old periods.
    foreach (psFxrUsedCurrencies($conn) as $code) {
        if (!isset($currencies[$code])) {
            $currencies[$code] = $all[$code] ?? ['code' => $code, 'name' => $code, 'symbol' => '', 'decimals' => 2, 'symbol_position' => 'before'];
        }
    }
    return [
        'company' => $company,
        'base' => $base,
        'view' => $view,
        'cur' => $cur,
        'currencies' => $currencies,
        'multi' => count($currencies) > 1 || $cur !== '' || $view === PS_FXR_BREAKDOWN,
    ];
}

/** Currencies that appear on sales (cheap: distinct on an indexed-ish column, cached per request). @return list<string> */
function psFxrUsedCurrencies(mysqli $conn): array
{
    static $codes = null;
    if ($codes !== null) {
        return $codes;
    }
    $codes = [];
    if (!psFxHasColumn($conn, 'online_sales_ref', 'currency')) {
        return $codes;
    }
    $res = @mysqli_query($conn, 'SELECT DISTINCT currency FROM online_sales_ref WHERE currency IS NOT NULL');
    while ($res && ($r = mysqli_fetch_row($res))) {
        $c = psFxNormalizeCode($r[0]);
        if ($c !== '') {
            $codes[] = $c;
        }
    }
    return $codes;
}

/** The GET parameters that keep the current view / filter (for links and forms). */
function psFxrQuery(array $ctx): array
{
    $q = [];
    if ($ctx['view'] === PS_FXR_BREAKDOWN) {
        $q['fx_view'] = PS_FXR_BREAKDOWN;
    }
    if ($ctx['cur'] !== '') {
        $q['fx_cur'] = $ctx['cur'];
    }
    return $q;
}

/**
 * SQL condition for the currency filter on a currency column (NULL = legacy currency). '' when no filter.
 * The code is validated to [A-Z]{3} by psFxNormalizeCode, so it is safe to inline.
 */
function psFxrCurFilterSql(mysqli $conn, array $ctx, string $curCol): string
{
    if ($ctx['cur'] === '') {
        return '';
    }
    $leg = mysqli_real_escape_string($conn, psFxLegacyCurrency());
    $code = mysqli_real_escape_string($conn, $ctx['cur']);
    return " AND COALESCE($curCol, '$leg') = '$code'";
}

/** Same filter for rows already loaded in PHP. */
function psFxrRowMatches(array $ctx, ?string $cur): bool
{
    if ($ctx['cur'] === '') {
        return true;
    }
    $code = psFxNormalizeCode($cur ?? '') ?: psFxLegacyCurrency();
    return $code === $ctx['cur'];
}

// ------------------------------------------------------------------ aggregation SQL

/**
 * Base amount of one row as SQL: legacy (currency NULL) and base-currency rows count at face value; other rows use
 * their stored base amount; NULL when a non-base row has no stored base amount (missing rate).
 */
function psFxrBaseExprSql(mysqli $conn, array $ctx, string $curCol, string $amtExpr, ?string $baseExpr): string
{
    $base = mysqli_real_escape_string($conn, $ctx['base']);
    $stored = $baseExpr !== null ? "WHEN $baseExpr IS NOT NULL THEN $baseExpr " : '';
    return "(CASE WHEN $curCol IS NULL THEN $amtExpr WHEN $curCol = '$base' THEN $amtExpr {$stored}ELSE NULL END)";
}

/**
 * SELECT list for "per currency" aggregation: GROUP BY the returned fx_cur expression (plus your own group columns).
 * $baseExpr: stored base amount column / expression, or null when only a rate is stored (pass $rateCol instead).
 * $rateCol: stored rate (1 BASE = rate TXN) used when there is no stored base amount (e.g. sale lines, payments).
 */
function psFxrAggSelectSql(mysqli $conn, array $ctx, string $curCol, string $amtExpr, ?string $baseExpr, ?string $rateCol = null, string $prefix = 'fx_', ?string $countExpr = null): string
{
    if ($baseExpr === null && $rateCol !== null) {
        $baseExpr = "ROUND($amtExpr / NULLIF($rateCol, 0), 2)";
    }
    $b = psFxrBaseExprSql($conn, $ctx, $curCol, $amtExpr, $baseExpr);
    $p = $prefix;
    $rateSel = $rateCol !== null ? ", MIN(CASE WHEN $curCol IS NOT NULL THEN $rateCol END) AS {$p}rate_min, MAX(CASE WHEN $curCol IS NOT NULL THEN $rateCol END) AS {$p}rate_max" : '';
    $cnt = $countExpr ?? 'COUNT(*)';
    $curSel = $prefix === 'fx_' ? "$curCol AS fx_cur, " : '';
    return "{$curSel}$cnt AS {$p}n, SUM($curCol IS NULL) AS {$p}legacy_n,
            IFNULL(SUM($amtExpr), 0) AS {$p}amt, IFNULL(SUM($b), 0) AS {$p}base,
            SUM($b IS NULL) AS {$p}missing_n, IFNULL(SUM(CASE WHEN $b IS NULL THEN $amtExpr ELSE 0 END), 0) AS {$p}missing_amt$rateSel";
}

// ------------------------------------------------------------------ money helpers (integer cents)

/** "12.34" / 12.34 / "-0.5" → cents (exact for DECIMAL(…,2) strings). */
function psFxrCents($v): int
{
    if ($v === null || $v === '') {
        return 0;
    }
    if (is_int($v)) {
        return $v * 100;
    }
    $s = trim((string)$v);
    if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $s, $m)) {
        $frac = substr(($m[3] ?? '') . '000', 0, 3);
        $c = (int)($m[2] === '' ? '0' : $m[2]) * 100 + (int)substr($frac, 0, 2) + ((int)$frac[2] >= 5 ? 1 : 0);
        return $m[1] === '-' ? -$c : $c;
    }
    return (int)round((float)$s * 100);
}

function psFxrMoney(int $cents): string
{
    $neg = $cents < 0;
    $a = abs($cents);
    return ($neg ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT);
}

// ------------------------------------------------------------------ mix (per-currency totals)

/**
 * Add one transaction (or a pre-summed group) to a mix.
 * $cur: transaction currency (null / '' = legacy); $txn: amount in it; $base: stored base amount (null = none stored).
 * $meta: rate (1 BASE = rate TXN), ids (exchange_rates ids), ref, at, n (rows represented, default 1).
 */
function psFxrAdd(array &$mix, array $ctx, ?string $cur, $txn, $base, array $meta = []): void
{
    $code = psFxNormalizeCode($cur ?? '');
    $legacy = $code === '';
    if ($legacy) {
        $code = psFxLegacyCurrency();
    }
    $n = (int)($meta['n'] ?? 1);
    $txnC = psFxrCents($txn);
    $p = $mix[$code] ?? ['code' => $code, 'n' => 0, 'legacy_n' => 0, 'txn' => 0, 'base' => 0, 'missing_n' => 0, 'missing_txn' => 0,
        'rates' => [], 'ids' => [], 'refs' => [], 'at' => []];
    $p['n'] += $n;
    $p['txn'] += $txnC;
    if ($legacy) {
        $p['legacy_n'] += $n;
    }
    if ($legacy || $code === $ctx['base']) {
        $p['base'] += $txnC;
    } elseif ($base !== null && $base !== '') {
        $p['base'] += psFxrCents($base);
    } else {
        $p['missing_n'] += $n;
        $p['missing_txn'] += $txnC;
    }
    $rate = trim((string)($meta['rate'] ?? ''));
    if ($rate !== '' && !$legacy && $code !== $ctx['base'] && count($p['rates']) < 4) {
        $p['rates'][psFxrRateText($rate)] = true;
    }
    $ids = trim((string)($meta['ids'] ?? ''));
    if ($ids !== '' && count($p['ids']) < 4) {
        $p['ids'][$ids] = true;
    }
    if (($meta['ref'] ?? '') !== '' && count($p['refs']) < 3) {
        $p['refs'][] = (string)$meta['ref'];
    }
    if (($meta['at'] ?? '') !== '' && count($p['at']) < 1) {
        $p['at'][] = (string)$meta['at'];
    }
    $mix[$code] = $p;
}

/** Add an aggregate row produced with psFxrAggSelectSql(). */
function psFxrAddAgg(array &$mix, array $ctx, array $row, string $prefix = 'fx_'): void
{
    $code = psFxNormalizeCode($row['fx_cur'] ?? '');
    if ($prefix !== 'fx_') {
        $row = ['fx_cur' => $row['fx_cur'] ?? null] + array_combine(
            array_map(fn($k) => 'fx_' . substr($k, strlen($prefix)), array_keys(array_filter($row, fn($k) => strpos($k, $prefix) === 0, ARRAY_FILTER_USE_KEY))),
            array_values(array_filter($row, fn($k) => strpos($k, $prefix) === 0, ARRAY_FILTER_USE_KEY))
        );
    }
    $n = (int)($row['fx_n'] ?? 0);
    if ($n === 0) {
        return;
    }
    $legacyN = min($n, (int)($row['fx_legacy_n'] ?? 0));
    $key = $code === '' ? psFxLegacyCurrency() : $code;
    $p = $mix[$key] ?? ['code' => $key, 'n' => 0, 'legacy_n' => 0, 'txn' => 0, 'base' => 0, 'missing_n' => 0, 'missing_txn' => 0,
        'rates' => [], 'ids' => [], 'refs' => [], 'at' => []];
    $p['n'] += $n;
    $p['legacy_n'] += $legacyN;
    $p['txn'] += psFxrCents($row['fx_amt'] ?? 0);
    $p['base'] += psFxrCents($row['fx_base'] ?? 0);
    $p['missing_n'] += (int)($row['fx_missing_n'] ?? 0);
    $p['missing_txn'] += psFxrCents($row['fx_missing_amt'] ?? 0);
    foreach (['fx_rate_min', 'fx_rate_max'] as $k) {
        $r = trim((string)($row[$k] ?? ''));
        if ($r !== '' && $key !== $ctx['base'] && count($p['rates']) < 4) {
            $p['rates'][psFxrRateText($r)] = true;
        }
    }
    $mix[$key] = $p;
}

/** Merge mix $b into $a. */
function psFxrMerge(array &$a, array $b): void
{
    foreach ($b as $code => $p) {
        if (!isset($a[$code])) {
            $a[$code] = $p;
            continue;
        }
        foreach (['n', 'legacy_n', 'txn', 'base', 'missing_n', 'missing_txn'] as $k) {
            $a[$code][$k] += $p[$k];
        }
        $a[$code]['rates'] += array_slice($p['rates'], 0, 4, true);
        $a[$code]['ids'] += array_slice($p['ids'], 0, 4, true);
    }
}

/** Mix with signs flipped (e.g. refunds deducted from sales). */
function psFxrNegate(array $mix): array
{
    foreach ($mix as $code => $p) {
        foreach (['txn', 'base', 'missing_txn'] as $k) {
            $mix[$code][$k] = -$p[$k];
        }
    }
    return $mix;
}

/** Consolidated base cents (resolvable rows only). */
function psFxrBase(array $mix): int
{
    return array_sum(array_map(fn($p) => $p['base'], $mix));
}

function psFxrIncomplete(array $mix): bool
{
    foreach ($mix as $p) {
        if ($p['missing_n'] > 0) {
            return true;
        }
    }
    return false;
}

function psFxrHasConverted(array $mix, array $ctx): bool
{
    foreach ($mix as $code => $p) {
        if ($code !== $ctx['base'] && $p['n'] > $p['legacy_n']) {
            return true;
        }
    }
    return false;
}

/** Codes ordered: base first, then alphabetical. */
function psFxrOrder(array $mix, array $ctx): array
{
    $codes = array_keys($mix);
    usort($codes, fn($a, $b) => ($a === $ctx['base'] ? -1 : ($b === $ctx['base'] ? 1 : strcmp($a, $b))));
    return $codes;
}

// ------------------------------------------------------------------ colours (central; never per screen)

/** Palette slot 1–6 for a currency: well-known codes fixed, others stable by code. The base is never coloured. */
function psFxrSlot(string $code): int
{
    $fixed = ['ZWG' => 1, 'ZAR' => 2, 'USD' => 3, 'BWP' => 4, 'EUR' => 5, 'GBP' => 6, 'ZWL' => 1, 'ZMW' => 4, 'MZN' => 6, 'CNY' => 5];
    return $fixed[$code] ?? (int)(sprintf('%u', crc32($code)) % 6) + 1;
}

function psFxrStyle(string $code): string
{
    return '--fxc:var(--fx-c' . psFxrSlot($code) . ')';
}

// ------------------------------------------------------------------ text

function psFxrRateText(string $rate): string
{
    $r = trim($rate);
    if ($r === '' || !is_numeric($r)) {
        return '';
    }
    // Same look as psFxRateLabel: trailing zeros dropped, at least 2 decimals ("26.50", "0.0377358491").
    $r = strpos($r, '.') !== false ? rtrim(rtrim($r, '0'), '.') : $r;
    $dec = strpos($r, '.') !== false ? strlen(substr(strrchr($r, '.'), 1)) : 0;
    return $dec >= 2 ? $r : ($dec === 1 ? $r . '0' : $r . '.00');
}

/** Effective date of exchange-rate rows (one query per request). */
function psFxrRateFrom(mysqli $conn, string $ids): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $res = @mysqli_query($conn, 'SELECT id, effective_from FROM exchange_rates');
        while ($res && ($r = mysqli_fetch_row($res))) {
            $map[(int)$r[0]] = substr((string)$r[1], 0, 16);
        }
    }
    $out = [];
    foreach (preg_split('/[^0-9]+/', $ids) as $id) {
        if ($id !== '' && isset($map[(int)$id])) {
            $out[] = $map[(int)$id];
        }
    }
    return implode(' / ', array_unique($out));
}

function psFxrH($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function psFxrFmt(mysqli $conn, int $cents, string $code): string
{
    return psFxFormat($conn, psFxrMoney($cents), $code);
}

/** One line per currency, plain text (tooltips, CSV, PDF). */
function psFxrDescribe(mysqli $conn, array $ctx, array $mix): array
{
    $lines = [];
    foreach (psFxrOrder($mix, $ctx) as $code) {
        $p = $mix[$code];
        $count = $p['n'] === 1 ? '1 transaction' : $p['n'] . ' transactions';
        if ($code === $ctx['base'] && $p['legacy_n'] === 0) {
            $lines[] = psFxrFmt($conn, $p['txn'], $code) . " ($count, no conversion)";
            continue;
        }
        $line = psFxrFmt($conn, $p['txn'], $code);
        $resolved = $p['txn'] - $p['missing_txn'];
        if ($code !== $ctx['base']) {
            if ($p['missing_n'] >= $p['n']) {
                $line .= ' → not converted (no stored rate)';
            } else {
                $line .= ' → ' . psFxrFmt($conn, $p['base'], $ctx['base']);
                if ($p['missing_n'] > 0) {
                    $line .= ' (for ' . psFxrFmt($conn, $resolved, $code) . '; ' . psFxrFmt($conn, $p['missing_txn'], $code) . ' has no stored rate)';
                }
            }
        }
        $line .= " ($count";
        if ($p['legacy_n'] > 0) {
            $line .= ', ' . $p['legacy_n'] . ' legacy';
        }
        $line .= ')';
        $lines[] = $line;
    }
    return $lines;
}

// ------------------------------------------------------------------ HTML: amounts

/**
 * A money cell. Consolidated: base amount, marked when it includes a conversion; By currency: original amounts.
 * $opt: ref (transaction reference for the tooltip), at (date), strong (bool), plain (no marker), empty (text for no data).
 */
function psFxrAmount(mysqli $conn, array $ctx, array $mix, array $opt = []): string
{
    static $seq = 0;
    if ($mix === []) {
        return isset($opt['empty']) ? psFxrH($opt['empty']) : psFxrH(psFxrFmt($conn, 0, $ctx['base']));
    }
    $strong = !empty($opt['strong']);
    $wrap = fn(string $s) => $strong ? '<strong>' . $s . '</strong>' : $s;

    // 'native': amounts that carry no stored rate (e.g. physical cash counts) are always shown in their own currency.
    if ($ctx['view'] === PS_FXR_BREAKDOWN || !empty($opt['native'])) {
        $parts = [];
        foreach (psFxrOrder($mix, $ctx) as $code) {
            $p = $mix[$code];
            $txt = psFxrH(psFxrFmt($conn, $p['txn'], $code));
            if ($code === $ctx['base'] && $p['legacy_n'] === 0) {
                $parts[] = '<span class="fxa">' . $wrap($txt) . '</span>';
            } elseif ($code === $ctx['base']) {
                $parts[] = '<span class="fxa fxa-legacy">' . $wrap($txt) . '<span class="fxa-tag" title="Includes legacy records: currency not recorded, treated as ' . psFxrH($code) . '">legacy</span></span>';
            } else {
                $parts[] = '<span class="fxa fxa-cur" style="' . psFxrStyle($code) . '">' . $wrap($txt)
                    . '<span class="fxa-tag">' . psFxrH($code) . '</span></span>';
            }
        }
        return '<span class="fxa-stack">' . implode('<br>', $parts) . '</span>';
    }

    $baseC = psFxrBase($mix);
    $txt = $wrap(psFxrH(psFxrFmt($conn, $baseC, $ctx['base'])));
    $converted = psFxrHasConverted($mix, $ctx);
    $incomplete = psFxrIncomplete($mix);
    $legacyN = array_sum(array_map(fn($p) => $p['legacy_n'], $mix));
    if (!empty($opt['plain']) || (!$converted && !$incomplete && $legacyN === 0)) {
        return '<span class="fxa">' . $txt . '</span>';
    }

    // Tooltip content
    $id = 'fxt' . (++$seq);
    $tip = '<span class="fxa-tip-row"><b>Report amount</b> ' . psFxrH(psFxrFmt($conn, $baseC, $ctx['base'])) . ' (' . psFxrH($ctx['base']) . ')</span>';
    $foreign = array_values(array_filter(psFxrOrder($mix, $ctx), fn($c) => $c !== $ctx['base']));
    $single = count($mix) === 1 && array_values($mix)[0]['n'] === 1;
    if ($single && $foreign) {
        $p = $mix[$foreign[0]];
        $tip .= '<span class="fxa-tip-row"><b>Originally</b> ' . psFxrH(psFxrFmt($conn, $p['txn'], $p['code'])) . '</span>';
        $rate = array_key_first($p['rates']);
        if ($rate !== null && $rate !== '') {
            $tip .= '<span class="fxa-tip-row"><b>Exchange rate</b> ' . psFxrH('1 ' . $ctx['base'] . ' = ' . $rate . ' ' . $p['code']) . ' (stored with the transaction)</span>';
        }
        $ids = array_key_first($p['ids']);
        if ($ids !== null && ($from = psFxrRateFrom($conn, (string)$ids)) !== '') {
            $tip .= '<span class="fxa-tip-row"><b>Rate in force from</b> ' . psFxrH($from) . '</span>';
        }
        $ref = $opt['ref'] ?? ($p['refs'][0] ?? '');
        if ($ref !== '') {
            $tip .= '<span class="fxa-tip-row"><b>Reference</b> ' . psFxrH($ref) . '</span>';
        }
        if (($opt['at'] ?? '') !== '') {
            $tip .= '<span class="fxa-tip-row"><b>Date</b> ' . psFxrH($opt['at']) . '</span>';
        }
    } else {
        $tip .= '<span class="fxa-tip-row"><b>Made up of</b></span>';
        foreach (psFxrDescribe($conn, $ctx, $mix) as $line) {
            $tip .= '<span class="fxa-tip-row fxa-tip-sub">' . psFxrH($line) . '</span>';
        }
        $rates = [];
        foreach ($mix as $p) {
            foreach (array_keys($p['rates']) as $r) {
                if ($r !== '') {
                    $rates[] = '1 ' . $ctx['base'] . ' = ' . $r . ' ' . $p['code'];
                }
            }
        }
        if ($rates) {
            $tip .= '<span class="fxa-tip-row"><b>Stored rates used</b> ' . psFxrH(implode('; ', array_unique($rates))) . (count($rates) >= 2 ? ' (range)' : '') . '</span>';
        }
    }
    if ($incomplete) {
        $missN = array_sum(array_map(fn($p) => $p['missing_n'], $mix));
        $tip .= '<span class="fxa-tip-row fxa-tip-warn">' . $missN . ' transaction(s) have no stored exchange rate and are not in this total.</span>';
    }
    if ($legacyN > 0) {
        $tip .= '<span class="fxa-tip-row">' . $legacyN . ' legacy record(s): currency not recorded, counted as ' . psFxrH(psFxLegacyCurrency()) . '.</span>';
    }

    $cls = 'fxa fxa-has-tip';
    $tag = '';
    $style = '';
    if ($converted) {
        $cls .= ' fxa-conv';
        $style = ' style="' . psFxrStyle($foreign[0] ?? $ctx['base']) . '"';
        $tag = '<span class="fxa-tag"><span class="fxa-sr">converted from </span>' . psFxrH(implode('+', array_slice($foreign, 0, 2)) . (count($foreign) > 2 ? '+' : '')) . '</span>';
    } elseif ($legacyN > 0) {
        $cls .= ' fxa-legacy';
        $tag = '<span class="fxa-tag">legacy</span>';
    }
    if ($incomplete) {
        $cls .= ' fxa-incomplete';
        $tag .= '<span class="fxa-tag fxa-tag-warn" aria-label="incomplete total">⚠ incomplete</span>';
    }
    return '<span class="' . $cls . '"' . $style . ' tabindex="0" role="button" aria-describedby="' . $id . '" data-fx-tip>'
        . $txt . $tag . '<span class="fxa-tip" role="tooltip" id="' . $id . '">' . $tip . '</span></span>';
}

/** Shortcut for one stored transaction row. */
function psFxrOne(mysqli $conn, array $ctx, ?string $cur, $txn, $base, array $meta = [], array $opt = []): string
{
    $mix = [];
    psFxrAdd($mix, $ctx, $cur, $txn, $base, $meta);
    return psFxrAmount($conn, $ctx, $mix, $opt + ['ref' => $meta['ref'] ?? '', 'at' => $meta['at'] ?? '']);
}

// ------------------------------------------------------------------ HTML: report furniture

/** "All amounts shown in USD …" / "Amounts in their original currencies …" */
function psFxrLabel(array $ctx): string
{
    $f = $ctx['cur'] !== '' ? ' Showing ' . $ctx['cur'] . ' transactions only.' : '';
    if ($ctx['view'] === PS_FXR_BREAKDOWN) {
        return 'Amounts in each transaction\'s own currency; totals are kept per currency (no conversion).' . $f;
    }
    return 'All amounts shown in ' . $ctx['base'] . ' (company base currency). Amounts marked with a currency code were converted at the rate stored with each transaction when it was made — never at today\'s rate.' . $f;
}

/**
 * View switch + currency filter (GET form). $keep: the report's own GET parameters to carry over.
 * Hidden when the company works in one currency only and no filter is active.
 */
function psFxrToolbar(array $ctx, array $keep = []): string
{
    if (empty($ctx['multi'])) {
        return '';
    }
    unset($keep['fx_view'], $keep['fx_cur'], $keep['export'], $keep['page']);
    $link = function (string $view) use ($ctx, $keep): string {
        $q = $keep;
        if ($view === PS_FXR_BREAKDOWN) {
            $q['fx_view'] = PS_FXR_BREAKDOWN;
        }
        if ($ctx['cur'] !== '') {
            $q['fx_cur'] = $ctx['cur'];
        }
        return '?' . http_build_query($q);
    };
    $out = '<form method="get" class="fxr-bar no-print" role="group" aria-label="Currency view">';
    foreach ($keep as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $vv) {
                $out .= '<input type="hidden" name="' . psFxrH($k) . '[]" value="' . psFxrH($vv) . '">';
            }
        } else {
            $out .= '<input type="hidden" name="' . psFxrH($k) . '" value="' . psFxrH($v) . '">';
        }
    }
    if ($ctx['view'] === PS_FXR_BREAKDOWN) {
        $out .= '<input type="hidden" name="fx_view" value="' . PS_FXR_BREAKDOWN . '">';
    }
    $isC = $ctx['view'] === PS_FXR_CONSOLIDATED;
    $out .= '<span class="fxr-seg">'
        . '<a href="' . psFxrH($link(PS_FXR_CONSOLIDATED)) . '" class="' . ($isC ? 'on' : '') . '"' . ($isC ? ' aria-current="true"' : '') . '>Consolidated (' . psFxrH($ctx['base']) . ')</a>'
        . '<a href="' . psFxrH($link(PS_FXR_BREAKDOWN)) . '" class="' . (!$isC ? 'on' : '') . '"' . (!$isC ? ' aria-current="true"' : '') . '>By currency</a>'
        . '</span>';
    $out .= '<label class="fxr-cur">Currency <select name="fx_cur" onchange="this.form.submit()"><option value="">All currencies</option>';
    foreach ($ctx['currencies'] as $code => $c) {
        $out .= '<option value="' . psFxrH($code) . '"' . ($ctx['cur'] === $code ? ' selected' : '') . '>' . psFxrH($code . ' · ' . ($c['name'] ?? $code)) . '</option>';
    }
    $out .= '</select></label><noscript><button type="submit" class="ps-btn ps-btn-sm">Apply</button></noscript></form>';
    $out .= '<p class="fxr-label">' . psFxrH(psFxrLabel($ctx)) . '</p>';
    return $out;
}

/**
 * Currency summary: per-currency counts and original totals, base equivalents and the consolidated base total.
 * This is the reconciliation bridge between the original amounts and the consolidated figure.
 */
function psFxrSummary(mysqli $conn, array $ctx, array $mix, string $title = 'Totals by currency', array $opt = []): string
{
    if ($mix === [] || (empty($ctx['multi']) && !psFxrHasConverted($mix, $ctx) && !psFxrIncomplete($mix))) {
        return '';
    }
    if (!empty($opt['native'])) {
        // No stored rate (cash counts): per-currency totals only, never a consolidated figure.
        $out = '<div class="fxr-sum"><h3 class="fxr-sum-title">' . psFxrH($title) . '</h3><div class="ps-table-wrap"><table class="fxr-sum-table"><thead><tr>'
            . '<th>Currency</th><th class="num">Records</th><th class="num">Total in currency</th></tr></thead><tbody>';
        foreach (psFxrOrder($mix, $ctx) as $code) {
            $p = $mix[$code];
            $cls = $code === $ctx['base'] ? '' : ' style="' . psFxrStyle($code) . '"';
            $out .= '<tr' . $cls . '><td><span class="fxa-code">' . psFxrH($code) . '</span>' . ($p['legacy_n'] > 0 ? ' <span class="fxa-tag">' . $p['legacy_n'] . ' legacy</span>' : '') . '</td><td class="num">' . (int)$p['n']
                . '</td><td class="num">' . psFxrH(psFxrFmt($conn, $p['txn'], $code)) . '</td></tr>';
        }
        return $out . '</tbody></table></div><p class="fxr-label">' . psFxrH($opt['note'] ?? 'These amounts are kept in their own currency only: no exchange rate is stored with them, so they are never converted or added across currencies.') . '</p></div>';
    }
    $out = '<div class="fxr-sum"><h3 class="fxr-sum-title">' . psFxrH($title) . '</h3><div class="ps-table-wrap"><table class="fxr-sum-table"><thead><tr>'
        . '<th>Currency</th><th class="num">Transactions</th><th class="num">Total in currency</th><th>Conversion basis</th><th class="num">In ' . psFxrH($ctx['base']) . '</th>'
        . '</tr></thead><tbody>';
    foreach (psFxrOrder($mix, $ctx) as $code) {
        $p = $mix[$code];
        $basis = $code === $ctx['base'] ? 'Base currency — no conversion' : 'Stored rate per transaction'
            . ($p['rates'] ? ' (' . implode('; ', array_map(fn($r) => '1 ' . $ctx['base'] . ' = ' . $r . ' ' . $code, array_keys($p['rates']))) . ')' : '');
        if ($p['legacy_n'] > 0) {
            $basis .= ' · ' . $p['legacy_n'] . ' legacy record(s): currency not recorded, counted as ' . $code;
        }
        $baseCell = $code === $ctx['base'] && $p['missing_n'] === 0 ? psFxrFmt($conn, $p['base'], $ctx['base']) : psFxrFmt($conn, $p['base'], $ctx['base']);
        $warn = $p['missing_n'] > 0 ? ' <span class="fxa-tag fxa-tag-warn">⚠ ' . $p['missing_n'] . ' without a rate (' . psFxrH(psFxrFmt($conn, $p['missing_txn'], $code)) . ') not converted</span>' : '';
        $cls = $code === $ctx['base'] ? '' : ' class="fxr-cur-row" style="' . psFxrStyle($code) . '"';
        $out .= '<tr' . $cls . '><td><span class="fxa-code">' . psFxrH($code) . '</span></td><td class="num">' . (int)$p['n'] . '</td>'
            . '<td class="num">' . psFxrH(psFxrFmt($conn, $p['txn'], $code)) . '</td><td class="fxr-basis">' . psFxrH($basis) . $warn . '</td>'
            . '<td class="num">' . psFxrH($baseCell) . '</td></tr>';
    }
    $n = array_sum(array_map(fn($p) => $p['n'], $mix));
    $out .= '</tbody><tfoot><tr><th>Consolidated</th><th class="num">' . $n . '</th><th class="num">—</th><th>' . psFxrH('Sum of the ' . $ctx['base'] . ' column (each transaction at its own stored rate)') . '</th>'
        . '<th class="num">' . psFxrH(psFxrFmt($conn, psFxrBase($mix), $ctx['base'])) . (psFxrIncomplete($mix) ? ' <span class="fxa-tag fxa-tag-warn">⚠ incomplete</span>' : '') . '</th></tr></tfoot></table></div>';
    if (psFxrIncomplete($mix)) {
        $out .= '<p class="fxr-warn" role="alert">Some transactions have no stored exchange rate, so the consolidated total leaves them out. They are still in their own currency total above.</p>';
    }
    if (!empty($opt['note'])) {
        $out .= '<p class="fxr-label">' . psFxrH($opt['note']) . '</p>';
    }
    return $out . '</div>';
}

// ------------------------------------------------------------------ CSV / PDF / Excel

/** Extra CSV headers for a transaction-level export. */
function psFxrCsvHeaders(array $ctx): array
{
    return ['Currency', 'Amount (original currency)', 'Exchange rate (1 ' . $ctx['base'] . ' = x currency)', 'Rate ids', 'Reporting currency', 'Amount (' . $ctx['base'] . ')', 'Currency status'];
}

/** CSV values for one transaction: matches psFxrCsvHeaders(). */
function psFxrCsvValues(array $ctx, ?string $cur, $txn, $base, string $rate = '', string $ids = ''): array
{
    $mix = [];
    psFxrAdd($mix, $ctx, $cur, $txn, $base, ['rate' => $rate]);
    $p = array_values($mix)[0];
    $legacy = $p['legacy_n'] > 0;
    if ($legacy) {
        $status = 'Legacy - currency not recorded, counted as ' . $p['code'];
    } elseif ($p['code'] === $ctx['base']) {
        $status = 'Base currency';
    } elseif ($p['missing_n'] > 0) {
        $status = 'No stored rate - not in consolidated total';
    } else {
        $status = 'Converted at stored rate';
    }
    return [
        $p['code'],
        psFxrMoney($p['txn']),
        $p['code'] === $ctx['base'] ? '1' : psFxrRateText($rate),
        $ids,
        $ctx['base'],
        $p['missing_n'] > 0 ? '' : psFxrMoney($p['base']),
        $status,
    ];
}

/** CSV rows for an aggregate (one row per currency): [prefix..., Currency, Transactions, Total in currency, Reporting currency, Total in base, Status]. */
function psFxrCsvAggHeaders(array $ctx): array
{
    return ['Currency', 'Transactions', 'Total (original currency)', 'Reporting currency', 'Total (' . $ctx['base'] . ')', 'Currency status'];
}

function psFxrCsvAggRows(array $ctx, array $mix, array $prefix = []): array
{
    $rows = [];
    foreach (psFxrOrder($mix, $ctx) as $code) {
        $p = $mix[$code];
        $status = $code === $ctx['base'] ? 'Base currency' : 'Converted at stored rates';
        if ($p['legacy_n'] > 0) {
            $status .= '; ' . $p['legacy_n'] . ' legacy (currency not recorded)';
        }
        if ($p['missing_n'] > 0) {
            $status .= '; ' . $p['missing_n'] . ' without a stored rate (' . psFxrMoney($p['missing_txn']) . ' ' . $code . ') left out of the ' . $ctx['base'] . ' total';
        }
        $rows[] = array_merge($prefix, [$code, $p['n'], psFxrMoney($p['txn']), $ctx['base'], psFxrMoney($p['base']), $status]);
    }
    return $rows;
}

/** Plain-text amount for PDF / Excel / print: "$100.00 (ZWG 2,650.00 at 1 USD = 26.5 ZWG)". */
function psFxrText(mysqli $conn, array $ctx, array $mix): string
{
    if ($mix === []) {
        return psFxrFmt($conn, 0, $ctx['base']);
    }
    if ($ctx['view'] === PS_FXR_BREAKDOWN) {
        return implode('; ', array_map(fn($c) => psFxrFmt($conn, $mix[$c]['txn'], $c), psFxrOrder($mix, $ctx)));
    }
    $s = psFxrFmt($conn, psFxrBase($mix), $ctx['base']);
    $foreign = array_values(array_filter(psFxrOrder($mix, $ctx), fn($c) => $c !== $ctx['base']));
    if ($foreign) {
        $bits = [];
        foreach ($foreign as $c) {
            $p = $mix[$c];
            $rates = array_keys($p['rates']);
            $rateTxt = count($rates) === 1 ? ' at 1 ' . $ctx['base'] . ' = ' . $rates[0] . ' ' . $c
                : (count($rates) > 1 ? ' at the stored rates (' . implode(' to ', (function ($r) { usort($r, fn($a, $b) => (float)$a <=> (float)$b); return [reset($r), end($r)]; })($rates)) . ' ' . $c . ' per ' . $ctx['base'] . ')' : '');
            $bits[] = psFxrFmt($conn, $p['txn'], $c) . $rateTxt;
        }
        $s .= ' (from ' . implode('; ', $bits) . ')';
    }
    if (psFxrIncomplete($mix)) {
        $s .= ' [incomplete: rate missing]';
    }
    $legacyN = array_sum(array_map(fn($p) => $p['legacy_n'], $mix));
    if ($legacyN > 0 && !$foreign) {
        $s .= ' [legacy]';
    }
    return $s;
}

// ------------------------------------------------------------------ grouped queries

/**
 * Run a grouped query whose SELECT has key columns k0..kN plus psFxrAggSelectSql() columns (prefix fx_, and any
 * extra prefixes), GROUP BY keys + currency. Folds the currency rows into one row per key:
 * ['keys' => [k0..], 'mix' => mix, 'mixes' => [prefix => mix], 'sum' => [col => float for $sumCols], 'n' => count].
 * Sorted by consolidated base amount (desc) unless $sort = false.
 * @return array{ok:bool,groups?:list<array>,total?:array,error?:string}
 */
function psFxrGrouped(mysqli $conn, array $ctx, string $sql, string $types, array $params, int $keyCount, array $sumCols = [], array $prefixes = [], bool $sort = true): array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('fx grouped prepare failed: ' . mysqli_error($conn));
        return ['ok' => false, 'error' => 'The report could not be loaded.'];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'The report could not be loaded.'];
    }
    $res = mysqli_stmt_get_result($stmt);
    $groups = [];
    $total = ['mix' => [], 'mixes' => [], 'sum' => [], 'n' => 0];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $keys = [];
        for ($i = 0; $i < $keyCount; $i++) {
            $keys[] = $row['k' . $i] ?? null;
        }
        $id = json_encode($keys);
        if (!isset($groups[$id])) {
            $groups[$id] = ['keys' => $keys, 'mix' => [], 'mixes' => [], 'sum' => [], 'n' => 0, 'row' => $row];
        }
        psFxrAddAgg($groups[$id]['mix'], $ctx, $row);
        psFxrAddAgg($total['mix'], $ctx, $row);
        foreach ($prefixes as $p) {
            $groups[$id]['mixes'][$p] = $groups[$id]['mixes'][$p] ?? [];
            $total['mixes'][$p] = $total['mixes'][$p] ?? [];
            psFxrAddAgg($groups[$id]['mixes'][$p], $ctx, $row, $p);
            psFxrAddAgg($total['mixes'][$p], $ctx, $row, $p);
        }
        foreach ($sumCols as $c) {
            $groups[$id]['sum'][$c] = ($groups[$id]['sum'][$c] ?? 0) + (float)($row[$c] ?? 0);
            $total['sum'][$c] = ($total['sum'][$c] ?? 0) + (float)($row[$c] ?? 0);
        }
        $groups[$id]['n'] += (int)($row['fx_n'] ?? 0);
        $total['n'] += (int)($row['fx_n'] ?? 0);
    }
    mysqli_stmt_close($stmt);
    $groups = array_values($groups);
    if ($sort) {
        usort($groups, fn($a, $b) => psFxrBase($b['mix']) <=> psFxrBase($a['mix']) ?: strcmp((string)json_encode($a['keys']), (string)json_encode($b['keys'])));
    }
    return ['ok' => true, 'groups' => $groups, 'total' => $total];
}

/** Sale header currency snapshot columns (alias r) for a SELECT list. */
function psFxrSaleColsSql(string $alias = 'r'): string
{
    return "$alias.currency AS fx_currency, $alias.base_total AS fx_base_total, $alias.fx_rate AS fx_rate, $alias.fx_rate_ids AS fx_rate_ids";
}

/** Per-currency aggregation of sale headers (alias r). */
function psFxrSaleAggSql(mysqli $conn, array $ctx, string $alias = 'r'): string
{
    return psFxrAggSelectSql($conn, $ctx, "$alias.currency", "CAST($alias.totalz AS DECIMAL(14,2))", "$alias.base_total", "$alias.fx_rate");
}

/** Per-currency aggregation of sale lines (alias s) using the header's stored rate (alias r). */
function psFxrLineAggSql(mysqli $conn, array $ctx, string $line = 's', string $hdr = 'r', ?string $countExpr = null): string
{
    return psFxrAggSelectSql($conn, $ctx, "$hdr.currency", "CAST($line.revenue AS DECIMAL(14,2))", null, "$hdr.fx_rate", 'fx_', $countExpr);
}
