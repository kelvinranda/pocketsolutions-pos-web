<?php
/**
 * Phase 9.5 / 9.6 — Read-only historical branch integrity diagnostics (admin).
 * Does not rewrite sales, tills, stock, invoices, or user homes.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require 'pockets_config.php';
require_once __DIR__ . '/includes/branch_integrity.php';
require_once __DIR__ . '/includes/ps_layout.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die('Connection failed.');
}

// 2026-11-02: this diagnostic covers every company's records, so it is for Super Admins only.
require_once __DIR__ . '/includes/platform.php';
if (!psPlatformSessionIsSuperAdmin()) {
    header('Location: admin_dashboard.php');
    exit;
}
psBranchIntegrityEnsureSchema($conn);
$snap = psBranchIntegritySnapshot($conn);
$constraintAssess = psBranchIntegrityConstraintAssessment($conn);
$mismatchReview = psBranchIntegrityMismatchReview($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Integrity</title>
    <?php ps_head(); ?>
</head>
<body class="ps-app">
<?php ps_open('Branch Integrity'); ?>
<div class="p95-wrap">
  <h1>Branch Integrity</h1>
  <p class="ps-page-lead">Read-only check of records whose branch name is missing or not in the branch list.</p>

  <div class="p95-grid">
    <div class="p95-kpi"><b><?php echo (int)($snap['fingerprints']['branchies'] ?? 0); ?></b><span>Registered branches</span></div>
    <div class="p95-kpi"><b><?php echo htmlspecialchars((string)($snap['sale_invoice_seq'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></b><span>Next invoice number</span></div>
    <div class="p95-kpi"><b><?php echo (int)($snap['max_committed_invoice'] ?? 0); ?></b><span>Last saved invoice</span></div>
    <div class="p95-kpi"><b><?php echo (int)($snap['null_open_count'] ?? 0); ?></b><span>NULL-branch OPEN tills</span></div>
    <div class="p95-kpi"><b><?php echo (int)($snap['mismatch_distinct_lines'] ?? 0); ?></b><span>Line↔header mismatches (distinct lines)</span></div>
    <div class="p95-kpi"><b><?php echo (int)($snap['users']['outside_registry'] ?? 0); ?></b><span>Users outside registry</span></div>
  </div>

  <div class="p95-section">
    <h2>Registered branches</h2>
    <p class="p95-note"><?php echo htmlspecialchars(implode(', ', $snap['registered'] ?? []), ENT_QUOTES, 'UTF-8'); ?></p>
    <p class="p95-note">Lookup index <code>idx_branchies_branch_name</code>: <?php echo !empty($snap['fingerprints']['idx_branchies_branch_name']) ? 'present' : 'missing'; ?> (non-unique; no FK/UNIQUE).</p>
  </div>

  <div class="p95-section">
    <h2>Invoice sequence gaps</h2>
    <?php if (empty($snap['invoice_gaps'])): ?>
      <p class="p95-note">No gap between max committed invoice and next_number.</p>
    <?php else: ?>
      <table class="p95-table">
        <thead><tr><th>From</th><th>To</th><th>Count</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($snap['invoice_gaps'] as $g): ?>
          <tr>
            <td><?php echo (int)$g['from']; ?></td>
            <td><?php echo (int)$g['to']; ?></td>
            <td><?php echo (int)$g['count']; ?></td>
            <td><?php echo htmlspecialchars((string)$g['note'], ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="p95-note">Sequence is not reset. Gaps may include pre-9.4 advancement and Phase 9.4 probe sync numbers whose sale rows were cleaned up.</p>
    <?php endif; ?>
  </div>

  <?php
  $renderGroup = static function (string $title, array $group): void {
      echo '<div class="p95-section"><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
      if (!empty($group['absent'])) {
          echo '<p class="p95-note">Table absent.</p></div>';
          return;
      }
      if (!empty($group['absent_column'])) {
          echo '<p class="p95-note">No branch column.</p></div>';
          return;
      }
      if (isset($group['note']) && empty($group['by_name'])) {
          echo '<p class="p95-note">Count: ' . (int)($group['count'] ?? 0) . '. ' . htmlspecialchars((string)$group['note'], ENT_QUOTES, 'UTF-8') . '</p></div>';
          return;
      }
      echo '<table class="p95-table"><thead><tr><th>Branch</th><th>Count</th><th>Class</th></tr></thead><tbody>';
      foreach ($group['by_name'] ?? [] as $row) {
          $cls = (string)($row['class'] ?? '');
          echo '<tr><td>' . htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8') . '</td>';
          echo '<td>' . (int)$row['count'] . '</td>';
          echo '<td><span class="p95-tag ' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
      }
      echo '</tbody></table></div>';
  };
  $renderGroup('Sale headers (online_sales_ref)', $snap['online_sales_ref'] ?? []);
  $renderGroup('Sale lines (online_sales)', $snap['online_sales'] ?? []);
  $renderGroup('Goods', $snap['goods'] ?? []);
  $renderGroup('Till sessions', $snap['sessions'] ?? []);
  $renderGroup('movements_receipts.branch', $snap['movements_receipts'] ?? []);
  $renderGroup('goods_receipts_ref.branch', $snap['goods_receipts_ref'] ?? []);
  $renderGroup('goods_receipts_ref.sending_branch', $snap['goods_receipts_ref_sending'] ?? []);
  $renderGroup('stock_take_adj', $snap['stock_take_adj'] ?? []);
  ?>

  <div class="p95-section">
    <h2>Users by home branch</h2>
    <table class="p95-table">
      <thead><tr><th>Branch</th><th>Count</th><th>Class</th></tr></thead>
      <tbody>
      <?php foreach (($snap['users']['by_name'] ?? []) as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars((string)$row['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo (int)$row['count']; ?></td>
          <td><span class="p95-tag <?php echo htmlspecialchars((string)$row['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$row['class'], ENT_QUOTES, 'UTF-8'); ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="p95-section">
    <h2>NULL-branch OPEN till sessions</h2>
    <p class="p95-note">Left unchanged in Phase 9.5–9.6. Block branch switch and operational till use; do not auto-assign branch or close without authorised business decision. Cannot claim/switch into NULL-branch OPEN tills.</p>
    <table class="p95-table">
      <thead><tr><th>ID</th><th>Cashier</th><th>Opened</th><th>Register</th><th>Float</th></tr></thead>
      <tbody>
      <?php foreach (($snap['null_open_sessions'] ?? []) as $s): ?>
        <tr>
          <td><?php echo (int)$s['id']; ?></td>
          <td><?php echo htmlspecialchars((string)($s['cashier_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> (id <?php echo (int)($s['cashier_id'] ?? 0); ?>)</td>
          <td><?php echo htmlspecialchars((string)($s['opened_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo $s['register_id'] === null || $s['register_id'] === '' ? 'NULL' : (int)$s['register_id']; ?></td>
          <td><?php echo htmlspecialchars((string)($s['opening_float'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="p95-section">
    <h2>Sale line↔header mismatches</h2>
    <p class="p95-note">
      Distinct mismatched lines: <strong><?php echo (int)($snap['mismatch_distinct_lines'] ?? 0); ?></strong>
      (join rows <?php echo (int)($snap['mismatch_join_rows'] ?? 0); ?>).
      Duplicate header invoice numbers: <strong><?php echo (int)($snap['duplicate_header_invoice_numbers'] ?? 0); ?></strong>.
      Phase 9.6 review: provable=<?php echo (int)($mismatchReview['provable'] ?? 0); ?>,
      unresolved=<?php echo (int)($mismatchReview['unresolved'] ?? 0); ?>.
      No conclusive remediations — preserved.
    </p>
    <?php if (!empty($mismatchReview['samples'])): ?>
    <table class="p95-table">
      <thead><tr><th>Line</th><th>Invoice</th><th>Line branch</th><th>Header branch</th><th>Class</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($mismatchReview['samples'] as $mm): ?>
        <tr>
          <td><?php echo (int)$mm['line_id']; ?></td>
          <td><?php echo htmlspecialchars((string)$mm['invoice'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$mm['line_branch'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars((string)$mm['header_branch'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><span class="p95-tag <?php echo htmlspecialchars((string)$mm['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$mm['class'], ENT_QUOTES, 'UTF-8'); ?></span></td>
          <td><?php echo htmlspecialchars((string)$mm['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="p95-section">
    <h2>Constraints / indexes (Phase 9.6 assessment)</h2>
    <p class="p95-note">
      UNIQUE(branch_name) safe to add: <strong><?php echo !empty($constraintAssess['unique_safe']) ? 'yes' : 'no (deferred)'; ?></strong>.
      FK to branchies safe: <strong><?php echo !empty($constraintAssess['fk_safe']) ? 'yes' : 'no (deferred)'; ?></strong>.
      Name duplicate rows: <?php echo (int)($constraintAssess['unique_name_duplicates'] ?? 0); ?>.
    </p>
    <?php if (!empty($constraintAssess['blockers'])): ?>
      <ul class="p95-policy">
        <?php foreach ($constraintAssess['blockers'] as $b): ?>
          <li><?php echo htmlspecialchars((string)$b, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($constraintAssess['notes'])): ?>
      <ul class="p95-policy">
        <?php foreach ($constraintAssess['notes'] as $n): ?>
          <li><?php echo htmlspecialchars((string)$n, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="p95-section">
    <h2>Classification policy</h2>
    <ul class="p95-policy">
      <?php foreach (($snap['policy'] ?? []) as $cls => $text): ?>
        <li><span class="p95-tag <?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="p95-section">
    <h2>Fingerprints</h2>
    <table class="p95-table">
      <tbody>
      <?php foreach (($snap['fingerprints'] ?? []) as $k => $v): ?>
        <tr><th><?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?></th><td><?php echo htmlspecialchars(is_bool($v) ? ($v ? 'Y' : 'N') : (string)$v, ENT_QUOTES, 'UTF-8'); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
ps_close();
mysqli_close($conn);
?>
</body>
</html>
