<?php
/**
 * Phase 17 — ZIMRA fiscalisation CLI regression.
 *
 * Runs the REAL production sale path (psCompleteSale) and refund path (request → approve → process)
 * against the configured database, with a SIMULATED ZIMRA server that checks every receipt and
 * fiscal-day signature, the receipt hash chain and the day counters the way FDMS does.
 *
 * WRITES test users, a test device, sales and refunds. Run ONLY on a copy of the database:
 *   php cli_zimra_regression.php --on-a-database-copy
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!in_array('--on-a-database-copy', $argv, true)) {
    fwrite(STDERR, "Refusing to run: this writes test sales. Use a database copy and pass --on-a-database-copy\n");
    exit(2);
}
define('PS_SALE_LIBRARY', 1);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();

$root = __DIR__;
require $root . '/pockets_config.php';
require_once $root . '/submit_sale2.php';
require_once $root . '/includes/sale_refund.php';
require_once $root . '/includes/zimra.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
mysqli_set_charset($conn, 'utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

psEnsureZimraModule($conn);
if (psZimraOne($conn, "SELECT id FROM zimra_devices WHERE environment = 'production' LIMIT 1")) {
    fwrite(STDERR, "Refusing to run: a production ZIMRA device exists in this database.\n");
    exit(2);
}

// ---------- test data ----------
$BR = 'Village';
$TILL = 'zimra_reg_till@test.local';
$SUP = 'zimra_reg_sup@test.local';
$company = (psZimraOne($conn, 'SELECT company_name FROM goods WHERE branch = ? AND status = ? LIMIT 1', 'ss', [$BR, 'ACTIVE'])['company_name'] ?? '');
foreach ([[$TILL, 'Till'], [$SUP, 'Supervisor']] as [$email, $level]) {
    if (!psZimraOne($conn, 'SELECT id FROM users WHERE email = ?', 's', [$email])) {
        psZimraQuery($conn, "INSERT INTO users (firstname, lastname, email, password, level, branch, company_name, lock_status, status, date_created)
            VALUES ('Zimra','Regression',?,?,?,?,?,'free','Posted',?)", 'ssssss',
            [$email, password_hash('x' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT), $level, $BR, $company, date('Y-m-d')]);
    }
}
$products = psZimraQuery($conn, "SELECT product_id, price FROM goods WHERE branch = ? AND status = 'ACTIVE' AND price > 0 ORDER BY product_id LIMIT 3", 's', [$BR]);
ok(count($products) === 3, 'three active products on ' . $BR);

function asUser(string $email, string $level, string $branch, string $company): void
{
    $_SESSION = ['logger' => $email, 'level' => $level, 'branch' => $branch, 'selected_branch' => $branch, 'the_company' => $company];
}

// ---------- simulated FDMS ----------
$gen = psZimraGenerateKeyAndCsr('ZREG0001', 99901);
$cert = openssl_csr_sign($gen['csr'], null, $gen['private_key'], 182, psZimraOpensslOptions() + ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
$FDMS = [
    'pub' => openssl_pkey_get_details(openssl_pkey_get_public($certPem))['key'],
    'day' => 0, 'status' => 'FiscalDayClosed', 'global' => 0, 'prev' => null, 'receipts' => [], 'opened' => '',
    'offline' => false, 'ids' => 5000, 'errors' => [],
];
$GLOBALS['PS_ZIMRA_HTTP_FAKE'] = function (array $device, string $method, string $path, ?array $body) use (&$FDMS) {
    if ($FDMS['offline']) {
        return ['status' => 0];
    }
    $verify = static function (string $data, array $sig) use (&$FDMS): bool {
        return $sig['hash'] === base64_encode(hash('sha256', $data, true))
            && openssl_verify($data, base64_decode($sig['signature']), $FDMS['pub'], OPENSSL_ALGO_SHA256) === 1;
    };
    $did = (int)$device['device_id'];
    if (str_ends_with($path, '/GetConfig')) {
        return ['status' => 200, 'body' => [
            'taxPayerName' => 'Regression Taxpayer', 'taxPayerTIN' => '1000000000', 'deviceBranchName' => 'Reg Branch',
            'deviceBranchAddress' => ['province' => 'Harare', 'city' => 'Harare', 'street' => 'Test St', 'houseNo' => '1'],
            'deviceBranchContacts' => ['phoneNo' => '000', 'email' => 'x@y'], 'deviceOperatingMode' => 'Online',
            'taxPayerDayMaxHrs' => 24, 'taxpayerDayEndNotificationHrs' => 2, 'qrUrl' => 'https://fdmstest.zimra.co.zw',
            'applicableTaxes' => [['taxPercent' => 0, 'taxName' => 'Non-VAT 0%', 'validFrom' => '2023-01-01T00:00:00', 'taxID' => 513]],
        ]];
    }
    if (str_ends_with($path, '/GetStatus')) {
        return ['status' => 200, 'body' => ['fiscalDayStatus' => $FDMS['status'], 'lastFiscalDayNo' => $FDMS['day'], 'lastReceiptGlobalNo' => $FDMS['global']]];
    }
    if (str_ends_with($path, '/OpenDay')) {
        if ($body['fiscalDayNo'] !== $FDMS['day'] + 1 || $FDMS['status'] !== 'FiscalDayClosed') {
            return ['status' => 422, 'body' => ['detail' => 'bad day', 'errorCode' => 'FISC01']];
        }
        $FDMS['day'] = $body['fiscalDayNo'];
        $FDMS['status'] = 'FiscalDayOpened';
        $FDMS['prev'] = null;
        $FDMS['receipts'] = [];
        $FDMS['opened'] = substr($body['fiscalDayOpened'], 0, 10);
        return ['status' => 200, 'body' => ['fiscalDayNo' => $FDMS['day']]];
    }
    if (str_ends_with($path, '/SubmitReceipt')) {
        $r = $body['receipt'];
        $errs = [];
        if ($FDMS['status'] !== 'FiscalDayOpened') {
            $errs[] = 'day not open';
        }
        if ($r['receiptGlobalNo'] !== $FDMS['global'] + 1) {
            $errs[] = 'global gap';
        }
        if (!$verify(psZimraReceiptSignatureString($did, $r, $FDMS['prev']), $r['receiptDeviceSignature'])) {
            $errs[] = 'RCPT020 signature';
        }
        $sum = 0;
        foreach ($r['receiptLines'] as $l) {
            $sum += $l['receiptLineTotal'];
            if (abs(round($l['receiptLinePrice'] * $l['receiptLineQuantity'], 2) - $l['receiptLineTotal']) > 0.001) {
                $errs[] = 'line total';
            }
        }
        if (abs(round($sum, 2) - $r['receiptTotal']) > 0.001) {
            $errs[] = 'receipt total';
        }
        if ($r['receiptType'] === 'CreditNote' && empty($r['creditDebitNote'])) {
            $errs[] = 'credit note reference';
        }
        $FDMS['global'] = $r['receiptGlobalNo'];
        $FDMS['prev'] = $r['receiptDeviceSignature']['hash'];
        $FDMS['receipts'][] = $r;
        $FDMS['errors'] = array_merge($FDMS['errors'], $errs);
        return ['status' => 200, 'body' => ['receiptID' => ++$FDMS['ids'], 'serverDate' => date('Y-m-d\TH:i:s'), 'validationErrors' => $errs]];
    }
    if (str_ends_with($path, '/CloseDay')) {
        $expected = psZimraDayCounters($FDMS['receipts']);
        $okSig = $verify(psZimraDaySignatureString($did, $body['fiscalDayNo'], $FDMS['opened'], $body['fiscalDayCounters']), $body['fiscalDayDeviceSignature']);
        $FDMS['status'] = ($okSig && $expected == $body['fiscalDayCounters'] && $body['receiptCounter'] === count($FDMS['receipts']))
            ? 'FiscalDayClosed' : 'FiscalDayCloseFailed';
        return ['status' => 200, 'body' => ['operationID' => 'sim']];
    }
    return ['status' => 404, 'body' => ['detail' => 'unknown ' . $path]];
};

// ---------- clean previous regression rows ----------
$old = psZimraOne($conn, 'SELECT id FROM zimra_devices WHERE device_id = 99901');
if ($old) {
    foreach (['zimra_receipts', 'zimra_fiscal_days', 'zimra_taxes', 'zimra_tax_map', 'zimra_audit', 'zimra_api_log'] as $t) {
        mysqli_query($conn, "DELETE FROM $t WHERE device_row_id = " . (int)$old['id']);
    }
    mysqli_query($conn, 'DELETE FROM zimra_devices WHERE id = ' . (int)$old['id']);
}
mysqli_query($conn, "UPDATE zimra_devices SET enabled = 0 WHERE branch = 'Village'");

// ---------- admin: device set-up ----------
asUser('zimra_reg_admin@test.local', 'Admin', $BR, $company);
ok(psZimraMaySetup(), 'Admin (capital A) may set up ZIMRA');
$created = psZimraDeviceCreate($conn, ['branch' => $BR, 'environment' => 'test', 'device_id' => 99901, 'device_serial_no' => 'ZREG0001',
    'model_name' => 'Virtual Server', 'model_version' => 'v1'], 'zimra_reg_admin@test.local');
ok($created['ok'], 'device created');
$devRow = (int)$created['id'];
$bad = psZimraDeviceImport($conn, $devRow, $gen['private_key'], str_replace('A', 'B', $certPem), 'admin');
ok(!$bad['ok'], 'tampered certificate rejected');
$imp = psZimraDeviceImport($conn, $devRow, $gen['private_key'], $certPem, 'zimra_reg_admin@test.local');
ok($imp['ok'], 'certificate imported + GetConfig synced');
$dev = psZimraDeviceGet($conn, $devRow);
ok(strpos((string)$dev['private_key_enc'], 'BEGIN') === false && $dev['taxpayer_tin'] === '1000000000', 'key encrypted at rest, taxpayer saved');
ok(psZimraDeviceSetEnabled($conn, $devRow, true, 'admin')['ok'], 'fiscalisation enabled for ' . $BR);

// ---------- till: open till + sales ----------
asUser($TILL, 'Till', $BR, $company);
ok(!psZimraMaySetup() && !psZimraMayManageDay(), 'Till cannot set up or manage the fiscal day');
mysqli_query($conn, "UPDATE sessions SET status = 'CLOSED' WHERE created_by = '" . mysqli_real_escape_string($conn, $TILL) . "' AND status = 'OPEN'");
$_POST = ['opening_float' => '0'];
$openMsg = tillOpen($conn);
$_POST = [];
ok(getActiveTillSession($conn) !== null, 'till opened (' . $openMsg . ')');

function sell(mysqli $conn, array $cart, string $method = 'CASH', ?string $paid = null): array
{
    $_SESSION['ps_sale_token'] = null;
    $token = psSaleTokenIssue();
    $total = 0;
    foreach ($cart as $c) {
        $total += (float)$c['price'] * (float)$c['quantity'];
    }
    return psCompleteSale($conn, [
        'cart' => json_encode($cart),
        'amount_paid' => $paid ?? number_format($total, 2, '.', ''),
        'payment_method' => $method,
        'payment_token' => $token,
    ]);
}
$c1 = [['product_id' => $products[0]['product_id'], 'quantity' => '2', 'price' => $products[0]['price']],
       ['product_id' => $products[1]['product_id'], 'quantity' => '1', 'price' => $products[1]['price']]];
$s1 = sell($conn, $c1);
ok(!empty($s1['success']), 'sale 1 completed: ' . ($s1['error'] ?? $s1['invoice_number']));
$r1 = psZimraReceiptForSale($conn, (string)($s1['invoice_number'] ?? ''), $BR);
ok($r1 && $r1['status'] === 'SUBMITTED', 'sale 1 fiscalised and sent (day auto-opened)');
$day1 = psZimraDayActive($conn, $devRow);
ok($day1 && (int)$day1['fiscal_day_no'] === 1 && $day1['open_state'] === 'CONFIRMED', 'fiscal day 1 open + confirmed by ZIMRA');
$print = psZimraOne($conn, 'SELECT receipt FROM print_jobs WHERE invoice_number = ?', 'i', [(int)$s1['invoice_number']]);
ok($print && strpos($print['receipt'], $r1['verification_code']) !== false, 'printed receipt carries verification code ' . $r1['verification_code']);

$s2 = sell($conn, [['product_id' => $products[2]['product_id'], 'quantity' => '3', 'price' => $products[2]['price']]], 'ECOCASH');
$r2 = psZimraReceiptForSale($conn, (string)($s2['invoice_number'] ?? ''), $BR);
$req2 = json_decode((string)($r2['request_json'] ?? '{}'), true);
ok($r2 && $r2['status'] === 'SUBMITTED' && ($req2['receiptPayments'][0]['moneyTypeCode'] ?? '') === 'MobileWallet', 'EcoCash sale → MobileWallet, sent');

// Offline: sale still completes, receipt pending, then resent in order
$FDMS['offline'] = true;
$s3 = sell($conn, [['product_id' => $products[0]['product_id'], 'quantity' => '1', 'price' => $products[0]['price']]], 'CARD');
$s4 = sell($conn, [['product_id' => $products[1]['product_id'], 'quantity' => '2', 'price' => $products[1]['price']]]);
$r3 = psZimraReceiptForSale($conn, (string)$s3['invoice_number'], $BR);
ok(!empty($s3['success']) && !empty($s4['success']) && $r3['status'] === 'PENDING', 'offline: sales complete, fiscal receipts PENDING');
ok(strpos((string)$s3['message'], 'when the connection is back') !== false, 'cashier told the receipt will be sent later');
$FDMS['offline'] = false;
$sent = psZimraSubmitPending($conn, $devRow, 'worker');
ok($sent['ok'] && $sent['sent'] === 2, 'worker resent 2 receipts in order');

// ---------- refund → credit note (partial) ----------
$line = psZimraOne($conn, 'SELECT id FROM online_sales WHERE invoice_number = ? ORDER BY id LIMIT 1', 's', [(string)$s1['invoice_number']]);
$rq = psRequestRefund($conn, ['lines' => [['sale_line_id' => (int)$line['id'], 'quantity' => '1']], 'reason' => 'CUSTOMER_RETURN', 'reason_description' => 'Regression']);
ok(!empty($rq['ok']), 'refund requested: ' . ($rq['error'] ?? $rq['refund_number'] ?? ''));
asUser($SUP, 'Supervisor', $BR, $company);
ok(psRefundMayApprove(), 'Supervisor may approve refunds');
$ap = psApproveRefundRequest($conn, ['request_id' => (int)$rq['request_id'], 'approval_note' => 'Approved by supervisor']);
ok(!empty($ap['ok']), 'refund approved by Supervisor: ' . ($ap['error'] ?? ''));
asUser($TILL, 'Till', $BR, $company);
$pr = psProcessApprovedRefund($conn, ['request_id' => (int)$rq['request_id']]);
ok(!empty($pr['ok']), 'refund processed: ' . ($pr['error'] ?? ''));
$cn = psZimraOne($conn, "SELECT * FROM zimra_receipts WHERE source_type = 'REFUND' AND source_ref = ?", 's', [(string)($rq['refund_number'] ?? '')]);
$cnReq = json_decode((string)($cn['request_json'] ?? '{}'), true);
ok($cn && $cn['status'] === 'SUBMITTED' && (float)$cn['receipt_total'] === -(float)$products[0]['price'],
    'credit note for 1 unit (' . ($cn['receipt_total'] ?? '?') . ') sent');
ok(!empty($cnReq['creditDebitNote']) && ($cnReq['receiptNotes'] ?? '') !== '', 'credit note references original + carries reason "' . ($cnReq['receiptNotes'] ?? '') . '"');

// ---------- close day (Supervisor) ----------
asUser($SUP, 'Supervisor', $BR, $company);
ok(psZimraMayManageDay() && !psZimraMaySetup(), 'Supervisor manages the day but not set-up');
$cl = psZimraDayClose($conn, $devRow, $SUP, 0);
ok($cl['ok'] && $cl['status'] === 'CLOSED', 'fiscal day 1 closed: counters + signature accepted (' . ($cl['error'] ?? '') . ')');
ok(empty($FDMS['errors']), 'simulated ZIMRA reported no validation errors' . ($FDMS['errors'] ? ': ' . implode(', ', $FDMS['errors']) : ''));

// Next day auto-opens on the next sale; counters restart, global continues
asUser($TILL, 'Till', $BR, $company);
$s5 = sell($conn, [['product_id' => $products[2]['product_id'], 'quantity' => '1', 'price' => $products[2]['price']]]);
$r5 = psZimraReceiptForSale($conn, (string)$s5['invoice_number'], $BR);
$day2 = psZimraDayActive($conn, $devRow);
ok($r5 && (int)$r5['receipt_counter'] === 1 && (int)$r5['receipt_global_no'] === 6 && (int)$day2['fiscal_day_no'] === 2, 'day 2 auto-opened: counter 1, global 6');

// Over the day limit: sales refused until a supervisor closes the day
mysqli_query($conn, 'UPDATE zimra_fiscal_days SET opened_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ' . (int)$day2['id']);
$s6 = sell($conn, [['product_id' => $products[2]['product_id'], 'quantity' => '1', 'price' => $products[2]['price']]]);
ok(empty($s6['success']) && strpos((string)$s6['error'], 'supervisor must close') !== false, 'sale refused after 24 h: ' . ($s6['error'] ?? ''));
$sale6 = psZimraOne($conn, "SELECT COUNT(*) n FROM online_sales_ref WHERE invoice_number = ?", 's', [(string)($s6['invoice_number'] ?? '-1')]);
ok((int)$sale6['n'] === 0, 'refused sale left no sale rows (rolled back)');
mysqli_query($conn, 'UPDATE zimra_fiscal_days SET opened_at = NOW() WHERE id = ' . (int)$day2['id']);
asUser($SUP, 'Supervisor', $BR, $company);
ok(psZimraDayClose($conn, $devRow, $SUP, 0)['status'] === 'CLOSED', 'day 2 closed');

// Device switched off later: refunds of fiscalised sales still issue credit notes
asUser($TILL, 'Till', $BR, $company);
psZimraDeviceSetEnabled($conn, $devRow, false, 'regression');
$line5 = psZimraOne($conn, 'SELECT id FROM online_sales WHERE invoice_number = ? ORDER BY id LIMIT 1', 's', [(string)$s5['invoice_number']]);
$rq2 = psRequestRefund($conn, ['lines' => [['sale_line_id' => (int)$line5['id'], 'quantity' => '1']], 'reason' => 'DAMAGED_ITEM', 'reason_description' => 'Regression 2']);
asUser($SUP, 'Supervisor', $BR, $company);
psApproveRefundRequest($conn, ['request_id' => (int)($rq2['request_id'] ?? 0), 'approval_note' => 'Approved again']);
asUser($TILL, 'Till', $BR, $company);
$pr2 = psProcessApprovedRefund($conn, ['request_id' => (int)($rq2['request_id'] ?? 0)]);
$cn2 = psZimraOne($conn, "SELECT status FROM zimra_receipts WHERE source_type = 'REFUND' AND source_ref = ?", 's', [(string)($rq2['refund_number'] ?? '')]);
ok(!empty($pr2['ok']) && $cn2 && $cn2['status'] === 'SUBMITTED', 'device off: refund still issues + sends credit note (' . ($pr2['error'] ?? '') . ')');
asUser($SUP, 'Supervisor', $BR, $company);
ok(psZimraDayClose($conn, $devRow, $SUP, 0)['status'] === 'CLOSED', 'day 3 (credit note only) closed');

// Branch without a device is not affected
$other = psZimraDeviceForBranch($conn, 'Westlea sports bar');
ok($other === null, 'other branches have no device → sales there are not fiscalised');

// QR + print view
$view = psZimraReceiptView($conn, $r1);
ok((bool)preg_match('~^https://fdmstest\.zimra\.co\.zw/0000099901\d{8}0000000001[0-9A-F]{16}$~', $r1['qr_url']), 'QR URL format ' . $r1['qr_url']);
ok(strpos(psZimraQrDataUri($r1['qr_url']), 'data:image/svg+xml;base64,') === 0, 'QR image renders from vendor endroid/qr-code');
ok($view['title'] === 'FISCAL INVOICE' && $view['tin'] === '1000000000', 'print view: non-VAT title + TIN');

mysqli_query($conn, "UPDATE sessions SET status = 'CLOSED' WHERE created_by = '" . mysqli_real_escape_string($conn, $TILL) . "' AND status = 'OPEN'");
psZimraDeviceSetEnabled($conn, $devRow, false, 'regression');
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
