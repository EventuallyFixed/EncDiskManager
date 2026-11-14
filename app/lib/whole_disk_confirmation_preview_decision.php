<?php
/**
 * Pure decision for a confirmation preview. It never authorizes execution.
 */
require_once __DIR__ . '/privileged_web_authorization.php';
require_once __DIR__ . '/whole_usb_disk_confirmation.php';

function edm_whole_disk_confirmation_preview_decision(
    $method, $https, $authenticated, $expectedCsrf, $post)
{
    $denied = array('confirmed' => false, 'execution_enabled' => false);
    $csrf = is_array($post) && isset($post['csrf_token']) ? $post['csrf_token'] : null;
    if (!edm_privileged_web_request_authorized(
        $method, $https, $authenticated, $expectedCsrf, $csrf)) {
        return array('status' => 403, 'body' => $denied);
    }
    $accepted = isset($post['warning_accepted']) && $post['warning_accepted'] === 'yes';
    $phrase = isset($post['confirmation_phrase']) && is_string($post['confirmation_phrase'])
        ? $post['confirmation_phrase'] : null;
    $confirmed = edm_whole_disk_erase_confirmed($accepted, $phrase);
    return array('status' => $confirmed ? 200 : 400, 'body' => array(
        'confirmed' => $confirmed, 'execution_enabled' => false
    ));
}
