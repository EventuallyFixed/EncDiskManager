<?php
require_once __DIR__ . '/../../lib/privileged_operation_request.php';
function check_privileged_request($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789ABC';
foreach (array('unlock', 'mount', 'unmount', 'lock') as $action) {
    $parsed = edm_privileged_operation_request(array(
        'action' => $action, 'luks_uuid' => $uuid));
    check_privileged_request(is_array($parsed) && $parsed['action'] === $action
        && $parsed['luks_uuid'] === strtolower($uuid),
        'Accept fixed operation and normalize UUID: ' . $action);
}
foreach (array('encrypt', 'format', 'luksFormat', 'delete', 'unlock;id', '') as $action) {
    check_privileged_request(edm_privileged_operation_request(array(
        'action' => $action, 'luks_uuid' => $uuid)) === null,
        'Reject unsupported operation');
}
foreach (array('device', 'mountpoint', 'mapper', 'argv', 'keyfile', 'passphrase',
    'command', 'timeout', 'sudo') as $field) {
    check_privileged_request(edm_privileged_operation_request(array(
        'action' => 'unlock', 'luks_uuid' => $uuid, $field => 'unsafe')) === null,
        'Reject extra request field: ' . $field);
}
foreach (array('/dev/sdb1', '../etc/passwd', '', 'not-a-uuid',
    '12345678-1234-1234-1234-123456789abc;id') as $bad) {
    check_privileged_request(edm_privileged_operation_request(array(
        'action' => 'lock', 'luks_uuid' => $bad)) === null,
        'Reject invalid target identifier');
}
check_privileged_request(edm_privileged_operation_request(array(
    'action' => 'lock')) === null, 'Reject missing UUID');
check_privileged_request(edm_privileged_operation_request('lock') === null,
    'Reject non-object request');
