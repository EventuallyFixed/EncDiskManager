<?php
/**
 * Restricted LUKS1 keyslot subprocess. Not exposed to HTTP.
 *
 * The caller MUST hold the shared disk-operation lock, verify the attached
 * USB partition identity, cryptsetup binary, and private keyfile immediately
 * before this call. No secret bytes enter argv, logs, or process output.
 */
require_once __DIR__ . '/trusted_operation_binary.php';

function edm_luks_keyslot_subprocess($plan, $secret, $timeoutSeconds = 30)
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
        || !function_exists('proc_open') || !function_exists('proc_get_status')
        || !is_array($plan) || !isset($plan['action'], $plan['argv'], $plan['stdin'])
        || !is_array($plan['argv']) || !is_string($secret)
        || $secret === '' || strlen($secret) > 4096
        || !is_int($timeoutSeconds) || $timeoutSeconds < 1 || $timeoutSeconds > 120)
        return false;
    $argv = $plan['argv'];
    // Verification plans have the device as the eighth operand.
    $verify = in_array($plan['action'], array('verify-recovery', 'verify-new-key'), true)
        && $plan['stdin'] === 'secret'
        && count($argv) === 8
        && array_slice($argv, 0, 7) === array(
            '/usr/local/modules/usr/sbin/cryptsetup', 'open', '--test-passphrase',
            '--type', 'luks1', '--key-file', '-');
    $add = $plan['action'] === 'add-key'
        && $plan['stdin'] === 'recovery-secret'
        && count($argv) === 8
        && array_slice($argv, 0, 6) === array(
            '/usr/local/modules/usr/sbin/cryptsetup', 'luksAddKey',
            '--type', 'luks1', '--key-file', '-')
        && is_string($argv[7])
        && preg_match('#^/[^[:cntrl:]]+/keys/[a-f0-9]{32}\\.key$#D', $argv[7]);
    if (!$verify && !$add) return false;
    if (!isset($argv[7 - ($add ? 1 : 0)]) || !is_string($argv[7 - ($add ? 1 : 0)])
        || !preg_match('#^/dev/sd[a-z]+[0-9]+$#D', $argv[7 - ($add ? 1 : 0)]))
        return false;
    // Check the canonical executable and every ancestor immediately before launch.
    if (!edm_trusted_operation_binary($argv[0])) return false;
    $pipes = array();
    $proc = @proc_open($argv, array(
        0 => array('pipe', 'r'),
        1 => array('file', '/dev/null', 'w'),
        2 => array('file', '/dev/null', 'w')
    ), $pipes, null, array('PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'),
        array('bypass_shell' => true));
    if (!is_resource($proc)) return false;
    $deadline = microtime(true) + $timeoutSeconds;
    $ok = true;
    $exitCode = null;
    try {
        stream_set_blocking($pipes[0], false);
        $offset = 0;
        $length = strlen($secret);
        while ($offset < $length) {
            if (microtime(true) >= $deadline) { $ok = false; break; }
            $written = @fwrite($pipes[0], substr($secret, $offset, 8192));
            if ($written === false) { $ok = false; break; }
            if ($written === 0) { usleep(20000); continue; }
            $offset += $written;
        }
        fclose($pipes[0]);
        while ($ok) {
            $status = @proc_get_status($proc);
            if (!is_array($status)) { $ok = false; break; }
            if (!$status['running']) { $exitCode = $status['exitcode']; break; }
            if (microtime(true) >= $deadline) {
                $ok = false;
                @proc_terminate($proc, 9);
                break;
            }
            usleep(20000);
        }
    } finally {
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        if (!$ok) {
            @proc_terminate($proc, 9);
            $reapDeadline = microtime(true) + 2;
            do {
                $status = @proc_get_status($proc);
                if (!is_array($status) || !$status['running']) break;
                usleep(20000);
            } while (microtime(true) < $reapDeadline);
        }
        @proc_close($proc);
    }
    return $ok && $exitCode === 0;
}
