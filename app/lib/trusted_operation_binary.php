<?php
/**
 * Check exact trusted system binary path without executing it.
 * The NAS must confirm these paths and ownership before enabling operations.
 */
function edm_trusted_operation_binary($path)
{
    if (!in_array($path, array('/bin/mount', '/bin/umount'), true))
        return false;
    $parts = explode('/', trim($path, '/'));
    $current = '';
    foreach ($parts as $part) {
        $current .= '/' . $part;
        $stat = @lstat($current);
        if ($stat === false || $stat['uid'] !== 0
            || ($stat['mode'] & 0022) !== 0
            || is_link($current)) return false;
        if ($current !== $path && ($stat['mode'] & 0170000) !== 0040000)
            return false;
    }
    $stat = @lstat($path);
    return $stat !== false && ($stat['mode'] & 0170000) === 0100000
        && ($stat['mode'] & 0111) !== 0;
}
