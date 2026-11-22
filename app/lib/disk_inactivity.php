<?php
/**
 * Fail-closed live state assessment for registry metadata changes.
 * This is not sufficient to authorise destructive disk operations.
 */
function edm_disk_registration_inactive($uuid, $devices, $mappers)
{
    if (!is_string($uuid) || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $uuid)
        || !is_array($devices) || !is_array($mappers)) {
        return false;
    }

    $matches = array();
    foreach ($devices as $device) {
        if (!is_array($device) || !isset($device['uuid'])) {
            return false;
        }
        if (strcasecmp((string)$device['uuid'], $uuid) === 0) {
            $matches[] = $device;
        }
    }
    // Disconnected registration may be edited, but not when a mapper with
    // uncertain ancestry exists. Live disk discovery must be complete.
    if (count($matches) > 1) {
        return false;
    }
    foreach ($mappers as $mapper) {
        if (!is_array($mapper) || !isset($mapper['parents']) || !is_array($mapper['parents'])) {
            return false;
        }
        if (count($matches) === 1) {
            $name = isset($matches[0]['name']) ? $matches[0]['name'] : null;
            if (!is_string($name) || $name === '') {
                return false;
            }
            if (in_array($name, $mapper['parents'], true)) {
                return false;
            }
        }
    }
    if (count($matches) === 0) {
        // Without complete kernel mapper enumeration, disconnected is not
        // proof of inactivity; do not authorise changes in this case.
        return false;
    }
    $device = $matches[0];
    if (empty($device['luks']) || !array_key_exists('mount', $device)
        || !array_key_exists('backup_mapper', $device)
        || !array_key_exists('backup_mount', $device)) {
        return false;
    }
    return empty($device['mount']) && empty($device['backup_mapper']) && empty($device['backup_mount']);
}
