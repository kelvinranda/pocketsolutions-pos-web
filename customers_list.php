<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/customer_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureCustomerMasterSchema($conn);
psEnsureCustomerAuditSchema($conn);

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$statusFilterRaw = array_key_exists('status_filter', $_GET)
    ? (string)$_GET['status_filter']
    : (array_key_exists('status_filter', $_POST) ? (string)$_POST['status_filter'] : 'active');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'active', 'inactive'], true)) {
    $statusFilterKey = 'active';
}
$typeFilterRaw = array_key_exists('type_filter', $_GET)
    ? (string)$_GET['type_filter']
    : (array_key_exists('type_filter', $_POST) ? (string)$_POST['type_filter'] : 'all');
$typeFilterKey = strtolower(trim($typeFilterRaw));
if (!in_array($typeFilterKey, ['all', 'individual', 'business'], true)) {
    $typeFilterKey = 'all';
}

$error = '';
$notice = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

if (!empty($_GET['created'])) {
    $notice = 'Customer created.';
}
if (!empty($_GET['updated'])) {
    $notice = 'Customer updated.';
}

// 2026-10-18: company switch — Till users may add new customers at the POS.
require_once __DIR__ . '/includes/cash_controls.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['till_add_customers'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {
        $r = psTillSetMayAddCustomers($conn, $_POST['till_add_customers'] === '1', (string)($_SESSION['logger'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: customers_list.php?till_setting=1');
            exit;
        }
        $error = (string)$r['error'];
    }
}
if (!empty($_GET['till_setting'])) {
    $notice = 'Setting saved.';
}
$tillAddCustomers = psTillMayAddCustomers($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to change customer status.';
    } else {
        $statusId = (int)($_POST['customer_id'] ?? 0);
        $postedStatus = (string)($_POST['new_status'] ?? '');
        $statusResult = psSetCustomerStatus($conn, $statusId, $postedStatus);
        if ($statusResult['ok']) {
            $q = 'status_filter=' . rawurlencode($statusFilterKey)
                . '&type_filter=' . rawurlencode($typeFilterKey);
            if ($search !== '') {
                $q .= '&search=' . rawurlencode($search);
            }
            header('Location: customers_list.php?' . $q);
            exit;
        }
        $error = (string)($statusResult['error'] ?? 'Customer status could not be saved.');
    }

    }
}

$list = ['ok' => false, 'rows' => []];
if (!$signedIn) {
    $error = $error !== '' ? $error : 'You must be signed in to view customers.';
} else {
    $list = psCustomerList($conn, $search, $statusFilterKey, $typeFilterKey);
    if (!$list['ok']) {
        $error = (string)($list['error'] ?? 'Customers could not be loaded.');
    }
}
$rows = $list['rows'] ?? [];

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Customers</h1>
    <p class="hint">
        Global customer master. Identity is <strong>customer code</strong> (and id), not name, phone, or email.
        Inactive customers stay for history and remain viewable (including linked sales). Customers are never hard-deleted from this screen.
        Linked sales count is from <code>customer_master_id</code> only — not a balance owed.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (strtolower(trim((string)($_SESSION['level'] ?? ''))) === 'admin'): ?>
    <form method="post" action="customers_list.php" class="ps-card" style="padding:14px 16px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <?php echo psCsrfField(); ?>
        <div style="flex:1;min-width:260px">
            <strong>Till users may add new customers at the POS:</strong> <?php echo $tillAddCustomers ? 'On' : 'Off'; ?>
            <div class="hint">Web POS and Windows tills. Till users can add a customer (name and phone) while selling, not edit or delete one.
                Supervisors, managers and admins can always add customers at the POS. Credit accounts and limits stay with supervisors and admins.</div>
        </div>
        <input type="hidden" name="till_add_customers" value="<?php echo $tillAddCustomers ? '0' : '1'; ?>">
        <button type="submit" class="ps-btn <?php echo $tillAddCustomers ? 'ps-btn-outline' : 'ps-btn-default'; ?>"><?php echo $tillAddCustomers ? 'Turn off' : 'Turn on'; ?></button>
    </form>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <form method="GET" action="" class="customer-toolbar">
            <div>
                <label for="search">Search</label>
                <input type="search" id="search" name="search"
                       value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="Code, name, phone…">
            </div>
            <div>
                <label for="status_filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="active"<?php echo $statusFilterKey === 'active' ? ' selected' : ''; ?>>Active</option>
                    <option value="inactive"<?php echo $statusFilterKey === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                    <option value="all"<?php echo $statusFilterKey === 'all' ? ' selected' : ''; ?>>All</option>
                </select>
            </div>
            <div>
                <label for="type_filter">Type</label>
                <select id="type_filter" name="type_filter">
                    <option value="all"<?php echo $typeFilterKey === 'all' ? ' selected' : ''; ?>>All</option>
                    <option value="individual"<?php echo $typeFilterKey === 'individual' ? ' selected' : ''; ?>>Individual</option>
                    <option value="business"<?php echo $typeFilterKey === 'business' ? ' selected' : ''; ?>>Business</option>
                </select>
            </div>
            <div>
                <button type="submit">Filter</button>
            </div>
            <div>
                <a href="create_customer.php">Create customer</a>
                &nbsp;|&nbsp;
                <a href="customer_audit_history.php">Customer audit</a>
            </div>
        </form>

        <?php if ($rows === []): ?>
            <p>No customers match this filter.</p>
        <?php else: ?>
            <table class="customer-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Linked sales</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int)$row['id'];
                    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
                    $isActive = $status === PS_CUSTOMER_STATUS_ACTIVE;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$row['customer_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psCustomerDisplayName($row), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(psCustomerTypeLabel((string)$row['customer_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="<?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo htmlspecialchars(psCustomerStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td><?php echo (int)($row['linked_sale_count'] ?? 0); ?></td>
                        <td class="row-actions">
                            <a href="customer_view.php?id=<?php echo $id; ?>">Open profile</a>
                            <form method="POST" action="" style="display:inline;">
        <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                <input type="hidden" name="customer_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="type_filter" value="<?php echo htmlspecialchars($typeFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($isActive): ?>
                                    <input type="hidden" name="new_status" value="<?php echo PS_CUSTOMER_STATUS_INACTIVE; ?>">
                                    <button type="submit" name="set_status" value="1">Deactivate</button>
                                <?php else: ?>
                                    <input type="hidden" name="new_status" value="<?php echo PS_CUSTOMER_STATUS_ACTIVE; ?>">
                                    <button type="submit" name="set_status" value="1">Activate</button>
                                <?php endif; ?>
                            </form>
                            <a href="customer_audit_history.php?customer_id=<?php echo $id; ?>">Audit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
