<?php
require_once __DIR__ . '/../../lib/whole_disk_read_only_check.php';

function assert_read_only_rejected($arguments, $description) {
    if (edm_whole_disk_read_only_check($arguments) !== false) {
        fwrite(STDERR, "FAIL: $description\n");
        exit(1);
    }
}
assert_read_only_rejected(array(), 'Missing arguments');
assert_read_only_rejected(array('encrypt', '/dev/sdd', '8:48',
    '/sys/devices/usb3/sdd', '30031250', ''), 'Destructive command');
assert_read_only_rejected(array('check', '/dev/sdd'), 'Incomplete identity');
assert_read_only_rejected(array('check', '/dev/sdd', '8:48',
    '/sys/devices/usb3/sdd', '30031250', array()), 'Non-string argument');
assert_read_only_rejected(array('check', '/dev/sdzzzz', '8:48',
    '/sys/devices/usb3/sdzzzz', '30031250', ''), 'Missing block device');
echo "PASS: read-only helper runner fails closed for invalid requests\n";
