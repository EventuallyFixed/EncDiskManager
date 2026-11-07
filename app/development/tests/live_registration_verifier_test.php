<?php
require_once __DIR__ . '/../../lib/live_registration_verifier.php';

function check_live($condition, $label)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "PASS: $label\n";
}
$uuid = '12345678-1234-1234-1234-123456789abc';
$device = array('uuid' => $uuid, 'name' => 'sdb1', 'luks' => true,
    'mount' => '', 'backup_mapper' => '', 'backup_mount' => '');
$emptyDevices = function () { return array('complete' => true, 'devices' => array()); };
$noMappers = function () { return array('complete' => true, 'mappers' => array()); };
$delete = edm_live_registration_verifier('delete', $emptyDevices, $noMappers);
check_live($delete($uuid) === true, 'Disconnected deletion permitted with complete snapshots');
$edit = edm_live_registration_verifier('edit', $emptyDevices, $noMappers);
check_live($edit($uuid) === false, 'Disconnected edit remains disabled');
$attached = function () use ($device) {
    return array('complete' => true, 'devices' => array($device));
};
$edit = edm_live_registration_verifier('edit', $attached, $noMappers);
check_live($edit($uuid) === true, 'Attached inactive edit passes policy');
$unknownMapper = function () {
    return array('complete' => false, 'mappers' => array());
};
check_live(edm_live_registration_verifier('delete', $emptyDevices, $unknownMapper)($uuid) === false,
    'Incomplete mapper discovery rejects deletion');
$incompleteDevices = function () { return array('complete' => false, 'devices' => array()); };
check_live(edm_live_registration_verifier('delete', $incompleteDevices, $noMappers)($uuid) === false,
    'Incomplete device discovery rejects deletion');
$broken = function () { throw new RuntimeException('Discovery failed'); };
check_live(edm_live_registration_verifier('delete', $broken, $noMappers)($uuid) === false,
    'Discovery exception fails closed');
$activeMapper = function () use ($uuid) {
    return array('complete' => true, 'mappers' => array(
        array('source_uuid' => $uuid, 'parents' => array('sdb1'))
    ));
};
check_live(edm_live_registration_verifier('delete', $emptyDevices, $activeMapper)($uuid) === false,
    'Active matching mapper blocks disconnected deletion');

$default = edm_authoritative_registration_verifier('delete');
check_live(is_callable($default), 'Default authoritative verifier factory returns callable');
$rejected = false;
try { edm_authoritative_registration_verifier('mount'); }
catch (RuntimeException $e) { $rejected = true; }
check_live($rejected, 'Verifier factory rejects unsupported privileged operations');
