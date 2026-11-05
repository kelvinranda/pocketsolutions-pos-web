<?php
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/product_packs.php';

$allowed = psSessionStockBranches($conn);
$company = trim((string)($_SESSION['the_company'] ?? ''));

$branch = trim((string)($_POST['branch'] ?? $_GET['branch'] ?? ''));
if (!isset($allowed[$branch])) {
    $branch = trim((string)($_SESSION['selected_branch'] ?? ''));
    if (!isset($allowed[$branch])) {
        $branch = $allowed ? (string)reset($allowed) : '';
    }
}

$pageError = '';
$notice = '';
if (!empty($_SESSION['ps_adjustment_notice'])) {
    $notice = (string)$_SESSION['ps_adjustment_notice'];
    unset($_SESSION['ps_adjustment_notice']);
}

$postedProduct = '';
$postedDirection = 'increase';
$postedQuantity = '';
$postedReason = '';
$postedDetail = '';
$postedUnit = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!psCsrfValidate()) {
        $pageError = 'Invalid security token. Reload and try again.';
    } else {
        $postedProduct = trim((string)($_POST['product_id'] ?? ''));
        $postedDirection = trim((string)($_POST['direction'] ?? ''));
        $postedQuantity = trim((string)($_POST['quantity'] ?? ''));
        $postedReason = trim((string)($_POST['reason'] ?? ''));
        $postedDetail = trim((string)($_POST['reason_detail'] ?? ''));
        $postedBranch = trim((string)($_POST['branch'] ?? ''));
        $postedUnit = trim((string)($_POST['unit'] ?? ''));
        if (isset($allowed[$postedBranch])) {
            $branch = $postedBranch;
        }
        // 2026-10-28 packs: a quantity entered in packs becomes base units (whole-unit products checked too).
        $ledgerQty = $postedQuantity;
        $ledgerDetail = $postedDetail;
        $conv = psPackConvertRows($conn, $company, [$postedProduct], [$postedQuantity], [$postedUnit]);
        if (empty($conv['ok'])) {
            $saved = ['ok' => false, 'error' => $conv['error']];
        } else {
            $ledgerQty = (string)$conv['qtys'][0];
            if (!empty($conv['notes'][0])) {
                $ledgerDetail = trim(substr($postedDetail . ' [' . $conv['notes'][0] . ']', 0, 300));
            }
        }
        $saved = $saved ?? psSaveStockAdjustment($conn, [
            'token' => $_POST['adjustment_token'] ?? '',
            'product_id' => $postedProduct,
            'branch' => $postedBranch,
            'direction' => $postedDirection,
            'quantity' => $ledgerQty,
            'reason' => $postedReason,
            'reason_detail' => $ledgerDetail,
        ]);
        if (!empty($saved['ok'])) {
            $_SESSION['ps_adjustment_notice'] = 'Stock adjustment saved.';
            mysqli_close($conn);
            header('Location: stock_adjustment.php?branch=' . rawurlencode($branch));
            exit;
        }
        $pageError = (string)($saved['error'] ?? 'Stock adjustment could not be saved. Please try again.');
    }
} else {
    psAdjustmentTokenIssue();
}

$token = psAdjustmentTokenPeek();
if ($token === '') {
    $token = psAdjustmentTokenIssue();
}

$products = $branch !== '' ? psAdjustmentProductChoices($conn, $branch, $company) : [];
$activeBranch = $branch !== '' ? $branch : 'No branch selected';
$packAssets = psPackUnitAssets($conn, $company);

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Adjustment</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Stock Adjustment</h1>
    <p>Branch: <?php echo htmlspecialchars($activeBranch, ENT_QUOTES, 'UTF-8'); ?></p>
    <p>Increase or decrease the ledger quantity for one product at the branch you select. This does not change the quantity stored on the product. A product stays off the ledger until it has an opening balance.</p>

    <form method="GET" action="">
        <select name="branch">
            <?php if (!$allowed): ?>
                <option value="">No branch</option>
            <?php else: ?>
                <?php foreach ($allowed as $branchName): ?>
                    <option value="<?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $branchName === $branch ? ' selected' : ''; ?>><?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <button type="submit">Show products</button>
    </form>

    <?php if ($notice !== ''): ?>
        <p><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($pageError !== ''): ?>
        <p><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($branch !== ''): ?>
        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <input type="hidden" name="adjustment_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
            <p>
                <label>
                    Product
                    <select name="product_id" required>
                        <option value="">Select a product</option>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $label = $product['product'] !== '' ? $product['product'] : $product['product_id'];
                            $label .= ' — ' . $product['product_id'];
                            if (!empty($product['barcode'])) {
                                $label .= ' · BC ' . $product['barcode'];
                            }
                            if ($product['shared_name']) {
                                $label .= ' (shared name)';
                            }
                            ?>
                            <option value="<?php echo htmlspecialchars($product['product_id'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $postedProduct === $product['product_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p>
                <label>
                    Direction
                    <select name="direction">
                        <option value="increase"<?php echo $postedDirection === 'increase' ? ' selected' : ''; ?>>Increase</option>
                        <option value="decrease"<?php echo $postedDirection === 'decrease' ? ' selected' : ''; ?>>Decrease</option>
                    </select>
                </label>
            </p>
            <p>
                <label>
                    Quantity
                    <input type="text" name="quantity" inputmode="decimal" value="<?php echo htmlspecialchars($postedQuantity, ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <select name="unit" data-pack-unit-for="product_id" aria-label="Unit" hidden><option value="<?php echo htmlspecialchars($postedUnit, ENT_QUOTES, 'UTF-8'); ?>"></option></select>
            </p>
            <p>
                <label>
                    Reason
                    <select name="reason" required>
                        <option value="">Select a reason</option>
                        <?php foreach (psAdjustmentReasons() as $reason): ?>
                            <option value="<?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $postedReason === $reason ? ' selected' : ''; ?>><?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p>
                <label>
                    Details
                    <input type="text" name="reason_detail" maxlength="300" value="<?php echo htmlspecialchars($postedDetail, ENT_QUOTES, 'UTF-8'); ?>">
                </label>
            </p>
            <p>Choose Other and type the reason when none of the listed reasons fit. Details are optional for the other reasons.</p>
            <p><button type="submit">Save adjustment</button></p>
        </form>
    <?php endif; ?>
<?php echo $packAssets; ?>
<?php ps_close(); ?>
</body>
</html>
