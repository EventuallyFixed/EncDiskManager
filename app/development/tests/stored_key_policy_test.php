<?php
require_once __DIR__ . '/../../lib/stored_key_policy.php';
function check_key_policy($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
check_key_policy(edm_stored_key_policy('/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager',
    $uuid, true, false) === null, 'Unverified recovery rejected');
$p = edm_stored_key_policy('/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager',
    $uuid, true, true);
check_key_policy(is_array($p) && $p['backup_excluded'] === true
    && $p['file_mode'] === 0600 && $p['directory_mode'] === 0700,
    'Private key storage and backup exclusion required');
check_key_policy($p['key_path'] ===
    '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager/keys/12345678123412341234123456789abc.key',
    'Key path derived only from validated UUID');
check_key_policy(edm_stored_key_policy('/tmp/../etc',
    $uuid, true, true) === null, 'Unsafe data directory rejected');
check_key_policy(edm_stored_key_policy('/tmp',
    '../etc', true, true) === null, 'Path injection rejected');
