<?php
require_once __DIR__ . '/../../lib/mount_subprocess.php';
function check_mount_exec($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = str_repeat('a', 32);
$plan = array('action' => 'mount',
    'argv' => array('/bin/mount', '-t', 'ext4', '-o', 'nosuid,nodev',
        '/dev/mapper/edm_' . $uuid, '/mnt/Backup'),
    'destination' => '/mnt/Backup', 'mapper_dev_id' => '253:2');
$bad = $plan;
$bad['argv'][0] = '/bin/sh';
check_mount_exec(!edm_mount_subprocess($bad), 'Shell executable rejected');
$bad = $plan;
$bad['argv'][4] = 'suid,dev';
check_mount_exec(!edm_mount_subprocess($bad), 'Unsafe mount flags rejected');
$bad = $plan;
$bad['argv'][5] = '/dev/sda1';
check_mount_exec(!edm_mount_subprocess($bad), 'Raw block device rejected');
$bad = $plan;
$bad['destination'] = '/etc';
check_mount_exec(!edm_mount_subprocess($bad), 'Destination traversal rejected');
$bad = $plan;
$bad['argv'][] = '--force';
check_mount_exec(!edm_mount_subprocess($bad), 'Extra arguments rejected');
$bad = $plan;
$bad['action'] = 'format';
check_mount_exec(!edm_mount_subprocess($bad), 'Destructive action rejected');
check_mount_exec(!edm_mount_subprocess($plan, 0), 'Invalid timeout rejected');
$bad = array('action' => 'unmount',
    'argv' => array('/bin/umount', '/etc'),
    'destination' => '/etc', 'mapper_dev_id' => '253:2');
check_mount_exec(!edm_mount_subprocess($bad), 'Unmount outside /mnt rejected');
