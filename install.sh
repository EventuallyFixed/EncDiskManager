#!/bin/sh

# Called upon app installation
	# -> 1. $UPLOAD_PATH/install.sh $UPLOAD_PATH $INSTALL_PATH
	#	 2. $INSTALL_PATH/init.sh $INSTALL_PATH
	#	 3. $INSTALL_PATH/start.sh $INSTALL_PATH

# Called upon app reinstallation
	#	 1. $INSTALL_PATH/stop.sh
	#	 2. $INSTALL_PATH/clean.sh
	#	 3. $INSTALL_PATH/preinst.sh $INSTALL_PATH
	#	 4. $INSTALL_PATH/remove.sh $INSTALL_PATH
	# -> 5. $UPLOAD_PATH/install.sh $UPLOAD_PATH $INSTALL_PATH
	#	 6. $INSTALL_PATH/init.sh $INSTALL_PATH
	#	 7. $INSTALL_PATH/start.sh $INSTALL_PATH

# Load all the useful variables
. "$1/helpers.sh" "$0" "$1" "$2";

set -eu

# The WD installer can leave an application directory containing only its
# generated apkg.xml metadata. BusyBox mv (including the EX4100's v1.30.1)
# cannot rename the uploaded directory over that existing non-empty directory.
# Remove only this known metadata-only placeholder, and preserve it for
# rollback until the uploaded application tree has been moved successfully.
if [ ! -d "${APP_UPLOAD_PATH}" ] || [ -L "${APP_UPLOAD_PATH}" ]; then
	log "ERROR: Uploaded application directory is missing or is not a real directory: ${APP_UPLOAD_PATH}"
	exit 1
fi

PLACEHOLDER_BACKUP="${APPS_PATH}/.${APP_NAME}.install-placeholder.$$"

if [ -e "${APP_PATH}" ] || [ -L "${APP_PATH}" ]; then
	if [ -L "${APP_PATH}" ] || [ ! -d "${APP_PATH}" ]; then
		log "ERROR: Existing application path is not a real directory; refusing replacement: ${APP_PATH}"
		exit 1
	fi

	if [ -L "${APP_PATH}/apkg.xml" ] || [ ! -f "${APP_PATH}/apkg.xml" ]; then
		log "ERROR: Existing application directory is not a metadata-only placeholder; refusing replacement: ${APP_PATH}"
		exit 1
	fi

	EXTRA_ENTRY="$(find "${APP_PATH}" -mindepth 1 -maxdepth 1 ! -name apkg.xml -print -quit)"
	if [ -n "${EXTRA_ENTRY}" ]; then
		log "ERROR: Existing application directory contains additional files; refusing replacement: ${APP_PATH}"
		exit 1
	fi

	if [ -e "${PLACEHOLDER_BACKUP}" ] || [ -L "${PLACEHOLDER_BACKUP}" ]; then
		log "ERROR: Application placeholder backup path already exists; refusing replacement: ${PLACEHOLDER_BACKUP}"
		exit 1
	fi

	if ! mv "${APP_PATH}" "${PLACEHOLDER_BACKUP}"; then
		log "ERROR: Could not safely move existing metadata placeholder aside."
		exit 1
	fi
fi

# Move the uploaded application tree into its permanent location. If this
# fails, restore the metadata placeholder rather than leaving the app path
# absent. No persistent data directory is touched by this operation.
if ! mv "${APP_UPLOAD_PATH}" "${APPS_PATH}"; then
	if [ -d "${PLACEHOLDER_BACKUP}" ] && [ ! -e "${APP_PATH}" ]; then
		if ! mv "${PLACEHOLDER_BACKUP}" "${APP_PATH}"; then
			log "ERROR: Application move failed and the metadata placeholder could not be restored: ${PLACEHOLDER_BACKUP}"
			exit 1
		fi
	fi
	log "ERROR: Could not move uploaded application into place."
	exit 1
fi

if [ ! -d "${APP_PATH}" ] || [ ! -f "${APP_PATH}/apkg.xml" ]; then
	log "ERROR: Uploaded application move completed but the expected application tree or metadata is missing."
	exit 1
fi

# The new application tree is now in place; discard only the verified
# metadata-only placeholder retained above.
if [ -d "${PLACEHOLDER_BACKUP}" ]; then
	rm -f "${PLACEHOLDER_BACKUP}/apkg.xml"
	rmdir "${PLACEHOLDER_BACKUP}"
fi

log "Application files moved successfully to: ${APP_PATH}"
