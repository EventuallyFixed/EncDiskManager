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

    // Decode as objects first so JSON objects cannot be confused with arrays.
    $decoded = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Registry JSON is malformed: ' . json_last_error_msg());
    }

    if (!($decoded instanceof stdClass)) {
        throw new RuntimeException('Registry root must be a JSON object.');
    }

    $root = get_object_vars($decoded);
    $rootKeys = array_keys($root);
    sort($rootKeys);
    if ($rootKeys !== array('disks', 'version')
        || !is_array($root['disks'])) {
        throw new RuntimeException('Registry must contain only version and a disks array.');
    }

    $disks = array();
    foreach ($root['disks'] as $index => $diskObject) {
        if (!($diskObject instanceof stdClass)) {
            throw new RuntimeException('Registry disk entry ' . $index . ' must be a JSON object.');
        }
        $disks[] = get_object_vars($diskObject);
    }

    return edm_registry_validate(array(
        'version' => $root['version'],
        'disks' => $disks
    ));
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

/**
 * Load the registry. A missing registry is treated as a new empty registry.
 * The caller must supply the trusted, absolute path from the app configuration.
 */
function edm_registry_load($registryPath)
{
    if (!is_string($registryPath) || $registryPath === '' || $registryPath[0] !== '/') {
        throw new RuntimeException('Registry path must be an absolute path.');
    }

    if (is_link($registryPath)) {
        throw new RuntimeException('Registry file must not be a symbolic link.');
    }

    if (!file_exists($registryPath)) {
        return edm_registry_empty();
    }

    if (!is_file($registryPath) || !is_readable($registryPath)) {
        throw new RuntimeException('Registry file is not a readable regular file.');
    }

    $json = file_get_contents($registryPath);
    if ($json === false) {
        throw new RuntimeException('Registry file could not be read.');
    }

    return edm_registry_decode($json);
}

/**
 * Save using an exclusive lock and same-directory atomic rename.
 * The containing data directory must already exist and be secured by init.sh.
 */
function edm_registry_save($registryPath, $registry)
{
    if (!is_string($registryPath) || $registryPath === '' || $registryPath[0] !== '/') {
        throw new RuntimeException('Registry path must be an absolute path.');
    }

    $directory = dirname($registryPath);
    if (!is_dir($directory) || is_link($directory) || !is_writable($directory)) {
        throw new RuntimeException('Registry data directory is unavailable or unsafe.');
    }

    if (is_link($registryPath)) {
        throw new RuntimeException('Registry file must not be a symbolic link.');
    }

    $json = edm_registry_encode($registry);
    $lockPath = $registryPath . '.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Registry lock file must not be a symbolic link.');
    }

    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Registry lock file could not be opened.');
    }

    $temporaryPath = null;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Registry could not be locked.');
        }

        $temporaryPath = tempnam($directory, '.encdiskmanager-registry-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Temporary registry file could not be created.');
        }

        $written = file_put_contents($temporaryPath, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            throw new RuntimeException('Registry could not be written completely.');
        }

        if (!chmod($temporaryPath, 0600)) {
            throw new RuntimeException('Registry permissions could not be secured.');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            if (!chown($temporaryPath, 0) || !chgrp($temporaryPath, 0)) {
                throw new RuntimeException('Registry ownership could not be secured.');
            }
        }

        if (!rename($temporaryPath, $registryPath)) {
            throw new RuntimeException('Registry could not be replaced atomically.');
        }
        $temporaryPath = null;
    } finally {
        if ($temporaryPath !== null && file_exists($temporaryPath)) {
            unlink($temporaryPath);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
