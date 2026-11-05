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

const PS_SUPPLIER_PAGE_SIZES = [25, 50, 100];
const PS_SUPPLIER_SORTS = ['name' => 'Name', 'code' => 'Code', 'status' => 'Status', 'terms' => 'Payment terms', 'created' => 'Created'];

$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$statusFilterRaw = array_key_exists('status_filter', $_GET)
    ? (string)$_GET['status_filter']
    : (array_key_exists('status_filter', $_POST) ? (string)$_POST['status_filter'] : 'active');
$statusFilterKey = strtolower(trim($statusFilterRaw));
if (!in_array($statusFilterKey, ['all', 'active', 'inactive'], true)) {
    $statusFilterKey = 'active';
}
$sort = (string)($_GET['sort'] ?? 'name');
if (!isset(PS_SUPPLIER_SORTS[$sort])) {
    $sort = 'name';
}
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, PS_SUPPLIER_PAGE_SIZES, true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$listQuery = static function (array $over = []) use ($search, $statusFilterKey, $sort, $dir, $perPage, $page): string {
    $q = array_merge([
        'search' => $search,
        'status_filter' => $statusFilterKey,
        'sort' => $sort,
        'dir' => $dir,
        'per_page' => $perPage,
        'page' => $page,
    ], $over);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return 'suppliers_list.php?' . http_build_query($q);
};

$error = '';
$notice = '';
$signedIn = isset($_SESSION['logger']) && trim((string)$_SESSION['logger']) !== '';

if (!empty($_GET['created'])) {
    $notice = 'Supplier created.';
}
if (!empty($_GET['updated'])) {
    $notice = 'Supplier updated.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (!psCsrfValidate()) {
        $error = 'Invalid security token. Reload and try again.';
    } elseif (!$signedIn) {
        $error = 'You must be signed in to change supplier status.';
    } else {
        $statusResult = psSetSupplierStatus($conn, (int)($_POST['supplier_id'] ?? 0), (string)($_POST['new_status'] ?? ''));
        if ($statusResult['ok']) {
            header('Location: ' . $listQuery());
            exit;
        }
        $error = (string)($statusResult['error'] ?? 'Supplier status could not be saved.');
    }
}

$list = ['ok' => false, 'rows' => [], 'total' => 0];
if (!$signedIn) {
    $error = $error !== '' ? $error : 'You must be signed in to view suppliers.';
} else {
    $list = psSupplierListPage($conn, $search, $statusFilterKey, $sort, $dir, $page, $perPage);
    if (!$list['ok']) {
        $error = (string)($list['error'] ?? 'Suppliers could not be loaded.');
    }
    $pages = max(1, (int)ceil($list['total'] / $perPage));
    if ($page > $pages && $list['total'] > 0) {
        header('Location: ' . $listQuery(['page' => $pages]));
        exit;
    }
}
$rows = $list['rows'] ?? [];
$total = (int)($list['total'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));

mysqli_close($conn);

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sortLink = static function (string $key) use ($sort, $dir, $listQuery, $h): string {
    $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $mark = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="' . $h($listQuery(['sort' => $key, 'dir' => $next, 'page' => 1])) . '">' . $h(PS_SUPPLIER_SORTS[$key]) . $mark . '</a>';
};
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
        Suppliers are never hard-deleted; deactivating keeps every purchase order, GRV and return.
    </p>

    <?php if ($error !== ''): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php echo $h($error); ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="ps-alert ps-alert-info" role="status"><?php echo $h($notice); ?></div>
    <?php endif; ?>

    <?php if ($signedIn): ?>
        <div class="ps-card">
            <div class="ps-card-header">
                <h2 class="ps-card-title">Filters</h2>
                <p class="ps-card-desc">Search by code, name, contact, phone, email, tax or registration number.</p>
            </div>
            <div class="ps-card-content">
                <form method="GET" action="" class="ps-form-inline filter-form">
                    <input class="ps-input" type="search" id="search" name="search" value="<?php echo $h($search); ?>" placeholder="Code, name, phone, tax no…">
                    <select class="ps-input" id="status_filter" name="status_filter">
                        <option value="active"<?php echo $statusFilterKey === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?php echo $statusFilterKey === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                        <option value="all"<?php echo $statusFilterKey === 'all' ? ' selected' : ''; ?>>All</option>
                    </select>
                    <select class="ps-input" name="per_page" aria-label="Rows per page">
                        <?php foreach (PS_SUPPLIER_PAGE_SIZES as $size): ?>
                            <option value="<?php echo $size; ?>"<?php echo $perPage === $size ? ' selected' : ''; ?>><?php echo $size; ?> per page</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="sort" value="<?php echo $h($sort); ?>">
                    <input type="hidden" name="dir" value="<?php echo $h($dir); ?>">
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
                </div>
            </div>
        <?php else: ?>
            <div class="ps-card">
                <div class="ps-card-header">
                    <h2 class="ps-card-title">Supplier list</h2>
                    <p class="ps-card-desc">
                        Showing <?php echo (($page - 1) * $perPage) + 1; ?>–<?php echo min($total, $page * $perPage); ?> of <?php echo $total; ?>.
                        Open a supplier to view details, edit or see transaction history.
                    </p>
                </div>
                <div class="ps-card-content ps-card-flush">
            <div class="ps-table-wrap">
            <table class="ps-table ps-supplier-table">
                <thead>
                    <tr>
                        <th><?php echo $sortLink('code'); ?></th>
                        <th><?php echo $sortLink('name'); ?></th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th><?php echo $sortLink('terms'); ?></th>
                        <th><?php echo $sortLink('status'); ?></th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int)$row['id'];
                    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
                    $isActive = $status === PS_SUPPLIER_STATUS_ACTIVE;
                    ?>
                    <tr>
                        <td><a href="supplier_view.php?id=<?php echo $id; ?>"><?php echo $h($row['supplier_code']); ?></a></td>
                        <td title="<?php echo $h($row['supplier_name']); ?>"><?php echo $h($row['supplier_name']); ?></td>
                        <td><?php echo $h($row['contact_person']); ?></td>
                        <td><?php echo $h($row['phone']); ?></td>
                        <td><?php echo $h($row['email']); ?></td>
                        <td><?php echo $h($row['payment_terms'] ?? ''); ?></td>
                        <td>
                            <span class="ps-badge <?php echo $isActive ? 'ps-badge-success' : 'ps-badge-secondary'; ?>">
                                <?php echo $h(psSupplierStatusLabel($status)); ?>
                            </span>
                        </td>
                        <td class="col-actions">
                            <div class="ps-action-menu">
                                <button type="button" class="ps-btn ps-btn-outline ps-btn-sm ps-btn-icon" data-ps-action-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Row actions">⋯</button>
                                <div class="ps-action-menu-panel" role="menu" hidden>
                                    <a role="menuitem" href="supplier_view.php?id=<?php echo $id; ?>">Open</a>
                                    <a role="menuitem" href="supplier_view.php?id=<?php echo $id; ?>&amp;edit=1">Edit</a>
                                    <form method="POST" action="<?php echo $h($listQuery()); ?>">
                                        <?php echo psCsrfField(); ?>
                                        <input type="hidden" name="supplier_id" value="<?php echo $id; ?>">
                                        <?php if ($isActive): ?>
                                            <input type="hidden" name="new_status" value="<?php echo PS_SUPPLIER_STATUS_INACTIVE; ?>">
                                            <button type="submit" name="set_status" value="1" role="menuitem" class="is-danger"
                                                    onclick="return confirm('Deactivate this supplier? History is kept; it just cannot be used for new purchasing.');">Deactivate</button>
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
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
                </div>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="ps-link-row" aria-label="Pages">
                    <?php if ($page > 1): ?>
                        <a class="ps-btn ps-btn-outline ps-btn-sm" href="<?php echo $h($listQuery(['page' => 1])); ?>">First</a>
                        <a class="ps-btn ps-btn-outline ps-btn-sm" href="<?php echo $h($listQuery(['page' => $page - 1])); ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="ps-btn ps-btn-outline ps-btn-sm" href="<?php echo $h($listQuery(['page' => $page + 1])); ?>">Next</a>
                        <a class="ps-btn ps-btn-outline ps-btn-sm" href="<?php echo $h($listQuery(['page' => $pages])); ?>">Last</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php ps_close(); ?>
</body>
</html>
