#!/bin/sh

# Called upon app removal
	#	 1. $INSTALL_PATH/stop.sh
	#	 2. $INSTALL_PATH/clean.sh
	# -> 3. $INSTALL_PATH/remove.sh $INSTALL_PATH

# Called upon app reinstallation
	#	 1. $INSTALL_PATH/stop.sh
	#	 2. $INSTALL_PATH/clean.sh
	#	 3. $INSTALL_PATH/preinst.sh $INSTALL_PATH
	# -> 4. $INSTALL_PATH/remove.sh $INSTALL_PATH
	#	 5. $UPLOAD_PATH/install.sh $UPLOAD_PATH $INSTALL_PATH
	#	 6. $INSTALL_PATH/init.sh $INSTALL_PATH
	#	 7. $INSTALL_PATH/start.sh $INSTALL_PATH

# Load all the useful variables
. "$1/helpers.sh" "$0" "$1";

# ----------------------------------------------------------------------------
# Removes all the app completely. 
#  - Remember to make backups of configuration / data here to support upgrades
# ----------------------------------------------------------------------------

PRESERVE_MARKER="$APP_PATH/.edm-upgrade-preserve"
if [ -e "$PRESERVE_MARKER" ] || [ -L "$PRESERVE_MARKER" ]; then
    BACKUP_DIR="/tmp/encdiskmanager-upgrade"
    if [ -L "$PRESERVE_MARKER" ] || [ ! -f "$PRESERVE_MARKER" ] ||
        [ "$(stat -c %u "$PRESERVE_MARKER")" != "0" ] ||
        [ "$(stat -c %a "$PRESERVE_MARKER")" != "600" ] ||
        [ "$(cat "$PRESERVE_MARKER")" != "pending" ] ||
        [ ! -d "$BACKUP_DIR" ] || [ -L "$BACKUP_DIR" ] ||
        [ "$(stat -c %u "$BACKUP_DIR")" != "0" ] ||
        [ "$(stat -c %a "$BACKUP_DIR")" != "700" ] ||
        [ ! -f "$BACKUP_DIR/backup-complete" ] ||
        [ -L "$BACKUP_DIR/backup-complete" ] ||
        [ "$(stat -c %u "$BACKUP_DIR/backup-complete")" != "0" ] ||
        [ "$(stat -c %a "$BACKUP_DIR/backup-complete")" != "600" ] ||
        [ "$(cat "$BACKUP_DIR/backup-complete")" != "complete" ] ||
        [ ! -f "$BACKUP_DIR/source-path" ] ||
        [ -L "$BACKUP_DIR/source-path" ] ||
        [ "$(stat -c %u "$BACKUP_DIR/source-path")" != "0" ] ||
        [ "$(stat -c %a "$BACKUP_DIR/source-path")" != "600" ] ||
        [ "$(cat "$BACKUP_DIR/source-path")" != "$APP_PATH" ]; then
        log "ERROR: Upgrade configuration backup is missing or invalid; refusing to delete the existing application."
        exit 1
    fi
    log "Verified temporary configuration backup; proceeding with upgrade replacement."
fi

log "Removing all files from: ${APP_PATH}";
rm -rf "${APP_PATH}";