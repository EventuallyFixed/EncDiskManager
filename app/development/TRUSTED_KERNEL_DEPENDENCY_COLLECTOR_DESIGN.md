# Whole-USB-disk safety inspection — implementation guide

Status: **DESIGN ONLY. Destructive operations remain blocked.**

## Purpose

Before offering whole-disk `Encrypt + ext4`, positively establish that the
selected device is a physical USB disk, not NAS/system storage, and is idle.
A browser-provided device path, a previous preview, or synthetic fixture data
must never authorize an erase. Keep the implementation small and understandable;
prefer standard Linux kernel interfaces and established utilities.

## Read-only inspection

Under the disk-operation lock, inspect the selected disk and all its partitions:

1. Resolve the whole-disk identity from sysfs (major:minor, canonical device
   relationship, USB ancestry, capacity and available model/serial evidence).
   Names such as `/dev/sdb` are hints, not stable identities.
2. Exclude the NAS boot/root/system/RAID/data devices and any disk that backs
   the application or persistent state. If the relationship is uncertain, stop.
3. Check the entire disk and descendants for mounts, swap, dm-crypt/mapper,
   RAID, holders/slaves, read-only state and other busy users. Consult sysfs,
   `/proc/self/mountinfo`, `/proc/swaps` and appropriate read-only tools.
   Missing or unreadable information must not count as a clear result.
4. Read the partition inventory and relevant signatures; record the complete
   target identity and current state. Reject inconsistent or changing results.

A clear inspection is **not** permission to execute. The same physical disk
must be reidentified and rechecked immediately before any destructive step,
with the lock held. A disconnected, replaced, renamed or newly busy disk
must cause an abort. Keep these checks in one readable code path instead of
building a chain of synthetic authorization wrappers.

## User confirmation

Show the physical disk model/identifier, capacity and all detected partitions.
Explain that **every partition and all data will be permanently erased**.
Require a separate, explicit confirmation bound to the selected disk and its
current state. Reject stale confirmation or any changed target. No fallback
to another device.

## Testing and implementation boundaries

Use isolated fixtures to test the real inspection logic: internal/NAS disks,
USB disks with partitions, mounted or swapped descendants, mapper/RAID
consumers, missing metadata, unplug/reconnect and changing identity. Tests
must never execute `cryptsetup`, partitioning or formatting on real devices.
The previous standalone `synthetic_*` and `fixture_*` planning gates were
removed because they checked caller-supplied data without proving live safety.

Only after the read-only inspector, confirmation, locking and recovery path
have been reviewed and explicitly approved may the destructive operation be
considered. Until then keep `execution_enabled: false` and backend
encryption unavailable. Do not deploy, package, merge or run privileged disk
operations as part of this documentation change.

Preserve the existing WD-managed directory layout and permissions, including
the private `_EncDiskManager` directory. Stable v0.5.5 `main` is untouched.
