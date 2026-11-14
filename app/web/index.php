<?php
require_once __DIR__ . '/../lib/auth.php';
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
        <h1>Encrypted Disk Manager</h1><p><?php echo $setupMode ? 'Enter the one-time setup code from the NAS, then create the administrator password.' : 'Sign in to manage encrypted disks.'; ?></p>
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
require_once __DIR__ . '/../lib/whole_usb_disk_preflight.php';
$wholeDiskInventory = edm_discover_whole_usb_disks();
$devices = $discovery['devices'];
$mapperDevices = $discovery['mapperDevices'];
$sysEntries = $discovery['sysEntries'];

// Validate authenticated configuration submissions without performing mutations.
// The live privileged verifier and keyfile retirement are not yet integrated.
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
            $validated = edm_configuration_request($request);
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
    .inventory-tabs { display: flex; gap: 8px; margin: 16px 0 10px; border-bottom: 1px solid #d9dfe2; }
    .inventory-tab { border: 1px solid transparent; border-bottom: 0; border-radius: 6px 6px 0 0; padding: 9px 15px; background: transparent; color: #075f91; font: inherit; cursor: pointer; }
    .inventory-tab[aria-selected="true"] { background: #f3f7f9; border-color: #d9dfe2; color: #20272c; font-weight: bold; }
    .inventory-tab:focus-visible { outline: 2px solid #075f91; outline-offset: -2px; }
    #disk-inventory tr[hidden] { display: none; }
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
        <h1>Encrypted Disk Manager</h1>
        <div class="muted">USB storage · LUKS inventory</div>
    </div>
    <div class="header-actions">
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
    <section class="panel safe-note">
        <strong>Before uninstalling or reinstalling:</strong>
        download a configuration backup from the Backup section on the Settings page.
        Normal upgrades and ordinary app removal preserve the administrator account
        and registered-disk configuration in WD's persistent app-data directory.
        Use Settings to restore a .tgz backup or explicitly delete saved configuration.
        Backups contain a password hash and disk settings, never plaintext passwords or LUKS passphrases.
    </section>
    <section class="panel">
        <h2>Disk inventory</h2>
        <?php if ($configValidationNotice !== ''): ?><p role="status"><?php echo h($configValidationNotice); ?></p><?php endif; ?>
        <?php if ($configValidationError !== ''): ?><p role="alert"><?php echo h($configValidationError); ?></p><?php endif; ?>
        <p class="muted">Registered disks remain listed when disconnected. Disk actions are disabled until the privileged operations are implemented and verified.</p>
        <?php if ($inventoryError !== ''): ?>
            <p class="error"><?php echo h($inventoryError); ?></p>
        <?php endif; ?>
        <?php if ($inventoryError === ''): ?>
        <?php
            $connectedCount = 0;
            $disconnectedCount = 0;
            foreach ($inventoryRows as $inventoryRow) {
                if ($inventoryRow['device'] === null) {
                    $disconnectedCount++;
                } else {
                    $connectedCount++;
                }
            }
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
                <th><button type="button" class="sort-heading" data-column="0" aria-sort="ascending">Name ▲</button></th>
                <th><button type="button" class="sort-heading" data-column="1">Device</button></th>
                <th><button type="button" class="sort-heading" data-column="2">Status</button></th>
                <th><button type="button" class="sort-heading" data-column="3">Filesystem / type</button></th>
                <th><button type="button" class="sort-heading" data-column="4">LUKS UUID</button></th>
                <th><button type="button" class="sort-heading" data-column="5">Mount directory</button></th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($inventoryRows as $row):
                $disk = $row['device'];
                $status = $row['status'];
                $mountFolder = $row['registered'] ? '/mnt/' . $row['mount_folder'] : '—';
            ?>
                <tr data-key="<?php echo h($row['registered'] ? 'registered:' . strtolower($row['luks_uuid']) : 'device:' . $disk['name']); ?>" data-connection="<?php echo $disk === null ? 'disconnected' : 'connected'; ?>"<?php echo $disk === null ? ' hidden' : ''; ?>>
                    <td><?php echo h($row['name']); ?><?php if (!$row['registered']): ?> <small>(unregistered)</small><?php endif; ?></td>
                    <td><code><?php echo h($disk === null ? '—' : $disk['path']); ?></code></td>
                    <td><?php echo h($status); ?></td>
                    <td><?php echo h($disk === null ? '—' : ($disk['type'] ?: 'Unknown')); ?></td>
                    <td><code><?php echo h($row['luks_uuid'] ?: '—'); ?></code></td>
                    <td><?php echo h($mountFolder); ?></td>
                    <td class="disk-actions">
                        <?php if ($row['registered']): ?>
                            <button type="button" class="configure-disk"
                                data-uuid="<?php echo h($row['luks_uuid']); ?>"
                                data-name="<?php echo h($row['name']); ?>"
                                data-folder="<?php echo h($row['mount_folder']); ?>"
                                data-status="<?php echo h($row['status']); ?>"
                                title="View registered disk configuration">Configure</button>
                        <?php endif; ?>
                        <?php foreach (array('Unlock', 'Mount', 'Unmount', 'Lock', 'Encrypt + ext4') as $action): ?>
                            <button type="button" disabled title="<?php echo h($action . ': not yet available in this development build'); ?>"><?php echo h($action); ?></button>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <section aria-labelledby="whole-usb-disks-title">
        <h2 id="whole-usb-disks-title">Whole USB disks</h2>
        <p class="muted">Read-only parent-disk inventory. Encryption and repartitioning are not yet available.</p>
        <?php if (!$wholeDiskInventory['complete']): ?>
            <p class="muted">Whole-disk discovery is temporarily unavailable.</p>
        <?php elseif (!$wholeDiskInventory['disks']): ?>
            <p class="muted">No whole USB disks detected.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Disk</th><th>Size</th><th>Partitions</th><th>State</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($wholeDiskInventory['disks'] as $wholeDisk): ?>
                    <?php $wholeDiskPreflight = edm_whole_usb_disk_preflight($wholeDiskInventory, $wholeDisk['name']); ?>
                    <tr>
                        <td><?php echo h('/dev/' . $wholeDisk['name']); ?></td>
                        <td><?php echo h(number_format($wholeDisk['size_bytes'] / 1073741824, 2) . ' GiB'); ?></td>
                        <td><?php echo h(implode(', ', $wholeDisk['partitions'])); ?></td>
                        <td><?php echo h($wholeDisk['mounted'] ? 'In use' : (($wholeDisk['has_holders'] || $wholeDisk['has_slaves']) ? 'Has device dependencies' : ($wholeDisk['read_only'] ? 'Read-only' : ($wholeDiskPreflight ? 'Preliminary checks passed (encryption disabled)' : 'Preliminary checks incomplete')))); ?></td>
                        <td><button type="button" disabled title="Whole-disk encryption is not yet available">Encrypt + ext4</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <dialog id="whole-disk-erase-preview" aria-labelledby="whole-disk-erase-title">
        <h2 id="whole-disk-erase-title">Whole-disk erase confirmation preview</h2>
        <p><strong>Warning:</strong> Encrypting a whole USB disk will erase and repartition
            the entire selected disk, permanently destroying all existing data.</p>
        <label><input type="checkbox" disabled> I understand that the entire disk will be erased.</label>
        <p>Are you sure? Your data will be permanently lost!</p>
        <label for="whole-disk-erase-phrase">Final confirmation phrase</label>
        <input id="whole-disk-erase-phrase" type="text" placeholder="ERASE ALL DATA" disabled>
        <p class="muted">This is a non-operational preview. No disk is selected,
            and no encryption request can be submitted.</p>
        <button type="button" disabled title="Encryption is not available">Confirm erase (disabled)</button>
        <form method="dialog"><button type="submit">Close preview</button></form>
    </dialog>
    <dialog id="disk-config-dialog" aria-labelledby="disk-config-title">
        <h2 id="disk-config-title">Disk configuration</h2>
        <p class="muted">Configuration preview. Editing, key management and registration deletion are not yet enabled.</p>
        <p><strong>LUKS UUID:</strong> <code id="config-uuid"></code></p>
        <p><strong>Connection:</strong> <span id="config-status"></span></p>
        <form method="post" id="disk-config-form">
        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="config_action" value="edit">
        <input type="hidden" name="luks_uuid" id="config-form-uuid">
        <label for="config-name">Registered name</label>
        <input id="config-name" name="name" type="text" maxlength="160" required>
        <label for="config-folder">Unique mount directory under /mnt</label>
        <input id="config-folder" name="mount_folder" type="text" maxlength="48" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,47}" required>
        <label><input type="checkbox" disabled> Store an unlock key on this NAS (optional)</label>
        <p class="muted">Key storage is not configured in this development build.</p>
        <div class="dialog-actions">
            <button type="submit">Validate changes (no save)</button>
            <button type="button" disabled>Delete registration</button>
            <button type="button" id="close-disk-config">Close</button>
        </div>
        </form>
    </dialog>
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
<script>
(function () {
    'use strict';
    var dialog = document.getElementById('disk-config-dialog');
    if (dialog && typeof dialog.showModal === 'function') {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('.configure-disk');
            if (!button || !document.getElementById('disk-inventory').contains(button)) return;
            document.getElementById('config-uuid').textContent = button.getAttribute('data-uuid');
            document.getElementById('config-form-uuid').value = button.getAttribute('data-uuid');
            document.getElementById('config-status').textContent = button.getAttribute('data-status');
            document.getElementById('config-name').value = button.getAttribute('data-name');
            document.getElementById('config-folder').value = button.getAttribute('data-folder');
            dialog.showModal();
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
            if (!row.hidden) visible++;
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
            var count = table.tBodies[0].querySelectorAll('tr[data-connection="' + connection + '"]').length;
            tabs.forEach(function (tab) {
                if (tab.getAttribute('data-filter') === connection) {
                    tab.textContent = (connection === 'connected' ? 'Connected' : 'Disconnected') + ' (' + count + ')';
                }
            });
        });
    }
    function updateRow(row, item) {
        row.setAttribute('data-connection', item.connection);
        var values = [item.name, item.device, item.status, item.type, item.uuid, item.mount];
        values.forEach(function (value, index) {
            var cell = row.cells[index];
            if (index === 0) {
                var text = item.name + (item.registered ? '' : ' (unregistered)');
                if (cell.textContent !== text) cell.textContent = text;
            } else if (cell.textContent !== value) {
                cell.textContent = value;
            }
        });
        var actionCell = row.cells[6];
        // Preview eligibility without enabling any disk-changing operation.
        ['unlock', 'mount', 'unmount', 'lock', 'encrypt'].forEach(function (action, index) {
            var button = Array.prototype.filter.call(actionCell.querySelectorAll('button'), function (candidate) {
                return !candidate.classList.contains('configure-disk');
            })[index];
            if (!button) return;
            button.disabled = true;
            button.title = item.actions[action]
                ? action.charAt(0).toUpperCase() + action.slice(1) + ': eligible in current read-only snapshot; operation not yet available'
                : action.charAt(0).toUpperCase() + action.slice(1) + ': not available for this disk state';
        });
        var configure = actionCell.querySelector('.configure-disk');
        if (item.registered && !configure) {
            configure = document.createElement('button');
            configure.type = 'button';
            configure.className = 'configure-disk';
            configure.textContent = 'Configure';
            actionCell.insertBefore(configure, actionCell.firstChild);
        } else if (!item.registered && configure) {
            configure.remove();
            configure = null;
        }
        if (configure) {
            configure.setAttribute('data-uuid', item.uuid);
            configure.setAttribute('data-name', item.name);
            configure.setAttribute('data-folder', item.folder);
            configure.setAttribute('data-status', item.status);
        }
    }
    function sortRows() {
        var body = table.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        rows.sort(function (a, b) {
            var x = a.cells[active].textContent.trim();
            var y = b.cells[active].textContent.trim();
            var cmp = x.localeCompare(y, undefined, {sensitivity: 'base', numeric: true});
            return ascending ? cmp : -cmp;
        });
        rows.forEach(function (row, index) {
            if (body.rows[index] !== row) body.insertBefore(row, body.rows[index] || null);
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
                    existing[row.getAttribute('data-key')] = row;
                });
                var seen = Object.create(null);
                // Reject malformed or duplicate keys rather than deleting valid rows.
                snapshot.rows.forEach(function (item) {
                    if (!item || typeof item.key !== 'string' || !item.key ||
                        seen[item.key] || !['connected', 'disconnected'].includes(item.connection) ||
                        typeof item.registered !== 'boolean' ||
                        ['name', 'device', 'status', 'type', 'uuid', 'mount', 'folder'].some(function (field) {
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
                        row.cells[6].className = 'disk-actions';
                        ['Unlock', 'Mount', 'Unmount', 'Lock', 'Encrypt + ext4'].forEach(function (action) {
                            var button = document.createElement('button');
                            button.type = 'button';
                            button.disabled = true;
                            button.title = action + ': not yet available in this development build';
                            button.textContent = action;
                            row.cells[6].appendChild(button);
                        });
                        body.appendChild(row);
                    }
                    updateRow(row, item);
                });
                Object.keys(existing).forEach(function (key) {
                    if (!seen[key]) existing[key].remove();
                });
                sortRows();
                updateCounts();
                selectConnection(currentConnection(), false);
                pollNotice.textContent = '';
            })
            .catch(function () {
                pollNotice.textContent = 'Live inventory unavailable; displayed disk status may be stale.';
            })
            .then(function () { polling = false; });
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) pollInventory();
    });
    window.setInterval(pollInventory, 3000);
    // Refresh immediately: the server-rendered snapshot may already be stale.
    pollInventory();

    var buttons = Array.prototype.slice.call(table.querySelectorAll('.sort-heading'));
    var active = 0, ascending = true;
    buttons.forEach(function (button, column) {
        var label = button.textContent.replace(/[ ▲▼]$/, '').trim();
        button.addEventListener('click', function () {
            ascending = active === column ? !ascending : true;
            active = column;
            var body = table.tBodies[0];
            var rows = Array.prototype.slice.call(body.rows);
            rows.sort(function (a, b) {
                var x = a.cells[column].textContent.trim();
                var y = b.cells[column].textContent.trim();
                var cmp = x.localeCompare(y, undefined, {sensitivity: 'base', numeric: true});
                return ascending ? cmp : -cmp;
            });
            rows.forEach(function (row, index) {
                if (body.rows[index] !== row) body.insertBefore(row, body.rows[index] || null);
            });
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
