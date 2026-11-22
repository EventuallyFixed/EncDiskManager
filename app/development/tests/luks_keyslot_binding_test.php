<?php
require_once __DIR__ . '/../../lib/luks_keyslot_binding.php';
function binding_check($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$p = edm_luks_keyslot_plan('add-key', 'sdb1');
$b = edm_luks_keyslot_bind_private_key($p, '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager', $uuid);
binding_check(is_array($b) && count($b['argv']) === 8
    && $b['argv'][7] === '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager/keys/12345678123412341234123456789abc.key',
    'Private key path derived from UUID');
binding_check(edm_luks_keyslot_bind_private_key(
    edm_luks_keyslot_plan('verify-recovery', 'sdb1'),
    '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager', $uuid) === null,
    'Verification command cannot be rebound as add-key');
$forged = $p;
$forged['argv'][6] = '/dev/../../etc/passwd';
binding_check(edm_luks_keyslot_bind_private_key($forged,
    '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager', $uuid) === null,
    'Unsafe device path rejected');
$forged = $p;
$forged['argv'][7] = '/tmp/key';
binding_check(edm_luks_keyslot_bind_private_key($forged,
    '/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager', $uuid) === null,
    'Client-provided key path rejected');

binding_check(edm_luks_keyslot_private_file_ready('/tmp', $uuid) === false,
    'Public temporary directory cannot hold an accepted private key');
binding_check(edm_luks_keyslot_private_file_ready('/does-not-exist', $uuid) === false,
    'Missing private key rejected');
