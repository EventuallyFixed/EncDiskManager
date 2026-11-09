<?php
/**
 * Authenticated, read-only operation eligibility preview.
 * This endpoint NEVER invokes a disk executor, helper or shell command.
 * All client input is restricted to a fixed action and LUKS UUID.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/authorized_operation_inspection.php';
require_once __DIR__ . '/../lib/operation_preview_form_request.php';

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
    $input = edm_operation_preview_form_request($_POST);
    if ($input === null) {
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
