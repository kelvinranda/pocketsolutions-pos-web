<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require 'pockets_config.php';
require_once __DIR__ . '/includes/stock_movement.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);

if (!$conn) {
    die('Connection failed.');
}

require_once __DIR__ . '/includes/branch_access.php';
psBranchRequireAuthRedirect();
$br = psBranchResolveWorking($conn);
$branch = !empty($br['ok']) ? (string)$br['branch'] : '';
if ($branch !== '') {
    if (!psSaveStockListCache($conn, $branch)) {
        error_log('stock list was not saved');
        die('The stock list could not be calculated.');
    }
}

header('location:stock_list.php');
