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
