# Plan: Phase 2: Vault Management

## Metadata
- **Feature Name**: Phase 2: Vault Management
- **Feature ID**: mdv-p2
- **Master Plan Phase**: **Implements Master Plan Phase 2, Vault Management (`docs/Masterplan.md` §51; §6 Vault Concept, §7 Vault Management, §11 `vaults`, §12 UUIDs, §19 File Storage Service, §40–43 services and error handling, §60 Rules 2/4/5/9)**
- **Author**: System Analyst
- **Created Date**: 2026-09-27
- **Task Complexity**: Level 3, Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED (user approved C1–C10 as recommended on 2026-09-27)

---

## 1. Summary
This plan adds a `vaults` registry (per §11, UUIDv7 in `uuid`) behind a new `VaultService`, plus a minimal `FileStorageService` for directory operations and an OS-trash boundary (`Trash` contract, implemented through NativePHP Shell).

- **Create**: follows §7's order (DB record, then directory, then check) inside a DB transaction. If the DB fails after the directory was created, an explicit filesystem compensation removes it.
- **Current vault**: stored as the setting `app.current_vault` (UUID).
- **Status**: `active` / `missing`, reconciled from the filesystem whenever vaults are read.
- **Rename**: display name only.
- **Remove**: unregisters by default; optionally moves the folder to the OS trash, checked afterwards.
- **Existing folders** can be registered.
- **UI**: the sidebar gets a real vault list (and a single root), plus a Vaults management page and a Workspace header.

---

## 2. Architecture & Design
- **Approach**:
  1. **Registry and consistency** (ADR `vault-registry-and-consistency`):
     - `path` = canonical absolute path. It alone decides where a vault is.
     - `relative_path` = forward-slash path relative to the storage root at creation or registration time. It is a hint only, for future backup/restore, and never used to find the vault.
     - A storage-root change never touches vaults (extends ADR `storage-root-resolution`).
     - Create runs as DB-first-in-transaction, then FS, then a check. If the operation fails after it created the folder, it runs `rmdir` on it (empty only).
     - Status is derived from `is_dir(path)` and persisted when it changes.
     - The current vault is a setting.
  2. **Removal and rename** (ADR `vault-removal-and-rename-semantics`):
     - Unregister is the default.
     - Optional OS trash (desktop only), protected by safety guards and checked afterwards. There is never a permanent delete.
     - Rename is display-only. Folder rename and move are deferred.
  3. **Filesystem boundary**:
     - `FileStorageService` (§41; the Phase 2 subset: directory creation, the empty check, the write probe, empty-dir removal, path comparison, trash) depends on `Illuminate\Filesystem\Filesystem` and the `Trash` contract.
     - `Trash` follows the `UserDirectories` precedent: an OS boundary that tests must substitute. `FileStorageService` is `final`, so it can't be mocked, which is why the contract sits underneath it.
  4. **HTTP**:
     - A thin `VaultController` and `ExistingVaultController`.
     - Form Requests check shape and the portable name. `VaultService` handles semantics and throws `VaultOperationException` (with `field()`), which controllers map to `ValidationException` (the same pattern as `StorageController`).
     - Non-form failures (open) become an error toast.
  5. **Shared state**: `HandleInertiaRequests` shares `vaults` lazily (a closure), fail-soft on `QueryException`. The sidebar reads it from `usePage()`.
- **Alternatives Considered**:
  - FS first, then DB (the Phase 1 pattern): rejected. §7 prescribes DB first, and the transaction gives atomic DB-side rollback for FS failures.
  - DB record with a `creating` status plus startup reconciliation: rejected as unnecessary. The transaction plus compensation covers failures, and a crash can only leave an empty orphan folder, which create then reuses (C8).
  - Resolving the path as `root + relative_path`: rejected. A root change would make every vault "missing", which contradicts ADR `storage-root-resolution`.
  - `vaults.is_current` column: rejected (C6). It needs a multi-row invariant; a single settings key cannot drift.
  - Mocking NativePHP `ShellFake`: insufficient. It doesn't delete anything, so the success path can't be tested. The `Trash` contract fixes this.
  - Folder rename on rename: deferred (C2). It needs lock handling and DB/FS compensation, and belongs with move (C4).
- **Decision Records**:
  - `.ai/decisions/vault-registry-and-consistency.md` (new)
  - `.ai/decisions/vault-removal-and-rename-semantics.md` (new)

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `vaults` | create | `id` PK; `uuid` uuid **unique**; `name` string(255); `description` text nullable; `path` text **unique**; `relative_path` text nullable; `is_encrypted` boolean default false; `status` string(30) default `'active'`; `created_at`, `updated_at` |
| `settings` | no schema change | new code-defined key `app.current_vault` (type string, group general, default null) |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Migration (new) | `database/migrations/<ts>_create_vaults_table.php` | Schema above |
| Model (new) | `app/Models/Vault.php` | `HasUuids` + `HasFactory`; `uniqueIds(): ['uuid']`; `$fillable = ['name','description','path','relative_path','is_encrypted','status']`; `casts()`: `is_encrypted` bool, `status` → `VaultStatus`; `$hidden = ['id']`; `@property` PHPDoc for all columns |
| Factory (new) | `database/factories/VaultFactory.php` | Default: unique name, a non-existent temp `path`, `status` active; `missing()` state |
| Enum (new) | `app/Enums/VaultStatus.php` | `Active='active'`, `Missing='missing'` |
| Enum (modify) | `app/Enums/SettingKey.php` | `CurrentVault = 'app.current_vault'` (String, General, default `null`) |
| Contract (new) | `app/Contracts/Trash.php` | `isAvailable(): bool`; `moveToTrash(string $path): void` |
| Support (new) | `app/Support/NativeTrash.php` | Desktop implementation via `Native\Desktop\Facades\Shell::trashFile()` |
| Service (new) | `app/Services/FileStorageService.php` | Directory primitives, path comparison, trash with a check afterwards |
| Service (modify) | `app/Services/StoragePathService.php` | New `ensureRootReady(): string` |
| Service (new) | `app/Services/VaultService.php` | Vault lifecycle (see T4) |
| Exception (new) | `app/Exceptions/VaultOperationException.php` | User-facing failures with `field()` |
| Provider (modify) | `app/Providers/AppServiceProvider.php` | Bind `Trash` → `NativeTrash` |
| Middleware (modify) | `app/Http/Middleware/HandleInertiaRequests.php` | Shared lazy `vaults` prop |
| Controller (new) | `app/Http/Controllers/VaultController.php` | index, store, update, destroy, open, close |
| Controller (new) | `app/Http/Controllers/ExistingVaultController.php` | store (register), browse (desktop picker), per C3 |
| Form Requests (new) | `app/Http/Requests/Vaults/{StoreVaultRequest,UpdateVaultRequest,DestroyVaultRequest,RegisterVaultRequest}.php` | Validation |
| Controller (modify) | `app/Http/Controllers/WorkspaceController.php` | `currentVault` prop |
| Controller (modify) | `app/Http/Controllers/Settings/GeneralController.php` | Stop exposing `group(General)` wholesale |
| Routes (new) | `routes/vaults.php` (required from `routes/web.php`) | See the route table |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Types (new) | `resources/js/types/vaults.ts` | `VaultStatus`, `VaultSummary`; export from `types/index.ts` |
| Types (modify) | `resources/js/types/global.d.ts` | `sharedPageProps.vaults: VaultSummary[]` |
| Page (new) | `resources/js/pages/vaults/Index.vue` | Management page |
| Components (new) | `resources/js/components/vaults/{CreateVaultDialog,AddExistingVaultDialog,RenameVaultDialog,RemoveVaultDialog,VaultStatusBadge}.vue` | Dialogs and badge (single root each) |
| Component (new) | `resources/js/components/NavVaults.vue` | Sidebar vault group |
| Component (modify) | `resources/js/components/AppSidebar.vue` | Use `NavVaults`; remove the trailing `<slot />` so there is a single root (QA-04) |
| Page (modify) | `resources/js/pages/Workspace.vue` | Current vault header, empty state, missing warning |

### Routes (`routes/vaults.php`, `web` middleware, no auth; `->whereUuid('vault')` on every `{vault:uuid}` route)
| Method | URI | Name | Controller@action |
|---|---|---|---|
| GET | `/vaults` | `vaults.index` | `VaultController@index` |
| POST | `/vaults` | `vaults.store` | `VaultController@store` |
| POST | `/vaults/close` | `vaults.close` | `VaultController@close` |
| POST | `/vaults/existing` | `vaults.existing.store` | `ExistingVaultController@store` (C3) |
| POST | `/vaults/existing/browse` | `vaults.existing.browse` | `ExistingVaultController@browse` (C3) |
| PATCH | `/vaults/{vault:uuid}` | `vaults.update` | `VaultController@update` |
| DELETE | `/vaults/{vault:uuid}` | `vaults.destroy` | `VaultController@destroy` |
| POST | `/vaults/{vault:uuid}/open` | `vaults.open` | `VaultController@open` |

Register the static routes (`close`, `existing`, `existing/browse`) **before** the `{vault:uuid}` routes.

---

## 3. Implementation Tasks
*Ordered. Each task leaves the suite green. After each task run `vendor/bin/pint --dirty --format agent` (as `php vendor/bin/pint …` in Git Bash) and that task's tests. After any route change run `php artisan wayfinder:generate --with-form --no-interaction`. `inertia.testing.ensure_pages_exist` is true, so a page must exist before its controller test runs. Every test that touches vaults or storage must use `beforeEach` with a temp dir + `fakeDocumentsDirectory($tmp/Documents)`, and `afterEach(File::deleteDirectory($tmp))`, exactly like `StorageSettingsTest`. Compare paths with `realpath()`.*

- [ ] **T0: Preconditions (orchestrator/user)**
  - Get the answers to C1–C10 (§6).
  - Suggested: `git add -A && git commit -m "Before Phase 2"`.
  - If C3 is rejected, skip T6, the `AddExistingVaultDialog`, and `VaultService::register()` with its tests.
  - Covers: —

- [ ] **T1: `vaults` schema, model, enum, factory**
  - Commands:
    - `php artisan make:model Vault -mf --no-interaction`
    - `php artisan make:enum VaultStatus --no-interaction`
  - Check that the generated paths are exactly `app/Models/Vault.php`, `app/Enums/VaultStatus.php` and `database/factories/VaultFactory.php`. Phase 1 saw doubled `Enums/Enums` paths; move any file that lands elsewhere and record it.
  - Migration: exactly the §2 schema. `$table->uuid('uuid')->unique()`, `$table->text('path')->unique()`. `down()` drops the table.
  - `Vault`:
    - `use HasFactory, HasUuids;`
    - `public function uniqueIds(): array { return ['uuid']; }`, so `id` stays incrementing.
    - Fillable, `$hidden = ['id']`, and casts as in §2.
    - PHPDoc `@property int $id; string $uuid; string $name; ?string $description; string $path; ?string $relative_path; bool $is_encrypted; VaultStatus $status; CarbonImmutable $created_at; CarbonImmutable $updated_at`.
    - No other logic.
  - `VaultStatus`: the two cases.
  - `VaultFactory`:
    - `name` = `Str::title(fake()->unique()->word())`
    - `path` = `sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-factory-'.Str::random(10)` (does not exist)
    - `relative_path` null, `is_encrypted` false, `status` `VaultStatus::Active`
    - state `missing()` sets `status` to Missing.
  - `tests/Feature/DatabaseSchemaTest.php`:
    - Add `vaults` to the present dataset.
    - Add a column test for all 10 columns.
    - Rename the absence test to "later-phase tables do not exist yet" with dataset `notes`, `vault_encryption`, `backups`.
  - New `tests/Feature/Models/VaultTest.php` (`php artisan make:test Models/VaultTest --pest --no-interaction`):
    - `uuid` is a 36-character string and `id` is an int.
    - `uuid` is unchanged after `update(['name' => 'X'])`.
    - `toArray()` has no `id`.
    - `status` casts to `VaultStatus`.
  - Run `php artisan migrate --no-interaction` and `php artisan native:migrate --no-interaction`.
  - Covers: FR-01, FR-17

- [ ] **T2: Current-vault setting key**
  - `SettingKey`: add `case CurrentVault = 'app.current_vault';`. In `type()` it is String, in `group()` General, and its `default()` is `null`.
  - `GeneralController::edit`: replace `$settings->group(SettingGroup::General)` with `['check_external_changes' => $settings->boolean(SettingKey::CheckExternalChanges)]`. Remove the unused import.
  - `tests/Feature/Settings/GeneralSettingsTest.php`: in the first test, add `->missing('settings.current_vault')`.
  - The existing `SettingsServiceTest` defaults dataset covers the new case automatically.
  - Covers: FR-05, FR-06

- [ ] **T3: `Trash` boundary, `FileStorageService`, `StoragePathService::ensureRootReady()`**
  - Create `app/Contracts/Trash.php`:
    ```php
    interface Trash
    {
        /** Whether moving to the OS Recycle Bin / Trash is possible in this runtime. */
        public function isAvailable(): bool;
        /** Ask the OS to move $path to its Recycle Bin / Trash. May fail silently; callers must verify. */
        public function moveToTrash(string $path): void;
    }
    ```
  - Create `app/Support/NativeTrash.php` (`final`):
    - Constructor `private readonly Repository $config`.
    - `isAvailable()` = `(bool) $this->config->get('nativephp-internal.running')`.
    - `moveToTrash()` returns early if it is not available. Otherwise it calls `\Native\Desktop\Facades\Shell::trashFile($path)`.
  - `AppServiceProvider::register()`: `$this->app->bind(Trash::class, NativeTrash::class);`
  - Create `app/Services/FileStorageService.php` (`php artisan make:class Services/FileStorageService --no-interaction`; `final`; constructor `private readonly Filesystem $files, private readonly Trash $trash`). Methods:
    - `isDirectory(string $path): bool`, `isFile(string $path): bool`: `is_dir` / `exists && ! is_dir`.
    - `isEmptyDirectory(string $path): bool`: a directory with no entries, including hidden ones (use `new \FilesystemIterator($path)` and `! $it->valid()`, inside try/catch → `false`).
    - `makeDirectory(string $path): bool`: `$this->files->makeDirectory($path, 0755, false, true)` and then `is_dir($path)`. The parent must already exist.
    - `isWritableDirectory(string $path): bool`:
      - a real probe: `put` of `.mdvault-write-test-<Str::random(12)>`;
      - try/catch `\Throwable` → false;
      - always delete the probe in `finally`.
      - The same approach as `StoragePathService::probeWritable`. Deduplicating the two is a Phase 3 follow-up; do not refactor `StoragePathService`'s private probe now.
    - `deleteEmptyDirectory(string $path): bool`: only if `isEmptyDirectory($path)`, then `@rmdir($path)` and `! is_dir($path)`. It never deletes a non-empty directory.
    - `canonical(string $path): string` = `realpath($path) ?: $path`.
    - `samePath(string $a, string $b): bool`:
      - compare `key()` values;
      - `key(p)` = canonical, separators unified to `/`, trailing `/` trimmed (unless the path is a root), then `mb_strtolower` when `PHP_OS_FAMILY === 'Windows'`.
    - `isSameOrInside(string $path, string $ancestor): bool`: `samePath`, or `str_starts_with(key(path), rtrim(key(ancestor), '/').'/')`. There must be a separator boundary, so `C:\a\Work` is **not** inside `C:\a\Wo`.
    - `relativeTo(string $path, string $ancestor): ?string`: returns null unless `isSameOrInside` is true and the paths are not the same. Otherwise it returns the remainder after the ancestor, using the **original case** of `canonical($path)` and `/` separators.
    - `isFilesystemRoot(string $path): bool`: `/`, `^[A-Za-z]:[\\/]?$`, or a UNC share root `^\\\\[^\\]+\\[^\\]+\\?$`.
    - `canTrash(): bool` = `$this->trash->isAvailable()`.
    - `moveToTrash(string $path): bool`:
      - if `! canTrash()` → false;
      - otherwise `$this->trash->moveToTrash($path)` inside try/catch `\Throwable` (→ false), then `clearstatcache()`;
      - return `! file_exists($path)`. This check afterwards is the only source of truth.
  - `StoragePathService::ensureRootReady(): string`:
    - `$root = $this->rootPath()`;
    - if it is an existing file → `InvalidStorageRootException::isFile()`;
    - if it is missing → `$this->files->makeDirectory($root, 0755, true, true)`, and if it still isn't a directory → `cannotCreate()`;
    - `$this->probeWritable($root)`;
    - return `realpath($root) ?: $root`.
    - It never writes settings.
  - `tests/Pest.php`: add
    ```php
    /** Bind a fake Trash. $deletes=true simulates success (deletes the directory); false simulates a silent OS failure. */
    function fakeTrash(bool $available = true, bool $deletes = true): object
    {
        $fake = new class($available, $deletes) implements \App\Contracts\Trash {
            /** @var list<string> */
            public array $trashed = [];
            public function __construct(private bool $available, private bool $deletes) {}
            public function isAvailable(): bool { return $this->available; }
            public function moveToTrash(string $path): void
            {
                $this->trashed[] = $path;
                if ($this->deletes) { \Illuminate\Support\Facades\File::deleteDirectory($path); }
            }
        };
        app()->instance(\App\Contracts\Trash::class, $fake);
        return $fake;
    }
    ```
  - `tests/Unit/ArchitectureTest.php`: add
    ```php
    arch('the OS shell is only used via NativeTrash')
        ->expect(['Native\Desktop\Facades\Shell', 'Native\Desktop\Shell'])
        ->toOnlyBeUsedIn('App\Support\NativeTrash');
    ```
  - Tests:
    - `tests/Feature/Services/FileStorageServiceTest.php` (`make:test Services/FileStorageServiceTest --pest`):
      - `isEmptyDirectory` for an empty dir, a dir with a hidden file (`.x`), a missing path and a file.
      - `makeDirectory` creates a dir and fails when the parent is missing.
      - `isWritableDirectory` returns true and leaves no probe file.
      - `deleteEmptyDirectory` removes an empty dir and refuses a non-empty one (its content survives).
      - `samePath` with a trailing separator.
      - `samePath` ignoring letter case (`->onlyOnWindows()`).
      - `isSameOrInside`: child → true, same → true, sibling prefix `Work` vs `Workshop` → false.
      - `relativeTo` gives `Team/Work`.
      - `isFilesystemRoot` dataset: `/`, `C:\`, `C:`, `\\srv\share` true; `C:\x` false.
      - `moveToTrash` with `fakeTrash()` → true and the dir is gone.
      - `fakeTrash(deletes: false)` → false and the dir remains.
      - `fakeTrash(available: false)` → false and `trashed` is empty.
    - `tests/Feature/Support/NativeTrashTest.php`:
      - Not available by default: `moveToTrash` sends nothing (`Http::fake(); … Http::assertNothingSent()`).
      - With `config(['nativephp-internal.running' => true])` and `Http::fake(['*shell/trash-item' => Http::response(null, 200)])`, `Http::assertSent` for method `DELETE`, a URL ending `shell/trash-item`, and `path` equal to the given path.
    - `tests/Feature/Services/StoragePathServiceTest.php`, three additions:
      - `ensureRootReady` creates a missing default root and returns its realpath, without writing settings (`isUsingDefault()` stays true);
      - it returns an existing root unchanged;
      - a file at the root path → `InvalidStorageRootException`.
  - Covers: FR-02, FR-10, FR-17

- [ ] **T4: `VaultService` and `VaultOperationException`**
  - Commands:
    - `php artisan make:class Services/VaultService --no-interaction`
    - `php artisan make:exception VaultOperationException --no-interaction`
    - Make the exception `final`, extending `\RuntimeException`, with the same private-constructor + `field()` pattern as `InvalidStorageRootException`.
  - `VaultOperationException` named constructors. Messages are user-facing; `{name}` / `{path}` are the user's own values.

    | Constructor | Field | Message |
    |---|---|---|
    | `invalidName()` | name | "Enter a valid vault name. It is also used as the folder name, so it can't contain < > : \" / \\ \| ? *, start or end with a space, end with a dot, or be a reserved system name (e.g. CON, NUL). 100 characters or fewer." |
    | `duplicateName(string $name)` | name | "A vault named “{name}” already exists." |
    | `storageUnavailable(string $reason)` | name | "Your storage location can't be used: {reason} Change it in Settings → Storage." |
    | `targetIsFile(string $path)` | name | "A file already exists at {path}." |
    | `targetNotEmpty(string $path)` | name | "A folder that already contains files exists at {path}. Choose another name, or add it with “Add existing folder”." |
    | `overlapsVault(string $vaultName, string $field)` | given | "This folder overlaps the vault “{vaultName}”. Vault folders can't be inside each other." |
    | `cannotCreate(string $path)` | name | "The vault folder could not be created at {path}." |
    | `notWritable(string $path)` | given | "MDVault cannot write to {path}." |
    | `folderMissing(string $path)` | given | "The vault folder can't be found at {path}. Reconnect the drive or remove the vault." |
    | `notAbsolute()` | path | "Enter a full (absolute) folder path." |
    | `pathNotDirectory(string $path)` | path | "No folder exists at {path}." |
    | `unsafeFolder(string $field)` | given | "For safety, this folder can't be used for a vault (it is a drive root, your Documents folder, or contains your storage location)." |
    | `trashUnavailable()` | move_to_trash | "Moving folders to the Recycle Bin / Trash is only available in the desktop app." |
    | `trashFailed(string $path)` | move_to_trash | "The folder at {path} could not be moved to the Recycle Bin / Trash. Close any programs using it and try again. The vault was not removed." |

  - `VaultService` (`final`):
    - Constructor: `SettingsService $settings`, `StoragePathService $paths`, `FileStorageService $files`, `UserDirectories $directories`, `DatabaseManager $database`.
    - Public API:
      ```php
      /** @return list<Vault> ordered by name (case-insensitive), statuses reconciled */
      public function all(): array;
      public function create(string $name, ?string $description = null): Vault;
      public function register(string $path, string $name, ?string $description = null): Vault; // C3
      public function rename(Vault $vault, string $name, ?string $description): Vault;
      public function remove(Vault $vault, bool $moveFolderToTrash = false): void;
      public function open(Vault $vault): Vault;
      public function close(): void;
      public function current(): ?Vault;
      public function refreshStatus(Vault $vault): Vault;
      public function canTrash(): bool; // delegates to FileStorageService
      public function suggestedNameFor(string $path): string; // basename of the normalised path
      /** @return array{uuid: string, name: string, description: ?string, path: string, relative_path: ?string, status: string, is_current: bool, is_encrypted: bool} */
      public function present(Vault $vault): array;
      /** @return list<array{...same shape...}> — fail-soft: QueryException → report() and [] */
      public function summaries(): array;
      ```
    - Private helpers:
      - `assertValidName(string $name)`: calls `$this->paths->assertValidFolderName($name)` and maps `InvalidStorageRootException` → `invalidName()`.
      - `assertNameAvailable(string $name, ?Vault $except)`: compare `mb_strtolower` against every other vault.
      - `assertNoOverlap(string $path, string $field, ?Vault $except)`: for each vault, if `isSameOrInside($path, $v->path) || isSameOrInside($v->path, $path)` → `overlapsVault($v->name, $field)`.
      - `assertSafeFolder(string $path, string $field)`:
        - `isFilesystemRoot($path)`;
        - or `isSameOrInside($this->paths->rootPath(), $path)`, meaning the path is the storage root or contains it;
        - or, when `documentsPath()` is not null, `isSameOrInside($documents, $path)`.
        - Any of these → `unsafeFolder($field)`.
    - `create()`, in this exact order:
      1. `assertValidName`, then `assertNameAvailable`.
      2. `$root = $this->paths->ensureRootReady()`. Catch `InvalidStorageRootException $e` → `storageUnavailable($e->getMessage())`.
      3. `$target = $root.DIRECTORY_SEPARATOR.$name`.
      4. `isFile` → `targetIsFile`; `isDirectory && ! isEmptyDirectory` → `targetNotEmpty`.
      5. `assertNoOverlap($target, 'name')`.
      6. Set `$createdHere = false` and run the transaction:
         ```php
         try {
             return $this->database->connection()->transaction(function () use ($name, $description, $target, &$createdHere) {
                 $vault = Vault::query()->create([...'path' => $target, 'relative_path' => $name, 'status' => VaultStatus::Active, 'is_encrypted' => false]); // §7: DB record
                 if (! $this->files->isDirectory($target)) {                                                   // §7: create directory
                     if (! $this->files->makeDirectory($target)) { throw VaultOperationException::cannotCreate($target); }
                     $createdHere = true;
                 }
                 if (! $this->files->isDirectory($target) || ! $this->files->isWritableDirectory($target)) {  // §7: verify
                     throw VaultOperationException::notWritable($target);
                 }
                 $vault->update(['path' => $this->files->canonical($target)]);
                 return $vault;                                                                                // §7: complete
             });
         } catch (\Throwable $e) {
             if ($createdHere) { $this->files->deleteEmptyDirectory($target); } // FS compensation; never removes content
             throw $e;
         }
         ```
      7. Add a PHPDoc on `create()` explaining the order and that a crash can leave at most an empty folder, which a later create with the same name reuses (C8).
    - `register()`:
      1. `$normalized = $this->paths->normalize($path)`. Map `InvalidStorageRootException` → `notAbsolute()`.
      2. `! $this->paths->isAbsolute(...)` → `notAbsolute()`.
      3. `! isDirectory` → `pathNotDirectory`.
      4. `$canonical = canonical(...)`.
      5. `assertSafeFolder($canonical, 'path')`, then `assertNoOverlap($canonical, 'path')`.
      6. `! isWritableDirectory` → `notWritable` (field path).
      7. `assertValidName`, `assertNameAvailable`.
      8. Create the record with `relative_path = $this->files->relativeTo($canonical, $this->paths->rootPath())`.
      - It creates nothing on disk.
    - `rename()`: `assertValidName`, `assertNameAvailable($name, except: $vault)`, then `update(['name', 'description'])`. No filesystem calls.
    - `remove()`:
      1. `refreshStatus`.
      2. If `$moveFolderToTrash`:
         - `! canTrash()` → `trashUnavailable()`;
         - status Missing → `folderMissing($vault->path)` (field `move_to_trash`);
         - `assertSafeFolder($vault->path, 'move_to_trash')`;
         - `! $this->files->moveToTrash($vault->path)` → `trashFailed($vault->path)`. The record is kept.
      3. Transaction: if `$this->settings->string(SettingKey::CurrentVault) === $vault->uuid`, then `$this->settings->forget(CurrentVault)`. Then `$vault->delete()`.
      4. Otherwise it never touches the filesystem.
    - `open()`: `refreshStatus`. Missing → `folderMissing` (field `vault`). Otherwise `set(CurrentVault, $vault->uuid)` and return the vault.
    - `close()`: `forget(CurrentVault)`.
    - `current()`:
      - The UUID comes from the setting; null → null.
      - Otherwise `Vault::query()->where('uuid', $uuid)->first()`.
      - Not found → `forget` + null.
      - Found → `refreshStatus` and return it. A missing current vault stays current, and the UI shows a warning.
    - `refreshStatus()`: `$expected = isDirectory($vault->path) ? Active : Missing`. If it differs, `update(['status' => $expected])`. Return the vault.
    - `all()`: `Vault::query()->get()`, sorted in PHP by `mb_strtolower(name)`, each passed through `refreshStatus`, returned as `->values()->all()`.
    - `present()`: the shape above, with `status` = `$vault->status->value` and `is_current` compared to the setting. It never includes `id`.
    - `summaries()`: `array_map(present, all())`, wrapped in `try { } catch (QueryException $e) { report($e); return []; }`.
  - `tests/Unit/ArchitectureTest.php`: add
    ```php
    arch('vault records are only used by services, controllers and the factory')
        ->expect('App\Models\Vault')
        ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'Database\Factories']);
    ```
  - Tests: `tests/Feature/Services/VaultServiceTest.php` (`make:test Services/VaultServiceTest --pest`).
    - Setup: temp dir + `fakeDocumentsDirectory`, `$root = <tmp>/Documents/MDVault`, `$svc = app(VaultService::class)`.
    - **Create**:
      1. Happy path. The folder exists, one record, `path === realpath(folder)`, `relative_path === 'Work'`, status active, no `.mdvault-write-test-*` left. The storage root was created because it was missing.
      2. A new instance after `app()->forgetScopedInstances()` still lists it (persistence).
      3. Invalid-name dataset (`''`, `' Work'`, `'Work.'`, `'a/b'`, `'a\\b'`, `'CON'`, `'nul.txt'`, `'x?'`, 101 characters) → `VaultOperationException` with `field() === 'name'`, `Vault::count() === 0`, and no new folder under the root.
      4. Duplicate `Work`, then `work` → `duplicateName`.
      5. A non-empty target (`<root>/Work/a.md`) → `targetNotEmpty`; `a.md` is intact; no record.
      6. An empty existing target is reused.
      7. The target is a file → `targetIsFile`.
      8. The storage root is unusable (`SettingsService::set(StorageRootPath, <tmp>/blocker.txt)` where that path is a file) → `storageUnavailable`; no record.
      9. **DB failure after mkdir**: register `Vault::updating(fn () => throw new \RuntimeException('db down'))` → exception; `Vault::count() === 0`; `<root>/Work` does **not** exist (compensated).
      10. **mkdir failure**: register `Vault::created(fn ($v) => File::put($v->path, 'x'))`. The target becomes a file after the insert, so `makeDirectory` fails → `cannotCreate`; `Vault::count() === 0` (rolled back); the file created by the listener still exists (never deleted by the service).
      11. Overlap: `register()` `<tmp>/Outer`, then set the storage root inside `<tmp>/Outer`, then `create('Inner')` → `overlapsVault`.
    - **Rename**:
      12. Name and description change; `path` is unchanged; a file inside the folder is intact; the folder name is unchanged.
      13. Duplicate → error.
      14. A letter-case-only change of its own name succeeds.
    - **Remove**:
      15. Unregister: the record is gone and the folder plus `a.md` still exist.
      16. Removing the current vault clears `CurrentVault`.
      17. Trash success (`fakeTrash()`): the folder is gone, the record is gone, and `$fake->trashed === [path]`.
      18. Silent trash failure (`fakeTrash(deletes: false)`): `trashFailed` with field `move_to_trash`; the record still exists; the folder still exists.
      19. Trash unavailable (`fakeTrash(available: false)`) → `trashUnavailable`; nothing changes.
      20. Trash on a missing vault → `folderMissing`; the record is kept.
      21. Unsafe trash: a factory vault whose `path` is the storage root (created on disk), and one whose path is the fake Documents dir → `unsafeFolder`; `$fake->trashed === []`.
    - **Open, close, current**:
      22. Open sets the setting. After `forgetScopedInstances()`, `app(VaultService::class)->current()->uuid` matches (restart).
      23. Close → `current()` is null.
      24. Open a vault whose folder was deleted → `folderMissing`; the setting is unchanged; the status is persisted as missing.
      25. `current()` with a stale UUID (the record was deleted directly via `Vault::query()->delete()`) → null, and the setting is forgotten.
    - **Status**:
      26. `File::deleteDirectory(folder)` → `all()` reports missing and the DB status is missing. Recreate the folder → `all()` reports active.
      27. `summaries()` after `Schema::drop('vaults')` → `[]` and no exception.
      28. `present()` has no `id` key and has the exact keys.
    - **Register** (C3):
      29. `<tmp>/Existing/n.md` → a record with `relative_path` null; `n.md` untouched; nothing created.
      30. A folder inside the root (`<root>/Old`, pre-created) → `relative_path === 'Old'`.
      31. Dataset of `path` errors: relative `x/y`, a missing dir, a file, the storage root itself, the parent of the storage root, the fake Documents dir, an already-registered path (plus an upper-case variant, `->onlyOnWindows()`), a path inside a registered vault.
      32. An invalid or duplicate `name` → `name` errors.
    - **Root-change isolation** (FR-16):
      33. Create A, then `StoragePathService::changeRoot(<tmp>/R2)`. A's `path` is unchanged; `open(A)` works; `create('B')` lands under `<tmp>/R2/MDVault/B`.
  - Covers: FR-02 to FR-12, FR-16

- [ ] **T5: Vault HTTP layer, shared prop, stub page**
  - Commands:
    - `php artisan make:controller VaultController --no-interaction`
    - `php artisan make:request Vaults/StoreVaultRequest --no-interaction`
    - `php artisan make:request Vaults/UpdateVaultRequest --no-interaction`
    - `php artisan make:request Vaults/DestroyVaultRequest --no-interaction`
  - Create `routes/vaults.php` with the §2 routes (T6's routes are added in T6). Add `require __DIR__.'/vaults.php';` to `routes/web.php`.
  - Form Requests (`authorize()` true):
    - `StoreVaultRequest` / `UpdateVaultRequest`:
      - `name` → `['bail', 'required', 'string', 'max:100', <closure calling StoragePathService::assertValidFolderName; on InvalidStorageRootException $fail(VaultOperationException::invalidName()->getMessage())>]`, with `rules(StoragePathService $paths)` method injection as in `UpdateStorageRootRequest`.
      - `description` → `['nullable', 'string', 'max:1000']`.
      - `messages()`: `name.required` → "The vault name is required."
    - `DestroyVaultRequest`: `move_to_trash` → `['sometimes', 'boolean']`.
  - `VaultController` (thin; each mutating action wraps its service call in a private `attempt(callable)` helper that maps `VaultOperationException` → `ValidationException::withMessages([$e->field() => $e->getMessage()])`):
    - `index(StoragePathService $paths, VaultService $vaults)` → `Inertia::render('vaults/Index', ['storageRoot' => $paths->rootPath(), 'canBrowse' => app(NativeDialogService::class)->isAvailable(), 'canTrash' => $vaults->canTrash()])`. Inject `NativeDialogService` as a parameter. The vault list comes from the shared prop.
    - `store(StoreVaultRequest, VaultService)`:
      - `create(...)`, then `open($vault)`;
      - flash toast success `Vault “{name}” created.`;
      - `to_route('workspace')`.
    - `update(UpdateVaultRequest, Vault $vault, VaultService)`: `rename`; toast `Vault renamed.`; `back()`.
    - `destroy(DestroyVaultRequest, Vault $vault, VaultService)`:
      - `remove($vault, $request->boolean('move_to_trash'))`;
      - toast `Vault “{name}” removed.` plus either ` Its folder was moved to the Recycle Bin / Trash.` or ` Its files were left on disk.`;
      - `to_route('vaults.index')`.
    - `open(Vault $vault, VaultService)`:
      - try `open`;
      - on `VaultOperationException` → `Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()])` and `back()`;
      - on success → `to_route('workspace')`.
    - `close(VaultService)`: `close()`; `to_route('workspace')`.
  - `HandleInertiaRequests`:
    - Constructor-inject `VaultService`.
    - In `share()`, add `'vaults' => fn () => $this->vaults->summaries(),`.
  - `resources/js/pages/vaults/Index.vue`: a **stub** for now. Single root `<div>` with `<Head title="Vaults" />` and a `Heading`. T7 fills it in.
  - Regenerate Wayfinder.
  - Tests: `tests/Feature/Vaults/VaultManagementTest.php` (`make:test Vaults/VaultManagementTest --pest`; temp dir + `fakeDocumentsDirectory` in `beforeEach`):
    1. GET `vaults.index` → component `vaults/Index`; `storageRoot` ends with `MDVault`; `canBrowse` false; `canTrash` false; shared `vaults` is `[]`.
    2. POST `vaults.store` `{name: 'Work', description: 'd'}` → redirect to `route('workspace')`; `assertInertiaFlash('toast.type', 'success')`; the folder exists; the setting equals the new UUID.
    3. POST with a name from the invalid dataset (`CON`, `a/b`, empty) → `assertSessionHasErrors('name')`; `Vault::count() === 0`.
    4. POST a duplicate → `assertSessionHasErrors('name')`.
    5. PATCH `vaults.update` with the UUID → the name is changed; PATCH a duplicate → `name` error.
    6. DELETE `vaults.destroy` (no flag) → redirect to `vaults.index`; the record is gone; the folder is intact.
    7. DELETE with `move_to_trash=1` in the browser runtime → `assertSessionHasErrors('move_to_trash')`; the record is kept.
    8. DELETE with `move_to_trash=1` after `fakeTrash()` → the folder is gone and the record is gone.
    9. POST `vaults.open` → redirect to workspace and the setting is set. After deleting the folder, `open` → redirect back with `assertInertiaFlash('toast.type', 'error')`.
    10. POST `vaults.close` → the setting is cleared.
    11. An unknown UUID → 404; the integer id (`/vaults/1/open`) → 404.
    12. The shared prop on `route('workspace')` and `route('settings.general.edit')`: `vaults` has 2 items; `is_current` is correct; `status` is `missing` after its folder is deleted; no item has an `id` key (use `->where('vaults.0', fn ($v) => ! array_key_exists('id', (array) $v))` or `->missing('vaults.0.id')`).
  - Covers: FR-02, FR-05, FR-07, FR-08, FR-09, FR-10, FR-11, FR-13, FR-14, FR-17

- [ ] **T6: Add existing folder (only if C3 is approved)**
  - Commands:
    - `php artisan make:controller ExistingVaultController --no-interaction`
    - `php artisan make:request Vaults/RegisterVaultRequest --no-interaction`
  - Routes: `vaults.existing.store` and `vaults.existing.browse`, placed before the `{vault:uuid}` routes.
  - `RegisterVaultRequest`:
    - `path` → `['required', 'string', 'max:1024']`;
    - `name` → the same rules as `StoreVaultRequest` (extract them into a shared `protected function nameRules(StoragePathService $paths): array` on a small abstract `App\Http\Requests\Vaults\VaultNameRules` trait, or duplicate them in the three requests; prefer the trait);
    - `description` nullable.
  - `ExistingVaultController`:
    - `store(RegisterVaultRequest, VaultService)` → `register(...)`, then `open`, then toast `Vault “{name}” added.`, then `to_route('workspace')`. Uses the same exception mapping.
    - `browse(StoragePathService $paths, NativeDialogService $dialogs, VaultService $vaults)`:
      - `abort_unless($dialogs->isAvailable(), 404)`;
      - `$chosen = $dialogs->chooseDirectory('Choose a folder to add as a vault', $paths->rootPath())`;
      - null → `back()`;
      - otherwise `Inertia::flash('pickedFolder', ['path' => $chosen, 'name' => $vaults->suggestedNameFor($chosen)])` and `back()`.
      - It does **not** register anything; the user confirms in the dialog.
  - Regenerate Wayfinder.
  - Tests: `tests/Feature/Vaults/ExistingVaultTest.php`:
    - The store happy path (the folder with `n.md` is untouched; redirect to workspace; current is set).
    - The store `path` error dataset: relative, missing, file, the storage root.
    - A store `name` error.
    - Browse → 404 in the browser runtime.
    - With `config(['nativephp-internal.running' => true])` and `Http::fake(['*dialog/open' => Http::response(['result' => [$dir]])])` → `assertInertiaFlash('pickedFolder.path', $dir)` and `assertInertiaFlash('pickedFolder.name', 'Existing')`; `Vault::count() === 0`.
    - A cancel (`result: []`) → `assertInertiaFlashMissing('pickedFolder')`.
  - Covers: FR-12

- [ ] **T7: Vaults management page and dialogs**
  - `resources/js/types/vaults.ts`:
    - `export type VaultStatus = 'active' | 'missing';`
    - `export type VaultSummary = { uuid: string; name: string; description: string | null; path: string; relative_path: string | null; status: VaultStatus; is_current: boolean; is_encrypted: boolean };`
    - Export it from `types/index.ts`.
  - `types/global.d.ts`: add `vaults: VaultSummary[];` to `sharedPageProps` (import the type).
  - `components/vaults/VaultStatusBadge.vue`:
    - prop `status`;
    - `Badge` secondary "Available" for active;
    - `Badge` destructive/outline with a `TriangleAlert` icon and the text "Folder missing" for missing.
  - `components/vaults/CreateVaultDialog.vue`:
    - prop `storageRoot: string`;
    - `Dialog` with a `DialogTrigger` button "New vault";
    - `useForm({ name: '', description: '' })`;
    - `form.submit(store())` (Wayfinder `@/routes/vaults`);
    - help text "The folder is created inside: {storageRoot}". No path joining in Vue.
    - `InputError` for `name` and `description`;
    - closes on success and resets the form.
  - `components/vaults/AddExistingVaultDialog.vue` (C3):
    - props `canBrowse: boolean`;
    - `useForm({ path: '', name: '', description: '' })`;
    - a "Choose folder…" button (`v-if="canBrowse"`) → `router.post(browse.url(), {}, { preserveState: true, preserveScroll: true, onFlash: ({ pickedFolder }) => { if (pickedFolder) { form.path = pickedFolder.path; if (!form.name) form.name = pickedFolder.name; } } })` from `@/routes/vaults/existing`, with a `browsing` ref for the disabled state;
    - submit via `form.submit(existingStore())`;
    - help text "The folder and its files are left as they are."
  - `components/vaults/RenameVaultDialog.vue`:
    - prop `vault: VaultSummary`;
    - `useForm({ name, description })`;
    - `form.submit(update(vault.uuid))`;
    - help text "Only the display name changes. The folder on disk keeps its name: {vault.path}."
  - `components/vaults/RemoveVaultDialog.vue`:
    - props `vault: VaultSummary`, `canTrash: boolean`;
    - `useForm({ move_to_trash: false })`;
    - text "Remove “{name}” from MDVault? Files in {path} are not deleted.";
    - a `Checkbox` "Also move the folder to the Recycle Bin / Trash", shown only when `canTrash && vault.status === 'active'`, with the note "You can restore it from the Recycle Bin / Trash.";
    - the destructive button "Remove vault" → `form.submit(destroy(vault.uuid))`;
    - `InputError` for `move_to_trash`.
  - `pages/vaults/Index.vue` (replaces the stub):
    - Props `storageRoot: string`, `canBrowse: boolean`, `canTrash: boolean`.
    - `const page = usePage(); const vaults = computed(() => page.props.vaults);`
    - `defineOptions({ layout: { breadcrumbs: [{ title: 'Vaults', href: index() }] } })`.
    - Single root `<div class="flex flex-col gap-6 p-4">`:
      - `Head`;
      - `Heading` "Vaults" / "Folders of Markdown notes managed by MDVault.";
      - an action row with `CreateVaultDialog` and `AddExistingVaultDialog`;
      - a list of `Card`s, one per vault: name, a "Current" badge, `VaultStatusBadge`, description, path (monospace, `break-all`), and buttons Open (`router.post(open.url(v.uuid))`, disabled when missing), `RenameVaultDialog` and `RemoveVaultDialog`;
      - an empty state "No vaults yet. Create one to get started."
  - Presentation only: no path or filesystem logic.
  - Covers: FR-14, FR-08, FR-09, FR-10, FR-12

- [ ] **T8: Sidebar vault list (and single-root `AppSidebar`)**
  - `components/NavVaults.vue`, single root `<SidebarGroup class="px-2 py-0">`:
    - `SidebarGroupLabel` "Vaults".
    - `SidebarGroupAction` (`Plus` icon, `as-child`, `<Link :href="index()">`, title "Manage vaults").
    - `SidebarMenu` with one `SidebarMenuItem` per `page.props.vaults`:
      - a `SidebarMenuButton` with `:is-active="v.is_current"` and `:tooltip="v.name"`, rendered as a `button` with `@click="router.post(open.url(v.uuid))"`;
      - a `FolderClosed` icon, or `TriangleAlert` when missing, plus an `sr-only` "(folder missing)";
      - the name in a `<span>`.
    - Empty state: `<Link :href="index()">` "Create a vault" in muted text.
    - If `SidebarGroupAction` is not exported by `@/components/ui/sidebar`, use a small ghost `Button` in the label row instead, and record the choice.
  - `components/AppSidebar.vue`:
    - Replace the placeholder `SidebarGroup` with `<NavVaults />`.
    - **Remove the trailing `<slot />`**. Its only consumer, `AppSidebarLayout.vue`, passes no slot content, so the component becomes single-root (QA-04 remainder).
    - Remove any imports that are no longer used.
  - Covers: FR-13

- [ ] **T9: Workspace current vault**
  - `WorkspaceController`: inject `VaultService`; add `'currentVault' => ($v = $vaults->current()) ? $vaults->present($v) : null`.
  - `pages/Workspace.vue`:
    - Prop `currentVault: VaultSummary | null`.
    - Inside the existing single root, above `<main>`, add a header bar:
      - When a vault is current: name, path (muted, truncated with a `title`), `VaultStatusBadge`, and a "Close vault" ghost button (`router.post(close.url())`).
      - When it is missing: an `Alert` (variant destructive) "This vault's folder can't be found at {path}. Reconnect the drive, or remove the vault from the Vaults page." with a link to `index()`.
      - When null: a muted empty-state bar "No vault open", with a `Link` "Open or create a vault" → `index()`.
    - Do **not** modify `TiptapEditor.vue`. The demo editor stays as is.
  - `tests/Feature/WorkspaceTest.php`:
    - Add `->where('currentVault', null)` to the first test.
    - New test: create and open via `VaultService` (with temp dir + `fakeDocumentsDirectory`, and clean-up), then `forgetScopedInstances()`, then GET workspace → `currentVault.uuid` and `currentVault.name` match (restart persistence).
  - Covers: FR-06, FR-15, FR-07

- [ ] **T10: Quality gates and handover**
  - Run:
    - `php vendor/bin/pint --dirty --format agent`
    - `php artisan test --compact` (full suite)
    - `vendor/bin/phpstan analyse` (level 7; array-shape PHPDocs, no baseline entries)
    - `npm run types:check`
    - `npm run build`
    - `npm run check`
    - `php artisan wayfinder:generate --with-form --no-interaction`
  - Checks:
    - `php artisan route:list --path=vaults` shows exactly the 8 routes in §2 (6 if C3 is rejected).
    - `rg -n "C:\\\\|/Users/|/home/" app` → no matches.
    - `rg -n "->id\b|'id'" app/Services/VaultService.php` → no integer id in `present()`.
    - `rg -n "deleteDirectory|rmdir|unlink" app/Services` → only `FileStorageService::deleteEmptyDirectory` (and `StoragePathService`'s existing probe delete).
  - Record everything in `implementation.md`, including any `make:*` path fixes.
  - **Manual (user, desktop)** with `composer native:dev`:
    1. The sidebar shows "Vaults" with the "Create a vault" empty state.
    2. Create "Work": `Documents\MDVault\Work` appears in Explorer; the Workspace shows "Work".
    3. Quit and relaunch: "Work" is still current.
    4. Rename it to "Office": the Explorer folder is still `Work`.
    5. Rename or delete the `Work` folder in Explorer: the sidebar shows it as missing, and Open shows an error. Restore it: it becomes available again.
    6. "Add existing folder" → Choose folder… → the picker fills the path and name → Add.
    7. Remove with "move to Recycle Bin": the folder appears in the Recycle Bin and can be restored. Remove without it: the folder stays.
    8. With the folder open in VS Code or Explorer, removing with Recycle Bin shows an error or succeeds cleanly. The vault is never removed while its folder remains.
  - Covers: all FRs (verification)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/DatabaseSchemaTest.php` | `vaults` present with 10 columns; `notes`/`vault_encryption`/`backups` absent | FR-01 |
| `tests/Feature/Models/VaultTest.php` | UUID generated, stable on update; `id` hidden; status cast | FR-01, FR-17 |
| `tests/Feature/Settings/GeneralSettingsTest.php` | `current_vault` not exposed | FR-05 |
| `tests/Feature/Services/FileStorageServiceTest.php` | Empty check, mkdir, probe, empty-only rmdir, path comparison (case, boundary), relative path, FS roots, trash with check afterwards | FR-02, FR-10, FR-17 |
| `tests/Feature/Support/NativeTrashTest.php` | Unavailable → no HTTP; available → `DELETE shell/trash-item` with path | FR-10 |
| `tests/Feature/Services/StoragePathServiceTest.php` | `ensureRootReady` creates/returns/rejects file, never writes settings | FR-02 |
| `tests/Feature/Services/VaultServiceTest.php` | 33 scenarios in T4: create (happy, persistence, names, duplicates, non-empty/empty/file targets, unusable root, **DB failure compensation**, **mkdir failure rollback**, overlap), rename, remove (unregister, current cleared, trash success / silent failure / unavailable / missing / unsafe), open/close/current/restart/stale, status detection and recovery, fail-soft summaries, `present` shape, register (C3), root-change isolation | FR-02 to FR-12, FR-16 |
| `tests/Feature/Vaults/VaultManagementTest.php` | Index props; store/update/destroy/open/close over HTTP; validation mapping; trash flag; UUID-only routing; shared `vaults` prop on multiple pages, no `id` | FR-02, FR-05, FR-07 to FR-11, FR-13, FR-14, FR-17 |
| `tests/Feature/Vaults/ExistingVaultTest.php` | Register happy/errors; browse 404 / picked / cancel via flash | FR-12 |
| `tests/Feature/WorkspaceTest.php` | `currentVault` null; persists across a scoped reset | FR-06, FR-15 |
| `tests/Unit/ArchitectureTest.php` | Shell only in `NativeTrash`; `Vault` only in services, controllers, factory; existing rules | FR-17 |
| (static) `npm run types:check`, `build`, `check` | New pages and components compile; single roots | FR-13, FR-14, FR-15 |
| (manual) `composer native:dev` | T10 steps 1–8 | FR-02, FR-06, FR-10, FR-11, FR-12 |

**Test scope for QA**:
- `php artisan test --compact`: the **full suite**, because Phase 2 changes the shared Inertia middleware (every page), `SettingKey`, routes and `StoragePathService`.
- `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run build`, `npm run check`.
- `php artisan route:list --path=vaults` (8 routes, or 6 without C3).
- The greps in T10.
- Code review:
  - no filesystem calls in controllers or Vue;
  - the only folder removal in `app/` is `FileStorageService::deleteEmptyDirectory` plus OS trash, with no permanent deletion;
  - `create()` follows DB, then FS, then the check, with compensation;
  - `remove()` follows trash, then the check, then DB;
  - no integer ids in props or URLs;
  - every new or edited Vue component (including `AppSidebar.vue`) has a single root;
  - `TiptapEditor.vue` is unchanged.
- The desktop steps in T10 are **checked by the user**. QA marks them as manual, not as defects.

---

## 5. Risks & Mitigations
- **Risk**: tests create or trash real user folders. **Mitigation**:
  - `fakeDocumentsDirectory` plus temp dirs in every vault test;
  - the default `Trash` does nothing outside the runtime;
  - `fakeTrash` only deletes the given temp path;
  - the service guards refuse roots, the storage root and Documents.
- **Risk**: NativePHP `trashFile` swallows errors. **Mitigation**: `FileStorageService::moveToTrash` returns `! file_exists($path)` after `clearstatcache()`, and the record is only deleted afterwards (FR-10).
- **Risk**: Windows path aliasing (letter case, 8.3 temp paths like `CHERW~1`). **Mitigation**:
  - store `realpath`;
  - `samePath` / `isSameOrInside` normalise and ignore case on Windows, with a separator boundary;
  - tests compare `realpath()` values.
- **Risk**: the shared `vaults` prop breaks every page if the table is missing (first native boot before migrations). **Mitigation**: `summaries()` is fail-soft (T4 test 27); NativePHP runs migrations at startup.
- **Risk**: status writes on GET requests. **Mitigation**: they only happen when the status changes; they are idempotent; there is one local user (§44).
- **Risk**: route conflicts between `/vaults/close` or `/vaults/existing` and `{vault}`. **Mitigation**: static routes are registered first, plus `whereUuid('vault')`.
- **Risk**: `Vault::updating` / `created` test listeners leak into later tests. **Mitigation**: each test boots a fresh app (Pest/Laravel). Register the listeners inside the test only.
- **Risk**: the sonnet developer "improves" delete into a permanent delete. **Mitigation**: the ADR, the QA code-review item and the grep in T10.

---

## 6. Open Questions (user approvals; recommended answers in bold)
- [ ] **C1: Delete semantics.** Options:
  - (a) unregister only;
  - (b) permanently delete the folder;
  - (c) move the folder to the OS Recycle Bin / Trash;
  - (d) (a) by default, plus an optional (c).

  NativePHP 2.3 offers `Shell::trashFile()` (Electron `shell.trashItem`). It is desktop-only and reports no failure, so MDVault checks afterwards that the folder is gone. **Recommended: (d). "Remove vault" unregisters and leaves the files. On desktop an optional checkbox also moves the folder to the Recycle Bin / Trash, checked afterwards and behind safety guards. Permanent deletion is never offered in v1.** If you choose (a) instead, drop the `Trash` contract, `NativeTrash`, the trash tests and the checkbox.
- [ ] **C2: Rename.** Display name only, or also rename the folder on disk. A folder rename can fail when other programs have the folder open (Explorer, VS Code, OneDrive), breaks external shortcuts, and needs DB/FS compensation; it is really a "move". **Recommended: display name and description only in Phase 2. The folder keeps the name it was created with, and the UI shows the real path. Folder rename is deferred together with C4.**
- [ ] **C3: "Add existing folder" in Phase 2?** It is cheap because it reuses the Phase 1 folder picker, and it is the only way to re-add a removed vault (C1) or use folders of Markdown you already have. **Recommended: yes, in Phase 2 (T6), with safety checks: no drive roots, not the storage root or Documents or any folder containing them, no nesting, and the folder must be writable.**
- [ ] **C4: Change vault location / move (§7)**, and "relink a missing vault to a new folder". A move means copying, checking and removing across volumes, handling partial failures and locked files, and none of it is in the §51 acceptance criteria. **Recommended: defer both to a dedicated "vault relocation" item before Phase 6 (backups rely on stable UUID-to-path identity). Until then, a moved folder shows as Missing; "Add existing folder" can re-add it, but under a new UUID.**
- [ ] **C5: `path` vs `relative_path`.** **Recommended:**
  - `path` = canonical absolute path, the only thing used to locate a vault.
  - `relative_path` = forward-slash path relative to the storage root when the vault was created or registered (null if outside it). It is kept for future backup/restore and never used to locate the vault.
  - Changing or resetting the storage root never moves, rewrites or re-resolves existing vaults. They stay where they are and keep working; only new vaults go under the new root. This is consistent with ADR `storage-root-resolution`.
- [ ] **C6: Where "current vault" lives.** **Recommended: the setting `app.current_vault` = vault UUID (group general), through `SettingsService`, not a `vaults.is_current` column.** A single value can't end up with two "current" vaults, and it survives restarts. A stale UUID self-heals to "no vault open".
- [ ] **C7: Vault name rules.** **Recommended: 1–100 characters, valid as a portable folder name (the same rules as the storage folder name, including Windows reserved names on every OS), and unique regardless of letter case.**
- [ ] **C8: Creating over an existing folder.** **Recommended: reuse an existing *empty* folder of the same name (for example one left over by an interrupted create), and refuse a non-empty folder or a file with a message pointing to "Add existing folder".**
- [ ] **C9: After creating or adding a vault.** **Recommended: open it immediately and go to the Workspace.**
- [ ] **C10: New folders and dependencies.** New folders:
  - `app/Http/Requests/Vaults/`
  - `resources/js/pages/vaults/`
  - `resources/js/components/vaults/`
  - `tests/Feature/Vaults/`
  - `tests/Feature/Models/`
  - `tests/Feature/Support/`
  - first use of `database/factories/`

  **No new composer or npm dependencies. Recommended: approve.**

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-27 | Initial plan | — |
