#!/bin/bash
. "../build_helpers.sh"

# The shared helper temporarily moves this script out of APP_PATH. Restore it
# on normal exit and on build errors so an interrupted build does not leave
# the application checkout without its build instructions.
trap 'restore_files' EXIT

# Some models use AMD64 architecture and others are ARM
declare -A MODELS
MODELS[amd64]="MyCloudPR4100 MyCloudPR2100 WDMyCloudDL4100 WDMyCloudDL2100"
MODELS[armhf]="WDCloud WDMyCloud WDMyCloudMirror WDMyCloudEX4100 WDMyCloudEX2100 MyCloudEX2Ultra"

for ARCH in "${!MODELS[@]}"; do
	# Build the archive for all models of this architecture
	build ${MODELS[${ARCH}]} ${ARCH}
done

# Cleanup
. "../build_helpers.sh"
