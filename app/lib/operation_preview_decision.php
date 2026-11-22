<?php
/**
 * Pure decision policy for the read-only operation preview response.
 * The caller supplies trusted server-derived authentication facts.
 * This never invokes a privileged operation or reads a device.
 */
require_once __DIR__ . '/privileged_web_authorization.php';
require_once __DIR__ . '/operation_preview_form_request.php';

function edm_operation_preview_decision($method, $https, $authenticated,
    $expectedCsrf, $post, $inspectEligibility)
{
    $postedCsrf = is_array($post) && array_key_exists('csrf_token', $post)
        ? $post['csrf_token'] : null;
    $denied = array('eligible' => false, 'execution_enabled' => false);
    if (!edm_privileged_web_request_authorized($method, $https,
        $authenticated, $expectedCsrf, $postedCsrf)) {
        return array('status' => 403, 'body' => $denied);
    }
    $request = edm_operation_preview_form_request($post);
    if ($request === null) return array('status' => 400, 'body' => $denied);
    if (!is_callable($inspectEligibility)) {
        return array('status' => 503, 'body' => $denied);
    }
    try {
        $eligible = call_user_func($inspectEligibility, $request) === true;
    } catch (Throwable $exception) {
        return array('status' => 503, 'body' => $denied);
    }
    return array('status' => 200, 'body' => array(
        'eligible' => $eligible, 'execution_enabled' => false
    ));
}
