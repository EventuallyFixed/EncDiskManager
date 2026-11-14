# Read-only busy-device check, shared by the helper and fixture tests.
# Usage: awk -v mode=mount -v ids="8:48 8:49" -f this-file mountinfo
#        awk -v mode=swap -v disk=/dev/sdd -f this-file swaps
BEGIN {
    if (mode == "mount") {
        n = split(ids, a, " ")
        for (i = 1; i <= n; i++) wanted[a[i]] = 1
    } else if (mode != "swap") {
        invalid = 1
    }
}
mode == "mount" {
    if (NF < 3) invalid = 1
    if (wanted[$3]) busy = 1
}
mode == "swap" {
    if (NR == 1) {
        if ($1 != "Filename" || $2 != "Type") invalid = 1
        next
    }
    if (NF < 2) invalid = 1
    if ($1 == disk || (index($1, disk) == 1 &&
        substr($1, length(disk) + 1) ~ /^[0-9]+$/)) busy = 1
}
END {
    if (NR == 0 || busy || invalid) exit 1
}
