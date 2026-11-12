# Synthetic mountinfo and swaps identity review

**Status: REVIEW ONLY — no live kernel collector, no disk authorization, no privileged execution.**

## Existing synthetic inputs

- `edm_synthetic_mountinfo_parse()` accepts caller-supplied mountinfo text and extracts canonical decimal `major:minor` strings. It does not prove the text was read from a complete mount namespace, that the device still exists, or that the number identifies the selected physical USB disk.
- `edm_synthetic_swaps_parse()` accepts caller-supplied swaps text and extracts path, type, size, usage and priority. Its path is **not** a block identity. Swap files can reside on filesystems whose underlying storage requires separate resolution.
- `edm_fixture_mount_swap_clear()` conservatively rejects any reported dependency, even one on an apparently unrelated device. It does not consume the parsed records or resolve ancestry.
- The fixture partition and holders/slaves collectors do not prove an independently complete live kernel inventory. Identical omitted partition links can produce an apparently complete fixture view.

## Required trusted identity model before integration

1. Bind every selected whole disk and partition to an independently collected kernel identity (canonical name, major:minor, parent, partition number, and physical USB ancestry), not a pathname alone.
2. Mountinfo device numbers must be compared against that complete kernel inventory, including all partitions and device-mapper/RAID descendants. The mount namespace and collection completeness must be explicit. A missing or unreadable source blocks.
3. Resolve swap **partitions** through a trusted kernel object, not merely `/dev/...` text. Resolve swap **files** through their backing mount and full storage dependency ancestry; unknown or ambiguous backing storage blocks.
4. Do not assume a mountinfo `major:minor` always represents a directly selectable block device: pseudo filesystems, bind mounts, overlays and mapper stacks need explicit handling.
5. All parsed evidence must be tied to a bounded collection epoch and revalidated under the coordinator lock immediately before any future operation. Stable synthetic snapshots alone do not eliminate unplug/replug races.
6. Reject malformed, duplicate, contradictory, unresolvable, incomplete or source-untrusted evidence. Never infer safety from an empty caller-supplied report.

## Current parser limitations requiring separate tests/design

- Parser acceptance does not authenticate the source or certify completeness.
- Mountinfo optional fields are not yet semantically validated; numeric range and field-specific constraints require further review.
- Swaps paths are not normalized to trusted kernel objects; escaped paths, swapfile backing and filesystem ancestry are unresolved.
- No production integration should be performed until an independent, read-only OS5-compatible kernel collector is designed and separately approved.

## Next safe milestones

- Add **pure synthetic** tests for device-number cross-view disagreement, ambiguous swap path mapping, and swapfile backing ambiguity.
- Define a typed, per-node dependency graph with trusted parent and major:minor provenance and explicit source completeness.
- Review OS5 kernel behavior read-only before proposing any privileged collector.

**Non-goal:** This document, the parsers and their tests do not grant permission to encrypt, repartition, mount, unlock or otherwise modify any disk. Stable v0.5.5 and WD-managed filesystem permissions remain unchanged.
