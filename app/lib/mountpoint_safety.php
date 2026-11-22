<?php
/**
 * Read-only mountpoint gate. Must be run as root under the shared disk lock.
 * Never creates, removes or follows a symlink in the destination path.
 */
function edm_mountpoint_safety($destination, $mountInfoLines, $expectedDevId,
    $expectMounted)
{
    if (!is_string($destination)
        || !preg_match('#^/mnt/[A-Za-z0-9][A-Za-z0-9_-]{0,47}$#D', $destination)
        || !is_string($expectedDevId)
        || !preg_match('/^[0-9]+:[0-9]+$/D', $expectedDevId)
        || !is_bool($expectMounted) || !is_array($mountInfoLines))
        return false;
    $root = @lstat('/mnt');
    $leaf = @lstat($destination);
    if ($root === false || $leaf === false
        || ($root['mode'] & 0170000) !== 0040000
        || ($leaf['mode'] & 0170000) !== 0040000
        || $root['uid'] !== 0 || $leaf['uid'] !== 0
        || (($leaf['mode'] & 0022) !== 0)
        || is_link($destination)) return false;

    $matches = 0;
    foreach ($mountInfoLines as $line) {
        if (!is_string($line)) return false;
        $fields = preg_split('/\\s+/', trim($line));
        $separator = array_search('-', $fields, true);
        if ($separator === false || $separator < 6
            || !isset($fields[2], $fields[4], $fields[$separator + 1])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $fields[2]))
            return false;
        if ($fields[4] !== $destination) continue;
        ++$matches;
        if ($fields[2] !== $expectedDevId
            || $fields[$separator + 1] !== 'ext4') return false;
    }
    return $expectMounted ? $matches === 1 : $matches === 0;
}
