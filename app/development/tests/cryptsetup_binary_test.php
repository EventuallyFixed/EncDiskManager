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
$bound = edm_bind_cryptsetup_binary($plan, '/sbin/cryptsetup');
check_binary($bound !== null && $bound['argv'][0] === '/sbin/cryptsetup'
    && $plan['argv'][0] === '/usr/sbin/cryptsetup',
    'Bind fixed binary without mutating original plan');
check_binary(edm_bind_cryptsetup_binary($plan, '/tmp/cryptsetup') === null,
    'Reject arbitrary binary override');
$bad = $plan;
$bad['argv'][1] = 'luksFormat';
check_binary(edm_bind_cryptsetup_binary($bad, '/sbin/cryptsetup') === null,
    'Reject destructive cryptsetup subcommand');
$bad = $plan;
$bad['action'] = 'lock';
check_binary(edm_bind_cryptsetup_binary($bad, '/sbin/cryptsetup') === null,
    'Reject action/subcommand mismatch');
