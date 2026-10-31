<?php
/*
 * Integration tests for EncDiskManager backup creation and restore.
 *
 * Run as root with PHP 7.4+ and /bin/tar:
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

try {
    foreach (array('auth.php', 'registry.php', 'backup.php') as $file) {
        if (!copy(__DIR__ . '/../../lib/' . $file, $lib . '/' . $file)) {
            throw new RuntimeException('Could not stage library file: ' . $file);
        }
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
    edm_backup_integration_assert(!file_exists($data . '/setup-token')
        && !file_exists($data . '/login-attempts.json'),
        'replacement restore removes obsolete setup and login-attempt files');
    $passed += 2;

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
    edm_backup_write_private_file($data . '/admin.json', json_encode($rollbackAdmin, JSON_PRETTY_PRINT) . "\\n");
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

    echo "PASS: {$passed} backup integration checks completed.\\n";
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
