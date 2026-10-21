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
                        <div class="cust-badge" id="custBadge" title="Customer" aria-label="Customer">Customer</div>
                    </div>
                    <div class="hint" id="customerSelectedLabel" style="margin-top:6px">Cash Customer</div>
                    <button type="button" class="btn btn-gray cust-clear" id="customerClearBtn" hidden
                            onclick="clearPosCustomer()">Clear</button>
                    <?php if ($mayAddCustomer): ?>
                    <button type="button" class="btn btn-gray" id="newCustBtn" style="margin-top:6px;padding:4px 10px" onclick="toggleNewCustomer(true)">+ New customer</button>
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
                <div class="kicker" style="margin-top:14px">Cart</div>
            </div>
            <div id="quoteBanner" class="hint" hidden style="margin:6px 0 8px;padding:8px 10px;border-radius:8px;background:rgba(23,138,67,.12);border:1px solid rgba(23,138,67,.35)"></div>
            <div class="order-lines" id="orderLines"></div>
            <div class="order-foot">
                <div class="total"><span>Items</span><span id="itemCount">0</span></div>
                <div class="total"><span>Subtotal</span><span id="subtotalAmount">$0.00</span></div>
                <div class="total"><span>Total</span><span id="totalAmount">$0.00</span></div>
                <button class="btn btn-blue" type="button" data-pay onclick="openPaymentScreen()">Print / Submit</button>
                <button class="btn btn-yellow" type="button" data-pay onclick="openPaymentScreen()">Place Order</button>
                <button class="btn btn-gray" type="button" id="discardCartBtn" onclick="openDiscardCart()">Clear cart</button>
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
            <div class="total" id="changeRow"><span>Change</span><span id="changeAmount">$0.00</span></div>
            <div id="payRefWrap" hidden>
                <div class="kicker">Reference (optional)</div>
                <input class="field" id="paymentReference" type="text" maxlength="120" placeholder="Optional" aria-label="Reference (optional)" autocomplete="off" oninput="onPaymentReference()">
            </div>
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
}
function closePaySheet(force) {
    if (saleBusy && !force) return;
    document.getElementById('paySheet').classList.remove('show');
}
function toggleOrder() {
    document.getElementById('orderPane').classList.toggle('mobile-open');
}
function qtyFor(id) {
    const line = cart.find((item) => item.product_id === id);
    return line ? Number(line.quantity) : 0;
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
    cart = cart.filter((item) => item.product_id !== product.product_id);
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
            unit: product.unit || ''
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
    return fmtAmount(fxUnit(p.price)) + (p.measure && p.unit ? ' / ' + p.unit : '');
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
    const current = qtyFor(p.product_id);
    document.getElementById('measureTitle').textContent = p.product;
    document.getElementById('measureUnit').textContent = p.measure ? (p.unit || '') : 'pcs';
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
function openQtyLine(productId) {
    const fromGrid = products.find(function (x) { return x.product_id === productId; });
    const line = cart.find(function (x) { return x.product_id === productId; });
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
    const available = ledgerText(p.ledger_qty);
    const availableMilli = available === null ? null : stockMilli(available);
    if (!ALLOW_NEGATIVE_STOCK && availableMilli !== null && stockMilli(raw) > availableMilli) {
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
        const stockHtml = available === null ? '' : '<div class="pstock">Stock: ' + escapeHtml(available) + (p.measure && p.unit ? ' ' + escapeHtml(p.unit) : '') + '</div>';
        return '<div class="pcard">' +
            '<div><div class="pname">' + escapeHtml(p.display_name || p.product) + '</div>' +
            stockHtml + '</div>' +
            '<div class="pfoot"><div class="pprice">' + escapeHtml(priceLabel(p)) + '</div>' +
            stepHtml(p, idx) +
            '</div></div>';
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
        const requestedMilli = stockMilli(String(next));
        const availableMilli = available === null ? null : stockMilli(available);
        if (!ALLOW_NEGATIVE_STOCK && availableMilli !== null && requestedMilli !== null && requestedMilli > availableMilli) {
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
    return moneyCents(cartTotalValue().toFixed(2)) || 0;
}
function formatMoney(cents) {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    return fmtAmount(cents / 100);
}
function payIssues() {
    const lines = cartLines();
    const totalCents = cartTotalCents();
    const method = (document.getElementById('paymentMethod').value || '').trim();
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
    if (posBranch) parts.push(posBranch);
    return parts.join(' · ');
}
function renderQuickCash(totalCents, method) {
    const wrap = document.getElementById('payQuick');
    if (method !== 'CASH') {
        wrap.hidden = true;
        quickSig = '';
        return;
    }
    const notes = [1000, 2000, 5000, 10000];
    const options = [{ label: 'Exact', cents: totalCents }];
    notes.forEach(function (cents) {
        if (cents >= totalCents) options.push({ label: fmtAmount(cents / 100), cents: cents });
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
    renderQuickCash(totalCents, method);
    const paidRaw = (document.getElementById('amountPaid').value || '').trim();
    const paid = moneyCents(paidRaw);
    const change = (method === 'CREDIT') ? 0
        : ((paid !== null && paid >= totalCents) ? (paid - totalCents) : 0);
    document.getElementById('changeAmount').textContent = formatMoney(change);
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
            return '<div class="oline" title="' + escapeHtml(title) + '">'
                + '<div class="oline-name">' + escapeHtml(item.product) + '</div>'
                + '<button type="button" class="oline-qty" onclick="openQtyLine(' + escapeHtml(JSON.stringify(item.product_id)) + ')" title="Change quantity">×' + escapeHtml(qtyLabel(item.quantity, item)) + '</button>'
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
    if (!selectedCustomer) {
        badge.innerText = 'Customer';
        return;
    }
    const label = selectedCustomer.name || selectedCustomer.code || 'C';
    const parts = String(label).split(/[\s@._-]+/).filter(Boolean);
    let initials = '';
    parts.forEach((part) => { if (initials.length < 2) initials += part.charAt(0).toUpperCase(); });
    badge.innerText = initials || 'C';
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
        clearBtn.hidden = false;
    } else {
        idEl.value = '';
        labelEl.textContent = CASH_CUSTOMER_LABEL;
        clearBtn.hidden = true;
        if (isCashCustomerSearchValue(searchEl.value)) {
            searchEl.value = CASH_CUSTOMER_LABEL;
        }
    }
    updateCustomerBadge();
}
function clearPosCustomer() {
    selectedCustomer = null;
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
    document.getElementById('newCustBtn').style.display = open ? 'none' : '';
    document.getElementById('ncError').hidden = true;
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
        fx_rate_seen: (fxInfo() || {}).rate || '1'
    };
    if (masterId !== '') {
        postData.customer_master_id = masterId;
    }
    if (posQuote) {
        postData.quotation_id = posQuote.id;
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
            }
            if (jsonResponse && jsonResponse.success) {
                cart = [];
                clearPosCustomer();
                closePaySheet(true);
                renderCart();
                if (jsonResponse.message) alert(jsonResponse.message);
                if (jsonResponse.url) window.location.href = jsonResponse.url;
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
                branch ? ('Branch: ' + branch) : 'Branch: not assigned yet',
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
