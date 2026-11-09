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
