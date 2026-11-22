<?php
require_once __DIR__ . '/../../lib/disk_inactivity.php';
function check($value, $label) {
    if (!$value) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$disk = array(
    'uuid' => $uuid, 'name' => 'sdb1', 'luks' => true,
    'mount' => null, 'backup_mapper' => null, 'backup_mount' => null
);
check(edm_disk_registration_inactive($uuid, array($disk), array()), 'Locked single USB partition may be inactive');
check(!edm_disk_registration_inactive($uuid, array(), array()), 'Disconnected disk fails closed');
check(!edm_disk_registration_inactive($uuid, array($disk, $disk), array()), 'Ambiguous UUID fails closed');
$mounted = $disk;
$mounted['mount'] = '/mnt/archive';
check(!edm_disk_registration_inactive($uuid, array($mounted), array()), 'Mounted partition fails closed');
$unlocked = $disk;
$unlocked['backup_mapper'] = '/dev/mapper/archive';
check(!edm_disk_registration_inactive($uuid, array($unlocked), array()), 'Unlocked mapper fails closed');
check(!edm_disk_registration_inactive($uuid, array($disk), array(array('parents' => array('sdb1')))), 'Mapper ancestry fails closed');
check(!edm_disk_registration_inactive($uuid, array($disk), array(array('parents' => null))), 'Incomplete mapper state fails closed');
check(!edm_disk_registration_inactive('not-a-uuid', array($disk), array()), 'Invalid UUID fails closed');
