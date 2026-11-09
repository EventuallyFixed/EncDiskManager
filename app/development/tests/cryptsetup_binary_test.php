<?php
require_once __DIR__ . '/../../lib/cryptsetup_binary.php';
function check_binary($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
check_binary(edm_resolve_cryptsetup_binary(array('/tmp/cryptsetup')) === null,
    'Reject untrusted binary location');
check_binary(edm_resolve_cryptsetup_binary(array()) === null,
    'Reject empty candidate list');
$plan = array('action' => 'unlock',
    'argv' => array('/usr/sbin/cryptsetup', 'luksOpen', '/dev/sdb1', 'edm_backup'));
$bound = edm_bind_cryptsetup_binary($plan, '/usr/sbin/cryptsetup');
check_binary($bound !== null && $bound['argv'][0] === '/usr/sbin/cryptsetup'
    && $plan['argv'][0] === '/usr/sbin/cryptsetup',
    'Bind fixed binary without mutating original plan');
check_binary(edm_bind_cryptsetup_binary($plan, '/tmp/cryptsetup') === null,
    'Reject arbitrary binary override');
$bad = $plan;
$bad['argv'][1] = 'luksFormat';
check_binary(edm_bind_cryptsetup_binary($bad, '/usr/sbin/cryptsetup') === null,
    'Reject destructive cryptsetup subcommand');
$bad = $plan;
$bad['action'] = 'lock';
check_binary(edm_bind_cryptsetup_binary($bad, '/usr/sbin/cryptsetup') === null,
    'Reject action/subcommand mismatch');

check_binary(edm_bind_cryptsetup_binary($plan, '/sbin/cryptsetup') === null,
    'Reject legacy fallback executable path');

// Resolution must use the same full-path trust policy as subprocess execution.
$trusted = edm_trusted_operation_binary('/usr/sbin/cryptsetup');
$resolved = edm_resolve_cryptsetup_binary(array('/usr/sbin/cryptsetup'));
check_binary(($resolved === '/usr/sbin/cryptsetup') === $trusted,
    'Resolver agrees with executable and parent-directory trust checks');
check_binary(edm_resolve_cryptsetup_binary(array('/bin/mount')) === null,
    'Cryptsetup resolver rejects a different trusted executable');
