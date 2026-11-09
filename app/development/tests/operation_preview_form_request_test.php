<?php
require_once __DIR__ . '/../../lib/operation_preview_form_request.php';
function check_preview_form($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$valid = array('action' => 'unlock', 'luks_uuid' => strtoupper($uuid),
    'csrf_token' => str_repeat('a', 64));
$parsed = edm_operation_preview_form_request($valid);
check_preview_form(is_array($parsed) && $parsed['luks_uuid'] === $uuid
    && $parsed['action'] === 'unlock', 'Exact form parses and normalizes UUID');
foreach (array('unlock', 'mount', 'unmount', 'lock') as $action) {
    $form = $valid;
    $form['action'] = $action;
    check_preview_form(edm_operation_preview_form_request($form) !== null,
        'Fixed action accepted: ' . $action);
}
foreach (array('encrypt', 'format', 'delete', 'luksOpen', '') as $action) {
    $form = $valid;
    $form['action'] = $action;
    check_preview_form(edm_operation_preview_form_request($form) === null,
        'Unknown or destructive action rejected: ' . $action);
}
foreach (array('device', 'mountpoint', 'mapper', 'argv', 'command',
    'keyfile', 'passphrase', 'sudo', 'timeout') as $field) {
    $form = $valid;
    $form[$field] = '/dev/sda';
    check_preview_form(edm_operation_preview_form_request($form) === null,
        'Unexpected form field rejected: ' . $field);
}
foreach (array('action', 'luks_uuid', 'csrf_token') as $field) {
    $form = $valid;
    unset($form[$field]);
    check_preview_form(edm_operation_preview_form_request($form) === null,
        'Missing required field rejected: ' . $field);
}
foreach (array(null, array('a'), 42, 'string') as $bad) {
    check_preview_form(edm_operation_preview_form_request($bad) === null,
        'Non-form input rejected');
}
$form = $valid;
$form['csrf_token'] = array('a');
check_preview_form(edm_operation_preview_form_request($form) === null,
    'Array-valued CSRF rejected');
$form = $valid;
$form['luks_uuid'] = array($uuid);
check_preview_form(edm_operation_preview_form_request($form) === null,
    'Array-valued UUID rejected');
$form = $valid;
$form['action'] = array('unlock');
check_preview_form(edm_operation_preview_form_request($form) === null,
    'Array-valued action rejected');
