<?php
/**
 * Fixture-only synthetic sysfs slaves probe.
 * Never accesses live /sys; an unknown or nonempty slaves directory blocks.
 * Not a complete dependency proof or disk-operation authorization.
 */
function edm_fixture_block_slaves_clear($root, $names)
{
    if (!is_string($root) || $root === '' || !is_array($names)
        || count($names) === 0 || count($names) > 256) return false;
    $canonicalRoot = realpath($root);
    if ($canonicalRoot === false || !is_dir($canonicalRoot)) return false;
    $base = $canonicalRoot . '/class/block';
    $seen = array();
    foreach ($names as $name) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)
            || isset($seen[$name])) return false;
        $seen[$name] = true;
        $resolved = realpath($base . '/' . $name);
        if ($resolved === false || !is_dir($resolved)
            || strpos($resolved, $canonicalRoot . '/') !== 0) return false;
        $slaves = $base . '/' . $name . '/slaves';
        if (!is_dir($slaves) || is_link($slaves)) return false;
        $resolvedSlaves = realpath($slaves);
        if ($resolvedSlaves === false || $resolvedSlaves !== $resolved . '/slaves')
            return false;
        $entries = @scandir($slaves);
        if (!is_array($entries)) return false;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') return false;
        }
    }
    return true;
}
