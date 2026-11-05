<?php
/**
 * Decide whether a registration may be deleted using authoritative discovery.
 *
 * A disconnected disk needs no physical disk operation. The caller must
 * establish that block-device and mapper enumeration both completed, and
 * must reject unknown or ambiguous device identities before invoking this.
 * This policy function does not itself inspect the kernel.
 */
function edm_registration_deletion_allowed($uuid, $devices, $mappers, $discoveryComplete)
{
    if ($discoveryComplete !== true || !is_string($uuid)
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $uuid)
        || !is_array($devices) || !is_array($mappers)) {
        return false;
    }
    $matches = array();
    foreach ($devices as $device) {
        if (!is_array($device) || !isset($device['uuid'], $device['name'])
            || !is_string($device['uuid']) || !is_string($device['name'])) {
            return false;
        }
        if (strcasecmp($device['uuid'], $uuid) === 0) {
            $matches[] = $device;
        }
    }
    if (count($matches) > 1) {
        return false;
    }
    foreach ($mappers as $mapper) {
        if (!is_array($mapper) || !isset($mapper['parents']) || !is_array($mapper['parents'])) {
            return false;
        }
        // The caller must supply all active mappers and their source UUIDs.
        // Unknown mapper source identity is not safe evidence of disconnection.
        if (!array_key_exists('source_uuid', $mapper) || !is_string($mapper['source_uuid'])) {
            return false;
        }
        if (strcasecmp($mapper['source_uuid'], $uuid) === 0) {
            return false;
        }
    }
    if (count($matches) === 0) {
        return true;
    }
    $device = $matches[0];
    if (empty($device['luks']) || !array_key_exists('mount', $device)
        || !array_key_exists('backup_mapper', $device)
        || !array_key_exists('backup_mount', $device)) {
        return false;
    }
    return empty($device['mount']) && empty($device['backup_mapper'])
        && empty($device['backup_mount']);
}
