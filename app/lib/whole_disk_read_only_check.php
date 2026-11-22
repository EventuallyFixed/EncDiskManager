<?php
/**
 * Run the existing read-only whole-disk helper.
 * A successful check never authorizes formatting or encryption.
 */
function edm_whole_disk_read_only_check($arguments)
{
    if (!is_array($arguments) || count($arguments) !== 6
        || $arguments[0] !== 'check') return false;
    foreach ($arguments as $argument) {
        if (!is_string($argument) || strpos($argument, "\0") !== false) return false;
    }
    if (!function_exists('exec')) return false;
    $helper = __DIR__ . '/../bin/encdiskmanager-whole-disk-helper';
    if (!is_file($helper) || !is_readable($helper)) return false;
    $command = '/bin/sh ' . escapeshellarg($helper);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = array();
    $status = 1;
    exec($command . ' 2>/dev/null', $output, $status);
    return $status === 0;
}
