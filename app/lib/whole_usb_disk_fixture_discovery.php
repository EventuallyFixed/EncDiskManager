<?php
/**
 * Read-only synthetic sysfs adapter. Caller supplies a directory containing
 * class/block symlinks and device metadata; no live system paths or commands.
 * This adapter is intentionally NOT an encryption authorization boundary.
 */
function edm_whole_usb_disk_fixture_snapshot($root)
{
    $fail = array('complete' => false, 'disks' => array());
    if (!is_string($root) || $root === '' || !is_dir($root . '/class/block')) return $fail;
    $entries = @scandir($root . '/class/block');
    if (!is_array($entries)) return $fail;
    $disks = array();
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return $fail;
        $base = $root . '/class/block/' . $name;
        $resolved = realpath($base);
        if ($resolved === false || !is_dir($resolved)) return $fail;
        if (is_file($base . '/partition')) continue;
        if (!preg_match('~/(usb[0-9]+)(/|$)~', $resolved)) continue;
        $dev = @file_get_contents($base . '/dev');
        $size = @file_get_contents($base . '/size');
        $serial = @file_get_contents($base . '/device/serial');
        if ($dev === false || $size === false || $serial === false
            || !preg_match('/^[0-9]+:[0-9]+$/D', trim($dev))
            || !preg_match('/^[0-9]+$/D', trim($size))
            || trim($serial) === '' || !ctype_digit(trim($size))) return $fail;
        $sectors = (int)trim($size);
        if ($sectors <= 0 || $sectors > intdiv(PHP_INT_MAX, 512)) return $fail;
        $disks[] = array('name' => $name, 'dev_id' => trim($dev),
            'sysfs_path' => $resolved, 'serial' => trim($serial),
            'transport' => 'usb', 'size_bytes' => $sectors * 512,
            'whole_disk' => true,
            // Unknown until separate complete mount, swap, holder, signature,
            // NAS dependency and topology verification is implemented.
            'system_dependency' => true, 'busy' => true, 'mounted' => true,
            'has_holders' => true, 'topology_complete' => false,
            'signatures_complete' => false, 'read_only' => true);
    }
    return array('complete' => true, 'disks' => $disks);
}
