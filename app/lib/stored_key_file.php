<?php
/**
 * Create a new private random keyfile without exposing its bytes to logs,
 * command arguments, the registry or HTTP. This is storage only: a separate
 * verified cryptsetup keyslot transaction must precede activation.
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
    try {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) throw new RuntimeException('Cannot create private key.');
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
        @unlink($path);
        throw $error;
    } finally {
        umask($oldUmask);
        unset($secret);
    }
}
