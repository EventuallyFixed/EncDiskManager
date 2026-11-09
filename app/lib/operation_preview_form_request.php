<?php
/**
 * Strict form envelope for the read-only operation preview.
 * CSRF is verified separately against the authenticated server session.
 * Never copy client-supplied paths, commands or execution parameters.
 */
require_once __DIR__ . '/privileged_operation_request.php';

function edm_operation_preview_form_request($post)
{
    if (!is_array($post) || count($post) !== 3
        || !array_key_exists('csrf_token', $post)
        || !array_key_exists('action', $post)
        || !array_key_exists('luks_uuid', $post)
        || !is_string($post['csrf_token'])) return null;

    return edm_privileged_operation_request(array(
        'action' => $post['action'],
        'luks_uuid' => $post['luks_uuid']
    ));
}
