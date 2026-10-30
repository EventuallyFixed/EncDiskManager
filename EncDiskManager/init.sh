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

# Create the persistent registry directory outside APP_PATH so registrations
# survive application removal and reinstallation. Never replace existing data.
REGISTRY_DIR="/mnt/HD/HD_a2/Nas_Prog/EncDiskManager_Reg"
if [ -L "${REGISTRY_DIR}" ]; then
    log "ERROR: Registry directory must not be a symbolic link: ${REGISTRY_DIR}"
    exit 1
fi

if mkdir -p "${REGISTRY_DIR}" &&
    chown root:root "${REGISTRY_DIR}" &&
    chmod 700 "${REGISTRY_DIR}"; then
    log "Secured persistent registry directory at ${REGISTRY_DIR}"
else
    log "ERROR: Could not create or secure registry directory: ${REGISTRY_DIR}"
    exit 1
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
