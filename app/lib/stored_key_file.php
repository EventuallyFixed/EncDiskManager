<?php
/**
 * Create a new private random keyfile without exposing its bytes to logs,
 * command arguments, the registry or HTTP. This is storage only: a separate
 * verified cryptsetup keyslot transaction must precede activation.
 * A failed keyslot transaction must retire the newly created key.
 */
require_once __DIR__ . '/stored_key_policy.php';
require_once __DIR__ . '/stored_key_directory.php';

function edm_stored_key_create($dataDirectory, $uuid, $recoveryVerified)
{
    $policy = edm_stored_key_policy($dataDirectory, $uuid, true, $recoveryVerified);
    if ($policy === null) throw new RuntimeException('Stored-key preflight rejected.');
    $directory = edm_stored_key_directory($dataDirectory);
    $path = $policy['key_path'];
    if (is_link($path) || file_exists($path))
        throw new RuntimeException('Stored key already exists or is unsafe.');
    $secret = random_bytes(64);
    $oldUmask = umask(0077);
    $handle = null;
    $created = false;
    try {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) throw new RuntimeException('Cannot create private key.');
        $created = true;
        $stat = fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0600)
            throw new RuntimeException('Private key ownership or permissions invalid.');
        $offset = 0;
        while ($offset < strlen($secret)) {
            $n = fwrite($handle, substr($secret, $offset));
            if (!is_int($n) || $n <= 0)
                throw new RuntimeException('Private key write failed.');
            $offset += $n;
        }
        if (!fflush($handle)) throw new RuntimeException('Private key flush failed.');
        if (function_exists('fsync') && !fsync($handle))
            throw new RuntimeException('Private key sync failed.');
        fclose($handle);
        $handle = null;
        return $path;
    } catch (Throwable $error) {
        if (is_resource($handle)) fclose($handle);
        if ($created) @unlink($path);
        throw $error;
    } finally {
        umask($oldUmask);
        unset($secret);
    }
}

/**
 * Read a key only from a root-owned, private, regular file.
 * Call only under the shared disk-operation lock after verifying the
 * registration and device identity. Never return the key to HTTP clients.
 */
function edm_stored_key_read($dataDirectory, $uuid)
{
    $policy = edm_stored_key_policy($dataDirectory, $uuid, false, false);
    if ($policy === null) throw new RuntimeException('Invalid stored-key identity.');
    edm_registry_assert_directory($dataDirectory);
    $directory = $dataDirectory . '/keys';
    if (is_link($directory)) throw new RuntimeException('Key directory symlink rejected.');
    $directoryStat = @lstat($directory);
    if ($directoryStat === false || ($directoryStat['mode'] & 0170000) !== 0040000
        || $directoryStat['uid'] !== 0 || ($directoryStat['mode'] & 0777) !== 0700)
        throw new RuntimeException('Key directory metadata invalid.');
    $path = $policy['key_path'];
    if (is_link($path)) throw new RuntimeException('Key symlink rejected.');
    $stat = @lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
        || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0600
        || $stat['size'] !== 64 || $stat['nlink'] !== 1)
        throw new RuntimeException('Stored-key metadata invalid.');
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Stored key cannot be opened.');
    try {
        $opened = fstat($handle);
        if ($opened === false || $opened['dev'] !== $stat['dev']
            || $opened['ino'] !== $stat['ino'] || $opened['size'] !== 64)
            throw new RuntimeException('Stored key changed during open.');
        $secret = stream_get_contents($handle, 65);
        if (!is_string($secret) || strlen($secret) !== 64)
            throw new RuntimeException('Stored key length invalid.');
        return $secret;
    } finally {
        fclose($handle);
    }
}
