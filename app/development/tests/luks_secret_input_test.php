<?php
require_once __DIR__ . '/../../lib/luks_secret_input.php';
function check_secret($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$plan = array('action' => 'unlock', 'argv' => array(
    '/usr/local/modules/usr/sbin/cryptsetup', 'luksOpen', '/dev/sdb1', 'edm_backup'));
$stdin = edm_luks_stdin_command($plan);
check_secret($stdin !== null && $stdin['argv'] === array(
    '/usr/local/modules/usr/sbin/cryptsetup', 'luksOpen', '--key-file', '-',
    '/dev/sdb1', 'edm_backup'), 'Unlock uses explicit stdin key input');
check_secret($stdin['secret_channel'] === 'stdin'
    && $stdin['secret_in_argv'] === false && $stdin['shell'] === false,
    'No secret in argv and no shell');
$bad = $plan;
$bad['argv'][2] = '/dev/sdb1;id';
check_secret(edm_luks_stdin_command($bad) === null,
    'Reject malformed device path');
$bad = $plan;
$bad['argv'][3] = 'bad name';
check_secret(edm_luks_stdin_command($bad) === null,
    'Reject malformed mapper name');
$bad = $plan;
$bad['argv'][1] = 'luksFormat';
check_secret(edm_luks_stdin_command($bad) === null,
    'Reject destructive command substitution');
$bad = $plan;
$bad['action'] = 'lock';
check_secret(edm_luks_stdin_command($bad) === null,
    'Reject Lock through secret input channel');

$bad = $plan;
$bad['argv'][0] = '/usr/sbin/cryptsetup';
check_secret(edm_luks_stdin_command($bad) === null,
    'Reject EX4100 symlink alias in Unlock stdin plan');
