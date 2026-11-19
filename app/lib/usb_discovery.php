<?php
/** Shared read-only USB inventory for web display and polling. */
function read_blkid($device) {
    // Only allow kernel-style block device names; never accept user input.
    if (!preg_match('/^[a-zA-Z0-9._+-]+$/', $device)) {
        return array();
    }

    $command = '/usr/bin/blkid -o export ' .
               escapeshellarg('/dev/' . $device) . ' 2>/dev/null';
    $lines = array();
    $status = 1;
    exec($command, $lines, $status);

    $result = array();
    if ($status === 0) {
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $result[$parts[0]] = $parts[1];
            }
        }
    }
    return $result;
}

function mounted_devices() {
    $mounted = array();
    $lines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', $line);
            if (isset($fields[0], $fields[1])) {
                $mounted[$fields[0]] = $fields[1];
            }
        }
    }
    return $mounted;
}


/**
 * Find mounts by kernel major:minor, not /dev/mapper path spelling.
 * /proc/self/mountinfo identifies the same filesystem through device aliases.
 */
function edm_mounts_for_block($name) {
    $id = @file_get_contents('/sys/class/block/' . $name . '/dev');
    if ($id === false) return array();
    $id = trim($id);
    $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return array();
    $mounts = array();
    foreach ($lines as $line) {
        $fields = explode(' ', $line);
        if (count($fields) < 6 || $fields[2] !== $id) continue;
        // mountinfo escapes whitespace and backslashes as octal sequences.
        $path = preg_replace_callback('/\\\\([0-7]{3})/', function ($match) {
            return chr(octdec($match[1]));
        }, $fields[4]);
        $mounts[] = $path;
    }
    return array_values(array_unique($mounts));
}

// Follow device-mapper slave links back to detected USB partitions.
// This is read-only and deliberately excludes mappings with no USB ancestor.
function find_usb_ancestors($name, $usbNames, $depth = 0, $visited = array()) {
    if (in_array($name, $usbNames, true)) {
        return array($name);
    }
    if ($depth >= 8 || isset($visited[$name])) {
        return array();
    }

    $visited[$name] = true;
    $dir = '/sys/class/block/' . $name . '/slaves';
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return array();
    }

    $found = array();
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $found = array_merge(
            $found,
            find_usb_ancestors($entry, $usbNames, $depth + 1, $visited)
        );
    }
    return array_values(array_unique($found));
}


function edm_discover_usb_inventory()
{
$configuredBackupUuid = 'b71a85a8-2a28-4e44-aeac-ef2cb1f5da17';

$mounted = mounted_devices();
$devices = array();
$sysEntries = @scandir('/sys/class/block');

if (is_array($sysEntries)) {
    foreach ($sysEntries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $sysPath = '/sys/class/block/' . $name;

        // Only list partitions, not whole-disk parent devices.
        if (!is_file($sysPath . '/partition')) {
            continue;
        }

        $resolved = realpath($sysPath);
        if ($resolved === false) {
            continue;
        }

        // Require a USB component in the resolved sysfs device path.
        if (!preg_match('~/(usb[0-9]+)(/|$)~', $resolved)) {
            continue;
        }

        $info = read_blkid($name);
        $source = '/dev/' . $name;

        $devices[] = array(
            'name' => $name,
            'path' => $source,
            'label' => isset($info['LABEL']) ? $info['LABEL'] : '',
            'uuid' => isset($info['UUID']) ? $info['UUID'] : '',
            'is_backup' => isset($info['UUID']) &&
                strtolower($info['UUID']) === strtolower($configuredBackupUuid),
            'type' => isset($info['TYPE']) ? $info['TYPE'] : '',
            'luks' => isset($info['TYPE']) && $info['TYPE'] === 'crypto_LUKS',
            'mount' => isset($mounted[$source]) ? $mounted[$source] : ''
        );
    }
}

usort($devices, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

$usbNames = array();
foreach ($devices as $device) {
    $usbNames[] = $device['name'];
}

$mapperDevices = array();
foreach (is_array($sysEntries) ? $sysEntries : array() as $name) {
    if (!preg_match('/^dm-[0-9]+$/', $name)) {
        continue;
    }

    $sysPath = '/sys/class/block/' . $name;
    $mapperName = @file_get_contents($sysPath . '/dm/name');
    if ($mapperName === false) {
        continue;
    }
    $mapperName = trim($mapperName);
    if ($mapperName === '' ||
        !preg_match('/^[a-zA-Z0-9._+-]+$/', $mapperName)) {
        continue;
    }

    $ancestors = find_usb_ancestors($name, $usbNames);
    if (!$ancestors) {
        continue;
    }

    $info = read_blkid($name);
    $mapperPath = '/dev/mapper/' . $mapperName;
    $dmPath = '/dev/' . $name;
    $actualMounts = edm_mounts_for_block($name);
    $mount = count($actualMounts) === 1 ? $actualMounts[0] : '';

    $mapperDevices[] = array(
        'name' => $mapperName,
        'path' => $mapperPath,
        'parents' => $ancestors,
        'type' => isset($info['TYPE']) ? $info['TYPE'] : '',
        'uuid' => isset($info['UUID']) ? $info['UUID'] : '',
        'mount' => $mount,
        'mounts' => $actualMounts
    );
}

usort($mapperDevices, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Derive read-only status and configured-backup mapping details.
foreach ($devices as &$device) {
    $linkedMappers = array();
    foreach ($mapperDevices as $mapper) {
        if (in_array($device['name'], $mapper['parents'], true)) {
            $linkedMappers[] = $mapper;
        }
    }

    // Preserve legacy fields for the configured backup while deriving the
    // general mapper state for every detected USB partition.
    $device['backup_mapper'] = '';
    $device['backup_mount'] = '';
    $device['mapper_names'] = array();
    $device['mapper_mounts'] = array();
    foreach ($linkedMappers as $mapper) {
        $device['mapper_names'][] = $mapper['name'];
        foreach ($mapper['mounts'] as $mountPath) {
            $device['mapper_mounts'][] = $mountPath;
        }
        if ($device['is_backup']) {
            $device['backup_mapper'] = $mapper['name'];
            if ($mapper['mount'] !== '') {
                $device['backup_mount'] = $mapper['mount'];
            }
        }
    }

    if (!$device['luks']) {
        $device['status'] = $device['mount'] !== ''
            ? 'Mounted'
            : 'Not identified as LUKS';
    } elseif (!$linkedMappers) {
        $device['status'] = 'Encrypted — Locked';
    } else {
        $isMounted = false;
        foreach ($linkedMappers as $mapper) {
            if ($mapper['mount'] !== '') {
                $isMounted = true;
                break;
            }
        }
        $device['status'] = $isMounted
            ? 'Encrypted — Mounted'
            : 'Encrypted — Unlocked';
    }
}
unset($device);


    return array('devices' => $devices, 'mapperDevices' => $mapperDevices, 'sysEntries' => $sysEntries);
}
