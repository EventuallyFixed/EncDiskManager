<?php
require_once __DIR__ . '/../../lib/luks_command_plan.php';
function assert_plan($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$state = array('uuid' => '12345678-1234-1234-1234-123456789abc',
    'name' => 'sdb1', 'dev_id' => '8:17', 'mapper_open' => false);
$unlock = edm_luks_command_plan('unlock', $state, 'edm_backup');
assert_plan($unlock !== null && $unlock['argv'][1] === 'luksOpen'
    && $unlock['argv'][2] === '/dev/sdb1'
    && $unlock['target_dev_id'] === '8:17', 'Unlock plan binds device identity');
assert_plan(edm_luks_command_plan('unlock', $state, 'other_mapper') === null,
    'Reject unmanaged mapper name');
$injected = $state;
$injected['name'] = 'sdb1;touch /tmp/unsafe';
assert_plan(edm_luks_command_plan('unlock', $injected, 'edm_backup') === null,
    'Reject shell metacharacters in device name');
$injected = $state;
$injected['mapper_open'] = true;
assert_plan(edm_luks_command_plan('unlock', $injected, 'edm_backup') === null,
    'Reject unlock of open mapper');
$open = $state;
$open['mapper_open'] = true;
$open['mapper_name'] = 'edm_backup';
$open['mapper_device'] = 'dm-2';
$open['mapper_dev_id'] = '253:2';
$open['mapper_mounted'] = false;
$lock = edm_luks_command_plan('lock', $open);
assert_plan($lock !== null && $lock['argv'][1] === 'luksClose'
    && $lock['mapper_dev_id'] === '253:2', 'Lock plan binds mapper kernel identity');
$mounted = $open;
$mounted['mapper_mounted'] = true;
assert_plan(edm_luks_command_plan('lock', $mounted) === null,
    'Reject close of mounted mapper');
$unknown = $open;
unset($unknown['mapper_mounted']);
assert_plan(edm_luks_command_plan('lock', $unknown) === null,
    'Reject close when mount state unknown');
assert_plan(edm_luks_command_plan('encrypt', $state) === null,
    'Destructive action has no command plan');
