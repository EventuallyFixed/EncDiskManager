<?php
/**
 * Cross-check complete USB and mapper snapshots before registration policy.
 * This is read-only evidence, not authorization for a disk operation.
 */
function edm_registration_snapshots_consistent($devices, $mappers)
{
    if (!is_array($devices) || !is_array($mappers)) return false;
    $names = array();
    $uuids = array();
    foreach ($devices as $device) {
        if (!is_array($device) || !isset($device['name'], $device['uuid'], $device['luks'])
            || !is_string($device['name']) || !is_string($device['uuid'])
            || !is_bool($device['luks'])
            || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $device['name'])
            || isset($names[$device['name']])) return false;
        $names[$device['name']] = true;
        if ($device['luks']) {
            if (!preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $device['uuid'])) return false;
            $key = strtolower($device['uuid']);
            if (isset($uuids[$key])) return false;
            $uuids[$key] = $device['name'];
        }
    }
    $mapperNames = array();
    foreach ($mappers as $mapper) {
        if (!is_array($mapper) || !isset($mapper['name'], $mapper['source_uuid'], $mapper['parents'])
            || !is_string($mapper['name']) || !is_string($mapper['source_uuid'])
            || !is_array($mapper['parents']) || !$mapper['parents']
            || isset($mapperNames[$mapper['name']])) return false;
        $mapperNames[$mapper['name']] = true;
        $source = strtolower($mapper['source_uuid']);
        if (!preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $source)) return false;
        $parents = array();
        foreach ($mapper['parents'] as $parent) {
            if (!is_string($parent) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $parent)
                || isset($parents[$parent])) return false;
            $parents[$parent] = true;
        }
        // For a USB LUKS partition we identified, a mapper claiming its UUID
        // must actually have that partition among its kernel slave devices.
        if (isset($uuids[$source]) && !isset($parents[$uuids[$source]])) return false;
        // Conversely, a mapper whose slave is a known USB LUKS partition
        // must identify that partition's LUKS UUID.
        foreach ($uuids as $uuid => $name) {
            if (isset($parents[$name]) && $uuid !== $source) return false;
        }
    }
    return true;
}
