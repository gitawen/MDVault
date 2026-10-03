# ADR: Backup and restore of encrypted vaults (format 2)

- **Status**: Accepted (E10 approved 2026-10-03; Level 4 analyst sign-off 2026-10-03)
- **Date**: 2026-10-03
- **Phase**: Master Plan Phase 7 (§25–§29, §56 "Backup/restore works with encrypted Vaults")
- **Amends**: `backup-archive-format` ("Phase 7 decides whether encrypted vaults bump `format_version`") and `backup-restore-semantics`.

## Context
- Format 1 refuses `is_encrypted: true`. The Phase 6 ADR anticipated copying encrypted bytes unchanged, with readers seeing the on-disk (opaque) paths.
- The key file travels inside the vault folder (ADR `encrypted-vault-storage-layout`), so no wrapped key needs to go into the manifest.
- MDVault has not shipped yet (Phase 8), so no older reader exists in the wild.

## Options Considered
1. Decrypt into the backup. Puts plaintext in the archive. Rejected.
2. Re-encrypt the backup under a backup password. A second key system. Rejected for v1.
3. **Copy the ciphertext unchanged, with `format_version` 2 and a non-secret `encryption` object in the manifest (chosen).**

## Decision
- **`BackupService::FORMAT_VERSION = 2`** for every new backup. The reader accepts 1 and 2. Format 1 rules are unchanged; format 1 with encrypted vaults is still refused.
- **Creating a backup**:
  - Encrypted vaults are no longer skipped, and no unlock is needed.
  - `ensureHeaderOnDisk()` runs first.
  - Notes are the registry rows (opaque `.mdenc` paths). `mdvault-encryption.json` and `folder.mdenc` are listed under `files`.
  - Manifest vault entry: `"is_encrypted": true, "encryption": {"format": "mdvault-encrypted-vault", "format_version": 1, "key_id": "<uuid>", "cipher": "xchacha20poly1305-ietf", "kdf": "argon2id13"}`.
  - The manifest holds no salt, wrapped key, password, token or key.
- **Validation** (format 2, encrypted vault) adds these checks to stages 2–8:
  - The `encryption` object has exactly the allowed keys and values.
  - `mdvault-encryption.json` is listed in `files`. It is read (at most 64 KiB) and parsed with `EncryptionService::parseHeader()`, which needs no password. Its `key_id` matches the manifest.
  - Every note path matches `^([0-9a-f]{32}/)*[0-9a-f]{32}\.mdenc$`.
  - Every directory matches `^[0-9a-f]{32}(/[0-9a-f]{32})*$`.
  - The only other allowed files are `<dir>/folder.mdenc`.
- **Restore**:
  - In the same transaction, the vault is created with `is_encrypted=true` and a `vault_encryption` row built from the archived key file.
  - Notes get the encrypted attributes.
  - The restored vault is **locked**, including "restore as copy" (new vault UUID; `key_id` unchanged).
  - It opens with the password that was valid when the backup was made.
  - The inspect preview shows "Encrypted: you'll need its password".

## Consequences
- **Positive**:
  - Archives never contain plaintext from encrypted vaults.
  - Backup and restore need no password.
  - Integrity checks still hash the stored bytes.
- **Negative / trade-offs**:
  - Format 2 archives can't be read by a format-1 build (no such build has shipped).
  - After a password change, older backups still use the old password.
- **Follow-ups**: optional password-protected backups of the whole archive.
- **Implementation notes (7C, confirmed at sign-off)**:
  - Backups of an encrypted vault include only `<32hex>.mdenc` notes, hex folders, `folder.mdenc` files and the key file. A plaintext file dropped into an encrypted vault is never copied into the archive, so a "ciphertext only" backup never carries plaintext.
  - If an encrypted vault's key file is damaged and can't be recovered from the mirror, a single-vault backup fails (`encryptedKeyUnavailable`) and a full backup skips that vault (listed in `skippedVaults`), the same as a missing folder.
  - Validation also refuses:
    - `mdvault-encryption.json` or any `.mdenc` file in a vault that isn't marked encrypted (7C-QA-03);
    - a format 2 plain vault whose `encryption` value is anything but `null`;
    - duplicate ZIP entry names (7C-QA-06; libzip's consistency check refuses them too, so this is defence in depth).
