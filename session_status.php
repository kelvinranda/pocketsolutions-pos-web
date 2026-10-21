<?php
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/till_session.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);
$wantJson = isset($_GET['format']) && $_GET['format'] === 'json';
if (!$conn) {
    if ($wantJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['open' => false, 'label' => 'Till: Not Open']);
        exit;
    }
    echo 'Could not read the till session.';
    exit;
}

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(tillStatusPayload($conn));
} else {
    echo tillStatusText($conn);
}
mysqli_close($conn);
