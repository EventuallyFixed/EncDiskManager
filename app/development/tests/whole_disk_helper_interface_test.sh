#!/bin/sh
# Contract tests only. The helper must reject every request without disk I/O.
set -eu
helper="$(dirname "$0")/../../bin/encdiskmanager-whole-disk-helper"
test -f "$helper"
sh -n "$helper"
for name in /dev/sdb /dev/sdd /dev/sdaa /dev/sdb1 /dev/mapper/test /dev/sdB /dev/sda/../sdb; do
    if sh "$helper" check "$name" >/dev/null 2>&1; then
        echo "FAIL: helper accepted $name" >&2
        exit 1
    fi
done
for command in erase encrypt format unlock mount; do
    if sh "$helper" "$command" /dev/sdb >/dev/null 2>&1; then
        echo "FAIL: helper accepted $command" >&2
        exit 1
    fi
done
if sh "$helper" check >/dev/null 2>&1; then
    echo 'FAIL: helper accepted missing disk' >&2
    exit 1
fi
# Assert the helper actually parses device names and reaches its read-only
# block-device check; a blanket failure would not test the interface.
invalid="$(sh "$helper" check /dev/sdb1 2>&1 || true)"
case "$invalid" in
    *'Expected a whole /dev/sdX USB disk name.'*) ;;
    *) echo 'FAIL: partition name was not rejected at the input boundary' >&2; exit 1 ;;
esac
nonblock="$(sh "$helper" check /dev/sdzzzz 2>&1 || true)"
case "$nonblock" in
    *'Selected path is not a block device.'*) ;;
    *) echo 'FAIL: valid-shaped missing disk did not reach the block-device check' >&2; exit 1 ;;
esac
echo 'PASS: disabled helper validates input and rejects all operations'
