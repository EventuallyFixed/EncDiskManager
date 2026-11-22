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
        || !preg_match('/^[1-9][0-9]*$/D', $expected['size_sectors'])) {
        return false;
    }
    foreach (array('serial', 'partitions', 'partition_dev_ids') as $field) {
        if (!array_key_exists($field, $expected) || !array_key_exists($field, $current)
            || $expected[$field] !== $current[$field]) return false;
    }
    return true;
}

/**
 * Serialize a confirmed partition snapshot for the read-only shell helper.
 * Empty string means no partitions; false means incomplete/invalid identity.
 */
function edm_whole_disk_partition_snapshot($identity)
{
    if (!is_array($identity) || !isset($identity['name'], $identity['partitions'],
        $identity['partition_dev_ids']) || !is_string($identity['name'])
        || !preg_match('/^sd[a-z]+$/D', $identity['name'])
        || !is_array($identity['partitions'])
        || !is_array($identity['partition_dev_ids'])) return false;

    $names = $identity['partitions'];
    $sorted = $names;
    sort($sorted, SORT_STRING);
    if ($names !== $sorted || count($names) !== count(array_unique($names))
        || count($names) !== count($identity['partition_dev_ids'])) {
        return false;
    }
    $parts = array();
    foreach ($names as $name) {
        if (!is_string($name)
            || !preg_match('/^' . preg_quote($identity['name'], '/') . '[0-9]+$/D', $name)
            || !isset($identity['partition_dev_ids'][$name])
            || !is_string($identity['partition_dev_ids'][$name])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $identity['partition_dev_ids'][$name])) {
            return false;
        }
        $parts[] = $name . '=' . $identity['partition_dev_ids'][$name];
    }
    return implode(',', $parts);
}
