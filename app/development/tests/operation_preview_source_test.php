<?php
/**
 * Guard against accidentally wiring the read-only endpoint to execution.
 * This is a source-level safety regression, not a substitute for HTTP tests.
 */
function check_preview_source($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$source = file_get_contents(__DIR__ . '/../../web/operation-preview.php');
check_preview_source(is_string($source), 'Preview endpoint source available');
check_preview_source(strpos($source, 'edm_auth_start_session()') !== false
    && strpos($source, 'edm_privileged_web_request_authorized(') !== false,
    'Session and web authorization required');
check_preview_source(strpos($source, 'edm_with_authorized_operation_inspection(') !== false,
    'Registry and live-state inspection required');
foreach (array('proc_open(', 'shell_exec(', 'system(', 'passthru(', 'exec(',
    'edm_luks_subprocess(', 'edm_mount_subprocess(') as $unsafe) {
    check_preview_source(strpos($source, $unsafe) === false,
        'Preview contains no direct executor: ' . $unsafe);
}
check_preview_source(strpos($source, "'execution_enabled' => false") !== false,
    'Preview explicitly reports execution disabled');
