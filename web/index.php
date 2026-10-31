<?php
/*
 * EncDiskManager - read-only USB/LUKS inventory.
 * No unlock, mount, unmount, or repair operations are performed here.
 */

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['bdm_csrf_token'])) {
    $_SESSION['bdm_csrf_token'] = bin2hex(random_bytes(32));
}

$actionMessage = '';
$actionOutput = array();

function run_backup_helper($action, $arguments, $passphrase, &$output, &$status) {
    $scriptPath = realpath(__FILE__);
    $appPath = ($scriptPath === false) ? false : realpath(dirname($scriptPath) . '/..');
    if ($appPath === false || !is_dir($appPath)) {
        $output = array('Action unavailable: the application installation path could not be resolved.');
        $status = 127;
        return false;
    }

    $configFile = $appPath . '/encdiskmanager.conf';
    if (is_link($configFile) || !is_file($configFile) || !is_readable($configFile)) {
        $output = array('Action unavailable: the application configuration is missing or inaccessible.');
        $status = 127;
        return false;
    }

    $configuredPath = trim((string)file_get_contents($configFile));
    if ($configuredPath !== $appPath) {
        $output = array('Action unavailable: the application configuration does not match its installation path.');
        $status = 127;
        return false;
    }

    $helper = $appPath . '/bin/encdiskmanager-helper';
    if (!is_file($helper) || !is_executable($helper)) {
        $output = array('Action unavailable: the installed helper is missing or not executable.');
        $status = 127;
        return false;
    }

    $parts = array(escapeshellarg($helper), escapeshellarg($action));
    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg($argument);
    }
    $command = implode(' ', $parts);
    $spec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('redirect', 1)
    );
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        $output = array('Could not start the storage helper.');
        $status = 127;
        return false;
    }

    $writeFailed = false;
    if ($passphrase !== null) {
        $expectedBytes = strlen($passphrase);
        $offset = 0;
        while ($offset < $expectedBytes) {
            $writtenBytes = fwrite($pipes[0], substr($passphrase, $offset));
            if ($writtenBytes === false || $writtenBytes === 0) {
                $writeFailed = true;
                break;
            }
            $offset += $writtenBytes;
        }
    }
    fclose($pipes[0]);
    $combined = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);

    if ($writeFailed) {
        $output = array('Could not safely deliver the passphrase to the storage helper; the action was not confirmed.');
        $status = 125;
        return false;
    }

    $combined = trim((string)$combined);
    $output = ($combined === '') ? array() : array_slice(explode("\n", $combined), 0, 30);
    return $status === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $action = isset($_POST['disk_action']) ? (string)$_POST['disk_action'] : '';
    $passphrase = isset($_POST['passphrase']) ? (string)$_POST['passphrase'] : '';
    unset($_POST['passphrase']);
    $mountPath = isset($_POST['mount_path']) ? trim((string)$_POST['mount_path']) : '';
    $confirmRepair = isset($_POST['confirm_repair']) && $_POST['confirm_repair'] === 'yes';

    if (!hash_equals($_SESSION['bdm_csrf_token'], $postedToken)) {
        http_response_code(403);
        $actionMessage = 'Request rejected: security token invalid. Reload the page and try again.';
    } elseif (!in_array($action, array('mount', 'unmount', 'repair'), true)) {
        http_response_code(400);
        $actionMessage = 'Request rejected: unsupported action.';
    } elseif (in_array($action, array('mount', 'unmount'), true) &&
        ($mountPath === '' || strpos($mountPath, '/mnt/') !== 0 ||
         strpos($mountPath, '..') !== false ||
         !preg_match('~^/mnt/[A-Za-z0-9._/-]+$~', $mountPath) ||
         preg_match('~//|/\./|/$~', $mountPath))) {
        http_response_code(400);
        $actionMessage = 'Invalid mount folder path. Choose a folder beneath /mnt using letters, numbers, dots, underscores, or hyphens.';
    } elseif ($action === 'repair' && !$confirmRepair) {
        http_response_code(400);
        $actionMessage = 'Repair was not started because explicit confirmation was not provided.';
    } elseif (in_array($action, array('mount', 'repair'), true) && $passphrase === '') {
        http_response_code(400);
        $actionMessage = 'Enter the encryption passphrase to continue.';
    } else {
        $arguments = array();
        if ($action === 'mount' || $action === 'unmount') {
            $arguments[] = $mountPath;
        } elseif ($action === 'repair') {
            $arguments[] = 'CONFIRM';
        }

        $helperOutput = array();
        $status = 1;
        $secret = in_array($action, array('mount', 'repair'), true) ? $passphrase : null;
        $ok = run_backup_helper($action, $arguments, $secret, $helperOutput, $status);
        $actionOutput = $helperOutput;
        $actionMessage = $ok
            ? 'Command completed successfully.'
            : 'Command failed (exit status ' . (int)$status . ').';
    }
    unset($passphrase);
}

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
foreach ($sysEntries as $name) {
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
    $mount = isset($mounted[$mapperPath]) ? $mounted[$mapperPath] :
             (isset($mounted[$dmPath]) ? $mounted[$dmPath] : '');

    $mapperDevices[] = array(
        'name' => $mapperName,
        'path' => $mapperPath,
        'parents' => $ancestors,
        'type' => isset($info['TYPE']) ? $info['TYPE'] : '',
        'uuid' => isset($info['UUID']) ? $info['UUID'] : '',
        'mount' => $mount
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

    $device['backup_mapper'] = '';
    $device['backup_mount'] = '';
    if ($device['is_backup']) {
        foreach ($linkedMappers as $mapper) {
            $device['backup_mapper'] = $mapper['name'];
            if ($mapper['mount'] !== '') {
                $device['backup_mount'] = $mapper['mount'];
                break;
            }
        }
    }

    if (!$device['luks']) {
        $device['status'] = $device['mount'] !== ''
            ? 'Mounted'
            : 'Not identified as LUKS';
    } elseif (!$linkedMappers) {
        $device['status'] = 'LUKS detected — no open USB-linked mapping found';
    } else {
        $isMounted = false;
        foreach ($linkedMappers as $mapper) {
            if ($mapper['mount'] !== '') {
                $isMounted = true;
                break;
            }
        }
        $device['status'] = $isMounted
            ? 'Unlocked and mounted'
            : 'Unlocked — not mounted';
    }
}
unset($device);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EncDiskManager</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
<style>
    body {
        margin: 0;
        background: #f3f5f6;
        color: #20272c;
        font: 16px/1.5 Arial, Helvetica, sans-serif;
    }
    header {
        background: #fff;
        border-bottom: 1px solid #d9dfe2;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
    }
    header img { width: 58px; height: 58px; object-fit: contain; }
    h1 { margin: 0; font-size: 1.45rem; }
    main { max-width: 1050px; margin: 24px auto; padding: 0 18px; }
    .panel {
        background: #fff;
        border: 1px solid #d9dfe2;
        border-radius: 8px;
        padding: 18px;
        margin-bottom: 18px;
    }
    .muted { color: #58656d; }
    .table-wrap { overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; }
    th, td {
        text-align: left;
        padding: 11px 10px;
        border-bottom: 1px solid #e5e9eb;
        vertical-align: top;
    }
    th { background: #f7f8f9; }
    code { overflow-wrap: anywhere; }
    .status { font-weight: bold; }
    .safe-note {
        border-left: 4px solid #647985;
        background: #f7f8f9;
        padding: 12px 14px;
    }
    .action-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin: 2px 10px 2px 0;
        color: #075f91;
        text-decoration: underline;
        cursor: pointer;
    }
    .material-symbol {
        font-family: 'Material Symbols Outlined';
        font-weight: normal;
        font-style: normal;
        font-size: 20px;
        line-height: 1;
        display: inline-block;
        white-space: nowrap;
        direction: ltr;
        font-feature-settings: 'liga';
        -webkit-font-feature-settings: 'liga';
        -webkit-font-smoothing: antialiased;
    }
    .action-link:focus-visible, dialog button:focus-visible,
    dialog input:focus-visible {
        outline: 2px solid #075f91;
        outline-offset: 3px;
    }
    dialog {
        width: min(460px, calc(100% - 40px));
        border: 1px solid #cbd4d9;
        border-radius: 8px;
        padding: 22px;
        color: #20272c;
    }
    dialog::backdrop { background: rgba(20, 30, 36, .55); }
    dialog label { display: block; margin: 14px 0 5px; font-weight: bold; }
    dialog input[type="text"], dialog input[type="password"] {
        box-sizing: border-box;
        width: 100%;
        padding: 10px;
        border: 1px solid #aebac1;
        border-radius: 4px;
        font: inherit;
    }
    .dialog-actions { display: flex; gap: 12px; margin-top: 20px; }
    .dialog-actions button { padding: 9px 14px; font: inherit; cursor: pointer; }
</style>
</head>
<body>
<header>
    <img src="logo.svg" alt="">
    <div>
        <h1>EncDiskManager</h1>
        <div class="muted">USB storage · LUKS inventory</div>
    </div>
</header>
<main>
    <section class="panel">
        <h2>Backup USB controls</h2>
        <p class="muted">
            Use the action links on the row matching the configured backup
            partition. Mounting requires the encryption passphrase and a
            destination folder beneath <code>/mnt</code>. Normal mounting
            and unmounting never run filesystem repair.
        </p>
        <?php if ($actionMessage !== ''): ?>
            <p class="status"><?php echo h($actionMessage); ?></p>
            <?php if ($actionOutput): ?>
                <pre><code><?php echo h(implode("\n", $actionOutput)); ?></code></pre>
            <?php endif; ?>
        <?php endif; ?>
        <p class="muted">
            Do not disconnect the backup USB disk while it is mounted.
            Repair is a separate, explicitly confirmed operation.
        </p>
    </section>
    <section class="panel">
        <h2>Detected USB partitions</h2>
        <p class="muted">
            This screen is read-only. It does not unlock, mount, unmount,
            or repair storage.
        </p>
        <?php if (!$devices): ?>
            <p>No USB partitions were detected, or the web server could not
            read the required system information.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Label</th>
                    <th>Encryption</th>
                    <th>Status</th>
                    <th>Filesystem / type</th>
                    <th>UUID</th>
                    <th>Mounted at</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devices as $device): ?>
                <tr>
                    <td><code><?php echo h($device['path']); ?></code></td>
                    <td><?php echo h($device['label'] ?: '—'); ?></td>
                    <td class="status">
                        <?php echo $device['luks'] ? 'LUKS detected' : 'Not identified as LUKS'; ?>
                    </td>
                    <td><?php echo h($device['status']); ?></td>
                    <td><?php echo h($device['type'] ?: 'Unknown'); ?></td>
                    <td><code><?php echo h($device['uuid'] ?: '—'); ?></code></td>
                    <td><?php echo h($device['mount'] ?: 'Not mounted directly'); ?></td>
                    <td>
                        <?php if (!empty($device['is_backup'])): ?>
                            <?php if (!empty($device['backup_mount'])): ?>
                                <code><?php echo h($device['backup_mount']); ?></code>
                                <a href="#" class="action-link"
                                   data-open-dialog="unmount-dialog"
                                   data-mount-path="<?php echo h($device['backup_mount']); ?>"
                                   aria-label="Unmount" title="Unmount"><span class="material-symbol" aria-hidden="true">eject</span></a>
                            <?php else: ?>
                                <span>Not Mounted</span>
                                <a href="#" class="action-link"
                                   data-open-dialog="mount-dialog"
                                   aria-label="Mount" title="Mount"><span class="material-symbol" aria-hidden="true">cloud_upload</span></a>
                                <?php if (!empty($device['backup_mapper'])): ?>
                                    <a href="#" class="action-link"
                                       data-open-dialog="repair-dialog"
                                       aria-label="Repair" title="Repair"><span class="material-symbol" aria-hidden="true">build</span></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Open encrypted volumes linked to USB</h2>
        <p class="muted">
            Only device-mapper volumes that can be traced through sysfs
            to a detected USB partition are shown here.
        </p>
        <?php if (!$mapperDevices): ?>
            <p>No open device-mapper volumes linked to the detected USB
            partitions were found.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Mapped volume</th>
                    <th>Underlying USB partition</th>
                    <th>Filesystem / type</th>
                    <th>UUID</th>
                    <th>Mounted at</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mapperDevices as $mapper): ?>
                <tr>
                    <td><code><?php echo h($mapper['path']); ?></code></td>
                    <td>
                        <?php
                        $parentPaths = array();
                        foreach ($mapper['parents'] as $parent) {
                            $parentPaths[] = '/dev/' . $parent;
                        }
                        echo h(implode(', ', $parentPaths));
                        ?>
                    </td>
                    <td><?php echo h($mapper['type'] ?: 'Unknown'); ?></td>
                    <td><code><?php echo h($mapper['uuid'] ?: '—'); ?></code></td>
                    <td><?php echo h($mapper['mount'] ?: 'Not mounted'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <section class="panel safe-note">
        <strong>Status information:</strong> This page uses detected block-device,
        encryption, and mount information to estimate each USB partition’s state.
        An open encrypted volume is shown separately when its device-mapper
        relationship to a detected USB partition can be established. This page
        makes no storage changes.
    </section>

    <dialog id="mount-dialog" aria-labelledby="mount-dialog-title">
        <form method="post">
            <h2 id="mount-dialog-title">Mount backup disk</h2>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['bdm_csrf_token']); ?>">
            <input type="hidden" name="disk_action" value="mount">
            <label for="mount-path">Backup folder under /mnt</label>
            <input id="mount-path" name="mount_path" type="text"
                   value="/mnt/backupdisk" required
                   pattern="/mnt/[A-Za-z0-9._/-]+"
                   autocomplete="off">
            <label for="mount-passphrase">Encryption passphrase</label>
            <input id="mount-passphrase" name="passphrase" type="password"
                   required autocomplete="off">
            <p class="muted">Normal mounting does not run filesystem repair.</p>
            <div class="dialog-actions">
                <button type="submit">Mount</button>
                <button type="button" data-close-dialog>Cancel</button>
            </div>
        </form>
    </dialog>

    <dialog id="unmount-dialog" aria-labelledby="unmount-dialog-title">
        <form method="post">
            <h2 id="unmount-dialog-title">Unmount backup disk</h2>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['bdm_csrf_token']); ?>">
            <input type="hidden" name="disk_action" value="unmount">
            <input type="hidden" name="mount_path" id="unmount-path">
            <p>Unmount the backup disk at <code id="unmount-path-label"></code>?</p>
            <div class="dialog-actions">
                <button type="submit">Unmount</button>
                <button type="button" data-close-dialog>Cancel</button>
            </div>
        </form>
    </dialog>

    <dialog id="repair-dialog" aria-labelledby="repair-dialog-title">
        <form method="post">
            <h2 id="repair-dialog-title">Repair backup filesystem</h2>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['bdm_csrf_token']); ?>">
            <input type="hidden" name="disk_action" value="repair">
            <p>Repair can modify filesystem metadata. Ensure the backup disk is unmounted and important data is backed up.</p>
            <label for="repair-passphrase">Encryption passphrase</label>
            <input id="repair-passphrase" name="passphrase" type="password"
                   required autocomplete="off">
            <label>
                <input type="checkbox" name="confirm_repair" value="yes" required>
                I explicitly confirm that the filesystem repair may modify data.
            </label>
            <div class="dialog-actions">
                <button type="submit">Confirm repair</button>
                <button type="button" data-close-dialog>Cancel</button>
            </div>
        </form>
    </dialog>

    <script>
    (function () {
        document.querySelectorAll('[data-open-dialog]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var dialog = document.getElementById(link.getAttribute('data-open-dialog'));
                if (!dialog) return;
                if (dialog.id === 'unmount-dialog') {
                    var path = link.getAttribute('data-mount-path') || '';
                    document.getElementById('unmount-path').value = path;
                    document.getElementById('unmount-path-label').textContent = path;
                }
                dialog.showModal();
            });
        });
        document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', function () {
                var dialog = button.closest('dialog');
                if (dialog) {
                    dialog.querySelectorAll('input[type="password"]').forEach(function (field) {
                        field.value = '';
                    });
                    dialog.close();
                }
            });
        });
        document.querySelectorAll('dialog form').forEach(function (form) {
            form.addEventListener('submit', function () {
                window.setTimeout(function () {
                    form.querySelectorAll('input[type="password"]').forEach(function (field) {
                        field.value = '';
                    });
                }, 0);
            });
        });
    }());
    </script>
</main>
</body>
</html>
