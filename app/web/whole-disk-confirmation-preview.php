<?php
/**
 * Authenticated confirmation-format preview only.
 * Never selects, opens, formats or otherwise operates on a disk.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/privileged_web_authorization.php';
require_once __DIR__ . '/../lib/whole_usb_disk_confirmation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

try {
    edm_auth_start_session();
    $postedCsrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    $expectedCsrf = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null;
    if (!edm_privileged_web_request_authorized(
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        edm_auth_is_https(), edm_auth_is_authenticated(), $expectedCsrf, $postedCsrf)) {
        http_response_code(403);
        echo json_encode(array('confirmed' => false, 'execution_enabled' => false));
        exit;
    }
    $accepted = isset($_POST['warning_accepted'])
        && $_POST['warning_accepted'] === 'yes';
    $phrase = isset($_POST['confirmation_phrase']) && is_string($_POST['confirmation_phrase'])
        ? $_POST['confirmation_phrase'] : null;
    $confirmed = edm_whole_disk_erase_confirmed($accepted, $phrase);
    http_response_code($confirmed ? 200 : 400);
    echo json_encode(array('confirmed' => $confirmed, 'execution_enabled' => false));
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(array('confirmed' => false, 'execution_enabled' => false));
}
