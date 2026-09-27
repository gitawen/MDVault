# Implementation: Phase 1 — Storage and Settings

## Metadata
- **Feature Name**: Phase 1 — Storage and Settings
- **Feature ID**: mdv-p1
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Runtime config regression test (QA-01) | DONE | `nativephp.updater.enabled` already resolved to real `false`; no config fix needed. |
| T2 — `settings` schema, model and enums | DONE | `make:model`/`make:enum` initially wrote to wrong paths (see Deviations); moved and rewritten by hand. |
| T3 — `SettingsService` | DONE | 14-case test suite (27 tests incl. datasets), arch rules added. |
| T4 — `UserDirectories` / `SystemUserDirectories` | DONE | Pure unit tests, no Laravel bootstrap. |
| T5 — `StoragePathService` / `InvalidStorageRootException` | DONE | Unwritable-directory test skipped on Windows per plan. |
| T6 — `NativeDialogService` | DONE | Arch rule added; `Dialog::new()` per call as specified. |
| T7 — Appearance slice (server-side theme) | DONE | Cookie/`localStorage` removed; single-root page. |
| T8 — Storage slice | DONE | Text input + native dialog browse + reset. |
| T9 — Editor slice and Workspace preferences | DONE | `TiptapEditor` applies size/family/line-height/wrap. |
| T10 — General slice, settings index and navigation | DONE | Nav order General, Storage, Editor, Appearance. |
| T11 — Quality gates and handover | DONE | All commands below are clean. |

---

## 2. Files Changed

### Created
| Path | Summary |
|---|---|
| `app/Models/Setting.php` | Fillable `key/value/type/group`; `casts()` to `SettingType`/`SettingGroup`; `@property` PHPDoc (see Deviations). |
| `app/Enums/SettingType.php` | `String\|Integer\|Float\|Boolean\|Json`; `accepts()`, `serialize()`, `cast()`. |
| `app/Enums/SettingGroup.php` | `General\|Storage\|Editor\|Appearance\|Backup\|Security`. |
| `app/Enums/SettingKey.php` | 8 Phase-1 keys; `type()`, `group()`, `default()`, `field()`. |
| `app/Enums/Theme.php` | `Light\|Dark\|System`. |
| `app/Enums/EditorFontFamily.php` | `Sans\|Serif\|Mono`. |
| `database/migrations/2026_09_26_172743_create_settings_table.php` | `settings` table per §10 schema. |
| `app/Services/SettingsService.php` | Typed get/set/setMany/forget/has/group; request-memoised; fail-soft reads. |
| `app/Contracts/UserDirectories.php` | `documentsPath(): ?string`. |
| `app/Support/SystemUserDirectories.php` | Native disk → Windows/Unix env → null; runtime `getenv()`. |
| `app/Services/StoragePathService.php` | Default/effective root, normalize/isAbsolute, changeRoot, resetToDefault, summary. |
| `app/Exceptions/InvalidStorageRootException.php` | Named constructors with user-facing messages. |
| `app/Services/NativeDialogService.php` | `isAvailable()`, `chooseDirectory()` via `Dialog::new()`. |
| `app/Http/Controllers/Settings/{Appearance,Storage,Editor,General}Controller.php` | Thin edit/update (+ Storage `browse`/`destroy`). |
| `app/Http/Requests/Settings/{UpdateAppearanceRequest,UpdateStorageRootRequest,UpdateEditorSettingsRequest,UpdateGeneralSettingsRequest}.php` | Validation per plan §2 table. |
| `resources/js/types/settings.ts` | `StorageSettings`, `EditorFontFamily`, `EditorPreferences`, `GeneralSettings`. |
| `resources/js/pages/settings/{Storage,Editor,General}.vue` | New settings pages, single root each. |
| `tests/Feature/RuntimeConfigTest.php` | QA-01 regression (4 strict assertions). |
| `tests/Feature/Services/SettingsServiceTest.php` | 14 scenarios / 27 tests. |
| `tests/Unit/Support/SystemUserDirectoriesTest.php` | 6 pure-unit scenarios / 7 tests. |
| `tests/Feature/Services/StoragePathServiceTest.php` | 14 scenarios (1 skipped on Windows). |
| `tests/Feature/Services/NativeDialogServiceTest.php` | 4 scenarios. |
| `tests/Feature/Settings/StorageSettingsTest.php` | 11 scenarios. |
| `tests/Feature/Settings/EditorSettingsTest.php` | Defaults, valid patch, 8-case validation dataset. |
| `tests/Feature/Settings/GeneralSettingsTest.php` | Page, patch, invalid. |
| `tests/Feature/Settings/SettingsNavigationTest.php` | All 4 settings pages render their component. |

### Modified
| Path | Summary |
|---|---|
| `app/Providers/AppServiceProvider.php` | `scoped(SettingsService::class)`; bind `UserDirectories` → `SystemUserDirectories` (runtime `getenv()`, never config-cached). |
| `app/Http/Middleware/HandleAppearance.php` | Reads `appearance.theme` from `SettingsService` instead of the cookie. |
| `bootstrap/app.php` | `encryptCookies(except: ['sidebar_state'])` — dropped `appearance`. |
| `resources/views/app.blade.php` | `data-appearance` attribute on `<html>`; inline script reads the dataset instead of Blade-interpolating the cookie value. |
| `resources/js/composables/useAppearance.ts` | Server-backed: `readServerAppearance()`, PATCH via Wayfinder `update.url()`, `onError` reverts; `localStorage`/cookie code removed. |
| `resources/js/pages/settings/Appearance.vue` | Single root (QA-04), `theme` prop, breadcrumb via `@/routes/settings/appearance`. |
| `resources/js/layouts/settings/Layout.vue` | Nav: General, Storage, Editor, Appearance with lucide icons. |
| `resources/js/components/AppSidebar.vue` | Footer Settings link → `index` from `@/routes/settings`. |
| `resources/js/components/editor/TiptapEditor.vue` | Optional `preferences` prop → font-size/line-height style, font-family class, `tiptap-nowrap` class. |
| `resources/js/pages/Workspace.vue` | `editor: EditorPreferences` prop passed to `TiptapEditor`. |
| `app/Http/Controllers/WorkspaceController.php` | Injects `SettingsService`; adds `editor` prop. |
| `resources/css/app.css` | `.tiptap-nowrap .tiptap-content { white-space: pre; overflow-x: auto; }`. |
| `resources/js/types/index.ts` | Export `./settings`. |
| `routes/settings.php` | Full rewrite: `settings.index` → `/settings/general`; 4 controllers × edit/update, plus Storage `browse`/`destroy`. |
| `tests/Feature/DatabaseSchemaTest.php` | `settings` added to present tables; column check; `vaults/notes/vault_encryption/backups` absence guard. |
| `tests/Unit/ArchitectureTest.php` | 3 new rules: `Setting` only in `SettingsService`; `App\Enums` only enums; `Dialog` only in `NativeDialogService`. |
| `tests/Pest.php` | Added `fakeDocumentsDirectory()` helper. |
| `tests/Feature/Settings/AppearanceSettingsTest.php` | Rewritten for new route names, server-rendered theme, redirect to `settings.general.edit`. |
| `tests/Feature/WorkspaceTest.php` | Added `editor` prop assertions. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` (run as `php vendor/bin/pint …`, see Deviations) | Clean on final run (fixed import ordering along the way). |
| `php artisan test --compact` (full suite) | `129 tests, 128 passed, 345 assertions, 1 skipped`. The skip is the Windows-incompatible unwritable-directory case (`->skipOnWindows()`, as the plan specifies). |
| `vendor/bin/phpstan analyse` (level 7) | 0 errors (see Deviations for two fixes required). |
| `npm run types:check` | Clean. |
| `npm run build` | Succeeds; per-page chunks for `Storage`, `Editor`, `General`, `Appearance`. |
| `npm run check` | **Fully passes**: "All 42 files are correctly formatted" / "no warnings or lint errors in 35 files". (QA-02's vite `fmt.ignorePatterns` fix has already landed, per the task brief — unlike Phase 0 this needed no scoped workaround, only `vp check --fix` on the 2 new pages I had authored with non-canonical formatting.) |
| `php artisan route:list --path=settings` | Exactly 11 routes, matching plan §2. |
| `rg -n "C:\\\\|/Users/|/home/" app` | No matches — no hardcoded platform paths. |
| `rg -n "localStorage\|appearance=" resources/js` | No matches — no theme cookie/`localStorage` remnants. |
| `php artisan migrate --no-interaction` (browser dev DB) | `settings` table created. |
| `php artisan native:migrate --no-interaction` (native dev DB) | `settings` table created — done proactively so the desktop manual checks below have a ready schema. |

---

## 4. Deviations from Plan
- **`make:model Setting -m` and `make:enum Enums/X` wrote to the wrong paths.** With no pre-existing `app/Models/` or `app/Enums/` directory structure, `make:model Setting -m` created `app/Setting.php` (namespace `App\Setting`) instead of `app/Models/Setting.php`, and after the first `make:enum Enums/SettingType` succeeded at `app/Enums/SettingType.php`, the next three `make:enum` calls doubled the path to `app/Enums/Enums/*.php` (namespace `App\Enums\Enums`). Same for `make:interface Contracts/UserDirectories` → `app/Contracts/Contracts/UserDirectories.php`. In every case I moved the file to the plan's specified path and namespace by hand before writing its real content; the final paths/namespaces exactly match the plan (`App\Models\Setting`, `App\Enums\*`, `App\Contracts\UserDirectories`). No functional impact; flagging in case this Artisan behaviour recurs in later phases when a fresh subdirectory is being created for the first time.
- **PHPStan required two small code changes not spelled out in the plan** (plan §T11 only says "add array-shape PHPDocs rather than baseline entries"; I extended that guidance to two related cases):
  1. `StoragePathService::changeRoot()` — the plan's `! $files->makeDirectory(...) || ! $files->isDirectory(...)` check triggered `booleanOr.alwaysTrue`/`booleanNot.alwaysTrue`: PHPStan (Larastan's stubs) treats two calls to `Filesystem::isDirectory()` with the same argument as returning the same result even though `makeDirectory()` sits between them and actually mutates the filesystem. Fixed by re-checking with the raw `is_dir()` function for the post-creation check instead of a second `$this->files->isDirectory()` call — identical runtime behaviour (same underlying syscall), no suppression.
  2. `Setting` model — Larastan did not infer the `type`/`group` columns as `SettingType`/`SettingGroup` from the method-based `casts()` (as opposed to the older `protected $casts` array property), so `SettingsService::load()`'s `$row->type->value` failed with "Cannot access property $value on string." Fixed by adding accurate `@property SettingType $type` / `@property SettingGroup $group` PHPDoc to the model — this documents the real, framework-guaranteed runtime type (confirmed by the passing `SettingsServiceTest` round-trip assertions, e.g. `expect($row->type)->toBe($key->type())`), not a cast or suppression.
- **`vendor/bin/pint` had to be invoked as `php vendor/bin/pint`** in this shell (Git Bash on Windows) — the bare `vendor/bin/pint` shebang failed with `env: 'php': No such file or directory`. Functionally identical; noting it in case QA's shell has the same quirk.
- **General settings auto-save instead of a separate Save button.** The plan's UI description for General doesn't specify the exact save trigger ("Saved via useForm PATCH"). I implemented the "Detect changes made outside MDVault" checkbox to save immediately on toggle (no separate Save button), matching the instant-apply pattern already used by `AppearanceTabs`. I deliberately avoided combining `v-model` with an explicit `@update:model-value` listener on the same Checkbox (undefined event-merge ordering); instead I bind `:model-value` and handle `@update:model-value` explicitly.
- Everything else matches the plan; no scope changes.

---

## 5. Notes for QA
- `tests/Feature/Services/StoragePathServiceTest.php`'s unwritable-directory test is skipped on this (Windows) dev machine per the plan (`->skipOnWindows()`). It has never been exercised in this session — if QA has access to a Linux/macOS runner, running it there would be valuable additional coverage, though it is out of the plan's required scope for this OS.
- `SettingsService::load()` uses `Setting::query()->get(['key', 'value', 'type'])` exactly as specified; the model's new `@property` PHPDoc (see Deviations) is what makes this type-check under Larastan — worth a sanity check that future column-set changes keep the docblock accurate.
- `NativeDialogServiceTest` and `StorageSettingsTest`'s browse tests use `Http::fake()` before constructing the service/making the request, per the plan's caution that `Native\Desktop\Dialog`'s underlying `Client` captures fakes at construction and `Dialog::new()` must be called per-call (confirmed working).
- The `browsing` ref in `Storage.vue` sets a disabled state on "Choose folder…" for the duration of the `router.post`, but there is no loading spinner glyph swapped in (the `Spinner` component exists in the design system but I judged a disabled button sufficient here) — worth a UX look if the desktop dialog proves slow in practice.
- **Manual verification only (cannot be automated), via `composer native:dev`:**
  1. Settings shows General / Storage / Editor / Appearance in that order.
  2. Storage shows the default `…\Documents\MDVault` (the real Documents folder, including any OneDrive redirection).
  3. "Choose folder…" opens the native picker; picking a folder updates the path; Cancel changes nothing.
  4. Typing a relative path shows a validation error.
  5. Switching the theme to Dark, and setting editor font size 20 with wrap off, are reflected live in the Workspace editor.
  6. Quit and relaunch: the theme is dark at first paint (no flash), and the storage root and editor preferences are unchanged (both dev SQLite files were migrated this session so the schema is ready; no data has been seeded into them by this session beyond what test runs create and roll back).
  7. "Use default location" restores the default.
  - None of these seven have been run by the developer (no native shell available in this session) — this is the first thing the user should check before sign-off.

---

## 6. Fix Rounds
*(none yet)*

---

## 7. Post-QA change requested by user (2026-09-27)

After implementation was complete and QA had passed, the user requested a behaviour change to how the storage root is resolved, plus a related write-detection bug fix. This was implemented directly (Level 2, no replan) on the same uncommitted `phase-1-storage-settings` branch.

### Change 1 — MDVault subfolder appended to the chosen root
`StoragePathService::changeRoot()` now appends `self::FOLDER_NAME` (`MDVault`) to whichever folder the user chooses — via the native "Choose folder…" dialog (`StorageController::browse`) or the typed text input (`StorageController::update`) — unless the chosen folder's own basename already equals `MDVault` (case-insensitive), in which case it is used as-is (no doubling). Implemented in a single new private helper, `withMdvaultSuffix()`, called once inside `changeRoot()` immediately after the `normalize()`/`isAbsolute()` checks and before the is-file/create/probe steps, so both `update` and `browse` get it "for free" without controller changes. A drive root (`D:\`) resolves to `D:\MDVault`. The existing create-if-missing, write-probe, canonicalisation and "choosing the default forgets the setting" logic all apply unchanged to the now-suffixed path (verified: picking the real Documents folder still forgets the setting, since `Documents\MDVault` equals `defaultRootPath()`).

### Change 2 — `summary()`'s `writable` bug fix
`summary()` previously reported `writable` via PHP's native `is_writable()`, which is unreliable on Windows for OneDrive-redirected/ACL-controlled folders (e.g. `Downloads`) — it could report `false` immediately after `changeRoot()`'s own write probe had just succeeded on the same folder, making the Storage page wrongly show "MDVault cannot write to this folder." Fixed by adding a private `isWritable()` helper that reuses the existing `probeWritable()` (the same real, temp-file write test `changeRoot()` uses) but catches `InvalidStorageRootException` and returns `false` instead of throwing. `summary()` only calls it when the directory already exists (`$exists && $this->isWritable($root)`), and it never creates anything.

### Files changed
| Path | Summary |
|---|---|
| `app/Services/StoragePathService.php` | Added `withMdvaultSuffix()` (called from `changeRoot()`); replaced `is_writable()` in `summary()` with a new `isWritable()` helper built on `probeWritable()`. |
| `resources/js/pages/settings/Storage.vue` | Updated the helper paragraph under the folder controls to explain that an "MDVault" folder is created inside the chosen folder, unless it is already named that. |
| `.ai/decisions/storage-root-resolution.md` | Appended a Decision note (subfolder suffix) and a Consequences note (writability via probe, not `is_writable()`). |
| `tests/Feature/Services/StoragePathServiceTest.php` | Updated 4 existing tests whose expected paths/messages changed because of the suffix (`nested/child`, `existing`, `trailing`, the "existing file" and "unwritable directory" cases — both re-targeted at the `MDVault` subfolder itself) and the "never moves existing data" test (now writes into the effective, suffixed root). Added 5 new tests: subfolder appended for a plain chosen dir; suffix not doubled when the chosen folder is already named `MDVault` (dataset: `MDVault`, `mdvault`, `MdVault`); an existing `MDVault` subfolder is reused (its contents untouched); `changeRoot` throws `isFile` when `<chosen>\MDVault` already exists as a file; `summary()`'s `writable` is `true` for a real writable directory reached via the new suffix path (locks in the probe-based behaviour, though it cannot reproduce the OneDrive/ACL-specific Windows bug in an automated test — see Notes for QA). |
| `tests/Feature/Settings/StorageSettingsTest.php` | Updated "patching a new path…" and "browse persists the picked folder…" to assert the `MDVault` subfolder is what gets created and persisted. The two existing "file path is rejected" tests (`update` and `browse`) needed no change — they only assert `assertSessionHasErrors('root_path')`, not the specific message, and the new subfolder logic still produces a `root_path` validation error for those inputs (via `cannotCreate()` instead of `isFile()`, since the failure now surfaces one level deeper, under the file component of the path — see Notes for QA). |

### Verification performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan test --compact --filter=StoragePathServiceTest` | `24 tests, 23 passed, 56 assertions, 1 skipped` (unwritable-subfolder case, `->skipOnWindows()`, unchanged from before). |
| `php artisan test --compact --filter=StorageSettingsTest` | `11 tests, 11 passed, 41 assertions`. |
| `php artisan test --compact` (full suite) | `135 tests, 134 passed, 358 assertions, 1 skipped`. |
| `php vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | Clean (`vue-tsc --noEmit`, no output). |
| `npm run check` | `vp check --fix` was needed once (Storage.vue's new helper paragraph needed re-wrapping); after that, "All 42 files are correctly formatted" / "no warnings or lint errors in 35 files". |
| `npm run build` | Succeeds; `Storage-*.js` chunk rebuilt. |

### Deviations from the request
- None. The suffix logic lives in exactly one place (`changeRoot()`'s new `withMdvaultSuffix()` private helper) as specified, both entry points inherit it without controller changes, `FOLDER_NAME` and `DIRECTORY_SEPARATOR` are reused as instructed, and the write-probe fix reuses `probeWritable()` as instructed.
- One existing test ("an existing file is rejected") was repurposed rather than left in place unmodified, because its old scenario (the raw chosen path itself is a file) no longer produces an `isFile` error once the suffix is applied first — it now fails one level deeper via `cannotCreate()` when `mkdir()` is attempted under a file path component (consistent with the pre-existing, still-passing "a path under an existing file cannot be created" test, which exercises the same underlying mechanism). The repurposed test now covers the genuinely new failure mode the task asked for: `<chosen>\MDVault` already existing as a file. No test was deleted; the same is true of the "unwritable directory" test, which now targets the `MDVault` subfolder specifically rather than the chosen directory itself (which would otherwise fail at `cannotCreate()` instead of exercising the write probe).

### Notes for QA
- The `cannotCreate()` (rather than `isFile()`) message for a raw file path typed directly into the input (e.g. `C:\...\blocker.txt`) is a natural side effect of always computing the suffix before the is-file check; the user-visible outcome is unchanged (a `root_path` validation error is still shown), only the specific wording differs from before this change. Worth a quick manual check that the message ("The folder could not be created. Check the path and your permissions.") still reads sensibly for that input.
- The new `summary()` write-probe test can only prove the code path is exercised and returns `true` for an ordinary writable temp directory in this environment — it cannot reproduce the actual Windows OneDrive/ACL `is_writable()` false-negative the bug report describes, since that requires a real redirected/ACL-restricted folder. Manual verification on a real "Downloads" folder (per the bug report) via `composer native:dev` would be the strongest confirmation.
- Manual desktop verification items 2 and 3 from §5 above (default path display, native picker) should be re-checked against the new subfolder behaviour: picking, e.g., `Downloads` should now show `Downloads\MDVault` as the stored root, and the "MDVault cannot write to this folder" warning should no longer appear for a writable `Downloads` folder.

## Change 3 — Configurable storage folder name (2026-09-27, Level 2)

After Change 1/2 above (still uncommitted on `phase-1-storage-settings`), the user asked to let the folder name itself ("MDVault") be user-configurable in Settings > Storage, in addition to the location. Implemented directly per the orchestrator's spec, no replan.

### What changed
- **New setting**: `SettingKey::StorageFolderName` (`storage.folder_name`, group `storage`, type `string`, default `StoragePathService::FOLDER_NAME`), read/written only via `SettingsService`, exactly like `StorageRootPath`.
- **`StoragePathService`**:
  - New public `folderName(): string` — stored value or `self::FOLDER_NAME`.
  - `defaultRootPath()` now composes `Documents + folderName()` instead of the hardcoded constant.
  - `changeRoot(string $location, ?string $folderName = null): string` — effective name is `$folderName ?? $this->folderName()`; every existing single-argument call site keeps working unchanged. The effective name is validated by a new public `assertValidFolderName()` guard *before* any filesystem or database operation. The suffix helper (`withMdvaultSuffix` → renamed `withFolderSuffix`) now takes the name as a parameter instead of using the constant directly, but the "already named that (case-insensitive)" convenience rule is unchanged. On success, the folder name is persisted (or forgotten, if it case-insensitively equals `MDVault`) *before* the root-vs-default comparison, so that comparison reflects the just-applied name. Filesystem steps (validate name, normalize/absolute check, is-file/mkdir/probe) all happen before either setting is written; a thrown `InvalidStorageRootException` at any point leaves both settings and the filesystem exactly as they were (verified by dedicated tests).
  - New public `assertValidFolderName(string $name): void` — rejects empty, leading/trailing whitespace, >100 chars, `.`/`..`, any of `< > : " / \ | ? *` or a control character, a trailing dot, and (after stripping a trailing `.extension`) a case-insensitive match against `CON/PRN/AUX/NUL/COM1-9/LPT1-9`. This runs unconditionally on every OS (not gated by `PHP_OS_FAMILY`), per the portability requirement.
  - `summary()` gained `location` (`dirname($root_path)`) and `folder_name` (`folderName()`).
  - `resetToDefault()` is unchanged in code (it only ever touched `StorageRootPath`) — its doc comment now spells out that this means "keep the folder name," per the spec.
- **`InvalidStorageRootException`**: gained a `field(): string` accessor (`'location'` by default, `'folder_name'` for the new `invalidFolderName()` factory), so the controller can route each failure to the correct form field without a chain of `catch` blocks.
- **`UpdateStorageRootRequest`**: fields renamed from a single `root_path` to `location` (required, same semantics as the old field) and `folder_name` (nullable — blank/omitted means "keep the current name"). The `folder_name` rule chain (`bail`, `nullable`, `string`, `max:100`, then a closure) delegates the actual character/reserved-name check to `StoragePathService::assertValidFolderName()` via constructor-style method injection on `rules()` (Laravel resolves `FormRequest::rules()` through the container, so type-hinting `StoragePathService $paths` as a parameter works) — this keeps the validation logic in exactly one place rather than duplicating the regex/reserved-name list in the request.
- **`StorageController`**: `update()` passes both validated fields to `changeRoot()`. `browse()` now explicitly passes `$paths->folderName()` as the second argument (the native picker only ever changes the location, never the name). The private `changeRoot()` helper now maps `InvalidStorageRootException::field()` to the matching validation-error key instead of always using `root_path`.
- **`Storage.vue`**: split the single "Folder" input into "Location" and "Folder name" (placeholder `MDVault`) fields, both submitted by one Save button. Added a live preview line ("Vaults will be stored in: …") computed client-side, mirroring the server's basename-already-matches rule, using whichever separator (`\` or `/`) appears in the current location value. The existing prop-resync `watch` now re-syncs both fields (and calls `form.reset()`/`form.clearErrors()` with no arguments, since there are now two fields to reset instead of one). Single root element preserved; component remains presentation-only (no direct filesystem/service access).
- **`resources/js/types/settings.ts`**: `StorageSettings` gained `location: string` and `folder_name: string`.
- **ADR `storage-root-resolution.md`**: appended a decision addendum (configurable name, validation rules, persistence order, request field rename) and a "Follow-ups" note is unchanged (no new follow-up introduced).

### Files changed
| Path | Summary |
|---|---|
| `app/Enums/SettingKey.php` | Added `StorageFolderName` case (type/group/default); default sources `StoragePathService::FOLDER_NAME`. |
| `app/Services/StoragePathService.php` | Added `folderName()`, `assertValidFolderName()`, `RESERVED_FOLDER_NAMES`; `changeRoot()` takes optional `$folderName`; `defaultRootPath()`/`summary()` updated; suffix helper renamed and parameterised. |
| `app/Exceptions/InvalidStorageRootException.php` | Added `field()` and `invalidFolderName()`. |
| `app/Http/Requests/Settings/UpdateStorageRootRequest.php` | `root_path` → `location` + `folder_name`; `rules()` takes `StoragePathService $paths` via method injection. |
| `app/Http/Controllers/Settings/StorageController.php` | `update`/`browse`/private `changeRoot()` updated for the two-field signature and per-exception field routing. |
| `resources/js/pages/settings/Storage.vue` | Location + Folder name fields, live preview, updated helper text and prop-resync watch. |
| `resources/js/types/settings.ts` | `StorageSettings` gained `location`, `folder_name`. |
| `resources/js/actions/**`, `resources/js/routes/**` | Regenerated via `wayfinder:generate --with-form` (route URLs/methods unchanged; no manual edits needed). |
| `.ai/decisions/storage-root-resolution.md` | Addendum: configurable folder name, validation rules, persistence order, request field rename. |
| `tests/Feature/Services/StoragePathServiceTest.php` | Updated `summary()` shape assertions; added folder-name validation dataset tests and 7 new `changeRoot`/`resetToDefault`/persistence-on-failure scenarios. |
| `tests/Feature/Settings/StorageSettingsTest.php` | `root_path` → `location` throughout; added custom-name update/browse tests and an invalid-`folder_name` controller test. |

### Verification performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan test --compact --filter=StoragePathServiceTest` | `45 tests, 44 passed, 93 assertions, 1 skipped` (the pre-existing Windows-only unwritable-directory skip). |
| `php artisan test --compact --filter=StorageSettingsTest` | `15 tests, 15 passed, 57 assertions`. |
| `php artisan test --compact` (full suite) | `161 tests, 160 passed, 412 assertions, 1 skipped`. |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | Clean (`vue-tsc --noEmit`, no output). |
| `npm run check` | One re-wrap needed in `Storage.vue` (`vp check --fix`); rerun: "All 42 files are correctly formatted" / "no warnings or lint errors in 35 files". |
| `npm run build` | Succeeds; `Storage-*.js` chunk rebuilt (5.08 kB). |
| `php artisan wayfinder:generate --with-form --no-interaction` | Regenerated actions/routes; no functional diff (route shapes unchanged, Wayfinder doesn't encode Form Request field names). |

### Deviations from the spec
- None functionally. One implementation choice not spelled out in the spec: `assertValidFolderName()` is `public` (not `private`) on `StoragePathService`, specifically so `UpdateStorageRootRequest::rules()` can call it directly via method injection instead of duplicating the character/reserved-name rules in the Form Request. This keeps "one source of truth" for the validation logic while still satisfying "Form Request + service guard" (the Form Request still owns the `required`/`max:1024` shape rules for `location` and `bail`/`nullable`/`string`/`max:100` for `folder_name`; the service owns the actual name-format logic that both the request closure and `changeRoot()` share).
- The `location`/`folder_name` field rename means `assertSessionHasErrors('root_path')` no longer applies anywhere; every affected test now asserts against `location` or `folder_name`, matching the spec's item 6 requirement to update existing tests to the new fields.

### Notes for QA
- `UpdateStorageRootRequest::rules(StoragePathService $paths)` relies on Laravel resolving `FormRequest::rules()` through the container (confirmed via `vendor/laravel/framework/.../FormRequest.php:208`: `$this->container->call([$this, 'rules'])`), which is how method-parameter injection works here. Worth double-checking this keeps working if the Form Request base class changes on a future Laravel upgrade.
- The reserved-device-name check intentionally runs on every OS, not just Windows (`RESERVED_FOLDER_NAMES` isn't gated by `isWindows()`), matching the spec's explicit portability requirement — a name like `CON` is rejected even when developing/testing on Linux/macOS.
- `folder_name`'s "nullable" behaviour depends on Laravel's default `ConvertEmptyStringsToNull` middleware (registered automatically by `Application::configure()`, confirmed in `vendor/laravel/framework/.../Configuration/Middleware.php`) turning a submitted empty string into `null` before validation — this is framework-default behaviour, not something this change added, but it's the reason "leave folder name blank to keep the current one" works from the UI without extra code.
- No manual desktop verification was performed for this change (no native shell available in this session, same limitation as noted in §5/Change 1-2). Worth re-checking on `composer native:dev`: (1) the "Folder name" field pre-fills with the current name (not blank) on page load; (2) the live preview line matches what actually gets persisted after Save; (3) "Choose folder…" still uses whatever custom name was last set, without prompting for a name.

## Change 4 — Bug fix: saved "MDVault" default was overriding a typed custom name (2026-09-27, Level 2)

User report: the default root folder name "MDVault" overrode the custom name typed into the "Folder name" field; it should just follow the input. Fixed on the same uncommitted branch.

### Root causes (as diagnosed by the coordinator, confirmed while fixing)
1. `Storage.vue`'s `chooseFolder()` posted no body (`router.post(browse.url(), {})`), so `StorageController::browse()` fell back to `$paths->folderName()`, the saved name, discarding whatever the user had typed but not yet saved. The page then reloaded with the saved name and the props-driven `watch()` wrote it back into the form, so the typed name visibly reverted.
2. `UpdateStorageRootRequest::folder_name` was `nullable` with "blank = keep current" semantics, and `Storage.vue`'s preview computed a fallback to `props.storage.folder_name` when the field was empty - both silently substituted the saved name for an absent/blank typed one.

### Fix
- `folder_name` is now `required` on both actions, not nullable. `UpdateStorageRootRequest` keeps its existing `folderNameRules()` helper (now `required` instead of `nullable`) and gained a `messages()` override for a friendly required message.
- New `BrowseStorageRootRequest` (`app/Http/Requests/Settings/BrowseStorageRootRequest.php`, via `make:request Settings/BrowseStorageRootRequest`) validates `folder_name` with the same `required`/`assertValidFolderName()`-backed rules. Its `authorize()` checks `NativeDialogService::isAvailable()` (resolved via `$this->container->make(...)`, since `authorize()` is not container-called the way `rules()` is) and its `failedAuthorization()` is overridden to `abort(404)` instead of the framework's default 403 - this preserves the pre-existing "browse is not found outside the desktop runtime" contract, and because `authorize()` runs before `rules()` in Laravel's `ValidatesWhenResolvedTrait`, that 404 still fires even when no `folder_name` is posted at all (confirmed by the unchanged "browse is not found outside the desktop runtime" test, which posts no body).
- `StorageController::browse()` now type-hints `BrowseStorageRootRequest $request`, dropped its own `abort_unless()` (now the request's job) and its `$paths->folderName()` fallback, and passes `$request->validated('folder_name')` to the shared private `changeRoot()` helper (whose `$folderName` parameter is now non-nullable `string`, since both call sites always have a definite value). Validation runs, and can fail, before `NativeDialogService::chooseDirectory()` is ever called, so an invalid or missing name never opens the native picker.
- `Storage.vue`: `chooseFolder()` now posts `{ folder_name: form.folder_name }`. The single combined `watch(() => [props.storage.location, props.storage.folder_name], ...)` was replaced with a `location`-only watch. `folder_name` is never resynced from server props; it is only re-baselined (`form.defaults('folder_name', form.folder_name)`, a no-op on the value itself) inside `chooseFolder()`'s `onSuccess`, mirroring the pattern `save()` already used. A cancelled picker changes nothing server-side and does not error, so it still triggers `onSuccess`, but since that only re-asserts the value already in the field as the new default, the typed name is never overwritten. `previewPath`'s fallback to `props.storage.folder_name` was removed; an empty folder name now simply means no preview, matching "the field is required to save."
- Note on the pre-existing combined watch: it used a getter returning a new array literal on every reactive re-evaluation. Since Vue's default change detection for a watched getter is `Object.is` on the returned value, a freshly-allocated array is never `===` its predecessor even when its contents are identical, so that watcher re-ran, and clobbered the form, far more often than "when the server value actually changed." Replacing it with a plain-string getter fixes this too: primitive string equality correctly suppresses no-op reruns.

### Files changed
| Path | Summary |
|---|---|
| `app/Http/Requests/Settings/UpdateStorageRootRequest.php` | `folder_name` rule changed `nullable` to `required`; added `messages()`. |
| `app/Http/Requests/Settings/BrowseStorageRootRequest.php` | New. Validates `folder_name` (required); `authorize()`/`failedAuthorization()` preserve the 404-outside-desktop-runtime contract. |
| `app/Http/Controllers/Settings/StorageController.php` | `browse()` takes `BrowseStorageRootRequest`, uses its validated `folder_name`, dropped the manual `abort_unless`/`folderName()` fallback; private `changeRoot()`'s `$folderName` param is now non-nullable. |
| `resources/js/pages/settings/Storage.vue` | `chooseFolder()` posts `folder_name`; combined watch replaced with a `location`-only watch; `folder_name` re-baselined only in `save()`/`chooseFolder()` `onSuccess`; preview no longer falls back to the saved name. |
| `tests/Feature/Settings/StorageSettingsTest.php` | Updated update tests to always post `folder_name` (matching the new `required` rule); replaced "omitting keeps current" with "omitted is rejected, nothing already-saved is disturbed" and "empty is rejected"; replaced "browse reuses the current custom folder name" with "browse uses the folder name posted with the request, overriding any previously saved name"; added empty/invalid-name-before-dialog tests using `Http::assertNothingSent()`; updated "cancelling changes nothing" to prove a previously-saved custom name survives a cancel untouched. |
| `.ai/decisions/storage-root-resolution.md` | Appended a bug-fix addendum under the Decision section. |

### Verification performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | passed |
| `php artisan test --compact --filter=StorageSettingsTest` | 18 tests, 18 passed, 72 assertions. |
| `php artisan test --compact` (full suite) | 164 tests, 163 passed, 427 assertions, 1 skipped (pre-existing Windows-only skip). |
| `vendor/bin/phpstan analyse` | 0 errors. |
| `npm run types:check` | Clean. |
| `npm run check` | Clean on the first run this round (no `--fix` needed). |
| `npm run build` | Succeeds; `Storage-*.js` chunk rebuilt. |
| `php artisan wayfinder:generate --with-form --no-interaction` | Regenerated; no functional diff. |

### Deviations
None. `BrowseStorageRootRequest::authorize()` resolving `NativeDialogService` via `$this->container->make(...)` (rather than a method parameter, as `rules()` uses) was necessary because Laravel calls `authorize()` directly, not through the container's `call()` helper - only `rules()`/`validator()`/`after()` get container-based method injection in `FormRequest::getValidatorInstance()`. This is documented inline in the class.

### Notes for QA
- No manual desktop verification was performed (no native shell in this session). Worth checking on `composer native:dev`: (1) type a custom name, click "Choose folder...", pick a folder - the new root should use the typed name, not whatever was previously saved; (2) type a custom name, click "Choose folder...", then Cancel in the OS picker - the typed name should still be sitting in the field afterward, untouched; (3) clear the folder name field and click Save or Choose folder - both should show "The folder name is required." without changing anything.
- `BrowseStorageRootRequest`'s authorize-before-rules ordering is load-bearing for the "browse is not found outside the desktop runtime" test (no `folder_name` in the request body, dialog unavailable) - if a future Laravel version changes `ValidatesWhenResolvedTrait`'s call order, that test would start failing with a 422 instead of 404, which would be a useful canary.
