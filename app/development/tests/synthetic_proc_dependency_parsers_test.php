<?php
require_once __DIR__ . '/../../lib/synthetic_proc_dependency_parsers.php';
function check_proc_parse($ok, $label) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
$mount = "31 22 8:17 / /mnt/usb rw,relatime - ext4 /dev/sdb1 rw\n";
$parsed = edm_synthetic_mountinfo_parse($mount);
check_proc_parse(is_array($parsed) && count($parsed) === 1
    && $parsed[0]['dev'] === '8:17', 'Parse synthetic mountinfo major minor');
check_proc_parse(edm_synthetic_mountinfo_parse('') === array(),
    'Represent explicitly empty synthetic mountinfo');
foreach (array(
    "31 22 08:17 / /mnt/usb rw - ext4 /dev/sdb1 rw\n",
    "31 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1\n",
    "31 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw",
    "31 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw\n" .
        "31 22 8:18 / /mnt/other rw - ext4 /dev/sdb2 rw\n",
    "31 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw\n\n",
) as $invalid) {
    check_proc_parse(edm_synthetic_mountinfo_parse($invalid) === false,
        'Reject malformed synthetic mountinfo');
}
$header = "Filename\tType\tSize\tUsed\tPriority\n";
check_proc_parse(edm_synthetic_swaps_parse($header) === array(),
    'Parse empty synthetic swaps table');
$swap = $header . "/dev/sdb2 partition 1024 0 -2\n";
$parsed = edm_synthetic_swaps_parse($swap);
check_proc_parse(is_array($parsed) && count($parsed) === 1
    && $parsed[0]['path'] === '/dev/sdb2',
    'Parse synthetic swap record without inferring block identity');
foreach (array(
    '',
    "Filename Type Size Used Priority",
    $header . "/dev/sdb2 partition bad 0 -2\n",
    $header . "/dev/sdb2 unknown 1024 0 -2\n",
    $header . "relative/path file 1024 0 -2\n",
    $swap . "/dev/sdb2 partition 1024 0 -2\n",
    $header . "\n",
) as $invalid) {
    check_proc_parse(edm_synthetic_swaps_parse($invalid) === false,
        'Reject malformed synthetic swaps input');
}

$escapedMount = "32 22 8:17 / /mnt/USB\\\\040Disk rw - ext4 /dev/sdb1 rw\n";
$escapedParsed = edm_synthetic_mountinfo_parse($escapedMount);
check_proc_parse(is_array($escapedParsed) && count($escapedParsed) === 1,
    'Accept kernel-style escaped whitespace in synthetic mountpoint');
foreach (array(
    "32 22 8:17 / /mnt/bad\\\\999 rw - ext4 /dev/sdb1 rw\n",
    "32 22 8:17 / /mnt/bad\\\\ rw - ext4 /dev/sdb1 rw\n",
    "32 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw - extra\n",
    "32 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw\n" .
        "32 22 8:18 / /mnt/other rw - ext4 /dev/sdb2 rw\n",
    "32 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw\n" .
        "33 22 8:18 / /mnt/other rw - ext4 /dev/sdb2\n",
) as $invalid) {
    check_proc_parse(edm_synthetic_mountinfo_parse($invalid) === false,
        'Reject invalid escape duplicate mount ID or incomplete record');
}
check_proc_parse(edm_synthetic_mountinfo_parse("32 22 8:17 / /mnt/usb rw - ext4 /dev/sdb1 rw\n" .
    "33 22 8:18 / /mnt/other rw - ext4 /dev/sdb2 rw\n") !== false,
    'Accept distinct well-formed synthetic mount IDs');
