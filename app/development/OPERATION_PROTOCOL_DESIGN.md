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
