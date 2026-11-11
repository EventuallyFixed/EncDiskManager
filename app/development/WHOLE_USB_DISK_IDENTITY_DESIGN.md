# Whole USB disk identity and exclusion — design only

Status: **PROPOSED / NO EXECUTION**. The administrator chose
whole-physical-USB-disk erase/repartition for `Encrypt + ext4`.
This document defines read-only verification requirements only;
it does not authorize destructive operations.

## Trust and scope

The UI may list partitions, but encryption selection must identify
the **parent physical USB disk** explicitly and show its full scope.
Never derive permission to erase a parent disk from a partition UUID
or an old preview. Never accept a client-supplied `/dev/sdX` path
as an execution target. An unknown or ambiguous device is ineligible.

## Proposed discovery snapshot

For every candidate whole-disk block device, collect and validate:

- Kernel block name and major:minor; canonical sysfs location and
  verifiable USB ancestry (not a name-prefix heuristic).
- Parent transport and USB physical topology; available serial,
  WWN and vendor/model identifiers, treating missing, duplicate or
  mutable identifiers as risk, not proof of uniqueness.
- Exact byte capacity, logical/physical sector sizes, read-only flag,
  removable/hotplug properties where available.
- Complete partition table, child partitions, kernel holders/slaves,
  dm/md/loop relationships and mounted filesystems.
- All direct and indirect consumers, including swap, root, system
  arrays, application data, active LUKS mappers and mounts.
- Probe success/completeness and an in-process snapshot identity;
  do not use timestamps or a device name alone as identity.

Require stable enumeration across repeated probes while holding the
single operation lock. Re-resolve and compare at the final destructive
boundary. Reject changed topology, major:minor, capacity, sysfs
identity, signature, mount/holder state or hardware evidence.

## Mandatory exclusions

Reject any disk if:

1. It is not positively established as a USB whole-disk device.
2. It is the device, ancestor or dependency of NAS boot, root,
   system, RAID, application-data or persistent-state storage.
3. It or any descendant is mounted, used as swap, opened through
   dm-crypt, part of an active RAID/mapper stack, has holders, or
   is otherwise busy.
4. It is read-only, has incomplete topology, has uncertain signature
   or partition-table probing, or has a conflicting/ambiguous
   hardware identity.
5. Its kernel identity, USB ancestry or descendant set changes
   between discovery, confirmation and pre-execution revalidation.

A partition table or filesystem signature that is absent or
unrecognized must not be interpreted as proof of an empty disk.
Positive safety checks are required even for blank disks.

## Confirmation contract

Present the physical disk's manufacturer/model, capacity, reliable
hardware identifier where available, and **all** detected partitions.
State that every partition and all data will be destroyed.
Server-side confirmation must be fresh, one-time, bound to exact
whole-disk identity and state, and invalidated on any change.
No auto-selection of a disk and no fallback to another device.

## Isolated validation plan

Use fake sysfs, mountinfo, swap and mapper/RAID fixtures. Cover:
- USB whole disk with/without partitions; non-USB internal disks.
- USB disk backing NAS/system storage or an active holder.
- Mounted child, swap child, open mapper and busy devices.
- Duplicate/missing hardware identifiers and two identical models.
- Unplug/replug, renamed `sdX`, reused major:minor, altered
  capacity, changed partitions and inconsistent snapshots.
- Failed/malformed/partial sysfs and metadata reads.
- Destructive confirmation bound to an old snapshot (must reject).

Tests must be read-only, isolated and must not invoke partitioning,
cryptsetup, mkfs or any real disk executor.

## Open decisions before implementation

Define the trusted USB ancestry parser, system-disk exclusion graph,
hardware-identity confidence policy, exact whole-disk target handle,
and confirmation token lifecycle. Review EX4100 sysfs and block-device
layout using only separately approved read-only inspection.

Stable v0.5.5 and WD-managed directory permissions are unchanged.
