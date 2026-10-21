<?php
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
require_once __DIR__ . '/includes/stock_movement.php';

$company = trim((string)($_SESSION['the_company'] ?? ''));
$email = trim((string)($_SESSION['logger'] ?? ''));
$userId = null;
if ($email !== '') {
    $userStmt = mysqli_prepare($conn, 'SELECT id, email FROM users WHERE email = ? LIMIT 1');
    if ($userStmt) {
        mysqli_stmt_bind_param($userStmt, 's', $email);
        if (mysqli_stmt_execute($userStmt)) {
            $userResult = mysqli_stmt_get_result($userStmt);
            $userRow = $userResult ? mysqli_fetch_assoc($userResult) : null;
            if ($userRow) {
                $userId = (int)$userRow['id'];
                $email = (string)$userRow['email'];
            }
        }
        mysqli_stmt_close($userStmt);
    }
}

$branches = psAllBranchNames($conn);
$branchSet = array_fill_keys($branches, true);

$branch = trim((string)($_POST['branch'] ?? $_GET['branch'] ?? ''));
if (!isset($branchSet[$branch])) {
    $branch = trim((string)($_SESSION['selected_branch'] ?? ''));
    if (!isset($branchSet[$branch])) {
        $branch = $branches ? (string)$branches[0] : '';
    }
}

$pageError = '';
$notice = '';
if (!empty($_SESSION['ps_migration_notice'])) {
    $notice = (string)$_SESSION['ps_migration_notice'];
    unset($_SESSION['ps_migration_notice']);
}

function psMigrationPostedLines(array $post, string $branch, string $company, string $email, $userId): array
{
    $selected = $post['selected'] ?? [];
    if (!is_array($selected)) {
        return [];
    }
    $quantities = is_array($post['qty'] ?? null) ? $post['qty'] : [];
    $reasons = is_array($post['reason'] ?? null) ? $post['reason'] : [];
    $names = is_array($post['product_name'] ?? null) ? $post['product_name'] : [];
    $lines = [];
    $seen = [];
    foreach ($selected as $productId) {
        $productId = trim((string)$productId);
        if ($productId === '' || isset($seen[$productId])) {
            continue;
        }
        $seen[$productId] = true;
        $lines[] = [
            'product_id' => $productId,
            'product_name' => trim((string)($names[$productId] ?? '')),
            'branch' => $branch,
            'company' => $company,
            'quantity' => trim((string)($quantities[$productId] ?? '')),
            'reason' => trim((string)($reasons[$productId] ?? '')),
            'user_id' => $userId,
            'user_email' => $email,
        ];
    }
    return $lines;
}

function psMigrationValidateLines(array $lines): ?string
{
    foreach ($lines as $line) {
        $qty = psParseMigrationOpeningQuantity((string)($line['quantity'] ?? ''));
        if (empty($qty['ok'])) {
            $name = trim((string)($line['product_name'] ?? $line['product_id'] ?? 'Product'));
            return $name . ': ' . (string)($qty['error'] ?? 'Quantity must be a number.');
        }
        $reason = psParseMigrationReason((string)($line['reason'] ?? ''));
        if (empty($reason['ok'])) {
            $name = trim((string)($line['product_name'] ?? $line['product_id'] ?? 'Product'));
            return $name . ': ' . (string)($reason['error'] ?? 'Enter a migration reason.');
        }
    }
    return null;
}

$phase = 'list';
$pending = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!function_exists('psCsrfValidate')) {
        require_once __DIR__ . '/includes/security.php';
    }
    if (!psCsrfValidate()) {
        $pageError = 'Invalid security token. Reload and try again.';
    } else {
    $action = trim((string)($_POST['action'] ?? ''));
    $postedBranch = trim((string)($_POST['branch'] ?? ''));
    if (!isset($branchSet[$postedBranch])) {
        $pageError = 'Select a valid branch.';
    } else {
        $branch = $postedBranch;
        $lines = psMigrationPostedLines($_POST, $branch, $company, $email, $userId);
        $singleProductId = trim((string)($_POST['single_product_id'] ?? ''));
        if ($action === 'review' && $singleProductId !== '') {
            $lines = psMigrationPostedLines(
                ['selected' => [$singleProductId]] + $_POST,
                $branch,
                $company,
                $email,
                $userId
            );
        }
        if ($action === 'save') {
            $confirmed = trim((string)($_POST['confirm_opening'] ?? ''));
            if ($confirmed !== '1') {
                $pageError = 'Confirm the opening quantity before saving.';
            } elseif (!$lines) {
                $pageError = 'Select at least one product.';
            } elseif (($validation = psMigrationValidateLines($lines)) !== null) {
                $pageError = $validation;
            } elseif (!mysqli_begin_transaction($conn)) {
                error_log('legacy migration transaction did not start');
                $pageError = 'The migration could not be saved.';
            } else {
                $saved = psSaveLegacyMigrationBatch($conn, $lines, false);
                if (empty($saved['ok'])) {
                    mysqli_rollback($conn);
                    $pageError = (string)($saved['error'] ?? 'No migrations were saved.');
                } elseif (!mysqli_commit($conn)) {
                    mysqli_rollback($conn);
                    error_log('legacy migration commit failed');
                    $pageError = 'No migrations were saved. The migration could not be saved.';
                } else {
                    $count = count($saved['ids'] ?? []);
                    $_SESSION['ps_migration_notice'] = $count === 1
                        ? '1 product was migrated onto the stock ledger.'
                        : $count . ' products were migrated onto the stock ledger.';
                    mysqli_close($conn);
                    header('Location: stock_migration.php?branch=' . rawurlencode($branch));
                    exit;
                }
            }
        } elseif ($action === 'review') {
            if (!$lines) {
                $pageError = 'Select at least one product to migrate.';
            } elseif (($validation = psMigrationValidateLines($lines)) !== null) {
                $pageError = $validation;
            } else {
                $phase = 'confirm';
                foreach ($lines as $line) {
                    $goods = psGoodsForOpening($conn, $line['product_id'], $branch, $company);
                    $legacy = $goods ? psLegacyStockQuantity($conn, (string)$goods['product'], $branch) : null;
                    $pending[] = [
                        'product_id' => $line['product_id'],
                        'product' => $goods ? (string)$goods['product'] : $line['product_name'],
                        'quantity' => $line['quantity'],
                        'reason' => $line['reason'],
                        'legacy' => $legacy,
                        'legacy_label' => $legacy === null ? 'Not available' : psStockQtyPlain($legacy),
                        'found' => (bool)$goods,
                    ];
                }
            }
        }
    }
    } // csrf else
}

$rows = ($branch !== '' && $phase === 'list') ? psLegacyMigrationRows($conn, $branch, $company) : [];
$activeBranch = $branch !== '' ? $branch : 'No branch selected';

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legacy Stock Migration</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Legacy Stock Migration</h1>
    <p>Branch: <?php echo htmlspecialchars($activeBranch, ENT_QUOTES, 'UTF-8'); ?></p>
    <p>Move products onto the controlled stock ledger with an explicit opening balance. Legacy stock is shown for information only; nothing is copied automatically from the old quantity fields or receipts.</p>
    <p>Each product and branch is migrated once. Enter the opening quantity and a reason, then confirm before saving.</p>

    <form method="GET" action="">
        <select name="branch">
            <?php if (!$branches): ?>
                <option value="">No branch</option>
            <?php else: ?>
                <?php foreach ($branches as $branchName): ?>
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

    <?php if ($phase === 'confirm'): ?>
        <h2>Confirm legacy migration</h2>
        <p>These rows will receive an opening balance on the ledger for <?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>. If any row cannot be saved, none of them are saved.</p>
        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
            <table>
                <thead>
                    <tr>
                        <th>Product ID</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th>Legacy stock</th>
                        <th>Opening quantity</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $line): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php echo htmlspecialchars($line['product'] !== '' ? $line['product'] : $line['product_id'], ENT_QUOTES, 'UTF-8'); ?>
                                <input type="hidden" name="selected[]" value="<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="product_name[<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($line['product'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="qty[<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($line['quantity'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="reason[<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($line['reason'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td><?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($line['legacy_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($line['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($line['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <label>
                    <input type="checkbox" name="confirm_opening" value="1">
                    I confirm that this quantity represents the opening stock for this product at this branch.
                </label>
            </p>
            <p>
                <button type="submit">Save migration</button>
                <a href="stock_migration.php?branch=<?php echo rawurlencode($branch); ?>">Back</a>
            </p>
        </form>
    <?php elseif ($branch !== ''): ?>
        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
            <table>
                <thead>
                    <tr>
                        <th>Select</th>
                        <th>Product ID</th>
                        <th>Product name</th>
                        <th>Branch</th>
                        <th>Legacy stock</th>
                        <th>Migration status</th>
                        <th>Opening quantity</th>
                        <th>Migration reason</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9">No unmigrated products for this branch.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected[]" value="<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                </td>
                                <td><?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($row['shared_name']): ?>
                                        <div>Same name, different product id — migrate each row separately.</div>
                                    <?php endif; ?>
                                    <input type="hidden" name="product_name[<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8'); ?>">
                                </td>
                                <td><?php echo htmlspecialchars($row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['legacy_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <input type="text" name="qty[<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($row['legacy_plain'], ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal">
                                </td>
                                <td>
                                    <input type="text" name="reason[<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="" maxlength="300">
                                </td>
                                <td>
                                    <button type="submit" name="single_product_id" value="<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>">Review one</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <p><button type="submit">Review selected</button></p>
        </form>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
