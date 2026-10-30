<?php
/**
 * Standalone tests for EncDiskManager registry.php.
 *
 * Run as root with PHP 7.4:
 *   php development/tests/registry_test.php
 *
 * Uses only temporary local files. It does not access disks or invoke
 * cryptsetup, mount, fsck, or any NAS service.
 */

require_once __DIR__ . '/../../lib/registry.php';

function edm_test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function edm_test_throws($callback, $message)
{
    $thrown = false;
    try {
        $callback();
    } catch (RuntimeException $exception) {
        $thrown = true;
    }
    edm_test_assert($thrown, $message);
}

function edm_test_disk($uuid, $name, $folder)
{
    return array(
        'luks_uuid' => $uuid,
        'name' => $name,
        'mount_folder' => $folder,
        'mapper_name' => edm_registry_mapper_name($uuid),
        'enabled' => true
    );
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Tests require root because registry persistence is root-only.\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir() . '/edm-registry-test-' . getmypid() . '-' . uniqid('', true);
if (!mkdir($temporaryRoot, 0700)) {
    fwrite(STDERR, "Could not create temporary test directory.\n");
    exit(2);
}
chmod($temporaryRoot, 0700);

$passed = 0;

try {
    $uuid1 = 'a1b2c3d4-1234-5678-9012-abcdef123456';
    $uuid2 = 'b1b2c3d4-1234-5678-9012-abcdef123456';

    // Schema validation and JSON round-trip.
    $registry = array(
        'version' => 1,
        'disks' => array(
            edm_test_disk($uuid1, 'Photography Archive', 'Photography-Archive'),
            edm_test_disk($uuid2, 'Documents', 'Documents')
        )
    );
    edm_test_assert(count(edm_registry_validate($registry)['disks']) === 2, 'valid registry is accepted');
    $decoded = edm_registry_decode(edm_registry_encode($registry));
    edm_test_assert($decoded === $registry, 'JSON encode/decode round-trip preserves registry');
    $passed += 2;

    edm_test_throws(function () use ($registry) {
        $bad = $registry;
        $bad['disks'][1]['luks_uuid'] = $bad['disks'][0]['luks_uuid'];
        $bad['disks'][1]['mapper_name'] = $bad['disks'][0]['mapper_name'];
        edm_registry_validate($bad);
    }, 'duplicate UUID is rejected');
    $passed++;

    edm_test_throws(function () use ($registry) {
        $bad = $registry;
        $bad['disks'][1]['mount_folder'] = 'photography-ARCHIVE';
        edm_registry_validate($bad);
    }, 'duplicate folder is rejected case-insensitively');
    $passed++;

    edm_test_throws(function () {
        edm_registry_decode('[]');
    }, 'JSON array root is rejected');
    $passed++;

    edm_test_throws(function () {
        edm_registry_decode('{"version":1,"disks":[[]]}');
    }, 'array disk entry is rejected');
    $passed++;

    edm_test_throws(function () {
        edm_registry_decode('{"version":1,"disks":[],"unexpected":true}');
    }, 'unexpected root field is rejected');
    $passed++;

    // Persistence and file metadata checks in an isolated root-owned directory.
    $dataDirectory = $temporaryRoot . '/data';
    edm_test_assert(mkdir($dataDirectory, 0700), 'temporary registry directory created');
    chown($dataDirectory, 0);
    chgrp($dataDirectory, 0);
    chmod($dataDirectory, 0700);
    $registryPath = $dataDirectory . '/disks.json';

    edm_test_assert(edm_registry_load($registryPath) === edm_registry_empty(), 'missing registry loads as empty');
    edm_registry_save($registryPath, $registry);
    edm_test_assert(edm_registry_load($registryPath) === $registry, 'saved registry reloads');
    $passed += 3;

    $registryStat = lstat($registryPath);
    $lockStat = lstat($registryPath . '.lock');
    edm_test_assert($registryStat['uid'] === 0 && (($registryStat['mode'] & 0777) === 0600), 'registry file is root-owned mode 0600');
    edm_test_assert($lockStat['uid'] === 0 && (($lockStat['mode'] & 0777) === 0600), 'lock file is root-owned mode 0600');
    $passed += 2;

    // Invalid save must not replace the previously valid registry.
    edm_test_throws(function () use ($registryPath) {
        edm_registry_save($registryPath, array('version' => 1, 'disks' => 'invalid'));
    }, 'invalid registry save is rejected');
    edm_test_assert(edm_registry_load($registryPath) === $registry, 'failed save preserves previous registry');
    $passed += 2;

    // Reject both live and dangling symlinks.
    $liveLink = $dataDirectory . '/live-link.json';
    edm_test_assert(symlink($registryPath, $liveLink), 'live symlink created');
    edm_test_throws(function () use ($liveLink) {
        edm_registry_load($liveLink);
    }, 'registry load rejects symlink');
    unlink($liveLink);
    $passed += 2;

    $danglingLink = $dataDirectory . '/dangling-link.json';
    edm_test_assert(symlink($dataDirectory . '/does-not-exist.json', $danglingLink), 'dangling symlink created');
    edm_test_throws(function () use ($danglingLink) {
        edm_registry_load($danglingLink);
    }, 'registry load rejects dangling symlink');
    edm_test_throws(function () use ($danglingLink, $registry) {
        edm_registry_save($danglingLink, $registry);
    }, 'registry save rejects dangling symlink');
    unlink($danglingLink);
    $passed += 3;

    // A directory with broader permissions must not be accepted.
    $looseDirectory = $temporaryRoot . '/loose';
    edm_test_assert(mkdir($looseDirectory, 0755), 'loose test directory created');
    chmod($looseDirectory, 0755);
    edm_test_throws(function () use ($looseDirectory) {
        edm_registry_load($looseDirectory . '/disks.json');
    }, 'directory mode other than 0700 is rejected');
    $passed += 2;

    echo "PASS: {$passed} registry checks completed.\n";
} catch (Exception $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exitCode:
    exit(1);
} finally {
    // Remove only files created under this unique temporary test directory.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($temporaryRoot);
}
