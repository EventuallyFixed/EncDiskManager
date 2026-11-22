<?php
require_once __DIR__ . '/../../lib/privileged_web_authorization.php';
function check_web_gate($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$csrf = str_repeat('a', 64);
$valid = function ($method, $https, $authenticated, $expected, $posted) {
    return edm_privileged_web_request_authorized(
        $method, $https, $authenticated, $expected, $posted);
};
check_web_gate($valid('POST', true, true, $csrf, $csrf),
    'Authenticated HTTPS POST with matching CSRF allowed');
check_web_gate(!$valid('GET', true, true, $csrf, $csrf),
    'GET rejected');
check_web_gate(!$valid('POST', false, true, $csrf, $csrf),
    'HTTP rejected');
check_web_gate(!$valid('POST', true, false, $csrf, $csrf),
    'Unauthenticated session rejected');
check_web_gate(!$valid('POST', true, true, $csrf, str_repeat('b', 64)),
    'Wrong CSRF rejected');
check_web_gate(!$valid('POST', true, true, $csrf, ''),
    'Missing CSRF rejected');
check_web_gate(!$valid('POST', true, true, 'invalid', 'invalid'),
    'Malformed session CSRF rejected');
check_web_gate(!$valid('POST', true, true, $csrf, array($csrf)),
    'Array-valued CSRF rejected');
check_web_gate(!$valid('POST', true, true, $csrf, $csrf . 'x'),
    'Overlong CSRF rejected');
