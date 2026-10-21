<?php
/**
 * POST { "pairing_code": "ABCD-EFGH", "machine_name": "...", "app_version": "..." }
 * One-time pairing code from desktop_tills.php → long till token (shown once).
 */
require __DIR__ . '/_bootstrap.php';
apiRequireMethod('POST');
$body = apiBody();
$r = psDtPair($conn, (string)($body['pairing_code'] ?? ''), (string)($body['machine_name'] ?? ''), (string)($body['app_version'] ?? ''));
if (!$r['ok']) {
    psSecurityAudit($conn, 'DESKTOP_PAIR_FAIL', ['actor' => 'desktop', 'source' => 'desktop', 'summary' => $r['error'], 'target_type' => 'desktop_till']);
    apiFail($r['error'], 400, 'pairing');
}
psSecurityAudit($conn, 'DESKTOP_PAIRED', ['actor' => 'desktop', 'source' => 'desktop', 'summary' => 'Till ' . $r['till']['till_code'] . ' paired on ' . $r['till']['machine_name'], 'target_type' => 'desktop_till', 'target_id' => (string)$r['till']['id']]);
apiRespond(['success' => true, 'token' => $r['token'], 'till' => psDtPublicTill($r['till']), 'server_time' => date('Y-m-d H:i:s')]);
