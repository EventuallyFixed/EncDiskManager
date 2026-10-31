<?php
/*
 * Standalone tests for Encrypted Disk Manager backup archive validation.
 *
 * Run with PHP 7.4 or newer:
 *   php development/tests/backup_test.php
 *
 * Uses only temporary files and tar/gzip. It does not access disks, LUKS,
 * cryptsetup, mount, fsck, or NAS services.
 */
require_once __DIR__ . '/../../lib/backup.php';

function edm_backup_test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function edm_backup_test_rejects($archive, $message)
{
    $rejected = false;
    try {
        edm_backup_read_archive($archive);
    } catch (RuntimeException $exception) {
        $rejected = true;
    }
    edm_backup_test_assert($rejected, $message);
}

function edm_backup_test_tar($base, $archive, $paths)
{
    $quoted = array();
    foreach ($paths as $path) {
        $quoted[] = escapeshellarg($path);
    }
    $command = '/bin/tar -czf ' . escapeshellarg($archive)
        . ' -C ' . escapeshellarg($base) . ' ' . implode(' ', $quoted) . ' 2>/dev/null';
    $output = array();
    $status = 1;
    exec($command, $output, $status);
    edm_backup_test_assert($status === 0 && is_file($archive), 'test tar archive created');
    $contents = file_get_contents($archive);
    edm_backup_test_assert($contents !== false, 'test tar archive read');
    return $contents;
}

$base = sys_get_temp_dir() . '/edm-backup-test-' . bin2hex(random_bytes(8));
$root = $base . '/encdiskmanager-backup';
$data = $root . '/data';
if (!mkdir($base, 0700) || !mkdir($root, 0700) || !mkdir($data, 0700)) {
    fwrite(STDERR, "Could not create temporary test directory.\n");
    exit(2);
}

$passed = 0;
try {
    $admin = array(
        'password_hash' => password_hash('test-only-password', PASSWORD_DEFAULT),
        'created_at' => time()
    );
    $registry = array('version' => 1, 'disks' => array());
    $metadata = array(
        'format_version' => 1,
        'application' => 'Encrypted Disk Manager',
        'created_at' => time()
    );
    file_put_contents($root . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT) . "\n");
    file_put_contents($data . '/admin.json', json_encode($admin, JSON_PRETTY_PRINT) . "\n");
    file_put_contents($data . '/disks.json', edm_registry_encode($registry));

    $archive = edm_backup_test_tar($base, $base . '/valid.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json'
    ));
    $payload = edm_backup_read_archive($archive);
    edm_backup_test_assert($payload['admin']['password_hash'] === $admin['password_hash'], 'valid archive retains password hash');
    edm_backup_test_assert($payload['registry'] === $registry, 'valid archive restores registry schema');
    $passed += 3;

    edm_backup_test_rejects('not a gzip archive', 'non-gzip input is rejected');
    $passed++;

    // Alter a header byte without updating its checksum, then recompress.
    $tar = gzdecode($archive);
    $tar[0] = ($tar[0] === 'x') ? 'y' : 'x';
    edm_backup_test_rejects(gzencode($tar), 'tampered tar header checksum is rejected');
    $passed++;

    // An extra regular file is rejected even though the expected files exist.
    file_put_contents($root . '/unexpected.txt', 'unexpected');
    $extra = edm_backup_test_tar($base, $base . '/extra.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json',
        'encdiskmanager-backup/unexpected.txt'
    ));
    edm_backup_test_rejects($extra, 'unexpected archive path is rejected');
    unlink($root . '/unexpected.txt');
    $passed++;

    // Duplicate archive paths are rejected rather than allowing a later
    // entry to silently override an earlier administrator or registry file.
    $duplicate = edm_backup_test_tar($base, $base . '/duplicate.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json'
    ));
    edm_backup_test_rejects($duplicate, 'duplicate archive path is rejected');
    $passed++;

    // Symbolic links are rejected, even when their target is within the archive.
    rename($root . '/metadata.json', $root . '/metadata.real');
    symlink('metadata.real', $root . '/metadata.json');
    $linked = edm_backup_test_tar($base, $base . '/linked.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json'
    ));
    edm_backup_test_rejects($linked, 'symbolic link entry is rejected');
    unlink($root . '/metadata.json');
    rename($root . '/metadata.real', $root . '/metadata.json');
    $passed++;

    echo "PASS: {$passed} backup archive checks completed.\n";
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
