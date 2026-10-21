<?php
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}
require_once __DIR__ . '/includes/branch_access.php';
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

// Phase 9.6: authorised registered branches only (not merely ∈ branchies).
$allowed = psSessionStockBranches($conn);

$branch = trim((string)($_POST['branch'] ?? $_GET['branch'] ?? ''));
if (!isset($allowed[$branch])) {
    $resolved = psBranchResolveWorking($conn);
    $branch = !empty($resolved['ok']) ? (string)$resolved['branch'] : '';
    if (!isset($allowed[$branch])) {
        $branch = $allowed ? (string)reset($allowed) : '';
    }
}

$pageError = '';
$notice = '';
if (!empty($_SESSION['ps_opening_notice'])) {
    $notice = (string)$_SESSION['ps_opening_notice'];
    unset($_SESSION['ps_opening_notice']);
}

// 2026-10-18: company switch — may the POS sell when stock has run out? (Admins; web POS and Windows tills.)
require_once __DIR__ . '/includes/cash_controls.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allow_negative_stock'])) {
    if (!psCsrfValidate()) {
        $pageError = 'Invalid security token. Reload and try again.';
    } else {
        $r = psSetCompanyAllowsNegativeStock($conn, $_POST['allow_negative_stock'] === '1', $email);
        if (!empty($r['ok'])) {
            $_SESSION['ps_opening_notice'] = $_POST['allow_negative_stock'] === '1'
                ? 'Saved. The POS will now sell even when stock runs out.'
                : 'Saved. The POS will now stop a sale when there is not enough stock.';
            header('Location: opening_stock.php?branch=' . rawurlencode($branch));
            exit;
        }
        $pageError = (string)($r['error'] ?? 'The setting could not be saved.');
    }
}
$allowNegativeStock = psCompanyAllowsNegativeStock($conn);
$isAdmin = strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin';

function psOpeningPostedLines(array $post, string $branch, string $company, string $email, $userId): array
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
    if (!isset($allowed[$postedBranch])) {
        $pageError = 'Select a branch you can use.';
    } else {
        $branch = $postedBranch;
        $lines = psOpeningPostedLines($_POST, $branch, $company, $email, $userId);
        if ($action === 'start_all') {
            // 2026-10-14: track every remaining product at this branch from its calculated stock.
            $res = psStockStartTrackingBranch($conn, $branch, '', $userId, $email);
            if (!empty($res['ok'])) {
                $n = (int)($res['started'] ?? 0);
                $_SESSION['ps_opening_notice'] = $n > 0
                    ? 'Stock tracking started for ' . $n . ' product' . ($n === 1 ? '' : 's') . ' at ' . $branch . '. Sales now check and reduce stock on hand.'
                    : 'Every product at ' . $branch . ' is already tracked.';
                if (function_exists('psSecurityAudit') && $n > 0) {
                    psSecurityAudit($conn, 'STOCK_TRACKING_START', [
                        'summary' => 'Stock tracking started for all products',
                        'target_type' => 'branch',
                        'target_id' => $branch,
                        'after' => ['products' => $n],
                    ]);
                }
                header('Location: opening_stock.php?branch=' . rawurlencode($branch));
                exit;
            }
            $pageError = (string)($res['error'] ?? 'Stock tracking could not be started.');
        } elseif ($action === 'save') {
            if (!$lines) {
                $pageError = 'Select at least one product.';
            } elseif (!mysqli_begin_transaction($conn)) {
                error_log('opening balance transaction did not start');
                $pageError = 'The opening balances could not be saved.';
            } else {
                $saved = psSaveOpeningBatch($conn, $lines, false);
                if (empty($saved['ok'])) {
                    mysqli_rollback($conn);
                    $pageError = (string)($saved['error'] ?? 'No opening balances were saved.');
                } elseif (!mysqli_commit($conn)) {
                    mysqli_rollback($conn);
                    error_log('opening balance commit failed');
                    $pageError = 'No opening balances were saved. The opening balances could not be saved.';
                } else {
                    $count = count($saved['ids'] ?? []);
                    $_SESSION['ps_opening_notice'] = $count === 1
                        ? '1 opening balance was saved.'
                        : $count . ' opening balances were saved.';
                    mysqli_close($conn);
                    header('Location: opening_stock.php?branch=' . rawurlencode($branch));
                    exit;
                }
            }
        } elseif ($action === 'review') {
            if (!$lines) {
                $pageError = 'Select at least one product.';
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
                        'legacy_plain' => $legacy === null ? '' : psStockQtyPlain($legacy),
                        'found' => (bool)$goods,
                    ];
                }
            }
        }
    }
    } // csrf else
}

$rows = ($branch !== '' && $phase === 'list') ? psOpeningStockRows($conn, $branch, $company) : [];
// Every product on the branch, whatever its company tag: stock is tracked per product and branch.
$untracked = 0;
if ($branch !== '' && $phase === 'list') {
    foreach (psOpeningStockRows($conn, $branch, '') as $r) {
        if (empty($r['migrated'])) {
            $untracked++;
        }
    }
}
$activeBranch = $branch !== '' ? $branch : 'No branch selected';

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opening Stock</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Opening Stock</h1>
    <p class="ps-page-lead">Branch: <?php echo htmlspecialchars($activeBranch, ENT_QUOTES, 'UTF-8'); ?>. The opening quantity is where a product's stock count starts. After that, goods receipts, sales, refunds, transfers and adjustments keep it up to date, and the POS stops a sale when there isn't enough stock. New products start at 0 automatically.</p>

    <div class="ps-card" style="margin:12px 0">
        <div class="ps-card-header" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
                <h2 class="ps-card-title">Selling when stock runs out: <?php echo $allowNegativeStock ? 'Allowed' : 'Blocked'; ?></h2>
                <p class="ps-card-desc"><?php echo $allowNegativeStock
                    ? 'The POS sells even when a product shows no stock left (stock goes below zero).'
                    : 'The POS stops a sale when there is not enough stock, and says how many are available.'; ?>
                    Applies to the web POS and the Windows tills (after their next sync), for products that have an opening stock.<?php echo $isAdmin ? '' : ' Only an administrator can change this.'; ?></p>
            </div>
            <?php if ($isAdmin): ?>
            <form method="POST" action="">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="allow_negative_stock" value="<?php echo $allowNegativeStock ? '0' : '1'; ?>">
                <button class="ps-btn <?php echo $allowNegativeStock ? 'ps-btn-default' : 'ps-btn-outline'; ?>" type="submit"><?php echo $allowNegativeStock ? 'Block selling without stock' : 'Allow selling without stock'; ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>

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
        <div class="ps-alert ps-alert-success" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($pageError !== ''): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($phase === 'list' && $branch !== '' && $untracked > 0): ?>
    <div class="ps-card">
        <div class="ps-card-header">
            <h2 class="ps-card-title">Track all products</h2>
            <p class="ps-card-desc"><?php echo (int)$untracked; ?> product<?php echo $untracked === 1 ? ' is' : 's are'; ?> not tracked yet at <?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>. Start them all at their calculated stock (receipts and transfers in, less sales and transfers out; never below 0). You can correct any count afterwards with a stock adjustment or stock take.</p>
        </div>
        <div class="ps-card-content">
            <form method="POST" action="" onsubmit="return confirm('Start stock tracking for all <?php echo (int)$untracked; ?> products at this branch? Sales will then be blocked when stock runs out.');">
                <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                <input type="hidden" name="action" value="start_all">
                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="ps-btn ps-btn-default" type="submit">Start tracking all <?php echo (int)$untracked; ?> products</button>
            </form>
        </div>
    </div>
    <p class="hint">Or set counted quantities for selected products below.</p>
    <?php endif; ?>

    <?php if ($phase === 'confirm'): ?>
        <h2>Confirm opening balances</h2>
        <p>These rows will be written for <?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>. If any row cannot be saved, none of them are saved.</p>
        <form method="POST" action="">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="branch" value="<?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?>">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Branch</th>
                        <th>Calculated stock</th>
                        <th>Quantity to write</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $line): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($line['product'] !== '' ? $line['product'] : $line['product_id'], ENT_QUOTES, 'UTF-8'); ?>
                                <input type="hidden" name="selected[]" value="<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="product_name[<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($line['product'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="qty[<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($line['quantity'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="reason[<?php echo htmlspecialchars($line['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($line['reason'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td><?php echo htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($line['legacy_plain'] !== '' ? $line['legacy_plain'] : 'Unavailable', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($line['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($line['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="submit">Save opening balances</button>
                <a href="opening_stock.php?branch=<?php echo rawurlencode($branch); ?>">Back</a>
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
                        <th>Product</th>
                        <th>Calculated stock</th>
                        <th>Ledger balance</th>
                        <th>Status</th>
                        <th>Opening quantity</th>
                        <th>Reason if changed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7">No products for this branch.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?php if (!$row['migrated'] && $row['legacy'] !== null): ?>
                                        <input type="checkbox" name="selected[]" value="<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8'); ?>
                                    <div><?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ($row['shared_name']): ?>
                                        <div>This name is shared by more than one product id. The stock list uses the name, so the calculated quantity is the same for each.</div>
                                    <?php endif; ?>
                                    <input type="hidden" name="product_name[<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8'); ?>">
                                </td>
                                <td><?php echo htmlspecialchars($row['legacy_plain'] !== '' ? $row['legacy_plain'] : 'Unavailable', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['migrated'] ? $row['ledger'] : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $row['migrated'] ? '<span class="ps-badge ps-badge-success">Tracked</span>' : '<span class="ps-badge ps-badge-secondary">Not tracked</span>'; ?></td>
                                <td>
                                    <?php if ($row['migrated']): ?>
                                        <?php echo htmlspecialchars($row['opening_plain'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php elseif ($row['legacy'] !== null): ?>
                                        <input type="text" name="qty[<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="<?php echo htmlspecialchars($row['legacy_plain'], ENT_QUOTES, 'UTF-8'); ?>" inputmode="decimal">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$row['migrated'] && $row['legacy'] !== null): ?>
                                        <input type="text" name="reason[<?php echo htmlspecialchars($row['product_id'], ENT_QUOTES, 'UTF-8'); ?>]" value="" maxlength="300">
                                    <?php endif; ?>
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
