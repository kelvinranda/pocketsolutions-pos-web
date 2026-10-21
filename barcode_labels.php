<?php
/**
 * Phase 13 — Browser-printable barcode labels (Code 128 via picqer).
 * Encodes the exact stored goods.barcode value (leading zeros preserved).
 * No thermal print API / no auto-assign of barcodes.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/barcode.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
mysqli_set_charset($conn, 'utf8mb4');

psBranchRequireAuthRedirect();
$branchGate = psRequireSessionProductBranch($conn);
if (empty($branchGate['ok'])) {
    die(htmlspecialchars((string)($branchGate['error'] ?? 'That branch is not available.'), ENT_QUOTES, 'UTF-8'));
}
$branch = (string)$branchGate['branch'];
psEnsureGoodsBarcodeSchema($conn);

$error = '';
$labels = [];
$copies = 1;
$selectedIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    $copiesRaw = (string)($_POST['copies'] ?? '1');
    $copies = (int)$copiesRaw;
    if ($copies < 1 || $copies > 50) {
        $error = 'Copies must be between 1 and 50.';
    } else {
        $rawIds = $_POST['product_id'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        foreach ($rawIds as $pid) {
            $pid = trim((string)$pid);
            if ($pid !== '') {
                $selectedIds[] = $pid;
            }
        }
        $resolved = psBarcodeLabelProducts($conn, $branch, $selectedIds);
        if (empty($resolved['ok'])) {
            $error = (string)($resolved['error'] ?? 'Labels could not be prepared.');
        } else {
            foreach ($resolved['rows'] as $row) {
                $code = (string)$row['barcode'];
                $img = psBarcodeRenderPng($code, 2, 40);
                if (empty($img['ok'])) {
                    $error = 'Could not encode barcode ' . $code . '.';
                    $labels = [];
                    break;
                }
                for ($i = 0; $i < $copies; $i++) {
                    $labels[] = [
                        'product_id' => (string)$row['product_id'],
                        'product' => (string)$row['product'],
                        'price' => $row['price'],
                        'barcode' => $code,
                        'png_b64' => base64_encode($img['png']),
                    ];
                }
            }
        }
    }

    }
}

$list = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT product_id, product, price, barcode
     FROM goods
     WHERE branch = ? AND barcode IS NOT NULL AND barcode <> ''
     ORDER BY product, product_id
     LIMIT 500"
);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $branch);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $list[] = $row;
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($conn);

$printMode = $labels !== [] && $error === '';
require_once __DIR__ . '/includes/ps_layout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Labels</title>
    <?php if (!$printMode) { ps_head(); } ?>
</head>
<body class="<?php echo $printMode ? '' : 'ps-app'; ?>">
<?php if (!$printMode) { ps_open(); } ?>

<?php if ($printMode): ?>
    <div class="no-print actions">
        <button type="button" onclick="window.print()">Print labels</button>
        <a href="barcode_labels.php">Back</a>
        <span class="hint">Browser print only — no direct thermal printer API. Use a label-compatible paper size in the print dialog.</span>
    </div>
    <div class="labels">
        <?php foreach ($labels as $lab): ?>
            <div class="label">
                <div class="name"><?php echo htmlspecialchars($lab['product']); ?></div>
                <div class="meta">SKU <?php echo htmlspecialchars($lab['product_id']); ?>
                    · $<?php echo number_format((float)$lab['price'], 2); ?></div>
                <img src="data:image/png;base64,<?php echo htmlspecialchars($lab['png_b64']); ?>"
                     alt="<?php echo htmlspecialchars($lab['barcode']); ?>">
                <div class="code"><?php echo htmlspecialchars($lab['barcode']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <script>window.addEventListener('load', function () { /* optional auto-print disabled */ });</script>
<?php else: ?>
    <h1>Barcode Labels</h1>
    <p class="hint">
        Prints browser-printable Code 128 labels using the exact stored barcode (leading zeros preserved).
        Products without a barcode are omitted. This does not assign barcodes and does not talk to a thermal printer API —
        use your OS print dialog (label stock / PDF). Cash drawer, scale, and customer display are not supported here.
        Branch: <strong><?php echo htmlspecialchars($branch); ?></strong>
    </p>
    <?php if ($error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <?php if (!$list): ?>
        <p class="hint">No products with barcodes on this branch. Assign barcodes on Items, then return here.</p>
        <p><a href="items_list.php">Open Items</a></p>
    <?php else: ?>
        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <div class="actions">
                <label>Copies per product
                    <input type="number" name="copies" min="1" max="50" value="<?php echo (int)$copies; ?>">
                </label>
                <button type="submit">Generate labels</button>
                <a href="items_list.php">Items</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th>Product ID</th>
                        <th>Product</th>
                        <th>Barcode</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $row): ?>
                        <?php $pid = (string)$row['product_id']; ?>
                        <tr>
                            <td><input type="checkbox" name="product_id[]" value="<?php echo htmlspecialchars($pid); ?>"
                                <?php echo in_array($pid, $selectedIds, true) ? ' checked' : ''; ?>></td>
                            <td><?php echo htmlspecialchars($pid); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['product']); ?></td>
                            <td><code><?php echo htmlspecialchars((string)$row['barcode']); ?></code></td>
                            <td>$<?php echo number_format((float)$row['price'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$printMode) { ps_close(); } ?>
</body>
</html>
