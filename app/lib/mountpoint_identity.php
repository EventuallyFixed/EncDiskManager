<?php
/**
 * Bind a mount destination to the same root-owned directory inode across
 * preflight and execution. Never follow a symlink for the leaf.
 */
function edm_mountpoint_identity($destination)
{
    if (!is_string($destination)
        || !preg_match('#^/mnt/[A-Za-z0-9][A-Za-z0-9_-]{0,47}$#D', $destination)
        || is_link('/mnt') || is_link($destination)) return null;
    $parent = @lstat('/mnt');
    $leaf = @lstat($destination);
    if (!is_array($parent) || !is_array($leaf)) return null;
    foreach (array($parent, $leaf) as $stat) {
        if (($stat['mode'] & 0170000) !== 0040000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0)
            return null;
    }
    return array(
        'parent_dev' => $parent['dev'], 'parent_ino' => $parent['ino'],
        'leaf_dev' => $leaf['dev'], 'leaf_ino' => $leaf['ino']
    );
}
function edm_mountpoint_identity_matches($destination, $identity)
{
    return is_array($identity) && edm_mountpoint_identity($destination) === $identity;
}
