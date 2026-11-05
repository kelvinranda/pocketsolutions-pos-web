<?php
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/sale_payment.php';
require_once __DIR__ . '/includes/sale_cancel.php';
require_once __DIR__ . '/includes/branch_access.php';
require_once __DIR__ . '/includes/till_session.php';
require_once __DIR__ . '/includes/ps_layout.php';
require_once __DIR__ . '/includes/stock_movement.php';
$paymentToken = psSaleTokenEnsure();
$cancelCartToken = psSaleCancelIssueSubmitToken();

// Sync working branch to any open till owned by this cashier BEFORE rendering POS
// (admin may have switched selected_branch to Village while till is still Westlea).
$branchSyncedNote = '';
$tillAlignError = '';
$posConn = @mysqli_connect($servername, $username, $password, $dbname);
if ($posConn) {
    mysqli_set_charset($posConn, 'utf8mb4');
    if (tillEnsureSchema($posConn)) {
        $openTill = getActiveTillSession($posConn);
        if ($openTill && tillRowIsOpen($openTill)) {
            $aligned = tillAlignWorkingBranchToOpenSession($posConn, $openTill, tillCurrentCashier($posConn));
            if (!empty($aligned['ok'])) {
                if (!empty($aligned['synced'])) {
                    $branchSyncedNote = 'You\'re selling on ' . (string)$aligned['branch']
                        . ' because your till is open there. To sell at another branch, close this till first.';
                } elseif (!empty($aligned['bound'])) {
                    $branchSyncedNote = 'This till had no branch; it was assigned to '
                        . (string)$aligned['branch'] . '.';
                }
            } elseif (!empty($aligned['error'])) {
                $tillAlignError = (string)$aligned['error'];
            }
        }
    }
    // 2026-10-17: "Load into POS" from a quotation — its items at the quoted prices (the sale re-checks everything).
    if (isset($_GET['quote'])) {
        require_once __DIR__ . '/includes/quotation.php';
        $qCompany = trim((string)($_SESSION['the_company'] ?? ''));
        $qBranch = trim((string)($_SESSION['selected_branch'] ?? $_SESSION['branch'] ?? ''));
        $qRow = psQuoteGet($posConn, (int)$_GET['quote'], $qCompany);
        if ($qRow === null) {
            $posQuoteError = 'Quotation was not found.';
        } elseif ($qRow['status'] !== PS_QUOTE_OPEN) {
            $posQuoteError = 'Quotation ' . $qRow['quote_number'] . ' is ' . strtolower(psQuoteStatusLabel($qRow)) . ' and cannot be sold again.';
        } elseif (psQuoteIsExpired($qRow)) {
            $posQuoteError = 'Quotation ' . $qRow['quote_number'] . ' expired on ' . $qRow['valid_until'] . '. Make a copy to quote again at today\'s prices.';
        } elseif (strcasecmp(trim((string)$qRow['branch']), $qBranch) !== 0) {
            $posQuoteError = 'Quotation ' . $qRow['quote_number'] . ' is for ' . $qRow['branch'] . '. You are selling on ' . $qBranch . '.';
        } else {
            $qLines = [];
            $g = mysqli_prepare($posConn, 'SELECT price, sell_by_measure, unit_of_measure FROM goods WHERE product_id = ? AND branch = ? LIMIT 1');
            foreach ($qRow['lines'] as $l) {
                $info = null;
                if ($g) {
                    $pid = (string)$l['product_id'];
                    mysqli_stmt_bind_param($g, 'ss', $pid, $qBranch);
                    mysqli_stmt_execute($g);
                    $info = mysqli_fetch_assoc(mysqli_stmt_get_result($g));
                }
                $qLines[] = [
                    'product_id' => (string)$l['product_id'],
                    'product' => (string)$l['product'],
                    'quantity' => (float)$l['quantity'],
                    'quote_price' => (float)$l['unit_price'],
                    'list_price' => $info ? (float)$info['price'] : (float)$l['list_price'],
                    'measure' => $info ? !empty($info['sell_by_measure']) : false,
                    'unit' => $info ? (string)($info['unit_of_measure'] ?? '') : '',
                ];
            }
            if ($g) {
                mysqli_stmt_close($g);
            }
            $posQuote = [
                'id' => (int)$qRow['id'],
                'number' => (string)$qRow['quote_number'],
                'valid_until' => (string)$qRow['valid_until'],
                'customer_id' => $qRow['customer_master_id'] !== null ? (int)$qRow['customer_master_id'] : null,
                'customer_name' => (string)$qRow['customer_name'],
                // Multi-currency: quoted prices are in the quote's currency; the till switches to it while the quote is loaded.
                'currency' => (string)($qRow['currency'] ?? '') !== '' ? (string)$qRow['currency'] : '',
                'lines' => $qLines,
            ];
        }
    }
    // 2026-10-18: "+ New customer" at the POS (Supervisor+ always; Till users when the company allows it).
    require_once __DIR__ . '/includes/cash_controls.php';
    $mayAddCustomer = psSessionMayAddCustomer($posConn);
    $posAllowNegative = psCompanyAllowsNegativeStock($posConn); // 2026-10-18: company switch (Opening Stock page)
    // 2026-10-20: currencies this company sells in, with the rate in force now (the sale re-checks it).
    require_once __DIR__ . '/includes/currency.php';
    if (psFxEnsureSchema($posConn)) {
        psFxEnsureTransactionColumns($posConn);
        $posFx = psFxSellingPayload($posConn, trim((string)($_SESSION['the_company'] ?? '')));
    }
    mysqli_close($posConn);
}
$mayAddCustomer = $mayAddCustomer ?? false;
$posQuote = $posQuote ?? null;
$posQuoteError = $posQuoteError ?? '';

$branch = htmlspecialchars((string)($_SESSION['selected_branch'] ?? $_SESSION['branch'] ?? ''));
$cashier = htmlspecialchars((string)($_SESSION['logger'] ?? ''));
$company = htmlspecialchars((string)($_SESSION['the_company'] ?? ''));
// Admin = session level admin (users.level). Everyone else (e.g. Till) is till operator.
$isAdmin = psBranchSessionIsAdmin();
// Refunds: any signed-in user may request (psRefundMayCreate); approve remains admin-only.
$mayRefund = trim((string)($_SESSION['logger'] ?? '')) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS · <?php echo $company !== '' ? $company : 'Pockets'; ?></title>
    <?php ps_head(); ?>
    <script src="assets/lib/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="assets/ps-pos.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/ps-pos.css'); ?>">
    <style>
    /* 2026-10-24: View Ticket — match desktop TicketViewModel (SellView overlay, ~640px content + 28px pad). */
    /* 2026-10-31: sale done (replaces the browser alert); restaurant order number shown big. */
    #saleDone.ps-dialog { width: min(440px, calc(100vw - 32px)); padding: 26px; text-align: center; }
    #saleDone .sd-title { margin: 0 0 6px; font-size: 20px; }
    #saleDone .sd-order-label { margin-top: 10px; color: var(--ps-muted); font-size: 13px; font-weight: 700; letter-spacing: .12em; }
    #saleDone .sd-order { font-size: 30px; font-weight: 800; line-height: 1.05; color: var(--ps-yellow); font-variant-numeric: tabular-nums; margin: 2px 0 8px; }
    #saleDone .sd-text { white-space: pre-line; text-align: left; margin: 12px 0 18px; color: var(--ps-text); line-height: 1.5; }
    #saleDone .btn { width: 100%; margin-top: 0; min-height: 52px; font-size: 17px; }
    #ticketSheet.ps-dialog {
        width: min(720px, calc(100vw - 32px));
        max-height: calc(100vh - 32px);
        padding: 28px;
        overflow: hidden;
    }
    #ticketSheet[open] {
        display: flex;
        flex-direction: column;
    }
    .ticket-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
        flex: 0 0 auto;
    }
    #ticketTitle {
        margin: 0;
        font-size: 22px;
        font-weight: 700;
        line-height: 1.2;
    }
    #ticketMeta {
        margin: 4px 0 0;
    }
    .ticket-head-total {
        flex: 0 0 auto;
        font-size: 34px;
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -0.03em;
        color: var(--ps-yellow);
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    #ticketBody {
        overflow: auto;
        flex: 1 1 auto;
        min-height: 0;
        max-height: min(42vh, 360px);
        -webkit-overflow-scrolling: touch;
    }
    .ticket-lines {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    .ticket-lines th {
        color: var(--ps-muted);
        font-size: 12px;
        font-weight: 600;
        text-align: left;
        padding: 0 8px 8px 0;
        border-bottom: 1px solid var(--ps-line);
    }
    .ticket-lines td {
        padding: 8px 8px 8px 0;
        border-bottom: 1px solid var(--ps-line);
        vertical-align: top;
    }
    .ticket-lines td:first-child { font-weight: 600; }
    .ticket-lines th.num,
    .ticket-lines td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        padding-left: 8px;
        padding-right: 0;
        white-space: nowrap;
        width: 1%;
    }
    .ticket-lines .ticket-tax {
        color: var(--ps-muted);
        white-space: normal;
    }
    .ticket-lines .ticket-line-total { font-weight: 700; }
    /* 2026-10-31: change quantities / remove lines on the ticket. */
    .ticket-lines td.ticket-qty { padding-left: 8px; white-space: nowrap; width: 1%; vertical-align: middle; }
    .ticket-step { display: inline-flex; align-items: center; gap: 6px; }
    .ticket-step button {
        min-width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--ps-line);
        background: var(--ps-card-2); color: var(--ps-text); font-size: 16px; font-weight: 700; cursor: pointer;
    }
    .ticket-step button:hover:not(:disabled) { border-color: var(--ps-yellow); }
    .ticket-step button:disabled { opacity: .45; cursor: default; }
    .ticket-step .ticket-n { min-width: 2.2em; text-align: center; font-weight: 700; font-variant-numeric: tabular-nums; }
    .ticket-lines td.ticket-del { width: 1%; padding-left: 6px; vertical-align: middle; }
    .ticket-del button {
        width: 30px; height: 30px; border-radius: 8px; border: 1px solid transparent; background: transparent;
        color: var(--ps-danger, #f07178); font-size: 18px; line-height: 1; cursor: pointer;
    }
    .ticket-del button:hover:not(:disabled) { border-color: var(--ps-danger, #f07178); }
    .ticket-del button:disabled { opacity: .45; cursor: default; }
    #ticketBody.is-updating { opacity: .6; }
    #ticketSheet .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .ticket-totals { margin-top: 10px; flex: 0 0 auto; }
    .ticket-tot {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin: 2px 0;
        font-variant-numeric: tabular-nums;
    }
    .ticket-tot-pay {
        margin-top: 4px;
        font-size: 18px;
        font-weight: 700;
    }
    #ticketLoyalty {
        margin: 12px 0 0;
        padding: 12px 14px;
        border: 1px solid var(--ps-line);
        border-radius: 10px;
        background: var(--ps-card-2);
        flex: 0 0 auto;
    }
    #ticketLoyalty[hidden] { display: none !important; }
    #ticketLoyalty .ticket-loyalty-row {
        display: flex;
        gap: 8px;
        margin-top: 8px;
        align-items: stretch;
    }
    #ticketLoyalty .ticket-loyalty-row .field {
        flex: 1;
        margin-top: 0;
        width: auto;
    }
    #ticketLoyalty .ticket-loyalty-row .btn {
        width: auto;
        margin-top: 0;
        padding: 0 14px;
    }
    #ticketError { flex: 0 0 auto; }
    .ticket-actions {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 8px;
        margin-top: 16px;
        flex: 0 0 auto;
        align-items: stretch;
    }
    .ticket-actions .btn { width: auto; margin-top: 0; }
    .ticket-actions #ticketConfirm {
        width: 100%;
        min-height: 56px;
        font-size: 18px;
    }
    @media (max-width: 700px) {
        #ticketSheet.ps-dialog { padding: 18px 16px 16px; }
        .ticket-head { flex-wrap: wrap; }
        .ticket-head-total { font-size: 28px; }
        .ticket-lines { font-size: 13px; }
        .ticket-actions { grid-template-columns: 1fr 1.6fr; }
    }
    </style>
</head>
<body class="ps-pos">
    <div class="top">
        <div class="brand"><span class="ps-mark"><?php echo ps_h(ps_initials($company !== '' ? $company : 'POS')); ?></span><?php echo $company !== '' ? $company : 'POS'; ?></div>
        <div class="search-wrap">
            <input class="search" id="search" type="text" autocomplete="off" maxlength="64"
                   placeholder="Search or scan barcode / product ID"
                   aria-label="Search products or scan barcode"
                   oninput="loadCatalog()">
        </div>
        <?php ps_theme_switch(); ?>
        <?php
        ps_account_user_chip([
            'variant' => 'pos',
            'name' => $cashier,
            'sub' => $branch,
            'initials_from' => $cashier !== '' ? $cashier : $company,
        ]);
        ?>
    </div>
    <div class="layout">
        <div class="menu">
            <div class="cats" id="cats"></div>
            <div class="grid" id="grid"></div>
        </div>
        <aside class="order" id="orderPane">
            <div class="order-head">
                <div class="till-line" id="tillLine">Till: Not Open</div>
                <div class="pos-currency" id="posCurrencyWrap" hidden>
                    <label for="posCurrency">Currency</label>
                    <select id="posCurrency" onchange="setPosCurrency(this.value)"></select>
                    <span class="pos-rate" id="posRate"></span>
                </div>
                <div class="order-cust">
                    <input type="hidden" id="customerMasterId" value="">
                    <div class="order-cust-row">
                        <div class="order-cust-field">
                            <input class="field" id="customerSearch" placeholder="Optional — code, name, or phone"
                                   value="Cash Customer"
                                   autocomplete="off" aria-label="Search customer (optional)"
                                   oninput="onCustomerSearch()" onfocus="onCustomerSearchFocus()"
                                   onblur="onCustomerSearchBlur()">
                            <div class="cust-results" id="customerResults" hidden></div>
                        </div>
                        <?php if ($mayAddCustomer): ?>
                        <button type="button" class="cust-badge" id="custBadge" title="Add / + custom"
                                aria-label="Add custom" onclick="toggleNewCustomer(true)">+ Custom</button>
                        <?php endif; ?>
                    </div>
                    <div class="order-cust-meta">
                        <div class="hint" id="customerSelectedLabel" hidden>Cash Customer</div>
                        <button type="button" class="btn btn-gray cust-clear" id="customerClearBtn" hidden
                                onclick="clearPosCustomer()">Clear</button>
                    </div>
                    <?php if ($mayAddCustomer): ?>
                    <button type="button" class="btn btn-gray" id="newCustBtn" hidden
                            onclick="toggleNewCustomer(true)">+ New customer</button>
                    <div id="newCustForm" style="display:none;margin-top:8px;gap:6px">
                        <select class="field" id="ncType" onchange="syncNewCustomerType()" aria-label="Customer type">
                            <option value="INDIVIDUAL">Individual</option>
                            <option value="BUSINESS">Business</option>
                        </select>
                        <input class="field" id="ncFirst" placeholder="First name" maxlength="250" autocomplete="off">
                        <input class="field" id="ncLast" placeholder="Last name" maxlength="250" autocomplete="off">
                        <input class="field" id="ncBiz" placeholder="Business name" maxlength="250" autocomplete="off" style="display:none">
                        <input class="field" id="ncPhone" placeholder="Phone (optional)" maxlength="64" autocomplete="off">
                        <input class="field" id="ncEmail" placeholder="Email (optional)" maxlength="250" autocomplete="off">
                        <p class="pay-error" id="ncError" hidden></p>
                        <div style="display:flex;gap:6px">
                            <button type="button" class="btn btn-blue" id="ncSave" onclick="saveNewCustomer()">Add customer</button>
                            <button type="button" class="btn btn-gray" onclick="toggleNewCustomer(false)">Cancel</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="kicker order-cart-kicker">Cart</div>
            </div>
            <div id="quoteBanner" class="hint" hidden style="margin:6px 0 8px;padding:8px 10px;border-radius:8px;background:rgba(23,138,67,.12);border:1px solid rgba(23,138,67,.35)"></div>
            <div class="order-lines" id="orderLines"></div>
            <div class="order-foot">
                <div class="total"><span>Items</span><span id="itemCount">0</span></div>
                <div class="total"><span>Subtotal</span><span id="subtotalAmount">$0.00</span></div>
                <div class="total"><span>Total</span><span id="totalAmount">$0.00</span></div>
                <div class="order-actions">
                    <button class="btn btn-gray" type="button" data-pay onclick="openTicket(false)" title="Show the customer the ticket">&#128065; View Ticket</button>
                    <button class="btn btn-yellow" type="button" data-pay onclick="openPaymentScreen()">Place Order</button>
                    <button class="btn btn-blue" type="button" data-pay onclick="openPaymentScreen()">Print / Submit</button>
                    <button class="btn btn-gray" type="button" id="discardCartBtn" onclick="openDiscardCart()">Clear cart</button>
                </div>
                <p class="pay-error" id="orderNotice" role="alert" hidden></p>
            </div>
        </aside>
    </div>
    <nav class="nav">
        <button class="on" type="button" onclick="setCategory('')">Home</button>
        <?php if ($isAdmin) { ?>
        <a href="admin_dashboard.php">Admin</a>
        <?php } else { ?>
        <a href="all_receipts.php">Orders</a>
        <?php } ?>
        <button class="fab" type="button" onclick="toggleOrder()">🛒</button>
        <button type="button" onclick="openSheet('tillSheet')">Till</button>
        <button type="button" onclick="openSheet('moreSheet')">More</button>
    </nav>

    <div class="modal modal-sheet" id="tillSheet" onclick="if(event.target===this)closeSheets()">
        <div class="sheet">
            <h3>Till</h3>
            <p class="hint"><?php echo $cashier; ?> · Branch: <?php echo $branch !== '' ? $branch : 'none selected'; ?></p>
            <p class="hint" id="tillSheetStatus"><?php
                if ($tillAlignError !== '') {
                    echo htmlspecialchars($tillAlignError, ENT_QUOTES, 'UTF-8');
                } elseif ($branchSyncedNote !== '') {
                    echo htmlspecialchars($branchSyncedNote, ENT_QUOTES, 'UTF-8');
                } else {
                    echo 'Open a till on this branch before selling. Flow: Open → sell → Reconcile → Close Till.';
                }
            ?></p>
            <div class="kicker">Opening float</div>
            <input class="field" id="openingFloat" type="number" min="0" step="0.01" value="0" inputmode="decimal">
            <button class="btn btn-yellow" type="button" onclick="openSession()">Open Session</button>
            <button class="btn btn-gray" type="button" onclick="checkSession()">Session Status</button>
            <a class="btn btn-gray" href="cash_reconciliation.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none;margin-top:8px">Cash Reconciliation</a>
            <a class="btn btn-gray" href="cash_till_close.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Close Till…</a>
            <p class="hint" style="margin-top:10px">Close requires a <strong>current</strong> cash reconciliation first. Flow: Open → sell → Reconcile → Close Till.</p>
            <p class="pay-error" id="tillSheetError" role="alert"<?php echo $tillAlignError !== '' ? '' : ' hidden'; ?>><?php
                echo $tillAlignError !== '' ? htmlspecialchars($tillAlignError, ENT_QUOTES, 'UTF-8') : '';
            ?></p>
        </div>
    </div>
    <div class="modal modal-sheet" id="moreSheet" onclick="if(event.target===this)closeSheets()">
        <div class="sheet">
            <h3>More</h3>
            <?php if ($isAdmin) { ?>
            <a class="btn btn-gray" href="admin_dashboard.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Dashboard</a>
            <a class="btn btn-gray" href="items_list.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Products</a>
            <a class="btn btn-gray" href="all_receipts.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Receipts</a>
            <?php if ($mayRefund) { ?>
            <a class="btn btn-gray" href="refund.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Refunds</a>
            <?php } ?>
            <?php } else { ?>
            <a class="btn btn-gray" href="all_receipts.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Receipts</a>
            <?php if ($mayRefund) { ?>
            <a class="btn btn-gray" href="refund.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Refunds</a>
            <?php } ?>
            <a class="btn btn-gray" href="change_password.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Change password</a>
            <?php } ?>
            <a class="btn btn-gray" href="logout.php" data-ps-logout style="display:flex;align-items:center;justify-content:center;text-decoration:none">Logout</a>
        </div>
    </div>
    <div class="modal modal-dialog" id="discardSheet" onclick="if(event.target===this)closeDiscardCart()">
        <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="discardTitle">
            <h3 id="discardTitle">Clear cart</h3>
            <p class="hint">Discards the uncommitted cart only. No sale, stock, cash or account records are written or reversed. Completed sales cannot be voided here — use Refunds.</p>
            <div class="kicker">Reason</div>
            <select class="field" id="discardReason" aria-label="Discard reason">
                <?php foreach (psSaleCancelControlledReasons() as $code) { ?>
                <option value="<?php echo ps_h($code); ?>"><?php echo ps_h(psSaleCancelReasonLabel($code)); ?></option>
                <?php } ?>
            </select>
            <div class="kicker">Description (required for Other)</div>
            <input class="field" id="discardReasonDescription" type="text" maxlength="500" autocomplete="off" aria-label="Discard description">
            <p class="pay-error" id="discardError" role="alert" hidden></p>
            <button class="btn btn-yellow" type="button" id="discardConfirm" onclick="confirmDiscardCart()">Confirm clear cart</button>
            <button class="btn btn-gray" type="button" onclick="closeDiscardCart()">Back</button>
        </div>
    </div>
    <div class="modal modal-dialog" id="variantSheet" onclick="if(event.target===this)closeVariants()">
        <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="variantTitle">
            <h3 id="variantTitle">Choose</h3>
            <div id="variantList" class="vlist"></div>
            <button class="btn btn-gray" type="button" onclick="closeVariants()">Done</button>
        </div>
    </div>
    <div class="modal modal-dialog" id="measureSheet" onclick="if(event.target===this)cancelMeasure()">
        <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="measureTitle">
            <h3 id="measureTitle">Amount</h3>
            <div class="kicker">Price <span id="measurePrice"></span></div>
            <div class="measure-row">
                <input class="field" id="measureQty" type="number" inputmode="decimal" step="0.001" min="0" aria-label="Quantity"
                       oninput="updateMeasureTotal()" onkeydown="if(event.key==='Enter'){event.preventDefault();confirmMeasure();}">
                <span id="measureUnit" class="measure-unit"></span>
            </div>
            <div class="total"><span>Line total</span><span id="measureTotal">$0.00</span></div>
            <p class="hint" id="measureHint"></p>
            <p class="pay-error" id="measureError" role="alert" hidden></p>
            <button class="btn btn-yellow" type="button" id="measureOk" onclick="confirmMeasure()">Add to cart</button>
            <button class="btn btn-gray" type="button" onclick="cancelMeasure()">Cancel</button>
        </div>
    </div>
    <dialog class="ps-dialog" id="saleDone" aria-labelledby="saleDoneTitle">
        <h3 class="sd-title" id="saleDoneTitle">Sale completed</h3>
        <div id="saleDoneOrderWrap" hidden>
            <div class="sd-order-label">ORDER NUMBER</div>
            <div class="sd-order" id="saleDoneOrder"></div>
        </div>
        <div class="sd-text" id="saleDoneText"></div>
        <button class="btn btn-yellow" type="button" id="saleDoneOk">New sale</button>
    </dialog>
    <dialog class="ps-dialog" id="ticketSheet" aria-labelledby="ticketTitle">
        <div class="ticket-head">
            <div>
                <h3 id="ticketTitle">Customer ticket</h3>
                <p class="hint" id="ticketMeta"></p>
            </div>
            <div class="ticket-head-total" id="ticketHeadTotal" aria-live="polite"></div>
        </div>
        <div id="ticketBody">
            <p class="hint">Loading…</p>
        </div>
        <div id="ticketTotals"></div>
        <div id="ticketLoyalty" hidden>
            <div class="kicker">Loyalty points</div>
            <div class="hint" id="ticketLoyaltyInfo"></div>
            <div class="ticket-loyalty-row">
                <input class="field" id="ticketRedeem" type="text" inputmode="numeric" maxlength="9" placeholder="Points to use" aria-label="Points to use" autocomplete="off">
                <button class="btn btn-gray" type="button" id="ticketRedeemApply" onclick="applyTicketRedeem()">Apply</button>
            </div>
        </div>
        <p class="pay-error" id="ticketError" role="alert" hidden></p>
        <div class="ticket-actions">
            <button class="btn btn-gray" type="button" onclick="closeTicket()">Back</button>
            <button class="btn btn-yellow" type="button" id="ticketConfirm" onclick="confirmTicket()" disabled>Confirm Receipt</button>
        </div>
    </dialog>
    <div class="modal modal-sheet" id="paySheet" onclick="if(event.target===this)closePaySheet()">
        <div class="sheet pay-sheet" role="dialog" aria-modal="true" aria-labelledby="payTitle">
            <h3 id="payTitle">Payment</h3>
            <div class="kicker">Total due</div>
            <div class="pay-due-amount" id="payTotalDue">$0.00</div>
            <div class="total"><span>Items</span><span id="payItemCount">0</span></div>
            <div class="total"><span>Subtotal</span><span id="paySubtotal">$0.00</span></div>
            <div class="total"><span>Total</span><span id="payTotal">$0.00</span></div>
            <p class="hint" id="payContext"></p>
            <div class="kicker">Payment method</div>
            <select class="field" id="paymentMethod" aria-label="Payment method" onchange="onPaymentMethod()">
                <?php foreach (psPaymentMethods() as $code => $label) { ?>
                <option value="<?php echo ps_h($code); ?>"<?php echo $code === 'CASH' ? ' selected' : ''; ?>><?php echo ps_h($label); ?></option>
                <?php } ?>
            </select>
            <div id="creditAccountPanel" class="hint" hidden style="margin:8px 0;padding:8px;border:1px solid #ccc;background:#fafafa">
                <div class="kicker">Account charge (read-only)</div>
                <div id="creditAccountLines">Select an active customer with an account.</div>
            </div>
            <div id="amountPaidWrap">
                <div class="kicker">Amount paid</div>
                <input class="field" id="amountPaid" type="text" inputmode="decimal" aria-label="Amount paid" autocomplete="off" oninput="onAmountPaid()">
            </div>
            <div class="pay-quick" id="payQuick" hidden></div>
            <div id="payRefWrap" hidden>
                <div class="kicker">Reference (optional)</div>
                <input class="field" id="paymentReference" type="text" maxlength="120" placeholder="Optional" aria-label="Reference (optional)" autocomplete="off" oninput="onPaymentReference()">
            </div>
            <!-- 2026-11-05 split payment / split currency: more payments, each with its own method and currency -->
            <div id="splitRows" class="split-rows"></div>
            <button type="button" class="btn btn-gray split-add" id="addTenderBtn" onclick="addTender()">+ Add another payment</button>
            <div class="total" id="dueRow" hidden><span>Still due</span><span id="stillDue"></span></div>
            <div id="changeCurWrap" hidden>
                <div class="kicker">Give change in</div>
                <select class="field" id="changeCurrency" aria-label="Give change in" onchange="syncPayForm()"></select>
            </div>
            <div class="total" id="changeRow"><span>Change</span><span id="changeAmount">$0.00</span></div>
            <div class="pay-confirm-wrap">
                <p class="pay-error" id="payError" role="alert" hidden></p>
                <button class="btn btn-yellow" id="payConfirm" type="button" onclick="submitSale()" disabled>Confirm Payment</button>
            </div>
        </div>
    </div>

<script>
const ALLOW_NEGATIVE_STOCK = <?php echo ($posAllowNegative ?? psAllowNegativeStockSales()) ? 'true' : 'false'; ?>;
const CAT_COLORS = ['#C4524A','#5C4AD4','#B44A86','#A07A2E','#2E57C8','#2C7A4B','#C45C66','#6A45C8'];
let products = [];
let categories = [];
let catColorMap = {}; // Phase 20: button group colours from Categories & Groups
let openVariantGroup = '';
let measureProduct = null;
let activeCategory = '';
let cart = [];
// 2026-10-20: multi-currency. Product prices are in the company base currency; the cart is priced in posCur with the exact
// rate fraction from the server (same half-up rounding as the server, BigInt — no floating point in the conversion).
const POS_FX = <?php echo json_encode($posFx ?? ['base' => 'USD', 'currencies' => ['USD' => ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'position' => 'before', 'ok' => true, 'num' => '1', 'den' => '1', 'rate' => '1']]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let posCur = POS_FX.base;
try { const saved = localStorage.getItem('ps_pos_currency'); if (saved && POS_FX.currencies[saved] && POS_FX.currencies[saved].ok) posCur = saved; } catch (e) {}
function fxInfo(code) { return POS_FX.currencies[code || posCur] || POS_FX.currencies[POS_FX.base]; }
/** Base-currency price (number, 2 dp) -> selected currency price (number, 2 dp). */
function fxUnit(basePrice) {
    const c = fxInfo();
    if (!c || posCur === POS_FX.base) return Number(basePrice) || 0;
    const cents = BigInt(Math.round((Number(basePrice) || 0) * 100));
    const neg = cents < 0n;
    const num = BigInt(c.num), den = BigInt(c.den);
    let v = ((neg ? -cents : cents) * num * 2n + den) / (2n * den);   // round half up
    const step = c.decimals >= 2 ? 1n : (c.decimals === 1 ? 10n : 100n);
    if (step > 1n) v = ((v + step / 2n) / step) * step;
    return Number(neg ? -v : v) / 100;
}
function fmtAmount(value, code) {
    const c = fxInfo(code);
    const dec = c ? c.decimals : 2;
    const n = Number(value) || 0;
    const txt = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    const sym = c && c.symbol ? c.symbol : (code || posCur);
    const out = !c || !c.symbol ? txt + ' ' + (code || posCur) : (c.position === 'after' ? txt + ' ' + sym : sym + (/\p{L}$/u.test(sym) ? ' ' : '') + txt);
    return (n < 0 ? '-' : '') + out;
}
function setPosCurrency(code) {
    const c = POS_FX.currencies[code];
    if (!c) return;
    if (posQuote && quoteCurrency() !== code) {
        showOrderNotice('Quotation ' + posQuote.number + ' is in ' + quoteCurrency() + '. Remove the quote to sell in another currency.');
        syncCurrencyUi();
        return;
    }
    if (!c.ok) { showOrderNotice(c.error || ('No exchange rate for ' + code + '.')); syncCurrencyUi(); return; }
    posCur = code;
    try { localStorage.setItem('ps_pos_currency', code); } catch (e) {}
    repriceCart();
    syncCurrencyUi();
    renderGrid();
    renderCart();
}
function repriceCart() {
    cart.forEach(function (item) {
        if (item.base_price == null) return;
        item.price = fxUnit(item.base_price);
        if (item.base_list_price != null) item.list_price = fxUnit(item.base_list_price);
    });
}
function syncCurrencyUi() {
    const codes = Object.keys(POS_FX.currencies);
    const wrap = document.getElementById('posCurrencyWrap');
    const sel = document.getElementById('posCurrency');
    if (!wrap || !sel) return;
    wrap.hidden = codes.length < 2;
    sel.innerHTML = codes.map(function (k) {
        const c = POS_FX.currencies[k];
        return '<option value="' + escapeHtml(k) + '"' + (k === posCur ? ' selected' : '') + (c.ok ? '' : ' disabled') + '>' + escapeHtml(k + (c.ok ? '' : ' (no rate)')) + '</option>';
    }).join('');
    const c = fxInfo();
    document.getElementById('posRate').textContent = posCur === POS_FX.base ? '' : (c && c.label ? c.label : '');
}
/** A product's base list price: cart lines carry base_list_price; catalogue items carry the base price. */
function baseListOf(product) {
    if (product.base_list_price != null) return Number(product.base_list_price) || 0;
    return Number(product.list_price != null ? product.list_price : product.price) || 0;
}
// 2026-10-17: quotation loaded into the POS (quoted price per product up to the quoted quantity).
let posQuote = <?php echo json_encode($posQuote, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const posQuoteError = <?php echo json_encode($posQuoteError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function quoteLine(id) {
    if (!posQuote) return null;
    return posQuote.lines.find(function (l) { return l.product_id === id; }) || null;
}
/** Quoted price while the quantity is within the quote; the current price above it. */
function quotedPrice(id, qty, fallback) {
    const q = quoteLine(id);
    return (q && Number(qty) <= Number(q.quantity) + 0.0000001) ? Number(q.quote_price) : fallback;
}
/** The quote's currency (quotes made before multi-currency are in the base). */
function quoteCurrency() {
    return posQuote && posQuote.currency ? posQuote.currency : POS_FX.base;
}
/** True while this quantity is still covered by the quote (its price is then fixed in the quote currency). */
function isQuoted(id, qty) {
    const q = quoteLine(id);
    return !!(q && Number(qty) <= Number(q.quantity) + 0.0000001);
}
function renderQuoteBanner() {
    const el = document.getElementById('quoteBanner');
    if (!el) return;
    if (!posQuote) { el.hidden = true; el.innerHTML = ''; return; }
    el.hidden = false;
    el.innerHTML = 'Quotation <b>' + escapeHtml(posQuote.number) + '</b> · ' + escapeHtml(posQuote.customer_name)
        + '<br>Quoted prices apply up to the quoted quantities (valid until ' + escapeHtml(posQuote.valid_until) + '). '
        + '<button type="button" class="btn btn-gray" style="padding:2px 8px;margin-top:4px" onclick="removeQuote()">Remove quote</button>';
}
function removeQuote() {
    if (!posQuote) return;
    cart.forEach(function (item) { if (item.base_list_price != null) { item.base_price = item.base_list_price; item.price = fxUnit(item.base_list_price); } });
    posQuote = null;
    renderQuoteBanner();
    renderCart();
}
function loadQuoteIntoCart() {
    if (posQuoteError) { showOrderNotice(posQuoteError); return; }
    if (!posQuote) return;
    if (posCur !== quoteCurrency()) {
        const qc = POS_FX.currencies[quoteCurrency()];
        if (!qc || !qc.ok) { showOrderNotice('Quotation ' + posQuote.number + ' is in ' + quoteCurrency() + ', which cannot be sold right now: ' + ((qc && qc.error) || 'currency not enabled') + '.'); posQuote = null; return; }
        posCur = quoteCurrency();
        try { localStorage.setItem('ps_pos_currency', posCur); } catch (e) {}
        syncCurrencyUi();
    }
    cart = posQuote.lines.map(function (l) {
        // Quoted price is already in the quote currency: kept as is (base_price null = not repriced).
        return { product_id: l.product_id, product: l.product, price: Number(l.quote_price), list_price: fxUnit(l.list_price),
                 base_price: null, base_list_price: Number(l.list_price),
                 quantity: Number(l.quantity), ledger_qty: null, measure: !!l.measure, unit: l.unit || '' };
    });
    if (posQuote.customer_id) {
        selectedCustomer = { id: posQuote.customer_id, code: '', name: posQuote.customer_name };
        syncCustomerUi();
    }
    renderQuoteBanner();
}
let catalogToken = 0;
let saleBusy = false;
let paidManual = false;
let tillOpen = false;
let payOpening = false;
let serverPayError = '';
let quickSig = '';
let paymentToken = <?php echo json_encode($paymentToken); ?>;
let cancelCartToken = <?php echo json_encode($cancelCartToken); ?>;
let discardBusy = false;
let customerSearchTimer = null;
let customerSearchToken = 0;
let selectedCustomer = null; // { id, code, name } or null = Cash Customer walk-in
const CASH_CUSTOMER_LABEL = 'Cash Customer';
let customerAccountInfo = null; // Phase 6.2 credit snapshot or null
let accountFetchToken = 0;
let barcodeBusy = false;
const posCashier = <?php echo json_encode((string)($_SESSION['logger'] ?? '')); ?>;
// 2026-11-02: shared branch names are stored as NAME~COMPANY; people see NAME.
function psBranchLabel(s) { s = String(s || ''); const i = s.indexOf('~'); return (i < 0 ? s : s.slice(0, i)).trim(); }
let posBranch = <?php echo json_encode((string)($_SESSION['selected_branch'] ?? $_SESSION['branch'] ?? '')); ?>;
const posTillAlignNote = <?php echo json_encode($branchSyncedNote); ?>;
const posTillAlignError = <?php echo json_encode($tillAlignError); ?>;

function catColor(name) {
    if (catColorMap && catColorMap[name]) return catColorMap[name];
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = (hash + name.charCodeAt(i) * (i + 3)) % CAT_COLORS.length;
    return CAT_COLORS[hash];
}
function openSheet(id) {
    if (typeof closeUserChipMenu === 'function') closeUserChipMenu();
    closeSheets();
    document.getElementById(id).classList.add('show');
}
function closeSheets() {
    document.querySelectorAll('.modal').forEach(function (el) {
        if (el.id === 'paySheet' && saleBusy) return;
        el.classList.remove('show');
    });
    const ticketEl = document.getElementById('ticketSheet');
    if (ticketEl && ticketEl.tagName === 'DIALOG' && ticketEl.open) ticketEl.close();
}
function closePaySheet(force) {
    if (saleBusy && !force) return;
    document.getElementById('paySheet').classList.remove('show');
}
function toggleOrder() {
    document.getElementById('orderPane').classList.toggle('mobile-open');
}
function qtyFor(id) {
    const line = cart.find((item) => item.product_id === id && !item.pack_id);
    return line ? Number(line.quantity) : 0;
}
/* ---- 2026-10-28 packs: a product may also be sold in packs (own price, own cart line); stock is in base units ---- */
function packQtyFor(id, packId) {
    const line = cart.find((item) => item.product_id === id && Number(item.pack_id) === Number(packId));
    return line ? Number(line.quantity) : 0;
}
/** Base units (milli) of a product already in the cart: single units + packs × units per pack. */
function cartBaseMilli(id, skipBase, skipPackId) {
    return cart.reduce(function (sum, item) {
        if (item.product_id !== id) return sum;
        if (item.pack_id) {
            if (skipPackId && Number(item.pack_id) === Number(skipPackId)) return sum;
            return sum + Math.round(Number(item.quantity)) * Number(item.factor_milli || 0);
        }
        if (skipBase) return sum;
        return sum + (stockMilli(String(Number(Number(item.quantity).toFixed(3)))) || 0);
    }, 0);
}
function plural(label, milli) {
    label = String(label || '');
    if (!label || milli === 1000 || label.length <= 2) return label;
    if (/(s|x|z|ch|sh)$/i.test(label)) return label + 'es';
    if (/[^aeiou]y$/i.test(label)) return label.slice(0, -1) + 'ies';
    return label + 's';
}
function milliText(milli) { return String(Number((milli / 1000).toFixed(3))); }
function unitsText(milli, label) { return milliText(milli) + (label ? ' ' + plural(label, milli) : ''); }
/** False (and a notice) when this many base units are more than the stock on hand (selling below zero blocked). */
function stockAllows(p, totalMilli, errEl) {
    const available = ledgerText(p.ledger_qty);
    const availableMilli = available === null ? null : stockMilli(available);
    if (ALLOW_NEGATIVE_STOCK || availableMilli === null || totalMilli <= availableMilli) return true;
    const label = p.base_unit ? (p.base_label || p.base_unit) : '';
    const msg = stockLimitMessage(p.product || p.product_id, unitsText(totalMilli, label), unitsText(availableMilli, label));
    if (errEl) { errEl.textContent = msg; errEl.hidden = false; } else { showOrderNotice(msg); }
    return false;
}
function setPackQty(p, pack, qty) {
    qty = Math.max(0, Math.round(Number(qty) || 0));
    cart = cart.filter((item) => !(item.product_id === p.product_id && Number(item.pack_id) === Number(pack.id)));
    if (qty > 0) {
        cart.push({
            product_id: p.product_id,
            product: p.product,
            pack_id: Number(pack.id),
            pack_name: pack.name,
            factor_milli: Number(pack.factor_milli),
            base_price: Number(pack.price),
            base_list_price: Number(pack.price),
            price: fxUnit(pack.price),
            list_price: fxUnit(pack.price),
            quantity: qty,
            ledger_qty: ledgerText(p.ledger_qty),
            measure: false,
            unit: '',
            base_unit: p.base_unit || '',
            base_label: p.base_label || p.base_unit || '',
            packs: p.packs || []
        });
    }
    renderCart();
    renderGrid();
    if (openVariantGroup) renderVariantSheet();
}
function bumpPack(p, pack, delta) {
    if (!p || !pack) return false;
    const next = packQtyFor(p.product_id, pack.id) + delta;
    if (delta > 0 && !stockAllows(p, cartBaseMilli(p.product_id, false, pack.id) + next * Number(pack.factor_milli))) return false;
    hideOrderNotice();
    setPackQty(p, pack, next);
    return true;
}
function bumpPackIdx(idx, packId, delta) {
    const p = products[idx];
    if (!p) return;
    const pack = (p.packs || []).find(function (k) { return Number(k.id) === Number(packId); });
    bumpPack(p, pack, delta);
}
function packRowsHtml(p, idx) {
    if (!p.packs || !p.packs.length) return '';
    return p.packs.map(function (k) {
        const n = packQtyFor(p.product_id, k.id);
        return '<div class="ppack"><span class="ppack-name">' + escapeHtml(k.name + ' of ' + k.factor) + ' · ' + escapeHtml(fmtAmount(fxUnit(k.price))) + '</span>'
            + '<div class="step step-sm">'
            + '<button type="button" onclick="bumpPackIdx(' + idx + ',' + Number(k.id) + ',-1)" aria-label="One ' + escapeHtml(k.name) + ' less">−</button>'
            + '<button type="button" class="qtybtn" onclick="openPackQty(' + idx + ',' + Number(k.id) + ')" aria-label="Type number of ' + escapeHtml(plural(k.name)) + '">' + n + '</button>'
            + '<button type="button" onclick="bumpPackIdx(' + idx + ',' + Number(k.id) + ',1)" aria-label="One ' + escapeHtml(k.name) + ' more">+</button>'
            + '</div></div>';
    }).join('');
}
/** Typed quantity of packs: the quantity sheet in "pack" mode. */
function packProxy(p, pack) {
    return Object.assign({}, p, { __pack: pack, product: p.product, price: Number(pack.price), base_list_price: Number(pack.price), measure: false });
}
function openPackQty(idx, packId) {
    const p = products[idx];
    if (!p) return;
    const pack = (p.packs || []).find(function (k) { return Number(k.id) === Number(packId); });
    if (pack) openMeasure(packProxy(p, pack));
}
function ledgerText(value) {
    // Null until an opening balance exists: do not show Stock: 0 and do not block +.
    if (value == null || String(value).trim() === '') return null;
    return String(value).trim();
}
function stockMilli(value) {
    const text = String(value == null ? '' : value).trim();
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
    if (!match) return null;
    const frac = (match[3] || '').slice(0, 3).padEnd(3, '0');
    const milli = (parseInt(match[2], 10) * 1000) + parseInt(frac, 10);
    if (!Number.isFinite(milli)) return null;
    return match[1] === '-' ? -milli : milli;
}
function stockLimitMessage(name, requested, available) {
    const label = (name && String(name).trim()) ? String(name).trim() : 'product';
    return 'Insufficient stock for ' + label + '. Requested: ' + requested + '. Available: ' + available + '.';
}
function setQty(product, qty) {
    qty = Math.max(0, Number(qty) || 0);
    cart = cart.filter((item) => item.product_id !== product.product_id || item.pack_id);
    if (qty > 0) {
        cart.push({
            product_id: product.product_id,
            product: product.product,
            // A cart line passed back in carries base_list_price (its price may be the quoted one). Prices are converted
            // from the base currency into the selected currency (2026-10-20).
            base_price: isQuoted(product.product_id, qty) ? null : baseListOf(product),
            base_list_price: baseListOf(product),
            price: isQuoted(product.product_id, qty) ? quotedPrice(product.product_id, qty, 0) : fxUnit(baseListOf(product)),
            list_price: fxUnit(baseListOf(product)),
            quantity: qty,
            ledger_qty: ledgerText(product.ledger_qty),
            measure: !!product.measure,
            unit: product.unit || '',
            pack_id: 0,
            base_unit: product.base_unit || '',
            base_label: product.base_label || product.base_unit || '',
            whole: !!product.whole,
            packs: product.packs || []
        });
    }
    renderCart();
    renderGrid();
    if (openVariantGroup) renderVariantSheet();
}
/** Phase 20: quantity text — "1.25 kg" for items sold by measure. */
function qtyLabel(qty, p) {
    if (p && p.measure) {
        const n = Number(qty) || 0;
        return (n ? String(Number(n.toFixed(3))) : '0') + (p.unit ? ' ' + p.unit : '');
    }
    return String(qty);
}
function priceLabel(p) {
    if (p.__pack) return fmtAmount(fxUnit(p.__pack.price)) + ' / ' + p.__pack.name;
    return fmtAmount(fxUnit(p.price)) + (p.measure && p.unit ? ' / ' + p.unit : (p.base_unit ? ' / ' + p.base_unit : ''));
}
function stepHtml(p, idx) {
    const qty = qtyFor(p.product_id);
    return '<div class="step">'
        + '<button type="button" onclick="bump(' + idx + ',-1)" aria-label="Less">−</button>'
        + '<button type="button" class="qtybtn" onclick="openQtyIdx(' + idx + ')" aria-label="Type quantity" title="Type quantity">'
        + escapeHtml(p.measure ? (qty ? String(Number(Number(qty).toFixed(3))) : '0') : String(qty)) + '</button>'
        + '<button type="button" onclick="bump(' + idx + ',1)" aria-label="' + (p.measure ? 'Enter amount' : 'More') + '">+</button>'
        + '</div>';
}
/* ---- Variants (one card per product family; choose size / colour in a sheet) ---- */
function variantMembers(group) {
    const out = [];
    products.forEach(function (p, idx) { if (p.variant_group === group) out.push({ p: p, idx: idx }); });
    // Keep the order the variants were created in (S, M, L …), not A–Z.
    out.sort(function (a, b) { return (Number(a.p.product_id) || 0) - (Number(b.p.product_id) || 0); });
    return out;
}
function openVariants(group) {
    openVariantGroup = group;
    renderVariantSheet();
    openSheet('variantSheet');
}
function closeVariants() {
    openVariantGroup = '';
    document.getElementById('variantSheet').classList.remove('show');
}
function renderVariantSheet() {
    const members = variantMembers(openVariantGroup);
    if (!members.length) { closeVariants(); return; }
    const first = members[0].p;
    document.getElementById('variantTitle').textContent = first.variant_base || first.product;
    document.getElementById('variantList').innerHTML = members.map(function (m) {
        const p = m.p;
        const available = ledgerText(p.ledger_qty);
        return '<div class="vrow">'
            + '<div class="vrow-main"><div class="vrow-label">' + escapeHtml(p.variant_label || p.product) + '</div>'
            + '<div class="vrow-meta">' + escapeHtml(priceLabel(p)) + (available === null ? '' : ' · Stock: ' + escapeHtml(available)) + '</div></div>'
            + stepHtml(p, m.idx)
            + '</div>';
    }).join('');
}
/* ---- Type the quantity: amount for items sold by weight / volume / length, a whole number for the rest ---- */
function openMeasure(p) {
    measureProduct = p;
    const current = p.__pack ? packQtyFor(p.product_id, p.__pack.id) : qtyFor(p.product_id);
    document.getElementById('measureTitle').textContent = p.product + (p.__pack ? ' · ' + p.__pack.name + ' of ' + p.__pack.factor : '');
    document.getElementById('measureUnit').textContent = p.__pack ? plural(p.__pack.name) : (p.measure ? (p.unit || '') : (p.base_unit ? plural(p.base_unit) : 'pcs'));
    document.getElementById('measurePrice').textContent = priceLabel(p);
    const input = document.getElementById('measureQty');
    input.step = p.measure ? '0.001' : '1';
    input.min = '0';
    input.setAttribute('inputmode', p.measure ? 'decimal' : 'numeric');
    input.value = current > 0 ? String(Number(current.toFixed(3))) : '';
    document.getElementById('measureOk').textContent = current > 0 ? 'Set quantity' : 'Add to cart';
    document.getElementById('measureHint').textContent = current > 0 ? 'Enter 0 to remove the item.' : '';
    document.getElementById('measureError').hidden = true;
    updateMeasureTotal();
    openSheet('measureSheet');
    setTimeout(function () { try { input.focus(); input.select(); } catch (e) {} }, 50);
}
/** Tap the number between − and + on a product. */
function openQtyIdx(idx) {
    const p = products[idx];
    if (p && p.product_id) openMeasure(p);
}
/** Tap the quantity on an order line (the product may not be in the current grid). */
function openQtyLine(productId, packId) {
    const fromGrid = products.find(function (x) { return x.product_id === productId; });
    const line = cart.find(function (x) { return x.product_id === productId && Number(x.pack_id || 0) === Number(packId || 0); });
    if (packId) {
        const src = fromGrid || line;
        const pack = src && (src.packs || []).find(function (k) { return Number(k.id) === Number(packId); });
        if (src && pack) { openMeasure(packProxy(src, pack)); }
        return;
    }
    if (fromGrid || line) openMeasure(fromGrid || line);
}
function updateMeasureTotal() {
    const p = measureProduct;
    const v = Number(document.getElementById('measureQty').value);
    const unit = p ? (p.base_list_price != null ? fxUnit(p.base_list_price) : fxUnit(p.price)) : 0;
    document.getElementById('measureTotal').textContent = fmtAmount((p && v > 0) ? Math.round(v * unit * 100) / 100 : 0);
}
function confirmMeasure() {
    const p = measureProduct;
    const raw = String(document.getElementById('measureQty').value || '').trim();
    const err = document.getElementById('measureError');
    if (!p) return;
    const inCart = qtyFor(p.product_id) > 0;
    if (p.measure) {
        if (!/^\d+(\.\d{1,3})?$/.test(raw) || (Number(raw) <= 0 && !inCart)) {
            err.textContent = 'Enter an amount greater than 0 (up to 3 decimals), e.g. 1.25';
            err.hidden = false;
            return;
        }
    } else if (!/^\d{1,6}$/.test(raw) || (Number(raw) <= 0 && !inCart)) {
        err.textContent = 'Enter a whole number of items, e.g. 12';
        err.hidden = false;
        return;
    }
    const qty = Number(Number(raw).toFixed(3));
    if (p.__pack) {
        const total = cartBaseMilli(p.product_id, false, p.__pack.id) + Math.round(qty) * Number(p.__pack.factor_milli);
        if (!stockAllows(p, total, err)) return;
        measureProduct = null;
        document.getElementById('measureSheet').classList.remove('show');
        hideOrderNotice();
        setPackQty(p, p.__pack, qty);
        if (openVariantGroup) openSheet('variantSheet');
        return;
    }
    const available = ledgerText(p.ledger_qty);
    const availableMilli = available === null ? null : stockMilli(available);
    const withPacks = (stockMilli(raw) || 0) + cartBaseMilli(p.product_id, true);
    if (!ALLOW_NEGATIVE_STOCK && availableMilli !== null && withPacks > availableMilli) {
        if (p.base_unit || cartBaseMilli(p.product_id, true) > 0) { stockAllows(p, withPacks, err); return; }
        err.textContent = stockLimitMessage(p.product, raw + (p.measure && p.unit ? ' ' + p.unit : ''), available);
        err.hidden = false;
        return;
    }
    measureProduct = null;
    document.getElementById('measureSheet').classList.remove('show');
    hideOrderNotice();
    setQty(p, qty);
    if (openVariantGroup) openSheet('variantSheet');
}
function cancelMeasure() {
    measureProduct = null;
    document.getElementById('measureSheet').classList.remove('show');
    if (openVariantGroup) openSheet('variantSheet');
}
function loadCatalog() {
    const searchRaw = document.getElementById('search').value.trim();
    const search = encodeURIComponent(searchRaw);
    const cat = encodeURIComponent(searchRaw ? '' : activeCategory);
    const token = ++catalogToken;
    const url = 'fetch_pos_catalog.php?search=' + search + '&category=' + cat;
    const apply = function (data) {
        if (token !== catalogToken || !data || !data.success) return;
        products = data.products || [];
        catColorMap = data.category_colors || {};
        if (!searchRaw && !activeCategory) categories = data.categories || [];
        renderCats();
        renderGrid();
    };
    if (window.jQuery) {
        $.getJSON(url, apply);
        return;
    }
    fetch(url).then(function (res) { return res.json(); }).then(apply).catch(function () {});
}
function setCategory(name) {
    activeCategory = (activeCategory === name) ? '' : name;
    loadCatalog();
}
function showAllItems() {
    activeCategory = '';
    const box = document.getElementById('search');
    if (box) box.value = '';
    loadCatalog();
}
function renderCats() {
    const searching = (document.getElementById('search').value || '').trim() !== '';
    // "All Items" first: every product, no button group filter.
    const allOn = !searching && activeCategory === '' ? ' on' : '';
    const html = '<button class="cat cat-all' + allOn + '" style="background:#334155" onclick="showAllItems()">All Items</button>' +
        (categories || []).map((name) => {
        const on = !searching && activeCategory === name ? ' on' : '';
        return '<button class="cat' + on + '" style="background:' + catColor(name) + '" onclick="setCategory(' + escapeHtml(JSON.stringify(name)) + ')">' +
            escapeHtml(name) + '</button>';
    }).join('');
    document.getElementById('cats').innerHTML = html;
}
function renderGrid() {
    const searching = (document.getElementById('search').value || '').trim() !== '';
    const seenGroups = {};
    const html = products.map((p, idx) => {
        // Variants: one card per family when browsing; individual items when searching / scanning.
        if (p.variant_group && !searching) {
            if (seenGroups[p.variant_group]) return '';
            seenGroups[p.variant_group] = true;
            const members = variantMembers(p.variant_group);
            const prices = members.map(function (m) { return fxUnit(m.p.price); });
            const lo = Math.min.apply(null, prices), hi = Math.max.apply(null, prices);
            const inCart = members.reduce(function (sum, m) { return sum + qtyFor(m.p.product_id); }, 0);
            return '<div class="pcard pcard-variant">' +
                '<div><div class="pname">' + escapeHtml(p.variant_base || p.product) + '</div>' +
                '<div class="pstock">' + members.length + ' options' + (inCart ? ' · ' + inCart + ' in cart' : '') + '</div></div>' +
                '<div class="pfoot"><div class="pprice">' + escapeHtml(fmtAmount(lo)) + (hi !== lo ? '–' + escapeHtml(fmtAmount(hi)) : '') + '</div>' +
                '<button type="button" class="vchoose" onclick="openVariants(' + escapeHtml(JSON.stringify(p.variant_group)) + ')">Choose</button>' +
                '</div></div>';
        }
        const available = ledgerText(p.ledger_qty);
        const stockHtml = available === null ? '' : '<div class="pstock">Stock: ' + escapeHtml(p.stock_text || (available + (p.measure && p.unit ? ' ' + p.unit : ''))) + '</div>';
        return '<div class="pcard' + (p.packs && p.packs.length ? ' pcard-packs' : '') + '">' +
            '<div><div class="pname">' + escapeHtml(p.display_name || p.product) + '</div>' +
            stockHtml + '</div>' +
            '<div class="pfoot"><div class="pprice">' + escapeHtml(priceLabel(p)) + '</div>' +
            stepHtml(p, idx) +
            '</div>' + packRowsHtml(p, idx) + '</div>';
    }).join('');
    document.getElementById('grid').innerHTML = html || '<p class="hint">No products found.</p>';
}
function bump(idx, delta) {
    const p = products[idx];
    if (!p) return;
    bumpProduct(p, delta);
}
function bumpProduct(p, delta) {
    if (!p || !p.product_id) return;
    if (p.measure) {
        // Sold by weight / volume / length: + asks for the amount, − removes the line.
        if (delta > 0) { openMeasure(p); } else { hideOrderNotice(); setQty(p, 0); }
        return true;
    }
    const next = qtyFor(p.product_id) + delta;
    if (delta > 0) {
        const available = ledgerText(p.ledger_qty);
        const packMilli = cartBaseMilli(p.product_id, true);
        const requestedMilli = stockMilli(String(next));
        const availableMilli = available === null ? null : stockMilli(available);
        if (!ALLOW_NEGATIVE_STOCK && availableMilli !== null && requestedMilli !== null && requestedMilli + packMilli > availableMilli) {
            if (packMilli > 0 || p.base_unit) { stockAllows(p, requestedMilli + packMilli); return false; }
            showOrderNotice(stockLimitMessage(p.product || p.product_id, next, available));
            return false;
        }
    }
    hideOrderNotice();
    setQty(p, next);
    return true;
}
function focusProductSearch() {
    const el = document.getElementById('search');
    if (el && !saleBusy) {
        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
    }
}
/**
 * Enter / keyboard-wedge: exact barcode then product_id → +1 cart.
 * Typing alone only filters the grid via loadCatalog() — no auto-add.
 */
function onProductSearchKey(event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    event.stopPropagation();
    const input = document.getElementById('search');
    if (!input || barcodeBusy || saleBusy) return;
    const code = (input.value || '').trim();
    if (!code) {
        focusProductSearch();
        return;
    }
    resolvePosCodeAndAdd(code, {
        clearInput: input,
        refocus: true,
        onMiss: function (data) {
            // Keep filtered grid; soft miss for unknown (name search still visible).
            if (data && data.code && data.code !== 'unknown') {
                showOrderNotice(data.error || 'No matching product.');
            } else {
                showOrderNotice((data && data.error) ? data.error : 'No exact barcode or product ID match.');
            }
        }
    });
}
/**
 * Shared POS code resolve (ajax_pos_barcode.php) → +1 via existing cart rules.
 * Single search/scan field: Enter resolves exact barcode / product_id.
 */
function resolvePosCodeAndAdd(code, opts) {
    opts = opts || {};
    const paySheet = document.getElementById('paySheet');
    if (paySheet && paySheet.classList.contains('show')) {
        showOrderNotice('Close payment before scanning another item.');
        if (opts.refocus) focusProductSearch();
        return;
    }
    if (barcodeBusy || saleBusy) return;
    barcodeBusy = true;
    const url = 'ajax_pos_barcode.php?code=' + encodeURIComponent(code);
    const finishFocus = function () {
        if (opts.refocus) focusProductSearch();
    };
    const done = function (data) {
        barcodeBusy = false;
        if (!data || !data.success || !data.product) {
            if (typeof opts.onMiss === 'function') opts.onMiss(data || {});
            finishFocus();
            return;
        }
        if (opts.clearInput) {
            opts.clearInput.value = '';
            loadCatalog();
        }
        const p = data.product;
        // Prefer live catalogue row when present (same product_id) for consistent display.
        let catalog = null;
        for (let i = 0; i < products.length; i++) {
            if (products[i].product_id === p.product_id) {
                catalog = products[i];
                break;
            }
        }
        const target = catalog || {
            product_id: p.product_id,
            product: p.product,
            price: Number(p.price) || 0,
            ledger_qty: p.ledger_qty
        };
        if (catalog && p.ledger_qty != null) {
            catalog.ledger_qty = p.ledger_qty;
        }
        if (data.pack) {
            // A pack's own barcode: one pack of the product.
            if (!target.packs) target.packs = [data.pack];
            const pk = (target.packs || []).find(function (k) { return Number(k.id) === Number(data.pack.id); }) || data.pack;
            bumpPack(target, pk, 1);
            finishFocus();
            return;
        }
        bumpProduct(target, 1);
        hideOrderNotice();
        finishFocus();
    };
    const fail = function () {
        barcodeBusy = false;
        showOrderNotice('Barcode lookup failed.');
        finishFocus();
    };
    if (window.jQuery) {
        $.getJSON(url).done(done).fail(function (xhr) {
            let data = null;
            try { data = xhr.responseJSON || JSON.parse(xhr.responseText || ''); } catch (e) {}
            if (data && data.error) {
                done(data);
                return;
            }
            fail();
        });
        return;
    }
    fetch(url).then(function (res) { return res.json().then(function (data) { done(data); }); }).catch(fail);
}
function cartLines() {
    return cart.filter((item) => item.quantity > 0);
}
function cartTotalValue() {
    return cartLines().reduce((sum, item) => sum + item.quantity * item.price, 0);
}
function moneyCents(raw) {
    const text = String(raw == null ? '' : raw).trim();
    const match = /^(\d+)(?:\.(\d+))?$/.exec(text);
    if (!match || match[1].length > 10) return null;
    const frac = match[2] || '';
    const centsText = (frac + '000').slice(0, 3);
    let cents = parseInt(centsText.slice(0, 2), 10);
    if (parseInt(centsText.slice(2, 3), 10) >= 5) cents += 1;
    const total = (parseInt(match[1], 10) * 100) + cents;
    if (total < 0 || total > 999999999999) return null;
    return total;
}
function cartTotalCents() {
    // After the customer confirmed the ticket, the server total (incl. any points discount) is what is paid.
    if (ticketConfirmed()) return ticket.data.total_cents;
    return moneyCents(cartTotalValue().toFixed(2)) || 0;
}
// 2026-10-24: customer ticket review. The sale is only submitted with the fingerprint of a ticket the customer confirmed;
// any change to the lines, currency, customer, quote or points afterwards needs a new confirmation.
let ticket = null;          // { sig, data, confirmed }
let ticketLoading = false;
let ticketToken = 0;
let loyaltyRedeem = 0;
function reviewSig() {
    return JSON.stringify([posCur, (document.getElementById('customerMasterId').value || ''), posQuote ? posQuote.id : 0, loyaltyRedeem,
        cartLines().map(function (l) { return [l.product_id, Number(l.pack_id || 0), Number(l.quantity)]; })]);
}
function ticketConfirmed() {
    return !!(ticket && ticket.confirmed && ticket.data && ticket.sig === reviewSig());
}
function openTicket(forPayment) {
    if (saleBusy) return;
    hideOrderNotice();
    if (!cartLines().length) { showOrderNotice('No products in the cart.'); return; }
    if (typeof closeUserChipMenu === 'function') closeUserChipMenu();
    closeSheets();
    const sheet = document.getElementById('ticketSheet');
    sheet.dataset.forPayment = forPayment ? '1' : '';
    if (typeof sheet.showModal === 'function') {
        if (!sheet.open) sheet.showModal();
    } else {
        sheet.classList.add('show');
    }
    loadTicket();
}
function closeTicket() {
    const sheet = document.getElementById('ticketSheet');
    if (!sheet) return;
    if (typeof sheet.close === 'function' && sheet.open) sheet.close();
    else sheet.classList.remove('show');
}
(function bindTicketBackdrop() {
    const sheet = document.getElementById('ticketSheet');
    if (!sheet) return;
    sheet.addEventListener('click', function (ev) {
        const r = sheet.getBoundingClientRect();
        if (ev.clientX < r.left || ev.clientX > r.right || ev.clientY < r.top || ev.clientY > r.bottom) {
            closeTicket();
        }
    });
}());
function ticketPost() {
    const data = {
        cart: JSON.stringify(cartLines()),
        currency: posCur,
        customer_master_id: (document.getElementById('customerMasterId').value || '').trim(),
        loyalty_redeem_points: loyaltyRedeem > 0 ? String(loyaltyRedeem) : ''
    };
    if (posQuote) data.quotation_id = posQuote.id;
    return data;
}
function loadTicket(keepView) {
    const token = ++ticketToken;
    const sig = reviewSig();
    ticketLoading = true;
    document.getElementById('ticketConfirm').disabled = true;
    document.getElementById('ticketError').hidden = true;
    const bodyEl = document.getElementById('ticketBody');
    if (keepView && bodyEl.querySelector('table')) {
        // 2026-10-31: after a quantity change keep the lines on screen (no flicker) while the server re-prices.
        bodyEl.classList.add('is-updating');
        bodyEl.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    } else {
        bodyEl.classList.remove('is-updating');
        bodyEl.innerHTML = '<p class="hint">Loading…</p>';
        const totalsEl = document.getElementById('ticketTotals');
        if (totalsEl) totalsEl.innerHTML = '';
        const headTotal = document.getElementById('ticketHeadTotal');
        if (headTotal) headTotal.textContent = '';
        const lpLoad = document.getElementById('ticketLoyalty');
        if (lpLoad) lpLoad.hidden = true;
    }
    $.ajax({ url: 'ajax_pos_ticket_review.php', type: 'POST', data: ticketPost(), dataType: 'json' })
        .done(function (d) {
            if (token !== ticketToken) return;
            ticketLoading = false;
            document.getElementById('ticketBody').classList.remove('is-updating');
            if (!d || !d.success) { ticket = null; showTicketError((d && d.error) || 'The ticket could not be loaded.'); return; }
            ticket = { sig: sig, data: d, confirmed: false };
            renderTicket();
        })
        .fail(function (xhr) {
            if (token !== ticketToken) return;
            ticketLoading = false;
            document.getElementById('ticketBody').classList.remove('is-updating');
            ticket = null;
            const d = xhr && xhr.responseJSON;
            showTicketError((d && d.error) || 'The ticket could not be loaded. Check the connection and try again.');
        });
}
function showTicketError(msg) {
    const el = document.getElementById('ticketError');
    el.textContent = msg;
    el.hidden = false;
    document.getElementById('ticketBody').innerHTML = '';
    const totalsEl = document.getElementById('ticketTotals');
    if (totalsEl) totalsEl.innerHTML = '';
    document.getElementById('ticketConfirm').disabled = true;
}
function renderTicket() {
    const d = ticket.data;
    const cur = d.currency;
    const money = function (v) { return escapeHtml(fmtAmount(Number(v), cur)); };
    document.getElementById('ticketMeta').textContent = d.customer + ' · ' + psBranchLabel(d.branch) + ' · ' + cur
        + (posQuote ? ' · Quotation ' + posQuote.number : '');
    const headTotal = document.getElementById('ticketHeadTotal');
    if (headTotal) headTotal.textContent = fmtAmount(Number(d.total), cur);
    let html = '<table class="ticket-lines"><thead><tr>'
        + '<th>Item</th><th class="num">Qty</th><th class="num">Unit price</th>'
        + '<th class="num">Tax</th><th class="num">Line total</th><th><span class="sr-only">Remove</span></th></tr></thead><tbody>';
    d.lines.forEach(function (l) {
        const key = escapeHtml(JSON.stringify([String(l.product_id || ''), Number(l.pack_id || 0)]));
        const cartLine = ticketCartLine(l);
        const qtyText = escapeHtml(l.qty_text ? String(l.qty_text) : String(Number(l.quantity)));
        // Whole units and packs get − / +; an amount sold by measure (kg, litres) is changed from the cart.
        const qtyCell = cartLine && !cartLine.measure
            ? '<div class="ticket-step">'
                + '<button type="button" data-ticket-step="-1" data-ticket-key="' + key + '" aria-label="One less ' + escapeHtml(l.product) + '">−</button>'
                + '<span class="ticket-n">' + escapeHtml(String(cartLine.quantity)) + '</span>'
                + '<button type="button" data-ticket-step="1" data-ticket-key="' + key + '" aria-label="One more ' + escapeHtml(l.product) + '">+</button>'
                + '</div>' + (l.qty_text ? '<div class="hint">' + qtyText + '</div>' : '')
            : qtyText;
        const delCell = cartLine
            ? '<button type="button" data-ticket-remove data-ticket-key="' + key + '" aria-label="Remove ' + escapeHtml(l.product) + '" title="Remove">×</button>'
            : '';
        const taxCell = l.tax !== null
            ? money(l.tax) + (l.tax_label ? '<div class="hint">' + escapeHtml(l.tax_label) + '</div>' : '')
            : '—';
        html += '<tr><td>' + escapeHtml(l.product)
            + (Number(l.discount) > 0 ? '<div class="hint">Discount −' + money(l.discount) + '</div>' : '')
            + '</td><td class="num ticket-qty">' + qtyCell + '</td>'
            + '<td class="num">' + money(l.unit_price) + '</td>'
            + '<td class="num ticket-tax">' + taxCell + '</td>'
            + '<td class="num ticket-line-total">' + money(l.line_total) + '</td>'
            + '<td class="ticket-del">' + delCell + '</td></tr>';
    });
    html += '</tbody></table>';
    document.getElementById('ticketBody').innerHTML = html;
    let totals = '<div class="ticket-totals">'
        + '<div class="ticket-tot"><span>Subtotal</span><span>' + money(d.subtotal) + '</span></div>'
        + '<div class="ticket-tot"><span>Discounts' + (d.loyalty && d.loyalty.used ? ' (' + d.loyalty.used + ' points)' : '') + '</span><span>'
        + (Number(d.discount) > 0 ? '−' + money(d.discount) : money(0)) + '</span></div>';
    if (d.tax !== null) {
        totals += '<div class="ticket-tot"><span>Tax' + (d.tax_mode === 'INCLUSIVE' ? ' (included)' : '') + '</span><span>' + money(d.tax) + '</span></div>';
    }
    totals += '<div class="ticket-tot ticket-tot-pay"><span>Total to pay</span><span>' + money(d.total) + '</span></div></div>';
    const totalsEl = document.getElementById('ticketTotals');
    if (totalsEl) totalsEl.innerHTML = totals;
    const lp = document.getElementById('ticketLoyalty');
    const hasCustomer = !!(document.getElementById('customerMasterId').value || '').trim();
    lp.hidden = !(d.loyalty && d.loyalty.enabled && hasCustomer);
    if (!lp.hidden) {
        document.getElementById('ticketLoyaltyInfo').textContent = 'Balance: ' + d.loyalty.balance + ' points'
            + ' · minimum to use: ' + d.loyalty.min_redeem
            + (d.loyalty.earn ? ' · this sale earns about ' + d.loyalty.earn + ' points' : '');
        document.getElementById('ticketRedeem').value = loyaltyRedeem > 0 ? String(loyaltyRedeem) : '';
    }
    if (d.loyalty && d.loyalty.error) {
        loyaltyRedeem = 0;
        ticket.sig = reviewSig();
        const el = document.getElementById('ticketError');
        el.textContent = d.loyalty.error;
        el.hidden = false;
    }
    document.getElementById('ticketConfirm').disabled = false;
}
/* 2026-10-31: change the order from the ticket. Each change updates the cart, then the ticket is re-priced by the
   server (taxes, quotes, loyalty) and has to be confirmed again. */
function ticketCartLine(l) {
    const pid = String(l.product_id || '');
    const packId = Number(l.pack_id || 0);
    if (!pid) return null;
    return cart.find(function (x) { return x.product_id === pid && Number(x.pack_id || 0) === packId && x.quantity > 0; }) || null;
}
function ticketKeyLine(btn) {
    try {
        const k = JSON.parse(btn.getAttribute('data-ticket-key'));
        return ticketCartLine({ product_id: k[0], pack_id: k[1] });
    } catch (e) { return null; }
}
function ticketStockError(msg) {
    const el = document.getElementById('ticketError');
    el.textContent = msg;
    el.hidden = false;
}
function afterTicketEdit() {
    if (!cartLines().length) {
        closeTicket();
        resetTicket();
        showOrderNotice('All items were removed from the ticket. The cart is empty.');
        return;
    }
    loadTicket(true);
}
/** setQty / setPackQty re-add a changed line at the end of the cart; put it back where it was so the ticket order holds. */
function keepCartPosition(line, change) {
    const at = cart.indexOf(line);
    const pid = line.product_id, packId = Number(line.pack_id || 0);
    change();
    const now = cart.findIndex(function (x) { return x.product_id === pid && Number(x.pack_id || 0) === packId; });
    if (at >= 0 && now >= 0 && now !== at) {
        const moved = cart.splice(now, 1)[0];
        cart.splice(Math.min(at, cart.length), 0, moved);
        renderCart();
    }
}
function ticketStep(line, delta) {
    if (!line || ticketLoading || saleBusy) return;
    document.getElementById('ticketError').hidden = true;
    const errEl = document.getElementById('ticketError');
    if (line.pack_id) {
        const pack = (line.packs || []).find(function (k) { return Number(k.id) === Number(line.pack_id); })
            || { id: line.pack_id, name: line.pack_name, factor_milli: line.factor_milli, price: line.base_price };
        const next = Math.max(0, Math.round(Number(line.quantity)) + delta);
        if (delta > 0 && !stockAllows(line, cartBaseMilli(line.product_id, false, pack.id) + next * Number(pack.factor_milli), errEl)) return;
        keepCartPosition(line, function () { setPackQty(line, pack, next); });
    } else {
        const next = Math.max(0, Number(line.quantity) + delta);
        if (delta > 0 && !stockAllows(line, cartBaseMilli(line.product_id, true) + (stockMilli(String(next)) || 0), errEl)) return;
        keepCartPosition(line, function () { setQty(line, next); });
    }
    afterTicketEdit();
}
function ticketRemove(line) {
    if (!line || ticketLoading || saleBusy) return;
    if (line.pack_id) {
        setPackQty(line, { id: line.pack_id }, 0);
    } else {
        setQty(line, 0);
    }
    afterTicketEdit();
}
(function bindTicketEdits() {
    const body = document.getElementById('ticketBody');
    if (!body) return;
    body.addEventListener('click', function (ev) {
        const step = ev.target.closest('[data-ticket-step]');
        if (step) { ticketStep(ticketKeyLine(step), Number(step.getAttribute('data-ticket-step'))); return; }
        const del = ev.target.closest('[data-ticket-remove]');
        if (del) ticketRemove(ticketKeyLine(del));
    });
}());
function applyTicketRedeem() {
    const raw = (document.getElementById('ticketRedeem').value || '').trim();
    if (raw !== '' && !/^\d{1,9}$/.test(raw)) { showTicketError('Enter the number of points as a whole number.'); return; }
    loyaltyRedeem = raw === '' ? 0 : parseInt(raw, 10);
    loadTicket();
}
function confirmTicket() {
    if (!ticket || ticketLoading || ticket.sig !== reviewSig()) { loadTicket(); return; }
    ticket.confirmed = true;
    closeTicket();
    openPaymentScreen();
}
function resetTicket() {
    ticket = null;
    loyaltyRedeem = 0;
}
function formatMoney(cents) {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    return fmtAmount(cents / 100);
}
// ---------------------------------------------------------------- 2026-11-05 split payment / split currency
// The first payment is the row above (method, amount, reference) in the sale currency. "Add another payment" adds rows,
// each with its own method and currency. A payment in another currency counts at the company rate (cross rate via the
// base, exact BigInt half-up like the server); the server re-checks everything (psEvaluateTenders) and is the authority.
let extraTenders = [];
const TENDER_METHODS = <?php echo json_encode(array_diff_key(psPaymentMethods(), ['CREDIT' => 1]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function isSplit() { return extraTenders.length > 0; }
function sellableCodes() {
    return Object.keys(POS_FX.currencies).filter(function (k) { return k === POS_FX.base || POS_FX.currencies[k].ok; });
}
/** cents in `from` -> cents in `to` (1 BASE = num/den CODE; base has 1/1). null when a rate is missing. */
function fxConvertCents(cents, from, to) {
    if (from === to) return cents;
    const a = POS_FX.currencies[from], b = POS_FX.currencies[to];
    if (!a || !b || (from !== POS_FX.base && !a.ok) || (to !== POS_FX.base && !b.ok)) return null;
    const an = BigInt(from === POS_FX.base ? 1 : a.num), ad = BigInt(from === POS_FX.base ? 1 : a.den);
    const bn = BigInt(to === POS_FX.base ? 1 : b.num), bd = BigInt(to === POS_FX.base ? 1 : b.den);
    const num = ad * bn, den = an * bd;              // from -> base -> to
    let v = (BigInt(Math.abs(cents)) * num * 2n + den) / (2n * den);
    const dec = b.decimals == null ? 2 : b.decimals;
    const step = dec >= 2 ? 1n : (dec === 1 ? 10n : 100n);
    if (step > 1n) v = ((v + step / 2n) / step) * step;
    return (cents < 0 ? -1 : 1) * Number(v);
}
function allTenders() {
    const first = {
        method: (document.getElementById('paymentMethod').value || '').trim(),
        currency: posCur,
        amount: (document.getElementById('amountPaid').value || '').trim(),
        reference: document.getElementById('paymentReference').value || '',
    };
    return [first].concat(extraTenders.map(function (t) { return Object.assign({}, t); }));
}
/** Sale-currency figures for the split: paid, non-cash, still due, change (sale cents) and any problem. */
function splitFigures() {
    const total = cartTotalCents();
    let paid = 0, nonCash = 0, problem = '';
    allTenders().forEach(function (t, i) {
        const c = moneyCents(t.amount);
        if (c === null || c <= 0) { if (!problem) problem = 'Enter the amount of payment ' + (i + 1) + '.'; return; }
        const s = fxConvertCents(c, t.currency || posCur, posCur);
        if (s === null) { if (!problem) problem = 'There is no exchange rate for ' + t.currency + '.'; return; }
        paid += s;
        if (t.method !== 'CASH') nonCash += s;
    });
    return { total: total, paid: paid, nonCash: nonCash, due: Math.max(0, total - paid), change: Math.max(0, paid - total), problem: problem };
}
function addTender() {
    if (saleBusy) return;
    const f = splitFigures();
    const codes = sellableCodes();
    const other = codes.find(function (k) { return k !== posCur; });
    const cur = other || posCur;
    const due = f.due > 0 ? fxConvertCents(f.due, posCur, cur) : null;
    // auto: the amount follows what is still due (as the first payment changes) until the cashier types in it.
    extraTenders.push({ method: other ? 'CASH' : 'ECOCASH', currency: cur, amount: due ? centsText(due, cur) : '', reference: '', auto: true });
    serverPayError = '';
    renderTenders();
    syncPayForm();
    const rows = document.querySelectorAll('#splitRows [data-tf="amount"]');
    if (rows.length) rows[rows.length - 1].focus();
}
function removeTender(i) {
    if (saleBusy) return;
    extraTenders.splice(i, 1);
    renderTenders();
    syncPayForm();
}
function centsText(cents, code) {
    const c = POS_FX.currencies[code];
    const dec = c && c.decimals != null ? c.decimals : 2;
    return (cents / 100).toFixed(dec);
}
/** Keep auto rows equal to what is still due once every other payment is counted, in their own currency. */
function refreshAutoTenders() {
    const total = cartTotalCents();
    extraTenders.forEach(function (t, i) {
        if (!t.auto) return;
        let others = 0;
        allTenders().forEach(function (o, j) {
            if (j === i + 1) return;
            const c = moneyCents(o.amount);
            const s = c ? fxConvertCents(c, o.currency || posCur, posCur) : 0;
            others += s || 0;
        });
        const dueSale = total - others;
        const dueHere = dueSale > 0 ? fxConvertCents(dueSale, posCur, t.currency) : 0;
        const text = dueHere && dueHere > 0 ? centsText(dueHere, t.currency) : '';
        if (text !== t.amount) {
            t.amount = text;
            const el = document.querySelector('#splitRows [data-ti="' + i + '"][data-tf="amount"]');
            if (el && document.activeElement !== el) el.value = text;
        }
    });
}
function onTenderField(el) {
    const i = Number(el.getAttribute('data-ti'));
    const f = el.getAttribute('data-tf');
    const t = extraTenders[i];
    if (!t) return;
    serverPayError = '';
    if (f === 'currency') {
        // Same money in the new currency: e.g. ZWG 60 at 1 USD = 30 ZWG becomes USD 2.00.
        const before = moneyCents(t.amount);
        const next = el.value;
        if (before && before > 0) {
            const conv = fxConvertCents(before, t.currency, next);
            t.amount = conv === null ? '' : centsText(conv, next);
        }
        t.currency = next;
        renderTenders();
    } else {
        t[f] = el.value;
        if (f === 'amount') t.auto = false;
        if (f === 'method') renderTenders();
    }
    syncPayForm();
}
function renderTenders() {
    const wrap = document.getElementById('splitRows');
    const codes = sellableCodes();
    wrap.innerHTML = extraTenders.map(function (t, i) {
        const methods = Object.keys(TENDER_METHODS).map(function (k) {
            return '<option value="' + escapeHtml(k) + '"' + (k === t.method ? ' selected' : '') + '>' + escapeHtml(TENDER_METHODS[k]) + '</option>';
        }).join('');
        const curs = codes.map(function (k) {
            return '<option value="' + escapeHtml(k) + '"' + (k === t.currency ? ' selected' : '') + '>' + escapeHtml(k) + '</option>';
        }).join('');
        return '<div class="split-row">'
            + '<div class="kicker">Payment ' + (i + 2) + '</div>'
            + '<div class="split-grid">'
            + '<select class="field" data-ti="' + i + '" data-tf="method" aria-label="Method of payment ' + (i + 2) + '" onchange="onTenderField(this)">' + methods + '</select>'
            + (codes.length > 1 ? '<select class="field" data-ti="' + i + '" data-tf="currency" aria-label="Currency of payment ' + (i + 2) + '" onchange="onTenderField(this)">' + curs + '</select>' : '')
            + '<input class="field" data-ti="' + i + '" data-tf="amount" type="text" inputmode="decimal" autocomplete="off" aria-label="Amount of payment ' + (i + 2) + '" value="' + escapeHtml(t.amount) + '" oninput="onTenderField(this)">'
            + '<button type="button" class="btn btn-gray split-remove" aria-label="Remove payment ' + (i + 2) + '" onclick="removeTender(' + i + ')">✕</button>'
            + '</div>'
            + (t.method !== 'CASH' ? '<input class="field" data-ti="' + i + '" data-tf="reference" type="text" maxlength="120" placeholder="Reference (optional)" aria-label="Reference of payment ' + (i + 2) + '" value="' + escapeHtml(t.reference) + '" oninput="onTenderField(this)">' : '')
            + '</div>';
    }).join('');
    wrap.querySelectorAll('select, input, button').forEach(function (el) { el.disabled = saleBusy; });
    // 2026-11-05: modern dropdowns (ps-theme.js); drop listbox panels left by rows that were just re-drawn.
    document.querySelectorAll('.ps-select-panel').forEach(function (p) {
        if (!document.querySelector('[aria-controls="' + p.id + '"]')) p.remove();
    });
    if (typeof window.psEnhanceSelect === 'function') wrap.querySelectorAll('select').forEach(window.psEnhanceSelect);
}
function syncChangeCurrency(changeSale) {
    const wrap = document.getElementById('changeCurWrap');
    const sel = document.getElementById('changeCurrency');
    const codes = sellableCodes();
    const keep = sel.value || posCur;
    if (sel.options.length !== codes.length) {
        sel.innerHTML = codes.map(function (k) { return '<option value="' + escapeHtml(k) + '">' + escapeHtml(k) + '</option>'; }).join('');
    }
    const want = codes.indexOf(keep) >= 0 ? keep : posCur;
    if (sel.value !== want) {
        sel.value = want;
        sel.dispatchEvent(new Event('change')); // keeps the modern dropdown's label in step
    }
    wrap.hidden = !(changeSale > 0 && codes.length > 1);
    return wrap.hidden ? posCur : sel.value;
}

function payIssues() {
    const lines = cartLines();
    const totalCents = cartTotalCents();
    const method = (document.getElementById('paymentMethod').value || '').trim();
    if (lines.length && isSplit()) {
        if (method === 'CREDIT') return { ready: false, message: 'Credit (account) cannot be combined with other payments.' };
        const f = splitFigures();
        if (f.problem) return { ready: false, message: f.problem };
        if (f.nonCash > f.total) return { ready: false, message: 'Card, EcoCash and other non-cash payments cannot be more than the total. Only cash gets change.' };
        if (f.paid < f.total) return { ready: false, message: 'Still due: ' + formatMoney(f.total - f.paid) + '.' };
        return { ready: true, message: '' };
    }
    const paidRaw = (document.getElementById('amountPaid').value || '').trim();
    const known = ['CASH', 'CARD', 'ECOCASH', 'BANK_TRANSFER', 'CREDIT', 'OTHER'];
    if (!lines.length) return { ready: false, message: '' };
    if (!method || known.indexOf(method) === -1) {
        return { ready: false, message: 'Please select a payment method.' };
    }
    if (method === 'CREDIT') {
        if (!selectedCustomer || !selectedCustomer.id) {
            return { ready: false, message: 'Credit sales require an active registered customer.' };
        }
        if (!customerAccountInfo || !customerAccountInfo.has_account) {
            return { ready: false, message: 'This customer has no account. Create an account before charging to credit.' };
        }
        if (!customerAccountInfo.eligible) {
            if ((customerAccountInfo.credit_status || '') === 'ON_HOLD') {
                return { ready: false, message: 'This account is on credit hold. New credit sales are not allowed until the hold is released.' };
            }
            return { ready: false, message: 'This account is not eligible for credit sales.' };
        }
        if (totalCents <= 0) {
            return { ready: false, message: 'Credit sales require a sale total greater than zero.' };
        }
        return { ready: true, message: '' };
    }
    if (paidRaw === '' || moneyCents(paidRaw) === null) {
        return { ready: false, message: 'Please enter the amount paid.' };
    }
    if (moneyCents(paidRaw) < totalCents) {
        return { ready: false, message: 'Amount paid is less than the sale total.' };
    }
    return { ready: true, message: '' };
}
function renderCreditAccountPanel(method) {
    const panel = document.getElementById('creditAccountPanel');
    const lines = document.getElementById('creditAccountLines');
    const amountWrap = document.getElementById('amountPaidWrap');
    const changeRow = document.getElementById('changeRow');
    if (method !== 'CREDIT') {
        panel.hidden = true;
        amountWrap.hidden = false;
        changeRow.hidden = false;
        return;
    }
    panel.hidden = false;
    amountWrap.hidden = true;
    changeRow.hidden = true;
    if (!selectedCustomer || !selectedCustomer.id) {
        lines.textContent = 'Select an active customer with an account.';
        return;
    }
    if (!customerAccountInfo) {
        lines.textContent = 'Loading account…';
        return;
    }
    if (!customerAccountInfo.has_account) {
        lines.textContent = (customerAccountInfo.customer_name || selectedCustomer.name || 'Customer')
            + ' has no account. Create an account before charging to credit.';
        return;
    }
    const creditStatus = customerAccountInfo.credit_status || 'NORMAL';
    lines.innerHTML =
        '<div><strong>' + escapeHtml(customerAccountInfo.customer_name || '') + '</strong>'
        + (customerAccountInfo.customer_code ? ' · ' + escapeHtml(customerAccountInfo.customer_code) : '')
        + '</div>'
        + '<div>Account: ' + escapeHtml(customerAccountInfo.account_number || '')
        + ' · ' + escapeHtml(customerAccountInfo.status || '')
        + ' · Credit: ' + escapeHtml(creditStatus) + '</div>'
        + '<div>Balance: $' + escapeHtml(customerAccountInfo.balance || '0.00') + '</div>'
        + '<div>Credit limit: $' + escapeHtml(customerAccountInfo.credit_limit || '0.00') + '</div>'
        + '<div>Available credit: $' + escapeHtml(customerAccountInfo.available_credit || '0.00') + '</div>'
        + (creditStatus === 'ON_HOLD'
            ? '<div class="pay-error">On credit hold — credit sales blocked (server-enforced).</div>'
            : '');
}
function fetchCustomerAccountInfo(customerId) {
    const token = ++accountFetchToken;
    customerAccountInfo = null;
    if (!customerId) {
        renderCreditAccountPanel(document.getElementById('paymentMethod').value);
        syncPayForm();
        return;
    }
    $.getJSON('ajax_pos_customer_account.php', { customer_master_id: customerId })
        .done(function (data) {
            if (token !== accountFetchToken) return;
            if (!data || !data.success) {
                customerAccountInfo = { has_account: false, eligible: false };
            } else {
                customerAccountInfo = data;
            }
            renderCreditAccountPanel(document.getElementById('paymentMethod').value);
            syncPayForm();
        })
        .fail(function () {
            if (token !== accountFetchToken) return;
            customerAccountInfo = { has_account: false, eligible: false };
            renderCreditAccountPanel(document.getElementById('paymentMethod').value);
            syncPayForm();
        });
}
function onPaymentMethod() {
    if (saleBusy) return;
    serverPayError = '';
    const method = document.getElementById('paymentMethod').value;
    const amount = document.getElementById('amountPaid');
    if (method === 'CREDIT') {
        amount.value = (cartTotalCents() / 100).toFixed(2);
        paidManual = false;
        if (selectedCustomer && selectedCustomer.id && !customerAccountInfo) {
            fetchCustomerAccountInfo(selectedCustomer.id);
        }
    } else if (method === 'CASH') {
        amount.value = '';
        paidManual = false;
    } else {
        amount.value = (cartTotalCents() / 100).toFixed(2);
        paidManual = false;
    }
    quickSig = '';
    renderCreditAccountPanel(method);
    syncPayForm();
    if (method !== 'CREDIT') {
        amount.focus();
    }
}
function onAmountPaid() {
    if (saleBusy) return;
    serverPayError = '';
    paidManual = true;
    syncPayForm();
}
function onPaymentReference() {
    if (saleBusy) return;
    serverPayError = '';
    syncPayForm();
}
function showOrderNotice(message) {
    const notice = document.getElementById('orderNotice');
    notice.hidden = false;
    notice.textContent = message;
}
function hideOrderNotice() {
    const notice = document.getElementById('orderNotice');
    notice.hidden = true;
    notice.textContent = '';
}
function payContextText() {
    // The till line already names the till, its cashier and branch ("Till: Till 01 · Kudakwashe · OPEN · Village").
    const till = (document.getElementById('tillLine').textContent || 'Till: Not Open').trim();
    if (/OPEN/.test(till)) return till;
    const parts = [till];
    if (posCashier) parts.push(posCashier);
    if (posBranch) parts.push(psBranchLabel(posBranch));
    return parts.join(' · ');
}
// 2026-10-31: quick cash buttons use the sale currency's notes and suggest what a customer is likely to hand over:
// the total rounded up to each note (e.g. $3.50 -> 4, 5, 10, 20, 50; $35 -> 40, 50, 100; $120 -> 150, 200). Up to 5 besides Exact.
const QUICK_CASH_NOTES = {
    USD: [1, 2, 5, 10, 20, 50, 100],
    ZWG: [1, 2, 5, 10, 20, 50, 100, 200],
    ZAR: [10, 20, 50, 100, 200],
};
function quickCashAmounts(totalCents, code) {
    const all = QUICK_CASH_NOTES[code] || QUICK_CASH_NOTES.USD;
    if (!(totalCents > 0)) return [];
    // Only round to notes worth at least a tenth of the bill: nobody hands over $36 for $35.
    let notes = all.filter(function (note) { return note * 100 * 10 >= totalCents; });
    if (!notes.length) notes = [all[all.length - 1]];
    const seen = {};
    const out = [];
    notes.forEach(function (note) {
        const n = note * 100;
        const amount = Math.ceil(totalCents / n) * n;
        if (amount > totalCents && !seen[amount]) { seen[amount] = true; out.push(amount); }
    });
    out.sort(function (a, b) { return a - b; });
    return out.slice(0, 5);
}
function renderQuickCash(totalCents, method) {
    const wrap = document.getElementById('payQuick');
    if (method !== 'CASH') {
        wrap.hidden = true;
        quickSig = '';
        return;
    }
    const options = [{ label: 'Exact', cents: totalCents }];
    quickCashAmounts(totalCents, posCur).forEach(function (cents) {
        options.push({ label: fmtAmount(cents / 100), cents: cents });
    });
    const sig = options.map(function (opt) { return opt.cents; }).join(',');
    wrap.hidden = false;
    if (sig !== quickSig) {
        quickSig = sig;
        wrap.innerHTML = options.map(function (opt) {
            return '<button type="button" class="btn btn-gray" data-quick="' + opt.cents + '">' + escapeHtml(opt.label) + '</button>';
        }).join('');
    }
    wrap.querySelectorAll('[data-quick]').forEach(function (btn) { btn.disabled = saleBusy; });
}
function syncPayForm() {
    const lines = cartLines();
    const totalCents = cartTotalCents();
    const count = lines.reduce(function (sum, item) { return sum + item.quantity; }, 0);
    const money = formatMoney(totalCents);
    document.getElementById('payTotalDue').textContent = money;
    document.getElementById('payItemCount').textContent = String(count);
    document.getElementById('paySubtotal').textContent = money;
    document.getElementById('payTotal').textContent = money;
    document.getElementById('payContext').textContent = payContextText();
    const method = document.getElementById('paymentMethod').value;
    document.getElementById('payRefWrap').hidden = method === 'CASH' || method === 'CREDIT';
    renderCreditAccountPanel(method);
    // 2026-11-05 split payment: quick-cash notes only for a single payment; "Still due" and the change currency.
    renderQuickCash(totalCents, isSplit() ? '' : method);
    document.getElementById('addTenderBtn').hidden = method === 'CREDIT' || extraTenders.length >= 7;
    const paidRaw = (document.getElementById('amountPaid').value || '').trim();
    const paid = moneyCents(paidRaw);
    let changeSale = (method === 'CREDIT') ? 0
        : ((paid !== null && paid >= totalCents) ? (paid - totalCents) : 0);
    const dueRow = document.getElementById('dueRow');
    dueRow.hidden = true;
    if (isSplit()) {
        refreshAutoTenders();
        const f = splitFigures();
        changeSale = f.change;
        dueRow.hidden = f.due <= 0 || !!f.problem;
        document.getElementById('stillDue').textContent = formatMoney(f.due);
    }
    const changeCur = syncChangeCurrency(isSplit() ? changeSale : 0);
    const changeShown = changeCur === posCur ? changeSale : fxConvertCents(changeSale, posCur, changeCur);
    document.getElementById('changeAmount').textContent = changeShown === null ? '—' : fmtAmount(changeShown / 100, changeCur);
    const issues = payIssues();
    const message = serverPayError || issues.message;
    const errorEl = document.getElementById('payError');
    errorEl.hidden = message === '';
    errorEl.textContent = message;
    const confirmBtn = document.getElementById('payConfirm');
    if (saleBusy) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Processing payment...';
    } else {
        confirmBtn.disabled = !issues.ready;
        confirmBtn.textContent = 'Confirm Payment';
    }
}
function setPayLocked(locked) {
    saleBusy = locked;
    document.getElementById('paymentMethod').disabled = locked;
    document.getElementById('amountPaid').disabled = locked;
    document.getElementById('paymentReference').disabled = locked;
    document.getElementById('addTenderBtn').disabled = locked;
    document.getElementById('changeCurrency').disabled = locked;
    document.querySelectorAll('#splitRows select, #splitRows input, #splitRows button').forEach(function (el) { el.disabled = locked; });
    document.querySelectorAll('[data-pay]').forEach(function (btn) { btn.disabled = locked; });
    document.querySelectorAll('[data-quick]').forEach(function (btn) { btn.disabled = locked; });
    if (locked) {
        const confirmBtn = document.getElementById('payConfirm');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Processing payment...';
    } else {
        syncPayForm();
    }
}
function showPaySheet() {
    if (typeof closeUserChipMenu === 'function') closeUserChipMenu();
    closeSheets();
    serverPayError = '';
    paidManual = false;
    quickSig = '';
    const method = document.getElementById('paymentMethod');
    const amount = document.getElementById('amountPaid');
    const reference = document.getElementById('paymentReference');
    method.value = 'CASH';
    method.disabled = false;
    amount.value = '';
    amount.disabled = false;
    reference.value = '';
    reference.disabled = false;
    extraTenders = []; // 2026-11-05: every sale starts with one payment
    renderTenders();
    document.getElementById('changeCurrency').value = posCur;
    method.dispatchEvent(new Event('change')); // modern dropdown label (onPaymentMethod: Cash, empty amount)
    document.getElementById('paySheet').classList.add('show');
    syncPayForm();
    window.setTimeout(function () { amount.focus(); }, 30);
}
function openPaymentScreen() {
    if (saleBusy || payOpening) return;
    hideOrderNotice();
    if (!cartLines().length) {
        showOrderNotice('No products in the cart.');
        return;
    }
    payOpening = true;
    const finish = function (data) {
        payOpening = false;
        if (data && data.label) setTillLabel(data.label);
        tillOpen = !!(data && data.open);
        if (data && data.branch) {
            posBranch = String(data.branch);
        }
        if (!cartLines().length) {
            showOrderNotice('No products in the cart.');
            return;
        }
        if (!tillOpen) {
            showOrderNotice('Please open a till session on this branch before completing a sale. Use Till → Open Session.');
            return;
        }
        if (data && data.align_ok === false) {
            showOrderNotice((data.align_error || data.align_note || 'This till cannot be used for sales right now. Close it or contact an administrator.'));
            return;
        }
        if (data && data.sale_ready === false) {
            showOrderNotice((data.align_error || data.align_note || 'Please open a till session on this branch before completing a sale.'));
            return;
        }
        if (!ticketConfirmed()) {
            openTicket(true);
            return;
        }
        showPaySheet();
    };
    if (window.jQuery) {
        $.getJSON('session_status.php?format=json').done(finish).fail(function () {
            payOpening = false;
            showOrderNotice('Please open a till session before completing a sale.');
        });
        return;
    }
    fetch('session_status.php?format=json').then(function (res) { return res.json(); }).then(finish).catch(function () {
        payOpening = false;
        showOrderNotice('Please open a till session before completing a sale.');
    });
}
function renderCart() {
    if (posQuote && cartLines().length === 0) { posQuote = null; renderQuoteBanner(); }
    const lines = cartLines();
    const totalCents = cartTotalCents();
    const money = formatMoney(totalCents);
    document.getElementById('orderLines').innerHTML = lines.length
        ? lines.map((item) => {
            const available = ledgerText(item.ledger_qty);
            const title = available === null
                ? String(item.product || '')
                : String(item.product || '') + ' · Stock: ' + available;
            const lineTotal = (item.quantity * item.price).toFixed(2);
            const packNote = item.pack_id ? ' · ' + item.pack_name + ' (' + unitsText(Number(item.factor_milli), item.base_label || '') + ')' : '';
            return '<div class="oline" title="' + escapeHtml(title) + '">'
                + '<div class="oline-name">' + escapeHtml(item.product + packNote) + '</div>'
                + '<button type="button" class="oline-qty" onclick="openQtyLine(' + escapeHtml(JSON.stringify(item.product_id)) + ',' + Number(item.pack_id || 0) + ')" title="Change quantity">×' + escapeHtml(item.pack_id ? String(item.quantity) : qtyLabel(item.quantity, item)) + '</button>'
                + '<div class="oline-price">' + escapeHtml(fmtAmount(lineTotal)) + '</div>'
                + '</div>';
        }).join('')
        : '<p class="hint">No items yet. Add products from the menu.</p>';
    document.getElementById('itemCount').innerText = lines.reduce((sum, item) => sum + (item.measure ? 1 : item.quantity), 0);
    document.getElementById('subtotalAmount').innerText = money;
    document.getElementById('totalAmount').innerText = money;
    updateCustomerBadge();
    if (document.getElementById('paySheet').classList.contains('show')) syncPayForm();
}
function isCashCustomerSearchValue(q) {
    return !q || String(q).trim().toUpperCase() === 'CASH CUSTOMER';
}
function updateCustomerBadge() {
    const badge = document.getElementById('custBadge');
    if (!badge) return;
    // Former Cash-customer chip is now the + Custom control; keep the caption stable.
    badge.textContent = '+ Custom';
}
function syncCustomerUi() {
    const idEl = document.getElementById('customerMasterId');
    const labelEl = document.getElementById('customerSelectedLabel');
    const clearBtn = document.getElementById('customerClearBtn');
    const searchEl = document.getElementById('customerSearch');
    if (selectedCustomer && selectedCustomer.id) {
        idEl.value = String(selectedCustomer.id);
        labelEl.textContent = selectedCustomer.name +
            (selectedCustomer.code ? ' · ' + selectedCustomer.code : '');
        labelEl.hidden = false;
        clearBtn.hidden = false;
    } else {
        idEl.value = '';
        labelEl.textContent = CASH_CUSTOMER_LABEL;
        labelEl.hidden = true;
        clearBtn.hidden = true;
        if (isCashCustomerSearchValue(searchEl.value)) {
            searchEl.value = CASH_CUSTOMER_LABEL;
        }
    }
    updateCustomerBadge();
}
function clearPosCustomer() {
    selectedCustomer = null;
    loyaltyRedeem = 0;
    customerAccountInfo = null;
    accountFetchToken++;
    document.getElementById('customerSearch').value = CASH_CUSTOMER_LABEL;
    hideCustomerResults();
    syncCustomerUi();
    if (document.getElementById('paySheet').classList.contains('show')) {
        renderCreditAccountPanel(document.getElementById('paymentMethod').value);
        syncPayForm();
    }
}
function hideCustomerResults() {
    const box = document.getElementById('customerResults');
    box.hidden = true;
    box.innerHTML = '';
}
function onCustomerSearchFocus() {
    const el = document.getElementById('customerSearch');
    if (!selectedCustomer && isCashCustomerSearchValue(el.value)) {
        el.value = '';
        hideCustomerResults();
        return;
    }
    onCustomerSearch();
}
function onCustomerSearchBlur() {
    if (selectedCustomer && selectedCustomer.id) return;
    const el = document.getElementById('customerSearch');
    if (isCashCustomerSearchValue(el.value)) {
        el.value = CASH_CUSTOMER_LABEL;
        hideCustomerResults();
    }
}
function onCustomerSearch() {
    if (customerSearchTimer) clearTimeout(customerSearchTimer);
    customerSearchTimer = setTimeout(runCustomerSearch, 220);
}
function runCustomerSearch() {
    const q = (document.getElementById('customerSearch').value || '').trim();
    const token = ++customerSearchToken;
    const box = document.getElementById('customerResults');
    if (isCashCustomerSearchValue(q)) {
        hideCustomerResults();
        return;
    }
    $.getJSON('ajax_pos_customer_search.php', { q: q })
        .done(function (data) {
            if (token !== customerSearchToken) return;
            if (!data || !data.success) {
                box.innerHTML = '<p class="hint">Could not search customers.</p>';
                box.hidden = false;
                return;
            }
            const rows = data.rows || [];
            if (!rows.length) {
                box.innerHTML = '<p class="hint">No active customers found.</p>';
                box.hidden = false;
                return;
            }
            box.innerHTML = rows.map(function (row) {
                const name = escapeHtml(row.display_name || '');
                const code = escapeHtml(row.customer_code || '');
                const phone = row.phone ? '<div class="hint">' + escapeHtml(row.phone) + '</div>' : '';
                return '<button type="button" data-id="' + Number(row.id) +
                    '" data-code="' + code + '" data-name="' + name + '">' +
                    '<strong>' + name + '</strong> · ' + code + phone + '</button>';
            }).join('');
            box.hidden = false;
        })
        .fail(function () {
            if (token !== customerSearchToken) return;
            box.innerHTML = '<p class="hint">Could not search customers.</p>';
            box.hidden = false;
        });
}
function toggleNewCustomer(open) {
    const form = document.getElementById('newCustForm');
    if (!form) return;
    form.style.display = open ? 'grid' : 'none';
    const legacyBtn = document.getElementById('newCustBtn');
    if (legacyBtn) {
        legacyBtn.hidden = true;
        legacyBtn.style.display = 'none';
    }
    const badge = document.getElementById('custBadge');
    if (badge) badge.hidden = !!open;
    const err = document.getElementById('ncError');
    if (err) err.hidden = true;
    if (open) { syncNewCustomerType(); document.getElementById(document.getElementById('ncType').value === 'BUSINESS' ? 'ncBiz' : 'ncFirst').focus(); }
}
function syncNewCustomerType() {
    const biz = document.getElementById('ncType').value === 'BUSINESS';
    document.getElementById('ncFirst').style.display = biz ? 'none' : '';
    document.getElementById('ncLast').style.display = biz ? 'none' : '';
    document.getElementById('ncBiz').style.display = biz ? '' : 'none';
}
function saveNewCustomer() {
    const err = document.getElementById('ncError');
    const btn = document.getElementById('ncSave');
    const v = function (id) { return (document.getElementById(id).value || '').trim(); };
    err.hidden = true;
    btn.disabled = true;
    $.post('ajax_pos_customer_create.php', {
        customer_type: v('ncType'), first_name: v('ncFirst'), last_name: v('ncLast'), business_name: v('ncBiz'), phone: v('ncPhone'), email: v('ncEmail')
    }, null, 'json').done(function (d) {
        btn.disabled = false;
        if (!d || !d.success) { err.textContent = (d && d.error) || 'The customer could not be saved.'; err.hidden = false; return; }
        ['ncFirst', 'ncLast', 'ncBiz', 'ncPhone', 'ncEmail'].forEach(function (id) { document.getElementById(id).value = ''; });
        toggleNewCustomer(false);
        selectPosCustomer(d.id, d.code, d.name);
    }).fail(function () {
        btn.disabled = false;
        err.textContent = 'The customer could not be saved.';
        err.hidden = false;
    });
}
function selectPosCustomer(id, code, name) {
    selectedCustomer = { id: Number(id), code: code || '', name: name || '' };
    loyaltyRedeem = 0;
    customerAccountInfo = null;
    document.getElementById('customerSearch').value = '';
    hideCustomerResults();
    syncCustomerUi();
    fetchCustomerAccountInfo(selectedCustomer.id);
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
}
function submitSale() {
    if (saleBusy) return;
    if (!ticketConfirmed()) {
        closePaySheet(true);
        openTicket(true);
        return;
    }
    const issues = payIssues();
    if (!issues.ready) {
        serverPayError = '';
        syncPayForm();
        return;
    }
    const lines = cartLines();
    const paidRaw = (document.getElementById('amountPaid').value || '').trim();
    const masterId = (document.getElementById('customerMasterId').value || '').trim();
    setPayLocked(true);
    const method = document.getElementById('paymentMethod').value;
    const postData = {
        cart: JSON.stringify(lines),
        customer_name: selectedCustomer ? (selectedCustomer.name || 'Cash Customer') : 'Cash Customer',
        payment_method: method,
        amount_paid: method === 'CREDIT' ? (cartTotalCents() / 100).toFixed(2) : paidRaw,
        payment_reference: document.getElementById('paymentReference').value || '',
        payment_token: paymentToken,
        // 2026-10-20: the sale currency and the rate the cashier saw (the server uses its own rate and refuses if it changed).
        currency: posCur,
        fx_rate_seen: (fxInfo() || {}).rate || '1',
        review_fingerprint: ticket.data.fingerprint,
        loyalty_redeem_points: loyaltyRedeem > 0 ? String(loyaltyRedeem) : ''
    };
    if (masterId !== '') {
        postData.customer_master_id = masterId;
    }
    if (posQuote) {
        postData.quotation_id = posQuote.id;
    }
    // 2026-11-05 split payment / split currency: every payment in its own currency, and the change currency.
    if (isSplit()) {
        postData.payments = JSON.stringify(allTenders().map(function (t) {
            return { method: t.method, currency: t.currency || posCur, amount: t.amount, reference: t.method === 'CASH' ? '' : t.reference };
        }));
        postData.change_currency = document.getElementById('changeCurWrap').hidden ? posCur : document.getElementById('changeCurrency').value;
    }
    $.ajax({
        url: 'submit_sale2.php',
        type: 'POST',
        data: postData,
        success: function (response) {
            let jsonResponse = response;
            if (typeof response === 'string') {
                try {
                    jsonResponse = JSON.parse(response);
                } catch (e) {
                    serverPayError = 'An unexpected error occurred. Please try again.';
                    setPayLocked(false);
                    return;
                }
            }
            if (jsonResponse && jsonResponse.payment_token) {
                paymentToken = jsonResponse.payment_token;
            }
            // 2026-10-20: the rate changed since the screen loaded — take the server's rate and re-price before confirming again.
            if (jsonResponse && jsonResponse.fx && jsonResponse.fx.code && POS_FX.currencies[jsonResponse.fx.code]) {
                Object.assign(POS_FX.currencies[jsonResponse.fx.code], jsonResponse.fx);
                repriceCart();
                syncCurrencyUi();
                renderGrid();
                renderCart();
                if (ticket) ticket.confirmed = false;
            }
            if (jsonResponse && jsonResponse.review_required) {
                if (ticket) ticket.confirmed = false;
                setPayLocked(false);
                closePaySheet(true);
                openTicket(true);
                return;
            }
            if (jsonResponse && jsonResponse.success) {
                cart = [];
                resetTicket();
                clearPosCustomer();
                closePaySheet(true);
                renderCart();
                showSaleDone(jsonResponse);
                return;
            }
            serverPayError = (jsonResponse && jsonResponse.error) || 'Payment could not be completed. Please try again.';
            setPayLocked(false);
        },
        error: function () {
            serverPayError = 'An unexpected error occurred. Please try again.';
            setPayLocked(false);
        }
    });
}
/** 2026-10-31: in-app "sale done" with the order number big; OK reloads the POS as before. */
function showSaleDone(res) {
    const dlg = document.getElementById('saleDone');
    const go = function () { if (res.url) window.location.href = res.url; };
    if (!dlg || typeof dlg.showModal !== 'function') {
        if (res.message) alert(res.message);
        go();
        return;
    }
    const order = String(res.order_number || '');
    document.getElementById('saleDoneOrderWrap').hidden = order === '';
    document.getElementById('saleDoneOrder').textContent = order;
    // The order number is shown big above, so drop the "ORDER …" line and the leading tick from the message.
    let text = String(res.message || '').replace(/^✅\s*Sale completed successfully!\s*/u, '').replace(/^ORDER [^\n]*\n/m, '').trim();
    // 2026-11-05: the change the server worked out, in the currency it is given in (split / multi-currency payments).
    if (res.change && res.change.amount && Number(res.change.amount) > 0) {
        text = 'Change: ' + fmtAmount(Number(res.change.amount), res.change.currency) + '\n' + text;
    }
    document.getElementById('saleDoneText').textContent = text;
    const ok = document.getElementById('saleDoneOk');
    ok.onclick = function () { dlg.close(); };
    dlg.addEventListener('close', go, { once: true });
    dlg.showModal();
    ok.focus();
}
function setTillLabel(text) {
    const el = document.getElementById('tillLine');
    if (el) el.textContent = text;
}
function refreshTillLabel() {
    $.getJSON('session_status.php?format=json', function (data) {
        if (data && data.label) {
            setTillLabel(data.label);
        }
        showTillStaleWarning(data && data.stale_warning ? data.stale_warning : '');
    });
}
// Health-check 2026-10-13: visible reminder when a till has been left open past a day.
function showTillStaleWarning(text) {
    let el = document.getElementById('tillStaleWarning');
    const line = document.getElementById('tillLine');
    if (!text) {
        if (el) el.remove();
        return;
    }
    if (!el && line && line.parentNode) {
        el = document.createElement('div');
        el.id = 'tillStaleWarning';
        el.className = 'till-stale-warning';
        el.setAttribute('role', 'alert');
        el.style.cssText = 'margin-top:6px;padding:6px 10px;border-radius:8px;font-size:12px;line-height:1.35;background:rgba(245,158,11,.15);color:#b45309;border:1px solid rgba(245,158,11,.45)';
        line.parentNode.insertBefore(el, line.nextSibling);
    }
    if (el) el.textContent = text;
}
function setTillSheetMessage(text, isError) {
    const statusEl = document.getElementById('tillSheetStatus');
    const errorEl = document.getElementById('tillSheetError');
    const msg = String(text || '').trim();
    if (statusEl && !isError) {
        statusEl.textContent = msg || 'Check session status, then reconcile before close.';
    }
    if (!errorEl) return;
    if (isError && msg) {
        errorEl.hidden = false;
        errorEl.textContent = msg;
    } else {
        errorEl.hidden = true;
        errorEl.textContent = '';
    }
}
function openSession() {
    var amount = document.getElementById('openingFloat').value;
    setTillSheetMessage('', false);
    $.ajax({
        url: 'open_session.php',
        type: 'POST',
        data: { opening_float: amount },
        success: function (data) {
            const text = String(data || '').trim();
            const failed = /could not|already have|select a branch|sign in/i.test(text)
                && !/till opened/i.test(text);
            setTillSheetMessage(text || 'Could not open the till session.', failed);
            if (!failed) {
                setTillSheetMessage(
                    (text || 'Till opened.') + ' When finished: Cash Reconciliation → Close Till.',
                    false
                );
            }
            refreshTillLabel();
        },
        error: function () {
            setTillSheetMessage('Could not open the till session. Check your connection and try again.', true);
        }
    });
}
function closeSession() {
    window.location.href = 'cash_till_close.php';
}
function checkSession() {
    setTillSheetMessage('', false);
    $.getJSON('session_status.php?format=json')
        .done(function (data) {
            if (data && data.label) setTillLabel(data.label);
            if (!data || !data.open) {
                setTillSheetMessage('No open till. Enter opening float and tap Open Session on this branch before selling.', false);
                return;
            }
            const branch = (data.branch || '').trim();
            const uiBranch = (data.ui_branch || posBranch || '').trim();
            if (data.branch) posBranch = String(data.branch);
            const parts = [
                data.label || 'Till open',
                branch ? ('Branch: ' + psBranchLabel(branch)) : 'Branch: not assigned yet',
                'Opened: ' + (data.opened_at || '—'),
                'Float: ' + (data.opening_float || '0.00'),
                'Next: Cash Reconciliation, then Close Till.'
            ].filter(Boolean);
            if (data.align_error) {
                setTillSheetMessage(parts.join(' · ') + ' — ' + data.align_error, true);
                return;
            }
            if (data.align_note) {
                parts.push(data.align_note);
            } else if (data.branch_differs && branch && uiBranch) {
                parts.push('You\'re selling on ' + branch + ' because your till is open there. To sell at another branch, close this till first.');
            }
            setTillSheetMessage(parts.join(' · '), false);
        })
        .fail(function () {
            $.get('session_status.php', function (data) {
                setTillSheetMessage(String(data || 'Could not read till status.'), false);
                refreshTillLabel();
            }).fail(function () {
                setTillSheetMessage('Could not read till status. Try again.', true);
            });
        });
}
document.getElementById('payQuick').addEventListener('click', function (event) {
    const btn = event.target.closest('[data-quick]');
    if (!btn || saleBusy) return;
    serverPayError = '';
    paidManual = true;
    document.getElementById('amountPaid').value = (Number(btn.getAttribute('data-quick')) / 100).toFixed(2);
    syncPayForm();
});
document.getElementById('customerResults').addEventListener('click', function (event) {
    const btn = event.target.closest('button[data-id]');
    if (!btn) return;
    selectPosCustomer(
        btn.getAttribute('data-id'),
        btn.getAttribute('data-code') || '',
        btn.getAttribute('data-name') || ''
    );
});
document.addEventListener('click', function (event) {
    const wrap = document.querySelector('.order-cust');
    if (wrap && !wrap.contains(event.target)) {
        hideCustomerResults();
    }
});
syncCustomerUi();
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const logoutSheet = document.getElementById('logoutSheet');
        if (logoutSheet && logoutSheet.classList.contains('show')) {
            // Shared account menu handler closes logout confirm.
            return;
        }
        if (typeof isUserChipMenuOpen === 'function' && isUserChipMenuOpen()) {
            return;
        }
    }
    const searchEl = document.getElementById('search');
    if (searchEl && event.target === searchEl) {
        // Handled by onProductSearchKey — never treat scanner Enter as sale submit.
        return;
    }
    const sheet = document.getElementById('paySheet');
    if (!sheet || !sheet.classList.contains('show')) return;
    if (event.key === 'Escape') {
        if (!saleBusy) {
            event.preventDefault();
            closePaySheet();
        }
        return;
    }
    if (event.key !== 'Enter') return;
    const tag = event.target && event.target.tagName ? event.target.tagName : '';
    if (tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'BUTTON') return;
    if (event.target && event.target.id === 'search') return;
    event.preventDefault();
    if (!saleBusy && payIssues().ready) submitSale();
});
(function initProductSearchScan() {
    const searchEl = document.getElementById('search');
    if (searchEl) {
        searchEl.addEventListener('keydown', onProductSearchKey);
        // Ready for keyboard-wedge scan; typing still filters the grid.
        setTimeout(focusProductSearch, 200);
    }
})();
function openDiscardCart() {
    if (!cartLines().length) {
        showOrderNotice('No products in the cart.');
        return;
    }
    if (typeof closeUserChipMenu === 'function') closeUserChipMenu();
    const err = document.getElementById('discardError');
    if (err) { err.hidden = true; err.textContent = ''; }
    document.getElementById('discardSheet').classList.add('show');
}
function closeDiscardCart() {
    if (discardBusy) return;
    document.getElementById('discardSheet').classList.remove('show');
}
function confirmDiscardCart() {
    if (discardBusy) return;
    if (!cartLines().length) {
        closeDiscardCart();
        return;
    }
    const reason = (document.getElementById('discardReason').value || '').trim();
    const desc = (document.getElementById('discardReasonDescription').value || '').trim();
    const err = document.getElementById('discardError');
    discardBusy = true;
    document.getElementById('discardConfirm').disabled = true;
    $.ajax({
        url: 'ajax_pos_discard_cart.php',
        type: 'POST',
        data: {
            reason: reason,
            reason_description: desc,
            cancel_submit_token: cancelCartToken,
            branch: 'IGNORED'
        },
        success: function (response) {
            let jsonResponse = response;
            if (typeof response === 'string') {
                try { jsonResponse = JSON.parse(response); } catch (e) { jsonResponse = null; }
            }
            discardBusy = false;
            document.getElementById('discardConfirm').disabled = false;
            if (jsonResponse && jsonResponse.cancel_submit_token) {
                cancelCartToken = jsonResponse.cancel_submit_token;
            }
            if (jsonResponse && jsonResponse.success) {
                cart = [];
                resetTicket();
                clearPosCustomer();
                renderCart();
                closeDiscardCart();
                showOrderNotice(jsonResponse.message || 'Cart cleared.');
                return;
            }
            if (err) {
                err.hidden = false;
                err.textContent = (jsonResponse && jsonResponse.error) || 'The cart could not be discarded.';
            }
        },
        error: function () {
            discardBusy = false;
            document.getElementById('discardConfirm').disabled = false;
            if (err) {
                err.hidden = false;
                err.textContent = 'The cart could not be discarded.';
            }
        }
    });
}
syncCurrencyUi();
loadCatalog();
loadQuoteIntoCart();
renderCart();
refreshTillLabel();
if (posTillAlignError) {
    showOrderNotice(posTillAlignError);
} else if (posTillAlignNote) {
    showOrderNotice(posTillAlignNote);
}
</script>
<?php
ps_logout_confirm_modal('You will need to sign in again to use the POS.');
ps_scripts();
?>
</body>
</html>
