<?php
/**
 * 2026-11-02 — Company role permissions.
 *
 * Each company's admin decides, per role (Till / Supervisor / Management), who may do what: see sales, add items,
 * change prices, receive goods, manage stock, see other reports, reprint or refund without another person's approval,
 * and use the mobile app. Admin always has everything. Selling at the till (POS, own receipts, own till cash, quotations,
 * refund requests) is always allowed and is not in the list.
 *
 * Stored in role_permissions (company, role, key → 0/1). A missing row means the default below, so nothing needs to be
 * set up for a company to work. Read by the web page gate and menus (includes/page_access.php), the pages themselves,
 * the mobile API (login and endpoints) and the Windows till (sent with the catalogue).
 */

const PS_ROLES = ['till' => 'Till', 'supervisor' => 'Supervisor', 'management' => 'Management'];

/**
 * key => [label, help, defaults [till, supervisor, management], pages this permission opens].
 * A page listed under two keys opens with either (items_list.php: items or prices).
 */
function psRolePermDefinitions(): array
{
    return [
        'sales_view' => ['See sales', 'Everyone\'s receipts, sales reports, dashboards, Z report, profit, cashier sales. Without it, people see only their own receipts.',
            [0, 0, 0], ['management_dashboard.php', 'sales_reports.php', 'z_report.php', 'cashier_sales.php', 'generate_pdf.php', 'profit_report.php',
                'zimra_receipts.php', 'ItemSales.php', 'InvestmentReport.php', 'income_statement.php']],
        'items_manage' => ['Add and edit items', 'Create, edit, import and deactivate items; categories, packs, barcode labels, item history.',
            [0, 1, 1], ['items_list.php', 'create_items.php', 'import_items.php', 'product_categories.php', 'product_packs.php', 'barcode_labels.php',
                'product_history.php', 'product_audit_history.php', 'generate_items_pdf.php']],
        'prices_edit' => ['Change prices', 'Change selling and cost prices (Items list, bulk prices).',
            [0, 1, 1], ['items_list.php', 'bulk_prices.php']],
        'stock_receive' => ['Receive goods', 'Goods received (GRV), suppliers, purchase orders and returns.',
            [0, 1, 1], ['grv.php', 'goods_receipt.php', 'goods_receipt_ref.php', 'goods_receipt_audit_history.php', 'grv_print.php', 'ItemReceived.php',
                'suppliers_list.php', 'create_supplier.php', 'supplier_view.php', 'supplier_audit_history.php', 'supplier_purchasing_history.php',
                'create_purchase_order.php', 'purchase_orders_list.php', 'purchase_order_view.php', 'purchase_order_audit_history.php',
                'create_purchase_return.php', 'purchase_returns_list.php', 'purchase_return_view.php', 'purchase_return_audit_history.php']],
        'stock_manage' => ['Manage stock', 'Stock take, adjustments, transfers, opening stock and stock reports.',
            [0, 0, 1], ['stock_adjustment.php', 'stock_take_sheet.php', 'stock_list.php', 'stock_movements.php', 'stock_movement.php', 'movement_list.php',
                'movement_receipt.php', 'opening_stock.php', 'inventory_control.php', 'inventory_reports.php', 'run_stocklist.php']],
        // 2026-11-03: who may see past stock takes and their results (Stock Take Records page).
        'stock_take_records' => ['Stock take records', 'See past stock takes: who counted, when, and the shortages and surpluses found.',
            [0, 0, 1], ['stock_take_records.php']],
        'cash_reports' => ['Cash reports', 'Cash management, cash movements, reconciliations, till closures.',
            [0, 0, 1], ['cash_management.php', 'cash_movements_report.php', 'cash_reconciliation_report.php', 'cash_closure_report.php',
                'cash_session_view.php', 'cash_audit_history.php']],
        'other_reports' => ['Other reports and expenses', 'Tax reports, refund reconciliation, purchasing reports, expenses, exchange rates.',
            [0, 0, 1], ['tax_reports.php', 'refund_reconciliation.php', 'refund_recon_report.php', 'refund_recon_finding.php', 'purchasing_reports.php',
                'expenses.php', 'new_expense.php', 'exchange_rates.php']],
        'customers' => ['Customers and accounts', 'Customers, credit accounts and limits, account payments and statements.',
            [0, 1, 1], ['customer_accounts_list.php', 'customer_account_view.php', 'customers.php', 'customers_list.php', 'create_customer.php',
                'customer_view.php', 'create_customer_account.php', 'create_customer_account_payment.php', 'customer_account_payments.php',
                'customer_account_payment_view.php', 'customer_account_payment_allocate.php', 'customer_account_statement.php',
                'customer_receivables_ageing.php', 'customer_credit_control.php', 'customer_collection_activity.php', 'customer_audit_history.php',
                'customer_account_audit_history.php', 'customer_account_payment_audit_history.php']],
        'zimra_day' => ['ZIMRA fiscal day', 'Open and close the ZIMRA fiscal day.', [0, 1, 1], ['zimra_day.php']],
        'reprint_self' => ['Reprint without approval', 'Reprint receipts and slips without another person\'s approval. Without it, a supervisor or admin approves each copy.',
            [0, 1, 1], []],
        'refund_self' => ['Refund without approval', 'Approve and process their own refund requests. Without it, another supervisor, manager or admin must approve.',
            [0, 1, 1], []],
        'mobile_app' => ['Use the mobile app', 'Sign in to the Pockets POS phone app. Admins can always sign in.', [0, 0, 0], []],
    ];
}

/** Session / users.level → till | supervisor | management | admin. */
function psRolePermRole(string $level): string
{
    $l = strtolower(trim($level));
    if ($l === 'admin' || $l === 'administrator') {
        return 'admin';
    }
    if ($l === 'supervisor' || $l === 'management') {
        return $l;
    }
    if ($l === 'manager') {
        return 'management';
    }
    return 'till';
}

/** A DB handle for permission reads when the caller has none (page gate, menus). */
function psRolePermConn(?mysqli $conn = null): ?mysqli
{
    if ($conn) {
        return $conn;
    }
    static $own = false;
    if ($own === false) {
        $own = null;
        if (function_exists('psConfigResolve')) {
            $cfg = psConfigResolve();
            if (($cfg['dbname'] ?? '') !== '') {
                mysqli_report(MYSQLI_REPORT_OFF);
                $c = @mysqli_connect($cfg['servername'], $cfg['username'], $cfg['password'], $cfg['dbname']);
                if ($c) {
                    mysqli_set_charset($c, 'utf8mb4');
                    $own = $c;
                }
            }
        }
    }
    return $own;
}

function psRolePermEnsureSchema(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $ok = (bool)mysqli_query($conn, "CREATE TABLE IF NOT EXISTS role_permissions (
        company_name VARCHAR(250) NOT NULL,
        role VARCHAR(20) NOT NULL,
        perm_key VARCHAR(40) NOT NULL,
        allowed TINYINT(1) NOT NULL,
        updated_by VARCHAR(250) NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (company_name, role, perm_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ok;
}

/** The company's matrix: [role][key] => bool (defaults filled in). Cached per request. */
function psRolePermMatrix(?mysqli $conn, string $company): array
{
    static $cache = [];
    $company = trim($company);
    if (isset($cache[$company])) {
        return $cache[$company];
    }
    $defs = psRolePermDefinitions();
    $m = [];
    $i = 0;
    foreach (PS_ROLES as $role => $label) {
        foreach ($defs as $key => $d) {
            $m[$role][$key] = (bool)$d[2][$i];
        }
        $i++;
    }
    $conn = psRolePermConn($conn);
    if ($conn && $company !== '') {
        $t = mysqli_query($conn, "SHOW TABLES LIKE 'role_permissions'");
        if ($t && mysqli_num_rows($t) > 0) {
            $stmt = mysqli_prepare($conn, 'SELECT role, perm_key, allowed FROM role_permissions WHERE company_name = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $company);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($r = mysqli_fetch_assoc($res)) {
                    if (isset($m[$r['role']][$r['perm_key']])) {
                        $m[$r['role']][$r['perm_key']] = (int)$r['allowed'] === 1;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    return $cache[$company] = $m;
}

/** May this level (in this company) do $key? Admin: always. */
function psRolePermAllowed(string $level, string $key, ?string $company = null, ?mysqli $conn = null): bool
{
    $role = psRolePermRole($level);
    if ($role === 'admin') {
        return true;
    }
    if ($company === null) {
        $company = (string)($_SESSION['the_company'] ?? '');
    }
    $m = psRolePermMatrix($conn, $company);
    return !empty($m[$role][$key]);
}

/** The signed-in web user. */
function psRolePermSession(string $key, ?mysqli $conn = null): bool
{
    return psRolePermAllowed((string)($_SESSION['level'] ?? ''), $key, null, $conn);
}

/** Extra pages (beyond selling) a level may open in its company. */
function psRolePermPagesFor(string $level, ?string $company = null): array
{
    $pages = [];
    foreach (psRolePermDefinitions() as $key => $d) {
        if ($d[3] && psRolePermAllowed($level, $key, $company)) {
            $pages = array_merge($pages, $d[3]);
        }
    }
    return array_values(array_unique($pages));
}

/** Admin only: save the whole matrix from the settings form ($posted[role][key] = '1'); others unticked = 0. */
function psRolePermSave(mysqli $conn, array $posted, string $actor): array
{
    if (psRolePermRole((string)($_SESSION['level'] ?? '')) !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change role permissions.'];
    }
    $company = trim((string)($_SESSION['the_company'] ?? ''));
    if ($company === '' || !psRolePermEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The permissions could not be saved.'];
    }
    $before = psRolePermMatrix($conn, $company);
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, 'INSERT INTO role_permissions (company_name, role, perm_key, allowed, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The permissions could not be saved.'];
    }
    $changes = [];
    mysqli_begin_transaction($conn);
    foreach (PS_ROLES as $role => $label) {
        foreach (psRolePermDefinitions() as $key => $d) {
            $v = !empty($posted[$role][$key]) ? 1 : 0;
            mysqli_stmt_bind_param($stmt, 'sssiss', $company, $role, $key, $v, $actor, $now);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_rollback($conn);
                mysqli_stmt_close($stmt);
                return ['ok' => false, 'error' => 'The permissions could not be saved.'];
            }
            if ((bool)$v !== (bool)($before[$role][$key] ?? false)) {
                $changes[] = $label . ': ' . $d[0] . ' ' . ($v ? 'on' : 'off');
            }
        }
    }
    mysqli_commit($conn);
    mysqli_stmt_close($stmt);
    if ($changes && function_exists('psSecurityAudit')) {
        psSecurityAudit($conn, 'SETTING_CHANGE', ['target_type' => 'setting', 'target_id' => 'role_permissions',
            'summary' => 'Role permissions changed: ' . implode('; ', $changes)]);
    }
    return ['ok' => true, 'changes' => count($changes)];
}

/** Admin only: remove the company's rows so every permission is back to its default. */
function psRolePermReset(mysqli $conn, string $actor): array
{
    if (psRolePermRole((string)($_SESSION['level'] ?? '')) !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change role permissions.'];
    }
    $company = trim((string)($_SESSION['the_company'] ?? ''));
    if ($company === '' || !psRolePermEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The permissions could not be reset.'];
    }
    $stmt = mysqli_prepare($conn, 'DELETE FROM role_permissions WHERE company_name = ?');
    mysqli_stmt_bind_param($stmt, 's', $company);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok && function_exists('psSecurityAudit')) {
        psSecurityAudit($conn, 'SETTING_CHANGE', ['target_type' => 'setting', 'target_id' => 'role_permissions', 'summary' => 'Role permissions reset to defaults']);
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The permissions could not be reset.'];
}

/** The matrix as plain data for the Windows till / mobile API: {role: {key: bool}}. */
function psRolePermExport(?mysqli $conn, string $company): array
{
    return psRolePermMatrix($conn, $company);
}

/** Page guard: signed in and allowed $key (admin always), else back to the till. Replaces admin-only redirects. */
function psRolePermRequire(string $key, string $fallback = 'ajax_pos.php'): void
{
    if (function_exists('psBranchRequireAuthRedirect')) {
        psBranchRequireAuthRedirect();
    }
    if (!psRolePermSession($key)) {
        header('Location: ' . $fallback);
        exit;
    }
}
