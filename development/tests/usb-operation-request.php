<?php
// Run: php development/tests/usb-operation-request.php
require_once __DIR__ . '/../../app/lib/usb_operation_request.php';

function check_request($label, $action, $path, $mapper, $inventory, $expected)
{
    $actual = edm_usb_operation_request($action, $path, $mapper, $inventory);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}
$locked = array('path'=>'/dev/sdd1','luks'=>true,'mount'=>'',
    'mapper_names'=>array(),'mapper_mounts'=>array());
$unlocked = $locked;
$unlocked['mapper_names'] = array('edm_test');
$mounted = $unlocked;
$mounted['mapper_mounts'] = array('/mnt/edm_test');
$inventory = function ($disk) { return array('devices'=>array($disk)); };
check_request('locked unlock', 'unlock','/dev/sdd1','edm_test',$inventory($locked),true);
check_request('locked mount', 'mount','/dev/sdd1','edm_test',$inventory($locked),false);
check_request('unlocked mount', 'mount','/dev/sdd1','edm_test',$inventory($unlocked),true);
check_request('unlocked lock', 'lock','/dev/sdd1','edm_test',$inventory($unlocked),true);
check_request('mounted unmount', 'unmount','/dev/sdd1','edm_test',$inventory($mounted),true);
check_request('mounted lock', 'lock','/dev/sdd1','edm_test',$inventory($mounted),false);
check_request('wrong mapper', 'unmount','/dev/sdd1','edm_other',$inventory($mounted),false);
check_request('wrong mountpoint', 'unmount','/dev/sdd1','edm_test',
    $inventory(array_merge($mounted,array('mapper_mounts'=>array('/mnt/elsewhere')))),false);
check_request('not LUKS', 'unlock','/dev/sdd1','edm_test',
    $inventory(array_merge($locked,array('luks'=>false))),false);
check_request('missing device', 'unlock','/dev/sdd2','edm_test',$inventory($locked),false);
check_request('invalid action', 'erase','/dev/sdd1','edm_test',$inventory($locked),false);
check_request('invalid mapper', 'unlock','/dev/sdd1','../other',$inventory($locked),false);
check_request('duplicate inventory', 'unlock','/dev/sdd1','edm_test',
    array('devices'=>array($locked,$locked)),false);
echo "PASS: USB operation request validation\n";
