<?php
require_once __DIR__ . '/registry.php';

/** Store generated credentials outside the web root and registry backups. */
function edm_unlock_key_path($directory, $uuid)
{
    edm_registry_assert_directory($directory);
    if (!is_string($uuid)
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)) {
        throw new RuntimeException('Invalid LUKS UUID.');
    }
    return $directory . '/unlock-' . strtolower($uuid) . '.key';
}

function edm_unlock_key_read($directory, $uuid)
{
    $path = edm_unlock_key_path($directory, $uuid);
    if (!file_exists($path) && !is_link($path)) return null;
    edm_registry_assert_file($path, 'Unlock key');
    $key = file_get_contents($path);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Invalid stored unlock key.');
    }
    return $key;
}

/**
 * Create a fresh 256-bit key and enrol it via the supplied privileged operation.
 * The caller must serialize configuration changes and disk operations.
 * Enrol returns true only when cryptsetup has successfully added the keyslot.
 */
function edm_unlock_key_enrol($directory, $uuid, $enrol)
{
    $path = edm_unlock_key_path($directory, $uuid);
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('An unlock key already exists.');
    }
    $temporary = tempnam($directory, '.unlock-key-');
    if ($temporary === false) throw new RuntimeException('Could not create private keyfile.');
    try {
        if (!chmod($temporary, 0600) || !chown($temporary, 0)
            || !chgrp($temporary, 0)) {
            throw new RuntimeException('Could not secure keyfile.');
        }
        if (file_put_contents($temporary, random_bytes(32)) !== 32) {
            throw new RuntimeException('Could not write generated key.');
        }
        if ($enrol($temporary) !== true) {
            throw new RuntimeException('Could not enrol unlock key.');
        }
        // Publish without replacing an existing credential.
        if (!link($temporary, $path)) {
            // Retain the enrolled key securely if publication fails.
            $temporary = null;
            throw new RuntimeException('Key enrolled but publication failed; private key retained for recovery.');
        }
    } finally {
        if ($temporary !== null) unlink($temporary);
    }
}

function edm_unlock_key_remove($directory, $uuid)
{
    $path = edm_unlock_key_path($directory, $uuid);
    if (!file_exists($path) && !is_link($path)) return;
    edm_registry_assert_file($path, 'Unlock key');
    if (!unlink($path)) throw new RuntimeException('Could not remove stored unlock key.');
}
