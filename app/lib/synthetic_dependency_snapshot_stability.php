<?php
require_once __DIR__ . '/synthetic_whole_disk_dependency_check.php';

/**
 * Pure synthetic two-snapshot consistency check.
 * Neither snapshot proves anything about live devices or authorizes execution.
 */
function edm_synthetic_dependency_snapshots_stable($first, $second, $root)
{
    if (!is_array($first) || !is_array($second)) return false;
    foreach (array($first, $second) as $snapshot) {
        if (!isset($snapshot['sources'], $snapshot['graph'], $snapshot['identity'])
            || !is_array($snapshot['identity'])
            || !edm_synthetic_whole_disk_dependency_check(
                $snapshot['sources'], $snapshot['graph'], $root)) return false;
        foreach (array('name', 'dev_id', 'sysfs_path', 'serial', 'size_bytes') as $key) {
            if (!array_key_exists($key, $snapshot['identity'])) return false;
        }
        if (!is_string($snapshot['identity']['name'])
            || $snapshot['identity']['name'] !== $root
            || !is_string($snapshot['identity']['dev_id'])
            || !preg_match('/^[0-9]+:[0-9]+$/D', $snapshot['identity']['dev_id'])
            || !is_string($snapshot['identity']['sysfs_path'])
            || $snapshot['identity']['sysfs_path'] === ''
            || !is_string($snapshot['identity']['serial'])
            || $snapshot['identity']['serial'] === ''
            || !is_int($snapshot['identity']['size_bytes'])
            || $snapshot['identity']['size_bytes'] <= 0) return false;
    }
    // Strict equality rejects changes to identity, source statuses, topology,
    // partition list, ordering, flags or unknown additional fields.
    return $first === $second;
}
