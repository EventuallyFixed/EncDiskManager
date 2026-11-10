#!/bin/sh
# Read-only ARM dynamic linker and libc inventory. No application execution.
set -u
echo '=== ARM dynamic linker ==='
for path in /lib/ld-linux-armhf.so.3 /lib/arm-linux-gnueabihf/ld-linux-armhf.so.3; do
  if [ -e "$path" ]; then
    ls -l "$path"
  else
    echo "$path: absent"
  fi
done
echo '=== C library locations ==='
for path in /lib/arm-linux-gnueabihf/libc.so.6 /lib/libc.so.6 /usr/lib/arm-linux-gnueabihf/libc.so.6; do
  if [ -e "$path" ]; then
    ls -l "$path"
  else
    echo "$path: absent"
  fi
done
echo '=== libc version (read-only system command) ==='
if command -v getconf >/dev/null 2>&1; then
  getconf GNU_LIBC_VERSION 2>/dev/null || echo 'GNU libc version unavailable'
else
  echo 'getconf unavailable'
fi
echo '=== End: no changes made ==='
