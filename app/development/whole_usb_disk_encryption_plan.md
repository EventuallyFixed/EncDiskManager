# Whole-USB-disk encryption implementation plan

Status: design only. No destructive command is enabled.

## Scope

Encrypt and format the **entire selected USB disk** on WD My Cloud EX4100 (ARMHF), using a fresh partition table, one LUKS partition and an ext4 filesystem. Existing `unlock` and `mount` helper commands must remain unchanged. Keep implementation small and understandable.

## Execution boundary

The browser and PHP web process must never run partitioning, formatting or cryptsetup directly. A new, narrowly scoped root helper command is required, separate from the existing fixed-backup-disk helper.

The helper must not trust a disk path merely because PHP previously validated it. Immediately before changing anything it must independently:

1. Accept only an explicitly named whole block device, not a partition, mapper or arbitrary path.
2. Resolve the live device through sysfs and confirm that it is USB-backed; reject internal, unknown or ambiguous devices.
3. Confirm that the device and its partitions are not mounted, in swap, held by another block device, or otherwise in use; reject read-only disks and incomplete discovery.
4. Compare the selected disk's current identity (including kernel major:minor, sysfs path and capacity) with the identity the user confirmed. Reject mismatches or device changes.
5. Require a fresh, explicit confirmation bound to that identity. A successful **preview** response is not authorization.

If any check cannot be completed, stop without modifying anything. Recheck as close as practical to the first destructive command; unplugging/replugging must never silently redirect the operation to a different disk.

## Destructive sequence (not implemented)

After all checks succeed: wipe/repartition the selected whole disk, create one partition, initialize LUKS, open it with the user-provided secret, format the mapper as ext4, then close the mapper. Check every command's exit status. Report errors without claiming a completed encryption. Do not add automatic retries or implicit fallback to another device.

Keep key material out of command-line arguments, logs and web responses. Never persist it unless an explicit, separately reviewed design requires it.

## Enablement gates

- Unit tests for device identity, rejection cases and command construction, using fake devices/commands only.
- End-to-end dry-run review of the exact helper interface and privilege boundary.
- Manual verification on the target EX4100 with a disposable USB disk and a recoverable backup.
- Only then connect the UI's currently disabled `Encrypt + ext4` action to the reviewed helper.

No work in this plan changes the stable 0.5.5 release.
