<?php
/**
 * POST { "events": [ {type, client_uuid, ...}, ... ] }  (oldest first, max 200)
 * types: session_open, sale, cash_movement, session_close.
 * Returns one result per event: ok | duplicate (already synced — same answer as before)
 * | retry (try again later) | rejected (kept on the till, shown on Sync Differences).
 */
require __DIR__ . '/_bootstrap.php';
apiRequireMethod('POST');
$till = apiTill($conn);
$body = apiBody();
$events = $body['events'] ?? null;
if (!is_array($events) || !array_is_list($events)) {
    apiFail('events must be a list.');
}
if (count($events) > 200) {
    apiFail('Send at most 200 events per request.', 413);
}
$results = [];
foreach ($events as $ev) {
    $results[] = psDtProcessEvent($conn, $till, is_array($ev) ? $ev : []);
}
apiRespond(['success' => true, 'results' => $results, 'server_time' => date('Y-m-d H:i:s')]);
