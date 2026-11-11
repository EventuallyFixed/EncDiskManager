# Privileged operation protocol — design only

Status: **PROPOSAL / BLOCKED**. No implementation, deployment, packaging,
disk modification or automatic recovery-state clearance is authorized.
The administrator's accepted WD-managed directory-permissions risk is
recorded in `PRIVILEGED_OPERATION_READINESS.md`; do not change vendor paths.

## Trust boundary

A future root-controlled coordinator must be the only component allowed
to authorize a disk-changing native helper. Browser/UI data, preview
results, caller-provided file descriptors, arbitrary executable paths,
device nodes and shell fragments are **never** authority. PHP request
authentication, CSRF checks and strict authorization must precede the
coordinator boundary. The coordinator must independently validate the
request, device identity and live state under its exclusive lock.

## Proposed request contract (version 1)

Fields are conceptual, not an implemented API:

| Field | Type / constraint | Meaning |
| --- | --- | --- |
| `version` | integer exactly 1 | Protocol compatibility |
| `operation_id` | fresh 128-bit or stronger random ID | Idempotency and audit correlation; never authorization |
| `action` | allowlisted enum | `encrypt_ext4`, `unlock`, `mount`, `unmount`, `lock` |
| `target_id` | bounded stable identifier | Resolved afresh to a physical USB disk; not a caller-supplied path |
| `confirmation` | explicit action-specific authorization token/decision | Required for destructive encryption; validated server-side, single-use and bound to target and current state |

Reject missing, duplicate, unknown or oversized fields; unknown
versions/actions; expired or replayed confirmations; stale target
identity; ambiguous USB topology; partition/system-disk targets; and
any operation incompatible with current LUKS, mapper, mount or busy
state. Exact `target_id` provenance and token design remain to be
specified before implementation. Never log encryption passphrases.

## Durable recovery record (version 1 proposal)

Record must be unambiguous and bound to a single operation. Candidate
fields: `schema_version`, `operation_id`, `action`, canonical
resolved target identity (including stable hardware/topology evidence),
`phase`, `created_at`, and an integrity-checked bounded payload.
Timestamp alone is not an ordering or trust primitive. A checksum
detects accidental corruption, not malicious modification.

The record's encoding, maximum size, ownership (root:root), mode
(0600), atomic creation/update protocol, file and directory fsync,
inode checks, replay and migration rules must be specified and tested.
Never replace an unresolved record without explicit reconciliation.
Invalid, missing-required-field, corrupted or unsupported-version
records must fail closed, not silently reset. A pre-existing legacy
`disk-operation-recovery-required` marker also blocks authorization.

## Proposed state machine

| State | Meaning | Allowed transition |
| --- | --- | --- |
| `idle` | No unresolved record; lock acquired and live checks pass | `prepared` only after validated authorization |
| `prepared` | Durable pending record committed before child launch | `running` after verified launch, or `needs_reconciliation` on uncertainty |
| `running` | Child may be modifying disk | `verifying` after definite child exit, or `needs_reconciliation` on uncertainty |
| `verifying` | Child reaped; live postconditions being checked | `completed_pending_review` on verified success, else `needs_reconciliation` |
| `completed_pending_review` | Verified result, durable outcome still recorded | `idle` only by a separately approved finalization protocol |
| `needs_reconciliation` | Crash, uncertain outcome, bad record or failed durability | No automatic transition; reviewed manual reconciliation required |

The transition to `running` is descriptive: a crash between launch
and durable phase update must still leave a blocking `prepared` record.
All states other than `idle` must prevent a second disk operation,
including after lock release or restart. No automatic retry, rollback,
record deletion or marker clearance is authorized by this design.

## Failure rules

- Failure before a durable `prepared` record: do not launch a child.
  If any partial record exists, treat it as unresolved until reviewed.
- Failure after `prepared`, including fork/exec failure: retain the
  record and block new operations until reconciliation.
- Coordinator death, child disappearance, surviving child, timeout,
  loss of lock identity or uncertain disk state: fail closed.
- Failed write/fsync/rename/directory fsync: assume state uncertain;
  never report successful completion or authorize another operation.
- Reboot and repeated requests: unresolved state blocks regardless of
  process/PID status. No PID-only identity or stale preview bypass.

## Required review and tests before implementation

1. Threat-model web-to-root authorization and confirmation binding.
2. Define stable USB device identity and race-safe fresh state checks.
3. Specify exact record bytes, durability/atomicity and versioning.
4. Test all state transitions, replay, corrupted/partial records,
   injected I/O failures and coordinator/child interruption.
5. Validate PHP 7.4 and armhf runtime compatibility using separately
   approved non-destructive EX4100 tests.

**No implementation of this specification is approved yet.**

## Existing-code compatibility review (2026-11-11)

Reviewed `privileged_operation_request.php`,
`verified_operation_authorization.php`,
`operation_target_identity.php`, `operation_target_state.php`,
and `web/operation-preview.php` on the feature branch.

**Current read-only coverage:** existing request parser accepts exactly
`action` and `luks_uuid` for unlock/mount/unmount/lock. Registered
LUKS target binding checks UUID, kernel name and major:minor device
ID against complete USB snapshots; fresh mapper/mount inspection
rejects ambiguous or inconsistent states. The preview endpoint
checks session authentication and CSRF, and returns
`execution_enabled: false`. None of these paths enables execution.

**Protocol mismatch:** the proposed v1 request schema adds
`version`, `operation_id`, `target_id` and confirmation; it is
not implemented by the current two-field parser. Do not silently
reinterpret or weaken the existing parser. Implement a separately
versioned, tested boundary if approved.

**Critical encryption distinction:** an unencrypted USB disk has
no LUKS UUID, so `encrypt_ext4` cannot use the registered-UUID
identity contract. Define a separate, strong pre-encryption device
identity using fresh authoritative USB topology, stable hardware
evidence when available, whole-disk versus partition semantics,
major:minor and explicit system-disk exclusions. Device names and
major:minor numbers alone can be reused and must not be treated as
persistent identifiers. Reject ambiguous or changing identities.

**Confirmation binding:** the destructive confirmation must bind
the exact resolved target, operation, current observed state and a
short-lived single-use server-side authorization; a client checkbox
or previously rendered preview is insufficient. Server-side
eligibility must require a locked, unmounted target for re-encryption
and must independently reject inapplicable unlock/mount actions on
unencrypted media.

**Open review items:** inspect the complete authorization chain,
the authoritative USB discovery schema, registered/unregistered
disk handling, and any UI action-state logic before implementing a
new request parser or coordinator. This is a gap analysis, not a
claim that the existing code meets the proposed v1 contract.

Status remains **BLOCKED**. No NAS changes, production executor,
packaging or stable-branch changes are authorized.

## Unencrypted USB discovery and encryption identity review (2026-11-11)

Reviewed `app/lib/authoritative_usb_discovery.php`,
`app/lib/usb_discovery.php`, and
`app/lib/locked_operation_target.php`.

**Observed limitations:**
- Both USB discovery functions enumerate sysfs **partitions**
  (`/sys/class/block/<name>/partition`); a blank whole USB disk
  with no partitions is not represented by this inventory.
- The display inventory tolerates missing `blkid` metadata, but
  authoritative discovery fails closed if `blkid` exits nonzero.
  This is appropriate for registered LUKS inspection; it cannot
  establish that an unknown disk is empty or safe to erase.
- Current authoritative identity uses sysfs resolved USB ancestry,
  kernel partition name and major:minor, plus filesystem/LUKS UUID
  where present. These are not sufficient persistent identities for
  a blank disk, and kernel names and major:minor can be reused.
- Existing locked target checks intentionally require a LUKS UUID;
  they cannot authorize initial encryption of unencrypted media.

**Proposed separate read-only discovery contract:**
1. Enumerate complete USB **whole-disk and partition topology**
   from sysfs, explicitly distinguishing whole disks, partitions,
   parent-child relationships and all dependent holders/slaves.
2. Require successful, complete, repeatable probing of disk identity,
   transport ancestry, capacity, partition table, signatures,
   mounts, swap, holders and system/root-device exclusion.
   Missing data or probe errors mean **ineligible**, not empty.
3. Capture stable USB hardware identifiers when reliably available,
   such as serial/WWN, with collision and absence checks. A serial
   is not guaranteed unique or trustworthy; reject ambiguous cases.
   Bind a fresh in-process identity to kernel major:minor, resolved
   sysfs path and full topology, then revalidate immediately before
   any destructive transition.
4. Treat `encrypt_ext4` target scope (whole disk versus partition)
   as an explicit, reviewed product decision. Never infer permission
   to wipe an entire physical disk from a partition row.
5. Require explicit server-side destructive confirmation bound to
   that exact identity and state, and reject mounted, unlocked,
   busy, system, ambiguous or changed targets. No client-supplied
   `/dev` path is accepted as authority.
6. Preserve the existing display and registered-LUKS discovery
   paths; do not retrofit destructive authorization into them.

**Status:** design-only. No encryption target is currently authorized.
No privileged execution or WD-managed filesystem changes are allowed.

## Administrator decision: whole-USB-disk encryption scope (2026-11-11)

**Approved product behavior, not execution authorization:** the
administrator explicitly selected **erase and repartition the entire
selected physical USB disk** for the `Encrypt + ext4` action.
The target is the whole USB block device; a partition row must never
implicitly authorize wiping its parent disk.

Proposed workflow, subject to separate safety review:

1. Resolve the selected whole USB disk from trusted, fresh sysfs
   topology; bind identity, capacity, parent/child partitions and
   hardware evidence, and revalidate under the coordinator lock.
2. Exclude NAS/internal/system disks and any device with mounted,
   swap, open mapper, holder, dependent, busy or ambiguous state.
   Re-encryption requires prior unmount and lock. Fail closed if
   any probe is incomplete or identity changes.
3. Display an explicit entire-disk warning listing the target
   identity and stating **all partitions and all data will be
   permanently destroyed**. Require a fresh, single-use,
   server-validated confirmation bound to that disk and action.
4. Only after all separately reviewed authorization and recovery
   gates are satisfied may a future executor replace the partition
   table, create the intended partition, initialize LUKS1 on that
   partition and create ext4 inside the unlocked LUKS mapping.
   Partition table type/alignment, secure secret handling and
   exact tool invocation remain to be specified.
5. Persist the recovery state before any destructive child launch;
   failures must block further operations pending reconciliation.

Existing read-only partition inventory is not itself sufficient
for whole-disk authorization. UI must make disk-versus-partition
scope unmistakable; disabled actions remain non-activatable and
backend rules are authoritative.

**No destructive execution, deployment, merge or NAS changes are
authorized by this decision.**
