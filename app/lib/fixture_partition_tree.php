<?php
/**
 * Fixture-only partition relationship collector.
 * The caller supplies an isolated test root; no live /sys or /proc access.
 * This does not assert that holders, mounts, swap or system dependencies
 * are complete, and must never be used for encryption authorization.
 */
function edm_fixture_partition_tree($root, $disk)
{
    $fail = array('complete' => false, 'nodes' => array());
    if (!is_string($root) || $root === '' || !is_string($disk)
        || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $disk)) return $fail;
    $base = $root . '/class/block';
    if (!is_dir($base) || !is_dir($base . '/' . $disk)
        || is_file($base . '/' . $disk . '/partition')) return $fail;
    $entries = @scandir($base);
    if (!is_array($entries)) return $fail;
    $diskPath = realpath($base . '/' . $disk);
    if ($diskPath === false) return $fail;
    $nodes = array($disk => array('children' => array()));
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return $fail;
        $path = $base . '/' . $name;
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) return $fail;
        $parent = dirname($resolved);
        if ($parent !== $diskPath) continue;
        // Any immediate child block entry of this disk must provide a
        // readable, valid partition marker; omission is not safe emptiness.
        if (!is_file($path . '/partition')) return $fail;
        $number = @file_get_contents($path . '/partition');
        if ($number === false || !preg_match('/^[1-9][0-9]*$/D', trim($number))) return $fail;
        $nodes[$name] = array('children' => array(), 'partition_number' => (int)trim($number));
        $nodes[$disk]['children'][] = $name;
    }
    if (count($nodes) > 256) return $fail;
    sort($nodes[$disk]['children'], SORT_STRING);
    return array('complete' => true, 'nodes' => $nodes);
}
