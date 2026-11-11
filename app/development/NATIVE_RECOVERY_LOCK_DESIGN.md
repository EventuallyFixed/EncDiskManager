# Native recovery marker lock coordination — design only

Status: **PROPOSAL / BLOCKED**. No implementation authorization for disk operations, deployment, packaging or marker clearance.

## Current coordination boundary

PHP `edm_with_disk_operation_lock()` takes an exclusive `flock` on
`disk-operations.lock`, verifies its inode against the pathname after locking,
and refuses to invoke the callback when a recovery marker exists. The
experimental C helper currently creates a marker without taking this lock,
so invoking it independently would not establish safe serialization.

## Proposed single-owner protocol

1. An authenticated, reviewed operation coordinator acquires the existing
   application-wide exclusive lock and verifies the root-owned 0600 regular
   lock file and root-owned 0700 private directory.
2. Under that lock, re-check the unresolved recovery marker and the live disk
   identity and state. Never authorize from a stale preview.
3. Before any subprocess that could outlive its parent, create and durably
   persist a write-ahead pending-operation record. If durability cannot be
   established, do not start the child.
4. The native helper must not independently acquire a second lock while the
   coordinator holds it. A future interface should use a carefully reviewed
   inherited file descriptor or another verifiable lock-ownership protocol,
   and verify that the descriptor identifies the expected lock inode.
   Merely passing a numeric FD or a boolean `lock_held` is insufficient:
   FD inheritance and `flock` open-file-description semantics must be
   tested with PHP 7.4, the EX4100 kernel, and subprocess lifecycle behavior.
5. Keep serialization effective until the child has been definitively
   reaped and the postcondition verified. If parent/child fate is uncertain,
   retain a durable fail-closed recovery state and block further operations.
6. Never automatically clear recovery state. A separate reviewed
   reconciliation procedure is required.

## Required isolated tests before integration

- Reject missing, wrong, symlinked, replaced, or unlocked lock descriptors.
- Prove that a competing process cannot enter while the coordinator is active.
- Test fork/exec inheritance and process termination without releasing
  serialization prematurely.
- Simulate write, fsync, directory fsync and child-start failures.
- Prove recovery state remains blocking after interruption and restart.
- Validate every private-path ancestor against the actual EX4100 filesystem
  layout and assess mount/replacement races.

The existing native marker helper is **not** a lock-aware executor. This
document is a design boundary, not a claim that these tests have passed.

## EX4100 fixed-path trust model (design only)

The user has chosen to retain
`/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager`. Read-only inventory shows
`/mnt/HD/HD_a2` and `Nas_Prog` are root-owned **0777**, while the
private directory is root-owned **0700**. No listed SMB share points
directly at `Nas_Prog`; this does not eliminate local rename attacks.

A future implementation should start from a trusted directory FD and
traverse each path component with `openat(O_DIRECTORY|O_NOFOLLOW)`,
checking directory type, ownership, and expected device/mount boundary.
Use descriptor-relative `openat`/`fstatat` for lock and marker files,
and compare open inode identities to the expected pathname under the
same trusted context. A stable opened FD protects operations on that
opened inode but **does not guarantee** that the pathname continues to
refer to it. Re-check identity at critical transitions and fail closed
on mismatches.

**Fundamental unresolved risk:** a non-root local process with write
permission on `Nas_Prog` can replace the private directory entry
between checks. No sequence of pathname checks can turn a
world-writable, non-sticky parent into a trusted namespace. Production
authorization requires a separately reviewed mechanism that prevents
or reliably contains this replacement risk, or an explicit redesign
of the security boundary. Do not silently chmod vendor directories,
assume root-only SSH proves local isolation, or treat the absence of
a direct SMB share as sufficient. Privileged operations remain BLOCKED.

## Inherited descriptor authentication: design decision (2026-11-11)

The isolated identity and `flock` experiments passed feature CI #286
and #288, respectively. They establish Linux descriptor inheritance
behavior, **not** a secure proof that an arbitrary inherited FD owns
the application lock.

### Threat and verification boundary

A native child must reject a missing, closed, nonregular, wrong-inode,
symlink-substituted, or unexpectedly unlocked descriptor. Checking
`fstat(fd)` against the lock pathname establishes file identity only.
Calling `flock(fd, LOCK_EX | LOCK_NB)` on the *inherited FD* is **not**
an ownership test: it may acquire a previously unlocked file or
succeed because the FD shares the parent's open-file description.
Likewise, contention observed through a second independently opened
FD proves only that *someone* holds a conflicting lock; it does not
authenticate the passed FD as its owner. Linux `F_GETLK` is not a
reliable substitute for BSD `flock` ownership authentication.

### Proposed fail-closed coordinator protocol

1. A single trusted, root-run coordinator opens the fixed lock file
   using descriptor-relative, no-follow operations, verifies inode
   identity and permissions, and acquires `LOCK_EX` itself. Do not
   accept an arbitrary FD supplied by an untrusted caller.
2. While holding the lock, verify the unresolved recovery state and
   current disk identity/state; create and fsync a durable write-ahead
   pending-operation record before starting any potentially persistent
   child. A failed durability step prohibits child launch.
3. Launch only an explicitly selected native executable, passing a
   *known coordinator-owned* lock FD and a separate authenticated
   operation context. Verify the child inherited the expected inode;
   treat that as a consistency check, not standalone authorization.
4. Keep a coordinator-owned duplicate/open-file-description reference
   to the lock until the child is definitively reaped and postconditions
   are checked. Avoid independent re-acquisition by the child and
   document `FD_CLOEXEC` handling explicitly.
5. On coordinator death or uncertain child fate, the previously
   durable pending-operation record must block new operations. Do not
   infer safety from lock release, PID existence, or a successful
   descriptor check. Recovery requires explicit reconciliation.
6. No external/untrusted process may invoke the helper as an
   authorized executor solely by presenting an FD number. Define and
   review the privileged invocation boundary before implementation.

### Remaining isolated tests

- Coordinator-created FD handoff and positive inode verification.
- Closed, wrong-inode, replaced-path and unlocked FD rejection **at
  the coordinator authorization boundary**, not by a misleading
  inherited-`flock` probe.
- Failure injection before/after durable pending-record fsync,
  fork/exec failure, unexpected parent exit, child exit and restart.
- Prove a competing operation cannot pass authorization while a
  pending record remains, including after lock release.
- Cross-compile for armhf and validate runtime behavior on the EX4100
  only after a separately reviewed, non-destructive test plan.

**Status remains BLOCKED.** This is a design record, not approval to
deploy, package, merge or perform privileged disk operations. The
administrator's accepted WD directory-permissions risk remains as
documented in `PRIVILEGED_OPERATION_READINESS.md`; no changes to
WD-managed directories are proposed.
