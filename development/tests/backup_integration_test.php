<?php
/*
 * Integration tests for EncDiskManager backup creation and restore.
 *
 * Run as root with PHP 7.4+, /bin/tar, exec enabled and the sync utility:
 *   php development/tests/backup_integration_test.php
 *
 * Copies the PHP libraries into an isolated temporary application tree and
 * creates its own private data directory. It never uses the installed app's
 * persistent data directory and never accesses disks, LUKS, or NAS services.
 */

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Tests require root for private configuration file checks.\n");
    exit(2);
}
if (!is_executable('/bin/tar')) {
    fwrite(STDERR, "Tests require /bin/tar.\n");
    exit(2);
}
if (!function_exists('exec')) {
    fwrite(STDERR, "Tests require PHP exec() so restore durability barriers can be exercised.\n");
    exit(2);
}
$syncOutput = array();
$syncStatus = 1;
exec('command -v sync', $syncOutput, $syncStatus);
if ($syncStatus !== 0 || count($syncOutput) === 0) {
    fwrite(STDERR, "Tests require the sync utility.\n");
    exit(2);
}

function edm_backup_integration_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$base = rtrim(sys_get_temp_dir(), '/') . '/edm-backup-integration-' . bin2hex(random_bytes(8));
$app = $base . '/EncDiskManager';
$lib = $app . '/lib';
$data = $base . '/_EncDiskManager';

if (!mkdir($base, 0700) || !mkdir($app, 0700) || !mkdir($lib, 0700)
    || !mkdir($data, 0700)) {
    fwrite(STDERR, "Could not create isolated integration-test directories.\n");
    exit(2);
}

$passed = 0;

// Test-only fault injection for the isolated backup.php copy.
$GLOBALS['edm_backup_test_failures'] = array();
function edm_backup_test_rename($from, $to)
{
    $failures = &$GLOBALS['edm_backup_test_failures']['rename'];
    if (is_array($failures)) {
        foreach ($failures as &$failure) {
            if (is_array($failure)
                && (!isset($failure['from']) || $failure['from'] === $from)
                && (!isset($failure['from_basename']) || basename($from) === $failure['from_basename'])
                && (!isset($failure['to']) || $failure['to'] === $to)
                && $failure['remaining'] > 0) {
                $failure['remaining']--;
                unset($failure);
                return false;
            }
        }
        unset($failure);
    }
    return rename($from, $to);
}
function edm_backup_test_unlink($path)
{
    $failure = &$GLOBALS['edm_backup_test_failures']['unlink'];
    if (is_array($failure) && $failure['path'] === $path && $failure['remaining'] > 0) {
        $failure['remaining']--;
        return false;
    }
    return unlink($path);
}

try {
    foreach (array('auth.php', 'registry.php') as $file) {
        if (!copy(__DIR__ . '/../../lib/' . $file, $lib . '/' . $file)) {
            throw new RuntimeException('Could not stage library file: ' . $file);
        }
    }

    // Route filesystem mutations in this disposable copy of backup.php through
    // wrappers so failure paths can be tested deterministically. Production
    // source files and the real filesystem functions remain unchanged.
    $backupSource = file_get_contents(__DIR__ . '/../../lib/backup.php');
    if (!is_string($backupSource)) {
        throw new RuntimeException('Could not read backup library for isolated test staging.');
    }
    $backupSource = str_replace(array('rename(', 'unlink('),
        array('edm_backup_test_rename(', 'edm_backup_test_unlink('), $backupSource);
    if (file_put_contents($lib . '/backup.php', $backupSource) !== strlen($backupSource)) {
        throw new RuntimeException('Could not stage instrumented backup library.');
    }

    require_once $lib . '/backup.php';

    $uuid = 'a1b2c3d4-1234-5678-9012-abcdef123456';
    $originalRegistry = array(
        'version' => 1,
        'disks' => array(array(
            'luks_uuid' => $uuid,
            'name' => 'Integration Test Disk',
            'mount_folder' => 'Integration-Test',
            'mapper_name' => edm_registry_mapper_name($uuid),
            'enabled' => true
        ))
    );
    $originalAdmin = array(
        'password_hash' => password_hash('integration-original-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );

    edm_backup_write_private_file($data . '/admin.json', json_encode($originalAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $originalRegistry);
    edm_auth_rotate_session_epoch();
    $_SESSION = array(
        'authenticated' => true,
        'auth_epoch' => trim(file_get_contents($data . '/auth-epoch'))
    );

    // Exercise the real create path, including its locks and self-validation.
    $archive = edm_backup_create_archive();
    $payload = edm_backup_read_archive($archive);
    edm_backup_integration_assert($payload['admin']['password_hash'] === $originalAdmin['password_hash'],
        'created backup contains the current administrator credentials');
    edm_backup_integration_assert($payload['registry'] === $originalRegistry,
        'created backup contains the current disk registry');
    $passed += 2;

    // Change both live files so restore must replace real, differing content.
    $changedAdmin = array(
        'password_hash' => password_hash('integration-replacement-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $changedRegistry = array('version' => 1, 'disks' => array());
    edm_backup_write_private_file($data . '/admin.json', json_encode($changedAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $changedRegistry);

    $oldEpoch = trim(file_get_contents($data . '/auth-epoch'));
    // These are disposable fixture files; restore should remove them.
    edm_backup_write_private_file($data . '/setup-token', bin2hex(random_bytes(32)) . "\n");
    edm_backup_write_private_file($data . '/login-attempts.json', "{}\n");

    edm_backup_restore_archive($archive, true);

    $restoredAdmin = edm_auth_read_admin();
    $restoredRegistry = edm_registry_load($data . '/disks.json');
    edm_backup_integration_assert($restoredAdmin['password_hash'] === $originalAdmin['password_hash'],
        'replacement restore reinstates the backed-up administrator credentials');
    edm_backup_integration_assert($restoredRegistry === $originalRegistry,
        'replacement restore reinstates the backed-up disk registry');
    $passed += 2;

    $newEpoch = trim(file_get_contents($data . '/auth-epoch'));
    edm_backup_integration_assert($newEpoch !== '' && $newEpoch !== $oldEpoch,
        'replacement restore rotates the authentication session epoch');
    // Model a fresh login after restore invalidates the previous browser session.
    $_SESSION['auth_epoch'] = $newEpoch;
    edm_backup_integration_assert(!file_exists($data . '/setup-token')
        && !file_exists($data . '/login-attempts.json'),
        'replacement restore removes obsolete setup and login-attempt files');
    $passed += 2;

    // Unsafe ephemeral entries must be rejected before replacing configuration
    // or rotating the authentication epoch. The symlink is confined to this
    // disposable test data directory.
    $preflightAdmin = edm_auth_read_admin();
    $preflightRegistry = edm_registry_load($data . '/disks.json');
    $preflightEpoch = trim(file_get_contents($data . '/auth-epoch'));
    if (!symlink($base . '/missing-setup-token-target', $data . '/setup-token')) {
        throw new RuntimeException('Could not prepare the isolated preflight-failure fixture.');
    }
    $preflightFailureMessage = null;
    try {
        edm_backup_restore_archive($archive, true);
    } catch (Throwable $exception) {
        $preflightFailureMessage = $exception->getMessage();
    }
    edm_backup_integration_assert($preflightFailureMessage ===
        'Unexpected symbolic link in private configuration directory.',
        'restore rejects an unsafe setup-token symlink during preflight');
    $passed++;
    edm_backup_integration_assert(
        edm_auth_read_admin()['password_hash'] === $preflightAdmin['password_hash']
            && edm_registry_load($data . '/disks.json') === $preflightRegistry,
        'preflight rejection leaves administrator and registry unchanged');
    $passed++;
    edm_backup_integration_assert(trim(file_get_contents($data . '/auth-epoch')) === $preflightEpoch,
        'preflight rejection leaves the authentication session epoch unchanged');
    $passed++;
    unlink($data . '/setup-token');

    // Also exercise first-time restore, where no administrator exists yet.
    unlink($data . '/admin.json');
    unlink($data . '/disks.json');
    edm_backup_restore_archive($archive, false);
    $firstInstallAdmin = edm_auth_read_admin();
    $firstInstallRegistry = edm_registry_load($data . '/disks.json');
    edm_backup_integration_assert($firstInstallAdmin['password_hash'] === $originalAdmin['password_hash'],
        'first-time restore installs the backed-up administrator credentials');
    edm_backup_integration_assert($firstInstallRegistry === $originalRegistry,
        'first-time restore installs the backed-up disk registry');
    $passed += 2;

    // Force a failure after both restored files have been installed: the
    // authentication epoch lock is checked only when restore rotates the
    // epoch, which happens after the two configuration files are committed.
    // The symlink is confined to this disposable test data directory.
    $_SESSION['auth_epoch'] = trim(file_get_contents($data . '/auth-epoch'));
    $rollbackAdmin = array(
        'password_hash' => password_hash('integration-rollback-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $rollbackRegistry = array('version' => 1, 'disks' => array());
    edm_backup_write_private_file($data . '/admin.json', json_encode($rollbackAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $rollbackRegistry);
    unlink($data . '/auth-epoch.lock');
    if (!symlink($base . '/missing-lock-target', $data . '/auth-epoch.lock')) {
        throw new RuntimeException('Could not prepare the isolated restore-failure fixture.');
    }

    $restoreFailureMessage = null;
    try {
        edm_backup_restore_archive($archive, true);
    } catch (Throwable $exception) {
        $restoreFailureMessage = $exception->getMessage();
    }
    edm_backup_integration_assert(
        is_string($restoreFailureMessage)
            && strpos($restoreFailureMessage, 'Authentication epoch lock must not be a symbolic link') !== false,
        'restore reports the deliberately induced post-install epoch-lock failure'
            . ($restoreFailureMessage === null ? ' (restore unexpectedly returned successfully)'
                : ' (actual exception: ' . $restoreFailureMessage . ')')
    );
    $passed++;

    $afterRollbackAdmin = edm_auth_read_admin();
    $afterRollbackRegistry = edm_registry_load($data . '/disks.json');
    edm_backup_integration_assert($afterRollbackAdmin['password_hash'] === $rollbackAdmin['password_hash'],
        'rollback restores the previous administrator file after a post-install failure');
    edm_backup_integration_assert($afterRollbackRegistry === $rollbackRegistry,
        'rollback restores the previous registry file after a post-install failure');
    $passed += 2;

    unlink($data . '/auth-epoch.lock');

    // Fail specifically when the restored registry is installed. The
    // one-shot failure allows the normal rollback renames to succeed.
    $_SESSION['auth_epoch'] = trim(file_get_contents($data . '/auth-epoch'));
    $installFailureAdmin = array(
        'password_hash' => password_hash('integration-install-failure-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $installFailureRegistry = array('version' => 1, 'disks' => array());
    edm_backup_write_private_file($data . '/admin.json', json_encode($installFailureAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $installFailureRegistry);
    $GLOBALS['edm_backup_test_failures']['rename'] = array(array(
        'to' => $data . '/disks.json',
        'remaining' => 1
    ));
    $installFailureMessage = null;
    try {
        edm_backup_restore_archive($archive, true);
    } catch (Throwable $exception) {
        $installFailureMessage = $exception->getMessage();
    }
    $GLOBALS['edm_backup_test_failures'] = array();
    edm_backup_integration_assert($installFailureMessage === 'Could not install restored disk registry.',
        'restore reports a deliberately induced registry-install failure');
    $passed++;
    edm_backup_integration_assert(
        edm_auth_read_admin()['password_hash'] === $installFailureAdmin['password_hash']
            && edm_registry_load($data . '/disks.json') === $installFailureRegistry,
        'rollback restores both original files after registry installation fails');
    $passed++;

    // Simulate a reboot after both new files were installed but before the
    // transaction was committed. Recovery must reinstate both previous files.
    $_SESSION['auth_epoch'] = trim(file_get_contents($data . '/auth-epoch'));
    $crashOldAdmin = array(
        'password_hash' => password_hash('crash-old-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $crashOldRegistry = array('version' => 1, 'disks' => array());
    $crashNewAdmin = array(
        'password_hash' => password_hash('crash-new-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $crashNewRegistry = $originalRegistry;
    edm_backup_write_private_file($data . '/admin.json', json_encode($crashNewAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $crashNewRegistry);
    $crashStage = $data . '/.restore-' . bin2hex(random_bytes(8));
    if (!mkdir($crashStage, 0700) || !chown($crashStage, 0) || !chgrp($crashStage, 0)) {
        throw new RuntimeException('Could not create simulated interrupted restore directory.');
    }
    edm_backup_write_private_file($crashStage . '/previous-admin.json',
        json_encode($crashOldAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_private_file($crashStage . '/previous-disks.json',
        json_encode($crashOldRegistry, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_transaction_state($crashStage, array(
        'version' => 1, 'state' => 'active',
        'admin_existed' => true, 'registry_existed' => true
    ));
    edm_backup_recover_interrupted_restores();
    edm_backup_integration_assert(
        edm_auth_read_admin()['password_hash'] === $crashOldAdmin['password_hash']
            && edm_registry_load($data . '/disks.json') === $crashOldRegistry,
        'startup recovery restores both previous files after an interrupted replacement');
    $passed++;
    edm_backup_integration_assert(!file_exists($crashStage),
        'startup recovery removes the completed rollback staging directory');
    $passed++;

    // A committed journal means both replacements and the new session epoch
    // were flushed; recovery must retain the new live configuration.
    $committedAdmin = array(
        'password_hash' => password_hash('crash-committed-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $committedRegistry = $originalRegistry;
    edm_backup_write_private_file($data . '/admin.json', json_encode($committedAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $committedRegistry);
    $committedStage = $data . '/.restore-' . bin2hex(random_bytes(8));
    if (!mkdir($committedStage, 0700) || !chown($committedStage, 0) || !chgrp($committedStage, 0)) {
        throw new RuntimeException('Could not create simulated committed restore directory.');
    }
    edm_backup_write_private_file($committedStage . '/previous-admin.json',
        json_encode($crashOldAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_private_file($committedStage . '/previous-disks.json',
        json_encode($crashOldRegistry, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_transaction_state($committedStage, array(
        'version' => 1, 'state' => 'committed',
        'admin_existed' => true, 'registry_existed' => true
    ));
    edm_backup_recover_interrupted_restores();
    edm_backup_integration_assert(
        edm_auth_read_admin()['password_hash'] === $committedAdmin['password_hash']
            && edm_registry_load($data . '/disks.json') === $committedRegistry,
        'startup recovery preserves configuration from a committed restore');
    $passed++;
    edm_backup_integration_assert(!file_exists($committedStage),
        'startup recovery cleans a committed restore staging directory');
    $passed++;

    // A first-time restore has no previous files. An interrupted transaction
    // must remove any partially installed first-time configuration.
    unlink($data . '/admin.json');
    unlink($data . '/disks.json');
    $firstCrashStage = $data . '/.restore-' . bin2hex(random_bytes(8));
    if (!mkdir($firstCrashStage, 0700) || !chown($firstCrashStage, 0) || !chgrp($firstCrashStage, 0)) {
        throw new RuntimeException('Could not create simulated first-time restore directory.');
    }
    edm_backup_write_transaction_state($firstCrashStage, array(
        'version' => 1, 'state' => 'active',
        'admin_existed' => false, 'registry_existed' => false
    ));
    edm_backup_write_private_file($data . '/admin.json', json_encode($crashNewAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $crashNewRegistry);
    edm_backup_recover_interrupted_restores();
    edm_backup_integration_assert(!file_exists($data . '/admin.json')
        && !file_exists($data . '/disks.json'),
        'startup recovery removes partial first-time configuration when no previous files existed');
    $passed++;

    // Recovery itself must be retryable if the NAS fails again while restoring
    // the old administrator file.
    edm_backup_write_private_file($data . '/admin.json', json_encode($crashNewAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_private_file($data . '/disks.json', json_encode($crashNewRegistry, JSON_PRETTY_PRINT) . "\n");
    $retryStage = $data . '/.restore-' . bin2hex(random_bytes(8));
    if (!mkdir($retryStage, 0700) || !chown($retryStage, 0) || !chgrp($retryStage, 0)) {
        throw new RuntimeException('Could not create simulated retry restore directory.');
    }
    edm_backup_write_private_file($retryStage . '/previous-admin.json',
        json_encode($crashOldAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_private_file($retryStage . '/previous-disks.json',
        json_encode($crashOldRegistry, JSON_PRETTY_PRINT) . "\n");
    edm_backup_write_transaction_state($retryStage, array(
        'version' => 1, 'state' => 'active',
        'admin_existed' => true, 'registry_existed' => true
    ));
    $GLOBALS['edm_backup_test_failures']['rename'] = array(array(
        'from_basename' => 'previous-admin.json',
        'to' => $data . '/admin.json',
        'remaining' => 1
    ));
    $retryFailure = false;
    try {
        edm_backup_recover_interrupted_restores();
    } catch (Throwable $exception) {
        $retryFailure = true;
    }
    $GLOBALS['edm_backup_test_failures'] = array();
    edm_backup_integration_assert($retryFailure && is_dir($retryStage),
        'interrupted recovery retains its journal and staging files when a recovery rename fails');
    $passed++;
    edm_backup_recover_interrupted_restores();
    edm_backup_integration_assert(
        edm_auth_read_admin()['password_hash'] === $crashOldAdmin['password_hash']
            && edm_registry_load($data . '/disks.json') === $crashOldRegistry
            && !file_exists($retryStage),
        'a later startup recovery safely completes a previously interrupted recovery');
    $passed++;

    // Fail the registry install and then fail restoration of the previous
    // administrator file. The old administrator must remain in the private
    // staging directory for manual recovery.
    $_SESSION['auth_epoch'] = trim(file_get_contents($data . '/auth-epoch'));
    $incompleteAdmin = array(
        'password_hash' => password_hash('integration-incomplete-rollback-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $incompleteRegistry = array('version' => 1, 'disks' => array());
    edm_backup_write_private_file($data . '/admin.json', json_encode($incompleteAdmin, JSON_PRETTY_PRINT) . "\n");
    edm_registry_save($data . '/disks.json', $incompleteRegistry);
    $GLOBALS['edm_backup_test_failures'] = array(
        'rename' => array(
            array('to' => $data . '/disks.json', 'remaining' => 1),
            array('from_basename' => 'previous-admin.json', 'to' => $data . '/admin.json', 'remaining' => 1)
        )
    );

    $incompleteFailureMessage = null;
    try {
        edm_backup_restore_archive($archive, true);
    } catch (Throwable $exception) {
        $incompleteFailureMessage = $exception->getMessage();
    }
    $GLOBALS['edm_backup_test_failures'] = array();
    edm_backup_integration_assert($incompleteFailureMessage === 'Could not install restored disk registry.',
        'restore reports the registry-install failure that triggers incomplete rollback');
    $passed++;
    $recoveryStages = glob($data . '/.restore-*', GLOB_ONLYDIR);
    $recoveryStage = (is_array($recoveryStages) && count($recoveryStages) === 1)
        ? $recoveryStages[0] : null;
    edm_backup_integration_assert($recoveryStage !== null
        && is_file($recoveryStage . '/previous-admin.json'),
        'incomplete rollback retains the previous administrator in its recovery directory');
    $passed++;
    edm_backup_integration_assert(edm_registry_load($data . '/disks.json') === $incompleteRegistry,
        'incomplete rollback still restores the previous registry where possible');
    $passed++;

    echo "PASS: {$passed} backup integration checks completed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($base);
}
