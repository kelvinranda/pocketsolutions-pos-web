<?php
/**
 * Phase 18 — Super Admin stops managing a company (POST + CSRF) and returns to the console.
 */
require 'session_check.php';
require 'pockets_config.php';
require_once __DIR__ . '/includes/platform_page.php';

$conn = psPlatformPageBoot();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !psCsrfValidate()) {
    header('Location: ' . (psPlatformIsActing() ? 'admin_dashboard.php' : 'platform.php'));
    exit;
}
$a = psPlatformActing();
psPlatformExit($conn);
if ($a) {
    psPlatformFlash('ok', 'You left ' . (string)$a['display'] . '.');
}
header('Location: platform.php');
exit;
