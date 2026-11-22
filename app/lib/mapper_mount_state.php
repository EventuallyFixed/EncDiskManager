<?php
/**
 * Determine whether a verified mapper is mounted by kernel major:minor ID.
 * Mount source names and /dev/mapper aliases are not authoritative.
 * Read-only, not operation authorization.
 */
function edm_mapper_mount_state($mapper, $mountInfoLines)
{
    if (!is_array($mapper) || !isset($mapper['dev_id'])
        || !is_string($mapper['dev_id'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $mapper['dev_id'])
        || !is_array($mountInfoLines)) return null;
    $mounted = false;
    foreach ($mountInfoLines as $line) {
        if (!is_string($line)) return null;
        $fields = preg_split('/\\s+/', trim($line));
        $separator = array_search('-', $fields, true);
        if ($separator === false || $separator < 6 || !isset($fields[2], $fields[$separator + 2])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $fields[2])) return null;
        if ($fields[2] === $mapper['dev_id']) $mounted = true;
    }
    return $mounted;
}

function edm_read_mapper_mount_state($mapper, $mountInfoPath = '/proc/self/mountinfo')
{
    if (!is_string($mountInfoPath) || $mountInfoPath === '') return null;
    $first = @file($mountInfoPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($first)) return null;
    $mounted = edm_mapper_mount_state($mapper, $first);
    if ($mounted === null) return null;
    $second = @file($mountInfoPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($second) || $first !== $second) return null;
    return $mounted;
}
