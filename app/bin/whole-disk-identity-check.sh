#!/bin/sh
# Compare an approved disk identity with a fresh observation.
# Read-only comparison; never authorizes encryption.
set -eu
[ "$#" -eq 6 ] || exit 1
expected_name=$1
expected_id=$2
expected_path=$3
expected_sectors=$4
current_id=$5
current_path_and_sectors=$6

case "$expected_name" in sd?*) ;; *) exit 1 ;; esac
letters=${expected_name#sd}
case "$letters" in ''|*[!a-z]*) exit 1 ;; esac
case "$expected_id" in
    *:*) major=${expected_id%%:*}; minor=${expected_id#*:} ;;
    *) exit 1 ;;
esac
case "$major:$minor" in :*|*:|*[!0-9:]*|*:*:*) exit 1 ;; esac
case "$expected_sectors" in ''|0|*[!0-9]*) exit 1 ;; esac
[ -n "$expected_path" ] || exit 1
[ "$expected_id" = "$current_id" ] || exit 1
[ "$expected_path:$expected_sectors" = "$current_path_and_sectors" ] || exit 1
