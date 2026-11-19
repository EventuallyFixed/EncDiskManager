#!/bin/sh
# Read-only dependency preflight for whole-disk LUKS2 + ext4 formatting.
# Run on the NAS before enabling destructive operations.
set -eu

missing=0
require_path() {
    if [ -x "$1" ]; then
        printf 'OK      %s\n' "$1"
    else
        printf 'MISSING %s\n' "$1"
        missing=1
    fi
}
find_tool() {
    label=$1
    shift
    for name in "$@"; do
        for directory in /sbin /usr/sbin /bin /usr/bin /usr/local/sbin /usr/local/bin; do
            if [ -x "$directory/$name" ]; then
                printf 'OK      %s: %s\n' "$label" "$directory/$name"
                return 0
            fi
        done
    done
    printf 'MISSING %s (must be bundled or supplied on NAS)\n' "$label"
    missing=1
}

require_path /usr/local/modules/usr/sbin/cryptsetup
require_path /usr/bin/blkid
require_path /usr/bin/blockdev
require_path /usr/bin/readlink
require_path /bin/mount
require_path /bin/umount

# The workflow needs a partition table writer and an ext4 formatter.
# mke2fs -t ext4 is equivalent to mkfs.ext4 when e2fsprogs is installed.
find_tool partitioner sfdisk
find_tool ext4_formatter mkfs.ext4 mke2fs
find_tool partition_reread partprobe blockdev

[ "$missing" -eq 0 ] || exit 1
printf 'Required executable paths found. No disk was modified.\n'
