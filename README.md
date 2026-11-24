# EncDiskManager

**EncDiskManager (Encrypted Disk Manager)** is a web-based utility for managing LUKS-encrypted USB storage on **WD My Cloud OS5** NAS devices.

Written in PHP and using out-of-box utilities on the NAS, it discovers attached USB disks, lets you unlock and mount encrypted volumes, and provides a controlled way to encrypt a whole USB disk with LUKS encryption and have an ext4 filesystem overlaid.

**Development version:** 1.0.1 — developed for the WD My Cloud EX4100. Latest published stable release: 1.0.0.

> [!WARNING]
> **Encrypt + ext4 permanently erases and repartitions the entire selected USB disk.** All existing partitions and data on that disk will be lost. Back up anything important and check the selected device carefully before confirming.

## Features

- **USB disk inventory:** shows connected USB disks, partitions, encrypted mappings, capacity, mount points and connection state.
- **Whole-disk encryption:** creates a GPT partition table, a LUKS2-encrypted partition and an ext4 filesystem, then mounts the new volume.
- **Disk operations:** Unlock, Mount, Unmount and Lock are offered according to the current disk state.
- **Disk registration:** save a display name and mount directory for an encrypted volume, identified by its LUKS UUID. Registered disks remain listed when disconnected.
- **Optional stored unlock key:** save a newly generated key on the NAS for convenient future unlocking, without saving your original passphrase.
- **Removal guidance:** displays **Safe to unplug** or **Do not unplug** beneath each physical USB disk's encryption button, based on its detected activity and dependencies.
- **Administration:** HTTPS-protected administrator login and configuration backup, restore and deletion.

The interface refreshes disk information automatically. Disk operations also recheck live state before proceeding.

## Compatibility

- **Platform:** WD My Cloud OS5
- **Minimum firmware:** 5.27.157
- **Developed and tested on:** WD My Cloud EX4100 (ARMHF)
- **Storage:** externally attached USB disks; the application is not intended to manage the NAS's internal system disks.

Installable packages for several other WD My Cloud models are available in the [GitHub release](https://github.com/EventuallyFixed/EncDiskManager/releases/tag/v1.0.0). Those models have not all been independently validated on physical hardware.

## Installation

1. Download the `.bin` package matching your NAS model from the [v1.0.0 GitHub release](https://github.com/EventuallyFixed/EncDiskManager/releases/tag/v1.0.0).
2. On the NAS, open the WD My Cloud dashboard and use the Apps page's manual installation option to install the package.
3. Launch **Encrypted Disk Manager** from the NAS dashboard, using **HTTPS**.
4. On first use, retrieve the one-time administrator setup code over SSH, then use it to create an administrator password.

For an EX4100 with the standard application path, the setup code is stored at:

```sh
cat /mnt/HD/HD_a2/Nas_Prog/_EncDiskManager/setup-token
```

Run this in an appropriately privileged SSH session on your NAS. Keep the code private. It is removed after administrator setup.

The EX4100 installer is named:

```text
EncDiskManager_1.0.0_WDMyCloudEX4100.bin
```

**Do not install an archive or package intended for a different NAS model.**

## Using EncDiskManager

### Work with an existing encrypted USB disk

1. Connect the USB disk and locate its LUKS partition in the inventory.
2. Select **Register** to give the disk a name and configure a mount directory under `/mnt`.
3. Click **Unlock** and enter the LUKS passphrase.
4. Click **Mount** to mount the unlocked filesystem.
5. When finished, click **Unmount**, then click **Lock**. These are deliberately separate operations.
6. Check that the physical disk reports **Safe to unplug** before disconnecting it.

Buttons are disabled when an operation does not apply to the detected state.

### Optionally save an unlock key

When unlocking a registered disk, select **Securely save the key** if you want subsequent unlocks to work without re-entering your passphrase.

EncDiskManager generates a separate random unlock key, enrols it in a LUKS keyslot and keeps its local copy in the NAS's private application-data directory. **Your original passphrase is not saved.**

With a stored key, **Unlock** opens the locked LUKS volume without asking for the recovery passphrase again. **Mount** is a separate action, available only after unlocking. **Unmount** still leaves the encrypted mapping open: click **Lock** separately before unplugging.

The stored key can be removed from **Configure**. Removing its local file does **not** revoke the corresponding LUKS keyslot. Keep your original recovery passphrase somewhere safe.

### Encrypt a new USB disk

1. Back up the disk: **all existing data will be destroyed**.
2. Select **Encrypt + ext4** on the row for the **whole physical USB disk**, not an individual partition.
3. Read the warning, acknowledge the erase operation and enter the required confirmation phrase: `ERASE ALL DATA`.
4. Enter and confirm a LUKS passphrase.
5. Confirm the operation and wait for it to finish. The app creates a single LUKS2/ext4 partition and mounts the new volume.
6. Register the resulting encrypted partition if you want to assign a friendly name and a persistent mount-directory preference.

The newly formatted volume is initially mounted under `/mnt/edm_<LUKS-UUID-without-hyphens>`. Before removing it, **Unmount** and then **Lock**.

If formatting reports an error, inspect the actual disk state before retrying. A failed later step does not necessarily mean that earlier destructive steps were rolled back.

## Configuration and backups

Open **Settings** to download a configuration backup (`.tgz`), restore a previous backup or explicitly delete saved configuration.

A configuration backup includes the administrator password **hash** and registered-disk settings. It does **not** include:

- Disk contents
- Plaintext administrator passwords or LUKS passphrases
- Locally stored LUKS unlock-key files

Normal application upgrades preserve private configuration in the sibling directory `_EncDiskManager`. On the EX4100 this is typically:

```text
/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager
```

Download and securely retain a configuration backup before upgrading or removing the application. **Restoring a backup replaces the current administrator account and registered-disk list.** A configuration backup is not a substitute for backing up your actual USB data or keeping your LUKS recovery passphrase.

## Important notes

- **Safe to unplug** is based on the application's current disk discovery and activity checks. It is not permission to disconnect a disk that another process is still using.
- Unmounting an encrypted filesystem does not close its LUKS mapping. Always **Lock** separately.
- A stored unlock key improves convenience but makes the encrypted disk accessible to someone who gains sufficient control of the NAS.
- **Configure** lets you edit a registered disk's name and mount directory only when disconnected or when every partition on its physical USB disk is unmounted. Configure is disabled if any partition is mounted. The new directory must be unique and unused. Saving does not move a live mount, change disk contents or remove a stored key.
- Disks are not automatically unlocked and mounted merely because they are reconnected.
- This software performs privileged storage operations. Use it only on hardware and data you are prepared to recover from backups.
- This does *NOT* prevent the NAS from showing a red LED on attaching the LUKS/ext4 disk, nor prevent the 'Unable to mount USB Device' message displayed by OS5, which must be removed manually. 

## Development

The repository is organised as follows:

| Path | Purpose |
| --- | --- |
| `app/web/` | PHP web interface and request handlers |
| `app/lib/` | Disk discovery, registry, authentication and operation logic |
| `app/bin/` | Privileged disk-operation helpers |
| `app/development/tests/` | Read-only fixtures and regression tests |
| `app/apkg.rc` | WD application metadata and version |
| `releases/` | Versioned WD installation packages |
| `.github/workflows/` | GitHub Actions validation |

The application is packaged with **WD-NAS-App-Builder**. The repository's top-level `build.sh` currently refers to developer-specific local builder directories and is **not** a portable one-command build on a fresh checkout. Adapt the builder paths and Docker setup before using it.

Some useful read-only checks from a development checkout:

```sh
php -l app/web/index.php
php -l app/web/inventory.php
php app/development/tests/whole_usb_disk_safe_to_unplug_test.php
sh app/development/tests/whole_disk_helper_interface_test.sh
```

The GitHub Actions workflows run additional syntax and fixture checks. Tests do not replace checking actual disk behaviour on the intended NAS hardware.

## Project links

- [Source code](https://github.com/EventuallyFixed/EncDiskManager)
- [Version 1.0.0 installation packages](https://github.com/EventuallyFixed/EncDiskManager/releases/tag/v1.0.0)
- [Issues and suggestions](https://github.com/EventuallyFixed/EncDiskManager/issues)

# Screenshots

## Disk attached, unlocked, and mounted.
<img width="1442" height="738" alt="Storage" src="https://github.com/user-attachments/assets/8ff10a48-0feb-41be-9618-113752a901e8" />

## Disk Registration
<img width="513" height="670" alt="DiskConf" src="https://github.com/user-attachments/assets/a562e0ac-40e4-41d6-a84e-634bfd6519dd" />

## Settings
<img width="786" height="786" alt="image" src="https://github.com/user-attachments/assets/9c574bcc-8a32-4716-90f0-a2d70c82bb55" />
