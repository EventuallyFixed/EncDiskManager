<?php
/**
 * Compare the identity approved by the user with a freshly observed disk.
 * This is a pure check, not permission to execute an erase.
 */
function edm_whole_disk_identity_matches($expected, $current)
{
    if (!is_array($expected) || !is_array($current)) return false;
    foreach (array('name', 'dev_id', 'sysfs_path', 'size_sectors') as $field) {
        if (!isset($expected[$field], $current[$field])
            || !is_string($expected[$field]) || !is_string($current[$field])
            || $expected[$field] === '' || $expected[$field] !== $current[$field]) {
            return false;
        }
    }
    if (!preg_match('/^sd[a-z]+$/D', $expected['name'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $expected['dev_id'])
        || !ctype_digit($expected['size_sectors']) || $expected['size_sectors'] === '0') {
        return false;
    }
    foreach (array('serial', 'partitions', 'partition_dev_ids') as $field) {
        if (!array_key_exists($field, $expected) || !array_key_exists($field, $current)
            || $expected[$field] !== $current[$field]) return false;
    }
    return true;
}
