<?php
require_once __DIR__ . '/../../lib/configuration_authorization.php';

$uuid = 'd73cf052-5818-48d8-adc6-362f2c2dbd42';
$locked = array('luks' => true, 'uuid' => $uuid, 'mount' => '',
    'mapper_names' => array(), 'mapper_mounts' => array());
$unlocked = $locked;
$unlocked['mapper_names'] = array('edm_test');
$mounted = $unlocked;
$mounted['mapper_mounts'] = array('/mnt/test');
$multiMount = $mounted;
$multiMount['mapper_mounts'][] = '/mnt/another';
$directMount = $locked;
$directMount['mount'] = '/mnt/test';
$unknown = $locked;
unset($unknown['mapper_mounts']);
$other = $mounted;
$other['uuid'] = '11111111-1111-1111-1111-111111111111';

$cases = array(
    array('Disconnected registration', array(), true),
    array('Locked disk', array($locked), true),
    array('Unlocked unmounted disk', array($unlocked), true),
    array('Mounted disk', array($mounted), false),
    array('Multiple mount paths', array($multiMount), false),
    array('Direct partition mount', array($directMount), false),
    array('Unknown mount state', array($unknown), false),
    array('Duplicate LUKS UUID', array($locked, $locked), false),
    array('Unrelated mounted disk', array($locked, $other), true),
    array('Unavailable inventory', null, false)
);
$failed = false;
foreach ($cases as $case) {
    list($label, $devices, $expected) = $case;
    $ok = edm_registration_can_delete($uuid, $devices) === $expected;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) $failed = true;
}
exit($failed ? 1 : 0);
