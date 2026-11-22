<?php
/**
 * Pure web authorization gate for a future operation endpoint.
 * No session start, registry reads, or execution here. The caller must
 * establish a valid authenticated session before using this result.
 */
function edm_privileged_web_request_authorized($method, $https, $authenticated,
    $expectedCsrf, $postedCsrf)
{
    return $method === 'POST'
        && $https === true
        && $authenticated === true
        && is_string($expectedCsrf)
        && preg_match('/^[a-f0-9]{64}$/D', $expectedCsrf) === 1
        && is_string($postedCsrf)
        && strlen($postedCsrf) === 64
        && hash_equals($expectedCsrf, $postedCsrf);
}
