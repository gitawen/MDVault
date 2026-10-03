# Implementation: Phase 7: Encryption

## Metadata
- **Feature Name**: Phase 7: Encryption
- **Feature ID**: mdv-p7
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: 7A and 7B PASSED QA; 7C READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T0 | DONE (orchestrator) | ADRs, `ext-sodium` in composer.json. Not redone. |
| T1 | DONE | Config, migration, model, phpunit env, `Vault::encryption()`, `resetRegistry()`, schema and reset tests, Pest helpers |
| T2 | DONE | `VaultKey`, `EncryptionHeader`, `EncryptionException`, `VaultLockedException` (+ renderer), `VaultKeyTest` |
| T3 | DONE | `EncryptionService` + `EncryptionServiceTest` |
| T4 | DONE | `SettingKey` additions, `VaultKeyService`, `ProvideVaultKeys`, `bootstrap/app.php`, `AppServiceProvider`, shared `security`, tests |
| T5 | DONE | `VaultEncryptionService` + test. `recover()` is T14 (not part of 7A). |
| T6 | DONE | Arch rules added to `tests/Unit/ArchitectureTest.php` |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| created | `config/mdvault.php` | `encryption.kdf.opslimit/memlimit`, `encryption.allow_weak_kdf` |
| created | `database/migrations/2026_10_02_234115_create_vault_encryption_table.php` | `vault_encryption` table exactly as plan §2 (the generated timestamp is the machine clock) |
| created | `app/Models/VaultEncryption.php` | Model, hidden `id/vault_id/salt/nonce/encrypted_key`, int casts, `vault()` |
| modified | `app/Models/Vault.php` | `encryption(): HasOne` + PHPDoc |
| modified | `app/Services/VaultService.php` | `resetRegistry()` deletes `VaultEncryption` rows first, in the same transaction |
| created | `app/Support/VaultKey.php` | Redacted secret holder; `__serialize`/`__clone` throw; `__destruct` memzero |
| created | `app/Support/EncryptionHeader.php` | Key-file DTO + `toRegistryAttributes()` |
| created | `app/Exceptions/EncryptionException.php` | Generic factories |
| created | `app/Exceptions/VaultLockedException.php` | Fixed message |
| created | `app/Services/EncryptionService.php` | The only `sodium_*` user |
| created | `app/Services/VaultKeyService.php` | Keyring (scoped) |
| created | `app/Services/VaultEncryptionService.php` | Key file I/O, mirror, unlock/lock, password change, headerAt/adopt/ensureHeaderOnDisk |
| created | `app/Http/Middleware/ProvideVaultKeys.php` | Hands the header to the keyring |
| modified | `app/Enums/SettingKey.php` | `SecurityAutoLockMinutes` (int, 15), `SecurityLockOnScreenLock` (bool, true), group Security |
| modified | `bootstrap/app.php` | Middleware order, `no_store;private`, `dontFlash`, 423 / redirect renderer |
| modified | `app/Providers/AppServiceProvider.php` | `VaultKeyService` scoped; `ScreenLocked` listener |
| modified | `app/Http/Middleware/HandleInertiaRequests.php` | Lazy shared `security` prop (injects `SettingsService`) |
| modified | `phpunit.xml` | `MDVAULT_KDF_OPSLIMIT=1`, `MDVAULT_KDF_MEMLIMIT=8192`, `MDVAULT_ALLOW_WEAK_KDF=true` |
| modified | `tests/Pest.php` | `encryptedVault`, `withVaultToken`, `assertNoNeedlesUnder`, `assertNoNeedlesInDatabase`, `captureLogs` |
| modified | `tests/Unit/ArchitectureTest.php` | 6 new rules (T6) |
| modified | `tests/Feature/DatabaseSchemaTest.php` | `vault_encryption` added to the table and column tests |
| modified | `tests/Feature/Services/VaultRegistryResetTest.php` | Reset removes mirror rows, never touches the key file |
| created | `tests/Feature/Support/VaultKeyTest.php` | Redaction, serialize, clone |
| created | `tests/Feature/Services/EncryptionServiceTest.php` | T3 matrix |
| created | `tests/Feature/Services/VaultKeyServiceTest.php` | T4 matrix + middleware |
| created | `tests/Feature/Services/VaultEncryptionServiceTest.php` | T5 matrix |
| created | `tests/Feature/Security/HttpHygieneTest.php` | `no-store`, `dontFlash`, 423 rendering |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | clean (final run) |
| `php vendor/bin/phpstan analyse --no-progress` | passed, 0 errors |
| 7A tests (`EncryptionServiceTest`, `VaultKeyTest`, `VaultKeyServiceTest`, `VaultEncryptionServiceTest`, `HttpHygieneTest`, `DatabaseSchemaTest`, `VaultRegistryResetTest`, `ArchitectureTest`) | all pass |
| `php artisan test --compact` (full suite) | 875 tests, 864 passed, 11 skipped (pre-existing skips), 0 failed |
| Negative check of the arch rules (temporary file, removed) | `sodium_memzero`, `report`, and `VaultKey` rules do fail when violated |

No `npm` build was run (no frontend change in 7A). No route changes, so no Wayfinder regeneration.

---

## 4. Deviations from Plan
- **Migration filename date** is `2026_10_02_234115` (artisan used the machine clock) instead of the plan's `2026_10_03_xxxxxx`. Ordering is still after every existing migration. No impact.
- **`DatabaseSchemaTest`**: the "later-phase tables" test was kept as a guard but re-pointed at `folders`, `sync_devices`, `remote_accounts` (Master Plan rule: no `folders` table, no sync/device/remote-account tables in v1) instead of being removed.
- **`encryptedVault()` helper** is fully implemented now (builds the key file by hand with `EncryptionService`, `adopt()`, then `unlock()`), not stubbed. The caller must call `fakeDocumentsDirectory()` first. T9 may re-point it at `createEncryptedVault`.
- **Extra `EncryptionException` factories** beyond the plan's list: `unsupportedNote()` (name 1-255 bytes of UTF-8 / size cap), `cryptoFailed()` (a `SodiumException`, e.g. not enough memory for Argon2), `keyFileWriteFailed()` (key file replace failed or guard lost). All fixed generic messages.
- **Empty password** to `EncryptionService::unlock()` throws `wrongPassword` before the KDF (libsodium refuses an empty password).
- **Session key layout**: Laravel session keys use dot notation, so `mdvault.keyring.<uuid>` is stored nested (`mdvault` -> `keyring` -> `<uuid>`). `forgetAll()` therefore forgets `mdvault.keyring` as a whole; behaviour matches the ADR.
- **Mirror `created_at` doubles as the key file `created_at`**: `adopt()`/mirror creation sets the row's `created_at` to the header's `createdAt` (UTC), so a key file rebuilt from the mirror is byte-identical to the original (and `header_hash` matches). The table has no separate column for it (schema is per plan).
- **Base64 outside tokens** uses PHP `base64_encode`/`base64_decode(strict)` (key file, mirror, session entry); `sodium_bin2base64`/`sodium_base642bin` are used only for the base64url session token, inside `EncryptionService`.
- **`VaultKey::$material` is `?string`** so `sodium_memzero` (which nulls its argument) type-checks under PHPStan; `material()` throws `LogicException` after a wipe.
- **Pint** reformatted only my own additions in `tests/Pest.php` and `VaultRegistryResetTest.php`.
- The `ScreenLocked` listener lives in `AppServiceProvider::lockVaultsWhenScreenLocks()` called from `boot()`.

---

## 5. Notes for QA
- **Unlock ordering in `VaultKeyService::keyFor`**: epoch, idle and `key_id` failures purge the entry; a missing or wrong token only returns null (does not purge). Intentional, so a stray request cannot lock out the real tab. Probe if you disagree.
- **Per-request memo**: opened keys are cached per request and cleared on `provideTokens()` and at `terminating`. Tests drive the scoped service across several "requests" by calling `provideTokens()`.
- **Mirror sync is best effort**: unlock never fails because the mirror update failed (`report()` is used in `VaultEncryptionService`, which the arch rule allows; only `EncryptionService` and `VaultKeyService` are banned from logging). If the mirror update fails and no row exists, `keyFor` will purge (no `key_id` to match) until a later unlock resyncs.
- **`var_export` / `(array)` cast of a `VaultKey`** would reveal the property (only `print_r`, `var_dump`, `json_encode`, `serialize`, `clone` are covered, as in the plan). Nothing in the app does this; the arch rules ban `var_export` in the two crypto services.
- **PHP string sharing**: `sodium_memzero` in `VaultKey::__destruct` zeroes the underlying buffer; any other variable that still shares that exact string will read zeros after the key is destroyed. Tests copy needles before keys die.
- **KDF cost in tests** is weak (ops 1, mem 8192) via `phpunit.xml`; production default is MODERATE and is floored at INTERACTIVE unless `allow_weak_kdf`. There is a test for the floor.
- **Not in 7A**: `recover()`, `createEncryptedVault`, `VaultService::register/present/remove/close` changes, HTTP controllers/routes, frontend, `NoteService` delegation, indexing, backups, conversion.
- **Existing dev DB** has pending migrations (including `backups` and the new `vault_encryption`); it was not migrated.
- Manual desktop checklist (T17) is not started.

---

## 6. Fix Rounds
*None yet.*

---

## 7. Sub-phase 7B: Working encrypted vaults (T7 to T12)

### 7.1 Task progress
| Task | Status | Notes |
|---|---|---|
| 7A-QA-02 | DONE | `VaultKeyService::isIdle`: a negative stored value falls back to the default (15); only an explicit 0 means "never". |
| 7A-QA-03 | DONE | `VaultKeyServiceTest`: lazy shared `security` prop (a full visit has it, a partial reload does not evaluate it), memo and tokens cleared at `terminate()`, a missing or wrong token leaves the session entry, corrupt and negative auto-lock values. |
| 7A-QA-04 | DONE | Two prefix-based arch tests (see 7.2). |
| T7 | DONE | Name rules, encryption-aware indexing, `Note::ENCRYPTED_MIME_TYPE`. |
| T8 | DONE | `EncryptedNoteService`, `VaultNamespace`, `NoteService` delegation, `ExternalChangeService` logical path. |
| T9 | DONE | `createEncryptedVault`, register detects the key file, `present()` adds `is_unlocked`, `remove()` and `close()` forget. |
| T10 | DONE | HTTP layer, routes, Workspace props, generic toasts. Wayfinder regenerated. |
| T11 | DONE | `keyring.ts`, interceptor, 423 mapping, types. |
| T12 | DONE | Unlock panel, lock button and sidebar action, create dialog, auto-lock, Security page, Workspace warnings. |

### 7.2 Files changed
| Action | Path | Summary |
|---|---|---|
| modified | `app/Services/VaultKeyService.php` | idle clamp (7A-QA-02) |
| modified | `app/Services/StoragePathService.php` | `assertValidNoteSegment()` |
| modified | `app/Services/NoteService.php` | uses `assertValidNoteSegment()`; injects `EncryptedNoteService`; delegates at entry in `create`, `createCopy`, `rename`, `move`, `delete`, `createFolder`, `deleteFolder`, `preview`, `save`, `present` |
| modified | `app/Services/VaultIndexService.php` | `ENCRYPTED_NOTE_PATTERN`, `ENCRYPTED_FOLDER_PATTERN`, `isIndexableFor`, `isEncryptedNotePath`, `encryptedFolderDirectories`, `treeSignatureFor`, `unencryptedFilesIn`; `plan()` collects `unencryptedFiles`; `newNoteAttributes(..., encrypted)` |
| modified | `app/Support/ReconcilePlan.php`, `IndexResult.php` | `unencryptedFiles` (default `[]`) |
| modified | `app/Models/Note.php` | `ENCRYPTED_MIME_TYPE` |
| created | `app/Services/EncryptedNoteService.php` | all encrypted note and folder operations, name map, tree |
| created | `app/Support/VaultNamespace.php` | decrypted name map and lookups |
| created | `app/Services/EncryptionHeaderService.php` | `inspect`, `headerAt`, `adopt`, `syncMirror`, key file read (deviation 1) |
| modified | `app/Services/VaultEncryptionService.php` | `createEncryptedVault`, `isConsistent`; `headerAt`/`adopt` delegate to `EncryptionHeaderService`; the unused `DatabaseManager` was dropped from the constructor |
| modified | `app/Services/VaultService.php` | registers encrypted folders, `present()` adds `is_unlocked`, `remove()`/`close()` forget |
| modified | `app/Services/ExternalChangeService.php` | logical `relative_path` for the open note while unlocked |
| modified | `app/Exceptions/NoteOperationException.php`, `NoteSaveConflictException.php`, `VaultOperationException.php` | generic factories (no names), `damagedEncryptionHeader` |
| modified | `app/Providers/AppServiceProvider.php` | `EncryptedNoteService` scoped |
| created | `app/Http/Requests/Vaults/StoreEncryptedVaultRequest.php`, `UnlockVaultRequest.php`, `app/Http/Requests/Settings/UpdateSecuritySettingsRequest.php` | validation |
| created | `app/Http/Controllers/EncryptedVaultController.php`, `VaultUnlockController.php`, `VaultLockController.php`, `Settings/SecurityController.php` | thin HTTP |
| modified | `app/Http/Controllers/WorkspaceController.php`, `NoteController.php`, `FolderController.php`, `NoteCopyController.php` | locked props, `encryption` prop, `encryptHistory`, generic toasts |
| modified | `routes/vaults.php`, `routes/settings.php` | 4 vault routes, 2 settings routes |
| regenerated | `resources/js/actions/**`, `resources/js/routes/**` | `wayfinder:generate --with-form` |
| created | `resources/js/lib/vault/keyring.ts` | token map, header, prune, interceptor |
| created | `resources/js/composables/useAutoLock.ts` | idle lock |
| created | `resources/js/components/vaults/UnlockVaultPanel.vue`, `VaultLockButton.vue` | unlock and lock UI |
| created | `resources/js/pages/settings/Security.vue`, `resources/js/types/security.ts` | settings page, types |
| modified | `resources/js/components/vaults/CreateVaultDialog.vue`, `SidebarVaultItem.vue`, `resources/js/pages/Workspace.vue`, `pages/vaults/Index.vue`, `layouts/AppLayout.vue`, `layouts/settings/Layout.vue`, `app.ts`, `lib/editor/saveTransport.ts`, `types/vaults.ts`, `types/index.ts`, `types/global.d.ts` | wiring |
| created tests | `tests/Feature/Services/EncryptedVaultIndexTest.php`, `EncryptedNoteServiceTest.php`, `EncryptedVaultLifecycleTest.php`; `tests/Feature/Vaults/EncryptedVaultHttpTest.php`; `tests/Feature/Settings/SecuritySettingsTest.php`; `tests/js/vault/keyring.test.ts` | |
| modified tests | `VaultKeyServiceTest` (+5), `tests/Unit/ArchitectureTest.php` (+2 prefix-based tests; `EncryptedNoteService` and `EncryptionHeaderService` added to the raw-filesystem rules), `VaultServiceTest` (`is_unlocked` key), `SettingsNavigationTest`, `WorkspaceTest` (deviation 6), `saveTransport.test.ts` (423) | |

Arch rules added (7A-QA-04): every `sodium_*` call anywhere in `app`, `bootstrap`, `config`, `database` or `routes` must be in `EncryptionService` or `VaultKey`, and `VaultKey` may only call `sodium_memzero`; `->material(` may appear only in `EncryptionService`.

### 7.3 Verification performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | passed |
| `php vendor/bin/phpstan analyse --no-progress` | passed, 0 errors |
| `php artisan test --compact` (full) | 964 tests, 953 passed, 11 skipped (pre-existing), 0 failed |
| `npm run types:check` | passed |
| `npm run test:js` | 13 files, 155 tests passed |
| `npm run build` | passed; `public/fonts-manifest.dev.json` restored with `git checkout` |
| `npx vp check` | no findings in 7B files; the only finding is the pre-existing formatting of `RestoreBackupDialog.vue` (left untouched: `vp check --fix` was run once and that unrelated change was reverted) |
| `php artisan wayfinder:generate --with-form --no-interaction` | run after the routes were added and the stale route cache cleared (deviation 7) |

### 7.4 Deviations from plan
1. **`EncryptionHeaderService` (the T9 fallback).** `VaultEncryptionService::createEncryptedVault` needs `VaultService::create()`, and `VaultService::register()` needs the key file reader, so a direct dependency would be a cycle. The small `EncryptionHeaderService` (no `VaultService` dependency) holds `inspect`/`headerAt`/`adopt`/`syncMirror` and the header read. `VaultEncryptionService::headerAt` and `adopt` remain (delegating) so the 7A API and tests are unchanged. `VaultService` depends on `VaultKeyService` and `EncryptionHeaderService`.
2. **Registration is one transaction.** `register()` creates the vault row and the mirror (`adopt`) inside one transaction, so a damaged key file or a mirror failure leaves no half-registered vault.
3. **Extra exception factories.** `NoteOperationException::encryptedFileMissing/encryptedMoveFailed/encryptedMoveRollbackFailed/encryptedTrashFailed/encryptedFolderNotEmpty/encryptedFolderDeleteFailed/encryptedSaveLocked/encryptedSaveWriteFailed/encryptedReadOnlyFile/encryptedNotEditable/encryptedSaveUnreadable/encryptedCopyNameUnavailable`, because the plaintext messages embed the path. None contains a name or path. Also `VaultOperationException::damagedEncryptionHeader`.
4. **`EncryptedNoteService` is `scoped`** (one instance per request, so the controller and `NoteService` share one name-map memo). The memo is validated on every call (fingerprint of the directories, folder name file size and mtime, and the registry rows), so a stale map is never served after a write or an external change.
5. **Extra helper methods** beyond the plan: `EncryptedNoteService::logicalPathFor` (decrypts only one note and its folder chain, so the 5-second change poll is O(depth), not O(notes)), `VaultIndexService::unencryptedFilesIn` (the cheap scan for the Workspace warning), `VaultEncryptionService::isConsistent` (the `inconsistent` flag), `VaultIndexService::treeSignatureFor` (one signature function for `apply()` and `browse()`).
6. **`/settings/security` is reused.** `WorkspaceTest` listed `/settings/security` among the removed Fortify starter-kit routes that must 404. Phase 7 mandates that exact URL (plan §2), so it was removed from that list; the other removed routes are still asserted.
7. **Stale route cache.** `bootstrap/cache/routes-v7.php` (git-ignored, dated Sep 30) hid the new routes from tests and Wayfinder. I ran `php artisan route:clear`. The user's dev environment needs the same if they ever cached routes.
8. **Foreign directories in encrypted vaults.** Only `<32hex>` directories count as folders in `directories` and in the tree signature; a plaintext `.md` at any depth is still reported in `unencryptedFiles`. A `.mdenc` outside the opaque layout (for example `Photos/<hex>.mdenc`) is not indexed.
9. **`NoteCopyController`** returns the logical `title` and `relative_path` for encrypted vaults (the model's own are opaque), because the editor navigates by them.
10. **Rename of an encrypted note** rewrites the file in place (same path, same UUID). A registry failure after the file was replaced is reported (`report()`) and not compensated, as in `save()` (the file is the truth, Rule 9); the next reconcile repairs the row.
11. **423 on the copy transport is not mapped** (only `saveTransport`, per plan); a locked "Save as new note" shows the existing generic copy error.
12. **`dontRemember`**: `useHttp` is never given a remember key, so nothing is remembered anyway; `.dontRemember('password', ...)` is still called on both forms.
13. **JS prune grace.** `pruneFrom` never drops a token younger than 3 s, so an in-flight visit that started before an unlock cannot discard the fresh token.
14. No change was needed to `HandleInertiaRequests`: the `security` prop was already shared, and `is_unlocked` comes through `VaultService::present`.

### 7.5 Notes for QA
- **Delegation seam**: every `NoteService` method that takes a vault or note starts with an `is_encrypted` check. `assertValidNoteName`/`assertValidFolderName` remain the request validators (names only, no disk).
- **Locked means nothing is sent**: a locked encrypted vault gets `tree` null, `folders` `['']`, `treeSignature` null and `note` null. `encryption.unencrypted_files` lists plaintext `.md` names (already plaintext on disk).
- **Name map cost**: `namespace()` decrypts every note and folder name file once per change (O(notes)); the background change check uses `logicalPathFor`.
- **Idle timer**: Workspace renders and the partial reloads that follow a detected change touch the idle timer (`keyFor` with touch); only the 5-second check path uses `touch: false`.
- **Compare the encrypted `save()` with `NoteService::save()`**: hash guard, post-write re-hash, no-op compares plaintext.
- **`deleteFolder`** deletes `folder.mdenc` first and recreates it if the `rmdir` fails; a folder holding any other file (a symlink too, which `scan` skips) is refused.
- **Name collisions are checked against readable notes only**; an unreadable note's name is unknown and cannot block a name.
- **A note row whose file is gone is skipped** by the name map until the next reconcile removes the row.
- **No unlock rate limiting**: each attempt costs one Argon2 run; there is no throttle (local desktop app). Say if you want one.
- **Not in 7B**: conversion, recovery, the change-password UI, backup format 2, `RestoreBackupDialog`, the security suite (T13 to T18), and the arch rule for `VaultConversionService`.

### 7.6 Manual checks for the user (desktop)
1. Run `php artisan route:clear` if your setup caches routes; restart `npm run dev` or run `npm run build`.
2. New vault, tick "Encrypt this vault", password of 10+ characters: the vault opens unlocked; Explorer shows the folder with only `mdvault-encryption.json`.
3. Create a folder and a note and type text: Explorer shows only 32-character hex names; a `.mdenc` opened in a text editor is binary.
4. Lock from the sidebar (lock icon) and from the header button: the unlock panel appears and the tree is gone.
5. A wrong password shows a generic error; the right one unlocks (about 1 s with default settings).
6. Reload the window (Ctrl+R): the vault is locked again.
7. Settings, Security: set auto-lock to 5 minutes and leave the app idle: it locks itself. With "lock on screen lock" on, press Win+L: the next action asks for the password.
8. Drop a plain `Secret.md` into an unlocked encrypted vault folder: a warning lists it and it is not shown as a note.
9. Delete `mdvault-encryption.json` and reload: the vault still unlocks with its password and the file is re-created.
10. Search `storage/logs/laravel.log` for a note name or some note text you typed: nothing is found.

---

## 8. Sub-phase 7C: Conversion, password management, backups, security sign-off (T13 to T18)

### 8.1 Task progress
| Task | Status | Notes |
|---|---|---|
| 7B-QA-01 | DONE | `throttle:10,1` on `vaults.unlock` (generic Laravel 429 `Too Many Attempts.`); test in `VaultEncryptionHttpTest`. |
| 7B-QA-02 | DONE | `keyring.ts` attaches `X-MDVault-Unlock` only when `isSameOrigin(config.url)`; a missing or unresolvable URL fails closed. JS test covers relative, same-origin, other-origin, other-scheme, protocol-relative, userinfo, `javascript:` and missing URLs. |
| 7B-QA-04 | DONE | Trailing newline added to `tests/Unit/ExampleTest.php`; `pint --test` is clean. |
| 7A-QA-05 | DONE | `EncryptionHeaderService::syncMirror` sets the mirror `created_at` on every sync (fixed, not just documented); test in `VaultEncryptionServiceTest`. |
| 7A-QA-01, 7A-QA-06 | ACCEPTED RISK | Recorded in the Follow-ups of ADRs `vault-encryption-cryptography` and `encrypted-vault-key-custody`. No fix was trivial in the T17 work. |
| T13 | DONE | `FileStorageService`: three conversion prefixes, `deleteStagingDirectory` allow-list, `inventory()`. |
| T14 | DONE | `VaultConversionService`, `VaultRecoveryService` (see deviation 1), recovery hooks. |
| T15 | DONE | Requests, `VaultEncryptionController` (`store`, `destroy`), `VaultPasswordController`, routes, three dialogs, Wayfinder regenerated. |
| T16 | DONE | `BackupService` format 2 (create, validate, inspect, restore), `RestoreBackupDialog`, types. |
| T17 | DONE | `EncryptionSecurityTest` (20 tests, about 1950 assertions); manual desktop checklist in 8.6. |
| T18 | DONE (code, tests, docs) | ADR statuses set to Accepted; the feature folder is not moved and nothing is committed. |

### 8.2 Files changed
| Action | Path | Summary |
|---|---|---|
| modified | `app/Services/FileStorageService.php` | `ENCRYPT_STAGING_PREFIX`, `DECRYPT_STAGING_PREFIX`, `CONVERSION_ORIGINAL_PREFIX`; `deleteStagingDirectory` accepts them (still the only recursive delete); `inventory()` (unfiltered, no link following) |
| created | `app/Services/VaultRecoveryService.php` | Crash recovery table from the ADR, the per-vault conversion lock, `recoverAll()` |
| created | `app/Services/VaultConversionService.php` | `preflight`, `encrypt`, `decrypt` (stage, verify, swap inside the DB transaction) |
| created | `app/Support/ConversionResult.php` | token, note count, cleanup warning |
| modified | `app/Services/VaultService.php` | injects `VaultRecoveryService`; `open()` and `refreshStatus()` (missing folder) run recovery |
| modified | `app/Services/VaultEncryptionService.php` | `recover()` (delegates), called at the start of `changePassword` |
| modified | `app/Providers/NativeAppServiceProvider.php` | `recoverAll()` at boot, in try/catch |
| modified | `app/Services/EncryptionHeaderService.php` | mirror `created_at` on every sync (7A-QA-05) |
| modified | `app/Exceptions/EncryptionException.php` | `foreignFiles()`, `unreadableNotes()` |
| modified | `app/Exceptions/BackupException.php` | `encryptedNotSupported()` removed, `encryptedKeyUnavailable()` added |
| modified | `app/Services/BackupService.php` | `FORMAT_VERSION = 2`; encrypted vaults backed up as ciphertext; format-2 validation; restore creates the vault, the mirror and encrypted note rows; inspect exposes `is_encrypted` |
| modified | `app/Support/BackupInspection.php` | doc type |
| created | `app/Http/Requests/Vaults/EncryptVaultRequest.php`, `DecryptVaultRequest.php`, `UpdateVaultPasswordRequest.php` | validation |
| created | `app/Http/Controllers/VaultEncryptionController.php`, `VaultPasswordController.php` | thin HTTP |
| modified | `routes/vaults.php` | encryption store and destroy, password update, unlock throttle |
| regenerated | `resources/js/actions/**`, `resources/js/routes/**` | `wayfinder:generate --with-form` |
| created | `resources/js/components/vaults/EncryptVaultDialog.vue`, `DecryptVaultDialog.vue`, `ChangeVaultPasswordDialog.vue` | UI |
| modified | `resources/js/pages/vaults/Index.vue` | buttons on each vault card |
| modified | `resources/js/components/backups/RestoreBackupDialog.vue`, `resources/js/types/backups.ts` | Encrypted badge and hint |
| modified | `resources/js/lib/vault/keyring.ts` | same-origin check |
| modified tests | `tests/Feature/Services/FileStorageServiceTest.php`, `VaultEncryptionServiceTest.php`, `tests/Feature/Backups/BackupCreateTest.php` (format 2), `tests/Unit/ArchitectureTest.php`, `tests/Unit/ExampleTest.php`, `tests/js/vault/keyring.test.ts` | |
| created tests | `tests/Feature/Services/VaultConversionServiceTest.php` (27 incl. datasets), `VaultConversionRecoveryTest.php` (13), `tests/Feature/Vaults/VaultEncryptionHttpTest.php` (16), `tests/Feature/Backups/EncryptedBackupTest.php` (19), `tests/Feature/Security/EncryptionSecurityTest.php` (20) | |
| modified docs | ADRs `vault-encryption-cryptography`, `encrypted-vault-key-custody`, `encrypted-vault-storage-layout`, `encrypted-vault-backups`, `vault-encryption-conversion` | statuses Accepted; accepted risks; conversion implementation notes |

Arch rules added or extended (`tests/Unit/ArchitectureTest.php`): the "no raw filesystem functions" rule now also covers `VaultConversionService` and `VaultRecoveryService`; both may not log or dump; `App\Services`, `App\Http` and `App\Support` may not use the `File` facade. No `ignoring()` exemptions were needed.

### 8.3 Verification performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --test` | passed (whole repo, including `tests/Unit/ExampleTest.php`) |
| `php vendor/bin/phpstan analyse --no-progress` | passed, 0 errors |
| `php artisan test --compact` (full) | 1068 tests, 1055 passed, 13 skipped (11 pre-existing, 2 new `skipOnWindows` symlink tests), 0 failed |
| `npm run types:check` | passed |
| `npm run test:js` | 13 files, 156 tests passed |
| `npm run build` | passed (exit 0); `public/fonts-manifest.dev.json` restored with `git checkout` |
| `npx vp check resources/js` | only finding is the pre-existing formatting of `RestoreBackupDialog.vue` (my edit to it is formatted; `--fix` was not run on it to avoid an unrelated rewrite) |
| `php artisan wayfinder:generate --with-form --no-interaction` | run after the routes were added |

The dev DB was not migrated.

### 8.4 T17 security suite (`tests/Feature/Security/EncryptionSecurityTest.php`)
Canaries: content `CANARY-CONTENT-7f3a`, note `Bank Accounts`, folder `My Credentials`, passwords `Canary-Password-91!` and `Another-Canary-Phrase-77?`, plus renamed and moved names and the names and content of a converted legacy vault. The tokens seen and the data keys (obtained by unlocking through `EncryptionService` with the password) are searched as hex, base64 and base64url (with and without padding).

| Plan assertion | Tests |
|---|---|
| 1 Disk | One lifecycle test drives HTTP create, folder, note, save, rename, move, folder delete, a failed validation, password change, encrypt-existing and a full backup, then runs `assertNoNeedlesUnder(storage root)` and scans every ZIP entry name and body. It also asserts every file under the vaults is `mdvault-encryption.json`, `<32hex>.mdenc` or `folder.mdenc` with hex folders. |
| 2 SQLite | `assertNoNeedlesInDatabase` after the lifecycle; a test that registry rows hold only opaque paths, hex titles and ciphertext hashes. |
| 3 Session | `serialize(session()->all())` after the lifecycle and after failed unlocks, a rejected create (mismatched confirmation) and a rejected decrypt, plus an Inertia render. |
| 4 Logs and exceptions | `captureLogs()` across a wrong password, a tampered note, a DB failure while saving, a DB failure in the mirror sync during unlock, a damaged key file, a conversion DB failure and a conversion swap failure; every log text and every thrown exception message and trace is checked, and the test asserts logs were actually captured. A second test checks the unlock exception. |
| 5 Inertia props | Locked workspace JSON has no names or content; an unlocked response has no data key, token, salt, nonce, wrapped key, password or `wrapped_key`; the shared `vaults` summaries have exactly the allowed keys with booleans; only the unlock response carries a token (workspace, vaults page, disk JSON and the lock response are scanned). |
| 6 Lock | Lock, lock-all, idle expiry, `ScreenLocked`, and a fresh session each leave 8 key-requiring endpoints answering 423 (disk compare, save, create note, create folder, rename, move, delete, copy) and the workspace without tree or note; after a re-unlock the new token works and the replayed old token is rejected; no header with a valid session entry is locked. |
| 7 Passwords | Seven wrong attempts (empty, wrong, trailing or leading space, upper-cased, truncated, another vault password) never unlock and leave no session entry; the correct one does; after a password change the old one fails. |
| 8 Cache | `Cache-Control` has `no-store` and `private` on the workspace, a note page and the vaults page. |
| 9 KDF bounds | Four hostile key files (2^31 memory, 11 passes, 0 passes, tiny memory) are refused with 422 in under a second and leave no session entry. |

Other 7C coverage: `VaultConversionServiceTest` (round trip with UUIDs, nested and empty folders, unicode, BOM, CRLF, invalid UTF-8; preflight refusals for an attachment, `.git`, `.obsidian`, `node_modules`, a hidden file, a fresh save temp file, an oversized note and a symlink; a note changed and a note added mid-conversion; first and second rename failures; rename-back failure then recovery; DB failure at the notes update and the mirror insert; cleanup failure then recovery; duplicate and invalid names on decrypt; wrong password, foreign file and tampered note on decrypt; DB failure on decrypt; no names, content or password in logs; a conversion in flight), `VaultConversionRecoveryTest` (the five crash states plus idempotence, the lock, hooks in `open()` and `refreshStatus()`, prefix isolation, `recoverAll`), `EncryptedBackupTest` and `VaultEncryptionHttpTest`.

### 8.5 Deviations from plan
1. **`VaultRecoveryService`** holds `recover()` (the plan put it in `VaultEncryptionService`). `VaultService::open()` and `refreshStatus()` must call it, and `VaultEncryptionService` depends on `VaultService`, so a direct dependency would be a cycle (the same reason as the 7B `EncryptionHeaderService`). `VaultEncryptionService::recover()` delegates, so the plan's API exists. `recoverAll()` was added so `NativeAppServiceProvider` does not touch the `Vault` model (an arch rule).
2. **A cache lock around conversions** (`mdvault.conversion.<uuid>`, held by `encrypt` and `decrypt`; `recover()` does nothing while it is held). Without it a request that opened the vault between the two renames, or a second window, could run recovery against a conversion that was still running. The ADR table is otherwise implemented as written. A cache store without locks makes the marker a no-op.
3. **`encrypt` and `decrypt` return `ConversionResult`** (token, note count, cleanup warning) instead of a bare token, so the "committed, but the original could not be deleted" warning can be returned (the plan lists `ConversionResult` under support types but gave the methods a `string` return).
4. **Full reconcile before encrypting** (the ADR says Quick). A Full reconcile hashes every file, so the registry hashes the verify step compares against are current. The extra before/after picture of the whole folder (every entry, size and mtime) is also not in the ADR; it catches a file added during the build, which a hash check of known notes would miss.
5. **Decrypt refuses foreign files** (anything in the folder that is not the key file, a `folder.mdenc`, a hex folder, a registered note or an old save temp file), listing them. The ADR only required aborting for undecryptable notes; without this check a plaintext file dropped into the encrypted vault (E11) would be deleted with the replaced original.
6. **Name rules for encrypt preflight** also apply `assertValidNoteSegment` (names must be valid for new encrypted notes: at most 100 characters, no reserved characters, not hidden), and two notes in a folder that differ only by case are refused. So every converted name can be renamed and converted back. There is also a free-disk-space check (needs the vault size plus 8 MiB).
7. **Error text for refused conversions** uses one `problem_<n>` error key per problem in the JSON 422 (plus the `problems` list), because the client shows the first message of each error key. The decrypt redirect appends the problems to the `vault` error message.
8. **Backup scan of encrypted vaults** includes only `<id>.mdenc` notes, hex folders, `folder.mdenc` and the key file. A plaintext file dropped into an encrypted vault is never copied into the archive (it would otherwise put plaintext into a "ciphertext only" backup and fail validation).
9. **A damaged or unrecoverable key file** makes a single-vault backup fail with `encryptedKeyUnavailable` and a full backup skip that vault (listed in `skippedVaults`), the same way a missing folder is handled.
10. **UI placement**: the encrypt, remove-encryption and change-password dialogs are on the vault card in `pages/vaults/Index.vue` only. The sidebar keeps its 7B lock action; no password action was added there ("when relevant" in the plan).
11. **`BackupService` constructor** gained `EncryptionService`, `EncryptionHeaderService` and `VaultEncryptionService` (before the existing `aggregateRatioAllowanceBytes` seam, which is still resolved by name).
12. **`set_time_limit(0)`** is called in the encrypt and decrypt controllers (a large vault can exceed PHP's default 30 s web time limit while Argon2 and the copy run).

### 8.6 Notes for QA
- **Swap order**: the DB writes are done first inside the transaction; the two directory renames are the last statements in the transaction closure. Check `VaultConversionService::commit()` for every failure ordering (DB failure before renames, first rename, second rename, commit failure after the swap, undo failure).
- **Recovery table**: `VaultRecoveryService::repair()`. Probe the "V and O exist, enc(V) = DB" branch (deletes the original) for any state where that could be wrong, and the lock behaviour.
- **Permanent delete (E7)**: `deleteOriginal()` runs only after `commit()` returned. Probe anything that could delete the original earlier, and the foreign-file and added-file protections.
- **Logs**: conversion failures are reported through `reportGeneric()` (no bindings). Other `report()` calls (mirror sync, registry reconcile) carry opaque hashes and paths only.
- **Backup validation**: `validateArchive()` format-2 branch, `encryptionKeyId()`, `readArchivedKeyFile()`. Hostile manifests are covered by 10 datasets in `EncryptedBackupTest`; look for a combination that is not.
- **Windows**: `Filesystem::moveDirectory` of a vault another program holds open is refused cleanly (`swapFailed`) but only simulated here.
- Old backups keep the old password after a password change (warning in the dialog text and the toast).

### 8.7 Manual desktop checklist (user)
1. Run `php artisan migrate` on the desktop DB (the `vault_encryption` and `backups` migrations are pending; I did not touch the dev DB), then `php artisan route:clear` if routes were ever cached.
2. Packaged-app unlock time: unlock a vault created with the default (MODERATE) cost; expect about 1 s and no out-of-memory error.
3. Win+L, then return: the vault is locked (with "lock on screen lock" on).
4. Ctrl+R reload: every vault is locked again.
5. Explorer on an encrypted vault shows only a key file and 32-character hex names (`.mdenc`), and the folders are hex too.
6. Encrypt an existing vault with a note open in an editor and the vault folder open in Explorer: expect the clean "Close any programs using the vault folder" message and an unchanged vault (no `.mdvault-*` folder left beside it).
7. Encrypt an existing vault normally: notes and folders keep their names in the app; only hex names are on disk; the old plaintext folder is gone (also check the Recycle Bin is not involved: the deletion is permanent).
8. Encrypt a vault containing a `.git` folder or a PDF: the dialog lists what blocks it and nothing changes.
9. Remove encryption: the readable names come back, the key file is gone, and a duplicate-name case shows "Name (2)".
10. Change the password: instant; the vault stays unlocked; after locking, the old password fails.
11. Back up all vaults including an encrypted one while it is locked, then open the ZIP: the encrypted vault has hex names and a key file only. Restore it (after "Reset database" or on another folder): it appears as an encrypted, locked vault and opens with the password.
12. Kill the app during an encrypt of a big vault (Task Manager), then start it again: the vault is intact (either the original or fully converted) and no `.mdvault-*` folder remains beside it after the next start.
13. Search `storage/logs/laravel.log` for a note name and some note text you typed: nothing is found.

## 7C Fix Round 1

Issues fixed (fix attempt 1): 7C-QA-01 to 7C-QA-06.

- **7C-QA-01**: new `App\Support\ConversionLock` handle (owner-checked `release()`, `isHeld()`); `VaultRecoveryService::lock()` returns it. `recoverAll()` force-releases each vault's stale lock before recovering. `VaultConversionService::commit()` takes a `$guard` closure run after the DB writes and right before the first rename (`assertSafeToSwap`): lock still held, else conversion fails and the transaction rolls back with nothing moved. ADR note added. Tests (database cache store): crash-left lock released at boot then repaired; owner-only release; lost lock mid-conversion aborts with no registry/folder mismatch.
- **7C-QA-02**: tests for a commit failure after the swap (swap undone) and for `undoSwap` failing (recovery reports `rolled-back`). Helper `failCommitAfterCallback()` wraps the DB manager so the swapping transaction rolls back and "fails" to commit.
- **7C-QA-03**: backup validation rejects `mdvault-encryption.json` and `.mdenc` entries for non-encrypted vaults (`isEncryptionArtifact`); dataset test with two cases.
- **7C-QA-04**: `throttle:10,1` added to `vaults.encryption.destroy` and `vaults.encryption.password.update`; test confirms 429 with the generic message. No route signature change, so Wayfinder was not regenerated.
- **7C-QA-05**: the same guard re-runs the snapshot compare immediately before the first rename (test: a file written late aborts the conversion). Note writes refusing while the lock is held was not added (would add a `NoteService` dependency; the snapshot check closes the loss window to the guard-to-rename gap).
- **7C-QA-06**: `validateArchive` now explicitly rejects duplicate entry names. Finding: libzip's `CHECKCONS` (already used by `ArchiveService::entries`) already refuses such archives as "not a valid ZIP", so the new check is defence in depth; the test asserts the archive is refused.

Verification: Pint passed; full `php artisan test --compact` passed (see final run); `php vendor/bin/phpstan analyse` 0 errors; `npm run types:check` and `npm run test:js` (156) passed. No build run; dev DB not migrated.
