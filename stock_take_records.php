<?php
/**
 * 2026-11-03 — Stock Take Records: every stock take (web and phone) with what the system expected, what was counted
 * and the difference, in quantity and value. Access: company role permission "Stock take records"
 * (includes/role_permissions.php; admins always). Only the company's own branches.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/role_permissions.php';
require_once __DIR__ . '/includes/stock_take_records.php';
require_once __DIR__ . '/includes/report_export.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psRolePermRequire('stock_take_records');
mysqli_report(MYSQLI_REPORT_OFF);

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$q3 = static function ($v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    return rtrim(rtrim(number_format((float)$v, 3, '.', ','), '0'), '.');
};
$m2 = static fn($v) => number_format((float)$v, 2);
$label = static fn($b) => function_exists('psBranchLabel') ? psBranchLabel($b) : (string)$b;

// Company branches the person may report on (admins: all of the company's; others: their working branch).
$scope = psBranchReportScope($conn);
$branches = !empty($scope['ok']) ? ($scope['branches'] ?? []) : [];
psStockTakeRecordsBackfill($conn); // earlier web stock takes (differences only), once

$sources = ['WEB' => 'Web', 'PHONE' => 'Phone', 'HISTORY' => 'Before records (differences only)'];
$validDate = static fn($d) => is_string($d) && DateTime::createFromFormat('Y-m-d', $d) !== false;
$f = [
    'from' => $validDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01'),
    'to' => $validDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d'),
    'branch' => trim((string)($_GET['branch'] ?? '')),
    'person' => trim((string)($_GET['person'] ?? '')),
    'source' => isset($sources[$_GET['source'] ?? '']) ? (string)$_GET['source'] : '',
];
if ($f['branch'] !== '' && !psBranchNameInList($f['branch'], $branches)) {
    $f['branch'] = '';
}
$id = (int)($_GET['id'] ?? 0);
$export = strtolower(trim((string)($_GET['export'] ?? '')));
$take = $id > 0 ? psStockTakeRecordsGet($conn, $branches, $id) : null;
$takes = $take ? [] : psStockTakeRecordsList($conn, $branches, $f);
$people = psStockTakeRecordsPeople($conn, $branches);
$whoLabel = static fn($t) => trim((string)$t) !== '' ? (string)$t : '—';

// Exports (PDF / Excel)
if ($export !== '' && in_array($export, psReportExportFormats(), true)) {
    if ($take) {
        $rows = [];
        foreach ($take['lines'] as $l) {
            $rows[] = [$l['product_id'], $l['product'], $q3($l['system_qty']), $q3($l['counted_qty']), $q3($l['variance']),
                $m2((float)$l['variance'] * (float)($l['unit_cost'] ?? 0)), $m2((float)$l['variance'] * (float)($l['unit_price'] ?? 0)),
                substr((string)$l['counted_at'], 11, 5)];
        }
        psReportExportSend($export, 'Stock take ST-' . str_pad((string)$take['id'], 5, '0', STR_PAD_LEFT),
            $label($take['branch']) . ' · ' . $take['take_date'] . ' · ' . $whoLabel($take['counted_by']) . ' · ' . ($sources[$take['source']] ?? $take['source']), [
                ['heading' => 'Items counted', 'columns' => ['Code', 'Item', 'System', 'Counted', 'Difference', 'Value at cost', 'Value at price', 'Time'],
                 'rows' => $rows, 'empty' => 'No items.'],
            ], 'stock_take_' . $take['id']);
    } else {
        $rows = [];
        foreach ($takes as $t) {
            $rows[] = ['ST-' . str_pad((string)$t['id'], 5, '0', STR_PAD_LEFT), $t['take_date'], $label($t['branch']), $whoLabel($t['counted_by']),
                $sources[$t['source']] ?? $t['source'], (int)$t['line_count'], (int)$t['lines_diff'], $q3($t['short_qty']), $m2($t['short_cost']),
                $q3($t['surplus_qty']), $m2($t['surplus_cost']), $m2((float)$t['surplus_cost'] - (float)$t['short_cost'])];
        }
        psReportExportSend($export, 'Stock Take Records', $f['from'] . ' to ' . $f['to'], [
            ['heading' => 'Stock takes', 'columns' => ['Ref', 'Date', 'Branch', 'Counted by', 'Where', 'Items', 'With difference',
                'Short qty', 'Short at cost', 'Surplus qty', 'Surplus at cost', 'Net at cost'], 'rows' => $rows, 'empty' => 'No stock takes in this period.'],
        ], 'stock_take_records');
    }
    exit;
}

$totals = ['takes' => count($takes), 'lines' => 0, 'diff' => 0, 'short_cost' => 0.0, 'surplus_cost' => 0.0, 'short_price' => 0.0, 'surplus_price' => 0.0];
foreach ($takes as $t) {
    $totals['lines'] += (int)$t['line_count'];
    $totals['diff'] += (int)$t['lines_diff'];
    foreach (['short_cost', 'surplus_cost', 'short_price', 'surplus_price'] as $k) {
        $totals[$k] += (float)$t[$k];
    }
}
$ref = static fn($tid) => 'ST-' . str_pad((string)$tid, 5, '0', STR_PAD_LEFT);
$query = array_filter($f, static fn($v) => $v !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Take Records</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
    <style>
        .str-neg { color: #b42318; font-weight: 600; }
        .str-pos { color: #067647; font-weight: 600; }
        .str-zero { color: var(--ps-muted); }
        @media print { .no-print, .ps-sidebar, .ps-topbar { display: none !important; } }
    </style>
</head>
<body class="ps-app">
<?php ps_open('Stock Take Records'); ?>
<?php if ($take): ?>
    <p class="no-print"><a href="stock_take_records.php?<?php echo $h(http_build_query($query)); ?>">← All stock takes</a></p>
    <h1>Stock take <?php echo $h($ref($take['id'])); ?></h1>
    <p class="ps-page-lead"><?php echo $h($label($take['branch'])); ?> · <?php echo $h($take['take_date']); ?>
        · <?php echo $h(substr((string)$take['started_at'], 11, 5)); ?>–<?php echo $h(substr((string)$take['last_count_at'], 11, 5)); ?>
        · counted by <?php echo $h($whoLabel($take['counted_by'])); ?> · <?php echo $h($sources[$take['source']] ?? $take['source']); ?></p>
    <?php if ($take['source'] === 'HISTORY'): ?>
        <div class="ps-alert">This stock take was done before records were kept, so only the items with a difference are shown.</div>
    <?php endif; ?>
    <?php echo psReportExportToolbar(['id' => $take['id']]); ?>
    <?php
    $sc = $ss = $pc = $ps = 0.0;
    foreach ($take['lines'] as $l) {
        $v = (float)$l['variance'];
        if ($v < 0) { $sc += -$v * (float)($l['unit_cost'] ?? 0); $pc += -$v * (float)($l['unit_price'] ?? 0); }
        if ($v > 0) { $ss += $v * (float)($l['unit_cost'] ?? 0); $ps += $v * (float)($l['unit_price'] ?? 0); }
    }
    ?>
    <div class="ps-kpi-grid">
        <div class="ps-kpi"><div class="ps-kpi-label">Items counted</div><div class="ps-kpi-value"><?php echo count($take['lines']); ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Shortage at cost</div><div class="ps-kpi-value"><?php echo $h($m2($sc)); ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Surplus at cost</div><div class="ps-kpi-value"><?php echo $h($m2($ss)); ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Net at selling price</div><div class="ps-kpi-value"><?php echo $h($m2($ps - $pc)); ?></div></div>
    </div>
    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap">
        <table class="ps-table">
            <thead><tr><th>Code</th><th>Item</th><th class="num">System</th><th class="num">Counted</th><th class="num">Difference</th>
                <th class="num">Value at cost</th><th class="num">Value at price</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($take['lines'] as $l): $v = (float)$l['variance']; $cls = $v < 0 ? 'str-neg' : ($v > 0 ? 'str-pos' : 'str-zero'); ?>
                <tr>
                    <td><?php echo $h($l['product_id']); ?></td>
                    <td><?php echo $h($l['product']); ?></td>
                    <td class="num"><?php echo $h($q3($l['system_qty'])); ?></td>
                    <td class="num"><?php echo $h($q3($l['counted_qty'])); ?></td>
                    <td class="num <?php echo $cls; ?>"><?php echo $v == 0 ? 'Matched' : $h(($v > 0 ? '+' : '') . $q3($l['variance'])); ?></td>
                    <td class="num"><?php echo $l['unit_cost'] === null ? '—' : $h($m2($v * (float)$l['unit_cost'])); ?></td>
                    <td class="num"><?php echo $l['unit_price'] === null ? '—' : $h($m2($v * (float)$l['unit_price'])); ?></td>
                    <td><?php echo $h(substr((string)$l['counted_at'], 11, 5)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
<?php else: ?>
    <h1>Stock Take Records</h1>
    <p class="ps-page-lead">Every stock take done on the web or the phone: what the system expected, what was counted and the difference.
        Counts by the same person at the same branch on the same day form one stock take.</p>
    <div class="ps-card no-print"><div class="ps-card-content">
        <form method="get" class="ps-form-inline filter-form">
            <label><span class="ps-label">From</span><input class="ps-input" type="date" name="from" value="<?php echo $h($f['from']); ?>"></label>
            <label><span class="ps-label">To</span><input class="ps-input" type="date" name="to" value="<?php echo $h($f['to']); ?>"></label>
            <label><span class="ps-label">Branch</span><select class="ps-input" name="branch"><option value="">All</option>
                <?php foreach ($branches as $b): ?><option value="<?php echo $h($b); ?>" <?php echo $f['branch'] === $b ? 'selected' : ''; ?>><?php echo $h($b); ?></option><?php endforeach; ?>
            </select></label>
            <label><span class="ps-label">Counted by</span><select class="ps-input" name="person"><option value="">Anyone</option>
                <?php foreach ($people as $p): ?><option value="<?php echo $h($p); ?>" <?php echo $f['person'] === $p ? 'selected' : ''; ?>><?php echo $h($p); ?></option><?php endforeach; ?>
            </select></label>
            <label><span class="ps-label">Where</span><select class="ps-input" name="source"><option value="">Web and phone</option>
                <?php foreach ($sources as $k => $v): ?><option value="<?php echo $h($k); ?>" <?php echo $f['source'] === $k ? 'selected' : ''; ?>><?php echo $h($v); ?></option><?php endforeach; ?>
            </select></label>
            <button class="ps-btn ps-btn-default" type="submit">Show</button>
        </form>
    </div></div>
    <?php echo psReportExportToolbar($query); ?>
    <div class="ps-kpi-grid">
        <div class="ps-kpi"><div class="ps-kpi-label">Stock takes</div><div class="ps-kpi-value"><?php echo (int)$totals['takes']; ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Items counted</div><div class="ps-kpi-value"><?php echo (int)$totals['lines']; ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">With a difference</div><div class="ps-kpi-value"><?php echo (int)$totals['diff']; ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Shortage at cost</div><div class="ps-kpi-value"><?php echo $h($m2($totals['short_cost'])); ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Surplus at cost</div><div class="ps-kpi-value"><?php echo $h($m2($totals['surplus_cost'])); ?></div></div>
    </div>
    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap">
        <table class="ps-table">
            <thead><tr><th>Ref</th><th>Date</th><th>Branch</th><th>Counted by</th><th>Where</th><th class="num">Items</th><th class="num">With difference</th>
                <th class="num">Short</th><th class="num">Short at cost</th><th class="num">Surplus</th><th class="num">Surplus at cost</th><th class="num">Net at cost</th><th class="no-print"></th></tr></thead>
            <tbody>
            <?php if (!$takes): ?>
                <tr><td colspan="13" class="ps-muted">No stock takes in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($takes as $t): $net = (float)$t['surplus_cost'] - (float)$t['short_cost']; ?>
                <tr>
                    <td><?php echo $h($ref($t['id'])); ?></td>
                    <td><?php echo $h($t['take_date']); ?></td>
                    <td><?php echo $h($t['branch']); ?></td>
                    <td><?php echo $h($whoLabel($t['counted_by'])); ?></td>
                    <td><?php echo $h($sources[$t['source']] ?? $t['source']); ?></td>
                    <td class="num"><?php echo (int)$t['line_count']; ?></td>
                    <td class="num"><?php echo (int)$t['lines_diff']; ?></td>
                    <td class="num"><?php echo $h($q3($t['short_qty'])); ?></td>
                    <td class="num"><?php echo $h($m2($t['short_cost'])); ?></td>
                    <td class="num"><?php echo $h($q3($t['surplus_qty'])); ?></td>
                    <td class="num"><?php echo $h($m2($t['surplus_cost'])); ?></td>
                    <td class="num <?php echo $net < 0 ? 'str-neg' : ($net > 0 ? 'str-pos' : ''); ?>"><?php echo $h($m2($net)); ?></td>
                    <td class="no-print"><a class="ps-btn ps-btn-outline ps-btn-sm" href="stock_take_records.php?<?php echo $h(http_build_query($query + ['id' => $t['id']])); ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
<?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
<?php mysqli_close($conn); ?>
