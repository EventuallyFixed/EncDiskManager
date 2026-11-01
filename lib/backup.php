<?php
/*
 * Encrypted Disk Manager configuration backup/restore.
 * Format: gzip-compressed tar with exactly three regular files:
 * encdiskmanager-backup/metadata.json
 * encdiskmanager-backup/data/admin.json
 * encdiskmanager-backup/data/disks.json
 *
 * The archive contains a password hash, never a plaintext password or LUKS key.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/registry.php';

function edm_backup_write_private_file($path, $contents)
{
    $written = file_put_contents($path, $contents, LOCK_EX);
    if ($written === false || $written !== strlen($contents)
        || !chown($path, 0) || !chgrp($path, 0) || !chmod($path, 0600)) {
        throw new RuntimeException('Could not securely write backup data.');
    }
}

function edm_backup_sync_filesystem()
{
    // PHP 7.4 has no fsync() API. Use the platform sync utility as a durability
    // barrier before advancing the transaction journal.
    if (!function_exists('exec')) {
        throw new RuntimeException('Filesystem sync is unavailable; restore cannot proceed safely.');
    }
    $syncPath = null;
    foreach (array('/bin/sync', '/usr/bin/sync', '/usr/sbin/sync') as $candidate) {
        if (is_executable($candidate)) {
            $syncPath = $candidate;
            break;
        }
    }
    if ($syncPath === null) {
        throw new RuntimeException('The sync utility is unavailable; restore cannot proceed safely.');
    }
    $output = array();
    $status = 1;
    exec(escapeshellarg($syncPath), $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Could not flush restore transaction data to storage.');
    }
}

function edm_backup_write_transaction_state($stage, $transaction)
{
    $temporary = $stage . '/transaction.json.tmp';
    $manifest = $stage . '/transaction.json';
    if (is_link($temporary) || is_link($manifest)) {
        throw new RuntimeException('Restore transaction journal must not contain symbolic links.');
    }
    $json = json_encode($transaction, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode restore transaction journal.');
    }
    edm_backup_write_private_file($temporary, $json . "\n");
    if (!rename($temporary, $manifest)) {
        throw new RuntimeException('Could not update restore transaction journal.');
    }
}

function edm_backup_open_recovery_locks($dataPath)
{
    $locks = array();
    foreach (array('restore.lock', 'admin.lock') as $name) {
        $path = $dataPath . '/' . $name;
        if (is_link($path)) {
            throw new RuntimeException('Restore recovery lock must not be a symbolic link.');
        }
        if (file_exists($path)) {
            $stat = @lstat($path);
            if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
                || $stat['uid'] !== 0) {
                throw new RuntimeException('Restore recovery lock must be a root-owned regular file.');
            }
        }
        $handle = fopen($path, 'c');
        if ($handle === false || !chown($path, 0) || !chgrp($path, 0)
            || !chmod($path, 0600) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Could not secure restore recovery locks.');
        }
        $locks[] = $handle;
    }
    return $locks;
}

function edm_backup_recovery_validate_file($path)
{
    if (is_link($path)) {
        throw new RuntimeException('Restore recovery encountered an unexpected symbolic link.');
    }
    $stat = @lstat($path);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
        throw new RuntimeException('Restore recovery file failed ownership or permission checks.');
    }
}

function edm_backup_recovery_remove_stage($stage)
{
    $allowed = array(
        'transaction.json', 'transaction.json.tmp',
        'previous-admin.json', 'previous-disks.json',
        'admin.json', 'disks.json'
    );
    $entries = scandir($stage);
    if (!is_array($entries)) {
        throw new RuntimeException('Could not inspect restore recovery directory.');
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!in_array($entry, $allowed, true)) {
            throw new RuntimeException('Unexpected entry in restore recovery directory: ' . $entry);
        }
        $path = $stage . '/' . $entry;
        edm_backup_recovery_validate_file($path);
        if ($entry !== 'transaction.json' && !@unlink($path)) {
            throw new RuntimeException('Could not remove a completed restore recovery file.');
        }
    }
    $manifest = $stage . '/transaction.json';
    if (file_exists($manifest) && !@unlink($manifest)) {
        throw new RuntimeException('Could not remove completed restore transaction journal.');
    }
    if (!@rmdir($stage)) {
        throw new RuntimeException('Could not remove completed restore recovery directory.');
    }
}

function edm_backup_recover_interrupted_restores()
{
    $dataPath = edm_auth_data_path();
    $locks = edm_backup_open_recovery_locks($dataPath);
    $recovered = 0;
    try {
        $stages = glob($dataPath . '/.restore-*', GLOB_ONLYDIR);
        if (!is_array($stages)) {
            throw new RuntimeException('Could not scan for interrupted restore transactions.');
        }
        foreach ($stages as $stage) {
            $base = basename($stage);
            if (!preg_match('/^\.restore-[a-f0-9]{16}$/', $base)) {
                throw new RuntimeException('Unexpected restore recovery directory name.');
            }
            $stageStat = @lstat($stage);
            if ($stageStat === false || (($stageStat['mode'] & 0170000) !== 0040000)
                || is_link($stage) || $stageStat['uid'] !== 0
                || (($stageStat['mode'] & 0777) !== 0700)) {
                throw new RuntimeException('Restore recovery directory failed security checks.');
            }

            $manifest = $stage . '/transaction.json';
            if (is_link($manifest)) {
                throw new RuntimeException('Restore transaction journal must not be a symbolic link.');
            }
            if (!file_exists($manifest)) {
                // A crash before the journal was written occurs before any live
                // configuration is moved. Only an empty stage or an incomplete
                // temporary journal is safe to discard.
                $entries = scandir($stage);
                if (!is_array($entries)) {
                    throw new RuntimeException('Could not inspect an unjournaled restore directory.');
                }
                $entries = array_values(array_diff($entries, array('.', '..')));
                if (count($entries) === 1 && $entries[0] === 'transaction.json.tmp') {
                    edm_backup_recovery_validate_file($stage . '/transaction.json.tmp');
                    if (!@unlink($stage . '/transaction.json.tmp')) {
                        throw new RuntimeException('Could not remove incomplete restore journal.');
                    }
                    $entries = array();
                }
                if (count($entries) !== 0 || !@rmdir($stage)) {
                    throw new RuntimeException('Unjournaled restore directory contains data; manual recovery is required.');
                }
                $recovered++;
                continue;
            }

            edm_backup_recovery_validate_file($manifest);
            $decoded = json_decode((string)file_get_contents($manifest), true);
            if (!is_array($decoded)
                || count($decoded) !== 4
                || !isset($decoded['version'], $decoded['state'], $decoded['admin_existed'], $decoded['registry_existed'])
                || $decoded['version'] !== 1
                || !in_array($decoded['state'], array('active', 'committed', 'rolled_back'), true)
                || !is_bool($decoded['admin_existed'])
                || !is_bool($decoded['registry_existed'])) {
                throw new RuntimeException('Restore transaction journal is invalid; manual recovery is required.');
            }

            if ($decoded['state'] === 'active') {
                $targets = array(
                    array('name' => 'admin.json', 'old' => 'previous-admin.json', 'existed' => $decoded['admin_existed']),
                    array('name' => 'disks.json', 'old' => 'previous-disks.json', 'existed' => $decoded['registry_existed'])
                );
                foreach ($targets as $target) {
                    $live = $dataPath . '/' . $target['name'];
                    $old = $stage . '/' . $target['old'];
                    if (is_link($live) || is_link($old)) {
                        throw new RuntimeException('Restore recovery refused a symbolic link.');
                    }
                    if ($target['existed']) {
                        if (file_exists($old)) {
                            edm_backup_recovery_validate_file($old);
                            if (file_exists($live)) {
                                edm_backup_recovery_validate_file($live);
                                if (!@unlink($live)) {
                                    throw new RuntimeException('Could not remove partially restored configuration.');
                                }
                            }
                            if (!@rename($old, $live)) {
                                throw new RuntimeException('Could not reinstate previous configuration during recovery.');
                            }
                        } elseif (file_exists($live)) {
                            // The old file was never moved, or a previous
                            // recovery pass already moved it back.
                            edm_backup_recovery_validate_file($live);
                        } else {
                            throw new RuntimeException('Previous configuration is missing; manual recovery is required.');
                        }
                    } elseif (file_exists($live)) {
                        edm_backup_recovery_validate_file($live);
                        if (!@unlink($live)) {
                            throw new RuntimeException('Could not remove partially installed first-time configuration.');
                        }
                    }
                }
                edm_backup_sync_filesystem();
                $decoded['state'] = 'rolled_back';
                edm_backup_write_transaction_state($stage, $decoded);
                edm_backup_sync_filesystem();
            }

            // A committed transaction keeps the new live files. A rolled-back
            // transaction has already reinstated the old state. Cleanup is safe
            // for either terminal state and is deliberately idempotent.
            edm_backup_recovery_remove_stage($stage);
            $recovered++;
        }
    } finally {
        for ($i = count($locks) - 1; $i >= 0; $i--) {
            @flock($locks[$i], LOCK_UN);
            fclose($locks[$i]);
        }
    }
    return $recovered;
}

function edm_backup_read_archive($compressed)
{
    if (!is_string($compressed) || strlen($compressed) < 20 || strlen($compressed) > 2097152) {
        throw new RuntimeException('Backup archive is empty or exceeds the 2 MiB limit.');
    }
    if (!function_exists('gzdecode')) {
        throw new RuntimeException('PHP zlib support is required to read backup archives.');
    }
    $tar = @gzdecode($compressed, 1048576);
    if (!is_string($tar) || strlen($tar) > 1048576 || (strlen($tar) % 512) !== 0) {
        throw new RuntimeException('Backup archive is not a valid bounded gzip/tar file.');
    }

    $expected = array(
        'encdiskmanager-backup/metadata.json' => 4096,
        'encdiskmanager-backup/data/admin.json' => 16384,
        'encdiskmanager-backup/data/disks.json' => 262144
    );
    $files = array();
    $offset = 0;
    $length = strlen($tar);
    $zeroBlocks = 0;

    while ($offset + 512 <= $length) {
        $header = substr($tar, $offset, 512);
        if ($header === str_repeat("\0", 512)) {
            $zeroBlocks++;
            $offset += 512;
            if ($zeroBlocks >= 2) {
                break;
            }
            continue;
        }
        if ($zeroBlocks !== 0) {
            throw new RuntimeException('Backup archive has data after its end marker.');
        }

        $storedChecksum = trim(substr($header, 148, 8), " \0");
        if ($storedChecksum === '' || !preg_match('/^[0-7]+$/', $storedChecksum)) {
            throw new RuntimeException('Backup archive header checksum is invalid.');
        }
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($checksumHeader[$i]);
        }
        if ($checksum !== octdec($storedChecksum)) {
            throw new RuntimeException('Backup archive header checksum does not match.');
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        $path = ($prefix === '') ? $name : $prefix . '/' . $name;
        $type = substr($header, 156, 1);
        $linkName = rtrim(substr($header, 157, 100), "\0");
        if (!isset($expected[$path]) || isset($files[$path])) {
            throw new RuntimeException('Backup archive contains an unexpected or duplicate path.');
        }
        if (($type !== "\0" && $type !== '0') || $linkName !== '') {
            throw new RuntimeException('Backup archive may contain regular files only; links and special entries are rejected.');
        }

        $sizeText = trim(substr($header, 124, 12), " \0");
        if ($sizeText === '' || !preg_match('/^[0-7]+$/', $sizeText)) {
            throw new RuntimeException('Backup archive contains an invalid file size.');
        }
        $size = octdec($sizeText);
        if ($size < 1 || $size > $expected[$path]) {
            throw new RuntimeException('A backup file is empty or exceeds its permitted size.');
        }
        $dataOffset = $offset + 512;
        $paddedSize = (int)(ceil($size / 512) * 512);
        if ($dataOffset + $paddedSize > $length) {
            throw new RuntimeException('Backup archive is truncated.');
        }
        $files[$path] = substr($tar, $dataOffset, $size);
        $offset = $dataOffset + $paddedSize;
    }

    if ($zeroBlocks < 2) {
        throw new RuntimeException('Backup archive is missing its end marker.');
    }
    if (trim(substr($tar, $offset), "\0") !== '') {
        throw new RuntimeException('Backup archive has unexpected trailing data.');
    }
    if (count($files) !== count($expected)) {
        throw new RuntimeException('Backup archive is missing one or more required files.');
    }

    $metadata = json_decode($files['encdiskmanager-backup/metadata.json'], true);
    $metadataKeys = is_array($metadata) ? array_keys($metadata) : array();
    sort($metadataKeys);
    if (!is_array($metadata) || $metadataKeys !== array('application', 'created_at', 'format_version')
        || $metadata['format_version'] !== 1
        || $metadata['application'] !== 'Encrypted Disk Manager'
        || !is_int($metadata['created_at']) || $metadata['created_at'] <= 0) {
        throw new RuntimeException('Backup metadata is invalid or incompatible.');
    }

    $admin = json_decode($files['encdiskmanager-backup/data/admin.json'], true);
    if (!is_array($admin)) {
        throw new RuntimeException('Backup administrator data is malformed.');
    }
    $adminKeys = array_keys($admin);
    sort($adminKeys);
    if ($adminKeys !== array('created_at', 'password_hash')
        || !is_string($admin['password_hash'])
        || !preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/', $admin['password_hash'])
        || !is_int($admin['created_at']) || $admin['created_at'] <= 0) {
        throw new RuntimeException('Backup administrator data is invalid.');
    }

    $registry = edm_registry_decode($files['encdiskmanager-backup/data/disks.json']);
    return array(
        'admin' => $admin,
        'admin_json' => json_encode($admin, JSON_PRETTY_PRINT) . "\n",
        'registry' => $registry,
        'registry_json' => edm_registry_encode($registry)
    );
}

function edm_backup_acquire_lock($dataPath, $name)
{
    if (!preg_match('/^[a-z0-9.-]+$/', $name)) {
        throw new RuntimeException('Internal configuration lock name is invalid.');
    }
    $path = $dataPath . '/' . $name;
    if (is_link($path)) {
        throw new RuntimeException('Configuration lock must not be a symbolic link: ' . $name);
    }
    if (file_exists($path)) {
        $stat = @lstat($path);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000) || $stat['uid'] !== 0) {
            throw new RuntimeException('Configuration lock must be a root-owned regular file: ' . $name);
        }
    }
    $lock = fopen($path, 'c');
    if ($lock === false || !chown($path, 0) || !chgrp($path, 0)
        || !chmod($path, 0600) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not secure configuration lock: ' . $name);
    }
    return $lock;
}

function edm_backup_release_lock($lock)
{
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function edm_backup_create_archive()
{
    $dataPath = edm_auth_data_path();
    $restoreLock = edm_backup_acquire_lock($dataPath, 'restore.lock');
    try {
        $adminLock = edm_backup_acquire_lock($dataPath, 'admin.lock');
        try {
            if (!edm_auth_is_authenticated()) {
                throw new RuntimeException('Your administrator session is no longer valid; sign in again before downloading a backup.');
            }
            $admin = edm_auth_read_admin();
            if ($admin === null) {
                throw new RuntimeException('No administrator account exists to back up.');
            }
            $registry = edm_registry_load($dataPath . '/disks.json');

            $base = rtrim(sys_get_temp_dir(), '/') . '/edm-backup-' . bin2hex(random_bytes(12));
            $root = $base . '/encdiskmanager-backup';
            $data = $root . '/data';
            $archive = $base . '/backup.tgz';
            if (!mkdir($base, 0700)) {
                throw new RuntimeException('Could not create a private temporary backup directory.');
            }

            try {
                if (!mkdir($root, 0700) || !mkdir($data, 0700)) {
                    throw new RuntimeException('Could not prepare the private backup directory.');
                }
                $metadata = json_encode(array(
                    'format_version' => 1,
                    'application' => 'Encrypted Disk Manager',
                    'created_at' => time()
                ), JSON_PRETTY_PRINT);
                if ($metadata === false) {
                    throw new RuntimeException('Backup metadata could not be encoded.');
                }
                edm_backup_write_private_file($root . '/metadata.json', $metadata . "\n");
                edm_backup_write_private_file($data . '/admin.json', json_encode($admin, JSON_PRETTY_PRINT) . "\n");
                edm_backup_write_private_file($data . '/disks.json', edm_registry_encode($registry));

                $command = '/bin/tar -czf ' . escapeshellarg($archive)
                    . ' -C ' . escapeshellarg($base)
                    . ' encdiskmanager-backup/metadata.json'
                    . ' encdiskmanager-backup/data/admin.json'
                    . ' encdiskmanager-backup/data/disks.json 2>/dev/null';
                $output = array();
                $status = 1;
                exec($command, $output, $status);
                if ($status !== 0 || is_link($archive) || !is_file($archive)) {
                    throw new RuntimeException('The NAS tar utility could not create the backup archive.');
                }
                $stat = lstat($archive);
                if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
                    || $stat['size'] < 20 || $stat['size'] > 2097152
                    || !chmod($archive, 0600)) {
                    throw new RuntimeException('Generated backup archive failed its safety checks.');
                }
                $contents = file_get_contents($archive);
                if ($contents === false) {
                    throw new RuntimeException('Generated backup archive could not be read.');
                }
                // Validate our own output with the same strict parser used for uploads.
                edm_backup_read_archive($contents);
                return $contents;
            } finally {
                foreach (array(
                    $data . '/admin.json', $data . '/disks.json',
                    $root . '/metadata.json', $archive
                ) as $file) {
                    if (is_file($file) && !is_link($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($data);
                @rmdir($root);
                @rmdir($base);
            }
        } finally {
            edm_backup_release_lock($adminLock);
        }
    } finally {
        edm_backup_release_lock($restoreLock);
    }
}

function edm_backup_restore_archive($compressed, $replaceExisting)
{
    // Finish any transaction left by an earlier interrupted request before starting another.
    edm_backup_recover_interrupted_restores();
    // Check that the durability barrier is available before creating a journal
    // or moving any live configuration files.
    edm_backup_sync_filesystem();
    $payload = edm_backup_read_archive($compressed);
    $dataPath = edm_auth_data_path();
    $currentAdmin = edm_auth_read_admin();
    if ($currentAdmin !== null && !$replaceExisting) {
        throw new RuntimeException('An administrator already exists; explicit replacement confirmation is required.');
    }
    if ($currentAdmin === null && $replaceExisting) {
        throw new RuntimeException('There is no existing administrator account to replace.');
    }

    $stageSuffix = bin2hex(random_bytes(8));
    $lockPath = $dataPath . '/restore.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Restore lock must not be a symbolic link.');
    }
    if (file_exists($lockPath)) {
        $lockStat = @lstat($lockPath);
        if ($lockStat === false || (($lockStat['mode'] & 0170000) !== 0100000)
            || $lockStat['uid'] !== 0) {
            throw new RuntimeException('Restore lock must be a root-owned regular file.');
        }
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !chown($lockPath, 0) || !chgrp($lockPath, 0)
        || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not secure the restore lock.');
    }

    // Serialize credential replacement with login and initial administrator
    // creation. Login captures the session epoch while holding this same lock.
    $adminLockPath = $dataPath . '/admin.lock';
    if (is_link($adminLockPath)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('Administrator lock must not be a symbolic link.');
    }
    if (file_exists($adminLockPath)) {
        $adminLockStat = @lstat($adminLockPath);
        if ($adminLockStat === false || (($adminLockStat['mode'] & 0170000) !== 0100000)
            || $adminLockStat['uid'] !== 0) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException('Administrator lock must be a root-owned regular file.');
        }
    }
    $adminLock = fopen($adminLockPath, 'c');
    if ($adminLock === false || !chown($adminLockPath, 0) || !chgrp($adminLockPath, 0)
        || !chmod($adminLockPath, 0600) || !flock($adminLock, LOCK_EX)) {
        if (is_resource($adminLock)) {
            fclose($adminLock);
        }
        @flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('Could not secure the administrator lock for restore.');
    }

    $stage = $dataPath . '/.restore-' . $stageSuffix;
    $oldAdmin = $stage . '/previous-admin.json';
    $oldRegistry = $stage . '/previous-disks.json';
    $adminPath = $dataPath . '/admin.json';
    $registryPath = $dataPath . '/disks.json';
    $adminMoved = false;
    $registryMoved = false;
    $adminInstalled = false;
    $registryInstalled = false;
    $preserveStageForRecovery = false;
    $transactionCreated = false;

    try {
        // Re-check authorization and account state after taking both locks;
        // another browser may have restored or deleted configuration meanwhile.
        $lockedAdmin = edm_auth_read_admin();
        if ($replaceExisting) {
            if ($lockedAdmin === null || !edm_auth_is_authenticated()) {
                throw new RuntimeException('Your administrator session is no longer valid; sign in again before replacing configuration.');
            }
        } elseif ($lockedAdmin !== null) {
            throw new RuntimeException('An administrator has been configured; sign in before replacing its configuration.');
        }

        // Reject unsafe ephemeral entries before changing either configuration
        // file or rotating the authentication epoch. This keeps a refused restore
        // from invalidating sessions when no replacement has been committed.
        foreach (array('setup-token', 'login-attempts.json') as $ephemeral) {
            $path = $dataPath . '/' . $ephemeral;
            if (is_link($path)) {
                throw new RuntimeException('Unexpected symbolic link in private configuration directory.');
            }
        }

        if (!mkdir($stage, 0700) || !chown($stage, 0) || !chgrp($stage, 0)) {
            throw new RuntimeException('Could not create a private restore staging directory.');
        }
        $adminExisted = file_exists($adminPath);
        $registryExisted = file_exists($registryPath);
        $transaction = array(
            'version' => 1,
            'state' => 'active',
            'admin_existed' => $adminExisted,
            'registry_existed' => $registryExisted
        );
        edm_backup_write_transaction_state($stage, $transaction);
        $transactionCreated = true;
        edm_backup_sync_filesystem();

        if ($adminExisted) {
            edm_auth_read_admin();
            if (!rename($adminPath, $oldAdmin)) {
                throw new RuntimeException('Could not stage the existing administrator file.');
            }
            $adminMoved = true;
        }
        // Load even when file_exists() is false so a dangling symlink is rejected.
        edm_registry_load($registryPath);
        if ($registryExisted) {
            if (!rename($registryPath, $oldRegistry)) {
                throw new RuntimeException('Could not stage the existing disk registry.');
            }
            $registryMoved = true;
        }

        edm_backup_write_private_file($stage . '/admin.json', $payload['admin_json']);
        edm_backup_write_private_file($stage . '/disks.json', $payload['registry_json']);
        // Persist the journal, previous files and staged replacements before
        // installing either replacement into the live configuration directory.
        edm_backup_sync_filesystem();

        if (!rename($stage . '/admin.json', $adminPath)) {
            throw new RuntimeException('Could not install restored administrator data.');
        }
        $adminInstalled = true;
        if (!rename($stage . '/disks.json', $registryPath)) {
            throw new RuntimeException('Could not install restored disk registry.');
        }
        $registryInstalled = true;
        edm_backup_sync_filesystem();

        // Invalidate sessions in every browser after replacing credentials.
        edm_auth_rotate_session_epoch();
        edm_backup_sync_filesystem();

        // The committed journal is written only after both replacements and
        // the new authentication epoch have been flushed to storage.
        $transaction['state'] = 'committed';
        edm_backup_write_transaction_state($stage, $transaction);
        edm_backup_sync_filesystem();

        // The installed admin.json disables setup mode even if an obsolete
        // token cannot be unlinked. Cleanup must not trigger a destructive
        // rollback after both replacement files have been committed.
        foreach (array('setup-token', 'login-attempts.json') as $ephemeral) {
            $path = $dataPath . '/' . $ephemeral;
            if (file_exists($path) && !@unlink($path)) {
                error_log('EncDiskManager: could not remove obsolete ' . $ephemeral . ' after restore.');
            }
        }
    } catch (Throwable $exception) {
        $rollbackOk = true;
        if ($adminInstalled && file_exists($adminPath) && !@unlink($adminPath)) {
            $rollbackOk = false;
        }
        if ($registryInstalled && file_exists($registryPath) && !@unlink($registryPath)) {
            $rollbackOk = false;
        }
        if ($adminMoved && file_exists($oldAdmin) && !@rename($oldAdmin, $adminPath)) {
            $rollbackOk = false;
        }
        if ($registryMoved && file_exists($oldRegistry) && !@rename($oldRegistry, $registryPath)) {
            $rollbackOk = false;
        }
        if ($rollbackOk && $transactionCreated) {
            try {
                edm_backup_sync_filesystem();
                $transaction['state'] = 'rolled_back';
                edm_backup_write_transaction_state($stage, $transaction);
                edm_backup_sync_filesystem();
            } catch (Throwable $journalException) {
                $rollbackOk = false;
                error_log('EncDiskManager: could not persist restore rollback state: ' . $journalException->getMessage());
            }
        }
        if (!$rollbackOk) {
            // Keep the root-only staging directory and journal so startup
            // recovery can retry, or an administrator can recover manually.
            $preserveStageForRecovery = true;
            error_log('EncDiskManager: restore rollback was incomplete; recovery files retained at ' . $stage);
        }
        throw $exception;
    } finally {
        if (!$preserveStageForRecovery && is_dir($stage) && !is_link($stage)) {
            try {
                edm_backup_recovery_remove_stage($stage);
            } catch (Throwable $cleanupException) {
                error_log('EncDiskManager: restore staging cleanup deferred to recovery: ' . $cleanupException->getMessage());
            }
        }
        @flock($adminLock, LOCK_UN);
        fclose($adminLock);
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function edm_backup_delete_configuration()
{
    $dataPath = edm_auth_data_path();

    // Use the same lock order as restore: restore.lock, then admin.lock.
    // This serializes deletion with restore, login and initial setup.
    $restoreLockPath = $dataPath . '/restore.lock';
    if (is_link($restoreLockPath)) {
        throw new RuntimeException('Restore lock must not be a symbolic link.');
    }
    if (file_exists($restoreLockPath)) {
        $stat = @lstat($restoreLockPath);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000) || $stat['uid'] !== 0) {
            throw new RuntimeException('Restore lock must be a root-owned regular file.');
        }
    }
    $restoreLock = fopen($restoreLockPath, 'c');
    if ($restoreLock === false || !chown($restoreLockPath, 0) || !chgrp($restoreLockPath, 0)
        || !chmod($restoreLockPath, 0600) || !flock($restoreLock, LOCK_EX)) {
        if (is_resource($restoreLock)) {
            fclose($restoreLock);
        }
        throw new RuntimeException('Could not secure the restore lock for configuration deletion.');
    }

    $adminLockPath = $dataPath . '/admin.lock';
    if (is_link($adminLockPath)) {
        @flock($restoreLock, LOCK_UN);
        fclose($restoreLock);
        throw new RuntimeException('Administrator lock must not be a symbolic link.');
    }
    if (file_exists($adminLockPath)) {
        $stat = @lstat($adminLockPath);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0100000) || $stat['uid'] !== 0) {
            @flock($restoreLock, LOCK_UN);
            fclose($restoreLock);
            throw new RuntimeException('Administrator lock must be a root-owned regular file.');
        }
    }
    $adminLock = fopen($adminLockPath, 'c');
    if ($adminLock === false || !chown($adminLockPath, 0) || !chgrp($adminLockPath, 0)
        || !chmod($adminLockPath, 0600) || !flock($adminLock, LOCK_EX)) {
        if (is_resource($adminLock)) {
            fclose($adminLock);
        }
        @flock($restoreLock, LOCK_UN);
        fclose($restoreLock);
        throw new RuntimeException('Could not secure the administrator lock for configuration deletion.');
    }

    $temporaryToken = null;
    try {
        if (edm_auth_read_admin() === null || !edm_auth_is_authenticated()) {
            throw new RuntimeException('Your administrator session is no longer valid; sign in again before deleting configuration.');
        }

        $known = array(
            'admin.json', 'disks.json', 'setup-token', 'login-attempts.json',
            'disks.json.lock'
        );

        // Validate every known entry before deleting any of them, so an unsafe
        // file cannot cause an avoidable partial wipe.
        foreach ($known as $name) {
            $path = $dataPath . '/' . $name;
            if (is_link($path)) {
                throw new RuntimeException('Refusing to delete a symbolic link from the private data directory.');
            }
            if (file_exists($path)) {
                $stat = lstat($path);
                if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
                    || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
                    throw new RuntimeException('Saved configuration file failed security checks: ' . $name);
                }
            }
        }

        $temporaryToken = $dataPath . '/.setup-token-delete-' . bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32)) . "\n";
        edm_backup_write_private_file($temporaryToken, $token);

        // Revoke other browser sessions before removing the administrator file.
        edm_auth_rotate_session_epoch();

        foreach ($known as $name) {
            $path = $dataPath . '/' . $name;
            if (file_exists($path) && !@unlink($path)) {
                if (!file_exists($dataPath . '/admin.json')
                    && @rename($temporaryToken, $dataPath . '/setup-token')) {
                    $temporaryToken = null;
                }
                error_log('EncDiskManager: could not delete saved configuration file ' . $name);
                throw new RuntimeException('Could not delete saved configuration file: ' . $name);
            }
        }
        if (!rename($temporaryToken, $dataPath . '/setup-token')) {
            throw new RuntimeException('Configuration was deleted but a new setup token could not be installed.');
        }
        $temporaryToken = null;
    } finally {
        if ($temporaryToken !== null && is_file($temporaryToken) && !is_link($temporaryToken)) {
            @unlink($temporaryToken);
        }
        @flock($adminLock, LOCK_UN);
        fclose($adminLock);
        @flock($restoreLock, LOCK_UN);
        fclose($restoreLock);
    }
}
