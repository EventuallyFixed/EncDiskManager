<?php
require_once __DIR__ . '/../../lib/mount_command_plan.php';
function check_mount_plan($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$mapper = edm_registry_mapper_name($uuid);
$registration = array('luks_uuid' => $uuid, 'name' => 'Backup',
    'mount_folder' => 'Backup', 'mapper_name' => $mapper, 'enabled' => true);
$state = array('uuid' => $uuid, 'name' => 'sdb1', 'dev_id' => '8:17',
    'direct_mounted' => false, 'mapper_open' => true,
    'mapper_name' => $mapper, 'mapper_device' => 'dm-2',
    'mapper_dev_id' => '253:2', 'mapper_mounted' => false);
$plan = edm_mount_command_plan('mount', $state, $registration);
check_mount_plan($plan !== null && $plan['argv'][0] === '/bin/mount'
    && $plan['destination'] === '/mnt/Backup'
    && $plan['mapper_dev_id'] === '253:2', 'Mount plan binds registered folder and mapper');
$mounted = $state;
$mounted['mapper_mounted'] = true;
$plan = edm_mount_command_plan('unmount', $mounted, $registration);
check_mount_plan($plan !== null && $plan['argv'][0] === '/bin/umount',
    'Unmount plan for mounted mapper');
check_mount_plan(edm_mount_command_plan('mount', $mounted, $registration) === null,
    'Cannot mount an already-mounted mapper');
check_mount_plan(edm_mount_command_plan('unmount', $state, $registration) === null,
    'Cannot unmount an unmounted mapper');
$foreign = $registration;
$foreign['mapper_name'] = 'edm_foreign';
check_mount_plan(edm_mount_command_plan('mount', $state, $foreign) === null,
    'Foreign mapper rejected');
$invalid = $registration;
$invalid['mount_folder'] = '../etc';
check_mount_plan(edm_mount_command_plan('mount', $state, $invalid) === null,
    'Path traversal rejected');
$disabled = $registration;
$disabled['enabled'] = false;
check_mount_plan(edm_mount_command_plan('mount', $state, $disabled) === null,
    'Disabled registration rejected');
check_mount_plan(edm_mount_command_plan('encrypt', $state, $registration) === null,
    'Destructive action rejected');
