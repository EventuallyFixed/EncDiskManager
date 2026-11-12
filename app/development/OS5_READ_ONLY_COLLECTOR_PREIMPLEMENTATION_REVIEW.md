# Independent OS5 kernel collector — pre-implementation review

**Status: DESIGN REVIEW ONLY. No live kernel reads, privileged helper, destructive execution, packaging, deployment or merge authorized.**

## Validated synthetic baseline

CI #376 passed for combined synthetic partition topology and per-node identity binding. Existing isolated fixtures also cover holders/slaves, conservative mount/swap reports, parser syntax, swap-backing ambiguity, two-view disagreement, and snapshot drift. These tests check caller-supplied evidence; they cannot establish independent live completeness.

## Critical missing guarantees

1. **Independent enumeration.** A caller-supplied empty or matching pair of partition inventories may omit actual partitions. A trusted collector must independently enumerate both `/sys/class/block` and `/sys/block/<disk>` and reconcile canonical device objects. Missing, unreadable, changing, or contradictory entries must block.
2. **Canonical object binding.** Bind each whole disk and partition to canonical sysfs paths, kernel `major:minor`, partition number, parent, and physical USB ancestry. Do not trust `/dev/sdX` names or supplied strings as proof of identity. Detect duplicate or reused device numbers and unplug/replug.
3. **Dependencies.** Enumerate holders **and** slaves, mdraid/device-mapper ancestry, mount namespaces and mountinfo, swaps including swapfile backing, system/internal-disk ancestry, and all relevant consumers. Unknown namespaces or unreadable sources block. A zero-entry report is not sufficient without trusted collection provenance.
4. **Source integrity.** Record source identity, collection status, error classification, and bounded epoch for each independently read view. A parseable response is not proof of a complete kernel snapshot. Reject source disagreement and partial traversal.
5. **Races.** Read-only snapshots can change between enumeration and use. A future coordinator must lock and revalidate the exact physical target and dependencies immediately before an operation; no current synthetic check solves this.
6. **OS5 compatibility.** Confirm WD My Cloud EX4100 ARMv7/armhf kernel/sysfs layout, BusyBox behavior, mount namespaces, helper privilege boundary, and glibc/toolchain constraints via separately approved read-only inspection. Do not change WD-managed `/mnt/HD/HD_a2` or `Nas_Prog` permissions; private `_EncDiskManager` remains root-owned 0700.

## Proposed read-only collector contract (not implemented)

Return an explicit structured result with `status` (`complete` or `blocked`), source-by-source readability and provenance, canonical root identity, per-node canonical parent and `major:minor`, partition numbers, USB/system ancestry, holders/slaves edges, mount/swap dependency identities, and collection epoch. Every field must be independently verified; unknown fields are **blocked**, never silently empty. Require deterministic bounded traversal and fail-closed error handling.

Separate collection from policy evaluation. The collector must not expose an encryption, format, partition, mount, unlock or other mutating action. A successful read-only result is not execution authorization.

## Safe next milestones

1. Review the above contract and list the minimum independent kernel sources for EX4100.
2. Build **synthetic-only contract fixtures** for unreadable/missing source, contradictory parent/device identities, omitted partition, and changed device generation.
3. After explicit separate approval, propose a tightly scoped read-only OS5 inspection plan. Do not execute inspection or add privileged collection code on the strength of this document.

**Release boundary:** Stable v0.5.5 `main` unchanged. Work remains on `feature/usb-disk-encryption`; privileged disk operations blocked.
