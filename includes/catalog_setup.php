<?php
/**
 * Phase 19 — Catalogue set-up lists.
 *
 *  - product_categories: per company. kind 'category' (product classification, used in reports)
 *    or 'button' (POS button group shown on the till). Managed by the company admin
 *    (or a Super Admin managing the company) on product_categories.php.
 *    goods.category / goods.button_category keep storing the NAME (existing data, reports, imports);
 *    renaming a category here renames it on that company's products too.
 *  - units_of_measure: one shared list for all companies (g, kg, ml, l, gal, each ...), managed by
 *    Super Admins on platform_units.php. goods.unit_of_measure stores the unit CODE; goods.pack_size
 *    stores the number (e.g. 500 + ml = 500 ml).
 */

if (!function_exists('psCatalogEnsureSchema')) {

    define('PS_CAT_KINDS', ['category' => 'Category', 'button' => 'Button group']);

    function psCatalogEnsureSchema(mysqli $conn): bool
    {
        static $done = false;
        if ($done) {
            return true;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $ok = @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(250) NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'category',
            name VARCHAR(100) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_categories (company_name, kind, name),
            KEY idx_product_categories_company (company_name, kind, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $ok = $ok && @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS units_of_measure (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(16) NOT NULL,
            name VARCHAR(60) NOT NULL,
            dimension VARCHAR(16) NOT NULL DEFAULT 'count',
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_units_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        if (!$ok) {
            error_log('catalog: schema failed: ' . mysqli_error($conn));
            return false;
        }
        // Phase 20: button colour, product variants and sell-by-measure.
        psCatalogAddColumn($conn, 'product_categories', 'color', 'VARCHAR(7) NULL DEFAULT NULL');
        psCatalogAddColumn($conn, 'goods', 'sell_by_measure', 'TINYINT(1) NOT NULL DEFAULT 0');
        psCatalogAddColumn($conn, 'goods', 'variant_group', 'VARCHAR(40) NULL DEFAULT NULL');
        psCatalogAddColumn($conn, 'goods', 'variant_base', 'VARCHAR(250) NULL DEFAULT NULL');
        psCatalogAddColumn($conn, 'goods', 'variant_label', 'VARCHAR(120) NULL DEFAULT NULL');
        $res = @mysqli_query($conn, 'SELECT COUNT(*) n FROM units_of_measure');
        $n = $res ? (int)(mysqli_fetch_assoc($res)['n'] ?? 0) : 0;
        if ($n === 0) {
            foreach (psUnitDefaults() as $i => $u) {
                $stmt = mysqli_prepare($conn, 'INSERT IGNORE INTO units_of_measure (code, name, dimension, sort_order) VALUES (?, ?, ?, ?)');
                $sort = ($i + 1) * 10;
                mysqli_stmt_bind_param($stmt, 'sssi', $u[0], $u[1], $u[2], $sort);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        $done = true;
        return true;
    }

    function psCatalogAddColumn(mysqli $conn, string $table, string $column, string $definition): void
    {
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
        if ($res && mysqli_num_rows($res) === 0 && !@mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            error_log("catalog: could not add $table.$column: " . mysqli_error($conn));
        }
    }

    /** Units that can be sold by an entered amount (weight / volume / length / area). */
    function psUnitIsMeasurable(mysqli $conn, string $code): bool
    {
        foreach (psUnitList($conn, false) as $u) {
            if ((string)$u['code'] === $code) {
                return in_array((string)$u['dimension'], ['weight', 'volume', 'length', 'area'], true);
            }
        }
        return false;
    }

    /** Button colour for the till: '#rrggbb' or '' (POS picks one). */
    function psCatNormalizeColor(string $c): string
    {
        $c = trim($c);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '';
    }

    /** Update colour / order of an entry. */
    function psCatSetLook(mysqli $conn, int $id, string $color, int $sort): array
    {
        $row = psCatGet($conn, $id);
        if (!$row) {
            return ['ok' => false, 'error' => 'Not found.'];
        }
        $c = psCatNormalizeColor($color);
        $cv = $c === '' ? null : $c;
        $stmt = mysqli_prepare($conn, 'UPDATE product_categories SET color = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'sii', $cv, $sort, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return ['ok' => true];
    }

    /** Starting units: [code, name, dimension]. */
    function psUnitDefaults(): array
    {
        return [
            ['ea', 'Each (single item)', 'count'],
            ['pack', 'Pack', 'count'],
            ['box', 'Box', 'count'],
            ['ctn', 'Carton', 'count'],
            ['dozen', 'Dozen', 'count'],
            ['pair', 'Pair', 'count'],
            ['set', 'Set', 'count'],
            ['bottle', 'Bottle', 'count'],
            ['can', 'Can', 'count'],
            ['bag', 'Bag', 'count'],
            ['roll', 'Roll', 'count'],
            ['mg', 'Milligrams', 'weight'],
            ['g', 'Grams', 'weight'],
            ['kg', 'Kilograms', 'weight'],
            ['t', 'Tonnes', 'weight'],
            ['ml', 'Millilitres', 'volume'],
            ['l', 'Litres', 'volume'],
            ['gal', 'Gallons', 'volume'],
            ['mm', 'Millimetres', 'length'],
            ['cm', 'Centimetres', 'length'],
            ['m', 'Metres', 'length'],
        ];
    }

    function psUnitDimensions(): array
    {
        return ['count' => 'Count', 'weight' => 'Weight', 'volume' => 'Volume', 'length' => 'Length', 'area' => 'Area', 'time' => 'Time'];
    }

    // ---------------------------------------------------------------- units

    /** @return array<int,array<string,mixed>> */
    function psUnitList(mysqli $conn, bool $activeOnly = true): array
    {
        if (!psCatalogEnsureSchema($conn)) {
            return [];
        }
        $res = mysqli_query($conn, 'SELECT * FROM units_of_measure' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name');
        return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    }

    /** Find a unit by code or name, case-insensitive (for imports). Returns the code or ''. */
    function psUnitResolve(mysqli $conn, string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }
        foreach (psUnitList($conn, true) as $u) {
            if (strtolower((string)$u['code']) === $raw || strtolower((string)$u['name']) === $raw) {
                return (string)$u['code'];
            }
        }
        return '';
    }

    function psUnitSave(mysqli $conn, int $id, string $code, string $name, string $dimension, int $sort): array
    {
        psCatalogEnsureSchema($conn);
        $code = trim($code);
        $name = preg_replace('/\s+/', ' ', trim($name));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,15}$/', $code)) {
            return ['ok' => false, 'error' => 'Code must be 1–16 letters or numbers, starting with a letter (e.g. kg, ml, ctn).'];
        }
        if ($name === '' || mb_strlen($name) > 60) {
            return ['ok' => false, 'error' => 'Name is required (up to 60 characters).'];
        }
        if (!array_key_exists($dimension, psUnitDimensions())) {
            $dimension = 'count';
        }
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, 'SELECT code FROM units_of_measure WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$row) {
                return ['ok' => false, 'error' => 'Unit not found.'];
            }
            // The code is stored on products, so it is never changed — only name / group / order.
            $stmt = mysqli_prepare($conn, 'UPDATE units_of_measure SET name = ?, dimension = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'ssii', $name, $dimension, $sort, $id);
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO units_of_measure (code, name, dimension, sort_order) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'sssi', $code, $name, $dimension, $sort);
        }
        $ok = mysqli_stmt_execute($stmt);
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            return ['ok' => false, 'error' => $errno === 1062 ? 'A unit with that code already exists.' : 'The unit could not be saved.'];
        }
        return ['ok' => true];
    }

    function psUnitSetActive(mysqli $conn, int $id, bool $active): void
    {
        $a = $active ? 1 : 0;
        $stmt = mysqli_prepare($conn, 'UPDATE units_of_measure SET active = ?, updated_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $a, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    /** Products using each unit code (all companies). */
    function psUnitUsage(mysqli $conn): array
    {
        $out = [];
        $res = @mysqli_query($conn, 'SELECT unit_of_measure u, COUNT(*) n FROM goods GROUP BY unit_of_measure');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[strtolower((string)$r['u'])] = (int)$r['n'];
        }
        return $out;
    }

    /** "500 ml", "1 Each" ... for display. */
    function psPackLabel($packSize, $unitCode): string
    {
        $p = trim((string)$packSize);
        $u = trim((string)$unitCode);
        return trim($p . ' ' . $u);
    }

    // ---------------------------------------------------------------- categories

    function psCatCompany(): string
    {
        return session_status() === PHP_SESSION_ACTIVE ? trim((string)($_SESSION['the_company'] ?? '')) : '';
    }

    /**
     * Seed a company's lists from the values its products already use (once per request; INSERT IGNORE).
     */
    function psCatBackfill(mysqli $conn, string $company): void
    {
        static $done = [];
        if ($company === '' || isset($done[$company]) || !psCatalogEnsureSchema($conn)) {
            return;
        }
        $done[$company] = true;
        $hasCompany = false;
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM goods LIKE 'company_name'");
        if ($res && mysqli_num_rows($res) > 0) {
            $hasCompany = true;
        }
        if (!$hasCompany) {
            return;
        }
        foreach (['category' => 'category', 'button' => 'button_category'] as $kind => $col) {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT IGNORE INTO product_categories (company_name, kind, name, created_by)
                 SELECT DISTINCT ?, ?, LEFT(TRIM($col), 100), 'existing products' FROM goods
                 WHERE company_name = ? AND TRIM($col) <> ''"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sss', $company, $kind, $company);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }

    /** @return array<int,array<string,mixed>> rows of the company's list */
    function psCatList(mysqli $conn, string $kind, bool $activeOnly = true, ?string $company = null): array
    {
        $company = $company ?? psCatCompany();
        if ($company === '' || !array_key_exists($kind, PS_CAT_KINDS) || !psCatalogEnsureSchema($conn)) {
            return [];
        }
        psCatBackfill($conn, $company);
        $sql = 'SELECT * FROM product_categories WHERE company_name = ? AND kind = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort_order, name';
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $company, $kind);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        mysqli_stmt_close($stmt);
        return $rows;
    }

    /** Names only (for dropdowns). */
    function psCatNames(mysqli $conn, string $kind, ?string $company = null): array
    {
        return array_map(static fn ($r) => (string)$r['name'], psCatList($conn, $kind, true, $company));
    }

    /** Is $name an active entry of the company's list? Returns the stored spelling or ''. */
    function psCatMatch(mysqli $conn, string $kind, string $name, ?string $company = null): string
    {
        $name = trim($name);
        foreach (psCatNames($conn, $kind, $company) as $n) {
            if (strcasecmp($n, $name) === 0) {
                return $n;
            }
        }
        return '';
    }

    function psCatValidateName(string $name): array
    {
        $name = preg_replace('/\s+/', ' ', trim($name));
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'error' => 'Name is required (up to 100 characters).'];
        }
        return ['ok' => true, 'name' => $name];
    }

    function psCatAdd(mysqli $conn, string $kind, string $name, ?string $company = null): array
    {
        $company = $company ?? psCatCompany();
        if ($company === '' || !array_key_exists($kind, PS_CAT_KINDS) || !psCatalogEnsureSchema($conn)) {
            return ['ok' => false, 'error' => 'The list could not be changed.'];
        }
        $v = psCatValidateName($name);
        if (!$v['ok']) {
            return $v;
        }
        $actor = (string)($_SESSION['logger'] ?? 'import');
        $stmt = mysqli_prepare($conn, 'INSERT INTO product_categories (company_name, kind, name, created_by) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'ssss', $company, $kind, $v['name'], $actor);
        $ok = mysqli_stmt_execute($stmt);
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            return ['ok' => false, 'error' => $errno === 1062 ? '"' . $v['name'] . '" is already in the list.' : 'It could not be saved.'];
        }
        return ['ok' => true, 'name' => $v['name']];
    }

    function psCatGet(mysqli $conn, int $id, ?string $company = null): ?array
    {
        $company = $company ?? psCatCompany();
        $stmt = mysqli_prepare($conn, 'SELECT * FROM product_categories WHERE id = ? AND company_name = ?');
        mysqli_stmt_bind_param($stmt, 'is', $id, $company);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }

    /** Rename an entry and the same value on this company's products (one transaction). */
    function psCatRename(mysqli $conn, int $id, string $newName): array
    {
        $row = psCatGet($conn, $id);
        if (!$row) {
            return ['ok' => false, 'error' => 'Not found.'];
        }
        $v = psCatValidateName($newName);
        if (!$v['ok']) {
            return $v;
        }
        if ($v['name'] === $row['name']) {
            return ['ok' => true, 'products' => 0];
        }
        $col = $row['kind'] === 'button' ? 'button_category' : 'category';
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, 'UPDATE product_categories SET name = ?, updated_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $v['name'], $id);
        $ok = mysqli_stmt_execute($stmt);
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => $errno === 1062 ? '"' . $v['name'] . '" is already in the list.' : 'It could not be renamed.'];
        }
        $stmt = mysqli_prepare($conn, "UPDATE goods SET $col = ? WHERE company_name = ? AND TRIM($col) = ?");
        $old = (string)$row['name'];
        $company = (string)$row['company_name'];
        mysqli_stmt_bind_param($stmt, 'sss', $v['name'], $company, $old);
        $ok = mysqli_stmt_execute($stmt);
        $n = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Products could not be updated.'];
        }
        mysqli_commit($conn);
        return ['ok' => true, 'products' => $n, 'name' => $v['name'], 'old' => $old];
    }

    function psCatSetActive(mysqli $conn, int $id, bool $active): array
    {
        $row = psCatGet($conn, $id);
        if (!$row) {
            return ['ok' => false, 'error' => 'Not found.'];
        }
        $a = $active ? 1 : 0;
        $stmt = mysqli_prepare($conn, 'UPDATE product_categories SET active = ?, updated_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $a, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return ['ok' => true, 'name' => $row['name']];
    }

    /** Products per name for the company: ['category' => [name => n], 'button' => [...]]. */
    function psCatUsage(mysqli $conn, ?string $company = null): array
    {
        $company = $company ?? psCatCompany();
        $out = ['category' => [], 'button' => []];
        foreach (['category' => 'category', 'button' => 'button_category'] as $kind => $col) {
            $stmt = mysqli_prepare($conn, "SELECT TRIM($col) v, COUNT(*) n FROM goods WHERE company_name = ? GROUP BY TRIM($col)");
            if (!$stmt) {
                continue;
            }
            mysqli_stmt_bind_param($stmt, 's', $company);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $out[$kind][strtolower((string)$r['v'])] = (int)$r['n'];
            }
            mysqli_stmt_close($stmt);
        }
        return $out;
    }

    /**
     * Imports: use the list spelling when the name exists (case-insensitive), otherwise add it.
     * Returns the name to store.
     */
    function psCatEnsure(mysqli $conn, string $kind, string $name, ?string $company = null): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $company = $company ?? psCatCompany();
        if ($company === '') {
            return $name;
        }
        $m = psCatMatch($conn, $kind, $name, $company);
        if ($m !== '') {
            return $m;
        }
        // Inactive entry with the same name: reactivate rather than duplicate.
        foreach (psCatList($conn, $kind, false, $company) as $r) {
            if (strcasecmp((string)$r['name'], $name) === 0) {
                psCatSetActive($conn, (int)$r['id'], true);
                return (string)$r['name'];
            }
        }
        $r = psCatAdd($conn, $kind, $name, $company);
        return $r['ok'] ? (string)$r['name'] : $name;
    }
}
