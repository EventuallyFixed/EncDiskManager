<?php
/**
 * EncDiskManager registry schema and validation.
 *
 * This file validates registry data only. It does not read or write disks,
 * mount filesystems, invoke cryptsetup, or persist the registry.
 */

function edm_registry_empty()
{
    return array(
        'version' => 1,
        'disks' => array()
    );
}

function edm_registry_mapper_name($uuid)
{
    $normalised = strtolower(str_replace('-', '', $uuid));
    return 'edm_' . $normalised;
}

function edm_registry_validate($registry)
{
    if (!is_array($registry)
        || !isset($registry['version'])
        || $registry['version'] !== 1
        || !isset($registry['disks'])
        || !is_array($registry['disks'])) {
        throw new RuntimeException('Registry must contain version 1 and a disks array.');
    }

    $seenUuids = array();
    $seenFolders = array();
    $seenMappers = array();

    foreach ($registry['disks'] as $index => $disk) {
        if (!is_array($disk)) {
            throw new RuntimeException('Registry disk entry ' . $index . ' is invalid.');
        }

        $required = array('luks_uuid', 'name', 'mount_folder', 'mapper_name', 'enabled');
        $keys = array_keys($disk);
        sort($keys);
        $expected = $required;
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('Registry disk entry ' . $index . ' has missing or unexpected fields.');
        }

        if (!is_string($disk['luks_uuid'])
            || !preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/', $disk['luks_uuid'])) {
            throw new RuntimeException('Registry disk entry ' . $index . ' has an invalid LUKS UUID.');
        }

        $uuid = strtolower($disk['luks_uuid']);
        if (isset($seenUuids[$uuid])) {
            throw new RuntimeException('Duplicate LUKS UUID in registry.');
        }
        $seenUuids[$uuid] = true;

        if (!is_string($disk['name'])
            || trim($disk['name']) === ''
            || strlen($disk['name']) > 160
            || preg_match('/[\x00-\x1F\x7F]/', $disk['name'])) {
            throw new RuntimeException('Registry disk entry ' . $index . ' has an invalid display name.');
        }

        if (!is_string($disk['mount_folder'])
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/', $disk['mount_folder'])) {
            throw new RuntimeException('Registry disk entry ' . $index . ' has an invalid mount folder name.');
        }

        $folderKey = strtolower($disk['mount_folder']);
        if (isset($seenFolders[$folderKey])) {
            throw new RuntimeException('Duplicate mount folder in registry.');
        }
        $seenFolders[$folderKey] = true;

        $expectedMapper = edm_registry_mapper_name($uuid);
        if (!is_string($disk['mapper_name']) || $disk['mapper_name'] !== $expectedMapper) {
            throw new RuntimeException('Registry disk entry ' . $index . ' has an invalid mapper name.');
        }
        if (isset($seenMappers[$disk['mapper_name']])) {
            throw new RuntimeException('Duplicate mapper name in registry.');
        }
        $seenMappers[$disk['mapper_name']] = true;

        if (!is_bool($disk['enabled'])) {
            throw new RuntimeException('Registry disk entry ' . $index . ' has an invalid enabled flag.');
        }
    }

    return $registry;
}

function edm_registry_decode($json)
{
    if (!is_string($json)) {
        throw new RuntimeException('Registry content must be text.');
    }

    $registry = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Registry JSON is malformed: ' . json_last_error_msg());
    }

    return edm_registry_validate($registry);
}

function edm_registry_encode($registry)
{
    edm_registry_validate($registry);

    $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Registry could not be encoded as JSON.');
    }

    return $json . "\n";
}
