# Combined fixture dependency gate review

Reviewed at commit `edd69c46fa1acbf44250da6cc04ea69d0d14b875`.
Scope: `fixture_disk_dependencies.php`, `fixture_partition_tree.php`,
`fixture_block_holders.php`, and `fixture_mount_swap.php`.

**Verdict: synthetic regression fixture only. Not a live-disk safety
decision, not a complete dependency snapshot, and not authorization.**

## Findings (highest priority first)

1. **Partition inventory completeness is not independently proven.**
   `fixture_partition_tree.php` enumerates links present in the
   caller-controlled `class/block` directory. If the fixture omits a
   partition link, the collector cannot discover it from the physical
   disk directory alone. The returned `complete: true` means its
   visible entries were parsed, not that the kernel partition inventory
   is complete. A future trusted collector must cross-check independent
   kernel views and reject disagreement or missing entries.

2. **Mount and swap completeness is asserted by the caller.**
   `fixture_mount_swap.php` receives `readable`/`complete` booleans
   and device arrays. It does not read mountinfo, swap tables, namespace
   mount views, or resolve major:minor ancestry. An empty caller report
   cannot prove no dependency exists. A trusted read-only collector must
   derive these facts from verified kernel interfaces and explicitly
   represent unavailable sources as unknown.

3. **Holder emptiness is not full device inactivity.** A present
   `holders` directory with no entries is only one signal. The fixture
   gate does not examine `slaves`, mdraid, device-mapper, open handles,
   swap, filesystem users, NAS system ancestry, or other busy conditions.
   The conservative mount/swap report rejects *all* reported entries
   because it lacks a trusted ancestry resolver.

4. **Path confinement is not a trusted filesystem boundary.** Canonical
   path checks prevent ordinary links outside the fixture root, but
   mutable paths and symlink races are not solved. This code must never
   be repurposed to consume arbitrary live filesystem roots or to
   authorize privileged operations.

5. **No physical-disk identity binding or atomic revalidation.** Names
   like `sdb` are not durable identity. The gate does not prove USB
   transport, whole-disk status, internal/system disk exclusion, stable
   serial or major:minor, or the same device across a destructive
   operation. It does not integrate with coordinator locks or durable
   recovery records.

## Next safe development order

1. Add **synthetic negative tests** showing that an omitted partition
   can yield a locally consistent fixture. Mark this as a documented
   limitation; do not call the result authoritative.
2. Define a separate **trusted read-only collector contract** with
   independent sources, explicit source errors, canonical major:minor
   identities, system-disk exclusion, and a complete dependency graph.
3. Test source disagreement, missing mounts/swaps, disappearing devices,
   topology changes, and identity replacement in isolated fixtures.
4. Review on the target OS5/ARM architecture in read-only mode before
   considering any privileged-operation proposal.

Do not change WD-managed parent permissions or layout. Keep stable
v0.5.5 `main` unchanged. Full-disk erase/repartition, encryption, and
other privileged operations remain **blocked** pending separate review
and explicit approval.
