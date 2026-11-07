<?php
require_once __DIR__ . '/../../lib/luks_verified_transition.php';
function check_transition($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$closed = array('uuid' => '12345678-1234-1234-1234-123456789abc',
    'name' => 'sdb1', 'dev_id' => '8:17', 'direct_mounted' => false,
    'mapper_open' => false, 'mapper_name' => null, 'mapper_device' => null,
    'mapper_dev_id' => null, 'mapper_mounted' => null);
$open = $closed;
$open['mapper_open'] = true;
$open['mapper_name'] = 'edm_backup';
$open['mapper_device'] = 'dm-2';
$open['mapper_dev_id'] = '253:2';
$open['mapper_mounted'] = false;
$trusted = function () { return '/usr/sbin/cryptsetup'; };
$calls = 0;
$execute = function ($plan) use (&$calls) {
    ++$calls;
    return $plan['argv'][1] === 'luksOpen';
};
check_transition(edm_luks_verified_transition('unlock', $closed, 'edm_backup',
    $execute, function () use ($open) { return $open; }, $trusted),
    'Unlock requires success and expected postcondition');
check_transition($calls === 1, 'Valid transition invokes executor once');
check_transition(!edm_luks_verified_transition('unlock', $closed, 'edm_backup',
    $execute, function () use ($closed) { return $closed; }, $trusted),
    'Successful command with unchanged mapper state fails');
$changed = $open;
$changed['dev_id'] = '8:33';
check_transition(!edm_luks_verified_transition('unlock', $closed, 'edm_backup',
    $execute, function () use ($changed) { return $changed; }, $trusted),
    'Replaced device after execution fails');
check_transition(!edm_luks_verified_transition('unlock', $closed, 'edm_backup',
    function () { return false; }, function () use ($open) { return $open; },
    $trusted), 'Failed command is rejected');
$denied = $calls;
check_transition(!edm_luks_verified_transition('lock', $closed, null,
    $execute, function () use ($closed) { return $closed; }, $trusted)
    && $calls === $denied, 'Unauthorized Lock does not execute');
check_transition(edm_luks_verified_transition('lock', $open, null,
    function ($plan) { return $plan['argv'][1] === 'luksClose'; },
    function () use ($closed) { return $closed; }, $trusted),
    'Lock requires mapper disappearance');
check_transition(!edm_luks_verified_transition('encrypt', $closed, null,
    $execute, function () use ($open) { return $open; }, $trusted),
    'Destructive operation rejected');
