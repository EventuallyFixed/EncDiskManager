<?php
/**
 * Create or verify a private mountpoint beneath /mnt, without recursive mkdir.
 * Call only as root while holding the shared disk-operation lock, after
 * checking registered folder ownership and before the final mountinfo probe.
 */
function edm_secure_mount_directory($folder)
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
        || !is_string($folder)
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/D', $folder))
        return false;
    $parent = @lstat('/mnt');
    if ($parent === false || ($parent['mode'] & 0170000) !== 0040000
        || $parent['uid'] !== 0 || is_link('/mnt')) return false;
    $path = '/mnt/' . $folder;
    if (is_link($path)) return false;
    $created = false;
    if (@lstat($path) === false) {
        $old = umask(0077);
        try { $created = @mkdir($path, 0700, false); }
        finally { umask($old); }
        if (!$created) return false;
    }
    $stat = @lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
        || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0
        || is_link($path)) return false;
    // Do not chmod/chown pre-existing paths: they may belong to other services.
    return true;
}
