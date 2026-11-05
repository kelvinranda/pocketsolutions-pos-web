<?php
/**
 * Phase 18 — Super Admin console: companies list + create a company (first branch + first admin).
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';

$conn = psPlatformPageBoot();
$hasBranchCompany = psPlatformBranchesHaveCompany($conn);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } else {
        $name = preg_replace('/\s+/', ' ', trim((string)($_POST['company_name'] ?? '')));
        $branch = preg_replace('/\s+/', ' ', trim((string)($_POST['branch_name'] ?? '')));
        $managed = !empty($_POST['provider_managed']) ? 1 : 0;
        $ref = trim((string)($_POST['managed_reference'] ?? ''));
        $contactName = trim((string)($_POST['contact_name'] ?? ''));
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors[] = 'Company name must be 2–100 characters.';
        } elseif (strcasecmp($name, PS_PLATFORM_COMPANY) === 0) {
            $errors[] = 'That company name is reserved.';
        }
        if ($hasBranchCompany && (mb_strlen($branch) < 2 || mb_strlen($branch) > 100)) {
            $errors[] = 'First branch name must be 2–100 characters.';
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Contact email is not valid.';
        }
        if ($managed && mb_strlen($ref) < 3) {
            $errors[] = 'To administer this company for the client, record the agreement (e.g. "Service contract 2026-14, signed 15 Oct").';
        }
        if (!$errors) {
            // Unique, case-insensitive, against registered companies and names already used by users / branches.
            $stmt = mysqli_prepare($conn, 'SELECT 1 FROM companies WHERE LOWER(company_name) = LOWER(?)
                UNION SELECT 1 FROM users WHERE LOWER(TRIM(company_name)) = LOWER(?) LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $name, $name);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res && mysqli_fetch_row($res)) {
                $errors[] = 'A company with that name already exists.';
            }
            mysqli_stmt_close($stmt);
        }
        if (!$errors && psPlatformLoginIdTaken($conn, trim((string)($_POST['admin_login'] ?? '')))) {
            $errors[] = 'That email or username is already used by another user.';
        }
        if (!$errors && (string)($_POST['admin_password'] ?? '') !== (string)($_POST['admin_password2'] ?? '')) {
            $errors[] = 'The two admin passwords do not match.';
        }

        if (!$errors) {
            $now = date('Y-m-d H:i:s');
            $actor = (string)($_SESSION['logger'] ?? '');
            mysqli_begin_transaction($conn);
            $ok = true;
            $stmt = mysqli_prepare(
                $conn,
                'INSERT INTO companies (company_name, display_name, status, provider_managed, managed_since, managed_reference,
                    contact_name, contact_email, contact_phone, created_at, created_by)
                 VALUES (?, ?, \'ACTIVE\', ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $since = $managed ? $now : null;
            $refV = $managed ? $ref : null;
            mysqli_stmt_bind_param($stmt, 'ssisssssss', $name, $name, $managed, $since, $refV, $contactName, $contactEmail, $contactPhone, $now, $actor);
            $ok = mysqli_stmt_execute($stmt);
            $companyId = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            if ($ok && $hasBranchCompany) {
                // 2026-11-02: the name may already be used by another company; then the stored key is NAME~COMPANY.
                $bk = psBranchKeyFor($conn, $branch, $name);
                $ok = !empty($bk['ok']);
                if ($ok) {
                    $branch = $bk['key'];
                }
            }
            if ($ok && $hasBranchCompany) {
                $stmt = mysqli_prepare($conn, 'INSERT INTO branchies (branch_name, date_created, company_name) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'sss', $branch, $now, $name);
                $ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $created = $ok ? psPlatformCreateUser($conn, [
                'firstname' => (string)($_POST['admin_first'] ?? ''),
                'lastname' => (string)($_POST['admin_last'] ?? ''),
                'phonenumber' => (string)($_POST['admin_phone'] ?? ''),
                'login_id' => (string)($_POST['admin_login'] ?? ''),
                'password' => (string)($_POST['admin_password'] ?? ''),
                'level' => 'admin',
                'company_name' => $name,
                'branch' => $hasBranchCompany ? $branch : '',
            ]) : ['ok' => false, 'error' => 'The company could not be saved.'];

            if ($ok && !empty($created['ok'])) {
                mysqli_commit($conn);
                psPlatformAudit($conn, 'COMPANY_CREATE', $name, '', ['branch' => $branch, 'provider_managed' => $managed, 'agreement' => $refV]);
                psPlatformAudit($conn, 'ADMIN_CREATE', $name, '', ['login' => $created['login_id']]);
                if ($managed) {
                    psPlatformAudit($conn, 'MANAGED_ON', $name, $ref);
                }
                psSecurityAudit($conn, 'USER_CREATE', [
                    'summary' => 'Company admin created by ' . PS_PLATFORM_PROVIDER_NAME,
                    'target_type' => 'user',
                    'target_id' => (string)$created['id'],
                    'after' => ['email' => $created['login_id'], 'level' => 'admin', 'company_name' => $name],
                ]);
                psPlatformFlash('ok', 'Company ' . $name . ' created. Its admin signs in as "' . $created['login_id'] . '".');
                header('Location: platform_company.php?id=' . $companyId);
                exit;
            }
            mysqli_rollback($conn);
            $errors[] = $created['error'] ?? 'The company could not be saved.';
        }
    }
}

// Company list with user / branch counts.
$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['f'] ?? '');
$rows = [];
$res = mysqli_query($conn, 'SELECT * FROM companies ORDER BY display_name, company_name');
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $rows[] = $r;
}
$userCounts = [];
$res = mysqli_query($conn, "SELECT company_name, COUNT(*) n, SUM(LOWER(TRIM(level)) = 'admin') a FROM users GROUP BY company_name");
while ($res && ($r = mysqli_fetch_assoc($res))) {
    $userCounts[(string)$r['company_name']] = ['n' => (int)$r['n'], 'a' => (int)$r['a']];
}
$branchCounts = [];
if ($hasBranchCompany) {
    $res = mysqli_query($conn, 'SELECT company_name, COUNT(*) n FROM branchies GROUP BY company_name');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $branchCounts[(string)$r['company_name']] = (int)$r['n'];
    }
}
$totals = ['all' => count($rows), 'managed' => 0, 'suspended' => 0];
foreach ($rows as $r) {
    $totals['managed'] += (int)$r['provider_managed'] === 1 ? 1 : 0;
    $totals['suspended'] += strtoupper((string)$r['status']) === 'SUSPENDED' ? 1 : 0;
}
$rows = array_values(array_filter($rows, static function ($r) use ($q, $filter) {
    if ($q !== '' && stripos($r['company_name'] . ' ' . $r['display_name'] . ' ' . $r['contact_name'], $q) === false) {
        return false;
    }
    if ($filter === 'managed') {
        return (int)$r['provider_managed'] === 1;
    }
    if ($filter === 'suspended') {
        return strtoupper((string)$r['status']) === 'SUSPENDED';
    }
    return true;
}));
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$v = static fn (string $k): string => htmlspecialchars((string)($_POST[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$showForm = $errors || isset($_GET['new']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies</title>
    <?php ps_head(); psPlatformPageCss(); ?>
</head>
<body class="ps-app">
<?php ps_open('Companies'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <h1>Companies</h1>
        <p class="ps-page-lead">Every client on this POS. Open a company to edit it, reset its admin's password, or manage it for the client when they have asked <?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?> to administer it.</p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-kpi-grid">
        <div class="ps-kpi"><div class="ps-kpi-label">Companies</div><div class="ps-kpi-value"><?php echo $totals['all']; ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Administered by us</div><div class="ps-kpi-value"><?php echo $totals['managed']; ?></div></div>
        <div class="ps-kpi"><div class="ps-kpi-label">Suspended</div><div class="ps-kpi-value"><?php echo $totals['suspended']; ?></div></div>
    </div>

    <details class="ps-card" <?php echo $showForm ? 'open' : ''; ?> id="new">
        <summary class="ps-card-header"><h2 class="ps-card-title">Add a company</h2></summary>
        <div class="ps-card-content">
            <form method="post" action="platform.php" class="pf-form" autocomplete="off">
                <?php echo psCsrfField(); ?>
                <input type="hidden" name="action" value="create">
                <section class="pf-section">
                    <h3 class="pf-h">Company</h3>
                    <div class="pf-grid">
                        <label class="ps-field"><span class="ps-label">Company name</span>
                            <input class="ps-input" name="company_name" required maxlength="100" value="<?php echo $v('company_name'); ?>">
                            <span class="pf-help">Shown on screens and receipts. It cannot be changed later (a display name can).</span></label>
                        <?php if ($hasBranchCompany): ?>
                        <label class="ps-field"><span class="ps-label">First branch</span>
                            <input class="ps-input" name="branch_name" required maxlength="100" value="<?php echo $v('branch_name'); ?>" placeholder="e.g. Main Street">
                            <span class="pf-help">More branches can be added later.</span></label>
                        <?php endif; ?>
                        <label class="ps-field"><span class="ps-label">Contact person</span>
                            <input class="ps-input" name="contact_name" maxlength="250" value="<?php echo $v('contact_name'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Contact phone</span>
                            <input class="ps-input" name="contact_phone" maxlength="100" value="<?php echo $v('contact_phone'); ?>"></label>
                        <label class="ps-field pf-span"><span class="ps-label">Contact email</span>
                            <input class="ps-input" type="email" name="contact_email" maxlength="250" value="<?php echo $v('contact_email'); ?>"></label>
                    </div>
                </section>
                <section class="pf-section">
                    <h3 class="pf-h">Administration</h3>
                    <label class="pf-check"><input type="checkbox" name="provider_managed" value="1" <?php echo !empty($_POST['provider_managed']) ? 'checked' : ''; ?>>
                        <span><strong>Administered by <?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?></strong><br>
                        <span class="pf-meta">The client has agreed that we set up users, products, prices, tax and ZIMRA for them. We never make their sales or refunds.</span></span></label>
                    <label class="pf-field"><span class="ps-label">Agreement reference</span>
                        <input class="ps-input" name="managed_reference" maxlength="250" value="<?php echo $v('managed_reference'); ?>" placeholder="e.g. Service contract 2026-14, signed 15 Oct 2026">
                        <span class="pf-help">Required when the box above is ticked.</span></label>
                </section>
                <section class="pf-section">
                    <h3 class="pf-h">First admin (company owner)</h3>
                    <div class="pf-grid">
                        <label class="ps-field"><span class="ps-label">First name</span><input class="ps-input" name="admin_first" required value="<?php echo $v('admin_first'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Last name</span><input class="ps-input" name="admin_last" required value="<?php echo $v('admin_last'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Email or username</span><input class="ps-input" name="admin_login" required autocomplete="off" value="<?php echo $v('admin_login'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Phone</span><input class="ps-input" name="admin_phone" value="<?php echo $v('admin_phone'); ?>"></label>
                        <label class="ps-field"><span class="ps-label">Password</span><input class="ps-input" type="password" name="admin_password" required minlength="<?php echo PS_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password"></label>
                        <label class="ps-field"><span class="ps-label">Confirm password</span><input class="ps-input" type="password" name="admin_password2" required minlength="<?php echo PS_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password"></label>
                    </div>
                </section>
                <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Create company</button></div>
            </form>
        </div>
    </details>

    <form method="get" class="ps-form-actions">
        <label class="ps-label">Search <input class="ps-input" name="q" value="<?php echo $h($q); ?>" placeholder="Company or contact"></label>
        <label class="ps-label">Show <select class="ps-input" name="f">
            <option value="">All companies</option>
            <option value="managed" <?php echo $filter === 'managed' ? 'selected' : ''; ?>>Administered by us</option>
            <option value="suspended" <?php echo $filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
        </select></label>
        <button class="ps-btn ps-btn-outline" type="submit">Filter</button>
    </form>

    <div class="ps-card"><div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
        <thead><tr><th>Company</th><th>Status</th><th>Administration</th><th>Users</th><th>Admins</th><th>Branches</th><th>Contact</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="ps-muted">No companies match.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $key = (string)$r['company_name'];
            $uc = $userCounts[$key] ?? ['n' => 0, 'a' => 0];
            $canManage = strtoupper((string)$r['status']) === 'ACTIVE' && (int)$r['provider_managed'] === 1; ?>
            <tr>
                <td><strong><?php echo $h(psPlatformCompanyLabel($r)); ?></strong>
                    <?php if ((string)$r['display_name'] !== $key): ?><div class="pf-meta"><?php echo $h($key); ?></div><?php endif; ?></td>
                <td><?php echo psPlatformStatusBadge($r); ?></td>
                <td><?php echo psPlatformManagedBadge($r); ?></td>
                <td><?php echo (int)$uc['n']; ?></td>
                <td><?php echo (int)$uc['a']; ?><?php if ((int)$uc['a'] === 0): ?> <span class="ps-badge ps-badge-warning">none</span><?php endif; ?></td>
                <td><?php echo $hasBranchCompany ? (int)($branchCounts[$key] ?? 0) : '—'; ?></td>
                <td><?php echo $h(trim((string)$r['contact_name'] . ' ' . (string)$r['contact_phone'])); ?></td>
                <td><div class="pf-row-actions">
                    <a class="ps-btn ps-btn-outline ps-btn-sm" href="platform_company.php?id=<?php echo (int)$r['id']; ?>">Open</a>
                    <?php if ($canManage): ?><a class="ps-btn ps-btn-default ps-btn-sm" href="platform_company.php?id=<?php echo (int)$r['id']; ?>#manage">Manage</a><?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
</div>
<?php ps_close(); ?>
</body>
</html>
