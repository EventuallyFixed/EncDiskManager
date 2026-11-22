<?php
/**
 * Pure registry editing operations. Callers must authenticate, authorise,
 * lock/reload the registry and verify live disk state before saving.
 */
require_once __DIR__ . '/registry.php';

function edm_registry_add_disk($registry, $entry)
{
    edm_registry_validate($registry);
    $next = $registry;
    $next['disks'][] = $entry;
    return edm_registry_validate($next);
}

function edm_registry_update_disk($registry, $uuid, $name, $mountFolder)
{
    edm_registry_validate($registry);
    $next = $registry;
    $found = false;
    foreach ($next['disks'] as &$disk) {
        if (strcasecmp($disk['luks_uuid'], $uuid) !== 0) {
            continue;
        }
        $disk['name'] = $name;
        $disk['mount_folder'] = $mountFolder;
        $found = true;
        break;
    }
    unset($disk);
    if (!$found) {
        throw new RuntimeException('Registered disk not found.');
    }
    return edm_registry_validate($next);
}

function edm_registry_remove_disk($registry, $uuid)
{
    edm_registry_validate($registry);
    $next = $registry;
    $next['disks'] = array_values(array_filter($registry['disks'], function ($disk) use ($uuid) {
        return strcasecmp($disk['luks_uuid'], $uuid) !== 0;
    }));
    if (count($next['disks']) === count($registry['disks'])) {
        throw new RuntimeException('Registered disk not found.');
    }
    return edm_registry_validate($next);
}
