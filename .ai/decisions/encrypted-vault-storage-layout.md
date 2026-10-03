# ADR: Encrypted vault storage layout, filename encryption and registry contents

- **Status**: Accepted (E3, E4 and E11 approved 2026-10-03)
- **Date**: 2026-10-03
- **Phase**: Master Plan Phase 7 (§9, §11–§18, §24, §31, §32, §37–§39, §56; Rules 1–5, 8, 9)
- **Resolves**: the Phase 7 follow-ups in `database-reset-semantics`, `external-change-detection` and `note-registry-and-indexing`.

## Context
- §32 requires note content, folder names and file names to be protected, with "sensitive metadata where practical". Its example shows opaque names (`7e9c31a4/8af29c11`).
- The registry is path-based:
  - `notes.relative_path` is unique per vault;
  - reconciliation pairs files by exact path, then case-insensitive path, then identical hash, then file name;
  - the tree is built from DB rows plus a directory scan;
  - backups skip dot-prefixed entries.
- "Reset database" (`resetRegistry`) and vault removal delete the DB rows. "Add existing folder" registers folders. If key material lived only in SQLite, either action would make an encrypted vault's files permanently undecryptable (`database-reset-semantics` follow-up).
- The SQLite DB is itself an unencrypted file on disk. Anything stored there in plaintext is exposed at rest.

## Options Considered
- **Filename scheme**:
  1. Deterministic per-segment name encryption (SIV/EME style). libsodium has no SIV or EME, so we would have to build our own synthetic-IV construction, and long encoded names run into path limits. Rejected.
  2. **Random opaque IDs on disk, with each real name stored encrypted inside its own file: the note file, or a per-folder `folder.mdenc` (chosen).**
  3. One encrypted index file mapping IDs to names. A single point of failure that external edits and merges can break. Rejected.
- **What SQLite stores for encrypted notes**:
  - (a) plaintext titles and paths: leaks names. Rejected.
  - (b) an encrypted name cache: a second copy to keep consistent, plus a schema change. Deferred.
  - **(c) opaque on-disk paths only (chosen).**
- **Key material location**:
  - (a) SQLite only. Unrecoverable after a reset, removal or move to another machine. Rejected.
  - **(b) a key file in the vault root that is the source of truth, mirrored in `vault_encryption` (chosen, E3).**

## Decision
- **On-disk layout** (vault folder name = vault name, readable, E4):
  ```text
  Work/
  ├── mdvault-encryption.json            key file (no secrets): source of truth for key material
  ├── 5d0c…e1.mdenc                      note at the vault root
  └── 9a41…7b/                           folder (32-hex id)
      ├── folder.mdenc                   its encrypted name
      └── c3e8…0f.mdenc                  note
  ```
  - Note pattern: `^[0-9a-f]{32}\.mdenc$`.
  - Folder pattern: `^[0-9a-f]{32}$`.
  - Reserved names: `folder.mdenc` and `mdvault-encryption.json`.
  - None of these start with `.`, so backups and the indexer see them.
- **Key file** `mdvault-encryption.json` (UTF-8 JSON, at most 64 KiB):
  ```json
  {
    "application": "MDVault",
    "format": "mdvault-encrypted-vault",
    "format_version": 1,
    "key_id": "<uuidv7>",
    "key_version": 1,
    "cipher": "xchacha20poly1305-ietf",
    "kdf": {"algorithm": "argon2id13", "opslimit": 3, "memlimit": 268435456, "salt": "<b64>"},
    "wrapped_key": {"nonce": "<b64>", "ciphertext": "<b64>"},
    "created_at": "2026-10-03T10:00:00Z"
  }
  ```
  - It is written with `FileStorageService::createFile` (exclusive create) or `replaceFile` with a hash guard, so it is never overwritten blindly.
  - **The disk copy is authoritative.**
    - On unlock, if its SHA-256 differs from `vault_encryption.header_hash`, the key file on disk is parsed and the mirror is updated.
    - If the file is missing but the mirror exists, unlock uses the mirror. After a successful unwrap (which proves the mirror is genuine), the file is re-created with `createFile`.
    - Backups call `ensureHeaderOnDisk()` first.
- **`vault_encryption` table** (the §31 table, Master Plan name):
  - `id`
  - `vault_id` (FK `vaults`, `cascadeOnDelete`, unique)
  - `key_id` (string 36)
  - `key_version` (unsigned int, default 1)
  - `algorithm` (string 50)
  - `kdf_algorithm` (string 30)
  - `kdf_opslimit` (unsigned int)
  - `kdf_memlimit` (unsigned big int)
  - `salt` (string 64, base64)
  - `nonce` (string 64, base64)
  - `encrypted_key` (text, base64)
  - `format_version` (unsigned small int)
  - `header_hash` (char 64)
  - timestamps

  These values are not secret; they are the same as the key file.
  - `resetRegistry()` deletes `vault_encryption` rows explicitly, before notes and vaults.
  - `VaultService::remove()` relies on the cascade, and also forgets any unlocked key.
- **What SQLite may store for encrypted vaults (E4)**:
  - Vaults: `name`, `description`, `path`, `relative_path` (readable, shown while locked); `is_encrypted = true`.
  - Notes:
    - `relative_path` is the **opaque on-disk path** (`9a41…7b/c3e8…0f.mdenc`);
    - `filename` is `<id>.mdenc`;
    - `title` is `<id>`;
    - `extension` is `mdenc`;
    - `mime_type` is `application/vnd.mdvault.note+encrypted`;
    - `file_hash` and `file_size` are the SHA-256 and size of the **stored ciphertext bytes**, the same meaning as for plaintext vaults (§17 and `backup-archive-format`);
    - `file_mtime`;
    - `is_encrypted = true`.
  - **Never stored**: note titles, folder names, logical paths, content, passwords, keys, tokens. There is no FTS or search index of encrypted vaults in SQLite. When search arrives (§37), it decrypts in memory while the vault is unlocked.
- **Indexing, reconciliation and external changes need no key**:
  - For encrypted vaults, `VaultIndexService` indexes only files matching the note pattern.
  - Pairing (exact path, case-insensitive path, hash, file name) works on opaque names, and moves keep the file ID.
  - Quick and Full reconcile, external-change checks, the open-note check and backups therefore all work while the vault is **locked**.
  - The tree signature for encrypted vaults hashes `uuid:path:file_hash` (not just `uuid:path`), so an in-place rename (the ciphertext changes, the path does not) refreshes the tree.
- **Decrypted name map (`VaultNamespace`)** is built per request, and only when unlocked, by `EncryptedNoteService`:
  - Folder names come from `folder.mdenc` (at most 4 KiB).
  - Note names come from decrypting each registry row's file (at most 16 MiB plus overhead).
  - The logical path is the chain of decrypted names.
  - The client sees only logical paths (`My Credentials/Bank Accounts.md`) exactly as for plaintext vaults. The tree, Move dialog and folder parameters keep their contract.
  - The server maps logical paths to disk paths. A logical folder path that is ambiguous (two sibling folders whose names differ only by case, possible only through outside tampering) or unknown is refused as "folder not found".
- **Damaged or foreign entries are reported, never fatal**:
  - A note that fails to decrypt is shown as "Unreadable note (<first 8 hex>)" and its preview state is `unreadable`.
  - A folder without a valid name file is shown as "Unnamed folder (<first 8>)".
  - Unencrypted `.md` files inside an encrypted vault are not indexed; they are listed as a security warning (E11).
- **Registration and reset recovery**: `VaultService::register()` detects `mdvault-encryption.json`.
  - A valid key file registers the folder as an encrypted vault and creates the mirror.
  - An invalid key file is refused ("looks like an encrypted MDVault vault, but its key file is damaged").
- **Encrypted names leak only**: the number of entries, folder shape, approximate sizes and timestamps (accepted, E4).

## Consequences
- **Positive**:
  - §32/§56 are met.
  - Rules 1, 3, 4, 8 and 9 still hold: files on disk are the truth, paths are relative, UUIDs are stable, every indexed file is hashed, and the index can be rebuilt from disk.
  - Detection and backups need no key.
  - Encrypted vaults survive a DB reset or a move to another machine.
- **Negative / trade-offs**:
  - Tree and operations cost O(notes) decryptions per request. This is fine up to a few thousand notes; a name cache is the follow-up.
  - Renaming a note rewrites (re-encrypts) the file.
  - The vault name is readable.
  - Folder rename is not offered (as in plaintext vaults today).
- **Follow-ups**:
  - Encrypted name cache.
  - A separate metadata segment so the tree reads only file prefixes (`format_version` 2).
  - Attachments in encrypted vaults.
  - "Encrypt stray plaintext files" action.
