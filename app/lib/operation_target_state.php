<?php
/**
 * Read-only fail-closed mapper/mount checks for a bound USB LUKS partition.
 * Not an authorization to execute disk operations.
 */
require_once __DIR__ . '/registration_snapshot_consistency.php';

function edm_operation_target_state($bound, $deviceSnapshot, $mapperSnapshot)
{
    if (!is_array($bound) || !isset($bound['uuid'], $bound['name'], $bound['dev_id'])
        || !is_array($deviceSnapshot) || !is_array($mapperSnapshot)
        || !isset($deviceSnapshot['complete'], $deviceSnapshot['devices'],
            $mapperSnapshot['complete'], $mapperSnapshot['mappers'])
        || $deviceSnapshot['complete'] !== true || $mapperSnapshot['complete'] !== true
        || !is_array($deviceSnapshot['devices']) || !is_array($mapperSnapshot['mappers'])
        || !edm_registration_snapshots_consistent(
            $deviceSnapshot['devices'], $mapperSnapshot['mappers'])) return null;

    $target = null;
    foreach ($deviceSnapshot['devices'] as $device) {
        if (!isset($device['name'], $device['dev_id'], $device['uuid'], $device['luks'],
            $device['mount']) || !is_string($device['mount'])) return null;
        if ($device['name'] !== $bound['name']) continue;
        if ($device['dev_id'] !== $bound['dev_id'] || !$device['luks']
            || strcasecmp($device['uuid'], $bound['uuid']) !== 0) return null;
        $target = $device;
    }
    if ($target === null) return null;
    $matches = array();
    foreach ($mapperSnapshot['mappers'] as $mapper) {
        if (!isset($mapper['source_uuid'], $mapper['parents'], $mapper['name'],
            $mapper['device'])) return null;
        if (strcasecmp($mapper['source_uuid'], $bound['uuid']) === 0
            || in_array($bound['name'], $mapper['parents'], true)) {
            if (strcasecmp($mapper['source_uuid'], $bound['uuid']) !== 0
                || !in_array($bound['name'], $mapper['parents'], true)) return null;
            $matches[] = $mapper;
        }
    }
    if (count($matches) > 1) return null;
    return array(
        'uuid' => $bound['uuid'],
        'name' => $bound['name'],
        'dev_id' => $bound['dev_id'],
        'direct_mounted' => $target['mount'] !== '',
        'mapper_open' => count($matches) === 1,
        'mapper_name' => $matches ? $matches[0]['name'] : null,
        'mapper_device' => $matches ? $matches[0]['device'] : null
    );
}
