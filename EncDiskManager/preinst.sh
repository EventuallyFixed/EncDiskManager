#!/bin/sh

# Called only during WD OS5 application reinstallation, before remove.sh.
. "$1/helpers.sh" "$0" "$1";

set -eu
DATA_DIR="$APP_PATH/data"
BACKUP_DIR="/tmp/encdiskmanager-upgrade"

if [ ! -e "$DATA_DIR" ] && [ ! -L "$DATA_DIR" ]; then
    log "No existing private data directory; nothing to preserve."
    exit 0
fi

if [ -L "$DATA_DIR" ] || [ ! -d "$DATA_DIR" ]; then
    log "ERROR: Existing private data path is not a real directory; refusing upgrade."
    exit 1
fi
if [ "$(stat -c %u "$DATA_DIR")" != "0" ] || [ "$(stat -c %a "$DATA_DIR")" != "700" ]; then
    log "ERROR: Existing private data directory ownership or permissions are unsafe; refusing upgrade."
    exit 1
fi

# Never reuse or overwrite an earlier backup. It may be the only recoverable copy.
if [ -e "$BACKUP_DIR" ] || [ -L "$BACKUP_DIR" ]; then
    log "ERROR: A previous upgrade backup already exists at $BACKUP_DIR; refusing to overwrite it."
    exit 1
fi

# This marker tells remove.sh that deletion is part of an upgrade and
# must not proceed unless the handover backup is complete.
umask 077
printf '%s\\n' "pending" > "$APP_PATH/.edm-upgrade-preserve"
chown root:root "$APP_PATH/.edm-upgrade-preserve"
chmod 600 "$APP_PATH/.edm-upgrade-preserve"

if ! mkdir "$BACKUP_DIR"; then
    log "ERROR: Could not create temporary upgrade backup directory."
    exit 1
fi
chown root:root "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

for FILE in disks.json admin.json; do
    SOURCE="$DATA_DIR/$FILE"
    if [ -L "$SOURCE" ]; then
        log "ERROR: Refusing to preserve symbolic link $SOURCE."
        exit 1
    fi
    if [ -e "$SOURCE" ]; then
        if [ ! -f "$SOURCE" ] || [ "$(stat -c %u "$SOURCE")" != "0" ] || [ "$(stat -c %a "$SOURCE")" != "600" ]; then
            log "ERROR: Refusing to preserve unsafe private file $SOURCE."
            exit 1
        fi
        cp -p "$SOURCE" "$BACKUP_DIR/$FILE"
        chown root:root "$BACKUP_DIR/$FILE"
        chmod 600 "$BACKUP_DIR/$FILE"
    fi
done

printf '%s\n' "$APP_PATH" > "$BACKUP_DIR/source-path"
chown root:root "$BACKUP_DIR/source-path"
chmod 600 "$BACKUP_DIR/source-path"
printf '%s\n' "complete" > "$BACKUP_DIR/backup-complete"
chown root:root "$BACKUP_DIR/backup-complete"
chmod 600 "$BACKUP_DIR/backup-complete"

log "Private disk registry and administrator credentials safely preserved for upgrade."
