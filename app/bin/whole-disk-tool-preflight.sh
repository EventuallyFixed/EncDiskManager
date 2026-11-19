#!/bin/sh
# Check runtime tools required by the planned whole-disk LUKS2 + ext4 workflow.
# Read-only: this script never opens or modifies a disk.
set -eu
missing=0
require() {
    if [ -x "$1" ]; then
        printf 'OK      %s\n' "$1"
    else
        printf 'MISSING %s\n' "$1"
        missing=1
    fi
}
require /usr/local/modules/usr/sbin/cryptsetup
require /usr/bin/blkid
require /usr/bin/blockdev
require /usr/bin/readlink
require /bin/mount
require /bin/umount

# Some WD OS5 builds place these utilities in different directories.
find_tool() {
    name=$1
    for directory in /sbin /usr/sbin /bin /usr/bin /usr/local/sbin /usr/local/bin; do
        if [ -x "$directory/$name" ]; then
            printf 'OK      %s\n' "$directory/$name"
            return 0
        fi
    done
    printf 'MISSING %s (must be provided in the package if absent)\n' "$name"
    missing=1
}
find_tool sfdisk
find_tool mkfs.ext4
[ "$missing" -eq 0 ] || exit 1
printf 'All required executable paths were found.\n'
