<?php
/**
 * Read-only verification of the existing USB operation request.
 * This never executes a command or accepts a disk name as sufficient identity.
 */
require_once __DIR__ . '/usb_discovery.php';

function edm_usb_operation_request($action, $devicePath, $mapperName, $inventory)
{
    if (!in_array($action, array('unlock', 'mount', 'unmount', 'lock'), true)
        || !is_string($devicePath)
        || !preg_match('~^/dev/sd[a-z]+[0-9]+$~D', $devicePath)
        || !is_string($mapperName)
        || !preg_match('/^[a-zA-Z0-9_-]+$/D', $mapperName)
        || !is_array($inventory) || !isset($inventory['devices'])
        || !is_array($inventory['devices'])) {
        return false;
    }

    $matches = array();
    foreach ($inventory['devices'] as $device) {
        if (isset($device['path']) && $device['path'] === $devicePath) {
            $matches[] = $device;
        }
    }
    if (count($matches) !== 1 || empty($matches[0]['luks'])) return false;
    $device = $matches[0];

    $mappers = isset($device['mapper_names']) && is_array($device['mapper_names'])
        ? $device['mapper_names'] : array();
    $mounts = isset($device['mapper_mounts']) && is_array($device['mapper_mounts'])
        ? $device['mapper_mounts'] : array();
    if (count($mappers) > 1 || count($mounts) > 1 || !empty($device['mount'])) {
        return false;
    }

    if ($action === 'unlock') return strpos($mapperName, 'edm_') === 0
        && count($mappers) === 0 && count($mounts) === 0;
    if (count($mappers) !== 1 || $mappers[0] !== $mapperName) return false;
    if ($action === 'mount' || $action === 'lock') return count($mounts) === 0;
    return count($mounts) === 1
        && is_string($mounts[0])
        && preg_match('~^/mnt/[^\\x00-\\x1f]+$~D', $mounts[0]) === 1;
}
