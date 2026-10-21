<?php
require 'pockets_config.php';
require 'session_check.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/product_master.php';

$conn = mysqli_connect($servername, $username, $password, $dbname);

if (!$conn) {
    http_response_code(500);
    echo 'Connection failed.';
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

function psExportDownloadName(string $label): string
{
    $label = str_replace(' ', '_', $label);
    $label = preg_replace('/[\\\\\\/:*?"<>|\x00-\x1F]/', '', $label);
    $label = str_replace('..', '', (string)$label);
    $label = trim($label, '.');
    if ($label === '') {
        $label = 'export';
    }
    return 'items_' . $label . '.xls';
}

function psExportReject(int $code, string $message): void
{
    http_response_code($code);
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    psExportReject(400, 'Select a branch before exporting.');
}

if (!psCsrfValidate()) {
    psExportReject(403, 'Invalid security token. Reload and try again.');
}

$rawScope = $_POST['export_branch'] ?? null;
if (!is_string($rawScope) || trim($rawScope) === '') {
    psExportReject(400, 'Select a branch before exporting.');
}
$scope = trim($rawScope);
$allToken = '__all__';

$allowed = [];
foreach (psAuthorisedProductBranches($conn) as $name) {
    $name = trim((string)$name);
    if ($name !== '') {
        $allowed[$name] = $name;
    }
}

$branch = null;
if ($scope === $allToken) {
    if (!psSessionAllowsAllProductBranches()) {
        psExportReject(403, 'That branch is not available.');
    }
    $filename = psExportDownloadName('all_branches');
} elseif (!isset($allowed[$scope])) {
    psExportReject(400, 'That branch is not available.');
} else {
    $branch = $allowed[$scope];
    $filename = psExportDownloadName($branch);
}

if ($branch === null) {
    $result = mysqli_query($conn, 'SELECT * FROM goods');
} else {
    $stmt = mysqli_prepare($conn, 'SELECT * FROM goods WHERE branch = ?');
    if (!$stmt) {
        psExportReject(500, 'Items could not be exported.');
    }
    mysqli_stmt_bind_param($stmt, 's', $branch);
    if (!mysqli_stmt_execute($stmt)) {
        psExportReject(500, 'Items could not be exported.');
    }
    $result = mysqli_stmt_get_result($stmt);
}

if (!$result) {
    psExportReject(500, 'Items could not be exported.');
}

http_response_code(200);
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Product-master columns only. Category and Button Category are independent — never merge/swap.
// Branch is informational on re-import; Status is exported for reference and is not applied on import.
echo implode("\t", psProductMasterExportHeaders()) . "\n";

while ($row = mysqli_fetch_assoc($result)) {
    echo implode("\t", psProductMasterExportRow($row)) . "\n";
}

if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    mysqli_stmt_close($stmt);
}
mysqli_close($conn);
