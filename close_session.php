<?php
/**
 * Legacy POS till-close endpoint (ajax_pos close button).
 * Phase 7.4: unprotected CLOSE removed — point cashiers to controlled close UI.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/till_session.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo 'Could not close the till session.';
    exit;
}

$row = getActiveTillSession($conn);
mysqli_close($conn);

if (!$row) {
    echo 'There is no open till session.';
    exit;
}

echo 'Till closure requires a current final cash reconciliation. Open Cash Till Close (cash_till_close.php) to finish.';
