<?php
/**
 * Validate a selected disk for the read-only confirmation preview.
 * This never authorizes disk modification.
 */
require_once __DIR__ . '/whole_usb_disk_preflight.php';

function edm_whole_disk_preview_selection($post, $inventory)
{
    if (!is_array($post) || !isset($post['disk']) || !is_string($post['disk'])
        || !preg_match('/^\/dev\/(sd[a-z]+)$/D', $post['disk'], $matches)) {
        return false;
    }
    return edm_whole_usb_disk_preflight($inventory, $matches[1]);
}
