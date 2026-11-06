<?php
/**
 * Phase 18 — One company: manage (enter as its admin), details, administration agreement, status,
 * company admins (add / reset password) and the provider activity log for this company.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';
require_once __DIR__ . '/includes/user_roles.php';

$conn = psPlatformPageBoot();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = psPlatformCompanyById($conn, $id);
if (!$c) {
    psPlatformFlash('err', 'Company not found.');
    header('Location: platform.php');
    exit;
}
$key = (string)$c['company_name'];
$errors = [];
$self = 'platform_company.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $now = date('Y-m-d H:i:s');
    $actor = (string)($_SESSION['logger'] ?? '');
    if (!psCsrfValidate()) {
        $errors[] = 'Invalid security token. Reload and try again.';
    } elseif ($action === 'enter') {
        $r = psPlatformEnter($conn, $id, (string)($_POST['reason'] ?? ''));
        if (!empty($r['ok'])) {
            header('Location: admin_dashboard.php');
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'details') {
        $display = preg_replace('/\s+/', ' ', trim((string)($_POST['display_name'] ?? '')));
        $email = trim((string)($_POST['contact_email'] ?? ''));
        if (mb_strlen($display) < 2 || mb_strlen($display) > 250) {
            $errors[] = 'Display name must be 2–250 characters.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Contact email is not valid.';
        }
        if (!$errors) {
            $before = array_intersect_key($c, array_flip(['display_name', 'contact_name', 'contact_email', 'contact_phone', 'notes']));
            $after = [
                'display_name' => $display,
                'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
                'contact_email' => $email,
                'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ];
            $stmt = mysqli_prepare($conn, 'UPDATE companies SET display_name=?, contact_name=?, contact_email=?, contact_phone=?, notes=?, updated_at=?, updated_by=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'sssssssi', $after['display_name'], $after['contact_name'], $after['contact_email'], $after['contact_phone'], $after['notes'], $now, $actor, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            psPlatformAudit($conn, 'COMPANY_UPDATE', $key, '', ['before' => $before, 'after' => $after]);
            psPlatformFlash('ok', 'Company details saved.');
            header('Location: ' . $self);
            exit;
        }
    } elseif ($action === 'managed_on' || $action === 'managed_off') {
        $on = $action === 'managed_on';
        $ref = trim((string)($_POST['managed_reference'] ?? ''));
        if ($on && mb_strlen($ref) < 3) {
            $errors[] = 'Record the client\'s agreement (e.g. "Service contract 2026-14, signed 15 Oct").';
        } else {
            $flag = $on ? 1 : 0;
            $since = $on ? $now : null;
            $refV = $on ? $ref : null;
            $stmt = mysqli_prepare($conn, 'UPDATE companies SET provider_managed=?, managed_since=?, managed_reference=?, updated_at=?, updated_by=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'issssi', $flag, $since, $refV, $now, $actor, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            psPlatformAudit($conn, $on ? 'MANAGED_ON' : 'MANAGED_OFF', $key, $on ? $ref : trim((string)($_POST['reason'] ?? '')));
            psPlatformFlash('ok', $on
                ? 'This company is now administered by ' . PS_PLATFORM_PROVIDER_NAME . '. You can manage it.'
                : 'Administration turned off. ' . PS_PLATFORM_PROVIDER_NAME . ' staff can no longer manage this company.');
            header('Location: ' . $self);
            exit;
        }
    } elseif ($action === 'suspend' || $action === 'activate') {
        $suspend = $action === 'suspend';
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($suspend && mb_strlen($reason) < 5) {
            $errors[] = 'Give a reason for suspending (at least 5 characters).';
        } else {
            $st = $suspend ? 'SUSPENDED' : 'ACTIVE';
            $rv = $suspend ? $reason : null;
            $stmt = mysqli_prepare($conn, 'UPDATE companies SET status=?, suspended_reason=?, updated_at=?, updated_by=? WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'ssssi', $st, $rv, $now, $actor, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            psPlatformAudit($conn, $suspend ? 'COMPANY_SUSPEND' : 'COMPANY_ACTIVATE', $key, $reason);
            psPlatformFlash('ok', $suspend
                ? 'Company suspended. Its users are signed out and cannot sign in until you reactivate it.'
                : 'Company reactivated. Its users can sign in again.');
            header('Location: ' . $self);
            exit;
        }
    } elseif ($action === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pw = (string)($_POST['new_password'] ?? '');
        $target = null;
        foreach (psPlatformCompanyAdmins($conn, $key) as $a) {
            if ((int)$a['id'] === $uid) {
                $target = $a;
            }
        }
        if (!$target) {
            $errors[] = 'Choose one of this company\'s admins.';
        } elseif ($pw !== (string)($_POST['new_password2'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        } else {
            $r = psPlatformSetPassword($conn, $uid, $pw);
            if (!empty($r['ok'])) {
                psPlatformAudit($conn, 'ADMIN_PASSWORD_RESET', $key, trim((string)($_POST['reason'] ?? '')), ['login' => $target['email']]);
                psSecurityAudit($conn, 'PASSWORD_RESET', [
                    'summary' => 'Company admin password reset by ' . PS_PLATFORM_PROVIDER_NAME . ' (' . $key . ')',
                    'target_type' => 'user',
                    'target_id' => (string)$uid,
                ]);
                psPlatformFlash('ok', 'Password for ' . $target['email'] . ' changed and the account unlocked. Give the new password to the client securely.');
                header('Location: ' . $self);
                exit;
            }
            $errors[] = $r['error'];
        }
    } elseif ($action === 'reset_default') {
        // 2026-11-06: Super Admin resets any of this company's users to the default password (000000). The user must
        // choose their own password at the next web sign-in. Reason required; logged here and in the security audit.
        $uid = (int)($_POST['user_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $target = null;
        foreach (psUserRoleCompanyUsers($conn, $key) as $u) {
            if ((int)$u['id'] === $uid) {
                $target = $u;
            }
        }
        if (!$target) {
            $errors[] = 'Choose one of this company\'s users.';
        } elseif (mb_strlen($reason) < 3) {
            $errors[] = 'Give a reason for the reset (e.g. "Cashier forgot password, called 6 Nov").';
        } else {
            $r = psPlatformSetPassword($conn, $uid, PS_DEFAULT_RESET_PASSWORD);
            if (!empty($r['ok'])) {
                psSecuritySetMustChangePassword($conn, $uid, true);
                psPlatformAudit($conn, 'USER_PASSWORD_DEFAULT', $key, $reason, ['login' => $target['email']]);
                psSecurityAudit($conn, 'PASSWORD_RESET', [
                    'summary' => 'Password reset to the default by ' . PS_PLATFORM_PROVIDER_NAME . ' (' . $key . ')',
                    'target_type' => 'user',
                    'target_id' => (string)$uid,
                ]);
                psPlatformFlash('ok', 'Password for ' . $target['email'] . ' reset to ' . PS_DEFAULT_RESET_PASSWORD
                    . ' and the account unlocked. They must choose a new password when they next sign in on the web.');
                header('Location: ' . $self);
                exit;
            }
            $errors[] = $r['error'];
        }
    } elseif ($action === 'change_role') {
        // 2026-10-28: Super Admin changes a company user's role (reason required; logged here and in the company's audit).
        $r = psUserRoleChange($conn, $key, (int)($_POST['user_id'] ?? 0), (string)($_POST['level'] ?? ''), [
            'actor' => $actor, 'platform' => true, 'reason' => (string)($_POST['reason'] ?? ''),
        ]);
        if (!empty($r['ok'])) {
            psPlatformFlash('ok', $r['email'] . ' is now ' . psUserRoleLabel((string)$r['after']) . '.');
            header('Location: ' . $self);
            exit;
        }
        $errors[] = $r['error'];
    } elseif ($action === 'add_branch') {
        // 2026-11-02: a branch may share its name with another company's branch; the stored key then gets "~COMPANY".
        $k = psBranchKeyFor($conn, (string)($_POST['branch_name'] ?? ''), $key);
        if (empty($k['ok'])) {
            $errors[] = $k['error'];
        } elseif (mb_strlen(psBranchLabel($k['key'])) < 2 || mb_strlen(psBranchLabel($k['key'])) > 100) {
            $errors[] = 'Branch name must be 2–100 characters.';
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO branchies (branch_name, date_created, company_name) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'sss', $k['key'], $now, $key);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if ($ok) {
                psPlatformAudit($conn, 'BRANCH_CREATE', $key, '', ['branch' => $k['key']]);
                psPlatformFlash('ok', 'Branch ' . psBranchLabel($k['key']) . ' added.');
                header('Location: ' . $self);
                exit;
            }
            $errors[] = 'The branch could not be saved.';
        }
    } elseif ($action === 'add_admin') {
        $branches = psPlatformCompanyBranches($conn, $key);
        $branch = trim((string)($_POST['branch'] ?? ''));
        if ($branches && !in_array($branch, $branches, true)) {
            $errors[] = 'Choose one of this company\'s branches.';
        } elseif ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        } else {
            $r = psPlatformCreateUser($conn, [
                'firstname' => (string)($_POST['firstname'] ?? ''),
                'lastname' => (string)($_POST['lastname'] ?? ''),
                'phonenumber' => (string)($_POST['phonenumber'] ?? ''),
                'login_id' => (string)($_POST['login_id'] ?? ''),
                'password' => (string)($_POST['password'] ?? ''),
                'level' => 'admin',
                'company_name' => $key,
                'branch' => $branch,
            ]);
            if (!empty($r['ok'])) {
                psPlatformAudit($conn, 'ADMIN_CREATE', $key, '', ['login' => $r['login_id']]);
                psSecurityAudit($conn, 'USER_CREATE', [
                    'summary' => 'Company admin created by ' . PS_PLATFORM_PROVIDER_NAME,
                    'target_type' => 'user',
                    'target_id' => (string)$r['id'],
                    'after' => ['email' => $r['login_id'], 'level' => 'admin', 'company_name' => $key],
                ]);
                psPlatformFlash('ok', 'Admin ' . $r['login_id'] . ' added.');
                header('Location: ' . $self);
                exit;
            }
            $errors[] = $r['error'];
        }
    }
    $c = psPlatformCompanyById($conn, $id) ?: $c;
}

$admins = psPlatformCompanyAdmins($conn, $key);
$roleUsers = psUserRoleCompanyUsers($conn, $key);
$branches = psPlatformCompanyBranches($conn, $key);
$activity = psPlatformAuditRows($conn, $key, 50);
$stmt = mysqli_prepare($conn, 'SELECT level, COUNT(*) n FROM users WHERE company_name = ? GROUP BY level ORDER BY level');
mysqli_stmt_bind_param($stmt, 's', $key);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$byLevel = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
mysqli_stmt_close($stmt);

$active = strtoupper((string)$c['status']) === 'ACTIVE';
$managed = (int)$c['provider_managed'] === 1;
$acting = psPlatformActing();
$h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$v = static fn (string $k, string $d = ''): string => htmlspecialchars((string)($_POST[$k] ?? $d), ENT_QUOTES, 'UTF-8');
$minLen = PS_PASSWORD_MIN_LENGTH;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h(psPlatformCompanyLabel($c)); ?></title>
    <?php ps_head(); psPlatformPageCss(); ?>
</head>
<body class="ps-app">
<?php ps_open('Company'); ?>
<div class="pf-wrap pf-stack">
    <div>
        <p class="pf-meta pf-back"><a href="platform.php">← Companies</a></p>
        <h1><?php echo $h(psPlatformCompanyLabel($c)); ?></h1>
        <p class="ps-page-lead"><?php echo psPlatformStatusBadge($c); ?> <?php echo psPlatformManagedBadge($c); ?>
            <?php if ((string)$c['display_name'] !== $key): ?><span class="pf-meta">· Company key: <?php echo $h($key); ?></span><?php endif; ?></p>
    </div>
    <?php psPlatformFlashShow(); ?>
    <?php if ($errors): ?>
        <div class="ps-alert ps-alert-destructive" role="alert"><?php foreach ($errors as $e): ?><div><?php echo $h($e); ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="ps-card" id="manage">
        <div class="ps-card-header"><h2 class="ps-card-title">Manage this company</h2></div>
        <div class="ps-card-content">
            <?php if ($active && $managed): ?>
                <p class="pf-text">You will see the POS as this company's admin: users, branches, products and prices, tax, ZIMRA set-up and reports.
                    <strong>Sales, refunds and till/cash entries stay with the company's own staff.</strong> Everything you do is recorded under your name.</p>
                <form method="post" action="<?php echo $h($self); ?>" class="pf-inline">
                    <?php echo psCsrfField(); ?>
                    <input type="hidden" name="action" value="enter"><input type="hidden" name="id" value="<?php echo $id; ?>">
                    <label class="pf-field"><span class="ps-label">Reason</span>
                        <input class="ps-input" name="reason" required minlength="5" maxlength="500" placeholder="e.g. Add new cashier, update prices" value="<?php echo $v('reason'); ?>"></label>
                    <button class="ps-btn ps-btn-default" type="submit">Manage company</button>
                </form>
                <p class="pf-help pf-after">Agreement: <?php echo $h((string)$c['managed_reference']); ?><?php if ($c['managed_since']): ?> · since <?php echo $h(date('d M Y', strtotime((string)$c['managed_since']))); ?><?php endif; ?></p>
            <?php elseif (!$active): ?>
                <p class="pf-text ps-muted">This company is suspended. Reactivate it below before managing it.</p>
            <?php else: ?>
                <p class="pf-text ps-muted">This company manages itself. To work inside it, turn on <strong>Administered by <?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?></strong> below — only once the client has agreed.
                    You can still reset its admin's password from here.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="pf-cols">
        <div class="pf-stack">
            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Company admins</h2></div>
                <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
                    <thead><tr><th>Name</th><th>Signs in as</th><th>Branch</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$admins): ?><tr><td colspan="4"><span class="ps-badge ps-badge-warning">No admin</span> <span class="ps-muted">Add one below so the company can run itself.</span></td></tr><?php endif; ?>
                    <?php foreach ($admins as $a): ?>
                        <tr><td><?php echo $h($a['firstname'] . ' ' . $a['lastname']); ?></td><td><?php echo $h($a['email']); ?></td><td><?php echo $h($a['branch']); ?></td>
                            <td><?php echo psSecurityUserIsLocked($a) ? '<span class="ps-badge ps-badge-destructive">Locked</span>' : '<span class="ps-badge ps-badge-success">Active</span>'; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div>
            </div>

            <?php if ($admins): ?>
            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Reset an admin's password</h2></div>
                <div class="ps-card-content">
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form" autocomplete="off">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div class="pf-grid">
                            <label class="ps-field pf-span"><span class="ps-label">Admin</span>
                                <select class="ps-input" name="user_id" required><?php foreach ($admins as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $h($a['email'] . ' — ' . $a['firstname'] . ' ' . $a['lastname']); ?></option><?php endforeach; ?></select></label>
                            <label class="ps-field"><span class="ps-label">New password</span><input class="ps-input" type="password" name="new_password" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                            <label class="ps-field"><span class="ps-label">Confirm password</span><input class="ps-input" type="password" name="new_password2" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                            <label class="ps-field pf-span"><span class="ps-label">Why (optional)</span><input class="ps-input" name="reason" maxlength="500" placeholder="e.g. Owner forgot password, called 15 Oct"></label>
                        </div>
                        <div class="pf-actions"><span class="pf-help" style="margin-right:auto;align-self:center">This also unlocks the account.</span><button class="ps-btn ps-btn-default" type="submit">Reset password</button></div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <details class="ps-card" <?php echo ($_POST['action'] ?? '') === 'reset_default' ? 'open' : ''; ?>>
                <summary class="ps-card-header"><h2 class="ps-card-title">Reset a user's password to <?php echo $h(PS_DEFAULT_RESET_PASSWORD); ?></h2></summary>
                <div class="ps-card-content">
                    <p class="pf-help">For any user of this company (cashier, supervisor, manager or admin) who cannot sign in. The account is unlocked,
                        and the user must choose their own password the next time they sign in on the web. The reason is kept in this company's activity log.</p>
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form" autocomplete="off"
                          onsubmit="return confirm('Reset this user\'s password to <?php echo $h(PS_DEFAULT_RESET_PASSWORD); ?>?');">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="reset_default"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div class="pf-grid">
                            <label class="ps-field pf-span"><span class="ps-label">User</span>
                                <select class="ps-input" name="user_id" required><option value="">Choose a user…</option>
                                <?php foreach ($roleUsers as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo $h(trim($u['firstname'] . ' ' . $u['lastname']) . ' · ' . $u['email'] . ' · ' . psUserRoleLabel((string)$u['level'])); ?></option><?php endforeach; ?></select></label>
                            <label class="ps-field pf-span"><span class="ps-label">Reason</span><input class="ps-input" name="reason" required minlength="3" maxlength="400" placeholder="e.g. Cashier forgot password, called 6 Nov"></label>
                        </div>
                        <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Reset to <?php echo $h(PS_DEFAULT_RESET_PASSWORD); ?></button></div>
                    </form>
                </div>
            </details>

            <details class="ps-card" <?php echo ($_POST['action'] ?? '') === 'change_role' ? 'open' : ''; ?>>
                <summary class="ps-card-header"><h2 class="ps-card-title">Change a user's role</h2></summary>
                <div class="ps-card-content">
                    <p class="pf-help">Normally the company's Admin does this on their Users page. Use this when they cannot (e.g. their only Admin is away).
                        The last Admin cannot be moved to another role. The reason is kept in this company's activity log.</p>
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form" autocomplete="off">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="change_role"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div class="pf-grid">
                            <label class="ps-field pf-span"><span class="ps-label">User</span>
                                <select class="ps-input" name="user_id" required><option value="">Choose a user…</option>
                                <?php foreach ($roleUsers as $u): ?><option value="<?php echo (int)$u['id']; ?>" <?php echo (int)($_POST['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : ''; ?>><?php echo $h(trim($u['firstname'] . ' ' . $u['lastname']) . ' · ' . $u['email'] . ' · now ' . psUserRoleLabel((string)$u['level'])); ?></option><?php endforeach; ?></select></label>
                            <label class="ps-field"><span class="ps-label">New role</span>
                                <select class="ps-input" name="level" required><?php foreach (psUserRoleChoices() as $val => $label): ?><option value="<?php echo $h($val); ?>" <?php echo ($_POST['level'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?></select></label>
                            <label class="ps-field"><span class="ps-label">Reason</span><input class="ps-input" name="reason" required maxlength="400" placeholder="e.g. Owner asked by phone, 28 Oct" value="<?php echo $v('reason'); ?>"></label>
                        </div>
                        <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Change role</button></div>
                    </form>
                </div>
            </details>

            <details class="ps-card" <?php echo ($_POST['action'] ?? '') === 'add_admin' ? 'open' : ''; ?>>
                <summary class="ps-card-header"><h2 class="ps-card-title">Add another admin</h2></summary>
                <div class="ps-card-content">
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form" autocomplete="off">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="add_admin"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div class="pf-grid">
                            <label class="ps-field"><span class="ps-label">First name</span><input class="ps-input" name="firstname" required value="<?php echo $v('firstname'); ?>"></label>
                            <label class="ps-field"><span class="ps-label">Last name</span><input class="ps-input" name="lastname" required value="<?php echo $v('lastname'); ?>"></label>
                            <label class="ps-field"><span class="ps-label">Email or username</span><input class="ps-input" name="login_id" required value="<?php echo $v('login_id'); ?>"></label>
                            <label class="ps-field"><span class="ps-label">Branch</span>
                                <?php if ($branches): ?>
                                <select class="ps-input" name="branch" required><?php foreach ($branches as $b): ?><option <?php echo ($_POST['branch'] ?? '') === $b ? 'selected' : ''; ?>><?php echo $h($b); ?></option><?php endforeach; ?></select>
                                <?php else: ?><input class="ps-input" name="branch" value="<?php echo $v('branch'); ?>"><?php endif; ?></label>
                            <label class="ps-field"><span class="ps-label">Password</span><input class="ps-input" type="password" name="password" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                            <label class="ps-field"><span class="ps-label">Confirm password</span><input class="ps-input" type="password" name="password2" required minlength="<?php echo $minLen; ?>" autocomplete="new-password"></label>
                        </div>
                        <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Add admin</button></div>
                    </form>
                </div>
            </details>

            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Provider activity in this company</h2></div>
                <div class="ps-card-content ps-card-flush"><div class="ps-table-wrap"><table class="ps-table">
                    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Reason / detail</th></tr></thead>
                    <tbody>
                    <?php if (!$activity): ?><tr><td colspan="4" class="ps-muted">Nothing recorded yet.</td></tr><?php endif; ?>
                    <?php foreach ($activity as $a): ?>
                        <tr><td class="pf-nowrap" style="white-space:nowrap"><?php echo $h(date('d M Y H:i', strtotime((string)$a['occurred_at']))); ?></td><td><?php echo $h($a['actor']); ?></td>
                            <td><?php echo $h(psPlatformActionLabel((string)$a['action_code'])); ?></td><td><?php echo $h((string)$a['reason']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div>
            </div>
        </div>

        <div class="pf-stack">
            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Details</h2></div>
                <div class="ps-card-content">
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="details"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div class="pf-grid">
                            <label class="ps-field pf-span"><span class="ps-label">Display name</span><input class="ps-input" name="display_name" required value="<?php echo $v('display_name', (string)$c['display_name']); ?>"></label>
                            <label class="ps-field pf-span"><span class="ps-label">Contact person</span><input class="ps-input" name="contact_name" value="<?php echo $v('contact_name', (string)$c['contact_name']); ?>"></label>
                            <label class="ps-field"><span class="ps-label">Phone</span><input class="ps-input" name="contact_phone" value="<?php echo $v('contact_phone', (string)$c['contact_phone']); ?>"></label>
                            <label class="ps-field"><span class="ps-label">Email</span><input class="ps-input" type="email" name="contact_email" value="<?php echo $v('contact_email', (string)$c['contact_email']); ?>"></label>
                            <label class="ps-field pf-span"><span class="ps-label">Notes</span><textarea class="ps-input" name="notes" rows="3"><?php echo $v('notes', (string)$c['notes']); ?></textarea></label>
                        </div>
                        <div class="pf-actions"><button class="ps-btn ps-btn-outline" type="submit">Save details</button></div>
                    </form>
                    <dl class="pf-dl">
                        <dt>Users</dt><dd><?php echo $byLevel ? $h(implode(', ', array_map(static fn ($r) => $r['n'] . ' ' . psSecurityNormalizeLevel((string)$r['level']), $byLevel))) : '0'; ?></dd>
                        <dt>Branches</dt><dd><?php echo $branches ? $h(implode(', ', array_map('psBranchLabel', $branches))) : '—'; ?></dd>
                        <dt>Added</dt><dd><?php echo $h(date('d M Y', strtotime((string)$c['created_at']))); ?><?php echo $c['created_by'] !== 'backfill' ? ' by ' . $h($c['created_by']) : ' (existing company)'; ?></dd>
                    </dl>
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-actions" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="action" value="add_branch"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <label class="ps-field" style="margin:0"><span class="ps-label">Add a branch</span>
                            <input class="ps-input" name="branch_name" required maxlength="100" placeholder="e.g. Rusape"></label>
                        <button class="ps-btn ps-btn-outline" type="submit">Add branch</button>
                        <span class="pf-help" style="flex-basis:100%">Another company may already use the same name; each company only sees its own.</span>
                    </form>
                </div>
            </div>

            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Administration by <?php echo $h(PS_PLATFORM_PROVIDER_NAME); ?></h2></div>
                <div class="ps-card-content">
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <?php if ($managed): ?>
                            <p class="pf-text">On — agreement: <strong><?php echo $h((string)$c['managed_reference']); ?></strong></p>
                            <input type="hidden" name="action" value="managed_off">
                            <label class="pf-field"><span class="ps-label">Reason for turning off (optional)</span><input class="ps-input" name="reason" maxlength="500"></label>
                            <div class="pf-actions"><button class="ps-btn ps-btn-outline" type="submit">Turn off</button></div>
                        <?php else: ?>
                            <p class="pf-text ps-muted">Off — the company runs its own administration.</p>
                            <input type="hidden" name="action" value="managed_on">
                            <label class="pf-field"><span class="ps-label">Client agreement reference</span>
                                <input class="ps-input" name="managed_reference" required minlength="3" maxlength="250" placeholder="e.g. Service contract 2026-14, signed 15 Oct 2026"></label>
                            <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Turn on</button></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="ps-card">
                <div class="ps-card-header"><h2 class="ps-card-title">Status</h2></div>
                <div class="ps-card-content">
                    <form method="post" action="<?php echo $h($self); ?>" class="pf-form">
                        <?php echo psCsrfField(); ?>
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <?php if ($active): ?>
                            <input type="hidden" name="action" value="suspend">
                            <p class="pf-text ps-muted">Suspending signs out all of this company's users and blocks sign-in (web and mobile) until reactivated. No data is deleted.</p>
                            <label class="pf-field"><span class="ps-label">Reason</span><input class="ps-input" name="reason" required minlength="5" maxlength="500" placeholder="e.g. Subscription unpaid since September"></label>
                            <div class="pf-actions"><button class="ps-btn ps-btn-destructive" type="submit">Suspend company</button></div>
                        <?php else: ?>
                            <input type="hidden" name="action" value="activate">
                            <p class="pf-text">Suspended: <?php echo $h((string)$c['suspended_reason']); ?></p>
                            <div class="pf-actions"><button class="ps-btn ps-btn-default" type="submit">Reactivate company</button></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php ps_close(); ?>
</body>
</html>
