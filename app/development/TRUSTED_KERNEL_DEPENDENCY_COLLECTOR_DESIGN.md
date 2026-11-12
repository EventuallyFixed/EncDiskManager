# Trusted read-only kernel dependency collector — design gate

Status: DESIGN ONLY. No live NAS discovery or privileged execution is enabled.

## Scope and trust boundary

The existing PHP synthetic topology, source-completeness, and two-snapshot
validators accept caller-supplied structures. They are regression tools, not
proof that a disk is safe. No browser, request parameter, or unprivileged
process may assert `complete` or construct authoritative identity.

A future privileged, read-only collector must obtain device identity and
dependencies directly from kernel interfaces, validate each source, and
produce an immutable snapshot for a separate native authorization boundary.
The collector must never write to block devices or WD-managed directories.

## Required kernel evidence

- Resolve the selected whole-disk block device from trusted sysfs, verifying
  major:minor, canonical path, physical USB ancestry, size, removable/read-only
  status and partition relationship. Do not trust a `/dev/sdX` name alone.
- Enumerate the whole-disk partition tree and each node's holders/slaves.
  Missing, unreadable, unexpected, cyclic or changing links fail closed.
  Treat device-mapper and MD/RAID relationships as consumers, not partitions.
- Cross-check all relevant `/proc/self/mountinfo` entries, including mounts
  reachable through dm/MD, and `/proc/swaps`. A mount namespace limited to
  the collector may omit mounts elsewhere: namespace coverage must be
  established or eligibility must be denied.
- Resolve root filesystem, NAS data volumes and other system dependencies.
  Any uncertain ancestry, incomplete MD/dm mapping or unsupported device
  type makes the disk ineligible.
- Read and validate signatures and partition-table state with an approved
  read-only probe. Probe failure or contradictory signatures fail closed.
- Bind USB identity using available hardware attributes, but do not assume
  a USB serial is unique or immutable. Ambiguous or absent identity must
  be rejected until a stronger device-binding scheme is reviewed.

## Collection and consistency protocol

1. Acquire the coordinator's designated lock and keep it through all
   discovery, revalidation and authorization checks.
2. Collect complete source evidence, recording each source's status and
   generation/timing. A missing directory is *not* proof of an empty set.
3. Build a typed graph separating partition-child and holder/slave edges;
   explicitly reject unrecognized relationship types.
4. Re-read kernel evidence independently. Require matching identity,
   partition inventory, mount/swap state, dependencies and signatures.
   Any discrepancy fails closed; retrying must begin a new complete check.
5. A future executor must revalidate immediately before destructive steps,
   retain the required lock and durable pending-operation state, and abort
   on any change. A successful preview must never authorize execution.

## Integration rules

- Do not connect `whole_usb_disk_fixture_discovery.php` to live sysfs:
  its fixtures and safety flags are deliberately synthetic.
- Do not interpret `edm_synthetic_dependency_snapshots_stable()` as
  authorization; strict array equality is a useful test but not a trusted
  kernel-source provenance guarantee.
- Keep `execution_enabled: false` and the backend encryption action
  unavailable until the native collector, permission model, recovery,
  confirmation protocol and device-specific EX4100 read-only review pass.
- Preserve the user's decision not to modify WD-managed parent permissions,
  mount options or directory layout.

## Read-only validation plan (requires separate approval before NAS access)

Inventory kernel interfaces and existing WD device topology without modifying
files, then compare their output against isolated fixture models. Include
negative cases for absent USB serial, duplicate serial, USB bridge replacement,
device renumbering, dm/MD holders, namespace mounts, swaps, unreadable files,
hot-unplug and change between snapshots. Review all failures before designing
any executor. No real encryption or repartitioning is part of this plan.
