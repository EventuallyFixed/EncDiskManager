<?php
/**
 * Fixture-only holder probe for an isolated synthetic sysfs tree.
 * Never reads live /sys and never authorizes disk operations.
 * An existing holder is unsafe even when its metadata looks idle.
 */
function edm_fixture_block_holders_clear($root, $names)
{
    if (!is_string($root) || $root === '' || !is_array($names)
        || count($names) === 0 || count($names) > 256) return false;
    $base = $root . '/class/block';
    $seen = array();
    foreach ($names as $name) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)
            || isset($seen[$name])) return false;
        $seen[$name] = true;
        $path = $base . '/' . $name;
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) return false;
        $holders = $path . '/holders';
        // A missing or unreadable holder directory cannot mean "empty".
        if (!is_dir($holders) || is_link($holders)) return false;
        $entries = @scandir($holders);
        if (!is_array($entries)) return false;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') return false;
        }
    }
    return true;
}
