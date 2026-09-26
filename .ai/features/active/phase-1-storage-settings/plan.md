# Plan: Phase 1 — Storage and Settings

## Metadata
- **Feature Name**: Phase 1 — Storage and Settings
- **Feature ID**: mdv-p1
- **Master Plan Phase**: **Phase 1 — Storage and Settings (`docs/Masterplan.md` §50; §8 Default Storage Location; §10 `settings`; §35 settings structure)**
- **Author**: System Analyst
- **Created Date**: 2026-09-27
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED (user approved B1–B4 as recommended on 2026-09-27)

---

## 1. Summary
This plan implements Master Plan Phase 1:
- A sparse, typed `settings` table behind a request-scoped `SettingsService`, with keys, types, groups and defaults defined in a `SettingKey` enum.
- A `StoragePathService` that resolves `<Documents>/MDVault` through a small `UserDirectories` contract (NativePHP documents path, otherwise the OS home directory), and that validates, creates and persists a user-chosen root without ever moving data.
- A desktop-only native folder picker (`NativeDialogService`).
- Theme persistence moves from browser `localStorage`/cookie to SQLite and is rendered server-side.
- The settings UI becomes General, Storage, Editor and Appearance.
- Editor preferences are stored, and the simple ones are applied to the Workspace editor.

---

## 2. Architecture & Design
- **Approach**:
  1. **Settings** (ADR `settings-persistence`):
     - Only keys used by Phase 1 are defined; later phases add enum cases.
     - Values are stored as type-cast strings.
     - Rows exist only for keys the user changed; defaults live in code.
     - The service loads all rows once per request (`scoped` binding) and invalidates on write.
     - Reads fall back to defaults on `QueryException`; writes throw.
  2. **Storage root** (ADR `storage-root-resolution`):
     - `UserDirectories` contract, implemented by `SystemUserDirectories` (native documents disk root, then `USERPROFILE`/`HOMEDRIVE+HOMEPATH`/`HOME` + `Documents`, then null).
     - `StoragePathService` decides the fallback, normalises, validates, creates the directory, probes it and persists.
     - Changing the root never moves anything. The root is the parent for future vaults, and each vault will store its own absolute path (§11).
     - Folder choice: text input everywhere, plus the native dialog in the desktop runtime.
  3. **Theme** (ADR `server-side-theme-persistence`):
     - `HandleAppearance` reads `appearance.theme` from `SettingsService` and shares it to Blade.
     - `<html data-appearance>` plus the `dark` class are rendered server-side.
     - `useAppearance` reads its initial value from `data-appearance`, applies changes immediately, and persists them with a PATCH (reverting on error).
     - The cookie and `localStorage` are removed.
  4. **UI**: four settings pages as vertical slices (route + controller + Form Request + page + test). Backup and Security are omitted from the nav until Phases 6 and 7 (B3).
- **Alternatives Considered**:
  - A JSON blob or a single-row settings table — rejected (breaks §10 key/value/type/group).
  - Seeding every default row in the migration — rejected (defaults duplicated in DB and code; migrations needed to change defaults).
  - Using Laravel Cache for settings — rejected (invalidation complexity for about 10 rows; SQLite is local).
  - Keeping the cookie/`localStorage` theme — rejected (the Master Plan says SQLite stores application settings; there would be two sources of truth).
  - Putting `HOME`/`USERPROFILE` in a config file — rejected (`config:cache` at build time would bake the builder's home into the packaged app).
  - Only a native dialog, with no text input — rejected (not usable or testable in browser dev).
  - Moving existing data on root change — rejected (no vaults exist; Phase 2 owns vault moves).
  - Adding `symfony/filesystem` for `Path::isAbsolute` — rejected (new direct dependency; a 3-line platform check suffices).
- **Decision Records**:
  - `.ai/decisions/settings-persistence.md`
  - `.ai/decisions/storage-root-resolution.md`
  - `.ai/decisions/server-side-theme-persistence.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `settings` | create | `id` (PK); `key` string(100) **unique**; `value` text nullable; `type` string(20); `group` string(30) **index**; `created_at`, `updated_at` |

No other tables. The v1 tables `vaults`, `notes`, `vault_encryption` and `backups` stay absent; a test asserts this.

### Setting keys (Phase 1)
| Enum case (`App\Enums\SettingKey`) | Key | Type | Group | Default | Validation (Form Request) |
|---|---|---|---|---|---|
| `StorageRootPath` | `storage.root_path` | string | storage | `null` (effective default via `StoragePathService`) | `required|string|max:1024` |
| `AppearanceTheme` | `appearance.theme` | string | appearance | `'system'` | `required`, `Rule::enum(Theme::class)` |
| `EditorFontSize` | `editor.font_size` | integer | editor | `16` | `required|integer|between:12,24` |
| `EditorFontFamily` | `editor.font_family` | string | editor | `'sans'` | `required`, `Rule::enum(EditorFontFamily::class)` |
| `EditorLineHeight` | `editor.line_height` | float | editor | `1.6` | `required|numeric|between:1.2,2.2` |
| `EditorWordWrap` | `editor.word_wrap` | boolean | editor | `true` | `required|boolean` |
| `EditorShowLineNumbers` | `editor.show_line_numbers` | boolean | editor | `false` | `required|boolean` |
| `CheckExternalChanges` | `app.check_external_changes` | boolean | general | `true` | `required|boolean` |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Migration (new) | `database/migrations/<ts>_create_settings_table.php` | `settings` schema above |
| Model (new) | `app/Models/Setting.php` | `$fillable = ['key','value','type','group']`; `casts()`: `type` → `SettingType`, `group` → `SettingGroup`. No other logic. |
| Enum (new) | `app/Enums/SettingType.php` | `String`, `Integer`, `Float`, `Boolean`, `Json` (string-backed); `accepts(mixed): bool`, `serialize(mixed): string`, `cast(string): mixed` (throws `\UnexpectedValueException` on uncastable) |
| Enum (new) | `app/Enums/SettingGroup.php` | `General`, `Storage`, `Editor`, `Appearance`, `Backup`, `Security` (values lowercase) |
| Enum (new) | `app/Enums/SettingKey.php` | Cases in the table above; `type()`, `group()`, `default()`, `field()` (the part after the first `.`) |
| Enum (new) | `app/Enums/Theme.php` | `Light='light'`, `Dark='dark'`, `System='system'` |
| Enum (new) | `app/Enums/EditorFontFamily.php` | `Sans='sans'`, `Serif='serif'`, `Mono='mono'` |
| Service (new) | `app/Services/SettingsService.php` | Typed settings API (see T3) |
| Contract (new) | `app/Contracts/UserDirectories.php` | `documentsPath(): ?string` |
| Support (new) | `app/Support/SystemUserDirectories.php` | Runtime OS documents-dir resolution (see T4) |
| Service (new) | `app/Services/StoragePathService.php` | Default, effective, validate, change and reset root (see T5) |
| Exception (new) | `app/Exceptions/InvalidStorageRootException.php` | Domain exception with user-facing messages |
| Service (new) | `app/Services/NativeDialogService.php` | Desktop folder picker (see T6) |
| Provider (modify) | `app/Providers/AppServiceProvider.php` | `scoped(SettingsService::class)`; bind `UserDirectories` → `SystemUserDirectories` |
| Middleware (modify) | `app/Http/Middleware/HandleAppearance.php` | Share the `appearance` theme from `SettingsService` |
| Bootstrap (modify) | `bootstrap/app.php` | `encryptCookies(except: ['sidebar_state'])` (drop `appearance`) |
| Controllers (new) | `app/Http/Controllers/Settings/{General,Storage,Editor,Appearance}Controller.php` | Thin: edit / update (+ Storage `browse`, `destroy`) |
| Form Requests (new) | `app/Http/Requests/Settings/{UpdateGeneralSettingsRequest,UpdateStorageRootRequest,UpdateEditorSettingsRequest,UpdateAppearanceRequest}.php` | Validation per table above |
| Controller (modify) | `app/Http/Controllers/WorkspaceController.php` | Add `editor` prop |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Types (new) | `resources/js/types/settings.ts` | `EditorFontFamily`, `EditorPreferences`, `GeneralSettings`, `StorageSettings`; export from `types/index.ts` |
| Composable (modify) | `resources/js/composables/useAppearance.ts` | Server-backed theme (see T7) |
| View (modify) | `resources/views/app.blade.php` | `data-appearance` attribute; `dark` class from the shared value |
| Page (modify) | `resources/js/pages/settings/Appearance.vue` | Single root (QA-04), `theme` prop |
| Page (new) | `resources/js/pages/settings/Storage.vue` | Root path form, status, Choose folder (desktop), Reset |
| Page (new) | `resources/js/pages/settings/Editor.vue` | Editor preferences form |
| Page (new) | `resources/js/pages/settings/General.vue` | External-changes toggle + About card |
| Layout (modify) | `resources/js/layouts/settings/Layout.vue` | Nav: General, Storage, Editor, Appearance, with lucide icons |
| Component (modify) | `resources/js/components/AppSidebar.vue` | Settings footer link → `settings.index` (import change only; QA-04 restructure stays Phase 2) |
| Component (modify) | `resources/js/components/editor/TiptapEditor.vue` | Optional `preferences` prop applied as style/classes |
| Page (modify) | `resources/js/pages/Workspace.vue` | Pass the `editor` prop to `TiptapEditor` |
| CSS (modify) | `resources/css/app.css` | `.tiptap-nowrap .tiptap-content { white-space: pre; overflow-x: auto; }` |

### Routes
All in `routes/settings.php`, `web` middleware (no auth).

| Method | URI | Name | Controller@action |
|---|---|---|---|
| GET | `/settings` | `settings.index` | `Route::redirect` → `/settings/general` |
| GET | `/settings/general` | `settings.general.edit` | `Settings\GeneralController@edit` |
| PATCH | `/settings/general` | `settings.general.update` | `Settings\GeneralController@update` |
| GET | `/settings/storage` | `settings.storage.edit` | `Settings\StorageController@edit` |
| PATCH | `/settings/storage` | `settings.storage.update` | `Settings\StorageController@update` |
| POST | `/settings/storage/browse` | `settings.storage.browse` | `Settings\StorageController@browse` |
| DELETE | `/settings/storage` | `settings.storage.destroy` | `Settings\StorageController@destroy` |
| GET | `/settings/editor` | `settings.editor.edit` | `Settings\EditorController@edit` |
| PATCH | `/settings/editor` | `settings.editor.update` | `Settings\EditorController@update` |
| GET | `/settings/appearance` | `settings.appearance.edit` | `Settings\AppearanceController@edit` |
| PATCH | `/settings/appearance` | `settings.appearance.update` | `Settings\AppearanceController@update` |

The `settings.` prefix keeps Wayfinder output in `@/routes/settings/*`. This avoids mixing with the framework's `storage.local` routes in `@/routes/storage`. The old names `settings` and `appearance.edit` are removed; update all importers (`AppSidebar.vue`, `layouts/settings/Layout.vue`, `pages/settings/Appearance.vue`, `AppearanceSettingsTest.php`).

---

## 3. Implementation Tasks
*Ordered. Each task leaves the app working and the suite green. Run `vendor/bin/pint --dirty --format agent` and the task's tests after each task. After any route change run `php artisan wayfinder:generate --with-form --no-interaction`. Pages rendered by a controller must exist before that controller's test runs (`ensure_pages_exist`), so T7–T10 are vertical slices.*

- [ ] **T0 — Preconditions (orchestrator/user)**
  - Get the user's answers to B1–B4 (§6).
  - Suggested: `git add -A && git commit -m "Before Phase 1"`.
  - Covers: —

- [ ] **T1 — Runtime config regression test (Phase 0 QA-01)**
  - Command: `php artisan make:test RuntimeConfigTest --pest --no-interaction` → `tests/Feature/RuntimeConfigTest.php`
  - Details: one test with four `expect()` assertions, each using `toBe` (strict):
    - `config('nativephp.app_id')` → `'com.mdvault.app'`
    - `config('nativephp.version')` → `'0.1.0'`
    - `config('nativephp.updater.enabled')` → `false`
    - `config('inertia.ssr.enabled')` → `false`
  - If `updater.enabled` resolves to a non-bool from `env()`, fix the config default to a real `false` rather than weakening the assertion. Record any such fix in `implementation.md`.
  - Covers: FR-12

- [ ] **T2 — `settings` schema, model and enums**
  - Commands:
    - `php artisan make:model Setting -m --no-interaction` (recreates `app/Models/`, B1)
    - `php artisan make:enum Enums/SettingType --no-interaction`, `make:enum Enums/SettingGroup`, `make:enum Enums/SettingKey`, `make:enum Enums/Theme`, `make:enum Enums/EditorFontFamily` (all `--no-interaction`). If `make:enum` is unavailable, create the files by hand under `app/Enums/`.
  - Migration: exactly the schema in §2 (`key` string(100) unique, `value` text nullable, `type` string(20), `group` string(30) indexed, timestamps). `down()` drops `settings`.
  - `Setting` model: `$fillable`, and a `casts()` method mapping `type` → `SettingType::class` and `group` → `SettingGroup::class`. No accessors, scopes or factory.
  - `SettingType` (string-backed `string|integer|float|boolean|json`):
    - `accepts(mixed $v): bool`:
      - String → `is_string`
      - Integer → `is_int`
      - Float → `is_int || is_float`
      - Boolean → `is_bool`
      - Json → `is_array`
    - `serialize(mixed $v): string`:
      - string as-is
      - int → `(string)`
      - float → `(string) (float)`
      - bool → `'1'`/`'0'`
      - array → `json_encode($v, JSON_THROW_ON_ERROR)`
    - `cast(string $raw): mixed`:
      - String → raw
      - Integer → `filter_var(FILTER_VALIDATE_INT)`
      - Float → `is_numeric` → `(float)`
      - Boolean → `'1'` true / `'0'` false
      - Json → `json_decode(assoc, JSON_THROW_ON_ERROR)` must yield an array
      - Anything else throws `\UnexpectedValueException`.
  - `SettingKey`: cases and values per the §2 table; `type()`, `group()` and `default()` via `match`; `field(): string` = `Str::after($this->value, '.')`.
  - `Theme`, `EditorFontFamily`: cases per §2.
  - `tests/Feature/DatabaseSchemaTest.php`:
    - Add `'settings'` to the present-tables dataset.
    - Add a test that `Schema::hasColumns('settings', ['id','key','value','type','group','created_at','updated_at'])` is true.
    - Add a test that `vaults`, `notes`, `vault_encryption` and `backups` do **not** exist (Phase 1 scope guard; Phase 2 edits this).
  - Run `php artisan migrate --no-interaction`. The native dev DB migrates on the next `native:dev` start (or run `php artisan native:migrate --no-interaction`).
  - Covers: FR-01

- [ ] **T3 — `SettingsService`**
  - Command: `php artisan make:class Services/SettingsService --no-interaction`
  - API (`final`, constructor `private readonly DatabaseManager $database`):
    ```php
    public function get(SettingKey $key): mixed;              // stored cast value, or $key->default()
    public function string(SettingKey $key): ?string;         // LogicException if $key->type() !== String
    public function integer(SettingKey $key): int;            // LogicException if not Integer
    public function float(SettingKey $key): float;            // LogicException if not Float
    public function boolean(SettingKey $key): bool;           // LogicException if not Boolean
    public function set(SettingKey $key, mixed $value): void; // null => forget(); !accepts => InvalidArgumentException
    /** @param array<string, mixed> $values keyed by SettingKey value, e.g. 'editor.font_size' */
    public function setMany(array $values): void;             // SettingKey::from() each (ValueError on unknown); validate ALL first; then one transaction
    public function forget(SettingKey $key): void;
    public function has(SettingKey $key): bool;               // a row exists
    /** @return array<string, mixed> field => value, every key of the group with defaults filled */
    public function group(SettingGroup $group): array;
    ```
  - Behaviour:
    - A private `load()` memoises `array<string, array{value: ?string, type: string}>` from a single `Setting::query()->get(['key','value','type'])`.
    - `load()` catches `Illuminate\Database\QueryException`: it calls `report($e)` and memoises `[]`, so reads return defaults.
    - `set`/`setMany`/`forget` reset the memo to `null` after writing.
    - Writes use `Setting::query()->updateOrCreate(['key' => $key->value], ['value' => $type->serialize($v), 'type' => $key->type(), 'group' => $key->group()])` and are not fail-soft.
    - `setMany` wraps its writes in `$this->database->connection()->transaction(...)`.
    - When reading, if the stored `type` ≠ `$key->type()->value`, `value` is null, or `cast()` throws, return `$key->default()`. Never throw on read.
    - Rows whose key is not a `SettingKey` case are ignored.
    - Floats: `float()` returns `(float)`; the `EditorLineHeight` default is `1.6`.
  - `AppServiceProvider::register()`: `$this->app->scoped(SettingsService::class);` (legitimate per-request memo; `scoped` is flushed between queue jobs).
  - `tests/Unit/ArchitectureTest.php`: add
    ```php
    arch('settings are only accessed through SettingsService')
        ->expect('App\Models\Setting')->toOnlyBeUsedIn('App\Services\SettingsService');
    arch('enums folder only contains enums')->expect('App\Enums')->toBeEnums();
    ```
  - Tests (`php artisan make:test Services/SettingsServiceTest --pest --no-interaction`):
    1. Every key returns its default on an empty table (dataset over `SettingKey::cases()`).
    2. Round-trip per type: string, integer, float, boolean `true` and `false`. Each writes exactly one row with the right `type`/`group` strings.
    3. Setting twice updates the same row (`Setting::count() === 1`).
    4. `set` with the wrong type (dataset: `EditorFontSize` ← `'16'`, `EditorWordWrap` ← `1`, `AppearanceTheme` ← `true`) throws `InvalidArgumentException` and writes nothing.
    5. `set(..., null)` and `forget()` remove the row and restore the default.
    6. `setMany` with one invalid value writes nothing (atomic; validated before the transaction).
    7. `setMany` with an unknown key throws `ValueError`.
    8. Corrupt stored value (insert a row via `Setting::query()->create` with `value='abc'` for `editor.font_size`) → `get` returns `16`.
    9. A row with a mismatched `type` → default.
    10. Memo is invalidated: `get` → `set` → `get` returns the new value.
    11. Persistence: after `set`, a **new** instance (`app()->forgetScopedInstances(); app(SettingsService::class)`) returns the value (FR-02).
    12. `group(SettingGroup::Editor)` returns exactly `['font_size'=>16,'font_family'=>'sans','line_height'=>1.6,'word_wrap'=>true,'show_line_numbers'=>false]`, merged with stored overrides.
    13. With the table unavailable (`Schema::drop('settings')` inside the test), `get(AppearanceTheme)` returns `'system'` and does not throw. Use `Exceptions::fake()` and assert reported, or simply assert no exception.
    14. A typed accessor on the wrong type (`integer(SettingKey::AppearanceTheme)`) throws `LogicException`.
  - Covers: FR-01, FR-02, FR-13

- [ ] **T4 — `UserDirectories` contract and system implementation**
  - Create `app/Contracts/UserDirectories.php`:
    ```php
    interface UserDirectories
    {
        /** Absolute path of the current user's Documents directory, or null if it cannot be determined. Never creates anything. */
        public function documentsPath(): ?string;
    }
    ```
  - Create `app/Support/SystemUserDirectories.php` (`final`):
    - Constructor: `private readonly Repository $config`, `private readonly array $environment` (`@param array<string, string>`), `private readonly string $osFamily`.
    - `documentsPath()`:
      1. If `config('filesystems.disks.documents.root')` is a non-empty string, return it. NativePHP sets this at runtime from `NATIVEPHP_DOCUMENTS_PATH` (Electron `app.getPath('documents')`).
      2. Home directory:
         - if `$osFamily === 'Windows'`: `USERPROFILE`, then `HOMEDRIVE`.`HOMEPATH` (both non-empty), then `HOME`;
         - otherwise `HOME`.
         - Trim trailing `\` and `/`.
      3. If a home was found, return `home . sep . 'Documents'`, where `sep` is `'\\'` on Windows and `'/'` otherwise. Otherwise return `null`.
    - No `C:\` or other literal platform paths anywhere.
  - `AppServiceProvider::register()`: `$this->app->bind(UserDirectories::class, fn ($app) => new SystemUserDirectories($app->make('config'), getenv(), PHP_OS_FAMILY));`
    - Runtime `getenv()` is deliberate: do **not** move this into a config file, because `config:cache` would bake the build machine's home into the packaged app.
  - Tests: `tests/Unit/Support/SystemUserDirectoriesTest.php` (pure unit; build `new \Illuminate\Config\Repository([...])`):
    - The native documents disk wins over env.
    - Windows `USERPROFILE` → `…\Documents`.
    - Windows `HOMEDRIVE`+`HOMEPATH` fallback.
    - Linux/Darwin `HOME` → `/…/Documents`.
    - A trailing separator on home is trimmed.
    - No env and no disk → `null`.
  - Covers: FR-04

- [ ] **T5 — `StoragePathService` and `InvalidStorageRootException`**
  - Commands: `php artisan make:class Services/StoragePathService --no-interaction`; `php artisan make:exception InvalidStorageRootException --no-interaction` (then make it `final` and extend `\RuntimeException`).
  - `InvalidStorageRootException` named constructors with user-facing messages. Messages must not include stack traces or anything beyond the user's own path.

    | Constructor | Message |
    |---|---|
    | `notAbsolute()` | "Enter a full (absolute) folder path." |
    | `isFile()` | "That path points to a file, not a folder." |
    | `cannotCreate()` | "The folder could not be created. Check the path and your permissions." |
    | `notWritable()` | "MDVault cannot write to that folder." |
    | `invalid()` | "The folder path is invalid." (empty or contains a NUL byte) |

  - `StoragePathService` (`final`):
    - Constructor: `SettingsService $settings`, `UserDirectories $directories`, `Illuminate\Filesystem\Filesystem $files`, `Illuminate\Contracts\Foundation\Application $app`.
    - `public const FOLDER_NAME = 'MDVault';`
    - `defaultRootPath(): string` = `rtrim(($this->directories->documentsPath() ?? $this->app->storagePath('app')), '\\/') . DIRECTORY_SEPARATOR . self::FOLDER_NAME`. It is never created or persisted here.
    - `rootPath(): string` = `$settings->string(SettingKey::StorageRootPath) ?? $this->defaultRootPath()`.
    - `isUsingDefault(): bool` = `! $settings->has(SettingKey::StorageRootPath)`.
    - `summary(): array{root_path: string, default_path: string, is_default: bool, exists: bool, writable: bool}`, where `exists` = `is_dir(root)` and `writable` = `exists && is_writable(root)`. No probe write here.
    - `normalize(string $path): string`:
      - `trim`
      - throw `invalid()` if empty or it contains `"\0"`
      - on Windows, replace `/` with `\`
      - collapse repeated separators except a leading UNC `\\`
      - `rtrim` trailing separators unless the result is a root (`/` or `X:\`)
    - `isAbsolute(string $path): bool`:
      - Windows (`PHP_OS_FAMILY === 'Windows'`): `/^[A-Za-z]:\\\\/` or UNC `/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/`
      - otherwise: `str_starts_with($path, '/')`
    - `changeRoot(string $path): string`:
      1. `$p = normalize($path)`; if `! isAbsolute($p)` → `notAbsolute()`.
      2. If `$files->exists($p) && ! $files->isDirectory($p)` → `isFile()`.
      3. If not a directory: `$files->makeDirectory($p, 0755, true, true)` (force=true, so it returns bool with no warning). If that is false or `! isDirectory` → `cannotCreate()`.
      4. Probe: `$probe = $p . DIRECTORY_SEPARATOR . '.mdvault-write-test-' . Str::random(12)`. Wrap `$files->put($probe, '')` in try/catch(`\Throwable`); `put` returning `false` or throwing → `notWritable()`. Always `$files->delete($probe)` in `finally` if it exists.
      5. `$canonical = realpath($p) ?: $p`.
      6. If `$canonical === realpath(defaultRootPath())` (when the default exists) → `$settings->forget(StorageRootPath)`; else `$settings->set(StorageRootPath, $canonical)`.
      7. Return `$canonical`.
      - Order is FS first, DB second (§43). A DB failure can leave only an empty created directory. Document this in the PHPDoc.
    - `resetToDefault(): void` → `$settings->forget(StorageRootPath)`. It creates nothing.
    - **Never** move, copy, rename or delete anything other than its own probe file (FR-08).
  - `tests/Pest.php`: add a helper
    ```php
    function fakeDocumentsDirectory(?string $path): void
    {
        app()->instance(\App\Contracts\UserDirectories::class, new class($path) implements \App\Contracts\UserDirectories {
            public function __construct(private ?string $path) {}
            public function documentsPath(): ?string { return $this->path; }
        });
    }
    ```
    Every test touching storage must call it with a temp directory, so tests never touch the real Documents.
  - Tests (`php artisan make:test Services/StoragePathServiceTest --pest --no-interaction`):
    - Setup: `beforeEach` creates `$this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-tests-'.Str::random(8)` and calls `fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents')`. `afterEach` runs `File::deleteDirectory($this->tmp)`. Compare paths via `realpath()`.
    1. Default = fake Documents + `MDVault`; not created on disk; `isUsingDefault()` true.
    2. Fake documents `null` → default = `storage_path('app').DIRECTORY_SEPARATOR.'MDVault'`.
    3. `changeRoot` on a missing nested path creates it and persists the canonical path; `rootPath()` returns it; no probe file remains.
    4. `changeRoot` on an existing empty dir → persisted.
    5. Trailing separator trimmed.
    6. Relative paths rejected: dataset `relative/dir`, `./x`, `..`; plus `\no-drive` on Windows (`->onlyOnWindows()` sub-dataset).
    7. An existing file → `isFile`.
    8. A path under an existing file (`$tmp/blocker/child`) → `cannotCreate`.
    9. Not writable → `notWritable`: `chmod 0555`; `->skipOnWindows()`, and skip if `function_exists('posix_geteuid') && posix_geteuid() === 0`.
    10. An empty string or whitespace → `invalid`.
    11. Choosing the default path → forgets the setting (`isUsingDefault()` true).
    12. `resetToDefault()` after a custom root → default again; the custom directory still exists.
    13. FR-08: put `old.md` in custom root A, change to B → `A/old.md` still exists with the same contents, and B contains nothing (no probe leftovers).
    14. `summary()` shape and `exists`/`writable` flags for a missing and an existing root.
  - Covers: FR-04, FR-05, FR-07, FR-08

- [ ] **T6 — `NativeDialogService`**
  - Command: `php artisan make:class Services/NativeDialogService --no-interaction`
  - `final`; constructor `private readonly Repository $config`.
  - `isAvailable(): bool` = `(bool) $this->config->get('nativephp-internal.running')`.
  - `chooseDirectory(string $title, ?string $defaultPath = null): ?string`:
    - Return `null` if not available, with no HTTP call.
    - Otherwise `$dialog = Dialog::new()->title($title)->button('Select folder')->properties(['openDirectory', 'createDirectory']);`
    - If `$defaultPath !== null && is_dir($defaultPath)`, call `->defaultPath($defaultPath)`.
    - `$result = $dialog->open();` Return `is_string($result) && $result !== '' ? $result : null`. A cancel returns an empty array, which becomes null.
    - Use `Dialog::new()` inside the method, not constructor-injected `Client`. `Client` captures `Http` fake stubs when constructed.
  - Arch rule: `arch('native dialogs only via NativeDialogService')->expect('Native\Desktop\Dialog')->toOnlyBeUsedIn('App\Services\NativeDialogService');`
  - Tests (`php artisan make:test Services/NativeDialogServiceTest --pest --no-interaction`):
    - `Http::fake()` **before** calling the service.
    1. Not available by default; `chooseDirectory` returns null and `Http::assertNothingSent()`.
    2. `config(['nativephp-internal.running' => true])` + `Http::fake(['*dialog/open' => Http::response(['result' => ['/picked/dir']])])` → returns `'/picked/dir'`. `Http::assertSent` for a URL ending with `dialog/open` whose `properties` contain `openDirectory` and whose `title` matches.
    3. Cancel (`['result' => []]`) → null.
    4. `defaultPath` is sent only when the directory exists (use a temp dir versus a missing path).
  - Covers: FR-06, FR-13

- [ ] **T7 — Appearance slice: server-side theme (and QA-04 partial)**
  - Commands:
    - `php artisan make:controller Settings/AppearanceController --no-interaction`
    - `php artisan make:request Settings/UpdateAppearanceRequest --no-interaction`
  - `routes/settings.php`: replace the `Route::inertia('settings/appearance', …)` line with the GET `settings.appearance.edit` and PATCH `settings.appearance.update` routes. Rename the existing redirect to `->name('settings.index')`; it still points at `/settings/appearance` until T10.
  - `AppearanceController`:
    - `edit(SettingsService $settings)` → `Inertia::render('settings/Appearance', ['theme' => $settings->string(SettingKey::AppearanceTheme)])`.
    - `update(UpdateAppearanceRequest $request, SettingsService $settings)` → `set(AppearanceTheme, $request->validated('theme'))`, then `return back();` with no toast (instant UI).
  - `UpdateAppearanceRequest`: `authorize()` true; `theme` → `['required', Rule::enum(Theme::class)]`.
  - `HandleAppearance`:
    - Constructor-inject `SettingsService`.
    - `View::share('appearance', (Theme::tryFrom((string) $this->settings->string(SettingKey::AppearanceTheme)) ?? Theme::System)->value)`.
    - Stop reading the cookie.
  - `bootstrap/app.php`: `encryptCookies(except: ['sidebar_state'])`.
  - `resources/views/app.blade.php`:
    - `<html lang=… data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>`
    - The inline script reads `document.documentElement.dataset.appearance` instead of the Blade-interpolated string (same logic).
  - `resources/js/composables/useAppearance.ts`:
    - Remove `setCookie` and all `localStorage` usage.
    - `readServerAppearance(): Appearance`: `document.documentElement.dataset.appearance` if it is one of light/dark/system, else `'system'` (SSR-safe guard).
    - Module ref initialised with `readServerAppearance()`.
    - `initializeTheme()`: `updateTheme(readServerAppearance())`; the system-change listener uses the current `appearance.value`.
    - `updateAppearance(value)`:
      - Remember `previous`.
      - Set the ref and `document.documentElement.dataset.appearance = value`, then `updateTheme(value)`.
      - Then `router.patch(update.url(), { theme: value }, { preserveScroll: true, preserveState: true, onError: () => { /* restore previous: ref, dataset, updateTheme */ } })`.
      - `update` comes from `@/actions/App/Http/Controllers/Settings/AppearanceController`.
    - Keep the exported `UseAppearanceReturn` type and signatures.
  - `resources/js/pages/settings/Appearance.vue`:
    - **Single root**: wrap everything in `<div class="space-y-6">` containing `<Head>`, the `sr-only` `<h1>`, `Heading` and `AppearanceTabs`.
    - Accept the prop `theme: Appearance` (unused visually; keeps the page and test in sync).
    - Breadcrumb href from `@/routes/settings/appearance` `edit()`.
  - Update importers of the old names: `layouts/settings/Layout.vue` and `components/AppSidebar.vue` import `edit` from `@/routes/settings/appearance` (T10 retargets them).
  - Regenerate Wayfinder: `php artisan wayfinder:generate --with-form --no-interaction`.
  - Tests: rewrite `tests/Feature/Settings/AppearanceSettingsTest.php`:
    1. `GET route('settings.appearance.edit')` → 200, component `settings/Appearance`, `theme` `'system'`.
    2. PATCH `{theme: 'dark'}` → redirect; `SettingsService::string(AppearanceTheme) === 'dark'`.
    3. PATCH invalid (`'blue'`, missing) → `assertSessionHasErrors('theme')`; value unchanged.
    4. With theme `dark` stored, `GET route('workspace')` `assertSee('data-appearance="dark"', false)` and `assertSee('class="dark"', false)`.
    5. Default render has `data-appearance="system"` and no `class="dark"`.
    6. The redirect test stays until T10: `/settings` → `/settings/appearance`.
  - Covers: FR-09, FR-03 (partial), FR-02, QA-04 (Appearance)

- [ ] **T8 — Storage slice**
  - Commands:
    - `php artisan make:controller Settings/StorageController --no-interaction`
    - `php artisan make:request Settings/UpdateStorageRootRequest --no-interaction`
  - Routes: `settings.storage.edit` (GET), `settings.storage.update` (PATCH), `settings.storage.browse` (POST `settings/storage/browse`), `settings.storage.destroy` (DELETE).
  - `StorageController`:
    - `edit(StoragePathService $paths, NativeDialogService $dialogs)` → `Inertia::render('settings/Storage', ['storage' => $paths->summary(), 'canBrowse' => $dialogs->isAvailable()])`.
    - `update(UpdateStorageRootRequest $request, StoragePathService $paths)`:
      - `try { $paths->changeRoot($request->validated('root_path')); } catch (InvalidStorageRootException $e) { throw ValidationException::withMessages(['root_path' => $e->getMessage()]); }`
      - `Inertia::flash('toast', ['type' => 'success', 'message' => 'Storage location updated.']); return back();`
    - `browse(StoragePathService $paths, NativeDialogService $dialogs)`:
      - `abort_unless($dialogs->isAvailable(), 404);`
      - `$chosen = $dialogs->chooseDirectory('Choose where MDVault stores your vaults', $paths->rootPath());`
      - If null → `back()`.
      - Otherwise use the same try/catch and flash as `update`.
    - `destroy(StoragePathService $paths)` → `resetToDefault()`, flash `'Storage location reset to default.'`, `back()`.
    - No filesystem calls in the controller (arch rule).
  - `UpdateStorageRootRequest`: `root_path` → `['required', 'string', 'max:1024']`. Semantic path validation lives in the service.
  - `resources/js/pages/settings/Storage.vue`:
    - Single root `<div class="space-y-6">` with `<Head title="Storage settings" />`, sr-only h1 and `Heading` ("Storage location" / "Where MDVault creates new vaults.").
    - Props: `storage: StorageSettings`, `canBrowse: boolean`.
    - `useForm({ root_path: props.storage.root_path })`. Watch `props.storage.root_path` and set `form.root_path` plus `form.defaults()`.
    - `<Label for="root_path">`, `<Input id="root_path" v-model="form.root_path" />`, `<InputError :message="form.errors.root_path" />`.
    - Buttons:
      - **Save**: `form.submit(update(), { preserveScroll: true })`, or `form.patch(update.url(), …)`; follow the `wayfinder-development` skill.
      - **Choose folder…** (only `v-if="canBrowse"`): `router.post(browse.url(), {}, { preserveScroll: true })`, with a local `browsing` ref for a Spinner/disabled state while the dialog is open. Validation errors from `browse` land in `usePage().props.errors.root_path`; show them via the same `InputError` (e.g. `form.errors.root_path ?? page.props.errors.root_path`).
      - **Use default location** (only `v-if="!storage.is_default"`): `router.delete(destroy.url(), { preserveScroll: true })`.
    - Status lines:
      - Current path.
      - "Default: {default_path}".
      - A badge "Folder exists" / "Will be created when needed".
      - When it exists but is not writable: a warning "MDVault cannot write to this folder".
    - Help text: "Changing this does not move existing files. The folder is created if it doesn't exist."
    - No path manipulation in Vue.
  - `resources/js/types/settings.ts`: add `StorageSettings` (see §2) and export it from `types/index.ts`.
  - `layouts/settings/Layout.vue`: add a "Storage" nav item (`HardDrive` icon) → `@/routes/settings/storage` `edit()`.
  - Regenerate Wayfinder.
  - Tests (`php artisan make:test Settings/StorageSettingsTest --pest --no-interaction`; `beforeEach` temp dir + `fakeDocumentsDirectory`, `afterEach` cleanup):
    1. GET → component `settings/Storage`; `storage.is_default` true; `storage.default_path` ends with `MDVault`; `canBrowse` false.
    2. PATCH a new temp path → redirect; the directory exists; the setting equals `realpath`.
    3. PATCH `relative/dir` → `assertSessionHasErrors('root_path')`; unchanged.
    4. PATCH empty → error.
    5. PATCH a file path → error.
    6. DELETE → default restored (`has()` false).
    7. POST browse in browser runtime → 404.
    8. POST browse with `config(['nativephp-internal.running' => true])` + `Http::fake(['*dialog/open' => Http::response(['result' => [$dir]])])` → persisted `realpath($dir)`.
    9. Browse cancel (`result: []`) → unchanged, redirect.
    10. Browse picking a file path → session error `root_path`.
    11. With running=true, GET shows `canBrowse` true.
    - Note: `nativephp-internal.running=true` in a test does not trigger NativePHP's boot rewrites, which happen only at provider registration. Only the flag is read.
  - Covers: FR-03, FR-05, FR-06, FR-07, FR-08

- [ ] **T9 — Editor slice and Workspace preferences**
  - Commands:
    - `php artisan make:controller Settings/EditorController --no-interaction`
    - `php artisan make:request Settings/UpdateEditorSettingsRequest --no-interaction`
  - Routes: `settings.editor.edit` (GET), `settings.editor.update` (PATCH).
  - `EditorController`:
    - `edit` → `Inertia::render('settings/Editor', ['preferences' => $settings->group(SettingGroup::Editor)])`.
    - `update` → `$settings->setMany([...])` mapping each validated field to its `SettingKey` value. Cast `line_height` to float and `font_size` to int before calling. Flash toast `'Editor preferences saved.'`, `back()`.
  - `UpdateEditorSettingsRequest`: rules per the §2 table for `font_size`, `font_family`, `line_height`, `word_wrap`, `show_line_numbers`.
  - `resources/js/pages/settings/Editor.vue`:
    - Single root; `Head`, sr-only h1, `Heading` ("Editor" / "Customise how notes look while editing.").
    - `useForm({...props.preferences})`.
    - Controls:
      - Font size: `Input type="number" min=12 max=24` with `v-model.number`.
      - Font family: shadcn `Select` with Sans / Serif / Monospace.
      - Line height: `Input type="number" step=0.1 min=1.2 max=2.2` with `v-model.number`.
      - Word wrap: `Checkbox`.
      - Show line numbers: `Checkbox`, with helper text "Saved for a future editor update".
    - Each control has a `Label` and an `InputError`. Submit via PATCH with `preserveScroll`.
  - `types/settings.ts`: `EditorFontFamily = 'sans' | 'serif' | 'mono'`; `EditorPreferences = { font_size: number; font_family: EditorFontFamily; line_height: number; word_wrap: boolean; show_line_numbers: boolean }`.
  - `WorkspaceController`: inject `SettingsService`; add `'editor' => $settings->group(SettingGroup::Editor)`.
  - `pages/Workspace.vue`: prop `editor: EditorPreferences`; `<TiptapEditor :content="demoContent" :preferences="editor" />`.
  - `components/editor/TiptapEditor.vue`: optional prop `preferences?: EditorPreferences`. On the existing root div:
    - `:style="preferences ? { fontSize: preferences.font_size + 'px', lineHeight: String(preferences.line_height) } : undefined"`
    - `:class` adds `font-sans` / `font-serif` / `font-mono` from `font_family`, and `tiptap-nowrap` when `word_wrap === false`.
    - It stays presentation-only, and the single root is kept.
  - `resources/css/app.css`: add `.tiptap-nowrap .tiptap-content { white-space: pre; overflow-x: auto; }` in the existing components layer. Its specificity beats ProseMirror's `.ProseMirror { white-space: pre-wrap }`.
  - `layouts/settings/Layout.vue`: add an "Editor" nav item (`PenLine` icon).
  - Regenerate Wayfinder.
  - Tests:
    - `php artisan make:test Settings/EditorSettingsTest --pest --no-interaction`:
      - GET → defaults in `preferences`.
      - PATCH valid (`20, 'mono', 1.8, false, true`) → persisted (typed accessors return int 20, float 1.8, bool false).
      - Validation dataset → `assertSessionHasErrors(field)`: `font_size` 11/25/'abc'; `font_family` 'comic'; `line_height` 1.0/3; `word_wrap` 'maybe'; missing fields.
    - `tests/Feature/WorkspaceTest.php`: the existing test also asserts `editor.font_size` 16 and `editor.word_wrap` true. A new test: after `set(EditorFontSize, 20)`, the Workspace prop `editor.font_size` is 20.
  - Covers: FR-10, FR-03, FR-02

- [ ] **T10 — General slice, settings index and navigation**
  - Commands:
    - `php artisan make:controller Settings/GeneralController --no-interaction`
    - `php artisan make:request Settings/UpdateGeneralSettingsRequest --no-interaction`
  - Routes: `settings.general.edit` (GET), `settings.general.update` (PATCH). Retarget the redirect: `Route::redirect('settings', '/settings/general')->name('settings.index')`.
  - `GeneralController`:
    - `edit(SettingsService $settings, SystemStatusService $status)` → `Inertia::render('settings/General', ['settings' => $settings->group(SettingGroup::General), 'status' => $status->summary()])`.
    - `update` → `set(CheckExternalChanges, $request->boolean('check_external_changes'))` (validated `required|boolean`), flash `'Settings saved.'`, `back()`.
  - `resources/js/pages/settings/General.vue`:
    - Single root; `Head`, sr-only h1, `Heading` ("General" / "Application behaviour and information.").
    - A Checkbox "Detect changes made outside MDVault", with helper text "Takes effect once vaults are available." Saved via `useForm` PATCH.
    - A Card "About": application, version, runtime (Desktop/Browser) and database driver from `status`.
  - `types/settings.ts`: `GeneralSettings = { check_external_changes: boolean }`.
  - `layouts/settings/Layout.vue`: final `sidebarNavItems` order General (`SlidersHorizontal`), Storage (`HardDrive`), Editor (`PenLine`), Appearance (`Palette`). No Backup/Security (B3).
  - `components/AppSidebar.vue`: the footer Settings link uses `index` from `@/routes/settings` (import `{ index as settingsIndex }`). Import change only; the multi-root restructure is deferred to Phase 2 per Phase 0 Revision 1.1.
  - Regenerate Wayfinder; grep that no stale `@/routes/appearance` import remains.
  - Tests:
    - `php artisan make:test Settings/GeneralSettingsTest --pest --no-interaction`:
      - GET → component `settings/General`, `settings.check_external_changes` true, `status.database.driver` sqlite.
      - PATCH false → persisted false.
      - PATCH 'nope' → error.
    - `AppearanceSettingsTest`: update the redirect test so `/settings` redirects to `route('settings.general.edit')`.
    - `tests/Feature/WorkspaceTest.php`: keep the removed-URI dataset.
    - A new `tests/Feature/Settings/SettingsNavigationTest.php`: dataset of the four `settings.*.edit` route names → 200 with the matching component (FR-03).
  - Covers: FR-03, FR-11, FR-02

- [ ] **T11 — Quality gates and handover**
  - Run:
    - `vendor/bin/pint --dirty --format agent`
    - `php artisan test --compact` (full suite)
    - `vendor/bin/phpstan analyse` (level 7; add array-shape PHPDocs rather than baseline entries)
    - `npm run types:check`
    - `npm run build`
    - `npm run check`. If QA-02 has not landed yet, run the scoped check `npx vp check resources/js/components resources/js/pages resources/js/layouts resources/js/types resources/js/composables resources/js/app.ts resources/css` and record it.
  - Checks:
    - `php artisan route:list --path=settings` lists exactly the 11 routes in §2.
    - Scope grep: `rg -n "C:\\\\|/Users/|/home/" app` finds no hardcoded platform paths.
    - `rg -n "localStorage|appearance=" resources/js` finds no theme cookie/`localStorage` usage (other code may use `localStorage`; justify any hit).
  - Record everything in `implementation.md`.
  - **Manual (user, desktop)**: `composer native:dev`, then:
    1. Settings shows General / Storage / Editor / Appearance.
    2. Storage shows the default `…\Documents\MDVault` (your real Documents, including OneDrive redirection if any).
    3. **Choose folder…** opens the native picker. Picking a folder updates the path. Cancel changes nothing.
    4. Typing a relative path shows an error.
    5. Switch the theme to Dark, and set editor font size 20 and wrap off. The Workspace editor reflects them.
    6. Quit and relaunch: the theme is dark at first paint (no flash), and the storage root and editor preferences are unchanged.
    7. **Use default location** restores the default.
  - Covers: all FRs (verification)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/RuntimeConfigTest.php` | app_id, version, updater off, SSR off (strict) | FR-12 |
| `tests/Feature/DatabaseSchemaTest.php` | `settings` exists with §10 columns; framework tables present; auth tables absent; `vaults`/`notes`/`vault_encryption`/`backups` absent | FR-01 |
| `tests/Feature/Services/SettingsServiceTest.php` | Defaults; typed round-trips; single row per key; wrong type rejected; forget/null; atomic `setMany`; unknown key; corrupt and mismatched rows → default; memo invalidation; new-instance persistence; `group()` shape; missing table fail-soft; accessor type guard | FR-01, FR-02 |
| `tests/Unit/Support/SystemUserDirectoriesTest.php` | Native disk wins; Windows USERPROFILE / HOMEDRIVE+HOMEPATH; Unix HOME; trimming; null | FR-04 |
| `tests/Feature/Services/StoragePathServiceTest.php` | Default derivation and fallback; not created; change creates and persists canonical; relative/file/uncreatable/unwritable/empty rejected; default forgets; reset; no data moved; summary | FR-04, FR-05, FR-07, FR-08 |
| `tests/Feature/Services/NativeDialogServiceTest.php` | Unavailable → null and no HTTP; picked path; cancel; payload; defaultPath only if exists | FR-06 |
| `tests/Feature/Settings/AppearanceSettingsTest.php` | Page + theme prop; PATCH persists; invalid rejected; server-rendered `data-appearance` / `dark`; `/settings` redirect | FR-09, FR-03 |
| `tests/Feature/Settings/StorageSettingsTest.php` | Page props; PATCH valid/invalid; DELETE reset; browse 404 in browser; browse success/cancel/invalid in desktop mode (`Http::fake`) | FR-05, FR-06, FR-07, FR-08, FR-03 |
| `tests/Feature/Settings/EditorSettingsTest.php` | Defaults; valid PATCH persists typed; validation dataset | FR-10 |
| `tests/Feature/Settings/GeneralSettingsTest.php` | Page with settings + status; PATCH persists; invalid rejected | FR-11 |
| `tests/Feature/Settings/SettingsNavigationTest.php` | All four settings pages render their components | FR-03 |
| `tests/Feature/WorkspaceTest.php` | `editor` prop defaults and overrides; existing assertions | FR-10 |
| `tests/Unit/ArchitectureTest.php` | Existing rules + `Setting` only in `SettingsService`; `Dialog` only in `NativeDialogService`; `App\Enums` enums | FR-13 |
| (static) `npm run types:check`, `npm run build`, `npm run check` | Pages, composable, types compile; single-root components | FR-03, FR-09 |
| (manual) `composer native:dev` | Native Documents default, native picker, restart persistence, no theme flash | FR-02, FR-04, FR-06, FR-09 |

**Test scope for QA**:
- `php artisan test --compact` (full suite; Phase 1 changes shared middleware, the Blade root and routes). It must include every file in the table above.
- `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run build`, `npm run check` (or the scoped `vp check` per T11 if QA-02 has not landed).
- `php artisan route:list --path=settings` (11 routes).
- The greps in T11: no hardcoded platform paths in `app/`; no theme cookie/`localStorage`.
- Code review: controllers contain no filesystem calls; no settings queries outside `SettingsService`; every new or edited Vue component has a single root.
- The desktop checks in T11 are **user-verified**; QA marks them as manual, not as defects.

---

## 5. Risks & Mitigations
- **Risk**: Tests creating directories in the real Documents folder. — **Mitigation**: `fakeDocumentsDirectory()` in every storage-related test; T5 test 1 asserts the default path is under the temp dir.
- **Risk**: A missing `settings` table (first native boot before migrations, or a stale dev DB) 500s every page via `HandleAppearance`. — **Mitigation**: fail-soft reads (T3 test 13); NativePHP runs migrations at startup; the plan tells the developer to `migrate`.
- **Risk**: Windows-specific path semantics (UNC, drive-relative `\foo`, 8.3 names, ACLs). — **Mitigation**: `isAbsolute` platform branch; a real write probe; `realpath` comparisons in tests; `onlyOnWindows`/`skipOnWindows` datasets.
- **Risk**: NativePHP `Client` captures `Http` fakes at construction. — **Mitigation**: `Dialog::new()` inside the method; tests call `Http::fake()` first.
- **Risk**: A theme PATCH fails after optimistic apply. — **Mitigation**: `onError` restores the previous theme.
- **Risk**: The Wayfinder route rename breaks imports. — **Mitigation**: T7/T10 list every importer; `types:check` + `build` + grep.
- **Risk**: Double storage of a folder path between browser dev and native dev DBs. — **Mitigation**: expected per ADR `desktop-runtime-baseline`; documented.
- **Risk**: The native dialog blocks the request thread while open. — **Mitigation**: busy UI state; the NativePHP client timeout is 1h; manual verification.
- **Risk**: A blind `setMany` with keys from request input. — **Mitigation**: controllers build the key map explicitly from validated fields; unknown keys throw `ValueError`.

---

## 6. Open Questions
*User approvals needed. Recommended answers in bold. None changes the design if the recommendation is accepted.*
- [x] **B1**: New folders:
  - `app/Models/` (recreated early; Phase 0 expected Phase 2)
  - `app/Enums/`
  - `app/Contracts/` (anticipated by ADR `service-layer-architecture`)
  - `app/Support/`
  - `app/Exceptions/`
  - `app/Http/Controllers/Settings/`
  - `app/Http/Requests/Settings/`
  - `tests/Unit/Support/`

  **Approve.**
- [x] **B2**: The theme moves from browser `localStorage` plus the `appearance` cookie to SQLite. An existing local browser preference is not migrated (pre-release), so the first load after upgrade shows `system`. **Approve.**
- [x] **B3**: The settings nav shows General, Storage, Editor and Appearance only. Backup and Security are omitted until Phases 6 and 7 instead of showing empty placeholders. **Approve.**
- [x] **B4**: Defer `appearance.accent_color`, `appearance.logo`, `appearance.favicon`, the sidebar toggles and `backup.default_format`. Phase 1's acceptance criteria only require theme; logo/favicon need file storage. Candidates for a later Appearance polish item. **Approve.**
- No new composer or npm dependencies are required.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-27 | Initial plan | — |
