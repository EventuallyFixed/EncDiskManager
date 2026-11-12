# Trusted read-only kernel collector: proposed interface (design only)

Status: **DESIGN / NOT IMPLEMENTED / NOT AUTHORIZATION**.
This contract supplements `TRUSTED_KERNEL_DEPENDENCY_COLLECTOR_DESIGN.md`
and `COMBINED_FIXTURE_GATE_REVIEW.md`. No live kernel access is added.

## Input boundary

The privileged, read-only collector receives a server-resolved target
identity, never a browser-supplied `/dev` path. Its input identifies a
candidate physical USB whole disk by a previously observed major:minor and
device-generation/identity evidence. Device names (`sdb`, etc.) are only
diagnostic hints. Missing, inconsistent, or reused identity fails closed.

## Source observations

Each source returns `status` (`ok`, `missing`, `unreadable`,
`malformed`, `changed`, or `unsupported`), a monotonic collection
epoch, and canonical block-device identities where applicable. The
collector must distinguish a verified empty result from an unavailable
source; a caller cannot assert `complete: true` without independent
verification.

Minimum independent views:

1. `/sys/class/block` and `/sys/block/<disk>` partition membership,
   canonicalized to kernel device identities and checked for exact
   agreement, including zero-partition disks.
2. Per-device `dev` major:minor, `partition` markers, physical-disk
   status, canonical parent relationships and USB transport ancestry.
3. `holders` **and** `slaves` dependency edges, mdraid/device-mapper
   ancestry, and system/internal volume exclusions.
4. `/proc/self/mountinfo` plus any relevant mount namespaces, and
   `/proc/swaps`, with device identity resolution rather than string
   comparisons on `/dev/sdX`.
5. Busy/open-use checks and any OS5-specific state needed to rule out
   system or WD-managed volumes. Unknown coverage remains a blocker.

## Normalized output proposal

```text
{
  status: "complete" | "blocked",
  target: { major_minor, stable_identity, usb_ancestry, whole_disk },
  sources: { source_name: { status, epoch, observed_ids } },
  topology: { nodes: [...], edges: [...] },
  blockers: [stable_reason_codes...],
  snapshot_fingerprint: canonical_digest
}
```

No field in this output is an authorization token. `complete` means
the independently checked **read-only observation** is internally
consistent, not that encryption is permitted. The caller must not
interpret a missing blocker as permission if any required source is
unknown or unsupported.

## Fail-closed disagreement rules

- A partition in one independent view but absent from another blocks.
- Duplicate major:minor, ambiguous parentage, or unexpected holder/slave
  links block.
- A mount, swap, mapper, RAID or NAS system dependency blocks.
- Missing `dev` or partition metadata, unresolved symlinks, malformed
  mountinfo, unsupported kernel features or unreadable sources block.
- USB unplug/reconnect, target replacement, generation changes, or
  topology changes between observations block.
- Two matching observations are still not a guarantee of atomicity;
  coordinator locking, kernel-object binding and immediate pre-operation
  revalidation require separate review.

## Read-only verification milestones

1. Add pure synthetic parsers and tests for independent partition-view
   disagreement and canonical identity collisions.
2. Add isolated fixture tests for holders/slaves, mountinfo, swaps and
   system-ancestry source failure modes.
3. Review OS5 kernel interface availability and ARM compatibility using
   non-destructive evidence only.
4. Only after separate security review propose a privileged read-only
   collector implementation. Do not connect it to the destructive
   operation path without explicit authorization and recovery approval.

WD-managed parent directory permissions and layout remain unchanged.
Stable v0.5.5 `main` remains untouched. Entire-disk erase, repartition,
LUKS and ext4 execution remain **blocked**.
