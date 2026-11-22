<?php
/**
 * Pure policy for optional locally stored unlock keys.
 * No key generation, persistence or cryptsetup invocation occurs here.
 */
function edm_stored_key_policy($dataDirectory, $uuid, $enabled, $recoveryVerified)
{
    if (!is_string($dataDirectory) || $dataDirectory === ''
        || $dataDirectory[0] !== '/' || strpos($dataDirectory, '..') !== false
        || !is_string($uuid)
        || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)
        || !is_bool($enabled) || !is_bool($recoveryVerified))
        return null;
    $normalized = strtolower(str_replace('-', '', $uuid));
    if ($enabled && !$recoveryVerified) return null;
    return array(
        'enabled' => $enabled,
        'key_directory' => $dataDirectory . '/keys',
        'key_path' => $dataDirectory . '/keys/' . $normalized . '.key',
        'directory_mode' => 0700,
        'file_mode' => 0600,
        'backup_excluded' => true,
        'requires_recovery_passphrase' => true,
        'requires_verified_keyslot_addition' => $enabled,
        'requires_explicit_retirement' => !$enabled
    );
}
