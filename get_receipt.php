<?php
/**
 * Print-agent poll: returns the oldest pending receipt text from print_jobs.
 *
 * Health-check fix 2026-10-13: previously readable by anyone. Now requires the shared
 * print-agent token (env PS_PRINT_AGENT_TOKEN or 'print_agent_token' in the private
 * pockets_config.php). Send it as header "X-Print-Token: <token>" (or ?token=).
 * With no token configured the endpoint is closed.
 */
ini_set('display_errors', '0');
require __DIR__ . '/pockets_config.php';
require_once __DIR__ . '/includes/print_agent.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

psPrintAgentRequireToken();

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(503);
    echo json_encode(['status' => 'error']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

$result = mysqli_query($conn, "SELECT id, receipt FROM print_jobs WHERE status = 'Pending' ORDER BY id LIMIT 1");
if ($result && ($row = mysqli_fetch_assoc($result))) {
    echo json_encode([
        'status' => 'success',
        'id' => $row['id'],
        'text' => $row['receipt'],
    ]);
} else {
    echo json_encode(['status' => 'empty']);
}
mysqli_close($conn);
