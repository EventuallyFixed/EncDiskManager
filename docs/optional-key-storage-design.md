# Optional key storage and disk registration (design decision)

Status: approved design; **not yet implemented**.

## Registration after encryption

- After successful LUKS1 formatting and ext4 creation, register the disk using its verified LUKS UUID, a validated display name, unique mount folder under `/mnt`, deterministic mapper name, and enabled state.
- If registration fails, report that encryption succeeded but registration did not; never automatically format again.
- Keep the registry JSON metadata-only. Never store plaintext passphrases or key bytes in it.
- Use the existing registry validation and atomic-save mechanisms, with appropriate locking and race checks.
- Re-encryption invalidates the old LUKS UUID and any prior stored key: replace the registry entry and retire the old key only after the new disk is verified and registered.

## Optional stored key

- Offer a per-disk opt-in during registration. Default to **not** storing a key.
- For opt-in, generate a cryptographically secure random key on the NAS, add it to a separate LUKS keyslot, and store it in a dedicated root-owned `0700` private key directory, with individual root-owned `0600` regular files.
- Reject symlinks, unsafe path components, unexpected ownership/modes, and untrusted file names. Derive file identity from the verified LUKS UUID, never a browser-provided path.
- Always retain an independently tested recovery passphrase in another LUKS keyslot; verify recovery access before reporting success.
- A stored key is convenient but is **not** protected from a root compromise or an attacker with an unencrypted NAS backup containing the key. Provide a separately protected recovery/export procedure; exclude key material from the normal configuration backup.
- Never pass secrets in command-line arguments, URLs, logs, or JSON responses. Use cryptsetup keyfile or a carefully controlled file descriptor; minimise secret lifetime.
- Avoid changing the existing registry schema until a backwards-compatible migration and backup/restore policy are designed and tested.

## UI actions

- Unlock: open LUKS mapping without mounting; use stored key if available, otherwise request passphrase.
- Mount: when locked, explicitly unlock using stored key or requested passphrase, then validate ext4 and mount under the nominated `/mnt/<mount_folder>`. When already unlocked, mount directly.
- Unmount: unmount without closing the mapping.
- Lock: close only an unmounted mapping.
- Encryption/re-encryption: never automatically unmount or close mappings.
- The action-state policy and all UI disabled states are advisory only; privileged helper must independently verify live USB ancestry, UUID, device topology, active mappings, mounts, and destination before every operation.
- For a locked disk, Mount may be enabled once the backend supports the unlock-and-mount transaction. Until then, do not enable an inoperative UI control.
- A failed mount after unlocking must report partial state accurately and must not silently discard the mapping.

## Compatibility

- Preserve existing WD EX4100-compatible LUKS1 AES-CBC-ESSIV:SHA256 profile.
- Existing hard-coded backup-disk helper behaviour must remain intact until replaced by a tested, registry-driven implementation.
- No destructive NAS operations are authorised by this design document.
