<?php
require_once __DIR__ . '/../../lib/registry.php';

$dir = sys_get_temp_dir() . '/edm-key-preference-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) exit(1);
$path = $dir . '/disks.json';
$uuid = '11111111-1111-1111-1111-111111111111';
$other = '22222222-2222-2222-2222-222222222222';
$failed = false;
function key_preference_check($ok, $label) {
    global $failed;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) $failed = true;
}
try {
    edm_registry_register($path, $uuid, 'First', 'first',
        array(array('luks' => true, 'uuid' => $uuid)));
    edm_registry_register($path, $other, 'Second', 'second',
        array(array('luks' => true, 'uuid' => $other)));
    $initial = edm_registry_load($path);
    key_preference_check(empty($initial['disks'][0]['store_unlock_key']), 'Legacy registration defaults to storage off');
    edm_registry_set_key_preference($path, strtoupper($uuid), true);
    $saved = edm_registry_load($path);
    key_preference_check($saved['disks'][0]['store_unlock_key'] === true, 'Enable preference without connected disk');
    key_preference_check($saved['disks'][1] === $initial['disks'][1], 'Other registration unchanged');
    key_preference_check(edm_registry_decode(edm_registry_encode($saved)) === $saved, 'Preference survives registry encoding and decoding');
    try {
        edm_registry_set_key_preference($path, '33333333-3333-3333-3333-333333333333', true);
        key_preference_check(false, 'Unregistered UUID rejected');
    } catch (RuntimeException $e) {
        key_preference_check(true, 'Unregistered UUID rejected');
    }
    key_preference_check(edm_registry_load($path) === $saved, 'Rejected change leaves registry intact');
    $invalid = $saved;
    $invalid['disks'][0]['store_unlock_key'] = 'true';
    try {
        edm_registry_validate($invalid);
        key_preference_check(false, 'Non-boolean preference rejected');
    } catch (RuntimeException $e) {
        key_preference_check(true, 'Non-boolean preference rejected');
    }
    edm_registry_set_key_preference($path, $uuid, false);
    key_preference_check(edm_registry_load($path) === $initial, 'Disable preference without connected disk');
} finally {
    foreach (array($path, $path . '.lock') as $file) {
        if (file_exists($file)) unlink($file);
    }
    rmdir($dir);
}
exit($failed ? 1 : 0);
