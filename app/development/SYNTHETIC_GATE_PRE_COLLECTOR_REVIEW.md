# Synthetic safety gate review before live kernel discovery

Status: **REVIEW ONLY / NO LIVE COLLECTOR / NO AUTHORIZATION**.

Reviewed after CI #355 (commit `f982bd1`) on `feature/usb-disk-encryption`.
The current synthetic validators are useful negative-test building blocks, not
a trusted whole-disk safety decision.

## Verified synthetic coverage

- Two caller-supplied partition inventories must agree exactly on canonical
  major:minor, name and partition number; duplicates and malformed schemas fail.
- The partition inventory must match the synthetic root's immediate topology,
  with no extra, missing or nested nodes.
- Synthetic dependency graph checks reject holders, mounted/swap/system/busy/
  RAID/mapper flags and malformed or disconnected topology.
- Two synthetic snapshots reject identity, source-status or topology drift.
  Matching snapshots do not establish atomicity.

## Remaining gaps, ordered by risk

1. **Independent live inventory provenance:** Both partition views are still
   caller-supplied. Even identical empty inventories can omit real partitions.
   Need independently collected `/sys/class/block` and `/sys/block/<disk>`
   views, canonical parent and major:minor checks, explicit missing/unreadable
   statuses and consistency across the collection epoch.
2. **Dependency completeness:** No trusted, complete holders **and slaves**
   traversal, mdraid/device-mapper/system ancestry, open-use checks, mount
   namespaces, mountinfo or swap resolution. Empty fixture reports are not
   proof of inactivity.
3. **Physical USB target binding:** Synthetic root names and metadata do not
   bind a live kernel object across unplug/reconnect, reuse of `/dev/sdX`,
   or device generation changes. Internal/system-disk exclusions must be
   verified independently.
4. **Atomicity and concurrency:** Two matching read-only observations cannot
   close the time-of-check/time-of-use window. Lock ownership, durable
   recovery and immediate pre-operation revalidation need separate review.
5. **Schema and relationship integrity:** The synthetic graph does not bind
   per-node major:minor identities or partition numbers to graph edges.
   Cross-view comparison matches names but cannot prove parentage from
   independent kernel evidence. Current graph intentionally rejects nested
   nodes and holders; it is not a general kernel dependency graph.
6. **Kernel/OS5 compatibility:** Required interfaces and namespace visibility
   have not been confirmed read-only on WD My Cloud EX4100 / OS5 ARM.
   Unavailable, malformed, changing or unsupported sources must block.

## Safe next milestones

1. Add isolated fixture-only negative tests for **slaves**, source
   unreadability and disagreement. Do not interpret fixture completeness as
   live completeness.
2. Define and test strict read-only parsing of synthetic mountinfo and swaps
   records with canonical major:minor and malformed-input rejection.
3. Specify independently sourced per-node parent/partition identity schema,
   including zero-partition disks and contradictory parent evidence.
4. Review OS5 kernel evidence without mutation, then separately review a
   read-only collector proposal. No destructive execution path is enabled.

Stable v0.5.5 `main` and WD-managed directory layout/permissions remain
unchanged. Whole-disk erase, repartition, LUKS and ext4 operations remain
**BLOCKED** until separate explicit authorization and security review.
