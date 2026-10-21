<?php
/**
 * Phase 17 — ZIMRA fiscal receipts report (Management, admin). Read-only.
 * Non-admins see their working branch only.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/zimra.php';
require_once __DIR__ . '/includes/till_session.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);
if (!psZimraMayViewReports()) {
    http_response_code(403);
    die('ZIMRA reports are for Management.');
}
psEnsureZimraModule($conn);
$isAdmin = psZimraMaySetup();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$status = in_array($_GET['status'] ?? '', ['PENDING', 'SUBMITTED', 'SUBMITTED_WITH_ERRORS', 'REJECTED'], true) ? $_GET['status'] : '';
$branch = $isAdmin ? trim((string)($_GET['branch'] ?? '')) : tillSelectedBranch();
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT r.*, d.fiscal_day_no, v.device_id FROM zimra_receipts r
        JOIN zimra_fiscal_days d ON d.id = r.fiscal_day_row_id JOIN zimra_devices v ON v.id = r.device_row_id
        WHERE r.receipt_date >= ? AND r.receipt_date < DATE_ADD(?, INTERVAL 1 DAY)';
$types = 'ss';
$params = [$from, $to];
$zCompany = psZimraSessionCompany();
if ($zCompany !== '') { // Phase 18: own company's devices only
    $sql .= " AND (v.company_name = ? OR v.company_name = '')";
    $types .= 's';
    $params[] = $zCompany;
}
if ($branch !== '') {
    $sql .= ' AND r.branch = ?';
    $types .= 's';
    $params[] = $branch;
}
if ($status !== '') {
    $sql .= ' AND r.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (r.invoice_no = ? OR r.invoice_number = ? OR r.verification_code = ?)';
    $types .= 'sss';
    array_push($params, $q, $q, strtoupper($q));
}
$sql .= ' ORDER BY r.receipt_date DESC, r.id DESC LIMIT 500';
$rows = psZimraQuery($conn, $sql, $types, $params) ?? [];
$dw = [];
$dt = '';
$dp = [];
if ($zCompany !== '') {
    $dw[] = "(v.company_name = ? OR v.company_name = '')";
    $dt .= 's';
    $dp[] = $zCompany;
}
if ($branch !== '') {
    $dw[] = 'v.branch = ?';
    $dt .= 's';
    $dp[] = $branch;
}
$days = psZimraQuery($conn, 'SELECT d.*, v.branch, v.device_id FROM zimra_fiscal_days d JOIN zimra_devices v ON v.id = d.device_row_id'
    . ($dw ? ' WHERE ' . implode(' AND ', $dw) : '') . ' ORDER BY d.opened_at DESC LIMIT 20', $dt, $dp) ?? [];
$branches = $isAdmin ? array_values(array_unique(array_column(psZimraDeviceList($conn), 'branch'))) : [];
mysqli_close($conn);

$inv = 0.0;
$cn = 0.0;
foreach ($rows as $r) {
    $r['receipt_type'] === 'CreditNote' ? $cn += (float)$r['receipt_total'] : $inv += (float)$r['receipt_total'];
}
$h = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZIMRA Fiscal Receipts</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('ZIMRA Fiscal Receipts'); ?>
<h1>ZIMRA Fiscal Receipts</h1>
<p class="ps-page-lead">Every fiscal invoice and credit note sent (or waiting to be sent) to ZIMRA, with its fiscal day and verification code.</p>

<form method="get" class="ps-form-actions">
    <label class="ps-label">From <input class="ps-input" type="date" name="from" value="<?php echo $h($from); ?>"></label>
    <label class="ps-label">To <input class="ps-input" type="date" name="to" value="<?php echo $h($to); ?>"></label>
    <?php if ($isAdmin): ?>
        <label class="ps-label">Branch <select class="ps-input" name="branch"><option value="">All</option>
            <?php foreach ($branches as $b): ?><option <?php echo $b === $branch ? 'selected' : ''; ?>><?php echo $h($b); ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label class="ps-label">Status <select class="ps-input" name="status"><option value="">All</option>
        <?php foreach (['PENDING' => 'Waiting to send', 'SUBMITTED' => 'Accepted', 'SUBMITTED_WITH_ERRORS' => 'Accepted with errors', 'REJECTED' => 'Rejected'] as $k => $v): ?>
            <option value="<?php echo $k; ?>" <?php echo $k === $status ? 'selected' : ''; ?>><?php echo $h($v); ?></option><?php endforeach; ?></select></label>
    <label class="ps-label">Invoice / code <input class="ps-input" name="q" value="<?php echo $h($q); ?>"></label>
    <button class="ps-btn ps-btn-default" type="submit">Filter</button>
</form>

<div class="ps-kpi-grid">
    <div class="ps-kpi"><div class="ps-kpi-label">Receipts</div><div class="ps-kpi-value"><?php echo count($rows); ?></div></div>
    <div class="ps-kpi"><div class="ps-kpi-label">Invoices</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo $h(ps_money($inv)); ?></div></div>
    <div class="ps-kpi"><div class="ps-kpi-label">Credit notes</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo $h(ps_money($cn)); ?></div></div>
    <div class="ps-kpi"><div class="ps-kpi-label">Net</div><div class="ps-kpi-value ps-kpi-value-sm"><?php echo $h(ps_money($inv + $cn)); ?></div></div>
</div>

<div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
    <thead><tr><th>Date</th><th>Branch</th><th>Type</th><th>Invoice</th><th>Day</th><th>Receipt</th><th class="num">Total</th><th>Status</th><th>Verification code</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="ps-muted">No fiscal receipts in this period.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $b = ['SUBMITTED' => 'success', 'PENDING' => 'warning', 'SUBMITTED_WITH_ERRORS' => 'warning', 'REJECTED' => 'destructive'][$r['status']] ?? 'secondary'; ?>
        <tr>
            <td><?php echo $h(date('d M H:i', strtotime($r['receipt_date']))); ?></td>
            <td><?php echo $h($r['branch']); ?></td>
            <td><?php echo $r['receipt_type'] === 'CreditNote' ? 'Credit note' : 'Invoice'; ?></td>
            <td><?php echo $h($r['invoice_no']); ?></td>
            <td><?php echo (int)$r['fiscal_day_no']; ?></td>
            <td><?php echo $h($r['receipt_counter'] . '/' . $r['receipt_global_no']); ?></td>
            <td class="num"><?php echo $h(ps_money($r['receipt_total'], $r['receipt_currency'])); ?></td>
            <td><span class="ps-badge ps-badge-<?php echo $b; ?>"><?php echo $h($r['status']); ?></span></td>
            <td><?php echo $h($r['verification_code']); ?></td>
            <td><a class="ps-btn ps-btn-outline ps-btn-sm" href="zimra_receipt_print.php?id=<?php echo (int)$r['id']; ?>">View</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div></div>

<div class="ps-card">
    <div class="ps-card-header"><h2 class="ps-card-title">Recent fiscal days</h2></div>
    <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>Branch</th><th>Day</th><th>Opened</th><th>Opened by</th><th>Closed</th><th>Closed by</th><th class="num">Receipts</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($days as $d): ?>
            <tr><td><?php echo $h($d['branch']); ?></td><td><?php echo (int)$d['fiscal_day_no']; ?></td>
                <td><?php echo $h($d['opened_at']); ?></td><td><?php echo $h($d['opened_by']); ?></td>
                <td><?php echo $h($d['closed_at'] ?? ''); ?></td><td><?php echo $h($d['close_requested_by'] ?? ''); ?></td>
                <td class="num"><?php echo (int)$d['last_receipt_counter']; ?></td><td><?php echo $h($d['status']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<?php ps_close(); ?>
</body>
</html>
