<?php
require_once __DIR__ . '/../lib/auth.php';

try {
    edm_auth_start_session();
    $admin = edm_auth_read_admin();
    $setupToken = ($admin === null) ? edm_auth_read_setup_token() : null;
    $authError = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !edm_auth_is_https()) {
        http_response_code(400);
        $authError = 'HTTPS is required for administrator sign-in and setup. Open the NAS using its HTTPS address.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        if (!hash_equals((string)$_SESSION['csrf_token'], $postedToken)) {
            http_response_code(403);
            $authError = 'Your security token expired. Please reload and try again.';
        } else {
            $authAction = isset($_POST['auth_action']) ? (string)$_POST['auth_action'] : '';
            if ($authAction === 'setup' && $admin === null) {
                $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
                $confirm = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';
                $providedSetupToken = isset($_POST['setup_token']) ? (string)$_POST['setup_token'] : '';
                if ($setupToken === null || !hash_equals($setupToken, $providedSetupToken)) {
                    $authError = 'The one-time setup code is missing or incorrect.';
                } elseif (strlen($password) < 12) {
                    $authError = 'Choose a password with at least 12 characters.';
                } elseif (strlen($password) > 72) {
                    $authError = 'Choose a password no longer than 72 bytes.';
                } elseif (!hash_equals($password, $confirm)) {
                    $authError = 'The password confirmation does not match.';
                } else {
                    edm_auth_write_admin($password);
                    if (!edm_auth_consume_setup_token($providedSetupToken)) {
                        throw new RuntimeException('The one-time setup code could not be consumed.');
                    }
                    unset($password, $confirm, $providedSetupToken);
                    if (!edm_auth_login((string)$_POST['password'])) {
                        throw new RuntimeException('Administrator account was created, but automatic sign-in failed. Please sign in.');
                    }
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                }
                unset($password, $confirm);
            } elseif ($authAction === 'login' && $admin !== null) {
                $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
                $ok = edm_auth_login($password);
                unset($password);
                if ($ok) {
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                }
                $authError = 'The password was not recognised.';
            } elseif ($authAction === 'logout' && edm_auth_is_authenticated()) {
                edm_auth_logout();
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            } else {
                http_response_code(400);
                $authError = 'That request is not available.';
            }
        }
        unset($_POST['password'], $_POST['confirm_password']);
    }

    if (!edm_auth_is_authenticated()) {
        $setupMode = ($admin === null);
        $pageTitle = $setupMode ? 'Set up administrator' : 'Administrator sign in';
        $csrf = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
        $errorHtml = ($authError === '') ? '' : '<p class="error">' . htmlspecialchars($authError, ENT_QUOTES, 'UTF-8') . '</p>';
        $httpsConnection = edm_auth_is_https();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        ?><!doctype html>
        <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> · Encrypted Disk Manager</title>
        <style>
        body{margin:0;background:#f3f5f6;color:#20272c;font:16px/1.5 Arial,Helvetica,sans-serif;display:grid;min-height:100vh;place-items:center}
        main{box-sizing:border-box;width:min(440px,calc(100% - 32px));background:#fff;border:1px solid #d9dfe2;border-radius:9px;padding:28px}
        h1{font-size:1.45rem;margin:0 0 5px}p{color:#58656d}.field{display:block;font-weight:bold;margin:17px 0 5px}
        input{box-sizing:border-box;width:100%;padding:11px;border:1px solid #aebac1;border-radius:5px;font:inherit}
        button{margin-top:20px;padding:10px 16px;border:0;border-radius:5px;background:#075f91;color:#fff;font:inherit;cursor:pointer}
        .error{padding:10px;background:#fff0ed;border-left:4px solid #b23b2b;color:#842719}
        .warning{padding:10px;background:#fff8e7;border-left:4px solid #b7791f;color:#704b0b}
        </style></head><body><main>
        <h1>Encrypted Disk Manager</h1><p><?php echo $setupMode ? 'Enter the one-time setup code from the NAS, then create the administrator password.' : 'Sign in to manage encrypted disks.'; ?></p>
        <?php echo $errorHtml; ?>
        <?php if (!$httpsConnection): ?>
        <p class="warning">Administrator setup and sign-in are disabled over HTTP. Open Encrypted Disk Manager using your NAS's HTTPS address. A self-signed certificate is acceptable if you have verified it belongs to your NAS.</p>
        <?php else: ?>
        <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="auth_action" value="<?php echo $setupMode ? 'setup' : 'login'; ?>">
        <?php if ($setupMode): ?>
        <label class="field" for="setup-token">One-time setup code</label>
        <input id="setup-token" name="setup_token" type="password" required autocomplete="off">
        <?php endif; ?>
        <label class="field" for="password">Administrator password</label>
        <input id="password" name="password" type="password" required autocomplete="<?php echo $setupMode ? 'new-password' : 'current-password'; ?>" <?php echo $setupMode ? 'minlength="12" maxlength="72"' : ''; ?>>
        <?php if ($setupMode): ?>
        <label class="field" for="confirm-password">Confirm password</label>
        <input id="confirm-password" name="confirm_password" type="password" required minlength="12" autocomplete="new-password">
        <?php endif; ?>
        <button type="submit"><?php echo $setupMode ? 'Create administrator' : 'Sign in'; ?></button>
        </form>
        <?php endif; ?>
        </main></body></html><?php
        exit;
    }
    edm_auth_require_login();
} catch (Throwable $exception) {
    error_log('EncDiskManager authentication error: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Authentication is temporarily unavailable. Check the private application data permissions.\n";
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
<title>Encrypted Disk Manager</title>
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
    .header-actions { margin-left: auto; }
    .backup-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 11px;
        border: 1px solid #aebac1;
        border-radius: 5px;
        color: #075f91;
        text-decoration: none;
        white-space: nowrap;
    }
    .backup-link:hover { background: #f3f7f9; }
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
        <h1>Encrypted Disk Manager</h1>
        <div class="muted">USB storage · LUKS inventory</div>
    </div>
    <div class="header-actions">
        <a class="backup-link" href="download.php"
           title="Download encrypted-disk configuration backup"
           aria-label="Download configuration backup">
            <span class="material-symbol" aria-hidden="true">download</span>
            <span>Backup config</span>
        </a>
        <form method="post" style="display:inline-block;margin-left:8px">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="auth_action" value="logout">
            <button type="submit" style="padding:8px 11px;border:1px solid #aebac1;border-radius:5px;background:#fff;color:#075f91;cursor:pointer">Sign out</button>
        </form>
    </div>
</header>
<main>
    <section class="panel safe-note">
        <strong>Before uninstalling or reinstalling:</strong>
        download a configuration backup using the header button.
        Normal upgrades preserve the registered-disk configuration and administrator
        account automatically. A complete uninstall removes local configuration;
        the downloaded backup contains disk UUIDs and mount settings only, never passphrases.
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

</main>
</body>
</html>
