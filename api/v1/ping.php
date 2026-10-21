<?php
/** GET — is the server reachable and is this till still registered? */
require __DIR__ . '/_bootstrap.php';
$till = apiTill($conn);
apiRespond(['success' => true, 'till' => psDtPublicTill($till), 'server_time' => date('Y-m-d H:i:s')]);
