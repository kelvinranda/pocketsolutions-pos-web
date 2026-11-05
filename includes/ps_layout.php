<?php

if (!function_exists('ps_session')) {
    function ps_session() {
        return session_status() === PHP_SESSION_ACTIVE ? $_SESSION : [];
    }

    function ps_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * One money format across pages (health-check 2026-10-13): "$1,234.50" for USD,
     * "<CODE> 1,234.50" for any other currency (e.g. ZWG). Negative → "-$12.00".
     */
    function ps_money($amount, $currency = null) {
        $value = is_numeric($amount) ? (float)$amount : 0.0;
        // Multi-currency (2026-10-20): no currency given = the signed-in company's base (set when it is looked up).
        if ($currency === null) {
            $currency = $GLOBALS['ps_fx_session_base'] ?? 'USD';
        }
        $code = strtoupper(trim((string)$currency));
        $text = number_format(abs($value), 2, '.', ',');
        $prefix = ($code === '' || $code === 'USD') ? '$' : ($code . ' ');
        return ($value < 0 ? '-' : '') . $prefix . $text;
    }

    function ps_initials($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return 'PS';
        }
        $parts = preg_split('/[\s@._-]+/', $value);
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= strtoupper(substr($part, 0, 1));
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters !== '' ? $letters : 'PS';
    }

    function ps_head() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo '<script>(function(){try{var t=localStorage.getItem("ps-theme");if(t!=="light"&&t!=="dark")t="dark";document.documentElement.setAttribute("data-theme",t);}catch(e){document.documentElement.setAttribute("data-theme","dark");}})();</script>' . "\n";
        $themeCss = dirname(__DIR__) . '/assets/ps-theme.css';
        $themeVer = is_file($themeCss) ? (string)filemtime($themeCss) : (string)time();
        echo '<link rel="stylesheet" href="assets/ps-theme.css?v=' . rawurlencode($themeVer) . '">' . "\n";
        // 2026-11-04: Hallmark Dusk phase 1 (tokens + Wix Madefor type). Remove these two lines to undo.
        $duskCss = dirname(__DIR__) . '/assets/ps-dusk.css';
        echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Wix+Madefor+Display:wght@400;500;600&family=Wix+Madefor+Text:wght@400;500;600;700&display=swap"><link rel="stylesheet" href="assets/ps-dusk.css?v=' . rawurlencode(is_file($duskCss) ? (string)filemtime($duskCss) : '1') . '">' . "\n";
        // 2026-10-18: Pockets logo as the browser tab / home-screen icon.
        echo '<link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png"><link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16.png"><link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">' . "\n";
        if (function_exists('psCsrfMeta')) {
            echo psCsrfMeta() . "\n";
        } elseif (is_file(__DIR__ . '/security.php')) {
            require_once __DIR__ . '/security.php';
            if (function_exists('psSecurityStartSession')) {
                psSecurityStartSession();
            }
            if (function_exists('psCsrfMeta')) {
                echo psCsrfMeta() . "\n";
            }
        }
    }

    function ps_theme_switch() {
        echo '<div class="ps-theme-switch" role="group" aria-label="Color theme">';
        echo '<button type="button" data-theme-value="light">Light</button>';
        echo '<button type="button" data-theme-value="dark">Dark</button>';
        echo '</div>';
    }

    /**
     * Ensure session (+ branch helpers) before nav/account role checks.
     * Pages that emit HTML before session_start() otherwise look like Till
     * and get the till allow-list sidebar even for admins.
     */
    function ps_layout_boot_session() {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!function_exists('psSecurityStartSession') && is_file(__DIR__ . '/security.php')) {
                require_once __DIR__ . '/security.php';
            }
            if (function_exists('psSecurityStartSession')) {
                psSecurityStartSession();
            } elseif (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
        }
        if (!function_exists('psBranchSessionIsAdmin') && is_file(__DIR__ . '/branch_access.php')) {
            require_once __DIR__ . '/branch_access.php';
        }
    }

    /** Session admin gate (matches psBranchSessionIsAdmin when available). */
    function ps_layout_is_admin() {
        ps_layout_boot_session();
        if (function_exists('psBranchSessionIsAdmin')) {
            return psBranchSessionIsAdmin();
        }
        $session = ps_session();
        return strtolower(trim((string)($session['level'] ?? ''))) === 'admin';
    }

    /**
     * Account menu links by role.
     * Till: Receipts, Change password, Log out.
     * Admin: + Dashboard, Change branch.
     */
    function ps_account_menu_items() {
        $isAdmin = ps_layout_is_admin();
        $items = [];
        if (function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin()) {
            $items[] = ['href' => 'platform.php', 'label' => 'Companies', 'id' => 'userChipPlatform'];
            if (psPlatformIsActing()) {
                $items[] = ['href' => 'admin_dashboard.php', 'label' => 'Admin Home', 'id' => 'userChipDashboard'];
                $items[] = ['href' => 'admin_dashboard.php?switch=1', 'label' => 'Change branch', 'id' => 'userChipBranch'];
            }
            $items[] = ['href' => 'change_password.php', 'label' => 'Change password', 'id' => 'userChipPassword'];
            return $items;
        }
        if ($isAdmin) {
            $items[] = ['href' => 'admin_dashboard.php', 'label' => 'Dashboard', 'id' => 'userChipDashboard'];
        }
        $items[] = ['href' => 'all_receipts.php', 'label' => 'Receipts', 'id' => 'userChipReceipts'];
        $items[] = ['href' => 'change_password.php', 'label' => 'Change password', 'id' => 'userChipPassword'];
        if ($isAdmin) {
            $items[] = ['href' => 'admin_dashboard.php?switch=1', 'label' => 'Change branch', 'id' => 'userChipBranch'];
        }
        return $items;
    }

    /** Shared account dropdown panel (POS chip + shell avatar). */
    function ps_account_menu_panel() {
        echo '<div class="user-chip-menu" id="userChipMenu" role="menu" hidden>';
        foreach (ps_account_menu_items() as $item) {
            echo '<a role="menuitem" href="' . ps_h($item['href']) . '" id="' . ps_h($item['id']) . '">'
                . ps_h($item['label']) . '</a>';
        }
        echo '<div class="user-chip-menu-sep" role="separator"></div>';
        echo '<button role="menuitem" type="button" id="userChipLogout">Log out</button>';
        echo '</div>';
    }

    /**
     * Clickable user chip + account menu.
     * $opts: variant (shell|pos), name, sub, initials_from
     */
    function ps_account_user_chip(array $opts = []) {
        $variant = ($opts['variant'] ?? 'shell') === 'pos' ? 'pos' : 'shell';
        $name = trim((string)($opts['name'] ?? ''));
        $sub = trim((string)($opts['sub'] ?? ''));
        $initialsFrom = trim((string)($opts['initials_from'] ?? $name));
        $displayName = $name !== '' ? $name : 'Signed in';

        echo '<div class="user-chip-wrap ps-account-wrap">';
        echo '<button type="button" class="user-chip' . ($variant === 'shell' ? ' ps-user' : '') . '" id="userChipBtn"'
            . ' aria-haspopup="menu" aria-expanded="false" aria-controls="userChipMenu"'
            . ' aria-label="Account menu">';
        if ($variant === 'pos') {
            echo '<div class="user">';
            echo '<div class="user-name">' . ps_h($displayName) . '</div>';
            if ($sub !== '') {
                echo '<div class="user-branch">' . ps_h($sub) . '</div>';
            }
            echo '</div>';
            echo '<div class="ps-avatar" aria-hidden="true">' . ps_h(ps_initials($initialsFrom !== '' ? $initialsFrom : 'PS')) . '</div>';
        } else {
            echo '<div class="ps-avatar" aria-hidden="true">' . ps_h(ps_initials($initialsFrom !== '' ? $initialsFrom : 'PS')) . '</div>';
            echo '<div class="ps-user-copy"><div class="ps-user-name">' . ps_h($displayName) . '</div>';
            echo '<div class="ps-user-role">' . ps_h($sub !== '' ? $sub : 'User') . '</div></div>';
        }
        echo '</button>';
        ps_account_menu_panel();
        echo '</div>';
    }

    /** Logout confirmation overlay (works on shell + POS). */
    function ps_logout_confirm_modal($hint = '') {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if ($hint === '') {
            $hint = 'You will need to sign in again.';
        }
        echo '<div class="ps-logout-overlay" id="logoutSheet" onclick="if(event.target===this&&window.closeLogoutConfirm)closeLogoutConfirm()">';
        echo '<div class="ps-logout-sheet" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" aria-describedby="logoutDesc">';
        echo '<h3 id="logoutTitle">Log out?</h3>';
        echo '<p class="ps-logout-hint" id="logoutDesc">' . ps_h($hint) . '</p>';
        echo '<div class="ps-logout-actions">';
        echo '<button class="ps-logout-btn ps-logout-btn-secondary" type="button" id="logoutCancel">Cancel</button>';
        echo '<button class="ps-logout-btn ps-logout-btn-primary" type="button" id="logoutConfirm">Log out</button>';
        echo '</div></div></div>';
    }

    function ps_icon($name) {
        $icons = [
            'grid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
            'cart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/><path d="M3 4h2l2.2 11h11.3l2-7H7"/></svg>',
            'box' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
            'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="3"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a3 3 0 0 1 0 5.75"/></svg>',
            'receipt' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/></svg>',
            'chart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/></svg>',
            'pin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>',
        ];
        return $icons[$name] ?? $icons['grid'];
    }

    /**
     * Sidebar catalogue, grouped (health-check 2026-10-13). Each item:
     * [href, label, icon, group]. Labels match each page's own heading/title.
     * Till users see only their allow-list; the server enforces the same list
     * in includes/page_access.php (hiding a link is not the security boundary).
     */
    function ps_nav_items() {
        $all = [
            ['ajax_pos.php', 'Web POS', 'cart', 'Sell'],
            ['all_receipts.php', 'Receipts', 'receipt', 'Sell'],
            ['refund.php', 'Refunds', 'receipt', 'Sell'],
            ['quotations.php', 'Quotations', 'receipt', 'Sell'],
            ['zimra_day.php', 'ZIMRA Fiscal Day', 'receipt', 'Sell'],
            ['zimra_receipts.php', 'ZIMRA Fiscal Receipts', 'chart', 'Reports'],

            ['cash_movement.php', 'Cash In / Out', 'receipt', 'Till & Cash'],
            ['cash_reconciliation.php', 'Cash Reconciliation', 'receipt', 'Till & Cash'],
            ['cash_till_close.php', 'Close Till', 'receipt', 'Till & Cash'],
            ['cash_management.php', 'Cash Management', 'chart', 'Till & Cash'],
            ['cash_movements_report.php', 'Cash Movements Report', 'chart', 'Till & Cash'],
            ['cash_reconciliation_report.php', 'Cash Reconciliation Report', 'chart', 'Till & Cash'],
            ['cash_closure_report.php', 'Till Closure Report', 'chart', 'Till & Cash'],
            ['desktop_tills.php', 'Desktop Tills', 'receipt', 'Till & Cash'],
            ['desktop_sync_issues.php', 'Sync Differences', 'chart', 'Till & Cash'],

            ['items_list.php', 'Items', 'box', 'Products & Stock'],
            ['import_items.php', 'Import Items', 'box', 'Products & Stock'],
            ['product_categories.php', 'Categories & Groups', 'grid', 'Products & Stock'],
            ['product_packs.php', 'Packs & Units', 'box', 'Products & Stock'],
            ['bulk_prices.php', 'Bulk Price Change', 'chart', 'Products & Stock'],
            ['barcode_labels.php', 'Barcode Labels', 'pin', 'Products & Stock'],
            ['run_stocklist.php', 'Stock List', 'chart', 'Products & Stock'],
            ['stock_take_sheet.php', 'Stock Take', 'pin', 'Products & Stock'],
            ['stock_take_records.php', 'Stock Take Records', 'chart', 'Products & Stock'], // 2026-11-03
            ['inventory_control.php', 'Inventory Control', 'box', 'Products & Stock'],
            ['opening_stock.php', 'Opening Stock', 'box', 'Products & Stock'],
            ['stock_adjustment.php', 'Stock Adjustment', 'box', 'Products & Stock'],
            ['movement_list.php', 'Stock Transfers', 'box', 'Products & Stock'],
            ['stock_movements.php', 'Stock Movements', 'box', 'Products & Stock'],
            ['product_history.php', 'Product History', 'receipt', 'Products & Stock'],
            ['product_audit_history.php', 'Product Audit', 'receipt', 'Products & Stock'],
            ['stock_migration.php', 'Legacy Stock Migration', 'box', 'Products & Stock'],

            ['goods_receipt_ref.php', 'Goods Receipt', 'box', 'Purchasing'],
            ['purchase_orders_list.php', 'Purchase Orders', 'receipt', 'Purchasing'],
            ['purchase_returns_list.php', 'Purchase Returns', 'receipt', 'Purchasing'],
            ['suppliers_list.php', 'Suppliers', 'users', 'Purchasing'],
            ['purchasing_reports.php', 'Purchasing Reports', 'chart', 'Purchasing'],

            ['customers_list.php', 'Customers', 'users', 'Customers & Accounts'],
            ['loyalty_settings.php', 'Loyalty Programme', 'grid', 'Customers & Accounts'],
            ['customer_accounts_list.php', 'Customer Accounts', 'receipt', 'Customers & Accounts'],
            ['customer_account_payments.php', 'Account Payments', 'receipt', 'Customers & Accounts'],
            ['customer_receivables_ageing.php', 'Receivables Ageing', 'chart', 'Customers & Accounts'],
            ['customer_credit_control.php', 'Credit Control', 'chart', 'Customers & Accounts'],
            ['customer_collection_activity.php', 'Collection Activity', 'receipt', 'Customers & Accounts'],

            ['management_dashboard.php', 'Management Dashboard', 'grid', 'Reports'],
            ['sales_reports.php', 'Sales Reports', 'chart', 'Reports'],
            ['z_report.php', 'Z Report', 'chart', 'Reports'],
            ['ItemSales.php', 'Item Sales', 'chart', 'Reports'],
            ['ItemReceived.php', 'Items Received', 'receipt', 'Reports'],
            ['InvestmentReport.php', 'Investment Report', 'chart', 'Reports'],
            ['inventory_reports.php', 'Inventory Reports', 'chart', 'Reports'],
            ['income_statement.php', 'Income Statement', 'chart', 'Reports'],
            ['profit_report.php', 'Profit & Margin', 'chart', 'Reports'],
            ['refund_reconciliation.php', 'Refund Reconciliation', 'chart', 'Reports'],
            ['refund_recon_report.php', 'Refund Reconciliation Report', 'chart', 'Reports'],

            ['expenses.php', 'Expenses', 'receipt', 'Finance'],
            ['tax_rates.php', 'Tax Rates', 'receipt', 'Finance'],
            ['exchange_rates.php', 'Exchange Rates', 'receipt', 'Finance'],
            ['tax_reports.php', 'Tax Reports', 'chart', 'Finance'],
            ['accounting.php', 'Accounting Export', 'receipt', 'Finance'],

            ['admin_dashboard.php', 'Admin Home', 'grid', 'Admin'],
            ['users.php', 'Users', 'users', 'Admin'],
            ['role_permissions.php', 'Role Permissions', 'users', 'Admin'], // 2026-11-02
            ['backup_admin.php', 'Backup & Recovery', 'box', 'Admin'],
            ['security_audit_history.php', 'Security Audit', 'receipt', 'Admin'],
            ['zimra_setup.php', 'ZIMRA Device Setup', 'grid', 'Admin'],
            ['settings.php', 'System Reset', 'grid', 'Admin'],
        ];

        // Phase 18 — Super Admin: platform console, or (managing a company) admin menu without
        // sales / refunds / till & cash pages, which stay with the client's own staff.
        $platform = [
            ['platform.php', 'Companies', 'grid', 'Platform'],
            ['platform_admins.php', 'Super Admins', 'users', 'Platform'],
            ['platform_units.php', 'Units of Measure', 'box', 'Platform'],
            ['platform_currencies.php', 'Currencies', 'grid', 'Platform'],
            ['platform_zimra_devices.php', 'ZIMRA Devices', 'receipt', 'Platform'],
            ['platform_desktop_releases.php', 'Desktop Releases', 'box', 'Platform'],
            ['platform_activity.php', 'Platform Activity', 'receipt', 'Platform'],
            ['branch_integrity_report.php', 'Branch Integrity', 'chart', 'Platform'], // 2026-11-02: covers every company
        ];
        if (function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin()) {
            if (!psPlatformIsActing()) {
                return $platform;
            }
            $blocked = psPlatformBlockedPagesWhileActing();
            $out = [];
            foreach ($all as $item) {
                if (!in_array($item[0], $blocked, true)) {
                    $out[] = $item;
                }
            }
            return array_merge($out, [['platform.php', 'Back to companies', 'grid', 'Platform']]);
        }
        $all[] = ['provider_access.php', 'Provider Access', 'receipt', 'Admin'];

        // Till operators (users.level = Till / non-admin): only till-appropriate shell links.
        // Admin keeps the full catalogue. Gate matches psBranchSessionIsAdmin().
        // Always boot session first — late/missing session_start must not collapse to Till nav.
        $isAdminNav = ps_layout_is_admin();
        if ($isAdminNav) {
            return $all;
        }

        $session = ps_session();
        $tillAllow = function_exists('psPageAccessPagesForLevel') ? psPageAccessPagesForLevel((string)($session['level'] ?? '')) : [
            'ajax_pos.php',
            'all_receipts.php',
            'cash_movement.php',
            'cash_reconciliation.php',
            'cash_till_close.php',
            'refund.php',
        ];
        $filtered = [];
        foreach ($all as $item) {
            if (in_array($item[0], $tillAllow, true)) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }

    function ps_open($title = '') {
        ps_layout_boot_session();
        $session = ps_session();
        $company = trim((string)($session['the_company'] ?? ''));
        $psAct = function_exists('psPlatformActing') ? psPlatformActing() : null;
        $psSuper = function_exists('psPlatformSessionIsSuperAdmin') && psPlatformSessionIsSuperAdmin();
        if ($psAct) {
            $company = (string)($psAct['display'] ?? $company);
        } elseif ($psSuper) {
            $company = PS_PLATFORM_PROVIDER_NAME;
        }
        if ($company === '') {
            $company = 'Pockets';
        }
        $user = trim((string)($session['logger'] ?? ''));
        $role = trim((string)($session['level'] ?? 'User'));
        if ($psSuper) {
            $role = $psAct ? 'Super Admin · managing' : 'Super Admin';
        }
        $branch = trim((string)($session['selected_branch'] ?? $session['branch'] ?? ''));
        $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($current === 'stock_list.php') {
            $current = 'run_stocklist.php';
        }
        if ($current === 'cashier_sales.php' || $current === 'generate_pdf.php') {
            $current = 'z_report.php';
        }
        if ($current === 'movement_receipt.php') {
            $current = 'movement_list.php';
        }
        if ($current === 'user_lock.php' || $current === 'update_branch.php') {
            $current = 'users.php';
        }
        if ($current === 'grv.php'
            || $current === 'goods_receipt.php'
            || $current === 'goods_receipt_audit_history.php') {
            $current = 'goods_receipt_ref.php';
        }
        if ($current === 'stock_movement.php') {
            $current = 'movement_list.php';
        }
        if ($current === 'tax_audit_history.php') {
            $current = 'tax_rates.php';
        }
        if ($current === 'new_expense.php') {
            $current = 'expenses.php';
        }
        if ($current === 'create_user.php') {
            $current = 'users.php';
        }
        if ($current === 'create_items.php' || $current === 'barcode_labels.php') {
            $current = 'items_list.php';
        }
        if ($current === 'create_supplier.php'
            || $current === 'supplier_audit_history.php'
            || $current === 'supplier_purchasing_history.php') {
            $current = 'suppliers_list.php';
        }
        if ($current === 'create_customer.php'
            || $current === 'customer_view.php'
            || $current === 'customer_audit_history.php'
            || $current === 'customers.php') {
            $current = 'customers_list.php';
        }
        if ($current === 'create_customer_account.php'
            || $current === 'customer_account_view.php'
            || $current === 'customer_account_audit_history.php'
            || $current === 'customer_account_statement.php') {
            $current = 'customer_accounts_list.php';
        }
        if ($current === 'create_customer_account_payment.php'
            || $current === 'customer_account_payment_view.php'
            || $current === 'customer_account_payment_allocate.php'
            || $current === 'customer_account_payment_audit_history.php') {
            $current = 'customer_account_payments.php';
        }
        if ($current === 'create_purchase_order.php'
            || $current === 'purchase_order_view.php'
            || $current === 'purchase_order_audit_history.php') {
            $current = 'purchase_orders_list.php';
        }
        if ($current === 'create_purchase_return.php'
            || $current === 'purchase_return_view.php'
            || $current === 'purchase_return_audit_history.php') {
            $current = 'purchase_returns_list.php';
        }
        if ($current === 'cash_session_view.php'
            || $current === 'cash_audit_history.php') {
            $current = 'cash_management.php';
        }
        if ($current === 'refund_recon_finding.php') {
            $current = 'refund_recon_report.php';
        }
        if ($current === 'accounting_mappings.php'
            || $current === 'accounting_preview.php'
            || $current === 'accounting_batches.php'
            || $current === 'accounting_recon.php') {
            $current = 'accounting.php';
        }

        echo '<div class="ps-nav-backdrop" data-ps-backdrop></div>';
        echo '<div class="ps-shell">';
        echo '<aside class="ps-side">';
        echo '<div class="ps-brand"><span class="ps-mark"><img src="assets/pockets-logo.png" alt="Pockets ICT Solutions"></span>';
        echo '<div class="ps-brand-copy"><div class="ps-brand-name">' . ps_h($company) . '</div>';
        echo '<div class="ps-brand-sub">' . ($psSuper && !$psAct ? 'Platform console' : 'Point of sale') . '</div></div></div>';
        echo '<nav class="ps-nav">';
        // 2026-11-03: each section is a dropdown; the section of the open page starts open (ps-theme.js remembers the rest).
        $sections = [];
        foreach (ps_nav_items() as $item) {
            $sections[(string)($item[3] ?? '')][] = $item;
        }
        $chev = '<svg class="ps-nav-chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
        $secNo = 0;
        foreach ($sections as $group => $items) {
            $links = '';
            $hasCurrent = false;
            foreach ($items as $item) {
                $active = $current === $item[0] ? ' is-on' : '';
                $hasCurrent = $hasCurrent || $active !== '';
                $links .= '<a class="' . trim($active) . '" href="' . ps_h($item[0]) . '">' . ps_icon($item[2]) . '<span>' . ps_h($item[1]) . '</span></a>';
            }
            if ($group === '') {
                echo $links;
                continue;
            }
            $secNo++;
            $id = 'ps-nav-sec-' . $secNo;
            echo '<div class="ps-nav-sec' . ($hasCurrent ? ' is-open' : '') . '" data-ps-sec="' . ps_h($group) . '"' . ($hasCurrent ? ' data-ps-current="1"' : '') . '>';
            echo '<button type="button" class="ps-nav-group" data-ps-sec-toggle aria-expanded="' . ($hasCurrent ? 'true' : 'false') . '" aria-controls="' . $id . '">'
                . '<span>' . ps_h($group) . '</span>' . $chev . '</button>';
            echo '<div class="ps-nav-items" id="' . $id . '">' . $links . '</div>';
            echo '</div>';
        }
        echo '</nav>';
        echo '<div class="ps-side-foot">';
        // Phase 9.2: only admins may switch working branch across branchies.
        $isAdminNav = ps_layout_is_admin() && !($psSuper && !$psAct);
        if ($isAdminNav) {
            echo '<a href="admin_dashboard.php?switch=1" data-ps-branch>' . ps_icon('pin') . '<span>Switch branch</span></a>';
        }
        echo '<a href="change_password.php">' . ps_icon('users') . '<span>Password</span></a>';
        echo '<a href="logout.php" data-ps-logout>' . ps_icon('users') . '<span>Log out</span></a>';
        echo '</div></aside>';
        echo '<div class="ps-main">';
        echo '<header class="ps-top"><div class="ps-top-left">';
        echo '<button type="button" class="ps-burger" data-ps-burger aria-label="Open menu">☰</button>';
        // Display home when selected is absent; do not advertise an unauthorised selected value.
        if (!$isAdminNav) {
            $homeBranch = trim((string)($session['branch'] ?? ''));
            $selBranch = trim((string)($session['selected_branch'] ?? ''));
            if ($selBranch !== '' && $homeBranch !== '' && $selBranch !== $homeBranch) {
                $branch = $homeBranch;
            } elseif ($selBranch === '' && $homeBranch !== '') {
                $branch = $homeBranch;
            }
        }
        if ($psSuper && !$psAct) {
            echo '<div class="ps-branch">Platform</div>';
        } else {
            echo '<div class="ps-branch">' . ($branch !== '' ? ps_h($branch) : 'No branch selected') . '</div>';
        }
        echo '</div>';
        $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $headerSearch = ($page === 'items_list.php') ? trim((string)($_GET['search'] ?? '')) : '';
        // Items page: filters products. POS: drives the catalogue. Elsewhere: filters the menu
        // (Enter jumps to Items for admins). Till users only ever filter the menu.
        $menuOnly = !$isAdminNav && $page !== 'ajax_pos.php';
        if ($page === 'items_list.php') {
            $searchHint = 'Search products';
        } elseif ($menuOnly) {
            $searchHint = 'Search menu';
        } else {
            $searchHint = 'Search menu · Enter = products';
        }
        echo '<label class="ps-search"><input type="search" data-ps-nav-filter data-ps-page="' . ps_h($page) . '"'
            . ($menuOnly ? ' data-ps-menu-only="1"' : '')
            . ' data-ps-loaded-search="' . ps_h($headerSearch) . '" value="' . ps_h($headerSearch) . '" placeholder="' . ps_h($searchHint) . '" aria-label="' . ps_h($searchHint) . '" autocomplete="off"></label>';
        echo '<div class="ps-top-right">';
        ps_theme_switch();
        ps_account_user_chip([
            'variant' => 'shell',
            'name' => $user,
            'sub' => $role !== '' ? $role : 'User',
            'initials_from' => $user !== '' ? $user : $company,
        ]);
        echo '</div></header>';
        echo '<div class="ps-content">';
        if ($psAct) {
            $mins = max(0, (int)floor((time() - (int)($psAct['since'] ?? time())) / 60));
            echo '<div class="ps-platform-banner" role="status"><div class="ps-platform-banner-text">'
                . '<strong>Managing ' . ps_h((string)$psAct['display']) . '</strong> as ' . ps_h(PS_PLATFORM_PROVIDER_NAME)
                . ' <span class="ps-platform-banner-meta">· ' . $mins . ' min · ' . ps_h((string)($psAct['reason'] ?? '')) . '</span>'
                . '<div class="ps-platform-banner-meta">Sales, refunds and till/cash entries are done by the company\'s own staff. Your changes are recorded under your name.</div>'
                . '</div><form method="post" action="platform_exit.php">';
            if (function_exists('psCsrfField')) {
                echo psCsrfField();
            }
            echo '<button type="submit" class="ps-btn ps-btn-sm">Exit company</button></form></div>';
        }
        if (!empty($session['ps_platform_notice'])) {
            echo '<div class="ps-alert ps-alert-warn">' . ps_h((string)$session['ps_platform_notice']) . '</div>';
            unset($_SESSION['ps_platform_notice']);
        }
    }

    function ps_scripts() {
        ps_logout_confirm_modal();
        $themeJs = dirname(__DIR__) . '/assets/ps-theme.js';
        echo '<script src="assets/ps-theme.js?v=' . rawurlencode(is_file($themeJs) ? (string)filemtime($themeJs) : '1') . '"></script>';
        echo '<script>(function(){function t(){var m=document.querySelector(\'meta[name="csrf-token"]\');return m?m.getAttribute("content"):"";}window.psCsrfToken=t;if(window.jQuery){jQuery.ajaxSetup({beforeSend:function(xhr,settings){var tok=t();if(!tok)return;xhr.setRequestHeader("X-CSRF-Token",tok);if(settings.data&&typeof settings.data==="string"&&settings.data.indexOf("csrf_token=")<0){settings.data+=(settings.data.length?"&":"")+"csrf_token="+encodeURIComponent(tok);}else if(settings.data&&typeof settings.data==="object"&&!(settings.data instanceof FormData)&&settings.data.csrf_token==null){settings.data.csrf_token=tok;}}});}var _fetch=window.fetch;if(typeof _fetch==="function"){window.fetch=function(input,init){init=init||{};var tok=t();if(tok){var headers=new Headers(init.headers||{});if(!headers.has("X-CSRF-Token"))headers.set("X-CSRF-Token",tok);init.headers=headers;if(init.method&&String(init.method).toUpperCase()==="POST"&&typeof init.body==="string"&&init.body.indexOf("csrf_token=")<0&&(headers.get("Content-Type")||"").indexOf("application/x-www-form-urlencoded")>=0){init.body+=(init.body.length?"&":"")+"csrf_token="+encodeURIComponent(tok);}if(init.body&&typeof URLSearchParams!=="undefined"&&init.body instanceof URLSearchParams&&!init.body.has("csrf_token")){init.body.append("csrf_token",tok);}}return _fetch(input,init);};}})();</script>';
    }

    function ps_close() {
        echo '</div></div></div>';
        ps_scripts();
    }
}
