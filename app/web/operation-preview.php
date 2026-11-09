<?php
/**
 * Authenticated, read-only operation eligibility preview.
 * This endpoint NEVER invokes a disk executor, helper or shell command.
 * All client input is restricted to a fixed action and LUKS UUID.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/authorized_operation_inspection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

try {
    edm_auth_start_session();
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    $https = edm_auth_is_https();
    $authenticated = edm_auth_is_authenticated();
    $expectedCsrf = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null;
    $postedCsrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!edm_privileged_web_request_authorized($method, $https,
        $authenticated, $expectedCsrf, $postedCsrf)) {
        http_response_code(403);
        echo json_encode(array('eligible' => false, 'execution_enabled' => false));
        exit;
    }
    // Explicit field construction prevents csrf_token and transport metadata
    // from becoming extra fields in the strict operation request.
    $input = array(
        'action' => isset($_POST['action']) ? $_POST['action'] : null,
        'luks_uuid' => isset($_POST['luks_uuid']) ? $_POST['luks_uuid'] : null
    );
    // Reject all other posted fields, even when the two required ones are valid.
    if (count($_POST) !== 3
        || !array_key_exists('csrf_token', $_POST)
        || !array_key_exists('action', $_POST)
        || !array_key_exists('luks_uuid', $_POST)) {
        http_response_code(400);
        echo json_encode(array('eligible' => false, 'execution_enabled' => false));
        exit;
    }
    $eligible = edm_with_authorized_operation_inspection(
        edm_auth_data_path(), $method, $https, $authenticated,
        $expectedCsrf, $postedCsrf, $input,
        function ($request, $state) { return true; }) === true;
    echo json_encode(array('eligible' => $eligible, 'execution_enabled' => false));
} catch (Throwable $exception) {
    // No disk paths, request data, secrets or exception details in the response.
    http_response_code(503);
    echo json_encode(array('eligible' => false, 'execution_enabled' => false));
}
