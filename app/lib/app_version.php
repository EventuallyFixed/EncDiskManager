<?php
/**
 * Read the installed application version from package metadata.
 */
function edm_app_version()
{
    $lines = @file(__DIR__ . '/../apkg.rc', FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return '';
    foreach ($lines as $line) {
        if (preg_match('/^Version:\s*([0-9]+(?:\.[0-9]+)+)\s*$/D', $line, $match)) {
            return $match[1];
        }
    }
    return '';
}
