<?php
require_once __DIR__ . '/../../lib/whole_usb_disk_confirmation.php';

$cases = array(
    array(false, '', false),
    array(true, '', false),
    array(false, 'ERASE ALL DATA', false),
    array(true, 'erase all data', false),
    array(true, 'ERASE ALL DATA ', false),
    array(true, 'ERASE ALL DATA', true)
);
foreach ($cases as $case) {
    if (edm_whole_disk_erase_confirmed($case[0], $case[1]) !== $case[2]) {
        fwrite(STDERR, "FAIL: Erase acknowledgement validation\n");
        exit(1);
    }
}
echo "PASS: Both exact erase acknowledgements are required\n";
