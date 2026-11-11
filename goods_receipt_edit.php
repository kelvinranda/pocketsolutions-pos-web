<?php
/**
 * 2026-11-11 — Edit a saved goods receipt (see includes/goods_receipt_edit.php for the rules).
 * Opened from the Goods Receipt list ("Edit") by people with the "Edit goods receipts" permission.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/goods_receipt_edit.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/ps_layout.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
psRolePermRequire('grv_edit', 'goods_receipt_ref.php');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');
psEnsureGoodsReceiptAllSchema($conn);

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$grv = trim((string)($_GET['grv'] ?? $_POST['grv'] ?? ''));
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $r = psGrvEditSave($conn, $grv, [
            'invoice_number' => (string)($_POST['invoice_number'] ?? ''),
            'date' => (string)($_POST['date'] ?? ''),
            'supplier_name' => (string)($_POST['supplier_name'] ?? ''),
            'reason' => (string)($_POST['reason'] ?? ''),
            'lines' => is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [],
        ]);
        if (!empty($r['ok'])) {
            $_SESSION['ps_grv_notice'] = (int)$r['changes'] > 0
                ? 'Goods receipt ' . $grv . ' corrected. Stock was adjusted by the difference and the change is in the receiving audit.'
                : 'Nothing was changed on goods receipt ' . $grv . '.';
            header('Location: goods_receipt_ref.php');
            exit;
        }
        $error = (string)$r['error'];
    }
}

$branchCheck = psRequireSessionProductBranch($conn);
$load = !empty($branchCheck['ok']) ? psGrvEditLoad($conn, $grv, (string)$branchCheck['branch']) : ['ok' => false, 'error' => (string)($branchCheck['error'] ?? 'That branch is not available.')];
$ref = $load['ref'] ?? null;
$lines = $load['lines'] ?? [];
$locks = $load['locks'] ?? [];
$poMode = $ref !== null && (int)($ref['purchase_order_id'] ?? 0) > 0;
$cur = $ref !== null ? (psFxNormalizeCode($ref['currency'] ?? '') ?: psFxLegacyCurrency()) : '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$pv = static function (string $name, $default) use ($post) {
    return $post && isset($_POST[$name]) ? (string)$_POST[$name] : (string)$default;
};
$lv = static function (int $id, string $field, $default) use ($post) {
    return $post && isset($_POST['lines'][$id][$field]) ? (string)$_POST['lines'][$id][$field] : (string)$default;
};
$disabled = $locks ? ' disabled' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Goods Receipt</title>
    <?php ps_head(); echo psCsrfMeta(); ?>
    <style>
        .gre-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .gre-table input[type="text"] { width: 100%; min-width: 80px; }
        .gre-table td.num, .gre-table th.num { text-align: right; }
        .gre-table .gre-remove { white-space: nowrap; }
        .gre-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 14px; }
        .gre-note { color: var(--ps-muted, #666); font-size: 13px; }
    </style>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Edit Goods Receipt<?php echo $ref ? ' ' . $h($grv) : ''; ?></h1>
    <p class="ps-page-lead">Correct a mistake on a saved receipt. Stock is adjusted by the difference in each quantity, and every change is kept in the
        <a href="goods_receipt_audit_history.php">receiving audit</a> with your reason. To add a product that was left out, make a
        <a href="grv.php">new goods receipt</a>.</p>

    <?php if ($error !== ''): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
    <?php endif; ?>

    <?php if (empty($load['ok'])): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($load['error'] ?? 'Goods receipt not found.'); ?></div>
        <p><a href="goods_receipt_ref.php">Back to goods receipts</a></p>
    <?php else: ?>
        <?php if ($locks): ?>
            <div class="ps-alert ps-alert-warning" role="alert"><strong>This goods receipt cannot be edited.</strong>
                <?php foreach ($locks as $l): ?><br><?php echo $h($l); ?><?php endforeach; ?>
                <br><span class="gre-note">An admin sets these limits on the Goods Receipt page.</span></div>
        <?php endif; ?>

        <form method="post" action="goods_receipt_edit.php" autocomplete="off">
            <?php echo psCsrfField(); ?>
            <input type="hidden" name="grv" value="<?php echo $h($grv); ?>">

            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Receipt</h2>
                    <p class="ps-card-desc"><?php echo $poMode ? 'Received against purchase order ' . $h($load['po']['po_number'] ?? '') . '. The supplier and unit costs come from the purchase order.' : 'Received without a purchase order.'; ?>
                        Currency: <?php echo $h($cur); ?>.</p></div>
                <div class="ps-card-content">
                    <div class="gre-grid">
                        <label class="ps-field"><span class="ps-label">Supplier invoice number</span>
                            <input class="ps-input" name="invoice_number" required maxlength="250" value="<?php echo $h($pv('invoice_number', $ref['reference1'])); ?>"<?php echo $disabled; ?>></label>
                        <label class="ps-field"><span class="ps-label">Receipt date</span>
                            <input class="ps-input" type="date" name="date" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo $h($pv('date', substr((string)$ref['date_'], 0, 10))); ?>"<?php echo $disabled; ?>></label>
                        <label class="ps-field"><span class="ps-label">Supplier</span>
                            <input class="ps-input" name="supplier_name" maxlength="250" value="<?php echo $h($pv('supplier_name', $ref['product'])); ?>"<?php echo $poMode ? ' readonly' : ''; ?><?php echo $disabled; ?>></label>
                    </div>
                </div>
            </div>

            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Lines</h2>
                    <p class="ps-card-desc">Change a quantity<?php echo $poMode ? '' : ', unit cost or VAT'; ?>, or tick Remove to take a line off. At least one line must stay.</p></div>
                <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap">
                    <table class="ps-table gre-table">
                        <thead><tr>
                            <th>Product</th>
                            <th class="num">Quantity</th>
                            <th class="num">Unit cost</th>
                            <th class="num">VAT</th>
                            <th>Remove</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($lines as $l): $id = (int)$l['id']; $ret = psStockDecimalCmp((string)$l['returned_qty'], '0') > 0; ?>
                            <tr>
                                <td><?php echo $h($l['product']); ?><br><span class="gre-note"><?php echo $h($l['product_id']); ?>
                                    <?php echo $ret ? ' · ' . $h(psStockQtyPlain((string)$l['returned_qty'])) . ' returned to supplier' : ''; ?></span></td>
                                <td class="num"><input type="text" inputmode="decimal" name="lines[<?php echo $id; ?>][quantity]" aria-label="Quantity of <?php echo $h($l['product']); ?>"
                                    value="<?php echo $h($lv($id, 'quantity', psStockQtyPlain(psStockDecimal((string)$l['quantity']) ?? '0'))); ?>"<?php echo $disabled; ?>></td>
                                <td class="num"><input type="text" inputmode="decimal" name="lines[<?php echo $id; ?>][line_cost]" aria-label="Unit cost of <?php echo $h($l['product']); ?>"
                                    value="<?php echo $h($lv($id, 'line_cost', $l['line_cost'])); ?>"<?php echo $poMode ? ' readonly' : ''; ?><?php echo $disabled; ?>></td>
                                <td class="num"><input type="text" inputmode="decimal" name="lines[<?php echo $id; ?>][vat]" aria-label="VAT of <?php echo $h($l['product']); ?>"
                                    value="<?php echo $h($lv($id, 'vat', $l['vat'] === '' ? '0' : $l['vat'])); ?>"<?php echo $poMode ? ' readonly' : ''; ?><?php echo $disabled; ?>></td>
                                <td class="gre-remove"><label><input type="checkbox" name="lines[<?php echo $id; ?>][remove]" value="1"
                                    <?php echo $post && !empty($_POST['lines'][$id]['remove']) ? 'checked' : ''; ?><?php echo ($ret || $locks) ? ' disabled' : ''; ?>> Remove</label></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>

            <div class="ps-card">
                <div class="ps-card-content">
                    <label class="ps-field"><span class="ps-label">Reason for the change</span>
                        <input class="ps-input" name="reason" required minlength="3" maxlength="400" placeholder="e.g. Supplier invoice was 12 boxes, not 21"
                               value="<?php echo $h($pv('reason', '')); ?>"<?php echo $disabled; ?>></label>
                    <div class="gre-actions">
                        <button class="ps-btn ps-btn-default" type="submit"<?php echo $disabled; ?>>Save changes</button>
                        <a class="ps-btn ps-btn-outline" href="goods_receipt_ref.php">Cancel</a>
                        <span class="gre-note">Current total: <?php echo $h(psFxFormat($conn, $ref['totalz'] ?? 0, $cur)); ?></span>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php ps_close(); mysqli_close($conn); ?>
</body>
</html>
