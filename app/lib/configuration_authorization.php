<?php
/**
 * Confirm a validated configuration request targets a current registration.
 * This is a metadata preflight, NOT live disk-state authorisation. The caller
 * must still verify live state under the shared disk-operation lock before
 * making any registry change.
 */
function edm_configuration_registration_preflight($request, $registry)
{
    if (!is_array($request) || !isset($request['action'], $request['luks_uuid'])
        || !in_array($request['action'], array('edit', 'delete'), true)
        || !is_string($request['luks_uuid']) || !is_array($registry)
        || !isset($registry['disks']) || !is_array($registry['disks'])) {
        throw new RuntimeException('Invalid configuration preflight.');
    }
    $matching = array();
    foreach ($registry['disks'] as $disk) {
        if (!is_array($disk) || !isset($disk['luks_uuid']) || !is_string($disk['luks_uuid'])) {
            throw new RuntimeException('Invalid registry entry.');
        }
        if (strcasecmp($disk['luks_uuid'], $request['luks_uuid']) === 0) {
            $matching[] = $disk;
        }
    }
    if (count($matching) !== 1) {
        throw new RuntimeException('Registration is missing or ambiguous.');
    }
    $target = $matching[0];
    if ($request['action'] === 'edit') {
        if (!isset($request['name'], $request['mount_folder'])
            || !is_string($request['name']) || !is_string($request['mount_folder'])) {
            throw new RuntimeException('Invalid edit details.');
        }
        foreach ($registry['disks'] as $disk) {
            if (strcasecmp($disk['luks_uuid'], $request['luks_uuid']) === 0) continue;
            if (strcasecmp($disk['name'], $request['name']) === 0
                || strcasecmp($disk['mount_folder'], $request['mount_folder']) === 0) {
                throw new RuntimeException('Name or mount directory is already registered.');
            }
        }
    }
    return $target;
}

/** Registration deletion is a metadata change, allowed only when unmounted. */
function edm_registration_can_delete($uuid, $devices)
{
    if (!is_string($uuid) || !is_array($devices)) return false;
    $matches = 0;
    foreach ($devices as $device) {
        if (empty($device['luks']) || !isset($device['uuid'])
            || strcasecmp($device['uuid'], $uuid) !== 0) continue;
        $matches++;
        if ($matches > 1 || !isset($device['mount'], $device['mapper_mounts'], $device['mapper_names'])
            || $device['mount'] !== '' || !is_array($device['mapper_mounts'])
            || count($device['mapper_mounts']) !== 0 || !is_array($device['mapper_names'])
            || count($device['mapper_names']) > 1) return false;
    }
    // No matching device means the registration is disconnected.
    return true;
}

/** Advisory configuration availability for the entire physical USB disk.
 * The whole-disk inventory detects direct mounts, including aliases; the
 * partition inventory also detects mounted encrypted mappings. */
function edm_configuration_available($uuid, $devices, $whole)
{
    if (empty($whole['complete']) || !is_array($devices)
        || !isset($whole['disks']) || !edm_registration_can_delete($uuid, $devices)) return false;
    $target = null;
    foreach ($devices as $device) {
        if (!empty($device['luks']) && strcasecmp($device['uuid'], $uuid) === 0) {
            $target = $device['name'];
        }
    }
    if ($target === null) return true; // disconnected registration
    foreach ($whole['disks'] as $disk) {
        if (!in_array($target, $disk['partitions'], true)) continue;
        if ($disk['mounted']) return false;
        foreach ($devices as $device) {
            if (!in_array($device['name'], $disk['partitions'], true)) continue;
            if (!isset($device['mount'], $device['mapper_mounts'])
                || $device['mount'] !== '' || $device['mapper_mounts']) return false;
        }
        return true;
    }
    return false; // parent disk is unknown
}

/** Change only the registered name and mount folder after live authorization. */
function edm_configuration_edit_registry($request, $registry, $canChangeFolder)
{
    $target = edm_configuration_registration_preflight($request, $registry);
    $newFolder = $target['mount_folder'] !== $request['mount_folder'] ? $request['mount_folder'] : null;
    if (!is_callable($canChangeFolder) || $canChangeFolder($target['luks_uuid'], $newFolder) !== true) {
        throw new RuntimeException('Unmount all partitions on the USB disk before configuring it. Disk state must be known and any new mount directory must be unused.');
    }
    require_once __DIR__ . '/registry_operations.php';
    return edm_registry_update_disk($registry, $target['luks_uuid'],
        $request['name'], $request['mount_folder']);
}

/** Read-only policy for a changed mount folder. Accept disconnected, locked,
 * and unlocked/unmounted registrations; reject mounted or uncertain state. */
function edm_configuration_mount_folder_edit_allowed($uuid, $deviceSnapshot, $mapperSnapshot, $mountInfo, $whole)
{
    if (!is_array($deviceSnapshot) || !is_array($mapperSnapshot)
        || ($deviceSnapshot['complete'] ?? false) !== true
        || ($mapperSnapshot['complete'] ?? false) !== true
        || !isset($deviceSnapshot['devices'], $mapperSnapshot['mappers'])
        || !is_array($mountInfo) || empty($whole['complete']) || !isset($whole['disks'])) return false;
    require_once __DIR__ . '/registration_snapshot_consistency.php';
    $devices = $deviceSnapshot['devices'];
    $mappers = $mapperSnapshot['mappers'];
    if (!edm_registration_snapshots_consistent($devices, $mappers)) return false;

    $mounted = array();
    foreach ($mountInfo as $line) {
        $fields = explode(' ', $line);
        if (!isset($fields[2]) || !preg_match('/^[0-9]+:[0-9]+$/D', $fields[2])) return false;
        $mounted[$fields[2]] = true;
    }
    $target = null;
    foreach ($devices as $device) {
        if (strcasecmp($device['uuid'], $uuid) !== 0) continue;
        if ($target !== null || empty($device['luks']) || !isset($device['mount'], $device['dev_id'])
            || $device['mount'] !== '' || isset($mounted[$device['dev_id']])) return false;
        $target = $device;
    }
    if ($target === null) {
        foreach ($mappers as $mapper) {
            if (strcasecmp($mapper['source_uuid'], $uuid) === 0) return false;
        }
        return true;
    }
    $parent = null;
    foreach ($whole['disks'] as $disk) {
        if (in_array($target['name'], $disk['partitions'], true)) {
            if ($parent !== null) return false;
            $parent = $disk;
        }
    }
    if ($parent === null || $parent['mounted']
        || !isset($parent['partition_dev_ids'][$target['name']])
        || $parent['partition_dev_ids'][$target['name']] !== $target['dev_id']) return false;
    foreach (array_merge(array($parent['dev_id']), array_values($parent['partition_dev_ids'])) as $id) {
        if (isset($mounted[$id])) return false;
    }
    foreach ($mappers as $mapper) {
        if (!array_intersect($mapper['parents'], $parent['partitions'])) continue;
        if (!isset($mapper['dev_id']) || !preg_match('/^[0-9]+:[0-9]+$/D', $mapper['dev_id'])
            || isset($mounted[$mapper['dev_id']])) return false;
    }
    return true;
}

/** Called while holding the same lock as the live Unlock/Mount operations,
 * and inside the atomic registry update. No directory is created or moved. */
function edm_configuration_can_change_mount_folder($uuid, $folder = null)
{
    require_once __DIR__ . '/authoritative_usb_discovery.php';
    require_once __DIR__ . '/kernel_mapper_discovery.php';
    require_once __DIR__ . '/whole_usb_disk_discovery.php';
    $mountInfo = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $devices = edm_authoritative_usb_discovery();
    $mappers = edm_discover_kernel_mappers();
    $whole = edm_discover_whole_usb_disks();
    if (!is_array($mountInfo) || !$mountInfo || $mountInfo !== @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        || !edm_configuration_mount_folder_edit_allowed($uuid, $devices, $mappers, $mountInfo, $whole)) return false;
    if ($folder === null) return true;
    // The existing mount helper requires a new, unused destination.
    $path = '/mnt/' . $folder;
    clearstatcache(true, $path);
    return !file_exists($path) && !is_link($path);
}

/** Recheck before deleting a registration or its stored key. */
function edm_configuration_require_unmounted($uuid)
{
    if (!edm_configuration_can_change_mount_folder($uuid)) {
        throw new RuntimeException('Unmount all partitions on the USB disk before configuring it, or wait for disk state to become available.');
    }
}
