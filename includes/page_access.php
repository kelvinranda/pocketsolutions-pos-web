<?php
/**
 * Central page access gate (health-check 2026-10-13).
 *
 * Runs from pockets_config.php, which every web page in the webroot loads first, so the
 * rules hold even when a page forgets its own check. It enforces three things the pages did
 * unevenly before:
 *   1. Login required for every page except the few public endpoints below.
 *   2. The same session rules everywhere: 10-minute idle timeout and the live locked/deleted
 *      user check (psSecurityEnforceWebSession) — about 60 pages skipped these.
 *   3. Role: Till users may open only the till pages (Phase 17: Supervisor / Management get
 *      their own wider lists — psPageAccessPagesForLevel). Before, the menu hid admin pages but
 *      typing the address opened them (stock adjustment, GRV, item edit/import, reports ...).
 *
 * Pages keep their own existing checks; this gate only adds the floor.
 * To give Till users one more page, add its file name to PS_TILL_EXTRA_PAGES in the private
 * pockets_config.php return array as a comma list, e.g. 'till_extra_pages' => 'grv.php'.
 */

if (!function_exists('psPageAccessEnforce')) {

    /** Pages anyone may load (they handle their own auth or are login itself). */
    function psPageAccessPublicPages(): array
    {
        return [
            'login.php',
            'logout.php',
            'index.php',
            'health.php',
            'get_receipt.php',   // print agent: shared token
            'printed.php',       // print agent: shared token
        ];
    }

    /** Pages a Till (non-admin) user may use: selling, own till cash, refunds, receipts. */
    function psPageAccessTillPages(): array
    {
        $pages = [
            'ajax_pos.php',
            'ajax_pos_barcode.php',
            'ajax_pos_customer_account.php',
            'ajax_pos_customer_search.php',
            'ajax_pos_customer_create.php',   // 2026-10-18: checks psSessionMayAddCustomer itself
            'ajax_pos_discard_cart.php',
            'fetch_pos_catalog.php',
            'submit_sale2.php',
            // 2026-10-17: quotations — all staff (price changes on a quote need Supervisor+, checked in the page).
            'quotations.php',
            'quotation_edit.php',
            'quotation_view.php',
            'session_status.php',
            'session_check.php',
            'open_session.php',
            'close_session.php',
            'set_branch.php',
            'all_receipts.php',
            'my_receipt.php',
            'receipt_print.php',
            'zimra_receipt_print.php',
            'cash_movement.php',
            'cash_reconciliation.php',
            'cash_till_close.php',
            'refund.php',
            'change_password.php',
        ];
        $extra = function_exists('psConfigValue') ? psConfigValue('till_extra_pages', '') : '';
        foreach (explode(',', $extra) as $p) {
            $p = basename(trim($p));
            if ($p !== '' && preg_match('/^[A-Za-z0-9_]+\.php$/', $p)) {
                $pages[] = $p;
            }
        }
        return $pages;
    }

    /** Phase 17 — Supervisor: Till pages + ZIMRA fiscal day + fiscal receipt print. Refund approval is in refund.php. */
    function psPageAccessSupervisorPages(): array
    {
        return array_merge(psPageAccessTillPages(), [
            'zimra_day.php',
            // 2026-10-17: Supervisors (and Management) set customer credit limits.
            'customer_accounts_list.php',
            'customer_account_view.php',
        ]);
    }

    /** Phase 17 — Management: Supervisor pages + read-only reports (own working branch; admin keeps all-branch). */
    function psPageAccessManagementPages(): array
    {
        return array_merge(psPageAccessSupervisorPages(), [
            'zimra_receipts.php',
            'management_dashboard.php',
            'sales_reports.php',
            'z_report.php',
            'cashier_sales.php',
            'generate_pdf.php',
            'inventory_reports.php',
            'tax_reports.php',
            'cash_management.php',
            'cash_movements_report.php',
            'cash_reconciliation_report.php',
            'cash_closure_report.php',
            'cash_session_view.php',
            'cash_audit_history.php',
            'refund_reconciliation.php',
            'refund_recon_report.php',
            'refund_recon_finding.php',
            'profit_report.php',
            'exchange_rates.php', // 2026-10-20: managers keep rates; admins too (all pages)
        ]);
    }

    /** Pages allowed for a non-admin level (case-insensitive): Till | Supervisor | Management. */
    function psPageAccessPagesForLevel(string $level): array
    {
        $level = strtolower(trim($level));
        if ($level === 'management') {
            return psPageAccessManagementPages();
        }
        if ($level === 'supervisor') {
            return psPageAccessSupervisorPages();
        }
        return psPageAccessTillPages();
    }

    /** Endpoints that answer AJAX with JSON (deny with JSON, not a redirect). */
    function psPageAccessIsJsonEndpoint(string $page): bool
    {
        // ajax_pos.php is the POS screen itself (HTML) — only its ajax_pos_* helpers answer JSON.
        if ($page === 'ajax_pos.php') {
            return false;
        }
        return (bool)preg_match('/^(ajax_|fetch_|submit_|suggest|session_status|open_session|close_session|set_branch)/', $page)
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
                && stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') === false);
    }

    function psPageAccessDeny(string $page, int $code, string $message): void
    {
        http_response_code($code);
        if (psPageAccessIsJsonEndpoint($page)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $message]);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Not available</title>';
        echo '<link rel="stylesheet" href="assets/ps-theme.css"></head><body class="ps-app">';
        echo '<div style="max-width:460px;margin:15vh auto;padding:0 16px;text-align:center;font-family:system-ui,sans-serif">';
        echo '<h1 style="font-size:1.4rem">Not available for your account</h1><p>' . $safe . '</p>';
        $back = (function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin())
            ? (psPlatformIsActing() ? '<a href="admin_dashboard.php">Back to Admin Home</a>' : '<a href="platform.php">Back to companies</a>')
            : '<a href="ajax_pos.php">Back to the POS</a>';
        echo '<p>' . $back . '</p></div></body></html>';
        exit;
    }

    function psPageAccessEnforce(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($script === '') {
            return;
        }
        $webroot = realpath(dirname(__DIR__));
        $scriptDir = realpath(dirname($script));
        // Only top-level web pages. mobile-endpoints/ has its own Bearer-token auth.
        if ($webroot === false || $scriptDir === false || strcasecmp($webroot, $scriptDir) !== 0) {
            return;
        }
        $page = basename($script);
        if (in_array(strtolower($page), psPageAccessPublicPages(), true)) {
            return;
        }

        require_once __DIR__ . '/security.php';
        $json = psPageAccessIsJsonEndpoint($page);

        // Live user revalidation needs a DB handle; reuse the resolved config.
        $conn = null;
        if (function_exists('psConfigResolve')) {
            $cfg = psConfigResolve();
            if ($cfg['dbname'] !== '') {
                mysqli_report(MYSQLI_REPORT_OFF);
                $conn = @mysqli_connect($cfg['servername'], $cfg['username'], $cfg['password'], $cfg['dbname']);
                if ($conn) {
                    mysqli_set_charset($conn, 'utf8mb4');
                }
            }
        }
        psSecurityEnforceWebSession($conn ?: null, [
            'redirect' => !$json,
            'json' => $json,
            'login' => 'login.php',
            'touch' => true,
            'csrf' => true,
        ]);
        if ($conn) {
            mysqli_close($conn);
        }

        // Phase 18 — Super Admin: console pages only; while managing a company, admin pages except
        // sales / refunds / till & cash operations.
        if (function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin()) {
            if (psPlatformIsActing()) {
                if (in_array($page, psPlatformBlockedPagesWhileActing(), true)) {
                    psPageAccessDeny($page, 403, psPlatformMoneyBlockedMessage());
                }
                return;
            }
            if (in_array($page, psPlatformConsolePages(), true)) {
                return;
            }
            if (!$json) {
                header('Location: platform.php');
                exit;
            }
            psPageAccessDeny($page, 403, 'Choose a company to manage first.');
        }

        $level = strtolower(trim((string)($_SESSION['level'] ?? '')));
        if ($level === 'admin') {
            if (in_array($page, ['platform.php', 'platform_company.php', 'platform_admins.php', 'platform_activity.php', 'platform_units.php', 'platform_desktop_releases.php', 'platform_exit.php'], true)) {
                psPageAccessDeny($page, 403, 'This page is for ' . PS_PLATFORM_PROVIDER_NAME . ' staff.');
            }
            return;
        }
        if (!in_array($page, psPageAccessPagesForLevel($level), true)) {
            psPageAccessDeny($page, 403, 'This page is for administrators. Ask an admin if you need it.');
        }
    }
}
