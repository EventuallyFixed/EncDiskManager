<?php
/**
 * Validate the destructive whole-disk request before any privileged command.
 * This function does not itself authorize or execute disk formatting.
 */
require_once __DIR__ . '/privileged_web_authorization.php';
require_once __DIR__ . '/whole_usb_disk_confirmation.php';

function edm_whole_disk_format_request($method, $https, $authenticated,
    $expectedCsrf, $post)
{
    if (!is_array($post)) return false;
    $csrf = isset($post['csrf_token']) ? $post['csrf_token'] : null;
    if (!edm_privileged_web_request_authorized(
        $method, $https, $authenticated, $expectedCsrf, $csrf)) return false;

    $expected = array('csrf_token', 'disk', 'warning_accepted',
        'confirmation_phrase', 'passphrase', 'passphrase_confirm');
    $actual = array_keys($post);
    sort($expected);
    sort($actual);
    if ($actual !== $expected) return false;

    if (!is_string($post['disk']) ||
        !preg_match('~^/dev/sd[a-z]+$~D', $post['disk'])) return false;
    if ($post['warning_accepted'] !== 'yes' ||
        !is_string($post['confirmation_phrase']) ||
        !edm_whole_disk_erase_confirmed(true, $post['confirmation_phrase'])) {
        return false;
    }
    if (!is_string($post['passphrase']) ||
        !is_string($post['passphrase_confirm']) ||
        $post['passphrase'] === '' ||
        strlen($post['passphrase']) > 4096 ||
        !hash_equals($post['passphrase'], $post['passphrase_confirm'])) {
        return false;
    }
    return $post['disk'];
}
