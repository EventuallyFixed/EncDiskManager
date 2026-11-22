<?php
require_once __DIR__ . '/../../lib/registration_deletion_policy.php';

function check($condition, $label)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$other = '87654321-4321-4321-4321-cba987654321';
$disk = array('uuid' => $uuid, 'name' => 'sdb1', 'luks' => true,
    'mount' => '', 'backup_mapper' => '', 'backup_mount' => '');
$mapper = array('parents' => array('sdc1'), 'source_uuid' => $other);
check(edm_registration_deletion_allowed($uuid, array(), array(), true),
    'Disconnected registration can be deleted after complete discovery');
check(!edm_registration_deletion_allowed($uuid, array(), array(), false),
    'Incomplete discovery rejects disconnected deletion');
check(edm_registration_deletion_allowed($uuid, array($disk), array(), true),
    'Attached locked registration can be deleted');
check(!edm_registration_deletion_allowed($uuid, array($disk, $disk), array(), true),
    'Duplicate UUID rejects deletion');
$mounted = $disk;
$mounted['mount'] = '/mnt/archive';
check(!edm_registration_deletion_allowed($uuid, array($mounted), array(), true),
    'Mounted registration rejects deletion');
$unlocked = $disk;
$unlocked['backup_mapper'] = 'edm_archive';
check(!edm_registration_deletion_allowed($uuid, array($unlocked), array(), true),
    'Unlocked registration rejects deletion');
check(edm_registration_deletion_allowed($uuid, array(), array($mapper), true),
    'Unrelated known mapper does not block disconnected deletion');
$mapper['source_uuid'] = $uuid;
check(!edm_registration_deletion_allowed($uuid, array(), array($mapper), true),
    'Active mapper with matching source UUID blocks disconnected deletion');
$mapper['source_uuid'] = '';
check(!edm_registration_deletion_allowed($uuid, array(), array($mapper), true),
    'Unknown mapper source UUID fails closed');
$mapper['source_uuid'] = $other;
$mapper['parents'] = array('sdb1');
check(!edm_registration_deletion_allowed($uuid, array($disk), array($mapper), true),
    'Attached mapper ancestry blocks deletion');
check(!edm_registration_deletion_allowed('invalid', array(), array(), true),
    'Malformed registration UUID rejected');
