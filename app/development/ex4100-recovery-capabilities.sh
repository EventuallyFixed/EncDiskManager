#!/bin/sh
# Read-only EX4100 capability inventory for internal operation-journal design.
# No cryptsetup, mount, USB writes, or application configuration changes.
set -u
echo '=== Kernel / architecture ==='
uname -srm 2>/dev/null || true
echo '=== PHP CLI ==='
if command -v php >/dev/null 2>&1; then
  php -r 'echo "PHP_VERSION=", PHP_VERSION, "\n"; foreach (array("fsync", "fdatasync", "posix_geteuid", "proc_open", "proc_get_status", "proc_terminate", "proc_close") as $name) echo $name, "=", function_exists($name) ? "yes" : "no", "\n";'
else
  echo 'PHP CLI unavailable'
fi
echo '=== Native compiler / synchronization tools (presence only) ==='
for tool in cc gcc arm-linux-gnueabihf-gcc sync stat findmnt; do
  if command -v "$tool" >/dev/null 2>&1; then
    echo "$tool: available"
  else
    echo "$tool: unavailable"
  fi
done
echo '=== App data filesystem (read-only metadata) ==='
data_dir='/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager'
if [ -d "$data_dir" ]; then
  if command -v stat >/dev/null 2>&1; then
    stat "$data_dir" 2>/dev/null || true
  fi
  if command -v df >/dev/null 2>&1; then
    df -T "$data_dir" 2>/dev/null || df "$data_dir" 2>/dev/null || true
  fi
else
  echo 'Private application data directory not present'
fi
echo '=== End: no changes made ==='
