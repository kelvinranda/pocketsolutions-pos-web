<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/purchase_return.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsurePurchaseReturnAllSchema($conn);

$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$error = '';
$branch = '';
$grvNumber = trim((string)($_GET['grv_number'] ?? $_POST['grv_number'] ?? ''));
$grvOptions = [];
$header = null;
$lines = [];
$submitToken = '';

if (!$signedIn) {
    $error = 'You must be signed in to create a purchase return.';
} else {
    $branchCheck = psRequireSessionProductBranch($conn);
    if (!$branchCheck['ok']) {
        $error = (string)($branchCheck['error'] ?? 'That branch is not available.');
    } else {
        $branch = $branchCheck['branch'];
        $opt = psPrReturnableGrvListForSession($conn);
        if ($opt['ok']) {
            $grvOptions = $opt['rows'] ?? [];
        }
            if ($grvNumber !== '') {
            $detail = psPrReturnableLinesForGrv($conn, $grvNumber);
            if (!$detail['ok']) {
                $error = (string)($detail['error'] ?? 'Goods receipt not found.');
                $grvNumber = '';
            } else {
                $header = $detail['header'];
                $lines = $detail['lines'];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $signedIn && $branch !== '') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!psPrConsumeSubmitToken($_POST['pr_submit_token'] ?? '')) {
        $error = 'This return was already submitted or the form expired. Reload and try again.';
    } else {
        $saved = psSavePurchaseReturn($conn, [
            'grv_number' => $_POST['grv_number'] ?? '',
            'return_date' => $_POST['return_date'] ?? '',
            'reason' => $_POST['reason'] ?? '',
            'branch' => $_POST['branch'] ?? null,
            'goods_receipt_line_ids' => $_POST['goods_receipt_line_id'] ?? [],
            'return_quantities' => $_POST['return_qty'] ?? [],
        ]);
        if (!$saved['ok']) {
            $error = (string)($saved['error'] ?? 'The purchase return could not be saved.');
            $grvNumber = trim((string)($_POST['grv_number'] ?? ''));
            if ($grvNumber !== '') {
                $detail = psPrReturnableLinesForGrv($conn, $grvNumber);
                if ($detail['ok']) {
                    $header = $detail['header'];
                    $lines = $detail['lines'];
                }
            }
        } else {
            header('Location: purchase_return_view.php?id=' . (int)$saved['return_id'] . '&created=1');
            mysqli_close($conn);
            exit;
        }
    }

    }
}

if ($signedIn && $branch !== '') {
    $submitToken = psPrIssueSubmitToken();
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase Return</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>New Purchase Return</h1>
    <p class="hint">Select a numbered GRV on this branch. Return qty must be &gt; 0 and ≤ returnable (received − previously returned). Cost comes from the original receiving line.</p>
    <p class="hint">Branch: <?php echo htmlspecialchars($branch !== '' ? $branch : '(none)', ENT_QUOTES, 'UTF-8'); ?></p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn && $branch !== ''): ?>
        <form method="GET" action="" class="field">
            <label for="grv_number">Goods receipt (GRV)</label>
            <select id="grv_number" name="grv_number" onchange="this.form.submit()">
                <option value="">— Select GRV —</option>
                <?php foreach ($grvOptions as $optRow): ?>
                    <?php $gn = (string)$optRow['grv_number']; ?>
                    <option value="<?php echo htmlspecialchars($gn, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $gn === $grvNumber ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($gn . ' — ' . (string)$optRow['supplier_name'] . ' — ' . (string)$optRow['date_'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($header !== null): ?>
            <p class="hint">
                Supplier: <?php echo htmlspecialchars((string)$header['product'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($header['po_number'])): ?>
                    | PO: <?php echo htmlspecialchars((string)$header['po_number'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
                | GRV date: <?php echo htmlspecialchars((string)$header['date_'], ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <input type="hidden" name="grv_number" value="<?php echo htmlspecialchars($grvNumber, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="pr_submit_token" value="<?php echo htmlspecialchars($submitToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="field">
                    <label for="return_date">Return date</label>
                    <input type="date" id="return_date" name="return_date" required value="<?php echo htmlspecialchars((string)($_POST['return_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="field">
                    <label for="reason">Reason</label>
                    <textarea id="reason" name="reason" required maxlength="500"><?php echo htmlspecialchars((string)($_POST['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Product ID</th>
                            <th>Received</th>
                            <th>Returned</th>
                            <th>Returnable</th>
                            <th>Unit cost</th>
                            <th>Return qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $idx => $line): ?>
                            <?php
                            $returnable = (string)$line['returnable_qty'];
                            $can = psStockDecimalCmp($returnable, '0') > 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$line['product'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$line['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psStockQtyPlain((string)$line['received_qty']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psStockQtyPlain((string)$line['returned_qty']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(psStockQtyPlain($returnable), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$line['unit_cost'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <input type="hidden" name="goods_receipt_line_id[<?php echo (int)$idx; ?>]" value="<?php echo (int)$line['id']; ?>">
                                    <input type="text" name="return_qty[<?php echo (int)$idx; ?>]" <?php echo $can ? '' : 'disabled'; ?>
                                           placeholder="<?php echo $can ? '0' : 'n/a'; ?>"
                                           value="">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:12px;">
                    <button type="submit" name="confirm" value="1">Complete return</button>
                    <a href="purchase_returns_list.php">Cancel</a>
                </p>
            </form>
        <?php elseif ($grvOptions === []): ?>
            <p>No numbered GRVs with returnable quantity on this branch.</p>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
