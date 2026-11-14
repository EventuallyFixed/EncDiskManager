<?php
/**
 * Authenticated confirmation-format preview only.
 * Never selects, opens, formats or otherwise operates on a disk.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/whole_disk_confirmation_preview_decision.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

try {
    edm_auth_start_session();
    $decision = edm_whole_disk_confirmation_preview_decision(
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        edm_auth_is_https(), edm_auth_is_authenticated(),
        isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null, $_POST);
    http_response_code($decision['status']);
    echo json_encode($decision['body']);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(array('confirmed' => false, 'execution_enabled' => false));
}
