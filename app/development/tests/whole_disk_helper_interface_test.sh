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
echo 'PASS: whole-disk helper remains disabled and rejects all requests'
