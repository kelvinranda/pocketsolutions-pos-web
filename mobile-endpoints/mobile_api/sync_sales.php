<?php
/**
 * POST { "sales": [ { client_uuid, branch?, cashier?, customer, total_amount, method, lines: [...], till_session_id? } ] }
 *
 * Phase 5.2 / 6.2 / 9.4 auth + branch binding retained.
 * Phase 9.6: NEW mobile sale posts are fail-closed. This endpoint does not write
 * sale_payments / stock_movements / cash_movements and must not report incomplete
 * sales as completed. Production sale posting remains submit_sale2.php (web POS).
 * Already-mapped client_uuid values still return idempotent success (no rewrite).
 */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/sale_payment.php';

$body = json_body();
$tokenFromQuery = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$tokenFromBody = isset($body['token']) ? trim((string)$body['token']) : '';

$tokenOverride = null;
if ($tokenFromBody !== '') {
    $tokenOverride = $tokenFromBody;
} elseif ($tokenFromQuery !== '') {
    $tokenOverride = $tokenFromQuery;
}
$ctx = mobileAuthRequire($conn, $tokenOverride);

ensure_mobile_tables($conn);
$sales = $body['sales'] ?? null;
if (!is_array($sales) || !count($sales)) {
    respond(['success' => false, 'error' => 'No sales payload'], 400);
}

$results = [];
$working = psBranchNormalizeName($ctx['working_branch'] ?? '');

foreach ($sales as $sale) {
    if (!is_array($sale)) {
        $results[] = ['client_uuid' => '', 'success' => false, 'error' => 'Invalid sale payload'];
        continue;
    }

    $clientUuid = trim((string)($sale['client_uuid'] ?? ''));
    if ($clientUuid === '') {
        $results[] = ['client_uuid' => '', 'success' => false, 'error' => 'Missing client_uuid'];
        continue;
    }

    $uuidEsc = mysqli_real_escape_string($conn, $clientUuid);
    $existing = mysqli_query($conn, "SELECT invoice_number FROM mobile_sale_map WHERE client_uuid = '$uuidEsc' LIMIT 1");
    if ($existing && ($map = mysqli_fetch_assoc($existing))) {
        $results[] = [
            'client_uuid' => $clientUuid,
            'success' => true,
            'invoice_number' => $map['invoice_number'],
            'idempotent' => true,
        ];
        continue;
    }

    // Auth / branch consistency still enforced so spoofed foreign branch is rejected
    // before the incomplete-posting denial (clearer client diagnostics).
    if ($working === '') {
        $results[] = [
            'client_uuid' => $clientUuid,
            'success' => false,
            'error' => 'That branch is not available.',
        ];
        continue;
    }
    $branchCheck = mobileAuthAcceptClientBranch($ctx, $sale['branch'] ?? '');
    if (empty($branchCheck['ok'])) {
        $results[] = [
            'client_uuid' => $clientUuid,
            'success' => false,
            'error' => (string)($branchCheck['error'] ?? 'That branch is not available.'),
        ];
        continue;
    }

    $tillCheck = mobileAuthValidateTillForSale(
        $conn,
        $ctx,
        $sale['till_session_id'] ?? ($sale['session_id'] ?? ''),
        (string)$branchCheck['branch']
    );
    if (empty($tillCheck['ok'])) {
        $results[] = [
            'client_uuid' => $clientUuid,
            'success' => false,
            'error' => (string)($tillCheck['error'] ?? 'That till session is not available.'),
        ];
        continue;
    }

    // Fail closed: do not allocate invoice / insert sale rows without full posting.
    $results[] = [
        'client_uuid' => $clientUuid,
        'success' => false,
        'error' => 'Mobile sale sync cannot complete stock and payment posting. Use the web POS (submit_sale2) for live sales.',
        'code' => 'MOBILE_SALE_POSTING_RESTRICTED',
    ];
}

respond(['success' => true, 'results' => $results]);
