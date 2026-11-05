<?php

/**
 * Product spreadsheet import.
 * Identity: product_id + selected form branch (sheet Branch never overrides).
 * goods.reorder_level is optional and is read only when a column heading
 * matches "Reorder Level". The older goods.re_order_level column is never written.
 * Status: new rows ACTIVE; existing status is never changed by import.
 * Stock: goods.quantity is never written.
 * 2026-11-02: optional "Opening Stock" column — for NEW items only it becomes the opening stock balance (stock ledger
 * OPENING_BALANCE). For items that already exist it is ignored and listed in the result (use a stock take instead),
 * so importing the same file twice never doubles stock.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/stock_movement.php';
require_once __DIR__ . '/product_master.php';
require_once __DIR__ . '/barcode.php';
require_once __DIR__ . '/catalog_setup.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function psImportNormalizeHeader($value): string
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value === null ? '' : $value;
}

function psImportColumnLetter(int $index): string
{
    $letter = '';
    $n = $index + 1;
    while ($n > 0) {
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = intdiv($n, 26);
    }
    return $letter;
}

function psImportScalar($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_float($value)) {
        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        return $text === '' ? '0' : $text;
    }
    return (string)$value;
}

function psImportReadDelimited(string $file): ?array
{
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (strncmp($raw, "PK", 2) === 0 || strncmp($raw, "\xD0\xCF", 2) === 0) {
        return null;
    }
    $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw);
    $sample = strtok($raw, "\r\n");
    if ($sample === false || strpos($sample, "\t") === false) {
        return null;
    }
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    if (!is_array($lines)) {
        return null;
    }
    $rows = [];
    $number = 1;
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $cells = str_getcsv($line, "\t");
        $row = [];
        foreach ($cells as $index => $cell) {
            $row[psImportColumnLetter((int)$index)] = $cell;
        }
        $rows[$number] = $row;
        $number++;
    }
    return $rows === [] ? null : $rows;
}

function psImportReadRows(string $file): ?array
{
    if ($file === '' || !is_file($file)) {
        return null;
    }
    try {
        $spreadsheet = IOFactory::load($file);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        return is_array($rows) ? $rows : null;
    } catch (Throwable $e) {
        return psImportReadDelimited($file);
    }
}

function psImportHeaderColumns(array $rows): array
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (psImportNormalizeHeader($row['A'] ?? '') !== 'product id') {
            continue;
        }
        $columns = [];
        foreach ($row as $letter => $value) {
            $name = psImportNormalizeHeader($value);
            if ($name === '' || isset($columns[$name])) {
                continue;
            }
            $columns[$name] = (string)$letter;
        }
        return $columns;
    }
    // Positional fallback for sheets with no "Product ID" header row (legacy six-column).
    // Named headers (preferred) map Category and Button Category independently.
    return [
        'product id' => 'A',
        'product' => 'B',
        'price' => 'C',
        'cost price' => 'D',
        'button category' => 'E',
        'pack size' => 'F',
    ];
}

function psImportCell(array $row, ?string $letter): string
{
    if ($letter === null || $letter === '' || !array_key_exists($letter, $row)) {
        return '';
    }
    return psImportScalar($row[$letter]);
}

/**
 * @return array{inserted:int,updated:int,skipped:int,rejected:int,validation_errors:int}
 */
function psImportEmptyStats(): array
{
    return [
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'rejected' => 0,
        'validation_errors' => 0,
        'opening_set' => 0,
        'opening_ignored' => [],
    ];
}

function psImportEchoError(string $message): void
{
    echo "<div class='error'>Error: " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</div>";
}

/**
 * Parse sheet rows into product payloads. Invalid money/reorder rows are counted
 * as validation errors and omitted from the returned list.
 *
 * @return array{products:array<int,array<string,mixed>>,stats:array{inserted:int,updated:int,skipped:int,rejected:int,validation_errors:int}}
 */
function psImportProductsFromRows(array $rows): array
{
    $columns = psImportHeaderColumns($rows);
    $reorderLetter = $columns['reorder level'] ?? null;
    $openingLetter = $columns['opening stock'] ?? ($columns['opening qty'] ?? ($columns['opening quantity'] ?? ($columns['opening balance'] ?? null)));
    $products = [];
    $stats = psImportEmptyStats();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (psImportNormalizeHeader($row['A'] ?? '') === 'product id') {
            continue;
        }
        $productId = trim(psImportCell($row, $columns['product id'] ?? null));
        $categoryValue = null;
        if (array_key_exists('category', $columns)) {
            $categoryValue = psNormalizeCategoryField(psImportCell($row, $columns['category']));
        }
        $buttonCategoryValue = null;
        if (array_key_exists('button category', $columns)) {
            $buttonCategoryValue = psNormalizeCategoryField(psImportCell($row, $columns['button category']));
        }
        $packSize = null;
        if (array_key_exists('pack size', $columns)) {
            // Blank pack size is stored as empty string when the column is present.
            $packSize = psImportCell($row, $columns['pack size']);
        }
        // Phase 19: optional "Unit" (or "Unit of Measure") column — a code or name from Units of Measure.
        $unitValue = null;
        $unitLetter = $columns['unit'] ?? ($columns['unit of measure'] ?? ($columns['uom'] ?? null));
        if ($unitLetter !== null) {
            $unitValue = trim(psImportCell($row, $unitLetter));
        }
        $barcodeValue = null;
        $barcodePresent = array_key_exists('barcode', $columns);
        if ($barcodePresent) {
            // Keep as text string — do not cast through float (leading zeros).
            $barcodeValue = psImportCell($row, $columns['barcode']);
        }
        $product = [
            'product_id' => $productId,
            'product' => psImportCell($row, $columns['product'] ?? null),
            'price' => psImportCell($row, $columns['price'] ?? null),
            'cost_price' => psImportCell($row, $columns['cost price'] ?? null),
            'product_present' => array_key_exists('product', $columns),
            'price_present' => array_key_exists('price', $columns),
            'cost_present' => array_key_exists('cost price', $columns),
            // Independent fields — never copy Category into Button Category or vice versa.
            'category' => $categoryValue,
            'button_category' => $buttonCategoryValue,
            'pack_size' => $packSize,
            'unit' => $unitValue,
            'barcode_present' => $barcodePresent,
            'barcode' => $barcodeValue,
            'reorder_apply' => $reorderLetter !== null,
            'reorder_level' => null,
            'opening' => $openingLetter !== null ? trim(psImportCell($row, $openingLetter)) : '',
        ];
        if ($product['product'] === '' && $product['price'] === '' && $product['cost_price'] === '' && $productId === ''
            && ($product['category'] === null || $product['category'] === '')
            && ($product['button_category'] === null || $product['button_category'] === '')
            && ($product['pack_size'] === null || $product['pack_size'] === '')
            && (!$barcodePresent || $barcodeValue === '')) {
            continue;
        }
        if ($barcodePresent) {
            $bcCheck = psNormalizeBarcode($barcodeValue);
            if (!$bcCheck['ok']) {
                $stats['validation_errors']++;
                psImportEchoError((string)$bcCheck['error'] . ' ' . ($productId === '' ? 'This row' : $productId) . ' was not changed.');
                continue;
            }
            $product['barcode'] = $bcCheck['value'];
        }
        $rowLabel = $productId === '' ? 'This row' : $productId;
        if ($product['price_present'] === true) {
            $priceCheck = psValidateProductMoney($product['price'], 'Selling price');
            if (!$priceCheck['ok']) {
                $stats['validation_errors']++;
                psImportEchoError((string)$priceCheck['error'] . ' ' . $rowLabel . ' was not changed.');
                continue;
            }
            $product['price'] = $priceCheck['value'];
        }
        if ($product['cost_present'] === true) {
            $costCheck = psValidateProductMoney($product['cost_price'], 'Cost price');
            if (!$costCheck['ok']) {
                $stats['validation_errors']++;
                psImportEchoError((string)$costCheck['error'] . ' ' . $rowLabel . ' was not changed.');
                continue;
            }
            $product['cost_price'] = $costCheck['value'];
        }
        if ($reorderLetter !== null) {
            $raw = array_key_exists($reorderLetter, $row) ? $row[$reorderLetter] : null;
            $text = psImportScalar($raw);
            if (trim($text) === '') {
                $product['reorder_level'] = null;
            } else {
                $parsed = psParseReorderLevel($text);
                if (!$parsed['ok']) {
                    $stats['validation_errors']++;
                    psImportEchoError((string)$parsed['error'] . ' ' . $rowLabel . ' was not changed.');
                    continue;
                }
                $product['reorder_level'] = $parsed['value'];
            }
        }
        $products[] = $product;
    }
    return ['products' => $products, 'stats' => $stats];
}

function psImportBindAndExecute(mysqli_stmt $stmt, string $types, array $values): bool
{
    $params = [$types];
    foreach ($values as $index => $value) {
        $params[] = &$values[$index];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $params)) {
        return false;
    }
    return mysqli_stmt_execute($stmt);
}

function psImportBranchFlagColumn(mysqli $conn): ?string
{
    static $done = false;
    static $found = null;
    if ($done) {
        return $found;
    }
    $done = true;
    $res = mysqli_query($conn, 'SHOW COLUMNS FROM branchies');
    if (!$res) {
        error_log('product import branch list failed');
        return null;
    }
    $lower = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '' && preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            $lower[strtolower($field)] = $field;
        }
    }
    foreach (['active', 'is_active', 'branch_active', 'enabled', 'is_enabled', 'inactive', 'is_inactive', 'status'] as $name) {
        if (isset($lower[$name])) {
            $found = $lower[$name];
            return $found;
        }
    }
    return null;
}

function psImportBranchRowSelectable(array $row, ?string $flagColumn): bool
{
    if ($flagColumn === null || !array_key_exists($flagColumn, $row)) {
        return true;
    }
    $text = strtolower(trim((string)$row[$flagColumn]));
    $name = strtolower($flagColumn);
    if ($name === 'inactive' || $name === 'is_inactive') {
        return !in_array($text, ['1', 'yes', 'true', 'y', 'inactive'], true);
    }
    if ($name === 'status') {
        return !in_array($text, ['0', 'inactive', 'disabled', 'no', 'false', 'n'], true);
    }
    return !in_array($text, ['0', 'no', 'false', 'n', 'inactive', 'disabled'], true);
}

/**
 * Branch names that may be chosen on the import screen.
 * branchies is authoritative; session-restricted users only see authorised branches.
 *
 * @return array<int,string>
 */
function psImportBranchChoices(mysqli $conn): array
{
    $authorised = [];
    foreach (psAuthorisedProductBranches($conn) as $name) {
        $authorised[$name] = $name;
    }
    if (!$authorised) {
        return [];
    }
    $flag = psImportBranchFlagColumn($conn);
    $sql = 'SELECT branch_name';
    if ($flag !== null) {
        $sql .= ', `' . $flag . '`';
    }
    $sql .= ' FROM branchies ORDER BY branch_name ASC';
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        error_log('product import branch list failed');
        return [];
    }
    $names = [];
    while ($row = mysqli_fetch_assoc($res)) {
        if (!psImportBranchRowSelectable($row, $flag)) {
            continue;
        }
        $name = trim((string)($row['branch_name'] ?? ''));
        if ($name === '' || !isset($authorised[$name])) {
            continue;
        }
        $names[] = $name;
    }
    return $names;
}

/**
 * Posted import branch must be session-authorised and present in branchies.
 * Sheet Branch column is never used as the target.
 *
 * @return array{ok:bool,branch?:string,error?:string}
 */
function psImportPostedBranch(mysqli $conn): array
{
    if (!isset($_POST['branch']) || trim((string)$_POST['branch']) === '') {
        return ['ok' => false, 'error' => 'Select a branch before importing.'];
    }
    $posted = trim((string)$_POST['branch']);
    if (!psProductBranchIsAuthorised($conn, $posted)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    foreach (psImportBranchChoices($conn) as $name) {
        if ($name === $posted) {
            return ['ok' => true, 'branch' => $name];
        }
    }
    return ['ok' => false, 'error' => 'That branch is not available.'];
}

function psImportBranchSelect(array $branches, string $selected = ''): void
{
    echo '<label for="branch">Branch</label>';
    echo '<select id="branch" name="branch" required>';
    echo '<option value="">Select a branch</option>';
    foreach ($branches as $name) {
        $isSelected = $selected !== '' && $selected === $name;
        echo '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
            . ($isSelected ? ' selected' : '') . '>'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select>';
    echo '<p class="import-note">The target branch is chosen on this screen. A Branch column in the sheet is informational only and is not used to choose the target branch.</p>';
}

/**
 * Report import outcome. Never claim complete success when any row failed.
 *
 * @param array{inserted:int,updated:int,skipped:int,rejected:int,validation_errors:int} $stats
 */
function psImportReportResult(array $stats): void
{
    $ok = (int)$stats['inserted'] + (int)$stats['updated'];
    $bad = (int)$stats['rejected'] + (int)$stats['validation_errors'];
    $skipped = (int)$stats['skipped'];
    $parts = [];
    if ((int)$stats['inserted'] > 0) {
        $parts[] = (int)$stats['inserted'] . ' inserted';
    }
    if ((int)$stats['updated'] > 0) {
        $parts[] = (int)$stats['updated'] . ' updated';
    }
    if ($skipped > 0) {
        $parts[] = $skipped . ' skipped';
    }
    if ((int)$stats['rejected'] > 0) {
        $parts[] = (int)$stats['rejected'] . ' rejected';
    }
    if ((int)$stats['validation_errors'] > 0) {
        $parts[] = (int)$stats['validation_errors'] . ' validation error(s)';
    }
    $summary = $parts ? implode(', ', $parts) : 'no rows processed';
    // 2026-11-02: opening stock notes.
    $openingSet = (int)($GLOBALS['ps_import_opening_set'] ?? 0);
    if ($openingSet > 0) {
        echo "<div class='success'>Opening stock was set for " . $openingSet . ' new item' . ($openingSet === 1 ? '' : 's') . '.</div>';
    }
    $ignored = array_values(array_unique((array)($stats['opening_ignored'] ?? [])));
    if ($ignored) {
        echo "<div class='error'>Opening Stock was not applied to " . count($ignored) . ' item' . (count($ignored) === 1 ? '' : 's')
            . ' that already exist (' . htmlspecialchars(implode(', ', array_slice($ignored, 0, 20)) . (count($ignored) > 20 ? ' …' : ''), ENT_QUOTES, 'UTF-8')
            . '). Their stock is unchanged — use a stock take to correct it.</div>';
    }
    if ($ok > 0 && $bad === 0) {
        echo "<div class='success'>Import complete: " . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . ".</div>";
        return;
    }
    if ($ok > 0 && $bad > 0) {
        echo "<div class='error'>Import finished with errors: " . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8')
            . '. Some rows were not imported.</div>';
        return;
    }
    if ($bad > 0) {
        echo "<div class='error'>Import failed: " . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . ".</div>";
        return;
    }
    echo "<div class='error'>No product rows were imported.</div>";
}

/**
 * Apply parsed products to the selected branch only (row-by-row; not all-or-nothing).
 * Resolve by product_id + selected branch. Blank Product ID follows create behaviour
 * (auto-assign shared next id) for new rows only.
 *
 * @param array<int,array<string,mixed>> $products
 * @param array{inserted:int,updated:int,skipped:int,rejected:int,validation_errors:int} $stats
 * @return array{inserted:int,updated:int,skipped:int,rejected:int,validation_errors:int}
 */
function importProducts($conn, $products, string $branch, array $stats = null): array
{
    if ($stats === null) {
        $stats = psImportEmptyStats();
    }
    if (!$products) {
        return $stats;
    }
    $reorderNeeded = false;
    foreach ($products as $product) {
        if (!empty($product['reorder_apply'])) {
            $reorderNeeded = true;
            break;
        }
    }
    if ($reorderNeeded && !psEnsureGoodsReorderLevel($conn)) {
        psImportEchoError('Reorder level could not be saved.');
        $stats['rejected'] += count($products);
        return $stats;
    }

    $seenInFile = [];
    psCatalogEnsureSchema($conn);
    foreach ($products as $product) {
        $productId = trim((string)$product['product_id']);
        // Phase 19: categories / button groups come from the company's lists (new names are added);
        // units must be one of the Units of Measure.
        if ($product['category'] !== null && $product['category'] !== '') {
            $product['category'] = psCatEnsure($conn, 'category', (string)$product['category']);
        }
        if ($product['button_category'] !== null && $product['button_category'] !== '') {
            $product['button_category'] = psCatEnsure($conn, 'button', (string)$product['button_category']);
        }
        if (($product['unit'] ?? null) !== null && $product['unit'] !== '') {
            $code = psUnitResolve($conn, (string)$product['unit']);
            if ($code === '') {
                $stats['validation_errors']++;
                psImportEchoError('Unit "' . $product['unit'] . '" is not in the Units of Measure list. '
                    . ($productId !== '' ? $productId : (string)($product['product'] ?? 'This row')) . ' was not imported.');
                continue;
            }
            $product['unit'] = $code;
        } else {
            $product['unit'] = null;
        }
        if ($productId !== '') {
            if (isset($seenInFile[$productId])) {
                $stats['rejected']++;
                psImportEchoError(
                    $productId . ' appears more than once in this file for the selected branch and was not changed.'
                );
                continue;
            }
            $seenInFile[$productId] = true;
        }

        if ($productId === '') {
            // Blank Product ID: create-style new product only (cannot resolve an existing row).
            $result = psImportInsertProduct($conn, $product, $branch);
            if ($result === 'inserted') {
                $stats['inserted']++;
            } elseif ($result === 'skipped') {
                $stats['skipped']++;
            } elseif ($result === 'validation') {
                $stats['validation_errors']++;
            } else {
                $stats['rejected']++;
            }
            continue;
        }

        $find = mysqli_prepare($conn, 'SELECT id FROM goods WHERE product_id = ? AND branch = ?');
        if (!$find) {
            error_log('product import lookup failed');
            $stats['rejected']++;
            psImportEchoError($productId . ' was not changed.');
            continue;
        }
        $branchLookup = $branch;
        mysqli_stmt_bind_param($find, 'ss', $productId, $branchLookup);
        if (!mysqli_stmt_execute($find)) {
            mysqli_stmt_close($find);
            error_log('product import lookup failed');
            $stats['rejected']++;
            psImportEchoError($productId . ' was not changed.');
            continue;
        }
        $found = mysqli_stmt_get_result($find);
        $ids = [];
        if ($found) {
            while ($row = mysqli_fetch_assoc($found)) {
                $ids[] = (int)$row['id'];
            }
        }
        mysqli_stmt_close($find);

        if (count($ids) > 1) {
            $stats['rejected']++;
            psImportEchoError(
                $productId . ' exists more than once on the selected branch and was not changed.'
            );
            continue;
        }

        if (count($ids) === 1) {
            if (($product['opening'] ?? '') !== '') {
                $stats['opening_ignored'][] = $productId; // existing item: never adds stock again
            }
            $result = psImportUpdateProduct($conn, $ids[0], $product, $branch);
            if ($result === 'updated') {
                $stats['updated']++;
            } elseif ($result === 'skipped') {
                $stats['skipped']++;
            } elseif ($result === 'validation') {
                $stats['validation_errors']++;
            } else {
                $stats['rejected']++;
            }
            continue;
        }

        $result = psImportInsertProduct($conn, $product, $branch);
        if ($result === 'inserted') {
            $stats['inserted']++;
        } elseif ($result === 'skipped') {
            $stats['skipped']++;
        } elseif ($result === 'validation') {
            $stats['validation_errors']++;
        } else {
            $stats['rejected']++;
        }
    }
    return $stats;
}

/**
 * Update supported master fields only. Never changes status, quantity, re_order_level, or branch.
 * Audits only fields that actually change (source IMPORT). Unchanged rows are skipped.
 *
 * @return string updated|skipped|rejected|validation
 */
function psImportUpdateProduct(mysqli $conn, int $goodsId, array $product, string $branch): string
{
    $existing = psGoodsRowById($conn, $goodsId, $branch);
    if (!$existing) {
        psImportEchoError((string)$product['product_id'] . ' was not changed.');
        return 'rejected';
    }

    $sets = [];
    $types = '';
    $values = [];
    $map = [];
    if ($product['product_present'] === true) {
        $map['product'] = 'product';
    }
    if ($product['price_present'] === true) {
        $map['price'] = 'price';
    }
    if ($product['cost_present'] === true) {
        $map['cost_price'] = 'cost_price';
    }
    if ($product['category'] !== null) {
        $map['category'] = 'category';
    }
    if ($product['button_category'] !== null) {
        $map['button_category'] = 'button_category';
    }
    if ($product['pack_size'] !== null) {
        $map['pack_size'] = 'pack_size';
    }
    if (($product['unit'] ?? null) !== null) {
        $map['unit_of_measure'] = 'unit';
    }
    foreach ($map as $column => $key) {
        $sets[] = $column . ' = ?';
        $types .= 's';
        $values[] = (string)$product[$key];
    }
    if ($product['reorder_apply'] === true) {
        if ($product['reorder_level'] === null) {
            $sets[] = 'reorder_level = NULL';
        } else {
            $sets[] = 'reorder_level = ?';
            $types .= 's';
            $values[] = (string)$product['reorder_level'];
        }
    }
    $barcodeApply = !empty($product['barcode_present']);
    $barcodeParam = null;
    if ($barcodeApply) {
        if (!psEnsureGoodsBarcodeSchema($conn)) {
            psImportEchoError((string)$product['product_id'] . ' was not changed.');
            return 'rejected';
        }
        $bcCheck = psValidateBarcodeForBranch(
            $conn,
            $branch,
            $product['barcode'],
            $goodsId
        );
        if (!$bcCheck['ok']) {
            psImportEchoError((string)$bcCheck['error'] . ' ' . (string)$product['product_id'] . ' was not changed.');
            return 'validation';
        }
        $barcodeParam = psBarcodeBindParam($bcCheck['value']);
        $sets[] = 'barcode = ?';
        $types .= 's';
        $values[] = $barcodeParam;
    }
    $productId = (string)$product['product_id'];
    if (!$sets) {
        return 'skipped';
    }

    $afterRow = $existing;
    foreach ($map as $column => $key) {
        $afterRow[$column] = (string)$product[$key];
    }
    if ($product['reorder_apply'] === true) {
        $afterRow['reorder_level'] = $product['reorder_level'];
    }
    if ($barcodeApply) {
        $afterRow['barcode'] = $barcodeParam;
    }
    $beforeSnap = psProductAuditSnapshotFromGoods($existing);
    $afterSnap = psProductAuditSnapshotFromGoods($afterRow);
    // Import never changes status — keep before status for diff.
    $afterSnap['status'] = $beforeSnap['status'];
    $auditChanges = psProductAuditDiff($beforeSnap, $afterSnap);
    if ($auditChanges === []) {
        return 'skipped';
    }
    if (!psEnsureProductAuditSchema($conn)) {
        psImportEchoError($productId . ' was not changed.');
        return 'rejected';
    }

    $tx = psProductAuditBegin($conn);
    if (!$tx['ok']) {
        psImportEchoError($productId . ' was not changed.');
        return 'rejected';
    }
    $owns = !empty($tx['owns']);
    $types .= 'iss';
    $values[] = $goodsId;
    $values[] = $productId;
    $values[] = $branch;
    $sql = 'UPDATE goods SET ' . implode(', ', $sets) . ' WHERE id = ? AND product_id = ? AND branch = ?';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        psProductAuditRollback($conn, $owns);
        error_log('product import update failed');
        psImportEchoError($productId . ' was not changed.');
        return 'rejected';
    }
    if (!psImportBindAndExecute($stmt, $types, $values)) {
        error_log('product import update failed');
        mysqli_stmt_close($stmt);
        psProductAuditRollback($conn, $owns);
        psImportEchoError($productId . ' was not changed.');
        return 'rejected';
    }
    mysqli_stmt_close($stmt);
    $audited = psProductAuditRecordChanges($conn, [
        'product_id' => $productId,
        'goods_id' => $goodsId,
        'branch' => $branch,
        'action' => PS_PRODUCT_AUDIT_ACTION_IMPORT,
        'source' => PS_PRODUCT_AUDIT_SOURCE_IMPORT,
    ], $auditChanges);
    if (!$audited || !psProductAuditCommit($conn, $owns)) {
        psProductAuditRollback($conn, $owns);
        psImportEchoError($productId . ' was not changed.');
        return 'rejected';
    }
    return 'updated';
}

/**
 * Insert a new product on the selected branch. Blank Product ID uses psNextSharedProductId
 * (same as create). Status ACTIVE. Does not write quantity or re_order_level.
 *
 * @return string inserted|skipped|rejected|validation
 */
function psImportInsertProduct(mysqli $conn, array $product, string $branch): string
{
    $productId = trim((string)$product['product_id']);
    $autoId = ($productId === '');
    if ($autoId) {
        $productId = psNextSharedProductId($conn);
        if (psProductIdExistsOnBranch($conn, $productId, $branch)) {
            psImportEchoError(psProductDuplicateMessage() . ' Auto Product ID was not imported.');
            return 'rejected';
        }
    }

    $name = trim((string)($product['product'] ?? ''));
    if ($name === '') {
        psImportEchoError('Product name is required. ' . ($autoId ? 'This row' : $productId) . ' was not imported.');
        return 'validation';
    }

    $priceCheck = psValidateProductMoney($product['price'] ?? '', 'Selling price');
    $costCheck = psValidateProductMoney($product['cost_price'] ?? '', 'Cost price');
    if (!$priceCheck['ok']) {
        psImportEchoError((string)$priceCheck['error'] . ' ' . ($autoId ? 'This row' : $productId) . ' was not imported.');
        return 'validation';
    }
    if (!$costCheck['ok']) {
        psImportEchoError((string)$costCheck['error'] . ' ' . ($autoId ? 'This row' : $productId) . ' was not imported.');
        return 'validation';
    }
    // 2026-11-02: opening stock for the new item (blank = 0). Whole or decimal quantity, not negative.
    $openingRaw = trim((string)($product['opening'] ?? ''));
    $opening = '0';
    if ($openingRaw !== '') {
        $openingDec = psStockDecimal($openingRaw);
        if ($openingDec === null || psStockDecimalCmp($openingDec, '0') < 0) {
            psImportEchoError('Opening Stock must be a number of 0 or more. ' . ($autoId ? 'This row' : $productId) . ' was not imported.');
            return 'validation';
        }
        $opening = $openingDec;
    }
    if (function_exists('psEnsureGoodsStatus')) {
        psEnsureGoodsStatus($conn);
    }
    // Category defaults to INVENTORY ITEM only when the sheet has no Category value.
    // Button Category is never written into Category (and vice versa).
    $categoryInsert = ($product['category'] !== null && $product['category'] !== '')
        ? (string)$product['category']
        : 'INVENTORY ITEM';
    $columns = [
        'product_id', 'product', 'price', 'category', 'date_', 'cost_price', 'xx', 'yy', 'VAT_STATE', 'button_color', 'status',
    ];
    $values = [
        $productId,
        $name,
        (string)$priceCheck['value'],
        $categoryInsert,
        date('Y-m-d H:i:s'),
        (string)$costCheck['value'],
        '17',
        '112',
        'vatable',
        'Green',
        defined('PS_PRODUCT_STATUS_ACTIVE') ? PS_PRODUCT_STATUS_ACTIVE : 'ACTIVE',
    ];
    if ($product['button_category'] !== null) {
        $columns[] = 'button_category';
        $values[] = (string)$product['button_category'];
    }
    if ($product['pack_size'] !== null) {
        $columns[] = 'pack_size';
        $values[] = (string)$product['pack_size'];
    }
    if (($product['unit'] ?? null) !== null) {
        $columns[] = 'unit_of_measure';
        $values[] = (string)$product['unit'];
    }
    $reorderNull = false;
    // A blank reorder level on a NEW row is simply left out (column default NULL). Adding a literal
    // NULL mark here used to shift later values (barcode) into the wrong columns.
    if ($product['reorder_apply'] === true && $product['reorder_level'] !== null) {
        $columns[] = 'reorder_level';
        $values[] = (string)$product['reorder_level'];
    }
    $barcodeParam = null;
    $barcodeApply = !empty($product['barcode_present']);
    if ($barcodeApply) {
        if (!psEnsureGoodsBarcodeSchema($conn)) {
            psImportEchoError(($autoId ? 'This row' : $productId) . ' was not imported.');
            return 'rejected';
        }
        $bcCheck = psValidateBarcodeForBranch($conn, $branch, $product['barcode'], 0);
        if (!$bcCheck['ok']) {
            psImportEchoError((string)$bcCheck['error'] . ' ' . ($autoId ? 'This row' : $productId) . ' was not imported.');
            return 'validation';
        }
        $barcodeParam = psBarcodeBindParam($bcCheck['value']);
        $columns[] = 'barcode';
        $values[] = $barcodeParam;
    }
    $marks = array_fill(0, count($values), '?');
    if ($reorderNull) {
        $marks[] = 'NULL';
    }
    $columns[] = 'branch';
    $values[] = $branch;
    $marks[] = '?';
    // Health-check 2026-10-13: tag the company like legacy rows so POS/stock lookups match.
    $columns[] = 'company_name';
    $values[] = trim((string)($_SESSION['the_company'] ?? ''));
    $marks[] = "NULLIF(?, '')";
    if (!psEnsureProductAuditSchema($conn)) {
        psImportEchoError(($autoId ? 'This row' : $productId) . ' was not imported.');
        return 'rejected';
    }
    $tx = psProductAuditBegin($conn);
    if (!$tx['ok']) {
        psImportEchoError(($autoId ? 'This row' : $productId) . ' was not imported.');
        return 'rejected';
    }
    $owns = !empty($tx['owns']);
    $sql = 'INSERT INTO goods (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        psProductAuditRollback($conn, $owns);
        error_log('product import insert failed');
        psImportEchoError(($autoId ? 'This row' : $productId) . ' was not imported.');
        return 'rejected';
    }
    $types = str_repeat('s', count($values));
    if (!psImportBindAndExecute($stmt, $types, $values)) {
        if (psProductDuplicateKeyError($conn)) {
            psImportEchoError(psProductDuplicateMessage() . ' ' . $productId . ' was not imported.');
        } else {
            error_log('product import insert failed');
            psImportEchoError($productId . ' was not imported.');
        }
        mysqli_stmt_close($stmt);
        psProductAuditRollback($conn, $owns);
        return 'rejected';
    }
    mysqli_stmt_close($stmt);
    $goodsId = (int)mysqli_insert_id($conn);
    $statusActive = defined('PS_PRODUCT_STATUS_ACTIVE') ? PS_PRODUCT_STATUS_ACTIVE : 'ACTIVE';
    $snapshot = psProductAuditSnapshotFromGoods([
        'product' => $name,
        'price' => (string)$priceCheck['value'],
        'cost_price' => (string)$costCheck['value'],
        'category' => $categoryInsert,
        'button_category' => $product['button_category'] !== null ? (string)$product['button_category'] : '',
        'reorder_level' => ($product['reorder_apply'] === true) ? $product['reorder_level'] : null,
        'pack_size' => $product['pack_size'] !== null ? (string)$product['pack_size'] : '',
        'status' => $statusActive,
        'barcode' => $barcodeApply ? $barcodeParam : null,
    ]);
    $audited = $goodsId > 0 && psProductAuditRecordCreate(
        $conn,
        $productId,
        $goodsId,
        $branch,
        $snapshot,
        PS_PRODUCT_AUDIT_SOURCE_IMPORT,
        PS_PRODUCT_AUDIT_ACTION_IMPORT
    );
    // 2026-10-14: imported products are stock-tracked from creation (opening 0).
    if (!function_exists('psStockOpenNewProduct')) {
        require_once __DIR__ . '/stock_movement.php';
    }
    $tracked = $audited && psStockOpenNewProduct($conn, (string)$productId, (string)$branch, null, trim((string)($_SESSION['logger'] ?? '')), $opening);
    if (!$tracked || !psProductAuditCommit($conn, $owns)) {
        psProductAuditRollback($conn, $owns);
        psImportEchoError($productId . ' was not imported.');
        return 'rejected';
    }
    if (psStockDecimalCmp($opening, '0') > 0) {
        $GLOBALS['ps_import_opening_set'] = (int)($GLOBALS['ps_import_opening_set'] ?? 0) + 1;
    }
    return 'inserted';
}

function psImportUploadedSheet(mysqli $conn): void
{
    $resolved = psImportPostedBranch($conn);
    if ($resolved['ok'] !== true) {
        echo "<div class='error'>" . htmlspecialchars((string)($resolved['error'] ?? 'Select a branch before importing.'), ENT_QUOTES, 'UTF-8') . "</div>";
        return;
    }
    $branch = (string)$resolved['branch'];
    if (!isset($_FILES['excel_file'])) {
        echo "<div class='error'>No file uploaded</div>";
        return;
    }
    $file = (string)($_FILES['excel_file']['tmp_name'] ?? '');
    $rows = psImportReadRows($file);
    if ($rows === null) {
        echo "<div class='error'>The file could not be read.</div>";
        return;
    }
    $parsed = psImportProductsFromRows($rows);
    $stats = importProducts($conn, $parsed['products'], $branch, $parsed['stats']);
    psImportReportResult($stats);
}
