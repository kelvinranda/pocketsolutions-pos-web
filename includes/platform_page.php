<?php
/**
 * Phase 18 — shared start for Super Admin console pages (platform*.php).
 * The central gate (page_access.php) already limits these pages to Super Admins; this repeats the
 * check so a page never runs for anyone else even if the gate is bypassed.
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/ps_layout.php';

function psPlatformPageBoot(): mysqli
{
    global $servername, $username, $password, $dbname;
    psSecurityStartSession();
    if (!psPlatformSessionIsSuperAdmin()) {
        http_response_code(403);
        exit('This page is for ' . PS_PLATFORM_PROVIDER_NAME . ' Super Admins.');
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_connect($servername, $username, $password, $dbname);
    if (!$conn) {
        exit('Connection failed.');
    }
    mysqli_set_charset($conn, 'utf8mb4');
    if (!psPlatformEnsureSchema($conn)) {
        exit('The companies table could not be created. Run the Phase 18 SQL first.');
    }
    return $conn;
}

/** Post-redirect-get flash message. */
function psPlatformFlash(string $type, string $msg): void
{
    $_SESSION['ps_platform_flash'] = ['type' => $type, 'msg' => $msg];
}

function psPlatformFlashShow(): void
{
    $f = $_SESSION['ps_platform_flash'] ?? null;
    unset($_SESSION['ps_platform_flash']);
    if (!is_array($f)) {
        return;
    }
    $cls = $f['type'] === 'ok' ? 'ps-alert-success' : 'ps-alert-destructive';
    echo '<div class="ps-alert ' . $cls . '" role="status">' . htmlspecialchars((string)$f['msg'], ENT_QUOTES, 'UTF-8') . '</div>';
}

/** Does branchies have company_name (multi-company registry)? */
function psPlatformBranchesHaveCompany(mysqli $conn): bool
{
    return psBranchRegistryHasCompany($conn);
}

/** Branch names registered for a company. */
function psPlatformCompanyBranches(mysqli $conn, string $company): array
{
    if (!psPlatformBranchesHaveCompany($conn)) {
        return [];
    }
    $stmt = mysqli_prepare($conn, 'SELECT branch_name FROM branchies WHERE company_name = ? ORDER BY branch_name');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $company);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (trim((string)$r['branch_name']) !== '') {
            $out[] = trim((string)$r['branch_name']);
        }
    }
    mysqli_stmt_close($stmt);
    return array_values(array_unique($out));
}

/** Shared page CSS for the console. */
function psPlatformPageCss(): void
{
    // Prefixed to beat the theme's generic ".ps-card-content > form" rule (display:block).
    $p = 'html[data-theme] body.ps-app ';
    $rules = [
        '.pf-wrap{max-width:1100px}',
        '.pf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}',
        '.pf-grid .ps-field{margin:0;display:flex;flex-direction:column;gap:6px}',
        '.pf-grid .ps-input{width:100%}',
        '.pf-span{grid-column:1/-1}',
        'form.pf-form.pf-form{display:flex;flex-direction:column;gap:20px}',
        '.pf-field{margin:0;display:flex;flex-direction:column;gap:6px}',
        '.pf-section + .pf-section{border-top:1px solid var(--ps-line);padding-top:20px}',
        '.pf-h{margin:0 0 14px;font-size:1rem;font-weight:700;line-height:1.3}',
        '.pf-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap}',
        '.pf-stack{display:flex;flex-direction:column;gap:20px}',
        '.pf-stack > .ps-card{margin:0}',
        '.pf-cols > *, .pf-stack > *, .pf-wrap{min-width:0}',
        '.pf-wrap .ps-card{max-width:100%;min-width:0}',
        '.pf-cols{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:20px;align-items:start}',
        '.pf-check{display:flex;gap:10px;align-items:flex-start;line-height:1.4}',
        '.pf-check input{margin-top:3px}',
        '.pf-help{color:var(--ps-muted);font-size:.86rem;margin:0}',
        '.pf-meta{color:var(--ps-muted);font-size:.86rem}',
        '.pf-text{margin:0;line-height:1.5}',
        '.pf-after{margin-top:12px}',
        '.pf-back{margin:0 0 6px}',
        '.pf-row-actions{display:flex;gap:8px;flex-wrap:nowrap;justify-content:flex-end;white-space:nowrap}',
        '.pf-row-actions form{margin:0;display:inline}',
        'form.pf-inline.pf-inline{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:16px}',
        '.pf-inline .pf-field{flex:1 1 260px}',
        '.pf-dl{display:grid;grid-template-columns:max-content 1fr;gap:8px 16px;margin:20px 0 0;padding-top:16px;border-top:1px solid var(--ps-line)}',
        '.pf-dl dt{color:var(--ps-muted)}',
        '.pf-dl dd{margin:0}',
        '.ps-card-header + .ps-card-content.ps-card-flush{margin-top:16px}',
        'details.ps-card > summary.ps-card-header{cursor:pointer;list-style:none;padding-bottom:20px}',
        'details.ps-card > summary.ps-card-header::-webkit-details-marker{display:none}',
        'details.ps-card > summary.ps-card-header .ps-card-title::after{content:" +";color:var(--ps-muted);font-weight:400}',
        'details.ps-card[open] > summary.ps-card-header .ps-card-title::after{content:" −"}',
        'details.ps-card[open] > summary.ps-card-header{padding-bottom:0}',
    ];
    $css = '';
    foreach ($rules as $r) {
        $css .= $p . $r . "\n";
    }
    $css .= '@media (max-width:900px){' . $p . '.pf-cols{grid-template-columns:1fr}}' . "\n";
    $css .= '@media (max-width:640px){' . $p . '.pf-grid{grid-template-columns:1fr}' . $p . '.pf-actions .ps-btn{flex:1 1 auto}}' . "\n";
    echo '<style>' . "\n" . $css . '</style>';
}

function psPlatformStatusBadge(array $c): string
{
    $s = strtoupper((string)($c['status'] ?? 'ACTIVE'));
    $cls = $s === 'ACTIVE' ? 'success' : 'destructive';
    return '<span class="ps-badge ps-badge-' . $cls . '">' . ($s === 'ACTIVE' ? 'Active' : 'Suspended') . '</span>';
}

function psPlatformManagedBadge(array $c): string
{
    return (int)($c['provider_managed'] ?? 0) === 1
        ? '<span class="ps-badge ps-badge-default">Administered by us</span>'
        : '<span class="ps-badge ps-badge-secondary">Self-managed</span>';
}
