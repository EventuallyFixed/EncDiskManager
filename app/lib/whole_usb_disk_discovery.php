<?php
/**
 * Read-only whole-USB-disk discovery. This is an inventory, NOT an
 * authorization to erase a disk. Eligibility remains disabled until a
 * complete topology and dependency check is implemented.
 */
function edm_discover_whole_usb_disks($sysBlock = '/sys/class/block',
    $mountsFile = '/proc/mounts', $swapsFile = '/proc/swaps',
    $mountInfoFile = '/proc/self/mountinfo')
{
    $entries = @scandir($sysBlock);
    $mountLines = @file($mountsFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($entries) || !is_array($mountLines)) {
        return array('complete' => false, 'disks' => array());
    }

    // Mountinfo reports kernel major:minor IDs even when mounted through
    // a UUID alias or another path instead of /dev/sdX.
    $mountInfoLines = @file($mountInfoFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($mountInfoLines)) {
        return array('complete' => false, 'disks' => array());
    }
    $mountedIds = array();
    foreach ($mountInfoLines as $line) {
        $fields = preg_split('/\\s+/', trim($line));
        if (!isset($fields[2]) || !preg_match('/^[0-9]+:[0-9]+$/D', $fields[2])) {
            return array('complete' => false, 'disks' => array());
        }
        $mountedIds[$fields[2]] = true;
    }
    $mounted = array();
    foreach ($mountLines as $line) {
        $fields = preg_split('/\\s+/', trim($line));
        if (isset($fields[0])) $mounted[$fields[0]] = true;
    }

    // Active swap is not listed in /proc/mounts. Treat it as in-use.
    $swapLines = @file($swapsFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($swapLines)) {
        return array('complete' => false, 'disks' => array());
    }
    foreach (array_slice($swapLines, 1) as $line) {
        $fields = preg_split('/\\s+/', trim($line));
        if (isset($fields[0])) $mounted[$fields[0]] = true;
    }

    $disks = array();
    foreach ($entries as $name) {
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)) continue;
        $path = $sysBlock . '/' . $name;
        if (is_file($path . '/partition')) continue;
        $resolved = realpath($path);
        if ($resolved === false || !preg_match('~/usb[0-9]+(?:/|$)~', $resolved)) continue;

        $children = array();
        $childEntries = @scandir($path);
        if (!is_array($childEntries)) {
            return array('complete' => false, 'disks' => array());
        }
        foreach ($childEntries as $child) {
            if (!preg_match('/^[a-zA-Z0-9._+-]+$/D', $child)
                || !is_file($path . '/' . $child . '/partition')) continue;
            $children[] = $child;
        }
        sort($children);
        // Capture kernel identities for every partition, not just the parent.
        // An incomplete partition identity makes the inventory incomplete.
        $partitionIds = array();
        foreach ($children as $child) {
            $partitionDev = @file_get_contents($path . '/' . $child . '/dev');
            if ($partitionDev === false
                || !preg_match('/^[0-9]+:[0-9]+$/D', trim($partitionDev))) {
                return array('complete' => false, 'disks' => array());
            }
            $partitionIds[$child] = trim($partitionDev);
        }
        // Check the whole disk and each partition for block-device users.
        $hasHolders = false;
        $hasSlaves = false;
        foreach (array_merge(array($name), $children) as $device) {
            $devicePath = $device === $name ? $path : $path . '/' . $device;
            $holders = @scandir($devicePath . '/holders');
            if (!is_array($holders)) {
                return array('complete' => false, 'disks' => array());
            }
            if (count($holders) > 2) $hasHolders = true;
            $slaves = @scandir($devicePath . '/slaves');
            if (is_array($slaves) && count($slaves) > 2) $hasSlaves = true;
        }
        $isMounted = isset($mounted['/dev/' . $name]);
        foreach ($children as $child) {
            if (isset($mounted['/dev/' . $child])) $isMounted = true;
        }

        $devId = @file_get_contents($path . '/dev');
        $sectors = @file_get_contents($path . '/size');
        $readOnly = @file_get_contents($path . '/ro');
        if ($devId === false || $sectors === false || $readOnly === false
            || !preg_match('/^[0-9]+:[0-9]+$/D', trim($devId))
            || !preg_match('/^[0-9]+$/D', trim($sectors))
            || !preg_match('/^[01]$/D', trim($readOnly))) {
            return array('complete' => false, 'disks' => array());
        }
        // ARMHF PHP may use 32-bit integers. Use floating-point only for
        // read-only capacity display; never use it to identify an erase target.
        $sectorCount = (float) trim($sectors);
        if (!is_finite($sectorCount) || $sectorCount <= 0
            || $sectorCount > PHP_FLOAT_MAX / 512) {
            return array('complete' => false, 'disks' => array());
        }

        // USB serial is optional on real devices. Without a reliable
        // physical identifier, the disk remains display-only.
        $serial = '';
        $deviceDir = dirname(dirname($resolved));
        for ($depth = 0; $depth < 6; $depth++) {
            $value = @file_get_contents($deviceDir . '/serial');
            if ($value !== false && trim($value) !== '') {
                $serial = trim($value);
                break;
            }
            $parent = dirname($deviceDir);
            if ($parent === $deviceDir) break;
            $deviceDir = $parent;
        }

        if (isset($mountedIds[trim($devId)])) $isMounted = true;
        foreach ($partitionIds as $partitionDevId) {
            if (isset($mountedIds[$partitionDevId])) $isMounted = true;
        }

        $disks[] = array(
            'name' => $name,
            'sysfs_path' => $resolved,
            'dev_id' => trim($devId),
            'serial' => $serial,
            'size_bytes' => $sectorCount * 512,
            // Exact decimal sector count for identity checks on 32-bit PHP.
            'size_sectors' => ltrim(trim($sectors), '0') ?: '0',
            'read_only' => trim($readOnly) === '1',
            'partitions' => $children,
            'partition_dev_ids' => $partitionIds,
            'mounted' => $isMounted,
            'has_holders' => $hasHolders,
            'has_slaves' => $hasSlaves,
            // Never infer destructive-operation eligibility from this
            // partial read-only inventory.
            'encryption_eligible' => false
        );
    }
    usort($disks, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    return array('complete' => true, 'disks' => $disks);
}
