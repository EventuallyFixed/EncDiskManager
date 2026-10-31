#!/bin/sh

# Called upon app installation
	#	 1. $UPLOAD_PATH/install.sh $UPLOAD_PATH $INSTALL_PATH
	# -> 2. $INSTALL_PATH/init.sh $INSTALL_PATH
	#	 3. $INSTALL_PATH/start.sh $INSTALL_PATH

# Called upon app reinstallation
	#	 1. $INSTALL_PATH/stop.sh
	#	 2. $INSTALL_PATH/clean.sh
	#	 3. $INSTALL_PATH/preinst.sh $INSTALL_PATH
	#	 4. $INSTALL_PATH/remove.sh $INSTALL_PATH
	#	 5. $UPLOAD_PATH/install.sh $UPLOAD_PATH $INSTALL_PATH
	# -> 6. $INSTALL_PATH/init.sh $INSTALL_PATH
	#	 7. $INSTALL_PATH/start.sh $INSTALL_PATH

# Load all the useful variables
. "$1/helpers.sh" "$0" "$1";

# ----------------------------------------------------------------------
# Initialisation script: prepares app icon, index page and paths
#  - Use this to restore custom paths and settings on reinstall / reboot
# ----------------------------------------------------------------------

# Create folder for the webpage
log "creating web path: ${APP_WEB_PATH}"
mkdir -p "${APP_WEB_PATH}"

log "linking redirect page from: ${APP_PATH}/web/* to: ${APP_WEB_PATH}"
ln -sf "${APP_PATH}"/web/* "${APP_WEB_PATH}" >> "${LOG}" 2>&1

# Protect the installed app tree: PHP-FPM runs as root on this NAS.
# Keep web assets readable, app scripts executable, and the helper private.
if [ -d "${APP_PATH}" ]; then
    chown -R root:root "${APP_PATH}"
    find "${APP_PATH}" -type d -exec chmod 755 {} \;
    find "${APP_PATH}" -type f -exec chmod 644 {} \;

    for script in install.sh init.sh start.sh stop.sh clean.sh preinst.sh remove.sh apkg_helpers.sh helpers.sh; do
        [ ! -f "${APP_PATH}/${script}" ] || chmod 755 "${APP_PATH}/${script}"
    done

    HELPER_DIR="${APP_PATH}/bin"
    HELPER="${HELPER_DIR}/encdiskmanager-helper"
    if [ -f "${HELPER}" ]; then
        chmod 700 "${HELPER_DIR}" "${HELPER}"
        log "Secured EncDiskManager helper at ${HELPER}"
    else
        log "WARNING: EncDiskManager helper not found at ${HELPER}"
    fi
fi

# Create a private data directory inside the application tree.
# It is deliberately outside web/ and protected from web access.
# users should download a configuration backup before removing the app.
REGISTRY_DIR="${APP_PATH}/data"
if [ -L "${REGISTRY_DIR}" ]; then
    log "ERROR: Registry data directory must not be a symbolic link: ${REGISTRY_DIR}"
    exit 1
fi

if mkdir -p "${REGISTRY_DIR}" &&
    chown root:root "${REGISTRY_DIR}" &&
    chmod 700 "${REGISTRY_DIR}"; then
    log "Secured private app-local registry directory at ${REGISTRY_DIR}"
else
    log "ERROR: Could not create or secure registry directory: ${REGISTRY_DIR}"
    exit 1
fi

# Restore the private registry and administrator credentials after an upgrade.
# The preinst script creates this root-only handover directory before remove.sh.
UPGRADE_BACKUP="/tmp/encdiskmanager-upgrade"
if [ -e "$UPGRADE_BACKUP" ] || [ -L "$UPGRADE_BACKUP" ]; then
    if [ -L "$UPGRADE_BACKUP" ] || [ ! -d "$UPGRADE_BACKUP" ] ||
        [ "$(stat -c %u "$UPGRADE_BACKUP")" != "0" ] ||
        [ "$(stat -c %a "$UPGRADE_BACKUP")" != "700" ] ||
        [ ! -f "$UPGRADE_BACKUP/backup-complete" ] ||
        [ -L "$UPGRADE_BACKUP/backup-complete" ] ||
        [ "$(stat -c %u "$UPGRADE_BACKUP/backup-complete")" != "0" ] ||
        [ "$(stat -c %a "$UPGRADE_BACKUP/backup-complete")" != "600" ] ||
        [ "$(cat "$UPGRADE_BACKUP/backup-complete")" != "complete" ] ||
        [ ! -f "$UPGRADE_BACKUP/source-path" ] ||
        [ -L "$UPGRADE_BACKUP/source-path" ] ||
        [ "$(stat -c %u "$UPGRADE_BACKUP/source-path")" != "0" ] ||
        [ "$(stat -c %a "$UPGRADE_BACKUP/source-path")" != "600" ] ||
        [ "$(cat "$UPGRADE_BACKUP/source-path")" != "$APP_PATH" ]; then
        log "ERROR: Temporary upgrade backup failed validation; it has been left untouched."
        exit 1
    fi

    for FILE in disks.json admin.json; do
        SOURCE="$UPGRADE_BACKUP/$FILE"
        if [ -L "$SOURCE" ]; then
            log "ERROR: Upgrade backup contains an unexpected symbolic link: $SOURCE"
            exit 1
        fi
        if [ -e "$SOURCE" ]; then
            if [ ! -f "$SOURCE" ] || [ "$(stat -c %u "$SOURCE")" != "0" ] || [ "$(stat -c %a "$SOURCE")" != "600" ]; then
                log "ERROR: Upgrade backup file failed ownership or permission checks: $SOURCE"
                exit 1
            fi
            TEMP_FILE="$REGISTRY_DIR/.$FILE.restore.$$"
            if ! cp "$SOURCE" "$TEMP_FILE" ||
                ! chown root:root "$TEMP_FILE" ||
                ! chmod 600 "$TEMP_FILE" ||
                ! mv -f "$TEMP_FILE" "$REGISTRY_DIR/$FILE"; then
                rm -f "$TEMP_FILE"
                log "ERROR: Could not restore $FILE; temporary backup has been retained."
                exit 1
            fi
        fi
    done

    rm -rf "$UPGRADE_BACKUP"
    log "Previous private configuration restored successfully; temporary backup removed."
fi

# Create a one-time setup code only while no administrator account exists.
# Retrieve it over SSH from: $APP_PATH/data/setup-token
ADMIN_FILE="$APP_PATH/data/admin.json"
SETUP_TOKEN_FILE="$APP_PATH/data/setup-token"
if [ -f "$ADMIN_FILE" ]; then
    rm -f "$SETUP_TOKEN_FILE"
elif [ ! -e "$SETUP_TOKEN_FILE" ]; then
    SETUP_TOKEN_TMP="$APP_PATH/data/.setup-token.$$"
    SETUP_TOKEN="$(php -r 'echo bin2hex(random_bytes(32));')"
    case "$SETUP_TOKEN" in
        *[!a-f0-9]*|'') log "ERROR: Could not generate a valid one-time setup code."; exit 1 ;;
    esac
    if printf '%s\n' "$SETUP_TOKEN" > "$SETUP_TOKEN_TMP" &&
        chown root:root "$SETUP_TOKEN_TMP" &&
        chmod 600 "$SETUP_TOKEN_TMP" &&
        mv -f "$SETUP_TOKEN_TMP" "$SETUP_TOKEN_FILE"; then
        log "One-time setup code created. Retrieve it over SSH from $SETUP_TOKEN_FILE."
    else
        rm -f "$SETUP_TOKEN_TMP"
        log "ERROR: Could not securely create the one-time setup code."
        exit 1
    fi
fi

# Write private runtime configuration using the actual WD installation path.
CONFIG_FILE="${APP_PATH}/encdiskmanager.conf"
CONFIG_TMP="${APP_PATH}/.encdiskmanager.conf.$$"
umask 077
if printf '%s\n' "${APP_PATH}" > "${CONFIG_TMP}" &&
    chown root:root "${CONFIG_TMP}" &&
    chmod 600 "${CONFIG_TMP}" &&
    mv -f "${CONFIG_TMP}" "${CONFIG_FILE}"; then
    log "Created private EncDiskManager configuration at ${CONFIG_FILE}"
else
    rm -f "${CONFIG_TMP}"
    log "ERROR: Could not create EncDiskManager configuration; initialisation aborted"
    exit 1
fi
