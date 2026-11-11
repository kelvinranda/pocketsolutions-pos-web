<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/goods_receipt.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/goods_receipt_edit.php'; // 2026-11-11: edit a saved receipt + the admin's limits

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureGoodsReceiptAllSchema($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$branch = '';
$date = trim((string)($_POST['date'] ?? $_GET['date'] ?? ''));
$supplier_name = trim((string)($_POST['supplier_name'] ?? $_GET['supplier_name'] ?? ''));
$invoice_number = trim((string)($_POST['invoice_number'] ?? $_GET['invoice_number'] ?? ''));
$receipts_data = [];

if (!$signedIn) {
    $error = 'You must be signed in to view goods receipts.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $list = psGoodsReceiptListForSession($conn, [
            'date' => $date,
            'supplier' => $supplier_name,
            'invoice_number' => $invoice_number,
        ]);
        if (!$list['ok']) {
            $error = (string)($list['error'] ?? 'Goods receipts could not be loaded.');
        } else {
            $receipts_data = $list['rows'] ?? [];
        }
    }
}

require_once __DIR__ . '/includes/currency.php';

// 2026-11-11: editing saved receipts — who may (Role Permissions: Edit goods receipts) and the admin's limits.
$grvNotice = '';
if (!empty($_SESSION['ps_grv_notice'])) {
    $grvNotice = (string)$_SESSION['ps_grv_notice'];
    unset($_SESSION['ps_grv_notice']);
}
$grvSettingsError = '';
if ($signedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grv_edit_settings'])) {
    if (!psCsrfValidate()) {
        $grvSettingsError = 'Invalid security token. Reload and try again.';
    } else {
        $r = psGrvEditSaveSettings($conn, $_POST, (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            $_SESSION['ps_grv_notice'] = 'Goods receipt editing limits saved.';
            header('Location: goods_receipt_ref.php');
            exit;
        }
        $grvSettingsError = (string)$r['error'];
    }
}
$mayEditGrv = $signedIn && psGrvEditAllowed($conn);
$isGrvAdmin = $signedIn && psCashControlsLevel() === 'admin';
$grvEditSettings = $isGrvAdmin ? psGrvEditSettings($conn) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Receipt</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Goods Receipt</h1>
    <p class="hint">Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : '(none)', ENT_QUOTES, 'UTF-8'); ?></p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($grvNotice !== ''): ?>
        <div class="ps-alert ps-alert-success" role="status"><?php echo htmlspecialchars($grvNotice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $branch !== ''): ?>
        <form method="GET" action="">
            <input type="date" name="date" value="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="supplier_name" placeholder="Supplier" value="<?php echo htmlspecialchars($supplier_name, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" name="invoice_number" placeholder="GRV / supplier invoice" value="<?php echo htmlspecialchars($invoice_number, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit">Search</button>
        </form>

        <!-- 2026-10-18: actions share one row (wraps on phones). -->
        <div class="grr-actions">
            <a class="new-order-button" href="grv.php">New goods receipt</a>
            <a class="new-order-button" href="grv.php?mode=po">Receive against PO</a>
            <a class="new-order-button" href="create_purchase_return.php">Purchase return</a>
            <span class="grr-links">
                <a href="goods_receipt_audit_history.php">Receiving audit</a>
                <a href="purchasing_reports.php?report=grv">GRV report</a>
            </span>
        </div>
        <style>
            .grr-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 14px; }
            html[data-theme] body.ps-app .grr-actions a.new-order-button { margin: 0; flex: 1 1 180px; max-width: 260px; }
            .grr-actions .grr-links { display: inline-flex; gap: 16px; margin-left: 6px; }
            @media (max-width: 600px) {
                html[data-theme] body.ps-app .grr-actions a.new-order-button { max-width: none; }
                .grr-actions .grr-links { width: 100%; margin-left: 0; }
            }
        </style>

        <table>
            <thead>
                <tr>
                    <th>GRV #</th>
                    <th>PO #</th>
                    <th>Supplier</th>
                    <th>Supplier invoice</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Branch</th>
                    <th>User</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($receipts_data)): ?>
                    <tr>
                        <td colspan="9">No goods receipts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts_data as $receipt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($receipt['grv_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($receipt['po_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($receipt['product'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($receipt['reference1'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($receipt['date_'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="num"><?php echo htmlspecialchars(psFxFormat($conn, $receipt['totalz'] ?? 0, psFxNormalizeCode($receipt['currency'] ?? '') ?: psFxLegacyCurrency()), ENT_QUOTES, 'UTF-8'); ?><?php if (($receipt['currency'] ?? null) === null): ?> <span class="fxa-tag" title="Recorded before currencies were stored">legacy</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars((string)($receipt['branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($receipt['created_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <button type="button" onclick="window.location.href='goods_receipt.php?invoice_number=<?php echo urlencode((string)$receipt['reference1']); ?>'">View lines</button>
                                <?php if (!empty($receipt['grv_number']) && $mayEditGrv): ?>
                                    <a class="ps-btn ps-btn-outline ps-btn-sm" href="goods_receipt_edit.php?grv=<?php echo rawurlencode((string)$receipt['grv_number']); ?>">Edit</a>
                                <?php endif; ?>
                                <?php if (!empty($receipt['grv_number'])): ?>
                                    <a class="ps-btn ps-btn-outline ps-btn-sm" href="grv_print.php?grv=<?php echo rawurlencode((string)$receipt['grv_number']); ?>" target="_blank" rel="noopener">GRV PDF</a>
                                    <a class="ps-btn ps-btn-ghost ps-btn-sm" href="grv_print.php?grv=<?php echo rawurlencode((string)$receipt['grv_number']); ?>&amp;format=slip" target="_blank" rel="noopener">Slip</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($isGrvAdmin && $grvEditSettings !== null): ?>
        <!-- 2026-11-11: admin's limits on editing saved goods receipts -->
        <div class="ps-card" style="margin-top:20px">
            <div class="ps-card-header"><h2 class="ps-card-title">Editing goods receipts</h2>
                <p class="ps-card-desc">Who may edit is set on <a href="role_permissions.php">Role Permissions</a> (Edit goods receipts). Choose when a saved receipt can no longer be changed.</p></div>
            <div class="ps-card-content">
                <?php if ($grvSettingsError !== ''): ?>
                    <div class="ps-alert ps-alert-destructive" role="alert"><?php echo htmlspecialchars($grvSettingsError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <form method="post" action="goods_receipt_ref.php" style="display:grid;gap:10px;max-width:640px">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="grv_edit_settings" value="1">
                    <label><input type="checkbox" name="lock_exported" value="1" <?php echo $grvEditSettings['lock_exported'] ? 'checked' : ''; ?>>
                        Lock a receipt once it is in an accounting export</label>
                    <label><input type="checkbox" name="lock_returns" value="1" <?php echo $grvEditSettings['lock_returns'] ? 'checked' : ''; ?>>
                        Lock a receipt once goods were returned to the supplier against it</label>
                    <label><input type="checkbox" name="no_negative" value="1" <?php echo $grvEditSettings['no_negative'] ? 'checked' : ''; ?>>
                        Do not allow an edit that would take stock below zero</label>
                    <label>Allow edits only within
                        <input type="number" name="days" min="0" max="3650" step="1" style="width:90px" value="<?php echo (int)$grvEditSettings['days']; ?>">
                        days of the receipt date (0 = no time limit)</label>
                    <div><button class="ps-btn ps-btn-default" type="submit">Save limits</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
