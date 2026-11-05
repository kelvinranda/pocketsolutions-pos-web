<?php
/**
 * 2026-10-28 — Packs and units of measure.
 *
 * Every product keeps ONE stock unit (its base unit): stock, the stock ledger (stock_movements), sale line
 * quantity, cost and every report stay in base units. Optional packaging options ("packs") say how many base units
 * one pack holds and have their own selling price and barcode:
 *   - goods.base_unit        : label of one stock unit ("tin", "egg", "bag"); blank = "unit". The existing
 *                              goods.pack_size + unit_of_measure still say what ONE base unit contains (2 kg bag).
 *   - product_packs          : per company product (all its branches): name, factor (base units per pack),
 *                              price (company base currency; NULL = not sold as this pack), barcode, active.
 *   - online_sales.sell_*    : snapshot on each sale line sold in a pack — unit name, quantity in that unit, factor,
 *                              pack price, pack id. quantity stays the BASE quantity (sell_qty × sell_factor).
 * Whole units: a product with packs (or a base unit set) that is not sold by weight / measure must be sold, received
 * and counted in whole base units, and its pack factors must be whole numbers. Products without packs are unchanged.
 */

require_once __DIR__ . '/stock_movement.php';

if (!function_exists('psPackEnsureSchema')) {

    function psPackAddColumn(mysqli $conn, string $table, string $column, string $definition): bool
    {
        $t = mysqli_real_escape_string($conn, $table);
        $c = mysqli_real_escape_string($conn, $column);
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '$c'");
        if ($res && mysqli_num_rows($res) > 0) {
            return true;
        }
        return (bool)@mysqli_query($conn, "ALTER TABLE `$t` ADD COLUMN `$c` $definition");
    }

    /** Create / extend the tables. DDL commits a transaction, so call it before one (it is skipped inside one). */
    function psPackEnsureSchema(mysqli $conn): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        if (function_exists('stockMovementInTransaction') && stockMovementInTransaction($conn)) {
            $ok = @mysqli_query($conn, 'SELECT id FROM product_packs LIMIT 0') && @mysqli_query($conn, 'SELECT sell_unit FROM online_sales LIMIT 0')
                && @mysqli_query($conn, 'SELECT base_unit FROM goods LIMIT 0');
            if ($ok) {
                $ready = true;
            }
            return (bool)$ok;
        }
        $ok = @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_packs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(250) NOT NULL,
            product_id VARCHAR(250) NOT NULL,
            name VARCHAR(40) NOT NULL,
            factor DECIMAL(12,3) NOT NULL,
            price DECIMAL(12,2) NULL DEFAULT NULL,
            barcode VARCHAR(64) NULL DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            created_by VARCHAR(250) NOT NULL DEFAULT '',
            updated_at DATETIME NULL,
            updated_by VARCHAR(250) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_packs_name (company_name, product_id, name),
            KEY idx_product_packs_product (company_name, product_id, active),
            KEY idx_product_packs_barcode (company_name, barcode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = $ok && psPackAddColumn($conn, 'goods', 'base_unit', 'VARCHAR(40) NULL DEFAULT NULL');
        foreach ([
            'sell_unit' => 'VARCHAR(40) NULL DEFAULT NULL',
            'sell_qty' => 'DECIMAL(12,3) NULL DEFAULT NULL',
            'sell_factor' => 'DECIMAL(12,3) NULL DEFAULT NULL',
            'sell_price' => 'DECIMAL(12,2) NULL DEFAULT NULL',
            'pack_id' => 'INT UNSIGNED NULL DEFAULT NULL',
        ] as $col => $def) {
            $ok = $ok && psPackAddColumn($conn, 'online_sales', $col, $def);
        }
        if (!$ok) {
            error_log('packs: schema failed: ' . mysqli_error($conn));
            return false;
        }
        $ready = true;
        return true;
    }

    // ------------------------------------------------------------------ labels and numbers

    /** "bag" → "bags", "box" → "boxes"; short abbreviations (kg, l) are not changed. */
    function psPackPlural(string $label, int $countMilli = 2000): string
    {
        $label = trim($label);
        if ($label === '' || $countMilli === 1000 || $countMilli === -1000 || strlen($label) <= 2) {
            return $label;
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $label)) {
            return $label . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $label)) {
            return substr($label, 0, -1) . 'ies';
        }
        return $label . 's';
    }

    /** "12", "2.5" from milli units. */
    function psPackQtyText(int $milli): string
    {
        return psStockQtyPlain(psMilliToStock($milli));
    }

    /** "3 bags", "1 pack". */
    function psPackCount(int $milli, string $label): string
    {
        return psPackQtyText($milli) . ' ' . psPackPlural($label, $milli);
    }

    function psPackBaseLabel(?array $goods): string
    {
        $b = trim((string)($goods['base_unit'] ?? ''));
        return $b !== '' ? $b : 'unit';
    }

    /** Validate a unit / pack label: 1–40 letters, digits, spaces, - . / ( ). */
    function psPackValidLabel(string $raw): ?string
    {
        $s = preg_replace('/\s+/', ' ', trim($raw));
        if ($s === '' || mb_strlen($s) > 40 || !preg_match('/^[\p{L}\p{N} .\/()\-]+$/u', $s)) {
            return null;
        }
        return $s;
    }

    /** Factor (base units per pack) as milli; whole-unit products need a whole number ≥ 2. */
    function psPackParseFactor(string $raw, bool $whole): array
    {
        $raw = trim($raw);
        if (!preg_match('/^\d{1,7}(\.\d{1,3})?$/', $raw)) {
            return ['ok' => false, 'error' => 'Enter how many base units one pack holds, e.g. 6 or 10.'];
        }
        $milli = psStockMilli($raw);
        if ($milli === null || $milli <= 0) {
            return ['ok' => false, 'error' => 'Pack size must be more than zero.'];
        }
        if ($whole && $milli % 1000 !== 0) {
            return ['ok' => false, 'error' => 'This product is counted in whole units, so a pack must hold a whole number of them.'];
        }
        if ($milli === 1000) {
            return ['ok' => false, 'error' => 'A pack must hold more than one base unit (one base unit is already sold on its own).'];
        }
        if ($milli > 1000000000) {
            return ['ok' => false, 'error' => 'Pack size is too large.'];
        }
        return ['ok' => true, 'milli' => $milli, 'text' => psPackQtyText($milli)];
    }

    // ------------------------------------------------------------------ reading

    function psPackRowOut(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'product_id' => (string)$r['product_id'],
            'name' => (string)$r['name'],
            'factor_milli' => (int)psStockMilli((string)$r['factor']),
            'factor' => psStockQtyPlain((string)$r['factor']),
            'price' => $r['price'] === null ? null : number_format((float)$r['price'], 2, '.', ''),
            'barcode' => (string)($r['barcode'] ?? ''),
            'active' => (int)$r['active'] === 1,
            'sort_order' => (int)$r['sort_order'],
        ];
    }

    /** Packs of one product, largest first. */
    function psPackList(mysqli $conn, string $company, string $productId, bool $activeOnly = true): array
    {
        if (!psPackEnsureSchema($conn)) {
            return [];
        }
        $sql = 'SELECT * FROM product_packs WHERE company_name = ? AND product_id = ?' . ($activeOnly ? ' AND active = 1' : '')
            . ' ORDER BY factor DESC, sort_order, name';
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'ss', $company, $productId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = psPackRowOut($r);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }

    /** Active packs for many products: product_id => [packs], largest first. */
    function psPackListForCompany(mysqli $conn, string $company, bool $activeOnly = true): array
    {
        if (!psPackEnsureSchema($conn)) {
            return [];
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM product_packs WHERE company_name = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY product_id, factor DESC, sort_order, name');
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 's', $company);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[(string)$r['product_id']][] = psPackRowOut($r);
        }
        mysqli_stmt_close($stmt);
        return $out;
    }

    function psPackGet(mysqli $conn, string $company, int $packId, bool $forUpdate = false): ?array
    {
        if ($packId < 1 || !psPackEnsureSchema($conn)) {
            return null;
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM product_packs WHERE id = ? AND company_name = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'is', $packId, $company);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $r = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $r ? psPackRowOut($r) : null;
    }

    /** Pack by barcode within a company (active only). */
    function psPackByBarcode(mysqli $conn, string $company, string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '' || !psPackEnsureSchema($conn)) {
            return null;
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM product_packs WHERE company_name = ? AND barcode = ? AND active = 1 LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $company, $barcode);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $r = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $r ? psPackRowOut($r) : null;
    }

    /** Base unit label and whole-unit rule for a company product (first goods row of the company). */
    function psPackProductInfo(mysqli $conn, string $company, string $productId): ?array
    {
        if (!psPackEnsureSchema($conn)) {
            return null;
        }
        $stmt = mysqli_prepare($conn, 'SELECT product_id, product, base_unit, sell_by_measure, pack_size, unit_of_measure FROM goods
                                        WHERE product_id = ? AND company_name = ? ORDER BY id LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $productId, $company);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $r = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$r) {
            return null;
        }
        $packs = psPackList($conn, $company, $productId, true);
        $r['base_label'] = psPackBaseLabel($r);
        $r['measure'] = (int)($r['sell_by_measure'] ?? 0) === 1;
        $r['packs'] = $packs;
        $r['whole'] = psPackWholeUnits($r, $packs);
        return $r;
    }

    /** Whole base units only: product with packs or a base unit set, not sold by weight / measure. */
    function psPackWholeUnits(array $goods, array $packs): bool
    {
        if ((int)($goods['sell_by_measure'] ?? 0) === 1) {
            return false;
        }
        return $packs !== [] || trim((string)($goods['base_unit'] ?? '')) !== '';
    }

    // ------------------------------------------------------------------ conversion

    /**
     * Quantity entered in a unit (base unit when $pack is null, or a pack) → base units (milli).
     * Packs are counted in whole packs; whole-unit products need whole base units.
     *
     * @return array{ok:bool, error?:string, qty_milli?:int, base_milli?:int}
     */
    function psPackToBase(string $qtyRaw, ?array $pack, bool $whole, string $baseLabel = 'unit'): array
    {
        $qtyRaw = trim($qtyRaw);
        if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $qtyRaw)) {
            return ['ok' => false, 'error' => 'Enter a valid quantity.'];
        }
        $q = psStockMilli($qtyRaw);
        if ($q === null || $q <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be greater than zero.'];
        }
        if ($pack !== null) {
            if ($q % 1000 !== 0) {
                return ['ok' => false, 'error' => 'Enter whole ' . psPackPlural((string)$pack['name']) . '. For part of a ' . $pack['name'] . ', use ' . psPackPlural($baseLabel) . '.'];
            }
            $f = (int)$pack['factor_milli'];
            if ($f <= 0) {
                return ['ok' => false, 'error' => 'This pack has no valid size.'];
            }
            $base = intdiv($q, 1000) * $f;
        } else {
            $base = $q;
        }
        if ($whole && $base % 1000 !== 0) {
            return ['ok' => false, 'error' => 'This product is counted in whole ' . psPackPlural($baseLabel) . '.'];
        }
        if ($base > 1000000000000) {
            return ['ok' => false, 'error' => 'Quantity is too large.'];
        }
        return ['ok' => true, 'qty_milli' => $q, 'base_milli' => $base];
    }

    /**
     * "109 bags = 10 packs + 9 bags" (largest pack first). Without packs: "109 bags". Negative stock is shown as is.
     */
    function psPackFormatStock(int $baseMilli, array $packs, string $baseLabel): string
    {
        $plain = psPackCount($baseMilli, $baseLabel);
        $usable = array_values(array_filter($packs, static fn ($p) => !empty($p['active']) && (int)$p['factor_milli'] > 1000));
        if ($baseMilli <= 0 || !$usable) {
            return $plain;
        }
        usort($usable, static fn ($a, $b) => (int)$b['factor_milli'] <=> (int)$a['factor_milli']);
        $left = $baseMilli;
        $parts = [];
        foreach ($usable as $p) {
            $f = (int)$p['factor_milli'];
            $n = intdiv($left, $f);
            if ($n > 0) {
                $parts[] = psPackCount($n * 1000, (string)$p['name']);
                $left -= $n * $f;
            }
        }
        if (!$parts) {
            return $plain;
        }
        if ($left > 0) {
            $parts[] = psPackCount($left, $baseLabel);
        }
        return $plain . ' = ' . implode(' + ', $parts);
    }

    /** "1 pack = 10 bags (20 kg)" for a pack, using the base unit's own content (goods.pack_size + unit_of_measure). */
    function psPackDescribe(array $pack, string $baseLabel, string $contentSize = '', string $contentUnit = ''): string
    {
        $txt = '1 ' . $pack['name'] . ' = ' . psPackCount((int)$pack['factor_milli'], $baseLabel);
        $size = trim($contentSize);
        $unit = trim($contentUnit);
        if ($size !== '' && is_numeric($size) && (float)$size > 0 && $unit !== '' && strcasecmp($unit, 'each') !== 0 && strcasecmp($unit, 'ea') !== 0) {
            $total = (float)$size * ((int)$pack['factor_milli'] / 1000);
            $txt .= ' (' . rtrim(rtrim(number_format($total, 3, '.', ''), '0'), '.') . ' ' . $unit . ')';
        }
        return $txt;
    }

    // ------------------------------------------------------------------ writing

    /** Is the barcode free for this company (no product on any of its branches and no other pack uses it)? */
    function psPackBarcodeFree(mysqli $conn, string $company, string $barcode, int $exceptPackId = 0): bool
    {
        $stmt = mysqli_prepare($conn, 'SELECT 1 FROM goods WHERE company_name = ? AND barcode = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $company, $barcode);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $hit = $res && mysqli_fetch_row($res);
        mysqli_stmt_close($stmt);
        if ($hit) {
            return false;
        }
        $stmt = mysqli_prepare($conn, 'SELECT 1 FROM product_packs WHERE company_name = ? AND barcode = ? AND id <> ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ssi', $company, $barcode, $exceptPackId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $hit = $res && mysqli_fetch_row($res);
        mysqli_stmt_close($stmt);
        return !$hit;
    }

    function psPackAudit(mysqli $conn, string $action, string $company, string $productId, array $before, array $after): void
    {
        if (function_exists('psSecurityAudit')) {
            psSecurityAudit($conn, $action, [
                'summary' => 'Product ' . $productId . ' packaging changed (' . $company . ')',
                'target_type' => 'product_pack',
                'target_id' => $productId,
                'before' => $before,
                'after' => $after + ['company_name' => $company],
            ]);
        }
    }

    /** Set the base unit label of a company product (all its branch rows). Blank = "unit". */
    function psPackSetBaseUnit(mysqli $conn, string $company, string $productId, string $label, string $actor): array
    {
        if (!psPackEnsureSchema($conn)) {
            return ['ok' => false, 'error' => 'Packs are not available. Try again.'];
        }
        $label = trim($label);
        $clean = $label === '' ? '' : psPackValidLabel($label);
        if ($clean === null) {
            return ['ok' => false, 'error' => 'Base unit must be 1–40 letters or digits, e.g. tin, egg, bag.'];
        }
        $info = psPackProductInfo($conn, $company, $productId);
        if (!$info) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        $val = $clean === '' ? null : $clean;
        $stmt = mysqli_prepare($conn, 'UPDATE goods SET base_unit = ? WHERE product_id = ? AND company_name = ?');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'The base unit could not be saved.'];
        }
        mysqli_stmt_bind_param($stmt, 'sss', $val, $productId, $company);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            return ['ok' => false, 'error' => 'The base unit could not be saved.'];
        }
        psPackAudit($conn, 'PRODUCT_BASE_UNIT', $company, $productId, ['base_unit' => (string)($info['base_unit'] ?? '')], ['base_unit' => (string)$val, 'by' => $actor]);
        return ['ok' => true];
    }

    /**
     * Add (id 0) or change a pack. $data: name, factor, price ('' = not sold as this pack), barcode, sort_order.
     * Changing a pack never changes past sales: each sale line keeps its own snapshot.
     */
    function psPackSave(mysqli $conn, string $company, string $productId, int $packId, array $data, string $actor): array
    {
        if (!psPackEnsureSchema($conn)) {
            return ['ok' => false, 'error' => 'Packs are not available. Try again.'];
        }
        $info = psPackProductInfo($conn, $company, $productId);
        if (!$info) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        $name = psPackValidLabel((string)($data['name'] ?? ''));
        if ($name === null) {
            return ['ok' => false, 'error' => 'Pack name must be 1–40 letters or digits, e.g. pack, crate, case.'];
        }
        if (strcasecmp($name, $info['base_label']) === 0) {
            return ['ok' => false, 'error' => 'A pack cannot have the same name as the base unit (' . $info['base_label'] . ').'];
        }
        $whole = (int)($info['sell_by_measure'] ?? 0) !== 1;
        $f = psPackParseFactor((string)($data['factor'] ?? ''), $whole);
        if (empty($f['ok'])) {
            return $f;
        }
        $priceRaw = trim((string)($data['price'] ?? ''));
        $price = null;
        if ($priceRaw !== '') {
            if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $priceRaw)) {
                return ['ok' => false, 'error' => 'Enter the pack price like 18.00, or leave it blank if this pack is not sold.'];
            }
            $price = number_format((float)$priceRaw, 2, '.', '');
        }
        $barcode = trim((string)($data['barcode'] ?? ''));
        if ($barcode !== '') {
            if (!preg_match('/^[A-Za-z0-9\-]{3,64}$/', $barcode)) {
                return ['ok' => false, 'error' => 'Barcode must be 3–64 letters or digits.'];
            }
            if (!psPackBarcodeFree($conn, $company, $barcode, $packId)) {
                return ['ok' => false, 'error' => 'Barcode ' . $barcode . ' is already used by another product or pack.'];
            }
        }
        $sort = (int)($data['sort_order'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $factorDec = psMilliToStock((int)$f['milli']);
        $barcodeVal = $barcode === '' ? null : $barcode;
        if ($packId > 0) {
            $before = psPackGet($conn, $company, $packId);
            if (!$before || $before['product_id'] !== $productId) {
                return ['ok' => false, 'error' => 'Pack not found.'];
            }
            $stmt = mysqli_prepare($conn, 'UPDATE product_packs SET name = ?, factor = ?, price = ?, barcode = ?, sort_order = ?, updated_at = ?, updated_by = ?
                                            WHERE id = ? AND company_name = ? AND product_id = ?');
            if (!$stmt) {
                return ['ok' => false, 'error' => 'The pack could not be saved.'];
            }
            mysqli_stmt_bind_param($stmt, 'ssssississ', $name, $factorDec, $price, $barcodeVal, $sort, $now, $actor, $packId, $company, $productId);
        } else {
            $before = [];
            $stmt = mysqli_prepare($conn, 'INSERT INTO product_packs (company_name, product_id, name, factor, price, barcode, active, sort_order, created_at, created_by)
                                            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)');
            if (!$stmt) {
                return ['ok' => false, 'error' => 'The pack could not be saved.'];
            }
            mysqli_stmt_bind_param($stmt, 'ssssssiss', $company, $productId, $name, $factorDec, $price, $barcodeVal, $sort, $now, $actor);
        }
        $ok = mysqli_stmt_execute($stmt);
        $dup = mysqli_errno($conn) === 1062;
        $id = $packId > 0 ? $packId : (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            return ['ok' => false, 'error' => $dup ? 'This product already has a pack called ' . $name . '.' : 'The pack could not be saved.'];
        }
        $after = psPackGet($conn, $company, $id) ?? [];
        psPackAudit($conn, $packId > 0 ? 'PRODUCT_PACK_UPDATE' : 'PRODUCT_PACK_CREATE', $company, $productId, $before, $after + ['by' => $actor]);
        return ['ok' => true, 'id' => $id, 'pack' => $after];
    }

    /** Hide / show a pack (packs are never deleted: past sales point to them). */
    function psPackSetActive(mysqli $conn, string $company, string $productId, int $packId, bool $active, string $actor): array
    {
        $before = psPackGet($conn, $company, $packId);
        if (!$before || $before['product_id'] !== $productId) {
            return ['ok' => false, 'error' => 'Pack not found.'];
        }
        $a = $active ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($conn, 'UPDATE product_packs SET active = ?, updated_at = ?, updated_by = ? WHERE id = ? AND company_name = ?');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'The pack could not be changed.'];
        }
        mysqli_stmt_bind_param($stmt, 'issis', $a, $now, $actor, $packId, $company);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            return ['ok' => false, 'error' => 'The pack could not be changed.'];
        }
        psPackAudit($conn, $active ? 'PRODUCT_PACK_SHOW' : 'PRODUCT_PACK_HIDE', $company, $productId, $before, ['active' => $active, 'by' => $actor]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ stock entry (receiving, adjustments, counts, transfers)

    /**
     * Read a stock quantity posted with an optional unit: $unitRaw '' / 'base' = base units, else a pack id of this
     * product. Returns the base quantity as a decimal string for the ledger, plus a note for the movement.
     *
     * @return array{ok:bool, error?:string, base?:string, base_milli?:int, note?:string}
     */
    function psPackStockEntry(mysqli $conn, string $company, string $productId, string $qtyRaw, string $unitRaw): array
    {
        $info = psPackProductInfo($conn, $company, $productId);
        if (!$info) {
            // Product not found in this company: leave validation to the caller (base units, unchanged behaviour).
            $q = psStockMilli(trim($qtyRaw));
            return $q === null ? ['ok' => false, 'error' => 'Enter a valid quantity.'] : ['ok' => true, 'base' => psMilliToStock($q), 'base_milli' => $q, 'note' => ''];
        }
        $pack = null;
        $unitRaw = trim($unitRaw);
        if ($unitRaw !== '' && $unitRaw !== 'base') {
            $pack = psPackGet($conn, $company, (int)$unitRaw);
            if (!$pack || $pack['product_id'] !== $productId || !$pack['active']) {
                return ['ok' => false, 'error' => 'Choose one of this product\'s packs.'];
            }
        }
        $c = psPackToBase($qtyRaw, $pack, (bool)$info['whole'], (string)$info['base_label']);
        if (empty($c['ok'])) {
            return $c;
        }
        $note = $pack === null ? '' : 'Entered ' . psPackCount((int)$c['qty_milli'], (string)$pack['name']) . ' × ' . $pack['factor'] . ' = '
            . psPackCount((int)$c['base_milli'], (string)$info['base_label']);
        return ['ok' => true, 'base' => psMilliToStock((int)$c['base_milli']), 'base_milli' => (int)$c['base_milli'], 'note' => $note];
    }

    /** <select> options for a stock entry unit (base unit + active packs) — empty string when the product has no packs. */
    function psPackUnitOptionsHtml(array $info, string $selected = ''): string
    {
        if (empty($info['packs'])) {
            return '';
        }
        $h = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<option value="base"' . ($selected === '' || $selected === 'base' ? ' selected' : '') . '>' . $h(psPackPlural((string)$info['base_label'])) . '</option>';
        foreach ($info['packs'] as $p) {
            $html .= '<option value="' . (int)$p['id'] . '"' . ((string)$p['id'] === $selected ? ' selected' : '') . '>'
                . $h($p['name'] . ' (' . $p['factor'] . ')') . '</option>';
        }
        return $html;
    }

    /**
     * Convert posted stock rows (product ids, quantities, units; optional per-unit costs) to base units in one go.
     * Unit '' / 'base' rows are checked for whole units only. Costs entered per pack become cost per base unit
     * (pack cost ÷ units per pack, rounded half up to the cent).
     *
     * @return array{ok:bool, error?:string, qtys?:array, costs?:array, notes?:array}
     */
    function psPackConvertRows(mysqli $conn, string $company, array $productIds, array $qtys, array $units, ?array $costs = null): array
    {
        $outQ = $qtys;
        $outC = $costs ?? [];
        $notes = [];
        foreach ($qtys as $k => $qtyRaw) {
            $pid = trim((string)($productIds[$k] ?? ''));
            $qtyRaw = trim((string)$qtyRaw);
            $unit = trim((string)($units[$k] ?? ''));
            if ($pid === '' || $qtyRaw === '' || (psStockMilli($qtyRaw) ?? 0) <= 0) {
                continue; // blank / zero rows: the page's own rules apply
            }
            $info = psPackProductInfo($conn, $company, $pid);
            if (!$info || (empty($info['whole']) && ($unit === '' || $unit === 'base'))) {
                continue; // no packs, no base unit: unchanged behaviour
            }
            $e = psPackStockEntry($conn, $company, $pid, $qtyRaw, $unit);
            if (empty($e['ok'])) {
                return ['ok' => false, 'error' => trim((string)$info['product']) . ': ' . $e['error']];
            }
            $outQ[$k] = psStockQtyPlain((string)$e['base']);
            if ($unit !== '' && $unit !== 'base') {
                $pack = psPackGet($conn, $company, (int)$unit);
                if ($costs !== null && isset($costs[$k]) && trim((string)$costs[$k]) !== '' && $pack) {
                    $c = psDecimalCentsOrNull((string)$costs[$k]);
                    if ($c === null) {
                        return ['ok' => false, 'error' => trim((string)$info['product']) . ': the cost is not valid.'];
                    }
                    $f = (int)$pack['factor_milli'];
                    $outC[$k] = number_format(intdiv($c * 1000 * 2 + $f, 2 * $f) / 100, 2, '.', '');
                }
                $notes[$k] = (string)$e['note'];
            }
        }
        return ['ok' => true, 'qtys' => $outQ, 'costs' => $outC, 'notes' => $notes];
    }

    function psDecimalCentsOrNull(string $raw): ?int
    {
        $raw = trim($raw);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $raw)) {
            return null;
        }
        [$w, $f] = array_pad(explode('.', $raw, 2), 2, '');
        return (int)$w * 100 + (int)str_pad($f, 2, '0');
    }

    /**
     * Script for stock forms: window.PS_PACKS (product id => base unit + packs) and psPackFill(select, productId) /
     * auto-binding of <select data-pack-unit-for="NAME"> to the product field NAME in the same form or row.
     */
    function psPackUnitAssets(mysqli $conn, string $company): string
    {
        $map = [];
        if (psPackEnsureSchema($conn)) {
            $packs = psPackListForCompany($conn, $company, true);
            $stmt = mysqli_prepare($conn, "SELECT product_id, MAX(base_unit) AS base_unit, MAX(sell_by_measure) AS m FROM goods WHERE company_name = ? GROUP BY product_id
                                            HAVING MAX(COALESCE(base_unit, '')) <> '' OR product_id IN (SELECT product_id FROM product_packs WHERE company_name = ? AND active = 1)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $company, $company);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($r = mysqli_fetch_assoc($res))) {
                    $pid = (string)$r['product_id'];
                    $base = trim((string)$r['base_unit']) !== '' ? trim((string)$r['base_unit']) : 'unit';
                    $map[$pid] = ['base' => psPackPlural($base), 'whole' => (int)$r['m'] !== 1,
                        'packs' => array_map(static fn ($p) => ['id' => $p['id'], 'label' => $p['name'] . ' (' . $p['factor'] . ' ' . psPackPlural($base) . ')'], $packs[$pid] ?? [])];
                }
                mysqli_stmt_close($stmt);
            }
        }
        $json = json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return <<<HTML
<script>
window.PS_PACKS = {$json};
function psPackFill(sel, pid) {
    var d = window.PS_PACKS[String(pid || '')];
    var keep = sel.value;
    sel.innerHTML = '';
    if (!d || !d.packs.length) { sel.hidden = true; sel.value = ''; sel.disabled = !d; var o0 = document.createElement('option'); o0.value = 'base'; o0.textContent = d ? d.base : 'units'; sel.appendChild(o0); return; }
    sel.hidden = false; sel.disabled = false;
    var o = document.createElement('option'); o.value = 'base'; o.textContent = d.base; sel.appendChild(o);
    d.packs.forEach(function (p) { var x = document.createElement('option'); x.value = String(p.id); x.textContent = p.label; sel.appendChild(x); });
    if (Array.prototype.some.call(sel.options, function (x) { return x.value === keep; })) sel.value = keep;
}
function psPackBindAll(root) {
    (root || document).querySelectorAll('select[data-pack-unit-for]').forEach(function (sel) {
        if (sel.dataset.packBound) return;
        sel.dataset.packBound = '1';
        var scope = sel.closest('tr') || sel.form || document;
        var field = scope.querySelector('[name="' + sel.getAttribute('data-pack-unit-for') + '"]');
        if (!field) return;
        var sync = function () { psPackFill(sel, field.value); };
        field.addEventListener('change', sync);
        field.addEventListener('input', sync);
        sync();
    });
}
document.addEventListener('DOMContentLoaded', function () { psPackBindAll(document); });
</script>
HTML;
    }

    /** Server-rendered unit <select> for one product row ('' when the product has no packs). */
    function psPackUnitSelect(mysqli $conn, string $company, string $productId, string $name, string $selected = ''): string
    {
        static $packs = null;
        static $bases = [];
        if ($packs === null) {
            $packs = psPackListForCompany($conn, $company, true);
        }
        if (empty($packs[$productId])) {
            return '';
        }
        if (!array_key_exists($productId, $bases)) {
            $info = psPackProductInfo($conn, $company, $productId);
            $bases[$productId] = $info ? $info['base_label'] : 'unit';
        }
        $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" aria-label="Unit">'
            . psPackUnitOptionsHtml(['packs' => $packs[$productId], 'base_label' => $bases[$productId]], $selected) . '</select>';
        return $html;
    }

    /** "10 packs + 9 bags" for a stock figure of a product with packs ('' when it has none). */
    function psPackStockNote(mysqli $conn, string $company, string $productId, string $qty): string
    {
        static $packs = null;
        static $labels = [];
        if ($packs === null) {
            $packs = psPackListForCompany($conn, $company, true);
        }
        if (empty($packs[$productId])) {
            return '';
        }
        if (!array_key_exists($productId, $labels)) {
            $info = psPackProductInfo($conn, $company, $productId);
            $labels[$productId] = $info ? $info['base_label'] : 'unit';
        }
        $m = psStockMilli(trim($qty));
        if ($m === null) {
            return '';
        }
        $txt = psPackFormatStock($m, $packs[$productId], $labels[$productId]);
        $pos = strpos($txt, ' = ');
        return $pos === false ? '' : substr($txt, $pos + 3);
    }

    // ------------------------------------------------------------------ sale lines

    /** SQL values "sell_unit, sell_qty, sell_factor, sell_price, pack_id" of a sale line (NULLs for base-unit lines). */
    function psPackSaleLineSql(mysqli $conn, array $line): string
    {
        if (empty($line['sell_unit']) || empty($line['pack_id'])) {
            return 'NULL, NULL, NULL, NULL, NULL';
        }
        $q = static fn ($v) => "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
        return $q($line['sell_unit']) . ', ' . $q($line['sell_qty']) . ', ' . $q($line['sell_factor']) . ', ' . $q($line['sell_price']) . ', ' . (int)$line['pack_id'];
    }

    /** "1 pack (10 bags)" for a sold line (snapshot); base-unit lines: "10" (+ unit when a base unit is set). */
    function psPackLineQtyText(array $line, string $baseLabel = ''): string
    {
        $q = psStockMilli((string)($line['quantity'] ?? '')) ?? 0;
        $unit = trim((string)($line['sell_unit'] ?? ''));
        if ($unit !== '' && ($line['sell_qty'] ?? null) !== null) {
            $sq = psStockMilli((string)$line['sell_qty']) ?? 0;
            return psPackCount($sq, $unit) . ($baseLabel !== '' ? ' (' . psPackCount($q, $baseLabel) . ')' : ' (' . psPackQtyText($q) . ')');
        }
        return $baseLabel !== '' ? psPackCount($q, $baseLabel) : psPackQtyText($q);
    }
}
