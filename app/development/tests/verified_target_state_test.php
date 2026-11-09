<?php
require_once __DIR__ . '/../../lib/verified_target_state.php';
function check_verified_state($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$usb = array('complete' => true, 'devices' => array(array(
    'name' => 'sdb1', 'dev_id' => '8:17', 'uuid' => $uuid,
    'luks' => true, 'mount' => '', 'backup_mapper' => '', 'backup_mount' => ''
)));
$mapper = array('complete' => true, 'mappers' => array(array(
    'name' => 'usb', 'device' => 'dm-2', 'dev_id' => '253:2',
    'source_uuid' => $uuid, 'parents' => array('sdb1')
)));
$mounts = array('20 1 253:2 / /mnt/usb rw - ext4 /dev/mapper/alias rw');
$u = 0; $m = 0; $p = 0;
$state = edm_verified_target_state_probe($uuid,
    function () use ($usb, &$u) { ++$u; return $usb; },
    function () use ($mapper, &$m) { ++$m; return $mapper; },
    function () use ($mounts, &$p) { ++$p; return $mounts; });
check_verified_state($state !== null && $state['mapper_mounted'] === true
    && $u === 2 && $m === 2 && $p === 2, 'Stable identity and mounted mapper verified twice');
$reads = 0;
$changed = edm_verified_target_state_probe($uuid,
    function () use ($usb) { return $usb; },
    function () use ($mapper) { return $mapper; },
    function () use ($mounts, &$reads) {
        ++$reads;
        return $reads === 1 ? $mounts : array();
    });
check_verified_state($changed === null, 'Mount disappearing between probes fails closed');
$reads = 0;
$changed = edm_verified_target_state_probe($uuid,
    function () use ($usb) { return $usb; },
    function () use ($mapper, &$reads) {
        ++$reads;
        if ($reads === 1) return $mapper;
        $changed = $mapper;
        $changed['mappers'][0]['dev_id'] = '253:3';
        return $changed;
    },
    function () use ($mounts) { return $mounts; });
check_verified_state($changed === null, 'Mapper device identity changing between probes fails closed');
$reads = 0;
$changed = edm_verified_target_state_probe($uuid,
    function () use ($usb, &$reads) {
        ++$reads;
        if ($reads === 1) return $usb;
        $changed = $usb;
        $changed['devices'][0]['dev_id'] = '8:33';
        return $changed;
    },
    function () use ($mapper) { return $mapper; },
    function () use ($mounts) { return $mounts; });
check_verified_state($changed === null, 'USB device identity changing between probes fails closed');
check_verified_state(edm_verified_target_state_probe($uuid,
    function () { throw new RuntimeException('discovery failed'); },
    function () use ($mapper) { return $mapper; },
    function () use ($mounts) { return $mounts; }) === null,
    'Discovery exception fails closed');

check_verified_state(!edm_verified_mountinfo_snapshot(array('malformed mountinfo')),
    'Malformed mountinfo entry rejected');
check_verified_state(!edm_verified_mountinfo_snapshot(array(123)),
    'Non-string mountinfo entry rejected');
check_verified_state(edm_verified_mountinfo_snapshot($mounts),
    'Valid Linux mountinfo entry accepted');
$lockedMappers = array('complete' => true, 'mappers' => array());
check_verified_state(edm_verified_target_state_probe($uuid,
    function () use ($usb) { return $usb; },
    function () use ($lockedMappers) { return $lockedMappers; },
    function () { return array('malformed mountinfo'); }) === null,
    'Malformed mountinfo fails closed even when no mapper is open');

check_verified_state(edm_verified_mountinfo_snapshot(array(
    '20 1 253:2 / /mnt/usb rw shared:1 - ext4 /dev/mapper/alias rw')),
    'Mountinfo optional fields accepted');
foreach (array(
    '20 1 253:2 / /mnt/usb rw - ext4 /dev/mapper/alias',
    '20 1 253:2 / /mnt/usb rw - ext4 /dev/mapper/alias rw garbage',
    '20 1 bad / /mnt/usb rw - ext4 /dev/mapper/alias rw',
    '20 1 253:2 / /mnt/usb rw  - ext4 /dev/mapper/alias rw'
) as $badMountinfo) {
    check_verified_state(!edm_verified_mountinfo_snapshot(array($badMountinfo)),
        'Reject incomplete or malformed mountinfo field structure');
}

foreach (array(1, 2) as $badPass) {
    $mountReads = 0;
    $inspected = edm_verified_target_state_probe($uuid,
        function () use ($usb) { return $usb; },
        function () use ($lockedMappers) { return $lockedMappers; },
        function () use (&$mountReads, $badPass, $mounts) {
            ++$mountReads;
            return $mountReads === $badPass
                ? array('incomplete mountinfo') : $mounts;
        });
    check_verified_state($inspected === null && $mountReads === $badPass,
        'Malformed mountinfo on verification pass ' . $badPass . ' fails closed');
}

$duplicateMounts = array(
    '20 1 253:2 / /mnt/usb rw - ext4 /dev/mapper/alias rw',
    '020 1 8:17 / /mnt/other rw - ext4 /dev/sdb1 rw'
);
check_verified_state(!edm_verified_mountinfo_snapshot($duplicateMounts),
    'Duplicate mount IDs, including leading-zero aliases, are rejected');
check_verified_state(edm_verified_target_state_probe($uuid,
    function () use ($usb) { return $usb; },
    function () use ($lockedMappers) { return $lockedMappers; },
    function () use ($duplicateMounts) { return $duplicateMounts; }) === null,
    'Duplicate mount IDs fail closed before operation inspection');
check_verified_state(edm_verified_mountinfo_snapshot(array(
    '20 1 253:2 / /mnt/usb rw - ext4 /dev/mapper/alias rw',
    '21 1 8:17 / /mnt/other rw - ext4 /dev/sdb1 rw'
)), 'Distinct mount IDs remain accepted');
