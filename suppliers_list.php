<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/supplier_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

psEnsureSupplierMasterSchema($conn);
psEnsureSupplierAuditSchema($conn);

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$statusFilterRaw = array_key_exists('status_filter', $_GET)
    ? (string)$_GET['status_filter']
    : (array_key_exists('status_filter', $_POST) ? (string)$_POST['status_filter'] : 'active');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'active', 'inactive'], true)) {
    $statusFilterKey = 'active';
}

$error = '';
$notice = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';
$editId = (int)($_GET['edit'] ?? 0);

if (!empty($_GET['created'])) {
    $notice = 'Supplier created.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } else {

    if (!$signedIn) {
        $error = 'You must be signed in to change supplier status.';
    } else {
        $statusId = (int)($_POST['supplier_id'] ?? 0);
        $postedStatus = (string)($_POST['new_status'] ?? '');
        $statusResult = psSetSupplierStatus($conn, $statusId, $postedStatus);
        if ($statusResult['ok']) {
            $q = 'status_filter=' . rawurlencode($statusFilterKey);
            if ($search !== '') {
                $q .= '&search=' . rawurlencode($search);
            }
            header('Location: suppliers_list.php?' . $q);
            exit;
        }
        $error = (string)($statusResult['error'] ?? 'Supplier status could not be saved.');
    }

    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } elseif (!$signedIn) {
        $error = 'You must be signed in to edit a supplier.';
    } else {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $result = psUpdateSupplier($conn, $supplierId, [
            'supplier_code' => $_POST['supplier_code'] ?? '',
            'supplier_name' => $_POST['supplier_name'] ?? '',
            'contact_person' => $_POST['contact_person'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'address' => $_POST['address'] ?? '',
        ]);
        if ($result['ok']) {
            $q = 'status_filter=' . rawurlencode($statusFilterKey);
            if ($search !== '') {
                $q .= '&search=' . rawurlencode($search);
            }
            header('Location: suppliers_list.php?' . $q . '&updated=1');
            exit;
        }
        $error = (string)($result['error'] ?? 'The supplier could not be saved.');
        $editId = $supplierId;
    }
}

if (!empty($_GET['updated'])) {
    $notice = 'Supplier updated.';
}

$list = ['ok' => false, 'rows' => []];
if (!$signedIn) {
    $error = $error !== '' ? $error : 'You must be signed in to view suppliers.';
} else {
    $list = psSupplierList($conn, $search, $statusFilterKey);
    if (!$list['ok']) {
        $error = (string)($list['error'] ?? 'Suppliers could not be loaded.');
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
    <title>Suppliers</title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
    <h1>Suppliers</h1>
    <p class="ps-page-lead">
        Global supplier master. Identity is <strong>supplier code</strong>, not name.
        Inactive suppliers stay for history and are not selectable for new purchasing.
        Suppliers are never hard-deleted from this screen.
    </p>

    <?php if ($error !== ''): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="ps-alert ps-alert-info" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Filters</h2>
                <p class="ps-card-desc">Search by code, name, or phone.</p>
            </div>
            <div class="ps-card-content">
                <form method="GET" action="" class="ps-form-inline filter-form">
                    <input class="ps-input" type="search" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Code, name, phone…">
                    <select class="ps-input" id="status_filter" name="status_filter">
                        <option value="active"<?php echo $statusFilterKey === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?php echo $statusFilterKey === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                        <option value="all"<?php echo $statusFilterKey === 'all' ? ' selected' : ''; ?>>All</option>
                    </select>
                    <button class="ps-btn ps-btn-default" type="submit">Filter</button>
                    <a class="ps-btn ps-btn-outline" href="create_supplier.php">Create supplier</a>
                    <a class="ps-btn ps-btn-outline" href="supplier_audit_history.php">Supplier audit</a>
                </form>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="ps-empty">
                <p>No suppliers match this filter.</p>
                <div class="ps-link-row">
                    <a class="ps-btn ps-btn-default ps-btn-sm" href="create_supplier.php">Create supplier</a>
                    <a class="ps-btn ps-btn-outline ps-btn-sm" href="supplier_audit_history.php">Supplier audit</a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($editId > 0): ?>
                <form method="POST" action="" id="edit-supplier-<?php echo (int)$editId; ?>" hidden aria-hidden="true">
                    <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                </form>
            <?php endif; ?>
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Supplier list</h2>
                    <p class="ps-card-desc">Use the actions menu for edit, status, audit, and history.</p>
                </div>
                <div class="ps-card-content ps-card-flush">
            <div class="ps-table-wrap">
            <table class="ps-table ps-supplier-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int)$row['id'];
                    $isEdit = $editId === $id;
                    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
                    $isActive = $status === PS_SUPPLIER_STATUS_ACTIVE;
                    $formId = 'edit-supplier-' . $id;
                    $code = (string)$row['supplier_code'];
                    $name = (string)$row['supplier_name'];
                    ?>
                    <tr>
                        <?php if ($isEdit): ?>
                            <td>
                                <input form="<?php echo $formId; ?>" type="hidden" name="supplier_id" value="<?php echo $id; ?>">
                                <input form="<?php echo $formId; ?>" type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                <input form="<?php echo $formId; ?>" type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <input class="ps-input" form="<?php echo $formId; ?>" type="text" name="supplier_code" maxlength="64" required title="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td><input class="ps-input" form="<?php echo $formId; ?>" type="text" name="supplier_name" maxlength="250" required title="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input class="ps-input" form="<?php echo $formId; ?>" type="text" name="contact_person" maxlength="250" value="<?php echo htmlspecialchars((string)$row['contact_person'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input class="ps-input" form="<?php echo $formId; ?>" type="text" name="phone" maxlength="64" value="<?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input class="ps-input" form="<?php echo $formId; ?>" type="email" name="email" maxlength="250" title="<?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><textarea class="ps-input" form="<?php echo $formId; ?>" name="address" maxlength="500" title="<?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?></textarea></td>
                            <td>
                                <span class="ps-badge <?php echo $isActive ? 'ps-badge-success' : 'ps-badge-secondary'; ?>">
                                    <?php echo htmlspecialchars(psSupplierStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="col-actions">
                                <div class="ps-action-menu">
                                    <button type="button" class="ps-btn ps-btn-outline ps-btn-sm ps-btn-icon" data-ps-action-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Row actions">⋯</button>
                                    <div class="ps-action-menu-panel" role="menu" hidden>
                                        <button form="<?php echo $formId; ?>" type="submit" name="update" value="1" role="menuitem">Save</button>
                                        <a role="menuitem" href="suppliers_list.php?status_filter=<?php echo rawurlencode($statusFilterKey); ?><?php echo $search !== '' ? '&search=' . rawurlencode($search) : ''; ?>">Cancel</a>
                                    </div>
                                </div>
                            </td>
                        <?php else: ?>
                            <td title="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td title="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td title="<?php echo htmlspecialchars((string)$row['contact_person'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['contact_person'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td title="<?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td title="<?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td title="<?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['address'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="ps-badge <?php echo $isActive ? 'ps-badge-success' : 'ps-badge-secondary'; ?>">
                                    <?php echo htmlspecialchars(psSupplierStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="col-actions">
                                <div class="ps-action-menu">
                                    <button type="button" class="ps-btn ps-btn-outline ps-btn-sm ps-btn-icon" data-ps-action-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Row actions">⋯</button>
                                    <div class="ps-action-menu-panel" role="menu" hidden>
                                        <a role="menuitem" href="suppliers_list.php?edit=<?php echo $id; ?>&amp;status_filter=<?php echo rawurlencode($statusFilterKey); ?><?php echo $search !== '' ? '&amp;search=' . rawurlencode($search) : ''; ?>">Edit</a>
                                        <form method="POST" action="">
                                            <?php echo function_exists('psCsrfField') ? psCsrfField() : ''; ?>
                                            <input type="hidden" name="supplier_id" value="<?php echo $id; ?>">
                                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilterKey, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php if ($isActive): ?>
                                                <input type="hidden" name="new_status" value="<?php echo PS_SUPPLIER_STATUS_INACTIVE; ?>">
                                                <button type="submit" name="set_status" value="1" role="menuitem" class="is-danger">Deactivate</button>
                                            <?php else: ?>
                                                <input type="hidden" name="new_status" value="<?php echo PS_SUPPLIER_STATUS_ACTIVE; ?>">
                                                <button type="submit" name="set_status" value="1" role="menuitem">Activate</button>
                                            <?php endif; ?>
                                        </form>
                                        <a role="menuitem" href="supplier_audit_history.php?supplier_id=<?php echo $id; ?>">Audit</a>
                                        <a role="menuitem" href="supplier_purchasing_history.php?supplier_id=<?php echo $id; ?>">History</a>
                                    </div>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
