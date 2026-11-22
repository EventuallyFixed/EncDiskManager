#!/bin/sh
# Compile-only validation: NEVER runs the native recovery helper.
set -eu
src_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
src="$src_dir/native/disk_recovery_marker.c"
if ! command -v cc >/dev/null 2>&1; then
    echo "SKIP: no host C compiler"
    exit 0
fi
cc -std=c11 -Wall -Wextra -Werror -pedantic -fsyntax-only "$src"
echo "PASS: native recovery marker syntax and warnings"

# A production build must reject a caller-supplied marker directory.
if cc -std=c11 -Wall -Wextra -Werror -pedantic -fsyntax-only \
    -DPRIVATE_DIR='"/tmp/untrusted-marker-directory"' "$src" >/dev/null 2>&1; then
    echo "FAIL: production helper accepted PRIVATE_DIR override"
    exit 1
fi
echo "PASS: production directory override rejected"
