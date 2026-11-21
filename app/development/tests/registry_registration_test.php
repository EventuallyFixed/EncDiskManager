<?php
require_once __DIR__ . '/../../lib/registry.php';
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "Registration persistence tests require root in an isolated environment.\n";
    exit(1);
}
$dir = sys_get_temp_dir() . '/edm-registration-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) exit(1);
$path = $dir . '/disks.json';
$a = '11111111-1111-1111-1111-111111111111';
$b = '22222222-2222-2222-2222-222222222222';
$devices = array(array('luks' => true, 'uuid' => $a), array('luks' => true, 'uuid' => $b));
$failed = false;
function registration_check($condition, $label) {
    global $failed;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) $failed = true;
}
function registration_reject($callback, $label) {
    try { $callback(); registration_check(false, $label); }
    catch (RuntimeException $e) { registration_check(true, $label); }
}
try {
    edm_registry_register($path, $a, 'First disk', 'first', $devices);
    $first = edm_registry_load($path);
    registration_check(count($first['disks']) === 1 && $first['disks'][0]['mapper_name'] === edm_registry_mapper_name($a), 'Registration survives reload');
    registration_reject(function () use ($path, $a, $devices) { edm_registry_register($path, $a, 'Duplicate', 'other', $devices); }, 'Duplicate UUID rejected');
    registration_reject(function () use ($path, $b, $devices) { edm_registry_register($path, $b, 'Second', 'FIRST', $devices); }, 'Duplicate folder rejected');
    registration_reject(function () use ($path, $b) { edm_registry_register($path, $b, 'Missing', 'missing', array()); }, 'Absent USB identity rejected');
    registration_reject(function () use ($path, $b) { $d = array('luks' => true, 'uuid' => $b); edm_registry_register($path, $b, 'Ambiguous', 'ambiguous', array($d, $d)); }, 'Ambiguous UUID rejected');
    registration_reject(function () use ($path, $b, $devices) { edm_registry_register($path, $b, 'Invalid', '../unsafe', $devices); }, 'Unsafe folder rejected');
    registration_check(edm_registry_load($path) === $first, 'Rejected requests preserve registry');
    edm_registry_register($path, $b, 'Second disk', 'second', $devices);
    $both = edm_registry_load($path);
    registration_check(count($both['disks']) === 2 && $both['disks'][0] === $first['disks'][0], 'Second registration preserves first');
} finally {
    foreach (array($path, $path . '.lock') as $file) {
        if (file_exists($file)) unlink($file);
    }
    rmdir($dir);
}
exit($failed ? 1 : 0);
