<?php
/**
 * 2026-11-02 — Expenses belong to a company.
 * The expenses table had no branch or company column, so every company saw every company's expenses
 * (Expenses list, Income Statement, accounting export). New expenses store the signed-in company; all reads
 * filter on it. Older rows have no company and are hidden until an admin assigns them, e.g.
 *   UPDATE expenses SET company_name = 'YOUR COMPANY' WHERE company_name IS NULL;
 */

function psExpenseEnsureCompanyColumn(mysqli $conn): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $t = @mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
    if (!$t || mysqli_num_rows($t) === 0) {
        return $ok = false;
    }
    $c = @mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'company_name'");
    if ($c && mysqli_num_rows($c) > 0) {
        return $ok = true;
    }
    $ok = (bool)@mysqli_query($conn, 'ALTER TABLE expenses ADD COLUMN company_name VARCHAR(250) NULL, ADD KEY idx_expenses_company (company_name)');
    return $ok;
}

function psExpenseCompany(): string
{
    return trim((string)($_SESSION['the_company'] ?? ''));
}

/** WHERE fragment limiting expenses to the signed-in company ('0' = nothing when there is no company). */
function psExpenseCompanySql(mysqli $conn, string $alias = ''): string
{
    $co = psExpenseCompany();
    if ($co === '' || !psExpenseEnsureCompanyColumn($conn)) {
        return '0';
    }
    $col = ($alias !== '' ? $alias . '.' : '') . 'company_name';
    return $col . " = '" . mysqli_real_escape_string($conn, $co) . "'";
}
