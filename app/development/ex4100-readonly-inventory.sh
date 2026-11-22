#!/bin/sh
# Read-only EX4100 environment inventory for manual security review.
# Does not invoke cryptsetup, mount, umount, blkid, or any disk-changing command.
set -eu

printf '%s\n' 'EncDiskManager v0.6.0 read-only EX4100 inventory'
printf 'Kernel: '; uname -srmo 2>/dev/null || uname -a
printf 'Shell UID: '; id -u
printf 'PHP CLI: '
if command -v php >/dev/null 2>&1; then
    php -r 'echo PHP_VERSION, " / CLI effective UID: ", function_exists("posix_geteuid") ? posix_geteuid() : "unavailable", PHP_EOL;'
else
    printf '%s\n' 'unavailable'
fi
printf '%s\n' 'NOTE: CLI UID does not establish PHP-FPM worker UID.'

printf '\n%s\n' 'Executable and parent path metadata (no binaries executed):'
for path in /usr /usr/sbin /usr/sbin/cryptsetup \
    /usr/local /usr/local/modules /usr/local/modules/usr \
    /usr/local/modules/usr/sbin /usr/local/modules/usr/sbin/cryptsetup \
    /bin /bin/mount /bin/umount
do
    printf '%s\n' "PATH: $path"
    if [ -L "$path" ]; then
        printf '%s\n' '  type: symlink'
        ls -ldn "$path" 2>/dev/null || :
        if command -v readlink >/dev/null 2>&1; then
            printf '  target: '
            readlink "$path" 2>/dev/null || printf '%s\n' 'unavailable'
        fi
    elif [ -e "$path" ]; then
        ls -ldn "$path" 2>/dev/null || :
    else
        printf '%s\n' '  absent'
    fi
done

printf '\n%s\n' 'PHP-FPM process identity (status/comm only, no command lines):'
found=0
for status in /proc/[0-9]*/status; do
    [ -r "$status" ] || continue
    pid=${status%/status}
    pid=${pid##*/}
    [ -r "/proc/$pid/comm" ] || continue
    IFS= read -r comm < "/proc/$pid/comm" || continue
    case "$comm" in
        php-fpm|php-fpm[0-9]*)
            found=1
            printf 'PID %s (%s)\n' "$pid" "$comm"
            grep -E '^(Name|Uid|Gid):' "$status" 2>/dev/null || :
            if [ -r "/proc/$pid/ns/mnt" ] && command -v readlink >/dev/null 2>&1; then
                printf 'Mount namespace: '
                readlink "/proc/$pid/ns/mnt" 2>/dev/null || printf '%s\n' 'unavailable'
            fi
            ;;
    esac
done
[ "$found" -eq 1 ] || printf '%s\n' 'No readable php-fpm process found (not proof of absence).'

printf '\n%s\n' 'No disk operations performed. Review output before any executor work.'
