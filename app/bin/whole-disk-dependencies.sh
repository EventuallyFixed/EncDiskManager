#!/bin/sh
# Read-only dependency check for a sysfs whole disk and its partitions.
# A nonempty holders/slaves directory means the device is in use.
set -eu
[ "$#" -eq 1 ] && [ -d "$1" ] || exit 1
disk=$1
for device in "$disk" "$disk"/*; do
    [ "$device" = "$disk" ] || [ -f "$device/partition" ] || continue
    for kind in holders slaves; do
        directory="$device/$kind"
        [ -d "$directory" ] && [ -r "$directory" ] || exit 1
        for entry in "$directory"/*; do
            [ ! -e "$entry" ] && [ ! -L "$entry" ] || exit 1
        done
    done
done
