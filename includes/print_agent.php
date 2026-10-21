<?php
/**
 * Shared-token gate for the desktop print agent (get_receipt.php / printed.php).
 * Token: env PS_PRINT_AGENT_TOKEN or 'print_agent_token' in the private pockets_config.php.
 */
require_once __DIR__ . '/ps_config_bootstrap.php';

function psPrintAgentRequireToken(): void
{
    $expected = psConfigValue('print_agent_token', '');
    $given = (string)($_SERVER['HTTP_X_PRINT_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
    if ($expected === '' || strlen($expected) < 16 || $given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'forbidden']);
        exit;
    }
}
