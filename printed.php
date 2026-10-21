<?php
/**
 * Print-agent acknowledgement: marks one pending print job as printed.
 *
 * Health-check fix 2026-10-13: was disabled (unauthenticated GET writer), which left the
 * print agent re-printing the same job. Re-enabled behind the same print-agent token as
 * get_receipt.php. Accepts id via POST (preferred) or GET; replies "OK" like the original endpoint.
 */
ini_set('display_errors', '0');
require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/print_agent.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

psPrintAgentRequireToken();

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'ERROR';
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(503);
    echo 'ERROR';
    exit;
}
$stmt = mysqli_prepare($conn, "UPDATE print_jobs SET status = 'Printed' WHERE id = ? AND status = 'Pending'");
$ok = false;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
mysqli_close($conn);
// Same reply as the original agent contract.
echo $ok ? 'OK' : 'ERROR';
