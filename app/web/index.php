<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/app_version.php';
require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/inventory.php';
require_once __DIR__ . '/../lib/configuration_request.php';
require_once __DIR__ . '/../lib/configuration_authorization.php';

try {
    edm_auth_start_session();
    $admin = edm_auth_read_admin();
    $setupToken = ($admin === null) ? edm_auth_read_setup_token() : null;
    $authError = '';
    $authNotice = '';
    if (isset($_GET['restored']) && $_GET['restored'] === '1') {
        $authNotice = 'Backup restored successfully. Sign in using the administrator password stored in that backup.';
    } elseif (isset($_GET['configuration_deleted']) && $_GET['configuration_deleted'] === '1') {
        $authNotice = 'Saved configuration was deleted. A new setup code is available from the Settings page.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !edm_auth_is_https()) {
        http_response_code(400);
        $authError = 'HTTPS is required for administrator sign-in and setup. Open the NAS using its HTTPS address.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
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
        $noticeHtml = ($authNotice === '') ? '' : '<p class="notice">' . htmlspecialchars($authNotice, ENT_QUOTES, 'UTF-8') . '</p>';
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
        .error{padding:10px;background:#fff0ed;border-left:4px solid #b23b2b;color:#842719}.notice{padding:10px;background:#eaf4f8;border-left:4px solid #075f91;color:#17465e}
        .warning{padding:10px;background:#fff8e7;border-left:4px solid #b7791f;color:#704b0b}
        </style></head><body><main>
        <h1>Encrypted Disk Manager<?php if (edm_app_version() !== ''): ?> · v<?php echo htmlspecialchars(edm_app_version(), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></h1><p><?php echo $setupMode ? 'Enter the one-time setup code from the NAS, then create the administrator password.' : 'Sign in to manage encrypted disks.'; ?></p>
        <?php echo $noticeHtml; ?>
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
        <?php if ($setupMode): ?>
        <p><a href="settings.php">Restore configuration from a .tgz backup</a></p>
        <?php endif; ?>
        <?php endif; ?>
        </main></body></html><?php
        exit;
    }
    // Non-authentication POSTs are handled only after the administrator gate.
    // Never let a configuration request enter the login/logout dispatcher.
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
require_once __DIR__ . '/../lib/usb_discovery.php';
$discovery = edm_discover_usb_inventory();
require_once __DIR__ . '/../lib/whole_usb_disk_discovery.php';
require_once __DIR__ . '/../lib/whole_usb_disk_stable_candidate.php';
$wholeDiskInventory = edm_discover_whole_usb_disks();
$wholeDiskSecondSnapshot = edm_discover_whole_usb_disks();
$devices = $discovery['devices'];
$mapperDevices = $discovery['mapperDevices'];
$sysEntries = $discovery['sysEntries'];

// Handle authenticated disk registration and configuration requests.
$configValidationNotice = '';
$configValidationError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['config_action'])) {
    if (!edm_auth_is_https()) {
        http_response_code(400);
        $configValidationError = 'HTTPS is required for configuration requests.';
    } elseif (!isset($_POST['csrf_token'])
        || !is_string($_POST['csrf_token'])
        || !hash_equals((string)$_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        $configValidationError = 'Security token expired. Reload and try again.';
    } else {
        try {
            $request = array(
                'action' => $_POST['config_action'],
                'luks_uuid' => isset($_POST['luks_uuid']) ? $_POST['luks_uuid'] : null
            );
            if ($request['action'] === 'edit') {
                $request['name'] = isset($_POST['name']) ? $_POST['name'] : null;
                $request['mount_folder'] = isset($_POST['mount_folder']) ? $_POST['mount_folder'] : null;
            } elseif ($request['action'] === 'delete') {
                $request['confirmation'] = isset($_POST['confirmation']) ? $_POST['confirmation'] : null;
            }
            if ($request['action'] === 'key_preference') {
                // Configuration can remove a saved key, but enrolling a new
                // one requires the explicit checkbox in the Unlock dialogue.
                if (isset($_POST['remove_unlock_key'])) {
                    if ($_POST['remove_unlock_key'] !== '1') {
                        throw new RuntimeException('Invalid unlock key preference.');
                    }
                    require_once __DIR__ . '/../lib/unlock_key.php';
                    $directory = edm_auth_data_path();
                    $uuid = $request['luks_uuid'];
                    edm_unlock_key_with_lock($directory, function () use ($directory, $uuid) {
                        edm_registry_set_key_preference($directory . '/disks.json', $uuid, false);
                        edm_unlock_key_remove($directory, $uuid);
                    });
                }
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            if ($request['action'] === 'register') {
                edm_registry_register(edm_auth_data_path() . '/disks.json', $request['luks_uuid'],
                    isset($_POST['name']) ? $_POST['name'] : null,
                    isset($_POST['mount_folder']) ? $_POST['mount_folder'] : null, $devices);
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            $validated = edm_configuration_request($request);
            if ($validated['action'] === 'delete') {
                require_once __DIR__ . '/../lib/unlock_key.php';
                $directory = edm_auth_data_path();
                $uuid = $validated['luks_uuid'];
                edm_unlock_key_with_lock($directory, function () use ($directory, $uuid, $validated) {
                    edm_registry_save($directory . '/disks.json', null, function ($registry) use ($directory, $uuid, $validated) {
                        edm_configuration_registration_preflight($validated, $registry);
                        $live = edm_discover_usb_inventory();
                        if (!is_array($live['sysEntries']) || !is_readable('/proc/self/mountinfo')
                            || !edm_registration_can_delete($uuid, $live['devices'])) {
                            throw new RuntimeException('Unmount the disk before deleting its registration, or wait for disk state to become available.');
                        }
                        edm_unlock_key_remove($directory, $uuid);
                        $registry['disks'] = array_values(array_filter($registry['disks'], function ($disk) use ($uuid) {
                            return strcasecmp($disk['luks_uuid'], $uuid) !== 0;
                        }));
                        return $registry;
                    });
                });
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
            $currentRegistry = edm_registry_load(edm_auth_data_path() . '/disks.json');
            edm_configuration_registration_preflight($validated, $currentRegistry);
            $configValidationNotice = 'Configuration request validated for ' . $validated['luks_uuid']
                . '. No changes were saved: live disk-state verification is not yet enabled.';
        } catch (Throwable $exception) {
            http_response_code(400);
            $configValidationError = 'Invalid configuration request: ' . $exception->getMessage();
        }
    }
}

// Registry is authoritative for persistent rows; USB discovery supplies live state.
$inventoryError = '';
try {
    $registry = edm_registry_load(edm_auth_data_path() . '/disks.json');
    if (!is_array($sysEntries)) {
        throw new RuntimeException('Kernel block device discovery is unavailable.');
    }
    $inventoryRows = edm_inventory_rows($registry['disks'], $devices);
    // Display-only association; never used to authorize encryption.
    $partitionParents = array();
    if (!empty($wholeDiskInventory['complete'])) {
        foreach ($wholeDiskInventory['disks'] as $wholeDisk) {
            foreach ($wholeDisk['partitions'] as $partitionName) {
                $partitionParents[$partitionName] = $wholeDisk['name'];
            }
        }
    }
} catch (Throwable $exception) {
    error_log('EncDiskManager inventory error: ' . $exception->getMessage());
    $inventoryError = 'Registered disk inventory is temporarily unavailable.';
    $inventoryRows = array();
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Encrypted Disk Manager</title>

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
        background: #fff;
        font: inherit;
        cursor: pointer;
    }
    .backup-link:hover { background: #f3f7f9; }
    h1 { margin: 0; font-size: 1.45rem; }
    main { margin: 24px 0; padding: 0 18px; }
    .panel {
        background: #fff;
        border: 1px solid #d9dfe2;
        border-radius: 8px;
        padding: 18px;
        margin-bottom: 18px;
    }
    .muted { color: #58656d; }
    .table-wrap { overflow-x: auto; }
    .inventory-tabs { display: flex; gap: 8px; margin: 16px 0 10px; border-bottom: 1px solid #d9dfe2; }
    .inventory-tab { border: 1px solid transparent; border-bottom: 0; border-radius: 6px 6px 0 0; padding: 9px 15px; background: transparent; color: #075f91; font: inherit; cursor: pointer; }
    .inventory-tab[aria-selected="true"] { background: #f3f7f9; border-color: #d9dfe2; color: #20272c; font-weight: bold; }
    .inventory-tab:focus-visible { outline: 2px solid #075f91; outline-offset: -2px; }
    #disk-inventory tr[hidden] { display: none; }
    #disk-inventory tr[data-whole-disk] { background: #f3f7f9; font-weight: bold; }
    #disk-inventory tr[data-parent-disk] td:first-child { padding-left: 28px; }
    .sort-heading { border: 0; background: transparent; font: inherit; font-weight: bold; cursor: pointer; padding: 0; text-align: left; }
    .sort-heading:focus-visible { outline: 2px solid #075f91; outline-offset: 3px; }
    .disk-actions { min-width: 220px; }
    .disk-actions button { margin: 2px; padding: 5px; }
    .disk-actions button:disabled { opacity: .5; cursor: not-allowed; }
    .configure-disk { border: 1px solid #aebac1; background: #fff; border-radius: 4px; color: #075f91; cursor: pointer; }
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
        <h1>Encrypted Disk Manager<?php if (edm_app_version() !== ''): ?> · v<?php echo h(edm_app_version()); ?><?php endif; ?></h1>
        <div class="muted">USB storage · LUKS inventory</div>
    </div>
    <div class="header-actions">
        <button type="button" class="backup-link" id="open-information" title="Application information">
            <span>Information</span>
        </button>
        <a class="backup-link" href="settings.php"
           title="Manage configuration and backups">
            
            <span>Settings</span>
        </a>
        <form method="post" style="display:inline-block;margin-left:8px">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="auth_action" value="logout">
            <button type="submit" class="backup-link" title="Sign out of Encrypted Disk Manager">
                
                <span>Sign out</span>
            </button>
        </form>
    </div>
</header>
<main>
    <section class="panel">
        <h2>Disk inventory</h2>
        <?php if ($configValidationNotice !== ''): ?><p role="status"><?php echo h($configValidationNotice); ?></p><?php endif; ?>
        <?php if ($configValidationError !== ''): ?><p role="alert"><?php echo h($configValidationError); ?></p><?php endif; ?>
        <p class="muted">Registered disks remain listed when disconnected. Available disk actions depend on the disk's encryption and mount state. Whole-disk encryption permanently erases the selected USB disk and requires explicit confirmation.</p>
        <?php if ($inventoryError !== ''): ?>
            <p class="error"><?php echo h($inventoryError); ?></p>
        <?php endif; ?>
        <?php if ($inventoryError === ''): ?>
        <?php
            $connectedCount = 0;
            $disconnectedCount = 0;
            foreach ($inventoryRows as $inventoryRow) {
                if ($inventoryRow['device'] === null) $disconnectedCount++;
            }
            $connectedCount = !empty($wholeDiskInventory['complete'])
                ? count($wholeDiskInventory['disks']) : 0;
        ?>
        <div class="inventory-tabs" role="tablist" aria-label="Disk connection status">
            <button type="button" class="inventory-tab" id="connected-tab" role="tab"
                aria-selected="true" aria-controls="disk-inventory" data-filter="connected">Connected (<?php echo $connectedCount; ?>)</button>
            <button type="button" class="inventory-tab" id="disconnected-tab" role="tab"
                aria-selected="false" aria-controls="disk-inventory" data-filter="disconnected" tabindex="-1">Disconnected (<?php echo $disconnectedCount; ?>)</button>
        </div>
        <p id="inventory-empty-filter" class="muted" role="status" hidden></p>
        <div class="table-wrap" id="disk-inventory-panel" role="tabpanel" aria-labelledby="connected-tab">
        <table id="disk-inventory">
            <thead><tr>
                <th>Actions</th>
                <th><button type="button" class="sort-heading" data-column="1" aria-sort="ascending">Name ▲</button></th>
                <th><button type="button" class="sort-heading" data-column="2">Device</button></th>
                <th><button type="button" class="sort-heading" data-column="3">Status</button></th>
                <th><button type="button" class="sort-heading" data-column="4">Filesystem / type</button></th>
                <th><button type="button" class="sort-heading" data-column="5">Mount directory</button></th>
                <th><button type="button" class="sort-heading" data-column="6">LUKS UUID</button></th>
            </tr></thead>
            <tbody>
            <?php if (!empty($wholeDiskInventory['complete'])): ?>
            <?php foreach ($wholeDiskInventory['disks'] as $wholeDisk):
                $candidate = edm_whole_usb_disk_stable_candidate($wholeDiskInventory, $wholeDiskSecondSnapshot, $wholeDisk['name']);
                $ready = $candidate !== false;
                $busy = $wholeDisk['mounted'] || $wholeDisk['has_holders'] || $wholeDisk['has_slaves'];
            ?>
                <tr data-whole-disk="<?php echo h($wholeDisk['name']); ?>" data-key="whole:<?php echo h($wholeDisk['name']); ?>" data-connection="connected">
                    <td class="disk-actions"><button type="button" class="whole-disk-preview-button"
                        data-disk="<?php echo h('/dev/' . $wholeDisk['name']); ?>"
                        <?php echo $ready ? '' : 'disabled'; ?>>Encrypt + ext4</button></td>
                    <td><?php echo h('USB disk ' . $wholeDisk['name']); ?></td>
                    <td><code><?php echo h('/dev/' . $wholeDisk['name']); ?></code></td>
                    <td><?php echo h($busy ? 'In use' : ($wholeDisk['read_only'] ? 'Read-only' : ($ready ? 'Available' : 'Disk checks incomplete'))); ?></td>
                    <td><?php echo h(number_format($wholeDisk['size_bytes'] / 1073741824, 2) . ' GiB; ' . (count($wholeDisk['partitions']) ? implode(', ', $wholeDisk['partitions']) : 'no partitions')); ?></td>
                    <td>—</td><td>—</td>

                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php foreach ($inventoryRows as $row):
                $disk = $row['device'];
                $status = $row['status'];
                $mountFolder = $row['registered'] && $status !== 'Encrypted — Mounted'
                    ? '/mnt/' . $row['mount_folder'] : '—';
            ?>
                <tr data-key="<?php echo h($row['registered'] ? 'registered:' . strtolower($row['luks_uuid']) : 'device:' . $disk['name']); ?>" data-parent-disk="<?php echo h($disk !== null && isset($partitionParents[$disk['name']]) ? $partitionParents[$disk['name']] : ''); ?>" data-connection="<?php echo $disk === null ? 'disconnected' : 'connected'; ?>"<?php echo $disk === null ? ' hidden' : ''; ?>>
                    <td class="disk-actions">
                        <button type="button" class="register-disk" data-uuid="<?php echo h($row['luks_uuid']); ?>"
                            data-name="<?php echo h($row['name']); ?>" data-status="<?php echo h($row['status']); ?>"
                            <?php echo !$row['registered'] && $disk !== null && !empty($disk['luks']) && $row['luks_uuid'] !== '' ? '' : 'disabled'; ?>>Register</button>
                            <button type="button" class="configure-disk" <?php echo $row['registered'] ? '' : 'disabled'; ?>
                                data-uuid="<?php echo h($row['luks_uuid']); ?>"
                                data-name="<?php echo h($row['name']); ?>"
                                data-folder="<?php echo h($row['mount_folder']); ?>"
                                data-store-key="<?php echo !empty($row['store_unlock_key']) ? '1' : '0'; ?>"
                                data-status="<?php echo h($row['status']); ?>"
                                title="View registered disk configuration">Configure</button>
                        <?php foreach (array('Unlock', 'Mount', 'Unmount', 'Lock') as $action): ?>
                            <button type="button" disabled title="<?php echo h($action . ': not yet available in this development build'); ?>"><?php echo h($action); ?></button>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo $disk !== null && isset($partitionParents[$disk['name']]) ? '↳ ' : ''; ?><?php echo h($row['name']); ?><?php if (!$row['registered']): ?> <small>(unregistered)</small><?php endif; ?></td>
                    <td><code><?php echo h($disk === null ? '—' : $disk['path']); ?></code></td>
                    <td><?php echo h($status); ?></td>
                    <td><?php echo h($disk === null ? '—' : ($disk['type'] ?: 'Unknown')); ?></td>
                    <td><?php echo h($mountFolder); ?></td>
                    <td><code><?php echo h($row['luks_uuid'] ?: '—'); ?></code></td>

                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <dialog id="whole-disk-erase-preview" aria-labelledby="whole-disk-erase-title">
        <h2 id="whole-disk-erase-title">Whole-disk erase confirmation</h2>
        <p>Selected disk: <strong id="whole-disk-preview-name">None</strong></p>
        <p><strong>Warning:</strong> Encrypting a whole USB disk will erase and repartition
            the entire selected disk, permanently destroying all existing data.</p>
        <label><input id="whole-disk-erase-ack" type="checkbox"> I understand that the entire disk will be erased.</label>
        <p>Are you sure? Your data will be permanently lost!</p>
        <label for="whole-disk-erase-phrase">Final confirmation phrase</label>
        <input id="whole-disk-erase-phrase" type="text" placeholder="ERASE ALL DATA" autocomplete="off">
        <p>Encryption passphrase:</p>
        <label for="whole-disk-passphrase">Passphrase</label>
        <input id="whole-disk-passphrase" type="password" autocomplete="new-password" maxlength="4096">
        <label for="whole-disk-passphrase-confirm">Confirm passphrase</label>
        <input id="whole-disk-passphrase-confirm" type="password" autocomplete="new-password" maxlength="4096">
        
        
        <p id="whole-disk-preview-status" role="status" aria-live="polite"></p>
        <p id="whole-disk-mount-warning" role="alert" hidden></p>
        <button id="whole-disk-preview-confirm" type="button" disabled title="This button only validates the confirmation">Validate confirmation (read-only)</button>
        <button id="whole-disk-format-confirm" type="button" disabled>Encrypt + ext4 — erase disk</button>
        <form method="dialog"><button type="submit">Cancel</button></form>
    </dialog>
    <dialog id="disk-config-dialog" aria-labelledby="disk-config-title">
        <h2 id="disk-config-title">Disk configuration</h2>
        <p class="muted" id="config-preview-note">A stored key or an unmounted disk registration can be removed even when the disk is disconnected. Name and mount directory editing are not yet enabled.</p>
        <p id="registration-note" hidden>Registration does not change the current mount or disk contents. The configured directory is saved for future mount support.</p>
        <p><strong>LUKS UUID:</strong> <code id="config-uuid"></code></p>
        <p><strong>Connection:</strong> <span id="config-status"></span></p>
        <form method="post" id="disk-config-form">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="config_action" value="edit">
        <input type="hidden" name="luks_uuid" id="config-form-uuid">
        <input type="hidden" name="confirmation" id="config-delete-confirmation" disabled>
        <label for="config-name">Registered name</label>
        <input id="config-name" name="name" type="text" maxlength="160" required>
        <label for="config-folder">Unique mount directory under /mnt</label>
        <input id="config-folder" name="mount_folder" type="text" maxlength="48" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,47}" required>
        <div id="config-key-options">
            <label><input type="checkbox" id="config-remove-key" name="remove_unlock_key" value="1"> Remove stored unlock key</label>
            <p class="muted" id="config-key-status"></p>
            <p class="muted">Save a key in the Unlock dialogue. Removing a key here removes the local copy, not its LUKS keyslot.</p>
        </div>
        <div class="dialog-actions">
            <button type="submit">Validate changes (no save)</button>
            <button type="button" id="config-delete-registration" disabled>Delete registration</button>
            <button type="button" id="close-disk-config">Close</button>
        </div>
        </form>
    </dialog>

    <dialog id="information-dialog" aria-labelledby="information-title">
        <h2 id="information-title">Information</h2>
        <section class="panel safe-note">
            <strong>Before uninstalling or reinstalling:</strong>
            download a configuration backup from the Backup section on the Settings page.
            Normal upgrades and ordinary app removal preserve the administrator account
            and registered-disk configuration in WD's persistent app-data directory.
            Use Settings to restore a .tgz backup or explicitly delete saved configuration.
            Backups contain a password hash and disk settings, never plaintext passwords or LUKS passphrases.
        </section>
        <section class="panel safe-note">
            <strong>Status information:</strong> This page uses detected block-device,
            encryption, and mount information to estimate each USB partition’s state.
            An open encrypted volume is shown separately when its device-mapper
            relationship to a detected USB partition can be established. Unlock,
            Mount, Unmount and Lock are available only for eligible disk states.
            A stored key allows Mount to unlock automatically. Before unplugging a disk,
            click Unmount and then Lock separately, even if a key is stored.
            Whole-disk encryption permanently erases the selected USB disk.
        </section>
        <form method="dialog" class="dialog-actions">
            <button type="submit">Close</button>
        </form>
    </dialog>

</main>
<script>
(function () {
    var dialog = document.getElementById('information-dialog');
    document.getElementById('open-information').addEventListener('click', function () {
        dialog.showModal();
    });
}());
(function () {
    var dialog = document.getElementById('whole-disk-erase-preview');
    var selected = document.getElementById('whole-disk-preview-name');
    var acknowledgement = document.getElementById('whole-disk-erase-ack');
    var phrase = document.getElementById('whole-disk-erase-phrase');
    var passphrase = document.getElementById('whole-disk-passphrase');
    var passphraseConfirm = document.getElementById('whole-disk-passphrase-confirm');
    var confirm = document.getElementById('whole-disk-preview-confirm');
    var formatConfirm = document.getElementById('whole-disk-format-confirm');
    var status = document.getElementById('whole-disk-preview-status');
    var mountWarning = document.getElementById('whole-disk-mount-warning');
    if (!dialog || !selected || !acknowledgement || !phrase || !confirm || !status
        || typeof dialog.showModal !== 'function') return;
    var selectionVersion = 0;
    function refreshButton() {
        confirm.disabled = !acknowledgement.checked || phrase.value !== 'ERASE ALL DATA';
        formatConfirm.disabled = confirm.disabled || !passphrase.value || passphrase.value !== passphraseConfirm.value;
    }
    function refresh() {
        selectionVersion++;
        refreshButton();
        status.textContent = '';
    }
    acknowledgement.addEventListener('change', refresh);
    passphrase.addEventListener('input', refresh);
    passphraseConfirm.addEventListener('input', refresh);
    phrase.addEventListener('input', refresh);
    // Closing the dialog must also discard unsent passphrases.
    dialog.addEventListener('close', function () {
        selectionVersion++;
        passphrase.value = '';
        passphraseConfirm.value = '';
    });
    confirm.addEventListener('click', function () {
        if (confirm.disabled) return;
        confirm.disabled = true;
        status.textContent = 'Checking confirmation with the server…';
        var form = new URLSearchParams();
        var csrfInput = document.querySelector('input[name="csrf_token"]');
        if (!csrfInput) {
            refreshButton();
            status.textContent = 'Confirmation unavailable. No disk was modified.';
            return;
        }
        form.set('csrf_token', csrfInput.value);
        form.set('warning_accepted', acknowledgement.checked ? 'yes' : 'no');
        form.set('confirmation_phrase', phrase.value);
        form.set('disk', selected.textContent);
        var requestVersion = selectionVersion;
        fetch('whole-disk-confirmation-preview.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: form.toString()
        }).then(function (response) {
            if (!response.ok) throw new Error('Confirmation rejected');
            return response.json();
        }).then(function (result) {
            if (requestVersion !== selectionVersion) return;
            if (!result || result.confirmed !== true || result.execution_enabled !== false) {
                throw new Error('Confirmation rejected');
            }
            status.textContent = 'Server validated the confirmation. No disk was modified by this check.';
        }).catch(function () {
            if (requestVersion === selectionVersion) {
                status.textContent = 'Confirmation could not be validated. No disk was modified.';
            }
        }).then(function () {
            if (requestVersion === selectionVersion) refreshButton();
        });
    });
    // Send the confirmed disk and passphrase only to the authenticated endpoint.
    if (formatConfirm) {
        formatConfirm.addEventListener('click', function () {
            if (formatConfirm.disabled || !acknowledgement.checked ||
                phrase.value !== 'ERASE ALL DATA' ||
                !passphrase.value || passphrase.value !== passphraseConfirm.value) return;
            var csrfInput = document.querySelector('input[name="csrf_token"]');
            if (!csrfInput) return;
            var form = new URLSearchParams();
            form.set('csrf_token', csrfInput.value);
            form.set('disk', selected.textContent);
            form.set('warning_accepted', 'yes');
            form.set('confirmation_phrase', phrase.value);
            form.set('passphrase', passphrase.value);
            form.set('passphrase_confirm', passphraseConfirm.value);
            formatConfirm.disabled = true;
            status.textContent = 'Encrypting and mounting USB volume…';
            fetch('whole-disk-format.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: form.toString()
            }).then(function (response) {
                return response.json().then(function (result) {
                    if (!response.ok) throw new Error(result.error || 'Encryption failed.');
                    return result;
                });
            }).then(function (result) {
                if (!showFormatResult(result)) throw new Error('Unexpected format response.');
            }).catch(function (error) {
                status.textContent = error.message + ' Check disk state before retrying.';
            }).then(function () {
                // Never retain a formatting passphrase after a request, even
                // when the server rejects it or the connection is interrupted.
                passphrase.value = '';
                passphraseConfirm.value = '';
            });
        });
    }
    // Shared completion handler for the formatting request.
    // Do not infer the mountpoint: display only the server-confirmed result.
    function showFormatResult(result) {
        if (!result || result.ok !== true) return false;
        if (mountWarning) {
            mountWarning.textContent = typeof result.warning === 'string'
                ? result.warning
                : 'Encryption completed. Check the disk table for its current mount state.';
            mountWarning.hidden = false;
        }
        status.textContent = 'Encryption completed. Disk inventory will refresh automatically.';
        passphrase.value = '';
        passphraseConfirm.value = '';
        return true;
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.whole-disk-preview-button');
        if (!button || button.disabled || !document.getElementById('disk-inventory').contains(button)) return;
            selected.textContent = button.getAttribute('data-disk') || 'Unknown';
            acknowledgement.checked = false;
            phrase.value = '';
            passphrase.value = '';
            passphraseConfirm.value = '';
            if (mountWarning) {
                mountWarning.hidden = true;
                mountWarning.textContent = '';
            }
            refresh();
            dialog.showModal();
    });
}());
(function () {
    'use strict';
    var dialog = document.getElementById('disk-config-dialog');
    if (dialog && typeof dialog.showModal === 'function') {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.configure-disk, .register-disk');
            if (!button || button.disabled || !document.getElementById('disk-inventory').contains(button)) return;
            var registering = button.classList.contains('register-disk');
            document.getElementById('disk-config-title').textContent = registering ? 'Register disk' : 'Disk configuration';
            document.querySelector('#disk-config-form [name=config_action]').value = registering ? 'register' : 'key_preference';
            document.querySelector('#disk-config-form button[type=submit]').textContent = registering ? 'Register' : 'Save';
            var deleteButton = document.getElementById('config-delete-registration');
            deleteButton.disabled = registering || button.dataset.canDelete !== '1';
            deleteButton.title = deleteButton.disabled ? 'Available only for unmounted registrations with known disk state' : 'Remove registration without erasing the disk';
            document.getElementById('config-delete-confirmation').disabled = true;
            document.getElementById('config-preview-note').hidden = registering;
            document.getElementById('registration-note').hidden = !registering;
            document.getElementById('config-key-options').hidden = registering;
            document.getElementById('config-remove-key').disabled = registering || button.dataset.storeKey !== '1';
            document.getElementById('config-remove-key').checked = false;
            document.getElementById('config-key-status').textContent = button.dataset.keyAvailable === '1'
                ? 'A stored key is available on this NAS.'
                : button.dataset.storeKey === '1' ? 'Key storage is enabled, but no key is saved yet.'
                : 'No stored unlock key.';
            document.getElementById('config-name').readOnly = !registering;
            document.getElementById('config-folder').readOnly = !registering;
            document.getElementById('config-uuid').textContent = button.getAttribute('data-uuid');
            document.getElementById('config-form-uuid').value = button.getAttribute('data-uuid');
            document.getElementById('config-status').textContent = button.getAttribute('data-status');
            document.getElementById('config-name').value = button.getAttribute('data-name');
            document.getElementById('config-folder').value = registering
                ? 'edm_' + button.dataset.uuid.toLowerCase().replace(/-/g, '')
                : button.getAttribute('data-folder');
            dialog.showModal();
        });
        document.getElementById('config-delete-registration').addEventListener('click', function () {
            if (this.disabled) return;
            if (!window.confirm('Delete this disk registration and its locally stored unlock key? Disk contents and LUKS encryption will not be changed. Keep your original passphrase.')) return;
            var form = document.getElementById('disk-config-form');
            var uuid = document.getElementById('config-form-uuid').value;
            var confirmation = document.getElementById('config-delete-confirmation');
            confirmation.value = 'DELETE ' + uuid.toLowerCase();
            confirmation.disabled = false;
            form.querySelector('[name=config_action]').value = 'delete';
            form.submit();
        });
        document.getElementById('close-disk-config').addEventListener('click', function () { dialog.close(); });
    }
    var table = document.getElementById('disk-inventory');
    if (!table) return;
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.inventory-tab'));
    var emptyFilter = document.getElementById('inventory-empty-filter');
    var panel = document.getElementById('disk-inventory-panel');
    function selectConnection(connection, focusTab) {
        var visible = 0;
        Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
            row.hidden = row.getAttribute('data-connection') !== connection;
            if (!row.hidden && (connection !== 'connected' || !!row.dataset.wholeDisk)) visible++;
        });
        tabs.forEach(function (tab) {
            var selected = tab.getAttribute('data-filter') === connection;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            if (selected) {
                panel.setAttribute('aria-labelledby', tab.id);
                if (focusTab) tab.focus();
            }
        });
        emptyFilter.hidden = visible !== 0;
        emptyFilter.textContent = connection === 'connected'
            ? 'No connected disks were found.'
            : 'No disconnected registered disks were found.';
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            selectConnection(tab.getAttribute('data-filter'), false);
        });
        tab.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight'
                && event.key !== 'Home' && event.key !== 'End') return;
            event.preventDefault();
            var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
                : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            selectConnection(tabs[next].getAttribute('data-filter'), true);
        });
    });
    selectConnection('connected', false);
    // Poll only while the page is visible. Reconcile rows by stable identity;
    // never replace the table or overwrite an open configuration dialog.
    var pollNotice = document.createElement('p');
    pollNotice.id = 'inventory-poll-status';
    pollNotice.setAttribute('role', 'status');
    pollNotice.className = 'muted';
    table.parentNode.insertBefore(pollNotice, table);
    function currentConnection() {
        var selected = tabs.filter(function (tab) {
            return tab.getAttribute('aria-selected') === 'true';
        })[0];
        return selected ? selected.getAttribute('data-filter') : 'connected';
    }
    function updateCounts() {
        ['connected', 'disconnected'].forEach(function (connection) {
            var count = connection === 'connected'
                ? table.tBodies[0].querySelectorAll('tr[data-whole-disk]').length
                : table.tBodies[0].querySelectorAll('tr[data-connection="disconnected"]').length;
            tabs.forEach(function (tab) {
                if (tab.getAttribute('data-filter') === connection) {
                    tab.textContent = (connection === 'connected' ? 'Connected' : 'Disconnected') + ' (' + count + ')';
                }
            });
        });
    }
    function updateRow(row, item) {
        row.setAttribute('data-connection', item.connection);
        row.dataset.device = item.device;
        row.dataset.mapper = item.mapper;
        row.dataset.uuid = item.uuid;
        row.dataset.registered = item.registered ? '1' : '0';
        row.dataset.storeKey = item.store_unlock_key ? '1' : '0';
        row.dataset.keyAvailable = item.stored_key_available ? '1' : '0';
        row.dataset.parentDisk = item.parent_disk || '';
        var mount = item.status === 'Encrypted — Mounted' ? '—' : item.mount;
        var values = [item.name, item.device, item.status, item.type, mount, item.uuid];
        values.forEach(function (value, index) {
            var cell = row.cells[index + 1];
            if (index === 0) {
                var text = (item.parent_disk ? '↳ ' : '') + item.name + (item.registered ? '' : ' (unregistered)');
                if (cell.textContent !== text) cell.textContent = text;
            } else if (cell.textContent !== value) {
                cell.textContent = value;
            }
        });
        var actionCell = row.cells[0];
        // LUKS actions follow the current partition state. Whole-disk
        // encryption is controlled separately on the physical USB disk row.
        ['unlock', 'mount', 'unmount', 'lock'].forEach(function (action, index) {
            var button = Array.prototype.filter.call(actionCell.querySelectorAll('button'), function (candidate) {
                return !candidate.classList.contains('configure-disk') && !candidate.classList.contains('register-disk');
            })[index];
            if (!button) return;
            button.dataset.operation = action;
            // A locked disk with key storage enabled can mount directly.
            // Once a mapper exists, Mount and Unmount stay on that child row.
            var combined = item.registered && item.store_unlock_key;
            var directMount = combined && item.actions.unlock;
            button.hidden = action === 'unmount' || (action === 'mount' && !directMount);
            var available = action === 'unlock' && combined ? false :
                action === 'mount' && directMount ? true : item.actions[action];
            button.disabled = !available || operationBusy;
            button.title = !available ? 'Not available for this disk state' :
                directMount && action === 'mount' ? 'Unlock and mount' :
                action === 'unmount' ? 'Unmount filesystem (then Lock before unplugging)' :
                action === 'lock' ? 'Lock encrypted disk before unplugging' : 'Perform ' + action;
        });
        ['register', 'configure'].forEach(function (mode) {
            var button = actionCell.querySelector('.' + mode + '-disk');
            if (!button) {
                button = document.createElement('button');
                button.type = 'button';
                button.className = mode + '-disk';
                button.textContent = mode === 'register' ? 'Register' : 'Configure';
                actionCell.insertBefore(button, actionCell.firstChild);
            }
            button.disabled = mode === 'configure' ? !item.registered :
                item.registered || item.connection !== 'connected' ||
                !/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(item.uuid);
            button.dataset.uuid = item.uuid;
            button.dataset.name = item.name;
            button.dataset.folder = item.folder;
            button.dataset.storeKey = item.store_unlock_key === true ? '1' : '0';
            button.dataset.keyAvailable = item.stored_key_available ? '1' : '0';
            button.dataset.canDelete = item.can_delete_registration === true && !operationBusy ? '1' : '0';
            button.dataset.status = item.status;
        });

    }
    function sortRows() {
        var body = table.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        rows.sort(function (a, b) {
            // Keep each USB parent immediately above its partitions.
            var groupA = a.dataset.wholeDisk || a.dataset.parentDisk || a.dataset.key || '';
            var groupB = b.dataset.wholeDisk || b.dataset.parentDisk || b.dataset.key || '';
            var groupOrder = groupA.localeCompare(groupB, undefined, {numeric: true});
            if (groupOrder !== 0) return ascending ? groupOrder : -groupOrder;
            if (!!a.dataset.wholeDisk !== !!b.dataset.wholeDisk) return a.dataset.wholeDisk ? -1 : 1;
            var partitionA = a.dataset.mapperParent || a.dataset.device || '';
            var partitionB = b.dataset.mapperParent || b.dataset.device || '';
            if (partitionA && partitionB && partitionA !== partitionB) {
                var partitionOrder = partitionA.localeCompare(partitionB, undefined, {numeric: true});
                if (partitionOrder !== 0) return ascending ? partitionOrder : -partitionOrder;
            }
            if (!!a.dataset.mapperRow !== !!b.dataset.mapperRow) return a.dataset.mapperRow ? 1 : -1;
            var x = a.cells[active].textContent.trim();
            var y = b.cells[active].textContent.trim();
            var cmp = x.localeCompare(y, undefined, {sensitivity: 'base', numeric: true});
            return ascending ? cmp : -cmp;
        });
        rows.forEach(function (row, index) {
            if (body.rows[index] !== row) body.insertBefore(row, body.rows[index] || null);
        });
    }
    // Physical disks are displayed in this same table, above their partitions.
    // Their erase controls use the existing confirmation dialog.
    function reconcileWholeDisks(snapshot) {
        if (!snapshot.whole_disks_complete || !Array.isArray(snapshot.whole_disks)) {
            Array.prototype.forEach.call(table.querySelectorAll('tr[data-whole-disk]'), function (row) {
                var button = row.querySelector('.whole-disk-preview-button');
                if (button) {
                    button.disabled = true;
                    button.title = 'Physical disk discovery incomplete; encryption unavailable';
                }
                row.cells[3].textContent = 'Disk checks incomplete';
            });
            return;
        }
        var body = table.tBodies[0];
        var present = Object.create(null);
        // Validate every parent before modifying any live table rows.
        snapshot.whole_disks.forEach(function (disk) {
            if (!disk || typeof disk.name !== 'string' || !/^[a-zA-Z0-9._+-]+$/.test(disk.name)
                || typeof disk.path !== 'string' || disk.path !== '/dev/' + disk.name
                || !Array.isArray(disk.partitions) || typeof disk.can_encrypt !== 'boolean'
                || typeof disk.size_bytes !== 'number' || present[disk.name]) {
                throw new Error('Invalid or duplicate physical USB disk');
            }
            present[disk.name] = true;
        });
        snapshot.whole_disks.forEach(function (disk) {
            var row = Array.prototype.filter.call(body.rows, function (item) {
                return item.dataset.wholeDisk === disk.name;
            })[0];
            if (!row) {
                row = document.createElement('tr');
                row.dataset.wholeDisk = disk.name;
                row.dataset.key = 'whole:' + disk.name;
                row.dataset.connection = 'connected';
                for (var i = 0; i < 7; i++) row.appendChild(document.createElement('td'));
                row.cells[0].className = 'disk-actions';
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'whole-disk-preview-button';
                button.textContent = 'Encrypt + ext4';
                row.cells[0].appendChild(button);
                body.appendChild(row);
            }
            row.cells[1].textContent = 'USB disk ' + disk.name;
            row.cells[2].textContent = disk.path;
            row.cells[3].textContent = disk.mounted ? 'Mounted / in use'
                : disk.busy ? 'In use' : disk.read_only ? 'Read-only'
                : disk.can_encrypt ? 'Available' : 'Disk checks incomplete';
            row.cells[4].textContent = (disk.size_bytes / 1073741824).toFixed(2) + ' GiB; '
                + (disk.partitions.length ? disk.partitions.join(', ') : 'no partitions');
            row.cells[5].textContent = '—';
            row.cells[6].textContent = '—';
            var action = row.querySelector('.whole-disk-preview-button');
            action.dataset.disk = disk.path;
            action.disabled = !disk.can_encrypt || operationBusy;
            action.title = action.disabled ? 'Disk not eligible for whole-disk encryption'
                : 'Erase and encrypt the entire USB disk';
        });
        Array.prototype.forEach.call(body.querySelectorAll('tr[data-whole-disk]'), function (row) {
            if (!present[row.dataset.wholeDisk]) row.remove();
        });
    }
    var operationBusy = false;
    function requestUnlockPassphrase(device, action, canSave) {
        return new Promise(function (resolve) {
            var dialog = document.createElement('dialog');
            var form = document.createElement('form');
            form.method = 'dialog';
            var label = document.createElement('label');
            label.textContent = 'LUKS passphrase for ' + device;
            var password = document.createElement('input');
            password.type = 'password';
            password.autocomplete = 'off';
            password.required = true;
            password.maxLength = 4096;
            label.appendChild(password);
            form.appendChild(label);
            var save = document.createElement('input');
            save.type = 'checkbox';
            save.checked = false;
            if (canSave) {
                var saveLabel = document.createElement('label');
                saveLabel.appendChild(save);
                saveLabel.appendChild(document.createTextNode(' Securely save the key'));
                form.appendChild(saveLabel);
                var note = document.createElement('p');
                note.className = 'muted';
                note.textContent = 'Store a generated unlock key on this NAS for future mounts. Your original passphrase is not saved.';
                form.appendChild(note);
            }
            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.textContent = 'Cancel';
            var submit = document.createElement('button');
            submit.type = 'submit';
            submit.textContent = action === 'mount' ? 'Mount' : 'Unlock';
            form.appendChild(cancel);
            form.appendChild(submit);
            dialog.appendChild(form);
            document.body.appendChild(dialog);
            var settled = false;
            function finish(value) {
                if (settled) return;
                settled = true;
                password.value = '';
                dialog.close();
                dialog.remove();
                resolve(value);
            }
            cancel.addEventListener('click', function () { finish(null); });
            dialog.addEventListener('cancel', function (event) {
                event.preventDefault();
                finish(null);
            });
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                finish(password.value ? {passphrase: password.value, saveKey: canSave && save.checked} : null);
            });
            dialog.showModal();
            password.focus();
        });
    }
    table.addEventListener('click', async function (event) {
        var button = event.target.closest('button[data-operation]');
        if (!button || button.disabled || operationBusy) return;
        var row = button.closest('tr');
        var action = button.dataset.operation;
        if (!row || !['unlock', 'mount', 'unmount', 'lock'].includes(action)) return;
        var device = row.dataset.device;
        var mapper = row.dataset.mapper;
        var uuid = row.dataset.uuid;
        if (action === 'unlock') {
            // Derive a deterministic, app-owned mapper name from the LUKS UUID.
            // Never reuse the legacy backupdisk mapping.
            if (!/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(row.dataset.uuid)) {
                window.alert('The disk has no valid LUKS UUID.');
                return;
            }
            mapper = 'edm_' + row.dataset.uuid.replace(/-/g, '').toLowerCase();
        }
        var credentials = null;
        if (action === 'unlock' || (action === 'mount' && row.dataset.storeKey === '1' &&
            row.dataset.keyAvailable !== '1')) {
            credentials = await requestUnlockPassphrase(device, action,
                action === 'unlock' && row.dataset.registered === '1' && row.dataset.storeKey !== '1');
            if (credentials === null) return;
        } else if (!window.confirm('Perform ' + action + ' on ' + device + '?')) {
            return;
        }
        var csrf = document.querySelector('input[name="csrf_token"]');
        if (!csrf) return;
        var body = new URLSearchParams();
        body.set('csrf_token', csrf.value);
        body.set('action', action);
        body.set('device', device);
        body.set('mapper', mapper);
        body.set('luks_uuid', uuid);
        if (credentials !== null) {
            body.set('passphrase', credentials.passphrase);
            if (credentials.saveKey) body.set('save_unlock_key', '1');
        }
        credentials = null;
        operationBusy = true;
        Array.prototype.forEach.call(table.querySelectorAll('button[data-operation]'), function (item) {
            item.disabled = true;
        });
        fetch('usb-operation.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.ok) throw new Error(result.error || 'Operation failed');
            });
        }).then(function () {
            window.alert('Operation completed.');
        }).catch(function (error) {
            window.alert(error.message);
        }).then(function () {
            operationBusy = false;
            pollInventory();
        });
    });
    // Show an open mapper directly beneath its USB partition, not in a
    // separate table. These rows are informational, not action targets.
    function updateMapperRows(mappers) {
        if (!Array.isArray(mappers)) throw new Error('Missing mapper inventory');
        var body = table.tBodies[0];
        var seen = Object.create(null);
        var parents = Object.create(null);
        Array.prototype.forEach.call(body.rows, function (row) {
            if (!row.dataset.wholeDisk && !row.dataset.mapperRow && row.dataset.device) {
                parents[row.dataset.device] = row;
            }
        });
        mappers.forEach(function (mapper) {
            if (!mapper || typeof mapper.path !== 'string' ||
                !/^\/dev\/mapper\/[a-zA-Z0-9._+-]+$/.test(mapper.path) ||
                !Array.isArray(mapper.parents) || mapper.parents.length !== 1 ||
                !/^[a-zA-Z0-9._+-]+$/.test(mapper.parents[0]) ||
                ['type', 'uuid', 'mount'].some(function (key) { return typeof mapper[key] !== 'string'; }) ||
                seen[mapper.path]) throw new Error('Invalid mapper inventory');
            seen[mapper.path] = true;
        });
        Array.prototype.forEach.call(body.querySelectorAll('tr[data-mapper-row]'), function (row) {
            if (!seen[row.dataset.mapperRow]) row.remove();
        });
        mappers.forEach(function (mapper) {
            var parent = parents['/dev/' + mapper.parents[0]];
            if (!parent || parent.dataset.connection !== 'connected') return;
            var row = Array.prototype.filter.call(body.querySelectorAll('tr[data-mapper-row]'), function (item) {
                return item.dataset.mapperRow === mapper.path;
            })[0];
            if (!row) {
                row = document.createElement('tr');
                row.dataset.mapperRow = mapper.path;
                row.dataset.key = 'mapper:' + mapper.path;
                for (var i = 0; i < 7; i++) row.appendChild(document.createElement('td'));
                body.appendChild(row);
            }
            row.dataset.parentDisk = parent.dataset.parentDisk || '';
            row.dataset.mapperParent = parent.dataset.device;
            row.dataset.connection = 'connected';
            var name = mapper.path.substring('/dev/mapper/'.length);
            row.cells[1].textContent = '↳ ' + name;
            row.cells[2].textContent = mapper.path;
            row.cells[3].textContent = mapper.mount ? 'Mounted' : 'Unlocked';
            row.cells[4].textContent = mapper.type || 'Unknown';
            row.cells[5].textContent = mapper.mount || '—';
            row.cells[6].textContent = mapper.uuid || '—';
            row.dataset.device = parent.dataset.device;
            row.dataset.mapper = mapper.path.substring('/dev/mapper/'.length);
            row.dataset.uuid = parent.dataset.uuid || '';
            row.dataset.storeKey = parent.dataset.storeKey;
            row.dataset.keyAvailable = parent.dataset.keyAvailable;
            var actions = row.cells[0];
            ['mount', 'unmount'].forEach(function (action) {
                var button = actions.querySelector('button[data-operation="' + action + '"]');
                // A mounted mapper needs only Unmount; do not show a redundant Mount button.
                if (action === 'mount' && mapper.mount) {
                    if (button) button.remove();
                    return;
                }
                if (!button) {
                    button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.operation = action;
                    button.textContent = action === 'mount' ? 'Mount' : 'Unmount';
                    actions.appendChild(button);
                }
                // Reuse the parent partition's existing state policy.
                var source = parent.querySelector('button[data-operation="' + action + '"]');
                button.disabled = !source || source.disabled || operationBusy;
                button.title = source ? source.title : 'Not available for this disk state';
            });
            row.cells[1].style.paddingLeft = '3rem';
        });
    }
    var polling = false;
    function pollInventory() {
        if (polling || document.hidden || (dialog && dialog.open)) return;
        polling = true;
        fetch('inventory.php', {credentials: 'same-origin', cache: 'no-store'})
            .then(function (response) {
                if (!response.ok) throw new Error('Inventory request failed');
                return response.json();
            })
            .then(function (snapshot) {
                if (dialog && dialog.open) return;
                if (!snapshot || !Array.isArray(snapshot.rows)) throw new Error('Invalid inventory');
                var body = table.tBodies[0];
                var existing = Object.create(null);
                Array.prototype.forEach.call(body.rows, function (row) {
                    if (!row.dataset.wholeDisk && !row.dataset.mapperRow) existing[row.getAttribute('data-key')] = row;
                });
                var seen = Object.create(null);
                // Reject malformed or duplicate keys rather than deleting valid rows.
                snapshot.rows.forEach(function (item) {
                    if (!item || typeof item.key !== 'string' || !item.key ||
                        seen[item.key] || !['connected', 'disconnected'].includes(item.connection) ||
                        typeof item.registered !== 'boolean' ||
                        typeof item.can_delete_registration !== 'boolean' ||
                         typeof item.parent_disk !== 'string' ||
                        ['name', 'device', 'status', 'type', 'uuid', 'mount', 'folder', 'mapper'].some(function (field) {
                            return typeof item[field] !== 'string';
                        }) ||
                        (item.registered && item.key !== 'registered:' + item.uuid.toLowerCase()) ||
                        (!item.registered && item.key.indexOf('device:') !== 0) ||
                        (!item.registered && item.connection !== 'connected') ||
                        !item.actions || typeof item.actions !== 'object' ||
                        ['unlock', 'mount', 'unmount', 'lock', 'encrypt'].some(function (action) {
                            return typeof item.actions[action] !== 'boolean';
                        })) {
                        throw new Error('Ambiguous inventory');
                    }
                    seen[item.key] = true;
                });
                snapshot.rows.forEach(function (item) {
                    var row = existing[item.key];
                    if (!row) {
                        row = document.createElement('tr');
                        row.setAttribute('data-key', item.key);
                        for (var i = 0; i < 7; i++) row.appendChild(document.createElement('td'));
                        row.cells[0].className = 'disk-actions';
                        ['Unlock', 'Mount', 'Unmount', 'Lock'].forEach(function (action) {
                            var button = document.createElement('button');
                            button.type = 'button';
                            button.disabled = true;
                            button.dataset.operation = action.toLowerCase().split(' ')[0];
                            button.title = action + ': not available for this disk state';
                            button.textContent = action;
                            row.cells[0].appendChild(button);
                        });
                        body.appendChild(row);
                    }
                    updateRow(row, item);
                });
                Object.keys(existing).forEach(function (key) {
                    if (!seen[key]) existing[key].remove();
                });
                reconcileWholeDisks(snapshot);
                updateMapperRows(snapshot.mappers);
                sortRows();
                updateCounts();
                selectConnection(currentConnection(), false);
                pollNotice.textContent = '';
            })
            .catch(function () {
                pollNotice.textContent = 'Live inventory unavailable; displayed disk status may be stale.';
                // Do not allow actions based on a stale disk state.
                Array.prototype.forEach.call(table.querySelectorAll('.configure-disk'), function (button) {
                    button.dataset.canDelete = '0';
                });
                Array.prototype.forEach.call(table.querySelectorAll('button[data-operation], .whole-disk-preview-button'), function (button) {
                    button.disabled = true;
                    button.title = 'Disk state unavailable; wait for inventory to refresh';
                });
            })
            .then(function () { polling = false; });
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) pollInventory();
    });
    window.setInterval(pollInventory, 3000);
    var active = 1, ascending = true;
    // Group server-rendered physical disks with their partitions immediately.
    sortRows();
    // Refresh immediately: the server-rendered snapshot may already be stale.
    pollInventory();

    var buttons = Array.prototype.slice.call(table.querySelectorAll('.sort-heading'));

    buttons.forEach(function (button, column) {
        var label = button.textContent.replace(/[ ▲▼]$/, '').trim();
        button.addEventListener('click', function () {
            ascending = active === column ? !ascending : true;
            active = column;
            sortRows();
            buttons.forEach(function (item, index) {
                item.textContent = item.textContent.replace(/ [▲▼]$/, '');
                item.removeAttribute('aria-sort');
                if (index === column) {
                    item.textContent = label + (ascending ? ' ▲' : ' ▼');
                    item.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                }
            });
        });
    });
}());
</script>
</body>
</html>
