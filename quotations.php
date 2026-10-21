<?php
/**
 * 2026-10-17 — Quotations list (all signed-in staff; branch-scoped like reports).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/reporting.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/quotation.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
psReportingRequireAuthRedirect();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$company = trim((string)($_SESSION['the_company'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? 'open')));
$statuses = ['open' => 'Open', 'expired' => 'Expired', 'sold' => 'Sold', 'cancelled' => 'Cancelled', '' => 'All'];
if (!array_key_exists($status, $statuses)) {
    $status = 'open';
}
$scope = psBranchReportScope($conn);
$error = empty($scope['ok']) ? (string)($scope['error'] ?? 'That branch is not available.') : '';
$rows = $error === '' ? psQuoteList($conn, $company, $scope['branches'], $q, $status) : [];
$notice = trim((string)($_GET['notice'] ?? ''));
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotations</title>
<?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Quotations</h1>
    <p class="hint">Priced offers for customers. A quotation is not a sale and is not sent to ZIMRA. When the customer accepts, load it into the POS: the quoted prices are used and the quote is marked sold.</p>
    <p><a class="ps-btn ps-btn-default" href="quotation_edit.php">+ New quotation</a></p>
    <?php if ($notice !== ''): ?><p class="success"><?php echo $h($notice); ?></p><?php endif; ?>

    <div class="tabs">
        <?php foreach ($statuses as $k => $label): ?>
            <a class="<?php echo $status === $k ? 'on' : ''; ?>" href="quotations.php?<?php echo $h(http_build_query(['status' => $k, 'q' => $q])); ?>"><?php echo $h($label); ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="quotations.php" style="margin-top:16px;">
        <input type="hidden" name="status" value="<?php echo $h($status); ?>">
        <label>Search <input type="text" name="q" value="<?php echo $h($q); ?>" placeholder="Quote no., customer, phone or invoice" maxlength="100"></label>
        <button type="submit">Search</button>
    </form>

    <?php if ($error !== ''): ?>
        <p class="error"><?php echo $h($error); ?></p>
    <?php elseif (!$rows): ?>
        <p>No quotations here yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Quote</th><th>Date</th><th>Customer</th><th>Branch</th><th>Valid until</th><th>Status</th><th style="text-align:right">Total</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="quotation_view.php?id=<?php echo (int)$r['id']; ?>"><?php echo $h($r['quote_number']); ?></a><?php if ($r['till_ref']): ?><div class="ps-muted"><?php echo $h($r['till_ref']); ?></div><?php endif; ?></td>
                    <td><?php echo $h(substr((string)$r['created_at'], 0, 16)); ?></td>
                    <td><?php echo $h($r['customer_name']); ?><?php if ($r['customer_phone'] !== ''): ?><div class="ps-muted"><?php echo $h($r['customer_phone']); ?></div><?php endif; ?></td>
                    <td><?php echo $h($r['branch']); ?></td>
                    <td><?php echo $h($r['valid_until']); ?></td>
                    <td><?php echo $h(psQuoteStatusLabel($r)); ?><?php if ($r['converted_invoice']): ?><div class="ps-muted">Invoice <?php echo $h($r['converted_invoice']); ?></div><?php endif; ?></td>
                    <td style="text-align:right"><?php echo $h(ps_money($r['total'])); ?></td>
                    <td><?php echo $h($r['created_by']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
