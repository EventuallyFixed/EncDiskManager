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
