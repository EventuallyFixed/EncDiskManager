<?php
/**
 * Compose web authorization, fixed request validation, registry ownership,
 * and live disk-state inspection. NOT a web endpoint or privileged executor.
 *
 * The caller must derive $authenticated from edm_auth_is_authenticated(),
 * $https from edm_auth_is_https(), and $expectedCsrf from its server session.
 * Never take those trusted values from POST data.
 *
 * The inspector is for read-only decisions. No commands or secrets are passed.
 */
require_once __DIR__ . '/privileged_web_authorization.php';
require_once __DIR__ . '/registered_operation_inspection.php';

function edm_with_authorized_operation_inspection($dataDirectory, $method,
    $https, $authenticated, $expectedCsrf, $postedCsrf, $input, $inspect,
    $discoverUsb = null, $discoverMappers = null, $readMounts = null)
{
    if (!edm_privileged_web_request_authorized($method, $https,
        $authenticated, $expectedCsrf, $postedCsrf)) return false;
    if (edm_privileged_operation_request($input) === null
        || !is_callable($inspect)) return false;
    return edm_with_registered_operation_inspection($dataDirectory, $input,
        $inspect, $discoverUsb, $discoverMappers, $readMounts);
}
