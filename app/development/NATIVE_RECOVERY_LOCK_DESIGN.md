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
