<?php
/**
 * Read-only disk selection check. Device names from the browser are untrusted.
 * This never grants permission to modify a disk.
 */
require_once __DIR__ . '/whole_usb_disk_stable_candidate.php';

function edm_whole_disk_preview_selection($post, $first, $second)
{
    if (!is_array($post) || !isset($post['disk']) || !is_string($post['disk'])
        || !preg_match('/^\/dev\/(sd[a-z]+)$/D', $post['disk'], $matches)) {
        return false;
    }
    return edm_whole_usb_disk_stable_candidate($first, $second, $matches[1]);
}
