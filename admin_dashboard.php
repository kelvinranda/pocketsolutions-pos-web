<?php
/**
 * Admin dashboard + branch selection UI.
 * Phase 9.2: requires authenticated admin before branch controls / admin actions.
 * Branch list comes from branchies for admins only; selection ≠ role elevation.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/stock_movement.php';
require_once __DIR__ . '/includes/branch_access.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!psBranchSessionIsAdmin()) {
    // Do not expose branch list or admin chrome to non-admin.
    header('Location: ajax_pos.php');
    exit;
}

$conn = mysqli_connect($servername, $username, $password, $dbname);
$branches = [];
$error = null;

if ($conn) {
    foreach (psBranchRegisteredNames($conn) as $name) {
        $branches[] = ['branch_name' => $name];
    }
    if (!$branches) {
        $error = 'Failed to load branches.';
    }
} else {
    $error = 'Failed to load branches.';
}

$forceSwitch = isset($_GET['switch']) && (string)$_GET['switch'] === '1';
$workingResolved = $conn ? psBranchResolveWorking($conn) : ['ok' => false];
$currentSelected = !empty($workingResolved['ok']) ? (string)$workingResolved['branch'] : psBranchSelectedRaw();
$hasUsableWorking = !empty($workingResolved['ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Home · <?php echo htmlspecialchars($_SESSION['the_company'] ?? 'POS'); ?></title>
<?php require_once __DIR__ . '/includes/ps_layout.php'; ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open(); ?>
<!-- Legacy in-page menu removed 2026-10-13: the shared sidebar (ps_open) is the only menu. -->
<a href="#" id="openBranchModal" hidden aria-hidden="true"></a>

<div class="content">
  <header class="ps-dash-head">
    <div class="ps-dash-logo">
      <img class="ps-dash-logo-img" src="earth.jpeg" alt="<?php echo htmlspecialchars($_SESSION['the_company'] ?? 'Company'); ?>">
    </div>
    <div class="ps-dash-copy">
      <h1><?php echo htmlspecialchars($_SESSION['the_company'] ?? 'Welcome back'); ?></h1>
      <?php if ($currentSelected !== ''): ?>
        <p class="ps-dash-branch"><?php echo htmlspecialchars($currentSelected); ?></p>
      <?php endif; ?>
    </div>
  </header>
  <div class="ps-tiles">
    <a class="ps-tile" style="background:#1F5C4A" href="management_dashboard.php"><div>Management Dashboard</div><span>Sales, stock, cash metrics</span></a>
    <a class="ps-tile" style="background:#2E57C8" href="sales_reports.php"><div>Sales Reports</div><span>By date, branch, product</span></a>
    <a class="ps-tile" style="background:#3D6B8A" href="inventory_reports.php"><div>Inventory Reports</div><span>On hand, low stock, movements</span></a>
    <?php if (!(function_exists('psPlatformIsActing') && psPlatformIsActing())): // Phase 18: provider staff do not sell ?>
    <a class="ps-tile" style="background:#C4524A" href="ajax_pos.php"><div>Web POS</div><span>Sell and take payment</span></a>
    <?php endif; ?>
    <a class="ps-tile" style="background:#5C4AD4" href="goods_receipt_ref.php"><div>Goods Receipt</div><span>Receive stock (GRV)</span></a>
    <a class="ps-tile" style="background:#B44A86" href="all_receipts.php"><div>Receipts</div><span>Past sales</span></a>
    <a class="ps-tile" style="background:#A07A2E" href="items_list.php"><div>Items</div><span>Products and prices</span></a>
    <a class="ps-tile" style="background:#2E57C8" href="customers_list.php"><div>Customers</div><span>Contacts</span></a>
    <a class="ps-tile" style="background:#2C7A4B" href="z_report.php"><div>Z Report</div><span>Sales by cashier today</span></a>
    <a class="ps-tile" style="background:#C45C66" href="expenses.php"><div>Expenses</div><span>Costs</span></a>
    <a class="ps-tile" style="background:#6A45C8" href="users.php"><div>Users</div><span>Staff accounts</span></a>
    <a class="ps-tile" style="background:#3D6B8A" href="branch_integrity_report.php"><div>Branch Integrity</div><span>Records with missing branches</span></a>
  </div>
</div>

<!-- Branch selection modal (admin only; names escaped via json_encode) -->
<div class="modal-overlay" id="branchModalOverlay">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="branchModalTitle">
    <h3 id="branchModalTitle">Select Branch</h3>

    <div class="ps-branch-field">
      <label for="branchSelect">Branch</label>
      <select id="branchSelect">
        <!-- options injected by JS -->
      </select>
    </div>

    <div class="error" id="branchModalError" <?php echo $error ? '' : 'hidden'; ?>><?php
      echo $error ? htmlspecialchars($error) : '';
    ?></div>

    <div class="actions">
      <button class="btn btn-secondary" type="button" id="btnCancel">Cancel</button>
      <button class="btn btn-primary" type="button" id="btnSave">Continue</button>
    </div>
  </div>
</div>

<script>
  const branches = <?php echo json_encode($branches, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  const overlay = document.getElementById('branchModalOverlay');
  const selectEl = document.getElementById('branchSelect');
  const btnSave = document.getElementById('btnSave');
  const btnCancel = document.getElementById('btnCancel');
  const errorEl = document.getElementById('branchModalError');
  const openBranchLink = document.getElementById('openBranchModal');

  const currentSelected = <?php echo json_encode($currentSelected, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const forceSwitch = <?php echo $forceSwitch ? 'true' : 'false'; ?>;
  const hasUsableWorking = <?php echo $hasUsableWorking ? 'true' : 'false'; ?>;

  function setModalError(msg) {
    if (!errorEl) return;
    const text = (msg || '').trim();
    if (!text) {
      errorEl.textContent = '';
      errorEl.hidden = true;
      return;
    }
    errorEl.textContent = text;
    errorEl.hidden = false;
  }

  // Reparent to body so position:fixed covers the viewport (.ps-content uses container-type).
  function showModal() {
    if (!overlay) return;
    if (overlay.parentElement !== document.body) {
      document.body.appendChild(overlay);
    }
    setModalError('');
    overlay.style.display = 'flex';
  }
  function hideModal() {
    if (!overlay) return;
    overlay.style.display = 'none';
    setModalError('');
  }

  // Populate dropdown from server-authorised branchies list only
  if (selectEl && branches && branches.length > 0) {
    for (const b of branches) {
      const opt = document.createElement('option');
      opt.value = b.branch_name;
      opt.textContent = b.branch_name;

      if (currentSelected && b.branch_name === currentSelected) {
        opt.selected = true;
      }
      selectEl.appendChild(opt);
    }
  } else if (selectEl) {
    const opt = document.createElement('option');
    opt.value = "";
    opt.textContent = "No branches found";
    selectEl.appendChild(opt);
  }

  // Open modal when no usable working branch, or explicit switch=1
  window.addEventListener('load', () => {
    if (!hasUsableWorking || forceSwitch) showModal();
  });

  if (openBranchLink) {
    openBranchLink.addEventListener('click', (e) => {
      e.preventDefault();
      showModal();
    });
  }

  if (btnCancel) {
    btnCancel.addEventListener('click', () => {
      hideModal();
    });
  }

  if (btnSave) {
    btnSave.addEventListener('click', async () => {
      if (!selectEl) return;
      const selected = selectEl.value;
      if (!selected) {
        setModalError('Please select a branch.');
        return;
      }

      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
      const token = csrf || (typeof window.psCsrfToken === 'function' ? window.psCsrfToken() : '');
      const body = new URLSearchParams({ selected_branch: selected });
      if (token) body.append('csrf_token', token);

      const prevLabel = btnSave.textContent;
      btnSave.disabled = true;
      btnSave.textContent = 'Switching…';
      setModalError('');

      try {
        const res = await fetch('set_branch.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': token || ''
          },
          credentials: 'same-origin',
          body
        });

        if (res.ok) {
          window.location.href = 'admin_dashboard.php';
          return;
        }
        const msg = await res.text();
        setModalError(msg && msg.trim() !== '' ? msg.trim() : 'Failed to switch branch.');
      } catch (err) {
        setModalError('Could not switch branch. Check your connection and try again.');
      } finally {
        btnSave.disabled = false;
        btnSave.textContent = prevLabel || 'Continue';
      }
    });
  }
</script>

<?php ps_close(); ?>
</body>
</html>
