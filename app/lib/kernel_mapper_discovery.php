<?php
require_once __DIR__ . '/mapper_identity.php';

/**
 * Read the complete device-mapper list from sysfs for conservative policy use.
 * Unlike the UI's USB-linked mapper list, this includes internal and unrelated
 * mappings. Any missing/unreadable identity causes a fail-closed result.
 *
 * This is a snapshot, not a lock on kernel state. The caller must coordinate
 * its own privileged disk operations and recheck while holding the shared lock.
 */
function edm_discover_kernel_mappers($sysBlock = '/sys/class/block')
{
    if (!is_string($sysBlock) || $sysBlock === '') {
        return array('complete' => false, 'mappers' => array());
    }
    $entries = @scandir($sysBlock);
    if (!is_array($entries)) {
        return array('complete' => false, 'mappers' => array());
    }
    $mappers = array();
    $observed = array();
    $seenNames = array();
    $complete = true;
    foreach ($entries as $entry) {
        if (!preg_match('/^dm-[0-9]+$/D', $entry)) {
            continue;
        }
        $base = $sysBlock . '/' . $entry;
        $identity = @file_get_contents($base . '/dm/uuid');
        $name = @file_get_contents($base . '/dm/name');
        $devId = @file_get_contents($base . '/dev');
        $slaves = @scandir($base . '/slaves');
        if ($identity === false || $name === false || $devId === false || !is_array($slaves)) {
            $complete = false;
            continue;
        }
        $identity = trim($identity);
        $name = trim($name);
        $devId = trim($devId);
        if (!preg_match('/^[0-9]+:[0-9]+$/D', $devId)) $complete = false;
        $observed[$entry] = array('uuid' => $identity, 'name' => $name,
            'dev_id' => $devId,
            'slaves' => array_values(array_diff($slaves, array('.', '..'))));
        $uuid = edm_mapper_luks_uuid($identity);
        if ($uuid === null || $name === ''
            || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) {
            $complete = false;
            continue;
        }
        if (isset($seenNames[$name])) {
            $complete = false;
        }
        $seenNames[$name] = true;
        $parents = array();
        foreach ($slaves as $slave) {
            if ($slave === '.' || $slave === '..') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $slave)) {
                $complete = false;
                continue;
            }
            $parents[] = $slave;
        }
        if (!$parents) {
            $complete = false;
        }
        $mappers[] = array(
            'name' => $name,
            'device' => $entry,
            'dev_id' => $devId,
            'source_uuid' => $uuid,
            'parents' => $parents
        );
    }
    // If enumeration changes while reading, the snapshot cannot be trusted.
    $after = @scandir($sysBlock);
    if (!is_array($after)) {
        $complete = false;
    } else {
        $beforeDm = array_values(array_filter($entries, function ($item) {
            return preg_match('/^dm-[0-9]+$/D', $item) === 1;
        }));
        $afterDm = array_values(array_filter($after, function ($item) {
            return preg_match('/^dm-[0-9]+$/D', $item) === 1;
        }));
        sort($beforeDm);
        sort($afterDm);
        if ($beforeDm !== $afterDm) {
            $complete = false;
        }
    }
    // A dm-N node can be replaced or its ancestry changed without changing
    // the list of dm-N names. Re-read each observed identity and slave set.
    foreach ($observed as $entry => $expected) {
        $base = $sysBlock . '/' . $entry;
        $uuidAfter = @file_get_contents($base . '/dm/uuid');
        $nameAfter = @file_get_contents($base . '/dm/name');
        $devIdAfter = @file_get_contents($base . '/dev');
        $slavesAfter = @scandir($base . '/slaves');
        if ($uuidAfter === false || $nameAfter === false || $devIdAfter === false || !is_array($slavesAfter)) {
            $complete = false;
            continue;
        }
        $actualSlaves = array_values(array_diff($slavesAfter, array('.', '..')));
        $expectedSlaves = $expected['slaves'];
        sort($actualSlaves);
        sort($expectedSlaves);
        if (trim($uuidAfter) !== $expected['uuid']
            || trim($nameAfter) !== $expected['name']
            || trim($devIdAfter) !== $expected['dev_id']
            || $actualSlaves !== $expectedSlaves) {
            $complete = false;
        }
    }
    return array('complete' => $complete, 'mappers' => $mappers);
}
