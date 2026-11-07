<?php
require_once __DIR__ . '/../../lib/secure_mount_directory.php';
function check_secure_dir($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
check_secure_dir(!edm_secure_mount_directory('../etc'), 'Traversal rejected');
check_secure_dir(!edm_secure_mount_directory('with/slash'), 'Nested path rejected');
check_secure_dir(!edm_secure_mount_directory(''), 'Empty folder rejected');
check_secure_dir(!edm_secure_mount_directory(str_repeat('a', 49)),
    'Oversized folder rejected');
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $folder = 'edm_test_' . getmypid();
    $path = '/mnt/' . $folder;
    try {
        check_secure_dir(edm_secure_mount_directory($folder),
            'Root creates private mount directory');
        check_secure_dir(edm_secure_mount_directory($folder),
            'Existing safe directory accepted');
        $stat = lstat($path);
        check_secure_dir(($stat['mode'] & 0777) === 0700,
            'Created directory is private');
    } finally { @rmdir($path); }
} else {
    check_secure_dir(!edm_secure_mount_directory('edm_never_create'),
        'Non-root creation rejected');
}
