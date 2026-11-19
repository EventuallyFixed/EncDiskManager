<?php
/**
 * Convert the registry-first inventory into a stable, JSON-safe row contract.
 * This function is pure and can be tested without a NAS or HTTP session.
 */
require_once __DIR__ . '/disk_action_availability.php';

/**
 * Advisory policy only. Never authorize an operation using this snapshot.
 */
function edm_inventory_action_preview($row)
{
    $none = array('unlock' => false, 'mount' => false,
        'unmount' => false, 'lock' => false, 'encrypt' => false);
    if (!empty($row['ambiguous']) || empty($row['device'])
        || !is_array($row['device'])) {
        return $none;
    }
    // The same discovery fields used by the operation request validator
    // drive the disabled/enabled preview state. Execution is still gated
    // separately by the authenticated endpoint and privileged helper.
    $actions = edm_disk_available_actions($row['device']);
    $device = $row['device'];
    $mappers = isset($device['mapper_names']) && is_array($device['mapper_names'])
        ? $device['mapper_names'] : array();
    if (count($mappers) === 1) {
        // The helper only manages mappings created by EncDiskManager.
        // Legacy backupdisk and other external mappings remain read-only.
        if (!preg_match('/^edm_[a-zA-Z0-9_-]+$/D', $mappers[0])) {
            $actions['mount'] = false;
            $actions['unmount'] = false;
            $actions['lock'] = false;
        } elseif (count($device['mapper_mounts']) === 1
            && $device['mapper_mounts'][0] !== '/mnt/' . $mappers[0]) {
            $actions['unmount'] = false;
        }
    }
    $uuid = isset($device['uuid']) ? (string)$device['uuid'] : '';
    if (!preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)) {
        $actions['unlock'] = false;
    }
    return $actions;
}

function edm_inventory_payload($rows)
{
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid inventory rows.');
    }
    $result = array();
    $keys = array();
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['registered'], $row['name'], $row['status'])
            || !array_key_exists('device', $row)) {
            throw new RuntimeException('Incomplete inventory row.');
        }
        $device = $row['device'];
        $registered = $row['registered'] === true;
        if (!$registered && (!is_array($device) || empty($device['name']))) {
            throw new RuntimeException('Unregistered disk has no device identity.');
        }
        $uuid = $registered ? strtolower((string)$row['luks_uuid']) : '';
        if ($registered && !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $uuid)) {
            throw new RuntimeException('Invalid registered disk UUID.');
        }
        $key = $registered ? 'registered:' . $uuid : 'device:' . $device['name'];
        if (isset($keys[$key])) {
            throw new RuntimeException('Duplicate inventory row identity.');
        }
        $keys[$key] = true;
        $result[] = array(
            'key' => $key,
            'connection' => $device === null ? 'disconnected' : 'connected',
            'registered' => $registered,
            'name' => (string)$row['name'],
            'device' => $device === null ? '—' : (string)$device['path'],
            'status' => (string)$row['status'],
            'type' => $device === null ? '—' : ((string)$device['type'] ?: 'Unknown'),
            'uuid' => $registered ? $uuid : ((string)$row['luks_uuid'] ?: '—'),
            'mount' => $registered ? '/mnt/' . $row['mount_folder'] : '—',
            'folder' => $registered ? (string)$row['mount_folder'] : '',
            'mapper' => ($device !== null && isset($device['mapper_names'])
                && is_array($device['mapper_names']) && count($device['mapper_names']) === 1)
                ? (string)$device['mapper_names'][0] : '',
            'actions' => edm_inventory_action_preview($row)
        );
    }
    return $result;
}
