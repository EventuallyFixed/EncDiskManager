# v0.6.0 privileged-operation readiness review

Status: **BLOCKED — review only; no NAS deployment or disk-changing operations authorized.**

This checklist records source-level observations on the feature branch. A passing
CI suite does **not** demonstrate that privileged disk operations are safe on
WD My Cloud EX4100 hardware.

## Confirmed source-level boundaries

- `app/web/operation-preview.php` is read-only; it returns
  `execution_enabled: false` and does not invoke a disk executor.
- `app/lib/authorized_operation_inspection.php` combines POST, HTTPS,
  authenticated session, CSRF, strict action/UUID validation, registry
  membership and live target-state inspection under a disk lock.
- `app/lib/luks_subprocess.php` permits only fixed `luksOpen`/`luksClose`
  argv shapes and is not wired to the web endpoint.
- The legacy `app/bin/encdiskmanager-helper` is **not** a generic
  v0.6.0 executor. It contains a fixed UUID, keyfile, mapper and mountpoint.

## Blocking verification before enabling any executor

1. **Executable location and trust mismatch (partially verified on EX4100):**
   `/usr/sbin/cryptsetup` is a root-owned symlink to the root-owned executable
   `/usr/local/modules/usr/sbin/cryptsetup` (mode 0755). The feature-branch
   resolver, allowlist, and subprocess argv checks now use only the explicit
   canonical module path and continue rejecting symlinks. Still verify all
   ancestor directory ownership/permissions and review executable replacement
   or path-race risks before enabling execution. This is not an approval to
   invoke cryptsetup.
2. **Web privilege boundary (root confirmed):** The read-only EX4100 inventory
   reported four PHP-FPM processes with real/effective/saved UID 0 and GID 0.
   PHP CLI is 7.4.33 (also UID 0); Linux is 4.14.22-armada-18.09.3 on armv7l.
   Root PHP-FPM is a high-impact execution boundary: review process isolation,
   application path ownership, session/CSRF handling and private data security
   before enabling any privileged action.
3. **Execution-time identity:** A future executor must revalidate the target
   identity and action-specific state while holding the same lock immediately
   before execution, then verify the resulting state. A preview result must
   never serve as authorization for a later action.
4. **Process lifecycle:** Review `proc_open` array argv support, PHP version,
   signal handling, child reaping, and timeout behavior on WD OS5/armhf.
   The subprocess runner currently has no hardware-backed integration test.
5. **Mount namespace and kernel state:** All four reported PHP-FPM processes
   share mount namespace `mnt:[4026531840]`. Confirm the namespace inspected
   by PHP matches the namespace where any future mount/unmount subprocess
   operates. Verify USB identity, mapper identity and mount state
   against real non-destructive EX4100 observations.
6. **Credential handling:** Define a secure, non-logged passphrase/key
   transport and ensure no secrets appear in argv, environment, HTTP response,
   application logs or error diagnostics.
7. **Action-by-action policy:** Review Unlock, Mount, Unmount and Lock
   preconditions, race behavior and postconditions separately. Encryption
   and ext4 formatting must remain disabled until their own destructive-action
   review and explicit confirmation tests are complete.
8. **Hardware tests:** Begin with read-only environment checks; only after
   separate explicit approval use a disposable USB disk for any mutating test.
   Never use internal NAS volumes.

## Release gate

Do not merge, package, deploy, tag or enable privileged execution until the
above checks are resolved, regression CI passes, and hardware-specific
authorization is separately given. Stable v0.5.5 on `main` remains untouched.

## Read-only EX4100 inventory procedure

A non-mutating diagnostic script is available at
`app/development/ex4100-readonly-inventory.sh`. Review the script before
running it manually on the NAS with `sh`; it does not need to be installed
as an application component. It prints executable path metadata, PHP CLI
version and UID, and readable PHP-FPM process UIDs and mount namespace IDs.
It does **not** run cryptsetup, mount, umount, or any disk operation.

Do not assume the PHP CLI UID matches PHP-FPM worker identity. The inventory
does not establish filesystem or disk-operation readiness by itself.
Review its output locally; redact any identifying host details before sharing.

## Keyslot mutation gate

The restricted keyslot subprocess explicitly rejects `add-key`, even when
its command shape is valid. The keyslot planner and private-key binding are
non-executing preparation only. Before this gate can be removed, implement
and test a privileged caller that holds the shared disk lock, proves current
USB device identity and LUKS1 UUID, validates the private keyfile and its
ancestor paths, binds recovery credentials securely, and verifies the
postcondition. Passing CI alone does not authorize keyslot mutation.

## Subprocess lifecycle review

The restricted Unlock/Lock runner now rejects malformed process status,
requires a confirmed child exit, and rejects exit observed after the deadline.
These are defensive source-level checks, **not** hardware validation. The
remaining release blocker is to prove on PHP 7.4/EX4100 that timeout,
SIGKILL, `proc_get_status`, and `proc_close` reliably reap the exact
child before the shared operation lock is released. No disk-changing
integration tests are authorized.

### Cleanup uncertainty (still blocking)

The Unlock/Lock runner records cleanup confirmation only after an explicit
non-running child status, and treats malformed status as uncertain. However,
`proc_close()` may block beyond the requested timeout, and a short reaping
window is **not** proof that an unobserved child has stopped. The code must
not be represented as having a hard execution-time bound. Before enabling
execution, validate PHP 7.4 process handling on EX4100 using harmless test
processes, including forced termination, ambiguous status, exit-code handling
and cleanup while holding the lock. Any unresolved child must prevent
authorization of subsequent operations; do not rely on a failed return value
alone to imply the device was unchanged.

### Shared-lock failure boundary

The shared `flock` is released in a `finally` block when its callback
returns or throws. A harmless regression test now checks that exception
path. This is appropriate for ordinary callback errors, **but unsafe as a
complete containment mechanism for an unconfirmed subprocess**: after
`edm_luks_subprocess()` returns false, the callback can finish and the lock
can be released even if process cleanup was not conclusively observed.

**Release blocker:** implement and test a persistent, root-owned
fail-closed recovery/quarantine state for uncertain child termination, or
otherwise prove every child is reaped before releasing the operation lock.
A subsequent disk operation must be denied until the uncertainty is
resolved by authoritative reconciliation. A plain boolean failure return
does not enforce this requirement. Do not wire up execution while this
boundary is unresolved.

### Persistent recovery stop marker (partial safeguard)

While holding `disk-operations.lock`, the lock wrapper now refuses to
invoke callbacks if `disk-operation-recovery-required` exists in the
private registry directory. A root-owned regular marker with mode 0600
blocks operations; symlinks or unsafe marker metadata also fail closed.
Regression tests exercise both paths. The marker is never automatically
removed.

**This is not a complete recovery implementation.** No subprocess currently
writes the marker on uncertain termination, and there is no reviewed
reconciliation or clearance workflow. A marker also does not by itself
stop a child already running. Crash-safe marker creation and persistence,
the process-to-operation binding, recovery verification, and preventing
unresolved children from outliving lock ownership remain release blockers.
Do not enable privileged execution on the strength of this guard alone.

### Recovery marker writer (not integrated)

`edm_disk_operation_mark_recovery_required()` provides an exclusive-create,
root-owned mode-0600 marker writer and rejects unsafe existing markers.
It must only be called while holding the shared disk-operation lock.
Harmless root-only tests cover creation, repeat calls, and symlink rejection.
The writer is **not** called by subprocess code, does **not** clear markers,
and does **not** claim power-loss durability: PHP 7.4 has no portable fsync
for the marker and containing directory. Validate durable persistence and
the full process/lock recovery protocol before enabling disk execution.

### Proposed crash-safe operation lifecycle (design only)

A post-failure marker alone is insufficient: a PHP worker can die after
launching a child but before writing the marker. The future privileged
executor therefore needs a **write-ahead pending-operation record**,
persisted before `proc_open`, while the shared disk-operation lock is held.
The record must bind a unique operation identifier to the action, verified
LUKS UUID, USB device identity, expected mapper, and phase. It must never
contain passphrases, key bytes or untrusted argv.

Required phases:
1. Under the lock, revalidate authorization, registry ownership, USB
   identity, and process policy; reject any pending/recovery record.
2. Create a pending record using exclusive, root-owned storage; verify
   durable file contents and directory entry before launching a child.
   A failed persistence step must prevent launch.
3. Launch only the separately approved restricted command. Keep the lock
   throughout execution, termination, reaping and postcondition checks.
4. On a confirmed clean child exit **and** authoritative postcondition
   verification, durably transition the pending record to resolved before
   allowing another operation. Unexpected exit, timeout, uncertain child,
   PHP crash, or failed postcondition leaves recovery required.
5. Recovery after restart must be a separately authenticated, reviewed
   reconciliation path. Never infer safety from PID alone (PID reuse);
   inspect the device/mapper state and process identity, and never clear
   the record automatically based on elapsed time.

Durability is a release blocker: `fflush` alone does not provide an
on-disk commit after power loss. A supported fsync/fdatasync strategy for
both the file and containing directory is required and must be tested on
the EX4100 firmware/filesystem. The current PHP 7.4 writer is not
sufficient. Also consider that a confirmed child exit with a failed
postcondition still requires recovery; do not clear on exit code alone.

**Status: DESIGN ONLY.** No pending-record writer, state-clearance
function, subprocess integration, or web executor is authorized by this
section. Stable v0.5.5 and deployed NAS behavior must remain unchanged.

### EX4100 persistence capability assessment (no disk-data sync feature)

This section concerns only EncDiskManager's tiny internal safety journal,
**not** copying, mirroring, or synchronizing user files on USB disks.

On the target firmware, inspect the actual PHP CLI/FPM versions and whether
`fsync`/`fdatasync` functions are available. PHP 7.4 normally lacks
these stream functions; do not assume the NAS uses a newer PHP version.
Also inspect whether the installed, trusted system utilities can durably
sync a file **and its containing directory**. A plain `sync` command or
`fflush` alone is not proof of the required ordering or crash safety.
No helper is approved merely because a binary exists.

Implementation options to evaluate, in order:
1. A narrowly scoped, root-owned native helper using `openat` with
   `O_NOFOLLOW|O_CLOEXEC`, exclusive file creation, complete writes,
   `fsync(file)`, `fsync(directory)`, and explicit error reporting.
   Audit and test its ownership, ancestor trust, argv policy, and
   packaging for armhf before allowing it to be invoked.
2. A supported PHP runtime providing reliable stream synchronization
   and directory-entry persistence, if proven on the target firmware.
3. If neither is viable, retain the operation-execution block and use
   non-executing preview/registration functionality only.

Do not use the USB device as journal storage: keep the internal safety
record in the root-owned private NAS application data directory. A helper
must never accept arbitrary paths from an HTTP request or run a disk
command. A pending record must be durably committed before a child is
launched, and clearing it must be an independently durable, reviewed
transition after verified completion. Test failure injection, partial
writes, power-loss/restart behavior and filesystem semantics separately.
No EX4100 capability or durability has yet been verified by this design.

### Read-only EX4100 journal capability inventory

Run `sh app/development/ex4100-recovery-capabilities.sh` manually from
a checked-out feature branch **only when convenient**. The script prints
kernel architecture, PHP CLI function availability, compiler/tool presence
and metadata about the private application-data filesystem. It does not
write files or invoke cryptsetup. Do not mistake PHP CLI capability for
PHP-FPM worker capability. Do not upload the output publicly without
reviewing host-specific path and filesystem metadata. No result has yet
been collected from the NAS.

### EX4100 observed inventory (2026-11-10; user-supplied)

Read-only script output from WDMyCloudEX4100:
- Linux `4.14.22-armada-18.09.3`, `armv7l`.
- PHP CLI `7.4.33`; `fsync` and `fdatasync` unavailable.
- PHP CLI exposes `posix_geteuid`, `proc_open`,
  `proc_get_status`, `proc_terminate`, `proc_close`.
- No `cc`, `gcc`, or `arm-linux-gnueabihf-gcc` on the NAS;
  `sync` and `stat` available, `findmnt` unavailable.
- `/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager` is root:root,
  mode 0700, on ext4 filesystem `/dev/mapper/md1`.

Implication: native helper must be cross-compiled off-device for armhf
and separately reviewed before packaging. PHP CLI feature presence
does not establish FPM identity or subprocess safety. ext4 and the
presence of `sync` do not demonstrate durable ordered writes.
A helper should accept a fixed operation and fixed, trusted private
directory only, use no USB disk paths, and must not execute cryptsetup.
Any helper testing must be non-destructive and separately approved.
**Execution remains blocked.**

### Experimental native marker prototype (not packaged)

`app/development/native/disk_recovery_marker.c` is source-only and
unintegrated. Its sole `mark` command attempts exclusive creation of
`disk-operation-recovery-required` in the fixed private NAS data directory,
followed by `fsync` of the marker and directory. It refuses non-root
invocation, symlink traversal at the final directory component and marker,
unsafe directory/marker metadata, and existing markers. It never clears
a marker, runs cryptsetup, or accesses a USB disk.

**Do not run this prototype on the NAS**: a successful invocation leaves
a recovery-required marker that deliberately blocks disk-operation lock
callbacks. It has not been cross-compiled, tested on the EX4100, or audited
for trusted ancestor path resolution, lock ownership, partial-failure
handling, error injection, packaging or firmware behavior. No runtime
integration is authorized. Native compilation and harmless isolated tests
must precede any on-device evaluation.

### Native helper compile-only validation

`sh app/development/tests/native_recovery_marker_compile_test.sh`
checks the experimental C source with host `cc -fsyntax-only` and
strict warnings when a host compiler exists. It **never runs** the
helper, never creates a marker, and does not cross-compile an ARM
binary. It prints SKIP if no compiler is present. A passing compile
check is not evidence of secure runtime behavior, armhf compatibility,
or durability on the NAS. The helper remains source-only and unapproved
for deployment.

### ARM cross-compilation outcome

GitHub Actions run #235 passed the native ARM hard-float cross-compile,
the native host compile and isolated runtime tests, PHP tests, browser
tests and root-authorization tests. This establishes successful
cross-compilation, **not** runtime compatibility with EX4100 firmware.
The CI workflow now also prints the ELF program interpreter and
dynamic-library requirements, but no resulting executable is uploaded,
packaged, deployed or run on the NAS. Before any on-device test, compare
those requirements with the NAS's actual libc and dynamic linker and
review ancestor-path trust, lock ownership, error handling and durability.

### ARM runtime dependency inventory

GitHub Actions runs #236 and #237 passed all five jobs. The cross-built
prototype is ELF32 ARM EABI5 hard-float, dynamically linked with
`/lib/ld-linux-armhf.so.3`, and requires `libc.so.6`.
This is a build result, not confirmation of the NAS runtime ABI or
minimum GLIBC symbol versions.

`sh app/development/ex4100-arm-runtime.sh` is an optional read-only
NAS inventory of the dynamic linker, common libc paths, and
`getconf GNU_LIBC_VERSION`. It never invokes the prototype or touches
USB disk contents. Review output before sharing host metadata. Compare
with symbol-version requirements from the final cross-build before any
runtime evaluation.

### Observed EX4100 glibc and version gate

User-supplied read-only NAS inventory confirms
`/lib/ld-linux-armhf.so.3 -> ld-2.31.so`,
`/lib/libc.so.6 -> libc-2.31.so`, and `glibc 2.31`.
The absence of Debian multiarch library paths is expected for this
firmware layout and is not itself an incompatibility.

The ARM cross-compile CI now inspects ELF GLIBC symbol requirements
and fails if a required version is newer than 2.31. This check does
not prove complete runtime compatibility, but catches one common
cross-toolchain mismatch before any on-device execution.

### Critical ARM libc incompatibility found

Although CI runs #240 and #241 were green, their ARM build logs
explicitly showed `GLIBC_2.34` as a required symbol version. The
EX4100 has glibc 2.31, so **the produced ARM binary is incompatible**.
The initial grep-based version gate had an escaping bug and missed this
requirement. The CI gate has been replaced with numeric parsing of
`readelf --version-info` requirements; the next cross-compile must
fail until the toolchain is changed or symbol requirements are reduced.
Do not deploy or run the current binary on the NAS. Passing host-side
tests does not override this incompatibility.
