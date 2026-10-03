# Plan: Phase 7: Encryption

## Metadata
- **Feature Name**: Phase 7: Encryption
- **Feature ID**: mdv-p7
- **Master Plan Phase**: **Implements Master Plan Phase 7, Encryption (`docs/Masterplan.md` §56).** Related sections: §9, §11–§18, §24–§34, §37–§44, §62, §63, §67.
- **Author**: System Analyst
- **Created Date**: 2026-10-03
- **Task Complexity**: Level 4, Architectural (analyst sign-off after QA)
- **Requirements**: `requirements.md`
- **Status**: **APPROVED** (user approved E1–E12 as recommended, 2026-10-03)

---

## 1. Summary
Vaults can be encrypted with libsodium:
- Argon2id derives a key-encryption key from the password. It wraps a random per-vault data key.
- Notes and folder names are encrypted with XChaCha20-Poly1305 under subkeys derived from the data key.
- Files on disk get random 32-hex names. Each real name is stored encrypted inside its own file.

Key material:
- An on-disk key file `mdvault-encryption.json` is the source of truth.
- `vault_encryption` mirrors it.
- SQLite holds only opaque paths and ciphertext hashes, so indexing, change detection and backups work while the vault is locked.

Unlocked keys are split in two halves:
- a random token in renderer memory, sent as a header by an Inertia interceptor;
- the data key, sealed under that token, in the session.

Locking deletes the session entry. Auto-lock, screen lock, reload and restart all lock.

`EncryptedNoteService` implements every note operation for encrypted vaults; `NoteService` delegates to it at method entry. Existing vaults are converted with stage, verify and swap, plus deterministic crash recovery. Backups move to format 2 and copy the ciphertext unchanged.

Delivery is in three sub-phases with QA gates:
- **7A**: crypto core and key custody;
- **7B**: working encrypted vaults and the UI;
- **7C**: conversion, password management, backups and the security sign-off.

---

## 2. Architecture & Design
- **Approach**: see the five new ADRs.
- **Alternatives Considered**:
  - AES-GCM + PBKDF2, Laravel `Crypt`, halite: see ADR `vault-encryption-cryptography`.
  - SIV-encrypted names, a single index file, plaintext titles in SQLite, key material only in SQLite: see ADR `encrypted-vault-storage-layout`.
  - Data key in the session or renderer, re-deriving per request, OS keychain: see ADR `encrypted-vault-key-custody`.
  - In-place per-file conversion: see ADR `vault-encryption-conversion`.
  - Decrypting or re-encrypting backups: see ADR `encrypted-vault-backups`.
  - **A storage interface with plaintext and encrypted implementations behind `NoteService`**: rejected for v1. It would mean refactoring every Phase 3–6 code path. Delegating at method entry keeps the plaintext paths byte-for-byte unchanged.
- **Decision Records**:
  - New:
    - `.ai/decisions/vault-encryption-cryptography.md`
    - `.ai/decisions/encrypted-vault-storage-layout.md`
    - `.ai/decisions/encrypted-vault-key-custody.md`
    - `.ai/decisions/vault-encryption-conversion.md`
    - `.ai/decisions/encrypted-vault-backups.md`
  - Amended in T0: `vault-removal-and-rename-semantics`, `database-reset-semantics`, `backup-archive-format`, `backup-restore-semantics`, `external-change-detection`, `note-registry-and-indexing`, `service-layer-architecture`, `settings-persistence`.

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `vault_encryption` | create | `id`; `vault_id` FK→`vaults` `cascadeOnDelete` **unique**; `key_id` string(36); `key_version` unsignedInteger default 1; `algorithm` string(50); `kdf_algorithm` string(30); `kdf_opslimit` unsignedInteger; `kdf_memlimit` unsignedBigInteger; `salt` string(64); `nonce` string(64); `encrypted_key` text; `format_version` unsignedSmallInteger; `header_hash` char(64); timestamps. **No secrets. No content columns.** |
| `vaults`, `notes` | none | The existing `is_encrypted` columns are used. Encrypted notes store opaque values (ADR `encrypted-vault-storage-layout`). |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Config (new) | `config/mdvault.php` | `encryption.kdf.opslimit` (env `MDVAULT_KDF_OPSLIMIT`, default `SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE`), `encryption.kdf.memlimit` (default MODERATE), `encryption.allow_weak_kdf` (default false) |
| Migration / Model (new) | `database/migrations/2026_10_03_xxxxxx_create_vault_encryption_table.php`, `app/Models/VaultEncryption.php` | `$table='vault_encryption'`, hidden `id`, casts, `vault()` BelongsTo; `Vault::encryption()` HasOne |
| Support (new) | `app/Support/VaultKey.php`, `EncryptionHeader.php`, `VaultNamespace.php`, `ConversionResult.php` | Secret holder (redacting); key-file DTO; decrypted name map; conversion result |
| Exceptions (new) | `app/Exceptions/EncryptionException.php`, `VaultLockedException.php` | Generic user-safe messages and `field()`; 423 rendering |
| Service (new) | `app/Services/EncryptionService.php` | The only `sodium_*` user: KDF, wrap and unwrap, note and folder formats, key-file encode, parse and validate, session seal and open |
| Service (new) | `app/Services/VaultKeyService.php` | Keyring: tokens, session entries, idle and epoch checks, wiping |
| Service (new) | `app/Services/VaultEncryptionService.php` | Key file I/O and mirror, create encrypted vault, unlock, lock, change password, detect and adopt key files, `ensureHeaderOnDisk`, `recover` |
| Service (new) | `app/Services/EncryptedNoteService.php` | Name map, tree, every note and folder operation for encrypted vaults |
| Service (new) | `app/Services/VaultConversionService.php` | Preflight, encrypt existing, decrypt |
| Services (modify) | `NoteService`, `VaultIndexService`, `VaultService`, `BackupService`, `ExternalChangeService`, `FileStorageService`, `StoragePathService` | Delegation, encryption-aware indexing, register, present, remove and close, format 2, logical open path, inventory and prefixes, name rules |
| Enum (modify) | `app/Enums/SettingKey.php` | `SecurityAutoLockMinutes`, `SecurityLockOnScreenLock` |
| Exceptions (modify) | `NoteOperationException`, `NoteSaveConflictException`, `BackupException`, `VaultOperationException` | Generic factories (no names) for encrypted vaults; new backup and registration messages |
| Middleware (new) | `app/Http/Middleware/ProvideVaultKeys.php` | Passes the `X-MDVault-Unlock` header to `VaultKeyService` |
| Requests (new) | `app/Http/Requests/Vaults/StoreEncryptedVaultRequest.php`, `UnlockVaultRequest.php`, `EncryptVaultRequest.php`, `DecryptVaultRequest.php`, `UpdateVaultPasswordRequest.php`, `app/Http/Requests/Settings/UpdateSecuritySettingsRequest.php` | Validation |
| Controllers (new) | `EncryptedVaultController@store`, `VaultUnlockController@store`, `VaultLockController@store` and `@storeAll`, `VaultEncryptionController@store` and `@destroy`, `VaultPasswordController@update`, `Settings\SecurityController@edit` and `@update` | Thin HTTP |
| Controllers (modify) | `WorkspaceController`, `VaultController` (close locks; open runs recovery), `NoteController`, `FolderController`, `NoteCopyController` (generic messages for encrypted vaults) | — |
| Bootstrap (modify) | `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/NativeAppServiceProvider.php` | Middleware, `dontFlash`, 423 render, shared `security`, `ScreenLocked` listener, boot recovery |
| composer (modify, E1) | `composer.json` | `"ext-sodium": "*"` |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Lib (new) | `resources/js/lib/vault/keyring.ts` | In-memory token map, header encoding, pruning, `installVaultKeyringInterceptor()` |
| Composable (new) | `resources/js/composables/useAutoLock.ts` | Idle tracking, then the Lock-all visit |
| Components (new) | `resources/js/components/vaults/UnlockVaultPanel.vue`, `EncryptVaultDialog.vue`, `DecryptVaultDialog.vue`, `ChangeVaultPasswordDialog.vue`, `VaultLockButton.vue` | Unlock, encrypt, decrypt, password, lock UI |
| Page (new) | `resources/js/pages/settings/Security.vue` | Auto-lock settings |
| Modify | `app.ts`, `layouts/AppLayout.vue`, `layouts/settings/Layout.vue`, `pages/Workspace.vue`, `pages/vaults/Index.vue`, `components/vaults/CreateVaultDialog.vue`, `SidebarVaultItem.vue`, `components/backups/RestoreBackupDialog.vue`, `lib/editor/saveTransport.ts`, `types/vaults.ts`, `types/backups.ts`, `types/global.d.ts` | Wiring |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| POST | `/vaults/encrypted` | `vaults.encrypted.store` | `EncryptedVaultController@store` (JSON) | web |
| POST | `/vaults/lock` | `vaults.lock.all` | `VaultLockController@storeAll` | web |
| POST | `/vaults/{vault:uuid}/unlock` | `vaults.unlock` | `VaultUnlockController@store` (JSON) | web |
| POST | `/vaults/{vault:uuid}/lock` | `vaults.lock` | `VaultLockController@store` | web |
| POST | `/vaults/{vault:uuid}/encryption` | `vaults.encryption.store` | `VaultEncryptionController@store` (JSON) | web |
| DELETE | `/vaults/{vault:uuid}/encryption` | `vaults.encryption.destroy` | `VaultEncryptionController@destroy` | web |
| PUT | `/vaults/{vault:uuid}/encryption/password` | `vaults.encryption.password.update` | `VaultPasswordController@update` | web |
| GET | `/settings/security` | `settings.security.edit` | `Settings\SecurityController@edit` | web |
| PATCH | `/settings/security` | `settings.security.update` | `Settings\SecurityController@update` | web |

All `{vault}` routes use `->whereUuid('vault')`. `ProvideVaultKeys` is appended to `web` **before** `HandleInertiaRequests`. `SetCacheHeaders::using('no_store;private')` is appended to `web`.

---

## 3. Implementation Tasks
**Rules for every task:**
- Read the five new ADRs and `docs/Masterplan.md` §30–§34, §56 and §62 first.
- After each task, run `vendor/bin/pint --dirty --format agent` and that task's tests.
- After route changes, run `php artisan wayfinder:generate --with-form --no-interaction`.
- Use the skills `laravel-best-practices`, `testing-best-practices`, `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` where relevant.
- No AI attribution anywhere.
- Never run `migrate:fresh` on the desktop DB.
- **Security rules**:
  - `#[\SensitiveParameter]` on every password, token or key parameter.
  - No decrypted name, path or content in any toast, flash, validation message, exception message or log line.
  - Passwords and tokens only in request bodies, headers or JSON responses.
- Tests use the helpers added in T1 to `tests/Pest.php`:
  - `encryptedVault(string $name, string $password = 'correct horse battery'): array{0: Vault, 1: string}`, which returns the vault and its token;
  - `withVaultToken(TestCase $t, Vault $v, string $token)`;
  - `assertNoNeedlesUnder(string $dir, array $needles)`: scans every file's bytes **and** every file and folder name, case-insensitively;
  - `assertNoNeedlesInDatabase(array $needles)`: every row of every table, JSON-encoded;
  - `captureLogs(): Collection`: listens for `MessageLogged` and stores message plus serialized context.

### Sub-phase 7A: Crypto foundation and key custody (no UI change)

- [x] **T0: Preconditions (orchestrator/user)**
  - Record the E1–E12 answers in §6. If E1, E3, E5 or E7 differ from the recommendation, stop and request an analyst revision.
  - Save the five ADRs. Work on branch `phase-7-encryption`.
  - Check `php -r "var_dump(extension_loaded('sodium'));"` is true. Add `"ext-sodium": "*"` to `composer.json` `require` and run `composer validate`.
  - **ADR amendments** (append):
    - `vault-removal-and-rename-semantics.md`, Decision → Remove: "Amended in Phase 7 (ADR `vault-encryption-conversion`, E7): `deleteStagingDirectory()` also accepts `.mdvault-encrypt-`, `.mdvault-decrypt-` and `.mdvault-original-` folders; after a committed conversion the replaced original is deleted permanently."
    - `database-reset-semantics.md`, Follow-ups: "Phase 7 (resolved with option (a)): the key file lives in the vault folder (ADR `encrypted-vault-storage-layout`); `resetRegistry()` deletes `vault_encryption` rows first."
    - `backup-archive-format.md` and `backup-restore-semantics.md`, Follow-ups: "Phase 7: format 2, see ADR `encrypted-vault-backups`."
    - `external-change-detection.md`, Follow-ups: "Phase 7 (resolved): detection on encrypted vaults works on ciphertext without the key."
    - `note-registry-and-indexing.md`, Follow-ups: "Phase 7: encrypted vaults index `<32hex>.mdenc` with opaque paths and ciphertext hashes."
    - `service-layer-architecture.md`, Follow-ups: "Phase 7: `sodium_*` only in `EncryptionService` (and `VaultKey` for `sodium_memzero`)."
    - `settings-persistence.md`, Follow-ups: "Phase 7 adds `security.auto_lock_minutes` and `security.lock_on_screen_lock`."
  - Covers: FR-18

- [ ] **T1: Config, schema and test helpers**
  - Commands:
    - `php artisan make:migration create_vault_encryption_table --no-interaction`
    - `php artisan make:model VaultEncryption --no-interaction`
  - Create `config/mdvault.php` (keys in §2).
  - In `phpunit.xml`, set:
    - `MDVAULT_KDF_OPSLIMIT=1`;
    - `MDVAULT_KDF_MEMLIMIT=8192`;
    - `MDVAULT_ALLOW_WEAK_KDF=true`.
  - Migration exactly as in §2.
  - Model:
    - `$table = 'vault_encryption'`;
    - `$fillable` = every column except `id` and the timestamps;
    - `$hidden = ['id', 'vault_id', 'salt', 'nonce', 'encrypted_key']`;
    - casts as ints;
    - `vault()`.
  - `Vault`: add `encryption(): HasOne` and the PHPDoc.
  - `VaultService::resetRegistry()`: delete `VaultEncryption` rows first, still in the same transaction.
  - Tests:
    - `DatabaseSchemaTest`: add `vault_encryption` to the "keeps only…" dataset with a column test; remove the "later-phase tables" test, or make it an empty-dataset assertion that `vault_encryption` exists.
    - `VaultRegistryResetTest`: rows are removed.
    - Add the `tests/Pest.php` helpers. Stub `encryptedVault()` until T9, or add it in T9.
  - Covers: FR-05

- [ ] **T2: Support types and exceptions**
  - Commands:
    - `php artisan make:class Support/VaultKey --no-interaction`
    - `php artisan make:class Support/EncryptionHeader --no-interaction`
    - `php artisan make:exception EncryptionException --no-interaction`
    - `php artisan make:exception VaultLockedException --no-interaction`
  - `VaultKey` (`final`):
    - constructor `(#[\SensitiveParameter] string $material, public readonly string $keyId)`;
    - `material(): string` (`@internal`, used only by `EncryptionService`);
    - `__debugInfo()` is redacted;
    - `__serialize()` and `__clone()` throw `\LogicException`;
    - `__destruct()` calls `sodium_memzero`.
  - `EncryptionHeader` (`final readonly`):
    - fields: `formatVersion`, `keyId`, `keyVersion`, `cipher`, `kdfAlgorithm`, `opslimit`, `memlimit`, `salt` (raw), `wrapNonce` (raw), `wrappedKey` (raw), `createdAt`;
    - `toRegistryAttributes(string $headerHash): array` (base64 for the binary fields).
  - `EncryptionException` (`final`, private constructor, `field()`). Factories:
    - `wrongPassword()`: field `password`, "That password didn't unlock this vault."
    - `damagedHeader()`: "This vault's key file is damaged or isn't an MDVault key file."
    - `headerMissing()`
    - `undecryptable()`
    - `notEncrypted()`
    - `alreadyEncrypted()`
    - `unsupportedContents(list<string> $problems)` (with `problems()`)
    - `vaultChanged()`
    - `parentNotWritable()`
    - `swapFailed()`: "Close any programs using the vault folder and try again. Nothing was changed."
    - `conversionFailed()`
    - `cleanupIncomplete(string $folder)`
  - `VaultLockedException` (`final`) has a fixed message, "This vault is locked. Unlock it to continue." Register a renderer in `bootstrap/app.php`:
    - `expectsJson()` → 423 `{message, reason:'locked'}`;
    - otherwise flash a generic error toast and `redirect()->route('workspace')`.
  - Tests in `tests/Feature/Support/VaultKeyTest.php`:
    - `print_r`, `var_dump` (captured) and `json_encode` never contain the material;
    - `serialize` throws;
    - `clone` throws.
  - Covers: FR-17

- [ ] **T3: `EncryptionService`**
  - Command: `php artisan make:class Services/EncryptionService --no-interaction` (`final`; constructor `Repository $config`).
  - API and constants exactly as in ADR `vault-encryption-cryptography`:
    ```php
    public function newVaultKey(#[\SensitiveParameter] string $password): array; // {0: EncryptionHeader, 1: VaultKey}
    public function unlock(EncryptionHeader $header, #[\SensitiveParameter] string $password): VaultKey; // EncryptionException::wrongPassword
    public function rewrap(EncryptionHeader $header, VaultKey $key, #[\SensitiveParameter] string $newPassword): EncryptionHeader; // key_version+1, new salt
    public function encodeHeader(EncryptionHeader $header): string;
    public function parseHeader(string $bytes): EncryptionHeader; // strict: keys, types, base64 lengths (salt 16, nonce 24, wrapped 48), bounds; EncryptionException::damagedHeader
    public function newFileId(): string; // 32 lowercase hex
    public function encryptNote(VaultKey $key, string $fileId, string $name, string $content): string;
    public function decryptNote(VaultKey $key, string $fileId, string $bytes): array; // {name: string, content: string}; EncryptionException::undecryptable
    public function encryptFolderName(VaultKey $key, string $folderId, string $name): string;
    public function decryptFolderName(VaultKey $key, string $folderId, string $bytes): string;
    public function newSessionToken(): string;
    public function sealForSession(VaultKey $key, string $vaultUuid, int $epoch, #[\SensitiveParameter] string $token): array; // {nonce, sealed} base64
    public function openFromSession(array $entry, string $vaultUuid, int $epoch, #[\SensitiveParameter] string $token): ?VaultKey;
    ```
  - Creation cost comes from config, raised to the INTERACTIVE floor unless `allow_weak_kdf`.
  - Every `SodiumException` is caught and mapped to an `EncryptionException` without chaining.
  - Tests in `tests/Feature/Services/EncryptionServiceTest.php`:
    - Key-file round trip.
    - Correct password unlocks; wrong password → `wrongPassword`.
    - Tampering with each key-file field (salt, ops, mem, key_version, key_id, nonce, ciphertext) → fails.
    - Out-of-bounds memlimit (2^31) and opslimit (0, 11) → `damagedHeader`, and the KDF never runs (assert it completes in well under 50 ms).
    - Note round trip: names (unicode, 255 bytes), empty content, binary/invalid UTF-8 content, BOM and CRLF preserved.
    - The same plaintext encrypted twice gives different bytes.
    - The ciphertext contains neither the name nor the content.
    - The wrong `fileId` (swap) fails; a truncated file, a flipped tag, wrong magic and wrong version all fail.
    - A 256-byte name and content over the cap are rejected.
    - Folder-name round trip and swap detection.
    - `rewrap`: the old password fails, the new one works, and the data key is the same (encrypt with the old key, decrypt with the rewrapped one).
    - Session seal: the wrong token, wrong epoch or wrong vault UUID → null.
    - An `EncryptionException` thrown from inside `unlock` never contains the password in `getMessage()` or `getTraceAsString()`.
  - Covers: FR-03, FR-04, FR-06, FR-12

- [ ] **T4: Security settings, keyring and global hygiene**
  - `SettingKey`:
    - `SecurityAutoLockMinutes = 'security.auto_lock_minutes'`: Integer, group Security, default 15;
    - `SecurityLockOnScreenLock = 'security.lock_on_screen_lock'`: Boolean, Security, true.
  - Command: `php artisan make:class Services/VaultKeyService --no-interaction`.
    - Constructor: `EncryptionService`, `SettingsService`, `Illuminate\Contracts\Session\Session`, `Illuminate\Contracts\Cache\Repository`, `Illuminate\Contracts\Foundation\Application` (for `terminating`).
    - Register it as `scoped` in `AppServiceProvider::register()`, since it holds per-request state.
    - API:
      ```php
      public const HEADER = 'X-MDVault-Unlock';
      public const SESSION_PREFIX = 'mdvault.keyring.';
      public const EPOCH_CACHE_KEY = 'mdvault.vault_lock_epoch';
      public const IDLE_GRACE_SECONDS = 120;
      public function provideTokens(#[\SensitiveParameter] ?string $header): void; // "uuid:token" pairs, max 20, malformed ignored
      public function store(Vault $vault, VaultKey $key): string; // token
      public function keyFor(Vault $vault, bool $touch = true): ?VaultKey; // checks entry, epoch, idle, key_id vs vault_encryption; purges on failure
      public function requireKey(Vault $vault): VaultKey; // VaultLockedException
      public function isUnlocked(Vault $vault): bool; // touch false
      public function forget(Vault $vault): void;
      public function forgetAll(): void;
      public function lockEverywhere(): void; // epoch++ and forgetAll
      ```
  - Command: `php artisan make:middleware ProvideVaultKeys --no-interaction`. It calls `provideTokens($request->header(VaultKeyService::HEADER))`.
  - `bootstrap/app.php`:
    - append `ProvideVaultKeys` before `HandleInertiaRequests`;
    - append `SetCacheHeaders::using('no_store;private')`;
    - `$exceptions->dontFlash([...])` with the list in ADR `encrypted-vault-key-custody`.
  - `AppServiceProvider::boot()`: `Event::listen(ScreenLocked::class, …)`. It calls `lockEverywhere()` when `SecurityLockOnScreenLock` is on.
  - `HandleInertiaRequests::share()`: `security` (lazy) = `{auto_lock_minutes, lock_on_screen_lock}`.
  - Tests in `tests/Feature/Services/VaultKeyServiceTest.php`:
    - store then `keyFor` with a token works; a wrong or missing token → null;
    - `forget` and `forgetAll`;
    - idle: `travel(15*60+121)` → null and the entry is purged;
    - `touch: false` doesn't extend the idle timer;
    - auto-lock 0 → never expires;
    - after `lockEverywhere` → null; dispatching `ScreenLocked` → locked (setting on) or unlocked (setting off);
    - a `key_id` mismatch → null;
    - `serialize(session()->all())` never contains the data key (raw, base64 or hex) or the token;
    - header parsing caps at 20 pairs.

    In `tests/Feature/Security/HttpHygieneTest.php`:
    - `GET /` has a `Cache-Control` header containing `no-store`;
    - a failed validation on `POST vaults/{v}/notes` doesn't flash `name` or `folder` (`session('_old_input')` lacks them).
  - Covers: FR-07, FR-08, FR-17

- [ ] **T5: `VaultEncryptionService` (key file, mirror, unlock, lock, password)**
  - Command: `php artisan make:class Services/VaultEncryptionService --no-interaction`.
    - Constructor: `EncryptionService`, `VaultKeyService`, `FileStorageService`, `FileHashService`, `DatabaseManager`.
  - API:
    ```php
    public const HEADER_FILENAME = 'mdvault-encryption.json';
    public const HEADER_MAX_BYTES = 65536;
    public function readHeader(Vault $vault): array; // {0: EncryptionHeader, 1: 'disk'|'registry', 2: ?string diskHash}; disk first, else mirror; EncryptionException
    public function unlock(Vault $vault, #[\SensitiveParameter] string $password): string; // token; syncs mirror (disk wins); restores missing key file via createFile after success
    public function lock(Vault $vault): void;
    public function lockAll(): void;
    public function changePassword(Vault $vault, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new): void; // replaceFile guarded by old hash, then mirror update (report() on DB failure; disk is truth)
    public function headerAt(string $folder): ?EncryptionHeader; // null if no file; EncryptionException::damagedHeader if invalid
    public function adopt(Vault $vault, EncryptionHeader $header, string $headerHash): void; // mirror upsert + is_encrypted=true in one transaction
    public function ensureHeaderOnDisk(Vault $vault): void;
    ```
    `recover()` is added in T14.
  - `unlock` refuses an unencrypted vault (`notEncrypted`) and a Missing vault (`VaultOperationException::folderMissing`).
  - Tests in `tests/Feature/Services/VaultEncryptionServiceTest.php`, building an encrypted vault by hand with `EncryptionService` and `adopt()`:
    - Correct and wrong passwords.
    - A changed key file updates the mirror.
    - The key file is deleted, then a correct unlock re-creates it byte-for-byte equivalent; a wrong unlock doesn't create it.
    - Password change: the old password fails, the new one works, `key_version` is 2, note files are untouched, and the session key stays valid.
    - A DB failure during the mirror update (SQLite trigger `RAISE(ABORT)`) → the key file still changes, and the next unlock resyncs.
    - The key file contains no salt-derived key or password.
    - `captureLogs()` across wrong-password, damaged key file and DB-failure paths never contains the password.
  - Covers: FR-05, FR-06, FR-07, FR-12

- [ ] **T6: Architecture rules (7A)**
  - Add to `tests/Unit/ArchitectureTest.php`:
    - `sodium_crypto_pwhash`, `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt`, `sodium_crypto_aead_xchacha20poly1305_ietf_decrypt`, `sodium_crypto_aead_xchacha20poly1305_ietf_keygen`, `sodium_crypto_kdf_derive_from_key` and `sodium_bin2base64`/`sodium_base642bin` are only used in `App\Services\EncryptionService`. `sodium_memzero` is only used in `EncryptionService` and `App\Support\VaultKey`.
    - `App\Models\VaultEncryption` is only used in `App\Services`, `App\Models`, `Database`.
    - `App\Support\VaultKey` is only used in `App\Services`, `App\Support`.
    - `App\Services\EncryptionService` and `App\Services\VaultKeyService` don't use `Illuminate\Support\Facades\Log`, `logger`, `info`, `report`, `dump`, `dd`, `var_dump`, `print_r`, `var_export` or `error_log`.
    - Extend "index and note services use no raw filesystem functions" to `EncryptedNoteService`, `VaultConversionService` and `VaultEncryptionService` (create empty classes now if needed, or add this in T8/T14).
  - Covers: FR-17, FR-18

**QA gate 7A**: full suite plus phpstan (scope in §4).

### Sub-phase 7B: Working encrypted vaults

- [ ] **T7: Name rules and encryption-aware indexing**
  - `StoragePathService::assertValidNoteSegment(string $name)`: `assertValidFolderName`, plus no leading `.` and no `node_modules`. `NoteService::assertValidFolderName` calls it, with unchanged behaviour.
  - `VaultIndexService`:
    - `ENCRYPTED_NOTE_PATTERN = '/^[0-9a-f]{32}\.mdenc$/'`;
    - `isIndexableFor(Vault $vault, string $name): bool`;
    - `plan()` uses it. For encrypted vaults it also collects readable `.md` files into a new `ReconcilePlan::$unencryptedFiles` → `IndexResult::$unencryptedFiles`. Default `[]` keeps existing constructors working.
    - `insertAttributes` and `newNoteAttributes(string $path, int $size, string $hash, bool $encrypted = false)`: encrypted → `extension 'mdenc'`, `mime_type Note::ENCRYPTED_MIME_TYPE`, `is_encrypted true`.
    - `treeSignature(array $directories, array $pairs)` is unchanged. For encrypted vaults, callers (`apply()` and `EncryptedNoteService::browse()`) pass `uuid => path."\0".file_hash`.
  - Add `Note::ENCRYPTED_MIME_TYPE = 'application/vnd.mdvault.note+encrypted'`.
  - Tests (`tests/Feature/Services/EncryptedVaultIndexTest.php`), with no key at all:
    - `.mdenc` files indexed with encrypted attributes;
    - `folder.mdenc` and the key file not indexed;
    - external move (same ID into another folder) → moved with the UUID kept;
    - external delete or modify detected;
    - a dropped `Secret.md` → not indexed and listed in `unencryptedFiles`;
    - unencrypted vaults behave exactly as before (existing tests unchanged).
  - Covers: FR-11

- [ ] **T8: `EncryptedNoteService` and `NoteService` delegation**
  - Command: `php artisan make:class Services/EncryptedNoteService --no-interaction`.
    - Constructor: `FileStorageService`, `FileHashService`, `StoragePathService`, `MarkdownService`, `SettingsService`, `EncryptionService`, `VaultKeyService`, `VaultIndexService`, `DatabaseManager`.
  - Command: `php artisan make:class Support/VaultNamespace --no-interaction`. It holds:
    - folders: logical ⇄ disk, with ambiguity flags;
    - notes by registry UUID: `{name, logicalPath, diskPath, state: ok|unreadable}`;
    - lookups `folderDisk(?string $logical): ?string` (null for unknown or ambiguous), `noteNameTaken(string $diskFolder, string $stem, ?string $exceptUuid): bool` (case-insensitive) and `logicalFolderOf(string $diskFolder): ?string`.
  - API (same signatures and return shapes as `NoteService`): `namespace(Vault, VaultKey)` (memoized per request), `browse(Vault, ?Note $open)`, `create`, `createCopy`, `rename`, `move`, `delete`, `createFolder` (returns the logical path), `deleteFolder` (the folder may contain only `folder.mdenc`; delete it with `deleteNewFileWithContents`, then `deleteEmptyDirectory`; if the rmdir fails, re-create the name file), `preview`, `save`, `present` (logical `title`, `filename` = stem.md, `relative_path`, `folder`).
  - Algorithms follow ADR `encrypted-vault-storage-layout` and mirror the plaintext ones:
    - exclusive create;
    - `replaceFile` with a ciphertext-hash guard plus a re-hash afterwards;
    - `renameFile` with DB compensation;
    - `registerNewFile` compensation (`deleteNewFileWithContents`);
    - a no-op save compares plaintext bytes;
    - `base_hash` = ciphertext hash;
    - a decrypt failure → preview `state 'unreadable'`.
  - Every mutation and every preview calls `requireKey()`.
  - Generic exception factories (new): `NoteOperationException::encryptedNameTaken($field)`, `encryptedFolderNotFound($field)`, `encryptedCreateFailed($field)`, `encryptedUnreadable()`; `NoteSaveConflictException::encryptedChanged($hash)`, `encryptedMissing()`. None contains a name or path.
  - `NoteService`: inject `EncryptedNoteService`. At the very start of `create`, `createCopy`, `rename`, `move`, `delete`, `createFolder`, `deleteFolder`, `preview`, `save` and `present`, add `if ($vault->is_encrypted) return $this->encrypted->…;`. Nothing else in `NoteService` changes.
  - `ExternalChangeService::openNoteState`: for encrypted vaults, `relative_path` = the logical path when `keyFor($vault, touch: false)` succeeds, otherwise null.
  - Tests (`tests/Feature/Services/EncryptedNoteServiceTest.php`):
    - For each operation: success, then on disk only ciphertext and opaque names (`assertNoNeedlesUnder`), DB rows opaque, UUID kept on rename and move.
    - Name conflicts (case-insensitive).
    - Locked → `VaultLockedException`.
    - Save guard conflict on external modification; no-op save writes nothing.
    - Delete-folder refuses a non-empty folder.
    - A tampered note → unreadable state, and the tree still renders.
    - A folder without a name file → "Unnamed folder".
    - `createCopy` "(my version)" naming.
    - Rename changes the encrypted tree signature.
    - `captureLogs()` never contains names or content.
  - Covers: FR-04, FR-09, FR-11

- [ ] **T9: Create, register, present, remove and close encrypted vaults**
  - `VaultEncryptionService::createEncryptedVault(string $name, ?string $description, #[\SensitiveParameter] string $password): array{0: Vault, 1: string}`, with the compensation from ADR `vault-encryption-conversion`. Inject `VaultService`, avoiding cycles: `VaultService` must not depend on `VaultEncryptionService` for creation.
  - `VaultService`:
    - inject `VaultKeyService`;
    - `present()` adds `is_unlocked`;
    - `remove()` and `close()` call `forget()` (close locks the current vault);
    - `register()` calls `VaultEncryptionService::headerAt()` (inject it). A valid key file → `adopt()` after creating the vault in the same flow. A damaged one → `VaultOperationException::damagedEncryptionHeader('path')`.

    If `VaultService` ⇄ `VaultEncryptionService` forms a cycle, move `headerAt`/`adopt` into a small `EncryptionHeaderService` with no `VaultService` dependency. Record the choice in `implementation.md`.
  - Finish the `encryptedVault()` test helper.
  - Tests:
    - create (the folder contains only the key file);
    - a DB failure during create → no record, no folder;
    - `resetRegistry` then register → encrypted and locked, and the password unlocks it;
    - a damaged key file is refused;
    - close locks;
    - remove forgets and cascades the mirror.
  - Covers: FR-01, FR-05, FR-07, FR-15

- [ ] **T10: HTTP layer**
  - Commands:
    - `php artisan make:request Vaults/StoreEncryptedVaultRequest --no-interaction` (rules: `name` as in `VaultNameRules`, `description`, `password` `required|string|min:10|max:1024|confirmed`, `acknowledge` `accepted`)
    - `php artisan make:request Vaults/UnlockVaultRequest --no-interaction` (`password` `required|string|max:1024`)
    - `php artisan make:controller EncryptedVaultController --no-interaction`
    - `php artisan make:controller VaultUnlockController --no-interaction`
    - `php artisan make:controller VaultLockController --no-interaction`
    - `php artisan make:request Settings/UpdateSecuritySettingsRequest --no-interaction` (`auto_lock_minutes` `in:0,5,15,30,60`; `lock_on_screen_lock` `boolean`)
    - `php artisan make:controller Settings/SecurityController --no-interaction`
  - Routes as in §2, in `routes/vaults.php` and `routes/settings.php`.
  - JSON endpoints map `EncryptionException` to 422 `{message, errors: {field: [message]}}`. The unlock response is exactly `{token}`. Create-encrypted returns `{uuid, token}`.
  - Lock endpoints: `forget` or `forgetAll`, `Inertia::clearHistory()`, generic toast, `to_route('workspace')`.
  - `WorkspaceController`, for an encrypted current vault:
    - `Inertia::encryptHistory()`;
    - `tree`, `folders`, `treeSignature` and `note` come from `EncryptedNoteService` when `keyFor()` is non-null, otherwise null, `['']`, null and null;
    - the new prop `encryption` (lazy) = `{locked: bool, unencrypted_files: list<string>, inconsistent: bool}`, where unencrypted files come from a Quick reconcile result only when one ran; otherwise a cheap scan for `*.md` at most depth 32, capped at 20 entries.
  - `NoteController`, `FolderController` and `NoteCopyController`: when `$vault->is_encrypted`, use generic toasts ("Note created.", "Note renamed.", "Folder created.", …).
  - Tests:
    - `tests/Feature/Vaults/EncryptedVaultHttpTest.php`:
      - create-encrypted (422 on a short or mismatched password, or a missing acknowledgment; nothing created);
      - unlock with a wrong password → 422 and no session entry;
      - unlock correct → `{token}` only;
      - Workspace locked props (no tree or note; `encryption.locked`);
      - Workspace with the token → tree with decrypted names and `encryptHistory` true;
      - `notes.show` content with the token;
      - save via `PUT notes/{n}/content` with the token → 200; without it → 423 JSON;
      - lock → later requests with the old token are locked and `clearHistory` is set;
      - lock-all; close locks;
      - the shared `vaults[].is_unlocked` flag;
      - flashed toasts and errors in the session never contain decrypted names.
    - `tests/Feature/Settings/SecuritySettingsTest.php`: edit and update, plus validation.
  - Covers: FR-01, FR-06, FR-07, FR-08, FR-09, FR-10, FR-17

- [ ] **T11: Frontend keyring and transport**
  - `resources/js/lib/vault/keyring.ts`, with no storage APIs:
    - `setToken(uuid, token)`, `forget(uuid)`, `forgetAll()`, `has(uuid)`, `headerValue(): string | null`;
    - `pruneFrom(vaults: {uuid, is_encrypted, is_unlocked}[])` drops tokens the server reports as locked or unencrypted;
    - `installVaultKeyringInterceptor()` uses `http.onRequest` from `@inertiajs/vue3` and adds the header when it is non-empty;
    - call `router.on('success', e => pruneFrom(e.detail.page.props.vaults))`.
  - Call it in `app.ts`.
  - `saveTransport.ts`: 423 → a `failed` outcome with the message "This vault is locked. Unlock it to keep editing."
  - Types:
    - `VaultSummary.is_unlocked`;
    - shared `security` in `global.d.ts`;
    - the Workspace `encryption` prop.
  - Tests:
    - `tests/js/vault/keyring.test.ts`: header encoding, prune, forget, and no `localStorage`/`sessionStorage` access (spy);
    - extend `tests/js/editor/saveTransport.test.ts` for 423.
  - Covers: FR-07, FR-17

- [ ] **T12: Frontend UI**
  - `UnlockVaultPanel.vue`:
    - `useHttp({password})` posts to `unlock.url(uuid)`;
    - on success `setToken`, clear the password field, `router.reload()`;
    - on 422 show the error;
    - the password input is never remembered (`dontRemember('password')`).
  - `Workspace.vue`:
    - renders the panel when `encryption.locked`;
    - warning alerts for `unencrypted_files` and `inconsistent`.
  - `SidebarVaultItem.vue`:
    - `Lock`/`LockOpen` icon for encrypted vaults;
    - a "Lock" `SidebarMenuAction` when unlocked (`router.post(lock.url(uuid), {}, {onSuccess: () => forget(uuid)})`).
  - `CreateVaultDialog.vue`:
    - an "Encrypt this vault" checkbox reveals the password, confirmation and acknowledgment fields, plus the hints "The vault name stays visible" and "If you forget this password, your notes can't be recovered";
    - when checked, submits via `useHttp` to `vaults.encrypted.store`, then `setToken`, then `router.visit(workspace())`.
  - `useAutoLock.ts`, used in `AppLayout.vue`:
    - activity listeners;
    - a timer from `security.auto_lock_minutes` (0 = off);
    - on expiry, if any token: `router.post(lockAll.url(), {}, {onSuccess: forgetAll})`.
  - `pages/settings/Security.vue` plus a nav item (`ShieldCheck` icon) in `layouts/settings/Layout.vue`.
  - Run `npm run types:check`.
  - Covers: FR-01, FR-07, FR-08, FR-10

**QA gate 7B**: full suite, phpstan, `types:check`, `test:js`.

### Sub-phase 7C: Conversion, password management, backups, security sign-off

- [ ] **T13: Filesystem primitives for conversion**
  - `FileStorageService`:
    - constants `ENCRYPT_STAGING_PREFIX = '.mdvault-encrypt-'`, `DECRYPT_STAGING_PREFIX = '.mdvault-decrypt-'`, `CONVERSION_ORIGINAL_PREFIX = '.mdvault-original-'`;
    - `deleteStagingDirectory` accepts these as well (still the only recursive delete);
    - `inventory(string $root): array{files: list<array{path,size,mtime}>, directories: list<string>, symlinks: list<string>, unreadable: list<string>}` with no filtering and no link following.
  - Tests (extend `FileStorageServiceTest`):
    - the prefix allow-list;
    - inventory lists dot entries and symlinks (symlinks `->skipOnWindows()`, or a junction where supported).
  - Covers: FR-02, FR-13, FR-16

- [ ] **T14: `VaultConversionService` and recovery**
  - Command: `php artisan make:class Services/VaultConversionService --no-interaction`.
    - Constructor: `VaultService`, `VaultIndexService`, `VaultEncryptionService`, `EncryptionService`, `VaultKeyService`, `EncryptedNoteService`, `FileStorageService`, `FileHashService`, `StoragePathService`, `DatabaseManager`.
  - API:
    - `preflight(Vault): list<string>`;
    - `encrypt(Vault, #[\SensitiveParameter] string $password): string` (token);
    - `decrypt(Vault, #[\SensitiveParameter] string $password): void`.

    Both follow ADR `vault-encryption-conversion` step by step.
  - `VaultEncryptionService::recover(Vault): ?string` implements the recovery table. It is called from:
    - `NativeAppServiceProvider::boot()` (every vault; try/catch then `report`);
    - `VaultService::open()`;
    - `VaultService::refreshStatus()` when the folder is missing;
    - the start of `encrypt`, `decrypt` and `changePassword`.

    Watch for cycles: `recover` must depend only on `FileStorageService` and the DB.
  - Failure injection: substitute `Illuminate\Filesystem\Filesystem` with the existing test subclass pattern (`moveDirectory` fails on chosen calls), and use SQLite triggers for DB failures.
  - Tests:
    - `tests/Feature/Services/VaultConversionServiceTest.php`:
      - encrypt a vault with nested folders, unicode names, BOM/CRLF, an empty note and an empty folder: UUIDs kept; after unlock every note's content is identical; `assertNoNeedlesUnder(vault parent)` for every name and a content canary; no `.mdvault-*` folder left;
      - preflight refusals (attachment, `.git`, `.obsidian`, symlink, `node_modules`, oversized note) leave nothing changed;
      - a concurrent modification between staging and verify → `vaultChanged`, nothing changed;
      - first-rename failure and second-rename failure → original intact, DB unchanged;
      - DB failure at commit → original intact;
      - cleanup failure → committed, warning returned, a later `recover` deletes the original;
      - decrypt round trip with duplicate-name suffixing;
      - a wrong password for decrypt → nothing changed.
    - `tests/Feature/Services/VaultConversionRecoveryTest.php`: build each of the five on-disk and DB states by hand and assert the end states and idempotence (running twice is harmless).
  - Covers: FR-02, FR-13, FR-16

- [ ] **T15: HTTP and UI for encrypt existing, remove encryption, change password**
  - Commands:
    - `php artisan make:request Vaults/EncryptVaultRequest --no-interaction` (`password` `min:10|max:1024|confirmed`, `acknowledge` `accepted`, `acknowledge_delete` `accepted`)
    - `php artisan make:request Vaults/DecryptVaultRequest --no-interaction` (`password` `required`, `acknowledge` `accepted`)
    - `php artisan make:request Vaults/UpdateVaultPasswordRequest --no-interaction` (`current_password` `required`, `password` `min:10|max:1024|confirmed|different:current_password`)
    - `php artisan make:controller VaultEncryptionController --no-interaction`
    - `php artisan make:controller VaultPasswordController --no-interaction`
  - `store` is JSON and returns `{token}`, or 422 with `problems` for preflight. `destroy` and `update` are Inertia redirects with generic toasts.
  - Dialogs `EncryptVaultDialog.vue`, `DecryptVaultDialog.vue` and `ChangeVaultPasswordDialog.vue` are reachable from the vault card menu in `pages/vaults/Index.vue`, and lock and password actions from `SidebarVaultItem` when relevant. Required copy:
    - "Your existing unencrypted notes in this folder will be permanently deleted after encryption. Copies in backups, cloud sync history or the Recycle Bin are not affected, and deleted files may be recoverable with forensic tools."
    - "Older backups still open with the old password."
  - Tests (`tests/Feature/Vaults/VaultEncryptionHttpTest.php`):
    - happy paths;
    - 422 paths (no flash of password fields);
    - preflight problems listed;
    - password change keeps the session unlocked.
  - Covers: FR-02, FR-12, FR-13

- [ ] **T16: Backups, format 2**
  - `BackupService`:
    - `FORMAT_VERSION = 2`;
    - remove the encrypted skip and the `encryptedNotSupported` path (keep the factory if it is still referenced in tests, otherwise delete it);
    - `isIndexableFor` per vault;
    - `ensureHeaderOnDisk` before the scan;
    - manifest `is_encrypted` and `encryption` as in ADR `encrypted-vault-backups`;
    - `validateArchive` accepts `format_version` ≤ 2 with the format-2 encrypted rules (inject `EncryptionService` to parse the archived key file through `ArchiveService::readEntry`);
    - `restore` creates the vault with `is_encrypted` and a `vault_encryption` row from the key file in the same transaction, using `newNoteAttributes(..., encrypted: true)`;
    - `inspect` exposes `is_encrypted` per vault.
  - `RestoreBackupDialog.vue` shows an "Encrypted" badge and the hint.
  - Tests (`tests/Feature/Backups/EncryptedBackupTest.php`, and extend the existing tests where the format number is asserted):
    - backup of a **locked** encrypted vault plus a plain vault; every ZIP entry and the manifest contain no names or content canary and no salt or wrapped key in the manifest;
    - restore after `resetRegistry` → locked; the password unlocks; contents equal the originals;
    - restore as copy → new UUID, same password works;
    - crafted manifests: an encrypted vault without a key file, a non-hex path, a mismatched `key_id`, a damaged key file, an extra unknown file → refused with nothing written;
    - format-1 archives (`makeZip` fixture) still restore.
  - Covers: FR-14

- [ ] **T17: Mandatory security suite** (`tests/Feature/Security/EncryptionSecurityTest.php`)
  - Each scenario uses canaries:
    - content `CANARY-CONTENT-7f3a`;
    - note name `Bank Accounts`;
    - folder `My Credentials`;
    - password `Canary-Password-91!`.

    It also collects the token and the data key (via a test-only helper that unlocks through `EncryptionService` and reads `VaultKey::material()`) as base64, base64url and hex needles.
  - Required assertions:
    1. **Disk**: after create, edit, rename, move, folder operations, encrypt-existing, password change and backup, `assertNoNeedlesUnder(storage root)` and the backup ZIP contain no canaries.
    2. **SQLite**: `assertNoNeedlesInDatabase` contains no content, names, password, token or data key. The only readable vault name allowed is the vault's own.
    3. **Session**: `serialize(session()->all())` contains no password, token or data key, and no names or content after any operation, including failed validations.
    4. **Logs and exceptions**: `captureLogs()` across wrong password, damaged key file, tampered notes, a DB failure in unlock, save and conversion, and a conversion swap failure contains no canaries. Thrown exceptions' `getMessage()` and `getTraceAsString()` contain no password.
    5. **Inertia props**:
       - locked Workspace JSON has no names or content;
       - unlocked responses never contain the data key, token (except the unlock and create JSON responses), salt or wrapped key;
       - shared `vaults` contains only booleans for encryption.
    6. **Lock**:
       - after lock, lock-all, idle expiry, `ScreenLocked`, or a fresh session with no header, every key-requiring endpoint returns locked;
       - a replayed old token after re-unlock (new token) is rejected.
    7. **Wrong password** never unlocks. **Correct password** does.
    8. **Cache**: `Cache-Control: no-store` on Workspace GETs.
    9. **KDF bounds**: a hostile key file is rejected quickly.
  - Add `docs` nothing. Write the **manual desktop checklist** in `implementation.md`:
    - packaged-app unlock time;
    - Win+L locks;
    - reload locks;
    - Explorer shows only hex names;
    - encrypting with a file open in Explorer fails cleanly;
    - `storage/logs/laravel.log` grep for canaries.
  - Covers: FR-17, plus the §56 acceptance criteria

- [ ] **T18: Finalize**
  - `implementation.md`:
    - deviations;
    - arch `ignoring()` justifications;
    - the manual checklist results.
  - Set ADR statuses to "Accepted (E… approved <date>)" once approved.
  - Covers: FR-18

**QA gate 7C**: full suite, phpstan, `types:check`, `test:js`; then analyst review.

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Services/EncryptionServiceTest.php` | Primitives, formats, tamper and swap detection, KDF bounds, rewrap, session seal, no password in traces | FR-03, FR-04, FR-06, FR-12 |
| `tests/Feature/Support/VaultKeyTest.php` | Redaction, no serialize or clone | FR-17 |
| `tests/Feature/Services/VaultKeyServiceTest.php` | Tokens, idle, epoch, screen lock, `key_id` mismatch, session contents | FR-07, FR-08 |
| `tests/Feature/Services/VaultEncryptionServiceTest.php` | Unlock, mirror sync, key-file restore, password change, DB failure | FR-05, FR-06, FR-12 |
| `tests/Feature/Services/EncryptedVaultIndexTest.php` | Key-free indexing, external changes, unencrypted-file warning | FR-11 |
| `tests/Feature/Services/EncryptedNoteServiceTest.php` | Every operation, conflicts, locked, tamper, signature | FR-04, FR-09 |
| `tests/Feature/Services/VaultConversionServiceTest.php` | Encrypt and decrypt, preflight, failure injection | FR-02, FR-13 |
| `tests/Feature/Services/VaultConversionRecoveryTest.php` | Five crash states, idempotence | FR-16 |
| `tests/Feature/Vaults/EncryptedVaultHttpTest.php` | Create, unlock, lock, close, 423, Workspace props, history flags | FR-01, FR-06, FR-07, FR-10 |
| `tests/Feature/Vaults/VaultEncryptionHttpTest.php` | Encrypt existing, decrypt, password change over HTTP | FR-02, FR-12, FR-13 |
| `tests/Feature/Settings/SecuritySettingsTest.php` | Security settings page | FR-08 |
| `tests/Feature/Security/HttpHygieneTest.php` | `no-store`, `dontFlash` | FR-17 |
| `tests/Feature/Security/EncryptionSecurityTest.php` | Mandatory security suite (T17) | FR-17, §56 |
| `tests/Feature/Backups/EncryptedBackupTest.php` | Format 2, encrypted backup and restore, hostile manifests, format-1 compatibility | FR-14 |
| `tests/Feature/Vaults/ExistingVaultTest.php`, `Services/VaultRegistryResetTest.php` (extend) | Register an encrypted folder, reset recovery | FR-15 |
| `tests/Feature/DatabaseSchemaTest.php`, `tests/Unit/ArchitectureTest.php` (extend) | Schema and arch rules | FR-05, FR-18 |
| `tests/js/vault/keyring.test.ts`, `tests/js/editor/saveTransport.test.ts` (extend) | Interceptor, prune, no storage, 423 | FR-07, FR-17 |
| Existing `tests/Feature/{Notes,Services,Vaults,Backups,Settings}` | Regression: plaintext behaviour unchanged | All |

**Test scope for QA (every gate)**:
- `php artisan test --compact` (the full suite: `NoteService`, `VaultIndexService`, `BackupService`, middleware and exception handling all change);
- `vendor/bin/phpstan analyse`;
- from 7B on: `npm run types:check` and `npm run test:js`.
- QA greps (expected only in the files allowed by the arch rules):
  - `sodium_` in `app/`;
  - `Log::|logger\(|report\(` in `EncryptionService`, `VaultKeyService`;
  - `localStorage|sessionStorage` in `resources/js/lib/vault`;
  - `deleteDirectory` in `app/`.

---

## 5. Risks & Mitigations
- **Risk**: regressions in Phases 3–6 from the cross-cutting changes. **Mitigation**: delegate at method entry; plaintext code paths unchanged; full suite at every gate.
- **Risk**: O(n) decryption per tree or operation is slow on large vaults. **Mitigation**: per-request memoization; the performance target in the requirements; a name cache as a follow-up.
- **Risk**: Argon2id at 256 MiB is slow or memory-hungry on low-end machines (libsodium allocates outside PHP's `memory_limit`). **Mitigation**: the cost is stored per vault; E2 fallback to INTERACTIVE.
- **Risk**: on Windows, folder handles held by other programs block the conversion swap. **Mitigation**: abort cleanly with nothing changed and a message to close those programs; manual check.
- **Risk**: forgotten passwords. **Mitigation**: acknowledgments and repeated warnings; no false promise of recovery.
- **Risk**: deleted plaintext stays forensically recoverable after conversion (SSD). **Mitigation**: explicit UI warning; recommend a new encrypted vault for the most sensitive data.
- **Risk**: old backups keep the old password. **Mitigation**: warning in the password-change UI.
- **Risk**: debug error pages expose request headers and bodies in development. **Mitigation**: Phase 8 enforces `APP_DEBUG=false`; documented.
- **Risk**: the unsaved-changes guard and auto-lock interact badly (a flush fails, so a dialog waits). **Mitigation**: the server-side idle backstop still locks; generic 423 handling.
- **Risk**: a dependency cycle between `VaultService` and the encryption services. **Mitigation**: the T9 fallback (`EncryptionHeaderService`); `recover()` depends only on `FileStorageService` and the DB.
- **Risk**: `ext-sodium` is missing in Herd or CI. **Mitigation**: T0 check; `composer.json` platform requirement.

---

## 6. Open Questions
All answered: the user approved E1–E12 as recommended on 2026-10-03.
- [x] **E1** (approved) Use `ext-sodium` (Argon2id + XChaCha20-Poly1305 + BLAKE2b) instead of the AES-256-GCM in the §31 example, and add `ext-sodium` to `composer.json`? *Recommended: yes.*
- [x] **E2** (approved) KDF cost MODERATE (ops 3, 256 MiB)? *Recommended: yes* (INTERACTIVE as a fallback).
- [x] **E3** (approved) On-disk key file (authoritative) plus a `vault_encryption` mirror? *Recommended: yes.*
- [x] **E4** (approved) Vault name, description and path stay readable; sizes, counts, shape and times are visible; no note or folder names in SQLite? *Recommended: yes.*
- [x] **E5** (approved) Split-key custody (renderer token plus session-sealed key); reload or restart locks? *Recommended: yes.*
- [x] **E6** (approved) Auto-lock 15 min default (0, 5, 15, 30, 60), lock on screen lock on, lock on close? *Recommended: yes.*
- [x] **E7** (approved) Encrypt existing vaults, refusing non-note contents, and permanently delete the unencrypted original after success (amends the "never permanently delete" ADR)? *Recommended: yes.*
- [x] **E8** (approved) Include "Remove encryption"? *Recommended: yes.*
- [x] **E9** (approved) Password min 10 and max 1024, confirmation, no recovery key or hint, data-loss acknowledgment? *Recommended: yes.*
- [x] **E10** (approved) Backup format 2 for all new backups; encrypted vaults copied as ciphertext and restored locked? *Recommended: yes.*
- [x] **E11** (approved) Unencrypted `.md` files inside encrypted vaults: warn only, no indexing or import? *Recommended: yes.*
- [x] **E12** (approved) Deliver as 7A, 7B and 7C with QA after each? *Recommended: yes.*

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-10-03 | Initial plan | — |
