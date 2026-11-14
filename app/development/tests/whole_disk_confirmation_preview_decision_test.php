<?php
require_once __DIR__ . '/../../lib/whole_disk_confirmation_preview_decision.php';
function check_confirmation_preview($actual, $expected, $label) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
$token = str_repeat('a', 64);
$valid = array('csrf_token' => $token, 'warning_accepted' => 'yes',
    'confirmation_phrase' => 'ERASE ALL DATA');
$decide = function ($method, $https, $authenticated, $csrf, $post) {
    return edm_whole_disk_confirmation_preview_decision(
        $method, $https, $authenticated, $token, $post);
};
$ok = $decide('POST', true, true, $token, $valid);
check_confirmation_preview($ok['status'], 200, 'Valid confirmation accepted');
check_confirmation_preview($ok['body']['confirmed'], true, 'Confirmation reported valid');
check_confirmation_preview($ok['body']['execution_enabled'], false, 'Execution always disabled');
foreach (array(
    array('GET', true, true, $valid, 403),
    array('POST', false, true, $valid, 403),
    array('POST', true, false, $valid, 403),
    array('POST', true, true, array_merge($valid, array('csrf_token' => 'wrong')), 403),
    array('POST', true, true, array_merge($valid, array('warning_accepted' => 'no')), 400),
    array('POST', true, true, array_merge($valid, array('confirmation_phrase' => 'erase all data')), 400),
    array('POST', true, true, array('csrf_token' => $token), 400)
) as $case) {
    $result = $decide($case[0], $case[1], $case[2], $token, $case[3]);
    check_confirmation_preview($result['status'], $case[4], 'Invalid request rejected');
    check_confirmation_preview($result['body']['execution_enabled'], false, 'Rejected request cannot execute');
}
