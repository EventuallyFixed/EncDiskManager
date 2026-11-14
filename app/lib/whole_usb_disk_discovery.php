<?php
/**
 * Read-only whole-USB-disk discovery. This is an inventory, NOT an
 * authorization to erase a disk. Eligibility remains disabled until a
 * complete topology and dependency check is implemented.
 */
function edm_discover_whole_usb_disks($sysBlock = '/sys/class/block',
    $mountsFile = '/proc/mounts', $swapsFile = '/proc/swaps')
{
    $entries = @scandir($sysBlock);
    $mountLines = @file($mountsFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($entries) || !is_array($mountLines)) {
        return array('complete' => false, 'disks' => array());
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
        $holders = @scandir($path . '/holders');
        if (!is_array($holders)) {
            return array('complete' => false, 'disks' => array());
        }
        $hasHolders = count(array_diff($holders, array('.', '..'))) > 0;
        $isMounted = isset($mounted['/dev/' . $name]);
        foreach ($children as $child) {
            if (isset($mounted['/dev/' . $child])) $isMounted = true;
            $childHolders = @scandir($path . '/' . $child . '/holders');
            if (!is_array($childHolders)) {
                return array('complete' => false, 'disks' => array());
            }
            if (count(array_diff($childHolders, array('.', '..'))) > 0) {
                $hasHolders = true;
            }
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
        $sectorCount = (int) trim($sectors);
        if ($sectorCount <= 0 || $sectorCount > intdiv(PHP_INT_MAX, 512)) {
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

        $disks[] = array(
            'name' => $name,
            'sysfs_path' => $resolved,
            'dev_id' => trim($devId),
            'serial' => $serial,
            'size_bytes' => $sectorCount * 512,
            'read_only' => trim($readOnly) === '1',
            'partitions' => $children,
            'mounted' => $isMounted,
            'has_holders' => $hasHolders,
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
