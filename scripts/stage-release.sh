#!/bin/bash
set -euo pipefail

SOURCE="$(cd "$(dirname "$0")/.." && pwd)"
BUILDER="${BUILDER:-/home/steven/Nextcloud/src/NAS_Apps/WD-NAS-App-Builder}"
STAGE="${1:?Usage: scripts/stage-release.sh NEW_STAGE_DIRECTORY}"

if [ -e "$STAGE" ]; then
    echo "ERROR: Staging directory already exists: $STAGE"
    exit 1
fi

mkdir -p "$STAGE"
cp -a "$BUILDER/." "$STAGE/"

rm -rf "$STAGE/apps/EncDiskManager"
mkdir -p "$STAGE/apps/EncDiskManager"

git -C "$SOURCE" archive HEAD:app |
    tar -x -C "$STAGE/apps/EncDiskManager"

rm -rf "$STAGE/apps/EncDiskManager/development"

echo "Release staging prepared: $STAGE"
grep '^Version:' "$STAGE/apps/EncDiskManager/apkg.rc"
