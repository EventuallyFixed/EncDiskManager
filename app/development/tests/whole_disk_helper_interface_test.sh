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
# Keep this helper independent from PHP discovery, but prevent it from
# accidentally acquiring destructive commands before review and enablement.
if grep -En '(^|[[:space:]/])(cryptsetup|sfdisk|fdisk|parted|wipefs|mkfs([.]ext4)?|dd)([[:space:]]|$)' "$helper"; then
    echo 'FAIL: destructive command found in disabled helper' >&2
    exit 1
fi
# Exercise the exact read-only awk logic used by the helper with fake /proc data.
busy_check="$(dirname "$helper")/whole-disk-busy-check.awk"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT HUP INT TERM
printf '31 22 8:49 / /mnt/usb rw - vfat /dev/sdd1 rw\n' > "$tmpdir/mountinfo"
if awk -v mode=mount -v ids='8:48 8:49' -f "$busy_check" "$tmpdir/mountinfo"; then
    echo 'FAIL: mounted partition not detected' >&2; exit 1
fi
if ! awk -v mode=mount -v ids='8:64 8:65' -f "$busy_check" "$tmpdir/mountinfo"; then
    echo 'FAIL: unrelated mount was rejected' >&2; exit 1
fi
printf 'Filename Type Size Used Priority\n/dev/sdd1 partition 1024 0 -2\n' > "$tmpdir/swaps"
if awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/swaps"; then
    echo 'FAIL: active partition swap not detected' >&2; exit 1
fi
if ! awk -v mode=swap -v disk=/dev/sde -f "$busy_check" "$tmpdir/swaps"; then
    echo 'FAIL: unrelated swap was rejected' >&2; exit 1
fi
# Missing or malformed kernel data must never be treated as idle.
: > "$tmpdir/empty"
if awk -v mode=mount -v ids='8:48' -f "$busy_check" "$tmpdir/empty"; then
    echo 'FAIL: empty mountinfo accepted' >&2; exit 1
fi
if awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/empty"; then
    echo 'FAIL: empty swaps accepted' >&2; exit 1
fi
printf 'not-a-swap-header\n' > "$tmpdir/bad-swaps"
if awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/bad-swaps"; then
    echo 'FAIL: invalid swaps header accepted' >&2; exit 1
fi
printf 'Filename Type Size Used Priority\n/dev/sdde1 partition 1024 0 -2\n' > "$tmpdir/swaps"
if ! awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/swaps"; then
    echo 'FAIL: unrelated disk prefix treated as swap' >&2; exit 1
fi
# Whole-disk mounts and malformed mount records must also be rejected.
printf '32 22 8:48 / /mnt/usb rw - ext4 /dev/sdd rw\n' > "$tmpdir/mountinfo"
if awk -v mode=mount -v ids='8:48 8:49' -f "$busy_check" "$tmpdir/mountinfo"; then
    echo 'FAIL: mounted whole disk not detected' >&2; exit 1
fi
printf 'broken mount record\n' > "$tmpdir/mountinfo"
if awk -v mode=mount -v ids='8:48' -f "$busy_check" "$tmpdir/mountinfo"; then
    echo 'FAIL: malformed mount record accepted' >&2; exit 1
fi
printf 'Filename Type Size Used Priority\n/dev/sdd partition 1024 0 -2\n' > "$tmpdir/swaps"
if awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/swaps"; then
    echo 'FAIL: whole-disk swap not detected' >&2; exit 1
fi
# A mount source may be a UUID alias; kernel major:minor must still catch it.
printf '33 22 8:49 / /mnt/usb rw - ext4 /dev/disk/by-uuid/example rw\n' > "$tmpdir/mountinfo"
if awk -v mode=mount -v ids='8:48 8:49' -f "$busy_check" "$tmpdir/mountinfo"; then
    echo 'FAIL: UUID-aliased mount not detected' >&2; exit 1
fi
# A similarly prefixed disk is not a partition of the selected disk.
printf 'Filename Type Size Used Priority\n/dev/sdde1 partition 1024 0 -2\n' > "$tmpdir/swaps"
if ! awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/swaps"; then
    echo 'FAIL: similarly prefixed disk treated as selected swap' >&2; exit 1
fi
echo 'PASS: disabled helper validates input, busy disks, and rejects all operations'
