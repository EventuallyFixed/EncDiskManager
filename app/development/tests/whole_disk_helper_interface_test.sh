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
# A clean, well-formed snapshot should be accepted by the busy checker
# (the enclosing helper still refuses encryption).
printf '34 22 8:65 / /mnt/other rw - ext4 /dev/sde1 rw\n' > "$tmpdir/mountinfo"
printf 'Filename Type Size Used Priority\n' > "$tmpdir/swaps"
if ! awk -v mode=mount -v ids='8:48 8:49' -f "$busy_check" "$tmpdir/mountinfo"; then
    echo 'FAIL: clean mount snapshot was rejected' >&2; exit 1
fi
if ! awk -v mode=swap -v disk=/dev/sdd -f "$busy_check" "$tmpdir/swaps"; then
    echo 'FAIL: clean swap snapshot was rejected' >&2; exit 1
fi
# Fake sysfs directories: no real block devices are accessed.
dependencies="$(dirname "$helper")/whole-disk-dependencies.sh"
sh -n "$dependencies"
mkdir -p "$tmpdir/disk/holders" "$tmpdir/disk/slaves" \
    "$tmpdir/disk/sdd1/holders" "$tmpdir/disk/sdd1/slaves"
: > "$tmpdir/disk/sdd1/partition"
if ! sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: idle fake disk rejected' >&2; exit 1
fi
: > "$tmpdir/disk/sdd1/holders/dm-0"
if sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: partition holder ignored' >&2; exit 1
fi
rm "$tmpdir/disk/sdd1/holders/dm-0"
: > "$tmpdir/disk/slaves/dm-0"
if sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: whole-disk slave ignored' >&2; exit 1
fi
rm "$tmpdir/disk/slaves/dm-0"
rmdir "$tmpdir/disk/sdd1/slaves"
if sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: missing dependency directory accepted' >&2; exit 1
fi
# Cover both dependency types at both levels, including sysfs-style links.
mkdir "$tmpdir/disk/sdd1/slaves"
: > "$tmpdir/disk/holders/dm-1"
if sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: whole-disk holder ignored' >&2; exit 1
fi
rm "$tmpdir/disk/holders/dm-1"
ln -s "$tmpdir/disk" "$tmpdir/disk/sdd1/slaves/dm-1"
if sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: partition slave symlink ignored' >&2; exit 1
fi
rm "$tmpdir/disk/sdd1/slaves/dm-1"
if ! sh "$dependencies" "$tmpdir/disk"; then
    echo 'FAIL: restored idle disk rejected' >&2; exit 1
fi
identity="$(dirname "$helper")/whole-disk-identity-check.sh"
sh -n "$identity"
if ! sh "$identity" sdd 8:48 /sys/devices/usb3/sdd 30031250 8:48 /sys/devices/usb3/sdd 30031250; then
    echo 'FAIL: matching identity rejected' >&2; exit 1
fi
for changed in '8:64 /sys/devices/usb3/sdd 30031250' \
    '8:48 /sys/devices/usb4/sdd 30031250' \
    '8:48 /sys/devices/usb3/sdd 30031251'; do
    # Deliberately split the three fixture fields.
    if sh "$identity" sdd 8:48 /sys/devices/usb3/sdd 30031250 $changed; then
        echo 'FAIL: changed disk identity accepted' >&2; exit 1
    fi
done
if sh "$identity" sdd1 8:48 /sys/devices/usb3/sdd 30031250 8:48 /sys/devices/usb3/sdd 30031250; then
    echo 'FAIL: partition identity accepted' >&2; exit 1
fi
echo 'PASS: disabled helper validates input, busy disks, dependencies and identity fixtures'
