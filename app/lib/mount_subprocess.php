<?php
require_once __DIR__ . '/trusted_operation_binary.php';
/**
 * Restricted mount/umount runner. Not exposed to HTTP.
 * Caller MUST hold shared disk lock, verify registry ownership and perform
 * authoritative before/after probes. Never use a shell.
 */
function edm_mount_subprocess($plan, $timeoutSeconds = 30)
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
        || !is_int($timeoutSeconds) || $timeoutSeconds < 1
        || $timeoutSeconds > 120 || !is_array($plan)
        || !isset($plan['action'], $plan['argv'], $plan['destination'],
            $plan['mapper_dev_id']) || !is_array($plan['argv']))
        return false;
    $argv = $plan['argv'];
    $destination = $plan['destination'];
    if (!is_string($destination)
        || !preg_match('#^/mnt/[A-Za-z0-9][A-Za-z0-9_-]{0,47}$#D', $destination)
        || !is_string($plan['mapper_dev_id'])
        || !preg_match('/^[0-9]+:[0-9]+$/D', $plan['mapper_dev_id']))
        return false;
    $mount = $plan['action'] === 'mount'
        && count($argv) === 7
        && $argv[0] === '/bin/mount'
        && $argv[1] === '-t' && $argv[2] === 'ext4'
        && $argv[3] === '-o' && $argv[4] === 'nosuid,nodev'
        && is_string($argv[5])
        && preg_match('#^/dev/mapper/edm_[a-f0-9]{32}$#D', $argv[5])
        && $argv[6] === $destination;
    $unmount = $plan['action'] === 'unmount'
        && count($argv) === 2
        && $argv[0] === '/bin/umount' && $argv[1] === $destination;
    if (!$mount && !$unmount) return false;
    if (!edm_trusted_operation_binary($argv[0])) return false;
    if (!function_exists('proc_open') || !function_exists('proc_get_status'))
        return false;
    $pipes = array();
    $process = @proc_open($argv, array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('file', '/dev/null', 'w'),
        2 => array('file', '/dev/null', 'w')
    ), $pipes, null, array('PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'),
        array('bypass_shell' => true));
    if (!is_resource($process)) return false;
    $deadline = microtime(true) + $timeoutSeconds;
    $ok = false;
    try {
        do {
            $status = @proc_get_status($process);
            if (!is_array($status)) break;
            if (!$status['running']) {
                $ok = $status['exitcode'] === 0;
                break;
            }
            if (microtime(true) >= $deadline) break;
            usleep(20000);
        } while (true);
    } finally {
        if (!$ok) {
            @proc_terminate($process, 9);
            $reapDeadline = microtime(true) + 2;
            do {
                $status = @proc_get_status($process);
                if (!is_array($status) || !$status['running']) break;
                usleep(20000);
            } while (microtime(true) < $reapDeadline);
        }
        @proc_close($process);
    }
    return $ok;
}
