# Plan: Phase 6: Backup and Restore

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Master Plan Phase**: **Implements Master Plan Phase 6, Backup and Restore (`docs/Masterplan.md` §55). Related sections: §9, §12, §14, §17, §25–§29, §40–§44, §62; Rules 1–5, 9, 10.**
- **Author**: System Analyst
- **Created Date**: 2026-09-30
- **Task Complexity**: Level 4, Architectural (analyst sign-off after QA)
- **Requirements**: `requirements.md`
- **Status**: **COMPLETE**: H1–H11 approved 2026-09-30; implemented as Revision 1; QA PASS (rounds 1–5); analyst APPROVED

---

## 1. Summary
`BackupService` handles backups:
1. Quick-reconciles each vault.
2. Scans every non-ignored file and folder, hashing each one.
3. Writes a ZIP (`manifest.json` + `vaults/<Name>/…`) through a new `ArchiveService` (`ZipArchive`) to a hidden temp sibling.
4. Re-opens and fully verifies it.
5. Renames it into place without overwriting, and records it in a new `backups` table.

A restore takes these steps:
1. Validate the manifest, registry, path safety, entry set, sizes and SHA-256.
2. Stage the selected vaults into a hidden same-volume `.mdvault-restore-*` folder, with per-file verification.
3. Commit all vault and note records (original UUIDs, or new ones for a copy) and the folder renames in one DB transaction, with rename-back compensation.
4. Run a Full reconcile.

The UI adds a Settings → Backup page, a "Back up" button on each vault card, and a restore preview dialog.

---

## 2. Architecture & Design
- **Approach**: see ADRs `backup-archive-format` and `backup-restore-semantics`.
- **Alternatives Considered**:
  - Raw SQLite snapshot in the archive: rejected (absolute paths, whole-install replace, schema coupling, untrusted DB file).
  - Pure-PHP ZIP packages: rejected (no need; ext-zip ships in the NativePHP PHP builds).
  - `extractTo()`: rejected (zip-slip).
  - Extracting in place: rejected (partial imports).
  - Separate Restore and Import flows with an in-place replace: rejected for v1 (destructive).
  - A browser upload: rejected (inconsistent with the app's path-based flows; unnecessary locally).
  - Queue jobs: deferred.
- **Decision Records**:
  - `.ai/decisions/backup-archive-format.md` (new)
  - `.ai/decisions/backup-restore-semantics.md` (new)
  - Amended in T0: `vault-removal-and-rename-semantics.md`, `note-save-atomic-replace.md`, `external-change-reconciliation.md`, `note-registry-and-indexing.md`, `vault-registry-and-consistency.md`, `settings-persistence.md`, `service-layer-architecture.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `backups` | create (H8) | `id`; `uuid` uuid unique; `scope` string(20); `filename` string(255); `path` text; `file_size` unsignedBigInteger; `file_hash` char(64); `format_version` unsignedSmallInteger; `vault_count`, `note_count`, `file_count` unsignedInteger; `contents` json; timestamps; `index(created_at)`. No FK. **No other table or column changes. No `content` column. No `mdvault.sqlite` in the archive.** |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Migration (new) | `database/migrations/2026_09_30_xxxxxx_create_backups_table.php` | `backups` table |
| Model (new) | `app/Models/Backup.php` | `HasUuids` (`uniqueIds = ['uuid']`), hidden `id`, casts (`contents` array, ints, `scope` → `BackupScope`) |
| Factory (new) | `database/factories/BackupFactory.php` | Test data |
| Enum (new) | `app/Enums/BackupScope.php` | `All = 'all'`, `Vault = 'vault'` |
| Enum (new) | `app/Enums/RestoreAction.php` | `Restore = 'restore'`, `Copy = 'copy'`, `Skip = 'skip'` |
| Exception (new) | `app/Exceptions/BackupException.php` | User-facing, with `field()` and `problems()`; static factories (T5–T7) |
| Support (new) | `app/Support/BackupResult.php`, `BackupInspection.php`, `RestoreResult.php` | Immutable result DTOs |
| Service (new) | `app/Services/ArchiveService.php` | The only `ZipArchive` user: `write`, `entries`, `readEntry`, `eachEntryStream` |
| Service (new) | `app/Services/BackupService.php` | `suggestedFilename`, `defaultDestination`, `create`, `recent`, `inspect`, `restore`, validation core |
| Service (modify) | `app/Services/FileStorageService.php` | Temp and staging prefixes, `createFileFromStream`, public `discardTempFile` (prefix allow-list), `discardStaleTempFiles`, `deleteStagingDirectory`, `staleStagingDirectories`, `makeDirectories`, `setModifiedTime`, `freeSpace` |
| Service (modify) | `app/Services/FileHashService.php` | `hashStream` |
| Service (modify) | `app/Services/NativeDialogService.php` | `chooseFile`, `chooseSaveFile` |
| Service (modify) | `app/Services/StoragePathService.php` | `BACKUP_FOLDER_NAME`, `defaultBackupDirectory()` |
| Service (modify) | `app/Services/VaultService.php` | Public `nameAvailable()`, `overlappingVault()` (reuse the private checks) |
| Service (modify) | `app/Services/VaultIndexService.php` | Public `newNoteAttributes()` wrapping `insertAttributes` |
| Requests (new) | `app/Http/Requests/Settings/StoreBackupRequest.php`, `InspectBackupRequest.php`, `RestoreBackupRequest.php` | Validation |
| Controllers (new) | `app/Http/Controllers/Settings/BackupController.php` (`edit`, `store`), `app/Http/Controllers/Settings/BackupRestoreController.php` (`browse`, `inspect`, `store`) | Thin HTTP |
| Routes (modify) | `routes/settings.php` | Five routes (below) |
| composer (modify, H1) | `composer.json` | `"ext-zip": "*"` in `require` (platform requirement only) |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Page (new) | `resources/js/pages/settings/Backup.vue` | Back up all; restore by dialog or path; recent backups |
| Component (new) | `resources/js/components/backups/RestoreBackupDialog.vue` | Inspect → preview → per-vault action → restore |
| Component (new) | `resources/js/components/backups/BackupVaultButton.vue` | Per-vault "Back up" |
| Layout (modify) | `resources/js/layouts/settings/Layout.vue` | "Backup" nav item (`Archive` icon) |
| Page (modify) | `resources/js/pages/vaults/Index.vue` | Add `BackupVaultButton` to each card |
| Types (new) | `resources/js/types/backups.ts` (exported via `@/types`) | `BackupRecord`, `BackupInspection`, `BackupInspectionVault`, `RestoreAction` |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| GET | `/settings/backup` | `settings.backup.edit` | `Settings\BackupController@edit` | web |
| POST | `/settings/backup` | `settings.backup.store` | `Settings\BackupController@store` | web (CSRF) |
| POST | `/settings/backup/restore/browse` | `settings.backup.restore.browse` | `Settings\BackupRestoreController@browse` | web (CSRF); 404 outside the desktop |
| POST | `/settings/backup/restore/inspect` | `settings.backup.restore.inspect` | `Settings\BackupRestoreController@inspect` | web (CSRF), JSON |
| POST | `/settings/backup/restore` | `settings.backup.restore.store` | `Settings\BackupRestoreController@store` | web (CSRF) |

---

## 3. Implementation Tasks
Rules for every task:
- Read both new ADRs first.
- After each task, run `php vendor/bin/pint --dirty --format agent` and that task's tests.
- After route changes, run `php artisan wayfinder:generate --with-form --no-interaction`.
- Test harness: a temp dir, `fakeDocumentsDirectory($tmp)`, `VaultService::create('Work')`, `writeVaultFiles`, and cleanup in `afterEach` (`File::deleteDirectory`).
- Activate the skills `laravel-best-practices`, `testing-best-practices`, `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` where relevant.
- **No AI attribution anywhere.** Never run `migrate:fresh` against the desktop DB (`php artisan native:migrate` applies the new migration). Relative imports only inside `resources/js/lib/**`.
- Every user-facing message uses the curly quotes “ ” like the existing exceptions, and contains no stack traces.

- [x] **T0: Preconditions (orchestrator/user)**
  - Record the H1–H11 answers in §6. If H2, H5, H6 or H9 deviate from the recommendation, stop and ask the analyst for a revision.
  - Save both ADRs. Work on the branch `phase-6-backup-restore` (exists).
  - H1: add `"ext-zip": "*"` to `composer.json` `require` and run `composer validate`. No `composer update` is needed.
  - Append these amendments:
    - `vault-removal-and-rename-semantics.md`, Decision → Remove, after "MDVault never permanently deletes…": "Amended in Phase 6 (ADR `backup-restore-semantics`, H9): the only exception is `FileStorageService::deleteStagingDirectory()`, which removes MDVault's own `.mdvault-restore-*` staging folders (copies extracted from a backup archive that still exists)." In Follow-ups, change "Phase 6 backups may offer 'back up before removing'" to "Deferred beyond Phase 6: 'back up before removing'."
    - `note-save-atomic-replace.md` → Follow-ups: replace the Phase 6 bullet with "Phase 6 (delivered): backups skip every dot-prefixed entry, including `.mdvault-*` temp files."
    - `external-change-reconciliation.md` → Follow-ups: replace the Phase 6 bullet with "Phase 6 (delivered): manifests carry uuid, relative_path, file_hash and file_size but never file_mtime; restored notes start with `file_mtime` null."
    - `note-registry-and-indexing.md` → Follow-ups: replace the Phase 6 bullet with "Phase 6 (delivered, ADRs `backup-archive-format`, `backup-restore-semantics`): backups export uuid + relative_path + file_hash; restore re-creates records with their original UUIDs (or new ones for a copy), then runs a Full reconcile."
    - `vault-registry-and-consistency.md` → Follow-ups: "Phase 6: restore inserts vaults with their original UUID (or a new one for a copy) under the current storage root; `relative_path` = the restored folder name."
    - `settings-persistence.md` → Follow-ups: replace "Phase 6 adds `backup.*`" with "Phase 6 adds no `backup.*` keys (H3, H7); settings are not included in backup format 1."
    - `service-layer-architecture.md` → Follow-ups: "Phase 6: `ZipArchive` only in `ArchiveService`; `BackupService` uses no raw filesystem functions."
  - Covers: FR-16

- [x] **T1: `backups` table and model (H8)**
  - Commands:
    - `php artisan make:migration create_backups_table --no-interaction`
    - `php artisan make:model Backup --factory --no-interaction`
    - `php artisan make:enum Enums/BackupScope --no-interaction`
    - `php artisan make:enum Enums/RestoreAction --no-interaction`
  - Migration: exactly the columns in §2. `down()` drops the table.
  - `Backup` model:
    - `HasFactory, HasUuids`; `uniqueIds(): ['uuid']`;
    - `$fillable` = every column except `id`, `uuid` and the timestamps;
    - `$hidden = ['id']`;
    - `casts()`: `scope` → `BackupScope::class`, `contents` → `array`, the ints → `integer`;
    - a PHPDoc `@property` block (as in `Vault`).

    The factory produces a valid row (`scope` all, `contents` with 1 vault).
  - `tests/Feature/DatabaseSchemaTest.php`:
    - add `'backups'` to the "keeps only…" dataset, and a column test for `backups`;
    - change "later-phase tables do not exist yet" to `->with(['vault_encryption'])` only.
  - Covers: FR-07

- [x] **T2: Filesystem, hash, dialog and path primitives**
  - **`FileStorageService`** (keep `final`; every method documents that it never overwrites or follows links):
    ```php
    public const BACKUP_TEMP_PREFIX = '.mdvault-backup-';
    public const RESTORE_STAGING_PREFIX = '.mdvault-restore-';

    /** Exclusive-create $path ('x') and copy at most $maxBytes from $stream in 1 MiB chunks.
     *  Returns bytes written; null on any failure, including the stream yielding more than $maxBytes.
     *  On failure the file this call created is removed. Never overwrites. */
    public function createFileFromStream(string $path, mixed $stream, int $maxBytes): ?int;

    /** Now PUBLIC (was private). Removes $path only when it is a regular non-symlink file whose
     *  basename starts with SAVE_TEMP_PREFIX or BACKUP_TEMP_PREFIX. */
    public function discardTempFile(string $path): bool;

    /** Best-effort: discards $prefix temp files (allow-listed prefixes only) directly in $directory
     *  whose mtime is older than now - $minAgeSeconds. Returns the count. */
    public function discardStaleTempFiles(string $directory, string $prefix, int $minAgeSeconds): int;

    /** The ONLY recursive delete (ADR backup-restore-semantics, H9). Accepts only a non-symlink
     *  directory whose basename starts with RESTORE_STAGING_PREFIX; uses Filesystem::deleteDirectory
     *  (does not follow links); checks afterwards. */
    public function deleteStagingDirectory(string $path): bool;

    /** @return list<string> absolute paths of RESTORE_STAGING_PREFIX directories directly in $root
     *  older than now - $minAgeSeconds */
    public function staleStagingDirectories(string $root, int $minAgeSeconds): array;

    /** Recursive mkdir (used only inside a staging folder). True if it is a directory afterwards. */
    public function makeDirectories(string $path): bool;

    /** Best-effort touch($path, $mtime); false on failure. */
    public function setModifiedTime(string $path, int $mtime): bool;

    /** disk_free_space($directory) as int, or null when unknown. */
    public function freeSpace(string $directory): ?int;
    ```
    `replaceFile` keeps calling `discardTempFile` (behaviour unchanged for `.mdvault-save-`).
  - **`FileHashService`**:
    ```php
    /** Streamed SHA-256 of $stream (hash_init/hash_update), reading at most $maxBytes + 1 bytes.
     *  @return array{hash: string, bytes: int}|null null when a read fails or more than $maxBytes bytes are available */
    public function hashStream(mixed $stream, int $maxBytes): ?array;
    ```
  - **`NativeDialogService`**, following the pattern of `chooseDirectory` (`Dialog::new()` per call; null when unavailable, cancelled or empty):
    ```php
    /** @param list<string> $extensions */
    public function chooseFile(string $title, string $filterName, array $extensions, ?string $defaultPath = null): ?string;
    // ->files()->filter($filterName, $extensions)->button('Open')->open()

    /** @param list<string> $extensions */
    public function chooseSaveFile(string $title, string $defaultPath, string $filterName, array $extensions): ?string;
    // ->button('Save')->defaultPath($defaultPath)->filter(...)->properties(['createDirectory','showOverwriteConfirmation'])->save()
    ```
  - **`StoragePathService`**: `public const BACKUP_FOLDER_NAME = 'MDVault Backups';` and `public function defaultBackupDirectory(): string`, which returns `rtrim(documentsPath() ?? storage_path('app'), '\\/').DIRECTORY_SEPARATOR.self::BACKUP_FOLDER_NAME`. It never creates anything.
  - **`VaultService`**:
    - `public function nameAvailable(string $name): bool` (case-insensitive; wraps the `assertNameAvailable` logic);
    - `public function overlappingVault(string $path): ?Vault` (the first registered vault whose path is the same as, inside, or containing `$path`).

    Refactor the private asserts to use them. Behaviour is unchanged.
  - **`VaultIndexService`**: `public function newNoteAttributes(string $relativePath, int $size, string $hash): array` = `insertAttributes($relativePath, ['size' => $size, 'hash' => $hash, 'mtime' => null])`.
  - Tests:
    - `tests/Feature/Services/FileStorageServiceTest.php` (extend):
      - `createFileFromStream`: writes exact bytes; the cap is exceeded → null and the file is gone; an existing target → null and the target is unchanged;
      - `discardTempFile`: refuses a user file (`note.md`), accepts both prefixes;
      - `discardStaleTempFiles`: removes only old prefixed files;
      - `deleteStagingDirectory`: deletes a nested prefixed folder, refuses `Work/` and a prefixed **file**; a symlinked staging folder is not followed (`->skipOnWindows()`);
      - `staleStagingDirectories`: age filter;
      - `setModifiedTime`; `freeSpace` returns an int for the temp dir.
    - `tests/Feature/Services/FileHashServiceTest.php` (create if missing): `hashStream` equals `hash('sha256', …)`; over the cap → null.
    - `tests/Feature/Services/NativeDialogServiceTest.php` (extend):
      - `Http::fake(['*dialog/save' => Http::response(['result' => 'C:\\b\\x.zip'])])` → the path; an empty result → null; unavailable → null with no request;
      - `chooseFile` with `*dialog/open` → the first path; the payload contains the filter `zip` (`Http::assertSent`).
    - `tests/Feature/Services/StoragePathServiceTest.php` (extend): `defaultBackupDirectory` under the fake Documents; with a null Documents → under `storage_path('app')`; nothing created.
    - `tests/Feature/Services/VaultServiceTest.php` (or the existing vault test file): `nameAvailable` (case-insensitive), `overlappingVault` (same, inside, containing, unrelated → null).
  - Covers: FR-05, FR-06, FR-09, FR-12

- [x] **T3: `ArchiveService` (the ZIP boundary)**
  - Command: `php artisan make:class Services/ArchiveService --no-interaction` (`final`; no constructor dependencies).
  - API:
    ```php
    /** Creates a NEW zip at $path (ZipArchive::CREATE | ZipArchive::EXCL). Entries are added in order:
     *  $strings (e.g. manifest.json first), then directory entries (names ending '/'), then files via addFile.
     *  @param array<string, string> $strings entry name => contents
     *  @param list<string> $directories entry names ending in '/'
     *  @param list<array{name: string, source: string}> $files
     *  @throws BackupException::archiveWriteFailed on open/add/close failure (the caller discards the temp file) */
    public function write(string $path, array $strings, array $directories, array $files): void;

    /** Opens read-only (RDONLY | CHECKCONS).
     *  @return list<array{name: string, size: int, compressed_size: int, is_directory: bool, is_symlink: bool, is_encrypted: bool}>
     *  @throws BackupException::notAnArchive when it can't be opened or read */
    public function entries(string $path): array;

    /** The whole entry as a string when it exists and its size is <= $maxBytes; otherwise null. */
    public function readEntry(string $path, string $name, int $maxBytes): ?string;

    /** Opens once; for each name in order, gets a read stream (getStream) and calls $consumer($name, $stream),
     *  then closes the stream. A missing entry calls $consumer($name, null).
     *  @param list<string> $names @param callable(string, mixed): void $consumer
     *  @throws BackupException::notAnArchive */
    public function eachEntryStream(string $path, array $names, callable $consumer): void;
    ```
    - Symlink detection: `getExternalAttributesIndex($i, $opsys, $attr)`, and `$opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000`.
    - Encrypted: `statIndex($i)['encryption_method'] !== ZipArchive::EM_NONE`.
    - Directory: the name ends with `/`.
    - Always close the archive in `finally`.
  - Arch: `arch('zip archives only via ArchiveService')->expect('ZipArchive')->toOnlyBeUsedIn('App\Services\ArchiveService');`. If Pest scans test helpers, add `->ignoring('Tests')` and justify it in `implementation.md`.
  - Tests in `tests/Feature/Services/ArchiveServiceTest.php`:
    1. Round trip: strings, an empty directory, and files with **non-ASCII names** (`Café/Ünïcode 日本.md`); `entries()` lists them with sizes; `eachEntryStream` bytes equal the sources. **This must pass on Windows** (risk R2).
    2. `write` to an existing path → exception; the existing file is unchanged.
    3. A non-ZIP file → `entries` throws.
    4. A symlink entry (crafted with `setExternalAttributesName(..., OPSYS_UNIX, 0120777 << 16)`) → `is_symlink` true.
    5. An encrypted entry (`setEncryptionName` with `EM_AES_256` and a password, `->skip()` if unsupported) → `is_encrypted` true.
    6. `readEntry` over the cap → null.

    Add the helper `makeZip(string $path, array $entries): void` (entries `name => string|null`, where null means a directory entry) to `tests/Pest.php` for crafting hostile archives in T6/T7.
  - Covers: FR-03, FR-09

- [x] **T4: `BackupException` and DTOs**
  - `app/Exceptions/BackupException.php` (`final`, private constructor `(string $message, string $field, array $problems = [])`, `field()`, `problems(): list<string>`). Factories, each with a user-facing message:
    - destination: `invalidDestination()`, `invalidDestinationName()`, `destinationFolderUnavailable($dir)`, `destinationExists($path)` ("… MDVault never overwrites files; choose another name."), `destinationInsideVault($name)`;
    - vaults: `nothingToBackUp()`, `vaultMissing($name)`, `encryptedNotSupported($name)`, `vaultBusy($name)`, `vaultChanged($name)`, `unreadable($vaultName, list $paths)`, `unsupportedName($vaultName, $path)`;
    - writing: `archiveWriteFailed($dir)`, `changedDuringBackup()`;
    - archive path: `archiveNotFound($path)`, `notZipFile($path)`, `notAnArchive()`;
    - restore: `invalidBackup(list $problems)` (message = the first problem + "(and N more)", `problems` = all ≤ 20), `storageUnavailable($reason)`, `unknownVault()`, `nothingSelected()`, `vaultAlreadyRegistered($name)`, `noFreeName($name)`, `notEnoughSpace($need, $free)`, `extractFailed($entry)`, `damaged($entry)`, `moveFailed($target)`, `restoreFailed()` ("Nothing was restored."), `restoreRollbackFailed(list $folders)` (names the folders left unregistered).

    Field: `destination` for destination and backup errors, `path` for archive and restore errors, `vaults` for selection errors.
  - `app/Support/BackupResult.php` (`final readonly`): `string $path, string $filename, int $size, string $hash, int $vaultCount, int $noteCount, int $fileCount, list<string> $skippedVaults, bool $recorded`.
  - `app/Support/BackupInspection.php` (`final readonly`): `bool $valid`, `list<string> $problems`, `?array $backup`, `list<array{...}> $vaults` (the shapes from FR-08), plus `toArray()` for JSON.
  - `app/Support/RestoreResult.php` (`final readonly`): `list<array{uuid: string, name: string, path: string, notes: int, copy: bool}> $restored`, `list<string> $skipped`, `list<string> $warnings`, plus `summary(): string` (e.g. "Restored 2 vaults (127 notes)." plus the warnings).
  - Covers: FR-05 to FR-12

- [x] **T5: `BackupService::create` (FR-01 to FR-07)**
  - Command: `php artisan make:class Services/BackupService --no-interaction` (`final`).
  - Constructor: `VaultService $vaults, VaultIndexService $index, FileStorageService $files, FileHashService $hashes, ArchiveService $archives, StoragePathService $paths, DatabaseManager $database, Repository $config`.
  - Constants:
    - `MANIFEST_NAME = 'manifest.json'`
    - `VAULTS_PREFIX = 'vaults/'`
    - `FORMAT = 'mdvault-backup'`
    - `FORMAT_VERSION = 1`
    - `DATABASE_VERSION = 1`
    - `MANIFEST_MAX_BYTES = 16 * 1024 * 1024`
    - `MAX_ENTRIES = 100_000`
    - `SPACE_MARGIN_BYTES = 64 * 1024 * 1024`
    - `STALE_TEMP_SECONDS = 3600`
    - `NAME_SUFFIX_LIMIT = 20`
    - `MAX_PROBLEMS = 20`
  - Public API:
    ```php
    public function suggestedFilename(?Vault $vault): string;       // MDVault-Backup[-<name>]-Y-m-d-His.zip
    /** Default folder + suggested name. With $create, creates the default folder (non-recursive) when missing. @throws BackupException */
    public function defaultDestination(?Vault $vault, bool $create): string;
    /** $vault null = all vaults. @throws BackupException */
    public function create(?Vault $vault, string $destination): BackupResult;
    /** @return list<array{uuid: string, scope: string, filename: string, path: string, file_size: int, vault_count: int, note_count: int, file_count: int, vaults: list<string>, created_at: string, exists: bool}> newest first, max $limit */
    public function recent(int $limit = 20): array;
    ```
  - `create()` algorithm (ADR `backup-archive-format`, "Write procedure"):
    1. `@set_time_limit(0)`.
    2. `$target = $this->assertDestination($destination)`:
       - `paths->normalize` (catch → `invalidDestination`) and `isAbsolute`;
       - append `.zip` unless it ends with `.zip` (case-insensitive);
       - basename: `paths->assertValidFolderName` (catch → `invalidDestinationName`) and must not start with `.`;
       - `$dir = dirname($target)` must satisfy `files->isDirectory` && `files->isWritableDirectory`;
       - `files->exists($target)` → `destinationExists`;
       - `vaults->overlappingVault($target)` (only the "target inside vault" direction matters; accept the helper's result) → `destinationInsideVault`.
    3. Vault set:
       - a single vault: `refreshStatus` → Missing → `vaultMissing`; `is_encrypted` → `encryptedNotSupported`;
       - all: `vaults->all()`, skipping Missing and encrypted ones (their names go into `$skipped`); empty → `nothingToBackUp`.

       Sort by lower-cased name.
    4. For each vault:
       1. `index->reconcile($vault, IndexMode::Quick)`. A `NoteOperationException` means: single → `vaultMissing`; all → skip and add to `$skipped`. Still stale after the internal retry → `vaultBusy`.
       2. `files->scan($vault->path, fn ($rel, $name, $isDir) => ! $index->isIgnoredName($name, $isDir))`. A non-empty `unreadable` → `unreadable($name, $scan['unreadable'])`.
       3. `$rows = $vault->notes()->get()->keyBy('relative_path')`.
       4. For each file:
          - `! mb_check_encoding($path, 'UTF-8')` → `unsupportedName`;
          - `$abs = files->joinRelative`; `$hash = hashes->hashFile($abs)` and `$size = files->size($abs)`; either null → `unreadable`;
          - `index->isIndexableFileName(basename)` → the note entry with `uuid = $rows[$path]->uuid` (a missing row → `vaultChanged`), `created_at`/`updated_at` as ISO-8601 UTC (`toIso8601ZuluString()`), `modified_at = $file['mtime']`;
          - otherwise a file entry.
       5. `directories = $scan['directories']`.
       6. Add the entries `vaults/<name>/`, `vaults/<name>/<dir>/`, and `['name' => "vaults/<name>/<rel>", 'source' => $abs]`.
    5. If every vault was skipped → `nothingToBackUp`.
    6. Build the manifest array exactly as in the ADR:
       - `app_version` = `(string) config('nativephp.version')`;
       - `created_at` = now (UTC, Zulu);
       - `scope` = `all` | `vault`.

       Encode it with `JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
    7. `files->discardStaleTempFiles($dir, BACKUP_TEMP_PREFIX, STALE_TEMP_SECONDS)`.
    8. `$tmp = $dir.DIRECTORY_SEPARATOR.BACKUP_TEMP_PREFIX.Str::random(12).'.zip'`. `try { archives->write($tmp, [MANIFEST_NAME => $json], $dirs, $files); } catch (Throwable) { files->discardTempFile($tmp); throw archiveWriteFailed($dir); }`
    9. `[$manifest, $problems] = $this->validateArchive($tmp, verifyHashes: true)`. Any problem → `discardTempFile`, then `changedDuringBackup`. `report()` the problems first (paths only).
    10. `$zipHash = hashes->hashFile($tmp)`, `$zipSize = files->size($tmp)`.
    11. `! files->renameFile($tmp, $target)` → `discardTempFile($tmp)`; then `files->exists($target) ? destinationExists : archiveWriteFailed`.
    12. `try { Backup::query()->create([...]); $recorded = true; } catch (Throwable $e) { report($e); $recorded = false; }`
    13. Return a `BackupResult`.
  - `defaultDestination($vault, $create)`:
    - `$dir = paths->defaultBackupDirectory()`;
    - if `$create` and it's missing: `files->makeDirectory($dir)` (non-recursive), failing → `destinationFolderUnavailable($dir)`;
    - if `! $create` and it's missing: return `dirname($dir).DIRECTORY_SEPARATOR.suggestedFilename($vault)`, so the dialog opens in Documents;
    - return `$dir.DIRECTORY_SEPARATOR.suggestedFilename($vault)`.
  - `recent()`: `Backup::query()->latest()->limit($limit)->get()` mapped to arrays (never `id`), with `exists = files->isFile(path)` and `vaults` = the names from `contents`.
  - Arch: add `App\Services\BackupService` and `App\Services\ArchiveService` to the targets of "index and note services use no raw filesystem functions". `ArchiveService` is exempt only from `ZipArchive`; it must not use `fopen` or `unlink` either. Add `arch('backup records are only used by services, controllers, models and factories')->expect('App\Models\Backup')->toOnlyBeUsedIn([...same list as Vault...])`.
  - Tests in `tests/Feature/Backups/BackupCreateTest.php` (`php artisan make:test Backups/BackupCreateTest --pest --no-interaction`). Fixture:
    - vault Work with `Projects/HRMIS.md` (CRLF + BOM), `Café/Ünïcode.md`, `Root.md` (empty), `assets/logo.png` (binary bytes), `Empty/`, `.git/HEAD`, `.obsidian/app.json`, `node_modules/x.md`, `.mdvault-save-abc`;
    - vault Personal with `Ideas.md`.

    Tests:
    1. All: the ZIP entries are exactly `manifest.json`, the expected directory entries and the expected files; no ignored entries.
    2. The manifest keys and values: counts and sums; `scope`; `format_version` 1; `database_version` 1; `hash_algorithm`; each note UUID equals the DB row; `file_hash` equals `hash_file`; `archive_path`; `directories` includes `Empty`; the manifest JSON does **not** contain either vault's absolute path.
    3. The bytes of every entry equal the source (CRLF/BOM, binary).
    4. A `.md` file created externally before the backup (no reconcile) → registered and in the manifest with its new UUID.
    5. A single vault → only that vault, `scope: vault`, and the file name contains the vault name.
    6. All with Personal missing (folder deleted) → only Work, `skippedVaults = ['Personal']`. A single missing vault → `vaultMissing`, and no file.
    7. Destination rules: relative path; an existing file (unchanged afterwards); inside a vault; a missing folder; `.zip` appended; a name starting with `.`.
    8. Publish failure: `failFileMoves([1])` (resolve services afterwards) → exception, no target, no `.mdvault-backup-*` left, no `backups` row.
    9. Record failure: `Backup::creating(fn () => throw new RuntimeException)` → the result has `recorded` false and the file exists.
    10. A record is written with the correct counts, `file_hash` equals `hash_file(zip)`, and `recent()` lists it with `exists` true. After deleting the file, `exists` is false.
    11. Stale temp cleanup: a `.mdvault-backup-old.zip` touched 2 h ago is removed; a fresh one is kept.
    12. An unreadable file (`chmod 000`, `->skipOnWindows()`) → `unreadable`, and no file.
  - Covers: FR-01 to FR-07

- [x] **T6: Validation core and `BackupService::inspect` (FR-08, FR-09)**
  - Private core: `validateArchive(string $archive, bool $verifyHashes): array{0: ?array, 1: list<string>}` (the parsed manifest, or null, plus the problems), implementing ADR `backup-restore-semantics` stages 2–8. Rules:
    - Stop collecting after `MAX_PROBLEMS`.
    - Skip stage 8 when earlier stages found problems.
    - The path rules live in a private `relativePathProblem(string $path, bool $isFileEntry): ?string` with the exact rules from ADR stage 6, where "on Windows" means `PHP_OS_FAMILY === 'Windows'`, and case-insensitive duplicate checks apply when `PHP_OS_FAMILY` is `Windows` or `Darwin`.
    - Vault names use `paths->assertValidFolderName`.
    - The problem messages are specific. Examples:
      - "“manifest.json” is missing, so this isn't an MDVault backup."
      - "This backup was made by a newer version of MDVault (format 2)."
      - "“vaults/Work/Projects/HRMIS.md” is damaged (its checksum doesn't match)."
      - "“vaults/Work/../x.md” has an unsafe path."
      - "The backup lists 3 notes but contains 2."
  - Public:
    ```php
    /** @throws BackupException archiveNotFound|notZipFile (unusable path only) */
    public function inspect(string $path): BackupInspection;
    ```
    1. `@set_time_limit(0)`.
    2. `$archive = assertArchivePath($path)`: normalize and absolute, `files->isFile` and not a symlink, ends in `.zip` (case-insensitive).
    3. `validateArchive($archive, true)`.
    4. If valid, build the `backup` info (plus `archive_size`) and per vault:
       - `state` = `Vault::where('uuid')->exists()` ? `exists` : `new`;
       - `restore_name` (the predicted name via `resolveName`, or null when `exists`);
       - `copy_name`;
       - `default_action` (`restore` for new, `skip` for exists);
       - the counts and bytes.
  - `resolveName(string $base, string $root, array $claimed): ?string` (private, shared with T7):
    - candidates: `$base`, then `"{$b} (restored)"`, then `"{$b} (restored {$n})"` for n = 2..20, where `$b = rtrim(mb_substr($base, 0, 100 - mb_strlen($suffix)), ' .')`;
    - the first candidate that passes `assertValidFolderName`, `vaults->nameAvailable`, `! in_array(mb_strtolower($c), $claimed)`, `! files->exists("$root/$c")` and `vaults->overlappingVault("$root/$c") === null`;
    - otherwise null.

    Inspect uses `paths->rootPath()` for prediction only, and never creates it.
  - Tests in `tests/Feature/Backups/BackupInspectTest.php`. Build valid backups through `create()`, and hostile ones with `makeZip`:
    1. Valid → `valid` true; vault states `new` (after deleting the rows) versus `exists`; `restore_name` `Work`, or `Work (restored)` when a folder `Work` exists in the root.
    2. Invalid → `valid` false with a matching problem, one dataset row each:
       - not a ZIP (a `.zip` text file);
       - no manifest;
       - invalid JSON;
       - manifest > 16 MiB;
       - `application` wrong;
       - `format` wrong;
       - `format_version` 2 ("newer");
       - `database_version` 2;
       - `hash_algorithm` md5;
       - `is_encrypted` true;
       - count mismatch;
       - duplicate vault UUID;
       - duplicate note UUID;
       - invalid UUID;
       - bad hash format;
       - an invalid vault name (`CON`);
       - `archive_path` mismatch;
       - a note path not ending `.md`;
       - an undeclared parent directory;
       - an entry `vaults/Work/../../evil.md`;
       - `/abs.md`;
       - `C:/x.md`;
       - a backslash;
       - a `.hidden/x.md` segment;
       - an extra entry;
       - a missing entry;
       - a size mismatch;
       - a hash mismatch (same size, different bytes);
       - a symlink entry;
       - `A.md` + `a.md` (`->skip()` unless Windows or Darwin).
    3. `inspect` on a missing path, and on a `.txt` → `BackupException` with field `path`.
    4. Inspect writes nothing: the storage root's directory listing is unchanged, and no rows are added.
  - Covers: FR-08, FR-09

- [x] **T7: `BackupService::restore` (FR-10 to FR-13)**
  - Public:
    ```php
    /** @param array<string, RestoreAction> $actions keyed by the vault uuid in the backup; missing = Skip
     *  @throws BackupException */
    public function restore(string $path, array $actions): RestoreResult;
    ```
  - Algorithm (ADR `backup-restore-semantics`, "Restore procedure"):
    1. `@set_time_limit(0)`.
    2. `assertArchivePath`.
    3. `validateArchive($archive, verifyHashes: false)`: problems → `invalidBackup($problems)`.
    4. `$root = paths->ensureRootReady()` (catch `InvalidStorageRootException` → `storageUnavailable`).
    5. Best-effort: for each `files->staleStagingDirectories($root, STALE_TEMP_SECONDS)`, call `deleteStagingDirectory`.
    6. Plan. An action key that isn't a vault UUID in the manifest → `unknownVault`. For each manifest vault with action ≠ Skip:
       - `Restore` and the UUID is registered → `vaultAlreadyRegistered`;
       - vault UUID = the manifest UUID (Restore) or `(string) Str::uuid7()` (Copy);
       - name = `resolveName(...)` (null → `noFreeName`), and push it onto `$claimed`;
       - note UUIDs: Restore → the manifest UUID, unless `Note::where('uuid')->exists()` (then a new UUIDv7, counted in `$reidentified`); Copy → a new UUIDv7 each.

       Nothing planned → `nothingSelected`.
    7. Space: `$need = Σ total bytes`; `$free = files->freeSpace($root)`; `$free !== null && $free < $need + SPACE_MARGIN_BYTES` → `notEnoughSpace`.
    8. Stage:
       - `$staging = $root.DIRECTORY_SEPARATOR.RESTORE_STAGING_PREFIX.Str::random(12)`; `files->makeDirectory($staging)` (failure → `extractFailed('')`);
       - `try` for each planned vault `i`:
         - `makeDirectory("$staging/v$i")`;
         - for each directory in `strcmp` order, `makeDirectories(joinRelative(...))`;
         - `archives->eachEntryStream($archive, $entryNames, $consumer)`, where the consumer does:
           - a null stream → `damaged`;
           - `$abs = files->joinRelative("$staging/v$i", $relative)`;
           - `createFileFromStream($abs, $stream, $size) !== $size` → `damaged`;
           - `hashes->hashFile($abs) !== $hash` → `damaged`;
           - `modified_at !== null` → `files->setModifiedTime` (best-effort).
         - Assert that the consumer saw every entry.
       - `catch (Throwable $e)`: `files->deleteStagingDirectory($staging)`; rethrow a `BackupException` as-is; otherwise `report($e)` and throw `extractFailed`.
    9. Commit:
       ```php
       $moved = [];
       try {
           $created = $this->database->connection()->transaction(function () use (...): array {
               // per planned vault i, in order:
               //   $vault = new Vault(); $vault->forceFill(['uuid','name','description','path' => $target,'relative_path' => $name,
               //       'is_encrypted' => false,'status' => VaultStatus::Active,'created_at','updated_at'])->save();
               //   foreach (array_chunk($noteRows, 500) as $chunk) Note::query()->insert($chunk);
               //     each row = index->newNoteAttributes($rel, $size, $hash) + ['uuid','vault_id' => $vault->id,'created_at','updated_at'
               //     (manifest values; null → now, formatted 'Y-m-d H:i:s')]
               //   if (! $this->moveWithRetry("$staging/v$i", $target)) throw BackupException::moveFailed($target);
               //   $moved[] = ["$staging/v$i", $target];
               //   $vault->update(['path' => files->canonical($target)]);
           });
       } catch (Throwable $e) {
           $left = [];
           foreach (array_reverse($moved) as [$from, $to]) { if (! $this->files->renameDirectory($to, $from)) { $left[] = $to; } }
           $this->files->deleteStagingDirectory($staging);
           if (! $e instanceof BackupException) { report($e); }
           throw $left !== [] ? BackupException::restoreRollbackFailed($left) : ($e instanceof BackupException ? $e : BackupException::restoreFailed());
       }
       ```
       `moveWithRetry`: up to 3 `files->renameDirectory()` attempts, 200 ms apart (`usleep(200_000)`).
    10. `files->deleteEmptyDirectory($staging) || files->deleteStagingDirectory($staging)`.
    11. Verify: for each created vault, `try { $r = index->reconcile($vault, IndexMode::Full); if ($r->hasChanges()) $warnings[] = "“{$vault->name}” had differences after restoring and was re-indexed."; } catch (NoteOperationException $e) { $warnings[] = $e->getMessage(); }`. Add `$reidentified > 0` → "N note(s) got new identities because they already exist in another vault."
    12. `if ($this->vaults->current() === null) { $this->vaults->open($created[0]); }`
    13. Return a `RestoreResult`, with the Skip vaults' names in `skipped`.
  - Tests in `tests/Feature/Backups/BackupRestoreTest.php`. Fixture: create the T5 fixture, back it up, then simulate a fresh state (see T9's helper):
    1. A fresh restore of all → vault UUIDs, names, descriptions and `created_at` equal the originals; note UUIDs, relative paths, hashes and sizes equal; the file bytes equal; `Empty/` exists; no `.mdvault-restore-*` remains; Full reconcile reports no changes; `file_mtime` is null; the file mtimes are restored (±2 s); the current vault is the first restored.
    2. `restore` for a registered UUID → `vaultAlreadyRegistered`, nothing changed (the row count and root listing are equal before and after).
    3. `copy` of a registered vault → new vault and note UUIDs (all different from the originals), name `Work (restored)`, the original vault untouched; a second copy → `Work (restored 2)`.
    4. An unregistered folder `Work` in the root → restored as `Work (restored)`; the old folder's files are untouched.
    5. A note UUID collision (one note UUID pre-inserted in another vault) → that note gets a new UUID, and there's a warning.
    6. Skip everything → `nothingSelected`; an unknown UUID key → `unknownVault`.
    7. An invalid archive (tampered hash, crafted with `makeZip` from a valid manifest) → the restore fails **during staging** with `damaged`; no rows; no target folders; no staging folder.
    8. Zip-slip archive → `invalidBackup`; nothing written anywhere (check the parent of the root too).
    9. DB failure on the second vault (`Vault::creating` listener throwing on the 2nd call) → no vault or note rows, no target folders, no staging folder.
    10. Rename failure: `failFolderRenames([2])`, resolving `BackupService` afterwards → the first vault's folder is moved back, nothing is registered, and no folders or staging remain.
    11. Rename-back failure: `failFolderRenames([2, 3])` → `restoreRollbackFailed`, whose message names the leftover folder; no rows.
    12. Stale staging cleanup: a `.mdvault-restore-old/` with files, touched 2 h ago, is removed by the next restore; a fresh one is kept.
    13. `defaultDestination`, and restoring into a storage root that doesn't exist yet → created by `ensureRootReady`.
  - Covers: FR-10 to FR-13

- [x] **T8: HTTP layer (FR-06 to FR-08, FR-10, FR-15)**
  - Commands:
    - `php artisan make:controller Settings/BackupController --no-interaction`
    - `php artisan make:controller Settings/BackupRestoreController --no-interaction`
    - `php artisan make:request Settings/StoreBackupRequest --no-interaction`
    - `php artisan make:request Settings/InspectBackupRequest --no-interaction`
    - `php artisan make:request Settings/RestoreBackupRequest --no-interaction`
  - Requests (`authorize()` returns true):
    - `StoreBackupRequest`: `vault` → `['nullable', 'uuid', Rule::exists('vaults', 'uuid')]`.
    - `InspectBackupRequest`: `path` → `['required', 'string', 'max:4096']`.
    - `RestoreBackupRequest`:
      - `path` → `['required', 'string', 'max:4096']`;
      - `vaults` → `['required', 'array', 'min:1', 'max:1000']`;
      - `vaults.*.uuid` → `['required', 'uuid', 'distinct']`;
      - `vaults.*.action` → `['required', Rule::enum(RestoreAction::class)]`;
      - `after()`: every action is `skip` → an error on `vaults`: "Choose at least one vault to restore."
      - Accessor `actions(): array<string, RestoreAction>`.
  - `BackupController`:
    - `edit(BackupService $backups, StoragePathService $paths, NativeDialogService $dialogs)` renders `settings/Backup` with:
      - `backups` = `recent()`;
      - `defaultDirectory` = `paths->defaultBackupDirectory()`;
      - `canBrowse` = `dialogs->isAvailable()`.
    - `store(StoreBackupRequest $request, BackupService $backups, NativeDialogService $dialogs)`:
      1. `$vault` = null, or `Vault::where('uuid', …)->firstOrFail()`.
      2. If `$dialogs->isAvailable()`: `$dest = $dialogs->chooseSaveFile('Save MDVault backup', $backups->defaultDestination($vault, false), 'MDVault backup', ['zip'])`; null → `back()`. Otherwise `$dest = $backups->defaultDestination($vault, true)` (a `BackupException` → error toast and back).
      3. `try { $r = $backups->create($vault, $dest); } catch (BackupException $e) { Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]); return back(); }`
      4. On success: toast `success` "Backup saved to {path}. {n} vault(s), {m} note(s)." plus " Skipped missing vault(s): A, B." when there are skipped vaults, plus " It couldn't be added to the backup history." when `! recorded` (then the type is `warning`). Return `back()`.
  - `BackupRestoreController`:
    - `browse(StoragePathService $paths, NativeDialogService $dialogs, BackupService $backups)`:
      - `abort_unless($dialogs->isAvailable(), 404)`;
      - `$chosen = $dialogs->chooseFile('Choose an MDVault backup', 'MDVault backup', ['zip'], $paths->defaultBackupDirectory())`;
      - null → `back()`; otherwise `Inertia::flash('pickedBackup', ['path' => $chosen])` and `back()`.
    - `inspect(InspectBackupRequest $request, BackupService $backups): JsonResponse`: `try { return response()->json($backups->inspect($request->validated('path'))->toArray()); } catch (BackupException $e) { throw ValidationException::withMessages(['path' => $e->getMessage()]); }`
    - `store(RestoreBackupRequest $request, BackupService $backups): RedirectResponse`:
      - `try { $r = $backups->restore(path, actions); } catch (BackupException $e) { throw ValidationException::withMessages([$e->field() => $e->getMessage()]); }`
      - toast success (`warning` if there are warnings) with `$r->summary()`;
      - `to_route('vaults.index')`.
  - `routes/settings.php`: add the five routes from §2, keeping `restore/browse` and `restore/inspect` **before** `restore`.
  - Tests in `tests/Feature/Backups/BackupHttpTest.php`:
    1. `GET settings.backup.edit` → the Inertia component `settings/Backup` with the props `backups`, `defaultDirectory` and `canBrowse` false; no `id` in `backups`.
    2. `POST settings.backup.store` (all, browser mode) → the file exists in the fake `Documents/MDVault Backups`, the success toast, a row.
    3. `vault` = a Work UUID → a single-vault backup.
    4. An unknown vault UUID → 422.
    5. The desktop path: `config(['nativephp-internal.running' => true])` plus `Http::fake(['*dialog/save' => Http::response(['result' => $tmp.'/chosen.zip'])])` → the file at `chosen.zip`. A cancel (`result` null) → no file, no row.
    6. Destination exists (dialog returns an existing file) → error toast; the file is unchanged.
    7. `browse` outside the desktop → 404. In the desktop, with `Http::fake(['*dialog/open' => ['result' => [$zip]]])` → flash `pickedBackup.path`.
    8. `inspect` valid → 200 JSON with the exact top-level keys `valid`, `problems`, `backup` and `vaults`. A tampered archive → 200 `valid: false`. A missing path → 422 on `path`. A GET → 405.
    9. `restore`: validation errors (no vaults, all skip, bad action, not a UUID) → 422 or session errors. Success → redirect to `vaults.index`, a toast, rows created. A registered UUID with `restore` → a session error on `path`.
  - Covers: FR-06, FR-07, FR-08, FR-10, FR-15

- [x] **T9: §55 acceptance round trip (FR-14)**
  - Add a helper to `tests/Pest.php`:
    ```php
    /** Simulates "remove local data → fresh application state": deletes every vault/note/setting/backup row,
     *  deletes $oldRoot recursively, points Documents at a NEW temp dir, and forgets scoped services. Returns the new Documents dir. */
    function simulateFreshInstall(string $oldRoot): string
    ```
    It uses `File::deleteDirectory`, `DB::table(...)->delete()`, `fakeDocumentsDirectory($new)` and `app()->forgetInstance(SettingsService::class)` (plus any other scoped or singleton services that cache state).
  - `tests/Feature/Backups/BackupRoundTripTest.php`:
    1. **Service level**:
       - create Work and Personal via `VaultService::create` (with descriptions);
       - create notes via `NoteService::create`, and save content via `NoteService::save`: nested folders, an empty folder via `createFolder`, a unicode name, CRLF + BOM content written with `writeVaultFiles` and then reconciled, an attachment `assets/a.png`;
       - capture every vault and note (uuid, name, description, relative_path, file_hash, file_size) and every file's bytes;
       - `create(null, $backupPath)` where `$backupPath` is **outside** the storage root and survives the reset;
       - `simulateFreshInstall`;
       - `inspect` → valid, both states `new`;
       - `restore` with both `restore`;
       - assert everything captured is equal, the empty folder exists, each vault's `path` is under the **new** root, `relative_path` equals the name, a Full reconcile reports no changes, and `VaultService::current()` is Work or Personal (the first restored, in manifest order).
    2. **HTTP level**, the same flow through `post(route('settings.backup.store'))`, `postJson(route('settings.backup.restore.inspect'))` and `post(route('settings.backup.restore.store'))`. Then GET the Workspace and `notes.show` for a restored note UUID → 200, with the content equal to the original.
  - Covers: FR-14

- [x] **T10: Frontend (FR-15)**
  - `php artisan wayfinder:generate --with-form --no-interaction`. Expected: `@/routes/settings/backup` (`edit`, `store`) and `@/routes/settings/backup/restore` (`browse`, `inspect`, `store`). Verify the exact export names and record them in `implementation.md`.
  - `resources/js/types/backups.ts`:
    ```ts
    export type RestoreAction = 'restore' | 'copy' | 'skip';
    export type BackupRecord = { uuid: string; scope: 'all' | 'vault'; filename: string; path: string; file_size: number; vault_count: number; note_count: number; file_count: number; vaults: string[]; created_at: string; exists: boolean };
    export type BackupInspectionVault = { uuid: string; name: string; description: string | null; note_count: number; file_count: number; total_bytes: number; state: 'new' | 'exists'; restore_name: string | null; copy_name: string | null; default_action: RestoreAction };
    export type BackupInspection = { valid: boolean; problems: string[]; backup: { created_at: string; app_version: string; format_version: number; scope: 'all' | 'vault'; vault_count: number; note_count: number; file_count: number; total_bytes: number; archive_size: number } | null; vaults: BackupInspectionVault[] };
    ```
    Export them through `@/types`.
  - Byte formatting: reuse an existing helper if `rg -n "formatBytes|formatSize" resources/js` finds one. Otherwise add `resources/js/lib/formatBytes.ts` (pure; B, KB, MB, GB with 1 decimal place) and `tests/js/formatBytes.test.ts`.
  - `layouts/settings/Layout.vue`: add `{ title: 'Backup', href: editBackup(), icon: Archive }` after Storage.
  - `pages/settings/Backup.vue` (single root; `Heading` + sections like `Storage.vue`):
    - **Back up**: a "Back up all vaults" `Button`, disabled when the shared `vaults` list has no active vault or a request is in flight, using `router.post(store.url(), { vault: null }, { preserveScroll: true, onStart/onFinish })`. The help text is "You'll choose where to save the backup." when `canBrowse`, otherwise "Backups are saved to {defaultDirectory}." Add a sentence explaining that backups include every note, folder and attachment in your vaults, but not hidden folders such as `.git`, or app settings.
    - **Restore**:
      - when `canBrowse`, a "Choose backup file…" button → `router.post(browse.url())`. A `watch` on the `pickedBackup` flash (pattern as in `AddExistingVaultDialog.vue`) opens the dialog with that path;
      - always a `Label` + `Input` "Backup file path" and a "Check backup" button that opens the dialog with the typed path.
    - **Recent backups**: newest first. Each row shows the date (`toLocaleString`), "All vaults" or the vault names, "N notes", the size, the path (mono, `break-all`), a `Badge` "Missing" when `! exists`, and a "Restore…" button (disabled when missing) that opens the dialog. If there are none: "No backups yet."
  - `components/backups/RestoreBackupDialog.vue` (single root `Dialog`; props `open` (v-model) and `path: string | null`):
    - On open (or a path change while open): POST `inspect.url()` via `useHttp` with `{ path }` (as in `NoteCompareDialog.vue`).
    - States:
      - loading: "Checking the backup… This can take a while for large backups.";
      - 422: show `errors.path`;
      - `valid: false`: an `Alert` (destructive) "This backup can't be restored." plus a `<ul>` of problems;
      - valid: a summary (date, app version, counts, size) and one row per vault.
    - Each vault row shows the name, the counts, the state text, and a labelled `Select` for the action:
      - `new`: options "Restore" (the default) and "Skip". The text is "Will be restored as “{restore_name}”." (note that the name differs when it does).
      - `exists`: options "Skip" (the default) and "Restore as a copy". The text is "Already in MDVault. A copy would be named “{copy_name}” and get new identities."
    - Footer: Cancel, and "Restore" (disabled when every action is skip or the form is processing) → `useForm({ path, vaults: [{uuid, action}] }).post(restoreStore.url(), { onSuccess: close })`. Show `form.errors.path` and `form.errors.vaults`.
    - Show a processing note: "Restoring… don't close MDVault."
    - Interpolation only, no `v-html`.
  - `components/backups/BackupVaultButton.vue` (props `vault: { uuid: string; status: string }`): a `Button size="sm" variant="outline"` labelled "Back up", disabled unless `status === 'active'` or while processing; `router.post(store.url(), { vault: vault.uuid }, { preserveScroll: true })`.
  - `pages/vaults/Index.vue`: add `<BackupVaultButton :vault="vault" />` after `RenameVaultDialog`.
  - Presentation only: no path manipulation (paths are passed through verbatim), no FS or crypto logic, single-root components.
  - Covers: FR-15

- [x] **T11: Architecture test updates**
  - `tests/Unit/ArchitectureTest.php`:
    - the ZipArchive rule (T3);
    - add `BackupService` and `ArchiveService` to the "no raw filesystem functions" rule (T5);
    - the Backup model rule (T5).

    Confirm the existing rule "only FileStorageService deletes, writes or syncs files" still passes; `ArchiveService` must not use `unlink`, `file_put_contents` or `fsync`.
  - Covers: FR-16

- [x] **T12: Quality gates and handover**
  - Run:
    - `php vendor/bin/pint --dirty --format agent`
    - `php artisan test --compact` (full suite)
    - `vendor/bin/phpstan analyse` (no new baseline entries)
    - `npm run test:js`
    - `npm run types:check`
    - `npm run build`
    - `npm run check`
  - Greps, each with the stated result:
    - `rg -n "extractTo" app` → none.
    - `rg -n "ZipArchive" app` → only `app/Services/ArchiveService.php`.
    - `rg -n "Schema::create" database/migrations` → the existing tables plus `backups` only.
    - `rg -n "deleteDirectory" app` → only inside `FileStorageService::deleteStagingDirectory`.
    - `rg -n "v-html" resources/js` → none.
    - `rg -n "mdvault.sqlite|notes.content|'content'" database/migrations` → none.
    - `rg -n "sync|device" database/migrations app/Services/BackupService.php` → none.
    - `rg -n "Co-Authored-By|Generated with" .` over the changed files → none.
  - `php artisan route:list --path=settings/backup` shows the five routes.
  - Record in `implementation.md`: the Wayfinder names, any arch `ignoring()` entries with justification, whether the Windows non-ASCII ZIP test passed (R2), and any deviations.
  - **Manual desktop checks** (D1–D14 pending user verification) (user: `php artisan native:migrate`, then `composer native:dev`):
    - **D1**: Settings → Backup → Back up all vaults. The Save dialog opens in `Documents\MDVault Backups` (or Documents). Save. Open the ZIP in Explorer: `manifest.json` plus `vaults\<Name>\…`; no `.git`, `.obsidian` or `.mdvault-*`; empty folders present.
    - **D2**: Back up a single vault from its card. The file name contains the vault name.
    - **D3**: Cancel the Save dialog. No file, no history entry.
    - **D4**: Choose an existing file and confirm "Replace". An error toast appears, and the existing file is unchanged.
    - **D5**: Choose a location inside a vault folder. An error toast appears.
    - **D6 (§55)**: Create a vault with nested folders, an empty folder, a unicode note and an image. Back up. Quit MDVault. Delete the vault folders. Run `php artisan native:migrate:fresh` (this wipes the **dev** desktop DB). Relaunch → Settings → Backup → Choose backup file → preview shows both vaults as new → Restore. The vaults, tree, notes and content are identical, and the note URLs (UUIDs) equal those in `manifest.json`.
    - **D7**: Restore the same backup again. The preview shows "Already in MDVault" with Skip selected. Choose "Restore as a copy". `Work (restored)` appears with its own identities; the original is unchanged.
    - **D8**: Edit a note inside a copy of the ZIP with 7-Zip. Check backup reports that entry as damaged. Restore isn't possible, and nothing changes.
    - **D9**: Rename a `.txt` file to `.zip` and check it. A clear "isn't a valid ZIP/MDVault backup" message appears.
    - **D10**: During a restore of a large backup, watch the storage root. Only a hidden `.mdvault-restore-*` folder appears, then the final folders; the staging folder disappears.
    - **D11**: With Wi-Fi off, D1–D7 still work.
    - **D12**: 5,000-note vault: the backup and the restore each complete in well under a minute, and the UI shows the busy state and recovers.
    - **D13**: Save a note in VS Code repeatedly while backing up. The result is either success or "a file changed while it was being backed up"; never a broken ZIP (re-check with Check backup).
    - **D14**: Recent backups: delete one backup file in Explorer and reload. It shows "Missing" and its Restore… button is disabled.
  - Covers: all FRs (verification)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Backups/BackupCreateTest.php` | Layout, manifest, bytes, ignored entries, reconcile first, single vs all, missing vaults, destination rules, publish failure, record failure, history, stale temp cleanup, unreadable | FR-01 to FR-07 |
| `tests/Feature/Backups/BackupInspectTest.php` | Valid preview and states and names; the full invalid-archive dataset (format, registry, path safety, entry set, size, hash, symlink, case duplicates); no writes | FR-08, FR-09 |
| `tests/Feature/Backups/BackupRestoreTest.php` | Fresh restore identity and bytes; registered UUID; copy; suffixes; note UUID collision; selection errors; damaged during staging; zip-slip; DB failure; rename failure; rename-back failure; stale staging; root creation | FR-10 to FR-13 |
| `tests/Feature/Backups/BackupRoundTripTest.php` | §55 acceptance at the service and HTTP level | FR-14 |
| `tests/Feature/Backups/BackupHttpTest.php` | Page props; store (browser, desktop dialog, cancel, errors); browse; inspect JSON and 422; restore validation, success and conflicts | FR-06 to FR-08, FR-10, FR-15 |
| `tests/Feature/Services/ArchiveServiceTest.php` | Round trip including non-ASCII names; EXCL; not a ZIP; symlink and encrypted detection; `readEntry` cap | FR-03, FR-09 |
| `tests/Feature/Services/FileStorageServiceTest.php` | New primitives: stream create, temp discard allow-list, staging delete scope, stale lists, touch, free space | FR-05, FR-09, FR-12 |
| `tests/Feature/Services/FileHashServiceTest.php` | `hashStream` and its cap | FR-08 |
| `tests/Feature/Services/NativeDialogServiceTest.php` | `chooseSaveFile`, `chooseFile` | FR-06, FR-15 |
| `tests/Feature/Services/StoragePathServiceTest.php` | `defaultBackupDirectory` | FR-06 |
| `tests/Feature/Services/VaultServiceTest.php` (or the existing vault service tests) | `nameAvailable`, `overlappingVault` | FR-06, FR-11 |
| `tests/Feature/DatabaseSchemaTest.php` | `backups` exists with its columns; `vault_encryption` still absent; no `notes.content` | FR-07, FR-16 |
| `tests/Unit/ArchitectureTest.php` | ZipArchive boundary; no raw FS in `BackupService`/`ArchiveService`; Backup model usage | FR-16 |
| `tests/js/formatBytes.test.ts` (only if the helper is added) | Units and rounding | FR-15 |
| (manual) D1–D14 | Desktop behaviour | FR-01 to FR-15 |

**Test scope for QA**:
- Targeted first:
  ```
  php artisan test --compact tests/Feature/Backups tests/Feature/Services/ArchiveServiceTest.php tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/FileHashServiceTest.php tests/Feature/Services/NativeDialogServiceTest.php tests/Feature/Services/StoragePathServiceTest.php tests/Feature/Services/VaultServiceTest.php tests/Feature/DatabaseSchemaTest.php tests/Unit/ArchitectureTest.php
  npm run test:js
  ```
  (Replace `VaultServiceTest.php` with the actual vault service test file if it's named differently.)
- Then the **full suite**, `php artisan test --compact`. It is required because of the migration, the `FileStorageService` visibility change (`discardTempFile`), and the `VaultService` refactor.
- Also: `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run build`, `npm run check`, and the T12 greps.
- Code review focus:
  - no `extractTo`; every entry path validated before any join;
  - nothing visible changes before staging verification completes;
  - the commit's rename-back compensation, and staging deletion on every failure path;
  - `deleteStagingDirectory` prefix guard;
  - never overwriting (destination, publish, restore targets);
  - the backup is verified before it is published;
  - the manifest has no absolute paths;
  - UUID handling for restore versus copy;
  - no settings or sync concepts;
  - the thin controllers map `BackupException` correctly;
  - single-root Vue, no `v-html`, no AI attribution.
- D1–D14 are **user-verified manual checks**, not defects.

---

## 5. Risks & Mitigations
- **R1: Long synchronous operations block the single-threaded desktop server** (the external-change polling stalls meanwhile). **Mitigation**: streaming I/O, a busy UI, `set_time_limit(0)`, and D12. Background jobs are a later option.
- **R2: libzip non-ASCII file names on Windows** (`addFile` with UTF-8 paths). **Mitigation**: T3 test 1 on Windows. **Fallback, if it fails**: for entries whose name isn't ASCII, `ArchiveService::write` reads the file and adds it with `addFromString`, flushing (close and re-open the archive in `ZipArchive::CREATE` mode) after every 64 MiB of buffered strings. Record this in `implementation.md`; no ADR change.
- **R3: Windows locks while renaming the staged folders.** **Mitigation**: 3 attempts, 200 ms apart, then full compensation; D10.
- **R4: A crash mid-commit leaves an unregistered restored folder and a staging folder.** **Mitigation**: SQLite rolls back; the backup is intact; stale staging is removed after 1 h; the folder is visible and can be added or deleted. Documented in the ADR.
- **R5: Hostile archives (zip-slip, bombs, symlinks).** **Mitigation**: the validation pipeline, streamed byte caps, the free-space check, never `extractTo`, and the T6 dataset.
- **R6: The OS Save dialog's "Replace?" confirmation followed by MDVault refusing is confusing.** **Mitigation**: a clear toast, and timestamped default names make collisions rare (D4).
- **R7: The pre-backup Quick reconcile races an external edit.** **Mitigation**: post-write verification; a clear "try again" error (D13).
- **R8: Large memory use.** **Mitigation**: `addFile` (lazy), stream reads, and the manifest ≤ 16 MiB. Only the fallback in R2 buffers, and it is bounded.

---

## 6. Open Questions (user approvals; recommended answers in bold)

> **Approved by user 2026-09-30:** H1–H11 all accepted as recommended (H2, H3, H4, H6 confirmed explicitly; H1, H5, H7–H11 accepted by default).
- [x] **H1: ZIP library.** **Use PHP's built-in `ZipArchive` (ext-zip) behind a new `ArchiveService`, and declare `"ext-zip": "*"` in `composer.json` (a platform requirement, no new package).** Verified: the zip extension is in the NativePHP php-bin 1.2.0 builds for Windows, macOS and Linux, and `nativephp/desktop` itself requires `ext-zip`. Alternative: a pure-PHP ZIP package (a new dependency, no benefit).
- [x] **H2: No SQLite file in the archive.** **The manifest carries a JSON registry of vaults and notes (UUIDs, relative paths, hashes, sizes, timestamps); no `mdvault.sqlite` inside the ZIP.** This deliberately deviates from §26's *example* layout; §28 "validate database" becomes validating that registry. Why: a raw DB copy embeds absolute paths, sessions and cache, ties backups to migrations, can only be restored by replacing the whole installation, and is an untrusted-file risk. Alternatives: a raw SQLite snapshot; a purpose-built mini SQLite file.
- [x] **H3: Manifest and versioning.** **Format 1 as in ADR `backup-archive-format`:**
  - `format_version` and `database_version` (both 1), `app_version`, `created_at`, `scope`, counts;
  - vault UUID, name, description and created date;
  - note UUID, relative path, SHA-256, size, timestamps;
  - no absolute paths; unknown keys ignored; newer versions refused;
  - encrypted vaults refused until Phase 7;
  - **app settings are not included.**

  Alternative: include portable preferences (theme, editor) and restore them on opt-in.
- [x] **H4: What gets backed up.** **Every file and folder in the vault except hidden entries (`.git`, `.obsidian`, `.mdvault-*`), `node_modules` and symlinks. That means notes plus attachments, and empty folders.** Alternative: `.md` notes only (attachments would be silently lost).
- [x] **H5: Restore versus Import.** **One flow, "Restore from backup", with a per-vault choice.** Import is the same flow. Alternative: separate operations, with Restore replacing the whole installation.
- [x] **H6: Conflicts and where restored vaults go.** **Vaults are restored into the current storage root:**
  - with their original UUIDs when that vault isn't in MDVault (the default);
  - if it already is, the default is **Skip**, with an option "Restore as a copy" (new identities, name `Work (restored)`);
  - a name or folder that's already taken gets `(restored)` / `(restored 2)` …;
  - nothing is ever merged or overwritten;
  - no in-place replace in v1.

  Alternatives: replace the existing vault (via the Recycle Bin); always copy.
- [x] **H7: Where backups are written.** **The desktop app shows the native Save dialog, starting in `Documents\MDVault Backups`. Browser dev and tests write straight into that folder.** The file name is `MDVault-Backup-[Vault-]YYYY-MM-DD-HHMMSS.zip`. MDVault never overwrites an existing file and refuses locations inside a vault. No new setting. Alternatives: a fixed folder only; a `backup.directory` setting.
- [x] **H8: `backups` table.** **Records each successful backup:** uuid, scope, file name, path, size, SHA-256, format version, counts, and the vault names/UUIDs. No FK to `vaults`; restores are not recorded. It is shown as "Recent backups" (20).
- [x] **H9: Restore safety mechanism.** **Validate fully → extract into a hidden `.mdvault-restore-*` folder in the storage root with per-file checksum checks → one DB transaction that registers everything and moves the folders into place, undone completely on any failure.** This requires one narrow exception to "MDVault never deletes a non-empty folder": it may delete **its own staging folders only**.
- [x] **H10: "Back up all" with a missing vault** (unplugged drive). **Skip it and name it in the result.** Backing up a single missing vault is an error. Alternative: fail the whole backup.
- [x] **H11: New folders.** **Approve `resources/js/components/backups/` and `tests/Feature/Backups/`** (plus optionally `resources/js/lib/formatBytes.ts`). No new composer or npm packages. One migration (`backups`).

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-30 | Initial plan | — |
| 1.1 | 2026-09-30 | QA rounds 1–5 and analyst review | Zip-bomb hardening (absolute caps QA-01, ratio heuristic, Stage 7.5 aggregate gate QA-05) with ADR amendments; documentation correction QA-06; analyst findings AR-01 to AR-05 (write-failure handling, stage-5 normalization, timestamp test, dialog reset, test temp dirs). Deferred optional test-coverage follow-ups QA-07, QA-08. No task or design changes. |

---
