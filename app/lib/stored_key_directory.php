<?php
/**
 * Root-only private key-directory preparation. Never follows an existing
 * symlink and never changes permissions on a pre-existing unsafe directory.
 * The parent must be the validated private registry data directory.
 */
require_once __DIR__ . '/registry.php';

function edm_stored_key_directory($dataDirectory)
{
    edm_registry_assert_directory($dataDirectory);
    $path = $dataDirectory . '/keys';
    if (is_link($path)) throw new RuntimeException('Key directory symlink rejected.');
    if (!file_exists($path)) {
        $previous = umask(0077);
        try {
            if (!@mkdir($path, 0700)) {
                throw new RuntimeException('Could not create private key directory.');
            }
        } finally {
            umask($previous);
        }
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
        || $stat['uid'] !== 0 || ($stat['mode'] & 0777) !== 0700
        || realpath($path) !== $path) {
        throw new RuntimeException('Key directory must be root-owned mode 0700.');
    }
    return $path;
}
