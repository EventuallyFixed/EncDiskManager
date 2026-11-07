<?php
require_once __DIR__ . '/../../lib/encryption_confirmation.php';
function check_confirm($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$valid = array('acknowledge_data_loss' => true, 'confirmation' =>
    'Are you sure? Your data will be permanently lost!', 'luks_uuid' => $uuid);
check_confirm(edm_encryption_confirmation($valid, $uuid, 0),
    'Both confirmations accepted at first attempt');
check_confirm(edm_encryption_confirmation($valid, $uuid, 2),
    'Third manual attempt permitted');
check_confirm(!edm_encryption_confirmation($valid, $uuid, 3),
    'Fourth attempt rejected');
$bad = $valid;
$bad['acknowledge_data_loss'] = false;
check_confirm(!edm_encryption_confirmation($bad, $uuid, 0),
    'Missing first confirmation rejected');
$bad = $valid;
$bad['confirmation'] = 'yes';
check_confirm(!edm_encryption_confirmation($bad, $uuid, 0),
    'Wrong exact second confirmation rejected');
$bad = $valid;
$bad['luks_uuid'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
check_confirm(!edm_encryption_confirmation($bad, $uuid, 0),
    'Wrong target UUID rejected');
$bad = $valid;
$bad['device'] = '/dev/sda';
check_confirm(!edm_encryption_confirmation($bad, $uuid, 0),
    'Client device path rejected');
