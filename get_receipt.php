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

// 2026-10-24: ?doc=RECEIPT or ?doc=PICKING_SLIP lets a second agent drive a separate storeroom printer.
// Without doc every pending job is returned (one printer prints both documents), as before.
require_once __DIR__ . '/includes/picking_slip.php';
$doc = strtoupper(trim((string)($_GET['doc'] ?? '')));
if ($doc !== '' && $doc !== PS_PRINT_DOC_RECEIPT && $doc !== PS_PRINT_DOC_PICKING) {
    http_response_code(400);
    echo json_encode(['status' => 'error']);
    exit;
}
if (psPrintJobsEnsureSchema($conn)) {
    $docSql = $doc !== '' ? " AND document = '" . $doc . "'" : '';
    $result = mysqli_query($conn, "SELECT id, receipt, document, invoice_number FROM print_jobs WHERE status = 'Pending'$docSql ORDER BY id LIMIT 1");
} else {
    $result = $doc === PS_PRINT_DOC_PICKING ? false
        : mysqli_query($conn, "SELECT id, receipt, 'RECEIPT' AS document, invoice_number FROM print_jobs WHERE status = 'Pending' ORDER BY id LIMIT 1");
}
if ($result && ($row = mysqli_fetch_assoc($result))) {
    echo json_encode([
        'status' => 'success',
        'id' => $row['id'],
        'text' => $row['receipt'],
        'document' => $row['document'],
        'invoice_number' => $row['invoice_number'],
    ]);
} else {
    echo json_encode(['status' => 'empty']);
}
mysqli_close($conn);
