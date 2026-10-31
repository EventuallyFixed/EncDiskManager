<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/backup.php';

function edm_settings_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';
$admin = null;
$setupToken = null;

try {
    edm_auth_start_session();
    $admin = edm_auth_read_admin();
    if ($admin === null) {
        $setupToken = edm_auth_read_setup_token();
    } else {
        edm_auth_require_login();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!edm_auth_is_https()) {
            throw new RuntimeException('HTTPS is required for configuration restore and deletion.');
        }
        $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        if (!hash_equals((string)$_SESSION['csrf_token'], $postedCsrf)) {
            http_response_code(403);
            throw new RuntimeException('Your security token expired. Reload this page and try again.');
        }

        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'restore') {
            if (!isset($_FILES['backup']) || !is_array($_FILES['backup'])
                || $_FILES['backup']['error'] !== UPLOAD_ERR_OK
                || !is_uploaded_file($_FILES['backup']['tmp_name'])) {
                throw new RuntimeException('Choose a valid .tgz backup file to restore.');
            }
            if ($_FILES['backup']['size'] < 20 || $_FILES['backup']['size'] > 2097152) {
                throw new RuntimeException('The backup file must be no larger than 2 MiB.');
            }
            $compressed = file_get_contents($_FILES['backup']['tmp_name']);
            if ($compressed === false) {
                throw new RuntimeException('The uploaded backup could not be read.');
            }

            if ($admin === null) {
                $providedToken = isset($_POST['setup_token']) ? (string)$_POST['setup_token'] : '';
                if ($setupToken === null || !hash_equals($setupToken, $providedToken)) {
                    throw new RuntimeException('The one-time setup code is missing or incorrect.');
                }
                edm_backup_restore_archive($compressed, false);
            } else {
                if (!isset($_POST['replace_confirmation'])
                    || $_POST['replace_confirmation'] !== 'REPLACE CONFIGURATION') {
                    throw new RuntimeException('Type REPLACE CONFIGURATION to confirm replacing the current administrator and disk registry.');
                }
                edm_backup_restore_archive($compressed, true);
            }

            // A successful restore replaces the administrator hash. End this
            // session and require sign-in with the credentials in the archive.
            edm_auth_logout();
            header('Location: index.php?restored=1');
            exit;
        } elseif ($action === 'delete' && $admin !== null) {
            if (!isset($_POST['delete_confirmation'])
                || $_POST['delete_confirmation'] !== 'DELETE SAVED CONFIGURATION') {
                throw new RuntimeException('Type DELETE SAVED CONFIGURATION to confirm permanent deletion.');
            }
            edm_backup_delete_configuration();
            edm_auth_logout();
            header('Location: index.php?configuration_deleted=1');
            exit;
        } else {
            http_response_code(400);
            throw new RuntimeException('That configuration action is not available.');
        }
    }
} catch (Throwable $exception) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    error_log('EncDiskManager settings error: ' . $exception->getMessage());
    $error = $exception->getMessage();
}

$csrf = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
$isSetup = ($admin === null);
$https = edm_auth_is_https();
$setupTokenPath = $isSetup ? edm_auth_data_path() . '/setup-token' : '';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuration · Encrypted Disk Manager</title>
<style>
body{margin:0;background:#f3f5f6;color:#20272c;font:16px/1.5 Arial,Helvetica,sans-serif}
header{background:#fff;border-bottom:1px solid #d9dfe2;padding:18px 24px}
main{max-width:780px;margin:24px auto;padding:0 18px}
.panel{background:#fff;border:1px solid #d9dfe2;border-radius:8px;padding:20px;margin-bottom:18px}
h1{font-size:1.4rem;margin:0}h2{font-size:1.15rem;margin:0 0 8px}
p{color:#58656d}.warning{padding:11px;background:#fff8e7;border-left:4px solid #b7791f;color:#704b0b}
.error{padding:11px;background:#fff0ed;border-left:4px solid #b23b2b;color:#842719}
label{display:block;font-weight:bold;margin:14px 0 5px}
input[type=file],input[type=text],input[type=password]{box-sizing:border-box;width:100%;padding:10px;border:1px solid #aebac1;border-radius:4px;font:inherit}
button{margin-top:16px;padding:10px 15px;border:0;border-radius:5px;background:#075f91;color:white;font:inherit;cursor:pointer}
.danger button{background:#9b2c2c}a{color:#075f91}
.small{font-size:.92rem}
</style>
</head>
<body>
<header><h1>Encrypted Disk Manager · Configuration</h1></header>
<main>
<p><a href="index.php">← Return to Encrypted Disk Manager</a></p>
<?php if ($error !== ''): ?><p class="error"><?php echo edm_settings_h($error); ?></p><?php endif; ?>
<?php if (!$https): ?>
<section class="panel warning">Configuration restore and deletion are disabled over HTTP. Open this page using the NAS HTTPS address.</section>
<?php else: ?>
<section class="panel">
<h2><?php echo $isSetup ? 'Restore an existing backup' : 'Restore configuration'; ?></h2>
<p>A backup contains the administrator password hash and registered-disk settings. It does not contain plaintext passwords, LUKS passphrases or disk contents. Treat the file as sensitive.</p>
<?php if ($isSetup): ?>
<p class="warning">No administrator is configured. Enter the one-time setup code from the NAS to restore a backup. Retrieve the code over SSH from <code><?php echo edm_settings_h($setupTokenPath); ?></code>. After restore, sign in using the administrator password contained in that backup.</p>
<?php else: ?>
<p class="warning">Restoring replaces the current administrator account and the entire registered-disk list. The current configuration will be replaced only after the uploaded archive passes validation.</p>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?php echo edm_settings_h($csrf); ?>">
<input type="hidden" name="action" value="restore">
<?php if ($isSetup): ?>
<label for="setup-token">One-time setup code</label>
<input id="setup-token" name="setup_token" type="password" required autocomplete="off">
<?php else: ?>
<label for="replace-confirmation">Type REPLACE CONFIGURATION to confirm</label>
<input id="replace-confirmation" name="replace_confirmation" type="text" required autocomplete="off">
<?php endif; ?>
<label for="backup">Backup archive (.tgz)</label>
<input id="backup" name="backup" type="file" accept=".tgz,.tar.gz,application/gzip" required>
<p class="small">Maximum upload size: 2 MiB. The archive must contain exactly the three expected regular files; links, duplicate paths and extra entries are rejected.</p>
<button type="submit">Validate and restore backup</button>
</form>
</section>
<?php if (!$isSetup): ?>
<section class="panel danger">
<h2>Permanently delete saved configuration</h2>
<p>This removes the saved administrator credentials, registered-disk list and local authentication state from the persistent data directory. It does not remove the application or alter any disks. A new one-time setup code will be generated.</p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo edm_settings_h($csrf); ?>">
<input type="hidden" name="action" value="delete">
<label for="delete-confirmation">Type DELETE SAVED CONFIGURATION to confirm</label>
<input id="delete-confirmation" name="delete_confirmation" type="text" required autocomplete="off">
<button type="submit">Permanently delete saved configuration</button>
</form>
</section>
<?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
