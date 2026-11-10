#!/bin/sh
# Harmless root-only runtime test in an isolated temporary directory.
# Never run the production fixed-path helper.
set -eu
if [ "$(id -u)" -ne 0 ]; then
  echo "SKIP: isolated native helper runtime test requires root"
  exit 0
fi
if ! command -v cc >/dev/null 2>&1; then
  echo "SKIP: no host C compiler"
  exit 0
fi
src_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT HUP INT TERM
chmod 700 "$tmp"
mkdir "$tmp/private"
chmod 700 "$tmp/private"
marker="$tmp/private/disk-operation-recovery-required"
cc -std=c11 -Wall -Wextra -Werror -pedantic -DPRIVATE_DIR="\"$tmp/private\"" \
  "$src_dir/native/disk_recovery_marker.c" -o "$tmp/marker-helper"
"$tmp/marker-helper" mark
test -f "$marker"
test ! -L "$marker"
test "$(stat -c %u "$marker")" = 0
test "$(stat -c %a "$marker")" = 600
grep -qx 'recovery-required' "$marker"
echo "PASS: private marker created in isolated directory"
if "$tmp/marker-helper" mark >/dev/null 2>&1; then
  echo "FAIL: existing marker accepted"
  exit 1
fi
echo "PASS: existing marker rejected"
rm "$marker"
ln -s /dev/null "$marker"
if "$tmp/marker-helper" mark >/dev/null 2>&1; then
  echo "FAIL: symlink marker accepted"
  exit 1
fi
echo "PASS: symlink marker rejected"
rm "$marker"
chmod 755 "$tmp/private"
if "$tmp/marker-helper" mark >/dev/null 2>&1; then
  echo "FAIL: unsafe directory accepted"
  exit 1
fi
test ! -e "$marker"
echo "PASS: unsafe directory rejected"
