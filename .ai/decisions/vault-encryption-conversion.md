# ADR: Creating encrypted vaults, encrypting or decrypting existing vaults, and crash recovery

- **Status**: Accepted (E7 and E8 approved 2026-10-03; Level 4 analyst sign-off 2026-10-03)
- **Date**: 2026-10-03
- **Phase**: Master Plan Phase 7 (§7, §43, §56, §63 items 19–21; Rules 9, 10)
- **Amends**: `vault-removal-and-rename-semantics` (permanent deletion of the replaced original after a committed conversion; E7)

## Context
- §63 requires "Encrypt a Vault", so existing vaults must be convertible.
- The DB and the filesystem fail independently (§43, Rule 10). The existing patterns are:
  - restore stages content first, then renames folders inside the DB transaction, renaming them back if that fails;
  - vault rename uses rename-back compensation.
- On Windows, renaming a directory fails while another program has it open (ADR `vault-removal-and-rename-semantics`).
- A conversion must never leave a half-plaintext, half-encrypted vault, and it must never lose notes.

## Options Considered
1. **Convert file by file in place, with a journal.** Mixed states, resume logic and partial exposure. Rejected.
2. **Build the converted copy beside the vault, verify it, then swap folders (chosen).**
3. **Export to a new vault and leave the old one for the user to delete.** Leaves plaintext behind and needs a manual step. Offered in the UI copy as the advice for the most sensitive data, not as the mechanism.

## Decision
- **Create a new encrypted vault** (`VaultEncryptionService::createEncryptedVault`):
  1. `VaultService::create()`. Unchanged; the folder starts empty and unencrypted.
  2. Build a new key file and write it with `createFile`.
  3. In one DB transaction, insert `vault_encryption` and set `is_encrypted=true`.
  4. Store the key in the keyring and return a token. No second Argon2 run is needed.

  If step 2 or 3 fails, the key file is deleted (only if its bytes still match), and the vault is unregistered and its empty folder removed.
- **Encrypt an existing vault** (`VaultConversionService::encrypt`):
  1. Run recovery for this vault, refresh its status, and require it to be Active and not encrypted.
  2. Quick reconcile. If it is still stale after the retry, the vault is busy.
  3. **Preflight** with `FileStorageService::inventory()` (unfiltered). It refuses with a list of problems if the vault contains any of these (E7):
     - a file that is not `.md`;
     - a dot-entry (other than MDVault `.mdvault-save-*` files older than 60 s, which are discarded);
     - a symlink or junction;
     - a `node_modules` folder;
     - an unreadable directory;
     - a note larger than 16 MiB;
     - a name that is not valid UTF-8.

     The parent folder must be writable.
  4. Generate the key file and `VaultKey`.
  5. Staging folder `<parent>/.mdvault-encrypt-<vaultUuid>`, on the same volume. It is deleted first if it is left over:
     - write the key file;
     - recreate every folder as `<id>/` plus `folder.mdenc`;
     - encrypt every note into `<id>.mdenc`, checking that the bytes read hash to the value just reconciled.
  6. **Verify**:
     - re-read and decrypt every staged file and compare the plaintext SHA-256 with the source;
     - re-hash every source file.

     Any mismatch deletes the staging folder and reports "the vault changed while it was being encrypted; try again".
  7. **Commit**, in one DB transaction:
     - insert `vault_encryption`;
     - set `is_encrypted=true`;
     - update every note row: new opaque path, filename, title, extension, mime type, hash and size, `file_mtime` null, `is_encrypted`. UUIDs are kept.
     - Then `renameDirectory(V → <parent>/.mdvault-original-<uuid>)` and `renameDirectory(staging → V)`.

     If the second rename fails, rename the original back and throw, which rolls the transaction back. If the first fails, throw. The user sees "Close any programs using the folder; nothing was changed."
  8. After the commit, `deleteStagingDirectory(.mdvault-original-<uuid>)`. This **permanently deletes the unencrypted original** (E7). If that fails, report it and show a warning that names the folder; recovery retries later.
  9. Full reconcile, store the key and return a token.
- **Remove encryption** (`VaultConversionService::decrypt`, E8) mirrors this:
  - It requires the password, not just an unlocked key.
  - Staging is `.mdvault-decrypt-<uuid>`, with logical names written as `<name>.md`.
  - Duplicate or invalid names are made unique with " (2)", " (3)" and so on, or replaced by "Untitled".
  - Undecryptable notes abort the operation with a list.
  - On success the commit deletes `vault_encryption`, sets `is_encrypted=false` and restores readable paths. The ciphertext original is deleted and the keyring entry is forgotten.
- **Crash recovery** (`VaultEncryptionService::recover(Vault)`). Definitions:
  - `V` = the vault path; `O` = `.mdvault-original-<uuid>`; `S` = `.mdvault-encrypt|decrypt-<uuid>`;
  - `enc(V)` = whether `V/mdvault-encryption.json` exists;
  - the DB value is the committed truth.

  | State on disk | Meaning | Action |
  |---|---|---|
  | V missing, O exists | Crash between the two renames (not committed) | Rename O → V; delete S |
  | V and O exist, `enc(V)` ≠ `vaults.is_encrypted` | Both renames done, not committed | Rename V → S, then O → V; delete S |
  | V and O exist, `enc(V)` = `vaults.is_encrypted` | Committed; cleanup didn't finish | Delete O (permanent) |
  | V exists, no O, S exists | Crash while staging | Delete S |
  | V has a key file but the DB says unencrypted, no O or S | Inconsistent (manual copy) | No automatic action; the Workspace warns: remove the vault and use "Add existing folder" |

  It runs:
  - for every vault in `NativeAppServiceProvider::boot()` (errors reported, never fatal);
  - in `VaultService::open()`;
  - in `VaultService::refreshStatus()` when the folder is missing and O exists;
  - at the start of encrypt, decrypt and password change.

  Every action uses `renameDirectory` (never overwrites) and `deleteStagingDirectory`. The latter is extended to the allow-listed prefixes `.mdvault-encrypt-`, `.mdvault-decrypt-` and `.mdvault-original-`, so it stays the only recursive delete.

## Consequences
- **Positive**:
  - At any instant, V is either the full original or the full converted vault.
  - Every crash point has one recovery rule.
  - Note UUIDs are preserved.
  - Nothing changes when a Windows lock blocks the swap.
- **Negative / trade-offs**:
  - Needs free space for a second copy, plus a writable parent folder.
  - Vaults with attachments or `.git` must be cleaned up first.
  - Deleting the original is permanent. It does not securely wipe data on SSDs, and copies in backups, sync history or the Recycle Bin are untouched (the UI warns).
  - For a few milliseconds the vault can appear Missing (recovery fixes it).
- **Follow-ups**:
  - Encrypting attachments; offering "back up first".
  - AR-01: the conversion lock TTL (`LOCK_SECONDS` = 1800) caps a conversion at 30 minutes. A longer one loses its lock and is aborted, safely, by the commit guard. Refresh the lock during the build, or scale the TTL, if very large vaults must be convertible.
  - 7C-QA-08: optional named rate limiter keyed by vault UUID instead of the shared `throttle:10,1` bucket.
- **Implementation notes (7C)**:
  - `recover()` lives in `VaultRecoveryService` (depends only on `FileStorageService`, the cache and the DB, so `VaultService` can call it without a cycle); `VaultEncryptionService::recover()` delegates to it. A cache lock (`mdvault.conversion.<uuid>`) is held for the whole conversion, and recovery does nothing while it is held, so a request that opens the vault mid-swap can never repair a conversion that is still running.
  - 7C fix round 1: the lock carries an owner token (only its owner releases it); `recoverAll()` at boot force-releases any stale conversion lock (single-instance app: none can be in flight, and a persisted cache store keeps the row for up to `LOCK_SECONDS` after a crash); and `commit()` re-checks, after the DB writes and right before the first rename, that the lock is still owned and that the vault folder snapshot is unchanged, aborting (DB rolled back, nothing moved) otherwise.
  - **Single-instance assumption (7C-QA-07, accepted at sign-off)**: the boot force-release is safe only because `NativeAppServiceProvider::boot()` runs once per app launch (Electron's "booted" request) on a single-worker `php -S` server, so no conversion request can be in flight or run concurrently. If MDVault ever runs several PHP workers or instances, or re-runs `boot()` mid-session, this must be revisited. The commit guard still makes a broken assumption fail safe: the conversion aborts and nothing changes.
  - The commit's two renames are the last step inside the DB transaction. If the commit fails after the swap, the swap is undone; if even that fails, the next recovery does it.
  - Encrypt runs a Full (not Quick) reconcile first, and re-checks a before/after picture of the whole folder (every entry, size and mtime) as well as every source hash, so a file added or changed during the build aborts the conversion.
  - Encrypt preflight additionally refuses names that are invalid for new encrypted notes (`assertValidNoteSegment`), two notes in one folder whose names differ only by case, and insufficient free disk space (vault size plus 8 MiB), so every converted name can be renamed and converted back.
  - `encrypt` and `decrypt` return `ConversionResult` (token, note count, cleanup warning), not a bare token.
  - Remove encryption refuses to continue if the folder holds anything other than MDVault's own files (a plaintext file would be deleted with the original), or any unreadable note.
  - Conversion failures are reported through a fresh exception with no bindings, because a `QueryException` carries decrypted names during a decryption.
  - Decrypt and change-password verify the password with an Argon2 run, so their routes carry `throttle:10,1` like unlock. The bucket is shared (keyed by user or IP, not by route), which is acceptable for a single-user desktop app.
