<?php
/**
 * Read-only, fail-closed USB partition discovery for privileged verification.
 *
 * This is NOT the display inventory. A missing or unreadable sysfs entry,
 * unstable enumeration, or failed identity probe invalidates the snapshot.
 * Call under the shared disk-operation lock, alongside mapper discovery.
 */
function edm_authoritative_usb_discovery($sysBlock = '/sys/class/block', $probe = null)
{
    $empty = array('complete' => false, 'devices' => array());
    if (!is_string($sysBlock) || $sysBlock === '') return $empty;
    if ($probe === null) $probe = 'edm_authoritative_blkid_probe';
    if (!is_callable($probe)) return $empty;
    $before = @scandir($sysBlock);
    if (!is_array($before)) return $empty;
    $mountLines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($mountLines)) return $empty;
    $mounted = array();
    foreach ($mountLines as $line) {
        $fields = preg_split('/\\s+/', $line);
        if (!isset($fields[0], $fields[1])) return $empty;
        $mounted[$fields[0]] = $fields[1];
    }
    // /proc/mounts source paths can be aliases (/dev/disk/by-uuid, etc.).
    // Cross-check kernel major:minor mount identities instead of trusting paths.
    $mountInfoBefore = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($mountInfoBefore)) return $empty;
    $mountedDevIds = array();
    foreach ($mountInfoBefore as $line) {
        $fields = preg_split('/\\s+/', $line);
        if (!isset($fields[2]) || !preg_match('/^[0-9]+:[0-9]+$/D', $fields[2])) return $empty;
        $mountedDevIds[$fields[2]] = true;
    }
    $devices = array();
    $partitionNames = array();
    $partitionPaths = array();
    $partitionDevIds = array();
    foreach ($before as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return $empty;
        $base = $sysBlock . '/' . $name;
        if (!is_file($base . '/partition')) continue;
        $partitionNames[] = $name;
        $resolved = realpath($base);
        if ($resolved === false) return $empty;
        $partitionPaths[$name] = $resolved;
        $devId = @file_get_contents($base . '/dev');
        if ($devId === false || !preg_match('/^[0-9]+:[0-9]+$/D', trim($devId))) return $empty;
        $devId = trim($devId);
        $partitionDevIds[$name] = $devId;
        if (!preg_match('~/(usb[0-9]+)(/|$)~', $resolved)) continue;
        try {
            $info = call_user_func($probe, $name);
        } catch (Throwable $e) {
            return $empty;
        }
        if (!is_array($info) || !isset($info['uuid'], $info['type'])
            || !is_string($info['uuid']) || !is_string($info['type'])) return $empty;
        $luks = $info['type'] === 'crypto_LUKS';
        if ($luks && !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $info['uuid'])) {
            return $empty;
        }
        $devices[] = array(
            'name' => $name, 'dev_id' => $devId, 'uuid' => $info['uuid'],
            'luks' => $luks, 'mount' => isset($mountedDevIds[$devId]) ? 'mounted' : '',
            'backup_mapper' => '', 'backup_mount' => ''
        );
    }
    $after = @scandir($sysBlock);
    if (!is_array($after)) return $empty;
    $afterPartitions = array();
    foreach ($after as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return $empty;
        if (is_file($sysBlock . '/' . $name . '/partition')) $afterPartitions[] = $name;
    }
    sort($partitionNames);
    sort($afterPartitions);
    if ($partitionNames !== $afterPartitions) return $empty;
    foreach ($partitionPaths as $name => $resolved) {
        if (realpath($sysBlock . '/' . $name) !== $resolved) return $empty;
        $devIdAfter = @file_get_contents($sysBlock . '/' . $name . '/dev');
        if ($devIdAfter === false || trim($devIdAfter) !== $partitionDevIds[$name]) return $empty;
    }
    // Mount state can change independently of block-device enumeration.
    // Do not return a complete snapshot if it changed during probing.
    $mountLinesAfter = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($mountLinesAfter) || $mountLinesAfter !== $mountLines) return $empty;
    $mountInfoAfter = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($mountInfoAfter) || $mountInfoAfter !== $mountInfoBefore) return $empty;
    return array('complete' => true, 'devices' => $devices);
}

/**
 * Use blkid's exit status as part of identity verification. A nonzero status
 * cannot be interpreted as proof that a disk is disconnected.
 */
function edm_authoritative_blkid_probe($name)
{
    if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) return null;
    $lines = array();
    $exit = 1;
    exec('/usr/bin/blkid -o export ' . escapeshellarg('/dev/' . $name) . ' 2>/dev/null', $lines, $exit);
    if ($exit !== 0) return null;
    $info = array();
    foreach ($lines as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $info[$parts[0]] = $parts[1];
    }
    if (!isset($info['TYPE'])) return null;
    return array('uuid' => isset($info['UUID']) ? $info['UUID'] : '', 'type' => $info['TYPE']);
}
