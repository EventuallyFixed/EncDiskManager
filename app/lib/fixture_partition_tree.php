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
    $seenNumbers = array();
    $seenPaths = array($diskPath => true);
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return $fail;
        $path = $base . '/' . $name;
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) return $fail;
        if (isset($seenPaths[$resolved])) return $fail;
        $seenPaths[$resolved] = true;
        $parent = dirname($resolved);
        // A nested block entry beneath the selected disk is not a
        // recognized immediate partition and cannot be ignored.
        if ($parent !== $diskPath) {
            if (strpos($resolved, $diskPath . '/') === 0) return $fail;
            continue;
        }
        // Any immediate child block entry of this disk must provide a
        // readable, valid partition marker; omission is not safe emptiness.
        if (!is_file($path . '/partition')) return $fail;
        $number = @file_get_contents($path . '/partition');
        if ($number === false || !preg_match('/^[1-9][0-9]*$/D', trim($number))) return $fail;
        $numberText = trim($number);
        $partitionNumber = filter_var($numberText, FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1)));
        if ($partitionNumber === false || isset($seenNumbers[$partitionNumber])) return $fail;
        $seenNumbers[$partitionNumber] = true;
        $nodes[$name] = array('children' => array(), 'partition_number' => $partitionNumber);
        $nodes[$disk]['children'][] = $name;
    }
    if (count($nodes) > 256) return $fail;
    sort($nodes[$disk]['children'], SORT_STRING);
    return array('complete' => true, 'nodes' => $nodes);
}
