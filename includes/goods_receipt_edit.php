<?php
/**
 * 2026-11-11 — Edit a saved goods receipt (GRV) to fix a mistake.
 *
 * Who: the "Edit goods receipts" role permission (Role Permissions; Management by default, Admin always).
 * What: supplier invoice number, receipt date, supplier (receipts without a purchase order), and per line the quantity,
 * unit cost and VAT (cost and VAT only without a purchase order: a PO line keeps the PO's cost). A line can be removed
 * while at least one stays. To add a product that was forgotten, make a new goods receipt.
 *
 * Stock: the difference of each changed quantity is posted as a GRV_CORRECTION stock movement, so the ledger and the
 * history show the original receipt and the correction. Totals (and the base-currency total, at the rate stored when the
 * goods were received) are worked out again. Every change is kept in the goods receipt audit with the reason.
 *
 * Limits (company settings, set by the admin on the Goods Receipt page):
 *   grv_edit_lock_exported  1 = no edits once the GRV is in an accounting export           (default on)
 *   grv_edit_lock_returns   1 = no edits once goods were returned against it               (default on)
 *   grv_edit_days           N = only within N days of the receipt date; 0 = no time limit  (default 0)
 *   grv_edit_no_negative    1 = an edit may not take a product's stock below zero          (default on)
 */

require_once __DIR__ . '/goods_receipt.php';
require_once __DIR__ . '/stock_movement.php';
require_once __DIR__ . '/cash_controls.php';
require_once __DIR__ . '/role_permissions.php';
require_once __DIR__ . '/purchase_order.php';

const PS_GRV_EDIT_SETTINGS = [
    'grv_edit_lock_exported' => '1',
    'grv_edit_lock_returns' => '1',
    'grv_edit_days' => '0',
    'grv_edit_no_negative' => '1',
];

/** The company's edit limits, with defaults for anything never set. */
function psGrvEditSettings(mysqli $conn, ?string $company = null): array
{
    $company = trim((string)($company ?? psCashControlsCompany()));
    $out = PS_GRV_EDIT_SETTINGS;
    if ($company !== '' && psCashControlsEnsureSchema($conn)) {
        $stmt = mysqli_prepare($conn, "SELECT setting_key, setting_value FROM cash_control_settings
            WHERE company_name = ? AND setting_key IN ('grv_edit_lock_exported', 'grv_edit_lock_returns', 'grv_edit_days', 'grv_edit_no_negative')");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $company);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $out[(string)$r['setting_key']] = (string)$r['setting_value'];
            }
            mysqli_stmt_close($stmt);
        }
    }
    return [
        'lock_exported' => $out['grv_edit_lock_exported'] !== '0',
        'lock_returns' => $out['grv_edit_lock_returns'] !== '0',
        'days' => max(0, min(3650, (int)$out['grv_edit_days'])),
        'no_negative' => $out['grv_edit_no_negative'] !== '0',
    ];
}

/** Admin only. $posted: lock_exported / lock_returns / no_negative ('1' when ticked), days (whole number). */
function psGrvEditSaveSettings(mysqli $conn, array $posted, string $actor): array
{
    if (psCashControlsLevel() !== 'admin') {
        return ['ok' => false, 'error' => 'Only an administrator may change this.'];
    }
    $company = psCashControlsCompany();
    if ($company === '' || !psCashControlsEnsureSchema($conn)) {
        return ['ok' => false, 'error' => 'The settings could not be saved.'];
    }
    $daysRaw = trim((string)($posted['days'] ?? '0'));
    if ($daysRaw === '') {
        $daysRaw = '0';
    }
    if (!preg_match('/^\d{1,4}$/', $daysRaw) || (int)$daysRaw > 3650) {
        return ['ok' => false, 'error' => 'Days must be a whole number from 0 to 3650 (0 = no time limit).'];
    }
    $values = [
        'grv_edit_lock_exported' => !empty($posted['lock_exported']) ? '1' : '0',
        'grv_edit_lock_returns' => !empty($posted['lock_returns']) ? '1' : '0',
        'grv_edit_days' => (string)(int)$daysRaw,
        'grv_edit_no_negative' => !empty($posted['no_negative']) ? '1' : '0',
    ];
    $now = date('Y-m-d H:i:s');
    $stmt = mysqli_prepare($conn, 'INSERT INTO cash_control_settings (company_name, setting_key, setting_value, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The settings could not be saved.'];
    }
    foreach ($values as $key => $val) {
        mysqli_stmt_bind_param($stmt, 'sssss', $company, $key, $val, $actor, $now);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return ['ok' => false, 'error' => 'The settings could not be saved.'];
        }
    }
    mysqli_stmt_close($stmt);
    if (function_exists('psSecurityAudit')) {
        psSecurityAudit($conn, 'SETTING_CHANGE', ['target_type' => 'setting', 'target_id' => 'grv_edit',
            'summary' => 'Goods receipt editing limits: exported lock ' . $values['grv_edit_lock_exported'] . ', returns lock '
                . $values['grv_edit_lock_returns'] . ', days ' . $values['grv_edit_days'] . ', no negative stock ' . $values['grv_edit_no_negative']]);
    }
    return ['ok' => true];
}

/** May the signed-in user edit goods receipts? */
function psGrvEditAllowed(?mysqli $conn = null): bool
{
    return psRolePermSession('grv_edit', $conn);
}

/**
 * One GRV on a branch with its lines, the lines' returned quantities and why it cannot be edited ([] = it can).
 *
 * @return array{ok:bool,error?:string,ref?:array,lines?:array,locks?:array,po?:?array}
 */
function psGrvEditLoad(mysqli $conn, string $grvNumber, string $branch, bool $forUpdate = false): array
{
    $grvNumber = trim($grvNumber);
    if ($grvNumber === '' || $branch === '') {
        return ['ok' => false, 'error' => 'Goods receipt not found.'];
    }
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = mysqli_prepare($conn, 'SELECT * FROM goods_receipts_ref WHERE grv_number = ? AND branch = ? ORDER BY id LIMIT 1' . $lock);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The goods receipt could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $grvNumber, $branch);
    mysqli_stmt_execute($stmt);
    $ref = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if ($ref === null) {
        return ['ok' => false, 'error' => 'Goods receipt not found on this branch.'];
    }

    $stmt = mysqli_prepare($conn, 'SELECT * FROM goods_receipts WHERE grv_number = ? AND branch = ? ORDER BY id' . $lock);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'The goods receipt could not be loaded.'];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $grvNumber, $branch);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $lines = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);

    // Quantities already returned to the supplier, per GRV line.
    $returned = [];
    $returnCount = 0;
    $t = mysqli_query($conn, "SHOW TABLES LIKE 'purchase_returns'");
    if ($t && mysqli_num_rows($t) > 0) {
        $refId = (int)$ref['id'];
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) n FROM purchase_returns
            WHERE (goods_receipt_ref_id = ? OR (grv_number = ? AND branch = ?)) AND UPPER(COALESCE(status, '')) NOT IN ('CANCELLED', 'VOID')");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iss', $refId, $grvNumber, $branch);
            mysqli_stmt_execute($stmt);
            $returnCount = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['n'] ?? 0);
            mysqli_stmt_close($stmt);
        }
        $ids = array_map(static fn($l) => (int)$l['id'], $lines);
        if ($ids) {
            $in = implode(',', $ids);
            $q = mysqli_query($conn, "SELECT l.goods_receipt_line_id id, COALESCE(SUM(l.quantity), 0) q
                FROM purchase_return_lines l JOIN purchase_returns r ON r.id = l.purchase_return_id
                WHERE l.goods_receipt_line_id IN ($in) AND UPPER(COALESCE(r.status, '')) NOT IN ('CANCELLED', 'VOID')
                GROUP BY l.goods_receipt_line_id");
            while ($q && ($r = mysqli_fetch_assoc($q))) {
                $returned[(int)$r['id']] = psStockDecimal((string)$r['q']) ?? '0.000';
            }
        }
    }
    foreach ($lines as &$l) {
        $l['returned_qty'] = $returned[(int)$l['id']] ?? '0.000';
    }
    unset($l);

    $po = null;
    $poId = (int)($ref['purchase_order_id'] ?? 0);
    if ($poId > 0) {
        $po = psPurchaseOrderById($conn, $poId);
    }

    $settings = psGrvEditSettings($conn);
    $locks = [];
    if ($settings['lock_exported']) {
        require_once __DIR__ . '/accounting.php';
        if (function_exists('psAccountingSourceExportState')) {
            $exp = psAccountingSourceExportState($conn, defined('PS_ACCT_SRC_GRV') ? PS_ACCT_SRC_GRV : 'GRV', (string)(int)$ref['id']);
            if (!empty($exp['exported'])) {
                $locks[] = 'It is in an accounting export (batch ' . (int)$exp['batch_id'] . '). Correct it with a purchase return or a new goods receipt.';
            }
        }
    }
    if ($settings['lock_returns'] && $returnCount > 0) {
        $locks[] = 'Goods were already returned to the supplier against it.';
    }
    if ($settings['days'] > 0) {
        $received = DateTime::createFromFormat('Y-m-d', substr((string)$ref['date_'], 0, 10));
        if ($received) {
            $age = (int)$received->diff(new DateTime('today'))->format('%r%a');
            if ($age > $settings['days']) {
                $locks[] = 'It is older than ' . $settings['days'] . ' day' . ($settings['days'] === 1 ? '' : 's') . ' (received ' . $received->format('Y-m-d') . ').';
            }
        }
    }
    if ($po !== null && strtoupper(trim((string)($po['status'] ?? ''))) === PS_PO_STATUS_CANCELLED) {
        $locks[] = 'Its purchase order was cancelled.';
    }
    return ['ok' => true, 'ref' => $ref, 'lines' => $lines, 'locks' => $locks, 'po' => $po, 'settings' => $settings, 'return_count' => $returnCount];
}

/**
 * Save an edit. $edit: invoice_number, date, supplier_name, reason, lines[id] = [quantity, line_cost, vat, remove].
 *
 * @return array{ok:bool,error?:string,changes?:int}
 */
function psGrvEditSave(mysqli $conn, string $grvNumber, array $edit): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    if (!psGrvEditAllowed($conn)) {
        return ['ok' => false, 'error' => 'You do not have permission to edit goods receipts.'];
    }
    $actor = psSessionGrvUser();
    if ($actor === '') {
        return ['ok' => false, 'error' => 'You must be signed in to edit goods receipts.'];
    }
    $branchCheck = psRequireSessionProductBranch($conn);
    if (empty($branchCheck['ok'])) {
        return ['ok' => false, 'error' => (string)($branchCheck['error'] ?? 'That branch is not available.')];
    }
    $branch = (string)$branchCheck['branch'];
    $reason = trim((string)($edit['reason'] ?? ''));
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 400) {
        return ['ok' => false, 'error' => 'Give a reason for the change (3–400 characters), e.g. "Supplier invoice was 12 boxes, not 21".'];
    }
    if (!stockMovementEnsureSchema($conn) || !psEnsureGoodsReceiptAllSchema($conn)) {
        return ['ok' => false, 'error' => 'The goods receipt could not be saved.'];
    }

    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'The goods receipt could not be saved.'];
    }
    try {
        $load = psGrvEditLoad($conn, $grvNumber, $branch, true);
        if (empty($load['ok'])) {
            throw new RuntimeException((string)$load['error']);
        }
        if ($load['locks']) {
            throw new RuntimeException('This goods receipt cannot be edited: ' . implode(' ', $load['locks']));
        }
        $ref = $load['ref'];
        $settings = $load['settings'];
        $poId = (int)($ref['purchase_order_id'] ?? 0);
        $poMode = $poId > 0;
        $now = date('Y-m-d H:i:s');
        $changes = [];

        // ---- header
        $oldInvoice = (string)$ref['reference1'];
        $newInvoice = trim((string)($edit['invoice_number'] ?? $oldInvoice));
        if ($newInvoice === '' || mb_strlen($newInvoice) > 250) {
            throw new RuntimeException('Supplier invoice number is required.');
        }
        $oldDate = substr((string)$ref['date_'], 0, 10);
        $newDate = trim((string)($edit['date'] ?? $oldDate));
        $d = DateTime::createFromFormat('Y-m-d', $newDate);
        if (!$d || $d->format('Y-m-d') !== $newDate) {
            throw new RuntimeException('Date is required.');
        }
        if ($newDate > date('Y-m-d')) {
            throw new RuntimeException('The receipt date cannot be in the future.');
        }
        $oldSupplier = (string)$ref['product'];
        $newSupplier = $oldSupplier;
        if (!$poMode) {
            $newSupplier = trim((string)($edit['supplier_name'] ?? $oldSupplier));
            if ($newSupplier === '' || mb_strlen($newSupplier) > 250) {
                throw new RuntimeException('Supplier is required.');
            }
        }

        // ---- PO lines: how much may be received on each (ordered − received on other receipts)
        $poRoom = [];
        if ($poMode) {
            $poLines = psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, $poId));
            foreach ($poLines as $pl) {
                $poRoom[(int)$pl['id']] = ['ordered' => (string)$pl['ordered_qty'], 'received' => (string)$pl['received_qty']];
            }
        }

        // ---- lines
        $posted = is_array($edit['lines'] ?? null) ? $edit['lines'] : [];
        $keep = 0;
        $plan = [];
        foreach ($load['lines'] as $line) {
            $id = (int)$line['id'];
            $p = is_array($posted[$id] ?? null) ? $posted[$id] : [];
            $oldQty = psStockDecimal((string)$line['quantity']) ?? '0.000';
            $remove = !empty($p['remove']);
            $newQty = $oldQty;
            if (!$remove) {
                $raw = trim((string)($p['quantity'] ?? $line['quantity']));
                $newQty = psStockDecimal($raw);
                if ($newQty === null || psStockDecimalCmp($newQty, '0') <= 0) {
                    throw new RuntimeException('Quantity for ' . $line['product'] . ' must be greater than zero (tick Remove to take the line off).');
                }
                $keep++;
            }
            if (psStockDecimalCmp($line['returned_qty'], '0') > 0) {
                if ($remove) {
                    throw new RuntimeException($line['product'] . ' cannot be removed: some of it was already returned to the supplier.');
                }
                if (psStockDecimalCmp($newQty, $line['returned_qty']) < 0) {
                    throw new RuntimeException($line['product'] . ': ' . psStockQtyPlain($line['returned_qty']) . ' were already returned, so the quantity cannot go below that.');
                }
            }
            $oldCost = (string)$line['line_cost'];
            $newCost = $oldCost;
            $oldVat = (string)$line['vat'];
            $newVat = $oldVat;
            if (!$poMode && !$remove) {
                $costRaw = trim((string)($p['line_cost'] ?? $oldCost));
                $cc = psValidateProductMoney($costRaw === '' ? '0' : $costRaw, 'Unit cost');
                if (empty($cc['ok'])) {
                    throw new RuntimeException($line['product'] . ': ' . ($cc['error'] ?? 'unit cost is not valid.'));
                }
                $newCost = (string)$cc['value'];
                $vatRaw = trim((string)($p['vat'] ?? $oldVat));
                $vc = psValidateProductMoney($vatRaw === '' ? '0' : $vatRaw, 'VAT');
                if (empty($vc['ok'])) {
                    throw new RuntimeException($line['product'] . ': ' . ($vc['error'] ?? 'VAT is not valid.'));
                }
                $newVat = (string)$vc['value'];
            }
            $poLineId = (int)($line['purchase_order_line_id'] ?? 0);
            if ($poMode && !$remove && $poLineId > 0 && isset($poRoom[$poLineId])) {
                $others = function_exists('bcsub') ? bcsub($poRoom[$poLineId]['received'], $oldQty, 3) : (string)((float)$poRoom[$poLineId]['received'] - (float)$oldQty);
                $room = function_exists('bcsub') ? bcsub($poRoom[$poLineId]['ordered'], $others, 3) : (string)((float)$poRoom[$poLineId]['ordered'] - (float)$others);
                if (psStockDecimalCmp($newQty, $room) > 0) {
                    throw new RuntimeException($line['product'] . ': the purchase order allows at most ' . psStockQtyPlain($room) . ' on this receipt.');
                }
            }
            $plan[] = ['line' => $line, 'remove' => $remove, 'old_qty' => $oldQty, 'new_qty' => $remove ? '0.000' : $newQty,
                'old_cost' => $oldCost, 'new_cost' => $newCost, 'old_vat' => $oldVat, 'new_vat' => $newVat];
        }
        if ($keep === 0) {
            throw new RuntimeException('A goods receipt needs at least one line. To cancel all of it, return the goods to the supplier.');
        }

        // ---- stock: post the differences
        $upd = mysqli_prepare($conn, 'UPDATE goods_receipts SET quantity = ?, line_cost = ?, vat = ?, invoice_number = ?, date_ = ?, supplier_name = ? WHERE id = ?');
        $del = mysqli_prepare($conn, 'DELETE FROM goods_receipts WHERE id = ?');
        if (!$upd || !$del) {
            throw new RuntimeException('The goods receipt could not be saved.');
        }
        $total = '0.00';
        $totalVat = '0.00';
        foreach ($plan as $pl) {
            $line = $pl['line'];
            $id = (int)$line['id'];
            $delta = function_exists('bcsub') ? bcsub($pl['new_qty'], $pl['old_qty'], 3) : number_format((float)$pl['new_qty'] - (float)$pl['old_qty'], 3, '.', '');
            if (psStockDecimalCmp($delta, '0') !== 0) {
                if ($settings['no_negative'] && psStockDecimalCmp($delta, '0') < 0) {
                    $bal = getStockBalance($conn, (string)$line['product_id'], $branch);
                    $after = function_exists('bcadd') ? bcadd($bal, $delta, 3) : (string)((float)$bal + (float)$delta);
                    if (psStockDecimalCmp($after, '0') < 0) {
                        throw new RuntimeException($line['product'] . ': only ' . psStockQtyPlain($bal) . ' in stock, so the receipt cannot be lowered by '
                            . psStockQtyPlain(ltrim($delta, '-')) . ' (stock would go below zero).');
                    }
                }
                $rec = recordStockMovement($conn, [
                    'product_id' => (string)$line['product_id'],
                    'branch' => $branch,
                    'movement_type' => 'GRV_CORRECTION',
                    'quantity' => $delta,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $grvNumber,
                    'movement_at' => $now,
                    'notes' => mb_substr('Edit of ' . $grvNumber . ': ' . $reason, 0, 500),
                ]);
                if (empty($rec['ok'])) {
                    throw new RuntimeException((string)($rec['error'] ?? 'The stock correction could not be saved.'));
                }
            }
            if ($pl['remove']) {
                mysqli_stmt_bind_param($del, 'i', $id);
                if (!mysqli_stmt_execute($del)) {
                    throw new RuntimeException('The goods receipt could not be saved.');
                }
                $changes[] = ['field_name' => 'line_removed', 'old_value' => $line['product_id'] . ' ' . $line['product'] . ': ' . psStockQtyPlain($pl['old_qty']) . ' @ ' . $pl['old_cost'], 'new_value' => null];
                continue;
            }
            $qtyPlain = psStockQtyPlain($pl['new_qty']);
            mysqli_stmt_bind_param($upd, 'ssssssi', $qtyPlain, $pl['new_cost'], $pl['new_vat'], $newInvoice, $newDate, $newSupplier, $id);
            if (!mysqli_stmt_execute($upd)) {
                throw new RuntimeException('The goods receipt could not be saved.');
            }
            $label = $line['product_id'] . ' ' . $line['product'];
            if (psStockDecimalCmp($pl['new_qty'], $pl['old_qty']) !== 0) {
                $changes[] = ['field_name' => 'quantity', 'old_value' => $label . ': ' . psStockQtyPlain($pl['old_qty']), 'new_value' => $label . ': ' . $qtyPlain];
            }
            if ((float)$pl['new_cost'] !== (float)$pl['old_cost']) {
                $changes[] = ['field_name' => 'unit_cost', 'old_value' => $label . ': ' . $pl['old_cost'], 'new_value' => $label . ': ' . $pl['new_cost']];
            }
            if ((float)$pl['new_vat'] !== (float)$pl['old_vat']) {
                $changes[] = ['field_name' => 'vat', 'old_value' => $label . ': ' . $pl['old_vat'], 'new_value' => $label . ': ' . $pl['new_vat']];
            }
            $total = function_exists('bcadd') ? bcadd($total, psPoComputeLineTotal(psStockQtyPlain($pl['new_qty']), $pl['new_cost']), 2)
                : number_format((float)$total + (float)$pl['new_qty'] * (float)$pl['new_cost'], 2, '.', '');
            $totalVat = function_exists('bcadd') ? bcadd($totalVat, (string)(float)$pl['new_vat'], 2) : number_format((float)$totalVat + (float)$pl['new_vat'], 2, '.', '');
        }
        mysqli_stmt_close($upd);
        mysqli_stmt_close($del);

        // ---- header and totals (the base total at the rate stored when the goods were received)
        require_once __DIR__ . '/currency.php';
        $baseSql = '';
        if (psFxHasColumn($conn, 'goods_receipts_ref', 'base_total') && trim((string)($ref['currency'] ?? '')) !== '') {
            $snap = psFxStoredSnapshot($conn, $ref, psFxSessionCompany());
            if ($snap['frac'] !== null) {
                $baseSql = ", base_total = '" . mysqli_real_escape_string($conn, psFxCentsToMoney(psFxToBaseCents($conn, $snap, (int)psFxMoneyToCents($total)))) . "'";
            }
        }
        $refId = (int)$ref['id'];
        $hdr = mysqli_prepare($conn, 'UPDATE goods_receipts_ref SET reference1 = ?, date_ = ?, product = ?, totalz = ?, vat = ?' . $baseSql . ' WHERE id = ?');
        if (!$hdr) {
            throw new RuntimeException('The goods receipt could not be saved.');
        }
        mysqli_stmt_bind_param($hdr, 'sssssi', $newInvoice, $newDate, $newSupplier, $total, $totalVat, $refId);
        if (!mysqli_stmt_execute($hdr)) {
            throw new RuntimeException('The goods receipt could not be saved.');
        }
        mysqli_stmt_close($hdr);
        if ($newInvoice !== $oldInvoice) {
            $changes[] = ['field_name' => 'supplier_invoice', 'old_value' => $oldInvoice, 'new_value' => $newInvoice];
        }
        if ($newDate !== $oldDate) {
            $changes[] = ['field_name' => 'date', 'old_value' => $oldDate, 'new_value' => $newDate];
        }
        if ($newSupplier !== $oldSupplier) {
            $changes[] = ['field_name' => 'supplier_name', 'old_value' => $oldSupplier, 'new_value' => $newSupplier];
        }
        if ($changes === []) {
            mysqli_rollback($conn);
            return ['ok' => true, 'changes' => 0];
        }
        if ((float)$total !== (float)$ref['totalz']) {
            $changes[] = ['field_name' => 'total', 'old_value' => (string)$ref['totalz'], 'new_value' => $total];
        }
        $changes[] = ['field_name' => 'reason', 'old_value' => null, 'new_value' => $reason];

        // ---- purchase order: reopen if it is no longer fully received, close if it now is
        $poNumber = null;
        if ($poMode && $load['po'] !== null) {
            $po = $load['po'];
            $poNumber = (string)($po['po_number'] ?? '');
            $status = strtoupper(trim((string)$po['status']));
            $full = psPoLinesFullyReceived(psPoLinesWithReceived($conn, psPurchaseOrderLines($conn, $poId)));
            if ($status === PS_PO_STATUS_CLOSED && !$full) {
                $approved = PS_PO_STATUS_APPROVED;
                $closed = PS_PO_STATUS_CLOSED;
                $poBranch = trim((string)$po['branch']);
                $st = mysqli_prepare($conn, 'UPDATE purchase_orders SET status = ?, updated_by = ?, updated_at = ? WHERE id = ? AND branch = ? AND status = ?');
                if (!$st) {
                    throw new RuntimeException('The purchase order could not be reopened.');
                }
                mysqli_stmt_bind_param($st, 'sssiss', $approved, $actor, $now, $poId, $poBranch, $closed);
                if (!mysqli_stmt_execute($st)) {
                    throw new RuntimeException('The purchase order could not be reopened.');
                }
                mysqli_stmt_close($st);
                $changes[] = ['field_name' => 'po_status', 'old_value' => $closed, 'new_value' => $approved . ' (reopened: not fully received after the edit)'];
            } elseif ($status === PS_PO_STATUS_APPROVED && $full) {
                $close = psClosePurchaseOrderIfFullyReceived($conn, $poId, $actor, $now, $grvNumber);
                if (empty($close['ok'])) {
                    throw new RuntimeException((string)($close['error'] ?? 'The purchase order could not be closed.'));
                }
            }
        }

        if (!psGrvAuditRecordChanges($conn, [
            'goods_receipt_ref_id' => $refId,
            'grv_number' => $grvNumber,
            'purchase_order_id' => $poMode ? $poId : null,
            'po_number' => $poNumber,
            'branch' => $branch,
            'action' => PS_GRV_AUDIT_EDIT,
            'changed_by' => $actor,
            'source' => PS_GRV_AUDIT_SOURCE_UI,
            'changed_at' => $now,
        ], $changes)) {
            throw new RuntimeException('The goods receipt could not be saved.');
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('The goods receipt could not be saved.');
        }
        return ['ok' => true, 'changes' => count($changes) - 1];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $msg = $e->getMessage();
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'The goods receipt could not be saved.'];
    }
}
