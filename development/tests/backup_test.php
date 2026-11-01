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


function edm_backup_test_rechecksum(&$tar, $offset)
{
    $header = substr($tar, $offset, 512);
    $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $checksum = 0;
    for ($i = 0; $i < 512; $i++) {
        $checksum += ord($checksumHeader[$i]);
    }
    $field = sprintf("%06o\0 ", $checksum);
    $tar = substr_replace($tar, $field, $offset + 148, 8);
}

function edm_backup_test_header_offset($tar, $wantedPath)
{
    $offset = 0;
    $length = strlen($tar);
    while ($offset + 512 <= $length) {
        $header = substr($tar, $offset, 512);
        if ($header === str_repeat("\0", 512)) {
            return false;
        }
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        $path = ($prefix === '') ? $name : $prefix . '/' . $name;
        if ($path === $wantedPath) {
            return $offset;
        }
        $sizeText = trim(substr($header, 124, 12), " \0");
        if ($sizeText === '' || !preg_match('/^[0-7]+$/', $sizeText)) {
            return false;
        }
        $size = octdec($sizeText);
        $offset += 512 + (int)(ceil($size / 512) * 512);
    }
    return false;
}

function edm_backup_test_rejects_with_reason($archive, $message)
{
    edm_backup_test_rejects($archive, $message);
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
    $tarForDuplicate = gzdecode($archive);
    $firstSize = octdec(trim(substr($tarForDuplicate, 124, 12), " \\0"));
    $firstRecordLength = 512 + (int)(ceil($firstSize / 512) * 512);
    $duplicateTar = substr($tarForDuplicate, 0, $firstRecordLength)
        . substr($tarForDuplicate, 0, $firstRecordLength)
        . substr($tarForDuplicate, $firstRecordLength);
    edm_backup_test_rejects(gzencode($duplicateTar), 'duplicate archive path is rejected');
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


    // Archive paths are matched against an exact allow-list. Mutate a valid
    // header and recompute its checksum so these tests reach path validation.
    $pathTraversalTar = gzdecode($archive);
    $pathTraversalTar = substr_replace(
        $pathTraversalTar,
        str_pad('../metadata.json', 100, "\0"),
        0,
        100
    );
    edm_backup_test_rechecksum($pathTraversalTar, 0);
    edm_backup_test_rejects(gzencode($pathTraversalTar), 'parent-directory traversal path is rejected');
    $passed++;

    $absolutePathTar = gzdecode($archive);
    $absolutePathTar = substr_replace(
        $absolutePathTar,
        str_pad('/tmp/metadata.json', 100, "\0"),
        0,
        100
    );
    edm_backup_test_rechecksum($absolutePathTar, 0);
    edm_backup_test_rejects(gzencode($absolutePathTar), 'absolute archive path is rejected');
    $passed++;

    // Hard links and special TAR entries must not be accepted as regular files.
    $hardLinkTar = gzdecode($archive);
    $hardLinkTar = substr_replace($hardLinkTar, '1', 156, 1);
    $hardLinkTar = substr_replace(
        $hardLinkTar,
        str_pad('metadata.json', 100, "\0"),
        157,
        100
    );
    edm_backup_test_rechecksum($hardLinkTar, 0);
    edm_backup_test_rejects(gzencode($hardLinkTar), 'hard-link entry is rejected');
    $passed++;

    $specialEntryTar = gzdecode($archive);
    $specialEntryTar = substr_replace($specialEntryTar, '5', 156, 1);
    edm_backup_test_rechecksum($specialEntryTar, 0);
    edm_backup_test_rejects(gzencode($specialEntryTar), 'directory/special TAR entry is rejected');
    $passed++;

    // Increase the final file's declared size without adding data. The parser
    // must detect that the file body extends beyond the available archive.
    $truncatedTar = gzdecode($archive);
    $lastPath = 'encdiskmanager-backup/data/disks.json';
    $lastOffset = edm_backup_test_header_offset($truncatedTar, $lastPath);
    edm_backup_test_assert($lastOffset !== false, 'last TAR header located for truncation test');
    $lastHeader = substr($truncatedTar, $lastOffset, 512);
    $lastSize = octdec(trim(substr($lastHeader, 124, 12), " \0"));
    $largerSize = $lastSize + 2048;
    $sizeField = sprintf("%011o\0", $largerSize);
    $truncatedTar = substr_replace($truncatedTar, $sizeField, $lastOffset + 124, 12);
    edm_backup_test_rechecksum($truncatedTar, $lastOffset);
    edm_backup_test_rejects(gzencode($truncatedTar), 'truncated TAR file body is rejected');
    $passed++;

    // GNU tar pads archives with many zero blocks. Remove everything after
    // the final file body so no valid two-block TAR terminator remains.
    $lastEntryEnd = $lastOffset + 512 + (int)(ceil($lastSize / 512) * 512);
    $missingEndMarkerTar = substr(gzdecode($archive), 0, $lastEntryEnd);
    edm_backup_test_rejects(gzencode($missingEndMarkerTar), 'archive missing a TAR end block is rejected');
    $passed++;

    // A syntactically valid TAR missing one required file must be rejected.
    $missing = edm_backup_test_tar($base, $base . '/missing.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json'
    ));
    edm_backup_test_rejects($missing, 'archive missing required registry file is rejected');
    $passed++;

    // The compressed input is small, but its expanded content exceeds the
    // parser's 1 MiB decompression ceiling.
    $oversizedExpanded = gzencode(str_repeat('A', 1048577));
    edm_backup_test_rejects($oversizedExpanded, 'oversized decompressed archive is rejected');
    $passed++;

    // Invalid administrator hashes must not be accepted, even in valid JSON.
    $originalAdminJson = file_get_contents($data . '/admin.json');
    $invalidAdmin = array('password_hash' => 'not-a-password-hash', 'created_at' => time());
    file_put_contents($data . '/admin.json', json_encode($invalidAdmin) . "\n");
    $invalidAdminArchive = edm_backup_test_tar($base, $base . '/invalid-admin.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json'
    ));
    edm_backup_test_rejects($invalidAdminArchive, 'invalid administrator data is rejected');
    file_put_contents($data . '/admin.json', $originalAdminJson);
    $passed++;

    // Registry JSON is parsed and schema-validated before restore.
    $originalRegistryJson = file_get_contents($data . '/disks.json');
    $invalidRegistry = array('version' => 1, 'disks' => array(array('luks_uuid' => 'invalid')));
    file_put_contents($data . '/disks.json', json_encode($invalidRegistry) . "\n");
    $invalidRegistryArchive = edm_backup_test_tar($base, $base . '/invalid-registry.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json'
    ));
    edm_backup_test_rejects($invalidRegistryArchive, 'invalid disk registry schema is rejected');
    file_put_contents($data . '/disks.json', $originalRegistryJson);
    $passed++;

    // Metadata is independently constrained to the supported application and
    // format version, not merely accepted because it is valid JSON.
    $originalMetadataJson = file_get_contents($root . '/metadata.json');
    $invalidMetadata = array(
        'format_version' => 99,
        'application' => 'Other application',
        'created_at' => time()
    );
    file_put_contents($root . '/metadata.json', json_encode($invalidMetadata) . "\n");
    $invalidMetadataArchive = edm_backup_test_tar($base, $base . '/invalid-metadata.tgz', array(
        'encdiskmanager-backup/metadata.json',
        'encdiskmanager-backup/data/admin.json',
        'encdiskmanager-backup/data/disks.json'
    ));
    edm_backup_test_rejects($invalidMetadataArchive, 'unsupported backup metadata is rejected');
    file_put_contents($root . '/metadata.json', $originalMetadataJson);
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
