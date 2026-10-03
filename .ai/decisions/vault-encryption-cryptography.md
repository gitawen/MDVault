# ADR: Vault encryption cryptography (library, primitives, key hierarchy, file formats)

- **Status**: Accepted (E1, E2 and E9 approved 2026-10-03)
- **Date**: 2026-10-03
- **Phase**: Master Plan Phase 7, Encryption (§30–§34, §56, §62; Rules 6, 7; §61 items 12, 13, 18)

## Context
- §30, §34 and Rule 7 require established libraries, no custom primitives and no plaintext passwords. §31's example names AES-256-GCM and says the design "must be reviewed and tested before implementation".
- PHP 8.4 runtime:
  - `ext-sodium` (libsodium) is compiled into `nativephp/php-bin` 1.2.0 for win, mac and linux (`build-meta/build-extensions-*.json`). Herd and standard PHP builds also ship it.
  - libsodium provides Argon2id (`sodium_crypto_pwhash`), XChaCha20-Poly1305-IETF AEAD, a BLAKE2b key-derivation function (`sodium_crypto_kdf_derive_from_key`) and `sodium_memzero`.
  - `ext-openssl` offers AES-256-GCM with a 96-bit nonce and only PBKDF2 for passwords, which is not memory-hard.
- Laravel `Crypt` uses `APP_KEY` (AES-256-CBC plus HMAC). In a packaged NativePHP app, `APP_KEY` is bundled with the build, so it is neither secret nor password-derived.
- Notes are edited up to 1 MiB (`NoteService::EDIT_LIMIT`), and reads and saves already handle whole files.
- **Threat model (v1)**:
  - **Protects against** an attacker who gets the vault folder, a backup ZIP, the SQLite DB or the app data directory **while the vault is locked or the app is closed**. Examples: a stolen laptop, a cloud-synced folder, a copied backup.
  - **Not protected against**:
    - malware or a person with live access to the user's unlocked session or process memory;
    - inference from file sizes, counts or timestamps;
    - tampering that only rolls a file back to an older valid copy of itself.

## Options Considered
1. **Laravel Encrypter keyed by `hash_pbkdf2`.** CBC plus HMAC, and PBKDF2 is cheap to attack with GPUs. Rejected.
2. **OpenSSL AES-256-GCM plus PBKDF2-SHA256.** Matches §31 literally. But PBKDF2 is not memory-hard, a random 96-bit GCM nonce has birthday limits, and nonce reuse is catastrophic. Rejected.
3. **libsodium: Argon2id, XChaCha20-Poly1305-IETF and the BLAKE2b key-derivation function (chosen).**
4. **`paragonie/halite`.** A high-level wrapper over libsodium, but it is a new dependency for about ten calls, and the key hierarchy and file formats would still be ours. Rejected (§61.3).
5. **An existing vault format (Cryptomator, gocryptfs, age).** None has a maintained PHP implementation. Rejected.

## Decision
- **Library**:
  - `ext-sodium` only, with `"ext-sodium": "*"` in `composer.json` `require` (E1).
  - `App\Services\EncryptionService` is the only caller of `sodium_*`. The one exception is `App\Support\VaultKey`, which may call `sodium_memzero`. A Pest arch test plus a QA grep enforce this.
  - Randomness comes only from `random_bytes()` and sodium key generation.
- **Primitives**:
  - **Password key derivation**: `sodium_crypto_pwhash` with `ALG_ARGON2ID13`, a 32-byte output and a 16-byte random salt.
    - Default cost is libsodium MODERATE: opslimit 3, memlimit 268,435,456 bytes (256 MiB) (E2).
    - The cost comes from `config('mdvault.encryption.kdf.*')`.
    - When a vault is created, the cost is raised to at least INTERACTIVE (2 / 64 MiB), unless `mdvault.encryption.allow_weak_kdf` is true. Only `phpunit.xml` sets it, together with the libsodium MIN values, to keep tests fast.
    - The cost is stored per vault. Readers accept opslimit 1–10 and memlimit 8,192 bytes to 1 GiB. Anything else means the key file is damaged, which stops a crafted key file from forcing a huge memory allocation.
  - **AEAD**: `crypto_aead_xchacha20poly1305_ietf` (256-bit key, 192-bit random nonce, 128-bit tag).
    - Used for key wrapping, note files, folder names and session sealing.
    - Every encryption uses a fresh random nonce. Nonces are never reused.
  - **Subkeys**: `sodium_crypto_kdf_derive_from_key(32, id, ctx, DEK)`.
    - id 1, ctx `MDVNOTE1` for note files.
    - id 2, ctx `MDVFOLD1` for folder names.
    - The data key itself is used only as input to this derivation.
- **Key hierarchy**:
  ```text
  password ──Argon2id(salt, ops, mem)──▶ KEK (32 B; never stored)
  DEK (32 B random per vault; never changes) ──AEAD(KEK, nonce, AD=wrapAD)──▶ wrapped_key (stored in the key file + mirror)
  DEK ──KDF──▶ noteKey (id 1) / folderKey (id 2)
  ```
  - `key_id` is a UUIDv7 created when the vault becomes encrypted. It names the DEK. It never changes with password changes, renames or restore-as-copy, so a vault's own UUID is never part of any associated data (AD). Its raw 16 bytes go into every AD.
  - The key-wrap AD (`wrapAD`) is:
    `"MDVK" | 0x01 | key_id(16) | key_version(uint32 BE) | cipher | "|" | kdf | "|" | opslimit(uint32 BE) | memlimit(uint64 BE) | salt(16)`.
    Changing any key-file field makes unwrapping fail.
  - **Password validation is a successful AEAD unwrap.** No verifier or password hash is stored. A wrong password, a tampered salt or parameters, and a tampered wrapped key all give one generic error: "That password didn't unlock this vault."
  - **Password change** works like this:
    1. Unwrap with the current password.
    2. Generate a new salt and derive a new KEK.
    3. Re-wrap the **same** DEK and increase `key_version` by 1.

    Notes are never re-encrypted. Any older copy of the key file (for example, inside an older backup) still opens with the old password. The UI says so.
  - **No recovery key and no hint in v1 (E9).** A lost password means the data is lost permanently.
- **File formats** (integers big-endian; every format carries a version byte):
  - **Note file `<file_id>.mdenc`**. `file_id` is 32 lowercase hex characters (16 random bytes).
    ```text
    "MDVN" | 0x01 | nonce(24) | AEAD_noteKey(payload, AD = "MDVN"|0x01|key_id(16)|file_id(16))
    payload = name_len(uint16) | name (UTF-8 note stem, 1–255 bytes) | content (the exact Markdown bytes, BOM/EOL preserved)
    ```
    - Putting `file_id` into the AD means that swapping the contents of two note files on disk is detected (decryption fails).
    - Moving a file to another folder under the same name stays valid.
  - **Folder-name file `<folder_id>/folder.mdenc`**:
    `"MDVF" | 0x01 | nonce(24) | AEAD_folderKey(name, AD = "MDVF"|0x01|key_id|folder_id(16))`
  - **Size caps**: the plaintext payload is at most 16 MiB (`MAX_NOTE_PLAINTEXT_BYTES`). The editor keeps its 1 MiB limit. Files are read whole, so a single AEAD per file is sufficient. If streaming is ever needed, the version byte allows adding a `secretstream` format later.
- **Session sealing** (ADR `encrypted-vault-key-custody`):
  `AEAD_token(DEK, AD = "MDVS"|0x01|vault_uuid|key_id(16)|epoch(uint64 BE))`, keyed by a 32-byte random token.
- **Hygiene**:
  - `#[\SensitiveParameter]` goes on every parameter that carries a password, token or key, in services, controllers and Form Request helpers.
  - `App\Support\VaultKey` holds the DEK in a private property:
    - `__debugInfo()` returns `['keyId' => …, 'key' => '[redacted]']`;
    - `__serialize()` and `__clone()` throw;
    - `__destruct()` calls `sodium_memzero`.
  - KEK and other temporary secrets are wiped with `sodium_memzero` after use. This is best effort, because PHP may copy strings.
  - `EncryptionService` never logs, reports or dumps anything.
  - Its exceptions (`EncryptionException`) carry fixed, user-safe messages. They never include input data and never chain `SodiumException`.

## Consequences
- **Positive**:
  - Audited primitives and no new packages.
  - A memory-hard KDF and nonce-misuse-resistant 192-bit nonces.
  - Password change is O(1).
  - Tampering with a key file or note, or swapping files, is detected.
  - Every format is versioned.
- **Negative / trade-offs**:
  - Departs from the §31 example cipher (AES-256-GCM), so it needs approval.
  - Each unlock costs about 1 s and 256 MiB of temporary memory.
  - Old key files and backups keep accepting old passwords.
  - Rolling a file back to an older valid copy of itself is not detected (outside the threat model).
  - Wiping memory in PHP is best effort only.
- **Follow-ups**:
  - Optional recovery key, v1.x.
  - Raise the default cost when hardware allows.
  - Phase 8 must ship with `APP_DEBUG=false`. Debug error pages can show request bodies and headers.
  - Accepted risk (7A-QA-01, accepted at the 7C security review): `VaultKey` redacts `print_r`, `var_dump`, `json_encode`, `serialize` and `clone`, but `var_export` and an `(array)` cast or reflection still expose the private material. Nothing in the app does this, the arch rules ban `var_export` in the crypto services and `->material(` outside `EncryptionService`, and a process that can use reflection already holds the key.
