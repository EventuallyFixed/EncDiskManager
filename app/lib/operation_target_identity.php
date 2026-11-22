<?php
/**
 * Read-only identity binding for future privileged disk operations.
 * A target is identified by registered LUKS UUID, never a client device path.
 * This does NOT authorize or execute any operation.
 */
function edm_operation_target_identity($uuid, $snapshot)
{
    if (!is_string($uuid)
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)
        || !is_array($snapshot) || !isset($snapshot['complete'], $snapshot['devices'])
        || $snapshot['complete'] !== true || !is_array($snapshot['devices'])) return null;
    $found = null;
    $seenNames = array();
    $seenDevIds = array();
    foreach ($snapshot['devices'] as $device) {
        if (!is_array($device) || !isset($device['name'], $device['dev_id'],
                $device['uuid'], $device['luks'])
            || !is_string($device['name']) || !is_string($device['dev_id'])
            || !is_string($device['uuid']) || !is_bool($device['luks'])
            || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $device['name'])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $device['dev_id'])
            || isset($seenNames[$device['name']]) || isset($seenDevIds[$device['dev_id']])) return null;
        $seenNames[$device['name']] = true;
        $seenDevIds[$device['dev_id']] = true;
        if (strcasecmp($device['uuid'], $uuid) !== 0) continue;
        if (!$device['luks'] || $found !== null) return null;
        $found = array('uuid' => strtolower($uuid),
            'name' => $device['name'], 'dev_id' => $device['dev_id']);
    }
    return $found;
}

/** Require a fresh complete snapshot to match the previously bound target. */
function edm_operation_target_still_matches($bound, $freshSnapshot)
{
    if (!is_array($bound) || !isset($bound['uuid'], $bound['name'], $bound['dev_id']))
        return false;
    $fresh = edm_operation_target_identity($bound['uuid'], $freshSnapshot);
    return $fresh !== null && $fresh === $bound;
}
