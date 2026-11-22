<?php
require_once __DIR__ . '/../../lib/luks_restricted_transition.php';
function check_restricted($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$dir = '/path/never/used';
check_restricted(!edm_restricted_luks_transition($dir, $dir . '/disks.json', $uuid, 'encrypt',
    'edm_backup', 'secret'), 'Destructive command rejected before disk access');
check_restricted(!edm_restricted_luks_transition($dir, $dir . '/disks.json', $uuid, 'unlock',
    'edm_backup', null), 'Missing Unlock secret rejected');
check_restricted(!edm_restricted_luks_transition($dir, $dir . '/disks.json', $uuid, 'unlock',
    'edm_backup', str_repeat('x', 4097)), 'Oversized secret rejected');
check_restricted(!edm_restricted_luks_transition($dir, $dir . '/disks.json', $uuid, 'lock',
    null, 'secret'), 'Lock rejects secret input');
check_restricted(!edm_restricted_luks_transition($dir, $dir . '/disks.json', $uuid, 'lock',
    'edm_backup'), 'Lock rejects external mapper selection');
check_restricted(!edm_restricted_luks_transition($dir, $dir . '/disks.json', $uuid, 'unlock',
    'edm_backup', 'secret', 0), 'Invalid timeout rejected before disk access');
