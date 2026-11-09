<?php
/**
 * Guard the production preview against accidental test authentication hooks.
 * These assertions do not replace authenticated end-to-end HTTP testing.
 */
function check_preview_boundary($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$path = __DIR__ . '/../../web/operation-preview.php';
$source = file_get_contents($path);
check_preview_boundary(is_string($source), 'Read production endpoint');
foreach (array(
    'edm_auth_start_session()' => 'Starts the real administrator session',
    'edm_auth_is_https()' => 'Derives HTTPS from server context',
    'edm_auth_is_authenticated()' => 'Uses the real administrator check',
    "\$_SESSION['csrf_token']" => 'Reads the session CSRF secret',
    'edm_operation_preview_decision(' => 'Applies strict form and HTTP decision policy',
    'edm_with_authorized_operation_inspection(' => 'Uses combined locked inspection',
    "'execution_enabled' => false" => 'Execution is always disabled'
) as $needle => $label) {
    check_preview_boundary(strpos($source, $needle) !== false, $label);
}
foreach (array('getenv(', 'putenv(', 'EDM_TEST_AUTH', 'test_authenticated',
    'HTTP_X_TEST_AUTH', 'edm_auth_login(', 'edm_registry_save(',
    'proc_open(', 'shell_exec(', 'passthru(', 'system(', 'eval(') as $needle) {
    check_preview_boundary(strpos($source, $needle) === false,
        'No authentication bypass, mutation or command primitive: ' . $needle);
}
