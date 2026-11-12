# Fixture dependency gate: readiness boundary

Status: **TEST-ONLY — NOT AUTHORIZATION**. The current combined gate is
`edm_fixture_disk_dependencies_clear()` in
`app/lib/fixture_disk_dependencies.php`. It consumes an isolated synthetic
sysfs fixture root and a caller-supplied mount/swap report. A true result
means only that those particular fixture inputs passed their checks.

## Evidence currently checked

- Synthetic block entries and immediate partition relationships, including
  partition-number and alias ambiguity checks.
- Explicitly empty synthetic holder directories for the selected disk and
  every partition discovered in that fixture.
- Explicit readable and complete synthetic mount and swap reports; the
  current conservative gate rejects **any** reported dependency, even on an
  unrelated device, because it has no trusted ancestry resolver.

## Critical limitations

1. **No trusted kernel source:** fixtures and report flags are controlled by
   their caller. A caller can omit partitions, mounts or swap entries and
   claim completeness. No independent kernel verification exists.
2. **No authoritative whole-disk identity:** the gate does not establish
   USB transport ancestry, stable identity, device major:minor, physical
   whole-disk status, reconnect identity or exclusive target binding.
3. **No NAS/system disk exclusion:** the gate does not prove the disk is
   external or exclude WD-managed internal volumes, root filesystems,
   mdraid, device-mapper stacks, LVM, or other system dependencies.
4. **Incomplete dependency coverage:** holders, mounts and swap alone are
   insufficient; slaves, mdraid, mapper, open descriptors, filesystem use,
   partition table state, udev changes and other busy conditions remain.
5. **No concurrency or durability proof:** snapshots are not atomically
   collected, locked to a kernel object, or rechecked across the irreversible
   boundary. Device names can be reused and topology can change.
6. **Fixture path trust:** symlink traversal and caller-selected fixture
   roots are not a hardened security boundary. These helpers must never
   accept arbitrary live filesystem paths as an authorization input.
7. **No execution protocol:** this gate does not verify operation intent,
   authentication, explicit full-disk confirmations, persistent pending
   records, crash recovery, or privileged helper handoff.

## Required before any live authorization

- Implement a separately reviewed **read-only trusted kernel collector**
  that independently validates source completeness and canonical ancestry.
- Bind the selected physical USB disk to a stable identity and kernel
  major:minor; reject internal/system disks and all ambiguous targets.
- Cross-check full dependency topology, active users, mounts, swap, holders,
  slaves and system ancestry, failing closed on unreadable or unknown state.
- Revalidate the exact same physical target immediately before destructive
  operations under the reviewed coordinator/lock and durable recovery
  protocol; reject disconnect/reconnect and any topology drift.
- Enforce separate irreversible **entire-disk erase and repartition**
  confirmations, state-dependent UI restrictions and server-side checks.
- Review native helper, OS5 compatibility, authorization boundaries and
  recovery behavior on the target architecture **without** first enabling
  destructive operations.

The accepted WD-managed parent-directory permissions and layout must not be
modified. Stable v0.5.5 `main` must remain untouched. The branch remains
blocked from privileged disk operations until a separate explicit review and
approval; passing CI does not remove this block.
